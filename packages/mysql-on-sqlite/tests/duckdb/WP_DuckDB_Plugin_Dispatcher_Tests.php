<?php

/**
 * @group duckdb-plugin
 */
class WP_DuckDB_Plugin_Dispatcher_Tests extends PHPUnit\Framework\TestCase {
	/**
	 * Temporary directories created by the current test.
	 *
	 * @var string[]
	 */
	private $temp_dirs = array();

	protected function tearDown(): void {
		foreach ( array_reverse( $this->temp_dirs ) as $temp_dir ) {
			$this->remove_temp_dir( $temp_dir );
		}
		$this->temp_dirs = array();

		parent::tearDown();
	}

	public function test_dispatcher_leaves_mysql_engine_to_wordpress(): void {
		$result = $this->run_dispatcher_script( "'mysql'" );

		$this->assertFalse( $result['died'] );
		$this->assertSame( 'mysql', $result['db_engine'] );
		$this->assertNull( $result['wpdb_class'] );
		$this->assertFalse( $result['sqlite_loaded'] );
		$this->assertFalse( $result['duckdb_loaded'] );
	}

	public function test_dispatcher_loads_sqlite_adapter_for_sqlite_engine(): void {
		$result = $this->run_dispatcher_script( "'sqlite'" );

		$this->assertFalse( $result['died'] );
		$this->assertSame( 'sqlite', $result['db_engine'] );
		$this->assertSame( 'WP_SQLite_DB', $result['wpdb_class'] );
		$this->assertTrue( $result['sqlite_loaded'] );
		$this->assertFalse( $result['duckdb_loaded'] );
	}

	public function test_dispatcher_reports_missing_duckdb_runtime(): void {
		if ( null === WP_DuckDB_Runtime::get_unavailable_reason() ) {
			$this->markTestSkipped( 'DuckDB runtime is available in this environment.' );
		}

		$result = $this->run_dispatcher_script( "'duckdb'" );

		$this->assertTrue( $result['died'] );
		$this->assertSame( 'duckdb', $result['db_engine'] );
		$this->assertSame( 'duckdb_runtime_unavailable', $result['wp_die_code'] );
		$this->assertSame( 'DuckDB runtime is unavailable.', $result['wp_die_title'] );
		$this->assertFalse( $result['sqlite_loaded'] );
	}

	public function test_db_copy_defaults_to_sqlite(): void {
		$result = $this->run_dropin_copy_script( 'db.copy' );

		$this->assertFalse( $result['died'] );
		$this->assertSame( 'sqlite', $result['db_engine'] );
		$this->assertSame( 'sqlite', $result['database_type'] );
		$this->assertSame( '1.8.0', $result['sqlite_dropin_version'] );
		$this->assertNull( $result['duckdb_dropin_version'] );
		$this->assertSame( 'WP_SQLite_DB', $result['wpdb_class'] );
	}

	public function test_db_copy_respects_explicit_duckdb_override(): void {
		$result = $this->run_dropin_copy_script( 'db.copy', "define( 'DB_ENGINE', 'duckdb' );" );

		$this->assertSame( 'duckdb', $result['db_engine'] );
		$this->assertSame( 'duckdb', $result['database_type'] );
		$this->assertSame( '1.8.0', $result['sqlite_dropin_version'] );

		if ( null !== WP_DuckDB_Runtime::get_unavailable_reason() ) {
			$this->assertTrue( $result['died'] );
			$this->assertSame( 'duckdb_runtime_unavailable', $result['wp_die_code'] );
			return;
		}

		$this->assertFalse( $result['died'] );
		$this->assertSame( 'WP_DuckDB_DB', $result['wpdb_class'] );
	}

	public function test_duckdb_copy_defaults_to_duckdb(): void {
		$result = $this->run_dropin_copy_script( 'db-duckdb.copy' );

		$this->assertSame( 'duckdb', $result['db_engine'] );
		$this->assertSame( 'duckdb', $result['database_type'] );
		$this->assertSame( '0.1.0', $result['duckdb_dropin_version'] );

		if ( null !== WP_DuckDB_Runtime::get_unavailable_reason() ) {
			$this->assertTrue( $result['died'] );
			$this->assertSame( 'duckdb_runtime_unavailable', $result['wp_die_code'] );
			return;
		}

		$this->assertFalse( $result['died'] );
		$this->assertSame( 'WP_DuckDB_DB', $result['wpdb_class'] );
	}

	public function test_duckdb_wpdb_query_updates_raw_query_state(): void {
		$result = $this->run_raw_query_state_script();

		$this->assertTrue( $result['connected'] );
		$this->assertSame( 1, $result['select_return'] );
		$this->assertSame( 'SELECT 42 AS answer', $result['select_last_query'] );
		$this->assertSame( 1, $result['select_num_rows'] );
		$this->assertSame( 42, $result['select_answer'] );
		$this->assertSame( 2, $result['insert_return'] );
		$this->assertSame( 2, $result['insert_rows_affected'] );
		$this->assertTrue( $result['create_return'] );
		$this->assertSame( 3, $result['num_queries'] );
		$user_client_queries = array_values(
			array_filter(
				$result['client_queries'],
				function ( string $sql ): bool {
					return false === strpos( $sql, '__wp_duckdb_' )
						&& false === strpos( $sql, 'information_schema.tables' )
						&& false === strpos( $sql, 'currval(' );
				}
			)
		);
		$this->assertSame(
			array(
				'CREATE OR REPLACE MACRO date_format(d, f) AS strftime(d, f)',
				'SELECT 42 AS answer',
				'INSERT INTO t VALUES (1), (2)',
				'CREATE TABLE "t" ("id" INTEGER)',
			),
			array_slice( $user_client_queries, 0, 4 )
		);
	}

	public function test_duckdb_wpdb_insert_id_tracks_driver_insert_id(): void {
		$result = $this->run_insert_id_state_script();

		$this->assertTrue( $result['connected'] );
		$this->assertSame( 1, $result['insert_return'] );
		$this->assertSame( 101, $result['insert_id_after_insert'] );
		$this->assertSame( 1, $result['replace_return'] );
		$this->assertSame( 102, $result['insert_id_after_replace'] );
		$this->assertFalse( $result['failed_insert_return'] );
		$this->assertSame( 0, $result['insert_id_after_failed_insert'] );
	}

	public function test_duckdb_wpdb_flush_clears_statement_metadata(): void {
		$result = $this->run_flush_state_script();

		$this->assertTrue( $result['connected'] );
		$this->assertSame( 1, $result['select_return'] );
		$this->assertSame( array( 'answer' ), $result['before_flush_column_names'] );
		$this->assertSame( array(), $result['last_result_after_flush'] );
		$this->assertNull( $result['col_info_after_flush'] );
		$this->assertNull( $result['last_query_after_flush'] );
		$this->assertSame( 0, $result['rows_affected_after_flush'] );
		$this->assertSame( 0, $result['num_rows_after_flush'] );
		$this->assertSame( '', $result['last_error_after_flush'] );
		$this->assertNull( $result['result_after_flush'] );
		$this->assertSame( array(), $result['after_flush_column_names'] );
	}

	private function run_dispatcher_script( string $engine_expression ): array {
		$plugin_dir = $this->get_plugin_dir();
		$code       = $this->get_wordpress_stub_code();
		$code      .= "\ndefine( 'DB_ENGINE', {$engine_expression} );\n";
		$code      .= $this->get_dispatcher_include_code( $plugin_dir . '/wp-includes/db.php' );

		return $this->run_isolated_php( $code );
	}

	private function run_dropin_copy_script( string $copy_file, string $prepend_code = '' ): array {
		$plugin_dir    = $this->get_plugin_dir();
		$dropin_source = file_get_contents( $plugin_dir . '/' . $copy_file );
		$dropin_source = str_replace(
			array(
				'{SQLITE_IMPLEMENTATION_FOLDER_PATH}',
				'{SQLITE_PLUGIN}',
			),
			array(
				$plugin_dir,
				'sqlite-database-integration/load.php',
			),
			$dropin_source
		);
		$dropin_file   = $this->create_temp_file( $dropin_source );
		$code          = $this->get_wordpress_stub_code();
		$code         .= "\n{$prepend_code}\n";
		$code         .= $this->get_dispatcher_include_code( $dropin_file );

		try {
			return $this->run_isolated_php( $code );
		} finally {
			if ( is_file( $dropin_file ) ) {
				unlink( $dropin_file );
			}
		}
	}

	private function run_raw_query_state_script(): array {
		$plugin_dir  = $this->get_plugin_dir();
		$driver_load = dirname( __DIR__, 2 ) . '/src/load.php';
		$code        = $this->get_wordpress_stub_code();
		$code       .= "\nrequire_once " . var_export( $driver_load, true ) . ";\n";
		$code       .= 'require_once ' . var_export( $plugin_dir . '/wp-includes/duckdb/class-wp-duckdb-db.php', true ) . ";\n";
		$code       .= <<<'PHP'

class WP_DuckDB_Plugin_Test_Result {
	private $columns;
	private $rows;

	public function __construct( array $columns, array $rows ) {
		$this->columns = $columns;
		$this->rows    = $rows;
	}

	public function columnNames() {
		return new ArrayIterator( $this->columns );
	}

	public function rows( $assoc = false ) {
		return new ArrayIterator( $this->rows );
	}
}

class WP_DuckDB_Plugin_Test_Client {
	public $queries = array();

	public function query( $sql ) {
		$this->queries[] = $sql;
		$normalized     = strtolower( trim( $sql ) );

		if ( 0 === strpos( $normalized, 'select' ) ) {
			return new WP_DuckDB_Plugin_Test_Result(
				array( 'answer' ),
				array(
					array( 'answer' => 42 ),
				)
			);
		}

		if ( 0 === strpos( $normalized, 'insert' ) ) {
			return new WP_DuckDB_Plugin_Test_Result(
				array( 'Count' ),
				array(
					array( 'Count' => 2 ),
				)
			);
		}

		if ( 0 === strpos( $normalized, 'delete' ) ) {
			return new WP_DuckDB_Plugin_Test_Result(
				array( 'Count' ),
				array(
					array( 'Count' => 0 ),
				)
			);
		}

		if ( 0 === strpos( $normalized, 'create' ) ) {
			return new WP_DuckDB_Plugin_Test_Result(
				array( 'Success' ),
				array(
					array( 'Success' => true ),
				)
			);
		}

		throw new RuntimeException( 'Unexpected query: ' . $sql );
	}
}

$client             = new WP_DuckDB_Plugin_Test_Client();
$GLOBALS['@duckdb'] = new WP_DuckDB_Connection( array( 'duckdb' => $client ) );
$db                 = new WP_DuckDB_DB( 'wordpress_test' );
$connected          = $db->db_connect( false );
$select_return      = $db->query( 'SELECT 42 AS answer' );
$select_state       = array(
	'last_query' => $db->last_query,
	'num_rows'   => $db->num_rows,
	'answer'     => $db->last_result[0]->answer,
);
$insert_return      = $db->query( 'INSERT INTO t VALUES (1), (2)' );
$insert_state       = array(
	'rows_affected' => $db->rows_affected,
);
$create_return      = $db->query( 'CREATE TABLE t (id INTEGER)' );

echo json_encode(
	array(
		'connected'            => $connected,
		'select_return'        => $select_return,
		'select_last_query'    => $select_state['last_query'],
		'select_num_rows'      => $select_state['num_rows'],
		'select_answer'        => $select_state['answer'],
		'insert_return'        => $insert_return,
		'insert_rows_affected' => $insert_state['rows_affected'],
		'create_return'        => $create_return,
		'num_queries'          => $db->num_queries,
		'client_queries'       => $client->queries,
	)
);
PHP;

		return $this->run_isolated_php( $code );
	}

	private function run_insert_id_state_script(): array {
		$plugin_dir  = $this->get_plugin_dir();
		$driver_load = dirname( __DIR__, 2 ) . '/src/load.php';
		$code        = $this->get_wordpress_stub_code();
		$code       .= "\nrequire_once " . var_export( $driver_load, true ) . ";\n";
		$code       .= 'require_once ' . var_export( $plugin_dir . '/wp-includes/duckdb/class-wp-duckdb-db.php', true ) . ";\n";
		$code       .= <<<'PHP'

class WP_DuckDB_Plugin_Insert_Id_Test_Driver extends WP_DuckDB_Driver {
	private $insert_id = 0;

	public function __construct() {}

	public function query( string $sql ): WP_DuckDB_Result_Statement {
		if ( false !== strpos( $sql, 'BROKEN' ) ) {
			throw new RuntimeException( 'Synthetic insert failure.' );
		}

		if ( 0 === stripos( trim( $sql ), 'replace' ) ) {
			$this->insert_id = 102;
			return new WP_DuckDB_Result_Statement( array(), array(), 1 );
		}

		if ( 0 === stripos( trim( $sql ), 'insert' ) ) {
			$this->insert_id = 101;
			return new WP_DuckDB_Result_Statement( array(), array(), 1 );
		}

		return new WP_DuckDB_Result_Statement( array(), array(), 0 );
	}

	public function get_insert_id(): int {
		return $this->insert_id;
	}
}

$GLOBALS['@duckdb_driver'] = new WP_DuckDB_Plugin_Insert_Id_Test_Driver();
$db                        = new WP_DuckDB_DB( 'wordpress_test' );
$connected                 = $db->db_connect( false );
$insert_return             = $db->query( "INSERT INTO t (name) VALUES ('first')" );
$insert_id                 = $db->insert_id;
$replace_return            = $db->query( "REPLACE INTO t (name) VALUES ('second')" );
$replace_insert_id         = $db->insert_id;
$failed_insert             = $db->query( 'INSERT INTO t VALUES (BROKEN)' );
$failed_insert_id          = $db->insert_id;

echo json_encode(
	array(
		'connected'                     => $connected,
		'insert_return'                 => $insert_return,
		'insert_id_after_insert'        => $insert_id,
		'replace_return'                => $replace_return,
		'insert_id_after_replace'       => $replace_insert_id,
		'failed_insert_return'          => $failed_insert,
		'insert_id_after_failed_insert' => $failed_insert_id,
	)
);
PHP;

		return $this->run_isolated_php( $code );
	}

	private function run_flush_state_script(): array {
		$plugin_dir  = $this->get_plugin_dir();
		$driver_load = dirname( __DIR__, 2 ) . '/src/load.php';
		$code        = $this->get_wordpress_stub_code();
		$code       .= "\nrequire_once " . var_export( $driver_load, true ) . ";\n";
		$code       .= 'require_once ' . var_export( $plugin_dir . '/wp-includes/duckdb/class-wp-duckdb-db.php', true ) . ";\n";
		$code       .= <<<'PHP'

class WP_DuckDB_Plugin_Flush_Test_Result {
	private $columns;
	private $rows;

	public function __construct( array $columns, array $rows ) {
		$this->columns = $columns;
		$this->rows    = $rows;
	}

	public function columnNames() {
		return new ArrayIterator( $this->columns );
	}

	public function rows( $assoc = false ) {
		return new ArrayIterator( $this->rows );
	}
}

class WP_DuckDB_Plugin_Flush_Test_Client {
	public function query( $sql ) {
		$normalized = strtolower( trim( $sql ) );

		if ( 0 === strpos( $normalized, 'select' ) ) {
			return new WP_DuckDB_Plugin_Flush_Test_Result(
				array( 'answer' ),
				array(
					array( 'answer' => 42 ),
				)
			);
		}

		if ( 0 === strpos( $normalized, 'create' ) ) {
			return new WP_DuckDB_Plugin_Flush_Test_Result(
				array( 'Success' ),
				array(
					array( 'Success' => true ),
				)
			);
		}

		throw new RuntimeException( 'Unexpected query: ' . $sql );
	}
}

class WP_DuckDB_Plugin_Flush_Test_DB extends WP_DuckDB_DB {
	public function exported_column_names() {
		$this->load_col_info();
		return array_map(
			function ( $column ) {
				return $column->name;
			},
			$this->col_info
		);
	}
}

$GLOBALS['@duckdb'] = new WP_DuckDB_Connection( array( 'duckdb' => new WP_DuckDB_Plugin_Flush_Test_Client() ) );
$db                 = new WP_DuckDB_Plugin_Flush_Test_DB( 'wordpress_test' );
$connected          = $db->db_connect( false );
$select_return      = $db->query( 'SELECT 42 AS answer' );
$before_columns     = $db->exported_column_names();

$db->flush();

$state_after_flush = array(
	'last_result'   => $db->last_result,
	'col_info'      => $db->col_info,
	'last_query'    => $db->last_query,
	'rows_affected' => $db->rows_affected,
	'num_rows'      => $db->num_rows,
	'last_error'    => $db->last_error,
	'result'        => $db->result,
);
$after_columns     = $db->exported_column_names();

echo json_encode(
	array(
		'connected'                  => $connected,
		'select_return'              => $select_return,
		'before_flush_column_names'  => $before_columns,
		'last_result_after_flush'    => $state_after_flush['last_result'],
		'col_info_after_flush'       => $state_after_flush['col_info'],
		'last_query_after_flush'     => $state_after_flush['last_query'],
		'rows_affected_after_flush'  => $state_after_flush['rows_affected'],
		'num_rows_after_flush'       => $state_after_flush['num_rows'],
		'last_error_after_flush'     => $state_after_flush['last_error'],
		'result_after_flush'         => $state_after_flush['result'],
		'after_flush_column_names'   => $after_columns,
	)
);
PHP;

		return $this->run_isolated_php( $code );
	}

	private function get_dispatcher_include_code( string $include_file ): string {
		return "\ntry {\n"
			. "\trequire " . var_export( $include_file, true ) . ";\n"
			. "\techo json_encode( wp_duckdb_plugin_test_state( false ) );\n"
			. "} catch ( RuntimeException \$e ) {\n"
			. "\techo json_encode( wp_duckdb_plugin_test_state( true ) );\n"
			. "}\n";
	}

	private function get_wordpress_stub_code(): string {
		$temp_dir = $this->create_temp_dir();

		return "<?php\n"
			. "define( 'ABSPATH', " . var_export( $temp_dir . '/', true ) . " );\n"
			. "define( 'WP_CONTENT_DIR', " . var_export( $temp_dir . '/wp-content', true ) . " );\n"
			. "define( 'DB_NAME', 'wordpress_test' );\n"
			. "define( 'FQDBDIR', " . var_export( $temp_dir . '/database/', true ) . " );\n"
			. "define( 'FQDB', FQDBDIR . '.ht.sqlite' );\n"
			. "define( 'FQDUCKDB', FQDBDIR . '.ht.duckdb' );\n"
			. <<<'PHP'

class WP_Error {
	private $code;
	private $message;

	public function __construct( $code = '', $message = '' ) {
		$this->code    = $code;
		$this->message = $message;
	}

	public function get_error_code() {
		return $this->code;
	}

	public function get_error_message() {
		return $this->message;
	}
}

class wpdb {
	public $charset;
	public $col_info = null;
	public $dbname = '';
	public $func_call = '';
	public $insert_id = 0;
	public $last_error = '';
	public $last_query = null;
	public $last_result = array();
	public $num_queries = 0;
	public $num_rows = 0;
	public $options = null;
	public $prefix = '';
	public $ready = false;
	public $result = null;
	public $rows_affected = 0;
	public $show_errors = false;
	public $suppress_errors = false;
	public $time_start = 0;

	public function __construct( $dbuser = '', $dbpassword = '', $dbname = '', $dbhost = '' ) {
		$this->dbname = $dbname;
	}

	public function add_placeholder_escape( $query ) {
		return $query;
	}

	public function bail( $message, $error_code = '500' ) {
		wp_die( $message, $error_code );
	}

	public function flush() {
		$this->last_result   = array();
		$this->col_info      = null;
		$this->last_query    = null;
		$this->rows_affected = 0;
		$this->num_rows      = 0;
		$this->last_error    = '';
		$this->result        = null;
	}

	public function get_caller() {
		return '';
	}

	public function get_row( $query ) {
		return null;
	}

	public function hide_errors() {
		$show_errors       = $this->show_errors;
		$this->show_errors = false;
		return $show_errors;
	}

	public function log_query( $query, $query_time, $query_callstack, $query_start, $query_data ) {
	}

	public function prepare( $query, ...$args ) {
		return $query;
	}

	public function print_error( $str = '' ) {
		$this->last_error = $str ? $str : $this->last_error;
	}

	public function set_prefix( $prefix ) {
		$this->prefix  = $prefix;
		$this->options = $prefix . 'options';
	}

	public function show_errors( $show = true ) {
		$old_show_errors   = $this->show_errors;
		$this->show_errors = $show;
		return $old_show_errors;
	}

	public function timer_start() {
		$this->time_start = microtime( true );
		return true;
	}

	public function timer_stop() {
		return microtime( true ) - $this->time_start;
	}
}

function __( $text ) {
	return $text;
}

function add_action( $hook_name, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['wp_duckdb_plugin_test_actions'][] = array( $hook_name, $priority, $accepted_args );
	return true;
}

function apply_filters( $hook_name, $value ) {
	return $value;
}

function is_admin() {
	return false;
}

function is_multisite() {
	return false;
}

function trailingslashit( $path ) {
	return rtrim( $path, '/\\' ) . '/';
}

function wp_die( $message = '', $title = '', $args = array() ) {
	if ( $message instanceof WP_Error ) {
		$GLOBALS['wp_duckdb_plugin_test_die'] = array(
			'code'    => $message->get_error_code(),
			'message' => $message->get_error_message(),
			'title'   => $title,
		);
	} else {
		$GLOBALS['wp_duckdb_plugin_test_die'] = array(
			'code'    => null,
			'message' => (string) $message,
			'title'   => $title,
		);
	}

	throw new RuntimeException( 'wp_die' );
}

function wp_duckdb_plugin_test_state( $died ) {
	return array(
		'died'                   => $died,
		'db_engine'              => defined( 'DB_ENGINE' ) ? DB_ENGINE : null,
		'database_type'          => defined( 'DATABASE_TYPE' ) ? DATABASE_TYPE : null,
		'sqlite_dropin_version'  => defined( 'SQLITE_DB_DROPIN_VERSION' ) ? SQLITE_DB_DROPIN_VERSION : null,
		'duckdb_dropin_version'  => defined( 'DUCKDB_DB_DROPIN_VERSION' ) ? DUCKDB_DB_DROPIN_VERSION : null,
		'wpdb_class'             => isset( $GLOBALS['wpdb'] ) && is_object( $GLOBALS['wpdb'] ) ? get_class( $GLOBALS['wpdb'] ) : null,
		'sqlite_loaded'          => class_exists( 'WP_SQLite_DB', false ),
		'duckdb_loaded'          => class_exists( 'WP_DuckDB_DB', false ),
		'wp_die_code'            => isset( $GLOBALS['wp_duckdb_plugin_test_die']['code'] ) ? $GLOBALS['wp_duckdb_plugin_test_die']['code'] : null,
		'wp_die_message'         => isset( $GLOBALS['wp_duckdb_plugin_test_die']['message'] ) ? $GLOBALS['wp_duckdb_plugin_test_die']['message'] : null,
		'wp_die_title'           => isset( $GLOBALS['wp_duckdb_plugin_test_die']['title'] ) ? $GLOBALS['wp_duckdb_plugin_test_die']['title'] : null,
	);
}
PHP;
	}

	private function run_isolated_php( string $code ): array {
		$script_file = $this->create_temp_file( $code );
		$output      = array();
		$exit_code   = 0;
		$command     = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $script_file ) . ' 2>&1';

		try {
			exec( $command, $output, $exit_code ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
		} finally {
			if ( is_file( $script_file ) ) {
				unlink( $script_file );
			}
		}

		$output = implode( "\n", $output );
		$this->assertSame( 0, $exit_code, $output );

		$result = json_decode( $output, true );
		$this->assertIsArray( $result, $output );

		return $result;
	}

	private function create_temp_file( string $contents ): string {
		$file = tempnam( sys_get_temp_dir(), 'wp_duckdb_plugin_' );
		$this->assertIsString( $file );
		$this->assertNotFalse( file_put_contents( $file, $contents ) );

		return $file;
	}

	private function create_temp_dir(): string {
		$dir = sys_get_temp_dir() . '/wp_duckdb_plugin_' . str_replace( '.', '_', uniqid( '', true ) );
		$this->assertTrue( mkdir( $dir, 0700, true ) );
		$this->temp_dirs[] = $dir;

		return $dir;
	}

	private function remove_temp_dir( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}

		$items = scandir( $dir );
		if ( false === $items ) {
			return;
		}

		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}

			$path = $dir . DIRECTORY_SEPARATOR . $item;
			if ( is_dir( $path ) && ! is_link( $path ) ) {
				$this->remove_temp_dir( $path );
			} elseif ( file_exists( $path ) || is_link( $path ) ) {
				unlink( $path );
			}
		}

		rmdir( $dir );
	}

	private function get_plugin_dir(): string {
		return dirname( __DIR__, 3 ) . '/plugin-sqlite-database-integration';
	}
}
