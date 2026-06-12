#!/usr/bin/env bash
#
# Fetch the MySQL server test suite (the mysql-test directory) from the pinned
# mysql-server tag into the build workspace. Override the tag with MYSQL_TAG.
#
# The script uses a shallow clone with sparse checkout to reduce the download
# size. This is faster than downloading the full ZIP file, and it will enable
# us to easily download multiple tags to create per-version query corpora.
#
set -euo pipefail

DEFAULT_TAG="mysql-8.4.10"
MYSQL_TAG="${MYSQL_TAG:-$DEFAULT_TAG}"
# The commit the default tag points to. Tags are not technically immutable, so
# the resolved commit is verified after checkout and the build fails on a mismatch.
DEFAULT_TAG_COMMIT="6adc159923b7b6abbe649949551ec25264c2daf9"

script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
build_dir="$script_dir/../build"
clone_dir="$build_dir/mysql-tests"

rm -rf "$clone_dir"
mkdir -p "$build_dir"

echo "Fetching the MySQL server test suite at ${MYSQL_TAG} ..."
git clone --depth 1 --no-checkout https://github.com/mysql/mysql-server.git "$clone_dir"

cd "$clone_dir"
git config core.sparseCheckout true
echo "mysql-test/" > .git/info/sparse-checkout
git fetch --depth 1 origin tag "$MYSQL_TAG"
git checkout "tags/$MYSQL_TAG"

actual_commit="$(git rev-parse HEAD)"
if [ "$MYSQL_TAG" = "$DEFAULT_TAG" ]; then
	if [ "$actual_commit" != "$DEFAULT_TAG_COMMIT" ]; then
		echo "error: $MYSQL_TAG resolved to $actual_commit, expected $DEFAULT_TAG_COMMIT; the tag moved." >&2
		exit 1
	fi
	echo "  commit OK ($actual_commit)"
else
	echo "  MYSQL_TAG overridden; skipping verification. Resolved commit (pin this for a tag bump): $actual_commit"
fi

echo "  -> $clone_dir/mysql-test"
