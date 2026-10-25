<?php
/**
 * Uninstall routine.
 *
 * @package Failsafe
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'failsafe_retention_count' );

// Backups are only removed when the site owner explicitly opted in.
if ( ! get_option( 'failsafe_delete_backups_on_uninstall', false ) ) {
	return;
}

$backup_dir = WP_CONTENT_DIR . '/failsafe-backups';

if ( ! is_dir( $backup_dir ) ) {
	return;
}

require_once ABSPATH . 'wp-admin/includes/file.php';

if ( WP_Filesystem( false, $backup_dir, true ) ) {
	global $wp_filesystem;

	$wp_filesystem->delete( $backup_dir, true );
}

delete_option( 'failsafe_delete_backups_on_uninstall' );
