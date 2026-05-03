# WP MySQL Parser PHP Extension

This crate builds an optional PHP extension named `wp_mysql_parser`.

When the extension is loaded before `packages/mysql-on-sqlite/src/load.php`, it
registers native base classes used by the public `WP_MySQL_Lexer` and
`WP_MySQL_Parser` wrappers. Without the extension, those public wrappers extend
the pure-PHP implementations instead.

## Build

The build requires Rust, PHP development headers, `php-config`, and libclang.
Depending on the environment, `LIBCLANG_PATH` may need to point at the directory
containing `libclang.so`.

```bash
PHP_CONFIG=/path/to/php-config \
LIBCLANG_PATH=/path/to/libclang/lib \
cargo build --release
```

The resulting shared object is written to:

```text
target/release/libwp_mysql_parser.so
```

Load it for local test runs with:

```bash
php -d extension=/path/to/libwp_mysql_parser.so vendor/bin/phpunit
```

## PHP.wasm side-module build

The experimental PHP.wasm side-module build lives in `wasm-spike/` and is
verified in CI for PHP `8.0` through `8.5`.

PHP `7.4` is not supported by this Rust WASM path. The build uses
`ext-php-rs` `0.15`, which depends on PHP 8 Zend APIs and does not compile
against PHP `7.4` headers.
