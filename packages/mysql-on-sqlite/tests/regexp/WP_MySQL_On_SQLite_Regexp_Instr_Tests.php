<?php

require_once __DIR__ . '/WP_MySQL_On_SQLite_Regexp_Test_Case.php';

/**
 * Check REGEXP_INSTR() against MySQL's results for the REGEXP test cases.
 */
class WP_MySQL_On_SQLite_Regexp_Instr_Tests extends WP_MySQL_On_SQLite_Regexp_Test_Case {
	const CHECKS = array( 'regexp_instr', 'regexp_instr_end' );

	protected function setUp(): void {
		$this->markTestSkipped( 'REGEXP_INSTR() is not supported yet.' );
	}
}
