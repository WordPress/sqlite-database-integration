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
		} elseif ( in_array( $type, array( 'tinytext', 'text', 'mediumtext', 'longtext', 'datetime', 'timestamp', 'date', 'time', 'year' ), true ) ) {
			$postgresql_type = 'text';
		} elseif ( in_array( $type, array( 'tinyblob', 'blob', 'mediumblob', 'longblob' ), true ) ) {
			$postgresql_type = 'bytea';
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
