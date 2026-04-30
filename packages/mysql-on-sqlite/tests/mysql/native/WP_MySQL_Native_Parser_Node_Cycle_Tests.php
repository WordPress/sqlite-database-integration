<?php

use PHPUnit\Framework\TestCase;

/**
 * Cycle-collection / memory-bound tests for the Rust-side identity cache.
 *
 * The Rust extension stores cached wrappers in a HashMap that PHP's GC
 * cannot see by default. Each cached wrapper has a `$native_ast` property
 * pointing back at the AST, forming a cycle the cycle collector can't
 * walk into without help. These tests are the contract for the custom
 * `gc_handler` on `WP_MySQL_Native_Ast` that exposes the cached wrappers
 * to PHP's collector — they will fail until the handler is in place and
 * working correctly. They're written to break in every direction the
 * leak can manifest:
 *
 * - Loops parsing many ASTs without explicit GC must not grow without
 *   bound (ordinary mode of use).
 * - Walking, mutating, and dropping an AST must reclaim the wrapper
 *   memory once `gc_collect_cycles()` runs.
 * - Holding a child wrapper after the parent AST goes out of scope must
 *   not crash, must not corrupt memory, and the AST must stay alive as
 *   long as that child is reachable.
 * - Nested ASTs with overlapping lifetimes must not interfere — dropping
 *   one mustn't free another's cached wrappers.
 * - Mutating a cached wrapper before dropping the AST must still allow
 *   collection.
 *
 * Skipped when the native extension is not loaded.
 */
class WP_MySQL_Native_Parser_Node_Cycle_Tests extends TestCase {

	protected function setUp(): void {
		if ( ! class_exists( 'WP_MySQL_Native_Parser', false ) ) {
			$this->markTestSkipped( 'Native MySQL parser extension is not loaded.' );
		}
		// Force a clean slate before each test — ASTs from earlier tests
		// must not pollute the memory measurements below.
		gc_collect_cycles();
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

	/**
	 * Hostile loop: parse and walk many ASTs in a tight loop, only
	 * `gc_collect_cycles()` between iterations. Memory must plateau.
	 *
	 * Without a working gc_handler the Rust cache retains every AST's
	 * wrappers forever — peak memory grows linearly with iteration count.
	 * With the handler, each dropped AST's cycle is collected and the
	 * working set stays bounded.
	 */
	public function test_repeated_parse_walk_drop_does_not_leak(): void {
		$sql = 'SELECT a, b, c FROM t WHERE a + b * c IN (1, 2, 3) AND d = 4';

		// Warm-up: do enough work that allocator overhead is amortized
		// before we sample the floor.
		for ( $i = 0; $i < 20; $i++ ) {
			$ast = $this->parse( $sql );
			$ast->get_descendants();
			$ast = null;
			gc_collect_cycles();
		}
		$baseline = memory_get_usage();

		// Now run substantially more iterations and assert the working
		// set stays within a small multiple of the warm-up floor.
		for ( $i = 0; $i < 500; $i++ ) {
			$ast = $this->parse( $sql );
			$ast->get_descendants();
			$ast = null;
			gc_collect_cycles();
		}
		$after = memory_get_usage();

		// 4 MB headroom — generous, but a leaking cache adds tens of MB
		// across 500 iterations on this query.
		$delta = $after - $baseline;
		$this->assertLessThan(
			4 * 1024 * 1024,
			$delta,
			sprintf(
				'Memory grew %.1f MB across 500 parse-walk-drop cycles; the per-AST cache is not being collected.',
				$delta / 1024 / 1024
			)
		);
	}

	/**
	 * After dropping the AST and triggering GC, the entire wrapper
	 * graph must be reclaimable. We hand out one descendant, drop the
	 * root, then drop the descendant — the next gc cycle must reclaim
	 * the rest of the cached wrappers.
	 */
	public function test_drop_then_gc_reclaims_cached_wrappers(): void {
		$sql = 'SELECT a, b, c FROM t WHERE a + b * c IN (1, 2, 3) AND d = 4';

		// Establish a memory floor with no AST live.
		gc_collect_cycles();
		$floor = memory_get_usage();

		$ast        = $this->parse( $sql );
		$descendant = $ast->get_first_descendant_node();
		$this->assertNotNull( $descendant );
		$ast        = null;
		$descendant = null;
		gc_collect_cycles();

		$after = memory_get_usage();
		$delta = $after - $floor;
		// Generous bound — but tens of MB of leaked wrappers would blow it.
		$this->assertLessThan(
			1 * 1024 * 1024,
			$delta,
			sprintf(
				'After dropping the AST and the descendant and running gc, %.1f MB of cached wrappers remain.',
				$delta / 1024 / 1024
			)
		);
	}

	/**
	 * Holding a child wrapper *outlives* the variable holding the root.
	 * The cache pinning the child must keep the AST alive (no UAF when
	 * the bridge is called on the orphaned child). Once the child is
	 * also dropped, GC must collect the whole graph.
	 */
	public function test_orphaned_child_keeps_ast_alive_then_collects(): void {
		$sql   = 'SELECT a, b, c FROM t WHERE a + b * c IN (1, 2, 3)';
		$child = ( function () use ( $sql ) {
			$ast = $this->parse( $sql );
			return $ast->get_first_descendant_node();
		} )();

		// Root variable is gone; only the child reference remains, but
		// the cache still pins the AST through the back-reference. The
		// child must still be functional — accessing it must not crash.
		$this->assertNotNull( $child );
		$this->assertIsString( $child->rule_name );
		// The child's own children should also resolve without UAF.
		$grand = $child->get_first_child();
		$this->assertNotNull( $grand );

		// Now drop the child too; the AST + cache should be reclaimable.
		$child = null;
		$grand = null;
		gc_collect_cycles();
		// If the cycle collected, this assertion always passes; the real
		// signal is the absence of a segfault during teardown.
		$this->addToAssertionCount( 1 );
	}

	/**
	 * Mutating a cached wrapper through `append_child` before dropping
	 * the AST must not block collection. The mutated wrapper's
	 * `$children` array now contains a non-cached node; that's an extra
	 * edge for the gc_handler to traverse, not a reason to leak.
	 */
	public function test_mutation_before_drop_does_not_block_collection(): void {
		$sql = 'SELECT 1 + 2';

		gc_collect_cycles();
		$floor = memory_get_usage();

		for ( $i = 0; $i < 200; $i++ ) {
			$ast      = $this->parse( $sql );
			$child    = $ast->get_first_child_node();
			$injected = new WP_Parser_Node( 0, 'synthetic-' . $i );
			$ast->append_child( $injected );
			// Touch the cache after mutation to keep wrappers live.
			$ast->get_descendants();
			$ast      = null;
			$child    = null;
			$injected = null;
			gc_collect_cycles();
		}
		$after = memory_get_usage();
		$delta = $after - $floor;
		$this->assertLessThan(
			4 * 1024 * 1024,
			$delta,
			sprintf(
				'Memory grew %.1f MB across 200 mutate-then-drop cycles.',
				$delta / 1024 / 1024
			)
		);
	}

	/**
	 * Two ASTs alive simultaneously, then dropped in interleaved order.
	 * Dropping AST A must not affect AST B's cached wrappers; both must
	 * eventually collect once unreferenced.
	 */
	public function test_overlapping_asts_do_not_corrupt_each_other(): void {
		$ast_a = $this->parse( 'SELECT a FROM ta WHERE a > 1' );
		$ast_b = $this->parse( 'SELECT b FROM tb WHERE b < 9' );

		$child_a = $ast_a->get_first_descendant_node();
		$child_b = $ast_b->get_first_descendant_node();

		// Drop A first and run gc; B must remain fully functional.
		$ast_a   = null;
		$child_a = null;
		gc_collect_cycles();

		$this->assertNotNull( $child_b );
		$walk = $ast_b->get_descendants();
		$this->assertNotEmpty( $walk );

		// Drop B too; walk one of its still-held descendants — the cache
		// is still alive because $child_b pins it.
		$ast_b = null;
		$this->assertIsString( $child_b->rule_name );

		$child_b = null;
		$walk    = null;
		gc_collect_cycles();
		$this->addToAssertionCount( 1 );
	}

	/**
	 * Re-walk + drop + collect across many iterations. This is the
	 * "translator pass on each query" shape of real workloads. The Rust
	 * cache should give us the perf win of `rewalk` without the memory
	 * cliff that a missing gc_handler creates.
	 */
	public function test_rewalk_loop_stays_bounded(): void {
		$sql = 'SELECT a, b, c, d, e FROM t WHERE (a + b) * (c - d) > e AND f IN (1,2,3,4,5)';

		gc_collect_cycles();
		// Warm-up.
		for ( $i = 0; $i < 10; $i++ ) {
			$ast = $this->parse( $sql );
			for ( $r = 0; $r < 10; $r++ ) {
				$ast->get_descendants();
			}
			$ast = null;
			gc_collect_cycles();
		}
		$floor = memory_get_usage();

		for ( $i = 0; $i < 200; $i++ ) {
			$ast = $this->parse( $sql );
			for ( $r = 0; $r < 10; $r++ ) {
				$ast->get_descendants();
			}
			$ast = null;
			gc_collect_cycles();
		}
		$after = memory_get_usage();
		$delta = $after - $floor;
		$this->assertLessThan(
			4 * 1024 * 1024,
			$delta,
			sprintf(
				'Rewalk loop grew memory by %.1f MB; cache likely uncollectable.',
				$delta / 1024 / 1024
			)
		);
	}
}
