<?php

/**
 * Native-mode `WP_MySQL_Parser` implementation, delivered as a trait.
 *
 * The class that uses this trait (`WP_MySQL_Parser` in native mode)
 * extends the pure-PHP `WP_Parser` so callers' `instanceof WP_Parser`
 * checks keep working, while the actual parsing work is delegated to
 * the Rust-registered `WP_MySQL_Native_Parser` instance held in
 * `$this->native`. `WP_Parser`'s state (`$grammar`, `$tokens`,
 * `$position`) stays inert in native mode — the trait's overrides
 * never read it.
 *
 * Adding a public method here is enough to plumb a new public method
 * through to the native parser; the using class does not need touching.
 */
trait WP_MySQL_Native_Parser_Impl {
	/**
	 * @var WP_MySQL_Native_Parser
	 */
	private $native;

	public function __construct( WP_Parser_Grammar $grammar, array $tokens ) {
		parent::__construct( $grammar, $tokens );
		$this->native = new WP_MySQL_Native_Parser( $grammar, $tokens );
	}

	public function reset_tokens( array $tokens ): void {
		$this->native->reset_tokens( $tokens );
	}

	public function next_query(): bool {
		return $this->native->next_query();
	}

	public function get_query_ast(): ?WP_Parser_Node {
		return $this->native->get_query_ast();
	}

	public function parse() {
		return $this->native->parse();
	}
}
