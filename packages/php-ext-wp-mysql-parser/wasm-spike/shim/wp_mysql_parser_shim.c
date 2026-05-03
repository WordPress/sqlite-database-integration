/*
 * Trivial shim. ext-php-rs already exports `get_module()` from the Rust
 * crate when built with the `runtime` feature; phpize only needs a C
 * translation unit so that Autoconf has something to compile and so the
 * libtool archive command gets invoked. The real module entry comes from
 * libwp_mysql_parser.a, force-linked through PHP_ADD_LIBRARY_WITH_PATH +
 * --whole-archive in build-in-docker.sh.
 */
#include "php.h"

/* Forward-declare so the linker keeps the symbol from the Rust archive. */
extern zend_module_entry *get_module(void);

/* Reference get_module so the static library is not GC'd by the linker
 * even if the compile-extension whole-archive path changes. */
zend_module_entry *(*wp_mysql_parser_keep_alive)(void) = get_module;
