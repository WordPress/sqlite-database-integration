#![allow(dead_code)]

use std::mem;
use std::ptr;

use ext_php_rs::boxed::ZBox;
use ext_php_rs::builders::ClassBuilder;
use ext_php_rs::ffi::{zval, HashTable};
use ext_php_rs::types::ZendHashTable;

type DtorFunc = Option<unsafe extern "C" fn(*mut zval)>;
const GC_IMMUTABLE: u32 = 1 << 6;

extern "C" {
    fn _zend_hash_init(ht: *mut HashTable, nSize: u32, pDestructor: DtorFunc, persistent: bool);
}

fn persistent_array(capacity: usize) -> ZBox<ZendHashTable> {
    unsafe {
        let pointer = libc::malloc(mem::size_of::<ZendHashTable>()) as *mut ZendHashTable;
        if pointer.is_null() {
            panic!("Failed to allocate persistent Zend array");
        }
        ptr::write_bytes(pointer, 0, 1);
        _zend_hash_init(pointer, capacity as u32, None, true);
        ZBox::from_raw(pointer)
    }
}

fn freeze_array(array: &mut ZendHashTable) {
    unsafe {
        array.gc.u.type_info |= GC_IMMUTABLE;
    }
}
fn array_tokens() -> ZBox<ZendHashTable> {
    let mut array = persistent_array(800);
    array.insert("ACCESSIBLE", 1i64).unwrap();
    array.insert("ACCOUNT", 2i64).unwrap();
    array.insert("ACTION", 3i64).unwrap();
    array.insert("ADD", 4i64).unwrap();
    array.insert("ADDDATE", 5i64).unwrap();
    array.insert("AFTER", 6i64).unwrap();
    array.insert("AGAINST", 7i64).unwrap();
    array.insert("AGGREGATE", 8i64).unwrap();
    array.insert("ALGORITHM", 9i64).unwrap();
    array.insert("ALL", 10i64).unwrap();
    array.insert("ALTER", 11i64).unwrap();
    array.insert("ALWAYS", 12i64).unwrap();
    array.insert("ANALYSE", 13i64).unwrap();
    array.insert("ANALYZE", 14i64).unwrap();
    array.insert("AND", 15i64).unwrap();
    array.insert("ANY", 16i64).unwrap();
    array.insert("AS", 17i64).unwrap();
    array.insert("ASC", 18i64).unwrap();
    array.insert("ASCII", 19i64).unwrap();
    array.insert("ASENSITIVE", 20i64).unwrap();
    array.insert("AT", 21i64).unwrap();
    array.insert("ATTRIBUTE", 812i64).unwrap();
    array.insert("AUTHORS", 22i64).unwrap();
    array.insert("AUTO_INCREMENT", 24i64).unwrap();
    array.insert("AUTOEXTEND_SIZE", 23i64).unwrap();
    array.insert("AVG", 26i64).unwrap();
    array.insert("AVG_ROW_LENGTH", 25i64).unwrap();
    array.insert("BACKUP", 27i64).unwrap();
    array.insert("BEFORE", 28i64).unwrap();
    array.insert("BEGIN", 29i64).unwrap();
    array.insert("BETWEEN", 30i64).unwrap();
    array.insert("BIGINT", 31i64).unwrap();
    array.insert("BIN_NUM", 34i64).unwrap();
    array.insert("BINARY", 32i64).unwrap();
    array.insert("BINLOG", 33i64).unwrap();
    array.insert("BIT", 37i64).unwrap();
    array.insert("BIT_AND", 35i64).unwrap();
    array.insert("BIT_OR", 36i64).unwrap();
    array.insert("BIT_XOR", 38i64).unwrap();
    array.insert("BLOB", 39i64).unwrap();
    array.insert("BLOCK", 40i64).unwrap();
    array.insert("BOOL", 42i64).unwrap();
    array.insert("BOOLEAN", 41i64).unwrap();
    array.insert("BOTH", 43i64).unwrap();
    array.insert("BTREE", 44i64).unwrap();
    array.insert("BY", 45i64).unwrap();
    array.insert("BYTE", 46i64).unwrap();
    array.insert("CACHE", 47i64).unwrap();
    array.insert("CALL", 48i64).unwrap();
    array.insert("CASCADE", 49i64).unwrap();
    array.insert("CASCADED", 50i64).unwrap();
    array.insert("CASE", 51i64).unwrap();
    array.insert("CAST", 52i64).unwrap();
    array.insert("CATALOG_NAME", 53i64).unwrap();
    array.insert("CHAIN", 54i64).unwrap();
    array.insert("CHANGE", 55i64).unwrap();
    array.insert("CHANGED", 56i64).unwrap();
    array.insert("CHANNEL", 57i64).unwrap();
    array.insert("CHAR", 60i64).unwrap();
    array.insert("CHARACTER", 59i64).unwrap();
    array.insert("CHARSET", 58i64).unwrap();
    array.insert("CHECK", 62i64).unwrap();
    array.insert("CHECKSUM", 61i64).unwrap();
    array.insert("CIPHER", 63i64).unwrap();
    array.insert("CLASS_ORIGIN", 64i64).unwrap();
    array.insert("CLIENT", 65i64).unwrap();
    array.insert("CLOSE", 66i64).unwrap();
    array.insert("COALESCE", 67i64).unwrap();
    array.insert("CODE", 68i64).unwrap();
    array.insert("COLLATE", 69i64).unwrap();
    array.insert("COLLATION", 70i64).unwrap();
    array.insert("COLUMN", 72i64).unwrap();
    array.insert("COLUMN_FORMAT", 74i64).unwrap();
    array.insert("COLUMN_NAME", 73i64).unwrap();
    array.insert("COLUMNS", 71i64).unwrap();
    array.insert("COMMENT", 75i64).unwrap();
    array.insert("COMMIT", 77i64).unwrap();
    array.insert("COMMITTED", 76i64).unwrap();
    array.insert("COMPACT", 78i64).unwrap();
    array.insert("COMPLETION", 79i64).unwrap();
    array.insert("COMPRESSED", 80i64).unwrap();
    array.insert("COMPRESSION", 81i64).unwrap();
    array.insert("CONCURRENT", 82i64).unwrap();
    array.insert("CONDITION", 83i64).unwrap();
    array.insert("CONNECTION", 84i64).unwrap();
    array.insert("CONSISTENT", 85i64).unwrap();
    array.insert("CONSTRAINT", 86i64).unwrap();
    array.insert("CONSTRAINT_CATALOG", 87i64).unwrap();
    array.insert("CONSTRAINT_NAME", 88i64).unwrap();
    array.insert("CONSTRAINT_SCHEMA", 89i64).unwrap();
    array.insert("CONTAINS", 90i64).unwrap();
    array.insert("CONTEXT", 91i64).unwrap();
    array.insert("CONTINUE", 92i64).unwrap();
    array.insert("CONTRIBUTORS", 93i64).unwrap();
    array.insert("CONVERT", 94i64).unwrap();
    array.insert("COUNT", 95i64).unwrap();
    array.insert("CPU", 96i64).unwrap();
    array.insert("CREATE", 97i64).unwrap();
    array.insert("CROSS", 98i64).unwrap();
    array.insert("CUBE", 99i64).unwrap();
    array.insert("CURDATE", 100i64).unwrap();
    array.insert("CURRENT", 101i64).unwrap();
    array.insert("CURRENT_DATE", 102i64).unwrap();
    array.insert("CURRENT_TIME", 103i64).unwrap();
    array.insert("CURRENT_TIMESTAMP", 104i64).unwrap();
    array.insert("CURRENT_USER", 105i64).unwrap();
    array.insert("CURSOR", 106i64).unwrap();
    array.insert("CURSOR_NAME", 107i64).unwrap();
    array.insert("CURTIME", 108i64).unwrap();
    array.insert("DATA", 112i64).unwrap();
    array.insert("DATABASE", 109i64).unwrap();
    array.insert("DATABASES", 110i64).unwrap();
    array.insert("DATAFILE", 111i64).unwrap();
    array.insert("DATE", 116i64).unwrap();
    array.insert("DATE_ADD", 114i64).unwrap();
    array.insert("DATE_SUB", 115i64).unwrap();
    array.insert("DATETIME", 113i64).unwrap();
    array.insert("DAY", 122i64).unwrap();
    array.insert("DAY_HOUR", 118i64).unwrap();
    array.insert("DAY_MICROSECOND", 119i64).unwrap();
    array.insert("DAY_MINUTE", 120i64).unwrap();
    array.insert("DAY_SECOND", 121i64).unwrap();
    array.insert("DAYOFMONTH", 117i64).unwrap();
    array.insert("DEALLOCATE", 123i64).unwrap();
    array.insert("DEC", 124i64).unwrap();
    array.insert("DECIMAL", 126i64).unwrap();
    array.insert("DECIMAL_NUM", 125i64).unwrap();
    array.insert("DECLARE", 127i64).unwrap();
    array.insert("DEFAULT", 128i64).unwrap();
    array.insert("DEFAULT_AUTH", 129i64).unwrap();
    array.insert("DEFINER", 130i64).unwrap();
    array.insert("DELAY_KEY_WRITE", 132i64).unwrap();
    array.insert("DELAYED", 131i64).unwrap();
    array.insert("DELETE", 133i64).unwrap();
    array.insert("DES_KEY_FILE", 136i64).unwrap();
    array.insert("DESC", 134i64).unwrap();
    array.insert("DESCRIBE", 135i64).unwrap();
    array.insert("DETERMINISTIC", 137i64).unwrap();
    array.insert("DIAGNOSTICS", 138i64).unwrap();
    array.insert("DIRECTORY", 139i64).unwrap();
    array.insert("DISABLE", 140i64).unwrap();
    array.insert("DISCARD", 141i64).unwrap();
    array.insert("DISK", 142i64).unwrap();
    array.insert("DISTINCT", 143i64).unwrap();
    array.insert("DISTINCTROW", 144i64).unwrap();
    array.insert("DIV", 145i64).unwrap();
    array.insert("DO", 147i64).unwrap();
    array.insert("DOUBLE", 146i64).unwrap();
    array.insert("DROP", 148i64).unwrap();
    array.insert("DUAL", 149i64).unwrap();
    array.insert("DUMPFILE", 150i64).unwrap();
    array.insert("DUPLICATE", 151i64).unwrap();
    array.insert("DYNAMIC", 152i64).unwrap();
    array.insert("EACH", 153i64).unwrap();
    array.insert("ELSE", 154i64).unwrap();
    array.insert("ELSEIF", 155i64).unwrap();
    array.insert("ENABLE", 156i64).unwrap();
    array.insert("ENCLOSED", 157i64).unwrap();
    array.insert("ENCRYPTION", 158i64).unwrap();
    array.insert("END", 159i64).unwrap();
    array.insert("END_OF_INPUT", -1i64).unwrap();
    array.insert("ENDS", 160i64).unwrap();
    array.insert("ENGINE", 163i64).unwrap();
    array.insert("ENGINES", 162i64).unwrap();
    array.insert("ENUM", 164i64).unwrap();
    array.insert("ERROR", 165i64).unwrap();
    array.insert("ERRORS", 166i64).unwrap();
    array.insert("ESCAPE", 168i64).unwrap();
    array.insert("ESCAPED", 167i64).unwrap();
    array.insert("EVENT", 170i64).unwrap();
    array.insert("EVENTS", 169i64).unwrap();
    array.insert("EVERY", 171i64).unwrap();
    array.insert("EXCHANGE", 172i64).unwrap();
    array.insert("EXECUTE", 173i64).unwrap();
    array.insert("EXISTS", 174i64).unwrap();
    array.insert("EXIT", 175i64).unwrap();
    array.insert("EXPANSION", 176i64).unwrap();
    array.insert("EXPIRE", 177i64).unwrap();
    array.insert("EXPLAIN", 178i64).unwrap();
    array.insert("EXPORT", 179i64).unwrap();
    array.insert("EXTENDED", 180i64).unwrap();
    array.insert("EXTENT_SIZE", 181i64).unwrap();
    array.insert("EXTRACT", 182i64).unwrap();
    array.insert("FALSE", 183i64).unwrap();
    array.insert("FAST", 184i64).unwrap();
    array.insert("FAULTS", 185i64).unwrap();
    array.insert("FETCH", 186i64).unwrap();
    array.insert("FIELDS", 187i64).unwrap();
    array.insert("FILE", 188i64).unwrap();
    array.insert("FILE_BLOCK_SIZE", 189i64).unwrap();
    array.insert("FILTER", 190i64).unwrap();
    array.insert("FIRST", 191i64).unwrap();
    array.insert("FIXED", 192i64).unwrap();
    array.insert("FLOAT", 195i64).unwrap();
    array.insert("FLOAT4", 193i64).unwrap();
    array.insert("FLOAT8", 194i64).unwrap();
    array.insert("FLUSH", 196i64).unwrap();
    array.insert("FOLLOWS", 197i64).unwrap();
    array.insert("FOR", 200i64).unwrap();
    array.insert("FORCE", 198i64).unwrap();
    array.insert("FOREIGN", 199i64).unwrap();
    array.insert("FORMAT", 201i64).unwrap();
    array.insert("FOUND", 202i64).unwrap();
    array.insert("FROM", 203i64).unwrap();
    array.insert("FULL", 204i64).unwrap();
    array.insert("FULLTEXT", 205i64).unwrap();
    array.insert("FUNCTION", 206i64).unwrap();
    array.insert("GENERAL", 208i64).unwrap();
    array.insert("GENERATED", 209i64).unwrap();
    array.insert("GEOMCOLLECTION", 852i64).unwrap();
    array.insert("GEOMETRY", 212i64).unwrap();
    array.insert("GEOMETRYCOLLECTION", 211i64).unwrap();
    array.insert("GET", 207i64).unwrap();
    array.insert("GET_FORMAT", 213i64).unwrap();
    array.insert("GLOBAL", 214i64).unwrap();
    array.insert("GRANT", 215i64).unwrap();
    array.insert("GRANTS", 216i64).unwrap();
    array.insert("GROUP", 217i64).unwrap();
    array.insert("GROUP_CONCAT", 218i64).unwrap();
    array.insert("GROUP_REPLICATION", 210i64).unwrap();
    array.insert("HANDLER", 219i64).unwrap();
    array.insert("HASH", 220i64).unwrap();
    array.insert("HAVING", 221i64).unwrap();
    array.insert("HELP", 222i64).unwrap();
    array.insert("HIGH_PRIORITY", 223i64).unwrap();
    array.insert("HOST", 224i64).unwrap();
    array.insert("HOSTS", 225i64).unwrap();
    array.insert("HOUR", 229i64).unwrap();
    array.insert("HOUR_MICROSECOND", 226i64).unwrap();
    array.insert("HOUR_MINUTE", 227i64).unwrap();
    array.insert("HOUR_SECOND", 228i64).unwrap();
    array.insert("IDENTIFIED", 230i64).unwrap();
    array.insert("IF", 231i64).unwrap();
    array.insert("IGNORE", 232i64).unwrap();
    array.insert("IGNORE_SERVER_IDS", 233i64).unwrap();
    array.insert("IMPORT", 234i64).unwrap();
    array.insert("IN", 251i64).unwrap();
    array.insert("INDEX", 236i64).unwrap();
    array.insert("INDEXES", 235i64).unwrap();
    array.insert("INFILE", 237i64).unwrap();
    array.insert("INITIAL_SIZE", 238i64).unwrap();
    array.insert("INNER", 239i64).unwrap();
    array.insert("INNODB", 844i64).unwrap();
    array.insert("INOUT", 240i64).unwrap();
    array.insert("INSENSITIVE", 241i64).unwrap();
    array.insert("INSERT", 242i64).unwrap();
    array.insert("INSERT_METHOD", 243i64).unwrap();
    array.insert("INSTALL", 245i64).unwrap();
    array.insert("INSTANCE", 244i64).unwrap();
    array.insert("INT", 249i64).unwrap();
    array.insert("INT1", 795i64).unwrap();
    array.insert("INT2", 796i64).unwrap();
    array.insert("INT3", 797i64).unwrap();
    array.insert("INT4", 798i64).unwrap();
    array.insert("INT8", 799i64).unwrap();
    array.insert("INTEGER", 246i64).unwrap();
    array.insert("INTERVAL", 247i64).unwrap();
    array.insert("INTO", 248i64).unwrap();
    array.insert("INVOKER", 250i64).unwrap();
    array.insert("IO", 255i64).unwrap();
    array.insert("IO_AFTER_GTIDS", 252i64).unwrap();
    array.insert("IO_BEFORE_GTIDS", 253i64).unwrap();
    array.insert("IO_THREAD", 254i64).unwrap();
    array.insert("IPC", 256i64).unwrap();
    array.insert("IS", 257i64).unwrap();
    array.insert("ISOLATION", 258i64).unwrap();
    array.insert("ISSUER", 259i64).unwrap();
    array.insert("ITERATE", 260i64).unwrap();
    array.insert("JOIN", 261i64).unwrap();
    array.insert("JSON", 262i64).unwrap();
    array.insert("KEY", 265i64).unwrap();
    array.insert("KEY_BLOCK_SIZE", 264i64).unwrap();
    array.insert("KEYS", 263i64).unwrap();
    array.insert("KILL", 266i64).unwrap();
    array.insert("LANGUAGE", 267i64).unwrap();
    array.insert("LAST", 268i64).unwrap();
    array.insert("LEADING", 269i64).unwrap();
    array.insert("LEAVE", 271i64).unwrap();
    array.insert("LEAVES", 270i64).unwrap();
    array.insert("LEFT", 272i64).unwrap();
    array.insert("LESS", 273i64).unwrap();
    array.insert("LEVEL", 274i64).unwrap();
    array.insert("LIKE", 275i64).unwrap();
    array.insert("LIMIT", 276i64).unwrap();
    array.insert("LINEAR", 277i64).unwrap();
    array.insert("LINES", 278i64).unwrap();
    array.insert("LINESTRING", 279i64).unwrap();
    array.insert("LIST", 280i64).unwrap();
    array.insert("LOAD", 281i64).unwrap();
    array.insert("LOCAL", 284i64).unwrap();
    array.insert("LOCALTIME", 282i64).unwrap();
    array.insert("LOCALTIMESTAMP", 283i64).unwrap();
    array.insert("LOCATOR", 285i64).unwrap();
    array.insert("LOCK", 287i64).unwrap();
    array.insert("LOCKS", 286i64).unwrap();
    array.insert("LOGFILE", 288i64).unwrap();
    array.insert("LOGS", 289i64).unwrap();
    array.insert("LONG", 293i64).unwrap();
    array.insert("LONG_NUM", 292i64).unwrap();
    array.insert("LONGBLOB", 290i64).unwrap();
    array.insert("LONGTEXT", 291i64).unwrap();
    array.insert("LOOP", 294i64).unwrap();
    array.insert("LOW_PRIORITY", 295i64).unwrap();
    array.insert("MASTER", 316i64).unwrap();
    array.insert("MASTER_AUTO_POSITION", 296i64).unwrap();
    array.insert("MASTER_BIND", 297i64).unwrap();
    array.insert("MASTER_CONNECT_RETRY", 298i64).unwrap();
    array.insert("MASTER_DELAY", 299i64).unwrap();
    array.insert("MASTER_HEARTBEAT_PERIOD", 319i64).unwrap();
    array.insert("MASTER_HOST", 300i64).unwrap();
    array.insert("MASTER_LOG_FILE", 301i64).unwrap();
    array.insert("MASTER_LOG_POS", 302i64).unwrap();
    array.insert("MASTER_PASSWORD", 303i64).unwrap();
    array.insert("MASTER_PORT", 304i64).unwrap();
    array.insert("MASTER_RETRY_COUNT", 305i64).unwrap();
    array.insert("MASTER_SERVER_ID", 306i64).unwrap();
    array.insert("MASTER_SSL", 314i64).unwrap();
    array.insert("MASTER_SSL_CA", 308i64).unwrap();
    array.insert("MASTER_SSL_CAPATH", 307i64).unwrap();
    array.insert("MASTER_SSL_CERT", 309i64).unwrap();
    array.insert("MASTER_SSL_CIPHER", 310i64).unwrap();
    array.insert("MASTER_SSL_CRL", 311i64).unwrap();
    array.insert("MASTER_SSL_CRLPATH", 312i64).unwrap();
    array.insert("MASTER_SSL_KEY", 313i64).unwrap();
    array
        .insert("MASTER_SSL_VERIFY_SERVER_CERT", 315i64)
        .unwrap();
    array.insert("MASTER_TLS_VERSION", 317i64).unwrap();
    array.insert("MASTER_USER", 318i64).unwrap();
    array.insert("MATCH", 320i64).unwrap();
    array.insert("MAX", 326i64).unwrap();
    array.insert("MAX_CONNECTIONS_PER_HOUR", 321i64).unwrap();
    array.insert("MAX_QUERIES_PER_HOUR", 322i64).unwrap();
    array.insert("MAX_ROWS", 323i64).unwrap();
    array.insert("MAX_SIZE", 324i64).unwrap();
    array.insert("MAX_STATEMENT_TIME", 325i64).unwrap();
    array.insert("MAX_UPDATES_PER_HOUR", 327i64).unwrap();
    array.insert("MAX_USER_CONNECTIONS", 328i64).unwrap();
    array.insert("MAXVALUE", 329i64).unwrap();
    array.insert("MEDIUM", 333i64).unwrap();
    array.insert("MEDIUMBLOB", 330i64).unwrap();
    array.insert("MEDIUMINT", 331i64).unwrap();
    array.insert("MEDIUMTEXT", 332i64).unwrap();
    array.insert("MEMORY", 334i64).unwrap();
    array.insert("MERGE", 335i64).unwrap();
    array.insert("MESSAGE_TEXT", 336i64).unwrap();
    array.insert("MICROSECOND", 337i64).unwrap();
    array.insert("MID", 338i64).unwrap();
    array.insert("MIDDLEINT", 339i64).unwrap();
    array.insert("MIGRATE", 340i64).unwrap();
    array.insert("MIN", 345i64).unwrap();
    array.insert("MIN_ROWS", 344i64).unwrap();
    array.insert("MINUTE", 343i64).unwrap();
    array.insert("MINUTE_MICROSECOND", 341i64).unwrap();
    array.insert("MINUTE_SECOND", 342i64).unwrap();
    array.insert("MOD", 349i64).unwrap();
    array.insert("MODE", 346i64).unwrap();
    array.insert("MODIFIES", 347i64).unwrap();
    array.insert("MODIFY", 348i64).unwrap();
    array.insert("MONTH", 350i64).unwrap();
    array.insert("MULTILINESTRING", 351i64).unwrap();
    array.insert("MULTIPOINT", 352i64).unwrap();
    array.insert("MULTIPOLYGON", 353i64).unwrap();
    array.insert("MUTEX", 354i64).unwrap();
    array.insert("MYSQL_ERRNO", 355i64).unwrap();
    array.insert("NAME", 357i64).unwrap();
    array.insert("NAMES", 356i64).unwrap();
    array.insert("NATIONAL", 358i64).unwrap();
    array.insert("NATURAL", 359i64).unwrap();
    array.insert("NCHAR", 361i64).unwrap();
    array.insert("NCHAR_STRING", 360i64).unwrap();
    array.insert("NDB", 362i64).unwrap();
    array.insert("NDBCLUSTER", 363i64).unwrap();
    array.insert("NEG", 364i64).unwrap();
    array.insert("NEVER", 365i64).unwrap();
    array.insert("NEW", 366i64).unwrap();
    array.insert("NEXT", 367i64).unwrap();
    array.insert("NO", 373i64).unwrap();
    array.insert("NO_WAIT", 374i64).unwrap();
    array.insert("NO_WRITE_TO_BINLOG", 375i64).unwrap();
    array.insert("NODEGROUP", 368i64).unwrap();
    array.insert("NONBLOCKING", 370i64).unwrap();
    array.insert("NONE", 369i64).unwrap();
    array.insert("NOT", 371i64).unwrap();
    array.insert("NOW", 372i64).unwrap();
    array.insert("NULL", 376i64).unwrap();
    array.insert("NUMBER", 377i64).unwrap();
    array.insert("NUMERIC", 378i64).unwrap();
    array.insert("NVARCHAR", 379i64).unwrap();
    array.insert("OFFLINE", 380i64).unwrap();
    array.insert("OFFSET", 381i64).unwrap();
    array.insert("OLD_PASSWORD", 382i64).unwrap();
    array.insert("ON", 383i64).unwrap();
    array.insert("ONE", 384i64).unwrap();
    array.insert("ONLINE", 385i64).unwrap();
    array.insert("ONLY", 386i64).unwrap();
    array.insert("OPEN", 387i64).unwrap();
    array.insert("OPTIMIZE", 388i64).unwrap();
    array.insert("OPTIMIZER_COSTS", 389i64).unwrap();
    array.insert("OPTION", 391i64).unwrap();
    array.insert("OPTIONALLY", 392i64).unwrap();
    array.insert("OPTIONS", 390i64).unwrap();
    array.insert("OR", 394i64).unwrap();
    array.insert("ORDER", 393i64).unwrap();
    array.insert("OUT", 397i64).unwrap();
    array.insert("OUTER", 395i64).unwrap();
    array.insert("OUTFILE", 396i64).unwrap();
    array.insert("OWNER", 398i64).unwrap();
    array.insert("PACK_KEYS", 399i64).unwrap();
    array.insert("PAGE", 400i64).unwrap();
    array.insert("PARSER", 401i64).unwrap();
    array.insert("PARTIAL", 402i64).unwrap();
    array.insert("PARTITION", 405i64).unwrap();
    array.insert("PARTITIONING", 403i64).unwrap();
    array.insert("PARTITIONS", 404i64).unwrap();
    array.insert("PASSWORD", 406i64).unwrap();
    array.insert("PHASE", 407i64).unwrap();
    array.insert("PLUGIN", 410i64).unwrap();
    array.insert("PLUGIN_DIR", 409i64).unwrap();
    array.insert("PLUGINS", 408i64).unwrap();
    array.insert("POINT", 411i64).unwrap();
    array.insert("POLYGON", 412i64).unwrap();
    array.insert("PORT", 413i64).unwrap();
    array.insert("POSITION", 414i64).unwrap();
    array.insert("PRECEDES", 415i64).unwrap();
    array.insert("PRECISION", 416i64).unwrap();
    array.insert("PREPARE", 417i64).unwrap();
    array.insert("PRESERVE", 418i64).unwrap();
    array.insert("PREV", 419i64).unwrap();
    array.insert("PRIMARY", 420i64).unwrap();
    array.insert("PRIVILEGES", 421i64).unwrap();
    array.insert("PROCEDURE", 422i64).unwrap();
    array.insert("PROCESS", 423i64).unwrap();
    array.insert("PROCESSLIST", 424i64).unwrap();
    array.insert("PROFILE", 425i64).unwrap();
    array.insert("PROFILES", 426i64).unwrap();
    array.insert("PROXY", 427i64).unwrap();
    array.insert("PURGE", 428i64).unwrap();
    array.insert("QUARTER", 429i64).unwrap();
    array.insert("QUERY", 430i64).unwrap();
    array.insert("QUICK", 431i64).unwrap();
    array.insert("RANGE", 432i64).unwrap();
    array.insert("READ", 435i64).unwrap();
    array.insert("READ_ONLY", 434i64).unwrap();
    array.insert("READ_WRITE", 436i64).unwrap();
    array.insert("READS", 433i64).unwrap();
    array.insert("REAL", 437i64).unwrap();
    array.insert("REBUILD", 438i64).unwrap();
    array.insert("RECOVER", 439i64).unwrap();
    array.insert("REDO_BUFFER_SIZE", 441i64).unwrap();
    array.insert("REDOFILE", 440i64).unwrap();
    array.insert("REDUNDANT", 442i64).unwrap();
    array.insert("REFERENCES", 443i64).unwrap();
    array.insert("REGEXP", 444i64).unwrap();
    array.insert("RELAY", 445i64).unwrap();
    array.insert("RELAY_LOG_FILE", 447i64).unwrap();
    array.insert("RELAY_LOG_POS", 448i64).unwrap();
    array.insert("RELAY_THREAD", 449i64).unwrap();
    array.insert("RELAYLOG", 446i64).unwrap();
    array.insert("RELEASE", 450i64).unwrap();
    array.insert("RELOAD", 451i64).unwrap();
    array.insert("REMOVE", 452i64).unwrap();
    array.insert("RENAME", 453i64).unwrap();
    array.insert("REORGANIZE", 454i64).unwrap();
    array.insert("REPAIR", 455i64).unwrap();
    array.insert("REPEAT", 457i64).unwrap();
    array.insert("REPEATABLE", 456i64).unwrap();
    array.insert("REPLACE", 458i64).unwrap();
    array.insert("REPLICATE_DO_DB", 460i64).unwrap();
    array.insert("REPLICATE_DO_TABLE", 462i64).unwrap();
    array.insert("REPLICATE_IGNORE_DB", 461i64).unwrap();
    array.insert("REPLICATE_IGNORE_TABLE", 463i64).unwrap();
    array.insert("REPLICATE_REWRITE_DB", 466i64).unwrap();
    array.insert("REPLICATE_WILD_DO_TABLE", 464i64).unwrap();
    array.insert("REPLICATE_WILD_IGNORE_TABLE", 465i64).unwrap();
    array.insert("REPLICATION", 459i64).unwrap();
    array.insert("REQUIRE", 467i64).unwrap();
    array.insert("RESET", 468i64).unwrap();
    array.insert("RESIGNAL", 469i64).unwrap();
    array.insert("RESTORE", 470i64).unwrap();
    array.insert("RESTRICT", 471i64).unwrap();
    array.insert("RESUME", 472i64).unwrap();
    array.insert("RETURN", 475i64).unwrap();
    array.insert("RETURNED_SQLSTATE", 473i64).unwrap();
    array.insert("RETURNS", 474i64).unwrap();
    array.insert("REVERSE", 476i64).unwrap();
    array.insert("REVOKE", 477i64).unwrap();
    array.insert("RIGHT", 478i64).unwrap();
    array.insert("RLIKE", 479i64).unwrap();
    array.insert("ROLLBACK", 480i64).unwrap();
    array.insert("ROLLUP", 481i64).unwrap();
    array.insert("ROTATE", 482i64).unwrap();
    array.insert("ROUTINE", 483i64).unwrap();
    array.insert("ROW", 487i64).unwrap();
    array.insert("ROW_COUNT", 485i64).unwrap();
    array.insert("ROW_FORMAT", 486i64).unwrap();
    array.insert("ROWS", 484i64).unwrap();
    array.insert("RTREE", 488i64).unwrap();
    array.insert("SAVEPOINT", 489i64).unwrap();
    array.insert("SCHEDULE", 490i64).unwrap();
    array.insert("SCHEMA", 491i64).unwrap();
    array.insert("SCHEMA_NAME", 492i64).unwrap();
    array.insert("SCHEMAS", 493i64).unwrap();
    array.insert("SECOND", 495i64).unwrap();
    array.insert("SECOND_MICROSECOND", 494i64).unwrap();
    array.insert("SECURITY", 496i64).unwrap();
    array.insert("SELECT", 497i64).unwrap();
    array.insert("SENSITIVE", 498i64).unwrap();
    array.insert("SEPARATOR", 499i64).unwrap();
    array.insert("SERIAL", 501i64).unwrap();
    array.insert("SERIALIZABLE", 500i64).unwrap();
    array.insert("SERVER", 503i64).unwrap();
    array.insert("SERVER_OPTIONS", 504i64).unwrap();
    array.insert("SESSION", 502i64).unwrap();
    array.insert("SESSION_USER", 505i64).unwrap();
    array.insert("SET", 506i64).unwrap();
    array.insert("SET_VAR", 507i64).unwrap();
    array.insert("SHARE", 508i64).unwrap();
    array.insert("SHOW", 509i64).unwrap();
    array.insert("SHUTDOWN", 510i64).unwrap();
    array.insert("SIGNAL", 511i64).unwrap();
    array.insert("SIGNED", 512i64).unwrap();
    array.insert("SIMPLE", 513i64).unwrap();
    array.insert("SLAVE", 514i64).unwrap();
    array.insert("SLOW", 515i64).unwrap();
    array.insert("SMALLINT", 516i64).unwrap();
    array.insert("SNAPSHOT", 517i64).unwrap();
    array.insert("SOCKET", 519i64).unwrap();
    array.insert("SOME", 518i64).unwrap();
    array.insert("SONAME", 520i64).unwrap();
    array.insert("SOUNDS", 521i64).unwrap();
    array.insert("SOURCE", 522i64).unwrap();
    array.insert("SPATIAL", 523i64).unwrap();
    array.insert("SPECIFIC", 524i64).unwrap();
    array.insert("SQL", 537i64).unwrap();
    array.insert("SQL_AFTER_GTIDS", 528i64).unwrap();
    array.insert("SQL_AFTER_MTS_GAPS", 529i64).unwrap();
    array.insert("SQL_BEFORE_GTIDS", 530i64).unwrap();
    array.insert("SQL_BIG_RESULT", 531i64).unwrap();
    array.insert("SQL_BUFFER_RESULT", 532i64).unwrap();
    array.insert("SQL_CACHE", 533i64).unwrap();
    array.insert("SQL_CALC_FOUND_ROWS", 534i64).unwrap();
    array.insert("SQL_NO_CACHE", 535i64).unwrap();
    array.insert("SQL_SMALL_RESULT", 536i64).unwrap();
    array.insert("SQL_THREAD", 538i64).unwrap();
    array.insert("SQL_TSI_DAY", 802i64).unwrap();
    array.insert("SQL_TSI_HOUR", 803i64).unwrap();
    array.insert("SQL_TSI_MICROSECOND", 804i64).unwrap();
    array.insert("SQL_TSI_MINUTE", 805i64).unwrap();
    array.insert("SQL_TSI_MONTH", 806i64).unwrap();
    array.insert("SQL_TSI_QUARTER", 807i64).unwrap();
    array.insert("SQL_TSI_SECOND", 808i64).unwrap();
    array.insert("SQL_TSI_WEEK", 809i64).unwrap();
    array.insert("SQL_TSI_YEAR", 810i64).unwrap();
    array.insert("SQLEXCEPTION", 525i64).unwrap();
    array.insert("SQLSTATE", 526i64).unwrap();
    array.insert("SQLWARNING", 527i64).unwrap();
    array.insert("SSL", 539i64).unwrap();
    array.insert("STACKED", 540i64).unwrap();
    array.insert("START", 543i64).unwrap();
    array.insert("STARTING", 541i64).unwrap();
    array.insert("STARTS", 542i64).unwrap();
    array.insert("STATS_AUTO_RECALC", 544i64).unwrap();
    array.insert("STATS_PERSISTENT", 545i64).unwrap();
    array.insert("STATS_SAMPLE_PAGES", 546i64).unwrap();
    array.insert("STATUS", 547i64).unwrap();
    array.insert("STD", 551i64).unwrap();
    array.insert("STDDEV", 549i64).unwrap();
    array.insert("STDDEV_POP", 550i64).unwrap();
    array.insert("STDDEV_SAMP", 548i64).unwrap();
    array.insert("STOP", 552i64).unwrap();
    array.insert("STORAGE", 553i64).unwrap();
    array.insert("STORED", 554i64).unwrap();
    array.insert("STRAIGHT_JOIN", 555i64).unwrap();
    array.insert("STRING", 556i64).unwrap();
    array.insert("SUBCLASS_ORIGIN", 557i64).unwrap();
    array.insert("SUBDATE", 558i64).unwrap();
    array.insert("SUBJECT", 559i64).unwrap();
    array.insert("SUBPARTITION", 561i64).unwrap();
    array.insert("SUBPARTITIONS", 560i64).unwrap();
    array.insert("SUBSTR", 562i64).unwrap();
    array.insert("SUBSTRING", 563i64).unwrap();
    array.insert("SUM", 564i64).unwrap();
    array.insert("SUPER", 565i64).unwrap();
    array.insert("SUSPEND", 566i64).unwrap();
    array.insert("SWAPS", 567i64).unwrap();
    array.insert("SWITCHES", 568i64).unwrap();
    array.insert("SYSDATE", 569i64).unwrap();
    array.insert("SYSTEM_USER", 570i64).unwrap();
    array.insert("TABLE", 574i64).unwrap();
    array.insert("TABLE_CHECKSUM", 575i64).unwrap();
    array.insert("TABLE_NAME", 576i64).unwrap();
    array.insert("TABLE_REF_PRIORITY", 573i64).unwrap();
    array.insert("TABLES", 571i64).unwrap();
    array.insert("TABLESPACE", 572i64).unwrap();
    array.insert("TEMPORARY", 577i64).unwrap();
    array.insert("TEMPTABLE", 578i64).unwrap();
    array.insert("TERMINATED", 579i64).unwrap();
    array.insert("TEXT", 580i64).unwrap();
    array.insert("THAN", 581i64).unwrap();
    array.insert("THEN", 582i64).unwrap();
    array.insert("TIME", 586i64).unwrap();
    array.insert("TIMESTAMP", 583i64).unwrap();
    array.insert("TIMESTAMP_ADD", 584i64).unwrap();
    array.insert("TIMESTAMP_DIFF", 585i64).unwrap();
    array.insert("TINYBLOB", 587i64).unwrap();
    array.insert("TINYINT", 588i64).unwrap();
    array.insert("TINYTEXT", 589i64).unwrap();
    array.insert("TO", 590i64).unwrap();
    array.insert("TRAILING", 591i64).unwrap();
    array.insert("TRANSACTION", 592i64).unwrap();
    array.insert("TRIGGER", 594i64).unwrap();
    array.insert("TRIGGERS", 593i64).unwrap();
    array.insert("TRIM", 595i64).unwrap();
    array.insert("TRUE", 596i64).unwrap();
    array.insert("TRUNCATE", 597i64).unwrap();
    array.insert("TYPE", 599i64).unwrap();
    array.insert("TYPES", 598i64).unwrap();
    array.insert("UDF_RETURNS", 600i64).unwrap();
    array.insert("UNCOMMITTED", 601i64).unwrap();
    array.insert("UNDEFINED", 602i64).unwrap();
    array.insert("UNDO", 605i64).unwrap();
    array.insert("UNDO_BUFFER_SIZE", 604i64).unwrap();
    array.insert("UNDOFILE", 603i64).unwrap();
    array.insert("UNICODE", 606i64).unwrap();
    array.insert("UNINSTALL", 607i64).unwrap();
    array.insert("UNION", 608i64).unwrap();
    array.insert("UNIQUE", 609i64).unwrap();
    array.insert("UNKNOWN", 610i64).unwrap();
    array.insert("UNLOCK", 611i64).unwrap();
    array.insert("UNSIGNED", 612i64).unwrap();
    array.insert("UNTIL", 613i64).unwrap();
    array.insert("UPDATE", 614i64).unwrap();
    array.insert("UPGRADE", 615i64).unwrap();
    array.insert("USAGE", 616i64).unwrap();
    array.insert("USE", 620i64).unwrap();
    array.insert("USE_FRM", 619i64).unwrap();
    array.insert("USER", 618i64).unwrap();
    array.insert("USER_RESOURCES", 617i64).unwrap();
    array.insert("USING", 621i64).unwrap();
    array.insert("UTC_DATE", 622i64).unwrap();
    array.insert("UTC_TIME", 624i64).unwrap();
    array.insert("UTC_TIMESTAMP", 623i64).unwrap();
    array.insert("VALIDATION", 625i64).unwrap();
    array.insert("VALUE", 627i64).unwrap();
    array.insert("VALUES", 626i64).unwrap();
    array.insert("VAR_POP", 634i64).unwrap();
    array.insert("VAR_SAMP", 635i64).unwrap();
    array.insert("VARBINARY", 628i64).unwrap();
    array.insert("VARCHAR", 629i64).unwrap();
    array.insert("VARCHARACTER", 630i64).unwrap();
    array.insert("VARIABLES", 631i64).unwrap();
    array.insert("VARIANCE", 632i64).unwrap();
    array.insert("VARYING", 633i64).unwrap();
    array.insert("VIEW", 636i64).unwrap();
    array.insert("VIRTUAL", 637i64).unwrap();
    array.insert("WAIT", 638i64).unwrap();
    array.insert("WARNINGS", 639i64).unwrap();
    array.insert("WEEK", 640i64).unwrap();
    array.insert("WEIGHT_STRING", 641i64).unwrap();
    array.insert("WHEN", 642i64).unwrap();
    array.insert("WHERE", 643i64).unwrap();
    array.insert("WHILE", 644i64).unwrap();
    array.insert("WITH", 645i64).unwrap();
    array.insert("WITHOUT", 646i64).unwrap();
    array.insert("WORK", 647i64).unwrap();
    array.insert("WRAPPER", 648i64).unwrap();
    array.insert("WRITE", 649i64).unwrap();
    array.insert("X509", 650i64).unwrap();
    array.insert("XA", 651i64).unwrap();
    array.insert("XID", 652i64).unwrap();
    array.insert("XML", 653i64).unwrap();
    array.insert("XOR", 654i64).unwrap();
    array.insert("YEAR", 656i64).unwrap();
    array.insert("YEAR_MONTH", 655i64).unwrap();
    array.insert("ZEROFILL", 657i64).unwrap();
    array.insert("ACTIVE", 724i64).unwrap();
    array.insert("ADMIN", 660i64).unwrap();
    array.insert("ARRAY", 731i64).unwrap();
    array
        .insert("ASSIGN_GTIDS_TO_ANONYMOUS_TRANSACTIONS", 842i64)
        .unwrap();
    array.insert("BUCKETS", 675i64).unwrap();
    array.insert("CLONE", 677i64).unwrap();
    array.insert("COMPONENT", 664i64).unwrap();
    array.insert("CUME_DIST", 678i64).unwrap();
    array.insert("DEFINITION", 715i64).unwrap();
    array.insert("DENSE_RANK", 679i64).unwrap();
    array.insert("DESCRIPTION", 716i64).unwrap();
    array.insert("EMPTY", 700i64).unwrap();
    array.insert("ENFORCED", 730i64).unwrap();
    array.insert("ENGINE_ATTRIBUTE", 848i64).unwrap();
    array.insert("EXCEPT", 663i64).unwrap();
    array.insert("EXCLUDE", 680i64).unwrap();
    array.insert("FAILED_LOGIN_ATTEMPTS", 741i64).unwrap();
    array.insert("FIRST_VALUE", 681i64).unwrap();
    array.insert("FOLLOWING", 682i64).unwrap();
    array.insert("GET_MASTER_PUBLIC_KEY_SYM", 713i64).unwrap();
    array.insert("GET_SOURCE_PUBLIC_KEY", 840i64).unwrap();
    array.insert("GROUPING", 672i64).unwrap();
    array.insert("GROUPS", 683i64).unwrap();
    array.insert("GTID_ONLY", 841i64).unwrap();
    array.insert("HISTOGRAM", 674i64).unwrap();
    array.insert("HISTORY", 705i64).unwrap();
    array.insert("INACTIVE", 725i64).unwrap();
    array.insert("INTERSECT", 811i64).unwrap();
    array.insert("INVISIBLE", 661i64).unwrap();
    array.insert("JSON_ARRAYAGG", 667i64).unwrap();
    array.insert("JSON_OBJECTAGG", 666i64).unwrap();
    array.insert("JSON_TABLE", 701i64).unwrap();
    array.insert("JSON_VALUE", 850i64).unwrap();
    array.insert("KEYRING", 847i64).unwrap();
    array.insert("LAG", 684i64).unwrap();
    array.insert("LAST_VALUE", 685i64).unwrap();
    array.insert("LATERAL", 726i64).unwrap();
    array.insert("LEAD", 686i64).unwrap();
    array.insert("LOCKED", 670i64).unwrap();
    array
        .insert("MASTER_COMPRESSION_ALGORITHM", 735i64)
        .unwrap();
    array.insert("MASTER_PUBLIC_KEY_PATH", 712i64).unwrap();
    array.insert("MASTER_TLS_CIPHERSUITES", 738i64).unwrap();
    array
        .insert("MASTER_ZSTD_COMPRESSION_LEVEL", 736i64)
        .unwrap();
    array.insert("MEMBER", 733i64).unwrap();
    array.insert("NESTED", 702i64).unwrap();
    array.insert("NETWORK_NAMESPACE", 729i64).unwrap();
    array.insert("NOWAIT", 671i64).unwrap();
    array.insert("NTH_VALUE", 687i64).unwrap();
    array.insert("NTILE", 688i64).unwrap();
    array.insert("NULLS", 689i64).unwrap();
    array.insert("OF", 668i64).unwrap();
    array.insert("OFF", 744i64).unwrap();
    array.insert("OJ", 732i64).unwrap();
    array.insert("OLD", 728i64).unwrap();
    array.insert("OPTIONAL", 719i64).unwrap();
    array.insert("ORDINALITY", 703i64).unwrap();
    array.insert("ORGANIZATION", 717i64).unwrap();
    array.insert("OTHERS", 690i64).unwrap();
    array.insert("OVER", 691i64).unwrap();
    array.insert("PASSWORD_LOCK_TIME", 740i64).unwrap();
    array.insert("PATH", 704i64).unwrap();
    array.insert("PERCENT_RANK", 692i64).unwrap();
    array.insert("PERSIST", 658i64).unwrap();
    array.insert("PERSIST_ONLY", 673i64).unwrap();
    array.insert("PRECEDING", 693i64).unwrap();
    array.insert("PRIVILEGE_CHECKS_USER", 737i64).unwrap();
    array.insert("RANDOM", 734i64).unwrap();
    array.insert("RANK", 694i64).unwrap();
    array.insert("RECURSIVE", 665i64).unwrap();
    array.insert("REDO_LOG", 846i64).unwrap();
    array.insert("REFERENCE", 718i64).unwrap();
    array.insert("REMOTE", 676i64).unwrap();
    array.insert("REQUIRE_ROW_FORMAT", 739i64).unwrap();
    array
        .insert("REQUIRE_TABLE_PRIMARY_KEY_CHECK", 742i64)
        .unwrap();
    array.insert("RESOURCE", 709i64).unwrap();
    array.insert("RESPECT", 695i64).unwrap();
    array.insert("RESTART", 714i64).unwrap();
    array.insert("RETAIN", 727i64).unwrap();
    array.insert("RETURNING", 851i64).unwrap();
    array.insert("REUSE", 706i64).unwrap();
    array.insert("ROLE", 659i64).unwrap();
    array.insert("ROW_NUMBER", 696i64).unwrap();
    array.insert("SECONDARY", 720i64).unwrap();
    array.insert("SECONDARY_ENGINE", 721i64).unwrap();
    array.insert("SECONDARY_ENGINE_ATTRIBUTE", 849i64).unwrap();
    array.insert("SECONDARY_LOAD", 722i64).unwrap();
    array.insert("SECONDARY_UNLOAD", 723i64).unwrap();
    array.insert("SKIP", 669i64).unwrap();
    array.insert("SOURCE_AUTO_POSITION", 813i64).unwrap();
    array.insert("SOURCE_BIND", 814i64).unwrap();
    array
        .insert("SOURCE_COMPRESSION_ALGORITHM", 815i64)
        .unwrap();
    array.insert("SOURCE_CONNECT_RETRY", 816i64).unwrap();
    array
        .insert("SOURCE_CONNECTION_AUTO_FAILOVER", 817i64)
        .unwrap();
    array.insert("SOURCE_DELAY", 818i64).unwrap();
    array.insert("SOURCE_HEARTBEAT_PERIOD", 819i64).unwrap();
    array.insert("SOURCE_HOST", 820i64).unwrap();
    array.insert("SOURCE_LOG_FILE", 821i64).unwrap();
    array.insert("SOURCE_LOG_POS", 822i64).unwrap();
    array.insert("SOURCE_PASSWORD", 823i64).unwrap();
    array.insert("SOURCE_PORT", 824i64).unwrap();
    array.insert("SOURCE_PUBLIC_KEY_PATH", 825i64).unwrap();
    array.insert("SOURCE_RETRY_COUNT", 826i64).unwrap();
    array.insert("SOURCE_SSL", 827i64).unwrap();
    array.insert("SOURCE_SSL_CA", 828i64).unwrap();
    array.insert("SOURCE_SSL_CAPATH", 829i64).unwrap();
    array.insert("SOURCE_SSL_CERT", 830i64).unwrap();
    array.insert("SOURCE_SSL_CIPHER", 831i64).unwrap();
    array.insert("SOURCE_SSL_CRL", 832i64).unwrap();
    array.insert("SOURCE_SSL_CRLPATH", 833i64).unwrap();
    array.insert("SOURCE_SSL_KEY", 834i64).unwrap();
    array
        .insert("SOURCE_SSL_VERIFY_SERVER_CERT", 835i64)
        .unwrap();
    array.insert("SOURCE_TLS_CIPHERSUITES", 836i64).unwrap();
    array.insert("SOURCE_TLS_VERSION", 837i64).unwrap();
    array.insert("SOURCE_USER", 838i64).unwrap();
    array
        .insert("SOURCE_ZSTD_COMPRESSION_LEVEL", 839i64)
        .unwrap();
    array.insert("SRID", 707i64).unwrap();
    array.insert("STREAM", 743i64).unwrap();
    array.insert("SYSTEM", 710i64).unwrap();
    array.insert("THREAD_PRIORITY", 708i64).unwrap();
    array.insert("TIES", 697i64).unwrap();
    array.insert("TLS", 845i64).unwrap();
    array.insert("UNBOUNDED", 698i64).unwrap();
    array.insert("VCPU", 711i64).unwrap();
    array.insert("VISIBLE", 662i64).unwrap();
    array.insert("WINDOW", 699i64).unwrap();
    array.insert("ZONE", 843i64).unwrap();
    freeze_array(&mut array);
    array
}

fn array_functions() -> ZBox<ZendHashTable> {
    let mut array = persistent_array(34);
    array.insert_at_index(5i64, true).unwrap();
    array.insert_at_index(35i64, true).unwrap();
    array.insert_at_index(36i64, true).unwrap();
    array.insert_at_index(38i64, true).unwrap();
    array.insert_at_index(52i64, true).unwrap();
    array.insert_at_index(95i64, true).unwrap();
    array.insert_at_index(100i64, true).unwrap();
    array.insert_at_index(102i64, true).unwrap();
    array.insert_at_index(103i64, true).unwrap();
    array.insert_at_index(108i64, true).unwrap();
    array.insert_at_index(114i64, true).unwrap();
    array.insert_at_index(115i64, true).unwrap();
    array.insert_at_index(182i64, true).unwrap();
    array.insert_at_index(218i64, true).unwrap();
    array.insert_at_index(326i64, true).unwrap();
    array.insert_at_index(338i64, true).unwrap();
    array.insert_at_index(345i64, true).unwrap();
    array.insert_at_index(372i64, true).unwrap();
    array.insert_at_index(414i64, true).unwrap();
    array.insert_at_index(505i64, true).unwrap();
    array.insert_at_index(551i64, true).unwrap();
    array.insert_at_index(550i64, true).unwrap();
    array.insert_at_index(548i64, true).unwrap();
    array.insert_at_index(549i64, true).unwrap();
    array.insert_at_index(558i64, true).unwrap();
    array.insert_at_index(562i64, true).unwrap();
    array.insert_at_index(563i64, true).unwrap();
    array.insert_at_index(564i64, true).unwrap();
    array.insert_at_index(569i64, true).unwrap();
    array.insert_at_index(570i64, true).unwrap();
    array.insert_at_index(595i64, true).unwrap();
    array.insert_at_index(634i64, true).unwrap();
    array.insert_at_index(635i64, true).unwrap();
    array.insert_at_index(632i64, true).unwrap();
    freeze_array(&mut array);
    array
}

fn array_synonyms() -> ZBox<ZendHashTable> {
    let mut array = persistent_array(43);
    array.insert_at_index(59i64, 60i64).unwrap();
    array.insert_at_index(102i64, 100i64).unwrap();
    array.insert_at_index(103i64, 108i64).unwrap();
    array.insert_at_index(104i64, 372i64).unwrap();
    array.insert_at_index(117i64, 122i64).unwrap();
    array.insert_at_index(124i64, 126i64).unwrap();
    array.insert_at_index(144i64, 143i64).unwrap();
    array.insert_at_index(187i64, 71i64).unwrap();
    array.insert_at_index(193i64, 195i64).unwrap();
    array.insert_at_index(194i64, 146i64).unwrap();
    array.insert_at_index(852i64, 211i64).unwrap();
    array.insert_at_index(795i64, 588i64).unwrap();
    array.insert_at_index(796i64, 516i64).unwrap();
    array.insert_at_index(797i64, 331i64).unwrap();
    array.insert_at_index(798i64, 249i64).unwrap();
    array.insert_at_index(799i64, 31i64).unwrap();
    array.insert_at_index(246i64, 249i64).unwrap();
    array.insert_at_index(254i64, 449i64).unwrap();
    array.insert_at_index(282i64, 372i64).unwrap();
    array.insert_at_index(283i64, 372i64).unwrap();
    array.insert_at_index(338i64, 563i64).unwrap();
    array.insert_at_index(339i64, 331i64).unwrap();
    array.insert_at_index(362i64, 363i64).unwrap();
    array.insert_at_index(479i64, 444i64).unwrap();
    array.insert_at_index(491i64, 109i64).unwrap();
    array.insert_at_index(493i64, 110i64).unwrap();
    array.insert_at_index(505i64, 618i64).unwrap();
    array.insert_at_index(518i64, 16i64).unwrap();
    array.insert_at_index(802i64, 122i64).unwrap();
    array.insert_at_index(803i64, 229i64).unwrap();
    array.insert_at_index(804i64, 337i64).unwrap();
    array.insert_at_index(805i64, 343i64).unwrap();
    array.insert_at_index(806i64, 350i64).unwrap();
    array.insert_at_index(807i64, 429i64).unwrap();
    array.insert_at_index(808i64, 495i64).unwrap();
    array.insert_at_index(809i64, 640i64).unwrap();
    array.insert_at_index(810i64, 656i64).unwrap();
    array.insert_at_index(550i64, 551i64).unwrap();
    array.insert_at_index(549i64, 551i64).unwrap();
    array.insert_at_index(562i64, 563i64).unwrap();
    array.insert_at_index(570i64, 618i64).unwrap();
    array.insert_at_index(634i64, 632i64).unwrap();
    array.insert_at_index(630i64, 629i64).unwrap();
    freeze_array(&mut array);
    array
}

fn array_versions() -> ZBox<ZendHashTable> {
    let mut array = persistent_array(179);
    array.insert_at_index(2i64, 50707i64).unwrap();
    array.insert_at_index(12i64, 50707i64).unwrap();
    array.insert_at_index(13i64, -80000i64).unwrap();
    array.insert_at_index(22i64, -50700i64).unwrap();
    array.insert_at_index(57i64, 50706i64).unwrap();
    array.insert_at_index(81i64, 50707i64).unwrap();
    array.insert_at_index(93i64, -50700i64).unwrap();
    array.insert_at_index(101i64, 50604i64).unwrap();
    array.insert_at_index(129i64, 50604i64).unwrap();
    array.insert_at_index(136i64, -80003i64).unwrap();
    array.insert_at_index(158i64, 50711i64).unwrap();
    array.insert_at_index(177i64, 50606i64).unwrap();
    array.insert_at_index(179i64, 50606i64).unwrap();
    array.insert_at_index(189i64, 50707i64).unwrap();
    array.insert_at_index(190i64, 50700i64).unwrap();
    array.insert_at_index(197i64, 50700i64).unwrap();
    array.insert_at_index(209i64, 50707i64).unwrap();
    array.insert_at_index(207i64, 50604i64).unwrap();
    array.insert_at_index(210i64, 50707i64).unwrap();
    array.insert_at_index(844i64, 50711i64).unwrap();
    array.insert_at_index(244i64, 50713i64).unwrap();
    array.insert_at_index(262i64, 50708i64).unwrap();
    array.insert_at_index(296i64, 50605i64).unwrap();
    array.insert_at_index(297i64, 50602i64).unwrap();
    array.insert_at_index(305i64, 50601i64).unwrap();
    array.insert_at_index(311i64, 50603i64).unwrap();
    array.insert_at_index(312i64, 50603i64).unwrap();
    array.insert_at_index(317i64, 50713i64).unwrap();
    array.insert_at_index(365i64, 50704i64).unwrap();
    array.insert_at_index(377i64, 50606i64).unwrap();
    array.insert_at_index(382i64, -50706i64).unwrap();
    array.insert_at_index(386i64, 50605i64).unwrap();
    array.insert_at_index(389i64, 50706i64).unwrap();
    array.insert_at_index(409i64, 50604i64).unwrap();
    array.insert_at_index(415i64, 50700i64).unwrap();
    array.insert_at_index(440i64, -80000i64).unwrap();
    array.insert_at_index(460i64, 50700i64).unwrap();
    array.insert_at_index(462i64, 50700i64).unwrap();
    array.insert_at_index(461i64, 50700i64).unwrap();
    array.insert_at_index(463i64, 50700i64).unwrap();
    array.insert_at_index(466i64, 50700i64).unwrap();
    array.insert_at_index(464i64, 50700i64).unwrap();
    array.insert_at_index(465i64, 50700i64).unwrap();
    array.insert_at_index(482i64, 50713i64).unwrap();
    array.insert_at_index(529i64, 50606i64).unwrap();
    array.insert_at_index(533i64, -80000i64).unwrap();
    array.insert_at_index(540i64, 50700i64).unwrap();
    array.insert_at_index(554i64, 50707i64).unwrap();
    array.insert_at_index(573i64, -80000i64).unwrap();
    array.insert_at_index(625i64, 50706i64).unwrap();
    array.insert_at_index(637i64, 50707i64).unwrap();
    array.insert_at_index(652i64, 50704i64).unwrap();
    array.insert_at_index(724i64, 80014i64).unwrap();
    array.insert_at_index(660i64, 80000i64).unwrap();
    array.insert_at_index(731i64, 80017i64).unwrap();
    array.insert_at_index(842i64, 80000i64).unwrap();
    array.insert_at_index(812i64, 80021i64).unwrap();
    array.insert_at_index(675i64, 80000i64).unwrap();
    array.insert_at_index(677i64, 80000i64).unwrap();
    array.insert_at_index(664i64, 80000i64).unwrap();
    array.insert_at_index(678i64, 80000i64).unwrap();
    array.insert_at_index(715i64, 80011i64).unwrap();
    array.insert_at_index(679i64, 80000i64).unwrap();
    array.insert_at_index(716i64, 80011i64).unwrap();
    array.insert_at_index(700i64, 80000i64).unwrap();
    array.insert_at_index(730i64, 80017i64).unwrap();
    array.insert_at_index(848i64, 80021i64).unwrap();
    array.insert_at_index(663i64, 80000i64).unwrap();
    array.insert_at_index(680i64, 80000i64).unwrap();
    array.insert_at_index(741i64, 80019i64).unwrap();
    array.insert_at_index(681i64, 80000i64).unwrap();
    array.insert_at_index(682i64, 80000i64).unwrap();
    array.insert_at_index(852i64, 80000i64).unwrap();
    array.insert_at_index(713i64, 80000i64).unwrap();
    array.insert_at_index(840i64, 80000i64).unwrap();
    array.insert_at_index(672i64, 80000i64).unwrap();
    array.insert_at_index(683i64, 80000i64).unwrap();
    array.insert_at_index(841i64, 80000i64).unwrap();
    array.insert_at_index(674i64, 80000i64).unwrap();
    array.insert_at_index(705i64, 80000i64).unwrap();
    array.insert_at_index(725i64, 80014i64).unwrap();
    array.insert_at_index(811i64, 80031i64).unwrap();
    array.insert_at_index(661i64, 80000i64).unwrap();
    array.insert_at_index(667i64, 80000i64).unwrap();
    array.insert_at_index(666i64, 80000i64).unwrap();
    array.insert_at_index(701i64, 80000i64).unwrap();
    array.insert_at_index(850i64, 80021i64).unwrap();
    array.insert_at_index(847i64, 80024i64).unwrap();
    array.insert_at_index(684i64, 80000i64).unwrap();
    array.insert_at_index(685i64, 80000i64).unwrap();
    array.insert_at_index(726i64, 80014i64).unwrap();
    array.insert_at_index(686i64, 80000i64).unwrap();
    array.insert_at_index(670i64, 80000i64).unwrap();
    array.insert_at_index(735i64, 80018i64).unwrap();
    array.insert_at_index(712i64, 80000i64).unwrap();
    array.insert_at_index(738i64, 80018i64).unwrap();
    array.insert_at_index(736i64, 80018i64).unwrap();
    array.insert_at_index(733i64, 80017i64).unwrap();
    array.insert_at_index(702i64, 80000i64).unwrap();
    array.insert_at_index(729i64, 80017i64).unwrap();
    array.insert_at_index(671i64, 80000i64).unwrap();
    array.insert_at_index(687i64, 80000i64).unwrap();
    array.insert_at_index(688i64, 80000i64).unwrap();
    array.insert_at_index(689i64, 80000i64).unwrap();
    array.insert_at_index(668i64, 80000i64).unwrap();
    array.insert_at_index(744i64, 80019i64).unwrap();
    array.insert_at_index(732i64, 80017i64).unwrap();
    array.insert_at_index(728i64, 80014i64).unwrap();
    array.insert_at_index(719i64, 80013i64).unwrap();
    array.insert_at_index(703i64, 80000i64).unwrap();
    array.insert_at_index(717i64, 80011i64).unwrap();
    array.insert_at_index(690i64, 80000i64).unwrap();
    array.insert_at_index(691i64, 80000i64).unwrap();
    array.insert_at_index(740i64, 80019i64).unwrap();
    array.insert_at_index(704i64, 80000i64).unwrap();
    array.insert_at_index(692i64, 80000i64).unwrap();
    array.insert_at_index(673i64, 80000i64).unwrap();
    array.insert_at_index(658i64, 80000i64).unwrap();
    array.insert_at_index(693i64, 80000i64).unwrap();
    array.insert_at_index(737i64, 80018i64).unwrap();
    array.insert_at_index(734i64, 80018i64).unwrap();
    array.insert_at_index(694i64, 80000i64).unwrap();
    array.insert_at_index(665i64, 80000i64).unwrap();
    array.insert_at_index(846i64, 80021i64).unwrap();
    array.insert_at_index(718i64, 80011i64).unwrap();
    array.insert_at_index(739i64, 80019i64).unwrap();
    array.insert_at_index(742i64, 80019i64).unwrap();
    array.insert_at_index(709i64, 80000i64).unwrap();
    array.insert_at_index(695i64, 80000i64).unwrap();
    array.insert_at_index(714i64, 80011i64).unwrap();
    array.insert_at_index(727i64, 80014i64).unwrap();
    array.insert_at_index(706i64, 80000i64).unwrap();
    array.insert_at_index(851i64, 80021i64).unwrap();
    array.insert_at_index(659i64, 80000i64).unwrap();
    array.insert_at_index(696i64, 80000i64).unwrap();
    array.insert_at_index(849i64, 80021i64).unwrap();
    array.insert_at_index(721i64, 80013i64).unwrap();
    array.insert_at_index(722i64, 80013i64).unwrap();
    array.insert_at_index(720i64, 80013i64).unwrap();
    array.insert_at_index(723i64, 80013i64).unwrap();
    array.insert_at_index(669i64, 80000i64).unwrap();
    array.insert_at_index(813i64, 80000i64).unwrap();
    array.insert_at_index(814i64, 80000i64).unwrap();
    array.insert_at_index(815i64, 80000i64).unwrap();
    array.insert_at_index(816i64, 80000i64).unwrap();
    array.insert_at_index(817i64, 80000i64).unwrap();
    array.insert_at_index(818i64, 80000i64).unwrap();
    array.insert_at_index(819i64, 80000i64).unwrap();
    array.insert_at_index(820i64, 80000i64).unwrap();
    array.insert_at_index(821i64, 80000i64).unwrap();
    array.insert_at_index(822i64, 80000i64).unwrap();
    array.insert_at_index(823i64, 80000i64).unwrap();
    array.insert_at_index(824i64, 80000i64).unwrap();
    array.insert_at_index(825i64, 80000i64).unwrap();
    array.insert_at_index(826i64, 80000i64).unwrap();
    array.insert_at_index(828i64, 80000i64).unwrap();
    array.insert_at_index(829i64, 80000i64).unwrap();
    array.insert_at_index(830i64, 80000i64).unwrap();
    array.insert_at_index(831i64, 80000i64).unwrap();
    array.insert_at_index(832i64, 80000i64).unwrap();
    array.insert_at_index(833i64, 80000i64).unwrap();
    array.insert_at_index(834i64, 80000i64).unwrap();
    array.insert_at_index(827i64, 80000i64).unwrap();
    array.insert_at_index(835i64, 80000i64).unwrap();
    array.insert_at_index(836i64, 80000i64).unwrap();
    array.insert_at_index(837i64, 80000i64).unwrap();
    array.insert_at_index(838i64, 80000i64).unwrap();
    array.insert_at_index(839i64, 80000i64).unwrap();
    array.insert_at_index(707i64, 80000i64).unwrap();
    array.insert_at_index(743i64, 80019i64).unwrap();
    array.insert_at_index(710i64, 80000i64).unwrap();
    array.insert_at_index(708i64, 80000i64).unwrap();
    array.insert_at_index(697i64, 80000i64).unwrap();
    array.insert_at_index(845i64, 80016i64).unwrap();
    array.insert_at_index(698i64, 80000i64).unwrap();
    array.insert_at_index(711i64, 80000i64).unwrap();
    array.insert_at_index(662i64, 80000i64).unwrap();
    array.insert_at_index(699i64, 80000i64).unwrap();
    array.insert_at_index(843i64, 80022i64).unwrap();
    freeze_array(&mut array);
    array
}

fn array_underscore_charsets() -> ZBox<ZendHashTable> {
    let mut array = persistent_array(42);
    array.insert("_armscii8", true).unwrap();
    array.insert("_ascii", true).unwrap();
    array.insert("_big5", true).unwrap();
    array.insert("_binary", true).unwrap();
    array.insert("_cp1250", true).unwrap();
    array.insert("_cp1251", true).unwrap();
    array.insert("_cp1256", true).unwrap();
    array.insert("_cp1257", true).unwrap();
    array.insert("_cp850", true).unwrap();
    array.insert("_cp852", true).unwrap();
    array.insert("_cp866", true).unwrap();
    array.insert("_cp932", true).unwrap();
    array.insert("_dec8", true).unwrap();
    array.insert("_eucjpms", true).unwrap();
    array.insert("_euckr", true).unwrap();
    array.insert("_gb18030", true).unwrap();
    array.insert("_gb2312", true).unwrap();
    array.insert("_gbk", true).unwrap();
    array.insert("_geostd8", true).unwrap();
    array.insert("_greek", true).unwrap();
    array.insert("_hebrew", true).unwrap();
    array.insert("_hp8", true).unwrap();
    array.insert("_keybcs2", true).unwrap();
    array.insert("_koi8r", true).unwrap();
    array.insert("_koi8u", true).unwrap();
    array.insert("_latin1", true).unwrap();
    array.insert("_latin2", true).unwrap();
    array.insert("_latin5", true).unwrap();
    array.insert("_latin7", true).unwrap();
    array.insert("_macce", true).unwrap();
    array.insert("_macroman", true).unwrap();
    array.insert("_sjis", true).unwrap();
    array.insert("_swe7", true).unwrap();
    array.insert("_tis620", true).unwrap();
    array.insert("_ucs2", true).unwrap();
    array.insert("_ujis", true).unwrap();
    array.insert("_utf16", true).unwrap();
    array.insert("_utf16le", true).unwrap();
    array.insert("_utf32", true).unwrap();
    array.insert("_utf8", true).unwrap();
    array.insert("_utf8mb3", true).unwrap();
    array.insert("_utf8mb4", true).unwrap();
    freeze_array(&mut array);
    array
}

pub const SCALAR_INT_CONSTANTS: &[(&str, i64)] = &[
    ("SQL_MODE_HIGH_NOT_PRECEDENCE", 1i64),
    ("SQL_MODE_PIPES_AS_CONCAT", 2i64),
    ("SQL_MODE_IGNORE_SPACE", 4i64),
    ("SQL_MODE_NO_BACKSLASH_ESCAPES", 8i64),
    ("ACCESSIBLE_SYMBOL", 1i64),
    ("ACCOUNT_SYMBOL", 2i64),
    ("ACTION_SYMBOL", 3i64),
    ("ADD_SYMBOL", 4i64),
    ("ADDDATE_SYMBOL", 5i64),
    ("AFTER_SYMBOL", 6i64),
    ("AGAINST_SYMBOL", 7i64),
    ("AGGREGATE_SYMBOL", 8i64),
    ("ALGORITHM_SYMBOL", 9i64),
    ("ALL_SYMBOL", 10i64),
    ("ALTER_SYMBOL", 11i64),
    ("ALWAYS_SYMBOL", 12i64),
    ("ANALYSE_SYMBOL", 13i64),
    ("ANALYZE_SYMBOL", 14i64),
    ("AND_SYMBOL", 15i64),
    ("ANY_SYMBOL", 16i64),
    ("AS_SYMBOL", 17i64),
    ("ASC_SYMBOL", 18i64),
    ("ASCII_SYMBOL", 19i64),
    ("ASENSITIVE_SYMBOL", 20i64),
    ("AT_SYMBOL", 21i64),
    ("AUTHORS_SYMBOL", 22i64),
    ("AUTOEXTEND_SIZE_SYMBOL", 23i64),
    ("AUTO_INCREMENT_SYMBOL", 24i64),
    ("AVG_ROW_LENGTH_SYMBOL", 25i64),
    ("AVG_SYMBOL", 26i64),
    ("BACKUP_SYMBOL", 27i64),
    ("BEFORE_SYMBOL", 28i64),
    ("BEGIN_SYMBOL", 29i64),
    ("BETWEEN_SYMBOL", 30i64),
    ("BIGINT_SYMBOL", 31i64),
    ("BINARY_SYMBOL", 32i64),
    ("BINLOG_SYMBOL", 33i64),
    ("BIN_NUM_SYMBOL", 34i64),
    ("BIT_AND_SYMBOL", 35i64),
    ("BIT_OR_SYMBOL", 36i64),
    ("BIT_SYMBOL", 37i64),
    ("BIT_XOR_SYMBOL", 38i64),
    ("BLOB_SYMBOL", 39i64),
    ("BLOCK_SYMBOL", 40i64),
    ("BOOLEAN_SYMBOL", 41i64),
    ("BOOL_SYMBOL", 42i64),
    ("BOTH_SYMBOL", 43i64),
    ("BTREE_SYMBOL", 44i64),
    ("BY_SYMBOL", 45i64),
    ("BYTE_SYMBOL", 46i64),
    ("CACHE_SYMBOL", 47i64),
    ("CALL_SYMBOL", 48i64),
    ("CASCADE_SYMBOL", 49i64),
    ("CASCADED_SYMBOL", 50i64),
    ("CASE_SYMBOL", 51i64),
    ("CAST_SYMBOL", 52i64),
    ("CATALOG_NAME_SYMBOL", 53i64),
    ("CHAIN_SYMBOL", 54i64),
    ("CHANGE_SYMBOL", 55i64),
    ("CHANGED_SYMBOL", 56i64),
    ("CHANNEL_SYMBOL", 57i64),
    ("CHARSET_SYMBOL", 58i64),
    ("CHARACTER_SYMBOL", 59i64),
    ("CHAR_SYMBOL", 60i64),
    ("CHECKSUM_SYMBOL", 61i64),
    ("CHECK_SYMBOL", 62i64),
    ("CIPHER_SYMBOL", 63i64),
    ("CLASS_ORIGIN_SYMBOL", 64i64),
    ("CLIENT_SYMBOL", 65i64),
    ("CLOSE_SYMBOL", 66i64),
    ("COALESCE_SYMBOL", 67i64),
    ("CODE_SYMBOL", 68i64),
    ("COLLATE_SYMBOL", 69i64),
    ("COLLATION_SYMBOL", 70i64),
    ("COLUMNS_SYMBOL", 71i64),
    ("COLUMN_SYMBOL", 72i64),
    ("COLUMN_NAME_SYMBOL", 73i64),
    ("COLUMN_FORMAT_SYMBOL", 74i64),
    ("COMMENT_SYMBOL", 75i64),
    ("COMMITTED_SYMBOL", 76i64),
    ("COMMIT_SYMBOL", 77i64),
    ("COMPACT_SYMBOL", 78i64),
    ("COMPLETION_SYMBOL", 79i64),
    ("COMPRESSED_SYMBOL", 80i64),
    ("COMPRESSION_SYMBOL", 81i64),
    ("CONCURRENT_SYMBOL", 82i64),
    ("CONDITION_SYMBOL", 83i64),
    ("CONNECTION_SYMBOL", 84i64),
    ("CONSISTENT_SYMBOL", 85i64),
    ("CONSTRAINT_SYMBOL", 86i64),
    ("CONSTRAINT_CATALOG_SYMBOL", 87i64),
    ("CONSTRAINT_NAME_SYMBOL", 88i64),
    ("CONSTRAINT_SCHEMA_SYMBOL", 89i64),
    ("CONTAINS_SYMBOL", 90i64),
    ("CONTEXT_SYMBOL", 91i64),
    ("CONTINUE_SYMBOL", 92i64),
    ("CONTRIBUTORS_SYMBOL", 93i64),
    ("CONVERT_SYMBOL", 94i64),
    ("COUNT_SYMBOL", 95i64),
    ("CPU_SYMBOL", 96i64),
    ("CREATE_SYMBOL", 97i64),
    ("CROSS_SYMBOL", 98i64),
    ("CUBE_SYMBOL", 99i64),
    ("CURDATE_SYMBOL", 100i64),
    ("CURRENT_SYMBOL", 101i64),
    ("CURRENT_DATE_SYMBOL", 102i64),
    ("CURRENT_TIME_SYMBOL", 103i64),
    ("CURRENT_TIMESTAMP_SYMBOL", 104i64),
    ("CURRENT_USER_SYMBOL", 105i64),
    ("CURSOR_SYMBOL", 106i64),
    ("CURSOR_NAME_SYMBOL", 107i64),
    ("CURTIME_SYMBOL", 108i64),
    ("DATABASE_SYMBOL", 109i64),
    ("DATABASES_SYMBOL", 110i64),
    ("DATAFILE_SYMBOL", 111i64),
    ("DATA_SYMBOL", 112i64),
    ("DATETIME_SYMBOL", 113i64),
    ("DATE_ADD_SYMBOL", 114i64),
    ("DATE_SUB_SYMBOL", 115i64),
    ("DATE_SYMBOL", 116i64),
    ("DAYOFMONTH_SYMBOL", 117i64),
    ("DAY_HOUR_SYMBOL", 118i64),
    ("DAY_MICROSECOND_SYMBOL", 119i64),
    ("DAY_MINUTE_SYMBOL", 120i64),
    ("DAY_SECOND_SYMBOL", 121i64),
    ("DAY_SYMBOL", 122i64),
    ("DEALLOCATE_SYMBOL", 123i64),
    ("DEC_SYMBOL", 124i64),
    ("DECIMAL_NUM_SYMBOL", 125i64),
    ("DECIMAL_SYMBOL", 126i64),
    ("DECLARE_SYMBOL", 127i64),
    ("DEFAULT_SYMBOL", 128i64),
    ("DEFAULT_AUTH_SYMBOL", 129i64),
    ("DEFINER_SYMBOL", 130i64),
    ("DELAYED_SYMBOL", 131i64),
    ("DELAY_KEY_WRITE_SYMBOL", 132i64),
    ("DELETE_SYMBOL", 133i64),
    ("DESC_SYMBOL", 134i64),
    ("DESCRIBE_SYMBOL", 135i64),
    ("DES_KEY_FILE_SYMBOL", 136i64),
    ("DETERMINISTIC_SYMBOL", 137i64),
    ("DIAGNOSTICS_SYMBOL", 138i64),
    ("DIRECTORY_SYMBOL", 139i64),
    ("DISABLE_SYMBOL", 140i64),
    ("DISCARD_SYMBOL", 141i64),
    ("DISK_SYMBOL", 142i64),
    ("DISTINCT_SYMBOL", 143i64),
    ("DISTINCTROW_SYMBOL", 144i64),
    ("DIV_SYMBOL", 145i64),
    ("DOUBLE_SYMBOL", 146i64),
    ("DO_SYMBOL", 147i64),
    ("DROP_SYMBOL", 148i64),
    ("DUAL_SYMBOL", 149i64),
    ("DUMPFILE_SYMBOL", 150i64),
    ("DUPLICATE_SYMBOL", 151i64),
    ("DYNAMIC_SYMBOL", 152i64),
    ("EACH_SYMBOL", 153i64),
    ("ELSE_SYMBOL", 154i64),
    ("ELSEIF_SYMBOL", 155i64),
    ("ENABLE_SYMBOL", 156i64),
    ("ENCLOSED_SYMBOL", 157i64),
    ("ENCRYPTION_SYMBOL", 158i64),
    ("END_SYMBOL", 159i64),
    ("ENDS_SYMBOL", 160i64),
    ("END_OF_INPUT_SYMBOL", 161i64),
    ("ENGINES_SYMBOL", 162i64),
    ("ENGINE_SYMBOL", 163i64),
    ("ENUM_SYMBOL", 164i64),
    ("ERROR_SYMBOL", 165i64),
    ("ERRORS_SYMBOL", 166i64),
    ("ESCAPED_SYMBOL", 167i64),
    ("ESCAPE_SYMBOL", 168i64),
    ("EVENTS_SYMBOL", 169i64),
    ("EVENT_SYMBOL", 170i64),
    ("EVERY_SYMBOL", 171i64),
    ("EXCHANGE_SYMBOL", 172i64),
    ("EXECUTE_SYMBOL", 173i64),
    ("EXISTS_SYMBOL", 174i64),
    ("EXIT_SYMBOL", 175i64),
    ("EXPANSION_SYMBOL", 176i64),
    ("EXPIRE_SYMBOL", 177i64),
    ("EXPLAIN_SYMBOL", 178i64),
    ("EXPORT_SYMBOL", 179i64),
    ("EXTENDED_SYMBOL", 180i64),
    ("EXTENT_SIZE_SYMBOL", 181i64),
    ("EXTRACT_SYMBOL", 182i64),
    ("FALSE_SYMBOL", 183i64),
    ("FAST_SYMBOL", 184i64),
    ("FAULTS_SYMBOL", 185i64),
    ("FETCH_SYMBOL", 186i64),
    ("FIELDS_SYMBOL", 187i64),
    ("FILE_SYMBOL", 188i64),
    ("FILE_BLOCK_SIZE_SYMBOL", 189i64),
    ("FILTER_SYMBOL", 190i64),
    ("FIRST_SYMBOL", 191i64),
    ("FIXED_SYMBOL", 192i64),
    ("FLOAT4_SYMBOL", 193i64),
    ("FLOAT8_SYMBOL", 194i64),
    ("FLOAT_SYMBOL", 195i64),
    ("FLUSH_SYMBOL", 196i64),
    ("FOLLOWS_SYMBOL", 197i64),
    ("FORCE_SYMBOL", 198i64),
    ("FOREIGN_SYMBOL", 199i64),
    ("FOR_SYMBOL", 200i64),
    ("FORMAT_SYMBOL", 201i64),
    ("FOUND_SYMBOL", 202i64),
    ("FROM_SYMBOL", 203i64),
    ("FULL_SYMBOL", 204i64),
    ("FULLTEXT_SYMBOL", 205i64),
    ("FUNCTION_SYMBOL", 206i64),
    ("GET_SYMBOL", 207i64),
    ("GENERAL_SYMBOL", 208i64),
    ("GENERATED_SYMBOL", 209i64),
    ("GROUP_REPLICATION_SYMBOL", 210i64),
    ("GEOMETRYCOLLECTION_SYMBOL", 211i64),
    ("GEOMETRY_SYMBOL", 212i64),
    ("GET_FORMAT_SYMBOL", 213i64),
    ("GLOBAL_SYMBOL", 214i64),
    ("GRANT_SYMBOL", 215i64),
    ("GRANTS_SYMBOL", 216i64),
    ("GROUP_SYMBOL", 217i64),
    ("GROUP_CONCAT_SYMBOL", 218i64),
    ("HANDLER_SYMBOL", 219i64),
    ("HASH_SYMBOL", 220i64),
    ("HAVING_SYMBOL", 221i64),
    ("HELP_SYMBOL", 222i64),
    ("HIGH_PRIORITY_SYMBOL", 223i64),
    ("HOST_SYMBOL", 224i64),
    ("HOSTS_SYMBOL", 225i64),
    ("HOUR_MICROSECOND_SYMBOL", 226i64),
    ("HOUR_MINUTE_SYMBOL", 227i64),
    ("HOUR_SECOND_SYMBOL", 228i64),
    ("HOUR_SYMBOL", 229i64),
    ("IDENTIFIED_SYMBOL", 230i64),
    ("IF_SYMBOL", 231i64),
    ("IGNORE_SYMBOL", 232i64),
    ("IGNORE_SERVER_IDS_SYMBOL", 233i64),
    ("IMPORT_SYMBOL", 234i64),
    ("INDEXES_SYMBOL", 235i64),
    ("INDEX_SYMBOL", 236i64),
    ("INFILE_SYMBOL", 237i64),
    ("INITIAL_SIZE_SYMBOL", 238i64),
    ("INNER_SYMBOL", 239i64),
    ("INOUT_SYMBOL", 240i64),
    ("INSENSITIVE_SYMBOL", 241i64),
    ("INSERT_SYMBOL", 242i64),
    ("INSERT_METHOD_SYMBOL", 243i64),
    ("INSTANCE_SYMBOL", 244i64),
    ("INSTALL_SYMBOL", 245i64),
    ("INTEGER_SYMBOL", 246i64),
    ("INTERVAL_SYMBOL", 247i64),
    ("INTO_SYMBOL", 248i64),
    ("INT_SYMBOL", 249i64),
    ("INVOKER_SYMBOL", 250i64),
    ("IN_SYMBOL", 251i64),
    ("IO_AFTER_GTIDS_SYMBOL", 252i64),
    ("IO_BEFORE_GTIDS_SYMBOL", 253i64),
    ("IO_THREAD_SYMBOL", 254i64),
    ("IO_SYMBOL", 255i64),
    ("IPC_SYMBOL", 256i64),
    ("IS_SYMBOL", 257i64),
    ("ISOLATION_SYMBOL", 258i64),
    ("ISSUER_SYMBOL", 259i64),
    ("ITERATE_SYMBOL", 260i64),
    ("JOIN_SYMBOL", 261i64),
    ("JSON_SYMBOL", 262i64),
    ("KEYS_SYMBOL", 263i64),
    ("KEY_BLOCK_SIZE_SYMBOL", 264i64),
    ("KEY_SYMBOL", 265i64),
    ("KILL_SYMBOL", 266i64),
    ("LANGUAGE_SYMBOL", 267i64),
    ("LAST_SYMBOL", 268i64),
    ("LEADING_SYMBOL", 269i64),
    ("LEAVES_SYMBOL", 270i64),
    ("LEAVE_SYMBOL", 271i64),
    ("LEFT_SYMBOL", 272i64),
    ("LESS_SYMBOL", 273i64),
    ("LEVEL_SYMBOL", 274i64),
    ("LIKE_SYMBOL", 275i64),
    ("LIMIT_SYMBOL", 276i64),
    ("LINEAR_SYMBOL", 277i64),
    ("LINES_SYMBOL", 278i64),
    ("LINESTRING_SYMBOL", 279i64),
    ("LIST_SYMBOL", 280i64),
    ("LOAD_SYMBOL", 281i64),
    ("LOCALTIME_SYMBOL", 282i64),
    ("LOCALTIMESTAMP_SYMBOL", 283i64),
    ("LOCAL_SYMBOL", 284i64),
    ("LOCATOR_SYMBOL", 285i64),
    ("LOCKS_SYMBOL", 286i64),
    ("LOCK_SYMBOL", 287i64),
    ("LOGFILE_SYMBOL", 288i64),
    ("LOGS_SYMBOL", 289i64),
    ("LONGBLOB_SYMBOL", 290i64),
    ("LONGTEXT_SYMBOL", 291i64),
    ("LONG_NUM_SYMBOL", 292i64),
    ("LONG_SYMBOL", 293i64),
    ("LOOP_SYMBOL", 294i64),
    ("LOW_PRIORITY_SYMBOL", 295i64),
    ("MASTER_AUTO_POSITION_SYMBOL", 296i64),
    ("MASTER_BIND_SYMBOL", 297i64),
    ("MASTER_CONNECT_RETRY_SYMBOL", 298i64),
    ("MASTER_DELAY_SYMBOL", 299i64),
    ("MASTER_HOST_SYMBOL", 300i64),
    ("MASTER_LOG_FILE_SYMBOL", 301i64),
    ("MASTER_LOG_POS_SYMBOL", 302i64),
    ("MASTER_PASSWORD_SYMBOL", 303i64),
    ("MASTER_PORT_SYMBOL", 304i64),
    ("MASTER_RETRY_COUNT_SYMBOL", 305i64),
    ("MASTER_SERVER_ID_SYMBOL", 306i64),
    ("MASTER_SSL_CAPATH_SYMBOL", 307i64),
    ("MASTER_SSL_CA_SYMBOL", 308i64),
    ("MASTER_SSL_CERT_SYMBOL", 309i64),
    ("MASTER_SSL_CIPHER_SYMBOL", 310i64),
    ("MASTER_SSL_CRL_SYMBOL", 311i64),
    ("MASTER_SSL_CRLPATH_SYMBOL", 312i64),
    ("MASTER_SSL_KEY_SYMBOL", 313i64),
    ("MASTER_SSL_SYMBOL", 314i64),
    ("MASTER_SSL_VERIFY_SERVER_CERT_SYMBOL", 315i64),
    ("MASTER_SYMBOL", 316i64),
    ("MASTER_TLS_VERSION_SYMBOL", 317i64),
    ("MASTER_USER_SYMBOL", 318i64),
    ("MASTER_HEARTBEAT_PERIOD_SYMBOL", 319i64),
    ("MATCH_SYMBOL", 320i64),
    ("MAX_CONNECTIONS_PER_HOUR_SYMBOL", 321i64),
    ("MAX_QUERIES_PER_HOUR_SYMBOL", 322i64),
    ("MAX_ROWS_SYMBOL", 323i64),
    ("MAX_SIZE_SYMBOL", 324i64),
    ("MAX_STATEMENT_TIME_SYMBOL", 325i64),
    ("MAX_SYMBOL", 326i64),
    ("MAX_UPDATES_PER_HOUR_SYMBOL", 327i64),
    ("MAX_USER_CONNECTIONS_SYMBOL", 328i64),
    ("MAXVALUE_SYMBOL", 329i64),
    ("MEDIUMBLOB_SYMBOL", 330i64),
    ("MEDIUMINT_SYMBOL", 331i64),
    ("MEDIUMTEXT_SYMBOL", 332i64),
    ("MEDIUM_SYMBOL", 333i64),
    ("MEMORY_SYMBOL", 334i64),
    ("MERGE_SYMBOL", 335i64),
    ("MESSAGE_TEXT_SYMBOL", 336i64),
    ("MICROSECOND_SYMBOL", 337i64),
    ("MID_SYMBOL", 338i64),
    ("MIDDLEINT_SYMBOL", 339i64),
    ("MIGRATE_SYMBOL", 340i64),
    ("MINUTE_MICROSECOND_SYMBOL", 341i64),
    ("MINUTE_SECOND_SYMBOL", 342i64),
    ("MINUTE_SYMBOL", 343i64),
    ("MIN_ROWS_SYMBOL", 344i64),
    ("MIN_SYMBOL", 345i64),
    ("MODE_SYMBOL", 346i64),
    ("MODIFIES_SYMBOL", 347i64),
    ("MODIFY_SYMBOL", 348i64),
    ("MOD_SYMBOL", 349i64),
    ("MONTH_SYMBOL", 350i64),
    ("MULTILINESTRING_SYMBOL", 351i64),
    ("MULTIPOINT_SYMBOL", 352i64),
    ("MULTIPOLYGON_SYMBOL", 353i64),
    ("MUTEX_SYMBOL", 354i64),
    ("MYSQL_ERRNO_SYMBOL", 355i64),
    ("NAMES_SYMBOL", 356i64),
    ("NAME_SYMBOL", 357i64),
    ("NATIONAL_SYMBOL", 358i64),
    ("NATURAL_SYMBOL", 359i64),
    ("NCHAR_STRING_SYMBOL", 360i64),
    ("NCHAR_SYMBOL", 361i64),
    ("NDB_SYMBOL", 362i64),
    ("NDBCLUSTER_SYMBOL", 363i64),
    ("NEG_SYMBOL", 364i64),
    ("NEVER_SYMBOL", 365i64),
    ("NEW_SYMBOL", 366i64),
    ("NEXT_SYMBOL", 367i64),
    ("NODEGROUP_SYMBOL", 368i64),
    ("NONE_SYMBOL", 369i64),
    ("NONBLOCKING_SYMBOL", 370i64),
    ("NOT_SYMBOL", 371i64),
    ("NOW_SYMBOL", 372i64),
    ("NO_SYMBOL", 373i64),
    ("NO_WAIT_SYMBOL", 374i64),
    ("NO_WRITE_TO_BINLOG_SYMBOL", 375i64),
    ("NULL_SYMBOL", 376i64),
    ("NUMBER_SYMBOL", 377i64),
    ("NUMERIC_SYMBOL", 378i64),
    ("NVARCHAR_SYMBOL", 379i64),
    ("OFFLINE_SYMBOL", 380i64),
    ("OFFSET_SYMBOL", 381i64),
    ("OLD_PASSWORD_SYMBOL", 382i64),
    ("ON_SYMBOL", 383i64),
    ("ONE_SYMBOL", 384i64),
    ("ONLINE_SYMBOL", 385i64),
    ("ONLY_SYMBOL", 386i64),
    ("OPEN_SYMBOL", 387i64),
    ("OPTIMIZE_SYMBOL", 388i64),
    ("OPTIMIZER_COSTS_SYMBOL", 389i64),
    ("OPTIONS_SYMBOL", 390i64),
    ("OPTION_SYMBOL", 391i64),
    ("OPTIONALLY_SYMBOL", 392i64),
    ("ORDER_SYMBOL", 393i64),
    ("OR_SYMBOL", 394i64),
    ("OUTER_SYMBOL", 395i64),
    ("OUTFILE_SYMBOL", 396i64),
    ("OUT_SYMBOL", 397i64),
    ("OWNER_SYMBOL", 398i64),
    ("PACK_KEYS_SYMBOL", 399i64),
    ("PAGE_SYMBOL", 400i64),
    ("PARSER_SYMBOL", 401i64),
    ("PARTIAL_SYMBOL", 402i64),
    ("PARTITIONING_SYMBOL", 403i64),
    ("PARTITIONS_SYMBOL", 404i64),
    ("PARTITION_SYMBOL", 405i64),
    ("PASSWORD_SYMBOL", 406i64),
    ("PHASE_SYMBOL", 407i64),
    ("PLUGINS_SYMBOL", 408i64),
    ("PLUGIN_DIR_SYMBOL", 409i64),
    ("PLUGIN_SYMBOL", 410i64),
    ("POINT_SYMBOL", 411i64),
    ("POLYGON_SYMBOL", 412i64),
    ("PORT_SYMBOL", 413i64),
    ("POSITION_SYMBOL", 414i64),
    ("PRECEDES_SYMBOL", 415i64),
    ("PRECISION_SYMBOL", 416i64),
    ("PREPARE_SYMBOL", 417i64),
    ("PRESERVE_SYMBOL", 418i64),
    ("PREV_SYMBOL", 419i64),
    ("PRIMARY_SYMBOL", 420i64),
    ("PRIVILEGES_SYMBOL", 421i64),
    ("PROCEDURE_SYMBOL", 422i64),
    ("PROCESS_SYMBOL", 423i64),
    ("PROCESSLIST_SYMBOL", 424i64),
    ("PROFILE_SYMBOL", 425i64),
    ("PROFILES_SYMBOL", 426i64),
    ("PROXY_SYMBOL", 427i64),
    ("PURGE_SYMBOL", 428i64),
    ("QUARTER_SYMBOL", 429i64),
    ("QUERY_SYMBOL", 430i64),
    ("QUICK_SYMBOL", 431i64),
    ("RANGE_SYMBOL", 432i64),
    ("READS_SYMBOL", 433i64),
    ("READ_ONLY_SYMBOL", 434i64),
    ("READ_SYMBOL", 435i64),
    ("READ_WRITE_SYMBOL", 436i64),
    ("REAL_SYMBOL", 437i64),
    ("REBUILD_SYMBOL", 438i64),
    ("RECOVER_SYMBOL", 439i64),
    ("REDOFILE_SYMBOL", 440i64),
    ("REDO_BUFFER_SIZE_SYMBOL", 441i64),
    ("REDUNDANT_SYMBOL", 442i64),
    ("REFERENCES_SYMBOL", 443i64),
    ("REGEXP_SYMBOL", 444i64),
    ("RELAY_SYMBOL", 445i64),
    ("RELAYLOG_SYMBOL", 446i64),
    ("RELAY_LOG_FILE_SYMBOL", 447i64),
    ("RELAY_LOG_POS_SYMBOL", 448i64),
    ("RELAY_THREAD_SYMBOL", 449i64),
    ("RELEASE_SYMBOL", 450i64),
    ("RELOAD_SYMBOL", 451i64),
    ("REMOVE_SYMBOL", 452i64),
    ("RENAME_SYMBOL", 453i64),
    ("REORGANIZE_SYMBOL", 454i64),
    ("REPAIR_SYMBOL", 455i64),
    ("REPEATABLE_SYMBOL", 456i64),
    ("REPEAT_SYMBOL", 457i64),
    ("REPLACE_SYMBOL", 458i64),
    ("REPLICATION_SYMBOL", 459i64),
    ("REPLICATE_DO_DB_SYMBOL", 460i64),
    ("REPLICATE_IGNORE_DB_SYMBOL", 461i64),
    ("REPLICATE_DO_TABLE_SYMBOL", 462i64),
    ("REPLICATE_IGNORE_TABLE_SYMBOL", 463i64),
    ("REPLICATE_WILD_DO_TABLE_SYMBOL", 464i64),
    ("REPLICATE_WILD_IGNORE_TABLE_SYMBOL", 465i64),
    ("REPLICATE_REWRITE_DB_SYMBOL", 466i64),
    ("REQUIRE_SYMBOL", 467i64),
    ("RESET_SYMBOL", 468i64),
    ("RESIGNAL_SYMBOL", 469i64),
    ("RESTORE_SYMBOL", 470i64),
    ("RESTRICT_SYMBOL", 471i64),
    ("RESUME_SYMBOL", 472i64),
    ("RETURNED_SQLSTATE_SYMBOL", 473i64),
    ("RETURNS_SYMBOL", 474i64),
    ("RETURN_SYMBOL", 475i64),
    ("REVERSE_SYMBOL", 476i64),
    ("REVOKE_SYMBOL", 477i64),
    ("RIGHT_SYMBOL", 478i64),
    ("RLIKE_SYMBOL", 479i64),
    ("ROLLBACK_SYMBOL", 480i64),
    ("ROLLUP_SYMBOL", 481i64),
    ("ROTATE_SYMBOL", 482i64),
    ("ROUTINE_SYMBOL", 483i64),
    ("ROWS_SYMBOL", 484i64),
    ("ROW_COUNT_SYMBOL", 485i64),
    ("ROW_FORMAT_SYMBOL", 486i64),
    ("ROW_SYMBOL", 487i64),
    ("RTREE_SYMBOL", 488i64),
    ("SAVEPOINT_SYMBOL", 489i64),
    ("SCHEDULE_SYMBOL", 490i64),
    ("SCHEMA_SYMBOL", 491i64),
    ("SCHEMA_NAME_SYMBOL", 492i64),
    ("SCHEMAS_SYMBOL", 493i64),
    ("SECOND_MICROSECOND_SYMBOL", 494i64),
    ("SECOND_SYMBOL", 495i64),
    ("SECURITY_SYMBOL", 496i64),
    ("SELECT_SYMBOL", 497i64),
    ("SENSITIVE_SYMBOL", 498i64),
    ("SEPARATOR_SYMBOL", 499i64),
    ("SERIALIZABLE_SYMBOL", 500i64),
    ("SERIAL_SYMBOL", 501i64),
    ("SESSION_SYMBOL", 502i64),
    ("SERVER_SYMBOL", 503i64),
    ("SERVER_OPTIONS_SYMBOL", 504i64),
    ("SESSION_USER_SYMBOL", 505i64),
    ("SET_SYMBOL", 506i64),
    ("SET_VAR_SYMBOL", 507i64),
    ("SHARE_SYMBOL", 508i64),
    ("SHOW_SYMBOL", 509i64),
    ("SHUTDOWN_SYMBOL", 510i64),
    ("SIGNAL_SYMBOL", 511i64),
    ("SIGNED_SYMBOL", 512i64),
    ("SIMPLE_SYMBOL", 513i64),
    ("SLAVE_SYMBOL", 514i64),
    ("SLOW_SYMBOL", 515i64),
    ("SMALLINT_SYMBOL", 516i64),
    ("SNAPSHOT_SYMBOL", 517i64),
    ("SOME_SYMBOL", 518i64),
    ("SOCKET_SYMBOL", 519i64),
    ("SONAME_SYMBOL", 520i64),
    ("SOUNDS_SYMBOL", 521i64),
    ("SOURCE_SYMBOL", 522i64),
    ("SPATIAL_SYMBOL", 523i64),
    ("SPECIFIC_SYMBOL", 524i64),
    ("SQLEXCEPTION_SYMBOL", 525i64),
    ("SQLSTATE_SYMBOL", 526i64),
    ("SQLWARNING_SYMBOL", 527i64),
    ("SQL_AFTER_GTIDS_SYMBOL", 528i64),
    ("SQL_AFTER_MTS_GAPS_SYMBOL", 529i64),
    ("SQL_BEFORE_GTIDS_SYMBOL", 530i64),
    ("SQL_BIG_RESULT_SYMBOL", 531i64),
    ("SQL_BUFFER_RESULT_SYMBOL", 532i64),
    ("SQL_CACHE_SYMBOL", 533i64),
    ("SQL_CALC_FOUND_ROWS_SYMBOL", 534i64),
    ("SQL_NO_CACHE_SYMBOL", 535i64),
    ("SQL_SMALL_RESULT_SYMBOL", 536i64),
    ("SQL_SYMBOL", 537i64),
    ("SQL_THREAD_SYMBOL", 538i64),
    ("SSL_SYMBOL", 539i64),
    ("STACKED_SYMBOL", 540i64),
    ("STARTING_SYMBOL", 541i64),
    ("STARTS_SYMBOL", 542i64),
    ("START_SYMBOL", 543i64),
    ("STATS_AUTO_RECALC_SYMBOL", 544i64),
    ("STATS_PERSISTENT_SYMBOL", 545i64),
    ("STATS_SAMPLE_PAGES_SYMBOL", 546i64),
    ("STATUS_SYMBOL", 547i64),
    ("STDDEV_SAMP_SYMBOL", 548i64),
    ("STDDEV_SYMBOL", 549i64),
    ("STDDEV_POP_SYMBOL", 550i64),
    ("STD_SYMBOL", 551i64),
    ("STOP_SYMBOL", 552i64),
    ("STORAGE_SYMBOL", 553i64),
    ("STORED_SYMBOL", 554i64),
    ("STRAIGHT_JOIN_SYMBOL", 555i64),
    ("STRING_SYMBOL", 556i64),
    ("SUBCLASS_ORIGIN_SYMBOL", 557i64),
    ("SUBDATE_SYMBOL", 558i64),
    ("SUBJECT_SYMBOL", 559i64),
    ("SUBPARTITIONS_SYMBOL", 560i64),
    ("SUBPARTITION_SYMBOL", 561i64),
    ("SUBSTR_SYMBOL", 562i64),
    ("SUBSTRING_SYMBOL", 563i64),
    ("SUM_SYMBOL", 564i64),
    ("SUPER_SYMBOL", 565i64),
    ("SUSPEND_SYMBOL", 566i64),
    ("SWAPS_SYMBOL", 567i64),
    ("SWITCHES_SYMBOL", 568i64),
    ("SYSDATE_SYMBOL", 569i64),
    ("SYSTEM_USER_SYMBOL", 570i64),
    ("TABLES_SYMBOL", 571i64),
    ("TABLESPACE_SYMBOL", 572i64),
    ("TABLE_REF_PRIORITY_SYMBOL", 573i64),
    ("TABLE_SYMBOL", 574i64),
    ("TABLE_CHECKSUM_SYMBOL", 575i64),
    ("TABLE_NAME_SYMBOL", 576i64),
    ("TEMPORARY_SYMBOL", 577i64),
    ("TEMPTABLE_SYMBOL", 578i64),
    ("TERMINATED_SYMBOL", 579i64),
    ("TEXT_SYMBOL", 580i64),
    ("THAN_SYMBOL", 581i64),
    ("THEN_SYMBOL", 582i64),
    ("TIMESTAMP_SYMBOL", 583i64),
    ("TIMESTAMP_ADD_SYMBOL", 584i64),
    ("TIMESTAMP_DIFF_SYMBOL", 585i64),
    ("TIME_SYMBOL", 586i64),
    ("TINYBLOB_SYMBOL", 587i64),
    ("TINYINT_SYMBOL", 588i64),
    ("TINYTEXT_SYMBOL", 589i64),
    ("TO_SYMBOL", 590i64),
    ("TRAILING_SYMBOL", 591i64),
    ("TRANSACTION_SYMBOL", 592i64),
    ("TRIGGERS_SYMBOL", 593i64),
    ("TRIGGER_SYMBOL", 594i64),
    ("TRIM_SYMBOL", 595i64),
    ("TRUE_SYMBOL", 596i64),
    ("TRUNCATE_SYMBOL", 597i64),
    ("TYPES_SYMBOL", 598i64),
    ("TYPE_SYMBOL", 599i64),
    ("UDF_RETURNS_SYMBOL", 600i64),
    ("UNCOMMITTED_SYMBOL", 601i64),
    ("UNDEFINED_SYMBOL", 602i64),
    ("UNDOFILE_SYMBOL", 603i64),
    ("UNDO_BUFFER_SIZE_SYMBOL", 604i64),
    ("UNDO_SYMBOL", 605i64),
    ("UNICODE_SYMBOL", 606i64),
    ("UNINSTALL_SYMBOL", 607i64),
    ("UNION_SYMBOL", 608i64),
    ("UNIQUE_SYMBOL", 609i64),
    ("UNKNOWN_SYMBOL", 610i64),
    ("UNLOCK_SYMBOL", 611i64),
    ("UNSIGNED_SYMBOL", 612i64),
    ("UNTIL_SYMBOL", 613i64),
    ("UPDATE_SYMBOL", 614i64),
    ("UPGRADE_SYMBOL", 615i64),
    ("USAGE_SYMBOL", 616i64),
    ("USER_RESOURCES_SYMBOL", 617i64),
    ("USER_SYMBOL", 618i64),
    ("USE_FRM_SYMBOL", 619i64),
    ("USE_SYMBOL", 620i64),
    ("USING_SYMBOL", 621i64),
    ("UTC_DATE_SYMBOL", 622i64),
    ("UTC_TIMESTAMP_SYMBOL", 623i64),
    ("UTC_TIME_SYMBOL", 624i64),
    ("VALIDATION_SYMBOL", 625i64),
    ("VALUES_SYMBOL", 626i64),
    ("VALUE_SYMBOL", 627i64),
    ("VARBINARY_SYMBOL", 628i64),
    ("VARCHAR_SYMBOL", 629i64),
    ("VARCHARACTER_SYMBOL", 630i64),
    ("VARIABLES_SYMBOL", 631i64),
    ("VARIANCE_SYMBOL", 632i64),
    ("VARYING_SYMBOL", 633i64),
    ("VAR_POP_SYMBOL", 634i64),
    ("VAR_SAMP_SYMBOL", 635i64),
    ("VIEW_SYMBOL", 636i64),
    ("VIRTUAL_SYMBOL", 637i64),
    ("WAIT_SYMBOL", 638i64),
    ("WARNINGS_SYMBOL", 639i64),
    ("WEEK_SYMBOL", 640i64),
    ("WEIGHT_STRING_SYMBOL", 641i64),
    ("WHEN_SYMBOL", 642i64),
    ("WHERE_SYMBOL", 643i64),
    ("WHILE_SYMBOL", 644i64),
    ("WITH_SYMBOL", 645i64),
    ("WITHOUT_SYMBOL", 646i64),
    ("WORK_SYMBOL", 647i64),
    ("WRAPPER_SYMBOL", 648i64),
    ("WRITE_SYMBOL", 649i64),
    ("X509_SYMBOL", 650i64),
    ("XA_SYMBOL", 651i64),
    ("XID_SYMBOL", 652i64),
    ("XML_SYMBOL", 653i64),
    ("XOR_SYMBOL", 654i64),
    ("YEAR_MONTH_SYMBOL", 655i64),
    ("YEAR_SYMBOL", 656i64),
    ("ZEROFILL_SYMBOL", 657i64),
    ("PERSIST_SYMBOL", 658i64),
    ("ROLE_SYMBOL", 659i64),
    ("ADMIN_SYMBOL", 660i64),
    ("INVISIBLE_SYMBOL", 661i64),
    ("VISIBLE_SYMBOL", 662i64),
    ("EXCEPT_SYMBOL", 663i64),
    ("COMPONENT_SYMBOL", 664i64),
    ("RECURSIVE_SYMBOL", 665i64),
    ("JSON_OBJECTAGG_SYMBOL", 666i64),
    ("JSON_ARRAYAGG_SYMBOL", 667i64),
    ("OF_SYMBOL", 668i64),
    ("SKIP_SYMBOL", 669i64),
    ("LOCKED_SYMBOL", 670i64),
    ("NOWAIT_SYMBOL", 671i64),
    ("GROUPING_SYMBOL", 672i64),
    ("PERSIST_ONLY_SYMBOL", 673i64),
    ("HISTOGRAM_SYMBOL", 674i64),
    ("BUCKETS_SYMBOL", 675i64),
    ("REMOTE_SYMBOL", 676i64),
    ("CLONE_SYMBOL", 677i64),
    ("CUME_DIST_SYMBOL", 678i64),
    ("DENSE_RANK_SYMBOL", 679i64),
    ("EXCLUDE_SYMBOL", 680i64),
    ("FIRST_VALUE_SYMBOL", 681i64),
    ("FOLLOWING_SYMBOL", 682i64),
    ("GROUPS_SYMBOL", 683i64),
    ("LAG_SYMBOL", 684i64),
    ("LAST_VALUE_SYMBOL", 685i64),
    ("LEAD_SYMBOL", 686i64),
    ("NTH_VALUE_SYMBOL", 687i64),
    ("NTILE_SYMBOL", 688i64),
    ("NULLS_SYMBOL", 689i64),
    ("OTHERS_SYMBOL", 690i64),
    ("OVER_SYMBOL", 691i64),
    ("PERCENT_RANK_SYMBOL", 692i64),
    ("PRECEDING_SYMBOL", 693i64),
    ("RANK_SYMBOL", 694i64),
    ("RESPECT_SYMBOL", 695i64),
    ("ROW_NUMBER_SYMBOL", 696i64),
    ("TIES_SYMBOL", 697i64),
    ("UNBOUNDED_SYMBOL", 698i64),
    ("WINDOW_SYMBOL", 699i64),
    ("EMPTY_SYMBOL", 700i64),
    ("JSON_TABLE_SYMBOL", 701i64),
    ("NESTED_SYMBOL", 702i64),
    ("ORDINALITY_SYMBOL", 703i64),
    ("PATH_SYMBOL", 704i64),
    ("HISTORY_SYMBOL", 705i64),
    ("REUSE_SYMBOL", 706i64),
    ("SRID_SYMBOL", 707i64),
    ("THREAD_PRIORITY_SYMBOL", 708i64),
    ("RESOURCE_SYMBOL", 709i64),
    ("SYSTEM_SYMBOL", 710i64),
    ("VCPU_SYMBOL", 711i64),
    ("MASTER_PUBLIC_KEY_PATH_SYMBOL", 712i64),
    ("GET_MASTER_PUBLIC_KEY_SYMBOL", 713i64),
    ("RESTART_SYMBOL", 714i64),
    ("DEFINITION_SYMBOL", 715i64),
    ("DESCRIPTION_SYMBOL", 716i64),
    ("ORGANIZATION_SYMBOL", 717i64),
    ("REFERENCE_SYMBOL", 718i64),
    ("OPTIONAL_SYMBOL", 719i64),
    ("SECONDARY_SYMBOL", 720i64),
    ("SECONDARY_ENGINE_SYMBOL", 721i64),
    ("SECONDARY_LOAD_SYMBOL", 722i64),
    ("SECONDARY_UNLOAD_SYMBOL", 723i64),
    ("ACTIVE_SYMBOL", 724i64),
    ("INACTIVE_SYMBOL", 725i64),
    ("LATERAL_SYMBOL", 726i64),
    ("RETAIN_SYMBOL", 727i64),
    ("OLD_SYMBOL", 728i64),
    ("NETWORK_NAMESPACE_SYMBOL", 729i64),
    ("ENFORCED_SYMBOL", 730i64),
    ("ARRAY_SYMBOL", 731i64),
    ("OJ_SYMBOL", 732i64),
    ("MEMBER_SYMBOL", 733i64),
    ("RANDOM_SYMBOL", 734i64),
    ("MASTER_COMPRESSION_ALGORITHM_SYMBOL", 735i64),
    ("MASTER_ZSTD_COMPRESSION_LEVEL_SYMBOL", 736i64),
    ("PRIVILEGE_CHECKS_USER_SYMBOL", 737i64),
    ("MASTER_TLS_CIPHERSUITES_SYMBOL", 738i64),
    ("REQUIRE_ROW_FORMAT_SYMBOL", 739i64),
    ("PASSWORD_LOCK_TIME_SYMBOL", 740i64),
    ("FAILED_LOGIN_ATTEMPTS_SYMBOL", 741i64),
    ("REQUIRE_TABLE_PRIMARY_KEY_CHECK_SYMBOL", 742i64),
    ("STREAM_SYMBOL", 743i64),
    ("OFF_SYMBOL", 744i64),
    ("AT_AT_SIGN_SYMBOL", 745i64),
    ("AT_SIGN_SYMBOL", 746i64),
    ("CLOSE_CURLY_SYMBOL", 747i64),
    ("CLOSE_PAR_SYMBOL", 748i64),
    ("COLON_SYMBOL", 749i64),
    ("COMMA_SYMBOL", 750i64),
    ("DOT_SYMBOL", 751i64),
    ("OPEN_CURLY_SYMBOL", 752i64),
    ("OPEN_PAR_SYMBOL", 753i64),
    ("PARAM_MARKER", 754i64),
    ("SEMICOLON_SYMBOL", 755i64),
    ("ASSIGN_OPERATOR", 756i64),
    ("BITWISE_AND_OPERATOR", 757i64),
    ("BITWISE_NOT_OPERATOR", 758i64),
    ("BITWISE_OR_OPERATOR", 759i64),
    ("BITWISE_XOR_OPERATOR", 760i64),
    ("CONCAT_PIPES_SYMBOL", 761i64),
    ("DIV_OPERATOR", 762i64),
    ("EQUAL_OPERATOR", 763i64),
    ("GREATER_OR_EQUAL_OPERATOR", 764i64),
    ("GREATER_THAN_OPERATOR", 765i64),
    ("JSON_SEPARATOR_SYMBOL", 766i64),
    ("JSON_UNQUOTED_SEPARATOR_SYMBOL", 767i64),
    ("LESS_OR_EQUAL_OPERATOR", 768i64),
    ("LESS_THAN_OPERATOR", 769i64),
    ("LOGICAL_AND_OPERATOR", 770i64),
    ("LOGICAL_NOT_OPERATOR", 771i64),
    ("LOGICAL_OR_OPERATOR", 772i64),
    ("MINUS_OPERATOR", 773i64),
    ("MOD_OPERATOR", 774i64),
    ("MULT_OPERATOR", 775i64),
    ("NOT_EQUAL_OPERATOR", 776i64),
    ("NULL_SAFE_EQUAL_OPERATOR", 777i64),
    ("PLUS_OPERATOR", 778i64),
    ("SHIFT_LEFT_OPERATOR", 779i64),
    ("SHIFT_RIGHT_OPERATOR", 780i64),
    ("BACK_TICK_QUOTED_ID", 781i64),
    ("BIN_NUMBER", 782i64),
    ("DECIMAL_NUMBER", 783i64),
    ("DOUBLE_QUOTED_TEXT", 784i64),
    ("FLOAT_NUMBER", 785i64),
    ("HEX_NUMBER", 786i64),
    ("INT_NUMBER", 787i64),
    ("LONG_NUMBER", 788i64),
    ("NCHAR_TEXT", 789i64),
    ("SINGLE_QUOTED_TEXT", 790i64),
    ("ULONGLONG_NUMBER", 791i64),
    ("AT_TEXT_SUFFIX", 792i64),
    ("IDENTIFIER", 793i64),
    ("UNDERSCORE_CHARSET", 794i64),
    ("INT1_SYMBOL", 795i64),
    ("INT2_SYMBOL", 796i64),
    ("INT3_SYMBOL", 797i64),
    ("INT4_SYMBOL", 798i64),
    ("INT8_SYMBOL", 799i64),
    ("NOT2_SYMBOL", 800i64),
    ("NULL2_SYMBOL", 801i64),
    ("SQL_TSI_DAY_SYMBOL", 802i64),
    ("SQL_TSI_HOUR_SYMBOL", 803i64),
    ("SQL_TSI_MICROSECOND_SYMBOL", 804i64),
    ("SQL_TSI_MINUTE_SYMBOL", 805i64),
    ("SQL_TSI_MONTH_SYMBOL", 806i64),
    ("SQL_TSI_QUARTER_SYMBOL", 807i64),
    ("SQL_TSI_SECOND_SYMBOL", 808i64),
    ("SQL_TSI_WEEK_SYMBOL", 809i64),
    ("SQL_TSI_YEAR_SYMBOL", 810i64),
    ("INTERSECT_SYMBOL", 811i64),
    ("ATTRIBUTE_SYMBOL", 812i64),
    ("SOURCE_AUTO_POSITION_SYMBOL", 813i64),
    ("SOURCE_BIND_SYMBOL", 814i64),
    ("SOURCE_COMPRESSION_ALGORITHM_SYMBOL", 815i64),
    ("SOURCE_CONNECT_RETRY_SYMBOL", 816i64),
    ("SOURCE_CONNECTION_AUTO_FAILOVER_SYMBOL", 817i64),
    ("SOURCE_DELAY_SYMBOL", 818i64),
    ("SOURCE_HEARTBEAT_PERIOD_SYMBOL", 819i64),
    ("SOURCE_HOST_SYMBOL", 820i64),
    ("SOURCE_LOG_FILE_SYMBOL", 821i64),
    ("SOURCE_LOG_POS_SYMBOL", 822i64),
    ("SOURCE_PASSWORD_SYMBOL", 823i64),
    ("SOURCE_PORT_SYMBOL", 824i64),
    ("SOURCE_PUBLIC_KEY_PATH_SYMBOL", 825i64),
    ("SOURCE_RETRY_COUNT_SYMBOL", 826i64),
    ("SOURCE_SSL_SYMBOL", 827i64),
    ("SOURCE_SSL_CA_SYMBOL", 828i64),
    ("SOURCE_SSL_CAPATH_SYMBOL", 829i64),
    ("SOURCE_SSL_CERT_SYMBOL", 830i64),
    ("SOURCE_SSL_CIPHER_SYMBOL", 831i64),
    ("SOURCE_SSL_CRL_SYMBOL", 832i64),
    ("SOURCE_SSL_CRLPATH_SYMBOL", 833i64),
    ("SOURCE_SSL_KEY_SYMBOL", 834i64),
    ("SOURCE_SSL_VERIFY_SERVER_CERT_SYMBOL", 835i64),
    ("SOURCE_TLS_CIPHERSUITES_SYMBOL", 836i64),
    ("SOURCE_TLS_VERSION_SYMBOL", 837i64),
    ("SOURCE_USER_SYMBOL", 838i64),
    ("SOURCE_ZSTD_COMPRESSION_LEVEL_SYMBOL", 839i64),
    ("GET_SOURCE_PUBLIC_KEY_SYMBOL", 840i64),
    ("GTID_ONLY_SYMBOL", 841i64),
    ("ASSIGN_GTIDS_TO_ANONYMOUS_TRANSACTIONS_SYMBOL", 842i64),
    ("ZONE_SYMBOL", 843i64),
    ("INNODB_SYMBOL", 844i64),
    ("TLS_SYMBOL", 845i64),
    ("REDO_LOG_SYMBOL", 846i64),
    ("KEYRING_SYMBOL", 847i64),
    ("ENGINE_ATTRIBUTE_SYMBOL", 848i64),
    ("SECONDARY_ENGINE_ATTRIBUTE_SYMBOL", 849i64),
    ("JSON_VALUE_SYMBOL", 850i64),
    ("RETURNING_SYMBOL", 851i64),
    ("GEOMCOLLECTION_SYMBOL", 852i64),
    ("COMMENT", 900i64),
    ("MYSQL_COMMENT_START", 901i64),
    ("MYSQL_COMMENT_END", 902i64),
    ("WHITESPACE", 0i64),
    ("EOF", -1i64),
];

pub const SQL_MODE_HIGH_NOT_PRECEDENCE: i64 = 1i64;
pub const SQL_MODE_PIPES_AS_CONCAT: i64 = 2i64;
pub const SQL_MODE_IGNORE_SPACE: i64 = 4i64;
pub const SQL_MODE_NO_BACKSLASH_ESCAPES: i64 = 8i64;
pub const WHITESPACE_MASK: &str = " \t\n\r\x0c";
pub const DIGIT_MASK: &str = "0123456789";
pub const HEX_DIGIT_MASK: &str = "0123456789abcdefABCDEF";
pub const ACCESSIBLE_SYMBOL: i64 = 1i64;
pub const ACCOUNT_SYMBOL: i64 = 2i64;
pub const ACTION_SYMBOL: i64 = 3i64;
pub const ADD_SYMBOL: i64 = 4i64;
pub const ADDDATE_SYMBOL: i64 = 5i64;
pub const AFTER_SYMBOL: i64 = 6i64;
pub const AGAINST_SYMBOL: i64 = 7i64;
pub const AGGREGATE_SYMBOL: i64 = 8i64;
pub const ALGORITHM_SYMBOL: i64 = 9i64;
pub const ALL_SYMBOL: i64 = 10i64;
pub const ALTER_SYMBOL: i64 = 11i64;
pub const ALWAYS_SYMBOL: i64 = 12i64;
pub const ANALYSE_SYMBOL: i64 = 13i64;
pub const ANALYZE_SYMBOL: i64 = 14i64;
pub const AND_SYMBOL: i64 = 15i64;
pub const ANY_SYMBOL: i64 = 16i64;
pub const AS_SYMBOL: i64 = 17i64;
pub const ASC_SYMBOL: i64 = 18i64;
pub const ASCII_SYMBOL: i64 = 19i64;
pub const ASENSITIVE_SYMBOL: i64 = 20i64;
pub const AT_SYMBOL: i64 = 21i64;
pub const AUTHORS_SYMBOL: i64 = 22i64;
pub const AUTOEXTEND_SIZE_SYMBOL: i64 = 23i64;
pub const AUTO_INCREMENT_SYMBOL: i64 = 24i64;
pub const AVG_ROW_LENGTH_SYMBOL: i64 = 25i64;
pub const AVG_SYMBOL: i64 = 26i64;
pub const BACKUP_SYMBOL: i64 = 27i64;
pub const BEFORE_SYMBOL: i64 = 28i64;
pub const BEGIN_SYMBOL: i64 = 29i64;
pub const BETWEEN_SYMBOL: i64 = 30i64;
pub const BIGINT_SYMBOL: i64 = 31i64;
pub const BINARY_SYMBOL: i64 = 32i64;
pub const BINLOG_SYMBOL: i64 = 33i64;
pub const BIN_NUM_SYMBOL: i64 = 34i64;
pub const BIT_AND_SYMBOL: i64 = 35i64;
pub const BIT_OR_SYMBOL: i64 = 36i64;
pub const BIT_SYMBOL: i64 = 37i64;
pub const BIT_XOR_SYMBOL: i64 = 38i64;
pub const BLOB_SYMBOL: i64 = 39i64;
pub const BLOCK_SYMBOL: i64 = 40i64;
pub const BOOLEAN_SYMBOL: i64 = 41i64;
pub const BOOL_SYMBOL: i64 = 42i64;
pub const BOTH_SYMBOL: i64 = 43i64;
pub const BTREE_SYMBOL: i64 = 44i64;
pub const BY_SYMBOL: i64 = 45i64;
pub const BYTE_SYMBOL: i64 = 46i64;
pub const CACHE_SYMBOL: i64 = 47i64;
pub const CALL_SYMBOL: i64 = 48i64;
pub const CASCADE_SYMBOL: i64 = 49i64;
pub const CASCADED_SYMBOL: i64 = 50i64;
pub const CASE_SYMBOL: i64 = 51i64;
pub const CAST_SYMBOL: i64 = 52i64;
pub const CATALOG_NAME_SYMBOL: i64 = 53i64;
pub const CHAIN_SYMBOL: i64 = 54i64;
pub const CHANGE_SYMBOL: i64 = 55i64;
pub const CHANGED_SYMBOL: i64 = 56i64;
pub const CHANNEL_SYMBOL: i64 = 57i64;
pub const CHARSET_SYMBOL: i64 = 58i64;
pub const CHARACTER_SYMBOL: i64 = 59i64;
pub const CHAR_SYMBOL: i64 = 60i64;
pub const CHECKSUM_SYMBOL: i64 = 61i64;
pub const CHECK_SYMBOL: i64 = 62i64;
pub const CIPHER_SYMBOL: i64 = 63i64;
pub const CLASS_ORIGIN_SYMBOL: i64 = 64i64;
pub const CLIENT_SYMBOL: i64 = 65i64;
pub const CLOSE_SYMBOL: i64 = 66i64;
pub const COALESCE_SYMBOL: i64 = 67i64;
pub const CODE_SYMBOL: i64 = 68i64;
pub const COLLATE_SYMBOL: i64 = 69i64;
pub const COLLATION_SYMBOL: i64 = 70i64;
pub const COLUMNS_SYMBOL: i64 = 71i64;
pub const COLUMN_SYMBOL: i64 = 72i64;
pub const COLUMN_NAME_SYMBOL: i64 = 73i64;
pub const COLUMN_FORMAT_SYMBOL: i64 = 74i64;
pub const COMMENT_SYMBOL: i64 = 75i64;
pub const COMMITTED_SYMBOL: i64 = 76i64;
pub const COMMIT_SYMBOL: i64 = 77i64;
pub const COMPACT_SYMBOL: i64 = 78i64;
pub const COMPLETION_SYMBOL: i64 = 79i64;
pub const COMPRESSED_SYMBOL: i64 = 80i64;
pub const COMPRESSION_SYMBOL: i64 = 81i64;
pub const CONCURRENT_SYMBOL: i64 = 82i64;
pub const CONDITION_SYMBOL: i64 = 83i64;
pub const CONNECTION_SYMBOL: i64 = 84i64;
pub const CONSISTENT_SYMBOL: i64 = 85i64;
pub const CONSTRAINT_SYMBOL: i64 = 86i64;
pub const CONSTRAINT_CATALOG_SYMBOL: i64 = 87i64;
pub const CONSTRAINT_NAME_SYMBOL: i64 = 88i64;
pub const CONSTRAINT_SCHEMA_SYMBOL: i64 = 89i64;
pub const CONTAINS_SYMBOL: i64 = 90i64;
pub const CONTEXT_SYMBOL: i64 = 91i64;
pub const CONTINUE_SYMBOL: i64 = 92i64;
pub const CONTRIBUTORS_SYMBOL: i64 = 93i64;
pub const CONVERT_SYMBOL: i64 = 94i64;
pub const COUNT_SYMBOL: i64 = 95i64;
pub const CPU_SYMBOL: i64 = 96i64;
pub const CREATE_SYMBOL: i64 = 97i64;
pub const CROSS_SYMBOL: i64 = 98i64;
pub const CUBE_SYMBOL: i64 = 99i64;
pub const CURDATE_SYMBOL: i64 = 100i64;
pub const CURRENT_SYMBOL: i64 = 101i64;
pub const CURRENT_DATE_SYMBOL: i64 = 102i64;
pub const CURRENT_TIME_SYMBOL: i64 = 103i64;
pub const CURRENT_TIMESTAMP_SYMBOL: i64 = 104i64;
pub const CURRENT_USER_SYMBOL: i64 = 105i64;
pub const CURSOR_SYMBOL: i64 = 106i64;
pub const CURSOR_NAME_SYMBOL: i64 = 107i64;
pub const CURTIME_SYMBOL: i64 = 108i64;
pub const DATABASE_SYMBOL: i64 = 109i64;
pub const DATABASES_SYMBOL: i64 = 110i64;
pub const DATAFILE_SYMBOL: i64 = 111i64;
pub const DATA_SYMBOL: i64 = 112i64;
pub const DATETIME_SYMBOL: i64 = 113i64;
pub const DATE_ADD_SYMBOL: i64 = 114i64;
pub const DATE_SUB_SYMBOL: i64 = 115i64;
pub const DATE_SYMBOL: i64 = 116i64;
pub const DAYOFMONTH_SYMBOL: i64 = 117i64;
pub const DAY_HOUR_SYMBOL: i64 = 118i64;
pub const DAY_MICROSECOND_SYMBOL: i64 = 119i64;
pub const DAY_MINUTE_SYMBOL: i64 = 120i64;
pub const DAY_SECOND_SYMBOL: i64 = 121i64;
pub const DAY_SYMBOL: i64 = 122i64;
pub const DEALLOCATE_SYMBOL: i64 = 123i64;
pub const DEC_SYMBOL: i64 = 124i64;
pub const DECIMAL_NUM_SYMBOL: i64 = 125i64;
pub const DECIMAL_SYMBOL: i64 = 126i64;
pub const DECLARE_SYMBOL: i64 = 127i64;
pub const DEFAULT_SYMBOL: i64 = 128i64;
pub const DEFAULT_AUTH_SYMBOL: i64 = 129i64;
pub const DEFINER_SYMBOL: i64 = 130i64;
pub const DELAYED_SYMBOL: i64 = 131i64;
pub const DELAY_KEY_WRITE_SYMBOL: i64 = 132i64;
pub const DELETE_SYMBOL: i64 = 133i64;
pub const DESC_SYMBOL: i64 = 134i64;
pub const DESCRIBE_SYMBOL: i64 = 135i64;
pub const DES_KEY_FILE_SYMBOL: i64 = 136i64;
pub const DETERMINISTIC_SYMBOL: i64 = 137i64;
pub const DIAGNOSTICS_SYMBOL: i64 = 138i64;
pub const DIRECTORY_SYMBOL: i64 = 139i64;
pub const DISABLE_SYMBOL: i64 = 140i64;
pub const DISCARD_SYMBOL: i64 = 141i64;
pub const DISK_SYMBOL: i64 = 142i64;
pub const DISTINCT_SYMBOL: i64 = 143i64;
pub const DISTINCTROW_SYMBOL: i64 = 144i64;
pub const DIV_SYMBOL: i64 = 145i64;
pub const DOUBLE_SYMBOL: i64 = 146i64;
pub const DO_SYMBOL: i64 = 147i64;
pub const DROP_SYMBOL: i64 = 148i64;
pub const DUAL_SYMBOL: i64 = 149i64;
pub const DUMPFILE_SYMBOL: i64 = 150i64;
pub const DUPLICATE_SYMBOL: i64 = 151i64;
pub const DYNAMIC_SYMBOL: i64 = 152i64;
pub const EACH_SYMBOL: i64 = 153i64;
pub const ELSE_SYMBOL: i64 = 154i64;
pub const ELSEIF_SYMBOL: i64 = 155i64;
pub const ENABLE_SYMBOL: i64 = 156i64;
pub const ENCLOSED_SYMBOL: i64 = 157i64;
pub const ENCRYPTION_SYMBOL: i64 = 158i64;
pub const END_SYMBOL: i64 = 159i64;
pub const ENDS_SYMBOL: i64 = 160i64;
pub const END_OF_INPUT_SYMBOL: i64 = 161i64;
pub const ENGINES_SYMBOL: i64 = 162i64;
pub const ENGINE_SYMBOL: i64 = 163i64;
pub const ENUM_SYMBOL: i64 = 164i64;
pub const ERROR_SYMBOL: i64 = 165i64;
pub const ERRORS_SYMBOL: i64 = 166i64;
pub const ESCAPED_SYMBOL: i64 = 167i64;
pub const ESCAPE_SYMBOL: i64 = 168i64;
pub const EVENTS_SYMBOL: i64 = 169i64;
pub const EVENT_SYMBOL: i64 = 170i64;
pub const EVERY_SYMBOL: i64 = 171i64;
pub const EXCHANGE_SYMBOL: i64 = 172i64;
pub const EXECUTE_SYMBOL: i64 = 173i64;
pub const EXISTS_SYMBOL: i64 = 174i64;
pub const EXIT_SYMBOL: i64 = 175i64;
pub const EXPANSION_SYMBOL: i64 = 176i64;
pub const EXPIRE_SYMBOL: i64 = 177i64;
pub const EXPLAIN_SYMBOL: i64 = 178i64;
pub const EXPORT_SYMBOL: i64 = 179i64;
pub const EXTENDED_SYMBOL: i64 = 180i64;
pub const EXTENT_SIZE_SYMBOL: i64 = 181i64;
pub const EXTRACT_SYMBOL: i64 = 182i64;
pub const FALSE_SYMBOL: i64 = 183i64;
pub const FAST_SYMBOL: i64 = 184i64;
pub const FAULTS_SYMBOL: i64 = 185i64;
pub const FETCH_SYMBOL: i64 = 186i64;
pub const FIELDS_SYMBOL: i64 = 187i64;
pub const FILE_SYMBOL: i64 = 188i64;
pub const FILE_BLOCK_SIZE_SYMBOL: i64 = 189i64;
pub const FILTER_SYMBOL: i64 = 190i64;
pub const FIRST_SYMBOL: i64 = 191i64;
pub const FIXED_SYMBOL: i64 = 192i64;
pub const FLOAT4_SYMBOL: i64 = 193i64;
pub const FLOAT8_SYMBOL: i64 = 194i64;
pub const FLOAT_SYMBOL: i64 = 195i64;
pub const FLUSH_SYMBOL: i64 = 196i64;
pub const FOLLOWS_SYMBOL: i64 = 197i64;
pub const FORCE_SYMBOL: i64 = 198i64;
pub const FOREIGN_SYMBOL: i64 = 199i64;
pub const FOR_SYMBOL: i64 = 200i64;
pub const FORMAT_SYMBOL: i64 = 201i64;
pub const FOUND_SYMBOL: i64 = 202i64;
pub const FROM_SYMBOL: i64 = 203i64;
pub const FULL_SYMBOL: i64 = 204i64;
pub const FULLTEXT_SYMBOL: i64 = 205i64;
pub const FUNCTION_SYMBOL: i64 = 206i64;
pub const GET_SYMBOL: i64 = 207i64;
pub const GENERAL_SYMBOL: i64 = 208i64;
pub const GENERATED_SYMBOL: i64 = 209i64;
pub const GROUP_REPLICATION_SYMBOL: i64 = 210i64;
pub const GEOMETRYCOLLECTION_SYMBOL: i64 = 211i64;
pub const GEOMETRY_SYMBOL: i64 = 212i64;
pub const GET_FORMAT_SYMBOL: i64 = 213i64;
pub const GLOBAL_SYMBOL: i64 = 214i64;
pub const GRANT_SYMBOL: i64 = 215i64;
pub const GRANTS_SYMBOL: i64 = 216i64;
pub const GROUP_SYMBOL: i64 = 217i64;
pub const GROUP_CONCAT_SYMBOL: i64 = 218i64;
pub const HANDLER_SYMBOL: i64 = 219i64;
pub const HASH_SYMBOL: i64 = 220i64;
pub const HAVING_SYMBOL: i64 = 221i64;
pub const HELP_SYMBOL: i64 = 222i64;
pub const HIGH_PRIORITY_SYMBOL: i64 = 223i64;
pub const HOST_SYMBOL: i64 = 224i64;
pub const HOSTS_SYMBOL: i64 = 225i64;
pub const HOUR_MICROSECOND_SYMBOL: i64 = 226i64;
pub const HOUR_MINUTE_SYMBOL: i64 = 227i64;
pub const HOUR_SECOND_SYMBOL: i64 = 228i64;
pub const HOUR_SYMBOL: i64 = 229i64;
pub const IDENTIFIED_SYMBOL: i64 = 230i64;
pub const IF_SYMBOL: i64 = 231i64;
pub const IGNORE_SYMBOL: i64 = 232i64;
pub const IGNORE_SERVER_IDS_SYMBOL: i64 = 233i64;
pub const IMPORT_SYMBOL: i64 = 234i64;
pub const INDEXES_SYMBOL: i64 = 235i64;
pub const INDEX_SYMBOL: i64 = 236i64;
pub const INFILE_SYMBOL: i64 = 237i64;
pub const INITIAL_SIZE_SYMBOL: i64 = 238i64;
pub const INNER_SYMBOL: i64 = 239i64;
pub const INOUT_SYMBOL: i64 = 240i64;
pub const INSENSITIVE_SYMBOL: i64 = 241i64;
pub const INSERT_SYMBOL: i64 = 242i64;
pub const INSERT_METHOD_SYMBOL: i64 = 243i64;
pub const INSTANCE_SYMBOL: i64 = 244i64;
pub const INSTALL_SYMBOL: i64 = 245i64;
pub const INTEGER_SYMBOL: i64 = 246i64;
pub const INTERVAL_SYMBOL: i64 = 247i64;
pub const INTO_SYMBOL: i64 = 248i64;
pub const INT_SYMBOL: i64 = 249i64;
pub const INVOKER_SYMBOL: i64 = 250i64;
pub const IN_SYMBOL: i64 = 251i64;
pub const IO_AFTER_GTIDS_SYMBOL: i64 = 252i64;
pub const IO_BEFORE_GTIDS_SYMBOL: i64 = 253i64;
pub const IO_THREAD_SYMBOL: i64 = 254i64;
pub const IO_SYMBOL: i64 = 255i64;
pub const IPC_SYMBOL: i64 = 256i64;
pub const IS_SYMBOL: i64 = 257i64;
pub const ISOLATION_SYMBOL: i64 = 258i64;
pub const ISSUER_SYMBOL: i64 = 259i64;
pub const ITERATE_SYMBOL: i64 = 260i64;
pub const JOIN_SYMBOL: i64 = 261i64;
pub const JSON_SYMBOL: i64 = 262i64;
pub const KEYS_SYMBOL: i64 = 263i64;
pub const KEY_BLOCK_SIZE_SYMBOL: i64 = 264i64;
pub const KEY_SYMBOL: i64 = 265i64;
pub const KILL_SYMBOL: i64 = 266i64;
pub const LANGUAGE_SYMBOL: i64 = 267i64;
pub const LAST_SYMBOL: i64 = 268i64;
pub const LEADING_SYMBOL: i64 = 269i64;
pub const LEAVES_SYMBOL: i64 = 270i64;
pub const LEAVE_SYMBOL: i64 = 271i64;
pub const LEFT_SYMBOL: i64 = 272i64;
pub const LESS_SYMBOL: i64 = 273i64;
pub const LEVEL_SYMBOL: i64 = 274i64;
pub const LIKE_SYMBOL: i64 = 275i64;
pub const LIMIT_SYMBOL: i64 = 276i64;
pub const LINEAR_SYMBOL: i64 = 277i64;
pub const LINES_SYMBOL: i64 = 278i64;
pub const LINESTRING_SYMBOL: i64 = 279i64;
pub const LIST_SYMBOL: i64 = 280i64;
pub const LOAD_SYMBOL: i64 = 281i64;
pub const LOCALTIME_SYMBOL: i64 = 282i64;
pub const LOCALTIMESTAMP_SYMBOL: i64 = 283i64;
pub const LOCAL_SYMBOL: i64 = 284i64;
pub const LOCATOR_SYMBOL: i64 = 285i64;
pub const LOCKS_SYMBOL: i64 = 286i64;
pub const LOCK_SYMBOL: i64 = 287i64;
pub const LOGFILE_SYMBOL: i64 = 288i64;
pub const LOGS_SYMBOL: i64 = 289i64;
pub const LONGBLOB_SYMBOL: i64 = 290i64;
pub const LONGTEXT_SYMBOL: i64 = 291i64;
pub const LONG_NUM_SYMBOL: i64 = 292i64;
pub const LONG_SYMBOL: i64 = 293i64;
pub const LOOP_SYMBOL: i64 = 294i64;
pub const LOW_PRIORITY_SYMBOL: i64 = 295i64;
pub const MASTER_AUTO_POSITION_SYMBOL: i64 = 296i64;
pub const MASTER_BIND_SYMBOL: i64 = 297i64;
pub const MASTER_CONNECT_RETRY_SYMBOL: i64 = 298i64;
pub const MASTER_DELAY_SYMBOL: i64 = 299i64;
pub const MASTER_HOST_SYMBOL: i64 = 300i64;
pub const MASTER_LOG_FILE_SYMBOL: i64 = 301i64;
pub const MASTER_LOG_POS_SYMBOL: i64 = 302i64;
pub const MASTER_PASSWORD_SYMBOL: i64 = 303i64;
pub const MASTER_PORT_SYMBOL: i64 = 304i64;
pub const MASTER_RETRY_COUNT_SYMBOL: i64 = 305i64;
pub const MASTER_SERVER_ID_SYMBOL: i64 = 306i64;
pub const MASTER_SSL_CAPATH_SYMBOL: i64 = 307i64;
pub const MASTER_SSL_CA_SYMBOL: i64 = 308i64;
pub const MASTER_SSL_CERT_SYMBOL: i64 = 309i64;
pub const MASTER_SSL_CIPHER_SYMBOL: i64 = 310i64;
pub const MASTER_SSL_CRL_SYMBOL: i64 = 311i64;
pub const MASTER_SSL_CRLPATH_SYMBOL: i64 = 312i64;
pub const MASTER_SSL_KEY_SYMBOL: i64 = 313i64;
pub const MASTER_SSL_SYMBOL: i64 = 314i64;
pub const MASTER_SSL_VERIFY_SERVER_CERT_SYMBOL: i64 = 315i64;
pub const MASTER_SYMBOL: i64 = 316i64;
pub const MASTER_TLS_VERSION_SYMBOL: i64 = 317i64;
pub const MASTER_USER_SYMBOL: i64 = 318i64;
pub const MASTER_HEARTBEAT_PERIOD_SYMBOL: i64 = 319i64;
pub const MATCH_SYMBOL: i64 = 320i64;
pub const MAX_CONNECTIONS_PER_HOUR_SYMBOL: i64 = 321i64;
pub const MAX_QUERIES_PER_HOUR_SYMBOL: i64 = 322i64;
pub const MAX_ROWS_SYMBOL: i64 = 323i64;
pub const MAX_SIZE_SYMBOL: i64 = 324i64;
pub const MAX_STATEMENT_TIME_SYMBOL: i64 = 325i64;
pub const MAX_SYMBOL: i64 = 326i64;
pub const MAX_UPDATES_PER_HOUR_SYMBOL: i64 = 327i64;
pub const MAX_USER_CONNECTIONS_SYMBOL: i64 = 328i64;
pub const MAXVALUE_SYMBOL: i64 = 329i64;
pub const MEDIUMBLOB_SYMBOL: i64 = 330i64;
pub const MEDIUMINT_SYMBOL: i64 = 331i64;
pub const MEDIUMTEXT_SYMBOL: i64 = 332i64;
pub const MEDIUM_SYMBOL: i64 = 333i64;
pub const MEMORY_SYMBOL: i64 = 334i64;
pub const MERGE_SYMBOL: i64 = 335i64;
pub const MESSAGE_TEXT_SYMBOL: i64 = 336i64;
pub const MICROSECOND_SYMBOL: i64 = 337i64;
pub const MID_SYMBOL: i64 = 338i64;
pub const MIDDLEINT_SYMBOL: i64 = 339i64;
pub const MIGRATE_SYMBOL: i64 = 340i64;
pub const MINUTE_MICROSECOND_SYMBOL: i64 = 341i64;
pub const MINUTE_SECOND_SYMBOL: i64 = 342i64;
pub const MINUTE_SYMBOL: i64 = 343i64;
pub const MIN_ROWS_SYMBOL: i64 = 344i64;
pub const MIN_SYMBOL: i64 = 345i64;
pub const MODE_SYMBOL: i64 = 346i64;
pub const MODIFIES_SYMBOL: i64 = 347i64;
pub const MODIFY_SYMBOL: i64 = 348i64;
pub const MOD_SYMBOL: i64 = 349i64;
pub const MONTH_SYMBOL: i64 = 350i64;
pub const MULTILINESTRING_SYMBOL: i64 = 351i64;
pub const MULTIPOINT_SYMBOL: i64 = 352i64;
pub const MULTIPOLYGON_SYMBOL: i64 = 353i64;
pub const MUTEX_SYMBOL: i64 = 354i64;
pub const MYSQL_ERRNO_SYMBOL: i64 = 355i64;
pub const NAMES_SYMBOL: i64 = 356i64;
pub const NAME_SYMBOL: i64 = 357i64;
pub const NATIONAL_SYMBOL: i64 = 358i64;
pub const NATURAL_SYMBOL: i64 = 359i64;
pub const NCHAR_STRING_SYMBOL: i64 = 360i64;
pub const NCHAR_SYMBOL: i64 = 361i64;
pub const NDB_SYMBOL: i64 = 362i64;
pub const NDBCLUSTER_SYMBOL: i64 = 363i64;
pub const NEG_SYMBOL: i64 = 364i64;
pub const NEVER_SYMBOL: i64 = 365i64;
pub const NEW_SYMBOL: i64 = 366i64;
pub const NEXT_SYMBOL: i64 = 367i64;
pub const NODEGROUP_SYMBOL: i64 = 368i64;
pub const NONE_SYMBOL: i64 = 369i64;
pub const NONBLOCKING_SYMBOL: i64 = 370i64;
pub const NOT_SYMBOL: i64 = 371i64;
pub const NOW_SYMBOL: i64 = 372i64;
pub const NO_SYMBOL: i64 = 373i64;
pub const NO_WAIT_SYMBOL: i64 = 374i64;
pub const NO_WRITE_TO_BINLOG_SYMBOL: i64 = 375i64;
pub const NULL_SYMBOL: i64 = 376i64;
pub const NUMBER_SYMBOL: i64 = 377i64;
pub const NUMERIC_SYMBOL: i64 = 378i64;
pub const NVARCHAR_SYMBOL: i64 = 379i64;
pub const OFFLINE_SYMBOL: i64 = 380i64;
pub const OFFSET_SYMBOL: i64 = 381i64;
pub const OLD_PASSWORD_SYMBOL: i64 = 382i64;
pub const ON_SYMBOL: i64 = 383i64;
pub const ONE_SYMBOL: i64 = 384i64;
pub const ONLINE_SYMBOL: i64 = 385i64;
pub const ONLY_SYMBOL: i64 = 386i64;
pub const OPEN_SYMBOL: i64 = 387i64;
pub const OPTIMIZE_SYMBOL: i64 = 388i64;
pub const OPTIMIZER_COSTS_SYMBOL: i64 = 389i64;
pub const OPTIONS_SYMBOL: i64 = 390i64;
pub const OPTION_SYMBOL: i64 = 391i64;
pub const OPTIONALLY_SYMBOL: i64 = 392i64;
pub const ORDER_SYMBOL: i64 = 393i64;
pub const OR_SYMBOL: i64 = 394i64;
pub const OUTER_SYMBOL: i64 = 395i64;
pub const OUTFILE_SYMBOL: i64 = 396i64;
pub const OUT_SYMBOL: i64 = 397i64;
pub const OWNER_SYMBOL: i64 = 398i64;
pub const PACK_KEYS_SYMBOL: i64 = 399i64;
pub const PAGE_SYMBOL: i64 = 400i64;
pub const PARSER_SYMBOL: i64 = 401i64;
pub const PARTIAL_SYMBOL: i64 = 402i64;
pub const PARTITIONING_SYMBOL: i64 = 403i64;
pub const PARTITIONS_SYMBOL: i64 = 404i64;
pub const PARTITION_SYMBOL: i64 = 405i64;
pub const PASSWORD_SYMBOL: i64 = 406i64;
pub const PHASE_SYMBOL: i64 = 407i64;
pub const PLUGINS_SYMBOL: i64 = 408i64;
pub const PLUGIN_DIR_SYMBOL: i64 = 409i64;
pub const PLUGIN_SYMBOL: i64 = 410i64;
pub const POINT_SYMBOL: i64 = 411i64;
pub const POLYGON_SYMBOL: i64 = 412i64;
pub const PORT_SYMBOL: i64 = 413i64;
pub const POSITION_SYMBOL: i64 = 414i64;
pub const PRECEDES_SYMBOL: i64 = 415i64;
pub const PRECISION_SYMBOL: i64 = 416i64;
pub const PREPARE_SYMBOL: i64 = 417i64;
pub const PRESERVE_SYMBOL: i64 = 418i64;
pub const PREV_SYMBOL: i64 = 419i64;
pub const PRIMARY_SYMBOL: i64 = 420i64;
pub const PRIVILEGES_SYMBOL: i64 = 421i64;
pub const PROCEDURE_SYMBOL: i64 = 422i64;
pub const PROCESS_SYMBOL: i64 = 423i64;
pub const PROCESSLIST_SYMBOL: i64 = 424i64;
pub const PROFILE_SYMBOL: i64 = 425i64;
pub const PROFILES_SYMBOL: i64 = 426i64;
pub const PROXY_SYMBOL: i64 = 427i64;
pub const PURGE_SYMBOL: i64 = 428i64;
pub const QUARTER_SYMBOL: i64 = 429i64;
pub const QUERY_SYMBOL: i64 = 430i64;
pub const QUICK_SYMBOL: i64 = 431i64;
pub const RANGE_SYMBOL: i64 = 432i64;
pub const READS_SYMBOL: i64 = 433i64;
pub const READ_ONLY_SYMBOL: i64 = 434i64;
pub const READ_SYMBOL: i64 = 435i64;
pub const READ_WRITE_SYMBOL: i64 = 436i64;
pub const REAL_SYMBOL: i64 = 437i64;
pub const REBUILD_SYMBOL: i64 = 438i64;
pub const RECOVER_SYMBOL: i64 = 439i64;
pub const REDOFILE_SYMBOL: i64 = 440i64;
pub const REDO_BUFFER_SIZE_SYMBOL: i64 = 441i64;
pub const REDUNDANT_SYMBOL: i64 = 442i64;
pub const REFERENCES_SYMBOL: i64 = 443i64;
pub const REGEXP_SYMBOL: i64 = 444i64;
pub const RELAY_SYMBOL: i64 = 445i64;
pub const RELAYLOG_SYMBOL: i64 = 446i64;
pub const RELAY_LOG_FILE_SYMBOL: i64 = 447i64;
pub const RELAY_LOG_POS_SYMBOL: i64 = 448i64;
pub const RELAY_THREAD_SYMBOL: i64 = 449i64;
pub const RELEASE_SYMBOL: i64 = 450i64;
pub const RELOAD_SYMBOL: i64 = 451i64;
pub const REMOVE_SYMBOL: i64 = 452i64;
pub const RENAME_SYMBOL: i64 = 453i64;
pub const REORGANIZE_SYMBOL: i64 = 454i64;
pub const REPAIR_SYMBOL: i64 = 455i64;
pub const REPEATABLE_SYMBOL: i64 = 456i64;
pub const REPEAT_SYMBOL: i64 = 457i64;
pub const REPLACE_SYMBOL: i64 = 458i64;
pub const REPLICATION_SYMBOL: i64 = 459i64;
pub const REPLICATE_DO_DB_SYMBOL: i64 = 460i64;
pub const REPLICATE_IGNORE_DB_SYMBOL: i64 = 461i64;
pub const REPLICATE_DO_TABLE_SYMBOL: i64 = 462i64;
pub const REPLICATE_IGNORE_TABLE_SYMBOL: i64 = 463i64;
pub const REPLICATE_WILD_DO_TABLE_SYMBOL: i64 = 464i64;
pub const REPLICATE_WILD_IGNORE_TABLE_SYMBOL: i64 = 465i64;
pub const REPLICATE_REWRITE_DB_SYMBOL: i64 = 466i64;
pub const REQUIRE_SYMBOL: i64 = 467i64;
pub const RESET_SYMBOL: i64 = 468i64;
pub const RESIGNAL_SYMBOL: i64 = 469i64;
pub const RESTORE_SYMBOL: i64 = 470i64;
pub const RESTRICT_SYMBOL: i64 = 471i64;
pub const RESUME_SYMBOL: i64 = 472i64;
pub const RETURNED_SQLSTATE_SYMBOL: i64 = 473i64;
pub const RETURNS_SYMBOL: i64 = 474i64;
pub const RETURN_SYMBOL: i64 = 475i64;
pub const REVERSE_SYMBOL: i64 = 476i64;
pub const REVOKE_SYMBOL: i64 = 477i64;
pub const RIGHT_SYMBOL: i64 = 478i64;
pub const RLIKE_SYMBOL: i64 = 479i64;
pub const ROLLBACK_SYMBOL: i64 = 480i64;
pub const ROLLUP_SYMBOL: i64 = 481i64;
pub const ROTATE_SYMBOL: i64 = 482i64;
pub const ROUTINE_SYMBOL: i64 = 483i64;
pub const ROWS_SYMBOL: i64 = 484i64;
pub const ROW_COUNT_SYMBOL: i64 = 485i64;
pub const ROW_FORMAT_SYMBOL: i64 = 486i64;
pub const ROW_SYMBOL: i64 = 487i64;
pub const RTREE_SYMBOL: i64 = 488i64;
pub const SAVEPOINT_SYMBOL: i64 = 489i64;
pub const SCHEDULE_SYMBOL: i64 = 490i64;
pub const SCHEMA_SYMBOL: i64 = 491i64;
pub const SCHEMA_NAME_SYMBOL: i64 = 492i64;
pub const SCHEMAS_SYMBOL: i64 = 493i64;
pub const SECOND_MICROSECOND_SYMBOL: i64 = 494i64;
pub const SECOND_SYMBOL: i64 = 495i64;
pub const SECURITY_SYMBOL: i64 = 496i64;
pub const SELECT_SYMBOL: i64 = 497i64;
pub const SENSITIVE_SYMBOL: i64 = 498i64;
pub const SEPARATOR_SYMBOL: i64 = 499i64;
pub const SERIALIZABLE_SYMBOL: i64 = 500i64;
pub const SERIAL_SYMBOL: i64 = 501i64;
pub const SESSION_SYMBOL: i64 = 502i64;
pub const SERVER_SYMBOL: i64 = 503i64;
pub const SERVER_OPTIONS_SYMBOL: i64 = 504i64;
pub const SESSION_USER_SYMBOL: i64 = 505i64;
pub const SET_SYMBOL: i64 = 506i64;
pub const SET_VAR_SYMBOL: i64 = 507i64;
pub const SHARE_SYMBOL: i64 = 508i64;
pub const SHOW_SYMBOL: i64 = 509i64;
pub const SHUTDOWN_SYMBOL: i64 = 510i64;
pub const SIGNAL_SYMBOL: i64 = 511i64;
pub const SIGNED_SYMBOL: i64 = 512i64;
pub const SIMPLE_SYMBOL: i64 = 513i64;
pub const SLAVE_SYMBOL: i64 = 514i64;
pub const SLOW_SYMBOL: i64 = 515i64;
pub const SMALLINT_SYMBOL: i64 = 516i64;
pub const SNAPSHOT_SYMBOL: i64 = 517i64;
pub const SOME_SYMBOL: i64 = 518i64;
pub const SOCKET_SYMBOL: i64 = 519i64;
pub const SONAME_SYMBOL: i64 = 520i64;
pub const SOUNDS_SYMBOL: i64 = 521i64;
pub const SOURCE_SYMBOL: i64 = 522i64;
pub const SPATIAL_SYMBOL: i64 = 523i64;
pub const SPECIFIC_SYMBOL: i64 = 524i64;
pub const SQLEXCEPTION_SYMBOL: i64 = 525i64;
pub const SQLSTATE_SYMBOL: i64 = 526i64;
pub const SQLWARNING_SYMBOL: i64 = 527i64;
pub const SQL_AFTER_GTIDS_SYMBOL: i64 = 528i64;
pub const SQL_AFTER_MTS_GAPS_SYMBOL: i64 = 529i64;
pub const SQL_BEFORE_GTIDS_SYMBOL: i64 = 530i64;
pub const SQL_BIG_RESULT_SYMBOL: i64 = 531i64;
pub const SQL_BUFFER_RESULT_SYMBOL: i64 = 532i64;
pub const SQL_CACHE_SYMBOL: i64 = 533i64;
pub const SQL_CALC_FOUND_ROWS_SYMBOL: i64 = 534i64;
pub const SQL_NO_CACHE_SYMBOL: i64 = 535i64;
pub const SQL_SMALL_RESULT_SYMBOL: i64 = 536i64;
pub const SQL_SYMBOL: i64 = 537i64;
pub const SQL_THREAD_SYMBOL: i64 = 538i64;
pub const SSL_SYMBOL: i64 = 539i64;
pub const STACKED_SYMBOL: i64 = 540i64;
pub const STARTING_SYMBOL: i64 = 541i64;
pub const STARTS_SYMBOL: i64 = 542i64;
pub const START_SYMBOL: i64 = 543i64;
pub const STATS_AUTO_RECALC_SYMBOL: i64 = 544i64;
pub const STATS_PERSISTENT_SYMBOL: i64 = 545i64;
pub const STATS_SAMPLE_PAGES_SYMBOL: i64 = 546i64;
pub const STATUS_SYMBOL: i64 = 547i64;
pub const STDDEV_SAMP_SYMBOL: i64 = 548i64;
pub const STDDEV_SYMBOL: i64 = 549i64;
pub const STDDEV_POP_SYMBOL: i64 = 550i64;
pub const STD_SYMBOL: i64 = 551i64;
pub const STOP_SYMBOL: i64 = 552i64;
pub const STORAGE_SYMBOL: i64 = 553i64;
pub const STORED_SYMBOL: i64 = 554i64;
pub const STRAIGHT_JOIN_SYMBOL: i64 = 555i64;
pub const STRING_SYMBOL: i64 = 556i64;
pub const SUBCLASS_ORIGIN_SYMBOL: i64 = 557i64;
pub const SUBDATE_SYMBOL: i64 = 558i64;
pub const SUBJECT_SYMBOL: i64 = 559i64;
pub const SUBPARTITIONS_SYMBOL: i64 = 560i64;
pub const SUBPARTITION_SYMBOL: i64 = 561i64;
pub const SUBSTR_SYMBOL: i64 = 562i64;
pub const SUBSTRING_SYMBOL: i64 = 563i64;
pub const SUM_SYMBOL: i64 = 564i64;
pub const SUPER_SYMBOL: i64 = 565i64;
pub const SUSPEND_SYMBOL: i64 = 566i64;
pub const SWAPS_SYMBOL: i64 = 567i64;
pub const SWITCHES_SYMBOL: i64 = 568i64;
pub const SYSDATE_SYMBOL: i64 = 569i64;
pub const SYSTEM_USER_SYMBOL: i64 = 570i64;
pub const TABLES_SYMBOL: i64 = 571i64;
pub const TABLESPACE_SYMBOL: i64 = 572i64;
pub const TABLE_REF_PRIORITY_SYMBOL: i64 = 573i64;
pub const TABLE_SYMBOL: i64 = 574i64;
pub const TABLE_CHECKSUM_SYMBOL: i64 = 575i64;
pub const TABLE_NAME_SYMBOL: i64 = 576i64;
pub const TEMPORARY_SYMBOL: i64 = 577i64;
pub const TEMPTABLE_SYMBOL: i64 = 578i64;
pub const TERMINATED_SYMBOL: i64 = 579i64;
pub const TEXT_SYMBOL: i64 = 580i64;
pub const THAN_SYMBOL: i64 = 581i64;
pub const THEN_SYMBOL: i64 = 582i64;
pub const TIMESTAMP_SYMBOL: i64 = 583i64;
pub const TIMESTAMP_ADD_SYMBOL: i64 = 584i64;
pub const TIMESTAMP_DIFF_SYMBOL: i64 = 585i64;
pub const TIME_SYMBOL: i64 = 586i64;
pub const TINYBLOB_SYMBOL: i64 = 587i64;
pub const TINYINT_SYMBOL: i64 = 588i64;
pub const TINYTEXT_SYMBOL: i64 = 589i64;
pub const TO_SYMBOL: i64 = 590i64;
pub const TRAILING_SYMBOL: i64 = 591i64;
pub const TRANSACTION_SYMBOL: i64 = 592i64;
pub const TRIGGERS_SYMBOL: i64 = 593i64;
pub const TRIGGER_SYMBOL: i64 = 594i64;
pub const TRIM_SYMBOL: i64 = 595i64;
pub const TRUE_SYMBOL: i64 = 596i64;
pub const TRUNCATE_SYMBOL: i64 = 597i64;
pub const TYPES_SYMBOL: i64 = 598i64;
pub const TYPE_SYMBOL: i64 = 599i64;
pub const UDF_RETURNS_SYMBOL: i64 = 600i64;
pub const UNCOMMITTED_SYMBOL: i64 = 601i64;
pub const UNDEFINED_SYMBOL: i64 = 602i64;
pub const UNDOFILE_SYMBOL: i64 = 603i64;
pub const UNDO_BUFFER_SIZE_SYMBOL: i64 = 604i64;
pub const UNDO_SYMBOL: i64 = 605i64;
pub const UNICODE_SYMBOL: i64 = 606i64;
pub const UNINSTALL_SYMBOL: i64 = 607i64;
pub const UNION_SYMBOL: i64 = 608i64;
pub const UNIQUE_SYMBOL: i64 = 609i64;
pub const UNKNOWN_SYMBOL: i64 = 610i64;
pub const UNLOCK_SYMBOL: i64 = 611i64;
pub const UNSIGNED_SYMBOL: i64 = 612i64;
pub const UNTIL_SYMBOL: i64 = 613i64;
pub const UPDATE_SYMBOL: i64 = 614i64;
pub const UPGRADE_SYMBOL: i64 = 615i64;
pub const USAGE_SYMBOL: i64 = 616i64;
pub const USER_RESOURCES_SYMBOL: i64 = 617i64;
pub const USER_SYMBOL: i64 = 618i64;
pub const USE_FRM_SYMBOL: i64 = 619i64;
pub const USE_SYMBOL: i64 = 620i64;
pub const USING_SYMBOL: i64 = 621i64;
pub const UTC_DATE_SYMBOL: i64 = 622i64;
pub const UTC_TIMESTAMP_SYMBOL: i64 = 623i64;
pub const UTC_TIME_SYMBOL: i64 = 624i64;
pub const VALIDATION_SYMBOL: i64 = 625i64;
pub const VALUES_SYMBOL: i64 = 626i64;
pub const VALUE_SYMBOL: i64 = 627i64;
pub const VARBINARY_SYMBOL: i64 = 628i64;
pub const VARCHAR_SYMBOL: i64 = 629i64;
pub const VARCHARACTER_SYMBOL: i64 = 630i64;
pub const VARIABLES_SYMBOL: i64 = 631i64;
pub const VARIANCE_SYMBOL: i64 = 632i64;
pub const VARYING_SYMBOL: i64 = 633i64;
pub const VAR_POP_SYMBOL: i64 = 634i64;
pub const VAR_SAMP_SYMBOL: i64 = 635i64;
pub const VIEW_SYMBOL: i64 = 636i64;
pub const VIRTUAL_SYMBOL: i64 = 637i64;
pub const WAIT_SYMBOL: i64 = 638i64;
pub const WARNINGS_SYMBOL: i64 = 639i64;
pub const WEEK_SYMBOL: i64 = 640i64;
pub const WEIGHT_STRING_SYMBOL: i64 = 641i64;
pub const WHEN_SYMBOL: i64 = 642i64;
pub const WHERE_SYMBOL: i64 = 643i64;
pub const WHILE_SYMBOL: i64 = 644i64;
pub const WITH_SYMBOL: i64 = 645i64;
pub const WITHOUT_SYMBOL: i64 = 646i64;
pub const WORK_SYMBOL: i64 = 647i64;
pub const WRAPPER_SYMBOL: i64 = 648i64;
pub const WRITE_SYMBOL: i64 = 649i64;
pub const X509_SYMBOL: i64 = 650i64;
pub const XA_SYMBOL: i64 = 651i64;
pub const XID_SYMBOL: i64 = 652i64;
pub const XML_SYMBOL: i64 = 653i64;
pub const XOR_SYMBOL: i64 = 654i64;
pub const YEAR_MONTH_SYMBOL: i64 = 655i64;
pub const YEAR_SYMBOL: i64 = 656i64;
pub const ZEROFILL_SYMBOL: i64 = 657i64;
pub const PERSIST_SYMBOL: i64 = 658i64;
pub const ROLE_SYMBOL: i64 = 659i64;
pub const ADMIN_SYMBOL: i64 = 660i64;
pub const INVISIBLE_SYMBOL: i64 = 661i64;
pub const VISIBLE_SYMBOL: i64 = 662i64;
pub const EXCEPT_SYMBOL: i64 = 663i64;
pub const COMPONENT_SYMBOL: i64 = 664i64;
pub const RECURSIVE_SYMBOL: i64 = 665i64;
pub const JSON_OBJECTAGG_SYMBOL: i64 = 666i64;
pub const JSON_ARRAYAGG_SYMBOL: i64 = 667i64;
pub const OF_SYMBOL: i64 = 668i64;
pub const SKIP_SYMBOL: i64 = 669i64;
pub const LOCKED_SYMBOL: i64 = 670i64;
pub const NOWAIT_SYMBOL: i64 = 671i64;
pub const GROUPING_SYMBOL: i64 = 672i64;
pub const PERSIST_ONLY_SYMBOL: i64 = 673i64;
pub const HISTOGRAM_SYMBOL: i64 = 674i64;
pub const BUCKETS_SYMBOL: i64 = 675i64;
pub const REMOTE_SYMBOL: i64 = 676i64;
pub const CLONE_SYMBOL: i64 = 677i64;
pub const CUME_DIST_SYMBOL: i64 = 678i64;
pub const DENSE_RANK_SYMBOL: i64 = 679i64;
pub const EXCLUDE_SYMBOL: i64 = 680i64;
pub const FIRST_VALUE_SYMBOL: i64 = 681i64;
pub const FOLLOWING_SYMBOL: i64 = 682i64;
pub const GROUPS_SYMBOL: i64 = 683i64;
pub const LAG_SYMBOL: i64 = 684i64;
pub const LAST_VALUE_SYMBOL: i64 = 685i64;
pub const LEAD_SYMBOL: i64 = 686i64;
pub const NTH_VALUE_SYMBOL: i64 = 687i64;
pub const NTILE_SYMBOL: i64 = 688i64;
pub const NULLS_SYMBOL: i64 = 689i64;
pub const OTHERS_SYMBOL: i64 = 690i64;
pub const OVER_SYMBOL: i64 = 691i64;
pub const PERCENT_RANK_SYMBOL: i64 = 692i64;
pub const PRECEDING_SYMBOL: i64 = 693i64;
pub const RANK_SYMBOL: i64 = 694i64;
pub const RESPECT_SYMBOL: i64 = 695i64;
pub const ROW_NUMBER_SYMBOL: i64 = 696i64;
pub const TIES_SYMBOL: i64 = 697i64;
pub const UNBOUNDED_SYMBOL: i64 = 698i64;
pub const WINDOW_SYMBOL: i64 = 699i64;
pub const EMPTY_SYMBOL: i64 = 700i64;
pub const JSON_TABLE_SYMBOL: i64 = 701i64;
pub const NESTED_SYMBOL: i64 = 702i64;
pub const ORDINALITY_SYMBOL: i64 = 703i64;
pub const PATH_SYMBOL: i64 = 704i64;
pub const HISTORY_SYMBOL: i64 = 705i64;
pub const REUSE_SYMBOL: i64 = 706i64;
pub const SRID_SYMBOL: i64 = 707i64;
pub const THREAD_PRIORITY_SYMBOL: i64 = 708i64;
pub const RESOURCE_SYMBOL: i64 = 709i64;
pub const SYSTEM_SYMBOL: i64 = 710i64;
pub const VCPU_SYMBOL: i64 = 711i64;
pub const MASTER_PUBLIC_KEY_PATH_SYMBOL: i64 = 712i64;
pub const GET_MASTER_PUBLIC_KEY_SYMBOL: i64 = 713i64;
pub const RESTART_SYMBOL: i64 = 714i64;
pub const DEFINITION_SYMBOL: i64 = 715i64;
pub const DESCRIPTION_SYMBOL: i64 = 716i64;
pub const ORGANIZATION_SYMBOL: i64 = 717i64;
pub const REFERENCE_SYMBOL: i64 = 718i64;
pub const OPTIONAL_SYMBOL: i64 = 719i64;
pub const SECONDARY_SYMBOL: i64 = 720i64;
pub const SECONDARY_ENGINE_SYMBOL: i64 = 721i64;
pub const SECONDARY_LOAD_SYMBOL: i64 = 722i64;
pub const SECONDARY_UNLOAD_SYMBOL: i64 = 723i64;
pub const ACTIVE_SYMBOL: i64 = 724i64;
pub const INACTIVE_SYMBOL: i64 = 725i64;
pub const LATERAL_SYMBOL: i64 = 726i64;
pub const RETAIN_SYMBOL: i64 = 727i64;
pub const OLD_SYMBOL: i64 = 728i64;
pub const NETWORK_NAMESPACE_SYMBOL: i64 = 729i64;
pub const ENFORCED_SYMBOL: i64 = 730i64;
pub const ARRAY_SYMBOL: i64 = 731i64;
pub const OJ_SYMBOL: i64 = 732i64;
pub const MEMBER_SYMBOL: i64 = 733i64;
pub const RANDOM_SYMBOL: i64 = 734i64;
pub const MASTER_COMPRESSION_ALGORITHM_SYMBOL: i64 = 735i64;
pub const MASTER_ZSTD_COMPRESSION_LEVEL_SYMBOL: i64 = 736i64;
pub const PRIVILEGE_CHECKS_USER_SYMBOL: i64 = 737i64;
pub const MASTER_TLS_CIPHERSUITES_SYMBOL: i64 = 738i64;
pub const REQUIRE_ROW_FORMAT_SYMBOL: i64 = 739i64;
pub const PASSWORD_LOCK_TIME_SYMBOL: i64 = 740i64;
pub const FAILED_LOGIN_ATTEMPTS_SYMBOL: i64 = 741i64;
pub const REQUIRE_TABLE_PRIMARY_KEY_CHECK_SYMBOL: i64 = 742i64;
pub const STREAM_SYMBOL: i64 = 743i64;
pub const OFF_SYMBOL: i64 = 744i64;
pub const AT_AT_SIGN_SYMBOL: i64 = 745i64;
pub const AT_SIGN_SYMBOL: i64 = 746i64;
pub const CLOSE_CURLY_SYMBOL: i64 = 747i64;
pub const CLOSE_PAR_SYMBOL: i64 = 748i64;
pub const COLON_SYMBOL: i64 = 749i64;
pub const COMMA_SYMBOL: i64 = 750i64;
pub const DOT_SYMBOL: i64 = 751i64;
pub const OPEN_CURLY_SYMBOL: i64 = 752i64;
pub const OPEN_PAR_SYMBOL: i64 = 753i64;
pub const PARAM_MARKER: i64 = 754i64;
pub const SEMICOLON_SYMBOL: i64 = 755i64;
pub const ASSIGN_OPERATOR: i64 = 756i64;
pub const BITWISE_AND_OPERATOR: i64 = 757i64;
pub const BITWISE_NOT_OPERATOR: i64 = 758i64;
pub const BITWISE_OR_OPERATOR: i64 = 759i64;
pub const BITWISE_XOR_OPERATOR: i64 = 760i64;
pub const CONCAT_PIPES_SYMBOL: i64 = 761i64;
pub const DIV_OPERATOR: i64 = 762i64;
pub const EQUAL_OPERATOR: i64 = 763i64;
pub const GREATER_OR_EQUAL_OPERATOR: i64 = 764i64;
pub const GREATER_THAN_OPERATOR: i64 = 765i64;
pub const JSON_SEPARATOR_SYMBOL: i64 = 766i64;
pub const JSON_UNQUOTED_SEPARATOR_SYMBOL: i64 = 767i64;
pub const LESS_OR_EQUAL_OPERATOR: i64 = 768i64;
pub const LESS_THAN_OPERATOR: i64 = 769i64;
pub const LOGICAL_AND_OPERATOR: i64 = 770i64;
pub const LOGICAL_NOT_OPERATOR: i64 = 771i64;
pub const LOGICAL_OR_OPERATOR: i64 = 772i64;
pub const MINUS_OPERATOR: i64 = 773i64;
pub const MOD_OPERATOR: i64 = 774i64;
pub const MULT_OPERATOR: i64 = 775i64;
pub const NOT_EQUAL_OPERATOR: i64 = 776i64;
pub const NULL_SAFE_EQUAL_OPERATOR: i64 = 777i64;
pub const PLUS_OPERATOR: i64 = 778i64;
pub const SHIFT_LEFT_OPERATOR: i64 = 779i64;
pub const SHIFT_RIGHT_OPERATOR: i64 = 780i64;
pub const BACK_TICK_QUOTED_ID: i64 = 781i64;
pub const BIN_NUMBER: i64 = 782i64;
pub const DECIMAL_NUMBER: i64 = 783i64;
pub const DOUBLE_QUOTED_TEXT: i64 = 784i64;
pub const FLOAT_NUMBER: i64 = 785i64;
pub const HEX_NUMBER: i64 = 786i64;
pub const INT_NUMBER: i64 = 787i64;
pub const LONG_NUMBER: i64 = 788i64;
pub const NCHAR_TEXT: i64 = 789i64;
pub const SINGLE_QUOTED_TEXT: i64 = 790i64;
pub const ULONGLONG_NUMBER: i64 = 791i64;
pub const AT_TEXT_SUFFIX: i64 = 792i64;
pub const IDENTIFIER: i64 = 793i64;
pub const UNDERSCORE_CHARSET: i64 = 794i64;
pub const INT1_SYMBOL: i64 = 795i64;
pub const INT2_SYMBOL: i64 = 796i64;
pub const INT3_SYMBOL: i64 = 797i64;
pub const INT4_SYMBOL: i64 = 798i64;
pub const INT8_SYMBOL: i64 = 799i64;
pub const NOT2_SYMBOL: i64 = 800i64;
pub const NULL2_SYMBOL: i64 = 801i64;
pub const SQL_TSI_DAY_SYMBOL: i64 = 802i64;
pub const SQL_TSI_HOUR_SYMBOL: i64 = 803i64;
pub const SQL_TSI_MICROSECOND_SYMBOL: i64 = 804i64;
pub const SQL_TSI_MINUTE_SYMBOL: i64 = 805i64;
pub const SQL_TSI_MONTH_SYMBOL: i64 = 806i64;
pub const SQL_TSI_QUARTER_SYMBOL: i64 = 807i64;
pub const SQL_TSI_SECOND_SYMBOL: i64 = 808i64;
pub const SQL_TSI_WEEK_SYMBOL: i64 = 809i64;
pub const SQL_TSI_YEAR_SYMBOL: i64 = 810i64;
pub const INTERSECT_SYMBOL: i64 = 811i64;
pub const ATTRIBUTE_SYMBOL: i64 = 812i64;
pub const SOURCE_AUTO_POSITION_SYMBOL: i64 = 813i64;
pub const SOURCE_BIND_SYMBOL: i64 = 814i64;
pub const SOURCE_COMPRESSION_ALGORITHM_SYMBOL: i64 = 815i64;
pub const SOURCE_CONNECT_RETRY_SYMBOL: i64 = 816i64;
pub const SOURCE_CONNECTION_AUTO_FAILOVER_SYMBOL: i64 = 817i64;
pub const SOURCE_DELAY_SYMBOL: i64 = 818i64;
pub const SOURCE_HEARTBEAT_PERIOD_SYMBOL: i64 = 819i64;
pub const SOURCE_HOST_SYMBOL: i64 = 820i64;
pub const SOURCE_LOG_FILE_SYMBOL: i64 = 821i64;
pub const SOURCE_LOG_POS_SYMBOL: i64 = 822i64;
pub const SOURCE_PASSWORD_SYMBOL: i64 = 823i64;
pub const SOURCE_PORT_SYMBOL: i64 = 824i64;
pub const SOURCE_PUBLIC_KEY_PATH_SYMBOL: i64 = 825i64;
pub const SOURCE_RETRY_COUNT_SYMBOL: i64 = 826i64;
pub const SOURCE_SSL_SYMBOL: i64 = 827i64;
pub const SOURCE_SSL_CA_SYMBOL: i64 = 828i64;
pub const SOURCE_SSL_CAPATH_SYMBOL: i64 = 829i64;
pub const SOURCE_SSL_CERT_SYMBOL: i64 = 830i64;
pub const SOURCE_SSL_CIPHER_SYMBOL: i64 = 831i64;
pub const SOURCE_SSL_CRL_SYMBOL: i64 = 832i64;
pub const SOURCE_SSL_CRLPATH_SYMBOL: i64 = 833i64;
pub const SOURCE_SSL_KEY_SYMBOL: i64 = 834i64;
pub const SOURCE_SSL_VERIFY_SERVER_CERT_SYMBOL: i64 = 835i64;
pub const SOURCE_TLS_CIPHERSUITES_SYMBOL: i64 = 836i64;
pub const SOURCE_TLS_VERSION_SYMBOL: i64 = 837i64;
pub const SOURCE_USER_SYMBOL: i64 = 838i64;
pub const SOURCE_ZSTD_COMPRESSION_LEVEL_SYMBOL: i64 = 839i64;
pub const GET_SOURCE_PUBLIC_KEY_SYMBOL: i64 = 840i64;
pub const GTID_ONLY_SYMBOL: i64 = 841i64;
pub const ASSIGN_GTIDS_TO_ANONYMOUS_TRANSACTIONS_SYMBOL: i64 = 842i64;
pub const ZONE_SYMBOL: i64 = 843i64;
pub const INNODB_SYMBOL: i64 = 844i64;
pub const TLS_SYMBOL: i64 = 845i64;
pub const REDO_LOG_SYMBOL: i64 = 846i64;
pub const KEYRING_SYMBOL: i64 = 847i64;
pub const ENGINE_ATTRIBUTE_SYMBOL: i64 = 848i64;
pub const SECONDARY_ENGINE_ATTRIBUTE_SYMBOL: i64 = 849i64;
pub const JSON_VALUE_SYMBOL: i64 = 850i64;
pub const RETURNING_SYMBOL: i64 = 851i64;
pub const GEOMCOLLECTION_SYMBOL: i64 = 852i64;
pub const COMMENT: i64 = 900i64;
pub const MYSQL_COMMENT_START: i64 = 901i64;
pub const MYSQL_COMMENT_END: i64 = 902i64;
pub const WHITESPACE: i64 = 0i64;
pub const EOF: i64 = -1i64;

pub const KEYWORD_TOKENS: &[(&str, i64)] = &[
    ("ACCESSIBLE", 1i64),
    ("ACCOUNT", 2i64),
    ("ACTION", 3i64),
    ("ADD", 4i64),
    ("ADDDATE", 5i64),
    ("AFTER", 6i64),
    ("AGAINST", 7i64),
    ("AGGREGATE", 8i64),
    ("ALGORITHM", 9i64),
    ("ALL", 10i64),
    ("ALTER", 11i64),
    ("ALWAYS", 12i64),
    ("ANALYSE", 13i64),
    ("ANALYZE", 14i64),
    ("AND", 15i64),
    ("ANY", 16i64),
    ("AS", 17i64),
    ("ASC", 18i64),
    ("ASCII", 19i64),
    ("ASENSITIVE", 20i64),
    ("AT", 21i64),
    ("ATTRIBUTE", 812i64),
    ("AUTHORS", 22i64),
    ("AUTO_INCREMENT", 24i64),
    ("AUTOEXTEND_SIZE", 23i64),
    ("AVG", 26i64),
    ("AVG_ROW_LENGTH", 25i64),
    ("BACKUP", 27i64),
    ("BEFORE", 28i64),
    ("BEGIN", 29i64),
    ("BETWEEN", 30i64),
    ("BIGINT", 31i64),
    ("BIN_NUM", 34i64),
    ("BINARY", 32i64),
    ("BINLOG", 33i64),
    ("BIT", 37i64),
    ("BIT_AND", 35i64),
    ("BIT_OR", 36i64),
    ("BIT_XOR", 38i64),
    ("BLOB", 39i64),
    ("BLOCK", 40i64),
    ("BOOL", 42i64),
    ("BOOLEAN", 41i64),
    ("BOTH", 43i64),
    ("BTREE", 44i64),
    ("BY", 45i64),
    ("BYTE", 46i64),
    ("CACHE", 47i64),
    ("CALL", 48i64),
    ("CASCADE", 49i64),
    ("CASCADED", 50i64),
    ("CASE", 51i64),
    ("CAST", 52i64),
    ("CATALOG_NAME", 53i64),
    ("CHAIN", 54i64),
    ("CHANGE", 55i64),
    ("CHANGED", 56i64),
    ("CHANNEL", 57i64),
    ("CHAR", 60i64),
    ("CHARACTER", 59i64),
    ("CHARSET", 58i64),
    ("CHECK", 62i64),
    ("CHECKSUM", 61i64),
    ("CIPHER", 63i64),
    ("CLASS_ORIGIN", 64i64),
    ("CLIENT", 65i64),
    ("CLOSE", 66i64),
    ("COALESCE", 67i64),
    ("CODE", 68i64),
    ("COLLATE", 69i64),
    ("COLLATION", 70i64),
    ("COLUMN", 72i64),
    ("COLUMN_FORMAT", 74i64),
    ("COLUMN_NAME", 73i64),
    ("COLUMNS", 71i64),
    ("COMMENT", 75i64),
    ("COMMIT", 77i64),
    ("COMMITTED", 76i64),
    ("COMPACT", 78i64),
    ("COMPLETION", 79i64),
    ("COMPRESSED", 80i64),
    ("COMPRESSION", 81i64),
    ("CONCURRENT", 82i64),
    ("CONDITION", 83i64),
    ("CONNECTION", 84i64),
    ("CONSISTENT", 85i64),
    ("CONSTRAINT", 86i64),
    ("CONSTRAINT_CATALOG", 87i64),
    ("CONSTRAINT_NAME", 88i64),
    ("CONSTRAINT_SCHEMA", 89i64),
    ("CONTAINS", 90i64),
    ("CONTEXT", 91i64),
    ("CONTINUE", 92i64),
    ("CONTRIBUTORS", 93i64),
    ("CONVERT", 94i64),
    ("COUNT", 95i64),
    ("CPU", 96i64),
    ("CREATE", 97i64),
    ("CROSS", 98i64),
    ("CUBE", 99i64),
    ("CURDATE", 100i64),
    ("CURRENT", 101i64),
    ("CURRENT_DATE", 102i64),
    ("CURRENT_TIME", 103i64),
    ("CURRENT_TIMESTAMP", 104i64),
    ("CURRENT_USER", 105i64),
    ("CURSOR", 106i64),
    ("CURSOR_NAME", 107i64),
    ("CURTIME", 108i64),
    ("DATA", 112i64),
    ("DATABASE", 109i64),
    ("DATABASES", 110i64),
    ("DATAFILE", 111i64),
    ("DATE", 116i64),
    ("DATE_ADD", 114i64),
    ("DATE_SUB", 115i64),
    ("DATETIME", 113i64),
    ("DAY", 122i64),
    ("DAY_HOUR", 118i64),
    ("DAY_MICROSECOND", 119i64),
    ("DAY_MINUTE", 120i64),
    ("DAY_SECOND", 121i64),
    ("DAYOFMONTH", 117i64),
    ("DEALLOCATE", 123i64),
    ("DEC", 124i64),
    ("DECIMAL", 126i64),
    ("DECIMAL_NUM", 125i64),
    ("DECLARE", 127i64),
    ("DEFAULT", 128i64),
    ("DEFAULT_AUTH", 129i64),
    ("DEFINER", 130i64),
    ("DELAY_KEY_WRITE", 132i64),
    ("DELAYED", 131i64),
    ("DELETE", 133i64),
    ("DES_KEY_FILE", 136i64),
    ("DESC", 134i64),
    ("DESCRIBE", 135i64),
    ("DETERMINISTIC", 137i64),
    ("DIAGNOSTICS", 138i64),
    ("DIRECTORY", 139i64),
    ("DISABLE", 140i64),
    ("DISCARD", 141i64),
    ("DISK", 142i64),
    ("DISTINCT", 143i64),
    ("DISTINCTROW", 144i64),
    ("DIV", 145i64),
    ("DO", 147i64),
    ("DOUBLE", 146i64),
    ("DROP", 148i64),
    ("DUAL", 149i64),
    ("DUMPFILE", 150i64),
    ("DUPLICATE", 151i64),
    ("DYNAMIC", 152i64),
    ("EACH", 153i64),
    ("ELSE", 154i64),
    ("ELSEIF", 155i64),
    ("ENABLE", 156i64),
    ("ENCLOSED", 157i64),
    ("ENCRYPTION", 158i64),
    ("END", 159i64),
    ("END_OF_INPUT", -1i64),
    ("ENDS", 160i64),
    ("ENGINE", 163i64),
    ("ENGINES", 162i64),
    ("ENUM", 164i64),
    ("ERROR", 165i64),
    ("ERRORS", 166i64),
    ("ESCAPE", 168i64),
    ("ESCAPED", 167i64),
    ("EVENT", 170i64),
    ("EVENTS", 169i64),
    ("EVERY", 171i64),
    ("EXCHANGE", 172i64),
    ("EXECUTE", 173i64),
    ("EXISTS", 174i64),
    ("EXIT", 175i64),
    ("EXPANSION", 176i64),
    ("EXPIRE", 177i64),
    ("EXPLAIN", 178i64),
    ("EXPORT", 179i64),
    ("EXTENDED", 180i64),
    ("EXTENT_SIZE", 181i64),
    ("EXTRACT", 182i64),
    ("FALSE", 183i64),
    ("FAST", 184i64),
    ("FAULTS", 185i64),
    ("FETCH", 186i64),
    ("FIELDS", 187i64),
    ("FILE", 188i64),
    ("FILE_BLOCK_SIZE", 189i64),
    ("FILTER", 190i64),
    ("FIRST", 191i64),
    ("FIXED", 192i64),
    ("FLOAT", 195i64),
    ("FLOAT4", 193i64),
    ("FLOAT8", 194i64),
    ("FLUSH", 196i64),
    ("FOLLOWS", 197i64),
    ("FOR", 200i64),
    ("FORCE", 198i64),
    ("FOREIGN", 199i64),
    ("FORMAT", 201i64),
    ("FOUND", 202i64),
    ("FROM", 203i64),
    ("FULL", 204i64),
    ("FULLTEXT", 205i64),
    ("FUNCTION", 206i64),
    ("GENERAL", 208i64),
    ("GENERATED", 209i64),
    ("GEOMCOLLECTION", 852i64),
    ("GEOMETRY", 212i64),
    ("GEOMETRYCOLLECTION", 211i64),
    ("GET", 207i64),
    ("GET_FORMAT", 213i64),
    ("GLOBAL", 214i64),
    ("GRANT", 215i64),
    ("GRANTS", 216i64),
    ("GROUP", 217i64),
    ("GROUP_CONCAT", 218i64),
    ("GROUP_REPLICATION", 210i64),
    ("HANDLER", 219i64),
    ("HASH", 220i64),
    ("HAVING", 221i64),
    ("HELP", 222i64),
    ("HIGH_PRIORITY", 223i64),
    ("HOST", 224i64),
    ("HOSTS", 225i64),
    ("HOUR", 229i64),
    ("HOUR_MICROSECOND", 226i64),
    ("HOUR_MINUTE", 227i64),
    ("HOUR_SECOND", 228i64),
    ("IDENTIFIED", 230i64),
    ("IF", 231i64),
    ("IGNORE", 232i64),
    ("IGNORE_SERVER_IDS", 233i64),
    ("IMPORT", 234i64),
    ("IN", 251i64),
    ("INDEX", 236i64),
    ("INDEXES", 235i64),
    ("INFILE", 237i64),
    ("INITIAL_SIZE", 238i64),
    ("INNER", 239i64),
    ("INNODB", 844i64),
    ("INOUT", 240i64),
    ("INSENSITIVE", 241i64),
    ("INSERT", 242i64),
    ("INSERT_METHOD", 243i64),
    ("INSTALL", 245i64),
    ("INSTANCE", 244i64),
    ("INT", 249i64),
    ("INT1", 795i64),
    ("INT2", 796i64),
    ("INT3", 797i64),
    ("INT4", 798i64),
    ("INT8", 799i64),
    ("INTEGER", 246i64),
    ("INTERVAL", 247i64),
    ("INTO", 248i64),
    ("INVOKER", 250i64),
    ("IO", 255i64),
    ("IO_AFTER_GTIDS", 252i64),
    ("IO_BEFORE_GTIDS", 253i64),
    ("IO_THREAD", 254i64),
    ("IPC", 256i64),
    ("IS", 257i64),
    ("ISOLATION", 258i64),
    ("ISSUER", 259i64),
    ("ITERATE", 260i64),
    ("JOIN", 261i64),
    ("JSON", 262i64),
    ("KEY", 265i64),
    ("KEY_BLOCK_SIZE", 264i64),
    ("KEYS", 263i64),
    ("KILL", 266i64),
    ("LANGUAGE", 267i64),
    ("LAST", 268i64),
    ("LEADING", 269i64),
    ("LEAVE", 271i64),
    ("LEAVES", 270i64),
    ("LEFT", 272i64),
    ("LESS", 273i64),
    ("LEVEL", 274i64),
    ("LIKE", 275i64),
    ("LIMIT", 276i64),
    ("LINEAR", 277i64),
    ("LINES", 278i64),
    ("LINESTRING", 279i64),
    ("LIST", 280i64),
    ("LOAD", 281i64),
    ("LOCAL", 284i64),
    ("LOCALTIME", 282i64),
    ("LOCALTIMESTAMP", 283i64),
    ("LOCATOR", 285i64),
    ("LOCK", 287i64),
    ("LOCKS", 286i64),
    ("LOGFILE", 288i64),
    ("LOGS", 289i64),
    ("LONG", 293i64),
    ("LONG_NUM", 292i64),
    ("LONGBLOB", 290i64),
    ("LONGTEXT", 291i64),
    ("LOOP", 294i64),
    ("LOW_PRIORITY", 295i64),
    ("MASTER", 316i64),
    ("MASTER_AUTO_POSITION", 296i64),
    ("MASTER_BIND", 297i64),
    ("MASTER_CONNECT_RETRY", 298i64),
    ("MASTER_DELAY", 299i64),
    ("MASTER_HEARTBEAT_PERIOD", 319i64),
    ("MASTER_HOST", 300i64),
    ("MASTER_LOG_FILE", 301i64),
    ("MASTER_LOG_POS", 302i64),
    ("MASTER_PASSWORD", 303i64),
    ("MASTER_PORT", 304i64),
    ("MASTER_RETRY_COUNT", 305i64),
    ("MASTER_SERVER_ID", 306i64),
    ("MASTER_SSL", 314i64),
    ("MASTER_SSL_CA", 308i64),
    ("MASTER_SSL_CAPATH", 307i64),
    ("MASTER_SSL_CERT", 309i64),
    ("MASTER_SSL_CIPHER", 310i64),
    ("MASTER_SSL_CRL", 311i64),
    ("MASTER_SSL_CRLPATH", 312i64),
    ("MASTER_SSL_KEY", 313i64),
    ("MASTER_SSL_VERIFY_SERVER_CERT", 315i64),
    ("MASTER_TLS_VERSION", 317i64),
    ("MASTER_USER", 318i64),
    ("MATCH", 320i64),
    ("MAX", 326i64),
    ("MAX_CONNECTIONS_PER_HOUR", 321i64),
    ("MAX_QUERIES_PER_HOUR", 322i64),
    ("MAX_ROWS", 323i64),
    ("MAX_SIZE", 324i64),
    ("MAX_STATEMENT_TIME", 325i64),
    ("MAX_UPDATES_PER_HOUR", 327i64),
    ("MAX_USER_CONNECTIONS", 328i64),
    ("MAXVALUE", 329i64),
    ("MEDIUM", 333i64),
    ("MEDIUMBLOB", 330i64),
    ("MEDIUMINT", 331i64),
    ("MEDIUMTEXT", 332i64),
    ("MEMORY", 334i64),
    ("MERGE", 335i64),
    ("MESSAGE_TEXT", 336i64),
    ("MICROSECOND", 337i64),
    ("MID", 338i64),
    ("MIDDLEINT", 339i64),
    ("MIGRATE", 340i64),
    ("MIN", 345i64),
    ("MIN_ROWS", 344i64),
    ("MINUTE", 343i64),
    ("MINUTE_MICROSECOND", 341i64),
    ("MINUTE_SECOND", 342i64),
    ("MOD", 349i64),
    ("MODE", 346i64),
    ("MODIFIES", 347i64),
    ("MODIFY", 348i64),
    ("MONTH", 350i64),
    ("MULTILINESTRING", 351i64),
    ("MULTIPOINT", 352i64),
    ("MULTIPOLYGON", 353i64),
    ("MUTEX", 354i64),
    ("MYSQL_ERRNO", 355i64),
    ("NAME", 357i64),
    ("NAMES", 356i64),
    ("NATIONAL", 358i64),
    ("NATURAL", 359i64),
    ("NCHAR", 361i64),
    ("NCHAR_STRING", 360i64),
    ("NDB", 362i64),
    ("NDBCLUSTER", 363i64),
    ("NEG", 364i64),
    ("NEVER", 365i64),
    ("NEW", 366i64),
    ("NEXT", 367i64),
    ("NO", 373i64),
    ("NO_WAIT", 374i64),
    ("NO_WRITE_TO_BINLOG", 375i64),
    ("NODEGROUP", 368i64),
    ("NONBLOCKING", 370i64),
    ("NONE", 369i64),
    ("NOT", 371i64),
    ("NOW", 372i64),
    ("NULL", 376i64),
    ("NUMBER", 377i64),
    ("NUMERIC", 378i64),
    ("NVARCHAR", 379i64),
    ("OFFLINE", 380i64),
    ("OFFSET", 381i64),
    ("OLD_PASSWORD", 382i64),
    ("ON", 383i64),
    ("ONE", 384i64),
    ("ONLINE", 385i64),
    ("ONLY", 386i64),
    ("OPEN", 387i64),
    ("OPTIMIZE", 388i64),
    ("OPTIMIZER_COSTS", 389i64),
    ("OPTION", 391i64),
    ("OPTIONALLY", 392i64),
    ("OPTIONS", 390i64),
    ("OR", 394i64),
    ("ORDER", 393i64),
    ("OUT", 397i64),
    ("OUTER", 395i64),
    ("OUTFILE", 396i64),
    ("OWNER", 398i64),
    ("PACK_KEYS", 399i64),
    ("PAGE", 400i64),
    ("PARSER", 401i64),
    ("PARTIAL", 402i64),
    ("PARTITION", 405i64),
    ("PARTITIONING", 403i64),
    ("PARTITIONS", 404i64),
    ("PASSWORD", 406i64),
    ("PHASE", 407i64),
    ("PLUGIN", 410i64),
    ("PLUGIN_DIR", 409i64),
    ("PLUGINS", 408i64),
    ("POINT", 411i64),
    ("POLYGON", 412i64),
    ("PORT", 413i64),
    ("POSITION", 414i64),
    ("PRECEDES", 415i64),
    ("PRECISION", 416i64),
    ("PREPARE", 417i64),
    ("PRESERVE", 418i64),
    ("PREV", 419i64),
    ("PRIMARY", 420i64),
    ("PRIVILEGES", 421i64),
    ("PROCEDURE", 422i64),
    ("PROCESS", 423i64),
    ("PROCESSLIST", 424i64),
    ("PROFILE", 425i64),
    ("PROFILES", 426i64),
    ("PROXY", 427i64),
    ("PURGE", 428i64),
    ("QUARTER", 429i64),
    ("QUERY", 430i64),
    ("QUICK", 431i64),
    ("RANGE", 432i64),
    ("READ", 435i64),
    ("READ_ONLY", 434i64),
    ("READ_WRITE", 436i64),
    ("READS", 433i64),
    ("REAL", 437i64),
    ("REBUILD", 438i64),
    ("RECOVER", 439i64),
    ("REDO_BUFFER_SIZE", 441i64),
    ("REDOFILE", 440i64),
    ("REDUNDANT", 442i64),
    ("REFERENCES", 443i64),
    ("REGEXP", 444i64),
    ("RELAY", 445i64),
    ("RELAY_LOG_FILE", 447i64),
    ("RELAY_LOG_POS", 448i64),
    ("RELAY_THREAD", 449i64),
    ("RELAYLOG", 446i64),
    ("RELEASE", 450i64),
    ("RELOAD", 451i64),
    ("REMOVE", 452i64),
    ("RENAME", 453i64),
    ("REORGANIZE", 454i64),
    ("REPAIR", 455i64),
    ("REPEAT", 457i64),
    ("REPEATABLE", 456i64),
    ("REPLACE", 458i64),
    ("REPLICATE_DO_DB", 460i64),
    ("REPLICATE_DO_TABLE", 462i64),
    ("REPLICATE_IGNORE_DB", 461i64),
    ("REPLICATE_IGNORE_TABLE", 463i64),
    ("REPLICATE_REWRITE_DB", 466i64),
    ("REPLICATE_WILD_DO_TABLE", 464i64),
    ("REPLICATE_WILD_IGNORE_TABLE", 465i64),
    ("REPLICATION", 459i64),
    ("REQUIRE", 467i64),
    ("RESET", 468i64),
    ("RESIGNAL", 469i64),
    ("RESTORE", 470i64),
    ("RESTRICT", 471i64),
    ("RESUME", 472i64),
    ("RETURN", 475i64),
    ("RETURNED_SQLSTATE", 473i64),
    ("RETURNS", 474i64),
    ("REVERSE", 476i64),
    ("REVOKE", 477i64),
    ("RIGHT", 478i64),
    ("RLIKE", 479i64),
    ("ROLLBACK", 480i64),
    ("ROLLUP", 481i64),
    ("ROTATE", 482i64),
    ("ROUTINE", 483i64),
    ("ROW", 487i64),
    ("ROW_COUNT", 485i64),
    ("ROW_FORMAT", 486i64),
    ("ROWS", 484i64),
    ("RTREE", 488i64),
    ("SAVEPOINT", 489i64),
    ("SCHEDULE", 490i64),
    ("SCHEMA", 491i64),
    ("SCHEMA_NAME", 492i64),
    ("SCHEMAS", 493i64),
    ("SECOND", 495i64),
    ("SECOND_MICROSECOND", 494i64),
    ("SECURITY", 496i64),
    ("SELECT", 497i64),
    ("SENSITIVE", 498i64),
    ("SEPARATOR", 499i64),
    ("SERIAL", 501i64),
    ("SERIALIZABLE", 500i64),
    ("SERVER", 503i64),
    ("SERVER_OPTIONS", 504i64),
    ("SESSION", 502i64),
    ("SESSION_USER", 505i64),
    ("SET", 506i64),
    ("SET_VAR", 507i64),
    ("SHARE", 508i64),
    ("SHOW", 509i64),
    ("SHUTDOWN", 510i64),
    ("SIGNAL", 511i64),
    ("SIGNED", 512i64),
    ("SIMPLE", 513i64),
    ("SLAVE", 514i64),
    ("SLOW", 515i64),
    ("SMALLINT", 516i64),
    ("SNAPSHOT", 517i64),
    ("SOCKET", 519i64),
    ("SOME", 518i64),
    ("SONAME", 520i64),
    ("SOUNDS", 521i64),
    ("SOURCE", 522i64),
    ("SPATIAL", 523i64),
    ("SPECIFIC", 524i64),
    ("SQL", 537i64),
    ("SQL_AFTER_GTIDS", 528i64),
    ("SQL_AFTER_MTS_GAPS", 529i64),
    ("SQL_BEFORE_GTIDS", 530i64),
    ("SQL_BIG_RESULT", 531i64),
    ("SQL_BUFFER_RESULT", 532i64),
    ("SQL_CACHE", 533i64),
    ("SQL_CALC_FOUND_ROWS", 534i64),
    ("SQL_NO_CACHE", 535i64),
    ("SQL_SMALL_RESULT", 536i64),
    ("SQL_THREAD", 538i64),
    ("SQL_TSI_DAY", 802i64),
    ("SQL_TSI_HOUR", 803i64),
    ("SQL_TSI_MICROSECOND", 804i64),
    ("SQL_TSI_MINUTE", 805i64),
    ("SQL_TSI_MONTH", 806i64),
    ("SQL_TSI_QUARTER", 807i64),
    ("SQL_TSI_SECOND", 808i64),
    ("SQL_TSI_WEEK", 809i64),
    ("SQL_TSI_YEAR", 810i64),
    ("SQLEXCEPTION", 525i64),
    ("SQLSTATE", 526i64),
    ("SQLWARNING", 527i64),
    ("SSL", 539i64),
    ("STACKED", 540i64),
    ("START", 543i64),
    ("STARTING", 541i64),
    ("STARTS", 542i64),
    ("STATS_AUTO_RECALC", 544i64),
    ("STATS_PERSISTENT", 545i64),
    ("STATS_SAMPLE_PAGES", 546i64),
    ("STATUS", 547i64),
    ("STD", 551i64),
    ("STDDEV", 549i64),
    ("STDDEV_POP", 550i64),
    ("STDDEV_SAMP", 548i64),
    ("STOP", 552i64),
    ("STORAGE", 553i64),
    ("STORED", 554i64),
    ("STRAIGHT_JOIN", 555i64),
    ("STRING", 556i64),
    ("SUBCLASS_ORIGIN", 557i64),
    ("SUBDATE", 558i64),
    ("SUBJECT", 559i64),
    ("SUBPARTITION", 561i64),
    ("SUBPARTITIONS", 560i64),
    ("SUBSTR", 562i64),
    ("SUBSTRING", 563i64),
    ("SUM", 564i64),
    ("SUPER", 565i64),
    ("SUSPEND", 566i64),
    ("SWAPS", 567i64),
    ("SWITCHES", 568i64),
    ("SYSDATE", 569i64),
    ("SYSTEM_USER", 570i64),
    ("TABLE", 574i64),
    ("TABLE_CHECKSUM", 575i64),
    ("TABLE_NAME", 576i64),
    ("TABLE_REF_PRIORITY", 573i64),
    ("TABLES", 571i64),
    ("TABLESPACE", 572i64),
    ("TEMPORARY", 577i64),
    ("TEMPTABLE", 578i64),
    ("TERMINATED", 579i64),
    ("TEXT", 580i64),
    ("THAN", 581i64),
    ("THEN", 582i64),
    ("TIME", 586i64),
    ("TIMESTAMP", 583i64),
    ("TIMESTAMP_ADD", 584i64),
    ("TIMESTAMP_DIFF", 585i64),
    ("TINYBLOB", 587i64),
    ("TINYINT", 588i64),
    ("TINYTEXT", 589i64),
    ("TO", 590i64),
    ("TRAILING", 591i64),
    ("TRANSACTION", 592i64),
    ("TRIGGER", 594i64),
    ("TRIGGERS", 593i64),
    ("TRIM", 595i64),
    ("TRUE", 596i64),
    ("TRUNCATE", 597i64),
    ("TYPE", 599i64),
    ("TYPES", 598i64),
    ("UDF_RETURNS", 600i64),
    ("UNCOMMITTED", 601i64),
    ("UNDEFINED", 602i64),
    ("UNDO", 605i64),
    ("UNDO_BUFFER_SIZE", 604i64),
    ("UNDOFILE", 603i64),
    ("UNICODE", 606i64),
    ("UNINSTALL", 607i64),
    ("UNION", 608i64),
    ("UNIQUE", 609i64),
    ("UNKNOWN", 610i64),
    ("UNLOCK", 611i64),
    ("UNSIGNED", 612i64),
    ("UNTIL", 613i64),
    ("UPDATE", 614i64),
    ("UPGRADE", 615i64),
    ("USAGE", 616i64),
    ("USE", 620i64),
    ("USE_FRM", 619i64),
    ("USER", 618i64),
    ("USER_RESOURCES", 617i64),
    ("USING", 621i64),
    ("UTC_DATE", 622i64),
    ("UTC_TIME", 624i64),
    ("UTC_TIMESTAMP", 623i64),
    ("VALIDATION", 625i64),
    ("VALUE", 627i64),
    ("VALUES", 626i64),
    ("VAR_POP", 634i64),
    ("VAR_SAMP", 635i64),
    ("VARBINARY", 628i64),
    ("VARCHAR", 629i64),
    ("VARCHARACTER", 630i64),
    ("VARIABLES", 631i64),
    ("VARIANCE", 632i64),
    ("VARYING", 633i64),
    ("VIEW", 636i64),
    ("VIRTUAL", 637i64),
    ("WAIT", 638i64),
    ("WARNINGS", 639i64),
    ("WEEK", 640i64),
    ("WEIGHT_STRING", 641i64),
    ("WHEN", 642i64),
    ("WHERE", 643i64),
    ("WHILE", 644i64),
    ("WITH", 645i64),
    ("WITHOUT", 646i64),
    ("WORK", 647i64),
    ("WRAPPER", 648i64),
    ("WRITE", 649i64),
    ("X509", 650i64),
    ("XA", 651i64),
    ("XID", 652i64),
    ("XML", 653i64),
    ("XOR", 654i64),
    ("YEAR", 656i64),
    ("YEAR_MONTH", 655i64),
    ("ZEROFILL", 657i64),
    ("ACTIVE", 724i64),
    ("ADMIN", 660i64),
    ("ARRAY", 731i64),
    ("ASSIGN_GTIDS_TO_ANONYMOUS_TRANSACTIONS", 842i64),
    ("BUCKETS", 675i64),
    ("CLONE", 677i64),
    ("COMPONENT", 664i64),
    ("CUME_DIST", 678i64),
    ("DEFINITION", 715i64),
    ("DENSE_RANK", 679i64),
    ("DESCRIPTION", 716i64),
    ("EMPTY", 700i64),
    ("ENFORCED", 730i64),
    ("ENGINE_ATTRIBUTE", 848i64),
    ("EXCEPT", 663i64),
    ("EXCLUDE", 680i64),
    ("FAILED_LOGIN_ATTEMPTS", 741i64),
    ("FIRST_VALUE", 681i64),
    ("FOLLOWING", 682i64),
    ("GET_MASTER_PUBLIC_KEY_SYM", 713i64),
    ("GET_SOURCE_PUBLIC_KEY", 840i64),
    ("GROUPING", 672i64),
    ("GROUPS", 683i64),
    ("GTID_ONLY", 841i64),
    ("HISTOGRAM", 674i64),
    ("HISTORY", 705i64),
    ("INACTIVE", 725i64),
    ("INTERSECT", 811i64),
    ("INVISIBLE", 661i64),
    ("JSON_ARRAYAGG", 667i64),
    ("JSON_OBJECTAGG", 666i64),
    ("JSON_TABLE", 701i64),
    ("JSON_VALUE", 850i64),
    ("KEYRING", 847i64),
    ("LAG", 684i64),
    ("LAST_VALUE", 685i64),
    ("LATERAL", 726i64),
    ("LEAD", 686i64),
    ("LOCKED", 670i64),
    ("MASTER_COMPRESSION_ALGORITHM", 735i64),
    ("MASTER_PUBLIC_KEY_PATH", 712i64),
    ("MASTER_TLS_CIPHERSUITES", 738i64),
    ("MASTER_ZSTD_COMPRESSION_LEVEL", 736i64),
    ("MEMBER", 733i64),
    ("NESTED", 702i64),
    ("NETWORK_NAMESPACE", 729i64),
    ("NOWAIT", 671i64),
    ("NTH_VALUE", 687i64),
    ("NTILE", 688i64),
    ("NULLS", 689i64),
    ("OF", 668i64),
    ("OFF", 744i64),
    ("OJ", 732i64),
    ("OLD", 728i64),
    ("OPTIONAL", 719i64),
    ("ORDINALITY", 703i64),
    ("ORGANIZATION", 717i64),
    ("OTHERS", 690i64),
    ("OVER", 691i64),
    ("PASSWORD_LOCK_TIME", 740i64),
    ("PATH", 704i64),
    ("PERCENT_RANK", 692i64),
    ("PERSIST", 658i64),
    ("PERSIST_ONLY", 673i64),
    ("PRECEDING", 693i64),
    ("PRIVILEGE_CHECKS_USER", 737i64),
    ("RANDOM", 734i64),
    ("RANK", 694i64),
    ("RECURSIVE", 665i64),
    ("REDO_LOG", 846i64),
    ("REFERENCE", 718i64),
    ("REMOTE", 676i64),
    ("REQUIRE_ROW_FORMAT", 739i64),
    ("REQUIRE_TABLE_PRIMARY_KEY_CHECK", 742i64),
    ("RESOURCE", 709i64),
    ("RESPECT", 695i64),
    ("RESTART", 714i64),
    ("RETAIN", 727i64),
    ("RETURNING", 851i64),
    ("REUSE", 706i64),
    ("ROLE", 659i64),
    ("ROW_NUMBER", 696i64),
    ("SECONDARY", 720i64),
    ("SECONDARY_ENGINE", 721i64),
    ("SECONDARY_ENGINE_ATTRIBUTE", 849i64),
    ("SECONDARY_LOAD", 722i64),
    ("SECONDARY_UNLOAD", 723i64),
    ("SKIP", 669i64),
    ("SOURCE_AUTO_POSITION", 813i64),
    ("SOURCE_BIND", 814i64),
    ("SOURCE_COMPRESSION_ALGORITHM", 815i64),
    ("SOURCE_CONNECT_RETRY", 816i64),
    ("SOURCE_CONNECTION_AUTO_FAILOVER", 817i64),
    ("SOURCE_DELAY", 818i64),
    ("SOURCE_HEARTBEAT_PERIOD", 819i64),
    ("SOURCE_HOST", 820i64),
    ("SOURCE_LOG_FILE", 821i64),
    ("SOURCE_LOG_POS", 822i64),
    ("SOURCE_PASSWORD", 823i64),
    ("SOURCE_PORT", 824i64),
    ("SOURCE_PUBLIC_KEY_PATH", 825i64),
    ("SOURCE_RETRY_COUNT", 826i64),
    ("SOURCE_SSL", 827i64),
    ("SOURCE_SSL_CA", 828i64),
    ("SOURCE_SSL_CAPATH", 829i64),
    ("SOURCE_SSL_CERT", 830i64),
    ("SOURCE_SSL_CIPHER", 831i64),
    ("SOURCE_SSL_CRL", 832i64),
    ("SOURCE_SSL_CRLPATH", 833i64),
    ("SOURCE_SSL_KEY", 834i64),
    ("SOURCE_SSL_VERIFY_SERVER_CERT", 835i64),
    ("SOURCE_TLS_CIPHERSUITES", 836i64),
    ("SOURCE_TLS_VERSION", 837i64),
    ("SOURCE_USER", 838i64),
    ("SOURCE_ZSTD_COMPRESSION_LEVEL", 839i64),
    ("SRID", 707i64),
    ("STREAM", 743i64),
    ("SYSTEM", 710i64),
    ("THREAD_PRIORITY", 708i64),
    ("TIES", 697i64),
    ("TLS", 845i64),
    ("UNBOUNDED", 698i64),
    ("VCPU", 711i64),
    ("VISIBLE", 662i64),
    ("WINDOW", 699i64),
    ("ZONE", 843i64),
];

pub const VERSION_RULES: &[(i64, i64)] = &[
    (2i64, 50707i64),
    (12i64, 50707i64),
    (13i64, -80000i64),
    (22i64, -50700i64),
    (57i64, 50706i64),
    (81i64, 50707i64),
    (93i64, -50700i64),
    (101i64, 50604i64),
    (129i64, 50604i64),
    (136i64, -80003i64),
    (158i64, 50711i64),
    (177i64, 50606i64),
    (179i64, 50606i64),
    (189i64, 50707i64),
    (190i64, 50700i64),
    (197i64, 50700i64),
    (209i64, 50707i64),
    (207i64, 50604i64),
    (210i64, 50707i64),
    (844i64, 50711i64),
    (244i64, 50713i64),
    (262i64, 50708i64),
    (296i64, 50605i64),
    (297i64, 50602i64),
    (305i64, 50601i64),
    (311i64, 50603i64),
    (312i64, 50603i64),
    (317i64, 50713i64),
    (365i64, 50704i64),
    (377i64, 50606i64),
    (382i64, -50706i64),
    (386i64, 50605i64),
    (389i64, 50706i64),
    (409i64, 50604i64),
    (415i64, 50700i64),
    (440i64, -80000i64),
    (460i64, 50700i64),
    (462i64, 50700i64),
    (461i64, 50700i64),
    (463i64, 50700i64),
    (466i64, 50700i64),
    (464i64, 50700i64),
    (465i64, 50700i64),
    (482i64, 50713i64),
    (529i64, 50606i64),
    (533i64, -80000i64),
    (540i64, 50700i64),
    (554i64, 50707i64),
    (573i64, -80000i64),
    (625i64, 50706i64),
    (637i64, 50707i64),
    (652i64, 50704i64),
    (724i64, 80014i64),
    (660i64, 80000i64),
    (731i64, 80017i64),
    (842i64, 80000i64),
    (812i64, 80021i64),
    (675i64, 80000i64),
    (677i64, 80000i64),
    (664i64, 80000i64),
    (678i64, 80000i64),
    (715i64, 80011i64),
    (679i64, 80000i64),
    (716i64, 80011i64),
    (700i64, 80000i64),
    (730i64, 80017i64),
    (848i64, 80021i64),
    (663i64, 80000i64),
    (680i64, 80000i64),
    (741i64, 80019i64),
    (681i64, 80000i64),
    (682i64, 80000i64),
    (852i64, 80000i64),
    (713i64, 80000i64),
    (840i64, 80000i64),
    (672i64, 80000i64),
    (683i64, 80000i64),
    (841i64, 80000i64),
    (674i64, 80000i64),
    (705i64, 80000i64),
    (725i64, 80014i64),
    (811i64, 80031i64),
    (661i64, 80000i64),
    (667i64, 80000i64),
    (666i64, 80000i64),
    (701i64, 80000i64),
    (850i64, 80021i64),
    (847i64, 80024i64),
    (684i64, 80000i64),
    (685i64, 80000i64),
    (726i64, 80014i64),
    (686i64, 80000i64),
    (670i64, 80000i64),
    (735i64, 80018i64),
    (712i64, 80000i64),
    (738i64, 80018i64),
    (736i64, 80018i64),
    (733i64, 80017i64),
    (702i64, 80000i64),
    (729i64, 80017i64),
    (671i64, 80000i64),
    (687i64, 80000i64),
    (688i64, 80000i64),
    (689i64, 80000i64),
    (668i64, 80000i64),
    (744i64, 80019i64),
    (732i64, 80017i64),
    (728i64, 80014i64),
    (719i64, 80013i64),
    (703i64, 80000i64),
    (717i64, 80011i64),
    (690i64, 80000i64),
    (691i64, 80000i64),
    (740i64, 80019i64),
    (704i64, 80000i64),
    (692i64, 80000i64),
    (673i64, 80000i64),
    (658i64, 80000i64),
    (693i64, 80000i64),
    (737i64, 80018i64),
    (734i64, 80018i64),
    (694i64, 80000i64),
    (665i64, 80000i64),
    (846i64, 80021i64),
    (718i64, 80011i64),
    (739i64, 80019i64),
    (742i64, 80019i64),
    (709i64, 80000i64),
    (695i64, 80000i64),
    (714i64, 80011i64),
    (727i64, 80014i64),
    (706i64, 80000i64),
    (851i64, 80021i64),
    (659i64, 80000i64),
    (696i64, 80000i64),
    (849i64, 80021i64),
    (721i64, 80013i64),
    (722i64, 80013i64),
    (720i64, 80013i64),
    (723i64, 80013i64),
    (669i64, 80000i64),
    (813i64, 80000i64),
    (814i64, 80000i64),
    (815i64, 80000i64),
    (816i64, 80000i64),
    (817i64, 80000i64),
    (818i64, 80000i64),
    (819i64, 80000i64),
    (820i64, 80000i64),
    (821i64, 80000i64),
    (822i64, 80000i64),
    (823i64, 80000i64),
    (824i64, 80000i64),
    (825i64, 80000i64),
    (826i64, 80000i64),
    (828i64, 80000i64),
    (829i64, 80000i64),
    (830i64, 80000i64),
    (831i64, 80000i64),
    (832i64, 80000i64),
    (833i64, 80000i64),
    (834i64, 80000i64),
    (827i64, 80000i64),
    (835i64, 80000i64),
    (836i64, 80000i64),
    (837i64, 80000i64),
    (838i64, 80000i64),
    (839i64, 80000i64),
    (707i64, 80000i64),
    (743i64, 80019i64),
    (710i64, 80000i64),
    (708i64, 80000i64),
    (697i64, 80000i64),
    (845i64, 80016i64),
    (698i64, 80000i64),
    (711i64, 80000i64),
    (662i64, 80000i64),
    (699i64, 80000i64),
    (843i64, 80022i64),
];

pub const FUNCTION_TOKENS: &[i64] = &[
    5i64, 35i64, 36i64, 38i64, 52i64, 95i64, 100i64, 102i64, 103i64, 108i64, 114i64, 115i64,
    182i64, 218i64, 326i64, 338i64, 345i64, 372i64, 414i64, 505i64, 551i64, 550i64, 548i64, 549i64,
    558i64, 562i64, 563i64, 564i64, 569i64, 570i64, 595i64, 634i64, 635i64, 632i64,
];

pub const TOKEN_SYNONYMS: &[(i64, i64)] = &[
    (59i64, 60i64),
    (102i64, 100i64),
    (103i64, 108i64),
    (104i64, 372i64),
    (117i64, 122i64),
    (124i64, 126i64),
    (144i64, 143i64),
    (187i64, 71i64),
    (193i64, 195i64),
    (194i64, 146i64),
    (852i64, 211i64),
    (795i64, 588i64),
    (796i64, 516i64),
    (797i64, 331i64),
    (798i64, 249i64),
    (799i64, 31i64),
    (246i64, 249i64),
    (254i64, 449i64),
    (282i64, 372i64),
    (283i64, 372i64),
    (338i64, 563i64),
    (339i64, 331i64),
    (362i64, 363i64),
    (479i64, 444i64),
    (491i64, 109i64),
    (493i64, 110i64),
    (505i64, 618i64),
    (518i64, 16i64),
    (802i64, 122i64),
    (803i64, 229i64),
    (804i64, 337i64),
    (805i64, 343i64),
    (806i64, 350i64),
    (807i64, 429i64),
    (808i64, 495i64),
    (809i64, 640i64),
    (810i64, 656i64),
    (550i64, 551i64),
    (549i64, 551i64),
    (562i64, 563i64),
    (570i64, 618i64),
    (634i64, 632i64),
    (630i64, 629i64),
];

pub const UNDERSCORE_CHARSET_NAMES: &[&str] = &[
    "_armscii8",
    "_ascii",
    "_big5",
    "_binary",
    "_cp1250",
    "_cp1251",
    "_cp1256",
    "_cp1257",
    "_cp850",
    "_cp852",
    "_cp866",
    "_cp932",
    "_dec8",
    "_eucjpms",
    "_euckr",
    "_gb18030",
    "_gb2312",
    "_gbk",
    "_geostd8",
    "_greek",
    "_hebrew",
    "_hp8",
    "_keybcs2",
    "_koi8r",
    "_koi8u",
    "_latin1",
    "_latin2",
    "_latin5",
    "_latin7",
    "_macce",
    "_macroman",
    "_sjis",
    "_swe7",
    "_tis620",
    "_ucs2",
    "_ujis",
    "_utf16",
    "_utf16le",
    "_utf32",
    "_utf8",
    "_utf8mb3",
    "_utf8mb4",
];

pub fn token_id(name: &str) -> Option<i64> {
    SCALAR_INT_CONSTANTS
        .iter()
        .find_map(|(constant_name, id)| (*constant_name == name).then_some(*id))
}

pub fn token_name(id: i64) -> Option<&'static str> {
    SCALAR_INT_CONSTANTS
        .iter()
        .rev()
        .find_map(|(constant_name, token_id)| (*token_id == id).then_some(*constant_name))
}

pub fn keyword_token(keyword: &str) -> Option<i64> {
    KEYWORD_TOKENS
        .iter()
        .find_map(|(candidate, id)| (*candidate == keyword).then_some(*id))
}

pub fn version_rule(token_id: i64) -> Option<i64> {
    VERSION_RULES
        .iter()
        .find_map(|(candidate, version)| (*candidate == token_id).then_some(*version))
}

pub fn is_function_token(token_id: i64) -> bool {
    FUNCTION_TOKENS.contains(&token_id)
}

pub fn token_synonym(token_id: i64) -> Option<i64> {
    TOKEN_SYNONYMS
        .iter()
        .find_map(|(candidate, synonym)| (*candidate == token_id).then_some(*synonym))
}

pub fn is_underscore_charset(name: &str) -> bool {
    UNDERSCORE_CHARSET_NAMES.contains(&name)
}

pub fn register_lexer_constants(mut builder: ClassBuilder) -> ClassBuilder {
    builder = builder
        .constant("SQL_MODE_HIGH_NOT_PRECEDENCE", 1i64, &[])
        .unwrap();
    builder = builder
        .constant("SQL_MODE_PIPES_AS_CONCAT", 2i64, &[])
        .unwrap();
    builder = builder
        .constant("SQL_MODE_IGNORE_SPACE", 4i64, &[])
        .unwrap();
    builder = builder
        .constant("SQL_MODE_NO_BACKSLASH_ESCAPES", 8i64, &[])
        .unwrap();
    builder = builder
        .constant("WHITESPACE_MASK", " \t\n\r\x0c", &[])
        .unwrap();
    builder = builder.constant("DIGIT_MASK", "0123456789", &[]).unwrap();
    builder = builder
        .constant("HEX_DIGIT_MASK", "0123456789abcdefABCDEF", &[])
        .unwrap();
    builder = builder.constant("ACCESSIBLE_SYMBOL", 1i64, &[]).unwrap();
    builder = builder.constant("ACCOUNT_SYMBOL", 2i64, &[]).unwrap();
    builder = builder.constant("ACTION_SYMBOL", 3i64, &[]).unwrap();
    builder = builder.constant("ADD_SYMBOL", 4i64, &[]).unwrap();
    builder = builder.constant("ADDDATE_SYMBOL", 5i64, &[]).unwrap();
    builder = builder.constant("AFTER_SYMBOL", 6i64, &[]).unwrap();
    builder = builder.constant("AGAINST_SYMBOL", 7i64, &[]).unwrap();
    builder = builder.constant("AGGREGATE_SYMBOL", 8i64, &[]).unwrap();
    builder = builder.constant("ALGORITHM_SYMBOL", 9i64, &[]).unwrap();
    builder = builder.constant("ALL_SYMBOL", 10i64, &[]).unwrap();
    builder = builder.constant("ALTER_SYMBOL", 11i64, &[]).unwrap();
    builder = builder.constant("ALWAYS_SYMBOL", 12i64, &[]).unwrap();
    builder = builder.constant("ANALYSE_SYMBOL", 13i64, &[]).unwrap();
    builder = builder.constant("ANALYZE_SYMBOL", 14i64, &[]).unwrap();
    builder = builder.constant("AND_SYMBOL", 15i64, &[]).unwrap();
    builder = builder.constant("ANY_SYMBOL", 16i64, &[]).unwrap();
    builder = builder.constant("AS_SYMBOL", 17i64, &[]).unwrap();
    builder = builder.constant("ASC_SYMBOL", 18i64, &[]).unwrap();
    builder = builder.constant("ASCII_SYMBOL", 19i64, &[]).unwrap();
    builder = builder.constant("ASENSITIVE_SYMBOL", 20i64, &[]).unwrap();
    builder = builder.constant("AT_SYMBOL", 21i64, &[]).unwrap();
    builder = builder.constant("AUTHORS_SYMBOL", 22i64, &[]).unwrap();
    builder = builder
        .constant("AUTOEXTEND_SIZE_SYMBOL", 23i64, &[])
        .unwrap();
    builder = builder
        .constant("AUTO_INCREMENT_SYMBOL", 24i64, &[])
        .unwrap();
    builder = builder
        .constant("AVG_ROW_LENGTH_SYMBOL", 25i64, &[])
        .unwrap();
    builder = builder.constant("AVG_SYMBOL", 26i64, &[]).unwrap();
    builder = builder.constant("BACKUP_SYMBOL", 27i64, &[]).unwrap();
    builder = builder.constant("BEFORE_SYMBOL", 28i64, &[]).unwrap();
    builder = builder.constant("BEGIN_SYMBOL", 29i64, &[]).unwrap();
    builder = builder.constant("BETWEEN_SYMBOL", 30i64, &[]).unwrap();
    builder = builder.constant("BIGINT_SYMBOL", 31i64, &[]).unwrap();
    builder = builder.constant("BINARY_SYMBOL", 32i64, &[]).unwrap();
    builder = builder.constant("BINLOG_SYMBOL", 33i64, &[]).unwrap();
    builder = builder.constant("BIN_NUM_SYMBOL", 34i64, &[]).unwrap();
    builder = builder.constant("BIT_AND_SYMBOL", 35i64, &[]).unwrap();
    builder = builder.constant("BIT_OR_SYMBOL", 36i64, &[]).unwrap();
    builder = builder.constant("BIT_SYMBOL", 37i64, &[]).unwrap();
    builder = builder.constant("BIT_XOR_SYMBOL", 38i64, &[]).unwrap();
    builder = builder.constant("BLOB_SYMBOL", 39i64, &[]).unwrap();
    builder = builder.constant("BLOCK_SYMBOL", 40i64, &[]).unwrap();
    builder = builder.constant("BOOLEAN_SYMBOL", 41i64, &[]).unwrap();
    builder = builder.constant("BOOL_SYMBOL", 42i64, &[]).unwrap();
    builder = builder.constant("BOTH_SYMBOL", 43i64, &[]).unwrap();
    builder = builder.constant("BTREE_SYMBOL", 44i64, &[]).unwrap();
    builder = builder.constant("BY_SYMBOL", 45i64, &[]).unwrap();
    builder = builder.constant("BYTE_SYMBOL", 46i64, &[]).unwrap();
    builder = builder.constant("CACHE_SYMBOL", 47i64, &[]).unwrap();
    builder = builder.constant("CALL_SYMBOL", 48i64, &[]).unwrap();
    builder = builder.constant("CASCADE_SYMBOL", 49i64, &[]).unwrap();
    builder = builder.constant("CASCADED_SYMBOL", 50i64, &[]).unwrap();
    builder = builder.constant("CASE_SYMBOL", 51i64, &[]).unwrap();
    builder = builder.constant("CAST_SYMBOL", 52i64, &[]).unwrap();
    builder = builder.constant("CATALOG_NAME_SYMBOL", 53i64, &[]).unwrap();
    builder = builder.constant("CHAIN_SYMBOL", 54i64, &[]).unwrap();
    builder = builder.constant("CHANGE_SYMBOL", 55i64, &[]).unwrap();
    builder = builder.constant("CHANGED_SYMBOL", 56i64, &[]).unwrap();
    builder = builder.constant("CHANNEL_SYMBOL", 57i64, &[]).unwrap();
    builder = builder.constant("CHARSET_SYMBOL", 58i64, &[]).unwrap();
    builder = builder.constant("CHARACTER_SYMBOL", 59i64, &[]).unwrap();
    builder = builder.constant("CHAR_SYMBOL", 60i64, &[]).unwrap();
    builder = builder.constant("CHECKSUM_SYMBOL", 61i64, &[]).unwrap();
    builder = builder.constant("CHECK_SYMBOL", 62i64, &[]).unwrap();
    builder = builder.constant("CIPHER_SYMBOL", 63i64, &[]).unwrap();
    builder = builder.constant("CLASS_ORIGIN_SYMBOL", 64i64, &[]).unwrap();
    builder = builder.constant("CLIENT_SYMBOL", 65i64, &[]).unwrap();
    builder = builder.constant("CLOSE_SYMBOL", 66i64, &[]).unwrap();
    builder = builder.constant("COALESCE_SYMBOL", 67i64, &[]).unwrap();
    builder = builder.constant("CODE_SYMBOL", 68i64, &[]).unwrap();
    builder = builder.constant("COLLATE_SYMBOL", 69i64, &[]).unwrap();
    builder = builder.constant("COLLATION_SYMBOL", 70i64, &[]).unwrap();
    builder = builder.constant("COLUMNS_SYMBOL", 71i64, &[]).unwrap();
    builder = builder.constant("COLUMN_SYMBOL", 72i64, &[]).unwrap();
    builder = builder.constant("COLUMN_NAME_SYMBOL", 73i64, &[]).unwrap();
    builder = builder
        .constant("COLUMN_FORMAT_SYMBOL", 74i64, &[])
        .unwrap();
    builder = builder.constant("COMMENT_SYMBOL", 75i64, &[]).unwrap();
    builder = builder.constant("COMMITTED_SYMBOL", 76i64, &[]).unwrap();
    builder = builder.constant("COMMIT_SYMBOL", 77i64, &[]).unwrap();
    builder = builder.constant("COMPACT_SYMBOL", 78i64, &[]).unwrap();
    builder = builder.constant("COMPLETION_SYMBOL", 79i64, &[]).unwrap();
    builder = builder.constant("COMPRESSED_SYMBOL", 80i64, &[]).unwrap();
    builder = builder.constant("COMPRESSION_SYMBOL", 81i64, &[]).unwrap();
    builder = builder.constant("CONCURRENT_SYMBOL", 82i64, &[]).unwrap();
    builder = builder.constant("CONDITION_SYMBOL", 83i64, &[]).unwrap();
    builder = builder.constant("CONNECTION_SYMBOL", 84i64, &[]).unwrap();
    builder = builder.constant("CONSISTENT_SYMBOL", 85i64, &[]).unwrap();
    builder = builder.constant("CONSTRAINT_SYMBOL", 86i64, &[]).unwrap();
    builder = builder
        .constant("CONSTRAINT_CATALOG_SYMBOL", 87i64, &[])
        .unwrap();
    builder = builder
        .constant("CONSTRAINT_NAME_SYMBOL", 88i64, &[])
        .unwrap();
    builder = builder
        .constant("CONSTRAINT_SCHEMA_SYMBOL", 89i64, &[])
        .unwrap();
    builder = builder.constant("CONTAINS_SYMBOL", 90i64, &[]).unwrap();
    builder = builder.constant("CONTEXT_SYMBOL", 91i64, &[]).unwrap();
    builder = builder.constant("CONTINUE_SYMBOL", 92i64, &[]).unwrap();
    builder = builder.constant("CONTRIBUTORS_SYMBOL", 93i64, &[]).unwrap();
    builder = builder.constant("CONVERT_SYMBOL", 94i64, &[]).unwrap();
    builder = builder.constant("COUNT_SYMBOL", 95i64, &[]).unwrap();
    builder = builder.constant("CPU_SYMBOL", 96i64, &[]).unwrap();
    builder = builder.constant("CREATE_SYMBOL", 97i64, &[]).unwrap();
    builder = builder.constant("CROSS_SYMBOL", 98i64, &[]).unwrap();
    builder = builder.constant("CUBE_SYMBOL", 99i64, &[]).unwrap();
    builder = builder.constant("CURDATE_SYMBOL", 100i64, &[]).unwrap();
    builder = builder.constant("CURRENT_SYMBOL", 101i64, &[]).unwrap();
    builder = builder
        .constant("CURRENT_DATE_SYMBOL", 102i64, &[])
        .unwrap();
    builder = builder
        .constant("CURRENT_TIME_SYMBOL", 103i64, &[])
        .unwrap();
    builder = builder
        .constant("CURRENT_TIMESTAMP_SYMBOL", 104i64, &[])
        .unwrap();
    builder = builder
        .constant("CURRENT_USER_SYMBOL", 105i64, &[])
        .unwrap();
    builder = builder.constant("CURSOR_SYMBOL", 106i64, &[]).unwrap();
    builder = builder.constant("CURSOR_NAME_SYMBOL", 107i64, &[]).unwrap();
    builder = builder.constant("CURTIME_SYMBOL", 108i64, &[]).unwrap();
    builder = builder.constant("DATABASE_SYMBOL", 109i64, &[]).unwrap();
    builder = builder.constant("DATABASES_SYMBOL", 110i64, &[]).unwrap();
    builder = builder.constant("DATAFILE_SYMBOL", 111i64, &[]).unwrap();
    builder = builder.constant("DATA_SYMBOL", 112i64, &[]).unwrap();
    builder = builder.constant("DATETIME_SYMBOL", 113i64, &[]).unwrap();
    builder = builder.constant("DATE_ADD_SYMBOL", 114i64, &[]).unwrap();
    builder = builder.constant("DATE_SUB_SYMBOL", 115i64, &[]).unwrap();
    builder = builder.constant("DATE_SYMBOL", 116i64, &[]).unwrap();
    builder = builder.constant("DAYOFMONTH_SYMBOL", 117i64, &[]).unwrap();
    builder = builder.constant("DAY_HOUR_SYMBOL", 118i64, &[]).unwrap();
    builder = builder
        .constant("DAY_MICROSECOND_SYMBOL", 119i64, &[])
        .unwrap();
    builder = builder.constant("DAY_MINUTE_SYMBOL", 120i64, &[]).unwrap();
    builder = builder.constant("DAY_SECOND_SYMBOL", 121i64, &[]).unwrap();
    builder = builder.constant("DAY_SYMBOL", 122i64, &[]).unwrap();
    builder = builder.constant("DEALLOCATE_SYMBOL", 123i64, &[]).unwrap();
    builder = builder.constant("DEC_SYMBOL", 124i64, &[]).unwrap();
    builder = builder.constant("DECIMAL_NUM_SYMBOL", 125i64, &[]).unwrap();
    builder = builder.constant("DECIMAL_SYMBOL", 126i64, &[]).unwrap();
    builder = builder.constant("DECLARE_SYMBOL", 127i64, &[]).unwrap();
    builder = builder.constant("DEFAULT_SYMBOL", 128i64, &[]).unwrap();
    builder = builder
        .constant("DEFAULT_AUTH_SYMBOL", 129i64, &[])
        .unwrap();
    builder = builder.constant("DEFINER_SYMBOL", 130i64, &[]).unwrap();
    builder = builder.constant("DELAYED_SYMBOL", 131i64, &[]).unwrap();
    builder = builder
        .constant("DELAY_KEY_WRITE_SYMBOL", 132i64, &[])
        .unwrap();
    builder = builder.constant("DELETE_SYMBOL", 133i64, &[]).unwrap();
    builder = builder.constant("DESC_SYMBOL", 134i64, &[]).unwrap();
    builder = builder.constant("DESCRIBE_SYMBOL", 135i64, &[]).unwrap();
    builder = builder
        .constant("DES_KEY_FILE_SYMBOL", 136i64, &[])
        .unwrap();
    builder = builder
        .constant("DETERMINISTIC_SYMBOL", 137i64, &[])
        .unwrap();
    builder = builder.constant("DIAGNOSTICS_SYMBOL", 138i64, &[]).unwrap();
    builder = builder.constant("DIRECTORY_SYMBOL", 139i64, &[]).unwrap();
    builder = builder.constant("DISABLE_SYMBOL", 140i64, &[]).unwrap();
    builder = builder.constant("DISCARD_SYMBOL", 141i64, &[]).unwrap();
    builder = builder.constant("DISK_SYMBOL", 142i64, &[]).unwrap();
    builder = builder.constant("DISTINCT_SYMBOL", 143i64, &[]).unwrap();
    builder = builder.constant("DISTINCTROW_SYMBOL", 144i64, &[]).unwrap();
    builder = builder.constant("DIV_SYMBOL", 145i64, &[]).unwrap();
    builder = builder.constant("DOUBLE_SYMBOL", 146i64, &[]).unwrap();
    builder = builder.constant("DO_SYMBOL", 147i64, &[]).unwrap();
    builder = builder.constant("DROP_SYMBOL", 148i64, &[]).unwrap();
    builder = builder.constant("DUAL_SYMBOL", 149i64, &[]).unwrap();
    builder = builder.constant("DUMPFILE_SYMBOL", 150i64, &[]).unwrap();
    builder = builder.constant("DUPLICATE_SYMBOL", 151i64, &[]).unwrap();
    builder = builder.constant("DYNAMIC_SYMBOL", 152i64, &[]).unwrap();
    builder = builder.constant("EACH_SYMBOL", 153i64, &[]).unwrap();
    builder = builder.constant("ELSE_SYMBOL", 154i64, &[]).unwrap();
    builder = builder.constant("ELSEIF_SYMBOL", 155i64, &[]).unwrap();
    builder = builder.constant("ENABLE_SYMBOL", 156i64, &[]).unwrap();
    builder = builder.constant("ENCLOSED_SYMBOL", 157i64, &[]).unwrap();
    builder = builder.constant("ENCRYPTION_SYMBOL", 158i64, &[]).unwrap();
    builder = builder.constant("END_SYMBOL", 159i64, &[]).unwrap();
    builder = builder.constant("ENDS_SYMBOL", 160i64, &[]).unwrap();
    builder = builder
        .constant("END_OF_INPUT_SYMBOL", 161i64, &[])
        .unwrap();
    builder = builder.constant("ENGINES_SYMBOL", 162i64, &[]).unwrap();
    builder = builder.constant("ENGINE_SYMBOL", 163i64, &[]).unwrap();
    builder = builder.constant("ENUM_SYMBOL", 164i64, &[]).unwrap();
    builder = builder.constant("ERROR_SYMBOL", 165i64, &[]).unwrap();
    builder = builder.constant("ERRORS_SYMBOL", 166i64, &[]).unwrap();
    builder = builder.constant("ESCAPED_SYMBOL", 167i64, &[]).unwrap();
    builder = builder.constant("ESCAPE_SYMBOL", 168i64, &[]).unwrap();
    builder = builder.constant("EVENTS_SYMBOL", 169i64, &[]).unwrap();
    builder = builder.constant("EVENT_SYMBOL", 170i64, &[]).unwrap();
    builder = builder.constant("EVERY_SYMBOL", 171i64, &[]).unwrap();
    builder = builder.constant("EXCHANGE_SYMBOL", 172i64, &[]).unwrap();
    builder = builder.constant("EXECUTE_SYMBOL", 173i64, &[]).unwrap();
    builder = builder.constant("EXISTS_SYMBOL", 174i64, &[]).unwrap();
    builder = builder.constant("EXIT_SYMBOL", 175i64, &[]).unwrap();
    builder = builder.constant("EXPANSION_SYMBOL", 176i64, &[]).unwrap();
    builder = builder.constant("EXPIRE_SYMBOL", 177i64, &[]).unwrap();
    builder = builder.constant("EXPLAIN_SYMBOL", 178i64, &[]).unwrap();
    builder = builder.constant("EXPORT_SYMBOL", 179i64, &[]).unwrap();
    builder = builder.constant("EXTENDED_SYMBOL", 180i64, &[]).unwrap();
    builder = builder.constant("EXTENT_SIZE_SYMBOL", 181i64, &[]).unwrap();
    builder = builder.constant("EXTRACT_SYMBOL", 182i64, &[]).unwrap();
    builder = builder.constant("FALSE_SYMBOL", 183i64, &[]).unwrap();
    builder = builder.constant("FAST_SYMBOL", 184i64, &[]).unwrap();
    builder = builder.constant("FAULTS_SYMBOL", 185i64, &[]).unwrap();
    builder = builder.constant("FETCH_SYMBOL", 186i64, &[]).unwrap();
    builder = builder.constant("FIELDS_SYMBOL", 187i64, &[]).unwrap();
    builder = builder.constant("FILE_SYMBOL", 188i64, &[]).unwrap();
    builder = builder
        .constant("FILE_BLOCK_SIZE_SYMBOL", 189i64, &[])
        .unwrap();
    builder = builder.constant("FILTER_SYMBOL", 190i64, &[]).unwrap();
    builder = builder.constant("FIRST_SYMBOL", 191i64, &[]).unwrap();
    builder = builder.constant("FIXED_SYMBOL", 192i64, &[]).unwrap();
    builder = builder.constant("FLOAT4_SYMBOL", 193i64, &[]).unwrap();
    builder = builder.constant("FLOAT8_SYMBOL", 194i64, &[]).unwrap();
    builder = builder.constant("FLOAT_SYMBOL", 195i64, &[]).unwrap();
    builder = builder.constant("FLUSH_SYMBOL", 196i64, &[]).unwrap();
    builder = builder.constant("FOLLOWS_SYMBOL", 197i64, &[]).unwrap();
    builder = builder.constant("FORCE_SYMBOL", 198i64, &[]).unwrap();
    builder = builder.constant("FOREIGN_SYMBOL", 199i64, &[]).unwrap();
    builder = builder.constant("FOR_SYMBOL", 200i64, &[]).unwrap();
    builder = builder.constant("FORMAT_SYMBOL", 201i64, &[]).unwrap();
    builder = builder.constant("FOUND_SYMBOL", 202i64, &[]).unwrap();
    builder = builder.constant("FROM_SYMBOL", 203i64, &[]).unwrap();
    builder = builder.constant("FULL_SYMBOL", 204i64, &[]).unwrap();
    builder = builder.constant("FULLTEXT_SYMBOL", 205i64, &[]).unwrap();
    builder = builder.constant("FUNCTION_SYMBOL", 206i64, &[]).unwrap();
    builder = builder.constant("GET_SYMBOL", 207i64, &[]).unwrap();
    builder = builder.constant("GENERAL_SYMBOL", 208i64, &[]).unwrap();
    builder = builder.constant("GENERATED_SYMBOL", 209i64, &[]).unwrap();
    builder = builder
        .constant("GROUP_REPLICATION_SYMBOL", 210i64, &[])
        .unwrap();
    builder = builder
        .constant("GEOMETRYCOLLECTION_SYMBOL", 211i64, &[])
        .unwrap();
    builder = builder.constant("GEOMETRY_SYMBOL", 212i64, &[]).unwrap();
    builder = builder.constant("GET_FORMAT_SYMBOL", 213i64, &[]).unwrap();
    builder = builder.constant("GLOBAL_SYMBOL", 214i64, &[]).unwrap();
    builder = builder.constant("GRANT_SYMBOL", 215i64, &[]).unwrap();
    builder = builder.constant("GRANTS_SYMBOL", 216i64, &[]).unwrap();
    builder = builder.constant("GROUP_SYMBOL", 217i64, &[]).unwrap();
    builder = builder
        .constant("GROUP_CONCAT_SYMBOL", 218i64, &[])
        .unwrap();
    builder = builder.constant("HANDLER_SYMBOL", 219i64, &[]).unwrap();
    builder = builder.constant("HASH_SYMBOL", 220i64, &[]).unwrap();
    builder = builder.constant("HAVING_SYMBOL", 221i64, &[]).unwrap();
    builder = builder.constant("HELP_SYMBOL", 222i64, &[]).unwrap();
    builder = builder
        .constant("HIGH_PRIORITY_SYMBOL", 223i64, &[])
        .unwrap();
    builder = builder.constant("HOST_SYMBOL", 224i64, &[]).unwrap();
    builder = builder.constant("HOSTS_SYMBOL", 225i64, &[]).unwrap();
    builder = builder
        .constant("HOUR_MICROSECOND_SYMBOL", 226i64, &[])
        .unwrap();
    builder = builder.constant("HOUR_MINUTE_SYMBOL", 227i64, &[]).unwrap();
    builder = builder.constant("HOUR_SECOND_SYMBOL", 228i64, &[]).unwrap();
    builder = builder.constant("HOUR_SYMBOL", 229i64, &[]).unwrap();
    builder = builder.constant("IDENTIFIED_SYMBOL", 230i64, &[]).unwrap();
    builder = builder.constant("IF_SYMBOL", 231i64, &[]).unwrap();
    builder = builder.constant("IGNORE_SYMBOL", 232i64, &[]).unwrap();
    builder = builder
        .constant("IGNORE_SERVER_IDS_SYMBOL", 233i64, &[])
        .unwrap();
    builder = builder.constant("IMPORT_SYMBOL", 234i64, &[]).unwrap();
    builder = builder.constant("INDEXES_SYMBOL", 235i64, &[]).unwrap();
    builder = builder.constant("INDEX_SYMBOL", 236i64, &[]).unwrap();
    builder = builder.constant("INFILE_SYMBOL", 237i64, &[]).unwrap();
    builder = builder
        .constant("INITIAL_SIZE_SYMBOL", 238i64, &[])
        .unwrap();
    builder = builder.constant("INNER_SYMBOL", 239i64, &[]).unwrap();
    builder = builder.constant("INOUT_SYMBOL", 240i64, &[]).unwrap();
    builder = builder.constant("INSENSITIVE_SYMBOL", 241i64, &[]).unwrap();
    builder = builder.constant("INSERT_SYMBOL", 242i64, &[]).unwrap();
    builder = builder
        .constant("INSERT_METHOD_SYMBOL", 243i64, &[])
        .unwrap();
    builder = builder.constant("INSTANCE_SYMBOL", 244i64, &[]).unwrap();
    builder = builder.constant("INSTALL_SYMBOL", 245i64, &[]).unwrap();
    builder = builder.constant("INTEGER_SYMBOL", 246i64, &[]).unwrap();
    builder = builder.constant("INTERVAL_SYMBOL", 247i64, &[]).unwrap();
    builder = builder.constant("INTO_SYMBOL", 248i64, &[]).unwrap();
    builder = builder.constant("INT_SYMBOL", 249i64, &[]).unwrap();
    builder = builder.constant("INVOKER_SYMBOL", 250i64, &[]).unwrap();
    builder = builder.constant("IN_SYMBOL", 251i64, &[]).unwrap();
    builder = builder
        .constant("IO_AFTER_GTIDS_SYMBOL", 252i64, &[])
        .unwrap();
    builder = builder
        .constant("IO_BEFORE_GTIDS_SYMBOL", 253i64, &[])
        .unwrap();
    builder = builder.constant("IO_THREAD_SYMBOL", 254i64, &[]).unwrap();
    builder = builder.constant("IO_SYMBOL", 255i64, &[]).unwrap();
    builder = builder.constant("IPC_SYMBOL", 256i64, &[]).unwrap();
    builder = builder.constant("IS_SYMBOL", 257i64, &[]).unwrap();
    builder = builder.constant("ISOLATION_SYMBOL", 258i64, &[]).unwrap();
    builder = builder.constant("ISSUER_SYMBOL", 259i64, &[]).unwrap();
    builder = builder.constant("ITERATE_SYMBOL", 260i64, &[]).unwrap();
    builder = builder.constant("JOIN_SYMBOL", 261i64, &[]).unwrap();
    builder = builder.constant("JSON_SYMBOL", 262i64, &[]).unwrap();
    builder = builder.constant("KEYS_SYMBOL", 263i64, &[]).unwrap();
    builder = builder
        .constant("KEY_BLOCK_SIZE_SYMBOL", 264i64, &[])
        .unwrap();
    builder = builder.constant("KEY_SYMBOL", 265i64, &[]).unwrap();
    builder = builder.constant("KILL_SYMBOL", 266i64, &[]).unwrap();
    builder = builder.constant("LANGUAGE_SYMBOL", 267i64, &[]).unwrap();
    builder = builder.constant("LAST_SYMBOL", 268i64, &[]).unwrap();
    builder = builder.constant("LEADING_SYMBOL", 269i64, &[]).unwrap();
    builder = builder.constant("LEAVES_SYMBOL", 270i64, &[]).unwrap();
    builder = builder.constant("LEAVE_SYMBOL", 271i64, &[]).unwrap();
    builder = builder.constant("LEFT_SYMBOL", 272i64, &[]).unwrap();
    builder = builder.constant("LESS_SYMBOL", 273i64, &[]).unwrap();
    builder = builder.constant("LEVEL_SYMBOL", 274i64, &[]).unwrap();
    builder = builder.constant("LIKE_SYMBOL", 275i64, &[]).unwrap();
    builder = builder.constant("LIMIT_SYMBOL", 276i64, &[]).unwrap();
    builder = builder.constant("LINEAR_SYMBOL", 277i64, &[]).unwrap();
    builder = builder.constant("LINES_SYMBOL", 278i64, &[]).unwrap();
    builder = builder.constant("LINESTRING_SYMBOL", 279i64, &[]).unwrap();
    builder = builder.constant("LIST_SYMBOL", 280i64, &[]).unwrap();
    builder = builder.constant("LOAD_SYMBOL", 281i64, &[]).unwrap();
    builder = builder.constant("LOCALTIME_SYMBOL", 282i64, &[]).unwrap();
    builder = builder
        .constant("LOCALTIMESTAMP_SYMBOL", 283i64, &[])
        .unwrap();
    builder = builder.constant("LOCAL_SYMBOL", 284i64, &[]).unwrap();
    builder = builder.constant("LOCATOR_SYMBOL", 285i64, &[]).unwrap();
    builder = builder.constant("LOCKS_SYMBOL", 286i64, &[]).unwrap();
    builder = builder.constant("LOCK_SYMBOL", 287i64, &[]).unwrap();
    builder = builder.constant("LOGFILE_SYMBOL", 288i64, &[]).unwrap();
    builder = builder.constant("LOGS_SYMBOL", 289i64, &[]).unwrap();
    builder = builder.constant("LONGBLOB_SYMBOL", 290i64, &[]).unwrap();
    builder = builder.constant("LONGTEXT_SYMBOL", 291i64, &[]).unwrap();
    builder = builder.constant("LONG_NUM_SYMBOL", 292i64, &[]).unwrap();
    builder = builder.constant("LONG_SYMBOL", 293i64, &[]).unwrap();
    builder = builder.constant("LOOP_SYMBOL", 294i64, &[]).unwrap();
    builder = builder
        .constant("LOW_PRIORITY_SYMBOL", 295i64, &[])
        .unwrap();
    builder = builder
        .constant("MASTER_AUTO_POSITION_SYMBOL", 296i64, &[])
        .unwrap();
    builder = builder.constant("MASTER_BIND_SYMBOL", 297i64, &[]).unwrap();
    builder = builder
        .constant("MASTER_CONNECT_RETRY_SYMBOL", 298i64, &[])
        .unwrap();
    builder = builder
        .constant("MASTER_DELAY_SYMBOL", 299i64, &[])
        .unwrap();
    builder = builder.constant("MASTER_HOST_SYMBOL", 300i64, &[]).unwrap();
    builder = builder
        .constant("MASTER_LOG_FILE_SYMBOL", 301i64, &[])
        .unwrap();
    builder = builder
        .constant("MASTER_LOG_POS_SYMBOL", 302i64, &[])
        .unwrap();
    builder = builder
        .constant("MASTER_PASSWORD_SYMBOL", 303i64, &[])
        .unwrap();
    builder = builder.constant("MASTER_PORT_SYMBOL", 304i64, &[]).unwrap();
    builder = builder
        .constant("MASTER_RETRY_COUNT_SYMBOL", 305i64, &[])
        .unwrap();
    builder = builder
        .constant("MASTER_SERVER_ID_SYMBOL", 306i64, &[])
        .unwrap();
    builder = builder
        .constant("MASTER_SSL_CAPATH_SYMBOL", 307i64, &[])
        .unwrap();
    builder = builder
        .constant("MASTER_SSL_CA_SYMBOL", 308i64, &[])
        .unwrap();
    builder = builder
        .constant("MASTER_SSL_CERT_SYMBOL", 309i64, &[])
        .unwrap();
    builder = builder
        .constant("MASTER_SSL_CIPHER_SYMBOL", 310i64, &[])
        .unwrap();
    builder = builder
        .constant("MASTER_SSL_CRL_SYMBOL", 311i64, &[])
        .unwrap();
    builder = builder
        .constant("MASTER_SSL_CRLPATH_SYMBOL", 312i64, &[])
        .unwrap();
    builder = builder
        .constant("MASTER_SSL_KEY_SYMBOL", 313i64, &[])
        .unwrap();
    builder = builder.constant("MASTER_SSL_SYMBOL", 314i64, &[]).unwrap();
    builder = builder
        .constant("MASTER_SSL_VERIFY_SERVER_CERT_SYMBOL", 315i64, &[])
        .unwrap();
    builder = builder.constant("MASTER_SYMBOL", 316i64, &[]).unwrap();
    builder = builder
        .constant("MASTER_TLS_VERSION_SYMBOL", 317i64, &[])
        .unwrap();
    builder = builder.constant("MASTER_USER_SYMBOL", 318i64, &[]).unwrap();
    builder = builder
        .constant("MASTER_HEARTBEAT_PERIOD_SYMBOL", 319i64, &[])
        .unwrap();
    builder = builder.constant("MATCH_SYMBOL", 320i64, &[]).unwrap();
    builder = builder
        .constant("MAX_CONNECTIONS_PER_HOUR_SYMBOL", 321i64, &[])
        .unwrap();
    builder = builder
        .constant("MAX_QUERIES_PER_HOUR_SYMBOL", 322i64, &[])
        .unwrap();
    builder = builder.constant("MAX_ROWS_SYMBOL", 323i64, &[]).unwrap();
    builder = builder.constant("MAX_SIZE_SYMBOL", 324i64, &[]).unwrap();
    builder = builder
        .constant("MAX_STATEMENT_TIME_SYMBOL", 325i64, &[])
        .unwrap();
    builder = builder.constant("MAX_SYMBOL", 326i64, &[]).unwrap();
    builder = builder
        .constant("MAX_UPDATES_PER_HOUR_SYMBOL", 327i64, &[])
        .unwrap();
    builder = builder
        .constant("MAX_USER_CONNECTIONS_SYMBOL", 328i64, &[])
        .unwrap();
    builder = builder.constant("MAXVALUE_SYMBOL", 329i64, &[]).unwrap();
    builder = builder.constant("MEDIUMBLOB_SYMBOL", 330i64, &[]).unwrap();
    builder = builder.constant("MEDIUMINT_SYMBOL", 331i64, &[]).unwrap();
    builder = builder.constant("MEDIUMTEXT_SYMBOL", 332i64, &[]).unwrap();
    builder = builder.constant("MEDIUM_SYMBOL", 333i64, &[]).unwrap();
    builder = builder.constant("MEMORY_SYMBOL", 334i64, &[]).unwrap();
    builder = builder.constant("MERGE_SYMBOL", 335i64, &[]).unwrap();
    builder = builder
        .constant("MESSAGE_TEXT_SYMBOL", 336i64, &[])
        .unwrap();
    builder = builder.constant("MICROSECOND_SYMBOL", 337i64, &[]).unwrap();
    builder = builder.constant("MID_SYMBOL", 338i64, &[]).unwrap();
    builder = builder.constant("MIDDLEINT_SYMBOL", 339i64, &[]).unwrap();
    builder = builder.constant("MIGRATE_SYMBOL", 340i64, &[]).unwrap();
    builder = builder
        .constant("MINUTE_MICROSECOND_SYMBOL", 341i64, &[])
        .unwrap();
    builder = builder
        .constant("MINUTE_SECOND_SYMBOL", 342i64, &[])
        .unwrap();
    builder = builder.constant("MINUTE_SYMBOL", 343i64, &[]).unwrap();
    builder = builder.constant("MIN_ROWS_SYMBOL", 344i64, &[]).unwrap();
    builder = builder.constant("MIN_SYMBOL", 345i64, &[]).unwrap();
    builder = builder.constant("MODE_SYMBOL", 346i64, &[]).unwrap();
    builder = builder.constant("MODIFIES_SYMBOL", 347i64, &[]).unwrap();
    builder = builder.constant("MODIFY_SYMBOL", 348i64, &[]).unwrap();
    builder = builder.constant("MOD_SYMBOL", 349i64, &[]).unwrap();
    builder = builder.constant("MONTH_SYMBOL", 350i64, &[]).unwrap();
    builder = builder
        .constant("MULTILINESTRING_SYMBOL", 351i64, &[])
        .unwrap();
    builder = builder.constant("MULTIPOINT_SYMBOL", 352i64, &[]).unwrap();
    builder = builder
        .constant("MULTIPOLYGON_SYMBOL", 353i64, &[])
        .unwrap();
    builder = builder.constant("MUTEX_SYMBOL", 354i64, &[]).unwrap();
    builder = builder.constant("MYSQL_ERRNO_SYMBOL", 355i64, &[]).unwrap();
    builder = builder.constant("NAMES_SYMBOL", 356i64, &[]).unwrap();
    builder = builder.constant("NAME_SYMBOL", 357i64, &[]).unwrap();
    builder = builder.constant("NATIONAL_SYMBOL", 358i64, &[]).unwrap();
    builder = builder.constant("NATURAL_SYMBOL", 359i64, &[]).unwrap();
    builder = builder
        .constant("NCHAR_STRING_SYMBOL", 360i64, &[])
        .unwrap();
    builder = builder.constant("NCHAR_SYMBOL", 361i64, &[]).unwrap();
    builder = builder.constant("NDB_SYMBOL", 362i64, &[]).unwrap();
    builder = builder.constant("NDBCLUSTER_SYMBOL", 363i64, &[]).unwrap();
    builder = builder.constant("NEG_SYMBOL", 364i64, &[]).unwrap();
    builder = builder.constant("NEVER_SYMBOL", 365i64, &[]).unwrap();
    builder = builder.constant("NEW_SYMBOL", 366i64, &[]).unwrap();
    builder = builder.constant("NEXT_SYMBOL", 367i64, &[]).unwrap();
    builder = builder.constant("NODEGROUP_SYMBOL", 368i64, &[]).unwrap();
    builder = builder.constant("NONE_SYMBOL", 369i64, &[]).unwrap();
    builder = builder.constant("NONBLOCKING_SYMBOL", 370i64, &[]).unwrap();
    builder = builder.constant("NOT_SYMBOL", 371i64, &[]).unwrap();
    builder = builder.constant("NOW_SYMBOL", 372i64, &[]).unwrap();
    builder = builder.constant("NO_SYMBOL", 373i64, &[]).unwrap();
    builder = builder.constant("NO_WAIT_SYMBOL", 374i64, &[]).unwrap();
    builder = builder
        .constant("NO_WRITE_TO_BINLOG_SYMBOL", 375i64, &[])
        .unwrap();
    builder = builder.constant("NULL_SYMBOL", 376i64, &[]).unwrap();
    builder = builder.constant("NUMBER_SYMBOL", 377i64, &[]).unwrap();
    builder = builder.constant("NUMERIC_SYMBOL", 378i64, &[]).unwrap();
    builder = builder.constant("NVARCHAR_SYMBOL", 379i64, &[]).unwrap();
    builder = builder.constant("OFFLINE_SYMBOL", 380i64, &[]).unwrap();
    builder = builder.constant("OFFSET_SYMBOL", 381i64, &[]).unwrap();
    builder = builder
        .constant("OLD_PASSWORD_SYMBOL", 382i64, &[])
        .unwrap();
    builder = builder.constant("ON_SYMBOL", 383i64, &[]).unwrap();
    builder = builder.constant("ONE_SYMBOL", 384i64, &[]).unwrap();
    builder = builder.constant("ONLINE_SYMBOL", 385i64, &[]).unwrap();
    builder = builder.constant("ONLY_SYMBOL", 386i64, &[]).unwrap();
    builder = builder.constant("OPEN_SYMBOL", 387i64, &[]).unwrap();
    builder = builder.constant("OPTIMIZE_SYMBOL", 388i64, &[]).unwrap();
    builder = builder
        .constant("OPTIMIZER_COSTS_SYMBOL", 389i64, &[])
        .unwrap();
    builder = builder.constant("OPTIONS_SYMBOL", 390i64, &[]).unwrap();
    builder = builder.constant("OPTION_SYMBOL", 391i64, &[]).unwrap();
    builder = builder.constant("OPTIONALLY_SYMBOL", 392i64, &[]).unwrap();
    builder = builder.constant("ORDER_SYMBOL", 393i64, &[]).unwrap();
    builder = builder.constant("OR_SYMBOL", 394i64, &[]).unwrap();
    builder = builder.constant("OUTER_SYMBOL", 395i64, &[]).unwrap();
    builder = builder.constant("OUTFILE_SYMBOL", 396i64, &[]).unwrap();
    builder = builder.constant("OUT_SYMBOL", 397i64, &[]).unwrap();
    builder = builder.constant("OWNER_SYMBOL", 398i64, &[]).unwrap();
    builder = builder.constant("PACK_KEYS_SYMBOL", 399i64, &[]).unwrap();
    builder = builder.constant("PAGE_SYMBOL", 400i64, &[]).unwrap();
    builder = builder.constant("PARSER_SYMBOL", 401i64, &[]).unwrap();
    builder = builder.constant("PARTIAL_SYMBOL", 402i64, &[]).unwrap();
    builder = builder
        .constant("PARTITIONING_SYMBOL", 403i64, &[])
        .unwrap();
    builder = builder.constant("PARTITIONS_SYMBOL", 404i64, &[]).unwrap();
    builder = builder.constant("PARTITION_SYMBOL", 405i64, &[]).unwrap();
    builder = builder.constant("PASSWORD_SYMBOL", 406i64, &[]).unwrap();
    builder = builder.constant("PHASE_SYMBOL", 407i64, &[]).unwrap();
    builder = builder.constant("PLUGINS_SYMBOL", 408i64, &[]).unwrap();
    builder = builder.constant("PLUGIN_DIR_SYMBOL", 409i64, &[]).unwrap();
    builder = builder.constant("PLUGIN_SYMBOL", 410i64, &[]).unwrap();
    builder = builder.constant("POINT_SYMBOL", 411i64, &[]).unwrap();
    builder = builder.constant("POLYGON_SYMBOL", 412i64, &[]).unwrap();
    builder = builder.constant("PORT_SYMBOL", 413i64, &[]).unwrap();
    builder = builder.constant("POSITION_SYMBOL", 414i64, &[]).unwrap();
    builder = builder.constant("PRECEDES_SYMBOL", 415i64, &[]).unwrap();
    builder = builder.constant("PRECISION_SYMBOL", 416i64, &[]).unwrap();
    builder = builder.constant("PREPARE_SYMBOL", 417i64, &[]).unwrap();
    builder = builder.constant("PRESERVE_SYMBOL", 418i64, &[]).unwrap();
    builder = builder.constant("PREV_SYMBOL", 419i64, &[]).unwrap();
    builder = builder.constant("PRIMARY_SYMBOL", 420i64, &[]).unwrap();
    builder = builder.constant("PRIVILEGES_SYMBOL", 421i64, &[]).unwrap();
    builder = builder.constant("PROCEDURE_SYMBOL", 422i64, &[]).unwrap();
    builder = builder.constant("PROCESS_SYMBOL", 423i64, &[]).unwrap();
    builder = builder.constant("PROCESSLIST_SYMBOL", 424i64, &[]).unwrap();
    builder = builder.constant("PROFILE_SYMBOL", 425i64, &[]).unwrap();
    builder = builder.constant("PROFILES_SYMBOL", 426i64, &[]).unwrap();
    builder = builder.constant("PROXY_SYMBOL", 427i64, &[]).unwrap();
    builder = builder.constant("PURGE_SYMBOL", 428i64, &[]).unwrap();
    builder = builder.constant("QUARTER_SYMBOL", 429i64, &[]).unwrap();
    builder = builder.constant("QUERY_SYMBOL", 430i64, &[]).unwrap();
    builder = builder.constant("QUICK_SYMBOL", 431i64, &[]).unwrap();
    builder = builder.constant("RANGE_SYMBOL", 432i64, &[]).unwrap();
    builder = builder.constant("READS_SYMBOL", 433i64, &[]).unwrap();
    builder = builder.constant("READ_ONLY_SYMBOL", 434i64, &[]).unwrap();
    builder = builder.constant("READ_SYMBOL", 435i64, &[]).unwrap();
    builder = builder.constant("READ_WRITE_SYMBOL", 436i64, &[]).unwrap();
    builder = builder.constant("REAL_SYMBOL", 437i64, &[]).unwrap();
    builder = builder.constant("REBUILD_SYMBOL", 438i64, &[]).unwrap();
    builder = builder.constant("RECOVER_SYMBOL", 439i64, &[]).unwrap();
    builder = builder.constant("REDOFILE_SYMBOL", 440i64, &[]).unwrap();
    builder = builder
        .constant("REDO_BUFFER_SIZE_SYMBOL", 441i64, &[])
        .unwrap();
    builder = builder.constant("REDUNDANT_SYMBOL", 442i64, &[]).unwrap();
    builder = builder.constant("REFERENCES_SYMBOL", 443i64, &[]).unwrap();
    builder = builder.constant("REGEXP_SYMBOL", 444i64, &[]).unwrap();
    builder = builder.constant("RELAY_SYMBOL", 445i64, &[]).unwrap();
    builder = builder.constant("RELAYLOG_SYMBOL", 446i64, &[]).unwrap();
    builder = builder
        .constant("RELAY_LOG_FILE_SYMBOL", 447i64, &[])
        .unwrap();
    builder = builder
        .constant("RELAY_LOG_POS_SYMBOL", 448i64, &[])
        .unwrap();
    builder = builder
        .constant("RELAY_THREAD_SYMBOL", 449i64, &[])
        .unwrap();
    builder = builder.constant("RELEASE_SYMBOL", 450i64, &[]).unwrap();
    builder = builder.constant("RELOAD_SYMBOL", 451i64, &[]).unwrap();
    builder = builder.constant("REMOVE_SYMBOL", 452i64, &[]).unwrap();
    builder = builder.constant("RENAME_SYMBOL", 453i64, &[]).unwrap();
    builder = builder.constant("REORGANIZE_SYMBOL", 454i64, &[]).unwrap();
    builder = builder.constant("REPAIR_SYMBOL", 455i64, &[]).unwrap();
    builder = builder.constant("REPEATABLE_SYMBOL", 456i64, &[]).unwrap();
    builder = builder.constant("REPEAT_SYMBOL", 457i64, &[]).unwrap();
    builder = builder.constant("REPLACE_SYMBOL", 458i64, &[]).unwrap();
    builder = builder.constant("REPLICATION_SYMBOL", 459i64, &[]).unwrap();
    builder = builder
        .constant("REPLICATE_DO_DB_SYMBOL", 460i64, &[])
        .unwrap();
    builder = builder
        .constant("REPLICATE_IGNORE_DB_SYMBOL", 461i64, &[])
        .unwrap();
    builder = builder
        .constant("REPLICATE_DO_TABLE_SYMBOL", 462i64, &[])
        .unwrap();
    builder = builder
        .constant("REPLICATE_IGNORE_TABLE_SYMBOL", 463i64, &[])
        .unwrap();
    builder = builder
        .constant("REPLICATE_WILD_DO_TABLE_SYMBOL", 464i64, &[])
        .unwrap();
    builder = builder
        .constant("REPLICATE_WILD_IGNORE_TABLE_SYMBOL", 465i64, &[])
        .unwrap();
    builder = builder
        .constant("REPLICATE_REWRITE_DB_SYMBOL", 466i64, &[])
        .unwrap();
    builder = builder.constant("REQUIRE_SYMBOL", 467i64, &[]).unwrap();
    builder = builder.constant("RESET_SYMBOL", 468i64, &[]).unwrap();
    builder = builder.constant("RESIGNAL_SYMBOL", 469i64, &[]).unwrap();
    builder = builder.constant("RESTORE_SYMBOL", 470i64, &[]).unwrap();
    builder = builder.constant("RESTRICT_SYMBOL", 471i64, &[]).unwrap();
    builder = builder.constant("RESUME_SYMBOL", 472i64, &[]).unwrap();
    builder = builder
        .constant("RETURNED_SQLSTATE_SYMBOL", 473i64, &[])
        .unwrap();
    builder = builder.constant("RETURNS_SYMBOL", 474i64, &[]).unwrap();
    builder = builder.constant("RETURN_SYMBOL", 475i64, &[]).unwrap();
    builder = builder.constant("REVERSE_SYMBOL", 476i64, &[]).unwrap();
    builder = builder.constant("REVOKE_SYMBOL", 477i64, &[]).unwrap();
    builder = builder.constant("RIGHT_SYMBOL", 478i64, &[]).unwrap();
    builder = builder.constant("RLIKE_SYMBOL", 479i64, &[]).unwrap();
    builder = builder.constant("ROLLBACK_SYMBOL", 480i64, &[]).unwrap();
    builder = builder.constant("ROLLUP_SYMBOL", 481i64, &[]).unwrap();
    builder = builder.constant("ROTATE_SYMBOL", 482i64, &[]).unwrap();
    builder = builder.constant("ROUTINE_SYMBOL", 483i64, &[]).unwrap();
    builder = builder.constant("ROWS_SYMBOL", 484i64, &[]).unwrap();
    builder = builder.constant("ROW_COUNT_SYMBOL", 485i64, &[]).unwrap();
    builder = builder.constant("ROW_FORMAT_SYMBOL", 486i64, &[]).unwrap();
    builder = builder.constant("ROW_SYMBOL", 487i64, &[]).unwrap();
    builder = builder.constant("RTREE_SYMBOL", 488i64, &[]).unwrap();
    builder = builder.constant("SAVEPOINT_SYMBOL", 489i64, &[]).unwrap();
    builder = builder.constant("SCHEDULE_SYMBOL", 490i64, &[]).unwrap();
    builder = builder.constant("SCHEMA_SYMBOL", 491i64, &[]).unwrap();
    builder = builder.constant("SCHEMA_NAME_SYMBOL", 492i64, &[]).unwrap();
    builder = builder.constant("SCHEMAS_SYMBOL", 493i64, &[]).unwrap();
    builder = builder
        .constant("SECOND_MICROSECOND_SYMBOL", 494i64, &[])
        .unwrap();
    builder = builder.constant("SECOND_SYMBOL", 495i64, &[]).unwrap();
    builder = builder.constant("SECURITY_SYMBOL", 496i64, &[]).unwrap();
    builder = builder.constant("SELECT_SYMBOL", 497i64, &[]).unwrap();
    builder = builder.constant("SENSITIVE_SYMBOL", 498i64, &[]).unwrap();
    builder = builder.constant("SEPARATOR_SYMBOL", 499i64, &[]).unwrap();
    builder = builder
        .constant("SERIALIZABLE_SYMBOL", 500i64, &[])
        .unwrap();
    builder = builder.constant("SERIAL_SYMBOL", 501i64, &[]).unwrap();
    builder = builder.constant("SESSION_SYMBOL", 502i64, &[]).unwrap();
    builder = builder.constant("SERVER_SYMBOL", 503i64, &[]).unwrap();
    builder = builder
        .constant("SERVER_OPTIONS_SYMBOL", 504i64, &[])
        .unwrap();
    builder = builder
        .constant("SESSION_USER_SYMBOL", 505i64, &[])
        .unwrap();
    builder = builder.constant("SET_SYMBOL", 506i64, &[]).unwrap();
    builder = builder.constant("SET_VAR_SYMBOL", 507i64, &[]).unwrap();
    builder = builder.constant("SHARE_SYMBOL", 508i64, &[]).unwrap();
    builder = builder.constant("SHOW_SYMBOL", 509i64, &[]).unwrap();
    builder = builder.constant("SHUTDOWN_SYMBOL", 510i64, &[]).unwrap();
    builder = builder.constant("SIGNAL_SYMBOL", 511i64, &[]).unwrap();
    builder = builder.constant("SIGNED_SYMBOL", 512i64, &[]).unwrap();
    builder = builder.constant("SIMPLE_SYMBOL", 513i64, &[]).unwrap();
    builder = builder.constant("SLAVE_SYMBOL", 514i64, &[]).unwrap();
    builder = builder.constant("SLOW_SYMBOL", 515i64, &[]).unwrap();
    builder = builder.constant("SMALLINT_SYMBOL", 516i64, &[]).unwrap();
    builder = builder.constant("SNAPSHOT_SYMBOL", 517i64, &[]).unwrap();
    builder = builder.constant("SOME_SYMBOL", 518i64, &[]).unwrap();
    builder = builder.constant("SOCKET_SYMBOL", 519i64, &[]).unwrap();
    builder = builder.constant("SONAME_SYMBOL", 520i64, &[]).unwrap();
    builder = builder.constant("SOUNDS_SYMBOL", 521i64, &[]).unwrap();
    builder = builder.constant("SOURCE_SYMBOL", 522i64, &[]).unwrap();
    builder = builder.constant("SPATIAL_SYMBOL", 523i64, &[]).unwrap();
    builder = builder.constant("SPECIFIC_SYMBOL", 524i64, &[]).unwrap();
    builder = builder
        .constant("SQLEXCEPTION_SYMBOL", 525i64, &[])
        .unwrap();
    builder = builder.constant("SQLSTATE_SYMBOL", 526i64, &[]).unwrap();
    builder = builder.constant("SQLWARNING_SYMBOL", 527i64, &[]).unwrap();
    builder = builder
        .constant("SQL_AFTER_GTIDS_SYMBOL", 528i64, &[])
        .unwrap();
    builder = builder
        .constant("SQL_AFTER_MTS_GAPS_SYMBOL", 529i64, &[])
        .unwrap();
    builder = builder
        .constant("SQL_BEFORE_GTIDS_SYMBOL", 530i64, &[])
        .unwrap();
    builder = builder
        .constant("SQL_BIG_RESULT_SYMBOL", 531i64, &[])
        .unwrap();
    builder = builder
        .constant("SQL_BUFFER_RESULT_SYMBOL", 532i64, &[])
        .unwrap();
    builder = builder.constant("SQL_CACHE_SYMBOL", 533i64, &[]).unwrap();
    builder = builder
        .constant("SQL_CALC_FOUND_ROWS_SYMBOL", 534i64, &[])
        .unwrap();
    builder = builder
        .constant("SQL_NO_CACHE_SYMBOL", 535i64, &[])
        .unwrap();
    builder = builder
        .constant("SQL_SMALL_RESULT_SYMBOL", 536i64, &[])
        .unwrap();
    builder = builder.constant("SQL_SYMBOL", 537i64, &[]).unwrap();
    builder = builder.constant("SQL_THREAD_SYMBOL", 538i64, &[]).unwrap();
    builder = builder.constant("SSL_SYMBOL", 539i64, &[]).unwrap();
    builder = builder.constant("STACKED_SYMBOL", 540i64, &[]).unwrap();
    builder = builder.constant("STARTING_SYMBOL", 541i64, &[]).unwrap();
    builder = builder.constant("STARTS_SYMBOL", 542i64, &[]).unwrap();
    builder = builder.constant("START_SYMBOL", 543i64, &[]).unwrap();
    builder = builder
        .constant("STATS_AUTO_RECALC_SYMBOL", 544i64, &[])
        .unwrap();
    builder = builder
        .constant("STATS_PERSISTENT_SYMBOL", 545i64, &[])
        .unwrap();
    builder = builder
        .constant("STATS_SAMPLE_PAGES_SYMBOL", 546i64, &[])
        .unwrap();
    builder = builder.constant("STATUS_SYMBOL", 547i64, &[]).unwrap();
    builder = builder.constant("STDDEV_SAMP_SYMBOL", 548i64, &[]).unwrap();
    builder = builder.constant("STDDEV_SYMBOL", 549i64, &[]).unwrap();
    builder = builder.constant("STDDEV_POP_SYMBOL", 550i64, &[]).unwrap();
    builder = builder.constant("STD_SYMBOL", 551i64, &[]).unwrap();
    builder = builder.constant("STOP_SYMBOL", 552i64, &[]).unwrap();
    builder = builder.constant("STORAGE_SYMBOL", 553i64, &[]).unwrap();
    builder = builder.constant("STORED_SYMBOL", 554i64, &[]).unwrap();
    builder = builder
        .constant("STRAIGHT_JOIN_SYMBOL", 555i64, &[])
        .unwrap();
    builder = builder.constant("STRING_SYMBOL", 556i64, &[]).unwrap();
    builder = builder
        .constant("SUBCLASS_ORIGIN_SYMBOL", 557i64, &[])
        .unwrap();
    builder = builder.constant("SUBDATE_SYMBOL", 558i64, &[]).unwrap();
    builder = builder.constant("SUBJECT_SYMBOL", 559i64, &[]).unwrap();
    builder = builder
        .constant("SUBPARTITIONS_SYMBOL", 560i64, &[])
        .unwrap();
    builder = builder
        .constant("SUBPARTITION_SYMBOL", 561i64, &[])
        .unwrap();
    builder = builder.constant("SUBSTR_SYMBOL", 562i64, &[]).unwrap();
    builder = builder.constant("SUBSTRING_SYMBOL", 563i64, &[]).unwrap();
    builder = builder.constant("SUM_SYMBOL", 564i64, &[]).unwrap();
    builder = builder.constant("SUPER_SYMBOL", 565i64, &[]).unwrap();
    builder = builder.constant("SUSPEND_SYMBOL", 566i64, &[]).unwrap();
    builder = builder.constant("SWAPS_SYMBOL", 567i64, &[]).unwrap();
    builder = builder.constant("SWITCHES_SYMBOL", 568i64, &[]).unwrap();
    builder = builder.constant("SYSDATE_SYMBOL", 569i64, &[]).unwrap();
    builder = builder.constant("SYSTEM_USER_SYMBOL", 570i64, &[]).unwrap();
    builder = builder.constant("TABLES_SYMBOL", 571i64, &[]).unwrap();
    builder = builder.constant("TABLESPACE_SYMBOL", 572i64, &[]).unwrap();
    builder = builder
        .constant("TABLE_REF_PRIORITY_SYMBOL", 573i64, &[])
        .unwrap();
    builder = builder.constant("TABLE_SYMBOL", 574i64, &[]).unwrap();
    builder = builder
        .constant("TABLE_CHECKSUM_SYMBOL", 575i64, &[])
        .unwrap();
    builder = builder.constant("TABLE_NAME_SYMBOL", 576i64, &[]).unwrap();
    builder = builder.constant("TEMPORARY_SYMBOL", 577i64, &[]).unwrap();
    builder = builder.constant("TEMPTABLE_SYMBOL", 578i64, &[]).unwrap();
    builder = builder.constant("TERMINATED_SYMBOL", 579i64, &[]).unwrap();
    builder = builder.constant("TEXT_SYMBOL", 580i64, &[]).unwrap();
    builder = builder.constant("THAN_SYMBOL", 581i64, &[]).unwrap();
    builder = builder.constant("THEN_SYMBOL", 582i64, &[]).unwrap();
    builder = builder.constant("TIMESTAMP_SYMBOL", 583i64, &[]).unwrap();
    builder = builder
        .constant("TIMESTAMP_ADD_SYMBOL", 584i64, &[])
        .unwrap();
    builder = builder
        .constant("TIMESTAMP_DIFF_SYMBOL", 585i64, &[])
        .unwrap();
    builder = builder.constant("TIME_SYMBOL", 586i64, &[]).unwrap();
    builder = builder.constant("TINYBLOB_SYMBOL", 587i64, &[]).unwrap();
    builder = builder.constant("TINYINT_SYMBOL", 588i64, &[]).unwrap();
    builder = builder.constant("TINYTEXT_SYMBOL", 589i64, &[]).unwrap();
    builder = builder.constant("TO_SYMBOL", 590i64, &[]).unwrap();
    builder = builder.constant("TRAILING_SYMBOL", 591i64, &[]).unwrap();
    builder = builder.constant("TRANSACTION_SYMBOL", 592i64, &[]).unwrap();
    builder = builder.constant("TRIGGERS_SYMBOL", 593i64, &[]).unwrap();
    builder = builder.constant("TRIGGER_SYMBOL", 594i64, &[]).unwrap();
    builder = builder.constant("TRIM_SYMBOL", 595i64, &[]).unwrap();
    builder = builder.constant("TRUE_SYMBOL", 596i64, &[]).unwrap();
    builder = builder.constant("TRUNCATE_SYMBOL", 597i64, &[]).unwrap();
    builder = builder.constant("TYPES_SYMBOL", 598i64, &[]).unwrap();
    builder = builder.constant("TYPE_SYMBOL", 599i64, &[]).unwrap();
    builder = builder.constant("UDF_RETURNS_SYMBOL", 600i64, &[]).unwrap();
    builder = builder.constant("UNCOMMITTED_SYMBOL", 601i64, &[]).unwrap();
    builder = builder.constant("UNDEFINED_SYMBOL", 602i64, &[]).unwrap();
    builder = builder.constant("UNDOFILE_SYMBOL", 603i64, &[]).unwrap();
    builder = builder
        .constant("UNDO_BUFFER_SIZE_SYMBOL", 604i64, &[])
        .unwrap();
    builder = builder.constant("UNDO_SYMBOL", 605i64, &[]).unwrap();
    builder = builder.constant("UNICODE_SYMBOL", 606i64, &[]).unwrap();
    builder = builder.constant("UNINSTALL_SYMBOL", 607i64, &[]).unwrap();
    builder = builder.constant("UNION_SYMBOL", 608i64, &[]).unwrap();
    builder = builder.constant("UNIQUE_SYMBOL", 609i64, &[]).unwrap();
    builder = builder.constant("UNKNOWN_SYMBOL", 610i64, &[]).unwrap();
    builder = builder.constant("UNLOCK_SYMBOL", 611i64, &[]).unwrap();
    builder = builder.constant("UNSIGNED_SYMBOL", 612i64, &[]).unwrap();
    builder = builder.constant("UNTIL_SYMBOL", 613i64, &[]).unwrap();
    builder = builder.constant("UPDATE_SYMBOL", 614i64, &[]).unwrap();
    builder = builder.constant("UPGRADE_SYMBOL", 615i64, &[]).unwrap();
    builder = builder.constant("USAGE_SYMBOL", 616i64, &[]).unwrap();
    builder = builder
        .constant("USER_RESOURCES_SYMBOL", 617i64, &[])
        .unwrap();
    builder = builder.constant("USER_SYMBOL", 618i64, &[]).unwrap();
    builder = builder.constant("USE_FRM_SYMBOL", 619i64, &[]).unwrap();
    builder = builder.constant("USE_SYMBOL", 620i64, &[]).unwrap();
    builder = builder.constant("USING_SYMBOL", 621i64, &[]).unwrap();
    builder = builder.constant("UTC_DATE_SYMBOL", 622i64, &[]).unwrap();
    builder = builder
        .constant("UTC_TIMESTAMP_SYMBOL", 623i64, &[])
        .unwrap();
    builder = builder.constant("UTC_TIME_SYMBOL", 624i64, &[]).unwrap();
    builder = builder.constant("VALIDATION_SYMBOL", 625i64, &[]).unwrap();
    builder = builder.constant("VALUES_SYMBOL", 626i64, &[]).unwrap();
    builder = builder.constant("VALUE_SYMBOL", 627i64, &[]).unwrap();
    builder = builder.constant("VARBINARY_SYMBOL", 628i64, &[]).unwrap();
    builder = builder.constant("VARCHAR_SYMBOL", 629i64, &[]).unwrap();
    builder = builder
        .constant("VARCHARACTER_SYMBOL", 630i64, &[])
        .unwrap();
    builder = builder.constant("VARIABLES_SYMBOL", 631i64, &[]).unwrap();
    builder = builder.constant("VARIANCE_SYMBOL", 632i64, &[]).unwrap();
    builder = builder.constant("VARYING_SYMBOL", 633i64, &[]).unwrap();
    builder = builder.constant("VAR_POP_SYMBOL", 634i64, &[]).unwrap();
    builder = builder.constant("VAR_SAMP_SYMBOL", 635i64, &[]).unwrap();
    builder = builder.constant("VIEW_SYMBOL", 636i64, &[]).unwrap();
    builder = builder.constant("VIRTUAL_SYMBOL", 637i64, &[]).unwrap();
    builder = builder.constant("WAIT_SYMBOL", 638i64, &[]).unwrap();
    builder = builder.constant("WARNINGS_SYMBOL", 639i64, &[]).unwrap();
    builder = builder.constant("WEEK_SYMBOL", 640i64, &[]).unwrap();
    builder = builder
        .constant("WEIGHT_STRING_SYMBOL", 641i64, &[])
        .unwrap();
    builder = builder.constant("WHEN_SYMBOL", 642i64, &[]).unwrap();
    builder = builder.constant("WHERE_SYMBOL", 643i64, &[]).unwrap();
    builder = builder.constant("WHILE_SYMBOL", 644i64, &[]).unwrap();
    builder = builder.constant("WITH_SYMBOL", 645i64, &[]).unwrap();
    builder = builder.constant("WITHOUT_SYMBOL", 646i64, &[]).unwrap();
    builder = builder.constant("WORK_SYMBOL", 647i64, &[]).unwrap();
    builder = builder.constant("WRAPPER_SYMBOL", 648i64, &[]).unwrap();
    builder = builder.constant("WRITE_SYMBOL", 649i64, &[]).unwrap();
    builder = builder.constant("X509_SYMBOL", 650i64, &[]).unwrap();
    builder = builder.constant("XA_SYMBOL", 651i64, &[]).unwrap();
    builder = builder.constant("XID_SYMBOL", 652i64, &[]).unwrap();
    builder = builder.constant("XML_SYMBOL", 653i64, &[]).unwrap();
    builder = builder.constant("XOR_SYMBOL", 654i64, &[]).unwrap();
    builder = builder.constant("YEAR_MONTH_SYMBOL", 655i64, &[]).unwrap();
    builder = builder.constant("YEAR_SYMBOL", 656i64, &[]).unwrap();
    builder = builder.constant("ZEROFILL_SYMBOL", 657i64, &[]).unwrap();
    builder = builder.constant("PERSIST_SYMBOL", 658i64, &[]).unwrap();
    builder = builder.constant("ROLE_SYMBOL", 659i64, &[]).unwrap();
    builder = builder.constant("ADMIN_SYMBOL", 660i64, &[]).unwrap();
    builder = builder.constant("INVISIBLE_SYMBOL", 661i64, &[]).unwrap();
    builder = builder.constant("VISIBLE_SYMBOL", 662i64, &[]).unwrap();
    builder = builder.constant("EXCEPT_SYMBOL", 663i64, &[]).unwrap();
    builder = builder.constant("COMPONENT_SYMBOL", 664i64, &[]).unwrap();
    builder = builder.constant("RECURSIVE_SYMBOL", 665i64, &[]).unwrap();
    builder = builder
        .constant("JSON_OBJECTAGG_SYMBOL", 666i64, &[])
        .unwrap();
    builder = builder
        .constant("JSON_ARRAYAGG_SYMBOL", 667i64, &[])
        .unwrap();
    builder = builder.constant("OF_SYMBOL", 668i64, &[]).unwrap();
    builder = builder.constant("SKIP_SYMBOL", 669i64, &[]).unwrap();
    builder = builder.constant("LOCKED_SYMBOL", 670i64, &[]).unwrap();
    builder = builder.constant("NOWAIT_SYMBOL", 671i64, &[]).unwrap();
    builder = builder.constant("GROUPING_SYMBOL", 672i64, &[]).unwrap();
    builder = builder
        .constant("PERSIST_ONLY_SYMBOL", 673i64, &[])
        .unwrap();
    builder = builder.constant("HISTOGRAM_SYMBOL", 674i64, &[]).unwrap();
    builder = builder.constant("BUCKETS_SYMBOL", 675i64, &[]).unwrap();
    builder = builder.constant("REMOTE_SYMBOL", 676i64, &[]).unwrap();
    builder = builder.constant("CLONE_SYMBOL", 677i64, &[]).unwrap();
    builder = builder.constant("CUME_DIST_SYMBOL", 678i64, &[]).unwrap();
    builder = builder.constant("DENSE_RANK_SYMBOL", 679i64, &[]).unwrap();
    builder = builder.constant("EXCLUDE_SYMBOL", 680i64, &[]).unwrap();
    builder = builder.constant("FIRST_VALUE_SYMBOL", 681i64, &[]).unwrap();
    builder = builder.constant("FOLLOWING_SYMBOL", 682i64, &[]).unwrap();
    builder = builder.constant("GROUPS_SYMBOL", 683i64, &[]).unwrap();
    builder = builder.constant("LAG_SYMBOL", 684i64, &[]).unwrap();
    builder = builder.constant("LAST_VALUE_SYMBOL", 685i64, &[]).unwrap();
    builder = builder.constant("LEAD_SYMBOL", 686i64, &[]).unwrap();
    builder = builder.constant("NTH_VALUE_SYMBOL", 687i64, &[]).unwrap();
    builder = builder.constant("NTILE_SYMBOL", 688i64, &[]).unwrap();
    builder = builder.constant("NULLS_SYMBOL", 689i64, &[]).unwrap();
    builder = builder.constant("OTHERS_SYMBOL", 690i64, &[]).unwrap();
    builder = builder.constant("OVER_SYMBOL", 691i64, &[]).unwrap();
    builder = builder
        .constant("PERCENT_RANK_SYMBOL", 692i64, &[])
        .unwrap();
    builder = builder.constant("PRECEDING_SYMBOL", 693i64, &[]).unwrap();
    builder = builder.constant("RANK_SYMBOL", 694i64, &[]).unwrap();
    builder = builder.constant("RESPECT_SYMBOL", 695i64, &[]).unwrap();
    builder = builder.constant("ROW_NUMBER_SYMBOL", 696i64, &[]).unwrap();
    builder = builder.constant("TIES_SYMBOL", 697i64, &[]).unwrap();
    builder = builder.constant("UNBOUNDED_SYMBOL", 698i64, &[]).unwrap();
    builder = builder.constant("WINDOW_SYMBOL", 699i64, &[]).unwrap();
    builder = builder.constant("EMPTY_SYMBOL", 700i64, &[]).unwrap();
    builder = builder.constant("JSON_TABLE_SYMBOL", 701i64, &[]).unwrap();
    builder = builder.constant("NESTED_SYMBOL", 702i64, &[]).unwrap();
    builder = builder.constant("ORDINALITY_SYMBOL", 703i64, &[]).unwrap();
    builder = builder.constant("PATH_SYMBOL", 704i64, &[]).unwrap();
    builder = builder.constant("HISTORY_SYMBOL", 705i64, &[]).unwrap();
    builder = builder.constant("REUSE_SYMBOL", 706i64, &[]).unwrap();
    builder = builder.constant("SRID_SYMBOL", 707i64, &[]).unwrap();
    builder = builder
        .constant("THREAD_PRIORITY_SYMBOL", 708i64, &[])
        .unwrap();
    builder = builder.constant("RESOURCE_SYMBOL", 709i64, &[]).unwrap();
    builder = builder.constant("SYSTEM_SYMBOL", 710i64, &[]).unwrap();
    builder = builder.constant("VCPU_SYMBOL", 711i64, &[]).unwrap();
    builder = builder
        .constant("MASTER_PUBLIC_KEY_PATH_SYMBOL", 712i64, &[])
        .unwrap();
    builder = builder
        .constant("GET_MASTER_PUBLIC_KEY_SYMBOL", 713i64, &[])
        .unwrap();
    builder = builder.constant("RESTART_SYMBOL", 714i64, &[]).unwrap();
    builder = builder.constant("DEFINITION_SYMBOL", 715i64, &[]).unwrap();
    builder = builder.constant("DESCRIPTION_SYMBOL", 716i64, &[]).unwrap();
    builder = builder
        .constant("ORGANIZATION_SYMBOL", 717i64, &[])
        .unwrap();
    builder = builder.constant("REFERENCE_SYMBOL", 718i64, &[]).unwrap();
    builder = builder.constant("OPTIONAL_SYMBOL", 719i64, &[]).unwrap();
    builder = builder.constant("SECONDARY_SYMBOL", 720i64, &[]).unwrap();
    builder = builder
        .constant("SECONDARY_ENGINE_SYMBOL", 721i64, &[])
        .unwrap();
    builder = builder
        .constant("SECONDARY_LOAD_SYMBOL", 722i64, &[])
        .unwrap();
    builder = builder
        .constant("SECONDARY_UNLOAD_SYMBOL", 723i64, &[])
        .unwrap();
    builder = builder.constant("ACTIVE_SYMBOL", 724i64, &[]).unwrap();
    builder = builder.constant("INACTIVE_SYMBOL", 725i64, &[]).unwrap();
    builder = builder.constant("LATERAL_SYMBOL", 726i64, &[]).unwrap();
    builder = builder.constant("RETAIN_SYMBOL", 727i64, &[]).unwrap();
    builder = builder.constant("OLD_SYMBOL", 728i64, &[]).unwrap();
    builder = builder
        .constant("NETWORK_NAMESPACE_SYMBOL", 729i64, &[])
        .unwrap();
    builder = builder.constant("ENFORCED_SYMBOL", 730i64, &[]).unwrap();
    builder = builder.constant("ARRAY_SYMBOL", 731i64, &[]).unwrap();
    builder = builder.constant("OJ_SYMBOL", 732i64, &[]).unwrap();
    builder = builder.constant("MEMBER_SYMBOL", 733i64, &[]).unwrap();
    builder = builder.constant("RANDOM_SYMBOL", 734i64, &[]).unwrap();
    builder = builder
        .constant("MASTER_COMPRESSION_ALGORITHM_SYMBOL", 735i64, &[])
        .unwrap();
    builder = builder
        .constant("MASTER_ZSTD_COMPRESSION_LEVEL_SYMBOL", 736i64, &[])
        .unwrap();
    builder = builder
        .constant("PRIVILEGE_CHECKS_USER_SYMBOL", 737i64, &[])
        .unwrap();
    builder = builder
        .constant("MASTER_TLS_CIPHERSUITES_SYMBOL", 738i64, &[])
        .unwrap();
    builder = builder
        .constant("REQUIRE_ROW_FORMAT_SYMBOL", 739i64, &[])
        .unwrap();
    builder = builder
        .constant("PASSWORD_LOCK_TIME_SYMBOL", 740i64, &[])
        .unwrap();
    builder = builder
        .constant("FAILED_LOGIN_ATTEMPTS_SYMBOL", 741i64, &[])
        .unwrap();
    builder = builder
        .constant("REQUIRE_TABLE_PRIMARY_KEY_CHECK_SYMBOL", 742i64, &[])
        .unwrap();
    builder = builder.constant("STREAM_SYMBOL", 743i64, &[]).unwrap();
    builder = builder.constant("OFF_SYMBOL", 744i64, &[]).unwrap();
    builder = builder.constant("AT_AT_SIGN_SYMBOL", 745i64, &[]).unwrap();
    builder = builder.constant("AT_SIGN_SYMBOL", 746i64, &[]).unwrap();
    builder = builder.constant("CLOSE_CURLY_SYMBOL", 747i64, &[]).unwrap();
    builder = builder.constant("CLOSE_PAR_SYMBOL", 748i64, &[]).unwrap();
    builder = builder.constant("COLON_SYMBOL", 749i64, &[]).unwrap();
    builder = builder.constant("COMMA_SYMBOL", 750i64, &[]).unwrap();
    builder = builder.constant("DOT_SYMBOL", 751i64, &[]).unwrap();
    builder = builder.constant("OPEN_CURLY_SYMBOL", 752i64, &[]).unwrap();
    builder = builder.constant("OPEN_PAR_SYMBOL", 753i64, &[]).unwrap();
    builder = builder.constant("PARAM_MARKER", 754i64, &[]).unwrap();
    builder = builder.constant("SEMICOLON_SYMBOL", 755i64, &[]).unwrap();
    builder = builder.constant("ASSIGN_OPERATOR", 756i64, &[]).unwrap();
    builder = builder
        .constant("BITWISE_AND_OPERATOR", 757i64, &[])
        .unwrap();
    builder = builder
        .constant("BITWISE_NOT_OPERATOR", 758i64, &[])
        .unwrap();
    builder = builder
        .constant("BITWISE_OR_OPERATOR", 759i64, &[])
        .unwrap();
    builder = builder
        .constant("BITWISE_XOR_OPERATOR", 760i64, &[])
        .unwrap();
    builder = builder
        .constant("CONCAT_PIPES_SYMBOL", 761i64, &[])
        .unwrap();
    builder = builder.constant("DIV_OPERATOR", 762i64, &[]).unwrap();
    builder = builder.constant("EQUAL_OPERATOR", 763i64, &[]).unwrap();
    builder = builder
        .constant("GREATER_OR_EQUAL_OPERATOR", 764i64, &[])
        .unwrap();
    builder = builder
        .constant("GREATER_THAN_OPERATOR", 765i64, &[])
        .unwrap();
    builder = builder
        .constant("JSON_SEPARATOR_SYMBOL", 766i64, &[])
        .unwrap();
    builder = builder
        .constant("JSON_UNQUOTED_SEPARATOR_SYMBOL", 767i64, &[])
        .unwrap();
    builder = builder
        .constant("LESS_OR_EQUAL_OPERATOR", 768i64, &[])
        .unwrap();
    builder = builder.constant("LESS_THAN_OPERATOR", 769i64, &[]).unwrap();
    builder = builder
        .constant("LOGICAL_AND_OPERATOR", 770i64, &[])
        .unwrap();
    builder = builder
        .constant("LOGICAL_NOT_OPERATOR", 771i64, &[])
        .unwrap();
    builder = builder
        .constant("LOGICAL_OR_OPERATOR", 772i64, &[])
        .unwrap();
    builder = builder.constant("MINUS_OPERATOR", 773i64, &[]).unwrap();
    builder = builder.constant("MOD_OPERATOR", 774i64, &[]).unwrap();
    builder = builder.constant("MULT_OPERATOR", 775i64, &[]).unwrap();
    builder = builder.constant("NOT_EQUAL_OPERATOR", 776i64, &[]).unwrap();
    builder = builder
        .constant("NULL_SAFE_EQUAL_OPERATOR", 777i64, &[])
        .unwrap();
    builder = builder.constant("PLUS_OPERATOR", 778i64, &[]).unwrap();
    builder = builder
        .constant("SHIFT_LEFT_OPERATOR", 779i64, &[])
        .unwrap();
    builder = builder
        .constant("SHIFT_RIGHT_OPERATOR", 780i64, &[])
        .unwrap();
    builder = builder
        .constant("BACK_TICK_QUOTED_ID", 781i64, &[])
        .unwrap();
    builder = builder.constant("BIN_NUMBER", 782i64, &[]).unwrap();
    builder = builder.constant("DECIMAL_NUMBER", 783i64, &[]).unwrap();
    builder = builder.constant("DOUBLE_QUOTED_TEXT", 784i64, &[]).unwrap();
    builder = builder.constant("FLOAT_NUMBER", 785i64, &[]).unwrap();
    builder = builder.constant("HEX_NUMBER", 786i64, &[]).unwrap();
    builder = builder.constant("INT_NUMBER", 787i64, &[]).unwrap();
    builder = builder.constant("LONG_NUMBER", 788i64, &[]).unwrap();
    builder = builder.constant("NCHAR_TEXT", 789i64, &[]).unwrap();
    builder = builder.constant("SINGLE_QUOTED_TEXT", 790i64, &[]).unwrap();
    builder = builder.constant("ULONGLONG_NUMBER", 791i64, &[]).unwrap();
    builder = builder.constant("AT_TEXT_SUFFIX", 792i64, &[]).unwrap();
    builder = builder.constant("IDENTIFIER", 793i64, &[]).unwrap();
    builder = builder.constant("UNDERSCORE_CHARSET", 794i64, &[]).unwrap();
    builder = builder.constant("INT1_SYMBOL", 795i64, &[]).unwrap();
    builder = builder.constant("INT2_SYMBOL", 796i64, &[]).unwrap();
    builder = builder.constant("INT3_SYMBOL", 797i64, &[]).unwrap();
    builder = builder.constant("INT4_SYMBOL", 798i64, &[]).unwrap();
    builder = builder.constant("INT8_SYMBOL", 799i64, &[]).unwrap();
    builder = builder.constant("NOT2_SYMBOL", 800i64, &[]).unwrap();
    builder = builder.constant("NULL2_SYMBOL", 801i64, &[]).unwrap();
    builder = builder.constant("SQL_TSI_DAY_SYMBOL", 802i64, &[]).unwrap();
    builder = builder
        .constant("SQL_TSI_HOUR_SYMBOL", 803i64, &[])
        .unwrap();
    builder = builder
        .constant("SQL_TSI_MICROSECOND_SYMBOL", 804i64, &[])
        .unwrap();
    builder = builder
        .constant("SQL_TSI_MINUTE_SYMBOL", 805i64, &[])
        .unwrap();
    builder = builder
        .constant("SQL_TSI_MONTH_SYMBOL", 806i64, &[])
        .unwrap();
    builder = builder
        .constant("SQL_TSI_QUARTER_SYMBOL", 807i64, &[])
        .unwrap();
    builder = builder
        .constant("SQL_TSI_SECOND_SYMBOL", 808i64, &[])
        .unwrap();
    builder = builder
        .constant("SQL_TSI_WEEK_SYMBOL", 809i64, &[])
        .unwrap();
    builder = builder
        .constant("SQL_TSI_YEAR_SYMBOL", 810i64, &[])
        .unwrap();
    builder = builder.constant("INTERSECT_SYMBOL", 811i64, &[]).unwrap();
    builder = builder.constant("ATTRIBUTE_SYMBOL", 812i64, &[]).unwrap();
    builder = builder
        .constant("SOURCE_AUTO_POSITION_SYMBOL", 813i64, &[])
        .unwrap();
    builder = builder.constant("SOURCE_BIND_SYMBOL", 814i64, &[]).unwrap();
    builder = builder
        .constant("SOURCE_COMPRESSION_ALGORITHM_SYMBOL", 815i64, &[])
        .unwrap();
    builder = builder
        .constant("SOURCE_CONNECT_RETRY_SYMBOL", 816i64, &[])
        .unwrap();
    builder = builder
        .constant("SOURCE_CONNECTION_AUTO_FAILOVER_SYMBOL", 817i64, &[])
        .unwrap();
    builder = builder
        .constant("SOURCE_DELAY_SYMBOL", 818i64, &[])
        .unwrap();
    builder = builder
        .constant("SOURCE_HEARTBEAT_PERIOD_SYMBOL", 819i64, &[])
        .unwrap();
    builder = builder.constant("SOURCE_HOST_SYMBOL", 820i64, &[]).unwrap();
    builder = builder
        .constant("SOURCE_LOG_FILE_SYMBOL", 821i64, &[])
        .unwrap();
    builder = builder
        .constant("SOURCE_LOG_POS_SYMBOL", 822i64, &[])
        .unwrap();
    builder = builder
        .constant("SOURCE_PASSWORD_SYMBOL", 823i64, &[])
        .unwrap();
    builder = builder.constant("SOURCE_PORT_SYMBOL", 824i64, &[]).unwrap();
    builder = builder
        .constant("SOURCE_PUBLIC_KEY_PATH_SYMBOL", 825i64, &[])
        .unwrap();
    builder = builder
        .constant("SOURCE_RETRY_COUNT_SYMBOL", 826i64, &[])
        .unwrap();
    builder = builder.constant("SOURCE_SSL_SYMBOL", 827i64, &[]).unwrap();
    builder = builder
        .constant("SOURCE_SSL_CA_SYMBOL", 828i64, &[])
        .unwrap();
    builder = builder
        .constant("SOURCE_SSL_CAPATH_SYMBOL", 829i64, &[])
        .unwrap();
    builder = builder
        .constant("SOURCE_SSL_CERT_SYMBOL", 830i64, &[])
        .unwrap();
    builder = builder
        .constant("SOURCE_SSL_CIPHER_SYMBOL", 831i64, &[])
        .unwrap();
    builder = builder
        .constant("SOURCE_SSL_CRL_SYMBOL", 832i64, &[])
        .unwrap();
    builder = builder
        .constant("SOURCE_SSL_CRLPATH_SYMBOL", 833i64, &[])
        .unwrap();
    builder = builder
        .constant("SOURCE_SSL_KEY_SYMBOL", 834i64, &[])
        .unwrap();
    builder = builder
        .constant("SOURCE_SSL_VERIFY_SERVER_CERT_SYMBOL", 835i64, &[])
        .unwrap();
    builder = builder
        .constant("SOURCE_TLS_CIPHERSUITES_SYMBOL", 836i64, &[])
        .unwrap();
    builder = builder
        .constant("SOURCE_TLS_VERSION_SYMBOL", 837i64, &[])
        .unwrap();
    builder = builder.constant("SOURCE_USER_SYMBOL", 838i64, &[]).unwrap();
    builder = builder
        .constant("SOURCE_ZSTD_COMPRESSION_LEVEL_SYMBOL", 839i64, &[])
        .unwrap();
    builder = builder
        .constant("GET_SOURCE_PUBLIC_KEY_SYMBOL", 840i64, &[])
        .unwrap();
    builder = builder.constant("GTID_ONLY_SYMBOL", 841i64, &[]).unwrap();
    builder = builder
        .constant("ASSIGN_GTIDS_TO_ANONYMOUS_TRANSACTIONS_SYMBOL", 842i64, &[])
        .unwrap();
    builder = builder.constant("ZONE_SYMBOL", 843i64, &[]).unwrap();
    builder = builder.constant("INNODB_SYMBOL", 844i64, &[]).unwrap();
    builder = builder.constant("TLS_SYMBOL", 845i64, &[]).unwrap();
    builder = builder.constant("REDO_LOG_SYMBOL", 846i64, &[]).unwrap();
    builder = builder.constant("KEYRING_SYMBOL", 847i64, &[]).unwrap();
    builder = builder
        .constant("ENGINE_ATTRIBUTE_SYMBOL", 848i64, &[])
        .unwrap();
    builder = builder
        .constant("SECONDARY_ENGINE_ATTRIBUTE_SYMBOL", 849i64, &[])
        .unwrap();
    builder = builder.constant("JSON_VALUE_SYMBOL", 850i64, &[]).unwrap();
    builder = builder.constant("RETURNING_SYMBOL", 851i64, &[]).unwrap();
    builder = builder
        .constant("GEOMCOLLECTION_SYMBOL", 852i64, &[])
        .unwrap();
    builder = builder.constant("COMMENT", 900i64, &[]).unwrap();
    builder = builder
        .constant("MYSQL_COMMENT_START", 901i64, &[])
        .unwrap();
    builder = builder.constant("MYSQL_COMMENT_END", 902i64, &[]).unwrap();
    builder = builder.constant("WHITESPACE", 0i64, &[]).unwrap();
    builder = builder.constant("EOF", -1i64, &[]).unwrap();
    builder = builder.constant("TOKENS", array_tokens(), &[]).unwrap();
    builder = builder
        .constant("FUNCTIONS", array_functions(), &[])
        .unwrap();
    builder = builder.constant("SYNONYMS", array_synonyms(), &[]).unwrap();
    builder = builder.constant("VERSIONS", array_versions(), &[]).unwrap();
    builder = builder
        .constant("UNDERSCORE_CHARSETS", array_underscore_charsets(), &[])
        .unwrap();
    builder
}
