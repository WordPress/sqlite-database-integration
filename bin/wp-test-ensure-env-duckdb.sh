#!/bin/bash

##
# Ensure the WordPress Docker test environment is configured for DuckDB.
##

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd -P)"
WP_DIR="$ROOT_DIR/wordpress"
COMPOSE_OVERRIDE="$WP_DIR/docker-compose.override.yml"

PHP_BASE_IMAGE="wordpressdevelop/php@sha256:c0ba85936a9d1ac2c98bf3da2d62ceb0e5787a6b11e383630df0c5a5bf2534b5"
CLI_BASE_IMAGE="wordpressdevelop/cli@sha256:85ad7d7a9c3bd9a8775fc83aea7f7dfc0aad25b2bc4f7d740696b28cd2a0ef89"
PHP_FFI_IMAGE="sqlite-duckdb-wordpress-php:8.3.10-ffi"
CLI_FFI_IMAGE="sqlite-duckdb-wordpress-cli:8.3.10-ffi"

needs_duckdb_setup() {
	if [ ! -f "$WP_DIR/src/wp-load.php" ]; then
		return 0
	fi

	if ! grep -q "define( 'DB_ENGINE', 'duckdb' );" "$WP_DIR/src/wp-content/db.php" 2> /dev/null; then
		return 0
	fi

	if [ ! -f "$COMPOSE_OVERRIDE" ]; then
		return 0
	fi

	return 1
}

ensure_duckdb_setup() {
	if needs_duckdb_setup; then
		(
			cd "$ROOT_DIR"
			composer run wp-setup-duckdb
		)
	fi
}

ensure_docker_available() {
	if ! command -v docker > /dev/null; then
		echo 'Error: Docker is required to run WordPress DuckDB tests.' >&2
		exit 1
	fi

	docker info > /dev/null
}

write_ffi_dockerfile() {
	local image_dir="$WP_DIR/duckdb-php-ffi-image"

	mkdir -p "$image_dir"
	cat > "$image_dir/Dockerfile" <<'EOF'
ARG BASE_IMAGE
FROM ${BASE_IMAGE}

USER root

RUN set -eux; \
	apt-get update; \
	apt-get install -y --no-install-recommends libffi-dev; \
	docker-php-ext-install ffi; \
	rm -rf /var/lib/apt/lists/*
EOF
}

build_ffi_images() {
	local image_dir="$WP_DIR/duckdb-php-ffi-image"

	write_ffi_dockerfile
	if ! docker image inspect "$PHP_FFI_IMAGE" > /dev/null 2>&1; then
		docker build --build-arg "BASE_IMAGE=$PHP_BASE_IMAGE" --tag "$PHP_FFI_IMAGE" "$image_dir"
	fi
	if ! docker image inspect "$CLI_FFI_IMAGE" > /dev/null 2>&1; then
		docker build --build-arg "BASE_IMAGE=$CLI_BASE_IMAGE" --tag "$CLI_FFI_IMAGE" "$image_dir"
	fi
}

use_ffi_images_in_compose_override() {
	sed -i.bak \
		-e "s#image: $PHP_BASE_IMAGE#image: $PHP_FFI_IMAGE#g" \
		-e "s#image: $CLI_BASE_IMAGE#image: $CLI_FFI_IMAGE#g" \
		"$COMPOSE_OVERRIDE"
	rm -f "$COMPOSE_OVERRIDE.bak"

	if ! grep -q "image: $PHP_FFI_IMAGE" "$COMPOSE_OVERRIDE"; then
		echo 'Error: Could not configure the WordPress PHP container to use the DuckDB FFI image.' >&2
		exit 1
	fi

	if ! grep -q "image: $CLI_FFI_IMAGE" "$COMPOSE_OVERRIDE"; then
		echo 'Error: Could not configure the WordPress CLI container to use the DuckDB FFI image.' >&2
		exit 1
	fi
}

start_wordpress_environment() {
	export COMPOSE_IGNORE_ORPHANS=true

	if [ -n "$(cd "$WP_DIR" && node tools/local-env/scripts/docker.js ps -q)" ]; then
		(
			cd "$WP_DIR"
			docker compose -f docker-compose.yml -f docker-compose.override.yml up -d --no-deps --force-recreate php
		)
		return
	fi

	(
		cd "$ROOT_DIR"
		composer run wp-test-start
	)
}

verify_service_ffi() {
	local service="$1"

	if ! ( cd "$WP_DIR" && docker compose -f docker-compose.yml -f docker-compose.override.yml run --rm "$service" php -m ) | grep -qi '^FFI$'; then
		echo "Error: PHP FFI extension is not loaded in the WordPress $service container." >&2
		exit 1
	fi

	if ! ( cd "$WP_DIR" && docker compose -f docker-compose.yml -f docker-compose.override.yml run --rm "$service" php -i ) | grep -Eiq '^ffi\.enable => (1|on|true) => (1|on|true)$'; then
		echo "Error: PHP FFI is not enabled in the WordPress $service container." >&2
		exit 1
	fi
}

verify_ffi_runtime() {
	verify_service_ffi php
	verify_service_ffi cli
}

ensure_duckdb_setup
ensure_docker_available
build_ffi_images
use_ffi_images_in_compose_override
start_wordpress_environment
verify_ffi_runtime
