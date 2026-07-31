<?php

require_once WP_CONTENT_DIR . '/plugins/sqlite-database-integration/load.php';

class WP_SQLite_Database_Integration_Authorization_Test extends WP_UnitTestCase {

	public function test_plugin_is_loaded() {
		$this->assertTrue( defined( 'SQLITE_MAIN_FILE' ) );
		$this->assertFileExists( SQLITE_MAIN_FILE );
	}
}
