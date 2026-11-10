<?php

require_once __DIR__ . '/WP_MySQL_On_SQLite_Regexp_Test_Case.php';

/**
 * Check REGEXP_REPLACE() against MySQL's results for the REGEXP test cases.
 */
class WP_MySQL_On_SQLite_Regexp_Replace_Tests extends WP_MySQL_On_SQLite_Regexp_Test_Case {
	const CHECKS = array( 'regexp_replace' );

	protected function setUp(): void {
		$this->markTestSkipped( 'REGEXP_REPLACE() is not supported yet.' );
	}
}
