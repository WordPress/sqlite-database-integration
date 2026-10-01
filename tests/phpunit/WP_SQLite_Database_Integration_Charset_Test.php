<?php

class WP_SQLite_Database_Integration_Charset_Test extends PHPUnit_Adapter_TestCase {

	public function test_charset_and_collation() {
		global $wpdb;

		$this->assertSame( 'utf8mb4', $wpdb->charset );
		$this->assertSame( 'utf8mb4_unicode_520_ci', $wpdb->collate );
		$this->assertSame(
			'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci',
			$wpdb->get_charset_collate()
		);
	}

	public function test_charset_and_collation_without_connection() {
		global $wpdb;

		// The SQLite driver uses the charset while connecting (e.g., to reconstruct tables).
		$this->assertTrue( $wpdb->close() );
		try {
			$wpdb->charset = null;
			$wpdb->collate = null;
			$wpdb->init_charset();
			$this->assertSame( 'utf8mb4', $wpdb->charset );
			$this->assertSame( 'utf8mb4_unicode_520_ci', $wpdb->collate );
		} finally {
			$this->assertTrue( $wpdb->check_connection() );
		}
	}

	public function test_wordpress_table_reconstructed_while_connecting() {
		global $wpdb;

		$table      = $wpdb->options;
		$driver     = $wpdb->get_driver();
		$connection = $driver->get_connection();
		$this->assertFalse( $driver->inTransaction() );

		// Remove the table from the information schema, and make the next connection reconstruct it.
		$connection->query(
			sprintf(
				'DELETE FROM %s WHERE table_name = ?',
				$connection->quote_identifier( WP_MySQL_On_SQLite::RESERVED_PREFIX . 'mysql_information_schema_tables' )
			),
			array( $table )
		);
		$connection->query(
			sprintf(
				'DELETE FROM %s WHERE name = ?',
				$connection->quote_identifier( WP_MySQL_On_SQLite::GLOBAL_VARIABLES_TABLE_NAME )
			),
			array( WP_MySQL_On_SQLite::DRIVER_VERSION_VARIABLE_NAME )
		);

		$original_wpdb = $wpdb;
		$this->assertTrue( $wpdb->close() );
		$db = null;
		try {
			// As in the SQLite drop-in, the constructor connects and reconstructs the table.
			$db        = new WP_SQLite_DB( DB_NAME );
			$collation = $db->get_var(
				$db->prepare(
					'SELECT table_collation FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = %s',
					$table
				)
			);
		} finally {
			if ( $db ) {
				$db->close();
			}
			$GLOBALS['wpdb'] = $original_wpdb;
			$this->assertTrue( $original_wpdb->check_connection() );
		}

		$this->assertSame( 'utf8mb4_unicode_520_ci', $collation );
	}

	public function test_table_created_with_charset_collate() {
		global $wpdb;

		$table = $wpdb->prefix . 'sqlite_charset_test';
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) );

		try {
			$wpdb->query( $wpdb->prepare( 'CREATE TABLE %i (id INT, name VARCHAR(20))', $table ) . ' ' . $wpdb->get_charset_collate() );
			$this->assertSame( '', $wpdb->last_error );

			$this->assertSame(
				'utf8mb4_unicode_520_ci',
				$wpdb->get_var(
					$wpdb->prepare(
						'SELECT table_collation FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = %s',
						$table
					)
				)
			);

			$create_table = $wpdb->get_row( $wpdb->prepare( 'SHOW CREATE TABLE %i', $table ), ARRAY_N )[1];
			$this->assertStringEndsWith( 'DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci', $create_table );
		} finally {
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) );
		}
	}
}
