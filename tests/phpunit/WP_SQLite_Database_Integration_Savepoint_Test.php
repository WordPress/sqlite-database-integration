<?php

class WP_SQLite_Database_Integration_Savepoint_Test extends WP_UnitTestCase {

	public function test_wpdb_update_inside_savepoint_does_not_open_nested_transaction() {
		global $wpdb;

		$table_name = $wpdb->prefix . 'sqlite_savepoint_write_test';
		$wpdb->query( $wpdb->prepare( 'CREATE TABLE %i (id INT PRIMARY KEY, value INT)', $table_name ) );
		$wpdb->insert(
			$table_name,
			array(
				'id'    => 1,
				'value' => 1,
			)
		);

		try {
			$this->assertNotFalse( $wpdb->query( 'SAVEPOINT wpdb_update' ) );

			$result  = $wpdb->update( $table_name, array( 'value' => 2 ), array( 'id' => 1 ) );
			$queries = array_column( $wpdb->get_driver()->get_last_sqlite_queries(), 'sql' );

			$this->assertSame( 1, $result );
			$this->assertSame( 1, $wpdb->rows_affected );
			$this->assertSame( '', $wpdb->last_error );
			$this->assertNotContains( 'BEGIN IMMEDIATE', $queries );
			$this->assertNotFalse( $wpdb->query( 'RELEASE SAVEPOINT wpdb_update' ) );
			$this->assertSame( '2', $wpdb->get_var( $wpdb->prepare( 'SELECT value FROM %i WHERE id = 1', $table_name ) ) );
		} finally {
			if ( $wpdb->get_driver()->inTransaction() ) {
				$wpdb->query( 'ROLLBACK' );
			}
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table_name ) );
		}
	}
}
