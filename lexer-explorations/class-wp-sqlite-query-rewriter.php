<?php
/**
 * Class WP_SQLite_Query_Rewriter
 *
 * @package wp-sqlite-integration
 */

/**
 * The query rewriter class.
 */
class WP_SQLite_Query_Rewriter {

	/**
	 * An array of input token objects.
	 *
	 * @var WP_SQLite_Token[]
	 */
	public $input_tokens = array();

	/**
	 * An array of output token objects.
	 *
	 * @var WP_SQLite_Token[]
	 */
	public $output_tokens = array();

	/**
	 * The current index.
	 *
	 * @var int
	 */
	public $index = -1;

	/**
	 * The maximum index.
	 *
	 * @var int
	 */
	public $max = -1;

	/**
	 * The call stack.
	 *
	 * @var array
	 */
	public $call_stack = array();

	/**
	 * The current depth.
	 *
	 * @var int
	 */
	public $depth = 0;

	/**
	 * Constructor.
	 *
	 * @param WP_SQLite_Token[] $input_tokens Array of token objects.
	 */
	public function __construct( $input_tokens ) {
		$this->input_tokens = $input_tokens;
		$this->max          = count( $input_tokens );
	}

	/**
	 * Returns the updated query.
	 *
	 * @return string
	 */
	public function get_updated_query() {
		$query = '';
		foreach ( $this->output_tokens as $token ) {
			$query .= $token->token;
		}
		return $query;
	}

	/**
	 * Returns the current token.
	 *
	 * @return WP_SQLite_Token|null
	 */
	public function current() {
		if ( $this->index < 0 || $this->index >= $this->max ) {
			return null;
		}
		return $this->input_tokens[ $this->index ];
	}

	/**
	 * Add a token to the output.
	 *
	 * @param WP_SQLite_Token $token Token object.
	 */
	public function add( $token ) {
		$this->output_tokens[] = $token;
	}

	/**
	 * Add multiple tokens to the output.
	 *
	 * @param WP_SQLite_Token[] $tokens Array of token objects.
	 */
	public function add_many( $tokens ) {
		$this->output_tokens = array_merge( $this->output_tokens, $tokens );
	}

	/**
	 * Replaces the last token.
	 *
	 * @param WP_SQLite_Token $token Token object.
	 */
	public function replace_last( $token ) {
		$this->drop_last();
		$this->output_tokens[] = $token;
	}

	/**
	 * Replaces all tokens.
	 *
	 * @param WP_SQLite_Token[] $tokens Array of token objects.
	 */
	public function replace_all( $tokens ) {
		$this->output_tokens = $tokens;
	}

	/**
	 * Peek at the next tokens and return one that matches the given criteria.
	 *
	 * @param string|null $type   Token type.
	 * @param int|null    $flags  Token flags.
	 * @param string|null $values Token values.
	 *
	 * @return WP_SQLite_Token|null
	 */
	public function peek( $type = null, $flags = null, $values = null, $nth = 0 ) {
		$i = $this->index;
		$matches = 0;
		while ( ++$i < $this->max ) {
			$token = $this->input_tokens[ $i ];
			if ( $this->matches( $token, $type, $flags, $values ) ) {
				if(++$matches >= $nth) {
					return $token;
				}
			}
		}
	}

	/**
	 * Consume all the tokens.
	 *
	 * @param array $options Options.
	 *
	 * @return void
	 */
	public function consume_all( $options = array() ) {
		while ( $this->consume( $options ) ) {
			// Do nothing.
		}
	}

	/**
	 * Consume the next tokens and return one that matches the given criteria.
	 *
	 * @param array $options Options.
	 *
	 * @return WP_SQLite_Token|null
	 */
	public function consume( $options = array() ) {
		$next_tokens         = $this->get_next_tokens( $options );
		$tokens              = $next_tokens[0];
		$last_matched        = $next_tokens[1];
		$count               = count( $tokens );
		$this->index        += $count;
		$this->output_tokens = array_merge( $this->output_tokens, $tokens );
		if ( ! $count ) {
			++$this->index;
		}
		return $last_matched ? $this->current() : null;
	}

	/**
	 * Drop the last token and return it.
	 *
	 * @return WP_SQLite_Token|null
	 */
	public function drop_last() {
		return array_pop( $this->output_tokens );
	}

	/**
	 * Skip over the next tokens and return one that matches the given criteria.
	 *
	 * @param array $options Options.
	 *
	 * @return WP_SQLite_Token|null
	 */
	public function skip( $options = array() ) {
		$this->skip_over( $options );
		return $this->current();
	}

	public function skip_field_length() {
		$paren_maybe = $this->peek();
		if ( $paren_maybe && '(' === $paren_maybe->token ) {
			$this->skip();
			$this->skip();
			$this->skip();
		}
	}

	/**
	 * Skip over the next tokens and return one that matches the given criteria.
	 *
	 * @param array $options Options.
	 *
	 * @return WP_SQLite_Token[]|null
	 */
	public function skip_over( $options = array() ) {
		$next_tokens  = $this->get_next_tokens( $options );
		$tokens       = $next_tokens[0];
		$last_matched = $next_tokens[1];
		$count        = count( $tokens );
		$this->index += $count;
		if ( ! $count ) {
			++$this->index;
		}
		return $last_matched ? $tokens : null;
	}

	/**
	 * Returns the next tokens that match the given criteria.
	 *
	 * @param array $options Options.
	 *
	 * @return array
	 */
	private function get_next_tokens( $options = array() ) {
		$type   = isset( $options['type'] ) ? $options['type'] : null;
		$flags  = isset( $options['flags'] ) ? $options['flags'] : null;
		$values = isset( $options['value'] )
			? ( is_array( $options['value'] ) ? $options['value'] : array( $options['value'] ) )
			: null;
		$depth  = isset( $options['depth'] ) ? $options['depth'] : null;
		
		$buffered = array();
		$i        = $this->index;
		while ( ++$i < $this->max ) {
			$token = $this->input_tokens[ $i ];
			$this->update_call_stack( $token, $i );
			$buffered[] = $token;
			if (
				( null === $depth || $this->depth === $depth )
				&& $this->matches( $token, $type, $flags, $values ) 
			) {
				return array( $buffered, true );
			}
		}

		return array( $buffered, false );
	}

	/**
	 * Checks if the given token matches the given criteria.
	 *
	 * @param WP_SQLite_Token $token  Token object.
	 * @param string|null     $type   Token type.
	 * @param int|null        $flags  Token flags.
	 * @param string|null     $values Token values.
	 *
	 * @return bool
	 */
	private function matches( $token, $type = null, $flags = null, $values = null ) {
		if ( null === $type && null === $flags && null === $values ) {
			return !$token->is_whitespace() && !$token->is_comment();
		}

		return $token->matches( $type, $flags, $values );
	}

	/**
	 * Returns the last call stack element.
	 *
	 * @return WP_SQLite_Token|null
	 */
	public function last_call_stack_element() {
		return count( $this->call_stack ) ? $this->call_stack[ count( $this->call_stack ) - 1 ] : null;
	}

	/**
	 * Updates the call stack.
	 *
	 * @param WP_SQLite_Token $token Token.
	 * @param int             $current_idx Current index.
	 *
	 * @return void
	 */
	private function update_call_stack( $token, $current_idx ) {
		if ( WP_SQLite_Token::TYPE_KEYWORD === $token->type ) {
			if (
				$token->flags & WP_SQLite_Token::FLAG_KEYWORD_FUNCTION
				// && ! ( $token->flags & WP_SQLite_Token::FLAG_KEYWORD_RESERVED )
			) {
				$j = $current_idx;
				do {
					$peek = $this->input_tokens[ ++$j ];
				} while ( WP_SQLite_Token::TYPE_WHITESPACE === $peek->type );
				if ( WP_SQLite_Token::TYPE_OPERATOR === $peek->type && '(' === $peek->value ) {
					array_push( $this->call_stack, array( $token->value, $this->depth ) );
				}
			}
		} elseif ( WP_SQLite_Token::TYPE_OPERATOR === $token->type ) {
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
