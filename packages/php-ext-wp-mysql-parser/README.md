# WP MySQL Parser PHP Extension

`wp_mysql_parser` is an optional native PHP extension for the SQLite Database Integration project. It provides native implementations of the MySQL lexer/parser path used by the SQLite driver while keeping the pure-PHP implementation as the portable fallback.

When the extension is loaded before `packages/mysql-on-sqlite/src/load.php`, it registers native base classes used by the public `WP_MySQL_Lexer` and `WP_MySQL_Parser` wrappers. Without the extension, those public wrappers extend the pure-PHP implementations instead.

## Published WASM build for Playground

Published WASM builds are listed on this repository's GitHub Pages site, with manifest links, checksums, and a “Run in Playground” link for each release:

<https://wordpress.github.io/sqlite-database-integration/>

The current build is also available directly:

- Manifest: <https://wordpress.github.io/sqlite-database-integration/wp_mysql_parser-wasm-extension/latest/manifest.json>
- Checksums: <https://wordpress.github.io/sqlite-database-integration/wp_mysql_parser-wasm-extension/latest/SHA256SUMS>
- Supported PHP versions: 8.0, 8.1, 8.2, 8.3, 8.4, and 8.5.

Use this Playground URL to load the extension and open the demo/benchmark page:

<https://playground.wordpress.net/?php=8.4&php-extension=https%3A%2F%2Fwordpress.github.io%2Fsqlite-database-integration%2Fwp_mysql_parser-wasm-extension%2Flatest%2Fmanifest.json&blueprint-url=https%3A%2F%2Fwordpress.github.io%2Fsqlite-database-integration%2Fnative-extension%2Fblueprint.json>

## Build the native extension locally

Requirements:

- Rust toolchain.
- PHP development headers and `php-config`.
- libclang, with `LIBCLANG_PATH` pointing at the directory containing the libclang shared library when auto-detection is not enough.

```bash
(
	cd packages/php-ext-wp-mysql-parser
	PHP_CONFIG="$(command -v php-config)" \
	LIBCLANG_PATH=/path/to/libclang \
	cargo build --release
)
```

On macOS, if the linker reports unresolved Zend/PHP symbols, add dynamic lookup linker flags:

```bash
(
	cd packages/php-ext-wp-mysql-parser
	RUSTFLAGS='-C link-arg=-undefined -C link-arg=dynamic_lookup' \
	PHP_CONFIG="$(command -v php-config)" \
	LIBCLANG_PATH=/path/to/libclang \
	cargo build --release
)
```

The resulting shared object is written under `target/release/` as `libwp_mysql_parser.so` on Linux or `libwp_mysql_parser.dylib` on macOS.

From the repository root, load it for local verification or benchmarks:

```bash
php -d extension=/absolute/path/to/libwp_mysql_parser.so -m | grep wp_mysql_parser
php -d extension=/absolute/path/to/libwp_mysql_parser.so packages/mysql-on-sqlite/tests/tools/verify-native-parser-extension.php
```

## Verify the WASM artifacts

Download the manifest and checksums:

```bash
curl -O https://wordpress.github.io/sqlite-database-integration/wp_mysql_parser-wasm-extension/latest/manifest.json
curl -O https://wordpress.github.io/sqlite-database-integration/wp_mysql_parser-wasm-extension/latest/SHA256SUMS
```

Each `.so` listed in the manifest is published next to the manifest and can be checked against `SHA256SUMS`:

```bash
curl -O https://wordpress.github.io/sqlite-database-integration/wp_mysql_parser-wasm-extension/latest/wp_mysql_parser-php8.4-jspi.so
shasum -a 256 -c SHA256SUMS --ignore-missing
```

## Benchmarks

Run the pure-PHP lexer/parser benchmarks:

```bash
php packages/mysql-on-sqlite/tests/tools/run-lexer-benchmark.php --json
php packages/mysql-on-sqlite/tests/tools/run-parser-benchmark.php --json
```

Run the same parser benchmark with the native extension loaded:

```bash
php -d extension=/absolute/path/to/libwp_mysql_parser.so packages/mysql-on-sqlite/tests/tools/run-parser-benchmark.php --json
```

Compare the pure-PHP lexer with the native extension lexer in one process:

```bash
php -d extension=/absolute/path/to/libwp_mysql_parser.so packages/mysql-on-sqlite/tests/tools/run-native-extension-benchmark.php
php -d extension=/absolute/path/to/libwp_mysql_parser.so packages/mysql-on-sqlite/tests/tools/run-native-extension-benchmark.php --json
```

The GitHub Pages demo reads published benchmark data from:

<https://wordpress.github.io/sqlite-database-integration/native-extension/benchmark.json>

Latest local measurement (Apple Silicon macOS, PHP 8.4.5 CLI, 2026-05-26):

| Benchmark | Implementation | Queries | QPS | Speedup |
| --- | --- | ---: | ---: | ---: |
| MySQL lexer | Pure PHP | 69,577 | 71,553 | — |
| MySQL lexer | Native extension | 69,577 | 343,124 | 4.80x |
| MySQL parser | Pure PHP | 69,577 | 7,015 | — |
| MySQL parser | Native extension | 69,577 | 108,354 | 15.45x |

That file should be updated whenever a new extension build or benchmark environment is published.

## PHP.wasm side-module build

The experimental PHP.wasm side-module build lives in `wasm-spike/` and is verified in CI for PHP `8.0` through `8.5`.

PHP `7.4` is not supported by this Rust WASM path. The build uses `ext-php-rs` `0.15`, which depends on PHP 8 Zend APIs and does not compile against PHP `7.4` headers.
