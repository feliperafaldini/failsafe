<?php
/**
 * Backups screen: list, empty state and notices.
 *
 * @package Failsafe
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the backups page.
 *
 * @return void
 */
function failsafe_render_backups_page() {
	if ( ! current_user_can( failsafe_backup_capability() ) ) {
		wp_die(
			esc_html__( 'Sorry, you are not allowed to access this page.', 'failsafe-backup' ),
			'',
			array( 'response' => 403 )
		);
	}

	$filesystem = failsafe_fs();
	$backups    = $filesystem ? failsafe_get_backups() : array();
	?>
	<div class="wrap">
		<h1 class="wp-heading-inline"><?php esc_html_e( 'Backups', 'failsafe-backup' ); ?></h1>
		<?php
		if ( ! empty( $backups ) ) {
			failsafe_render_backup_button();
		}
		?>
		<hr class="wp-header-end" />

		<?php failsafe_render_notices(); ?>

		<?php if ( ! $filesystem ) : ?>
			<div class="notice notice-error">
				<p><?php esc_html_e( 'The backup directory is not accessible to WordPress. Check the file permissions and the site log.', 'failsafe-backup' ); ?></p>
			</div>
		<?php elseif ( empty( $backups ) ) : ?>
			<p><?php esc_html_e( 'No backups yet.', 'failsafe-backup' ); ?></p>
			<p><?php esc_html_e( 'Create a backup of your database and files to get started.', 'failsafe-backup' ); ?></p>
			<?php failsafe_render_backup_button(); ?>
		<?php else : ?>
			<?php failsafe_render_backups_table( $backups ); ?>
			<?php failsafe_render_backups_footer( $backups ); ?>
		<?php endif; ?>
	</div>
	<?php
}

/**
 * Renders the fixed notices that follow a redirect.
 *
 * Only known codes are translated and printed; error messages from the
 * filesystem never reach the screen.
 *
 * @return void
 */
function failsafe_render_notices() {
	$notice = isset( $_GET['failsafe_notice'] ) ? sanitize_key( wp_unslash( $_GET['failsafe_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only notice code; it changes no state.

	$messages = array(
		'created'       => array( 'success', __( 'Backup created.', 'failsafe-backup' ) ),
		'deleted'       => array( 'success', __( 'Backup deleted.', 'failsafe-backup' ) ),
		'delete_failed' => array( 'error', __( 'The backup could not be deleted completely. Check the site log for details.', 'failsafe-backup' ) ),
		'error'         => array( 'error', __( 'The backup could not be created. Check the site log for details.', 'failsafe-backup' ) ),
		'busy'          => array( 'warning', __( 'Another backup is already running. Wait for it to finish and try again.', 'failsafe-backup' ) ),
	);

	// Known failures explain themselves; anything else falls back to the generic text.
	$error_code = isset( $_GET['failsafe_error'] ) ? sanitize_key( wp_unslash( $_GET['failsafe_error'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only error code; it changes no state.

	$error_messages = array(
		'failsafe_limit_memory'  => __( 'The backup was not created: the database is too large for the available PHP memory. Raise memory_limit or back up from a server with more memory.', 'failsafe-backup' ),
		'failsafe_limit_disk'    => __( 'The backup was not created: there is not enough free disk space for the archive.', 'failsafe-backup' ),
		'failsafe_limit_time'    => __( 'The backup was not created: the expected run time exceeds the PHP execution limit. Raise max_execution_time or run the backup from WP-Cron.', 'failsafe-backup' ),
		'failsafe_no_ziparchive' => __( 'The backup was not created: the PHP zip extension is not available on this server.', 'failsafe-backup' ),
		'failsafe_fs_unavailable' => __( 'The backup was not created: WordPress cannot write to the backup directory.', 'failsafe-backup' ),
		'failsafe_write_failed'  => __( 'The backup was not created: the database dump could not be written to disk.', 'failsafe-backup' ),
		'failsafe_zip_partial'   => __( 'The archive was written but some files could not be added. Check the site log for the list.', 'failsafe-backup' ),
		'failsafe_no_tables'     => __( 'The backup was not created: no database tables were found.', 'failsafe-backup' ),
	);

	if ( 'error' === $notice && isset( $error_messages[ $error_code ] ) ) {
		$messages['error'][1] = $error_messages[ $error_code ];
	}

	if ( ! isset( $messages[ $notice ] ) ) {
		return;
	}

	printf(
		'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
		esc_attr( $messages[ $notice ][0] ),
		esc_html( $messages[ $notice ][1] )
	);
}

/**
 * Renders the table of backup sets.
 *
 * @param array $backups Backup sets as returned by failsafe_get_backups().
 * @return void
 */
function failsafe_render_backups_table( $backups ) {
	?>
	<table class="wp-list-table widefat fixed striped">
		<thead>
			<tr>
				<th scope="col" class="column-date"><?php esc_html_e( 'Date', 'failsafe-backup' ); ?></th>
				<th scope="col" class="column-files"><?php esc_html_e( 'Files', 'failsafe-backup' ); ?></th>
				<th scope="col" class="column-size"><?php esc_html_e( 'Size', 'failsafe-backup' ); ?></th>
				<th scope="col" class="column-actions"><?php esc_html_e( 'Actions', 'failsafe-backup' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $backups as $backup ) : ?>
				<tr>
					<td>
						<?php echo esc_html( failsafe_format_time( (int) $backup['time'] ) ); ?>
					</td>
					<td>
						<?php if ( ! empty( $backup['zip'] ) ) : ?>
							<code><?php echo esc_html( $backup['zip']['name'] ); ?></code><br />
						<?php endif; ?>
						<?php if ( ! empty( $backup['sql'] ) ) : ?>
							<code><?php echo esc_html( $backup['sql']['name'] ); ?></code>
						<?php endif; ?>
						<?php if ( ! $backup['complete'] ) : ?>
							<p>
								<strong><?php esc_html_e( 'Incomplete', 'failsafe-backup' ); ?></strong><br />
								<span class="description"><?php esc_html_e( 'One file of this set is missing. It is kept until you delete it.', 'failsafe-backup' ); ?></span>
							</p>
						<?php endif; ?>
					</td>
					<td>
						<?php echo esc_html( failsafe_format_size( (int) $backup['size'] ) ); ?>
					</td>
					<td>
						<?php failsafe_render_row_actions( $backup ); ?>
					</td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
	<?php
}

/**
 * Renders the count and the total size under the table.
 *
 * @param array $backups Backup sets as returned by failsafe_get_backups().
 * @return void
 */
function failsafe_render_backups_footer( $backups ) {
	$count = count( $backups );
	$size  = failsafe_get_backups_total_size();
	?>
	<p class="description">
		<?php
		printf(
			esc_html(
				/* translators: 1: number of backups, 2: total size on disk. */
				_n( '%1$s backup, %2$s on disk.', '%1$s backups, %2$s on disk.', $count, 'failsafe-backup' )
			),
			esc_html( number_format_i18n( $count ) ),
			esc_html( failsafe_format_size( $size ) )
		);
		?>
	</p>
	<?php
}
