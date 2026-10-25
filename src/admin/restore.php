<?php
/**
 * Restore screen and form handler.
 *
 * @package Failsafe
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'admin_menu', 'failsafe_register_restore_page' );
add_action( 'admin_post_failsafe_restore_backup', 'failsafe_handle_restore_backup' );

/**
 * Registers the restore screen under Tools.
 *
 * @return void
 */
function failsafe_register_restore_page() {
	add_management_page(
		__( 'Failsafe Restore', 'failsafe' ),
		__( 'Failsafe Restore', 'failsafe' ),
		failsafe_backup_capability(),
		'failsafe-restore',
		'failsafe_restore_page_render'
	);
}

/**
 * Transient key that carries the result of the last restore to the screen.
 *
 * @return string
 */
function failsafe_restore_notice_key() {
	return 'failsafe_restore_notice_' . (int) get_current_user_id();
}

/**
 * Stores a notice and redirects back to the restore screen.
 *
 * @param string $type    Either "success" or "error".
 * @param string $message Message shown to the user.
 * @return void
 */
function failsafe_restore_redirect( $type, $message ) {
	set_transient(
		failsafe_restore_notice_key(),
		array(
			'type'    => 'success' === $type ? 'success' : 'error',
			'message' => (string) $message,
		),
		5 * MINUTE_IN_SECONDS
	);

	wp_safe_redirect(
		add_query_arg(
			array( 'page' => 'failsafe-restore' ),
			admin_url( 'tools.php' )
		)
	);

	exit;
}

/**
 * Reads and removes the notice left by the last restore.
 *
 * @return array|null Notice with "type" and "message", or null.
 */
function failsafe_restore_take_notice() {
	$notice = get_transient( failsafe_restore_notice_key() );

	if ( ! is_array( $notice ) || empty( $notice['message'] ) ) {
		return null;
	}

	delete_transient( failsafe_restore_notice_key() );

	return array(
		'type'    => isset( $notice['type'] ) && 'success' === $notice['type'] ? 'success' : 'error',
		'message' => (string) $notice['message'],
	);
}

/**
 * Ends the request with a result screen.
 *
 * Unlike a redirect this screen is shown even when the restore deactivated the
 * plugin or invalidated the current session.
 *
 * @param string $type    Either "success" or "error".
 * @param string $message Message shown to the user.
 * @return void
 */
function failsafe_restore_result_screen( $type, $message ) {
	$is_success = 'success' === $type;
	$link       = sprintf(
		'<p><a href="%1$s">%2$s</a></p>',
		esc_url( admin_url() ),
		esc_html__( 'Go to the dashboard', 'failsafe' )
	);

	wp_die(
		'<p>' . esc_html( $message ) . '</p>' . $link,
		$is_success ? __( 'Restore complete', 'failsafe' ) : __( 'Restore failed', 'failsafe' ),
		array( 'response' => $is_success ? 200 : 500 )
	);
}

/**
 * Handles the restore form submission.
 *
 * @return void
 */
function failsafe_handle_restore_backup() {
	if ( ! current_user_can( failsafe_backup_capability() ) ) {
		wp_die(
			esc_html__( 'You are not allowed to restore backups.', 'failsafe' ),
			'',
			array( 'response' => 403 )
		);
	}

	if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || 'POST' !== strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) ) {
		wp_die(
			esc_html__( 'Restores must be submitted from the restore screen.', 'failsafe' ),
			'',
			array( 'response' => 405 )
		);
	}

	check_admin_referer( 'failsafe_restore_backup', 'failsafe_restore_nonce' );

	$base = isset( $_POST['failsafe_backup'] ) ? sanitize_text_field( wp_unslash( $_POST['failsafe_backup'] ) ) : '';

	if ( ! failsafe_is_valid_backup_name( $base ) ) {
		failsafe_restore_redirect( 'error', __( 'The selected backup is not valid.', 'failsafe' ) );
	}

	$confirm = isset( $_POST['confirm'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['confirm'] ) ) ) : '';

	if ( $confirm !== $base ) {
		failsafe_restore_redirect( 'error', __( 'Type the backup name exactly as shown to confirm the restore.', 'failsafe' ) );
	}

	if ( ! function_exists( 'failsafe_restore_backup' ) ) {
		failsafe_restore_redirect( 'error', __( 'The restore component is not available.', 'failsafe' ) );
	}

	$result = failsafe_restore_backup( $base );

	if ( is_wp_error( $result ) ) {
		failsafe_log( 'restore ' . $base . ': ' . $result->get_error_message() );

		failsafe_restore_result_screen( 'error', $result->get_error_message() );
	}

	$safety_backup = is_array( $result ) && ! empty( $result['safety_backup'] );

	failsafe_restore_result_screen(
		'success',
		$safety_backup
			? sprintf(
				/* translators: %s: backup name. */
				__( 'The database was restored from %s. A safety backup was created first and the original backup was kept. You may need to sign in again.', 'failsafe' ),
				$base
			)
			: sprintf(
				/* translators: %s: backup name. */
				__( 'The database was restored from %s. No safety backup was available on this site, but the original backup was kept. You may need to sign in again.', 'failsafe' ),
				$base
			)
	);
}

/**
 * Renders the restore screen.
 *
 * @return void
 */
function failsafe_restore_page_render() {
	if ( ! current_user_can( failsafe_backup_capability() ) ) {
		wp_die(
			esc_html__( 'You are not allowed to restore backups.', 'failsafe' ),
			'',
			array( 'response' => 403 )
		);
	}

	$notice  = failsafe_restore_take_notice();
	$backups = failsafe_get_backups();
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Restore a backup', 'failsafe' ); ?></h1>

		<?php if ( null !== $notice ) : ?>
			<div class="notice notice-<?php echo esc_attr( $notice['type'] ); ?> is-dismissible">
				<p><?php echo esc_html( $notice['message'] ); ?></p>
			</div>
		<?php endif; ?>

		<p>
			<?php esc_html_e( 'Restoring replaces the current database with the one stored in the backup. A safety backup is created before the restore starts, and the backup being restored is never deleted.', 'failsafe' ); ?>
		</p>
		<p>
			<strong><?php esc_html_e( 'This cannot be undone from this screen.', 'failsafe' ); ?></strong>
		</p>

		<?php if ( empty( $backups ) ) : ?>
			<p><?php esc_html_e( 'No backups were found in the protected directory. Create one from the dashboard first.', 'failsafe' ); ?></p>
		<?php else : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="failsafe_restore_backup" />
				<?php wp_nonce_field( 'failsafe_restore_backup', 'failsafe_restore_nonce' ); ?>

				<table class="widefat striped">
					<thead>
						<tr>
							<td class="manage-column column-cb check-column">&nbsp;</td>
							<th scope="col"><?php esc_html_e( 'Backup', 'failsafe' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Created', 'failsafe' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Size', 'failsafe' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Contents', 'failsafe' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php
						$preselected = false;

						foreach ( $backups as $backup ) {
							$restorable = ( ! empty( $backup['zip'] ) && (int) $backup['zip']['size'] > 0 ) || ! empty( $backup['sql'] );
							$created    = (int) $backup['time'] > 0
								? (string) wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $backup['time'] )
								: __( 'Unknown', 'failsafe' );
							$contents   = $backup['complete']
								? __( 'Complete', 'failsafe' )
								: __( 'Incomplete', 'failsafe' );
							$field_id   = 'failsafe-restore-' . $backup['base'];
							?>
							<tr>
								<th scope="row" class="check-column">
									<input
										type="radio"
										name="failsafe_backup"
										id="<?php echo esc_attr( $field_id ); ?>"
										value="<?php echo esc_attr( $backup['base'] ); ?>"
										<?php checked( $restorable && ! $preselected ); ?>
										<?php disabled( ! $restorable ); ?>
									/>
								</th>
								<td>
									<label for="<?php echo esc_attr( $field_id ); ?>">
										<?php echo esc_html( $backup['base'] ); ?>
									</label>
								</td>
								<td><?php echo esc_html( $created ); ?></td>
								<td><?php echo esc_html( size_format( (int) $backup['size'], 1 ) ); ?></td>
								<td><?php echo esc_html( $contents ); ?></td>
							</tr>
							<?php
							if ( $restorable ) {
								$preselected = true;
							}
						}
						?>
					</tbody>
				</table>

				<p>
					<label for="failsafe-restore-confirm">
						<?php esc_html_e( 'Type the backup name to confirm', 'failsafe' ); ?>
					</label>
					<br />
					<input
						type="text"
						class="regular-text"
						id="failsafe-restore-confirm"
						name="confirm"
						autocomplete="off"
						spellcheck="false"
						required
					/>
				</p>
				<p class="description">
					<?php esc_html_e( 'Use the exact name shown in the table, for example failsafe-20260101-120000.', 'failsafe' ); ?>
				</p>

				<p>
					<button type="submit" class="button button-primary">
						<?php esc_html_e( 'Restore this backup', 'failsafe' ); ?>
					</button>
				</p>
			</form>
		<?php endif; ?>
	</div>
	<?php
}
