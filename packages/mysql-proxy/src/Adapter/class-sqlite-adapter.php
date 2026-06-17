<?php declare( strict_types = 1 );

namespace WP_MySQL_Proxy\Adapter;

use PDOException;
use Throwable;
use WP_MySQL_Proxy\MySQL_Result;
use WP_SQLite_Connection;
use WP_SQLite_Driver;
use WP_MySQL_Proxy\MySQL_Protocol;

require_once __DIR__ . '/../../../../wp-pdo-mysql-on-sqlite.php';

class SQLite_Adapter implements Adapter {
	/** @var WP_SQLite_Driver */
	private $sqlite_driver;

	/** @var string */
	private $database_name;

	/** @var array<string, true> */
	private $database_aliases = array();

	public function __construct( $sqlite_database_path, string $database_name = 'sqlite_database', array $database_aliases = array() ) {
		define( 'FQDB', $sqlite_database_path );
		define( 'FQDBDIR', dirname( FQDB ) . '/' );

		$this->database_name = $database_name;

		foreach ( $database_aliases as $database_alias ) {
			$this->database_aliases[ strtolower( $database_alias ) ] = true;
		}

		$this->sqlite_driver = new WP_SQLite_Driver(
			new WP_SQLite_Connection( array( 'path' => $sqlite_database_path ) ),
			$database_name
		);
	}

	public function handle_query( string $query ): MySQL_Result {
		$affected_rows  = 0;
		$last_insert_id = null;
		$columns        = array();
		$rows           = array();

		try {
			$show_variables_like = $this->get_show_variables_like_pattern( $query );
			$query               = $this->replace_database_alias_in_use_query( $query );
			$return_value        = $this->sqlite_driver->query( $query );
			$last_insert_id      = $this->sqlite_driver->get_insert_id() ?? null;
			if ( is_numeric( $return_value ) ) {
				$affected_rows = (int) $return_value;
			} elseif ( is_array( $return_value ) ) {
				$rows = $return_value;
			}
			if ( $this->sqlite_driver->get_last_column_count() > 0 ) {
				$columns = $this->computeColumnInfo();
			}
			if ( false !== $show_variables_like && empty( $rows ) ) {
				return $this->get_show_variables_result( $show_variables_like );
			}
			return MySQL_Result::from_data( $affected_rows, $last_insert_id, $columns, $rows ?? array() );
		} catch ( Throwable $e ) {
			$error_info = $e->errorInfo ?? null; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			if ( $e instanceof PDOException && $error_info ) {
				return MySQL_Result::from_error( $error_info[0], $error_info[1], $error_info[2] );
			}
			return MySQL_Result::from_error( 'HY000', 1105, $e->getMessage() ?? 'Unknown error' );
		}
	}

	private function replace_database_alias_in_use_query( string $query ): string {
		if ( empty( $this->database_aliases ) ) {
			return $query;
		}

		if ( ! preg_match( '/^USE\s+(`([^`]+)`|"([^"]+)"|\\\'([^\\\']+)\\\'|([^\\s;]+))\s*;?\s*$/i', $query, $matches ) ) {
			return $query;
		}

		$database_name = '';
		for ( $i = 2; $i <= 5; $i++ ) {
			if ( isset( $matches[ $i ] ) && '' !== $matches[ $i ] ) {
				$database_name = $matches[ $i ];
				break;
			}
		}
		if ( ! isset( $this->database_aliases[ strtolower( $database_name ) ] ) ) {
			return $query;
		}

		return 'USE `' . str_replace( '`', '``', $this->database_name ) . '`';
	}

	/**
	 * Extract a LIKE pattern from SHOW VARIABLES queries.
	 *
	 * @param  string $query The query to inspect.
	 * @return string|null|false LIKE pattern, null for all variables, or false for non-matching queries.
	 */
	private function get_show_variables_like_pattern( string $query ) {
		if ( ! preg_match( '/^\s*SHOW\s+(?:(?:GLOBAL|SESSION|LOCAL)\s+)?VARIABLES(?:\s+LIKE\s+((?:\'(?:\'\'|[^\'])*\'|"(?:""|[^"])*"|[^\s;]+)))?\s*;?\s*$/i', $query, $matches ) ) {
			return false;
		}

		if ( empty( $matches[1] ) ) {
			return null;
		}

		$pattern = $matches[1];
		$quote   = $pattern[0];
		if ( ( "'" === $quote || '"' === $quote ) && substr( $pattern, -1 ) === $quote ) {
			$pattern = substr( $pattern, 1, -1 );
			$pattern = str_replace( $quote . $quote, $quote, $pattern );
		}

		return $pattern;
	}

	/**
	 * Build a fallback SHOW VARIABLES result for clients that need server metadata.
	 *
	 * @param  string|null $like_pattern Optional SHOW VARIABLES LIKE pattern.
	 * @return MySQL_Result
	 */
	private function get_show_variables_result( $like_pattern ): MySQL_Result {
		$rows = array();

		foreach ( $this->get_proxy_server_variables() as $name => $value ) {
			if ( null !== $like_pattern && ! $this->mysql_like_matches( $name, $like_pattern ) ) {
				continue;
			}

			$rows[] = (object) array(
				'Variable_name' => $name,
				'Value'         => $value,
			);
		}

		return MySQL_Result::from_data( 0, 0, $this->get_show_variables_columns(), $rows );
	}

	/**
	 * Return the minimal server variables needed by MySQL clients and MTR startup probes.
	 *
	 * @return array<string, string>
	 */
	private function get_proxy_server_variables(): array {
		$variables = array(
			'autocommit'                 => 'ON',
			'basedir'                    => '',
			'binlog_format'              => 'ROW',
			'character_set_client'       => 'utf8mb4',
			'character_set_connection'   => 'utf8mb4',
			'character_set_results'      => 'utf8mb4',
			'character_set_server'       => 'utf8mb4',
			'collation_connection'       => 'utf8mb4_0900_ai_ci',
			'collation_server'           => 'utf8mb4_0900_ai_ci',
			'datadir'                    => '',
			'default_storage_engine'     => 'InnoDB',
			'default_tmp_storage_engine' => 'InnoDB',
			'gtid_mode'                  => 'OFF',
			'have_ssl'                   => 'NO',
			'have_symlink'               => 'YES',
			'hostname'                   => 'localhost',
			'innodb_page_size'           => '16384',
			'log_bin'                    => 'OFF',
			'lower_case_file_system'     => 'OFF',
			'lower_case_table_names'     => '0',
			'max_allowed_packet'         => '67108864',
			'max_connections'            => '151',
			'performance_schema'         => 'OFF',
			'port'                       => '0',
			'protocol_version'           => '10',
			'read_only'                  => 'OFF',
			'secure_file_priv'           => '',
			'server_id'                  => '1',
			'skip_name_resolve'          => 'OFF',
			'skip_networking'            => 'OFF',
			'sql_mode'                   => '',
			'storage_engine'             => 'InnoDB',
			'super_read_only'            => 'OFF',
			'system_time_zone'           => 'UTC',
			'time_zone'                  => 'SYSTEM',
			'tmpdir'                     => sys_get_temp_dir(),
			'version'                    => '8.0.46',
			'version_comment'            => 'WordPress SQLite Database Integration MySQL proxy',
			'version_compile_machine'    => 'x86_64',
			'version_compile_os'         => PHP_OS_FAMILY,
		);

		ksort( $variables );
		return $variables;
	}

	/**
	 * Return SHOW VARIABLES column definitions.
	 *
	 * @return array<int, array<string, int|string>>
	 */
	private function get_show_variables_columns(): array {
		return array(
			array(
				'name'     => 'Variable_name',
				'length'   => 64,
				'type'     => MySQL_Protocol::FIELD_TYPE_VAR_STRING,
				'flags'    => 0,
				'decimals' => 0,
			),
			array(
				'name'     => 'Value',
				'length'   => 1024,
				'type'     => MySQL_Protocol::FIELD_TYPE_VAR_STRING,
				'flags'    => 0,
				'decimals' => 0,
			),
		);
	}

	/**
	 * Match MySQL SHOW VARIABLES LIKE patterns.
	 *
	 * @param  string $value   Value to compare.
	 * @param  string $pattern MySQL LIKE pattern.
	 * @return bool
	 */
	private function mysql_like_matches( string $value, string $pattern ): bool {
		$regex  = '';
		$length = strlen( $pattern );
		for ( $i = 0; $i < $length; $i++ ) {
			$char = $pattern[ $i ];
			if ( '%' === $char ) {
				$regex .= '.*';
			} elseif ( '_' === $char ) {
				$regex .= '.';
			} else {
				$regex .= preg_quote( $char, '/' );
			}
		}

		return 1 === preg_match( '/^' . $regex . '$/i', $value );
	}

	public function computeColumnInfo() {
		$columns = array();

		$column_meta = $this->sqlite_driver->get_last_column_meta();

		$types = array(
			'DECIMAL'     => MySQL_Protocol::FIELD_TYPE_DECIMAL,
			'TINY'        => MySQL_Protocol::FIELD_TYPE_TINY,
			'SHORT'       => MySQL_Protocol::FIELD_TYPE_SHORT,
			'LONG'        => MySQL_Protocol::FIELD_TYPE_LONG,
			'FLOAT'       => MySQL_Protocol::FIELD_TYPE_FLOAT,
			'DOUBLE'      => MySQL_Protocol::FIELD_TYPE_DOUBLE,
			'NULL'        => MySQL_Protocol::FIELD_TYPE_NULL,
			'TIMESTAMP'   => MySQL_Protocol::FIELD_TYPE_TIMESTAMP,
			'LONGLONG'    => MySQL_Protocol::FIELD_TYPE_LONGLONG,
			'INT24'       => MySQL_Protocol::FIELD_TYPE_INT24,
			'DATE'        => MySQL_Protocol::FIELD_TYPE_DATE,
			'TIME'        => MySQL_Protocol::FIELD_TYPE_TIME,
			'DATETIME'    => MySQL_Protocol::FIELD_TYPE_DATETIME,
			'YEAR'        => MySQL_Protocol::FIELD_TYPE_YEAR,
			'NEWDATE'     => MySQL_Protocol::FIELD_TYPE_NEWDATE,
			'VARCHAR'     => MySQL_Protocol::FIELD_TYPE_VARCHAR,
			'BIT'         => MySQL_Protocol::FIELD_TYPE_BIT,
			'NEWDECIMAL'  => MySQL_Protocol::FIELD_TYPE_NEWDECIMAL,
			'ENUM'        => MySQL_Protocol::FIELD_TYPE_ENUM,
			'SET'         => MySQL_Protocol::FIELD_TYPE_SET,
			'TINY_BLOB'   => MySQL_Protocol::FIELD_TYPE_TINY_BLOB,
			'MEDIUM_BLOB' => MySQL_Protocol::FIELD_TYPE_MEDIUM_BLOB,
			'LONG_BLOB'   => MySQL_Protocol::FIELD_TYPE_LONG_BLOB,
			'BLOB'        => MySQL_Protocol::FIELD_TYPE_BLOB,
			'VAR_STRING'  => MySQL_Protocol::FIELD_TYPE_VAR_STRING,
			'STRING'      => MySQL_Protocol::FIELD_TYPE_STRING,
			'GEOMETRY'    => MySQL_Protocol::FIELD_TYPE_GEOMETRY,
		);

		foreach ( $column_meta as $column ) {
			$type = $types[ $column['native_type'] ] ?? null;
			if ( null === $type ) {
				throw new Exception( 'Unknown column type: ' . $column['native_type'] );
			}
			$columns[] = array(
				'name'     => $column['name'],
				'length'   => $column['len'],
				'type'     => $type,
				'flags'    => 129,
				'decimals' => $column['precision'],
			);
		}
		return $columns;
	}
}
