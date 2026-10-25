<?php
/**
 * Disaster protocol helper: runs the WordPress side of tests/disaster-protocol.sh.
 *
 * Every command exits 0 on success and prints at most one "KEY=value" line on
 * standard output, so the shell can read the value without parsing prose.
 * Errors go to standard error and exit with status 1.
 *
 * Commands:
 *   create <title>       Create the marker post; prints POST_ID=<id>.
 *   backup               Create a backup set; prints BACKUP_BASE=<base>.
 *   delete <post_id>     Delete the marker post and confirm it is gone.
 *   restore <base>       Restore the database from the SQL file of that set.
 *   verify <id> <title>  Check the marker post is back with the same title.
 *   check <base>         Check both files of the set are still on disk.
 *   cleanup <post_id>    Delete the marker post; an absent post is fine.
 *
 * Run inside the WordPress container:
 *   php /tmp/failsafe-disaster/php/disaster-marker.php create 'failsafe disaster ...'
 *
 * @package Failsafe
 */

// A cron request must not touch the database while the tables are replaced.
if ( ! defined( 'DISABLE_WP_CRON' ) ) {
	define( 'DISABLE_WP_CRON', true );
}

require '/var/www/html/wp-load.php';

if ( ! function_exists( 'failsafe_create_backup' ) || ! function_exists( 'failsafe_restore_database' ) ) {
	require_once WP_PLUGIN_DIR . '/failsafe/failsafe.php';
}

/**
 * Prints an error and exits with a non-zero status.
 *
 * @param string $message Error description.
 * @return never
 */
function failsafe_disaster_fail( $message ) {
	fwrite( STDERR, 'failsafe disaster: ' . $message . "\n" );
	exit( 1 );
}

/**
 * Reads a post ID argument.
 *
 * @param mixed $value Raw argument.
 * @return int
 */
function failsafe_disaster_post_id( $value ) {
	$value = (string) $value;

	if ( 1 !== preg_match( '/^[1-9][0-9]*$/', $value ) ) {
		failsafe_disaster_fail( sprintf( 'invalid post ID "%s".', $value ) );
	}

	return (int) $value;
}

if ( ! function_exists( 'failsafe_create_backup' ) || ! function_exists( 'failsafe_restore_database' ) ) {
	failsafe_disaster_fail( 'the Failsafe Backup plugin is not loaded.' );
}

$command = isset( $argv[1] ) ? (string) $argv[1] : '';

switch ( $command ) {
	case 'create':
		$title = trim( (string) ( $argv[2] ?? '' ) );

		if ( '' === $title ) {
			failsafe_disaster_fail( 'the marker title is missing.' );
		}

		$post_id = wp_insert_post(
			array(
				'post_title'   => $title,
				'post_content' => 'Created by tests/disaster-protocol.sh. Safe to delete.',
				'post_status'  => 'draft',
				'post_type'    => 'post',
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			failsafe_disaster_fail( 'could not create the marker post: ' . $post_id->get_error_message() );
		}

		if ( ! $post_id ) {
			failsafe_disaster_fail( 'could not create the marker post.' );
		}

		echo 'POST_ID=' . (int) $post_id . "\n";
		break;

	case 'backup':
		// Retention must not delete the site owner's backups during the test.
		add_filter( 'failsafe_retention_count', '__return_zero' );

		$backup = failsafe_create_backup();

		if ( is_wp_error( $backup ) ) {
			failsafe_disaster_fail( $backup->get_error_code() . ': ' . $backup->get_error_message() );
		}

		echo 'BACKUP_BASE=' . $backup['base'] . "\n";
		break;

	case 'delete':
		$post_id = failsafe_disaster_post_id( $argv[2] ?? '' );

		if ( ! wp_delete_post( $post_id, true ) ) {
			failsafe_disaster_fail( sprintf( 'could not delete post %d.', $post_id ) );
		}

		// The object cache may still hold the post that was just deleted.
		clean_post_cache( $post_id );

		if ( get_post( $post_id ) ) {
			failsafe_disaster_fail( sprintf( 'post %d is still present after deletion.', $post_id ) );
		}
		break;

	case 'restore':
		$base = (string) ( $argv[2] ?? '' );

		if ( ! failsafe_is_valid_backup_name( $base ) ) {
			failsafe_disaster_fail( sprintf( 'invalid backup name "%s".', $base ) );
		}

		$sql_path = failsafe_backup_path( $base, 'sql' );

		if ( is_wp_error( $sql_path ) ) {
			failsafe_disaster_fail( $sql_path->get_error_message() );
		}

		if ( ! is_readable( $sql_path ) ) {
			failsafe_disaster_fail( sprintf( 'the SQL dump %s cannot be read.', $sql_path ) );
		}

		$sql = file_get_contents( $sql_path );

		if ( false === $sql ) {
			failsafe_disaster_fail( sprintf( 'could not read %s.', $sql_path ) );
		}

		$restored = failsafe_restore_database( $sql );

		if ( is_wp_error( $restored ) ) {
			failsafe_disaster_fail( $restored->get_error_code() . ': ' . $restored->get_error_message() );
		}
		break;

	case 'verify':
		$post_id = failsafe_disaster_post_id( $argv[2] ?? '' );
		$title   = (string) ( $argv[3] ?? '' );
		$post    = get_post( $post_id );

		if ( ! $post ) {
			failsafe_disaster_fail( sprintf( 'post %d did not come back after the restore.', $post_id ) );
		}

		if ( $post->post_title !== $title ) {
			failsafe_disaster_fail(
				sprintf(
					'post %d came back with title "%s" instead of "%s".',
					$post_id,
					$post->post_title,
					$title
				)
			);
		}
		break;

	case 'check':
		$base = (string) ( $argv[2] ?? '' );

		if ( ! failsafe_is_valid_backup_name( $base ) ) {
			failsafe_disaster_fail( sprintf( 'invalid backup name "%s".', $base ) );
		}

		foreach ( array( 'sql', 'zip' ) as $extension ) {
			$path = failsafe_backup_path( $base, $extension );

			if ( is_wp_error( $path ) ) {
				failsafe_disaster_fail( $path->get_error_message() );
			}

			clearstatcache( true, $path );

			if ( ! is_readable( $path ) || 0 >= (int) filesize( $path ) ) {
				failsafe_disaster_fail( sprintf( 'the backup file %s is missing or empty.', $path ) );
			}
		}
		break;

	case 'cleanup':
		$post_id = failsafe_disaster_post_id( $argv[2] ?? '' );

		if ( ! get_post( $post_id ) ) {
			break;
		}

		if ( ! wp_delete_post( $post_id, true ) ) {
			failsafe_disaster_fail( sprintf( 'could not delete post %d.', $post_id ) );
		}
		break;

	default:
		failsafe_disaster_fail( 'unknown command. See the file header for the available commands.' );
}
