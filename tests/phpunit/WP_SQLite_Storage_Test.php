<?php

require_once WP_CONTENT_DIR . '/plugins/sqlite-database-integration/wp-includes/sqlite/class-wp-sqlite-storage.php';

class WP_SQLite_Storage_Test extends WP_UnitTestCase {

	/**
	 * @var string[]
	 */
	private $temporary_directories = array();

	public function tear_down() {
		foreach ( $this->temporary_directories as $directory ) {
			$this->remove_directory( $directory );
		}

		parent::tear_down();
	}

	public function test_locks_and_unlocks_the_storage() {
		$database_root    = $this->create_temporary_directory();
		$lock_path        = $database_root . '/.ht.sqlite.lock';
		$maintenance_path = $database_root . '/.ht.sqlite.maintenance';
		$storage          = new WP_SQLite_Storage( $database_root );

		$storage->lock();

		$this->assertFileExists( $lock_path );
		$this->assertSame( 0600, fileperms( $lock_path ) & 0777 );
		$this->assertFileExists( $maintenance_path );
		$this->assertSame( 0600, fileperms( $maintenance_path ) & 0777 );

		$storage->unlock();

		// Keep the database as a stable target for future lock transactions.
		$this->assertFileExists( $lock_path );
		$this->assertFileDoesNotExist( $maintenance_path );
	}

	public function test_waits_for_another_process_to_unlock() {
		$database_root = $this->create_temporary_directory();
		$storage       = new WP_SQLite_Storage( $database_root );
		$storage->lock();

		list( $process, $pipes ) = $this->open_storage_process( $database_root, '$storage->lock(); echo "locked";' );
		$this->assert_process_is_running( $process );

		$storage->unlock();
		$result = $this->close_process( $process, $pipes );

		$this->assertSame( 0, $result['exit_code'] );
		$this->assertSame( '', $result['error'] );
		$this->assertSame( 'locked', $result['output'] );
	}

	public function test_does_not_acquire_a_busy_storage_lock() {
		$database_root = $this->create_temporary_directory();
		$owner         = new WP_SQLite_Storage( $database_root );
		$storage       = new WP_SQLite_Storage( $database_root );
		$owner->lock();
		$this->set_storage_property( $storage, 'database_lock_timeout', 10 );

		try {
			$storage->lock();
			$this->fail( 'A busy storage lock was acquired.' );
		} catch ( RuntimeException $exception ) {
			$this->assertSame( 'Failed to acquire the SQLite storage lock.', $exception->getMessage() );
		}

		$this->assertFileExists( $database_root . '/.ht.sqlite.maintenance' );
		$owner->unlock();
	}

	public function test_unlocks_the_storage_when_the_owner_dies() {
		$database_root = $this->create_temporary_directory();

		$result = $this->close_process(
			...$this->open_storage_process(
				$database_root,
				'$storage->lock(); echo "locked"; fflush(STDOUT); set_time_limit(1); while (true) {}'
			)
		);

		$this->assertSame( 'locked', $result['output'] );
		$this->assertNotSame( 0, $result['exit_code'] );
		$this->assertFileExists( $database_root . '/.ht.sqlite.maintenance' );

		// The process releases its SQLite transaction automatically.
		$storage = new WP_SQLite_Storage( $database_root );
		$storage->lock();
		$storage->unlock();
		$this->assertFileDoesNotExist( $database_root . '/.ht.sqlite.maintenance' );
	}

	public function test_fails_when_the_lock_cannot_be_created() {
		$database_root = $this->create_temporary_directory();
		$storage       = new WP_SQLite_Storage( $database_root );
		$storage->lock();
		$storage->unlock();
		$this->assertTrue( unlink( $database_root . '/.ht.sqlite.lock' ) );
		$this->make_read_only( $database_root );

		try {
			$storage->lock();
			$this->fail( 'A lock was created in a read-only directory.' );
		} catch ( RuntimeException $exception ) {
			$this->assertSame( 'Failed to acquire the SQLite storage lock.', $exception->getMessage() );
			$this->assertStringNotContainsString( $database_root, $exception->getMessage() );
		} finally {
			$this->assertTrue( chmod( $database_root, 0700 ) );
		}
	}

	public function test_releases_the_lock_when_the_maintenance_marker_cannot_be_created() {
		$database_root    = $this->create_temporary_directory();
		$maintenance_path = $database_root . '/.ht.sqlite.maintenance';
		$this->assertTrue( mkdir( $maintenance_path ) );
		$storage = new WP_SQLite_Storage( $database_root );

		try {
			$storage->lock();
			$this->fail( 'Storage was locked without a maintenance marker.' );
		} catch ( RuntimeException $exception ) {
			$this->assertSame( 'Failed to create the SQLite storage maintenance marker.', $exception->getMessage() );
		}

		$this->assertTrue( rmdir( $maintenance_path ) );
		$storage->lock();
		$storage->unlock();
	}

	private function set_storage_property( $storage, $name, $value ) {
		$set = Closure::bind(
			function () use ( $name, $value ) {
				$this->$name = $value;
			},
			$storage,
			WP_SQLite_Storage::class
		);

		$set();
	}

	private function make_read_only( $directory ) {
		$this->assertTrue( chmod( $directory, 0500 ) );
		clearstatcache( true, $directory );
		if ( is_writable( $directory ) ) {
			$this->assertTrue( chmod( $directory, 0700 ) );
			$this->markTestSkipped( 'Directory permissions are not enforced for the current user.' );
		}
	}

	private function open_storage_process( $database_root, $script ) {
		$prelude = sprintf(
			'function trailingslashit($value) { return untrailingslashit($value) . "/"; } function untrailingslashit($value) { return rtrim($value, "/\\\\"); } require %s; $storage = new WP_SQLite_Storage(%s); ',
			var_export( WP_CONTENT_DIR . '/plugins/sqlite-database-integration/wp-includes/sqlite/class-wp-sqlite-storage.php', true ),
			var_export( $database_root, true )
		);

		return $this->open_process( $prelude . $script );
	}

	private function open_process( $script ) {
		$command = escapeshellarg( PHP_BINARY ) . ' -d display_errors=stderr -r ' . escapeshellarg( $script );
		$process = proc_open(
			$command,
			array(
				array( 'pipe', 'r' ),
				array( 'pipe', 'w' ),
				array( 'pipe', 'w' ),
			),
			$pipes
		);

		$this->assertIsResource( $process );
		fclose( $pipes[0] );

		return array( $process, $pipes );
	}

	private function assert_process_is_running( $process ) {
		usleep( 500000 );
		$this->assertTrue( proc_get_status( $process )['running'] );
	}

	private function close_process( $process, $pipes ) {
		$output = stream_get_contents( $pipes[1] );
		fclose( $pipes[1] );
		$error = stream_get_contents( $pipes[2] );
		fclose( $pipes[2] );

		return array(
			'exit_code' => proc_close( $process ),
			'output'    => $output,
			'error'     => $error,
		);
	}

	private function create_temporary_directory() {
		$path = $this->create_temporary_directory_path();
		$this->assertTrue( mkdir( $path, 0700 ) );

		return $path;
	}

	private function create_temporary_directory_path() {
		$path = tempnam( sys_get_temp_dir(), 'wp-sqlite-storage-' );
		$this->assertNotFalse( $path );
		$this->assertTrue( unlink( $path ) );
		$this->temporary_directories[] = $path;

		return $path;
	}

	private function remove_directory( $directory ) {
		if ( ! is_dir( $directory ) ) {
			return;
		}

		chmod( $directory, 0700 );
		foreach ( scandir( $directory ) as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}

			$path = $directory . DIRECTORY_SEPARATOR . $entry;
			if ( is_dir( $path ) && ! is_link( $path ) ) {
				$this->remove_directory( $path );
			} else {
				unlink( $path );
			}
		}

		rmdir( $directory );
	}
}
