<?php

/**
 * MySQL lexer.
 *
 * An exhaustive lexer for the MySQL SQL dialect. It scans the provided SQL
 * input payload and generates MySQL token objects in the vocabulary of the
 * official MySQL grammar: token ids are the grammar's own token numbers, and
 * the keyword table is generated from MySQL's keyword list (sql/lex.h) — see
 * the generated token data at the top of this class.
 *
 * The lexer is implemented with zero dependencies, it doesn't require any PHP
 * extensions, and it doesn't use PCRE or any other regular expression engines.
 *
 * The token stream matches the one MySQL's own server lexer feeds to its Bison
 * grammar: the "@" sign is a standalone terminal followed by its name, "WITH
 * ROLLUP" is contracted into the internal WITH_ROLLUP_SYM terminal, and the
 * input is terminated with END_OF_INPUT and Bison's end marker. These are
 * produced directly during scanning, as MySQL's lexer does (sql/sql_lex.cc),
 * not by a post-processing pass.
 *
 * The byte-level scanning structure (identifiers, numbers, quoted text,
 * operators) follows the MySQL Workbench lexer. See:
 *   https://github.com/mysql/mysql-workbench/blob/8.0.38/library/parsers/grammars/MySQLLexer.g4
 *   https://github.com/mysql/mysql-workbench/blob/8.0.38/library/parsers/mysql/MySQLBaseLexer.cpp
 */
class WP_MySQL_Lexer {
	/**
	 * Auto-generated MySQL grammar tokens, keywords, and functions.
	 *
	 * The auto-generated block below defines MySQL grammar symbols extracted
	 * from the official MySQL grammar by the "composer run build-grammar" command.
	 * It is important that these symbols are included in the lexer class itself,
	 * so that PHP can inline the token constants for optimal performance.
	 */

	// BEGIN: AUTO-GENERATED MYSQL GRAMMAR SYMBOLS
	// This block is auto-generated from MySQL mysql-8.4.10. DO NOT EDIT!
	// phpcs:disable
	const OPEN_PAR_SYMBOL                = 40;
	const CLOSE_PAR_SYMBOL               = 41;
	const OPEN_CURLY_SYMBOL              = 123;
	const CLOSE_CURLY_SYMBOL             = 125;
	const AT_SIGN_SYMBOL                 = 64;
	const COLON_SYMBOL                   = 58;
	const COMMA_SYMBOL                   = 44;
	const DOT_SYMBOL                     = 46;
	const SEMICOLON_SYMBOL               = 59;
	const BITWISE_AND_OPERATOR           = 38;
	const BITWISE_NOT_OPERATOR           = 126;
	const BITWISE_OR_OPERATOR            = 124;
	const BITWISE_XOR_OPERATOR           = 94;
	const DIV_OPERATOR                   = 47;
	const MINUS_OPERATOR                 = 45;
	const MOD_OPERATOR                   = 37;
	const MULT_OPERATOR                  = 42;
	const PLUS_OPERATOR                  = 43;
	const LOGICAL_NOT_OPERATOR           = 33;
	const EQUAL_OPERATOR                 = 415;
	const NULL_SAFE_EQUAL_OPERATOR       = 416;
	const GREATER_OR_EQUAL_OPERATOR      = 456;
	const GREATER_THAN_OPERATOR          = 469;
	const LESS_OR_EQUAL_OPERATOR         = 522;
	const LESS_THAN_OPERATOR             = 549;
	const NOT_EQUAL_OPERATOR             = 614;
	const ASSIGN_OPERATOR                = 757;
	const LOGICAL_AND_OPERATOR           = 273;
	const LOGICAL_OR_OPERATOR            = 642;
	const CONCAT_PIPES_SYMBOL            = 644;
	const SHIFT_LEFT_OPERATOR            = 759;
	const SHIFT_RIGHT_OPERATOR           = 760;
	const JSON_SEPARATOR_SYMBOL          = 514;
	const JSON_UNQUOTED_SEPARATOR_SYMBOL = 907;
	const PARAM_MARKER                   = 652;
	const IDENTIFIER                     = 482;
	const BACK_TICK_QUOTED_ID            = 484;
	const SINGLE_QUOTED_TEXT             = 827;
	const DOUBLE_QUOTED_TEXT             = 827;
	const NCHAR_TEXT                     = 611;
	const UNDERSCORE_CHARSET             = 852;
	const BIN_NUMBER                     = 292;
	const HEX_NUMBER                     = 474;
	const INT_NUMBER                     = 628;
	const LONG_NUMBER                    = 545;
	const ULONGLONG_NUMBER               = 849;
	const DECIMAL_NUMBER                 = 377;
	const FLOAT_NUMBER                   = 443;
	const NULL2_SYMBOL                   = 627;
	const NOT_SYMBOL                     = 622;
	const NOT2_SYMBOL                    = 621;
	const WITH_SYMBOL                    = 892;
	const ROLLUP_SYMBOL                  = 734;
	const WITH_ROLLUP_SYMBOL             = 894;
	const END_OF_INPUT                   = 411;
	const END_MARKER                     = 0;

	const KEYWORDS = array( '!=' => 614, '&&' => 273, '<' => 549, '<<' => 759, '<=' => 522, '<=>' => 416, '<>' => 614, '=' => 415, '>' => 469, '>=' => 456, '>>' => 760, 'ACCESSIBLE' => 259, 'ACCOUNT' => 260, 'ACTION' => 261, 'ACTIVE' => 973, 'ADD' => 262, 'ADDDATE' => 263, 'ADMIN' => 910, 'AFTER' => 264, 'AGAINST' => 265, 'AGGREGATE' => 266, 'ALGORITHM' => 267, 'ALL' => 268, 'ALTER' => 269, 'ALWAYS' => 270, 'ANALYZE' => 272, 'AND' => 274, 'ANY' => 275, 'ARRAY' => 976, 'AS' => 276, 'ASC' => 277, 'ASCII' => 278, 'ASENSITIVE' => 279, 'ASSIGN_GTIDS_TO_ANONYMOUS_TRANSACTIONS' => 1161, 'AT' => 280, 'ATTRIBUTE' => 1153, 'AUTHENTICATION' => 1191, 'AUTO' => 1211, 'AUTOEXTEND_SIZE' => 281, 'AUTO_INCREMENT' => 282, 'AVG' => 284, 'AVG_ROW_LENGTH' => 283, 'BACKUP' => 285, 'BEFORE' => 286, 'BEGIN' => 287, 'BERNOULLI' => 1213, 'BETWEEN' => 288, 'BIGINT' => 289, 'BINARY' => 290, 'BINLOG' => 291, 'BIT' => 295, 'BIT_AND' => 293, 'BIT_OR' => 294, 'BIT_XOR' => 296, 'BLOB' => 297, 'BLOCK' => 298, 'BOOL' => 300, 'BOOLEAN' => 299, 'BOTH' => 301, 'BTREE' => 302, 'BUCKETS' => 929, 'BULK' => 1201, 'BY' => 303, 'BYTE' => 304, 'CACHE' => 305, 'CALL' => 306, 'CASCADE' => 307, 'CASCADED' => 308, 'CASE' => 309, 'CAST' => 310, 'CATALOG_NAME' => 311, 'CHAIN' => 312, 'CHALLENGE_RESPONSE' => 1198, 'CHANGE' => 313, 'CHANGED' => 314, 'CHANNEL' => 315, 'CHAR' => 317, 'CHARACTER' => 317, 'CHARSET' => 316, 'CHECK' => 319, 'CHECKSUM' => 318, 'CIPHER' => 320, 'CLASS_ORIGIN' => 321, 'CLIENT' => 322, 'CLONE' => 931, 'CLOSE' => 323, 'COALESCE' => 324, 'CODE' => 325, 'COLLATE' => 326, 'COLLATION' => 327, 'COLUMN' => 329, 'COLUMNS' => 328, 'COLUMN_FORMAT' => 330, 'COLUMN_NAME' => 331, 'COMMENT' => 332, 'COMMIT' => 334, 'COMMITTED' => 333, 'COMPACT' => 335, 'COMPLETION' => 336, 'COMPONENT' => 914, 'COMPRESSED' => 337, 'COMPRESSION' => 338, 'CONCURRENT' => 340, 'CONDITION' => 341, 'CONNECTION' => 342, 'CONSISTENT' => 343, 'CONSTRAINT' => 344, 'CONSTRAINT_CATALOG' => 345, 'CONSTRAINT_NAME' => 346, 'CONSTRAINT_SCHEMA' => 347, 'CONTAINS' => 348, 'CONTEXT' => 349, 'CONTINUE' => 350, 'CONVERT' => 351, 'COUNT' => 352, 'CPU' => 353, 'CREATE' => 354, 'CROSS' => 355, 'CUBE' => 356, 'CUME_DIST' => 932, 'CURDATE' => 357, 'CURRENT' => 358, 'CURRENT_DATE' => 357, 'CURRENT_TIME' => 362, 'CURRENT_TIMESTAMP' => 623, 'CURRENT_USER' => 359, 'CURSOR' => 360, 'CURSOR_NAME' => 361, 'CURTIME' => 362, 'DATA' => 366, 'DATABASE' => 363, 'DATABASES' => 364, 'DATAFILE' => 365, 'DATE' => 370, 'DATETIME' => 367, 'DATE_ADD' => 368, 'DATE_SUB' => 369, 'DAY' => 375, 'DAY_HOUR' => 371, 'DAY_MICROSECOND' => 372, 'DAY_MINUTE' => 373, 'DAY_SECOND' => 374, 'DEALLOCATE' => 376, 'DEC' => 378, 'DECIMAL' => 378, 'DECLARE' => 379, 'DEFAULT' => 380, 'DEFAULT_AUTH' => 381, 'DEFINER' => 382, 'DEFINITION' => 969, 'DELAYED' => 383, 'DELAY_KEY_WRITE' => 384, 'DELETE' => 385, 'DENSE_RANK' => 933, 'DESC' => 386, 'DESCRIBE' => 387, 'DESCRIPTION' => 970, 'DETERMINISTIC' => 389, 'DIAGNOSTICS' => 390, 'DIRECTORY' => 391, 'DISABLE' => 392, 'DISCARD' => 393, 'DISK' => 394, 'DISTINCT' => 395, 'DISTINCTROW' => 395, 'DIV' => 396, 'DO' => 398, 'DOUBLE' => 397, 'DROP' => 399, 'DUAL' => 400, 'DUMPFILE' => 401, 'DUPLICATE' => 402, 'DYNAMIC' => 403, 'EACH' => 404, 'ELSE' => 405, 'ELSEIF' => 406, 'EMPTY' => 954, 'ENABLE' => 407, 'ENCLOSED' => 408, 'ENCRYPTION' => 339, 'END' => 409, 'ENDS' => 410, 'ENFORCED' => 985, 'ENGINE' => 413, 'ENGINES' => 412, 'ENGINE_ATTRIBUTE' => 1154, 'ENUM' => 414, 'ERROR' => 417, 'ERRORS' => 418, 'ESCAPE' => 420, 'ESCAPED' => 419, 'EVENT' => 422, 'EVENTS' => 421, 'EVERY' => 423, 'EXCEPT' => 913, 'EXCHANGE' => 424, 'EXCLUDE' => 934, 'EXECUTE' => 425, 'EXISTS' => 426, 'EXIT' => 427, 'EXPANSION' => 428, 'EXPIRE' => 429, 'EXPLAIN' => 387, 'EXPORT' => 430, 'EXTENDED' => 431, 'EXTENT_SIZE' => 432, 'EXTRACT' => 433, 'FACTOR' => 1192, 'FAILED_LOGIN_ATTEMPTS' => 995, 'FALSE' => 434, 'FAST' => 435, 'FAULTS' => 436, 'FETCH' => 437, 'FIELDS' => 328, 'FILE' => 438, 'FILE_BLOCK_SIZE' => 439, 'FILTER' => 440, 'FINISH' => 1193, 'FIRST' => 441, 'FIRST_VALUE' => 935, 'FIXED' => 442, 'FLOAT' => 444, 'FLOAT4' => 444, 'FLOAT8' => 397, 'FLUSH' => 445, 'FOLLOWING' => 936, 'FOLLOWS' => 446, 'FOR' => 449, 'FORCE' => 447, 'FOREIGN' => 448, 'FORMAT' => 450, 'FOUND' => 451, 'FROM' => 452, 'FULL' => 453, 'FULLTEXT' => 454, 'FUNCTION' => 455, 'GENERAL' => 457, 'GENERATE' => 1203, 'GENERATED' => 458, 'GEOMCOLLECTION' => 460, 'GEOMETRY' => 461, 'GEOMETRYCOLLECTION' => 460, 'GET' => 463, 'GET_FORMAT' => 462, 'GET_SOURCE_PUBLIC_KEY' => 1162, 'GLOBAL' => 464, 'GRANT' => 465, 'GRANTS' => 466, 'GROUP' => 467, 'GROUPING' => 926, 'GROUPS' => 937, 'GROUP_CONCAT' => 468, 'GROUP_REPLICATION' => 459, 'GTIDS' => 1207, 'GTID_ONLY' => 1199, 'HANDLER' => 470, 'HASH' => 471, 'HAVING' => 472, 'HELP' => 473, 'HIGH_PRIORITY' => 475, 'HISTOGRAM' => 928, 'HISTORY' => 959, 'HOST' => 476, 'HOSTS' => 477, 'HOUR' => 481, 'HOUR_MICROSECOND' => 478, 'HOUR_MINUTE' => 479, 'HOUR_SECOND' => 480, 'IDENTIFIED' => 483, 'IF' => 485, 'IGNORE' => 486, 'IGNORE_SERVER_IDS' => 487, 'IMPORT' => 488, 'IN' => 504, 'INACTIVE' => 974, 'INDEX' => 490, 'INDEXES' => 489, 'INFILE' => 491, 'INITIAL' => 1197, 'INITIAL_SIZE' => 492, 'INITIATE' => 1194, 'INNER' => 493, 'INOUT' => 494, 'INSENSITIVE' => 495, 'INSERT' => 496, 'INSERT_METHOD' => 497, 'INSTALL' => 499, 'INSTANCE' => 498, 'INT' => 502, 'INT1' => 836, 'INT2' => 768, 'INT3' => 584, 'INT4' => 502, 'INT8' => 289, 'INTEGER' => 502, 'INTERSECT' => 1200, 'INTERVAL' => 500, 'INTO' => 501, 'INVISIBLE' => 911, 'INVOKER' => 503, 'IO' => 507, 'IO_AFTER_GTIDS' => 505, 'IO_BEFORE_GTIDS' => 506, 'IO_THREAD' => 702, 'IPC' => 508, 'IS' => 509, 'ISOLATION' => 510, 'ISSUER' => 511, 'ITERATE' => 512, 'JOIN' => 513, 'JSON' => 515, 'JSON_ARRAYAGG' => 921, 'JSON_OBJECTAGG' => 920, 'JSON_TABLE' => 955, 'JSON_VALUE' => 1151, 'KEY' => 518, 'KEYRING' => 1190, 'KEYS' => 516, 'KEY_BLOCK_SIZE' => 517, 'KILL' => 519, 'LAG' => 938, 'LANGUAGE' => 520, 'LAST' => 521, 'LAST_VALUE' => 939, 'LATERAL' => 975, 'LEAD' => 940, 'LEADING' => 523, 'LEAVE' => 525, 'LEAVES' => 524, 'LEFT' => 526, 'LESS' => 527, 'LEVEL' => 528, 'LIKE' => 530, 'LIMIT' => 531, 'LINEAR' => 532, 'LINES' => 533, 'LINESTRING' => 534, 'LIST' => 535, 'LOAD' => 536, 'LOCAL' => 537, 'LOCALTIME' => 623, 'LOCALTIMESTAMP' => 623, 'LOCK' => 540, 'LOCKED' => 924, 'LOCKS' => 539, 'LOG' => 1206, 'LOGFILE' => 541, 'LOGS' => 542, 'LONG' => 546, 'LONGBLOB' => 543, 'LONGTEXT' => 544, 'LOOP' => 547, 'LOW_PRIORITY' => 548, 'MANUAL' => 1212, 'MASTER' => 571, 'MATCH' => 574, 'MAX' => 579, 'MAXVALUE' => 582, 'MAX_CONNECTIONS_PER_HOUR' => 575, 'MAX_QUERIES_PER_HOUR' => 576, 'MAX_ROWS' => 577, 'MAX_SIZE' => 578, 'MAX_UPDATES_PER_HOUR' => 580, 'MAX_USER_CONNECTIONS' => 581, 'MEDIUM' => 586, 'MEDIUMBLOB' => 583, 'MEDIUMINT' => 584, 'MEDIUMTEXT' => 585, 'MEMBER' => 977, 'MEMORY' => 587, 'MERGE' => 588, 'MESSAGE_TEXT' => 589, 'MICROSECOND' => 590, 'MID' => 811, 'MIDDLEINT' => 584, 'MIGRATE' => 591, 'MIN' => 596, 'MINUTE' => 594, 'MINUTE_MICROSECOND' => 592, 'MINUTE_SECOND' => 593, 'MIN_ROWS' => 595, 'MOD' => 600, 'MODE' => 597, 'MODIFIES' => 598, 'MODIFY' => 599, 'MONTH' => 601, 'MULTILINESTRING' => 602, 'MULTIPOINT' => 603, 'MULTIPOLYGON' => 604, 'MUTEX' => 605, 'MYSQL_ERRNO' => 606, 'NAME' => 608, 'NAMES' => 607, 'NATIONAL' => 609, 'NATURAL' => 610, 'NCHAR' => 612, 'NDB' => 613, 'NDBCLUSTER' => 613, 'NESTED' => 956, 'NETWORK_NAMESPACE' => 987, 'NEVER' => 616, 'NEW' => 617, 'NEXT' => 618, 'NO' => 624, 'NODEGROUP' => 619, 'NONE' => 620, 'NOT' => 622, 'NOW' => 623, 'NOWAIT' => 925, 'NO_WAIT' => 625, 'NO_WRITE_TO_BINLOG' => 626, 'NTH_VALUE' => 941, 'NTILE' => 942, 'NULL' => 627, 'NULLS' => 943, 'NUMBER' => 629, 'NUMERIC' => 630, 'NVARCHAR' => 631, 'OF' => 922, 'OFF' => 998, 'OFFSET' => 632, 'OJ' => 986, 'OLD' => 984, 'ON' => 633, 'ONE' => 634, 'ONLY' => 635, 'OPEN' => 636, 'OPTIMIZE' => 637, 'OPTIMIZER_COSTS' => 638, 'OPTION' => 640, 'OPTIONAL' => 978, 'OPTIONALLY' => 641, 'OPTIONS' => 639, 'OR' => 645, 'ORDER' => 643, 'ORDINALITY' => 957, 'ORGANIZATION' => 971, 'OTHERS' => 944, 'OUT' => 648, 'OUTER' => 646, 'OUTFILE' => 647, 'OVER' => 945, 'OWNER' => 649, 'PACK_KEYS' => 650, 'PAGE' => 651, 'PARALLEL' => 1208, 'PARSER' => 653, 'PARSE_TREE' => 1205, 'PARTIAL' => 655, 'PARTITION' => 656, 'PARTITIONING' => 658, 'PARTITIONS' => 657, 'PASSWORD' => 659, 'PASSWORD_LOCK_TIME' => 994, 'PATH' => 958, 'PERCENT_RANK' => 946, 'PERSIST' => 908, 'PERSIST_ONLY' => 927, 'PHASE' => 660, 'PLUGIN' => 662, 'PLUGINS' => 663, 'PLUGIN_DIR' => 661, 'POINT' => 664, 'POLYGON' => 665, 'PORT' => 666, 'POSITION' => 667, 'PRECEDES' => 668, 'PRECEDING' => 947, 'PRECISION' => 669, 'PREPARE' => 670, 'PRESERVE' => 671, 'PREV' => 672, 'PRIMARY' => 673, 'PRIVILEGES' => 674, 'PRIVILEGE_CHECKS_USER' => 991, 'PROCEDURE' => 675, 'PROCESS' => 676, 'PROCESSLIST' => 677, 'PROFILE' => 678, 'PROFILES' => 679, 'PROXY' => 680, 'PURGE' => 681, 'QUALIFY' => 1210, 'QUARTER' => 682, 'QUERY' => 683, 'QUICK' => 684, 'RANDOM' => 988, 'RANGE' => 685, 'RANK' => 948, 'READ' => 688, 'READS' => 686, 'READ_ONLY' => 687, 'READ_WRITE' => 689, 'REAL' => 690, 'REBUILD' => 691, 'RECOVER' => 692, 'RECURSIVE' => 915, 'REDO_BUFFER_SIZE' => 694, 'REDUNDANT' => 695, 'REFERENCE' => 972, 'REFERENCES' => 696, 'REGEXP' => 697, 'REGISTRATION' => 1195, 'RELAY' => 698, 'RELAYLOG' => 699, 'RELAY_LOG_FILE' => 700, 'RELAY_LOG_POS' => 701, 'RELAY_THREAD' => 702, 'RELEASE' => 703, 'RELOAD' => 704, 'REMOVE' => 705, 'RENAME' => 706, 'REORGANIZE' => 707, 'REPAIR' => 708, 'REPEAT' => 710, 'REPEATABLE' => 709, 'REPLACE' => 711, 'REPLICA' => 1159, 'REPLICAS' => 1160, 'REPLICATE_DO_DB' => 713, 'REPLICATE_DO_TABLE' => 715, 'REPLICATE_IGNORE_DB' => 714, 'REPLICATE_IGNORE_TABLE' => 716, 'REPLICATE_REWRITE_DB' => 719, 'REPLICATE_WILD_DO_TABLE' => 717, 'REPLICATE_WILD_IGNORE_TABLE' => 718, 'REPLICATION' => 712, 'REQUIRE' => 720, 'REQUIRE_ROW_FORMAT' => 993, 'REQUIRE_TABLE_PRIMARY_KEY_CHECK' => 996, 'RESET' => 721, 'RESIGNAL' => 722, 'RESOURCE' => 963, 'RESPECT' => 949, 'RESTART' => 968, 'RESTORE' => 724, 'RESTRICT' => 725, 'RESUME' => 726, 'RETAIN' => 983, 'RETURN' => 729, 'RETURNED_SQLSTATE' => 727, 'RETURNING' => 999, 'RETURNS' => 728, 'REUSE' => 960, 'REVERSE' => 730, 'REVOKE' => 731, 'RIGHT' => 732, 'RLIKE' => 697, 'ROLE' => 909, 'ROLLBACK' => 733, 'ROLLUP' => 734, 'ROTATE' => 735, 'ROUTINE' => 736, 'ROW' => 739, 'ROWS' => 737, 'ROW_COUNT' => 740, 'ROW_FORMAT' => 738, 'ROW_NUMBER' => 950, 'RTREE' => 741, 'S3' => 1209, 'SAVEPOINT' => 742, 'SCHEDULE' => 743, 'SCHEMA' => 363, 'SCHEMAS' => 364, 'SCHEMA_NAME' => 744, 'SECOND' => 746, 'SECONDARY' => 979, 'SECONDARY_ENGINE' => 980, 'SECONDARY_ENGINE_ATTRIBUTE' => 1155, 'SECONDARY_LOAD' => 981, 'SECONDARY_UNLOAD' => 982, 'SECOND_MICROSECOND' => 745, 'SECURITY' => 747, 'SELECT' => 748, 'SENSITIVE' => 749, 'SEPARATOR' => 750, 'SERIAL' => 752, 'SERIALIZABLE' => 751, 'SERVER' => 754, 'SESSION' => 753, 'SESSION_USER' => 867, 'SET' => 756, 'SHARE' => 758, 'SHOW' => 761, 'SHUTDOWN' => 762, 'SIGNAL' => 763, 'SIGNED' => 764, 'SIMPLE' => 765, 'SKIP' => 923, 'SLAVE' => 766, 'SLOW' => 767, 'SMALLINT' => 768, 'SNAPSHOT' => 769, 'SOCKET' => 770, 'SOME' => 275, 'SONAME' => 771, 'SOUNDS' => 772, 'SOURCE' => 773, 'SOURCE_AUTO_POSITION' => 1163, 'SOURCE_BIND' => 1164, 'SOURCE_COMPRESSION_ALGORITHMS' => 1165, 'SOURCE_CONNECTION_AUTO_FAILOVER' => 1156, 'SOURCE_CONNECT_RETRY' => 1166, 'SOURCE_DELAY' => 1167, 'SOURCE_HEARTBEAT_PERIOD' => 1168, 'SOURCE_HOST' => 1169, 'SOURCE_LOG_FILE' => 1170, 'SOURCE_LOG_POS' => 1171, 'SOURCE_PASSWORD' => 1172, 'SOURCE_PORT' => 1173, 'SOURCE_PUBLIC_KEY_PATH' => 1174, 'SOURCE_RETRY_COUNT' => 1175, 'SOURCE_SSL' => 1176, 'SOURCE_SSL_CA' => 1177, 'SOURCE_SSL_CAPATH' => 1178, 'SOURCE_SSL_CERT' => 1179, 'SOURCE_SSL_CIPHER' => 1180, 'SOURCE_SSL_CRL' => 1181, 'SOURCE_SSL_CRLPATH' => 1182, 'SOURCE_SSL_KEY' => 1183, 'SOURCE_SSL_VERIFY_SERVER_CERT' => 1184, 'SOURCE_TLS_CIPHERSUITES' => 1185, 'SOURCE_TLS_VERSION' => 1186, 'SOURCE_USER' => 1187, 'SOURCE_ZSTD_COMPRESSION_LEVEL' => 1188, 'SPATIAL' => 774, 'SPECIFIC' => 775, 'SQL' => 788, 'SQLEXCEPTION' => 776, 'SQLSTATE' => 777, 'SQLWARNING' => 778, 'SQL_AFTER_GTIDS' => 779, 'SQL_AFTER_MTS_GAPS' => 780, 'SQL_BEFORE_GTIDS' => 781, 'SQL_BIG_RESULT' => 782, 'SQL_BUFFER_RESULT' => 783, 'SQL_CALC_FOUND_ROWS' => 785, 'SQL_NO_CACHE' => 786, 'SQL_SMALL_RESULT' => 787, 'SQL_THREAD' => 789, 'SQL_TSI_DAY' => 375, 'SQL_TSI_HOUR' => 481, 'SQL_TSI_MINUTE' => 594, 'SQL_TSI_MONTH' => 601, 'SQL_TSI_QUARTER' => 682, 'SQL_TSI_SECOND' => 746, 'SQL_TSI_WEEK' => 887, 'SQL_TSI_YEAR' => 905, 'SRID' => 961, 'SSL' => 790, 'STACKED' => 791, 'START' => 794, 'STARTING' => 792, 'STARTS' => 793, 'STATS_AUTO_RECALC' => 795, 'STATS_PERSISTENT' => 796, 'STATS_SAMPLE_PAGES' => 797, 'STATUS' => 798, 'STD' => 800, 'STDDEV' => 800, 'STDDEV_POP' => 800, 'STDDEV_SAMP' => 799, 'STOP' => 801, 'STORAGE' => 802, 'STORED' => 803, 'STRAIGHT_JOIN' => 804, 'STREAM' => 997, 'STRING' => 805, 'ST_COLLECT' => 1189, 'SUBCLASS_ORIGIN' => 806, 'SUBDATE' => 807, 'SUBJECT' => 808, 'SUBPARTITION' => 810, 'SUBPARTITIONS' => 809, 'SUBSTR' => 811, 'SUBSTRING' => 811, 'SUM' => 812, 'SUPER' => 813, 'SUSPEND' => 814, 'SWAPS' => 815, 'SWITCHES' => 816, 'SYSDATE' => 817, 'SYSTEM' => 964, 'SYSTEM_USER' => 867, 'TABLE' => 821, 'TABLES' => 818, 'TABLESAMPLE' => 1214, 'TABLESPACE' => 819, 'TABLE_CHECKSUM' => 822, 'TABLE_NAME' => 823, 'TEMPORARY' => 824, 'TEMPTABLE' => 825, 'TERMINATED' => 826, 'TEXT' => 828, 'THAN' => 829, 'THEN' => 830, 'THREAD_PRIORITY' => 962, 'TIES' => 951, 'TIME' => 834, 'TIMESTAMP' => 831, 'TIMESTAMPADD' => 832, 'TIMESTAMPDIFF' => 833, 'TINYBLOB' => 835, 'TINYINT' => 836, 'TINYTEXT' => 837, 'TLS' => 1152, 'TO' => 838, 'TRAILING' => 839, 'TRANSACTION' => 840, 'TRIGGER' => 842, 'TRIGGERS' => 841, 'TRIM' => 843, 'TRUE' => 844, 'TRUNCATE' => 845, 'TYPE' => 847, 'TYPES' => 846, 'UNBOUNDED' => 952, 'UNCOMMITTED' => 850, 'UNDEFINED' => 851, 'UNDO' => 855, 'UNDOFILE' => 853, 'UNDO_BUFFER_SIZE' => 854, 'UNICODE' => 856, 'UNINSTALL' => 857, 'UNION' => 858, 'UNIQUE' => 859, 'UNKNOWN' => 860, 'UNLOCK' => 861, 'UNREGISTER' => 1196, 'UNSIGNED' => 862, 'UNTIL' => 863, 'UPDATE' => 864, 'UPGRADE' => 865, 'URL' => 1202, 'USAGE' => 866, 'USE' => 869, 'USER' => 867, 'USER_RESOURCES' => 723, 'USE_FRM' => 868, 'USING' => 870, 'UTC_DATE' => 871, 'UTC_TIME' => 873, 'UTC_TIMESTAMP' => 872, 'VALIDATION' => 874, 'VALUE' => 876, 'VALUES' => 875, 'VARBINARY' => 877, 'VARCHAR' => 878, 'VARCHARACTER' => 878, 'VARIABLES' => 879, 'VARIANCE' => 880, 'VARYING' => 881, 'VAR_POP' => 880, 'VAR_SAMP' => 882, 'VCPU' => 965, 'VIEW' => 883, 'VIRTUAL' => 884, 'VISIBLE' => 912, 'WAIT' => 885, 'WARNINGS' => 886, 'WEEK' => 887, 'WEIGHT_STRING' => 888, 'WHEN' => 889, 'WHERE' => 890, 'WHILE' => 891, 'WINDOW' => 953, 'WITH' => 892, 'WITHOUT' => 895, 'WORK' => 896, 'WRAPPER' => 897, 'WRITE' => 898, 'X509' => 899, 'XA' => 900, 'XID' => 901, 'XML' => 902, 'XOR' => 903, 'YEAR' => 905, 'YEAR_MONTH' => 904, 'ZEROFILL' => 906, 'ZONE' => 1157, '||' => 644 );

	const FUNCTIONS = array( 'ADDDATE' => true, 'BIT_AND' => true, 'BIT_OR' => true, 'BIT_XOR' => true, 'CAST' => true, 'COUNT' => true, 'CURDATE' => true, 'CURTIME' => true, 'DATE_ADD' => true, 'DATE_SUB' => true, 'EXTRACT' => true, 'GROUP_CONCAT' => true, 'JSON_ARRAYAGG' => true, 'JSON_OBJECTAGG' => true, 'MAX' => true, 'MID' => true, 'MIN' => true, 'NOW' => true, 'POSITION' => true, 'SESSION_USER' => true, 'STD' => true, 'STDDEV' => true, 'STDDEV_POP' => true, 'STDDEV_SAMP' => true, 'ST_COLLECT' => true, 'SUBDATE' => true, 'SUBSTR' => true, 'SUBSTRING' => true, 'SUM' => true, 'SYSDATE' => true, 'SYSTEM_USER' => true, 'TRIM' => true, 'VARIANCE' => true, 'VAR_POP' => true, 'VAR_SAMP' => true );
	// phpcs:enable
	// END: AUTO-GENERATED MYSQL GRAMMAR SYMBOLS

	/**
	 * The SQL modes that affect the lexer behavior.
	 *
	 * These values are intended to be used in a bitmask. See "$this->sql_modes".
	 * The list of the SQL modes is not exhaustive. Only the ones that influence
	 * the lexer behavior are included in this list.
	 *
	 * See:
	 *   https://dev.mysql.com/doc/refman/8.4/en/sql-mode.html
	 */
	const SQL_MODE_HIGH_NOT_PRECEDENCE  = 1;
	const SQL_MODE_PIPES_AS_CONCAT      = 2;
	const SQL_MODE_IGNORE_SPACE         = 4;
	const SQL_MODE_NO_BACKSLASH_ESCAPES = 8;
	const SQL_MODE_ANSI_QUOTES          = 16;

	/**
	 * Character masks for frequently used character classes.
	 *
	 * These are intended to be used with "strspn()" and "strcspn()" functions
	 * for fast character class matching in the SQL payload.
	 */
	private const WHITESPACE_MASK = " \t\n\r\f";
	private const DIGIT_MASK      = '0123456789';
	private const HEX_DIGIT_MASK  = '0123456789abcdefABCDEF';

	/**
	 * Internal scanner sentinels.
	 *
	 * These values are produced and consumed inside the scanner only; they
	 * never appear in the emitted token stream, which uses the grammar token
	 * numbers from the generated token data above. They are negative so they
	 * can never collide with a grammar token number.
	 */
	private const EOF        = -1;
	private const WHITESPACE = -2;

	// Comments.
	private const COMMENT             = -3;
	private const MYSQL_COMMENT_START = -4;
	private const MYSQL_COMMENT_END   = -5;

	/**
	 * Identifier-like strings that may represent underscore-prefixed charset names.
	 *
	 * Includes charsets from both MySQL 5 and 8; via "SHOW CHARACTER SET"/docs:
	 *   https://dev.mysql.com/doc/refman/5.7/en/charset-charsets.html
	 *   https://dev.mysql.com/doc/refman/8.4/en/charset-charsets.html
	 *
	 * @TODO: Make the list respect the MySQL version. The _utf8 underscore charset
	 *        exists only on MySQL 5, and maybe some others are version-dependant too.
	 *        We can check this using SHOW CHARACTER SET on different MySQL versions.
	 */
	private const UNDERSCORE_CHARSETS = array(
		'_armscii8' => true,
		'_ascii'    => true,
		'_big5'     => true,
		'_binary'   => true,
		'_cp1250'   => true,
		'_cp1251'   => true,
		'_cp1256'   => true,
		'_cp1257'   => true,
		'_cp850'    => true,
		'_cp852'    => true,
		'_cp866'    => true,
		'_cp932'    => true,
		'_dec8'     => true,
		'_eucjpms'  => true,
		'_euckr'    => true,
		'_gb18030'  => true,
		'_gb2312'   => true,
		'_gbk'      => true,
		'_geostd8'  => true,
		'_greek'    => true,
		'_hebrew'   => true,
		'_hp8'      => true,
		'_keybcs2'  => true,
		'_koi8r'    => true,
		'_koi8u'    => true,
		'_latin1'   => true,
		'_latin2'   => true,
		'_latin5'   => true,
		'_latin7'   => true,
		'_macce'    => true,
		'_macroman' => true,
		'_sjis'     => true,
		'_swe7'     => true,
		'_tis620'   => true,
		'_ucs2'     => true,
		'_ujis'     => true,
		'_utf16'    => true,
		'_utf16le'  => true,
		'_utf32'    => true,
		'_utf8'     => true,
		'_utf8mb3'  => true,
		'_utf8mb4'  => true,
	);

	/**
	 * The SQL payload to tokenize.
	 *
	 * @var string
	 */
	private $sql;

	/**
	 * Byte length of the SQL payload.
	 *
	 * @var int
	 */
	private $sql_length;

	/**
	 * The version of the MySQL server that the SQL payload is intended for.
	 *
	 * This is used to determine which tokens are valid for the given MySQL
	 * version, and how some tokens should be interpreted.
	 *
	 * @var int
	 */
	private $mysql_version;

	/**
	 * The SQL modes that should be considered active during tokenization.
	 *
	 * This is an integer that represents currently active SQL modes as a bitmask.
	 * The SQL modes are defined as "SQL_MODE_"-prefixed constants in this class.
	 * The list of the SQL modes isn't exhaustive, as only some affect tokenization.
	 *
	 * @var int
	 */
	private $sql_modes = 0;

	/**
	 * How many bytes from the original SQL payload have been read and tokenized.
	 *
	 * This is an internal cursor that is used to track the current position in
	 * the SQL payload during tokenization. When used as an index in the SQL
	 * payload, it points to the next byte to read.
	 *
	 * @var int
	 */
	private $bytes_already_read = 0;

	/**
	 * Byte offset in the SQL payload where current token starts.
	 *
	 * This is used to extract the token bytes after the token is processed.
	 * The bytes of the current token are represented by "$this->sql" in range
	 * from "$this->token_starts_at" to "$this->bytes_already_read - 1".
	 *
	 * @var int
	 */
	private $token_starts_at = 0;

	/**
	 * The type of the current token.
	 *
	 * When a token is successfully recognized and read, this value is set to the
	 * constant representing the token type. When no token was read yet, or the
	 * end of the SQL payload or an invalid token is reached, this value is null.
	 *
	 * @var int|null
	 */
	private $token_type;

	/**
	 * Whether the tokenizer is inside an active MySQL-specific comment.
	 *
	 * MySQL supports a special comment syntax whose content is recognized as
	 * a comment by most database engines, but can be treated as SQL by MySQL:
	 *
	 *  1. /*! ...  - The content is treated as SQL.
	 *  2. /*!12345 - The content is treated as SQL when "MySQL version >= 12345".
	 *
	 * @var bool
	 */
	private $in_mysql_comment = false;

	/**
	 * Whether the NO_BACKSLASH_ESCAPES SQL mode is active, cached for the tokens.
	 *
	 * @var bool
	 */
	private $no_backslash_escapes = false;

	/**
	 * A grammar token produced ahead of the cursor, waiting to be emitted.
	 *
	 * A single scan step can yield two grammar tokens (e.g. "@" plus its name,
	 * or the two end terminals); next_token() returns the first and holds the
	 * second here for the following call.
	 *
	 * @var WP_MySQL_Token|null
	 */
	private $pending_token = null;

	/**
	 * A scanned-ahead grammar token held back to be processed on the next step.
	 *
	 * Contracting "WITH ROLLUP" needs a one-token lookahead (as in MySQL's
	 * lexer). When the token after "WITH" is not "ROLLUP", it is stashed here
	 * as [ type, start, length ] so the next scan step reprocesses it.
	 *
	 * @var array{0:int,1:int,2:int}|null
	 */
	private $lookahead = null;

	/**
	 * Whether the token stream has ended (end markers emitted, or invalid input).
	 *
	 * @var bool
	 */
	private $stream_ended = false;

	/**
	 * The token at the cursor, returned by get_token().
	 *
	 * @var WP_MySQL_Token|null
	 */
	private $current_token = null;

	/**
	 * @param string $sql The SQL payload to tokenize.
	 * @param int $mysql_version The version of the MySQL server that the SQL payload is intended for.
	 * @param string[] $sql_modes The SQL modes that should be considered active during tokenization.
	 */
	public function __construct(
		string $sql,
		int $mysql_version = 80038,
		array $sql_modes = array()
	) {
		$this->sql           = $sql;
		$this->sql_length    = strlen( $sql );
		$this->mysql_version = $mysql_version;

		foreach ( $sql_modes as $sql_mode ) {
			$sql_mode = strtoupper( $sql_mode );
			if ( 'HIGH_NOT_PRECEDENCE' === $sql_mode ) {
				$this->sql_modes |= self::SQL_MODE_HIGH_NOT_PRECEDENCE;
			} elseif ( 'PIPES_AS_CONCAT' === $sql_mode ) {
				$this->sql_modes |= self::SQL_MODE_PIPES_AS_CONCAT;
			} elseif ( 'IGNORE_SPACE' === $sql_mode ) {
				$this->sql_modes |= self::SQL_MODE_IGNORE_SPACE;
			} elseif ( 'NO_BACKSLASH_ESCAPES' === $sql_mode ) {
				$this->sql_modes |= self::SQL_MODE_NO_BACKSLASH_ESCAPES;
			} elseif ( 'ANSI_QUOTES' === $sql_mode ) {
				$this->sql_modes |= self::SQL_MODE_ANSI_QUOTES;
			} elseif ( 'ANSI' === $sql_mode ) {
				/*
				 * Expand the composite ANSI mode into its lexer-relevant components.
				 * The ANSI mode also implies REAL_AS_FLOAT and ONLY_FULL_GROUP_BY,
				 * which do not affect the lexer.
				 *
				 * See: https://dev.mysql.com/doc/refman/8.4/en/sql-mode.html#sqlmode_ansi
				 */
				$this->sql_modes |= self::SQL_MODE_PIPES_AS_CONCAT
					| self::SQL_MODE_IGNORE_SPACE
					| self::SQL_MODE_ANSI_QUOTES;
			}
		}

		$this->no_backslash_escapes = $this->is_sql_mode_active( self::SQL_MODE_NO_BACKSLASH_ESCAPES );
	}

	/**
	 * Advance to the next token in the MySQL grammar token stream.
	 *
	 * The lexer is a pull iterator over the grammar's token stream: each call
	 * advances the cursor by one token, which get_token() then returns. The
	 * stream ends with END_OF_INPUT and Bison's end marker; on invalid input it
	 * ends early, right after the last valid token, with no terminators.
	 *
	 * @return bool Whether a token is available at the new cursor position.
	 */
	public function next_token(): bool {
		if ( null !== $this->pending_token ) {
			$this->current_token = $this->pending_token;
			$this->pending_token = null;
			return true;
		}
		if ( $this->stream_ended ) {
			$this->current_token = null;
			return false;
		}

		// A scan step yields one or two grammar tokens: return the first and
		// hold the second (if any) back for the next call.
		$out = array();
		$this->produce( $out );
		if ( ! $out ) {
			$this->current_token = null;
			return false;
		}
		$this->current_token = $out[0];
		if ( isset( $out[1] ) ) {
			$this->pending_token = $out[1];
		}
		return true;
	}

	/**
	 * Return the token at the current cursor position.
	 *
	 * Returns the same object until the cursor advances, and null before the
	 * first next_token() call or once the stream is exhausted.
	 *
	 * @return WP_MySQL_Token|null The current token, or null when there is none.
	 */
	public function get_token(): ?WP_MySQL_Token {
		return $this->current_token;
	}

	/**
	 * Read all remaining tokens as an array, draining the stream.
	 *
	 * From a fresh lexer this runs a tight scan-and-emit loop that bypasses the
	 * pull iterator's per-token queue bookkeeping — the dominant cost when
	 * tokenizing a whole statement. The common single-token lexemes are emitted
	 * inline; only the multi-token ones (end markers, "WITH ROLLUP", "@") route
	 * through the buffered producers. A lexer already advanced by next_token()
	 * falls back to the iterator to stay correct.
	 *
	 * @return WP_MySQL_Token[] The remaining tokens — terminated by END_OF_INPUT
	 *                          and the end marker, or a partial list with no
	 *                          terminators on invalid input.
	 */
	public function remaining_tokens(): array {
		if ( $this->stream_ended || null !== $this->pending_token || null !== $this->current_token ) {
			$tokens = array();
			while ( $this->next_token() ) {
				$tokens[] = $this->current_token;
			}
			return $tokens;
		}

		$tokens = array();
		$sql    = $this->sql;
		$nbe    = $this->no_backslash_escapes;
		while ( true ) {
			if ( null !== $this->lookahead ) {
				list( $type, $start, $length ) = $this->lookahead;
				$this->lookahead               = null;
			} else {
				// Inlined scan_lexeme(): skip whitespace and comments, then scan.
				$this->bytes_already_read += strspn( $sql, self::WHITESPACE_MASK, $this->bytes_already_read );
				do {
					$this->token_starts_at = $this->bytes_already_read;
					$this->token_type      = $this->read_next_token();
				} while (
					self::WHITESPACE === $this->token_type
					|| self::COMMENT === $this->token_type
					|| self::MYSQL_COMMENT_START === $this->token_type
					|| self::MYSQL_COMMENT_END === $this->token_type
				);
				$type = $this->token_type;
				if ( null === $type ) {
					// Invalid input: end the stream after the last valid token.
					$this->stream_ended = true;
					break;
				}
				$start  = $this->token_starts_at;
				$length = $this->bytes_already_read - $start;
			}

			// Common single-token lexeme — the overwhelming majority.
			if (
				self::EOF !== $type
				&& self::WITH_SYMBOL !== $type
				&& self::AT_SIGN_SYMBOL !== $type
			) {
				$tokens[] = new WP_MySQL_Token( $type, $start, $length, $sql, $nbe );
				continue;
			}

			// Multi-token lexemes: the shared producers append straight to the list.
			if ( self::EOF === $type ) {
				$this->emit_end_markers( $tokens );
				break;
			} elseif ( self::WITH_SYMBOL === $type ) {
				$this->produce_with_or_rollup( $tokens, $start, $length );
			} else {
				$this->produce_at_variable( $tokens, $start );
			}
			if ( $this->stream_ended ) {
				break;
			}
		}

		return $tokens;
	}

	/**
	 * The version of the MySQL server that the SQL payload is intended for.
	 *
	 * This represents the MySQL server version that the lexer is set up to
	 * consider when tokenizing the SQL payload.
	 *
	 * @return int The MySQL server version that the lexer is set up to consider.
	 */
	public function get_mysql_version(): int {
		return $this->mysql_version;
	}

	/**
	 * Whether an SQL mode is set to be considered as active during tokenization.
	 * The SQL modes are defined as "SQL_MODE_"-prefixed constants in this class.
	 *
	 * @param int $mode The SQL mode to check, an "SQL_MODE_"-prefixed constant.
	 * @return bool Whether the given SQL mode is active.
	 */
	public function is_sql_mode_active( int $mode ): bool {
		return ( $this->sql_modes & $mode ) !== 0;
	}

	/**
	 * Get the numeric token ID for a given token name.
	 *
	 * @param string $token_name The name of the token.
	 * @return int|null The token ID for the given token name; null when not found.
	 */
	public static function get_token_id( string $token_name ): ?int {
		// Token constants share the class with the lexer's own constants, so
		// resolve only the grammar tokens: non-negative ints, never an SQL mode.
		$constant_name = self::class . '::' . $token_name;
		if ( 0 === strpos( $token_name, 'SQL_MODE_' ) || ! defined( $constant_name ) ) {
			return null;
		}
		$value = constant( $constant_name );
		return is_int( $value ) && $value >= 0 ? $value : null;
	}

	/**
	 * Get the name of a token for a given token ID.
	 *
	 * Names are derived from the grammar data rather than stored: a keyword
	 * token resolves to its keyword string via self::KEYWORDS, any other token
	 * to its token constant name via reflection. When several names share a
	 * token number (keyword synonyms, equal-valued constants), plain keywords
	 * win over paren-gated function keywords (USER over SESSION_USER), then the
	 * first name in table order wins.
	 *
	 * This method is intended to be used only for testing and debugging purposes,
	 * when tokens need to be presented by their names in a human-readable form.
	 * It should not be used in production code, as it's not performance-optimized.
	 *
	 * @param int $token_id The numeric token ID.
	 * @return string The token name for the given token ID; null when not found.
	 */
	public static function get_token_name( int $token_id ): ?string {
		static $names = null;
		if ( null === $names ) {
			$names = array();
			foreach ( self::KEYWORDS as $keyword => $id ) {
				if ( ! isset( $names[ $id ] ) && ! isset( self::FUNCTIONS[ $keyword ] ) ) {
					$names[ $id ] = $keyword;
				}
			}
			foreach ( self::KEYWORDS as $keyword => $id ) {
				if ( ! isset( $names[ $id ] ) ) {
					$names[ $id ] = $keyword;
				}
			}
			// Token constants share the class with the lexer's own constants;
			// grammar tokens are the non-negative ints that are not SQL modes
			// (the scanner sentinels are negative, the SQL modes are bit flags).
			foreach ( ( new ReflectionClass( self::class ) )->getConstants() as $name => $value ) {
				if ( is_int( $value ) && $value >= 0 && 0 !== strpos( $name, 'SQL_MODE_' ) && ! isset( $names[ $value ] ) ) {
					$names[ $value ] = $name;
				}
			}
		}
		return $names[ $token_id ] ?? null;
	}

	/**
	 * Produce the next grammar token (or tokens) into the buffer.
	 *
	 * Most lexemes map to a single grammar token. The exceptions mirror MySQL's
	 * own lexer: "@" expands to the sign plus its name, "WITH ROLLUP" contracts
	 * into one terminal, and the end of input yields the two end terminals.
	 */
	private function produce( array &$out ): void {
		// Take the lexeme held back by a "WITH" lookahead, or scan a new one.
		if ( null !== $this->lookahead ) {
			list( $type, $start, $length ) = $this->lookahead;
			$this->lookahead               = null;
		} elseif ( $this->scan_lexeme() ) {
			$type   = $this->token_type;
			$start  = $this->token_starts_at;
			$length = $this->bytes_already_read - $start;
		} else {
			// Invalid input: end the stream after the last valid token.
			$this->stream_ended = true;
			return;
		}

		if ( self::EOF === $type ) {
			$this->emit_end_markers( $out );
		} elseif ( self::WITH_SYMBOL === $type ) {
			$this->produce_with_or_rollup( $out, $start, $length );
		} elseif ( self::AT_SIGN_SYMBOL === $type ) {
			$this->produce_at_variable( $out, $start );
		} else {
			$out[] = $this->make_token( $type, $start, $length );
		}
	}

	/**
	 * Emit "WITH", or contract "WITH ROLLUP" into a single terminal.
	 *
	 * MySQL's lexer needs a one-token lookahead here. When the scanned-ahead
	 * lexeme is not "ROLLUP", it is held back to be processed on the next step.
	 *
	 * @param int $with_start  Byte offset of the "WITH" token.
	 * @param int $with_length Byte length of the "WITH" token.
	 */
	private function produce_with_or_rollup( array &$out, int $with_start, int $with_length ): void {
		if ( ! $this->scan_lexeme() ) {
			// "WITH" was the last valid token; the trailing input is invalid.
			$out[]              = $this->make_token( self::WITH_SYMBOL, $with_start, $with_length );
			$this->stream_ended = true;
			return;
		}

		if ( self::ROLLUP_SYMBOL === $this->token_type ) {
			$out[] = $this->make_token(
				self::WITH_ROLLUP_SYMBOL,
				$with_start,
				$this->bytes_already_read - $with_start
			);
			return;
		}

		$out[] = $this->make_token( self::WITH_SYMBOL, $with_start, $with_length );
		if ( self::EOF === $this->token_type ) {
			$this->emit_end_markers( $out );
			return;
		}
		$this->lookahead = array(
			$this->token_type,
			$this->token_starts_at,
			$this->bytes_already_read - $this->token_starts_at,
		);
	}

	/**
	 * Emit a "@" or "@@" variable prefix as standalone terminals.
	 *
	 * MySQL treats "@" as its own terminal. A single "@" is followed by the
	 * unquoted user-variable name (always an identifier, never a keyword; it may
	 * contain "." and "$"), by an empty name (MySQL's empty LEX_HOSTNAME, which
	 * makes "SELECT @" and "user1@" valid), or by a quoted name supplied by the
	 * following token. A "@@" prefix is two "@" terminals, with the system
	 * variable name scanned normally afterwards.
	 *
	 * @param int $at_start Byte offset of the "@" token.
	 */
	private function produce_at_variable( array &$out, int $at_start ): void {
		$out[] = $this->make_token( self::AT_SIGN_SYMBOL, $at_start, 1 );

		$position = $this->bytes_already_read;   // Right after the "@".
		$next     = $this->sql[ $position ] ?? '';

		if ( '@' === $next ) {
			$out[]                    = $this->make_token( self::AT_SIGN_SYMBOL, $position, 1 );
			$this->bytes_already_read = $position + 1;
			return;
		}

		$name_length = strspn(
			$this->sql,
			'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_.$',
			$position
		);
		if ( $name_length > 0 ) {
			$out[]                    = $this->make_token( self::IDENTIFIER, $position, $name_length );
			$this->bytes_already_read = $position + $name_length;
		} elseif ( "'" !== $next && '"' !== $next && '`' !== $next ) {
			$out[] = $this->make_token( self::IDENTIFIER, $position, 0 );
		}
	}

	/**
	 * Emit the two terminals that close a valid stream: END_OF_INPUT and Bison's
	 * end marker ($end), both at the end-of-input position.
	 */
	private function emit_end_markers( array &$out ): void {
		$end                = $this->bytes_already_read;
		$out[]              = $this->make_token( self::END_OF_INPUT, $end, 0 );
		$out[]              = $this->make_token( self::END_MARKER, $end, 0 );
		$this->stream_ended = true;
	}

	/**
	 * Scan one lexeme from the input, skipping whitespace and comments.
	 *
	 * Sets the raw token type and byte range. Returns false only on invalid
	 * input; at the end of input it sets the EOF sentinel and returns true.
	 *
	 * @return bool Whether a lexeme (possibly the EOF sentinel) was scanned.
	 */
	private function scan_lexeme(): bool {
		// Skip leading whitespace inline for optimal performance.
		$this->bytes_already_read += strspn( $this->sql, self::WHITESPACE_MASK, $this->bytes_already_read );

		do {
			$this->token_starts_at = $this->bytes_already_read;
			$this->token_type      = $this->read_next_token();
		} while (
			self::WHITESPACE === $this->token_type
			|| self::COMMENT === $this->token_type
			|| self::MYSQL_COMMENT_START === $this->token_type
			|| self::MYSQL_COMMENT_END === $this->token_type
		);

		return null !== $this->token_type;
	}

	/**
	 * Build a grammar token over the current input and SQL mode.
	 *
	 * @param int $type   The grammar token number.
	 * @param int $start  Byte offset where the token begins.
	 * @param int $length Byte length of the token.
	 * @return WP_MySQL_Token
	 */
	private function make_token( int $type, int $start, int $length ): WP_MySQL_Token {
		return new WP_MySQL_Token(
			$type,
			$start,
			$length,
			$this->sql,
			$this->no_backslash_escapes
		);
	}

	private function read_next_token(): ?int {
		$byte      = $this->sql[ $this->bytes_already_read ] ?? null;
		$next_byte = $this->sql[ $this->bytes_already_read + 1 ] ?? null;

		// A map for a single-byte symbol fast path.
		static $single_byte_ops = array(
			'(' => self::OPEN_PAR_SYMBOL,
			')' => self::CLOSE_PAR_SYMBOL,
			',' => self::COMMA_SYMBOL,
			';' => self::SEMICOLON_SYMBOL,
			'+' => self::PLUS_OPERATOR,
			'~' => self::BITWISE_NOT_OPERATOR,
			'%' => self::MOD_OPERATOR,
			'^' => self::BITWISE_XOR_OPERATOR,
			'?' => self::PARAM_MARKER,
			'{' => self::OPEN_CURLY_SYMBOL,
			'}' => self::CLOSE_CURLY_SYMBOL,
			'=' => self::EQUAL_OPERATOR,
		);

		// Fast path for keywords and identifiers.
		// `$byte > "\x7F"` catches any non-ASCII byte (0x80-0xFF); read_identifier()
		// restricts the accepted identifier codepoints to U+0080-U+FFFF.
		// `"'" !== $next_byte` defers x'..', n'..' and similar special
		// literals to their dedicated branches below; only single quotes
		// form those, regardless of SQL mode.
		if (
			(
				( $byte >= 'a' && $byte <= 'z' )
				|| ( $byte >= 'A' && $byte <= 'Z' )
				|| $byte > "\x7F"
			)
			&& "'" !== $next_byte
		) {
			$started_at = $this->bytes_already_read;
			$type       = $this->read_identifier();
			if (
				self::IDENTIFIER === $type
				// When preceded by a dot, it is always an identifier.
				&& ! ( $started_at > 0 && '.' === $this->sql[ $started_at - 1 ] )
			) {
				// Inline the keyword lookup on the hot identifier path: most
				// identifiers are not keywords, so this avoids two method calls
				// (token-bytes extraction + keyword determination) per token.
				$word    = strtoupper(
					substr( $this->sql, $started_at, $this->bytes_already_read - $started_at )
				);
				$keyword = self::KEYWORDS[ $word ] ?? self::IDENTIFIER;
				if ( self::IDENTIFIER !== $keyword ) {
					$type = $this->resolve_keyword_type( $keyword, $word );
				}
			}
		} elseif ( null !== $byte && isset( $single_byte_ops[ $byte ] ) ) {
			// Fast path for single-byte symbols.
			$this->bytes_already_read += 1;
			$type                      = $single_byte_ops[ $byte ];
		} elseif ( "'" === $byte || '"' === $byte || '`' === $byte ) {
			$type = $this->read_quoted_text();
		} elseif ( null !== $byte && strspn( $byte, self::DIGIT_MASK ) > 0 ) {
			$type = $this->read_number();
		} elseif ( '.' === $byte ) {
			if ( null !== $next_byte && strspn( $next_byte, self::DIGIT_MASK ) > 0 ) {
				$type = $this->read_number();
			} else {
				$this->bytes_already_read += 1;
				$type                      = self::DOT_SYMBOL;
			}
		} elseif ( ':' === $byte ) {
			$this->bytes_already_read += 1; // Consume the ':'.
			if ( '=' === $next_byte ) {
				$this->bytes_already_read += 1; // Consume the '='.
				$type                      = self::ASSIGN_OPERATOR;
			} else {
				$type = self::COLON_SYMBOL;
			}
		} elseif ( '<' === $byte ) {
			$this->bytes_already_read += 1; // Consume the '<'.
			if ( '=' === $next_byte ) {
				$this->bytes_already_read += 1; // Consume the '='.
				if ( '>' === ( $this->sql[ $this->bytes_already_read ] ?? null ) ) {
					$this->bytes_already_read += 1; // Consume the '>'.
					$type                      = self::NULL_SAFE_EQUAL_OPERATOR;
				} else {
					$type = self::LESS_OR_EQUAL_OPERATOR;
				}
			} elseif ( '>' === $next_byte ) {
				$this->bytes_already_read += 1; // Consume the '>'.
				$type                      = self::NOT_EQUAL_OPERATOR;
			} elseif ( '<' === $next_byte ) {
				$this->bytes_already_read += 1; // Consume the '<'.
				$type                      = self::SHIFT_LEFT_OPERATOR;
			} else {
				$type = self::LESS_THAN_OPERATOR;
			}
		} elseif ( '>' === $byte ) {
			$this->bytes_already_read += 1; // Consume the '>'.
			if ( '=' === $next_byte ) {
				$this->bytes_already_read += 1; // Consume the '='.
				$type                      = self::GREATER_OR_EQUAL_OPERATOR;
			} elseif ( '>' === $next_byte ) {
				$this->bytes_already_read += 1; // Consume the '>'.
				$type                      = self::SHIFT_RIGHT_OPERATOR;
			} else {
				$type = self::GREATER_THAN_OPERATOR;
			}
		} elseif ( '!' === $byte ) {
			$this->bytes_already_read += 1; // Consume the '!'.
			if ( '=' === $next_byte ) {
				$this->bytes_already_read += 1; // Consume the '='.
				$type                      = self::NOT_EQUAL_OPERATOR;
			} else {
				$type = self::LOGICAL_NOT_OPERATOR;
			}
		} elseif ( '-' === $byte ) {
			if (
				'-' === $next_byte
				&& $this->bytes_already_read + 2 < $this->sql_length
				&& strspn( $this->sql[ $this->bytes_already_read + 2 ], self::WHITESPACE_MASK ) > 0
			) {
				$type = $this->read_line_comment();
			} elseif ( '>' === $next_byte ) {
				$this->bytes_already_read += 2; // Consume the '->'.
				if ( '>' === ( $this->sql[ $this->bytes_already_read ] ?? null ) ) {
					$this->bytes_already_read += 1; // Consume the '>'.
					if ( $this->mysql_version >= 50713 ) {
						$type = self::JSON_UNQUOTED_SEPARATOR_SYMBOL;
					} else {
						return null; // Invalid input.
					}
				} else {
					if ( $this->mysql_version >= 50708 ) {
						$type = self::JSON_SEPARATOR_SYMBOL;
					} else {
						return null; // Invalid input.
					}
				}
			} else {
				$this->bytes_already_read += 1; // Consume the '-'.
				$type                      = self::MINUS_OPERATOR;
			}
		} elseif ( '*' === $byte ) {
			$this->bytes_already_read += 1;
			if ( '/' === $next_byte && $this->in_mysql_comment ) {
				$this->bytes_already_read += 1; // Consume the '/'.
				$type                      = self::MYSQL_COMMENT_END;
				$this->in_mysql_comment    = false;
			} else {
				$type = self::MULT_OPERATOR;
			}
		} elseif ( '/' === $byte ) {
			if ( '*' === $next_byte ) {
				if ( '!' === ( $this->sql[ $this->bytes_already_read + 2 ] ?? null ) ) {
					$type = $this->read_mysql_comment();
				} else {
					$this->bytes_already_read += 2; // Consume the '/*'.
					$this->read_comment_content();
					$type = self::COMMENT;
				}
			} else {
				$this->bytes_already_read += 1;
				$type                      = self::DIV_OPERATOR;
			}
		} elseif ( '&' === $byte ) {
			$this->bytes_already_read += 1; // Consume the '&'.
			if ( '&' === $next_byte ) {
				$this->bytes_already_read += 1; // Consume the '&'.
				$type                      = self::LOGICAL_AND_OPERATOR;
			} else {
				$type = self::BITWISE_AND_OPERATOR;
			}
		} elseif ( '|' === $byte ) {
			$this->bytes_already_read += 1; // Consume the '|'.
			if ( '|' === $next_byte ) {
				$this->bytes_already_read += 1; // Consume the '|'.
				$type                      = $this->is_sql_mode_active( self::SQL_MODE_PIPES_AS_CONCAT )
					? self::CONCAT_PIPES_SYMBOL
					: self::LOGICAL_OR_OPERATOR;
			} else {
				$type = self::BITWISE_OR_OPERATOR;
			}
		} elseif ( '@' === $byte ) {
			// MySQL treats "@" as a standalone terminal; the variable name that
			// follows is emitted as its own token (see produce_at_variable()).
			$this->bytes_already_read += 1; // Consume the '@'.
			$type                      = self::AT_SIGN_SYMBOL;
		} elseif ( '\\' === $byte ) {
			$this->bytes_already_read += 1; // Consume the '\'.
			if ( 'N' === $next_byte ) {
				$this->bytes_already_read += 1; // Consume the 'N'.
				$type                      = self::NULL2_SYMBOL;
			} else {
				return null; // Invalid input.
			}
		} elseif ( '#' === $byte ) {
			$type = $this->read_line_comment();
		} elseif ( null !== $byte && strspn( $byte, self::WHITESPACE_MASK ) > 0 ) {
			$this->bytes_already_read += strspn( $this->sql, self::WHITESPACE_MASK, $this->bytes_already_read );
			$type                      = self::WHITESPACE;
		} elseif ( ( 'x' === $byte || 'X' === $byte || 'b' === $byte || 'B' === $byte ) && "'" === $next_byte ) {
			$type = $this->read_number();
		} elseif ( ( 'n' === $byte || 'N' === $byte ) && "'" === $next_byte ) {
			$this->bytes_already_read += 1; // n/N
			$type                      = $this->read_quoted_text( "'" );
			if ( self::SINGLE_QUOTED_TEXT === $type ) {
				$type = self::NCHAR_TEXT;
			}
		} elseif ( null === $byte ) {
			$type = self::EOF;
		} else {
			$started_at = $this->bytes_already_read;
			$type       = $this->read_identifier();
			if ( self::IDENTIFIER === $type ) {
				// When preceded by a dot, it is always an identifier.
				if ( $started_at > 0 && '.' === $this->sql[ $started_at - 1 ] ) {
					$type = self::IDENTIFIER;
				} elseif ( '_' === $byte && isset( self::UNDERSCORE_CHARSETS[ strtolower( $this->get_current_token_bytes() ) ] ) ) {
					$type = self::UNDERSCORE_CHARSET;
				} else {
					$type = $this->determine_identifier_or_keyword_type( $this->get_current_token_bytes() );
				}
			}
		}
		return $type;
	}

	private function get_current_token_bytes(): string {
		return substr(
			$this->sql,
			$this->token_starts_at,
			$this->bytes_already_read - $this->token_starts_at
		);
	}

	/**
	 * Read an unquoted identifier.
	 *
	 * This function reads characters that are allowed in an unquoted identifier.
	 * An identifier cannot consist solely of digits, but this function doesn't
	 * ensure that explicitly, as numbers are processed before identifiers in
	 * the tokenization process, recognizing all digit-only sequences as numbers.
	 *
	 * Rules:
	 *   1. Allowed characters are ASCII a-z, A-Z, 0-9, _, $, and Unicode U+0080-U+FFFF.
	 *   2. Unquoted identifiers may begin with a digit but may not consist solely of digits.
	 *
	 *  See:
	 *    https://dev.mysql.com/doc/refman/8.4/en/identifiers.html
	 */
	private function read_identifier(): ?int {
		$started_at = $this->bytes_already_read;
		while ( true ) {
			// First, let's try to parse an ASCII sequence.
			$this->bytes_already_read += strspn(
				$this->sql,
				'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_$',
				$this->bytes_already_read
			);

			// Check if the following byte can be part of a multibyte character
			// in the range of U+0080 to U+FFFF before looking at further bytes.
			// If it can't, bail out early to avoid unnecessary UTF-8 decoding.
			// Identifiers are usually ASCII-only, so we can optimize for that.
			$byte_1 = ord(
				$this->sql[ $this->bytes_already_read ] ?? "\0"
			);
			if ( $byte_1 < 0xC2 || $byte_1 > 0xEF ) {
				break;
			}

			// Look for a valid 2-byte UTF-8 symbol. Covers range U+0080 - U+07FF.
			$byte_2 = ord(
				$this->sql[ $this->bytes_already_read + 1 ] ?? "\0"
			);
			if (
				$byte_1 <= 0xDF
				&& $byte_2 >= 0x80 && $byte_2 <= 0xBF
			) {
				$this->bytes_already_read += 2;
				continue;
			}

			// Look for a valid 3-byte UTF-8 symbol in range U+0800 - U+FFFF.
			$byte_3 = ord(
				$this->sql[ $this->bytes_already_read + 2 ] ?? "\0"
			);
			if (
				$byte_1 <= 0xEF
				&& $byte_2 >= 0x80 && $byte_2 <= 0xBF
				&& $byte_3 >= 0x80 && $byte_3 <= 0xBF
				// Exclude surrogate range U+D800 to U+DFFF:
				&& ! ( 0xED === $byte_1 && $byte_2 >= 0xA0 )
				// Exclude overlong encodings:
				&& ! ( 0xE0 === $byte_1 && $byte_2 < 0xA0 )
			) {
				$this->bytes_already_read += 3;
				continue;
			}

			// Not a valid identifier character.
			break;
		}

		// An identifier cannot consist solely of digits, but we don't need to
		// ensure that explicitly, as numbers are processed before identifiers.

		return $this->bytes_already_read - $started_at > 0
			? self::IDENTIFIER
			: null; // Invalid input.
	}

	private function read_number(): ?int {
		// @TODO: Support numeric-only identifier parts after "." (e.g., 1ea10.1).

		$byte       = $this->sql[ $this->bytes_already_read ] ?? null;
		$next_byte  = $this->sql[ $this->bytes_already_read + 1 ] ?? null;
		$third_byte = $this->sql[ $this->bytes_already_read + 2 ] ?? null;

		if (
			// HEX number in the form of 0xN.
			(
				'0' === $byte
				&& 'x' === $next_byte
				&& null !== $third_byte
				&& strspn( $third_byte, self::HEX_DIGIT_MASK ) > 0
			)
			// HEX number in the form of x'N' or X'N'.
			|| ( ( 'x' === $byte || 'X' === $byte ) && "'" === $next_byte )
		) {
			$is_quoted                 = "'" === $next_byte;
			$this->bytes_already_read += 2; // Consume "0x" or "x'".
			$this->bytes_already_read += strspn( $this->sql, self::HEX_DIGIT_MASK, $this->bytes_already_read );
			if ( $is_quoted ) {
				if (
					$this->bytes_already_read >= $this->sql_length
					|| "'" !== $this->sql[ $this->bytes_already_read ]
				) {
					return null; // Invalid input.
				}
				$this->bytes_already_read += 1; // Consume the "'".
			}
			$type = self::HEX_NUMBER;
		} elseif (
			// BIN number in the form of 0bN.
			(
				'0' === $byte
				&& 'b' === $next_byte
				&& ( '0' === $third_byte || '1' === $third_byte )
			)
			// BIN number in the form of b'N' or B'N'.
			|| ( ( 'b' === $byte || 'B' === $byte ) && "'" === $next_byte )
		) {
			$is_quoted                 = "'" === $next_byte;
			$this->bytes_already_read += 2; // Consume "0b" or "b'".
			$this->bytes_already_read += strspn( $this->sql, '01', $this->bytes_already_read );
			if ( $is_quoted ) {
				if (
					$this->bytes_already_read >= $this->sql_length
					|| "'" !== $this->sql[ $this->bytes_already_read ]
				) {
					return null; // Invalid input.
				}
				$this->bytes_already_read += 1; // Consume the "'".
			}
			$type = self::BIN_NUMBER;
		} else {
			// Here, we have a sequence starting with N or .N, where N is a digit.

			// 1. Try integer first.
			$this->bytes_already_read += strspn( $this->sql, self::DIGIT_MASK, $this->bytes_already_read );
			$type                      = self::INT_NUMBER;

			// 2. In case of N. or .N, it's a decimal or float number.
			if ( '.' === ( $this->sql[ $this->bytes_already_read ] ?? null ) ) {
				$this->bytes_already_read += 1;
				$type                      = self::DECIMAL_NUMBER;
				$this->bytes_already_read += strspn( $this->sql, self::DIGIT_MASK, $this->bytes_already_read );
			}

			// 3. When exponent is present, it's a float number.
			$byte         = $this->sql[ $this->bytes_already_read ] ?? null;
			$next_byte    = $this->sql[ $this->bytes_already_read + 1 ] ?? null;
			$has_exponent =
				( 'e' === $byte || 'E' === $byte )
				&& null !== $next_byte
				&& (
					strspn( $next_byte, self::DIGIT_MASK ) > 0
					|| (
						( '+' === $next_byte || '-' === $next_byte )
						&& $this->bytes_already_read + 2 < $this->sql_length
						&& strspn( $this->sql[ $this->bytes_already_read + 2 ], self::DIGIT_MASK ) > 0
					)
				);
			if ( $has_exponent ) {
				$this->bytes_already_read += 1; // Consume the 'e' or 'E'.
				$this->bytes_already_read += 1; // Consume the '+', '-', or digit.
				$this->bytes_already_read += strspn( $this->sql, self::DIGIT_MASK, $this->bytes_already_read );
				$type                      = self::FLOAT_NUMBER;
			}
		}

		/*
		 * In MySQL, when an input matches both a number and an identifier, the
		 * number always wins. However, if the number is followed by a non-numeric
		 * identifier-like character, then it is recognized as an identifier...
		 * Unless it's a float number, which ignores any subsequent input.
		 *
		 * Examples:
		 *  - "1234" (integer) vs. "1234a" (identifier)
		 *  - "0b01" (bin)     vs. "0b012" (identifier)
		 *  - "0xa1" (hex)     vs. "0xa1x" (identifier)
		 *  - "12.3" (decimal) vs. "12.3a" (identifier)
		 *  - "1e10" (float)   vs. "1e10a" (float, followed by identifier)
		 */
		$text                       = $this->get_current_token_bytes();
		$possible_identifier_prefix =
			self::INT_NUMBER === $type
			|| ( '0' === $text[0] && ( 'b' === $text[1] || 'x' === $text[1] ) );

		/*
		 * When we match some subsequent identifier bytes, it's an identifier.
		 * Note that the "$this->read_identifier()" method doesn't check that
		 * the identifier doesn't consist solely of digits. This is an advantage
		 * here, as we can look only at subsequent bytes instead of backtracking
		 * to the beginning of the number (for valid identifiers like 0b019).
		 */
		if ( $possible_identifier_prefix && self::IDENTIFIER === $this->read_identifier() ) {
			$type = self::IDENTIFIER;
		}

		// Determine integer type.
		if ( self::INT_NUMBER === $type ) {
			// Fast path for most integers.
			$bytes = $this->get_current_token_bytes();
			if ( strlen( $bytes ) < 10 ) {
				return self::INT_NUMBER;
			}

			// Remove leading zeros.
			$bytes  = substr( $bytes, strspn( $bytes, '0' ) );
			$length = strlen( $bytes );

			// Determine integer type based on its length and value.
			if ( $length < 10 ) {
				return self::INT_NUMBER;
			} elseif ( 10 === $length ) {
				return strcmp( $bytes, '2147483647' ) > 0
					? self::LONG_NUMBER
					: self::INT_NUMBER;
			} elseif ( $length < 19 ) {
				return self::LONG_NUMBER;
			} elseif ( 19 === $length ) {
				return strcmp( $bytes, '9223372036854775807' ) > 0
					? self::ULONGLONG_NUMBER
					: self::LONG_NUMBER;
			} elseif ( 20 === $length ) {
				return strcmp( $bytes, '18446744073709551615' ) > 0
					? self::DECIMAL_NUMBER
					: self::ULONGLONG_NUMBER;
			} else {
				return self::DECIMAL_NUMBER;
			}
		}
		return $type;
	}

	/**
	 * Quoted literals and identifiers:
	 *   https://dev.mysql.com/doc/refman/8.4/en/string-literals.html
	 *   https://dev.mysql.com/doc/refman/8.4/en/identifiers.html
	 *
	 * Rules:
	 *   1. Quotes can be escaped by doubling them ('', "", ``).
	 *   2. In string literals, backslashes escape the next character,
	 *      unless the NO_BACKSLASH_ESCAPES SQL mode is set.
	 *   3. In identifiers, backslashes are always literal and never escape.
	 */
	private function read_quoted_text(): ?int {
		$quote                     = $this->sql[ $this->bytes_already_read ];
		$this->bytes_already_read += 1; // Consume the quote.

		/*
		 * Determine whether the quote opens an identifier or a string literal.
		 * An identifier is quoted with a backtick or a double quote when the
		 * ANSI_QUOTES SQL mode is active. Otherwise, it is a string literal.
		 *
		 * See: https://dev.mysql.com/doc/refman/8.4/en/sql-mode.html#sqlmode_ansi_quotes
		 */
		$is_identifier_quote = '`' === $quote
			|| ( '"' === $quote && $this->is_sql_mode_active( self::SQL_MODE_ANSI_QUOTES ) );

		/*
		 * Backslash escapes apply only to string literals, and only when the
		 * NO_BACKSLASH_ESCAPES SQL mode is not set.
		 */
		$backslash_is_escape = ! $is_identifier_quote && ! $this->no_backslash_escapes;

		// We need to look for the closing quote in a loop, as it can be escaped,
		// in which case the escape sequence is consumed and the loop continues.
		$at = $this->bytes_already_read;
		while ( true ) {
			$quote_at = strpos( $this->sql, $quote, $at );
			if ( false === $quote_at ) {
				return null; // Invalid input.
			}
			$at = $quote_at;

			/*
			 * In string literals, quotes can be escaped with a backslash. When
			 * NO_BACKSLASH_ESCAPES SQL mode is active, the backslash is treated
			 * as a regular character. Identifiers never use backslash escaping.
			 *
			 * The quote is escaped only when the number of preceding backslashes
			 * is odd - "\" is an escape sequence, "\\" is an escaped backslash,
			 * "\\\" is an escaped backslash and an escape sequence, and so on.
			 *
			 * The `($at - $i - 1) >= 0` guard prevents PHP's negative-string-
			 * offset wraparound (PHP 7.1+) when the closing-quote candidate
			 * sits at the very start of the input. The `?? null` covers
			 * positive out-of-range indexes belt-and-suspenders.
			 */
			if ( $backslash_is_escape ) {
				$i = 0;
				while ( ( $at - $i - 1 ) >= 0 && '\\' === ( $this->sql[ $at - $i - 1 ] ?? null ) ) {
					$i += 1;
				}
				if ( 1 === $i % 2 ) {
					$at += 1;
					continue;
				}
			}

			// Check if the quote is doubled.
			if ( ( $this->sql[ $at + 1 ] ?? null ) === $quote ) {
				$at += 2;
				continue;
			}

			break;
		}
		$at += 1;

		$this->bytes_already_read = $at;

		if ( $is_identifier_quote ) {
			return self::BACK_TICK_QUOTED_ID;
		} elseif ( '"' === $quote ) {
			return self::DOUBLE_QUOTED_TEXT;
		} else {
			return self::SINGLE_QUOTED_TEXT;
		}
	}

	private function read_line_comment(): int {
		$this->bytes_already_read += strcspn( $this->sql, "\r\n", $this->bytes_already_read );
		return self::COMMENT;
	}

	private function read_mysql_comment(): int {
		// @TODO: Consider supporting optimizer hints (/*+ ... */) or document
		//        that they are not supported.
		// @TODO: Implement six-digit version number support (from MySQL 8.4).

		// MySQL-specific comment in one of the following forms:
		//   1. /*! ... */      - The content is treated as SQL.
		//   2. /*!12345 ... */ - The content is treated as SQL when "MySQL version >= 12345".
		$this->bytes_already_read += 3; // Consume the '/*!'.

		// Check if the next 5 characters are digits.
		$digit_count        = strspn( $this->sql, self::DIGIT_MASK, $this->bytes_already_read, 5 );
		$is_version_comment = 5 === $digit_count;

		// For version comments, extract the version number.
		$version = $is_version_comment
			? (int) substr( $this->sql, $this->bytes_already_read, $digit_count )
			: 0;

		if ( $this->mysql_version < $version ) {
			// Version not satisfied. Treat the content as a regular comment.
			$this->read_comment_content();
			return self::COMMENT;
		} else {
			// Version satisfied or not specified. Treat the content as SQL code.
			$this->bytes_already_read += $digit_count; // Skip the version number.
			$this->in_mysql_comment    = true;
			return self::MYSQL_COMMENT_START;
		}
	}

	private function read_comment_content(): void {
		$comment_end = strpos( $this->sql, '*/', $this->bytes_already_read );
		if ( false === $comment_end ) {
			$this->bytes_already_read = $this->sql_length;
		} else {
			$this->bytes_already_read = $comment_end + 2;
		}
	}

	private function determine_identifier_or_keyword_type( string $value ): int {
		$word = strtoupper( $value );
		$type = self::KEYWORDS[ $word ] ?? self::IDENTIFIER;
		if ( self::IDENTIFIER === $type ) {
			return self::IDENTIFIER;
		}
		return $this->resolve_keyword_type( $type, $word );
	}

	/**
	 * Resolve a keyword token matched in self::KEYWORDS, applying the
	 * function-call lookahead and the SQL_MODE_HIGH_NOT_PRECEDENCE rule.
	 *
	 * @param int    $type The grammar token number matched in self::KEYWORDS.
	 * @param string $word The upper-cased keyword string that was matched.
	 */
	private function resolve_keyword_type( int $type, string $word ): int {
		// Function keywords (declared with SYM_FN in MySQL's lex.h) are keywords
		// only when directly followed by an opening parenthesis.
		if ( isset( self::FUNCTIONS[ $word ] ) ) {
			// Skip any whitespace character if the SQL mode says they should be ignored.
			if ( $this->is_sql_mode_active( self::SQL_MODE_IGNORE_SPACE ) ) {
				$this->bytes_already_read += strspn( $this->sql, self::WHITESPACE_MASK, $this->bytes_already_read );
			}
			if ( '(' !== ( $this->sql[ $this->bytes_already_read ] ?? null ) ) {
				return self::IDENTIFIER;
			}
		}

		// With "SQL_MODE_HIGH_NOT_PRECEDENCE" enabled, "NOT" needs to be emitted as a higher priority NOT2 symbol.
		if ( self::NOT_SYMBOL === $type && $this->is_sql_mode_active( self::SQL_MODE_HIGH_NOT_PRECEDENCE ) ) {
			return self::NOT2_SYMBOL;
		}

		return $type;
	}
}
