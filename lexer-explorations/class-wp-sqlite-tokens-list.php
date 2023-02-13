<?php

declare(strict_types=1);

/**
 * Defines an array of tokens and utility functions to iterate through it.
 *
 * A structure representing a list of tokens.
 *
 * @implements ArrayAccess<int, stdClass>
 */
class WP_SQLite_Tokens_List implements ArrayAccess {

	/**
	 * The array of tokens.
	 *
	 * @var stdClass[]
	 */
	public $tokens = array();

	/**
	 * The count of tokens.
	 *
	 * @var int
	 */
	public $count = 0;

	/**
	 * The index of the next token to be returned.
	 *
	 * @var int
	 */
	public $idx = 0;

	/**
	 * @param stdClass[] $tokens The initial array of tokens.
	 * @param int        $count  The count of tokens in the initial array.
	 */
	public function __construct( array $tokens = array(), $count = -1 ) {
		if ( empty( $tokens ) ) {
			return;
		}

		$this->tokens = $tokens;
		$this->count  = -1 === $count ? count( $tokens ) : $count;
	}

	/**
	 * Builds an array of tokens by merging their raw value.
	 *
	 * @param string|stdClass[]|TokensList $list The tokens to be built.
	 *
	 * @return string
	 */
	public static function build( $list ) {
		if ( is_string( $list ) ) {
			return $list;
		}

		if ( $list instanceof self ) {
			$list = $list->tokens;
		}

		$ret = '';
		if ( is_array( $list ) ) {
			foreach ( $list as $tok ) {
				$ret .= $tok->token;
			}
		}

		return $ret;
	}

	/**
	 * Adds a new token.
	 *
	 * @param stdClass $token Token to be added in list.
	 *
	 * @return void
	 */
	public function add( $token ) {
		$this->tokens[ $this->count++ ] = $token;
	}

	/**
	 * Gets the next token. Skips any irrelevant token (whitespaces and
	 * comments).
	 *
	 * @return stdClass|null
	 */
	public function get_next() {
		for ( ; $this->idx < $this->count; ++$this->idx ) {
			if (
				( WP_SQLite_Token::TYPE_WHITESPACE !== $this->tokens[ $this->idx ]->type )
				&& ( WP_SQLite_Token::TYPE_COMMENT !== $this->tokens[ $this->idx ]->type )
			) {
				return $this->tokens[ $this->idx++ ];
			}
		}

		return null;
	}

	/**
	 * Gets the previous token. Skips any irrelevant token (whitespaces and
	 * comments).
	 */
	public function get_previous() {
		for ( ; $this->idx > 0; --$this->idx ) {
			if (
				( WP_SQLite_Token::TYPE_WHITESPACE !== $this->tokens[ $this->idx ]->type )
				&& ( WP_SQLite_Token::TYPE_COMMENT !== $this->tokens[ $this->idx ]->type )
			) {
				return $this->tokens[ $this->idx-- ];
			}
		}

		return null;
	}

	/**
	 * Gets the next token.
	 *
	 * @param int $type the type
	 *
	 * @return stdClass|null
	 */
	public function get_next_of_type( $type ) {
		for ( ; $this->idx < $this->count; ++$this->idx ) {
			if ( $this->tokens[ $this->idx ]->type === $type ) {
				return $this->tokens[ $this->idx++ ];
			}
		}

		return null;
	}

	/**
	 * Gets the next token.
	 *
	 * @param int    $type  the type of the token
	 * @param string $value the value of the token
	 *
	 * @return stdClass|null
	 */
	public function get_next_of_type_and_value( $type, $value ) {
		for ( ; $this->idx < $this->count; ++$this->idx ) {
			if ( ( $this->tokens[ $this->idx ]->type === $type ) && ( $this->tokens[ $this->idx ]->value === $value ) ) {
				return $this->tokens[ $this->idx++ ];
			}
		}

		return null;
	}

	/**
	 * Gets the next token.
	 *
	 * @param int $type the type of the token
	 * @param int $flag the flag of the token
	 */
	public function get_next_of_type_and_flag( int $type, int $flag ) {
		for ( ; $this->idx < $this->count; ++$this->idx ) {
			if ( ( $this->tokens[ $this->idx ]->type === $type ) && ( $this->tokens[ $this->idx ]->flags === $flag ) ) {
				return $this->tokens[ $this->idx++ ];
			}
		}

		return null;
	}

	/**
	 * Sets an value inside the container.
	 *
	 * @param int|null $offset the offset to be set
	 * @param stdClass    $value  the token to be saved
	 *
	 * @return void
	 */
	#[\ReturnTypeWillChange]
	public function offsetSet( $offset, $value ) {
		if ( null === $offset ) {
			$this->tokens[ $this->count++ ] = $value;
		} else {
			$this->tokens[ $offset ] = $value;
		}
	}

	/**
	 * Gets a value from the container.
	 *
	 * @param int $offset the offset to be returned
	 *
	 * @return stdClass|null
	 */
	#[\ReturnTypeWillChange]
	public function offsetGet( $offset ) {
		return $offset < $this->count ? $this->tokens[ $offset ] : null;
	}

	/**
	 * Checks if an offset was previously set.
	 *
	 * @param int $offset the offset to be checked
	 *
	 * @return bool
	 */
	#[\ReturnTypeWillChange]
	public function offsetExists( $offset ) {
		return $offset < $this->count;
	}

	/**
	 * Unsets the value of an offset.
	 *
	 * @param int $offset the offset to be unset
	 *
	 * @return void
	 */
	#[\ReturnTypeWillChange]
	public function offsetUnset( $offset ) {
		unset( $this->tokens[ $offset ] );
		--$this->count;
		for ( $i = $offset; $i < $this->count; ++$i ) {
			$this->tokens[ $i ] = $this->tokens[ $i + 1 ];
		}

		unset( $this->tokens[ $this->count ] );
	}
}
