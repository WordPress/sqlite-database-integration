# MySQL proxy

> [!WARNING]
> This package is experimental. Use it at your own risk.

A MySQL proxy that bridges the MySQL wire protocol to a PDO-like interface.

This is a zero-dependency, pure PHP implementation of a MySQL proxy that acts as
a MySQL server, accepts MySQL-native commands, and executes them using a configurable
PDO-like driver. This allows MySQL-compatible clients to connect and run queries
against alternative database backends over the MySQL wire protocol.

Combined with [**MySQL on SQLite**](../mysql-on-sqlite/), this allows
MySQL-based projects to run on SQLite.

## Installation

Install the proxy core to use it with a custom adapter:

```bash
composer require wordpress/mysql-proxy
```

The bundled SQLite adapter and CLI also require MySQL on SQLite:

```bash
composer require wordpress/mysql-proxy wordpress/mysql-on-sqlite:^3.0
```

## Usage

### CLI:

```bash
$ ./vendor/bin/wp-mysql-proxy.php [--bind-address <address>] [--port <port>] [--database <path/to/db.sqlite>] [--log-level <log_level>]

Options:
  -h, --help              Show this help message and exit.
  --bind-address=<ip>    The IPv4 or IPv6 address to listen on. Default: 127.0.0.1
  -p, --port=<port>       The port to listen on. Default: 3306
  -d, --database=<path>   The path to the SQLite database file. Default: :memory:
  -l, --log-level=<level> The log level to use. One of 'error', 'warning', 'info', 'debug'. Default: info
```

When working from a checked-out repository, Composer does not create a proxy for
the root package's own binary. Run it directly from the package directory:

```bash
php bin/wp-mysql-proxy.php [--bind-address <address>] [--port <port>] [--database <path/to/db.sqlite>] [--log-level <log_level>]
```

### PHP:
```php
use WP_MySQL_Proxy\MySQL_Proxy;
use WP_MySQL_Proxy\Adapter\SQLite_Adapter;

require_once __DIR__ . '/vendor/autoload.php';

$proxy = new MySQL_Proxy(
	new SQLite_Adapter( $db_path ),
	array( 'port' => $port, 'log_level' => $log_level )
);
$proxy->start();
```

The proxy listens on `127.0.0.1` by default. Set `--bind-address` (or the PHP
`bind_address` option) to an IPv4 or IPv6 address to change the listener. Binding
to `0.0.0.0` or `::` explicitly exposes the proxy on all interfaces of that family.
