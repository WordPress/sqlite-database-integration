dnl C shim that lets the Playground compile-extension Docker pipeline (phpize +
dnl emconfigure + emmake) build a side module whose actual code is a
dnl pre-compiled Rust staticlib produced by `cargo build --target
dnl wasm32-unknown-emscripten --release`.
dnl
dnl The staticlib is NOT linked here through PHP_ADD_LIBRARY_WITH_PATH —
dnl libtool refuses to treat a wasm `.a` as a viable input for a `.so`
dnl link and silently degrades the build to a static module. Instead, the
dnl spike's wrapper (`build-in-docker-rust.sh`) passes the archive to
dnl `@php-wasm/compile-extension` via `--extra-ldflags
dnl /build/libwp_mysql_parser.a`, which injects it with `--whole-archive`
dnl into the final libtool link.

PHP_ARG_ENABLE([wp_mysql_parser],
  [whether to enable wp_mysql_parser],
  [AS_HELP_STRING([--enable-wp_mysql_parser], [Enable wp_mysql_parser])],
  [yes])

if test "$PHP_WP_MYSQL_PARSER" != "no"; then
  PHP_NEW_EXTENSION(wp_mysql_parser, wp_mysql_parser_shim.c, $ext_shared)
fi
