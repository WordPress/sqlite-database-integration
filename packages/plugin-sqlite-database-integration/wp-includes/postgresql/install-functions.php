<?php
/**
 * PostgreSQL install hooks.
 *
 * @package wp-sqlite-integration
 */

if ( ! function_exists( 'postgresql_make_db_current_silent' ) ) {
	/**
	 * Placeholder for PostgreSQL schema installation.
	 *
	 * @return bool False until the PostgreSQL backend can translate WordPress
	 *              install DDL into PostgreSQL.
	 */
	function postgresql_make_db_current_silent() {
		return false;
	}
}
