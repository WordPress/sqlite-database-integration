<?php
/**
 * Plugin Name: WP SQLite DB
 * Description: SQLite database driver drop-in. (based on SQLite Integration by Kojima Toshiyasu)
 * Author: Ari Stathopoulos
 * Version: 1.0.0
 * Requires PHP: 5.6
 *
 * This project is based on the original work of Kojima Toshiyasu and his SQLite Integration plugin,
 * and the work of Evan Mattson and his WP SQLite DB plugin - See https://github.com/aaemnnosttv/wp-sqlite-db
 */

// Add the db.php file in wp-content.
register_activation_hook( __FILE__, function() {
	$db_file_path = WP_CONTENT_DIR . '/db.php';
	if ( ! file_exists( $db_file_path ) ) {
		global $wp_filesystem;
		if ( ! $wp_filesystem ) {
			require_once ABSPATH . '/wp-admin/includes/file.php';
			WP_Filesystem();
		}
		if ( $wp_filesystem->touch( $db_file_path ) ) {
			$file_contents = file_get_contents( __DIR__ . '/db.copy' );
			$file_contents = str_replace( 'DB_PLUGIN_DIR', __DIR__, $file_contents );
			$wp_filesystem->put_contents( $db_file_path, $file_contents );
		}
	}
} );
