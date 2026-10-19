<?php declare( strict_types = 1 );

/*
 * Allow silencing errors for socket functions. We check the return values.
 * phpcs:disable WordPress.PHP.NoSilencedErrors.Discouraged
 */

namespace WP_MySQL_Proxy;

use InvalidArgumentException;
use Socket;
use Throwable;
use WP_MySQL_Proxy\Adapter\Adapter;

/**
 * A MySQL proxy.
 *
 * This class manages MySQL client connections and uses an adapter instance to
 * execute MySQL queries and return their results.
 */
class MySQL_Proxy {
	/**
	 * Seconds a client may take to log in, and again to answer an authentication switch.
	 *
	 * This matches the default MySQL "connect_timeout".
	 */
	const LOGIN_TIMEOUT = 10;

	/**
	 * Seconds a logged-in client may stay connected without sending anything.
	 *
	 * This matches the default MySQL "wait_timeout".
	 */
	const IDLE_TIMEOUT = 28800;

	/**
	 * Seconds a logged-in client may take to send more of a request it has started.
	 *
	 * This matches the default MySQL "net_read_timeout".
	 */
	const READ_TIMEOUT = 30;

	/**
	 * Seconds a client may take to accept more of a pending response.
	 *
	 * This matches the default MySQL "net_write_timeout".
	 */
	const WRITE_TIMEOUT = 60;

	/**
	 * Largest number of bytes read from a client at once.
	 *
	 * Large reads keep the passes through the proxy loop few for a large message.
	 */
	const READ_CHUNK_SIZE = 65536;

	/**
	 * Largest number of bytes passed to a single socket write.
	 *
	 * Writing a pending response in chunks avoids copying the rest of it on
	 * every write.
	 */
	const WRITE_CHUNK_SIZE = 65536;

	/**
	 * Number of client connections served at once.
	 *
	 * This matches the default MySQL "max_connections".
	 */
	const MAX_CONNECTIONS = 151;

	/**
	 * The socket to listen on.
	 *
	 * @var Socket|resource
	 */
	private $socket;

	/**
	 * The port to listen on.
	 *
	 * @var int
	 */
	private $port;

	/**
	 * The IP address to listen on.
	 *
	 * @var string
	 */
	private $bind_address;

	/**
	 * The adapter to use to execute queries.
	 *
	 * @var Adapter
	 */
	private $adapter;

	/**
	 * The configured account verifier.
	 *
	 * @var MySQL_Authentication
	 */
	private $authentication;

	/**
	 * A map of connected clients.
	 *
	 * Maps client IDs to their socket, session, deadline, and the response that
	 * the client has not accepted yet, with the number of its bytes already sent.
	 *
	 * @var array<int, array{socket: Socket|resource, session: MySQL_Session, deadline: int, output: string, sent: int}>
	 */
	private $clients = array();

	/**
	 * Logger instance.
	 *
	 * @var Logger
	 */
	private $logger;

	/**
	 * Constructor.
	 *
	 * @param Adapter $adapter The adapter to use to execute MySQL queries.
	 * @param array   $options {
	 *     An associative array of options. The account is required.
	 *
	 *     @type MySQL_Authentication $authentication The account clients log in with. Required.
	 *     @type string               $bind_address   The IPv4 or IPv6 address to listen on. Default: 127.0.0.1
	 *     @type int                  $port           The port to listen on. Default: 3306
	 *     @type string               $log_level      The log level to use. One of 'error', 'warning', 'info', 'debug'.
	 *                                                Default: 'warning'
	 * }
	 */
	public function __construct( Adapter $adapter, $options = array() ) {
		$this->bind_address = $options['bind_address'] ?? '127.0.0.1';
		if ( ! is_string( $this->bind_address ) || false === filter_var( $this->bind_address, FILTER_VALIDATE_IP ) ) {
			throw new InvalidArgumentException( 'The bind address must be a valid IPv4 or IPv6 address.' );
		}

		$authentication = $options['authentication'] ?? null;
		if ( ! $authentication instanceof MySQL_Authentication ) {
			throw new InvalidArgumentException( 'The authentication option must be a MySQL_Authentication account.' );
		}

		$this->authentication = $authentication;
		$this->adapter        = $adapter;
		$this->port           = $options['port'] ?? 3306;
		$this->logger         = new Logger( $options['log_level'] ?? Logger::LEVEL_WARNING );
	}

	/**
	 * Start the MySQL proxy.
	 *
	 * This method creates a socket, binds it to a port, and handles connections.
	 */
	public function start(): void {
		// Create a socket.
		$family = false === strpos( $this->bind_address, ':' ) ? AF_INET : AF_INET6;
		$socket = @socket_create( $family, SOCK_STREAM, SOL_TCP );
		if ( false === $socket ) {
			throw $this->new_socket_error();
		}
		$this->socket = $socket;

		// Set socket options.
		if ( false === @socket_set_option( $this->socket, SOL_SOCKET, SO_REUSEADDR, 1 ) ) {
			throw $this->new_socket_error();
		}

		// Bind the socket to a port.
		if ( false === @socket_bind( $this->socket, $this->bind_address, $this->port ) ) {
			throw $this->new_socket_error();
		}

		// Listen for connections.
		if ( false === @socket_listen( $this->socket ) ) {
			throw $this->new_socket_error();
		}

		$this->logger->info(
			'MySQL proxy listening on {address}:{port}',
			array(
				'address' => AF_INET6 === $family ? '[' . $this->bind_address . ']' : $this->bind_address,
				'port'    => $this->port,
			)
		);

		// Start the main proxy loop.
		while ( true ) {
			try {
				$this->handle_socket_activity();
			} catch ( Throwable $e ) {
				$this->logger->error( $e->getMessage() );
			}
		}
	}

	/**
	 * Wait for socket activity, close clients past their deadline, and serve the rest.
	 */
	private function handle_socket_activity(): void {
		// Wait for activity on any socket, waking up for the next deadline.
		// A client is not read while it has a response it has not accepted.
		$read  = array( $this->socket );
		$write = array();
		foreach ( $this->clients as $client ) {
			if ( '' === $client['output'] ) {
				$read[] = $client['socket'];
			} else {
				$write[] = $client['socket'];
			}
		}
		$except = null;
		if ( false === @socket_select( $read, $write, $except, $this->get_select_timeout() ) ) {
			throw $this->new_socket_error();
		}

		// Drop clients that missed their login, idle, or write deadline. Check only
		// after waiting, so data that arrived while the proxy was busy is served.
		$this->close_timed_out_clients( array_merge( $read, $write ) );

		// Clients that can accept more of a pending response.
		foreach ( $write as $socket ) {
			$this->handle_client_writable( $this->get_client_id( $socket ) );
		}

		// New client connection.
		if ( in_array( $this->socket, $read, true ) ) {
			$this->handle_new_client();
			unset( $read[ array_search( $this->socket, $read, true ) ] );
		}

		// Handle client activity.
		foreach ( $read as $socket ) {
			$this->handle_client_activity( $this->get_client_id( $socket ) );
		}
	}

	/**
	 * Close connections past their deadline.
	 *
	 * A client must log in within LOGIN_TIMEOUT. A logged-in client must then
	 * accept more of a pending response within WRITE_TIMEOUT, send more of a
	 * started request within READ_TIMEOUT, and otherwise send something within
	 * IDLE_TIMEOUT.
	 *
	 * A client with activity is served even past its deadline. The proxy may have
	 * been busy with another client's query when that client's data arrived.
	 *
	 * @param array $ready_sockets The sockets that have activity to serve.
	 */
	private function close_timed_out_clients( array $ready_sockets ): void {
		$now = $this->now();
		foreach ( $this->clients as $client_id => $client ) {
			if ( $client['deadline'] > $now || in_array( $client['socket'], $ready_sockets, true ) ) {
				continue;
			}
			$context = array( 'client_id' => $client_id );
			if ( ! $client['session']->is_authenticated() ) {
				$this->logger->error( 'Client [{client_id}]: login timed out, closing connection', $context );
			} elseif ( '' !== $client['output'] ) {
				$this->logger->error( 'Client [{client_id}]: response not accepted in time, closing connection', $context );
			} elseif ( $client['session']->has_buffered_data() ) {
				$this->logger->error( 'Client [{client_id}]: request not completed in time, closing connection', $context );
			} else {
				$this->logger->info( 'Client [{client_id}]: idle for too long, closing connection', $context );
			}
			$this->close_client( $client_id );
		}
	}

	/**
	 * Get the seconds to wait for socket activity.
	 *
	 * @return int|null The seconds until the next deadline, or null to wait indefinitely.
	 */
	private function get_select_timeout(): ?int {
		if ( array() === $this->clients ) {
			return null;
		}
		return max( 0, min( array_column( $this->clients, 'deadline' ) ) - $this->now() );
	}

	/**
	 * Send more of a pending response, then serve requests that wait behind it.
	 *
	 * @param int $client_id The numeric ID of the client.
	 */
	private function handle_client_writable( int $client_id ): void {
		if ( ! $this->send_pending_output( $client_id ) ) {
			return;
		}
		$client = $this->clients[ $client_id ] ?? null;
		if ( null !== $client && '' === $client['output'] && $client['session']->has_buffered_data() ) {
			$this->process_client_input( $client_id, '' );
		}
		$this->restart_deadline( $client_id );
	}

	/**
	 * Handle a new MySQL client connection.
	 */
	private function handle_new_client(): void {
		$this->logger->info( 'Connecting a new client' );

		// Accept the new client connection.
		$socket = @socket_accept( $this->socket );
		if ( false === $socket ) {
			throw $this->new_socket_error();
		}

		// Refuse the connection while the proxy is full, as MySQL does.
		if ( count( $this->clients ) >= self::MAX_CONNECTIONS ) {
			$this->logger->error(
				'Refusing a new client: {count} connections are already open',
				array( 'count' => count( $this->clients ) )
			);
			// The send buffer of a new connection is empty, so it takes this short packet whole.
			// The client has not sent its capabilities, so the packet has no SQL state.
			@socket_write( $socket, MySQL_Protocol::build_err_packet( 0, 1040, null, 'Too many connections' ) );
			@socket_close( $socket );
			return;
		}

		// Never wait for a client, so one that stops reading cannot stall the proxy.
		if ( false === @socket_set_nonblock( $socket ) ) {
			$error = $this->new_socket_error();
			@socket_close( $socket );
			throw $error;
		}

		// Create a new session for the client.
		$client_id                   = $this->get_client_id( $socket );
		$session                     = new MySQL_Session( $this->adapter, $client_id, $this->authentication, $this->logger );
		$this->clients[ $client_id ] = array(
			'socket'   => $socket,
			'session'  => $session,
			'deadline' => $this->now() + self::LOGIN_TIMEOUT,
			'output'   => '',
			'sent'     => 0,
		);
		$this->logger->info( 'Client [{client_id}]: connected', array( 'client_id' => $client_id ) );

		// Handle the initial handshake.
		$this->logger->info( 'Client [{client_id}]: initial handshake', array( 'client_id' => $client_id ) );
		$this->send_response( $client_id, $session->get_initial_handshake() );
	}

	/**
	 * Handle client activity.
	 *
	 * @param int $client_id The numeric ID of the client.
	 */
	private function handle_client_activity( int $client_id ): void {
		$this->logger->info( 'Client [{client_id}]: reading data from client', array( 'client_id' => $client_id ) );

		// Read data from the client. The socket does not block, so it may have none yet.
		$socket = $this->clients[ $client_id ]['socket'];
		$data   = @socket_read( $socket, self::READ_CHUNK_SIZE );
		if ( false === $data && SOCKET_EWOULDBLOCK === socket_last_error( $socket ) ) {
			return;
		}
		if ( false === $data ) {
			$this->close_client_on_error( $client_id );
			return;
		}

		// Handle client disconnection.
		if ( '' === $data ) {
			$this->logger->info( 'Client [{client_id}]: disconnected', array( 'client_id' => $client_id ) );
			$this->close_client( $client_id );
			return;
		}

		$this->process_client_input( $client_id, $data );
		$this->restart_deadline( $client_id );
	}

	/**
	 * Pass client data to the session and send the responses to complete messages.
	 *
	 * Incomplete input stays in the session for the next read. Processing stops
	 * at a response the client has not accepted, so a client that does not read
	 * makes the proxy hold one response, not one for each request.
	 *
	 * @param int    $client_id The numeric ID of the client.
	 * @param string $data      The data received from the client.
	 */
	private function process_client_input( int $client_id, string $data ): void {
		// Do not dump request bytes: even authenticated clients can send credentials
		// in COM_CHANGE_USER, including across multiple reads or buffered packets.
		$session = $this->clients[ $client_id ]['session'];
		do {
			try {
				$response = $session->receive_bytes( $data );
			} catch ( Incomplete_Input_Exception $e ) {
				return;
			}
			$data = '';

			if ( null !== $response && '' !== $response ) {
				// Do not dump response bytes: they carry the data a query returned.
				$this->logger->info(
					'Client [{client_id}]: writing {bytes} response bytes',
					array(
						'client_id' => $client_id,
						'bytes'     => strlen( $response ),
					)
				);

				// Before login, only an authentication switch request keeps the connection
				// open, and a session sends at most one. Like MySQL's per-read connect_timeout,
				// the client gets a full login timeout to answer it, even after a late read.
				if ( ! $session->is_authenticated() && ! $session->is_closed() ) {
					$this->clients[ $client_id ]['deadline'] = $this->now() + self::LOGIN_TIMEOUT;
				}
			}
			$this->send_response( $client_id, (string) $response );
		} while (
			isset( $this->clients[ $client_id ] )
			&& '' === $this->clients[ $client_id ]['output']
			&& $session->has_buffered_data()
		);
	}

	/**
	 * Queue a response and send as much of it as the client accepts now.
	 *
	 * @param int    $client_id The numeric ID of the client.
	 * @param string $response  The response packets.
	 */
	private function send_response( int $client_id, string $response ): void {
		$this->clients[ $client_id ]['output'] .= $response;
		$this->send_pending_output( $client_id );
	}

	/**
	 * Send as much of a pending response as the client socket accepts now.
	 *
	 * The connection of a closed session is closed once its response is sent.
	 *
	 * @param  int  $client_id The numeric ID of the client.
	 * @return bool            Whether any bytes were sent.
	 */
	private function send_pending_output( int $client_id ): bool {
		$client = $this->clients[ $client_id ];
		$length = strlen( $client['output'] );
		$sent   = $client['sent'];
		while ( $sent < $length ) {
			$written = @socket_write( $client['socket'], substr( $client['output'], $sent, self::WRITE_CHUNK_SIZE ) );
			if ( false === $written && SOCKET_EWOULDBLOCK !== socket_last_error( $client['socket'] ) ) {
				$this->close_client_on_error( $client_id );
				return false;
			}
			if ( ! $written ) {
				break;
			}
			$sent += $written;
		}

		if ( $sent < $length ) {
			$this->clients[ $client_id ]['sent'] = $sent;
		} else {
			$this->clients[ $client_id ]['output'] = '';
			$this->clients[ $client_id ]['sent']   = 0;
			if ( $client['session']->is_closed() ) {
				$this->log_closed_session( $client_id, $client['session']->get_close_reason() );
				$this->close_client( $client_id );
			}
		}
		return $sent > $client['sent'];
	}

	/**
	 * Restart the deadline of a logged-in client that made progress.
	 *
	 * Before login, progress does not extend the login deadline.
	 *
	 * @param int $client_id The numeric ID of the client.
	 */
	private function restart_deadline( int $client_id ): void {
		$client = $this->clients[ $client_id ] ?? null;
		if ( null === $client || ! $client['session']->is_authenticated() ) {
			return;
		}
		if ( '' !== $client['output'] ) {
			$timeout = self::WRITE_TIMEOUT;
		} elseif ( $client['session']->has_buffered_data() ) {
			// The rest of a started request is pending.
			$timeout = self::READ_TIMEOUT;
		} else {
			$timeout = self::IDLE_TIMEOUT;
		}
		$this->clients[ $client_id ]['deadline'] = $this->now() + $timeout;
	}

	/**
	 * Get the current time in seconds for deadlines.
	 *
	 * Unlike time(), the monotonic hrtime() does not jump when the system clock
	 * changes. PHP 7.2 has no monotonic clock, so it uses time().
	 *
	 * @return int The seconds from an arbitrary starting point.
	 */
	private function now(): int {
		$time = function_exists( 'hrtime' ) ? hrtime() : false;
		return false === $time ? time() : $time[0];
	}

	/**
	 * Log why a session stopped serving its connection.
	 *
	 * @param int    $client_id The numeric ID of the client.
	 * @param string $reason    One of the MySQL_Session CLOSE_* reasons.
	 */
	private function log_closed_session( int $client_id, string $reason ): void {
		$message = 'Client [{client_id}]: {reason}, closing connection';
		$context = array(
			'client_id' => $client_id,
			'reason'    => $reason,
		);
		if ( MySQL_Session::CLOSE_CLIENT_QUIT === $reason ) {
			$this->logger->info( $message, $context );
		} else {
			$this->logger->error( $message, $context );
		}
	}

	/**
	 * Log the error of a failed client socket and close the connection.
	 *
	 * @param int $client_id The numeric ID of the client.
	 */
	private function close_client_on_error( int $client_id ): void {
		$this->logger->error(
			'Client [{client_id}]: {error}, closing connection',
			array(
				'client_id' => $client_id,
				'error'     => socket_strerror( socket_last_error( $this->clients[ $client_id ]['socket'] ) ),
			)
		);
		$this->close_client( $client_id );
	}

	/**
	 * Forget a client and close its connection, discarding any unsent response.
	 *
	 * @param int $client_id The numeric ID of the client.
	 */
	private function close_client( int $client_id ): void {
		$socket = $this->clients[ $client_id ]['socket'];
		unset( $this->clients[ $client_id ] );
		@socket_close( $socket );
	}

	/**
	 * Get a numeric ID for a client connected to the proxy.
	 *
	 * @param  resource|object $socket The client Socket object or resource.
	 * @return int                     The numeric ID of the client.
	 */
	private function get_client_id( $socket ): int {
		if ( is_resource( $socket ) ) {
			return (int) $socket;
		} else {
			return spl_object_id( $socket );
		}
	}

	/**
	 * Create a new MySQL proxy exception for the last socket error.
	 *
	 * @return MySQL_Proxy_Exception
	 */
	private function new_socket_error(): MySQL_Proxy_Exception {
		$error_code    = socket_last_error();
		$error_message = socket_strerror( $error_code );
		@socket_clear_error();
		return new MySQL_Proxy_Exception( sprintf( 'Socket error: %s', $error_message ) );
	}
}
