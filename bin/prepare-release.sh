#!/usr/bin/env bash
#
# Prepare a new release of the SQLite Database Integration plugin.
#
# Usage:
#   ./bin/prepare-release.sh <version>
#
# This script:
#   1. Verifies prerequisites (clean tree, trunk branch, up to date).
#   2. Creates a release branch and bumps version numbers.
#   3. Generates a changelog from merged PRs since the last release.
#   4. Commits the changes and creates a pull request.

set -euo pipefail
cd "$(dirname "$0")/.."

fail() {
	printf '\033[0;31mError: %s\033[0m\n' "$1" >&2
	exit 1
}

REPO_URL="https://github.com/WordPress/sqlite-database-integration"
VERSION_PHP="packages/mysql-on-sqlite/src/version.php"
LOAD_PHP="packages/plugin-sqlite-database-integration/load.php"
README_TXT="packages/plugin-sqlite-database-integration/readme.txt"
CURRENT_VERSION="$(sed -n "s/.*SQLITE_DRIVER_VERSION', '\(.*\)'.*/\1/p" "$VERSION_PHP")"

# 1. VALIDATE ARGUMENTS
NEW_VERSION="${1:-}"
NEW_VERSION="${NEW_VERSION#v}"

if [ -z "$NEW_VERSION" ]; then
	echo "Usage: $0 <version>"
	echo ""
	echo "Current version: $CURRENT_VERSION"
	exit 1
fi

if ! [[ "$NEW_VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+(-.+)?$ ]]; then
	fail "Invalid version format: $NEW_VERSION"
fi

# 2. VERIFY PREREQUISITES
command -v git >/dev/null 2>&1 || fail "git is not installed."
command -v gh >/dev/null 2>&1 || fail "gh CLI is not installed."

[ -z "$(git status --porcelain)" ] || fail "Working tree is not clean."

BRANCH="$(git rev-parse --abbrev-ref HEAD)"
[ "$BRANCH" = "trunk" ] || fail "Not on trunk branch (current: $BRANCH)."

git pull --ff-only origin trunk --quiet || fail "trunk is not up to date with origin/trunk."

# 3. GENERATE CHANGELOG
LATEST_TAG="v$CURRENT_VERSION"

echo "Generating changelog from merged PRs since $LATEST_TAG..."
TAG_DATE="$(git log -1 --format='%aI' "$LATEST_TAG" 2>/dev/null | cut -d'T' -f1 || true)"
CHANGELOG=""

if [ -n "$TAG_DATE" ]; then
	CHANGELOG="$(gh pr list \
		--state merged \
		--base trunk \
		--search "merged:>=$TAG_DATE" \
		--limit 100 \
		--json number,title \
		--jq '.[] | "* \(.title) (#\(.number))"' 2>/dev/null \
		| grep -v "^\* Release $CURRENT_VERSION " || true)"
fi

if [ -z "$CHANGELOG" ]; then
	CHANGELOG="* (no changes listed)"
fi

# 4. PREPARE RELEASE BRANCH
RELEASE_BRANCH="release/v$NEW_VERSION"
echo "Creating branch $RELEASE_BRANCH..."
git checkout -b "$RELEASE_BRANCH"

# Bump version in version.php.
sed -i'' -e "s/define( 'SQLITE_DRIVER_VERSION', '.*' );/define( 'SQLITE_DRIVER_VERSION', '$NEW_VERSION' );/" "$VERSION_PHP"

# Bump version in load.php.
sed -i'' -e "s/^ \* Version: .*/ * Version: $NEW_VERSION/" "$LOAD_PHP"

# Bump version in readme.txt.
sed -i'' -e "s/^Stable tag:[[:space:]]*.*/Stable tag:        $NEW_VERSION/" "$README_TXT"

# Update changelog in readme.txt.
if ! grep -Fxq '== Changelog ==' "$README_TXT"; then
	printf '\n== Changelog ==\n' >> "$README_TXT"
fi
TMPFILE="$(mktemp)"
trap 'rm -f "$TMPFILE"' EXIT
printf '\n= %s =\n\n%s\n' "$NEW_VERSION" "$CHANGELOG" > "$TMPFILE"
sed -i'' -e "/^== Changelog ==$/r $TMPFILE" "$README_TXT"

# 5. CREATE A PULL REQUEST
git add "$VERSION_PHP" "$LOAD_PHP" "$README_TXT"
git commit -m "Prepare release $NEW_VERSION"
git push origin "$RELEASE_BRANCH"

PR_URL="$(gh pr create \
	--base trunk \
	--head "$RELEASE_BRANCH" \
	--title "Release $NEW_VERSION" \
	--assignee @me \
	--reviewer @me \
	--body "$(cat <<EOF
Version bump and changelog update for release $NEW_VERSION.

[Commits since $LATEST_TAG]($REPO_URL/compare/$LATEST_TAG...$RELEASE_BRANCH)

## Next steps

1. Review the version bump and changelog in this PR.
2. Push any additional edits to the \`$RELEASE_BRANCH\` branch.
3. Mark this PR as ready for review and merge it.

Merging will automatically build the plugin ZIP, create a GitHub
release, and deploy to WordPress.org.
EOF
)")"

echo ""
echo "PR created: $PR_URL"
echo ""
echo "Review the changelog, push edits if needed, then merge to release."
