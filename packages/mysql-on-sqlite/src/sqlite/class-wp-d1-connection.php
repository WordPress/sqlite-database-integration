<?php declare(strict_types = 1);

require_once __DIR__ . '/class-wp-d1-statement.php';

/**
 * Cloudflare D1 connection.
 *
 * A standalone client for executing SQLite statements through the Cloudflare D1
 * HTTP API.
 *
 * @access private
 */
class WP_D1_Connection {
	/**
	 * Cloudflare account ID.
	 *
	 * @var string
	 */
	private $account_id;

	/**
	 * Cloudflare D1 database ID.
	 *
	 * @var string
	 */
	private $database_id;

	/**
	 * Cloudflare API token.
	 *
	 * @var string
	 */
	private $api_token;

	/**
	 * Base API URL.
	 *
	 * @var string
	 */
	private $api_base;

	/**
	 * Last inserted row ID.
	 *
	 * @var int|string
	 */
	private $last_insert_id = 0;

	/**
	 * Constructor.
	 *
	 * @param array $options {
	 *     @type string $account_id  Cloudflare account ID.
	 *     @type string $database_id Cloudflare D1 database ID.
	 *     @type string $api_token   Cloudflare API token.
	 *     @type string $api_base    Optional API base URL.
	 * }
	 *
	 * @throws InvalidArgumentException When required options are missing.
	 */
	public function __construct( array $options ) {
		if ( empty( $options['account_id'] ) || ! is_string( $options['account_id'] ) ) {
			throw new InvalidArgumentException( 'Option "account_id" is required.' );
		}
		if ( empty( $options['database_id'] ) || ! is_string( $options['database_id'] ) ) {
			throw new InvalidArgumentException( 'Option "database_id" is required.' );
		}
		if ( empty( $options['api_token'] ) || ! is_string( $options['api_token'] ) ) {
			throw new InvalidArgumentException( 'Option "api_token" is required.' );
		}

		$this->account_id  = $options['account_id'];
		$this->database_id = $options['database_id'];
		$this->api_token   = $options['api_token'];
		$this->api_base    = $options['api_base'] ?? 'https://api.cloudflare.com/client/v4';
	}

	/**
	 * Execute a SQL query against D1.
	 *
	 * @param string $sql    SQL statement.
	 * @param array  $params Bound parameters.
	 *
	 * @return WP_D1_Statement
	 */
	public function query( string $sql, array $params = array() ): WP_D1_Statement {
		$statement = $this->prepare( $sql );
		$statement->execute( $params );
		return $statement;
	}

	/**
	 * Prepare a SQL statement.
	 *
	 * @param string $sql SQL statement.
	 *
	 * @return WP_D1_Statement
	 */
	public function prepare( string $sql ): WP_D1_Statement {
		return new WP_D1_Statement( $this, $sql, array() );
	}

	/**
	 * Quote a value (basic).
	 *
	 * @param mixed $value Value to quote.
	 * @param int   $type  PDO type.
	 *
	 * @return string
	 */
	public function quote( $value, int $type = 2 ): string {
		if ( is_null( $value ) ) {
			return 'NULL';
		}
		if ( is_bool( $value ) ) {
			return $value ? '1' : '0';
		}
		if ( is_int( $value ) || is_float( $value ) ) {
			return (string) $value;
		}
		return "'" . str_replace( "'", "''", (string) $value ) . "'";
	}

	/**
	 * Quote an identifier.
	 *
	 * @param string $identifier Identifier.
	 *
	 * @return string
	 */
	public function quote_identifier( string $identifier ): string {
		return '`' . str_replace( '`', '``', $identifier ) . '`';
	}

	/**
	 * Get last insert ID.
	 *
	 * @return int|string
	 */
	public function get_last_insert_id() {
		return $this->last_insert_id;
	}

	/**
	 * Set query logger (no-op for now).
	 *
	 * @param callable|null $logger Logger.
	 */
	public function set_query_logger( $logger ): void {
		// No-op.
	}

	/**
	 * Execute a request against the D1 API.
	 *
	 * @param string $sql    SQL statement.
	 * @param array  $params Parameters.
	 *
	 * @return array
	 *
	 * @throws RuntimeException On HTTP or API error.
	 */
	public function execute_remote( string $sql, array $params = array() ): array {
		$url  = rtrim( $this->api_base, '/' ) . '/accounts/' . rawurlencode( $this->account_id ) . '/d1/database/' . rawurlencode( $this->database_id ) . '/raw';
		$body = json_encode(
			array(
				'sql'    => $sql,
				'params' => array_values( $params ),
			)
		);

		$raw  = null;
		$code = 0;
		if ( function_exists( 'curl_init' ) ) {
			$ch = curl_init( $url );
			curl_setopt( $ch, CURLOPT_POST, true );
			curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
			curl_setopt(
				$ch,
				CURLOPT_HTTPHEADER,
				array(
					'Authorization: Bearer ' . $this->api_token,
					'Content-Type: application/json',
				)
			);
			curl_setopt( $ch, CURLOPT_POSTFIELDS, $body );
			curl_setopt( $ch, CURLOPT_TIMEOUT, 30 );
			$raw  = curl_exec( $ch );
			$code = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
			if ( false === $raw ) {
				$err = curl_error( $ch );
				curl_close( $ch );
				throw new RuntimeException( 'Failed to contact D1 API: ' . $err );
			}
			curl_close( $ch );
		} else {
			$context = stream_context_create(
				array(
					'http' => array(
						'method'  => 'POST',
						'header'  => "Authorization: Bearer {$this->api_token}\r\nContent-Type: application/json\r\n",
						'content' => $body,
						'timeout' => 30,
					),
				)
			);
			$raw     = @file_get_contents( $url, false, $context );
			if ( false === $raw ) {
				throw new RuntimeException( 'Failed to contact D1 API.' );
			}
			$code = 0;
			if ( isset( $http_response_header[0] ) ) {
				preg_match( '#HTTP/\S+\s+(\d+)#', $http_response_header[0], $m );
				$code = isset( $m[1] ) ? (int) $m[1] : 0;
			}
		}

		if ( $code >= 400 ) {
			throw new RuntimeException( 'D1 API HTTP error: ' . $code );
		}

		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) ) {
			throw new RuntimeException( 'Invalid D1 API response.' );
		}
		if ( empty( $decoded['success'] ) ) {
			$message = isset( $decoded['errors'][0]['message'] ) ? $decoded['errors'][0]['message'] : 'D1 API error.';
			throw new RuntimeException( $message );
		}

		$result = $decoded['result'][0] ?? array();
		if ( isset( $result['meta']['last_row_id'] ) ) {
			$this->last_insert_id = $result['meta']['last_row_id'];
		}

		$columns                = $result['results']['columns'] ?? array();
		$rows                   = $result['results']['rows'] ?? array();
		$result['column_count'] = count( $columns );
		$result['results']      = array();
		foreach ( $rows as $row ) {
			$result['results'][] = array_combine( $columns, $row );
		}

		return $result;
	}
}

// Backwards/alternative names.
class_alias( 'WP_D1_Connection', 'WP_Cloudflare_D1_Connection' );
