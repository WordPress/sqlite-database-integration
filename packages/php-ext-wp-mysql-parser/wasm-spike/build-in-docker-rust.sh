#!/usr/bin/env bash
# Drives the two-stage build for the Rust ext-php-rs PHP extension targeting
# PHP.wasm side modules:
#
#   1. cargo build --release --target wasm32-unknown-emscripten
#      inside playground-php-wasm-ext-rust:<PHP_VERSION>-<ASYNC>, which
#      layers rustup + ext-php-rs build metadata on top of the Playground
#      compile-extension image.
#   2. Hand the resulting libwp_mysql_parser.a + C shim + config.m4 to
#      `@php-wasm/compile-extension`, which owns phpize, emconfigure,
#      emmake, wasm-opt, and manifest generation.
#
# Outputs:
#   wasm-spike/dist/libwp_mysql_parser.a  (Stage 1)
#   wasm-spike/dist/wp_mysql_parser-php<version>-jspi.so (Stage 2, wasm side module)
#   wasm-spike/dist/manifest.json (written by @php-wasm/compile-extension)
set -euo pipefail

PHP_VERSION="${PHP_VERSION:-8.4}"
ASYNC_MODE="${ASYNC_MODE:-jspi}"
SPIKE_DIR="$(cd "$(dirname "$0")" && pwd)"
CRATE_DIR="$(cd "$SPIKE_DIR/.." && pwd)"
OUT_DIR="${OUT_DIR:-$SPIKE_DIR/dist}"
COMPILE_EXTENSION_PACKAGE="${COMPILE_EXTENSION_PACKAGE:-@php-wasm/compile-extension@3.1.27}"
PLAYGROUND_REPO="${PLAYGROUND_REPO:-$(cd "$SPIKE_DIR/../../../../wordpress-playground" 2>/dev/null && pwd || true)}"
mkdir -p "$OUT_DIR"

if [ "$ASYNC_MODE" != "jspi" ]; then
  echo "Unsupported ASYNC_MODE: $ASYNC_MODE. @php-wasm/compile-extension is JSPI-only." >&2
  exit 1
fi

case "$PHP_VERSION" in
  8.0) PHP_API_VERSION=20200930; PHP_RELEASE=8.0.30 ;;
  8.1) PHP_API_VERSION=20210902; PHP_RELEASE=8.1.34 ;;
  8.2) PHP_API_VERSION=20220829; PHP_RELEASE=8.2.30 ;;
  8.3) PHP_API_VERSION=20230831; PHP_RELEASE=8.3.30 ;;
  8.4) PHP_API_VERSION=20240924; PHP_RELEASE=8.4.20 ;;
  8.5) PHP_API_VERSION=20250925; PHP_RELEASE=8.5.5 ;;
  7.4)
    echo "Unsupported PHP_VERSION: 7.4" >&2
    echo "The WASM Rust build uses ext-php-rs 0.15, which depends on PHP 8 Zend APIs and does not compile against PHP 7.4 headers." >&2
    exit 1
    ;;
  *)
    echo "Unsupported PHP_VERSION: $PHP_VERSION. Supported values: 8.0, 8.1, 8.2, 8.3, 8.4, 8.5." >&2
    echo "PHP 7.4 is outside this WASM Rust path because ext-php-rs 0.15 requires PHP 8 Zend APIs." >&2
    exit 1
    ;;
esac

if [ -z "$PLAYGROUND_REPO" ] || [ ! -f "$PLAYGROUND_REPO/packages/php-wasm/compile/Makefile" ] || [ ! -f "$PLAYGROUND_REPO/packages/php-wasm/compile-extension/docker/Dockerfile.ext" ]; then
  echo "PLAYGROUND_REPO must point at a wordpress-playground checkout with packages/php-wasm/compile and packages/php-wasm/compile-extension/docker." >&2
  exit 1
fi

RUST_IMAGE="playground-php-wasm-ext-rust:${PHP_VERSION}-${ASYNC_MODE}"
BASE_IMAGE="playground-php-wasm:compile-extension-php${PHP_VERSION//./-}-${ASYNC_MODE}"

echo "==> Stage 0: preparing $BASE_IMAGE via Playground compile-extension tooling"
make -C "$PLAYGROUND_REPO/packages/php-wasm/compile" base-image
docker build \
  -f "$PLAYGROUND_REPO/packages/php-wasm/compile-extension/docker/Dockerfile.ext" \
  "$PLAYGROUND_REPO/packages/php-wasm" \
  --tag="$BASE_IMAGE" \
  --progress=plain \
  --build-arg "PHP_VERSION=$PHP_RELEASE" \
  --build-arg "JSPI=yes"

echo "==> Stage 0: building $RUST_IMAGE"
docker build \
  --build-arg "BASE_IMAGE=$BASE_IMAGE" \
  --build-arg "HOST_PHP_VERSION=$PHP_VERSION" \
  --build-arg "HOST_PHP_API_VERSION=$PHP_API_VERSION" \
  -t "$RUST_IMAGE" \
  -f "$SPIKE_DIR/Dockerfile.rust" \
  "$SPIKE_DIR"

echo "==> Stage 1: cargo build --target wasm32-unknown-emscripten"
# Feed the container script through stdin so Docker-side comments and strings
# cannot break host-shell quoting.
docker run --rm -i \
  -e "PHP_VERSION=$PHP_VERSION" \
  -e "PHP_API_VERSION=$PHP_API_VERSION" \
  -v "$CRATE_DIR":/src:ro \
  -v "$OUT_DIR":/out \
  --entrypoint bash \
  "$RUST_IMAGE" -s <<'EOF'
    set -ex
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
    #
    # `-sSUPPORT_LONGJMP=wasm` and `-fwasm-exceptions` must match the
    # PHP-wasm main module's exception/longjmp ABI. Without them, the
    # ext-php-rs `wrapper.c` is compiled with emscripten's legacy JS-based
    # SjLj, which imports the `__THREW__` global. The main module exports
    # only the wasm-native variants, so dlopen() rejects the side module
    # with "bad export type for '__THREW__': undefined".
    export CFLAGS_wasm32_unknown_emscripten="-fPIC -DZEND_ENABLE_ZVAL_LONG64 -D__x86_64__ -sSUPPORT_LONGJMP=wasm -fwasm-exceptions"
    # Tell cargo/rustc to build with PIE-friendly relocations as well.
    # `-C relocation-model=pic` is required for the side-module link.
    # `-C panic=abort` keeps the Rust archive from importing the C++
    # exception tag (`__cpp_exception`) that the PHP-wasm main module
    # does not export — without this, dlopen() fails with a
    # WebAssembly.Instance LinkError on Import "env" "__cpp_exception".
    export CARGO_TARGET_WASM32_UNKNOWN_EMSCRIPTEN_RUSTFLAGS="-C relocation-model=pic -C panic=abort"

    # Operate on a copy so the sed Cargo.toml flip never touches /src.
    rm -rf /work
    mkdir -p /work
    cp -a /src/. /work/
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

    # PHP.wasm does not export every Zend global as a wasm global that a side
    # module can import. Avoid those imports by resolving class entries from
    # the runtime class table when ext-php-rs needs them.
    patch_class_entry() {
      sed -i "s/unsafe { $1.as_ref() }.unwrap()/ClassEntry::try_find(\"$2\").unwrap()/" \
        "$REG/ext-php-rs-0.15.12/src/zend/ce.rs"
    }
    patch_class_entry zend_standard_class_def stdClass
    patch_class_entry zend_ce_throwable Throwable
    patch_class_entry zend_ce_exception Exception
    patch_class_entry zend_ce_error_exception ErrorException
    patch_class_entry zend_ce_compile_error CompileError
    patch_class_entry zend_ce_parse_error ParseError
    patch_class_entry zend_ce_type_error TypeError
    patch_class_entry zend_ce_argument_count_error ArgumentCountError
    patch_class_entry zend_ce_value_error ValueError
    patch_class_entry zend_ce_arithmetic_error ArithmeticError
    patch_class_entry zend_ce_division_by_zero_error DivisionByZeroError
    patch_class_entry zend_ce_unhandled_match_error UnhandledMatchError
    patch_class_entry zend_ce_traversable Traversable
    patch_class_entry zend_ce_aggregate IteratorAggregate
    patch_class_entry zend_ce_iterator Iterator
    patch_class_entry zend_ce_arrayaccess ArrayAccess
    patch_class_entry zend_ce_serializable Serializable
    patch_class_entry zend_ce_countable Countable
    patch_class_entry zend_ce_stringable Stringable

    # ClassEntry::try_find can ask Zend directly. The executor global check
    # only forces an extra side-module import that PHP.wasm does not provide.
    sed -i '/ExecutorGlobals::get().class_table()?;/d' \
      "$REG/ext-php-rs-0.15.12/src/zend/class.rs"

    # Class constant stub strings are only used for generated PHP stubs, but
    # ext-php-rs computes them during module startup by walking the actual zval.
    # The lexer registers several large array constants, and walking those
    # arrays imports helpers such as zend_array_count that PHP.wasm does not
    # expose as callable side-module functions. Keep runtime constant
    # registration intact, but use a placeholder stub value for this WASM build.
    sed -i 's/let stub = crate::convert::zval_to_stub(&zval);/let stub = String::from("null");/' \
      "$REG/ext-php-rs-0.15.12/src/builders/class.rs"

    # PHP.wasm exports zend_declare_class_constant_ex, but not the legacy
    # zend_declare_class_constant helper. Use the exported API when ext-php-rs
    # registers class constants.
    sed -i '/zend_declare_class_constant,/a \    zend_declare_class_constant_ex,' \
      "$REG/ext-php-rs-0.15.12/allowed_bindings.rs"
    sed -i \
      's/zend_declare_class_constant, zend_declare_property,/zend_declare_class_constant_ex, zend_declare_property,/' \
      "$REG/ext-php-rs-0.15.12/src/builders/class.rs"
    perl -0pi -e 's/zend_declare_class_constant\(\n\s+class,\n\s+CString::new\(name\.as_str\(\)\)\?\.as_ptr\(\),\n\s+name\.len\(\),\n\s+value,\n\s+\);/let mut name = ZendStr::new_interned(name.as_str(), true);\n                zend_declare_class_constant_ex(\n                    class,\n                    name.as_mut_ptr(),\n                    value,\n                    crate::ffi::ZEND_ACC_PUBLIC as _,\n                    ptr::null_mut(),\n                );/s' \
      "$REG/ext-php-rs-0.15.12/src/builders/class.rs"

    # ext-php-rs keeps helper functions for globals in one C translation unit.
    # The Rust staticlib can pull in the whole object even when a helper is not
    # called, so mark optional PHP globals weak to keep dlopen from requiring
    # PHP.wasm exports for unused helpers.
    sed -i \
      -e '/#pragma weak zend_one_char_string/a #pragma weak executor_globals' \
      -e '/#pragma weak zend_one_char_string/a #pragma weak compiler_globals' \
      -e '/#pragma weak zend_one_char_string/a #pragma weak core_globals' \
      -e '/#pragma weak zend_one_char_string/a #pragma weak sapi_globals' \
      -e '/#pragma weak zend_one_char_string/a #pragma weak file_globals' \
      -e '/#pragma weak zend_one_char_string/a #pragma weak sapi_module' \
      "$REG/ext-php-rs-0.15.12/src/wrapper.c"

    # php_eval is not used by this extension, but ext-php-rs exposes wrappers
    # for it from wrapper.c. Since the wrapper object is linked into the static
    # archive, those wrappers can still pull in PHP compile/execute symbols that
    # PHP.wasm does not export. Stub the unused wrappers so module startup does
    # not try to resolve them through JS import stubs.
    sed -i '/^zend_op_array \*ext_php_rs_zend_compile_string(/,/^}/c\
zend_op_array *ext_php_rs_zend_compile_string(zend_string *source, const char *filename) {\
  (void) source;\
  (void) filename;\
  return NULL;\
}' "$REG/ext-php-rs-0.15.12/src/wrapper.c"
    sed -i '/^void ext_php_rs_zend_execute(/,/^}/c\
void ext_php_rs_zend_execute(zend_op_array *op_array) {\
  (void) op_array;\
}' "$REG/ext-php-rs-0.15.12/src/wrapper.c"

    # Avoid ext-php-rs' direct Rust import of the sapi_module global in output
    # helpers. Route it through the weak C wrapper instead.
    sed -i 's/ffi::{php_output_write, php_printf, sapi_module}/ffi::{php_output_write, php_printf}/' \
      "$REG/ext-php-rs-0.15.12/src/zend/mod.rs"
    sed -i 's/sapi_module\.ub_write/(*crate::ffi::ext_php_rs_sapi_module()).ub_write/' \
      "$REG/ext-php-rs-0.15.12/src/zend/mod.rs"
    sed -i 's/sapi_module\.name/(*crate::ffi::ext_php_rs_sapi_module()).name/' \
      "$REG/ext-php-rs-0.15.12/src/zend/mod.rs"

    # Verify the targeted patches matched the registry sources before doing an
    # expensive cargo build.
    ! grep -Eq 'unsafe \{ (zend_standard_class_def|zend_ce_[a-z_]+)\.as_ref\(\) \}\.unwrap\(\)' \
      "$REG/ext-php-rs-0.15.12/src/zend/ce.rs"
    ! grep -q 'ExecutorGlobals::get().class_table()' \
      "$REG/ext-php-rs-0.15.12/src/zend/class.rs"
    ! grep -q 'let stub = crate::convert::zval_to_stub(&zval)' \
      "$REG/ext-php-rs-0.15.12/src/builders/class.rs"
    grep -q 'zend_declare_class_constant_ex,' \
      "$REG/ext-php-rs-0.15.12/allowed_bindings.rs"
    grep -q 'zend_declare_class_constant_ex(' \
      "$REG/ext-php-rs-0.15.12/src/builders/class.rs"
    ! grep -q 'zend_declare_class_constant(' \
      "$REG/ext-php-rs-0.15.12/src/builders/class.rs"
    ! grep -q 'return zend_compile_string' \
      "$REG/ext-php-rs-0.15.12/src/wrapper.c"
    ! grep -q 'zend_execute(op_array' \
      "$REG/ext-php-rs-0.15.12/src/wrapper.c"
    grep -q '#pragma weak file_globals' \
      "$REG/ext-php-rs-0.15.12/src/wrapper.c"

    # Use nightly + -Zbuild-std=std,panic_abort so libstd is rebuilt with
    # panic=abort. Without rebuilding std, the precompiled libstd still
    # contains panic-unwind code that imports __cpp_exception.
    cargo +nightly build --release --target wasm32-unknown-emscripten \
        -Zbuild-std=std,panic_abort
    cp target/wasm32-unknown-emscripten/release/libwp_mysql_parser.a /out/
EOF

echo "==> Stage 2: phpize + emconfigure + emmake (@php-wasm/compile-extension)"
SRC_STAGE="$(mktemp -d)"
CLI_STAGE="$(mktemp -d)"
trap 'rm -rf "$SRC_STAGE" "$CLI_STAGE"' EXIT
cp "$SPIKE_DIR/shim/config.m4"               "$SRC_STAGE/"
cp "$SPIKE_DIR/shim/wp_mysql_parser_shim.c"  "$SRC_STAGE/"
cp "$OUT_DIR/libwp_mysql_parser.a"           "$SRC_STAGE/"

ARTIFACT="wp_mysql_parser-php${PHP_VERSION}-${ASYNC_MODE}.so"
COMPILE_EXTENSION_CLI="$CLI_STAGE/node_modules/@php-wasm/compile-extension/cli.js"

npm install --prefix "$CLI_STAGE" --no-audit --no-fund --ignore-scripts "$COMPILE_EXTENSION_PACKAGE"

(
  cd "$PLAYGROUND_REPO"
  node "$COMPILE_EXTENSION_CLI" \
    --source "$SRC_STAGE" \
    --name wp_mysql_parser \
    --php-versions "$PHP_VERSION" \
    --out "$OUT_DIR" \
    --jobs 1 \
    --extra-ldflags "/build/libwp_mysql_parser.a"
)

rm -rf "$SRC_STAGE"
trap - EXIT

echo "==> Built $OUT_DIR/$ARTIFACT"
