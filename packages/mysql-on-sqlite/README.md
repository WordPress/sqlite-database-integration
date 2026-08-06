# MySQL on SQLite

`WP_MySQL_On_SQLite` executes MySQL queries against SQLite through a
PDO-compatible API. It requires PHP 7.2 or newer with PDO and PDO SQLite.

## Usage

Load the driver and construct it with a `mysql-on-sqlite` DSN:

```php
require_once __DIR__ . '/src/load.php';

$database = new WP_MySQL_On_SQLite(
	'mysql-on-sqlite:path=/path/to/database.sqlite;dbname=application'
);

$statement = $database->query( 'SELECT * FROM users' );
$rows      = $statement->fetchAll( PDO::FETCH_ASSOC );
```

The `path` field defaults to `:memory:` and `dbname` defaults to
`sqlite_database`. Escape a literal semicolon in either value as `;;`.
Usernames and passwords are accepted for PDO signature compatibility and ignored.

## Constructor options

The fourth constructor argument accepts standard numeric PDO options and these
driver options:

| Option | Value | Default |
| --- | --- | --- |
| `mysql_version` | MySQL version as an integer, such as `80038` | `80038` |
| `pdo` | Existing PDO SQLite connection | A new connection for `path` |
| `journal_mode` | `DELETE`, `TRUNCATE`, `PERSIST`, `MEMORY`, `WAL`, or `OFF` | `WAL` |
| `synchronous` | `OFF`, `NORMAL`, `FULL`, `EXTRA`, or the corresponding integer from `0` to `3` | `NORMAL` when the effective journal mode is `WAL`; otherwise the SQLite default |

PDO driver-specific options, whose integer keys start at `1000`, are not
supported because PDO MySQL and PDO SQLite assign different meanings to them.

## Configuration

Define `WP_SQLITE_UNSAFE_ENABLE_UNSUPPORTED_VERSIONS` as `true` before creating
a connection to allow SQLite 3.27.0 through 3.36.x. This unsafe compatibility
mode can corrupt databases created by newer SQLite versions and should only be
enabled when that risk is understood.

WordPress-specific configuration constants are documented in the
[SQLite Database Integration plugin README](../plugin-sqlite-database-integration/readme.txt).
