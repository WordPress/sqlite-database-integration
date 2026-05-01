#!/usr/bin/env bash
# Drives the two-stage build for the Rust ext-php-rs PHP extension targeting
# PHP.wasm side modules:
#
#   1. cargo build --release --target wasm32-unknown-emscripten
#      inside playground-php-wasm-ext-rust:<PHP_VERSION>-<ASYNC>, which
#      layers rustup + a host PHP 8.4 CLI on top of the official
#      compile-extension image.
#   2. Hand the resulting libwp_mysql_parser.a + C shim + config.m4 to
#      `build-php-wasm-extension` (the entrypoint baked into the official
#      compile-extension image), which runs phpize + emconfigure + emmake
#      and produces a side module .so plus runs wasm-opt.
#
# Outputs:
#   wasm-spike/dist/libwp_mysql_parser.a  (Stage 1)
#   wasm-spike/dist/wp_mysql_parser-php8.4-jspi.so (Stage 2, wasm side module)
#   wasm-spike/dist/manifest.json (written by run-spike.mjs alternative path)
set -euo pipefail

PHP_VERSION="${PHP_VERSION:-8.4}"
ASYNC_MODE="${ASYNC_MODE:-jspi}"
SPIKE_DIR="$(cd "$(dirname "$0")" && pwd)"
CRATE_DIR="$(cd "$SPIKE_DIR/.." && pwd)"
OUT_DIR="${OUT_DIR:-$SPIKE_DIR/dist}"
mkdir -p "$OUT_DIR"

RUST_IMAGE="playground-php-wasm-ext-rust:${PHP_VERSION}-${ASYNC_MODE}"
BASE_IMAGE="playground-php-wasm:compile-extension-php${PHP_VERSION//./-}-${ASYNC_MODE}"

echo "==> Stage 0: building $RUST_IMAGE"
docker build \
  --build-arg "BASE_IMAGE=$BASE_IMAGE" \
  -t "$RUST_IMAGE" \
  -f "$SPIKE_DIR/Dockerfile.rust" \
  "$SPIKE_DIR"

echo "==> Stage 1: cargo build --target wasm32-unknown-emscripten"
docker run --rm \
  -v "$CRATE_DIR":/src:ro \
  -v "$OUT_DIR":/out \
  --entrypoint bash \
  "$RUST_IMAGE" -lc '
    set -e
    source /root/emsdk/emsdk_env.sh
    SYSROOT=/root/emsdk/upstream/emscripten/cache/sysroot
    export CC=emcc CXX=em++ AR=emar
    # bindgen does not pick up cargo target automatically. Steer its
    # libclang invocation at the emscripten sysroot, and force
    # ZEND_ENABLE_ZVAL_LONG64 + __x86_64__ so zend_long is i64 (the
    # convention compile-extension uses for the C side too).
    export BINDGEN_EXTRA_CLANG_ARGS="--target=wasm32-unknown-emscripten --sysroot=$SYSROOT -I$SYSROOT/include -DZEND_ENABLE_ZVAL_LONG64 -D__x86_64__"

    # cc-rs (used by ext-php-rs to compile wrapper.c) does not pick up
    # -fPIC by default for wasm32. The side-module link demands PIC, so
    # force it via the target-specific CFLAGS env var.
    export CFLAGS_wasm32_unknown_emscripten="-fPIC -DZEND_ENABLE_ZVAL_LONG64 -D__x86_64__"
    # Tell cargo/rustc to build with PIE-friendly relocations as well.
    # `-C relocation-model=pic` is required for the side-module link.
    # `-C panic=abort` keeps the Rust archive from importing the C++
    # exception tag (`__cpp_exception`) that the PHP-wasm main module
    # does not export — without this, dlopen() fails with a
    # WebAssembly.Instance LinkError on Import "env" "__cpp_exception".
    export CARGO_TARGET_WASM32_UNKNOWN_EMSCRIPTEN_RUSTFLAGS="-C relocation-model=pic -C panic=abort"

    # Operate on a copy so the sed Cargo.toml flip never touches /src.
    cp -R /src /work
    cd /work
    sed -i "s/crate-type = \[\"cdylib\"\]/crate-type = [\"staticlib\"]/" Cargo.toml

    # Pre-fetch so the registry sources are extracted, then patch
    # ext-php-rs to relax a const assertion that does not hold when
    # ZEND_ENABLE_ZVAL_LONG64 is forced on a 32-bit (wasm32) target.
    cargo fetch >/dev/null 2>&1 || true
    REG=$(find /root/cargo/registry/src -maxdepth 1 -type d -name "index.crates.io-*" | head -1)
    chmod -R u+w "$REG"
    sed -i "s/12 \* std::mem::size_of::<usize>/24 * std::mem::size_of::<usize>/" \
      "$REG/ext-php-rs-0.15.12/src/internal/property.rs"

    # Use nightly + -Zbuild-std=std,panic_abort so libstd is rebuilt with
    # panic=abort. Without rebuilding std, the precompiled libstd still
    # contains panic-unwind code that imports __cpp_exception.
    cargo +nightly build --release --target wasm32-unknown-emscripten \
        -Zbuild-std=std,panic_abort
    cp target/wasm32-unknown-emscripten/release/libwp_mysql_parser.a /out/
  '

echo "==> Stage 2: phpize + emconfigure + emmake (build-php-wasm-extension)"
SRC_STAGE="$(mktemp -d)"
cp "$SPIKE_DIR/shim/config.m4"               "$SRC_STAGE/"
cp "$SPIKE_DIR/shim/wp_mysql_parser_shim.c"  "$SRC_STAGE/"
cp "$OUT_DIR/libwp_mysql_parser.a"           "$SRC_STAGE/"

ARTIFACT="wp_mysql_parser-php${PHP_VERSION}-${ASYNC_MODE}.so"

docker run --rm \
  -v "$SRC_STAGE":/src:ro \
  -v "$OUT_DIR":/out \
  --env "EXTENSION_NAME=wp_mysql_parser" \
  --env "PHP_VERSION_SHORT=${PHP_VERSION}" \
  --env "ASYNC_MODE=${ASYNC_MODE}" \
  --env "ARTIFACT_FILENAME=${ARTIFACT}" \
  --env "OPTIMIZE=2" \
  --env "EXTRA_LDFLAGS=/build/libwp_mysql_parser.a" \
  --env "CONFIG_ARGS_COUNT=0" \
  "$BASE_IMAGE"

rm -rf "$SRC_STAGE"
echo "==> Built $OUT_DIR/$ARTIFACT"
