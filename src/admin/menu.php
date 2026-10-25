<?php
/**
 * Admin menu registration and asset loading.
 *
 * @package Failsafe
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'admin_menu', 'failsafe_register_menu' );
add_action( 'admin_enqueue_scripts', 'failsafe_enqueue_admin_assets' );

/**
 * Registers the top level backups page and remembers its hook suffix.
 *
 * @return void
 */
function failsafe_register_menu() {
	$hook = add_menu_page(
		__( 'Failsafe Backups', 'failsafe-backup' ),
		__( 'Failsafe', 'failsafe-backup' ),
		failsafe_backup_capability(),
		'failsafe-backups',
		'failsafe_render_backups_page',
		'dashicons-backup'
	);

	$GLOBALS['failsafe_menu_hook'] = $hook;
}

/**
 * Loads the admin script on the backups screen only.
 *
 * @param string $hook_suffix Current admin page hook suffix.
 * @return void
 */
function failsafe_enqueue_admin_assets( $hook_suffix ) {
	if ( ! isset( $GLOBALS['failsafe_menu_hook'] ) || $GLOBALS['failsafe_menu_hook'] !== $hook_suffix ) {
		return;
	}

	wp_enqueue_script(
		'failsafe-admin',
		plugins_url( 'assets/admin.js', FAILSAFE_DIR . '/failsafe.php' ),
		array(),
		FAILSAFE_VERSION,
		true
	);
}

/**
 * Builds a URL that points at the backups page.
 *
 * @param array $args Extra query arguments.
 * @return string
 */
function failsafe_backups_page_url( $args = array() ) {
	return add_query_arg(
		array_merge( array( 'page' => 'failsafe-backups' ), $args ),
		admin_url( 'admin.php' )
	);
}
