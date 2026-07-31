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
	private static $super_admin_id;

	public static function wpSetUpBeforeClass( WP_UnitTest_Factory $factory ) {
		if ( ! is_multisite() ) {
			return;
		}

		self::$super_admin_id = $factory->user->create( array( 'role' => 'subscriber' ) );
		grant_super_admin( self::$super_admin_id );
	}

	public static function wpTearDownAfterClass() {
		if ( is_multisite() ) {
			revoke_super_admin( self::$super_admin_id );
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
		$this->set_single_site_administrator();
		$this->reset_admin_menu();

		$this->assertSame( 10, has_action( 'admin_menu', 'sqlite_add_admin_menu' ) );
		$this->assertFalse( has_action( 'network_admin_menu', 'sqlite_add_admin_menu' ) );
		$this->assertSame( 10, has_action( 'admin_notices', 'sqlite_plugin_admin_notice' ) );
		$this->assertFalse( has_action( 'network_admin_notices', 'sqlite_plugin_admin_notice' ) );
		$this->assertSame( admin_url( 'options-general.php?page=sqlite-integration' ), sqlite_plugin_get_admin_page_url() );

		sqlite_add_admin_menu();

		$this->assertSame( 10, has_action( 'settings_page_sqlite-integration', 'sqlite_integration_admin_screen' ) );
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
		wp_set_current_user( self::$super_admin_id );
		$this->reset_admin_menu();

		$this->assertFalse( has_action( 'admin_menu', 'sqlite_add_admin_menu' ) );
		$this->assertSame( 10, has_action( 'network_admin_menu', 'sqlite_add_admin_menu' ) );
		$this->assertFalse( has_action( 'admin_notices', 'sqlite_plugin_admin_notice' ) );
		$this->assertSame( 10, has_action( 'network_admin_notices', 'sqlite_plugin_admin_notice' ) );
		$this->assertSame( network_admin_url( 'settings.php?page=sqlite-integration' ), sqlite_plugin_get_admin_page_url() );

		sqlite_add_admin_menu();

		$this->assertSame( 10, has_action( 'settings_page_sqlite-integration', 'sqlite_integration_admin_screen' ) );
	}

	/**
	 * @group ms-excluded
	 */
	public function test_single_site_activation_redirects_to_site_admin() {
		$this->assertSame(
			admin_url( 'options-general.php?page=sqlite-integration' ),
			$this->get_activation_redirect()
		);
	}

	/**
	 * @group ms-required
	 */
	public function test_multisite_activation_redirects_to_network_admin() {
		$this->assertSame(
			network_admin_url( 'settings.php?page=sqlite-integration' ),
			$this->get_activation_redirect()
		);
	}

	/**
	 * @group ms-excluded
	 */
	public function test_single_site_admin_bar_links_to_site_admin() {
		$admin_bar = new WP_Admin_Bar();

		sqlite_plugin_adminbar_item( $admin_bar );

		$this->assertSame(
			admin_url( 'options-general.php?page=sqlite-integration' ),
			$admin_bar->get_node( 'sqlite-db-integration' )->href
		);
	}

	/**
	 * @group ms-required
	 */
	public function test_multisite_admin_bar_links_to_network_admin() {
		$admin_bar = new WP_Admin_Bar();

		sqlite_plugin_adminbar_item( $admin_bar );

		$this->assertSame(
			network_admin_url( 'settings.php?page=sqlite-integration' ),
			$admin_bar->get_node( 'sqlite-db-integration' )->href
		);
	}

	/**
	 * @group ms-required
	 */
	public function test_network_deactivation_context_is_forwarded_to_mysql_cleanup() {
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

	private function set_single_site_administrator() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );

		wp_set_current_user( $user_id );
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

	private function get_activation_redirect() {
		$redirect_url = null;
		$filter       = function ( $location ) use ( &$redirect_url ) {
			$redirect_url = $location;
			return false;
		};

		add_filter( 'wp_redirect', $filter );
		sqlite_plugin_activation_redirect( plugin_basename( SQLITE_MAIN_FILE ) );
		remove_filter( 'wp_redirect', $filter );

		return $redirect_url;
	}
}
