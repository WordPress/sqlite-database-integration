<?php declare( strict_types = 1 );

namespace WP_MySQL_Proxy\Adapter;

use Error;
use PDO;
use PDOException;
use PDOStatement;
use WP_MySQL_Proxy\MySQL_Result;
use WP_MySQL_On_SQLite;
use WP_MySQL_Proxy\MySQL_Protocol;

class SQLite_Adapter implements Adapter {
	/** @var WP_MySQL_On_SQLite */
	private $sqlite_driver;

	public function __construct( $sqlite_database_path ) {
		$this->sqlite_driver = new WP_MySQL_On_SQLite(
			sprintf( 'mysql-on-sqlite:path=%s;dbname=sqlite_database', str_replace( ';', ';;', $sqlite_database_path ) )
		);
		$this->sqlite_driver->setAttribute( PDO::ATTR_STRINGIFY_FETCHES, true );
	}

	public function handle_query( string $query ): MySQL_Result {
		$affected_rows  = 0;
		$last_insert_id = 0;
		$columns        = array();
		$rows           = array();

		try {
			$statement      = $this->sqlite_driver->query( $query, PDO::FETCH_OBJ );
			$last_insert_id = (int) $this->sqlite_driver->lastInsertId();
			if ( $statement->columnCount() > 0 ) {
				$rows = $statement->fetchAll();
			} else {
				$affected_rows = $statement->rowCount();
			}
			if ( $statement->columnCount() > 0 ) {
				$columns = $this->computeColumnInfo( $statement );
			}
			return MySQL_Result::from_data( $affected_rows, $last_insert_id, $columns, $rows ?? array() );
		} catch ( PDOException $e ) {
			// Only query errors reach the client. The session logs internal errors instead.
			$error_info = $e->errorInfo; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			if ( ! $error_info || $this->is_internal_error( $e ) ) {
				throw $e;
			}
			return MySQL_Result::from_error( $error_info[0], $error_info[1], $error_info[2] );
		}
	}

	public function computeColumnInfo( PDOStatement $statement ) {
		$columns = array();

		$column_meta = array();
		for ( $i = 0; $i < $statement->columnCount(); $i++ ) {
			$column_meta[] = $statement->getColumnMeta( $i );
		}

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

	/**
	 * Whether a query exception is a PHP error that the driver reported as a query error.
	 *
	 * A PHP error is a bug, not a query error, and its message can contain server
	 * file paths.
	 *
	 * @param  PDOException $e The query exception.
	 * @return bool
	 */
	private function is_internal_error( PDOException $e ): bool {
		for ( $cause = $e->getPrevious(); null !== $cause; $cause = $cause->getPrevious() ) {
			if ( $cause instanceof Error ) {
				return true;
			}
		}
		return false;
	}
}
