<?php

/**
 * Per-AST identity map for native parser node wrappers.
 *
 * The native parser extension constructs a fresh WP_MySQL_Native_Parser_Node
 * every time it returns a child or descendant. Without an identity map, two
 * reads of the same logical node would yield distinct PHP objects, breaking
 * the WP_Parser_Node contract that callers can mutate a child in place and
 * see the mutation again when they walk the tree later.
 *
 * One cache is created lazily on the root node and shared by reference with
 * every wrapper interned through it. Lookup is keyed by the Rust-side
 * `node_index`, which is stable for the lifetime of the AST.
 */
class WP_MySQL_Native_AST_Cache {
	/**
	 * Map of native node index => WP_MySQL_Native_Parser_Node.
	 *
	 * @var array<int, WP_MySQL_Native_Parser_Node>
	 */
	public $nodes = array();
}
