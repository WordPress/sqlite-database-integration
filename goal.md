# PostgreSQL MySQL Compatibility Goals

## Objective

Keep MySQL queries working the same way across the PostgreSQL and SQLite backends. Prefer MySQL-compatible emulation in the query layer over relying on PostgreSQL-specific behavior.

## Tasks

- [x] Centralize SQL mode parsing and normalization so PostgreSQL does not store `sql_mode` as an opaque raw string.
- [x] Make PostgreSQL's default SQL modes match SQLite's defaults:
  - `ERROR_FOR_DIVISION_BY_ZERO`
  - `NO_ENGINE_SUBSTITUTION`
  - `NO_ZERO_DATE`
  - `NO_ZERO_IN_DATE`
  - `ONLY_FULL_GROUP_BY`
  - `STRICT_TRANS_TABLES`
- [x] Support the same session SQL mode syntaxes as SQLite:
  - `SET sql_mode = ...`
  - `SET @@sql_mode = ...`
  - `SET SESSION sql_mode = ...`
  - `SET @@SESSION.sql_mode = ...`
  - user-variable save/restore flows such as `SET @old_sql_mode = @@SESSION.sql_mode` followed by `SET SESSION sql_mode = @old_sql_mode`
- [x] Ensure `SELECT @@sql_mode`, `SELECT @@SESSION.sql_mode`, `SELECT @@GLOBAL.sql_mode`, and `SHOW VARIABLES LIKE 'sql_mode'` report normalized emulated state.
- [x] Pass active SQL modes into every PostgreSQL MySQL lexer/parser construction, including direct lexer calls outside the main tokenization helper.
- [x] Add `ANSI_QUOTES` support to the PHP MySQL lexer.
- [x] Add `ANSI_QUOTES` support to the Rust native MySQL parser path.
- [x] When `ANSI_QUOTES` is active, tokenize double-quoted text as identifier-like, equivalent to backtick-quoted identifiers.
- [x] When `ANSI_QUOTES` is inactive, keep double-quoted text as string literals.
- [x] Preserve existing parser-mode behavior for:
  - `NO_BACKSLASH_ESCAPES`
  - `PIPES_AS_CONCAT`
  - `IGNORE_SPACE`
  - `HIGH_NOT_PRECEDENCE`
- [x] Update the PostgreSQL wpdb adapter so `set_sql_mode()` mirrors SQLite/core behavior when called without explicit modes.
- [x] Stop treating `ANSI_QUOTES` as inherently incompatible for PostgreSQL once lexer/parser support exists.
- [x] Emulate `NO_AUTO_VALUE_ON_ZERO` for PostgreSQL INSERT translation against auto-increment columns.
- [x] Enforce `NO_ZERO_DATE` and `NO_ZERO_IN_DATE` before PostgreSQL receives invalid MySQL date values.
- [x] Enforce strict-mode behavior for invalid values, truncation cases, invalid dates, and impossible coercions where PostgreSQL differs from MySQL.
- [x] Keep unsupported SQL explicit: translate/emulate supported MySQL constructs, and return clear unsupported-SQL errors for unsupported constructs.
- [x] Do not silently swallow unsupported SQL.
- [x] Continue treating `FULLTEXT` and `SPATIAL` as unsupported unless separate explicit support is added.
- [x] Audit regex/string-based PostgreSQL SQL translation paths that may bypass mode-aware tokenization.
- [x] Prefer tokenized translation paths where SQL mode affects parsing.

## CI And PR Gates

- [x] Remove `continue-on-error: true` from PostgreSQL and e2e jobs; failures should fail the PR once the expected failures are fixed or explicitly skipped.
- [x] Ensure any temporary PR skip logic cannot become a permanent default-branch skip after merge.
- [x] Keep end-to-end workflows running on default-branch pushes, not only pull requests.
- [x] If e2e jobs are skipped on PRs, make the skip condition explicit, documented, and limited to the intended event/path/label.
- [x] Keep PostgreSQL PHPUnit as a required, non-optional CI lane rather than a best-effort signal.
- [x] Keep the PR progress summary informational only; it must not hide failing PostgreSQL jobs.

## WP-CLI

- [x] Avoid relying on WP-CLI for PostgreSQL environment install/reset paths that assume a MySQL connection.
- [x] Generate PostgreSQL `wp-config.php` and `wp-tests-config.php` directly for WordPress PHPUnit runs.
- [x] Preserve PostgreSQL `DB_ENGINE` and `DATABASE_ENGINE` constants in both runtime and test configs.
- [x] Keep a small WP-CLI smoke check after config generation so WP-CLI still loads the PostgreSQL adapter and reports the expected constants.
- [x] Ensure PostgreSQL Docker PHP and CLI images both install and enable `pdo_pgsql`.

## DDL And Unsupported SQL

- [x] Translate supported `CREATE TABLE ... [AS] SELECT` forms for PostgreSQL.
- [x] Store MySQL-facing metadata for translated `CREATE TABLE ... [AS] SELECT` result tables.
- [x] Reject `CREATE TABLE ... [AS] SELECT` variants that mix unsupported table definitions, constraints, indexes, or MySQL-only options.
- [x] Return explicit unsupported-SQL errors for unsupported MySQL DDL instead of swallowing or silently passing through incompatible SQL.
- [x] Translate/emulate the supported constructs identified in this work except `FULLTEXT` and `SPATIAL`, which remain explicit unsupported cases.
- [x] Tolerate MySQL `FIRST`/`AFTER <column>` placement suffixes inside parenthesized `ALTER TABLE ... ADD (...)` column batches while preserving explicit errors for malformed placement.
- [x] Tolerate supported MySQL table/storage options in `ALTER TABLE` with either `OPTION=value` or `OPTION value` spelling as PostgreSQL no-ops.
- [x] Emulate the common plugin upsert side effect `ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)` for deterministic single-row AUTO_INCREMENT self-assignments.
- [x] Harden `ON DUPLICATE KEY UPDATE` expression assignments so resolved current-row columns and `VALUES(column)` work, while unknown column references fail before backend execution.

## Runtime And Metadata Parity

- [x] Emulate MySQL session identity runtime functions for PostgreSQL: `CURRENT_USER`, `CURRENT_USER()`, `USER()`, `SESSION_USER()`, and `SYSTEM_USER()`.
- [x] Emulate zero-argument `CONNECTION_ID()` using the same synthetic session ID exposed by `SHOW PROCESSLIST` and `information_schema.processlist`.
- [x] Emulate zero-argument `LAST_INSERT_ID()` without exact-query caching, so repeated calls reflect mutable insert state.
- [x] Emulate narrow standalone `LAST_INSERT_ID(expr)` scalar `SELECT` assignments for non-negative integer literals, and fail closed for table-backed, embedded, nonliteral, negative, and overflow forms.
- [x] Emulate zero-argument `ROW_COUNT()` without exact-query caching, including DML affected-row values and result-set `-1` semantics.
- [x] Translate MySQL `COALESCE()` as a common runtime function for PostgreSQL expression paths.
- [x] Fail closed for unsupported MySQL runtime function forms such as unsupported `LAST_INSERT_ID(expr)` shapes, `CURRENT_USER(expr)`, `USER(expr)`, `ROW_COUNT(expr)`, and `UUID()`.
- [x] Emulate `group_concat_max_len` as MySQL session state for `SET`, `SELECT @@...`, and `SHOW VARIABLES`, while keeping global and expression forms explicit errors.
- [x] Enforce `group_concat_max_len` for supported `GROUP_CONCAT(expr [ORDER BY ...] [SEPARATOR ...])` translations and fail closed for unsupported `GROUP_CONCAT` shapes.
- [x] Synthesize direct `information_schema.TABLES.AUTO_INCREMENT` values with schema-aware lookup instead of assuming only `public`.
- [x] Expose direct `information_schema.plugins` as an empty queryable relation with MySQL-compatible columns.
- [ ] Decide whether to emulate exact `ROW_COUNT()` behavior after failed statements; unsupported or undefined forms remain explicit.
- [ ] Decide whether to expose additional MySQL `information_schema` privilege/security tables beyond plugins and the currently supported relations and empty routine/view/trigger/parameter shims; unsupported relations continue to fail explicitly.

## Tests

- [x] Port relevant SQLite SQL mode tests to PostgreSQL.
- [x] Add PostgreSQL tests for every supported `SET sql_mode` syntax.
- [x] Add PostgreSQL tests for SQL mode reporting through `SELECT @@...` and `SHOW VARIABLES`.
- [x] Add lexer tests proving `ANSI_QUOTES` changes double-quoted tokens from string literals to identifiers.
- [x] Add PostgreSQL translation tests for double-quoted identifiers under `ANSI_QUOTES`.
- [x] Add PostgreSQL tests proving double-quoted values remain string literals without `ANSI_QUOTES`.
- [x] Add tests for `NO_BACKSLASH_ESCAPES`, `PIPES_AS_CONCAT`, `IGNORE_SPACE`, and `HIGH_NOT_PRECEDENCE` parity.
- [x] Add PostgreSQL tests for `NO_AUTO_VALUE_ON_ZERO` insert behavior.
- [x] Add PostgreSQL tests for zero-date and zero-in-date behavior in strict and non-strict modes.
- [x] Add wpdb adapter tests for no-argument `set_sql_mode()` parity with SQLite/core.
- [x] Add CI assertions or workflow checks proving PostgreSQL/e2e jobs are not best-effort and not skipped on default-branch pushes.
- [x] Add/keep WP-CLI smoke tests for PostgreSQL config loading without using WP-CLI for MySQL-specific install/reset steps.
- [x] Add PostgreSQL tests for supported `CREATE TABLE ... [AS] SELECT` translations and unsupported variant errors.
- [x] Add PostgreSQL runtime-function tests for emulated session identity, `CONNECTION_ID()`, `LAST_INSERT_ID()`, and fail-closed unsupported forms.
- [x] Add PostgreSQL tests for `ROW_COUNT()` mutable state, `group_concat_max_len`, parenthesized `ALTER TABLE ... ADD (...)` placement, direct `information_schema.TABLES.AUTO_INCREMENT`, and `LAST_INSERT_ID(id)` upsert side effects.
- [x] Add PostgreSQL tests for standalone `LAST_INSERT_ID(expr)` assignment behavior, `GROUP_CONCAT` truncation and fail-closed forms, `information_schema.plugins`, optional-equals `ALTER TABLE` options, and upsert expression column validation.
