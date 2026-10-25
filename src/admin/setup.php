<?php
/**
 * Creates and protects the backup directory.
 *
 * @package Failsafe
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

register_activation_hook( dirname( __DIR__ ) . '/failsafe.php', 'failsafe_prepare_backup_dir' );
add_action( 'admin_init', 'failsafe_admin_init' );

/**
 * Creates the backup directory and its protective files.
 *
 * @return true|WP_Error True when the directory is ready.
 */
function failsafe_prepare_backup_dir() {
	if ( ! wp_mkdir_p( FAILSAFE_BACKUP_DIR ) && ! is_dir( FAILSAFE_BACKUP_DIR ) ) {
		return new WP_Error( 'failsafe_mkdir_failed', FAILSAFE_BACKUP_DIR );
	}

	$htaccess = "<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n"
		. "<IfModule !mod_authz_core.c>\n\tOrder deny,allow\n\tDeny from all\n</IfModule>\n";

	$files = array(
		'.htaccess' => $htaccess,
		'index.php' => "<?php\n// Silence is golden.\n",
	);

	foreach ( $files as $name => $contents ) {
		$path = FAILSAFE_BACKUP_DIR . '/' . $name;

		if ( is_readable( $path ) && file_get_contents( $path ) === $contents ) {
			continue;
		}

		if ( false === file_put_contents( $path, $contents ) ) {
			return new WP_Error( 'failsafe_protect_failed', $path );
		}
	}

	return true;
}

/**
 * Keeps the directory ready on every admin request and warns when it is not.
 *
 * @return void
 */
function failsafe_admin_init() {
	$result = failsafe_prepare_backup_dir();

	if ( ! is_wp_error( $result ) ) {
		return;
	}

	failsafe_log( $result->get_error_code() . ': ' . $result->get_error_message() );

	$message = sprintf(
		/* translators: %s: directory path. */
		__( 'Failsafe Backup cannot use the backup directory %s. Backups will fail until this is fixed.', 'failsafe' ),
		$result->get_error_message()
	);

	add_action(
		'admin_notices',
		static function () use ( $message ) {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html( $message )
			);
		}
	);
}
