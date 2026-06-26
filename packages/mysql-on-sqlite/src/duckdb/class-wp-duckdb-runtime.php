<?php declare(strict_types = 1);

/**
 * Runtime checks for the optional DuckDB PHP client.
 */
class WP_DuckDB_Runtime {
	const CLIENT_CLASS = 'Saturio\\DuckDB\\DuckDB';

	/**
	 * Get the reason why DuckDB is unavailable, or null when it can be used.
	 *
	 * @param bool $probe_connection Whether to open an in-memory database and run SELECT 1.
	 * @return string|null
	 */
	public static function get_unavailable_reason( bool $probe_connection = false ): ?string {
		if ( PHP_VERSION_ID < 80300 ) {
			return 'DuckDB support requires PHP 8.3 or newer.';
		}

		if ( ! extension_loaded( 'ffi' ) ) {
			return 'DuckDB support requires the PHP FFI extension.';
		}

		if ( ! class_exists( self::CLIENT_CLASS ) ) {
			return 'DuckDB support requires the satur.io/duckdb PHP client to be installed and autoloaded.';
		}

		if ( ! $probe_connection ) {
			return null;
		}

		try {
			$client_class = self::CLIENT_CLASS;
			$duckdb       = $client_class::create();
			$result       = $duckdb->query( 'SELECT 1 AS value' );
			foreach ( $result->rows( true ) as $row ) {
				if ( isset( $row['value'] ) && 1 === (int) $row['value'] ) {
					return null;
				}
			}
		} catch ( Throwable $e ) {
			return 'DuckDB runtime probe failed: ' . $e->getMessage();
		}

		return 'DuckDB runtime probe did not return the expected result.';
	}

	/**
	 * Assert that DuckDB is available.
	 *
	 * @param bool $probe_connection Whether to open an in-memory database and run SELECT 1.
	 * @throws WP_DuckDB_Driver_Exception When DuckDB is unavailable.
	 */
	public static function assert_available( bool $probe_connection = false ): void {
		$reason = self::get_unavailable_reason( $probe_connection );
		if ( null !== $reason ) {
			throw new WP_DuckDB_Driver_Exception( $reason );
		}
	}
}
