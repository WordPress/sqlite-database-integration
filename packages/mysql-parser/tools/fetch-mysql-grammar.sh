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

DEFAULT_TAG="mysql-8.4.3"
MYSQL_TAG="${MYSQL_TAG:-$DEFAULT_TAG}"
SQL_YACC_SHA256="09c33e9144fdd95d73f4a864a55baa1e3002ce6769ae1057cc06240ab70cee74"
LEX_H_SHA256="1114ccc9781bff96a80940090f21afa9ea690363899fea3669c51bbbcd1faa03"

script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
build_dir="$script_dir/../build"
base_url="https://raw.githubusercontent.com/mysql/mysql-server/${MYSQL_TAG}/sql"

mkdir -p "$build_dir"
echo "Fetching MySQL grammar sources at ${MYSQL_TAG} ..."
curl -fsSL "${base_url}/sql_yacc.yy" -o "$build_dir/sql_yacc.yy"
curl -fsSL "${base_url}/lex.h" -o "$build_dir/lex.h"

if [ "$MYSQL_TAG" = "$DEFAULT_TAG" ]; then
	echo "${SQL_YACC_SHA256}  $build_dir/sql_yacc.yy" | shasum -a 256 -c - >/dev/null
	echo "${LEX_H_SHA256}  $build_dir/lex.h" | shasum -a 256 -c - >/dev/null
	echo "  checksums OK"
else
	echo "  MYSQL_TAG overridden; skipping checksum verification. New sums (pin these for a tag bump):"
	shasum -a 256 "$build_dir/sql_yacc.yy" "$build_dir/lex.h" | sed 's/^/    /'
fi

echo "  -> $build_dir/sql_yacc.yy ($(wc -l < "$build_dir/sql_yacc.yy" | tr -d ' ') lines)"
echo "  -> $build_dir/lex.h ($(wc -l < "$build_dir/lex.h" | tr -d ' ') lines)"
