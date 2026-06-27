<!--
MAINTENANCE: Update this file when:
- Adding/removing composer scripts
- Changing the directory structure (new modules, major refactors)
- Modifying build/test workflows
- Adding new architectural patterns or conventions
-->

# SQLite database integration
This project implements SQLite database support for MySQL-based projects.

It is a monorepo that includes the following components:
- **MySQL lexer** — A fast MySQL lexer with multi-version support.
- **MySQL parser** — An exhaustive MySQL parser with multi-version support.
- **SQLite driver** — A MySQL emulation layer on top of SQLite with a PDO-compatible API.
- **DuckDB driver** — An experimental, optional DuckDB backend for targeted development and testing.
- **MySQL proxy** — A MySQL binary protocol implementation to support MySQL-based projects beyond PHP.
- **WordPress plugin** — A plugin that adds SQLite support to WordPress.
- **Test suites** — A set of extensive test suites to cover MySQL syntax and functionality.

The monorepo packages are placed under the `packages` directory.

The WordPress plugin links the SQLite driver using a symlink. The build script
replaces the symlink with a copy of the driver for release.

The codebase is pure PHP with zero dependencies. It supports PHP 7.2 through 8.5,
MySQL syntax from version 5.7 onward, and requires SQLite 3.37.0 or newer
(with legacy mode down to 3.27.0).

The default SQLite path remains the zero-dependency runtime. DuckDB support is
optional and must be installed and enabled explicitly.

## Quick start
The codebase is written in PHP and Composer is used to manage the project.
The following commands are useful for development and testing:

```bash
composer install                        # Install dependencies
composer run check-cs                   # Check coding standards (PHPCS)
composer run fix-cs                     # Auto-fix coding standards (PHPCBF)
composer run build-sqlite-plugin-zip    # Build the plugin zip
composer run prepare-release            # Prepare a new release

# SQLite driver tests (under packages/mysql-on-sqlite)
cd packages/mysql-on-sqlite
composer run test                       # Run unit tests
composer run test tests/SomeTest.php    # Run specific unit test file
composer run test -- --filter testName  # Run specific unit test class/method

# SQLite Database Integration plugin E2E tests
composer run test-e2e                   # Run E2E tests (Playwright via WP env)

# WordPress tests
composer run wp-setup                   # Set up WordPress with SQLite for tests
composer run wp-run                     # Run a WordPress repository command
composer run wp-test-start              # Start WordPress environment (Docker)
composer run wp-test-php                # Run WordPress PHPUnit tests
composer run wp-test-e2e                # Run WordPress E2E tests (Playwright)
composer run wp-test-clean              # Clean up WordPress environment (Docker and DB)

# Optional DuckDB verification
DUCKDB_PHP_AUTOLOAD=/tmp/wp-duckdb-php/vendor/autoload.php composer run wp-smoke-duckdb-local
DUCKDB_PHP_AUTOLOAD=/tmp/wp-duckdb-php/vendor/autoload.php composer run wp-test-php-duckdb
DUCKDB_PHP_AUTOLOAD=/tmp/wp-duckdb-php/vendor/autoload.php composer run wp-test-e2e-duckdb
```

## Optional: Native MySQL Parser Extension

The default code path is pure PHP. For environments that can load PHP extensions, the optional `wp_mysql_parser` extension accelerates the MySQL lexer/parser used by the SQLite driver.

- [Published WASM release list, manifest links, Playground links, and native extension overview](https://wordpress.github.io/sqlite-database-integration/)
- [Build, load, and benchmark docs](packages/php-ext-wp-mysql-parser/README.md)

Latest local measurement (Apple Silicon macOS, PHP 8.4.5 CLI, 2026-05-26): the native lexer path processed the MySQL test corpus at ~343k QPS versus ~72k QPS for pure PHP (~4.80x), and the native parser path processed it at ~108k QPS versus ~7k QPS for pure PHP (~15.45x).

## Optional: DuckDB backend

DuckDB support is experimental and is not part of the default SQLite runtime. It
uses the third-party DuckDB PHP client documented by DuckDB. That client uses
FFI, requires PHP 8.3 or newer and `ext-ffi`, and DuckDB recommends the
automatic Composer install with `satur.io/duckdb-auto`. See the
[DuckDB PHP client docs](https://duckdb.org/docs/lts/clients/php) and the
[saturio/duckdb-php installation docs](https://duckdb-php.readthedocs.io/en/latest/installation/).

Install the DuckDB PHP client outside the default project dependency graph, then
point this project at that Composer autoloader. The commands below install
`satur.io/duckdb-auto` but run the bundled C-library installer explicitly, which
avoids Composer plugin hook ordering failures before `vendor/autoload.php`
exists.

```bash
REPO_DIR=$(pwd)
mkdir -p /tmp/wp-duckdb-php
cd /tmp/wp-duckdb-php
composer init --no-interaction --name=wp/duckdb-runtime
composer config allow-plugins.satur.io/duckdb-auto true
composer require --no-plugins --no-interaction satur.io/duckdb-auto
composer dump-autoload
./vendor/bin/install-c-lib
cd "$REPO_DIR"
```

For WordPress manual testing, select the DuckDB backend and load that autoloader
before the drop-in initializes:

```php
define( 'DB_ENGINE', 'duckdb' );
define( 'DUCKDB_PHP_AUTOLOAD', '/tmp/wp-duckdb-php/vendor/autoload.php' );
```

If your local WordPress launcher maps environment variables into `wp-config.php`,
set `DB_ENGINE=duckdb` and `DUCKDB_PHP_AUTOLOAD=/tmp/wp-duckdb-php/vendor/autoload.php`.

Exact verification commands for this branch:

```bash
cd packages/mysql-on-sqlite
composer run test -- --group duckdb-runtime

WP_DUCKDB_TESTS=1 \
WP_DUCKDB_AUTOLOAD=/tmp/wp-duckdb-php/vendor/autoload.php \
DUCKDB_PHP_AUTOLOAD=/tmp/wp-duckdb-php/vendor/autoload.php \
composer run test -- --group duckdb
```

The root WordPress scripts exercise the generated DuckDB drop-in path:

```bash
DUCKDB_PHP_AUTOLOAD=/tmp/wp-duckdb-php/vendor/autoload.php \
composer run wp-smoke-duckdb-local

DUCKDB_PHP_AUTOLOAD=/tmp/wp-duckdb-php/vendor/autoload.php \
composer run wp-test-php-duckdb

DUCKDB_PHP_AUTOLOAD=/tmp/wp-duckdb-php/vendor/autoload.php \
composer run wp-test-e2e-duckdb
```

`wp-smoke-duckdb-local` is a non-Docker smoke for the generated WordPress
drop-in and a simple `$wpdb` query. The WordPress PHPUnit and E2E scripts use
`wp-test-ensure-env-duckdb`, which creates or reuses a DuckDB-configured
WordPress checkout and starts the local Docker environment before running the
tests.

DuckDB CI is isolated in `.github/workflows/duckdb-phpunit-tests.yml`. It
installs the optional DuckDB PHP client at runtime, verifies the FFI-backed
runtime, and runs three paths:

- package PHPUnit: `php -d ffi.enable=true ./vendor/bin/phpunit -c ./phpunit.xml.dist --group duckdb`;
- WordPress PHPUnit: `node .github/workflows/wp-tests-phpunit-run.js` with
  `WP_SQLITE_PHPUNIT_COMMAND` set to `composer run wp-test-php-duckdb -- --log-junit=phpunit-duckdb-results.xml --verbose`;
- WordPress E2E: `composer run wp-test-e2e-duckdb`.

Current limitations:
- DuckDB support is a first-stage adapter. It is intended for targeted local
  verification and isolated CI, not production WordPress traffic.
- The branch currently verifies runtime gating, connections, query execution,
  persistence, result handling, prepared statements, a focused MySQL-to-DuckDB
  driver subset, WordPress-style schema DDL including secondary indexes, and
  dedicated WordPress PHPUnit/E2E CI paths. Local full WordPress acceptance
  still requires Docker; when Docker is unavailable, only package PHPUnit and
  the non-Docker smoke can be proven locally.
- Remaining DuckDB parity gaps include savepoints, broader foreign-key, view,
  and spatial function semantics, seeded `RAND()` in complex query contexts,
  richer origin metadata, and `SHOW WARNINGS`/`SHOW ERRORS`.
- DuckDB's concurrency model allows one process to read and write, or multiple
  read-only processes. The DuckDB docs state that automatic writes from multiple
  processes are not supported and that many small transactions are not its
  primary design goal; see the [DuckDB concurrency docs](https://duckdb.org/docs/lts/connect/concurrency/).

## Release workflow
Release is streamlined with a local preparation script and GitHub Actions:

1. **Run the release preparation script locally.**
   ```bash
   composer run prepare-release <version>
   ```
   The script will:
     - Bump version numbers and generate a changelog from merged PRs.
     - Create a `release/<version>` branch with a preparation commit.
     - Push the branch and create a PR.

2. **Review the PR.**
   Edit the changelog or push additional changes to the release branch.

3. **Mark as ready and merge the PR.**
   The `release-publish` workflow will automatically:
     - Build the plugin ZIP.
     - Create and publish a GitHub release with the ZIP attached.
     - Deploy the release to WordPress.org.

## Architecture
The project consists of multiple components providing different APIs that funnel
into the SQLite driver to support diverse use cases both inside and outside the
PHP ecosystem.

### Component overview
The following diagrams show how different types of applications can be supported
using components from this project:

```
┌──────────────────────┐
│ PHP applications     │
│ Adminer, phpMyAdmin  │──────────────────────────┐
└──────────────────────┘                          │
                                                  │
┌──────────────────────┐  wpdb API                │  PDO\MySQL API           PDO\SQLite
│ WordPress + plugins  │   │   ╔══════════════╗   │   │   ╔═══════════════╗   │   ┌────────┐
│ WordPress Playground │───┴──→║ wpdb drop-in ║───┼───┴──→║ SQLite driver ║───┴──→│ SQLite │
│ Studio, wp-env       │       ╚══════════════╝   │       ╚═══════════════╝       └────────┘
└──────────────────────┘                          │
                          MySQL binary protocol   │
┌──────────────────────┐   │   ╔══════════════╗   │
│ MySQL CLI            │───┴──→║ MySQL proxy  ║───┘
│ Desktop clients      │       ╚══════════════╝
└──────────────────────┘
```

### Query processing pipeline
The following diagram illustrates how a MySQL query is processed and emulated:

```
                string        tokens         AST ╔═════════════╗ SQL
┌─────────────┐  │  ╔═══════╗  │  ╔════════╗  │  ║ Translation ║  │  ┌────────┐
│ MySQL query │──┴─→║ Lexer ║──┴─→║ Parser ║──┴─→║      &      ║──┴─→│ SQLite │
└─────────────┘     ╚═══════╝     ╚════════╝     ║  Emulation  ║     └────────┘
                                                 ╚═════════════╝
```
