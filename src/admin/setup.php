<?php

if ( ! defined('ABSPATH' ) ) {
	exit;
}

add_action( 'admin_init', 'failsafe_admin_init' );

function failsafe_admin_init() {
	wp_mkdir_p( FAILSAFE_BACKUP_DIR );
	if ( ! file_exists( FAILSAFE_BACKUP_DIR . '/.htaccess' ) ) {
			file_put_contents( FAILSAFE_BACKUP_DIR . '/.htaccess', 'Require all denied' );
	}
	if ( ! file_exists( FAILSAFE_BACKUP_DIR . '/index.php' ) ) {
			file_put_contents( FAILSAFE_BACKUP_DIR . '/index.php', '<?php // silence is golden.' );
	}
}
