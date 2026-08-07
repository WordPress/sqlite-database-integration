<?php

class WPDB_Stub {
	public function add_placeholder_escape( $query ) {
		return $query;
	}

	public function db_version() {
		return preg_replace( '/[^0-9.].*/', '', $this->db_server_info() );
	}
}

class_alias( WPDB_Stub::class, 'wpdb' );
