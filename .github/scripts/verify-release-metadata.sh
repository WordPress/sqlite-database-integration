#!/usr/bin/env bash

set -euo pipefail

fail() {
	echo "$1" >&2
	exit 1
}

EXPECTED_VERSION="${1:-}"

PLUGIN_VERSION="$(sed -n 's/^ \* Version: \(.*\)$/\1/p' load.php | head -n 1 | tr -d '\r')"
STABLE_TAG="$(sed -n 's/^Stable tag:[[:space:]]*//p' readme.txt | head -n 1 | tr -d '\r')"
CONSTANT_VERSION="$(php -r "require 'version.php'; echo SQLITE_DRIVER_VERSION;")"

[ -n "$PLUGIN_VERSION" ] || fail 'Could not extract the plugin version from load.php.'
[ -n "$STABLE_TAG" ] || fail 'Could not extract the Stable tag from readme.txt.'
[ -n "$CONSTANT_VERSION" ] || fail 'Could not extract SQLITE_DRIVER_VERSION from version.php.'

[ "$PLUGIN_VERSION" = "$CONSTANT_VERSION" ] || fail "Version mismatch: load.php=$PLUGIN_VERSION, version.php=$CONSTANT_VERSION."
[ "$PLUGIN_VERSION" = "$STABLE_TAG" ] || fail "Version mismatch: load.php=$PLUGIN_VERSION, readme.txt Stable tag=$STABLE_TAG."

if [ -n "$EXPECTED_VERSION" ] && [ "$PLUGIN_VERSION" != "$EXPECTED_VERSION" ]; then
	fail "Version mismatch: expected $EXPECTED_VERSION, found $PLUGIN_VERSION in plugin metadata."
fi

grep -Fxq '== Changelog ==' readme.txt || fail 'readme.txt is missing a == Changelog == section.'

if ! awk -v version="$PLUGIN_VERSION" '
BEGIN {
	in_changelog = 0
	in_entry = 0
	has_content = 0
}
$0 == "== Changelog ==" {
	in_changelog = 1
	next
}
in_changelog && $0 == "= " version " =" {
	in_entry = 1
	next
}
in_entry && $0 ~ /^= / {
	exit has_content ? 0 : 1
}
in_entry && $0 !~ /^[[:space:]]*$/ {
	has_content = 1
}
END {
	if ( ! in_changelog || ! in_entry || ! has_content ) {
		exit 1
	}
}
' readme.txt; then
	fail "readme.txt must contain a changelog entry with content for version $PLUGIN_VERSION."
fi

echo "Verified release metadata for version $PLUGIN_VERSION."
