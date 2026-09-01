<?php

/**
 * Manages the storage layout for the WordPress SQLite database.
 *
 * Legacy database migration uses a PDO SQLite connection:
 * phpcs:disable WordPress.DB.RestrictedClasses.mysql__PDO
 *
 * Filesystem warnings are suppressed to avoid exposing database paths:
 * phpcs:disable WordPress.PHP.NoSilencedErrors.Discouraged
 */
class WP_SQLite_Storage {
	/**
	 * Filename of a script that returns the path to the database file.
	 */
	private const DATABASE_PATH_FILENAME = 'db-path.php';

	/**
	 * Filename of the database file.
	 */
	private const DATABASE_FILENAME = '.ht.sqlite';

	/**
	 * Filename of a lock file used for database storage setup.
	 */
	private const LOCK_FILENAME = '.ht.sqlite.lock';

	/**
	 * Number of bytes of a random token used in generated database paths.
	 */
	private const RANDOM_TOKEN_BYTE_LENGTH = 16;

	/**
	 * Managed database root directory.
	 *
	 * @var string
	 */
	private $database_root;

	/**
	 * Database busy timeout during legacy migration, in milliseconds.
	 *
	 * @var int
	 */
	private $migration_timeout = 10000;

	/**
	 * Create a SQLite storage manager.
	 *
	 * @param string|null $database_root Managed database root. Defaults to FQDBDIR.
	 */
	public function __construct( ?string $database_root = null ) {
		$this->database_root = trailingslashit( $database_root ?? FQDBDIR );
	}

	/**
	 * Initialize the SQLite database storage.
	 *
	 * Uses an explicit file path or ":memory:" as provided. Otherwise, initializes
	 * managed storage with a randomized path and migrates legacy storage as needed.
	 *
	 * @param string|null $database_path Optional explicit database path.
	 * @return string Absolute path to the SQLite database file, or ":memory:".
	 */
	public function initialize( ?string $database_path = null ): string {
		if ( '' === $database_path ) {
			throw new RuntimeException( 'The SQLite database path is invalid.' );
		}

		if ( ':memory:' === $database_path ) {
			return $database_path;
		}

		// Explicitly provided database path.
		if ( null !== $database_path ) {
			$this->ensure_database( $database_path );
			return $database_path;
		}

		// Reuse an initialized managed database without modifying its storage.
		$db_path_file = $this->database_root . self::DATABASE_PATH_FILENAME;
		if ( @is_file( $db_path_file ) ) {
			$database_path = $this->read_database_path( $db_path_file );
			if ( @is_file( $database_path ) ) {
				return $database_path;
			}
		}

		// Initialize or repair managed storage with an advisory lock when available.
		$this->ensure_protected_directory( $this->database_root );
		$lock = $this->acquire_lock();
		if ( false === $lock ) {
			// Give a concurrent process time to finish initialization.
			sleep( 3 );

			// Reuse its database only after both the path file and database exist.
			clearstatcache( true, $db_path_file );
			if ( @is_file( $db_path_file ) ) {
				$database_path = $this->read_database_path( $db_path_file );
				clearstatcache( true, $database_path );
				if ( @is_file( $database_path ) ) {
					return $database_path;
				}
			}

			// Continue without a lock when advisory locking is unavailable.
		}
		try {
			// Publish a new database path.
			if ( ! @is_file( $db_path_file ) ) {
				$this->publish_database_path();
			}
			$database_path = $this->read_database_path( $db_path_file );

			// Migrate from legacy ".ht.sqlite" and ".ht.sqlite.php" paths.
			$legacy_path = $this->database_root . self::DATABASE_FILENAME;
			if ( ! @is_file( $legacy_path ) ) {
				$legacy_path .= '.php';
			}
			if ( ! @is_file( $database_path ) && @is_file( $legacy_path ) ) {
				$this->migrate_legacy_database( $legacy_path, $database_path );
				return $database_path;
			}

			// Initialize a new database.
			$this->ensure_database( $database_path );
			return $database_path;
		} finally {
			if ( false !== $lock ) {
				fclose( $lock );
			}
		}
	}

	/**
	 * Ensure that a database and its protected directory exist.
	 *
	 * @param string $database_path Absolute database path.
	 */
	private function ensure_database( string $database_path ) {
		$this->ensure_protected_directory( dirname( $database_path ) );

		if ( ! @is_file( $database_path ) ) {
			// Create an empty database file with restricted permissions.
			$database_handle = @fopen( $database_path, 'c' );
			if ( false === $database_handle ) {
				throw new RuntimeException( 'Failed to create the SQLite database file.' );
			}
			fclose( $database_handle );
			@chmod( $database_path, 0600 );
		}
	}

	/**
	 * Move the legacy database to the intended database path.
	 *
	 * @param string $legacy_path   Absolute legacy database path.
	 * @param string $database_path Absolute intended database path.
	 */
	private function migrate_legacy_database( string $legacy_path, string $database_path ) {
		// Disable WAL so only the main database file needs to be moved.
		try {
			$this->ensure_protected_directory( dirname( $database_path ) );

			$connection = new PDO( 'sqlite:' . $legacy_path );
			$connection->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
			$connection->exec( 'PRAGMA busy_timeout = ' . $this->migration_timeout );

			$deadline = microtime( true ) + ( $this->migration_timeout / 1000 );
			do {
				try {
					$journal_mode = $connection->query( 'PRAGMA journal_mode = DELETE' )->fetchColumn();
					if ( 'delete' === strtolower( (string) $journal_mode ) ) {
						break;
					}
				} catch ( PDOException $exception ) {
					$error_info        = $connection->errorInfo();
					$sqlite_error_code = isset( $error_info[1] ) ? (int) $error_info[1] : 0;
					$sqlite_busy       = 5;
					if ( ( $sqlite_error_code & 0xff ) !== $sqlite_busy ) {
						throw $exception;
					}
				}

				if ( microtime( true ) >= $deadline ) {
					throw new RuntimeException( 'Failed to disable WAL before migrating the SQLite database.' );
				}
				usleep( 100 * 1000 ); // Wait 100 milliseconds.
			} while ( true );

			// Block other reads and writes before moving the database.
			$connection->exec( 'BEGIN EXCLUSIVE' );
		} catch ( Throwable $exception ) {
			throw new RuntimeException( 'Failed to prepare the SQLite database for migration.', 0, $exception );
		}

		/*
		 * Close the connection just before moving the database. SQLite considers
		 * renaming an open database undefined, and Windows generally prevents it.
		 * We cannot fully prevent race conditions, but this makes them unlikely.
		 *
		 * See: https://www.sqlite.org/howtocorrupt.html#unlink
		*/
		$connection = null;

		// Move only the main database file. WAL was disabled and an exclusive
		// lock was acquired, so no valid sidecar files are expected.
		if ( ! @rename( $legacy_path, $database_path ) ) {
			throw new RuntimeException( 'Failed to move the SQLite database file.' );
		}
		@chmod( $database_path, 0600 );
	}

	/**
	 * Read and validate the database path file.
	 *
	 * @param string $database_path_file Absolute database path file.
	 * @return string Absolute path to the SQLite database file.
	 */
	private function read_database_path( string $database_path_file ): string {
		try {
			// Use include so an unreadable file can be handled without terminating on PHP 7.
			$database_path = @include $database_path_file;
		} catch ( Throwable $exception ) {
			throw new RuntimeException( 'Failed to read the SQLite database path file.', 0, $exception );
		}

		if ( false === $database_path ) {
			throw new RuntimeException( 'Failed to read the SQLite database path file.' );
		}

		if ( ! is_string( $database_path ) || '' === $database_path ) {
			throw new RuntimeException( 'The SQLite database path file is invalid.' );
		}

		return $database_path;
	}

	/**
	 * Generate a randomized database path and publish it atomically.
	 */
	private function publish_database_path() {
		$directory_name = '.ht.' . bin2hex( random_bytes( self::RANDOM_TOKEN_BYTE_LENGTH ) );
		$directory_path = $this->database_root . $directory_name;

		if ( @file_exists( $directory_path ) ) {
			throw new RuntimeException( 'Failed to generate a unique SQLite database path.' );
		}

		$database_path_file     = $this->database_root . self::DATABASE_PATH_FILENAME;
		$database_path_contents = sprintf(
			"<?php\n\n/**\n * SQLite database path.\n *\n * IMPORTANT: Keep this path secret. When possible, point it outside the document root.\n */\nreturn __DIR__ . '/%s/%s';\n",
			$directory_name,
			self::DATABASE_FILENAME
		);
		$temporary_path         = $this->database_root . '.ht.' . self::DATABASE_PATH_FILENAME;

		if ( false === @file_put_contents( $temporary_path, $database_path_contents ) ) {
			throw new RuntimeException( 'Failed to write the SQLite database path file.' );
		}
		@chmod( $temporary_path, 0600 );

		if ( ! @rename( $temporary_path, $database_path_file ) ) {
			@unlink( $temporary_path );
			throw new RuntimeException( 'Failed to publish the SQLite database path file.' );
		}

		// This runs before wp_opcache_invalidate() is available.
		$opcache_restrict_api = ini_get( 'opcache.restrict_api' );
		$script_filename      = isset( $_SERVER['SCRIPT_FILENAME'] ) ? realpath( $_SERVER['SCRIPT_FILENAME'] ) : false;
		if (
			function_exists( 'opcache_invalidate' )
			&& ( ! $opcache_restrict_api || ( $script_filename && 0 === stripos( $script_filename, $opcache_restrict_api ) ) )
		) {
			opcache_invalidate( $database_path_file, true );
		}
	}

	/**
	 * Ensure that a database directory exists and deny direct access.
	 *
	 * @param string $directory Absolute directory path.
	 */
	private function ensure_protected_directory( string $directory ) {
		if ( ! @is_dir( $directory ) ) {
			// Create the path one directory at a time to avoid changing the process-wide umask.
			$missing_directories = array();
			for ( $path = untrailingslashit( $directory ); ! @is_dir( $path ); $path = dirname( $path ) ) {
				$missing_directories[] = $path;
				if ( dirname( $path ) === $path ) {
					break;
				}
			}

			foreach ( array_reverse( $missing_directories ) as $path ) {
				if ( ! @mkdir( $path, 0700 ) && ! @is_dir( $path ) ) {
					throw new RuntimeException( 'Failed to create the SQLite database directory.' );
				}
				@chmod( $path, 0700 );
			}
		}

		$this->ensure_file( trailingslashit( $directory ) . '.htaccess', 'DENY FROM ALL' );
		$this->ensure_file( trailingslashit( $directory ) . 'index.php', '<?php // Silence is golden.' );
	}

	/**
	 * Create a protected file when it does not already exist.
	 *
	 * @param string $path     Absolute file path.
	 * @param string $contents File contents.
	 */
	private function ensure_file( string $path, string $contents ) {
		if ( @is_file( $path ) ) {
			return;
		}

		if ( false === @file_put_contents( $path, $contents ) ) {
			throw new RuntimeException( 'Failed to create SQLite database protection file.' );
		}
		@chmod( $path, 0600 );
	}

	/**
	 * Open and acquire the database storage lock when available.
	 *
	 * @return resource|false Lock file handle, or false when locking is unavailable.
	 */
	private function acquire_lock() {
		$lock_path   = $this->database_root . self::LOCK_FILENAME;
		$lock_handle = @fopen( $lock_path, 'c' );
		if ( false === $lock_handle ) {
			return false;
		}
		@chmod( $lock_path, 0600 );

		if ( ! function_exists( 'flock' ) || ! @flock( $lock_handle, LOCK_EX ) ) {
			fclose( $lock_handle );
			return false;
		}

		return $lock_handle;
	}
}
