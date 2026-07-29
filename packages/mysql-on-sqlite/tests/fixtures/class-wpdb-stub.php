<?php

class WPDB_Stub {
	public function add_placeholder_escape( $query ) {
		return $query;
	}
}

class_alias( WPDB_Stub::class, 'wpdb' );
