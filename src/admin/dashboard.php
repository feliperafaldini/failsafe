<?php
/**
 * Dashboard widget and admin notices.
 *
 * @package Failsafe
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'wp_dashboard_setup', 'failsafe_add_dashboard_widgets' );
add_action( 'admin_notices', 'failsafe_backup_notice' );

/**
 * Registers the dashboard widget for users allowed to run backups.
 *
 * @return void
 */
function failsafe_add_dashboard_widgets() {
	if ( ! current_user_can( failsafe_backup_capability() ) ) {
		return;
	}

	wp_add_dashboard_widget(
		'failsafe_dashboard',
		esc_html__( 'Failsafe Backup', 'failsafe' ),
		'failsafe_dashboard_widget_render'
	);
}

/**
 * Renders the dashboard widget.
 *
 * @return void
 */
function failsafe_dashboard_widget_render() {
	?>
	<p><?php esc_html_e( 'Create a backup of your database and files.', 'failsafe' ); ?></p>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="failsafe_run_backup" />
		<?php wp_nonce_field( 'failsafe_run_backup', 'failsafe_backup_nonce' ); ?>
		<button type="submit" class="button button-primary">
			<?php esc_html_e( 'Backup now', 'failsafe' ); ?>
		</button>
	</form>
	<?php
}

/**
 * Shows the result of the last backup run on the dashboard.
 *
 * @return void
 */
function failsafe_backup_notice() {
	if ( ! isset( $_GET['failsafe_backup'] ) || 'done' !== sanitize_key( wp_unslash( $_GET['failsafe_backup'] ) ) ) {
		return;
	}

	if ( ! current_user_can( failsafe_backup_capability() ) ) {
		return;
	}

	$screen = get_current_screen();

	if ( ! $screen || 'dashboard' !== $screen->id ) {
		return;
	}

	$sql_bytes = isset( $_GET['failsafe_sql_bytes'] ) ? absint( wp_unslash( $_GET['failsafe_sql_bytes'] ) ) : 0;
	$zip_bytes = isset( $_GET['failsafe_zip_bytes'] ) ? absint( wp_unslash( $_GET['failsafe_zip_bytes'] ) ) : 0;

	printf(
		'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
		esc_html(
			sprintf(
				/* translators: 1: database dump size, 2: archive size. */
				__( 'Backup created. Database dump: %1$s. Archive: %2$s.', 'failsafe' ),
				size_format( $sql_bytes, 1 ),
				size_format( $zip_bytes, 1 )
			)
		)
	);
}
