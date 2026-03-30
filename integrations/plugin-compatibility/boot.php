<?php
/**
 * Plugin compatibility layer.
 *
 * Provides string-level translation fallbacks for complex third-party plugin queries
 * that are incompatible with the pure AST SQLite evaluator (e.g. Action Scheduler).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Filter SQL queries early to fix plugin compatibility issues.
 *
 * @param string $query The SQL query.
 * @return string Modified query.
 */
function wp_sqlite_integration_plugin_compat( $query ) {
	if ( ! is_string( $query ) ) {
		return $query;
	}

	// 1. Heavy cleaning of unsupported MySQL locking clauses.
	// SQLite doesn't support FOR UPDATE, SKIP LOCKED, or NOWAIT anywhere.
	// We strip these globally (case-insensitive, multi-line) to prevent syntax errors in subqueries.
	if ( stripos( $query, 'FOR UPDATE' ) !== false ) {
		$query = preg_replace( '/\s+FOR\s+UPDATE(?:\s+(?:SKIP\s+LOCKED|NOWAIT))?\b/is', '', $query );
	}

	// 2. Action Scheduler specific compatibility fixes.
	if ( stripos( $query, 'actionscheduler' ) !== false ) {
		// Escape the 'group' keyword safely.
		// Action Scheduler sometimes queries an unquoted `group` column.
		$query = preg_replace( '/(?<![\'"`])\bgroup\b(?!\s+by)(?![\'"`])/i', '`group`', $query );

		// Fix 'INSERT wp_actionscheduler...' syntax to include 'INTO'.
		$query = preg_replace( '/INSERT\s+(?!INTO\s+)(wp_actionscheduler_[a-zA-Z0-9_]+)/i', 'INSERT INTO $1', $query );

		// Fix 'UPDATE ... JOIN' syntax manually.
		// Handles variants like "UPDATE table t1 JOIN" and "UPDATE table AS t1 JOIN".
		$pattern = '/UPDATE\s+([^\s]+)\s+(?:AS\s+)?t1\s+JOIN\s*\((.*?)\)\s*(?:AS\s+)?t2\s*ON\s*t1\.action_id\s*=\s*t2\.action_id\s*SET\s*(.*)/is';
		if ( preg_match( $pattern, $query, $matches ) ) {
			$set_clause = str_ireplace( 't1.', '', $matches[3] );
			// Extract the SELECT logic from the join to use in an IN clause.
			$query = "UPDATE {$matches[1]} SET {$set_clause} WHERE action_id IN ({$matches[2]})";
		}
	}

	return $query;
}

if ( function_exists( 'add_filter' ) ) {
	// Execute the compatibility fixes at priority 0 (before other generic manipulations).
	add_filter( 'query', 'wp_sqlite_integration_plugin_compat', 0 );
}
