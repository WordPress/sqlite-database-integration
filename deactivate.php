<?php
/**
 * Handle the SQLite deactivation.
 *
 * @since 1.0.0
 * @package wp-sqlite-integration
 */

/**
 * Delete the db.php file in wp-content.
 *
 * When the plugin gets merged in wp-core, this is not to be ported.
 */
function sqlite_plugin_remove_db_file() {
	if ( ! defined( 'SQLITE_DB_DROPIN_VERSION' ) || ! file_exists( WP_CONTENT_DIR . '/db.php' ) ) {
		return;
	}

	global $wp_filesystem;

	require_once ABSPATH . '/wp-admin/includes/file.php';

	// Init the filesystem if needed, then delete custom drop-in.
	if ( $wp_filesystem || WP_Filesystem() ) {
		$wp_filesystem->delete( WP_CONTENT_DIR . '/db.php' );
	}
}
register_deactivation_hook( __FILE__, 'sqlite_plugin_remove_db_file' ); // Remove db.php file on plugin deactivation.
