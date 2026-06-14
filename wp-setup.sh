#!/bin/bash

##
# This script prepares the WordPress repository for tests and development.
# It clones the WordPress repository and makes sure that the SQLite plugin
# is used in the development and testing environment instead of MySQL.
##

set -e

WP_VERSION="6.7.2"
WP_TEST_DB_BACKEND="${WP_TEST_DB_BACKEND:-${1:-sqlite}}"
WP_TEST_SKIP_WORDPRESS_NPM="${WP_TEST_SKIP_WORDPRESS_NPM:-0}"
WP_RELEASE_REPOSITORY_URL="${WP_RELEASE_REPOSITORY_URL:-https://github.com/WordPress/WordPress.git}"

DIR="$(cd "$(dirname "$0")" && pwd)"
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

WP_SETUP_LOCK_DIR="$DIR/.wp-setup.lock"
if ! mkdir "$WP_SETUP_LOCK_DIR" 2>/dev/null; then
	echo 'Error: Another wp-setup.sh process is already running for this checkout.' >&2
	echo "If no setup process is running, remove '$WP_SETUP_LOCK_DIR' and rerun this command." >&2
	exit 1
fi
trap 'rmdir "$WP_SETUP_LOCK_DIR" 2>/dev/null || true' EXIT

# 1. Ensure that Git is installed.
echo "Checking if Git is installed..."
if ! command -v git &> /dev/null; then
	echo 'Error: Git is not installed.' >&2
	exit 1
fi

# 2. Clone the WordPress repository, if it doesn't exist.
echo "Cleaning up the WordPress repository..."
if [ -d "$WP_DIR" ]; then
	UNWRITABLE_WORDPRESS_PATH="$(find "$WP_DIR" -type d ! -writable -print -quit 2>/dev/null || true)"
	if [ -n "$UNWRITABLE_WORDPRESS_PATH" ]; then
		echo "Fixing ownership for Docker-generated WordPress files..."
		if command -v docker > /dev/null; then
			docker run --rm -v "$WP_DIR":/workspace --user 0:0 alpine:3.20 chown -R "$(id -u):$(id -g)" /workspace || true
		fi

		UNWRITABLE_WORDPRESS_PATH="$(find "$WP_DIR" -type d ! -writable -print -quit 2>/dev/null || true)"
		if [ -n "$UNWRITABLE_WORDPRESS_PATH" ]; then
			echo 'Error: Cannot clean the WordPress repository because it contains non-writable generated files.' >&2
			echo "First non-writable path: $UNWRITABLE_WORDPRESS_PATH" >&2
			echo "Fix ownership or remove '$WP_DIR' with appropriate permissions, then rerun this command." >&2
			exit 1
		fi
	fi
fi
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
	cat << 'EOF' > "$WP_DIR/tools/local-env/Dockerfile.postgresql-php"
FROM wordpressdevelop/php@sha256:c0ba85936a9d1ac2c98bf3da2d62ceb0e5787a6b11e383630df0c5a5bf2534b5

USER root

RUN if command -v git > /dev/null; then \
		git config --system --add safe.directory /var/www \
		|| git config --global --add safe.directory /var/www; \
	fi

RUN if command -v apt-get > /dev/null; then \
		apt-get update \
		&& apt-get install -y --no-install-recommends libpq-dev \
		&& docker-php-ext-install pdo_pgsql \
		&& rm -rf /var/lib/apt/lists/*; \
	elif command -v apk > /dev/null; then \
		apk add --no-cache postgresql-dev \
		&& docker-php-ext-install pdo_pgsql; \
	else \
		echo 'Unsupported PHP base image: cannot install pdo_pgsql.' >&2; \
		exit 1; \
	fi
EOF
	cat << 'EOF' > "$WP_DIR/tools/local-env/Dockerfile.postgresql-cli"
FROM wordpressdevelop/cli@sha256:85ad7d7a9c3bd9a8775fc83aea7f7dfc0aad25b2bc4f7d740696b28cd2a0ef89

USER root

RUN if command -v git > /dev/null; then \
		git config --system --add safe.directory /var/www \
		|| git config --global --add safe.directory /var/www; \
	fi

RUN if command -v apt-get > /dev/null; then \
		apt-get update \
		&& apt-get install -y --no-install-recommends libpq-dev \
		&& docker-php-ext-install pdo_pgsql \
		&& rm -rf /var/lib/apt/lists/*; \
	elif command -v apk > /dev/null; then \
		apk add --no-cache postgresql-dev \
		&& docker-php-ext-install pdo_pgsql; \
	else \
		echo 'Unsupported CLI base image: cannot install pdo_pgsql.' >&2; \
		exit 1; \
	fi
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
      mysql: !reset null
      php:
        condition: service_started
      postgres:
        condition: service_healthy

  php:
    # PHP temporarily pinned to 8.3.10, see: https://github.com/WordPress/wordpress-develop/pull/9602
    image: wordpressdevelop/php-postgresql:local
    build:
      context: .
      dockerfile: tools/local-env/Dockerfile.postgresql-php
    environment:
      DB_ENGINE: postgresql
      DATABASE_ENGINE: postgresql
    volumes:
      - ../packages/plugin-sqlite-database-integration:/var/www/src/wp-content/plugins/sqlite-database-integration
      - ../packages/mysql-on-sqlite/src:/var/www/src/wp-content/plugins/sqlite-database-integration/wp-includes/database

  cli:
    # PHP temporarily pinned to 8.3.10, see: https://github.com/WordPress/wordpress-develop/pull/9602
    image: wordpressdevelop/cli-postgresql:local
    build:
      context: .
      dockerfile: tools/local-env/Dockerfile.postgresql-cli
    environment:
      DB_ENGINE: postgresql
      DATABASE_ENGINE: postgresql
    volumes:
      - ../packages/plugin-sqlite-database-integration:/var/www/src/wp-content/plugins/sqlite-database-integration
      - ../packages/mysql-on-sqlite/src:/var/www/src/wp-content/plugins/sqlite-database-integration/wp-includes/database
    depends_on:
      mysql: !reset null
      php:
        condition: service_started
      postgres:
        condition: service_healthy

  mysql: !reset null

  postgres:
    image: postgres:16-alpine
    command:
      - postgres
      - -c
      - fsync=off
      - -c
      - synchronous_commit=off
      - -c
      - full_page_writes=off
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
  mysql: !reset null
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
	rm -f "$WP_DIR"/src/wp-content/db.php.bak
else
	echo "Using WordPress default MySQL test database."
	rm -f "$WP_DIR"/src/wp-content/db.php
fi

if [ "$WP_TEST_DB_BACKEND" = "sqlite" ]; then
	# 5. Rewrite helper class WpdbExposedMethodsForTesting to extend WP_SQLite_DB.
	echo "Rewriting helper class 'WpdbExposedMethodsForTesting' to extend WP_SQLite_DB..."
	sed -i.bak "s#class WpdbExposedMethodsForTesting extends wpdb {#class WpdbExposedMethodsForTesting extends WP_SQLite_DB {#g" "$WP_DIR"/tests/phpunit/includes/utils.php
	rm -f "$WP_DIR"/tests/phpunit/includes/utils.php.bak
elif [ "$WP_TEST_DB_BACKEND" = "postgresql" ]; then
	# 5. Rewrite helper class WpdbExposedMethodsForTesting to extend WP_PostgreSQL_DB.
	echo "Rewriting helper class 'WpdbExposedMethodsForTesting' to extend WP_PostgreSQL_DB..."
	sed -i.bak "s#class WpdbExposedMethodsForTesting extends wpdb {#require_once ABSPATH . 'wp-content/plugins/sqlite-database-integration/wp-includes/postgresql/class-wp-postgresql-db.php';\nclass WpdbExposedMethodsForTesting extends WP_PostgreSQL_DB {#g" "$WP_DIR"/tests/phpunit/includes/utils.php
	rm -f "$WP_DIR"/tests/phpunit/includes/utils.php.bak

	echo "Rewriting WordPress local-env install script for PostgreSQL..."
	node - "$WP_DIR/tools/local-env/scripts/install.js" << 'NODE'
const fs = require( 'fs' );

const file = process.argv[2];
const replacements = [
	{
		from: "local_env_utils.determine_auth_option();",
		to: [
			"local_env_utils.determine_auth_option();",
			"",
			"install_postgresql_test_environment();",
			"return;",
		],
	},
	{
		from: "const { renameSync, readFileSync, writeFileSync } = require( 'fs' );",
		to: [
			"const fs = require( 'fs' );",
			"const { existsSync, renameSync, readFileSync, writeFileSync } = fs;",
		],
	},
	{
		from: "wp_cli( 'config create --dbname=wordpress_develop --dbuser=root --dbpass=password --dbhost=mysql --path=/var/www/src --force' );",
		to: [
			"wp_cli( 'config create --dbname=wordpress_develop --dbuser=root --dbpass=password --dbhost=postgres --path=/var/www/src --force --skip-check' );",
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
		from: "renameSync( 'src/wp-config.php', 'wp-config.php' );",
		to: [
			"if ( existsSync( 'src/wp-config.php' ) ) {",
			"\trenameSync( 'src/wp-config.php', 'wp-config.php' );",
			"}",
			"if ( ! existsSync( 'wp-config.php' ) ) {",
			"\tthrow new Error( 'wp-config.php was not generated.' );",
			"}",
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
	{
		from: "\t\twp_cli( 'db reset --yes' );",
		to: [
			"\t\t// PostgreSQL databases are created by the compose init SQL.",
		],
	},
	{
		from: "\t\tconst installCommand = process.env.LOCAL_MULTISITE === 'true'  ? 'multisite-install' : 'install';",
		to: [
			"\t\t// Skip WP-CLI site installation; the PHPUnit bootstrap owns the test schema.",
		],
	},
	{
		from: "\t\twp_cli( `core ${ installCommand } --title=\"WordPress Develop\" --admin_user=admin --admin_password=password --admin_email=test@test.com --skip-email --url=http://localhost:${process.env.LOCAL_PORT}` );",
		to: [
			"\t\t// The PostgreSQL scaffold cannot use WP-CLI's MySQL-backed install commands.",
		],
	},
];

const input = fs.readFileSync( file, 'utf8' ).split( '\n' );
const containsLines = ( lines, expected ) => {
	for ( let index = 0; index <= lines.length - expected.length; index++ ) {
		let matches = true;
		for ( let offset = 0; offset < expected.length; offset++ ) {
			if ( lines[ index + offset ] !== expected[ offset ] ) {
				matches = false;
				break;
			}
		}
		if ( matches ) {
			return true;
		}
	}
	return false;
};

const found = new Set();
const output = [];
for ( const line of input ) {
	const replacement = replacements.find( candidate => candidate.from === line );
	if ( replacement ) {
		found.add( replacement.from );
		output.push( ...replacement.to );
	} else {
		output.push( line );
	}
}

for ( const replacement of replacements ) {
	if ( ! found.has( replacement.from ) && ! containsLines( input, replacement.to ) ) {
		throw new Error( `Expected line not found in ${ file }: ${ replacement.from }` );
	}
}

let contents = output.join( '\n' );
let importerExecReplacementCount = 0;
contents = contents.replace(
	/exec -T php (rm -rf \$\{testPluginDirectory\}|git clone https:\/\/github\.com\/WordPress\/wordpress-importer\.git \$\{testPluginDirectory\} --depth=1)/g,
	( match, command ) => {
		importerExecReplacementCount++;
		return `run --rm --workdir /var/www php ${ command }`;
	}
);

if ( 2 !== importerExecReplacementCount ) {
	throw new Error( `Expected to rewrite 2 WordPress Importer docker exec commands in ${ file }, rewrote ${ importerExecReplacementCount }.` );
}

contents += `

function install_postgresql_test_environment() {
	write_postgresql_wp_config();
	write_postgresql_wp_tests_config();
	install_postgresql_wp_importer();
}

function write_postgresql_wp_config() {
	let config = fs.readFileSync( 'wp-config-sample.php', 'utf8' );
	config = config
		.replace( "define( 'DB_NAME', 'database_name_here' );", "define( 'DB_NAME', 'wordpress_develop' );" )
		.replace( "define( 'DB_USER', 'username_here' );", "define( 'DB_USER', 'root' );" )
		.replace( "define( 'DB_PASSWORD', 'password_here' );", "define( 'DB_PASSWORD', 'password' );" )
		.replace( "define( 'DB_HOST', 'localhost' );", "define( 'DB_HOST', 'postgres' );" )
		.replace(
			"define( 'WP_DEBUG', false );",
			"define( 'WP_DEBUG', " + get_postgresql_raw_constant_value( 'LOCAL_WP_DEBUG', 'true' ) + " );"
		)
		.replace(
			'/* Add any custom values between this line and the "stop editing" line. */',
			[
				'/* Add any custom values between this line and the "stop editing" line. */',
				'',
				"define( 'DB_ENGINE', 'postgresql' );",
				"define( 'DATABASE_ENGINE', 'postgresql' );",
				"define( 'WP_DEBUG_LOG', " + get_postgresql_raw_constant_value( 'LOCAL_WP_DEBUG_LOG', 'true' ) + " );",
				"define( 'WP_DEBUG_DISPLAY', " + get_postgresql_raw_constant_value( 'LOCAL_WP_DEBUG_DISPLAY', 'true' ) + " );",
				"define( 'SCRIPT_DEBUG', " + get_postgresql_raw_constant_value( 'LOCAL_SCRIPT_DEBUG', 'true' ) + " );",
				"define( 'WP_ENVIRONMENT_TYPE', " + quote_postgresql_php_string( get_postgresql_env_value( 'LOCAL_WP_ENVIRONMENT_TYPE', 'local' ) ) + " );",
				"define( 'WP_DEVELOPMENT_MODE', " + quote_postgresql_php_string( get_postgresql_env_value( 'LOCAL_WP_DEVELOPMENT_MODE', 'core' ) ) + " );",
			].join( '\\n' )
		);

	fs.rmSync( 'src/wp-config.php', { force: true } );
	fs.writeFileSync( 'wp-config.php', config );
}

function write_postgresql_wp_tests_config() {
	const testConfig = fs.readFileSync( 'wp-tests-config-sample.php', 'utf8' )
		.replace( 'youremptytestdbnamehere', 'wordpress_develop_tests' )
		.replace( 'yourusernamehere', 'root' )
		.replace( 'yourpasswordhere', 'password' )
		.replace( 'localhost', 'postgres' )
		.replace(
			"'WP_TESTS_DOMAIN', 'example.org'",
			"'WP_TESTS_DOMAIN', " + quote_postgresql_php_string( get_postgresql_env_value( 'LOCAL_WP_TESTS_DOMAIN', 'example.org' ) )
		)
		.concat( "\\ndefine( 'DB_ENGINE', 'postgresql' );\\n" )
		.concat( "define( 'DATABASE_ENGINE', 'postgresql' );\\n" )
		.concat( "define( 'FS_METHOD', 'direct' );\\n" );

	fs.writeFileSync( 'wp-tests-config.php', testConfig );
}

function install_postgresql_wp_importer() {
	const testPluginDirectory = 'tests/phpunit/data/plugins/wordpress-importer';
	if ( fs.existsSync( testPluginDirectory + '/wordpress-importer.php' ) ) {
		return;
	}

	fs.rmSync( testPluginDirectory, { recursive: true, force: true } );
	execSync( 'git clone https://github.com/WordPress/wordpress-importer.git ' + testPluginDirectory + ' --depth=1', { stdio: 'inherit' } );
}

function get_postgresql_env_value( name, defaultValue ) {
	return process.env[ name ] || defaultValue;
}

function get_postgresql_raw_constant_value( name, defaultValue ) {
	const value = get_postgresql_env_value( name, defaultValue );
	if ( /^(?:true|false|null|[0-9]+)$/i.test( value ) ) {
		return value.toLowerCase();
	}

	throw new Error( \`Unsupported raw constant value for \${ name }: \${ value }\` );
}

function quote_postgresql_php_string( value ) {
	return "'" + String( value ).replace( /\\\\/g, '\\\\\\\\' ).replace( /'/g, "\\\\'" ) + "'";
}
`;

fs.writeFileSync( file, contents );
NODE
fi

install_wordpress_release_assets() {
	local release_asset_path
	local release_dir
	release_dir="$(mktemp -d "${TMPDIR:-/tmp}/wordpress-release-assets.XXXXXX")"

	echo "Hydrating WordPress release assets for PostgreSQL PHP tests..."
	if ! git clone -c advice.detachedHead=false --depth 1 --filter=blob:none --sparse --single-branch --branch "$WP_VERSION" "$WP_RELEASE_REPOSITORY_URL" "$release_dir"; then
		rm -rf "$release_dir"
		return 1
	fi

	if ! git -C "$release_dir" sparse-checkout set \
		wp-admin/css \
		wp-admin/js \
		wp-includes/assets \
		wp-includes/blocks \
		wp-includes/css \
		wp-includes/js
	then
		rm -rf "$release_dir"
		return 1
	fi

	for release_asset_path in \
		wp-admin/css \
		wp-admin/js \
		wp-includes/assets \
		wp-includes/blocks \
		wp-includes/css \
		wp-includes/js
	do
		if [ ! -e "$release_dir/$release_asset_path" ]; then
			echo "Error: WordPress release asset path is missing: $release_asset_path" >&2
			rm -rf "$release_dir"
			return 1
		fi

		rm -rf "$WP_DIR/src/$release_asset_path"
		mkdir -p "$(dirname "$WP_DIR/src/$release_asset_path")"
		cp -R "$release_dir/$release_asset_path" "$WP_DIR/src/$release_asset_path"
	done

	rm -rf "$release_dir"
}

# 6. Install dependencies.
if [ "$WP_TEST_DB_BACKEND" = "postgresql" ] && [ "$WP_TEST_SKIP_WORDPRESS_NPM" = "1" ]; then
	echo "Skipping WordPress npm install and JavaScript build for PostgreSQL PHP tests..."
	install_wordpress_release_assets
else
	echo "Installing dependencies..."
	npm --prefix "$WP_DIR" install
	npm --prefix "$WP_DIR" run build:dev
fi
