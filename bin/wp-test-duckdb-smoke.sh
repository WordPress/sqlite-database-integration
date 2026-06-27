#!/bin/bash

##
# Run a fast Docker-backed smoke test against the WordPress DuckDB drop-in.
##

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd -P)"
WP_DIR="$ROOT_DIR/wordpress"
SMOKE_TIMEOUT="${WP_DUCKDB_SMOKE_TIMEOUT:-240}"
COMPOSE_ARGS=( -f docker-compose.yml -f docker-compose.override.yml )

fail() {
	echo "Error: $*" >&2
	exit 1
}

run_with_timeout() {
	if command -v timeout > /dev/null 2>&1; then
		timeout "$SMOKE_TIMEOUT" "$@"
		return
	fi

	"$@"
}

if [ ! -d "$WP_DIR" ]; then
	fail 'WordPress test directory is missing. Run composer run wp-test-ensure-env-duckdb first.'
fi

if [ ! -f "$WP_DIR/docker-compose.override.yml" ]; then
	fail 'WordPress Docker override is missing. Run composer run wp-test-ensure-env-duckdb first.'
fi

if [ ! -f "$WP_DIR/src/wp-load.php" ]; then
	fail 'WordPress checkout is incomplete. Run composer run wp-test-ensure-env-duckdb first.'
fi

if [ ! -f "$WP_DIR/src/wp-content/db.php" ]; then
	fail 'WordPress db.php drop-in is missing after DuckDB setup.'
fi

if ! grep -q "define( 'DB_ENGINE', 'duckdb' );" "$WP_DIR/src/wp-content/db.php"; then
	fail 'WordPress db.php drop-in is not configured for DB_ENGINE=duckdb.'
fi

echo 'Running WordPress DuckDB smoke gate...'
echo "Using timeout: ${SMOKE_TIMEOUT}s"

(
	cd "$WP_DIR"

	echo 'Verifying Docker compose services...'
	docker compose "${COMPOSE_ARGS[@]}" config --services | grep -qx 'cli' \
		|| fail 'WordPress Docker compose does not define the cli service.'

	echo 'Verifying PHP FFI in the WordPress CLI container...'
	run_with_timeout docker compose "${COMPOSE_ARGS[@]}" run --rm cli php -r '
if ( ! extension_loaded( "ffi" ) ) {
	fwrite( STDERR, "Error: PHP FFI extension is not loaded in the WordPress CLI container.\n" );
	exit( 1 );
}

$ffi_enabled = strtolower( (string) ini_get( "ffi.enable" ) );
if ( ! in_array( $ffi_enabled, array( "1", "on", "true" ), true ) ) {
	fwrite( STDERR, "Error: PHP FFI is not enabled in the WordPress CLI container. ffi.enable={$ffi_enabled}\n" );
	exit( 1 );
}
'

	echo 'Loading WordPress and executing a DuckDB query round trip through $wpdb...'
	run_with_timeout docker compose "${COMPOSE_ARGS[@]}" run --rm cli wp --path=/var/www/src eval '
global $wpdb;

$diagnostics = array(
	"db_engine"  => defined( "DB_ENGINE" ) ? DB_ENGINE : null,
	"wpdb_class" => is_object( $wpdb ) ? get_class( $wpdb ) : gettype( $wpdb ),
	"last_error" => is_object( $wpdb ) && isset( $wpdb->last_error ) ? (string) $wpdb->last_error : null,
);

if ( ! defined( "DB_ENGINE" ) || "duckdb" !== DB_ENGINE ) {
	fwrite( STDERR, "Error: WordPress did not load with DB_ENGINE=duckdb.\n" );
	fwrite( STDERR, wp_json_encode( $diagnostics, JSON_UNESCAPED_SLASHES ) . "\n" );
	exit( 1 );
}

if ( ! class_exists( "WP_DuckDB_DB" ) || ! $wpdb instanceof WP_DuckDB_DB ) {
	fwrite( STDERR, "Error: WordPress did not load the DuckDB wpdb drop-in.\n" );
	fwrite( STDERR, wp_json_encode( $diagnostics, JSON_UNESCAPED_SLASHES ) . "\n" );
	exit( 1 );
}

$table = $wpdb->prefix . "duckdb_smoke_gate";
$quoted_table = "`" . str_replace( "`", "``", $table ) . "`";

$wpdb->query( "DROP TABLE IF EXISTS {$quoted_table}" );
$create = $wpdb->query( "CREATE TABLE {$quoted_table} (id INTEGER, note VARCHAR(20))" );
$insert = $wpdb->query( "INSERT INTO {$quoted_table} VALUES (1, '\''ok'\'')" );
$rows   = $wpdb->get_results( "SELECT id, note FROM {$quoted_table} ORDER BY id", ARRAY_A );
$wpdb->query( "DROP TABLE IF EXISTS {$quoted_table}" );

$diagnostics["create"] = $create;
$diagnostics["insert"] = $insert;
$diagnostics["rows"] = $rows;
$diagnostics["last_error"] = $wpdb->last_error;

$has_expected_row = is_array( $rows )
	&& 1 === count( $rows )
	&& isset( $rows[0]["id"], $rows[0]["note"] )
	&& "1" === (string) $rows[0]["id"]
	&& "ok" === (string) $rows[0]["note"];

if ( true !== $create || 1 !== $insert || ! $has_expected_row || "" !== $wpdb->last_error ) {
	fwrite( STDERR, "Error: DuckDB smoke query failed.\n" );
	fwrite( STDERR, wp_json_encode( $diagnostics, JSON_UNESCAPED_SLASHES ) . "\n" );
	exit( 1 );
}

echo wp_json_encode( $diagnostics, JSON_UNESCAPED_SLASHES ) . "\n";
'
)

echo 'WordPress DuckDB smoke gate passed.'
