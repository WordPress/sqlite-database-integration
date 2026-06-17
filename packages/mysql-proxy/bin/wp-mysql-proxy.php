<?php declare( strict_types = 1 );

use WP_MySQL_Proxy\MySQL_Proxy;
use WP_MySQL_Proxy\Adapter\SQLite_Adapter;
use WP_MySQL_Proxy\Logger;

require_once __DIR__ . '/../vendor/autoload.php';

// Process CLI arguments:
$shortopts = 'h:d:p:l:';
$longopts  = array( 'help', 'database:', 'database-name:', 'database-alias:', 'port:', 'log-level:' );
$opts      = getopt( $shortopts, $longopts );

$help = <<<USAGE
Usage: php bin/wp-mysql-proxy.php [--port <port>] [--database <path/to/db.sqlite>] [--database-name <name>] [--database-alias <name>] [--log-level <log_level>]

Options:
  -h, --help              Show this help message and exit.
  -p, --port=<port>       The port to listen on. Default: 3306
  -d, --database=<path>   The path to the SQLite database file. Default: :memory:
      --database-name=<name>
                           The MySQL database name exposed by the proxy. Default: sqlite_database
      --database-alias=<name>
                           Additional database name accepted as an alias for --database-name.
                           May be passed more than once.
  -l, --log-level=<level> The log level to use. One of 'error', 'warning', 'info', 'debug'. Default: info

USAGE;

// Help.
if ( isset( $opts['h'] ) || isset( $opts['help'] ) ) {
	fwrite( STDERR, $help );
	exit( 0 );
}

// Database path.
$db_path = $opts['d'] ?? $opts['database'] ?? ':memory:';

// Database name.
$db_name = $opts['database-name'] ?? 'sqlite_database';
if ( '' === $db_name ) {
	fwrite( STDERR, "Error: --database-name cannot be empty. Use --help for more information.\n" );
	exit( 1 );
}

// Database aliases.
$db_aliases = $opts['database-alias'] ?? array();
if ( ! is_array( $db_aliases ) ) {
	$db_aliases = array( $db_aliases );
}
if ( in_array( '', $db_aliases, true ) ) {
	fwrite( STDERR, "Error: --database-alias cannot be empty. Use --help for more information.\n" );
	exit( 1 );
}

// Port.
$port = (int) ( $opts['p'] ?? $opts['port'] ?? 3306 );
if ( $port < 1 || $port > 65535 ) {
	fwrite( STDERR, "Error: --port must be an integer between 1 and 65535. Use --help for more information.\n" );
	exit( 1 );
}

// Log level.
$log_level = $opts['l'] ?? $opts['log-level'] ?? 'info';
if ( ! in_array( $log_level, Logger::LEVELS, true ) ) {
	fwrite( STDERR, 'Error: --log-level must be one of: ' . implode( ', ', Logger::LEVELS ) . ". Use --help for more information.\n" );
	exit( 1 );
}

// Start the MySQL proxy.
$proxy = new MySQL_Proxy(
	new SQLite_Adapter( $db_path, $db_name, $db_aliases ),
	array(
		'port'      => $port,
		'log_level' => $log_level,
	)
);
$proxy->start();
