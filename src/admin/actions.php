<?php

if( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'admin_post_failsafe_run_backup', 'failsafe_handle_run_backup' );
function failsafe_handle_run_backup() {
	global $wpdb;
	check_admin_referer( 'failsafe_run_backup', 'failsafe_backup_nonce' );
	$tables = $wpdb->get_col( 'SHOW TABLES' );
	error_log( print_r( $tables, true ) );
	wp_safe_redirect( admin_url() );
	exit;
}
