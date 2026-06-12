# wasm-spike: PHP.wasm side-module build of `wp_mysql_parser`

## Current status

The spike now builds and loads `wp_mysql_parser` in Playground across every
PHP version supported by the current `ext-php-rs` binding layer: 8.0 through
8.5, all JSPI. CI verifies each generated side module by loading its manifest
through Playground's compile-extension test harness and running a native lexer
smoke test. PHP 7.4 is outside this Rust path because `ext-php-rs` 0.15
depends on PHP 8 Zend APIs and does not compile against PHP 7.4 headers.

## PHP version scope

This spike intentionally builds only PHP 8.0 through PHP 8.5. Adding PHP 7.4
would require a different binding layer, or real PHP 7.4 support in
`ext-php-rs`; it is not a workflow-only change. The failures are in the
generated/wrapped Zend API surface itself, including PHP 8-only symbols and
struct fields.

The build uses the published `@php-wasm/compile-extension` CLI for the phpize
side-module build, static archive force-linking, wasm-opt pass, and manifest
generation. A sparse Playground checkout is still required for the
`packages/php-wasm/compile` Docker assets and for CI's Playground load test.
The CLI is installed into an isolated temporary npm prefix before it is run;
the sparse Playground workspace also contains an unbuilt local
`@php-wasm/compile-extension` package, so relying on workspace `.bin` links
would bypass the published package.
The local glue that remains is Rust-specific: build `libwp_mysql_parser.a`
with the same Emscripten/PHP.wasm ABI and patch the vendored `ext-php-rs`
registry copy so it can run as a PHP.wasm side module.

## Publishing a Playground extension artifact

`.github/workflows/publish-wasm-extension-artifact.yml` builds the JSPI side
module for PHP 8.0 through 8.5, collects the side modules into one Actions
artifact, publishes the same bundle to the `gh-pages` branch, and writes a
Playground extension manifest using the sidecar format from
WordPress/wordpress-playground#3580:

```json
{
  "name": "wp_mysql_parser",
  "mode": "php-extension",
  "artifacts": [
    {
      "phpVersion": "8.4",
      "sourcePath": "wp_mysql_parser-php8.4-jspi.so"
    }
  ]
}
```

The per-version build manifest comes from `@php-wasm/compile-extension` and
already uses `sourcePath` while omitting the retired `file` and `sha256`
artifact fields. The publish job writes a combined all-version manifest in the
same shape before uploading the final Actions artifact and publishing the
static bundle to GitHub Pages. The public URLs are:

- `https://wordpress.github.io/sqlite-database-integration/wp_mysql_parser-wasm-extension/latest/manifest.json`
- `https://wordpress.github.io/sqlite-database-integration/wp_mysql_parser-wasm-extension/<commit-sha>/manifest.json`

The uploaded manifest is intended for Playground builds that include the #3580
resolver change; older Playground loaders still expect `file`. Checksums are
published separately in `SHA256SUMS`. The repository's GitHub Pages source
must be configured to publish from the `gh-pages` branch root for these URLs to
resolve.

## What the Playground helper covers

`@php-wasm/compile-extension` is complete enough to replace the custom
Stage 2 path that previously lived in this spike:

- Builds the matching `playground-php-wasm:base` and per-version
  compile-extension Docker images.
- Runs `phpize`, `emconfigure`, and `emmake` for JSPI side modules.
- Accepts Rust or C static archives via `--extra-ldflags` and force-links
  `.a` inputs with `--whole-archive`.
- Runs `wasm-opt` when available.
- Emits a `sourcePath` manifest that the Playground runtime can load at
  startup.

The helper is not a full Rust build system. This crate still needs a
per-PHP-version prebuild step that produces a wasm32-unknown-emscripten
`staticlib` with matching PHP headers and host PHP CLI version, plus a tiny
phpize shim (`config.m4` and `wp_mysql_parser_shim.c`) so the helper has a
normal extension source directory to compile.

## Remaining local compatibility patches

The patches are applied only inside the Docker build container:

- Switch the crate from `cdylib` to `staticlib`.
- Build Rust nightly with `-Zbuild-std=std,panic_abort`, `panic=abort`, and
  position-independent code.
- Force bindgen/cc-rs to use the Emscripten sysroot plus
  `ZEND_ENABLE_ZVAL_LONG64` and `__x86_64__`, matching PHP.wasm.
- Relax ext-php-rs's `PropertyDescriptor` size assertion for wasm32 + LONG64.
- Avoid ext-php-rs imports of Zend globals/functions that are not part of the
  stable PHP.wasm side-module surface used by this extension.
- Use `zend_declare_class_constant_ex`, which PHP.wasm exports, instead of the
  legacy class-constant helper.

These should eventually move upstream into ext-php-rs or into a dedicated
Rust recipe in Playground, but they no longer duplicate the generic PHP.wasm
extension build and manifest machinery.

## Files

| Path | What it is |
| --- | --- |
| `Dockerfile.rust` | Rust/nightly/host-PHP layer on top of Playground's compile-extension image. |
| `build-in-docker-rust.sh` | Builds the Rust staticlib, then calls the published `@php-wasm/compile-extension` CLI. |
| `write-extension-manifest.mjs` | Writes the combined all-version artifact manifest for the publish workflow. |
| `shim/config.m4` | Minimal phpize wrapper. |
| `shim/wp_mysql_parser_shim.c` | Pulls the Rust `get_module()` symbol into the side-module link. |
| `run-spike.mjs` | Loads the generated manifest in Playground and verifies the native lexer. |

## Reproduce

```bash
cd packages/php-ext-wp-mysql-parser/wasm-spike

PLAYGROUND_REPO=/abs/path/to/wordpress-playground \
  bash build-in-docker-rust.sh

PLAYGROUND_REPO=/abs/path/to/wordpress-playground PHP_VERSION=8.4 \
  node run-spike.mjs
```

The workflow still uses a sparse checkout of `WordPress/wordpress-playground`
for `packages/php-wasm/compile` Docker assets and the Playground loader smoke
test, while the extension compile itself runs through an isolated install of
the published npm CLI.

Follow-up for Playground: make `@php-wasm/compile-extension` self-contained so
external extension projects do not need to shallow or sparse checkout
`WordPress/wordpress-playground` just to access Docker assets or test harness
files.
