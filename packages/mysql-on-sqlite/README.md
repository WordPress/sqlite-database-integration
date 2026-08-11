# MySQL on SQLite

A **PDO MySQL drop-in** for running MySQL-based PHP applications on SQLite.

## Overview

**MySQL on SQLite** is a pure-PHP database driver that exposes SQLite through
an API compatible with the PDO MySQL driver.

At a glance:

- **MySQL compatibility:** Broad coverage of MySQL syntax, semantics, types, and metadata.
- **PDO MySQL drop-in:** Extensive PDO API coverage with MySQL behavior emulation.
- **Pure PHP:** No third-party runtime dependencies.
- **Lean runtime:** Small footprint, low overhead, and efficient query processing.
- **Extensive validation:** Comprehensive test suites covering real-world patterns.

## Usage

Load the package and create a connection using a `mysql-on-sqlite` DSN:

```php
require_once __DIR__ . '/vendor/autoload.php';

// Use a PDO-like constructor.
$pdo = new WP_MySQL_On_SQLite(
	'mysql-on-sqlite:path=/path/to/database.sqlite;dbname=app'
);

// Use the PDO API to talk to the database as with the PDO MySQL driver.
$statement = $pdo->query( 'SELECT * FROM users' );
$users     = $statement->fetchAll( PDO::FETCH_ASSOC );
```

Switching an existing application from the PDO MySQL driver to MySQL on SQLite
can be as simple as:

```diff
-$pdo = new PDO( 'mysql:host=localhost;dbname=app', $username, $password );
+$pdo = new WP_MySQL_On_SQLite( 'mysql-on-sqlite:path=database.sqlite;dbname=app' );
```

## Configuration

The driver is configured through the standard PDO API, closely mirroring the
PDO MySQL driver while providing additional SQLite-specific options.

### DSN

The DSN has the following format:

```text
mysql-on-sqlite:path=<sqlite-path>;dbname=<mysql-database-name>
```

| Field | Description | Default |
| --- | --- | --- |
| `path` | SQLite database path or `:memory:` | `:memory:` |
| `dbname` | Logical MySQL database name | `sqlite_database` |

Use `;;` to include a literal semicolon in either value.

### PDO options

The constructor follows the PDO signature and accepts most common attributes
supported by the PDO MySQL driver in its fourth argument. Its `username` and
`password` arguments are accepted for compatibility and ignored. MySQL-specific
PDO attributes are currently not supported.

#### Driver options

The fourth argument accepts additional options for selecting the emulated MySQL
version and configuring the SQLite connection:

| Option | Description | Default |
| --- | --- | --- |
| `mysql_version` | MySQL version to emulate, represented as an integer | `80038` |
| `sqlite_pdo` | Existing PDO SQLite connection | A new connection for `path` |
| `sqlite_journal_mode` | SQLite journal mode | `WAL` |
| `sqlite_synchronous` | SQLite synchronous setting | `NORMAL` in WAL mode; otherwise the SQLite default |

## Compatibility

The driver covers extensive MySQL functionality behind an API compatible with
the PDO MySQL driver.

### MySQL

Supported areas include:

- **Queries:** Joins, subqueries, CTEs, unions, grouping, `HAVING`, ordering,
  limits, and more.
- **Data manipulation:** `INSERT`, `UPDATE`, `DELETE`, and `REPLACE`, including
  MySQL-specific forms such as `INSERT IGNORE`, `ON DUPLICATE KEY UPDATE`, and
  joined updates.
- **Schema definition:** Creating, altering, dropping, and truncating tables,
  including temporary tables and complex column definitions.
- **Indexes and constraints:** Index definitions and primary, unique,
  foreign-key, and check constraints.
- **Data types:** Numeric, character, binary, temporal, `ENUM`, `SET`, `JSON`,
  and spatial type declarations, plus character sets and collations.
- **Value semantics:** MySQL-style casting, coercion, defaults, auto-increment
  values, and date and time behavior.
- **Expressions and functions:** MySQL operators and string, numeric, date/time,
  aggregate, regular-expression, conversion, and utility functions.
- **Metadata:** `INFORMATION_SCHEMA`, `SHOW`, `DESCRIBE`, and database selection
  with `USE`.
- **Session state:** SQL modes and system and user variables.
- **Transactions and locking:** Transactions, savepoints, and locks.

### PDO

The driver broadly supports the PDO API and aims for full compatibility with the
PDO MySQL driver. Some APIs, such as prepared statements, parameter binding,
and multi-statement queries, are not yet supported.

## Development

Install the development dependencies and run the tests from this directory:

```bash
composer install
composer run test
```

Run an individual test file or test method with:

```bash
composer run test tests/SomeTest.php
composer run test -- --filter testName
```

## Requirements

- **PHP:** 7.2+
- **PHP extensions:** `pdo`, `pdo_sqlite`
- **SQLite:** 3.37.0+

## License

MySQL on SQLite is licensed under the
[GNU General Public License v2 or later](LICENSE).
