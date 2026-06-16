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
	 * SQL modes active while tokenizing CREATE TABLE statements.
	 *
	 * @var string[]
	 */
	private $sql_modes;

	/**
	 * Constructor.
	 *
	 * @param string[] $sql_modes Active SQL modes.
	 */
	public function __construct( array $sql_modes = array() ) {
		$this->sql_modes = $sql_modes;
	}

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
		$foreign_key_ordinal = 1;
		$check_ordinal       = 1;

		foreach ( $element_list->get_child_nodes( 'tableElement' ) as $table_element ) {
			$column_definition = $table_element->get_first_child_node( 'columnDefinition' );
			if ( $column_definition ) {
				$columns[] = $this->translate_column_definition( $column_definition, $table_name, $foreign_key_ordinal, $check_ordinal );
				continue;
			}

			$table_constraint = $table_element->get_first_child_node( 'tableConstraintDef' );
			if ( ! $table_constraint ) {
				throw new InvalidArgumentException( 'Unsupported CREATE TABLE element.' );
			}

			if ( $table_constraint->get_first_child_node( 'checkConstraint' ) ) {
				$constraints[] = $this->translate_check_constraint_definition( $table_constraint, $table_name, $check_ordinal );
				continue;
			}

			$this->validate_mysql_table_constraint_index_options( $table_constraint );

			if ( $table_constraint->has_child_token( WP_MySQL_Lexer::PRIMARY_SYMBOL ) ) {
				$constraints[] = 'PRIMARY KEY (' . implode( ', ', $this->quote_key_parts( $table_constraint ) ) . ')';
				continue;
			}

			$index = $this->translate_secondary_index( $table_constraint, $table_name, $if_not_exists );
			if ( null !== $index ) {
				$indexes[] = $index;
			}
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
		$sql    = $this->normalize_parser_unsafe_long_character_aliases( $sql );
		$lexer  = new WP_MySQL_Lexer( $sql, 80038, $this->sql_modes );
		$tokens = $lexer instanceof WP_MySQL_Native_Lexer
			? $lexer->native_token_stream()
			: $lexer->remaining_tokens();

		return new WP_MySQL_Parser( $this->get_mysql_grammar(), $tokens );
	}

	/**
	 * Normalize LONG CHAR aliases that can make the parser fail to converge.
	 *
	 * SQLite treats these as MEDIUMTEXT metadata, same as LONG VARCHAR. Rewrite
	 * only the tokenized type alias so parsing remains bounded while downstream
	 * metadata normalization still sees a LONG-prefixed text alias.
	 *
	 * @param string $sql MySQL SQL.
	 * @return string SQL with parser-safe LONG character aliases.
	 */
	private function normalize_parser_unsafe_long_character_aliases( string $sql ): string {
		$lexer  = new WP_MySQL_Lexer( $sql, 80038, $this->sql_modes );
		$tokens = $lexer instanceof WP_MySQL_Native_Lexer
			? $lexer->native_token_stream()
			: $lexer->remaining_tokens();

		$rewritten = '';
		$cursor    = 0;
		$changed   = false;
		for ( $i = 0; isset( $tokens[ $i ] ) && WP_MySQL_Lexer::EOF !== $tokens[ $i ]->id; ++$i ) {
			if (
				WP_MySQL_Lexer::LONG_SYMBOL !== $tokens[ $i ]->id
				|| ! isset( $tokens[ $i + 1 ] )
				|| WP_MySQL_Lexer::CHAR_SYMBOL !== $tokens[ $i + 1 ]->id
			) {
				continue;
			}

			$start_token = $tokens[ $i ];
			$end_token   = $tokens[ $i + 1 ];
			if ( isset( $tokens[ $i + 2 ] ) && WP_MySQL_Lexer::VARYING_SYMBOL === $tokens[ $i + 2 ]->id ) {
				$end_token = $tokens[ $i + 2 ];
				$i         = $i + 2;
			} else {
				++$i;
			}

			$start     = $start_token->start;
			$end       = $end_token->start + $end_token->length;
			$rewritten .= substr( $sql, $cursor, $start - $cursor ) . 'LONG VARCHAR';
			$cursor    = $end;
			$changed   = true;
		}

		if ( ! $changed ) {
			return $sql;
		}

		return $rewritten . substr( $sql, $cursor );
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
	 * @param WP_Parser_Node $column_definition   Column definition node.
	 * @param string         $table_name          Table name.
	 * @param int            $foreign_key_ordinal Next inline foreign key ordinal.
	 * @param int            $check_ordinal       Next inline CHECK ordinal.
	 * @return string PostgreSQL column definition.
	 */
	private function translate_column_definition( WP_Parser_Node $column_definition, string $table_name, int &$foreign_key_ordinal, int &$check_ordinal ): string {
		$name             = $this->get_identifier_value( $column_definition->get_first_child_node( 'fieldIdentifier' ) );
		$field_definition = $column_definition->get_first_child_node( 'fieldDefinition' );
		if ( ! $field_definition ) {
			throw new InvalidArgumentException( 'Column definition is missing a field definition.' );
		}

		$data_type         = $field_definition->get_first_child_node( 'dataType' );
		$is_serial         = $this->is_serial_data_type( $data_type );
		$is_auto_increment = $is_serial || null !== $field_definition->get_first_descendant_token( WP_MySQL_Lexer::AUTO_INCREMENT_SYMBOL );
		$has_not_null      = false;
		$has_primary_key   = false;
		$has_unique_key    = false;
		$parts             = array(
			$this->quote_identifier( $name ),
			$this->translate_data_type( $data_type, $is_auto_increment ),
		);

		foreach ( $field_definition->get_child_nodes( 'columnAttribute' ) as $attribute ) {
			if ( $attribute->has_child_token( WP_MySQL_Lexer::AUTO_INCREMENT_SYMBOL ) ) {
				continue;
			}

			if ( $attribute->has_child_token( WP_MySQL_Lexer::NOT_SYMBOL ) ) {
				$has_not_null = true;
				$parts[]      = 'NOT NULL';
				continue;
			}

			if ( $attribute->has_child_token( WP_MySQL_Lexer::PRIMARY_SYMBOL ) ) {
				$has_primary_key = true;
				$parts[]         = 'PRIMARY KEY';
				continue;
			}

			if ( $attribute->has_child_token( WP_MySQL_Lexer::UNIQUE_SYMBOL ) ) {
				$has_unique_key = true;
				$parts[]        = 'UNIQUE';
				continue;
			}

			$check_constraint = $attribute->get_first_child_node( 'checkConstraint' );
			if ( $check_constraint ) {
				$parts[] = $this->translate_check_constraint_definition( $attribute, $table_name, $check_ordinal );
				continue;
			}

			if ( $attribute->get_first_child_node( 'constraintEnforcement' ) ) {
				$this->validate_check_constraint_enforcement( $attribute );
				continue;
			}

			if ( $attribute->has_child_token( WP_MySQL_Lexer::DEFAULT_SYMBOL ) ) {
				$parts[] = $this->translate_default_attribute( $attribute );
			}
		}

		if ( $is_serial ) {
			if ( ! $has_not_null && ! $has_primary_key ) {
				$parts[] = 'NOT NULL';
			}

			if ( ! $has_primary_key && ! $has_unique_key ) {
				$parts[] = 'UNIQUE';
			}
		}

		$references = $this->get_inline_references_node( $column_definition );
		if ( $references ) {
			$constraint_name = $this->get_implicit_foreign_key_constraint_name( $table_name, $foreign_key_ordinal );
			$parts[]         = 'CONSTRAINT ' . $this->quote_identifier( $constraint_name ) . ' ' . $this->translate_inline_references( $references );
			++$foreign_key_ordinal;
		}

		$check_constraint = $this->get_inline_check_constraint_node( $column_definition );
		if ( $check_constraint ) {
			$parts[] = $this->translate_check_constraint_definition( $check_constraint, $table_name, $check_ordinal );
		}

		return implode( ' ', $parts );
	}

	/**
	 * Get a MySQL inline REFERENCES node from a field definition.
	 *
	 * @param WP_Parser_Node $column_definition Column definition node.
	 * @return WP_Parser_Node|null REFERENCES node.
	 */
	private function get_inline_references_node( WP_Parser_Node $column_definition ): ?WP_Parser_Node {
		$check_or_references = $column_definition->get_first_child_node( 'checkOrReferences' );
		return $check_or_references ? $check_or_references->get_first_child_node( 'references' ) : null;
	}

	/**
	 * Get a MySQL inline CHECK node after a column definition.
	 *
	 * @param WP_Parser_Node|null $column_definition Column definition node.
	 * @return WP_Parser_Node|null Inline CHECK node.
	 */
	private function get_inline_check_constraint_node( ?WP_Parser_Node $column_definition ): ?WP_Parser_Node {
		$check_or_references = $column_definition ? $column_definition->get_first_child_node( 'checkOrReferences' ) : null;
		return $check_or_references ? $check_or_references->get_first_child_node( 'checkConstraint' ) : null;
	}

	/**
	 * Translate a MySQL CHECK constraint to PostgreSQL.
	 *
	 * @param WP_Parser_Node $node          Node containing a checkConstraint child.
	 * @param string         $table_name    Table name used for implicit constraint names.
	 * @param int            $check_ordinal Next implicit CHECK ordinal.
	 * @return string PostgreSQL CHECK constraint SQL.
	 */
	private function translate_check_constraint_definition( WP_Parser_Node $node, string $table_name, int &$check_ordinal ): string {
		$this->validate_check_constraint_enforcement( $node );

		$check_constraint = 'checkConstraint' === $node->rule_name ? $node : $node->get_first_child_node( 'checkConstraint' );
		if ( ! $check_constraint ) {
			throw new InvalidArgumentException( 'Expected CHECK constraint node.' );
		}

		$constraint_name = $this->get_check_constraint_name( $node, $table_name, $check_ordinal );
		$expression      = $this->translate_check_constraint_expression( $check_constraint );

		return sprintf(
			'CONSTRAINT %s CHECK (%s)',
			$this->quote_identifier( $constraint_name ),
			$expression
		);
	}

	/**
	 * Get the MySQL-compatible CHECK constraint name.
	 *
	 * @param WP_Parser_Node $node          Node containing an optional constraintName child.
	 * @param string         $table_name    Table name used for implicit constraint names.
	 * @param int            $check_ordinal Next implicit CHECK ordinal.
	 * @return string Constraint name.
	 */
	private function get_check_constraint_name( WP_Parser_Node $node, string $table_name, int &$check_ordinal ): string {
		$constraint_name = $node->get_first_child_node( 'constraintName' );
		if ( $constraint_name ) {
			return $this->get_identifier_value( $constraint_name->get_first_child_node( 'identifier' ) );
		}

		return $table_name . '_chk_' . $check_ordinal++;
	}

	/**
	 * Validate MySQL CHECK constraint enforcement clauses.
	 *
	 * PostgreSQL enforces CHECK constraints immediately. MySQL's explicit
	 * ENFORCED clause is equivalent here, but NOT ENFORCED cannot be preserved.
	 *
	 * @param WP_Parser_Node $node Node to inspect.
	 */
	private function validate_check_constraint_enforcement( WP_Parser_Node $node ): void {
		$enforcement = $node->get_first_child_node( 'constraintEnforcement' );
		if ( $enforcement && $enforcement->has_child_token( WP_MySQL_Lexer::NOT_SYMBOL ) ) {
			throw new InvalidArgumentException( 'Unsupported NOT ENFORCED CHECK constraint.' );
		}
	}

	/**
	 * Translate a MySQL CHECK expression to PostgreSQL SQL.
	 *
	 * @param WP_Parser_Node $check_constraint CHECK constraint node.
	 * @return string PostgreSQL expression SQL.
	 */
	private function translate_check_constraint_expression( WP_Parser_Node $check_constraint ): string {
		$expression = $check_constraint->get_first_descendant_node( 'expr' );
		if ( ! $expression ) {
			throw new InvalidArgumentException( 'CHECK constraint is missing an expression.' );
		}

		return $this->translate_check_constraint_tokens( $expression->get_descendant_tokens() );
	}

	/**
	 * Translate CHECK expression tokens.
	 *
	 * @param WP_MySQL_Token[] $tokens MySQL tokens.
	 * @return string PostgreSQL SQL.
	 */
	private function translate_check_constraint_tokens( array $tokens ): string {
		$sql            = '';
		$previous_token = null;

		foreach ( $tokens as $token ) {
			$fragment = $this->translate_check_constraint_token( $token );
			if ( '' === $fragment ) {
				continue;
			}

			if ( '' !== $sql && $this->check_constraint_tokens_need_space( $previous_token, $token ) ) {
				$sql .= ' ';
			}

			$sql           .= $fragment;
			$previous_token = $token;
		}

		return $sql;
	}

	/**
	 * Translate one CHECK expression token.
	 *
	 * @param WP_MySQL_Token $token MySQL token.
	 * @return string PostgreSQL SQL fragment.
	 */
	private function translate_check_constraint_token( WP_MySQL_Token $token ): string {
		if ( WP_MySQL_Lexer::BACK_TICK_QUOTED_ID === $token->id ) {
			return $this->quote_identifier( $token->get_value() );
		}

		return $token->get_bytes();
	}

	/**
	 * Decide whether two CHECK expression tokens need a separating space.
	 *
	 * @param WP_MySQL_Token|null $previous Previous token, or null.
	 * @param WP_MySQL_Token      $current  Current token.
	 * @return bool Whether to add a space.
	 */
	private function check_constraint_tokens_need_space( ?WP_MySQL_Token $previous, WP_MySQL_Token $current ): bool {
		if ( null === $previous ) {
			return false;
		}

		if (
			WP_MySQL_Lexer::CLOSE_PAR_SYMBOL === $current->id
			|| WP_MySQL_Lexer::COMMA_SYMBOL === $current->id
			|| WP_MySQL_Lexer::DOT_SYMBOL === $current->id
			|| WP_MySQL_Lexer::DOT_SYMBOL === $previous->id
			|| WP_MySQL_Lexer::OPEN_PAR_SYMBOL === $previous->id
		) {
			return false;
		}

		if (
			WP_MySQL_Lexer::OPEN_PAR_SYMBOL === $current->id
			&& $this->is_check_constraint_identifier_like_token( $previous )
		) {
			return false;
		}

		return true;
	}

	/**
	 * Check whether a CHECK token is identifier-like.
	 *
	 * @param WP_MySQL_Token $token MySQL token.
	 * @return bool Whether the token is identifier-like.
	 */
	private function is_check_constraint_identifier_like_token( WP_MySQL_Token $token ): bool {
		return in_array(
			$token->id,
			array(
				WP_MySQL_Lexer::IDENTIFIER,
				WP_MySQL_Lexer::BACK_TICK_QUOTED_ID,
			),
			true
		);
	}

	/**
	 * Translate inline MySQL REFERENCES syntax.
	 *
	 * @param WP_Parser_Node $references REFERENCES node.
	 * @return string PostgreSQL REFERENCES clause.
	 */
	private function translate_inline_references( WP_Parser_Node $references ): string {
		$reference = $this->extract_inline_reference_metadata( $references );
		$table_sql = $this->quote_table_reference(
			$reference['referenced_schema'],
			$reference['referenced_table']
		);
		$columns = array_map( array( $this, 'quote_identifier' ), $reference['referenced_columns'] );

		$sql = sprintf(
			'REFERENCES %s (%s)',
			$table_sql,
			implode( ', ', $columns )
		);

		if ( 'NO ACTION' !== $reference['delete_rule'] ) {
			$sql .= ' ON DELETE ' . $reference['delete_rule'];
		}

		if ( 'NO ACTION' !== $reference['update_rule'] ) {
			$sql .= ' ON UPDATE ' . $reference['update_rule'];
		}

		return $sql;
	}

	/**
	 * Extract the referenced table, columns, and rules from inline REFERENCES.
	 *
	 * @param WP_Parser_Node $references REFERENCES node.
	 * @return array{referenced_schema: string|null, referenced_table: string, referenced_columns: string[], update_rule: string, delete_rule: string}
	 */
	private function extract_inline_reference_metadata( WP_Parser_Node $references ): array {
		if ( $references->has_child_token( WP_MySQL_Lexer::MATCH_SYMBOL ) ) {
			throw new InvalidArgumentException( 'Unsupported inline REFERENCES option.' );
		}

		$table_reference = $this->get_table_reference_parts( $references->get_first_child_node( 'tableRef' ) );
		$columns         = $this->get_identifier_list_values( $references->get_first_child_node( 'identifierListWithParentheses' ) );
		if ( empty( $columns ) ) {
			throw new InvalidArgumentException( 'Unsupported inline REFERENCES option.' );
		}

		$rules = $this->get_inline_reference_rules( $references );

		return array(
			'referenced_schema'  => $table_reference['schema'],
			'referenced_table'   => $table_reference['table'],
			'referenced_columns' => $columns,
			'update_rule'        => $rules['update_rule'],
			'delete_rule'        => $rules['delete_rule'],
		);
	}

	/**
	 * Get table reference parts from a tableRef node.
	 *
	 * @param WP_Parser_Node|null $table_ref Table reference node.
	 * @return array{schema: string|null, table: string}
	 */
	private function get_table_reference_parts( ?WP_Parser_Node $table_ref ): array {
		if ( ! $table_ref ) {
			throw new InvalidArgumentException( 'Expected table reference node.' );
		}

		$identifiers = array();
		foreach ( $table_ref->get_descendant_nodes( 'identifier' ) as $identifier ) {
			$identifiers[] = $this->get_identifier_value( $identifier );
		}

		if ( 1 === count( $identifiers ) ) {
			return array(
				'schema' => null,
				'table'  => $identifiers[0],
			);
		}

		if ( 2 === count( $identifiers ) ) {
			return array(
				'schema' => $identifiers[0],
				'table'  => $identifiers[1],
			);
		}

		throw new InvalidArgumentException( 'Unsupported table reference.' );
	}

	/**
	 * Quote a possibly schema-qualified table reference.
	 *
	 * @param string|null $schema_name Schema name, or null.
	 * @param string      $table_name  Table name.
	 * @return string Quoted table reference.
	 */
	private function quote_table_reference( ?string $schema_name, string $table_name ): string {
		if ( null === $schema_name ) {
			return $this->quote_identifier( $table_name );
		}

		return $this->quote_identifier( $schema_name ) . '.' . $this->quote_identifier( $table_name );
	}

	/**
	 * Get identifier values from an identifierListWithParentheses node.
	 *
	 * @param WP_Parser_Node|null $identifier_list Identifier list node.
	 * @return string[] Identifier values.
	 */
	private function get_identifier_list_values( ?WP_Parser_Node $identifier_list ): array {
		if ( ! $identifier_list ) {
			return array();
		}

		$identifiers = array();
		foreach ( $identifier_list->get_descendant_nodes( 'identifier' ) as $identifier ) {
			$identifiers[] = $this->get_identifier_value( $identifier );
		}

		return $identifiers;
	}

	/**
	 * Parse inline foreign key ON UPDATE/ON DELETE rules.
	 *
	 * @param WP_Parser_Node $references REFERENCES node.
	 * @return array{update_rule: string, delete_rule: string}
	 */
	private function get_inline_reference_rules( WP_Parser_Node $references ): array {
		$rules = array(
			'update_rule' => 'NO ACTION',
			'delete_rule' => 'NO ACTION',
		);
		$seen   = array();
		$tokens = $references->get_descendant_tokens();

		for ( $position = 0; $position < count( $tokens ); ++$position ) {
			if ( WP_MySQL_Lexer::ON_SYMBOL !== $tokens[ $position ]->id ) {
				continue;
			}

			if ( ! isset( $tokens[ $position + 1 ] ) ) {
				throw new InvalidArgumentException( 'Unsupported inline REFERENCES option.' );
			}

			if ( WP_MySQL_Lexer::UPDATE_SYMBOL === $tokens[ $position + 1 ]->id ) {
				$rule_key = 'update_rule';
			} elseif ( WP_MySQL_Lexer::DELETE_SYMBOL === $tokens[ $position + 1 ]->id ) {
				$rule_key = 'delete_rule';
			} else {
				throw new InvalidArgumentException( 'Unsupported inline REFERENCES option.' );
			}

			if ( isset( $seen[ $rule_key ] ) ) {
				throw new InvalidArgumentException( 'Unsupported inline REFERENCES option.' );
			}

			$option_position    = $position + 2;
			$rules[ $rule_key ] = $this->get_inline_reference_option( $tokens, $option_position );
			$seen[ $rule_key ]  = true;
			$position           = $option_position - 1;
		}

		return $rules;
	}

	/**
	 * Parse one inline foreign key reference option.
	 *
	 * @param WP_MySQL_Token[] $tokens   REFERENCES token stream.
	 * @param int             $position Current token position, updated on success.
	 * @return string Reference option.
	 */
	private function get_inline_reference_option( array $tokens, int &$position ): string {
		if ( ! isset( $tokens[ $position ] ) ) {
			throw new InvalidArgumentException( 'Unsupported inline REFERENCES option.' );
		}

		if ( in_array( $tokens[ $position ]->id, array( WP_MySQL_Lexer::CASCADE_SYMBOL, WP_MySQL_Lexer::RESTRICT_SYMBOL ), true ) ) {
			$rule = strtoupper( $tokens[ $position ]->get_value() );
			++$position;
			return $rule;
		}

		if ( WP_MySQL_Lexer::SET_SYMBOL === $tokens[ $position ]->id ) {
			if ( ! isset( $tokens[ $position + 1 ] ) ) {
				throw new InvalidArgumentException( 'Unsupported inline REFERENCES option.' );
			}

			if ( WP_MySQL_Lexer::NULL_SYMBOL === $tokens[ $position + 1 ]->id ) {
				$position += 2;
				return 'SET NULL';
			}

			if ( WP_MySQL_Lexer::DEFAULT_SYMBOL === $tokens[ $position + 1 ]->id ) {
				$position += 2;
				return 'SET DEFAULT';
			}

			throw new InvalidArgumentException( 'Unsupported inline REFERENCES option.' );
		}

		if (
			isset( $tokens[ $position + 1 ] )
			&& WP_MySQL_Lexer::NO_SYMBOL === $tokens[ $position ]->id
			&& WP_MySQL_Lexer::ACTION_SYMBOL === $tokens[ $position + 1 ]->id
		) {
			$position += 2;
			return 'NO ACTION';
		}

		throw new InvalidArgumentException( 'Unsupported inline REFERENCES option.' );
	}

	/**
	 * Get the MySQL-style implicit foreign key constraint name.
	 *
	 * @param string $table_name Table name.
	 * @param int    $ordinal    Constraint ordinal.
	 * @return string Constraint name.
	 */
	private function get_implicit_foreign_key_constraint_name( string $table_name, int $ordinal ): string {
		return $table_name . '_ibfk_' . $ordinal;
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

		$type = $this->get_normalized_mysql_data_type( $data_type );
		if ( 'bigint' === $type ) {
			$postgresql_type = 'bigint';
		} elseif ( 'serial' === $type ) {
			$postgresql_type = 'bigint';
		} elseif ( in_array( $type, array( 'bit', 'bool', 'boolean', 'int', 'integer', 'mediumint', 'smallint', 'tinyint' ), true ) ) {
			$postgresql_type = 'integer';
		} elseif ( in_array( $type, array( 'varchar', 'char' ), true ) ) {
			$length          = $this->get_field_length( $data_type );
			if ( 'char' === $type && null === $length ) {
				$length = 1;
			}
			$postgresql_type = $length ? sprintf( '%s(%d)', $type, $length ) : $type;
		} elseif (
			in_array( $type, array( 'tinytext', 'text', 'mediumtext', 'longtext', 'json', 'datetime', 'timestamp', 'date', 'time', 'year' ), true )
			|| $this->is_mysql_spatial_column_type( $type )
		) {
			$postgresql_type = 'text';
		} elseif ( in_array( $type, array( 'enum', 'set' ), true ) ) {
			$postgresql_type = 'text';
		} elseif ( in_array( $type, array( 'binary', 'varbinary', 'tinyblob', 'blob', 'mediumblob', 'longblob' ), true ) ) {
			$postgresql_type = 'bytea';
		} elseif ( in_array( $type, array( 'float', 'double', 'real' ), true ) ) {
			$precision_fragment = $this->get_numeric_precision_fragment( $data_type );
			$postgresql_type    = '' === $precision_fragment ? 'double precision' : 'numeric' . $precision_fragment;
		} elseif ( in_array( $type, array( 'dec', 'decimal', 'fixed', 'numeric' ), true ) ) {
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

			if ( $this->is_unquoted_mysql_null_token( $token ) ) {
				return 'DEFAULT NULL';
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
	 * @return string|null PostgreSQL CREATE INDEX statement, or null for metadata-only MySQL index types.
	 */
	private function translate_secondary_index( WP_Parser_Node $table_constraint, string $table_name, bool $if_not_exists ): ?string {
		if (
			$table_constraint->has_child_token( WP_MySQL_Lexer::FULLTEXT_SYMBOL )
			|| $table_constraint->has_child_token( WP_MySQL_Lexer::SPATIAL_SYMBOL )
		) {
			return null;
		}

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
			implode( ', ', $this->quote_key_parts( $table_constraint, $table_constraint->has_child_token( WP_MySQL_Lexer::UNIQUE_SYMBOL ), true ) )
		);
	}

	/**
	 * Validate MySQL index options that PostgreSQL DDL can safely ignore.
	 *
	 * @param WP_Parser_Node $table_constraint Table constraint node.
	 */
	private function validate_mysql_table_constraint_index_options( WP_Parser_Node $table_constraint ): void {
		$is_metadata_only = $table_constraint->has_child_token( WP_MySQL_Lexer::FULLTEXT_SYMBOL )
			|| $table_constraint->has_child_token( WP_MySQL_Lexer::SPATIAL_SYMBOL );

		if (
			$is_metadata_only
			&& (
				! empty( $table_constraint->get_child_nodes( 'indexOption' ) )
				|| ! empty( $table_constraint->get_child_nodes( 'fulltextIndexOption' ) )
				|| ! empty( $table_constraint->get_child_nodes( 'spatialIndexOption' ) )
			)
		) {
			throw new InvalidArgumentException( 'Unsupported CREATE TABLE index option.' );
		}

		if (
			! $is_metadata_only
			&& (
				! empty( $table_constraint->get_child_nodes( 'fulltextIndexOption' ) )
				|| ! empty( $table_constraint->get_child_nodes( 'spatialIndexOption' ) )
			)
		) {
			throw new InvalidArgumentException( 'Unsupported CREATE TABLE index option.' );
		}

		foreach ( $table_constraint->get_descendant_nodes( 'indexType' ) as $index_type ) {
			if ( ! $index_type->has_child_token( WP_MySQL_Lexer::BTREE_SYMBOL ) ) {
				throw new InvalidArgumentException( 'Unsupported CREATE TABLE index option.' );
			}
		}

		$is_primary = $table_constraint->has_child_token( WP_MySQL_Lexer::PRIMARY_SYMBOL );
		foreach ( $table_constraint->get_child_nodes( 'indexOption' ) as $index_option ) {
			if ( ! $this->is_supported_mysql_table_index_option( $index_option, $is_primary ) ) {
				throw new InvalidArgumentException( 'Unsupported CREATE TABLE index option.' );
			}
		}
	}

	/**
	 * Check whether a table index option can be ignored by PostgreSQL DDL.
	 *
	 * @param WP_Parser_Node $index_option Index option node.
	 * @param bool           $is_primary   Whether this is a PRIMARY KEY constraint.
	 * @return bool Whether the option is supported.
	 */
	private function is_supported_mysql_table_index_option( WP_Parser_Node $index_option, bool $is_primary ): bool {
		$index_type_clause = $index_option->get_first_child_node( 'indexTypeClause' );
		if ( $index_type_clause ) {
			$index_type = $index_type_clause->get_first_child_node( 'indexType' );
			return $index_type && $index_type->has_child_token( WP_MySQL_Lexer::BTREE_SYMBOL );
		}

		$common_option = $index_option->get_first_child_node( 'commonIndexOption' );
		if ( ! $common_option ) {
			return false;
		}

		if (
			$common_option->has_child_token( WP_MySQL_Lexer::COMMENT_SYMBOL )
			|| $common_option->has_child_token( WP_MySQL_Lexer::KEY_BLOCK_SIZE_SYMBOL )
		) {
			return true;
		}

		$visibility = $common_option->get_first_child_node( 'visibility' );
		if ( ! $visibility ) {
			return false;
		}

		return ! $is_primary || $visibility->has_child_token( WP_MySQL_Lexer::VISIBLE_SYMBOL );
	}

	/**
	 * Get quoted key parts from a MySQL key constraint.
	 *
	 * @param WP_Parser_Node $table_constraint       Table constraint node.
	 * @param bool           $use_prefix_expressions Whether explicit key-part prefix lengths should become expressions.
	 * @param bool           $include_direction      Whether ASC/DESC key-part direction should be included.
	 * @return string[] Quoted PostgreSQL column names.
	 */
	private function quote_key_parts( WP_Parser_Node $table_constraint, bool $use_prefix_expressions = false, bool $include_direction = false ): array {
		$quoted_parts = array();

		foreach ( $table_constraint->get_descendant_nodes( 'keyPart' ) as $key_part ) {
			$column_name = $this->get_identifier_value( $key_part->get_first_child_node( 'identifier' ) );
			$sub_part    = $this->get_field_length( $key_part );
			if ( $use_prefix_expressions && null !== $sub_part ) {
				$quoted_part = $this->get_prefix_key_part_expression_sql( $column_name, $sub_part );
			} else {
				$quoted_part = $this->quote_identifier( $column_name );
			}

			if ( $include_direction ) {
				$quoted_part .= $this->get_key_part_direction_sql( $key_part );
			}

			$quoted_parts[] = $quoted_part;
		}

		if ( empty( $quoted_parts ) ) {
			throw new InvalidArgumentException( 'Index definition does not contain any key parts.' );
		}

		return $quoted_parts;
	}

	/**
	 * Get PostgreSQL ASC/DESC SQL for a MySQL key part.
	 *
	 * @param WP_Parser_Node $key_part Key part node.
	 * @return string Direction SQL, including leading space, or empty string.
	 */
	private function get_key_part_direction_sql( WP_Parser_Node $key_part ): string {
		$direction = $key_part->get_first_child_node( 'direction' );
		if ( ! $direction ) {
			return '';
		}

		$value = strtoupper( $this->get_node_value( $direction ) );
		if ( 'ASC' === $value || 'DESC' === $value ) {
			return ' ' . $value;
		}

		return '';
	}

	/**
	 * Get PostgreSQL SQL for a MySQL prefix key part.
	 *
	 * @param string $column_name Column name.
	 * @param int    $sub_part    Prefix length.
	 * @return string PostgreSQL expression SQL.
	 */
	private function get_prefix_key_part_expression_sql( string $column_name, int $sub_part ): string {
		return sprintf(
			'SUBSTR(CAST(%s AS text), 1, %d)',
			$this->quote_identifier( $column_name ),
			$sub_part
		);
	}

	/**
	 * Get key part column names.
	 *
	 * Prefix lengths are handled by quote_key_parts() when a PostgreSQL index
	 * expression is needed; this helper returns only the underlying names.
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
		return $this->get_last_identifier_value( $create_table->get_first_child_node( 'tableName' ) );
	}

	/**
	 * Get the last identifier value in a node.
	 *
	 * @param WP_Parser_Node|null $node Node containing an identifier.
	 * @return string Identifier value.
	 */
	private function get_last_identifier_value( ?WP_Parser_Node $node ): string {
		if ( ! $node ) {
			throw new InvalidArgumentException( 'Expected identifier node.' );
		}

		$identifier = null;
		$tokens     = $node->get_descendant_tokens();
		foreach ( $tokens as $token ) {
			if ( WP_MySQL_Lexer::IDENTIFIER === $token->id || WP_MySQL_Lexer::BACK_TICK_QUOTED_ID === $token->id ) {
				$identifier = $token->get_value();
			}
		}

		if ( null !== $identifier ) {
			return $identifier;
		}

		if ( 1 === count( $tokens ) && '' !== $tokens[0]->get_value() ) {
			return $tokens[0]->get_value();
		}

		throw new InvalidArgumentException( 'Expected identifier token.' );
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
		$table_comment = $this->get_table_comment( $create_table );
		$columns       = array();
		$column_types  = array();
		$indexes       = array();
		$foreign_keys  = array();
		$ordinal       = 1;
		$index_ordinal = 1;
		$foreign_key_ordinal = 1;

		list ( $table_charset, $table_collation ) = $charset;

		$element_list = $create_table->get_first_child_node( 'tableElementList' );
		if ( ! $element_list ) {
			return array(
				'table_name' => $table_name,
				'comment'    => $table_comment,
				'columns'    => array(),
			);
		}

		foreach ( $element_list->get_child_nodes( 'tableElement' ) as $table_element ) {
			$column_definition = $table_element->get_first_child_node( 'columnDefinition' );
			if ( $column_definition ) {
				$name              = $this->get_identifier_value( $column_definition->get_first_child_node( 'fieldIdentifier' ) );
				$field_definition  = $column_definition->get_first_child_node( 'fieldDefinition' );
				$data_type         = $field_definition ? $field_definition->get_first_child_node( 'dataType' ) : null;
				$column_type       = $this->get_mysql_column_type( $data_type, $field_definition );
				$is_serial         = $this->is_serial_data_type( $data_type );
				$is_inline_primary = $field_definition && $this->has_inline_primary_key( $field_definition );

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
					'comment'   => $field_definition ? $this->get_column_comment( $field_definition ) : '',
					'ordinal'   => $ordinal,
				);

				if ( $include_indexes ) {
					$column_metadata['nullable'] = $is_serial || $is_inline_primary || ( $field_definition && $field_definition->get_first_descendant_token( WP_MySQL_Lexer::NOT_SYMBOL ) ) ? 'NO' : 'YES';
					$column_metadata['default']  = $field_definition ? $this->get_column_default_metadata( $field_definition ) : null;
					$column_metadata['extra']    = $is_serial || ( $field_definition && $field_definition->get_first_descendant_token( WP_MySQL_Lexer::AUTO_INCREMENT_SYMBOL ) ) ? 'auto_increment' : '';
				}

				$columns[]                           = $column_metadata;
				$column_types[ strtolower( $name ) ] = $column_type;

				if ( $include_indexes && $field_definition ) {
					foreach ( $this->extract_inline_index_metadata( $name, $field_definition, $column_type, $index_ordinal ) as $index ) {
						$indexes[] = $index;
						++$index_ordinal;
					}

					$foreign_key = $this->extract_inline_foreign_key_metadata( $table_name, $name, $field_definition, $column_definition, $foreign_key_ordinal );
					if ( null !== $foreign_key ) {
						$foreign_keys[] = $foreign_key;
						++$foreign_key_ordinal;
					}
				}

				++$ordinal;
				continue;
			}

			if ( $include_indexes ) {
				$table_constraint = $table_element->get_first_child_node( 'tableConstraintDef' );
				if ( $table_constraint ) {
					if ( $table_constraint->get_first_child_node( 'checkConstraint' ) ) {
						continue;
					}

					$this->validate_mysql_table_constraint_index_options( $table_constraint );
					$indexes[] = $this->extract_index_metadata( $table_constraint, $index_ordinal, $column_types );
					++$index_ordinal;
				}
			}
		}

		$metadata = array(
			'table_name' => $table_name,
			'comment'    => $table_comment,
			'columns'    => $columns,
		);

		if ( $include_indexes ) {
			$metadata['indexes']      = $indexes;
			$metadata['foreign_keys'] = $foreign_keys;
		}

		return $metadata;
	}

	/**
	 * Check whether a field definition has an inline PRIMARY KEY attribute.
	 *
	 * @param WP_Parser_Node $field_definition Field definition node.
	 * @return bool Whether inline PRIMARY KEY is present.
	 */
	private function has_inline_primary_key( WP_Parser_Node $field_definition ): bool {
		foreach ( $field_definition->get_child_nodes( 'columnAttribute' ) as $attribute ) {
			if ( $attribute->has_child_token( WP_MySQL_Lexer::PRIMARY_SYMBOL ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check whether a field definition has an inline UNIQUE attribute.
	 *
	 * @param WP_Parser_Node $field_definition Field definition node.
	 * @return bool Whether inline UNIQUE is present.
	 */
	private function has_inline_unique_key( WP_Parser_Node $field_definition ): bool {
		foreach ( $field_definition->get_child_nodes( 'columnAttribute' ) as $attribute ) {
			if ( $attribute->has_child_token( WP_MySQL_Lexer::UNIQUE_SYMBOL ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Extract metadata for inline PRIMARY KEY and UNIQUE attributes.
	 *
	 * @param string         $column_name      Column name.
	 * @param WP_Parser_Node $field_definition Field definition node.
	 * @param string         $column_type      MySQL column type.
	 * @param int            $index_ordinal    Current index ordinal.
	 * @return array[] Index metadata rows.
	 */
	private function extract_inline_index_metadata( string $column_name, WP_Parser_Node $field_definition, string $column_type, int $index_ordinal ): array {
		$indexes      = array();
		$column_types = array( strtolower( $column_name ) => $column_type );
		$sub_part     = $this->get_implicit_index_sub_part( $column_name, $column_types );
		$column       = array(
			'column_name'  => $column_name,
			'seq_in_index' => 1,
			'sub_part'     => $sub_part,
		);

		if ( $this->has_inline_primary_key( $field_definition ) ) {
			$indexes[] = array(
				'name'       => 'PRIMARY',
				'ordinal'    => $index_ordinal,
				'non_unique' => '0',
				'index_type' => 'BTREE',
				'comment'    => '',
				'columns'    => array( $column ),
			);
			++$index_ordinal;
		}

		if (
			! $this->has_inline_primary_key( $field_definition )
			&& (
				$this->has_inline_unique_key( $field_definition )
				|| $this->is_serial_data_type( $field_definition->get_first_child_node( 'dataType' ) )
			)
		) {
			$indexes[] = array(
				'name'       => $column_name,
				'ordinal'    => $index_ordinal,
				'non_unique' => '0',
				'index_type' => 'BTREE',
				'comment'    => '',
				'columns'    => array( $column ),
			);
		}

		return $indexes;
	}

	/**
	 * Extract metadata for an inline foreign key reference.
	 *
	 * @param string         $table_name          Table name.
	 * @param string         $column_name         Local column name.
	 * @param WP_Parser_Node $field_definition   Field definition node.
	 * @param WP_Parser_Node $column_definition  Column definition node.
	 * @param int            $foreign_key_ordinal Current foreign key ordinal.
	 * @return array|null Foreign key metadata, or null.
	 */
	private function extract_inline_foreign_key_metadata( string $table_name, string $column_name, WP_Parser_Node $field_definition, WP_Parser_Node $column_definition, int $foreign_key_ordinal ): ?array {
		$references = $this->get_inline_references_node( $column_definition );
		if ( ! $references ) {
			return null;
		}

		$reference = $this->extract_inline_reference_metadata( $references );
		if ( 1 !== count( $reference['referenced_columns'] ) ) {
			throw new InvalidArgumentException( 'Unsupported inline REFERENCES option.' );
		}

		return array(
			'name'               => $this->get_implicit_foreign_key_constraint_name( $table_name, $foreign_key_ordinal ),
			'columns'            => array( $column_name ),
			'referenced_schema'  => $reference['referenced_schema'],
			'referenced_table'   => $reference['referenced_table'],
			'referenced_columns' => $reference['referenced_columns'],
			'update_rule'        => $reference['update_rule'],
			'delete_rule'        => $reference['delete_rule'],
		);
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
		$is_primary       = $table_constraint->has_child_token( WP_MySQL_Lexer::PRIMARY_SYMBOL );
		$is_spatial_index = $this->is_mysql_spatial_index_metadata( $table_constraint, $column_types );
		$key_parts        = $this->get_key_part_metadata( $table_constraint, $column_types, $is_spatial_index );
		$key_name         = $is_primary ? 'PRIMARY' : $this->get_index_name( $table_constraint, $key_parts );

		return array(
			'name'       => $key_name,
			'ordinal'    => $index_ordinal,
			'non_unique' => $is_primary || $table_constraint->has_child_token( WP_MySQL_Lexer::UNIQUE_SYMBOL ) ? '0' : '1',
			'index_type' => $this->get_mysql_index_type_metadata( $table_constraint, $is_spatial_index ),
			'comment'    => $this->get_index_comment( $table_constraint ),
			'columns'    => $key_parts,
		);
	}

	/**
	 * Get table comment metadata from a CREATE TABLE node.
	 *
	 * @param WP_Parser_Node $create_table CREATE TABLE node.
	 * @return string Table comment.
	 */
	private function get_table_comment( WP_Parser_Node $create_table ): string {
		foreach ( $create_table->get_descendant_nodes( 'createTableOption' ) as $option ) {
			if ( ! $option->has_child_token( WP_MySQL_Lexer::COMMENT_SYMBOL ) ) {
				continue;
			}

			$comment = $option->get_first_child_node( 'textStringLiteral' );
			return $comment ? $this->get_node_value( $comment ) : '';
		}

		return '';
	}

	/**
	 * Get column comment metadata from a field definition node.
	 *
	 * @param WP_Parser_Node $field_definition Field definition node.
	 * @return string Column comment.
	 */
	private function get_column_comment( WP_Parser_Node $field_definition ): string {
		foreach ( $field_definition->get_descendant_nodes( 'columnAttribute' ) as $attribute ) {
			if ( ! $attribute->has_child_token( WP_MySQL_Lexer::COMMENT_SYMBOL ) ) {
				continue;
			}

			$comment = $attribute->get_first_child_node( 'textLiteral' );
			return $comment ? $this->get_node_value( $comment ) : '';
		}

		return '';
	}

	/**
	 * Get index comment metadata from a table constraint node.
	 *
	 * @param WP_Parser_Node $table_constraint Table constraint node.
	 * @return string Index comment.
	 */
	private function get_index_comment( WP_Parser_Node $table_constraint ): string {
		foreach ( $table_constraint->get_descendant_nodes( 'commonIndexOption' ) as $option ) {
			if ( ! $option->has_child_token( WP_MySQL_Lexer::COMMENT_SYMBOL ) ) {
				continue;
			}

			$comment = $option->get_first_child_node( 'textLiteral' );
			return $comment ? $this->get_node_value( $comment ) : '';
		}

		return '';
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
	 * @param bool           $is_spatial_index  Whether the index targets spatial data.
	 * @return string Index type.
	 */
	private function get_mysql_index_type_metadata( WP_Parser_Node $table_constraint, bool $is_spatial_index ): string {
		if ( $table_constraint->has_child_token( WP_MySQL_Lexer::FULLTEXT_SYMBOL ) ) {
			return 'FULLTEXT';
		}

		if ( $table_constraint->has_child_token( WP_MySQL_Lexer::SPATIAL_SYMBOL ) || $is_spatial_index ) {
			return 'SPATIAL';
		}

		return 'BTREE';
	}

	/**
	 * Check whether index metadata should use MySQL SPATIAL semantics.
	 *
	 * @param WP_Parser_Node $table_constraint Table constraint node.
	 * @param array          $column_types     Column types keyed by lowercase name.
	 * @return bool Whether the index is spatial.
	 */
	private function is_mysql_spatial_index_metadata( WP_Parser_Node $table_constraint, array $column_types ): bool {
		if ( $table_constraint->has_child_token( WP_MySQL_Lexer::SPATIAL_SYMBOL ) ) {
			return true;
		}

		$key_part = $table_constraint->get_first_descendant_node( 'keyPart' );
		if ( ! $key_part ) {
			return false;
		}

		$column_name = $this->get_identifier_value( $key_part->get_first_child_node( 'identifier' ) );
		$column_type = $column_types[ strtolower( $column_name ) ] ?? null;

		return is_string( $column_type ) && $this->is_mysql_spatial_column_type( $column_type );
	}

	/**
	 * Get key part metadata from a MySQL key constraint.
	 *
	 * @param WP_Parser_Node $table_constraint Table constraint node.
	 * @param array          $column_types     Column types keyed by lowercase name.
	 * @param bool           $is_spatial_index  Whether the index targets spatial data.
	 * @return array[] Key part metadata.
	 */
	private function get_key_part_metadata( WP_Parser_Node $table_constraint, array $column_types, bool $is_spatial_index ): array {
		$key_parts = array();

		foreach ( $table_constraint->get_descendant_nodes( 'keyPart' ) as $key_part ) {
			$column_name = $this->get_identifier_value( $key_part->get_first_child_node( 'identifier' ) );
			$sub_part    = $this->get_field_length( $key_part );
			if ( null === $sub_part && $is_spatial_index ) {
				$sub_part = 32;
			} elseif ( null === $sub_part && ! $table_constraint->has_child_token( WP_MySQL_Lexer::FULLTEXT_SYMBOL ) ) {
				$sub_part = $this->get_implicit_index_sub_part( $column_name, $column_types );
			}

			$key_parts[] = array(
				'column_name'  => $column_name,
				'seq_in_index' => count( $key_parts ) + 1,
				'collation'    => $table_constraint->has_child_token( WP_MySQL_Lexer::FULLTEXT_SYMBOL ) ? null : $this->get_key_part_collation_metadata( $key_part ),
				'sub_part'     => $sub_part,
			);
		}

		if ( empty( $key_parts ) ) {
			throw new InvalidArgumentException( 'Index definition does not contain any key parts.' );
		}

		return $key_parts;
	}

	/**
	 * Get MySQL SHOW INDEX Collation metadata for a key part.
	 *
	 * @param WP_Parser_Node $key_part Key part node.
	 * @return string MySQL collation metadata.
	 */
	private function get_key_part_collation_metadata( WP_Parser_Node $key_part ): string {
		$direction = $key_part->get_first_child_node( 'direction' );
		if ( ! $direction ) {
			return 'A';
		}

		return 'DESC' === strtoupper( $this->get_node_value( $direction ) ) ? 'D' : 'A';
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

				if ( $this->is_unquoted_mysql_null_token( $token ) ) {
					return null;
				}

				return $token->get_value();
			}
		}

		return null;
	}

	/**
	 * Check whether a token represents an unquoted MySQL NULL literal.
	 *
	 * @param WP_MySQL_Token $token MySQL token.
	 * @return bool Whether the token is unquoted NULL.
	 */
	private function is_unquoted_mysql_null_token( WP_MySQL_Token $token ): bool {
		return WP_MySQL_Lexer::SINGLE_QUOTED_TEXT !== $token->id
			&& WP_MySQL_Lexer::DOUBLE_QUOTED_TEXT !== $token->id
			&& WP_MySQL_Lexer::BACK_TICK_QUOTED_ID !== $token->id
			&& 0 === strcasecmp( $token->get_value(), 'null' );
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
		$is_binary   = false;
		$is_national = $this->is_national_character_data_type( $field_definition->get_first_child_node( 'dataType' ) );

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

		if ( null === $charset && null === $collation && $is_national ) {
			$charset   = 'utf8';
			$collation = $this->get_default_collation_for_charset( $charset );
		} elseif ( null === $charset && null === $collation ) {
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

		$type = $this->get_normalized_mysql_data_type( $data_type );
		if ( 'integer' === $type ) {
			$type = 'int';
		}

		if ( 'serial' === $type ) {
			return 'bigint unsigned';
		}

		if ( in_array( $type, array( 'enum', 'set' ), true ) ) {
			return $this->get_enum_or_set_column_type( $type, $data_type );
		}

		$numeric_precision = $this->get_numeric_precision_fragment( $data_type );
		if ( '' !== $numeric_precision && in_array( $type, array( 'dec', 'decimal', 'double', 'fixed', 'float', 'numeric' ), true ) ) {
			$type .= $numeric_precision;
		}

		$length = $this->get_field_length( $data_type );
		if ( null === $length && in_array( $type, array( 'binary', 'bit', 'char' ), true ) ) {
			$length = 1;
		}
		if ( null !== $length && in_array( $type, array( 'bigint', 'binary', 'bit', 'char', 'int', 'mediumint', 'smallint', 'tinyint', 'varbinary', 'varchar' ), true ) ) {
			$type = sprintf( '%s(%d)', $type, $length );
		}

		if ( $field_definition && $field_definition->get_first_descendant_token( WP_MySQL_Lexer::UNSIGNED_SYMBOL ) ) {
			$type .= ' unsigned';
		}

		return $type;
	}

	/**
	 * Get a normalized MySQL data type name from a dataType node.
	 *
	 * @param WP_Parser_Node $data_type Data type node.
	 * @return string Normalized type name.
	 */
	private function get_normalized_mysql_data_type( WP_Parser_Node $data_type ): string {
		if ( $this->is_serial_data_type( $data_type ) ) {
			return 'serial';
		}

		$long_alias = $this->get_normalized_mysql_long_data_type( $data_type );
		if ( null !== $long_alias ) {
			return $long_alias;
		}

		$character_alias = $this->get_normalized_mysql_character_data_type( $data_type );
		if ( null !== $character_alias ) {
			return $character_alias;
		}

		$type_token = $data_type->get_first_child_token();
		if ( ! $type_token ) {
			throw new InvalidArgumentException( 'Column data type is empty.' );
		}

		return strtolower( $type_token->get_value() );
	}

	/**
	 * Normalize MySQL LONG-prefixed data type aliases.
	 *
	 * @param WP_Parser_Node $data_type Data type node.
	 * @return string|null Normalized data type, or null for non-LONG aliases.
	 */
	private function get_normalized_mysql_long_data_type( WP_Parser_Node $data_type ): ?string {
		$tokens = $data_type->get_descendant_tokens();
		if (
			! isset( $tokens[0], $tokens[1] )
			|| WP_MySQL_Lexer::LONG_SYMBOL !== $tokens[0]->id
		) {
			return null;
		}

		if ( WP_MySQL_Lexer::VARBINARY_SYMBOL === $tokens[1]->id ) {
			return 'mediumblob';
		}

		if ( in_array( $tokens[1]->id, array( WP_MySQL_Lexer::CHAR_SYMBOL, WP_MySQL_Lexer::VARCHAR_SYMBOL, WP_MySQL_Lexer::VARCHARACTER_SYMBOL ), true ) ) {
			return 'mediumtext';
		}

		return null;
	}

	/**
	 * Normalize MySQL character data type aliases.
	 *
	 * @param WP_Parser_Node $data_type Data type node.
	 * @return string|null Normalized character type, or null for non-character types.
	 */
	private function get_normalized_mysql_character_data_type( WP_Parser_Node $data_type ): ?string {
		$tokens = $data_type->get_descendant_tokens();
		if ( empty( $tokens ) ) {
			return null;
		}

		$first_id    = $tokens[0]->id;
		$has_varchar = false;
		$has_varying = false;
		foreach ( $tokens as $token ) {
			if ( in_array( $token->id, array( WP_MySQL_Lexer::VARCHAR_SYMBOL, WP_MySQL_Lexer::VARCHARACTER_SYMBOL, WP_MySQL_Lexer::NVARCHAR_SYMBOL ), true ) ) {
				$has_varchar = true;
			}
			if ( WP_MySQL_Lexer::VARYING_SYMBOL === $token->id ) {
				$has_varying = true;
			}
		}

		if (
			$has_varchar
			|| $has_varying
			|| in_array( $first_id, array( WP_MySQL_Lexer::VARCHAR_SYMBOL, WP_MySQL_Lexer::VARCHARACTER_SYMBOL, WP_MySQL_Lexer::NVARCHAR_SYMBOL ), true )
		) {
			return 'varchar';
		}

		if ( in_array( $first_id, array( WP_MySQL_Lexer::CHAR_SYMBOL, WP_MySQL_Lexer::NCHAR_SYMBOL, WP_MySQL_Lexer::NATIONAL_SYMBOL ), true ) ) {
			return 'char';
		}

		return null;
	}

	/**
	 * Check whether a data type is SERIAL.
	 *
	 * @param WP_Parser_Node|null $data_type Data type node.
	 * @return bool Whether SERIAL is present.
	 */
	private function is_serial_data_type( ?WP_Parser_Node $data_type ): bool {
		return $data_type && null !== $data_type->get_first_descendant_token( WP_MySQL_Lexer::SERIAL_SYMBOL );
	}

	/**
	 * Check whether a data type uses MySQL's national character set aliases.
	 *
	 * @param WP_Parser_Node|null $data_type Data type node.
	 * @return bool Whether the data type is national character based.
	 */
	private function is_national_character_data_type( ?WP_Parser_Node $data_type ): bool {
		return $data_type && (
			null !== $data_type->get_first_descendant_token( WP_MySQL_Lexer::NATIONAL_SYMBOL )
			|| null !== $data_type->get_first_descendant_token( WP_MySQL_Lexer::NCHAR_SYMBOL )
			|| null !== $data_type->get_first_descendant_token( WP_MySQL_Lexer::NVARCHAR_SYMBOL )
		);
	}

	/**
	 * Get the MySQL COLUMN_TYPE metadata for ENUM and SET columns.
	 *
	 * @param string         $type      Base MySQL data type.
	 * @param WP_Parser_Node $data_type Data type node.
	 * @return string MySQL column type.
	 */
	private function get_enum_or_set_column_type( string $type, WP_Parser_Node $data_type ): string {
		$values = array();
		foreach ( $data_type->get_descendant_tokens() as $token ) {
			if (
				WP_MySQL_Lexer::SINGLE_QUOTED_TEXT === $token->id
				|| WP_MySQL_Lexer::DOUBLE_QUOTED_TEXT === $token->id
			) {
				$values[] = $this->quote_string_literal( $token->get_value() );
			}
		}

		if ( empty( $values ) ) {
			throw new InvalidArgumentException( sprintf( 'Unsupported MySQL column type for PostgreSQL install DDL: %s.', $type ) );
		}

		return sprintf( '%s(%s)', $type, implode( ',', $values ) );
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
	 * Check whether a MySQL column type is spatial.
	 *
	 * @param string $data_type MySQL data type.
	 * @return bool Whether the type is spatial.
	 */
	private function is_mysql_spatial_column_type( string $data_type ): bool {
		$base_type = $this->get_base_mysql_column_type( $data_type );
		return in_array(
			$base_type,
			array(
				'geometry',
				'point',
				'linestring',
				'polygon',
				'multipoint',
				'multilinestring',
				'multipolygon',
				'geometrycollection',
				'geomcollection',
			),
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
