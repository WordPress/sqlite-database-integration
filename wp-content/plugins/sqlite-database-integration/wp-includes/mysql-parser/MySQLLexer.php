<?php

function safe_ctype_digit($string) {
    if (null === $string || strlen($string) === 0) {
        return false;
    }
    return ctype_digit($string);
}

function safe_ctype_space($string) {
    if (null === $string || strlen($string) === 0) {
        return false;
    }
    return ctype_space($string);
}

function safe_ctype_alpha($string) {
    if (null === $string || strlen($string) === 0) {
        return false;
    }
    return ctype_alpha($string);
}

function safe_ctype_alnum($string) {
    if (null === $string || strlen($string) === 0) {
        return false;
    }
    return ctype_alnum($string);
}

class MySQLLexer {
    
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
    public const UNDERLINE_SYMBOL = 31;
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
    public const IO_THREAD_SYMBOL = 846;
    public const REPLICA_SYMBOL = 847;
    public const CONSTRAINTS_SYMBOL = 848;

    public const EOF = -1;

    protected $input;
    protected $c; // Current character.
    protected $n; // Next character.
    protected $position = 0;
    protected $token;
    protected $text = '';
    protected $channel = self::DEFAULT_TOKEN_CHANNEL;
    protected $type;
    protected $tokenInstance;
    protected $serverVersion;
    protected $sqlModes;

    protected const DEFAULT_TOKEN_CHANNEL = 0;
    protected const HIDDEN = 99;

    public function __construct(string $input, int $serverVersion = 80000, int $sqlModes = 0)
    {
        $this->input = $input;
        $this->serverVersion = $serverVersion;
        $this->sqlModes = $sqlModes;
    }

    const PipesAsConcat = 1;
    const HighNotPrecedence = 2;
    const NoBackslashEscapes = 4;
    public const ANSI_QUOTES = 8;

    public function isSqlModeActive(int $mode): bool
    {
        return ($this->sqlModes & $mode) !== 0;
    }

    public function getServerVersion()
    {
        return $this->serverVersion;        
    }

    public static function getTokenName(int $tokenType): string
    {
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
            } elseif (safe_ctype_digit($la)) {
                $this->NUMBER();
            } elseif ($la === '.') {
                if (safe_ctype_digit($this->LA(2))) {
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
            } elseif ($la === '_') {
                $this->UNDERLINE_SYMBOL();
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
            } elseif (safe_ctype_space($la)) {
                $this->WHITESPACE();
            } elseif ($la === '0' && ($this->LA(2) === 'x' || $this->LA(2) === 'b')) {
                $this->NUMBER();
            } elseif (safe_ctype_alpha($la)) {
                $this->IDENTIFIER_OR_KEYWORD();
            } elseif ($la === null) {
                $this->matchEOF();
                $this->tokenInstance = new MySQLToken(self::EOF, '<EOF>');
                return false;
            } else {
                $this->INVALID_INPUT();
            }

            if(null !== $this->type) {
                break;
            }
        }

        $this->tokenInstance = new MySQLToken($this->type, $this->text, $this->channel);
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
     * @return int
     */
    protected function checkCharset(string $text): int
    {
        return 0;
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
        self::UNDERLINE_SYMBOL => 'UNDERLINE_SYMBOL',
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
    ];

    protected function IDENTIFIER_OR_KEYWORD()
    {
        // Match the longest possible keyword.
        while (safe_ctype_alnum($this->LA(1)) || $this->LA(1) === '_' || $this->LA(1) === '$') {
            $this->consume();
        }
        $text = $this->getText();

        // Check for keywords that are also identifiers.
        switch (strtoupper($text)) {
            case 'ACCESSIBLE':
                $this->ACCESSIBLE_SYMBOL();
                break;
            case 'ACCOUNT':
                if ($this->serverVersion >= 50707) {
                    $this->ACCOUNT_SYMBOL();
                } else {
                    $this->IDENTIFIER();
                }
                break;
            case 'ACTION':
                $this->ACTION_SYMBOL();
                break;
            case 'ADD':
                $this->ADD_SYMBOL();
                break;
            case 'ADDDATE':
                $this->ADDDATE_SYMBOL();
                break;
            case 'AFTER':
                $this->AFTER_SYMBOL();
                break;
            case 'AGAINST':
                $this->AGAINST_SYMBOL();
                break;
            case 'AGGREGATE':
                $this->AGGREGATE_SYMBOL();
                break;
            case 'ALGORITHM':
                $this->ALGORITHM_SYMBOL();
                break;
            case 'ALL':
                $this->ALL_SYMBOL();
                break;
            case 'ALTER':
                $this->ALTER_SYMBOL();
                break;
            case 'ALWAYS':
                if ($this->serverVersion >= 50707) {
                    $this->ALWAYS_SYMBOL();
                } else {
                    $this->IDENTIFIER();
                }
                break;
            case 'ANALYSE':
                if ($this->serverVersion < 80000) {
                    $this->ANALYSE_SYMBOL();
                } else {
                    $this->IDENTIFIER();
                }
                break;
            case 'ANALYZE':
                $this->ANALYZE_SYMBOL();
                break;
            case 'AND':
                $this->AND_SYMBOL();
                break;
            case 'ANY':
                $this->ANY_SYMBOL();
                break;
            case 'AS':
                $this->AS_SYMBOL();
                break;
            case 'ASC':
                $this->ASC_SYMBOL();
                break;
            case 'ASCII':
                $this->ASCII_SYMBOL();
                break;
            case 'ASENSITIVE':
                $this->ASENSITIVE_SYMBOL();
                break;
            case 'AT':
                $this->AT_SYMBOL();
                break;
            case 'AUTHORS':
                if ($this->serverVersion < 50700) {
                    $this->AUTHORS_SYMBOL();
                } else {
                    $this->IDENTIFIER();
                }
                break;
            case 'AUTOEXTEND_SIZE':
                $this->AUTOEXTEND_SIZE_SYMBOL();
                break;
            case 'AUTO_INCREMENT':
                $this->AUTO_INCREMENT_SYMBOL();
                break;
            case 'AVG':
                $this->AVG_SYMBOL();
                break;
            case 'AVG_ROW_LENGTH':
                $this->AVG_ROW_LENGTH_SYMBOL();
                break;
            case 'BACKUP':
                $this->BACKUP_SYMBOL();
                break;
            case 'BEFORE':
                $this->BEFORE_SYMBOL();
                break;
            case 'BEGIN':
                $this->BEGIN_SYMBOL();
                break;
            case 'BETWEEN':
                $this->BETWEEN_SYMBOL();
                break;
            case 'BIGINT':
                $this->BIGINT_SYMBOL();
                break;
            case 'BINARY':
                $this->BINARY_SYMBOL();
                break;
            case 'BINLOG':
                $this->BINLOG_SYMBOL();
                break;
            case 'BIT':
                $this->BIT_SYMBOL();
                break;
            case 'BIT_AND':
                $this->BIT_AND_SYMBOL();
                break;
            case 'BIT_OR':
                $this->BIT_OR_SYMBOL();
                break;
            case 'BIT_XOR':
                $this->BIT_XOR_SYMBOL();
                break;
            case 'BLOB':
                $this->BLOB_SYMBOL();
                break;
            case 'BLOCK':
                $this->BLOCK_SYMBOL();
                break;
            case 'BOOL':
                $this->BOOL_SYMBOL();
                break;
            case 'BOOLEAN':
                $this->BOOLEAN_SYMBOL();
                break;
            case 'BOTH':
                $this->BOTH_SYMBOL();
                break;
            case 'BTREE':
                $this->BTREE_SYMBOL();
                break;
            case 'BY':
                $this->BY_SYMBOL();
                break;
            case 'BYTE':
                $this->BYTE_SYMBOL();
                break;
            case 'CACHE':
                $this->CACHE_SYMBOL();
                break;
            case 'CALL':
                $this->CALL_SYMBOL();
                break;
            case 'CASCADE':
                $this->CASCADE_SYMBOL();
                break;
            case 'CASCADED':
                $this->CASCADED_SYMBOL();
                break;
            case 'CASE':
                $this->CASE_SYMBOL();
                break;
            case 'CAST':
                $this->CAST_SYMBOL();
                break;
            case 'CATALOG_NAME':
                $this->CATALOG_NAME_SYMBOL();
                break;
            case 'CHAIN':
                $this->CHAIN_SYMBOL();
                break;
            case 'CHANGE':
                $this->CHANGE_SYMBOL();
                break;
            case 'CHANGED':
                $this->CHANGED_SYMBOL();
                break;
            case 'CHANNEL':
                if ($this->serverVersion >= 50706) {
                    $this->CHANNEL_SYMBOL();
                } else {
                    $this->IDENTIFIER();
                }
                break;
            case 'CHAR':
                $this->CHAR_SYMBOL();
                break;
            case 'CHARSET':
                $this->CHARSET_SYMBOL();
                break;
            case 'CHARACTER':
                $this->CHAR_SYMBOL(); // Synonym
                break;
            case 'CHECK':
                $this->CHECK_SYMBOL();
                break;
            case 'CHECKSUM':
                $this->CHECKSUM_SYMBOL();
                break;
            case 'CIPHER':
                $this->CIPHER_SYMBOL();
                break;
            case 'CLASS_ORIGIN':
                $this->CLASS_ORIGIN_SYMBOL();
                break;
            case 'CLIENT':
                $this->CLIENT_SYMBOL();
                break;
            case 'CLOSE':
                $this->CLOSE_SYMBOL();
                break;
            case 'COALESCE':
                $this->COALESCE_SYMBOL();
                break;
            case 'CODE':
                $this->CODE_SYMBOL();
                break;
            case 'COLLATE':
                $this->COLLATE_SYMBOL();
                break;
            case 'COLLATION':
                $this->COLLATION_SYMBOL();
                break;
            case 'COLUMN':
                $this->COLUMN_SYMBOL();
                break;
            case 'COLUMNS':
                $this->COLUMNS_SYMBOL();
                break;
            case 'COLUMN_FORMAT':
                $this->COLUMN_FORMAT_SYMBOL();
                break;
            case 'COLUMN_NAME':
                $this->COLUMN_NAME_SYMBOL();
                break;
            case 'COMMENT':
                $this->COMMENT_SYMBOL();
                break;
            case 'COMMITTED':
                $this->COMMITTED_SYMBOL();
                break;
            case 'COMMIT':
                $this->COMMIT_SYMBOL();
                break;
            case 'COMPACT':
                $this->COMPACT_SYMBOL();
                break;
            case 'COMPLETION':
                $this->COMPLETION_SYMBOL();
                break;
            case 'COMPRESSED':
                $this->COMPRESSED_SYMBOL();
                break;
            case 'COMPRESSION':
                if ($this->serverVersion >= 50707) {
                    $this->COMPRESSION_SYMBOL();
                } else {
                    $this->IDENTIFIER();
                }
                break;
            case 'CONCURRENT':
                $this->CONCURRENT_SYMBOL();
                break;
            case 'CONDITION':
                $this->CONDITION_SYMBOL();
                break;
            case 'CONNECTION':
                $this->CONNECTION_SYMBOL();
                break;
            case 'CONSISTENT':
                $this->CONSISTENT_SYMBOL();
                break;
            case 'CONSTRAINT':
                $this->CONSTRAINT_SYMBOL();
                break;
            case 'CONSTRAINTS':
                $this->CONSTRAINTS_SYMBOL();
                break;
            case 'CONSTRAINT_CATALOG':
                $this->CONSTRAINT_CATALOG_SYMBOL();
                break;
            case 'CONSTRAINT_NAME':
                $this->CONSTRAINT_NAME_SYMBOL();
                break;
            case 'CONSTRAINT_SCHEMA':
                $this->CONSTRAINT_SCHEMA_SYMBOL();
                break;
            case 'CONTAINS':
                $this->CONTAINS_SYMBOL();
                break;
            case 'CONTEXT':
                $this->CONTEXT_SYMBOL();
                break;
            case 'CONTINUE':
                $this->CONTINUE_SYMBOL();
                break;
            case 'CONTRIBUTORS':
                if ($this->serverVersion < 50700) {
                    $this->CONTRIBUTORS_SYMBOL();
                } else {
                    $this->IDENTIFIER();
                }
                break;
            case 'CONVERT':
                $this->CONVERT_SYMBOL();
                break;
            case 'COUNT':
                $this->COUNT_SYMBOL();
                break;
            case 'CPU':
                $this->CPU_SYMBOL();
                break;
            case 'CREATE':
                $this->CREATE_SYMBOL();
                break;
            case 'CROSS':
                $this->CROSS_SYMBOL();
                break;
            case 'CUBE':
                if ($this->serverVersion < 80000) {
                    $this->CUBE_SYMBOL();
                } else {
                    $this->IDENTIFIER();
                }
                break;
            case 'CURDATE':
                $this->CURDATE_SYMBOL();
                break;
            case 'CURRENT':
                if ($this->serverVersion >= 50604) {
                    $this->CURRENT_SYMBOL();
                } else {
                    $this->IDENTIFIER();
                }
                break;
            case 'CURRENT_DATE':
                $this->CURDATE_SYMBOL(); // Synonym
                break;
            case 'CURRENT_TIME':
                $this->CURTIME_SYMBOL(); // Synonym
                break;
            case 'CURRENT_TIMESTAMP':
                $this->NOW_SYMBOL(); // Synonym
                break;
            case 'CURRENT_USER':
                $this->CURRENT_USER_SYMBOL();
                break;
            case 'CURSOR':
                $this->CURSOR_SYMBOL();
                break;
            case 'CURSOR_NAME':
                $this->CURSOR_NAME_SYMBOL();
                break;
            case 'CURTIME':
                $this->CURTIME_SYMBOL();
                break;
            case 'DATABASE':
                $this->DATABASE_SYMBOL();
                break;
            case 'DATABASES':
                $this->DATABASES_SYMBOL();
                break;
            case 'DATAFILE':
                $this->DATAFILE_SYMBOL();
                break;
            case 'DATA':
                $this->DATA_SYMBOL();
                break;
            case 'DATETIME':
                $this->DATETIME_SYMBOL();
                break;
            case 'DATE':
                $this->DATE_SYMBOL();
                break;
            case 'DATE_ADD':
                $this->DATE_ADD_SYMBOL();
                break;
            case 'DATE_SUB':
                $this->DATE_SUB_SYMBOL();
                break;
            case 'DAY':
                $this->DAY_SYMBOL();
                break;
            case 'DAY_HOUR':
                $this->DAY_HOUR_SYMBOL();
                break;
            case 'DAY_MICROSECOND':
                $this->DAY_MICROSECOND_SYMBOL();
                break;
            case 'DAY_MINUTE':
                $this->DAY_MINUTE_SYMBOL();
                break;
            case 'DAY_SECOND':
                $this->DAY_SECOND_SYMBOL();
                break;
            case 'DAYOFMONTH':
                $this->DAY_SYMBOL(); // Synonym
                break;
            case 'DEALLOCATE':
                $this->DEALLOCATE_SYMBOL();
                break;
            case 'DEC':
                $this->DECIMAL_SYMBOL(); // Synonym
                break;
            case 'DECIMAL':
                $this->DECIMAL_SYMBOL();
                break;
            case 'DECLARE':
                $this->DECLARE_SYMBOL();
                break;
            case 'DEFAULT':
                $this->DEFAULT_SYMBOL();
                break;
            case 'DEFAULT_AUTH':
                if ($this->serverVersion >= 50604) {
                    $this->DEFAULT_AUTH_SYMBOL(); // Internal
                } else {
                    $this->IDENTIFIER();
                }
                break;
            case 'DEFINER':
                $this->DEFINER_SYMBOL();
                break;
            case 'DEFINITION':
                if ($this->serverVersion >= 80011) {
                    $this->DEFINITION_SYMBOL();
                } else {
                    $this->IDENTIFIER();
                }
                break;
            case 'DELAYED':
                $this->DELAYED_SYMBOL();
                break;
            case 'DELAY_KEY_WRITE':
                $this->DELAY_KEY_WRITE_SYMBOL();
                break;
            case 'DELETE':
                $this->DELETE_SYMBOL();
                break;
            case 'DENSE_RANK':
                if ($this->serverVersion >= 80000) {
                    $this->DENSE_RANK_SYMBOL();
                } else {
                    $this->IDENTIFIER();
                }
                break;
            case 'DESC':
                $this->DESC_SYMBOL();
                break;
            case 'DESCRIBE':
                $this->DESCRIBE_SYMBOL();
                break;
            case 'DESCRIPTION':
                if ($this->serverVersion >= 80011) {
                    $this->DESCRIPTION_SYMBOL();
                } else {
                    $this->IDENTIFIER();
                }
                break;
            case 'DES_KEY_FILE':
                if ($this->serverVersion < 80000) {
                    $this->DES_KEY_FILE_SYMBOL();
                } else {
                    $this->IDENTIFIER();
                }
                break;
            case 'DETERMINISTIC':
                $this->DETERMINISTIC_SYMBOL();
                break;
            case 'DIAGNOSTICS':
                $this->DIAGNOSTICS_SYMBOL();
                break;
            case 'DIRECTORY':
                $this->DIRECTORY_SYMBOL();
                break;
            case 'DISABLE':
                $this->DISABLE_SYMBOL();
                break;
            case 'DISCARD':
                $this->DISCARD_SYMBOL();
                break;
            case 'DISK':
                $this->DISK_SYMBOL();
                break;
            case 'DISTINCT':
                $this->DISTINCT_SYMBOL();
                break;
            case 'DISTINCTROW':
                $this->DISTINCT_SYMBOL(); // Synonym
                break;
            case 'DIV':
                $this->DIV_SYMBOL();
                break;
            case 'DOUBLE':
                $this->DOUBLE_SYMBOL();
                break;
            case 'DO':
                $this->DO_SYMBOL();
                break;
            case 'DROP':
                $this->DROP_SYMBOL();
                break;
            case 'DUAL':
                $this->DUAL_SYMBOL();
                break;
            case 'DUMPFILE':
                $this->DUMPFILE_SYMBOL();
                break;
            case 'DUPLICATE':
                $this->DUPLICATE_SYMBOL();
                break;
            case 'DYNAMIC':
                $this->DYNAMIC_SYMBOL();
                break;
            case 'EACH':
                $this->EACH_SYMBOL();
                break;
            case 'ELSE':
                $this->ELSE_SYMBOL();
                break;
            case 'ELSEIF':
                $this->ELSEIF_SYMBOL();
                break;
            case 'EMPTY':
                if ($this->serverVersion >= 80000) {
                    $this->EMPTY_SYMBOL();
                } else {
                    $this->IDENTIFIER();
                }
                break;
            case 'ENABLE':
                $this->ENABLE_SYMBOL();
                break;
            case 'ENCLOSED':
                $this->ENCLOSED_SYMBOL();
                break;
            case 'ENCRYPTION':
                if ($this->serverVersion >= 50711) {
                    $this->ENCRYPTION_SYMBOL();
                } else {
                    $this->IDENTIFIER();
                }
                break;
            case 'END':
                $this->END_SYMBOL();
                break;
            case 'ENDS':
                $this->ENDS_SYMBOL();
                break;
            case 'ENGINE':
                $this->ENGINE_SYMBOL();
                break;
            case 'ENGINES':
                $this->ENGINES_SYMBOL();
                break;
            case 'ENFORCED':
                if ($this->serverVersion >= 80017) {
                    $this->ENFORCED_SYMBOL();
                } else {
                    $this->IDENTIFIER();
                }
                break;
            case 'ENUM':
                $this->ENUM_SYMBOL();
                break;
            case 'ERRORS':
                $this->ERRORS_SYMBOL();
                break;
            case 'ERROR':
                $this->ERROR_SYMBOL();
                break;
            case 'ESCAPED':
                $this->ESCAPED_SYMBOL();
                break;
            case 'ESCAPE':
                $this->ESCAPE_SYMBOL();
                break;
            case 'EVENT':
                $this->EVENT_SYMBOL();
                break;
            case 'EVENTS':
                $this->EVENTS_SYMBOL();
                break;
            case 'EVERY':
                $this->EVERY_SYMBOL();
                break;
            case 'EXCEPT':
                if ($this->serverVersion >= 80000) {
                    $this->EXCEPT_SYMBOL();
                } else {
                    $this->IDENTIFIER();
                }
                break;
            case 'EXCHANGE':
                $this->EXCHANGE_SYMBOL();
                break;
            case 'EXCLUDE':
                if ($this->serverVersion >= 80000) {
                    $this->EXCLUDE_SYMBOL();
                } else {
                    $this->IDENTIFIER();
                }
                break;
            case 'EXECUTE':
                $this->EXECUTE_SYMBOL();
                break;
            case 'EXISTS':
                $this->EXISTS_SYMBOL();
                break;
            case 'EXIT':
                $this->EXIT_SYMBOL();
                break;
            case 'EXPANSION':
                $this->EXPANSION_SYMBOL();
                break;
            case 'EXPIRE':
                if ($this->serverVersion >= 50606) {
                    $this->EXPIRE_SYMBOL();
                } else {
                    $this->IDENTIFIER();
                }
                break;
            case 'EXPLAIN':
                $this->EXPLAIN_SYMBOL();
                break;
            case 'EXPORT':
                if ($this->serverVersion >= 50606) {
                    $this->EXPORT_SYMBOL();
                } else {
                    $this->IDENTIFIER();
                }
                break;
            case 'EXTENDED':
                $this->EXTENDED_SYMBOL();
                break;
            case 'EXTENT_SIZE':
                $this->EXTENT_SIZE_SYMBOL();
                break;
            case 'EXTRACT':
                $this->EXTRACT_SYMBOL();
                break;
            case 'FALSE':
                $this->FALSE_SYMBOL();
                break;
            case 'FAILED_LOGIN_ATTEMPTS':
                if ($this->serverVersion >= 80019) {
                    $this->FAILED_LOGIN_ATTEMPTS_SYMBOL();
                } else {
                    $this->IDENTIFIER();
                }
                break;
            case 'FAST':
                $this->FAST_SYMBOL();
                break;
            case 'FAULTS':
                $this->FAULTS_SYMBOL();
                break;
            case 'FETCH':
                $this->FETCH_SYMBOL();
                break;
            case 'FIELDS':
                $this->COLUMNS_SYMBOL(); // Synonym
                break;
            case 'FILE':
                $this->FILE_SYMBOL();
                break;
            case 'FILE_BLOCK_SIZE':
                if ($this->serverVersion >= 50707) {
                    $this->FILE_BLOCK_SIZE_SYMBOL();
                } else {
                    $this->IDENTIFIER();
                }
                break;
            case 'FILTER':
                if ($this->serverVersion >= 50700) {
                    $this->FILTER_SYMBOL();
                } else {
                    $this->IDENTIFIER();
                }
                break;
            case 'FIRST':
                $this->FIRST_SYMBOL();
                break;
            case 'FIRST_VALUE':
                if ($this->serverVersion >= 80000) {
                    $this->FIRST_VALUE_SYMBOL();
                } else {
                    $this->IDENTIFIER();
                }
                break;
            case 'FIXED':
                $this->FIXED_SYMBOL();
                break;
            case 'FLOAT':
                $this->FLOAT_SYMBOL();
                break;
            case 'FLOAT4':
                $this->FLOAT_SYMBOL(); // Synonym
                break;
            case 'FLOAT8':
                $this->DOUBLE_SYMBOL(); // Synonym
                break;
            case 'FLUSH':
                $this->FLUSH_SYMBOL();
                break;
            case 'FOLLOWS':
                if ($this->serverVersion >= 50700) {
                    $this->FOLLOWS_SYMBOL();
                } else {
                    $this->IDENTIFIER();
                }
                break;
            case 'FOLLOWING':
                if ($this->serverVersion >= 80000) {
                    $this->FOLLOWING_SYMBOL();
                } else {
                    $this->IDENTIFIER();
                }
                break;
            case 'FORCE':
                $this->FORCE_SYMBOL();
                break;
            case 'FOR':
                $this->FOR_SYMBOL();
                break;
            case 'FOREIGN':
                $this->FOREIGN_SYMBOL();
                break;
            case 'FORMAT':
                $this->FORMAT_SYMBOL();
                break;
            case 'FOUND':
                $this->FOUND_SYMBOL();
                break;
            case 'FROM':
                $this->FROM_SYMBOL();
                break;
            case 'FULL':
                $this->FULL_SYMBOL();
                break;
            case 'FULLTEXT':
                $this->FULLTEXT_SYMBOL();
                break;
            case 'FUNCTION':
                if ($this->serverVersion < 80000) {
                    $this->FUNCTION_SYMBOL();
                } else {
                    $this->IDENTIFIER();
                }
                break;
            case 'GENERATED':
                if ($this->serverVersion >= 50707) {
                    $this->GENERATED_SYMBOL();
                } else {
                    $this->IDENTIFIER();
                }
                break;
            case 'GENERAL':
                $this->GENERAL_SYMBOL();
                break;
            case 'GEOMETRYCOLLECTION':
                $this->GEOMETRYCOLLECTION_SYMBOL();
                break;
            case 'GEOMETRY':
                $this->GEOMETRY_SYMBOL();
                break;
            case 'GET':
                if ($this->serverVersion >= 50604) {
                    $this->GET_SYMBOL();
                } else {
                    $this->IDENTIFIER();
                }
                break;
            case 'GET_FORMAT':
                $this->GET_FORMAT_SYMBOL();
                break;
            case 'GLOBAL':
                $this->GLOBAL_SYMBOL();
                break;
            case 'GRANT':
                $this->GRANT_SYMBOL();
                break;
            case 'GRANTS':
                $this->GRANTS_SYMBOL();
                break;
            case 'GROUP':
                $this->GROUP_SYMBOL();
                break;
            case 'GROUP_CONCAT':
                $this->GROUP_CONCAT_SYMBOL();
                break;
            case 'GROUP_REPLICATION':
                if ($this->serverVersion >= 50707) {
                    $this->GROUP_REPLICATION_SYMBOL();
                } else {
                    $this->IDENTIFIER();
                }
                break;
            case 'GROUPING':
                if ($this->serverVersion >= 80000) {
                    $this->GROUPING_SYMBOL();
                } else {
                    $this->IDENTIFIER();
                }
                break;
            case 'GROUPS':
                if ($this->serverVersion >= 80000) {
                    $this->GROUPS_SYMBOL();
                } else {
                    $this->IDENTIFIER();
                }
                break;
            case 'HANDLER':
                $this->HANDLER_SYMBOL();
                break;
            case 'HASH':
                $this->HASH_SYMBOL();
                break;
            case 'HAVING':
                $this->HAVING_SYMBOL();
                break;
            case 'HELP':
                $this->HELP_SYMBOL();
                break;
            case 'HIGH_PRIORITY':
                $this->HIGH_PRIORITY_SYMBOL();
                break;
            case 'HISTOGRAM':
                if ($this->serverVersion >= 80000) {
                    $this->HISTOGRAM_SYMBOL();
                } else {
                    $this->IDENTIFIER();
                }
                break;
            case 'HISTORY':
                if ($this->serverVersion >= 80000) {
                    $this->HISTORY_SYMBOL();
                } else {
                    $this->IDENTIFIER();
                }
                break;
            case 'HOST':
                $this->HOST_SYMBOL();
                break;
            case 'HOSTS':
                $this->HOSTS_SYMBOL();
                break;
            case 'HOUR':
                $this->HOUR_SYMBOL();
                break;
            case 'HOUR_MICROSECOND':
                $this->HOUR_MICROSECOND_SYMBOL();
                break;
            case 'HOUR_MINUTE':
                $this->HOUR_MINUTE_SYMBOL();
                break;
            case 'HOUR_SECOND':
                $this->HOUR_SECOND_SYMBOL();
                break;
            case 'IDENTIFIED':
                $this->IDENTIFIED_SYMBOL();
                break;
            case 'IF':
                $this->IF_SYMBOL();
                break;
            case 'IGNORE':
                $this->IGNORE_SYMBOL();
                break;
            case 'IGNORE_SERVER_IDS':
                $this->IGNORE_SERVER_IDS_SYMBOL();
                break;
            case 'IMPORT':
                if ($this->serverVersion < 80000) {
                    $this->IMPORT_SYMBOL();
                } else {
                    $this->IDENTIFIER();
                }
                break;
            case 'IN':
                $this->IN_SYMBOL();
                break;
            case 'INDEX':
                $this->INDEX_SYMBOL();
                break;
            case 'INDEXES':
                $this->INDEXES_SYMBOL();
                break;
            case 'INFILE':
                $this->INFILE_SYMBOL();
                break;
            case 'INITIAL_SIZE':
                $this->INITIAL_SIZE_SYMBOL();
                break;
            case 'INNER':
                $this->INNER_SYMBOL();
                break;
            case 'INOUT':
                $this->INOUT_SYMBOL();
                break;
            case 'INSENSITIVE':
                $this->INSENSITIVE_SYMBOL();
                break;
            case 'INSERT':
                $this->INSERT_SYMBOL();
                break;
            case 'INSERT_METHOD':
                $this->INSERT_METHOD_SYMBOL();
                break;
            case 'INSTANCE':
                if ($this->serverVersion >= 50713) {
                    $this->INSTANCE_SYMBOL();
                } else {
                    $this->IDENTIFIER();
                }
                break;
            case 'INSTALL':
                $this->INSTALL_SYMBOL();
                break;
            case 'INT':
                $this->INT_SYMBOL();
                break;
            case 'INTEGER':
                $this->INT_SYMBOL(); // Synonym
                break;
            case 'INTERVAL':
                $this->INTERVAL_SYMBOL();
                break;
            case 'INTO':
                $this->INTO_SYMBOL();
                break;
            case 'INVISIBLE':
                if ($this->serverVersion >= 80000) {
                    $this->INVISIBLE_SYMBOL();
                } else {
                    $this->IDENTIFIER();
                }
                break;
            case 'INVOKER':
                $this->INVOKER_SYMBOL();
                break;
            case 'IO_THREAD':
                $this->RELAY_THREAD_SYMBOL(); // Synonym
                break;
            case 'IO_AFTER_GTIDS':
                $this->IDENTIFIER(); // MYSQL, FUTURE-USE
                break;
            case 'IO_BEFORE_GTIDS':
                $this->IDENTIFIER(); // MYSQL, FUTURE-USE
                break;
            case 'IO':
                $this->IO_SYMBOL();
                break;
            case 'IPC':
                $this->IPC_SYMBOL();
                break;
            case 'IS':
                $this->IS_SYMBOL();
                break;
            case 'ISOLATION':
                $this->ISOLATION_SYMBOL();
                break;
            case 'ISSUER':
                $this->ISSUER_SYMBOL();
                break;
            case 'ITERATE':
                $this->ITERATE_SYMBOL();
                break;
            case 'JOIN':
                $this->JOIN_SYMBOL();
                break;
            case 'JSON':
                if ($this->serverVersion >= 50708) {
                    $this->JSON_SYMBOL(); // MYSQL
                } else {
                    $this->IDENTIFIER();
                }
                break;
            case 'JSON_TABLE':
                if ($this->serverVersion >= 80000) {
                    $this->JSON_TABLE_SYMBOL(); // SQL-2016-R
                } else {
                    $this->IDENTIFIER();
                }
                break;
            case 'JSON_ARRAYAGG':
                if ($this->serverVersion >= 80000) {
                    $this->JSON_ARRAYAGG_SYMBOL();
                } else {
                    $this->IDENTIFIER();
                }
                break;
            case 'JSON_OBJECTAGG':
                if ($this->serverVersion >= 80000) {
                    $this->JSON_OBJECTAGG_SYMBOL();
                } else {
                    $this->IDENTIFIER();
                }
                break;
            case 'KEY':
                $this->KEY_SYMBOL();
                break;
            case 'KEYS':
                $this->KEYS_SYMBOL();
                break;
            case 'KEY_BLOCK_SIZE':
                $this->KEY_BLOCK_SIZE_SYMBOL();
                break;
            case 'KILL':
                $this->KILL_SYMBOL();
                break;
            case 'LAG':
                if ($this->serverVersion >= 80000) {
                    $this->LAG_SYMBOL();
                } else {
                    $this->IDENTIFIER();
                }
                break;
            case 'LANGUAGE':
                $this->LANGUAGE_SYMBOL();
                break;
            case 'LAST':
                $this->LAST_SYMBOL();
                break;
            case 'LAST_VALUE':
                if ($this->serverVersion >= 80000) {
                    $this->LAST_VALUE_SYMBOL();
                } else {
                    $this->IDENTIFIER();
                }
                break;
            case 'LATERAL':
                if ($this->serverVersion >= 80014) {
                    $this->LATERAL_SYMBOL();
                } else {
                    $this->IDENTIFIER();
                }
                break;
            case 'LEAD':
                if ($this->serverVersion >= 80000) {
                    $this->LEAD_SYMBOL();
                } else {
                    $this->IDENTIFIER();
                }
                break;
            case 'LEADING':
                $this->LEADING_SYMBOL();
                break;
            case 'LEAVE':
                $this->LEAVE_SYMBOL();
                break;
            case 'LEAVES':
                $this->LEAVES_SYMBOL();
                break;
            case 'LEFT':
                $this->LEFT_SYMBOL();
                break;
            case 'LESS':
                $this->LESS_SYMBOL();
                break;
            case 'LEVEL':
                $this->LEVEL_SYMBOL();
                break;
            case 'LIKE':
                $this->LIKE_SYMBOL();
                break;
            case 'LIMIT':
                $this->LIMIT_SYMBOL();
                break;
            case 'LINEAR':
                $this->LINEAR_SYMBOL();
                break;
            case 'LINES':
                $this->LINES_SYMBOL();
                break;
            case 'LINESTRING':
                $this->LINESTRING_SYMBOL();
                break;
            case 'LIST':
                $this->LIST_SYMBOL();
                break;
            case 'LOAD':
                $this->LOAD_SYMBOL();
                break;
            case 'LOCAL':
                $this->LOCAL_SYMBOL();
                break;
            case 'LOCALTIME':
                $this->NOW_SYMBOL(); // Synonym
                break;
            case 'LOCALTIMESTAMP':
                $this->NOW_SYMBOL(); // Synonym
                break;
            case 'LOCATOR':
                $this->LOCATOR_SYMBOL();
                break;
            case 'LOCK':
                $this->LOCK_SYMBOL();
                break;
            case 'LOCKS':
                $this->LOCKS_SYMBOL();
                break;
            case 'LOGFILE':
                $this->LOGFILE_SYMBOL();
                break;
            case 'LOGS':
                $this->LOGS_SYMBOL();
                break;
            case 'LONGBLOB':
                $this->LONGBLOB_SYMBOL();
                break;
            case 'LONGTEXT':
                $this->LONGTEXT_SYMBOL();
                break;
            case 'LONG':
                $this->LONG_SYMBOL();
                break;
            case 'LOOP':
                $this->LOOP_SYMBOL();
                break;
            case 'LOW_PRIORITY':
                $this->LOW_PRIORITY_SYMBOL();
                break;
            case 'MASTER':
                $this->MASTER_SYMBOL();
                break;
            case 'MASTER_AUTO_POSITION':
                if ($this->serverVersion >= 50605) {
                    $this->MASTER_AUTO_POSITION_SYMBOL();
                } else {
                    $this->IDENTIFIER();
                }
                break;
            case 'MASTER_BIND':
                if ($this->serverVersion >= 50602) {
                    $this->MASTER_BIND_SYMBOL();
                } else {
                    $this->IDENTIFIER();
                }
                break;
            case 'MASTER_COMPRESSION_ALGORITHM':
                if ($this->serverVersion >= 80018) {
                    $this->MASTER_COMPRESSION_ALGORITHM_SYMBOL();
                } else {
                    $this->IDENTIFIER();
                }
                break;
            case 'MASTER_CONNECT_RETRY':
                $this->MASTER_CONNECT_RETRY_SYMBOL();
                break;
            case 'MASTER_DELAY':
                $this->MASTER_DELAY_SYMBOL();
                break;
            case 'MASTER_HEARTBEAT_PERIOD':
                $this->MASTER_HEARTBEAT_PERIOD_SYMBOL();
                break;
            case 'MASTER_HOST':
                $this->MASTER_HOST_SYMBOL();
                break;
            case 'MASTER_LOG_FILE':
                $this->MASTER_LOG_FILE_SYMBOL();
                break;
            case 'MASTER_LOG_POS':
                $this->MASTER_LOG_POS_SYMBOL();
                break;
            case 'MASTER_PASSWORD':
                $this->MASTER_PASSWORD_SYMBOL();
                break;
            case 'MASTER_PORT':
                $this->MASTER_PORT_SYMBOL();
                break;
            case 'MASTER_PUBLIC_KEY_PATH':
                if ($this->serverVersion >= 80000) {
                    $this->MASTER_PUBLIC_KEY_PATH_SYMBOL();
                } else {
                    $this->IDENTIFIER();
                }
                break;
            case 'MASTER_RETRY_COUNT':
                if ($this->serverVersion >= 50601) {
                    $this->MASTER_RETRY_COUNT_SYMBOL();
                } else {
                    $this->IDENTIFIER();
                }
                break;
            case 'MASTER_SERVER_ID':
                $this->MASTER_SERVER_ID_SYMBOL();
                break;
            case 'MASTER_SSL':
                $this->MASTER_SSL_SYMBOL();
                break;
            case 'MASTER_SSL_CA':
                $this->MASTER_SSL_CA_SYMBOL();
                break;
            case 'MASTER_SSL_CAPATH':
                $this->MASTER_SSL_CAPATH_SYMBOL();
                break;
            case 'MASTER_SSL_CERT':
                $this->MASTER_SSL_CERT_SYMBOL();
                break;
            case 'MASTER_SSL_CIPHER':
                $this->MASTER_SSL_CIPHER_SYMBOL();
                break;
            case 'MASTER_SSL_CRL':
                if ($this->serverVersion >= 50603) {
                    $this->MASTER_SSL_CRL_SYMBOL();
                } else {
                    $this->IDENTIFIER();
                }
                break;
            case 'MASTER_SSL_CRLPATH':
                if ($this->serverVersion >= 50603) {
                    $this->MASTER_SSL_CRLPATH_SYMBOL();
                } else {
                    $this->IDENTIFIER();
                }
                break;
            case 'MASTER_SSL_KEY':
                $this->MASTER_SSL_KEY_SYMBOL();
                break;
            case 'MASTER_SSL_VERIFY_SERVER_CERT':
                $this->MASTER_SSL_VERIFY_SERVER_CERT_SYMBOL();
                break;
            case 'MASTER_TLS_CIPHERSUITES':
                if ($this->serverVersion >= 80018) {
                    $this->MASTER_TLS_CIPHERSUITES_SYMBOL();
                } else {
                    $this->IDENTIFIER();
                }
                break;
            case 'MASTER_TLS_VERSION':
                if ($this->serverVersion >= 50713) {
                    $this->MASTER_TLS_VERSION_SYMBOL();
                } else {
                    $this->IDENTIFIER();
                }
                break;
            case 'MASTER_USER':
                $this->MASTER_USER_SYMBOL();
                break;
            case 'MASTER_ZSTD_COMPRESSION_LEVEL':
                if ($this->serverVersion >= 80018) {
                    $this->MASTER_ZSTD_COMPRESSION_LEVEL_SYMBOL();
                } else {
                    $this->IDENTIFIER();
                }
                break;
            case 'MATCH':
                $this->MATCH_SYMBOL();
                break;
            case 'MAX':
                $this->MAX_SYMBOL();
                break;
            case 'MAX_CONNECTIONS_PER_HOUR':
                $this->MAX_CONNECTIONS_PER_HOUR_SYMBOL();
                break;
            case 'MAX_QUERIES_PER_HOUR':
                $this->MAX_QUERIES_PER_HOUR_SYMBOL();
                break;
            case 'MAX_ROWS':
                $this->MAX_ROWS_SYMBOL();
                break;
            case 'MAX_SIZE':
                $this->MAX_SIZE_SYMBOL();
                break;
            case 'MAX_STATEMENT_TIME':
                if (50704 < $this->serverVersion && $this->serverVersion < 50708) {
                    $this->MAX_STATEMENT_TIME_SYMBOL();
                } else {
                    $this->IDENTIFIER();
                }
                break;
            case 'MAX_UPDATES_PER_HOUR':
                $this->MAX_UPDATES_PER_HOUR_SYMBOL();
                break;
            case 'MAX_USER_CONNECTIONS':
                $this->MAX_USER_CONNECTIONS_SYMBOL();
                break;
            case 'MAXVALUE':
                $this->MAXVALUE_SYMBOL();
                break;
            case 'MEDIUM':
                $this->MEDIUM_SYMBOL();
                break;
            case 'MEDIUMBLOB':
                $this->MEDIUMBLOB_SYMBOL();
                break;
            case 'MEDIUMINT':
                $this->MEDIUMINT_SYMBOL();
                break;
            case 'MEDIUMTEXT':
                $this->MEDIUMTEXT_SYMBOL();
                break;
            case 'MEMBER':
                if ($this->serverVersion >= 80017) {
                    $this->MEMBER_SYMBOL();
                } else {
                    $this->IDENTIFIER();
                }
                break;
            case 'MEMORY':
                $this->MEMORY_SYMBOL();
                break;
            case 'MERGE':
                $this->MERGE_SYMBOL();
                break;
            case 'MESSAGE_TEXT':
                $this->MESSAGE_TEXT_SYMBOL();
                break;
            case 'MICROSECOND':
                $this->MICROSECOND_SYMBOL();
                break;
            case 'MIDDLEINT':
                $this->MEDIUMINT_SYMBOL(); // Synonym
                break;
            case 'MIGRATE':
                $this->MIGRATE_SYMBOL();
                break;
            case 'MINUTE':
                $this->MINUTE_SYMBOL();
                break;
            case 'MINUTE_MICROSECOND':
                $this->MINUTE_MICROSECOND_SYMBOL();
                break;
            case 'MINUTE_SECOND':
                $this->MINUTE_SECOND_SYMBOL();
                break;
            case 'MIN':
                $this->MIN_SYMBOL();
                break;
            case 'MIN_ROWS':
                $this->MIN_ROWS_SYMBOL();
                break;
            case 'MODE':
                $this->MODE_SYMBOL();
                break;
            case 'MODIFIES':
                $this->MODIFIES_SYMBOL();
                break;
            case 'MODIFY':
                $this->MODIFY_SYMBOL();
                break;
            case 'MOD':
                $this->MOD_SYMBOL();
                break;
            case 'MONTH':
                $this->MONTH_SYMBOL();
                break;
            case 'MULTILINESTRING':
                $this->MULTILINESTRING_SYMBOL();
                break;
            case 'MULTIPOINT':
                $this->MULTIPOINT_SYMBOL();
                break;
            case 'MULTIPOLYGON':
                $this->MULTIPOLYGON_SYMBOL();
                break;
            case 'MUTEX':
                $this->MUTEX_SYMBOL();
                break;
            case 'MYSQL_ERRNO':
                $this->MYSQL_ERRNO_SYMBOL();
                break;
            case 'NAME':
                $this->NAME_SYMBOL();
                break;
            case 'NAMES':
                $this->NAMES_SYMBOL();
                break;
            case 'NATIONAL':
                $this->NATIONAL_SYMBOL();
                break;
            case 'NATURAL':
                $this->NATURAL_SYMBOL();
                break;
            case 'NCHAR':
                $this->NCHAR_SYMBOL();
                break;
            case 'NDB':
                $this->NDBCLUSTER_SYMBOL(); // Synonym
                break;
            case 'NDBCLUSTER':
                $this->NDBCLUSTER_SYMBOL();
                break;
            case 'NETWORK_NAMESPACE':
                if ($this->serverVersion >= 80017) {
                    $this->NETWORK_NAMESPACE_SYMBOL();
                } else {
                    $this->IDENTIFIER();
                }
                break;
            case 'NEG':
                $this->NEG_SYMBOL();
                break;
            case 'NESTED':
                if ($this->serverVersion >= 80000) {
                    $this->NESTED_SYMBOL();
                } else {
                    $this->IDENTIFIER();
                }
                break;
            case 'NEVER':
                if ($this->serverVersion >= 50704) {
                    $this->NEVER_SYMBOL();
                } else {
                    $this->IDENTIFIER();
                }
                break;
            case 'NEW':
                $this->NEW_SYMBOL();
                break;
            case 'NEXT':
                $this->NEXT_SYMBOL();
                break;
            case 'NODEGROUP':
                $this->NODEGROUP_SYMBOL();
                break;
            case 'NONE':
                $this->NONE_SYMBOL();
                break;
            case 'NONBLOCKING':
                if (50700 < $this->serverVersion && $this->serverVersion < 50706) {
                    $this->NONBLOCKING_SYMBOL();
                } else {
                    $this->IDENTIFIER();
                }
                break;
            case 'NOT':
                $this->NOT_SYMBOL();
                break;
            case 'NOW':
                $this->NOW_SYMBOL();
                break;
            case 'NOWAIT':
                if ($this->serverVersion >= 80000) {
                    $this->NOWAIT_SYMBOL();
                } else {
                    $this->IDENTIFIER();
                }
                break;
            case 'NO':
                $this->NO_SYMBOL();
                break;
            case 'NO_WAIT':
                $this->NO_WAIT_SYMBOL();
                break;
            case 'NO_WRITE_TO_BINLOG':
                $this->NO_WRITE_TO_BINLOG_SYMBOL();
                break;
            case 'NULL':
                $this->NULL_SYMBOL();
                break;
            case 'NULLS':
                if ($this->serverVersion >= 80000) {
                    $this->NULLS_SYMBOL();
                } else {
                    $this->IDENTIFIER();
                }
                break;
            case 'NUMBER':
                if ($this->serverVersion >= 50606) {
                    $this->NUMBER_SYMBOL();
                } else {
                    $this->IDENTIFIER();
                }
                break;
            case 'NUMERIC':
                $this->NUMERIC_SYMBOL();
                break;
            case 'NVARCHAR':
                $this->NVARCHAR_SYMBOL();
                break;
            case 'NTH_VALUE':
                if ($this->serverVersion >= 80000) {
                    $this->NTH_VALUE_SYMBOL();
                } else {
                    $this->IDENTIFIER();
                }
                break;
            case 'NTILE':
                if ($this->serverVersion >= 80000) {
                    $this->NTILE_SYMBOL();
                } else {
                    $this->IDENTIFIER();
                }
                break;
            case 'OFF':
                if ($this->serverVersion >= 80019) {
                    $this->OFF_SYMBOL();
                } else {
                    $this->IDENTIFIER();
                }
                break;
            case 'OF':
                if ($this->serverVersion >= 80000) {
                    $this->OF_SYMBOL();
                } else {
                    $this->IDENTIFIER();
                }
                break;
            case 'OFFLINE':
                $this->OFFLINE_SYMBOL();
                break;
            case 'OFFSET':
                $this->OFFSET_SYMBOL();
                break;
            case 'OJ':
                if ($this->serverVersion >= 80017) {
                    $this->OJ_SYMBOL(); // ODBC
                } else {
                    $this->IDENTIFIER();
                }
                break;
            case 'OLD':
                if ($this->serverVersion >= 80014) {
                    $this->OLD_SYMBOL();
                } else {
                    $this->IDENTIFIER();
                }
                break;
            case 'OLD_PASSWORD':
                if ($this->serverVersion < 50706) {
                    $this->OLD_PASSWORD_SYMBOL();
                } else {
                    $this->IDENTIFIER();
                }
                break;
            case 'ON':
                $this->ON_SYMBOL();
                break;
            case 'ONE':
                $this->ONE_SYMBOL();
                break;
            case 'ONLINE':
                $this->ONLINE_SYMBOL();
                break;
            case 'ONLY':
                if ($this->serverVersion >= 50605) {
                    $this->ONLY_SYMBOL();
                } else {
                    $this->IDENTIFIER();
                }
                break;
            case 'OPEN':
                $this->OPEN_SYMBOL();
                break;
            case 'OPTIONAL':
                if ($this->serverVersion >= 80013) {
                    $this->OPTIONAL_SYMBOL();
                } else {
                    $this->IDENTIFIER();
                }
                break;
            case 'OPTIONALLY':
                $this->OPTIONALLY_SYMBOL();
                break;
            case 'OPTION':
                $this->OPTION_SYMBOL();
                break;
            case 'OPTIONS':
                $this->OPTIONS_SYMBOL();
                break;
            case 'OPTIMIZE':
                $this->OPTIMIZE_SYMBOL();
                break;
            case 'OPTIMIZER_COSTS':
                if ($this->serverVersion >= 50706) {
                    $this->OPTIMIZER_COSTS_SYMBOL();
                } else {
                    $this->IDENTIFIER();
                }
                break;
            case 'OR':
                $this->OR_SYMBOL();
                break;
            case 'ORDER':
                $this->ORDER_SYMBOL();
                break;
            case 'ORDINALITY':
                if ($this->serverVersion >= 80000) {
                    $this->ORDINALITY_SYMBOL();
                } else {
                    $this->IDENTIFIER();
                }
                break;
            case 'ORGANIZATION':
                if ($this->serverVersion >= 80011) {
                    $this->ORGANIZATION_SYMBOL();
                } else {
                    $this->IDENTIFIER();
                }
                break;
            case 'OTHERS':
                if ($this->serverVersion >= 80000) {
                    $this->OTHERS_SYMBOL();
                } else {
                    $this->IDENTIFIER();
                }
                break;
            case 'OUTER':
                $this->OUTER_SYMBOL();
                break;
            case 'OUTFILE':
                $this->OUTFILE_SYMBOL();
                break;
            case 'OUT':
                $this->OUT_SYMBOL();
                break;
            case 'OWNER':
                $this->OWNER_SYMBOL();
                break;
            case 'PACK_KEYS':
                $this->PACK_KEYS_SYMBOL();
                break;
            case 'PAGE':
                $this->PAGE_SYMBOL();
                break;
            case 'PARSER':
                $this->PARSER_SYMBOL();
                break;
            case 'PARTIAL':
                $this->PARTIAL_SYMBOL();
                break;
            case 'PARTITION':
                $this->PARTITION_SYMBOL();
                break;
            case 'PARTITIONING':
                $this->PARTITIONING_SYMBOL();
                break;
            case 'PARTITIONS':
                $this->PARTITIONS_SYMBOL();
                break;
            case 'PASSWORD':
                $this->PASSWORD_SYMBOL();
                break;
            case 'PASSWORD_LOCK_TIME':
                if ($this->serverVersion >= 80019) {
                    $this->PASSWORD_LOCK_TIME_SYMBOL();
                } else {
                    $this->IDENTIFIER();
                }
                break;
            case 'PATH':
                if ($this->serverVersion >= 80000) {
                    $this->PATH_SYMBOL();
                } else {
                    $this->IDENTIFIER();
                }
                break;
            case 'PERCENT_RANK':
                if ($this->serverVersion >= 80000) {
                    $this->PERCENT_RANK_SYMBOL();
                } else {
                    $this->IDENTIFIER();
                }
                break;
            case 'PERSIST':
                if ($this->serverVersion >= 80000) {
                    $this->PERSIST_SYMBOL();
                } else {
                    $this->IDENTIFIER();
                }
                break;
            case 'PERSIST_ONLY':
                if ($this->serverVersion >= 80000) {
                    $this->PERSIST_ONLY_SYMBOL();
                } else {
                    $this->IDENTIFIER();
                }
                break;
            case 'PHASE':
                $this->PHASE_SYMBOL();
                break;
            case 'PLUGIN':
                $this->PLUGIN_SYMBOL();
                break;
            case 'PLUGINS':
                $this->PLUGINS_SYMBOL();
                break;
            case 'PLUGIN_DIR':
                if ($this->serverVersion >= 50604) {
                    $this->PLUGIN_DIR_SYMBOL(); // Internal
                } else {
                    $this->IDENTIFIER();
                }
                break;
            case 'POINT':
                $this->POINT_SYMBOL();
                break;
            case 'POLYGON':
                $this->POLYGON_SYMBOL();
                break;
            case 'PORT':
                $this->PORT_SYMBOL();
                break;
            case 'POSITION':
                $this->POSITION_SYMBOL();
                break;
            case 'PRECEDES':
                if ($this->serverVersion >= 50700) {
                    $this->PRECEDES_SYMBOL();
                } else {
                    $this->IDENTIFIER();
                }
                break;
            case 'PRECEDING':
                if ($this->serverVersion >= 80000) {
                    $this->PRECEDING_SYMBOL();
                } else {
                    $this->IDENTIFIER();
                }
                break;
            case 'PRECISION':
                $this->PRECISION_SYMBOL();
                break;
            case 'PREPARE':
                $this->PREPARE_SYMBOL();
                break;
            case 'PRESERVE':
                $this->PRESERVE_SYMBOL();
                break;
            case 'PREV':
                $this->PREV_SYMBOL();
                break;
            case 'PRIMARY':
                $this->PRIMARY_SYMBOL();
                break;
            case 'PRIVILEGE_CHECKS_USER':
                if ($this->serverVersion >= 80018) {
                    $this->PRIVILEGE_CHECKS_USER_SYMBOL();
                } else {
                    $this->IDENTIFIER();
                }
                break;
            case 'PRIVILEGES':
                $this->PRIVILEGES_SYMBOL();
                break;
            case 'PROCEDURE':
                $this->PROCEDURE_SYMBOL();
                break;
            case 'PROCESS':
                $this->PROCESS_SYMBOL();
                break;
            case 'PROCESSLIST':
                $this->PROCESSLIST_SYMBOL();
                break;
            case 'PROFILE':
                $this->PROFILE_SYMBOL();
                break;
            case 'PROFILES':
                $this->PROFILES_SYMBOL();
                break;
            case 'PROXY':
                $this->PROXY_SYMBOL();
                break;
            case 'PURGE':
                $this->PURGE_SYMBOL();
                break;
            case 'QUARTER':
                $this->QUARTER_SYMBOL();
                break;
            case 'QUERY':
                $this->QUERY_SYMBOL();
                break;
            case 'QUICK':
                $this->QUICK_SYMBOL();
                break;
            case 'RANDOM':
                if ($this->serverVersion >= 80018) {
                    $this->RANDOM_SYMBOL();
                } else {
                    $this->IDENTIFIER();
                }
                break;
            case 'RANGE':
                $this->RANGE_SYMBOL();
                break;
            case 'RANK':
                if ($this->serverVersion >= 80000) {
                    $this->RANK_SYMBOL();
                } else {
                    $this->IDENTIFIER();
                }
                break;
            case 'READ':
                $this->READ_SYMBOL();
                break;
            case 'READS':
                $this->READS_SYMBOL();
                break;
            case 'READ_ONLY':
                $this->READ_ONLY_SYMBOL();
                break;
            case 'READ_WRITE':
                $this->READ_WRITE_SYMBOL();
                break;
            case 'REAL':
                $this->REAL_SYMBOL();
                break;
            case 'REBUILD':
                $this->REBUILD_SYMBOL();
                break;
            case 'RECOVER':
                $this->RECOVER_SYMBOL();
                break;
            case 'RECURSIVE':
                if ($this->serverVersion >= 80000) {
                    $this->RECURSIVE_SYMBOL();
                } else {
                    $this->IDENTIFIER();
                }
                break;
            case 'REDOFILE':
                if ($this->serverVersion < 80000) {
                    $this->REDOFILE_SYMBOL();
                } else {
                    $this->IDENTIFIER();
                }
                break;
            case 'REDO_BUFFER_SIZE':
                $this->REDO_BUFFER_SIZE_SYMBOL();
                break;
            case 'REDUNDANT':
                $this->REDUNDANT_SYMBOL();
                break;
            case 'REFERENCE':
                if ($this->serverVersion >= 80011) {
                    $this->REFERENCE_SYMBOL();
                } else {
                    $this->IDENTIFIER();
                }
                break;
            case 'REFERENCES':
                $this->REFERENCES_SYMBOL();
                break;
            case 'REGEXP':
                $this->REGEXP_SYMBOL();
                break;
            case 'RELAY':
                $this->RELAY_SYMBOL();
                break;
            case 'RELAYLOG':
                $this->RELAYLOG_SYMBOL();
                break;
            case 'RELAY_LOG_FILE':
                $this->RELAY_LOG_FILE_SYMBOL();
                break;
            case 'RELAY_LOG_POS':
                $this->RELAY_LOG_POS_SYMBOL();
                break;
            case 'RELAY_THREAD':
                $this->RELAY_THREAD_SYMBOL();
                break;
            case 'RELEASE':
                $this->RELEASE_SYMBOL();
                break;
            case 'RELOAD':
                $this->RELOAD_SYMBOL();
                break;
            case 'REMOTE':
                if ($this->serverVersion >= 80003 && $this->serverVersion < 80014) {
                    $this->REMOTE_SYMBOL();
                } else {
                    $this->IDENTIFIER();
                }
                break;
            case 'REMOVE':
                $this->REMOVE_SYMBOL();
                break;
            case 'RENAME':
                $this->RENAME_SYMBOL();
                break;
            case 'REORGANIZE':
                $this->REORGANIZE_SYMBOL();
                break;
            case 'REPAIR':
                $this->REPAIR_SYMBOL();
                break;
            case 'REPEAT':
                $this->REPEAT_SYMBOL();
                break;
            case 'REPEATABLE':
                $this->REPEATABLE_SYMBOL();
                break;
            case 'REPLACE':
                $this->REPLACE_SYMBOL();
                break;
            case 'REPLICATION':
                $this->REPLICATION_SYMBOL();
                break;
            case 'REPLICATE_DO_DB':
                if ($this->serverVersion >= 50700) {
                    $this->REPLICATE_DO_DB_SYMBOL();
                } else {
                    $this->IDENTIFIER();
                }
                break;
            case 'REPLICATE_IGNORE_DB':
                if ($this->serverVersion >= 50700) {
                    $this->REPLICATE_IGNORE_DB_SYMBOL();
                } else {
                    $this->IDENTIFIER();
                }
                break;
            case 'REPLICATE_DO_TABLE':
                if ($this->serverVersion >= 50700) {
                    $this->REPLICATE_DO_TABLE_SYMBOL();
                } else {
                    $this->IDENTIFIER();
                }
                break;
            case 'REPLICATE_IGNORE_TABLE':
                if ($this->serverVersion >= 50700) {
                    $this->REPLICATE_IGNORE_TABLE_SYMBOL();
                } else {
                    $this->IDENTIFIER();
                }
                break;
            case 'REPLICATE_WILD_DO_TABLE':
                if ($this->serverVersion >= 50700) {
                    $this->REPLICATE_WILD_DO_TABLE_SYMBOL();
                } else {
                    $this->IDENTIFIER();
                }
                break;
            case 'REPLICATE_WILD_IGNORE_TABLE':
                if ($this->serverVersion >= 50700) {
                    $this->REPLICATE_WILD_IGNORE_TABLE_SYMBOL();
                } else {
                    $this->IDENTIFIER();
                }
                break;
            case 'REPLICATE_REWRITE_DB':
                if ($this->serverVersion >= 50700) {
                    $this->REPLICATE_REWRITE_DB_SYMBOL();
                } else {
                    $this->IDENTIFIER();
                }
                break;
            case 'REQUIRE':
                $this->REQUIRE_SYMBOL();
                break;
            case 'REQUIRE_ROW_FORMAT':
                if ($this->serverVersion >= 80019) {
                    $this->REQUIRE_ROW_FORMAT_SYMBOL();
                } else {
                    $this->IDENTIFIER();
                }
                break;
            case 'REQUIRE_TABLE_PRIMARY_KEY_CHECK':
                if ($this->serverVersion >= 80019) {
                    $this->REQUIRE_TABLE_PRIMARY_KEY_CHECK_SYMBOL();
                } else {
                    $this->IDENTIFIER();
                }
                break;
            case 'RESOURCE':
                if ($this->serverVersion >= 80000) {
                    $this->RESOURCE_SYMBOL();
                } else {
                    $this->IDENTIFIER();
                }
                break;
            case 'RESPECT':
                if ($this->serverVersion >= 80000) {
                    $this->RESPECT_SYMBOL();
                } else {
                    $this->IDENTIFIER();
                }
                break;
            case 'RESTART':
                if ($this->serverVersion >= 80011) {
                    $this->RESTART_SYMBOL();
                } else {
                    $this->IDENTIFIER();
                }
                break;
            case 'RESTORE':
                $this->RESTORE_SYMBOL();
                break;
            case 'RESTRICT':
                $this->RESTRICT_SYMBOL();
                break;
            case 'RESUME':
                $this->RESUME_SYMBOL();
                break;
            case 'RETAIN':
                if ($this->serverVersion >= 80014) {
                    $this->RETAIN_SYMBOL();
                } else {
                    $this->IDENTIFIER();
                }
                break;
            case 'RETURNED_SQLSTATE':
                $this->RETURNED_SQLSTATE_SYMBOL();
                break;
            case 'RETURNS':
                $this->RETURNS_SYMBOL();
                break;
            case 'RETURN':
                $this->RETURN_SYMBOL();
                break;
            case 'REUSE':
                if ($this->serverVersion >= 80000) {
                    $this->REUSE_SYMBOL();
                } else {
                    $this->IDENTIFIER();
                }
                break;
            case 'REVERSE':
                $this->REVERSE_SYMBOL();
                break;
            case 'REVOKE':
                $this->REVOKE_SYMBOL();
                break;
            case 'RIGHT':
                $this->RIGHT_SYMBOL();
                break;
            case 'RLIKE':
                $this->REGEXP_SYMBOL(); // Synonym
                break;
            case 'ROLE':
                if ($this->serverVersion >= 80000) {
                    $this->ROLE_SYMBOL();
                } else {
                    $this->IDENTIFIER();
                }
                break;
            case 'ROLLBACK':
                $this->ROLLBACK_SYMBOL();
                break;
            case 'ROLLUP':
                $this->ROLLUP_SYMBOL();
                break;
            case 'ROTATE':
                if ($this->serverVersion >= 50713) {
                    $this->ROTATE_SYMBOL();
                } else {
                    $this->IDENTIFIER();
                }
                break;
            case 'ROW':
                if ($this->serverVersion < 80000) {
                    $this->ROW_SYMBOL();
                } else {
                    $this->IDENTIFIER();
                }
                break;
            case 'ROWS':
                if ($this->serverVersion <               80000) {
                    $this->ROWS_SYMBOL();
                } else {
                    $this->IDENTIFIER();
                }
                break;
            case 'ROW_COUNT':
                $this->ROW_COUNT_SYMBOL();
                break;
            case 'ROW_FORMAT':
                $this->ROW_FORMAT_SYMBOL();
                break;
            case 'ROW_NUMBER':
                if ($this->serverVersion >= 80000) {
                    $this->ROW_NUMBER_SYMBOL();
                } else {
                    $this->IDENTIFIER();
                }
                break;
            case 'RTREE':
                $this->RTREE_SYMBOL();
                break;
            case 'SAVEPOINT':
                $this->SAVEPOINT_SYMBOL();
                break;
            case 'SCHEDULE':
                $this->SCHEDULE_SYMBOL();
                break;
            case 'SCHEMA':
                $this->DATABASE_SYMBOL(); // Synonym
                break;
            case 'SCHEMAS':
                $this->DATABASES_SYMBOL(); // Synonym
                break;
            case 'SCHEMA_NAME':
                $this->SCHEMA_NAME_SYMBOL();
                break;
            case 'SECOND':
                $this->SECOND_SYMBOL();
                break;
            case 'SECOND_MICROSECOND':
                $this->SECOND_MICROSECOND_SYMBOL();
                break;
            case 'SECONDARY':
                if ($this->serverVersion >= 80013) {
                    $this->SECONDARY_SYMBOL();
                } else {
                    $this->IDENTIFIER();
                }
                break;
            case 'SECONDARY_ENGINE':
                if ($this->serverVersion >= 80013) {
                    $this->SECONDARY_ENGINE_SYMBOL();
                } else {
                    $this->IDENTIFIER();
                }
                break;
            case 'SECONDARY_LOAD':
                if ($this->serverVersion >= 80013) {
                    $this->SECONDARY_LOAD_SYMBOL();
                } else {
                    $this->IDENTIFIER();
                }
                break;
            case 'SECONDARY_UNLOAD':
                if ($this->serverVersion >= 80013) {
                    $this->SECONDARY_UNLOAD_SYMBOL();
                } else {
                    $this->IDENTIFIER();
                }
                break;
            case 'SECURITY':
                $this->SECURITY_SYMBOL();
                break;
            case 'SELECT':
                $this->SELECT_SYMBOL();
                break;
            case 'SENSITIVE':
                $this->SENSITIVE_SYMBOL();
                break;
            case 'SEPARATOR':
                $this->SEPARATOR_SYMBOL();
                break;
            case 'SERIALIZABLE':
                $this->SERIALIZABLE_SYMBOL();
                break;
            case 'SERIAL':
                $this->SERIAL_SYMBOL();
                break;
            case 'SERVER':
                $this->SERVER_SYMBOL();
                break;
            case 'SERVER_OPTIONS':
                $this->SERVER_OPTIONS_SYMBOL();
                break;
            case 'SESSION':
                $this->SESSION_SYMBOL();
                break;
            case 'SESSION_USER':
                $this->USER_SYMBOL(); // Synonym
                break;
            case 'SET':
                $this->SET_SYMBOL();
                break;
            case 'SET_VAR':
                $this->SET_VAR_SYMBOL();
                break;
            case 'SHARE':
                $this->SHARE_SYMBOL();
                break;
            case 'SHOW':
                $this->SHOW_SYMBOL();
                break;
            case 'SHUTDOWN':
                if ($this->serverVersion < 50709) {
                    $this->SHUTDOWN_SYMBOL();
                } else {
                    $this->IDENTIFIER();
                }
                break;
            case 'SIGNAL':
                $this->SIGNAL_SYMBOL();
                break;
            case 'SIGNED':
                $this->SIGNED_SYMBOL();
                break;
            case 'SIMPLE':
                $this->SIMPLE_SYMBOL();
                break;
            case 'SKIP':
                if ($this->serverVersion >= 80000) {
                    $this->SKIP_SYMBOL();
                } else {
                    $this->IDENTIFIER();
                }
                break;
            case 'SLAVE':
                $this->SLAVE_SYMBOL();
                break;
            case 'SLOW':
                $this->SLOW_SYMBOL();
                break;
            case 'SMALLINT':
                $this->SMALLINT_SYMBOL();
                break;
            case 'SNAPSHOT':
                $this->SNAPSHOT_SYMBOL();
                break;
            case 'SOME':
                $this->ANY_SYMBOL(); // Synonym
                break;
            case 'SOCKET':
                $this->SOCKET_SYMBOL();
                break;
            case 'SONAME':
                $this->SONAME_SYMBOL();
                break;
            case 'SOUNDS':
                $this->SOUNDS_SYMBOL();
                break;
            case 'SOURCE':
                $this->SOURCE_SYMBOL();
                break;
            case 'SPATIAL':
                $this->SPATIAL_SYMBOL();
                break;
            case 'SPECIFIC':
                $this->SPECIFIC_SYMBOL();
                break;
            case 'SQL':
                $this->SQL_SYMBOL();
                break;
            case 'SQLEXCEPTION':
                $this->SQLEXCEPTION_SYMBOL();
                break;
            case 'SQLSTATE':
                $this->SQLSTATE_SYMBOL();
                break;
            case 'SQLWARNING':
                $this->SQLWARNING_SYMBOL();
                break;
            case 'SQL_AFTER_GTIDS':
                $this->SQL_AFTER_GTIDS_SYMBOL();
                break;
            case 'SQL_AFTER_MTS_GAPS':
                if ($this->serverVersion >= 50606) {
                    $this->SQL_AFTER_MTS_GAPS_SYMBOL();
                } else {
                    $this->IDENTIFIER();
                }
                break;
            case 'SQL_BEFORE_GTIDS':
                $this->SQL_BEFORE_GTIDS_SYMBOL();
                break;
            case 'SQL_BIG_RESULT':
                $this->SQL_BIG_RESULT_SYMBOL();
                break;
            case 'SQL_BUFFER_RESULT':
                $this->SQL_BUFFER_RESULT_SYMBOL();
                break;
            case 'SQL_CALC_FOUND_ROWS':
                $this->SQL_CALC_FOUND_ROWS_SYMBOL();
                break;
            case 'SQL_CACHE':
                if ($this->serverVersion < 80000) {
                    $this->SQL_CACHE_SYMBOL();
                } else {
                    $this->IDENTIFIER();
                }
                break;
            case 'SQL_NO_CACHE':
                $this->SQL_NO_CACHE_SYMBOL();
                break;
            case 'SQL_SMALL_RESULT':
                $this->SQL_SMALL_RESULT_SYMBOL();
                break;
            case 'SQL_THREAD':
                $this->SQL_THREAD_SYMBOL();
                break;
            case 'SQL_TSI_SECOND':
                $this->SECOND_SYMBOL(); // Synonym
                break;
            case 'SQL_TSI_MINUTE':
                $this->MINUTE_SYMBOL(); // Synonym
                break;
            case 'SQL_TSI_HOUR':
                $this->HOUR_SYMBOL(); // Synonym
                break;
            case 'SQL_TSI_DAY':
                $this->DAY_SYMBOL(); // Synonym
                break;
            case 'SQL_TSI_WEEK':
                $this->WEEK_SYMBOL(); // Synonym
                break;
            case 'SQL_TSI_MONTH':
                $this->MONTH_SYMBOL(); // Synonym
                break;
            case 'SQL_TSI_QUARTER':
                $this->QUARTER_SYMBOL(); // Synonym
                break;
            case 'SQL_TSI_YEAR':
                $this->YEAR_SYMBOL(); // Synonym
                break;
            case 'SRID':
                if ($this->serverVersion >= 80000) {
                    $this->SRID_SYMBOL();
                } else {
                    $this->IDENTIFIER();
                }
                break;
            case 'SSL':
                $this->SSL_SYMBOL();
                break;
            case 'STACKED':
                if ($this->serverVersion >= 50700) {
                    $this->STACKED_SYMBOL();
                } else {
                    $this->IDENTIFIER();
                }
                break;
            case 'STARTING':
                $this->STARTING_SYMBOL();
                break;
            case 'STARTS':
                $this->STARTS_SYMBOL();
                break;
            case 'START':
                $this->START_SYMBOL();
                break;
            case 'STATS_AUTO_RECALC':
                $this->STATS_AUTO_RECALC_SYMBOL();
                break;
            case 'STATS_PERSISTENT':
                $this->STATS_PERSISTENT_SYMBOL();
                break;
            case 'STATS_SAMPLE_PAGES':
                $this->STATS_SAMPLE_PAGES_SYMBOL();
                break;
            case 'STATUS':
                $this->STATUS_SYMBOL();
                break;
            case 'STD':
                $this->STD_SYMBOL();
                break;
            case 'STDDEV':
                $this->STD_SYMBOL(); // Synonym
                break;
            case 'STDDEV_POP':
                $this->STD_SYMBOL(); // Synonym
                break;
            case 'STDDEV_SAMP':
                $this->STDDEV_SAMP_SYMBOL();
                break;
            case 'STOP':
                $this->STOP_SYMBOL();
                break;
            case 'STORAGE':
                $this->STORAGE_SYMBOL();
                break;
            case 'STORED':
                if ($this->serverVersion >= 50707) {
                    $this->STORED_SYMBOL();
                } else {
                    $this->IDENTIFIER();
                }
                break;
            case 'STRAIGHT_JOIN':
                $this->STRAIGHT_JOIN_SYMBOL();
                break;
            case 'STREAM':
                if ($this->serverVersion >= 80019) {
                    $this->STREAM_SYMBOL();
                } else {
                    $this->IDENTIFIER();
                }
                break;
            case 'STRING':
                $this->STRING_SYMBOL();
                break;
            case 'SUBCLASS_ORIGIN':
                $this->SUBCLASS_ORIGIN_SYMBOL();
                break;
            case 'SUBDATE':
                $this->SUBDATE_SYMBOL();
                break;
            case 'SUBJECT':
                $this->SUBJECT_SYMBOL();
                break;
            case 'SUBPARTITION':
                $this->SUBPARTITION_SYMBOL();
                break;
            case 'SUBPARTITIONS':
                $this->SUBPARTITIONS_SYMBOL();
                break;
            case 'SUBSTR':
                $this->SUBSTRING_SYMBOL(); // Synonym
                break;
            case 'SUBSTRING':
                $this->SUBSTRING_SYMBOL();
                break;
            case 'SUM':
                $this->SUM_SYMBOL();
                break;
            case 'SUPER':
                $this->SUPER_SYMBOL();
                break;
            case 'SUSPEND':
                $this->SUSPEND_SYMBOL();
                break;
            case 'SWAPS':
                $this->SWAPS_SYMBOL();
                break;
            case 'SWITCHES':
                $this->SWITCHES_SYMBOL();
                break;
            case 'SYSDATE':
                $this->SYSDATE_SYMBOL();
                break;
            case 'SYSTEM':
                if ($this->serverVersion >= 80000) {
                    $this->SYSTEM_SYMBOL();
                } else {
                    $this->IDENTIFIER();
                }
                break;
            case 'SYSTEM_USER':
                $this->USER_SYMBOL(); // Synonym
                break;
            case 'TABLE':
                $this->TABLE_SYMBOL();
                break;
            case 'TABLES':
                $this->TABLES_SYMBOL();
                break;
            case 'TABLESPACE':
                $this->TABLESPACE_SYMBOL();
                break;
            case 'TABLE_CHECKSUM':
                $this->TABLE_CHECKSUM_SYMBOL();
                break;
            case 'TABLE_NAME':
                $this->TABLE_NAME_SYMBOL();
                break;
            case 'TEMPORARY':
                $this->TEMPORARY_SYMBOL();
                break;
            case 'TEMPTABLE':
                $this->TEMPTABLE_SYMBOL();
                break;
            case 'TERMINATED':
                $this->TERMINATED_SYMBOL();
                break;
            case 'TEXT':
                $this->TEXT_SYMBOL();
                break;
            case 'THAN':
                $this->THAN_SYMBOL();
                break;
            case 'THEN':
                $this->THEN_SYMBOL();
                break;
            case 'THREAD_PRIORITY':
                if ($this->serverVersion >= 80000) {
                    $this->THREAD_PRIORITY_SYMBOL();
                } else {
                    $this->IDENTIFIER();
                }
                break;
            case 'TIES':
                if ($this->serverVersion >= 80000) {
                    $this->TIES_SYMBOL();
                } else {
                    $this->IDENTIFIER();
                }
                break;
            case 'TIME':
                $this->TIME_SYMBOL();
                break;
            case 'TIMESTAMP':
                $this->TIMESTAMP_SYMBOL();
                break;
            case 'TIMESTAMPADD':
                $this->TIMESTAMP_ADD_SYMBOL();
                break;
            case 'TIMESTAMPDIFF':
                $this->TIMESTAMP_DIFF_SYMBOL();
                break;
            case 'TINYBLOB':
                $this->TINYBLOB_SYMBOL();
                break;
            case 'TINYINT':
                $this->TINYINT_SYMBOL();
                break;
            case 'TINYTEXT':
                $this->TINYTEXT_SYMBOL();
                break;
            case 'TO':
                $this->TO_SYMBOL();
                break;
            case 'TRAILING':
                $this->TRAILING_SYMBOL();
                break;
            case 'TRANSACTION':
                $this->TRANSACTION_SYMBOL();
                break;
            case 'TRIGGER':
                $this->TRIGGER_SYMBOL();
                break;
            case 'TRIGGERS':
                $this->TRIGGERS_SYMBOL();
                break;
            case 'TRIM':
                $this->TRIM_SYMBOL();
                break;
            case 'TRUE':
                $this->TRUE_SYMBOL();
                break;
            case 'TRUNCATE':
                $this->TRUNCATE_SYMBOL();
                break;
            case 'TYPES':
                $this->TYPES_SYMBOL();
                break;
            case 'TYPE':
                $this->TYPE_SYMBOL();
                break;
            case 'UDF_RETURNS':
                $this->UDF_RETURNS_SYMBOL();
                break;
            case 'UNBOUNDED':
                if ($this->serverVersion >= 80000) {
                    $this->UNBOUNDED_SYMBOL();
                } else {
                    $this->IDENTIFIER();
                }
                break;
            case 'UNCOMMITTED':
                $this->UNCOMMITTED_SYMBOL();
                break;
            case 'UNDEFINED':
                $this->UNDEFINED_SYMBOL();
                break;
            case 'UNDO':
                $this->UNDO_SYMBOL();
                break;
            case 'UNDO_BUFFER_SIZE':
                $this->UNDO_BUFFER_SIZE_SYMBOL();
                break;
            case 'UNDOFILE':
                $this->UNDOFILE_SYMBOL();
                break;
            case 'UNICODE':
                $this->UNICODE_SYMBOL();
                break;
            case 'UNION':
                $this->UNION_SYMBOL();
                break;
            case 'UNIQUE':
                $this->UNIQUE_SYMBOL();
                break;
            case 'UNKNOWN':
                $this->UNKNOWN_SYMBOL();
                break;
            case 'UNINSTALL':
                $this->UNINSTALL_SYMBOL();
                break;
            case 'UNLOCK':
                $this->UNLOCK_SYMBOL();
                break;
            case 'UNSIGNED':
                $this->UNSIGNED_SYMBOL();
                break;
            case 'UPDATE':
                $this->UPDATE_SYMBOL();
                break;
            case 'UPGRADE':
                $this->UPGRADE_SYMBOL();
                break;
            case 'USAGE':
                $this->USAGE_SYMBOL();
                break;
            case 'USER':
                $this->USER_SYMBOL();
                break;
            case 'USER_RESOURCES':
                $this->USER_RESOURCES_SYMBOL();
                break;
            case 'USE':
                $this->USE_SYMBOL();
                break;
            case 'USE_FRM':
                $this->USE_FRM_SYMBOL();
                break;
            case 'USING':
                $this->USING_SYMBOL();
                break;
            case 'UTC_DATE':
                $this->UTC_DATE_SYMBOL();
                break;
            case 'UTC_TIME':
                $this->UTC_TIME_SYMBOL();
                break;
            case 'UTC_TIMESTAMP':
                $this->UTC_TIMESTAMP_SYMBOL();
                break;
            case 'VALIDATION':
                if ($this->serverVersion >= 50706) {
                    $this->VALIDATION_SYMBOL();
                } else {
                    $this->IDENTIFIER();
                }
                break;
            case 'VALUE':
                $this->VALUE_SYMBOL();
                break;
            case 'VALUES':
                $this->VALUES_SYMBOL();
                break;
            case 'VARBINARY':
                $this->VARBINARY_SYMBOL();
                break;
            case 'VARCHAR':
                $this->VARCHAR_SYMBOL();
                break;
            case 'VARCHARACTER':
                $this->VARCHAR_SYMBOL(); // Synonym
                break;
            case 'VARIABLES':
                $this->VARIABLES_SYMBOL();
                break;
            case 'VARIANCE':
                $this->VARIANCE_SYMBOL();
                break;
            case 'VARYING':
                $this->VARYING_SYMBOL();
                break;
            case 'VAR_POP':
                $this->VARIANCE_SYMBOL(); // Synonym
                break;
            case 'VAR_SAMP':
                $this->VAR_SAMP_SYMBOL();
                break;
            case 'VCPU':
                if ($this->serverVersion >= 80000) {
                    $this->VCPU_SYMBOL();
                } else {
                    $this->IDENTIFIER();
                }
                break;
            case 'VIEW':
                $this->VIEW_SYMBOL();
                break;
            case 'VIRTUAL':
                if ($this->serverVersion >= 50707) {
                    $this->VIRTUAL_SYMBOL();
                } else {
                    $this->IDENTIFIER();
                }
                break;
            case 'VISIBLE':
                if ($this->serverVersion >= 80000) {
                    $this->VISIBLE_SYMBOL();
                } else {
                    $this->IDENTIFIER();
                }
                break;
            case 'WAIT':
                $this->WAIT_SYMBOL();
                break;
            case 'WARNINGS':
                $this->WARNINGS_SYMBOL();
                break;
            case 'WEEK':
                $this->WEEK_SYMBOL();
                break;
            case 'WHEN':
                $this->WHEN_SYMBOL();
                break;
            case 'WEIGHT_STRING':
                $this->WEIGHT_STRING_SYMBOL();
                break;
            case 'WHERE':
                $this->WHERE_SYMBOL();
                break;
            case 'WHILE':
                $this->WHILE_SYMBOL();
                break;
            case 'WINDOW':
                if ($this->serverVersion >= 80000) {
                    $this->WINDOW_SYMBOL();
                } else {
                    $this->IDENTIFIER();
                }
                break;
            case 'WITH':
                $this->WITH_SYMBOL();
                break;
            case 'WITHOUT':
                if ($this->serverVersion >= 80000) {
                    $this->WITHOUT_SYMBOL();
                } else {
                    $this->IDENTIFIER();
                }
                break;
            case 'WORK':
                $this->WORK_SYMBOL();
                break;
            case 'WRAPPER':
                $this->WRAPPER_SYMBOL();
                break;
            case 'WRITE':
                $this->WRITE_SYMBOL();
                break;
            case 'XA':
                $this->XA_SYMBOL();
                break;
            case 'X509':
                $this->X509_SYMBOL();
                break;
            case 'XID':
                if ($this->serverVersion >= 50704) {
                    $this->XID_SYMBOL();
                } else {
                    $this->IDENTIFIER();
                }
                break;
            case 'XML':
                $this->XML_SYMBOL();
                break;
            case 'XOR':
                $this->XOR_SYMBOL();
                break;
            case 'YEAR':
                $this->YEAR_SYMBOL();
                break;
            case 'YEAR_MONTH':
                $this->YEAR_MONTH_SYMBOL();
                break;
            case 'ZEROFILL':
                $this->ZEROFILL_SYMBOL();
                break;
            case 'INT1':
                $this->INT1_SYMBOL();
                break;
            case 'INT2':
                $this->INT2_SYMBOL();
                break;
            case 'INT3':
                $this->INT3_SYMBOL();
                break;
            case 'INT4':
                $this->INT4_SYMBOL();
                break;
            case 'INT8':
                $this->INT8_SYMBOL();
                break;
            default:
                // Not a keyword, emit as identifier.
                $this->IDENTIFIER();
        }
    }

    protected function blockComment()
    {
        $this->consume(); // Consume the '/'.
        $this->consume(); // Consume the '*'.

        // If the next character is '!', it could be a version comment.
        if ($this->c === '!') {
            $this->consume(); // Consume the '!'.

            // If the next character is a digit, it's a version comment.
            if (safe_ctype_digit($this->c)) {
                // Consume all digits.
                while (safe_ctype_digit($this->c)) {
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
        if ($this->c === '0' && $this->n === 'x') {
            $this->HEX_NUMBER();
        } elseif ($this->c === '0' && $this->n === 'b') {
            $this->BIN_NUMBER();
        } elseif ($this->c === '.' && safe_ctype_digit($this->LA(2))) {
            $this->DECIMAL_NUMBER();
        } else {
            $this->INT_NUMBER();

            if ($this->c === '.') {
                $this->consume();

                if (safe_ctype_digit($this->c)) {
                    while (safe_ctype_digit($this->c)) {
                        $this->consume();
                    }

                    if ($this->c === 'e' || $this->c === 'E') {
                        $this->consume();
                        if ($this->c === '+' || $this->c === '-') {
                            $this->consume();
                        }
                        while (safe_ctype_digit($this->c)) {
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
                while (safe_ctype_digit($this->c)) {
                    $this->consume();
                }
                $this->setType(self::FLOAT_NUMBER);
            }
        }
    }

    protected function SINGLE_QUOTED_TEXT()
    {
        do {
            $this->consume(); // Consume the first single quote.
            while ($this->c !== null) {
                if ($this->c === '\\' && !$this->isSqlModeActive(MySQLLexer::NoBackslashEscapes)) {
                    // If it's an escape sequence, consume the backslash and the next character.
                    $this->consume();
                    $this->consume();
                } elseif ($this->c === "'") {
                    $this->consume(); // Consume the second single quote.
                    break;
                } else {
                    $this->consume();
                }
            }
        } while ($this->c === "'"); // Continue if there's another single quote.

        $this->setType(self::SINGLE_QUOTED_TEXT);
    }

    protected function DOUBLE_QUOTED_TEXT()
    {
        do {
            $this->consume(); // Consume the first double quote.
            while ($this->c !== null) {
                if ($this->c === '\\' && !$this->isSqlModeActive(MySQLLexer::NoBackslashEscapes)) {
                    // If it's an escape sequence, consume the backslash and the next character.
                    $this->consume();
                    $this->consume();
                } elseif ($this->c === '"') {
                    $this->consume(); // Consume the second double quote.
                    break;
                } else {
                    $this->consume();
                }
            }
        } while ($this->c === '"'); // Continue if there's another double quote.

        $this->setType(self::DOUBLE_QUOTED_TEXT);
    }

    protected function BACK_TICK_QUOTED_ID()
    {
        $this->consume(); // Consume the first back tick.
        while ($this->c !== null) {
            if ($this->c === '\\' && !$this->isSqlModeActive(MySQLLexer::NoBackslashEscapes)) {
                // If it's an escape sequence, consume the backslash and the next character.
                $this->consume();
                $this->consume();
            } elseif ($this->c === '`') {
                $this->consume(); // Consume the second back tick.
                break;
            } else {
                $this->consume();
            }
        }

        $this->setType(self::BACK_TICK_QUOTED_ID);
    }

    protected function HEX_NUMBER()
    {
        $this->consume(); // Consume the '0'.
        $this->consume(); // Consume the 'x'.
        while (ctype_xdigit($this->c)) {
            $this->consume();
        }
        $this->setType(self::HEX_NUMBER);
    }

    protected function BIN_NUMBER()
    {
        $this->consume(); // Consume the '0'.
        $this->consume(); // Consume the 'b'.
        while ($this->c === '0' || $this->c === '1') {
            $this->consume();
        }
        $this->setType(self::BIN_NUMBER);
    }

    protected function INT_NUMBER()
    {
        while (safe_ctype_digit($this->c)) {
            $this->consume();
        }
        $this->setType(self::DECIMAL_NUMBER);
    }

    protected function DECIMAL_NUMBER()
    {
        $this->consume(); // Consume the '.'.
        while (safe_ctype_digit($this->c)) {
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

        if ($this->isSqlModeActive(MySQLLexer::PipesAsConcat)) {
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

    protected function UNDERLINE_SYMBOL()
    {
        $this->consume();

        if (safe_ctype_alpha($this->LA(1))) {
            // If the next character is a letter, it's a charset.
            while (ctype_alnum($this->LA(1))) {
                $this->consume();
            }

            $this->setType($this->checkCharset($this->getText()));
        } else {
            $this->setType(self::UNDERLINE_SYMBOL);
        }
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
        while (safe_ctype_space($this->c)) {
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

        while (safe_ctype_space($this->c)) {
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

    protected function ACCESSIBLE_SYMBOL()
    {
        $this->setType(self::ACCESSIBLE_SYMBOL);
    }

    protected function ACCOUNT_SYMBOL()
    {
        $this->setType(self::ACCOUNT_SYMBOL);
    }

    protected function ACTION_SYMBOL()
    {
        $this->setType(self::ACTION_SYMBOL);
    }

    protected function ADD_SYMBOL()
    {
        $this->setType(self::ADD_SYMBOL);
    }

    protected function ADDDATE_SYMBOL()
    {
        $this->setType($this->determineFunction(self::ADDDATE_SYMBOL)); // MYSQL-FUNC
    }

    protected function AFTER_SYMBOL()
    {
        $this->setType(self::AFTER_SYMBOL);
    }

    protected function AGAINST_SYMBOL()
    {
        $this->setType(self::AGAINST_SYMBOL);
    }

    protected function AGGREGATE_SYMBOL()
    {
        $this->setType(self::AGGREGATE_SYMBOL);
    }

    protected function ALGORITHM_SYMBOL()
    {
        $this->setType(self::ALGORITHM_SYMBOL);
    }

    protected function ALL_SYMBOL()
    {
        $this->setType(self::ALL_SYMBOL);
    }

    protected function ALTER_SYMBOL()
    {
        $this->setType(self::ALTER_SYMBOL);
    }

    protected function ALWAYS_SYMBOL()
    {
        $this->setType(self::ALWAYS_SYMBOL);
    }

    protected function ANALYSE_SYMBOL()
    {
        $this->setType(self::ANALYSE_SYMBOL);
    }

    protected function ANALYZE_SYMBOL()
    {
        $this->setType(self::ANALYZE_SYMBOL);
    }

    protected function AND_SYMBOL()
    {
        $this->setType(self::AND_SYMBOL);
    }

    protected function ANY_SYMBOL()
    {
        $this->setType(self::ANY_SYMBOL);
    }

    protected function AS_SYMBOL()
    {
        $this->setType(self::AS_SYMBOL);
    }

    protected function ASC_SYMBOL()
    {
        $this->setType(self::ASC_SYMBOL);
    }

    protected function ASCII_SYMBOL()
    {
        $this->setType(self::ASCII_SYMBOL);
    }

    protected function ASENSITIVE_SYMBOL()
    {
        $this->setType(self::ASENSITIVE_SYMBOL);
    }

    protected function AT_SYMBOL()
    {
        $this->setType(self::AT_SYMBOL);
    }

    protected function AUTHORS_SYMBOL()
    {
        $this->setType(self::AUTHORS_SYMBOL);
    }

    protected function AUTOEXTEND_SIZE_SYMBOL()
    {
        $this->setType(self::AUTOEXTEND_SIZE_SYMBOL);
    }

    protected function AUTO_INCREMENT_SYMBOL()
    {
        $this->setType(self::AUTO_INCREMENT_SYMBOL);
    }

    protected function AVG_SYMBOL()
    {
        $this->setType(self::AVG_SYMBOL);
    }

    protected function AVG_ROW_LENGTH_SYMBOL()
    {
        $this->setType(self::AVG_ROW_LENGTH_SYMBOL);
    }

    protected function BACKUP_SYMBOL()
    {
        $this->setType(self::BACKUP_SYMBOL);
    }

    protected function BEFORE_SYMBOL()
    {
        $this->setType(self::BEFORE_SYMBOL);
    }

    protected function BEGIN_SYMBOL()
    {
        $this->setType(self::BEGIN_SYMBOL);
    }

    protected function BETWEEN_SYMBOL()
    {
        $this->setType(self::BETWEEN_SYMBOL);
    }

    protected function BIGINT_SYMBOL()
    {
        $this->setType(self::BIGINT_SYMBOL);
    }

    protected function BINARY_SYMBOL()
    {
        $this->setType(self::BINARY_SYMBOL);
    }

    protected function BINLOG_SYMBOL()
    {
        $this->setType(self::BINLOG_SYMBOL);
    }

    protected function BIT_AND_SYMBOL()
    {
        $this->setType($this->determineFunction(self::BIT_AND_SYMBOL)); // MYSQL-FUNC
    }

    protected function BIT_OR_SYMBOL()
    {
        $this->setType($this->determineFunction(self::BIT_OR_SYMBOL)); // MYSQL-FUNC
    }

    protected function BIT_SYMBOL()
    {
        $this->setType(self::BIT_SYMBOL);
    }

    protected function BIT_XOR_SYMBOL()
    {
        $this->setType($this->determineFunction(self::BIT_XOR_SYMBOL)); // MYSQL-FUNC
    }

    protected function BLOB_SYMBOL()
    {
        $this->setType(self::BLOB_SYMBOL);
    }

    protected function BLOCK_SYMBOL()
    {
        $this->setType(self::BLOCK_SYMBOL);
    }

    protected function BOOLEAN_SYMBOL()
    {
        $this->setType(self::BOOLEAN_SYMBOL);
    }

    protected function BOOL_SYMBOL()
    {
        $this->setType(self::BOOL_SYMBOL);
    }

    protected function BOTH_SYMBOL()
    {
        $this->setType(self::BOTH_SYMBOL);
    }

    protected function BTREE_SYMBOL()
    {
        $this->setType(self::BTREE_SYMBOL);
    }

    protected function BUCKETS_SYMBOL()
    {
        $this->setType(self::BUCKETS_SYMBOL);
    }

    protected function BY_SYMBOL()
    {
        $this->setType(self::BY_SYMBOL);
    }

    protected function BYTE_SYMBOL()
    {
        $this->setType(self::BYTE_SYMBOL);
    }

    protected function CACHE_SYMBOL()
    {
        $this->setType(self::CACHE_SYMBOL);
    }

    protected function CALL_SYMBOL()
    {
        $this->setType(self::CALL_SYMBOL);
    }

    protected function CASCADE_SYMBOL()
    {
        $this->setType(self::CASCADE_SYMBOL);
    }

    protected function CASCADED_SYMBOL()
    {
        $this->setType(self::CASCADED_SYMBOL);
    }

    protected function CASE_SYMBOL()
    {
        $this->setType(self::CASE_SYMBOL);
    }

    protected function CAST_SYMBOL()
    {
        $this->setType($this->determineFunction(self::CAST_SYMBOL)); // SQL-2003-R
    }

    protected function CATALOG_NAME_SYMBOL()
    {
        $this->setType(self::CATALOG_NAME_SYMBOL);
    }

    protected function CHAIN_SYMBOL()
    {
        $this->setType(self::CHAIN_SYMBOL);
    }

    protected function CHANGE_SYMBOL()
    {
        $this->setType(self::CHANGE_SYMBOL);
    }

    protected function CHANGED_SYMBOL()
    {
        $this->setType(self::CHANGED_SYMBOL);
    }

    protected function CHANNEL_SYMBOL()
    {
        $this->setType(self::CHANNEL_SYMBOL);
    }

    protected function CHAR_SYMBOL()
    {
        $this->setType(self::CHAR_SYMBOL);
    }

    protected function CHARSET_SYMBOL()
    {
        $this->setType(self::CHARSET_SYMBOL);
    }

    protected function CHECK_SYMBOL()
    {
        $this->setType(self::CHECK_SYMBOL);
    }

    protected function CHECKSUM_SYMBOL()
    {
        $this->setType(self::CHECKSUM_SYMBOL);
    }

    protected function CIPHER_SYMBOL()
    {
        $this->setType(self::CIPHER_SYMBOL);
    }

    protected function CLASS_ORIGIN_SYMBOL()
    {
        $this->setType(self::CLASS_ORIGIN_SYMBOL);
    }

    protected function CLIENT_SYMBOL()
    {
        $this->setType(self::CLIENT_SYMBOL);
    }

    protected function CLONE_SYMBOL()
    {
        $this->setType(self::CLONE_SYMBOL);
    }

    protected function CLOSE_SYMBOL()
    {
        $this->setType(self::CLOSE_SYMBOL);
    }

    protected function COALESCE_SYMBOL()
    {
        $this->setType(self::COALESCE_SYMBOL);
    }

    protected function CODE_SYMBOL()
    {
        $this->setType(self::CODE_SYMBOL);
    }

    protected function COLLATE_SYMBOL()
    {
        $this->setType(self::COLLATE_SYMBOL);
    }

    protected function COLLATION_SYMBOL()
    {
        $this->setType(self::COLLATION_SYMBOL);
    }

    protected function COLUMN_FORMAT_SYMBOL()
    {
        $this->setType(self::COLUMN_FORMAT_SYMBOL);
    }

    protected function COLUMN_NAME_SYMBOL()
    {
        $this->setType(self::COLUMN_NAME_SYMBOL);
    }

    protected function COLUMNS_SYMBOL()
    {
        $this->setType(self::COLUMNS_SYMBOL);
    }

    protected function COLUMN_SYMBOL()
    {
        $this->setType(self::COLUMN_SYMBOL);
    }

    protected function COMMENT_SYMBOL()
    {
        $this->setType(self::COMMENT_SYMBOL);
    }

    protected function COMMIT_SYMBOL()
    {
        $this->setType(self::COMMIT_SYMBOL);
    }

    protected function COMMITTED_SYMBOL()
    {
        $this->setType(self::COMMITTED_SYMBOL);
    }

    protected function COMPACT_SYMBOL()
    {
        $this->setType(self::COMPACT_SYMBOL);
    }

    protected function COMPLETION_SYMBOL()
    {
        $this->setType(self::COMPLETION_SYMBOL);
    }

    protected function COMPONENT_SYMBOL()
    {
        $this->setType(self::COMPONENT_SYMBOL);
    }

    protected function COMPRESSED_SYMBOL()
    {
        $this->setType(self::COMPRESSED_SYMBOL);
    }

    protected function COMPRESSION_SYMBOL()
    {
        $this->setType(self::COMPRESSION_SYMBOL);
    }

    protected function CONCURRENT_SYMBOL()
    {
        $this->setType(self::CONCURRENT_SYMBOL);
    }

    protected function CONDITION_SYMBOL()
    {
        $this->setType(self::CONDITION_SYMBOL);
    }

    protected function CONNECTION_SYMBOL()
    {
        $this->setType(self::CONNECTION_SYMBOL);
    }

    protected function CONSISTENT_SYMBOL()
    {
        $this->setType(self::CONSISTENT_SYMBOL);
    }

    protected function CONSTRAINT_SYMBOL()
    {
        $this->setType(self::CONSTRAINT_SYMBOL);
    }

    protected function CONSTRAINTS_SYMBOL()
    {
        $this->setType(self::CONSTRAINTS_SYMBOL);
    }

    protected function CONSTRAINT_CATALOG_SYMBOL()
    {
        $this->setType(self::CONSTRAINT_CATALOG_SYMBOL);
    }

    protected function CONSTRAINT_NAME_SYMBOL()
    {
        $this->setType(self::CONSTRAINT_NAME_SYMBOL);
    }

    protected function CONSTRAINT_SCHEMA_SYMBOL()
    {
        $this->setType(self::CONSTRAINT_SCHEMA_SYMBOL);
    }

    protected function CONTAINS_SYMBOL()
    {
        $this->setType(self::CONTAINS_SYMBOL);
    }

    protected function CONTEXT_SYMBOL()
    {
        $this->setType(self::CONTEXT_SYMBOL);
    }

    protected function CONTINUE_SYMBOL()
    {
        $this->setType(self::CONTINUE_SYMBOL);
    }

    protected function CONTRIBUTORS_SYMBOL()
    {
        $this->setType(self::CONTRIBUTORS_SYMBOL);
    }

    protected function CONVERT_SYMBOL()
    {
        $this->setType(self::CONVERT_SYMBOL);
    }

    protected function COUNT_SYMBOL()
    {
        $this->setType($this->determineFunction(self::COUNT_SYMBOL)); // SQL-2003-N
    }

    protected function CPU_SYMBOL()
    {
        $this->setType(self::CPU_SYMBOL);
    }

    protected function CREATE_SYMBOL()
    {
        $this->setType(self::CREATE_SYMBOL);
    }

    protected function CROSS_SYMBOL()
    {
        $this->setType(self::CROSS_SYMBOL);
    }

    protected function CUBE_SYMBOL()
    {
        $this->setType(self::CUBE_SYMBOL);
    }

    protected function CURDATE_SYMBOL()
    {
        $this->setType($this->determineFunction(self::CURDATE_SYMBOL)); // MYSQL-FUNC
    }

    protected function CURRENT_SYMBOL()
    {
        $this->setType(self::CURRENT_SYMBOL);
    }

    protected function CURRENT_DATE_SYMBOL()
    {
        $this->setType($this->determineFunction(self::CURDATE_SYMBOL)); // Synonym, MYSQL-FUNC
    }

    protected function CURRENT_TIME_SYMBOL()
    {
        $this->setType($this->determineFunction(self::CURTIME_SYMBOL)); // Synonym, MYSQL-FUNC
    }

    protected function CURRENT_TIMESTAMP_SYMBOL()
    {
        $this->setType(self::NOW_SYMBOL); // Synonym
    }

    protected function CURRENT_USER_SYMBOL()
    {
        $this->setType(self::CURRENT_USER_SYMBOL);
    }

    protected function CURSOR_SYMBOL()
    {
        $this->setType(self::CURSOR_SYMBOL);
    }

    protected function CURSOR_NAME_SYMBOL()
    {
        $this->setType(self::CURSOR_NAME_SYMBOL);
    }

    protected function CURTIME_SYMBOL()
    {
        $this->setType($this->determineFunction(self::CURTIME_SYMBOL)); // MYSQL-FUNC
    }

    protected function DATABASE_SYMBOL()
    {
        $this->setType(self::DATABASE_SYMBOL);
    }

    protected function DATABASES_SYMBOL()
    {
        $this->setType(self::DATABASES_SYMBOL);
    }

    protected function DATAFILE_SYMBOL()
    {
        $this->setType(self::DATAFILE_SYMBOL);
    }

    protected function DATA_SYMBOL()
    {
        $this->setType(self::DATA_SYMBOL);
    }

    protected function DATETIME_SYMBOL()
    {
        $this->setType(self::DATETIME_SYMBOL);
    }

    protected function DATE_ADD_SYMBOL()
    {
        $this->setType($this->determineFunction(self::DATE_ADD_SYMBOL));
    }

    protected function DATE_SUB_SYMBOL()
    {
        $this->setType($this->determineFunction(self::DATE_SUB_SYMBOL));
    }

    protected function DATE_SYMBOL()
    {
        $this->setType(self::DATE_SYMBOL);
    }

    protected function DAY_HOUR_SYMBOL()
    {
        $this->setType(self::DAY_HOUR_SYMBOL);
    }

    protected function DAY_MICROSECOND_SYMBOL()
    {
        $this->setType(self::DAY_MICROSECOND_SYMBOL);
    }

    protected function DAY_MINUTE_SYMBOL()
    {
        $this->setType(self::DAY_MINUTE_SYMBOL);
    }

    protected function DAY_SECOND_SYMBOL()
    {
        $this->setType(self::DAY_SECOND_SYMBOL);
    }

    protected function DAY_SYMBOL()
    {
        $this->setType(self::DAY_SYMBOL);
    }

    protected function DAYOFMONTH_SYMBOL()
    {
        $this->setType(self::DAY_SYMBOL); // Synonym
    }

    protected function DEALLOCATE_SYMBOL()
    {
        $this->setType(self::DEALLOCATE_SYMBOL);
    }

    protected function DECIMAL_SYMBOL()
    {
        $this->setType(self::DECIMAL_SYMBOL);
    }

    protected function DEC_SYMBOL()
    {
        $this->setType(self::DECIMAL_SYMBOL); // Synonym
    }

    protected function DECLARE_SYMBOL()
    {
        $this->setType(self::DECLARE_SYMBOL);
    }

    protected function DEFAULT_SYMBOL()
    {
        $this->setType(self::DEFAULT_SYMBOL);
    }

    protected function DEFAULT_AUTH_SYMBOL()
    {
        $this->setType(self::DEFAULT_AUTH_SYMBOL);
    }

    protected function DEFINER_SYMBOL()
    {
        $this->setType(self::DEFINER_SYMBOL);
    }

    protected function DEFINITION_SYMBOL()
    {
        $this->setType(self::DEFINITION_SYMBOL);
    }

    protected function DELAYED_SYMBOL()
    {
        $this->setType(self::DELAYED_SYMBOL);
    }

    protected function DELAY_KEY_WRITE_SYMBOL()
    {
        $this->setType(self::DELAY_KEY_WRITE_SYMBOL);
    }

    protected function DELETE_SYMBOL()
    {
        $this->setType(self::DELETE_SYMBOL);
    }

    protected function DENSE_RANK_SYMBOL()
    {
        $this->setType(self::DENSE_RANK_SYMBOL);
    }

    protected function DESC_SYMBOL()
    {
        $this->setType(self::DESC_SYMBOL);
    }

    protected function DESCRIBE_SYMBOL()
    {
        $this->setType(self::DESCRIBE_SYMBOL);
    }

    protected function DESCRIPTION_SYMBOL()
    {
        $this->setType(self::DESCRIPTION_SYMBOL);
    }

    protected function DES_KEY_FILE_SYMBOL()
    {
        $this->setType(self::DES_KEY_FILE_SYMBOL);
    }

    protected function DETERMINISTIC_SYMBOL()
    {
        $this->setType(self::DETERMINISTIC_SYMBOL);
    }

    protected function DIAGNOSTICS_SYMBOL()
    {
        $this->setType(self::DIAGNOSTICS_SYMBOL);
    }

    protected function DIRECTORY_SYMBOL()
    {
        $this->setType(self::DIRECTORY_SYMBOL);
    }

    protected function DISABLE_SYMBOL()
    {
        $this->setType(self::DISABLE_SYMBOL);
    }

    protected function DISCARD_SYMBOL()
    {
        $this->setType(self::DISCARD_SYMBOL);
    }

    protected function DISK_SYMBOL()
    {
        $this->setType(self::DISK_SYMBOL);
    }

    protected function DISTINCT_SYMBOL()
    {
        $this->setType(self::DISTINCT_SYMBOL);
    }

    protected function DISTINCTROW_SYMBOL()
    {
        $this->setType(self::DISTINCT_SYMBOL); // Synonym
    }

    protected function DIV_SYMBOL()
    {
        $this->setType(self::DIV_SYMBOL);
    }

    protected function DOUBLE_SYMBOL()
    {
        $this->setType(self::DOUBLE_SYMBOL);
    }

    protected function DO_SYMBOL()
    {
        $this->setType(self::DO_SYMBOL);
    }

    protected function DROP_SYMBOL()
    {
        $this->setType(self::DROP_SYMBOL);
    }

    protected function DUAL_SYMBOL()
    {
        $this->setType(self::DUAL_SYMBOL);
    }

    protected function DUMPFILE_SYMBOL()
    {
        $this->setType(self::DUMPFILE_SYMBOL);
    }

    protected function DUPLICATE_SYMBOL()
    {
        $this->setType(self::DUPLICATE_SYMBOL);
    }

    protected function DYNAMIC_SYMBOL()
    {
        $this->setType(self::DYNAMIC_SYMBOL);
    }

    protected function EACH_SYMBOL()
    {
        $this->setType(self::EACH_SYMBOL);
    }

    protected function ELSE_SYMBOL()
    {
        $this->setType(self::ELSE_SYMBOL);
    }

    protected function ELSEIF_SYMBOL()
    {
        $this->setType(self::ELSEIF_SYMBOL);
    }

    protected function EMPTY_SYMBOL()
    {
        $this->setType(self::EMPTY_SYMBOL);
    }

    protected function ENABLE_SYMBOL()
    {
        $this->setType(self::ENABLE_SYMBOL);
    }

    protected function ENCLOSED_SYMBOL()
    {
        $this->setType(self::ENCLOSED_SYMBOL);
    }

    protected function ENCRYPTION_SYMBOL()
    {
        $this->setType(self::ENCRYPTION_SYMBOL);
    }

    protected function END_SYMBOL()
    {
        $this->setType(self::END_SYMBOL);
    }

    protected function ENDS_SYMBOL()
    {
        $this->setType(self::ENDS_SYMBOL);
    }

    protected function ENFORCED_SYMBOL()
    {
        $this->setType(self::ENFORCED_SYMBOL);
    }

    protected function ENGINE_SYMBOL()
    {
        $this->setType(self::ENGINE_SYMBOL);
    }

    protected function ENGINES_SYMBOL()
    {
        $this->setType(self::ENGINES_SYMBOL);
    }

    protected function ENUM_SYMBOL()
    {
        $this->setType(self::ENUM_SYMBOL);
    }

    protected function ERROR_SYMBOL()
    {
        $this->setType(self::ERROR_SYMBOL);
    }

    protected function ERRORS_SYMBOL()
    {
        $this->setType(self::ERRORS_SYMBOL);
    }

    protected function ESCAPED_SYMBOL()
    {
        $this->setType(self::ESCAPED_SYMBOL);
    }

    protected function ESCAPE_SYMBOL()
    {
        $this->setType(self::ESCAPE_SYMBOL);
    }

    protected function EVENT_SYMBOL()
    {
        $this->setType(self::EVENT_SYMBOL);
    }

    protected function EVENTS_SYMBOL()
    {
        $this->setType(self::EVENTS_SYMBOL);
    }

    protected function EVERY_SYMBOL()
    {
        $this->setType(self::EVERY_SYMBOL);
    }

    protected function EXCHANGE_SYMBOL()
    {
        $this->setType(self::EXCHANGE_SYMBOL);
    }

    protected function EXCEPT_SYMBOL()
    {
        $this->setType(self::EXCEPT_SYMBOL);
    }

    protected function EXECUTE_SYMBOL()
    {
        $this->setType(self::EXECUTE_SYMBOL);
    }

    protected function EXISTS_SYMBOL()
    {
        $this->setType(self::EXISTS_SYMBOL);
    }

    protected function EXIT_SYMBOL()
    {
        $this->setType(self::EXIT_SYMBOL);
    }

    protected function EXPANSION_SYMBOL()
    {
        $this->setType(self::EXPANSION_SYMBOL);
    }

    protected function EXPIRE_SYMBOL()
    {
        $this->setType(self::EXPIRE_SYMBOL);
    }

    protected function EXPLAIN_SYMBOL()
    {
        $this->setType(self::EXPLAIN_SYMBOL);
    }

    protected function EXPORT_SYMBOL()
    {
        $this->setType(self::EXPORT_SYMBOL);
    }

    protected function EXTENDED_SYMBOL()
    {
        $this->setType(self::EXTENDED_SYMBOL);
    }

    protected function EXTENT_SIZE_SYMBOL()
    {
        $this->setType(self::EXTENT_SIZE_SYMBOL);
    }

    protected function EXTRACT_SYMBOL()
    {
        $this->setType($this->determineFunction(self::EXTRACT_SYMBOL)); // SQL-2003-N
    }

    protected function FALSE_SYMBOL()
    {
        $this->setType(self::FALSE_SYMBOL);
    }

    protected function FAILED_LOGIN_ATTEMPTS_SYMBOL()
    {
        $this->setType(self::FAILED_LOGIN_ATTEMPTS_SYMBOL);
    }

    protected function FAST_SYMBOL()
    {
        $this->setType(self::FAST_SYMBOL);
    }

    protected function FAULTS_SYMBOL()
    {
        $this->setType(self::FAULTS_SYMBOL);
    }

    protected function FETCH_SYMBOL()
    {
        $this->setType(self::FETCH_SYMBOL);
    }

    protected function FIELDS_SYMBOL()
    {
        $this->setType(self::COLUMNS_SYMBOL); // Synonym
    }

    protected function FILE_BLOCK_SIZE_SYMBOL()
    {
        $this->setType(self::FILE_BLOCK_SIZE_SYMBOL);
    }

    protected function FILE_SYMBOL()
    {
        $this->setType(self::FILE_SYMBOL);
    }

    protected function FILTER_SYMBOL()
    {
        $this->setType(self::FILTER_SYMBOL);
    }

    protected function FIRST_SYMBOL()
    {
        $this->setType(self::FIRST_SYMBOL);
    }

    protected function FIRST_VALUE_SYMBOL()
    {
        $this->setType(self::FIRST_VALUE_SYMBOL);
    }

    protected function FIXED_SYMBOL()
    {
        $this->setType(self::FIXED_SYMBOL);
    }

    protected function FLOAT4_SYMBOL()
    {
        $this->setType(self::FLOAT_SYMBOL); // Synonym
    }

    protected function FLOAT8_SYMBOL()
    {
        $this->setType(self::DOUBLE_SYMBOL); // Synonym
    }

    protected function FLOAT_SYMBOL()
    {
        $this->setType(self::FLOAT_SYMBOL);
    }

    protected function FLUSH_SYMBOL()
    {
        $this->setType(self::FLUSH_SYMBOL);
    }

    protected function FOLLOWS_SYMBOL()
    {
        $this->setType(self::FOLLOWS_SYMBOL);
    }

    protected function FOLLOWING_SYMBOL()
    {
        $this->setType(self::FOLLOWING_SYMBOL);
    }

    protected function FORCE_SYMBOL()
    {
        $this->setType(self::FORCE_SYMBOL);
    }

    protected function FOR_SYMBOL()
    {
        $this->setType(self::FOR_SYMBOL);
    }

    protected function FOREIGN_SYMBOL()
    {
        $this->setType(self::FOREIGN_SYMBOL);
    }

    protected function FORMAT_SYMBOL()
    {
        $this->setType(self::FORMAT_SYMBOL);
    }

    protected function FOUND_SYMBOL()
    {
        $this->setType(self::FOUND_SYMBOL);
    }

    protected function FROM_SYMBOL()
    {
        $this->setType(self::FROM_SYMBOL);
    }

    protected function FULLTEXT_SYMBOL()
    {
        $this->setType(self::FULLTEXT_SYMBOL);
    }

    protected function FULL_SYMBOL()
    {
        $this->setType(self::FULL_SYMBOL);
    }

    protected function FUNCTION_SYMBOL()
    {
        $this->setType(self::FUNCTION_SYMBOL);
    }

    protected function GENERATED_SYMBOL()
    {
        $this->setType(self::GENERATED_SYMBOL);
    }

    protected function GENERAL_SYMBOL()
    {
        $this->setType(self::GENERAL_SYMBOL);
    }

    protected function GEOMETRYCOLLECTION_SYMBOL()
    {
        $this->setType(self::GEOMETRYCOLLECTION_SYMBOL);
    }

    protected function GEOMETRY_SYMBOL()
    {
        $this->setType(self::GEOMETRY_SYMBOL);
    }

    protected function GET_SYMBOL()
    {
        $this->setType(self::GET_SYMBOL);
    }

    protected function GET_FORMAT_SYMBOL()
    {
        $this->setType(self::GET_FORMAT_SYMBOL);
    }

    protected function GET_MASTER_PUBLIC_KEY_SYMBOL()
    {
        $this->setType(self::GET_MASTER_PUBLIC_KEY_SYMBOL);
    }

    protected function GLOBAL_SYMBOL()
    {
        $this->setType(self::GLOBAL_SYMBOL);
    }

    protected function GRANT_SYMBOL()
    {
        $this->setType(self::GRANT_SYMBOL);
    }

    protected function GRANTS_SYMBOL()
    {
        $this->setType(self::GRANTS_SYMBOL);
    }

    protected function GROUP_CONCAT_SYMBOL()
    {
        $this->setType($this->determineFunction(self::GROUP_CONCAT_SYMBOL));
    }

    protected function GROUP_SYMBOL()
    {
        $this->setType(self::GROUP_SYMBOL);
    }

    protected function GROUP_REPLICATION_SYMBOL()
    {
        $this->setType(self::GROUP_REPLICATION_SYMBOL);
    }

    protected function GROUPING_SYMBOL()
    {
        $this->setType(self::GROUPING_SYMBOL);
    }

    protected function GROUPS_SYMBOL()
    {
        $this->setType(self::GROUPS_SYMBOL);
    }

    protected function HANDLER_SYMBOL()
    {
        $this->setType(self::HANDLER_SYMBOL);
    }

    protected function HASH_SYMBOL()
    {
        $this->setType(self::HASH_SYMBOL);
    }

    protected function HAVING_SYMBOL()
    {
        $this->setType(self::HAVING_SYMBOL);
    }

    protected function HELP_SYMBOL()
    {
        $this->setType(self::HELP_SYMBOL);
    }

    protected function HIGH_PRIORITY_SYMBOL()
    {
        $this->setType(self::HIGH_PRIORITY_SYMBOL);
    }

    protected function HISTOGRAM_SYMBOL()
    {
        $this->setType(self::HISTOGRAM_SYMBOL);
    }

    protected function HISTORY_SYMBOL()
    {
        $this->setType(self::HISTORY_SYMBOL);
    }

    protected function HOSTS_SYMBOL()
    {
        $this->setType(self::HOSTS_SYMBOL);
    }

    protected function HOST_SYMBOL()
    {
        $this->setType(self::HOST_SYMBOL);
    }

    protected function HOUR_SYMBOL()
    {
        $this->setType(self::HOUR_SYMBOL);
    }

    protected function HOUR_MICROSECOND_SYMBOL()
    {
        $this->setType(self::HOUR_MICROSECOND_SYMBOL);
    }

    protected function HOUR_MINUTE_SYMBOL()
    {
        $this->setType(self::HOUR_MINUTE_SYMBOL);
    }

    protected function HOUR_SECOND_SYMBOL()
    {
        $this->setType(self::HOUR_SECOND_SYMBOL);
    }

    protected function IDENTIFIED_SYMBOL()
    {
        $this->setType(self::IDENTIFIED_SYMBOL);
    }

    protected function IF_SYMBOL()
    {
        $this->setType(self::IF_SYMBOL);
    }

    protected function IGNORE_SYMBOL()
    {
        $this->setType(self::IGNORE_SYMBOL);
    }

    protected function IGNORE_SERVER_IDS_SYMBOL()
    {
        $this->setType(self::IGNORE_SERVER_IDS_SYMBOL);
    }

    protected function IMPORT_SYMBOL()
    {
        $this->setType(self::IMPORT_SYMBOL);
    }

    protected function IN_SYMBOL()
    {
        $this->setType(self::IN_SYMBOL);
    }

    protected function INDEX_SYMBOL()
    {
        $this->setType(self::INDEX_SYMBOL);
    }

    protected function INDEXES_SYMBOL()
    {
        $this->setType(self::INDEXES_SYMBOL);
    }

    protected function INFILE_SYMBOL()
    {
        $this->setType(self::INFILE_SYMBOL);
    }

    protected function INITIAL_SIZE_SYMBOL()
    {
        $this->setType(self::INITIAL_SIZE_SYMBOL);
    }

    protected function INNER_SYMBOL()
    {
        $this->setType(self::INNER_SYMBOL);
    }

    protected function INOUT_SYMBOL()
    {
        $this->setType(self::INOUT_SYMBOL);
    }

    protected function INSENSITIVE_SYMBOL()
    {
        $this->setType(self::INSENSITIVE_SYMBOL);
    }

    protected function INSERT_SYMBOL()
    {
        $this->setType(self::INSERT_SYMBOL);
    }

    protected function INSERT_METHOD_SYMBOL()
    {
        $this->setType(self::INSERT_METHOD_SYMBOL);
    }

    protected function INSTANCE_SYMBOL()
    {
        $this->setType(self::INSTANCE_SYMBOL);
    }

    protected function INSTALL_SYMBOL()
    {
        $this->setType(self::INSTALL_SYMBOL);
    }

    protected function INT_SYMBOL()
    {
        $this->setType(self::INT_SYMBOL);
    }

    protected function INTEGER_SYMBOL()
    {
        $this->setType(self::INT_SYMBOL); // Synonym
    }

    protected function INTERVAL_SYMBOL()
    {
        $this->setType(self::INTERVAL_SYMBOL);
    }

    protected function INTO_SYMBOL()
    {
        $this->setType(self::INTO_SYMBOL);
    }

    protected function INVISIBLE_SYMBOL()
    {
        $this->setType(self::INVISIBLE_SYMBOL);
    }

    protected function INVOKER_SYMBOL()
    {
        $this->setType(self::INVOKER_SYMBOL);
    }

    protected function IO_SYMBOL()
    {
        $this->setType(self::IO_SYMBOL);
    }

    protected function IPC_SYMBOL()
    {
        $this->setType(self::IPC_SYMBOL);
    }

    protected function IS_SYMBOL()
    {
        $this->setType(self::IS_SYMBOL);
    }

    protected function ISOLATION_SYMBOL()
    {
        $this->setType(self::ISOLATION_SYMBOL);
    }

    protected function ISSUER_SYMBOL()
    {
        $this->setType(self::ISSUER_SYMBOL);
    }

    protected function ITERATE_SYMBOL()
    {
        $this->setType(self::ITERATE_SYMBOL);
    }

    protected function JOIN_SYMBOL()
    {
        $this->setType(self::JOIN_SYMBOL);
    }

    protected function JSON_SYMBOL()
    {
        $this->setType(self::JSON_SYMBOL);
    }

    protected function JSON_TABLE_SYMBOL()
    {
        $this->setType(self::JSON_TABLE_SYMBOL);
    }

    protected function JSON_ARRAYAGG_SYMBOL()
    {
        $this->setType(self::JSON_ARRAYAGG_SYMBOL);
    }

    protected function JSON_OBJECTAGG_SYMBOL()
    {
        $this->setType(self::JSON_OBJECTAGG_SYMBOL);
    }

    protected function KEYS_SYMBOL()
    {
        $this->setType(self::KEYS_SYMBOL);
    }

    protected function KEY_SYMBOL()
    {
        $this->setType(self::KEY_SYMBOL);
    }

    protected function KEY_BLOCK_SIZE_SYMBOL()
    {
        $this->setType(self::KEY_BLOCK_SIZE_SYMBOL);
    }

    protected function KILL_SYMBOL()
    {
        $this->setType(self::KILL_SYMBOL);
    }

    protected function LAG_SYMBOL()
    {
        $this->setType(self::LAG_SYMBOL);
    }

    protected function LANGUAGE_SYMBOL()
    {
        $this->setType(self::LANGUAGE_SYMBOL);
    }

    protected function LAST_SYMBOL()
    {
        $this->setType(self::LAST_SYMBOL);
    }

    protected function LAST_VALUE_SYMBOL()
    {
        $this->setType(self::LAST_VALUE_SYMBOL);
    }

    protected function LATERAL_SYMBOL()
    {
        $this->setType(self::LATERAL_SYMBOL);
    }

    protected function LEAD_SYMBOL()
    {
        $this->setType(self::LEAD_SYMBOL);
    }

    protected function LEADING_SYMBOL()
    {
        $this->setType(self::LEADING_SYMBOL);
    }

    protected function LEAVE_SYMBOL()
    {
        $this->setType(self::LEAVE_SYMBOL);
    }

    protected function LEAVES_SYMBOL()
    {
        $this->setType(self::LEAVES_SYMBOL);
    }

    protected function LEFT_SYMBOL()
    {
        $this->setType(self::LEFT_SYMBOL);
    }

    protected function LESS_SYMBOL()
    {
        $this->setType(self::LESS_SYMBOL);
    }

    protected function LEVEL_SYMBOL()
    {
        $this->setType(self::LEVEL_SYMBOL);
    }

    protected function LIKE_SYMBOL()
    {
        $this->setType(self::LIKE_SYMBOL);
    }

    protected function LIMIT_SYMBOL()
    {
        $this->setType(self::LIMIT_SYMBOL);
    }

    protected function LINEAR_SYMBOL()
    {
        $this->setType(self::LINEAR_SYMBOL);
    }

    protected function LINES_SYMBOL()
    {
        $this->setType(self::LINES_SYMBOL);
    }

    protected function LINESTRING_SYMBOL()
    {
        $this->setType(self::LINESTRING_SYMBOL);
    }

    protected function LIST_SYMBOL()
    {
        $this->setType(self::LIST_SYMBOL);
    }

    protected function LOAD_SYMBOL()
    {
        $this->setType(self::LOAD_SYMBOL);
    }

    protected function LOCALTIME_SYMBOL()
    {
        $this->setType(self::NOW_SYMBOL); // Synonym
    }

    protected function LOCALTIMESTAMP_SYMBOL()
    {
        $this->setType(self::NOW_SYMBOL); // Synonym
    }

    protected function LOCAL_SYMBOL()
    {
        $this->setType(self::LOCAL_SYMBOL);
    }

    protected function LOCATOR_SYMBOL()
    {
        $this->setType(self::LOCATOR_SYMBOL);
    }

    protected function LOCK_SYMBOL()
    {
        $this->setType(self::LOCK_SYMBOL);
    }

    protected function LOCKS_SYMBOL()
    {
        $this->setType(self::LOCKS_SYMBOL);
    }

    protected function LOGFILE_SYMBOL()
    {
        $this->setType(self::LOGFILE_SYMBOL);
    }

    protected function LOGS_SYMBOL()
    {
        $this->setType(self::LOGS_SYMBOL);
    }

    protected function LONGBLOB_SYMBOL()
    {
        $this->setType(self::LONGBLOB_SYMBOL);
    }

    protected function LONGTEXT_SYMBOL()
    {
        $this->setType(self::LONGTEXT_SYMBOL);
    }

    protected function LONG_SYMBOL()
    {
        $this->setType(self::LONG_SYMBOL);
    }

    protected function LOOP_SYMBOL()
    {
        $this->setType(self::LOOP_SYMBOL);
    }

    protected function LOW_PRIORITY_SYMBOL()
    {
        $this->setType(self::LOW_PRIORITY_SYMBOL);
    }

    protected function MASTER_SYMBOL()
    {
        $this->setType(self::MASTER_SYMBOL);
    }

    protected function MASTER_AUTO_POSITION_SYMBOL()
    {
        $this->setType(self::MASTER_AUTO_POSITION_SYMBOL);
    }

    protected function MASTER_BIND_SYMBOL()
    {
        $this->setType(self::MASTER_BIND_SYMBOL);
    }

    protected function MASTER_COMPRESSION_ALGORITHM_SYMBOL()
    {
        $this->setType(self::MASTER_COMPRESSION_ALGORITHM_SYMBOL);
    }

    protected function MASTER_CONNECT_RETRY_SYMBOL()
    {
        $this->setType(self::MASTER_CONNECT_RETRY_SYMBOL);
    }

    protected function MASTER_DELAY_SYMBOL()
    {
        $this->setType(self::MASTER_DELAY_SYMBOL);
    }

    protected function MASTER_HEARTBEAT_PERIOD_SYMBOL()
    {
        $this->setType(self::MASTER_HEARTBEAT_PERIOD_SYMBOL);
    }

    protected function MASTER_HOST_SYMBOL()
    {
        $this->setType(self::MASTER_HOST_SYMBOL);
    }

    protected function NETWORK_NAMESPACE_SYMBOL()
    {
        $this->setType(self::NETWORK_NAMESPACE_SYMBOL);
    }

    protected function MASTER_LOG_FILE_SYMBOL()
    {
        $this->setType(self::MASTER_LOG_FILE_SYMBOL);
    }

    protected function MASTER_LOG_POS_SYMBOL()
    {
        $this->setType(self::MASTER_LOG_POS_SYMBOL);
    }

    protected function MASTER_PASSWORD_SYMBOL()
    {
        $this->setType(self::MASTER_PASSWORD_SYMBOL);
    }

    protected function MASTER_PORT_SYMBOL()
    {
        $this->setType(self::MASTER_PORT_SYMBOL);
    }

    protected function MASTER_PUBLIC_KEY_PATH_SYMBOL()
    {
        $this->setType(self::MASTER_PUBLIC_KEY_PATH_SYMBOL);
    }

    protected function MASTER_RETRY_COUNT_SYMBOL()
    {
        $this->setType(self::MASTER_RETRY_COUNT_SYMBOL);
    }

    protected function MASTER_SERVER_ID_SYMBOL()
    {
        $this->setType(self::MASTER_SERVER_ID_SYMBOL);
    }

    protected function MASTER_SSL_CAPATH_SYMBOL()
    {
        $this->setType(self::MASTER_SSL_CAPATH_SYMBOL);
    }

    protected function MASTER_SSL_CA_SYMBOL()
    {
        $this->setType(self::MASTER_SSL_CA_SYMBOL);
    }

    protected function MASTER_SSL_CERT_SYMBOL()
    {
        $this->setType(self::MASTER_SSL_CERT_SYMBOL);
    }

    protected function MASTER_SSL_CIPHER_SYMBOL()
    {
        $this->setType(self::MASTER_SSL_CIPHER_SYMBOL);
    }

    protected function MASTER_SSL_CRL_SYMBOL()
    {
        $this->setType(self::MASTER_SSL_CRL_SYMBOL);
    }

    protected function MASTER_SSL_CRLPATH_SYMBOL()
    {
        $this->setType(self::MASTER_SSL_CRLPATH_SYMBOL);
    }

    protected function MASTER_SSL_KEY_SYMBOL()
    {
        $this->setType(self::MASTER_SSL_KEY_SYMBOL);
    }

    protected function MASTER_SSL_SYMBOL()
    {
        $this->setType(self::MASTER_SSL_SYMBOL);
    }

    protected function MASTER_SSL_VERIFY_SERVER_CERT_SYMBOL()
    {
        $this->setType(self::MASTER_SSL_VERIFY_SERVER_CERT_SYMBOL);
    }

    protected function MASTER_TLS_VERSION_SYMBOL()
    {
        $this->setType(self::MASTER_TLS_VERSION_SYMBOL);
    }

    protected function MASTER_TLS_CIPHERSUITES_SYMBOL()
    {
        $this->setType(self::MASTER_TLS_CIPHERSUITES_SYMBOL);
    }

    protected function MASTER_USER_SYMBOL()
    {
        $this->setType(self::MASTER_USER_SYMBOL);
    }

    protected function MASTER_ZSTD_COMPRESSION_LEVEL_SYMBOL()
    {
        $this->setType(self::MASTER_ZSTD_COMPRESSION_LEVEL_SYMBOL);
    }

    protected function MATCH_SYMBOL()
    {
        $this->setType(self::MATCH_SYMBOL);
    }

    protected function MAX_SYMBOL()
    {
        $this->setType($this->determineFunction(self::MAX_SYMBOL)); // SQL-2003-N
    }

    protected function MAX_CONNECTIONS_PER_HOUR_SYMBOL()
    {
        $this->setType(self::MAX_CONNECTIONS_PER_HOUR_SYMBOL);
    }

    protected function MAX_QUERIES_PER_HOUR_SYMBOL()
    {
        $this->setType(self::MAX_QUERIES_PER_HOUR_SYMBOL);
    }

    protected function MAX_ROWS_SYMBOL()
    {
        $this->setType(self::MAX_ROWS_SYMBOL);
    }

    protected function MAX_SIZE_SYMBOL()
    {
        $this->setType(self::MAX_SIZE_SYMBOL);
    }

    protected function MAX_STATEMENT_TIME_SYMBOL()
    {
        $this->setType(self::MAX_STATEMENT_TIME_SYMBOL);
    }

    protected function MAX_UPDATES_PER_HOUR_SYMBOL()
    {
        $this->setType(self::MAX_UPDATES_PER_HOUR_SYMBOL);
    }

    protected function MAX_USER_CONNECTIONS_SYMBOL()
    {
        $this->setType(self::MAX_USER_CONNECTIONS_SYMBOL);
    }

    protected function MAXVALUE_SYMBOL()
    {
        $this->setType(self::MAXVALUE_SYMBOL);
    }

    protected function MEDIUM_SYMBOL()
    {
        $this->setType(self::MEDIUM_SYMBOL);
    }

    protected function MEDIUMBLOB_SYMBOL()
    {
        $this->setType(self::MEDIUMBLOB_SYMBOL);
    }

    protected function MEDIUMINT_SYMBOL()
    {
        $this->setType(self::MEDIUMINT_SYMBOL);
    }

    protected function MEDIUMTEXT_SYMBOL()
    {
        $this->setType(self::MEDIUMTEXT_SYMBOL);
    }

    protected function MEMBER_SYMBOL()
    {
        $this->setType(self::MEMBER_SYMBOL);
    }

    protected function MEMORY_SYMBOL()
    {
        $this->setType(self::MEMORY_SYMBOL);
    }

    protected function MERGE_SYMBOL()
    {
        $this->setType(self::MERGE_SYMBOL);
    }

    protected function MESSAGE_TEXT_SYMBOL()
    {
        $this->setType(self::MESSAGE_TEXT_SYMBOL);
    }

    protected function MICROSECOND_SYMBOL()
    {
        $this->setType(self::MICROSECOND_SYMBOL);
    }

    protected function MIDDLEINT_SYMBOL()
    {
        $this->setType(self::MEDIUMINT_SYMBOL); // Synonym
    }

    protected function MIGRATE_SYMBOL()
    {
        $this->setType(self::MIGRATE_SYMBOL);
    }

    protected function MINUTE_SYMBOL()
    {
        $this->setType(self::MINUTE_SYMBOL);
    }

    protected function MINUTE_MICROSECOND_SYMBOL()
    {
        $this->setType(self::MINUTE_MICROSECOND_SYMBOL);
    }

    protected function MINUTE_SECOND_SYMBOL()
    {
        $this->setType(self::MINUTE_SECOND_SYMBOL);
    }

    protected function MIN_SYMBOL()
    {
        $this->setType($this->determineFunction(self::MIN_SYMBOL)); // SQL-2003-N
    }

    protected function MIN_ROWS_SYMBOL()
    {
        $this->setType(self::MIN_ROWS_SYMBOL);
    }

    protected function MODE_SYMBOL()
    {
        $this->setType(self::MODE_SYMBOL);
    }

    protected function MODIFIES_SYMBOL()
    {
        $this->setType(self::MODIFIES_SYMBOL);
    }

    protected function MODIFY_SYMBOL()
    {
        $this->setType(self::MODIFY_SYMBOL);
    }

    protected function MOD_SYMBOL()
    {
        $this->setType(self::MOD_SYMBOL);
    }

    protected function MONTH_SYMBOL()
    {
        $this->setType(self::MONTH_SYMBOL);
    }

    protected function MULTILINESTRING_SYMBOL()
    {
        $this->setType(self::MULTILINESTRING_SYMBOL);
    }

    protected function MULTIPOINT_SYMBOL()
    {
        $this->setType(self::MULTIPOINT_SYMBOL);
    }

    protected function MULTIPOLYGON_SYMBOL()
    {
        $this->setType(self::MULTIPOLYGON_SYMBOL);
    }

    protected function MUTEX_SYMBOL()
    {
        $this->setType(self::MUTEX_SYMBOL);
    }

    protected function MYSQL_ERRNO_SYMBOL()
    {
        $this->setType(self::MYSQL_ERRNO_SYMBOL);
    }

    protected function NAME_SYMBOL()
    {
        $this->setType(self::NAME_SYMBOL);
    }

    protected function NAMES_SYMBOL()
    {
        $this->setType(self::NAMES_SYMBOL);
    }

    protected function NATIONAL_SYMBOL()
    {
        $this->setType(self::NATIONAL_SYMBOL);
    }

    protected function NATURAL_SYMBOL()
    {
        $this->setType(self::NATURAL_SYMBOL);
    }

    protected function NCHAR_SYMBOL()
    {
        $this->setType(self::NCHAR_SYMBOL);
    }

    protected function NDBCLUSTER_SYMBOL()
    {
        $this->setType(self::NDBCLUSTER_SYMBOL);
    }

    protected function NDB_SYMBOL()
    {
        $this->setType(self::NDBCLUSTER_SYMBOL); // Synonym
    }

    protected function NEG_SYMBOL()
    {
        $this->setType(self::NEG_SYMBOL);
    }

    protected function NESTED_SYMBOL()
    {
        $this->setType(self::NESTED_SYMBOL);
    }

    protected function NEVER_SYMBOL()
    {
        $this->setType(self::NEVER_SYMBOL);
    }

    protected function NEW_SYMBOL()
    {
        $this->setType(self::NEW_SYMBOL);
    }

    protected function NEXT_SYMBOL()
    {
        $this->setType(self::NEXT_SYMBOL);
    }

    protected function NODEGROUP_SYMBOL()
    {
        $this->setType(self::NODEGROUP_SYMBOL);
    }

    protected function NONE_SYMBOL()
    {
        $this->setType(self::NONE_SYMBOL);
    }

    protected function NONBLOCKING_SYMBOL()
    {
        $this->setType(self::NONBLOCKING_SYMBOL);
    }

    protected function NOT2_SYMBOL()
    {
        $this->setType(self::NOT2_SYMBOL);
    }

    protected function NOT_SYMBOL()
    {
        if ($this->isSqlModeActive(MySQLLexer::HighNotPrecedence)) {
            $this->setType(self::NOT2_SYMBOL);
        } else {
            $this->setType(self::NOT_SYMBOL);
        }
    }

    protected function NOW_SYMBOL()
    {
        $this->setType($this->determineFunction(self::NOW_SYMBOL));
    }

    protected function NOWAIT_SYMBOL()
    {
        $this->setType(self::NOWAIT_SYMBOL);
    }

    protected function NO_SYMBOL()
    {
        $this->setType(self::NO_SYMBOL);
    }

    protected function NO_WAIT_SYMBOL()
    {
        $this->setType(self::NO_WAIT_SYMBOL);
    }

    protected function NO_WRITE_TO_BINLOG_SYMBOL()
    {
        $this->setType(self::NO_WRITE_TO_BINLOG_SYMBOL);
    }

    protected function NULL_SYMBOL()
    {
        $this->setType(self::NULL_SYMBOL);
    }

    protected function NULLS_SYMBOL()
    {
        $this->setType(self::NULLS_SYMBOL);
    }

    protected function NUMBER_SYMBOL()
    {
        $this->setType(self::NUMBER_SYMBOL);
    }

    protected function NUMERIC_SYMBOL()
    {
        $this->setType(self::NUMERIC_SYMBOL);
    }

    protected function NVARCHAR_SYMBOL()
    {
        $this->setType(self::NVARCHAR_SYMBOL);
    }

    protected function NTH_VALUE_SYMBOL()
    {
        $this->setType(self::NTH_VALUE_SYMBOL);
    }

    protected function NTILE_SYMBOL()
    {
        $this->setType(self::NTILE_SYMBOL);
    }

    protected function OF_SYMBOL()
    {
        $this->setType(self::OF_SYMBOL);
    }

    protected function OFF_SYMBOL()
    {
        $this->setType(self::OFF_SYMBOL);
    }

    protected function OFFLINE_SYMBOL()
    {
        $this->setType(self::OFFLINE_SYMBOL);
    }

    protected function OFFSET_SYMBOL()
    {
        $this->setType(self::OFFSET_SYMBOL);
    }

    protected function OJ_SYMBOL()
    {
        $this->setType(self::OJ_SYMBOL);
    }

    protected function OLD_PASSWORD_SYMBOL()
    {
        $this->setType(self::OLD_PASSWORD_SYMBOL);
    }

    protected function OLD_SYMBOL()
    {
        $this->setType(self::OLD_SYMBOL);
    }

    protected function ON_SYMBOL()
    {
        $this->setType(self::ON_SYMBOL);
    }

    protected function ONLINE_SYMBOL()
    {
        $this->setType(self::ONLINE_SYMBOL);
    }

    protected function ONE_SYMBOL()
    {
        $this->setType(self::ONE_SYMBOL);
    }

    protected function ONLY_SYMBOL()
    {
        $this->setType(self::ONLY_SYMBOL);
    }

    protected function OPEN_SYMBOL()
    {
        $this->setType(self::OPEN_SYMBOL);
    }

    protected function OPTIONAL_SYMBOL()
    {
        $this->setType(self::OPTIONAL_SYMBOL);
    }

    protected function OPTIONALLY_SYMBOL()
    {
        $this->setType(self::OPTIONALLY_SYMBOL);
    }

    protected function OPTION_SYMBOL()
    {
        $this->setType(self::OPTION_SYMBOL);
    }

    protected function OPTIONS_SYMBOL()
    {
        $this->setType(self::OPTIONS_SYMBOL);
    }

    protected function OPTIMIZE_SYMBOL()
    {
        $this->setType(self::OPTIMIZE_SYMBOL);
    }

    protected function OPTIMIZER_COSTS_SYMBOL()
    {
        $this->setType(self::OPTIMIZER_COSTS_SYMBOL);
    }

    protected function ORDER_SYMBOL()
    {
        $this->setType(self::ORDER_SYMBOL);
    }

    protected function ORDINALITY_SYMBOL()
    {
        $this->setType(self::ORDINALITY_SYMBOL);
    }

    protected function ORGANIZATION_SYMBOL()
    {
        $this->setType(self::ORGANIZATION_SYMBOL);
    }

    protected function OR_SYMBOL()
    {
        $this->setType(self::OR_SYMBOL);
    }

    protected function OTHERS_SYMBOL()
    {
        $this->setType(self::OTHERS_SYMBOL);
    }

    protected function OUTER_SYMBOL()
    {
        $this->setType(self::OUTER_SYMBOL);
    }

    protected function OUTFILE_SYMBOL()
    {
        $this->setType(self::OUTFILE_SYMBOL);
    }

    protected function OUT_SYMBOL()
    {
        $this->setType(self::OUT_SYMBOL);
    }

    protected function OWNER_SYMBOL()
    {
        $this->setType(self::OWNER_SYMBOL);
    }

    protected function PACK_KEYS_SYMBOL()
    {
        $this->setType(self::PACK_KEYS_SYMBOL);
    }

    protected function PAGE_SYMBOL()
    {
        $this->setType(self::PAGE_SYMBOL);
    }

    protected function PARSER_SYMBOL()
    {
        $this->setType(self::PARSER_SYMBOL);
    }

    protected function PARTITIONS_SYMBOL()
    {
        $this->setType(self::PARTITIONS_SYMBOL);
    }

    protected function PARTITION_SYMBOL()
    {
        $this->setType(self::PARTITION_SYMBOL);
    }

    protected function PARTIAL_SYMBOL()
    {
        $this->setType(self::PARTIAL_SYMBOL);
    }

    protected function PARTITIONING_SYMBOL()
    {
        $this->setType(self::PARTITIONING_SYMBOL);
    }

    protected function PASSWORD_SYMBOL()
    {
        $this->setType(self::PASSWORD_SYMBOL);
    }

    protected function PATH_SYMBOL()
    {
        $this->setType(self::PATH_SYMBOL);
    }

    protected function PERCENT_RANK_SYMBOL()
    {
        $this->setType(self::PERCENT_RANK_SYMBOL);
    }

    protected function PERSIST_SYMBOL()
    {
        $this->setType(self::PERSIST_SYMBOL);
    }

    protected function PERSIST_ONLY_SYMBOL()
    {
        $this->setType(self::PERSIST_ONLY_SYMBOL);
    }

    protected function PHASE_SYMBOL()
    {
        $this->setType(self::PHASE_SYMBOL);
    }

    protected function PLUGIN_SYMBOL()
    {
        $this->setType(self::PLUGIN_SYMBOL);
    }

    protected function PLUGINS_SYMBOL()
    {
        $this->setType(self::PLUGINS_SYMBOL);
    }

    protected function PLUGIN_DIR_SYMBOL()
    {
        $this->setType(self::PLUGIN_DIR_SYMBOL);
    }

    protected function POINT_SYMBOL()
    {
        $this->setType(self::POINT_SYMBOL);
    }

    protected function POLYGON_SYMBOL()
    {
        $this->setType(self::POLYGON_SYMBOL);
    }

    protected function PORT_SYMBOL()
    {
        $this->setType(self::PORT_SYMBOL);
    }

    protected function POSITION_SYMBOL()
    {
        $this->setType($this->determineFunction(self::POSITION_SYMBOL)); // SQL-2003-N
    }

    protected function PRECEDES_SYMBOL()
    {
        $this->setType(self::PRECEDES_SYMBOL);
    }

    protected function PRECEDING_SYMBOL()
    {
        $this->setType(self::PRECEDING_SYMBOL);
    }

    protected function PRECISION_SYMBOL()
    {
        $this->setType(self::PRECISION_SYMBOL);
    }

    protected function PREPARE_SYMBOL()
    {
        $this->setType(self::PREPARE_SYMBOL);
    }

    protected function PRESERVE_SYMBOL()
    {
        $this->setType(self::PRESERVE_SYMBOL);
    }

    protected function PREV_SYMBOL()
    {
        $this->setType(self::PREV_SYMBOL);
    }

    protected function PRIMARY_SYMBOL()
    {
        $this->setType(self::PRIMARY_SYMBOL);
    }

    protected function PRIVILEGES_SYMBOL()
    {
        $this->setType(self::PRIVILEGES_SYMBOL);
    }

    protected function PRIVILEGE_CHECKS_USER_SYMBOL()
    {
        $this->setType(self::PRIVILEGE_CHECKS_USER_SYMBOL);
    }

    protected function PROCEDURE_SYMBOL()
    {
        $this->setType(self::PROCEDURE_SYMBOL);
    }

    protected function PROCESS_SYMBOL()
    {
        $this->setType(self::PROCESS_SYMBOL);
    }

    protected function PROCESSLIST_SYMBOL()
    {
        $this->setType(self::PROCESSLIST_SYMBOL);
    }

    protected function PROFILE_SYMBOL()
    {
        $this->setType(self::PROFILE_SYMBOL);
    }

    protected function PROFILES_SYMBOL()
    {
        $this->setType(self::PROFILES_SYMBOL);
    }

    protected function PROXY_SYMBOL()
    {
        $this->setType(self::PROXY_SYMBOL);
    }

    protected function PURGE_SYMBOL()
    {
        $this->setType(self::PURGE_SYMBOL);
    }

    protected function QUARTER_SYMBOL()
    {
        $this->setType(self::QUARTER_SYMBOL);
    }

    protected function QUERY_SYMBOL()
    {
        $this->setType(self::QUERY_SYMBOL);
    }

    protected function QUICK_SYMBOL()
    {
        $this->setType(self::QUICK_SYMBOL);
    }

    protected function RANDOM_SYMBOL()
    {
        $this->setType(self::RANDOM_SYMBOL);
    }

    protected function RANGE_SYMBOL()
    {
        $this->setType(self::RANGE_SYMBOL);
    }

    protected function RANK_SYMBOL()
    {
        $this->setType(self::RANK_SYMBOL);
    }

    protected function READS_SYMBOL()
    {
        $this->setType(self::READS_SYMBOL);
    }

    protected function READ_ONLY_SYMBOL()
    {
        $this->setType(self::READ_ONLY_SYMBOL);
    }

    protected function READ_SYMBOL()
    {
        $this->setType(self::READ_SYMBOL);
    }

    protected function READ_WRITE_SYMBOL()
    {
        $this->setType(self::READ_WRITE_SYMBOL);
    }

    protected function REAL_SYMBOL()
    {
        $this->setType(self::REAL_SYMBOL);
    }

    protected function REBUILD_SYMBOL()
    {
        $this->setType(self::REBUILD_SYMBOL);
    }

    protected function RECOVER_SYMBOL()
    {
        $this->setType(self::RECOVER_SYMBOL);
    }

    protected function REDOFILE_SYMBOL()
    {
        $this->setType(self::REDOFILE_SYMBOL);
    }

    protected function REDO_BUFFER_SIZE_SYMBOL()
    {
        $this->setType(self::REDO_BUFFER_SIZE_SYMBOL);
    }

    protected function REDUNDANT_SYMBOL()
    {
        $this->setType(self::REDUNDANT_SYMBOL);
    }

    protected function REFERENCES_SYMBOL()
    {
        $this->setType(self::REFERENCES_SYMBOL);
    }

    protected function REFERENCE_SYMBOL()
    {
        $this->setType(self::REFERENCE_SYMBOL);
    }

    protected function RECURSIVE_SYMBOL()
    {
        $this->setType(self::RECURSIVE_SYMBOL);
    }

    protected function REGEXP_SYMBOL()
    {
        $this->setType(self::REGEXP_SYMBOL);
    }

    protected function RELAY_SYMBOL()
    {
        $this->setType(self::RELAY_SYMBOL);
    }

    protected function RELAYLOG_SYMBOL()
    {
        $this->setType(self::RELAYLOG_SYMBOL);
    }

    protected function RELAY_LOG_FILE_SYMBOL()
    {
        $this->setType(self::RELAY_LOG_FILE_SYMBOL);
    }

    protected function RELAY_LOG_POS_SYMBOL()
    {
        $this->setType(self::RELAY_LOG_POS_SYMBOL);
    }

    protected function RELAY_THREAD_SYMBOL()
    {
        $this->setType(self::RELAY_THREAD_SYMBOL);
    }

    protected function RELEASE_SYMBOL()
    {
        $this->setType(self::RELEASE_SYMBOL);
    }

    protected function RELOAD_SYMBOL()
    {
        $this->setType(self::RELOAD_SYMBOL);
    }

    protected function REMOTE_SYMBOL()
    {
        $this->setType(self::REMOTE_SYMBOL);
    }

    protected function REMOVE_SYMBOL()
    {
        $this->setType(self::REMOVE_SYMBOL);
    }

    protected function RENAME_SYMBOL()
    {
        $this->setType(self::RENAME_SYMBOL);
    }

    protected function REORGANIZE_SYMBOL()
    {
        $this->setType(self::REORGANIZE_SYMBOL);
    }

    protected function REPAIR_SYMBOL()
    {
        $this->setType(self::REPAIR_SYMBOL);
    }

    protected function REPEAT_SYMBOL()
    {
        $this->setType(self::REPEAT_SYMBOL);
    }

    protected function REPEATABLE_SYMBOL()
    {
        $this->setType(self::REPEATABLE_SYMBOL);
    }

    protected function REPLACE_SYMBOL()
    {
        $this->setType(self::REPLACE_SYMBOL);
    }

    protected function REPLICATION_SYMBOL()
    {
        $this->setType(self::REPLICATION_SYMBOL);
    }

    protected function REPLICATE_DO_DB_SYMBOL()
    {
        $this->setType(self::REPLICATE_DO_DB_SYMBOL);
    }

    protected function REPLICATE_DO_TABLE_SYMBOL()
    {
        $this->setType(self::REPLICATE_DO_TABLE_SYMBOL);
    }

    protected function REPLICATE_IGNORE_DB_SYMBOL()
    {
        $this->setType(self::REPLICATE_IGNORE_DB_SYMBOL);
    }

    protected function REPLICATE_IGNORE_TABLE_SYMBOL()
    {
        $this->setType(self::REPLICATE_IGNORE_TABLE_SYMBOL);
    }

    protected function REPLICATE_REWRITE_DB_SYMBOL()
    {
        $this->setType(self::REPLICATE_REWRITE_DB_SYMBOL);
    }

    protected function REPLICATE_WILD_DO_TABLE_SYMBOL()
    {
        $this->setType(self::REPLICATE_WILD_DO_TABLE_SYMBOL);
    }

    protected function REPLICATE_WILD_IGNORE_TABLE_SYMBOL()
    {
        $this->setType(self::REPLICATE_WILD_IGNORE_TABLE_SYMBOL);
    }

    protected function REQUIRE_SYMBOL()
    {
        $this->setType(self::REQUIRE_SYMBOL);
    }

    protected function REQUIRE_ROW_FORMAT_SYMBOL()
    {
        $this->setType(self::REQUIRE_ROW_FORMAT_SYMBOL);
    }

    protected function REQUIRE_TABLE_PRIMARY_KEY_CHECK_SYMBOL()
    {
        $this->setType(self::REQUIRE_TABLE_PRIMARY_KEY_CHECK_SYMBOL);
    }

    protected function RESOURCE_SYMBOL()
    {
        $this->setType(self::RESOURCE_SYMBOL);
    }

    protected function RESPECT_SYMBOL()
    {
        $this->setType(self::RESPECT_SYMBOL);
    }

    protected function RESTART_SYMBOL()
    {
        $this->setType(self::RESTART_SYMBOL);
    }

    protected function RESTORE_SYMBOL()
    {
        $this->setType(self::RESTORE_SYMBOL);
    }

    protected function RESTRICT_SYMBOL()
    {
        $this->setType(self::RESTRICT_SYMBOL);
    }

    protected function RESUME_SYMBOL()
    {
        $this->setType(self::RESUME_SYMBOL);
   }

    protected function RETAIN_SYMBOL()
    {
        $this->setType(self::RETAIN_SYMBOL);
    }

    protected function RETURN_SYMBOL()
    {
        $this->setType(self::RETURN_SYMBOL);
    }

    protected function RETURNED_SQLSTATE_SYMBOL()
    {
        $this->setType(self::RETURNED_SQLSTATE_SYMBOL);
    }

    protected function RETURNS_SYMBOL()
    {
        $this->setType(self::RETURNS_SYMBOL);
    }

    protected function REUSE_SYMBOL()
    {
        $this->setType(self::REUSE_SYMBOL);
    }

    protected function REVERSE_SYMBOL()
    {
        $this->setType(self::REVERSE_SYMBOL);
    }

    protected function REVOKE_SYMBOL()
    {
        $this->setType(self::REVOKE_SYMBOL);
    }

    protected function RIGHT_SYMBOL()
    {
        $this->setType(self::RIGHT_SYMBOL);
    }

    protected function RLIKE_SYMBOL()
    {
        $this->setType(self::REGEXP_SYMBOL); // Synonym
    }

    protected function ROLE_SYMBOL()
    {
        $this->setType(self::ROLE_SYMBOL);
    }

    protected function ROLLBACK_SYMBOL()
    {
        $this->setType(self::ROLLBACK_SYMBOL);
    }

    protected function ROLLUP_SYMBOL()
    {
        $this->setType(self::ROLLUP_SYMBOL);
    }

    protected function ROTATE_SYMBOL()
    {
        $this->setType(self::ROTATE_SYMBOL);
    }

    protected function ROW_SYMBOL()
    {
        $this->setType(self::ROW_SYMBOL);
    }

    protected function ROWS_SYMBOL()
    {
        $this->setType(self::ROWS_SYMBOL);
    }

    protected function ROW_COUNT_SYMBOL()
    {
        $this->setType(self::ROW_COUNT_SYMBOL);
    }

    protected function ROW_FORMAT_SYMBOL()
    {
        $this->setType(self::ROW_FORMAT_SYMBOL);
    }

    protected function ROW_NUMBER_SYMBOL()
    {
        $this->setType(self::ROW_NUMBER_SYMBOL);
    }

    protected function RTREE_SYMBOL()
    {
        $this->setType(self::RTREE_SYMBOL);
    }

    protected function SAVEPOINT_SYMBOL()
    {
        $this->setType(self::SAVEPOINT_SYMBOL);
    }

    protected function SCHEMA_NAME_SYMBOL()
    {
        $this->setType(self::SCHEMA_NAME_SYMBOL);
    }

    protected function SCHEMAS_SYMBOL()
    {
        $this->setType(self::DATABASES_SYMBOL); // Synonym
    }

    protected function SCHEMA_SYMBOL()
    {
        $this->setType(self::DATABASE_SYMBOL); // Synonym
    }

    protected function SCHEDULE_SYMBOL()
    {
        $this->setType(self::SCHEDULE_SYMBOL);
    }

    protected function SECOND_SYMBOL()
    {
        $this->setType(self::SECOND_SYMBOL);
    }

    protected function SECOND_MICROSECOND_SYMBOL()
    {
        $this->setType(self::SECOND_MICROSECOND_SYMBOL);
    }

    protected function SECONDARY_SYMBOL()
    {
        $this->setType(self::SECONDARY_SYMBOL);
    }

    protected function SECONDARY_ENGINE_SYMBOL()
    {
        $this->setType(self::SECONDARY_ENGINE_SYMBOL);
    }

    protected function SECONDARY_LOAD_SYMBOL()
    {
        $this->setType(self::SECONDARY_LOAD_SYMBOL);
    }

    protected function SECONDARY_UNLOAD_SYMBOL()
    {
        $this->setType(self::SECONDARY_UNLOAD_SYMBOL);
    }

    protected function SECURITY_SYMBOL()
    {
        $this->setType(self::SECURITY_SYMBOL);
    }

    protected function SELECT_SYMBOL()
    {
        $this->setType(self::SELECT_SYMBOL);
    }

    protected function SENSITIVE_SYMBOL()
    {
        $this->setType(self::SENSITIVE_SYMBOL);
    }

    protected function SEPARATOR_SYMBOL()
    {
        $this->setType(self::SEPARATOR_SYMBOL);
    }

    protected function SERIALIZABLE_SYMBOL()
    {
        $this->setType(self::SERIALIZABLE_SYMBOL);
    }

    protected function SERIAL_SYMBOL()
    {
        $this->setType(self::SERIAL_SYMBOL);
    }

    protected function SERVER_SYMBOL()
    {
        $this->setType(self::SERVER_SYMBOL);
    }

    protected function SERVER_OPTIONS_SYMBOL()
    {
        $this->setType(self::SERVER_OPTIONS_SYMBOL);
    }

    protected function SESSION_SYMBOL()
    {
        $this->setType(self::SESSION_SYMBOL);
    }

    protected function SESSION_USER_SYMBOL()
    {
        $this->setType($this->determineFunction(self::USER_SYMBOL)); // Synonym
    }

    protected function SET_SYMBOL()
    {
        $this->setType(self::SET_SYMBOL);
    }

    protected function SET_VAR_SYMBOL()
    {
        $this->setType(self::SET_VAR_SYMBOL);
    }

    protected function SHARE_SYMBOL()
    {
        $this->setType(self::SHARE_SYMBOL);
    }

    protected function SHOW_SYMBOL()
    {
        $this->setType(self::SHOW_SYMBOL);
    }

    protected function SHUTDOWN_SYMBOL()
    {
        $this->setType(self::SHUTDOWN_SYMBOL);
    }

    protected function SIGNAL_SYMBOL()
    {
        $this->setType(self::SIGNAL_SYMBOL);
    }

    protected function SIGNED_SYMBOL()
    {
        $this->setType(self::SIGNED_SYMBOL);
    }

    protected function SIMPLE_SYMBOL()
    {
        $this->setType(self::SIMPLE_SYMBOL);
    }

    protected function SKIP_SYMBOL()
    {
        $this->setType(self::SKIP_SYMBOL);
    }

    protected function SLAVE_SYMBOL()
    {
        $this->setType(self::SLAVE_SYMBOL);
    }

    protected function SLOW_SYMBOL()
    {
        $this->setType(self::SLOW_SYMBOL);
    }

    protected function SMALLINT_SYMBOL()
    {
        $this->setType(self::SMALLINT_SYMBOL);
    }

    protected function SNAPSHOT_SYMBOL()
    {
        $this->setType(self::SNAPSHOT_SYMBOL);
    }

    protected function SOME_SYMBOL()
    {
        $this->setType(self::ANY_SYMBOL); // Synonym
    }

    protected function SOCKET_SYMBOL()
    {
        $this->setType(self::SOCKET_SYMBOL);
    }

    protected function SONAME_SYMBOL()
    {
        $this->setType(self::SONAME_SYMBOL);
    }

    protected function SOUNDS_SYMBOL()
    {
        $this->setType(self::SOUNDS_SYMBOL);
    }

    protected function SOURCE_SYMBOL()
    {
        $this->setType(self::SOURCE_SYMBOL);
    }

    protected function SPATIAL_SYMBOL()
    {
        $this->setType(self::SPATIAL_SYMBOL);
    }

    protected function SPECIFIC_SYMBOL()
    {
        $this->setType(self::SPECIFIC_SYMBOL);
    }

    protected function SQLEXCEPTION_SYMBOL()
    {
        $this->setType(self::SQLEXCEPTION_SYMBOL);
    }

    protected function SQLSTATE_SYMBOL()
    {
        $this->setType(self::SQLSTATE_SYMBOL);
    }

    protected function SQLWARNING_SYMBOL()
    {
        $this->setType(self::SQLWARNING_SYMBOL);
    }

    protected function SQL_AFTER_GTIDS_SYMBOL()
    {
        $this->setType(self::SQL_AFTER_GTIDS_SYMBOL);
    }

    protected function SQL_AFTER_MTS_GAPS_SYMBOL()
    {
        $this->setType(self::SQL_AFTER_MTS_GAPS_SYMBOL);
    }

    protected function SQL_BEFORE_GTIDS_SYMBOL()
    {
        $this->setType(self::SQL_BEFORE_GTIDS_SYMBOL);
    }

    protected function SQL_BIG_RESULT_SYMBOL()
    {
        $this->setType(self::SQL_BIG_RESULT_SYMBOL);
    }

    protected function SQL_BUFFER_RESULT_SYMBOL()
    {
        $this->setType(self::SQL_BUFFER_RESULT_SYMBOL);
    }

    protected function SQL_CALC_FOUND_ROWS_SYMBOL()
    {
        $this->setType(self::SQL_CALC_FOUND_ROWS_SYMBOL);
    }

    protected function SQL_CACHE_SYMBOL()
    {
        $this->setType(self::SQL_CACHE_SYMBOL);
    }

    protected function SQL_NO_CACHE_SYMBOL()
    {
        $this->setType(self::SQL_NO_CACHE_SYMBOL);
    }

    protected function SQL_SMALL_RESULT_SYMBOL()
    {
        $this->setType(self::SQL_SMALL_RESULT_SYMBOL);
    }

    protected function SQL_SYMBOL()
    {
        $this->setType(self::SQL_SYMBOL);
    }

    protected function SQL_THREAD_SYMBOL()
    {
        $this->setType(self::SQL_THREAD_SYMBOL);
    }

    protected function SQL_TSI_SECOND_SYMBOL()
    {
        $this->setType(self::SECOND_SYMBOL); // Synonym
    }

    protected function SQL_TSI_MINUTE_SYMBOL()
    {
        $this->setType(self::MINUTE_SYMBOL); // Synonym
    }

    protected function SQL_TSI_HOUR_SYMBOL()
    {
        $this->setType(self::HOUR_SYMBOL); // Synonym
    }

    protected function SQL_TSI_DAY_SYMBOL()
    {
        $this->setType(self::DAY_SYMBOL); // Synonym
    }

    protected function SQL_TSI_WEEK_SYMBOL()
    {
        $this->setType(self::WEEK_SYMBOL); // Synonym
    }

    protected function SQL_TSI_MONTH_SYMBOL()
    {
        $this->setType(self::MONTH_SYMBOL); // Synonym
    }

    protected function SQL_TSI_QUARTER_SYMBOL()
    {
        $this->setType(self::QUARTER_SYMBOL); // Synonym
    }

    protected function SQL_TSI_YEAR_SYMBOL()
    {
        $this->setType(self::YEAR_SYMBOL); // Synonym
    }

    protected function SRID_SYMBOL()
    {
        $this->setType(self::SRID_SYMBOL);
    }

    protected function SSL_SYMBOL()
    {
        $this->setType(self::SSL_SYMBOL);
    }

    protected function STACKED_SYMBOL()
    {
        $this->setType(self::STACKED_SYMBOL);
    }

    protected function STARTING_SYMBOL()
    {
        $this->setType(self::STARTING_SYMBOL);
    }

    protected function STARTS_SYMBOL()
    {
        $this->setType(self::STARTS_SYMBOL);
    }

    protected function START_SYMBOL()
    {
        $this->setType(self::START_SYMBOL);
    }

    protected function STATS_AUTO_RECALC_SYMBOL()
    {
        $this->setType(self::STATS_AUTO_RECALC_SYMBOL);
    }

    protected function STATS_PERSISTENT_SYMBOL()
    {
        $this->setType(self::STATS_PERSISTENT_SYMBOL);
    }

    protected function STATS_SAMPLE_PAGES_SYMBOL()
    {
        $this->setType(self::STATS_SAMPLE_PAGES_SYMBOL);
    }

    protected function STATUS_SYMBOL()
    {
        $this->setType(self::STATUS_SYMBOL);
    }

    protected function STD_SYMBOL()
    {
        $this->setType($this->determineFunction(self::STD_SYMBOL));
    }

    protected function STDDEV_SYMBOL()
    {
        $this->setType($this->determineFunction(self::STD_SYMBOL)); // Synonym
    }

    protected function STDDEV_POP_SYMBOL()
    {
        $this->setType($this->determineFunction(self::STD_SYMBOL)); // Synonym
    }

    protected function STDDEV_SAMP_SYMBOL()
    {
        $this->setType($this->determineFunction(self::STDDEV_SAMP_SYMBOL)); // SQL-2003-N
    }

    protected function STOP_SYMBOL()
    {
        $this->setType(self::STOP_SYMBOL);
    }

    protected function STORAGE_SYMBOL()
    {
        $this->setType(self::STORAGE_SYMBOL);
    }

    protected function STORED_SYMBOL()
    {
        $this->setType(self::STORED_SYMBOL);
    }

    protected function STRAIGHT_JOIN_SYMBOL()
    {
        $this->setType(self::STRAIGHT_JOIN_SYMBOL);
    }

    protected function STREAM_SYMBOL()
    {
        $this->setType(self::STREAM_SYMBOL);
    }

    protected function STRING_SYMBOL()
    {
        $this->setType(self::STRING_SYMBOL);
    }

    protected function SUBCLASS_ORIGIN_SYMBOL()
    {
        $this->setType(self::SUBCLASS_ORIGIN_SYMBOL);
    }

    protected function SUBDATE_SYMBOL()
    {
        $this->setType($this->determineFunction(self::SUBDATE_SYMBOL));
    }

    protected function SUBJECT_SYMBOL()
    {
        $this->setType(self::SUBJECT_SYMBOL);
    }

    protected function SUBPARTITION_SYMBOL()
    {
        $this->setType(self::SUBPARTITION_SYMBOL);
    }

    protected function SUBPARTITIONS_SYMBOL()
    {
        $this->setType(self::SUBPARTITIONS_SYMBOL);
    }

    protected function SUBSTR_SYMBOL()
    {
        $this->setType($this->determineFunction(self::SUBSTRING_SYMBOL)); // Synonym
    }

    protected function SUBSTRING_SYMBOL()
    {
        $this->setType($this->determineFunction(self::SUBSTRING_SYMBOL)); // SQL-2003-N
    }

    protected function SUM_SYMBOL()
    {
        $this->setType($this->determineFunction(self::SUM_SYMBOL)); // SQL-2003-N
    }

    protected function SUPER_SYMBOL()
    {
        $this->setType(self::SUPER_SYMBOL);
    }

    protected function SUSPEND_SYMBOL()
    {
        $this->setType(self::SUSPEND_SYMBOL);
    }

    protected function SWAPS_SYMBOL()
    {
        $this->setType(self::SWAPS_SYMBOL);
    }

    protected function SWITCHES_SYMBOL()
    {
        $this->setType(self::SWITCHES_SYMBOL);
    }

    protected function SYSDATE_SYMBOL()
    {
        $this->setType($this->determineFunction(self::SYSDATE_SYMBOL));
    }

    protected function SYSTEM_SYMBOL()
    {
        $this->setType(self::SYSTEM_SYMBOL);
    }

    protected function SYSTEM_USER_SYMBOL()
    {
        $this->setType($this->determineFunction(self::USER_SYMBOL));
    }

    protected function TABLE_CHECKSUM_SYMBOL()
    {
        $this->setType(self::TABLE_CHECKSUM_SYMBOL);
    }

    protected function TABLE_SYMBOL()
    {
        $this->setType(self::TABLE_SYMBOL);
    }

    protected function TABLES_SYMBOL()
    {
        $this->setType(self::TABLES_SYMBOL);
    }

    protected function TABLESPACE_SYMBOL()
    {
        $this->setType(self::TABLESPACE_SYMBOL);
    }

    protected function TABLE_NAME_SYMBOL()
    {
        $this->setType(self::TABLE_NAME_SYMBOL);
    }

    protected function TEMPORARY_SYMBOL()
    {
        $this->setType(self::TEMPORARY_SYMBOL);
    }

    protected function TEMPTABLE_SYMBOL()
    {
        $this->setType(self::TEMPTABLE_SYMBOL);
    }

    protected function TERMINATED_SYMBOL()
    {
        $this->setType(self::TERMINATED_SYMBOL);
    }

    protected function TEXT_SYMBOL()
    {
        $this->setType(self::TEXT_SYMBOL);
    }

    protected function THAN_SYMBOL()
    {
        $this->setType(self::THAN_SYMBOL);
    }

    protected function THEN_SYMBOL()
    {
        $this->setType(self::THEN_SYMBOL);
    }

    protected function THREAD_PRIORITY_SYMBOL()
    {
        $this->setType(self::THREAD_PRIORITY_SYMBOL);
    }

    protected function TIES_SYMBOL()
    {
        $this->setType(self::TIES_SYMBOL);
    }

    protected function TIME_SYMBOL()
    {
        $this->setType(self::TIME_SYMBOL);
    }

    protected function TIMESTAMP_SYMBOL()
    {
        $this->setType(self::TIMESTAMP_SYMBOL);
    }

    protected function TIMESTAMP_ADD_SYMBOL()
    {
        $this->setType(self::TIMESTAMP_ADD_SYMBOL);
    }

    protected function TIMESTAMP_DIFF_SYMBOL()
    {
        $this->setType(self::TIMESTAMP_DIFF_SYMBOL);
    }

    protected function TINYBLOB_SYMBOL()
    {
        $this->setType(self::TINYBLOB_SYMBOL);
    }

    protected function TINYINT_SYMBOL()
    {
        $this->setType(self::TINYINT_SYMBOL);
    }

    protected function TINYTEXT_SYMBOL()
    {
        $this->setType(self::TINYTEXT_SYMBOL);
    }

    protected function TO_SYMBOL()
    {
        $this->setType(self::TO_SYMBOL);
    }

    protected function TRAILING_SYMBOL()
    {
        $this->setType(self::TRAILING_SYMBOL);
    }

    protected function TRANSACTION_SYMBOL()
    {
        $this->setType(self::TRANSACTION_SYMBOL);
    }

    protected function TRIGGERS_SYMBOL()
    {
        $this->setType(self::TRIGGERS_SYMBOL);
    }

    protected function TRIGGER_SYMBOL()
    {
        $this->setType(self::TRIGGER_SYMBOL);
    }

    protected function TRIM_SYMBOL()
    {
        $this->setType($this->determineFunction(self::TRIM_SYMBOL)); // SQL-2003-N
    }

    protected function TRUE_SYMBOL()
    {
        $this->setType(self::TRUE_SYMBOL);
    }

    protected function TRUNCATE_SYMBOL()
    {
        $this->setType(self::TRUNCATE_SYMBOL);
    }

    protected function TYPES_SYMBOL()
    {
        $this->setType(self::TYPES_SYMBOL);
    }

    protected function TYPE_SYMBOL()
    {
        $this->setType(self::TYPE_SYMBOL);
    }

    protected function UDF_RETURNS_SYMBOL()
    {
        $this->setType(self::UDF_RETURNS_SYMBOL);
    }

    protected function UNBOUNDED_SYMBOL()
    {
        $this->setType(self::UNBOUNDED_SYMBOL);
    }

    protected function UNCOMMITTED_SYMBOL()
    {
        $this->setType(self::UNCOMMITTED_SYMBOL);
    }

    protected function UNDEFINED_SYMBOL()
    {
        $this->setType(self::UNDEFINED_SYMBOL);
    }

    protected function UNDO_BUFFER_SIZE_SYMBOL()
    {
        $this->setType(self::UNDO_BUFFER_SIZE_SYMBOL);
    }

    protected function UNDOFILE_SYMBOL()
    {
        $this->setType(self::UNDOFILE_SYMBOL);
    }

    protected function UNDO_SYMBOL()
    {
        $this->setType(self::UNDO_SYMBOL);
    }

    protected function UNICODE_SYMBOL()
    {
        $this->setType(self::UNICODE_SYMBOL);
    }

    protected function UNION_SYMBOL()
    {
        $this->setType(self::UNION_SYMBOL);
    }

    protected function UNIQUE_SYMBOL()
    {
        $this->setType(self::UNIQUE_SYMBOL);
    }

    protected function UNKNOWN_SYMBOL()
    {
        $this->setType(self::UNKNOWN_SYMBOL);
    }

    protected function UNINSTALL_SYMBOL()
    {
        $this->setType(self::UNINSTALL_SYMBOL);
    }

    protected function UNLOCK_SYMBOL()
    {
        $this->setType(self::UNLOCK_SYMBOL);
    }

    protected function UNSIGNED_SYMBOL()
    {
        $this->setType(self::UNSIGNED_SYMBOL);
    }

    protected function UPDATE_SYMBOL()
    {
        $this->setType(self::UPDATE_SYMBOL);
    }

    protected function UPGRADE_SYMBOL()
    {
        $this->setType(self::UPGRADE_SYMBOL);
    }

    protected function USAGE_SYMBOL()
    {
        $this->setType(self::USAGE_SYMBOL);
    }

    protected function USER_RESOURCES_SYMBOL()
    {
        $this->setType(self::USER_RESOURCES_SYMBOL);
    }

    protected function USER_SYMBOL()
    {
        $this->setType(self::USER_SYMBOL);
    }

    protected function USE_FRM_SYMBOL()
    {
        $this->setType(self::USE_FRM_SYMBOL);
    }

    protected function USE_SYMBOL()
    {
        $this->setType(self::USE_SYMBOL);
    }

    protected function USING_SYMBOL()
    {
        $this->setType(self::USING_SYMBOL);
    }

    protected function UTC_DATE_SYMBOL()
    {
        $this->setType(self::UTC_DATE_SYMBOL);
    }

    protected function UTC_TIME_SYMBOL()
    {
        $this->setType(self::UTC_TIME_SYMBOL);
    }

    protected function UTC_TIMESTAMP_SYMBOL()
    {
        $this->setType(self::UTC_TIMESTAMP_SYMBOL);
    }

    protected function VALIDATION_SYMBOL()
    {
        $this->setType(self::VALIDATION_SYMBOL);
    }

    protected function VALUE_SYMBOL()
    {
        $this->setType(self::VALUE_SYMBOL);
    }

    protected function VALUES_SYMBOL()
    {
        $this->setType(self::VALUES_SYMBOL);
    }

    protected function VARBINARY_SYMBOL()
    {
        $this->setType(self::VARBINARY_SYMBOL);
    }

    protected function VARCHAR_SYMBOL()
    {
        $this->setType(self::VARCHAR_SYMBOL);
    }

    protected function VARCHARACTER_SYMBOL()
    {
        $this->setType(self::VARCHAR_SYMBOL); // Synonym
    }

    protected function VARIABLES_SYMBOL()
    {
        $this->setType(self::VARIABLES_SYMBOL);
    }

    protected function VARIANCE_SYMBOL()
    {
        $this->setType($this->determineFunction(self::VARIANCE_SYMBOL));
    }

    protected function VARYING_SYMBOL()
    {
        $this->setType(self::VARYING_SYMBOL);
    }

    protected function VAR_POP_SYMBOL()
    {
        $this->setType($this->determineFunction(self::VARIANCE_SYMBOL)); // Synonym
    }

    protected function VAR_SAMP_SYMBOL()
    {
        $this->setType($this->determineFunction(self::VAR_SAMP_SYMBOL));
    }

    protected function VCPU_SYMBOL()
    {
        $this->setType(self::VCPU_SYMBOL);
    }

    protected function VIEW_SYMBOL()
    {
        $this->setType(self::VIEW_SYMBOL);
    }

    protected function VIRTUAL_SYMBOL()
    {
        $this->setType(self::VIRTUAL_SYMBOL);
    }

    protected function VISIBLE_SYMBOL()
    {
        $this->setType(self::VISIBLE_SYMBOL);
    }

    protected function WAIT_SYMBOL()
    {
        $this->setType(self::WAIT_SYMBOL);
    }

    protected function WARNINGS_SYMBOL()
    {
        $this->setType(self::WARNINGS_SYMBOL);
    }

    protected function WEEK_SYMBOL()
    {
        $this->setType(self::WEEK_SYMBOL);
    }

    protected function WHEN_SYMBOL()
    {
        $this->setType(self::WHEN_SYMBOL);
    }

    protected function WEIGHT_STRING_SYMBOL()
    {
        $this->setType(self::WEIGHT_STRING_SYMBOL);
    }

    protected function WHERE_SYMBOL()
    {
        $this->setType(self::WHERE_SYMBOL);
    }

    protected function WHILE_SYMBOL()
    {
        $this->setType(self::WHILE_SYMBOL);
    }

    protected function WINDOW_SYMBOL()
    {
        $this->setType(self::WINDOW_SYMBOL);
    }

    protected function WITH_SYMBOL()
    {
        $this->setType(self::WITH_SYMBOL);
    }

    protected function WITHOUT_SYMBOL()
    {
        $this->setType(self::WITHOUT_SYMBOL);
    }

    protected function WORK_SYMBOL()
    {
        $this->setType(self::WORK_SYMBOL);
    }

    protected function WRAPPER_SYMBOL()
    {
        $this->setType(self::WRAPPER_SYMBOL);
    }

    protected function WRITE_SYMBOL()
    {
        $this->setType(self::WRITE_SYMBOL);
    }

    protected function XA_SYMBOL()
    {
        $this->setType(self::XA_SYMBOL);
    }

    protected function X509_SYMBOL()
    {
        $this->setType(self::X509_SYMBOL);
    }

    protected function XID_SYMBOL()
    {
        $this->setType(self::XID_SYMBOL);
    }

    protected function XML_SYMBOL()
    {
        $this->setType(self::XML_SYMBOL);
    }

    protected function XOR_SYMBOL()
    {
        $this->setType(self::XOR_SYMBOL);
    }

    protected function YEAR_MONTH_SYMBOL()
    {
        $this->setType(self::YEAR_MONTH_SYMBOL);
    }

    protected function YEAR_SYMBOL()
    {
        $this->setType(self::YEAR_SYMBOL);
    }

    protected function ZEROFILL_SYMBOL()
    {
        $this->setType(self::ZEROFILL_SYMBOL);
    }

    protected function INT1_SYMBOL()
    {
        $this->setType(self::TINYINT_SYMBOL); // Synonym
    }

    protected function INT2_SYMBOL()
    {
        $this->setType(self::SMALLINT_SYMBOL); // Synonym
    }

    protected function INT3_SYMBOL()
    {
        $this->setType(self::MEDIUMINT_SYMBOL); // Synonym
    }

    protected function INT4_SYMBOL()
    {
        $this->setType(self::INT_SYMBOL); // Synonym
    }

    protected function INT8_SYMBOL()
    {
        $this->setType(self::BIGINT_SYMBOL); // Synonym
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
        while (safe_ctype_digit($this->c)) {
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

    /**
     * This is a place holder to support features of MySQLBaseLexer which are not yet implemented
     * in the PHP target.
     *
     * @return int
     */
    protected function determineFunction(int $type): int
    {
        return $type;
    }
}

class MySQLToken
{
    private $type;
    private $text;
    private $channel;

    public function __construct($type, $text, $channel=null)
    {
        $this->type = $type;
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
        return $this->text . ' (' . $this->type . ')';
    }
}
