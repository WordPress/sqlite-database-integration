<?php declare(strict_types = 1);

/*
 * The PostgreSQL driver uses PDO. Enable PDO function calls:
 * phpcs:disable WordPress.DB.RestrictedClasses.mysql__PDO
 */

/**
 * PostgreSQL driver rewrite rule translators and dispatch helpers.
 */
trait WP_PostgreSQL_Driver_Rewrite_Rules {
	private function apply_mysql_top_level_query_dispatch_rules( string &$query, bool &$translated_for_postgresql, $fetch_mode, array $fetch_mode_args ) {
		foreach ( $this->get_mysql_top_level_query_dispatch_rules() as $rule ) {
			$result = $this->apply_mysql_top_level_query_dispatch_rule( $rule, $query, $translated_for_postgresql, $fetch_mode, $fetch_mode_args );
			if ( null !== $result ) {
				return $result;
			}
		}
		return null;
	}
	private function get_mysql_top_level_query_dispatch_rules(): array {
		return array( array( 'result', 'execute_mysql_runtime_setting_query' ), array( 'parse_result', 'get_mysql_use_database_name', 'execute_mysql_use_statement' ), array( 'parse_result', 'get_mysql_transaction_control_query', 'execute_mysql_transaction_control_query' ), array( 'parse_result', 'get_mysql_savepoint_query', 'execute_mysql_savepoint_query' ), array( 'fetch_result', 'execute_mysql_static_select_query' ), array( 'fetch_result', 'execute_mysql_show_query' ), array( 'reject', 'reject_unsupported_mysql_constructs', array( array( 'contains_unsupported_mysql_group_concat_function_query', 'Unsupported MySQL runtime function form.' ), array( 'contains_unsupported_mysql_extract_function_query', 'Unsupported MySQL runtime function form.' ), array( 'contains_unsupported_mysql_fulltext_search_query', 'Unsupported MySQL full-text search syntax.' ) ) ), array( 'translate_first', array( 'translate_direct_information_schema_cte_select_query', 'translate_direct_information_schema_select_query', 'translate_application_select_with_direct_information_schema_nested_selects' ) ), array( 'reject_untranslated', 'should_reject_information_schema_backend_query', 'Unsupported information_schema query.' ), array( 'parse_result', 'get_mysql_lock_tables_query', 'execute_mysql_lock_tables_query' ), array( 'parse_noop', 'get_mysql_flush_query' ), array( 'parse_result', 'get_mysql_truncate_table_query', 'execute_mysql_truncate_table_query' ), array( 'parse_result', 'get_found_rows_query_column_name', 'execute_mysql_found_rows_query' ), array( 'parse_result', 'translate_mysql_create_table_select_query', 'execute_mysql_translated_create_table_query' ), array( 'parse_result', 'translate_mysql_create_table_like_query', 'execute_mysql_translated_create_table_query' ), array( 'reject_if', 'contains_unsupported_mysql_create_table_column_attribute_query', 'Unsupported CREATE TABLE column attribute.' ), array( 'result', 'execute_mysql_create_table_query' ), array( 'parse_statements', 'translate_mysql_view_query', null, array( WP_MySQL_Lexer::CREATE_SYMBOL, 'CREATE VIEW' ) ), array( 'parse_result', 'translate_mysql_create_index_query', 'execute_mysql_create_index_query' ), array( 'message', 'get_unsupported_mysql_create_statement_message' ), array( 'parse_result', 'translate_mysql_dbdelta_alter_table_query', 'execute_mysql_dbdelta_alter_query' ), array( 'reject', 'reject_mysql_statement_prefix', array( WP_MySQL_Lexer::ALTER_SYMBOL, WP_MySQL_Lexer::TABLE_SYMBOL ), 'Unsupported ALTER TABLE statement.' ), array( 'parse_statements', 'translate_mysql_view_query', null, array( WP_MySQL_Lexer::ALTER_SYMBOL, 'ALTER VIEW' ) ), array( 'parse_admin', 'translate_mysql_drop_table_query', true ), array( 'parse_admin', 'translate_mysql_drop_view_query', false ), array( 'parse_admin', 'translate_mysql_drop_index_query', true ), array( 'message', 'get_unsupported_mysql_drop_statement_message' ), array( 'parse_admin', 'translate_mysql_rename_table_query', true ), array( 'reject', 'reject_mysql_statement_prefix', array( WP_MySQL_Lexer::RENAME_SYMBOL, WP_MySQL_Lexer::TABLE_SYMBOL ), 'Unsupported RENAME TABLE statement.' ), array( 'fetch_result', 'execute_mysql_metadata_show_query' ) );
	}
	private function apply_mysql_top_level_query_dispatch_rule( array $rule, string &$query, bool &$translated_for_postgresql, $fetch_mode, array $fetch_mode_args ) {
		switch ( $rule[0] ) {
			case 'result':
				return $this->{$rule[1]}( $query );
			case 'fetch_result':
				return $this->{$rule[1]}( $query, $fetch_mode, ...$fetch_mode_args );
			case 'reject':
				$this->{$rule[1]}( $query, $rule[2], ...( isset( $rule[3] ) ? array( $rule[3] ) : array() ) );
				return null;
			case 'translate_first':
				$translated_query = $this->translate_first_mysql_query( $query, $rule[1] );
				if ( null !== $translated_query ) {
					$query                     = $translated_query;
					$translated_for_postgresql = true;
				}
				return null;
			case 'reject_if':
			case 'reject_untranslated':
				if ( ( 'reject_if' === $rule[0] || ! $translated_for_postgresql ) && $this->{$rule[1]}( $query ) ) {
					throw new InvalidArgumentException( $rule[2] );
				}
				return null;
			case 'message':
				$message = $this->{$rule[1]}( $query );
				if ( null !== $message ) {
					throw new InvalidArgumentException( $message );
				}
				return null;
		}
		$parsed_query = $this->{$rule[1]}( $query, ...( $rule[3] ?? array() ) );
		if ( null === $parsed_query ) {
			return null;
		}
		if ( 'parse_noop' === $rule[0] ) {
			return $this->execute_mysql_admin_noop_query();
		}
		if ( 'parse_result' === $rule[0] ) {
			return $this->{$rule[2]}( $parsed_query );
		}
		if ( 'parse_statements' === $rule[0] ) {
			return $this->execute_postgresql_statements( $parsed_query['statements'] );
		}

		$result = $this->execute_mysql_admin_statements( $parsed_query['statements'], $rule[2] );
		if ( 'translate_mysql_drop_table_query' === $rule[1] ) {
			$this->update_mysql_table_schema_state_after_drop( $parsed_query );
		}
		return $result;
	}
	private function reject_mysql_statement_prefix( string $query, array $token_ids, string $message ): void {
		$tokens = $this->get_mysql_tokens( $query );
		foreach ( $token_ids as $position => $token_id ) {
			if ( ( $tokens[ $position ]->id ?? null ) !== $token_id ) {
				return;
			}
		}
		throw new InvalidArgumentException( $message );
	}

	private function get_mysql_post_translation_unsupported_construct_guards(): array {
		return array( array( 'contains_mysql_index_hint_syntax', 'Unsupported MySQL index hint syntax.' ), array( 'contains_unsupported_mysql_date_arithmetic_function_query', 'Unsupported MySQL date arithmetic statement.' ), array( 'contains_unsupported_mysql_range_scanner_query', 'Unsupported MySQL runtime function form.', array( 'contains_unsupported_mysql_date_format_function' ) ), array( 'contains_unsupported_mysql_range_scanner_query', 'Unsupported MySQL runtime function form.', array( 'contains_unsupported_mysql_rand_function' ) ), array( 'contains_unsupported_mysql_range_scanner_query', 'Unsupported MySQL runtime function form.', array( 'contains_unsupported_mysql_convert_function' ) ), array( 'contains_unsupported_mysql_fulltext_search_query', 'Unsupported MySQL full-text search syntax.' ), array( 'contains_unsupported_mysql_range_scanner_query', 'Unsupported MySQL runtime function form.', array( 'contains_unsupported_mysql_common_function' ) ), array( 'contains_unsupported_mysql_group_concat_function_query', 'Unsupported MySQL runtime function form.' ), array( 'contains_unsupported_mysql_week_function_query', 'Unsupported MySQL WEEK() mode.' ) );
	}

	private function execute_mysql_savepoint_query( string $savepoint_query ): int {
		$this->execute_postgresql_logged_statement( $savepoint_query );
		return $this->execute_mysql_admin_noop_query();
	}

	private function execute_mysql_found_rows_query( string $found_rows_column ) {
		$this->last_result      = array( (object) array( $found_rows_column => (string) $this->last_found_rows ) );
		$this->last_column_meta = array(
			array_combine( array( 'name', 'table', 'mysqli:orgtable', 'mysqli:orgname', 'mysqli:db', 'mysqli:charsetnr', 'mysqli:flags', 'mysqli:type', 'len', 'precision', 'native_type' ), array( $found_rows_column, '', '', 'FOUND_ROWS()', $this->db_name, 63, 0, 8, 20, 0, 'integer' ) ),
		);
		return $this->last_result;
	}

	private function execute_mysql_create_table_query( string $query ): ?int {
		if ( ! $this->is_create_table_query( $query ) ) {
			return null;
		}

		$create_table_target = $this->get_mysql_create_table_target( $query );
		if ( null === $create_table_target ) {
			throw new InvalidArgumentException( 'Unsupported CREATE TABLE statement.' );
		}

		if ( $this->mysql_create_table_if_not_exists_target_exists( $query ) ) {
			return $this->execute_mysql_admin_noop_query();
		}

		$translator = new WP_PostgreSQL_Create_Table_Translator( $this->active_sql_modes );
		return $this->execute_mysql_translated_create_table_query(
			array_merge(
				$create_table_target,
				array(
					'metadata_query' => $query,
					'statements'     => $this->qualify_translated_create_table_statements( $translator->translate_schema( $query ), $create_table_target['schema'], $create_table_target['table'], $create_table_target['temporary'] ),
				)
			)
		);
	}

	private function execute_mysql_create_index_query( array $create_index_query ): int {
		$metadata = $create_index_query['metadata'];
		$this->assert_postgresql_catalog_recoverable_mysql_index_metadata( $metadata['index'] );
		$this->execute_postgresql_statements( $create_index_query['statements'] );
		$this->clear_mysql_metadata_caches();
		$this->sync_postgresql_catalog_index_comment( $metadata['schema'], $metadata['table'], $metadata['index'], true );
		return $this->execute_mysql_admin_noop_query();
	}

	private function execute_mysql_dbdelta_alter_query( array $alter_query ): int {
		$result = $this->execute_postgresql_statements( $alter_query['statements'] );
		$this->apply_mysql_dbdelta_alter_metadata( $alter_query['metadata'] );
		if ( $this->mysql_dbdelta_alter_metadata_has_operation( $alter_query['metadata'], 'drop_index' ) || $this->mysql_dbdelta_alter_metadata_has_operation( $alter_query['metadata'], 'rename_table' ) || $this->mysql_dbdelta_alter_metadata_has_operation( $alter_query['metadata'], 'set_auto_increment' ) ) {
			$this->last_result = 0;
			return $this->last_result;
		}
		return $result;
	}

	private function apply_mysql_dml_rewrite_rules( string &$query, bool &$translated_for_postgresql, ?array &$dml_identity_repair_query, ?int &$replace_return_value, bool $mysql_update_ignore_query ) {
		$first_token = $this->get_mysql_tokens( $query )[0]->id ?? null;
		foreach ( $this->get_mysql_dml_rewrite_rules() as $rule ) {
			$contains = $rule[1] ?? null;
			if ( ! in_array( $first_token, (array) $rule[0], true ) || ( null !== $contains && false === stripos( $query, $contains ) ) ) {
				continue;
			}

			if ( null === $rule[2] ) {
				$guard = $rule[4] ?? null;
				if ( ( true === $guard || true === ( $rule[5] ?? false ) ) && $translated_for_postgresql ) {
					continue;
				}
				if ( true !== $guard && null !== $guard && ! $this->{$guard}( $query ) ) {
					continue;
				}
				throw new InvalidArgumentException( $rule[3] );
			}

			$translated_query = $this->{$rule[2]}( $query );
			if ( null === $translated_query ) {
				continue;
			}

			$result = $this->apply_mysql_dml_rewrite_result( $rule[3], $translated_query, $query, $translated_for_postgresql, $dml_identity_repair_query, $replace_return_value, $mysql_update_ignore_query );
			if ( null !== $result ) {
				return $result;
			}
			$first_token = $this->get_mysql_tokens( $query )[0]->id ?? null;
		}
		return null;
	}

	private function get_mysql_dml_rewrite_rules(): array {
		return array( array( WP_MySQL_Lexer::DELETE_SYMBOL, 'REGEXP', 'translate_wordpress_options_regexp_delete_query', 'sql' ), array( WP_MySQL_Lexer::DELETE_SYMBOL, 'SUBSTRING', 'translate_wordpress_expired_transients_delete_query', 'execute_statement' ), array( WP_MySQL_Lexer::DELETE_SYMBOL, 'LEFT', 'translate_mysql_left_join_orphan_delete_query', 'sql' ), array( WP_MySQL_Lexer::DELETE_SYMBOL, null, 'translate_mysql_multi_target_delete_query', 'execute_multi_target_delete' ), array( WP_MySQL_Lexer::DELETE_SYMBOL, 'JOIN', 'translate_mysql_single_target_join_delete_query', 'sql' ), array( WP_MySQL_Lexer::DELETE_SYMBOL, null, 'translate_simple_mysql_delete_query', 'sql' ), array( WP_MySQL_Lexer::DELETE_SYMBOL, null, null, 'Unsupported DELETE statement.', true ), array( WP_MySQL_Lexer::INSERT_SYMBOL, 'DUPLICATE', 'translate_mysql_on_duplicate_key_update_query', 'upsert' ), array( WP_MySQL_Lexer::INSERT_SYMBOL, 'DUPLICATE', null, 'Unsupported ON DUPLICATE KEY UPDATE statement.', 'is_unsupported_mysql_on_duplicate_key_update_query' ), array( WP_MySQL_Lexer::REPLACE_SYMBOL, null, 'translate_simple_mysql_replace_query', 'replace' ), array( WP_MySQL_Lexer::REPLACE_SYMBOL, null, null, 'Unsupported REPLACE statement.', 'is_mysql_replace_query' ), array( WP_MySQL_Lexer::INSERT_SYMBOL, null, 'translate_simple_mysql_insert_query', 'dml' ), array( WP_MySQL_Lexer::INSERT_SYMBOL, null, null, 'Unsupported INSERT statement.', 'is_unsupported_mysql_insert_set_query', true ), array( WP_MySQL_Lexer::INSERT_SYMBOL, null, 'translate_simple_mysql_insert_select_query', 'dml' ), array( WP_MySQL_Lexer::INSERT_SYMBOL, null, null, 'Unsupported INSERT statement.', 'is_unsupported_mysql_insert_query', true ), array( WP_MySQL_Lexer::WITH_SYMBOL, 'UPDATE', 'translate_mysql_cte_prefixed_update_query', 'sql' ), array( WP_MySQL_Lexer::UPDATE_SYMBOL, null, 'translate_mysql_multi_target_update_query', 'multi_target_update' ), array( WP_MySQL_Lexer::UPDATE_SYMBOL, null, 'translate_simple_mysql_update_query', 'update' ), array( array( WP_MySQL_Lexer::UPDATE_SYMBOL, WP_MySQL_Lexer::WITH_SYMBOL ), null, null, 'Unsupported UPDATE statement.', 'is_unsupported_mysql_update_rewrite_query', true ) );
	}

	private function apply_mysql_dml_rewrite_result( string $result_type, $translated_query, string &$query, bool &$translated_for_postgresql, ?array &$dml_identity_repair_query, ?int &$replace_return_value, bool $mysql_update_ignore_query ) {
		switch ( $result_type ) {
			case 'execute_statement':
				return $this->execute_postgresql_statements( array( $translated_query ) );
			case 'execute_multi_target_delete':
				return $this->execute_mysql_multi_target_delete_query( $translated_query );
			case 'upsert':
			case 'replace':
				if ( 'replace' === $result_type && null !== $translated_query['conflict_column'] && empty( $translated_query['replace_select_materialized'] ) ) {
					$replace_return_value = $this->get_mysql_replace_return_value( $translated_query );
				}
				if ( 'upsert' === $result_type && ! empty( $translated_query['upsert_select_materialized'] ) ) {
					return $this->execute_materialized_mysql_upsert_select_statements( $translated_query );
				}
				if ( isset( $translated_query['statements'] ) && is_array( $translated_query['statements'] ) ) {
					if ( 'upsert' === $result_type ) {
						return $this->execute_translated_dml_statements( $translated_query );
					}
					return ! empty( $translated_query['replace_select_materialized'] )
						? $this->execute_materialized_mysql_replace_select_statements( $translated_query )
						: $this->execute_translated_dml_statements( $translated_query, $replace_return_value );
				}
				// Fall through.
			case 'dml':
				$dml_identity_repair_query = $translated_query;
				break;
			case 'multi_target_update':
				return $mysql_update_ignore_query
					? $this->execute_mysql_update_ignore_query( $translated_query, true )
					: $this->execute_mysql_multi_target_update_query( $translated_query );
			case 'update':
				if ( $mysql_update_ignore_query ) {
					return $this->execute_mysql_update_ignore_query( $translated_query );
				}
				break;
		}

		$query                     = is_array( $translated_query ) ? $translated_query['sql'] : $translated_query;
		$translated_for_postgresql = true;
		return null;
	}

	private function is_unsupported_mysql_update_rewrite_query( string $query ): bool {
		$update_tokens = $this->get_mysql_tokens( $query );
		if ( ! isset( $update_tokens[0] ) || WP_MySQL_Lexer::WITH_SYMBOL !== $update_tokens[0]->id ) {
			return isset( $update_tokens[0] ) && WP_MySQL_Lexer::UPDATE_SYMBOL === $update_tokens[0]->id;
		}
		$update_end = $this->get_mysql_statement_end_position( $update_tokens, 1 );
		return null !== $update_end && null !== $this->find_top_level_mysql_token( $update_tokens, WP_MySQL_Lexer::UPDATE_SYMBOL, 1, $update_end );
	}

	private function translate_wordpress_options_regexp_delete_query( string $query ): ?string {
		$tokens = $this->get_mysql_tokens( $query );
		foreach ( array(
			1 => WP_MySQL_Lexer::FROM_SYMBOL,
			3 => WP_MySQL_Lexer::WHERE_SYMBOL,
			5 => WP_MySQL_Lexer::REGEXP_SYMBOL,
			6 => array( WP_MySQL_Lexer::SINGLE_QUOTED_TEXT, WP_MySQL_Lexer::DOUBLE_QUOTED_TEXT ),
		) as $position => $token_ids ) {
			if ( ! isset( $tokens[ $position ] ) || ! in_array( $tokens[ $position ]->id, (array) $token_ids, true ) ) {
				return null;
			}
		}

		$table_name = $this->get_mysql_identifier_token_value( $tokens[2] ?? null );
		$column     = $this->get_mysql_identifier_token_value( $tokens[4] ?? null );
		if ( null === $table_name || null === $column || ! $this->is_wordpress_options_table_name( $table_name ) || ! $this->is_at_mysql_query_end( $tokens, 7 ) ) {
			return null;
		}
		return sprintf(
			'DELETE FROM %s WHERE %s ~* %s',
			$this->connection->quote_identifier( $table_name ),
			$this->connection->quote_identifier( $column ),
			$this->connection->quote( $tokens[6]->get_value() )
		);
	}
	private function translate_wordpress_expired_transients_delete_query( string $query ): ?string {
		$pattern = '/^\s*DELETE\s+a\s*,\s*b\s+FROM\s+([A-Za-z0-9_]+)\s+a\s*,\s*\1\s+b\s+WHERE\s+a\.option_name\s+LIKE\s+([\'"])([^\'"]+)\\2\s+AND\s+a\.option_name\s+NOT\s+LIKE\s+([\'"])([^\'"]+)\\4\s+AND\s+b\.option_name\s*=\s*CONCAT\s*\(\s*([\'"])([^\'"]+)\\6\s*,\s*SUBSTRING\s*\(\s*a\.option_name\s*,\s*([0-9]+)\s*\)\s*\)\s+AND\s+b\.option_value\s*<\s*([0-9]+)\s*;?\s*$/is';
		if ( ! preg_match( $pattern, $query, $matches ) ) {
			return null;
		}

		$table_name     = $matches[1];
		$value_like     = $matches[3];
		$timeout_like   = $matches[5];
		$timeout_prefix = $matches[7];
		$substring_from = (int) $matches[8];
		$expires_before = $matches[9];

		$transient_timeout_descriptors = array( array( '_transient_timeout_', 12 ), array( '_site_transient_timeout_', 17 ) );
		if ( ! $this->is_wordpress_options_table_name( $table_name ) || ! in_array( array( $timeout_prefix, $substring_from ), $transient_timeout_descriptors, true ) ) {
			return null;
		}
		$timeout_value_sql = $this->get_postgresql_mysql_numeric_cast_sql( 'b.option_value' );
		return sprintf(
			'WITH expired_transients AS (
	SELECT a.option_name AS value_name, b.option_name AS timeout_name
	FROM %1$s a
	INNER JOIN %1$s b
		ON b.option_name = %2$s || SUBSTR(a.option_name, %3$d)
	WHERE a.option_name LIKE %4$s ESCAPE %5$s
		AND a.option_name NOT LIKE %6$s ESCAPE %5$s
		AND %7$s < %8$s
)
DELETE FROM %1$s
WHERE option_name IN (
	SELECT value_name FROM expired_transients
	UNION
	SELECT timeout_name FROM expired_transients
)',
			$this->connection->quote_identifier( $table_name ),
			$this->connection->quote( $timeout_prefix ),
			$substring_from,
			$this->connection->quote( $value_like ),
			$this->connection->quote( '\\' ),
			$this->connection->quote( $timeout_like ),
			$timeout_value_sql,
			$expires_before
		);
	}
	private function translate_mysql_left_join_orphan_delete_query( string $query ): ?string {
		$tokens = $this->get_mysql_tokens( $query );
		foreach ( array( array( 2, WP_MySQL_Lexer::FROM_SYMBOL ), array( 5, WP_MySQL_Lexer::LEFT_SYMBOL ), array( 6, WP_MySQL_Lexer::JOIN_SYMBOL ), array( 9, WP_MySQL_Lexer::ON_SYMBOL ) ) as list( $position, $token_id ) ) {
			if ( ! isset( $tokens[ $position ] ) || $token_id !== $tokens[ $position ]->id ) {
				return null;
			}
		}

		$statement_end = $this->get_mysql_statement_end_position( $tokens, 10 );
		if ( null === $statement_end ) {
			return null;
		}

		$where_position = $this->find_top_level_mysql_token( $tokens, WP_MySQL_Lexer::WHERE_SYMBOL, 10, $statement_end );
		if ( null === $where_position || 10 >= $where_position || $where_position + 1 >= $statement_end ) {
			return null;
		}

		$identifiers = array(
			'delete_alias' => $this->get_mysql_identifier_token_value( $tokens[1] ?? null ),
			'target_table' => $this->get_mysql_identifier_token_value( $tokens[3] ?? null ),
			'target_alias' => $this->get_mysql_identifier_token_value( $tokens[4] ?? null ),
			'joined_table' => $this->get_mysql_identifier_token_value( $tokens[7] ?? null ),
			'joined_alias' => $this->get_mysql_identifier_token_value( $tokens[8] ?? null ),
		);
		if (
			in_array( null, $identifiers, true )
			|| strtolower( $identifiers['delete_alias'] ) !== strtolower( $identifiers['target_alias'] )
		) {
			return null;
		}

		if ( ! $this->is_mysql_null_rejected_join_alias_predicate( $tokens, $where_position + 1, $statement_end, $identifiers['joined_alias'] ) ) {
			return null;
		}
		return sprintf(
			'DELETE FROM %s AS %s WHERE NOT EXISTS (SELECT 1 FROM %s AS %s WHERE %s)',
			$this->connection->quote_identifier( $identifiers['target_table'] ),
			$this->translate_mysql_identifier_value_to_postgresql( $identifiers['target_alias'] ),
			$this->connection->quote_identifier( $identifiers['joined_table'] ),
			$this->translate_mysql_identifier_value_to_postgresql( $identifiers['joined_alias'] ),
			$this->translate_mysql_token_sequence_to_postgresql( $tokens, 10, $where_position )
		);
	}
	private function translate_mysql_multi_target_delete_query( string $query ): ?string {
		$tokens = $this->get_mysql_tokens( $query );
		if ( ! isset( $tokens[1] ) ) {
			return null;
		}

		$position = 1;
		$this->consume_mysql_delete_modifiers( $tokens, $position );

		$statement_end = $this->get_mysql_statement_end_position( $tokens, $position );
		if ( null === $statement_end || ! $this->is_at_mysql_query_end( $tokens, $statement_end ) ) {
			return null;
		}

		if ( ! isset( $tokens[ $position ] ) ) {
			return null;
		}

		if ( WP_MySQL_Lexer::FROM_SYMBOL === $tokens[ $position ]->id ) {
			$using_position = $this->find_top_level_mysql_token( $tokens, WP_MySQL_Lexer::USING_SYMBOL, $position + 1, $statement_end );
			if ( null === $using_position || $position + 1 >= $using_position || $using_position + 1 >= $statement_end ) {
				return null;
			}

			$target_aliases         = $this->parse_mysql_delete_target_aliases( $tokens, $position + 1, $using_position );
			$table_references_start = $using_position + 1;
		} else {
			$from_position = $this->find_top_level_mysql_token( $tokens, WP_MySQL_Lexer::FROM_SYMBOL, $position, $statement_end );
			if ( null === $from_position || $position >= $from_position || $from_position + 1 >= $statement_end ) {
				return null;
			}

			$target_aliases         = $this->parse_mysql_delete_target_aliases( $tokens, $position, $from_position );
			$table_references_start = $from_position + 1;
		}

		if ( null === $target_aliases ) {
			return null;
		}

		$clauses = $this->get_mysql_joined_dml_clause_positions( $tokens, $table_references_start, $statement_end );
		if ( null === $clauses ) {
			return null;
		}

		$from_end = $clauses['body_end'];
		if ( $table_references_start >= $from_end ) {
			return null;
		}

		$source_translation = $this->get_mysql_joined_dml_source_translation( $query, $tokens, $table_references_start, $from_end, true );
		if ( null === $source_translation ) {
			return null;
		}
		$scope      = $source_translation['scope'];
		$source_sql = $source_translation['sql'];

		$target_tables = array();
		$target_groups = array();
		foreach ( $target_aliases as $target_alias ) {
			$target_key = strtolower( $target_alias );
			if ( ! isset( $scope['aliases'][ $target_key ] ) ) {
				return null;
			}

			$target_table = $scope['aliases'][ $target_key ];
			$this->get_mysql_schema_aware_table_backend_schema(
				array(
					'schema' => $target_table['schema'],
					'table'  => $target_table['table'],
				),
				'DELETE'
			);
			$target_physical_name = strtolower( $target_table['schema'] . '.' . $target_table['table'] );
			if ( ! isset( $target_groups[ $target_physical_name ] ) ) {
				$target_groups[ $target_physical_name ] = array(
					'alias'        => $target_alias,
					'schema'       => $target_table['schema'],
					'table'        => $target_table['table'],
					'ctid_aliases' => array(),
				);
			}

			$target_tables[] = array(
				'alias'         => $target_alias,
				'table'         => $target_table['table'],
				'physical_name' => $target_physical_name,
			);
		}

		$where_sql = '';
		if ( null !== $clauses['where'] ) {
			$where_end = $clauses['order'] ?? $clauses['limit'] ?? $statement_end;
			if ( $clauses['where'] + 1 >= $where_end ) {
				return null;
			}

			$where = $this->translate_mysql_joined_dml_where_sql(
				$query,
				$tokens,
				$clauses['where'] + 1,
				$where_end,
				$scope,
				$source_translation['context'] ?? null,
				true
			);
			if ( null === $where ) {
				return null;
			}
			$where_sql = ' WHERE ' . $where;
		}

		$tail = $this->translate_simple_mysql_dml_order_limit_sql( $tokens, $clauses['order'], $clauses['limit'], $statement_end, $scope );
		if ( null === $tail ) {
			return null;
		}
		$order_sql = $tail['order'];
		$limit_sql = $tail['limit'];

		$select_columns = array();
		foreach ( $target_tables as $index => $target_table ) {
			$ctid_alias       = 'mysql_delete_target_' . $index . '_ctid';
			$target_alias_sql = $this->connection->quote_identifier( $target_table['alias'] );

			$select_columns[] = sprintf(
				'%s.ctid AS %s',
				$target_alias_sql,
				$this->connection->quote_identifier( $ctid_alias )
			);
			$target_groups[ $target_table['physical_name'] ]['ctid_aliases'][] = $ctid_alias;
		}

		$delete_ctes = array();
		$count_parts = array();
		foreach ( array_values( $target_groups ) as $index => $target_group ) {
			$delete_cte_name  = 'mysql_delete_target_' . $index;
			$target_alias_sql = $this->connection->quote_identifier( $target_group['alias'] );
			if ( 1 === count( $target_group['ctid_aliases'] ) ) {
				$ctid_predicate = sprintf(
					'%s.ctid = mysql_delete_rows.%s',
					$target_alias_sql,
					$this->connection->quote_identifier( $target_group['ctid_aliases'][0] )
				);
			} else {
				$ctid_selects = array();
				foreach ( $target_group['ctid_aliases'] as $ctid_alias ) {
					$ctid_selects[] = sprintf(
						'SELECT %s FROM mysql_delete_rows',
						$this->connection->quote_identifier( $ctid_alias )
					);
				}
				$ctid_predicate = sprintf(
					'%s.ctid IN (%s)',
					$target_alias_sql,
					implode( ' UNION ', $ctid_selects )
				);
			}

			$delete_ctes[] = sprintf(
				'%s AS (DELETE FROM %s AS %s USING mysql_delete_rows WHERE %s RETURNING 1)',
				$delete_cte_name,
				$this->get_postgresql_table_identifier_sql( $target_group['schema'], $target_group['table'] ),
				$target_alias_sql,
				$ctid_predicate
			);
			$count_parts[] = sprintf( '(SELECT COUNT(*) FROM %s)', $delete_cte_name );
		}
		return sprintf(
			'WITH mysql_delete_rows AS MATERIALIZED (SELECT %s FROM %s%s%s%s), %s SELECT %s AS affected_rows',
			implode( ', ', $select_columns ),
			$source_sql,
			$where_sql,
			$order_sql,
			$limit_sql,
			implode( ', ', $delete_ctes ),
			implode( ' + ', $count_parts )
		);
	}
	private function get_direct_information_schema_dml_source_translation( string $query, array $tokens, int $source_start, int $source_end ): ?array {
		$parsed_sources = $this->parse_direct_information_schema_select_sources( $query, $tokens, $source_start, $source_end );
		if ( null === $parsed_sources ) {
			return null;
		}

		$context = array(
			'sources'                     => $parsed_sources['sources'],
			'join_predicate_ranges'       => $parsed_sources['join_predicate_ranges'],
			'join_predicate_replacements' => $parsed_sources['join_predicate_replacements'],
			'using_columns'               => $parsed_sources['using_columns'],
		);

		$scope = array(
			'tables'  => array(),
			'aliases' => array(),
			'unknown' => false,
		);
		foreach ( $context['sources'] as $source ) {
			if ( ! isset( $source['table'] ) ) {
				continue;
			}

			$table = array(
				'schema' => $this->resolve_mysql_table_schema_for_introspection( 'public', $source['table'] ),
				'table'  => $source['table'],
			);
			$alias = strtolower( $source['alias'] );
			if ( isset( $scope['aliases'][ $alias ] ) ) {
				return null;
			}

			$scope['tables'][]          = $table;
			$scope['aliases'][ $alias ] = $table;
		}

		if ( empty( $scope['tables'] ) ) {
			return null;
		}

		$sql = $this->translate_direct_information_schema_range_to_postgresql(
			null,
			$tokens,
			$source_start,
			$source_end,
			$context,
			array(
				'replacements'                => $context['join_predicate_replacements'],
				'expression_ranges'           => $context['join_predicate_ranges'],
				'include_source_replacements' => true,
			)
		);
		if ( null === $sql ) {
			return null;
		}
		return array(
			'scope'   => $scope,
			'sql'     => $sql,
			'context' => $context,
		);
	}
	private function get_mysql_joined_dml_source_translation( string $query, array $tokens, int $source_start, int $source_end, bool $translate_non_public_schema ): ?array {
		if (
			0 === strcasecmp( $this->db_name, 'information_schema' )
			|| $this->direct_information_schema_source_range_references_information_schema( $tokens, $source_start, $source_end )
		) {
			return $this->get_direct_information_schema_dml_source_translation( $query, $tokens, $source_start, $source_end );
		}

		$scope = $this->get_mysql_select_scope( $tokens, $source_start, $source_end );
		if ( null === $scope || ! empty( $scope['unknown'] ) ) {
			return null;
		}
		$source_sql = $translate_non_public_schema && $this->mysql_scope_references_non_public_schema( $scope )
			? $this->translate_mysql_table_reference_range_to_postgresql( $tokens, $source_start, $source_end )
			: $this->translate_mysql_token_sequence_to_postgresql( $tokens, $source_start, $source_end );
		return null === $source_sql ? null : array(
			'scope' => $scope,
			'sql'   => $source_sql,
		);
	}
	private function translate_direct_information_schema_dml_predicate_to_postgresql( ?string $query, array $tokens, int $start, int $end, array $context ): ?string {
		return $this->translate_direct_information_schema_range_to_postgresql(
			$query,
			$tokens,
			$start,
			$end,
			$context,
			array(
				'nested_select_ranges'        => array(
					array(
						'start' => $start,
						'end'   => $end,
					),
				),
				'nested_selects_if_present'   => true,
				'reject_nested_select_unions' => true,
				'cover_nested_selects'        => true,
			)
		);
	}
	private function translate_mysql_joined_dml_where_sql( string $query, array $tokens, int $start, int $end, array $scope, ?array $information_schema_context, bool $require_supported_expression ): ?string {
		if ( null !== $information_schema_context ) {
			return $this->translate_direct_information_schema_dml_predicate_to_postgresql( $query, $tokens, $start, $end, $information_schema_context );
		}
		if ( $require_supported_expression && ! $this->is_supported_simple_mysql_expression_fragment( $tokens, $start, $end ) ) {
			return null;
		}
		$where = $this->translate_mysql_predicate_token_sequence_to_postgresql( $tokens, $start, $end, $scope );
		return $where['sql'];
	}
	private function parse_mysql_delete_target_aliases( array $tokens, int $start, int $end ): ?array {
		$aliases  = array();
		$position = $start;

		while ( $position < $end ) {
			$alias = $this->get_mysql_identifier_token_value( $tokens[ $position ] ?? null );
			if ( null === $alias ) {
				return null;
			}
			++$position;

			if (
				$position + 1 < $end
				&& WP_MySQL_Lexer::DOT_SYMBOL === ( $tokens[ $position ]->id ?? null )
				&& WP_MySQL_Lexer::MULT_OPERATOR === ( $tokens[ $position + 1 ]->id ?? null )
			) {
				$position += 2;
			}

			$alias_key = strtolower( $alias );
			if ( isset( $aliases[ $alias_key ] ) ) {
				return null;
			}
			$aliases[ $alias_key ] = $alias;

			if ( $position === $end ) {
				break;
			}

			if ( WP_MySQL_Lexer::COMMA_SYMBOL !== ( $tokens[ $position ]->id ?? null ) ) {
				return null;
			}
			++$position;
		}
		return empty( $aliases ) ? null : array_values( $aliases );
	}
	private function translate_mysql_single_target_join_delete_query( string $query ): ?string {
		$tokens = $this->get_mysql_tokens( $query );
		if ( WP_MySQL_Lexer::FROM_SYMBOL !== ( $tokens[2]->id ?? null ) ) {
			return null;
		}

		$statement_end = $this->get_mysql_statement_end_position( $tokens, 3 );
		if ( null === $statement_end || ! $this->is_at_mysql_query_end( $tokens, $statement_end ) ) {
			return null;
		}

		$clauses = $this->get_mysql_joined_dml_clause_positions( $tokens, 3, $statement_end );
		if ( null === $clauses || null === $clauses['where'] || 3 >= $clauses['where'] || $clauses['where'] + 1 >= $statement_end ) {
			return null;
		}
		$where_position = $clauses['where'];

		$delete_alias = $this->get_mysql_identifier_token_value( $tokens[1] ?? null );
		$target_ref   = $this->parse_mysql_table_reference( $tokens, 3, $where_position );
		if ( null === $delete_alias || null === $target_ref ) {
			return null;
		}
		$this->get_mysql_writable_table_backend_schema(
			array(
				'schema' => $target_ref['schema'],
				'table'  => $target_ref['table'],
			),
			'DELETE'
		);

		$target_alias        = $target_ref['alias'] ?? $target_ref['table'];
		$accepted_alias_keys = array_map( 'strtolower', array( $target_alias, $target_ref['table'] ) );
		if ( ! in_array( strtolower( $delete_alias ), $accepted_alias_keys, true ) ) {
			return null;
		}

		if ( null === $this->find_top_level_mysql_token( $tokens, WP_MySQL_Lexer::JOIN_SYMBOL, $target_ref['position'], $where_position ) ) {
			return null;
		}

		$source_translation = $this->get_mysql_joined_dml_source_translation( $query, $tokens, 3, $where_position, false );
		if ( null === $source_translation ) {
			return null;
		}
		$scope      = $source_translation['scope'];
		$source_sql = $source_translation['sql'];

		$where_end = $clauses['order'] ?? $clauses['limit'] ?? $statement_end;
		if ( $where_position + 1 >= $where_end ) {
			return null;
		}

		$where_sql = $this->translate_mysql_joined_dml_where_sql(
			$query,
			$tokens,
			$where_position + 1,
			$where_end,
			$scope,
			$source_translation['context'] ?? null,
			false
		);
		if ( null === $where_sql ) {
			return null;
		}

		$tail = $this->translate_simple_mysql_dml_order_limit_sql( $tokens, $clauses['order'], $clauses['limit'], $statement_end, $scope );
		if ( null === $tail ) {
			return null;
		}
		$order_sql = $tail['order'];
		$limit_sql = $tail['limit'];

		$target_alias_sql = $this->connection->quote_identifier( $target_alias );
		return sprintf(
			'DELETE FROM %s AS %s WHERE %s.ctid IN (SELECT %s.ctid FROM %s WHERE %s%s%s)',
			$this->connection->quote_identifier( $target_ref['table'] ),
			$target_alias_sql,
			$target_alias_sql,
			$target_alias_sql,
			$source_sql,
			$where_sql,
			$order_sql,
			$limit_sql
		);
	}
	private function is_mysql_null_rejected_join_alias_predicate( array $tokens, int $start, int $end, string $alias ): bool {
		if (
			$start + 5 !== $end
			|| WP_MySQL_Lexer::DOT_SYMBOL !== ( $tokens[ $start + 1 ]->id ?? null )
			|| WP_MySQL_Lexer::IS_SYMBOL !== ( $tokens[ $start + 3 ]->id ?? null )
			|| WP_MySQL_Lexer::NULL_SYMBOL !== ( $tokens[ $start + 4 ]->id ?? null )
		) {
			return false;
		}

		$predicate_alias = $this->get_mysql_identifier_token_value( $tokens[ $start ] ?? null );
		$column          = $this->get_mysql_identifier_token_value( $tokens[ $start + 2 ] ?? null );
		return null !== $predicate_alias
			&& null !== $column
			&& strtolower( $predicate_alias ) === strtolower( $alias );
	}
	private function translate_simple_mysql_delete_query( string $query ): ?string {
		$tokens   = $this->get_mysql_tokens( $query );
		$position = 1;
		$this->consume_mysql_delete_modifiers( $tokens, $position );
		if ( ! isset( $tokens[ $position ] ) || WP_MySQL_Lexer::FROM_SYMBOL !== $tokens[ $position ]->id ) {
			return null;
		}

		++$position;
		$statement_end = $this->get_mysql_statement_end_position( $tokens, $position );
		if ( null === $statement_end || ! $this->is_at_mysql_query_end( $tokens, $statement_end ) ) {
			return null;
		}

		$table_reference = $this->parse_mysql_main_database_table_reference( $tokens, $position, $statement_end );
		if ( null === $table_reference ) {
			return null;
		}

		$table_name = $table_reference['table'];
		$alias      = $table_reference['alias'];

		$where_position = $this->find_top_level_mysql_token( $tokens, WP_MySQL_Lexer::WHERE_SYMBOL, $position, $statement_end );
		$order_position = $this->find_top_level_mysql_token( $tokens, WP_MySQL_Lexer::ORDER_SYMBOL, $position, $statement_end );
		$limit_position = $this->find_top_level_mysql_token( $tokens, WP_MySQL_Lexer::LIMIT_SYMBOL, $position, $statement_end );

		if (
			( null !== $where_position && $where_position !== $position )
				|| ( null !== $order_position && null !== $where_position && $order_position < $where_position )
				|| ( null !== $limit_position && null !== $where_position && $limit_position < $where_position )
				|| ( null !== $limit_position && null !== $order_position && $limit_position < $order_position )
				|| ( null === $where_position && null === $order_position && null !== $limit_position && $limit_position !== $position )
			) {
			return null;
		}

		$unsupported_tokens             = array( WP_MySQL_Lexer::COMMA_SYMBOL, WP_MySQL_Lexer::JOIN_SYMBOL, WP_MySQL_Lexer::REGEXP_SYMBOL, WP_MySQL_Lexer::STRAIGHT_JOIN_SYMBOL, WP_MySQL_Lexer::USING_SYMBOL );
			$unsupported_token_scan_end = $order_position ?? $limit_position ?? $statement_end;
		if ( $this->contains_top_level_mysql_token( $tokens, $position, $unsupported_token_scan_end, $unsupported_tokens ) ) {
			return null;
		}

		$where_end = $order_position ?? $limit_position ?? $statement_end;
		$scope     = $this->get_mysql_single_table_scope( $table_name, $alias );
		$where     = $this->translate_simple_mysql_dml_where_sql( $query, $tokens, $where_position, $where_end, $scope );
		$tail      = $this->translate_simple_mysql_dml_order_limit_sql( $tokens, $order_position, $limit_position, $statement_end, $scope );
		if ( null === $where || null === $tail ) {
			return null;
		}
		$where_sql = $where['sql'];
		$order_sql = $tail['order'];
		$limit_sql = $tail['limit'];

		$table_sql = $this->get_postgresql_dml_table_reference_sql( $table_name, $alias );
		if ( '' !== $order_sql || '' !== $limit_sql ) {
			$subquery_where_sql = null === $where_sql ? '' : ' WHERE ' . $where_sql;
			return sprintf(
				'DELETE FROM %s WHERE %s IN (SELECT %s FROM %s%s%s%s)',
				$table_sql,
				$this->get_postgresql_dml_ctid_reference_sql( $alias ),
				$this->get_postgresql_dml_ctid_reference_sql( $alias ),
				$table_sql,
				$subquery_where_sql,
				$order_sql,
				$limit_sql
			);
		}

		$sql = 'DELETE FROM ' . $table_sql;
		if ( null !== $where_sql ) {
			$sql .= ' WHERE ' . $where_sql;
		}
		return $sql;
	}
	private function translate_simple_mysql_dml_where_sql( string $query, array $tokens, ?int $where_position, int $where_end, array $scope, array $cte_names = array() ): ?array {
		if ( null === $where_position ) {
			return array( 'sql' => null );
		}

		$where_replacements = $this->get_simple_mysql_dml_predicate_nested_select_replacements( $query, $tokens, $where_position + 1, $where_end, $cte_names );
		if (
			$where_position + 1 >= $where_end
			|| null === $where_replacements
			|| ! $this->is_supported_simple_mysql_expression_fragment_with_replacements( $tokens, $where_position + 1, $where_end, $where_replacements )
		) {
			return null;
		}

		$where = $this->translate_mysql_predicate_token_sequence_to_postgresql(
			$tokens,
			$where_position + 1,
			$where_end,
			$scope,
			$where_replacements
		);
		return array( 'sql' => $where['sql'] );
	}
	private function translate_simple_mysql_dml_order_limit_sql( array $tokens, ?int $order_position, ?int $limit_position, int $statement_end, array $scope ): ?array {
		$order_sql = '';
		if ( null !== $order_position ) {
			$order_sql = $this->translate_mysql_joined_dml_order_by_clause_to_postgresql( $tokens, $order_position, $limit_position ?? $statement_end, $scope );
			if ( null === $order_sql ) {
				return null;
			}
		}

		$limit_sql = '';
		if ( null !== $limit_position ) {
			$limit_sql = $this->translate_simple_dml_limit_clause_to_postgresql( $tokens, $limit_position, $statement_end, true );
			if ( null === $limit_sql ) {
				return null;
			}
		}
		return array(
			'order' => $order_sql,
			'limit' => $limit_sql,
		);
	}
	private function translate_mysql_on_duplicate_key_update_query( string $query ): ?array {
		$tokens        = $this->get_mysql_tokens( $query );
		$statement_end = $this->get_mysql_statement_end_position( $tokens, 1 );
		if ( null === $statement_end ) {
			return null;
		}

		$position = 1;
		$ignore   = false;
		$header   = $this->parse_mysql_insert_table_header( $tokens, $position, $ignore );
		if ( null === $header ) {
			return null;
		}
		$table_name            = $header['table'];
		$table_reference_start = $header['start'];
		$table_reference_end   = $header['end'];

		$column_metadata = null;
		$on_duplicate    = $this->find_on_duplicate_key_update_clause( $tokens, $position );
		if ( null === $on_duplicate ) {
			return null;
		}

		$source = $this->parse_mysql_insert_like_dml_source(
			$table_name,
			$tokens,
			$position,
			$on_duplicate,
			$column_metadata,
			array(
				'allow_values_alias' => true,
			)
		);
		if ( null === $source ) {
			return null;
		}
		$columns                   = $source['columns'];
		$value_rows                = $source['value_rows'];
		$value_range_rows          = $source['value_range_rows'];
		$probe_safe_rows           = $source['probe_safe_rows'];
		$upsert_source_aliases     = $source['source_aliases'];
		$insert_select_column_list = $source['insert_column_list'];

		if ( $this->is_mysql_dml_select_source_token( $tokens[ $position ] ?? null ) ) {
			return $this->translate_mysql_insert_select_on_duplicate_key_update_query(
				$query,
				$table_name,
				$columns,
				$tokens,
				$position,
				$on_duplicate,
				$statement_end,
				$table_reference_start,
				$table_reference_end,
				$insert_select_column_list
			);
		}

		$column_metadata = $this->normalize_mysql_dml_value_rows_for_columns(
			$table_name,
			$columns,
			$value_rows,
			$value_range_rows,
			$tokens,
			$column_metadata
		);

		$table_column_lookup = $this->get_mysql_dml_column_metadata_lookup_from_rows( $column_metadata );
		$upsert_plan         = $this->get_mysql_upsert_write_plan(
			$table_name,
			$columns,
			$value_rows,
			$probe_safe_rows,
			array(
				'allow_omitted_conflict_target'    => true,
				'allow_unresolved_conflict_target' => true,
				'table_column_lookup'              => $table_column_lookup,
			)
		);
		if ( null === $upsert_plan ) {
			return null;
		}
		$conflict_target              = $upsert_plan['conflict_target'];
		$conflict_columns             = $upsert_plan['conflict_columns'];
		$conflict_indexes             = $upsert_plan['conflict_indexes'];
		$uses_omitted_conflict_target = $upsert_plan['uses_omitted_conflict_target'];

		$column_lookup = $this->get_mysql_dml_column_lookup( $columns );

		$position = $on_duplicate + 4;

		$assignment_effects = array();
		$assignments        = $this->parse_upsert_update_assignments( $table_name, $tokens, $position, $statement_end, $column_lookup, $table_column_lookup, $upsert_source_aliases, $assignment_effects );
		if ( null === $assignments || ! $this->is_at_mysql_query_end( $tokens, $position ) ) {
			return null;
		}

		if ( null === $conflict_target ) {
			if ( count( $value_rows ) < 2 ) {
				return null;
			}

			if ( isset( $assignment_effects['last_insert_id_column'] ) ) {
				return null;
			}

			$per_row_upsert = $this->get_mysql_per_row_upsert_statements_for_ambiguous_targets(
				$table_name,
				$columns,
				$value_rows,
				$probe_safe_rows,
				$assignments,
				$assignment_effects['assigned_columns'] ?? array()
			);
			if ( null === $per_row_upsert ) {
				return null;
			}
			return $this->get_mysql_upsert_values_query(
				$table_name,
				$columns,
				$per_row_upsert['inserted_value_rows'],
				$value_rows,
				null,
				$per_row_upsert['conflict_columns'],
				array(
					'statements'            => $per_row_upsert['statements'],
					'conflict_index_groups' => $per_row_upsert['conflict_index_groups'],
				)
			);
		}

		if ( $uses_omitted_conflict_target ) {
			$inserted_value_rows = $value_rows;
		} else {
			$inserted_value_rows = $this->get_mysql_upsert_inserted_value_rows(
				$table_name,
				$columns,
				$value_rows,
				$probe_safe_rows,
				$conflict_target['parts']
			);
			if ( null === $inserted_value_rows ) {
				return null;
			}
		}

		$last_insert_id_fields = $this->get_mysql_upsert_last_insert_id_query_fields(
			$table_name,
			$assignment_effects,
			null,
			$value_rows,
			$probe_safe_rows,
			$conflict_indexes,
			count( $inserted_value_rows ) > 0
		);
		if ( false === $last_insert_id_fields ) {
			return null;
		}

		$conflict_sql = $this->get_postgresql_dml_conflict_update_sql( $conflict_target, $assignments );
		$upsert_query = $this->get_mysql_upsert_values_query(
			$table_name,
			$columns,
			$inserted_value_rows,
			$value_rows,
			$this->get_postgresql_dml_insert_values_sql(
				$this->get_postgresql_unqualified_dml_table_reference_sql( $table_name ),
				$columns,
				$value_rows,
				$conflict_sql
			),
			$conflict_columns,
			array(
				'conflict_indexes' => $conflict_indexes,
			)
		);
		$upsert_query = array_merge( $upsert_query, $last_insert_id_fields );

		if ( null !== $conflict_indexes && $this->has_duplicate_mysql_replace_conflict_value_rows( $value_rows, $probe_safe_rows, $conflict_indexes ) ) {
			$upsert_query['statements'] = $this->get_postgresql_dml_insert_value_row_statements( $table_name, $columns, $value_rows, $conflict_sql );
		}
		return $upsert_query;
	}
	private function get_mysql_upsert_values_query( string $table_name, array $columns, array $inserted_value_rows, array $insert_id_value_rows, ?string $sql, array $conflict_columns, array $extra = array() ): array {
		$query = array(
			'action'               => 'upsert',
			'table_name'           => $table_name,
			'columns'              => $columns,
			'values'               => $inserted_value_rows[0] ?? array(),
			'value_rows'           => $inserted_value_rows,
			'insert_id_value_rows' => $insert_id_value_rows,
			'conflict_columns'     => $conflict_columns,
			'inserted_new_row'     => count( $inserted_value_rows ) > 0,
		);
		if ( null !== $sql ) {
			$query['sql'] = $sql;
		}
		return array_merge( $query, $extra );
	}
	private function is_unsupported_mysql_on_duplicate_key_update_query( string $query ): bool {
		$tokens = $this->get_mysql_tokens( $query );
		return isset( $tokens[0] )
			&& WP_MySQL_Lexer::INSERT_SYMBOL === $tokens[0]->id
			&& null !== $this->find_on_duplicate_key_update_clause( $tokens, 1 );
	}
	private function translate_mysql_insert_select_on_duplicate_key_update_query(
		string $query,
		string $table_name,
		array $columns,
		array $tokens,
		int $position,
		int $on_duplicate,
		int $statement_end,
		int $table_reference_start,
		int $table_reference_end,
		bool $insert_column_list = false
	): ?array {
		$rewrite = $this->get_mysql_insert_select_rewrite_data(
			$query,
			$table_name,
			$columns,
			$tokens,
			$position,
			$on_duplicate,
			$table_reference_start,
			$table_reference_end,
			$insert_column_list,
			array(),
			'',
			false
		);
		if ( null === $rewrite ) {
			return null;
		}
		$select_start = $rewrite['select_start'];
		$select_end   = $rewrite['select_end'];
		$insert_sql   = $rewrite['sql'];

		$table_column_lookup       = $this->get_mysql_dml_column_metadata_lookup( $table_name );
		$auto_increment_column     = $this->get_mysql_auto_increment_column_from_metadata( $table_column_lookup );
		$literal_value_row         = $this->get_mysql_insert_select_upsert_literal_value_row(
			$table_name,
			$columns,
			$tokens,
			$select_start,
			$select_end
		);
		$literal_value_rows        = null === $literal_value_row ? null : array( $literal_value_row['values'] );
		$literal_probe_safe_rows   = null === $literal_value_row ? null : array( $literal_value_row['probe_safe_values'] );
		$explicit_identity_columns = array();
		$upsert_plan               = $this->get_mysql_upsert_write_plan(
			$table_name,
			$columns,
			$literal_value_rows,
			$literal_probe_safe_rows,
			array(
				'allow_ambiguous_conflict_candidates' => null === $literal_value_row,
			)
		);
		if ( null === $upsert_plan ) {
			return null;
		}
		$conflict_target               = $upsert_plan['conflict_target'];
		$conflict_columns              = $upsert_plan['conflict_columns'];
		$conflict_indexes              = $upsert_plan['conflict_indexes'];
		$ambiguous_conflict_candidates = $upsert_plan['ambiguous_conflict_candidates'];

		$column_lookup = $this->get_mysql_dml_column_lookup( $columns );

		$assignment_position = $on_duplicate + 4;
		$assignment_effects  = array();
		$assignments         = $this->parse_upsert_update_assignments(
			$table_name,
			$tokens,
			$assignment_position,
			$statement_end,
			$column_lookup,
			$table_column_lookup,
			array(),
			$assignment_effects
		);
		if ( null === $assignments || ! $this->is_at_mysql_query_end( $tokens, $assignment_position ) ) {
			return null;
		}

		if ( null === $conflict_target ) {
			if ( isset( $assignment_effects['last_insert_id_column'] ) ) {
				return null;
			}

			if (
				null !== $auto_increment_column
				&& ! $this->can_mysql_insert_select_upsert_skip_auto_increment_literal_probe(
					$auto_increment_column,
					$columns,
					$conflict_columns
				)
			) {
				if ( ! $this->mysql_dml_column_list_contains_column( $columns, $auto_increment_column ) ) {
					return null;
				}

				$explicit_identity_columns[ strtolower( $auto_increment_column ) ] = true;
			}

			$materialized_flow = $this->get_mysql_insert_select_upsert_materialized_flow_for_ambiguous_targets(
				$table_name,
				$columns,
				$tokens,
				$select_start,
				$select_end,
				$ambiguous_conflict_candidates,
				$assignments,
				$assignment_effects['assigned_columns'] ?? array()
			);
			if ( null === $materialized_flow ) {
				return null;
			}
			return $this->get_mysql_upsert_select_query(
				$table_name,
				$columns,
				$insert_sql,
				$conflict_columns,
				null,
				null,
				null,
				$explicit_identity_columns,
				$materialized_flow
			);
		}

		$last_insert_id_fields = $this->get_mysql_upsert_last_insert_id_query_fields(
			$table_name,
			$assignment_effects,
			$literal_value_row,
			null,
			null,
			$conflict_indexes,
			false
		);
		if ( false === $last_insert_id_fields ) {
			return null;
		}
		$conflict_sql = $this->get_postgresql_dml_conflict_update_sql( $conflict_target, $assignments );

		$inserted_value_rows  = null;
		$insert_id_value_rows = null;
		if ( null !== $auto_increment_column ) {
			if ( null === $literal_value_row ) {
				if (
					! $this->can_mysql_insert_select_upsert_skip_auto_increment_literal_probe(
						$auto_increment_column,
						$columns,
						$conflict_columns
					)
				) {
					if ( ! $this->mysql_dml_column_list_contains_column( $columns, $auto_increment_column ) ) {
						return null;
					}

					$explicit_identity_columns[ strtolower( $auto_increment_column ) ] = true;
				}
			} else {
				$insert_id_value_rows = array( $literal_value_row['insert_id_values'] );
				$inserted_value_rows  = $this->get_mysql_upsert_inserted_value_rows(
					$table_name,
					$columns,
					$insert_id_value_rows,
					array( $literal_value_row['probe_safe_values'] ),
					$conflict_target['parts']
				);
				if ( null === $inserted_value_rows ) {
					return null;
				}
			}
		}

		$upsert_query = $this->get_mysql_upsert_select_query(
			$table_name,
			$columns,
			sprintf(
				'%s %s',
				$insert_sql,
				$conflict_sql
			),
			$conflict_columns,
			$conflict_indexes,
			$inserted_value_rows,
			$insert_id_value_rows,
			$explicit_identity_columns
		);
		$upsert_query = array_merge( $upsert_query, $last_insert_id_fields );

		if ( null === $literal_value_row ) {
			$materialized_flow = $this->get_mysql_insert_select_upsert_materialized_flow(
				$table_name,
				$columns,
				$tokens,
				$select_start,
				$select_end,
				$conflict_indexes,
				$conflict_target['parts'],
				$conflict_sql
			);
			if ( null === $materialized_flow ) {
				return null;
			}

			$upsert_query = array_merge( $upsert_query, $materialized_flow );
		}
		return $upsert_query;
	}
	private function get_mysql_upsert_select_query( string $table_name, array $columns, string $sql, array $conflict_columns, ?array $conflict_indexes, ?array $value_rows, ?array $insert_id_value_rows, array $explicit_identity_columns, array $extra = array() ): array {
		$query = array(
			'action'                    => 'upsert',
			'sql'                       => $sql,
			'table_name'                => $table_name,
			'columns'                   => $columns,
			'conflict_columns'          => $conflict_columns,
			'inserted_new_row'          => null === $value_rows ? true : count( $value_rows ) > 0,
			'value_rows'                => $value_rows,
			'insert_id_value_rows'      => $insert_id_value_rows,
			'insert_id_unknown'         => ! empty( $explicit_identity_columns ),
			'explicit_identity_columns' => $explicit_identity_columns,
		);
		if ( null !== $conflict_indexes ) {
			$query['conflict_indexes'] = $conflict_indexes;
		}
		return array_merge( $query, $extra );
	}
	private function get_mysql_insert_select_upsert_materialized_flow( string $table_name, array $columns, array $tokens, int $select_start, int $select_end, array $conflict_indexes, array $conflict_parts, string $conflict_sql ): ?array {
		$select_sql = $this->get_mysql_replace_select_source_sql(
			$table_name,
			$columns,
			array(),
			$tokens,
			$select_start,
			$select_end
		);
		if ( null === $select_sql ) {
			return null;
		}

		$table_context = $this->get_mysql_materialized_dml_table_context(
			'__wp_pg_upsert_select_',
			'__wp_pg_upsert_select_ord_',
			'upsert-select' . "\0" . $table_name . "\0" . $select_start . "\0" . $select_end . "\0" . implode( "\0", $columns )
		);
		$rows_alias    = $this->connection->quote_identifier( '__wp_pg_upsert_rows' );

		$duplicate_conflict_rows_sql = $this->get_mysql_replace_select_duplicate_conflict_rows_sql(
			$table_context['source_table_sql'],
			$rows_alias,
			array( $conflict_indexes )
		);
		if ( null === $duplicate_conflict_rows_sql ) {
			return null;
		}

		$insert_sql = $this->get_postgresql_dml_insert_from_source_sql(
			$table_name,
			$columns,
			$table_context['source_table_sql'],
			$rows_alias,
			'WHERE 1 = 1 ' . $conflict_sql
		);
		return $this->get_mysql_materialized_dml_flow(
			$select_sql,
			$table_context,
			array( $insert_sql ),
			array(
				'upsert_select_materialized'  => true,
				'duplicate_conflict_rows_sql' => $duplicate_conflict_rows_sql,
				'conflict_sql'                => $conflict_sql,
				'conflict_parts'              => $conflict_parts,
			)
		);
	}
	private function get_mysql_materialized_dml_table_context( string $table_prefix, string $ordinal_prefix, string $hash_input ): array {
		$table_hash = substr( md5( $hash_input ), 0, 12 );
		$table_sql  = $this->connection->quote_identifier( $table_prefix . $table_hash );
		return array(
			'source_table_sql'         => $table_sql,
			'ordinal_source_table_sql' => $this->connection->quote_identifier( $ordinal_prefix . $table_hash ),
			'drop_sql'                 => sprintf( 'DROP TABLE IF EXISTS %s', $table_sql ),
		);
	}
	private function get_mysql_materialized_dml_flow( string $select_sql, array $table_context, array $mutation_statements, array $fields, bool $include_statements = false ): array {
		$flow = array(
			'materialize_statements'   => array(
				$table_context['drop_sql'],
				sprintf( 'CREATE TEMPORARY TABLE %s AS %s', $table_context['source_table_sql'], $select_sql ),
			),
			'mutation_statements'      => $mutation_statements,
			'cleanup_statements'       => array( $table_context['drop_sql'] ),
			'source_table_sql'         => $table_context['source_table_sql'],
			'ordinal_source_table_sql' => $table_context['ordinal_source_table_sql'],
		);
		if ( $include_statements ) {
			$flow['statements'] = array_merge( $flow['materialize_statements'], $mutation_statements, $flow['cleanup_statements'] );
		}
		return array_merge( $flow, $fields );
	}
	private function get_mysql_insert_select_upsert_materialized_flow_for_ambiguous_targets( string $table_name, array $columns, array $tokens, int $select_start, int $select_end, array $candidates, array $assignments, array $assigned_columns ): ?array {
		if ( count( $candidates ) < 2 ) {
			return null;
		}

		$conflict_targets      = array();
		$conflict_index_groups = array();
		foreach ( $candidates as $candidate ) {
			foreach ( $candidate['parts'] as $part ) {
				if ( isset( $assigned_columns[ strtolower( (string) ( $part['column'] ?? '' ) ) ] ) ) {
					return null;
				}
			}

			$conflict_indexes = $this->get_mysql_upsert_conflict_indexes( $columns, $candidate['parts'] );
			if ( null === $conflict_indexes ) {
				return null;
			}

			$conflict_targets[]      = $this->get_mysql_upsert_conflict_target_from_candidate( $candidate );
			$conflict_index_groups[] = $conflict_indexes;
		}

		$select_sql = $this->get_mysql_replace_select_source_sql(
			$table_name,
			$columns,
			array(),
			$tokens,
			$select_start,
			$select_end
		);
		if ( null === $select_sql ) {
			return null;
		}

		$table_context = $this->get_mysql_materialized_dml_table_context(
			'__wp_pg_upsert_select_',
			'__wp_pg_upsert_select_ord_',
			'upsert-select-ambiguous' . "\0" . $table_name . "\0" . $select_start . "\0" . $select_end . "\0" . implode( "\0", $columns )
		);
		return $this->get_mysql_materialized_dml_flow(
			$select_sql,
			$table_context,
			array(),
			array(
				'upsert_select_materialized'               => true,
				'upsert_select_ambiguous_conflict_targets' => true,
				'conflict_targets'                         => $conflict_targets,
				'conflict_index_groups'                    => $conflict_index_groups,
				'assignments'                              => $assignments,
			)
		);
	}
	private function mysql_dml_column_list_contains_column( array $columns, string $column_name ): bool {
		foreach ( $columns as $column ) {
			if ( 0 === strcasecmp( (string) $column, $column_name ) ) {
				return true;
			}
		}
		return false;
	}
	private function can_mysql_insert_select_upsert_skip_auto_increment_literal_probe( string $auto_increment_column, array $columns, array $conflict_columns ): bool {
		$columns = array_merge( $columns, $conflict_columns );
		return ! $this->mysql_dml_column_list_contains_column( $columns, $auto_increment_column );
	}
	private function get_mysql_insert_select_upsert_literal_value_row( string $table_name, array $columns, array $tokens, int $select_start, int $select_end ): ?array {
		$from_position = $this->find_top_level_mysql_token( $tokens, WP_MySQL_Lexer::FROM_SYMBOL, $select_start + 1, $select_end );
		if ( null !== $from_position ) {
			if (
				$from_position + 2 !== $select_end
				|| WP_MySQL_Lexer::DUAL_SYMBOL !== ( $tokens[ $from_position + 1 ]->id ?? null )
			) {
				return null;
			}
		}

		$projection_end    = $from_position ?? $select_end;
		$projection_ranges = $this->split_top_level_mysql_arguments( $tokens, $select_start + 1, $projection_end );
		if ( null === $projection_ranges || count( $projection_ranges ) !== count( $columns ) ) {
			return null;
		}

		$target_metadata   = $this->get_mysql_dml_column_metadata_lookup( $table_name );
		$values            = array();
		$insert_id_values  = array();
		$probe_safe_values = array();
		foreach ( $projection_ranges as $index => $range ) {
			$probe_safe                  = $this->is_supported_mysql_upsert_conflict_probe_token_sequence( $tokens, $range['start'], $range['end'] );
			$constant_expression         = false;
			$constant_integer_expression = null;
			if ( ! $probe_safe ) {
				$constant_expression = $this->is_supported_mysql_upsert_literal_select_expression( $tokens, $range['start'], $range['end'] );
				if ( ! $constant_expression ) {
					return null;
				}

				$constant_integer_expression = $this->get_mysql_constant_integer_expression_value( $tokens, $range['start'], $range['end'] );
				$probe_safe                  = true;
			}

			$column_key      = strtolower( (string) $columns[ $index ] );
			$projection_sql  = $this->translate_mysql_token_sequence_to_postgresql( $tokens, $range['start'], $range['end'] );
			$insert_id_sql   = $projection_sql;
			$column_metadata = $target_metadata[ $column_key ] ?? null;
			if ( null !== $column_metadata ) {
				$coerced_sql = $this->get_mysql_insert_select_projection_sql_for_target_column(
					$table_name,
					$column_metadata,
					$tokens,
					$range['start'],
					$range['end'],
					$projection_sql,
					null
				);
				if ( null !== $coerced_sql ) {
					$projection_sql = $coerced_sql;
				}

				if (
					$this->is_mysql_auto_increment_column_metadata( $column_metadata )
				) {
					if ( $this->is_mysql_generated_auto_increment_value_sql( $projection_sql ) ) {
						$insert_id_sql = $projection_sql;
					} else {
						if ( null === $constant_integer_expression && $constant_expression ) {
							return null;
						}

						if ( null !== $constant_integer_expression ) {
							$insert_id_sql = $constant_integer_expression;
						}
					}
				}
			}

			$values[]            = $projection_sql;
			$insert_id_values[]  = $insert_id_sql;
			$probe_safe_values[] = $probe_safe;
		}
		return array(
			'values'            => $values,
			'insert_id_values'  => $insert_id_values,
			'probe_safe_values' => $probe_safe_values,
		);
	}
	private function parse_mysql_values_rows( array $tokens, int &$position, int $end, int $expected_count, array &$probe_safe_rows, array &$value_range_rows ): ?array {
		$rows             = array();
		$probe_safe_rows  = array();
		$value_range_rows = array();

		while ( $position < $end ) {
			$probe_safe_values = array();
			$parsed_values     = $this->parse_mysql_value_list_with_probe_safety( $tokens, $position, $probe_safe_values );
			if ( null === $parsed_values || count( $parsed_values['values'] ) !== $expected_count ) {
				return null;
			}

			$rows[]             = $parsed_values['values'];
			$probe_safe_rows[]  = $probe_safe_values;
			$value_range_rows[] = $parsed_values['ranges'];

			if (
				$position === $end
				|| WP_MySQL_Lexer::AS_SYMBOL === ( $tokens[ $position ]->id ?? null )
			) {
				return $rows;
			}

			if ( ! isset( $tokens[ $position ] ) || WP_MySQL_Lexer::COMMA_SYMBOL !== $tokens[ $position ]->id ) {
				return null;
			}

			++$position;
		}
		return count( $rows ) > 0 ? $rows : null;
	}
	private function get_postgresql_dml_values_rows_sql( array $value_rows ): string {
		$sql_rows = array();
		foreach ( $value_rows as $values ) {
			$sql_rows[] = '(' . implode( ', ', $values ) . ')';
		}
		return implode( ', ', $sql_rows );
	}
	private function parse_mysql_upsert_values_alias_clause( array $tokens, int &$position, int $end, array $columns ): ?array {
		if ( $position === $end ) {
			return array();
		}

		if ( ! isset( $tokens[ $position ] ) || WP_MySQL_Lexer::AS_SYMBOL !== $tokens[ $position ]->id ) {
			return null;
		}

		$row_alias = $this->get_mysql_dml_identifier_token_value( $tokens[ $position + 1 ] ?? null );
		if ( null === $row_alias ) {
			return null;
		}

		$position += 2;

		$qualified = array();
		foreach ( $columns as $column ) {
			$qualified[ strtolower( $column ) ] = $column;
		}

		$unqualified = array();
		if ( $position < $end && WP_MySQL_Lexer::OPEN_PAR_SYMBOL === ( $tokens[ $position ]->id ?? null ) ) {
			$column_aliases = $this->parse_mysql_identifier_list( $tokens, $position );
			if ( null === $column_aliases || count( $column_aliases ) !== count( $columns ) ) {
				return null;
			}

			$seen_aliases = array();
			foreach ( $column_aliases as $index => $column_alias ) {
				$alias_key = strtolower( $column_alias );
				if ( isset( $seen_aliases[ $alias_key ] ) ) {
					return null;
				}

				$seen_aliases[ $alias_key ] = true;
				$qualified[ $alias_key ]    = $columns[ $index ];
				$unqualified[ $alias_key ]  = $columns[ $index ];
			}
		}

		if ( $position !== $end ) {
			return null;
		}
		return array(
			'row'         => strtolower( $row_alias ),
			'qualified'   => $qualified,
			'unqualified' => $unqualified,
		);
	}
	private function parse_mysql_value_list_with_probe_safety( array $tokens, int &$position, array &$probe_safety ): ?array {
		if ( ! isset( $tokens[ $position ] ) || WP_MySQL_Lexer::OPEN_PAR_SYMBOL !== $tokens[ $position ]->id ) {
			return null;
		}

		++$position;
		$values       = array();
		$ranges       = array();
		$probe_safety = array();
		$value_start  = $position;
		$depth        = 0;

		while ( isset( $tokens[ $position ] ) && WP_MySQL_Lexer::EOF !== $tokens[ $position ]->id ) {
			if ( WP_MySQL_Lexer::OPEN_PAR_SYMBOL === $tokens[ $position ]->id ) {
				++$depth;
				++$position;
				continue;
			}

			if ( WP_MySQL_Lexer::CLOSE_PAR_SYMBOL === $tokens[ $position ]->id ) {
				if ( 0 === $depth ) {
					if ( $value_start === $position ) {
						return null;
					}

					$values[]       = $this->translate_mysql_token_sequence_to_postgresql( $tokens, $value_start, $position );
					$ranges[]       = array(
						'start' => $value_start,
						'end'   => $position,
					);
					$probe_safety[] = $this->is_supported_mysql_upsert_conflict_probe_token_sequence( $tokens, $value_start, $position );
					++$position;
					return array(
						'values' => $values,
						'ranges' => $ranges,
					);
				}

				--$depth;
				++$position;
				continue;
			}

			if ( 0 === $depth && WP_MySQL_Lexer::COMMA_SYMBOL === $tokens[ $position ]->id ) {
				if ( $value_start === $position ) {
					return null;
				}

				$values[]       = $this->translate_mysql_token_sequence_to_postgresql( $tokens, $value_start, $position );
				$ranges[]       = array(
					'start' => $value_start,
					'end'   => $position,
				);
				$probe_safety[] = $this->is_supported_mysql_upsert_conflict_probe_token_sequence( $tokens, $value_start, $position );
				$value_start    = $position + 1;
			}

			++$position;
		}
		return null;
	}
	private function is_supported_mysql_upsert_conflict_probe_token_sequence( array $tokens, int $start, int $end ): bool {
		if ( $start + 1 !== $end || ! isset( $tokens[ $start ] ) ) {
			return false;
		}
		return in_array( $tokens[ $start ]->id, self::MYSQL_UPSERT_CONFLICT_PROBE_LITERAL_TOKENS, true );
	}
	private function is_supported_mysql_upsert_literal_select_expression( array $tokens, int $start, int $end ): bool {
		if ( $start >= $end ) {
			return false;
		}

		for ( $position = $start; $position < $end; $position++ ) {
			if (
				null !== $this->get_mysql_identifier_token_value( $tokens[ $position ] )
				|| in_array( $tokens[ $position ]->id, array( WP_MySQL_Lexer::COMMA_SYMBOL, WP_MySQL_Lexer::DOT_SYMBOL ), true )
			) {
				return false;
			}

			if ( ! $this->is_supported_simple_mysql_expression_token( $tokens[ $position ] ) ) {
				return false;
			}
		}
		return true;
	}
	private function get_mysql_upsert_conflict_target(
		string $table_name,
		array $columns,
		?array $value_rows = null,
		?array $probe_safe_rows = null,
		?array $unique_index_metadata_rows = null
	): ?array {
		$insert_columns = array_map( 'strtolower', $columns );
		sort( $insert_columns, SORT_STRING );

		$table_schema = $this->get_mysql_unqualified_dml_table_backend_schema( $table_name );
		$cache_key    = $table_schema . "\0" . $table_name . "\0" . serialize( $insert_columns );
		if ( array_key_exists( $cache_key, $this->mysql_upsert_conflict_target_cache ) ) {
			$cached = $this->mysql_upsert_conflict_target_cache[ $cache_key ];
			return null === $cached ? null : $cached;
		}

		$candidates = $this->get_mysql_upsert_conflict_target_candidates( $table_name, $columns, false, $unique_index_metadata_rows );

		if ( 1 !== count( $candidates ) ) {
			if ( null !== $value_rows && null !== $probe_safe_rows && count( $candidates ) > 1 ) {
				return $this->get_mysql_upsert_conflict_target_for_value_rows(
					$table_name,
					$columns,
					$candidates,
					$value_rows,
					$probe_safe_rows
				);
			}
			return null;
		}

		$conflict_target = $this->get_mysql_upsert_conflict_target_from_candidate( $candidates[0] );

		$this->mysql_upsert_conflict_target_cache[ $cache_key ] = $conflict_target;
		return $conflict_target;
	}
	private function get_mysql_upsert_write_plan( string $table_name, array $columns, ?array $value_rows = null, ?array $probe_safe_rows = null, array $options = array() ): ?array {
		$conflict_target              = $this->get_mysql_upsert_conflict_target( $table_name, $columns, $value_rows, $probe_safe_rows );
		$uses_omitted_conflict_target = false;

		if (
			null === $conflict_target
			&& ! empty( $options['allow_omitted_conflict_target'] )
			&& null !== $value_rows
			&& 1 === count( $value_rows )
		) {
			$table_column_lookup = isset( $options['table_column_lookup'] ) && is_array( $options['table_column_lookup'] )
				? $options['table_column_lookup']
				: $this->get_mysql_dml_column_metadata_lookup( $table_name );
			if ( null === $this->get_mysql_auto_increment_column_from_metadata( $table_column_lookup ) ) {
				$conflict_target              = $this->get_mysql_upsert_omitted_column_conflict_target( $table_name, $columns );
				$uses_omitted_conflict_target = null !== $conflict_target;
			}
		}

		if ( null === $conflict_target ) {
			if ( ! empty( $options['allow_ambiguous_conflict_candidates'] ) ) {
				$candidates = $this->get_mysql_upsert_conflict_target_candidates( $table_name, $columns );
				if ( count( $candidates ) < 2 ) {
					return null;
				}

				return array(
					'conflict_target'               => null,
					'conflict_columns'              => $this->get_mysql_upsert_conflict_candidate_columns( $candidates ),
					'conflict_indexes'              => null,
					'uses_omitted_conflict_target'  => false,
					'ambiguous_conflict_candidates' => $candidates,
				);
			}

			return ! empty( $options['allow_unresolved_conflict_target'] )
				? array(
					'conflict_target'               => null,
					'conflict_columns'              => array(),
					'conflict_indexes'              => null,
					'uses_omitted_conflict_target'  => false,
					'ambiguous_conflict_candidates' => array(),
				)
				: null;
		}

		$conflict_indexes = $this->get_mysql_upsert_conflict_indexes( $columns, $conflict_target['parts'] );
		if ( null === $conflict_indexes && ! $uses_omitted_conflict_target ) {
			return null;
		}

		return array(
			'conflict_target'               => $conflict_target,
			'conflict_columns'              => $conflict_target['columns'],
			'conflict_indexes'              => $conflict_indexes,
			'uses_omitted_conflict_target'  => $uses_omitted_conflict_target,
			'ambiguous_conflict_candidates' => array(),
		);
	}
	private function get_mysql_upsert_omitted_column_conflict_target( string $table_name, array $columns ): ?array {
		$insert_column_lookup = $this->get_mysql_upsert_insert_column_lookup( $columns );

		$omitted_candidates = array();
		foreach ( $this->get_mysql_upsert_conflict_target_candidates( $table_name, $columns, true ) as $candidate ) {
			foreach ( $candidate['columns'] as $column ) {
				if ( ! isset( $insert_column_lookup[ strtolower( (string) $column ) ] ) ) {
					$omitted_candidates[] = $candidate;
					continue 2;
				}
			}
		}

		if ( 1 !== count( $omitted_candidates ) ) {
			return null;
		}
		return $this->get_mysql_upsert_conflict_target_from_candidate( $omitted_candidates[0] );
	}
	private function get_mysql_upsert_conflict_target_candidates( string $table_name, array $columns, bool $allow_omitted_columns = false, ?array $unique_index_metadata_rows = null ): array {
		$insert_column_lookup = $this->get_mysql_upsert_insert_column_lookup( $columns );

		$table_schema = $this->get_mysql_unqualified_dml_table_backend_schema( $table_name );
		return $this->get_mysql_upsert_conflict_target_candidates_from_rows(
			null === $unique_index_metadata_rows ? $this->get_mysql_unique_index_metadata_rows( $table_schema, $table_name ) : $unique_index_metadata_rows,
			$insert_column_lookup,
			$allow_omitted_columns
		);
	}
	private function get_mysql_upsert_insert_column_lookup( array $columns ): array {
		return array_fill_keys( array_map( 'strtolower', array_map( 'strval', $columns ) ), true );
	}
	private function get_mysql_unique_index_metadata_rows( string $table_schema, string $table_name ): array {
		$cache_key = $table_schema . "\0" . $table_name;
		if ( array_key_exists( $cache_key, $this->mysql_unique_index_metadata_introspection_cache ) ) {
			return $this->mysql_unique_index_metadata_introspection_cache[ $cache_key ];
		}

		$rows       = array();
		$index_rows = $this->get_show_create_table_metadata_rows( 'indexes', $table_schema, $table_name, false );

		foreach ( $index_rows as $row ) {
			if ( '0' !== (string) ( $row['non_unique'] ?? '' ) ) {
				continue;
			}

			$rows[] = array(
				'key_name'    => $row['key_name'],
				'column_name' => $row['column_name'],
				'index_type'  => $row['index_type'],
				'sub_part'    => $row['sub_part'],
			);
		}
		$this->mysql_unique_index_metadata_introspection_cache[ $cache_key ] = $rows;
		return $rows;
	}
	private function get_mysql_upsert_conflict_target_candidates_from_rows( array $rows, array $insert_column_lookup, bool $allow_omitted_columns ): array {
		$candidates = array();
		foreach ( $this->get_mysql_unique_index_groups_from_metadata_rows( $rows ) as $index ) {
			if ( empty( $index['columns'] ) ) {
				continue;
			}

			if ( $this->is_mysql_metadata_only_index_type( $index['index_type'] ) ) {
				continue;
			}

			foreach ( $index['columns'] as $column ) {
				if ( ! $allow_omitted_columns && ! isset( $insert_column_lookup[ strtolower( $column ) ] ) ) {
					continue 2;
				}
			}

			$candidates[] = array(
				'columns' => $index['columns'],
				'parts'   => $index['parts'],
			);
		}
		return $candidates;
	}
	private function get_mysql_upsert_conflict_candidate_columns( array $candidates ): array {
		$columns = array();
		foreach ( $candidates as $candidate ) {
			foreach ( $candidate['columns'] ?? array() as $column ) {
				$column_key = strtolower( (string) $column );
				if ( isset( $columns[ $column_key ] ) ) {
					continue;
				}

				$columns[ $column_key ] = (string) $column;
			}
		}
		return array_values( $columns );
	}
	private function get_mysql_upsert_conflict_target_for_value_rows(
		string $table_name,
		array $columns,
		array $candidates,
		array $value_rows,
		array $probe_safe_rows
	): ?array {
		$conflicting_candidates = array();

		foreach ( $candidates as $candidate ) {
			$conflict_indexes = $this->get_mysql_upsert_conflict_indexes( $columns, $candidate['parts'] );
			if ( null === $conflict_indexes ) {
				return null;
			}

			$candidate_conflicts = false;
			foreach ( $value_rows as $row_index => $values ) {
				if ( ! $this->mysql_upsert_conflict_indexes_are_probe_safe_for_row( $values, $probe_safe_rows[ $row_index ] ?? array(), $conflict_indexes ) ) {
					return null;
				}

				$conflict_exists = $this->mysql_upsert_conflict_exists( $table_name, $values, $conflict_indexes );
				if ( null === $conflict_exists ) {
					return null;
				}

				if ( $conflict_exists ) {
					$candidate_conflicts = true;
				}
			}

			if ( $candidate_conflicts ) {
				$conflicting_candidates[] = $candidate;
			}
		}

		if ( 1 === count( $conflicting_candidates ) ) {
			return $this->get_mysql_upsert_conflict_target_from_candidate( $conflicting_candidates[0] );
		}

		if ( 0 === count( $conflicting_candidates ) ) {
			$conflict_index_groups = array();
			foreach ( $candidates as $candidate ) {
				$conflict_indexes = $this->get_mysql_upsert_conflict_indexes( $columns, $candidate['parts'] );
				if ( null === $conflict_indexes ) {
					return null;
				}
				$conflict_index_groups[] = $conflict_indexes;
			}
			if ( $this->has_duplicate_mysql_replace_conflict_value_rows_in_groups( $value_rows, $probe_safe_rows, $conflict_index_groups ) ) {
				return null;
			}
			return $this->get_mysql_upsert_conflict_target_from_candidate( $candidates[0] );
		}
		return null;
	}
	private function get_mysql_per_row_upsert_statements_for_ambiguous_targets( string $table_name, array $columns, array $value_rows, array $probe_safe_rows, array $assignments, array $assigned_columns ): ?array {
		$candidates = $this->get_mysql_upsert_conflict_target_candidates( $table_name, $columns );
		if ( count( $candidates ) < 2 ) {
			return null;
		}

		$conflict_index_groups = array();
		foreach ( $candidates as $candidate ) {
			foreach ( $candidate['parts'] as $part ) {
				if ( isset( $assigned_columns[ strtolower( (string) ( $part['column'] ?? '' ) ) ] ) ) {
					return null;
				}
			}

			$conflict_indexes = $this->get_mysql_upsert_conflict_indexes( $columns, $candidate['parts'] );
			if ( null === $conflict_indexes ) {
				return null;
			}

			$conflict_index_groups[] = $conflict_indexes;
		}

		$column_sql           = $this->get_postgresql_dml_column_list_sql( $columns );
		$inserted_value_rows  = array();
		$statements           = array();
		$seen_inserted_values = array();
		$used_columns         = array();

		foreach ( $value_rows as $row_index => $values ) {
			$probe_safety     = $probe_safe_rows[ $row_index ] ?? array();
			$matching_indexes = array();

			foreach ( $conflict_index_groups as $candidate_index => $conflict_indexes ) {
				if ( ! $this->mysql_upsert_conflict_indexes_are_probe_safe_for_row( $values, $probe_safety, $conflict_indexes ) ) {
					return null;
				}

				$conflict_exists = $this->mysql_upsert_conflict_exists( $table_name, $values, $conflict_indexes );
				if ( null === $conflict_exists ) {
					return null;
				}

				$seen_key      = $this->get_mysql_replace_conflict_seen_key_for_row( $values, $conflict_indexes );
				$seen_conflict = null !== $seen_key && isset( $seen_inserted_values[ $candidate_index ][ $seen_key ] );
				if ( $conflict_exists || $seen_conflict ) {
					$matching_indexes[] = $candidate_index;
				}
			}

			if ( count( $matching_indexes ) > 1 ) {
				return null;
			}

			$target_index    = 1 === count( $matching_indexes ) ? $matching_indexes[0] : 0;
			$conflict_target = $this->get_mysql_upsert_conflict_target_from_candidate( $candidates[ $target_index ] );
			foreach ( $conflict_target['columns'] as $column ) {
				$used_columns[ strtolower( $column ) ] = $column;
			}

			$statements[] = sprintf(
				'INSERT INTO %s (%s) VALUES (%s) ON CONFLICT (%s) DO UPDATE SET %s',
				$this->get_postgresql_unqualified_dml_table_reference_sql( $table_name ),
				$column_sql,
				implode( ', ', $values ),
				implode( ', ', $conflict_target['sql'] ),
				implode( ', ', $assignments )
			);

			if ( 0 === count( $matching_indexes ) ) {
				$inserted_value_rows[] = $values;
				foreach ( $conflict_index_groups as $candidate_index => $conflict_indexes ) {
					$seen_key = $this->get_mysql_replace_conflict_seen_key_for_row( $values, $conflict_indexes );
					if ( null !== $seen_key ) {
						$seen_inserted_values[ $candidate_index ][ $seen_key ] = true;
					}
				}
			}
		}
		return array(
			'statements'            => $statements,
			'inserted_value_rows'   => $inserted_value_rows,
			'conflict_columns'      => array_values( $used_columns ),
			'conflict_index_groups' => $conflict_index_groups,
		);
	}
	private function get_mysql_upsert_conflict_target_from_candidate( array $candidate ): array {
		$conflict_target = array(
			'columns' => array_values( $candidate['columns'] ),
			'parts'   => array_values( $candidate['parts'] ),
			'sql'     => array(),
		);
		foreach ( $conflict_target['parts'] as $part ) {
			$conflict_target['sql'][] = $this->get_mysql_index_key_part_sql( $part['column'], $part['sub_part'] );
		}
		return $conflict_target;
	}
	private function get_mysql_upsert_inserted_value_rows( string $table_name, array $columns, array $value_rows, array $probe_safe_rows, array $conflict_parts ): ?array {
		$conflict_indexes = $this->get_mysql_upsert_conflict_indexes( $columns, $conflict_parts );
		if ( null === $conflict_indexes ) {
			return null;
		}

		$inserted_rows = array();
		foreach ( $value_rows as $row_index => $values ) {
			if ( ! $this->mysql_upsert_conflict_indexes_are_probe_safe_for_row( $values, $probe_safe_rows[ $row_index ] ?? array(), $conflict_indexes ) ) {
				return null;
			}

			$conflict_exists = $this->mysql_upsert_conflict_exists( $table_name, $values, $conflict_indexes );
			if ( null === $conflict_exists ) {
				return null;
			}

			if ( $conflict_exists ) {
				continue;
			}

			$inserted_rows[] = $values;
		}
		return $inserted_rows;
	}
	private function get_mysql_upsert_conflict_indexes( array $columns, array $conflict_parts ): ?array {
		$column_indexes = array();
		foreach ( $columns as $index => $column ) {
			$column_indexes[ strtolower( $column ) ] = $index;
		}

		$conflict_indexes = array();
		foreach ( $conflict_parts as $part ) {
			$column     = (string) ( $part['column'] ?? '' );
			$column_key = strtolower( $column );
			if ( ! isset( $column_indexes[ $column_key ] ) ) {
				return null;
			}

			$conflict_indexes[] = array(
				'column'   => $column,
				'index'    => $column_indexes[ $column_key ],
				'sub_part' => $part['sub_part'] ?? null,
			);
		}
		return $conflict_indexes;
	}
	private function mysql_upsert_conflict_indexes_are_probe_safe_for_row( array $values, array $probe_safety, array $conflict_indexes ): bool {
		foreach ( $conflict_indexes as $conflict_index ) {
			if (
				! array_key_exists( $conflict_index['index'], $values )
				|| ! isset( $probe_safety[ $conflict_index['index'] ] )
				|| ! $probe_safety[ $conflict_index['index'] ]
			) {
				return false;
			}
		}
		return true;
	}
	private function get_mysql_conflict_index_value_comparison_sql( array $conflict_index, string $value_sql ): string {
		if ( null !== ( $conflict_index['sub_part'] ?? null ) && '' !== (string) $conflict_index['sub_part'] ) {
			return sprintf(
				'%s = SUBSTR(CAST(%s AS text), 1, %d)',
				$this->get_mysql_index_key_part_sql( (string) $conflict_index['column'], $conflict_index['sub_part'] ),
				$value_sql,
				(int) $conflict_index['sub_part']
			);
		}
		return sprintf(
			'%s = %s',
			$this->connection->quote_identifier( (string) $conflict_index['column'] ),
			$value_sql
		);
	}
	private function mysql_upsert_conflict_exists( string $table_name, array $values, array $conflict_indexes ): ?bool {
		$where = array();
		foreach ( $conflict_indexes as $conflict_index ) {
			if ( ! array_key_exists( $conflict_index['index'], $values ) ) {
				return null;
			}

			$value = (string) $values[ $conflict_index['index'] ];
			if ( $this->is_mysql_generated_auto_increment_value_sql( $value ) ) {
				return false;
			}

			$where[] = $this->get_mysql_conflict_index_value_comparison_sql( $conflict_index, $value );
		}

		$stmt = $this->connection->query(
			sprintf(
				'SELECT 1 FROM %s WHERE %s LIMIT 1',
				$this->get_postgresql_unqualified_dml_table_reference_sql( $table_name ),
				implode( ' AND ', $where )
			)
		);
		return false !== $stmt->fetchColumn();
	}
	private function get_mysql_upsert_last_insert_id_query_fields( string $table_name, array $assignment_effects, ?array $literal_value_row, ?array $value_rows, ?array $probe_safe_rows, ?array $conflict_indexes, bool $has_inserted_rows ) {
		if ( ! isset( $assignment_effects['last_insert_id_column'] ) ) {
			return array();
		}

		if ( null === $conflict_indexes ) {
			return false;
		}

		$column_name = (string) $assignment_effects['last_insert_id_column'];
		if ( null !== $literal_value_row ) {
			$row = $this->get_mysql_upsert_conflicting_row_column_value(
				$table_name,
				$column_name,
				$literal_value_row['values'],
				$literal_value_row['probe_safe_values'],
				$conflict_indexes
			);
			if ( null === $row ) {
				return false;
			}
			return $row['found']
				? array( 'last_insert_id_on_duplicate_key_update' => $row['value'] )
				: array();
		}

		if ( null === $value_rows || null === $probe_safe_rows ) {
			return array( 'last_insert_id_column_on_duplicate_key_update' => $column_name );
		}

		$row = $this->get_mysql_upsert_last_insert_id_row_for_value_rows(
			$table_name,
			$column_name,
			$value_rows,
			$probe_safe_rows,
			$conflict_indexes,
			$has_inserted_rows
		);
		if ( null === $row ) {
			return false;
		}
		return $row['found']
			? array( 'last_insert_id_on_duplicate_key_update' => $row['value'] )
			: array();
	}
	private function get_mysql_upsert_last_insert_id_row_for_value_rows( string $table_name, string $column_name, array $value_rows, array $probe_safe_rows, array $conflict_indexes, bool $has_inserted_rows ): ?array {
		$found = false;
		$value = null;
		foreach ( $value_rows as $row_index => $values ) {
			$row = $this->get_mysql_upsert_conflicting_row_column_value(
				$table_name,
				$column_name,
				$values,
				$probe_safe_rows[ $row_index ] ?? array(),
				$conflict_indexes
			);
			if ( null === $row ) {
				return null;
			}

			if ( ! $row['found'] ) {
				continue;
			}

			$found = true;
			$value = $row['value'];
		}

		if ( $found && $has_inserted_rows ) {
			return null;
		}
		return array(
			'found' => $found,
			'value' => $value,
		);
	}
	private function get_mysql_upsert_conflicting_row_column_value( string $table_name, string $column_name, array $values, array $probe_safety, array $conflict_indexes ): ?array {
		if ( ! $this->mysql_upsert_conflict_indexes_are_probe_safe_for_row( $values, $probe_safety, $conflict_indexes ) ) {
			return null;
		}

		$where = array();
		foreach ( $conflict_indexes as $conflict_index ) {
			$value = (string) $values[ $conflict_index['index'] ];
			if ( $this->is_mysql_generated_auto_increment_value_sql( $value ) ) {
				return array(
					'found' => false,
					'value' => null,
				);
			}

			$where[] = $this->get_mysql_conflict_index_value_comparison_sql( $conflict_index, $value );
		}

		if ( empty( $where ) ) {
			return null;
		}

		$stmt  = $this->connection->query(
			sprintf(
				'SELECT %s FROM %s WHERE %s LIMIT 1',
				$this->connection->quote_identifier( $column_name ),
				$this->get_postgresql_unqualified_dml_table_reference_sql( $table_name ),
				implode( ' AND ', $where )
			)
		);
		$value = $stmt->fetchColumn();
		return array(
			'found' => false !== $value,
			'value' => false === $value ? null : $value,
		);
	}
	private function translate_simple_mysql_replace_query( string $query ): ?array {
		$tokens   = $this->get_mysql_tokens( $query );
		$position = 1;
		$header   = $this->parse_mysql_replace_table_header( $tokens, $position );
		if ( null === $header ) {
			return null;
		}
		$table_name            = $header['table'];
		$table_reference_start = $header['start'];
		$table_reference_end   = $header['end'];

		$statement_end = $this->get_mysql_statement_end_position( $tokens, $position );
		if ( null === $statement_end ) {
			return null;
		}

		$column_metadata = null;
		$source          = $this->parse_mysql_insert_like_dml_source( $table_name, $tokens, $position, $statement_end, $column_metadata );
		if ( null === $source ) {
			return null;
		}
		$columns            = $source['columns'];
		$value_rows         = $source['value_rows'];
		$value_range_rows   = $source['value_range_rows'];
		$probe_safe_rows    = $source['probe_safe_rows'];
		$insert_column_list = $source['insert_column_list'];

		if ( $this->is_mysql_dml_select_source_token( $tokens[ $position ] ?? null ) ) {
			return $this->translate_simple_mysql_replace_select_query(
				$query,
				$table_name,
				$columns,
				$tokens,
				$position,
				$table_reference_start,
				$table_reference_end,
				$insert_column_list
			);
		}

		$column_metadata = $this->normalize_mysql_dml_value_rows_for_columns(
			$table_name,
			$columns,
			$value_rows,
			$value_range_rows,
			$tokens,
			$column_metadata
		);
		$this->append_non_strict_dml_defaults_for_omitted_value_rows( $table_name, $columns, $value_rows, $column_metadata );

		$sql = $this->get_postgresql_dml_insert_values_sql(
			$this->get_postgresql_unqualified_dml_table_reference_sql( $table_name ),
			$columns,
			$value_rows
		);

		$table_schema                     = $this->get_mysql_unqualified_dml_table_backend_schema( $table_name );
		$unique_index_metadata_rows       = $this->get_mysql_unique_index_metadata_rows( $table_schema, $table_name );
		$unique_index_groups              = $this->get_mysql_unique_index_groups_from_metadata_rows( $unique_index_metadata_rows );
		$conflict_target                  = $this->get_mysql_replace_conflict_target(
			$table_name,
			$columns,
			$value_rows,
			$probe_safe_rows,
			$unique_index_metadata_rows,
			$unique_index_groups
		);
		$delete_conflict_index_groups     = $this->get_mysql_replace_delete_conflict_index_groups(
			$table_name,
			$columns,
			$value_rows,
			$probe_safe_rows,
			$unique_index_groups
		);
		$all_delete_conflict_index_groups = $this->get_mysql_replace_delete_conflict_index_groups( $table_name, $columns, array(), array(), $unique_index_groups );
		if (
			null !== $conflict_target
			&& count( $all_delete_conflict_index_groups ) > count( $delete_conflict_index_groups )
		) {
			$materialized_values_flow = $this->get_mysql_replace_values_delete_then_insert_flow(
				$table_name,
				$columns,
				$value_rows,
				$conflict_target,
				$all_delete_conflict_index_groups
			);
			if ( null !== $materialized_values_flow ) {
				return array_merge(
					$this->get_mysql_replace_query(
						$table_name,
						$columns,
						$value_rows,
						null,
						$conflict_target,
						array(
							'conflict_probe_safe_rows' => $probe_safe_rows,
						)
					),
					$materialized_values_flow
				);
			}
		}
		if ( null === $conflict_target ) {
			if ( ! empty( $delete_conflict_index_groups ) ) {
				$delete_insert_statements = $this->get_mysql_replace_delete_then_insert_statements(
					$table_name,
					$columns,
					$value_rows,
					$probe_safe_rows,
					$delete_conflict_index_groups,
					$this->has_duplicate_mysql_replace_conflict_value_rows_in_groups( $value_rows, $probe_safe_rows, $delete_conflict_index_groups )
				);
				if ( null !== $delete_insert_statements ) {
					return $this->get_mysql_replace_query(
						$table_name,
						$columns,
						$value_rows,
						$sql,
						null,
						array(
							'statements'               => $delete_insert_statements,
							'conflict_probe_safe_rows' => $probe_safe_rows,
							'delete_then_insert'       => true,
						)
					);
				}
			}
			return $this->get_mysql_replace_query(
				$table_name,
				$columns,
				$value_rows,
				$sql,
				null,
				array(
					'conflict_value' => null,
				)
			);
		}

		$conflict_indexes = $this->get_mysql_upsert_conflict_indexes( $columns, $conflict_target['parts'] );
		if ( null === $conflict_indexes ) {
			return null;
		}
		if ( empty( $delete_conflict_index_groups ) ) {
			$delete_conflict_index_groups = array( $conflict_indexes );
		}

		$conflict_sql  = $this->get_postgresql_dml_conflict_update_sql( $conflict_target, $this->get_postgresql_dml_excluded_assignments( $columns ) );
		$replace_query = $this->get_mysql_replace_query(
			$table_name,
			$columns,
			$value_rows,
			$sql . ' ' . $conflict_sql,
			$conflict_target,
			array(
				'conflict_indexes'         => $conflict_indexes,
				'conflict_probe_safe_rows' => $probe_safe_rows,
			)
		);

		$has_duplicate_conflict_rows = $this->has_duplicate_mysql_replace_conflict_value_rows_in_groups( $value_rows, $probe_safe_rows, $delete_conflict_index_groups );
		$delete_insert_statements    = $this->get_mysql_replace_delete_then_insert_statements(
			$table_name,
			$columns,
			$value_rows,
			$probe_safe_rows,
			$delete_conflict_index_groups,
			$has_duplicate_conflict_rows
		);
		if ( null !== $delete_insert_statements ) {
			$replace_query['statements']         = $delete_insert_statements;
			$replace_query['delete_then_insert'] = true;
			$replace_query['inserted_new_row']   = true;
		} elseif ( $has_duplicate_conflict_rows ) {
			$replace_query['statements'] = $this->get_postgresql_dml_insert_value_row_statements( $table_name, $columns, $value_rows, $conflict_sql );
		}
		return $replace_query;
	}
	private function get_mysql_replace_query( string $table_name, array $columns, ?array $value_rows, ?string $sql, ?array $conflict_target, array $extra = array() ): array {
		$query = array(
			'action'           => 'replace',
			'table_name'       => $table_name,
			'columns'          => $columns,
			'conflict_column'  => null === $conflict_target ? null : ( $conflict_target['columns'][0] ?? null ),
			'inserted_new_row' => true,
		);
		foreach ( compact( 'value_rows', 'sql', 'conflict_target' ) as $field => $value ) {
			if ( null !== $value ) {
				$query[ $field ] = $value;
			}
		}
		return array_merge( $query, $extra );
	}
	private function get_mysql_replace_delete_then_insert_statements( string $table_name, array $columns, array $value_rows, array $probe_safe_rows, array $conflict_index_groups, bool $sequential_statements ): ?array {
		$quoted_table = $this->get_postgresql_unqualified_dml_table_reference_sql( $table_name );

		$row_predicates = array();
		foreach ( $value_rows as $row_index => $values ) {
			$predicate = $this->get_mysql_replace_delete_predicate_for_row(
				$values,
				$probe_safe_rows[ $row_index ] ?? array(),
				$conflict_index_groups
			);
			if ( false === $predicate ) {
				return null;
			}

			$row_predicates[ $row_index ] = $predicate;
		}

		$statements = array();
		if ( $sequential_statements ) {
			foreach ( $value_rows as $row_index => $values ) {
				if ( null !== $row_predicates[ $row_index ] ) {
					$statements[] = sprintf(
						'DELETE FROM %s WHERE %s',
						$quoted_table,
						$row_predicates[ $row_index ]
					);
				}

				$statements[] = $this->get_postgresql_dml_insert_values_sql( $quoted_table, $columns, array( $values ) );
			}
			return $statements;
		}

		$delete_predicates = array_values(
			array_filter(
				$row_predicates,
				static function ( $predicate ): bool {
					return null !== $predicate;
				}
			)
		);
		if ( ! empty( $delete_predicates ) ) {
			$statements[] = sprintf(
				'DELETE FROM %s WHERE %s',
				$quoted_table,
				$this->get_mysql_parenthesized_or_predicate_sql( $delete_predicates )
			);
		}

		$statements[] = $this->get_postgresql_dml_insert_values_sql( $quoted_table, $columns, $value_rows );
		return $statements;
	}

	/** Render a PostgreSQL INSERT ... VALUES statement. */
	private function get_postgresql_dml_insert_values_sql( string $table_sql, array $columns, array $value_rows, string $suffix = '' ): string {
		$sql = sprintf(
			'INSERT INTO %s (%s) VALUES %s',
			$table_sql,
			$this->get_postgresql_dml_column_list_sql( $columns ),
			$this->get_postgresql_dml_values_rows_sql( $value_rows )
		);
		return '' === $suffix ? $sql : $sql . ' ' . $suffix;
	}
	private function get_postgresql_dml_insert_value_row_statements( string $table_name, array $columns, array $value_rows, string $suffix = '' ): array {
		$statements = array();
		$table_sql  = $this->get_postgresql_unqualified_dml_table_reference_sql( $table_name );
		foreach ( $value_rows as $values ) {
			$statements[] = $this->get_postgresql_dml_insert_values_sql( $table_sql, $columns, array( $values ), $suffix );
		}
		return $statements;
	}
	private function get_postgresql_dml_insert_from_source_sql( string $table_name, array $columns, string $source_table_sql, string $rows_alias, string $suffix = '' ): string {
		$sql = sprintf(
			'INSERT INTO %s (%s) SELECT %s FROM %s AS %s',
			$this->get_postgresql_unqualified_dml_table_reference_sql( $table_name ),
			$this->get_postgresql_dml_column_list_sql( $columns ),
			$this->get_postgresql_dml_qualified_column_list_sql( $rows_alias, $columns ),
			$source_table_sql,
			$rows_alias
		);
		return '' === $suffix ? $sql : $sql . ' ' . $suffix;
	}

	/** Render a PostgreSQL DML column list. */
	private function get_postgresql_dml_column_list_sql( array $columns ): string {
		return implode( ', ', array_map( array( $this->connection, 'quote_identifier' ), $columns ) );
	}
	private function get_postgresql_dml_qualified_column_list_sql( string $alias, array $columns ): string {
		return array() === $columns ? '' : $alias . '.' . implode( ', ' . $alias . '.', array_map( array( $this->connection, 'quote_identifier' ), $columns ) );
	}
	private function get_postgresql_dml_excluded_assignments( array $columns ): array {
		$columns = array_map( array( $this->connection, 'quote_identifier' ), $columns );
		return array_map( 'sprintf', array_fill( 0, count( $columns ), '%s = excluded.%s' ), $columns, $columns );
	}
	private function get_postgresql_dml_conflict_update_sql( array $conflict_target, array $assignments ): string {
		return sprintf(
			'ON CONFLICT (%s) DO UPDATE SET %s',
			implode( ', ', $conflict_target['sql'] ),
			implode( ', ', $assignments )
		);
	}
	private function get_mysql_replace_delete_predicate_for_row( array $values, array $probe_safety, array $conflict_index_groups ) {
		$predicates = array();
		foreach ( $conflict_index_groups as $conflict_indexes ) {
			$predicate = $this->get_mysql_replace_delete_predicate_for_row_conflict_indexes(
				$values,
				$probe_safety,
				$conflict_indexes
			);
			if ( false === $predicate ) {
				return false;
			}

			if ( null !== $predicate ) {
				$predicates[] = $predicate;
			}
		}

		if ( empty( $predicates ) ) {
			return null;
		}

		if ( 1 === count( $predicates ) ) {
			return $predicates[0];
		}
		return $this->get_mysql_parenthesized_or_predicate_sql( $predicates );
	}
	private function get_mysql_parenthesized_or_predicate_sql( array $predicates ): string {
		return implode(
			' OR ',
			array_map(
				static function ( string $predicate ): string {
					return '(' . $predicate . ')';
				},
				$predicates
			)
		);
	}
	private function get_mysql_replace_delete_predicate_for_row_conflict_indexes( array $values, array $probe_safety, array $conflict_indexes ) {
		if ( ! $this->mysql_upsert_conflict_indexes_are_probe_safe_for_row( $values, $probe_safety, $conflict_indexes ) ) {
			return false;
		}

		$where = array();
		foreach ( $conflict_indexes as $conflict_index ) {
			$value = trim( (string) $values[ $conflict_index['index'] ] );
			if ( $this->is_mysql_replace_conflict_ignored_value_sql( $value ) ) {
				return null;
			}

			$where[] = $this->get_mysql_conflict_index_value_comparison_sql( $conflict_index, $value );
		}
		return empty( $where ) ? null : implode( ' AND ', $where );
	}
	private function get_mysql_replace_delete_conflict_index_groups( string $table_name, array $columns, array $value_rows = array(), array $probe_safe_rows = array(), ?array $unique_index_groups = null ): array {
		if ( null === $unique_index_groups ) {
			$table_schema        = $this->get_mysql_unqualified_dml_table_backend_schema( $table_name );
			$unique_index_groups = $this->get_mysql_unique_index_groups_from_metadata_rows( $this->get_mysql_unique_index_metadata_rows( $table_schema, $table_name ) );
		}

		$conflict_index_groups = array();
		foreach ( $unique_index_groups as $index ) {
			if ( empty( $index['parts'] ) || $this->is_mysql_metadata_only_index_type( $index['index_type'] ) ) {
				continue;
			}

			$conflict_indexes = $this->get_mysql_upsert_conflict_indexes( $columns, $index['parts'] );
			if ( null === $conflict_indexes ) {
				continue;
			}

			if ( ! $this->mysql_replace_conflict_indexes_are_probe_safe_for_rows( $conflict_indexes, $value_rows, $probe_safe_rows ) ) {
				continue;
			}

			$conflict_index_groups[] = $conflict_indexes;
		}
		return $conflict_index_groups;
	}
	private function mysql_replace_conflict_indexes_are_probe_safe_for_rows( array $conflict_indexes, array $value_rows, array $probe_safe_rows ): bool {
		foreach ( $value_rows as $row_index => $values ) {
			if ( ! $this->mysql_upsert_conflict_indexes_are_probe_safe_for_row( $values, $probe_safe_rows[ $row_index ] ?? array(), $conflict_indexes ) ) {
				return false;
			}
		}
		return true;
	}
	private function translate_simple_mysql_replace_select_query(
		string $query,
		string $table_name,
		array $columns,
		array $tokens,
		int $position,
		int $table_reference_start,
		int $table_reference_end,
		bool $insert_column_list = false
	): ?array {
		$statement_end = $this->get_mysql_statement_end_position( $tokens, $position );
		if ( null === $statement_end || ! $this->is_at_mysql_query_end( $tokens, $statement_end ) ) {
			return null;
		}

		$select_columns  = $columns;
		$default_columns = $this->get_non_strict_dml_defaults_for_omitted_columns( $table_name, $columns );
		$rewrite         = $this->get_mysql_insert_select_rewrite_data(
			$query,
			$table_name,
			$columns,
			$tokens,
			$position,
			$statement_end,
			$table_reference_start,
			$table_reference_end,
			$insert_column_list,
			$default_columns,
			'__wp_pg_replace_source'
		);
		if ( null === $rewrite ) {
			return null;
		}
		$columns      = $rewrite['columns'];
		$select_start = $rewrite['select_start'];
		$select_end   = $rewrite['select_end'];
		$sql          = $rewrite['sql'];

		$replace_select_value_rows      = null;
		$replace_select_probe_safe_rows = null;
		$replace_select_literal_row     = $this->get_mysql_insert_select_upsert_literal_value_row(
			$table_name,
			$select_columns,
			$tokens,
			$select_start,
			$select_end
		);
		if ( null !== $replace_select_literal_row && empty( $default_columns ) ) {
			$replace_select_value_rows      = array( $replace_select_literal_row['values'] );
			$replace_select_probe_safe_rows = array( $replace_select_literal_row['probe_safe_values'] );
		}

		$table_schema               = $this->get_mysql_unqualified_dml_table_backend_schema( $table_name );
		$unique_index_metadata_rows = $this->get_mysql_unique_index_metadata_rows( $table_schema, $table_name );
		$unique_index_groups        = $this->get_mysql_unique_index_groups_from_metadata_rows( $unique_index_metadata_rows );
		$conflict_target            = $this->get_mysql_replace_conflict_target(
			$table_name,
			$columns,
			$replace_select_value_rows,
			$replace_select_probe_safe_rows,
			$unique_index_metadata_rows,
			$unique_index_groups
		);
		$replace_select_flow        = $this->get_mysql_replace_select_delete_then_insert_flow(
			$table_name,
			$columns,
			$select_columns,
			$default_columns,
			$conflict_target,
			$tokens,
			$select_start,
			$select_end,
			$unique_index_groups
		);
		$replace_query              = null;
		if ( null !== $replace_select_flow ) {
			$conflict_target = $conflict_target ?? array(
				'columns' => array(),
				'parts'   => array(),
				'sql'     => array(),
			);

			$sql           = $replace_select_flow['sql'];
			$replace_query = $replace_select_flow;
		}

		return $this->get_mysql_replace_query(
			$table_name,
			$columns,
			null,
			$sql,
			$conflict_target,
			array_merge(
				array(
					'conflict_value'                   => null,
					'replace_select_affected_rows_sql' => null,
				),
				$replace_query ?? array()
			)
		);
	}
	private function get_mysql_replace_values_delete_then_insert_flow( string $table_name, array $columns, array $value_rows, array $conflict_target, array $conflict_index_groups ): ?array {
		if ( empty( $conflict_index_groups ) || empty( $value_rows ) ) {
			return null;
		}

		$conflict_indexes = $this->get_mysql_upsert_conflict_indexes( $columns, $conflict_target['parts'] ?? array() );
		if ( null === $conflict_indexes ) {
			return null;
		}

		$select_rows = array();
		foreach ( $value_rows as $row_index => $values ) {
			if ( count( $values ) !== count( $columns ) ) {
				return null;
			}

			$projections = array();
			foreach ( $values as $column_index => $value_sql ) {
				$projection = (string) $value_sql;
				if ( 0 === $row_index ) {
					$projection .= ' AS ' . $this->connection->quote_identifier( $columns[ $column_index ] );
				}
				$projections[] = $projection;
			}

			$select_rows[] = 'SELECT ' . implode( ', ', $projections );
		}

		return $this->get_mysql_replace_materialized_source_delete_insert_flow(
			$table_name,
			$columns,
			implode( ' UNION ALL ', $select_rows ),
			'__wp_pg_replace_values_',
			'__wp_pg_replace_values_ord_',
			$table_name . "\0" . implode( "\0", $columns ) . "\0" . implode( "\0", array_map( 'implode', $value_rows ) ),
			$conflict_indexes,
			$conflict_index_groups
		);
	}
	private function get_mysql_replace_select_delete_then_insert_flow( string $table_name, array $columns, array $select_columns, array $default_columns, ?array $conflict_target, array $tokens, int $select_start, int $select_end, ?array $unique_index_groups = null ): ?array {
		$conflict_index_groups = $this->get_mysql_replace_delete_conflict_index_groups( $table_name, $columns, array(), array(), $unique_index_groups );
		$conflict_indexes      = null;
		if ( null !== $conflict_target ) {
			$conflict_indexes = $this->get_mysql_upsert_conflict_indexes( $columns, $conflict_target['parts'] ?? array() );
			if ( null === $conflict_indexes ) {
				return null;
			}
		}

		if ( empty( $conflict_index_groups ) && null !== $conflict_indexes ) {
			$conflict_index_groups = array( $conflict_indexes );
		}
		if ( empty( $conflict_index_groups ) ) {
			return null;
		}
		$conflict_indexes = $conflict_indexes ?? $conflict_index_groups[0];

		$select_sql = $this->get_mysql_replace_select_source_sql(
			$table_name,
			$select_columns,
			$default_columns,
			$tokens,
			$select_start,
			$select_end
		);
		if ( null === $select_sql ) {
			return null;
		}

		return $this->get_mysql_replace_materialized_source_delete_insert_flow(
			$table_name,
			$columns,
			$select_sql,
			'__wp_pg_replace_select_',
			'__wp_pg_replace_select_ord_',
			$table_name . "\0" . $select_start . "\0" . $select_end . "\0" . implode( "\0", $columns ),
			$conflict_indexes,
			$conflict_index_groups
		);
	}
	private function get_mysql_replace_materialized_source_delete_insert_flow( string $table_name, array $columns, string $select_sql, string $table_prefix, string $ordinal_prefix, string $hash_input, array $conflict_indexes, array $conflict_index_groups ): ?array {
		if ( empty( $conflict_indexes ) || empty( $conflict_index_groups ) ) {
			return null;
		}
		return $this->get_mysql_replace_materialized_delete_insert_flow(
			$table_name,
			$columns,
			$select_sql,
			$this->get_mysql_materialized_dml_table_context( $table_prefix, $ordinal_prefix, $hash_input ),
			$conflict_indexes,
			$conflict_index_groups
		);
	}
	private function get_mysql_replace_materialized_delete_insert_flow( string $table_name, array $columns, string $select_sql, array $table_context, array $conflict_indexes, array $conflict_index_groups ): ?array {
		$rows_alias           = $this->connection->quote_identifier( '__wp_pg_replace_rows' );
		$target_alias         = $this->connection->quote_identifier( '__wp_pg_replace_target' );
		$quoted_target_table  = $this->get_postgresql_unqualified_dml_table_reference_sql( $table_name );
		$delete_predicate_sql = $this->get_mysql_replace_select_delete_predicate_sql(
			$target_alias,
			$rows_alias,
			$conflict_index_groups
		);
		if ( null === $delete_predicate_sql ) {
			return null;
		}

		$affected_rows_count_sql     = sprintf(
			'SELECT ((SELECT COUNT(*) FROM %1$s) + (SELECT COUNT(*) FROM %2$s AS %3$s WHERE EXISTS (SELECT 1 FROM %1$s AS %4$s WHERE %5$s))) AS affected_rows, (SELECT COUNT(*) FROM %1$s) AS inserted_rows',
			$table_context['source_table_sql'],
			$quoted_target_table,
			$target_alias,
			$rows_alias,
			$delete_predicate_sql
		);
		$duplicate_conflict_rows_sql = $this->get_mysql_replace_select_duplicate_conflict_rows_sql(
			$table_context['source_table_sql'],
			$rows_alias,
			$conflict_index_groups
		);
		if ( null === $duplicate_conflict_rows_sql ) {
			return null;
		}

		$delete_sql = sprintf(
			'DELETE FROM %s AS %s WHERE EXISTS (SELECT 1 FROM %s AS %s WHERE %s)',
			$quoted_target_table,
			$target_alias,
			$table_context['source_table_sql'],
			$rows_alias,
			$delete_predicate_sql
		);
		$insert_sql = $this->get_postgresql_dml_insert_from_source_sql(
			$table_name,
			$columns,
			$table_context['source_table_sql'],
			$rows_alias
		);
		return $this->get_mysql_materialized_dml_flow(
			$select_sql,
			$table_context,
			array( $delete_sql, $insert_sql ),
			array(
				'sql'                              => $insert_sql,
				'replace_select_materialized'      => true,
				'replace_select_affected_rows_sql' => $affected_rows_count_sql,
				'duplicate_conflict_rows_sql'      => $duplicate_conflict_rows_sql,
				'conflict_indexes'                 => $conflict_indexes,
				'conflict_index_groups'            => $conflict_index_groups,
			),
			true
		);
	}
	private function get_mysql_replace_select_source_sql( string $table_name, array $select_columns, array $default_columns, array $tokens, int $select_start, int $select_end ): ?string {
		if ( $this->mysql_select_range_requires_direct_information_schema_rewrite( $tokens, $select_start, $select_end ) ) {
			return null;
		}

		$from_position     = $this->find_top_level_mysql_token( $tokens, WP_MySQL_Lexer::FROM_SYMBOL, $select_start + 1, $select_end );
		$projection_end    = $from_position ?? $select_end;
		$projection_ranges = $this->split_top_level_mysql_arguments( $tokens, $select_start + 1, $projection_end );
		if ( null === $projection_ranges || count( $projection_ranges ) !== count( $select_columns ) ) {
			return null;
		}

		$target_metadata = $this->get_mysql_dml_column_metadata_lookup( $table_name );
		$scope           = null;
		$replacements    = array();
		if ( null !== $from_position ) {
			$first_clause_position = $this->find_first_top_level_mysql_token(
				$tokens,
				self::MYSQL_SELECT_FROM_BOUNDARY_TOKENS,
				$from_position + 1,
				$select_end
			) ?? $select_end;
			$scope                 = $this->get_mysql_select_scope( $tokens, $from_position + 1, $first_clause_position );
			if (
				null !== $scope
				&& empty( $scope['unknown'] )
				&& $this->mysql_scope_references_non_public_schema( $scope )
			) {
				$source_sql = $this->translate_mysql_table_reference_range_to_postgresql( $tokens, $from_position + 1, $first_clause_position );
				if ( null === $source_sql ) {
					return null;
				}

				$replacements[] = array(
					'start' => $from_position + 1,
					'end'   => $first_clause_position,
					'sql'   => $source_sql,
				);
			}
		}

		foreach ( $projection_ranges as $index => $range ) {
			$expression_bounds = $this->get_mysql_select_projection_expression_bounds( $tokens, $range['start'], $range['end'] );
			if ( null === $expression_bounds ) {
				return null;
			}

			$expression_start = $expression_bounds['start'];
			$expression_end   = $expression_bounds['end'];
			$projection_sql   = $this->translate_mysql_token_sequence_to_postgresql( $tokens, $expression_start, $expression_end );
			$column_metadata  = $target_metadata[ strtolower( $select_columns[ $index ] ) ] ?? null;
			if ( null !== $column_metadata ) {
				$coerced_sql = $this->get_mysql_insert_select_projection_sql_for_target_column(
					$table_name,
					$column_metadata,
					$tokens,
					$expression_start,
					$expression_end,
					$projection_sql,
					$scope
				);
				if ( null !== $coerced_sql ) {
					$projection_sql = $coerced_sql;
				}
			}

			$replacements[] = array(
				'start' => $range['start'],
				'end'   => $range['end'],
				'sql'   => $projection_sql . ' AS ' . $this->connection->quote_identifier( $select_columns[ $index ] ),
			);
		}

		$this->sort_mysql_replacements( $replacements );
		$select_sql = $this->translate_mysql_token_sequence_with_replacements_to_postgresql(
			$tokens,
			$select_start,
			$select_end,
			$replacements
		);
		if ( ! empty( $default_columns ) ) {
			$select_sql = $this->append_mysql_select_default_projection_sql(
				$select_sql,
				$default_columns,
				'__wp_pg_replace_rows_source'
			);
		}
		return $select_sql;
	}
	private function append_mysql_select_default_projection_sql( string $select_sql, array $default_columns, string $source_alias ): string {
		$quoted_source_alias = $this->connection->quote_identifier( $source_alias );
		$projection_sql      = array(
			$quoted_source_alias . '.*',
		);

		foreach ( $default_columns as $default_column ) {
			$projection_sql[] = sprintf(
				'%s AS %s',
				$default_column['sql'],
				$this->connection->quote_identifier( $default_column['column'] )
			);
		}
		return sprintf(
			'SELECT %s FROM (%s) AS %s WHERE 1 = 1',
			implode( ', ', $projection_sql ),
			$select_sql,
			$quoted_source_alias
		);
	}
	private function get_mysql_replace_select_delete_predicate_sql( string $target_alias, string $rows_alias, array $conflict_index_groups ): ?string {
		$group_predicates = array();
		foreach ( $conflict_index_groups as $conflict_indexes ) {
			$predicate = $this->get_materialized_mysql_dml_conflict_group_predicate_sql( $target_alias, $rows_alias, $conflict_indexes );
			if ( null === $predicate ) {
				return null;
			}

			$group_predicates[] = '(' . $predicate . ')';
		}
		return empty( $group_predicates ) ? null : implode( ' OR ', $group_predicates );
	}
	private function get_mysql_replace_select_duplicate_conflict_rows_sql( string $source_table_sql, string $rows_alias, array $conflict_index_groups ): ?string {
		$probes = array();
		foreach ( $conflict_index_groups as $conflict_indexes ) {
			$key_sql      = array();
			$not_null_sql = array();
			foreach ( $conflict_indexes as $conflict_index ) {
				$column = (string) ( $conflict_index['column'] ?? '' );
				if ( '' === $column ) {
					return null;
				}

				$incoming_column = $this->get_postgresql_dml_qualified_column_list_sql( $rows_alias, array( $column ) );
				$not_null_sql[]  = $incoming_column . ' IS NOT NULL';
				$key_sql[]       = null !== ( $conflict_index['sub_part'] ?? null ) && '' !== (string) $conflict_index['sub_part']
					? sprintf(
						'SUBSTR(CAST(%s AS text), 1, %d)',
						$incoming_column,
						(int) $conflict_index['sub_part']
					)
					: $incoming_column;
			}

			if ( empty( $key_sql ) ) {
				return null;
			}

			$probes[] = sprintf(
				'SELECT 1 FROM %s AS %s WHERE %s GROUP BY %s HAVING COUNT(*) > 1',
				$source_table_sql,
				$rows_alias,
				implode( ' AND ', $not_null_sql ),
				implode( ', ', $key_sql )
			);
		}
		return empty( $probes ) ? null : implode( ' UNION ALL ', $probes ) . ' LIMIT 1';
	}
	private function get_mysql_replace_return_value( array &$replace_query ): ?int {
		if (
			! isset( $replace_query['table_name'], $replace_query['conflict_column'] )
			|| null === $replace_query['conflict_column']
		) {
			return null;
		}

		if ( ! empty( $replace_query['delete_then_insert'] ) ) {
			return null;
		}

		if ( isset( $replace_query['replace_select_affected_rows_sql'] ) && is_string( $replace_query['replace_select_affected_rows_sql'] ) ) {
			$stmt = $this->connection->query( $replace_query['replace_select_affected_rows_sql'] );
			$row  = $stmt->fetch( PDO::FETCH_ASSOC );
			if ( ! is_array( $row ) || ! isset( $row['affected_rows'] ) ) {
				return null;
			}

			$replace_query['inserted_new_row'] = isset( $row['inserted_rows'] ) && (int) $row['inserted_rows'] > 0;
			return (int) $row['affected_rows'];
		}

		if (
			! isset( $replace_query['value_rows'], $replace_query['conflict_indexes'] )
			|| ! is_array( $replace_query['value_rows'] )
			|| ! is_array( $replace_query['conflict_indexes'] )
		) {
			return null;
		}

		$probe_safe_rows = isset( $replace_query['conflict_probe_safe_rows'] ) && is_array( $replace_query['conflict_probe_safe_rows'] )
			? $replace_query['conflict_probe_safe_rows']
			: array();

		$return_value     = 0;
		$inserted_new_row = false;
		$seen_values      = array();
		foreach ( $replace_query['value_rows'] as $row_index => $values ) {
			if ( ! is_array( $values ) ) {
				return null;
			}

			if ( ! $this->mysql_upsert_conflict_indexes_are_probe_safe_for_row( $values, $probe_safe_rows[ $row_index ] ?? array(), $replace_query['conflict_indexes'] ) ) {
				return null;
			}

			$seen_key                = $this->get_mysql_replace_conflict_seen_key_for_row( $values, $replace_query['conflict_indexes'] );
			$replace_conflict_exists = null !== $seen_key && isset( $seen_values[ $seen_key ] );
			if ( ! $replace_conflict_exists ) {
				$replace_conflict_exists = $this->mysql_upsert_conflict_exists(
					(string) $replace_query['table_name'],
					$values,
					$replace_query['conflict_indexes']
				);
				if ( null === $replace_conflict_exists ) {
					return null;
				}
			}

			$return_value += $replace_conflict_exists ? 2 : 1;
			if ( ! $replace_conflict_exists ) {
				$inserted_new_row = true;
			}
			if ( null !== $seen_key ) {
				$seen_values[ $seen_key ] = true;
			}
		}

		$replace_query['inserted_new_row'] = ! empty( $replace_query['delete_then_insert'] ) ? true : $inserted_new_row;
		return $return_value;
	}
	private function get_mysql_replace_conflict_target( string $table_name, array $columns, ?array $value_rows = null, ?array $probe_safe_rows = null, ?array $unique_index_metadata_rows = null, ?array $unique_index_groups = null ): ?array {
		$metadata_target  = $this->get_mysql_upsert_conflict_target( $table_name, $columns, $value_rows, $probe_safe_rows, $unique_index_metadata_rows );
		$heuristic_target = $this->get_simple_replace_conflict_target( $table_name, $columns );
		if ( null === $heuristic_target ) {
			return $metadata_target;
		}

		if ( null === $metadata_target ) {
			return $heuristic_target;
		}

		if (
			$this->is_mysql_replace_conflict_target_backed_by_unique_metadata( $table_name, $heuristic_target, $unique_index_groups )
			&&
			null !== $value_rows
			&& null !== $probe_safe_rows
			&& ! $this->mysql_replace_conflict_target_has_existing_conflict(
				$table_name,
				$columns,
				$value_rows,
				$probe_safe_rows,
				$metadata_target
			)
		) {
			return $heuristic_target;
		}
		return $metadata_target;
	}
	private function is_mysql_replace_conflict_target_backed_by_unique_metadata( string $table_name, array $conflict_target, ?array $unique_index_groups = null ): bool {
		$target_parts = $conflict_target['parts'] ?? array();
		if ( empty( $target_parts ) ) {
			return false;
		}

		if ( null === $unique_index_groups ) {
			$table_schema        = $this->get_mysql_unqualified_dml_table_backend_schema( $table_name );
			$unique_index_groups = $this->get_mysql_unique_index_groups_from_metadata_rows( $this->get_mysql_unique_index_metadata_rows( $table_schema, $table_name ) );
		}

		foreach ( $unique_index_groups as $index ) {
			if ( $this->is_mysql_metadata_only_index_type( $index['index_type'] ) ) {
				continue;
			}

			if ( count( $index['parts'] ) !== count( $target_parts ) ) {
				continue;
			}

			foreach ( $target_parts as $part_index => $part ) {
				$index_part = $index['parts'][ $part_index ];
				if (
					0 !== strcasecmp( (string) ( $index_part['column'] ?? '' ), (string) ( $part['column'] ?? '' ) )
					|| (string) ( $index_part['sub_part'] ?? '' ) !== (string) ( $part['sub_part'] ?? '' )
				) {
					continue 2;
				}
			}
			return true;
		}
		return false;
	}
	private function get_mysql_unique_index_groups_from_metadata_rows( array $rows ): array {
		$indexes = array();
		foreach ( $rows as $row ) {
			$key_name = (string) ( $row['key_name'] ?? '' );
			if ( '' === $key_name ) {
				continue;
			}

			if ( ! isset( $indexes[ $key_name ] ) ) {
				$indexes[ $key_name ] = array(
					'columns'    => array(),
					'index_type' => strtoupper( (string) ( $row['index_type'] ?? 'BTREE' ) ),
					'parts'      => array(),
				);
			}

			$column_name = (string) ( $row['column_name'] ?? '' );
			if ( '' === $column_name ) {
				continue;
			}

			$indexes[ $key_name ]['columns'][] = $column_name;
			$indexes[ $key_name ]['parts'][]   = array(
				'column'   => $column_name,
				'sub_part' => null !== ( $row['sub_part'] ?? null ) && '' !== (string) $row['sub_part'] ? (string) $row['sub_part'] : null,
			);
		}
		return $indexes;
	}
	private function get_simple_replace_conflict_target( string $table_name, array $columns ): ?array {
		$conflict_column = $this->get_simple_replace_conflict_column( $table_name, $columns );
		if ( null === $conflict_column ) {
			return null;
		}
		return array(
			'columns' => array( $conflict_column ),
			'parts'   => array(
				array(
					'column'   => $conflict_column,
					'sub_part' => null,
				),
			),
			'sql'     => array( $this->connection->quote_identifier( $conflict_column ) ),
		);
	}
	private function mysql_replace_conflict_target_has_existing_conflict( string $table_name, array $columns, array $value_rows, array $probe_safe_rows, array $conflict_target ): bool {
		$conflict_indexes = $this->get_mysql_upsert_conflict_indexes( $columns, $conflict_target['parts'] ?? array() );
		if ( null === $conflict_indexes ) {
			return true;
		}

		foreach ( $value_rows as $row_index => $values ) {
			if ( ! $this->mysql_upsert_conflict_indexes_are_probe_safe_for_row( $values, $probe_safe_rows[ $row_index ] ?? array(), $conflict_indexes ) ) {
				return true;
			}

			$conflict_exists = $this->mysql_upsert_conflict_exists( $table_name, $values, $conflict_indexes );
			if ( null === $conflict_exists || $conflict_exists ) {
				return true;
			}
		}
		return false;
	}
	private function has_duplicate_mysql_replace_conflict_value_rows( array $value_rows, array $probe_safe_rows, array $conflict_indexes ): bool {
		$seen_values = array();
		foreach ( $value_rows as $row_index => $values ) {
			if ( ! $this->mysql_upsert_conflict_indexes_are_probe_safe_for_row( $values, $probe_safe_rows[ $row_index ] ?? array(), $conflict_indexes ) ) {
				return false;
			}

			$seen_key = $this->get_mysql_replace_conflict_seen_key_for_row( $values, $conflict_indexes );
			if ( null === $seen_key ) {
				continue;
			}

			if ( isset( $seen_values[ $seen_key ] ) ) {
				return true;
			}

			$seen_values[ $seen_key ] = true;
		}
		return false;
	}
	private function has_duplicate_mysql_replace_conflict_value_rows_in_groups( array $value_rows, array $probe_safe_rows, array $conflict_index_groups ): bool {
		foreach ( $conflict_index_groups as $conflict_indexes ) {
			if ( $this->has_duplicate_mysql_replace_conflict_value_rows( $value_rows, $probe_safe_rows, $conflict_indexes ) ) {
				return true;
			}
		}
		return false;
	}
	private function get_mysql_replace_conflict_seen_key_for_row( array $values, array $conflict_indexes ): ?string {
		$parts = array();
		foreach ( $conflict_indexes as $conflict_index ) {
			if ( ! array_key_exists( $conflict_index['index'], $values ) ) {
				return null;
			}

			$value = trim( (string) $values[ $conflict_index['index'] ] );
			if ( $this->is_mysql_replace_conflict_ignored_value_sql( $value ) ) {
				return null;
			}

			if ( null !== ( $conflict_index['sub_part'] ?? null ) && '' !== (string) $conflict_index['sub_part'] ) {
				$value = $this->get_mysql_replace_conflict_prefix_seen_value( $value, (int) $conflict_index['sub_part'] );
			}

			$parts[] = $value;
		}
		return implode( "\0", $parts );
	}
	private function is_mysql_replace_conflict_ignored_value_sql( string $value_sql ): bool {
		return '' === $value_sql
			|| 'NULL' === strtoupper( $value_sql )
			|| $this->is_mysql_generated_auto_increment_value_sql( $value_sql );
	}
	private function get_mysql_replace_conflict_prefix_seen_value( string $value_sql, int $length ): string {
		if ( $length <= 0 || strlen( $value_sql ) < 2 || "'" !== $value_sql[0] || "'" !== $value_sql[ strlen( $value_sql ) - 1 ] ) {
			return $value_sql;
		}

		$value = str_replace( "''", "'", substr( $value_sql, 1, -1 ) );
		$count = preg_match_all( '/./us', $value, $matches );
		if ( false !== $count ) {
			$value = implode( '', array_slice( $matches[0], 0, $length ) );
		} else {
			$value = substr( $value, 0, $length );
		}
		return "'" . str_replace( "'", "''", $value ) . "'";
	}
	private function get_simple_replace_conflict_column( string $table_name, array $columns ): ?string {
		$column_lookup = array();
		foreach ( $columns as $column ) {
			$column_lookup[ strtolower( $column ) ] = $column;
		}

		if ( $this->is_wordpress_options_table_name( $table_name ) && isset( $column_lookup['option_name'] ) ) {
			return $column_lookup['option_name'];
		}

		if ( $this->is_mysql_wordpress_table_name( $table_name, 'wc_customer_lookup' ) && isset( $column_lookup['customer_id'] ) ) {
			return $column_lookup['customer_id'];
		}

		if ( $this->is_mysql_wordpress_table_name( $table_name, 'wc_product_meta_lookup' ) && isset( $column_lookup['product_id'] ) ) {
			return $column_lookup['product_id'];
		}

		foreach ( explode( ' ', 'id comment_id link_id option_id meta_id umeta_id term_id term_taxonomy_id' ) as $candidate ) {
			if ( isset( $column_lookup[ $candidate ] ) ) {
				return $column_lookup[ $candidate ];
			}
		}
		return null;
	}
	private function translate_simple_mysql_insert_query( string $query ): ?array {
		$tokens   = $this->get_mysql_tokens( $query );
		$position = 1;
		$ignore   = false;
		$header   = $this->parse_mysql_insert_table_header( $tokens, $position, $ignore );
		if ( null === $header ) {
			return null;
		}
		$table_name = $header['table'];

		$statement_end = $this->get_mysql_statement_end_position( $tokens, $position );
		if ( null === $statement_end ) {
			return null;
		}

		$column_metadata = null;
		$source          = $this->parse_mysql_insert_like_dml_source(
			$table_name,
			$tokens,
			$position,
			$statement_end,
			$column_metadata,
			array(
				'allow_select_source' => false,
			)
		);
		if ( null === $source ) {
			return null;
		}
		$columns          = $source['columns'];
		$value_rows       = $source['value_rows'];
		$value_range_rows = $source['value_range_rows'];

		$column_metadata = $this->normalize_mysql_dml_value_rows_for_columns(
			$table_name,
			$columns,
			$value_rows,
			$value_range_rows,
			$tokens,
			$column_metadata
		);
		$this->append_non_strict_dml_defaults_for_omitted_value_rows( $table_name, $columns, $value_rows, $column_metadata );

		$sql = $this->get_postgresql_dml_insert_values_sql(
			$this->get_postgresql_unqualified_dml_table_reference_sql( $table_name ),
			$columns,
			$value_rows
		);
		return array(
			'action'           => 'insert',
			'sql'              => $ignore ? $sql . ' ON CONFLICT DO NOTHING' : $sql,
			'table_name'       => $table_name,
			'columns'          => $columns,
			'values'           => $value_rows[0] ?? array(),
			'value_rows'       => $value_rows,
			'ignore'           => $ignore,
			'inserted_new_row' => true,
		);
	}
}
