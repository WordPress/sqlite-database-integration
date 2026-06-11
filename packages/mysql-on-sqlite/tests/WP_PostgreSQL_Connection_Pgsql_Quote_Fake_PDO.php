<?php

/**
 * PDO fixture that reports the PostgreSQL driver for quote tests.
 */
class WP_PostgreSQL_Connection_Pgsql_Quote_Fake_PDO {
	/**
	 * Handle fake PDO method calls.
	 *
	 * @param string $method_name Method name.
	 * @param array  $arguments   Method arguments.
	 * @return mixed Method return value.
	 */
	public function __call( $method_name, array $arguments ) {
		if ( 'getAttribute' === $method_name && PDO::ATTR_DRIVER_NAME === ( $arguments[0] ?? null ) ) {
			return 'pgsql';
		}

		if ( 'quote' === $method_name ) {
			return "'" . str_replace( "'", "''", (string) ( $arguments[0] ?? '' ) ) . "'";
		}

		return null;
	}
}
