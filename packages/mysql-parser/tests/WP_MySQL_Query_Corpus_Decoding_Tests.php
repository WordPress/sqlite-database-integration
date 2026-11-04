<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../tools/query-corpus-decoding.php';

/**
 * Tests for reading the lines of MySQL server test files as UTF-8 text.
 */
class WP_MySQL_Query_Corpus_Decoding_Tests extends TestCase {
	/**
	 * @dataProvider data_lines_in_character_sets
	 */
	public function test_line_is_read_in_its_character_set( string $charset, string $line, string $expected ): void {
		$this->assertSame(
			$expected,
			convert_to_utf8( $line, array( QUERY_CORPUS_CHARSET_ENCODINGS[ $charset ] ) )
		);
	}

	public function test_line_is_read_in_the_first_valid_encoding(): void {
		$koi8r_bytes = "SELECT '\xD4\xC5\xD3\xD4'";

		// The client character set goes first, even when it reads the bytes differently.
		$this->assertSame( "SELECT 'тест'", convert_to_utf8( $koi8r_bytes, array( 'KOI8-R', 'Windows-1251' ) ) );
		$this->assertSame( "SELECT 'ФЕУФ'", convert_to_utf8( $koi8r_bytes, array( 'Windows-1251', 'KOI8-R' ) ) );

		// Bytes that aren't valid in the client character set fall back to the next one.
		$this->assertSame( "SELECT 'тест'", convert_to_utf8( $koi8r_bytes, array( 'UTF-8', 'KOI8-R' ) ) );
		$this->assertSame( "SELECT 'é'", convert_to_utf8( "SELECT 'é'", array( 'UTF-8', 'KOI8-R' ) ) );

		// Latin1 takes any bytes, so nothing is tried after it.
		$this->assertSame( "SELECT 'Ã©'", convert_to_utf8( "SELECT 'é'", array( 'Windows-1252', 'UTF-8' ) ) );

		// Bytes that aren't valid in any of the encodings are read as latin1.
		$this->assertSame( "SELECT 'é'", convert_to_utf8( "SELECT '\xE9'", array( 'UTF-8', 'SJIS' ) ) );
	}

	/**
	 * Test every non-ASCII byte that MySQL's cp1250 defines. The expected text comes
	 * from MySQL, as the result of CONVERT(_cp1250 x'..' USING utf32) for each byte.
	 */
	public function test_cp1250_characters_match_mysql(): void {
		$bytes = '';
		for ( $byte = 0x80; $byte <= 0xFF; $byte += 1 ) {
			if ( false === strpos( "\x81\x83\x88\x90\x98", chr( $byte ) ) ) {
				$bytes .= chr( $byte );
			}
		}

		$this->assertSame(
			"€‚„…†‡‰Š‹ŚŤŽŹ‘’“”•–—™š›śťžź\u{A0}ˇ˘Ł¤Ą¦§"
			. "¨©Ş«¬\u{AD}®Ż°±˛ł´µ¶·¸ąş»Ľ˝ľżŔÁÂĂÄĹĆÇČÉĘ"
			. 'ËĚÍÎĎĐŃŇÓÔŐÖ×ŘŮÚŰÜÝŢßŕáâăäĺćçčéęëěíîďđńň'
			. 'óôőö÷řůúűüýţ˙',
			convert_to_utf8( $bytes, array( QUERY_CORPUS_CHARSET_ENCODINGS['cp1250'] ) )
		);
	}

	/**
	 * @dataProvider data_introduced_literals
	 */
	public function test_introduced_literal_is_read_in_its_character_set( string $encoding, string $line, string $expected ): void {
		$this->assertSame( $expected, convert_to_utf8( $line, array( $encoding ) ) );
	}

	/**
	 * @return array<string,array{0:string,1:string,2:string}>
	 */
	public function data_introduced_literals(): array {
		return array(
			'utf8mb3 in a koi8r line'      => array( 'KOI8-R', "SELECT _utf8mb3'тест'", "SELECT _utf8mb3'тест'" ),
			'koi8r in a UTF-8 line'        => array( 'UTF-8', "SELECT _koi8r'\xD4\xC5\xD3\xD4'", "SELECT _koi8r'тест'" ),
			'latin1 in a UTF-8 line'       => array( 'UTF-8', "SELECT _latin1'é'", "SELECT _latin1'Ã©'" ),
			'cp1250 in a UTF-8 line'       => array( 'UTF-8', "SELECT _cp1250'\xE8'", "SELECT _cp1250'č'" ),
			'whitespace is kept'           => array( 'UTF-8', "SELECT _koi8r  \t'\xD4'", "SELECT _koi8r  \t'т'" ),
			'uppercase introducer'         => array( 'UTF-8', "SELECT _KOI8R'\xD4'", "SELECT _KOI8R'т'" ),
			'double quotes'                => array( 'UTF-8', "SELECT _koi8r\"\xD4\"", 'SELECT _koi8r"т"' ),
			'rest of the line'             => array( 'KOI8-R', "SELECT '\xD4', _utf8mb3'т', '\xD4'", "SELECT 'т', _utf8mb3'т', 'т'" ),
			'several introducers'          => array( 'UTF-8', "SELECT _koi8r'\xD4', _latin1'\xE9', 'é'", "SELECT _koi8r'т', _latin1'é', 'é'" ),
			'escaped quote'                => array( 'UTF-8', "SELECT _koi8r'\xD4\\'\xD4', 'é'", "SELECT _koi8r'т\\'т', 'é'" ),
			'doubled quote'                => array( 'UTF-8', "SELECT _koi8r'\xD4''\xD4', 'é'", "SELECT _koi8r'т''т', 'é'" ),
			'escaped backslash at the end' => array( 'UTF-8', "SELECT _koi8r'\xD4\\\\', 'é'", "SELECT _koi8r'т\\\\', 'é'" ),

			// Bytes that aren't valid in the character set use the encodings of the line.
			'invalid in its character set' => array( 'KOI8-R', "SELECT _utf8mb3'\xD4'", "SELECT _utf8mb3'т'" ),

			// These aren't literals with an introducer that this file can read.
			'unsupported introducer'       => array( 'KOI8-R', "SELECT _ucs2'\xD4'", "SELECT _ucs2'т'" ),
			'end of a column name'         => array( 'UTF-8', "SELECT a_koi8r'\xD4'", "SELECT a_koi8r'Ô'" ),
			'hexadecimal literal'          => array( 'UTF-8', "SELECT _koi8r x'D4', '\xD4'", "SELECT _koi8r x'D4', 'Ô'" ),
			'unterminated literal'         => array( 'UTF-8', "SELECT _koi8r'\xD4", "SELECT _koi8r'Ô" ),
		);
	}

	/**
	 * @dataProvider data_client_charset_statements
	 */
	public function test_read_client_charset( string $line, ?string $expected ): void {
		$this->assertSame( $expected, read_client_charset( $line ) );
	}

	/**
	 * @return array<string,array{0:string,1:?string}>
	 */
	public function data_client_charset_statements(): array {
		return array(
			'SET NAMES'                     => array( 'SET NAMES latin1;', 'latin1' ),
			'lowercase and spaces'          => array( '  set   names   KOI8R ;', 'koi8r' ),
			'single quotes'                 => array( "SET NAMES 'latin1';", 'latin1' ),
			'double quotes'                 => array( 'SET NAMES "latin1";', 'latin1' ),
			'custom delimiter'              => array( 'set names koi8r|', 'koi8r' ),
			'long custom delimiter'         => array( 'set names koi8r//', 'koi8r' ),
			'collation'                     => array( 'SET NAMES utf8mb3 COLLATE utf8mb3_bin;', 'utf8mb3' ),
			'another variable'              => array( 'SET NAMES utf8mb3, @@character_set_results=NULL;', 'utf8mb3' ),
			'default'                       => array( 'SET NAMES DEFAULT;', 'default' ),
			'SET CHARACTER SET'             => array( 'SET CHARACTER SET koi8r;', 'koi8r' ),
			'SET CHARSET'                   => array( 'SET CHARSET koi8r;', 'koi8r' ),
			'old character set name'        => array( 'SET CHARACTER SET cp1250_latin2;', 'cp1250_latin2' ),
			'character_set_client'          => array( 'SET character_set_client = latin1;', 'latin1' ),
			'character_set_client no space' => array( 'SET character_set_client=latin1;', 'latin1' ),
			'quoted character_set_client'   => array( "SET character_set_client= 'latin1';", 'latin1' ),
			'SESSION'                       => array( 'SET SESSION character_set_client=latin1;', 'latin1' ),
			'LOCAL'                         => array( 'SET LOCAL character_set_client=latin1;', 'latin1' ),
			'system variable'               => array( 'SET @@character_set_client= cp1251;', 'cp1251' ),
			'system variable with SESSION'  => array( 'SET @@session.character_set_client=cp1251;', 'cp1251' ),
			'system variable with LOCAL'    => array( 'SET @@local.character_set_client=cp1251;', 'cp1251' ),
			'user variable'                 => array( 'SET character_set_client = @old_cs_client;', '@old_cs_client' ),

			'not character_set_client'      => array( 'SET character_set_results = koi8r;', null ),
			'not a character set'           => array( "SET names='foo2';", null ),
			'user variable named names'     => array( 'SET @names = 1;', null ),
			'comment'                       => array( '# SET NAMES latin1;', null ),
			'mysqltest command'             => array( '--character_set latin1', null ),
			'text in another statement'     => array( "SELECT 'SET NAMES latin1';", null ),
		);
	}

	/**
	 * MySQL's latin1 keeps the bytes that Windows-1252 leaves undefined as C1 controls.
	 * On PHP below 8.1, mbstring replaces them with U+FFFD or rejects them as invalid.
	 *
	 * @dataProvider data_charsets_with_undefined_latin1_bytes
	 */
	public function test_undefined_latin1_bytes_are_c1_controls( string $charset ): void {
		$this->assertSame(
			"SELECT '\u{81}\u{8D}\u{8F}\u{90}\u{9D}€'",
			convert_to_utf8( "SELECT '\x81\x8D\x8F\x90\x9D\x80'", array( QUERY_CORPUS_CHARSET_ENCODINGS[ $charset ] ) )
		);
	}

	/**
	 * @return array<string,array{0:string}>
	 */
	public function data_charsets_with_undefined_latin1_bytes(): array {
		return array(
			'declared latin1'       => array( 'latin1' ),
			'fallback from utf8mb4' => array( 'utf8mb4' ),
			'fallback from tis620'  => array( 'tis620' ),
		);
	}

	/**
	 * @return array<string,array{0:string,1:string,2:string}>
	 */
	public function data_lines_in_character_sets(): array {
		return array(
			'ASCII is unchanged'               => array( 'koi8r', "SELECT 'a\\'b'", "SELECT 'a\\'b'" ),
			'big5'                             => array( 'big5', "SELECT '\xA4\x40'", "SELECT '一'" ),
			'binary as UTF-8'                  => array( 'binary', "SELECT 'é'", "SELECT 'é'" ),
			'binary that is not UTF-8'         => array( 'binary', "SELECT '\xE9'", "SELECT 'é'" ),
			'cp1250'                           => array( 'cp1250', "SELECT '\xE1\xE1\xE8\xE8'", "SELECT 'ááčč'" ),
			'cp1250_latin2'                    => array( 'cp1250_latin2', "SELECT '\xA3\xA5\xAA\xAF'", "SELECT 'ŁĄŞŻ'" ),
			'cp1251'                           => array( 'cp1251', "SELECT '\xF2\xE5\xF1\xF2'", "SELECT 'тест'" ),
			'gb18030'                          => array( 'gb18030', "SELECT '\xC4\xE3'", "SELECT '你'" ),
			'gbk'                              => array( 'gbk', "SELECT '\xC4\xE3'", "SELECT '你'" ),
			'koi8r'                            => array( 'koi8r', "SELECT '\xD4\xC5\xD3\xD4'", "SELECT 'тест'" ),
			'latin1'                           => array( 'latin1', "SELECT '\x80\xE9'", "SELECT '€é'" ),
			'latin2'                           => array( 'latin2', "SELECT '\xB1'", "SELECT 'ą'" ),
			'ujis'                             => array( 'ujis', "SELECT '\xA4\xA2'", "SELECT 'あ'" ),
			'utf8'                             => array( 'utf8', "SELECT 'é'", "SELECT 'é'" ),
			'utf8mb3'                          => array( 'utf8mb3', "SELECT 'é'", "SELECT 'é'" ),
			'utf8mb4'                          => array( 'utf8mb4', "SELECT '🐬'", "SELECT '🐬'" ),

			// The second byte of this character is a backslash in ASCII.
			'sjis with a backslash byte'       => array( 'sjis', "SELECT '\x83\x5C'", "SELECT 'ソ'" ),

			// A line that isn't valid in its character set is read as latin1.
			'invalid utf8mb4'                  => array( 'utf8mb4', "SELECT '\xE9'", "SELECT 'é'" ),
			'invalid sjis'                     => array( 'sjis', "SELECT '\xFD'", "SELECT 'ý'" ),

			// TIS-620 maps to the Unicode Thai block, with four unassigned bytes.
			'tis620'                           => array( 'tis620', "SELECT '\xBE\xB9\xD4\xB4\xD2'", "SELECT 'พนิดา'" ),
			'first and last tis620 byte'       => array( 'tis620', "SELECT '\xA1\xFB'", "SELECT 'ก๛'" ),
			'unassigned tis620 byte'           => array( 'tis620', "SELECT '\xA1\xDB'", "SELECT '¡Û'" ),
			'tis620 byte below the Thai block' => array( 'tis620', "SELECT '\xA0\xA1'", "SELECT '\u{A0}¡'" ),
			'tis620 byte above the Thai block' => array( 'tis620', "SELECT '\xFC\xA1'", "SELECT 'ü¡'" ),

			// MySQL's cp1250 leaves five bytes undefined, so these lines are read as latin1.
			'undefined cp1250 bytes'           => array( 'cp1250', "SELECT '\xE8\x81\x83\x88\x90\x98'", "SELECT 'è\u{81}ƒˆ\u{90}˜'" ),
		);
	}
}
