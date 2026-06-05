# Turso source patches

The CI lane `PHPUnit Tests (Turso DB)` runs the test suite against
[Turso DB](https://github.com/tursodatabase/turso) (a Rust
reimplementation of SQLite). Turso is still in beta and a number of
issues need to be papered over for `pdo_sqlite` and the driver to run
green. Each fix lives in its own script here so the rationale is
discoverable and individual patches can be retired as upstream lands
fixes.

## Layout

- `NN-name.py` — one Python script per fix, applied in lexicographic
  order. Each script does a string-replace against a Turso source
  file and `assert`s the original block exists, so upstream churn
  fails loudly with a clear message instead of silently mis-applying.
- `apply.sh` — wrapper that loops over `NN-*.py`. The workflow runs
  this from the Turso source root.

## Adding a fix

1. Create `NN-short-name.py`. Use the next free 2-digit prefix.
2. Document the *why*, *repro*, and *fix* at the top of the file.
3. Encode the change as `OLD` / `NEW` string blocks with `assert OLD in
   src` (or a regex with explicit fallthrough on no match — see
   `02-column-functions-null-row.py`).
4. Mark with `(UPSTREAM)` in the docstring if the bug is a real Turso
   bug (vs. an environment workaround) so it shows up under
   `grep -l UPSTREAM` when cataloguing what to file upstream.

## Retiring a fix

When upstream lands the equivalent fix and the workflow bumps the
pinned Turso commit past it, the corresponding script's `assert` will
trip. Delete the script and the workflow keeps green.
