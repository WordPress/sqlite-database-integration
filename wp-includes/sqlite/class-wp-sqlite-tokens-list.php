<?php
/**
 * This file is a port of the Tokens class from the PHPMyAdmin/sql-parser library.
 *
 * @package wp-sqlite-integration
 * @see https://github.com/phpmyadmin/sql-parser
 */

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
	public $index = 0;

	/**
	 * Constructor.
	 *
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
	 * Gets the next token. Skips any irrelevant token (whitespaces and
	 * comments).
	 *
	 * @return stdClass|null
	 */
	public function get_next() {
		for ( ; $this->index < $this->count; ++$this->index ) {
			if (
				( WP_SQLite_Token::TYPE_WHITESPACE !== $this->tokens[ $this->index ]->type )
				&& ( WP_SQLite_Token::TYPE_COMMENT !== $this->tokens[ $this->index ]->type )
			) {
				return $this->tokens[ $this->index++ ];
			}
		}

		return null;
	}

	/**
	 * Gets the next token.
	 *
	 * @param int    $type  The type of the token.
	 * @param string $value The value of the token.
	 *
	 * @return stdClass|null
	 */
	public function get_next_of_type_and_value( $type, $value ) {
		for ( ; $this->index < $this->count; ++$this->index ) {
			if ( ( $this->tokens[ $this->index ]->type === $type ) && ( $this->tokens[ $this->index ]->value === $value ) ) {
				return $this->tokens[ $this->index++ ];
			}
		}

		return null;
	}

	/**
	 * Gets the next token.
	 *
	 * @param int $type The type of the token.
	 * @param int $flag The flag of the token.
	 */
	public function get_next_of_type_and_flag( int $type, int $flag ) {
		for ( ; $this->index < $this->count; ++$this->index ) {
			if ( ( $this->tokens[ $this->index ]->type === $type ) && ( $this->tokens[ $this->index ]->flags === $flag ) ) {
				return $this->tokens[ $this->index++ ];
			}
		}

		return null;
	}

	/**
	 * Sets an value inside the container.
	 *
	 * @param int|null $offset The offset to be set.
	 * @param stdClass $value  The token to be saved.
	 *
	 * @return void
	 */
	#[\ReturnTypeWillChange]
	public function offsetSet( $offset, $value ) { // phpcs:ignore Squiz.Commenting.FunctionComment
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
	public function offsetGet( $offset ) { // phpcs:ignore Squiz.Commenting.FunctionComment
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
	public function offsetExists( $offset ) { // phpcs:ignore Squiz.Commenting.FunctionComment
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
	public function offsetUnset( $offset ) { // phpcs:ignore Squiz.Commenting.FunctionComment
		unset( $this->tokens[ $offset ] );
		--$this->count;
		for ( $i = $offset; $i < $this->count; ++$i ) {
			$this->tokens[ $i ] = $this->tokens[ $i + 1 ];
		}

		unset( $this->tokens[ $this->count ] );
	}
}
