<?php
/**
 * Plugin Name: SQLite integration (Drop-in)
 * Version: 1.0.0
 * Author: WordPress Performance Team
 * Author URI: https://make.wordpress.org/performance/
 *
 * This file is auto-generated and copied from the sqlite plugin.
 * Please don't edit this file directly.
 *
 * @package wp-sqlite-integration
 */

define( 'SQLITE_DB_DROPIN_VERSION', '1.8.0' );

// Bail early if the SQLite implementation was not located in the plugin.
if ( ! file_exists( '{SQLITE_IMPLEMENTATION_FOLDER_PATH}/wp-includes/sqlite/db.php' ) ) {
	return;
}

// Define SQLite constant.
if ( ! defined( 'DATABASE_TYPE' ) ) {
	define( 'DATABASE_TYPE', 'sqlite' );
}

// Require the implementation from the plugin.
require_once '{SQLITE_IMPLEMENTATION_FOLDER_PATH}/wp-includes/sqlite/db.php';
