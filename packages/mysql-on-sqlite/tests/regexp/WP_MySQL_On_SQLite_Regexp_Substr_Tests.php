<?php

require_once __DIR__ . '/WP_MySQL_On_SQLite_Regexp_Test_Case.php';

/**
 * Check REGEXP_SUBSTR() against MySQL's results for the REGEXP test cases.
 */
class WP_MySQL_On_SQLite_Regexp_Substr_Tests extends WP_MySQL_On_SQLite_Regexp_Test_Case {
	const CHECKS = array( 'regexp_substr' );

	protected function setUp(): void {
		$this->markTestSkipped( 'REGEXP_SUBSTR() is not supported yet.' );
	}
}
