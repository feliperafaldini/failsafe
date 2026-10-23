<?php

if( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'admin_post_failsafe_run_backup', 'failsafe_handle_run_backup' );
function failsafe_handle_run_backup() {
	check_admin_referer( 'failsafe_run_backup', 'failsafe_backup_nonce' );
			error_log( 'failsafe: handler executed' );
	wp_safe_redirect( admin_url() );
	exit;
}
