<?php

if( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'admin_post_failsafe_run_backup', 'failsafe_handle_run_backup' );

function failsafe_handle_run_backup() {
	global $wpdb;
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html( 'Permission denied.', 'failsafe' ) );
	}
	check_admin_referer( 'failsafe_run_backup', 'failsafe_backup_nonce' );
	$tables = $wpdb->get_results( 'SHOW CREATE TABLE `wp_posts`' );
	error_log( print_r( $tables, true ) );
	wp_safe_redirect( admin_url() );
	exit;
}
