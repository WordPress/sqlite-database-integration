<?php

class MySQLLexer {
	// SQL modes
	const SQL_MODE_NO_MODE = 0;
	const SQL_MODE_ANSI_QUOTES = 1 << 0;
	const SQL_MODE_HIGH_NOT_PRECEDENCE = 1 << 1;
	const SQL_MODE_PIPES_AS_CONCAT = 1 << 2;
	const SQL_MODE_IGNORE_SPACE = 1 << 3;
	const SQL_MODE_NO_BACKSLASH_ESCAPES = 1 << 4;

	const WHITESPACES = [' ' => true, "\t" => true, "\n" => true, "\r" => true, "\f" => true];

	/**
	 * Unquoted identifiers:
	 *   https://dev.mysql.com/doc/refman/8.4/en/identifiers.html
	 *
	 * Rules:
	 *   1. Allowed characters are ASCII a-z, A-Z, 0-9, $, _ and Unicode \x{0080}-\x{ffff}.
	 *   2. Unquoted identifiers may begin with a digit but may not consist solely of digits.
	 */
	const PATTERN_UNQUOTED_IDENTIFIER = '(?=\D)[\w_$\x{80}-\x{ffff}]+';

    /**
     * Quoted literals and identifiers:
     *   https://dev.mysql.com/doc/refman/8.4/en/string-literals.html
     *   https://dev.mysql.com/doc/refman/8.4/en/identifiers.html
     *
     * Rules:
     *   1. Quotes can be escaped by doubling them ('', "", ``).
     *   2. Backslashes escape the next character, unless NO_BACKSLASH_ESCAPES is set.
     */
    const PATTERN_SINGLE_QUOTED_TEXT = "'(?:''|\\\\.|[^'])*'";
    const PATTERN_DOUBLE_QUOTED_TEXT = '"(?:""|\\\\.|[^"])*"';
    const PATTERN_BACKTICK_QUOTED_ID = '`(?:``|\\\\.|[^`])*`';
    const PATTERN_SINGLE_QUOTED_TEXT_NO_BACKSLASH_ESCAPES = "'(?:''|[^'])*'";
    const PATTERN_DOUBLE_QUOTED_TEXT_NO_BACKSLASH_ESCAPES = '"(?:""|[^"])*"';
    const PATTERN_BACKTICK_QUOTED_ID_NO_BACKSLASH_ESCAPES = '`(?:``|[^`])*`';

    // Constants for token types.
    // Operators
    public const EQUAL_OPERATOR = 1;
    public const ASSIGN_OPERATOR = 2;
    public const NULL_SAFE_EQUAL_OPERATOR = 3;
    public const GREATER_OR_EQUAL_OPERATOR = 4;
    public const GREATER_THAN_OPERATOR = 5;
    public const LESS_OR_EQUAL_OPERATOR = 6;
    public const LESS_THAN_OPERATOR = 7;
    public const NOT_EQUAL_OPERATOR = 8;
    public const PLUS_OPERATOR = 9;
    public const MINUS_OPERATOR = 10;
    public const MULT_OPERATOR = 11;
    public const DIV_OPERATOR = 12;
    public const MOD_OPERATOR = 13;
    public const LOGICAL_NOT_OPERATOR = 14;
    public const BITWISE_NOT_OPERATOR = 15;
    public const SHIFT_LEFT_OPERATOR = 16;
    public const SHIFT_RIGHT_OPERATOR = 17;
    public const LOGICAL_AND_OPERATOR = 18;
    public const BITWISE_AND_OPERATOR = 19;
    public const BITWISE_XOR_OPERATOR = 20;
    public const LOGICAL_OR_OPERATOR = 21;
    public const BITWISE_OR_OPERATOR = 22;
    public const DOT_SYMBOL = 23;
    public const COMMA_SYMBOL = 24;
    public const SEMICOLON_SYMBOL = 25;
    public const COLON_SYMBOL = 26;
    public const OPEN_PAR_SYMBOL = 27;
    public const CLOSE_PAR_SYMBOL = 28;
    public const OPEN_CURLY_SYMBOL = 29;
    public const CLOSE_CURLY_SYMBOL = 30;
    public const JSON_SEPARATOR_SYMBOL = 32;
    public const JSON_UNQUOTED_SEPARATOR_SYMBOL = 33;
    public const AT_SIGN_SYMBOL = 34;
    public const AT_TEXT_SUFFIX = 35;
    public const AT_AT_SIGN_SYMBOL = 36;
    public const NULL2_SYMBOL = 37;
    public const PARAM_MARKER = 38;
    // Data types
    public const INT_SYMBOL = 39;
    public const TINYINT_SYMBOL = 40;
    public const SMALLINT_SYMBOL = 41;
    public const MEDIUMINT_SYMBOL = 42;
    public const BIGINT_SYMBOL = 43;
    public const REAL_SYMBOL = 44;
    public const DOUBLE_SYMBOL = 45;
    public const FLOAT_SYMBOL    = 46;
    public const DECIMAL_SYMBOL = 47;
    public const NUMERIC_SYMBOL = 48;
    public const DATE_SYMBOL = 49;
    public const TIME_SYMBOL = 50;
    public const TIMESTAMP_SYMBOL = 51;
    public const DATETIME_SYMBOL = 52;
    public const YEAR_SYMBOL = 53;
    public const CHAR_SYMBOL = 54;
    public const VARCHAR_SYMBOL = 55;
    public const BINARY_SYMBOL = 56;
    public const VARBINARY_SYMBOL = 57;
    public const TINYBLOB_SYMBOL = 58;
    public const BLOB_SYMBOL = 59;
    public const MEDIUMBLOB_SYMBOL = 60;
    public const LONGBLOB_SYMBOL = 61;
    public const TINYTEXT_SYMBOL = 62;
    public const TEXT_SYMBOL = 63;
    public const MEDIUMTEXT_SYMBOL = 64;
    public const LONGTEXT_SYMBOL = 65;
    public const ENUM_SYMBOL = 66;
    public const SET_SYMBOL = 67;
    public const JSON_SYMBOL = 68;
    public const GEOMETRY_SYMBOL = 69;
    public const POINT_SYMBOL = 70;
    public const LINESTRING_SYMBOL = 71;
    public const POLYGON_SYMBOL = 72;
    public const GEOMETRYCOLLECTION_SYMBOL = 73;
    public const MULTIPOINT_SYMBOL = 74;
    public const MULTILINESTRING_SYMBOL = 75;
    public const MULTIPOLYGON_SYMBOL = 76;
    // Keywords
    public const ACCESSIBLE_SYMBOL = 77;
    public const ACCOUNT_SYMBOL = 78;
    public const ACTION_SYMBOL = 79;
    public const ADD_SYMBOL = 80;
    public const AFTER_SYMBOL = 81;
    public const AGAINST_SYMBOL = 82;
    public const AGGREGATE_SYMBOL = 83;
    public const ALGORITHM_SYMBOL = 84;
    public const ALL_SYMBOL = 85;
    public const ALTER_SYMBOL = 86;
    public const ALWAYS_SYMBOL = 87;
    public const ANALYSE_SYMBOL = 88;
    public const ANALYZE_SYMBOL = 89;
    public const AND_SYMBOL = 90;
    public const ANY_SYMBOL = 91;
    public const AS_SYMBOL = 92;
    public const ASC_SYMBOL = 93;
    public const ASENSITIVE_SYMBOL = 94;
    public const AT_SYMBOL = 95;
    public const AUTOEXTEND_SIZE_SYMBOL = 96;
    public const AUTO_INCREMENT_SYMBOL = 97;
    public const AVG_ROW_LENGTH_SYMBOL = 98;
    public const AVG_SYMBOL = 99;
    public const BACKUP_SYMBOL = 100;
    public const BEFORE_SYMBOL = 101;
    public const BEGIN_SYMBOL = 102;
    public const BETWEEN_SYMBOL = 103;
    public const BINLOG_SYMBOL = 106;
    public const BIT_AND_SYMBOL = 107;
    public const BIT_OR_SYMBOL = 108;
    public const BIT_XOR_SYMBOL = 109;
    public const BLOCK_SYMBOL = 111;
    public const BOOL_SYMBOL = 112;
    public const BOOLEAN_SYMBOL = 113;
    public const BOTH_SYMBOL = 114;
    public const BTREE_SYMBOL = 115;
    public const BY_SYMBOL = 116;
    public const BYTE_SYMBOL = 117;
    public const CACHE_SYMBOL = 118;
    public const CALL_SYMBOL = 119;
    public const CASCADE_SYMBOL = 120;
    public const CASCADED_SYMBOL = 121;
    public const CASE_SYMBOL = 122;
    public const CAST_SYMBOL = 123;
    public const CATALOG_NAME_SYMBOL = 124;
    public const CHAIN_SYMBOL = 125;
    public const CHANGE_SYMBOL = 126;
    public const CHANGED_SYMBOL = 127;
    public const CHANNEL_SYMBOL = 128;
    public const CHARSET_SYMBOL = 129;
    public const CHARACTER_SYMBOL = 131;
    public const CHECK_SYMBOL = 132;
    public const CHECKSUM_SYMBOL = 133;
    public const CIPHER_SYMBOL = 134;
    public const CLASS_ORIGIN_SYMBOL = 135;
    public const CLIENT_SYMBOL = 136;
    public const CLOSE_SYMBOL = 137;
    public const COALESCE_SYMBOL = 138;
    public const CODE_SYMBOL = 139;
    public const COLLATE_SYMBOL = 140;
    public const COLLATION_SYMBOL = 141;
    public const COLUMN_FORMAT_SYMBOL = 142;
    public const COLUMN_NAME_SYMBOL = 143;
    public const COLUMNS_SYMBOL = 144;
    public const COLUMN_SYMBOL = 145;
    public const COMMENT_SYMBOL = 146;
    public const COMMITTED_SYMBOL = 147;
    public const COMMIT_SYMBOL = 148;
    public const COMPACT_SYMBOL = 149;
    public const COMPLETION_SYMBOL = 150;
    public const COMPRESSED_SYMBOL = 151;
    public const COMPRESSION_SYMBOL = 152;
    public const CONCURRENT_SYMBOL = 153;
    public const CONDITION_SYMBOL = 154;
    public const CONNECTION_SYMBOL = 155;
    public const CONSISTENT_SYMBOL = 156;
    public const CONSTRAINT_SYMBOL = 157;
    public const CONSTRAINT_CATALOG_SYMBOL = 158;
    public const CONSTRAINT_NAME_SYMBOL = 159;
    public const CONSTRAINT_SCHEMA_SYMBOL = 160;
    public const CONTAINS_SYMBOL = 161;
    public const CONTEXT_SYMBOL = 162;
    public const CONTINUE_SYMBOL = 163;
    public const CONTRIBUTORS_SYMBOL = 164;
    public const CONVERT_SYMBOL = 165;
    public const COUNT_SYMBOL = 166;
    public const CPU_SYMBOL = 167;
    public const CREATE_SYMBOL = 168;
    public const CROSS_SYMBOL = 169;
    public const CUBE_SYMBOL = 170;
    public const CURDATE_SYMBOL = 171;
    public const CURRENT_DATE_SYMBOL = 172;
    public const CURRENT_TIME_SYMBOL = 173;
    public const CURRENT_TIMESTAMP_SYMBOL = 174;
    public const CURRENT_USER_SYMBOL = 175;
    public const CURRENT_SYMBOL = 176;
    public const CURSOR_SYMBOL = 177;
    public const CURSOR_NAME_SYMBOL = 178;
    public const CURTIME_SYMBOL = 179;
    public const DATABASE_SYMBOL = 180;
    public const DATABASES_SYMBOL = 181;
    public const DATAFILE_SYMBOL = 182;
    public const DATA_SYMBOL = 183;
    public const DATE_ADD_SYMBOL = 185;
    public const DATE_SUB_SYMBOL = 186;
    public const DAY_HOUR_SYMBOL = 188;
    public const DAY_MICROSECOND_SYMBOL = 189;
    public const DAY_MINUTE_SYMBOL = 190;
    public const DAY_SECOND_SYMBOL = 191;
    public const DAY_SYMBOL = 192;
    public const DAYOFMONTH_SYMBOL = 193;
    public const DEALLOCATE_SYMBOL = 194;
    public const DEC_SYMBOL = 195;
    public const DECLARE_SYMBOL = 197;
    public const DEFAULT_SYMBOL = 198;
    public const DEFAULT_AUTH_SYMBOL = 199;
    public const DEFINER_SYMBOL = 200;
    public const DELAYED_SYMBOL = 201;
    public const DELAY_KEY_WRITE_SYMBOL = 202;
    public const DELETE_SYMBOL = 203;
    public const DESC_SYMBOL = 204;
    public const DESCRIBE_SYMBOL = 205;
    public const DES_KEY_FILE_SYMBOL = 206;
    public const DETERMINISTIC_SYMBOL = 207;
    public const DIAGNOSTICS_SYMBOL = 208;
    public const DIRECTORY_SYMBOL = 209;
    public const DISABLE_SYMBOL = 210;
    public const DISCARD_SYMBOL = 211;
    public const DISK_SYMBOL = 212;
    public const DISTINCT_SYMBOL = 213;
    public const DISTINCTROW_SYMBOL = 214;
    public const DIV_SYMBOL = 215;
    public const DO_SYMBOL = 218;
    public const DROP_SYMBOL = 219;
    public const DUAL_SYMBOL = 220;
    public const DUMPFILE_SYMBOL = 221;
    public const DUPLICATE_SYMBOL = 222;
    public const DYNAMIC_SYMBOL = 223;
    public const EACH_SYMBOL = 224;
    public const ELSE_SYMBOL = 225;
    public const ELSEIF_SYMBOL = 226;
    public const EMPTY_SYMBOL = 227;
    public const ENABLE_SYMBOL = 228;
    public const ENCLOSED_SYMBOL = 229;
    public const ENCRYPTION_SYMBOL = 230;
    public const END_SYMBOL = 231;
    public const ENDS_SYMBOL = 232;
    public const ENFORCED_SYMBOL = 233;
    public const ENGINES_SYMBOL = 234;
    public const ENGINE_SYMBOL = 235;
    public const ERROR_SYMBOL = 237;
    public const ERRORS_SYMBOL = 238;
    public const ESCAPED_SYMBOL = 239;
    public const ESCAPE_SYMBOL = 240;
    public const EVENT_SYMBOL = 241;
    public const EVENTS_SYMBOL = 242;
    public const EVERY_SYMBOL = 243;
    public const EXCHANGE_SYMBOL = 244;
    public const EXCEPT_SYMBOL = 245;
    public const EXECUTE_SYMBOL = 246;
    public const EXISTS_SYMBOL = 247;
    public const EXIT_SYMBOL = 248;
    public const EXPANSION_SYMBOL = 249;
    public const EXPIRE_SYMBOL = 250;
    public const EXPLAIN_SYMBOL = 251;
    public const EXPORT_SYMBOL = 252;
    public const EXTENDED_SYMBOL = 253;
    public const EXTENT_SIZE_SYMBOL = 254;
    public const EXTRACT_SYMBOL = 255;
    public const FALSE_SYMBOL = 256;
    public const FAST_SYMBOL = 257;
    public const FAULTS_SYMBOL = 258;
    public const FETCH_SYMBOL = 259;
    public const FIELDS_SYMBOL = 260;
    public const FILE_BLOCK_SIZE_SYMBOL = 261;
    public const FILE_SYMBOL = 262;
    public const FILTER_SYMBOL = 263;
    public const FIRST_SYMBOL = 264;
    public const FIRST_VALUE_SYMBOL = 265;
    public const FIXED_SYMBOL = 266;
    public const FLOAT4_SYMBOL = 267;
    public const FLOAT8_SYMBOL = 268;
    public const FLUSH_SYMBOL = 270;
    public const FOLLOWS_SYMBOL = 271;
    public const FORCE_SYMBOL = 272;
    public const FOREIGN_SYMBOL = 273;
    public const FOR_SYMBOL = 274;
    public const FORMAT_SYMBOL = 275;
    public const FOUND_SYMBOL = 276;
    public const FROM_SYMBOL = 277;
    public const FULLTEXT_SYMBOL = 278;
    public const FULL_SYMBOL = 279;
    public const FUNCTION_SYMBOL = 280;
    public const GENERATED_SYMBOL = 281;
    public const GENERAL_SYMBOL = 282;
    public const GET_FORMAT_SYMBOL = 285;
    public const GET_MASTER_PUBLIC_KEY_SYMBOL = 286;
    public const GLOBAL_SYMBOL = 287;
    public const GRANT_SYMBOL = 288;
    public const GRANTS_SYMBOL = 289;
    public const GROUP_CONCAT_SYMBOL = 290;
    public const GROUP_REPLICATION_SYMBOL = 291;
    public const GROUP_SYMBOL = 292;
    public const HANDLER_SYMBOL = 293;
    public const HASH_SYMBOL = 294;
    public const HAVING_SYMBOL = 295;
    public const HELP_SYMBOL = 296;
    public const HIGH_PRIORITY_SYMBOL = 297;
    public const HISTOGRAM_SYMBOL = 298;
    public const HISTORY_SYMBOL = 299;
    public const HOST_SYMBOL = 300;
    public const HOSTS_SYMBOL = 301;
    public const HOUR_MICROSECOND_SYMBOL = 302;
    public const HOUR_MINUTE_SYMBOL = 303;
    public const HOUR_SECOND_SYMBOL = 304;
    public const HOUR_SYMBOL = 305;
    public const IDENTIFIED_SYMBOL = 306;
    public const IF_SYMBOL = 307;
    public const IGNORE_SYMBOL = 308;
    public const IGNORE_SERVER_IDS_SYMBOL = 309;
    public const IMPORT_SYMBOL = 310;
    public const IN_SYMBOL = 311;
    public const INDEXES_SYMBOL = 312;
    public const INDEX_SYMBOL = 313;
    public const INFILE_SYMBOL = 314;
    public const INITIAL_SIZE_SYMBOL = 315;
    public const INNER_SYMBOL = 316;
    public const INOUT_SYMBOL = 317;
    public const INSENSITIVE_SYMBOL = 318;
    public const INSERT_SYMBOL = 319;
    public const INSERT_METHOD_SYMBOL = 320;
    public const INSTANCE_SYMBOL = 321;
    public const INSTALL_SYMBOL = 322;
    public const INTEGER_SYMBOL = 324;
    public const INTERVAL_SYMBOL = 325;
    public const INTO_SYMBOL = 326;
    public const INVISIBLE_SYMBOL = 327;
    public const INVOKER_SYMBOL = 328;
    public const IO_SYMBOL = 329;
    public const IPC_SYMBOL = 330;
    public const IS_SYMBOL = 331;
    public const ISOLATION_SYMBOL = 332;
    public const ISSUER_SYMBOL = 333;
    public const ITERATE_SYMBOL = 334;
    public const JOIN_SYMBOL = 335;
    public const JSON_TABLE_SYMBOL = 337;
    public const JSON_ARRAYAGG_SYMBOL = 338;
    public const JSON_OBJECTAGG_SYMBOL = 339;
    public const KEYS_SYMBOL = 340;
    public const KEY_BLOCK_SIZE_SYMBOL = 341;
    public const KEY_SYMBOL = 342;
    public const KILL_SYMBOL = 343;
    public const LANGUAGE_SYMBOL = 344;
    public const LAST_SYMBOL = 345;
    public const LAST_VALUE_SYMBOL = 346;
    public const LATERAL_SYMBOL = 347;
    public const LEAD_SYMBOL = 348;
    public const LEADING_SYMBOL = 349;
    public const LEAVE_SYMBOL = 350;
    public const LEAVES_SYMBOL = 351;
    public const LEFT_SYMBOL = 352;
    public const LESS_SYMBOL = 353;
    public const LEVEL_SYMBOL = 354;
    public const LIKE_SYMBOL = 355;
    public const LIMIT_SYMBOL = 356;
    public const LINEAR_SYMBOL = 357;
    public const LINES_SYMBOL = 358;
    public const LIST_SYMBOL = 360;
    public const LOAD_SYMBOL = 361;
    public const LOCALTIME_SYMBOL = 362;
    public const LOCALTIMESTAMP_SYMBOL = 363;
    public const LOCAL_SYMBOL = 364;
    public const LOCATOR_SYMBOL = 365;
    public const LOCK_SYMBOL = 366;
    public const LOCKS_SYMBOL = 367;
    public const LOGFILE_SYMBOL = 368;
    public const LOGS_SYMBOL = 369;
    public const LOOP_SYMBOL = 372;
    public const LOW_PRIORITY_SYMBOL = 373;
    public const MASTER_SYMBOL = 374;
    public const MASTER_AUTO_POSITION_SYMBOL = 375;
    public const MASTER_BIND_SYMBOL = 376;
    public const MASTER_CONNECT_RETRY_SYMBOL = 377;
    public const MASTER_DELAY_SYMBOL = 378;
    public const MASTER_HEARTBEAT_PERIOD_SYMBOL = 379;
    public const MASTER_HOST_SYMBOL = 380;
    public const NETWORK_NAMESPACE_SYMBOL = 381;
    public const MASTER_LOG_FILE_SYMBOL = 382;
    public const MASTER_LOG_POS_SYMBOL = 383;
    public const MASTER_PASSWORD_SYMBOL = 384;
    public const MASTER_PORT_SYMBOL = 385;
    public const MASTER_PUBLIC_KEY_PATH_SYMBOL = 386;
    public const MASTER_RETRY_COUNT_SYMBOL = 387;
    public const MASTER_SERVER_ID_SYMBOL = 388;
    public const MASTER_SSL_CAPATH_SYMBOL = 389;
    public const MASTER_SSL_CA_SYMBOL = 390;
    public const MASTER_SSL_CERT_SYMBOL = 391;
    public const MASTER_SSL_CIPHER_SYMBOL = 392;
    public const MASTER_SSL_CRL_SYMBOL = 393;
    public const MASTER_SSL_CRLPATH_SYMBOL = 394;
    public const MASTER_SSL_KEY_SYMBOL = 395;
    public const MASTER_SSL_SYMBOL = 396;
    public const MASTER_SSL_VERIFY_SERVER_CERT_SYMBOL = 397;
    public const MASTER_TLS_VERSION_SYMBOL = 398;
    public const MASTER_TLS_CIPHERSUITES_SYMBOL = 399;
    public const MASTER_USER_SYMBOL = 400;
    public const MASTER_ZSTD_COMPRESSION_LEVEL_SYMBOL = 401;
    public const MATCH_SYMBOL = 402;
    public const MAX_CONNECTIONS_PER_HOUR_SYMBOL = 403;
    public const MAX_QUERIES_PER_HOUR_SYMBOL = 404;
    public const MAX_ROWS_SYMBOL = 405;
    public const MAX_SIZE_SYMBOL = 406;
    public const MAX_STATEMENT_TIME_SYMBOL = 407;
    public const MAX_UPDATES_PER_HOUR_SYMBOL = 408;
    public const MAX_USER_CONNECTIONS_SYMBOL = 409;
    public const MAXVALUE_SYMBOL = 410;
    public const MAX_SYMBOL = 411;
    public const MEDIUM_SYMBOL = 415;
    public const MEMBER_SYMBOL = 416;
    public const MEMORY_SYMBOL = 417;
    public const MERGE_SYMBOL = 418;
    public const MESSAGE_TEXT_SYMBOL = 419;
    public const MICROSECOND_SYMBOL = 420;
    public const MIDDLEINT_SYMBOL = 421;
    public const MIGRATE_SYMBOL = 422;
    public const MINUTE_MICROSECOND_SYMBOL = 423;
    public const MINUTE_SECOND_SYMBOL = 424;
    public const MINUTE_SYMBOL = 425;
    public const MIN_ROWS_SYMBOL = 426;
    public const MIN_SYMBOL = 427;
    public const MODE_SYMBOL = 428;
    public const MODIFIES_SYMBOL = 429;
    public const MODIFY_SYMBOL = 430;
    public const MOD_SYMBOL = 431;
    public const MONTH_SYMBOL = 432;
    public const MUTEX_SYMBOL = 436;
    public const MYSQL_ERRNO_SYMBOL = 437;
    public const NAME_SYMBOL = 438;
    public const NAMES_SYMBOL = 439;
    public const NATIONAL_SYMBOL = 440;
    public const NATURAL_SYMBOL = 441;
    public const NCHAR_SYMBOL = 442;
    public const NDBCLUSTER_SYMBOL = 443;
    public const NDB_SYMBOL = 444;
    public const NEG_SYMBOL = 445;
    public const NESTED_SYMBOL = 446;
    public const NEVER_SYMBOL = 447;
    public const NEW_SYMBOL = 448;
    public const NEXT_SYMBOL = 449;
    public const NODEGROUP_SYMBOL = 450;
    public const NONE_SYMBOL = 451;
    public const NONBLOCKING_SYMBOL = 452;
    public const NOT_SYMBOL = 453;
    public const NOWAIT_SYMBOL = 454;
    public const NO_WAIT_SYMBOL = 455;
    public const NO_WRITE_TO_BINLOG_SYMBOL = 456;
    public const NULL_SYMBOL = 457;
    public const NULLS_SYMBOL = 458;
    public const NUMBER_SYMBOL = 459;
    public const NVARCHAR_SYMBOL = 461;
    public const NTH_VALUE_SYMBOL = 462;
    public const NTILE_SYMBOL = 463;
    public const OF_SYMBOL = 464;
    public const OFF_SYMBOL = 465;
    public const OFFLINE_SYMBOL = 466;
    public const OFFSET_SYMBOL = 467;
    public const OJ_SYMBOL = 468;
    public const OLD_PASSWORD_SYMBOL = 469;
    public const OLD_SYMBOL = 470;
    public const ON_SYMBOL = 471;
    public const ONLINE_SYMBOL = 472;
    public const ONE_SYMBOL = 473;
    public const ONLY_SYMBOL = 474;
    public const OPEN_SYMBOL = 475;
    public const OPTIONAL_SYMBOL = 476;
    public const OPTIONALLY_SYMBOL = 477;
    public const OPTIONS_SYMBOL = 478;
    public const OPTION_SYMBOL = 479;
    public const OPTIMIZE_SYMBOL = 480;
    public const OPTIMIZER_COSTS_SYMBOL = 481;
    public const ORDER_SYMBOL = 482;
    public const ORDINALITY_SYMBOL = 483;
    public const ORGANIZATION_SYMBOL = 484;
    public const OR_SYMBOL = 485;
    public const OTHERS_SYMBOL = 486;
    public const OUTER_SYMBOL = 487;
    public const OUTFILE_SYMBOL = 488;
    public const OUT_SYMBOL = 489;
    public const OWNER_SYMBOL = 490;
    public const PACK_KEYS_SYMBOL = 491;
    public const PAGE_SYMBOL = 492;
    public const PARSER_SYMBOL = 493;
    public const PARTIAL_SYMBOL = 494;
    public const PARTITIONING_SYMBOL = 495;
    public const PARTITIONS_SYMBOL = 496;
    public const PARTITION_SYMBOL = 497;
    public const PASSWORD_SYMBOL = 498;
    public const PATH_SYMBOL = 499;
    public const PERCENT_RANK_SYMBOL = 500;
    public const PERSIST_SYMBOL = 501;
    public const PERSIST_ONLY_SYMBOL = 502;
    public const PHASE_SYMBOL = 503;
    public const PLUGIN_SYMBOL = 504;
    public const PLUGINS_SYMBOL = 505;
    public const PLUGIN_DIR_SYMBOL = 506;
    public const PORT_SYMBOL = 509;
    public const POSITION_SYMBOL = 510;
    public const PRECEDES_SYMBOL = 511;
    public const PRECEDING_SYMBOL = 512;
    public const PRECISION_SYMBOL = 513;
    public const PREPARE_SYMBOL = 514;
    public const PRESERVE_SYMBOL = 515;
    public const PREV_SYMBOL = 516;
    public const PRIMARY_SYMBOL = 517;
    public const PRIVILEGES_SYMBOL = 518;
    public const PRIVILEGE_CHECKS_USER_SYMBOL = 519;
    public const PROCEDURE_SYMBOL = 520;
    public const PROCESS_SYMBOL = 521;
    public const PROCESSLIST_SYMBOL = 522;
    public const PROFILES_SYMBOL = 523;
    public const PROFILE_SYMBOL = 524;
    public const PROXY_SYMBOL = 525;
    public const PURGE_SYMBOL = 526;
    public const QUARTER_SYMBOL = 527;
    public const QUERY_SYMBOL = 528;
    public const QUICK_SYMBOL = 529;
    public const RANDOM_SYMBOL = 530;
    public const RANGE_SYMBOL = 531;
    public const RANK_SYMBOL = 532;
    public const READS_SYMBOL = 533;
    public const READ_ONLY_SYMBOL = 534;
    public const READ_SYMBOL = 535;
    public const READ_WRITE_SYMBOL = 536;
    public const REBUILD_SYMBOL = 538;
    public const RECOVER_SYMBOL = 539;
    public const REDOFILE_SYMBOL = 540;
    public const REDO_BUFFER_SIZE_SYMBOL = 541;
    public const REDUNDANT_SYMBOL = 542;
    public const REFERENCES_SYMBOL = 543;
    public const RECURSIVE_SYMBOL = 544;
    public const REGEXP_SYMBOL = 545;
    public const RELAYLOG_SYMBOL = 546;
    public const RELAY_SYMBOL = 547;
    public const RELAY_LOG_FILE_SYMBOL = 548;
    public const RELAY_LOG_POS_SYMBOL = 549;
    public const RELAY_THREAD_SYMBOL = 550;
    public const RELEASE_SYMBOL = 551;
    public const RELOAD_SYMBOL = 552;
    public const REMOTE_SYMBOL = 553;
    public const REMOVE_SYMBOL = 554;
    public const RENAME_SYMBOL = 555;
    public const REORGANIZE_SYMBOL = 556;
    public const REPAIR_SYMBOL = 557;
    public const REPEAT_SYMBOL = 558;
    public const REPEATABLE_SYMBOL = 559;
    public const REPLACE_SYMBOL = 560;
    public const REPLICATION_SYMBOL = 561;
    public const REPLICATE_DO_DB_SYMBOL = 562;
    public const REPLICATE_IGNORE_DB_SYMBOL = 563;
    public const REPLICATE_DO_TABLE_SYMBOL = 564;
    public const REPLICATE_IGNORE_TABLE_SYMBOL = 565;
    public const REPLICATE_WILD_DO_TABLE_SYMBOL = 566;
    public const REPLICATE_WILD_IGNORE_TABLE_SYMBOL = 567;
    public const REPLICATE_REWRITE_DB_SYMBOL = 568;
    public const REQUIRE_SYMBOL = 569;
    public const REQUIRE_ROW_FORMAT_SYMBOL = 570;
    public const REQUIRE_TABLE_PRIMARY_KEY_CHECK_SYMBOL = 571;
    public const RESET_SYMBOL = 572;
    public const RESIGNAL_SYMBOL = 573;
    public const RESOURCE_SYMBOL = 574;
    public const RESPECT_SYMBOL = 575;
    public const RESTART_SYMBOL = 576;
    public const RESTORE_SYMBOL = 577;
    public const RESTRICT_SYMBOL = 578;
    public const RESUME_SYMBOL = 579;
    public const RETAIN_SYMBOL = 580;
    public const RETURNED_SQLSTATE_SYMBOL = 581;
    public const RETURNS_SYMBOL = 582;
    public const REUSE_SYMBOL = 583;
    public const REVERSE_SYMBOL = 584;
    public const REVOKE_SYMBOL = 585;
    public const RIGHT_SYMBOL = 586;
    public const RLIKE_SYMBOL = 587;
    public const ROLE_SYMBOL = 588;
    public const ROLLBACK_SYMBOL = 589;
    public const ROLLUP_SYMBOL = 590;
    public const ROTATE_SYMBOL = 591;
    public const ROW_SYMBOL = 592;
    public const ROWS_SYMBOL = 593;
    public const ROW_COUNT_SYMBOL = 594;
    public const ROW_FORMAT_SYMBOL = 595;
    public const ROW_NUMBER_SYMBOL = 596;
    public const RTREE_SYMBOL = 597;
    public const SAVEPOINT_SYMBOL = 598;
    public const SCHEMA_SYMBOL = 599;
    public const SCHEMAS_SYMBOL = 600;
    public const SCHEMA_NAME_SYMBOL = 601;
    public const SCHEDULE_SYMBOL = 602;
    public const SECOND_MICROSECOND_SYMBOL = 603;
    public const SECOND_SYMBOL = 604;
    public const SECONDARY_SYMBOL = 605;
    public const SECONDARY_ENGINE_SYMBOL = 606;
    public const SECONDARY_LOAD_SYMBOL = 607;
    public const SECONDARY_UNLOAD_SYMBOL = 608;
    public const SECURITY_SYMBOL = 609;
    public const SELECT_SYMBOL = 610;
    public const SENSITIVE_SYMBOL = 611;
    public const SEPARATOR_SYMBOL = 612;
    public const SERIALIZABLE_SYMBOL = 613;
    public const SERIAL_SYMBOL = 614;
    public const SERVER_SYMBOL = 615;
    public const SERVER_OPTIONS_SYMBOL = 616;
    public const SESSION_SYMBOL = 617;
    public const SESSION_USER_SYMBOL = 618;
    public const SET_VAR_SYMBOL = 620;
    public const SHARE_SYMBOL = 621;
    public const SHOW_SYMBOL = 622;
    public const SHUTDOWN_SYMBOL = 623;
    public const SIGNAL_SYMBOL = 624;
    public const SIGNED_SYMBOL = 625;
    public const SIMPLE_SYMBOL = 626;
    public const SKIP_SYMBOL = 627;
    public const SLAVE_SYMBOL = 628;
    public const SLOW_SYMBOL = 629;
    public const SNAPSHOT_SYMBOL = 631;
    public const SOME_SYMBOL = 632;
    public const SOCKET_SYMBOL = 633;
    public const SONAME_SYMBOL = 634;
    public const SOUNDS_SYMBOL = 635;
    public const SOURCE_SYMBOL = 636;
    public const SPATIAL_SYMBOL = 637;
    public const SQL_SYMBOL = 638;
    public const SQLEXCEPTION_SYMBOL = 639;
    public const SQLSTATE_SYMBOL = 640;
    public const SQLWARNING_SYMBOL = 641;
    public const SQL_AFTER_GTIDS_SYMBOL = 642;
    public const SQL_AFTER_MTS_GAPS_SYMBOL = 643;
    public const SQL_BEFORE_GTIDS_SYMBOL = 644;
    public const SQL_BIG_RESULT_SYMBOL = 645;
    public const SQL_BUFFER_RESULT_SYMBOL = 646;
    public const SQL_CALC_FOUND_ROWS_SYMBOL = 647;
    public const SQL_CACHE_SYMBOL = 648;
    public const SQL_NO_CACHE_SYMBOL = 649;
    public const SQL_SMALL_RESULT_SYMBOL = 650;
    public const SQL_THREAD_SYMBOL = 651;
    public const SQL_TSI_DAY_SYMBOL = 652;
    public const SQL_TSI_HOUR_SYMBOL = 653;
    public const SQL_TSI_MICROSECOND_SYMBOL = 654;
    public const SQL_TSI_MINUTE_SYMBOL = 655;
    public const SQL_TSI_MONTH_SYMBOL = 656;
    public const SQL_TSI_QUARTER_SYMBOL = 657;
    public const SQL_TSI_SECOND_SYMBOL = 658;
    public const SQL_TSI_WEEK_SYMBOL = 659;
    public const SQL_TSI_YEAR_SYMBOL = 660;
    public const SRID_SYMBOL = 661;
    public const SSL_SYMBOL = 662;
    public const STACKED_SYMBOL = 663;
    public const STARTING_SYMBOL = 664;
    public const STARTS_SYMBOL = 665;
    public const STATS_AUTO_RECALC_SYMBOL = 666;
    public const STATS_PERSISTENT_SYMBOL = 667;
    public const STATS_SAMPLE_PAGES_SYMBOL = 668;
    public const STATUS_SYMBOL = 669;
    public const STD_SYMBOL = 670;
    public const STDDEV_POP_SYMBOL = 671;
    public const STDDEV_SAMP_SYMBOL = 672;
    public const STDDEV_SYMBOL = 673;
    public const STOP_SYMBOL = 674;
    public const STORAGE_SYMBOL = 675;
    public const STORED_SYMBOL = 676;
    public const STRAIGHT_JOIN_SYMBOL = 677;
    public const STREAM_SYMBOL = 678;
    public const STRING_SYMBOL = 679;
    public const SUBCLASS_ORIGIN_SYMBOL = 680;
    public const SUBDATE_SYMBOL = 681;
    public const SUBJECT_SYMBOL = 682;
    public const SUBPARTITIONS_SYMBOL = 683;
    public const SUBPARTITION_SYMBOL = 684;
    public const SUBSTR_SYMBOL = 685;
    public const SUBSTRING_SYMBOL = 686;
    public const SUM_SYMBOL = 687;
    public const SUPER_SYMBOL = 688;
    public const SUSPEND_SYMBOL = 689;
    public const SWAPS_SYMBOL = 690;
    public const SWITCHES_SYMBOL = 691;
    public const SYSDATE_SYMBOL = 692;
    public const SYSTEM_SYMBOL = 693;
    public const SYSTEM_USER_SYMBOL = 694;
    public const TABLE_SYMBOL = 695;
    public const TABLES_SYMBOL = 696;
    public const TABLESPACE_SYMBOL = 697;
    public const TABLE_CHECKSUM_SYMBOL = 698;
    public const TABLE_NAME_SYMBOL = 699;
    public const TEMPORARY_SYMBOL = 700;
    public const TEMPTABLE_SYMBOL = 701;
    public const TERMINATED_SYMBOL = 702;
    public const THAN_SYMBOL = 704;
    public const THEN_SYMBOL = 705;
    public const THREAD_PRIORITY_SYMBOL = 706;
    public const TIES_SYMBOL = 707;
    public const TIMESTAMP_ADD_SYMBOL = 710;
    public const TIMESTAMP_DIFF_SYMBOL = 711;
    public const TO_SYMBOL = 715;
    public const TRAILING_SYMBOL = 716;
    public const TRANSACTION_SYMBOL = 717;
    public const TRIGGER_SYMBOL = 718;
    public const TRIGGERS_SYMBOL = 719;
    public const TRIM_SYMBOL = 720;
    public const TRUE_SYMBOL = 721;
    public const TRUNCATE_SYMBOL = 722;
    public const TYPES_SYMBOL = 723;
    public const TYPE_SYMBOL = 724;
    public const UDF_RETURNS_SYMBOL = 725;
    public const UNBOUNDED_SYMBOL = 726;
    public const UNCOMMITTED_SYMBOL = 727;
    public const UNDEFINED_SYMBOL = 728;
    public const UNDO_BUFFER_SIZE_SYMBOL = 729;
    public const UNDOFILE_SYMBOL = 730;
    public const UNDO_SYMBOL = 731;
    public const UNICODE_SYMBOL = 732;
    public const UNION_SYMBOL = 733;
    public const UNIQUE_SYMBOL = 734;
    public const UNKNOWN_SYMBOL = 735;
    public const UNINSTALL_SYMBOL = 736;
    public const UNSIGNED_SYMBOL = 737;
    public const UPDATE_SYMBOL = 738;
    public const UPGRADE_SYMBOL = 739;
    public const USAGE_SYMBOL = 740;
    public const USER_RESOURCES_SYMBOL = 741;
    public const USER_SYMBOL = 742;
    public const USE_FRM_SYMBOL = 743;
    public const USE_SYMBOL = 744;
    public const USING_SYMBOL = 745;
    public const UTC_DATE_SYMBOL = 746;
    public const UTC_TIME_SYMBOL = 747;
    public const UTC_TIMESTAMP_SYMBOL = 748;
    public const VALIDATION_SYMBOL = 749;
    public const VALUE_SYMBOL = 750;
    public const VALUES_SYMBOL = 751;
    public const VARCHARACTER_SYMBOL = 754;
    public const VARIABLES_SYMBOL = 755;
    public const VARIANCE_SYMBOL = 756;
    public const VARYING_SYMBOL = 757;
    public const VAR_POP_SYMBOL = 758;
    public const VAR_SAMP_SYMBOL = 759;
    public const VCPU_SYMBOL = 760;
    public const VIEW_SYMBOL = 761;
    public const VIRTUAL_SYMBOL = 762;
    public const VISIBLE_SYMBOL = 763;
    public const WAIT_SYMBOL = 764;
    public const WARNINGS_SYMBOL = 765;
    public const WEEK_SYMBOL = 766;
    public const WHEN_SYMBOL = 767;
    public const WEIGHT_STRING_SYMBOL = 768;
    public const WHERE_SYMBOL = 769;
    public const WHILE_SYMBOL = 770;
    public const WINDOW_SYMBOL = 771;
    public const WITH_SYMBOL = 772;
    public const WITHOUT_SYMBOL = 773;
    public const WORK_SYMBOL = 774;
    public const WRAPPER_SYMBOL = 775;
    public const WRITE_SYMBOL = 776;
    public const XA_SYMBOL = 777;
    public const X509_SYMBOL = 778;
    public const XID_SYMBOL = 779;
    public const XML_SYMBOL = 780;
    public const XOR_SYMBOL = 781;
    public const YEAR_MONTH_SYMBOL = 782;
    public const ZEROFILL_SYMBOL = 784;
    public const INT1_SYMBOL = 785;
    public const INT2_SYMBOL = 786;
    public const INT3_SYMBOL = 787;
    public const INT4_SYMBOL = 788;
    public const INT8_SYMBOL = 789;
    // Literals
    public const IDENTIFIER = 790;
    public const BACK_TICK_QUOTED_ID = 791;
    public const DOUBLE_QUOTED_TEXT = 792;
    public const SINGLE_QUOTED_TEXT = 793;
    public const HEX_NUMBER = 794;
    public const BIN_NUMBER = 795;
    public const DECIMAL_NUMBER = 796;
    public const INT_NUMBER = 796;
    public const FLOAT_NUMBER = 797;
    // Special symbols
    public const UNDERSCORE_CHARSET = 798;
    public const DOT_IDENTIFIER = 799;
    // Other
    public const INVALID_INPUT = 800;
    public const LINEBREAK = 801;
    // Missing from the generated lexer and added manually
    public const START_SYMBOL = 802;
    public const UNLOCK_SYMBOL = 803;
    public const CLONE_SYMBOL = 804;
    public const GET_SYMBOL = 805;
    public const ASCII_SYMBOL = 806;
    public const BIT_SYMBOL = 807;
    public const BUCKETS_SYMBOL = 808;
    public const COMPONENT_SYMBOL = 809;
    public const NOW_SYMBOL = 810;
    public const DEFINITION_SYMBOL = 811;
    public const DENSE_RANK_SYMBOL = 812;
    public const DESCRIPTION_SYMBOL = 813;
    public const FAILED_LOGIN_ATTEMPTS_SYMBOL = 814;
    public const FOLLOWING_SYMBOL = 815;
    public const GROUPING_SYMBOL = 816;
    public const GROUPS_SYMBOL = 817;
    public const LAG_SYMBOL = 818;
    public const LONG_SYMBOL = 819;
    public const MASTER_COMPRESSION_ALGORITHM_SYMBOL = 820;
    public const NOT2_SYMBOL = 821;
    public const NO_SYMBOL = 822;
    public const REFERENCE_SYMBOL = 823;
    public const RETURN_SYMBOL = 824;
    public const SPECIFIC_SYMBOL = 825;
    public const AUTHORS_SYMBOL = 826;
    public const ADDDATE_SYMBOL = 827;
    public const CONCAT_PIPES_SYMBOL = 828;
    // Unused in this class but present in MySQLParser, hmm
    public const ACTIVE_SYMBOL = 829;
    public const ADMIN_SYMBOL = 830;
    public const EXCLUDE_SYMBOL = 831;
    public const INACTIVE_SYMBOL = 832;
    public const LOCKED_SYMBOL = 833;
    public const ROUTINE_SYMBOL = 834;
    public const UNTIL_SYMBOL = 835;
    public const ARRAY_SYMBOL = 836;
    public const PASSWORD_LOCK_TIME_SYMBOL = 837;
    public const NCHAR_TEXT = 838;
    public const LONG_NUMBER = 839;
    public const ULONGLONG_NUMBER = 840;
    public const CUME_DIST_SYMBO = 841;
    public const CUME_DIST_SYMBOL = 842;
    public const FOUND_ROWS_SYMBOL = 843;
    public const CONCAT_SYMBOL = 844;
    public const OVER_SYMBOL = 845;

	// more missing symbols
	public const BIN_NUM_SYMBOL = 846;
	public const DECIMAL_NUM_SYMBOL = 847;
	public const LONG_NUM_SYMBOL = 848;
	public const MID_SYMBOL = 849;
	public const NCHAR_STRING_SYMBOL = 850;
	public const TABLE_REF_PRIORITY_SYMBOL = 851;
	public const IO_AFTER_GTIDS_SYMBOL = 852;
	public const IO_BEFORE_GTIDS_SYMBOL = 853;
	public const IO_THREAD_SYMBOL = 854;


    public const EOF = -1;
    public const EMPTY_TOKEN = -2;

    protected $input;
    protected $c; // Current character.
    protected $n; // Next character.
    protected $position = 0;
    protected $token;
    protected $text = '';
    protected $channel = self::DEFAULT_TOKEN_CHANNEL;
    public $type;
    protected $tokenInstance;
    protected $serverVersion;
    protected $sqlModes;

    protected const DEFAULT_TOKEN_CHANNEL = 0;
    protected const HIDDEN = 99;

	const UNDERSCORE_CHARSETS = [
		'_armscii8' => true,
		'_ascii' => true,
		'_big5' => true,
		'_binary' => true,
		'_cp1250' => true,
		'_cp1251' => true,
		'_cp1256' => true,
		'_cp1257' => true,
		'_cp850' => true,
		'_cp852' => true,
		'_cp866' => true,
		'_cp932' => true,
		'_dec8' => true,
		'_eucjpms' => true,
		'_euckr' => true,
		'_gb18030' => true,
		'_gb2312' => true,
		'_gbk' => true,
		'_geostd8' => true,
		'_greek' => true,
		'_hebrew' => true,
		'_hp8' => true,
		'_keybcs2' => true,
		'_koi8r' => true,
		'_koi8u' => true,
		'_latin1' => true,
		'_latin2' => true,
		'_latin5' => true,
		'_latin7' => true,
		'_macce' => true,
		'_macroman' => true,
		'_sjis' => true,
		'_swe7' => true,
		'_tis620' => true,
		'_ucs2' => true,
		'_ujis' => true,
		'_utf16' => true,
		'_utf16le' => true,
		'_utf32' => true,
		'_utf8mb3' => true,
		'_utf8mb4' => true,
	];

    public function __construct(string $input, int $serverVersion = 80000, int $sqlModes = 0)
    {
        $this->input = $input;
        $this->serverVersion = $serverVersion;
        $this->sqlModes = $sqlModes;
    }

    public function isSqlModeActive(int $mode): bool
    {
        return ($this->sqlModes & $mode) !== 0;
    }

    public function getServerVersion()
    {
        return $this->serverVersion;        
    }

    public static function getTokenId(string $tokenName): int
    {
        if($tokenName === 'ε') {
            return self::EMPTY_TOKEN;
        }
        return constant(self::class . '::' . $tokenName);
    }

    public static function getTokenName($tokenType): string
    {
        if(is_numeric($tokenType)) {
            $tokenType = (int) $tokenType;
        }
        if (isset(self::$tokenNames[$tokenType])) {
            return self::$tokenNames[$tokenType];
        }

        return '<INVALID>';
    }

    public function getText(): string
    {
        return $this->text;
    }

    public function getType(): int
    {
        return $this->type;
    }

    public function setType(int $type): void
    {
        $this->type = $type;
    }

    public function getNextToken()
    {
        $this->nextToken();
        return $this->tokenInstance;
    }

    private function nextToken()
    {
        while (true) {
            $this->text = '';
            $this->type = null;
            $this->tokenInstance = null;
            $this->channel = self::DEFAULT_TOKEN_CHANNEL;

            $la = $this->LA(1);
            
            if ($la === "'") {
                $this->SINGLE_QUOTED_TEXT();
            } elseif ($la === '"') {
                $this->DOUBLE_QUOTED_TEXT();
            } elseif ($la === '`') {
                $this->BACK_TICK_QUOTED_ID();
            } elseif ($this->isDigit($la)) {
                $this->NUMBER();
            } elseif ($la === '.') {
                if ($this->isDigit($this->LA(2))) {
                    $this->NUMBER();
                } else {
                    $this->DOT_IDENTIFIER();
                }
            } elseif ($la === '=') {
                $this->EQUAL_OPERATOR();
            } elseif ($la === ':') {
                if ($this->LA(2) === '=') {
                    $this->ASSIGN_OPERATOR();
                } else {
                    $this->COLON_SYMBOL();
                }
            } elseif ($la === '<') {
                if ($this->LA(2) === '=') {
                    if ($this->LA(3) === '>') {
                        $this->NULL_SAFE_EQUAL_OPERATOR();
                    } else {
                        $this->LESS_OR_EQUAL_OPERATOR();
                    }
                } elseif ($this->LA(2) === '>') {
                    $this->NOT_EQUAL2_OPERATOR();
                } elseif ($this->LA(2) === '<') {
                    $this->SHIFT_LEFT_OPERATOR();
                } else {
                    $this->LESS_THAN_OPERATOR();
                }
            } elseif ($la === '>') {
                if ($this->LA(2) === '=') {
                    $this->GREATER_OR_EQUAL_OPERATOR();
                } elseif ($this->LA(2) === '>') {
                    $this->SHIFT_RIGHT_OPERATOR();
                } else {
                    $this->GREATER_THAN_OPERATOR();
                }
            } elseif ($la === '!') {
                if ($this->LA(2) === '=') {
                    $this->NOT_EQUAL_OPERATOR();
                } else {
                    $this->LOGICAL_NOT_OPERATOR();
                }
            } elseif ($la === '+') {
                $this->PLUS_OPERATOR();
            } elseif ($la === '-') {
                if ($this->LA(2) === '>') {
                    if ($this->LA(3) === '>') {
                        $this->JSON_UNQUOTED_SEPARATOR_SYMBOL();
                    } else {
                        $this->JSON_SEPARATOR_SYMBOL();
                    }
                } else {
                    $this->MINUS_OPERATOR();
                }
            } elseif ($la === '*') {
                $this->MULT_OPERATOR();
            } elseif ($la === '/') {
                if ($this->LA(2) === '*') {
                    $this->blockComment();
                } else {
                    $this->DIV_OPERATOR();
                }
            } elseif ($la === '%') {
                $this->MOD_OPERATOR();
            } elseif ($la === '&') {
                if ($this->LA(2) === '&') {
                    $this->LOGICAL_AND_OPERATOR();
                } else {
                    $this->BITWISE_AND_OPERATOR();
                }
            } elseif ($la === '^') {
                $this->BITWISE_XOR_OPERATOR();
            } elseif ($la === '|') {
                if ($this->LA(2) === '|') {
                    $this->LOGICAL_OR_OPERATOR();
                } else {
                    $this->BITWISE_OR_OPERATOR();
                }
            } elseif ($la === '~') {
                $this->BITWISE_NOT_OPERATOR();
            } elseif ($la === ',') {
                $this->COMMA_SYMBOL();
            } elseif ($la === ';') {
                $this->SEMICOLON_SYMBOL();
            } elseif ($la === '(') {
                $this->OPEN_PAR_SYMBOL();
            } elseif ($la === ')') {
                $this->CLOSE_PAR_SYMBOL();
            } elseif ($la === '{') {
                $this->OPEN_CURLY_SYMBOL();
            } elseif ($la === '}') {
                $this->CLOSE_CURLY_SYMBOL();
            } elseif ($la === '@') {
                if ($this->LA(2) === '@') {
                    $this->AT_AT_SIGN_SYMBOL();
                } else {
                    $this->AT_SIGN_SYMBOL();
                }
            } elseif ($la === '?') {
                $this->PARAM_MARKER();
            } elseif ($la === '\\') {
                if ($this->LA(2) === 'N') {
                    $this->NULL2_SYMBOL();
                } else {
                    $this->INVALID_INPUT();
                }
            } elseif ($la === '#') {
                $this->POUND_COMMENT();
            } elseif ($la === '-' && $this->LA(2) === '-') {
                $this->DASHDASH_COMMENT();
            } elseif ($this->isWhitespace($la)) {
                $this->WHITESPACE();
            } elseif ($la === '0' && ($this->LA(2) === 'x' || $this->LA(2) === 'b')) {
                $this->NUMBER();
			} elseif (($la === 'x' || $la === 'X' || $la === 'b' || $la === 'B') && $this->LA(2) === "'") {
				$this->NUMBER();
            } elseif (preg_match('/\G' . self::PATTERN_UNQUOTED_IDENTIFIER . '/u', $this->input, $matches, 0, $this->position)) {
				$this->text = $matches[0];
				$this->position += strlen($this->text);
				$this->c = $this->input[$this->position] ?? null;
				$this->n = $this->input[$this->position + 1] ?? null;
				if ($la === '_' && isset(self::UNDERSCORE_CHARSETS[strtolower($this->text)])) {
					$this->type = self::UNDERSCORE_CHARSET;
				} else {
					$this->IDENTIFIER_OR_KEYWORD();
				}
            } elseif ($la === null) {
                $this->matchEOF();
                $this->tokenInstance = new MySQLToken(self::EOF, self::$tokenNames[self::EOF], '<EOF>');
                return false;
            } else {
                $this->INVALID_INPUT();
            }

            if(null !== $this->type) {
                break;
            }
        }

        $this->tokenInstance = new MySQLToken($this->type, self::$tokenNames[$this->type], $this->text, $this->channel);
        return true;
    }

    public function getToken()
    {
        return $this->tokenInstance;
    }

    public function peekNextToken(int $k=1)
    {
        if ($k <= 0) {
            throw new \InvalidArgumentException('k must be greater than 0.');
        }

        $pos = $this->position;
        $c = $this->c;
        $n = $this->n;
        $token = $this->token;
        $text = $this->text;
        $type = $this->type;
        $channel = $this->channel;
        $tokenInstance = $this->tokenInstance;

        $token = null;
        for ($i = 1; $i <= $k; ++$i) {
            $token = $this->getNextToken();
        }

        $this->position = $pos;
        $this->c = $c;
        $this->n = $n;
        $this->token = $token;
        $this->text = $text;
        $this->type = $type;
        $this->channel = $channel;
        $this->tokenInstance = $tokenInstance;

        return $token;
    }

    protected function LA(int $i): ?string
    {
        if(null === $this->c) {
            $this->c = $this->input[$this->position] ?? null;
        }
        if ($i === 1) {
            return $this->c;
        } elseif ($i === 2) {
            return $this->n;
        } else {
            if ($this->position + $i - 1 >= strlen($this->input)) {
                return null;
            } else {
                return $this->input[$this->position + $i - 1];
            }
        }
    }

    protected function consume(): void
    {
        $this->text .= $this->c;

        if ($this->position < strlen($this->input)) {
            ++$this->position;
            $this->c = $this->input[$this->position] ?? null;
            $this->n = $this->input[$this->position + 1] ?? null;
        } else {
            $this->c = null;
            $this->n = null;
        }
    }

    protected function matchEOF(): void
    {
        if ($this->c === null) {
            $this->matchAny();
        } else {
            throw new \RuntimeException('Current character is not EOF.');
        }
    }

    protected function matchAny(): void
    {
        $this->consume();
    }

    protected function match(string $x): void
    {
        if ($this->c === $x) {
            $this->consume();
        } else {
            throw new \RuntimeException(sprintf("Expecting '%s', found '%s'", $x, $this->c));
        }
    }

    /**
     * This is a place holder to support features of MySQLBaseLexer which are not yet implemented
     * in the PHP target.
     *
     * @return bool
     */
    protected function checkVersion(string $text): bool
    {
        return false;
    }

    /**
     * This is a place holder to support features of MySQLBaseLexer which are not yet implemented
     * in the PHP target.
     *
     * @return void
     */
    protected function emitDot(): void
    {
        return;
    }

    protected static $tokenNames = [
        self::EOF => '$',
        self::EMPTY_TOKEN => 'ε',
        self::EQUAL_OPERATOR => 'EQUAL_OPERATOR',
        self::ASSIGN_OPERATOR => 'ASSIGN_OPERATOR',
        self::NULL_SAFE_EQUAL_OPERATOR => 'NULL_SAFE_EQUAL_OPERATOR',
        self::GREATER_OR_EQUAL_OPERATOR => 'GREATER_OR_EQUAL_OPERATOR',
        self::GREATER_THAN_OPERATOR => 'GREATER_THAN_OPERATOR',
        self::LESS_OR_EQUAL_OPERATOR => 'LESS_OR_EQUAL_OPERATOR',
        self::LESS_THAN_OPERATOR => 'LESS_THAN_OPERATOR',
        self::NOT_EQUAL_OPERATOR => 'NOT_EQUAL_OPERATOR',
        self::PLUS_OPERATOR => 'PLUS_OPERATOR',
        self::MINUS_OPERATOR => 'MINUS_OPERATOR',
        self::MULT_OPERATOR => 'MULT_OPERATOR',
        self::DIV_OPERATOR => 'DIV_OPERATOR',
        self::MOD_OPERATOR => 'MOD_OPERATOR',
        self::LOGICAL_NOT_OPERATOR => 'LOGICAL_NOT_OPERATOR',
        self::BITWISE_NOT_OPERATOR => 'BITWISE_NOT_OPERATOR',
        self::SHIFT_LEFT_OPERATOR => 'SHIFT_LEFT_OPERATOR',
        self::SHIFT_RIGHT_OPERATOR => 'SHIFT_RIGHT_OPERATOR',
        self::LOGICAL_AND_OPERATOR => 'LOGICAL_AND_OPERATOR',
        self::BITWISE_AND_OPERATOR => 'BITWISE_AND_OPERATOR',
        self::BITWISE_XOR_OPERATOR => 'BITWISE_XOR_OPERATOR',
        self::LOGICAL_OR_OPERATOR => 'LOGICAL_OR_OPERATOR',
        self::BITWISE_OR_OPERATOR => 'BITWISE_OR_OPERATOR',
        self::DOT_SYMBOL => 'DOT_SYMBOL',
        self::COMMA_SYMBOL => 'COMMA_SYMBOL',
        self::SEMICOLON_SYMBOL => 'SEMICOLON_SYMBOL',
        self::COLON_SYMBOL => 'COLON_SYMBOL',
        self::OPEN_PAR_SYMBOL => 'OPEN_PAR_SYMBOL',
        self::CLOSE_PAR_SYMBOL => 'CLOSE_PAR_SYMBOL',
        self::OPEN_CURLY_SYMBOL => 'OPEN_CURLY_SYMBOL',
        self::CLOSE_CURLY_SYMBOL => 'CLOSE_CURLY_SYMBOL',
        self::JSON_SEPARATOR_SYMBOL => 'JSON_SEPARATOR_SYMBOL',
        self::JSON_UNQUOTED_SEPARATOR_SYMBOL => 'JSON_UNQUOTED_SEPARATOR_SYMBOL',
        self::AT_SIGN_SYMBOL => 'AT_SIGN_SYMBOL',
        self::AT_TEXT_SUFFIX => 'AT_TEXT_SUFFIX',
        self::AT_AT_SIGN_SYMBOL => 'AT_AT_SIGN_SYMBOL',
        self::NULL2_SYMBOL => 'NULL2_SYMBOL',
        self::PARAM_MARKER => 'PARAM_MARKER',
        self::INT_SYMBOL => 'INT_SYMBOL',
        self::TINYINT_SYMBOL => 'TINYINT_SYMBOL',
        self::SMALLINT_SYMBOL => 'SMALLINT_SYMBOL',
        self::MEDIUMINT_SYMBOL => 'MEDIUMINT_SYMBOL',
        self::BIGINT_SYMBOL => 'BIGINT_SYMBOL',
        self::REAL_SYMBOL => 'REAL_SYMBOL',
        self::DOUBLE_SYMBOL => 'DOUBLE_SYMBOL',
        self::FLOAT_SYMBOL    => 'FLOAT_SYMBOL',
        self::DECIMAL_SYMBOL => 'DECIMAL_SYMBOL',
        self::NUMERIC_SYMBOL => 'NUMERIC_SYMBOL',
        self::DATE_SYMBOL => 'DATE_SYMBOL',
        self::TIME_SYMBOL => 'TIME_SYMBOL',
        self::TIMESTAMP_SYMBOL => 'TIMESTAMP_SYMBOL',
        self::DATETIME_SYMBOL => 'DATETIME_SYMBOL',
        self::YEAR_SYMBOL => 'YEAR_SYMBOL',
        self::CHAR_SYMBOL => 'CHAR_SYMBOL',
        self::VARCHAR_SYMBOL => 'VARCHAR_SYMBOL',
        self::BINARY_SYMBOL => 'BINARY_SYMBOL',
        self::VARBINARY_SYMBOL => 'VARBINARY_SYMBOL',
        self::TINYBLOB_SYMBOL => 'TINYBLOB_SYMBOL',
        self::BLOB_SYMBOL => 'BLOB_SYMBOL',
        self::MEDIUMBLOB_SYMBOL => 'MEDIUMBLOB_SYMBOL',
        self::LONGBLOB_SYMBOL => 'LONGBLOB_SYMBOL',
        self::TINYTEXT_SYMBOL => 'TINYTEXT_SYMBOL',
        self::TEXT_SYMBOL => 'TEXT_SYMBOL',
        self::MEDIUMTEXT_SYMBOL => 'MEDIUMTEXT_SYMBOL',
        self::LONGTEXT_SYMBOL => 'LONGTEXT_SYMBOL',
        self::ENUM_SYMBOL => 'ENUM_SYMBOL',
        self::SET_SYMBOL => 'SET_SYMBOL',
        self::JSON_SYMBOL => 'JSON_SYMBOL',
        self::GEOMETRY_SYMBOL => 'GEOMETRY_SYMBOL',
        self::POINT_SYMBOL => 'POINT_SYMBOL',
        self::LINESTRING_SYMBOL => 'LINESTRING_SYMBOL',
        self::POLYGON_SYMBOL => 'POLYGON_SYMBOL',
        self::GEOMETRYCOLLECTION_SYMBOL => 'GEOMETRYCOLLECTION_SYMBOL',
        self::MULTIPOINT_SYMBOL => 'MULTIPOINT_SYMBOL',
        self::MULTILINESTRING_SYMBOL => 'MULTILINESTRING_SYMBOL',
        self::MULTIPOLYGON_SYMBOL => 'MULTIPOLYGON_SYMBOL',
        self::ACCESSIBLE_SYMBOL => 'ACCESSIBLE_SYMBOL',
        self::ACCOUNT_SYMBOL => 'ACCOUNT_SYMBOL',
        self::ACTION_SYMBOL => 'ACTION_SYMBOL',
        self::ADD_SYMBOL => 'ADD_SYMBOL',
        self::AFTER_SYMBOL => 'AFTER_SYMBOL',
        self::AGAINST_SYMBOL => 'AGAINST_SYMBOL',
        self::AGGREGATE_SYMBOL => 'AGGREGATE_SYMBOL',
        self::ALGORITHM_SYMBOL => 'ALGORITHM_SYMBOL',
        self::ALL_SYMBOL => 'ALL_SYMBOL',
        self::ALTER_SYMBOL => 'ALTER_SYMBOL',
        self::ALWAYS_SYMBOL => 'ALWAYS_SYMBOL',
        self::ANALYSE_SYMBOL => 'ANALYSE_SYMBOL',
        self::ANALYZE_SYMBOL => 'ANALYZE_SYMBOL',
        self::AND_SYMBOL => 'AND_SYMBOL',
        self::ANY_SYMBOL => 'ANY_SYMBOL',
        self::AS_SYMBOL => 'AS_SYMBOL',
        self::ASC_SYMBOL => 'ASC_SYMBOL',
        self::ASENSITIVE_SYMBOL => 'ASENSITIVE_SYMBOL',
        self::AT_SYMBOL => 'AT_SYMBOL',
        self::AUTOEXTEND_SIZE_SYMBOL => 'AUTOEXTEND_SIZE_SYMBOL',
        self::AUTO_INCREMENT_SYMBOL => 'AUTO_INCREMENT_SYMBOL',
        self::AVG_ROW_LENGTH_SYMBOL => 'AVG_ROW_LENGTH_SYMBOL',
        self::AVG_SYMBOL => 'AVG_SYMBOL',
        self::BACKUP_SYMBOL => 'BACKUP_SYMBOL',
        self::BEFORE_SYMBOL => 'BEFORE_SYMBOL',
        self::BEGIN_SYMBOL => 'BEGIN_SYMBOL',
        self::BETWEEN_SYMBOL => 'BETWEEN_SYMBOL',
        self::BINLOG_SYMBOL => 'BINLOG_SYMBOL',
        self::BIT_AND_SYMBOL => 'BIT_AND_SYMBOL',
        self::BIT_OR_SYMBOL => 'BIT_OR_SYMBOL',
        self::BIT_XOR_SYMBOL => 'BIT_XOR_SYMBOL',
        self::BLOCK_SYMBOL => 'BLOCK_SYMBOL',
        self::BOOL_SYMBOL => 'BOOL_SYMBOL',
        self::BOOLEAN_SYMBOL => 'BOOLEAN_SYMBOL',
        self::BOTH_SYMBOL => 'BOTH_SYMBOL',
        self::BTREE_SYMBOL => 'BTREE_SYMBOL',
        self::BY_SYMBOL => 'BY_SYMBOL',
        self::BYTE_SYMBOL => 'BYTE_SYMBOL',
        self::CACHE_SYMBOL => 'CACHE_SYMBOL',
        self::CALL_SYMBOL => 'CALL_SYMBOL',
        self::CASCADE_SYMBOL => 'CASCADE_SYMBOL',
        self::CASCADED_SYMBOL => 'CASCADED_SYMBOL',
        self::CASE_SYMBOL => 'CASE_SYMBOL',
        self::CAST_SYMBOL => 'CAST_SYMBOL',
        self::CATALOG_NAME_SYMBOL => 'CATALOG_NAME_SYMBOL',
        self::CHAIN_SYMBOL => 'CHAIN_SYMBOL',
        self::CHANGE_SYMBOL => 'CHANGE_SYMBOL',
        self::CHANGED_SYMBOL => 'CHANGED_SYMBOL',
        self::CHANNEL_SYMBOL => 'CHANNEL_SYMBOL',
        self::CHARSET_SYMBOL => 'CHARSET_SYMBOL',
        self::CHARACTER_SYMBOL => 'CHARACTER_SYMBOL',
        self::CHECK_SYMBOL => 'CHECK_SYMBOL',
        self::CHECKSUM_SYMBOL => 'CHECKSUM_SYMBOL',
        self::CIPHER_SYMBOL => 'CIPHER_SYMBOL',
        self::CLASS_ORIGIN_SYMBOL => 'CLASS_ORIGIN_SYMBOL',
        self::CLIENT_SYMBOL => 'CLIENT_SYMBOL',
        self::CLOSE_SYMBOL => 'CLOSE_SYMBOL',
        self::COALESCE_SYMBOL => 'COALESCE_SYMBOL',
        self::CODE_SYMBOL => 'CODE_SYMBOL',
        self::COLLATE_SYMBOL => 'COLLATE_SYMBOL',
        self::COLLATION_SYMBOL => 'COLLATION_SYMBOL',
        self::COLUMN_FORMAT_SYMBOL => 'COLUMN_FORMAT_SYMBOL',
        self::COLUMN_NAME_SYMBOL => 'COLUMN_NAME_SYMBOL',
        self::COLUMNS_SYMBOL => 'COLUMNS_SYMBOL',
        self::COLUMN_SYMBOL => 'COLUMN_SYMBOL',
        self::COMMENT_SYMBOL => 'COMMENT_SYMBOL',
        self::COMMITTED_SYMBOL => 'COMMITTED_SYMBOL',
        self::COMMIT_SYMBOL => 'COMMIT_SYMBOL',
        self::COMPACT_SYMBOL => 'COMPACT_SYMBOL',
        self::COMPLETION_SYMBOL => 'COMPLETION_SYMBOL',
        self::COMPRESSED_SYMBOL => 'COMPRESSED_SYMBOL',
        self::COMPRESSION_SYMBOL => 'COMPRESSION_SYMBOL',
        self::CONCURRENT_SYMBOL => 'CONCURRENT_SYMBOL',
        self::CONDITION_SYMBOL => 'CONDITION_SYMBOL',
        self::CONNECTION_SYMBOL => 'CONNECTION_SYMBOL',
        self::CONSISTENT_SYMBOL => 'CONSISTENT_SYMBOL',
        self::CONSTRAINT_SYMBOL => 'CONSTRAINT_SYMBOL',
        self::CONSTRAINT_CATALOG_SYMBOL => 'CONSTRAINT_CATALOG_SYMBOL',
        self::CONSTRAINT_NAME_SYMBOL => 'CONSTRAINT_NAME_SYMBOL',
        self::CONSTRAINT_SCHEMA_SYMBOL => 'CONSTRAINT_SCHEMA_SYMBOL',
        self::CONTAINS_SYMBOL => 'CONTAINS_SYMBOL',
        self::CONTEXT_SYMBOL => 'CONTEXT_SYMBOL',
        self::CONTINUE_SYMBOL => 'CONTINUE_SYMBOL',
        self::CONTRIBUTORS_SYMBOL => 'CONTRIBUTORS_SYMBOL',
        self::CONVERT_SYMBOL => 'CONVERT_SYMBOL',
        self::COUNT_SYMBOL => 'COUNT_SYMBOL',
        self::CPU_SYMBOL => 'CPU_SYMBOL',
        self::CREATE_SYMBOL => 'CREATE_SYMBOL',
        self::CROSS_SYMBOL => 'CROSS_SYMBOL',
        self::CUBE_SYMBOL => 'CUBE_SYMBOL',
        self::CURDATE_SYMBOL => 'CURDATE_SYMBOL',
        self::CURRENT_DATE_SYMBOL => 'CURRENT_DATE_SYMBOL',
        self::CURRENT_TIME_SYMBOL => 'CURRENT_TIME_SYMBOL',
        self::CURRENT_TIMESTAMP_SYMBOL => 'CURRENT_TIMESTAMP_SYMBOL',
        self::CURRENT_USER_SYMBOL => 'CURRENT_USER_SYMBOL',
        self::CURRENT_SYMBOL => 'CURRENT_SYMBOL',
        self::CURSOR_SYMBOL => 'CURSOR_SYMBOL',
        self::CURSOR_NAME_SYMBOL => 'CURSOR_NAME_SYMBOL',
        self::CURTIME_SYMBOL => 'CURTIME_SYMBOL',
        self::DATABASE_SYMBOL => 'DATABASE_SYMBOL',
        self::DATABASES_SYMBOL => 'DATABASES_SYMBOL',
        self::DATAFILE_SYMBOL => 'DATAFILE_SYMBOL',
        self::DATA_SYMBOL => 'DATA_SYMBOL',
        self::DATE_ADD_SYMBOL => 'DATE_ADD_SYMBOL',
        self::DATE_SUB_SYMBOL => 'DATE_SUB_SYMBOL',
        self::DAY_HOUR_SYMBOL => 'DAY_HOUR_SYMBOL',
        self::DAY_MICROSECOND_SYMBOL => 'DAY_MICROSECOND_SYMBOL',
        self::DAY_MINUTE_SYMBOL => 'DAY_MINUTE_SYMBOL',
        self::DAY_SECOND_SYMBOL => 'DAY_SECOND_SYMBOL',
        self::DAY_SYMBOL => 'DAY_SYMBOL',
        self::DAYOFMONTH_SYMBOL => 'DAYOFMONTH_SYMBOL',
        self::DEALLOCATE_SYMBOL => 'DEALLOCATE_SYMBOL',
        self::DEC_SYMBOL => 'DEC_SYMBOL',
        self::DECLARE_SYMBOL => 'DECLARE_SYMBOL',
        self::DEFAULT_SYMBOL => 'DEFAULT_SYMBOL',
        self::DEFAULT_AUTH_SYMBOL => 'DEFAULT_AUTH_SYMBOL',
        self::DEFINER_SYMBOL => 'DEFINER_SYMBOL',
        self::DELAYED_SYMBOL => 'DELAYED_SYMBOL',
        self::DELAY_KEY_WRITE_SYMBOL => 'DELAY_KEY_WRITE_SYMBOL',
        self::DELETE_SYMBOL => 'DELETE_SYMBOL',
        self::DESC_SYMBOL => 'DESC_SYMBOL',
        self::DESCRIBE_SYMBOL => 'DESCRIBE_SYMBOL',
        self::DES_KEY_FILE_SYMBOL => 'DES_KEY_FILE_SYMBOL',
        self::DETERMINISTIC_SYMBOL => 'DETERMINISTIC_SYMBOL',
        self::DIAGNOSTICS_SYMBOL => 'DIAGNOSTICS_SYMBOL',
        self::DIRECTORY_SYMBOL => 'DIRECTORY_SYMBOL',
        self::DISABLE_SYMBOL => 'DISABLE_SYMBOL',
        self::DISCARD_SYMBOL => 'DISCARD_SYMBOL',
        self::DISK_SYMBOL => 'DISK_SYMBOL',
        self::DISTINCT_SYMBOL => 'DISTINCT_SYMBOL',
        self::DISTINCTROW_SYMBOL => 'DISTINCTROW_SYMBOL',
        self::DIV_SYMBOL => 'DIV_SYMBOL',
        self::DO_SYMBOL => 'DO_SYMBOL',
        self::DROP_SYMBOL => 'DROP_SYMBOL',
        self::DUAL_SYMBOL => 'DUAL_SYMBOL',
        self::DUMPFILE_SYMBOL => 'DUMPFILE_SYMBOL',
        self::DUPLICATE_SYMBOL => 'DUPLICATE_SYMBOL',
        self::DYNAMIC_SYMBOL => 'DYNAMIC_SYMBOL',
        self::EACH_SYMBOL => 'EACH_SYMBOL',
        self::ELSE_SYMBOL => 'ELSE_SYMBOL',
        self::ELSEIF_SYMBOL => 'ELSEIF_SYMBOL',
        self::EMPTY_SYMBOL => 'EMPTY_SYMBOL',
        self::ENABLE_SYMBOL => 'ENABLE_SYMBOL',
        self::ENCLOSED_SYMBOL => 'ENCLOSED_SYMBOL',
        self::ENCRYPTION_SYMBOL => 'ENCRYPTION_SYMBOL',
        self::END_SYMBOL => 'END_SYMBOL',
        self::ENDS_SYMBOL => 'ENDS_SYMBOL',
        self::ENFORCED_SYMBOL => 'ENFORCED_SYMBOL',
        self::ENGINES_SYMBOL => 'ENGINES_SYMBOL',
        self::ENGINE_SYMBOL => 'ENGINE_SYMBOL',
        self::ERROR_SYMBOL => 'ERROR_SYMBOL',
        self::ERRORS_SYMBOL => 'ERRORS_SYMBOL',
        self::ESCAPED_SYMBOL => 'ESCAPED_SYMBOL',
        self::ESCAPE_SYMBOL => 'ESCAPE_SYMBOL',
        self::EVENT_SYMBOL => 'EVENT_SYMBOL',
        self::EVENTS_SYMBOL => 'EVENTS_SYMBOL',
        self::EVERY_SYMBOL => 'EVERY_SYMBOL',
        self::EXCHANGE_SYMBOL => 'EXCHANGE_SYMBOL',
        self::EXCEPT_SYMBOL => 'EXCEPT_SYMBOL',
        self::EXECUTE_SYMBOL => 'EXECUTE_SYMBOL',
        self::EXISTS_SYMBOL => 'EXISTS_SYMBOL',
        self::EXIT_SYMBOL => 'EXIT_SYMBOL',
        self::EXPANSION_SYMBOL => 'EXPANSION_SYMBOL',
        self::EXPIRE_SYMBOL => 'EXPIRE_SYMBOL',
        self::EXPLAIN_SYMBOL => 'EXPLAIN_SYMBOL',
        self::EXPORT_SYMBOL => 'EXPORT_SYMBOL',
        self::EXTENDED_SYMBOL => 'EXTENDED_SYMBOL',
        self::EXTENT_SIZE_SYMBOL => 'EXTENT_SIZE_SYMBOL',
        self::EXTRACT_SYMBOL => 'EXTRACT_SYMBOL',
        self::FALSE_SYMBOL => 'FALSE_SYMBOL',
        self::FAST_SYMBOL => 'FAST_SYMBOL',
        self::FAULTS_SYMBOL => 'FAULTS_SYMBOL',
        self::FETCH_SYMBOL => 'FETCH_SYMBOL',
        self::FIELDS_SYMBOL => 'FIELDS_SYMBOL',
        self::FILE_BLOCK_SIZE_SYMBOL => 'FILE_BLOCK_SIZE_SYMBOL',
        self::FILE_SYMBOL => 'FILE_SYMBOL',
        self::FILTER_SYMBOL => 'FILTER_SYMBOL',
        self::FIRST_SYMBOL => 'FIRST_SYMBOL',
        self::FIRST_VALUE_SYMBOL => 'FIRST_VALUE_SYMBOL',
        self::FIXED_SYMBOL => 'FIXED_SYMBOL',
        self::FLOAT4_SYMBOL => 'FLOAT4_SYMBOL',
        self::FLOAT8_SYMBOL => 'FLOAT8_SYMBOL',
        self::FLUSH_SYMBOL => 'FLUSH_SYMBOL',
        self::FOLLOWS_SYMBOL => 'FOLLOWS_SYMBOL',
        self::FORCE_SYMBOL => 'FORCE_SYMBOL',
        self::FOREIGN_SYMBOL => 'FOREIGN_SYMBOL',
        self::FOR_SYMBOL => 'FOR_SYMBOL',
        self::FORMAT_SYMBOL => 'FORMAT_SYMBOL',
        self::FOUND_SYMBOL => 'FOUND_SYMBOL',
        self::FROM_SYMBOL => 'FROM_SYMBOL',
        self::FULLTEXT_SYMBOL => 'FULLTEXT_SYMBOL',
        self::FULL_SYMBOL => 'FULL_SYMBOL',
        self::FUNCTION_SYMBOL => 'FUNCTION_SYMBOL',
        self::GENERATED_SYMBOL => 'GENERATED_SYMBOL',
        self::GENERAL_SYMBOL => 'GENERAL_SYMBOL',
        self::GET_FORMAT_SYMBOL => 'GET_FORMAT_SYMBOL',
        self::GET_MASTER_PUBLIC_KEY_SYMBOL => 'GET_MASTER_PUBLIC_KEY_SYMBOL',
        self::GLOBAL_SYMBOL => 'GLOBAL_SYMBOL',
        self::GRANT_SYMBOL => 'GRANT_SYMBOL',
        self::GRANTS_SYMBOL => 'GRANTS_SYMBOL',
        self::GROUP_CONCAT_SYMBOL => 'GROUP_CONCAT_SYMBOL',
        self::GROUP_REPLICATION_SYMBOL => 'GROUP_REPLICATION_SYMBOL',
        self::GROUP_SYMBOL => 'GROUP_SYMBOL',
        self::HANDLER_SYMBOL => 'HANDLER_SYMBOL',
        self::HASH_SYMBOL => 'HASH_SYMBOL',
        self::HAVING_SYMBOL => 'HAVING_SYMBOL',
        self::HELP_SYMBOL => 'HELP_SYMBOL',
        self::HIGH_PRIORITY_SYMBOL => 'HIGH_PRIORITY_SYMBOL',
        self::HISTOGRAM_SYMBOL => 'HISTOGRAM_SYMBOL',
        self::HISTORY_SYMBOL => 'HISTORY_SYMBOL',
        self::HOST_SYMBOL => 'HOST_SYMBOL',
        self::HOSTS_SYMBOL => 'HOSTS_SYMBOL',
        self::HOUR_MICROSECOND_SYMBOL => 'HOUR_MICROSECOND_SYMBOL',
        self::HOUR_MINUTE_SYMBOL => 'HOUR_MINUTE_SYMBOL',
        self::HOUR_SECOND_SYMBOL => 'HOUR_SECOND_SYMBOL',
        self::HOUR_SYMBOL => 'HOUR_SYMBOL',
        self::IDENTIFIED_SYMBOL => 'IDENTIFIED_SYMBOL',
        self::IF_SYMBOL => 'IF_SYMBOL',
        self::IGNORE_SYMBOL => 'IGNORE_SYMBOL',
        self::IGNORE_SERVER_IDS_SYMBOL => 'IGNORE_SERVER_IDS_SYMBOL',
        self::IMPORT_SYMBOL => 'IMPORT_SYMBOL',
        self::IN_SYMBOL => 'IN_SYMBOL',
        self::INDEXES_SYMBOL => 'INDEXES_SYMBOL',
        self::INDEX_SYMBOL => 'INDEX_SYMBOL',
        self::INFILE_SYMBOL => 'INFILE_SYMBOL',
        self::INITIAL_SIZE_SYMBOL => 'INITIAL_SIZE_SYMBOL',
        self::INNER_SYMBOL => 'INNER_SYMBOL',
        self::INOUT_SYMBOL => 'INOUT_SYMBOL',
        self::INSENSITIVE_SYMBOL => 'INSENSITIVE_SYMBOL',
        self::INSERT_SYMBOL => 'INSERT_SYMBOL',
        self::INSERT_METHOD_SYMBOL => 'INSERT_METHOD_SYMBOL',
        self::INSTANCE_SYMBOL => 'INSTANCE_SYMBOL',
        self::INSTALL_SYMBOL => 'INSTALL_SYMBOL',
        self::INTEGER_SYMBOL => 'INTEGER_SYMBOL',
        self::INTERVAL_SYMBOL => 'INTERVAL_SYMBOL',
        self::INTO_SYMBOL => 'INTO_SYMBOL',
        self::INVISIBLE_SYMBOL => 'INVISIBLE_SYMBOL',
        self::INVOKER_SYMBOL => 'INVOKER_SYMBOL',
        self::IO_SYMBOL => 'IO_SYMBOL',
        self::IPC_SYMBOL => 'IPC_SYMBOL',
        self::IS_SYMBOL => 'IS_SYMBOL',
        self::ISOLATION_SYMBOL => 'ISOLATION_SYMBOL',
        self::ISSUER_SYMBOL => 'ISSUER_SYMBOL',
        self::ITERATE_SYMBOL => 'ITERATE_SYMBOL',
        self::JOIN_SYMBOL => 'JOIN_SYMBOL',
        self::JSON_TABLE_SYMBOL => 'JSON_TABLE_SYMBOL',
        self::JSON_ARRAYAGG_SYMBOL => 'JSON_ARRAYAGG_SYMBOL',
        self::JSON_OBJECTAGG_SYMBOL => 'JSON_OBJECTAGG_SYMBOL',
        self::KEYS_SYMBOL => 'KEYS_SYMBOL',
        self::KEY_BLOCK_SIZE_SYMBOL => 'KEY_BLOCK_SIZE_SYMBOL',
        self::KEY_SYMBOL => 'KEY_SYMBOL',
        self::KILL_SYMBOL => 'KILL_SYMBOL',
        self::LANGUAGE_SYMBOL => 'LANGUAGE_SYMBOL',
        self::LAST_SYMBOL => 'LAST_SYMBOL',
        self::LAST_VALUE_SYMBOL => 'LAST_VALUE_SYMBOL',
        self::LATERAL_SYMBOL => 'LATERAL_SYMBOL',
        self::LEAD_SYMBOL => 'LEAD_SYMBOL',
        self::LEADING_SYMBOL => 'LEADING_SYMBOL',
        self::LEAVE_SYMBOL => 'LEAVE_SYMBOL',
        self::LEAVES_SYMBOL => 'LEAVES_SYMBOL',
        self::LEFT_SYMBOL => 'LEFT_SYMBOL',
        self::LESS_SYMBOL => 'LESS_SYMBOL',
        self::LEVEL_SYMBOL => 'LEVEL_SYMBOL',
        self::LIKE_SYMBOL => 'LIKE_SYMBOL',
        self::LIMIT_SYMBOL => 'LIMIT_SYMBOL',
        self::LINEAR_SYMBOL => 'LINEAR_SYMBOL',
        self::LINES_SYMBOL => 'LINES_SYMBOL',
        self::LIST_SYMBOL => 'LIST_SYMBOL',
        self::LOAD_SYMBOL => 'LOAD_SYMBOL',
        self::LOCALTIME_SYMBOL => 'LOCALTIME_SYMBOL',
        self::LOCALTIMESTAMP_SYMBOL => 'LOCALTIMESTAMP_SYMBOL',
        self::LOCAL_SYMBOL => 'LOCAL_SYMBOL',
        self::LOCATOR_SYMBOL => 'LOCATOR_SYMBOL',
        self::LOCK_SYMBOL => 'LOCK_SYMBOL',
        self::LOCKS_SYMBOL => 'LOCKS_SYMBOL',
        self::LOGFILE_SYMBOL => 'LOGFILE_SYMBOL',
        self::LOGS_SYMBOL => 'LOGS_SYMBOL',
        self::LOOP_SYMBOL => 'LOOP_SYMBOL',
        self::LOW_PRIORITY_SYMBOL => 'LOW_PRIORITY_SYMBOL',
        self::MASTER_SYMBOL => 'MASTER_SYMBOL',
        self::MASTER_AUTO_POSITION_SYMBOL => 'MASTER_AUTO_POSITION_SYMBOL',
        self::MASTER_BIND_SYMBOL => 'MASTER_BIND_SYMBOL',
        self::MASTER_CONNECT_RETRY_SYMBOL => 'MASTER_CONNECT_RETRY_SYMBOL',
        self::MASTER_DELAY_SYMBOL => 'MASTER_DELAY_SYMBOL',
        self::MASTER_HEARTBEAT_PERIOD_SYMBOL => 'MASTER_HEARTBEAT_PERIOD_SYMBOL',
        self::MASTER_HOST_SYMBOL => 'MASTER_HOST_SYMBOL',
        self::NETWORK_NAMESPACE_SYMBOL => 'NETWORK_NAMESPACE_SYMBOL',
        self::MASTER_LOG_FILE_SYMBOL => 'MASTER_LOG_FILE_SYMBOL',
        self::MASTER_LOG_POS_SYMBOL => 'MASTER_LOG_POS_SYMBOL',
        self::MASTER_PASSWORD_SYMBOL => 'MASTER_PASSWORD_SYMBOL',
        self::MASTER_PORT_SYMBOL => 'MASTER_PORT_SYMBOL',
        self::MASTER_PUBLIC_KEY_PATH_SYMBOL => 'MASTER_PUBLIC_KEY_PATH_SYMBOL',
        self::MASTER_RETRY_COUNT_SYMBOL => 'MASTER_RETRY_COUNT_SYMBOL',
        self::MASTER_SERVER_ID_SYMBOL => 'MASTER_SERVER_ID_SYMBOL',
        self::MASTER_SSL_CAPATH_SYMBOL => 'MASTER_SSL_CAPATH_SYMBOL',
        self::MASTER_SSL_CA_SYMBOL => 'MASTER_SSL_CA_SYMBOL',
        self::MASTER_SSL_CERT_SYMBOL => 'MASTER_SSL_CERT_SYMBOL',
        self::MASTER_SSL_CIPHER_SYMBOL => 'MASTER_SSL_CIPHER_SYMBOL',
        self::MASTER_SSL_CRL_SYMBOL => 'MASTER_SSL_CRL_SYMBOL',
        self::MASTER_SSL_CRLPATH_SYMBOL => 'MASTER_SSL_CRLPATH_SYMBOL',
        self::MASTER_SSL_KEY_SYMBOL => 'MASTER_SSL_KEY_SYMBOL',
        self::MASTER_SSL_SYMBOL => 'MASTER_SSL_SYMBOL',
        self::MASTER_SSL_VERIFY_SERVER_CERT_SYMBOL => 'MASTER_SSL_VERIFY_SERVER_CERT_SYMBOL',
        self::MASTER_TLS_VERSION_SYMBOL => 'MASTER_TLS_VERSION_SYMBOL',
        self::MASTER_TLS_CIPHERSUITES_SYMBOL => 'MASTER_TLS_CIPHERSUITES_SYMBOL',
        self::MASTER_USER_SYMBOL => 'MASTER_USER_SYMBOL',
        self::MASTER_ZSTD_COMPRESSION_LEVEL_SYMBOL => 'MASTER_ZSTD_COMPRESSION_LEVEL_SYMBOL',
        self::MATCH_SYMBOL => 'MATCH_SYMBOL',
        self::MAX_CONNECTIONS_PER_HOUR_SYMBOL => 'MAX_CONNECTIONS_PER_HOUR_SYMBOL',
        self::MAX_QUERIES_PER_HOUR_SYMBOL => 'MAX_QUERIES_PER_HOUR_SYMBOL',
        self::MAX_ROWS_SYMBOL => 'MAX_ROWS_SYMBOL',
        self::MAX_SIZE_SYMBOL => 'MAX_SIZE_SYMBOL',
        self::MAX_STATEMENT_TIME_SYMBOL => 'MAX_STATEMENT_TIME_SYMBOL',
        self::MAX_UPDATES_PER_HOUR_SYMBOL => 'MAX_UPDATES_PER_HOUR_SYMBOL',
        self::MAX_USER_CONNECTIONS_SYMBOL => 'MAX_USER_CONNECTIONS_SYMBOL',
        self::MAXVALUE_SYMBOL => 'MAXVALUE_SYMBOL',
        self::MAX_SYMBOL => 'MAX_SYMBOL',
        self::MEDIUM_SYMBOL => 'MEDIUM_SYMBOL',
        self::MEMBER_SYMBOL => 'MEMBER_SYMBOL',
        self::MEMORY_SYMBOL => 'MEMORY_SYMBOL',
        self::MERGE_SYMBOL => 'MERGE_SYMBOL',
        self::MESSAGE_TEXT_SYMBOL => 'MESSAGE_TEXT_SYMBOL',
        self::MICROSECOND_SYMBOL => 'MICROSECOND_SYMBOL',
        self::MIDDLEINT_SYMBOL => 'MIDDLEINT_SYMBOL',
        self::MIGRATE_SYMBOL => 'MIGRATE_SYMBOL',
        self::MINUTE_MICROSECOND_SYMBOL => 'MINUTE_MICROSECOND_SYMBOL',
        self::MINUTE_SECOND_SYMBOL => 'MINUTE_SECOND_SYMBOL',
        self::MINUTE_SYMBOL => 'MINUTE_SYMBOL',
        self::MIN_ROWS_SYMBOL => 'MIN_ROWS_SYMBOL',
        self::MIN_SYMBOL => 'MIN_SYMBOL',
        self::MODE_SYMBOL => 'MODE_SYMBOL',
        self::MODIFIES_SYMBOL => 'MODIFIES_SYMBOL',
        self::MODIFY_SYMBOL => 'MODIFY_SYMBOL',
        self::MOD_SYMBOL => 'MOD_SYMBOL',
        self::MONTH_SYMBOL => 'MONTH_SYMBOL',
        self::MUTEX_SYMBOL => 'MUTEX_SYMBOL',
        self::MYSQL_ERRNO_SYMBOL => 'MYSQL_ERRNO_SYMBOL',
        self::NAME_SYMBOL => 'NAME_SYMBOL',
        self::NAMES_SYMBOL => 'NAMES_SYMBOL',
        self::NATIONAL_SYMBOL => 'NATIONAL_SYMBOL',
        self::NATURAL_SYMBOL => 'NATURAL_SYMBOL',
        self::NCHAR_SYMBOL => 'NCHAR_SYMBOL',
        self::NDBCLUSTER_SYMBOL => 'NDBCLUSTER_SYMBOL',
        self::NDB_SYMBOL => 'NDB_SYMBOL',
        self::NEG_SYMBOL => 'NEG_SYMBOL',
        self::NESTED_SYMBOL => 'NESTED_SYMBOL',
        self::NEVER_SYMBOL => 'NEVER_SYMBOL',
        self::NEW_SYMBOL => 'NEW_SYMBOL',
        self::NEXT_SYMBOL => 'NEXT_SYMBOL',
        self::NODEGROUP_SYMBOL => 'NODEGROUP_SYMBOL',
        self::NONE_SYMBOL => 'NONE_SYMBOL',
        self::NONBLOCKING_SYMBOL => 'NONBLOCKING_SYMBOL',
        self::NOT_SYMBOL => 'NOT_SYMBOL',
        self::NOWAIT_SYMBOL => 'NOWAIT_SYMBOL',
        self::NO_WAIT_SYMBOL => 'NO_WAIT_SYMBOL',
        self::NO_WRITE_TO_BINLOG_SYMBOL => 'NO_WRITE_TO_BINLOG_SYMBOL',
        self::NULL_SYMBOL => 'NULL_SYMBOL',
        self::NULLS_SYMBOL => 'NULLS_SYMBOL',
        self::NUMBER_SYMBOL => 'NUMBER_SYMBOL',
        self::NVARCHAR_SYMBOL => 'NVARCHAR_SYMBOL',
        self::NTH_VALUE_SYMBOL => 'NTH_VALUE_SYMBOL',
        self::NTILE_SYMBOL => 'NTILE_SYMBOL',
        self::OF_SYMBOL => 'OF_SYMBOL',
        self::OFF_SYMBOL => 'OFF_SYMBOL',
        self::OFFLINE_SYMBOL => 'OFFLINE_SYMBOL',
        self::OFFSET_SYMBOL => 'OFFSET_SYMBOL',
        self::OJ_SYMBOL => 'OJ_SYMBOL',
        self::OLD_PASSWORD_SYMBOL => 'OLD_PASSWORD_SYMBOL',
        self::OLD_SYMBOL => 'OLD_SYMBOL',
        self::ON_SYMBOL => 'ON_SYMBOL',
        self::ONLINE_SYMBOL => 'ONLINE_SYMBOL',
        self::ONE_SYMBOL => 'ONE_SYMBOL',
        self::ONLY_SYMBOL => 'ONLY_SYMBOL',
        self::OPEN_SYMBOL => 'OPEN_SYMBOL',
        self::OPTIONAL_SYMBOL => 'OPTIONAL_SYMBOL',
        self::OPTIONALLY_SYMBOL => 'OPTIONALLY_SYMBOL',
        self::OPTIONS_SYMBOL => 'OPTIONS_SYMBOL',
        self::OPTION_SYMBOL => 'OPTION_SYMBOL',
        self::OPTIMIZE_SYMBOL => 'OPTIMIZE_SYMBOL',
        self::OPTIMIZER_COSTS_SYMBOL => 'OPTIMIZER_COSTS_SYMBOL',
        self::ORDER_SYMBOL => 'ORDER_SYMBOL',
        self::ORDINALITY_SYMBOL => 'ORDINALITY_SYMBOL',
        self::ORGANIZATION_SYMBOL => 'ORGANIZATION_SYMBOL',
        self::OR_SYMBOL => 'OR_SYMBOL',
        self::OTHERS_SYMBOL => 'OTHERS_SYMBOL',
        self::OUTER_SYMBOL => 'OUTER_SYMBOL',
        self::OUTFILE_SYMBOL => 'OUTFILE_SYMBOL',
        self::OUT_SYMBOL => 'OUT_SYMBOL',
        self::OWNER_SYMBOL => 'OWNER_SYMBOL',
        self::PACK_KEYS_SYMBOL => 'PACK_KEYS_SYMBOL',
        self::PAGE_SYMBOL => 'PAGE_SYMBOL',
        self::PARSER_SYMBOL => 'PARSER_SYMBOL',
        self::PARTIAL_SYMBOL => 'PARTIAL_SYMBOL',
        self::PARTITIONING_SYMBOL => 'PARTITIONING_SYMBOL',
        self::PARTITIONS_SYMBOL => 'PARTITIONS_SYMBOL',
        self::PARTITION_SYMBOL => 'PARTITION_SYMBOL',
        self::PASSWORD_SYMBOL => 'PASSWORD_SYMBOL',
        self::PATH_SYMBOL => 'PATH_SYMBOL',
        self::PERCENT_RANK_SYMBOL => 'PERCENT_RANK_SYMBOL',
        self::PERSIST_SYMBOL => 'PERSIST_SYMBOL',
        self::PERSIST_ONLY_SYMBOL => 'PERSIST_ONLY_SYMBOL',
        self::PHASE_SYMBOL => 'PHASE_SYMBOL',
        self::PLUGIN_SYMBOL => 'PLUGIN_SYMBOL',
        self::PLUGINS_SYMBOL => 'PLUGINS_SYMBOL',
        self::PLUGIN_DIR_SYMBOL => 'PLUGIN_DIR_SYMBOL',
        self::PORT_SYMBOL => 'PORT_SYMBOL',
        self::POSITION_SYMBOL => 'POSITION_SYMBOL',
        self::PRECEDES_SYMBOL => 'PRECEDES_SYMBOL',
        self::PRECEDING_SYMBOL => 'PRECEDING_SYMBOL',
        self::PRECISION_SYMBOL => 'PRECISION_SYMBOL',
        self::PREPARE_SYMBOL => 'PREPARE_SYMBOL',
        self::PRESERVE_SYMBOL => 'PRESERVE_SYMBOL',
        self::PREV_SYMBOL => 'PREV_SYMBOL',
        self::PRIMARY_SYMBOL => 'PRIMARY_SYMBOL',
        self::PRIVILEGES_SYMBOL => 'PRIVILEGES_SYMBOL',
        self::PRIVILEGE_CHECKS_USER_SYMBOL => 'PRIVILEGE_CHECKS_USER_SYMBOL',
        self::PROCEDURE_SYMBOL => 'PROCEDURE_SYMBOL',
        self::PROCESS_SYMBOL => 'PROCESS_SYMBOL',
        self::PROCESSLIST_SYMBOL => 'PROCESSLIST_SYMBOL',
        self::PROFILES_SYMBOL => 'PROFILES_SYMBOL',
        self::PROFILE_SYMBOL => 'PROFILE_SYMBOL',
        self::PROXY_SYMBOL => 'PROXY_SYMBOL',
        self::PURGE_SYMBOL => 'PURGE_SYMBOL',
        self::QUARTER_SYMBOL => 'QUARTER_SYMBOL',
        self::QUERY_SYMBOL => 'QUERY_SYMBOL',
        self::QUICK_SYMBOL => 'QUICK_SYMBOL',
        self::RANDOM_SYMBOL => 'RANDOM_SYMBOL',
        self::RANGE_SYMBOL => 'RANGE_SYMBOL',
        self::RANK_SYMBOL => 'RANK_SYMBOL',
        self::READS_SYMBOL => 'READS_SYMBOL',
        self::READ_ONLY_SYMBOL => 'READ_ONLY_SYMBOL',
        self::READ_SYMBOL => 'READ_SYMBOL',
        self::READ_WRITE_SYMBOL => 'READ_WRITE_SYMBOL',
        self::REBUILD_SYMBOL => 'REBUILD_SYMBOL',
        self::RECOVER_SYMBOL => 'RECOVER_SYMBOL',
        self::REDOFILE_SYMBOL => 'REDOFILE_SYMBOL',
        self::REDO_BUFFER_SIZE_SYMBOL => 'REDO_BUFFER_SIZE_SYMBOL',
        self::REDUNDANT_SYMBOL => 'REDUNDANT_SYMBOL',
        self::REFERENCES_SYMBOL => 'REFERENCES_SYMBOL',
        self::RECURSIVE_SYMBOL => 'RECURSIVE_SYMBOL',
        self::REGEXP_SYMBOL => 'REGEXP_SYMBOL',
        self::RELAYLOG_SYMBOL => 'RELAYLOG_SYMBOL',
        self::RELAY_SYMBOL => 'RELAY_SYMBOL',
        self::RELAY_LOG_FILE_SYMBOL => 'RELAY_LOG_FILE_SYMBOL',
        self::RELAY_LOG_POS_SYMBOL => 'RELAY_LOG_POS_SYMBOL',
        self::RELAY_THREAD_SYMBOL => 'RELAY_THREAD_SYMBOL',
        self::RELEASE_SYMBOL => 'RELEASE_SYMBOL',
        self::RELOAD_SYMBOL => 'RELOAD_SYMBOL',
        self::REMOTE_SYMBOL => 'REMOTE_SYMBOL',
        self::REMOVE_SYMBOL => 'REMOVE_SYMBOL',
        self::RENAME_SYMBOL => 'RENAME_SYMBOL',
        self::REORGANIZE_SYMBOL => 'REORGANIZE_SYMBOL',
        self::REPAIR_SYMBOL => 'REPAIR_SYMBOL',
        self::REPEAT_SYMBOL => 'REPEAT_SYMBOL',
        self::REPEATABLE_SYMBOL => 'REPEATABLE_SYMBOL',
        self::REPLACE_SYMBOL => 'REPLACE_SYMBOL',
        self::REPLICATION_SYMBOL => 'REPLICATION_SYMBOL',
        self::REPLICATE_DO_DB_SYMBOL => 'REPLICATE_DO_DB_SYMBOL',
        self::REPLICATE_IGNORE_DB_SYMBOL => 'REPLICATE_IGNORE_DB_SYMBOL',
        self::REPLICATE_DO_TABLE_SYMBOL => 'REPLICATE_DO_TABLE_SYMBOL',
        self::REPLICATE_IGNORE_TABLE_SYMBOL => 'REPLICATE_IGNORE_TABLE_SYMBOL',
        self::REPLICATE_WILD_DO_TABLE_SYMBOL => 'REPLICATE_WILD_DO_TABLE_SYMBOL',
        self::REPLICATE_WILD_IGNORE_TABLE_SYMBOL => 'REPLICATE_WILD_IGNORE_TABLE_SYMBOL',
        self::REPLICATE_REWRITE_DB_SYMBOL => 'REPLICATE_REWRITE_DB_SYMBOL',
        self::REQUIRE_SYMBOL => 'REQUIRE_SYMBOL',
        self::REQUIRE_ROW_FORMAT_SYMBOL => 'REQUIRE_ROW_FORMAT_SYMBOL',
        self::REQUIRE_TABLE_PRIMARY_KEY_CHECK_SYMBOL => 'REQUIRE_TABLE_PRIMARY_KEY_CHECK_SYMBOL',
        self::RESET_SYMBOL => 'RESET_SYMBOL',
        self::RESIGNAL_SYMBOL => 'RESIGNAL_SYMBOL',
        self::RESOURCE_SYMBOL => 'RESOURCE_SYMBOL',
        self::RESPECT_SYMBOL => 'RESPECT_SYMBOL',
        self::RESTART_SYMBOL => 'RESTART_SYMBOL',
        self::RESTORE_SYMBOL => 'RESTORE_SYMBOL',
        self::RESTRICT_SYMBOL => 'RESTRICT_SYMBOL',
        self::RESUME_SYMBOL => 'RESUME_SYMBOL',
        self::RETAIN_SYMBOL => 'RETAIN_SYMBOL',
        self::RETURNED_SQLSTATE_SYMBOL => 'RETURNED_SQLSTATE_SYMBOL',
        self::RETURNS_SYMBOL => 'RETURNS_SYMBOL',
        self::REUSE_SYMBOL => 'REUSE_SYMBOL',
        self::REVERSE_SYMBOL => 'REVERSE_SYMBOL',
        self::REVOKE_SYMBOL => 'REVOKE_SYMBOL',
        self::RIGHT_SYMBOL => 'RIGHT_SYMBOL',
        self::RLIKE_SYMBOL => 'RLIKE_SYMBOL',
        self::ROLE_SYMBOL => 'ROLE_SYMBOL',
        self::ROLLBACK_SYMBOL => 'ROLLBACK_SYMBOL',
        self::ROLLUP_SYMBOL => 'ROLLUP_SYMBOL',
        self::ROTATE_SYMBOL => 'ROTATE_SYMBOL',
        self::ROW_SYMBOL => 'ROW_SYMBOL',
        self::ROWS_SYMBOL => 'ROWS_SYMBOL',
        self::ROW_COUNT_SYMBOL => 'ROW_COUNT_SYMBOL',
        self::ROW_FORMAT_SYMBOL => 'ROW_FORMAT_SYMBOL',
        self::ROW_NUMBER_SYMBOL => 'ROW_NUMBER_SYMBOL',
        self::RTREE_SYMBOL => 'RTREE_SYMBOL',
        self::SAVEPOINT_SYMBOL => 'SAVEPOINT_SYMBOL',
        self::SCHEMA_SYMBOL => 'SCHEMA_SYMBOL',
        self::SCHEMAS_SYMBOL => 'SCHEMAS_SYMBOL',
        self::SCHEMA_NAME_SYMBOL => 'SCHEMA_NAME_SYMBOL',
        self::SCHEDULE_SYMBOL => 'SCHEDULE_SYMBOL',
        self::SECOND_MICROSECOND_SYMBOL => 'SECOND_MICROSECOND_SYMBOL',
        self::SECOND_SYMBOL => 'SECOND_SYMBOL',
        self::SECONDARY_SYMBOL => 'SECONDARY_SYMBOL',
        self::SECONDARY_ENGINE_SYMBOL => 'SECONDARY_ENGINE_SYMBOL',
        self::SECONDARY_LOAD_SYMBOL => 'SECONDARY_LOAD_SYMBOL',
        self::SECONDARY_UNLOAD_SYMBOL => 'SECONDARY_UNLOAD_SYMBOL',
        self::SECURITY_SYMBOL => 'SECURITY_SYMBOL',
        self::SELECT_SYMBOL => 'SELECT_SYMBOL',
        self::SENSITIVE_SYMBOL => 'SENSITIVE_SYMBOL',
        self::SEPARATOR_SYMBOL => 'SEPARATOR_SYMBOL',
        self::SERIALIZABLE_SYMBOL => 'SERIALIZABLE_SYMBOL',
        self::SERIAL_SYMBOL => 'SERIAL_SYMBOL',
        self::SERVER_SYMBOL => 'SERVER_SYMBOL',
        self::SERVER_OPTIONS_SYMBOL => 'SERVER_OPTIONS_SYMBOL',
        self::SESSION_SYMBOL => 'SESSION_SYMBOL',
        self::SESSION_USER_SYMBOL => 'SESSION_USER_SYMBOL',
        self::SET_VAR_SYMBOL => 'SET_VAR_SYMBOL',
        self::SHARE_SYMBOL => 'SHARE_SYMBOL',
        self::SHOW_SYMBOL => 'SHOW_SYMBOL',
        self::SHUTDOWN_SYMBOL => 'SHUTDOWN_SYMBOL',
        self::SIGNAL_SYMBOL => 'SIGNAL_SYMBOL',
        self::SIGNED_SYMBOL => 'SIGNED_SYMBOL',
        self::SIMPLE_SYMBOL => 'SIMPLE_SYMBOL',
        self::SKIP_SYMBOL => 'SKIP_SYMBOL',
        self::SLAVE_SYMBOL => 'SLAVE_SYMBOL',
        self::SLOW_SYMBOL => 'SLOW_SYMBOL',
        self::SNAPSHOT_SYMBOL => 'SNAPSHOT_SYMBOL',
        self::SOME_SYMBOL => 'SOME_SYMBOL',
        self::SOCKET_SYMBOL => 'SOCKET_SYMBOL',
        self::SONAME_SYMBOL => 'SONAME_SYMBOL',
        self::SOUNDS_SYMBOL => 'SOUNDS_SYMBOL',
        self::SOURCE_SYMBOL => 'SOURCE_SYMBOL',
        self::SPATIAL_SYMBOL => 'SPATIAL_SYMBOL',
        self::SQL_SYMBOL => 'SQL_SYMBOL',
        self::SQLEXCEPTION_SYMBOL => 'SQLEXCEPTION_SYMBOL',
        self::SQLSTATE_SYMBOL => 'SQLSTATE_SYMBOL',
        self::SQLWARNING_SYMBOL => 'SQLWARNING_SYMBOL',
        self::SQL_AFTER_GTIDS_SYMBOL => 'SQL_AFTER_GTIDS_SYMBOL',
        self::SQL_AFTER_MTS_GAPS_SYMBOL => 'SQL_AFTER_MTS_GAPS_SYMBOL',
        self::SQL_BEFORE_GTIDS_SYMBOL => 'SQL_BEFORE_GTIDS_SYMBOL',
        self::SQL_BIG_RESULT_SYMBOL => 'SQL_BIG_RESULT_SYMBOL',
        self::SQL_BUFFER_RESULT_SYMBOL => 'SQL_BUFFER_RESULT_SYMBOL',
        self::SQL_CALC_FOUND_ROWS_SYMBOL => 'SQL_CALC_FOUND_ROWS_SYMBOL',
        self::SQL_CACHE_SYMBOL => 'SQL_CACHE_SYMBOL',
        self::SQL_NO_CACHE_SYMBOL => 'SQL_NO_CACHE_SYMBOL',
        self::SQL_SMALL_RESULT_SYMBOL => 'SQL_SMALL_RESULT_SYMBOL',
        self::SQL_THREAD_SYMBOL => 'SQL_THREAD_SYMBOL',
        self::SQL_TSI_DAY_SYMBOL => 'SQL_TSI_DAY_SYMBOL',
        self::SQL_TSI_HOUR_SYMBOL => 'SQL_TSI_HOUR_SYMBOL',
        self::SQL_TSI_MICROSECOND_SYMBOL => 'SQL_TSI_MICROSECOND_SYMBOL',
        self::SQL_TSI_MINUTE_SYMBOL => 'SQL_TSI_MINUTE_SYMBOL',
        self::SQL_TSI_MONTH_SYMBOL => 'SQL_TSI_MONTH_SYMBOL',
        self::SQL_TSI_QUARTER_SYMBOL => 'SQL_TSI_QUARTER_SYMBOL',
        self::SQL_TSI_SECOND_SYMBOL => 'SQL_TSI_SECOND_SYMBOL',
        self::SQL_TSI_WEEK_SYMBOL => 'SQL_TSI_WEEK_SYMBOL',
        self::SQL_TSI_YEAR_SYMBOL => 'SQL_TSI_YEAR_SYMBOL',
        self::SRID_SYMBOL => 'SRID_SYMBOL',
        self::SSL_SYMBOL => 'SSL_SYMBOL',
        self::STACKED_SYMBOL => 'STACKED_SYMBOL',
        self::STARTING_SYMBOL => 'STARTING_SYMBOL',
        self::START_SYMBOL => 'START_SYMBOL',
        self::STARTS_SYMBOL => 'STARTS_SYMBOL',
        self::STATS_AUTO_RECALC_SYMBOL => 'STATS_AUTO_RECALC_SYMBOL',
        self::STATS_PERSISTENT_SYMBOL => 'STATS_PERSISTENT_SYMBOL',
        self::STATS_SAMPLE_PAGES_SYMBOL => 'STATS_SAMPLE_PAGES_SYMBOL',
        self::STATUS_SYMBOL => 'STATUS_SYMBOL',
        self::STD_SYMBOL => 'STD_SYMBOL',
        self::STDDEV_POP_SYMBOL => 'STDDEV_POP_SYMBOL',
        self::STDDEV_SAMP_SYMBOL => 'STDDEV_SAMP_SYMBOL',
        self::STDDEV_SYMBOL => 'STDDEV_SYMBOL',
        self::STOP_SYMBOL => 'STOP_SYMBOL',
        self::STORAGE_SYMBOL => 'STORAGE_SYMBOL',
        self::STORED_SYMBOL => 'STORED_SYMBOL',
        self::STRAIGHT_JOIN_SYMBOL => 'STRAIGHT_JOIN_SYMBOL',
        self::STREAM_SYMBOL => 'STREAM_SYMBOL',
        self::STRING_SYMBOL => 'STRING_SYMBOL',
        self::SUBCLASS_ORIGIN_SYMBOL => 'SUBCLASS_ORIGIN_SYMBOL',
        self::SUBDATE_SYMBOL => 'SUBDATE_SYMBOL',
        self::SUBJECT_SYMBOL => 'SUBJECT_SYMBOL',
        self::SUBPARTITIONS_SYMBOL => 'SUBPARTITIONS_SYMBOL',
        self::SUBPARTITION_SYMBOL => 'SUBPARTITION_SYMBOL',
        self::SUBSTR_SYMBOL => 'SUBSTR_SYMBOL',
        self::SUBSTRING_SYMBOL => 'SUBSTRING_SYMBOL',
        self::SUM_SYMBOL => 'SUM_SYMBOL',
        self::SUPER_SYMBOL => 'SUPER_SYMBOL',
        self::SUSPEND_SYMBOL => 'SUSPEND_SYMBOL',
        self::SWAPS_SYMBOL => 'SWAPS_SYMBOL',
        self::SWITCHES_SYMBOL => 'SWITCHES_SYMBOL',
        self::SYSDATE_SYMBOL => 'SYSDATE_SYMBOL',
        self::SYSTEM_SYMBOL => 'SYSTEM_SYMBOL',
        self::SYSTEM_USER_SYMBOL => 'SYSTEM_USER_SYMBOL',
        self::TABLE_SYMBOL => 'TABLE_SYMBOL',
        self::TABLES_SYMBOL => 'TABLES_SYMBOL',
        self::TABLESPACE_SYMBOL => 'TABLESPACE_SYMBOL',
        self::TABLE_CHECKSUM_SYMBOL => 'TABLE_CHECKSUM_SYMBOL',
        self::TABLE_NAME_SYMBOL => 'TABLE_NAME_SYMBOL',
        self::TEMPORARY_SYMBOL => 'TEMPORARY_SYMBOL',
        self::TEMPTABLE_SYMBOL => 'TEMPTABLE_SYMBOL',
        self::TERMINATED_SYMBOL => 'TERMINATED_SYMBOL',
        self::THAN_SYMBOL => 'THAN_SYMBOL',
        self::THEN_SYMBOL => 'THEN_SYMBOL',
        self::THREAD_PRIORITY_SYMBOL => 'THREAD_PRIORITY_SYMBOL',
        self::TIES_SYMBOL => 'TIES_SYMBOL',
        self::TIMESTAMP_ADD_SYMBOL => 'TIMESTAMP_ADD_SYMBOL',
        self::TIMESTAMP_DIFF_SYMBOL => 'TIMESTAMP_DIFF_SYMBOL',
        self::TO_SYMBOL =>       'TO_SYMBOL',
        self::TRAILING_SYMBOL => 'TRAILING_SYMBOL',
        self::TRANSACTION_SYMBOL => 'TRANSACTION_SYMBOL',
        self::TRIGGER_SYMBOL => 'TRIGGER_SYMBOL',
        self::TRIGGERS_SYMBOL => 'TRIGGERS_SYMBOL',
        self::TRIM_SYMBOL => 'TRIM_SYMBOL',
        self::TRUE_SYMBOL => 'TRUE_SYMBOL',
        self::TRUNCATE_SYMBOL => 'TRUNCATE_SYMBOL',
        self::TYPES_SYMBOL => 'TYPES_SYMBOL',
        self::TYPE_SYMBOL => 'TYPE_SYMBOL',
        self::UDF_RETURNS_SYMBOL => 'UDF_RETURNS_SYMBOL',
        self::UNBOUNDED_SYMBOL => 'UNBOUNDED_SYMBOL',
        self::UNCOMMITTED_SYMBOL => 'UNCOMMITTED_SYMBOL',
        self::UNDEFINED_SYMBOL => 'UNDEFINED_SYMBOL',
        self::UNDO_BUFFER_SIZE_SYMBOL => 'UNDO_BUFFER_SIZE_SYMBOL',
        self::UNDOFILE_SYMBOL => 'UNDOFILE_SYMBOL',
        self::UNDO_SYMBOL => 'UNDO_SYMBOL',
        self::UNICODE_SYMBOL => 'UNICODE_SYMBOL',
        self::UNION_SYMBOL => 'UNION_SYMBOL',
        self::UNIQUE_SYMBOL => 'UNIQUE_SYMBOL',
        self::UNKNOWN_SYMBOL => 'UNKNOWN_SYMBOL',
        self::UNINSTALL_SYMBOL => 'UNINSTALL_SYMBOL',
        self::UNSIGNED_SYMBOL => 'UNSIGNED_SYMBOL',
        self::UPDATE_SYMBOL => 'UPDATE_SYMBOL',
        self::UPGRADE_SYMBOL => 'UPGRADE_SYMBOL',
        self::USAGE_SYMBOL => 'USAGE_SYMBOL',
        self::USER_RESOURCES_SYMBOL => 'USER_RESOURCES_SYMBOL',
        self::USER_SYMBOL => 'USER_SYMBOL',
        self::USE_FRM_SYMBOL => 'USE_FRM_SYMBOL',
        self::USE_SYMBOL => 'USE_SYMBOL',
        self::USING_SYMBOL => 'USING_SYMBOL',
        self::UTC_DATE_SYMBOL => 'UTC_DATE_SYMBOL',
        self::UTC_TIME_SYMBOL => 'UTC_TIME_SYMBOL',
        self::UTC_TIMESTAMP_SYMBOL => 'UTC_TIMESTAMP_SYMBOL',
        self::VALIDATION_SYMBOL => 'VALIDATION_SYMBOL',
        self::VALUE_SYMBOL => 'VALUE_SYMBOL',
        self::VALUES_SYMBOL => 'VALUES_SYMBOL',
        self::VARCHARACTER_SYMBOL => 'VARCHARACTER_SYMBOL',
        self::VARIABLES_SYMBOL => 'VARIABLES_SYMBOL',
        self::VARIANCE_SYMBOL => 'VARIANCE_SYMBOL',
        self::VARYING_SYMBOL => 'VARYING_SYMBOL',
        self::VAR_POP_SYMBOL => 'VAR_POP_SYMBOL',
        self::VAR_SAMP_SYMBOL => 'VAR_SAMP_SYMBOL',
        self::VCPU_SYMBOL => 'VCPU_SYMBOL',
        self::VIEW_SYMBOL => 'VIEW_SYMBOL',
        self::VIRTUAL_SYMBOL => 'VIRTUAL_SYMBOL',
        self::VISIBLE_SYMBOL => 'VISIBLE_SYMBOL',
        self::WAIT_SYMBOL => 'WAIT_SYMBOL',
        self::WARNINGS_SYMBOL => 'WARNINGS_SYMBOL',
        self::WEEK_SYMBOL => 'WEEK_SYMBOL',
        self::WHEN_SYMBOL => 'WHEN_SYMBOL',
        self::WEIGHT_STRING_SYMBOL => 'WEIGHT_STRING_SYMBOL',
        self::WHERE_SYMBOL => 'WHERE_SYMBOL',
        self::WHILE_SYMBOL => 'WHILE_SYMBOL',
        self::WINDOW_SYMBOL => 'WINDOW_SYMBOL',
        self::WITH_SYMBOL => 'WITH_SYMBOL',
        self::WITHOUT_SYMBOL => 'WITHOUT_SYMBOL',
        self::WORK_SYMBOL => 'WORK_SYMBOL',
        self::WRAPPER_SYMBOL => 'WRAPPER_SYMBOL',
        self::WRITE_SYMBOL => 'WRITE_SYMBOL',
        self::XA_SYMBOL => 'XA_SYMBOL',
        self::X509_SYMBOL => 'X509_SYMBOL',
        self::XID_SYMBOL => 'XID_SYMBOL',
        self::XML_SYMBOL => 'XML_SYMBOL',
        self::XOR_SYMBOL => 'XOR_SYMBOL',
        self::YEAR_MONTH_SYMBOL => 'YEAR_MONTH_SYMBOL',
        self::ZEROFILL_SYMBOL => 'ZEROFILL_SYMBOL',
        self::INT1_SYMBOL => 'INT1_SYMBOL',
        self::INT2_SYMBOL => 'INT2_SYMBOL',
        self::INT3_SYMBOL => 'INT3_SYMBOL',
        self::INT4_SYMBOL => 'INT4_SYMBOL',
        self::INT8_SYMBOL => 'INT8_SYMBOL',
        self::IDENTIFIER => 'IDENTIFIER',
        self::BACK_TICK_QUOTED_ID => 'BACK_TICK_QUOTED_ID',
        self::DOUBLE_QUOTED_TEXT => 'DOUBLE_QUOTED_TEXT',
        self::SINGLE_QUOTED_TEXT => 'SINGLE_QUOTED_TEXT',
        self::HEX_NUMBER => 'HEX_NUMBER',
        self::BIN_NUMBER => 'BIN_NUMBER',
        self::DECIMAL_NUMBER => 'DECIMAL_NUMBER',
        self::FLOAT_NUMBER => 'FLOAT_NUMBER',
        self::UNDERSCORE_CHARSET => 'UNDERSCORE_CHARSET',
        self::DOT_IDENTIFIER => 'DOT_IDENTIFIER',
        self::INVALID_INPUT => 'INVALID_INPUT',
        self::LINEBREAK => 'LINEBREAK',

		// Missing from the generated lexer and added manually
		self::START_SYMBOL => 'START_SYMBOL',
		self::UNLOCK_SYMBOL => 'UNLOCK_SYMBOL',
		self::CLONE_SYMBOL => 'CLONE_SYMBOL',
		self::GET_SYMBOL => 'GET_SYMBOL',
		self::ASCII_SYMBOL => 'ASCII_SYMBOL',
		self::BIT_SYMBOL => 'BIT_SYMBOL',
		self::BUCKETS_SYMBOL => 'BUCKETS_SYMBOL',
		self::COMPONENT_SYMBOL => 'COMPONENT_SYMBOL',
		self::NOW_SYMBOL => 'NOW_SYMBOL',
		self::DEFINITION_SYMBOL => 'DEFINITION_SYMBOL',
		self::DENSE_RANK_SYMBOL => 'DENSE_RANK_SYMBOL',
		self::DESCRIPTION_SYMBOL => 'DESCRIPTION_SYMBOL',
		self::FAILED_LOGIN_ATTEMPTS_SYMBOL => 'FAILED_LOGIN_ATTEMPTS_SYMBOL',
		self::FOLLOWING_SYMBOL => 'FOLLOWING_SYMBOL',
		self::GROUPING_SYMBOL => 'GROUPING_SYMBOL',
		self::GROUPS_SYMBOL => 'GROUPS_SYMBOL',
		self::LAG_SYMBOL => 'LAG_SYMBOL',
		self::LONG_SYMBOL => 'LONG_SYMBOL',
		self::MASTER_COMPRESSION_ALGORITHM_SYMBOL => 'MASTER_COMPRESSION_ALGORITHM_SYMBOL',
		self::NOT2_SYMBOL => 'NOT2_SYMBOL',
		self::NO_SYMBOL => 'NO_SYMBOL',
		self::REFERENCE_SYMBOL => 'REFERENCE_SYMBOL',
		self::RETURN_SYMBOL => 'RETURN_SYMBOL',
		self::SPECIFIC_SYMBOL => 'SPECIFIC_SYMBOL',
		self::AUTHORS_SYMBOL => 'AUTHORS_SYMBOL',
		self::ADDDATE_SYMBOL => 'ADDDATE_SYMBOL',
		self::CONCAT_PIPES_SYMBOL => 'CONCAT_PIPES_SYMBOL',

		// Unused in this class but present in MySQLParser, hmm
		self::ACTIVE_SYMBOL => 'ACTIVE_SYMBOL',
		self::ADMIN_SYMBOL => 'ADMIN_SYMBOL',
		self::EXCLUDE_SYMBOL => 'EXCLUDE_SYMBOL',
		self::INACTIVE_SYMBOL => 'INACTIVE_SYMBOL',
		self::LOCKED_SYMBOL => 'LOCKED_SYMBOL',
		self::ROUTINE_SYMBOL => 'ROUTINE_SYMBOL',
		self::UNTIL_SYMBOL => 'UNTIL_SYMBOL',
		self::ARRAY_SYMBOL => 'ARRAY_SYMBOL',
		self::PASSWORD_LOCK_TIME_SYMBOL => 'PASSWORD_LOCK_TIME_SYMBOL',
		self::NCHAR_TEXT => 'NCHAR_TEXT',
		self::LONG_NUMBER => 'LONG_NUMBER',
		self::ULONGLONG_NUMBER => 'ULONGLONG_NUMBER',
		self::CUME_DIST_SYMBO => 'CUME_DIST_SYMBO',
		self::CUME_DIST_SYMBOL => 'CUME_DIST_SYMBOL',
		self::FOUND_ROWS_SYMBOL => 'FOUND_ROWS_SYMBOL',
		self::CONCAT_SYMBOL => 'CONCAT_SYMBOL',
		self::OVER_SYMBOL => 'OVER_SYMBOL',

		self::BIN_NUM_SYMBOL => 'BIN_NUM_SYMBOL',
		self::DECIMAL_NUM_SYMBOL => 'DECIMAL_NUM_SYMBOL',
		self::LONG_NUM_SYMBOL => 'LONG_NUM_SYMBOL',
		self::MID_SYMBOL => 'MID_SYMBOL',
		self::NCHAR_STRING_SYMBOL => 'NCHAR_STRING_SYMBOL',
		self::TABLE_REF_PRIORITY_SYMBOL => 'TABLE_REF_PRIORITY_SYMBOL',
		self::IO_AFTER_GTIDS_SYMBOL => 'IO_AFTER_GTIDS_SYMBOL',
		self::IO_BEFORE_GTIDS_SYMBOL => 'IO_BEFORE_GTIDS_SYMBOL',
		self::IO_THREAD_SYMBOL => 'IO_THREAD_SYMBOL',
    ];

	const TOKENS = [
		// Tokens from MySQL 5.7:
        'ACCESSIBLE' => self::ACCESSIBLE_SYMBOL,
        'ACCOUNT' => self::ACCOUNT_SYMBOL,
        'ACTION' => self::ACTION_SYMBOL,
        'ADD' => self::ADD_SYMBOL,
        'ADDDATE' => self::ADDDATE_SYMBOL,
        'AFTER' => self::AFTER_SYMBOL,
        'AGAINST' => self::AGAINST_SYMBOL,
        'AGGREGATE' => self::AGGREGATE_SYMBOL,
        'ALGORITHM' => self::ALGORITHM_SYMBOL,
        'ALL' => self::ALL_SYMBOL,
        'ALTER' => self::ALTER_SYMBOL,
        'ALWAYS' => self::ALWAYS_SYMBOL,
        'ANALYSE' => self::ANALYSE_SYMBOL,
        'ANALYZE' => self::ANALYZE_SYMBOL,
        'AND' => self::AND_SYMBOL,
        'ANY' => self::ANY_SYMBOL,
        'AS' => self::AS_SYMBOL,
        'ASC' => self::ASC_SYMBOL,
        'ASCII' => self::ASCII_SYMBOL,
        'ASENSITIVE' => self::ASENSITIVE_SYMBOL,
        'AT' => self::AT_SYMBOL,
        'AUTHORS' => self::AUTHORS_SYMBOL,
        'AUTO_INCREMENT' => self::AUTO_INCREMENT_SYMBOL,
        'AUTOEXTEND_SIZE' => self::AUTOEXTEND_SIZE_SYMBOL,
        'AVG' => self::AVG_SYMBOL,
        'AVG_ROW_LENGTH' => self::AVG_ROW_LENGTH_SYMBOL,
        'BACKUP' => self::BACKUP_SYMBOL,
        'BEFORE' => self::BEFORE_SYMBOL,
        'BEGIN' => self::BEGIN_SYMBOL,
        'BETWEEN' => self::BETWEEN_SYMBOL,
        'BIGINT' => self::BIGINT_SYMBOL,
        'BIN_NUM' => self::BIN_NUM_SYMBOL,
        'BINARY' => self::BINARY_SYMBOL,
        'BINLOG' => self::BINLOG_SYMBOL,
        'BIT' => self::BIT_SYMBOL,
        'BIT_AND' => self::BIT_AND_SYMBOL,
        'BIT_OR' => self::BIT_OR_SYMBOL,
        'BIT_XOR' => self::BIT_XOR_SYMBOL,
        'BLOB' => self::BLOB_SYMBOL,
        'BLOCK' => self::BLOCK_SYMBOL,
        'BOOL' => self::BOOL_SYMBOL,
        'BOOLEAN' => self::BOOLEAN_SYMBOL,
        'BOTH' => self::BOTH_SYMBOL,
        'BTREE' => self::BTREE_SYMBOL,
        'BY' => self::BY_SYMBOL,
        'BYTE' => self::BYTE_SYMBOL,
        'CACHE' => self::CACHE_SYMBOL,
        'CALL' => self::CALL_SYMBOL,
        'CASCADE' => self::CASCADE_SYMBOL,
        'CASCADED' => self::CASCADED_SYMBOL,
        'CASE' => self::CASE_SYMBOL,
        'CAST' => self::CAST_SYMBOL,
        'CATALOG_NAME' => self::CATALOG_NAME_SYMBOL,
        'CHAIN' => self::CHAIN_SYMBOL,
        'CHANGE' => self::CHANGE_SYMBOL,
        'CHANGED' => self::CHANGED_SYMBOL,
        'CHANNEL' => self::CHANNEL_SYMBOL,
        'CHAR' => self::CHAR_SYMBOL,
        'CHARACTER' => self::CHARACTER_SYMBOL,
        'CHARSET' => self::CHARSET_SYMBOL,
        'CHECK' => self::CHECK_SYMBOL,
        'CHECKSUM' => self::CHECKSUM_SYMBOL,
        'CIPHER' => self::CIPHER_SYMBOL,
        'CLASS_ORIGIN' => self::CLASS_ORIGIN_SYMBOL,
        'CLIENT' => self::CLIENT_SYMBOL,
        'CLOSE' => self::CLOSE_SYMBOL,
        'COALESCE' => self::COALESCE_SYMBOL,
        'CODE' => self::CODE_SYMBOL,
        'COLLATE' => self::COLLATE_SYMBOL,
        'COLLATION' => self::COLLATION_SYMBOL,
        'COLUMN' => self::COLUMN_SYMBOL,
        'COLUMN_FORMAT' => self::COLUMN_FORMAT_SYMBOL,
        'COLUMN_NAME' => self::COLUMN_NAME_SYMBOL,
        'COLUMNS' => self::COLUMNS_SYMBOL,
        'COMMENT' => self::COMMENT_SYMBOL,
        'COMMIT' => self::COMMIT_SYMBOL,
        'COMMITTED' => self::COMMITTED_SYMBOL,
        'COMPACT' => self::COMPACT_SYMBOL,
        'COMPLETION' => self::COMPLETION_SYMBOL,
        'COMPRESSED' => self::COMPRESSED_SYMBOL,
        'COMPRESSION' => self::COMPRESSION_SYMBOL,
        'CONCURRENT' => self::CONCURRENT_SYMBOL,
        'CONDITION' => self::CONDITION_SYMBOL,
        'CONNECTION' => self::CONNECTION_SYMBOL,
        'CONSISTENT' => self::CONSISTENT_SYMBOL,
        'CONSTRAINT' => self::CONSTRAINT_SYMBOL,
        'CONSTRAINT_CATALOG' => self::CONSTRAINT_CATALOG_SYMBOL,
        'CONSTRAINT_NAME' => self::CONSTRAINT_NAME_SYMBOL,
        'CONSTRAINT_SCHEMA' => self::CONSTRAINT_SCHEMA_SYMBOL,
        'CONTAINS' => self::CONTAINS_SYMBOL,
        'CONTEXT' => self::CONTEXT_SYMBOL,
        'CONTINUE' => self::CONTINUE_SYMBOL,
        'CONTRIBUTORS' => self::CONTRIBUTORS_SYMBOL,
        'CONVERT' => self::CONVERT_SYMBOL,
        'COUNT' => self::COUNT_SYMBOL,
        'CPU' => self::CPU_SYMBOL,
        'CREATE' => self::CREATE_SYMBOL,
        'CROSS' => self::CROSS_SYMBOL,
        'CUBE' => self::CUBE_SYMBOL,
        'CURDATE' => self::CURDATE_SYMBOL,
        'CURRENT' => self::CURRENT_SYMBOL,
        'CURRENT_DATE' => self::CURRENT_DATE_SYMBOL,
        'CURRENT_TIME' => self::CURRENT_TIME_SYMBOL,
        'CURRENT_TIMESTAMP' => self::CURRENT_TIMESTAMP_SYMBOL,
        'CURRENT_USER' => self::CURRENT_USER_SYMBOL,
        'CURSOR' => self::CURSOR_SYMBOL,
        'CURSOR_NAME' => self::CURSOR_NAME_SYMBOL,
        'CURTIME' => self::CURTIME_SYMBOL,
        'DATA' => self::DATA_SYMBOL,
        'DATABASE' => self::DATABASE_SYMBOL,
        'DATABASES' => self::DATABASES_SYMBOL,
        'DATAFILE' => self::DATAFILE_SYMBOL,
        'DATE' => self::DATE_SYMBOL,
        'DATE_ADD' => self::DATE_ADD_SYMBOL,
        'DATE_SUB' => self::DATE_SUB_SYMBOL,
        'DATETIME' => self::DATETIME_SYMBOL,
        'DAY' => self::DAY_SYMBOL,
        'DAY_HOUR' => self::DAY_HOUR_SYMBOL,
        'DAY_MICROSECOND' => self::DAY_MICROSECOND_SYMBOL,
        'DAY_MINUTE' => self::DAY_MINUTE_SYMBOL,
        'DAY_SECOND' => self::DAY_SECOND_SYMBOL,
        'DAYOFMONTH' => self::DAYOFMONTH_SYMBOL,
        'DEALLOCATE' => self::DEALLOCATE_SYMBOL,
        'DEC' => self::DEC_SYMBOL,
        'DECIMAL' => self::DECIMAL_SYMBOL,
        'DECIMAL_NUM' => self::DECIMAL_NUM_SYMBOL,
        'DECLARE' => self::DECLARE_SYMBOL,
        'DEFAULT' => self::DEFAULT_SYMBOL,
        'DEFAULT_AUTH' => self::DEFAULT_AUTH_SYMBOL,
        'DEFINER' => self::DEFINER_SYMBOL,
        'DELAY_KEY_WRITE' => self::DELAY_KEY_WRITE_SYMBOL,
        'DELAYED' => self::DELAYED_SYMBOL,
        'DELETE' => self::DELETE_SYMBOL,
        'DES_KEY_FILE' => self::DES_KEY_FILE_SYMBOL,
        'DESC' => self::DESC_SYMBOL,
        'DESCRIBE' => self::DESCRIBE_SYMBOL,
        'DETERMINISTIC' => self::DETERMINISTIC_SYMBOL,
        'DIAGNOSTICS' => self::DIAGNOSTICS_SYMBOL,
        'DIRECTORY' => self::DIRECTORY_SYMBOL,
        'DISABLE' => self::DISABLE_SYMBOL,
        'DISCARD' => self::DISCARD_SYMBOL,
        'DISK' => self::DISK_SYMBOL,
        'DISTINCT' => self::DISTINCT_SYMBOL,
        'DISTINCTROW' => self::DISTINCTROW_SYMBOL,
        'DIV' => self::DIV_SYMBOL,
        'DO' => self::DO_SYMBOL,
        'DOUBLE' => self::DOUBLE_SYMBOL,
        'DROP' => self::DROP_SYMBOL,
        'DUAL' => self::DUAL_SYMBOL,
        'DUMPFILE' => self::DUMPFILE_SYMBOL,
        'DUPLICATE' => self::DUPLICATE_SYMBOL,
        'DYNAMIC' => self::DYNAMIC_SYMBOL,
        'EACH' => self::EACH_SYMBOL,
        'ELSE' => self::ELSE_SYMBOL,
        'ELSEIF' => self::ELSEIF_SYMBOL,
        'ENABLE' => self::ENABLE_SYMBOL,
        'ENCLOSED' => self::ENCLOSED_SYMBOL,
        'ENCRYPTION' => self::ENCRYPTION_SYMBOL,
        'END' => self::END_SYMBOL,
        'END_OF_INPUT' => self::EOF,
        'ENDS' => self::ENDS_SYMBOL,
        'ENGINE' => self::ENGINE_SYMBOL,
        'ENGINES' => self::ENGINES_SYMBOL,
        'ENUM' => self::ENUM_SYMBOL,
        'ERROR' => self::ERROR_SYMBOL,
        'ERRORS' => self::ERRORS_SYMBOL,
        'ESCAPE' => self::ESCAPE_SYMBOL,
        'ESCAPED' => self::ESCAPED_SYMBOL,
        'EVENT' => self::EVENT_SYMBOL,
        'EVENTS' => self::EVENTS_SYMBOL,
        'EVERY' => self::EVERY_SYMBOL,
        'EXCHANGE' => self::EXCHANGE_SYMBOL,
        'EXECUTE' => self::EXECUTE_SYMBOL,
        'EXISTS' => self::EXISTS_SYMBOL,
        'EXIT' => self::EXIT_SYMBOL,
        'EXPANSION' => self::EXPANSION_SYMBOL,
        'EXPIRE' => self::EXPIRE_SYMBOL,
        'EXPLAIN' => self::EXPLAIN_SYMBOL,
        'EXPORT' => self::EXPORT_SYMBOL,
        'EXTENDED' => self::EXTENDED_SYMBOL,
        'EXTENT_SIZE' => self::EXTENT_SIZE_SYMBOL,
        'EXTRACT' => self::EXTRACT_SYMBOL,
        'FALSE' => self::FALSE_SYMBOL,
        'FAST' => self::FAST_SYMBOL,
        'FAULTS' => self::FAULTS_SYMBOL,
        'FETCH' => self::FETCH_SYMBOL,
        'FIELDS' => self::FIELDS_SYMBOL,
        'FILE' => self::FILE_SYMBOL,
        'FILE_BLOCK_SIZE' => self::FILE_BLOCK_SIZE_SYMBOL,
        'FILTER' => self::FILTER_SYMBOL,
        'FIRST' => self::FIRST_SYMBOL,
        'FIXED' => self::FIXED_SYMBOL,
        'FLOAT' => self::FLOAT_SYMBOL,
        'FLOAT4' => self::FLOAT4_SYMBOL,
        'FLOAT8' => self::FLOAT8_SYMBOL,
        'FLUSH' => self::FLUSH_SYMBOL,
        'FOLLOWS' => self::FOLLOWS_SYMBOL,
        'FOR' => self::FOR_SYMBOL,
        'FORCE' => self::FORCE_SYMBOL,
        'FOREIGN' => self::FOREIGN_SYMBOL,
        'FORMAT' => self::FORMAT_SYMBOL,
        'FOUND' => self::FOUND_SYMBOL,
        'FROM' => self::FROM_SYMBOL,
        'FULL' => self::FULL_SYMBOL,
        'FULLTEXT' => self::FULLTEXT_SYMBOL,
        'FUNCTION' => self::FUNCTION_SYMBOL,
        'GENERAL' => self::GENERAL_SYMBOL,
        'GENERATED' => self::GENERATED_SYMBOL,
        'GEOMETRY' => self::GEOMETRY_SYMBOL,
        'GEOMETRYCOLLECTION' => self::GEOMETRYCOLLECTION_SYMBOL,
        'GET' => self::GET_SYMBOL,
        'GET_FORMAT' => self::GET_FORMAT_SYMBOL,
        'GLOBAL' => self::GLOBAL_SYMBOL,
        'GRANT' => self::GRANT_SYMBOL,
        'GRANTS' => self::GRANTS_SYMBOL,
        'GROUP' => self::GROUP_SYMBOL,
        'GROUP_CONCAT' => self::GROUP_CONCAT_SYMBOL,
        'GROUP_REPLICATION' => self::GROUP_REPLICATION_SYMBOL,
        'HANDLER' => self::HANDLER_SYMBOL,
        'HASH' => self::HASH_SYMBOL,
        'HAVING' => self::HAVING_SYMBOL,
        'HELP' => self::HELP_SYMBOL,
        'HIGH_PRIORITY' => self::HIGH_PRIORITY_SYMBOL,
        'HOST' => self::HOST_SYMBOL,
        'HOSTS' => self::HOSTS_SYMBOL,
        'HOUR' => self::HOUR_SYMBOL,
        'HOUR_MICROSECOND' => self::HOUR_MICROSECOND_SYMBOL,
        'HOUR_MINUTE' => self::HOUR_MINUTE_SYMBOL,
        'HOUR_SECOND' => self::HOUR_SECOND_SYMBOL,
        'IDENTIFIED' => self::IDENTIFIED_SYMBOL,
        'IF' => self::IF_SYMBOL,
        'IGNORE' => self::IGNORE_SYMBOL,
        'IGNORE_SERVER_IDS' => self::IGNORE_SERVER_IDS_SYMBOL,
        'IMPORT' => self::IMPORT_SYMBOL,
        'IN' => self::IN_SYMBOL,
        'INDEX' => self::INDEX_SYMBOL,
        'INDEXES' => self::INDEXES_SYMBOL,
        'INFILE' => self::INFILE_SYMBOL,
        'INITIAL_SIZE' => self::INITIAL_SIZE_SYMBOL,
        'INNER' => self::INNER_SYMBOL,
        'INOUT' => self::INOUT_SYMBOL,
        'INSENSITIVE' => self::INSENSITIVE_SYMBOL,
        'INSERT' => self::INSERT_SYMBOL,
        'INSERT_METHOD' => self::INSERT_METHOD_SYMBOL,
        'INSTALL' => self::INSTALL_SYMBOL,
        'INSTANCE' => self::INSTANCE_SYMBOL,
        'INT' => self::INT_SYMBOL,
        'INT1' => self::INT1_SYMBOL,
        'INT2' => self::INT2_SYMBOL,
        'INT3' => self::INT3_SYMBOL,
        'INT4' => self::INT4_SYMBOL,
        'INT8' => self::INT8_SYMBOL,
        'INTEGER' => self::INTEGER_SYMBOL,
        'INTERVAL' => self::INTERVAL_SYMBOL,
        'INTO' => self::INTO_SYMBOL,
        'INVOKER' => self::INVOKER_SYMBOL,
        'IO' => self::IO_SYMBOL,
        'IO_AFTER_GTIDS' => self::IO_AFTER_GTIDS_SYMBOL,
        'IO_BEFORE_GTIDS' => self::IO_BEFORE_GTIDS_SYMBOL,
        'IO_THREAD' => self::IO_THREAD_SYMBOL,
        'IPC' => self::IPC_SYMBOL,
        'IS' => self::IS_SYMBOL,
        'ISOLATION' => self::ISOLATION_SYMBOL,
        'ISSUER' => self::ISSUER_SYMBOL,
        'ITERATE' => self::ITERATE_SYMBOL,
        'JOIN' => self::JOIN_SYMBOL,
        'JSON' => self::JSON_SYMBOL,
        'KEY' => self::KEY_SYMBOL,
        'KEY_BLOCK_SIZE' => self::KEY_BLOCK_SIZE_SYMBOL,
        'KEYS' => self::KEYS_SYMBOL,
        'KILL' => self::KILL_SYMBOL,
        'LANGUAGE' => self::LANGUAGE_SYMBOL,
        'LAST' => self::LAST_SYMBOL,
        'LEADING' => self::LEADING_SYMBOL,
        'LEAVE' => self::LEAVE_SYMBOL,
        'LEAVES' => self::LEAVES_SYMBOL,
        'LEFT' => self::LEFT_SYMBOL,
        'LESS' => self::LESS_SYMBOL,
        'LEVEL' => self::LEVEL_SYMBOL,
        'LIKE' => self::LIKE_SYMBOL,
        'LIMIT' => self::LIMIT_SYMBOL,
        'LINEAR' => self::LINEAR_SYMBOL,
        'LINES' => self::LINES_SYMBOL,
        'LINESTRING' => self::LINESTRING_SYMBOL,
        'LIST' => self::LIST_SYMBOL,
        'LOAD' => self::LOAD_SYMBOL,
        'LOCAL' => self::LOCAL_SYMBOL,
        'LOCALTIME' => self::LOCALTIME_SYMBOL,
        'LOCALTIMESTAMP' => self::LOCALTIMESTAMP_SYMBOL,
        'LOCATOR' => self::LOCATOR_SYMBOL,
        'LOCK' => self::LOCK_SYMBOL,
        'LOCKS' => self::LOCKS_SYMBOL,
        'LOGFILE' => self::LOGFILE_SYMBOL,
        'LOGS' => self::LOGS_SYMBOL,
        'LONG' => self::LONG_SYMBOL,
        'LONG_NUM' => self::LONG_NUM_SYMBOL,
        'LONGBLOB' => self::LONGBLOB_SYMBOL,
        'LONGTEXT' => self::LONGTEXT_SYMBOL,
        'LOOP' => self::LOOP_SYMBOL,
        'LOW_PRIORITY' => self::LOW_PRIORITY_SYMBOL,
        'MASTER' => self::MASTER_SYMBOL,
        'MASTER_AUTO_POSITION' => self::MASTER_AUTO_POSITION_SYMBOL,
        'MASTER_BIND' => self::MASTER_BIND_SYMBOL,
        'MASTER_CONNECT_RETRY' => self::MASTER_CONNECT_RETRY_SYMBOL,
        'MASTER_DELAY' => self::MASTER_DELAY_SYMBOL,
        'MASTER_HEARTBEAT_PERIOD' => self::MASTER_HEARTBEAT_PERIOD_SYMBOL,
        'MASTER_HOST' => self::MASTER_HOST_SYMBOL,
        'MASTER_LOG_FILE' => self::MASTER_LOG_FILE_SYMBOL,
        'MASTER_LOG_POS' => self::MASTER_LOG_POS_SYMBOL,
        'MASTER_PASSWORD' => self::MASTER_PASSWORD_SYMBOL,
        'MASTER_PORT' => self::MASTER_PORT_SYMBOL,
        'MASTER_RETRY_COUNT' => self::MASTER_RETRY_COUNT_SYMBOL,
        'MASTER_SERVER_ID' => self::MASTER_SERVER_ID_SYMBOL,
        'MASTER_SSL' => self::MASTER_SSL_SYMBOL,
        'MASTER_SSL_CA' => self::MASTER_SSL_CA_SYMBOL,
        'MASTER_SSL_CAPATH' => self::MASTER_SSL_CAPATH_SYMBOL,
        'MASTER_SSL_CERT' => self::MASTER_SSL_CERT_SYMBOL,
        'MASTER_SSL_CIPHER' => self::MASTER_SSL_CIPHER_SYMBOL,
        'MASTER_SSL_CRL' => self::MASTER_SSL_CRL_SYMBOL,
        'MASTER_SSL_CRLPATH' => self::MASTER_SSL_CRLPATH_SYMBOL,
        'MASTER_SSL_KEY' => self::MASTER_SSL_KEY_SYMBOL,
        'MASTER_SSL_VERIFY_SERVER_CERT' => self::MASTER_SSL_VERIFY_SERVER_CERT_SYMBOL,
        'MASTER_TLS_VERSION' => self::MASTER_TLS_VERSION_SYMBOL,
        'MASTER_USER' => self::MASTER_USER_SYMBOL,
        'MATCH' => self::MATCH_SYMBOL,
        'MAX' => self::MAX_SYMBOL,
        'MAX_CONNECTIONS_PER_HOUR' => self::MAX_CONNECTIONS_PER_HOUR_SYMBOL,
        'MAX_QUERIES_PER_HOUR' => self::MAX_QUERIES_PER_HOUR_SYMBOL,
        'MAX_ROWS' => self::MAX_ROWS_SYMBOL,
        'MAX_SIZE' => self::MAX_SIZE_SYMBOL,
        'MAX_STATEMENT_TIME' => self::MAX_STATEMENT_TIME_SYMBOL,
        'MAX_UPDATES_PER_HOUR' => self::MAX_UPDATES_PER_HOUR_SYMBOL,
        'MAX_USER_CONNECTIONS' => self::MAX_USER_CONNECTIONS_SYMBOL,
        'MAXVALUE' => self::MAXVALUE_SYMBOL,
        'MEDIUM' => self::MEDIUM_SYMBOL,
        'MEDIUMBLOB' => self::MEDIUMBLOB_SYMBOL,
        'MEDIUMINT' => self::MEDIUMINT_SYMBOL,
        'MEDIUMTEXT' => self::MEDIUMTEXT_SYMBOL,
        'MEMORY' => self::MEMORY_SYMBOL,
        'MERGE' => self::MERGE_SYMBOL,
        'MESSAGE_TEXT' => self::MESSAGE_TEXT_SYMBOL,
        'MICROSECOND' => self::MICROSECOND_SYMBOL,
        'MID' => self::MID_SYMBOL,
        'MIDDLEINT' => self::MIDDLEINT_SYMBOL,
        'MIGRATE' => self::MIGRATE_SYMBOL,
        'MIN' => self::MIN_SYMBOL,
        'MIN_ROWS' => self::MIN_ROWS_SYMBOL,
        'MINUTE' => self::MINUTE_SYMBOL,
        'MINUTE_MICROSECOND' => self::MINUTE_MICROSECOND_SYMBOL,
        'MINUTE_SECOND' => self::MINUTE_SECOND_SYMBOL,
        'MOD' => self::MOD_SYMBOL,
        'MODE' => self::MODE_SYMBOL,
        'MODIFIES' => self::MODIFIES_SYMBOL,
        'MODIFY' => self::MODIFY_SYMBOL,
        'MONTH' => self::MONTH_SYMBOL,
        'MULTILINESTRING' => self::MULTILINESTRING_SYMBOL,
        'MULTIPOINT' => self::MULTIPOINT_SYMBOL,
        'MULTIPOLYGON' => self::MULTIPOLYGON_SYMBOL,
        'MUTEX' => self::MUTEX_SYMBOL,
        'MYSQL_ERRNO' => self::MYSQL_ERRNO_SYMBOL,
        'NAME' => self::NAME_SYMBOL,
        'NAMES' => self::NAMES_SYMBOL,
        'NATIONAL' => self::NATIONAL_SYMBOL,
        'NATURAL' => self::NATURAL_SYMBOL,
        'NCHAR' => self::NCHAR_SYMBOL,
        'NCHAR_STRING' => self::NCHAR_STRING_SYMBOL,
        'NDB' => self::NDB_SYMBOL,
        'NDBCLUSTER' => self::NDBCLUSTER_SYMBOL,
        'NEG' => self::NEG_SYMBOL,
        'NEVER' => self::NEVER_SYMBOL,
        'NEW' => self::NEW_SYMBOL,
        'NEXT' => self::NEXT_SYMBOL,
        'NO' => self::NO_SYMBOL,
        'NO_WAIT' => self::NO_WAIT_SYMBOL,
        'NO_WRITE_TO_BINLOG' => self::NO_WRITE_TO_BINLOG_SYMBOL,
        'NODEGROUP' => self::NODEGROUP_SYMBOL,
        'NONBLOCKING' => self::NONBLOCKING_SYMBOL,
        'NONE' => self::NONE_SYMBOL,
        'NOT' => self::NOT_SYMBOL,
        'NOW' => self::NOW_SYMBOL,
        'NULL' => self::NULL_SYMBOL,
        'NUMBER' => self::NUMBER_SYMBOL,
        'NUMERIC' => self::NUMERIC_SYMBOL,
        'NVARCHAR' => self::NVARCHAR_SYMBOL,
        'OFFLINE' => self::OFFLINE_SYMBOL,
        'OFFSET' => self::OFFSET_SYMBOL,
        'OLD_PASSWORD' => self::OLD_PASSWORD_SYMBOL,
        'ON' => self::ON_SYMBOL,
        'ONE' => self::ONE_SYMBOL,
        'ONLINE' => self::ONLINE_SYMBOL,
        'ONLY' => self::ONLY_SYMBOL,
        'OPEN' => self::OPEN_SYMBOL,
        'OPTIMIZE' => self::OPTIMIZE_SYMBOL,
        'OPTIMIZER_COSTS' => self::OPTIMIZER_COSTS_SYMBOL,
        'OPTION' => self::OPTION_SYMBOL,
        'OPTIONALLY' => self::OPTIONALLY_SYMBOL,
        'OPTIONS' => self::OPTIONS_SYMBOL,
        'OR' => self::OR_SYMBOL,
        'ORDER' => self::ORDER_SYMBOL,
        'OUT' => self::OUT_SYMBOL,
        'OUTER' => self::OUTER_SYMBOL,
        'OUTFILE' => self::OUTFILE_SYMBOL,
        'OWNER' => self::OWNER_SYMBOL,
        'PACK_KEYS' => self::PACK_KEYS_SYMBOL,
        'PAGE' => self::PAGE_SYMBOL,
        'PARSER' => self::PARSER_SYMBOL,
        'PARTIAL' => self::PARTIAL_SYMBOL,
        'PARTITION' => self::PARTITION_SYMBOL,
        'PARTITIONING' => self::PARTITIONING_SYMBOL,
        'PARTITIONS' => self::PARTITIONS_SYMBOL,
        'PASSWORD' => self::PASSWORD_SYMBOL,
        'PHASE' => self::PHASE_SYMBOL,
        'PLUGIN' => self::PLUGIN_SYMBOL,
        'PLUGIN_DIR' => self::PLUGIN_DIR_SYMBOL,
        'PLUGINS' => self::PLUGINS_SYMBOL,
        'POINT' => self::POINT_SYMBOL,
        'POLYGON' => self::POLYGON_SYMBOL,
        'PORT' => self::PORT_SYMBOL,
        'POSITION' => self::POSITION_SYMBOL,
        'PRECEDES' => self::PRECEDES_SYMBOL,
        'PRECISION' => self::PRECISION_SYMBOL,
        'PREPARE' => self::PREPARE_SYMBOL,
        'PRESERVE' => self::PRESERVE_SYMBOL,
        'PREV' => self::PREV_SYMBOL,
        'PRIMARY' => self::PRIMARY_SYMBOL,
        'PRIVILEGES' => self::PRIVILEGES_SYMBOL,
        'PROCEDURE' => self::PROCEDURE_SYMBOL,
        'PROCESS' => self::PROCESS_SYMBOL,
        'PROCESSLIST' => self::PROCESSLIST_SYMBOL,
        'PROFILE' => self::PROFILE_SYMBOL,
        'PROFILES' => self::PROFILES_SYMBOL,
        'PROXY' => self::PROXY_SYMBOL,
        'PURGE' => self::PURGE_SYMBOL,
        'QUARTER' => self::QUARTER_SYMBOL,
        'QUERY' => self::QUERY_SYMBOL,
        'QUICK' => self::QUICK_SYMBOL,
        'RANGE' => self::RANGE_SYMBOL,
        'READ' => self::READ_SYMBOL,
        'READ_ONLY' => self::READ_ONLY_SYMBOL,
        'READ_WRITE' => self::READ_WRITE_SYMBOL,
        'READS' => self::READS_SYMBOL,
        'REAL' => self::REAL_SYMBOL,
        'REBUILD' => self::REBUILD_SYMBOL,
        'RECOVER' => self::RECOVER_SYMBOL,
        'REDO_BUFFER_SIZE' => self::REDO_BUFFER_SIZE_SYMBOL,
        'REDOFILE' => self::REDOFILE_SYMBOL,
        'REDUNDANT' => self::REDUNDANT_SYMBOL,
        'REFERENCES' => self::REFERENCES_SYMBOL,
        'REGEXP' => self::REGEXP_SYMBOL,
        'RELAY' => self::RELAY_SYMBOL,
        'RELAY_LOG_FILE' => self::RELAY_LOG_FILE_SYMBOL,
        'RELAY_LOG_POS' => self::RELAY_LOG_POS_SYMBOL,
        'RELAY_THREAD' => self::RELAY_THREAD_SYMBOL,
        'RELAYLOG' => self::RELAYLOG_SYMBOL,
        'RELEASE' => self::RELEASE_SYMBOL,
        'RELOAD' => self::RELOAD_SYMBOL,
        'REMOVE' => self::REMOVE_SYMBOL,
        'RENAME' => self::RENAME_SYMBOL,
        'REORGANIZE' => self::REORGANIZE_SYMBOL,
        'REPAIR' => self::REPAIR_SYMBOL,
        'REPEAT' => self::REPEAT_SYMBOL,
        'REPEATABLE' => self::REPEATABLE_SYMBOL,
        'REPLACE' => self::REPLACE_SYMBOL,
        'REPLICATE_DO_DB' => self::REPLICATE_DO_DB_SYMBOL,
        'REPLICATE_DO_TABLE' => self::REPLICATE_DO_TABLE_SYMBOL,
        'REPLICATE_IGNORE_DB' => self::REPLICATE_IGNORE_DB_SYMBOL,
        'REPLICATE_IGNORE_TABLE' => self::REPLICATE_IGNORE_TABLE_SYMBOL,
        'REPLICATE_REWRITE_DB' => self::REPLICATE_REWRITE_DB_SYMBOL,
        'REPLICATE_WILD_DO_TABLE' => self::REPLICATE_WILD_DO_TABLE_SYMBOL,
        'REPLICATE_WILD_IGNORE_TABLE' => self::REPLICATE_WILD_IGNORE_TABLE_SYMBOL,
        'REPLICATION' => self::REPLICATION_SYMBOL,
        'REQUIRE' => self::REQUIRE_SYMBOL,
        'RESET' => self::RESET_SYMBOL,
        'RESIGNAL' => self::RESIGNAL_SYMBOL,
        'RESTORE' => self::RESTORE_SYMBOL,
        'RESTRICT' => self::RESTRICT_SYMBOL,
        'RESUME' => self::RESUME_SYMBOL,
        'RETURN' => self::RETURN_SYMBOL,
        'RETURNED_SQLSTATE' => self::RETURNED_SQLSTATE_SYMBOL,
        'RETURNS' => self::RETURNS_SYMBOL,
        'REVERSE' => self::REVERSE_SYMBOL,
        'REVOKE' => self::REVOKE_SYMBOL,
        'RIGHT' => self::RIGHT_SYMBOL,
        'RLIKE' => self::RLIKE_SYMBOL,
        'ROLLBACK' => self::ROLLBACK_SYMBOL,
        'ROLLUP' => self::ROLLUP_SYMBOL,
        'ROTATE' => self::ROTATE_SYMBOL,
        'ROUTINE' => self::ROUTINE_SYMBOL,
        'ROW' => self::ROW_SYMBOL,
        'ROW_COUNT' => self::ROW_COUNT_SYMBOL,
        'ROW_FORMAT' => self::ROW_FORMAT_SYMBOL,
        'ROWS' => self::ROWS_SYMBOL,
        'RTREE' => self::RTREE_SYMBOL,
        'SAVEPOINT' => self::SAVEPOINT_SYMBOL,
        'SCHEDULE' => self::SCHEDULE_SYMBOL,
        'SCHEMA' => self::SCHEMA_SYMBOL,
        'SCHEMA_NAME' => self::SCHEMA_NAME_SYMBOL,
        'SCHEMAS' => self::SCHEMAS_SYMBOL,
        'SECOND' => self::SECOND_SYMBOL,
        'SECOND_MICROSECOND' => self::SECOND_MICROSECOND_SYMBOL,
        'SECURITY' => self::SECURITY_SYMBOL,
        'SELECT' => self::SELECT_SYMBOL,
        'SENSITIVE' => self::SENSITIVE_SYMBOL,
        'SEPARATOR' => self::SEPARATOR_SYMBOL,
        'SERIAL' => self::SERIAL_SYMBOL,
        'SERIALIZABLE' => self::SERIALIZABLE_SYMBOL,
        'SERVER' => self::SERVER_SYMBOL,
        'SERVER_OPTIONS' => self::SERVER_OPTIONS_SYMBOL,
        'SESSION' => self::SESSION_SYMBOL,
        'SESSION_USER' => self::SESSION_USER_SYMBOL,
        'SET' => self::SET_SYMBOL,
        'SET_VAR' => self::SET_VAR_SYMBOL,
        'SHARE' => self::SHARE_SYMBOL,
        'SHOW' => self::SHOW_SYMBOL,
        'SHUTDOWN' => self::SHUTDOWN_SYMBOL,
        'SIGNAL' => self::SIGNAL_SYMBOL,
        'SIGNED' => self::SIGNED_SYMBOL,
        'SIMPLE' => self::SIMPLE_SYMBOL,
        'SLAVE' => self::SLAVE_SYMBOL,
        'SLOW' => self::SLOW_SYMBOL,
        'SMALLINT' => self::SMALLINT_SYMBOL,
        'SNAPSHOT' => self::SNAPSHOT_SYMBOL,
        'SOCKET' => self::SOCKET_SYMBOL,
        'SOME' => self::SOME_SYMBOL,
        'SONAME' => self::SONAME_SYMBOL,
        'SOUNDS' => self::SOUNDS_SYMBOL,
        'SOURCE' => self::SOURCE_SYMBOL,
        'SPATIAL' => self::SPATIAL_SYMBOL,
        'SPECIFIC' => self::SPECIFIC_SYMBOL,
        'SQL' => self::SQL_SYMBOL,
        'SQL_AFTER_GTIDS' => self::SQL_AFTER_GTIDS_SYMBOL,
        'SQL_AFTER_MTS_GAPS' => self::SQL_AFTER_MTS_GAPS_SYMBOL,
        'SQL_BEFORE_GTIDS' => self::SQL_BEFORE_GTIDS_SYMBOL,
        'SQL_BIG_RESULT' => self::SQL_BIG_RESULT_SYMBOL,
        'SQL_BUFFER_RESULT' => self::SQL_BUFFER_RESULT_SYMBOL,
        'SQL_CACHE' => self::SQL_CACHE_SYMBOL,
        'SQL_CALC_FOUND_ROWS' => self::SQL_CALC_FOUND_ROWS_SYMBOL,
        'SQL_NO_CACHE' => self::SQL_NO_CACHE_SYMBOL,
        'SQL_SMALL_RESULT' => self::SQL_SMALL_RESULT_SYMBOL,
        'SQL_THREAD' => self::SQL_THREAD_SYMBOL,
        'SQL_TSI_DAY' => self::SQL_TSI_DAY_SYMBOL,
        'SQL_TSI_HOUR' => self::SQL_TSI_HOUR_SYMBOL,
        'SQL_TSI_MINUTE' => self::SQL_TSI_MINUTE_SYMBOL,
        'SQL_TSI_MONTH' => self::SQL_TSI_MONTH_SYMBOL,
        'SQL_TSI_QUARTER' => self::SQL_TSI_QUARTER_SYMBOL,
        'SQL_TSI_SECOND' => self::SQL_TSI_SECOND_SYMBOL,
        'SQL_TSI_WEEK' => self::SQL_TSI_WEEK_SYMBOL,
        'SQL_TSI_YEAR' => self::SQL_TSI_YEAR_SYMBOL,
        'SQLEXCEPTION' => self::SQLEXCEPTION_SYMBOL,
        'SQLSTATE' => self::SQLSTATE_SYMBOL,
        'SQLWARNING' => self::SQLWARNING_SYMBOL,
        'SSL' => self::SSL_SYMBOL,
        'STACKED' => self::STACKED_SYMBOL,
        'START' => self::START_SYMBOL,
        'STARTING' => self::STARTING_SYMBOL,
        'STARTS' => self::STARTS_SYMBOL,
        'STATS_AUTO_RECALC' => self::STATS_AUTO_RECALC_SYMBOL,
        'STATS_PERSISTENT' => self::STATS_PERSISTENT_SYMBOL,
        'STATS_SAMPLE_PAGES' => self::STATS_SAMPLE_PAGES_SYMBOL,
        'STATUS' => self::STATUS_SYMBOL,
        'STD' => self::STD_SYMBOL,
        'STDDEV' => self::STDDEV_SYMBOL,
        'STDDEV_POP' => self::STDDEV_POP_SYMBOL,
        'STDDEV_SAMP' => self::STDDEV_SAMP_SYMBOL,
        'STOP' => self::STOP_SYMBOL,
        'STORAGE' => self::STORAGE_SYMBOL,
        'STORED' => self::STORED_SYMBOL,
        'STRAIGHT_JOIN' => self::STRAIGHT_JOIN_SYMBOL,
        'STRING' => self::STRING_SYMBOL,
        'SUBCLASS_ORIGIN' => self::SUBCLASS_ORIGIN_SYMBOL,
        'SUBDATE' => self::SUBDATE_SYMBOL,
        'SUBJECT' => self::SUBJECT_SYMBOL,
        'SUBPARTITION' => self::SUBPARTITION_SYMBOL,
        'SUBPARTITIONS' => self::SUBPARTITIONS_SYMBOL,
        'SUBSTR' => self::SUBSTR_SYMBOL,
        'SUBSTRING' => self::SUBSTRING_SYMBOL,
        'SUM' => self::SUM_SYMBOL,
        'SUPER' => self::SUPER_SYMBOL,
        'SUSPEND' => self::SUSPEND_SYMBOL,
        'SWAPS' => self::SWAPS_SYMBOL,
        'SWITCHES' => self::SWITCHES_SYMBOL,
        'SYSDATE' => self::SYSDATE_SYMBOL,
        'SYSTEM_USER' => self::SYSTEM_USER_SYMBOL,
        'TABLE' => self::TABLE_SYMBOL,
        'TABLE_CHECKSUM' => self::TABLE_CHECKSUM_SYMBOL,
        'TABLE_NAME' => self::TABLE_NAME_SYMBOL,
        'TABLE_REF_PRIORITY' => self::TABLE_REF_PRIORITY_SYMBOL,
        'TABLES' => self::TABLES_SYMBOL,
        'TABLESPACE' => self::TABLESPACE_SYMBOL,
        'TEMPORARY' => self::TEMPORARY_SYMBOL,
        'TEMPTABLE' => self::TEMPTABLE_SYMBOL,
        'TERMINATED' => self::TERMINATED_SYMBOL,
        'TEXT' => self::TEXT_SYMBOL,
        'THAN' => self::THAN_SYMBOL,
        'THEN' => self::THEN_SYMBOL,
        'TIME' => self::TIME_SYMBOL,
        'TIMESTAMP' => self::TIMESTAMP_SYMBOL,
        'TIMESTAMP_ADD' => self::TIMESTAMP_ADD_SYMBOL,
        'TIMESTAMP_DIFF' => self::TIMESTAMP_DIFF_SYMBOL,
        'TINYBLOB' => self::TINYBLOB_SYMBOL,
        'TINYINT' => self::TINYINT_SYMBOL,
        'TINYTEXT' => self::TINYTEXT_SYMBOL,
        'TO' => self::TO_SYMBOL,
        'TRAILING' => self::TRAILING_SYMBOL,
        'TRANSACTION' => self::TRANSACTION_SYMBOL,
        'TRIGGER' => self::TRIGGER_SYMBOL,
        'TRIGGERS' => self::TRIGGERS_SYMBOL,
        'TRIM' => self::TRIM_SYMBOL,
        'TRUE' => self::TRUE_SYMBOL,
        'TRUNCATE' => self::TRUNCATE_SYMBOL,
        'TYPE' => self::TYPE_SYMBOL,
        'TYPES' => self::TYPES_SYMBOL,
        'UDF_RETURNS' => self::UDF_RETURNS_SYMBOL,
        'UNCOMMITTED' => self::UNCOMMITTED_SYMBOL,
        'UNDEFINED' => self::UNDEFINED_SYMBOL,
        'UNDO' => self::UNDO_SYMBOL,
        'UNDO_BUFFER_SIZE' => self::UNDO_BUFFER_SIZE_SYMBOL,
        'UNDOFILE' => self::UNDOFILE_SYMBOL,
        'UNICODE' => self::UNICODE_SYMBOL,
        'UNINSTALL' => self::UNINSTALL_SYMBOL,
        'UNION' => self::UNION_SYMBOL,
        'UNIQUE' => self::UNIQUE_SYMBOL,
        'UNKNOWN' => self::UNKNOWN_SYMBOL,
        'UNLOCK' => self::UNLOCK_SYMBOL,
        'UNSIGNED' => self::UNSIGNED_SYMBOL,
        'UNTIL' => self::UNTIL_SYMBOL,
        'UPDATE' => self::UPDATE_SYMBOL,
        'UPGRADE' => self::UPGRADE_SYMBOL,
        'USAGE' => self::USAGE_SYMBOL,
        'USE' => self::USE_SYMBOL,
        'USE_FRM' => self::USE_FRM_SYMBOL,
        'USER' => self::USER_SYMBOL,
        'USER_RESOURCES' => self::USER_RESOURCES_SYMBOL,
        'USING' => self::USING_SYMBOL,
        'UTC_DATE' => self::UTC_DATE_SYMBOL,
        'UTC_TIME' => self::UTC_TIME_SYMBOL,
        'UTC_TIMESTAMP' => self::UTC_TIMESTAMP_SYMBOL,
        'VALIDATION' => self::VALIDATION_SYMBOL,
        'VALUE' => self::VALUE_SYMBOL,
        'VALUES' => self::VALUES_SYMBOL,
        'VAR_POP' => self::VAR_POP_SYMBOL,
        'VAR_SAMP' => self::VAR_SAMP_SYMBOL,
        'VARBINARY' => self::VARBINARY_SYMBOL,
        'VARCHAR' => self::VARCHAR_SYMBOL,
        'VARCHARACTER' => self::VARCHARACTER_SYMBOL,
        'VARIABLES' => self::VARIABLES_SYMBOL,
        'VARIANCE' => self::VARIANCE_SYMBOL,
        'VARYING' => self::VARYING_SYMBOL,
        'VIEW' => self::VIEW_SYMBOL,
        'VIRTUAL' => self::VIRTUAL_SYMBOL,
        'WAIT' => self::WAIT_SYMBOL,
        'WARNINGS' => self::WARNINGS_SYMBOL,
        'WEEK' => self::WEEK_SYMBOL,
        'WEIGHT_STRING' => self::WEIGHT_STRING_SYMBOL,
        'WHEN' => self::WHEN_SYMBOL,
        'WHERE' => self::WHERE_SYMBOL,
        'WHILE' => self::WHILE_SYMBOL,
        'WITH' => self::WITH_SYMBOL,
        'WITHOUT' => self::WITHOUT_SYMBOL,
        'WORK' => self::WORK_SYMBOL,
        'WRAPPER' => self::WRAPPER_SYMBOL,
        'WRITE' => self::WRITE_SYMBOL,
        'X509' => self::X509_SYMBOL,
        'XA' => self::XA_SYMBOL,
        'XID' => self::XID_SYMBOL,
        'XML' => self::XML_SYMBOL,
        'XOR' => self::XOR_SYMBOL,
        'YEAR' => self::YEAR_SYMBOL,
        'YEAR_MONTH' => self::YEAR_MONTH_SYMBOL,
        'ZEROFILL' => self::ZEROFILL_SYMBOL,

		// Tokens from MySQL 8.0:
        'ACTIVE' => self::ACTIVE_SYMBOL,
        'ADMIN' => self::ADMIN_SYMBOL,
        'ARRAY' => self::ARRAY_SYMBOL,
        'BUCKETS' => self::BUCKETS_SYMBOL,
        'CLONE' => self::CLONE_SYMBOL,
        'COMPONENT' => self::COMPONENT_SYMBOL,
        'CUME_DIST' => self::CUME_DIST_SYMBOL,
        'DEFINITION' => self::DEFINITION_SYMBOL,
        'DENSE_RANK' => self::DENSE_RANK_SYMBOL,
        'DESCRIPTION' => self::DESCRIPTION_SYMBOL,
        'EMPTY' => self::EMPTY_SYMBOL,
        'ENFORCED' => self::ENFORCED_SYMBOL,
        'EXCEPT' => self::EXCEPT_SYMBOL,
        'EXCLUDE' => self::EXCLUDE_SYMBOL,
        'FAILED_LOGIN_ATTEMPTS' => self::FAILED_LOGIN_ATTEMPTS_SYMBOL,
        'FIRST_VALUE' => self::FIRST_VALUE_SYMBOL,
        'FOLLOWING' => self::FOLLOWING_SYMBOL,
        'GET_MASTER_PUBLIC_KEY_SYM' => self::GET_MASTER_PUBLIC_KEY_SYMBOL,
        'GROUPING' => self::GROUPING_SYMBOL,
        'GROUPS' => self::GROUPS_SYMBOL,
        'HISTOGRAM' => self::HISTOGRAM_SYMBOL,
        'HISTORY' => self::HISTORY_SYMBOL,
        'INACTIVE' => self::INACTIVE_SYMBOL,
        'INVISIBLE' => self::INVISIBLE_SYMBOL,
        'JSON_ARRAYAGG' => self::JSON_ARRAYAGG_SYMBOL,
        'JSON_OBJECTAGG' => self::JSON_OBJECTAGG_SYMBOL,
        'JSON_TABLE' => self::JSON_TABLE_SYMBOL,
        'LAG' => self::LAG_SYMBOL,
        'LAST_VALUE' => self::LAST_VALUE_SYMBOL,
        'LATERAL' => self::LATERAL_SYMBOL,
        'LEAD' => self::LEAD_SYMBOL,
        'LOCKED' => self::LOCKED_SYMBOL,
        'MASTER_COMPRESSION_ALGORITHM' => self::MASTER_COMPRESSION_ALGORITHM_SYMBOL,
        'MASTER_PUBLIC_KEY_PATH' => self::MASTER_PUBLIC_KEY_PATH_SYMBOL,
        'MASTER_TLS_CIPHERSUITES' => self::MASTER_TLS_CIPHERSUITES_SYMBOL,
        'MASTER_ZSTD_COMPRESSION_LEVEL' => self::MASTER_ZSTD_COMPRESSION_LEVEL_SYMBOL,
        'MEMBER' => self::MEMBER_SYMBOL,
        'NESTED' => self::NESTED_SYMBOL,
        'NETWORK_NAMESPACE' => self::NETWORK_NAMESPACE_SYMBOL,
        'NOWAIT' => self::NOWAIT_SYMBOL,
        'NTH_VALUE' => self::NTH_VALUE_SYMBOL,
        'NTILE' => self::NTILE_SYMBOL,
        'NULLS' => self::NULLS_SYMBOL,
        'OF' => self::OF_SYMBOL,
        'OFF' => self::OFF_SYMBOL,
        'OJ' => self::OJ_SYMBOL,
        'OLD' => self::OLD_SYMBOL,
        'OPTIONAL' => self::OPTIONAL_SYMBOL,
        'ORDINALITY' => self::ORDINALITY_SYMBOL,
        'ORGANIZATION' => self::ORGANIZATION_SYMBOL,
        'OTHERS' => self::OTHERS_SYMBOL,
        'OVER' => self::OVER_SYMBOL,
        'PASSWORD_LOCK_TIME' => self::PASSWORD_LOCK_TIME_SYMBOL,
        'PATH' => self::PATH_SYMBOL,
        'PERCENT_RANK' => self::PERCENT_RANK_SYMBOL,
        'PERSIST' => self::PERSIST_SYMBOL,
        'PERSIST_ONLY' => self::PERSIST_ONLY_SYMBOL,
        'PRECEDING' => self::PRECEDING_SYMBOL,
        'PRIVILEGE_CHECKS_USER' => self::PRIVILEGE_CHECKS_USER_SYMBOL,
        'RANDOM' => self::RANDOM_SYMBOL,
        'RANK' => self::RANK_SYMBOL,
        'RECURSIVE' => self::RECURSIVE_SYMBOL,
        'REFERENCE' => self::REFERENCE_SYMBOL,
        'REMOTE' => self::REMOTE_SYMBOL,
        'REQUIRE_ROW_FORMAT' => self::REQUIRE_ROW_FORMAT_SYMBOL,
        'REQUIRE_TABLE_PRIMARY_KEY_CHECK' => self::REQUIRE_TABLE_PRIMARY_KEY_CHECK_SYMBOL,
        'RESOURCE' => self::RESOURCE_SYMBOL,
        'RESPECT' => self::RESPECT_SYMBOL,
        'RESTART' => self::RESTART_SYMBOL,
        'RETAIN' => self::RETAIN_SYMBOL,
        'REUSE' => self::REUSE_SYMBOL,
        'ROLE' => self::ROLE_SYMBOL,
        'ROW_NUMBER' => self::ROW_NUMBER_SYMBOL,
        'SECONDARY' => self::SECONDARY_SYMBOL,
        'SECONDARY_ENGINE' => self::SECONDARY_ENGINE_SYMBOL,
        'SECONDARY_LOAD' => self::SECONDARY_LOAD_SYMBOL,
        'SECONDARY_UNLOAD' => self::SECONDARY_UNLOAD_SYMBOL,
        'SKIP' => self::SKIP_SYMBOL,
        'SRID' => self::SRID_SYMBOL,
        'STREAM' => self::STREAM_SYMBOL,
        'SYSTEM' => self::SYSTEM_SYMBOL,
        'THREAD_PRIORITY' => self::THREAD_PRIORITY_SYMBOL,
        'TIES' => self::TIES_SYMBOL,
        'UNBOUNDED' => self::UNBOUNDED_SYMBOL,
        'VCPU' => self::VCPU_SYMBOL,
        'VISIBLE' => self::VISIBLE_SYMBOL,
        'WINDOW' => self::WINDOW_SYMBOL,
	];

	const SYNONYMS = [
        self::CHARACTER_SYMBOL => self::CHAR_SYMBOL,
        self::CURRENT_DATE_SYMBOL => self::CURDATE_SYMBOL,
        self::CURRENT_TIME_SYMBOL => self::CURTIME_SYMBOL,
        self::CURRENT_TIMESTAMP_SYMBOL => self::NOW_SYMBOL,
        self::DAYOFMONTH_SYMBOL => self::DAY_SYMBOL,
        self::DEC_SYMBOL => self::DECIMAL_SYMBOL,
        self::DISTINCTROW_SYMBOL => self::DISTINCT_SYMBOL,
        self::FIELDS_SYMBOL => self::COLUMNS_SYMBOL,
        self::FLOAT4_SYMBOL => self::FLOAT_SYMBOL,
        self::FLOAT8_SYMBOL => self::DOUBLE_SYMBOL,
        self::INT1_SYMBOL => self::TINYINT_SYMBOL,
        self::INT2_SYMBOL => self::SMALLINT_SYMBOL,
        self::INT3_SYMBOL => self::MEDIUMINT_SYMBOL,
        self::INT4_SYMBOL => self::INT_SYMBOL,
        self::INT8_SYMBOL => self::BIGINT_SYMBOL,
        self::INTEGER_SYMBOL => self::INT_SYMBOL,
        self::IO_THREAD_SYMBOL => self::RELAY_THREAD_SYMBOL,
        self::LOCALTIME_SYMBOL => self::NOW_SYMBOL,
        self::LOCALTIMESTAMP_SYMBOL => self::NOW_SYMBOL,
        self::MID_SYMBOL => self::SUBSTRING_SYMBOL,
        self::MIDDLEINT_SYMBOL => self::MEDIUMINT_SYMBOL,
        self::NDB_SYMBOL => self::NDBCLUSTER_SYMBOL,
        self::RLIKE_SYMBOL => self::REGEXP_SYMBOL,
        self::SCHEMA_SYMBOL => self::DATABASE_SYMBOL,
        self::SCHEMAS_SYMBOL => self::DATABASES_SYMBOL,
        self::SESSION_USER_SYMBOL => self::USER_SYMBOL,
        self::SOME_SYMBOL => self::ANY_SYMBOL,
        self::SQL_TSI_DAY_SYMBOL => self::DAY_SYMBOL,
        self::SQL_TSI_HOUR_SYMBOL => self::HOUR_SYMBOL,
        self::SQL_TSI_MINUTE_SYMBOL => self::MINUTE_SYMBOL,
        self::SQL_TSI_MONTH_SYMBOL => self::MONTH_SYMBOL,
        self::SQL_TSI_QUARTER_SYMBOL => self::QUARTER_SYMBOL,
        self::SQL_TSI_SECOND_SYMBOL => self::SECOND_SYMBOL,
        self::SQL_TSI_WEEK_SYMBOL => self::WEEK_SYMBOL,
        self::SQL_TSI_YEAR_SYMBOL => self::YEAR_SYMBOL,
        self::STDDEV_POP_SYMBOL => self::STD_SYMBOL,
        self::STDDEV_SYMBOL => self::STD_SYMBOL,
        self::SUBSTR_SYMBOL => self::SUBSTRING_SYMBOL,
        self::SYSTEM_USER_SYMBOL => self::USER_SYMBOL,
        self::VAR_POP_SYMBOL => self::VARIANCE_SYMBOL,
        self::VARCHARACTER_SYMBOL => self::VARCHAR_SYMBOL,
	];

	const FUNCTIONS = [
        self::ADDDATE_SYMBOL => true,
        self::BIT_AND_SYMBOL => true,
        self::BIT_OR_SYMBOL => true,
        self::BIT_XOR_SYMBOL => true,
        self::CAST_SYMBOL => true,
        self::COUNT_SYMBOL => true,
        self::CURDATE_SYMBOL => true,
        self::CURRENT_DATE_SYMBOL => true,
        self::CURRENT_TIME_SYMBOL => true,
        self::CURTIME_SYMBOL => true,
        self::DATE_ADD_SYMBOL => true,
        self::DATE_SUB_SYMBOL => true,
        self::EXTRACT_SYMBOL => true,
        self::GROUP_CONCAT_SYMBOL => true,
        self::MAX_SYMBOL => true,
        self::MID_SYMBOL => true,
        self::MIN_SYMBOL => true,
        self::NOW_SYMBOL => true,
        self::POSITION_SYMBOL => true,
        self::SESSION_USER_SYMBOL => true,
        self::STD_SYMBOL => true,
        self::STDDEV_POP_SYMBOL => true,
        self::STDDEV_SAMP_SYMBOL => true,
        self::STDDEV_SYMBOL => true,
        self::SUBDATE_SYMBOL => true,
        self::SUBSTR_SYMBOL => true,
        self::SUBSTRING_SYMBOL => true,
        self::SUM_SYMBOL => true,
        self::SYSDATE_SYMBOL => true,
        self::SYSTEM_USER_SYMBOL => true,
        self::TRIM_SYMBOL => true,
        self::VAR_POP_SYMBOL => true,
        self::VAR_SAMP_SYMBOL => true,
        self::VARIANCE_SYMBOL => true,
	];


	/**
	 * Positive number: >= <version>
	 * Negative number: <  <version>
	 */
	const VERSIONS = [
		// MySQL 5
        self::ACCOUNT_SYMBOL => 50707,
        self::ALWAYS_SYMBOL => 50707,
        self::ANALYSE_SYMBOL => -80000,
        self::AUTHORS_SYMBOL => -50700,
        self::CHANNEL_SYMBOL => 50706,
        self::COMPRESSION_SYMBOL => 50707,
        self::CONTRIBUTORS_SYMBOL => -50700,
        self::CURRENT_SYMBOL => 50604,
        self::DEFAULT_AUTH_SYMBOL => 50604,
        self::DES_KEY_FILE_SYMBOL < 80000,
        self::ENCRYPTION_SYMBOL => 50711,
        self::EXPIRE_SYMBOL => 50606,
        self::EXPORT_SYMBOL => 50606,
        self::FILE_BLOCK_SIZE_SYMBOL => 50707,
        self::FILTER_SYMBOL => 50700,
        self::FOLLOWS_SYMBOL => 50700,
        self::GENERATED_SYMBOL => 50707,
        self::GET_SYMBOL => 50604,
        self::GROUP_REPLICATION_SYMBOL => 50707,
        self::INSTANCE_SYMBOL => 50713,
        self::JSON_SYMBOL => 50708,
        self::MASTER_AUTO_POSITION_SYMBOL => 50605,
        self::MASTER_BIND_SYMBOL => 50602,
        self::MASTER_RETRY_COUNT_SYMBOL => 50601,
        self::MASTER_SSL_CRL_SYMBOL => 50603,
        self::MASTER_SSL_CRLPATH_SYMBOL => 50603,
        self::MASTER_TLS_VERSION_SYMBOL => 50713,
        self::NEVER_SYMBOL => 50704,
        self::NUMBER_SYMBOL => 50606,
        self::OLD_PASSWORD_SYMBOL => -50706,
        self::ONLY_SYMBOL => 50605,
        self::OPTIMIZER_COSTS_SYMBOL => 50706,
        self::PLUGIN_DIR_SYMBOL => 50604,
        self::PRECEDES_SYMBOL => 50700,
        self::REDOFILE_SYMBOL => -80000,
        self::REPLICATE_DO_DB_SYMBOL => 50700,
        self::REPLICATE_DO_TABLE_SYMBOL => 50700,
        self::REPLICATE_IGNORE_DB_SYMBOL => 50700,
        self::REPLICATE_IGNORE_TABLE_SYMBOL => 50700,
        self::REPLICATE_REWRITE_DB_SYMBOL => 50700,
        self::REPLICATE_WILD_DO_TABLE_SYMBOL => 50700,
        self::REPLICATE_WILD_IGNORE_TABLE_SYMBOL => 50700,
        self::ROTATE_SYMBOL => 50713,
        self::SQL_AFTER_MTS_GAPS_SYMBOL => 50606,
        self::SQL_CACHE_SYMBOL => -80000,
        self::STACKED_SYMBOL => 50700,
        self::STORED_SYMBOL => 50707,
        self::TABLE_REF_PRIORITY_SYMBOL => -80000,
        self::VALIDATION_SYMBOL => 50706,
        self::VIRTUAL_SYMBOL => 50707,
        self::XID_SYMBOL => 50704,

		// MySQL 8
        self::ADMIN_SYMBOL => 80000,
        self::BUCKETS_SYMBOL => 80000,
        self::CLONE_SYMBOL => 80000,
        self::COMPONENT_SYMBOL => 80000,
        self::CUME_DIST_SYMBOL => 80000,
        self::DENSE_RANK_SYMBOL => 80000,
        self::GET_MASTER_PUBLIC_KEY_SYMBOL => 80000,
        self::GROUPS_SYMBOL => 80000,
        self::LOCKED_SYMBOL => 80000,
        self::MASTER_PUBLIC_KEY_PATH_SYMBOL => 80000,
        self::OJ_SYMBOL => 80017,
        self::OVER_SYMBOL => 80000,
        self::ACTIVE_SYMBOL => 80014,
        self::ARRAY_SYMBOL => 80017,
        self::DEFINITION_SYMBOL => 80011,
        self::DESCRIPTION_SYMBOL => 80011,
        self::EMPTY_SYMBOL => 80000,
        self::ENFORCED_SYMBOL => 80017,
        self::EXCEPT_SYMBOL => 80000,
        self::EXCLUDE_SYMBOL => 80000,
        self::FAILED_LOGIN_ATTEMPTS_SYMBOL => 80019,
        self::FIRST_VALUE_SYMBOL => 80000,
        self::FOLLOWING_SYMBOL => 80000,
        self::GROUPING_SYMBOL => 80000,
        self::HISTOGRAM_SYMBOL => 80000,
        self::HISTORY_SYMBOL => 80000,
        self::INACTIVE_SYMBOL => 80014,
        self::INVISIBLE_SYMBOL => 80000,
        self::JSON_ARRAYAGG_SYMBOL => 80000,
        self::JSON_OBJECTAGG_SYMBOL => 80000,
        self::JSON_TABLE_SYMBOL => 80000,
        self::LAG_SYMBOL => 80000,
        self::LAST_VALUE_SYMBOL => 80000,
        self::LATERAL_SYMBOL => 80014,
        self::LEAD_SYMBOL => 80000,
        self::MASTER_COMPRESSION_ALGORITHM_SYMBOL => 80018,
        self::MASTER_TLS_CIPHERSUITES_SYMBOL => 80018,
        self::MASTER_ZSTD_COMPRESSION_LEVEL_SYMBOL => 80018,
        self::MEMBER_SYMBOL => 80017,
        self::NESTED_SYMBOL => 80000,
        self::NETWORK_NAMESPACE_SYMBOL => 80017,
        self::NOWAIT_SYMBOL => 80000,
        self::NTH_VALUE_SYMBOL => 80000,
        self::NTILE_SYMBOL => 80000,
        self::NULLS_SYMBOL => 80000,
        self::OF_SYMBOL => 80000,
        self::OFF_SYMBOL => 80019,
        self::OLD_SYMBOL => 80014,
        self::OPTIONAL_SYMBOL => 80013,
        self::ORDINALITY_SYMBOL => 80000,
        self::ORGANIZATION_SYMBOL => 80011,
        self::OTHERS_SYMBOL => 80000,
        self::PASSWORD_LOCK_TIME_SYMBOL => 80019,
        self::PATH_SYMBOL => 80000,
        self::PERCENT_RANK_SYMBOL => 80000,
        self::PERSIST_ONLY_SYMBOL => 80000,
        self::PERSIST_SYMBOL => 80000,
        self::PRECEDING_SYMBOL => 80000,
        self::PRIVILEGE_CHECKS_USER_SYMBOL => 80018,
        self::RANDOM_SYMBOL => 80018,
        self::RANK_SYMBOL => 80000,
        self::RECURSIVE_SYMBOL => 80000,
        self::REFERENCE_SYMBOL => 80011,
        self::REQUIRE_ROW_FORMAT_SYMBOL => 80019,
        self::REQUIRE_TABLE_PRIMARY_KEY_CHECK_SYMBOL => 80019,
        self::RESOURCE_SYMBOL => 80000,
        self::RESPECT_SYMBOL => 80000,
        self::RESTART_SYMBOL => 80011,
        self::RETAIN_SYMBOL => 80014,
        self::REUSE_SYMBOL => 80000,
        self::ROLE_SYMBOL => 80000,
        self::ROW_NUMBER_SYMBOL => 80000,
        self::SECONDARY_ENGINE_SYMBOL => 80013,
        self::SECONDARY_LOAD_SYMBOL => 80013,
        self::SECONDARY_SYMBOL => 80013,
        self::SECONDARY_UNLOAD_SYMBOL => 80013,
        self::SKIP_SYMBOL => 80000,
        self::SRID_SYMBOL => 80000,
        self::STREAM_SYMBOL => 80019,
        self::SYSTEM_SYMBOL => 80000,
        self::THREAD_PRIORITY_SYMBOL => 80000,
        self::TIES_SYMBOL => 80000,
        self::UNBOUNDED_SYMBOL => 80000,
        self::VCPU_SYMBOL => 80000,
        self::VISIBLE_SYMBOL => 80000,
        self::WINDOW_SYMBOL => 80000,
	];

    protected function IDENTIFIER_OR_KEYWORD()
    {
		$text = strtoupper($this->getText());

		// Lookup the string in the token table.
		$this->type = self::TOKENS[$text] ?? self::IDENTIFIER;
		if ($this->type === self::IDENTIFIER) {
			return;
		}

		// Apply MySQL server version specifics (positive number: >= <version>, negative number: < <version>).
		if (isset(self::VERSIONS[$this->type])) {
			$version = self::VERSIONS[$this->type];
			if ($this->serverVersion < $version || -$version >= $this->serverVersion) {
				$this->type = self::IDENTIFIER;
				return;
			}
		}

		// Apply MySQL version ranges manually.
		if (
			$this->type === self::MAX_STATEMENT_TIME_SYMBOL
			&& ($this->serverVersion <= 50704 || $this->serverVersion >= 50708)
		) {
			$this->type = self::IDENTIFIER;
			return;
		} elseif (
			$this->type === self::NONBLOCKING_SYMBOL
			&& ($this->serverVersion <= 50700 || $this->serverVersion >= 50706)
		) {
			$this->type = self::IDENTIFIER;
			return;
		} elseif (
			$this->type === self::REMOTE_SYMBOL
			&& ($this->serverVersion < 80003 || $this->serverVersion >= 80014)
		) {
			$this->type = self::IDENTIFIER;
			return;
		}

		// Determine function calls.
		if (isset(self::FUNCTIONS[$this->type])) {
			// Skip any whitespace character if the SQL mode says they should be ignored.
			$i = 1;
			if ($this->isSqlModeActive(self::SQL_MODE_IGNORE_SPACE)) {
				while ($this->isWhitespace($this->LA($i))) {
					$i++;
				}
			}
			if ($this->LA($i) !== '(') {
				$this->type = self::IDENTIFIER;
				return;
			}
		}

		// With "SQL_MODE_HIGH_NOT_PRECEDENCE" enabled, "NOT" needs to be emitted as a higher priority NOT2 symbol.
		if ($this->type === self::NOT_SYMBOL && $this->isSqlModeActive(MySQLLexer::SQL_MODE_HIGH_NOT_PRECEDENCE)) {
			$this->type = self::NOT2_SYMBOL;
		}

		// Apply synonyms.
		$this->type = self::SYNONYMS[$this->type] ?? $this->type;
    }

    protected function blockComment()
    {
        $this->consume(); // Consume the '/'.
        $this->consume(); // Consume the '*'.

        // If the next character is '!', it could be a version comment.
        if ($this->c === '!') {
            $this->consume(); // Consume the '!'.

            // If the next character is a digit, it's a version comment.
            if ($this->isDigit($this->c)) {
                // Consume all digits.
                while ($this->isDigit($this->c)) {
                    $this->consume();
                }

                // Check if the version comment is active for the current server version.
                if ($this->checkVersion($this->getText())) {
                    // If it's active, treat the content as regular SQL code.
                    $this->MYSQL_COMMENT_START();
                    $this->sqlCodeInComment();
                } else {
                    // If it's not active, skip the comment content.
                    $this->skipCommentContent();
                }
            } else {
                // If it's not a version comment, treat it as a regular multi-line comment.
                $this->MYSQL_COMMENT_START();
                $this->skipCommentContent();
            }
        } else {
            // If the next character is not '!', it's a regular multi-line comment.
            $this->skipCommentContent();
        }

        // Set the channel to HIDDEN for block comments.
        $this->channel = self::HIDDEN;
    }

    protected function skipCommentContent(): void
    {
        while ($this->c !== null) {
            if ($this->c === '*' && $this->n === '/') {
                $this->consume(); // Consume the '*'.
                $this->consume(); // Consume the '/'.
                break;
            }
            $this->consume();
        }
    }

    protected function sqlCodeInComment(): void
    {
        $this->skipCommentContent();
        $this->VERSION_COMMENT_END();
    }

    protected function DOT_IDENTIFIER()
    {
        $this->consume(); // Consume the '.'.
        $this->IDENTIFIER();
        $this->setType(self::DOT_SYMBOL);//@TODO: DOT_IDENTIFIER);
    }

    protected function NUMBER()
    {
        if (($this->c === '0' && $this->n === 'x') || (strtolower($this->c) === 'x' && $this->n === "'")) {
            $this->HEX_NUMBER();
        } elseif (($this->c === '0' && $this->n === 'b') || (strtolower($this->c) === 'b' && $this->n === "'")) {
            $this->BIN_NUMBER();
        } elseif ($this->c === '.' && $this->isDigit($this->LA(2))) {
            $this->DECIMAL_NUMBER();
        } else {
            $this->INT_NUMBER();

            if ($this->c === '.') {
                $this->consume();

                if ($this->isDigit($this->c)) {
                    while ($this->isDigit($this->c)) {
                        $this->consume();
                    }

                    if ($this->c === 'e' || $this->c === 'E') {
                        $this->consume();
                        if ($this->c === '+' || $this->c === '-') {
                            $this->consume();
                        }
                        while ($this->isDigit($this->c)) {
                            $this->consume();
                        }
                        $this->setType(self::FLOAT_NUMBER);
                    } else {
                        $this->setType(self::DECIMAL_NUMBER);
                    }
                } else {
                    // If there is no digit after the '.', it's a DOT_IDENTIFIER.
                    $this->emitDot();
                    $this->setType(self::IDENTIFIER);
                }
            } elseif ($this->c === 'e' || $this->c === 'E') {
                $this->consume();
                if ($this->c === '+' || $this->c === '-') {
                    $this->consume();
                }
                while ($this->isDigit($this->c)) {
                    $this->consume();
                }
                $this->setType(self::FLOAT_NUMBER);
            }
        }
    }

    protected function SINGLE_QUOTED_TEXT()
	{
		$pattern = $this->isSqlModeActive(self::SQL_MODE_NO_BACKSLASH_ESCAPES)
			? self::PATTERN_SINGLE_QUOTED_TEXT_NO_BACKSLASH_ESCAPES
			: self::PATTERN_SINGLE_QUOTED_TEXT;

	    if (preg_match('/\G' . $pattern . '/u', $this->input, $matches, 0, $this->position)) {
			$this->text = $matches[0];
			$this->position += strlen($this->text);
			$this->c = $this->input[$this->position] ?? null;
			$this->n = $this->input[$this->position + 1] ?? null;
			$this->type = self::SINGLE_QUOTED_TEXT;
		} else {
			$this->INVALID_INPUT();
		}
	}

    protected function DOUBLE_QUOTED_TEXT()
    {
		$pattern = $this->isSqlModeActive(self::SQL_MODE_NO_BACKSLASH_ESCAPES)
			? self::PATTERN_DOUBLE_QUOTED_TEXT_NO_BACKSLASH_ESCAPES
			: self::PATTERN_DOUBLE_QUOTED_TEXT;

		if (preg_match('/\G' . $pattern . '/u', $this->input, $matches, 0, $this->position)) {
			$this->text = $matches[0];
			$this->position += strlen($this->text);
			$this->c = $this->input[$this->position] ?? null;
			$this->n = $this->input[$this->position + 1] ?? null;
			$this->type = self::DOUBLE_QUOTED_TEXT;
		} else {
			$this->INVALID_INPUT();
		}
	}

    protected function BACK_TICK_QUOTED_ID()
    {
		$pattern = $this->isSqlModeActive(self::SQL_MODE_NO_BACKSLASH_ESCAPES)
			? self::PATTERN_BACKTICK_QUOTED_ID_NO_BACKSLASH_ESCAPES
			: self::PATTERN_BACKTICK_QUOTED_ID;

		if (preg_match('/\G' . $pattern . '/u', $this->input, $matches, 0, $this->position)) {
			$this->text = $matches[0];
			$this->position += strlen($this->text);
			$this->c = $this->input[$this->position] ?? null;
			$this->n = $this->input[$this->position + 1] ?? null;
			$this->type = self::BACK_TICK_QUOTED_ID;
		} else {
			$this->INVALID_INPUT();
		}
	}

	protected function HEX_NUMBER()
	{
		$isQuoted = strtolower($this->c) === 'x' && $this->n === "'";

		// Consume "0x" or "x'".
		$this->consume();
		$this->consume();

		while (
			($this->c >= '0' && $this->c <= '9')
			|| ($this->c >= 'a' && $this->c <= 'f')
			|| ($this->c >= 'A' && $this->c <= 'F')
		) {
			$this->consume();
		}

		if ($isQuoted) {
			$this->consume(); // Consume the "'".
		}

		$this->setType(self::HEX_NUMBER);
	}

    protected function BIN_NUMBER()
    {
		$isQuoted = strtolower($this->c) === 'b' && $this->n === "'";

		// Consume "0b" or "b'".
        $this->consume();
        $this->consume();

        while ($this->c === '0' || $this->c === '1') {
            $this->consume();
        }

		if ($isQuoted) {
			$this->consume(); // Consume the "'".
		}

        $this->setType(self::BIN_NUMBER);
    }

    protected function INT_NUMBER()
    {
        while ($this->isDigit($this->c)) {
            $this->consume();
        }
        $this->setType(self::DECIMAL_NUMBER);
    }

    protected function DECIMAL_NUMBER()
    {
        $this->consume(); // Consume the '.'.
        while ($this->isDigit($this->c)) {
            $this->consume();
        }
        $this->setType(self::DECIMAL_NUMBER);
    }

    protected function FLOAT_NUMBER()
    {
        // This rule is never actually called, as FLOAT_NUMBER tokens are emitted by NUMBER().
        throw new \BadMethodCallException('FLOAT_NUMBER() should never be called directly.');
    }

    protected function EQUAL_OPERATOR()
    {
        $this->consume();
        $this->setType(self::EQUAL_OPERATOR);
    }

    protected function ASSIGN_OPERATOR()
    {
        $this->consume(); // Consume the ':'.
        $this->consume(); // Consume the '='.
        $this->setType(self::ASSIGN_OPERATOR);
    }

    protected function NULL_SAFE_EQUAL_OPERATOR()
    {
        $this->consume(); // Consume the '<'.
        $this->consume(); // Consume the '='.
        $this->consume(); // Consume the '>'.
        $this->setType(self::NULL_SAFE_EQUAL_OPERATOR);
    }

    protected function GREATER_OR_EQUAL_OPERATOR()
    {
        $this->consume(); // Consume the '>'.
        $this->consume(); // Consume the '='.
        $this->setType(self::GREATER_OR_EQUAL_OPERATOR);
    }

    protected function GREATER_THAN_OPERATOR()
    {
        $this->consume();
        $this->setType(self::GREATER_THAN_OPERATOR);
    }

    protected function LESS_OR_EQUAL_OPERATOR()
    {
        $this->consume(); // Consume the '<'.
        $this->consume(); // Consume the '='.
        $this->setType(self::LESS_OR_EQUAL_OPERATOR);
    }

    protected function LESS_THAN_OPERATOR()
    {
        $this->consume();
        $this->setType(self::LESS_THAN_OPERATOR);
    }

    protected function NOT_EQUAL_OPERATOR()
    {
        $this->consume(); // Consume the '!'.
        $this->consume(); // Consume the '='.
        $this->setType(self::NOT_EQUAL_OPERATOR);
    }

    protected function NOT_EQUAL2_OPERATOR()
    {
        $this->consume(); // Consume the '<'.
        $this->consume(); // Consume the '>'.
        $this->setType(self::NOT_EQUAL_OPERATOR);
    }

    protected function PLUS_OPERATOR()
    {
        $this->consume();
        $this->setType(self::PLUS_OPERATOR);
    }

    protected function MINUS_OPERATOR()
    {
        $this->consume();
        $this->setType(self::MINUS_OPERATOR);
    }

    protected function MULT_OPERATOR()
    {
        $this->consume();
        $this->setType(self::MULT_OPERATOR);
    }

    protected function DIV_OPERATOR()
    {
        $this->consume();
        $this->setType(self::DIV_OPERATOR);
    }

    protected function MOD_OPERATOR()
    {
        $this->consume();
        $this->setType(self::MOD_OPERATOR);
    }

    protected function LOGICAL_NOT_OPERATOR()
    {
        $this->consume();
        $this->setType(self::LOGICAL_NOT_OPERATOR);
    }

    protected function BITWISE_NOT_OPERATOR()
    {
        $this->consume();
        $this->setType(self::BITWISE_NOT_OPERATOR);
    }

    protected function SHIFT_LEFT_OPERATOR()
    {
        $this->consume(); // Consume the '<'.
        $this->consume(); // Consume the '<'.
        $this->setType(self::SHIFT_LEFT_OPERATOR);
    }

    protected function SHIFT_RIGHT_OPERATOR()
    {
        $this->consume(); // Consume the '>'.
        $this->consume(); // Consume the '>'.
        $this->setType(self::SHIFT_RIGHT_OPERATOR);
    }

    protected function LOGICAL_AND_OPERATOR()
    {
        $this->consume(); // Consume the '&'.
        $this->consume(); // Consume the '&'.
        $this->setType(self::LOGICAL_AND_OPERATOR);
    }

    protected function BITWISE_AND_OPERATOR()
    {
        $this->consume();
        $this->setType(self::BITWISE_AND_OPERATOR);
    }

    protected function BITWISE_XOR_OPERATOR()
    {
        $this->consume();
        $this->setType(self::BITWISE_XOR_OPERATOR);
    }

    protected function LOGICAL_OR_OPERATOR()
    {
        $this->consume(); // Consume the '|'.
        $this->consume(); // Consume the '|'.

        if ($this->isSqlModeActive(self::SQL_MODE_PIPES_AS_CONCAT)) {
            $this->setType(self::CONCAT_PIPES_SYMBOL);
        } else {
            $this->setType(self::LOGICAL_OR_OPERATOR);
        }
    }

    protected function BITWISE_OR_OPERATOR()
    {
        $this->consume();
        $this->setType(self::BITWISE_OR_OPERATOR);
    }

    protected function DOT_SYMBOL()
    {
        $this->consume();
        $this->setType(self::DOT_SYMBOL);
    }

    protected function COMMA_SYMBOL()
    {
        $this->consume();
        $this->setType(self::COMMA_SYMBOL);
    }

    protected function SEMICOLON_SYMBOL()
    {
        $this->consume();
        $this->setType(self::SEMICOLON_SYMBOL);
    }

    protected function COLON_SYMBOL()
    {
        $this->consume();
        $this->setType(self::COLON_SYMBOL);
    }

    protected function OPEN_PAR_SYMBOL()
    {
        $this->consume();
        $this->setType(self::OPEN_PAR_SYMBOL);
    }

    protected function CLOSE_PAR_SYMBOL()
    {
        $this->consume();
        $this->setType(self::CLOSE_PAR_SYMBOL);
    }

    protected function OPEN_CURLY_SYMBOL()
    {
        $this       ->consume();
        $this->setType(self::OPEN_CURLY_SYMBOL);
    }

    protected function CLOSE_CURLY_SYMBOL()
    {
        $this->consume();
        $this->setType(self::CLOSE_CURLY_SYMBOL);
    }

    protected function JSON_SEPARATOR_SYMBOL()
    {
        if ($this->serverVersion >= 50708) {
            $this->consume(); // Consume the '-'.
            $this->consume(); // Consume the '>'.
            $this->setType(self::JSON_SEPARATOR_SYMBOL);
        } else {
            $this->setType(self::INVALID_INPUT);
        }
    }

    protected function JSON_UNQUOTED_SEPARATOR_SYMBOL()
    {
        if ($this->serverVersion >= 50713) {
            $this->consume(); // Consume the '-'.
            $this->consume(); // Consume the '>'.
            $this->consume(); // Consume the '>'.
            $this->setType(self::JSON_UNQUOTED_SEPARATOR_SYMBOL);
        } else {
            $this->setType(self::INVALID_INPUT);
        }
    }

    protected function AT_SIGN_SYMBOL()
    {
        $this->consume();
        $this->setType(self::AT_SIGN_SYMBOL);
    }

    protected function AT_AT_SIGN_SYMBOL()
    {
        $this->consume(); // Consume the '@'.
        $this->consume(); // Consume the '@'.
        $this->setType(self::AT_AT_SIGN_SYMBOL);
    }

    protected function NULL2_SYMBOL()
    {
        $this->consume(); // Consume the '\'.
        $this->consume(); // Consume the 'N'.
        $this->setType(self::NULL2_SYMBOL);
    }

    protected function PARAM_MARKER()
    {
        $this->consume();
        $this->setType(self::PARAM_MARKER);
    }

    protected function WHITESPACE()
    {
        while ($this->isWhitespace($this->c)) {
            $this->consume();
        }

        $this->channel = self::HIDDEN;
    }

    protected function INVALID_INPUT()
    {
        $this->consume();
        $this->setType(self::INVALID_INPUT);
    }

    protected function POUND_COMMENT()
    {
        $this->consume();

        while ($this->c !== null) {
            if ($this->c === "\n" || $this->c === "\r") {
                break;
            }
            $this->consume();
        }

        $this->channel = self::HIDDEN;
    }

    protected function DASHDASH_COMMENT()
    {
        $this->consume(); // Consume the '-'.
        $this->consume(); // Consume the '-'.

        while ($this->isWhitespace($this->c)) {
            $this->consume();
        }

        while ($this->c !== null) {
            if ($this->c === "\n" || $this->c === "\r") {
                break;
            }
            $this->consume();
        }

        $this->channel = self::HIDDEN;
    }

    protected function IDENTIFIER()
    {
        $this->setType(self::IDENTIFIER);
    }

    protected function MYSQL_COMMENT_START()
    {
        // TODO: use a lexer mode instead of a member variable.
        // Currently not used by the PHP target.
        return;
    }

    protected function VERSION_COMMENT_END()
    {
        // Currently not used by the PHP target.
        return;
    }

    protected function VERSION_COMMENT_START()
    {
        $this->consume(); // Consume the '/*'.
        $this->consume(); // Consume the '!'.
        while ($this->isDigit($this->c)) {
            $this->consume();
        }
        if ($this->checkVersion($this->getText())) {
            // If the version check passes, consume the rest of the comment
            while ($this->c !== null) {
                if ($this->c === '*' && $this->n === '/') {
                    $this->consume(); // Consume the '*'.
                    $this->consume(); // Consume the '/'.
                    break;
                }
                $this->consume();
            }
        } else {
            // If the version check fails, skip to the end of the comment.
            $this->skipCommentContent();
        }

        $this->channel = self::HIDDEN;
    }

    protected function BLOCK_COMMENT()
    {
        $this->consume(); // Consume the '/'.
        $this->consume(); // Consume the '*'.
        $this->skipCommentContent();
        $this->channel = self::HIDDEN;
    }

    // Helper functions -----------------------------------------------------------------------------------------------------

	private function isWhitespace($char) {
		return isset(self::WHITESPACES[$char]);
	}

	private function isDigit($char) {
		return $char >= '0' && $char <= '9';
	}

    private function determineNumericType($text): int
    {
        if (preg_match('/^0[xX][0-9a-fA-F]+$/', $text)) {
            return self::HEX_NUMBER;
        } elseif (preg_match('/^0[bB][01]+$/', $text)) {
            return self::BIN_NUMBER;
        } elseif (preg_match('/^\d+$/', $text)) {
            if (PHP_INT_MAX >= (int)$text) {
                return self::INT_NUMBER;
            }
            return self::LONG_NUMBER;
        }
        return self::INVALID_INPUT;
    }
}

class MySQLToken
{
    public $type;
    public $name;
    public $text;
    private $channel;

    public function __construct($type, $name, $text, $channel=null)
    {
        $this->type = $type;
        $this->name = $name;
        $this->text = $text;
        $this->channel = $channel;
    }

    public function getType()
    {
        return $this->type;
    }

    public function getText()
    {
        return $this->text;
    }

    public function getChannel()
    {
        return $this->channel;        
    }

    public function __toString()
    {
        $token_name = MySQLLexer::getTokenName($this->type);
        return $this->text . '<' . $this->type . ','.$token_name.'>';
    }

    public function extractValue()
    {
        if($this->type === MySQLLexer::BACK_TICK_QUOTED_ID) {
            return substr($this->text, 1, -1);
        } else if($this->type === MySQLLexer::DOUBLE_QUOTED_TEXT) {
            return substr($this->text, 1, -1);
        } else if($this->type === MySQLLexer::SINGLE_QUOTED_TEXT) {
            return substr($this->text, 1, -1);
        } else {
            return $this->text;
        }  
    }

}
