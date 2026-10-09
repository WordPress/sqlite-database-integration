<?php

require_once WP_CONTENT_DIR . '/plugins/sqlite-database-integration/load.php';

class WP_SQLite_Storage_Health_Test extends WP_UnitTestCase {

	private $directory;
	private $document_root;

	public function set_up() {
		parent::set_up();
		$this->directory     = sys_get_temp_dir() . '/sqlite-storage-health-' . bin2hex( random_bytes( 8 ) );
		$this->document_root = $_SERVER['DOCUMENT_ROOT'] ?? null;
		$this->assertTrue( mkdir( $this->directory, 0700 ) );
	}

	public function tear_down() {
		wp_dequeue_style( 'sqlite-integration-admin' );
		wp_dequeue_script( 'sqlite-integration-admin' );
		if ( null === $this->document_root ) {
			unset( $_SERVER['DOCUMENT_ROOT'] );
		} else {
			$_SERVER['DOCUMENT_ROOT'] = $this->document_root;
		}
		$this->remove_directory( $this->directory );
		parent::tear_down();
	}

	/**
	 * @dataProvider database_locations
	 */
	public function test_checks_locations_without_initializing_storage( $settings, $document_root, $expected, $environment = array() ) {
		$this->assert_location_status( $settings, $document_root, $expected, $environment );
		$this->assertSame( array( '.', '..' ), scandir( $this->directory ) );
	}

	public function database_locations() {
		$filesystem_root = sys_get_temp_dir();
		while ( dirname( $filesystem_root ) !== $filesystem_root ) {
			$filesystem_root = dirname( $filesystem_root );
		}

		return array(
			'default storage'              => array( array(), null, 'public' ),
			'randomized path'              => array( array( 'DB_PATH' => '{root}/content/database/.ht.secret/.ht.sqlite' ), null, 'public' ),
			'WordPress directory'          => array( array( 'DB_PATH' => '{root}/wordpress/site.db' ), null, 'public' ),
			'document root only'           => array( array( 'DB_PATH' => '{root}/public/site.db' ), '{root}/public', 'public' ),
			'private explicit path'        => array( array( 'DB_PATH' => '{root}/private/site.db' ), '{root}/public', 'outside' ),
			'private path wins'            => array(
				array(
					'DB_PATH' => '{root}/private/site.db',
					'FQDB'    => '{root}/content/site.db',
				),
				null,
				'outside',
			),
			'legacy private file path'     => array( array( 'FQDB' => '{root}/private/legacy.db' ), null, 'outside' ),
			'legacy private directory'     => array( array( 'DB_DIR' => '{root}/private' ), null, 'outside' ),
			'legacy root wins'             => array(
				array(
					'FQDBDIR' => '{root}/private',
					'DB_DIR'  => '{root}/content',
				),
				null,
				'outside',
			),
			'legacy filename with parents' => array(
				array(
					'DB_DIR'  => '{root}/private',
					'DB_FILE' => '../content/site.db',
				),
				null,
				'public',
			),
			'legacy file without slash'    => array(
				array(
					'FQDBDIR' => '{root}/content',
					'DB_FILE' => '.db',
				),
				null,
				'outside',
			),
			'in-memory database'           => array(
				array(
					'DB_PATH' => ':memory:',
					'FQDB'    => '{root}/content/site.db',
				),
				null,
				'memory',
			),
			'legacy in-memory database'    => array( array( 'FQDB' => ':memory:' ), null, 'memory' ),
			'invalid explicit path'        => array(
				array(
					'DB_PATH' => false,
					'FQDB'    => '{root}/content/site.db',
				),
				null,
				'unknown',
			),
			'rejected explicit path'       => array(
				array(
					'DB_ENGINE' => 'sqlite',
					'DB_PATH'   => false,
				),
				null,
				'unknown',
			),
			'empty explicit path'          => array( array( 'DB_PATH' => '' ), null, 'unknown' ),
			'relative explicit path'       => array( array( 'DB_PATH' => 'content/site.db' ), null, 'unknown' ),
			'stream path'                  => array( array( 'DB_PATH' => 'file://{root}/content/site.db' ), null, 'unknown' ),
			'nul byte'                     => array( array( 'DB_PATH' => "{root}/content/site.db\0" ), null, 'unknown' ),
			'directory explicit path'      => array( array( 'DB_PATH' => '{root}/private/' ), null, 'unknown' ),
			'existing directory path'      => array( array( 'DB_PATH' => '{root}' ), null, 'unknown' ),
			'relative legacy directory'    => array( array( 'DB_DIR' => 'private' ), null, 'unknown' ),
			'empty legacy filename'        => array(
				array(
					'DB_DIR'  => '{root}/private',
					'DB_FILE' => '',
				),
				null,
				'unknown',
			),
			'sibling prefix'               => array( array( 'DB_PATH' => '{root}/content-private/site.db' ), null, 'outside' ),
			'dot segments inside'          => array( array( 'DB_PATH' => '{root}/private/../content/./site.db' ), null, 'public' ),
			'dot segments outside'         => array( array( 'DB_PATH' => '{root}/content/../private/site.db' ), null, 'outside' ),
			'repeated separators'          => array( array( 'DB_PATH' => '{root}//content//site.db' ), null, 'public' ),
			'root directory itself'        => array( array( 'DB_DIR' => '{root}/content/' ), null, 'public' ),
			'empty document root'          => array( array( 'DB_PATH' => '{root}/private/site.db' ), '', 'outside' ),
			'relative document root'       => array( array( 'DB_PATH' => '{root}/private/site.db' ), 'private', 'outside' ),
			'invalid document root'        => array( array( 'DB_PATH' => '{root}/private/site.db' ), array(), 'outside' ),
			'document root with nul byte'  => array( array( 'DB_PATH' => '{root}/public/site.db' ), "{root}/public\0", 'outside' ),
			'filesystem document root'     => array( array( 'DB_PATH' => '{root}/private/site.db' ), $filesystem_root, 'public' ),
			'slashed document root'        => array( array( 'DB_PATH' => "{root}/o'brien/site.db" ), addslashes( "{root}/o'brien" ), 'public' ),
			'context document root'        => array(
				array( 'DB_PATH' => '{root}/public/site.db' ),
				null,
				'public',
				array( 'CONTEXT_DOCUMENT_ROOT' => '{root}/public' ),
			),
			'plugin directory'             => array(
				array(
					'WP_PLUGIN_DIR' => '{root}/plugins',
					'DB_PATH'       => '{root}/plugins/site.db',
				),
				null,
				'public',
			),
			'must-use plugin directory'    => array(
				array(
					'WPMU_PLUGIN_DIR' => '{root}/mu-plugins',
					'DB_PATH'         => '{root}/mu-plugins/site.db',
				),
				null,
				'public',
			),
			'uploads directory'            => array(
				array( 'DB_PATH' => '{root}/media/site.db' ),
				null,
				'public',
				array( 'upload_dir' => '{root}/media' ),
			),
			'site above WordPress'         => array(
				array(
					'ABSPATH' => '{root}/site/wp/',
					'DB_PATH' => '{root}/site/site.db',
				),
				null,
				'public',
				array( 'siteurl' => 'https://example.com/wp' ),
			),
			'encoded WordPress directory'  => array(
				array(
					'ABSPATH' => '{root}/site/my wp/',
					'DB_PATH' => '{root}/site/site.db',
				),
				null,
				'public',
				array(
					'home'    => 'http://example.com/',
					'siteurl' => 'https://EXAMPLE.com/My%20WP',
				),
			),
			'mismatched WordPress path'    => array(
				array( 'DB_PATH' => '{root}/site.db' ),
				null,
				'outside',
				array( 'siteurl' => 'https://example.com/wp' ),
			),
			'WordPress on another host'    => array(
				array(
					'ABSPATH' => '{root}/site/wp/',
					'DB_PATH' => '{root}/site/site.db',
				),
				null,
				'outside',
				array( 'siteurl' => 'https://wp.example.com/wp' ),
			),
		);
	}

	/**
	 * @dataProvider database_settings
	 */
	public function test_resolves_the_same_location_as_the_dropin( $settings ) {
		$output = $this->run_isolated(
			$settings + array( 'DB_ENGINE' => 'sqlite' ),
			'$location = sqlite_plugin_get_database_location();'
			// Choose the storage like wp-includes/sqlite/db.php after it loads constants.php.
			. '$dropin_location = defined( "DB_PATH" ) ? array( DB_PATH, false ) : ( defined( "FQDB" ) ? array( FQDB, false ) : array( FQDBDIR, true ) );'
			. 'echo json_encode( array( $location, $dropin_location ) );'
		);

		list( $location, $dropin_location ) = json_decode( $output, true );
		$this->assertSame( $dropin_location, $location );
	}

	public function test_keeps_rejecting_settings_that_stop_constants_early() {
		if ( PHP_VERSION_ID < 80000 ) {
			$this->markTestSkipped( 'PHP 7 does not throw for an invalid DB_DIR type.' );
		}

		// constants.php runs only once, so the second check sees FQDBDIR undefined.
		$this->assertSame(
			'unknown unknown',
			$this->run_isolated(
				array( 'DB_DIR' => array() ),
				'echo sqlite_plugin_get_database_location_status(), " ", sqlite_plugin_get_database_location_status();'
			)
		);
	}

	public function database_settings() {
		return array(
			'default storage'            => array( array() ),
			'explicit path'              => array( array( 'DB_PATH' => '{root}/private/site.db' ) ),
			'in-memory database'         => array( array( 'DB_PATH' => ':memory:' ) ),
			'legacy file path'           => array( array( 'FQDB' => '{root}/private/site.db' ) ),
			'legacy root'                => array( array( 'FQDBDIR' => '{root}/private/' ) ),
			'legacy directory'           => array( array( 'DB_DIR' => '{root}/private' ) ),
			'legacy directory separator' => array( array( 'DB_DIR' => '{root}/private\\' ) ),
			'legacy filename'            => array( array( 'DB_FILE' => 'site.db' ) ),
			'numeric legacy filename'    => array( array( 'DB_FILE' => 5 ) ),
			'legacy directory and file'  => array(
				array(
					'DB_DIR'  => '{root}/private',
					'DB_FILE' => 'site.db',
				),
			),
			'legacy root and file'       => array(
				array(
					'FQDBDIR' => '{root}/private',
					'DB_FILE' => 'site.db',
				),
			),
			'legacy root wins'           => array(
				array(
					'FQDBDIR' => '{root}/private/',
					'DB_DIR'  => '{root}/content',
				),
			),
			'legacy file path wins'      => array(
				array(
					'FQDB'    => '{root}/private/site.db',
					'DB_DIR'  => '{root}/content',
					'DB_FILE' => 'other.db',
				),
			),
		);
	}

	public function test_checks_symlinks_in_both_directions_and_before_creation() {
		$this->assertTrue( mkdir( $this->directory . '/content' ) );
		$this->assertTrue( mkdir( $this->directory . '/private' ) );
		$this->assertTrue( symlink( $this->directory . '/private', $this->directory . '/content/link' ) );
		$this->assertTrue( symlink( $this->directory . '/content', $this->directory . '/private/link' ) );

		$this->assert_location_status( array( 'DB_PATH' => '{root}/content/link/new/site.db' ), null, 'public' );
		$this->assert_location_status( array( 'DB_PATH' => '{root}/private/link/new/site.db' ), null, 'public' );
		$this->assertFileDoesNotExist( $this->directory . '/private/new' );
		$this->assertFileDoesNotExist( $this->directory . '/content/new' );
	}

	public function test_respects_filesystem_case_sensitivity() {
		$this->assertTrue( mkdir( $this->directory . '/content' ) );
		$same_directory = is_dir( $this->directory . '/CONTENT' );
		if ( ! $same_directory ) {
			$this->assertTrue( mkdir( $this->directory . '/CONTENT' ) );
		}
		file_put_contents( $this->directory . '/CONTENT/site.db', 'Existing database contents.' );
		$status = $same_directory ? 'public' : 'outside';

		$this->assert_location_status( array( 'DB_PATH' => '{root}/CONTENT/site.db' ), null, $status );
		$this->assert_location_status( array( 'DB_PATH' => '{root}/CONTENT/new/site.db' ), null, $status );
		$this->assert_location_status(
			array(
				'WP_CONTENT_DIR' => '{root}/CONTENT',
				'DB_PATH'        => '{root}/content/new/site.db',
			),
			null,
			$status
		);
		$this->assertFileDoesNotExist( $this->directory . '/CONTENT/new' );
		$this->assertSame( 'Existing database contents.', file_get_contents( $this->directory . '/CONTENT/site.db' ) );
	}

	public function test_checks_the_target_of_a_symlinked_content_directory() {
		$this->assertTrue( mkdir( $this->directory . '/shared-content' ) );
		$this->assertTrue( symlink( $this->directory . '/shared-content', $this->directory . '/content' ) );

		$this->assert_location_status( array( 'DB_PATH' => '{root}/shared-content/site.db' ), null, 'public' );
	}

	public function test_keeps_the_warning_for_a_broken_symlink_in_a_public_directory() {
		$this->assertTrue( mkdir( $this->directory . '/content' ) );
		$this->assertTrue( symlink( $this->directory . '/missing', $this->directory . '/content/link' ) );

		$this->assert_location_status( array( 'DB_PATH' => '{root}/content/link/site.db' ), null, 'public' );
	}

	public function test_site_health_recommends_review_without_disclosing_the_path() {
		$this->set_management_user();
		$_SERVER['DOCUMENT_ROOT'] = dirname( DB_PATH );

		$tests  = sqlite_plugin_filter_site_status_tests( array( 'direct' => array() ) );
		$test   = $tests['direct']['sqlite_database_location'];
		$result = call_user_func( $test['test'] );

		$this->assertTrue( $test['skip_cron'] );
		$this->assertSame( 'recommended', $result['status'] );
		$this->assertSame( 'Security', $result['badge']['label'] );
		$this->assertStringContainsString( 'The SQLite database location may be publicly accessible.', $result['description'] );
		$settings_url = is_multisite()
			? network_admin_url( 'settings.php?page=sqlite-integration' )
			: admin_url( 'options-general.php?page=sqlite-integration' );
		$this->assertStringContainsString(
			wp_kses_post( '<a href="' . esc_url( add_query_arg( 'tab', 'protection', $settings_url ) . '#sqlite-database-protection' ) . '">Learn how to protect your database</a>' ),
			$result['description']
		);
		$this->assertStringNotContainsString( DB_PATH, wp_json_encode( $result ) );
		$this->assertStringNotContainsString( basename( dirname( DB_PATH ) ), $result['description'] );
	}

	public function test_site_health_omits_the_check_for_users_who_cannot_manage_storage() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => is_multisite() ? 'administrator' : 'subscriber' ) ) );
		$_SERVER['DOCUMENT_ROOT'] = dirname( DB_PATH );

		$tests = sqlite_plugin_filter_site_status_tests( array( 'direct' => array() ) );

		$this->assertArrayNotHasKey( 'sqlite_database_location', $tests['direct'] );
	}

	public function test_site_health_omits_the_check_on_local_sites() {
		$this->set_management_user();
		$_SERVER['DOCUMENT_ROOT'] = dirname( DB_PATH );

		$environment      = getenv( 'WP_ENVIRONMENT_TYPE' );
		$environment_type = wp_get_environment_type();
		putenv( 'WP_ENVIRONMENT_TYPE=local' );
		try {
			$tests = sqlite_plugin_filter_site_status_tests( array( 'direct' => array() ) );
		} finally {
			// wp_get_environment_type() keeps the last value it read, so let it read the previous type.
			putenv( 'WP_ENVIRONMENT_TYPE=' . $environment_type );
			wp_get_environment_type();
			putenv( false === $environment ? 'WP_ENVIRONMENT_TYPE' : 'WP_ENVIRONMENT_TYPE=' . $environment );
		}

		$this->assertArrayNotHasKey( 'sqlite_database_location', $tests['direct'] );
		$this->assertSame( $environment_type, wp_get_environment_type() );
	}

	public function test_settings_screen_shows_the_same_warning() {
		$this->set_management_user();
		$_SERVER['DOCUMENT_ROOT'] = dirname( DB_PATH );
		$_GET['tab']              = 'protection';

		ob_start();
		sqlite_integration_admin_screen();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'dashicons-warning sqlite-warning', $html );
		$this->assertStringContainsString( wp_kses_post( wpautop( sqlite_plugin_get_database_location_warning() ) ), $html );
		$this->assertStringContainsString( 'id="sqlite-database-protection"', $html );
		$this->assertStringContainsString( 'Public access has not been tested.', $html );
		$this->assertStringContainsString( 'Changing <code>DB_PATH</code> does not move an existing database.', $html );
		$this->assertStringNotContainsString( DB_PATH, $html );
	}

	public function test_overview_shows_database_details_and_links_to_protection() {
		$this->set_management_user();
		unset( $_GET['tab'] );
		$_SERVER['DOCUMENT_ROOT'] = dirname( DB_PATH );
		ob_start();
		sqlite_integration_admin_screen();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'SQLite is active', $html );
		$this->assertStringContainsString( 'Database size', $html );
		$this->assertStringContainsString( 'Includes the database and its supporting files.', $html );
		$this->assertStringContainsString( '<details class="sqlite-path">', $html );
		$this->assertStringNotContainsString( '<details class="sqlite-path" open', $html );
		$this->assertStringContainsString( esc_html( DB_PATH ), $html );
		$this->assertStringContainsString( 'Copy path', $html );
		$this->assertStringContainsString( 'View protection status', $html );
		$this->assertStringNotContainsString( 'id="sqlite-database-protection"', $html );
	}

	public function test_outside_location_has_a_limited_positive_result() {
		ob_start();
		sqlite_plugin_render_protection_status( 'outside' );
		$html = ob_get_clean();

		$this->assertStringContainsString( 'Outside known public roots', $html );
		$this->assertStringContainsString( 'dashicons-yes-alt sqlite-success', $html );
		$this->assertStringContainsString( 'Other websites, aliases, or server rules may still expose this folder.', $html );
		$this->assertStringContainsString( 'Public access has not been tested.', $html );
		$this->assertStringNotContainsString( 'sqlite-warning', $html );
	}

	/**
	 * @dataProvider invalid_tabs
	 */
	public function test_invalid_tabs_fall_back_to_overview( $tab ) {
		$this->set_management_user();
		$_GET['tab'] = $tab;
		ob_start();
		sqlite_integration_admin_screen();
		$html = ob_get_clean();
		$this->assertStringContainsString( 'id="sqlite-status-heading"', $html );
		$this->assertStringNotContainsString( '<script>', $html );
	}

	public function invalid_tabs() {
		return array( array( array( 'protection' ) ), array( '<script>alert(1)</script>' ), array( 'other' ) );
	}

	public function test_size_includes_only_the_database_and_sqlite_sidecars() {
		$path = $this->directory . '/database.sqlite';
		foreach ( array(
			''         => 1024,
			'-wal'     => 256,
			'-shm'     => 128,
			'-journal' => 64,
			'.backup'  => 999,
		) as $suffix => $size ) {
			file_put_contents( $path . $suffix, str_repeat( 'x', $size ) );
		}
		$this->assertSame( 1472, sqlite_plugin_get_database_size( $path ) );
		file_put_contents( $path . '-wal', 'x' );
		$this->assertSame( 1217, sqlite_plugin_get_database_size( $path ) );
		unlink( $path . '-wal' );
		$this->assertSame( 1216, sqlite_plugin_get_database_size( $path ) );
	}

	/**
	 * @dataProvider database_journal_modes
	 */
	public function test_size_includes_sidecars_when_opened_through_a_symlink( $journal_mode, $suffixes ) {
		$path = $this->directory . '/database.sqlite';
		$link = $this->directory . '/link.sqlite';
		file_put_contents( $path, '' );
		$this->assertTrue( symlink( $path, $link ) );

		$pdo = new PDO( 'sqlite:' . $link );
		try {
			$this->assertSame( strtolower( $journal_mode ), $pdo->query( 'PRAGMA journal_mode = ' . $journal_mode )->fetchColumn() );
			$pdo->exec( 'CREATE TABLE test (id INTEGER)' );
			$pdo->beginTransaction();
			$pdo->exec( 'INSERT INTO test VALUES (1)' );

			$database      = $pdo->query( 'PRAGMA database_list' )->fetch( PDO::FETCH_ASSOC );
			$database_path = $database['file'];
			$unused_path   = basename( $database_path ) === basename( $link ) ? $path : $link;

			// Only count sidecars beside the path SQLite actually uses.
			foreach ( array( '-wal', '-shm', '-journal' ) as $suffix ) {
				file_put_contents( $unused_path . $suffix, 'Unrelated file.' );
			}

			clearstatcache();
			$expected = filesize( $database_path );
			foreach ( $suffixes as $suffix ) {
				$this->assertFileExists( $database_path . $suffix );
				$this->assertGreaterThan( 0, filesize( $database_path . $suffix ) );
				$expected += filesize( $database_path . $suffix );
			}
			$this->assertSame( $expected, sqlite_plugin_get_database_size( $database_path ) );
			$this->assertSame( $expected, sqlite_plugin_get_database_size( $link ) );
		} finally {
			$pdo = null;
		}
	}

	public function database_journal_modes() {
		return array(
			'write-ahead log'  => array( 'WAL', array( '-wal', '-shm' ) ),
			'rollback journal' => array( 'DELETE', array( '-journal' ) ),
		);
	}

	public function test_size_handles_empty_missing_and_in_memory_databases() {
		$path = $this->directory . '/empty.sqlite';
		file_put_contents( $path, '' );
		$this->assertSame( 0, sqlite_plugin_get_database_size( $path ) );
		$this->assertNull( sqlite_plugin_get_database_size( $path . '.missing' ) );
		$this->assertNull( sqlite_plugin_get_database_size( ':memory:' ) );
		$this->assertTrue( symlink( $path . '.missing', $path . '.link' ) );
		$this->assertNull( sqlite_plugin_get_database_size( $path . '.link' ) );
	}

	public function test_site_health_info_reports_the_same_size_as_the_overview() {
		$info = sqlite_plugin_filter_debug_data( array() );
		ob_start();
		sqlite_plugin_render_database_details( DB_PATH );
		$html = ob_get_clean();
		$this->assertStringContainsString( esc_html( $info['wp-database']['fields']['database_size']['value'] ), $html );
	}

	public function test_database_path_is_escaped_and_never_a_download_link() {
		$path = $this->directory . '/<script>alert("path")</script>.sqlite';
		ob_start();
		sqlite_plugin_render_database_details( $path );
		$html = ob_get_clean();
		$this->assertStringContainsString( esc_html( $path ), $html );
		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringNotContainsString( 'href=', $html );
	}

	public function test_in_memory_database_does_not_show_file_controls() {
		ob_start();
		sqlite_plugin_render_database_details( ':memory:' );
		$html = ob_get_clean();
		$this->assertStringContainsString( 'In memory', $html );
		$this->assertStringContainsString( 'Not available', $html );
		$this->assertStringNotContainsString( 'Copy path', $html );
	}

	public function test_assets_load_only_on_the_settings_screen() {
		sqlite_plugin_enqueue_admin_assets( 'index.php' );
		$this->assertFalse( wp_style_is( 'sqlite-integration-admin', 'enqueued' ) );
		$this->assertFalse( wp_script_is( 'sqlite-integration-admin', 'enqueued' ) );
		sqlite_plugin_enqueue_admin_assets( 'settings_page_sqlite-integration' );
		$this->assertTrue( wp_style_is( 'sqlite-integration-admin', 'enqueued' ) );
		$this->assertTrue( wp_script_is( 'sqlite-integration-admin', 'enqueued' ) );
	}

	private function assert_location_status( $settings, $document_root, $expected, $environment = array() ) {
		$this->assertSame(
			$expected,
			$this->run_isolated( $settings, 'echo sqlite_plugin_get_database_location_status();', $document_root, $environment )
		);
	}

	/**
	 * Run code with the location checks in a separate process, which can define its own constants.
	 */
	private function run_isolated( $settings, $code, $document_root = null, $environment = array() ) {
		$settings    += array(
			'ABSPATH'         => '{root}/wordpress/',
			'WP_CONTENT_DIR'  => '{root}/content',
			'WP_PLUGIN_DIR'   => '{root}/content/plugins',
			'WPMU_PLUGIN_DIR' => '{root}/content/mu-plugins',
		);
		$environment += array(
			'home'                  => 'https://example.com',
			'siteurl'               => 'https://example.com',
			'upload_dir'            => '{root}/content/uploads',
			'CONTEXT_DOCUMENT_ROOT' => null,
		);
		$expand       = function ( $value ) {
			return is_string( $value ) ? str_replace( '{root}', $this->directory, $value ) : $value;
		};

		$script = '';
		foreach ( $settings as $name => $value ) {
			$script .= sprintf( 'define(%s, %s);', var_export( $name, true ), var_export( $expand( $value ), true ) );
		}
		// Stub the WordPress state that the check reads. Creating upload directories fails the test.
		$script .= sprintf(
			'$_SERVER["DOCUMENT_ROOT"] = %s; $_SERVER["CONTEXT_DOCUMENT_ROOT"] = %s;'
			. 'function get_option( $name ) { return %s[ $name ]; }'
			. 'function wp_upload_dir( $time = null, $create_dir = true ) { if ( $create_dir ) { trigger_error( "Upload directories must not be created." ); } return array( "basedir" => %s ); }'
			. 'require %s; require %s; %s',
			var_export( $expand( $document_root ), true ),
			var_export( $expand( $environment['CONTEXT_DOCUMENT_ROOT'] ), true ),
			var_export(
				array(
					'home'    => $environment['home'],
					'siteurl' => $environment['siteurl'],
				),
				true
			),
			var_export( $expand( $environment['upload_dir'] ), true ),
			var_export( ABSPATH . WPINC . '/formatting.php', true ),
			var_export( WP_CONTENT_DIR . '/plugins/sqlite-database-integration/storage-health.php', true ),
			$code
		);
		// Unrelated CLI messages, such as Xdebug's, go to stderr. PHP warnings go to stdout and fail the test.
		$process = proc_open(
			escapeshellarg( PHP_BINARY ) . ' -d display_errors=stdout -d error_reporting=-1 -r ' . escapeshellarg( $script ),
			array( array( 'pipe', 'r' ), array( 'pipe', 'w' ), array( 'pipe', 'w' ) ),
			$pipes
		);
		$this->assertIsResource( $process );
		fclose( $pipes[0] );
		$output = stream_get_contents( $pipes[1] );
		$error  = stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		$this->assertSame( 0, proc_close( $process ), $error . $output );
		return $output;
	}

	private function set_management_user() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		if ( is_multisite() ) {
			grant_super_admin( $user_id );
		}
		wp_set_current_user( $user_id );
	}

	private function remove_directory( $directory ) {
		foreach ( scandir( $directory ) as $name ) {
			if ( '.' === $name || '..' === $name ) {
				continue;
			}
			$path = $directory . '/' . $name;
			if ( is_dir( $path ) && ! is_link( $path ) ) {
				$this->remove_directory( $path );
			} else {
				unlink( $path );
			}
		}
		rmdir( $directory );
	}
}
