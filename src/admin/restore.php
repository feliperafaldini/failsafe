<?php
/**
 * Restore screen and request handler.
 *
 * @package Failsafe
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'admin_post_failsafe_restore_backup', 'failsafe_handle_restore_backup' );
add_action( 'admin_menu', 'failsafe_register_restore_page' );
add_action( 'admin_notices', 'failsafe_restore_notice' );

/**
 * Registers the restore screen under Tools.
 *
 * @return void
 */
function failsafe_register_restore_page() {
	add_management_page(
		esc_html__( 'Failsafe Restore', 'failsafe-backup' ),
		esc_html__( 'Failsafe Restore', 'failsafe-backup' ),
		failsafe_backup_capability(),
		'failsafe-restore',
		'failsafe_render_restore_page'
	);
}

/**
 * URL of the restore screen.
 *
 * @param array $args Optional query arguments.
 * @return string
 */
function failsafe_restore_page_url( array $args = array() ) {
	$url = admin_url( 'tools.php?page=failsafe-restore' );

	if ( $args ) {
		$url = add_query_arg( $args, $url );
	}

	return $url;
}

/**
 * Handles the "Restore database" form submission.
 *
 * @return void
 */
function failsafe_handle_restore_backup() {
	if ( ! current_user_can( failsafe_backup_capability() ) ) {
		wp_die( esc_html__( 'You are not allowed to restore backups.', 'failsafe-backup' ) );
	}

	check_admin_referer( 'failsafe_restore_backup', 'failsafe_restore_nonce' );

	$base = isset( $_POST['failsafe_backup'] ) ? sanitize_text_field( wp_unslash( $_POST['failsafe_backup'] ) ) : '';

	if ( ! failsafe_is_valid_backup_name( $base ) ) {
		failsafe_redirect_restore_notice( 'error', __( 'The requested backup name is not valid. Nothing was restored.', 'failsafe-backup' ) );
	}

	$confirm = isset( $_POST['confirm'] ) ? trim( (string) sanitize_text_field( wp_unslash( $_POST['confirm'] ) ) ) : '';

	if ( $confirm !== $base ) {
		failsafe_redirect_restore_notice( 'error', __( 'The confirmation text does not match the backup name. Nothing was restored.', 'failsafe-backup' ) );
	}

	$backup = failsafe_get_backup( $base );

	if ( is_wp_error( $backup ) ) {
		failsafe_redirect_restore_notice( 'error', $backup->get_error_message() );
	}

	if ( empty( $backup['zip'] ) || (int) $backup['zip']['size'] <= 0 ) {
		failsafe_redirect_restore_notice( 'error', __( 'The backup archive is missing or empty. Nothing was restored.', 'failsafe-backup' ) );
	}

	$zip_path = failsafe_backup_path( $base, 'zip' );

	if ( is_wp_error( $zip_path ) || ! is_readable( $zip_path ) ) {
		failsafe_redirect_restore_notice( 'error', __( 'The backup archive cannot be read. Nothing was restored.', 'failsafe-backup' ) );
	}

	// Never overwrite the database without a fresh backup to fall back to.
	$safety = failsafe_create_restore_safety_backup();

	if ( is_wp_error( $safety ) ) {
		failsafe_log( 'restore: ' . $safety->get_error_code() . ': ' . $safety->get_error_message() );
		failsafe_redirect_restore_notice( 'error', $safety->get_error_message() );
	}

	$extracted = failsafe_extract_backup_archive( $zip_path );

	if ( is_wp_error( $extracted ) ) {
		failsafe_log( 'restore: ' . $extracted->get_error_code() . ': ' . $extracted->get_error_message() );
		failsafe_redirect_restore_notice( 'error', $extracted->get_error_message() );
	}

	$dump_path = trailingslashit( $extracted ) . FAILSAFE_RESTORE_DUMP_NAME;

	// Backups created after 0.2.0 name the dump after the set, e.g. failsafe-20261025-013721.sql.
	if ( ! is_readable( $dump_path ) ) {
		$candidates = glob( trailingslashit( $extracted ) . 'failsafe-*.sql' );

		if ( is_array( $candidates ) && $candidates ) {
			sort( $candidates );
			$dump_path = $candidates[0];
		}
	}

	if ( ! is_readable( $dump_path ) ) {
		failsafe_remove_temp_directory( $extracted );
		failsafe_redirect_restore_notice(
			'error',
			sprintf(
				/* translators: %s: dump file name. */
				__( 'The archive does not contain a readable %s file. Nothing was restored.', 'failsafe-backup' ),
				FAILSAFE_RESTORE_DUMP_NAME
			)
		);
	}

	$restored = failsafe_restore_database_from_file( $dump_path );

	failsafe_remove_temp_directory( $extracted );

	// The database changed underneath the object cache, even on a partial restore.
	wp_cache_flush();

	if ( is_wp_error( $restored ) ) {
		failsafe_log( 'restore: ' . $restored->get_error_code() . ': ' . $restored->get_error_message() );
		failsafe_redirect_restore_notice( 'error', $restored->get_error_message() );
	}

	/**
	 * Fires after a backup has been restored successfully.
	 *
	 * @param string $base Base name of the backup that was restored.
	 */
	do_action( 'failsafe_restore_completed', $base );

	failsafe_redirect_restore_notice(
		'done',
		sprintf(
			/* translators: %s: backup base name. */
			__( 'The database was restored from %s. The backup was kept and the site files were not changed. You may need to sign in again.', 'failsafe-backup' ),
			$base
		)
	);
}

/**
 * Stores a one-time notice and redirects back to the restore screen.
 *
 * @param string $type    Either "done" or "error".
 * @param string $message Message shown to the user.
 * @return void
 */
function failsafe_redirect_restore_notice( $type, $message ) {
	$type    = 'done' === $type ? 'done' : 'error';
	$user_id = get_current_user_id();

	if ( $user_id > 0 ) {
		set_transient(
			'failsafe_restore_notice_' . $user_id,
			array(
				'type'    => $type,
				'message' => (string) $message,
			),
			5 * MINUTE_IN_SECONDS
		);
	}

	wp_safe_redirect( failsafe_restore_page_url( array( 'failsafe_restore' => $type ) ) );
	exit;
}

/**
 * Shows the result of the last restore on the restore screen.
 *
 * @return void
 */
function failsafe_restore_notice() {
	if ( ! current_user_can( failsafe_backup_capability() ) ) {
		return;
	}

	$screen = get_current_screen();

	if ( ! $screen || ! in_array( $screen->id, array( 'tools_page_failsafe-restore', 'dashboard' ), true ) ) {
		return;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only flag set by our own redirect.
	$result = isset( $_GET['failsafe_restore'] ) ? sanitize_key( wp_unslash( $_GET['failsafe_restore'] ) ) : '';

	if ( ! in_array( $result, array( 'done', 'error' ), true ) ) {
		return;
	}

	$key    = 'failsafe_restore_notice_' . get_current_user_id();
	$notice = get_transient( $key );

	if ( is_array( $notice ) && isset( $notice['message'] ) ) {
		delete_transient( $key );
	}

	$message = is_array( $notice ) && isset( $notice['message'] )
		? (string) $notice['message']
		: ( 'done' === $result ? __( 'The restore finished.', 'failsafe-backup' ) : __( 'The restore failed.', 'failsafe-backup' ) );

	$type = is_array( $notice ) && isset( $notice['type'] ) && in_array( $notice['type'], array( 'done', 'error' ), true )
		? $notice['type']
		: $result;

	printf(
		'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
		'done' === $type ? 'success' : 'error',
		esc_html( $message )
	);
}

/**
 * Renders the restore screen.
 *
 * @return void
 */
function failsafe_render_restore_page() {
	if ( ! current_user_can( failsafe_backup_capability() ) ) {
		wp_die( esc_html__( 'You are not allowed to restore backups.', 'failsafe-backup' ) );
	}

	$backups    = failsafe_get_backups();
	$restorable = 0;

	echo '<div class="wrap">';
	echo '<h1>' . esc_html__( 'Failsafe Restore', 'failsafe-backup' ) . '</h1>';

	printf(
		'<div class="notice notice-warning inline"><p>%s</p></div>',
		esc_html__( 'Restoring replaces every table contained in the backup. A safety backup is created first, but the current database will be overwritten. Site files are not changed. Keep this tab open until the restore finishes.', 'failsafe-backup' )
	);

	if ( empty( $backups ) ) {
		echo '<p>' . esc_html__( 'No backups were found.', 'failsafe-backup' ) . '</p>';
		echo '</div>';
		return;
	}

	echo '<table class="widefat striped"><thead><tr>';
	echo '<th scope="col">' . esc_html__( 'Backup', 'failsafe-backup' ) . '</th>';
	echo '<th scope="col">' . esc_html__( 'Date', 'failsafe-backup' ) . '</th>';
	echo '<th scope="col">' . esc_html__( 'Size', 'failsafe-backup' ) . '</th>';
	echo '<th scope="col">' . esc_html__( 'Restore', 'failsafe-backup' ) . '</th>';
	echo '</tr></thead><tbody>';

	foreach ( $backups as $backup ) {
		$timestamp = (int) $backup['time'];
		$date      = $timestamp > 0
			? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp )
			: __( 'Unknown', 'failsafe-backup' );
		$has_zip   = ! empty( $backup['zip'] ) && (int) $backup['zip']['size'] > 0;

		echo '<tr>';
		echo '<td><code>' . esc_html( $backup['base'] ) . '</code></td>';
		echo '<td>' . esc_html( (string) $date ) . '</td>';
		echo '<td>' . esc_html( (string) size_format( (int) $backup['size'], 1 ) ) . '</td>';
		echo '<td>';

		if ( $has_zip ) {
			$restorable++;
			failsafe_render_restore_form( $backup['base'] );
		} else {
			echo esc_html__( 'The archive is missing or empty.', 'failsafe-backup' );
		}

		echo '</td>';
		echo '</tr>';
	}

	echo '</tbody></table>';

	if ( 0 === $restorable ) {
		echo '<p>' . esc_html__( 'None of the backups has a usable archive, so nothing can be restored right now.', 'failsafe-backup' ) . '</p>';
	}

	echo '</div>';
}

/**
 * Renders the restore form for one backup.
 *
 * @param string $base Backup base name.
 * @return void
 */
function failsafe_render_restore_form( $base ) {
	$field_id = 'failsafe-confirm-' . sanitize_html_class( $base );
	?>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="failsafe_restore_backup" />
		<input type="hidden" name="failsafe_backup" value="<?php echo esc_attr( $base ); ?>" />
		<?php wp_nonce_field( 'failsafe_restore_backup', 'failsafe_restore_nonce' ); ?>
		<p>
			<label for="<?php echo esc_attr( $field_id ); ?>">
				<?php esc_html_e( 'Type the backup name to confirm:', 'failsafe-backup' ); ?>
				<code><?php echo esc_html( $base ); ?></code>
			</label>
		</p>
		<p>
			<input type="text" class="regular-text code" id="<?php echo esc_attr( $field_id ); ?>" name="confirm" autocomplete="off" required />
		</p>
		<p>
			<button type="submit" class="button button-primary">
				<?php esc_html_e( 'Restore database', 'failsafe-backup' ); ?>
			</button>
		</p>
	</form>
	<?php
}
