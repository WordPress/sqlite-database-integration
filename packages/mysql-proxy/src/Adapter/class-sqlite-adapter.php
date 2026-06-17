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
			$query          = $this->replace_database_alias_in_use_query( $query );
			$return_value   = $this->sqlite_driver->query( $query );
			$last_insert_id = $this->sqlite_driver->get_insert_id() ?? null;
			if ( is_numeric( $return_value ) ) {
				$affected_rows = (int) $return_value;
			} elseif ( is_array( $return_value ) ) {
				$rows = $return_value;
			}
			if ( $this->sqlite_driver->get_last_column_count() > 0 ) {
				$columns = $this->computeColumnInfo();
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
