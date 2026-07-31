<?php

/**
 * Require SQLite management permission to deactivate an active integration.
 *
 * WordPress checks this capability when one plugin is deactivated, including
 * each plugin in a site-level batch. It skips this per-plugin check for a
 * Network Admin batch, so that case is handled separately below.
 *
 * @access private
 *
 * @param string[] $caps    Primitive capabilities required of the user.
 * @param string   $cap     Capability being checked.
 * @param int      $user_id User ID.
 * @param mixed[]  $args    Additional capability arguments.
 * @return string[] Required primitive capabilities.
 */
function sqlite_plugin_map_deactivation_capability( $caps, $cap, $user_id, $args ) {
	if (
		'deactivate_plugin' === $cap
		&& isset( $args[0] )
		&& plugin_basename( SQLITE_MAIN_FILE ) === $args[0]
		&& sqlite_plugin_has_active_dropin()
	) {
		$caps[] = sqlite_plugin_get_manage_capability();
	}
	return $caps;
}
add_filter( 'map_meta_cap', 'sqlite_plugin_map_deactivation_capability', 10, 4 );

/**
 * Check whether the SQLite database drop-in is active.
 *
 * @access private
 *
 * @return bool Whether the SQLite database drop-in is active.
 */
function sqlite_plugin_has_active_dropin() {
	return defined( 'SQLITE_DB_DROPIN_VERSION' ) && file_exists( WP_CONTENT_DIR . '/db.php' );
}

/**
 * Get the capability required to manage the SQLite integration.
 *
 * @access private
 *
 * @return string Required capability.
 */
function sqlite_plugin_get_manage_capability() {
	return is_multisite() ? 'manage_network_options' : 'manage_options';
}
