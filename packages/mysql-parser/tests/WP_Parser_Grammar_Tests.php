<?php

use PHPUnit\Framework\TestCase;

/**
 * Tests for the compacted MySQL parse table and its expansion into a grammar.
 *
 * The runtime expansion in WP_Parser_Grammar must exactly invert the generator's
 * compaction. These tests assert the structural invariants the runtime relies on,
 * so an inconsistent change to the compaction scheme fails fast and locally rather
 * than surfacing as a subtle misparse far downstream.
 */
class WP_Parser_Grammar_Tests extends TestCase {
	/**
	 * The raw compacted parse table, as shipped.
	 *
	 * @var array
	 */
	private static $table;

	public static function setUpBeforeClass(): void {
		self::$table = require WP_MySQL_Parser_Factory::PARSE_TABLE_PATH;
	}

	public function test_compacted_table_is_referentially_consistent(): void {
		$table = self::$table;

		// Every state points at an existing action row.
		foreach ( $table['action_table'] as $state => $row_id ) {
			$this->assertArrayHasKey( $row_id, $table['action_rows'], "state $state row" );
		}

		// Every patch row and its base exist, and the base precedes the row so the
		// runtime's single ascending merge pass expands bases before dependents.
		foreach ( $table['action_row_bases'] as $row_id => $base_id ) {
			$this->assertArrayHasKey( $row_id, $table['action_rows'], "patch row $row_id" );
			$this->assertArrayHasKey( $base_id, $table['action_rows'], "base row $base_id" );
			$this->assertLessThan( $row_id, $base_id, "base $base_id must precede row $row_id" );
		}

		// Every modal-shift token has a per-terminal target to restore from.
		foreach ( $table['action_row_shift_tokens'] as $row_id => $tokens ) {
			$this->assertArrayHasKey( $row_id, $table['action_rows'], "shift row $row_id" );
			foreach ( $tokens as $token ) {
				$this->assertArrayHasKey( $token, $table['action_shift_targets'], "shift target for token $token" );
			}
		}

		// Productions form one gap-free id domain shared by both per-rule maps.
		$production_ids = range( 0, count( $table['production_lhs'] ) - 1 );
		$this->assertSame( $production_ids, array_keys( $table['production_lhs'] ) );
		$this->assertSame( $production_ids, array_keys( $table['production_lengths'] ) );

		// Every left-hand side has a rule name.
		foreach ( array_unique( $table['production_lhs'] ) as $lhs ) {
			$this->assertArrayHasKey( $lhs, $table['rule_names'], "rule name for nonterminal $lhs" );
		}

		// Every GOTO exception is for a nonterminal that also has a default target.
		foreach ( array_keys( $table['goto_table'] ) as $nonterminal ) {
			$this->assertArrayHasKey( $nonterminal, $table['goto_defaults'], "goto default for nonterminal $nonterminal" );
		}
	}

	public function test_expanded_action_and_goto_codes_are_in_range(): void {
		$grammar        = WP_MySQL_Parser_Factory::create_grammar();
		$state_count    = $grammar->state_count;
		$production_lhs = $grammar->production_lhs;

		// Every explicit action cell is a shift, a reduce, or accept.
		$bad_actions = array();
		foreach ( $grammar->action_table as $state => $row ) {
			foreach ( $row as $token => $code ) {
				if ( ! $this->is_valid_action( $code, $state_count, $production_lhs ) ) {
					$bad_actions[] = "state $state token $token => $code";
				}
			}
		}
		$this->assertSame( array(), $bad_actions );

		// Each per-state default is a reduce, accept, or the error code 0.
		$bad_defaults = array();
		foreach ( $grammar->action_defaults as $state => $code ) {
			if ( 0 !== $code && ! $this->is_valid_action( $code, $state_count, $production_lhs ) ) {
				$bad_defaults[] = "state $state default => $code";
			}
		}
		$this->assertSame( array(), $bad_defaults );

		// Every GOTO target, exception or default, is a real state.
		$bad_gotos = array();
		foreach ( $grammar->goto_table as $nonterminal => $by_state ) {
			foreach ( $by_state as $state => $target ) {
				if ( $target < 0 || $target >= $state_count ) {
					$bad_gotos[] = "goto[$nonterminal][$state] => $target";
				}
			}
		}
		foreach ( $grammar->goto_defaults as $nonterminal => $target ) {
			if ( $target < 0 || $target >= $state_count ) {
				$bad_gotos[] = "goto default[$nonterminal] => $target";
			}
		}
		$this->assertSame( array(), $bad_gotos );
	}

	/**
	 * Whether an action code is a valid shift, reduce, or accept.
	 *
	 * @param  int   $code           The action code.
	 * @param  int   $state_count    The total number of states (also the accept code).
	 * @param  array $production_lhs The production => lhs map, used to validate reduces.
	 * @return bool                  True if the code is a valid action.
	 */
	private function is_valid_action( int $code, int $state_count, array $production_lhs ): bool {
		if ( $code < 0 ) {
			return isset( $production_lhs[ -$code ] ); // Reduce by an existing production.
		}
		return $code > 0 && $code <= $state_count; // Shift (< state_count) or accept (== state_count).
	}
}
