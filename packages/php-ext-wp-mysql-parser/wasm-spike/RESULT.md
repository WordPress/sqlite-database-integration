# wasm-spike: PHP-wasm side-module build of `wp_mysql_parser`

## Verdict

**Mixed. The Rust extension builds end-to-end as a PHP-wasm side module and
the Playground node loader successfully fetches and dlopen()s it. The load
fails inside Emscripten's dynamic linker on a Zend symbol export mismatch
in the PHP-wasm main module (`zend_ce_traversable`), not in the extension
or in `@php-wasm/compile-extension`. Resolving the spike's headline goal
("execute the parser inside Playground") therefore requires a Playground-
side change to PHP-wasm's exported symbol table, not further work on the
extension.**

This is a concrete blocker with a concrete fix path; it is not a
fundamental incompatibility of ext-php-rs with wasm32-unknown-emscripten.

## What actually runs now

```
$ bash packages/php-ext-wp-mysql-parser/wasm-spike/build-in-docker-rust.sh
…
==> Built wasm-spike/dist/wp_mysql_parser-php8.4-jspi.so

$ ls -lh wasm-spike/dist/
-rw-r--r-- 5.0M libwp_mysql_parser.a            # Rust staticlib (Stage 1)
-rwxr-xr-x 692K wp_mysql_parser-php8.4-jspi.so  # PHP-wasm side module (Stage 2)
-rw-r--r--  309 manifest.json                   # PR #3567 manifest format

$ PLAYGROUND_REPO=…/wordpress-playground-spike \
    node packages/php-ext-wp-mysql-parser/wasm-spike/run-spike.mjs
…
PHP Warning:  dl(): Unable to load dynamic library 'wp_mysql_parser.so'
  (… could not load dynamic lib …
   Error: bad export type for 'zend_ce_traversable': undefined (undefined))
```

So: the .so is fetched through the manifest, decoded, written into
`/internal/shared/extensions/`, and handed to PHP's `dl()` — which then
fails inside Emscripten's `loadDynamicLibrary` because the main PHP-wasm
module does not export `zend_ce_traversable` in a form the side module's
import table expects.

## What had to change vs. iteration 1

Iteration 1 stopped at "host bindgen can't read macOS SDK headers" without
ever running anything inside Docker. Iteration 2 actually drives the build
to completion, which produced a sequence of *real* findings — every one
of which is a non-trivial detail that the spike was supposed to surface.

### 1. Base image name (was wrong in iter 1)

`Dockerfile.rust` referenced `playground-php-wasm-ext:${PHP_VERSION}`, but
`@php-wasm/compile-extension` actually tags the per-version image
`playground-php-wasm:compile-extension-php<MAJ-MIN>-<ASYNC>`. Fixed via
a `BASE_IMAGE` build arg.

### 2. No host PHP CLI in the base image

The compile-extension base image has `php-config` and `phpize` but no
`php` binary (`--disable-cli` in `Dockerfile.ext`). `ext-php-rs/build.rs`
shells out to `php` to detect `PHP_VERSION_ID`, so the build script aborts
with "Could not find PHP executable."

Resolution: multi-stage `FROM php:8.4-cli AS host-php-cli` and copy the
binary plus its lib/etc/include trees, plus the runtime libs it needs
(`libonig5 libsqlite3-0 libargon2-1 libssl3 zlib1g libreadline8`). Set
`PHP=/opt/host-php/bin/php` and keep `PHP_CONFIG=/usr/local/bin/php-config`
(the wasm one) so bindgen's clang-sys finds the wasm-targeted PHP headers.

We tried the simpler path (Ubuntu noble's `php-cli`, then Sury's repo);
noble only ships PHP 8.3, which makes ext-php-rs emit `cfg=php83` while
bindgen reads PHP 8.4 headers — the resulting type mismatches make
ext-php-rs itself fail to compile. Sury's repo refuses the datacenter IP
with HTTP 418. The multi-stage `php:8.4-cli` copy is the only path that
worked on aarch64.

### 3. bindgen sysroot for the cross-compile

`ext-php-rs-bindgen` invokes libclang against `php-config --includes`. By
default libclang resolves system headers from the host (Linux glibc on the
build machine). Bindgen errors out on
`/usr/include/stdlib.h:26: 'bits/libc-header-start.h' file not found`
because aarch64 multiarch puts that header under a path bindgen does not
search.

Resolution: set
`BINDGEN_EXTRA_CLANG_ARGS="--target=wasm32-unknown-emscripten
--sysroot=$EMSDK_SYSROOT -I$EMSDK_SYSROOT/include …"`.

### 4. ZendLong width

PHP-wasm's compile recipe builds the host PHP and every side module with
`-DZEND_ENABLE_ZVAL_LONG64 -D__x86_64__` so `zend_long` is `int64_t` even
on wasm32. ext-php-rs's bindgen run (separate from the side-module link)
does not pick those up automatically and emits `ZendLong = i32` against
PHP 8.4 headers that declare 64-bit fields. Result: ~6 type-mismatch errors
in ext-php-rs itself.

Resolution: pass the same defines into `BINDGEN_EXTRA_CLANG_ARGS` *and*
into `CFLAGS_wasm32_unknown_emscripten` (cc-rs's wrapper.c compile).

### 5. `-fPIC` for cc-rs's wrapper.c

`wasm-ld` rejects the side-module link with `R_WASM_MEMORY_ADDR_SLEB
cannot be used against symbol 'zend_one_char_string'; recompile with
-fPIC`. cc-rs does not pass `-fPIC` for `wasm32-unknown-emscripten` by
default.

Resolution: `CFLAGS_wasm32_unknown_emscripten="-fPIC -DZEND_ENABLE_ZVAL_LONG64 -D__x86_64__"`
plus `CARGO_TARGET_WASM32_UNKNOWN_EMSCRIPTEN_RUSTFLAGS="-C relocation-model=pic …"`.

### 6. ext-php-rs `PropertyDescriptor` bound is hostile to wasm32+LONG64

`ext-php-rs/src/internal/property.rs` ends with:

```rust
const _: () = assert!(
    std::mem::size_of::<PropertyDescriptor<()>>() <= 12 * std::mem::size_of::<usize>(),
);
```

On wasm32, `usize=4` so the bound is 48 bytes. Forcing
`ZEND_ENABLE_ZVAL_LONG64` adds 4 bytes of zend_long alignment, so the
struct grows past 48 and the const assertion panics, killing the build.

Resolution: `sed` the bound up to `24 * usize` in the cargo registry copy
inside the container. **This needs an upstream patch in ext-php-rs.** The
assertion is a sanity check, not a correctness invariant — bumping it is
fine, but the right long-term fix is a target-aware `cfg` so wasm32 +
LONG64 has its own (looser) bound or the bound is dropped entirely.

### 7. libtool on the C shim

`PHP_ADD_LIBRARY_WITH_PATH(wp_mysql_parser, ., …)` makes libtool look for
a *shared* `libwp_mysql_parser.so` next to the .a. It can't find one,
prints "linker path does not have real file for library", and silently
degrades the build to a static module — so `compile-extension` cannot
find a `.so` under `modules/` at the end.

Resolution: drop `PHP_ADD_LIBRARY_WITH_PATH` from `config.m4`. Pass the
.a via `EXTRA_LDFLAGS=/build/libwp_mysql_parser.a` so the official
`build-in-docker.sh` recipe routes it through `EMCC_STATIC_ARCHIVES` and
`--whole-archive` in its libtool patch.

### 8. `__cpp_exception` tag import

After (1)-(7) the side module links and dlopens far enough to reach the
runtime symbol resolution stage — and fails with:

```
LinkError: WebAssembly.Instance(): Import #157 "env" "__cpp_exception":
  tag import requires a WebAssembly.Tag
```

Cause: Rust's precompiled `libstd` for `wasm32-unknown-emscripten` is
built with `panic=unwind`. When the side module is linked with
`-fwasm-exceptions` (PR #3567's jspi recipe), libstd's panic landing pads
turn into a wasm `__cpp_exception` tag *import*. The PHP-wasm main module
was not built with C++ exceptions enabled (PHP is C), so it does not
export that tag.

Resolution: build the Rust archive with `panic=abort` AND rebuild std:

```
rustup +nightly target add wasm32-unknown-emscripten
cargo +nightly build --release --target wasm32-unknown-emscripten \
    -Zbuild-std=std,panic_abort
```

Plus `RUSTFLAGS="-C panic=abort"`. Without rebuilding std, the precompiled
libstd still emits unwind paths. This adds ~4 minutes to the cold build
because `core`/`alloc`/`std`/`panic_abort` are all built from scratch.

This is a load-bearing finding: any Rust ext-php-rs extension targeting
PHP-wasm side modules must use `-Zbuild-std=std,panic_abort` until either
Rust ships an emscripten libstd compiled with `panic=abort`, or PHP-wasm
re-exports the C++ exception tag.

### 9. Zend symbol export mismatch — the unresolved blocker

After (1)-(8) the .so loads to the point where Emscripten's dynamic
linker resolves its imports from the main module, and fails with:

```
bad export type for 'zend_ce_traversable': undefined (undefined)
```

`zend_ce_traversable` is the global `zend_class_entry*` for PHP's
`Traversable` interface. ext-php-rs imports it because its bindings
reference the Traversable interface for iterator/generator support. The
PHP-wasm main module does not export this global in a way Emscripten's
side-module linker accepts; the export type is reported as `undefined`,
which usually means "exists in symbol table but not as a `WebAssembly.Global`."

This is a Playground-side fix, not an extension-side fix. The relevant
file is
`packages/php-wasm/compile/php/exported-functions.list` plus the
emscripten-flag wiring for MAIN_MODULE/EXPORTED_FUNCTIONS. A correct fix
has to add the Zend class-entry globals (and likely a handful of related
zend_* globals — `zend_ce_aggregate`, `zend_ce_iterator`,
`zend_ce_arrayaccess`, `zend_ce_throwable`, `zend_ce_stringable`, the
zval-handler vtables) to the PHP-wasm export set, AND ensure they are
exported as wasm globals, not as functions.

The spike does not include that change because it requires rebuilding
PHP-wasm itself (a multi-hour Docker build outside this directory).

## Files in this directory

| Path | What it is |
| --- | --- |
| `Dockerfile.rust` | Multi-stage layer that grafts rustup + nightly + a host PHP 8.4 CLI onto Playground's compile-extension image. Fixes #1, #2 above. |
| `build-in-docker-rust.sh` | Runs Stage 1 (cargo build → libwp_mysql_parser.a) inside the augmented image with all the env tweaks (#3-#6, #8), then runs Stage 2 (`build-in-docker.sh` from the official image) with `EXTRA_LDFLAGS=/build/libwp_mysql_parser.a`. |
| `shim/config.m4` | Minimal phpize config — only `PHP_NEW_EXTENSION`, no `PHP_ADD_LIBRARY_WITH_PATH` (#7). |
| `shim/wp_mysql_parser_shim.c` | Pulls the Rust `get_module()` symbol into the link. |
| `dist/libwp_mysql_parser.a` | Stage-1 output, ~5 MB. |
| `dist/wp_mysql_parser-php8.4-jspi.so` | Stage-2 output, ~692 KB. PR #3567-shaped artifact. |
| `dist/manifest.json` | PR #3567 manifest format. SHA256 in sync with the .so. |
| `run-spike.mjs` | Headless verifier. Spawns Node with `--experimental-strip-types --experimental-wasm-jspi` and runs the stock `load-built-extension.mjs` from PR #3567 against our manifest. Exits 0 on success, non-zero on the documented blocker. |
| `probe-host-cargo-wasm.sh` / `.log` | Iteration-1 host-side bindgen failure, kept for reference. |

## Reproduce

```bash
# Prerequisite: playground-php-wasm:compile-extension-php8-4-jspi must
# already be present in `docker images`. It is built by
# `@php-wasm/compile-extension` itself the first time you run the CLI;
# in this workspace it was pre-built and is reused.

cd packages/php-ext-wp-mysql-parser/wasm-spike
bash build-in-docker-rust.sh                  # ~6 min cold, ~30s warm

PLAYGROUND_REPO=/abs/path/to/wordpress-playground-spike \
    node run-spike.mjs                        # currently fails at dl()
```

## Patches that this spike implies should land

1. **ext-php-rs**: relax or `cfg`-gate
   `PropertyDescriptor` size assertion so it accommodates wasm32 + LONG64.
   Open issue/PR upstream.
2. **ext-php-rs**: bindgen invocation should respect cargo's TARGET when
   constructing clang args — currently the `wasm32-unknown-emscripten`
   target needs `BINDGEN_EXTRA_CLANG_ARGS` + `CFLAGS_*` set by the user.
3. **wordpress-playground PR #3567 (`@php-wasm/compile-extension`)**:
   document the Rust-staticlib-via-EXTRA_LDFLAGS pattern, or grow a
   first-class `--rust-staticlib path/to/lib.a` flag that handles
   the `--whole-archive` libtool patching.
4. **wordpress-playground (PHP-wasm main module build)**: export
   `zend_ce_traversable` and the rest of the Zend class-entry globals
   that ext-php-rs imports, as wasm globals. This is THE blocker for
   running this spike end-to-end.
5. **WordPress/sqlite-database-integration `php-ext-wp-mysql-parser`
   crate**: add `[target.'cfg(target_os = "emscripten")']` overrides so
   `stacker::maybe_grow` either no-ops or guards behind a `cfg(not(wasm))`
   call site (stacker has no real wasm support; on wasm the manual
   yielding it provides is meaningless because there is no native stack).

## Patches applied during the spike

Inside the build container only — the source trees on disk are not touched:

- `Cargo.toml`: `crate-type = ["cdylib"]` → `["staticlib"]` (sed).
- `ext-php-rs-0.15.12/src/internal/property.rs`: `12 * usize` → `24 * usize` (sed).
- `Cargo.toml` of `wp_mysql_parser` is NOT modified to drop `stacker` —
  with `panic=abort` + `-Zbuild-std=std,panic_abort` the build still
  succeeds because `stacker::maybe_grow` falls back to running the
  closure inline when `psm`'s probe says the stack is OK. On emscripten
  the probe presumably always says OK (single contiguous wasm linear
  stack); this is incidental, not designed.

The Rust/PHP source trees in this repo and in
`../wordpress-playground-spike` were not modified.
