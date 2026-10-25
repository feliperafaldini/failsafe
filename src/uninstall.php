<?php
/**
 * Uninstall routine.
 *
 * @package Failsafe
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$failsafe_options = array(
	'failsafe_retention_count',
	'failsafe_delete_backups_on_uninstall',
	'failsafe_schedule_enabled',
	'failsafe_schedule_frequency',
	'failsafe_last_run',
	'failsafe_last_result',
	'failsafe_db_size_cache',
	'failsafe_email_on_success',
);

$failsafe_remove_backups = (bool) get_option( 'failsafe_delete_backups_on_uninstall', false );

foreach ( $failsafe_options as $failsafe_option ) {
	delete_option( $failsafe_option );
}

delete_transient( 'failsafe_cron_lock' );

// Backups are only removed when the site owner explicitly opted in.
if ( ! $failsafe_remove_backups ) {
	return;
}

$failsafe_backup_dir = WP_CONTENT_DIR . '/failsafe-backups';

if ( ! is_dir( $failsafe_backup_dir ) ) {
	return;
}

require_once ABSPATH . 'wp-admin/includes/file.php';

if ( WP_Filesystem( false, $failsafe_backup_dir, true ) ) {
	global $wp_filesystem;

	$wp_filesystem->delete( $failsafe_backup_dir, true );
}
