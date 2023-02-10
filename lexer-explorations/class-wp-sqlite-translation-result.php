<?php

class WP_SQLite_Translation_Result {
	public $queries         = array();
	public $has_result      = false;
	public $result          = null;
	public $calc_found_rows = null;
	public $query_type      = null;

	public function __construct(
		$queries,
		$has_result = false,
		$result = null
	) {
		$this->queries    = $queries;
		$this->has_result = $has_result;
		$this->result     = $result;
	}
}
