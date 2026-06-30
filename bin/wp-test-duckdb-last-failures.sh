#!/bin/bash

##
# Run the package-local DuckDB PHPUnit defects recorded in PHPUnit's cache.
#
# This is a local iteration gate. It does not prepare WordPress, start Docker,
# run E2E, or expand to the package-wide DuckDB group after cached defects pass.
##

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd -P)"
PACKAGE_DIR="$ROOT_DIR/packages/mysql-on-sqlite"
CACHE_FILE="${WP_DUCKDB_LAST_FAILURE_CACHE:-$PACKAGE_DIR/.phpunit.result.cache}"
MODE="${WP_DUCKDB_LAST_FAILURE_MODE:-run}"
DRY_RUN="${WP_DUCKDB_LAST_FAILURE_DRY_RUN:-0}"
MAX_TESTS="${WP_DUCKDB_LAST_FAILURE_MAX_TESTS:-12}"
TIMEOUT_SECONDS="${WP_DUCKDB_LAST_FAILURE_TIMEOUT:-60}"
CLASS_PATTERN="${WP_DUCKDB_LAST_FAILURE_CLASS_PATTERN:-/^WP_DuckDB_[A-Za-z0-9_]*$/}"

fail() {
	echo "Error: $*" >&2
	exit 1
}

usage() {
	cat <<'EOF'
Usage: bin/wp-test-duckdb-last-failures.sh [run|validate]

Runs only package-local DuckDB PHPUnit defects recorded in
packages/mysql-on-sqlite/.phpunit.result.cache.

Environment:
  WP_DUCKDB_LAST_FAILURE_DRY_RUN=1    Print the selected command only.
  WP_DUCKDB_LAST_FAILURE_MAX_TESTS    Maximum cached defects to run. Default: 12.
  WP_DUCKDB_LAST_FAILURE_TIMEOUT      Timeout in seconds. Default: 60.
  WP_DUCKDB_LAST_FAILURE_CACHE        Override the PHPUnit cache path.
  WP_DUCKDB_LAST_FAILURE_CLASS_PATTERN
                                      PHP regex for class names. Default: /^WP_DuckDB_[A-Za-z0-9_]*$/.
EOF
}

is_positive_integer() {
	case "${1:-}" in
		''|*[!0-9]*)
			return 1
			;;
	esac

	[ "$1" -gt 0 ]
}

print_command() {
	printf 'Command:'
	printf ' %q' "$@"
	printf '\n'
}

run_with_timeout() {
	local seconds="$1"
	shift

	if command -v timeout > /dev/null 2>&1; then
		timeout "${seconds}s" "$@"
		return $?
	fi

	"$@"
}

read_duckdb_failure_filter() {
	[ -f "$CACHE_FILE" ] || {
			printf '0\n\n'
			return
		}

	WP_DUCKDB_LAST_FAILURE_CACHE_FILE="$CACHE_FILE" \
	WP_DUCKDB_LAST_FAILURE_MAX_TESTS="$MAX_TESTS" \
	WP_DUCKDB_LAST_FAILURE_CLASS_PATTERN="$CLASS_PATTERN" \
	php -r '
		$cache_file = getenv( "WP_DUCKDB_LAST_FAILURE_CACHE_FILE" );
		$max_tests = (int) getenv( "WP_DUCKDB_LAST_FAILURE_MAX_TESTS" );
		$class_pattern = getenv( "WP_DUCKDB_LAST_FAILURE_CLASS_PATTERN" );
		$cache = json_decode( file_get_contents( $cache_file ), true );
		if ( ! is_array( $cache ) || ! isset( $cache["defects"] ) || ! is_array( $cache["defects"] ) ) {
			fwrite( STDERR, "Error: PHPUnit cache does not contain a defects map: {$cache_file}\n" );
			exit( 2 );
		}
		if ( 1 !== ( $cache["version"] ?? null ) ) {
			fwrite( STDERR, "Error: Unsupported PHPUnit cache version in {$cache_file}\n" );
			exit( 2 );
		}
		if ( false === @preg_match( $class_pattern, "WP_DuckDB_Driver_Tests" ) ) {
			fwrite( STDERR, "Error: WP_DUCKDB_LAST_FAILURE_CLASS_PATTERN is not a valid PHP regex.\n" );
			exit( 2 );
		}

		$tests = array();
		foreach ( array_keys( $cache["defects"] ) as $test_id ) {
			if ( ! is_string( $test_id ) || ! preg_match( "/^([A-Za-z_][A-Za-z0-9_]*)::([A-Za-z_][A-Za-z0-9_]*)$/", $test_id, $matches ) ) {
				continue;
			}
			if ( ! preg_match( $class_pattern, $matches[1] ) ) {
				continue;
			}
			$tests[] = $test_id;
			if ( count( $tests ) >= $max_tests ) {
				break;
			}
		}

		echo count( $tests ), "\n";
		echo implode( "|", array_map( static function ( $test_id ) {
			return preg_quote( $test_id, "/" );
		}, $tests ) ), "\n";
		echo implode( "\n", $tests ), "\n";
	'
}

run_last_failures() {
	local failure_result failure_count failure_filter duckdb_php_autoload wp_duckdb_autoload
	local phpunit_output phpunit_status
	local -a failure_lines phpunit_command selected_tests

	failure_result="$(read_duckdb_failure_filter)"
	mapfile -t failure_lines <<< "$failure_result"
	failure_count="${failure_lines[0]:-0}"
	failure_filter="${failure_lines[1]:-}"
	selected_tests=( "${failure_lines[@]:2}" )

	if [ "$failure_count" -eq 0 ]; then
		echo "No cached package-local DuckDB PHPUnit defects found in $CACHE_FILE."
		return
	fi

	duckdb_php_autoload="${DUCKDB_PHP_AUTOLOAD:-}"
	if [ -z "$duckdb_php_autoload" ] && [ -f /tmp/wp-duckdb-php-runtime/vendor/autoload.php ]; then
		duckdb_php_autoload=/tmp/wp-duckdb-php-runtime/vendor/autoload.php
	fi
	if [ -z "$duckdb_php_autoload" ]; then
		duckdb_php_autoload="$PACKAGE_DIR/vendor/autoload.php"
	fi
	wp_duckdb_autoload="${WP_DUCKDB_AUTOLOAD:-$duckdb_php_autoload}"

	phpunit_command=(
		env
		WP_DUCKDB_TESTS=1
		"WP_DUCKDB_AUTOLOAD=$wp_duckdb_autoload"
		"DUCKDB_PHP_AUTOLOAD=$duckdb_php_autoload"
		php -d ffi.enable=1 ./vendor/bin/phpunit
		-c ./phpunit.xml.dist
		--filter "$failure_filter"
		--stop-on-error
		--stop-on-failure
	)

	echo "DuckDB cached failure cache: $CACHE_FILE"
	echo "DuckDB cached failure entries: $failure_count"
	echo "DuckDB cached failure max entries: $MAX_TESTS"
	echo "DuckDB cached failure timeout: ${TIMEOUT_SECONDS}s"
	echo "DuckDB cached failure filter: $failure_filter"
	printf 'DuckDB cached failure test: %s\n' "${selected_tests[@]}"
	print_command "${phpunit_command[@]}"

	if [ "$DRY_RUN" = '1' ]; then
		return
	fi

	[ -x "$PACKAGE_DIR/vendor/bin/phpunit" ] || fail "Missing PHPUnit binary: $PACKAGE_DIR/vendor/bin/phpunit"
	[ -f "$duckdb_php_autoload" ] || fail "DuckDB PHP autoload is not readable: $duckdb_php_autoload"
	[ -f "$wp_duckdb_autoload" ] || fail "WP_DUCKDB_AUTOLOAD is not readable: $wp_duckdb_autoload"

	set +e
	phpunit_output="$(
		cd "$PACKAGE_DIR" && run_with_timeout "$TIMEOUT_SECONDS" "${phpunit_command[@]}" 2>&1
	)"
	phpunit_status=$?
	set -e

	printf '%s\n' "$phpunit_output"

	if printf '%s\n' "$phpunit_output" | grep -q 'No tests executed!'; then
		fail 'Cached DuckDB failure filter matched zero PHPUnit tests.'
	fi

	return "$phpunit_status"
}

if [ "${1:-}" = '--help' ] || [ "${1:-}" = '-h' ]; then
	usage
	exit 0
fi

if [ "${1:-}" = '--dry-run' ]; then
	DRY_RUN=1
	shift
fi

if [ "${1:-}" != '' ]; then
	MODE="$1"
	shift
fi

if [ "$#" -gt 0 ]; then
	fail 'Unexpected arguments. Use run or validate.'
fi

is_positive_integer "$MAX_TESTS" || fail 'WP_DUCKDB_LAST_FAILURE_MAX_TESTS must be a positive integer.'
is_positive_integer "$TIMEOUT_SECONDS" || fail 'WP_DUCKDB_LAST_FAILURE_TIMEOUT must be a positive integer.'

case "$MODE" in
	run)
		run_last_failures
		;;
	validate)
		DRY_RUN=1
		run_last_failures
		;;
	*)
		usage >&2
		fail 'Mode must be run or validate.'
		;;
esac
