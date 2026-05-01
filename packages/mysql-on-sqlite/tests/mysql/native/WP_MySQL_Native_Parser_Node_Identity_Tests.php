<?php

use PHPUnit\Framework\TestCase;

/**
 * Regression tests for the per-AST identity map on native parser nodes.
 *
 * The native extension constructs a fresh PHP wrapper for every accessor
 * call. Without interning, two reads of the same logical node would yield
 * distinct objects, and a mutation made through the first wrapper would
 * be invisible through the second. WP_Parser_Node exposes public mutators
 * and stable child identity, so the native wrapper must preserve both.
 *
 * Skipped when the native extension is not loaded — the pure-PHP code
 * path already has stable identity by construction.
 */
class WP_MySQL_Native_Parser_Node_Identity_Tests extends TestCase {

	protected function setUp(): void {
		if ( ! class_exists( 'WP_MySQL_Native_Parser', false ) ) {
			$this->markTestSkipped( 'Native MySQL parser extension is not loaded.' );
		}
	}

	private function parse( string $sql ): WP_Parser_Node {
		static $grammar = null;
		if ( null === $grammar ) {
			$grammar = new WP_Parser_Grammar( include __DIR__ . '/../../../src/mysql/mysql-grammar.php' );
		}
		$lexer  = new WP_MySQL_Lexer( $sql );
		$tokens = $lexer instanceof WP_MySQL_Native_Lexer
			? $lexer->native_token_stream()
			: $lexer->remaining_tokens();
		$parser = new WP_MySQL_Parser( $grammar, $tokens );
		$tree   = $parser->parse();
		$this->assertNotNull( $tree, 'Failed to parse SQL: ' . $sql );
		return $tree;
	}

	public function test_get_first_child_node_returns_same_instance(): void {
		$tree = $this->parse( 'SELECT 1 + 2' );

		$first  = $tree->get_first_child_node();
		$second = $tree->get_first_child_node();

		$this->assertNotNull( $first );
		$this->assertSame( $first, $second );
	}

	public function test_get_children_returns_same_instances_across_calls(): void {
		$tree = $this->parse( 'SELECT 1, 2, 3' );

		$first_pass  = $tree->get_children();
		$second_pass = $tree->get_children();

		$this->assertSameSize( $first_pass, $second_pass );
		foreach ( $first_pass as $i => $child ) {
			if ( $child instanceof WP_Parser_Node ) {
				$this->assertSame( $child, $second_pass[ $i ] );
			}
		}
	}

	public function test_descendant_lookup_shares_identity_with_child_lookup(): void {
		$tree = $this->parse( 'SELECT 1 + 2' );

		$descendant = $tree->get_first_descendant_node();
		$this->assertNotNull( $descendant );

		// Walk down to the same node via direct children. We don't know the
		// exact depth, so we descend until we hit the descendant we found.
		$cursor = $tree;
		while ( null !== $cursor && $cursor !== $descendant ) {
			$next = $cursor->get_first_child_node();
			if ( $next === $cursor ) {
				break;
			}
			$cursor = $next;
		}

		$this->assertSame( $descendant, $cursor, 'Descendant and child lookups must return the same wrapper instance.' );
	}

	public function test_mutation_on_child_survives_re_read(): void {
		$tree = $this->parse( 'SELECT 1 + 2' );

		$child = $tree->get_first_child_node();
		$this->assertNotNull( $child );

		// Mutate via the public WP_Parser_Node API — this is exactly the
		// kind of state the reviewer worried would be lost when accessors
		// hand back fresh wrappers. rule_name is a declared public property
		// that the parser itself sets, so PHP 8.2's dynamic-property
		// deprecation does not apply here.
		$child->rule_name = 'mutated-rule';

		$same_child = $tree->get_first_child_node();
		$this->assertSame( $child, $same_child );
		$this->assertSame( 'mutated-rule', $same_child->rule_name );
	}

	public function test_mutation_survives_parent_materialization(): void {
		$tree = $this->parse( 'SELECT 1 + 2' );

		$child = $tree->get_first_child_node();
		$this->assertNotNull( $child );
		$child->rule_name = 'before-materialize';

		// Force the parent to materialize its native children by appending
		// a sibling. After this, the parent walks $this->children directly.
		$sibling = new WP_Parser_Node( 0, 'synthetic' );
		$tree->append_child( $sibling );

		$children = $tree->get_children();
		$this->assertContains( $child, $children, 'Materialized children must include the previously-mutated wrapper.' );
		$this->assertSame( 'before-materialize', $child->rule_name );
	}
}
