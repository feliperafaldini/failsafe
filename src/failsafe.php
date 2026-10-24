<?php
/**
 * Plugin Name: Failsafe Backup
 * Description: Plugin to backup data from your Wordpress site.
 * Version: 0.1.0
 * Author: Felipe Albino
 * License: GPL-2.0-or-later
 * Text Domain: failsafe
 */

if ( ! defined( 'ABSPATH' ) ) {
		exit;
}

define( 'FAILSAFE_VERSION', '0.1.0' );
define( 'FAILSAFE_DIR', __DIR__ );
define( 'FAILSAFE_BACKUP_DIR', WP_CONTENT_DIR . '/failsafe-backups' );
require FAILSAFE_DIR . '/admin/dashboard.php';
require FAILSAFE_DIR . '/admin/actions.php';
require FAILSAFE_DIR . '/admin/setup.php';
