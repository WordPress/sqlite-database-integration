#!/usr/bin/env bash
#
# Compile sql_yacc.yy into a Bison --xml automaton dump.
#
# MySQL 8.4 is built with Bison 3.8.2. To make the output reproducible regardless
# of the host toolchain, Bison is run inside a digest-pinned Docker image
# (alpine:3.20 ships exactly 3.8.2, and the digest freezes its package index so
# apk installs the same Bison), and the version is ASSERTED so a changed image can
# never silently produce a different automaton. Override with
# BISON_IMAGE/BISON_VERSION.
#
set -euo pipefail

BISON_IMAGE="${BISON_IMAGE:-alpine:3.20@sha256:d9e853e87e55526f6b2917df91a2115c36dd7c696a35be12163d44e6e2a4b6bc}"
BISON_VERSION="${BISON_VERSION:-3.8.2}"
script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
build_dir="$script_dir/../build"

if [ ! -f "$build_dir/sql_yacc.yy" ]; then
	echo "error: $build_dir/sql_yacc.yy not found; run fetch-mysql-grammar.sh first." >&2
	exit 1
fi

echo "Running Bison (via ${BISON_IMAGE}) ..."
docker run --rm -v "$build_dir:/work" -w /work -e BISON_VERSION="$BISON_VERSION" "$BISON_IMAGE" sh -c '
	apk add --no-cache bison >/dev/null &&
	version="$(bison --version | head -1)" &&
	echo "  using $version" &&
	case "$version" in
		*" $BISON_VERSION") ;;
		*) echo "error: expected Bison $BISON_VERSION, got: $version" >&2; exit 1 ;;
	esac &&
	bison --xml=automaton.xml -o sql_yacc.tab.c sql_yacc.yy
'

# Keep only the automaton dump; discard the generated parser source.
rm -f "$build_dir"/sql_yacc.tab.* "$build_dir"/sql_yacc.output
echo "  -> $build_dir/automaton.xml"
