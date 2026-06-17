# SQLancer SQLite Findings

The scheduled SQLancer SQLite fuzz workflow records new failures in a GitHub
issue named `SQLancer SQLite replay findings`.

Each issue comment records one MySQL-accepted SQLancer statement that failed
when replayed through the SQLite driver. The workflow deduplicates comments by a
hidden finding hash and stops appending after 1000 findings. Reduce each finding,
move the reduced query into `tests/e2e/specs/sqlancer-fuzz-regressions.test.js`
and the package-level SQLancer test slice, then fix it in this PR.
