<?php

use PHPUnit\Framework\TestCase;

/**
 * Base class for the MySQL-judged REGEXP tests.
 *
 * Each source in "data" has cases of a pattern, a subject, and a match type, with the
 * results that MySQL returned for each REGEXP function. A test class runs the checks
 * of one function through the driver, and compares their results with MySQL:
 *
 *   regexp_like       REGEXP_LIKE(subject, pattern[, match_type])
 *   regexp_instr      REGEXP_INSTR(subject, pattern, 1, 1, 0[, match_type])
 *   regexp_instr_end  REGEXP_INSTR(subject, pattern, 1, 1, 1[, match_type])
 *   regexp_substr     REGEXP_SUBSTR(subject, pattern, 1, 1[, match_type])
 *   regexp_replace    REGEXP_REPLACE(subject, pattern, template, 1, 0[, match_type])
 *
 * A case with a null match type runs without the argument, so the case sensitivity
 * comes from the collation, utf8mb4_0900_ai_ci, which ignores case. The template
 * wraps every match and its capture groups, like "<$0|$1|$2>".
 *
 * A check passes when the driver returns the same value as MySQL, or raises an error
 * with the same MySQL error code, without any PHP warning or notice. A case fails when
 * any check of the test class fails. A test class lists the cases that are expected to
 * fail in get_known_failures(), with version checks for the cases that fail only with
 * some PHP or PCRE versions.
 */
abstract class WP_MySQL_On_SQLite_Regexp_Test_Case extends TestCase {
	const DATA_DIR = __DIR__ . '/data';
	const SOURCES  = array( 'mysql-server-suite' );

	// The SQL of each check, with the subject, pattern, template, and match type argument.
	const QUERIES = array(
		'regexp_like'      => 'SELECT REGEXP_LIKE(%1$s, %2$s%4$s)',
		'regexp_instr'     => 'SELECT REGEXP_INSTR(%1$s, %2$s, 1, 1, 0%4$s)',
		'regexp_instr_end' => 'SELECT REGEXP_INSTR(%1$s, %2$s, 1, 1, 1%4$s)',
		'regexp_substr'    => 'SELECT REGEXP_SUBSTR(%1$s, %2$s, 1, 1%4$s)',
		'regexp_replace'   => 'SELECT REGEXP_REPLACE(%1$s, %2$s, %3$s, 1, 0%4$s)',
	);

	// The checks that a test class runs.
	const CHECKS = array();

	/**
	 * Check that exactly the known failures fail.
	 *
	 * @dataProvider data_sources
	 */
	public function test_mysql_results( string $source ): void {
		$failures = self::run_checks( $source, static::CHECKS );
		$known    = self::filter_by_source( $this->get_known_failures(), $source );

		$new_failures = array_diff_key( $failures, array_flip( $known ) );
		$new_passes   = array_values( array_diff( $known, array_keys( $failures ) ) );

		$report = '';
		if ( $new_failures ) {
			$report .= sprintf( "%d cases fail, but they are not known failures:\n", count( $new_failures ) );
			foreach ( array_slice( $new_failures, 0, 20, true ) as $key => $failure ) {
				$report .= "  $key  $failure\n";
			}
		}
		if ( $new_passes ) {
			$report .= sprintf( "%d known failures pass: %s\n", count( $new_passes ), implode( ' ', array_slice( $new_passes, 0, 20 ) ) );
		}
		if ( $report ) {
			$report .= sprintf( 'If this is expected, update %s::get_known_failures().', static::class );
		}
		$this->assertTrue( '' === $report, $report );
	}

	public static function data_sources(): array {
		$sources = array();
		foreach ( self::SOURCES as $source ) {
			$sources[ $source ] = array( $source );
		}
		return $sources;
	}

	/**
	 * Read the cases of a source.
	 *
	 * @return array[] The cases.
	 */
	public static function read_cases( string $source ): array {
		$data = json_decode( file_get_contents( self::DATA_DIR . "/$source.json" ), true );
		if ( ! is_array( $data ) ) {
			throw new RuntimeException( "Invalid data for source '$source': " . json_last_error_msg() );
		}
		return $data['cases'];
	}

	/**
	 * Build the SQL of a check, from SQL expressions for its subject, pattern, and template.
	 *
	 * MySQL needs a literal match type, so the match type is checked and inlined.
	 */
	public static function build_query( string $check, string $subject, string $pattern, string $template, ?string $match_type ): string {
		if ( null !== $match_type && ! preg_match( '/^[cimnu]*$/D', $match_type ) ) {
			throw new InvalidArgumentException( "Invalid match type: $match_type" );
		}
		$match_type_argument = null === $match_type ? '' : ", '$match_type'";
		return sprintf( self::QUERIES[ $check ], $subject, $pattern, $template, $match_type_argument );
	}

	/**
	 * Get a replacement template that wraps every match and its capture groups.
	 *
	 * MySQL raises an error for a group past the last one, so it needs the group count.
	 */
	public static function get_template( int $groups ): string {
		$template = '<$0';
		for ( $group = 1; $group <= $groups; $group++ ) {
			$template .= '|$' . $group;
		}
		return $template . '>';
	}

	/**
	 * Run checks on all cases of a source.
	 *
	 * @param string   $source The source.
	 * @param string[] $checks The checks to run.
	 * @return string[] A description of each failing case, keyed by "<source>:<case id>".
	 */
	protected static function run_checks( string $source, array $checks ): array {
		$driver   = new WP_MySQL_On_SQLite(
			'mysql-on-sqlite:path=:memory:;dbname=wp',
			null,
			null,
			array( PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION )
		);
		$failures = array();
		foreach ( self::read_cases( $source ) as $case ) {
			$differences = array();
			foreach ( $checks as $check ) {
				$query  = self::build_query(
					$check,
					$driver->quote( $case['subject'] ),
					$driver->quote( $case['pattern'] ),
					$driver->quote( self::get_template( $case['groups'] ) ),
					$case['match_type']
				);
				$actual = self::run_query( $driver, $query );
				if ( ! self::is_same_result( $case[ $check ], $actual ) ) {
					$differences[] = sprintf(
						'%s, expected %s, got %s',
						$query,
						self::format_result( $case[ $check ] ),
						self::format_result( $actual )
					);
				}
			}
			if ( $differences ) {
				$failures[ "$source:{$case['id']}" ] = implode( '; ', $differences );
			}
		}
		return $failures;
	}

	/**
	 * Get the cases that are expected to fail in this environment.
	 *
	 * @return string[] The failing cases, as "<source>:<case id>".
	 */
	protected function get_known_failures(): array {
		return array();
	}

	/**
	 * Get the cases of a source from a list of cases.
	 *
	 * @param string[] $cases  The cases, as "<source>:<case id>".
	 * @param string   $source The source.
	 * @return string[] The cases of the source.
	 */
	private static function filter_by_source( array $cases, string $source ): array {
		$prefix = "$source:";
		return array_values(
			array_filter(
				$cases,
				function ( $key ) use ( $prefix ) {
					return 0 === strpos( $key, $prefix );
				}
			)
		);
	}

	/**
	 * Run a query and get its single value, its error, or the PHP errors that it caused.
	 *
	 * @return mixed The value as a string or null, an array with "error" and "message",
	 *               or an array with "php_errors".
	 */
	private static function run_query( WP_MySQL_On_SQLite $driver, string $query ) {
		$result     = null;
		$php_errors = array();
		set_error_handler(
			function ( $severity, $message ) use ( &$php_errors ) {
				// Skip errors that are silenced with "@".
				if ( error_reporting() & $severity ) {
					$php_errors[] = $message;
				}
				return true;
			}
		);
		try {
			$value  = $driver->query( $query )->fetchColumn();
			$result = null === $value ? null : (string) $value;
		} catch ( PDOException $e ) {
			$result = array(
				'error'   => $e->errorInfo[1] ?? null,
				'message' => $e->getMessage(),
			);
		} catch ( Throwable $e ) {
			$php_errors[] = get_class( $e ) . ': ' . $e->getMessage();
		} finally {
			restore_error_handler();
		}
		return $php_errors ? array( 'php_errors' => $php_errors ) : $result;
	}

	/**
	 * Check whether a result matches the result from MySQL.
	 *
	 * @param mixed $expected The value or error from MySQL.
	 * @param mixed $actual   The result from run_query().
	 */
	private static function is_same_result( $expected, $actual ): bool {
		if ( is_array( $expected ) ) {
			return is_array( $actual ) && ( $actual['error'] ?? null ) === $expected['error'];
		}
		return ( null === $expected ? null : (string) $expected ) === $actual;
	}

	/**
	 * Format a value or an error for a failure description.
	 *
	 * @param mixed $result The value or error.
	 */
	private static function format_result( $result ): string {
		if ( is_array( $result ) && isset( $result['php_errors'] ) ) {
			return 'PHP errors: ' . implode( ' ', $result['php_errors'] );
		}
		if ( is_array( $result ) ) {
			return sprintf( 'error %s (%s)', $result['error'], $result['message'] );
		}
		return json_encode( $result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE );
	}
}
