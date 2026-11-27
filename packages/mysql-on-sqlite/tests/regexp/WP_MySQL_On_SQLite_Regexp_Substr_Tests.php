<?php

require_once __DIR__ . '/WP_MySQL_On_SQLite_Regexp_Test_Case.php';

/**
 * Check REGEXP_SUBSTR() against MySQL's results for the REGEXP test cases.
 */
class WP_MySQL_On_SQLite_Regexp_Substr_Tests extends WP_MySQL_On_SQLite_Regexp_Test_Case {
	const CHECKS = array( 'regexp_substr' );

	protected function get_known_failures(): array {
		$pcre_version = explode( ' ', PCRE_VERSION )[0];
		$is_pcre2     = version_compare( $pcre_version, '10', '>=' );

		// Cases that fail in every environment.
		$failures = array(
			// MySQL reports a duplicate capture name as 3887. The driver reports a syntax error (3688).
			'icu-regextst:524',
			'icu-regextst:525',

			// PCRE case folding cannot match one character against several, such as "ß" against "ss".
			'icu-regextst:538',
			'icu-regextst:539',
			'icu-regextst:540',
			'icu-regextst:541',
			'icu-regextst:542',
			'icu-regextst:543',
			'icu-regextst:544',
			'icu-regextst:545',
			'icu-regextst:546',
			'icu-regextst:547',
			'icu-regextst:1221',
			'icu-regextst:1222',
			'icu-regextst:1225',
			'icu-regextst:1226',
			'icu-regextst:1227',
			'icu-regextst:1228',
			'icu-regextst:1231',
			'icu-regextst:1232',
			'icu-regextst:1239',
			'icu-regextst:1240',
			'icu-regextst:1241',
			'icu-regextst:1242',
			'icu-regextst:1249',
			'icu-regextst:1250',
			'icu-regextst:1251',
			'icu-regextst:1252',
			'icu-regextst:1253',
			'icu-regextst:1254',
			'icu-regextst:1255',
			'icu-regextst:1256',
			'icu-regextst:1259',
			'icu-regextst:1260',
			'icu-regextst:1263',
			'icu-regextst:1264',

			// Case folding changes the matched text even though both engines find a match.
			'icu-regextst:1243',
			'icu-regextst:1244',
			'icu-regextst:1261',
			'icu-regextst:1262',

			// ICU accepts UTF-16 surrogate escapes. The translation emits each surrogate separately, which PCRE rejects.
			'icu-regextst:715',
			'icu-regextst:716',
			'icu-regextst:717',
			'icu-regextst:718',
			'icu-regextst:719',
			'icu-regextst:720',

			// The translator reads names such as Inbasiclatin and Letter as scripts, which PCRE rejects (3688).
			'icu-regextst:1093',
			'icu-regextst:1094',
			'icu-regextst:1382',
			'icu-regextst:1383',
			'icu-regextst:1434',
			'icu-regextst:1435',

			// MySQL reports error 4007 for oversized repetition counts and 3685 for the property names In and Is.
			'icu-regextst:1334',
			'icu-regextst:1335',
			'icu-regextst:1336',
			'icu-regextst:1337',
			'icu-regextst:1448',
			'icu-regextst:1449',
			'icu-regextst:1450',
			'icu-regextst:1451',

			// The driver normalizes the subject '2002-4-30' to '2002-04-30' before matching.
			'icu-regextst:3508',
			'icu-regextst:3509',
		);

		// PCRE 8 splits some grapheme clusters for \X, like an emoji with a skin tone, which ICU and PCRE2 keep whole.
		if ( ! $is_pcre2 ) {
			array_push(
				$failures,
				'mysql-server-suite:12583',
				'mysql-server-suite:12584'
			);
		}

		// Before PCRE2 10.35, a repeated group with a back reference to itself, like "^(a\1?){4}$", was atomic.
		if ( version_compare( $pcre_version, '10.35', '<' ) ) {
			array_push(
				$failures,
				'mysql-server-suite:6471',
				'mysql-server-suite:6472'
			);
		}

		return $failures;
	}
}
