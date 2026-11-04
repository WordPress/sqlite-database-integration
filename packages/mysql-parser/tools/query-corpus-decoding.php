<?php

/**
 * Functions that read the lines of MySQL server test files as UTF-8 text.
 *
 * MySQL reads a query in the character set of the client, which tests select with
 * statements like "SET NAMES". The "--character_set" command of mysqltest doesn't
 * change the character set of the current connection, but tests use it to mark
 * bytes in another character set, so it is the fallback. A test that sends UTF-8
 * bytes with "SET NAMES latin1" is read as latin1 too, because MySQL reads it so.
 * A character set introducer overrides all of them for its string literal.
 *
 * These functions are separate from the "generate-query-corpus.php" script, so that
 * tests can load them without running the script.
 */

// MySQL character sets that tests select, and the mbstring encodings to read them
// with. MySQL's latin1 is Windows-1252, binary data is read as UTF-8 when valid, and
// TIS-620 and Windows-1250, which mbstring doesn't support, are converted by
// convert_bytes_to_utf8(). "cp1250_latin2" is an old name that selects cp1250.
const QUERY_CORPUS_CHARSET_ENCODINGS = array(
	'big5'          => 'BIG-5',
	'binary'        => 'UTF-8',
	'cp1250'        => 'Windows-1250',
	'cp1250_latin2' => 'Windows-1250',
	'cp1251'        => 'Windows-1251',
	'gb18030'       => 'GB18030',
	'gbk'           => 'CP936',
	'koi8r'         => 'KOI8-R',
	'latin1'        => 'Windows-1252',
	'latin2'        => 'ISO-8859-2',
	'sjis'          => 'SJIS',
	'tis620'        => 'TIS-620',
	'ujis'          => 'EUC-JP',
	'utf8'          => 'UTF-8',
	'utf8mb3'       => 'UTF-8',
	'utf8mb4'       => 'UTF-8',
);

// The Windows-1250 characters of the bytes that differ from ISO-8859-2.
const QUERY_CORPUS_CP1250_CHARACTERS = array(
	"\x80" => '€',
	"\x82" => '‚',
	"\x84" => '„',
	"\x85" => '…',
	"\x86" => '†',
	"\x87" => '‡',
	"\x89" => '‰',
	"\x8A" => 'Š',
	"\x8B" => '‹',
	"\x8C" => 'Ś',
	"\x8D" => 'Ť',
	"\x8E" => 'Ž',
	"\x8F" => 'Ź',
	"\x91" => '‘',
	"\x92" => '’',
	"\x93" => '“',
	"\x94" => '”',
	"\x95" => '•',
	"\x96" => '–',
	"\x97" => '—',
	"\x99" => '™',
	"\x9A" => 'š',
	"\x9B" => '›',
	"\x9C" => 'ś',
	"\x9D" => 'ť',
	"\x9E" => 'ž',
	"\x9F" => 'ź',
	"\xA1" => 'ˇ',
	"\xA5" => 'Ą',
	"\xA6" => '¦',
	"\xA9" => '©',
	"\xAB" => '«',
	"\xAC" => '¬',
	"\xAE" => '®',
	"\xB1" => '±',
	"\xB5" => 'µ',
	"\xB6" => '¶',
	"\xB7" => '·',
	"\xB9" => 'ą',
	"\xBB" => '»',
	"\xBC" => 'Ľ',
	"\xBE" => 'ľ',
);

/**
 * Read the character set from a statement that sets the client character set.
 *
 * @param string $line The line bytes.
 * @return string|null The lowercase character set name, as written in the statement;
 *                     null when the line doesn't start with such a statement.
 */
function read_client_charset( string $line ): ?string {
	$is_statement = preg_match(
		'/^\s*set\s+(?:names\s+|char(?:acter\s+)?set\s+|(?:(?:session|local)\s+|@@(?:(?:session|local)\.)?)?character_set_client\s*=\s*)[\'"]?(@?\w+)/i',
		$line,
		$matches
	);
	return $is_statement ? strtolower( $matches[1] ) : null;
}

/**
 * Convert a line of a test file to UTF-8.
 *
 * A string literal with a character set introducer, such as "_koi8r'...'", is read
 * in that character set, as in MySQL. The rest of the line is read in the given
 * encodings.
 *
 * @param string   $line      The line bytes.
 * @param string[] $encodings The mbstring encodings to try, in order.
 * @return string The line in UTF-8.
 */
function convert_to_utf8( string $line, array $encodings ): string {
	if ( ! preg_match( '/[\x80-\xff]/', $line ) ) {
		return $line;
	}

	preg_match_all(
		'/(?<![\w$])_(\w+)\s*(\'(?:[^\'\\\\]++|\\\\.|\'\')*+\'|"(?:[^"\\\\]++|\\\\.|"")*+")/s',
		$line,
		$literals,
		PREG_SET_ORDER | PREG_OFFSET_CAPTURE
	);

	$converted = '';
	$offset    = 0;
	foreach ( $literals as $literal ) {
		$encoding = QUERY_CORPUS_CHARSET_ENCODINGS[ strtolower( $literal[1][0] ) ] ?? null;
		if ( null === $encoding ) {
			continue;
		}
		list( $text, $text_offset ) = $literal[2];

		$converted .= convert_bytes_to_utf8( substr( $line, $offset, $text_offset - $offset ), $encodings );
		$converted .= convert_bytes_to_utf8( $text, array_merge( array( $encoding ), $encodings ) );
		$offset     = $text_offset + strlen( $text );
	}
	return $converted . convert_bytes_to_utf8( substr( $line, $offset ), $encodings );
}

/**
 * Convert bytes to UTF-8.
 *
 * The bytes are read in the first of the encodings that they are valid in. When
 * there is none, as with invalid strings that some tests use on purpose, they are
 * read as MySQL's latin1 (Windows-1252), which maps every byte to a character.
 *
 * @param string   $bytes     The bytes.
 * @param string[] $encodings The mbstring encodings to try, in order.
 * @return string The bytes in UTF-8.
 */
function convert_bytes_to_utf8( string $bytes, array $encodings ): string {
	foreach ( $encodings as $encoding ) {
		if ( 'Windows-1252' === $encoding ) {
			break;
		}
		if ( 'TIS-620' === $encoding ) {
			// The TIS-620 characters map to the Unicode Thai block in the same order.
			if ( ! preg_match( '/[\x80-\xa0\xdb-\xde\xfc-\xff]/', $bytes ) ) {
				return preg_replace_callback(
					'/[\xa1-\xfb]/',
					function ( array $matches ): string {
						return mb_chr( 0x0E01 + ord( $matches[0] ) - 0xA1, 'UTF-8' );
					},
					$bytes
				);
			}
		} elseif ( 'Windows-1250' === $encoding ) {
			// MySQL's cp1250 leaves these five bytes undefined.
			if ( ! preg_match( '/[\x81\x83\x88\x90\x98]/', $bytes ) ) {
				return convert_cp1250_to_utf8( $bytes );
			}
		} elseif ( mb_check_encoding( $bytes, $encoding ) ) {
			return mb_convert_encoding( $bytes, 'UTF-8', $encoding );
		}
	}
	return convert_latin1_to_utf8( $bytes );
}

/**
 * Convert bytes in MySQL's cp1250 to UTF-8.
 *
 * @param string $bytes The cp1250 bytes, without any undefined ones.
 * @return string The bytes in UTF-8.
 */
function convert_cp1250_to_utf8( string $bytes ): string {
	return preg_replace_callback(
		'/[\x80-\xff]/',
		function ( array $matches ): string {
			return QUERY_CORPUS_CP1250_CHARACTERS[ $matches[0] ]
				?? mb_convert_encoding( $matches[0], 'UTF-8', 'ISO-8859-2' );
		},
		$bytes
	);
}

/**
 * Convert bytes in MySQL's latin1 to UTF-8.
 *
 * MySQL's latin1 keeps the five bytes that Windows-1252 leaves undefined as C1
 * controls. So does mbstring since PHP 8.1, but below that, it replaces them.
 *
 * @param string $bytes The latin1 bytes.
 * @return string The bytes in UTF-8.
 */
function convert_latin1_to_utf8( string $bytes ): string {
	return preg_replace_callback(
		'/[\x80-\xff]/',
		function ( array $matches ): string {
			return false === strpos( "\x81\x8d\x8f\x90\x9d", $matches[0] )
				? mb_convert_encoding( $matches[0], 'UTF-8', 'Windows-1252' )
				: mb_chr( ord( $matches[0] ), 'UTF-8' );
		},
		$bytes
	);
}
