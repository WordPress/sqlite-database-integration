#!/bin/bash

##
# Run a non-Docker smoke test against the generated WordPress DuckDB drop-in.
##

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd -P)"
WP_DIR="$ROOT_DIR/wordpress"
WP_VERSION="${WP_DUCKDB_LOCAL_SMOKE_WP_VERSION:-6.7.2}"
DUCKDB_PHP_AUTOLOAD="${DUCKDB_PHP_AUTOLOAD:-}"

insert_duckdb_autoload_constant() {
	local file="$1"
	local autoload="$2"

	php -r '
$file     = $argv[1];
$autoload = $argv[2];
$contents = file_get_contents( $file );
$needle   = "require_once \$sqlite_plugin_implementation_folder_path . " . chr(39) . "/wp-includes/db.php" . chr(39) . ";";
$define   = "if ( ! defined( " . chr(39) . "DUCKDB_PHP_AUTOLOAD" . chr(39) . " ) ) {\n\tdefine( " . chr(39) . "DUCKDB_PHP_AUTOLOAD" . chr(39) . ", " . var_export( $autoload, true ) . " );\n}\n\n";

if ( false === strpos( $contents, $needle ) ) {
	fwrite( STDERR, "Error: Could not find DuckDB drop-in insertion point.\n" );
	exit( 1 );
}

file_put_contents( $file, str_replace( $needle, $define . $needle, $contents ) );
' "$file" "$autoload"
}

ensure_wordpress_checkout() {
	if [ -f "$WP_DIR/src/wp-load.php" ] && [ -f "$WP_DIR/src/wp-includes/class-wpdb.php" ]; then
		return
	fi

	command -v git > /dev/null 2>&1 || {
		echo 'Error: Git is required to prepare the WordPress smoke checkout.' >&2
		exit 1
	}

	rm -rf "$WP_DIR"
	git clone --depth 1 --branch "$WP_VERSION" https://github.com/WordPress/wordpress-develop.git "$WP_DIR"
}

write_duckdb_dropin() {
	mkdir -p "$WP_DIR/src/wp-content"
	cp "$ROOT_DIR/packages/plugin-sqlite-database-integration/db-duckdb.copy" "$WP_DIR/src/wp-content/db.php"
	sed -i.bak "s#'{SQLITE_IMPLEMENTATION_FOLDER_PATH}'#__DIR__.'/plugins/sqlite-database-integration'#g" "$WP_DIR/src/wp-content/db.php"
	rm -f "$WP_DIR/src/wp-content/db.php.bak"
	insert_duckdb_autoload_constant "$WP_DIR/src/wp-content/db.php" "$DUCKDB_PHP_AUTOLOAD"
}

if [ -z "$DUCKDB_PHP_AUTOLOAD" ]; then
	echo 'Error: DUCKDB_PHP_AUTOLOAD must point to the DuckDB PHP runtime autoload file.' >&2
	exit 1
fi

case "$DUCKDB_PHP_AUTOLOAD" in
	/*)
		;;
	*)
		echo 'Error: DUCKDB_PHP_AUTOLOAD must be an absolute path.' >&2
		exit 1
		;;
esac

if [ ! -f "$DUCKDB_PHP_AUTOLOAD" ]; then
	echo "Error: DUCKDB_PHP_AUTOLOAD must point to an existing file: $DUCKDB_PHP_AUTOLOAD" >&2
	exit 1
fi

DUCKDB_PHP_AUTOLOAD="$(cd "$(dirname "$DUCKDB_PHP_AUTOLOAD")" && pwd -P)/$(basename "$DUCKDB_PHP_AUTOLOAD")"

ensure_wordpress_checkout

if [ ! -f "$WP_DIR/src/wp-load.php" ] || \
	[ ! -f "$WP_DIR/src/wp-content/db.php" ] || \
	! grep -q "define( 'DB_ENGINE', 'duckdb' );" "$WP_DIR/src/wp-content/db.php" 2>/dev/null || \
	! grep -Fq "$DUCKDB_PHP_AUTOLOAD" "$WP_DIR/src/wp-content/db.php" 2>/dev/null; then
	write_duckdb_dropin
fi

if [ ! -f "$WP_DIR/src/wp-includes/class-wpdb.php" ]; then
	echo 'Error: WordPress checkout is incomplete after setup.' >&2
	exit 1
fi

PLUGIN_DIR="$WP_DIR/src/wp-content/plugins/sqlite-database-integration"
rm -rf "$PLUGIN_DIR"
mkdir -p "$(dirname "$PLUGIN_DIR")"
cp -R "$ROOT_DIR/packages/plugin-sqlite-database-integration" "$PLUGIN_DIR"
rm -rf "$PLUGIN_DIR/wp-includes/database"
ln -s "$ROOT_DIR/packages/mysql-on-sqlite/src" "$PLUGIN_DIR/wp-includes/database"

rm -rf \
	"$WP_DIR/src/wp-content/database/.ht.duckdb" \
	"$WP_DIR/src/wp-content/database/.ht.duckdb.wal"

WP_DUCKDB_SMOKE_ROOT="$ROOT_DIR" php -d ffi.enable=1 <<'PHP'
<?php
$root = getenv( 'WP_DUCKDB_SMOKE_ROOT' );
if ( ! is_string( $root ) || '' === $root ) {
	fwrite( STDERR, "Error: WP_DUCKDB_SMOKE_ROOT is not set.\n" );
	exit( 1 );
}

function wp_duckdb_local_smoke_env_enabled( string $name ): bool {
	$value = getenv( $name );
	if ( false === $value ) {
		return false;
	}
	return in_array( strtolower( trim( $value ) ), array( '1', 'true', 'yes', 'on' ), true );
}

function wp_duckdb_local_smoke_positive_int_env( string $name, int $default ): int {
	$value = getenv( $name );
	if ( false === $value || '' === trim( $value ) ) {
		return $default;
	}
	if ( ! ctype_digit( $value ) || (int) $value < 1 ) {
		throw new RuntimeException( "{$name} must be a positive integer." );
	}
	return (int) $value;
}

function wp_duckdb_local_smoke_positive_float_env( string $name ): ?float {
	$value = getenv( $name );
	if ( false === $value || '' === trim( $value ) ) {
		return null;
	}
	if ( ! is_numeric( $value ) || (float) $value <= 0.0 ) {
		throw new RuntimeException( "{$name} must be a positive number." );
	}
	return (float) $value;
}

function wp_duckdb_local_smoke_normalize_rows( array $rows ): array {
	foreach ( $rows as $row_index => $row ) {
		foreach ( $row as $column => $value ) {
			if ( null === $value ) {
				$rows[ $row_index ][ $column ] = null;
			} elseif ( is_bool( $value ) ) {
				$rows[ $row_index ][ $column ] = $value ? '1' : '0';
			} else {
				$rows[ $row_index ][ $column ] = (string) $value;
			}
		}
	}
	return $rows;
}

function wp_duckdb_local_smoke_run_runtime_probe(): array {
	$iterations = wp_duckdb_local_smoke_positive_int_env( 'WP_DUCKDB_LOCAL_SMOKE_RUNTIME_ITERATIONS', 100 );
	$warmups    = wp_duckdb_local_smoke_positive_int_env( 'WP_DUCKDB_LOCAL_SMOKE_RUNTIME_WARMUPS', 5 );
	$max_ratio  = wp_duckdb_local_smoke_positive_float_env( 'WP_DUCKDB_LOCAL_SMOKE_MAX_RATIO' );
	if ( null === $max_ratio ) {
		$max_ratio = wp_duckdb_local_smoke_positive_float_env( 'WP_DUCKDB_LOCAL_SMOKE_RUNTIME_MAX_RATIO' );
	}

	$smoke_wpdb_was_set = array_key_exists( 'wpdb', $GLOBALS );
	$smoke_wpdb         = $smoke_wpdb_was_set ? $GLOBALS['wpdb'] : null;
	unset( $GLOBALS['wpdb'] );
	if ( ! defined( 'WP_CLI' ) ) {
		define( 'WP_CLI', true );
	}

	try {
		$sqlite = new WP_SQLite_Driver(
			new WP_SQLite_Connection(
				array(
					'path'         => ':memory:',
					'journal_mode' => 'MEMORY',
				)
			),
			'wp'
		);

		$duckdb_native_queries = 0;
		$duckdb_connection     = new WP_DuckDB_Connection( array( 'path' => ':memory:' ) );
		$duckdb_connection->set_query_logger(
			static function ( string $sql, array $params ) use ( &$duckdb_native_queries ): void {
				unset( $sql, $params );
				++$duckdb_native_queries;
			}
		);
		$duckdb = new WP_DuckDB_Driver(
			array(
				'connection' => $duckdb_connection,
				'database'   => 'wp',
			)
		);

		foreach ( array( $sqlite, $duckdb ) as $driver ) {
			$driver->query(
				"CREATE TABLE wp_options (
					option_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
					option_name VARCHAR(191) NOT NULL DEFAULT '',
					option_value LONGTEXT NOT NULL,
					autoload VARCHAR(20) NOT NULL DEFAULT 'yes',
					PRIMARY KEY (option_id),
					UNIQUE KEY option_name (option_name)
				) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
			);
			$driver->query( "INSERT INTO wp_options (option_name, option_value, autoload) VALUES ('siteurl', 'https://example.test', 'yes')" );
			$driver->query( "INSERT INTO wp_options (option_name, option_value, autoload) VALUES ('home', 'https://example.test', 'yes')" );
			$driver->query( "INSERT INTO wp_options (option_name, option_value, autoload) VALUES ('blogname', 'DuckDB Smoke', 'yes')" );
		}

		$query       = "SELECT option_name, option_value FROM wp_options WHERE option_name IN ('siteurl', 'home') ORDER BY option_name";
		$sqlite_rows = wp_duckdb_local_smoke_normalize_rows( $sqlite->query( $query, PDO::FETCH_ASSOC ) );
		$duckdb_rows = wp_duckdb_local_smoke_normalize_rows( $duckdb->query( $query )->fetchAll( PDO::FETCH_ASSOC ) );
		$rows_equal  = $sqlite_rows === $duckdb_rows;

		if ( ! $rows_equal ) {
			return array(
				'enabled'     => true,
				'rows_equal'  => false,
				'sqlite_rows' => $sqlite_rows,
				'duckdb_rows' => $duckdb_rows,
			);
		}

		for ( $i = 0; $i < $warmups; $i++ ) {
			$sqlite->query( $query, PDO::FETCH_ASSOC );
			$duckdb->query( $query )->fetchAll( PDO::FETCH_ASSOC );
		}

		$sqlite_started_at = hrtime( true );
		for ( $i = 0; $i < $iterations; $i++ ) {
			$sqlite->query( $query, PDO::FETCH_ASSOC );
		}
		$sqlite_seconds = ( hrtime( true ) - $sqlite_started_at ) / 1000000000;

		$duckdb_native_queries = 0;
		$duckdb_started_at     = hrtime( true );
		for ( $i = 0; $i < $iterations; $i++ ) {
			$duckdb->query( $query )->fetchAll( PDO::FETCH_ASSOC );
		}
		$duckdb_seconds = ( hrtime( true ) - $duckdb_started_at ) / 1000000000;
		$ratio          = $sqlite_seconds > 0.0 ? $duckdb_seconds / $sqlite_seconds : null;
		$within_ratio   = null === $max_ratio || ( null !== $ratio && $ratio <= $max_ratio );

		return array(
			'enabled'               => true,
			'iterations'            => $iterations,
			'warmups'               => $warmups,
			'sqlite_ms'             => round( $sqlite_seconds * 1000, 3 ),
			'duckdb_ms'             => round( $duckdb_seconds * 1000, 3 ),
			'ratio'                 => null === $ratio ? null : round( $ratio, 6 ),
			'max_ratio'             => $max_ratio,
			'within_max_ratio'      => $within_ratio,
			'rows_equal'            => true,
			'duckdb_native_queries' => $duckdb_native_queries,
		);
	} finally {
		if ( $smoke_wpdb_was_set ) {
			$GLOBALS['wpdb'] = $smoke_wpdb;
		}
	}
}

define( 'ABSPATH', $root . '/wordpress/src/' );
define( 'WPINC', 'wp-includes' );
define( 'WP_CONTENT_DIR', ABSPATH . 'wp-content' );
define( 'FQDBDIR', WP_CONTENT_DIR . '/database/' );
define( 'FQDUCKDB', FQDBDIR . '.ht.duckdb' );
define( 'DB_NAME', 'wordpress' );
define( 'DB_USER', '' );
define( 'DB_PASSWORD', '' );
define( 'DB_HOST', '' );
define( 'WP_DEBUG', false );

require_once ABSPATH . WPINC . '/class-wp-error.php';
require_once ABSPATH . WPINC . '/plugin.php';
require_once ABSPATH . WPINC . '/formatting.php';
require_once ABSPATH . WPINC . '/functions.php';
require_once ABSPATH . WPINC . '/class-wpdb.php';
require_once WP_CONTENT_DIR . '/db.php';

if ( ! isset( $GLOBALS['wpdb'] ) || ! $GLOBALS['wpdb'] instanceof WP_DuckDB_DB ) {
	fwrite( STDERR, "Error: WP_DuckDB_DB was not loaded from the generated drop-in.\n" );
	exit( 1 );
}

$connected = $GLOBALS['wpdb']->db_connect( false );
if ( true !== $connected ) {
	fwrite( STDERR, "Error: WP_DuckDB_DB did not connect.\n" );
	exit( 1 );
}

$GLOBALS['wpdb']->query( 'DROP TABLE IF EXISTS wp_duckdb_local_smoke' );
$create = $GLOBALS['wpdb']->query( 'CREATE TABLE wp_duckdb_local_smoke (id INTEGER, note VARCHAR(20))' );
$insert = $GLOBALS['wpdb']->query( "INSERT INTO wp_duckdb_local_smoke VALUES (1, 'ok')" );
$rows   = $GLOBALS['wpdb']->get_results( 'SELECT id, note FROM wp_duckdb_local_smoke ORDER BY id', ARRAY_A );

$expected_rows = array(
	array(
		'id'   => '1',
		'note' => 'ok',
	),
);

if ( true !== $create || 1 !== $insert || $expected_rows !== $rows || '' !== $GLOBALS['wpdb']->last_error ) {
	fwrite( STDERR, "Error: DuckDB local smoke query failed.\n" );
	fwrite(
		STDERR,
		json_encode(
			array(
				'create'     => $create,
				'insert'     => $insert,
				'rows'       => $rows,
				'last_error' => $GLOBALS['wpdb']->last_error,
			),
			JSON_UNESCAPED_SLASHES
		) . "\n"
	);
	exit( 1 );
}

$diagnostics = array(
	'wpdb_class' => get_class( $GLOBALS['wpdb'] ),
	'connect'    => $connected,
	'create'     => $create,
	'insert'     => $insert,
	'rows'       => $rows,
	'ready'      => $GLOBALS['wpdb']->ready,
	'last_error' => $GLOBALS['wpdb']->last_error,
);

if ( wp_duckdb_local_smoke_env_enabled( 'WP_DUCKDB_LOCAL_SMOKE_RUNTIME_SAMPLE' ) ) {
	$diagnostics['runtime_probe'] = wp_duckdb_local_smoke_run_runtime_probe();
	if (
		false === $diagnostics['runtime_probe']['rows_equal'] ||
		false === $diagnostics['runtime_probe']['within_max_ratio']
	) {
		fwrite( STDERR, "Error: DuckDB local smoke runtime probe failed.\n" );
		fwrite( STDERR, json_encode( $diagnostics, JSON_UNESCAPED_SLASHES ) . "\n" );
		exit( 1 );
	}
}

echo json_encode( $diagnostics, JSON_UNESCAPED_SLASHES ) . "\n";
PHP
