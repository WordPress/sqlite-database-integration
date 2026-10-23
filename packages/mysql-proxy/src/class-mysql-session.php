<?php declare( strict_types = 1 );

namespace WP_MySQL_Proxy;

use Throwable;
use WP_MySQL_Proxy\Adapter\Adapter;

/**
 * MySQL server session handling a single client connection.
 */
class MySQL_Session {
	/**
	 * Reasons the session closes a connection.
	 */
	const CLOSE_LOGIN_FAILED     = 'login failed';
	const CLOSE_CLIENT_QUIT      = 'client quit';
	const CLOSE_PACKET_TOO_LARGE = 'packet too large';
	const CLOSE_MALFORMED_PACKET = 'malformed packet';

	/**
	 * Largest payload a single packet can carry.
	 *
	 * A packet with a payload of exactly this size continues in the next packet.
	 *
	 * @see https://dev.mysql.com/doc/dev/mysql-server/latest/page_protocol_basic_packets.html
	 */
	const MAX_PACKET_PAYLOAD = 0xffffff;

	/**
	 * Largest message accepted from an authenticated client.
	 *
	 * This is the "max_allowed_packet" equivalent. It bounds the memory a single
	 * connection can hold, because a split message is assembled in memory.
	 */
	const MAX_MESSAGE_SIZE = 16777216;

	/**
	 * Largest message accepted before the client is authenticated.
	 *
	 * A login packet carries a username, a challenge response, a database name,
	 * and connection attributes, so it stays well below MySQL's own 16 KB
	 * network buffer. Anything larger is a malformed or hostile login.
	 */
	const MAX_UNAUTHENTICATED_PACKET_SIZE = 16384;

	/**
	 * Client capabilities that are supported by the server.
	 */
	const CAPABILITIES = (
		MySQL_Protocol::CLIENT_PROTOCOL_41
		| MySQL_Protocol::CLIENT_DEPRECATE_EOF
		| MySQL_Protocol::CLIENT_SECURE_CONNECTION
		| MySQL_Protocol::CLIENT_PLUGIN_AUTH
		| MySQL_Protocol::CLIENT_PLUGIN_AUTH_LENENC_CLIENT_DATA
		| MySQL_Protocol::CLIENT_CONNECT_WITH_DB
	);

	/**
	 * MySQL server version.
	 *
	 * @var string
	 */
	private $server_version = '8.0.38-php-mysql-server';

	/**
	 * Character set that is used by the server.
	 *
	 * @var int
	 */
	private $character_set = MySQL_Protocol::CHARSET_UTF8MB4;

	/**
	 * Status flags representing the server state.
	 *
	 * @var int
	 */
	private $status_flags = MySQL_Protocol::SERVER_STATUS_AUTOCOMMIT;

	/**
	 * An adapter instance to execute MySQL queries.
	 *
	 * @var Adapter
	 */
	private $adapter;

	/**
	 * Connection ID.
	 *
	 * @var int
	 */
	private $connection_id;

	/**
	 * Client capabilities.
	 *
	 * @var int
	 */
	private $client_capabilities = 0;

	/**
	 * Authentication plugin data (a random 20-byte salt/scramble).
	 *
	 * @var string
	 */
	private $auth_plugin_data;

	/**
	 * Whether the client is authenticated.
	 *
	 * @var bool
	 */
	private $is_authenticated = false;

	/**
	 * The account verifier shared by proxy sessions.
	 *
	 * @var MySQL_Authentication
	 */
	private $authentication;

	/**
	 * Login details retained while waiting for an authentication switch response.
	 *
	 * @var array{username: string, plugin: string, database: string, client_flags: int}|null
	 */
	private $pending_authentication;

	/**
	 * Why the connection must be closed, or null while it stays open.
	 *
	 * @var string|null
	 */
	private $close_reason;

	/**
	 * Logger instance.
	 *
	 * @var Logger
	 */
	private $logger;

	/**
	 * Packet sequence ID.
	 *
	 * @var int
	 */
	private $packet_id;

	/**
	 * Buffer to store incoming data from the client.
	 *
	 * @var string
	 */
	private $buffer = '';

	/**
	 * Constructor.
	 *
	 * @param Adapter              $adapter        The MySQL query adapter instance.
	 * @param int                  $connection_id  The connection ID.
	 * @param MySQL_Authentication $authentication The configured account verifier.
	 * @param Logger|null          $logger         The logger for internal errors. Default: a new logger.
	 */
	public function __construct( Adapter $adapter, int $connection_id, MySQL_Authentication $authentication, ?Logger $logger = null ) {
		$this->authentication = $authentication;
		$this->adapter        = $adapter;
		$this->connection_id  = $connection_id;
		$this->logger         = $logger ?? new Logger();
		$this->packet_id      = 0;

		// Avoid NUL bytes because some clients treat the challenge as a C string.
		$this->auth_plugin_data = str_replace( "\0", "\1", random_bytes( 20 ) );
	}

	/**
	 * Whether the connection has completed authentication.
	 *
	 * @return bool
	 */
	public function is_authenticated(): bool {
		return $this->is_authenticated;
	}

	/**
	 * Whether the transport must close this connection.
	 *
	 * @return bool
	 */
	public function is_closed(): bool {
		return null !== $this->close_reason;
	}

	/**
	 * Why the connection is closing.
	 *
	 * @return string|null One of the CLOSE_* reasons, or null while it stays open.
	 */
	public function get_close_reason(): ?string {
		return $this->close_reason;
	}

	/**
	 * Check if there's any buffered data that hasn't been processed yet
	 *
	 * @return bool True if there's data in the buffer
	 */
	public function has_buffered_data(): bool {
		return strlen( $this->buffer ) > 0;
	}

	/**
	 * Get the number of bytes currently in the buffer
	 *
	 * @return int Number of bytes in buffer
	 */
	public function get_buffer_size(): int {
		return strlen( $this->buffer );
	}

	/**
	 * Get the initial handshake packet to send to the client.
	 *
	 * @see https://dev.mysql.com/doc/dev/mysql-server/latest/page_protocol_connection_phase.html#sect_protocol_connection_phase_initial_handshake
	 *
	 * @return string The initial handshake packet.
	 */
	public function get_initial_handshake(): string {
		return MySQL_Protocol::build_handshake_packet(
			0,
			$this->server_version,
			$this->character_set,
			$this->connection_id,
			$this->auth_plugin_data,
			self::CAPABILITIES,
			$this->status_flags
		);
	}

	/**
	 * Process bytes received from the client.
	 *
	 * @param  string $data Binary data received from client.
	 * @return string|null  Response to send back to client, or null if no response needed.
	 * @throws Incomplete_Input_Exception When more data is needed to complete a packet.
	 */
	public function receive_bytes( string $data ): ?string {
		if ( $this->is_closed() ) {
			return null;
		}

		// Append new data to the existing buffer.
		$this->buffer .= $data;

		// Read one message, which the client may have split across packets.
		$received_sequence_id = null;
		$payload              = $this->read_message( $received_sequence_id );
		if ( null === $payload ) {
			return $this->reject_oversized_message();
		}

		/*
		 * Process the message.
		 *
		 * Depending on the lifecycle phase, handle authentication or a command.
		 *
		 * @see: https://dev.mysql.com/doc/dev/mysql-server/9.5.0/page_protocol_connection_lifecycle.html
		 */

		// Authentication phase.
		if ( ! $this->is_authenticated ) {
			$expected_sequence_id = null === $this->pending_authentication ? 1 : 3;
			if ( $expected_sequence_id !== $received_sequence_id ) {
				return $this->reject_authentication();
			}
			return $this->process_authentication( $payload );
		}

		// Command phase. Like MySQL, refuse a packet that carries no command.
		if ( '' === $payload ) {
			$this->close( self::CLOSE_MALFORMED_PACKET );
			return MySQL_Protocol::build_err_packet( $this->packet_id++, 1835, 'HY000', 'Malformed communication packet' );
		}

		$command = ord( $payload[0] );
		switch ( $command ) {
			case MySQL_Protocol::COM_QUIT:
				// MySQL closes the connection itself, without a response.
				$this->close( self::CLOSE_CLIENT_QUIT );
				return '';
			case MySQL_Protocol::COM_INIT_DB:
				return $this->use_database( substr( $payload, 1 ) )
					?? MySQL_Protocol::build_ok_packet( $this->packet_id++, $this->status_flags );
			case MySQL_Protocol::COM_QUERY:
				return $this->process_query( substr( $payload, 1 ) );
			case MySQL_Protocol::COM_PING:
				return MySQL_Protocol::build_ok_packet( $this->packet_id++, $this->status_flags );
			default:
				return MySQL_Protocol::build_err_packet(
					$this->packet_id++,
					0x04D2,
					'HY000',
					sprintf( 'Unsupported command: %d', $command )
				);
		}
	}

	/**
	 * Read one client message from the buffer, joining packets the client split.
	 *
	 * The message is removed from the buffer only once it is complete, so an
	 * incomplete message stays buffered for the next read.
	 *
	 * @param  int|null $first_sequence_id Receives the sequence ID of the first packet.
	 * @return string|null                 The message payload, or null if it is too large.
	 * @throws Incomplete_Input_Exception  When more data is needed to complete the message.
	 */
	private function read_message( ?int &$first_sequence_id ): ?string {
		$limit   = $this->is_authenticated ? self::MAX_MESSAGE_SIZE : self::MAX_UNAUTHENTICATED_PACKET_SIZE;
		$offset  = 0;
		$payload = '';

		while ( true ) {
			// Check if we have enough data for a packet header.
			if ( strlen( $this->buffer ) < $offset + 4 ) {
				throw new Incomplete_Input_Exception( 'Incomplete packet header, need more bytes' );
			}

			// Parse the packet header. A response continues the client's sequence.
			$payload_length    = unpack( 'V', substr( $this->buffer, $offset, 3 ) . "\x00" )[1];
			$sequence_id       = ord( $this->buffer[ $offset + 3 ] );
			$first_sequence_id = $first_sequence_id ?? $sequence_id;
			$this->packet_id   = $sequence_id + 1;

			// Refuse the message before buffering more than the limit allows.
			if ( strlen( $payload ) + $payload_length > $limit ) {
				return null;
			}

			// Check if we have the complete packet.
			$packet_length = 4 + $payload_length;
			if ( strlen( $this->buffer ) < $offset + $packet_length ) {
				throw new Incomplete_Input_Exception(
					sprintf(
						'Incomplete packet payload, have %d bytes, but need %d bytes',
						strlen( $this->buffer ) - $offset,
						$packet_length
					)
				);
			}

			$payload .= substr( $this->buffer, $offset + 4, $payload_length );
			$offset  += $packet_length;

			// A full payload means the message continues in the next packet.
			if ( self::MAX_PACKET_PAYLOAD !== $payload_length ) {
				break;
			}
		}

		// Remove the whole message from the buffer.
		$this->buffer = substr( $this->buffer, $offset );
		return $payload;
	}

	/**
	 * Refuse a message larger than this session accepts.
	 *
	 * @return string The error packet.
	 */
	private function reject_oversized_message(): string {
		if ( ! $this->is_authenticated ) {
			return $this->reject_authentication();
		}
		$this->close( self::CLOSE_PACKET_TOO_LARGE );
		return MySQL_Protocol::build_err_packet(
			$this->packet_id++,
			1153,
			'08S01',
			"Got a packet bigger than 'max_allowed_packet' bytes"
		);
	}

	/**
	 * Process authentication payload from the client.
	 *
	 * @param  string  $payload The authentication payload.
	 * @return string           The authentication response packet.
	 */
	private function process_authentication( string $payload ): string {
		if ( null !== $this->pending_authentication ) {
			$login                        = $this->pending_authentication;
			$this->pending_authentication = null;
			return $this->complete_authentication(
				$login['username'],
				$login['plugin'],
				$payload,
				$login['database'],
				$login['client_flags']
			);
		}

		try {
			if ( strlen( $payload ) < 32 ) {
				return $this->reject_authentication();
			}
			$client_flags = unpack( 'V', $payload )[1];
			if ( ! ( $client_flags & MySQL_Protocol::CLIENT_PROTOCOL_41 )
				|| ( $client_flags & MySQL_Protocol::CLIENT_SSL )
			) {
				return $this->reject_authentication();
			}

			$offset   = 32;
			$username = MySQL_Protocol::read_null_terminated_string( $payload, $offset );
			if ( $client_flags & MySQL_Protocol::CLIENT_PLUGIN_AUTH_LENENC_CLIENT_DATA ) {
				$auth_response = MySQL_Protocol::read_length_encoded_string( $payload, $offset );
			} elseif ( $client_flags & MySQL_Protocol::CLIENT_SECURE_CONNECTION ) {
				if ( $offset >= strlen( $payload ) ) {
					return $this->reject_authentication();
				}
				$length = ord( $payload[ $offset++ ] );
				if ( $length > strlen( $payload ) - $offset ) {
					return $this->reject_authentication();
				}
				$auth_response = substr( $payload, $offset, $length );
				$offset       += $length;
			} else {
				return $this->reject_authentication();
			}

			$database = '';
			if ( $client_flags & MySQL_Protocol::CLIENT_CONNECT_WITH_DB ) {
				$database = MySQL_Protocol::read_null_terminated_string( $payload, $offset );
			}

			$plugin = MySQL_Protocol::AUTH_PLUGIN_MYSQL_NATIVE_PASSWORD;
			if ( $client_flags & MySQL_Protocol::CLIENT_PLUGIN_AUTH ) {
				$plugin = MySQL_Protocol::read_null_terminated_string( $payload, $offset );
			}
		} catch ( MySQL_Proxy_Exception $e ) {
			return $this->reject_authentication();
		}

		$switch_plugin = null;
		if ( $client_flags & MySQL_Protocol::CLIENT_PLUGIN_AUTH ) {
			if ( $this->authentication->get_plugin() !== $plugin ) {
				// Like MySQL, switch a client that chose another method to the account's.
				$switch_plugin = $this->authentication->get_plugin();
			} elseif ( MySQL_Protocol::AUTH_PLUGIN_MYSQL_NATIVE_PASSWORD === $plugin && '' === $auth_response ) {
				// Older clients request native authentication without sending a response yet.
				$switch_plugin = $plugin;
			}
		}

		if ( null !== $switch_plugin ) {
			$this->pending_authentication = array(
				'username'     => $username,
				'plugin'       => $switch_plugin,
				'database'     => $database,
				'client_flags' => $client_flags,
			);
			return MySQL_Protocol::build_packet(
				$this->packet_id++,
				chr( MySQL_Protocol::AUTH_SWITCH_REQUEST_HEADER ) . $switch_plugin . "\0" . $this->auth_plugin_data . "\0"
			);
		}

		return $this->complete_authentication( $username, $plugin, $auth_response, $database, $client_flags );
	}

	/**
	 * Verify credentials before selecting the database and completing the login.
	 *
	 * @param string $username      The client account name.
	 * @param string $plugin        The authentication plugin.
	 * @param string $auth_response The client challenge response.
	 * @param string $database      The initial database name.
	 * @param int    $client_flags  The client capabilities.
	 * @return string The authentication result packets.
	 */
	private function complete_authentication( string $username, string $plugin, string $auth_response, string $database, int $client_flags ): string {
		if ( ! $this->authentication->verify( $username, $plugin, $auth_response, $this->auth_plugin_data ) ) {
			return $this->reject_authentication();
		}

		// No adapter calls are allowed until the credentials have been verified.
		if ( '' !== $database ) {
			$error = $this->use_database( $database );
			if ( null !== $error ) {
				$this->close( self::CLOSE_LOGIN_FAILED );
				return $error;
			}
		}

		$this->client_capabilities = $client_flags & self::CAPABILITIES;
		$this->is_authenticated    = true;
		$response                  = '';
		if ( MySQL_Protocol::AUTH_PLUGIN_CACHING_SHA2_PASSWORD === $plugin ) {
			$response = MySQL_Protocol::build_packet(
				$this->packet_id++,
				pack( 'CC', MySQL_Protocol::AUTH_MORE_DATA_HEADER, MySQL_Protocol::CACHING_SHA2_FAST_AUTH )
			);
		}
		return $response . MySQL_Protocol::build_ok_packet( $this->packet_id++, $this->status_flags );
	}

	/**
	 * Deny authentication and discard any pipelined commands.
	 *
	 * @return string The access denied packet.
	 */
	private function reject_authentication(): string {
		$this->close( self::CLOSE_LOGIN_FAILED );
		return MySQL_Protocol::build_err_packet( $this->packet_id++, 1045, '28000', 'Access denied' );
	}

	/**
	 * Stop serving this connection and discard any pipelined commands.
	 *
	 * @param string $reason One of the CLOSE_* reasons.
	 */
	private function close( string $reason ): void {
		$this->close_reason           = $reason;
		$this->buffer                 = '';
		$this->pending_authentication = null;
	}

	/**
	 * Select a client-supplied database, for COM_INIT_DB and the login.
	 *
	 * The name is quoted as an identifier. Like MySQL, any failure is reported
	 * as an unknown database.
	 *
	 * @param  string $database The database name.
	 * @return string|null      The error packet, or null when the database is selected.
	 */
	private function use_database( string $database ): ?string {
		try {
			$result = $this->adapter->handle_query( 'USE `' . str_replace( '`', '``', $database ) . '`' );
			$failed = (bool) $result->error_info;
		} catch ( Throwable $e ) {
			$this->log_internal_error( $e );
			$failed = true;
		}
		if ( ! $failed ) {
			return null;
		}
		return MySQL_Protocol::build_err_packet(
			$this->packet_id++,
			1049,
			'42000',
			sprintf( "Unknown database: '%s'", $database )
		);
	}

	/**
	 * Process a MySQL query from the client.
	 *
	 * @param  string $query The query to process.
	 * @return string        The query response packet.
	 */
	private function process_query( string $query ): string {
		$query = trim( $query );

		try {
			$result = $this->adapter->handle_query( $query );
			if ( $result->error_info ) {
				return MySQL_Protocol::build_err_packet(
					$this->packet_id++,
					$result->error_info[1],
					$result->error_info[0],
					$result->error_info[2]
				);
			}

			if ( count( $result->columns ) > 0 ) {
				return $this->build_result_set_packets(
					$result->columns,
					$result->rows,
					$result->affected_rows,
					$result->last_insert_id
				);
			}

			return MySQL_Protocol::build_ok_packet(
				$this->packet_id++,
				$this->status_flags,
				$result->affected_rows,
				$result->last_insert_id
			);
		} catch ( Throwable $e ) {
			$this->log_internal_error( $e );
			return MySQL_Protocol::build_err_packet( $this->packet_id++, 1105, 'HY000', 'Unknown error' );
		}
	}

	/**
	 * Log an internal error, which must not reach the client.
	 *
	 * Its message can contain internal details, such as file paths.
	 *
	 * @param Throwable $error The internal error.
	 */
	private function log_internal_error( Throwable $error ): void {
		$this->logger->error(
			'Client [{client_id}]: internal error: {error}',
			array(
				'client_id' => $this->connection_id,
				'error'     => $error->getMessage(),
			)
		);
	}

	/**
	 * Build the result set packets for a MySQL query.
	 *
	 * @see https://dev.mysql.com/doc/dev/mysql-server/latest/page_protocol_com_query_response_text_resultset.html
	 *
	 * @param  array  $columns The columns of the result set.
	 * @param  array  $rows    The rows of the result set.
	 * @return string          The result set packets.
	 */
	private function build_result_set_packets( array $columns, array $rows, int $affected_rows, int $last_insert_id ): string {
		// Columns.
		$packets = MySQL_Protocol::build_column_count_packet( $this->packet_id++, count( $columns ) );
		foreach ( $columns as $column ) {
			$packets .= MySQL_Protocol::build_column_definition_packet( $this->packet_id++, $column );
		}

		// EOF packet, if CLIENT_DEPRECATE_EOF is not supported.
		if ( ! ( $this->client_capabilities & MySQL_Protocol::CLIENT_DEPRECATE_EOF ) ) {
			$packets .= MySQL_Protocol::build_eof_packet( $this->packet_id++, $this->status_flags );
		}

		// Rows.
		foreach ( $rows as $row ) {
			$packets .= MySQL_Protocol::build_row_packet( $this->packet_id++, $columns, $row );
		}

		// OK or EOF packet, based on the CLIENT_DEPRECATE_EOF capability.
		if ( $this->client_capabilities & MySQL_Protocol::CLIENT_DEPRECATE_EOF ) {
			$packets .= MySQL_Protocol::build_ok_packet_as_eof(
				$this->packet_id++,
				$this->status_flags,
				$affected_rows,
				$last_insert_id
			);
		} else {
			$packets .= MySQL_Protocol::build_eof_packet( $this->packet_id++, $this->status_flags );
		}
		return $packets;
	}
}
