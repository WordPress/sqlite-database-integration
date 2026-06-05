#!/usr/bin/env bash
# Apply every Turso source patch in this directory, in lexicographic order.
#
# Run from the Turso source root (the `turso/` checkout the workflow
# clones). Each patch is a self-contained Python script that does a
# string-replace with a loud `assert` — if upstream Turso changes the
# surrounding code, the script aborts with a clear message and the
# affected patch needs regeneration.

set -euo pipefail

dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

shopt -s nullglob
patches=("$dir"/[0-9][0-9]-*.py)
if [[ ${#patches[@]} -eq 0 ]]; then
    echo "no patches found in $dir" >&2
    exit 1
fi

for patch in "${patches[@]}"; do
    name="$(basename "$patch")"
    echo "::group::$name"
    python3 "$patch"
    echo '::endgroup::'
done
