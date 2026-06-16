<?php

class WP_MySQL_Lexer extends WP_MySQL_Native_Lexer {
	const SQL_MODE_HIGH_NOT_PRECEDENCE  = 1;
	const SQL_MODE_PIPES_AS_CONCAT      = 2;
	const SQL_MODE_IGNORE_SPACE         = 4;
	const SQL_MODE_NO_BACKSLASH_ESCAPES = 8;
	const SQL_MODE_ANSI_QUOTES          = 16;
}
