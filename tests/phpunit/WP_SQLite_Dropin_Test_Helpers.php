<?php

trait WP_SQLite_Dropin_Test_Helpers {

	private function run_without_dropin( $callback ) {
		$dropin_path        = WP_CONTENT_DIR . '/db.php';
		$dropin_backup_path = tempnam( WP_CONTENT_DIR, 'db.php.' );

		$this->assertNotFalse( $dropin_backup_path );
		$this->assertTrue( unlink( $dropin_backup_path ) );
		$this->assertTrue( rename( $dropin_path, $dropin_backup_path ) );

		$restore_dropin      = function () use ( $dropin_path, $dropin_backup_path ) {
			if ( ! file_exists( $dropin_backup_path ) ) {
				return file_exists( $dropin_path );
			}

			return rename( $dropin_backup_path, $dropin_path );
		};
		$restore_on_shutdown = true;
		register_shutdown_function(
			function () use ( &$restore_on_shutdown, $restore_dropin ) {
				if ( $restore_on_shutdown ) {
					call_user_func( $restore_dropin );
				}
			}
		);

		try {
			$result = call_user_func( $callback );
		} finally {
			$dropin_restored     = call_user_func( $restore_dropin );
			$restore_on_shutdown = false;
		}

		$this->assertTrue( $dropin_restored );
		$this->assertFileExists( $dropin_path );

		return $result;
	}
}
