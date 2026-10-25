<?php
/**
 * Reusable pieces of the backup interface.
 *
 * @package Failsafe
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds the admin-post URL that downloads one file of a backup set.
 *
 * The URL carries a nonce. wp_nonce_url() already escapes its result for HTML,
 * so callers echo it directly instead of wrapping it in esc_url(), which would
 * encode the ampersands and break the query string.
 *
 * @param string $base Backup base name.
 * @param string $ext  Either "zip" or "sql".
 * @return string
 */
function failsafe_get_download_url( $base, $ext ) {
	$url = add_query_arg(
		array(
			'action' => 'failsafe_download_backup',
			'backup' => $base,
			'tipo'   => $ext,
		),
		admin_url( 'admin-post.php' )
	);

	return wp_nonce_url( $url, 'failsafe_download_backup' );
}

/**
 * Renders the "Backup now" form.
 *
 * @return void
 */
function failsafe_render_backup_button() {
	?>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="failsafe-backup-form">
		<input type="hidden" name="action" value="failsafe_run_backup" />
		<?php wp_nonce_field( 'failsafe_run_backup', 'failsafe_backup_nonce' ); ?>
		<button type="submit" class="button button-primary"><?php esc_html_e( 'Backup now', 'failsafe-backup' ); ?></button>
	</form>
	<?php
}

/**
 * Renders the actions available for one backup set.
 *
 * @param array $backup Backup set as returned by failsafe_get_backups().
 * @return void
 */
function failsafe_render_row_actions( $backup ) {
	?>
	<div class="failsafe-row-actions">
		<?php if ( ! empty( $backup['zip'] ) ) : ?>
			<a class="button button-small" href="<?php echo failsafe_get_download_url( $backup['base'], 'zip' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_nonce_url() escapes the URL. ?>">
				<?php esc_html_e( 'Download (.zip)', 'failsafe-backup' ); ?>
			</a>
		<?php endif; ?>

		<?php if ( ! empty( $backup['sql'] ) ) : ?>
			<a class="button button-small" href="<?php echo failsafe_get_download_url( $backup['base'], 'sql' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_nonce_url() escapes the URL. ?>">
				<?php esc_html_e( 'Download (.sql)', 'failsafe-backup' ); ?>
			</a>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="failsafe-delete-form">
			<input type="hidden" name="action" value="failsafe_delete_backup" />
			<input type="hidden" name="backup" value="<?php echo esc_attr( $backup['base'] ); ?>" />
			<?php wp_nonce_field( 'failsafe_delete_backup_' . $backup['base'], '_wpnonce' ); ?>
			<button
				type="submit"
				class="button-link button-link-delete"
				data-confirm="<?php echo esc_attr__( 'Delete this backup? This cannot be undone.', 'failsafe-backup' ); ?>"
			>
				<?php esc_html_e( 'Delete', 'failsafe-backup' ); ?>
			</button>
		</form>
	</div>
	<?php
}
