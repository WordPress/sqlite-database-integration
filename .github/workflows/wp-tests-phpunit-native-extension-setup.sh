#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
WP_DIR="$ROOT_DIR/wordpress"
COMPOSE_OVERRIDE="$WP_DIR/docker-compose.override.yml"
RUNTIME_DIR="$ROOT_DIR/tmp-native-extension"
EXTENSION_SOURCE_VOLUME="      - ../packages/php-ext-wp-mysql-parser:/var/native-parser-extension-src"
EXTENSION_RUNTIME_VOLUME="      - ../tmp-native-extension:/var/native-parser-extension:ro"
EXTENSION_INI_VOLUME="      - ../tmp-native-extension/wp-mysql-parser.ini:/usr/local/etc/php/conf.d/wp-mysql-parser.ini:ro"

if [ ! -f "$COMPOSE_OVERRIDE" ]; then
	echo "Missing $COMPOSE_OVERRIDE. Run composer run wp-setup first." >&2
	exit 1
fi

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
cargo build
EOF

chmod +x "$WP_DIR/native-build-extension.sh"

cd "$WP_DIR"
node tools/local-env/scripts/docker.js run --rm php sh /var/www/native-build-extension.sh

mkdir -p "$RUNTIME_DIR"
cp "$ROOT_DIR/packages/php-ext-wp-mysql-parser/target/debug/libwp_mysql_parser.so" "$RUNTIME_DIR/libwp_mysql_parser.so"
printf '%s\n' 'extension=/var/native-parser-extension/libwp_mysql_parser.so' > "$RUNTIME_DIR/wp-mysql-parser.ini"

add_volume_to_service php "$EXTENSION_RUNTIME_VOLUME"
add_volume_to_service cli "$EXTENSION_RUNTIME_VOLUME"
add_volume_to_service php "$EXTENSION_INI_VOLUME"
add_volume_to_service cli "$EXTENSION_INI_VOLUME"

cat > "$WP_DIR/native-verify-extension.php" <<'EOF'
<?php
require_once '/var/www/src/wp-content/plugins/sqlite-database-integration/wp-includes/database/load.php';

$lexer = new WP_MySQL_Lexer( 'SELECT 1' );
if ( ! ( $lexer instanceof WP_MySQL_Native_Lexer ) ) {
	fwrite( STDERR, "Native lexer is not available in the WordPress PHP test container.\n" );
	exit( 1 );
}

$tokens  = $lexer->native_token_stream();
$rules   = include '/var/www/src/wp-content/plugins/sqlite-database-integration/wp-includes/database/mysql/mysql-grammar.php';
$grammar = new WP_Parser_Grammar( $rules );
$parser  = new WP_MySQL_Parser( $grammar, $tokens );
$parser_reflection = new ReflectionObject( $parser );
if ( ! $parser_reflection->hasProperty( 'native' ) ) {
	fwrite( STDERR, "WordPress PHP test container did not select the native parser delegate.\n" );
	exit( 1 );
}
$native_property = $parser_reflection->getProperty( 'native' );
$native_property->setAccessible( true );
if ( ! ( $native_property->getValue( $parser ) instanceof WP_MySQL_Native_Parser ) ) {
	fwrite( STDERR, "WordPress PHP test container did not select the native parser delegate.\n" );
	exit( 1 );
}

$parser_ast = $parser->parse();
if ( ! ( $parser_ast instanceof WP_MySQL_Native_Parser_Node ) ) {
	fwrite( STDERR, "Native parser did not produce a native-backed AST in the WordPress PHP test container.\n" );
	exit( 1 );
}

$driver = new WP_PDO_MySQL_On_SQLite( 'mysql-on-sqlite:path=:memory:;dbname=wp;' );
$parser = $driver->create_parser( 'SELECT 1' );
$parser_reflection = new ReflectionObject( $parser );
if ( ! $parser_reflection->hasProperty( 'native' ) ) {
	fwrite( STDERR, "WordPress PHP test container SQLite driver did not create a native parser delegate.\n" );
	exit( 1 );
}
$native_property = $parser_reflection->getProperty( 'native' );
$native_property->setAccessible( true );
if ( ! ( $native_property->getValue( $parser ) instanceof WP_MySQL_Native_Parser ) ) {
	fwrite( STDERR, "WordPress PHP test container SQLite driver did not create a native parser delegate.\n" );
	exit( 1 );
}
$parser->next_query();
$ast = $parser->get_query_ast();

if ( ! ( $ast instanceof WP_MySQL_Native_Parser_Node ) ) {
	fwrite( STDERR, "WordPress PHP test container did not select the native-backed AST.\n" );
	exit( 1 );
}

$reflection = new ReflectionObject( $ast );
if ( $reflection->hasProperty( 'native_ast' ) || $reflection->hasProperty( 'native_node_index' ) ) {
	fwrite( STDERR, "Native wrapper still stores Rust AST handle properties.\n" );
	exit( 1 );
}

$first = $ast->get_first_child_node();
if ( ! ( $first instanceof WP_MySQL_Native_Parser_Node ) ) {
	fwrite( STDERR, "Native wrapper did not return a native-backed child node.\n" );
	exit( 1 );
}

if ( $first !== $ast->get_first_child_node() ) {
	fwrite( STDERR, "Native wrapper identity is not stable across reads.\n" );
	exit( 1 );
}

$synthetic = new WP_Parser_Node( 0, 'synthetic' );
$first->append_child( $synthetic );
$same_first = $ast->get_first_child_node();
if ( $same_first !== $first || ! in_array( $synthetic, $same_first->get_children(), true ) ) {
	fwrite( STDERR, "Materialized native wrapper was lost from the parent cache.\n" );
	exit( 1 );
}
EOF

node - "$WP_DIR/tests/phpunit/includes/bootstrap.php" <<'NODE'
const fs = require( 'fs' );

const file = process.argv[2];
const marker = "require_once ABSPATH . 'wp-settings.php';";
const guard = [
	'/*',
	' * Native parser CI guard. This file is generated by the SQLite integration workflow.',
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

node tools/local-env/scripts/docker.js run --rm php php -m | grep -qx 'wp_mysql_parser'
node tools/local-env/scripts/docker.js run --rm php php /var/www/native-verify-extension.php
