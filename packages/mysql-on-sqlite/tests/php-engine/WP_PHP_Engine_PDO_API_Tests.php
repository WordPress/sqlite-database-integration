<?php

require_once __DIR__ . '/../WP_PDO_MySQL_On_SQLite_PDO_API_Tests.php';

/**
 * Runs the MySQL-on-SQLite PDO API test suite against the pure-PHP database
 * engine instead of a real SQLite database.
 */
class WP_PHP_Engine_PDO_API_Tests extends WP_PDO_MySQL_On_SQLite_PDO_API_Tests {
	/**
	 * Create a driver backed by the pure-PHP database engine.
	 */
	protected function create_driver(): WP_PDO_MySQL_On_SQLite {
		return new WP_PDO_MySQL_On_SQLite(
			'mysql-on-sqlite:dbname=wp;',
			null,
			null,
			array( 'pdo' => new WP_PHP_Engine_PDO( 'php-engine::memory:' ) )
		);
	}

	/**
	 * The PDO C implementation of PDO::FETCH_NAMED produces arrays with
	 * numeric string keys (e.g. "1"), which cannot be represented in plain
	 * PHP arrays — PHP always casts them to integers. Compare the keys
	 * by their string values instead.
	 *
	 * @dataProvider data_pdo_fetch_methods
	 */
	public function test_fetch( $query, $mode, $expected ): void {
		if ( PDO::FETCH_NAMED !== $mode ) {
			parent::test_fetch( $query, $mode, $expected );
			return;
		}
		$stmt   = $this->driver->query( $query );
		$result = $stmt->fetch( $mode );
		$this->assertSame(
			array_map( 'strval', array_keys( $expected ) ),
			array_map( 'strval', array_keys( $result ) )
		);
		$this->assertSame( array_values( $expected ), array_values( $result ) );
	}

	/**
	 * See test_fetch() above for the PDO::FETCH_NAMED key handling.
	 *
	 * @dataProvider data_pdo_fetch_methods
	 */
	public function test_query_with_fetch_mode( $query, $mode, $expected ): void {
		if ( PDO::FETCH_NAMED !== $mode ) {
			parent::test_query_with_fetch_mode( $query, $mode, $expected );
			return;
		}
		$stmt   = $this->driver->query( $query, $mode );
		$result = $stmt->fetch();
		$this->assertSame(
			array_map( 'strval', array_keys( $expected ) ),
			array_map( 'strval', array_keys( $result ) )
		);
		$this->assertSame( array_values( $expected ), array_values( $result ) );
	}
}
