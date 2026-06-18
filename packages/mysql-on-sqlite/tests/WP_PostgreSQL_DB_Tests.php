<?php

use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the PostgreSQL wpdb adapter.
 */
class WP_PostgreSQL_DB_Tests extends TestCase {
	/**
	 * Tests the constructor registers itself globally and normalizes charset state.
	 */
	public function test_constructor_registers_global_wpdb_and_defaults_empty_charset(): void {
		$result = $this->run_isolated_wpdb_script(
			<<<'PHP'
require_once getcwd() . '/bootstrap.php';

if ( ! class_exists( 'wpdb', false ) ) {
	class wpdb {
		public static $next_charset = '';

		public $charset;
		public $parent_args;

		public function __construct( $dbuser, $dbpassword, $dbname, $dbhost ) {
			$this->charset     = self::$next_charset;
			$this->parent_args = array( $dbuser, $dbpassword, $dbname, $dbhost );
		}
	}
}

require_once getcwd() . '/../../plugin-sqlite-database-integration/wp-includes/postgresql/class-wp-postgresql-db.php';

wpdb::$next_charset = '';
$default_db          = new WP_PostgreSQL_DB( 'pg_user', 'pg_pass', 'pg_db', 'pg_host' );
$default_is_global  = $GLOBALS['wpdb'] === $default_db;

wpdb::$next_charset = 'latin1';
$latin_db           = new WP_PostgreSQL_DB( 'latin_user', 'latin_pass', 'latin_db', 'latin_host' );
$latin_is_global    = $GLOBALS['wpdb'] === $latin_db;

wp_postgresql_db_test_respond(
	array(
		'default_is_global' => $default_is_global,
		'default_args'      => $default_db->parent_args,
		'default_charset'   => $default_db->charset,
		'latin_is_global'   => $latin_is_global,
		'latin_args'        => $latin_db->parent_args,
		'latin_charset'     => $latin_db->charset,
	)
);
PHP
		);

		$this->assertSame(
			array(
				'default_is_global' => true,
				'default_args'      => array( 'pg_user', 'pg_pass', 'pg_db', 'pg_host' ),
				'default_charset'   => 'utf8mb4',
				'latin_is_global'   => true,
				'latin_args'        => array( 'latin_user', 'latin_pass', 'latin_db', 'latin_host' ),
				'latin_charset'     => 'latin1',
			),
			$result
		);
	}

	/**
	 * Tests WordPress core's expected wpdb capability checks.
	 */
	public function test_has_cap_matches_wordpress_db_expectations(): void {
		$result = $this->run_isolated_wpdb_script(
			<<<'PHP'
	require_once getcwd() . '/bootstrap.php';

class wpdb {}

require_once getcwd() . '/../../plugin-sqlite-database-integration/wp-includes/postgresql/class-wp-postgresql-db.php';

$db = ( new ReflectionClass( WP_PostgreSQL_DB::class ) )->newInstanceWithoutConstructor();

$capabilities = array();
foreach (
	array(
		'collation',
		'group_concat',
		'subqueries',
		'identifier_placeholders',
		'utf8mb4',
		'utf8mb4_520',
		'COLLATION',
		'GROUP_CONCAT',
		'SUBQUERIES',
		'IDENTIFIER_PLACEHOLDERS',
		'UTF8MB4',
		'UTF8MB4_520',
		'set_charset',
		'SET_CHARSET',
		'unsupported_postgresql_capability',
	) as $capability
) {
	$capabilities[ $capability ] = $db->has_cap( $capability );
}

wp_postgresql_db_test_respond(
	array(
		'db_version'    => $db->db_version(),
		'capabilities'  => $capabilities,
	)
);
PHP
		);

		$this->assertSame( '8.0', $result['db_version'] );
		$this->assertSame(
			array(
				'collation'                         => true,
				'group_concat'                      => true,
				'subqueries'                        => true,
				'identifier_placeholders'           => true,
				'utf8mb4'                           => true,
				'utf8mb4_520'                       => true,
				'COLLATION'                         => true,
				'GROUP_CONCAT'                      => true,
				'SUBQUERIES'                        => true,
				'IDENTIFIER_PLACEHOLDERS'           => true,
				'UTF8MB4'                           => true,
				'UTF8MB4_520'                       => true,
				'set_charset'                       => true,
				'SET_CHARSET'                       => true,
				'unsupported_postgresql_capability' => false,
			),
			$result['capabilities']
		);
	}

	/**
	 * Tests the wpdb adapter applies set_charset() to the PostgreSQL driver state.
	 */
	public function test_set_charset_updates_postgresql_driver_session_state(): void {
		$result = $this->run_isolated_wpdb_script(
			<<<'PHP'
require_once getcwd() . '/bootstrap.php';

class wpdb {
	public $charset = 'utf8mb4';
	public $collate = '';
}

require_once getcwd() . '/../../plugin-sqlite-database-integration/wp-includes/postgresql/class-wp-postgresql-db.php';

$db = ( new ReflectionClass( WP_PostgreSQL_DB::class ) )->newInstanceWithoutConstructor();

$driver = new WP_PostgreSQL_Driver(
	new WP_PostgreSQL_Connection( array( 'pdo' => new PDO( 'sqlite::memory:' ) ) ),
	'wptests'
);

$driver_property = new ReflectionProperty( WP_PostgreSQL_DB::class, 'dbh' );
$driver_property->setAccessible( true );
$driver_property->setValue( $db, $driver );

$db->set_charset( $driver, 'utf8', 'utf8_general_ci' );

$collation = $driver->query( "SHOW VARIABLES WHERE Variable_name='collation_connection'" );
$charset   = $driver->query( "SHOW VARIABLES WHERE Variable_name='character_set_client'" );

wp_postgresql_db_test_respond(
	array(
		'charset'   => $charset[0]->Value,
		'collation' => $collation[0]->Value,
	)
);
PHP
		);

		$this->assertSame(
			array(
				'charset'   => 'utf8',
				'collation' => 'utf8_general_ci',
			),
			$result
		);
	}

	/**
	 * Tests set_charset() uses wpdb defaults and ignores invalid handles.
	 */
	public function test_set_charset_uses_defaults_and_ignores_invalid_handles(): void {
		$result = $this->run_isolated_wpdb_script(
			<<<'PHP'
require_once getcwd() . '/bootstrap.php';

class wpdb {
	public $charset = 'utf8mb4';
	public $collate = '';
}

require_once getcwd() . '/../../plugin-sqlite-database-integration/wp-includes/postgresql/class-wp-postgresql-db.php';

$db = ( new ReflectionClass( WP_PostgreSQL_DB::class ) )->newInstanceWithoutConstructor();

$driver = new WP_PostgreSQL_Driver(
	new WP_PostgreSQL_Connection( array( 'pdo' => new PDO( 'sqlite::memory:' ) ) ),
	'wptests'
);

function wp_postgresql_db_charset_state( WP_PostgreSQL_Driver $driver ) {
	$collation = $driver->query( "SHOW VARIABLES WHERE Variable_name='collation_connection'" );
	$charset   = $driver->query( "SHOW VARIABLES WHERE Variable_name='character_set_client'" );

	return array(
		'charset'   => $charset[0]->Value,
		'collation' => $collation[0]->Value,
	);
}

$initial = wp_postgresql_db_charset_state( $driver );

$db->charset = 'latin1';
$db->collate = '';
$db->set_charset( $driver );
$after_defaults = wp_postgresql_db_charset_state( $driver );

$db->set_charset( $driver, '', 'utf8_general_ci' );
$after_empty_charset = wp_postgresql_db_charset_state( $driver );

$db->set_charset( new stdClass(), 'utf8mb4', 'utf8mb4_bin' );
$after_non_driver = wp_postgresql_db_charset_state( $driver );

$db->set_charset( $driver, 'utf8mb4', '' );
$after_empty_collate = wp_postgresql_db_charset_state( $driver );

wp_postgresql_db_test_respond(
	array(
		'initial'             => $initial,
		'after_defaults'      => $after_defaults,
		'after_empty_charset' => $after_empty_charset,
		'after_non_driver'    => $after_non_driver,
		'after_empty_collate' => $after_empty_collate,
	)
);
PHP
		);

		$this->assertSame(
			array(
				'initial'             => array(
					'charset'   => 'utf8mb4',
					'collation' => 'utf8mb4_unicode_ci',
				),
				'after_defaults'      => array(
					'charset'   => 'latin1',
					'collation' => 'latin1_swedish_ci',
				),
				'after_empty_charset' => array(
					'charset'   => 'latin1',
					'collation' => 'latin1_swedish_ci',
				),
				'after_non_driver'    => array(
					'charset'   => 'latin1',
					'collation' => 'latin1_swedish_ci',
				),
				'after_empty_collate' => array(
					'charset'   => 'utf8mb4',
					'collation' => 'utf8mb4_unicode_ci',
				),
			),
			$result
		);
	}

	/**
	 * Tests the wpdb adapter applies WordPress charset upgrade rules.
	 */
	public function test_determine_charset_applies_wordpress_utf8mb4_upgrade_rules(): void {
		$result = $this->run_isolated_wpdb_script(
			<<<'PHP'
require_once getcwd() . '/bootstrap.php';

if ( ! class_exists( 'wpdb', false ) ) {
	class wpdb {}
}

require_once getcwd() . '/../../plugin-sqlite-database-integration/wp-includes/postgresql/class-wp-postgresql-db.php';

$db = ( new ReflectionClass( WP_PostgreSQL_DB::class ) )->newInstanceWithoutConstructor();

$results = array(
	'without_dbh' => $db->determine_charset( 'utf8', '' ),
);

$dbh_property = new ReflectionProperty( WP_PostgreSQL_DB::class, 'dbh' );
if ( PHP_VERSION_ID < 80100 ) {
	$dbh_property->setAccessible( true );
}
$dbh_property->setValue( $db, new stdClass() );

foreach (
	array(
		'utf8_empty'             => array( 'utf8', '' ),
		'utf8_general_ci'        => array( 'utf8', 'utf8_general_ci' ),
		'utf8_bin'               => array( 'utf8', 'utf8_bin' ),
		'utf8mb4_unicode_ci'     => array( 'utf8mb4', 'utf8mb4_unicode_ci' ),
		'latin1_swedish_ci'      => array( 'latin1', 'latin1_swedish_ci' ),
	) as $name => $args
) {
	$results[ $name ] = $db->determine_charset( $args[0], $args[1] );
}

wp_postgresql_db_test_respond( $results );
PHP
		);

		$this->assertSame(
			array(
				'without_dbh'        => array(
					'charset' => 'utf8',
					'collate' => '',
				),
				'utf8_empty'         => array(
					'charset' => 'utf8mb4',
					'collate' => 'utf8mb4_unicode_520_ci',
				),
				'utf8_general_ci'    => array(
					'charset' => 'utf8mb4',
					'collate' => 'utf8mb4_unicode_520_ci',
				),
				'utf8_bin'           => array(
					'charset' => 'utf8mb4',
					'collate' => 'utf8mb4_bin',
				),
				'utf8mb4_unicode_ci' => array(
					'charset' => 'utf8mb4',
					'collate' => 'utf8mb4_unicode_520_ci',
				),
				'latin1_swedish_ci'  => array(
					'charset' => 'latin1',
					'collate' => 'latin1_swedish_ci',
				),
			),
			$result
		);
	}

	/**
	 * Tests the wpdb adapter filters and forwards SQL mode state to the PostgreSQL driver.
	 */
	public function test_set_sql_mode_filters_incompatible_modes_and_updates_postgresql_driver(): void {
		$result = $this->run_isolated_wpdb_script(
			<<<'PHP'
require_once getcwd() . '/bootstrap.php';

$GLOBALS['wp_postgresql_db_test_filter_calls'] = array();

function apply_filters( $hook_name, $value ) {
	$GLOBALS['wp_postgresql_db_test_filter_calls'][] = array(
		'hook_name' => $hook_name,
		'value'     => $value,
	);

	return $value;
}

class wpdb {
	protected $incompatible_modes = array(
		'NO_ZERO_DATE',
		'ONLY_FULL_GROUP_BY',
		'STRICT_TRANS_TABLES',
		'STRICT_ALL_TABLES',
		'TRADITIONAL',
		'ANSI',
	);
}

require_once getcwd() . '/../../plugin-sqlite-database-integration/wp-includes/postgresql/class-wp-postgresql-db.php';

$db = ( new ReflectionClass( WP_PostgreSQL_DB::class ) )->newInstanceWithoutConstructor();

$driver = new WP_PostgreSQL_Driver(
	new WP_PostgreSQL_Connection( array( 'pdo' => new PDO( 'sqlite::memory:' ) ) ),
	'wptests'
);

$driver_property = new ReflectionProperty( WP_PostgreSQL_DB::class, 'dbh' );
$driver_property->setAccessible( true );
$driver_property->setValue( $db, $driver );

$initial_mode = $driver->get_sql_mode();
$db->set_sql_mode();
$mode_after_empty_call   = $driver->get_sql_mode();
$filter_calls_after_empty = $GLOBALS['wp_postgresql_db_test_filter_calls'];

$zero_date_insert_result = null;
$zero_date_value         = null;
try {
	$driver->query(
		'CREATE TABLE wptests_zero_dates (
			id bigint(20) NOT NULL,
			logged_at datetime NOT NULL,
			PRIMARY KEY (id)
		)'
	);
	$zero_date_insert_result = $driver->query(
		"INSERT INTO `wptests_zero_dates` (`id`, `logged_at`) VALUES (1, '0000-00-00 00:00:00')"
	);
	$zero_date_rows = $driver->query( 'SELECT logged_at FROM wptests_zero_dates WHERE id = 1' );
	$zero_date_value = $zero_date_rows[0]->logged_at ?? null;
} catch ( Throwable $e ) {
	$zero_date_insert_result = get_class( $e ) . ': ' . $e->getMessage();
}

$db->set_sql_mode(
	array(
		'strict_trans_tables',
		'NO_ZERO_DATE',
		'ansi_quotes',
		'no_engine_substitution',
	)
);
$mode_after_filtered_call = $driver->get_sql_mode();
$filter_calls_after_modes = $GLOBALS['wp_postgresql_db_test_filter_calls'];

$driver_property->setValue( $db, null );
$db->set_sql_mode( array( 'STRICT_ALL_TABLES' ) );
$mode_after_detached_call = $driver->get_sql_mode();

wp_postgresql_db_test_respond(
	array(
		'initial_mode'              => $initial_mode,
		'mode_after_empty_call'     => $mode_after_empty_call,
		'filter_calls_after_empty'  => $filter_calls_after_empty,
		'zero_date_insert_result'   => $zero_date_insert_result,
		'zero_date_value'           => $zero_date_value,
		'mode_after_filtered_call'  => $mode_after_filtered_call,
		'filter_calls_after_modes'  => $filter_calls_after_modes,
		'mode_after_detached_call'  => $mode_after_detached_call,
	)
);
PHP
		);

		$this->assertSame(
			'ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION,NO_ZERO_DATE,NO_ZERO_IN_DATE,ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES',
			$result['initial_mode']
		);
		$this->assertSame(
			'ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION,NO_ZERO_IN_DATE',
			$result['mode_after_empty_call']
		);
		$this->assertSame(
			array(
				array(
					'hook_name' => 'incompatible_sql_modes',
					'value'     => array(
						'NO_ZERO_DATE',
						'ONLY_FULL_GROUP_BY',
						'STRICT_TRANS_TABLES',
						'STRICT_ALL_TABLES',
						'TRADITIONAL',
						'ANSI',
					),
				),
			),
			$result['filter_calls_after_empty']
		);
		$this->assertSame( 1, $result['zero_date_insert_result'] );
		$this->assertSame( '0000-00-00 00:00:00', $result['zero_date_value'] );
		$this->assertSame( 'ANSI_QUOTES,NO_ENGINE_SUBSTITUTION', $result['mode_after_filtered_call'] );
		$this->assertSame(
			array(
				array(
					'hook_name' => 'incompatible_sql_modes',
					'value'     => array(
						'NO_ZERO_DATE',
						'ONLY_FULL_GROUP_BY',
						'STRICT_TRANS_TABLES',
						'STRICT_ALL_TABLES',
						'TRADITIONAL',
						'ANSI',
					),
				),
				array(
					'hook_name' => 'incompatible_sql_modes',
					'value'     => array(
						'NO_ZERO_DATE',
						'ONLY_FULL_GROUP_BY',
						'STRICT_TRANS_TABLES',
						'STRICT_ALL_TABLES',
						'TRADITIONAL',
						'ANSI',
					),
				),
			),
			$result['filter_calls_after_modes']
		);
		$this->assertSame( 'ANSI_QUOTES,NO_ENGINE_SUBSTITUTION', $result['mode_after_detached_call'] );
	}

	/**
	 * Tests suppressed print_error() calls record explicit and stored errors.
	 */
	public function test_print_error_records_explicit_and_stored_errors_when_suppressed(): void {
		$result = $this->run_isolated_wpdb_script(
			<<<'PHP'
require_once getcwd() . '/bootstrap.php';

if ( ! class_exists( 'wpdb', false ) ) {
	class wpdb {
		public $last_error      = 'stored backend failure';
		public $last_query      = 'SELECT * FROM probe';
		public $suppress_errors = true;
		public $show_errors     = false;

		public function get_caller() {
			return 'sentinel caller';
		}
	}
}

global $EZSQL_ERROR;
$EZSQL_ERROR = array();

require_once getcwd() . '/../../plugin-sqlite-database-integration/wp-includes/postgresql/class-wp-postgresql-db.php';

$db = ( new ReflectionClass( WP_PostgreSQL_DB::class ) )->newInstanceWithoutConstructor();

$explicit_return = $db->print_error( 'explicit PostgreSQL error' );

$db->last_query = 'UPDATE probe SET x = 1';
$stored_return  = $db->print_error();

wp_postgresql_db_test_respond(
	array(
		'explicit_return' => $explicit_return,
		'stored_return'   => $stored_return,
		'errors'          => $EZSQL_ERROR,
	)
);
PHP
		);

		$this->assertFalse( $result['explicit_return'] );
		$this->assertFalse( $result['stored_return'] );
		$this->assertSame(
			array(
				array(
					'query'     => 'SELECT * FROM probe',
					'error_str' => 'explicit PostgreSQL error',
				),
				array(
					'query'     => 'UPDATE probe SET x = 1',
					'error_str' => 'stored backend failure',
				),
			),
			$result['errors']
		);
	}

	/**
	 * Tests flush() resets query state while preserving the connection handle.
	 */
	public function test_flush_resets_query_state_and_preserves_connection_handle(): void {
		$result = $this->run_isolated_wpdb_script(
			<<<'PHP'
require_once getcwd() . '/bootstrap.php';

if ( ! class_exists( 'wpdb', false ) ) {
	class wpdb {
		public $last_result   = array( 'row' );
		public $col_info      = array( 'column' );
		public $last_query    = 'SELECT * FROM probe';
		public $rows_affected = 7;
		public $num_rows      = 3;
		public $last_error    = 'stored backend failure';
		public $result        = 'driver-result';
	}
}

require_once getcwd() . '/../../plugin-sqlite-database-integration/wp-includes/postgresql/class-wp-postgresql-db.php';

$db       = ( new ReflectionClass( WP_PostgreSQL_DB::class ) )->newInstanceWithoutConstructor();
$sentinel = new stdClass();

$dbh_property = new ReflectionProperty( WP_PostgreSQL_DB::class, 'dbh' );
if ( PHP_VERSION_ID < 80100 ) {
	$dbh_property->setAccessible( true );
}
$dbh_property->setValue( $db, $sentinel );

$db->flush();

wp_postgresql_db_test_respond(
	array(
		'last_result'    => $db->last_result,
		'col_info'       => $db->col_info,
		'last_query'     => $db->last_query,
		'rows_affected'  => $db->rows_affected,
		'num_rows'       => $db->num_rows,
		'last_error'     => $db->last_error,
		'result'         => $db->result,
		'preserved_dbh'  => $sentinel === $dbh_property->getValue( $db ),
	)
);
PHP
		);

		$this->assertSame(
			array(
				'last_result'   => array(),
				'col_info'      => null,
				'last_query'    => null,
				'rows_affected' => 0,
				'num_rows'      => 0,
				'last_error'    => '',
				'result'        => null,
				'preserved_dbh' => true,
			),
			$result
		);
	}

	/**
	 * Tests _real_escape() escapes scalar values and rejects non-scalar values.
	 */
	public function test_real_escape_escapes_scalars_and_rejects_non_scalars(): void {
		$result = $this->run_isolated_wpdb_script(
			<<<'PHP'
require_once getcwd() . '/bootstrap.php';

if ( ! class_exists( 'wpdb', false ) ) {
	class wpdb {
		public function add_placeholder_escape( $query ) {
			return 'placeholder:' . $query;
		}
	}
}

require_once getcwd() . '/../../plugin-sqlite-database-integration/wp-includes/postgresql/class-wp-postgresql-db.php';

$db = ( new ReflectionClass( WP_PostgreSQL_DB::class ) )->newInstanceWithoutConstructor();

wp_postgresql_db_test_respond(
	array(
		'apostrophe'   => $db->_real_escape( "Bob's" ),
		'backslash'    => $db->_real_escape( 'C:\\Temp' ),
		'nul_byte'     => $db->_real_escape( "a\0b" ),
		'integer'      => $db->_real_escape( 123 ),
		'boolean_true' => $db->_real_escape( true ),
		'null'         => $db->_real_escape( null ),
		'array'        => $db->_real_escape( array( 'x' ) ),
		'object'       => $db->_real_escape( (object) array( 'x' => true ) ),
	)
);
PHP
		);

		$this->assertSame(
			array(
				'apostrophe'   => 'placeholder:' . addslashes( "Bob's" ),
				'backslash'    => 'placeholder:' . addslashes( 'C:\\Temp' ),
				'nul_byte'     => 'placeholder:' . addslashes( "a\0b" ),
				'integer'      => 'placeholder:123',
				'boolean_true' => 'placeholder:1',
				'null'         => '',
				'array'        => '',
				'object'       => '',
			),
			$result
		);
	}

	/**
	 * Tests the PostgreSQL adapter strips legacy charset text without MySQL.
	 */
	public function test_strip_invalid_text_handles_legacy_charsets_in_php(): void {
		if ( ! function_exists( 'mb_convert_encoding' ) ) {
			$this->markTestSkipped( 'mbstring is required for legacy charset conversion.' );
		}

		$result = $this->run_isolated_wpdb_script(
			<<<'PHP'
require_once getcwd() . '/bootstrap.php';

function mbstring_binary_safe_encoding() {}
function reset_mbstring_encoding() {}
function __( $text ) {
	return $text;
}

class WP_Error {}

class wpdb {
	public $charset = 'big5';
	public $collate = '';

	public function check_ascii( $text ) {
		return 1 === preg_match( '/^[\x00-\x7F]*$/', $text );
	}
}

require_once getcwd() . '/../../plugin-sqlite-database-integration/wp-includes/postgresql/class-wp-postgresql-db.php';

$db = ( new ReflectionClass( WP_PostgreSQL_DB::class ) )->newInstanceWithoutConstructor();

$method = new ReflectionMethod( WP_PostgreSQL_DB::class, 'strip_invalid_text' );
$method->setAccessible( true );

$utf8 = "a\xe5\x85\xb1b";
$big5 = mb_convert_encoding( $utf8, 'BIG-5', 'UTF-8' );

$big5_result = $method->invoke(
	$db,
	array(
		array(
			'charset' => 'big5',
			'value'   => str_repeat( $big5, 10 ),
			'length'  => array(
				'type'   => 'byte',
				'length' => 10,
			),
		),
	)
);

$db->charset = 'tis620';
$tis620_result = $method->invoke(
	$db,
	array(
		array(
			'charset' => 'tis620',
			'value'   => str_repeat( "\xcc\xe3", 10 ),
			'length'  => array(
				'type'   => 'char',
				'length' => 10,
			),
		),
	)
);

wp_postgresql_db_test_respond(
	array(
		'big5'   => bin2hex( $big5_result[0]['value'] ),
		'tis620' => bin2hex( $tis620_result[0]['value'] ),
	)
);
PHP
		);

		$big5 = mb_convert_encoding( "a\xe5\x85\xb1b", 'BIG-5', 'UTF-8' );

		$this->assertSame(
			array(
				'big5'   => bin2hex( str_repeat( $big5, 2 ) . 'a' ),
				'tis620' => bin2hex( str_repeat( "\xcc\xe3", 5 ) ),
			),
			$result
		);
	}

	/**
	 * Tests query validation lets charset-aware stripping handle non-UTF-8 SQL.
	 */
	public function test_query_uses_strip_invalid_text_for_non_utf8_sql(): void {
		$result = $this->run_isolated_wpdb_script(
			<<<'PHP'
require_once getcwd() . '/bootstrap.php';

function wp_load_translations_early() {}
function __( $text ) {
	return $text;
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $hook_name, $value ) {
		return $value;
	}
}

class wpdb {
	public $ready               = true;
	public $insert_id           = 0;
	public $last_query          = null;
	public $func_call           = null;
	public $last_error          = '';
	public $queries             = array();
	public $num_queries         = 0;
	public $last_result         = array();
	public $col_info            = null;
	public $rows_affected       = 0;
	public $num_rows            = 0;
	public $result              = null;
	public $suppress_errors     = true;
	public $show_errors         = false;
	public $check_current_query = true;
	public $strip_calls         = array();

	public function check_ascii( $text ) {
		return 1 === preg_match( '/^[\x00-\x7F]*$/', $text );
	}

	public function strip_invalid_text_from_query( $query ) {
		$this->strip_calls[] = $query;
		return $query;
	}
}

require_once getcwd() . '/../../plugin-sqlite-database-integration/wp-includes/postgresql/class-wp-postgresql-db.php';

class WP_PostgreSQL_DB_Invalid_Text_Fake_Driver extends WP_PostgreSQL_Driver {
	private $queries = array();

	public function __construct() {}

	public function query( string $query, $fetch_mode = PDO::FETCH_OBJ, ...$fetch_mode_args ) {
		$this->queries[] = $query;
		return 1;
	}

	public function get_last_return_value() {
		return 1;
	}

	public function get_insert_id() {
		return 0;
	}

	public function get_last_postgresql_queries(): array {
		return array();
	}

	public function get_last_column_meta(): array {
		return array();
	}

	public function get_recorded_queries(): array {
		return $this->queries;
	}
}

$db     = ( new ReflectionClass( WP_PostgreSQL_DB::class ) )->newInstanceWithoutConstructor();
$driver = new WP_PostgreSQL_DB_Invalid_Text_Fake_Driver();

$driver_property = new ReflectionProperty( WP_PostgreSQL_DB::class, 'dbh' );
$driver_property->setAccessible( true );
$driver_property->setValue( $db, $driver );

$query  = "INSERT INTO binary_probe (payload) VALUES ('\xff')";
$return = $db->query( $query );

$queries = $driver->get_recorded_queries();
wp_postgresql_db_test_respond(
	array(
		'return'              => $return,
		'strip_calls'         => count( $db->strip_calls ),
		'strip_query_hex'     => bin2hex( $db->strip_calls[0] ?? '' ),
		'driver_query_hex'    => bin2hex( $queries[0] ?? '' ),
		'last_error'          => $db->last_error,
		'check_current_query' => $db->check_current_query,
	)
);
PHP
		);

		$query = "INSERT INTO binary_probe (payload) VALUES ('\xff')";
		$this->assertSame(
			array(
				'return'              => 1,
				'strip_calls'         => 1,
				'strip_query_hex'     => bin2hex( $query ),
				'driver_query_hex'    => bin2hex( $query ),
				'last_error'          => '',
				'check_current_query' => true,
			),
			$result
		);
	}

	/**
	 * Tests temp table charset lookups use the temporary schema before native fallback.
	 */
	public function test_get_col_charset_uses_temporary_table_schema_before_native_fallback(): void {
		$result = $this->run_isolated_wpdb_script(
			<<<'PHP'
require_once getcwd() . '/bootstrap.php';

class wpdb {
	public $charset       = 'utf8mb4';
	public $is_mysql      = true;
	public $table_charset = array();
	public $col_meta      = array();
}

require_once getcwd() . '/../../plugin-sqlite-database-integration/wp-includes/postgresql/class-wp-postgresql-db.php';

class WP_PostgreSQL_DB_Temp_Charset_Fake_Connection extends WP_PostgreSQL_Connection {
	private $pdo;
	private $queries = array();

	public function __construct() {
		$this->pdo = new PDO( 'sqlite::memory:' );
	}

	public function query( string $sql, array $params = array() ): PDOStatement {
		if ( false !== strpos( $sql, 'FROM pg_catalog.pg_class c' ) && false !== strpos( $sql, 'pg_my_temp_schema()' ) ) {
			$this->queries[] = 'temp_schema';
			return $this->statement_from_rows(
				array(
					array(
						'nspname' => 'pg_temp_42',
					),
				)
			);
		}

		if ( false !== strpos( $sql, 'FROM information_schema.columns' ) && array( 'pg_temp_42', 'wptests_shadow_charset' ) === $params ) {
			$this->queries[] = 'native_temp_columns';
			return $this->statement_from_rows(
				array(
					array(
						'column_name'              => 'temp_value',
						'data_type'                => 'text',
						'character_maximum_length' => null,
					),
				)
			);
		}

		$this->queries[] = 'unexpected';
		return $this->statement_from_rows( array() );
	}

	public function get_pdo(): PDO {
		return $this->pdo;
	}

	public function get_queries(): array {
		return $this->queries;
	}

	private function statement_from_rows( array $rows ): PDOStatement {
		if ( empty( $rows ) ) {
			return $this->pdo->query( 'SELECT 1 WHERE 0 = 1' );
		}

		$columns = array_keys( $rows[0] );
		$selects = array();
		$params  = array();
		foreach ( $rows as $row ) {
			$fields = array();
			foreach ( $columns as $column ) {
				$fields[] = '? AS ' . WP_PostgreSQL_Connection::quote_identifier_value( $column );
				$params[] = $row[ $column ];
			}
			$selects[] = 'SELECT ' . implode( ', ', $fields );
		}

		$stmt = $this->pdo->prepare( implode( ' UNION ALL ', $selects ) );
		$stmt->execute( $params );
		return $stmt;
	}
}

class WP_PostgreSQL_DB_Temp_Charset_Fake_Driver extends WP_PostgreSQL_Driver {
	private $fake_connection;

	public function __construct( WP_PostgreSQL_DB_Temp_Charset_Fake_Connection $connection ) {
		$this->fake_connection = $connection;
	}

	public function get_connection(): WP_PostgreSQL_Connection {
		return $this->fake_connection;
	}
}

$connection = new WP_PostgreSQL_DB_Temp_Charset_Fake_Connection();
$driver     = new WP_PostgreSQL_DB_Temp_Charset_Fake_Driver( $connection );
$db         = ( new ReflectionClass( WP_PostgreSQL_DB::class ) )->newInstanceWithoutConstructor();

$driver_property = new ReflectionProperty( WP_PostgreSQL_DB::class, 'dbh' );
$driver_property->setAccessible( true );
$driver_property->setValue( $db, $driver );

wp_postgresql_db_test_respond(
	array(
		'charset' => $db->get_col_charset( 'wptests_shadow_charset', 'temp_value' ),
		'queries' => $connection->get_queries(),
	)
);
PHP
		);

		$this->assertSame(
			array(
				'charset' => 'utf8mb4',
				'queries' => array(
					'temp_schema',
					'native_temp_columns',
				),
			),
			$result
		);
	}

	/**
	 * Tests temp table charset lookups use driver SHOW COLUMNS metadata.
	 */
	public function test_get_charset_uses_temporary_driver_show_columns_metadata(): void {
		$result = $this->run_isolated_wpdb_script(
			<<<'PHP'
require_once getcwd() . '/bootstrap.php';

class wpdb {
	public $charset       = 'utf8';
	public $is_mysql      = true;
	public $table_charset = array();
	public $col_meta      = array();
}

require_once getcwd() . '/../../plugin-sqlite-database-integration/wp-includes/postgresql/class-wp-postgresql-db.php';

class WP_PostgreSQL_DB_Temp_Create_Charset_Fake_Connection extends WP_PostgreSQL_Connection {
	private $pdo;
	private $queries = array();

	public function __construct() {
		$this->pdo = new PDO( 'sqlite::memory:' );
	}

	public function query( string $sql, array $params = array() ): PDOStatement {
		if ( false !== strpos( $sql, 'FROM pg_catalog.pg_class c' ) && false !== strpos( $sql, 'pg_my_temp_schema()' ) ) {
			$this->queries[] = 'temp_schema';
			return $this->statement_from_rows(
				array(
					array(
						'nspname' => 'pg_temp_42',
					),
				)
			);
		}

		if ( false !== strpos( $sql, 'FROM information_schema.columns' ) ) {
			$this->queries[] = 'native_temp_columns';
			return $this->statement_from_rows(
				array(
					array(
						'column_name'              => 'a',
						'data_type'                => 'text',
						'character_maximum_length' => null,
					),
				)
			);
		}

		$this->queries[] = 'unexpected';
		return $this->statement_from_rows( array() );
	}

	public function get_pdo(): PDO {
		return $this->pdo;
	}

	public function get_queries(): array {
		return $this->queries;
	}

	private function statement_from_rows( array $rows ): PDOStatement {
		if ( empty( $rows ) ) {
			return $this->pdo->query( 'SELECT 1 WHERE 0 = 1' );
		}

		$columns = array_keys( $rows[0] );
		$selects = array();
		$params  = array();
		foreach ( $rows as $row ) {
			$fields = array();
			foreach ( $columns as $column ) {
				$fields[] = '? AS ' . WP_PostgreSQL_Connection::quote_identifier_value( $column );
				$params[] = $row[ $column ];
			}
			$selects[] = 'SELECT ' . implode( ', ', $fields );
		}

		$stmt = $this->pdo->prepare( implode( ' UNION ALL ', $selects ) );
		$stmt->execute( $params );
		return $stmt;
	}
}

	class WP_PostgreSQL_DB_Temp_Create_Charset_Fake_Driver extends WP_PostgreSQL_Driver {
		private $fake_connection;
		private $queries = array();

		public function __construct( WP_PostgreSQL_DB_Temp_Create_Charset_Fake_Connection $connection ) {
			$this->fake_connection = $connection;
	}

		public function get_connection(): WP_PostgreSQL_Connection {
			return $this->fake_connection;
		}

		public function query( string $query, $fetch_mode = PDO::FETCH_OBJ, ...$fetch_mode_args ) {
			$this->queries[] = $query;

			if ( 'SHOW FULL COLUMNS FROM `wptests_temp_declared_charset`' !== $query ) {
				return array();
			}

			$rows = array(
				array(
					'Field'     => 'a',
					'Type'      => 'varchar(50)',
					'Collation' => 'big5_chinese_ci',
				),
				array(
					'Field'     => 'b',
					'Type'      => 'text',
					'Collation' => 'koi8r_general_ci',
				),
			);

			if ( PDO::FETCH_ASSOC === $fetch_mode ) {
				return $rows;
			}

			return array_map(
				static function ( array $row ) {
					return (object) $row;
				},
				$rows
			);
		}

		public function get_queries(): array {
			return $this->queries;
		}
	}

$connection = new WP_PostgreSQL_DB_Temp_Create_Charset_Fake_Connection();
$driver     = new WP_PostgreSQL_DB_Temp_Create_Charset_Fake_Driver( $connection );
$db         = ( new ReflectionClass( WP_PostgreSQL_DB::class ) )->newInstanceWithoutConstructor();

$driver_property = new ReflectionProperty( WP_PostgreSQL_DB::class, 'dbh' );
$driver_property->setAccessible( true );
$driver_property->setValue( $db, $driver );

$store_metadata = new ReflectionMethod( WP_PostgreSQL_DB::class, 'store_postgresql_create_table_charset_metadata' );
$store_metadata->setAccessible( true );
$store_metadata->invoke(
	$db,
	'CREATE TEMPORARY TABLE wptests_temp_declared_charset ( a VARCHAR(50) CHARACTER SET big5, b TEXT CHARACTER SET koi8r )'
);

$get_table_charset = new ReflectionMethod( WP_PostgreSQL_DB::class, 'get_table_charset' );
$get_table_charset->setAccessible( true );

wp_postgresql_db_test_respond(
	array(
		'table_charset'      => $get_table_charset->invoke( $db, 'wptests_temp_declared_charset' ),
			'column_a_charset'   => $db->get_col_charset( 'wptests_temp_declared_charset', 'a' ),
			'column_b_charset'   => $db->get_col_charset( 'WPTESTS_TEMP_DECLARED_CHARSET', 'B' ),
			'connection_queries' => $connection->get_queries(),
			'driver_queries'     => $driver->get_queries(),
		)
	);
PHP
		);

		$this->assertSame(
			array(
				'table_charset'      => 'ascii',
				'column_a_charset'   => 'big5',
				'column_b_charset'   => 'koi8r',
				'connection_queries' => array(
					'temp_schema',
				),
				'driver_queries'     => array(
					'SHOW FULL COLUMNS FROM `wptests_temp_declared_charset`',
				),
			),
			$result
		);
	}

	/**
	 * Tests broad DDL cache invalidation preserves temporary table charset metadata.
	 */
	public function test_broad_cache_invalidation_preserves_temporary_table_charset_metadata(): void {
		$result = $this->run_isolated_wpdb_script(
			<<<'PHP'
require_once getcwd() . '/bootstrap.php';

class wpdb {
	public $ready           = true;
	public $charset         = 'utf8';
	public $is_mysql        = true;
	public $table_charset   = array();
	public $col_meta        = array();
	public $insert_id       = 0;
	public $last_query      = null;
	public $func_call       = null;
	public $last_error      = '';
	public $queries         = array();
	public $num_queries     = 0;
	public $last_result     = array();
	public $col_info        = null;
	public $rows_affected   = 0;
	public $num_rows        = 0;
	public $result          = null;
	public $suppress_errors = true;
	public $show_errors     = false;
}

require_once getcwd() . '/../../plugin-sqlite-database-integration/wp-includes/postgresql/class-wp-postgresql-db.php';

class WP_PostgreSQL_DB_Temp_Charset_Broad_Clear_Fake_Connection extends WP_PostgreSQL_Connection {
	private $pdo;
	private $queries = array();

	public function __construct() {
		$this->pdo = new PDO( 'sqlite::memory:' );
	}

	public function query( string $sql, array $params = array() ): PDOStatement {
		if ( false !== strpos( $sql, 'FROM pg_catalog.pg_class c' ) && false !== strpos( $sql, 'pg_my_temp_schema()' ) ) {
			$this->queries[] = 'temp_schema:' . ( $params[0] ?? '' );
			return $this->statement_from_rows(
				array(
					array(
						'nspname' => 'pg_temp_42',
					),
				)
			);
		}

		if ( false !== strpos( $sql, 'FROM information_schema.columns' ) ) {
			$this->queries[] = 'native_temp_columns';
			return $this->statement_from_rows(
				array(
					array(
						'column_name'              => 'a',
						'data_type'                => 'text',
						'character_maximum_length' => null,
					),
					array(
						'column_name'              => 'b',
						'data_type'                => 'text',
						'character_maximum_length' => null,
					),
				)
			);
		}

		$this->queries[] = 'unexpected';
		return $this->statement_from_rows( array() );
	}

	public function get_pdo(): PDO {
		return $this->pdo;
	}

	public function get_queries(): array {
		return $this->queries;
	}

	private function statement_from_rows( array $rows ): PDOStatement {
		if ( empty( $rows ) ) {
			return $this->pdo->query( 'SELECT 1 WHERE 0 = 1' );
		}

		$columns = array_keys( $rows[0] );
		$selects = array();
		$params  = array();
		foreach ( $rows as $row ) {
			$fields = array();
			foreach ( $columns as $column ) {
				$fields[] = '? AS ' . WP_PostgreSQL_Connection::quote_identifier_value( $column );
				$params[] = $row[ $column ];
			}
			$selects[] = 'SELECT ' . implode( ', ', $fields );
		}

		$stmt = $this->pdo->prepare( implode( ' UNION ALL ', $selects ) );
		$stmt->execute( $params );
		return $stmt;
	}
}

class WP_PostgreSQL_DB_Temp_Charset_Broad_Clear_Fake_Driver extends WP_PostgreSQL_Driver {
	private $fake_connection;
	private $queries = array();

	public function __construct( WP_PostgreSQL_DB_Temp_Charset_Broad_Clear_Fake_Connection $connection ) {
		$this->fake_connection = $connection;
	}

	public function get_connection(): WP_PostgreSQL_Connection {
		return $this->fake_connection;
	}

	public function query( string $query, $fetch_mode = PDO::FETCH_OBJ, ...$fetch_mode_args ) {
		$this->queries[] = $query;

		if ( 'SHOW FULL COLUMNS FROM `wptests_temp_declared_charset`' === $query ) {
			$rows = array(
				array(
					'Field'     => 'a',
					'Type'      => 'varchar(50)',
					'Collation' => 'big5_chinese_ci',
				),
				array(
					'Field'     => 'b',
					'Type'      => 'text',
					'Collation' => 'koi8r_general_ci',
				),
			);

			if ( PDO::FETCH_ASSOC === $fetch_mode ) {
				return $rows;
			}

			return array_map(
				static function ( array $row ) {
					return (object) $row;
				},
				$rows
			);
		}

		return true;
	}

	public function get_last_postgresql_queries(): array {
		return array();
	}

	public function get_queries(): array {
		return $this->queries;
	}
}

$connection = new WP_PostgreSQL_DB_Temp_Charset_Broad_Clear_Fake_Connection();
$driver     = new WP_PostgreSQL_DB_Temp_Charset_Broad_Clear_Fake_Driver( $connection );
$db         = ( new ReflectionClass( WP_PostgreSQL_DB::class ) )->newInstanceWithoutConstructor();

$driver_property = new ReflectionProperty( WP_PostgreSQL_DB::class, 'dbh' );
$driver_property->setAccessible( true );
$driver_property->setValue( $db, $driver );

$store_metadata = new ReflectionMethod( WP_PostgreSQL_DB::class, 'store_postgresql_create_table_charset_metadata' );
$store_metadata->setAccessible( true );
$store_metadata->invoke(
	$db,
	'CREATE TEMPORARY TABLE wptests_temp_declared_charset ( a VARCHAR(50) CHARACTER SET big5, b TEXT CHARACTER SET koi8r )'
);

$before_a = $db->get_col_charset( 'wptests_temp_declared_charset', 'a' );
$before_b = $db->get_col_charset( 'wptests_temp_declared_charset', 'b' );
$altered  = $db->query( 'ALTER TABLE wptests_unrelated ADD COLUMN flag INTEGER' );
$after_a  = $db->get_col_charset( 'wptests_temp_declared_charset', 'a' );
$after_b  = $db->get_col_charset( 'wptests_temp_declared_charset', 'b' );

wp_postgresql_db_test_respond(
	array(
		'before_a'           => $before_a,
		'before_b'           => $before_b,
		'altered'            => $altered,
		'after_a'            => $after_a,
		'after_b'            => $after_b,
		'connection_queries' => $connection->get_queries(),
		'driver_queries'     => $driver->get_queries(),
	)
);
PHP
		);

		$this->assertSame( 'big5', $result['before_a'] );
		$this->assertSame( 'koi8r', $result['before_b'] );
		$this->assertTrue( $result['altered'] );
		$this->assertSame( 'big5', $result['after_a'] );
		$this->assertSame( 'koi8r', $result['after_b'] );
		$this->assertSame(
			array(
				'temp_schema:wptests_temp_declared_charset',
				'temp_schema:wptests_temp_declared_charset',
			),
			$result['connection_queries']
		);
			$this->assertSame(
				array(
					'SHOW FULL COLUMNS FROM `wptests_temp_declared_charset`',
					'ALTER TABLE wptests_unrelated ADD COLUMN flag INTEGER',
					'SHOW FULL COLUMNS FROM `wptests_temp_declared_charset`',
				),
				$result['driver_queries']
			);
	}

	/**
	 * Tests permanent CREATE TABLE metadata stays in the PostgreSQL driver catalog.
	 */
	public function test_permanent_create_table_charset_metadata_uses_driver_without_adapter_side_table(): void {
		$result = $this->run_isolated_wpdb_script(
			<<<'PHP'
require_once getcwd() . '/bootstrap.php';

class wpdb {
	public $charset       = 'utf8mb4';
	public $is_mysql      = true;
	public $table_charset = array();
	public $col_meta      = array();
}

require_once getcwd() . '/../../plugin-sqlite-database-integration/wp-includes/postgresql/class-wp-postgresql-db.php';

class WP_PostgreSQL_DB_Stateless_Charset_Fake_Connection extends WP_PostgreSQL_Connection {
	private $pdo;
	private $queries = array();

	public function __construct() {
		$this->pdo = new PDO( 'sqlite::memory:' );
	}

	public function query( string $sql, array $params = array() ): PDOStatement {
		if ( false !== strpos( $sql, 'FROM pg_catalog.pg_class c' ) && false !== strpos( $sql, 'pg_my_temp_schema()' ) ) {
			$this->queries[] = 'temp_schema:' . ( $params[0] ?? '' );
			return $this->statement_from_rows( array() );
		}

		if ( false !== strpos( $sql, 'FROM information_schema.columns' ) ) {
			$this->queries[] = 'native_columns';
			return $this->statement_from_rows( array() );
		}

		$this->queries[] = 'unexpected';
		return $this->statement_from_rows( array() );
	}

	public function get_pdo(): PDO {
		return $this->pdo;
	}

	public function get_queries(): array {
		return $this->queries;
	}

	private function statement_from_rows( array $rows ): PDOStatement {
		if ( empty( $rows ) ) {
			return $this->pdo->query( 'SELECT 1 WHERE 0 = 1' );
		}

		$columns = array_keys( $rows[0] );
		$selects = array();
		$params  = array();
		foreach ( $rows as $row ) {
			$fields = array();
			foreach ( $columns as $column ) {
				$fields[] = '? AS ' . WP_PostgreSQL_Connection::quote_identifier_value( $column );
				$params[] = $row[ $column ];
			}
			$selects[] = 'SELECT ' . implode( ', ', $fields );
		}

		$stmt = $this->pdo->prepare( implode( ' UNION ALL ', $selects ) );
		$stmt->execute( $params );
		return $stmt;
	}
}

class WP_PostgreSQL_DB_Stateless_Charset_Fake_Driver extends WP_PostgreSQL_Driver {
	private $fake_connection;
	private $queries = array();

	public function __construct( WP_PostgreSQL_DB_Stateless_Charset_Fake_Connection $connection ) {
		$this->fake_connection = $connection;
	}

	public function get_connection(): WP_PostgreSQL_Connection {
		return $this->fake_connection;
	}

	public function query( string $query, $fetch_mode = PDO::FETCH_OBJ, ...$fetch_mode_args ) {
		$this->queries[] = $query;

		if ( 'SHOW FULL COLUMNS FROM `wptests_stateless_charset`' !== $query ) {
			return array();
		}

		return array(
			array(
				'Field'     => 'a',
				'Type'      => 'varchar(50)',
				'Collation' => 'utf8_unicode_ci',
			),
			array(
				'Field'     => 'b',
				'Type'      => 'text',
				'Collation' => 'big5_chinese_ci',
			),
		);
	}

	public function get_queries(): array {
		return $this->queries;
	}
}

$connection = new WP_PostgreSQL_DB_Stateless_Charset_Fake_Connection();
$driver     = new WP_PostgreSQL_DB_Stateless_Charset_Fake_Driver( $connection );
$db         = ( new ReflectionClass( WP_PostgreSQL_DB::class ) )->newInstanceWithoutConstructor();

$driver_property = new ReflectionProperty( WP_PostgreSQL_DB::class, 'dbh' );
$driver_property->setAccessible( true );
$driver_property->setValue( $db, $driver );

$store_metadata = new ReflectionMethod( WP_PostgreSQL_DB::class, 'store_postgresql_create_table_charset_metadata' );
$store_metadata->setAccessible( true );
$store_metadata->invoke(
	$db,
	'CREATE TABLE wptests_stateless_charset ( a VARCHAR(50) CHARACTER SET utf8, b TEXT CHARACTER SET big5 )'
);

$get_table_charset = new ReflectionMethod( WP_PostgreSQL_DB::class, 'get_table_charset' );
$get_table_charset->setAccessible( true );

wp_postgresql_db_test_respond(
	array(
		'table_charset'      => $get_table_charset->invoke( $db, 'wptests_stateless_charset' ),
		'column_a_charset'   => $db->get_col_charset( 'wptests_stateless_charset', 'a' ),
		'column_b_charset'   => $db->get_col_charset( 'wptests_stateless_charset', 'b' ),
		'connection_queries' => $connection->get_queries(),
		'driver_queries'     => $driver->get_queries(),
	)
);
PHP
		);

		$this->assertSame(
			array(
				'table_charset'      => 'ascii',
				'column_a_charset'   => 'utf8',
				'column_b_charset'   => 'big5',
				'connection_queries' => array(
					'temp_schema:wptests_stateless_charset',
				),
				'driver_queries'     => array(
					'SHOW FULL COLUMNS FROM `wptests_stateless_charset`',
				),
			),
			$result
		);
	}

	/**
	 * Tests charset lookups can use MySQL metadata stored by the PostgreSQL driver.
	 */
	public function test_get_charset_uses_driver_show_columns_metadata_when_adapter_metadata_is_absent(): void {
		$result = $this->run_isolated_wpdb_script(
			<<<'PHP'
require_once getcwd() . '/bootstrap.php';

class wpdb {
	public $charset       = 'utf8mb4';
	public $is_mysql      = true;
	public $table_charset = array();
	public $col_meta      = array();
}

require_once getcwd() . '/../../plugin-sqlite-database-integration/wp-includes/postgresql/class-wp-postgresql-db.php';

class WP_PostgreSQL_DB_Show_Columns_Charset_Fake_Connection extends WP_PostgreSQL_Connection {
	private $pdo;
	private $queries = array();

	public function __construct() {
		$this->pdo = new PDO( 'sqlite::memory:' );
	}

	public function query( string $sql, array $params = array() ): PDOStatement {
		if ( false !== strpos( $sql, 'FROM pg_catalog.pg_class c' ) && false !== strpos( $sql, 'pg_my_temp_schema()' ) ) {
			$this->queries[] = 'temp_schema';
			return $this->statement_from_rows( array() );
		}

		if ( false !== strpos( $sql, 'FROM information_schema.tables' ) ) {
			$this->queries[] = 'metadata_exists';
			return $this->statement_from_rows(
				array(
					array(
						'exists' => 0,
					),
				)
			);
		}

		if ( false !== strpos( $sql, 'FROM information_schema.columns' ) ) {
			$this->queries[] = 'native_columns';
			return $this->statement_from_rows(
				array(
					array(
						'column_name'              => 'a',
						'data_type'                => 'text',
						'character_maximum_length' => null,
					),
				)
			);
		}

		$this->queries[] = 'unexpected';
		return $this->statement_from_rows( array() );
	}

	public function get_pdo(): PDO {
		return $this->pdo;
	}

	public function get_queries(): array {
		return $this->queries;
	}

	private function statement_from_rows( array $rows ): PDOStatement {
		if ( empty( $rows ) ) {
			return $this->pdo->query( 'SELECT 1 WHERE 0 = 1' );
		}

		$columns = array_keys( $rows[0] );
		$selects = array();
		$params  = array();
		foreach ( $rows as $row ) {
			$fields = array();
			foreach ( $columns as $column ) {
				$fields[] = '? AS ' . WP_PostgreSQL_Connection::quote_identifier_value( $column );
				$params[] = $row[ $column ];
			}
			$selects[] = 'SELECT ' . implode( ', ', $fields );
		}

		$stmt = $this->pdo->prepare( implode( ' UNION ALL ', $selects ) );
		$stmt->execute( $params );
		return $stmt;
	}
}

class WP_PostgreSQL_DB_Show_Columns_Charset_Fake_Driver extends WP_PostgreSQL_Driver {
	private $fake_connection;
	private $queries = array();

	public function __construct( WP_PostgreSQL_DB_Show_Columns_Charset_Fake_Connection $connection ) {
		$this->fake_connection = $connection;
	}

	public function get_connection(): WP_PostgreSQL_Connection {
		return $this->fake_connection;
	}

	public function query( string $query, $fetch_mode = PDO::FETCH_OBJ, ...$fetch_mode_args ) {
		$this->queries[] = $query;

		if ( 'SHOW FULL COLUMNS FROM `wptests_declared_charset`' !== $query ) {
			return array();
		}

		$rows = array(
			array(
				'Field'     => 'a',
				'Type'      => 'varchar(50)',
				'Collation' => 'utf8_unicode_ci',
			),
			array(
				'Field'     => 'b',
				'Type'      => 'text',
				'Collation' => 'big5_chinese_ci',
			),
		);

		if ( PDO::FETCH_ASSOC === $fetch_mode ) {
			return $rows;
		}

		return array_map(
			static function ( array $row ) {
				return (object) $row;
			},
			$rows
		);
	}

	public function get_queries(): array {
		return $this->queries;
	}
}

$connection = new WP_PostgreSQL_DB_Show_Columns_Charset_Fake_Connection();
$driver     = new WP_PostgreSQL_DB_Show_Columns_Charset_Fake_Driver( $connection );
$db         = ( new ReflectionClass( WP_PostgreSQL_DB::class ) )->newInstanceWithoutConstructor();

$driver_property = new ReflectionProperty( WP_PostgreSQL_DB::class, 'dbh' );
$driver_property->setAccessible( true );
$driver_property->setValue( $db, $driver );

$get_table_charset = new ReflectionMethod( WP_PostgreSQL_DB::class, 'get_table_charset' );
$get_table_charset->setAccessible( true );

wp_postgresql_db_test_respond(
	array(
		'table_charset'      => $get_table_charset->invoke( $db, 'wptests_declared_charset' ),
		'column_a_charset'   => $db->get_col_charset( 'wptests_declared_charset', 'a' ),
		'column_b_charset'   => $db->get_col_charset( 'WPTESTS_DECLARED_CHARSET', 'B' ),
		'connection_queries' => $connection->get_queries(),
		'driver_queries'     => $driver->get_queries(),
	)
);
PHP
		);

		$this->assertSame(
			array(
				'table_charset'      => 'ascii',
				'column_a_charset'   => 'utf8',
				'column_b_charset'   => 'big5',
				'connection_queries' => array(
					'temp_schema',
				),
				'driver_queries'     => array(
					'SHOW FULL COLUMNS FROM `wptests_declared_charset`',
				),
			),
			$result
		);
	}

	/**
	 * Tests length checks prefer MySQL column metadata over widened PostgreSQL storage.
	 */
	public function test_get_col_length_uses_mysql_declared_metadata_before_postgresql_storage(): void {
		$result = $this->run_isolated_wpdb_script(
			<<<'PHP'
require_once getcwd() . '/bootstrap.php';

class wpdb {
	public $charset       = 'utf8mb4';
	public $is_mysql      = true;
	public $table_charset = array();
	public $col_meta      = array();
}

require_once getcwd() . '/../../plugin-sqlite-database-integration/wp-includes/postgresql/class-wp-postgresql-db.php';

class WP_PostgreSQL_DB_Column_Length_Fake_Connection extends WP_PostgreSQL_Connection {
	private $pdo;
	private $queries = array();

	public function __construct() {
		$this->pdo = new PDO( 'sqlite::memory:' );
	}

	public function query( string $sql, array $params = array() ): PDOStatement {
		if ( false !== strpos( $sql, 'FROM pg_catalog.pg_class c' ) && false !== strpos( $sql, 'pg_my_temp_schema()' ) ) {
			$this->queries[] = 'temp_schema:' . ( $params[0] ?? '' );
			if ( array( 'wptests_temp_comments' ) === $params ) {
				return $this->statement_from_rows(
					array(
						array(
							'nspname' => 'pg_temp_42',
						),
					)
				);
			}

			return $this->statement_from_rows( array() );
		}

		if ( false !== strpos( $sql, 'FROM information_schema.tables' ) ) {
			$this->queries[] = 'metadata_exists';
			return $this->statement_from_rows(
				array(
					array(
						'exists' => 0,
					),
				)
			);
		}

		if ( false !== strpos( $sql, 'SELECT column_name, data_type, character_maximum_length' ) ) {
			$this->queries[] = 'native_columns:' . ( $params[0] ?? '' );

			if ( array( 'wptests_native_text' ) === $params ) {
				return $this->statement_from_rows(
					array(
						array(
							'column_name'              => 'body',
							'data_type'                => 'text',
							'character_maximum_length' => null,
						),
					)
				);
			}

			if ( array( 'wptests_comments' ) === $params ) {
				return $this->statement_from_rows(
					array(
						array(
							'column_name'              => 'comment_author',
							'data_type'                => 'text',
							'character_maximum_length' => null,
						),
					)
				);
			}
		}

		if ( false !== strpos( $sql, 'SELECT data_type, character_maximum_length' ) ) {
			$this->queries[] = 'direct_length:' . implode( ':', $params );
			return $this->statement_from_rows(
				array(
					array(
						'data_type'                => 'text',
						'character_maximum_length' => null,
					),
				)
			);
		}

		$this->queries[] = 'unexpected';
		return $this->statement_from_rows( array() );
	}

	public function get_pdo(): PDO {
		return $this->pdo;
	}

	public function get_queries(): array {
		return $this->queries;
	}

	private function statement_from_rows( array $rows ): PDOStatement {
		if ( empty( $rows ) ) {
			return $this->pdo->query( 'SELECT 1 WHERE 0 = 1' );
		}

		$columns = array_keys( $rows[0] );
		$selects = array();
		$params  = array();
		foreach ( $rows as $row ) {
			$fields = array();
			foreach ( $columns as $column ) {
				$fields[] = '? AS ' . WP_PostgreSQL_Connection::quote_identifier_value( $column );
				$params[] = $row[ $column ];
			}
			$selects[] = 'SELECT ' . implode( ', ', $fields );
		}

		$stmt = $this->pdo->prepare( implode( ' UNION ALL ', $selects ) );
		$stmt->execute( $params );
		return $stmt;
	}
}

class WP_PostgreSQL_DB_Column_Length_Fake_Driver extends WP_PostgreSQL_Driver {
	private $fake_connection;
	private $queries = array();

	public function __construct( WP_PostgreSQL_DB_Column_Length_Fake_Connection $connection ) {
		$this->fake_connection = $connection;
	}

	public function get_connection(): WP_PostgreSQL_Connection {
		return $this->fake_connection;
	}

	public function query( string $query, $fetch_mode = PDO::FETCH_OBJ, ...$fetch_mode_args ) {
		$this->queries[] = $query;

			if ( ! in_array( $query, array( 'SHOW FULL COLUMNS FROM `wptests_comments`', 'SHOW FULL COLUMNS FROM `wptests_temp_comments`' ), true ) ) {
				return array();
			}

		$rows = array(
			array(
				'Field'     => 'comment_author',
				'Type'      => 'varchar(245)',
				'Collation' => 'utf8mb4_unicode_ci',
			),
			array(
				'Field'     => 'comment_content',
				'Type'      => 'longtext',
				'Collation' => 'utf8mb4_unicode_ci',
			),
		);

		if ( PDO::FETCH_ASSOC === $fetch_mode ) {
			return $rows;
		}

		return array_map(
			static function ( array $row ) {
				return (object) $row;
			},
			$rows
		);
	}

	public function get_queries(): array {
		return $this->queries;
	}
}

$connection = new WP_PostgreSQL_DB_Column_Length_Fake_Connection();
$driver     = new WP_PostgreSQL_DB_Column_Length_Fake_Driver( $connection );
$db         = ( new ReflectionClass( WP_PostgreSQL_DB::class ) )->newInstanceWithoutConstructor();

$driver_property = new ReflectionProperty( WP_PostgreSQL_DB::class, 'dbh' );
$driver_property->setAccessible( true );
$driver_property->setValue( $db, $driver );

$store_metadata = new ReflectionMethod( WP_PostgreSQL_DB::class, 'store_postgresql_create_table_charset_metadata' );
$store_metadata->setAccessible( true );
$store_metadata->invoke(
	$db,
	"CREATE TEMPORARY TABLE wptests_temp_comments (
		comment_author varchar(245) NOT NULL default '',
		comment_content longtext NOT NULL
	) DEFAULT CHARACTER SET utf8mb4"
);

wp_postgresql_db_test_respond(
	array(
		'temporary_comment_author_length' => $db->get_col_length( 'wptests_temp_comments', 'comment_author' ),
		'comment_author_length'           => $db->get_col_length( 'wptests_comments', 'comment_author' ),
		'comment_content_length'          => $db->get_col_length( 'wptests_comments', 'comment_content' ),
		'native_text_length'              => $db->get_col_length( 'wptests_native_text', 'body' ),
		'connection_queries'              => $connection->get_queries(),
		'driver_queries'                  => $driver->get_queries(),
	)
);
PHP
		);

		$this->assertSame(
			array(
				'type'   => 'char',
				'length' => 245,
			),
			$result['temporary_comment_author_length'],
			'Temporary CREATE TABLE metadata should preserve declared MySQL varchar length.'
		);
		$this->assertSame(
			array(
				'type'   => 'char',
				'length' => 245,
			),
			$result['comment_author_length'],
			'Declared MySQL varchar length should win over PostgreSQL text storage.'
		);
		$this->assertSame(
			array(
				'type'   => 'byte',
				'length' => 4294967295,
			),
			$result['comment_content_length']
		);
		$this->assertSame(
			array(
				'type'   => 'byte',
				'length' => 65535,
			),
			$result['native_text_length']
		);
		$this->assertSame(
			array(
				'temp_schema:wptests_temp_comments',
				'temp_schema:wptests_comments',
				'temp_schema:wptests_native_text',
				'native_columns:wptests_native_text',
			),
			$result['connection_queries']
		);
		$this->assertSame(
			array(
				'SHOW FULL COLUMNS FROM `wptests_temp_comments`',
				'SHOW FULL COLUMNS FROM `wptests_comments`',
				'SHOW FULL COLUMNS FROM `wptests_native_text`',
			),
			$result['driver_queries']
		);
	}

	/**
	 * Tests PostgreSQL column metadata is cached per table and can be invalidated.
	 */
	public function test_column_charset_metadata_cache_reuses_table_load_until_invalidated(): void {
		$result = $this->run_isolated_wpdb_script(
			<<<'PHP'
require_once getcwd() . '/bootstrap.php';

class wpdb {
	public $charset       = 'utf8mb4';
	public $is_mysql      = true;
	public $table_charset = array();
	public $col_meta      = array();
}

require_once getcwd() . '/../../plugin-sqlite-database-integration/wp-includes/postgresql/class-wp-postgresql-db.php';

class WP_PostgreSQL_DB_Cached_Metadata_Fake_Connection extends WP_PostgreSQL_Connection {
	private $pdo;
	private $queries = array();
	private $metadata_length = 50;

	public function __construct() {
		$this->pdo = new PDO( 'sqlite::memory:' );
	}

	public function query( string $sql, array $params = array() ): PDOStatement {
		if ( false !== strpos( $sql, 'FROM pg_catalog.pg_class c' ) && false !== strpos( $sql, 'pg_my_temp_schema()' ) ) {
			$this->queries[] = 'temp_schema:' . ( $params[0] ?? '' );
			return $this->statement_from_rows( array() );
		}

		if ( false !== strpos( $sql, 'SELECT column_name, data_type, character_maximum_length' ) ) {
			$this->queries[] = 'native_columns:' . ( $params[0] ?? '' );
			return $this->statement_from_rows(
				array(
					array(
						'column_name'              => 'name',
						'data_type'                => 'character varying',
						'character_maximum_length' => $this->metadata_length,
					),
				)
			);
		}

		$this->queries[] = 'unexpected';
		return $this->statement_from_rows( array() );
	}

	public function get_pdo(): PDO {
		return $this->pdo;
	}

	public function set_metadata_length( int $metadata_length ): void {
		$this->metadata_length = $metadata_length;
	}

	public function get_queries(): array {
		return $this->queries;
	}

	private function statement_from_rows( array $rows ): PDOStatement {
		if ( empty( $rows ) ) {
			return $this->pdo->query( 'SELECT 1 WHERE 0 = 1' );
		}

		$columns = array_keys( $rows[0] );
		$selects = array();
		$params  = array();
		foreach ( $rows as $row ) {
			$fields = array();
			foreach ( $columns as $column ) {
				$fields[] = '? AS ' . WP_PostgreSQL_Connection::quote_identifier_value( $column );
				$params[] = $row[ $column ];
			}
			$selects[] = 'SELECT ' . implode( ', ', $fields );
		}

		$stmt = $this->pdo->prepare( implode( ' UNION ALL ', $selects ) );
		$stmt->execute( $params );
		return $stmt;
	}
}

class WP_PostgreSQL_DB_Cached_Metadata_Fake_Driver extends WP_PostgreSQL_Driver {
	private $fake_connection;
	private $queries = array();

	public function __construct( WP_PostgreSQL_DB_Cached_Metadata_Fake_Connection $connection ) {
		$this->fake_connection = $connection;
	}

	public function get_connection(): WP_PostgreSQL_Connection {
		return $this->fake_connection;
	}

	public function query( string $query, $fetch_mode = PDO::FETCH_OBJ, ...$fetch_mode_args ) {
		$this->queries[] = $query;
		return array();
	}

	public function get_queries(): array {
		return $this->queries;
	}
}

$connection = new WP_PostgreSQL_DB_Cached_Metadata_Fake_Connection();
$driver     = new WP_PostgreSQL_DB_Cached_Metadata_Fake_Driver( $connection );
$db         = ( new ReflectionClass( WP_PostgreSQL_DB::class ) )->newInstanceWithoutConstructor();

$driver_property = new ReflectionProperty( WP_PostgreSQL_DB::class, 'dbh' );
$driver_property->setAccessible( true );
$driver_property->setValue( $db, $driver );

$first = $db->get_col_length( 'wptests_cache_probe', 'name' );

$connection->set_metadata_length( 75 );
$second = $db->get_col_length( 'WPTESTS_CACHE_PROBE', 'NAME' );

$clear_cache = new ReflectionMethod( WP_PostgreSQL_DB::class, 'clear_postgresql_table_charset_cache' );
$clear_cache->setAccessible( true );
$clear_cache->invoke( $db, array( 'wptests_cache_probe' ) );

$third = $db->get_col_length( 'wptests_cache_probe', 'name' );

wp_postgresql_db_test_respond(
	array(
		'first'              => $first,
		'second'             => $second,
		'third'              => $third,
		'connection_queries' => $connection->get_queries(),
		'driver_queries'     => $driver->get_queries(),
	)
);
PHP
		);

		$this->assertSame(
			array(
				'type'   => 'char',
				'length' => 50,
			),
			$result['first']
		);
		$this->assertSame(
			array(
				'type'   => 'char',
				'length' => 50,
			),
			$result['second']
		);
		$this->assertSame(
			array(
				'type'   => 'char',
				'length' => 75,
			),
			$result['third']
		);
		$this->assertSame(
			array(
				'temp_schema:wptests_cache_probe',
				'native_columns:wptests_cache_probe',
				'temp_schema:wptests_cache_probe',
				'native_columns:wptests_cache_probe',
			),
			$result['connection_queries']
		);
		$this->assertSame(
			array(
				'SHOW FULL COLUMNS FROM `wptests_cache_probe`',
				'SHOW FULL COLUMNS FROM `wptests_cache_probe`',
			),
			$result['driver_queries']
		);
	}

	/**
	 * Tests direct PostgreSQL column length fallback metadata is cached until invalidated.
	 */
	public function test_column_length_fallback_cache_reuses_lookup_until_invalidated(): void {
		$result = $this->run_isolated_wpdb_script(
			<<<'PHP'
require_once getcwd() . '/bootstrap.php';

class wpdb {
	public $charset       = 'utf8mb4';
	public $is_mysql      = true;
	public $table_charset = array();
	public $col_meta      = array();
}

require_once getcwd() . '/../../plugin-sqlite-database-integration/wp-includes/postgresql/class-wp-postgresql-db.php';

class WP_PostgreSQL_DB_Length_Fallback_Cache_Fake_Connection extends WP_PostgreSQL_Connection {
	private $pdo;
	private $queries = array();
	private $length = 50;

	public function __construct() {
		$this->pdo = new PDO( 'sqlite::memory:' );
	}

	public function query( string $sql, array $params = array() ): PDOStatement {
		if ( false !== strpos( $sql, 'FROM pg_catalog.pg_class c' ) && false !== strpos( $sql, 'pg_my_temp_schema()' ) ) {
			$this->queries[] = 'temp_schema:' . ( $params[0] ?? '' );
			return $this->statement_from_rows( array() );
		}

		if ( false !== strpos( $sql, 'FROM information_schema.tables' ) ) {
			$this->queries[] = 'metadata_exists';
			return $this->statement_from_rows(
				array(
					array(
						'exists' => 0,
					),
				)
			);
		}

		if ( false !== strpos( $sql, 'SELECT column_name, data_type, character_maximum_length' ) ) {
			$this->queries[] = 'native_columns:' . ( $params[0] ?? '' );
			return $this->statement_from_rows( array() );
		}

		if ( false !== strpos( $sql, 'SELECT data_type, character_maximum_length' ) ) {
			$this->queries[] = 'direct_length:' . implode( ':', $params );
			return $this->statement_from_rows(
				array(
					array(
						'data_type'                => 'character varying',
						'character_maximum_length' => $this->length,
					),
				)
			);
		}

		$this->queries[] = 'unexpected';
		return $this->statement_from_rows( array() );
	}

	public function get_pdo(): PDO {
		return $this->pdo;
	}

	public function set_length( int $length ): void {
		$this->length = $length;
	}

	public function get_queries(): array {
		return $this->queries;
	}

	private function statement_from_rows( array $rows ): PDOStatement {
		if ( empty( $rows ) ) {
			return $this->pdo->query( 'SELECT 1 WHERE 0 = 1' );
		}

		$columns = array_keys( $rows[0] );
		$selects = array();
		$params  = array();
		foreach ( $rows as $row ) {
			$fields = array();
			foreach ( $columns as $column ) {
				$fields[] = '? AS ' . WP_PostgreSQL_Connection::quote_identifier_value( $column );
				$params[] = $row[ $column ];
			}
			$selects[] = 'SELECT ' . implode( ', ', $fields );
		}

		$stmt = $this->pdo->prepare( implode( ' UNION ALL ', $selects ) );
		$stmt->execute( $params );
		return $stmt;
	}
}

class WP_PostgreSQL_DB_Length_Fallback_Cache_Fake_Driver extends WP_PostgreSQL_Driver {
	private $fake_connection;
	private $queries = array();

	public function __construct( WP_PostgreSQL_DB_Length_Fallback_Cache_Fake_Connection $connection ) {
		$this->fake_connection = $connection;
	}

	public function get_connection(): WP_PostgreSQL_Connection {
		return $this->fake_connection;
	}

	public function query( string $query, $fetch_mode = PDO::FETCH_OBJ, ...$fetch_mode_args ) {
		$this->queries[] = $query;
		return array();
	}

	public function get_queries(): array {
		return $this->queries;
	}
}

$connection = new WP_PostgreSQL_DB_Length_Fallback_Cache_Fake_Connection();
$driver     = new WP_PostgreSQL_DB_Length_Fallback_Cache_Fake_Driver( $connection );
$db         = ( new ReflectionClass( WP_PostgreSQL_DB::class ) )->newInstanceWithoutConstructor();

$driver_property = new ReflectionProperty( WP_PostgreSQL_DB::class, 'dbh' );
$driver_property->setAccessible( true );
$driver_property->setValue( $db, $driver );

$first = $db->get_col_length( 'wptests_length_fallback_cache', 'name' );

$connection->set_length( 75 );
$second = $db->get_col_length( 'WPTESTS_LENGTH_FALLBACK_CACHE', 'NAME' );

$clear_cache = new ReflectionMethod( WP_PostgreSQL_DB::class, 'clear_postgresql_table_charset_cache' );
$clear_cache->setAccessible( true );
$clear_cache->invoke( $db, array( 'wptests_length_fallback_cache' ) );

$third = $db->get_col_length( 'wptests_length_fallback_cache', 'name' );

wp_postgresql_db_test_respond(
	array(
		'first'              => $first,
		'second'             => $second,
		'third'              => $third,
		'connection_queries' => $connection->get_queries(),
		'driver_queries'     => $driver->get_queries(),
	)
);
PHP
		);

		$this->assertSame(
			array(
				'type'   => 'char',
				'length' => 50,
			),
			$result['first']
		);
		$this->assertSame(
			array(
				'type'   => 'char',
				'length' => 50,
			),
			$result['second']
		);
		$this->assertSame(
			array(
				'type'   => 'char',
				'length' => 75,
			),
			$result['third']
		);
		$this->assertSame(
			array(
				'temp_schema:wptests_length_fallback_cache',
				'native_columns:wptests_length_fallback_cache',
				'direct_length:wptests_length_fallback_cache:name',
				'temp_schema:wptests_length_fallback_cache',
				'native_columns:wptests_length_fallback_cache',
				'direct_length:wptests_length_fallback_cache:name',
			),
			$result['connection_queries']
		);
		$this->assertSame(
			array(
				'SHOW FULL COLUMNS FROM `wptests_length_fallback_cache`',
				'SHOW FULL COLUMNS FROM `wptests_length_fallback_cache`',
			),
			$result['driver_queries']
		);
	}

	/**
	 * Tests plain permanent CREATE TABLE invalidates cached missing metadata.
	 */
	public function test_plain_create_table_invalidates_cached_missing_column_charset_metadata(): void {
		$result = $this->run_isolated_wpdb_script(
			<<<'PHP'
require_once getcwd() . '/bootstrap.php';

function __( $text ) {
	return $text;
}

class WP_Error {
	public $code;
	public $message;

	public function __construct( $code = '', $message = '' ) {
		$this->code    = $code;
		$this->message = $message;
	}
}

function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}

class wpdb {
	public $ready           = true;
	public $charset         = 'utf8mb4';
	public $is_mysql        = true;
	public $table_charset   = array();
	public $col_meta        = array();
	public $insert_id       = 0;
	public $last_query      = null;
	public $func_call       = null;
	public $last_error      = '';
	public $queries         = array();
	public $num_queries     = 0;
	public $last_result     = array();
	public $col_info        = null;
	public $rows_affected   = 0;
	public $num_rows        = 0;
	public $result          = null;
	public $suppress_errors = true;
	public $show_errors     = false;
}

require_once getcwd() . '/../../plugin-sqlite-database-integration/wp-includes/postgresql/class-wp-postgresql-db.php';

class WP_PostgreSQL_DB_Plain_Create_Cache_Fake_Connection extends WP_PostgreSQL_Connection {
	private $pdo;
	private $queries = array();
	private $plain_table_exists = false;

	public function __construct() {
		$this->pdo = new PDO( 'sqlite::memory:' );
	}

	public function query( string $sql, array $params = array() ): PDOStatement {
		if ( false !== strpos( $sql, 'FROM pg_catalog.pg_class c' ) && false !== strpos( $sql, 'pg_my_temp_schema()' ) ) {
			$this->queries[] = 'temp_schema:' . ( $params[0] ?? '' );
			return $this->statement_from_rows( array() );
		}

		if ( false !== strpos( $sql, 'FROM information_schema.tables' ) ) {
			$this->queries[] = 'metadata_exists';
			return $this->statement_from_rows(
				array(
					array(
						'exists' => 0,
					),
				)
			);
		}

		if ( false !== strpos( $sql, 'SELECT column_name, data_type, character_maximum_length' ) ) {
			$table = $params[0] ?? '';
			$this->queries[] = 'native_columns:' . $table;

			if ( $this->plain_table_exists && 'wptests_plain_metadata_cache' === $table ) {
				return $this->statement_from_rows(
					array(
						array(
							'column_name'              => 'name',
							'data_type'                => 'character varying',
							'character_maximum_length' => 191,
						),
					)
				);
			}

			return $this->statement_from_rows( array() );
		}

		$this->queries[] = 'unexpected';
		return $this->statement_from_rows( array() );
	}

	public function get_pdo(): PDO {
		return $this->pdo;
	}

	public function set_plain_table_exists(): void {
		$this->plain_table_exists = true;
	}

	public function get_queries(): array {
		return $this->queries;
	}

	private function statement_from_rows( array $rows ): PDOStatement {
		if ( empty( $rows ) ) {
			return $this->pdo->query( 'SELECT 1 WHERE 0 = 1' );
		}

		$columns = array_keys( $rows[0] );
		$selects = array();
		$params  = array();
		foreach ( $rows as $row ) {
			$fields = array();
			foreach ( $columns as $column ) {
				$fields[] = '? AS ' . WP_PostgreSQL_Connection::quote_identifier_value( $column );
				$params[] = $row[ $column ];
			}
			$selects[] = 'SELECT ' . implode( ', ', $fields );
		}

		$stmt = $this->pdo->prepare( implode( ' UNION ALL ', $selects ) );
		$stmt->execute( $params );
		return $stmt;
	}
}

class WP_PostgreSQL_DB_Plain_Create_Cache_Fake_Driver extends WP_PostgreSQL_Driver {
	private $fake_connection;
	private $queries = array();

	public function __construct( WP_PostgreSQL_DB_Plain_Create_Cache_Fake_Connection $connection ) {
		$this->fake_connection = $connection;
	}

	public function get_connection(): WP_PostgreSQL_Connection {
		return $this->fake_connection;
	}

	public function query( string $query, $fetch_mode = PDO::FETCH_OBJ, ...$fetch_mode_args ) {
		$this->queries[] = $query;

		if ( 0 === stripos( $query, 'CREATE TABLE' ) ) {
			$this->fake_connection->set_plain_table_exists();
			return true;
		}

		return array();
	}

	public function get_last_return_value() {
		return 0;
	}

	public function get_insert_id() {
		return 0;
	}

	public function get_last_postgresql_queries(): array {
		return array();
	}

	public function get_last_column_meta(): array {
		return array();
	}

	public function get_queries(): array {
		return $this->queries;
	}
}

$connection = new WP_PostgreSQL_DB_Plain_Create_Cache_Fake_Connection();
$driver     = new WP_PostgreSQL_DB_Plain_Create_Cache_Fake_Driver( $connection );
$db         = ( new ReflectionClass( WP_PostgreSQL_DB::class ) )->newInstanceWithoutConstructor();

$driver_property = new ReflectionProperty( WP_PostgreSQL_DB::class, 'dbh' );
$driver_property->setAccessible( true );
$driver_property->setValue( $db, $driver );

$missing = $db->get_col_charset( 'wptests_plain_metadata_cache', 'name' );
$created = $db->query(
	'CREATE TABLE wptests_plain_metadata_cache (
		id INTEGER NOT NULL,
		name VARCHAR(191) NOT NULL
	)'
);
$reloaded = $db->get_col_charset( 'wptests_plain_metadata_cache', 'name' );

wp_postgresql_db_test_respond(
	array(
		'missing_is_error'   => $missing instanceof WP_Error,
		'created'            => $created,
		'reloaded'           => $reloaded,
		'connection_queries' => $connection->get_queries(),
		'driver_queries'     => $driver->get_queries(),
	)
);
PHP
		);

		$this->assertTrue( $result['missing_is_error'] );
		$this->assertTrue( $result['created'] );
		$this->assertSame( 'utf8mb4', $result['reloaded'] );
		$this->assertSame(
			array(
				'temp_schema:wptests_plain_metadata_cache',
				'native_columns:wptests_plain_metadata_cache',
				'temp_schema:wptests_plain_metadata_cache',
				'native_columns:wptests_plain_metadata_cache',
			),
			$result['connection_queries']
		);
		$this->assertSame(
			array(
				'SHOW FULL COLUMNS FROM `wptests_plain_metadata_cache`',
				"CREATE TABLE wptests_plain_metadata_cache (\n\t\tid INTEGER NOT NULL,\n\t\tname VARCHAR(191) NOT NULL\n\t)",
				'SHOW FULL COLUMNS FROM `wptests_plain_metadata_cache`',
			),
			$result['driver_queries']
		);
	}

	/**
	 * Tests DROP TABLE clears PostgreSQL charset metadata and cached wpdb metadata.
	 */
	public function test_drop_table_clears_postgresql_charset_metadata_and_cache(): void {
		$result = $this->run_isolated_wpdb_script(
			<<<'PHP'
require_once getcwd() . '/bootstrap.php';

function __( $text ) {
	return $text;
}

class WP_Error {
	public $code;
	public $message;

	public function __construct( $code = '', $message = '' ) {
		$this->code    = $code;
		$this->message = $message;
	}
}

function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}

class wpdb {
	public $ready           = true;
	public $charset         = 'utf8mb4';
	public $is_mysql        = true;
	public $table_charset   = array();
	public $col_meta        = array();
	public $insert_id       = 0;
	public $last_query      = null;
	public $func_call       = null;
	public $last_error      = '';
	public $queries         = array();
	public $num_queries     = 0;
	public $last_result     = array();
	public $col_info        = null;
	public $rows_affected   = 0;
	public $num_rows        = 0;
	public $result          = null;
	public $suppress_errors = true;
	public $show_errors     = false;
}

require_once getcwd() . '/../../plugin-sqlite-database-integration/wp-includes/postgresql/class-wp-postgresql-db.php';

class WP_PostgreSQL_DB_Drop_Metadata_Fake_Connection extends WP_PostgreSQL_Connection {
	private $pdo;
	private $queries                = array();
	private $temporary_table_exists = true;

	public function __construct() {
		$this->pdo = new PDO( 'sqlite::memory:' );
	}

	public function query( string $sql, array $params = array() ): PDOStatement {
		if ( false !== strpos( $sql, 'FROM pg_catalog.pg_class c' ) && false !== strpos( $sql, 'pg_my_temp_schema()' ) ) {
			$table           = $params[0] ?? '';
			$this->queries[] = 'temp_schema:' . $table;

			if ( $this->temporary_table_exists && 'wptests_temp_drop_metadata_cache' === $table ) {
				return $this->statement_from_rows(
					array(
						array(
							'nspname' => 'pg_temp_42',
						),
					)
				);
			}

			return $this->statement_from_rows( array() );
		}

		if ( false !== strpos( $sql, 'SELECT column_name, data_type, character_maximum_length' ) ) {
			$table           = end( $params );
			$this->queries[] = 'native_columns:' . $table;
			return $this->statement_from_rows( array() );
		}

		$this->queries[] = 'unexpected:' . preg_replace( '/\s+/', ' ', trim( $sql ) );
		return $this->statement_from_rows( array() );
	}

	public function get_pdo(): PDO {
		return $this->pdo;
	}

	public function mark_temporary_table_dropped(): void {
		$this->temporary_table_exists = false;
	}

	public function temporary_table_exists(): bool {
		return $this->temporary_table_exists;
	}

	public function get_queries(): array {
		return $this->queries;
	}

	private function statement_from_rows( array $rows ): PDOStatement {
		if ( empty( $rows ) ) {
			return $this->pdo->query( 'SELECT 1 WHERE 0 = 1' );
		}

		$columns = array_keys( $rows[0] );
		$selects = array();
		$params  = array();
		foreach ( $rows as $row ) {
			$fields = array();
			foreach ( $columns as $column ) {
				$fields[] = '? AS ' . WP_PostgreSQL_Connection::quote_identifier_value( $column );
				$params[] = $row[ $column ];
			}
			$selects[] = 'SELECT ' . implode( ', ', $fields );
		}

		$stmt = $this->pdo->prepare( implode( ' UNION ALL ', $selects ) );
		$stmt->execute( $params );
		return $stmt;
	}
}

class WP_PostgreSQL_DB_Drop_Metadata_Fake_Driver extends WP_PostgreSQL_Driver {
	private $fake_connection;
	private $queries = array();
	private $permanent_table_exists = true;

	public function __construct( WP_PostgreSQL_DB_Drop_Metadata_Fake_Connection $connection ) {
		$this->fake_connection = $connection;
	}

	public function get_connection(): WP_PostgreSQL_Connection {
		return $this->fake_connection;
	}

	public function query( string $query, $fetch_mode = PDO::FETCH_OBJ, ...$fetch_mode_args ) {
		$this->queries[] = $query;

		if ( 0 === stripos( $query, 'DROP TEMPORARY TABLE' ) ) {
			$this->fake_connection->mark_temporary_table_dropped();
			return true;
		}

		if ( 0 === stripos( $query, 'DROP TABLE' ) ) {
			$this->permanent_table_exists = false;
			return true;
		}

		if ( 'SHOW FULL COLUMNS FROM `wptests_drop_metadata_cache`' === $query ) {
			if ( ! $this->permanent_table_exists ) {
				return array();
			}

			$rows = array(
				array(
					'Field'     => 'name',
					'Type'      => 'varchar(191)',
					'Collation' => 'utf8mb4_unicode_ci',
				),
			);

			if ( PDO::FETCH_ASSOC === $fetch_mode ) {
				return $rows;
			}

			return array_map(
				static function ( array $row ) {
					return (object) $row;
				},
				$rows
			);
		}

		if ( 'SHOW FULL COLUMNS FROM `wptests_temp_drop_metadata_cache`' === $query ) {
			if ( ! $this->fake_connection->temporary_table_exists() ) {
				return array();
			}

			$rows = array(
				array(
					'Field'     => 'name',
					'Type'      => 'varchar(50)',
					'Collation' => 'big5_chinese_ci',
				),
			);

			if ( PDO::FETCH_ASSOC === $fetch_mode ) {
				return $rows;
			}

			return array_map(
				static function ( array $row ) {
					return (object) $row;
				},
				$rows
			);
		}

		if ( 0 === stripos( $query, 'SHOW FULL COLUMNS' ) ) {
			return array();
		}

		return true;
	}

	public function get_last_return_value() {
		return 0;
	}

	public function get_insert_id() {
		return 0;
	}

	public function get_last_postgresql_queries(): array {
		return array();
	}

	public function get_last_column_meta(): array {
		return array();
	}

	public function get_queries(): array {
		return $this->queries;
	}
}

$connection = new WP_PostgreSQL_DB_Drop_Metadata_Fake_Connection();
$driver     = new WP_PostgreSQL_DB_Drop_Metadata_Fake_Driver( $connection );
$db         = ( new ReflectionClass( WP_PostgreSQL_DB::class ) )->newInstanceWithoutConstructor();

$driver_property = new ReflectionProperty( WP_PostgreSQL_DB::class, 'dbh' );
$driver_property->setAccessible( true );
$driver_property->setValue( $db, $driver );

$store_metadata = new ReflectionMethod( WP_PostgreSQL_DB::class, 'store_postgresql_create_table_charset_metadata' );
$store_metadata->setAccessible( true );
$store_metadata->invoke(
	$db,
	'CREATE TEMPORARY TABLE wptests_temp_drop_metadata_cache ( name VARCHAR(50) CHARACTER SET big5 )'
);

$permanent_before = $db->get_col_charset( 'wptests_drop_metadata_cache', 'name' );
$temporary_before = $db->get_col_charset( 'wptests_temp_drop_metadata_cache', 'name' );

$permanent_dropped = $db->query( 'DROP TABLE IF EXISTS wptests_drop_metadata_cache' );
$permanent_after   = $db->get_col_charset( 'wptests_drop_metadata_cache', 'name' );

$temporary_dropped = $db->query( 'DROP TEMPORARY TABLE wptests_temp_drop_metadata_cache' );
$temporary_after   = $db->get_col_charset( 'wptests_temp_drop_metadata_cache', 'name' );

wp_postgresql_db_test_respond(
	array(
		'permanent_before'       => $permanent_before,
		'temporary_before'       => $temporary_before,
		'permanent_dropped'      => $permanent_dropped,
		'permanent_after_error'  => $permanent_after instanceof WP_Error,
		'temporary_dropped'      => $temporary_dropped,
		'temporary_after_error'  => $temporary_after instanceof WP_Error,
		'connection_queries'     => $connection->get_queries(),
		'driver_queries'         => $driver->get_queries(),
	)
);
PHP
		);

		$this->assertSame( 'utf8mb4', $result['permanent_before'] );
		$this->assertSame( 'big5', $result['temporary_before'] );
		$this->assertTrue( $result['permanent_dropped'] );
		$this->assertTrue( $result['permanent_after_error'] );
		$this->assertTrue( $result['temporary_dropped'] );
		$this->assertTrue( $result['temporary_after_error'] );
		$this->assertSame(
			array(
				'temp_schema:wptests_drop_metadata_cache',
				'temp_schema:wptests_temp_drop_metadata_cache',
				'temp_schema:wptests_drop_metadata_cache',
				'native_columns:wptests_drop_metadata_cache',
				'temp_schema:wptests_temp_drop_metadata_cache',
				'native_columns:wptests_temp_drop_metadata_cache',
			),
			$result['connection_queries']
		);
		$this->assertSame(
			array(
				'SHOW FULL COLUMNS FROM `wptests_drop_metadata_cache`',
				'SHOW FULL COLUMNS FROM `wptests_temp_drop_metadata_cache`',
				'DROP TABLE IF EXISTS wptests_drop_metadata_cache',
				'SHOW FULL COLUMNS FROM `wptests_drop_metadata_cache`',
				'DROP TEMPORARY TABLE wptests_temp_drop_metadata_cache',
				'SHOW FULL COLUMNS FROM `wptests_temp_drop_metadata_cache`',
			),
			$result['driver_queries']
		);
	}

	/**
	 * Tests metadata helpers return final table identifiers for qualified DDL.
	 */
	public function test_postgresql_metadata_helpers_parse_schema_qualified_table_names(): void {
		$result = $this->run_isolated_wpdb_script(
			<<<'PHP'
require_once getcwd() . '/bootstrap.php';

class wpdb {}

require_once getcwd() . '/../../plugin-sqlite-database-integration/wp-includes/postgresql/class-wp-postgresql-db.php';

$db = ( new ReflectionClass( WP_PostgreSQL_DB::class ) )->newInstanceWithoutConstructor();

$create_table_name = new ReflectionMethod( WP_PostgreSQL_DB::class, 'get_postgresql_create_table_name' );
$create_table_name->setAccessible( true );

$drop_table_names = new ReflectionMethod( WP_PostgreSQL_DB::class, 'get_postgresql_drop_table_names' );
$drop_table_names->setAccessible( true );

wp_postgresql_db_test_respond(
	array(
		'create_names' => array(
			'plain'              => $create_table_name->invoke( $db, 'CREATE TABLE wptests_plain (id bigint)' ),
			'qualified'          => $create_table_name->invoke( $db, 'CREATE TABLE app_schema.wptests_qualified (id bigint)' ),
			'quoted_qualified'   => $create_table_name->invoke( $db, 'CREATE TABLE `app_schema`.`wptests_quoted` (id bigint)' ),
			'temporary_if_exists' => $create_table_name->invoke( $db, 'CREATE TEMPORARY TABLE IF NOT EXISTS `app_schema`.`wptests_temp` (id bigint)' ),
		),
		'drop_names'   => array(
			'plain'               => $drop_table_names->invoke( $db, 'DROP TABLE wptests_plain' ),
			'qualified_list'      => $drop_table_names->invoke( $db, 'DROP TABLE IF EXISTS app_schema.wptests_one, `app_schema`.`wptests_two`, wptests_three CASCADE' ),
			'temporary_qualified' => $drop_table_names->invoke( $db, 'DROP TEMPORARY TABLE IF EXISTS `app_schema`.`wptests_temp`, scratch.wptests_other RESTRICT' ),
		),
	)
);
PHP
		);

		$this->assertSame(
			array(
				'plain'               => 'wptests_plain',
				'qualified'           => 'wptests_qualified',
				'quoted_qualified'    => 'wptests_quoted',
				'temporary_if_exists' => 'wptests_temp',
			),
			$result['create_names']
		);
		$this->assertSame(
			array(
				'plain'               => array( 'wptests_plain' ),
				'qualified_list'      => array( 'wptests_one', 'wptests_two', 'wptests_three' ),
				'temporary_qualified' => array( 'wptests_temp', 'wptests_other' ),
			),
			$result['drop_names']
		);
	}

	/**
	 * Tests real wpdb identifier placeholders use PostgreSQL identifier quotes.
	 */
	public function test_real_wpdb_prepare_identifier_placeholders_use_postgresql_quotes(): void {
		$wpdb_file = __DIR__ . '/../../../wordpress/src/wp-includes/class-wpdb.php';
		if ( ! is_readable( $wpdb_file ) ) {
			$this->markTestSkipped( 'Real WordPress wpdb class is not available.' );
		}

		$result = $this->run_isolated_wpdb_script(
			<<<'PHP'
	require_once getcwd() . '/bootstrap.php';

	function wp_load_translations_early() {}
	function __( $text ) {
		return $text;
	}
	function _doing_it_wrong() {}
	function has_filter() {
		return false;
	}
	function add_filter() {
		return true;
	}

	require_once getcwd() . '/../../../wordpress/src/wp-includes/class-wpdb.php';
	require_once getcwd() . '/../../plugin-sqlite-database-integration/wp-includes/postgresql/class-wp-postgresql-db.php';

	class WP_PostgreSQL_DB_Prepare_Fake_Connection extends WP_PostgreSQL_Connection {
		public function __construct() {}

		public function quote( $value, int $type = PDO::PARAM_STR ): string {
			return "'" . str_replace( "'", "''", (string) $value ) . "'";
		}
	}

	class WP_PostgreSQL_DB_Prepare_Fake_Driver extends WP_PostgreSQL_Driver {
		private $connection;

		public function __construct() {
			$this->connection = new WP_PostgreSQL_DB_Prepare_Fake_Connection();
		}

		public function get_connection(): WP_PostgreSQL_Connection {
			return $this->connection;
		}
	}

	$db = ( new ReflectionClass( WP_PostgreSQL_DB::class ) )->newInstanceWithoutConstructor();

	$driver_property = new ReflectionProperty( WP_PostgreSQL_DB::class, 'dbh' );
	$driver_property->setAccessible( true );
	$driver_property->setValue( $db, new WP_PostgreSQL_DB_Prepare_Fake_Driver() );

	$db->charset = 'utf8mb4';

	$marker_like_value = '__wp_pg_identifier_' . spl_object_hash( $db ) . '_1_0__';
	$prepared_marker_collision = $db->prepare(
		'SELECT %s AS value FROM %i',
		$marker_like_value,
		'my_table'
	);

	$identifier_collision_value    = '__wp_pg_identifier_' . spl_object_hash( $db ) . '_4_1__';
	$prepared_identifier_collision = $db->prepare(
		'SELECT %i, %i',
		$identifier_collision_value,
		'second'
	);

	$quote_identifier_nul_exception = null;
	$quote_identifier_nul_message   = null;
	try {
		$db->quote_identifier( "wp_\0posts" );
	} catch ( Throwable $e ) {
		$quote_identifier_nul_exception = get_class( $e );
		$quote_identifier_nul_message   = $e->getMessage();
	}

	wp_postgresql_db_test_respond(
		array(
			'has_identifier_cap'              => $db->has_cap( 'identifier_placeholders' ),
			'quoted_table'                    => $db->quote_identifier( 'wptests_options' ),
			'quoted_weird'                    => $db->quote_identifier( 'weird"name' ),
			'quote_identifier_nul_exception'  => $quote_identifier_nul_exception,
			'quote_identifier_nul_message'    => $quote_identifier_nul_message,
			'marker_like_value'               => $marker_like_value,
			'prepared_marker_collision'       => $prepared_marker_collision,
			'identifier_collision_value'      => $identifier_collision_value,
			'prepared_identifier_collision'   => $prepared_identifier_collision,
			'prepared_identifier'             => $db->prepare(
				'SELECT * FROM %i WHERE %i = %s',
				'wptests_options',
				'option_name',
				"Bob's"
			),
			'prepared_identifier_array'        => $db->prepare(
				'SELECT %i FROM %i WHERE %i = %s',
				array( 'option_value', 'wptests_options', 'option_name', "Bob's" )
			),
			'prepared_formatted_identifier'    => $db->prepare(
				'SELECT * FROM %05i WHERE %i = %s',
				'wptests_options',
				'option_name',
				"Bob's"
			),
			'prepared_string'                  => $db->prepare( 'SELECT %s', "Bob's" ),
		)
	);
PHP
		);

		$this->assertTrue( $result['has_identifier_cap'] );
		$this->assertSame( '"wptests_options"', $result['quoted_table'] );
		$this->assertSame( '"weird""name"', $result['quoted_weird'] );
		$this->assertSame( 'InvalidArgumentException', $result['quote_identifier_nul_exception'] );
		$this->assertSame(
			'PostgreSQL identifiers cannot contain NUL bytes.',
			$result['quote_identifier_nul_message']
		);
		$this->assertSame(
			'SELECT * FROM "wptests_options" WHERE "option_name" = \'Bob\\\'s\'',
			$result['prepared_identifier']
		);
		$this->assertSame(
			'SELECT \'' . $result['marker_like_value'] . '\' AS value FROM "my_table"',
			$result['prepared_marker_collision']
		);
		$this->assertSame(
			'SELECT "' . $result['identifier_collision_value'] . '", "second"',
			$result['prepared_identifier_collision']
		);
		$this->assertNotSame(
			'SELECT ""second"", "second"',
			$result['prepared_identifier_collision']
		);
		$this->assertSame(
			'SELECT "option_value" FROM "wptests_options" WHERE "option_name" = \'Bob\\\'s\'',
			$result['prepared_identifier_array']
		);
		$this->assertSame(
			'SELECT * FROM `wptests_options` WHERE `option_name` = \'Bob\\\'s\'',
			$result['prepared_formatted_identifier']
		);
		$this->assertSame( "SELECT 'Bob\\'s'", $result['prepared_string'] );
	}

	/**
	 * Tests db_connect() short-circuits when a PostgreSQL driver already exists.
	 */
	public function test_db_connect_short_circuits_when_postgresql_driver_already_exists(): void {
		$result = $this->run_isolated_wpdb_script(
			<<<'PHP'
require_once getcwd() . '/bootstrap.php';

class wpdb {
	public $ready              = false;
	public $is_mysql           = false;
	public $last_error         = 'previous error';
	public $charset            = 'latin1';
	public $init_charset_calls = 0;
	public $bail_calls         = array();

	public function init_charset() {
		++$this->init_charset_calls;
		$this->charset = 'utf8mb4';
	}

	public function bail( $message, $error_code = '500' ) {
		$this->bail_calls[] = array( $message, $error_code );
	}
}

require_once getcwd() . '/../../plugin-sqlite-database-integration/wp-includes/postgresql/class-wp-postgresql-db.php';

class WP_PostgreSQL_DB_Existing_Driver_Fake_Driver extends WP_PostgreSQL_Driver {
	public function __construct() {}
}

$db     = ( new ReflectionClass( WP_PostgreSQL_DB::class ) )->newInstanceWithoutConstructor();
$driver = new WP_PostgreSQL_DB_Existing_Driver_Fake_Driver();

$driver_property = new ReflectionProperty( WP_PostgreSQL_DB::class, 'dbh' );
$driver_property->setAccessible( true );
$driver_property->setValue( $db, $driver );

$connect_result       = $db->db_connect( false );
$driver_after_connect = $driver_property->getValue( $db );

wp_postgresql_db_test_respond(
	array(
		'connect_result'     => $connect_result,
		'ready'              => $db->ready,
		'is_mysql'           => $db->is_mysql,
		'driver_same'        => $driver_after_connect === $driver,
		'init_charset_calls' => $db->init_charset_calls,
		'bail_calls'         => $db->bail_calls,
		'last_error'         => $db->last_error,
		'charset'            => $db->charset,
	)
);
PHP
		);

		$this->assertSame(
			array(
				'connect_result'     => true,
				'ready'              => true,
				'is_mysql'           => true,
				'driver_same'        => true,
				'init_charset_calls' => 0,
				'bail_calls'         => array(),
				'last_error'         => 'previous error',
				'charset'            => 'latin1',
			),
			$result
		);
	}

	/**
	 * Tests db_connect() with a reusable PostgreSQL PDO and connection lifecycle methods.
	 */
	public function test_db_connect_reuses_global_postgresql_pdo_and_exposes_connection_lifecycle(): void {
		$result = $this->run_isolated_wpdb_script(
			<<<'PHP'
require_once getcwd() . '/bootstrap.php';

class wpdb {
	public $dbuser     = '';
	public $dbpassword = '';
	public $dbname     = '';
	public $dbhost     = '';
	public $ready      = false;
	public $is_mysql   = true;
	public $last_error = 'previous error';
	public $charset    = '';
	public $bail_calls = array();

	public function init_charset() {
		$this->charset = 'utf8mb4';
	}

	public function parse_db_host( $host ) {
		return false;
	}

	public function bail( $message, $error_code = '500' ) {
		$this->bail_calls[] = array( $message, $error_code );
	}
}

require_once getcwd() . '/../../plugin-sqlite-database-integration/wp-includes/postgresql/class-wp-postgresql-db.php';

class WP_PostgreSQL_DB_Connect_Fake_PDO extends PDO {
	public $attributes = array();

	public function __construct() {}

	public function setAttribute( $attribute, $value ): bool {
		$this->attributes[ $attribute ] = $value;
		return true;
	}

	/**
	 * Get a fake PDO attribute.
	 *
	 * @param int $attribute PDO attribute.
	 * @return mixed Attribute value.
	 */
	#[\ReturnTypeWillChange]
	public function getAttribute( $attribute ) {
		if ( PDO::ATTR_DRIVER_NAME === $attribute ) {
			return 'pgsql';
		}

		if ( PDO::ATTR_SERVER_VERSION === $attribute ) {
			return 'PostgreSQL 16 test';
		}

		return $this->attributes[ $attribute ] ?? null;
	}
}

$pdo             = new WP_PostgreSQL_DB_Connect_Fake_PDO();
$GLOBALS['@pdo'] = $pdo;

$db             = ( new ReflectionClass( WP_PostgreSQL_DB::class ) )->newInstanceWithoutConstructor();
$db->dbuser     = 'wptests_user';
$db->dbpassword = 'wptests_password';
$db->dbname     = 'wptests';
$db->dbhost     = 'localhost';

$connect_result      = $db->db_connect( false );
$ready_after_connect = $db->ready;

$driver_property = new ReflectionProperty( WP_PostgreSQL_DB::class, 'dbh' );
$driver_property->setAccessible( true );
$driver                 = $driver_property->getValue( $db );
$driver_uses_global_pdo = $driver->get_connection()->get_pdo() === $pdo;

$select_other_result   = $db->select( 'other', $driver );
$ready_after_other     = $db->ready;
$select_current_result = $db->select( 'wptests', $driver );
$ready_after_current   = $db->ready;
$server_info           = $db->db_server_info();
$close_result          = $db->close();
$ready_after_close     = $db->ready;
$driver_after_close    = $driver_property->getValue( $db );
$second_close_result   = $db->close();

wp_postgresql_db_test_respond(
	array(
		'connect_result'        => $connect_result,
		'ready_after_connect'   => $ready_after_connect,
		'is_mysql'              => $db->is_mysql,
		'last_error'            => $db->last_error,
		'charset'               => $db->charset,
		'bail_calls'            => $db->bail_calls,
		'driver_uses_global_pdo' => $driver_uses_global_pdo,
		'server_info'           => $server_info,
		'select_other_result'   => $select_other_result,
		'ready_after_other'     => $ready_after_other,
		'select_current_result' => $select_current_result,
		'ready_after_current'   => $ready_after_current,
		'close_result'          => $close_result,
		'ready_after_close'     => $ready_after_close,
		'driver_after_close'    => null === $driver_after_close,
		'second_close_result'   => $second_close_result,
	)
);
PHP
		);

		$this->assertSame(
			array(
				'connect_result'         => true,
				'ready_after_connect'    => true,
				'is_mysql'               => true,
				'last_error'             => '',
				'charset'                => 'utf8mb4',
				'bail_calls'             => array(),
				'driver_uses_global_pdo' => true,
				'server_info'            => 'PostgreSQL 16 test',
				'select_other_result'    => false,
				'ready_after_other'      => false,
				'select_current_result'  => true,
				'ready_after_current'    => true,
				'close_result'           => true,
				'ready_after_close'      => false,
				'driver_after_close'     => true,
				'second_close_result'    => false,
			),
			$result,
			'The PostgreSQL wpdb adapter keeps is_mysql=true so WordPress runs charset and length validation paths.'
		);
	}

	/**
	 * Tests close() clears stale ready state even without a driver handle.
	 */
	public function test_close_clears_stale_ready_state_without_driver(): void {
		$result = $this->run_isolated_wpdb_script(
			<<<'PHP'
require_once getcwd() . '/bootstrap.php';

class wpdb {
	public $ready = false;
}

require_once getcwd() . '/../../plugin-sqlite-database-integration/wp-includes/postgresql/class-wp-postgresql-db.php';

$db = ( new ReflectionClass( WP_PostgreSQL_DB::class ) )->newInstanceWithoutConstructor();

$driver_property = new ReflectionProperty( WP_PostgreSQL_DB::class, 'dbh' );
$driver_property->setAccessible( true );
$driver_property->setValue( $db, null );

$db->ready = true;

$close_result      = $db->close();
$ready_after_close = $db->ready;
$dbh_after_close   = $driver_property->getValue( $db );

wp_postgresql_db_test_respond(
	array(
		'close_result'      => $close_result,
		'ready_after_close' => $ready_after_close,
		'dbh_after_close'   => $dbh_after_close,
	)
);
PHP
		);

		$this->assertSame(
			array(
				'close_result'      => false,
				'ready_after_close' => false,
				'dbh_after_close'   => null,
			),
			$result
		);
	}

	/**
	 * Tests PostgreSQL connection options normalize socket-style DB_HOST values.
	 */
	public function test_get_connection_options_normalizes_postgresql_socket_hosts(): void {
		$result = $this->run_isolated_wpdb_script(
			<<<'PHP'
require_once getcwd() . '/bootstrap.php';

class wpdb {
	public $dbuser     = '';
	public $dbpassword = '';
	public $dbname     = '';
	public $dbhost     = '';

	public function parse_db_host( $host ) {
		$socket  = null;
		$is_ipv6 = false;

		$socket_pos = strpos( $host, ':/' );
		if ( false !== $socket_pos ) {
			$socket = substr( $host, $socket_pos + 1 );
			$host   = substr( $host, 0, $socket_pos );
		}

		if ( substr_count( $host, ':' ) > 1 ) {
			$pattern = '#^(?:\[)?(?P<host>[0-9a-fA-F:]+)(?:\]:(?P<port>[\d]+))?#';
			$is_ipv6 = true;
		} else {
			$pattern = '#^(?P<host>[^:/]*)(?::(?P<port>[\d]+))?#';
		}

		$matches = array();
		$result  = preg_match( $pattern, $host, $matches );
		if ( 1 !== $result ) {
			return false;
		}

		$host = ! empty( $matches['host'] ) ? $matches['host'] : '';
		$port = ! empty( $matches['port'] ) ? abs( (int) $matches['port'] ) : null;

		return array( $host, $port, $socket, $is_ipv6 );
	}
}

require_once getcwd() . '/../../plugin-sqlite-database-integration/wp-includes/postgresql/class-wp-postgresql-db.php';

class WP_PostgreSQL_DB_Unparsed_Host extends WP_PostgreSQL_DB {
	public function __construct() {}

	public function parse_db_host( $host ) {
		return false;
	}
}

function wp_postgresql_db_get_connection_options( WP_PostgreSQL_DB $db ) {
	$method = new ReflectionMethod( WP_PostgreSQL_DB::class, 'get_connection_options' );
	if ( PHP_VERSION_ID < 80100 ) {
		$method->setAccessible( true );
	}
	return $method->invoke( $db );
}

function wp_postgresql_db_options_for_host( $case, $dbhost ) {
	$db             = ( new ReflectionClass( WP_PostgreSQL_DB::class ) )->newInstanceWithoutConstructor();
	$db->dbuser     = 'user_' . $case;
	$db->dbpassword = 'password_' . $case;
	$db->dbname     = 'name_' . $case;
	$db->dbhost     = $dbhost;

	return wp_postgresql_db_get_connection_options( $db );
}

$options = array(
	'host_only'                 => wp_postgresql_db_options_for_host( 'host_only', 'postgres' ),
	'host_port'                 => wp_postgresql_db_options_for_host( 'host_port', 'postgres:6543' ),
	'socket_file'               => wp_postgresql_db_options_for_host( 'socket_file', 'localhost:/tmp/.s.PGSQL.6544' ),
	'explicit_port_socket_file' => wp_postgresql_db_options_for_host( 'explicit_port_socket_file', 'localhost:6545:/tmp/.s.PGSQL.6544' ),
	'socket_directory'          => wp_postgresql_db_options_for_host( 'socket_directory', 'localhost:/var/run/postgresql' ),
);

$unparsed_db             = new WP_PostgreSQL_DB_Unparsed_Host();
$unparsed_db->dbuser     = 'user_unparsed_fallback';
$unparsed_db->dbpassword = 'password_unparsed_fallback';
$unparsed_db->dbname     = 'name_unparsed_fallback';
$unparsed_db->dbhost     = 'fallback-host';

$options['unparsed_fallback'] = wp_postgresql_db_get_connection_options( $unparsed_db );

wp_postgresql_db_test_respond( $options );
PHP
		);

		$this->assertSame(
			array(
				'host_only'                 => array(
					'host'     => 'postgres',
					'port'     => null,
					'dbname'   => 'name_host_only',
					'user'     => 'user_host_only',
					'password' => 'password_host_only',
				),
				'host_port'                 => array(
					'host'     => 'postgres',
					'port'     => 6543,
					'dbname'   => 'name_host_port',
					'user'     => 'user_host_port',
					'password' => 'password_host_port',
				),
				'socket_file'               => array(
					'host'     => '/tmp',
					'port'     => 6544,
					'dbname'   => 'name_socket_file',
					'user'     => 'user_socket_file',
					'password' => 'password_socket_file',
				),
				'explicit_port_socket_file' => array(
					'host'     => '/tmp',
					'port'     => 6545,
					'dbname'   => 'name_explicit_port_socket_file',
					'user'     => 'user_explicit_port_socket_file',
					'password' => 'password_explicit_port_socket_file',
				),
				'socket_directory'          => array(
					'host'     => '/var/run/postgresql',
					'port'     => null,
					'dbname'   => 'name_socket_directory',
					'user'     => 'user_socket_directory',
					'password' => 'password_socket_directory',
				),
				'unparsed_fallback'         => array(
					'host'     => 'fallback-host',
					'port'     => null,
					'dbname'   => 'name_unparsed_fallback',
					'user'     => 'user_unparsed_fallback',
					'password' => 'password_unparsed_fallback',
				),
			),
			$result
		);
	}

	/**
	 * Tests PostgreSQL connection options only reuse PostgreSQL-backed global PDO objects.
	 */
	public function test_get_connection_options_reuses_only_global_postgresql_pdo(): void {
		$result = $this->run_isolated_wpdb_script(
			<<<'PHP'
require_once getcwd() . '/bootstrap.php';

class wpdb {
	public $dbuser     = 'pg_user';
	public $dbpassword = 'pg_password';
	public $dbname     = 'wptests';
	public $dbhost     = 'postgres:5432';

	public function parse_db_host( $host ) {
		return array( 'postgres', 5432, null, false );
	}
}

require_once getcwd() . '/../../plugin-sqlite-database-integration/wp-includes/postgresql/class-wp-postgresql-db.php';

class WP_PostgreSQL_DB_PDO_Filter_Fake_PDO extends PDO {
	private $driver_name;
	private $throw_on_driver_lookup;

	public function __construct( $driver_name, $throw_on_driver_lookup = false ) {
		$this->driver_name           = $driver_name;
		$this->throw_on_driver_lookup = $throw_on_driver_lookup;
	}

	/**
	 * Get a fake PDO attribute.
	 *
	 * @param int $attribute PDO attribute.
	 * @return mixed Attribute value.
	 */
	#[\ReturnTypeWillChange]
	public function getAttribute( $attribute ) {
		if ( PDO::ATTR_DRIVER_NAME === $attribute ) {
			if ( $this->throw_on_driver_lookup ) {
				throw new RuntimeException( 'driver lookup failed' );
			}

			return $this->driver_name;
		}

		return null;
	}
}

function wp_postgresql_db_get_connection_options_for_global_pdo( $global_value, $set_global ) {
	if ( $set_global ) {
		$GLOBALS['@pdo'] = $global_value;
	} else {
		unset( $GLOBALS['@pdo'] );
	}

	$db = ( new ReflectionClass( WP_PostgreSQL_DB::class ) )->newInstanceWithoutConstructor();

	$method = new ReflectionMethod( WP_PostgreSQL_DB::class, 'get_connection_options' );
	if ( PHP_VERSION_ID < 80100 ) {
		$method->setAccessible( true );
	}

	$options = $method->invoke( $db );

	return array(
		'has_pdo'  => array_key_exists( 'pdo', $options ),
		'pdo_same' => array_key_exists( 'pdo', $options ) ? $options['pdo'] === $global_value : null,
		'keys'     => array_keys( $options ),
	);
}

$pgsql_pdo    = new WP_PostgreSQL_DB_PDO_Filter_Fake_PDO( 'pgsql' );
$mysql_pdo    = new WP_PostgreSQL_DB_PDO_Filter_Fake_PDO( 'mysql' );
$throwing_pdo = new WP_PostgreSQL_DB_PDO_Filter_Fake_PDO( 'pgsql', true );

wp_postgresql_db_test_respond(
	array(
		'no_global'     => wp_postgresql_db_get_connection_options_for_global_pdo( null, false ),
		'pgsql_pdo'     => wp_postgresql_db_get_connection_options_for_global_pdo( $pgsql_pdo, true ),
		'mysql_pdo'     => wp_postgresql_db_get_connection_options_for_global_pdo( $mysql_pdo, true ),
		'throwing_pdo'  => wp_postgresql_db_get_connection_options_for_global_pdo( $throwing_pdo, true ),
		'non_pdo_value' => wp_postgresql_db_get_connection_options_for_global_pdo( (object) array( 'driver' => 'pgsql' ), true ),
	)
);
PHP
		);

		$base_keys = array( 'host', 'port', 'dbname', 'user', 'password' );

		$this->assertSame(
			array(
				'no_global'     => array(
					'has_pdo'  => false,
					'pdo_same' => null,
					'keys'     => $base_keys,
				),
				'pgsql_pdo'     => array(
					'has_pdo'  => true,
					'pdo_same' => true,
					'keys'     => array_merge( $base_keys, array( 'pdo' ) ),
				),
				'mysql_pdo'     => array(
					'has_pdo'  => false,
					'pdo_same' => null,
					'keys'     => $base_keys,
				),
				'throwing_pdo'  => array(
					'has_pdo'  => false,
					'pdo_same' => null,
					'keys'     => $base_keys,
				),
				'non_pdo_value' => array(
					'has_pdo'  => false,
					'pdo_same' => null,
					'keys'     => $base_keys,
				),
			),
			$result
		);
	}

	/**
	 * Tests select() uses the current PostgreSQL driver when no handle is passed.
	 */
	public function test_select_uses_current_postgresql_driver_when_handle_is_omitted(): void {
		$result = $this->run_isolated_wpdb_script(
			<<<'PHP'
require_once getcwd() . '/bootstrap.php';

class wpdb {
	public $dbname = '';
	public $ready  = false;
}

require_once getcwd() . '/../../plugin-sqlite-database-integration/wp-includes/postgresql/class-wp-postgresql-db.php';

class WP_PostgreSQL_DB_Select_Fake_Driver extends WP_PostgreSQL_Driver {
	public function __construct() {}
}

$db         = ( new ReflectionClass( WP_PostgreSQL_DB::class ) )->newInstanceWithoutConstructor();
$db->dbname = 'wptests';

$driver_property = new ReflectionProperty( WP_PostgreSQL_DB::class, 'dbh' );
$driver_property->setAccessible( true );
$driver_property->setValue( $db, new WP_PostgreSQL_DB_Select_Fake_Driver() );

$select_other_default_result = $db->select( 'other' );
$ready_after_other_default   = $db->ready;

$select_current_default_result = $db->select( 'wptests' );
$ready_after_current_default   = $db->ready;

$driver_property->setValue( $db, null );
$db->ready                    = true;
$select_missing_driver_result = $db->select( 'wptests' );
$ready_after_missing_driver   = $db->ready;

wp_postgresql_db_test_respond(
	array(
		'select_other_default_result'   => $select_other_default_result,
		'ready_after_other_default'     => $ready_after_other_default,
		'select_current_default_result' => $select_current_default_result,
		'ready_after_current_default'   => $ready_after_current_default,
		'select_missing_driver_result'  => $select_missing_driver_result,
		'ready_after_missing_driver'    => $ready_after_missing_driver,
	)
);
PHP
		);

		$this->assertSame(
			array(
				'select_other_default_result'   => false,
				'ready_after_other_default'     => false,
				'select_current_default_result' => true,
				'ready_after_current_default'   => true,
				'select_missing_driver_result'  => false,
				'ready_after_missing_driver'    => false,
			),
			$result
		);
	}

	/**
	 * Tests db_connect() rejects missing PostgreSQL database names before connecting.
	 */
	public function test_db_connect_rejects_missing_database_name_before_connecting(): void {
		$result = $this->run_isolated_wpdb_script(
			<<<'PHP'
require_once getcwd() . '/bootstrap.php';

class wpdb {
	public $dbname     = null;
	public $ready      = true;
	public $is_mysql   = false;
	public $last_error = 'previous error';
	public $charset    = '';
	public $bail_calls = array();

	public function init_charset() {
		$this->charset = 'utf8mb4';
	}

	public function bail( $message, $error_code = '500' ) {
		$this->bail_calls[] = array( $message, $error_code );
	}
}

require_once getcwd() . '/../../plugin-sqlite-database-integration/wp-includes/postgresql/class-wp-postgresql-db.php';

$null_db              = ( new ReflectionClass( WP_PostgreSQL_DB::class ) )->newInstanceWithoutConstructor();
$null_result          = $null_db->db_connect( false );
$null_db_bail_calls   = $null_db->bail_calls;
$null_db_last_error   = $null_db->last_error;
$null_db_ready        = $null_db->ready;
$null_db_is_mysql     = $null_db->is_mysql;
$null_db_charset      = $null_db->charset;

$empty_db             = ( new ReflectionClass( WP_PostgreSQL_DB::class ) )->newInstanceWithoutConstructor();
$empty_db->dbname     = '';
$empty_result         = $empty_db->db_connect( true );
$empty_db_bail_calls  = $empty_db->bail_calls;
$empty_db_last_error  = $empty_db->last_error;
$empty_db_ready       = $empty_db->ready;
$empty_db_is_mysql    = $empty_db->is_mysql;
$empty_db_charset     = $empty_db->charset;

wp_postgresql_db_test_respond(
	array(
		'null_result'          => $null_result,
		'null_bail_calls'      => $null_db_bail_calls,
		'null_last_error'      => $null_db_last_error,
		'null_ready'           => $null_db_ready,
		'null_is_mysql'        => $null_db_is_mysql,
		'null_charset'         => $null_db_charset,
		'empty_result'         => $empty_result,
		'empty_bail_calls'     => $empty_db_bail_calls,
		'empty_last_error'     => $empty_db_last_error,
		'empty_ready'          => $empty_db_ready,
		'empty_is_mysql'       => $empty_db_is_mysql,
		'empty_charset'        => $empty_db_charset,
	)
);
PHP
		);

		$expected_error = 'The database name was not set. The PostgreSQL backend requires DB_NAME.';

		$this->assertSame(
			array(
				'null_result'      => false,
				'null_bail_calls'  => array(),
				'null_last_error'  => $expected_error,
				'null_ready'       => false,
				'null_is_mysql'    => true,
				'null_charset'     => 'utf8mb4',
				'empty_result'     => false,
				'empty_bail_calls' => array(
					array( $expected_error, 'db_connect_fail' ),
				),
				'empty_last_error' => $expected_error,
				'empty_ready'      => false,
				'empty_is_mysql'   => true,
				'empty_charset'    => 'utf8mb4',
			),
			$result
		);
	}

	/**
	 * Tests db_connect() maps connection option failures to wpdb state.
	 */
	public function test_db_connect_maps_connection_option_failures_to_wpdb_state(): void {
		$result = $this->run_isolated_wpdb_script(
			<<<'PHP'
require_once getcwd() . '/bootstrap.php';

class wpdb {
	public $dbuser     = 'pg_user';
	public $dbpassword = 'pg_password';
	public $dbname     = 'wptests';
	public $dbhost     = 'bad;host';
	public $ready      = true;
	public $is_mysql   = false;
	public $last_error = 'previous error';
	public $charset    = '';
	public $bail_calls = array();

	public function init_charset() {
		$this->charset = 'utf8mb4';
	}

	public function parse_db_host( $host ) {
		return false;
	}

	public function bail( $message, $error_code = '500' ) {
		$this->bail_calls[] = array( $message, $error_code );
	}
}

require_once getcwd() . '/../../plugin-sqlite-database-integration/wp-includes/postgresql/class-wp-postgresql-db.php';

unset( $GLOBALS['@pdo'] );

$dbh_property = new ReflectionProperty( WP_PostgreSQL_DB::class, 'dbh' );
$dbh_property->setAccessible( true );

$non_bailing_db             = ( new ReflectionClass( WP_PostgreSQL_DB::class ) )->newInstanceWithoutConstructor();
$non_bailing_result         = $non_bailing_db->db_connect( false );
$non_bailing_dbh_after      = $dbh_property->getValue( $non_bailing_db );
$non_bailing_bail_calls     = $non_bailing_db->bail_calls;
$non_bailing_last_error     = $non_bailing_db->last_error;
$non_bailing_ready          = $non_bailing_db->ready;
$non_bailing_is_mysql       = $non_bailing_db->is_mysql;
$non_bailing_charset        = $non_bailing_db->charset;

$bailing_db             = ( new ReflectionClass( WP_PostgreSQL_DB::class ) )->newInstanceWithoutConstructor();
$bailing_result         = $bailing_db->db_connect( true );
$bailing_dbh_after      = $dbh_property->getValue( $bailing_db );
$bailing_bail_calls     = $bailing_db->bail_calls;
$bailing_last_error     = $bailing_db->last_error;
$bailing_ready          = $bailing_db->ready;
$bailing_is_mysql       = $bailing_db->is_mysql;
$bailing_charset        = $bailing_db->charset;

wp_postgresql_db_test_respond(
	array(
		'non_bailing_result'     => $non_bailing_result,
		'non_bailing_dbh_null'   => null === $non_bailing_dbh_after,
		'non_bailing_bail_calls' => $non_bailing_bail_calls,
		'non_bailing_last_error' => $non_bailing_last_error,
		'non_bailing_ready'      => $non_bailing_ready,
		'non_bailing_is_mysql'   => $non_bailing_is_mysql,
		'non_bailing_charset'    => $non_bailing_charset,
		'bailing_result'         => $bailing_result,
		'bailing_dbh_null'       => null === $bailing_dbh_after,
		'bailing_bail_calls'     => $bailing_bail_calls,
		'bailing_last_error'     => $bailing_last_error,
		'bailing_ready'          => $bailing_ready,
		'bailing_is_mysql'       => $bailing_is_mysql,
		'bailing_charset'        => $bailing_charset,
	)
);
PHP
		);

		$expected_error = 'PostgreSQL DSN parts cannot contain NUL bytes or semicolons.';

		$this->assertSame(
			array(
				'non_bailing_result'     => false,
				'non_bailing_dbh_null'   => true,
				'non_bailing_bail_calls' => array(),
				'non_bailing_last_error' => $expected_error,
				'non_bailing_ready'      => false,
				'non_bailing_is_mysql'   => true,
				'non_bailing_charset'    => 'utf8mb4',
				'bailing_result'         => false,
				'bailing_dbh_null'       => true,
				'bailing_bail_calls'     => array(
					array( $expected_error, 'db_connect_fail' ),
				),
				'bailing_last_error'     => $expected_error,
				'bailing_ready'          => false,
				'bailing_is_mysql'       => true,
				'bailing_charset'        => 'utf8mb4',
			),
			$result
		);
	}

	/**
	 * Tests check_connection() probes an existing driver and reconnects after failure.
	 */
	public function test_check_connection_probes_existing_driver_and_reconnects_after_failure(): void {
		$result = $this->run_isolated_wpdb_script(
			<<<'PHP'
require_once getcwd() . '/bootstrap.php';

if ( ! class_exists( 'wpdb', false ) ) {
	class wpdb {
		public $ready      = true;
		public $last_error = '';
		public $dbname     = '';
		public $bail_calls = array();

		public function bail( $message, $error_code = '500' ) {
			$this->bail_calls[] = array( $message, $error_code );
		}
	}
}

require_once getcwd() . '/../../plugin-sqlite-database-integration/wp-includes/postgresql/class-wp-postgresql-db.php';

class WP_PostgreSQL_DB_Check_Fake_Connection extends WP_PostgreSQL_Connection {
	public $queries      = array();
	public $should_throw = false;

	public function __construct() {}

	public function query( string $sql, array $params = array() ): PDOStatement {
		$this->queries[] = array( $sql, $params );

		if ( $this->should_throw ) {
			throw new RuntimeException( 'health probe failed' );
		}

		return ( new ReflectionClass( PDOStatement::class ) )->newInstanceWithoutConstructor();
	}
}

class WP_PostgreSQL_DB_Check_Fake_Driver extends WP_PostgreSQL_Driver {
	public $connection;

	public function __construct() {}

	public function get_connection(): WP_PostgreSQL_Connection {
		return $this->connection;
	}
}

class WP_PostgreSQL_DB_Check_Testable extends WP_PostgreSQL_DB {
	public $db_connect_calls = array();

	public function __construct() {}

	public function db_connect( $allow_bail = true ) {
		$this->db_connect_calls[] = $allow_bail;
		return false;
	}
}

function wp_postgresql_db_check_set_driver( WP_PostgreSQL_DB $db, $driver ) {
	$driver_property = new ReflectionProperty( WP_PostgreSQL_DB::class, 'dbh' );
	if ( PHP_VERSION_ID < 80100 ) {
		$driver_property->setAccessible( true );
	}
	$driver_property->setValue( $db, $driver );
}

function wp_postgresql_db_check_get_driver( WP_PostgreSQL_DB $db ) {
	$driver_property = new ReflectionProperty( WP_PostgreSQL_DB::class, 'dbh' );
	if ( PHP_VERSION_ID < 80100 ) {
		$driver_property->setAccessible( true );
	}
	return $driver_property->getValue( $db );
}

$success_connection             = new WP_PostgreSQL_DB_Check_Fake_Connection();
$success_driver                 = new WP_PostgreSQL_DB_Check_Fake_Driver();
$success_driver->connection     = $success_connection;
$success_db                     = new WP_PostgreSQL_DB_Check_Testable();
$success_db->ready              = true;
$success_db->last_error         = 'previous';
$success_db->dbname             = strtolower( 'WordPress' );
wp_postgresql_db_check_set_driver( $success_db, $success_driver );
$success_result                 = $success_db->check_connection( false );
$success_driver_after_probe     = wp_postgresql_db_check_get_driver( $success_db );

$failure_connection               = new WP_PostgreSQL_DB_Check_Fake_Connection();
$failure_connection->should_throw = true;
$failure_driver                   = new WP_PostgreSQL_DB_Check_Fake_Driver();
$failure_driver->connection       = $failure_connection;
$failure_db                       = new WP_PostgreSQL_DB_Check_Testable();
$failure_db->ready                = true;
$failure_db->last_error           = 'previous';
$failure_db->dbname               = strtolower( 'WordPress' );
wp_postgresql_db_check_set_driver( $failure_db, $failure_driver );
$failure_result                   = $failure_db->check_connection( false );
$failure_driver_after_probe       = wp_postgresql_db_check_get_driver( $failure_db );

wp_postgresql_db_test_respond(
	array(
		'success_result'             => $success_result,
		'success_queries'            => $success_connection->queries,
		'success_ready'              => $success_db->ready,
		'success_last_error'         => $success_db->last_error,
		'success_db_connect_calls'   => $success_db->db_connect_calls,
		'success_driver_after_probe' => $success_driver_after_probe instanceof WP_PostgreSQL_Driver,
		'failure_queries'            => $failure_connection->queries,
		'failure_result'             => $failure_result,
		'failure_ready'              => $failure_db->ready,
		'failure_last_error'         => $failure_db->last_error,
		'failure_driver_after_probe' => null === $failure_driver_after_probe,
		'failure_db_connect_calls'   => $failure_db->db_connect_calls,
		'failure_bail_calls'         => $failure_db->bail_calls,
	)
);
PHP
		);

		$this->assertSame(
			array(
				'success_result'             => true,
				'success_queries'            => array(
					array( 'SELECT 1', array() ),
				),
				'success_ready'              => true,
				'success_last_error'         => 'previous',
				'success_db_connect_calls'   => array(),
				'success_driver_after_probe' => true,
				'failure_queries'            => array(
					array( 'SELECT 1', array() ),
				),
				'failure_result'             => false,
				'failure_ready'              => false,
				'failure_last_error'         => 'health probe failed',
				'failure_driver_after_probe' => true,
				'failure_db_connect_calls'   => array( false ),
				'failure_bail_calls'         => array(),
			),
			$result
		);
	}

	/**
	 * Tests db_server_info() reports a pending PostgreSQL connection without a driver.
	 */
	public function test_db_server_info_reports_pending_connection_without_driver(): void {
		$result = $this->run_isolated_wpdb_script(
			<<<'PHP'
require_once getcwd() . '/bootstrap.php';

class wpdb {}

require_once getcwd() . '/../../plugin-sqlite-database-integration/wp-includes/postgresql/class-wp-postgresql-db.php';

$db = ( new ReflectionClass( WP_PostgreSQL_DB::class ) )->newInstanceWithoutConstructor();

$driver_property = new ReflectionProperty( WP_PostgreSQL_DB::class, 'dbh' );
$driver_property->setAccessible( true );
$driver_property->setValue( $db, null );

wp_postgresql_db_test_respond(
	array(
		'server_info' => $db->db_server_info(),
	)
);
PHP
		);

		$this->assertSame(
			array(
				'server_info' => 'PostgreSQL backend pending connection',
			),
			$result
		);
	}

	/**
	 * Tests query() returns before the driver for not-ready and empty queries.
	 */
	public function test_query_returns_false_before_driver_for_not_ready_and_empty_queries(): void {
		$result = $this->run_isolated_wpdb_script(
			<<<'PHP'
$GLOBALS['wp_postgresql_db_query_filter_inputs'] = array();

function apply_filters( $hook_name, $value ) {
	if ( 'query' !== $hook_name ) {
		return $value;
	}

	$GLOBALS['wp_postgresql_db_query_filter_inputs'][] = $value;
	if ( 'FILTER_TO_EMPTY' === $value ) {
		return '';
	}

	return $value;
}

require_once getcwd() . '/bootstrap.php';

class wpdb {
	public $ready         = true;
	public $insert_id     = 0;
	public $last_query    = null;
	public $num_queries   = 0;
	public $last_result   = array();
	public $col_info      = null;
	public $rows_affected = 0;
	public $num_rows      = 0;
	public $last_error    = '';
	public $result        = null;
}

require_once getcwd() . '/../../plugin-sqlite-database-integration/wp-includes/postgresql/class-wp-postgresql-db.php';

class WP_PostgreSQL_DB_Query_Early_Return_Fake_Driver extends WP_PostgreSQL_Driver {
	public $queries = array();

	public function __construct() {}

	public function query( string $query, $fetch_mode = PDO::FETCH_OBJ, ...$fetch_mode_args ) {
		$this->queries[] = $query;
		return array();
	}
}

$db     = ( new ReflectionClass( WP_PostgreSQL_DB::class ) )->newInstanceWithoutConstructor();
$driver = new WP_PostgreSQL_DB_Query_Early_Return_Fake_Driver();

$driver_property = new ReflectionProperty( WP_PostgreSQL_DB::class, 'dbh' );
if ( PHP_VERSION_ID < 80100 ) {
	$driver_property->setAccessible( true );
}
$driver_property->setValue( $db, $driver );

$db->ready     = false;
$db->insert_id = 123;
$not_ready     = array(
	'return'             => $db->query( 'SELECT 1' ),
	'insert_id'          => $db->insert_id,
	'num_queries'        => $db->num_queries,
	'last_query'         => $db->last_query,
	'driver_query_count' => count( $driver->queries ),
	'filter_input_count' => count( $GLOBALS['wp_postgresql_db_query_filter_inputs'] ),
);

$db->ready     = true;
$db->insert_id = 456;
$empty_query   = array(
	'return'             => $db->query( '' ),
	'insert_id'          => $db->insert_id,
	'num_queries'        => $db->num_queries,
	'last_query'         => $db->last_query,
	'driver_query_count' => count( $driver->queries ),
	'filter_inputs'      => $GLOBALS['wp_postgresql_db_query_filter_inputs'],
);

$db->insert_id    = 789;
$filter_cancelled = array(
	'return'             => $db->query( 'FILTER_TO_EMPTY' ),
	'insert_id'          => $db->insert_id,
	'num_queries'        => $db->num_queries,
	'last_query'         => $db->last_query,
	'driver_query_count' => count( $driver->queries ),
	'filter_inputs'      => $GLOBALS['wp_postgresql_db_query_filter_inputs'],
);

wp_postgresql_db_test_respond(
	array(
		'not_ready'        => $not_ready,
		'empty_query'      => $empty_query,
		'filter_cancelled' => $filter_cancelled,
	)
);
PHP
		);

		$this->assertSame(
			array(
				'return'             => false,
				'insert_id'          => 123,
				'num_queries'        => 0,
				'last_query'         => null,
				'driver_query_count' => 0,
				'filter_input_count' => 0,
			),
			$result['not_ready']
		);

		$this->assertSame(
			array(
				'return'             => false,
				'insert_id'          => 0,
				'num_queries'        => 0,
				'last_query'         => null,
				'driver_query_count' => 0,
				'filter_inputs'      => array( '' ),
			),
			$result['empty_query']
		);

		$this->assertSame(
			array(
				'return'             => false,
				'insert_id'          => 0,
				'num_queries'        => 0,
				'last_query'         => null,
				'driver_query_count' => 0,
				'filter_inputs'      => array( '', 'FILTER_TO_EMPTY' ),
			),
			$result['filter_cancelled']
		);
	}

	/**
	 * Tests query state, metadata, and SAVEQUERIES mapping.
	 */
	public function test_query_maps_backend_state_to_wpdb_fields(): void {
		$result = $this->run_isolated_wpdb_script(
			<<<'PHP'
define( 'SAVEQUERIES', true );

require_once getcwd() . '/bootstrap.php';

function wp_load_translations_early() {}
function is_multisite() {
	return false;
}
function __( $text ) {
	return $text;
}

class wpdb {
	public $ready           = true;
	public $insert_id       = 0;
	public $last_query      = null;
	public $func_call       = null;
	public $last_error      = '';
	public $queries         = array();
	public $num_queries     = 0;
	public $last_result     = array();
	public $col_info        = null;
	public $rows_affected   = 0;
	public $num_rows        = 0;
	public $result          = null;
	public $suppress_errors = true;
	public $show_errors     = false;
	public $time_start      = 0;

	public function timer_start() {
		$this->time_start = microtime( true );
	}

	public function timer_stop() {
		return microtime( true ) - $this->time_start;
	}

	public function get_caller() {
		return 'wpdb-test';
	}

	public function log_query( $query, $elapsed, $caller, $start, $data ) {
		$this->queries[] = array(
			'query'   => $query,
			'elapsed' => $elapsed,
			'caller'  => $caller,
			'start'   => $start,
			'data'    => $data,
		);
	}

	public function add_placeholder_escape( $query ) {
		return $query;
	}

	public function get_col_info( $info_type = 'name', $col_offset = -1 ) {
		$this->load_col_info();

		if ( -1 === $col_offset ) {
			return array_map(
				static function ( $column ) use ( $info_type ) {
					return $column->{$info_type};
				},
				$this->col_info
			);
		}

		return $this->col_info[ $col_offset ]->{$info_type} ?? null;
	}

	protected function load_col_info() {}
}

require_once getcwd() . '/../../plugin-sqlite-database-integration/wp-includes/postgresql/class-wp-postgresql-db.php';

class WP_PostgreSQL_DB_Fake_Driver extends WP_PostgreSQL_Driver {
	private $last_return_value       = 0;
	private $insert_id               = 0;
	private $last_postgresql_queries = array();
	private $last_column_meta        = array();

	public function __construct() {}

	public function query( string $query, $fetch_mode = PDO::FETCH_OBJ, ...$fetch_mode_args ) {
		$this->last_postgresql_queries = array(
			array(
				'sql'    => $query,
				'params' => array(),
			),
		);

		if ( false !== strpos( $query, 'broken' ) ) {
			throw new RuntimeException( 'synthetic backend failure' );
		}

		if ( 0 === stripos( $query, 'insert' ) ) {
			$this->last_return_value = 1;
			$this->insert_id         = 7;
			$this->last_column_meta  = array();
			return 1;
		}

		$this->last_return_value = 0;
		$this->insert_id         = 0;
		$this->last_column_meta  = array(
			array(
				'name'             => 'id',
				'mysqli:orgname'   => 'id',
				'table'            => 'probe',
				'mysqli:orgtable'  => 'probe',
				'mysqli:db'        => 'wptests',
				'len'              => 11,
				'mysqli:charsetnr' => 63,
				'mysqli:flags'     => 1,
				'mysqli:type'      => 3,
				'precision'        => 0,
			),
			array(
				'name'             => 'label',
				'mysqli:orgname'   => 'label',
				'table'            => 'probe',
				'mysqli:orgtable'  => 'probe',
				'mysqli:db'        => 'wptests',
				'len'              => 255,
				'mysqli:charsetnr' => 45,
				'mysqli:flags'     => 0,
				'mysqli:type'      => 253,
				'precision'        => 0,
			),
		);

		return array(
			(object) array(
				'id'    => '1',
				'label' => 'ok',
			),
		);
	}

	public function get_last_return_value() {
		return $this->last_return_value;
	}

	public function get_insert_id() {
		return $this->insert_id;
	}

	public function get_last_postgresql_queries(): array {
		return $this->last_postgresql_queries;
	}

	public function get_last_column_meta(): array {
		return $this->last_column_meta;
	}
}

$db = ( new ReflectionClass( WP_PostgreSQL_DB::class ) )->newInstanceWithoutConstructor();

$driver_property = new ReflectionProperty( WP_PostgreSQL_DB::class, 'dbh' );
$driver_property->setAccessible( true );
$driver_property->setValue( $db, new WP_PostgreSQL_DB_Fake_Driver() );
$db->ready = true;

$select_return = $db->query( 'SELECT id, label FROM probe' );
$select        = array(
	'return'             => $select_return,
	'num_rows'           => $db->num_rows,
	'last_result_label'  => $db->last_result[0]->label ?? null,
	'col_names'          => $db->get_col_info( 'name' ),
	'first_col_type'     => $db->get_col_info( 'type', 0 ),
	'savequeries_pg_sql' => $db->queries[0]['postgresql_queries'][0]['sql'] ?? null,
);

$insert_return = $db->query( "INSERT INTO probe (label) VALUES ('ok')" );
$insert        = array(
	'return'        => $insert_return,
	'rows_affected' => $db->rows_affected,
	'insert_id'     => $db->insert_id,
);

$failed_return = $db->query( "INSERT INTO broken (label) VALUES ('bad')" );
$failed_insert = array(
	'return'     => $failed_return,
	'last_error' => $db->last_error,
	'insert_id'  => $db->insert_id,
);

wp_postgresql_db_test_respond(
	array(
		'select'        => $select,
		'insert'        => $insert,
		'failed_insert' => $failed_insert,
	)
);
PHP
		);

		$this->assertSame(
			array(
				'return'             => 1,
				'num_rows'           => 1,
				'last_result_label'  => 'ok',
				'col_names'          => array( 'id', 'label' ),
				'first_col_type'     => 3,
				'savequeries_pg_sql' => 'SELECT id, label FROM probe',
			),
			$result['select']
		);

		$this->assertSame(
			array(
				'return'        => 1,
				'rows_affected' => 1,
				'insert_id'     => 7,
			),
			$result['insert']
		);

		$this->assertSame(
			array(
				'return'     => false,
				'last_error' => 'synthetic backend failure',
				'insert_id'  => 0,
			),
			$result['failed_insert']
		);
	}

	/**
	 * Tests query() detects write statements after leading SQL comments.
	 */
	public function test_query_detects_statement_keyword_after_leading_sql_comments(): void {
		$result = $this->run_isolated_wpdb_script(
			<<<'PHP'
require_once getcwd() . '/bootstrap.php';

class wpdb {
	public $ready           = true;
	public $insert_id       = 0;
	public $last_query      = null;
	public $func_call       = null;
	public $last_error      = '';
	public $num_queries     = 0;
	public $last_result     = array();
	public $col_info        = null;
	public $rows_affected   = 0;
	public $num_rows        = 0;
	public $result          = null;
	public $suppress_errors = true;
	public $show_errors     = false;

	public function get_caller() {
		return 'wpdb-leading-comments-test';
	}

	public function add_placeholder_escape( $query ) {
		return $query;
	}
}

global $EZSQL_ERROR;
$EZSQL_ERROR = array();

require_once getcwd() . '/../../plugin-sqlite-database-integration/wp-includes/postgresql/class-wp-postgresql-db.php';

class WP_PostgreSQL_DB_Leading_Comments_Fake_Driver extends WP_PostgreSQL_Driver {
	private $insert_id = 0;
	private $last_return_value = 0;
	private $queries = array();

	public function __construct() {}

	public function query( string $query, $fetch_mode = PDO::FETCH_OBJ, ...$fetch_mode_args ) {
		$this->queries[] = $query;

		if ( false !== strpos( $query, 'broken' ) ) {
			throw new RuntimeException( 'synthetic comment-prefixed failure' );
		}

		$this->insert_id         = 42;
		$this->last_return_value = 3;
		return 3;
	}

	public function get_last_return_value() {
		return $this->last_return_value;
	}

	public function get_insert_id() {
		return $this->insert_id;
	}

	public function get_last_postgresql_queries(): array {
		return array(
			array(
				'sql'    => end( $this->queries ),
				'params' => array(),
			),
		);
	}

	public function get_recorded_queries(): array {
		return $this->queries;
	}
}

$db = ( new ReflectionClass( WP_PostgreSQL_DB::class ) )->newInstanceWithoutConstructor();

$driver = new WP_PostgreSQL_DB_Leading_Comments_Fake_Driver();
$driver_property = new ReflectionProperty( WP_PostgreSQL_DB::class, 'dbh' );
$driver_property->setAccessible( true );
$driver_property->setValue( $db, $driver );
$db->ready = true;

$commented_insert = "/* plugin preamble */\n-- runtime marker\nINSERT INTO probe (label) VALUES ('ok')";
$insert_return    = $db->query( $commented_insert );
$insert           = array(
	'return'        => $insert_return,
	'rows_affected' => $db->rows_affected,
	'insert_id'     => $db->insert_id,
	'num_rows'      => $db->num_rows,
	'last_error'    => $db->last_error,
	'queries'       => $driver->get_recorded_queries(),
);

$db->insert_id = 99;

$commented_failed_insert = "/* plugin preamble */\n-- runtime marker\nINSERT INTO broken (label) VALUES ('bad')";
$failed_return           = $db->query( $commented_failed_insert );
$failed_insert           = array(
	'return'        => $failed_return,
	'last_error'    => $db->last_error,
	'insert_id'     => $db->insert_id,
	'rows_affected' => $db->rows_affected,
	'num_rows'      => $db->num_rows,
	'queries'       => $driver->get_recorded_queries(),
	'errors'        => $EZSQL_ERROR,
);

wp_postgresql_db_test_respond(
	array(
		'commented_insert'        => $commented_insert,
		'commented_failed_insert' => $commented_failed_insert,
		'insert'                  => $insert,
		'failed_insert'           => $failed_insert,
	)
);
PHP
		);

		$commented_insert        = "/* plugin preamble */\n-- runtime marker\nINSERT INTO probe (label) VALUES ('ok')";
		$commented_failed_insert = "/* plugin preamble */\n-- runtime marker\nINSERT INTO broken (label) VALUES ('bad')";

		$this->assertSame( $commented_insert, $result['commented_insert'] );
		$this->assertSame(
			array(
				'return'        => 3,
				'rows_affected' => 3,
				'insert_id'     => 42,
				'num_rows'      => 0,
				'last_error'    => '',
				'queries'       => array(
					$commented_insert,
				),
			),
			$result['insert']
		);

		$this->assertSame( $commented_failed_insert, $result['commented_failed_insert'] );
		$this->assertSame(
			array(
				'return'        => false,
				'last_error'    => 'synthetic comment-prefixed failure',
				'insert_id'     => 0,
				'rows_affected' => 0,
				'num_rows'      => 0,
				'queries'       => array(
					$commented_insert,
					$commented_failed_insert,
				),
				'errors'        => array(
					array(
						'query'     => $commented_failed_insert,
						'error_str' => 'synthetic comment-prefixed failure',
					),
				),
			),
			$result['failed_insert']
		);
	}

	/**
	 * Tests the SQL generated by real wpdb helper methods before the driver sees it.
	 */
	public function test_real_wpdb_update_and_delete_helpers_pass_backticked_sql_to_driver(): void {
		$wpdb_file = __DIR__ . '/../../../wordpress/src/wp-includes/class-wpdb.php';
		if ( ! is_readable( $wpdb_file ) ) {
			$this->markTestSkipped( 'Real WordPress wpdb class is not available.' );
		}

		$result = $this->run_isolated_wpdb_script(
			<<<'PHP'
require_once getcwd() . '/bootstrap.php';

function wp_load_translations_early() {}
function is_multisite() {
	return false;
}
function __( $text ) {
	return $text;
}
function _doing_it_wrong() {}
function has_filter() {
	return false;
}
function add_filter() {
	return true;
}
function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}
function mbstring_binary_safe_encoding() {}
function reset_mbstring_encoding() {}

if ( ! class_exists( 'WP_Error', false ) ) {
	class WP_Error {}
}

require_once getcwd() . '/../../../wordpress/src/wp-includes/class-wpdb.php';
require_once getcwd() . '/../../plugin-sqlite-database-integration/wp-includes/postgresql/class-wp-postgresql-db.php';

class WP_PostgreSQL_DB_Helper_SQL_Fake_Connection extends WP_PostgreSQL_Connection {
	public function __construct() {}

	public function quote( $value, int $type = PDO::PARAM_STR ): string {
		return "'" . str_replace( "'", "''", (string) $value ) . "'";
	}
}

class WP_PostgreSQL_DB_Helper_SQL_Fake_Driver extends WP_PostgreSQL_Driver {
	private $connection;
	private $queries = array();
	private $last_return_value = 0;

	public function __construct() {
		$this->connection = new WP_PostgreSQL_DB_Helper_SQL_Fake_Connection();
	}

	public function get_connection(): WP_PostgreSQL_Connection {
		return $this->connection;
	}

	public function query( string $query, $fetch_mode = PDO::FETCH_OBJ, ...$fetch_mode_args ) {
		$this->queries[] = $query;
		$this->last_return_value = 1;

		return 1;
	}

	public function get_last_return_value() {
		return $this->last_return_value;
	}

	public function get_insert_id() {
		return 0;
	}

	public function get_last_postgresql_queries(): array {
		return array(
			array(
				'sql'    => end( $this->queries ),
				'params' => array(),
			),
		);
	}

	public function get_recorded_queries(): array {
		return $this->queries;
	}
}

$db = ( new ReflectionClass( WP_PostgreSQL_DB::class ) )->newInstanceWithoutConstructor();

$driver = new WP_PostgreSQL_DB_Helper_SQL_Fake_Driver();
$driver_property = new ReflectionProperty( WP_PostgreSQL_DB::class, 'dbh' );
$driver_property->setAccessible( true );
$driver_property->setValue( $db, $driver );

$db->ready           = true;
$db->is_mysql        = false;
$db->dbname          = 'wptests';
$db->charset         = 'utf8mb4';
$db->suppress_errors = true;

$update_return = $db->update(
	'wptests_options',
	array(
		'option_value' => 'Site Name',
	),
	array(
		'option_name' => 'blogname',
	)
);
$delete_return = $db->delete(
	'wptests_options',
	array(
		'option_name' => 'temporary',
	)
);

wp_postgresql_db_test_respond(
	array(
		'loaded_wpdb'   => class_exists( 'wpdb', false ),
		'update_return' => $update_return,
		'delete_return' => $delete_return,
		'queries'       => $driver->get_recorded_queries(),
	)
);
PHP
		);

		$this->assertTrue( $result['loaded_wpdb'] );
		$this->assertSame( 1, $result['update_return'] );
		$this->assertSame( 1, $result['delete_return'] );
		$this->assertSame(
			array(
				"UPDATE `wptests_options` SET `option_value` = 'Site Name' WHERE `option_name` = 'blogname'",
				"DELETE FROM `wptests_options` WHERE `option_name` = 'temporary'",
			),
			$result['queries']
		);
	}

	/**
	 * Tests real wpdb query rejects empty-WHERE UPDATE statements before driver execution.
	 */
	public function test_real_wpdb_query_rejects_empty_where_update_before_driver(): void {
		$wpdb_file = __DIR__ . '/../../../wordpress/src/wp-includes/class-wpdb.php';
		if ( ! is_readable( $wpdb_file ) ) {
			$this->markTestSkipped( 'Real WordPress wpdb class is not available.' );
		}

		$result = $this->run_isolated_wpdb_script(
			<<<'PHP'
require_once getcwd() . '/bootstrap.php';

function wp_load_translations_early() {}
function is_multisite() {
	return false;
}
function __( $text ) {
	return $text;
}
function _doing_it_wrong() {}
function has_filter() {
	return false;
}
function add_filter() {
	return true;
}
function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}
function mbstring_binary_safe_encoding() {}
function reset_mbstring_encoding() {}

if ( ! class_exists( 'WP_Error', false ) ) {
	class WP_Error {}
}

require_once getcwd() . '/../../../wordpress/src/wp-includes/class-wpdb.php';
require_once getcwd() . '/../../plugin-sqlite-database-integration/wp-includes/postgresql/class-wp-postgresql-db.php';

class WP_PostgreSQL_DB_Empty_Where_Fake_Driver extends WP_PostgreSQL_Driver {
	private $queries = array();

	public function __construct() {}

	public function query( string $query, $fetch_mode = PDO::FETCH_OBJ, ...$fetch_mode_args ) {
		$this->queries[] = $query;

		return 1;
	}

	public function get_recorded_queries(): array {
		return $this->queries;
	}
}

function wp_postgresql_db_test_empty_where_result( string $query ): array {
	$db = ( new ReflectionClass( WP_PostgreSQL_DB::class ) )->newInstanceWithoutConstructor();

	$driver = new WP_PostgreSQL_DB_Empty_Where_Fake_Driver();
	$driver_property = new ReflectionProperty( WP_PostgreSQL_DB::class, 'dbh' );
	if ( PHP_VERSION_ID < 80100 ) {
		$driver_property->setAccessible( true );
	}
	$driver_property->setValue( $db, $driver );

	$db->ready           = true;
	$db->is_mysql        = false;
	$db->dbname          = 'wptests';
	$db->charset         = 'utf8mb4';
	$db->suppress_errors = true;

	$return = $db->query( $query );

	return array(
		'return'        => $return,
		'last_error'    => $db->last_error,
		'queries'       => $driver->get_recorded_queries(),
		'rows_affected' => $db->rows_affected,
		'num_queries'   => $db->num_queries,
	);
}

$queries = array(
	'plain' => "UPDATE `wptests_options` SET `option_value` = 'x' WHERE",
	'block' => "/* plugin preamble */\nUPDATE `wptests_options` SET `option_value` = 'x' WHERE",
	'dash'  => "-- plugin preamble\nUPDATE `wptests_options` SET `option_value` = 'x' WHERE",
	'hash'  => "# plugin preamble\nUPDATE `wptests_options` SET `option_value` = 'x' WHERE",
);

$results = array();
foreach ( $queries as $name => $query ) {
	$results[ $name ] = wp_postgresql_db_test_empty_where_result( $query );
}

wp_postgresql_db_test_respond(
	array(
		'results' => $results,
	)
);
PHP
		);

		foreach ( $result['results'] as $case => $case_result ) {
			$this->assertFalse( $case_result['return'], $case );
			$this->assertSame(
				'PostgreSQL query rejected because UPDATE requires a non-empty WHERE condition.',
				$case_result['last_error'],
				$case
			);
			$this->assertSame( array(), $case_result['queries'], $case );
			$this->assertSame( 0, $case_result['rows_affected'], $case );
			$this->assertSame( 0, $case_result['num_queries'], $case );
		}
	}

	/**
	 * Tests the SQL generated by real wpdb insert helpers before the driver sees it.
	 */
	public function test_real_wpdb_insert_and_replace_helpers_pass_backticked_sql_to_driver(): void {
		$wpdb_file = __DIR__ . '/../../../wordpress/src/wp-includes/class-wpdb.php';
		if ( ! is_readable( $wpdb_file ) ) {
			$this->markTestSkipped( 'Real WordPress wpdb class is not available.' );
		}

		$result = $this->run_isolated_wpdb_script(
			<<<'PHP'
require_once getcwd() . '/bootstrap.php';

function wp_load_translations_early() {}
function is_multisite() {
	return false;
}
function __( $text ) {
	return $text;
}
function _doing_it_wrong() {}
function has_filter() {
	return false;
}
function add_filter() {
	return true;
}
function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}
function mbstring_binary_safe_encoding() {}
function reset_mbstring_encoding() {}

if ( ! class_exists( 'WP_Error', false ) ) {
	class WP_Error {}
}

require_once getcwd() . '/../../../wordpress/src/wp-includes/class-wpdb.php';
require_once getcwd() . '/../../plugin-sqlite-database-integration/wp-includes/postgresql/class-wp-postgresql-db.php';

class WP_PostgreSQL_DB_Insert_SQL_Fake_Connection extends WP_PostgreSQL_Connection {
	public function __construct() {}

	public function quote( $value, int $type = PDO::PARAM_STR ): string {
		return "'" . str_replace( "'", "''", (string) $value ) . "'";
	}
}

class WP_PostgreSQL_DB_Insert_SQL_Fake_Driver extends WP_PostgreSQL_Driver {
	private $connection;
	private $insert_id = 0;
	private $last_return_value = 0;
	private $queries = array();

	public function __construct() {
		$this->connection = new WP_PostgreSQL_DB_Insert_SQL_Fake_Connection();
	}

	public function get_connection(): WP_PostgreSQL_Connection {
		return $this->connection;
	}

	public function query( string $query, $fetch_mode = PDO::FETCH_OBJ, ...$fetch_mode_args ) {
		$this->queries[] = $query;

		if ( 0 === stripos( $query, 'replace' ) ) {
			$this->insert_id         = 22;
			$this->last_return_value = 2;
			return 2;
		}

		$this->insert_id         = 11;
		$this->last_return_value = 1;
		return 1;
	}

	public function get_last_return_value() {
		return $this->last_return_value;
	}

	public function get_insert_id() {
		return $this->insert_id;
	}

	public function get_last_postgresql_queries(): array {
		return array(
			array(
				'sql'    => end( $this->queries ),
				'params' => array(),
			),
		);
	}

	public function get_recorded_queries(): array {
		return $this->queries;
	}
}

$db = ( new ReflectionClass( WP_PostgreSQL_DB::class ) )->newInstanceWithoutConstructor();

$driver = new WP_PostgreSQL_DB_Insert_SQL_Fake_Driver();
$driver_property = new ReflectionProperty( WP_PostgreSQL_DB::class, 'dbh' );
$driver_property->setAccessible( true );
$driver_property->setValue( $db, $driver );

$db->ready           = true;
$db->is_mysql        = false;
$db->dbname          = 'wptests';
$db->charset         = 'utf8mb4';
$db->suppress_errors = true;

$insert_return = $db->insert(
	'wptests_options',
	array(
		'option_name'  => 'blogdescription',
		'option_value' => 'Just another site',
	)
);
$insert_id_after_insert = $db->insert_id;

$replace_return = $db->replace(
	'wptests_options',
	array(
		'option_name'  => 'siteurl',
		'option_value' => 'http://example.org',
	)
);
$insert_id_after_replace = $db->insert_id;

wp_postgresql_db_test_respond(
	array(
		'loaded_wpdb'             => class_exists( 'wpdb', false ),
		'insert_return'           => $insert_return,
		'insert_id_after_insert'  => $insert_id_after_insert,
		'replace_return'          => $replace_return,
		'insert_id_after_replace' => $insert_id_after_replace,
		'queries'                 => $driver->get_recorded_queries(),
	)
);
PHP
		);

		$this->assertTrue( $result['loaded_wpdb'] );
		$this->assertSame( 1, $result['insert_return'] );
		$this->assertSame( 11, $result['insert_id_after_insert'] );
		$this->assertSame( 2, $result['replace_return'] );
		$this->assertSame( 22, $result['insert_id_after_replace'] );
		$this->assertSame(
			array(
				"INSERT INTO `wptests_options` (`option_name`, `option_value`) VALUES ('blogdescription', 'Just another site')",
				"REPLACE INTO `wptests_options` (`option_name`, `option_value`) VALUES ('siteurl', 'http://example.org')",
			),
			$result['queries']
		);
	}

	/**
	 * Tests the SQL sent by real wpdb read helpers before the driver sees it.
	 */
	public function test_real_wpdb_read_helpers_pass_identifier_select_sql_to_driver(): void {
		$wpdb_file = __DIR__ . '/../../../wordpress/src/wp-includes/class-wpdb.php';
		if ( ! is_readable( $wpdb_file ) ) {
			$this->markTestSkipped( 'Real WordPress wpdb class is not available.' );
		}

		$result = $this->run_isolated_wpdb_script(
			<<<'PHP'
require_once getcwd() . '/bootstrap.php';

function wp_load_translations_early() {}
function is_multisite() {
	return false;
}
function __( $text ) {
	return $text;
}
function _doing_it_wrong() {}
function has_filter() {
	return false;
}
function add_filter() {
	return true;
}
function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}
function mbstring_binary_safe_encoding() {}
function reset_mbstring_encoding() {}

if ( ! class_exists( 'WP_Error', false ) ) {
	class WP_Error {}
}

require_once getcwd() . '/../../../wordpress/src/wp-includes/class-wpdb.php';
require_once getcwd() . '/../../plugin-sqlite-database-integration/wp-includes/postgresql/class-wp-postgresql-db.php';

class WP_PostgreSQL_DB_Read_SQL_Fake_Driver extends WP_PostgreSQL_Driver {
	private $queries = array();

	public function __construct() {}

	public function query( string $query, $fetch_mode = PDO::FETCH_OBJ, ...$fetch_mode_args ) {
		$this->queries[] = $query;

		if ( false !== strpos( $query, 'wptests_users' ) ) {
			return array(
				(object) array(
					'ID'         => '1',
					'user_login' => 'admin',
				),
			);
		}

		if ( 0 === strpos( $query, 'SELECT `option_value`' ) ) {
			return array(
				(object) array(
					'option_value' => 'http://example.org',
				),
			);
		}

		if ( 0 === strpos( $query, 'SELECT `option_name` FROM' ) ) {
			return array(
				(object) array(
					'option_name' => 'siteurl',
				),
			);
		}

		return array(
			(object) array(
				'option_name'  => 'siteurl',
				'option_value' => 'http://example.org',
			),
		);
	}

	public function get_last_return_value() {
		return 0;
	}

	public function get_insert_id() {
		return 0;
	}

	public function get_last_postgresql_queries(): array {
		return array(
			array(
				'sql'    => end( $this->queries ),
				'params' => array(),
			),
		);
	}

	public function get_last_column_meta(): array {
		return array();
	}

	public function get_recorded_queries(): array {
		return $this->queries;
	}
}

$db = ( new ReflectionClass( WP_PostgreSQL_DB::class ) )->newInstanceWithoutConstructor();

$driver = new WP_PostgreSQL_DB_Read_SQL_Fake_Driver();
$driver_property = new ReflectionProperty( WP_PostgreSQL_DB::class, 'dbh' );
$driver_property->setAccessible( true );
$driver_property->setValue( $db, $driver );

$check_current_query_property = new ReflectionProperty( 'wpdb', 'check_current_query' );
$check_current_query_property->setAccessible( true );
$check_current_query_property->setValue( $db, false );

$db->ready           = true;
$db->is_mysql        = false;
$db->dbname          = 'wptests';
$db->charset         = 'utf8mb4';
$db->suppress_errors = true;

$option_value = $db->get_var( "SELECT `option_value` FROM `wptests_options` WHERE `option_name` = 'siteurl'" );
$option_row   = $db->get_row( "SELECT `option_name`, `option_value` FROM `wptests_options` WHERE `option_name` = 'siteurl'", ARRAY_A );
$option_rows  = $db->get_results( 'SELECT `option_name` FROM `wptests_options` ORDER BY `option_name`', ARRAY_A );
$user_row     = $db->get_row( 'SELECT ID, user_login FROM wptests_users WHERE ID = 1', ARRAY_A );

wp_postgresql_db_test_respond(
	array(
		'option_value' => $option_value,
		'option_row'   => $option_row,
		'option_rows'  => $option_rows,
		'user_row'     => $user_row,
		'queries'      => $driver->get_recorded_queries(),
	)
);
PHP
		);

		$this->assertSame( 'http://example.org', $result['option_value'] );
		$this->assertSame(
			array(
				'option_name'  => 'siteurl',
				'option_value' => 'http://example.org',
			),
			$result['option_row']
		);
		$this->assertSame(
			array(
				array(
					'option_name' => 'siteurl',
				),
			),
			$result['option_rows']
		);
		$this->assertSame(
			array(
				'ID'         => '1',
				'user_login' => 'admin',
			),
			$result['user_row']
		);
		$this->assertSame(
			array(
				"SELECT `option_value` FROM `wptests_options` WHERE `option_name` = 'siteurl'",
				"SELECT `option_name`, `option_value` FROM `wptests_options` WHERE `option_name` = 'siteurl'",
				'SELECT `option_name` FROM `wptests_options` ORDER BY `option_name`',
				'SELECT ID, user_login FROM wptests_users WHERE ID = 1',
			),
			$result['queries']
		);
	}

	/**
	 * Runs a PostgreSQL wpdb script in a separate PHP process.
	 *
	 * @param string $script Script body without the opening PHP tag.
	 * @return array Decoded JSON response from the script.
	 */
	private function run_isolated_wpdb_script( string $script ): array {
		$script_file = tempnam( sys_get_temp_dir(), 'wp_pg_db_' );
		if ( false === $script_file ) {
			$this->fail( 'Could not create temporary PostgreSQL wpdb test script.' );
		}

		$script_written = file_put_contents(
			$script_file,
			"<?php\n" . $this->get_isolated_script_prelude() . "\n" . $script
		);
		if ( false === $script_written ) {
			unlink( $script_file );
			$this->fail( 'Could not write temporary PostgreSQL wpdb test script.' );
		}

		$descriptor_spec = array(
			0 => array( 'pipe', 'r' ),
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		);
		$process         = proc_open(
			escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $script_file ),
			$descriptor_spec,
			$pipes,
			__DIR__
		);

		if ( ! is_resource( $process ) ) {
			unlink( $script_file );
			$this->fail( 'Could not start isolated PostgreSQL wpdb test process.' );
		}

		fclose( $pipes[0] );
		$stdout = stream_get_contents( $pipes[1] );
		$stderr = stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		$exitcode = proc_close( $process );
		unlink( $script_file );

		$this->assertSame(
			0,
			$exitcode,
			"Isolated PostgreSQL wpdb script failed.\nSTDOUT:\n" . $stdout . "\nSTDERR:\n" . $stderr
		);

		$decoded = json_decode( $stdout, true );
		$this->assertIsArray(
			$decoded,
			"Isolated PostgreSQL wpdb script did not return JSON.\nSTDOUT:\n" . $stdout . "\nSTDERR:\n" . $stderr
		);

		return $decoded;
	}

	/**
	 * Gets helper code prepended to every isolated script.
	 *
	 * @return string PHP script body.
	 */
	private function get_isolated_script_prelude(): string {
		return <<<'PHP'
function wp_postgresql_db_test_respond( array $payload ) {
	echo json_encode( $payload );
}
PHP;
	}
}
