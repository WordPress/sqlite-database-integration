<?php

/**
 * Generate the REGEXP test data in "tests/regexp/data" from a MySQL server.
 *
 * Each source gives cases of a pattern, a subject, and a match type. Every pattern and
 * subject also gets a case with a null match type, which runs without the argument.
 * MySQL is the judge: the script runs the checks of every case on the server, and it
 * stores the value that MySQL returns, or the code and message of the error that it
 * raises. See "tests/regexp/WP_MySQL_On_SQLite_Regexp_Test_Case.php" for the checks.
 *
 * Sources:
 *   mysql-server-suite  Patterns of REGEXP, RLIKE, and REGEXP_*() in the MySQL server
 *                       test suite, in "tests/mysql/data/mysql-server-tests-queries.csv".
 *                       Each pattern gets the literal subjects that the suite uses with
 *                       it and a fixed set of varied subjects, in match type "c".
 *
 * USAGE:
 *   MYSQL_PORT=3306 MYSQL_PWD=root php tests/tools/generate-regexp-test-data.php [<source>...]
 *
 * The server is set by MYSQL_HOST (default 127.0.0.1), MYSQL_PORT (3306), MYSQL_USER
 * (root), and MYSQL_PWD (empty). Use MySQL 8.4, for example:
 *
 *   docker run -d --name mysql-regexp -e MYSQL_ROOT_PASSWORD=root -p 127.0.0.1:3306:3306 mysql:8.4
 *
 * The case IDs change when the cases change, so update the pinned failures of the
 * test classes in "tests/regexp" too.
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../regexp/WP_MySQL_On_SQLite_Regexp_Test_Case.php';

// Varied subjects for the patterns of the MySQL server test suite.
const VARIED_SUBJECTS = array(
	'',
	'Hello, World! 123',
	"one\r\ntwo\nthree\rfour",
	"tab\tvertical\vform\ffeed",
	'café Ñandú Åström',
	"cafe\u{301} nai\u{308}ve",
	'Ελληνικά Кириллица',
	'नमस्ते สวัสดี தமிழ்',
	'日本語 한국어 中文',
	"👍🏽 ♥\u{FE0F} 😀",
	'İstanbul ılık Straße ǅ',
	"non\u{A0}breaking line\u{2028}separator next\u{85}line",
);

$sources = array_slice( $argv, 1 );
if ( ! $sources ) {
	$sources = WP_MySQL_On_SQLite_Regexp_Test_Case::SOURCES;
}

$pdo = connect_to_mysql();
foreach ( $sources as $source ) {
	if ( 'mysql-server-suite' === $source ) {
		$description = 'Patterns of REGEXP, RLIKE, and REGEXP_*() in the MySQL server test suite, in "tests/mysql/data/mysql-server-tests-queries.csv".';
		$cases       = get_mysql_server_suite_cases();
	} else {
		throw new InvalidArgumentException( "Unknown source: $source" );
	}

	$data = array(
		'description'   => $description,
		'mysql_version' => $pdo->query( 'SELECT VERSION()' )->fetchColumn(),
		'cases'         => judge_cases( $pdo, add_null_match_type_cases( $cases ) ),
	);
	write_data( $source, $data );
	printf( "%s: %d cases\n", $source, count( $data['cases'] ) );
}

/**
 * Connect to the MySQL server, and check that its REGEXP limits have the default values.
 */
function connect_to_mysql(): PDO {
	$pdo = new PDO(
		sprintf( 'mysql:host=%s;port=%d', get_env( 'MYSQL_HOST', '127.0.0.1' ), get_env( 'MYSQL_PORT', '3306' ) ),
		get_env( 'MYSQL_USER', 'root' ),
		get_env( 'MYSQL_PWD', '' ),
		array(
			PDO::ATTR_ERRMODE          => PDO::ERRMODE_EXCEPTION,
			PDO::ATTR_EMULATE_PREPARES => false,
		)
	);
	$pdo->exec( 'SET NAMES utf8mb4' );

	$limits = $pdo->query( 'SELECT @@regexp_time_limit, @@regexp_stack_limit' )->fetch( PDO::FETCH_NUM );
	if ( array( 32, 8000000 ) !== array_map( 'intval', $limits ) ) {
		throw new RuntimeException( 'The server must use the default regexp_time_limit and regexp_stack_limit.' );
	}
	return $pdo;
}

/**
 * Get the value of an environment variable, or a default value when it isn't set.
 */
function get_env( string $name, string $default_value ): string {
	$value = getenv( $name );
	return false === $value ? $default_value : $value;
}

/**
 * Get the patterns that the MySQL server test suite uses with REGEXP, RLIKE, and the
 * REGEXP_*() functions, as string literals. Pair each of them with the literal subjects
 * that the suite uses with it, and with the varied subjects, in match type "c". The
 * cases without a match type ignore case, so an "i" match type would repeat them.
 */
function get_mysql_server_suite_cases(): array {
	$patterns = array();
	$handle   = fopen( __DIR__ . '/../mysql/data/mysql-server-tests-queries.csv', 'r' );
	while ( ( $record = fgetcsv( $handle, null, ',', '"', '\\' ) ) !== false ) {
		if ( ! preg_match( '/regexp|rlike/i', $record[0] ) ) {
			continue;
		}
		$tokens = ( new WP_MySQL_Lexer( $record[0] ) )->remaining_tokens();
		foreach ( $tokens as $i => $token ) {
			if ( WP_MySQL_Lexer::REGEXP_SYMBOL === $token->id ) {
				// "subject [NOT] REGEXP pattern", where RLIKE is the same token.
				$subject_index = WP_MySQL_Lexer::NOT_SYMBOL === ( $tokens[ $i - 1 ]->id ?? null ) ? $i - 2 : $i - 1;
				$subject       = get_string_literal( $tokens, $subject_index );
				$pattern       = get_string_literal( $tokens, $i + 1 );
			} elseif (
				WP_MySQL_Lexer::IDENTIFIER === $token->id
				&& preg_match( '/^REGEXP_(?:LIKE|INSTR|SUBSTR|REPLACE)$/i', $token->get_value() )
				&& WP_MySQL_Lexer::OPEN_PAR_SYMBOL === ( $tokens[ $i + 1 ]->id ?? null )
				&& WP_MySQL_Lexer::COMMA_SYMBOL === ( $tokens[ $i + 3 ]->id ?? null )
			) {
				// "REGEXP_*(subject, pattern, ...)", with a single-token subject.
				$subject = get_string_literal( $tokens, $i + 2 );
				$pattern = get_string_literal( $tokens, $i + 4 );
			} else {
				continue;
			}

			if ( null !== $pattern ) {
				$patterns[ $pattern ][] = $subject;
			}
		}
	}
	fclose( $handle );

	$cases = array();
	foreach ( $patterns as $pattern => $subjects ) {
		$subjects = array_merge( array_filter( $subjects, 'is_string' ), VARIED_SUBJECTS );
		foreach ( array_unique( $subjects ) as $subject ) {
			$cases[] = array(
				'pattern'    => (string) $pattern,
				'match_type' => 'c',
				'subject'    => $subject,
			);
		}
	}
	return $cases;
}

/**
 * Get the value of a string literal at a token index, when it isn't a part of a series
 * of adjacent string literals, which MySQL concatenates.
 *
 * @param WP_MySQL_Token[] $tokens The tokens.
 * @param int              $index  The token index.
 */
function get_string_literal( array $tokens, int $index ): ?string {
	$is_string = function ( int $index ) use ( $tokens ): bool {
		return isset( $tokens[ $index ] ) && in_array(
			$tokens[ $index ]->id,
			array( WP_MySQL_Lexer::SINGLE_QUOTED_TEXT, WP_MySQL_Lexer::DOUBLE_QUOTED_TEXT ),
			true
		);
	};
	if ( ! $is_string( $index ) || $is_string( $index - 1 ) || $is_string( $index + 1 ) ) {
		return null;
	}
	return $tokens[ $index ]->get_value();
}

/**
 * Add a case with a null match type before the first case of each pattern and subject.
 */
function add_null_match_type_cases( array $cases ): array {
	$result = array();
	$seen   = array();
	foreach ( $cases as $case ) {
		$key = "{$case['pattern']}\0{$case['subject']}";
		if ( ! isset( $seen[ $key ] ) ) {
			$seen[ $key ] = true;
			$result[]     = array_merge( $case, array( 'match_type' => null ) );
		}
		$result[] = $case;
	}
	return $result;
}

/**
 * Run the checks of all cases on MySQL, and add an ID, a group count, and the results.
 */
function judge_cases( PDO $pdo, array $cases ): array {
	$statements = array();
	$groups     = array();
	$judged     = array();
	foreach ( $cases as $case ) {
		$pattern = $case['pattern'];
		if ( ! isset( $groups[ $pattern ] ) ) {
			$groups[ $pattern ] = count_groups( $pdo, $pattern );
		}
		$case = array_merge( array( 'id' => count( $judged ) + 1 ), $case, array( 'groups' => $groups[ $pattern ] ) );

		foreach ( array_keys( WP_MySQL_On_SQLite_Regexp_Test_Case::QUERIES ) as $check ) {
			$sql = WP_MySQL_On_SQLite_Regexp_Test_Case::build_query( $check, '?', '?', '?', $case['match_type'] );
			if ( ! isset( $statements[ $sql ] ) ) {
				$statements[ $sql ] = $pdo->prepare( $sql );
			}
			$params = array( $case['subject'], $pattern );
			if ( 'regexp_replace' === $check ) {
				$params[] = WP_MySQL_On_SQLite_Regexp_Test_Case::get_template( $case['groups'] );
			}
			$result = run_statement( $statements[ $sql ], $params );
			if ( 'regexp_replace' === $check && 3686 === ( $result['error'] ?? null ) ) {
				throw new RuntimeException( 'Wrong group count for pattern ' . json_encode( $pattern ) );
			}

			// Store numbers as numbers, whether or not PDO returns native types.
			$is_number      = in_array( $check, array( 'regexp_like', 'regexp_instr', 'regexp_instr_end' ), true );
			$case[ $check ] = $is_number && is_scalar( $result ) ? (int) $result : $result;
		}
		$judged[] = $case;
	}
	return $judged;
}

/**
 * Count the capture groups of a pattern.
 *
 * The pattern runs in its own group, followed by "(?!)", in the first branch of an
 * alternation. The group keeps any "|" of the pattern inside it, so the branch can't
 * match, and the empty branch matches the subject "x", with each capture group empty.
 * A template with "$n" then gives "<>x<>" up to the last group. After that, MySQL
 * raises an error, or reads fewer digits, like "$1" and "0" for "$10" with 9 groups.
 * Inside the group, the "\E" ends an unterminated "\Q", and the line break ends
 * a comment of the "x" flag.
 */
function count_groups( PDO $pdo, string $pattern ): int {
	$statement = $pdo->prepare( "SELECT REGEXP_REPLACE('x', ?, ?)" );
	for ( $groups = 0; ; $groups++ ) {
		$result = run_statement( $statement, array( "(?:(?:$pattern\\E\n)(?!))|", '<$' . ( $groups + 1 ) . '>' ) );
		if ( '<>x<>' !== $result ) {
			break;
		}
	}
	if ( is_string( $result ) || 3686 === $result['error'] ) {
		return $groups;
	}

	// The wrapped pattern doesn't compile, and neither should the pattern.
	$statement = $pdo->prepare( "SELECT REGEXP_LIKE('x', ?)" );
	if ( 0 === $groups && is_array( run_statement( $statement, array( $pattern ) ) ) ) {
		return 0;
	}
	throw new RuntimeException( 'Could not count the groups of pattern ' . json_encode( $pattern ) );
}

/**
 * Run a statement, and get its value, or the code and message of its error.
 *
 * @return mixed The value, or an array with "error" and "message".
 */
function run_statement( PDOStatement $statement, array $params ) {
	try {
		$statement->execute( $params );
		$value = $statement->fetchColumn();
		$statement->closeCursor();
	} catch ( PDOException $e ) {
		// Don't store a lost connection or another client error as a result.
		if ( $e->errorInfo[1] >= 2000 && $e->errorInfo[1] < 3000 ) {
			throw $e;
		}
		return array(
			'error'   => $e->errorInfo[1],
			'message' => $e->errorInfo[2],
		);
	}
	return $value;
}

/**
 * Write the data of a source, with one case per line.
 */
function write_data( string $source, array $data ): void {
	$cases = array();
	foreach ( $data['cases'] as $case ) {
		$cases[] = encode_json( $case );
	}
	$json  = "{\n";
	$json .= "\t\"description\": " . encode_json( $data['description'] ) . ",\n";
	$json .= "\t\"mysql_version\": " . encode_json( $data['mysql_version'] ) . ",\n";
	$json .= "\t\"cases\": [\n\t\t" . implode( ",\n\t\t", $cases ) . "\n\t]\n";
	$json .= "}\n";
	file_put_contents( WP_MySQL_On_SQLite_Regexp_Test_Case::DATA_DIR . "/$source.json", $json );
}

/**
 * Encode a value as JSON, with escapes for invisible characters and line breaks.
 *
 * @param mixed $value The value.
 */
function encode_json( $value ): string {
	$json = json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	if ( false === $json ) {
		throw new RuntimeException( 'Could not encode JSON: ' . json_last_error_msg() );
	}
	return preg_replace_callback(
		'/[\p{Cc}\p{Cf}\p{Co}\p{Zl}\p{Zp}]/u',
		function ( array $matches ): string {
			$code_point = mb_ord( $matches[0], 'UTF-8' );
			if ( $code_point < 0x10000 ) {
				return sprintf( '\u%04x', $code_point );
			}
			$code_point -= 0x10000;
			return sprintf( '\u%04x\u%04x', 0xD800 | ( $code_point >> 10 ), 0xDC00 | ( $code_point & 0x3FF ) );
		},
		$json
	);
}
