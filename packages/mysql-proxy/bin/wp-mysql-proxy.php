<?php declare( strict_types = 1 );

use WP_MySQL_Proxy\MySQL_Authentication;
use WP_MySQL_Proxy\MySQL_Protocol;
use WP_MySQL_Proxy\MySQL_Proxy;
use WP_MySQL_Proxy\Adapter\SQLite_Adapter;
use WP_MySQL_Proxy\Logger;

require_once $_composer_autoload_path ?? __DIR__ . '/../vendor/autoload.php';

// Parse every argument: getopt() silently discards unknown or incomplete options.
$aliases = array(
	'h' => 'help',
	'd' => 'database',
	'p' => 'port',
	'l' => 'log-level',
	'u' => 'user',
);
$options = array( 'help', 'database', 'port', 'log-level', 'bind-address', 'user', 'password-file', 'auth-plugin' );
$args    = array_slice( $argv, 1 );
$opts    = array();

// Never include argument values in errors: an unknown option may contain a password.
$option_error = "Error: Invalid command-line options. Use --help for supported options and required values.\n";

for ( $i = 0; $i < count( $args ); $i++ ) {
	$arg   = $args[ $i ];
	$name  = '';
	$value = null;
	if ( 0 === strpos( $arg, '--' ) ) {
		$parts = explode( '=', substr( $arg, 2 ), 2 );
		$name  = $parts[0];
		$value = $parts[1] ?? null;
	} elseif ( '-' === substr( $arg, 0, 1 ) && strlen( $arg ) > 1 ) {
		$name = $aliases[ $arg[1] ] ?? '';
		if ( strlen( $arg ) > 2 ) {
			if ( 'help' === $name ) {
				array_splice( $args, $i + 1, 0, array( '-' . substr( $arg, 2 ) ) );
			} else {
				$value = substr( $arg, 2 );
			}
		}
	}

	if ( ! in_array( $name, $options, true ) || array_key_exists( $name, $opts )
		|| ( 'help' === $name && null !== $value )
	) {
		fwrite( STDERR, $option_error );
		exit( 1 );
	}
	if ( 'help' === $name ) {
		$opts[ $name ] = true;
		continue;
	}
	$value = $value ?? ( $args[ ++$i ] ?? null );
	if ( null === $value ) {
		fwrite( STDERR, $option_error );
		exit( 1 );
	}
	$opts[ $name ] = $value;
}

$help = <<<USAGE
Usage: wp-mysql-proxy.php --user <username> [--password-file <path>] [--bind-address <address>] [--port <port>] [--database <path/to/db.sqlite>] [--log-level <log_level>]

Options:
  -h, --help              Show this help message and exit.
  -u, --user=<username>   The proxy account name. Required.
  --password-file=<path>  Read the password from a file instead of WP_MYSQL_PROXY_PASSWORD.
  --bind-address=<ip>     The IPv4 or IPv6 address to listen on. Default: 127.0.0.1
  --auth-plugin=<name>    The account's authentication plugin, either
                          caching_sha2_password or mysql_native_password.
                          Use the latter only for clients that need it.
                          Default: caching_sha2_password
  -p, --port=<port>       The port to listen on. Default: 3306
  -d, --database=<path>   The path to the SQLite database file. Default: :memory:
  -l, --log-level=<level> The log level to use. One of 'error', 'warning', 'info', 'debug'. Default: info

A nonempty password is required via WP_MYSQL_PROXY_PASSWORD or --password-file.
Password files are read verbatim, including any trailing newline.

USAGE;

// Help.
if ( isset( $opts['help'] ) ) {
	fwrite( STDERR, $help );
	exit( 0 );
}

// Database path.
$db_path = $opts['database'] ?? ':memory:';

// Validate options before SQLite_Adapter opens or creates the database file.
// MySQL_Proxy checks the bind address and credentials too, but only after that.

// Bind address.
$bind_address = $opts['bind-address'] ?? '127.0.0.1';
if ( false === filter_var( $bind_address, FILTER_VALIDATE_IP ) ) {
	fwrite( STDERR, "Error: --bind-address must be a valid IPv4 or IPv6 address.\n" );
	exit( 1 );
}

// Port.
$port = filter_var( $opts['port'] ?? 3306, FILTER_VALIDATE_INT );
if ( false === $port || $port < 1 || $port > 65535 ) {
	fwrite( STDERR, "Error: --port must be an integer between 1 and 65535. Use --help for more information.\n" );
	exit( 1 );
}

// Log level.
$log_level = $opts['log-level'] ?? 'info';
if ( ! in_array( $log_level, Logger::LEVELS, true ) ) {
	fwrite( STDERR, 'Error: --log-level must be one of: ' . implode( ', ', Logger::LEVELS ) . ". Use --help for more information.\n" );
	exit( 1 );
}

// Credentials. Do not accept passwords as command-line arguments.
$username = $opts['user'] ?? '';
if ( '' === $username || false !== strpos( $username, "\0" ) ) {
	fwrite( STDERR, "Error: --user must specify a nonempty username without NUL bytes.\n" );
	exit( 1 );
}

// Authentication plugin.
$auth_plugin = $opts['auth-plugin'] ?? MySQL_Protocol::DEFAULT_AUTH_PLUGIN;
if ( ! in_array( $auth_plugin, MySQL_Authentication::SUPPORTED_PLUGINS, true ) ) {
	fwrite(
		STDERR,
		'Error: --auth-plugin must be one of: ' . implode( ', ', MySQL_Authentication::SUPPORTED_PLUGINS ) . ".\n"
	);
	exit( 1 );
}

$password = getenv( 'WP_MYSQL_PROXY_PASSWORD' );
if ( isset( $opts['password-file'] ) ) {
	$password_file = $opts['password-file'];
	if ( ! is_file( $password_file ) || ! is_readable( $password_file ) ) {
		fwrite( STDERR, "Error: --password-file must name a readable file.\n" );
		exit( 1 );
	}
	$password = file_get_contents( $password_file );
}
if ( false === $password || '' === $password || false !== strpos( $password, "\0" ) ) {
	fwrite( STDERR, "Error: Set a nonempty password without NUL bytes using WP_MYSQL_PROXY_PASSWORD or --password-file.\n" );
	exit( 1 );
}

if ( ! class_exists( 'WP_MySQL_On_SQLite' ) ) {
	fwrite(
		STDERR,
		"Error: The bundled CLI requires wordpress/mysql-on-sqlite. Run `composer require wordpress/mysql-on-sqlite:^3.0`.\n"
	);
	exit( 1 );
}

// Start the MySQL proxy.
$proxy = new MySQL_Proxy(
	new SQLite_Adapter( $db_path ),
	array(
		'username'     => $username,
		'password'     => $password,
		'auth_plugin'  => $auth_plugin,
		'bind_address' => $bind_address,
		'port'         => $port,
		'log_level'    => $log_level,
	)
);
unset( $password );
$proxy->start();
