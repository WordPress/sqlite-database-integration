<?php

/**
 * SQLite-backed connection fixture that exercises PostgreSQL quote translation.
 */
class WP_PostgreSQL_Connection_Pgsql_Quote_SQLite_Connection extends WP_PostgreSQL_Connection {
	/**
	 * Report PostgreSQL for quote() while keeping the real PDO available.
	 *
	 * @return string PDO driver name.
	 */
	public function get_driver_name(): string {
		return 'pgsql';
	}
}
