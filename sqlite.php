<?php
/**
 * Plugin Name: SQLite Integration
 * Description: SQLite database driver drop-in. (based on SQLite Integration by Kojima Toshiyasu)
 * Author: Ari Stathopoulos
 * Version: 0.1.0
 * Requires PHP: 5.6
 * Textdomain: sqlite
 *
 * This project is based on the original work of Kojima Toshiyasu and his SQLite Integration plugin,
 * and the work of Evan Mattson and his WP SQLite DB plugin - See https://github.com/aaemnnosttv/wp-sqlite-db
 */

// Temporary - This will be in wp-config.php once SQLite is merged in Core.
if ( ! defined( 'DATABASE_TYPE' ) ) {
	define( 'DATABASE_TYPE', 'sqlite' );
}

define( 'SQLITE_MAIN_FILE', __FILE__ );

/**
 * Add the db.php file in wp-content.
 *
 * When the plugin gets merged in wp-core, this is not to be ported.
 */
function sqlite_plugin_copy_db_file() {
	// Bail early if the SQLite3 class does not exist.
	if ( ! class_exists( 'SQLite3' ) ) {
		return;
	}

	$destination = WP_CONTENT_DIR . '/db.php';

	// Place database drop-in if not present yet, except in case there is
	// another database drop-in present already.
	if ( ! defined( 'SQLITE_DB_DROPIN_VERSION' ) && ! file_exists( $destination ) ) {
		// Init the filesystem to allow copying the file.
		global $wp_filesystem;

		require_once ABSPATH . '/wp-admin/includes/file.php';

		// Init the filesystem if needed, then copy the file, replacing contents as needed.
		if ( ( $wp_filesystem || WP_Filesystem() ) && $wp_filesystem->touch( $destination ) ) {

			// Get the db.copy.php file contents, replace placeholders and write it to the destination.
			$file_contents = str_replace(
				'{SQLITE_IMPLEMENTATION_FOLDER_PATH}',
				__DIR__,
				file_get_contents( __DIR__ . '/db.copy.php' )
			);

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

/**
 * Add admin notices.
 *
 * When the plugin gets merged in wp-core, this is not to be ported.
 */
function sqlite_plugin_admin_notice() {

	// If SQLite is not detected, bail early.
	if ( ! class_exists( 'SQLite3' ) ) {
		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html__( 'The SQLite Integration plugin is active, but the SQLite3 class is missing from your server. Please make sure that SQLite is enabled in your PHP installation.', 'sqlite' )
		);
		return;
	}
	/*
	 * If the SQLITE_DB_DROPIN_VERSION constant is not defined
	 * but there's a db.php file in the wp-content directory, then the module can't be activated.
	 * The module should not have been activated in the first place
	 * (there's a check in the can-load.php file), but this is a fallback check.
	 */
	if ( file_exists( WP_CONTENT_DIR . '/db.php' ) ) {
		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			sprintf(
				/* translators: 1: SQLITE_DB_DROPIN_VERSION constant, 2: db.php drop-in path */
				__( 'The SQLite Integration module is active, but the %1$s constant is missing. It appears you already have another %2$s file present on your site. ', 'sqlite' ),
				'<code>SQLITE_DB_DROPIN_VERSION</code>',
				'<code>' . esc_html( basename( WP_CONTENT_DIR ) ) . '/db.php</code>'
			)
		);

		return;
	}

	// The dropin db.php is missing.
	printf(
		'<div class="notice notice-error"><p>%s</p></div>',
		sprintf(
			/* translators: 1: db.php drop-in path, 2: Admin URL to deactivate the module */
			__( 'The SQLite Integration plugin is active, but the %1$s file is missing. Please <a href="%2$s">deactivate the plugin</a> and re-activate it to try again.', 'sqlite' ),
			'<code>' . esc_html( basename( WP_CONTENT_DIR ) ) . '/db.php</code>',
			esc_url( admin_url( 'plugins.php' ) )
		)
	);

	// Check if the wp-content/db.php file exists.
	if ( ! file_exists( WP_CONTENT_DIR . '/db.php' ) ) {
		if ( ! wp_is_writable( WP_CONTENT_DIR ) ) {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html__( 'The SQLite Integration plugin is active, but the wp-content/db.php file is missing and the wp-content directory is not writable. Please ensure the wp-content folder is writable, then deactivate the plugin and try again.', 'sqlite' )
			);
			return;
		}
		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html__( 'The SQLite Integration plugin is active, but the wp-content/db.php file is missing. Please deactivate the plugin and try again.', 'sqlite' )
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

	$info['wp-constants']['fields']['DATABASE_TYPE'] = array(
		'label' => 'DATABASE_TYPE',
		'value' => ( defined( 'DATABASE_TYPE' ) ? DATABASE_TYPE : __( 'Undefined', 'sqlite' ) ),
		'debug' => ( defined( 'DATABASE_TYPE' ) ? DATABASE_TYPE : 'undefined' ),
	);

	$info['wp-database']['fields']['database_type'] = array(
		'label' => __( 'Database type', 'sqlite' ),
		'value' => 'sqlite' === $database_type ? 'SQLite' : 'MySQL/MariaDB',
	);

	if ( 'sqlite' === $database_type ) {
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
	}

	return $info;
}
add_filter( 'debug_information', 'sqlite_plugin_filter_debug_data' ); // Filter debug data in site-health screen.
