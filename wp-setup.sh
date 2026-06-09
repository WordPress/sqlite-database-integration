#!/bin/bash

##
# This script prepares the WordPress repository for tests and development.
# It clones the WordPress repository and makes sure that the SQLite plugin
# is used in the development and testing environment instead of MySQL.
##

set -e

WP_VERSION="6.7.2"
WP_TEST_DB_BACKEND="${WP_TEST_DB_BACKEND:-${1:-sqlite}}"

DIR="$(dirname "$0")"
WP_DIR="$DIR/wordpress"

case "$WP_TEST_DB_BACKEND" in
	mysql)
		WP_TEST_DB_BACKEND="mysql"
		;;
	sqlite)
		WP_TEST_DB_BACKEND="sqlite"
		;;
	postgres|pgsql|postgresql)
		WP_TEST_DB_BACKEND="postgresql"
		;;
	*)
		echo "Error: Unsupported WP_TEST_DB_BACKEND: $WP_TEST_DB_BACKEND" >&2
		exit 1
		;;
esac

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

if [ "$WP_TEST_DB_BACKEND" = "sqlite" ]; then
	# 3. Add "docker-compose.override.yml" to the WordPress repository.
	echo "Adding 'docker-compose.override.yml' to the WordPress repository..."
	cat << EOF > "$WP_DIR/docker-compose.override.yml"
services:
  wordpress-develop:
    environment:
      DB_ENGINE: $WP_TEST_DB_BACKEND
      DATABASE_ENGINE: $WP_TEST_DB_BACKEND
    volumes:
      - ../packages/plugin-sqlite-database-integration:/var/www/src/wp-content/plugins/sqlite-database-integration
      - ../packages/mysql-on-sqlite/src:/var/www/src/wp-content/plugins/sqlite-database-integration/wp-includes/database

  php:
    # PHP temporarily pinned to 8.3.10, see: https://github.com/WordPress/wordpress-develop/pull/9602
    image: wordpressdevelop/php@sha256:c0ba85936a9d1ac2c98bf3da2d62ceb0e5787a6b11e383630df0c5a5bf2534b5
    environment:
      DB_ENGINE: $WP_TEST_DB_BACKEND
      DATABASE_ENGINE: $WP_TEST_DB_BACKEND
    volumes:
      - ../packages/plugin-sqlite-database-integration:/var/www/src/wp-content/plugins/sqlite-database-integration
      - ../packages/mysql-on-sqlite/src:/var/www/src/wp-content/plugins/sqlite-database-integration/wp-includes/database

  cli:
    # PHP temporarily pinned to 8.3.10, see: https://github.com/WordPress/wordpress-develop/pull/9602
    image: wordpressdevelop/cli@sha256:85ad7d7a9c3bd9a8775fc83aea7f7dfc0aad25b2bc4f7d740696b28cd2a0ef89
    environment:
      DB_ENGINE: $WP_TEST_DB_BACKEND
      DATABASE_ENGINE: $WP_TEST_DB_BACKEND
    volumes:
      - ../packages/plugin-sqlite-database-integration:/var/www/src/wp-content/plugins/sqlite-database-integration
      - ../packages/mysql-on-sqlite/src:/var/www/src/wp-content/plugins/sqlite-database-integration/wp-includes/database
EOF
elif [ "$WP_TEST_DB_BACKEND" = "postgresql" ]; then
	# 3. Add "docker-compose.override.yml" to the WordPress repository.
	echo "Adding PostgreSQL 'docker-compose.override.yml' to the WordPress repository..."
	cat << 'EOF' > "$WP_DIR/tools/local-env/postgres-init.sql"
CREATE DATABASE wordpress_develop_tests;
EOF
	cat << EOF > "$WP_DIR/docker-compose.override.yml"
services:
  wordpress-develop:
    environment:
      DB_ENGINE: postgresql
      DATABASE_ENGINE: postgresql
    volumes:
      - ../packages/plugin-sqlite-database-integration:/var/www/src/wp-content/plugins/sqlite-database-integration
      - ../packages/mysql-on-sqlite/src:/var/www/src/wp-content/plugins/sqlite-database-integration/wp-includes/database
    depends_on:
      php:
        condition: service_started
      postgres:
        condition: service_healthy

  php:
    # PHP temporarily pinned to 8.3.10, see: https://github.com/WordPress/wordpress-develop/pull/9602
    image: wordpressdevelop/php@sha256:c0ba85936a9d1ac2c98bf3da2d62ceb0e5787a6b11e383630df0c5a5bf2534b5
    environment:
      DB_ENGINE: postgresql
      DATABASE_ENGINE: postgresql
    volumes:
      - ../packages/plugin-sqlite-database-integration:/var/www/src/wp-content/plugins/sqlite-database-integration
      - ../packages/mysql-on-sqlite/src:/var/www/src/wp-content/plugins/sqlite-database-integration/wp-includes/database

  cli:
    # PHP temporarily pinned to 8.3.10, see: https://github.com/WordPress/wordpress-develop/pull/9602
    image: wordpressdevelop/cli@sha256:85ad7d7a9c3bd9a8775fc83aea7f7dfc0aad25b2bc4f7d740696b28cd2a0ef89
    environment:
      DB_ENGINE: postgresql
      DATABASE_ENGINE: postgresql
    volumes:
      - ../packages/plugin-sqlite-database-integration:/var/www/src/wp-content/plugins/sqlite-database-integration
      - ../packages/mysql-on-sqlite/src:/var/www/src/wp-content/plugins/sqlite-database-integration/wp-includes/database
    depends_on:
      php:
        condition: service_started
      postgres:
        condition: service_healthy

  postgres:
    image: postgres:16-alpine
    networks:
      - wpdevnet
    ports:
      - "5432"
    environment:
      POSTGRES_DB: wordpress_develop
      POSTGRES_USER: root
      POSTGRES_PASSWORD: password
    volumes:
      - ./tools/local-env/postgres-init.sql:/docker-entrypoint-initdb.d/postgres-init.sql:ro
      - postgres:/var/lib/postgresql/data
    healthcheck:
      test: [ "CMD-SHELL", "pg_isready -U root -d wordpress_develop" ]
      timeout: 5s
      interval: 5s
      retries: 10

volumes:
  postgres: {}
EOF
fi

if [ "$WP_TEST_DB_BACKEND" != "mysql" ]; then
	# 4. Add "db.php" to the "wp-content" directory.
	echo "Adding '$WP_TEST_DB_BACKEND' db.php to the 'wp-content' directory..."
	rm -f "$WP_DIR"/src/wp-content/db.php
	cp "$DIR"/packages/plugin-sqlite-database-integration/db.copy "$WP_DIR"/src/wp-content/db.php
	sed -i.bak "s#'{SQLITE_IMPLEMENTATION_FOLDER_PATH}'#__DIR__.'/plugins/sqlite-database-integration'#g" "$WP_DIR"/src/wp-content/db.php
	sed -i.bak "s#{SQLITE_PLUGIN}#sqlite-database-integration/load.php#g" "$WP_DIR"/src/wp-content/db.php
	sed -i.bak "s#{DATABASE_ENGINE}#$WP_TEST_DB_BACKEND#g" "$WP_DIR"/src/wp-content/db.php
else
	echo "Using WordPress default MySQL test database."
	rm -f "$WP_DIR"/src/wp-content/db.php
fi

if [ "$WP_TEST_DB_BACKEND" = "sqlite" ]; then
	# 5. Rewrite helper class WpdbExposedMethodsForTesting to extend WP_SQLite_DB.
	echo "Rewriting helper class 'WpdbExposedMethodsForTesting' to extend WP_SQLite_DB..."
	sed -i.bak "s#class WpdbExposedMethodsForTesting extends wpdb {#class WpdbExposedMethodsForTesting extends WP_SQLite_DB {#g" "$WP_DIR"/tests/phpunit/includes/utils.php
elif [ "$WP_TEST_DB_BACKEND" = "postgresql" ]; then
	# 5. Rewrite helper class WpdbExposedMethodsForTesting to extend WP_PostgreSQL_DB.
	echo "Rewriting helper class 'WpdbExposedMethodsForTesting' to extend WP_PostgreSQL_DB..."
	sed -i.bak "s#class WpdbExposedMethodsForTesting extends wpdb {#require_once ABSPATH . 'wp-content/plugins/sqlite-database-integration/wp-includes/postgresql/class-wp-postgresql-db.php';\nclass WpdbExposedMethodsForTesting extends WP_PostgreSQL_DB {#g" "$WP_DIR"/tests/phpunit/includes/utils.php

	echo "Rewriting WordPress local-env install script for PostgreSQL..."
	node - "$WP_DIR/tools/local-env/scripts/install.js" << 'NODE'
const fs = require( 'fs' );

const file = process.argv[2];
const replacements = [
	{
		from: "wp_cli( 'config create --dbname=wordpress_develop --dbuser=root --dbpass=password --dbhost=mysql --path=/var/www/src --force' );",
		to: [
			"wp_cli( 'config create --dbname=wordpress_develop --dbuser=root --dbpass=password --dbhost=postgres --path=/var/www/src --force' );",
			"wp_cli( 'config set DB_ENGINE postgresql --type=constant' );",
			"wp_cli( 'config set DATABASE_ENGINE postgresql --type=constant' );",
		],
	},
	{
		from: "\t.replace( 'localhost', 'mysql' )",
		to: [
			"\t.replace( 'localhost', 'postgres' )",
		],
	},
	{
		from: "\t.concat( \"\\ndefine( 'FS_METHOD', 'direct' );\\n\" );",
		to: [
			"\t.concat( \"\\ndefine( 'DB_ENGINE', 'postgresql' );\\n\" )",
			"\t.concat( \"define( 'DATABASE_ENGINE', 'postgresql' );\\n\" )",
			"\t.concat( \"define( 'FS_METHOD', 'direct' );\\n\" );",
		],
	},
];

const found = new Set();
const output = [];
for ( const line of fs.readFileSync( file, 'utf8' ).split( '\n' ) ) {
	const replacement = replacements.find( candidate => candidate.from === line );
	if ( replacement ) {
		found.add( replacement.from );
		output.push( ...replacement.to );
	} else {
		output.push( line );
	}
}

for ( const replacement of replacements ) {
	if ( ! found.has( replacement.from ) ) {
		throw new Error( `Expected line not found in ${ file }: ${ replacement.from }` );
	}
}

fs.writeFileSync( file, output.join( '\n' ) );
NODE
fi

# 6. Install dependencies.
echo "Installing dependencies..."
npm --prefix "$WP_DIR" install
npm --prefix "$WP_DIR" run build:dev
