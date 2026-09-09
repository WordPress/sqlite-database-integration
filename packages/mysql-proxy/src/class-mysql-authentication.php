<?php declare( strict_types = 1 );

namespace WP_MySQL_Proxy;

use InvalidArgumentException;

/**
 * Verifies MySQL challenge responses for a configured proxy account.
 */
class MySQL_Authentication {
	/**
	 * The account name.
	 *
	 * @var string
	 */
	private $username;

	/**
	 * Authentication plugins an account can use.
	 */
	const SUPPORTED_PLUGINS = array(
		MySQL_Protocol::AUTH_PLUGIN_CACHING_SHA2_PASSWORD,
		MySQL_Protocol::AUTH_PLUGIN_MYSQL_NATIVE_PASSWORD,
	);

	/**
	 * The authentication plugin of this account.
	 *
	 * @var string
	 */
	private $plugin;

	/**
	 * The double password hash for the account's plugin.
	 *
	 * @var string
	 */
	private $password_hash;

	/**
	 * Configure the account. The plaintext password is not retained.
	 *
	 * Like MySQL, an account uses a single authentication plugin, so a client
	 * cannot choose a weaker one.
	 *
	 * @param string $username The nonempty account name.
	 * @param string $password The nonempty password.
	 * @param string $plugin   One of SUPPORTED_PLUGINS. Default: caching_sha2_password.
	 */
	public function __construct(
		string $username,
		string $password,
		string $plugin = MySQL_Protocol::DEFAULT_AUTH_PLUGIN
	) {
		if ( '' === $username || false !== strpos( $username, "\0" ) ) {
			throw new InvalidArgumentException( 'A nonempty username without NUL bytes is required.' );
		}
		if ( '' === $password || false !== strpos( $password, "\0" ) ) {
			throw new InvalidArgumentException( 'A nonempty password without NUL bytes is required.' );
		}
		if ( ! in_array( $plugin, self::SUPPORTED_PLUGINS, true ) ) {
			throw new InvalidArgumentException(
				'The authentication plugin must be one of: ' . implode( ', ', self::SUPPORTED_PLUGINS ) . '.'
			);
		}

		$this->username      = $username;
		$this->plugin        = $plugin;
		$this->password_hash = MySQL_Protocol::AUTH_PLUGIN_CACHING_SHA2_PASSWORD === $plugin
			? hash( 'sha256', hash( 'sha256', $password, true ), true )
			: sha1( sha1( $password, true ), true );
	}

	/**
	 * The authentication plugin this account uses.
	 *
	 * A client that chose another plugin must switch to this one.
	 *
	 * @return string
	 */
	public function get_plugin(): string {
		return $this->plugin;
	}

	/**
	 * Verify a response using the challenge issued to this connection.
	 *
	 * @see https://dev.mysql.com/doc/dev/mysql-server/latest/page_caching_sha2_authentication_exchanges.html
	 * @see https://dev.mysql.com/doc/dev/mysql-server/latest/page_protocol_connection_phase_authentication_methods_native_password_authentication.html
	 *
	 * @param string $username  The client account name.
	 * @param string $plugin    The client authentication plugin.
	 * @param string $response  The client challenge response.
	 * @param string $challenge The server's 20-byte challenge.
	 * @return bool Whether the credentials match.
	 */
	public function verify( string $username, string $plugin, string $response, string $challenge ): bool {
		if ( $plugin !== $this->plugin || 20 !== strlen( $challenge ) ) {
			return false;
		}

		$password_hash = $this->password_hash;
		if ( strlen( $response ) !== strlen( $password_hash ) ) {
			return false;
		}

		if ( MySQL_Protocol::AUTH_PLUGIN_CACHING_SHA2_PASSWORD === $plugin ) {
			$mask      = hash( 'sha256', $password_hash . $challenge, true );
			$candidate = hash( 'sha256', $response ^ $mask, true );
		} else {
			$mask      = sha1( $challenge . $password_hash, true );
			$candidate = sha1( $response ^ $mask, true );
		}

		// Verify the password even for an unknown username.
		$password_matches = hash_equals( $password_hash, $candidate );
		$username_matches = hash_equals( $this->username, $username );
		return $password_matches && $username_matches;
	}
}
