#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
WP_DIR="$ROOT_DIR/wordpress"
COMPOSE_OVERRIDE="$WP_DIR/docker-compose.override.yml"
RUNTIME_DIR="$ROOT_DIR/tmp-native-extension"
EXTENSION_SOURCE_VOLUME="      - ../packages/php-ext-wp-mysql-parser:/var/native-parser-extension-src"
EXTENSION_RUNTIME_VOLUME="      - ../tmp-native-extension:/var/native-parser-extension:ro"
EXTENSION_INI_VOLUME="      - ../tmp-native-extension/wp-mysql-parser.ini:/usr/local/etc/php/conf.d/wp-mysql-parser.ini:ro"
WP_DUCKDB_NATIVE_EXTENSION_BUILD_TIMEOUT_SECONDS="${WP_DUCKDB_NATIVE_EXTENSION_BUILD_TIMEOUT_SECONDS:-300}"
WP_DUCKDB_NATIVE_EXTENSION_VERIFY_TIMEOUT_SECONDS="${WP_DUCKDB_NATIVE_EXTENSION_VERIFY_TIMEOUT_SECONDS:-60}"

if [ ! -f "$COMPOSE_OVERRIDE" ]; then
	echo "Missing $COMPOSE_OVERRIDE. Run composer run wp-setup first." >&2
	exit 1
fi

native_phase_id() {
	printf '%s' "$1" | tr '[:upper:]' '[:lower:]' | tr ' ' '_' | tr -cd '[:alnum:]_-'
}

emit_native_progress() {
	local phase="$1"
	local status="$2"
	local elapsed_seconds="$3"
	local timeout_seconds="$4"
	local message

	message="WP_DUCKDB_NATIVE_EXTENSION_PROGRESS phase=$phase status=$status elapsed_seconds=$elapsed_seconds timeout_seconds=$timeout_seconds"
	printf '%s\n' "$message"

	if [ "${GITHUB_ACTIONS:-}" = "true" ]; then
		printf '::notice title=WordPress native parser setup::%s\n' "$message"
	fi
}

run_with_timeout() {
	local seconds="$1"
	shift

	if command -v timeout > /dev/null; then
		timeout "${seconds}s" "$@"
		return $?
	fi

	"$@"
}

run_phase() {
	local label="$1"
	local seconds="$2"
	local status started_at ended_at elapsed_seconds phase_id phase_status
	shift 2

	phase_id="$(native_phase_id "$label")"
	started_at="$(date +%s)"
	emit_native_progress "$phase_id" start 0 "$seconds"

	set +e
	run_with_timeout "$seconds" "$@"
	status=$?
	set -e

	ended_at="$(date +%s)"
	elapsed_seconds=$(( ended_at - started_at ))
	phase_status="success"
	if [ "$status" -eq 124 ]; then
		phase_status="timeout"
		echo "Error: $label timed out after $seconds seconds." >&2
	elif [ "$status" -ne 0 ]; then
		phase_status="failure"
	fi
	emit_native_progress "$phase_id" "$phase_status" "$elapsed_seconds" "$seconds"

	return "$status"
}

add_volume_to_service() {
	local service="$1"
	local volume="$2"

	node - "$COMPOSE_OVERRIDE" "$service" "$volume" <<'NODE'
const fs = require( 'fs' );

const file = process.argv[2];
const service = process.argv[3];
const volume = process.argv[4];
const lines = fs.readFileSync( file, 'utf8' ).split( '\n' );

const serviceIndex = lines.findIndex( line => line === `  ${ service }:` );
if ( serviceIndex === -1 ) {
	throw new Error( `Service ${ service } not found in ${ file }.` );
}

let serviceEnd = lines.length;
for ( let i = serviceIndex + 1; i < lines.length; i++ ) {
	if ( /^  [A-Za-z0-9_-]+:/.test( lines[i] ) ) {
		serviceEnd = i;
		break;
	}
}

if ( lines.slice( serviceIndex, serviceEnd ).some( line => line.trim() === volume.trim() ) ) {
	process.exit( 0 );
}

let volumesIndex = -1;
for ( let i = serviceIndex + 1; i < serviceEnd; i++ ) {
	if ( lines[i].trim() === 'volumes:' ) {
		volumesIndex = i;
		break;
	}
}

if ( volumesIndex === -1 ) {
	throw new Error( `Service ${ service } has no volumes list in ${ file }.` );
}

let insertAt = volumesIndex + 1;
while ( insertAt < serviceEnd && /^\s{6}- /.test( lines[insertAt] ) ) {
	insertAt++;
}

lines.splice( insertAt, 0, volume );
fs.writeFileSync( file, lines.join( '\n' ) );
NODE
}

add_volume_to_service php "$EXTENSION_SOURCE_VOLUME"
add_volume_to_service cli "$EXTENSION_SOURCE_VOLUME"

cat > "$WP_DIR/native-build-extension.sh" <<'EOF'
#!/bin/sh
set -eu

apt-get update
apt-get install -y --no-install-recommends ca-certificates curl build-essential clang libclang-dev pkg-config

if [ ! -x "$HOME/.cargo/bin/cargo" ]; then
	curl --proto '=https' --tlsv1.2 -sSf https://sh.rustup.rs | sh -s -- -y --profile minimal --default-toolchain stable
fi

. "$HOME/.cargo/env"

PHP_CONFIG="$(command -v php-config)"
export PHP_CONFIG

LIBCLANG_SO="$(find /usr/lib /usr/local/lib -name 'libclang.so*' 2>/dev/null | head -n 1)"
if [ -z "$LIBCLANG_SO" ]; then
	echo "Unable to locate libclang.so after installing libclang-dev." >&2
	exit 1
fi

LIBCLANG_PATH="$(dirname "$LIBCLANG_SO")"
export LIBCLANG_PATH

cd /var/native-parser-extension-src
cargo build --release
EOF

chmod +x "$WP_DIR/native-build-extension.sh"

run_phase \
	'Build native parser extension in WordPress PHP container' \
	"$WP_DUCKDB_NATIVE_EXTENSION_BUILD_TIMEOUT_SECONDS" \
	bash -c 'cd "$1" && node tools/local-env/scripts/docker.js run --rm php sh /var/www/native-build-extension.sh' bash "$WP_DIR"

mkdir -p "$RUNTIME_DIR"
cp "$ROOT_DIR/packages/php-ext-wp-mysql-parser/target/release/libwp_mysql_parser.so" "$RUNTIME_DIR/libwp_mysql_parser.so"
printf '%s\n' 'extension=/var/native-parser-extension/libwp_mysql_parser.so' > "$RUNTIME_DIR/wp-mysql-parser.ini"

add_volume_to_service php "$EXTENSION_RUNTIME_VOLUME"
add_volume_to_service cli "$EXTENSION_RUNTIME_VOLUME"
add_volume_to_service php "$EXTENSION_INI_VOLUME"
add_volume_to_service cli "$EXTENSION_INI_VOLUME"

cat > "$WP_DIR/native-verify-extension.php" <<'EOF'
<?php
require_once '/var/www/src/wp-content/plugins/sqlite-database-integration/wp-includes/database/load.php';

function wp_sqlite_native_parser_verification_fail( string $message ): void {
	fwrite( STDERR, $message . "\n" );
	exit( 1 );
}

function wp_sqlite_assert_native_parser_delegate( WP_MySQL_Parser $parser, string $message ): void {
	$parser_reflection = new ReflectionObject( $parser );
	if ( ! $parser_reflection->hasProperty( 'native' ) ) {
		wp_sqlite_native_parser_verification_fail( $message );
	}

	$native_property = $parser_reflection->getProperty( 'native' );
	$native_property->setAccessible( true );
	if ( ! ( $native_property->getValue( $parser ) instanceof WP_MySQL_Native_Parser ) ) {
		wp_sqlite_native_parser_verification_fail( $message );
	}
}

$lexer = new WP_MySQL_Lexer( 'SELECT 1' );
if ( ! ( $lexer instanceof WP_MySQL_Native_Lexer ) ) {
	wp_sqlite_native_parser_verification_fail( 'Native lexer is not available in the WordPress PHP test container.' );
}

$tokens  = $lexer->native_token_stream();
$rules   = include '/var/www/src/wp-content/plugins/sqlite-database-integration/wp-includes/database/mysql/mysql-grammar.php';
$grammar = new WP_Parser_Grammar( $rules );
$parser  = new WP_MySQL_Parser( $grammar, $tokens );
wp_sqlite_assert_native_parser_delegate( $parser, 'WordPress PHP test container did not select the native parser delegate.' );

$parser_ast = $parser->parse();
if ( ! ( $parser_ast instanceof WP_MySQL_Native_Parser_Node ) ) {
	wp_sqlite_native_parser_verification_fail( 'Native parser did not produce a native-backed AST in the WordPress PHP test container.' );
}

$driver = new WP_PDO_MySQL_On_SQLite( 'mysql-on-sqlite:path=:memory:;dbname=wp;' );
$parser = $driver->create_parser( 'SELECT 1' );
wp_sqlite_assert_native_parser_delegate( $parser, 'WordPress PHP test container SQLite driver did not create a native parser delegate.' );
$parser->next_query();
$ast = $parser->get_query_ast();

if ( ! ( $ast instanceof WP_MySQL_Native_Parser_Node ) ) {
	wp_sqlite_native_parser_verification_fail( 'WordPress PHP test container did not select the native-backed AST.' );
}

$reflection = new ReflectionObject( $ast );
if ( $reflection->hasProperty( 'native_ast' ) || $reflection->hasProperty( 'native_node_index' ) ) {
	wp_sqlite_native_parser_verification_fail( 'Native wrapper still stores Rust AST handle properties.' );
}

$first = $ast->get_first_child_node();
if ( ! ( $first instanceof WP_MySQL_Native_Parser_Node ) ) {
	wp_sqlite_native_parser_verification_fail( 'Native wrapper did not return a native-backed child node.' );
}

if ( $first !== $ast->get_first_child_node() ) {
	wp_sqlite_native_parser_verification_fail( 'Native wrapper identity is not stable across reads.' );
}

$synthetic = new WP_Parser_Node( 0, 'synthetic' );
$first->append_child( $synthetic );
$same_first = $ast->get_first_child_node();
if ( $same_first !== $first || ! in_array( $synthetic, $same_first->get_children(), true ) ) {
	wp_sqlite_native_parser_verification_fail( 'Materialized native wrapper was lost from the parent cache.' );
}
EOF

node - "$WP_DIR/tests/phpunit/includes/bootstrap.php" <<'NODE'
const fs = require( 'fs' );

const file = process.argv[2];
const marker = "require_once ABSPATH . 'wp-settings.php';";
const guard = [
	'/*',
	' * Native parser extension guard. This file is generated by the SQLite integration workflow.',
	' */',
	"require_once dirname( __DIR__, 3 ) . '/native-verify-extension.php';",
].join( '\n' );

let contents = fs.readFileSync( file, 'utf8' );

if ( contents.includes( guard ) ) {
	process.exit( 0 );
}

if ( ! contents.includes( marker ) ) {
	throw new Error( `Unable to find WordPress bootstrap marker in ${ file }.` );
}

contents = contents.replace( marker, `${ marker }\n\n${ guard }` );
fs.writeFileSync( file, contents );
NODE

run_phase \
	'Verify native parser extension module is loaded' \
	"$WP_DUCKDB_NATIVE_EXTENSION_VERIFY_TIMEOUT_SECONDS" \
	bash -c 'cd "$1" && node tools/local-env/scripts/docker.js run --rm php php -m | grep -qx wp_mysql_parser' bash "$WP_DIR"
run_phase \
	'Verify native parser extension runtime behavior' \
	"$WP_DUCKDB_NATIVE_EXTENSION_VERIFY_TIMEOUT_SECONDS" \
	bash -c 'cd "$1" && node tools/local-env/scripts/docker.js run --rm php php /var/www/native-verify-extension.php' bash "$WP_DIR"
