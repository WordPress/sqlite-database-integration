#!/bin/bash

##
# Run only the committed DuckDB WordPress recent-failure gates.
#
# This is intentionally bounded. By default it assumes the WordPress DuckDB
# Docker environment is already prepared, so a "fast-fail" command cannot
# silently turn into a long setup or full-suite run.
##

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd -P)"
PHPUNIT_LIST_FILE="$ROOT_DIR/.github/workflows/duckdb-wordpress-phpunit-fast-fail-tests.txt"
E2E_LIST_FILE="$ROOT_DIR/.github/workflows/duckdb-wordpress-e2e-fast-fail-specs.txt"

MODE="${WP_DUCKDB_FAST_FAIL_MODE:-phpunit}"
DRY_RUN="${WP_DUCKDB_FAST_FAIL_DRY_RUN:-0}"
PREPARED="${WP_DUCKDB_FAST_FAIL_PREPARED:-1}"
PHPUNIT_TIMEOUT_SECONDS="${WP_DUCKDB_FAST_FAIL_PHPUNIT_TIMEOUT:-90}"
E2E_TIMEOUT_SECONDS="${WP_DUCKDB_FAST_FAIL_E2E_TIMEOUT:-180}"
MIN_PHPUNIT_FILTERS="${WP_SQLITE_PHPUNIT_MIN_FILTER_PATTERNS:-9}"
PHPUNIT_MIN_TESTS="${WP_SQLITE_PHPUNIT_MIN_TESTS:-15}"
MIN_E2E_SPECS="${WP_DUCKDB_E2E_FAST_FAIL_MIN_SPECS:-4}"

fail() {
	echo "Error: $*" >&2
	exit 1
}

usage() {
	cat <<'EOF'
Usage: bin/wp-test-duckdb-fast-fail.sh [phpunit|e2e|all|validate]

Runs the committed DuckDB recent-failure gates instead of any full suite.

Environment:
  WP_DUCKDB_FAST_FAIL_DRY_RUN=1       Validate lists and print commands only.
  WP_DUCKDB_FAST_FAIL_PREPARED=0      Allow composer to prepare the DuckDB env.
  WP_DUCKDB_FAST_FAIL_PHPUNIT_TIMEOUT Seconds for the PHPUnit gate. Default: 90.
  WP_DUCKDB_FAST_FAIL_E2E_TIMEOUT     Seconds for the E2E gate. Default: 180.
  WP_SQLITE_REQUIRE_NATIVE_PARSER_EXTENSION=0
                                      Run the cheaper pre-native PHPUnit gate.
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

run_with_timeout() {
	local seconds="$1"
	shift

	if command -v timeout > /dev/null 2>&1; then
		timeout "${seconds}s" "$@"
		return $?
	fi

	local command_pid timer_pid timeout_file status
	timeout_file="${TMPDIR:-/tmp}/wp-duckdb-fast-fail-timeout-$$-$RANDOM"

	"$@" &
	command_pid=$!

	(
		sleep "$seconds"
		if kill -0 "$command_pid" 2> /dev/null; then
			: > "$timeout_file"
			kill -TERM "$command_pid" 2> /dev/null || true
			sleep 5
			kill -KILL "$command_pid" 2> /dev/null || true
		fi
	) &
	timer_pid=$!

	wait "$command_pid"
	status=$?

	kill "$timer_pid" 2> /dev/null || true
	wait "$timer_pid" 2> /dev/null || true

	if [ -f "$timeout_file" ]; then
		rm -f "$timeout_file"
		return 124
	fi

	rm -f "$timeout_file"
	return "$status"
}

read_phpunit_filter() {
	local invalid_entries count filter

	[ -f "$PHPUNIT_LIST_FILE" ] || fail "Missing PHPUnit fast-fail list: $PHPUNIT_LIST_FILE"
	is_positive_integer "$MIN_PHPUNIT_FILTERS" || fail 'WP_SQLITE_PHPUNIT_MIN_FILTER_PATTERNS must be a positive integer.'

	invalid_entries="$(
		awk '
			/^[[:space:]]*($|#)/ { next }
			{
				sub(/^[[:space:]]+/, "")
				sub(/[[:space:]]+$/, "")
				if ($0 !~ /^[A-Za-z_][A-Za-z0-9_]*(\\[A-Za-z_][A-Za-z0-9_]*)*::[A-Za-z_][A-Za-z0-9_]*$/) {
					print
				}
			}
		' "$PHPUNIT_LIST_FILE"
	)"

	if [ -n "$invalid_entries" ]; then
		printf '%s\n' "$invalid_entries" >&2
		fail "$PHPUNIT_LIST_FILE must contain literal PHPUnit Class::method names only."
	fi

	count="$(
		awk '
			/^[[:space:]]*($|#)/ { next }
			{
				sub(/^[[:space:]]+/, "")
				sub(/[[:space:]]+$/, "")
				if (seen[$0]++) {
					next
				}
				count++
			}
			END {
				print count + 0
			}
		' "$PHPUNIT_LIST_FILE"
	)"

	if [ "$count" -lt 1 ]; then
		fail "$PHPUNIT_LIST_FILE does not list any PHPUnit tests."
	fi

	if [ "$count" -lt "$MIN_PHPUNIT_FILTERS" ]; then
		fail "$PHPUNIT_LIST_FILE lists $count unique tests; expected at least $MIN_PHPUNIT_FILTERS."
	fi

	filter="$(
		awk '
			/^[[:space:]]*($|#)/ { next }
			{
				sub(/^[[:space:]]+/, "")
				sub(/[[:space:]]+$/, "")
				if (seen[$0]++) {
					next
				}
				printf "%s%s", separator, $0
				separator="\\|"
			}
		' "$PHPUNIT_LIST_FILE"
	)"

	printf '%s\n' "$count" "$filter"
}

read_e2e_specs() {
	local invalid_entries count

	[ -f "$E2E_LIST_FILE" ] || fail "Missing E2E fast-fail list: $E2E_LIST_FILE"
	is_positive_integer "$MIN_E2E_SPECS" || fail 'WP_DUCKDB_E2E_FAST_FAIL_MIN_SPECS must be a positive integer.'

	invalid_entries="$(
		awk '
			/^[[:space:]]*($|#)/ { next }
			{
				sub(/^[[:space:]]+/, "")
				sub(/[[:space:]]+$/, "")
				if (seen[$0]++) {
					next
				}
				if ($0 !~ /^tests\/e2e\/specs\/[A-Za-z0-9._-]+\.test\.js$/) {
					print
				}
			}
		' "$E2E_LIST_FILE"
	)"

	if [ -n "$invalid_entries" ]; then
		printf '%s\n' "$invalid_entries" >&2
		fail "$E2E_LIST_FILE must contain literal tests/e2e/specs/*.test.js paths only."
	fi

	count="$(
		awk '
			/^[[:space:]]*($|#)/ { next }
			{
				sub(/^[[:space:]]+/, "")
				sub(/[[:space:]]+$/, "")
				if (seen[$0]++) {
					next
				}
				count++
			}
			END {
				print count + 0
			}
		' "$E2E_LIST_FILE"
	)"

	if [ "$count" -lt 1 ]; then
		fail "$E2E_LIST_FILE does not list any E2E specs."
	fi

	if [ "$count" -lt "$MIN_E2E_SPECS" ]; then
		fail "$E2E_LIST_FILE lists $count unique specs; expected at least $MIN_E2E_SPECS."
	fi

	awk '
		/^[[:space:]]*($|#)/ { next }
		{
			sub(/^[[:space:]]+/, "")
			sub(/[[:space:]]+$/, "")
			if (seen[$0]++) {
				next
			}
			print
		}
	' "$E2E_LIST_FILE"
}

preflight_prepared_wordpress() {
	if [ "$PREPARED" != '1' ]; then
		return
	fi

	[ -f "$ROOT_DIR/wordpress/src/wp-load.php" ] \
		|| fail 'WordPress checkout is not prepared. Run composer run wp-test-ensure-env-duckdb, or set WP_DUCKDB_FAST_FAIL_PREPARED=0 to allow setup.'

	[ -f "$ROOT_DIR/wordpress/src/wp-content/db.php" ] \
		|| fail 'DuckDB db.php drop-in is missing. Run composer run wp-test-ensure-env-duckdb, or set WP_DUCKDB_FAST_FAIL_PREPARED=0 to allow setup.'

	grep -q "define( 'DB_ENGINE', 'duckdb' );" "$ROOT_DIR/wordpress/src/wp-content/db.php" \
		|| fail 'WordPress db.php drop-in is not configured for DB_ENGINE=duckdb.'
}

preflight_docker() {
	command -v docker > /dev/null 2>&1 || fail 'Docker is required for WordPress PHPUnit/E2E gates.'
	docker info > /dev/null 2>&1 || fail 'Docker is not available or not running.'
}

print_command() {
	printf 'Command:'
	printf ' %q' "$@"
	printf '\n'
}

run_phpunit_fast_fail() {
	local phpunit_count phpunit_filter phpunit_script
	local phpunit_command_string native_parser_required outer_timeout_seconds timing_label
	local duckdb_php_autoload phpunit_ensure_env_command phpunit_junit_basename
	local phpunit_junit_path phpunit_timing_label
	local phpunit_result
	local -a phpunit_wrapper_command

	phpunit_result="$(read_phpunit_filter)"
	phpunit_count="$(printf '%s\n' "$phpunit_result" | sed -n '1p')"
	phpunit_filter="$(printf '%s\n' "$phpunit_result" | sed -n '2p')"

	if [ "$PREPARED" = '1' ]; then
		phpunit_script='wp-test-php-duckdb-prepared'
		phpunit_ensure_env_command="${WP_SQLITE_PHPUNIT_ENSURE_ENV_COMMAND:-true}"
	else
		phpunit_script='wp-test-php-duckdb'
		phpunit_ensure_env_command="${WP_SQLITE_PHPUNIT_ENSURE_ENV_COMMAND:-composer run wp-test-ensure-env-duckdb}"
	fi

	case "$phpunit_filter" in
		*\'*)
			fail "$PHPUNIT_LIST_FILE generated a filter containing a single quote."
			;;
	esac

	native_parser_required="${WP_SQLITE_REQUIRE_NATIVE_PARSER_EXTENSION:-1}"
	case "$native_parser_required" in
		0)
			timing_label='duckdb-fast-fail-pre-native-local'
			;;
		1)
			timing_label='duckdb-fast-fail-local'
			;;
		*)
			fail 'WP_SQLITE_REQUIRE_NATIVE_PARSER_EXTENSION must be 0 or 1.'
			;;
	esac

	duckdb_php_autoload="${DUCKDB_PHP_AUTOLOAD:-$ROOT_DIR/packages/mysql-on-sqlite/vendor/autoload.php}"
	phpunit_junit_path="${WP_SQLITE_PHPUNIT_JUNIT_PATH:-${WP_DUCKDB_FAST_FAIL_PHPUNIT_JUNIT_PATH:-wordpress/phpunit-duckdb-fast-fail-local-results.xml}}"
	phpunit_junit_basename="${WP_DUCKDB_FAST_FAIL_PHPUNIT_JUNIT_BASENAME:-$(basename "$phpunit_junit_path")}"
	phpunit_timing_label="${WP_SQLITE_PHPUNIT_TIMING_LABEL:-$timing_label}"
	case "$phpunit_junit_basename" in
		''|*/*|*\\*|*[!A-Za-z0-9._-]*)
			fail 'PHPUnit JUnit basename must be a simple filename containing only letters, numbers, dots, underscores, and dashes.'
			;;
	esac
	case "$phpunit_junit_basename" in
		*.xml)
			;;
		*)
			fail 'PHPUnit JUnit basename must end in .xml.'
			;;
	esac
	phpunit_command_string="composer run ${phpunit_script} -- --log-junit=${phpunit_junit_basename} --verbose --stop-on-error --stop-on-failure --filter '${phpunit_filter}'"
	outer_timeout_seconds=$(( PHPUNIT_TIMEOUT_SECONDS + 30 ))
	phpunit_wrapper_command=(
		env
		"DUCKDB_PHP_AUTOLOAD=$duckdb_php_autoload"
		"WP_SQLITE_DUCKDB_CHILD_DIAGNOSTICS=${WP_SQLITE_DUCKDB_CHILD_DIAGNOSTICS:-0}"
		"WP_SQLITE_DUCKDB_CHILD_DIAGNOSTICS_VERBOSE=${WP_SQLITE_DUCKDB_CHILD_DIAGNOSTICS_VERBOSE:-0}"
		"WP_SQLITE_DUCKDB_CHILD_DIAGNOSTICS_STDERR=${WP_SQLITE_DUCKDB_CHILD_DIAGNOSTICS_STDERR:-0}"
		"WP_SQLITE_DUCKDB_CHILD_DB_COPY=${WP_SQLITE_DUCKDB_CHILD_DB_COPY:-1}"
		"WP_SQLITE_DUCKDB_PREPARE_OBJECT_DIAGNOSTICS=${WP_SQLITE_DUCKDB_PREPARE_OBJECT_DIAGNOSTICS:-0}"
		"WP_DUCKDB_QUERY_PROFILE=${WP_DUCKDB_QUERY_PROFILE:-0}"
		"WP_DUCKDB_QUERY_PROFILE_INTERVAL=${WP_DUCKDB_QUERY_PROFILE_INTERVAL:-0}"
		"WP_SQLITE_ENSURE_PHPUNIT_COMPATIBILITY=${WP_SQLITE_ENSURE_PHPUNIT_COMPATIBILITY:-1}"
		"WP_SQLITE_DISABLE_EXPECTED_RESULTS=${WP_SQLITE_DISABLE_EXPECTED_RESULTS:-0}"
		"WP_SQLITE_PHPUNIT_ENSURE_ENV_COMMAND=$phpunit_ensure_env_command"
		"WP_SQLITE_NATIVE_PARSER_VERIFY_TIMEOUT_SECONDS=${WP_SQLITE_NATIVE_PARSER_VERIFY_TIMEOUT_SECONDS:-60}"
		"WP_SQLITE_PHPUNIT_REQUIRED_FILTERS_FILE=${WP_SQLITE_PHPUNIT_REQUIRED_FILTERS_FILE:-$PHPUNIT_LIST_FILE}"
		"WP_SQLITE_PHPUNIT_COMMAND=$phpunit_command_string"
		"WP_SQLITE_REQUIRE_NATIVE_PARSER_EXTENSION=$native_parser_required"
		"WP_SQLITE_PHPUNIT_TIMING_LABEL=$phpunit_timing_label"
		"WP_SQLITE_PHPUNIT_MAX_SECONDS=$PHPUNIT_TIMEOUT_SECONDS"
		"WP_SQLITE_PHPUNIT_MIN_TESTS=$PHPUNIT_MIN_TESTS"
		"WP_SQLITE_IGNORE_MISSING_EXPECTED_RESULTS=1"
		"WP_SQLITE_PHPUNIT_JUNIT_PATH=$phpunit_junit_path"
		node .github/workflows/wp-tests-phpunit-run.js
	)

	echo "DuckDB PHPUnit fast-fail list: $PHPUNIT_LIST_FILE"
	echo "DuckDB PHPUnit fast-fail entries: $phpunit_count"
	echo "DuckDB PHPUnit fast-fail timeout: ${PHPUNIT_TIMEOUT_SECONDS}s"
	echo "DuckDB PHPUnit fast-fail min tests: $PHPUNIT_MIN_TESTS"
	echo "DuckDB PHPUnit native parser required: $native_parser_required"
	echo "DuckDB PHPUnit timing label: $phpunit_timing_label"
	echo "DuckDB PHPUnit JUnit path: $phpunit_junit_path"
	echo "DuckDB PHPUnit command: $phpunit_command_string"
	print_command "${phpunit_wrapper_command[@]}"

	if [ "$DRY_RUN" = '1' ]; then
		return
	fi

	preflight_prepared_wordpress
	preflight_docker
	(
		cd "$ROOT_DIR"
		run_with_timeout "$outer_timeout_seconds" "${phpunit_wrapper_command[@]}"
	)
}

run_e2e_fast_fail() {
	local -a e2e_specs e2e_command
	local spec

	mapfile -t e2e_specs < <(read_e2e_specs)

	for spec in "${e2e_specs[@]}"; do
		if [ "$DRY_RUN" != '1' ] && [ ! -f "$ROOT_DIR/wordpress/$spec" ]; then
			fail "Missing WordPress E2E spec: wordpress/$spec"
		fi
	done

	e2e_command=(
		npm --prefix wordpress run test:e2e --
		"${e2e_specs[@]}"
	)

	echo "DuckDB E2E fast-fail list: $E2E_LIST_FILE"
	echo "DuckDB E2E fast-fail entries: ${#e2e_specs[@]}"
	echo "DuckDB E2E fast-fail timeout: ${E2E_TIMEOUT_SECONDS}s"
	printf 'DuckDB E2E fast-fail spec: %s\n' "${e2e_specs[@]}"
	print_command "${e2e_command[@]}"

	if [ "$DRY_RUN" = '1' ]; then
		return
	fi

	preflight_prepared_wordpress
	preflight_docker
	(
		cd "$ROOT_DIR"
		run_with_timeout "$E2E_TIMEOUT_SECONDS" "${e2e_command[@]}"
	)
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
	fail 'Unexpected arguments. Use phpunit, e2e, or all.'
fi

is_positive_integer "$PHPUNIT_TIMEOUT_SECONDS" || fail 'WP_DUCKDB_FAST_FAIL_PHPUNIT_TIMEOUT must be a positive integer.'
is_positive_integer "$E2E_TIMEOUT_SECONDS" || fail 'WP_DUCKDB_FAST_FAIL_E2E_TIMEOUT must be a positive integer.'
is_positive_integer "$PHPUNIT_MIN_TESTS" || fail 'WP_SQLITE_PHPUNIT_MIN_TESTS must be a positive integer.'

case "$MODE" in
	phpunit)
		run_phpunit_fast_fail
		;;
	e2e)
		run_e2e_fast_fail
		;;
	all)
		run_phpunit_fast_fail
		run_e2e_fast_fail
		;;
	validate)
		DRY_RUN=1
		run_phpunit_fast_fail
		run_e2e_fast_fail
		;;
	*)
		usage >&2
		fail 'Mode must be phpunit, e2e, all, or validate.'
		;;
esac
