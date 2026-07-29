<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/fixtures/class-wp-sqlite-db-test-double.php';

class WP_SQLite_DB_Tests extends TestCase {
	/** @var WP_MySQL_On_SQLite */
	private $driver;

	/** @var WP_SQLite_DB_Test_Double */
	private $wpdb;

	public function setUp(): void {
		$pdo_class = PHP_VERSION_ID >= 80400 ? PDO\SQLite::class : PDO::class;
		$pdo       = new $pdo_class( 'sqlite::memory:' );

		$this->driver = new WP_MySQL_On_SQLite(
			'mysql-on-sqlite:dbname=wp',
			null,
			null,
			array( 'pdo' => $pdo )
		);
		$this->wpdb   = new WP_SQLite_DB_Test_Double( $this->driver );
	}

	/**
	 * @dataProvider dataMysqlndDefaultEscaping
	 */
	public function testRealEscapeMatchesMysqlndWithBackslashEscapes( string $value, string $expected ): void {
		$this->setSqlMode( '' );

		$this->assertSame( $expected, $this->wpdb->_real_escape( $value ) );
	}

	public static function dataMysqlndDefaultEscaping(): array {
		return array(
			'ASCII null'      => array( chr( 0 ), '\\0' ),
			'newline'         => array( "\n", '\\n' ),
			'carriage return' => array( "\r", '\\r' ),
			'backslash'       => array( '\\', '\\\\' ),
			'single quote'    => array( "'", "\\'" ),
			'double quote'    => array( '"', '\\"' ),
			'Control+Z'       => array( chr( 26 ), '\\Z' ),
			'backspace'       => array( chr( 8 ), chr( 8 ) ),
			'tab'             => array( "\t", "\t" ),
			'UTF-8'           => array( 'Ʈềʂᴛ🙂', 'Ʈềʂᴛ🙂' ),
		);
	}

	/**
	 * @dataProvider dataMysqlndNoBackslashEscapes
	 */
	public function testRealEscapeMatchesMysqlndWithoutBackslashEscapes( string $value, string $expected ): void {
		$this->setSqlMode( 'NO_BACKSLASH_ESCAPES' );

		$this->assertSame( $expected, $this->wpdb->_real_escape( $value ) );
	}

	public static function dataMysqlndNoBackslashEscapes(): array {
		return array(
			'ASCII null'      => array( chr( 0 ), chr( 0 ) ),
			'newline'         => array( "\n", "\n" ),
			'carriage return' => array( "\r", "\r" ),
			'backslash'       => array( '\\', '\\' ),
			'single quote'    => array( "'", "''" ),
			'double quote'    => array( '"', '"' ),
			'Control+Z'       => array( chr( 26 ), chr( 26 ) ),
			'backspace'       => array( chr( 8 ), chr( 8 ) ),
			'tab'             => array( "\t", "\t" ),
			'UTF-8'           => array( 'Ʈềʂᴛ🙂', 'Ʈềʂᴛ🙂' ),
		);
	}

	/**
	 * @dataProvider dataStringRoundTrips
	 */
	public function testRealEscapeRoundTripsThroughTheActiveSqlMode( string $mode, string $value ): void {
		$this->setSqlMode( $mode );

		$escaped = $this->wpdb->_real_escape( $value );
		$result  = $this->driver->query( "SELECT '$escaped'" );

		$this->assertSame( $value, $result->fetchColumn() );
	}

	public static function dataStringRoundTrips(): array {
		$special_characters = implode(
			'',
			array( chr( 0 ), "\n", "\r", '\\', "'", '"', chr( 26 ), chr( 8 ), "\t" )
		);

		return array(
			'default mode special characters'         => array( '', $special_characters ),
			'default mode UTF-8'                      => array( '', 'Ʈềʂᴛ🙂' ),
			'NO_BACKSLASH_ESCAPES special characters' => array( 'NO_BACKSLASH_ESCAPES', $special_characters ),
			'NO_BACKSLASH_ESCAPES UTF-8'              => array( 'NO_BACKSLASH_ESCAPES', 'Ʈềʂᴛ🙂' ),
		);
	}

	public function testRealEscapePreventsSqlInjectionWithoutBackslashEscapes(): void {
		$this->driver->query( 'CREATE TABLE users (username TEXT, secret TEXT)' );
		$this->driver->query( "INSERT INTO users VALUES ('admin', 'password-hash')" );
		$this->setSqlMode( 'NO_BACKSLASH_ESCAPES' );

		$payload = "nobody' UNION SELECT secret FROM users #";
		$escaped = $this->wpdb->_real_escape( $payload );
		$result  = $this->driver->query( "SELECT username FROM users WHERE username = '$escaped'" );

		$this->assertSame( array(), $result->fetchAll( PDO::FETCH_COLUMN ) );
	}

	private function setSqlMode( string $mode ): void {
		$this->driver->query( "SET SESSION sql_mode = '$mode'" );
	}
}
