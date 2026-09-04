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

	public function test_creates_randomized_database_storage() {
		$storage_root  = $this->create_temporary_directory_path();
		$database_root = $storage_root . '/nested/database';
		$umask         = umask( 0777 );

		try {
			$database_path = $this->initialize_managed_storage( $database_root );
		} finally {
			umask( $umask );
		}

		$database_path_file = $database_root . '/db-path.php';
		ob_start();
		$stored_database_path = require $database_path_file;
		$database_path_output = ob_get_clean();

		$this->assertSame( $database_path, $stored_database_path );
		$this->assertSame( '', $database_path_output );
		$this->assertSame( 1, preg_match( '/\A\.ht\.[0-9a-f]{32}\z/', basename( dirname( $stored_database_path ) ) ) );
		$this->assertFileExists( $database_path );
		$this->assertSame( 0, filesize( $database_path ) );
		$this->assertSame( 0600, fileperms( $database_path ) & 0777 );
		$this->assertSame( 0600, fileperms( $database_path_file ) & 0777 );
		$this->assertFileExists( $database_root . '/.ht.sqlite.lock' );
		$this->assertFileDoesNotExist( $database_root . '/.ht.sqlite.maintenance' );
		$this->assertStringContainsString(
			'IMPORTANT: Keep this path secret. When possible, point it outside the document root.',
			file_get_contents( $database_path_file )
		);
		$this->assertSame( 0700, fileperms( $storage_root ) & 0777 );
		$this->assertSame( 0700, fileperms( $storage_root . '/nested' ) & 0777 );
		$this->assert_protected_directory( $database_root );
		$this->assert_protected_directory( dirname( $database_path ) );
	}

	public function test_reuses_initialized_storage_without_repairing_protection_files() {
		$database_root = $this->create_temporary_directory_path();
		$first_path    = $this->initialize_managed_storage( $database_root );
		$this->assertTrue( unlink( $database_root . '/.htaccess' ) );
		$this->assertTrue( unlink( dirname( $first_path ) . '/index.php' ) );

		$second_path = $this->initialize_managed_storage( $database_root );

		$this->assertSame( $first_path, $second_path );
		$this->assertFileDoesNotExist( $database_root . '/.htaccess' );
		$this->assertFileDoesNotExist( dirname( $second_path ) . '/index.php' );
	}

	public function test_reuses_initialized_storage_with_read_only_database_root() {
		$database_root = $this->create_temporary_directory_path();
		$database_path = $this->initialize_managed_storage( $database_root );
		$this->assertTrue( chmod( $database_root, 0500 ) );

		try {
			$this->assertSame( $database_path, $this->initialize_managed_storage( $database_root ) );
		} finally {
			$this->assertTrue( chmod( $database_root, 0700 ) );
		}
	}

	public function test_skips_the_inactive_locking_database() {
		$database_root = $this->create_temporary_directory_path();
		$database_path = $this->initialize_managed_storage( $database_root );
		// Opening this invalid database would make initialization fail.
		file_put_contents( $database_root . '/.ht.sqlite.lock', str_repeat( 'x', 4096 ) );

		$this->assertSame( $database_path, $this->initialize_managed_storage( $database_root ) );
	}

	public function test_initializes_one_database_for_concurrent_requests() {
		$database_root = $this->create_temporary_directory_path();
		$processes     = array();
		for ( $i = 0; $i < 3; $i++ ) {
			$processes[] = $this->open_storage_process( $database_root, 'echo $storage->initialize();' );
		}

		$database_paths = array();
		foreach ( $processes as $process ) {
			$result = $this->close_process( ...$process );
			$this->assertSame( 0, $result['exit_code'] );
			$this->assertSame( '', $result['error'] );
			$database_paths[] = $result['output'];
		}

		$this->assertCount( 1, array_unique( $database_paths ) );
		$this->assertSame( $database_paths[0], require $database_root . '/db-path.php' );
		$this->assertFileExists( $database_paths[0] );
		$this->assertCount( 1, glob( $database_root . '/.ht.*', GLOB_ONLYDIR ) );
	}

	public function test_waits_for_storage_maintenance_before_initializing() {
		$database_root = $this->create_temporary_directory_path();
		$database_path = $this->initialize_managed_storage( $database_root );
		$storage       = new WP_SQLite_Storage( $database_root );
		$storage->lock();

		list( $process, $pipes ) = $this->open_storage_process( $database_root, 'echo $storage->initialize();' );
		$this->assert_process_is_running( $process );

		$storage->unlock();
		$result = $this->close_process( $process, $pipes );

		$this->assertSame( 0, $result['exit_code'] );
		$this->assertSame( '', $result['error'] );
		$this->assertSame( $database_path, $result['output'] );
	}

	public function test_initializes_the_storage_while_holding_the_lock() {
		$database_root = $this->create_temporary_directory_path();
		$storage       = new WP_SQLite_Storage( $database_root );
		$storage->lock();

		// The lock held by this instance must not block its own initialization.
		$database_path = $storage->initialize();

		$this->assertFileExists( $database_path );
		$this->assertFileExists( $database_root . '/.ht.sqlite.maintenance' );

		$storage->unlock();

		$this->assertSame( $database_path, $this->initialize_managed_storage( $database_root ) );
	}

	public function test_follows_a_moved_database_directory() {
		$original_root = $this->create_temporary_directory_path();
		$moved_root    = $this->create_temporary_directory_path();
		$original_path = $this->initialize_managed_storage( $original_root );
		$this->assertTrue( rename( $original_root, $moved_root ) );

		$moved_path = $this->initialize_managed_storage( $moved_root );

		$this->assertSame( $moved_root . substr( $original_path, strlen( $original_root ) ), $moved_path );
		$this->assertFileExists( $moved_path );
		$this->assertCount( 1, glob( $moved_root . '/.ht.*', GLOB_ONLYDIR ) );
	}

	public function test_recovers_an_interrupted_database_path_write() {
		$database_root = $this->create_temporary_directory();
		file_put_contents( $database_root . '/tmp.db-path.php', 'interrupted write' );

		$database_path = $this->initialize_managed_storage( $database_root );

		$this->assertFileExists( $database_path );
		$this->assertFileExists( $database_root . '/db-path.php' );
		$this->assertFileDoesNotExist( $database_root . '/tmp.db-path.php' );
	}

	public function test_rejects_a_database_path_file_that_does_not_return_a_path() {
		$database_root = $this->create_temporary_directory();
		file_put_contents( $database_root . '/db-path.php', "<?php\nreturn array();\n" );

		try {
			$this->initialize_managed_storage( $database_root );
			$this->fail( 'An invalid database path file was accepted.' );
		} catch ( RuntimeException $exception ) {
			$this->assertStringContainsString( 'database path file is invalid', $exception->getMessage() );
			$this->assertStringNotContainsString( $database_root, $exception->getMessage() );
		}
	}

	public function test_handles_a_database_path_file_that_cannot_be_loaded() {
		$database_root = $this->create_temporary_directory();
		file_put_contents( $database_root . '/db-path.php', "<?php\nreturn (;\n" );

		try {
			$this->initialize_managed_storage( $database_root );
			$this->fail( 'An unreadable database path file was loaded.' );
		} catch ( RuntimeException $exception ) {
			$this->assertSame( 'Failed to read the SQLite database path file.', $exception->getMessage() );
			$this->assertStringNotContainsString( $database_root, $exception->getMessage() );
		}
	}

	public function test_recovers_a_missing_database_referenced_by_the_path_file() {
		$database_root = $this->create_temporary_directory();
		$database_path = $database_root . '/.ht.0123456789abcdef0123456789abcdef/.ht.sqlite';
		file_put_contents(
			$database_root . '/db-path.php',
			"<?php\nreturn __DIR__ . '/.ht.0123456789abcdef0123456789abcdef/.ht.sqlite';\n"
		);

		$this->assertSame( $database_path, $this->initialize_managed_storage( $database_root ) );
		$this->assertFileExists( $database_path );
		$this->assertSame( 0600, fileperms( $database_path ) & 0777 );
		$this->assert_protected_directory( $database_root );
		$this->assert_protected_directory( dirname( $database_path ) );
	}

	public function test_initializes_an_explicit_database_file() {
		$database_root = $this->create_temporary_directory_path();
		$database_path = $database_root . '/custom/database.sqlite';
		$storage       = new WP_SQLite_Storage( $database_root, $database_path );

		$this->assertSame( $database_path, $storage->initialize() );
		$this->assertFileExists( $database_path );
		$this->assertSame( 0, filesize( $database_path ) );
		$this->assertSame( 0600, fileperms( $database_path ) & 0777 );
		$this->assertFileDoesNotExist( $database_root . '/db-path.php' );
		$this->assertFileDoesNotExist( $database_root . '/.ht.sqlite.lock' );
		$this->assert_protected_directory( dirname( $database_path ) );
	}

	public function test_initializes_an_in_memory_database_without_creating_files() {
		$this->assert_creates_no_files(
			function () {
				$storage = new WP_SQLite_Storage( $this->create_temporary_directory_path(), ':memory:' );
				$this->assertSame( ':memory:', $storage->initialize() );
			}
		);
	}

	public function test_rejects_an_empty_database_path_without_creating_files() {
		$this->assert_creates_no_files(
			function () {
				try {
					new WP_SQLite_Storage( $this->create_temporary_directory_path(), '' );
					$this->fail( 'An empty database path was accepted.' );
				} catch ( RuntimeException $exception ) {
					$this->assertSame( 'The SQLite database path is invalid.', $exception->getMessage() );
				}
			}
		);
	}

	public function test_preserves_an_existing_explicit_database_file() {
		$database_root = $this->create_temporary_directory();
		$database_path = $database_root . '/custom.sqlite';
		$this->create_sqlite_database( $database_path );
		chmod( $database_path, 0640 );
		$storage = new WP_SQLite_Storage( $database_root, $database_path );

		$this->assertSame( $database_path, $storage->initialize() );
		$this->assertSame( 'preserved', $this->read_sqlite_value( $database_path ) );
		$this->assertSame( 0640, fileperms( $database_path ) & 0777 );
		$this->assert_protected_directory( $database_root );
	}

	public function test_does_not_expose_the_database_path_when_initialization_fails() {
		$database_root = $this->create_temporary_directory();
		$blocking_path = $database_root . '/blocking-file';
		$database_path = $blocking_path . '/.ht.secret/.ht.sqlite';
		file_put_contents( $blocking_path, '' );

		try {
			$storage = new WP_SQLite_Storage( $database_root, $database_path );
			$storage->initialize();
			$this->fail( 'An inaccessible database path was initialized.' );
		} catch ( RuntimeException $exception ) {
			$this->assertSame( 'Failed to create the SQLite database directory.', $exception->getMessage() );
			$this->assertStringNotContainsString( $database_path, $exception->getMessage() );
		}
	}

	public function test_keeps_using_the_current_legacy_database() {
		$this->assert_legacy_database_is_kept( '.ht.sqlite' );
	}

	public function test_keeps_using_the_older_legacy_database() {
		$this->assert_legacy_database_is_kept( '.ht.sqlite.php' );
	}

	public function test_prefers_the_current_legacy_database() {
		$database_root = $this->create_temporary_directory();
		$current_path  = $database_root . '/.ht.sqlite';
		$older_path    = $database_root . '/.ht.sqlite.php';
		$this->create_sqlite_database( $current_path );
		$this->create_sqlite_database( $older_path );

		$this->assertSame( $current_path, $this->initialize_managed_storage( $database_root ) );
		$this->assertFileExists( $current_path );
		$this->assertFileExists( $older_path );
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

		// Requests stop waiting for maintenance after the same timeout.
		try {
			$storage->initialize();
			$this->fail( 'Storage was initialized during maintenance.' );
		} catch ( RuntimeException $exception ) {
			$this->assertSame( 'Failed to check the SQLite storage lock.', $exception->getMessage() );
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

		// The process released its SQLite transaction automatically. The next
		// request can remove its stale marker and initialize the storage.
		$storage = new WP_SQLite_Storage( $database_root );
		$this->assertFileExists( $storage->initialize() );
		$this->assertFileDoesNotExist( $database_root . '/.ht.sqlite.maintenance' );
	}

	public function test_clears_a_stale_marker_without_a_locking_database() {
		$database_root = $this->create_temporary_directory();
		$database_path = $this->initialize_managed_storage( $database_root );
		$this->assertTrue( unlink( $database_root . '/.ht.sqlite.lock' ) );
		file_put_contents( $database_root . '/.ht.sqlite.maintenance', '' );
		$umask = umask( 0000 );

		try {
			$this->assertSame( $database_path, $this->initialize_managed_storage( $database_root ) );
		} finally {
			umask( $umask );
		}

		$this->assertFileDoesNotExist( $database_root . '/.ht.sqlite.maintenance' );
		$this->assertFileExists( $database_root . '/.ht.sqlite.lock' );
		$this->assertSame( 0600, fileperms( $database_root . '/.ht.sqlite.lock' ) & 0777 );
	}

	public function test_fails_when_the_lock_cannot_be_created() {
		$database_root = $this->create_temporary_directory();
		$storage       = new WP_SQLite_Storage( $database_root );
		$storage->initialize();
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

	public function test_ignores_a_legacy_database_for_an_explicit_path() {
		$database_root = $this->create_temporary_directory();
		$database_path = $database_root . '/database.sqlite';
		$legacy_path   = $database_root . '/.ht.sqlite';
		file_put_contents( $legacy_path, str_repeat( 'x', 4096 ) );
		$storage = new WP_SQLite_Storage( $database_root, $database_path );

		$storage->lock();
		$storage->unlock();
		$this->assertFileDoesNotExist( $database_path );

		$this->assertSame( $database_path, $storage->initialize() );
		$this->assertSame( 0, filesize( $database_path ) );
		$this->assertSame( str_repeat( 'x', 4096 ), file_get_contents( $legacy_path ) );
	}

	private function assert_legacy_database_is_kept( $filename ) {
		$database_root = $this->create_temporary_directory();
		$legacy_path   = $database_root . '/' . $filename;
		$this->create_sqlite_database( $legacy_path );

		$this->assertSame( $legacy_path, $this->initialize_managed_storage( $database_root ) );
		$this->assertSame( 'preserved', $this->read_sqlite_value( $legacy_path ) );
		$this->assertFileDoesNotExist( $database_root . '/db-path.php' );
	}

	private function assert_creates_no_files( $callback ) {
		$working_directory          = $this->create_temporary_directory();
		$previous_working_directory = getcwd();
		$this->assertNotFalse( $previous_working_directory );
		$this->assertTrue( chdir( $working_directory ) );

		try {
			$callback();
		} finally {
			$this->assertTrue( chdir( $previous_working_directory ) );
		}

		$this->assertSame( array( '.', '..' ), scandir( $working_directory ) );
	}

	private function initialize_managed_storage( $database_root ) {
		$storage = new WP_SQLite_Storage( $database_root );

		return $storage->initialize();
	}

	private function assert_protected_directory( $directory ) {
		clearstatcache( true, $directory );
		$this->assertSame( 0700, fileperms( $directory ) & 0777 );
		$this->assertSame( 'DENY FROM ALL', file_get_contents( $directory . '/.htaccess' ) );
		$this->assertSame( 0600, fileperms( $directory . '/.htaccess' ) & 0777 );
		$this->assertSame( '<?php // Silence is golden.', file_get_contents( $directory . '/index.php' ) );
		$this->assertSame( 0600, fileperms( $directory . '/index.php' ) & 0777 );
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

	private function create_sqlite_database( $database_path ) {
		$connection = new PDO( 'sqlite:' . $database_path );
		$connection->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
		$connection->exec( 'CREATE TABLE storage_test (value TEXT NOT NULL)' );
		$connection->exec( "INSERT INTO storage_test VALUES ('preserved')" );

		return $connection;
	}

	private function read_sqlite_value( $database_path ) {
		$connection = new PDO( 'sqlite:' . $database_path );
		$connection->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
		$connection->exec( 'PRAGMA busy_timeout = 100' );

		return $connection->query( 'SELECT value FROM storage_test' )->fetchColumn();
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
