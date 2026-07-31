<?php

use PHPUnit\Framework\TestCase;

class WP_SQLite_Driver_Compatibility_Tests extends TestCase {
	/** @var WP_SQLite_Driver */
	private $driver;

	/** @var PDO */
	private $sqlite;

	public function setUp(): void {
		$pdo_class    = PHP_VERSION_ID >= 80400 ? PDO\SQLite::class : PDO::class;
		$this->sqlite = new $pdo_class( 'sqlite::memory:' );
		$this->driver = new WP_SQLite_Driver(
			new WP_SQLite_Connection( array( 'pdo' => $this->sqlite ) ),
			'wp'
		);
		$this->driver->query( 'CREATE TABLE t (id INT AUTO_INCREMENT PRIMARY KEY, value VARCHAR(255))' );
	}

	public function test_wraps_renamed_driver(): void {
		$get_driver = Closure::bind(
			function () {
				return $this->mysql_on_sqlite_driver;
			},
			$this->driver,
			WP_SQLite_Driver::class
		);

		$this->assertInstanceOf( WP_MySQL_On_SQLite::class, $get_driver() );
		$this->assertSame( $this->sqlite, $this->driver->get_connection()->get_pdo() );
		$this->assertSame( $this->driver->get_sqlite_version(), $this->driver->client_info );
		$this->assertSame( SQLITE_DRIVER_VERSION, $this->driver->get_saved_driver_version() );
		$this->assertTrue( $this->driver->is_sql_mode_active( 'STRICT_TRANS_TABLES' ) );
	}

	public function test_preserves_legacy_query_results(): void {
		$this->assertSame( 1, $this->driver->query( "INSERT INTO t (value) VALUES ('first')" ) );
		$this->assertSame( 1, $this->driver->get_insert_id() );

		$result = $this->driver->query( 'SELECT id, value FROM t', PDO::FETCH_ASSOC );
		$this->assertSame(
			array(
				array(
					'id'    => '1',
					'value' => 'first',
				),
			),
			$result
		);
		$this->assertSame( $result, $this->driver->get_query_results() );
		$this->assertSame( $result, $this->driver->get_last_return_value() );
		$this->assertSame( 2, $this->driver->get_last_column_count() );
		$this->assertCount( 2, $this->driver->get_last_column_meta() );
	}

	public function test_delegates_diagnostics_and_native_queries(): void {
		$this->driver->query( 'SELECT 1' );

		$this->assertSame( 'SELECT 1', $this->driver->get_last_mysql_query() );
		$this->assertNotEmpty( $this->driver->get_last_sqlite_queries() );
		$this->assertInstanceOf( WP_MySQL_Parser::class, $this->driver->create_parser( 'SELECT 1' ) );
		$this->assertSame( '42', $this->driver->execute_sqlite_query( 'SELECT 42' )->fetchColumn() );
	}

	public function test_preserves_transaction_method_aliases(): void {
		$this->driver->begin_transaction();
		$this->driver->query( "INSERT INTO t (value) VALUES ('rolled back')" );
		$this->driver->rollback();
		$this->assertSame( '0', $this->driver->query( 'SELECT COUNT(*) FROM t' )[0]->{'COUNT(*)'} );

		$this->driver->beginTransaction();
		$this->driver->query( "INSERT INTO t (value) VALUES ('committed')" );
		$this->driver->commit();
		$this->assertSame( '1', $this->driver->query( 'SELECT COUNT(*) FROM t' )[0]->{'COUNT(*)'} );
	}
}
