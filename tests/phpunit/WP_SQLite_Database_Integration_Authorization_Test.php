<?php

require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . WPINC . '/class-wp-admin-bar.php';
require_once WP_CONTENT_DIR . '/plugins/sqlite-database-integration/load.php';

class WP_SQLite_Database_Integration_Authorization_Test extends WP_UnitTestCase {

	/**
	 * @var array|null
	 */
	private $admin_menu_globals;

	/**
	 * @var int
	 */
	private static $site_id;

	/**
	 * @var int
	 */
	private static $subsite_admin_id;

	/**
	 * @var int
	 */
	private static $super_admin_id;

	public static function wpSetUpBeforeClass( WP_UnitTest_Factory $factory ) {
		if ( ! is_multisite() ) {
			return;
		}

		self::$subsite_admin_id = $factory->user->create( array( 'role' => 'subscriber' ) );
		self::$site_id          = $factory->blog->create(
			array(
				'path'    => '/sqlite-authorization/',
				'user_id' => self::$subsite_admin_id,
			)
		);
		self::$super_admin_id   = $factory->user->create( array( 'role' => 'subscriber' ) );

		add_user_to_blog( self::$site_id, self::$super_admin_id, 'administrator' );
		grant_super_admin( self::$super_admin_id );
	}

	public static function wpTearDownAfterClass() {
		if ( is_multisite() ) {
			revoke_super_admin( self::$super_admin_id );
			wp_delete_site( self::$site_id );
		}
	}

	public function tear_down() {
		if ( null !== $this->admin_menu_globals ) {
			foreach ( $this->admin_menu_globals as $name => $state ) {
				if ( $state['exists'] ) {
					$GLOBALS[ $name ] = $state['value'];
				} else {
					unset( $GLOBALS[ $name ] );
				}
			}
			$this->admin_menu_globals = null;
		}

		while ( is_multisite() && ms_is_switched() ) {
			restore_current_blog();
		}

		parent::tear_down();
	}

	public function test_plugin_is_loaded() {
		$this->assertTrue( defined( 'SQLITE_MAIN_FILE' ) );
		$this->assertFileExists( SQLITE_MAIN_FILE );
	}

	/**
	 * @group ms-excluded
	 */
	public function test_single_site_uses_site_admin() {
		$this->set_single_site_user( 'administrator' );
		$this->reset_admin_menu();

		$this->assertSame( 10, has_action( 'admin_menu', 'sqlite_add_admin_menu' ) );
		$this->assertFalse( has_action( 'network_admin_menu', 'sqlite_add_admin_menu' ) );
		$this->assertSame( 10, has_action( 'admin_notices', 'sqlite_plugin_admin_notice' ) );
		$this->assertFalse( has_action( 'network_admin_notices', 'sqlite_plugin_admin_notice' ) );
		$this->assertSame( admin_url( 'options-general.php?page=sqlite-integration' ), sqlite_plugin_get_admin_page_url() );

		sqlite_add_admin_menu();

		$this->assertSame( 10, has_action( 'settings_page_sqlite-integration', 'sqlite_integration_admin_screen' ) );
		$this->assert_admin_screen_is_accessible();
		$this->assertSame(
			admin_url( 'options-general.php?page=sqlite-integration' ),
			$this->get_activation_redirect()
		);
		$this->assertSame(
			admin_url( 'options-general.php?page=sqlite-integration' ),
			$this->get_admin_bar_node()->href
		);
		$this->assertStringContainsString( 'file is missing', $this->get_admin_notice_without_dropin() );
	}

	/**
	 * @group ms-required
	 */
	public function test_multisite_plugin_is_network_only() {
		$plugin_data = get_plugin_data( SQLITE_MAIN_FILE, false, false );

		$this->assertTrue( $plugin_data['Network'] );
	}

	/**
	 * @group ms-required
	 */
	public function test_multisite_uses_network_admin() {
		$this->set_multisite_user( true );
		$this->reset_admin_menu();

		$this->assertFalse( has_action( 'admin_menu', 'sqlite_add_admin_menu' ) );
		$this->assertSame( 10, has_action( 'network_admin_menu', 'sqlite_add_admin_menu' ) );
		$this->assertFalse( has_action( 'admin_notices', 'sqlite_plugin_admin_notice' ) );
		$this->assertSame( 10, has_action( 'network_admin_notices', 'sqlite_plugin_admin_notice' ) );
		$this->assertSame( network_admin_url( 'settings.php?page=sqlite-integration' ), sqlite_plugin_get_admin_page_url() );

		sqlite_add_admin_menu();

		$this->assertSame( 10, has_action( 'settings_page_sqlite-integration', 'sqlite_integration_admin_screen' ) );
		$this->assert_admin_screen_is_accessible();
		$this->assertSame(
			network_admin_url( 'settings.php?page=sqlite-integration' ),
			$this->get_activation_redirect()
		);
		$this->assertSame(
			network_admin_url( 'settings.php?page=sqlite-integration' ),
			$this->get_admin_bar_node()->href
		);
		$this->assertStringContainsString( 'file is missing', $this->get_admin_notice_without_dropin() );
	}

	/**
	 * @group ms-excluded
	 */
	public function test_single_site_subscriber_cannot_access_management_surfaces() {
		$this->set_single_site_user( 'subscriber' );

		$this->assertNull( $this->get_admin_bar_node() );
		$this->assertSame( '', $this->get_admin_notice_without_dropin() );
		$this->assertNull( $this->get_activation_redirect() );

		$this->reset_admin_menu();

		sqlite_add_admin_menu();

		$this->assertTrue( $GLOBALS['_wp_submenu_nopriv']['options-general.php']['sqlite-integration'] );

		$this->expectException( WPDieException::class );
		$this->expectExceptionCode( 403 );
		$this->expectExceptionMessage( 'Sorry, you are not allowed to access the SQLite integration settings.' );

		sqlite_integration_admin_screen();
	}

	/**
	 * @group ms-required
	 */
	public function test_subsite_administrator_cannot_access_management_surfaces() {
		$this->set_multisite_user( false );

		$this->assertNull( $this->get_admin_bar_node() );
		$this->assertSame( '', $this->get_admin_notice_without_dropin() );
		$this->assertNull( $this->get_activation_redirect() );

		$this->reset_admin_menu();

		sqlite_add_admin_menu();

		$this->assertTrue( $GLOBALS['_wp_submenu_nopriv']['settings.php']['sqlite-integration'] );

		$this->expectException( WPDieException::class );
		$this->expectExceptionCode( 403 );
		$this->expectExceptionMessage( 'Sorry, you are not allowed to access the SQLite integration settings.' );

		sqlite_integration_admin_screen();
	}

	/**
	 * @group ms-excluded
	 */
	public function test_other_plugin_activation_does_not_redirect() {
		$this->assertNull( $this->get_activation_redirect( 'another-plugin/plugin.php' ) );
	}

	/**
	 * @group ms-excluded
	 */
	public function test_confirm_install_is_ignored_on_another_page() {
		$_GET     = array(
			'page'            => 'another-page',
			'confirm-install' => '1',
			'_wpnonce'        => 'invalid',
		);
		$_REQUEST = $_GET;

		$this->assertNull( sqlite_activation() );
	}

	/**
	 * @group ms-excluded
	 */
	public function test_single_site_administrator_with_valid_nonce_can_install() {
		$this->set_single_site_user( 'administrator' );

		$this->assert_valid_nonce_reaches_install_redirect();
	}

	/**
	 * @group ms-excluded
	 */
	public function test_single_site_administrator_with_invalid_nonce_is_denied() {
		$this->set_single_site_user( 'administrator' );

		$this->assert_invalid_nonce_is_denied();
	}

	/**
	 * @group ms-excluded
	 */
	public function test_single_site_editor_with_valid_nonce_cannot_install() {
		$this->set_single_site_user( 'editor' );

		$this->assert_valid_nonce_install_is_denied();
	}

	/**
	 * @group ms-required
	 */
	public function test_super_admin_with_valid_nonce_can_install() {
		$this->set_multisite_user( true );

		$this->assert_valid_nonce_reaches_install_redirect();
	}

	/**
	 * @group ms-required
	 */
	public function test_super_admin_with_invalid_nonce_is_denied() {
		$this->set_multisite_user( true );

		$this->assert_invalid_nonce_is_denied();
	}

	/**
	 * @group ms-required
	 */
	public function test_subsite_administrator_with_valid_nonce_cannot_install() {
		$this->set_multisite_user( false );

		$this->assert_valid_nonce_install_is_denied();
	}

	/**
	 * @group ms-excluded
	 */
	public function test_single_site_administrator_can_deactivate_sqlite() {
		$this->set_single_site_user( 'administrator' );

		$this->assertTrue( current_user_can( 'deactivate_plugin', plugin_basename( SQLITE_MAIN_FILE ) ) );
		$this->assert_deactivation_removes_dropin();
	}

	/**
	 * @group ms-excluded
	 */
	public function test_single_site_plugin_manager_can_only_deactivate_sqlite_without_dropin() {
		$this->set_single_site_user( 'administrator' );
		wp_get_current_user()->add_cap( 'manage_options', false );

		$this->assertFalse( current_user_can( 'manage_options' ) );
		$this->assertTrue( current_user_can( 'deactivate_plugin', 'another-plugin/plugin.php' ) );
		$this->assertFalse( current_user_can( 'deactivate_plugin', plugin_basename( SQLITE_MAIN_FILE ) ) );

		$this->run_without_dropin(
			function () {
				$this->assertTrue( current_user_can( 'deactivate_plugin', plugin_basename( SQLITE_MAIN_FILE ) ) );
			}
		);
	}

	/**
	 * @group ms-required
	 */
	public function test_super_admin_can_deactivate_sqlite() {
		$this->set_multisite_user( true );

		$this->assertTrue( current_user_can( 'deactivate_plugin', plugin_basename( SQLITE_MAIN_FILE ) ) );
		$this->assert_deactivation_removes_dropin();
	}

	/**
	 * @group ms-required
	 */
	public function test_network_deactivation_context_is_forwarded_to_mysql_cleanup() {
		$this->set_multisite_user( true );
		$shutdown_hook_before = isset( $GLOBALS['wp_filter']['shutdown'] ) ? clone $GLOBALS['wp_filter']['shutdown'] : null;

		try {
			$this->run_with_deactivation_filesystem(
				function () {
					sqlite_plugin_remove_db_file( true );
				}
			);

			$callbacks = $GLOBALS['wp_filter']['shutdown']->callbacks[ PHP_INT_MAX ];
			$callback  = end( $callbacks );
			$variables = ( new ReflectionFunction( $callback['function'] ) )->getStaticVariables();

			$this->assertTrue( $variables['network_deactivating'] );
		} finally {
			if ( null !== $shutdown_hook_before ) {
				$GLOBALS['wp_filter']['shutdown'] = $shutdown_hook_before;
			} else {
				unset( $GLOBALS['wp_filter']['shutdown'] );
			}
		}
	}

	/**
	 * @group ms-required
	 */
	public function test_network_deactivation_updates_mysql_sitewide_plugins() {
		$sqlite_plugin = plugin_basename( SQLITE_MAIN_FILE );
		$other_plugin  = 'another-plugin/plugin.php';
		$network_id    = get_current_network_id();
		$wpdb_mysql    = $this->create_mysql_test_connection(
			array(
				$sqlite_plugin => 123,
				$other_plugin  => 456,
			)
		);

		sqlite_plugin_deactivate_in_mysql( $wpdb_mysql, true );

		$this->assertSame( array( $network_id, 'active_sitewide_plugins' ), $wpdb_mysql->prepared_args );
		$this->assertSame(
			array(
				'wp_sitemeta',
				array( 'meta_value' => maybe_serialize( array( $other_plugin => 456 ) ) ),
				array(
					'site_id'  => $network_id,
					'meta_key' => 'active_sitewide_plugins',
				),
			),
			$wpdb_mysql->update_args
		);
	}

	/**
	 * @group ms-excluded
	 */
	public function test_site_deactivation_updates_mysql_active_plugins() {
		$sqlite_plugin = plugin_basename( SQLITE_MAIN_FILE );
		$other_plugin  = 'another-plugin/plugin.php';
		$wpdb_mysql    = $this->create_mysql_test_connection( array( $sqlite_plugin, $other_plugin ) );

		sqlite_plugin_deactivate_in_mysql( $wpdb_mysql, false );

		$this->assertSame( array( 'active_plugins' ), $wpdb_mysql->prepared_args );
		$this->assertSame(
			array(
				'wp_options',
				array( 'option_value' => maybe_serialize( array( 1 => $other_plugin ) ) ),
				array( 'option_name' => 'active_plugins' ),
			),
			$wpdb_mysql->update_args
		);
	}

	/**
	 * @group ms-required
	 */
	public function test_delegated_network_plugin_manager_can_only_deactivate_sqlite_without_dropin() {
		$this->run_as_delegated_network_plugin_manager(
			function () {
				$this->assertTrue( current_user_can( 'deactivate_plugin', 'another-plugin/plugin.php' ) );
				$this->assertFalse( current_user_can( 'deactivate_plugin', plugin_basename( SQLITE_MAIN_FILE ) ) );

				$this->run_without_dropin(
					function () {
						$this->assertTrue( current_user_can( 'deactivate_plugin', plugin_basename( SQLITE_MAIN_FILE ) ) );
					}
				);
			}
		);
	}

	/**
	 * @group ms-excluded
	 */
	public function test_single_site_bulk_deactivation_skips_sqlite() {
		$this->set_single_site_user( 'administrator' );
		wp_get_current_user()->add_cap( 'manage_options', false );
		$plugins = array( 'another-plugin/plugin.php', plugin_basename( SQLITE_MAIN_FILE ) );

		foreach ( $plugins as $index => $plugin ) {
			if ( ! current_user_can( 'deactivate_plugin', $plugin ) ) {
				unset( $plugins[ $index ] );
			}
		}

		$this->assertSame( array( 'another-plugin/plugin.php' ), array_values( $plugins ) );
	}

	/**
	 * @group ms-required
	 */
	public function test_network_bulk_deactivation_skips_sqlite_and_continues() {
		$sqlite_plugin = plugin_basename( SQLITE_MAIN_FILE );
		$other_plugin  = 'another-plugin/plugin.php';
		$plugins       = array( $other_plugin, $sqlite_plugin );

		$this->run_as_delegated_network_plugin_manager(
			function () use ( $plugins, $sqlite_plugin, $other_plugin ) {
				$this->run_with_network_active_plugins(
					$plugins,
					function () use ( $plugins, $sqlite_plugin, $other_plugin ) {
						$dropin_before = file_get_contents( WP_CONTENT_DIR . '/db.php' );

						$this->set_bulk_deactivation_request( $plugins );
						$this->run_bulk_deactivation_preflight( 'plugins-network' );

						$this->assertSame( array( $other_plugin ), $_POST['checked'] );

						deactivate_plugins( $_POST['checked'], false, true );

						$this->assertTrue( is_plugin_active_for_network( $sqlite_plugin ) );
						$this->assertFalse( is_plugin_active_for_network( $other_plugin ) );
						$this->assertSame( $dropin_before, file_get_contents( WP_CONTENT_DIR . '/db.php' ) );
					}
				);
			}
		);
	}

	/**
	 * @group ms-required
	 */
	public function test_super_admin_can_bulk_deactivate_sqlite() {
		$this->set_multisite_user( true );
		$sqlite_plugin = plugin_basename( SQLITE_MAIN_FILE );

		$this->run_with_network_active_plugins(
			array( $sqlite_plugin ),
			function () use ( $sqlite_plugin ) {
				$this->set_bulk_deactivation_request( array( $sqlite_plugin ) );
				$this->run_bulk_deactivation_preflight( 'plugins-network' );

				$this->assertSame( array( $sqlite_plugin ), $_POST['checked'] );

				$filesystem = $this->run_with_deactivation_filesystem(
					function () use ( $sqlite_plugin ) {
						deactivate_plugins( $sqlite_plugin, false, true );
					}
				);

				$this->assertFalse( is_plugin_active_for_network( $sqlite_plugin ) );
				$this->assertSame( WP_CONTENT_DIR . '/db.php', $filesystem->deleted_path );
			}
		);
	}

	/**
	 * @group ms-required
	 */
	public function test_site_admin_bulk_preflight_does_not_modify_request() {
		$this->set_multisite_user( false );
		$sqlite_plugin = plugin_basename( SQLITE_MAIN_FILE );
		$plugins       = array( 'another-plugin/plugin.php', $sqlite_plugin );

		$this->set_bulk_deactivation_request( $plugins );
		$this->run_bulk_deactivation_preflight( 'plugins' );

		$this->assertSame( $plugins, $_POST['checked'] );
	}

	/**
	 * @group ms-required
	 */
	public function test_network_bulk_preflight_preserves_slashed_plugin_names() {
		$sqlite_plugin = plugin_basename( SQLITE_MAIN_FILE );
		$other_plugin  = "another-plugin/plugin's.php";

		$this->run_as_delegated_network_plugin_manager(
			function () use ( $sqlite_plugin, $other_plugin ) {
				$this->set_bulk_deactivation_request( array( $other_plugin, $sqlite_plugin ) );
				$_POST['checked'] = wp_slash( $_POST['checked'] );

				$this->run_bulk_deactivation_preflight( 'plugins-network' );

				$this->assertSame( wp_slash( array( $other_plugin ) ), $_POST['checked'] );
			}
		);
	}

	/**
	 * @group ms-required
	 */
	public function test_network_bulk_preflight_ignores_non_string_action() {
		$this->set_multisite_user( false );
		$plugins = array( plugin_basename( SQLITE_MAIN_FILE ) );

		$this->set_bulk_deactivation_request( $plugins );
		$_REQUEST['action'] = array( 'deactivate-selected' );

		$this->run_bulk_deactivation_preflight( 'plugins-network' );

		$this->assertSame( $plugins, $_POST['checked'] );
	}

	/**
	 * @group ms-required
	 */
	public function test_network_bulk_deactivation_allows_sqlite_without_dropin() {
		$sqlite_plugin = plugin_basename( SQLITE_MAIN_FILE );

		$this->run_as_delegated_network_plugin_manager(
			function () use ( $sqlite_plugin ) {
				$this->run_without_dropin(
					function () use ( $sqlite_plugin ) {
						$this->set_bulk_deactivation_request( array( $sqlite_plugin ) );
						$this->run_bulk_deactivation_preflight( 'plugins-network' );

						$this->assertSame( array( $sqlite_plugin ), $_POST['checked'] );
					}
				);
			}
		);
	}

	/**
	 * @group ms-required
	 */
	public function test_deactivation_hook_preserves_dropin_when_bulk_preflight_is_bypassed() {
		$sqlite_plugin = plugin_basename( SQLITE_MAIN_FILE );

		$this->run_as_delegated_network_plugin_manager(
			function () use ( $sqlite_plugin ) {
				$this->run_with_network_active_plugins(
					array( $sqlite_plugin ),
					function () use ( $sqlite_plugin ) {
						$filesystem = $this->run_with_deactivation_filesystem(
							function () use ( $sqlite_plugin ) {
								deactivate_plugins( $sqlite_plugin, false, true );
							}
						);

						$this->assertFalse( is_plugin_active_for_network( $sqlite_plugin ) );
						$this->assertNull( $filesystem->deleted_path );
					}
				);
			}
		);
	}

	/**
	 * @group ms-excluded
	 */
	public function test_deactivation_hook_allows_programmatic_call_without_user() {
		wp_set_current_user( 0 );

		$this->assert_deactivation_removes_dropin();
	}

	private function set_single_site_user( $role ) {
		$user_id = self::factory()->user->create( array( 'role' => $role ) );

		wp_set_current_user( $user_id );
	}

	private function set_multisite_user( $is_super_admin ) {
		$user_id = $is_super_admin ? self::$super_admin_id : self::$subsite_admin_id;

		switch_to_blog( self::$site_id );
		wp_set_current_user( $user_id );
	}

	private function run_as_delegated_network_plugin_manager( $callback ) {
		$this->set_multisite_user( false );

		$user         = wp_get_current_user();
		$capabilities = array( 'activate_plugins', 'manage_network_plugins' );

		foreach ( $capabilities as $capability ) {
			$user->add_cap( $capability );
		}

		try {
			return call_user_func( $callback );
		} finally {
			foreach ( $capabilities as $capability ) {
				$user->remove_cap( $capability );
			}
		}
	}

	private function run_with_network_active_plugins( $plugins, $callback ) {
		$active_before = get_site_option( 'active_sitewide_plugins', array() );
		$active        = $active_before;

		foreach ( $plugins as $plugin ) {
			$active[ $plugin ] = time();
		}
		update_site_option( 'active_sitewide_plugins', $active );

		try {
			return call_user_func( $callback );
		} finally {
			update_site_option( 'active_sitewide_plugins', $active_before );
		}
	}

	private function set_bulk_deactivation_request( $plugins ) {
		$_POST    = array( 'checked' => $plugins );
		$_REQUEST = array(
			'action' => 'deactivate-selected',
		);
	}

	private function run_bulk_deactivation_preflight( $screen_id ) {
		$screen_was_set = array_key_exists( 'current_screen', $GLOBALS );
		$screen_before  = $screen_was_set ? $GLOBALS['current_screen'] : null;

		$GLOBALS['current_screen'] = WP_Screen::get( $screen_id );

		try {
			sqlite_plugin_filter_network_bulk_deactivation();
		} finally {
			if ( $screen_was_set ) {
				$GLOBALS['current_screen'] = $screen_before;
			} else {
				unset( $GLOBALS['current_screen'] );
			}
		}
	}

	private function assert_deactivation_removes_dropin() {
		$filesystem = $this->run_with_deactivation_filesystem( 'sqlite_plugin_remove_db_file' );

		$this->assertSame( WP_CONTENT_DIR . '/db.php', $filesystem->deleted_path );
		$this->assertFileExists( WP_CONTENT_DIR . '/db.php' );
	}

	private function run_with_deactivation_filesystem( $callback ) {
		$wp_filesystem_was_set = array_key_exists( 'wp_filesystem', $GLOBALS );
		$wp_filesystem_before  = $wp_filesystem_was_set ? $GLOBALS['wp_filesystem'] : null;
		$filesystem            = new class() {
			/**
			 * @var string|null
			 */
			public $deleted_path;

			public function delete( $path ) {
				$this->deleted_path = $path;
				return true;
			}
		};

		$GLOBALS['wp_filesystem'] = $filesystem;

		try {
			call_user_func( $callback );
		} finally {
			if ( $wp_filesystem_was_set ) {
				$GLOBALS['wp_filesystem'] = $wp_filesystem_before;
			} else {
				unset( $GLOBALS['wp_filesystem'] );
			}
		}

		return $filesystem;
	}

	private function create_mysql_test_connection( $active_plugins ) {
		return new class( $active_plugins ) {
			public $options  = 'wp_options';
			public $sitemeta = 'wp_sitemeta';
			public $prepared_args;
			public $update_args;
			private $active_plugins;

			public function __construct( $active_plugins ) {
				$this->active_plugins = $active_plugins;
			}

			public function prepare( $query, ...$args ) {
				$this->prepared_args = $args;
				return $query;
			}

			public function get_row( $query ) {
				return (object) array( 'active_plugins' => maybe_serialize( $this->active_plugins ) );
			}

			public function update( $table, $data, $where ) {
				$this->update_args = array( $table, $data, $where );
			}
		};
	}

	private function reset_admin_menu() {
		$global_names = array(
			'menu',
			'submenu',
			'_wp_submenu_nopriv',
			'_registered_pages',
			'_parent_pages',
			'admin_page_hooks',
			'_wp_real_parent_file',
		);

		$this->admin_menu_globals = array();
		foreach ( $global_names as $name ) {
			$this->admin_menu_globals[ $name ] = array(
				'exists' => array_key_exists( $name, $GLOBALS ),
				'value'  => array_key_exists( $name, $GLOBALS ) ? $GLOBALS[ $name ] : null,
			);
		}

		$GLOBALS['menu']                 = array();
		$GLOBALS['submenu']              = array();
		$GLOBALS['_wp_submenu_nopriv']   = array();
		$GLOBALS['_registered_pages']    = array();
		$GLOBALS['_parent_pages']        = array();
		$GLOBALS['admin_page_hooks']     = array(
			is_multisite() ? 'settings.php' : 'options-general.php' => 'settings',
		);
		$GLOBALS['_wp_real_parent_file'] = array();
	}

	private function get_activation_redirect( $plugin = null ) {
		if ( null === $plugin ) {
			$plugin = plugin_basename( SQLITE_MAIN_FILE );
		}

		$redirect_url = null;
		$filter       = function ( $location ) use ( &$redirect_url ) {
			$redirect_url = $location;
			return false;
		};

		add_filter( 'wp_redirect', $filter );
		sqlite_plugin_activation_redirect( $plugin );
		remove_filter( 'wp_redirect', $filter );

		return $redirect_url;
	}

	private function get_admin_bar_node() {
		$admin_bar = new WP_Admin_Bar();

		sqlite_plugin_adminbar_item( $admin_bar );

		return $admin_bar->get_node( 'sqlite-db-integration' );
	}

	private function assert_admin_screen_is_accessible() {
		ob_start();
		sqlite_integration_admin_screen();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'SQLite is enabled.', $output );
		$this->assertStringContainsString( esc_url( self_admin_url( 'plugins.php' ) ), $output );
	}

	private function get_admin_notice_without_dropin() {
		return $this->run_without_dropin(
			function () {
				ob_start();
				sqlite_plugin_admin_notice();
				return ob_get_clean();
			}
		);
	}

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

	private function assert_valid_nonce_reaches_install_redirect() {
		$nonce            = wp_create_nonce( 'sqlite-install' );
		$redirect_url     = null;
		$redirect_reached = false;
		$filter           = function ( $location ) use ( &$redirect_url ) {
			$redirect_url = $location;
			throw new RuntimeException( 'Install redirect reached.' );
		};

		$this->set_install_request( $nonce );
		add_filter( 'wp_redirect', $filter );

		try {
			sqlite_activation();
		} catch ( RuntimeException $exception ) {
			$this->assertSame( 'Install redirect reached.', $exception->getMessage() );
			$redirect_reached = true;
		} finally {
			remove_filter( 'wp_redirect', $filter );
		}

		$this->assertTrue( $redirect_reached, 'The install request did not redirect.' );
		$this->assertSame( admin_url(), $redirect_url );
	}

	private function assert_valid_nonce_install_is_denied() {
		$dropin_before = file_get_contents( WP_CONTENT_DIR . '/db.php' );
		$nonce         = wp_create_nonce( 'sqlite-install' );
		$nonce_checked = false;
		$nonce_action  = function () use ( &$nonce_checked ) {
			$nonce_checked = true;
		};

		$this->assertNotFalse( wp_verify_nonce( $nonce, 'sqlite-install' ) );
		$this->set_install_request( $nonce );
		add_action( 'check_admin_referer', $nonce_action );

		try {
			sqlite_activation();
			$this->fail( 'The install request was not denied.' );
		} catch ( WPDieException $exception ) {
			$this->assertSame( 403, $exception->getCode() );
			$this->assertSame( 'Sorry, you are not allowed to install the SQLite database drop-in.', $exception->getMessage() );
		} finally {
			remove_action( 'check_admin_referer', $nonce_action );
		}

		$this->assertFalse( $nonce_checked, 'The capability check must run before nonce validation.' );
		$this->assertSame( $dropin_before, file_get_contents( WP_CONTENT_DIR . '/db.php' ) );
	}

	private function assert_invalid_nonce_is_denied() {
		$nonce_checked = false;
		$nonce_action  = function ( $action, $result ) use ( &$nonce_checked ) {
			$nonce_checked = true;
			$this->assertSame( 'sqlite-install', $action );
			$this->assertFalse( $result );
		};

		$this->set_install_request( 'invalid' );
		add_action( 'check_admin_referer', $nonce_action, 10, 2 );

		try {
			sqlite_activation();
			$this->fail( 'The invalid nonce was accepted.' );
		} catch ( WPDieException $exception ) {
			$this->assertSame( 403, $exception->getCode() );
		} finally {
			remove_action( 'check_admin_referer', $nonce_action );
		}

		$this->assertTrue( $nonce_checked );
	}

	private function set_install_request( $nonce ) {
		$_GET     = array(
			'page'            => 'sqlite-integration',
			'confirm-install' => '1',
			'_wpnonce'        => $nonce,
		);
		$_REQUEST = $_GET;
	}
}
