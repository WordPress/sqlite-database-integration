#!/usr/bin/env bash
#
# Fetch MySQL's grammar (sql_yacc.yy) and keyword table (lex.h) from the pinned
# mysql-server tag into the build workspace. Override the tag with MYSQL_TAG.
#
# For the default tag the downloads are verified against pinned SHA-256 sums, so
# the build fails loudly if the upstream files ever change (tags are not
# technically immutable). When MYSQL_TAG is overridden, verification is skipped
# and the new sums are printed for re-pinning.
#
set -euo pipefail

DEFAULT_TAG="mysql-8.4.10"
MYSQL_TAG="${MYSQL_TAG:-$DEFAULT_TAG}"
SQL_YACC_SHA256="91c79ba42c614439e2b134d454ce9da45da930dbfd81c0873476b09360dc2541"
LEX_H_SHA256="840953f229edea7af4e9bfe214e44ae1982c8d3efbc6ff68c8f2bd0356faa2cf"

# Pick a SHA-256 tool: sha256sum (GNU/Linux) or shasum (macOS/BSD).
if command -v sha256sum >/dev/null 2>&1; then
	sha256() { sha256sum "$@"; }
elif command -v shasum >/dev/null 2>&1; then
	sha256() { shasum -a 256 "$@"; }
else
	echo "error: neither sha256sum nor shasum is available." >&2
	exit 1
fi

script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
build_dir="$script_dir/../build"
base_url="https://raw.githubusercontent.com/mysql/mysql-server/${MYSQL_TAG}/sql"

mkdir -p "$build_dir"
echo "Fetching MySQL grammar sources at ${MYSQL_TAG} ..."
curl -fsSL "${base_url}/sql_yacc.yy" -o "$build_dir/sql_yacc.yy"
curl -fsSL "${base_url}/lex.h" -o "$build_dir/lex.h"

if [ "$MYSQL_TAG" = "$DEFAULT_TAG" ]; then
	echo "${SQL_YACC_SHA256}  $build_dir/sql_yacc.yy" | sha256 -c -
	echo "${LEX_H_SHA256}  $build_dir/lex.h" | sha256 -c -
else
	echo "  MYSQL_TAG overridden; skipping checksum verification. New sums (pin these for a tag bump):"
	sha256 "$build_dir/sql_yacc.yy" "$build_dir/lex.h" | sed 's/^/    /'
fi

echo "  -> $build_dir/sql_yacc.yy ($(wc -l < "$build_dir/sql_yacc.yy" | tr -d ' ') lines)"
echo "  -> $build_dir/lex.h ($(wc -l < "$build_dir/lex.h" | tr -d ' ') lines)"
