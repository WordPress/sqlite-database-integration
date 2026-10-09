<?php
/**
 * Location checks for the SQLite setup and Site Health screens.
 */

/**
 * Explain the risk of storing a database in a public directory.
 *
 * @access private
 * @return string Warning HTML without the database path.
 */
function sqlite_plugin_get_database_location_warning() {
	return __( 'The SQLite database location may be publicly accessible. Use a private location that your web server does not expose, or ask your hosting provider for help.', 'sqlite-database-integration' ) . "\n\n" . sprintf(
		/* translators: %s: URL of the database protection instructions. */
		__( '<a href="%s">Learn how to protect your database</a>', 'sqlite-database-integration' ),
		esc_url( add_query_arg( 'tab', 'protection', sqlite_plugin_get_admin_page_url() ) . '#sqlite-database-protection' )
	);
}

/**
 * Check the active or planned database location against known public directories.
 *
 * An "outside" result does not prove the location is private: server aliases and
 * other public directories may be unknown to WordPress. An "unknown" result means
 * that the drop-in rejects the configured location. No storage is initialized.
 *
 * @access private
 * @return string Location status: public, outside, memory, or unknown.
 */
function sqlite_plugin_get_database_location_status() {
	// The drop-in loads the storage only after SQLite is set up.
	if ( ! class_exists( 'WP_SQLite_Storage', false ) ) {
		require_once __DIR__ . '/wp-includes/sqlite/class-wp-sqlite-storage.php';
	}

	list( $path, $is_secret_root ) = sqlite_plugin_get_database_location();
	if ( ! sqlite_plugin_is_valid_database_location( $path, $is_secret_root ) ) {
		return 'unknown';
	}
	if ( ':memory:' === $path ) {
		return 'memory';
	}

	$paths       = sqlite_plugin_get_storage_path_variants( $path );
	$directories = array();
	foreach ( sqlite_plugin_get_public_roots() as $root ) {
		// A root is a directory, so a bare UNC share is valid with a trailing slash.
		if ( is_string( $root ) && '' !== $root ) {
			$root = rtrim( $root, '/\\' ) . '/';
		}
		$directories = array_merge( $directories, sqlite_plugin_get_storage_path_variants( $root ) );
	}
	$directory_ids = array();
	foreach ( $directories as $directory ) {
		foreach ( $paths as $candidate ) {
			if ( $candidate === $directory || 0 === strpos( $candidate, rtrim( $directory, '/' ) . '/' ) ) {
				return 'public';
			}
		}
		$stat = @stat( $directory ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Do not disclose filesystem paths.
		// Some platforms do not provide usable inode numbers.
		if ( false !== $stat && ! empty( $stat['ino'] ) ) {
			$directory_ids[] = $stat['dev'] . ':' . $stat['ino'];
		}
	}

	// realpath() may preserve letter case on case-insensitive filesystems.
	foreach ( $paths as $candidate ) {
		for ( $ancestor = $candidate; ; $ancestor = dirname( $ancestor ) ) {
			$stat = @stat( $ancestor ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Do not disclose the database path.
			if ( false !== $stat && in_array( $stat['dev'] . ':' . $stat['ino'], $directory_ids, true ) ) {
				return 'public';
			}
			if ( dirname( $ancestor ) === $ancestor ) {
				break;
			}
		}
	}
	return 'outside';
}

/**
 * Read the active path, or the planned legacy/default location before setup.
 *
 * @access private
 * @return mixed Configured path, which may be invalid.
 */
function sqlite_plugin_get_database_path() {
	list( $path ) = sqlite_plugin_get_database_location();
	return $path;
}

/**
 * Read the active location, or the planned legacy/default location before setup.
 *
 * Like the drop-in, use DB_PATH, then FQDB, then a secret path in FQDBDIR.
 * Before setup, load constants.php to define the deprecated constants. Never
 * initialize storage or load migration metadata to display this page.
 *
 * @access private
 * @return array{0: mixed, 1: bool} Configured path, which may be invalid, and
 *                                  whether it is the directory of a secret path.
 */
function sqlite_plugin_get_database_location() {
	try {
		// The drop-in loads the constants only after SQLite is set up.
		require_once __DIR__ . '/constants.php';
	} catch ( Throwable $exception ) {
		// The drop-in rejects these settings as well.
		return array( null, false );
	}

	if ( defined( 'DB_PATH' ) ) {
		return array( DB_PATH, false );
	}
	if ( defined( 'FQDB' ) ) {
		return array( FQDB, false );
	}
	// FQDBDIR is missing when constants.php failed in an earlier call.
	return array( defined( 'FQDBDIR' ) ? FQDBDIR : null, true );
}

/**
 * Check whether the drop-in accepts a database location.
 *
 * @access private
 * @param mixed $path           Configured path.
 * @param bool  $is_secret_root Whether the path is the directory of a secret path.
 * @return bool Whether the drop-in can use the location.
 */
function sqlite_plugin_is_valid_database_location( $path, $is_secret_root ) {
	if ( ! is_string( $path ) ) {
		return false;
	}
	try {
		if ( $is_secret_root ) {
			WP_SQLite_Storage::with_secret_path( $path );
		} else {
			WP_SQLite_Storage::with_explicit_path( $path );
		}
	} catch ( RuntimeException $exception ) {
		return false;
	}
	return true;
}

/**
 * List the directories that WordPress or the web server may serve publicly.
 *
 * @access private
 * @return mixed[] Directory paths, which may be invalid.
 */
function sqlite_plugin_get_public_roots() {
	$uploads = wp_upload_dir( null, false );
	$roots   = array(
		WP_CONTENT_DIR,
		ABSPATH,
		sqlite_plugin_get_home_directory(),
		WP_PLUGIN_DIR,
		WPMU_PLUGIN_DIR,
		$uploads['basedir'],
	);
	// Apache sets CONTEXT_DOCUMENT_ROOT for aliases and user directories.
	foreach ( array( 'DOCUMENT_ROOT', 'CONTEXT_DOCUMENT_ROOT' ) as $name ) {
		if ( isset( $_SERVER[ $name ] ) ) {
			$roots[] = wp_unslash( $_SERVER[ $name ] );
		}
	}
	return $roots;
}

/**
 * Find the site root when WordPress is installed in its own subdirectory.
 *
 * Unlike get_home_path(), match the subdirectory against ABSPATH rather than the
 * running script, and never fall back to the filesystem root.
 *
 * @access private
 * @return string|null Site root, or null when WordPress is not in its subdirectory.
 */
function sqlite_plugin_get_home_directory() {
	$home = preg_replace( '~^\w+://~', '', untrailingslashit( (string) get_option( 'home' ) ) );
	$site = preg_replace( '~^\w+://~', '', untrailingslashit( (string) get_option( 'siteurl' ) ) );
	if ( 0 !== stripos( $site, $home . '/' ) ) {
		return null;
	}

	// Remove the WordPress subdirectory, such as "/wp/", from the end of ABSPATH.
	$subdirectory = rawurldecode( substr( $site, strlen( $home ) ) ) . '/';
	$abspath      = str_replace( '\\', '/', ABSPATH );
	$length       = strlen( $abspath ) - strlen( $subdirectory );
	if ( $length < 1 || 0 !== substr_compare( $abspath, $subdirectory, $length, strlen( $subdirectory ), true ) ) {
		return null;
	}
	return substr( $abspath, 0, $length );
}

/**
 * Measure the database and its SQLite sidecars without opening the database.
 *
 * @access private
 * @param string $path Database file path, or ":memory:".
 * @return int|null Total bytes, or null when unavailable.
 */
function sqlite_plugin_get_database_size( $path ) {
	// Filesystem warnings must not disclose the randomized database path.
	$size = ':memory:' === $path ? false : @filesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	if ( false === $size ) {
		return null;
	}

	// SQLite resolves database symlinks on Unix, but not on Windows.
	if ( '\\' !== DIRECTORY_SEPARATOR ) {
		$path = @realpath( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $path ) {
			return null;
		}
	}
	foreach ( array( '-wal', '-shm', '-journal' ) as $suffix ) {
		$size += (int) @filesize( $path . $suffix ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	}
	return $size;
}

/**
 * Keep both the configured path and its filesystem target for location checks.
 *
 * The configured path may be public even when a symlink points outside the root.
 * Resolve the nearest existing ancestor to also check locations before setup.
 *
 * @access private
 * @param mixed $path Filesystem path.
 * @return string[] Normalized absolute paths, or an empty array for other values.
 */
function sqlite_plugin_get_storage_path_variants( $path ) {
	// PHP filesystem functions reject NUL bytes.
	if ( ! is_string( $path ) || false !== strpos( $path, "\0" ) ) {
		return array();
	}
	$normalized = sqlite_plugin_normalize_storage_path( $path );
	if ( null === $normalized ) {
		return array();
	}
	if ( '\\' === DIRECTORY_SEPARATOR ) {
		$path = str_replace( '\\', '/', $path );
	}

	$paths  = array( $normalized );
	$suffix = '';
	for ( $ancestor = $path; ; $ancestor = dirname( $ancestor ) ) {
		// Filesystem warnings can disclose the randomized database path.
		$resolved = @realpath( $ancestor ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false !== $resolved ) {
			$paths[] = sqlite_plugin_normalize_storage_path( $resolved . $suffix );
			break;
		}
		if ( dirname( $ancestor ) === $ancestor ) {
			break;
		}
		$suffix = '/' . basename( $ancestor ) . $suffix;
	}

	return array_unique( $paths );
}

/**
 * Normalize separators and dot segments for directory comparisons.
 *
 * @access private
 * @param string $path Filesystem path.
 * @return string|null Normalized path, or null when the path is not absolute.
 */
function sqlite_plugin_normalize_storage_path( $path ) {
	if ( '\\' === DIRECTORY_SEPARATOR ) {
		// Require a drive letter or UNC share. realpath() omits the slash after a bare share.
		$path = strtolower( str_replace( '\\', '/', $path ) );
		if ( ! preg_match( '~^(?:[a-z]:/|//[^/]+/[^/]+(?:/|$))~', $path, $matches ) ) {
			return null;
		}
		$prefix = rtrim( $matches[0], '/' ) . '/';
		$path   = substr( $path, strlen( $matches[0] ) );
	} elseif ( 0 === strpos( $path, '/' ) ) {
		$prefix = '/';
	} else {
		return null;
	}
	$parts = array();
	foreach ( explode( '/', $path ) as $part ) {
		if ( '' === $part || '.' === $part ) {
			continue;
		}
		if ( '..' === $part ) {
			array_pop( $parts );
		} else {
			$parts[] = $part;
		}
	}
	return $prefix . implode( '/', $parts );
}
