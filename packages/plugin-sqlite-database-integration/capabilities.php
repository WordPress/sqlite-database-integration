<?php

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
