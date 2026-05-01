#!/usr/bin/env bash
# Probe 1: try to cross-compile the ext-php-rs crate from the host with
# Emscripten on PATH. Expected to FAIL at the bindgen step inside ext-php-rs's
# build.rs because the host php-config points at native PHP headers and
# emscripten's clang chokes on the host system headers they pull in.
#
# Surfacing that failure is the point: it documents which knobs would have to
# move (PHP headers from a wasm-built PHP, plus an emcc-aware bindgen target)
# before any further work is meaningful.
set -uo pipefail

cd "$(dirname "$0")/.."

if ! command -v emcc >/dev/null 2>&1; then
  echo "emcc not on PATH. Activate emsdk first." >&2
  exit 2
fi

if ! rustup target list --installed 2>/dev/null | grep -q wasm32-unknown-emscripten; then
  rustup target add wasm32-unknown-emscripten
fi

set -x
CC=emcc CXX=em++ AR=emar \
  cargo build --release --target wasm32-unknown-emscripten 2>&1 | tee wasm-spike/probe-host-cargo-wasm.log
exit "${PIPESTATUS[0]}"
