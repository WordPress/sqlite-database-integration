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

# 3. Install the SQLite driver Composer dependencies (wordpress/mysql-parser)
#    into a dedicated, self-contained vendor directory (path repositories are
#    mirrored instead of symlinked) used only by the WordPress containers.
#
#    A production (--no-dev) install is required here: the driver development
#    vendor directory contains PHPUnit, and loading it inside the WordPress
#    test process would clash with the WordPress PHPUnit test runner.
#
#    Note that Docker resolves the driver source mountpoint through the
#    plugin's "wp-includes/database" symlink, so the driver lands in the
#    containers at "plugins/mysql-on-sqlite/src", and the vendor directory
#    is mounted next to it.
echo "Installing the SQLite driver Composer dependencies..."
rm -rf "$WP_DIR/driver-vendor"
COMPOSER_VENDOR_DIR="$WP_DIR/driver-vendor" COMPOSER_MIRROR_PATH_REPOS=1 \
	composer install --working-dir="$DIR/packages/mysql-on-sqlite" --no-dev --no-interaction

# 4. Add "docker-compose.override.yml" to the WordPress repository.
echo "Adding 'docker-compose.override.yml' to the WordPress repository..."
cat << EOF > "$WP_DIR/docker-compose.override.yml"
services:
  wordpress-develop:
    volumes:
      - ../packages/plugin-sqlite-database-integration:/var/www/src/wp-content/plugins/sqlite-database-integration
      - ../packages/mysql-on-sqlite/src:/var/www/src/wp-content/plugins/sqlite-database-integration/wp-includes/database
      - ./driver-vendor:/var/www/src/wp-content/plugins/mysql-on-sqlite/vendor

  php:
    # PHP temporarily pinned to 8.3.10, see: https://github.com/WordPress/wordpress-develop/pull/9602
    image: wordpressdevelop/php@sha256:c0ba85936a9d1ac2c98bf3da2d62ceb0e5787a6b11e383630df0c5a5bf2534b5
    volumes:
      - ../packages/plugin-sqlite-database-integration:/var/www/src/wp-content/plugins/sqlite-database-integration
      - ../packages/mysql-on-sqlite/src:/var/www/src/wp-content/plugins/sqlite-database-integration/wp-includes/database
      - ./driver-vendor:/var/www/src/wp-content/plugins/mysql-on-sqlite/vendor

  cli:
    # PHP temporarily pinned to 8.3.10, see: https://github.com/WordPress/wordpress-develop/pull/9602
    image: wordpressdevelop/cli@sha256:85ad7d7a9c3bd9a8775fc83aea7f7dfc0aad25b2bc4f7d740696b28cd2a0ef89
    volumes:
      - ../packages/plugin-sqlite-database-integration:/var/www/src/wp-content/plugins/sqlite-database-integration
      - ../packages/mysql-on-sqlite/src:/var/www/src/wp-content/plugins/sqlite-database-integration/wp-includes/database
      - ./driver-vendor:/var/www/src/wp-content/plugins/mysql-on-sqlite/vendor
EOF

# 5. Add "db.php" to the "wp-content" directory.
echo "Adding 'db.php' to the 'wp-content' directory..."
rm -f "$WP_DIR"/src/wp-content/db.php
cp "$DIR"/packages/plugin-sqlite-database-integration/db.copy "$WP_DIR"/src/wp-content/db.php
sed -i.bak "s#'{SQLITE_IMPLEMENTATION_FOLDER_PATH}'#__DIR__.'/plugins/sqlite-database-integration'#g" "$WP_DIR"/src/wp-content/db.php
sed -i.bak "s#{SQLITE_PLUGIN}#sqlite-database-integration/load.php#g" "$WP_DIR"/src/wp-content/db.php

# 6. Rewrite helper class WpdbExposedMethodsForTesting to extend WP_SQLite_DB.
echo "Rewriting helper class 'WpdbExposedMethodsForTesting' to extend WP_SQLite_DB..."
sed -i.bak "s#class WpdbExposedMethodsForTesting extends wpdb {#class WpdbExposedMethodsForTesting extends WP_SQLite_DB {#g" "$WP_DIR"/tests/phpunit/includes/utils.php

# 7. Install dependencies.
echo "Installing dependencies..."
npm --prefix "$WP_DIR" install
npm --prefix "$WP_DIR" run build:dev
