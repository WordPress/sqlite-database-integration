<?php

class WP_SQLite_Database_Integration_Savepoint_Test extends PHPUnit_Adapter_TestCase {

	public function test_wpdb_update_after_savepoint_commits_immediately() {
		global $wpdb;

		$option_name = 'sqlite_savepoint_write_test';
		$wpdb->insert(
			$wpdb->options,
			array(
				'option_name'  => $option_name,
				'option_value' => '1',
				'autoload'     => 'no',
			)
		);

		try {
			$this->assertFalse( $wpdb->get_driver()->inTransaction() );
			$this->assertNotFalse( $wpdb->query( 'SAVEPOINT wpdb_update' ) );

			$result  = $wpdb->update( $wpdb->options, array( 'option_value' => '2' ), array( 'option_name' => $option_name ) );
			$queries = array_column( $wpdb->get_driver()->get_last_sqlite_queries(), 'sql' );

			// The savepoint does not open a transaction, so the update takes the
			// write lock and is committed on its own, as it would be on MySQL.
			$this->assertSame( 1, $result );
			$this->assertSame( 1, $wpdb->rows_affected );
			$this->assertSame( '', $wpdb->last_error );
			$this->assertContains( 'BEGIN IMMEDIATE', $queries );
			$this->assertFalse( $wpdb->get_driver()->inTransaction() );

			// The savepoint was discarded, so releasing it reports MySQL error 1305.
			$suppress       = $wpdb->suppress_errors( true );
			$release_result = $wpdb->query( 'RELEASE SAVEPOINT wpdb_update' );
			$wpdb->suppress_errors( $suppress );
			$this->assertFalse( $release_result );
			$this->assertStringContainsString( 'SAVEPOINT wpdb_update does not exist', $wpdb->last_error );
			$this->assertSame(
				'2',
				$wpdb->get_var(
					$wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, $option_name )
				)
			);
		} finally {
			if ( $wpdb->get_driver()->inTransaction() ) {
				$wpdb->query( 'ROLLBACK' );
			}
			$wpdb->delete( $wpdb->options, array( 'option_name' => $option_name ) );
		}
	}
}
