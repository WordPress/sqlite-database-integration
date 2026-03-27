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
- **MySQL proxy** — A MySQL binary protocol implementation to support MySQL-based projects beyond PHP.
- **WordPress plugin** — A plugin that adds SQLite support to WordPress.
- **Test suites** — A set of extensive test suites to cover MySQL syntax and functionality.

The monorepo packages are placed under the `packages` directory.

The WordPress plugin links the SQLite driver using a symlink. The build script
replaces the symlink with a copy of the driver for release.

The codebase is pure PHP with zero dependencies. It supports PHP 7.2 through 8.5,
MySQL syntax from version 5.7 onward, and requires SQLite 3.37.0 or newer
(with legacy mode down to 3.27.0).

## Quick start
The codebase is written in PHP and Composer is used to manage the project.
The following commands are useful for development and testing:

```bash
composer install                        # Install dependencies
composer run check-cs                   # Check coding standards (PHPCS)
composer run fix-cs                     # Auto-fix coding standards (PHPCBF)
composer run build                      # Build the plugin zip

# SQLite driver tests (under packages/mysql-on-sqlite)
cd packages/mysql-on-sqlite
composer run test                       # Run unit tests
composer run test tests/SomeTest.php    # Run specific unit test file
composer run test -- --filter testName  # Run specific unit test class/method

# SQLite Database Integration plugin tests
composer run test                       # Run unit tests
composer run test tests/SomeTest.php    # Run specific unit test file
composer run test -- --filter testName  # Run specific unit test class/method
composer run test-e2e                   # Run E2E tests (Playwright via WP env)

# WordPress tests
composer run wp-setup                   # Set up WordPress with SQLite for tests
composer run wp-run                     # Run a WordPress repository command
composer run wp-test-start              # Start WordPress environment (Docker)
composer run wp-test-php                # Run WordPress PHPUnit tests
composer run wp-test-e2e                # Run WordPress E2E tests (Playwright)
composer run wp-test-clean              # Clean up WordPress environment (Docker and DB)
```

## Release workflow
Release is automated with GitHub Actions. It requires only two manual steps:

1. **Create a draft release with a new tag and changelog.**
  The `release-prepare` workflow will automatically:
    - Use the draft tag to bump versions in `version.php`, `load.php`, and `readme.txt`.
    - Add the draft release changelog entry to `readme.txt`.
    - Build the plugin ZIP and attach it to the draft release.
    - Create a PR (`release/<version>` → `trunk`) and link it in the draft release body.
2. **Review and merge the PR.**
  The `release-publish` workflow will automatically:
    - Publish the draft release.
    - Publish the release artifact to WordPress.org.

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
