<?php

/**
 * Parser node backed by a native (Rust) AST.
 *
 * This subclass keeps the regular WP_Parser_Node API while delegating lazy AST
 * reads to the optional native MySQL parser extension. The base node remains a
 * plain PHP tree node for the polyfill parser.
 */
class WP_MySQL_Native_Parser_Node extends WP_Parser_Node {
	private $native_ast        = null;
	private $native_node_index = null;
	private $was_mutated       = false;

	public function __construct( $rule_id, $rule_name, $native_ast = null, $native_node_index = null ) {
		parent::__construct( $rule_id, $rule_name );

		$this->native_ast        = $native_ast;
		$this->native_node_index = $native_node_index;
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
		if ( $this->has_native_ast() ) {
			return wp_sqlite_mysql_native_ast_has_child( $this->native_ast, $this->native_node_index );
		}
		return wp_sqlite_mysql_native_ast_has_child( $this->native_ast, $this->native_node_index );
	}

	/** @inheritDoc */
	public function has_child_node( ?string $rule_name = null ): bool {
		if ( $this->has_native_ast() ) {
			return wp_sqlite_mysql_native_ast_has_child_node( $this->native_ast, $this->native_node_index, $rule_name );
		}
		return wp_sqlite_mysql_native_ast_has_child_node( $this->native_ast, $this->native_node_index, $rule_name );
	}

	/** @inheritDoc */
	public function has_child_token( ?int $token_id = null ): bool {
		if ( $this->has_native_ast() ) {
			return wp_sqlite_mysql_native_ast_has_child_token( $this->native_ast, $this->native_node_index, $token_id );
		}
		return wp_sqlite_mysql_native_ast_has_child_token( $this->native_ast, $this->native_node_index, $token_id );
	}

	/** @inheritDoc */
	public function get_first_child() {
		if ( $this->has_native_ast() ) {
			return wp_sqlite_mysql_native_ast_get_first_child( $this->native_ast, $this->native_node_index );
		}
		return wp_sqlite_mysql_native_ast_get_first_child( $this->native_ast, $this->native_node_index );
	}

	/** @inheritDoc */
	public function get_first_child_node( ?string $rule_name = null ): ?WP_Parser_Node {
		if ( $this->has_native_ast() ) {
			return wp_sqlite_mysql_native_ast_get_first_child_node( $this->native_ast, $this->native_node_index, $rule_name );
		}
		return wp_sqlite_mysql_native_ast_get_first_child_node( $this->native_ast, $this->native_node_index, $rule_name );
	}

	/** @inheritDoc */
	public function get_first_child_token( ?int $token_id = null ): ?WP_Parser_Token {
		if ( $this->has_native_ast() ) {
			return wp_sqlite_mysql_native_ast_get_first_child_token( $this->native_ast, $this->native_node_index, $token_id );
		}
		return wp_sqlite_mysql_native_ast_get_first_child_token( $this->native_ast, $this->native_node_index, $token_id );
	}

	/** @inheritDoc */
	public function get_first_descendant_node( ?string $rule_name = null ): ?WP_Parser_Node {
		if ( $this->has_native_ast() ) {
			return wp_sqlite_mysql_native_ast_get_first_descendant_node( $this->native_ast, $this->native_node_index, $rule_name );
		}
		return wp_sqlite_mysql_native_ast_get_first_descendant_node( $this->native_ast, $this->native_node_index, $rule_name );
	}

	/** @inheritDoc */
	public function get_first_descendant_token( ?int $token_id = null ): ?WP_Parser_Token {
		if ( $this->has_native_ast() ) {
			return wp_sqlite_mysql_native_ast_get_first_descendant_token( $this->native_ast, $this->native_node_index, $token_id );
		}
		return wp_sqlite_mysql_native_ast_get_first_descendant_token( $this->native_ast, $this->native_node_index, $token_id );
	}

	/** @inheritDoc */
	public function get_children(): array {
		if ( $this->has_native_ast() ) {
			return wp_sqlite_mysql_native_ast_get_children( $this->native_ast, $this->native_node_index );
		}
		return wp_sqlite_mysql_native_ast_get_children( $this->native_ast, $this->native_node_index );
	}

	/** @inheritDoc */
	public function get_child_nodes( ?string $rule_name = null ): array {
		if ( $this->has_native_ast() ) {
			return wp_sqlite_mysql_native_ast_get_child_nodes( $this->native_ast, $this->native_node_index, $rule_name );
		}
		return wp_sqlite_mysql_native_ast_get_child_nodes( $this->native_ast, $this->native_node_index, $rule_name );
	}

	/** @inheritDoc */
	public function get_child_tokens( ?int $token_id = null ): array {
		if ( $this->has_native_ast() ) {
			return wp_sqlite_mysql_native_ast_get_child_tokens( $this->native_ast, $this->native_node_index, $token_id );
		}
		return wp_sqlite_mysql_native_ast_get_child_tokens( $this->native_ast, $this->native_node_index, $token_id );
	}

	/** @inheritDoc */
	public function get_descendants(): array {
		if ( $this->has_native_ast() ) {
			return wp_sqlite_mysql_native_ast_get_descendants( $this->native_ast, $this->native_node_index );
		}
		return wp_sqlite_mysql_native_ast_get_descendants( $this->native_ast, $this->native_node_index );
	}

	/** @inheritDoc */
	public function get_descendant_nodes( ?string $rule_name = null ): array {
		if ( $this->has_native_ast() ) {
			return wp_sqlite_mysql_native_ast_get_descendant_nodes( $this->native_ast, $this->native_node_index, $rule_name );
		}
		return wp_sqlite_mysql_native_ast_get_descendant_nodes( $this->native_ast, $this->native_node_index, $rule_name );
	}

	/** @inheritDoc */
	public function get_descendant_tokens( ?int $token_id = null ): array {
		if ( $this->has_native_ast() ) {
			return wp_sqlite_mysql_native_ast_get_descendant_tokens( $this->native_ast, $this->native_node_index, $token_id );
		}
		return wp_sqlite_mysql_native_ast_get_descendant_tokens( $this->native_ast, $this->native_node_index, $token_id );
	}

	/** @inheritDoc */
	public function get_start(): int {
		if ( $this->has_native_ast() ) {
			return wp_sqlite_mysql_native_ast_get_start( $this->native_ast, $this->native_node_index );
		}
		return wp_sqlite_mysql_native_ast_get_start( $this->native_ast, $this->native_node_index );
	}

	/** @inheritDoc */
	public function get_length(): int {
		if ( $this->has_native_ast() ) {
			return wp_sqlite_mysql_native_ast_get_length( $this->native_ast, $this->native_node_index );
		}
		return wp_sqlite_mysql_native_ast_get_length( $this->native_ast, $this->native_node_index );
	}

	private function has_native_ast(): bool {
		return null !== $this->native_ast;
	}

	private function materialize_native_children(): void {
		if ( ! $this->has_native_ast() ) {
			return;
		}

		$this->children          = wp_sqlite_mysql_native_ast_get_children( $this->native_ast, $this->native_node_index );
		$this->native_ast        = null;
		$this->native_node_index = null;
		$this->was_mutated       = true;
	}
}
