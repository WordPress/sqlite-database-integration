<?php
/**
 * Functions for the admin page of the plugin.
 */

/**
 * Add an admin menu page.
 */
function sqlite_add_admin_menu() {
	$parent_slug = is_multisite() ? 'settings.php' : 'options-general.php';

	add_submenu_page(
		$parent_slug,
		__( 'SQLite integration', 'sqlite-database-integration' ),
		__( 'SQLite integration', 'sqlite-database-integration' ),
		sqlite_plugin_get_manage_capability(),
		'sqlite-integration',
		'sqlite_integration_admin_screen'
	);
}
add_action( is_multisite() ? 'network_admin_menu' : 'admin_menu', 'sqlite_add_admin_menu' );

/**
 * Enqueue assets only on the SQLite settings screen.
 *
 * @access private
 * @param string $hook Current admin screen hook.
 */
function sqlite_plugin_enqueue_admin_assets( $hook ) {
	if ( 'settings_page_sqlite-integration' !== $hook ) {
		return;
	}
	wp_enqueue_style( 'sqlite-integration-admin', plugins_url( 'admin-page.css', SQLITE_MAIN_FILE ), array(), SQLITE_DRIVER_VERSION );
	wp_enqueue_script( 'sqlite-integration-admin', plugins_url( 'admin-page.js', SQLITE_MAIN_FILE ), array( 'clipboard' ), SQLITE_DRIVER_VERSION, true );
}
add_action( 'admin_enqueue_scripts', 'sqlite_plugin_enqueue_admin_assets' );

/**
 * The admin page contents.
 */
function sqlite_integration_admin_screen() {
	if ( ! current_user_can( sqlite_plugin_get_manage_capability() ) ) {
		wp_die(
			esc_html__( 'Sorry, you are not allowed to access the SQLite integration settings.', 'sqlite-database-integration' ),
			403
		);
	}

	// This read-only navigation does not change any settings.
	$tab    = isset( $_GET['tab'] ) && is_string( $_GET['tab'] ) && 'protection' === $_GET['tab'] ? 'protection' : 'overview'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$active = defined( 'SQLITE_DB_DROPIN_VERSION' );
	$status = sqlite_plugin_get_database_location_status();
	$url    = sqlite_plugin_get_admin_page_url();
	?>
	<div class="wrap sqlite-integration">
		<header class="sqlite-header">
			<h1><?php esc_html_e( 'SQLite integration', 'sqlite-database-integration' ); ?></h1>
			<p class="sqlite-intro"><?php esc_html_e( 'Manage how this site uses SQLite.', 'sqlite-database-integration' ); ?></p>
			<nav class="sqlite-tabs" aria-label="<?php esc_attr_e( 'SQLite settings', 'sqlite-database-integration' ); ?>">
				<a class="sqlite-tab" href="<?php echo esc_url( $url ); ?>" <?php echo 'overview' === $tab ? 'aria-current="page"' : ''; ?>><?php esc_html_e( 'Overview', 'sqlite-database-integration' ); ?></a>
				<a class="sqlite-tab" href="<?php echo esc_url( add_query_arg( 'tab', 'protection', $url ) ); ?>" <?php echo 'protection' === $tab ? 'aria-current="page"' : ''; ?>>
					<?php esc_html_e( 'Database protection', 'sqlite-database-integration' ); ?>
					<?php if ( 'public' === $status || 'unknown' === $status ) : ?>
						<span class="dashicons dashicons-warning sqlite-warning" aria-hidden="true"></span><span class="screen-reader-text"> — <?php esc_html_e( 'Needs attention', 'sqlite-database-integration' ); ?></span>
					<?php endif; ?>
				</a>
			</nav>
		</header>
		<hr class="wp-header-end">
		<div class="sqlite-content">
			<?php if ( 'protection' === $tab ) : ?>
				<?php sqlite_plugin_render_protection_status( $status ); ?>
				<?php sqlite_plugin_render_protection_instructions(); ?>
			<?php else : ?>
				<?php $location = sqlite_plugin_get_database_location_details( $status ); ?>
				<section class="sqlite-box" aria-labelledby="sqlite-status-heading">
					<h2 id="sqlite-status-heading"><?php esc_html_e( 'Database status', 'sqlite-database-integration' ); ?></h2>
					<dl class="sqlite-data">
						<div><dt><?php esc_html_e( 'Integration', 'sqlite-database-integration' ); ?></dt><dd class="sqlite-status">
							<?php if ( $active ) : ?>
								<span class="sqlite-status-dot" aria-hidden="true"></span><?php esc_html_e( 'SQLite is active', 'sqlite-database-integration' ); ?>
							<?php else : ?>
								<?php esc_html_e( 'SQLite is not active', 'sqlite-database-integration' ); ?>
							<?php endif; ?>
						</dd></div>
						<?php if ( $active ) : ?>
							<?php sqlite_plugin_render_database_details( sqlite_plugin_get_database_path() ); ?>
						<?php endif; ?>
						<div><dt><?php esc_html_e( 'Protection', 'sqlite-database-integration' ); ?></dt><dd>
							<p class="sqlite-status"><span class="dashicons <?php echo esc_attr( $location['icon'] ); ?>" aria-hidden="true"></span><strong><?php echo esc_html( $location['label'] ); ?></strong></p>
							<p><?php echo esc_html( $location['summary'] ); ?></p>
							<p><a href="<?php echo esc_url( add_query_arg( 'tab', 'protection', $url ) ); ?>"><?php esc_html_e( 'View protection status', 'sqlite-database-integration' ); ?></a></p>
						</dd></div>
					</dl>
				</section>
				<?php if ( $active ) : ?>
					<section class="sqlite-manage">
						<h2><?php esc_html_e( 'Manage the integration', 'sqlite-database-integration' ); ?></h2>
						<p><?php esc_html_e( 'Switching to MySQL does not copy your SQLite content.', 'sqlite-database-integration' ); ?></p>
						<details class="sqlite-help"><summary><?php esc_html_e( 'How to switch to MySQL', 'sqlite-database-integration' ); ?></summary><div>
							<p><?php esc_html_e( 'Back up your database. Prepare your MySQL database and configuration, and migrate any content you want to keep before switching.', 'sqlite-database-integration' ); ?></p>
							<p>
							<?php
							printf(
								/* translators: 1: Plugins page URL, 2: Drop-in file path relative to the content directory. */
								wp_kses_post( __( 'When ready, <a href="%1$s">deactivate the plugin</a> to remove the SQLite drop-in, or manually remove %2$s. Verify that your content loads correctly from MySQL.', 'sqlite-database-integration' ) ),
								esc_url( self_admin_url( 'plugins.php' ) ),
								'<code>' . esc_html( basename( WP_CONTENT_DIR ) . '/db.php' ) . '</code>'
							);
							?>
							</p>
							<?php if ( is_multisite() ) : ?>
								<p><?php esc_html_e( 'This change affects every site in the network.', 'sqlite-database-integration' ); ?></p>
							<?php endif; ?>
						</div></details>
					</section>
				<?php else : ?>
					<?php sqlite_plugin_render_installation(); ?>
				<?php endif; ?>
			<?php endif; ?>
		</div>
	</div>
	<?php
}

/**
 * Render the location-only protection result.
 *
 * @access private
 * @param string $status Location status.
 */
function sqlite_plugin_render_protection_status( $status ) {
	$location = sqlite_plugin_get_database_location_details( $status );
	?>
	<section class="sqlite-box" aria-labelledby="sqlite-protection-heading">
		<h2 id="sqlite-protection-heading"><?php esc_html_e( 'Protection status', 'sqlite-database-integration' ); ?></h2>
		<dl class="sqlite-data"><div><dt><?php esc_html_e( 'Database location', 'sqlite-database-integration' ); ?></dt><dd>
			<p class="sqlite-status"><span class="dashicons <?php echo esc_attr( $location['icon'] ); ?>" aria-hidden="true"></span><strong><?php echo esc_html( $location['label'] ); ?></strong></p>
			<?php echo wp_kses_post( wpautop( $location['description'] ) ); ?>
		</dd></div></dl>
		<?php if ( 'memory' !== $status ) : ?>
			<p class="sqlite-box-footer"><?php esc_html_e( 'This check is based on the database location. Public access has not been tested.', 'sqlite-database-integration' ); ?></p>
		<?php endif; ?>
	</section>
	<?php
}

/**
 * Describe the location result without claiming HTTP access was checked.
 *
 * @access private
 * @param string $status Location status.
 * @return string[] Icon classes and display text.
 */
function sqlite_plugin_get_database_location_details( $status ) {
	if ( 'public' === $status ) {
		return array(
			'icon'        => 'dashicons-warning sqlite-warning',
			'label'       => __( 'Review database location', 'sqlite-database-integration' ),
			'summary'     => __( 'The database location may be publicly accessible.', 'sqlite-database-integration' ),
			'description' => sqlite_plugin_get_database_location_warning(),
		);
	}
	if ( 'outside' === $status ) {
		return array(
			'icon'        => 'dashicons-yes-alt sqlite-success',
			'label'       => __( 'Outside known public roots', 'sqlite-database-integration' ),
			'summary'     => __( 'Public access has not been tested.', 'sqlite-database-integration' ),
			'description' => __( 'The database is outside the WordPress directory, content directory, and other public roots identified by this check. Other websites, aliases, or server rules may still expose this folder.', 'sqlite-database-integration' ),
		);
	}
	if ( 'memory' === $status ) {
		return array(
			'icon'        => 'dashicons-info-outline',
			'label'       => __( 'In-memory database', 'sqlite-database-integration' ),
			'summary'     => __( 'There is no database file to expose.', 'sqlite-database-integration' ),
			'description' => __( 'SQLite stores this database in memory, so there is no database file to expose.', 'sqlite-database-integration' ),
		);
	}
	return array(
		'icon'        => 'dashicons-warning sqlite-warning',
		'label'       => __( 'Database location unavailable', 'sqlite-database-integration' ),
		'summary'     => __( 'The database location could not be checked.', 'sqlite-database-integration' ),
		'description' => __( 'We could not determine an absolute database path to check. Ask your hosting provider to review its location.', 'sqlite-database-integration' ),
	);
}

/**
 * Display the active database size and an initially hidden filesystem path.
 *
 * @access private
 * @param string $path Active database path, or ":memory:".
 */
function sqlite_plugin_render_database_details( $path ) {
	$size = sqlite_plugin_get_database_size( $path );
	?>
	<div><dt><?php esc_html_e( 'Database size', 'sqlite-database-integration' ); ?></dt><dd>
		<?php echo null === $size ? esc_html__( 'Not available', 'sqlite-database-integration' ) : esc_html( size_format( $size, 1 ) ); ?>
		<?php if ( null !== $size ) : ?>
			<p class="description"><?php esc_html_e( 'Includes the database and its supporting files.', 'sqlite-database-integration' ); ?></p>
		<?php endif; ?>
	</dd></div>
	<div><dt><?php esc_html_e( 'Database location', 'sqlite-database-integration' ); ?></dt><dd>
		<?php if ( ':memory:' === $path ) : ?>
			<?php esc_html_e( 'In memory', 'sqlite-database-integration' ); ?>
		<?php else : ?>
			<details class="sqlite-path">
				<summary><span class="sqlite-path-hidden"><?php esc_html_e( 'Hidden', 'sqlite-database-integration' ); ?></span> <span class="sqlite-show-path"><?php esc_html_e( 'Show path', 'sqlite-database-integration' ); ?></span><span class="sqlite-hide-path"><?php esc_html_e( 'Hide path', 'sqlite-database-integration' ); ?></span></summary>
				<pre tabindex="0" aria-label="<?php esc_attr_e( 'Database file path', 'sqlite-database-integration' ); ?>"><code id="sqlite-database-path"><?php echo esc_html( $path ); ?></code></pre>
				<button type="button" class="button sqlite-copy-path" data-clipboard-target="#sqlite-database-path" hidden><?php esc_html_e( 'Copy path', 'sqlite-database-integration' ); ?></button>
				<span class="sqlite-copy-status" role="status" data-success="<?php esc_attr_e( 'Path copied.', 'sqlite-database-integration' ); ?>" data-error="<?php esc_attr_e( 'Could not copy automatically. Select and copy the path above.', 'sqlite-database-integration' ); ?>"></span>
			</details>
		<?php endif; ?>
	</dd></div>
	<?php
}

/**
 * Explain how to protect new and existing database files.
 *
 * @access private
 */
function sqlite_plugin_render_protection_instructions() {
	?>
	<section class="sqlite-box">
		<h2 id="sqlite-database-protection"><?php esc_html_e( 'How to protect your database', 'sqlite-database-integration' ); ?></h2>
		<div class="sqlite-instructions">
			<h3><?php esc_html_e( 'Choose a private location', 'sqlite-database-integration' ); ?></h3>
			<p><?php echo wp_kses_post( __( 'Choose a <strong>private folder outside the public web root</strong>, or ask your hosting provider to help you find one. Make sure the folder is not exposed through another website, alias, or symbolic link.', 'sqlite-database-integration' ) ); ?></p>
			<p><?php esc_html_e( 'PHP must be able to read and write the database file and its folder.', 'sqlite-database-integration' ); ?></p>

			<h3><?php esc_html_e( 'For a new installation', 'sqlite-database-integration' ); ?></h3>
			<p>
				<?php
				printf(
					/* translators: 1: DB_PATH constant, 2: wp-config.php file. The quoted text refers to a comment in wp-config.php. */
					esc_html__( 'Before installing SQLite, add %1$s to %2$s above the “That\'s all, stop editing!” comment. Replace the path in the example below with the full path to a database file in your private folder.', 'sqlite-database-integration' ),
					'<code>DB_PATH</code>',
					'<code>wp-config.php</code>'
				);
				?>
			</p>
			<pre tabindex="0" aria-label="<?php esc_attr_e( 'SQLite configuration example', 'sqlite-database-integration' ); ?>"><code>define( 'DB_PATH', '/path/to/private/database.sqlite' );</code></pre>

			<h3><?php esc_html_e( 'For an existing SQLite site', 'sqlite-database-integration' ); ?></h3>
			<p>
				<?php
				printf(
					/* translators: %s: DB_PATH constant. */
					esc_html__( 'Changing %s does not move an existing database. Follow these steps, or ask your hosting provider for help. On multisite, the move affects every site in the network.', 'sqlite-database-integration' ),
					'<code>DB_PATH</code>'
				);
				?>
			</p>
			<ol>
				<li><?php esc_html_e( 'Stop all database access, including web requests, scheduled tasks, and command-line processes, and close all database connections.', 'sqlite-database-integration' ); ?></li>
				<li><?php esc_html_e( 'Back up the database, then move it together with any accompanying SQLite journal files. Keep backups in a private location.', 'sqlite-database-integration' ); ?></li>
				<li>
					<?php
					printf(
						/* translators: 1: DB_PATH constant, 2: wp-config.php file. */
						esc_html__( 'Set %1$s in %2$s to the full path of the moved database. Restart the site and check that your existing content loads correctly.', 'sqlite-database-integration' ),
						'<code>DB_PATH</code>',
						'<code>wp-config.php</code>'
					);
					?>
				</li>
			</ol>

			<h3><?php esc_html_e( 'If a private location is unavailable', 'sqlite-database-integration' ); ?></h3>
			<p><?php esc_html_e( 'Ask your hosting provider to block web access to the entire database folder, including journal files, storage metadata, and backups, and disable directory listings. The required rules depend on your web server. Ask them to verify that the folder is protected on every website that serves it.', 'sqlite-database-integration' ); ?></p>
		</div>
	</section>
	<?php
}

/**
 * Preserve the installation requirements and drop-in replacement flow.
 *
 * @access private
 */
function sqlite_plugin_render_installation() {
	$db_dropin_path = WP_CONTENT_DIR . '/db.php';

	/*
	 * When an existing "db.php" drop-in is detected, let's check if it's a known
	 * plugin that we can continue supporting even when we override the drop-in.
	 */
	$override_db_dropin = false;
	if ( file_exists( $db_dropin_path ) && ! defined( 'SQLITE_DB_DROPIN_VERSION' ) ) {
		// Check for the Query Monitor plugin.
		// When "QM_DB" exists, it must have been loaded via the "db.php" file.
		if ( class_exists( 'QM_DB', false ) ) {
			$override_db_dropin = true;
		}
	}

	?>
	<section class="sqlite-installation">
		<?php if ( ! extension_loaded( 'pdo_sqlite' ) ) : ?>
			<div class="notice notice-error inline">
				<p><?php esc_html_e( 'We detected that the PDO SQLite driver is missing from your server (the pdo_sqlite extension is not loaded). Please make sure that SQLite is enabled in your PHP installation before proceeding.', 'sqlite-database-integration' ); ?></p>
			</div>
		<?php elseif ( file_exists( $db_dropin_path ) && ! defined( 'SQLITE_DB_DROPIN_VERSION' ) && ! $override_db_dropin ) : ?>
			<div class="notice notice-error inline">
				<p>
					<?php
					printf(
						/* translators: %s: db.php drop-in path */
						esc_html__( 'SQLite cannot be set up because a different %s drop-in already exists.', 'sqlite-database-integration' ),
						'<code>' . esc_html( basename( WP_CONTENT_DIR ) ) . '/db.php</code>'
					);
					?>
				</p>
			</div>
		<?php elseif ( ! is_writable( WP_CONTENT_DIR ) ) : ?>
			<div class="notice notice-error inline">
				<p>
					<?php
					printf(
						/* translators: %s: db.php drop-in path */
						esc_html__( 'SQLite cannot be set up because the %s directory is not writable.', 'sqlite-database-integration' ),
						'<code>' . esc_html( basename( WP_CONTENT_DIR ) ) . '</code>'
					);
					?>
				</p>
			</div>
		<?php else : ?>
			<div class="notice notice-success inline">
				<p><?php esc_html_e( 'The required PHP extension and file permissions are available. Before installing, choose a database location that your web server does not expose.', 'sqlite-database-integration' ); ?></p>
			</div>
			<h2><?php esc_html_e( 'Important note', 'sqlite-database-integration' ); ?></h2>
			<p><?php esc_html_e( 'This plugin will switch to a separate database and install WordPress in it. You will need to reconfigure your site, and start with a fresh site. Disabling the plugin you will get back to your previous MySQL database, with all your previous data intact.', 'sqlite-database-integration' ); ?></p>

			<?php if ( $override_db_dropin ) : ?>
				<p>
					<strong>NOTE:</strong>
					<?php
					printf(
						/* translators: %s: db.php drop-in path */
						esc_html__( 'We’ve detected an existing database drop-in file at %s, created by the Query Monitor plugin.', 'sqlite-database-integration' ),
						'<code>' . esc_html( basename( WP_CONTENT_DIR ) ) . '/db.php</code>'
					);
					?>
					<?php esc_html_e( 'To enable SQLite support, this file will need to be replaced.', 'sqlite-database-integration' ); ?>
					<?php esc_html_e( 'The Query Monitor plugin will continue to function correctly after the change. You can safely proceed with the installation.', 'sqlite-database-integration' ); ?>
				</p>
			<?php endif; ?>

			<p><?php esc_html_e( 'By clicking the button below, you will be redirected to the WordPress installation screen to setup your new database', 'sqlite-database-integration' ); ?></p>

			<a class="button button-primary" href="<?php echo esc_url( wp_nonce_url( add_query_arg( 'confirm-install', '1', sqlite_plugin_get_admin_page_url() ), 'sqlite-install' ) ); ?>"><?php esc_html_e( 'Install SQLite database', 'sqlite-database-integration' ); ?></a>
		<?php endif; ?>
	</section>
	<?php
}

/**
 * Adds a link to the admin bar.
 *
 * @global wpdb $wpdb WordPress database abstraction object.
 *
 * @param WP_Admin_Bar $admin_bar The admin bar object.
 */
function sqlite_plugin_adminbar_item( $admin_bar ) {
	if ( ! current_user_can( sqlite_plugin_get_manage_capability() ) ) {
		return;
	}

	global $wpdb;

	if ( defined( 'SQLITE_DB_DROPIN_VERSION' ) && defined( 'DB_ENGINE' ) && 'sqlite' === DB_ENGINE ) {
		$title = '<span style="color:#46B450;">' . __( 'Database: SQLite', 'sqlite-database-integration' ) . '</span>';
	} elseif ( stripos( $wpdb->db_server_info(), 'maria' ) !== false ) {
		$title = '<span style="color:#DC3232;">' . __( 'Database: MariaDB', 'sqlite-database-integration' ) . '</span>';
	} else {
		$title = '<span style="color:#DC3232;">' . __( 'Database: MySQL', 'sqlite-database-integration' ) . '</span>';
	}

	$args = array(
		'id'     => 'sqlite-db-integration',
		'parent' => 'top-secondary',
		'title'  => $title,
		'href'   => esc_url( sqlite_plugin_get_admin_page_url() ),
		'meta'   => false,
	);
	$admin_bar->add_node( $args );
}
add_action( 'admin_bar_menu', 'sqlite_plugin_adminbar_item', 999 );

/**
 * Get the SQLite integration admin page URL.
 *
 * @access private
 *
 * @return string Admin page URL.
 */
function sqlite_plugin_get_admin_page_url() {
	if ( is_multisite() ) {
		return network_admin_url( 'settings.php?page=sqlite-integration' );
	}
	return admin_url( 'options-general.php?page=sqlite-integration' );
}
