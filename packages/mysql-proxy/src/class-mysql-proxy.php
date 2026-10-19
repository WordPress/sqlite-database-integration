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
	 * Seconds a client may stay connected without completing a login.
	 *
	 * This matches the default MySQL "connect_timeout".
	 */
	const LOGIN_TIMEOUT = 10;

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
	 * Maps client IDs to their associated socket and session instances.
	 *
	 * @var array<int, array{socket: Socket|resource, session: MySQL_Session, login_deadline: int}>
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
	 *     An associative array of options. Username and password are required.
	 *
	 *     @type string $username     The nonempty proxy account name. Required.
	 *     @type string $password     The nonempty proxy password. Required.
	 *     @type string $auth_plugin  The account's authentication plugin, one of
	 *                                MySQL_Authentication::SUPPORTED_PLUGINS.
	 *                                Default: caching_sha2_password
	 *     @type string $bind_address The IPv4 or IPv6 address to listen on. Default: 127.0.0.1
	 *     @type int    $port         The port to listen on. Default: 3306
	 *     @type string $log_level    The log level to use. One of 'error', 'warning', 'info', 'debug'.
	 *                                Default: 'warning'
	 * }
	 */
	public function __construct( Adapter $adapter, $options = array() ) {
		$this->bind_address = $options['bind_address'] ?? '127.0.0.1';
		if ( ! is_string( $this->bind_address ) || false === filter_var( $this->bind_address, FILTER_VALIDATE_IP ) ) {
			throw new InvalidArgumentException( 'The bind address must be a valid IPv4 or IPv6 address.' );
		}

		$username = $options['username'] ?? '';
		$password = $options['password'] ?? '';
		if ( ! is_string( $username ) || ! is_string( $password ) ) {
			throw new InvalidArgumentException( 'The username and password must be strings.' );
		}
		$auth_plugin = $options['auth_plugin'] ?? MySQL_Protocol::DEFAULT_AUTH_PLUGIN;
		if ( ! is_string( $auth_plugin ) ) {
			throw new InvalidArgumentException( 'The authentication plugin must be a plugin name.' );
		}

		$this->authentication = new MySQL_Authentication( $username, $password, $auth_plugin );
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
				// Drop clients that connected but never completed a login.
				$this->close_timed_out_logins();

				// Wait for activity on any socket, waking up for the next login deadline.
				$read     = array_merge( array( $this->socket ), array_column( $this->clients, 'socket' ) );
				$write    = null;
				$except   = null;
				$activity = @socket_select( $read, $write, $except, $this->get_select_timeout() );
				if ( false === $activity ) {
					throw $this->new_socket_error();
				}

				// No activity on any socket.
				if ( $activity <= 0 ) {
					continue;
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
			} catch ( Throwable $e ) {
				$this->logger->error( $e->getMessage() );
			}
		}
	}

	/**
	 * Close connections that have not logged in before their deadline.
	 */
	private function close_timed_out_logins(): void {
		foreach ( $this->clients as $client_id => $client ) {
			if ( $client['session']->is_authenticated() || $client['login_deadline'] > time() ) {
				continue;
			}
			$this->logger->error( 'Client [{client_id}]: login timed out, closing connection', array( 'client_id' => $client_id ) );
			unset( $this->clients[ $client_id ] );
			@socket_close( $client['socket'] );
		}
	}

	/**
	 * Get the seconds to wait for socket activity.
	 *
	 * @return int|null The seconds until the next login deadline, or null to wait indefinitely.
	 */
	private function get_select_timeout(): ?int {
		$deadline = null;
		foreach ( $this->clients as $client ) {
			if ( $client['session']->is_authenticated() ) {
				continue;
			}
			$deadline = null === $deadline ? $client['login_deadline'] : min( $deadline, $client['login_deadline'] );
		}
		return null === $deadline ? null : max( 0, $deadline - time() );
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

		// Create a new session for the client.
		$client_id                   = $this->get_client_id( $socket );
		$session                     = new MySQL_Session( $this->adapter, $client_id, $this->authentication );
		$this->clients[ $client_id ] = array(
			'socket'         => $socket,
			'session'        => $session,
			'login_deadline' => time() + self::LOGIN_TIMEOUT,
		);
		$this->logger->info( 'Client [{client_id}]: connected', array( 'client_id' => $client_id ) );

		// Handle the initial handshake.
		$this->logger->info( 'Client [{client_id}]: initial handshake', array( 'client_id' => $client_id ) );
		$this->write_response( $socket, $session->get_initial_handshake() );
	}

	/**
	 * Handle client activity.
	 *
	 * @param int $client_id The numeric ID of the client.
	 */
	private function handle_client_activity( int $client_id ): void {
		$this->logger->info( 'Client [{client_id}]: reading data from client', array( 'client_id' => $client_id ) );

		// Read data from the client.
		$socket = $this->clients[ $client_id ]['socket'];
		$data   = @socket_read( $socket, 4096 );
		if ( false === $data ) {
			throw $this->new_socket_error();
		}

		// Do not dump request bytes: even authenticated clients can send credentials
		// in COM_CHANGE_USER, including across multiple reads or buffered packets.
		$session = $this->clients[ $client_id ]['session'];

		// Handle client disconnection.
		if ( '' === $data ) {
			$this->logger->info( 'Client [{client_id}]: disconnected', array( 'client_id' => $client_id ) );
			unset( $this->clients[ $client_id ] );
			@socket_close( $socket );
			return;
		}

		// Process complete packets, retaining incomplete input for the next read.
		do {
			try {
				$response = $session->receive_bytes( $data );
			} catch ( Incomplete_Input_Exception $e ) {
				break;
			}
			$data = '';

			try {
				if ( null !== $response && '' !== $response ) {
					// Do not dump response bytes: they carry the data a query returned.
					$this->logger->info(
						'Client [{client_id}]: writing {bytes} response bytes',
						array(
							'client_id' => $client_id,
							'bytes'     => strlen( $response ),
						)
					);
					$this->write_response( $socket, $response );
				}
			} finally {
				if ( $session->is_closed() ) {
					$this->logger->error( 'Client [{client_id}]: login failed, closing connection', array( 'client_id' => $client_id ) );
					unset( $this->clients[ $client_id ] );
					@socket_close( $socket );
				}
			}
		} while ( ! $session->is_closed() && $session->has_buffered_data() );
	}

	/**
	 * Write the complete response, including any authentication error.
	 *
	 * @param Socket|resource $socket   The client socket.
	 * @param string          $response The response packets.
	 */
	private function write_response( $socket, string $response ): void {
		$offset = 0;
		$length = strlen( $response );
		while ( $offset < $length ) {
			$written = @socket_write( $socket, substr( $response, $offset ) );
			if ( false === $written || 0 === $written ) {
				throw $this->new_socket_error();
			}
			$offset += $written;
		}
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
