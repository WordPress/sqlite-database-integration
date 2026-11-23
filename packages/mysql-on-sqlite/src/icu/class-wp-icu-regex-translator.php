<?php

/**
 * Translates MySQL's ICU regular expressions into PHP's PCRE syntax.
 *
 * The tokenizer rejects unrecognized tokens. PCRE checks the translated pattern
 * for errors such as unbalanced groups, reversed ranges, and unknown properties.
 *
 * Supported syntax includes groups, lookarounds, quantifiers, character sets,
 * POSIX classes, quoted literals (\Q...\E), code point escapes, and backreferences
 * (\1 through \9 and \k<name>). The i, m, s, and d flags can also be set inline.
 * Legacy MySQL patterns may use [[:<:]], [[:>:]], and literal braces.
 *
 * Unsupported ICU syntax
 *
 * - Nested sets, set operations, and negated classes that expand to several
 *   items inside a set, such as [\W_].
 * - Unicode properties beyond short general categories (Lu), scripts (Greek),
 *   and POSIX classes (alpha).
 * - Escaped letters without a meaning, which ICU reads literally, such as \y.
 * - Flags x and w, named characters (\N{name}), backreferences above \9,
 *   quantified anchors, and empty \Q\E.
 *
 * Known differences from ICU
 *
 * - Case-insensitive matching does not equate "ß" with "ss". The Lu, Ll, Lt,
 *   upper, and lower classes match every cased letter, including dotless "ı".
 * - [:upper:] and [:lower:] miss cased symbols such as "Ⅷ".
 * - \s, \h, [:space:], and [:blank:] also match U+180E. Word boundaries
 *   consider combining marks and format characters that ICU skips.
 * - In dotall mode, "." matches CR and LF separately. In multiline mode, "^"
 *   skips the position between them. Grapheme clusters (\X) follow PCRE's rules.
 * - Before PCRE 10.40, alphabetic classes miss some combining vowel signs.
 * - PCRE accepts backreferences in lookbehinds and named backreferences before
 *   their group. It limits explicit repetition counts to 65535, lookbehinds to
 *   a fixed length before 10.43, and group names to 32 characters before 10.44.
 */
class WP_ICU_Regex_Translator {
	// The characters that end a line in ICU.
	private const LINE_ENDS = '\n\x0B\f\r\x85\x{2028}\x{2029}';

	// PCRE counts "²" as a word character. ICU excludes it.
	private const WORD = '\p{L}\p{M}\p{Nd}\p{Nl}\p{Pc}\x{200C}\x{200D}\x{24B6}-\x{24E9}\x{1F130}-\x{1F149}\x{1F150}-\x{1F169}\x{1F170}-\x{1F189}';

	// PCRE class bodies for ICU's POSIX classes.
	private const POSIX_CLASSES = array(
		'alnum'  => '\p{Alphabetic}\p{Nd}',
		'alpha'  => '\p{Alphabetic}',
		'ascii'  => '\x00-\x7F',
		'blank'  => '\h',
		'cntrl'  => '\p{Cc}',
		'digit'  => '\d',
		'graph'  => '\p{L}\p{M}\p{N}\p{P}\p{S}\p{Cf}\p{Co}',
		'lower'  => '\p{Ll}',
		'print'  => '\p{L}\p{M}\p{N}\p{P}\p{S}\p{Cf}\p{Co}\p{Zs}',
		'punct'  => '\p{P}',
		'space'  => '\s',
		'upper'  => '\p{Lu}',
		'word'   => self::WORD,
		'xdigit' => '\p{Nd}A-Fa-f\x{FF21}-\x{FF26}\x{FF41}-\x{FF46}',
	);

	// Translations for ".", "^", and "$" under the active flags.
	private const NOT_IN_CRLF = '(?!(?<=\r)\n)';
	private const LINES       = array(
		'.'   => '[^' . self::LINE_ENDS . ']',
		'.d'  => '[^\n]',
		'.s'  => '[\s\S]',
		'.sd' => '[\s\S]',
		'^'   => '\A',
		'^d'  => '\A',
		'^m'  => '(?:\A|(?<=[' . self::LINE_ENDS . '])(?!\z))' . self::NOT_IN_CRLF,
		'^md' => '(?<![^\n])',
		'$'   => '(?=(?:\r\n|[' . self::LINE_ENDS . '])?\z)' . self::NOT_IN_CRLF,
		'$d'  => '(?=\n?\z)',
		'$m'  => '(?![^' . self::LINE_ENDS . '])' . self::NOT_IN_CRLF,
		'$md' => '(?![^\n])',
	);

	// Escapes outside of a set, and MySQL 5.7's word boundaries.
	private const ESCAPES = array(
		'A'       => '\A',
		'b'       => '(?(?<=[' . self::WORD . '])(?![' . self::WORD . '])|(?=[' . self::WORD . ']))',
		'B'       => '(?(?<=[' . self::WORD . '])(?=[' . self::WORD . '])|(?![' . self::WORD . ']))',
		'G'       => '\G',
		'R'       => '(?>\r\n|[' . self::LINE_ENDS . '])',
		'X'       => '\X',
		'z'       => '\z',
		'Z'       => self::LINES['$'],
		'[[:<:]]' => '(?<![' . self::WORD . '])(?=[' . self::WORD . '])',
		'[[:>:]]' => '(?<=[' . self::WORD . '])(?![' . self::WORD . '])',
	);

	private const QUOTE      = '\\\\Q(?!\\\\E)(?:[^\\\\]++|\\\\(?!E))*+';
	private const ESCAPE     = '\\\\(?:[pP]\{[^}]*\}|x\{[0-9A-Fa-f]{1,6}\}|x[0-9A-Fa-f]{1,2}|u[0-9A-Fa-f]{4}|U[0-9A-Fa-f]{8}|0[0-3][0-7]{2}|0[0-7]{1,2}|c[\x00-\x7F]|k<[A-Za-z][A-Za-z0-9]*>|[1-9](?!\d)|[^Q0-9])';
	private const POSIX      = '\[:\^?[A-Za-z]+:\]';
	private const SET_ITEM   = self::POSIX . '|' . self::QUOTE . '\\\\E|' . self::ESCAPE . '|-(?!-)|&(?!&)|[^\[\]\\\\&-]++';
	private const SET        = '(?!\[:[^:]*:\])\[\^?+\]?+(?:' . self::SET_ITEM . ')*+\]';
	private const INTERVAL   = '\{\d+(?:,\d*)?\}';
	private const QUANTIFIER = '((?:[*+?]|' . self::INTERVAL . ')[+?]?)?';
	private const GROUP      = '\((?:\?(?:[:=!>]|<[=!]|<[A-Za-z][A-Za-z0-9]*>|#[^)\\\\]*\)|(?=[imsdu-])([imsdu]*)-?([imsdu]*)([:)])))?';
	private const BRACE      = '(\}|(?!' . self::INTERVAL . ')\{)';
	private const TOKEN      = '/(?:[^\\\\\[(){}|^$.*+?\/\x00]++|' . self::QUOTE . '(?:\\\\E|\z)|' . self::ESCAPE . '|' . self::POSIX . '|' . self::SET . '|[.)\/\x00])' . self::QUANTIFIER . '|' . self::GROUP . '|' . self::BRACE . '|\[\[:[<>]:\]\]|[\s\S]/';

	// Capture groups in TOKEN.
	private const CAPTURE_QUANTIFIER     = 1;
	private const CAPTURE_FLAGS_ON       = 2;
	private const CAPTURE_FLAGS_OFF      = 3;
	private const CAPTURE_FLAG_DELIMITER = 4;
	private const CAPTURE_BRACE          = 5;

	// Cap the input size before expanding tokens such as word boundaries into longer PCRE patterns.
	private const MAX_LENGTH = 65535;

	/**
	 * Active ICU flags.
	 *
	 * @var string
	 */
	private $flags;

	/**
	 * Saved flags and repeatability for each open group.
	 *
	 * @var array[]
	 */
	private $groups = array();

	/**
	 * Whether \G in the input pattern must fail.
	 *
	 * @var bool
	 */
	private $without_g;

	/**
	 * Translate an ICU regular expression to a PCRE pattern.
	 *
	 * @param string $pattern   The ICU regular expression.
	 * @param string $flags     ICU flags: i (ignore case), m (multiline), s (dotall), d (Unix lines).
	 * @param bool   $without_g Disable \G when starting past the previous match or at a nonzero initial offset.
	 *
	 * @throws DomainException On unsupported syntax, flags, or a translation limit.
	 * @return string A pattern for the preg_*() functions. It can still fail to compile.
	 */
	public static function translate( $pattern, $flags = '', $without_g = false ) {
		if ( strlen( $pattern ) > self::MAX_LENGTH || strspn( $flags, 'imsd' ) !== strlen( $flags ) ) {
			throw new DomainException( 'Unsupported regular expression length or flags.' );
		}
		$translator            = new self();
		$translator->flags     = $flags;
		$translator->without_g = $without_g;
		$translated            = self::replace( self::TOKEN, array( $translator, 'translate_token' ), $pattern );

		// "X" stops PHP 7.3 and 7.4 from reading an unknown property as literal text.
		return '/' . $translated . '/uX' . ( false !== strpos( $flags, 'i' ) ? 'i' : '' );
	}

	/**
	 * Translate one token and update the state for the next token.
	 *
	 * @param string[] $parts Captures from TOKEN.
	 * @return string The PCRE text.
	 */
	private function translate_token( $parts ) {
		$token      = $parts[0];
		$quantifier = $parts[ self::CAPTURE_QUANTIFIER ] ?? '';
		$item       = substr( $token, 0, strlen( $token ) - strlen( $quantifier ) );

		switch ( $item[0] ) {
			case '\\':
				// A position, like "\b", can't be repeated.
				if ( isset( $item[1] ) && ( '' === $quantifier || false === strpos( 'AbBGzZ', $item[1] ) ) ) {
					return $this->translate_escape( substr( $item, 1 ), false ) . $quantifier;
				}
				break;
			case '[':
				if ( isset( self::ESCAPES[ $item ] ) ) {
					return self::ESCAPES[ $item ];
				} elseif ( preg_match( '/^\[:[^:]*:\]$/', $item ) ) {
					// ICU also accepts a POSIX class on its own, as in "[:digit:]".
					return $this->translate_property( trim( $item, '[:^]' ), '^' === $item[2], false ) . $quantifier;
				} elseif ( isset( $item[1] ) ) {
					return $this->translate_set( $item ) . $quantifier;
				}
				break;
			case '(':
				$delimiter = $parts[ self::CAPTURE_FLAG_DELIMITER ] ?? '';
				if ( '' !== $delimiter ) {
					// Pass case sensitivity to PCRE. The other flags affect how we translate the pattern.
					$old         = $this->flags;
					$this->flags = str_replace( str_split( $parts[ self::CAPTURE_FLAGS_OFF ] . '-' ), '', $old . $parts[ self::CAPTURE_FLAGS_ON ] );
					if ( ':' === $delimiter ) {
						$this->groups[] = array( $old, true );
					}
					return '(?' . ( false !== strpos( $this->flags, 'i' ) ? 'i' : '-i' ) . $delimiter;
				} elseif ( '(?#' === substr( $item, 0, 3 ) ) {
					return '(?#)';
				}
				// A look-around can't be repeated.
				$is_look_around = false !== strpbrk( $item, '=!' );
				$this->groups[] = array( $this->flags, ! $is_look_around );
				return $item;
			case ')':
				if ( $this->groups ) {
					list( $this->flags, $is_repeatable ) = array_pop( $this->groups );
					if ( '' === $quantifier || $is_repeatable ) {
						return $token;
					}
				}
				break;
			case '.':
				return $this->line_pattern( '.' ) . $quantifier;
			case '^':
			case '$':
				return $this->line_pattern( $item );
			case '{':
			case '}':
				// An interval with nothing to repeat is an error.
				if ( '' !== ( $parts[ self::CAPTURE_BRACE ] ?? '' ) ) {
					return '\\' . $item;
				}
				break;
			case '/':
				return '\/' . $quantifier;
			case "\0":
				return '\x00' . $quantifier;
			case '|':
				return '|';
			case '*':
			case '+':
			case '?':
				break;
			default:
				return $token;
		}
		throw new DomainException( "Unsupported regular expression syntax: $token" );
	}

	/**
	 * Translate an escape.
	 *
	 * @param string $escape The escape, without its backslash.
	 * @param bool   $in_set Whether the escape is in a set.
	 * @return string The PCRE text.
	 */
	private function translate_escape( $escape, $in_set ) {
		$letter = $escape[0];
		$lower  = strtolower( $letter );
		$rest   = substr( $escape, 1 );
		if ( 'Q' === $letter && '' !== $rest ) {
			return preg_quote( preg_replace( '/\\\\E$/', '', $rest ), '/' );
		} elseif ( 'p' === $lower && '' !== $rest ) {
			return $this->translate_property( substr( $rest, 1, -1 ), 'P' === $letter, $in_set );
		} elseif ( 'k' === $letter && '' !== $rest && ! $in_set ) {
			return '\\' . $escape;
		} elseif ( '' !== $rest && 'k' !== $letter ) {
			// For \cX, ICU takes the low five bits of X. The tokenizer accepts ASCII X.
			if ( 'c' === $letter ) {
				$code = ord( $rest ) & 0x1F;
			} else {
				$code = '0' === $letter ? octdec( $rest ) : hexdec( trim( $rest, '{}' ) );
			}
			return sprintf( '\x{%X}', $code );
		} elseif ( ! preg_match( '/^[A-Za-z0-9]/', $letter ) || false !== strpos( 'aefnrt', $letter ) ) {
			// Preserve escaped punctuation and shared escapes such as \n.
			return '\\' . $letter;
		} elseif ( false !== strpos( 'dhsvw', $lower ) ) {
			$body = 'w' === $lower ? self::WORD : '\\' . $lower;
			return $this->class_body( $body, $lower !== $letter, $in_set );
		} elseif ( ! $in_set ) {
			if ( 'G' === $letter && $this->without_g ) {
				return '(?!)';
			} elseif ( isset( self::ESCAPES[ $letter ] ) ) {
				return self::ESCAPES[ $letter ];
			} elseif ( false !== strpos( '123456789', $letter ) ) {
				return '\g{' . $letter . '}';
			}
		}
		throw new DomainException( "Unsupported regular expression syntax: \\$escape" );
	}

	/**
	 * Translate a set, like "[a-z[:digit:]]".
	 *
	 * Preserve character ranges. Elsewhere, "-" and "&" may be literals or ICU set operators.
	 *
	 * @param string $set The set, with its brackets.
	 * @return string The PCRE class.
	 */
	private function translate_set( $set ) {
		$start    = '^' === $set[1] ? 2 : 1;
		$previous = '';
		$body     = self::replace(
			// Capture the next class escape, opening bracket, or end of the set to disambiguate "-" and "&".
			'/(?:' . self::SET_ITEM . '|\])(?=(\z|\[|\\\\[dDhHsSvVwWpP])?)/',
			function ( $parts ) use ( &$previous ) {
				$item = $parts[0];
				$next = $parts[1] ?? null;
				$last = $previous;
				if ( '-' === $item || '&' === $item ) {
					$previous = $item;
					if ( '-' === $item && 'character' === $last && null === $next ) {
						return '-';
					}

					/*
					 * ICU reads a "-" or "&" between two sets as a set operator. It rejects a
					 * range that ends with a class, and after a literal "&", it starts a range
					 * at an earlier character.
					 */
					$is_operator   = 'set' === $last && '[' === $next;
					$is_ends_class = '-' === $item && isset( $next[1] ) && ( 'character' === $last || '&' === $last );
					$is_after_amp  = '-' === $item && null === $next && '&' === $last;
					if ( $is_operator || $is_ends_class || $is_after_amp ) {
						throw new DomainException( "Unsupported regular expression syntax: $item in a set" );
					}
					return '\\' . $item;
				} elseif ( '[' === $item[0] ) {
					$previous = 'set';
					return $this->translate_property( trim( $item, '[:^]' ), '^' === $item[2], true );
				}
				if ( '\\' === $item[0] ) {
					$previous = 'character';
					if ( preg_match( '/^\\\\(?:[pP]\{|[dDhHsSvVwW]$)/', $item ) ) {
						$previous = isset( $item[2] ) ? 'set' : 'class';
					}
					return $this->translate_escape( substr( $item, 1 ), true );
				}
				$previous = 'character';
				return strtr(
					$item,
					array(
						'/'  => '\/',
						"\0" => '\x00',
						']'  => '\]',
					)
				);
			},
			substr( $set, $start, -1 )
		);

		// PCRE 10.32–10.39 can match part of a UTF-8 character with "(?i)[^s]".
		// Writing "[^ss]" avoids the bug and excludes the same characters.
		if ( 2 === $start && preg_match( '/^(?:\\\\x\{[0-9A-F]+\}|\\\\[^a-zA-Z]|[^\\\\])$/su', $body ) ) {
			$body .= $body;
		}
		return substr( $set, 0, $start ) . $body . ']';
	}

	/**
	 * Translate a property name, like "Lu", "Greek", or "alpha".
	 *
	 * @param string $name    The name.
	 * @param bool   $negated Whether the property is negated.
	 * @param bool   $in_set  Whether the property is in a set.
	 * @return string The PCRE class, or its body when the property is in a set.
	 */
	private function translate_property( $name, $negated, $in_set ) {
		$key = strtolower( $name );
		if ( false !== strpos( $this->flags, 'i' ) && in_array( $key, array( 'lu', 'll', 'lt', 'upper', 'lower' ), true ) ) {
			// Lu, Ll, and Lt expand to all cased letters.
			$body = '\p{L&}';
		} elseif ( isset( self::POSIX_CLASSES[ $key ] ) ) {
			$body = self::POSIX_CLASSES[ $key ];
		} elseif ( preg_match( '/^[a-z]{1,2}$/', $key ) ) {
			// A general category. PCRE rejects the ones that it doesn't know.
			$body = '\p{' . ( 'lc' === $key ? 'L&' : ucfirst( $key ) ) . '}';
		} elseif ( preg_match( '/^[A-Z][a-z]{2,}(?:_[A-Z][a-z]+)*$/', $name ) && ! in_array( $name, array( 'Any', 'Xan', 'Xps', 'Xsp', 'Xuc', 'Xwd' ), true ) ) {
			// From PCRE 10.40, a bare script name can mean Script_Extensions. Request Script explicitly.
			$body = '\p{' . ( self::has_pcre_10_40() ? 'sc:' : '' ) . $name . '}';
		} else {
			throw new DomainException( "Unsupported regular expression property: $name" );
		}
		return $this->class_body( $body, $negated, $in_set );
	}

	/**
	 * Format a class for use on its own or inside another set.
	 *
	 * @param string $body    The class body.
	 * @param bool   $negated Whether the class is negated.
	 * @param bool   $in_set  Whether the class is in a set.
	 * @return string The PCRE class, or its body when the class is in a set.
	 */
	private function class_body( $body, $negated, $in_set ) {
		if ( ! self::has_pcre_10_40() ) {
			// Older PCRE versions lack Alphabetic. Approximate it with letters, letter numbers, and enclosed letters.
			$body = str_replace( '\p{Alphabetic}', '\p{L}\p{Nl}\x{24B6}-\x{24E9}\x{1F130}-\x{1F149}\x{1F150}-\x{1F169}\x{1F170}-\x{1F189}', $body );
		}
		if ( ! $in_set ) {
			return '[' . ( $negated ? '^' : '' ) . $body . ']';
		} elseif ( ! $negated ) {
			return $body;
		} elseif ( ! preg_match( '/^\\\\(?:[dhsv]|p\{(?!Alphabetic)[^}]*\})$/', $body ) ) {
			// Inside a set, PCRE can negate only a single class item. Alphabetic expands
			// to several items on older versions, so reject its negation here on all versions.
			throw new DomainException( 'Unsupported regular expression syntax: a negated class in a set' );
		}
		$body[1] = strtoupper( $body[1] );
		return $body;
	}

	/**
	 * Select a line pattern for the active flags.
	 *
	 * @param string $token A dot or a line anchor.
	 * @return string The PCRE fragment.
	 */
	private function line_pattern( $token ) {
		$flag = '.' === $token[0] ? 's' : 'm';
		$key  = $token;
		if ( false !== strpos( $this->flags, $flag ) ) {
			$key .= $flag;
		}
		if ( false !== strpos( $this->flags, 'd' ) ) {
			$key .= 'd';
		}
		return self::LINES[ $key ];
	}

	/**
	 * Replace matched tokens. A PCRE limit makes preg_replace_callback() return null,
	 * which would become an empty pattern when the caller adds delimiters.
	 *
	 * @param string   $regex    The regex that matches a token.
	 * @param callable $callback The function that translates a token.
	 * @param string   $text     The text.
	 * @return string The text with its tokens replaced.
	 */
	private static function replace( $regex, $callback, $text ) {
		$replaced = preg_replace_callback( $regex, $callback, $text );
		if ( null === $replaced ) {
			throw new DomainException( 'Unsupported regular expression: it is too complex.' );
		}
		return $replaced;
	}

	/**
	 * Check whether PCRE knows binary properties and the "sc:" prefix.
	 *
	 * @return bool Whether PCRE is 10.40 or newer.
	 */
	private static function has_pcre_10_40() {
		return version_compare( explode( ' ', PCRE_VERSION )[0], '10.40', '>=' );
	}
}
