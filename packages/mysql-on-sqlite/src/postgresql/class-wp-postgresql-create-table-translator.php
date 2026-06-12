<?php

/**
 * Translates WordPress MySQL CREATE TABLE statements to PostgreSQL DDL.
 *
 * This is intentionally scoped to the WordPress install schema. It consumes the
 * existing MySQL parser AST and refuses unsupported CREATE TABLE forms instead
 * of rewriting SQL with ad hoc string parsing.
 */
class WP_PostgreSQL_Create_Table_Translator {
	const MYSQL_GRAMMAR_PATH = __DIR__ . '/../mysql/mysql-grammar.php';

	const CHARSET_DEFAULT_COLLATION_MAP = array(
		'ascii'   => 'ascii_general_ci',
		'big5'    => 'big5_chinese_ci',
		'binary'  => 'binary',
		'cp1251'  => 'cp1251_general_ci',
		'hebrew'  => 'hebrew_general_ci',
		'koi8r'   => 'koi8r_general_ci',
		'latin1'  => 'latin1_swedish_ci',
		'tis620'  => 'tis620_thai_ci',
		'ujis'    => 'ujis_japanese_ci',
		'utf8'    => 'utf8_general_ci',
		'utf8mb4' => 'utf8mb4_unicode_ci',
	);

	/**
	 * Reusable MySQL grammar.
	 *
	 * @var WP_Parser_Grammar|null
	 */
	private static $mysql_grammar = null;

	/**
	 * Parse and translate all CREATE TABLE statements in a schema string.
	 *
	 * @param string $sql MySQL schema SQL.
	 * @return string[] PostgreSQL DDL statements.
	 */
	public function translate_schema( string $sql ): array {
		$parser     = $this->create_parser( $sql );
		$statements = array();

		while ( $parser->next_query() ) {
			$ast = $parser->get_query_ast();
			if ( ! $ast || ! $ast->has_child() ) {
				continue;
			}

			foreach ( $this->translate( $ast ) as $statement ) {
				$statements[] = $statement;
			}
		}

		return $statements;
	}

	/**
	 * Translate a parsed CREATE TABLE statement.
	 *
	 * @param WP_Parser_Node $create_statement Parsed query/createStatement/createTable node.
	 * @return string[] PostgreSQL DDL statements.
	 */
	public function translate( WP_Parser_Node $create_statement ): array {
		$create_table = $this->get_create_table_node( $create_statement );
		if ( ! $create_table ) {
			throw new InvalidArgumentException( 'Only CREATE TABLE statements are supported by the PostgreSQL DDL translator.' );
		}

		$element_list = $create_table->get_first_child_node( 'tableElementList' );
		if ( ! $element_list ) {
			throw new InvalidArgumentException( 'CREATE TABLE ... AS SELECT is not supported by the PostgreSQL DDL translator.' );
		}

		$table_name    = $this->get_table_name( $create_table );
		$if_not_exists = $create_table->has_child_node( 'ifNotExists' );
		$columns       = array();
		$constraints   = array();
		$indexes       = array();

		foreach ( $element_list->get_child_nodes( 'tableElement' ) as $table_element ) {
			$column_definition = $table_element->get_first_child_node( 'columnDefinition' );
			if ( $column_definition ) {
				$columns[] = $this->translate_column_definition( $column_definition );
				continue;
			}

			$table_constraint = $table_element->get_first_child_node( 'tableConstraintDef' );
			if ( ! $table_constraint ) {
				throw new InvalidArgumentException( 'Unsupported CREATE TABLE element.' );
			}

			if ( $table_constraint->has_child_token( WP_MySQL_Lexer::PRIMARY_SYMBOL ) ) {
				$constraints[] = 'PRIMARY KEY (' . implode( ', ', $this->quote_key_parts( $table_constraint ) ) . ')';
				continue;
			}

			$indexes[] = $this->translate_secondary_index( $table_constraint, $table_name, $if_not_exists );
		}

		$definitions = array_merge( $columns, $constraints );
		if ( empty( $definitions ) ) {
			throw new InvalidArgumentException( 'CREATE TABLE statement does not define any columns.' );
		}

		$create_sql = sprintf(
			'CREATE %sTABLE %s%s (%s%s%s)',
			$create_table->has_child_token( WP_MySQL_Lexer::TEMPORARY_SYMBOL ) ? 'TEMPORARY ' : '',
			$if_not_exists ? 'IF NOT EXISTS ' : '',
			$this->quote_identifier( $table_name ),
			"\n  ",
			implode( ",\n  ", $definitions ),
			"\n"
		);

		return array_merge( array( $create_sql ), $indexes );
	}

	/**
	 * Extract MySQL charset metadata from CREATE TABLE statements.
	 *
	 * @param string $sql MySQL schema SQL.
	 * @return array[] Table metadata.
	 */
	public function extract_schema_metadata( string $sql, bool $include_indexes = false ): array {
		$parser = $this->create_parser( $sql );
		$tables = array();

		while ( $parser->next_query() ) {
			$ast = $parser->get_query_ast();
			if ( ! $ast || ! $ast->has_child() ) {
				continue;
			}

			$create_table = $this->get_create_table_node( $ast );
			if ( $create_table ) {
				$tables[] = $this->extract_create_table_metadata( $create_table, $include_indexes );
			}
		}

		return $tables;
	}

	/**
	 * Create a parser for a MySQL SQL string.
	 *
	 * @param string $sql MySQL SQL.
	 * @return WP_MySQL_Parser Parser instance.
	 */
	private function create_parser( string $sql ): WP_MySQL_Parser {
		$lexer  = new WP_MySQL_Lexer( $sql, 80038, array() );
		$tokens = $lexer instanceof WP_MySQL_Native_Lexer
			? $lexer->native_token_stream()
			: $lexer->remaining_tokens();

		return new WP_MySQL_Parser( $this->get_mysql_grammar(), $tokens );
	}

	/**
	 * Get the parser grammar.
	 *
	 * @return WP_Parser_Grammar MySQL grammar.
	 */
	private function get_mysql_grammar(): WP_Parser_Grammar {
		if ( null === self::$mysql_grammar ) {
			self::$mysql_grammar = new WP_Parser_Grammar( require self::MYSQL_GRAMMAR_PATH );
		}

		return self::$mysql_grammar;
	}

	/**
	 * Locate a createTable node from accepted AST entry points.
	 *
	 * @param WP_Parser_Node $node Parsed node.
	 * @return WP_Parser_Node|null createTable node.
	 */
	private function get_create_table_node( WP_Parser_Node $node ): ?WP_Parser_Node {
		if ( 'createTable' === $node->rule_name ) {
			return $node;
		}

		if ( 'createStatement' === $node->rule_name ) {
			return $node->get_first_child_node( 'createTable' );
		}

		$simple_statement = $node->get_first_child_node( 'simpleStatement' );
		if ( $simple_statement ) {
			$create_statement = $simple_statement->get_first_child_node( 'createStatement' );
			return $create_statement ? $create_statement->get_first_child_node( 'createTable' ) : null;
		}

		return null;
	}

	/**
	 * Translate a MySQL column definition.
	 *
	 * @param WP_Parser_Node $column_definition Column definition node.
	 * @return string PostgreSQL column definition.
	 */
	private function translate_column_definition( WP_Parser_Node $column_definition ): string {
		$name             = $this->get_identifier_value( $column_definition->get_first_child_node( 'fieldIdentifier' ) );
		$field_definition = $column_definition->get_first_child_node( 'fieldDefinition' );
		if ( ! $field_definition ) {
			throw new InvalidArgumentException( 'Column definition is missing a field definition.' );
		}

		$is_auto_increment = null !== $field_definition->get_first_descendant_token( WP_MySQL_Lexer::AUTO_INCREMENT_SYMBOL );
		$parts             = array(
			$this->quote_identifier( $name ),
			$this->translate_data_type( $field_definition->get_first_child_node( 'dataType' ), $is_auto_increment ),
		);

		foreach ( $field_definition->get_child_nodes( 'columnAttribute' ) as $attribute ) {
			if ( $attribute->has_child_token( WP_MySQL_Lexer::AUTO_INCREMENT_SYMBOL ) ) {
				continue;
			}

			if ( $attribute->has_child_token( WP_MySQL_Lexer::NOT_SYMBOL ) ) {
				$parts[] = 'NOT NULL';
				continue;
			}

			if ( $attribute->has_child_token( WP_MySQL_Lexer::DEFAULT_SYMBOL ) ) {
				$parts[] = $this->translate_default_attribute( $attribute );
			}
		}

		return implode( ' ', $parts );
	}

	/**
	 * Translate a MySQL data type.
	 *
	 * @param WP_Parser_Node|null $data_type         Data type node.
	 * @param bool                $is_auto_increment Whether AUTO_INCREMENT is present.
	 * @return string PostgreSQL data type.
	 */
	private function translate_data_type( ?WP_Parser_Node $data_type, bool $is_auto_increment ): string {
		if ( ! $data_type ) {
			throw new InvalidArgumentException( 'Column definition is missing a data type.' );
		}

		$type_token = $data_type->get_first_child_token();
		if ( ! $type_token ) {
			throw new InvalidArgumentException( 'Column data type is empty.' );
		}

		$type = strtolower( $type_token->get_value() );
		if ( 'bigint' === $type ) {
			$postgresql_type = 'bigint';
		} elseif ( in_array( $type, array( 'int', 'integer', 'mediumint', 'smallint', 'tinyint' ), true ) ) {
			$postgresql_type = 'integer';
		} elseif ( in_array( $type, array( 'varchar', 'char' ), true ) ) {
			$length          = $this->get_field_length( $data_type );
			$postgresql_type = $length ? sprintf( '%s(%d)', $type, $length ) : $type;
		} elseif ( in_array( $type, array( 'tinytext', 'text', 'mediumtext', 'longtext', 'datetime', 'timestamp', 'date', 'time', 'year', 'geometrycollection', 'geomcollection' ), true ) ) {
			$postgresql_type = 'text';
		} elseif ( in_array( $type, array( 'binary', 'varbinary', 'tinyblob', 'blob', 'mediumblob', 'longblob' ), true ) ) {
			$postgresql_type = 'bytea';
		} elseif ( in_array( $type, array( 'float', 'double' ), true ) ) {
			$precision_fragment = $this->get_numeric_precision_fragment( $data_type );
			$postgresql_type    = '' === $precision_fragment ? 'double precision' : 'numeric' . $precision_fragment;
		} elseif ( in_array( $type, array( 'decimal', 'numeric' ), true ) ) {
			$postgresql_type = 'numeric' . $this->get_numeric_precision_fragment( $data_type );
		} else {
			throw new InvalidArgumentException( sprintf( 'Unsupported MySQL column type for PostgreSQL install DDL: %s.', $type ) );
		}

		if ( $is_auto_increment ) {
			return $postgresql_type . ' GENERATED BY DEFAULT AS IDENTITY';
		}

		return $postgresql_type;
	}

	/**
	 * Translate a simple DEFAULT attribute.
	 *
	 * @param WP_Parser_Node $attribute Column attribute node.
	 * @return string PostgreSQL DEFAULT clause.
	 */
	private function translate_default_attribute( WP_Parser_Node $attribute ): string {
		$value_token = $attribute->get_first_descendant_token( WP_MySQL_Lexer::NULL_SYMBOL );
		if ( $value_token ) {
			return 'DEFAULT NULL';
		}

		foreach ( $attribute->get_descendant_tokens() as $token ) {
			if ( WP_MySQL_Lexer::DEFAULT_SYMBOL === $token->id ) {
				continue;
			}

			if (
				WP_MySQL_Lexer::SINGLE_QUOTED_TEXT === $token->id
				|| WP_MySQL_Lexer::DOUBLE_QUOTED_TEXT === $token->id
				|| WP_MySQL_Lexer::INT_NUMBER === $token->id
				|| WP_MySQL_Lexer::LONG_NUMBER === $token->id
				|| WP_MySQL_Lexer::ULONGLONG_NUMBER === $token->id
				|| WP_MySQL_Lexer::DECIMAL_NUMBER === $token->id
				|| WP_MySQL_Lexer::FLOAT_NUMBER === $token->id
			) {
				return 'DEFAULT ' . $this->quote_string_literal( $token->get_value() );
			}
		}

		throw new InvalidArgumentException( 'Unsupported column DEFAULT expression.' );
	}

	/**
	 * Translate a non-primary MySQL key to a PostgreSQL CREATE INDEX statement.
	 *
	 * @param WP_Parser_Node $table_constraint Table constraint node.
	 * @param string         $table_name       Table name.
	 * @param bool           $if_not_exists    Whether CREATE TABLE used IF NOT EXISTS.
	 * @return string PostgreSQL CREATE INDEX statement.
	 */
	private function translate_secondary_index( WP_Parser_Node $table_constraint, string $table_name, bool $if_not_exists ): string {
		$index_name_node = $table_constraint->get_first_child_node( 'indexNameAndType' );
		$index_name      = $index_name_node ? $this->get_identifier_value( $index_name_node->get_first_child_node( 'indexName' ) ) : null;
		if ( null === $index_name || '' === $index_name ) {
			$key_parts  = $this->get_key_parts( $table_constraint );
			$index_name = $key_parts[0];
		}

		return sprintf(
			'CREATE %sINDEX %s%s ON %s (%s)',
			$table_constraint->has_child_token( WP_MySQL_Lexer::UNIQUE_SYMBOL ) ? 'UNIQUE ' : '',
			$if_not_exists ? 'IF NOT EXISTS ' : '',
			$this->quote_identifier( $table_name . '__' . $index_name ),
			$this->quote_identifier( $table_name ),
			implode( ', ', $this->quote_key_parts( $table_constraint ) )
		);
	}

	/**
	 * Get quoted key parts from a MySQL key constraint.
	 *
	 * @param WP_Parser_Node $table_constraint Table constraint node.
	 * @return string[] Quoted PostgreSQL column names.
	 */
	private function quote_key_parts( WP_Parser_Node $table_constraint ): array {
		return array_map( array( $this, 'quote_identifier' ), $this->get_key_parts( $table_constraint ) );
	}

	/**
	 * Get key part column names.
	 *
	 * Prefix lengths, e.g. meta_key(191), are deliberately ignored for the
	 * initial WordPress install slice.
	 *
	 * @param WP_Parser_Node $table_constraint Table constraint node.
	 * @return string[] Column names.
	 */
	private function get_key_parts( WP_Parser_Node $table_constraint ): array {
		$key_parts = array();

		foreach ( $table_constraint->get_descendant_nodes( 'keyPart' ) as $key_part ) {
			$key_parts[] = $this->get_identifier_value( $key_part->get_first_child_node( 'identifier' ) );
		}

		if ( empty( $key_parts ) ) {
			throw new InvalidArgumentException( 'Index definition does not contain any key parts.' );
		}

		return $key_parts;
	}

	/**
	 * Get a CREATE TABLE table name.
	 *
	 * @param WP_Parser_Node $create_table Create table node.
	 * @return string Table name.
	 */
	private function get_table_name( WP_Parser_Node $create_table ): string {
		return $this->get_identifier_value( $create_table->get_first_child_node( 'tableName' ) );
	}

	/**
	 * Get the first identifier value in a node.
	 *
	 * @param WP_Parser_Node|null $node Node containing an identifier.
	 * @return string Identifier value.
	 */
	private function get_identifier_value( ?WP_Parser_Node $node ): string {
		if ( ! $node ) {
			throw new InvalidArgumentException( 'Expected identifier node.' );
		}

		$tokens = $node->get_descendant_tokens();
		foreach ( $tokens as $token ) {
			if ( WP_MySQL_Lexer::IDENTIFIER === $token->id || WP_MySQL_Lexer::BACK_TICK_QUOTED_ID === $token->id ) {
				return $token->get_value();
			}
		}

		if ( 1 === count( $tokens ) && '' !== $tokens[0]->get_value() ) {
			return $tokens[0]->get_value();
		}

		throw new InvalidArgumentException( 'Expected identifier token.' );
	}

	/**
	 * Extract metadata for a CREATE TABLE statement.
	 *
	 * @param WP_Parser_Node $create_table Create table node.
	 * @return array Table metadata.
	 */
	private function extract_create_table_metadata( WP_Parser_Node $create_table, bool $include_indexes = false ): array {
		$table_name    = $this->get_table_name( $create_table );
		$charset       = $this->get_table_charset_and_collation( $create_table );
		$columns       = array();
		$column_types  = array();
		$indexes       = array();
		$ordinal       = 1;
		$index_ordinal = 1;

		list ( $table_charset, $table_collation ) = $charset;

		$element_list = $create_table->get_first_child_node( 'tableElementList' );
		if ( ! $element_list ) {
			return array(
				'table_name' => $table_name,
				'columns'    => array(),
			);
		}

		foreach ( $element_list->get_child_nodes( 'tableElement' ) as $table_element ) {
			$column_definition = $table_element->get_first_child_node( 'columnDefinition' );
			if ( $column_definition ) {
				$name             = $this->get_identifier_value( $column_definition->get_first_child_node( 'fieldIdentifier' ) );
				$field_definition = $column_definition->get_first_child_node( 'fieldDefinition' );
				$data_type        = $field_definition ? $field_definition->get_first_child_node( 'dataType' ) : null;
				$column_type      = $this->get_mysql_column_type( $data_type, $field_definition );

				list ( $charset, $collation ) = $this->get_column_charset_and_collation(
					$field_definition,
					$this->get_base_mysql_column_type( $column_type ),
					$table_charset,
					$table_collation
				);

				$column_metadata = array(
					'name'      => $name,
					'type'      => $column_type,
					'charset'   => $charset,
					'collation' => $collation,
					'ordinal'   => $ordinal,
				);

				if ( $include_indexes ) {
					$column_metadata['nullable'] = $field_definition && $field_definition->get_first_descendant_token( WP_MySQL_Lexer::NOT_SYMBOL ) ? 'NO' : 'YES';
					$column_metadata['default']  = $field_definition ? $this->get_column_default_metadata( $field_definition ) : null;
					$column_metadata['extra']    = $field_definition && $field_definition->get_first_descendant_token( WP_MySQL_Lexer::AUTO_INCREMENT_SYMBOL ) ? 'auto_increment' : '';
				}

				$columns[]                           = $column_metadata;
				$column_types[ strtolower( $name ) ] = $column_type;
				++$ordinal;
				continue;
			}

			if ( $include_indexes ) {
				$table_constraint = $table_element->get_first_child_node( 'tableConstraintDef' );
				if ( $table_constraint ) {
					$indexes[] = $this->extract_index_metadata( $table_constraint, $index_ordinal, $column_types );
					++$index_ordinal;
				}
			}
		}

		$metadata = array(
			'table_name' => $table_name,
			'columns'    => $columns,
		);

		if ( $include_indexes ) {
			$metadata['indexes'] = $indexes;
		}

		return $metadata;
	}

	/**
	 * Extract metadata for a table index.
	 *
	 * @param WP_Parser_Node $table_constraint Table constraint node.
	 * @param int            $index_ordinal    Index ordinal.
	 * @param array          $column_types     Column types keyed by lowercase name.
	 * @return array Index metadata.
	 */
	private function extract_index_metadata( WP_Parser_Node $table_constraint, int $index_ordinal, array $column_types ): array {
		$is_primary = $table_constraint->has_child_token( WP_MySQL_Lexer::PRIMARY_SYMBOL );
		$key_parts  = $this->get_key_part_metadata( $table_constraint, $column_types );
		$key_name   = $is_primary ? 'PRIMARY' : $this->get_index_name( $table_constraint, $key_parts );

		return array(
			'name'       => $key_name,
			'ordinal'    => $index_ordinal,
			'non_unique' => $is_primary || $table_constraint->has_child_token( WP_MySQL_Lexer::UNIQUE_SYMBOL ) ? '0' : '1',
			'index_type' => $this->get_mysql_index_type_metadata( $table_constraint ),
			'columns'    => $key_parts,
		);
	}

	/**
	 * Get an index name from a table constraint.
	 *
	 * @param WP_Parser_Node $table_constraint Table constraint node.
	 * @param array          $key_parts        Key part metadata.
	 * @return string Index name.
	 */
	private function get_index_name( WP_Parser_Node $table_constraint, array $key_parts ): string {
		$index_name_node = $table_constraint->get_first_child_node( 'indexNameAndType' );
		$index_name_node = $index_name_node ? $index_name_node->get_first_child_node( 'indexName' ) : $table_constraint->get_first_child_node( 'indexName' );

		if ( $index_name_node ) {
			return $this->get_identifier_value( $index_name_node );
		}

		return (string) $key_parts[0]['column_name'];
	}

	/**
	 * Get MySQL SHOW INDEX Index_type metadata.
	 *
	 * @param WP_Parser_Node $table_constraint Table constraint node.
	 * @return string Index type.
	 */
	private function get_mysql_index_type_metadata( WP_Parser_Node $table_constraint ): string {
		if ( $table_constraint->has_child_token( WP_MySQL_Lexer::FULLTEXT_SYMBOL ) ) {
			return 'FULLTEXT';
		}

		if ( $table_constraint->has_child_token( WP_MySQL_Lexer::SPATIAL_SYMBOL ) ) {
			return 'SPATIAL';
		}

		return 'BTREE';
	}

	/**
	 * Get key part metadata from a MySQL key constraint.
	 *
	 * @param WP_Parser_Node $table_constraint Table constraint node.
	 * @param array          $column_types     Column types keyed by lowercase name.
	 * @return array[] Key part metadata.
	 */
	private function get_key_part_metadata( WP_Parser_Node $table_constraint, array $column_types ): array {
		$key_parts = array();

		foreach ( $table_constraint->get_descendant_nodes( 'keyPart' ) as $key_part ) {
			$column_name = $this->get_identifier_value( $key_part->get_first_child_node( 'identifier' ) );
			$sub_part    = $this->get_field_length( $key_part );
			if ( null === $sub_part && ! $table_constraint->has_child_token( WP_MySQL_Lexer::FULLTEXT_SYMBOL ) ) {
				$sub_part = $this->get_implicit_index_sub_part( $column_name, $column_types );
			}

			$key_parts[] = array(
				'column_name'  => $column_name,
				'seq_in_index' => count( $key_parts ) + 1,
				'sub_part'     => $sub_part,
			);
		}

		if ( empty( $key_parts ) ) {
			throw new InvalidArgumentException( 'Index definition does not contain any key parts.' );
		}

		return $key_parts;
	}

	/**
	 * Get the implicit MySQL prefix length for oversized utf8mb4 string indexes.
	 *
	 * @param string $column_name  Column name.
	 * @param array  $column_types Column types keyed by lowercase name.
	 * @return int|null Sub part length.
	 */
	private function get_implicit_index_sub_part( string $column_name, array $column_types ): ?int {
		$column_type = $column_types[ strtolower( $column_name ) ] ?? null;
		if ( ! is_string( $column_type ) || ! preg_match( '/^(?:var)?char\((\d+)\)$/i', $column_type, $matches ) ) {
			return null;
		}

		$length = (int) $matches[1];
		return $length > 191 ? 191 : null;
	}

	/**
	 * Get a MySQL default value for DESCRIBE metadata.
	 *
	 * @param WP_Parser_Node $field_definition Field definition node.
	 * @return string|null Default value.
	 */
	private function get_column_default_metadata( WP_Parser_Node $field_definition ): ?string {
		foreach ( $field_definition->get_child_nodes( 'columnAttribute' ) as $attribute ) {
			if ( ! $attribute->has_child_token( WP_MySQL_Lexer::DEFAULT_SYMBOL ) ) {
				continue;
			}

			if ( $attribute->has_child_token( WP_MySQL_Lexer::NULL_SYMBOL ) ) {
				return null;
			}

			foreach ( $attribute->get_descendant_tokens() as $token ) {
				if ( WP_MySQL_Lexer::DEFAULT_SYMBOL === $token->id ) {
					continue;
				}

				return $token->get_value();
			}
		}

		return null;
	}

	/**
	 * Extract table default charset and collation.
	 *
	 * @param WP_Parser_Node $create_table Create table node.
	 * @return array{string, string} Charset and collation.
	 */
	private function get_table_charset_and_collation( WP_Parser_Node $create_table ): array {
		$charset   = 'utf8mb4';
		$collation = null;

		foreach ( $create_table->get_child_nodes( 'createTableOptions' ) as $options ) {
			foreach ( $options->get_child_nodes( 'createTableOption' ) as $option ) {
				$default_charset = $option->get_first_child_node( 'defaultCharset' );
				if ( $default_charset ) {
					$charset_name = $default_charset->get_first_child_node( 'charsetName' );
					if ( $charset_name ) {
						$charset = $this->normalize_charset( $this->get_node_value( $charset_name ) );
					}
				}

				$default_collation = $option->get_first_child_node( 'defaultCollation' );
				if ( $default_collation ) {
					$collation_name = $default_collation->get_first_child_node( 'collationName' );
					if ( $collation_name ) {
						$collation = $this->normalize_collation( $this->get_node_value( $collation_name ) );
					}
				}
			}
		}

		if ( null === $collation ) {
			$collation = $this->get_default_collation_for_charset( $charset );
		} else {
			$charset = $this->get_charset_from_collation( $collation );
		}

		return array( $charset, $collation );
	}

	/**
	 * Extract MySQL column charset and collation metadata.
	 *
	 * @param WP_Parser_Node|null $field_definition Field definition node.
	 * @param string              $data_type Column data type.
	 * @param string              $table_charset Table default charset.
	 * @param string              $table_collation Table default collation.
	 * @return array{string|null, string|null} Charset and collation.
	 */
	private function get_column_charset_and_collation( ?WP_Parser_Node $field_definition, string $data_type, string $table_charset, string $table_collation ): array {
		if ( ! $field_definition || ! $this->is_mysql_character_data_type( $data_type ) ) {
			return array( null, null );
		}

		$charset   = null;
		$collation = null;
		$is_binary = false;

		$charset_node = $field_definition->get_first_descendant_node( 'charsetWithOptBinary' );
		if ( $charset_node ) {
			$charset_name = $charset_node->get_first_child_node( 'charsetName' );
			if ( $charset_name ) {
				$charset = $this->normalize_charset( $this->get_node_value( $charset_name ) );
			} elseif ( $charset_node->has_child_token( WP_MySQL_Lexer::ASCII_SYMBOL ) ) {
				$charset = 'latin1';
			} elseif ( $charset_node->has_child_token( WP_MySQL_Lexer::UNICODE_SYMBOL ) ) {
				$charset = 'ucs2';
			}

			if ( $charset_node->has_child_token( WP_MySQL_Lexer::BINARY_SYMBOL ) ) {
				$is_binary = true;
			}
		}

		$collation_node = $field_definition->get_first_descendant_node( 'collationName' );
		if ( $collation_node ) {
			$collation = $this->normalize_collation( $this->get_node_value( $collation_node ) );
		}

		if ( null === $charset && null === $collation ) {
			$charset   = $table_charset;
			$collation = $table_collation;
		} elseif ( null === $collation ) {
			$collation = $is_binary ? $charset . '_bin' : $this->get_default_collation_for_charset( $charset );
		} elseif ( null === $charset ) {
			$charset = $this->get_charset_from_collation( $collation );
		}

		return array( $charset, $collation );
	}

	/**
	 * Get a MySQL column type for metadata.
	 *
	 * @param WP_Parser_Node|null $data_type        Data type node.
	 * @param WP_Parser_Node|null $field_definition Field definition node.
	 * @return string MySQL column type.
	 */
	private function get_mysql_column_type( ?WP_Parser_Node $data_type, ?WP_Parser_Node $field_definition = null ): string {
		if ( ! $data_type ) {
			throw new InvalidArgumentException( 'Column definition is missing a data type.' );
		}

		$type_token = $data_type->get_first_child_token();
		if ( ! $type_token ) {
			throw new InvalidArgumentException( 'Column data type is empty.' );
		}

		$type = strtolower( $type_token->get_value() );
		if ( 'integer' === $type ) {
			$type = 'int';
		}

		$numeric_precision = $this->get_numeric_precision_fragment( $data_type );
		if ( '' !== $numeric_precision && in_array( $type, array( 'decimal', 'double', 'float', 'numeric' ), true ) ) {
			$type .= $numeric_precision;
		}

		$length = $this->get_field_length( $data_type );
		if ( null !== $length && in_array( $type, array( 'bigint', 'binary', 'char', 'int', 'mediumint', 'smallint', 'tinyint', 'varbinary', 'varchar' ), true ) ) {
			$type = sprintf( '%s(%d)', $type, $length );
		}

		if ( $field_definition && $field_definition->get_first_descendant_token( WP_MySQL_Lexer::UNSIGNED_SYMBOL ) ) {
			$type .= ' unsigned';
		}

		return $type;
	}

	/**
	 * Get the base MySQL column type without length.
	 *
	 * @param string $column_type Column type.
	 * @return string Base type.
	 */
	private function get_base_mysql_column_type( string $column_type ): string {
		$length_position = strpos( $column_type, '(' );
		if ( false === $length_position ) {
			return strtolower( $column_type );
		}

		return strtolower( substr( $column_type, 0, $length_position ) );
	}

	/**
	 * Check whether a MySQL type has character set metadata.
	 *
	 * @param string $data_type Base MySQL data type.
	 * @return bool Whether the type is textual.
	 */
	private function is_mysql_character_data_type( string $data_type ): bool {
		return in_array(
			$data_type,
			array( 'char', 'varchar', 'tinytext', 'text', 'mediumtext', 'longtext', 'enum', 'set' ),
			true
		);
	}

	/**
	 * Normalize MySQL charset names for WordPress metadata expectations.
	 *
	 * @param string $charset Charset name.
	 * @return string Normalized charset.
	 */
	private function normalize_charset( string $charset ): string {
		$charset = strtolower( trim( $charset, "'\"` \t\n\r\0\x0B" ) );
		return 'utf8mb3' === $charset ? 'utf8' : $charset;
	}

	/**
	 * Normalize MySQL collation names for WordPress metadata expectations.
	 *
	 * @param string $collation Collation name.
	 * @return string Normalized collation.
	 */
	private function normalize_collation( string $collation ): string {
		$collation = strtolower( trim( $collation, "'\"` \t\n\r\0\x0B" ) );
		if ( 0 === strpos( $collation, 'utf8mb3_' ) ) {
			return 'utf8_' . substr( $collation, strlen( 'utf8mb3_' ) );
		}

		return $collation;
	}

	/**
	 * Get the charset prefix from a collation name.
	 *
	 * @param string $collation Collation name.
	 * @return string Charset name.
	 */
	private function get_charset_from_collation( string $collation ): string {
		$underscore = strpos( $collation, '_' );
		if ( false === $underscore ) {
			return $this->normalize_charset( $collation );
		}

		return $this->normalize_charset( substr( $collation, 0, $underscore ) );
	}

	/**
	 * Get the default MySQL collation for a charset.
	 *
	 * @param string $charset Charset name.
	 * @return string Collation name.
	 */
	private function get_default_collation_for_charset( string $charset ): string {
		$charset = $this->normalize_charset( $charset );
		if ( isset( self::CHARSET_DEFAULT_COLLATION_MAP[ $charset ] ) ) {
			return self::CHARSET_DEFAULT_COLLATION_MAP[ $charset ];
		}

		return $charset . '_general_ci';
	}

	/**
	 * Serialize a parser node value.
	 *
	 * @param WP_Parser_Node $node Parser node.
	 * @return string Node value.
	 */
	private function get_node_value( WP_Parser_Node $node ): string {
		$value = '';
		foreach ( $node->get_children() as $child ) {
			if ( $child instanceof WP_Parser_Node ) {
				$value .= $this->get_node_value( $child );
			} else {
				$value .= $child->get_value();
			}
		}

		return $value;
	}

	/**
	 * Get a numeric field length.
	 *
	 * @param WP_Parser_Node $node Node that may contain a fieldLength child.
	 * @return int|null Field length.
	 */
	private function get_field_length( WP_Parser_Node $node ): ?int {
		$field_length = $node->get_first_child_node( 'fieldLength' );
		if ( ! $field_length ) {
			return null;
		}

		$token = $field_length->get_first_descendant_token( WP_MySQL_Lexer::INT_NUMBER );
		return $token ? (int) $token->get_value() : null;
	}

	/**
	 * Get a numeric precision/scale SQL fragment.
	 *
	 * @param WP_Parser_Node $data_type Data type node.
	 * @return string Precision fragment, including parentheses, or empty string.
	 */
	private function get_numeric_precision_fragment( WP_Parser_Node $data_type ): string {
		$precision = $data_type->get_first_descendant_node( 'precision' );
		if ( $precision ) {
			return $this->get_node_value( $precision );
		}

		$field_length = $data_type->get_first_descendant_node( 'fieldLength' );
		return $field_length ? $this->get_node_value( $field_length ) : '';
	}

	/**
	 * Quote a PostgreSQL identifier.
	 *
	 * @param string $identifier Identifier.
	 * @return string Quoted identifier.
	 */
	private function quote_identifier( string $identifier ): string {
		return WP_PostgreSQL_Connection::quote_identifier_value( $identifier );
	}

	/**
	 * Quote a PostgreSQL string literal.
	 *
	 * @param string $value Literal value.
	 * @return string Quoted literal.
	 */
	private function quote_string_literal( string $value ): string {
		if ( false !== strpos( $value, "\0" ) ) {
			throw new InvalidArgumentException( 'PostgreSQL string literals cannot contain NUL bytes.' );
		}

		return "'" . str_replace( "'", "''", $value ) . "'";
	}
}
