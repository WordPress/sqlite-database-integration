<?php
/**
 * Define constants for the SQLite implementation.
 */

// Temporary - This will be in wp-config.php once SQLite is merged in Core.
if ( ! defined( 'DB_ENGINE' ) ) {
	if ( defined( 'SQLITE_DB_DROPIN_VERSION' ) ) {
		define( 'DB_ENGINE', 'sqlite' );
	} else {
		define( 'DB_ENGINE', 'mysql' );
	}
}

/**
 * DB_PATH is the absolute database file path, or ":memory:" for an in-memory database.
 *
 * Cannot be combined with explicit DB_DIR, DB_FILE, FQDBDIR, or FQDB definitions.
 * The database directory is also used for storage locks and must be writable by PHP.
 * When not configured, the drop-in defines DB_PATH after initializing the storage.
 *
 * Example: define( 'DB_PATH', '/private/wordpress/database.sqlite' );
 */
if ( 'sqlite' === DB_ENGINE && defined( 'DB_PATH' ) ) {
	if ( defined( 'DB_DIR' ) || defined( 'DB_FILE' ) || defined( 'FQDBDIR' ) || defined( 'FQDB' ) ) {
		throw new RuntimeException( 'DB_PATH cannot be combined with DB_DIR, DB_FILE, FQDBDIR, or FQDB. Remove the legacy definitions.' );
	}

	if ( ! is_string( DB_PATH ) ) {
		throw new RuntimeException( 'DB_PATH must be a string.' );
	}
}

/**
 * DB_DIR selects the database directory when DB_PATH is not configured.
 * Must not be configured together with DB_PATH.
 *
 * @deprecated 3.1.0 Define DB_PATH instead.
 */

/**
 * DB_FILE selects a filename inside DB_DIR or FQDBDIR.
 * Must not be configured together with DB_PATH.
 *
 * @deprecated 3.1.0 Define DB_PATH instead.
 */

/**
 * FQDBDIR is a directory where the sqlite database file is placed.
 * Defaults to the directory containing DB_PATH, or the legacy directory setting.
 * Must not be configured together with DB_PATH.
 *
 * @deprecated 3.0.0 Define DB_PATH instead of overriding FQDBDIR.
 */
if ( ! defined( 'FQDBDIR' ) ) {
	if ( defined( 'DB_PATH' ) && is_string( DB_PATH ) ) {
		define( 'FQDBDIR', rtrim( dirname( DB_PATH ), '/\\' ) . '/' );
	} elseif ( defined( 'DB_DIR' ) ) {
		define( 'FQDBDIR', rtrim( DB_DIR, '/\\' ) . '/' );
	} elseif ( defined( 'WP_CONTENT_DIR' ) ) {
		define( 'FQDBDIR', WP_CONTENT_DIR . '/database/' );
	} else {
		define( 'FQDBDIR', ABSPATH . 'wp-content/database/' );
	}
}

/**
 * FQDB is the absolute path to the SQLite database file.
 *
 * Defaults to DB_PATH, or FQDBDIR combined with DB_FILE. For managed storage,
 * the drop-in defines FQDB after resolving the randomized database path.
 * Must not be configured together with DB_PATH.
 *
 * @deprecated 3.0.0 Define DB_PATH instead of overriding FQDB.
 */
if ( ! defined( 'FQDB' ) ) {
	if ( defined( 'DB_PATH' ) ) {
		define( 'FQDB', DB_PATH );
	} elseif ( defined( 'DB_FILE' ) ) {
		define( 'FQDB', FQDBDIR . DB_FILE );
	}
}
