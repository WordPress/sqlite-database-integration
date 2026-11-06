<?php

/**
 * The SQL modes of the sessions in a MySQL server test file.
 *
 * The MySQL lexer reads a query according to the SQL modes of its session, which tests
 * select with statements like "SET sql_mode = 'ANSI_QUOTES'". This class follows these
 * statements and the connections of a test file, so that the query corpus can store
 * each query with its modes. It only keeps the modes that affect the lexer.
 *
 * Like the rest of the corpus extraction, this is a best effort. A value that can't be
 * evaluated, such as a numeric expression, keeps the modes. Sourced files aren't read.
 *
 * This class is separate from the "generate-query-corpus.php" script, so that tests
 * can load it without running the script.
 */
class Query_Corpus_SQL_Modes {
	/**
	 * The SQL modes that affect the lexer, in the order that the corpus stores them.
	 */
	private const LEXER_MODES = array(
		'ANSI_QUOTES',
		'HIGH_NOT_PRECEDENCE',
		'IGNORE_SPACE',
		'NO_BACKSLASH_ESCAPES',
		'PIPES_AS_CONCAT',
	);

	/**
	 * The lexer modes that the composite ANSI mode includes.
	 */
	private const ANSI_MODES = array( 'ANSI_QUOTES', 'IGNORE_SPACE', 'PIPES_AS_CONCAT' );

	/**
	 * The modes that a new session starts with.
	 *
	 * @var string[]
	 */
	private $global_modes = array();

	/**
	 * The modes of each session, by the name of its connection.
	 *
	 * @var array<string, string[]>
	 */
	private $session_modes = array( 'default' => array() );

	/**
	 * The name of the current connection.
	 *
	 * @var string
	 */
	private $connection = 'default';

	/**
	 * The modes that user variables hold, by lowercase variable name.
	 *
	 * @var array<string, string[]|null>
	 */
	private $variables = array();

	/**
	 * Get the modes of the current session.
	 *
	 * @return string The modes, separated by commas.
	 */
	public function get_modes(): string {
		return implode( ',', $this->session_modes[ $this->connection ] );
	}

	/**
	 * Follow a mysqltest command that opens, selects, or resets a connection.
	 *
	 * @param string $command The command, with or without the "--" prefix.
	 */
	public function run_command( string $command ): void {
		if ( preg_match( '/^(?:--\s*)?connect\s*\(\s*([^,\s)]+)/i', $command, $matches ) ) {
			$this->connection = $matches[1];

			$this->session_modes[ $this->connection ] = $this->global_modes;
		} elseif ( preg_match( '/^(?:--\s*)?connection\s+([^\s;]+)/i', $command, $matches ) ) {
			$this->connection = $matches[1];

			// A sourced file may have opened the connection.
			$this->session_modes[ $this->connection ] = $this->session_modes[ $this->connection ] ?? $this->global_modes;
		} elseif ( preg_match( '/^(?:--\s*)?(?:change_user|reset_connection)\b/i', $command ) ) {
			$this->session_modes[ $this->connection ] = $this->global_modes;
		}
	}

	/**
	 * Follow the statements of a query that ran without an error.
	 *
	 * @param string $query The query, which can have several statements.
	 */
	public function run_query( string $query ): void {
		if ( false === stripos( $query, 'sql_mode' ) ) {
			return;
		}

		foreach ( explode( ';', $query ) as $statement ) {
			$statement = trim( $statement );

			// A routine doesn't run when it's created, and its body may go on from here.
			if ( preg_match( '/^create\s+(?:definer\s*=\s*\S+\s+)?(?:procedure|function|trigger|event)\b/i', $statement ) ) {
				return;
			}

			if ( preg_match( '/^select\s+(@@[\w.]+)\s+into\s+(@\w+)$/i', $statement, $matches ) ) {
				$this->run_assignment( $matches[2], $matches[1] );
			} elseif ( preg_match( '/^set\s+(.*)$/is', $statement, $matches ) ) {
				// Find the assignments, whose commas aren't in strings or parentheses.
				preg_match_all(
					'/(?:[^,\'"()]++|\'[^\']*+\'|"[^"]*+"|(\((?:[^()\'"]++|\'[^\']*+\'|"[^"]*+"|(?1))*+\)))++/',
					$matches[1],
					$assignments
				);
				foreach ( $assignments[0] as $assignment ) {
					if ( preg_match( '/^\s*(.+?)\s*:?=\s*(.*?)\s*$/s', $assignment, $parts ) ) {
						$this->run_assignment( $parts[1], $parts[2] );
					}
				}
			}
		}
	}

	/**
	 * Follow an assignment to the session variable, global variable, or a user variable.
	 *
	 * @param string $target The target of the assignment.
	 * @param string $value  The assigned expression.
	 */
	private function run_assignment( string $target, string $value ): void {
		if ( preg_match( '/^(?:(?:session|local)\s+|@@(?:(?:session|local)\.)?)?sql_mode$/i', $target ) ) {
			$modes = $this->evaluate( $value, $this->global_modes );

			$this->session_modes[ $this->connection ] = $modes ?? $this->session_modes[ $this->connection ];
		} elseif ( preg_match( '/^(?:global\s+|@@global\.)sql_mode$/i', $target ) ) {
			// For the global variable, DEFAULT is the built-in value, without any lexer modes.
			$this->global_modes = $this->evaluate( $value, array() ) ?? $this->global_modes;
		} elseif ( preg_match( '/^@(\w+)$/', $target, $matches ) ) {
			$this->variables[ strtolower( $matches[1] ) ] = $this->evaluate( $value, array() );
		}
	}

	/**
	 * Evaluate an expression that test files assign to the SQL mode.
	 *
	 * @param string   $value         The expression.
	 * @param string[] $default_modes The modes that DEFAULT stands for.
	 * @return string[]|null The modes; null when the expression can't be evaluated.
	 */
	private function evaluate( string $value, array $default_modes ): ?array {
		$session = $this->session_modes[ $this->connection ];

		if ( preg_match( '/^\(\s*(?:select\s+)?(.*?)\s*\)$/is', $value, $matches ) ) {
			return $this->evaluate( $matches[1], $default_modes );
		}
		if ( preg_match( '/^([\'"])(.*)\1$/s', $value, $matches ) ) {
			return $this->read_modes( $matches[2] );
		}
		if ( 0 === strcasecmp( 'DEFAULT', $value ) ) {
			return $default_modes;
		}
		if ( '0' === $value ) {
			return array();
		}
		if ( preg_match( '/^[a-z_]+$/i', $value ) ) {
			return $this->read_modes( $value );
		}
		if ( preg_match( '/^@@(?:(?:session|local)\.)?sql_mode$/i', $value ) ) {
			return $session;
		}
		if ( 0 === strcasecmp( '@@global.sql_mode', $value ) ) {
			return $this->global_modes;
		}
		if ( preg_match( '/^@(\w+)$/', $value, $matches ) ) {
			return $this->variables[ strtolower( $matches[1] ) ] ?? null;
		}
		if ( preg_match( '/^(concat|sys\.list_add|sys\.list_drop)\s*\(\s*@@sql_mode\s*,\s*([\'"])(.*?)\2\s*\)$/is', $value, $matches ) ) {
			$modes = $this->read_modes( $matches[3] );
			return 0 === strcasecmp( 'sys.list_drop', $matches[1] )
				? array_values( array_diff( $session, $modes ) )
				: array_values( array_intersect( self::LEXER_MODES, array_merge( $session, $modes ) ) );
		}
		return null;
	}

	/**
	 * Read the lexer modes from a list of SQL modes.
	 *
	 * @param string $sql_modes The SQL modes, separated by commas.
	 * @return string[] The lexer modes.
	 */
	private function read_modes( string $sql_modes ): array {
		$modes = array_map( 'trim', explode( ',', strtoupper( $sql_modes ) ) );
		if ( in_array( 'ANSI', $modes, true ) ) {
			$modes = array_merge( $modes, self::ANSI_MODES );
		}
		return array_values( array_intersect( self::LEXER_MODES, $modes ) );
	}
}
