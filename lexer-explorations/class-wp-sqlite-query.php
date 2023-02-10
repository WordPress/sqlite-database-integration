<?php


class WP_SQLite_Query {
	public $sql;
	public $params;

	public function __construct( $sql, $params = array() ) {
		$this->sql    = trim( $sql );
		$this->params = $params;
	}

}
