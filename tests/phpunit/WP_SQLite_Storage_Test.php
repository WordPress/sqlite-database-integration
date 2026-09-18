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

	/**
	 * @dataProvider database_directory_suffixes
	 */
	public function test_initializes_custom_database_directory_without_wordpress_helpers( $suffix ) {
		$database_root = $this->create_temporary_directory_path() . '/nested/database';
		$plugin_path   = WP_CONTENT_DIR . '/plugins/sqlite-database-integration';
		$script        = sprintf(
			'define("DB_DIR", %s); require %s; require %s; $storage = new WP_SQLite_Storage(); echo $storage->initialize();',
			var_export( $database_root . $suffix, true ),
			var_export( $plugin_path . '/constants.php', true ),
			var_export( $plugin_path . '/wp-includes/sqlite/class-wp-sqlite-storage.php', true )
		);

		$result = $this->close_process( ...$this->open_process( $script ) );

		$this->assertSame( 0, $result['exit_code'], $result['error'] );
		$this->assertSame( '', $result['error'] );
		$this->assertSame( $result['output'], require $database_root . '/db-path.php' );
		$this->assertFileExists( $result['output'] );
		$this->assert_protected_directory( $database_root );
		$this->assert_protected_directory( dirname( $result['output'] ) );
	}

	public function database_directory_suffixes() {
		return array(
			'no separator'  => array( '' ),
			'forward slash' => array( '/' ),
			'backslash'     => array( '\\' ),
			'mixed slashes' => array( '/\\/' ),
		);
	}

	public function test_reports_storage_errors_before_wordpress_loads_in_wp_cli() {
		$database_root = $this->create_temporary_directory();
		$blocked_path  = $database_root . '/blocked';
		file_put_contents( $blocked_path, 'This file prevents creating the database directory.' );
		$script = sprintf(
			'define("WP_CLI", true); define("DB_ENGINE", "sqlite"); define("DB_DIR", %s);
			class WP_CLI { public static function error($message) { fwrite(STDERR, "Error: " . $message); exit(1); } }
			ini_set("error_log", %s); require %s;',
			var_export( $blocked_path . '/database', true ),
			var_export( $database_root . '/error.log', true ),
			var_export( WP_CONTENT_DIR . '/plugins/sqlite-database-integration/wp-includes/sqlite/db.php', true )
		);

		$result = $this->close_process( ...$this->open_process( $script ) );

		$this->assertSame( 1, $result['exit_code'] );
		$this->assertSame( '', $result['output'] );
		$this->assertSame( 'Error: Failed to acquire the SQLite storage lock.', $result['error'] );
		$this->assertStringContainsString( 'SQLite database error: RuntimeException:', file_get_contents( $database_root . '/error.log' ) );
	}

	/**
	 * @dataProvider database_file_settings
	 */
	public function test_dropin_resolves_database_file_settings( $settings, $expected_path ) {
		$directory = $this->create_temporary_directory();
		foreach ( $settings as $name => $value ) {
			if ( 'DB_FILE' !== $name ) {
				$settings[ $name ] = $directory . '/' . $value;
			}
		}
		$settings['WP_CONTENT_DIR'] = $directory . '/content';
		$script                     = '
			$path = $wpdb->get_driver()->get_sqlite_pdo()->query("PRAGMA database_list")->fetch(PDO::FETCH_ASSOC)["file"];
			$wpdb->set_prefix("wp_");
			sqlite_make_db_sqlite();
			echo json_encode(array(
				"path" => DB_PATH,
				"connected_path" => $path,
				"legacy_path" => FQDB,
				"legacy_directory" => FQDBDIR,
				"installed" => "wp_options" === $wpdb->get_var("SHOW TABLES LIKE \'wp_options\'")
			));
			$wpdb->close();
			gc_collect_cycles();
			$database_storage->lock();
			$database_storage->unlock();';

		$result = $this->close_process( ...$this->open_dropin_process( $settings, $script ) );

		$this->assertSame( 0, $result['exit_code'], $result['error'] );
		$this->assertSame( '', $result['error'] );
		$data = json_decode( $result['output'], true );
		$this->assertIsArray( $data );
		$this->assertTrue( $data['installed'] );
		$this->assertSame( $data['path'], $data['legacy_path'] );

		$this->assertSame( realpath( $data['path'] ), $data['connected_path'] );
		if ( null === $expected_path ) {
			$database_root = $settings['FQDBDIR'] ?? $settings['DB_DIR'] ?? $settings['WP_CONTENT_DIR'] . '/database';
			$this->assertSame( $data['path'], require $database_root . '/db-path.php' );
		} else {
			$this->assertSame( $directory . '/' . $expected_path, $data['path'] );
			$this->assertFileDoesNotExist( dirname( $data['path'] ) . '/db-path.php' );
		}

		if ( isset( $settings['DB_PATH'] ) ) {
			$this->assertFileExists( dirname( $data['path'] ) . '/.ht.sqlite.lock' );
			$this->assertFileDoesNotExist( $settings['WP_CONTENT_DIR'] );
			$this->assertSame( dirname( $data['path'] ) . '/', $data['legacy_directory'] );
		}
	}

	public function database_file_settings() {
		return array(
			'managed default'       => array( array(), null ),
			'legacy directory'      => array( array( 'DB_DIR' => 'legacy-directory' ), null ),
			'legacy storage root'   => array( array( 'FQDBDIR' => 'legacy-root' ), null ),
			'legacy filename'       => array( array( 'DB_FILE' => 'legacy.sqlite' ), 'content/database/legacy.sqlite' ),
			'legacy explicit path'  => array( array( 'FQDB' => 'legacy.sqlite' ), 'legacy.sqlite' ),
			'legacy directory/file' => array(
				array(
					'DB_DIR'  => 'legacy-directory',
					'DB_FILE' => 'legacy.sqlite',
				),
				'legacy-directory/legacy.sqlite',
			),
			'all legacy constants'  => array(
				array(
					'DB_DIR'  => 'ignored-directory',
					'DB_FILE' => 'ignored.sqlite',
					'FQDBDIR' => 'legacy-root',
					'FQDB'    => 'legacy.sqlite',
				),
				'legacy.sqlite',
			),
			'explicit path'         => array( array( 'DB_PATH' => 'private/database;name.sqlite' ), 'private/database;name.sqlite' ),
		);
	}

	public function test_dropin_uses_in_memory_db_path() {
		$directory = $this->create_temporary_directory();
		$settings  = array(
			'DB_PATH'        => ':memory:',
			'WP_CONTENT_DIR' => $directory . '/content',
		);
		$script    = '
			echo json_encode(array(
				"path" => DB_PATH,
				"connected_path" => $wpdb->get_driver()->get_sqlite_pdo()->query("PRAGMA database_list")->fetch(PDO::FETCH_ASSOC)["file"],
				"legacy_path" => FQDB
			));';

		$result = $this->close_process( ...$this->open_dropin_process( $settings, $script ) );

		$this->assertSame( 0, $result['exit_code'], $result['error'] );
		$this->assertSame( '', $result['error'] );
		$data = json_decode( $result['output'], true );
		$this->assertIsArray( $data );
		$this->assertSame( ':memory:', $data['path'] );
		$this->assertSame( '', $data['connected_path'] );
		$this->assertSame( ':memory:', $data['legacy_path'] );
		$this->assertSame( array( '.', '..' ), scandir( $directory ) );
	}

	/**
	 * @dataProvider mixed_database_file_settings
	 */
	public function test_rejects_db_path_with_predefined_legacy_constants( $settings ) {
		$settings += array( 'DB_PATH' => 'database.sqlite' );
		foreach ( array( 'constants.php', 'wp-includes/sqlite/db.php' ) as $entrypoint ) {
			$directory = $this->create_temporary_directory();
			$this->create_sqlite_database( $directory . '/database.sqlite' );
			$this->create_sqlite_database( $directory . '/legacy.sqlite' );
			$script = 'define("WP_CLI", true); define("DB_ENGINE", "sqlite");';
			foreach ( $settings as $name => $value ) {
				$path    = 'DB_FILE' === $name || ':memory:' === $value ? $value : $directory . '/' . $value;
				$script .= 'define(' . var_export( $name, true ) . ', ' . var_export( $path, true ) . ');';
			}
			$script .= sprintf(
				'class WP_CLI { public static function error($message) { fwrite(STDERR, "Error: " . $message); exit(1); } }
				chdir(%s); ini_set("error_log", "error.log");
				try { require %s; } catch (RuntimeException $exception) { fwrite(STDERR, "Exception: " . $exception->getMessage()); exit(1); }',
				var_export( $directory, true ),
				var_export( WP_CONTENT_DIR . '/plugins/sqlite-database-integration/' . $entrypoint, true )
			);

			$result = $this->close_process( ...$this->open_process( $script ) );
			$prefix = 'constants.php' === $entrypoint ? 'Exception: ' : 'Error: ';
			$this->assertSame( 1, $result['exit_code'] );
			$this->assertSame( '', $result['output'] );
			$this->assertSame( $prefix . 'DB_PATH cannot be combined with DB_DIR, DB_FILE, FQDBDIR, or FQDB. Remove the legacy definitions.', $result['error'] );
			$this->assertSame( 'preserved', $this->read_sqlite_value( $directory . '/database.sqlite' ) );
			$this->assertSame( 'preserved', $this->read_sqlite_value( $directory . '/legacy.sqlite' ) );
			$this->assertSame(
				'constants.php' === $entrypoint
					? array( '.', '..', 'database.sqlite', 'legacy.sqlite' )
					: array( '.', '..', 'database.sqlite', 'error.log', 'legacy.sqlite' ),
				scandir( $directory )
			);
		}
	}

	public function mixed_database_file_settings() {
		return array(
			'different DB_DIR'   => array( array( 'DB_DIR' => 'legacy/' ) ),
			'matching DB_DIR'    => array( array( 'DB_DIR' => '' ) ),
			'different DB_FILE'  => array( array( 'DB_FILE' => 'legacy.sqlite' ) ),
			'matching DB_FILE'   => array( array( 'DB_FILE' => 'database.sqlite' ) ),
			'different FQDBDIR'  => array( array( 'FQDBDIR' => 'legacy/' ) ),
			'matching FQDBDIR'   => array( array( 'FQDBDIR' => '' ) ),
			'different FQDB'     => array( array( 'FQDB' => 'legacy.sqlite' ) ),
			'matching FQDB'      => array( array( 'FQDB' => 'database.sqlite' ) ),
			'all different'      => array(
				array(
					'DB_DIR'  => 'legacy/',
					'DB_FILE' => 'legacy.sqlite',
					'FQDB'    => 'legacy.sqlite',
					'FQDBDIR' => 'legacy/',
				),
			),
			'all matching'       => array(
				array(
					'DB_DIR'  => '',
					'DB_FILE' => 'database.sqlite',
					'FQDB'    => 'database.sqlite',
					'FQDBDIR' => '',
				),
			),
			'in-memory database' => array(
				array(
					'DB_PATH' => ':memory:',
					'FQDB'    => ':memory:',
				),
			),
		);
	}

	/**
	 * @dataProvider absolute_database_paths
	 */
	public function test_validates_absolute_database_paths_without_wordpress_helpers( $database_path ) {
		$script = sprintf(
			'define("DB_PATH", %s); require %s; require %s; new WP_SQLite_Storage(FQDBDIR, DB_PATH); echo FQDB;',
			var_export( $database_path, true ),
			var_export( WP_CONTENT_DIR . '/plugins/sqlite-database-integration/constants.php', true ),
			var_export( WP_CONTENT_DIR . '/plugins/sqlite-database-integration/wp-includes/sqlite/class-wp-sqlite-storage.php', true )
		);

		$this->assert_creates_no_files(
			function () use ( $script, $database_path ) {
				$result = $this->close_process( ...$this->open_process( $script ) );
				$this->assertSame( 0, $result['exit_code'], $result['error'] );
				$this->assertSame( '', $result['error'] );
				$this->assertSame( $database_path, $result['output'] );
			}
		);
	}

	public function absolute_database_paths() {
		$root  = '/' === DIRECTORY_SEPARATOR ? '/missing-directory' : 'C:/missing-directory';
		$paths = array(
			'in-memory database' => array( ':memory:' ),
			'missing file'       => array( $root . '/database.sqlite' ),
			'path bytes'         => array( $root . "/data;quo'te-ž.sqlite " ),
			'forward slash UNC'  => array( '//server/share/database.sqlite' ),
		);
		if ( '\\' === DIRECTORY_SEPARATOR ) {
			$paths['Windows drive'] = array( 'C:\\missing-directory\\database.sqlite' );
			$paths['Windows UNC']   = array( '\\\\server\\share\\database.sqlite' );
		}
		return $paths;
	}

	/**
	 * @dataProvider invalid_database_paths
	 */
	public function test_invalid_db_path_does_not_fall_back_to_legacy_database( $database_path ) {
		$directory   = $this->create_temporary_directory();
		$legacy_path = $directory . '/content/database/.ht.sqlite';
		$this->assertTrue( mkdir( $directory . '/content/database', 0777, true ) );
		$this->assertTrue( mkdir( $directory . '/working' ) );
		$this->create_sqlite_database( $legacy_path );
		$script = sprintf(
			'define("WP_CLI", true); define("DB_ENGINE", "sqlite"); define("DB_PATH", %s); define("WP_CONTENT_DIR", %s);
			class WP_CLI { public static function error($message) { fwrite(STDERR, "Error: " . $message); exit(1); } }
			chdir(%s); ini_set("error_log", %s); require %s;',
			var_export( $database_path, true ),
			var_export( $directory . '/content', true ),
			var_export( $directory . '/working', true ),
			var_export( $directory . '/error.log', true ),
			var_export( WP_CONTENT_DIR . '/plugins/sqlite-database-integration/wp-includes/sqlite/db.php', true )
		);

		$result  = $this->close_process( ...$this->open_process( $script ) );
		$message = ! is_string( $database_path )
			? 'DB_PATH must be a string.'
			: 'The SQLite database path must be an absolute filesystem path or ":memory:".';
		$this->assertSame( 1, $result['exit_code'] );
		$this->assertSame( '', $result['output'] );
		$this->assertSame( 'Error: ' . $message, $result['error'] );
		$this->assertSame( 'preserved', $this->read_sqlite_value( $legacy_path ) );
		$this->assertSame( array( '.', '..', 'content', 'error.log', 'working' ), scandir( $directory ) );
		$this->assertSame( array( '.', '..' ), scandir( $directory . '/working' ) );
		$this->assertSame( array( '.', '..', 'database' ), scandir( $directory . '/content' ) );
		$this->assertSame( array( '.', '..', '.ht.sqlite' ), scandir( $directory . '/content/database' ) );
	}

	public function invalid_database_paths() {
		return $this->invalid_database_path_types() + $this->invalid_explicit_database_paths();
	}

	public function invalid_explicit_database_paths() {
		$paths = array(
			'empty string'           => array( '' ),
			'relative filename'      => array( 'database.sqlite' ),
			'current directory'      => array( './database.sqlite' ),
			'parent directory'       => array( '../database.sqlite' ),
			'relative subdirectory'  => array( 'data/database.sqlite' ),
			'Windows relative path'  => array( 'data\\database.sqlite' ),
			'Windows drive-relative' => array( 'C:database.sqlite' ),
			'Windows current drive'  => array( '\\database.sqlite' ),
			'file URI'               => array( 'file:///database.sqlite' ),
			'stream wrapper'         => array( 'php://memory' ),
			'invalid in-memory path' => array( ':memory:extra' ),
			'null byte'              => array( "/database\0.sqlite" ),
		);
		if ( '/' === DIRECTORY_SEPARATOR ) {
			$paths['Windows drive with forward slashes'] = array( 'C:/data/database.sqlite' );
			$paths['Windows drive with backslashes']     = array( 'C:\\data\\database.sqlite' );
			$paths['Windows UNC']                        = array( '\\\\server\\share\\database.sqlite' );
		} else {
			$paths['Windows current drive with forward slash'] = array( '/database.sqlite' );
			$paths['incomplete UNC path']                      = array( '//server/database.sqlite' );
		}
		return $paths;
	}

	/**
	 * @dataProvider invalid_database_path_types
	 */
	public function test_constants_reject_invalid_db_path_types_before_deriving_legacy_constants( $database_path ) {
		$script = sprintf(
			'define("DB_ENGINE", "sqlite"); define("DB_PATH", %s);
			try { require %s; } catch (RuntimeException $exception) { echo $exception->getMessage(); }
			if (defined("FQDB") || defined("FQDBDIR")) { exit(1); }',
			var_export( $database_path, true ),
			var_export( WP_CONTENT_DIR . '/plugins/sqlite-database-integration/constants.php', true )
		);
		$result = $this->close_process( ...$this->open_process( $script ) );

		$this->assertSame( 0, $result['exit_code'], $result['error'] );
		$this->assertSame( '', $result['error'] );
		$this->assertSame( 'DB_PATH must be a string.', $result['output'] );
	}

	public function invalid_database_path_types() {
		return array(
			'null'    => array( null ),
			'false'   => array( false ),
			'true'    => array( true ),
			'integer' => array( 123 ),
			'float'   => array( 1.5 ),
			'array'   => array( array() ),
		);
	}

	/**
	 * @dataProvider legacy_relative_database_paths
	 */
	public function test_dropin_rejects_legacy_relative_database_paths( $settings ) {
		$directory = $this->create_temporary_directory();
		$script    = sprintf(
			'define("WP_CLI", true); define("DB_ENGINE", "sqlite"); define("WP_CONTENT_DIR", %s);
			foreach (%s as $name => $value) { define($name, $value); }
			class WP_CLI { public static function error($message) { fwrite(STDERR, "Error: " . $message); exit(1); } }
			chdir(%s); ini_set("error_log", %s); require %s;',
			var_export( $directory . '/content', true ),
			var_export( $settings, true ),
			var_export( $directory, true ),
			var_export( $directory . '/error.log', true ),
			var_export( WP_CONTENT_DIR . '/plugins/sqlite-database-integration/wp-includes/sqlite/db.php', true )
		);
		$result    = $this->close_process( ...$this->open_process( $script ) );

		$this->assertSame( 1, $result['exit_code'] );
		$this->assertSame( '', $result['output'] );
		$this->assertSame( 'Error: The SQLite database path must be an absolute filesystem path or ":memory:".', $result['error'] );
		$this->assertSame( array( '.', '..', 'error.log' ), scandir( $directory ) );
	}

	public function legacy_relative_database_paths() {
		return array(
			'FQDB'    => array( array( 'FQDB' => 'legacy/database.sqlite' ) ),
			'DB_DIR'  => array(
				array(
					'DB_DIR'  => 'legacy',
					'DB_FILE' => 'database.sqlite',
				),
			),
			'FQDBDIR' => array(
				array(
					'FQDBDIR' => 'legacy/',
					'DB_FILE' => 'database.sqlite',
				),
			),
		);
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
		$script = sprintf(
			'$connection = new PDO(%s); $connection->exec("PRAGMA busy_timeout = 5000"); $connection->exec("BEGIN EXCLUSIVE"); echo "locked";',
			var_export( 'sqlite:' . $database_path, true )
		);

		list( $process, $pipes ) = $this->open_process( $script );

		try {
			$this->assert_process_is_running( $process );
		} finally {
			$storage->unlock();
			$result = $this->close_process( $process, $pipes );
		}

		$this->assertSame( 0, $result['exit_code'] );
		$this->assertSame( '', $result['error'] );
		$this->assertSame( 'locked', $result['output'] );
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

	/**
	 * @dataProvider invalid_explicit_database_paths
	 */
	public function test_rejects_invalid_explicit_database_paths_without_creating_files( $database_path ) {
		$this->assert_creates_no_files(
			function () use ( $database_path ) {
				try {
					new WP_SQLite_Storage( $this->create_temporary_directory_path(), $database_path );
					$this->fail( 'An invalid database path was accepted.' );
				} catch ( RuntimeException $exception ) {
					$this->assertSame( 'The SQLite database path must be an absolute filesystem path or ":memory:".', $exception->getMessage() );
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

	public function test_automatically_migrates_the_current_legacy_database() {
		$this->assert_legacy_database_is_migrated( '.ht.sqlite' );
	}

	public function test_automatically_migrates_the_older_legacy_database() {
		$this->assert_legacy_database_is_migrated( '.ht.sqlite.php' );
	}

	public function test_prefers_the_current_legacy_database() {
		$database_root = $this->create_temporary_directory();
		$current_path  = $database_root . '/.ht.sqlite';
		$older_path    = $database_root . '/.ht.sqlite.php';
		$this->create_sqlite_database( $current_path );
		$this->create_sqlite_database( $older_path );

		$database_path = $this->initialize_managed_storage( $database_root );

		$this->assertFileDoesNotExist( $current_path );
		$this->assertFileExists( $older_path );
		$this->assertSame( 'preserved', $this->read_sqlite_value( $database_path ) );
	}

	public function test_waits_for_existing_wal_connections_before_migrating() {
		$database_root = $this->create_temporary_directory();
		$legacy_path   = $database_root . '/.ht.sqlite';
		$this->create_wal_sqlite_database_copy( $legacy_path );

		list( $process, $pipes ) = $this->open_database_connection_process( $legacy_path );
		try {
			$database_path = $this->initialize_managed_storage( $database_root );
		} finally {
			$result = $this->close_process( $process, $pipes );
		}

		$this->assertSame( 0, $result['exit_code'] );
		$this->assertSame( '', $result['error'] );
		$this->assertFileDoesNotExist( $legacy_path );
		$this->assertFileDoesNotExist( $legacy_path . '-wal' );
		$this->assertSame( 'preserved', $this->read_sqlite_value( $database_path ) );
	}

	public function test_keeps_the_legacy_database_when_migration_fails() {
		$database_root = $this->create_temporary_directory();
		$legacy_path   = $database_root . '/.ht.sqlite';
		$database_path = $database_root . '/.ht.secret/.ht.sqlite';
		$this->create_sqlite_database( $legacy_path );
		$this->assertTrue( mkdir( $database_path, 0700, true ) );
		file_put_contents( $database_root . '/db-path.php', "<?php\nreturn " . var_export( $database_path, true ) . ";\n" );

		try {
			$this->initialize_managed_storage( $database_root );
			$this->fail( 'The database was migrated over a directory.' );
		} catch ( RuntimeException $exception ) {
			$this->assertSame( 'Failed to move the SQLite database file.', $exception->getMessage() );
			$this->assertStringNotContainsString( $database_path, $exception->getMessage() );
		}

		$this->assertFileExists( $legacy_path );
		$this->assertSame( 'preserved', $this->read_sqlite_value( $legacy_path ) );
		$this->assertFileExists( $database_root . '/.ht.sqlite.lock' );

		// The migration succeeds once the obstacle is removed.
		$this->assertTrue( rmdir( $database_path ) );
		$this->assertSame( $database_path, $this->initialize_managed_storage( $database_root ) );
		$this->assertFileDoesNotExist( $legacy_path );
		$this->assertSame( 'preserved', $this->read_sqlite_value( $database_path ) );
	}

	public function test_does_not_migrate_an_invalid_legacy_database() {
		$database_root = $this->create_temporary_directory();
		$legacy_path   = $database_root . '/.ht.sqlite';
		file_put_contents( $legacy_path, str_repeat( 'x', 4096 ) );

		try {
			$this->initialize_managed_storage( $database_root );
			$this->fail( 'An invalid legacy database was migrated.' );
		} catch ( RuntimeException $exception ) {
			$this->assertSame( 'Failed to lock the SQLite database.', $exception->getMessage() );
		}

		$this->assertSame( str_repeat( 'x', 4096 ), file_get_contents( $legacy_path ) );
		$this->assertFileDoesNotExist( $database_root . '/db-path.php' );
		$this->assertFileExists( $database_root . '/.ht.sqlite.lock' );
	}

	public function test_reports_when_the_migrated_database_cannot_be_locked() {
		$database_root = $this->create_temporary_directory();
		$legacy_path   = $database_root . '/.ht.sqlite';
		$database_path = $database_root . '/managed/.ht.sqlite';
		file_put_contents( $legacy_path, str_repeat( 'x', 4096 ) );
		$storage = new WP_SQLite_Storage( $database_root );
		$move    = Closure::bind(
			function () use ( $legacy_path, $database_path ) {
				$this->move_legacy_database( $legacy_path, $database_path );
			},
			$storage,
			WP_SQLite_Storage::class
		);

		try {
			$move();
			$this->fail( 'An invalid migrated database was locked.' );
		} catch ( RuntimeException $exception ) {
			$this->assertSame( 'Failed to lock the migrated SQLite database.', $exception->getMessage() );
			$this->assertStringNotContainsString( $database_path, $exception->getMessage() );
		}

		$this->assertFileDoesNotExist( $legacy_path );
		$this->assertSame( str_repeat( 'x', 4096 ), file_get_contents( $database_path ) );
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

	public function test_blocks_database_access_while_locked() {
		$database_root = $this->create_temporary_directory();
		$database_path = $database_root . '/database.sqlite';
		$connection    = $this->create_sqlite_database( $database_path );
		$this->assertSame( 'wal', $connection->query( 'PRAGMA journal_mode = WAL' )->fetchColumn() );
		$connection = null;

		$storage = new WP_SQLite_Storage( $database_root, $database_path );

		$storage->lock();

		try {
			$this->read_sqlite_value( $database_path );
			$this->fail( 'The database was readable while the storage was locked.' );
		} catch ( PDOException $exception ) {
			$this->assertStringContainsString( 'database is locked', $exception->getMessage() );
		}

		$storage->unlock();

		$this->assertSame( 'preserved', $this->read_sqlite_value( $database_path ) );
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

	public function test_waits_for_an_active_wal_connection() {
		$database_root = $this->create_temporary_directory();
		$database_path = $database_root . '/database.sqlite';
		$this->create_wal_sqlite_database_copy( $database_path );
		$storage = new WP_SQLite_Storage( $database_root, $database_path );

		list( $process, $pipes ) = $this->open_database_connection_process( $database_path );
		$started                 = microtime( true );
		try {
			$storage->lock();
		} finally {
			$elapsed = microtime( true ) - $started;
			$result  = $this->close_process( $process, $pipes );
		}
		$storage->unlock();

		$this->assertSame( 0, $result['exit_code'] );
		$this->assertSame( '', $result['error'] );
		$this->assertGreaterThan( 0.2, $elapsed );
		$this->assertFileDoesNotExist( $database_path . '-wal' );
		$this->assertSame( 'preserved', $this->read_sqlite_value( $database_path ) );
	}

	public function test_does_not_lock_an_invalid_database() {
		$database_root = $this->create_temporary_directory();
		$database_path = $database_root . '/database.sqlite';
		file_put_contents( $database_path, str_repeat( 'x', 4096 ) );

		try {
			$storage = new WP_SQLite_Storage( $database_root, $database_path );
			$storage->lock();
			$this->fail( 'An invalid database was locked.' );
		} catch ( RuntimeException $exception ) {
			$this->assertSame( 'Failed to lock the SQLite database.', $exception->getMessage() );
			$this->assertStringNotContainsString( $database_root, $exception->getMessage() );
		}

		$this->assertFileExists( $database_root . '/.ht.sqlite.lock' );
		$this->assertFileDoesNotExist( $database_root . '/.ht.sqlite.maintenance' );
	}

	public function test_does_not_create_a_missing_database() {
		if ( ! defined( 'PDO::SQLITE_ATTR_OPEN_FLAGS' ) && ! defined( 'Pdo\Sqlite::ATTR_OPEN_FLAGS' ) ) {
			$this->markTestSkipped( 'SQLite open flags require PHP 7.3.' );
		}

		$database_root = $this->create_temporary_directory();
		$database_path = $database_root . '/missing.sqlite';
		$storage       = new WP_SQLite_Storage( $database_root, $database_path );
		$lock_database = Closure::bind(
			function () use ( $database_path ) {
				return $this->lock_database( $database_path );
			},
			$storage,
			WP_SQLite_Storage::class
		);

		try {
			$lock_database();
			$this->fail( 'A missing database was created.' );
		} catch ( PDOException $exception ) {
			$this->assertStringNotContainsString( $database_path, $exception->getMessage() );
		}

		$this->assertFileDoesNotExist( $database_path );
	}

	public function test_does_not_lock_a_busy_database() {
		$database_root = $this->create_temporary_directory();
		$database_path = $database_root . '/database.sqlite';
		$connection    = $this->create_sqlite_database( $database_path );
		$connection->beginTransaction();
		$connection->exec( "UPDATE storage_test SET value = 'pending'" );
		$storage = new WP_SQLite_Storage( $database_root, $database_path );
		$this->set_storage_property( $storage, 'database_lock_timeout', 10 );

		try {
			$storage->lock();
			$this->fail( 'A busy database was locked.' );
		} catch ( RuntimeException $exception ) {
			$this->assertSame( 'Failed to lock the SQLite database.', $exception->getMessage() );
		} finally {
			$connection->rollBack();
		}

		$this->assertFileExists( $database_root . '/.ht.sqlite.lock' );
		$this->assertFileDoesNotExist( $database_root . '/.ht.sqlite.maintenance' );

		// The storage can be locked once the database is no longer busy.
		$storage->lock();
		$this->assertFileExists( $database_root . '/.ht.sqlite.lock' );
		$storage->unlock();
	}

	private function assert_legacy_database_is_migrated( $filename ) {
		$database_root = $this->create_temporary_directory();
		$legacy_path   = $database_root . '/' . $filename;
		$this->create_wal_sqlite_database_copy( $legacy_path );

		$database_path        = $this->initialize_managed_storage( $database_root );
		$stored_database_path = require $database_root . '/db-path.php';

		$this->assertSame( $database_path, $stored_database_path );
		$this->assertSame( 0600, fileperms( $database_path ) & 0777 );
		$this->assertFileDoesNotExist( $legacy_path );
		$this->assertFileDoesNotExist( $legacy_path . '-wal' );
		$this->assertFileExists( $database_root . '/.ht.sqlite.lock' );
		$this->assertSame( 'preserved', $this->read_sqlite_value( $database_path ) );
		$this->assert_protected_directory( dirname( $database_path ) );
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
			'require %s; $storage = new WP_SQLite_Storage(%s); ',
			var_export( WP_CONTENT_DIR . '/plugins/sqlite-database-integration/wp-includes/sqlite/class-wp-sqlite-storage.php', true ),
			var_export( $database_root, true )
		);

		return $this->open_process( $prelude . $script );
	}

	private function open_dropin_process( $settings, $script ) {
		$settings += array(
			'ABSPATH'   => ABSPATH,
			'WPINC'     => WPINC,
			'WP_DEBUG'  => false,
			'DB_ENGINE' => 'sqlite',
			'DB_NAME'   => 'storage_test',
		);
		$prelude   = sprintf(
			'foreach (%s as $name => $value) { define($name, $value); }
			require ABSPATH . WPINC . "/compat.php";
			require ABSPATH . WPINC . "/load.php";
			require ABSPATH . WPINC . "/plugin.php";
			require ABSPATH . WPINC . "/functions.php";
			require ABSPATH . WPINC . "/class-wpdb.php";
			require %s;',
			var_export( $settings, true ),
			var_export( WP_CONTENT_DIR . '/plugins/sqlite-database-integration/wp-includes/sqlite/db.php', true )
		);

		return $this->open_process( $prelude . $script );
	}

	private function open_database_connection_process( $database_path ) {
		$script = sprintf(
			'$connection = new PDO(%s); $connection->query("SELECT value FROM storage_test")->fetchColumn(); fwrite(STDOUT, "ready\n"); fflush(STDOUT); usleep(250000);',
			var_export( 'sqlite:' . $database_path, true )
		);

		list( $process, $pipes ) = $this->open_process( $script );
		$this->assertSame( "ready\n", fgets( $pipes[1] ) );

		return array( $process, $pipes );
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

	private function create_wal_sqlite_database_copy( $database_path ) {
		$source_path = $this->create_temporary_directory() . '/source.sqlite';

		$connection = new PDO( 'sqlite:' . $source_path );
		$connection->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
		$this->assertSame( 'wal', $connection->query( 'PRAGMA journal_mode = WAL' )->fetchColumn() );
		$connection->exec( 'PRAGMA wal_autocheckpoint = 0' );
		$connection->exec( 'CREATE TABLE storage_test (value TEXT NOT NULL)' );
		$connection->exec( "INSERT INTO storage_test VALUES ('preserved')" );

		// Copy the WAL while the source stays open, as closing it would checkpoint.
		$this->assertTrue( copy( $source_path, $database_path ) );
		$this->assertTrue( copy( $source_path . '-wal', $database_path . '-wal' ) );
		$connection = null;
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
