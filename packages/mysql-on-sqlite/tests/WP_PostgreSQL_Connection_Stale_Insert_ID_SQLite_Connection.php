<?php
/**
 * SQLite-backed connection fixture with a stale last insert ID.
 */
class WP_PostgreSQL_Connection_Stale_Insert_ID_SQLite_Connection extends WP_PostgreSQL_Connection {
	/**
	 * Create a connection backed by an in-memory SQLite database.
	 */
	public function __construct() {
		parent::__construct( array( 'pdo' => new PDO( 'sqlite::memory:' ) ) );
	}

	/**
	 * Return a stale insert ID to verify driver-level MySQL compatibility.
	 *
	 * @param string|null $sequence Optional sequence name.
	 * @return string
	 */
	public function get_last_insert_id( ?string $sequence = null ): string {
		return '29';
	}
}
