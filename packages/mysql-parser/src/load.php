<?php
/**
 * Load the MySQL parser and its dependencies.
 */
require_once __DIR__ . '/parser/class-wp-parser-token.php';
require_once __DIR__ . '/parser/class-wp-parser-node.php';
require_once __DIR__ . '/grammar/tokens.php';
require_once __DIR__ . '/class-wp-mysql-token.php';
require_once __DIR__ . '/class-wp-mysql-lexer.php';
require_once __DIR__ . '/class-wp-mysql-parser.php';
