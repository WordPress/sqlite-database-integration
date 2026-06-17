# SQLancer SQLite Findings

This file is an append-only queue for failures found by the scheduled SQLancer
SQLite fuzz workflow.

Each entry records a MySQL-accepted SQLancer statement that failed when replayed
through the SQLite driver. Reduce each finding, move the reduced query into
`tests/e2e/specs/sqlancer-fuzz-regressions.test.js` and the package-level
SQLancer test slice, then remove or mark the entry as handled in the follow-up
fix PR.
