<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/fixtures/class-wpdb-stub.php';
require_once __DIR__ . '/../../plugin-sqlite-database-integration/wp-includes/sqlite/class-wp-sqlite-db.php';

class WP_SQLite_DB_Tests extends TestCase {
	/** @var WP_MySQL_On_SQLite */
	private $driver;

	public function setUp(): void {
		$pdo_class = PHP_VERSION_ID >= 80400 ? PDO\SQLite::class : PDO::class;
		$pdo       = new $pdo_class( 'sqlite::memory:' );

		$this->driver = new WP_MySQL_On_SQLite(
			'mysql-on-sqlite:dbname=wp',
			null,
			null,
			array( 'pdo' => $pdo )
		);
	}

	public function test_exposes_mysql_on_sqlite_driver(): void {
		$wpdb = new class( $this->driver ) extends WP_SQLite_DB {
			public function __construct( WP_MySQL_On_SQLite $driver ) {
				$this->dbh = $driver;
			}
		};

		$this->assertSame( $this->driver, $wpdb->get_driver() );
	}

	public function test_rejects_driver_access_without_database_connection(): void {
		$wpdb = new class() extends WP_SQLite_DB {
			public function __construct() {
				$this->dbh = null;
			}
		};

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'Cannot access the driver without an active database connection.' );
		$wpdb->get_driver();
	}

	public function test_load_col_info_without_result(): void {
		$wpdb = new class( $this->driver ) extends WP_SQLite_DB {
			public $col_info;
			public $last_error;
			public $last_query;
			public $last_result;
			public $num_rows;
			public $result;
			public $rows_affected;

			public function __construct( WP_MySQL_On_SQLite $driver ) {
				$this->dbh = $driver;
			}

			public function get_loaded_col_info(): array {
				$this->load_col_info();
				return $this->col_info;
			}
		};

		$this->assertSame( array(), $wpdb->get_loaded_col_info() );

		$wpdb->flush();

		$this->assertSame( array(), $wpdb->get_loaded_col_info() );
	}

	/**
	 * @dataProvider dataMysqlEscaping
	 */
	public function testRealEscapeMatchesMysql( string $value, string $expected ): void {
		$wpdb = new class( $this->driver ) extends WP_SQLite_DB {
			public function __construct( ?WP_MySQL_On_SQLite $driver ) {
				$this->dbh = $driver;
			}

			public function add_placeholder_escape( $query ) {
				return $query;
			}
		};

		$this->assertSame( $expected, $wpdb->_real_escape( $value ) );
	}

	public static function dataMysqlEscaping(): array {
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

	public function testRealEscapePreservesValueInSqlStringLiteral(): void {
		$wpdb = new class( $this->driver ) extends WP_SQLite_DB {
			public function __construct( ?WP_MySQL_On_SQLite $driver ) {
				$this->dbh = $driver;
			}

			public function add_placeholder_escape( $query ) {
				return $query;
			}
		};

		$value   = chr( 0 ) . "\n\r\\'\"" . chr( 26 ) . chr( 8 ) . "\tƮềʂᴛ🙂";
		$escaped = $wpdb->_real_escape( $value );
		$result  = $this->driver->query( "SELECT '$escaped'" );

		$this->assertSame( $value, $result->fetchColumn() );
	}

	public function testRealEscapeRequiresDatabaseConnection(): void {
		$wpdb = new class( null ) extends WP_SQLite_DB {
			public function __construct( ?WP_MySQL_On_SQLite $driver ) {
				$this->dbh = $driver;
			}
		};

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'Cannot escape data without an active database connection.' );
		$wpdb->_real_escape( 'value' );
	}
}
