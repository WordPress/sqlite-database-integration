<?php
/**
 * Functions for the admin page of the plugin.
 *
 * @since 1.0.0
 * @package wp-sqlite-integration
 */


/**
 * Add an admin menu page.
 *
 * @since 1.0.0
 */
function sqlite_add_dummy_admin_menu() {
	add_menu_page(
		__( 'SQLite integration', 'sqlite' ),
		__( 'SQLite integration', 'sqlite' ),
		'manage_options',
		'sqlite-integration',
		'sqlite_integration_pre_install_check_screen',
		'',
		3
	);

}
add_action( 'admin_menu', 'sqlite_add_dummy_admin_menu' );

/**
 * Remove the admin menu, leaving the page itself.
 *
 * @since 1.0.0
 */
function sqlite_remove_dummy_admin_menu() {
	remove_menu_page( 'sqlite-integration' );
}
add_action( 'admin_init', 'sqlite_remove_dummy_admin_menu' );

/**
 * The admin page contents.
 */
function sqlite_integration_pre_install_check_screen() {
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'SQLite integration.', 'sqlite' ); ?></h1>
	</div>
	<!-- Set the wrapper width to 50em, to improve readability. -->
	<div style="max-width:50em;">
		<?php if ( ! class_exists( 'SQLite3' ) ) : ?>
			<div class="notice notice-error">
				<p><?php esc_html_e( 'We detected that the SQLite3 class is missing from your server. Please make sure that SQLite is enabled in your PHP installation before proceeding.', 'sqlite' ); ?></p>
			</div>
		<?php elseif ( file_exists( WP_CONTENT_DIR . '/db.php' ) && ! defined( 'SQLITE_DB_DROPIN_VERSION' ) ) : ?>
			<div class="notice notice-error">
				<p>
					<?php
					printf(
						/* translators: %s: db.php drop-in path */
						esc_html__( 'The SQLite plugin cannot be activated because a different %s drop-in already exists.', 'sqlite' ),
						'<code>' . esc_html( basename( WP_CONTENT_DIR ) ) . '/db.php</code>'
					);
					?>
				</p>
			</div>
		<?php elseif ( ! is_writable( WP_CONTENT_DIR ) ) : ?>
			<div class="notice notice-error">
				<p>
					<?php
					printf(
						/* translators: %s: db.php drop-in path */
						esc_html__( 'The SQLite plugin cannot be activated because the %s directory is not writable.', 'sqlite' ),
						'<code>' . esc_html( basename( WP_CONTENT_DIR ) ) . '</code>'
					);
					?>
				</p>
			</div>
		<?php else : ?>
			<div class="notice notice-success">
				<p><?php esc_html_e( 'All checks completed successfully, your site can use an SQLite database. You can proceed with the installation.', 'sqlite' ); ?></p>
			</div>
			<h2><?php esc_html_e( 'Important note', 'sqlite' ); ?></h2>
			<p><?php esc_html_e( 'This plugin will switch to a separate database and install WordPress in it. You will need to reconfigure your site, and start with a fresh site. Disabling the plugin you will get back to your previous MySQL database, with all your previous data intact.', 'sqlite' ); ?></p>
			<p><?php esc_html_e( 'By clicking the button below, you will be redirected to the WordPress installation screen to setup your new database', 'sqlite' ); ?></p>

			<a class="button button-primary" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=sqlite-integration&confirm-install' ), 'sqlite-install' ) ); ?>"><?php esc_html_e( 'Install SQLite database', 'sqlite' ); ?></a>
		<?php endif; ?>
	</div>
	<?php
}
