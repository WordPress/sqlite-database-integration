<?php
/**
 * PostgreSQL wpdb drop-in scaffold.
 *
 * @package wp-sqlite-integration
 */

if ( ! class_exists( 'WP_PostgreSQL_Driver', false ) ) {
	require_once __DIR__ . '/../database/postgresql/class-wp-postgresql-connection.php';
	require_once __DIR__ . '/../database/postgresql/class-wp-postgresql-create-table-translator.php';
	require_once __DIR__ . '/../database/postgresql/class-wp-postgresql-driver.php';
}

/*
 * The PostgreSQL drop-in uses PDO through the backend driver. Enable PDO
 * type checks in this compatibility layer:
 * phpcs:disable WordPress.DB.RestrictedClasses.mysql__PDO
 */

/**
 * PostgreSQL-backed wpdb replacement.
 *
 * The PostgreSQL backend is intentionally routed through its own class instead
 * of reusing the SQLite file-backed connection path.
 */
class WP_PostgreSQL_DB extends wpdb {
	const MYSQL_CHARSET_METADATA_TABLE = '__wp_postgresql_mysql_charset_metadata';

	/**
	 * Database handle.
	 *
	 * @var WP_PostgreSQL_Driver|null
	 */
	protected $dbh;

	/**
	 * MySQL charset metadata for PostgreSQL temporary tables.
	 *
	 * @var array
	 */
	private $postgresql_temporary_charset_metadata = array();

	/**
	 * Request-local MySQL charset metadata keyed by normalized table name.
	 *
	 * @var array
	 */
	private $postgresql_column_charset_metadata_cache = array();

	/**
	 * Request-local column length metadata keyed by normalized table and column names.
	 *
	 * @var array
	 */
	private $postgresql_column_length_cache = array();

	/**
	 * Cached existence state for the PostgreSQL MySQL charset metadata table.
	 *
	 * @var bool|null
	 */
	private $postgresql_charset_metadata_table_exists = null;

	/**
	 * Backward compatibility, see wpdb::$allow_unsafe_unquoted_parameters.
	 *
	 * @var bool
	 */
	private $allow_unsafe_unquoted_parameters = true;

	/**
	 * Constructor.
	 *
	 * @param string $dbuser     Database user.
	 * @param string $dbpassword Database password.
	 * @param string $dbname     Database name.
	 * @param string $dbhost     Database host.
	 */
	public function __construct( $dbuser, $dbpassword, $dbname, $dbhost ) {
		$GLOBALS['wpdb'] = $this;

		parent::__construct( $dbuser, $dbpassword, $dbname, $dbhost );
		if ( ! $this->charset ) {
			$this->charset = 'utf8mb4';
		}
	}

	/**
	 * Method to set character set for the database.
	 *
	 * @param resource $dbh     The database handle.
	 * @param string   $charset Optional. The character set.
	 * @param string   $collate Optional. The collation.
	 */
	public function set_charset( $dbh, $charset = null, $collate = null ) {
		if ( ! isset( $charset ) ) {
			$charset = $this->charset;
		}
		if ( ! isset( $collate ) ) {
			$collate = $this->collate;
		}

		if ( ! $this->has_cap( 'collation' ) || empty( $charset ) ) {
			return;
		}

		if ( $dbh instanceof WP_PostgreSQL_Driver ) {
			$dbh->set_charset( (string) $charset, empty( $collate ) ? null : (string) $collate );
		}
	}

	/**
	 * Method to get the character set for the database.
	 *
	 * @param string $table  The table name.
	 * @param string $column The column name.
	 * @return string The character set.
	 */
	public function get_col_charset( $table, $column ) {
		$tablekey  = $this->get_postgresql_metadata_key( (string) $table );
		$columnkey = $this->get_postgresql_metadata_key( (string) $column );

		if ( function_exists( 'apply_filters' ) ) {
			$charset = apply_filters( 'pre_get_col_charset', null, $table, $column );
			if ( null !== $charset ) {
				return $charset;
			}
		}

		if ( empty( $this->is_mysql ) ) {
			return false;
		}

		if ( ! array_key_exists( $tablekey, $this->table_charset ) ) {
			$table_charset = $this->get_table_charset( $table );
			if ( function_exists( 'is_wp_error' ) && is_wp_error( $table_charset ) ) {
				return $table_charset;
			}
		}

		if ( empty( $this->col_meta[ $tablekey ] ) ) {
			return $this->table_charset[ $tablekey ];
		}

		if ( empty( $this->col_meta[ $tablekey ][ $columnkey ] ) ) {
			return $this->table_charset[ $tablekey ];
		}

		if ( empty( $this->col_meta[ $tablekey ][ $columnkey ]->Collation ) ) {
			return false;
		}

		list( $charset ) = explode( '_', $this->col_meta[ $tablekey ][ $columnkey ]->Collation );
		return $charset;
	}

	/**
	 * Retrieves the MySQL-compatible table charset from PostgreSQL metadata.
	 *
	 * @param string $table Table name.
	 * @return string|false|WP_Error Table charset, false for non-text tables, or an error.
	 */
	protected function get_table_charset( $table ) {
		$tablekey = $this->get_postgresql_metadata_key( (string) $table );

		if ( function_exists( 'apply_filters' ) ) {
			$charset = apply_filters( 'pre_get_table_charset', null, $table );
			if ( null !== $charset ) {
				return $charset;
			}
		}

		if ( array_key_exists( $tablekey, $this->table_charset ) ) {
			return $this->table_charset[ $tablekey ];
		}

		$columns = $this->get_postgresql_column_charset_metadata( (string) $table );
		if ( false === $columns ) {
			return new WP_Error( 'wpdb_get_table_charset_failure', __( 'Could not retrieve table charset.' ) );
		}

		$this->col_meta[ $tablekey ]      = $columns;
		$this->table_charset[ $tablekey ] = $this->get_postgresql_table_charset_from_columns( $columns );

		return $this->table_charset[ $tablekey ];
	}

	/**
	 * Strips invalid text using PostgreSQL-compatible PHP charset handling.
	 *
	 * WordPress core falls back to MySQL CONVERT(... USING ...) calls for
	 * legacy charsets. PostgreSQL does not have MySQL charset names, so mirror
	 * the core pre-truncation and UTF-8 paths, then emulate the conversion step
	 * in PHP for the charsets covered by core's charset tests.
	 *
	 * @param array $data Field data.
	 * @return array|WP_Error Field data with invalid text removed, or error.
	 */
	protected function strip_invalid_text( $data ) {
		foreach ( $data as &$value ) {
			$charset = $value['charset'];

			if ( is_array( $value['length'] ) ) {
				$length                  = $value['length']['length'];
				$truncate_by_byte_length = 'byte' === $value['length']['type'];
			} else {
				$length                  = false;
				$truncate_by_byte_length = false;
			}

			if ( false === $charset || ! is_string( $value['value'] ) ) {
				continue;
			}

			$needs_validation = true;
			if (
				'latin1' === $charset
				|| ( ! isset( $value['ascii'] ) && $this->check_ascii( $value['value'] ) )
			) {
				$truncate_by_byte_length = true;
				$needs_validation        = false;
			}

			if ( $truncate_by_byte_length ) {
				mbstring_binary_safe_encoding();
				if ( false !== $length && strlen( $value['value'] ) > $length ) {
					$value['value'] = substr( $value['value'], 0, $length );
				}
				reset_mbstring_encoding();

				if ( ! $needs_validation ) {
					continue;
				}
			}

			if ( ( 'utf8' === $charset || 'utf8mb3' === $charset || 'utf8mb4' === $charset ) && function_exists( 'mb_strlen' ) ) {
				$value['value'] = $this->strip_postgresql_invalid_utf8_text( $value['value'], $charset, $length );
				continue;
			}

			$stripped = $this->strip_postgresql_invalid_legacy_text( $value['value'], $charset, $value['length'] );
			if ( false === $stripped ) {
				return new WP_Error( 'wpdb_strip_invalid_text_failure', __( 'Could not strip invalid text.' ) );
			}

			$value['value'] = $stripped;
		}
		unset( $value );

		return $data;
	}

	/**
	 * Gets the maximum string length for a PostgreSQL-backed column.
	 *
	 * Core wpdb skips length detection when the connection is not MySQL. The
	 * PostgreSQL schema translator still preserves varchar lengths, so expose
	 * them in the same shape wpdb::strip_invalid_text() expects.
	 *
	 * @param string $table  Table name.
	 * @param string $column Column name.
	 * @return array|false Maximum length data, or false when unrestricted/unknown.
	 */
	public function get_col_length( $table, $column ) {
		if ( ! $this->dbh instanceof WP_PostgreSQL_Driver ) {
			return false;
		}

		$table     = $this->normalize_postgresql_table_name( (string) $table );
		$column    = trim( (string) $column, "`\" \t\n\r\0\x0B" );
		$tablekey  = $this->get_postgresql_metadata_key( (string) $table );
		$columnkey = $this->get_postgresql_metadata_key( (string) $column );

		$columns = array_key_exists( $tablekey, $this->col_meta )
			? $this->col_meta[ $tablekey ]
			: $this->get_postgresql_column_charset_metadata( $table );
		if ( false !== $columns && isset( $columns[ $columnkey ] ) ) {
			$length = $this->get_postgresql_column_length_from_mysql_type(
				(string) $columns[ $columnkey ]->Type
			);
			if ( false !== $length ) {
				return $length;
			}
		}

		if (
			isset( $this->postgresql_column_length_cache[ $tablekey ] )
			&& array_key_exists( $columnkey, $this->postgresql_column_length_cache[ $tablekey ] )
		) {
			return $this->postgresql_column_length_cache[ $tablekey ][ $columnkey ];
		}

		try {
			$stmt = $this->dbh->get_connection()->query(
				'SELECT data_type, character_maximum_length
				FROM information_schema.columns
				WHERE (
						table_schema = (
							SELECT nspname
							FROM pg_catalog.pg_namespace
							WHERE oid = pg_my_temp_schema()
						)
						OR table_schema = current_schema()
					)
					AND table_name = ?
					AND column_name = ?
				ORDER BY CASE
					WHEN table_schema = (
						SELECT nspname
						FROM pg_catalog.pg_namespace
						WHERE oid = pg_my_temp_schema()
					) THEN 0
					ELSE 1
				END
				LIMIT 1',
				array( $table, $column )
			);
			$row  = $stmt->fetch( PDO::FETCH_ASSOC );
		} catch ( Throwable $e ) {
			return false;
		}

		if ( ! is_array( $row ) ) {
			$this->postgresql_column_length_cache[ $tablekey ][ $columnkey ] = false;
			return false;
		}

		$type   = strtolower( (string) ( $row['data_type'] ?? '' ) );
		$length = isset( $row['character_maximum_length'] ) ? (int) $row['character_maximum_length'] : 0;

		if ( in_array( $type, array( 'character varying', 'character', 'varchar', 'char' ), true ) && $length > 0 ) {
			$this->postgresql_column_length_cache[ $tablekey ][ $columnkey ] = array(
				'type'   => 'char',
				'length' => $length,
			);
			return $this->postgresql_column_length_cache[ $tablekey ][ $columnkey ];
		}

		if ( 'text' === $type ) {
			$this->postgresql_column_length_cache[ $tablekey ][ $columnkey ] = array(
				'type'   => 'byte',
				'length' => 65535,
			);
			return $this->postgresql_column_length_cache[ $tablekey ][ $columnkey ];
		}

		$this->postgresql_column_length_cache[ $tablekey ][ $columnkey ] = false;
		return false;
	}

	/**
	 * Determines the best charset/collation pair for PostgreSQL-backed wpdb.
	 *
	 * Core returns early for non-mysqli handles. The PostgreSQL backend still
	 * advertises MySQL-compatible charset capabilities to WordPress, so apply
	 * the same utf8-to-utf8mb4 upgrade rules without the mysqli guard.
	 *
	 * @param string $charset Requested charset.
	 * @param string $collate Requested collation.
	 * @return array{charset: string, collate: string} Charset/collation pair.
	 */
	public function determine_charset( $charset, $collate ) {
		if ( empty( $this->dbh ) ) {
			return compact( 'charset', 'collate' );
		}

		if ( 'utf8' === $charset ) {
			$charset = 'utf8mb4';
		}

		if ( 'utf8mb4' === $charset ) {
			if ( ! $collate || 'utf8_general_ci' === $collate ) {
				$collate = 'utf8mb4_unicode_ci';
			} else {
				$collate = str_replace( 'utf8_', 'utf8mb4_', $collate );
			}
		}

		if ( $this->has_cap( 'utf8mb4_520' ) && 'utf8mb4_unicode_ci' === $collate ) {
			$collate = 'utf8mb4_unicode_520_ci';
		}

		return compact( 'charset', 'collate' );
	}

	/**
	 * Strip invalid UTF-8 text using WordPress core's local regex path.
	 *
	 * @param string     $value   Text value.
	 * @param string     $charset MySQL charset.
	 * @param int|false  $length  Optional character length.
	 * @return string Stripped value.
	 */
	private function strip_postgresql_invalid_utf8_text( string $value, string $charset, $length ): string {
		$regex = '/
			(
				(?: [\x00-\x7F]
				|   [\xC2-\xDF][\x80-\xBF]
				|   \xE0[\xA0-\xBF][\x80-\xBF]
				|   [\xE1-\xEC][\x80-\xBF]{2}
				|   \xED[\x80-\x9F][\x80-\xBF]
				|   [\xEE-\xEF][\x80-\xBF]{2}';

		if ( 'utf8mb4' === $charset ) {
			$regex .= '
				|    \xF0[\x90-\xBF][\x80-\xBF]{2}
				|    [\xF1-\xF3][\x80-\xBF]{3}
				|    \xF4[\x80-\x8F][\x80-\xBF]{2}
			';
		}

		$regex .= '){1,40}
			)
			| .
			/x';

		$value = preg_replace( $regex, '$1', $value );
		if ( false !== $length && mb_strlen( $value, 'UTF-8' ) > $length ) {
			$value = mb_substr( $value, 0, $length, 'UTF-8' );
		}

		return $value;
	}

	/**
	 * Strip invalid text for MySQL legacy charsets using PHP conversion.
	 *
	 * @param string      $value   Text value.
	 * @param string      $charset MySQL charset.
	 * @param array|false $length  Optional length metadata.
	 * @return string|false Stripped value, or false when unsupported.
	 */
	private function strip_postgresql_invalid_legacy_text( string $value, string $charset, $length ) {
		$charset            = $this->normalize_postgresql_mysql_charset( $charset );
		$connection_charset = $this->get_postgresql_connection_charset();

		if ( is_array( $length ) && 'byte' === $length['type'] ) {
			return $this->strip_postgresql_invalid_trailing_bytes( $value, $connection_charset );
		}

		if ( $charset === $connection_charset && $this->is_postgresql_single_byte_mysql_charset( $charset ) ) {
			if ( is_array( $length ) ) {
				return substr( $value, 0, (int) $length['length'] );
			}

			return $value;
		}

		if ( ! function_exists( 'mb_convert_encoding' ) ) {
			return false;
		}

		$target_encoding     = $this->get_postgresql_php_encoding_for_mysql_charset( $charset );
		$connection_encoding = $this->get_postgresql_php_encoding_for_mysql_charset( $connection_charset );
		if ( null === $target_encoding || null === $connection_encoding ) {
			return false;
		}

		$target_value = $value;
		if ( $target_encoding !== $connection_encoding ) {
			$target_value = mb_convert_encoding( $value, $target_encoding, $connection_encoding );
		}

		if ( is_array( $length ) ) {
			$target_value = mb_substr( $target_value, 0, (int) $length['length'], $target_encoding );
		}

		if ( $target_encoding === $connection_encoding ) {
			return $this->strip_postgresql_invalid_trailing_bytes( $target_value, $connection_charset );
		}

		return mb_convert_encoding( $target_value, $connection_encoding, $target_encoding );
	}

	/**
	 * Strip a partial trailing multibyte sequence after byte truncation.
	 *
	 * @param string $value   Text value.
	 * @param string $charset MySQL charset.
	 * @return string|false Stripped value, or false when unsupported.
	 */
	private function strip_postgresql_invalid_trailing_bytes( string $value, string $charset ) {
		$charset = $this->normalize_postgresql_mysql_charset( $charset );
		if ( $this->is_postgresql_single_byte_mysql_charset( $charset ) ) {
			return $value;
		}

		$encoding = $this->get_postgresql_php_encoding_for_mysql_charset( $charset );
		if ( null === $encoding || ! function_exists( 'mb_check_encoding' ) ) {
			return false;
		}

		while ( '' !== $value && ! mb_check_encoding( $value, $encoding ) ) {
			$value = substr( $value, 0, -1 );
		}

		return $value;
	}

	/**
	 * Get the current MySQL-compatible connection charset.
	 *
	 * @return string Charset.
	 */
	private function get_postgresql_connection_charset(): string {
		if ( ! empty( $this->charset ) ) {
			return $this->normalize_postgresql_mysql_charset( (string) $this->charset );
		}

		if ( $this->dbh instanceof WP_PostgreSQL_Driver ) {
			return $this->normalize_postgresql_mysql_charset( $this->dbh->get_charset() );
		}

		return 'utf8mb4';
	}

	/**
	 * Normalize a MySQL charset for PostgreSQL adapter logic.
	 *
	 * @param string $charset Charset.
	 * @return string Normalized charset.
	 */
	private function normalize_postgresql_mysql_charset( string $charset ): string {
		$charset = strtolower( trim( $charset, "'\"` \t\n\r\0\x0B" ) );
		return 'utf8mb3' === $charset ? 'utf8' : $charset;
	}

	/**
	 * Check whether a charset is single-byte for truncation purposes.
	 *
	 * @param string $charset MySQL charset.
	 * @return bool Whether the charset is single-byte.
	 */
	private function is_postgresql_single_byte_mysql_charset( string $charset ): bool {
		return in_array(
			$this->normalize_postgresql_mysql_charset( $charset ),
			array( 'ascii', 'binary', 'cp1251', 'hebrew', 'koi8r', 'latin1', 'tis620' ),
			true
		);
	}

	/**
	 * Map a MySQL charset to a PHP mbstring encoding.
	 *
	 * @param string $charset MySQL charset.
	 * @return string|null PHP encoding, or null when unsupported.
	 */
	private function get_postgresql_php_encoding_for_mysql_charset( string $charset ): ?string {
		$encodings = array(
			'ascii'   => 'ASCII',
			'big5'    => 'BIG-5',
			'cp1251'  => 'Windows-1251',
			'hebrew'  => 'ISO-8859-8',
			'koi8r'   => 'KOI8-R',
			'latin1'  => 'ISO-8859-1',
			'ujis'    => 'EUC-JP',
			'utf8'    => 'UTF-8',
			'utf8mb4' => 'UTF-8',
		);

		$charset = $this->normalize_postgresql_mysql_charset( $charset );
		return $encodings[ $charset ] ?? null;
	}

	/**
	 * Store MySQL charset metadata for a successfully created PostgreSQL table.
	 *
	 * @param string $query Original MySQL CREATE TABLE query.
	 */
	private function store_postgresql_create_table_charset_metadata( string $query ): void {
		if ( ! $this->has_usable_postgresql_connection() ) {
			return;
		}

		$table_name = $this->get_postgresql_create_table_name( $query );
		if ( null !== $table_name ) {
			$this->clear_postgresql_table_charset_cache( array( $table_name ) );
		}

		if ( ! class_exists( 'WP_PostgreSQL_Create_Table_Translator', false ) ) {
			return;
		}

		if ( ! $this->is_postgresql_mysql_charset_metadata_create_query( $query ) ) {
			return;
		}

		if ( $this->is_postgresql_create_temporary_table_query( $query ) ) {
			try {
				$metadata = ( new WP_PostgreSQL_Create_Table_Translator() )->extract_schema_metadata( $query );
				foreach ( $metadata as $table ) {
					if ( empty( $table['table_name'] ) ) {
						continue;
					}

					$table_name = (string) $table['table_name'];
					$tablekey   = $this->get_postgresql_metadata_key( $table_name );
					$this->clear_postgresql_table_charset_cache( array( $table_name ) );

					$rows = array();
					foreach ( (array) ( $table['columns'] ?? array() ) as $column ) {
						$rows[] = array(
							'column_name'    => (string) $column['name'],
							'column_type'    => (string) $column['type'],
							'collation_name' => $column['collation'],
						);
					}

					$columns = $this->format_postgresql_charset_column_rows( $rows );
					if ( ! empty( $columns ) ) {
						$this->postgresql_temporary_charset_metadata[ $tablekey ] = $columns;
					}
				}
			} catch ( Throwable $e ) {
				return;
			}
			return;
		}

		try {
			$metadata = ( new WP_PostgreSQL_Create_Table_Translator() )->extract_schema_metadata( $query );
			if ( empty( $metadata ) ) {
				return;
			}

			$this->ensure_postgresql_charset_metadata_table();

			foreach ( $metadata as $table ) {
				if ( empty( $table['table_name'] ) ) {
					continue;
				}

				$table_name = (string) $table['table_name'];
				$this->dbh->get_connection()->query(
					'DELETE FROM ' . $this->quote_identifier( self::MYSQL_CHARSET_METADATA_TABLE ) . '
					WHERE table_schema = current_schema()
						AND lower(table_name) = lower(?)',
					array( $table_name )
				);

				foreach ( (array) ( $table['columns'] ?? array() ) as $column ) {
					$this->dbh->get_connection()->query(
						'INSERT INTO ' . $this->quote_identifier( self::MYSQL_CHARSET_METADATA_TABLE ) . ' (
							table_schema,
							table_name,
							column_name,
							ordinal_position,
							column_type,
							character_set_name,
							collation_name
						) VALUES (
							current_schema(),
							?,
							?,
							?,
							?,
							?,
							?
						)',
						array(
							$table_name,
							(string) $column['name'],
							(int) $column['ordinal'],
							(string) $column['type'],
							$column['charset'],
							$column['collation'],
						)
					);
				}

				$this->clear_postgresql_table_charset_cache( array( $table_name ) );
			}
		} catch ( Throwable $e ) {
			return;
		}
	}

	/**
	 * Delete MySQL charset metadata for successfully dropped PostgreSQL tables.
	 *
	 * @param string $query Original DROP TABLE query.
	 */
	private function delete_postgresql_dropped_table_charset_metadata( string $query ): void {
		if ( ! $this->has_usable_postgresql_connection() ) {
			return;
		}

		$tables = $this->get_postgresql_drop_table_names( $query );
		if ( empty( $tables ) ) {
			return;
		}

		$this->clear_postgresql_table_charset_cache( $tables );

		if ( $this->is_postgresql_drop_temporary_table_query( $query ) ) {
			return;
		}

		try {
			if ( ! $this->postgresql_charset_metadata_table_exists() ) {
				return;
			}

			foreach ( $tables as $table ) {
				$this->dbh->get_connection()->query(
					'DELETE FROM ' . $this->quote_identifier( self::MYSQL_CHARSET_METADATA_TABLE ) . '
					WHERE table_schema = current_schema()
						AND lower(table_name) = lower(?)',
					array( $table )
				);
			}
		} catch ( Throwable $e ) {
			return;
		}
	}

	/**
	 * Clear cached charset metadata for table names.
	 *
	 * @param string[] $tables Table names.
	 */
	private function clear_postgresql_table_charset_cache( array $tables ): void {
		foreach ( $tables as $table ) {
			$tablekey = $this->get_postgresql_metadata_key( (string) $table );
			if ( self::MYSQL_CHARSET_METADATA_TABLE === $this->normalize_postgresql_table_name( (string) $table ) ) {
				$this->postgresql_charset_metadata_table_exists = null;
			}

			unset(
				$this->table_charset[ $tablekey ],
				$this->col_meta[ $tablekey ],
				$this->postgresql_temporary_charset_metadata[ $tablekey ],
				$this->postgresql_column_charset_metadata_cache[ $tablekey ],
				$this->postgresql_column_length_cache[ $tablekey ]
			);
		}
	}

	/**
	 * Clear all derived PostgreSQL charset metadata caches.
	 */
	private function clear_all_postgresql_table_charset_cache(): void {
		$this->table_charset                            = array();
		$this->col_meta                                 = array();
		$this->postgresql_column_charset_metadata_cache = array();
		$this->postgresql_column_length_cache           = array();
		$this->postgresql_charset_metadata_table_exists = null;
	}

	/**
	 * Ensure the PostgreSQL side table for MySQL charset metadata exists.
	 */
	private function ensure_postgresql_charset_metadata_table(): void {
		$this->dbh->get_connection()->query(
			'CREATE TABLE IF NOT EXISTS ' . $this->quote_identifier( self::MYSQL_CHARSET_METADATA_TABLE ) . ' (
				table_schema text NOT NULL,
				table_name text NOT NULL,
				column_name text NOT NULL,
				ordinal_position integer NOT NULL,
				column_type text NOT NULL,
				character_set_name text,
				collation_name text,
				PRIMARY KEY (table_schema, table_name, column_name)
			)'
		);
		$this->postgresql_charset_metadata_table_exists = true;
	}

	/**
	 * Check whether a CREATE TABLE query carries MySQL charset metadata.
	 *
	 * The WordPress PostgreSQL installer executes already-translated PostgreSQL
	 * DDL. That SQL must not be fed back into the MySQL parser used for metadata
	 * extraction.
	 *
	 * @param string $query CREATE TABLE query.
	 * @return bool Whether the query should be parsed as MySQL charset DDL.
	 */
	private function is_postgresql_mysql_charset_metadata_create_query( string $query ): bool {
		return 1 === preg_match( '/\b(?:CHARSET|CHARACTER\s+SET|COLLATE|ASCII|UNICODE|BINARY)\b/i', $query );
	}

	/**
	 * Check whether a CREATE TABLE query creates a temporary table.
	 *
	 * @param string $query CREATE TABLE query.
	 * @return bool Whether the query is CREATE TEMPORARY TABLE.
	 */
	private function is_postgresql_create_temporary_table_query( string $query ): bool {
		if ( ! class_exists( 'WP_MySQL_Lexer', false ) ) {
			return false;
		}

		$lexer  = new WP_MySQL_Lexer( $query );
		$tokens = $lexer instanceof WP_MySQL_Native_Lexer ? $lexer->native_token_stream() : $lexer->remaining_tokens();

		return isset( $tokens[0], $tokens[1], $tokens[2] )
			&& WP_MySQL_Lexer::CREATE_SYMBOL === $tokens[0]->id
			&& WP_MySQL_Lexer::TEMPORARY_SYMBOL === $tokens[1]->id
			&& WP_MySQL_Lexer::TABLE_SYMBOL === $tokens[2]->id;
	}

	/**
	 * Get the table name from a CREATE TABLE query.
	 *
	 * @param string $query CREATE TABLE query.
	 * @return string|null Table name, or null when unavailable.
	 */
	private function get_postgresql_create_table_name( string $query ): ?string {
		if ( ! class_exists( 'WP_MySQL_Lexer', false ) ) {
			return null;
		}

		$lexer  = new WP_MySQL_Lexer( $query );
		$tokens = $lexer instanceof WP_MySQL_Native_Lexer ? $lexer->native_token_stream() : $lexer->remaining_tokens();

		if ( ! isset( $tokens[0] ) || WP_MySQL_Lexer::CREATE_SYMBOL !== $tokens[0]->id ) {
			return null;
		}

		$position = 1;
		if ( isset( $tokens[ $position ] ) && WP_MySQL_Lexer::TEMPORARY_SYMBOL === $tokens[ $position ]->id ) {
			++$position;
		}

		if ( ! isset( $tokens[ $position ] ) || WP_MySQL_Lexer::TABLE_SYMBOL !== $tokens[ $position ]->id ) {
			return null;
		}

		++$position;
		if (
			isset( $tokens[ $position ], $tokens[ $position + 1 ], $tokens[ $position + 2 ] )
			&& WP_MySQL_Lexer::IF_SYMBOL === $tokens[ $position ]->id
			&& WP_MySQL_Lexer::NOT_SYMBOL === $tokens[ $position + 1 ]->id
			&& WP_MySQL_Lexer::EXISTS_SYMBOL === $tokens[ $position + 2 ]->id
		) {
			$position += 3;
		}

		if (
			! isset( $tokens[ $position ] )
			|| ! in_array( $tokens[ $position ]->id, array( WP_MySQL_Lexer::IDENTIFIER, WP_MySQL_Lexer::BACK_TICK_QUOTED_ID ), true )
		) {
			return null;
		}

		return $tokens[ $position ]->get_value();
	}

	/**
	 * Check whether a DROP TABLE query targets temporary tables.
	 *
	 * @param string $query DROP TABLE query.
	 * @return bool Whether the query is DROP TEMPORARY TABLE.
	 */
	private function is_postgresql_drop_temporary_table_query( string $query ): bool {
		if ( ! class_exists( 'WP_MySQL_Lexer', false ) ) {
			return false;
		}

		$lexer  = new WP_MySQL_Lexer( $query );
		$tokens = $lexer instanceof WP_MySQL_Native_Lexer ? $lexer->native_token_stream() : $lexer->remaining_tokens();

		return isset( $tokens[0], $tokens[1], $tokens[2] )
			&& WP_MySQL_Lexer::DROP_SYMBOL === $tokens[0]->id
			&& WP_MySQL_Lexer::TEMPORARY_SYMBOL === $tokens[1]->id
			&& WP_MySQL_Lexer::TABLE_SYMBOL === $tokens[2]->id;
	}

	/**
	 * Check whether the side table for MySQL charset metadata exists.
	 *
	 * @return bool Whether the metadata table exists in the current schema.
	 */
	private function postgresql_charset_metadata_table_exists(): bool {
		if ( null !== $this->postgresql_charset_metadata_table_exists ) {
			return $this->postgresql_charset_metadata_table_exists;
		}

		try {
			$stmt = $this->dbh->get_connection()->query(
				'SELECT EXISTS (
					SELECT 1
					FROM information_schema.tables
					WHERE table_schema = current_schema()
						AND table_name = ?
				)',
				array( self::MYSQL_CHARSET_METADATA_TABLE )
			);
			$this->postgresql_charset_metadata_table_exists = (bool) $stmt->fetchColumn();
		} catch ( Throwable $e ) {
			$this->postgresql_charset_metadata_table_exists = false;
		}

		return $this->postgresql_charset_metadata_table_exists;
	}

	/**
	 * Load MySQL-compatible column metadata for a PostgreSQL table.
	 *
	 * @param string $table Table name.
	 * @return array|false Column metadata keyed by lowercase column name, or false.
	 */
	private function get_postgresql_column_charset_metadata( string $table ) {
		if ( ! $this->has_usable_postgresql_connection() ) {
			return false;
		}

		$tablekey = $this->get_postgresql_metadata_key( $table );
		if ( array_key_exists( $tablekey, $this->postgresql_column_charset_metadata_cache ) ) {
			return $this->postgresql_column_charset_metadata_cache[ $tablekey ];
		}

		$temp_schema = $this->get_postgresql_temporary_table_schema( $table );
		if ( false === $temp_schema ) {
			$this->postgresql_column_charset_metadata_cache[ $tablekey ] = false;
			return false;
		}

		if ( null !== $temp_schema ) {
			if ( array_key_exists( $tablekey, $this->postgresql_temporary_charset_metadata ) ) {
				$this->postgresql_column_charset_metadata_cache[ $tablekey ] = $this->postgresql_temporary_charset_metadata[ $tablekey ];
				return $this->postgresql_column_charset_metadata_cache[ $tablekey ];
			}

			$this->postgresql_column_charset_metadata_cache[ $tablekey ] = $this->get_native_postgresql_column_charset_metadata( $table, $temp_schema );
			return $this->postgresql_column_charset_metadata_cache[ $tablekey ];
		}

		$columns = $this->get_stored_postgresql_column_charset_metadata( $table );
		if ( false !== $columns && ! empty( $columns ) ) {
			$this->postgresql_column_charset_metadata_cache[ $tablekey ] = $columns;
			return $columns;
		}

		$columns = $this->get_driver_postgresql_column_charset_metadata( $table );
		if ( false !== $columns && ! empty( $columns ) ) {
			$this->postgresql_column_charset_metadata_cache[ $tablekey ] = $columns;
			return $columns;
		}

		$this->postgresql_column_charset_metadata_cache[ $tablekey ] = $this->get_native_postgresql_column_charset_metadata( $table );
		return $this->postgresql_column_charset_metadata_cache[ $tablekey ];
	}

	/**
	 * Get the active temporary schema for a table name.
	 *
	 * @param string $table Table name.
	 * @return string|null|false Temporary schema, null when not temporary, or false on failure.
	 */
	private function get_postgresql_temporary_table_schema( string $table ) {
		try {
			$stmt   = $this->dbh->get_connection()->query(
				'SELECT n.nspname
				FROM pg_catalog.pg_class c
				INNER JOIN pg_catalog.pg_namespace n
					ON n.oid = c.relnamespace
				WHERE n.oid = pg_my_temp_schema()
					AND lower(c.relname) = lower(?)
					AND c.relkind IN (\'r\', \'p\')
				LIMIT 1',
				array( $this->normalize_postgresql_table_name( $table ) )
			);
			$schema = $stmt->fetchColumn();
		} catch ( Throwable $e ) {
			return false;
		}

		return false === $schema ? null : (string) $schema;
	}

	/**
	 * Load previously stored MySQL charset metadata.
	 *
	 * @param string $table Table name.
	 * @return array|false Column metadata, or false when unavailable.
	 */
	private function get_stored_postgresql_column_charset_metadata( string $table ) {
		if ( ! $this->postgresql_charset_metadata_table_exists() ) {
			return false;
		}

		try {
			$stmt = $this->dbh->get_connection()->query(
				'SELECT column_name, column_type, collation_name
				FROM ' . $this->quote_identifier( self::MYSQL_CHARSET_METADATA_TABLE ) . '
				WHERE table_schema = current_schema()
					AND lower(table_name) = lower(?)
				ORDER BY ordinal_position',
				array( $this->normalize_postgresql_table_name( $table ) )
			);
			$rows = $stmt->fetchAll( PDO::FETCH_ASSOC );
		} catch ( Throwable $e ) {
			return false;
		}

		return $this->format_postgresql_charset_column_rows( $rows );
	}

	/**
	 * Load MySQL charset metadata through the PostgreSQL driver's SHOW COLUMNS path.
	 *
	 * The driver stores MySQL-facing column metadata as part of CREATE TABLE
	 * translation. Reusing it keeps wpdb charset checks aligned with DESCRIBE and
	 * SHOW FULL COLUMNS without depending on the adapter side table being present.
	 *
	 * @param string $table Table name.
	 * @return array|false Column metadata, or false when unavailable.
	 */
	private function get_driver_postgresql_column_charset_metadata( string $table ) {
		if ( ! $this->dbh instanceof WP_PostgreSQL_Driver ) {
			return false;
		}

		$table_name = $this->normalize_postgresql_table_name( $table );
		if ( '' === $table_name ) {
			return false;
		}

		try {
			$rows = $this->dbh->query(
				'SHOW FULL COLUMNS FROM ' . $this->quote_postgresql_mysql_identifier( $table_name ),
				PDO::FETCH_ASSOC
			);
		} catch ( Throwable $e ) {
			return false;
		}

		if ( ! is_array( $rows ) || empty( $rows ) ) {
			return false;
		}

		$metadata_rows = array();
		foreach ( $rows as $row ) {
			if ( is_object( $row ) ) {
				$row = get_object_vars( $row );
			}

			if ( ! is_array( $row ) || empty( $row['Field'] ) ) {
				continue;
			}

			$metadata_rows[] = array(
				'column_name'    => $row['Field'],
				'column_type'    => $row['Type'] ?? '',
				'collation_name' => $row['Collation'] ?? null,
			);
		}

		if ( empty( $metadata_rows ) ) {
			return false;
		}

		return $this->format_postgresql_charset_column_rows( $metadata_rows );
	}

	/**
	 * Quote an identifier for a MySQL statement handled by the PostgreSQL driver.
	 *
	 * @param string $identifier Identifier.
	 * @return string Backtick-quoted MySQL identifier.
	 */
	private function quote_postgresql_mysql_identifier( string $identifier ): string {
		return '`' . str_replace( '`', '``', $identifier ) . '`';
	}

	/**
	 * Synthesize MySQL metadata from PostgreSQL catalogs when side metadata is absent.
	 *
	 * @param string $table Table name.
	 * @return array|false Column metadata, or false when unavailable.
	 */
	private function get_native_postgresql_column_charset_metadata( string $table, ?string $table_schema = null ) {
		try {
			$table_schema_sql = null === $table_schema ? 'current_schema()' : '?';
			$params           = null === $table_schema
				? array( $this->normalize_postgresql_table_name( $table ) )
				: array( $table_schema, $this->normalize_postgresql_table_name( $table ) );

			$stmt = $this->dbh->get_connection()->query(
				'SELECT column_name, data_type, character_maximum_length
				FROM information_schema.columns
				WHERE table_schema = ' . $table_schema_sql . '
					AND lower(table_name) = lower(?)
				ORDER BY ordinal_position',
				$params
			);
			$rows = $stmt->fetchAll( PDO::FETCH_ASSOC );
		} catch ( Throwable $e ) {
			return false;
		}

		if ( empty( $rows ) ) {
			return false;
		}

		$columns           = array();
		$default_collation = $this->get_postgresql_default_collation_for_charset( $this->charset ? $this->charset : 'utf8mb4' );

		foreach ( $rows as $row ) {
			$type      = strtolower( (string) ( $row['data_type'] ?? '' ) );
			$length    = isset( $row['character_maximum_length'] ) ? (int) $row['character_maximum_length'] : 0;
			$mysqltype = $this->get_postgresql_native_mysql_column_type( $type, $length );
			$collation = in_array( $type, array( 'character varying', 'character', 'text' ), true ) ? $default_collation : null;

			$columns[] = array(
				'column_name'    => (string) $row['column_name'],
				'column_type'    => $mysqltype,
				'collation_name' => $collation,
			);
		}

		return $this->format_postgresql_charset_column_rows( $columns );
	}

	/**
	 * Convert metadata rows into wpdb col_meta objects.
	 *
	 * @param array $rows Metadata rows.
	 * @return array Column metadata keyed by lowercase column name.
	 */
	private function format_postgresql_charset_column_rows( array $rows ): array {
		$columns = array();

		foreach ( $rows as $row ) {
			$field = (string) ( $row['column_name'] ?? '' );
			if ( '' === $field ) {
				continue;
			}

			$columns[ $this->get_postgresql_metadata_key( $field ) ] = (object) array(
				'Field'     => $field,
				'Type'      => (string) ( $row['column_type'] ?? '' ),
				'Collation' => $row['collation_name'] ?? null,
			);
		}

		return $columns;
	}

	/**
	 * Convert a MySQL-facing column type into WordPress length metadata.
	 *
	 * @param string $column_type MySQL column type.
	 * @return array|false Column length metadata, or false when unrestricted/unknown.
	 */
	private function get_postgresql_column_length_from_mysql_type( string $column_type ) {
		$typeinfo = explode( '(', $column_type, 2 );
		$type     = strtolower( trim( $typeinfo[0] ) );
		$length   = false;

		if ( ! empty( $typeinfo[1] ) ) {
			$length = (int) trim( $typeinfo[1], ") \t\n\r\0\x0B" );
		}

		switch ( $type ) {
			case 'char':
			case 'varchar':
				if ( false === $length || $length <= 0 ) {
					return false;
				}

				return array(
					'type'   => 'char',
					'length' => $length,
				);

			case 'binary':
			case 'varbinary':
				if ( false === $length || $length <= 0 ) {
					return false;
				}

				return array(
					'type'   => 'byte',
					'length' => $length,
				);

			case 'tinyblob':
			case 'tinytext':
				return array(
					'type'   => 'byte',
					'length' => 255,
				);

			case 'blob':
			case 'text':
				return array(
					'type'   => 'byte',
					'length' => 65535,
				);

			case 'mediumblob':
			case 'mediumtext':
				return array(
					'type'   => 'byte',
					'length' => 16777215,
				);

			case 'longblob':
			case 'longtext':
				return array(
					'type'   => 'byte',
					'length' => 4294967295,
				);

			default:
				return false;
		}
	}

	/**
	 * Calculate WordPress's table charset value from column metadata.
	 *
	 * @param array $columns Column metadata.
	 * @return string|false Table charset, or false for tables without text columns.
	 */
	private function get_postgresql_table_charset_from_columns( array $columns ) {
		$charsets = array();

		foreach ( $columns as $column ) {
			$collation = $column->{'Collation'};
			if ( ! empty( $collation ) ) {
				list( $charset ) = explode( '_', $collation );

				$charsets[ strtolower( $charset ) ] = true;
			}

			$column_type = $column->{'Type'};

			list( $type ) = explode( '(', $column_type );
			if ( in_array( strtoupper( $type ), array( 'BINARY', 'VARBINARY', 'TINYBLOB', 'MEDIUMBLOB', 'BLOB', 'LONGBLOB' ), true ) ) {
				return 'binary';
			}
		}

		if ( isset( $charsets['utf8mb3'] ) ) {
			$charsets['utf8'] = true;
			unset( $charsets['utf8mb3'] );
		}

		$count = count( $charsets );
		if ( 1 === $count ) {
			return key( $charsets );
		}

		if ( 0 === $count ) {
			return false;
		}

		unset( $charsets['latin1'] );
		$count = count( $charsets );
		if ( 1 === $count ) {
			return key( $charsets );
		}

		if ( 2 === $count && isset( $charsets['utf8'], $charsets['utf8mb4'] ) ) {
			return 'utf8';
		}

		return 'ascii';
	}

	/**
	 * Convert PostgreSQL catalog types to MySQL-ish metadata types.
	 *
	 * @param string $type PostgreSQL data type.
	 * @param int    $length Character length.
	 * @return string MySQL-ish column type.
	 */
	private function get_postgresql_native_mysql_column_type( string $type, int $length ): string {
		if ( 'character varying' === $type ) {
			return $length > 0 ? sprintf( 'varchar(%d)', $length ) : 'varchar';
		}

		if ( 'character' === $type ) {
			return $length > 0 ? sprintf( 'char(%d)', $length ) : 'char';
		}

		if ( 'bytea' === $type ) {
			return 'blob';
		}

		if ( 'integer' === $type ) {
			return 'int';
		}

		if ( 'double precision' === $type || 'real' === $type || 'numeric' === $type ) {
			return 'float';
		}

		return $type;
	}

	/**
	 * Get the default MySQL collation for a charset.
	 *
	 * @param string $charset Charset.
	 * @return string Collation.
	 */
	private function get_postgresql_default_collation_for_charset( string $charset ): string {
		$charset = strtolower( $charset );
		if ( 'utf8mb3' === $charset ) {
			$charset = 'utf8';
		}

		$collations = array(
			'utf8'    => 'utf8_general_ci',
			'utf8mb4' => 'utf8mb4_unicode_ci',
			'latin1'  => 'latin1_swedish_ci',
			'big5'    => 'big5_chinese_ci',
			'koi8r'   => 'koi8r_general_ci',
			'cp1251'  => 'cp1251_general_ci',
			'ascii'   => 'ascii_general_ci',
		);

		return $collations[ $charset ] ?? $charset . '_general_ci';
	}

	/**
	 * Get table names from a DROP TABLE query.
	 *
	 * @param string $query DROP TABLE query.
	 * @return string[] Table names.
	 */
	private function get_postgresql_drop_table_names( string $query ): array {
		if ( ! class_exists( 'WP_MySQL_Lexer', false ) ) {
			return array();
		}

		$lexer  = new WP_MySQL_Lexer( $query );
		$tokens = $lexer instanceof WP_MySQL_Native_Lexer ? $lexer->native_token_stream() : $lexer->remaining_tokens();

		if ( ! isset( $tokens[0] ) || WP_MySQL_Lexer::DROP_SYMBOL !== $tokens[0]->id ) {
			return array();
		}

		$tables = array();
		foreach ( $tokens as $token ) {
			if ( WP_MySQL_Lexer::IDENTIFIER === $token->id || WP_MySQL_Lexer::BACK_TICK_QUOTED_ID === $token->id ) {
				$value = $token->get_value();
				if ( ! in_array( strtolower( $value ), array( 'drop', 'temporary', 'table', 'if', 'exists', 'restrict', 'cascade' ), true ) ) {
					$tables[] = $value;
				}
			}
		}

		return $tables;
	}

	/**
	 * Normalize a table name for PostgreSQL metadata lookups.
	 *
	 * @param string $table Table identifier.
	 * @return string Table name.
	 */
	private function normalize_postgresql_table_name( string $table ): string {
		$table = trim( $table, "`\" \t\n\r\0\x0B" );
		if ( false !== strpos( $table, '.' ) ) {
			$table = substr( $table, strrpos( $table, '.' ) + 1 );
			$table = trim( $table, "`\" \t\n\r\0\x0B" );
		}

		return $table;
	}

	/**
	 * Normalize an identifier for wpdb metadata cache keys.
	 *
	 * @param string $identifier Identifier.
	 * @return string Metadata key.
	 */
	private function get_postgresql_metadata_key( string $identifier ): string {
		return strtolower( $this->normalize_postgresql_table_name( $identifier ) );
	}

	/**
	 * Checks whether the adapter has a usable PDO-backed PostgreSQL connection.
	 *
	 * @return bool Whether a real connection is available.
	 */
	private function has_usable_postgresql_connection(): bool {
		if ( ! $this->dbh instanceof WP_PostgreSQL_Driver ) {
			return false;
		}

		try {
			return $this->dbh->get_connection()->get_pdo() instanceof PDO;
		} catch ( Throwable $e ) {
			return false;
		}
	}

	/**
	 * Changes the current SQL mode.
	 *
	 * PostgreSQL does not expose MySQL sql_mode, but WordPress stores and checks
	 * this state through wpdb. Keep the emulated state on the driver and apply
	 * the same incompatible-mode filtering as core wpdb.
	 *
	 * @param array $modes Optional. A list of SQL modes to set. Default empty array.
	 */
	public function set_sql_mode( $modes = array() ) {
		if ( empty( $modes ) ) {
			return;
		}

		$modes = array_map( 'strtoupper', (array) $modes );

		$incompatible_modes = property_exists( $this, 'incompatible_modes' ) ? (array) $this->incompatible_modes : array();
		if ( function_exists( 'apply_filters' ) ) {
			$incompatible_modes = (array) apply_filters( 'incompatible_sql_modes', $incompatible_modes );
		}

		foreach ( $modes as $i => $mode ) {
			if ( in_array( $mode, $incompatible_modes, true ) ) {
				unset( $modes[ $i ] );
			}
		}

		if ( $this->dbh instanceof WP_PostgreSQL_Driver ) {
			$this->dbh->set_sql_mode( implode( ',', array_values( $modes ) ) );
		}
	}

	/**
	 * Closes the current database connection.
	 *
	 * @return bool True when an open connection existed.
	 */
	public function close() {
		if ( ! $this->dbh ) {
			$this->ready = false;
			return false;
		}

		$this->dbh   = null;
		$this->ready = false;
		return true;
	}

	/**
	 * Method to select the database connection.
	 *
	 * @param string        $db  Database name.
	 * @param resource|null $dbh Optional link identifier.
	 * @return bool Whether the selected database matches the configured database.
	 */
	public function select( $db, $dbh = null ) {
		if ( null === $dbh ) {
			$dbh = $this->dbh;
		}

		$this->ready = $dbh instanceof WP_PostgreSQL_Driver && (string) $db === (string) $this->dbname;
		return $this->ready;
	}

	/**
	 * Escapes string data without using mysqli.
	 *
	 * @param string $data The string to escape.
	 * @return string Escaped string.
	 */
	public function _real_escape( $data ) {
		if ( ! is_scalar( $data ) ) {
			return '';
		}

		$escaped = addslashes( (string) $data );
		return $this->add_placeholder_escape( $escaped );
	}

	/**
	 * Prints SQL/DB error.
	 *
	 * This mirrors wpdb::print_error() without calling mysqli_error() on the
	 * PostgreSQL driver object.
	 *
	 * @global array $EZSQL_ERROR Stores error information of query and error string.
	 *
	 * @param string $str The error to display.
	 * @return void|false Void if the showing of errors is enabled, false if disabled.
	 */
	public function print_error( $str = '' ) {
		global $EZSQL_ERROR;

		if ( ! $str ) {
			$str = $this->last_error;
		}

		$EZSQL_ERROR[] = array(
			'query'     => $this->last_query,
			'error_str' => $str,
		);

		if ( $this->suppress_errors ) {
			return false;
		}

		$caller = $this->get_caller();
		if ( $caller ) {
			// Not translated, as this will only appear in the error log.
			$error_str = sprintf( 'WordPress database error %1$s for query %2$s made by %3$s', $str, $this->last_query, $caller );
		} else {
			$error_str = sprintf( 'WordPress database error %1$s for query %2$s', $str, $this->last_query );
		}

		error_log( $error_str );

		if ( ! $this->show_errors ) {
			return false;
		}

		wp_load_translations_early();

		if ( is_multisite() ) {
			$msg = sprintf(
				"%s [%s]\n%s\n",
				__( 'WordPress database error:' ),
				$str,
				$this->last_query
			);

			if ( defined( 'ERRORLOGFILE' ) ) {
				error_log( $msg, 3, ERRORLOGFILE );
			}
			if ( defined( 'DIEONDBERROR' ) ) {
				wp_die( $msg );
			}
		} else {
			$str   = htmlspecialchars( $str, ENT_QUOTES );
			$query = htmlspecialchars( $this->last_query, ENT_QUOTES );

			printf(
				'<div id="error"><p class="wpdberror"><strong>%s</strong> [%s]<br /><code>%s</code></p></div>',
				__( 'WordPress database error:' ),
				$str,
				$query
			);
		}
	}

	/**
	 * Quotes a PostgreSQL identifier.
	 *
	 * @param string $identifier Identifier to escape.
	 * @return string Escaped identifier.
	 */
	public function quote_identifier( $identifier ) {
		return WP_PostgreSQL_Connection::quote_identifier_value( (string) $identifier );
	}

	/**
	 * Method to flush cached data.
	 */
	public function flush() {
		$this->last_result   = array();
		$this->col_info      = null;
		$this->last_query    = null;
		$this->rows_affected = 0;
		$this->num_rows      = 0;
		$this->last_error    = '';
		$this->result        = null;
	}

	/**
	 * Connects to the PostgreSQL database.
	 *
	 * @param bool $allow_bail Whether to bail on connection failure.
	 * @return bool Whether the connection succeeded.
	 */
	public function db_connect( $allow_bail = true ) {
		$this->is_mysql = true;

		if ( $this->dbh instanceof WP_PostgreSQL_Driver ) {
			$this->ready = true;
			return true;
		}

		$this->ready      = false;
		$this->last_error = '';
		$this->init_charset();

		if ( null === $this->dbname || '' === (string) $this->dbname ) {
			$this->last_error = 'The database name was not set. The PostgreSQL backend requires DB_NAME.';
			if ( $allow_bail ) {
				$this->bail( $this->last_error, 'db_connect_fail' );
			}
			return false;
		}

		try {
			$connection      = new WP_PostgreSQL_Connection( $this->get_connection_options() );
			$this->dbh       = new WP_PostgreSQL_Driver( $connection, $this->dbname );
			$GLOBALS['@pdo'] = $connection->get_pdo();
			$this->ready     = true;
			$this->set_sql_mode();
			return true;
		} catch ( Throwable $e ) {
			$this->dbh        = null;
			$this->ready      = false;
			$this->last_error = $this->format_error_message( $e );

			if ( $allow_bail ) {
				$this->bail( $this->last_error, 'db_connect_fail' );
			}

			return false;
		}
	}

	/**
	 * Method to dummy out wpdb::check_connection().
	 *
	 * @param bool $allow_bail Whether to bail on connection failure.
	 * @return bool Whether the connection is alive.
	 */
	public function check_connection( $allow_bail = true ) {
		if ( $this->dbh instanceof WP_PostgreSQL_Driver ) {
			try {
				$this->dbh->get_connection()->query( 'SELECT 1' );
				return true;
			} catch ( Throwable $e ) {
				$this->last_error = $this->format_error_message( $e );
				$this->dbh        = null;
				$this->ready      = false;
			}
		}

		return $this->db_connect( $allow_bail );
	}

	/**
	 * Prepares a SQL query for safe execution.
	 *
	 * @param string      $query Query statement with placeholders.
	 * @param array|mixed $args  Variables to substitute.
	 * @param mixed       ...$args Further variables to substitute.
	 * @return string|void Sanitized query string, if there is a query to prepare.
	 */
	public function prepare( $query, ...$args ) {
		$wpdb_allow_unsafe_unquoted_parameters = $this->__get( 'allow_unsafe_unquoted_parameters' );
		if ( $wpdb_allow_unsafe_unquoted_parameters !== $this->allow_unsafe_unquoted_parameters ) {
			$property = new ReflectionProperty( 'wpdb', 'allow_unsafe_unquoted_parameters' );
			$property->setAccessible( true );
			$property->setValue( $this, $this->allow_unsafe_unquoted_parameters );
			$property->setAccessible( false );
		}

		if ( null === $query ) {
			return parent::prepare( $query, ...$args );
		}

		$identifier_prepare = $this->prepare_identifier_placeholders( $query, $args );
		if ( null === $identifier_prepare ) {
			return parent::prepare( $query, ...$args );
		}

		if ( $identifier_prepare['passed_as_array'] ) {
			$prepared = parent::prepare( $identifier_prepare['query'], $identifier_prepare['args'] );
		} else {
			$prepared = parent::prepare( $identifier_prepare['query'], ...$identifier_prepare['args'] );
		}

		if ( ! is_string( $prepared ) ) {
			return $prepared;
		}

		foreach ( $identifier_prepare['identifiers'] as $marker => $identifier ) {
			$prepared = str_replace( "'" . $marker . "'", $identifier, $prepared );
		}

		return $prepared;
	}

	/**
	 * Rewrites common unnumbered identifier placeholders for PostgreSQL quoting.
	 *
	 * Core wpdb::prepare() hardcodes %i as a MySQL-backticked placeholder. This
	 * adapter supports the common unnumbered %i form by letting core prepare all
	 * non-identifier values, then replacing exact quoted marker values with
	 * PostgreSQL-quoted identifiers. Numbered or formatted identifier placeholders
	 * fall back to core behavior until they can be mapped safely.
	 *
	 * @param string $query Query statement with placeholders.
	 * @param array  $args  Variables to substitute.
	 * @return array|null Rewritten prepare data, or null to use parent behavior.
	 */
	private function prepare_identifier_placeholders( $query, array $args ) {
		if ( ! is_string( $query ) || false === strpos( $query, '%i' ) ) {
			return null;
		}

		$scan = $this->rewrite_identifier_placeholder_query( $query );
		if ( null === $scan ) {
			return null;
		}

		$passed_as_array  = isset( $args[0] ) && is_array( $args[0] ) && 1 === count( $args );
		$prepare_args     = $passed_as_array ? $args[0] : $args;
		$collision_args   = $prepare_args;
		$identifiers      = array();
		static $marker_id = 0;

		foreach ( $scan['identifier_arg_indexes'] as $index => $arg_index ) {
			if ( ! array_key_exists( $arg_index, $prepare_args ) ) {
				continue;
			}

			do {
				++$marker_id;
				$marker = '__wp_pg_identifier_' . spl_object_hash( $this ) . '_' . $marker_id . '_' . $index . '__';
			} while ( $this->prepare_identifier_marker_has_collision( $marker, $query, $collision_args ) );

			$identifiers[ $marker ]     = $this->quote_identifier( $prepare_args[ $arg_index ] );
			$prepare_args[ $arg_index ] = $marker;
		}

		return array(
			'query'           => $scan['query'],
			'args'            => $prepare_args,
			'identifiers'     => $identifiers,
			'passed_as_array' => $passed_as_array,
		);
	}

	/**
	 * Checks whether an internal identifier marker appears in caller-controlled SQL.
	 *
	 * @param string $marker Marker candidate.
	 * @param string $query  Query statement with placeholders.
	 * @param array  $args   Variables to substitute.
	 * @return bool Whether the marker collides with the query or arguments.
	 */
	private function prepare_identifier_marker_has_collision( $marker, $query, array $args ) {
		if ( false !== strpos( $query, $marker ) ) {
			return true;
		}

		foreach ( $args as $arg ) {
			if ( ! is_scalar( $arg ) ) {
				continue;
			}

			if ( false !== strpos( (string) $arg, $marker ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Scans a prepare query and rewrites supported identifier placeholders.
	 *
	 * @param string $query Query statement with placeholders.
	 * @return array|null Rewritten query data, or null when unsupported.
	 */
	private function rewrite_identifier_placeholder_query( string $query ) {
		$length                 = strlen( $query );
		$position               = 0;
		$copy_from              = 0;
		$placeholder_index      = 0;
		$rewritten              = '';
		$has_identifier         = false;
		$has_numbered           = false;
		$has_escaped_candidate  = false;
		$identifier_arg_indexes = array();

		while ( $position < $length ) {
			if ( '%' !== $query[ $position ] ) {
				++$position;
				continue;
			}

			$run_start = $position;
			while ( $position < $length && '%' === $query[ $position ] ) {
				++$position;
			}

			$run_length = $position - $run_start;
			if ( 0 === $run_length % 2 ) {
				continue;
			}

			$placeholder_start = $position - 1;
			$placeholder       = $this->read_prepare_placeholder( $query, $position );
			if ( null === $placeholder ) {
				continue;
			}

			if ( 1 < $run_length ) {
				$has_escaped_candidate = true;
			}
			if ( $placeholder['numbered'] ) {
				$has_numbered = true;
			}

			if ( 'i' === $placeholder['type'] ) {
				if ( '' !== $placeholder['format'] || 1 < $run_length ) {
					return null;
				}

				$has_identifier           = true;
				$identifier_arg_indexes[] = $placeholder_index;
				$rewritten               .= substr( $query, $copy_from, $placeholder_start - $copy_from ) . '%s';
				$copy_from                = $placeholder['end'];
			}

			$position = $placeholder['end'];
			++$placeholder_index;
		}

		if ( ! $has_identifier || $has_numbered || $has_escaped_candidate ) {
			return null;
		}

		return array(
			'query'                  => $rewritten . substr( $query, $copy_from ),
			'identifier_arg_indexes' => $identifier_arg_indexes,
		);
	}

	/**
	 * Reads a wpdb::prepare() placeholder after the opening percent sign.
	 *
	 * @param string $query  Query statement with placeholders.
	 * @param int    $offset Offset immediately after the opening percent sign.
	 * @return array|null Placeholder metadata, or null when no placeholder matches.
	 */
	private function read_prepare_placeholder( string $query, int $offset ) {
		$length       = strlen( $query );
		$format_start = $offset;
		$position     = $offset;
		$numbered     = false;

		if ( $position < $length && '1' <= $query[ $position ] && '9' >= $query[ $position ] ) {
			$digits_start = $position;
			while ( $position < $length && ctype_digit( $query[ $position ] ) ) {
				++$position;
			}

			if ( $position < $length && '$' === $query[ $position ] ) {
				$numbered = true;
				++$position;
			} else {
				$position = $digits_start;
			}
		}

		while ( $position < $length && $this->is_prepare_format_flag( $query[ $position ] ) ) {
			++$position;
		}

		if ( $position < $length ) {
			if ( ' ' === $query[ $position ] ) {
				++$position;
			} elseif ( "'" === $query[ $position ] && $position + 1 < $length ) {
				$position += 2;
			}
		}

		while ( $position < $length && $this->is_prepare_format_flag( $query[ $position ] ) ) {
			++$position;
		}

		if ( $position < $length && '.' === $query[ $position ] ) {
			++$position;
			if ( $position >= $length || ! ctype_digit( $query[ $position ] ) ) {
				return null;
			}

			while ( $position < $length && ctype_digit( $query[ $position ] ) ) {
				++$position;
			}
		}

		if ( $position >= $length || false === strpos( 'sdfFi', $query[ $position ] ) ) {
			return null;
		}

		return array(
			'format'   => substr( $query, $format_start, $position - $format_start ),
			'type'     => $query[ $position ],
			'end'      => $position + 1,
			'numbered' => $numbered,
		);
	}

	/**
	 * Checks whether a character is allowed in a wpdb prepare format segment.
	 *
	 * @param string $char Character to inspect.
	 * @return bool Whether the character is a format flag, sign, or width digit.
	 */
	private function is_prepare_format_flag( string $char ): bool {
		return ctype_digit( $char ) || '-' === $char || '+' === $char;
	}

	/**
	 * Performs a database query.
	 *
	 * @param string $query Database query.
	 * @return int|bool Boolean true for CREATE, ALTER, TRUNCATE and DROP queries.
	 *                  Number of rows affected/selected for all other queries.
	 *                  Boolean false on error.
	 */
	public function query( $query ) {
		if ( ! $this->ready ) {
			return false;
		}

		$query = apply_filters( 'query', $query );

		if ( ! $query ) {
			$this->insert_id = 0;
			return false;
		}

		$this->flush();
		$this->func_call = "\$db->query(\"$query\")";

		$check_current_query = true;
		if ( property_exists( $this, 'check_current_query' ) ) {
			$check_current_query = (bool) $this->check_current_query;
		}

		if (
			$check_current_query
			&& is_string( $query )
			&& method_exists( $this, 'check_ascii' )
			&& method_exists( $this, 'strip_invalid_text_from_query' )
			&& ! $this->check_ascii( $query )
		) {
			$stripped_query = $this->strip_invalid_text_from_query( $query );
			$this->flush();
			if ( $stripped_query !== $query ) {
				$this->insert_id  = 0;
				$this->last_query = $query;
				wp_load_translations_early();
				$this->last_error = __( 'WordPress database error: Could not perform query because it contains invalid data.' );
				return false;
			}
		}

		if ( property_exists( $this, 'check_current_query' ) ) {
			$this->check_current_query = true;
		}
		$this->last_query = $query;

		if ( is_string( $query ) && preg_match( '/^\s*UPDATE\s+.+\s+WHERE\s*$/is', $query ) ) {
			$this->last_error = 'PostgreSQL query rejected because UPDATE requires a non-empty WHERE condition.';
			return false;
		}

		$last_query_count = count( $this->queries ?? array() );
		$this->_do_query( $query );

		if ( $this->last_error ) {
			if ( $this->insert_id && in_array( $this->get_statement_keyword( $query ), array( 'insert', 'replace' ), true ) ) {
				$this->insert_id = 0;
			}

			$this->print_error();
			return false;
		}

		$statement_type = $this->get_statement_keyword( $query );
		if ( 'create' === $statement_type ) {
			$this->store_postgresql_create_table_charset_metadata( $query );
		} elseif ( 'drop' === $statement_type ) {
			$this->delete_postgresql_dropped_table_charset_metadata( $query );
		} elseif ( in_array( $statement_type, array( 'alter', 'truncate' ), true ) ) {
			$this->clear_all_postgresql_table_charset_cache();
		}

		if ( in_array( $statement_type, array( 'create', 'alter', 'truncate', 'drop' ), true ) ) {
			$return_val = true;
		} elseif ( in_array( $statement_type, array( 'insert', 'delete', 'update', 'replace' ), true ) ) {
			$this->rows_affected = $this->dbh->get_last_return_value();

			if ( in_array( $statement_type, array( 'insert', 'replace' ), true ) ) {
				$this->insert_id = $this->dbh->get_insert_id();
			}

			$return_val = $this->rows_affected;
		} else {
			$num_rows = 0;

			if ( is_array( $this->result ) ) {
				$this->last_result = $this->result;
				$num_rows          = count( $this->result );
			}

			$this->num_rows = $num_rows;
			$return_val     = $num_rows;
		}

		if ( defined( 'SAVEQUERIES' ) && SAVEQUERIES && isset( $this->queries[ $last_query_count ] ) ) {
			$this->queries[ $last_query_count ]['postgresql_queries'] = $this->dbh->get_last_postgresql_queries();
		}

		return $return_val;
	}

	/**
	 * Method to return what the database can do.
	 *
	 * @param string $db_cap The feature to check.
	 * @return bool Whether the database feature is supported.
	 */
	public function has_cap( $db_cap ) {
		switch ( strtolower( $db_cap ) ) {
			case 'collation':
			case 'group_concat':
			case 'subqueries':
			case 'identifier_placeholders':
			case 'utf8mb4':
			case 'utf8mb4_520':
				return true;
			case 'set_charset':
				return version_compare( $this->db_version(), '5.0.7', '>=' );
		}

		return false;
	}

	/**
	 * Method to return database version number.
	 *
	 * @return string PostgreSQL compatibility version.
	 */
	public function db_version() {
		return '8.0';
	}

	/**
	 * Returns the server info string.
	 *
	 * @return string Server info.
	 */
	public function db_server_info() {
		if ( $this->dbh instanceof WP_PostgreSQL_Driver ) {
			return $this->dbh->get_postgresql_version();
		}
		return 'PostgreSQL backend pending connection';
	}

	/**
	 * Internal function to perform the PostgreSQL query call.
	 *
	 * @param string $query The query to run.
	 */
	private function _do_query( $query ) {
		if ( defined( 'SAVEQUERIES' ) && SAVEQUERIES ) {
			$this->timer_start();
		}

		try {
			$this->result = $this->dbh->query( $query );
		} catch ( Throwable $e ) {
			$this->last_error = $this->format_error_message( $e );
		}

		++$this->num_queries;

		if ( defined( 'SAVEQUERIES' ) && SAVEQUERIES ) {
			$this->log_query(
				$query,
				$this->timer_stop(),
				$this->get_caller(),
				$this->time_start,
				array()
			);
		}
	}

	/**
	 * Method to set the class variable $col_info.
	 *
	 * This overrides wpdb::load_col_info(), which uses mysqli metadata.
	 */
	protected function load_col_info() {
		if ( $this->col_info ) {
			return;
		}
		$this->col_info = array();
		foreach ( $this->dbh->get_last_column_meta() as $column ) {
			$this->col_info[] = (object) array(
				'name'       => $column['name'],
				'orgname'    => $column['mysqli:orgname'],
				'table'      => $column['table'],
				'orgtable'   => $column['mysqli:orgtable'],
				'def'        => '',
				'db'         => $column['mysqli:db'],
				'catalog'    => 'def',
				'max_length' => 0,
				'length'     => $column['len'],
				'charsetnr'  => $column['mysqli:charsetnr'],
				'flags'      => $column['mysqli:flags'],
				'type'       => $column['mysqli:type'],
				'decimals'   => $column['precision'],
			);
		}
	}

	/**
	 * Builds PostgreSQL connection options from wpdb constructor state.
	 *
	 * @return array
	 */
	private function get_connection_options() {
		$host = $this->dbhost;
		$port = null;

		$host_data = $this->parse_db_host( $this->dbhost );
		if ( $host_data ) {
			list( $host, $port, $socket ) = $host_data;

			if ( null !== $socket && '' !== $socket ) {
				$host        = $this->get_postgresql_socket_host( $socket );
				$socket_port = $this->get_postgresql_socket_port( $socket );
				if ( null === $port && null !== $socket_port ) {
					$port = $socket_port;
				}
			}
		}

		$options = array(
			'host'     => $host,
			'port'     => $port,
			'dbname'   => $this->dbname,
			'user'     => $this->dbuser,
			'password' => $this->dbpassword,
		);

		if ( isset( $GLOBALS['@pdo'] ) && $GLOBALS['@pdo'] instanceof PDO && $this->is_postgresql_pdo( $GLOBALS['@pdo'] ) ) {
			$options['pdo'] = $GLOBALS['@pdo'];
		}

		return $options;
	}

	/**
	 * Returns the libpq socket directory when DB_HOST includes a socket file.
	 *
	 * @param string $socket Socket path or directory.
	 * @return string PostgreSQL host option.
	 */
	private function get_postgresql_socket_host( $socket ) {
		$socket_file = basename( $socket );
		if ( 0 === strpos( $socket_file, '.s.PGSQL.' ) ) {
			return dirname( $socket );
		}
		return $socket;
	}

	/**
	 * Returns the PostgreSQL port encoded in a socket file path.
	 *
	 * @param string $socket Socket path or directory.
	 * @return int|null PostgreSQL port.
	 */
	private function get_postgresql_socket_port( $socket ) {
		$prefix      = '.s.PGSQL.';
		$socket_file = basename( $socket );
		if ( 0 !== strpos( $socket_file, $prefix ) ) {
			return null;
		}

		$port = substr( $socket_file, strlen( $prefix ) );
		return ctype_digit( $port ) ? (int) $port : null;
	}

	/**
	 * Checks whether a reusable PDO object is PostgreSQL-backed.
	 *
	 * @param PDO $pdo PDO instance.
	 * @return bool Whether the PDO driver is PostgreSQL.
	 */
	private function is_postgresql_pdo( PDO $pdo ) {
		try {
			return 'pgsql' === $pdo->getAttribute( PDO::ATTR_DRIVER_NAME );
		} catch ( Throwable $e ) {
			return false;
		}
	}

	/**
	 * Returns the first SQL statement keyword.
	 *
	 * @param string $query SQL query.
	 * @return string Lowercase statement keyword, or empty string.
	 */
	private function get_statement_keyword( $query ) {
		$length = strlen( $query );
		$i      = 0;

		while ( $i < $length ) {
			$char = $query[ $i ];
			if ( ctype_space( $char ) ) {
				++$i;
				continue;
			}

			if ( '-' === $char && $i + 1 < $length && '-' === $query[ $i + 1 ] ) {
				$i += 2;
				while ( $i < $length && "\n" !== $query[ $i ] ) {
					++$i;
				}
				continue;
			}

			if ( '/' === $char && $i + 1 < $length && '*' === $query[ $i + 1 ] ) {
				$i += 2;
				while ( $i + 1 < $length && ! ( '*' === $query[ $i ] && '/' === $query[ $i + 1 ] ) ) {
					++$i;
				}
				$i += 2;
				continue;
			}

			break;
		}

		$start = $i;
		while ( $i < $length && ( ctype_alpha( $query[ $i ] ) || '_' === $query[ $i ] ) ) {
			++$i;
		}

		return strtolower( substr( $query, $start, $i - $start ) );
	}

	/**
	 * Format PostgreSQL driver error message.
	 *
	 * @param Throwable $e Error.
	 * @return string Error message.
	 */
	private function format_error_message( Throwable $e ) {
		return $e->getMessage();
	}
}
