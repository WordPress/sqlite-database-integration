<?php
/**
 * Tweaks for the health-check screens.
 *
 * @since 1.0.0
 * @package wp-sqlite-integration
 */

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
		'value' => ( defined( 'DATABASE_TYPE' ) ? DATABASE_TYPE : __( 'Undefined', 'sqlite-database-integration' ) ),
		'debug' => ( defined( 'DATABASE_TYPE' ) ? DATABASE_TYPE : 'undefined' ),
	);

	$info['wp-database']['fields']['database_type'] = array(
		'label' => __( 'Database type', 'sqlite-database-integration' ),
		'value' => 'sqlite' === $database_type ? 'SQLite' : 'MySQL/MariaDB',
	);

	if ( 'sqlite' === $database_type ) {
		$info['wp-database']['fields']['database_version'] = array(
			'label' => __( 'SQLite version', 'sqlite-database-integration' ),
			'value' => class_exists( 'SQLite3' ) ? SQLite3::version()['versionString'] : null,
		);

		$info['wp-database']['fields']['database_file'] = array(
			'label'   => __( 'Database file', 'sqlite-database-integration' ),
			'value'   => FQDB,
			'private' => true,
		);

		$info['wp-database']['fields']['database_size'] = array(
			'label' => __( 'Database size', 'sqlite-database-integration' ),
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

/**
 * Filter site_status tests in site-health screen.
 *
 * When the plugin gets merged in wp-core, these should be merged in src/wp-admin/includes/class-wp-site-health.php
 *
 * @param array $tests The tests.
 * @return array
 */
function sqlite_plugin_filter_site_status_tests( $tests ) {
	$database_type = defined( 'DATABASE_TYPE' ) && 'sqlite' === DATABASE_TYPE ? 'sqlite' : 'mysql';

	if ( 'sqlite' === $database_type ) {
		unset( $tests['direct']['utf8mb4_support'] );
		unset( $tests['direct']['sql_server'] );
	}

	return $tests;
}
add_filter( 'site_status_tests', 'sqlite_plugin_filter_site_status_tests' );
