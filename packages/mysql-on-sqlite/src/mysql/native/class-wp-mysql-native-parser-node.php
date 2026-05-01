<?php

/**
 * Parser node backed by a native (Rust) AST.
 *
 * Constructed by the native MySQL parser extension. Read methods delegate
 * into the Rust-owned AST so children are never copied into PHP unless a
 * caller actually walks the tree. On the first mutation (append_child or
 * merge_fragment), the node materializes its children into the inherited
 * `$children` array and behaves like a plain WP_Parser_Node from then on.
 *
 * Wrappers returned by accessors are interned through a per-AST identity
 * map (WP_MySQL_Native_AST_Cache) so two reads of the same logical node
 * yield the same PHP instance. This preserves the WP_Parser_Node contract
 * that mutations performed on a child via `get_first_child_node()` remain
 * visible when the same child is reached again, including after the parent
 * has materialized.
 */
class WP_MySQL_Native_Parser_Node extends WP_Parser_Node {
	private $native_ast        = null;
	private $native_node_index = null;
	private $was_mutated       = false;

	/**
	 * Per-AST identity map shared between every interned wrapper.
	 *
	 * Created lazily on the first child access; the root wrapper is the
	 * first entry. Children inherit the same cache instance by reference.
	 *
	 * @var WP_MySQL_Native_AST_Cache|null
	 */
	private $cache = null;

	public function __construct( $rule_id, $rule_name, $native_ast = null, $native_node_index = null ) {
		parent::__construct( $rule_id, $rule_name );

		$this->native_ast        = $native_ast;
		$this->native_node_index = $native_node_index;
	}

	/**
	 * Native node index in the Rust-owned arena.
	 *
	 * Exposed so the identity cache can key on it. Returns null after
	 * the wrapper has materialized — at that point the node is detached
	 * from the native arena and behaves like a plain WP_Parser_Node.
	 *
	 * @return int|null
	 */
	public function get_native_node_index(): ?int {
		return $this->native_node_index;
	}

	/** @inheritDoc */
	public function append_child( $node ) {
		$this->materialize_native_children();
		parent::append_child( $node );
	}

	/** @inheritDoc */
	public function merge_fragment( $node ) {
		$this->materialize_native_children();
		if ( $node instanceof self ) {
			$node->materialize_native_children();
		}
		parent::merge_fragment( $node );
	}

	/** @inheritDoc */
	public function has_child(): bool {
		if ( $this->was_mutated() ) {
			return parent::has_child();
		}
		return wp_sqlite_mysql_native_ast_has_child( $this->native_ast, $this->native_node_index );
	}

	/** @inheritDoc */
	public function has_child_node( ?string $rule_name = null ): bool {
		if ( $this->was_mutated() ) {
			return parent::has_child_node( $rule_name );
		}
		return wp_sqlite_mysql_native_ast_has_child_node( $this->native_ast, $this->native_node_index, $rule_name );
	}

	/** @inheritDoc */
	public function has_child_token( ?int $token_id = null ): bool {
		if ( $this->was_mutated() ) {
			return parent::has_child_token( $token_id );
		}
		return wp_sqlite_mysql_native_ast_has_child_token( $this->native_ast, $this->native_node_index, $token_id );
	}

	/** @inheritDoc */
	public function get_first_child() {
		if ( $this->was_mutated() ) {
			return parent::get_first_child();
		}
		return $this->intern( wp_sqlite_mysql_native_ast_get_first_child( $this->native_ast, $this->native_node_index ) );
	}

	/** @inheritDoc */
	public function get_first_child_node( ?string $rule_name = null ): ?WP_Parser_Node {
		if ( $this->was_mutated() ) {
			return parent::get_first_child_node( $rule_name );
		}
		return $this->intern( wp_sqlite_mysql_native_ast_get_first_child_node( $this->native_ast, $this->native_node_index, $rule_name ) );
	}

	/** @inheritDoc */
	public function get_first_child_token( ?int $token_id = null ): ?WP_Parser_Token {
		if ( $this->was_mutated() ) {
			return parent::get_first_child_token( $token_id );
		}
		return wp_sqlite_mysql_native_ast_get_first_child_token( $this->native_ast, $this->native_node_index, $token_id );
	}

	/** @inheritDoc */
	public function get_first_descendant_node( ?string $rule_name = null ): ?WP_Parser_Node {
		if ( $this->was_mutated() ) {
			return parent::get_first_descendant_node( $rule_name );
		}
		return $this->intern( wp_sqlite_mysql_native_ast_get_first_descendant_node( $this->native_ast, $this->native_node_index, $rule_name ) );
	}

	/** @inheritDoc */
	public function get_first_descendant_token( ?int $token_id = null ): ?WP_Parser_Token {
		if ( $this->was_mutated() ) {
			return parent::get_first_descendant_token( $token_id );
		}
		return wp_sqlite_mysql_native_ast_get_first_descendant_token( $this->native_ast, $this->native_node_index, $token_id );
	}

	/** @inheritDoc */
	public function get_children(): array {
		if ( $this->was_mutated() ) {
			return parent::get_children();
		}
		return $this->intern_all( wp_sqlite_mysql_native_ast_get_children( $this->native_ast, $this->native_node_index ) );
	}

	/** @inheritDoc */
	public function get_child_nodes( ?string $rule_name = null ): array {
		if ( $this->was_mutated() ) {
			return parent::get_child_nodes( $rule_name );
		}
		return $this->intern_nodes( wp_sqlite_mysql_native_ast_get_child_nodes( $this->native_ast, $this->native_node_index, $rule_name ) );
	}

	/** @inheritDoc */
	public function get_child_tokens( ?int $token_id = null ): array {
		if ( $this->was_mutated() ) {
			return parent::get_child_tokens( $token_id );
		}
		return wp_sqlite_mysql_native_ast_get_child_tokens( $this->native_ast, $this->native_node_index, $token_id );
	}

	/** @inheritDoc */
	public function get_descendants(): array {
		if ( $this->was_mutated() ) {
			return parent::get_descendants();
		}
		return $this->intern_all( wp_sqlite_mysql_native_ast_get_descendants( $this->native_ast, $this->native_node_index ) );
	}

	/** @inheritDoc */
	public function get_descendant_nodes( ?string $rule_name = null ): array {
		if ( $this->was_mutated() ) {
			return parent::get_descendant_nodes( $rule_name );
		}
		return $this->intern_nodes( wp_sqlite_mysql_native_ast_get_descendant_nodes( $this->native_ast, $this->native_node_index, $rule_name ) );
	}

	/** @inheritDoc */
	public function get_descendant_tokens( ?int $token_id = null ): array {
		if ( $this->was_mutated() ) {
			return parent::get_descendant_tokens( $token_id );
		}
		return wp_sqlite_mysql_native_ast_get_descendant_tokens( $this->native_ast, $this->native_node_index, $token_id );
	}

	/** @inheritDoc */
	public function get_start(): int {
		if ( $this->was_mutated() ) {
			return parent::get_start();
		}
		return wp_sqlite_mysql_native_ast_get_start( $this->native_ast, $this->native_node_index );
	}

	/** @inheritDoc */
	public function get_length(): int {
		if ( $this->was_mutated() ) {
			return parent::get_length();
		}
		return wp_sqlite_mysql_native_ast_get_length( $this->native_ast, $this->native_node_index );
	}

	private function was_mutated(): bool {
		return $this->was_mutated;
	}

	/**
	 * Intern a single accessor return value through the per-AST cache.
	 *
	 * Tokens and nulls pass through untouched. Native node wrappers are
	 * keyed on their `native_node_index`: on cache miss, the freshly
	 * constructed wrapper is stored and given the cache reference; on
	 * cache hit, the canonical instance is returned and the new wrapper
	 * is discarded so callers see stable identity and surviving mutations.
	 *
	 * @param mixed $value Return value from the Rust bridge.
	 * @return mixed
	 */
	private function intern( $value ) {
		if ( ! $value instanceof WP_MySQL_Native_Parser_Node ) {
			return $value;
		}

		$cache = $this->ensure_cache();
		$index = $value->native_node_index;
		if ( null === $index ) {
			return $value;
		}
		if ( isset( $cache->nodes[ $index ] ) ) {
			return $cache->nodes[ $index ];
		}
		$value->cache           = $cache;
		$cache->nodes[ $index ] = $value;
		return $value;
	}

	/**
	 * Intern every entry in an accessor return array.
	 *
	 * Hot path: this runs once per descendant when a caller walks the tree,
	 * so cache lookup and the cache-miss write are inlined and the cache
	 * reference is hoisted out of the loop.
	 *
	 * @param array $values
	 * @return array
	 */
	private function intern_all( array $values ): array {
		if ( ! $values ) {
			return $values;
		}
		$cache = $this->cache ?? $this->ensure_cache();
		$nodes = &$cache->nodes;
		foreach ( $values as $i => $value ) {
			if ( ! $value instanceof WP_MySQL_Native_Parser_Node ) {
				continue;
			}
			$index = $value->native_node_index;
			if ( null === $index ) {
				continue;
			}
			if ( isset( $nodes[ $index ] ) ) {
				$values[ $i ] = $nodes[ $index ];
			} else {
				$value->cache    = $cache;
				$nodes[ $index ] = $value;
			}
		}
		return $values;
	}

	/**
	 * Intern array of guaranteed-node results (no token/null mixing).
	 *
	 * Used by `get_child_nodes()` / `get_descendant_nodes()` whose Rust
	 * bridge returns only WP_MySQL_Native_Parser_Node instances. Skips
	 * the per-item `instanceof` check that intern_all() must do for the
	 * mixed `get_children()` / `get_descendants()` arrays.
	 *
	 * @param array $values
	 * @return array
	 */
	private function intern_nodes( array $values ): array {
		if ( ! $values ) {
			return $values;
		}
		$cache = $this->cache ?? $this->ensure_cache();
		$nodes = &$cache->nodes;
		foreach ( $values as $i => $value ) {
			$index = $value->native_node_index;
			if ( isset( $nodes[ $index ] ) ) {
				$values[ $i ] = $nodes[ $index ];
			} else {
				$value->cache    = $cache;
				$nodes[ $index ] = $value;
			}
		}
		return $values;
	}

	/**
	 * Lazily build (or reuse) the per-AST identity map.
	 *
	 * The root wrapper is constructed without a cache, so the first time
	 * any accessor needs to intern a child, it creates the cache and
	 * registers itself as the root entry. Subsequent interns on this
	 * wrapper or any descendant share the same cache by reference.
	 *
	 * @return WP_MySQL_Native_AST_Cache
	 */
	private function ensure_cache(): WP_MySQL_Native_AST_Cache {
		if ( null === $this->cache ) {
			$this->cache = new WP_MySQL_Native_AST_Cache();
			if ( null !== $this->native_node_index ) {
				$this->cache->nodes[ $this->native_node_index ] = $this;
			}
		}
		return $this->cache;
	}

	private function materialize_native_children(): void {
		if ( $this->was_mutated ) {
			return;
		}

		// Pull children through the cache so any wrapper a caller already
		// mutated via get_first_child_node() etc. survives the transition
		// into $this->children — same instance, same mutations.
		$this->children          = $this->intern_all( wp_sqlite_mysql_native_ast_get_children( $this->native_ast, $this->native_node_index ) );
		$this->native_ast        = null;
		$this->native_node_index = null;
		$this->was_mutated       = true;
	}
}
