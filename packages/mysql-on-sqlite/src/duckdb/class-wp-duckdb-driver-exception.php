<?php

/**
 * Exception thrown by the DuckDB adapter.
 */
class WP_DuckDB_Driver_Exception extends RuntimeException {
	/**
	 * Constructor.
	 *
	 * @param string         $message  The exception message.
	 * @param int|string     $code     The exception code.
	 * @param Throwable|null $previous The previous throwable used for the exception chaining.
	 */
	public function __construct( string $message = '', $code = 0, ?Throwable $previous = null ) {
		parent::__construct( $message, 0, $previous );
		$this->code = ( 0 === $code && null !== $previous ) ? 'HY000' : $code;
	}
}
