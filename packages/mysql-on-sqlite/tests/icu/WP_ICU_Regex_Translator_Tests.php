<?php

use PHPUnit\Framework\TestCase;

/**
 * data_matches() uses MySQL 8.4.13 (ICU 77.1) REGEXP_REPLACE() results, with each
 * match and its captures marked as "<$0|$1|...>". data_first_match() uses ICU 79.1.
 */
class WP_ICU_Regex_Translator_Tests extends TestCase {
	/**
	 * @dataProvider data_matches
	 */
	public function test_matches( string $pattern, string $flags, string $subject, string $expected ): void {
		$this->assertSame( $expected, $this->replace_matches( WP_ICU_Regex_Translator::translate( $pattern, $flags ), $subject ) );
	}

	/**
	 * Each row gives the pattern, flags, subject, and subject with matches marked.
	 */
	public function data_matches(): array {
		return array(
			// Literals and escapes.
			array( 'abc', '', 'xabcxabc', 'x<abc>x<abc>' ),
			array( 'a/b', '', 'a/b', '<a/b>' ),
			array( 'a b', '', 'a b', '<a b>' ),
			array( 'a#b', '', 'a#b', '<a#b>' ),
			array( 'é', '', 'éa', '<é>a' ),
			array( '😀', '', 'a😀', 'a<😀>' ),
			array( ']', '', 'a]', 'a<]>' ),
			array( '\\_', '', '_', '<_>' ),
			array( '\\-', '', '-', '<->' ),
			array( '\\é', '', 'é', '<é>' ),
			array( '\\a', '', "\x07", "<\x07>" ),
			array( '\\e', '', "\e", "<\e>" ),
			array( '\\f', '', "\f", "<\f>" ),
			array( '\\n', '', "\n", "<\n>" ),
			array( '\\r', '', "\r", "<\r>" ),
			array( '\\t', '', "\t", "<\t>" ),
			array( '\\cA', '', "\x01", "<\x01>" ),
			array( '\\c1', '', "\x11", "<\x11>" ),
			array( '\\x41', '', 'A', '<A>' ),
			array( '\\x4', '', "\x04", "<\x04>" ),
			array( '\\x{41}', '', 'A', '<A>' ),
			array( '\\x{1F600}', '', '😀', '<😀>' ),
			array( '\\u0041', '', 'A', '<A>' ),
			array( '\\u00e9', '', 'é', '<é>' ),
			array( '\\U0001F600', '', '😀', '<😀>' ),
			array( '\\0101', '', 'A', '<A>' ),
			array( '\\07', '', "\x07", "<\x07>" ),
			array( '\\0377', '', 'ÿ', '<ÿ>' ),
			array( '\\0400', '', ' 0', '< 0>' ),
			array( '\\x41*', '', 'AAb', '<AA><>b<>' ),
			array( '\\Qa.b\\E', '', 'a.b', '<a.b>' ),
			array( '\\Qa.b', '', 'a.b', '<a.b>' ),
			array( '\\Qab\\E*', '', 'abb', '<abb>' ),
			array( '\\Q(\\E', '', '(', '<(>' ),
			array( '\\.', '', 'a.', 'a<.>' ),
			array( '\\*', '', '*', '<*>' ),
			array( '\\\\', '', '\\', '<\\>' ),
			array( '\\/', '', '/', '</>' ),
			// Quantifiers.
			array( 'a*', '', 'aab', '<aa><>b<>' ),
			array( 'a+', '', 'aab', '<aa>b' ),
			array( 'a?', '', 'aab', '<a><a><>b<>' ),
			array( 'a*?', '', 'aab', '<>a<>a<>b<>' ),
			array( 'a+?', '', 'aab', '<a><a>b' ),
			array( 'a??', '', 'aab', '<>a<>a<>b<>' ),
			array( 'a*+a', '', 'aaa', 'aaa' ),
			array( 'a++a', '', 'aaa', 'aaa' ),
			array( 'a?+a', '', 'aa', '<aa>' ),
			array( 'a{2}', '', 'aaa', '<aa>a' ),
			array( 'a{1,2}', '', 'aaa', '<aa><a>' ),
			array( 'a{2,}', '', 'aaa', '<aaa>' ),
			array( 'a{1,2}?', '', 'aaa', '<a><a><a>' ),
			array( 'a{1,2}+a', '', 'aaa', '<aaa>' ),
			array( 'a{0}b', '', 'ab', 'a<b>' ),
			array( 'a{007}', '', 'aaaaaaa', '<aaaaaaa>' ),
			array( 'é*', '', 'éé', '<éé><>' ),
			array( 'ab*', '', 'abb', '<abb>' ),
			array( ']*', '', ']]', '<]]><>' ),
			array( '\\P{Lu}+\\D', '', 'xa', '<xa>' ),
			array( '\\P{Lu}*\\D', '', 'xa', '<xa>' ),
			array( '\\P{Lu}+?\\P{Ll}', '', 'xA', '<xA>' ),
			array( '\\P{Nd}+\\P{Lu}', '', 'xa', '<xa>' ),
			array( '\\D+\\P{Lu}', '', 'xa', '<xa>' ),
			array( '\\D*\\P{Ll}', '', 'AB', '<AB>' ),
			array( '[:^Lu:]+\\D', '', 'xa', '<xa>' ),
			// Groups.
			array( '(a)(b)', '', 'ab', '<ab|a|b>' ),
			array( '(?:a)(b)', '', 'ab', '<ab|b>' ),
			array( '(?>a|ab)c', '', 'abc', 'abc' ),
			array( '(a)|(b)', '', 'ab', '<a|a|><b||b>' ),
			array( '(?<n>a)', '', 'a', '<a|a>' ),
			array( '(?<n1>a)(?<n2>b)', '', 'ab', '<ab|a|b>' ),
			array( 'a(?=b)', '', 'abac', '<a>bac' ),
			array( 'a(?!b)', '', 'abac', 'ab<a>c' ),
			array( '(?<=a)b', '', 'abcb', 'a<b>cb' ),
			array( '(?<!a)b', '', 'abcb', 'abc<b>' ),
			array( '(?<=a|bc)d', '', 'bcdad', 'bc<d>a<d>' ),
			array( '(?#comment)a', '', 'a', '<a>' ),
			array( 'a(?#comment)b', '', 'ab', '<ab>' ),
			array( '()', '', 'a', '<|>a<|>' ),
			array( '(|a)', '', 'a', '<|>a<|>' ),
			array( '(a*)*', '', 'aa', '<aa|><|>' ),
			array( '(?:a|b)+', '', 'abc', '<ab>c' ),
			// Backreferences.
			array( '(a)\\1', '', 'aa', '<aa|a>' ),
			array( '(a)(b)\\2', '', 'abb', '<abb|a|b>' ),
			array( '(a)(b)\\1\\2', '', 'abab', '<abab|a|b>' ),
			array( '\\1(a)', '', 'aa', 'aa' ),
			array( '(?<n>a)\\k<n>', '', 'aa', '<aa|a>' ),
			array( '(a)\\1', 'i', 'aA', '<aA|a>' ),
			array( '(a)|b\\1', '', 'b', 'b' ),
			// Flags.
			array( 'a', 'i', 'A', '<A>' ),
			array( 'a(?i)b', '', 'aB', '<aB>' ),
			array( 'a(?i)b', '', 'AB', 'AB' ),
			array( '(?i)a(?-i)b', '', 'AB', 'AB' ),
			array( '(?i)a(?-i)b', '', 'Ab', '<Ab>' ),
			array( '(a(?i)b)c', '', 'aBc', '<aBc|aB>' ),
			array( '(a(?i)b)c', '', 'aBC', 'aBC' ),
			array( '(?i:a)b', '', 'Ab', '<Ab>' ),
			array( '(?i:a)b', '', 'AB', 'AB' ),
			array( '(?-i:a)b', 'i', 'aB', '<aB>' ),
			array( '(?-i:a)b', 'i', 'AB', 'AB' ),
			array( 'a(?i)b|c', '', 'C', '<C>' ),
			array( '(?u)a', '', 'a', '<a>' ),
			array( '(?-)a', '', 'a', '<a>' ),
			array( '(?im-s:a.)', '', "A\n", "A\n" ),
			array( '(?s)a.', '', "a\n", "<a\n>" ),
			array( '(?s:a.)a.', '', "a\na\n", "a\na\n" ),
			array( '(?m)a$', '', "a\nb", "<a>\nb" ),
			array( '(?d)a$', '', "a\r", "a\r" ),
			array( '(?d).', '', "\r", "<\r>" ),
			array( '(?d-d).', '', "\r", "\r" ),
			// Sets.
			array( '[abc]', '', 'xabc', 'x<a><b><c>' ),
			array( '[^abc]', '', 'xabc', '<x>abc' ),
			array( '[a-c]', '', 'xabc', 'x<a><b><c>' ),
			array( '[é-ë]', '', 'êz', '<ê>z' ),
			array( '[😀-😂]', '', '😁', '<😁>' ),
			array( '[]a]', '', ']a', '<]><a>' ),
			array( '[^]a]', '', ']ab', ']a<b>' ),
			array( '[-a]', '', '-a', '<-><a>' ),
			array( '[a-]', '', '-a', '<-><a>' ),
			array( '[a-c-e]', '', 'be-d', '<b><e><->d' ),
			array( '[\\w-z]', '', '-z.', '<-><z>.' ),
			array( '[\\d-a]', '', '1-ab', '<1><-><a>b' ),
			array( '[\\p{Lu}-z]', '', 'A-z', '<A><-><z>' ),
			array( '[[:digit:]-z]', '', '1-z', '<1><-><z>' ),
			array( '[\\n]', '', "\nn", "<\n>n" ),
			array( '[\\x41-\\x43]', '', 'ABCD', '<A><B><C>D' ),
			array( '[a-\\x63]', '', 'abcd', '<a><b><c>d' ),
			array( '[\\u00e9]', '', 'é', '<é>' ),
			array( '[\\Qa-c\\E]', '', 'a-cb', '<a><-><c>b' ),
			array( '[\\Q]\\E]', '', ']', '<]>' ),
			array( '[.*+?(){}|^$]', '', '.*+?(){}|^$', '<.><*><+><?><(><)><{><}><|><^><$>' ),
			array( '[\\^a]', '', '^a', '<^><a>' ),
			array( '[a^]', '', '^a', '<^><a>' ),
			array( '[/]', '', '/', '</>' ),
			array( '[a]*', '', 'aa', '<aa><>' ),
			array( '[a&b]', '', 'a&bc', '<a><&><b>c' ),
			array( '[&a]', '', '&a', '<&><a>' ),
			array( '[a&]', '', '&a', '<&><a>' ),
			array( '[\\s\\S]', '', "a\n", "<a><\n>" ),
			array( '[\\P{L}]', '', 'a1', 'a<1>' ),
			array( '[^\\P{L}]', '', 'a1', '<a>1' ),
			array( '[\\h]', '', "a \t", "a< ><\t>" ),
			array( '[\\H]', '', 'a ', '<a> ' ),
			array( '[\\v]', '', "a\n", "a<\n>" ),
			array( '[\\V]', '', "a\n", "<a>\n" ),
			array( '(?i)[^a]', '', 'aAb', 'aA<b>' ),
			array( '(?i)[ß]', '', 'ßs', '<ß>s' ),
			// POSIX classes.
			array( '[[:alpha:]]', '', 'a1', '<a>1' ),
			array( '[:alpha:]', '', 'a1', '<a>1' ),
			array( '[:^alpha:]', '', 'a1', 'a<1>' ),
			array( '[:alpha:]+', '', 'ab1', '<ab>1' ),
			array( '[^:alpha:]', '', 'a1', 'a<1>' ),
			array( '[:abc]', '', 'a:d', '<a><:>d' ),
			array( '[[:alnum:]]', '', 'a1-', '<a><1>-' ),
			array( '[[:blank:]]', '', "a \t\n", "a< ><\t>\n" ),
			array( '[[:cntrl:]]', '', "a\x01", "a<\x01>" ),
			array( '[[:digit:]]', '', 'a1', 'a<1>' ),
			array( '[[:graph:]]', '', 'a ', '<a> ' ),
			array( '[[:lower:]]', '', 'aA', '<a>A' ),
			array( '[[:print:]]', '', "a\x01", "<a>\x01" ),
			array( '[[:punct:]]', '', 'a.+', 'a<.>+' ),
			array( '[[:space:]]', '', "a \n", "a< ><\n>" ),
			array( '[[:upper:]]', '', 'aA', 'a<A>' ),
			array( '[[:upper:]]', 'i', 'aA1', '<a><A>1' ),
			array( '[[:lower:]]', 'i', 'aA1', '<a><A>1' ),
			array( '[[:word:]]', '', 'a_-', '<a><_>-' ),
			array( '[[:xdigit:]]', '', 'afg', '<a><f>g' ),
			array( '[[:Lu:]]', '', 'aA', 'a<A>' ),
			array( '[[:alpha:][:digit:]]', '', 'a1-', '<a><1>-' ),
			array( '[[:alpha:]-z]', '', 'a-1', '<a><->1' ),
			// Properties.
			array( '\\p{L}', '', 'a1', '<a>1' ),
			array( '\\P{L}', '', 'a1', 'a<1>' ),
			array( '\\p{Lu}', '', 'aA', 'a<A>' ),
			array( '\\p{lu}', '', 'aA', 'a<A>' ),
			array( '\\p{ASCII}', '', 'aé', '<a>é' ),
			array( '\\p{word}', '', 'a-', '<a>-' ),
			array( '\\p{Greek}', '', 'aα', 'a<α>' ),
			array( '\\P{Greek}', '', 'aα', '<a>α' ),
			array( '\\p{Han}', '', '日。', '<日>。' ),
			array( '\\p{Old_Italic}', '', '𐌀a', '<𐌀>a' ),
			array( '\\p{Lu}', 'i', 'aA1', '<a><A>1' ),
			array( '\\P{Lu}', 'i', 'aA1', 'aA<1>' ),
			array( '\\p{Ll}', 'i', 'aA1', '<a><A>1' ),
			array( '(?i)\\p{Lu}', '', 'aA1', '<a><A>1' ),
			array( '(?i:\\p{Lu})\\p{Lu}', '', 'aa', 'aa' ),
			array( '(?i:\\p{Lu})\\p{Lu}', '', 'aA', '<aA>' ),
			array( '(?-i)\\p{Lu}', 'i', 'aA', 'a<A>' ),
			array( '\\p{Nd}', '', 'a٣', 'a<٣>' ),
			array( '\\p{Zs}', '', "a\u{A0}", "a<\u{A0}>" ),
			// Shorthand classes.
			array( '\\d', '', 'a1٣', 'a<1><٣>' ),
			array( '\\D', '', 'a1', '<a>1' ),
			array( '\\w', '', 'a1_-', '<a><1><_>-' ),
			array( '\\w', '', "e\u{301}", "<e><\u{301}>" ),
			array( '\\w', '', "\u{200D}", "<\u{200D}>" ),
			array( '\\w', '', 'Ⅷ', '<Ⅷ>' ),
			array( '\\w', '', 'Ⓐ', '<Ⓐ>' ),
			array( '\\w', '', '²½', '²½' ),
			array( '\\W', '', 'a-', 'a<->' ),
			array( '\\s', '', "a \t\n\u{A0}\u{2003}\u{85}", "a< ><\t><\n><\u{A0}><\u{2003}><\u{85}>" ),
			array( '\\v', '', "a\n\v\f\r\u{85}\u{2028}\u{2029}", "a<\n><\v><\f><\r><\u{85}><\u{2028}><\u{2029}>" ),
			array( '\\V', '', "a\n", "<a>\n" ),
			array( '\\R', '', "a\r\n\n\r\u{2028}", "a<\r\n><\n><\r><\u{2028}>" ),
			array( '\\R*', '', "\r\n\r\n", "<\r\n\r\n><>" ),
			array( '\\X', '', "e\u{301}a", "<e\u{301}><a>" ),
			// Line terminators.
			array( '^a', '', "b\na", "b\na" ),
			array( '^a', 'm', "b\na", "b\n<a>" ),
			array( '^', 'm', "a\nb\n", "<>a\n<>b\n" ),
			array( '^', 'm', "a\r\nb\r\n", "<>a\r\n<>b\r\n" ),
			array( '^', 'm', "\n\n", "<>\n<>\n" ),
			array( '^', 'md', "a\nb\n", "<>a\n<>b\n<>" ),
			array( '^', 'md', "a\rb", "<>a\rb" ),
			array( '(?m)^', '', "a\r\nb", "<>a\r\n<>b" ),
			array( '^a|^b', 'm', "a\r\nb", "<a>\r\n<b>" ),
			array( '^\\s*', 'm', "a\r\n b", "<>a\r\n< >b" ),
			array( 'a$', '', "a\n", "<a>\n" ),
			array( 'a$', '', "a\r", "<a>\r" ),
			array( 'a$', '', "a\r\n", "<a>\r\n" ),
			array( 'a$', '', "a\n\n", "a\n\n" ),
			array( 'a$', '', "a\n\r", "a\n\r" ),
			array( 'a$', '', "a\u{85}", "<a>\u{85}" ),
			array( 'a$', '', "a\u{2028}", "<a>\u{2028}" ),
			array( 'a$', '', "a\v", "<a>\v" ),
			array( '$', '', "a\n", "a<>\n<>" ),
			array( '$', '', "a\r\n", "a<>\r\n<>" ),
			array( '$', 'm', "a\nb\n", "a<>\nb<>\n<>" ),
			array( '$', 'm', "a\r\nb\r\n", "a<>\r\nb<>\r\n<>" ),
			array( '$', 'd', "a\n", "a<>\n<>" ),
			array( '$', 'd', "a\r\n", "a\r<>\n<>" ),
			array( '$', 'md', "a\nb\r\n", "a<>\nb\r<>\n<>" ),
			array( 'a\\Z', '', "a\n", "<a>\n" ),
			array( 'a\\Z', '', "a\r\n", "<a>\r\n" ),
			array( 'a\\Z', 'd', "a\r", "<a>\r" ),
			array( 'a\\z', '', "a\n", "a\n" ),
			array( 'a\\z', '', 'a', '<a>' ),
			array( '\\Aa', '', 'aa', '<a>a' ),
			array( '\\Aa', 'm', "b\na", "b\na" ),
			array( '.', '', "a\nb\r\u{85}\u{2028}\u{2029}\v\fc", "<a>\n<b>\r\u{85}\u{2028}\u{2029}\v\f<c>" ),
			array( '.', 'd', "a\nb\r\u{85}\u{2028}c", "<a>\n<b><\r><\u{85}><\u{2028}><c>" ),
			array( '.*', 's', "a\r\nb", "<a\r\nb><>" ),
			array( '.+', 's', "a\r\nb", "<a\r\nb>" ),
			array( '.*?', 's', "a\r\n", "<>a<>\r<>\n<>" ),
			array( '(?<=.)b', 's', "\r\nb", "\r\n<b>" ),
			array( '^.$', '', "\r\n", "\r\n" ),
			// Word boundaries.
			array( '\\bfoo\\b', '', 'foo foobar barfoo foo', '<foo> foobar barfoo <foo>' ),
			array( '\\b', '', 'foo bar', '<>foo<> <>bar<>' ),
			array( '\\B', '', 'foo bar', 'f<>o<>o b<>a<>r' ),
			array( '\\b', '', 'é a', '<>é<> <>a<>' ),
			array( '\\b', '', '日本語 x', '<>日本語<> <>x<>' ),
			array( '\\b', '', '_a', '<>_a<>' ),
			array( '\\b', '', 'a1', '<>a1<>' ),
			array( '\\b', '', "e\u{301}x y", "<>e\u{301}x<> <>y<>" ),
			array( '\\b', '', "e\u{301}\u{302} y", "<>e\u{301}\u{302}<> <>y<>" ),
			array( '\\b', '', "\u{FEFF}a", "\u{FEFF}<>a<>" ),
			array( '\\b', '', '²a', '²<>a<>' ),
			// Case folding.
			array( 'K', 'i', "k\u{212A}", "<k><\u{212A}>" ),
			array( "\u{212A}", 'i', 'kK', '<k><K>' ),
			array( 's', 'i', 'Sſ', '<S><ſ>' ),
			array( 'σ', 'i', 'Σς', '<Σ><ς>' ),
			array( 'ς', 'i', 'Σσ', '<Σ><σ>' ),
			array( 'é', 'i', 'É', '<É>' ),
			array( 'ı', 'i', 'iI', 'iI' ),
			array( 'i', 'i', 'ıİ', 'ıİ' ),
			array( 'ß', '', 'SS', 'SS' ),
			array( 'ss', '', 'ß', 'ß' ),
			array( 'ss*', 'i', 'ß', 'ß' ),
			array( 'ss+', 'i', 'ß', 'ß' ),
			array( 'ss?', 'i', 'ß', 'ß' ),
			array( 'ss{1}', 'i', 'ß', 'ß' ),
			array( 's', 'i', 'ß', 'ß' ),
			array( '[s]', 'i', 'ß', 'ß' ),
			array( '(?i)ss(?-i)ss', '', 'ßß', 'ßß' ),
		);
	}

	/**
	 * @dataProvider data_first_match
	 */
	public function test_first_match( string $pattern, string $subject, ?string $expected ): void {
		$found = preg_match( WP_ICU_Regex_Translator::translate( $pattern ), $subject, $match );
		$this->assertSame( $expected, 1 === $found ? $match[0] : null );
	}

	public function data_first_match(): array {
		return array(
			array( 'abc+', 'abccc', 'abccc' ),
			array( 'a|bc', 'bc', 'bc' ),
			array( '', 'abc', '' ),
			array( 'a||b', 'c', '' ),
			array( '(ab)+', 'abab', 'abab' ),
			array( '(?:ab){2,3}', 'ababab', 'ababab' ),
			array( '(?>a|ab)c', 'abc', null ),
			array( 'a++a', 'aaa', null ),
			array( 'a*?b', 'aaab', 'aaab' ),
			array( 'a{2}?', 'aaa', 'aa' ),
			array( 'a{' . str_repeat( '0', 4096 ) . '2}', 'aaa', 'aa' ),
			array( 'a?+a', 'a', null ),
			array( 'a(?=b)', 'ab', 'a' ),
			array( 'a(?!b)', 'ac', 'a' ),
			array( '(?<=ab{2})c', 'abbc', 'c' ),
			array( '(?<!ab)c', 'aac', 'c' ),
			array( '(?<word>a+)b\k<word>', 'aaabaaa', 'aaabaaa' ),
			array( '(a)(b)\2\1', 'abba', 'abba' ),
			array( '\1(a)', 'aa', null ),
			array( '(a)?b', 'b', 'b' ),
			array( '(?:(a)|(b))+', 'ab', 'ab' ),
			array( '(a|(b))*', 'ba', 'ba' ),
			array( '(?u)a', 'a', 'a' ),
			array( '(?-i:a)', 'a', 'a' ),
			array( '\Q.a[~]\E+', '.a[~]]', '.a[~]]' ),
			array( '\Qabc', 'abc', 'abc' ),
			array( 'a*\Q?\E', 'aaa?', 'aaa?' ),
			array( 'a*\Q+\E', 'aaa+', 'aaa+' ),
			array( '(\Q)\E)', ')', ')' ),
			array( '\Q\Q\E', '\Q', '\Q' ),
			array( '\Qquoted\E', 'quoted', 'quoted' ),
			array( '\Qé😀\E+', 'é😀😀', 'é😀😀' ),
			array( '[\Q]-&\E]+', ']-&', ']-&' ),
			array( '(?d).', "\r", "\r" ),
			array( '(?s).|.', "\n", "\n" ),
			array( 'a(?s).|.', "\n", "\n" ),
			array( '(?s:a.)|.', "\n", null ),
			array( 'a(?m)$', "a\nb", 'a' ),
			array( '(?m)^$', '', '' ),
			array( 'a$', "a\r\n", 'a' ),
			array( 'a\Z', "a\r\n", 'a' ),
			array( '\n(?m)^', "\n", null ),
			array( '\r(?m)$\n', "\r\n", null ),
			array( '\R+', "\r\n\u{2028}", "\r\n\u{2028}" ),
			array( '\R\n', "\r\n", null ),
			array( '\h+', "\t\u{a0}", "\t\u{a0}" ),
			array( '\s', "\v", "\v" ),
			array( '\s', "\u{85}", "\u{85}" ),
			array( '\w+', "é\u{301}\u{2160}\u{200c}", "é\u{301}\u{2160}\u{200c}" ),
			array( '\d+', '١٢', '١٢' ),
			array( '\D+', 'é!', 'é!' ),
			array( '[\D]+', 'é!', 'é!' ),
			array( '[a\D]+', '1a!', 'a!' ),
			array( '\u0061\U0001F600\x{62}\x63', 'a😀bc', 'a😀bc' ),
			array( '\xA', "\n", "\n" ),
			array( '\x414\u0061b\U00000063d', 'A4abcd', 'A4abcd' ),
			array( '\x{000061}\x{10FFFF}', "a\u{10ffff}", "a\u{10ffff}" ),
			array( '[\u0061-\x{63}]+', 'abc', 'abc' ),
			array( '\0777', '?7', '?7' ),
			array( '\00', "\0", "\0" ),
			array( "a\0b", "a\0b", "a\0b" ),
			array( '\a\e\f\n\r\t\cA', "\x07\x1b\f\n\r\t\x01", "\x07\x1b\f\n\r\t\x01" ),
			array( 'a~b\\\\c', 'a~b\\c', 'a~b\\c' ),
			array( ']', ']', ']' ),
			array( 'a]', 'a]', 'a]' ),
			array( '[def]]', 'abcdef', null ),
			array( '[def]]', 'abcdef]]', 'f]' ),
			array( '2(]*)?$\1', '2', '2' ),
			array( '[a-z]+', 'foo', 'foo' ),
			array( '[😀-🙏]+', '😀😁', '😀😁' ),
			array( '[]a]+', ']a', ']a' ),
			array( '[^]a]+', 'bc', 'bc' ),
			array( '[-a&]+', '-a&', '-a&' ),
			array( '(?dm)\n^', "\n", "\n" ),
			array( '(?<=abc{2})d', 'abccd', 'd' ),
			array( '(?<=(?:a|b){2})c', 'abc', 'c' ),
			array( '\A[a\D]+\z', str_repeat( 'a', 64 ) . '1', null ),
			array( '[abcdefghijk-z]+', 'ijxyz', 'ijxyz' ),
			array( '[:digit:]+', '١٢', '١٢' ),
			array( '[[:^digit:]]+', 'ab', 'ab' ),
		);
	}

	/**
	 * Syntax that MySQL 5.7 and MariaDB accept, and MySQL 8 rejects.
	 *
	 * @dataProvider data_legacy_syntax
	 */
	public function test_legacy_syntax( string $pattern, string $subject, string $expected ): void {
		$this->assertSame( $expected, $this->replace_matches( WP_ICU_Regex_Translator::translate( $pattern ), $subject ) );
	}

	public function data_legacy_syntax(): array {
		return array(
			array( '[[:<:]]word[[:>:]]', 'a word, words, sword', 'a <word>, words, sword' ),
			array( '[[:<:]]', 'ab cd', '<>ab <>cd' ),
			array( '[[:>:]]', 'ab cd', 'ab<> cd<>' ),
			array( '[[:<:]]a|b[[:>:]]', 'ab ba', '<a><b> ba' ),
			array( 'a:[0-9]+:{', 'a:12:{i:0;}', '<a:12:{>i:0;}' ),
			array( '{"id":[0-9]+}', '{"id":42}', '<{"id":42}>' ),
			array( 'x{2}}', 'xx}', '<xx}>' ),
			array( 'a{,3}', 'a{,3} aaa', '<a{,3}> aaa' ),
			array( 'a{x}', 'a{x}', '<a{x}>' ),
			array( '{', '{}', '<{>}' ),
			array( '}', '{}', '{<}>' ),
		);
	}

	/**
	 * @dataProvider data_known_differences
	 */
	public function test_known_differences( string $pattern, string $flags, string $subject, string $mysql, string $expected ): void {
		$actual = $this->replace_matches( WP_ICU_Regex_Translator::translate( $pattern, $flags ), $subject );
		$this->assertSame( $expected, $actual );
		$this->assertNotSame( $mysql, $actual );
	}

	/**
	 * Each row gives the pattern, flags, subject, MySQL result, and expected translation result.
	 */
	public function data_known_differences(): array {
		return array(
			array( '\\p{Lt}', 'i', 'ǅǆa', '<ǅ><ǆ>a', '<ǅ><ǆ><a>' ),
			array( '\\s', '', "\u{180E}", "\u{180E}", "<\u{180E}>" ),
			array( '\\S', '', "a \u{180E}", "<a> <\u{180E}>", "<a> \u{180E}" ),
			array( '\\h', '', "a \t\n\u{A0}\u{180E}", "a< ><\t>\n<\u{A0}>\u{180E}", "a< ><\t>\n<\u{A0}><\u{180E}>" ),
			array( '\\H', '', "a \u{180E}", "<a> <\u{180E}>", "<a> \u{180E}" ),
			array( '^|x', 'm', "a\r\nb", "<>a\r<>\n<>b", "<>a\r\n<>b" ),
			array( '.', 's', "a\r\nb", "<a><\r\n><b>", "<a><\r><\n><b>" ),
			array( '.', 'sd', "a\r\nb", "<a><\r\n><b>", "<a><\r><\n><b>" ),
			array( '.*\\n', 's', "a\r\n", "a\r<\n>", "<a\r\n>" ),
			array( '.+\\n', 's', "\r\n\r\n", "\r\n\r\n", "<\r\n\r\n>" ),
			array( '.?', 's', "\r\n", "<\r\n><>", "<\r><\n><>" ),
			array( '.{2}', 's', "\r\na", "<\r\na>", "<\r\n>a" ),
			array( '^.$', 's', "\r\n", "<\r\n>", "\r\n" ),
			array( '\\b', '', "-\u{301}x", "-\u{301}<>x<>", "-<>\u{301}x<>" ),
			array( '\\B', '', "-\u{301}x", "<>-<>\u{301}x", "<>-\u{301}<>x" ),
			array( '\\b', '', "\u{301}x", "\u{301}<>x<>", "<>\u{301}x<>" ),
			array( '\\b', '', "a\u{AD}b", "<>a\u{AD}b<>", "<>a<>\u{AD}<>b<>" ),
			array( '\\B', '', "a\u{AD}b", "a<>\u{AD}<>b", "a\u{AD}b" ),
			array( '\\b', '', "😀\u{FE0F}a", "😀\u{FE0F}<>a<>", "😀<>\u{FE0F}a<>" ),
			array( '\\b', '', "\u{200D}a", "\u{200D}<>a<>", "<>\u{200D}a<>" ),
			array( 'ss', 'i', 'ßẞSSss', '<ß><ẞ><SS><ss>', 'ßẞ<SS><ss>' ),
			array( 'ß', 'i', 'ßẞSSss', '<ß><ẞ><SS><ss>', '<ß><ẞ>SSss' ),
			array( 'ẞ', 'i', 'ßẞSSss', '<ß><ẞ><SS><ss>', '<ß><ẞ>SSss' ),
			array( 'strasse', 'i', 'Straße', '<Straße>', 'Straße' ),
			array( 'STRASSE', 'i', 'Straße', '<Straße>', 'Straße' ),
			array( 'straße', 'i', 'STRASSE', '<STRASSE>', 'STRASSE' ),
			array( '\\x{DF}', 'i', 'SS', '<SS>', 'SS' ),
			array( 'asss*', 'i', 'aß', '<aß>', 'aß' ),
			array( 'ß+', 'i', 'ssß', '<ssß>', 'ss<ß>' ),
			array( 'aß+', 'i', 'assß', '<assß>', 'assß' ),
			array( '(?i)ss(?-i)ss', '', 'ßss', '<ßss>', 'ßss' ),
		);
	}

	/**
	 * @dataProvider data_unsupported_syntax
	 */
	public function test_unsupported_syntax( string $pattern ): void {
		$this->expectException( DomainException::class );
		WP_ICU_Regex_Translator::translate( $pattern );
	}

	public function data_unsupported_syntax(): array {
		return $this->as_cases(
			array(
				'\\y',
				'\\K',
				'\\C',
				'\\g{1}',
				'\\Q\\E',
				'a\\Eb',
				'^*a',
				'$*a',
				'^+a',
				'^(?#c)*a',
				'a(?#c)*',
				'\\p{IsAssigned}+\\P{Lu}',
				'(a)\\10',
				'(a)(b)(c)(d)(e)(f)(g)(h)(i)(j)\\10',
				'(?x) a b ',
				"(?x)a # comment\n b",
				'(?x)a #',
				'(?x)a\\ b',
				'(?x)a *',
				'(?x)a { 1 , 2 }',
				'(?x)[a b]',
				'(?x)[a\\ b]',
				'(?x)\\Qa b\\E',
				'(?x)\\p{ L u }',
				'(?x: a ) b',
				"(?x)a\u{2028}b\u{200E}c",
				"(?x)a\u{3000}b",
				'(?x)(?#c)a',
				'(?x)( ?:a)',
				'[\\b]',
				'[\\1]',
				'[\\y]',
				'[a-\\p]',
				'[a[bc]d]',
				'[a[^b]]',
				'[^a[b]]',
				'[^[^a]]',
				'[[a][b]]+',
				'[a-c&&b-d]',
				'[a-c&&[b-d]]',
				'[a-c--b]',
				'[a-c--[b]]',
				'[[a-c]-[b]]',
				'[[a-c]&[b]]',
				'[a-c&&b-d&&c-e]',
				'[a-e--b--c]',
				'[a-c&&b-d--c]',
				'[a-c--b&&a]',
				'[a[b]&&b]',
				'[[a][b]-[b]]',
				'[[a][b]&[b]]',
				'[1[a]&[b]]',
				'[[a-c]-[b]d]',
				'[[a-c]-[b]\\d]',
				'[^a-c&&b]',
				'[\\w&&\\D]',
				'[\\W\\d]',
				'[^\\W]',
				'[\\w\\W]',
				'[\\W\\w]+',
				'[a\\w\\W]+',
				'[[\\w][\\W]]+',
				'[^\\w\\W]',
				'[\\w\\W&&a]',
				'[\\w\\W--a]',
				'[a--\\p{L}]',
				'[\\p{L}--[a]]',
				'[a][&-c]',
				'(?i)[[a-c]--[B]]',
				'[[:^alpha:]]',
				'[:a\\x41:]',
				'\\p{uppercase letter}',
				'\\p{UPPERCASE-LETTER}',
				'\\p{gc=Lu}',
				'\\p{General_Category=Letter}',
				'\\p{IsLu}',
				'\\p{IsTitleCase}',
				'\\P{IsAssigned}',
				'\\p{Any}',
				'\\P{Any}',
				'\\p{greek}',
				'\\p{IsGreek}',
				'\\p{Script=Greek}',
				'\\p{sc=Han}',
				'\\p{old italic}',
				'^*',
				'(a)\12',
				'\j\K\C',
				'\E',
				'a\Q\E+',
				'(?x)\Q #[]() \E',
				'(?x)[\Q ^ \E]',
				'(?x)[a-\Qz\E]',
				'a(?# a comment )+',
				'(?x) a # comment' . "\n" . 'b',
				'(?x)[a b]+',
				'(?x)[a # comment' . "\n" . 'b]+',
				'(?x:a b) c',
				'(?x)a\ b',
				'(?x)a{ 2 , 3 }',
				'(?x)( ? : a )',
				'(?x)(?< a >x)\k< a >',
				'(?x)a( ?# comment )+',
				'(?x)\p {L}',
				'(?x)[[a] & [b]]',
				'(?x)[a - b]',
				'(?x)[a & & a]',
				'(?x)[ :digit: ]',
				"(?x)a\u{200e}b",
				"(?x)a\u{00a0}b",
				'[a-z&&[^aeiou]]+',
				'[[a-z]-[aeiou]]+',
				'[[a-z]&[def]]+',
				'[a-z-[aeiou]]+',
				'[a&&[b]c]',
				'[[a]&[b]c]',
				'[a[b]&[b]]',
				'[a[b]-[b]]',
				'[ab-\p{L}]',
				'[a-\h]',
				'[[a-z]-[bc]\p{L}]',
				'[[a-z]&[bc]\d]',
				'[[a-z]&[bc]d]',
				'[[a-z]-[bc]&\p{L}]',
				'[ab--bc]',
				'[a--b&&b]',
				'[[a][b][c]]+',
				'[class[concat][union][not]]+',
				'[^[a-c][d-f]]+',
				'[^[^a-c]]+',
				str_repeat( '[a', 64 ) . 'b' . str_repeat( ']', 64 ),
				'[^' . str_repeat( '[a', 63 ) . 'b' . str_repeat( ']', 64 ),
				'[' . str_repeat( '[a]', 2048 ) . ']+',
				'[\b\A\1]+',
				'[\p{L}&&\P{Lu}]+',
				'\p{General_Category=Decimal_Number}+',
				'\p{gC=lU}+',
				'\p{upper-case_letter}+',
			)
		);
	}

	/**
	 * Reject PCRE extensions that ICU rejects or interprets differently.
	 *
	 * @dataProvider data_pcre_syntax
	 */
	public function test_pcre_syntax_is_rejected( string $pattern ): void {
		$this->expectException( DomainException::class );
		WP_ICU_Regex_Translator::translate( $pattern );
	}

	public function data_pcre_syntax(): array {
		return $this->as_cases(
			array(
				'(*ACCEPT)',
				'(*FAIL)',
				'(*F)',
				'(*COMMIT)',
				'(*PRUNE)',
				'(*SKIP)',
				'(*THEN)',
				'(*MARK:x)',
				'(*:x)',
				'(*UTF8)',
				'(*UTF)',
				'(*UCP)',
				'(*NO_JIT)',
				'(*LIMIT_MATCH=1)',
				'(*ANY)',
				'(*CRLF)',
				'(*BSR_ANYCRLF)',
				'(*NOTEMPTY)',
				'(*NO_START_OPT)',
				'(*NO_AUTO_POSSESS)',
				'a(*ACCEPT)b',
				'a|(*FAIL)',
				'(*pla:a)',
				'(*atomic:a)',
				'(?R)',
				'(?0)',
				'(?1)',
				'(a)(?1)',
				'(?+1)(a)',
				'(a)(?-1)',
				'(?<n>a)(?&n)',
				'(?<n>a)(?P>n)',
				'(a)(?(1)b|c)',
				'(?(DEFINE)a)',
				'(?(?=a)b)',
				'(?C)',
				'(?C1)a',
				'(?|a|b)',
				'(?P<n>a)',
				'(?\'n\'a)',
				'(?<n>a)(?P=n)',
				'(?<n>a)\\k\'n\'',
				'(?<n>a)\\k{n}',
				'(a)\\g1',
				'(a)\\g{1}',
				'(a)\\g{-1}',
				'(a)\\g<1>',
				'(?U)a',
				'(?J)a',
				'(?n)a',
				'(?^)a',
				'(?xx)a',
				'(?a)a',
				'(?r)a',
				'(?X)a',
				'\\N',
				'a\\N+',
				'\\N{U+41}',
				'\\K',
				'a\\Kb',
				'(?<=\\Ka)',
				'\\C',
				'\\o{101}',
				'\\E',
				'a\\Eb',
				'\\pL',
				'\\PL',
				'\\p{L&}',
				'\\p{^L}',
				'\\p{Xan}',
				'\\p{Xwd}',
				'\\p{Xsp}',
				'\\p{Any}',
				'[[.a.]]',
				'[[=a=]]',
				'(?<a_b>x)',
				'(?<1a>x)',
				'\\x',
				'\\xg',
				'\\x{}',
				'\\x{00000041}',
				'\\0',
				'\\08',
				'\\10',
				'\\400',
				'(a)\\10',
				'(?)',
				'(?[a])',
				'(?[ [a-z] - [aeiou] ])',
			)
		);
	}

	/**
	 * @dataProvider data_invalid_patterns
	 */
	public function test_invalid_patterns_do_not_compile( string $pattern ): void {
		try {
			$compiled = WP_ICU_Regex_Translator::translate( $pattern );
		} catch ( DomainException $e ) {
			$this->addToAssertionCount( 1 );
			return;
		}

		// Check the compile error without also reporting PHP's warning.
		set_error_handler( static function () {} );
		try {
			$result = preg_match( $compiled, '' );
		} finally {
			restore_error_handler();
		}
		$this->assertFalse( $result );
		$this->assertSame( PREG_INTERNAL_ERROR, preg_last_error() );
	}

	public function data_invalid_patterns(): array {
		return $this->as_cases(
			array(
				'(',
				')',
				'a)',
				'(a',
				'[',
				'[a',
				'[]',
				'[^]',
				'*',
				'+a',
				'a**',
				'a+*',
				'a{2}{3}',
				'a{2,1}',
				'^*',
				'$+',
				'\\b+',
				'\\A?',
				'(?=a)*',
				'(?<=a)+',
				'(?!a){2}',
				'\\',
				'a\\',
				'[z-a]',
				'[a-\\d]',
				'[--a]',
				'[a--]',
				'[&&a]',
				'[a&&]',
				'\\p{Nope}',
				'\\p{Greekish}',
				'\\p{L',
				'\\p',
				'(?<a>x)(?<a>y)',
				'\\2(a)',
				'\\k<a>',
				'\\x{110000}',
				'\\x{D800}',
				'(?#',
				'(?#a',
				'(?i',
				'(?z)a',
				'a{65536}',
				'[[:nope:]]',
				'[[:alpha:]',
				'(?<=a*)b',
				"\xC0\xAF",
				"\xED\xA0\x80",
			)
		);
	}

	/**
	 * @dataProvider data_output
	 */
	public function test_output( string $pattern, string $flags, string $expected ): void {
		$this->assertSame( $expected, WP_ICU_Regex_Translator::translate( $pattern, $flags ) );
	}

	public function data_output(): array {
		return array(
			array( 'abc', '', '/abc/uX' ),
			array( 'abc', 'i', '/abc/uXi' ),
			array( 'abc', 'ims', '/abc/uXi' ),
			array( 'a/b', '', '/a\/b/uX' ),
			array( "a\0b", '', '/a\x00b/uX' ),
			array( 'a.b', 's', '/a[\s\S]b/uX' ),
			array( 'a.b', 'd', '/a[^\n]b/uX' ),
			array( '^a$', 'md', '/(?<![^\n])a(?![^\n])/uX' ),
			array( '\d+', '', '/[\d]+/uX' ),
			array( '\D', '', '/[^\d]/uX' ),
			array( '[^\d-]', '', '/[^\d\-]/uX' ),
			array( '[[:digit:][:punct:]]', '', '/[\d\p{P}]/uX' ),
			array( '[\s\p{S}[:space:]]', '', '/[\s\p{S}\s]/uX' ),
			array( '[:^digit:]', '', '/[^\d]/uX' ),
			array( '\p{Lu}', '', '/[\p{Lu}]/uX' ),
			array( '\p{Lu}', 'i', '/[\p{L&}]/uXi' ),
			array( '(?i)[[:upper:]](?-i)[[:upper:]]', '', '/(?i)[\p{L&}](?-i)[\p{Lu}]/uX' ),
			array( '(?m)a(?s:b)', '', '/(?-i)a(?-i:b)/uX' ),
			array( '(a)\1', '', '/(a)\g{1}/uX' ),
			array( '\Qa.b/c\E', '', '/a\.b\/c/uX' ),
			array( '[\Qa-z\E]', '', '/[a\-z]/uX' ),
			array( 'a(?#comment)b', '', '/a(?#)b/uX' ),
			array( '\x41\x{1F600}\0101\cA', '', '/\x{41}\x{1F600}\x{41}\x{1}/uX' ),
			array( 'a{2,3}?b*+', '', '/a{2,3}?b*+/uX' ),
		);
	}

	public function test_inline_flags_end_with_their_group(): void {
		$this->assertSame( 1, preg_match( WP_ICU_Regex_Translator::translate( '(?:(?s).)a' ), "\na" ) );
		$this->assertSame( 0, preg_match( WP_ICU_Regex_Translator::translate( '(?:(?s).).' ), "\n\n" ) );
		$this->assertSame( 1, preg_match( WP_ICU_Regex_Translator::translate( 'a\n(?m)^b' ), "a\nb" ) );
		$this->assertSame( 0, preg_match( WP_ICU_Regex_Translator::translate( '(?:(?m)a\n)^b' ), "a\nb" ) );
	}

	public function test_the_match_start_can_be_disabled(): void {
		$this->assertSame( '/\Ga/uX', WP_ICU_Regex_Translator::translate( '\Ga' ) );
		$this->assertSame( 0, preg_match( WP_ICU_Regex_Translator::translate( '\Ga', '', true ), 'a' ) );
		$this->assertSame( 1, preg_match( WP_ICU_Regex_Translator::translate( '\Ga|b', '', true ), 'ab' ) );
	}

	public function test_unknown_flags_are_rejected(): void {
		$this->expectException( DomainException::class );
		WP_ICU_Regex_Translator::translate( 'a', 'x' );
	}

	public function test_a_pattern_that_pcre_cannot_compile_is_rejected(): void {
		$this->assertSame( '/' . str_repeat( 'a', 65535 ) . '/uX', WP_ICU_Regex_Translator::translate( str_repeat( 'a', 65535 ) ) );
		$this->expectException( DomainException::class );
		WP_ICU_Regex_Translator::translate( str_repeat( 'a', 65536 ) );
	}

	public function test_alphabetic_classes_work_with_any_pcre_version(): void {
		$compiled = WP_ICU_Regex_Translator::translate( '[[:alpha:]]+[[:alnum:]]' );
		$this->assertSame( 1, preg_match( $compiled, 'añ日Ⅷ٣', $match ) );
		$this->assertSame( 'añ日Ⅷ٣', $match[0] );
	}

	public function test_long_subjects_stay_within_pcre_limits(): void {
		$subject = str_repeat( "ab \r\n", 200000 );
		$this->assertSame( 1, preg_match( WP_ICU_Regex_Translator::translate( '^.*$', 's' ), $subject ) );
		$this->assertSame( 1, preg_match( WP_ICU_Regex_Translator::translate( '^(?:\w+\b\W+)*$' ), "ab \r\n" ) );
		$this->assertSame( 1, preg_match( WP_ICU_Regex_Translator::translate( '^[\w\s]*$' ), $subject ) );
		$this->assertSame( 1, preg_match( WP_ICU_Regex_Translator::translate( '\A[^x]*\z' ), $subject ) );
	}

	private function as_cases( array $patterns ): array {
		return array_map(
			static function ( $pattern ) {
				return array( $pattern );
			},
			$patterns
		);
	}

	/**
	 * Wrap each match and its captures in angle brackets, advancing after empty matches as ICU does.
	 */
	private function replace_matches( string $compiled, string $subject ): string {
		preg_match_all( $compiled, '', $empty );
		$group_count = count( array_filter( array_keys( $empty ), 'is_int' ) ) - 1;

		$out    = '';
		$copied = 0;
		$offset = 0;
		$length = strlen( $subject );
		while ( $offset <= $length && 1 === preg_match( $compiled, $subject, $match, PREG_OFFSET_CAPTURE, $offset ) ) {
			$start = $match[0][1];
			$end   = $start + strlen( $match[0][0] );
			$out  .= substr( $subject, $copied, $start - $copied ) . '<' . $match[0][0];
			for ( $i = 1; $i <= $group_count; $i++ ) {
				$out .= '|' . ( isset( $match[ $i ] ) && $match[ $i ][1] >= 0 ? $match[ $i ][0] : '' );
			}
			$out   .= '>';
			$copied = $end;

			// After an empty match, ICU continues one code point later.
			$offset = $end;
			if ( $end === $start ) {
				++$offset;
				while ( $offset < $length && 0x80 === ( ord( $subject[ $offset ] ) & 0xC0 ) ) {
					++$offset;
				}
			}
		}
		return $out . substr( $subject, $copied );
	}
}
