<?php

class WP_SQLite_Database_Integration_Charset_Test extends PHPUnit_Adapter_TestCase {

	public function test_charset_and_collation_are_determined_after_connecting() {
		global $wpdb;

		$this->assertSame( 'utf8mb4', $wpdb->charset );
		$this->assertSame( 'utf8mb4_unicode_520_ci', $wpdb->collate );
		$this->assertSame(
			'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci',
			$wpdb->get_charset_collate()
		);
	}

	public function test_charset_and_collation_are_determined_after_reconnecting() {
		global $wpdb;

		$this->assertTrue( $wpdb->close() );
		$this->assertTrue( $wpdb->check_connection() );

		$this->assertSame( 'utf8mb4', $wpdb->charset );
		$this->assertSame( 'utf8mb4_unicode_520_ci', $wpdb->collate );
	}

	public function test_table_created_with_charset_collate_uses_determined_collation() {
		global $wpdb;

		$table = $wpdb->prefix . 'sqlite_charset_collate_test';
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) );

		try {
			$this->assertTrue(
				$wpdb->query(
					$wpdb->prepare( 'CREATE TABLE %i (id int, name varchar(20))', $table )
						. ' ' . $wpdb->get_charset_collate()
				)
			);
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
			$this->assertSame(
				'utf8mb4_unicode_520_ci',
				$wpdb->get_var(
					$wpdb->prepare(
						"SELECT collation_name FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = %s AND column_name = 'name'",
						$table
					)
				)
			);

			$create_table = $wpdb->get_row( $wpdb->prepare( 'SHOW CREATE TABLE %i', $table ), ARRAY_N )[1];
			$this->assertStringContainsString( 'DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci', $create_table );
			$this->assertStringNotContainsString( 'utf8mb4_0900_ai_ci', $create_table );
		} finally {
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) );
		}
	}

	public function test_explicit_collation_is_preserved() {
		global $wpdb;

		$this->assertSame(
			array(
				'charset' => 'utf8mb4',
				'collate' => 'utf8mb4_bin',
			),
			$wpdb->determine_charset( 'utf8mb4', 'utf8mb4_bin' )
		);
		$this->assertSame(
			array(
				'charset' => 'utf8mb4',
				'collate' => 'utf8mb4_swedish_ci',
			),
			$wpdb->determine_charset( 'utf8', 'utf8_swedish_ci' )
		);
	}
}
