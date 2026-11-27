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
 *   POSIX classes (alpha), and Alphabetic.
 * - Lookbehinds beyond single characters, sets, and alternatives, even fixed
 *   repetitions such as (?<=a{2}).
 * - Flags x and w, named characters (\N{name}), backreferences above \9,
 *   quantified anchors, empty \Q\E, and comments containing a backslash
 *   or followed by a quantifier.
 *
 * Known differences from ICU
 *
 * - Case-insensitive matching does not equate "ß" with "ss". Properties can
 *   miss case equivalents, such as "µ" for \p{Greek}. The Lu, Ll, upper, and
 *   lower classes match every cased letter, including dotless "ı".
 * - [:lower:] misses lowercase characters such as "ⅷ" and "ª". When ignoring
 *   case, [:upper:] also misses uppercase symbols such as "Ⅷ".
 * - \s, \h, [:space:], and [:blank:] also match U+180E. Word boundaries
 *   consider combining marks and format characters that ICU skips.
 * - In dotall mode, "." matches CR and LF separately. In multiline mode, "^"
 *   skips the position between them. Grapheme clusters (\X) follow PCRE's rules.
 * - Before PCRE 10.40, alphabetic classes miss some combining vowel signs,
 *   and short script names such as Latn can fail where Latin works.
 * - PCRE accepts named backreferences before their group. It limits explicit
 *   repetition counts to 65535 and, before 10.44, group names to 32 characters.
 *
 * @internal
 */
class WP_ICU_Regex_Translator {
	// In byte mode, 0x85 is data. Exclude it when translating ICU line endings.
	private const BYTE_LINE_ENDS = array(
		'\v' => '[\n\x0B\f\r]',
		'\V' => '[^\n\x0B\f\r]',
	);

	// PCRE counts "²" as a word character. ICU excludes it.
	private const WORD = '\p{L}\p{M}\p{Nd}\p{Nl}\p{Pc}\x{200C}\x{200D}\x{24B6}-\x{24E9}\x{1F130}-\x{1F149}\x{1F150}-\x{1F169}\x{1F170}-\x{1F189}';

	// The titlecase letters, like "ǅ", with their uppercase and lowercase forms.
	private const TITLECASE = '\x{1C4}-\x{1CC}\x{1F1}-\x{1F3}\x{1F80}-\x{1FAF}\x{1FB3}\x{1FBC}\x{1FC3}\x{1FCC}\x{1FF3}\x{1FFC}';

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
		'upper'  => '\p{Lu}\x{2160}-\x{216F}\x{24B6}-\x{24CF}\x{1F130}-\x{1F149}\x{1F150}-\x{1F169}\x{1F170}-\x{1F189}',
		'word'   => self::WORD,
		'xdigit' => '\p{Nd}A-Fa-f\x{FF21}-\x{FF26}\x{FF41}-\x{FF46}',
	);

	// Translations for ".", "^", and "$" under the active flags. The ".*" entries
	// restrict a leading ".*" or ".+" to the search offset (\G) or a new line.
	private const NOT_IN_CRLF = '(?!(?<=\r)\n)';
	private const LINES       = array(
		'.'    => '\V',
		'.d'   => '[^\n]',
		'.s'   => '[\s\S]',
		'.sd'  => '[\s\S]',
		'.*'   => '(?:\G|(?<=\v))',
		'.*d'  => '(?:\G|(?<=\n))',
		'.*s'  => '\G',
		'.*sd' => '\G',
		'^'    => '\A',
		'^d'   => '\A',
		'^m'   => '(?:\A|(?<=\v)(?!\z))' . self::NOT_IN_CRLF,
		'^md'  => '(?<![^\n])',
		'$'    => '(?=(?:\r\n|\v)?\z)' . self::NOT_IN_CRLF,
		'$d'   => '(?=\n?\z)',
		'$m'   => '(?!\V)' . self::NOT_IN_CRLF,
		'$md'  => '(?![^\n])',
	);

	// Escapes outside of a set, and MySQL 5.7's word boundaries.
	private const ESCAPES = array(
		'A'       => '\A',
		'b'       => '(?(?<=[' . self::WORD . '])(?![' . self::WORD . '])|(?=[' . self::WORD . ']))',
		'B'       => '(?(?<=[' . self::WORD . '])(?=[' . self::WORD . '])|(?![' . self::WORD . ']))',
		'G'       => '\G',
		'R'       => '(?>\r\n|\v)',
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
	private const GROUP      = '\((?:(?!\?)|\?(?:[:=!>]|<[A-Za-z][A-Za-z0-9]*>|#[^)\\\\]*\)|(?=[imsdu-])([imsdu]*)-?([imsdu]*)([:)])))';
	private const BRACE      = '\}|(?!' . self::INTERVAL . ')\{';

	// Match the whole lookbehind here so each alternative has a fixed length on older PCRE versions.
	private const LOOK_BEHIND = '\(\?<[=!](?:' . self::SET . '|(?!\\\\[AbBGkRXzZ1-9])' . self::ESCAPE . '|[^\\\\\[(){}^$.*+?]++|\.)*+\)';

	// Catch unrecognized syntax so the error can name the offending token.
	private const OTHER = '\\\\(?:Q\\\\E|\d+)?|\(\?(?:<[=!]|\w+|[^)])?|[\s\S]';
	private const TOKEN = '/(?:(?:[^\\\\\[(){}|^$.*+?\/\x00]++|' . self::QUOTE . '(?:\\\\E|\z)|' . self::ESCAPE . '|' . self::POSIX . '|' . self::SET . '|[.)\/\x00])' . self::QUANTIFIER . '|' . self::LOOK_BEHIND . '|' . self::GROUP . '|' . self::BRACE . '|\[\[:[<>]:\]\]|[|^$]|(' . self::OTHER . '))(?=([A-Za-z0-9_](?![*+?{]))?)/';

	// Capture groups in TOKEN.
	private const CAPTURE_QUANTIFIER     = 1;
	private const CAPTURE_FLAGS_ON       = 2;
	private const CAPTURE_FLAGS_OFF      = 3;
	private const CAPTURE_FLAG_DELIMITER = 4;
	private const CAPTURE_UNSUPPORTED    = 5;
	private const CAPTURE_NEXT_WORD      = 6;

	// Cap the input size before expanding tokens such as word boundaries into longer PCRE patterns.
	private const MAX_LENGTH = 65535;

	// MySQL ER_REGEXP_UNIMPLEMENTED.
	private const UNSUPPORTED = 3690;

	/**
	 * Active ICU flags.
	 *
	 * @var string
	 */
	private $flags;

	/**
	 * Saved flags, repeatability, and start-check count for each open group.
	 *
	 * @var array[]
	 */
	private $groups = array();

	/**
	 * Deferred start checks in NUL placeholder order.
	 *
	 * @var string[]
	 */
	private $starts = array();

	/**
	 * Whether the pattern contains a backreference.
	 *
	 * @var bool
	 */
	private $has_backreference = false;

	/**
	 * Whether a leading .* or .+ can restrict the match start here.
	 *
	 * @var bool
	 */
	private $is_leading = true;

	/**
	 * Whether the previous token ended with an unquantified ASCII word character.
	 *
	 * @var bool
	 */
	private $is_after_word = false;

	/**
	 * Whether \G in the input pattern must fail.
	 *
	 * @var bool
	 */
	private $without_g;

	/**
	 * Whether to match byte by byte.
	 *
	 * @var bool
	 */
	private $bytes;

	/**
	 * Translate an ICU regular expression to a PCRE pattern.
	 *
	 * @param string $pattern   The ICU regular expression.
	 * @param string $flags     ICU flags: i (ignore case), m (multiline), s (dotall), d (Unix lines).
	 * @param bool   $without_g Disable \G when starting past the previous match or at a nonzero initial offset.
	 * @param bool   $bytes     Match individual bytes using PCRE's byte-mode classes and boundaries.
	 *
	 * @throws WP_ICU_Regex_Exception On unsupported syntax, flags, or a translation limit.
	 * @return string A pattern for the preg_*() functions. It can still fail to compile.
	 */
	public static function translate( $pattern, $flags = '', $without_g = false, $bytes = false ) {
		if ( strlen( $pattern ) > self::MAX_LENGTH || strspn( $flags, 'imsd' ) !== strlen( $flags ) ) {
			throw new WP_ICU_Regex_Exception( 'Unsupported regular expression length or flags.', self::UNSUPPORTED );
		}
		$translator            = new self();
		$translator->flags     = $flags;
		$translator->without_g = $without_g;
		$translator->bytes     = $bytes;
		$translated            = self::replace( self::TOKEN, array( $translator, 'translate_token' ), $pattern );

		// A backreference can require a later start within a line: "(.*)-\1" matches "b-b" in "ab-b".
		// Drop the deferred checks in that case.
		foreach ( $translator->starts as $start ) {
			$translated = substr_replace( $translated, $translator->has_backreference ? '' : $start, strpos( $translated, "\0" ), 1 );
		}

		// "X" stops PHP 7.3 and 7.4 from reading an unknown property as literal text.
		return '/' . $translated . ( $bytes ? '/X' : '/uX' ) . ( false !== strpos( $flags, 'i' ) ? 'i' : '' );
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
		if ( '' !== ( $parts[ self::CAPTURE_UNSUPPORTED ] ?? '' ) ) {
			throw new WP_ICU_Regex_Exception( "Unsupported regular expression syntax: $token", self::UNSUPPORTED );
		}
		$is_leading          = $this->is_leading;
		$this->is_leading    = '|' === $token && ! $this->groups;
		$is_after_word       = $this->is_after_word;
		$this->is_after_word = false;

		switch ( $item[0] ) {
			case '\\':
				// A position, like "\b", can't be repeated.
				if ( '' === $quantifier || false === strpos( 'AbBGzZ', $item[1] ) ) {
					// In "\bword\b", each boundary needs to test only the side facing away from the word.
					// Omitting the other tests keeps word lists smaller when PCRE compiles them.
					$is_before_word = isset( $parts[ self::CAPTURE_NEXT_WORD ] );
					if ( 'b' === strtolower( $item[1] ) && ! $this->bytes && ( $is_before_word || $is_after_word ) ) {
						return '(?' . ( $is_before_word ? '<' : '' ) . ( 'b' === $item[1] ? '!' : '=' ) . '[' . self::WORD . '])';
					}
					return $this->translate_escape( substr( $item, 1 ), false ) . $quantifier;
				}
				break;
			case '[':
				if ( isset( self::ESCAPES[ $item ] ) ) {
					return $this->bytes ? $item : self::ESCAPES[ $item ];
				} elseif ( preg_match( '/^\[:[^:]*:\]$/', $item ) ) {
					// ICU also accepts a POSIX class on its own, as in "[:digit:]".
					return $this->translate_property( trim( $item, '[:^]' ), '^' === $item[2], false ) . $quantifier;
				}
				return $this->translate_set( $item ) . $quantifier;
			case '(':
				$delimiter = $parts[ self::CAPTURE_FLAG_DELIMITER ] ?? '';
				if ( '' !== $delimiter ) {
					// Pass case sensitivity to PCRE. The other flags affect how we translate the pattern.
					$old         = $this->flags;
					$this->flags = str_replace( str_split( $parts[ self::CAPTURE_FLAGS_OFF ] . '-' ), '', $old . $parts[ self::CAPTURE_FLAGS_ON ] );
					if ( ':' === $delimiter ) {
						$this->groups[] = array( $old, true, count( $this->starts ) );
					}
					$this->is_leading = $is_leading;
					return '(?' . ( false !== strpos( $this->flags, 'i' ) ? 'i' : '-i' ) . $delimiter;
				} elseif ( ')' === substr( $item, -1 ) ) {
					if ( '#' === $item[2] ) {
						return '(?#)';
					}
					// LOOK_BEHIND excludes nested groups. Translate its contents with the current flags.
					$inside              = self::replace( self::TOKEN, array( $this, 'translate_token' ), substr( $item, 4, -1 ) );
					$this->is_leading    = false;
					$this->is_after_word = false;
					return substr( $item, 0, 4 ) . $inside . ')';
				}
				// A lookahead consumes no text. An atomic group keeps its first match.
				// Neither can use a leading ".*" to restrict where the whole pattern starts.
				$is_look_ahead    = false !== strpbrk( $item, '=!' );
				$this->is_leading = $is_leading && ! $is_look_ahead && '(?>' !== $item;
				$this->groups[]   = array( $this->flags, ! $is_look_ahead, count( $this->starts ) );
				return $item;
			case ')':
				if ( $this->groups ) {
					list( $this->flags, $is_repeatable, $start_count ) = array_pop( $this->groups );
					if ( '' !== $quantifier && $is_repeatable ) {
						// A repeated group can start again mid-line. Clear its checks but keep the placeholder count.
						$this->starts = array_pad( array_slice( $this->starts, 0, $start_count ), count( $this->starts ), '' );
					}
					if ( '' === $quantifier || $is_repeatable ) {
						return $token;
					}
				}
				break;
			case '.':
				$dot = $this->line_pattern( '.' );

				// PCRE 10.49's JIT can miss "c?-\V{2}" in "-cba". The equivalent set "[^\v]" works.
				if ( '\V' === $dot && 0 === strpos( $quantifier, '{' ) ) {
					$dot = '[^\v]';
				}
				$dot .= $quantifier;

				// PCRE's leading-dot optimization is lost when "." becomes a class. Without JIT,
				// a failed search can scan the rest of the line again from every character.
				if ( $is_leading && '' !== $quantifier && false !== strpos( '*+', $quantifier[0] ) ) {
					$start = $this->line_pattern( '.*' );
					if ( ! $this->groups ) {
						return $start . $dot;
					}
					$this->starts[] = $start;
					return "\0" . $dot;
				}
				return $dot;
			case '^':
			case '$':
				return $this->line_pattern( $item );
			case '{':
			case '}':
				return '\\' . $item;
			case '/':
				return '\/' . $quantifier;
			case "\0":
				return '\x00' . $quantifier;
			case '|':
				return '|';
			default:
				$this->is_after_word = '' === $quantifier && 1 === preg_match( '/[A-Za-z0-9_]$/D', $token );
				return $token;
		}
		throw new WP_ICU_Regex_Exception( "Unsupported regular expression syntax: $token", self::UNSUPPORTED );
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
						$previous = 'range';
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
						throw new WP_ICU_Regex_Exception( "Unsupported regular expression syntax: $item in a set", self::UNSUPPORTED );
					}
					return '\\' . $item;
				} elseif ( '[' === $item[0] ) {
					$previous = 'set';
					return $this->translate_property( trim( $item, '[:^]' ), '^' === $item[2], true );
				}

				// In [a-c-e], the first range uses up "c", so the second hyphen is literal.
				// In [a-cd-e], "d" can start a new range.
				$is_range_end = 'range' === $last && preg_match( '/^(?:\\\\[^Q]|.\z)/s' . ( $this->bytes ? '' : 'u' ), $item );
				$previous     = $is_range_end ? 'class' : 'character';
				if ( '\\' === $item[0] ) {
					if ( preg_match( '/^\\\\(?:[pP]\{|[dDhHsSvVwW]$)/', $item ) ) {
						$previous = isset( $item[2] ) ? 'set' : 'class';
					}
					return $this->translate_escape( substr( $item, 1 ), true );
				}
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
			$this->has_backreference = true;
			return '\\' . $escape;
		} elseif ( '' !== $rest && 'k' !== $letter ) {
			// For \cX, ICU takes the low five bits of X. The tokenizer accepts ASCII X.
			if ( 'c' === $letter ) {
				$code = ord( $rest ) & 0x1F;
			} else {
				$code = '0' === $letter ? octdec( $rest ) : hexdec( trim( $rest, '{}' ) );
			}
			return sprintf( '\x{%X}', $code );
		} elseif ( "\0" === $letter ) {
			// PHP before 8.2 rejects a NUL byte in a pattern.
			return '\x00';
		} elseif ( ! preg_match( '/^[A-Za-z0-9]/', $letter ) || false !== strpos( 'aefnrt', $letter ) ) {
			// Preserve escaped punctuation and shared escapes such as \n.
			return '\\' . $letter;
		} elseif ( false !== strpos( 'CEFgIiJjKLlMmOoqTYy', $letter ) ) {
			// ICU treats these letters literally. Removing the backslash prevents PCRE from interpreting escapes such as \K.
			return $letter;
		} elseif ( false !== strpos( 'dhsvw', $lower ) ) {
			$body = 'w' === $lower && ! $this->bytes ? self::WORD : '\\' . $lower;
			return $this->class_body( $body, $lower !== $letter, $in_set );
		} elseif ( ! $in_set ) {
			if ( 'G' === $letter && $this->without_g ) {
				return '(?!)';
			} elseif ( 'b' === $lower && $this->bytes ) {
				return '\\' . $letter;
			} elseif ( isset( self::ESCAPES[ $letter ] ) ) {
				return $this->with_line_ends( self::ESCAPES[ $letter ] );
			} elseif ( false !== strpos( '123456789', $letter ) ) {
				$this->has_backreference = true;
				return '\g{' . $letter . '}';
			}
		}
		throw new WP_ICU_Regex_Exception( "Unsupported regular expression syntax: \\$escape", self::UNSUPPORTED );
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
		if ( 'alphabetic' === $key ) {
			// Use the same fallback for Alphabetic and the POSIX alpha class on older PCRE versions.
			$key = 'alpha';
		}
		if ( $this->bytes && isset( self::POSIX_CLASSES[ $key ] ) ) {
			$body = '[:' . $key . ':]';
		} elseif ( false !== strpos( $this->flags, 'i' ) && in_array( $key, array( 'lu', 'll', 'lt', 'upper', 'lower' ), true ) ) {
			// Lu and Ll expand to all cased letters. Lt needs only titlecase letters and their case variants.
			$body = 'lt' === $key && ! $this->bytes ? self::TITLECASE : '\p{L&}';
		} elseif ( isset( self::POSIX_CLASSES[ $key ] ) ) {
			$body = self::POSIX_CLASSES[ $key ];
		} elseif ( preg_match( '/^(?:c[cfnos]?|l[clmotu]?|m[cen]?|n[dlo]?|p[cdefios]?|s[ckmo]?|z[lps]?)$/D', $key ) ) {
			$body = '\p{' . ( 'lc' === $key ? 'L&' : ucfirst( $key ) ) . '}';
		} elseif ( preg_match( '/^[A-Z][a-z]+(?:_[A-Z][a-z]+)*$/D', $name ) && ! in_array( $name, array( 'Any', 'Xan', 'Xps', 'Xsp', 'Xuc', 'Xwd' ), true ) ) {
			// From PCRE 10.40, a bare script name can mean Script_Extensions. Request Script explicitly.
			$body = '\p{' . ( self::has_pcre_10_40() ? 'sc:' : '' ) . $name . '}';
		} else {
			throw new WP_ICU_Regex_Exception( "Unsupported regular expression property: $name", self::UNSUPPORTED );
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
		} elseif ( '[' === $body[0] ) {
			return '[:^' . substr( $body, 2 );
		} elseif ( ! preg_match( '/^\\\\(?:[dhsvw]|p\{(?!Alphabetic)[^}]*\})$/', $body ) ) {
			// Inside a set, PCRE can negate only a single class item. Alphabetic expands
			// to several items on older versions, so reject its negation here on all versions.
			throw new WP_ICU_Regex_Exception( 'Unsupported regular expression syntax: a negated class in a set', self::UNSUPPORTED );
		}
		$body[1] = strtoupper( $body[1] );
		return $body;
	}

	/**
	 * Select a line pattern for the active flags and byte mode.
	 *
	 * @param string $token A dot, a line anchor, or ".*" for a leading-dot start check.
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
		return $this->with_line_ends( self::LINES[ $key ] );
	}

	/**
	 * Adapt a generated line-ending expression for byte mode.
	 *
	 * @param string $fragment The PCRE text of ".", "^", "$", or an escape.
	 * @return string The PCRE text for the current mode.
	 */
	private function with_line_ends( $fragment ) {
		return $this->bytes ? strtr( $fragment, self::BYTE_LINE_ENDS ) : $fragment;
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
			throw new WP_ICU_Regex_Exception( 'Unsupported regular expression: it is too complex.', self::UNSUPPORTED );
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
