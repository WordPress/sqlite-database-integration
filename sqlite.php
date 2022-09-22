<?php
/**
 * Plugin Name: SQLite Integration
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
 *
 * When the plugin gets merged in wp-core, this is not to be ported.
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
register_activation_hook( __FILE__, 'sqlite_plugin_copy_db_file' ); // Copy db.php file on plugin activation.

/**
 * Delete the db.php file in wp-content.
 *
 * When the plugin gets merged in wp-core, this is not to be ported.
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
register_deactivation_hook( __FILE__, 'sqlite_plugin_remove_db_file' ); // Remove db.php file on plugin deactivation.

/**
 * Add admin notices.
 *
 * When the plugin gets merged in wp-core, this is not to be ported.
 */
function sqlite_plugin_admin_notice() {
	// Check if the wp-content/db.php file exists.
	if ( ! file_exists( WP_CONTENT_DIR . '/db.php' ) ) {
		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html__( 'The SQLite Integration plugin is active, but the wp-content/db.php file is missing. Please deactivate the plugin and try again.', 'sqlite' )
		);
	}

	// Check if the DATABASE_TYPE constant is set.
	if ( ! defined( 'DATABASE_TYPE' ) || 'sqlite' !== DATABASE_TYPE ) {
		printf(
			/* translators: %1$s: DATABASE_TYPE, %2$s: sqlite, %3$s: wp-config.php, %4$s: hat\'s all, stop editing! Happy blogging. */
			'<div class="notice notice-error"><p>' . __( 'The %1$s constant is not defined or is not set to %2$s. The SQLite integration will not work until that constant is defined. Please edit your %3$s file and add %4$s above the line that says %5$s.', 'sqlite' ) . '</p></div>',
			'<code>DATABASE_TYPE</code>',
			'<code>sqlite</code>',
			'<code>wp-config.php</code>',
			'<code>define( \'DATABASE_TYPE\', \'sqlite\' );</code>',
			'<code>/* That\'s all, stop editing! Happy blogging. */</code>'
		);
	}
}
add_action( 'admin_notices', 'sqlite_plugin_admin_notice' ); // Add the admin notices.

/**
 * Filter debug data in site-health screen.
 *
 * When the plugin gets merged in wp-core, these should be merged in src/wp-admin/includes/class-wp-debug-data.php
 * See https://github.com/WordPress/wordpress-develop/pull/3220/files
 *
 * @param array $info The debug data.
 */
function sqlite_plugin_filter_debug_data( $info ) {
	$database_type = defined( 'DATABASE_TYPE' ) && 'sqlite' === DATABASE_TYPE ? 'sqlite' : 'mysql';
	if ( 'sqlite' !== $database_type ) {
		return $info;
	}

	$info['wp-constants']['fields']['DATABASE_TYPE'] = array(
		'label' => 'DATABASE_TYPE',
		'value' => ( defined( 'DATABASE_TYPE' ) ? DATABASE_TYPE : __( 'Undefined', 'sqlite' ) ),
		'debug' => ( defined( 'DATABASE_TYPE' ) ? DATABASE_TYPE : 'undefined' ),
	);

	$info['wp-database']['fields']['database_type'] = array(
		'label' => __( 'Database type', 'sqlite' ),
		'value' => 'sqlite' === $database_type ? 'SQLite' : 'MySQL/MariaDB',
	);

	$info['wp-database']['fields']['database_version'] = array(
		'label' => __( 'SQLite version', 'sqlite' ),
		'value' => class_exists( 'SQLite3' ) ? SQLite3::version()['versionString'] : null,
	);

	$info['wp-database']['fields']['database_file'] = array(
		'label'   => __( 'Database file', 'sqlite' ),
		'value'   => FQDB,
		'private' => true,
	);

	$info['wp-database']['fields']['database_size'] = array(
		'label' => __( 'Database size', 'sqlite' ),
		'value' => size_format( filesize( FQDB ) ),
	);

	unset( $info['wp-database']['fields']['extension'] );
	unset( $info['wp-database']['fields']['server_version'] );
	unset( $info['wp-database']['fields']['client_version'] );
	unset( $info['wp-database']['fields']['database_host'] );
	unset( $info['wp-database']['fields']['database_user'] );
	unset( $info['wp-database']['fields']['database_name'] );
	unset( $info['wp-database']['fields']['database_charset'] );
	unset( $info['wp-database']['fields']['database_collate'] );
	unset( $info['wp-database']['fields']['max_allowed_packet'] );
	unset( $info['wp-database']['fields']['max_connections'] );

	return $info;
}
add_filter( 'debug_information', 'sqlite_plugin_filter_debug_data' ); // Filter debug data in site-health screen.
