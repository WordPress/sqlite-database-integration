<?php

use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the PostgreSQL wpdb adapter.
 */
class WP_PostgreSQL_DB_Tests extends TestCase {
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
			'SELECT * FROM "wptests_options" WHERE "option_name" = \'Bob\'\'s\'',
			$result['prepared_identifier']
		);
		$this->assertSame( "SELECT 'Bob''s'", $result['prepared_string'] );
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
