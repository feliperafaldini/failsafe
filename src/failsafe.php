<?php
/**
 * Plugin Name:       Failsafe Backup
 * Description:       Creates a backup of the WordPress database and files as a single ZIP archive.
 * Version:           0.1.0
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            Felipe Albino
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       failsafe
 *
 * @package Failsafe
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'FAILSAFE_VERSION' ) ) {
	define( 'FAILSAFE_VERSION', '0.1.0' );
}

if ( ! defined( 'FAILSAFE_DIR' ) ) {
	define( 'FAILSAFE_DIR', __DIR__ );
}

if ( ! defined( 'FAILSAFE_BACKUP_DIR' ) ) {
	define( 'FAILSAFE_BACKUP_DIR', WP_CONTENT_DIR . '/failsafe-backups' );
}

require_once FAILSAFE_DIR . '/includes/storage.php';
require_once FAILSAFE_DIR . '/admin/setup.php';
require_once FAILSAFE_DIR . '/admin/actions.php';
require_once FAILSAFE_DIR . '/admin/dashboard.php';
