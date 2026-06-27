#!/bin/bash

##
# This script prepares the WordPress repository for tests and development.
# It clones the WordPress repository and makes sure that the SQLite plugin
# is used in the development and testing environment instead of MySQL.
##

set -e

WP_VERSION="6.7.2"

DIR="$(dirname "$0")"
WP_DIR="$DIR/wordpress"
WP_TEST_DB_ENGINE="${WP_TEST_DB_ENGINE:-sqlite}"
DUCKDB_PHP_AUTOLOAD="${DUCKDB_PHP_AUTOLOAD:-}"
DUCKDB_DOCKER_VOLUMES=""

yaml_single_quote() {
	local value="$1"

	value=${value//\'/\'\'}
	printf "'%s'" "$value"
}

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

case "$WP_TEST_DB_ENGINE" in
	sqlite|duckdb)
		;;
	*)
		echo "Error: WP_TEST_DB_ENGINE must be either 'sqlite' or 'duckdb'." >&2
		exit 1
		;;
esac

if [ "$WP_TEST_DB_ENGINE" = "duckdb" ]; then
	if [ -z "$DUCKDB_PHP_AUTOLOAD" ]; then
		echo 'Error: DUCKDB_PHP_AUTOLOAD must be set when WP_TEST_DB_ENGINE=duckdb.' >&2
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
	DUCKDB_RUNTIME_DIR="$(dirname "$DUCKDB_PHP_AUTOLOAD")"
	if [ "$(basename "$DUCKDB_RUNTIME_DIR")" = "vendor" ]; then
		DUCKDB_RUNTIME_DIR="$(dirname "$DUCKDB_RUNTIME_DIR")"
	fi

	DUCKDB_RUNTIME_VOLUME="$(yaml_single_quote "$DUCKDB_RUNTIME_DIR:$DUCKDB_RUNTIME_DIR:ro")"
	DUCKDB_FFI_INI_VOLUME="$(yaml_single_quote "./duckdb-php-conf/zz-duckdb-ffi.ini:/usr/local/etc/php/conf.d/zz-duckdb-ffi.ini:ro")"
	DUCKDB_DOCKER_VOLUMES=$(printf '      - %s\n      - %s' "$DUCKDB_RUNTIME_VOLUME" "$DUCKDB_FFI_INI_VOLUME")
fi

# 1. Ensure that Git is installed.
echo "Checking if Git is installed..."
if ! command -v git &> /dev/null; then
	echo 'Error: Git is not installed.' >&2
	exit 1
fi

# 2. Clone the WordPress repository, if it doesn't exist.
echo "Cleaning up the WordPress repository..."
rm -rf "$WP_DIR"
echo "Cloning the WordPress repository..."
git clone --depth 1 --branch "$WP_VERSION" https://github.com/WordPress/wordpress-develop.git "$WP_DIR"

# 3. Add "docker-compose.override.yml" to the WordPress repository.
echo "Adding 'docker-compose.override.yml' to the WordPress repository..."
cat << EOF > "$WP_DIR/docker-compose.override.yml"
services:
  wordpress-develop:
    volumes:
      - ../packages/plugin-sqlite-database-integration:/var/www/src/wp-content/plugins/sqlite-database-integration
      - ../packages/mysql-on-sqlite/src:/var/www/src/wp-content/plugins/sqlite-database-integration/wp-includes/database

  php:
    # PHP temporarily pinned to 8.3.10, see: https://github.com/WordPress/wordpress-develop/pull/9602
    image: wordpressdevelop/php@sha256:c0ba85936a9d1ac2c98bf3da2d62ceb0e5787a6b11e383630df0c5a5bf2534b5
    volumes:
      - ../packages/plugin-sqlite-database-integration:/var/www/src/wp-content/plugins/sqlite-database-integration
      - ../packages/mysql-on-sqlite/src:/var/www/src/wp-content/plugins/sqlite-database-integration/wp-includes/database
$DUCKDB_DOCKER_VOLUMES

  cli:
    # PHP temporarily pinned to 8.3.10, see: https://github.com/WordPress/wordpress-develop/pull/9602
    image: wordpressdevelop/cli@sha256:85ad7d7a9c3bd9a8775fc83aea7f7dfc0aad25b2bc4f7d740696b28cd2a0ef89
    volumes:
      - ../packages/plugin-sqlite-database-integration:/var/www/src/wp-content/plugins/sqlite-database-integration
      - ../packages/mysql-on-sqlite/src:/var/www/src/wp-content/plugins/sqlite-database-integration/wp-includes/database
$DUCKDB_DOCKER_VOLUMES
EOF
if [ "$WP_TEST_DB_ENGINE" = "duckdb" ]; then
	echo "Adding DuckDB PHP FFI configuration..."
	mkdir -p "$WP_DIR/duckdb-php-conf"
	printf 'ffi.enable=1\n' > "$WP_DIR/duckdb-php-conf/zz-duckdb-ffi.ini"
fi

# 4. Add "db.php" to the "wp-content" directory.
echo "Adding 'db.php' to the 'wp-content' directory..."
rm -f "$WP_DIR"/src/wp-content/db.php
if [ "$WP_TEST_DB_ENGINE" = "duckdb" ]; then
	cp "$DIR"/packages/plugin-sqlite-database-integration/db-duckdb.copy "$WP_DIR"/src/wp-content/db.php
else
	cp "$DIR"/packages/plugin-sqlite-database-integration/db.copy "$WP_DIR"/src/wp-content/db.php
fi
sed -i.bak "s#'{SQLITE_IMPLEMENTATION_FOLDER_PATH}'#__DIR__.'/plugins/sqlite-database-integration'#g" "$WP_DIR"/src/wp-content/db.php
if [ "$WP_TEST_DB_ENGINE" = "duckdb" ]; then
	insert_duckdb_autoload_constant "$WP_DIR"/src/wp-content/db.php "$DUCKDB_PHP_AUTOLOAD"
else
	sed -i.bak "s#{SQLITE_PLUGIN}#sqlite-database-integration/load.php#g" "$WP_DIR"/src/wp-content/db.php
fi

# 5. Rewrite helper class WpdbExposedMethodsForTesting to extend the active database adapter.
if [ "$WP_TEST_DB_ENGINE" = "duckdb" ]; then
	WPDB_TEST_HELPER_CLASS="WP_DuckDB_DB"
else
	WPDB_TEST_HELPER_CLASS="WP_SQLite_DB"
fi
echo "Rewriting helper class 'WpdbExposedMethodsForTesting' to extend $WPDB_TEST_HELPER_CLASS..."
sed -i.bak "s#class WpdbExposedMethodsForTesting extends wpdb {#class WpdbExposedMethodsForTesting extends $WPDB_TEST_HELPER_CLASS {#g" "$WP_DIR"/tests/phpunit/includes/utils.php

# 6. Install dependencies.
echo "Installing dependencies..."
npm --prefix "$WP_DIR" install
npm --prefix "$WP_DIR" run build:dev
