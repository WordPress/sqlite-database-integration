<?php
/**
 * Tweaks for the health-check screens.
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
	$db_engine = defined( 'DB_ENGINE' ) && 'sqlite' === DB_ENGINE ? 'sqlite' : 'mysql';

	$info['wp-constants']['fields']['DB_ENGINE'] = array(
		'label' => 'DB_ENGINE',
		'value' => ( defined( 'DB_ENGINE' ) ? DB_ENGINE : __( 'Undefined', 'sqlite-database-integration' ) ),
		'debug' => ( defined( 'DB_ENGINE' ) ? DB_ENGINE : 'undefined' ),
	);

	$info['wp-database']['fields']['db_engine'] = array(
		'label' => __( 'Database type', 'sqlite-database-integration' ),
		'value' => 'sqlite' === $db_engine ? 'SQLite' : 'MySQL/MariaDB',
	);

	if ( 'sqlite' === $db_engine ) {
		$info['wp-database']['fields']['database_version'] = array(
			'label' => __( 'SQLite version', 'sqlite-database-integration' ),
			'value' => $info['wp-database']['fields']['server_version'] ?? null,
		);

		$info['wp-database']['fields']['database_file'] = array(
			'label'   => __( 'Database file', 'sqlite-database-integration' ),
			'value'   => DB_PATH,
			'private' => true,
		);

		$database_size = sqlite_plugin_get_database_size( DB_PATH );

		$info['wp-database']['fields']['database_size'] = array(
			'label' => __( 'Database size', 'sqlite-database-integration' ),
			'value' => null === $database_size
				? __( 'Not available', 'sqlite-database-integration' )
				: size_format( $database_size, 1 ),
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
	$db_engine = defined( 'DB_ENGINE' ) && 'sqlite' === DB_ENGINE ? 'sqlite' : 'mysql';

	if ( 'sqlite' === $db_engine ) {
		unset( $tests['direct']['utf8mb4_support'] );
		unset( $tests['direct']['sql_server'] );
		unset( $tests['direct']['persistent_object_cache'] ); // Throws an error because DB_NAME is not defined.

		// Local development sites are not served publicly.
		if (
			'local' !== wp_get_environment_type()
			&& current_user_can( sqlite_plugin_get_manage_capability() )
			&& 'public' === sqlite_plugin_get_database_location_status()
		) {
			$tests['direct']['sqlite_database_location'] = array(
				'label'     => __( 'SQLite database location', 'sqlite-database-integration' ),
				'test'      => 'sqlite_plugin_test_database_location',
				'skip_cron' => true,
			);
		}
	}

	return $tests;
}
add_filter( 'site_status_tests', 'sqlite_plugin_filter_site_status_tests' );

/**
 * Recommend private storage when the database is in a potentially public folder.
 *
 * @access private
 * @return array Site Health result.
 */
function sqlite_plugin_test_database_location() {
	return array(
		'label'       => __( 'Review the SQLite database location', 'sqlite-database-integration' ),
		'status'      => 'recommended',
		'badge'       => array(
			'label' => __( 'Security', 'sqlite-database-integration' ),
			'color' => 'blue',
		),
		'description' => wp_kses_post( wpautop( sqlite_plugin_get_database_location_warning() ) ),
		'actions'     => '',
		'test'        => 'sqlite_database_location',
	);
}
