<?php

use PHPUnit\Framework\TestCase;
use WP_MySQL_Proxy\Adapter\SQLite_Adapter;

class WP_MySQL_Proxy_SQLite_Adapter_Test extends TestCase {
	public function test_show_variables_fallback_supplies_mysql_server_metadata(): void {
		$adapter = new SQLite_Adapter( ':memory:', 'test', array( 'mysql' ) );

		$result = $adapter->handle_query( 'SHOW GLOBAL VARIABLES' );

		$this->assertNull( $result->error_info );
		$this->assertCount( 2, $result->columns );

		$variables = $this->index_variables( $result->rows );
		$this->assertSame( '8.0.46', $variables['version'] );
		$this->assertSame( 'InnoDB', $variables['default_storage_engine'] );

		$like_result = $adapter->handle_query( "SHOW GLOBAL VARIABLES LIKE 'version%'" );
		$like_names  = array_keys( $this->index_variables( $like_result->rows ) );

		$this->assertContains( 'version', $like_names );
		$this->assertContains( 'version_comment', $like_names );
		$this->assertNotContains( 'default_storage_engine', $like_names );
	}

	/**
	 * Index SHOW VARIABLES rows by variable name.
	 *
	 * @param  array<int, object> $rows Result rows.
	 * @return array<string, string>
	 */
	private function index_variables( array $rows ): array {
		$variables = array();

		foreach ( $rows as $row ) {
			$variables[ $row->Variable_name ] = $row->Value;
		}

		return $variables;
	}
}
