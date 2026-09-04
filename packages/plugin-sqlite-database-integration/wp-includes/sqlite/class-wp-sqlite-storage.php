<?php

/**
 * Manages locking for the WordPress SQLite database storage.
 *
 * Locking uses a dedicated empty SQLite database and a maintenance file:
 *   1. An exclusive lock is acquired on the locking database at ".ht.sqlite.lock".
 *   2. A maintenance marker file is created at ".ht.sqlite.maintenance":
 *       - When requests see the file, they use the locking database.
 *       - Its absence provides a fast path for normal traffic.
 *   3. When the lock is released, the maintenance file is automatically removed.
 *
 * Database locking uses a PDO SQLite connection:
 * phpcs:disable WordPress.DB.RestrictedClasses.mysql__PDO
 *
 * Filesystem warnings are suppressed to avoid exposing database paths:
 * phpcs:disable WordPress.PHP.NoSilencedErrors.Discouraged
 */
class WP_SQLite_Storage {
	/**
	 * Filename of the SQLite locking database.
	 */
	private const LOCK_FILENAME = '.ht.sqlite.lock';

	/**
	 * Filename of the marker that makes requests check the storage lock.
	 */
	private const MAINTENANCE_FILENAME = '.ht.sqlite.maintenance';

	/**
	 * Managed database root directory.
	 *
	 * @var string
	 */
	private $database_root;

	/**
	 * Absolute path of the SQLite locking database.
	 *
	 * @var string
	 */
	private $lock_path;

	/**
	 * Absolute path of the storage maintenance marker.
	 *
	 * @var string
	 */
	private $maintenance_path;

	/**
	 * Connection holding the exclusive transaction on the SQLite locking database.
	 *
	 * @var PDO|null
	 */
	private $storage_lock_connection;

	/**
	 * Time to wait for the SQLite storage lock, in milliseconds.
	 *
	 * @var int
	 */
	private $database_lock_timeout = 10000;

	/**
	 * Create a SQLite storage lock.
	 *
	 * @param string|null $database_root Managed database root. Defaults to FQDBDIR.
	 */
	public function __construct( ?string $database_root = null ) {
		$this->database_root    = trailingslashit( $database_root ?? FQDBDIR );
		$this->lock_path        = $this->database_root . self::LOCK_FILENAME;
		$this->maintenance_path = $this->database_root . self::MAINTENANCE_FILENAME;
	}

	/**
	 * Lock the database storage for maintenance.
	 *
	 * The locking mechanism does the following:
	 *   - Waits for a lock held by another process, up to the database lock timeout.
	 *   - Marks maintenance as active so new requests wait.
	 *
	 * The lock is released when unlock() is called or the process ends.
	 *
	 * @throws RuntimeException When the lock cannot be acquired.
	 */
	public function lock(): void {
		// Reuse a lock already held by this instance.
		if ( null !== $this->storage_lock_connection ) {
			return;
		}

		// Serialize maintenance through the dedicated locking database.
		try {
			$this->ensure_database( $this->lock_path );
			$connection = new PDO( 'sqlite:' . $this->lock_path );
			$connection->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
			$connection->exec( 'PRAGMA busy_timeout = ' . $this->database_lock_timeout );
			$connection->exec( 'BEGIN EXCLUSIVE' );
			$this->storage_lock_connection = $connection;
		} catch ( Throwable $exception ) {
			throw new RuntimeException( 'Failed to acquire the SQLite storage lock.', 0, $exception );
		}

		try {
			// Ensure the maintenance marker exists so new requests use the locking database.
			if ( ! @is_file( $this->maintenance_path ) ) {
				if ( false === @file_put_contents( $this->maintenance_path, '' ) ) {
					throw new RuntimeException( 'Failed to create the SQLite storage maintenance marker.' );
				}
				@chmod( $this->maintenance_path, 0600 );
			}
		} catch ( Throwable $exception ) {
			$this->unlock();
			throw $exception;
		}
	}

	/**
	 * Unlock the storage.
	 */
	public function unlock(): void {
		if ( null !== $this->storage_lock_connection ) {
			// Remove the marker before releasing the lock that protects it.
			@unlink( $this->maintenance_path );
			$this->storage_lock_connection = null;
		}
	}

	/**
	 * Ensure that a database and its protected directory exist.
	 *
	 * @param string $database_path Absolute database path.
	 */
	private function ensure_database( string $database_path ): void {
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
	 * Ensure that a database directory exists and deny direct access.
	 *
	 * @param string $directory Absolute directory path.
	 */
	private function ensure_protected_directory( string $directory ): void {
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
	private function ensure_file( string $path, string $contents ): void {
		if ( @is_file( $path ) ) {
			return;
		}
		if ( false === @file_put_contents( $path, $contents ) ) {
			throw new RuntimeException( 'Failed to create SQLite database protection file.' );
		}
		@chmod( $path, 0600 );
	}
}
