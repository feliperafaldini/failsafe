<?php

if ( ! defined('ABSPATH' ) ) {
	exit;
}

add_action( 'wp_dashboard_setup', 'failsafe_add_dashboard_widgets' );

function failsafe_add_dashboard_widgets() {
	wp_add_dashboard_widget(
			'failsafe_dashboard',
			esc_html__( 'Failsafe Backup', 'failsafe' ),
			'failsafe_dashboard_widget_render',
	);
}

function failsafe_dashboard_widget_render() {
	?>
	<p><?php esc_html_e( 'Backup', 'failsafe' ); ?></p>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="failsafe_run_backup" />
			<?php wp_nonce_field( 'failsafe_run_backup', 'failsafe_backup_nonce' ); ?>
			<button type="submit" class="button button-primary">
					<?php esc_html_e( 'Backup now', 'failsafe' ); ?>
			</button>
	</form>
	<?php
}
