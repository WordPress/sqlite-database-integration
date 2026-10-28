<?php

use WP_MySQL_Proxy\MySQL_Authentication;
use WP_MySQL_Proxy\MySQL_Proxy;
use WP_MySQL_Proxy\Adapter\SQLite_Adapter;

require_once __DIR__ . '/../../vendor/autoload.php';

// Configuration.
$port    = (int) ( getenv( 'PORT' ) );
$db_path = getenv( 'DB_PATH' );

// Server.
$proxy = new MySQL_Proxy(
	new SQLite_Adapter( $db_path ),
	array(
		'port'           => $port,
		'authentication' => new MySQL_Authentication( 'user', 'password', getenv( 'AUTH_PLUGIN' ) ),
		'log_level'      => getenv( 'LOG_LEVEL' ),
		'bind_address'   => getenv( 'BIND_ADDRESS' ),
	)
);
$proxy->start();
