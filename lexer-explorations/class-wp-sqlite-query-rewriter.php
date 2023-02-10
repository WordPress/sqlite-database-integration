<?php

class WP_SQLite_Query_Rewriter {

	public $input_tokens  = array();
	public $output_tokens = array();
	public $idx           = -1;
	public $max           = -1;
	public $call_stack    = array();
	public $depth         = 0;

	public function __construct( $input_tokens ) {
		$this->input_tokens = $input_tokens;
		$this->max          = count( $input_tokens );
	}

	public function get_updated_query() {
		$query = '';
		foreach ( $this->output_tokens as $token ) {
			$query .= $token->token;
		}
		return $query;
	}

	public function current() {
		if ( $this->idx < 0 || $this->idx >= $this->max ) {
			return null;
		}
		return $this->input_tokens[ $this->idx ];
	}

	public function add( $token ) {
		$this->output_tokens[] = $token;
	}

	public function add_many( $tokens ) {
		$this->output_tokens = array_merge( $this->output_tokens, $tokens );
	}

	public function replace_last( $token ) {
		$this->drop_last();
		$this->output_tokens[] = $token;
	}

	public function replace_all( $tokens ) {
		$this->output_tokens = $tokens;
	}

	public function peek( $type = null, $flags = null, $values = null ) {
		$i = $this->idx;
		while ( ++$i < $this->max ) {
			$token = $this->input_tokens[ $i ];
			if ( $this->matches( $token, $type, $flags, $values ) ) {
				return $token;
			}
		}
	}

	public function consume_all() {
		while ( $this->consume() ) {
		}
	}

	public function consume( $options = array() ) {
		$next_tokens         = $this->get_next_tokens( $options );
		$tokens              = $next_tokens[0];
		$last_matched        = $next_tokens[1];
		$count               = count( $tokens );
		$this->idx          += $count;
		$this->output_tokens = array_merge( $this->output_tokens, $tokens );
		if ( ! $count ) {
			++$this->idx;
		}
		return $last_matched ? $this->current() : null;
	}

	public function drop_last() {
		return array_pop( $this->output_tokens );
	}

	public function skip( $options = array() ) {
		$this->skip_over( $options );
		return $this->current();
	}

	public function skip_over( $options = array() ) {
		$next_tokens  = $this->get_next_tokens( $options );
		$tokens       = $next_tokens[0];
		$last_matched = $next_tokens[1];
		$count        = count( $tokens );
		$this->idx   += $count;
		if ( ! $count ) {
			++$this->idx;
		}
		return $last_matched ? $tokens : null;
	}

	private function get_next_tokens( $options = array() ) {
		$type   = isset( $options['type'] ) ? $options['type'] : null;
		$flags  = isset( $options['flags'] ) ? $options['flags'] : null;
		$values = isset( $options['value'] )
			? ( is_array( $options['value'] ) ? $options['value'] : array( $options['value'] ) )
			: null;

		$buffered = array();
		$i        = $this->idx;
		while ( ++$i < $this->max ) {
			$token = $this->input_tokens[ $i ];
			$this->update_call_stack( $token, $i );
			$buffered[] = $token;
			if ( $this->matches( $token, $type, $flags, $values ) ) {
				return array( $buffered, true );
			}
		}

		return array( $buffered, false );
	}

	private function matches( $token, $type = null, $flags = null, $values = null ) {
		if ( null === $type && null === $flags && null === $values ) {
			if (
				WP_SQLite_Lexer::TYPE_WHITESPACE !== $token->type
				&& WP_SQLite_Lexer::TYPE_COMMENT !== $token->type
			) {
				return true;
			}
		} elseif (
			( null === $type || $token->type === $type )
			&& ( null === $flags || ( $token->flags & $flags ) )
			&& ( null === $values || in_array( $token->value, $values, true ) )
		) {
			return true;
		}

		return false;
	}

	public function last_call_stack_element() {
		return count( $this->call_stack ) ? $this->call_stack[ count( $this->call_stack ) - 1 ] : null;
	}

	private function update_call_stack( $token, $current_idx ) {
		if ( WP_SQLite_Lexer::TYPE_KEYWORD === $token->type ) {
			if (
				$token->flags & WP_SQLite_Lexer::FLAG_KEYWORD_FUNCTION
				// && ! ( $token->flags & WP_SQLite_Lexer::FLAG_KEYWORD_RESERVED )
			) {
				$j = $current_idx;
				do {
					$peek = $this->input_tokens[ ++$j ];
				} while ( WP_SQLite_Lexer::TYPE_WHITESPACE === $peek->type );
				if ( WP_SQLite_Lexer::TYPE_OPERATOR === $peek->type && '(' === $peek->value ) {
					array_push( $this->call_stack, array( $token->value, $this->depth ) );
				}
			}
		} elseif ( WP_SQLite_Lexer::TYPE_OPERATOR === $token->type ) {
			if ( '(' === $token->value ) {
				++$this->depth;
			} elseif ( ')' === $token->value ) {
				--$this->depth;
				$call_parent = $this->last_call_stack_element();
				if (
					$call_parent &&
					$call_parent[1] === $this->depth
				) {
					array_pop( $this->call_stack );
				}
			}
		}
	}


}
