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
		'COLLATION',
		'GROUP_CONCAT',
		'SUBQUERIES',
		'IDENTIFIER_PLACEHOLDERS',
		'set_charset',
		'SET_CHARSET',
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

		$this->assertSame(
			array(
				'collation'               => true,
				'group_concat'            => true,
				'subqueries'              => true,
				'identifier_placeholders' => true,
				'COLLATION'               => true,
				'GROUP_CONCAT'            => true,
				'SUBQUERIES'              => true,
				'IDENTIFIER_PLACEHOLDERS' => true,
				'set_charset'             => version_compare( $result['db_version'], '5.0.7', '>=' ),
				'SET_CHARSET'             => version_compare( $result['db_version'], '5.0.7', '>=' ),
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

	if ( 'incompatible_sql_modes' === $hook_name ) {
		$value[] = 'ANSI_QUOTES';
	}

	return $value;
}

class wpdb {
	public $incompatible_modes = array( 'NO_ZERO_DATE' );
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
		'mode_after_filtered_call'  => $mode_after_filtered_call,
		'filter_calls_after_modes'  => $filter_calls_after_modes,
		'mode_after_detached_call'  => $mode_after_detached_call,
	)
);
PHP
		);

		$this->assertSame( 'NO_ENGINE_SUBSTITUTION', $result['initial_mode'] );
		$this->assertSame( 'NO_ENGINE_SUBSTITUTION', $result['mode_after_empty_call'] );
		$this->assertSame( array(), $result['filter_calls_after_empty'] );
		$this->assertSame( 'STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION', $result['mode_after_filtered_call'] );
		$this->assertSame(
			array(
				array(
					'hook_name' => 'incompatible_sql_modes',
					'value'     => array( 'NO_ZERO_DATE' ),
				),
			),
			$result['filter_calls_after_modes']
		);
		$this->assertSame( 'STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION', $result['mode_after_detached_call'] );
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
	 * Tests temp table charset lookups prefer the temp schema over stored permanent metadata.
	 */
	public function test_get_col_charset_prefers_temporary_table_schema_over_stored_permanent_metadata(): void {
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

		if ( false !== strpos( $sql, 'FROM information_schema.tables' ) ) {
			$this->queries[] = 'metadata_exists';
			return $this->statement_from_rows(
				array(
					array(
						'exists' => 1,
					),
				)
			);
		}

		if ( false !== strpos( $sql, WP_PostgreSQL_DB::MYSQL_CHARSET_METADATA_TABLE ) ) {
			$this->queries[] = 'stored_charset_metadata';
			return $this->statement_from_rows(
				array(
					array(
						'column_name'    => 'permanent_value',
						'column_type'    => 'text',
						'collation_name' => 'latin1_swedish_ci',
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
	 * Tests temp table charset lookups use metadata from the temporary CREATE TABLE query.
	 */
	public function test_get_charset_uses_temporary_create_table_metadata(): void {
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

	public function __construct( WP_PostgreSQL_DB_Temp_Create_Charset_Fake_Connection $connection ) {
		$this->fake_connection = $connection;
	}

	public function get_connection(): WP_PostgreSQL_Connection {
		return $this->fake_connection;
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
				'ALTER TABLE wptests_unrelated ADD COLUMN flag INTEGER',
			),
			$result['driver_queries']
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
					'metadata_exists',
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

		if ( 'SHOW FULL COLUMNS FROM `wptests_comments`' !== $query ) {
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
				'metadata_exists',
				'temp_schema:wptests_native_text',
				'native_columns:wptests_native_text',
			),
			$result['connection_queries']
		);
		$this->assertSame(
			array(
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

		if ( false !== strpos( $sql, 'FROM information_schema.tables' ) ) {
			$this->queries[] = 'metadata_exists';
			return $this->statement_from_rows(
				array(
					array(
						'exists' => 1,
					),
				)
			);
		}

		if ( false !== strpos( $sql, WP_PostgreSQL_DB::MYSQL_CHARSET_METADATA_TABLE ) ) {
			$this->queries[] = 'stored:' . ( $params[0] ?? '' );
			return $this->statement_from_rows(
				array(
					array(
						'column_name'    => 'name',
						'column_type'    => 'varchar(' . $this->metadata_length . ')',
						'collation_name' => 'utf8mb4_unicode_ci',
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

	public function __construct( WP_PostgreSQL_DB_Cached_Metadata_Fake_Connection $connection ) {
		$this->fake_connection = $connection;
	}

	public function get_connection(): WP_PostgreSQL_Connection {
		return $this->fake_connection;
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
		'first'   => $first,
		'second'  => $second,
		'third'   => $third,
		'queries' => $connection->get_queries(),
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
				'metadata_exists',
				'stored:wptests_cache_probe',
				'temp_schema:wptests_cache_probe',
				'stored:wptests_cache_probe',
			),
			$result['queries']
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
				'metadata_exists',
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
				'metadata_exists',
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

	wp_postgresql_db_test_respond(
		array(
			'has_identifier_cap'  => $db->has_cap( 'identifier_placeholders' ),
			'quoted_table'        => $db->quote_identifier( 'wptests_options' ),
			'quoted_weird'        => $db->quote_identifier( 'weird"name' ),
			'prepared_identifier' => $db->prepare(
				'SELECT * FROM %i WHERE %i = %s',
				'wptests_options',
				'option_name',
				"Bob's"
			),
			'prepared_string'     => $db->prepare( 'SELECT %s', "Bob's" ),
		)
	);
PHP
		);

		$this->assertTrue( $result['has_identifier_cap'] );
		$this->assertSame( '"wptests_options"', $result['quoted_table'] );
		$this->assertSame( '"weird""name"', $result['quoted_weird'] );
		$this->assertSame(
			'SELECT * FROM `wptests_options` WHERE `option_name` = \'Bob\\\'s\'',
			$result['prepared_identifier']
		);
		$this->assertSame( "SELECT 'Bob\\'s'", $result['prepared_string'] );
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

$return = $db->query( "UPDATE `wptests_options` SET `option_value` = 'x' WHERE" );

wp_postgresql_db_test_respond(
	array(
		'return'         => $return,
		'last_error'     => $db->last_error,
		'queries'        => $driver->get_recorded_queries(),
		'rows_affected'  => $db->rows_affected,
		'num_queries'    => $db->num_queries,
	)
);
PHP
		);

		$this->assertFalse( $result['return'] );
		$this->assertSame(
			'PostgreSQL query rejected because UPDATE requires a non-empty WHERE condition.',
			$result['last_error']
		);
		$this->assertSame( array(), $result['queries'] );
		$this->assertSame( 0, $result['rows_affected'] );
		$this->assertSame( 0, $result['num_queries'] );
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
