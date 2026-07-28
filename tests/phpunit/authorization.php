<?php
/**
 * SQLite Database Integration authorization tests.
 *
 * @package wp-sqlite-integration
 */

require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . WPINC . '/class-wp-admin-bar.php';
require_once ABSPATH . 'wp-content/plugins/sqlite-database-integration/load.php';

/**
 * Tests the permissions protecting the SQLite database drop-in.
 *
 * @group sqlite-database-integration
 */
class Tests_SQLite_Database_Integration_Authorization extends WP_UnitTestCase {

	/**
	 * Subsite used by multisite tests.
	 *
	 * @var int
	 */
	private static $site_id;

	/**
	 * Subsite administrator used by multisite tests.
	 *
	 * @var int
	 */
	private static $subsite_admin_id;

	/**
	 * Super administrator used by multisite tests.
	 *
	 * @var int
	 */
	private static $super_admin_id;

	/**
	 * Create multisite fixtures outside per-test database transactions.
	 *
	 * @param WP_UnitTest_Factory $factory Test fixture factory.
	 */
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

	/**
	 * Remove multisite fixtures.
	 */
	public static function wpTearDownAfterClass() {
		if ( ! is_multisite() ) {
			return;
		}

		revoke_super_admin( self::$super_admin_id );
		wp_delete_site( self::$site_id );
	}

	/**
	 * Verify that the required capability follows the scope of the drop-in.
	 */
	public function test_manage_capability_matches_installation_scope() {
		$expected = is_multisite() ? 'manage_network_options' : 'manage_options';

		$this->assertSame( $expected, sqlite_plugin_get_manage_capability() );
	}

	/**
	 * Verify that a single-site administrator can access the settings page.
	 *
	 * @group ms-excluded
	 */
	public function test_single_site_administrator_can_access_admin_page() {
		$this->set_single_site_user( 'administrator' );
		$this->reset_admin_menu();

		sqlite_add_admin_menu();

		$this->assertSame( 10, has_action( 'settings_page_sqlite-integration', 'sqlite_integration_admin_screen' ) );
		$this->assert_admin_screen_is_accessible();
	}

	/**
	 * Verify that the existing single-site admin-bar behavior is preserved.
	 *
	 * @group ms-excluded
	 */
	public function test_single_site_admin_bar_item_remains_visible_to_subscribers() {
		$this->set_single_site_user( 'subscriber' );
		$admin_bar = new WP_Admin_Bar();

		sqlite_plugin_adminbar_item( $admin_bar );

		$this->assertNotNull( $admin_bar->get_node( 'sqlite-db-integration' ) );
	}

	/**
	 * Verify that a single-site administrator is redirected after activation.
	 *
	 * @group ms-excluded
	 */
	public function test_single_site_administrator_is_redirected_after_activation() {
		$this->set_single_site_user( 'administrator' );

		$this->assertSame(
			admin_url( 'options-general.php?page=sqlite-integration' ),
			$this->get_activation_redirect( plugin_basename( SQLITE_MAIN_FILE ) )
		);
	}

	/**
	 * Verify that activating another plugin does not trigger the redirect.
	 *
	 * @group ms-excluded
	 */
	public function test_other_plugin_activation_does_not_redirect() {
		$this->set_single_site_user( 'administrator' );

		$this->assertNull( $this->get_activation_redirect( 'another-plugin/plugin.php' ) );
	}

	/**
	 * Verify that a single-site administrator can submit a valid install nonce.
	 *
	 * @group ms-excluded
	 */
	public function test_single_site_administrator_with_valid_nonce_reaches_install_redirect() {
		$this->set_single_site_user( 'administrator' );

		$this->assert_valid_nonce_reaches_install_redirect();
	}

	/**
	 * Verify that a single-site administrator still needs a valid nonce.
	 *
	 * @group ms-excluded
	 */
	public function test_single_site_administrator_with_invalid_nonce_is_denied() {
		$this->set_single_site_user( 'administrator' );

		$this->assert_invalid_nonce_is_denied();
	}

	/**
	 * Verify that a subsite administrator cannot register the settings page.
	 *
	 * @group ms-required
	 */
	public function test_subsite_administrator_cannot_register_admin_page() {
		$this->set_multisite_user( false );
		$this->reset_admin_menu();

		sqlite_add_admin_menu();

		$this->assertFalse( has_action( 'settings_page_sqlite-integration', 'sqlite_integration_admin_screen' ) );
		$this->assertTrue( $GLOBALS['_wp_submenu_nopriv']['options-general.php']['sqlite-integration'] );
	}

	/**
	 * Verify that a direct page callback cannot expose an install nonce.
	 *
	 * @group ms-required
	 */
	public function test_subsite_administrator_cannot_render_admin_page() {
		$this->set_multisite_user( false );

		$this->expectException( WPDieException::class );
		$this->expectExceptionCode( 403 );

		sqlite_integration_admin_screen();
	}

	/**
	 * Verify that a subsite administrator does not see the admin-bar item.
	 *
	 * @group ms-required
	 */
	public function test_subsite_administrator_does_not_see_admin_bar_item() {
		$this->set_multisite_user( false );
		$admin_bar = new WP_Admin_Bar();

		sqlite_plugin_adminbar_item( $admin_bar );

		$this->assertNull( $admin_bar->get_node( 'sqlite-db-integration' ) );
	}

	/**
	 * Verify that activation does not redirect a subsite administrator to a forbidden page.
	 *
	 * @group ms-required
	 */
	public function test_subsite_administrator_is_not_redirected_after_activation() {
		$this->set_multisite_user( false );

		$this->assertNull( $this->get_activation_redirect( plugin_basename( SQLITE_MAIN_FILE ) ) );
	}

	/**
	 * Verify that a valid nonce cannot replace authorization.
	 *
	 * @group ms-required
	 */
	public function test_subsite_administrator_with_valid_nonce_cannot_install_dropin() {
		$this->set_multisite_user( false );

		$dropin_before = file_get_contents( WP_CONTENT_DIR . '/db.php' );
		$nonce         = wp_create_nonce( 'sqlite-install' );
		$nonce_checked = false;

		$this->assertNotFalse( wp_verify_nonce( $nonce, 'sqlite-install' ) );

		add_action(
			'check_admin_referer',
			function () use ( &$nonce_checked ) {
				$nonce_checked = true;
			}
		);
		$this->set_install_request( $nonce );

		try {
			sqlite_activation();
			$this->fail( 'The install request was not denied.' );
		} catch ( WPDieException $exception ) {
			$this->assertSame( 403, $exception->getCode() );
		}

		$this->assertFalse( $nonce_checked, 'The capability check must run before nonce validation.' );
		$this->assertSame( $dropin_before, file_get_contents( WP_CONTENT_DIR . '/db.php' ) );
	}

	/**
	 * Verify that a multisite super administrator can access the settings page.
	 *
	 * @group ms-required
	 */
	public function test_multisite_super_administrator_can_access_admin_page() {
		$this->set_multisite_user( true );
		$this->reset_admin_menu();

		sqlite_add_admin_menu();

		$this->assertSame( 10, has_action( 'settings_page_sqlite-integration', 'sqlite_integration_admin_screen' ) );
		$this->assert_admin_screen_is_accessible();
	}

	/**
	 * Verify that a multisite super administrator sees the admin-bar item.
	 *
	 * @group ms-required
	 */
	public function test_multisite_super_administrator_sees_admin_bar_item() {
		$this->set_multisite_user( true );
		$admin_bar = new WP_Admin_Bar();

		sqlite_plugin_adminbar_item( $admin_bar );

		$this->assertNotNull( $admin_bar->get_node( 'sqlite-db-integration' ) );
	}

	/**
	 * Verify that a multisite super administrator is redirected after activation.
	 *
	 * @group ms-required
	 */
	public function test_multisite_super_administrator_is_redirected_after_activation() {
		$this->set_multisite_user( true );

		$this->assertSame(
			admin_url( 'options-general.php?page=sqlite-integration' ),
			$this->get_activation_redirect( plugin_basename( SQLITE_MAIN_FILE ) )
		);
	}

	/**
	 * Verify that a multisite super administrator can submit a valid nonce.
	 *
	 * @group ms-required
	 */
	public function test_multisite_super_administrator_with_valid_nonce_reaches_install_redirect() {
		$this->set_multisite_user( true );

		$this->assert_valid_nonce_reaches_install_redirect();
	}

	/**
	 * Verify that a multisite super administrator still needs a valid nonce.
	 *
	 * @group ms-required
	 */
	public function test_multisite_super_administrator_with_invalid_nonce_is_denied() {
		$this->set_multisite_user( true );

		$this->assert_invalid_nonce_is_denied();
	}

	/**
	 * Set the current user on a single-site installation.
	 *
	 * @param string $role User role.
	 */
	private function set_single_site_user( $role ) {
		$user_id = self::factory()->user->create( array( 'role' => $role ) );

		wp_set_current_user( $user_id );
	}

	/**
	 * Set the current user as an administrator of a subsite.
	 *
	 * @param bool $is_super_admin Whether to make the user a super administrator.
	 */
	private function set_multisite_user( $is_super_admin ) {
		$user_id = $is_super_admin ? self::$super_admin_id : self::$subsite_admin_id;

		switch_to_blog( self::$site_id );
		wp_set_current_user( $user_id );

		$this->assertTrue( current_user_can( 'manage_options' ) );
		$this->assertSame( $is_super_admin, current_user_can( 'manage_network_options' ) );
	}

	/**
	 * Reset the globals used to register admin pages.
	 */
	private function reset_admin_menu() {
		$GLOBALS['menu']                 = array();
		$GLOBALS['submenu']              = array();
		$GLOBALS['_wp_submenu_nopriv']   = array();
		$GLOBALS['_registered_pages']    = array();
		$GLOBALS['_parent_pages']        = array();
		$GLOBALS['admin_page_hooks']     = array( 'options-general.php' => 'settings' );
		$GLOBALS['_wp_real_parent_file'] = array();
	}

	/**
	 * Get the redirect triggered by a plugin activation.
	 *
	 * @param string $plugin Plugin basename.
	 * @return string|null Redirect URL, or null when no redirect was attempted.
	 */
	private function get_activation_redirect( $plugin ) {
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

	/**
	 * Assert that the settings page renders for the current user.
	 */
	private function assert_admin_screen_is_accessible() {
		ob_start();
		sqlite_integration_admin_screen();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'SQLite is enabled.', $output );
	}

	/**
	 * Assert that a valid install request reaches the redirect.
	 */
	private function assert_valid_nonce_reaches_install_redirect() {
		$nonce        = wp_create_nonce( 'sqlite-install' );
		$redirect_url = null;

		$this->set_install_request( $nonce );
		add_filter(
			'wp_redirect',
			function ( $location ) use ( &$redirect_url ) {
				$redirect_url = $location;
				throw new RuntimeException( 'Install redirect reached.' );
			}
		);

		try {
			sqlite_activation();
			$this->fail( 'The install request did not redirect.' );
		} catch ( RuntimeException $exception ) {
			$this->assertSame( 'Install redirect reached.', $exception->getMessage() );
		}

		$this->assertSame( admin_url(), $redirect_url );
	}

	/**
	 * Assert that an invalid install nonce is rejected.
	 */
	private function assert_invalid_nonce_is_denied() {
		$this->set_install_request( 'invalid' );

		$this->expectException( WPDieException::class );
		$this->expectExceptionMessage( 'The link you followed has expired.' );
		$this->expectExceptionCode( 403 );

		sqlite_activation();
	}

	/**
	 * Populate an SQLite install request.
	 *
	 * @param string $nonce Install nonce.
	 */
	private function set_install_request( $nonce ) {
		$_GET = array(
			'confirm-install' => '1',
			'_wpnonce'        => $nonce,
		);

		$_REQUEST = $_GET;
	}
}
