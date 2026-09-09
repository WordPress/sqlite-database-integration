<?php

use WP_MySQL_Proxy\MySQL_Protocol;

/**
 * Builds client packets independently of the server's credential verifier.
 */
class MySQL_Test_Client {
	/**
	 * The account plugin a PDO or mysqli client on this PHP version can use.
	 *
	 * PHP's mysqlnd supports caching_sha2_password as of 7.4.4. An older client
	 * refuses the authentication switch, so those tests need a native account.
	 * This class builds its own packets, so it is not subject to that limit.
	 */
	public static function auth_plugin(): string {
		return PHP_VERSION_ID >= 70404
			? MySQL_Protocol::AUTH_PLUGIN_CACHING_SHA2_PASSWORD
			: MySQL_Protocol::AUTH_PLUGIN_MYSQL_NATIVE_PASSWORD;
	}

	/**
	 * Read the result of a login, skipping the caching_sha2_password fast path packet.
	 */
	public static function read_login_result( $stream ): string {
		$packet = self::read_packet( $stream );
		if ( "\x01\x03" === substr( $packet, 4 ) ) {
			return self::read_packet( $stream );
		}
		return $packet;
	}

	public static function handshake_response(
		string $challenge,
		string $username = 'user',
		string $password = 'password',
		string $plugin = MySQL_Protocol::AUTH_PLUGIN_CACHING_SHA2_PASSWORD,
		string $database = '',
		?int $flags = null
	): string {
		$flags = $flags ?? ( MySQL_Protocol::CLIENT_PROTOCOL_41 | MySQL_Protocol::CLIENT_SECURE_CONNECTION
			| MySQL_Protocol::CLIENT_PLUGIN_AUTH | MySQL_Protocol::CLIENT_CONNECT_WITH_DB );
		$token = self::scramble( $password, $challenge, $plugin );
		$data  = pack( 'VVC', $flags, 0, MySQL_Protocol::CHARSET_UTF8MB4 ) . str_repeat( "\0", 23 );
		$data .= $username . "\0" . chr( strlen( $token ) ) . $token;
		if ( $flags & MySQL_Protocol::CLIENT_CONNECT_WITH_DB ) {
			$data .= $database . "\0";
		}
		if ( $flags & MySQL_Protocol::CLIENT_PLUGIN_AUTH ) {
			$data .= $plugin . "\0";
		}
		return MySQL_Protocol::build_packet( 1, $data );
	}

	public static function scramble( string $password, string $challenge, string $plugin ): string {
		if ( 'mysql_clear_password' === $plugin ) {
			return $password . "\0";
		}
		if ( '' === $password ) {
			return '';
		}
		$algorithm = MySQL_Protocol::AUTH_PLUGIN_MYSQL_NATIVE_PASSWORD === $plugin ? 'sha1' : 'sha256';
		$stage1    = hash( $algorithm, $password, true );
		$stage2    = hash( $algorithm, $stage1, true );
		$input     = 'sha1' === $algorithm ? $challenge . $stage2 : $stage2 . $challenge;
		return $stage1 ^ hash( $algorithm, $input, true );
	}

	public static function challenge( string $handshake ): string {
		$offset = strpos( $handshake, "\0", 5 ) + 5;
		return substr( $handshake, $offset, 8 ) . substr( $handshake, $offset + 27, 12 );
	}

	public static function read_packet( $stream ): string {
		$header = self::read_bytes( $stream, 4 );
		$length = unpack( 'V', substr( $header, 0, 3 ) . "\0" )[1];
		return $header . self::read_bytes( $stream, $length );
	}

	private static function read_bytes( $stream, int $length ): string {
		$data = '';
		while ( strlen( $data ) < $length ) {
			$chunk = fread( $stream, $length - strlen( $data ) );
			if ( false === $chunk || '' === $chunk ) {
				throw new RuntimeException( 'Connection closed or timed out before a complete packet arrived.' );
			}
			$data .= $chunk;
		}
		return $data;
	}
}
