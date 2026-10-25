<?php
/**
 * Storage layer: backup names, listing, deletion and retention.
 *
 * Every backup is a set of two files sharing a base name:
 *   failsafe-YYYYMMDD-HHMMSS.zip  (site files + the SQL dump)
 *   failsafe-YYYYMMDD-HHMMSS.sql  (database dump on its own)
 *
 * @package Failsafe
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'FAILSAFE_RETENTION_DEFAULT' ) ) {
	define( 'FAILSAFE_RETENTION_DEFAULT', 5 );
}

/**
 * Directory that holds the backups.
 *
 * @return string
 */
function failsafe_backup_dir() {
	return (string) apply_filters( 'failsafe_backup_dir', FAILSAFE_BACKUP_DIR );
}

/**
 * Shared WP_Filesystem instance rooted at the backup directory.
 *
 * @return WP_Filesystem_Base|false False when the host requires credentials.
 */
function failsafe_fs() {
	static $filesystem = null;

	if ( null !== $filesystem ) {
		return $filesystem;
	}

	require_once ABSPATH . 'wp-admin/includes/file.php';

	$filesystem = WP_Filesystem( false, failsafe_backup_dir(), true ) ? $GLOBALS['wp_filesystem'] : false;

	return $filesystem;
}

/**
 * Validates a backup base name coming from a request.
 *
 * @param string $name Candidate name.
 * @return bool
 */
function failsafe_is_valid_backup_name( $name ) {
	if ( ! is_string( $name ) || strlen( $name ) > 40 ) {
		return false;
	}

	return 1 === preg_match( '/^failsafe-[0-9]{8}-[0-9]{6}(-[0-9]{1,2})?$/', $name );
}

/**
 * Builds the absolute path of one file of a backup set.
 *
 * @param string $base Backup base name.
 * @param string $ext  Either "zip" or "sql".
 * @return string|WP_Error
 */
function failsafe_backup_path( $base, $ext ) {
	if ( ! failsafe_is_valid_backup_name( $base ) || ! in_array( $ext, array( 'zip', 'sql' ), true ) ) {
		return new WP_Error( 'failsafe_invalid_backup', __( 'Invalid backup name.', 'failsafe' ) );
	}

	return failsafe_backup_dir() . '/' . $base . '.' . $ext;
}

/**
 * Lists every backup set, newest first.
 *
 * @return array<string,array>
 */
function failsafe_get_backups() {
	$filesystem = failsafe_fs();
	$directory  = failsafe_backup_dir();

	if ( ! $filesystem || ! $filesystem->is_dir( $directory ) ) {
		return array();
	}

	clearstatcache( true, $directory );

	$entries = $filesystem->dirlist( $directory, false );
	$sets    = array();

	if ( ! is_array( $entries ) ) {
		return array();
	}

	foreach ( $entries as $filename => $entry ) {
		if ( ! preg_match( '/^(failsafe-[0-9]{8}-[0-9]{6}(?:-[0-9]{1,2})?)\.(zip|sql)$/', $filename, $matches ) ) {
			continue;
		}

		$base = $matches[1];
		$ext  = $matches[2];

		if ( ! isset( $sets[ $base ] ) ) {
			$sets[ $base ] = array(
				'base'     => $base,
				'time'     => 0,
				'size'     => 0,
				'complete' => false,
				'zip'      => null,
				'sql'      => null,
			);
		}

		$sets[ $base ][ $ext ] = array(
			'name'  => $filename,
			'size'  => (int) $entry['size'],
			'mtime' => (int) $entry['lastmodunix'],
		);

		$sets[ $base ]['size'] += (int) $entry['size'];
	}

	foreach ( $sets as $base => $set ) {
		$reference = $set['zip'] ? $set['zip'] : $set['sql'];

		$sets[ $base ]['time']     = (int) $reference['mtime'];
		$sets[ $base ]['complete'] = null !== $set['zip'] && $set['zip']['size'] > 0 && null !== $set['sql'];
	}

	uasort(
		$sets,
		static function ( $a, $b ) {
			return $b['time'] <=> $a['time'];
		}
	);

	return $sets;
}

/**
 * Returns one backup set.
 *
 * @param string $base Backup base name.
 * @return array|WP_Error
 */
function failsafe_get_backup( $base ) {
	if ( ! failsafe_is_valid_backup_name( $base ) ) {
		return new WP_Error( 'failsafe_invalid_backup', __( 'Invalid backup name.', 'failsafe' ) );
	}

	$backups = failsafe_get_backups();

	if ( ! isset( $backups[ $base ] ) ) {
		return new WP_Error( 'failsafe_backup_not_found', __( 'Backup not found.', 'failsafe' ) );
	}

	return $backups[ $base ];
}

/**
 * Deletes both files of a backup set.
 *
 * @param string $base Backup base name.
 * @return true|WP_Error
 */
function failsafe_delete_backup( $base ) {
	$backup = failsafe_get_backup( $base );

	if ( is_wp_error( $backup ) ) {
		return $backup;
	}

	$filesystem = failsafe_fs();

	if ( ! $filesystem ) {
		return new WP_Error( 'failsafe_fs_unavailable', __( 'The filesystem is not writable by WordPress.', 'failsafe' ) );
	}

	$failed = array();

	foreach ( array( 'zip', 'sql' ) as $ext ) {
		if ( empty( $backup[ $ext ] ) ) {
			continue;
		}

		$path = failsafe_backup_path( $base, $ext );

		if ( is_wp_error( $path ) || ! $filesystem->delete( $path ) ) {
			$failed[] = $ext;
		}
	}

	if ( $failed ) {
		return new WP_Error(
			'failsafe_delete_partial',
			sprintf(
				/* translators: %s: comma separated file types. */
				__( 'Could not delete: %s.', 'failsafe' ),
				implode( ', ', $failed )
			)
		);
	}

	return true;
}

/**
 * Number of backups to keep (0 disables retention).
 *
 * @return int
 */
function failsafe_get_retention_count() {
	$count = (int) apply_filters(
		'failsafe_retention_count',
		(int) get_option( 'failsafe_retention_count', FAILSAFE_RETENTION_DEFAULT )
	);

	if ( $count < 0 ) {
		return 0;
	}

	return min( $count, 50 );
}

/**
 * Deletes backup sets beyond the retention limit.
 *
 * @param string $keep_base Backup created by the current run, never deleted.
 * @return string[] Base names that were deleted.
 */
function failsafe_apply_retention( $keep_base = '' ) {
	$limit = failsafe_get_retention_count();

	if ( 0 === $limit ) {
		return array();
	}

	$deleted = array();
	$kept    = 0;

	foreach ( failsafe_get_backups() as $base => $backup ) {
		if ( ! $backup['complete'] ) {
			continue;
		}

		$kept++;

		if ( $kept <= $limit || $base === $keep_base ) {
			continue;
		}

		$result = failsafe_delete_backup( $base );

		if ( is_wp_error( $result ) ) {
			failsafe_log( 'retention: ' . $base . ': ' . $result->get_error_message() );
			continue;
		}

		$deleted[] = $base;
	}

	return $deleted;
}

/**
 * Total size of every backup file on disk.
 *
 * @return int
 */
function failsafe_get_backups_total_size() {
	$total = 0;

	foreach ( failsafe_get_backups() as $backup ) {
		$total += (int) $backup['size'];
	}

	return $total;
}

/**
 * Writes a file atomically: temp file first, move into place last.
 *
 * @param string $path     Destination path.
 * @param string $contents File contents.
 * @return int|WP_Error Bytes written.
 */
function failsafe_write_file_atomic( $path, $contents ) {
	$temp = $path . '.part';

	$bytes = file_put_contents( $temp, $contents );

	if ( false === $bytes ) {
		return new WP_Error(
			'failsafe_write_failed',
			sprintf(
				/* translators: %s: file path. */
				__( 'Could not write %s.', 'failsafe' ),
				$temp
			)
		);
	}

	$filesystem = failsafe_fs();

	if ( ! $filesystem ) {
		wp_delete_file( $temp );

		return new WP_Error(
			'failsafe_fs_unavailable',
			__( 'The filesystem is not writable by WordPress.', 'failsafe' )
		);
	}

	if ( ! $filesystem->move( $temp, $path, true ) ) {
		wp_delete_file( $temp );

		return new WP_Error(
			'failsafe_rename_failed',
			sprintf(
				/* translators: %s: file path. */
				__( 'Could not move %s into place.', 'failsafe' ),
				$path
			)
		);
	}

	return $bytes;
}
