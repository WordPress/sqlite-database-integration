<?php
/**
 * Plugin Name: WP SQLite DB
 * Description: SQLite database driver drop-in. (based on SQLite Integration by Kojima Toshiyasu)
 * Author: Ari Stathopoulos
 * Version: 1.0.0
 * Requires PHP: 5.6
 * Textdomain: sqlite
 *
 * This project is based on the original work of Kojima Toshiyasu and his SQLite Integration plugin,
 * and the work of Evan Mattson and his WP SQLite DB plugin - See https://github.com/aaemnnosttv/wp-sqlite-db
 */

/**
 * Add the db.php file in wp-content.
 */
function sqlite_plugin_copy_db_file() {
	$destination = WP_CONTENT_DIR . '/db.php';
	if ( ! file_exists( $destination ) ) {
		global $wp_filesystem;
		if ( ! $wp_filesystem ) {
			require_once ABSPATH . '/wp-admin/includes/file.php';
			WP_Filesystem();
		}
		if ( $wp_filesystem->touch( $destination ) ) {
			$file_contents = file_get_contents( __DIR__ . '/db.copy' );
			$file_contents = str_replace( 'DB_PLUGIN_DIR', __DIR__, $file_contents );
			$wp_filesystem->put_contents( $destination, $file_contents );
		}
	}
}

/**
 * Delete the db.php file in wp-content.
 */
function sqlite_plugin_remove_db_file() {
	$destination = WP_CONTENT_DIR . '/db.php';
	if ( file_exists( $destination ) ) {
		global $wp_filesystem;
		if ( ! $wp_filesystem ) {
			require_once ABSPATH . '/wp-admin/includes/file.php';
			WP_Filesystem();
		}
		$wp_filesystem->delete( $destination );
	}
}

// Copy db.php file on plugin activation.
register_activation_hook( __FILE__, 'sqlite_plugin_copy_db_file' );

// Remove db.php file on plugin deactivation.
register_deactivation_hook( __FILE__, 'sqlite_plugin_remove_db_file' );


add_action( 'admin_notices', function() {
	if ( ! file_exists( WP_CONTENT_DIR . '/db.php' ) ) {
		echo '<div class="notice notice-error">';
		echo '<p>' . __( 'The wp-content/db.php file could not be detected. The SQLite integration will not work until that file is successfully copied. To try again automatically, deactivate the SQLite plugin and activate it again.', 'sqlite' ) . '</p>';
		echo '</div>';
	}
} );
