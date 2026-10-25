<?php
/**
 * Plugin Name:       Failsafe Backup
 * Description:       Creates a backup of the WordPress database and files as a single ZIP archive.
 * Version:           0.2.0-dev
 * Requires at least: 6.2
 * Requires PHP:      8.0
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
	define( 'FAILSAFE_VERSION', '0.2.0-dev' );
}

if ( ! defined( 'FAILSAFE_DIR' ) ) {
	define( 'FAILSAFE_DIR', __DIR__ );
}

if ( ! defined( 'FAILSAFE_BACKUP_DIR' ) ) {
	define( 'FAILSAFE_BACKUP_DIR', WP_CONTENT_DIR . '/failsafe-backups' );
}

if ( ! defined( 'FAILSAFE_RETENTION_DEFAULT' ) ) {
	define( 'FAILSAFE_RETENTION_DEFAULT', 5 );
}

require_once FAILSAFE_DIR . '/includes/storage.php';
require_once FAILSAFE_DIR . '/admin/setup.php';
require_once FAILSAFE_DIR . '/admin/format.php';
require_once FAILSAFE_DIR . '/admin/partials.php';
require_once FAILSAFE_DIR . '/admin/actions.php';
require_once FAILSAFE_DIR . '/admin/menu.php';
require_once FAILSAFE_DIR . '/admin/backups-page.php';
require_once FAILSAFE_DIR . '/admin/download.php';
require_once FAILSAFE_DIR . '/admin/delete.php';
require_once FAILSAFE_DIR . '/admin/dashboard.php';
