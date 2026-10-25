<?php
/**
 * Unit checks for the storage layer: names, paths, listing, retention and atomic writes.
 *
 * Run inside the WordPress container:
 *   php /var/www/html/wp-content/plugins/failsafe-backup/tests/test-storage.php
 *
 * @package Failsafe
 */

require '/var/www/html/wp-load.php';
require __DIR__ . '/assert.php';

$GLOBALS['failsafe_storage_test_files'] = array();
$GLOBALS['failsafe_storage_test_dirs']  = array();

$failsafe_original_retention = get_option( 'failsafe_retention_count', '__failsafe_missing__' );

$GLOBALS['failsafe_storage_test_retention'] = array(
	'exists'   => '__failsafe_missing__' !== $failsafe_original_retention,
	'value'    => $failsafe_original_retention,
	'restored' => false,
);

unset( $failsafe_original_retention );

/**
 * Deletes every file created by the test.
 *
 * @return void
 */
function failsafe_test_cleanup_files() {
	foreach ( (array) $GLOBALS['failsafe_storage_test_files'] as $file ) {
		if ( file_exists( $file ) ) {
			@unlink( $file );
		}
	}

	$GLOBALS['failsafe_storage_test_files'] = array();
}

/**
 * Deletes every temporary directory created by the test.
 *
 * @return void
 */
function failsafe_test_cleanup_dirs() {
	foreach ( (array) $GLOBALS['failsafe_storage_test_dirs'] as $dir ) {
		if ( ! is_dir( $dir ) ) {
			continue;
		}

		foreach ( (array) glob( $dir . '/*' ) as $file ) {
			if ( is_file( $file ) ) {
				@unlink( $file );
			}
		}

		@rmdir( $dir );
	}

	$GLOBALS['failsafe_storage_test_dirs'] = array();
}

/**
 * Puts the retention option back to the value it had before the test.
 *
 * @return void
 */
function failsafe_test_restore_retention() {
	$state = $GLOBALS['failsafe_storage_test_retention'];

	if ( ! empty( $state['restored'] ) ) {
		return;
	}

	if ( $state['exists'] ) {
		update_option( 'failsafe_retention_count', $state['value'] );
	} else {
		delete_option( 'failsafe_retention_count' );
	}

	$GLOBALS['failsafe_storage_test_retention']['restored'] = true;
}

/**
 * Cleans up even when a test fails and exits early.
 *
 * @return void
 */
function failsafe_test_shutdown() {
	failsafe_test_cleanup_files();
	failsafe_test_cleanup_dirs();
	failsafe_test_restore_retention();
}

register_shutdown_function( 'failsafe_test_shutdown' );

/**
 * Writes a file, sets its modification time and tracks it for cleanup.
 *
 * @param string $path     Absolute file path.
 * @param string $contents File contents.
 * @param int    $mtime    Modification time as a Unix timestamp.
 * @return true|WP_Error
 */
function failsafe_test_write_file( $path, $contents, $mtime ) {
	if ( false === file_put_contents( $path, $contents ) ) {
		return new WP_Error( 'failsafe_test_write_failed', 'Could not write ' . $path . '.' );
	}

	if ( ! touch( $path, $mtime ) ) {
		@unlink( $path );

		return new WP_Error( 'failsafe_test_touch_failed', 'Could not set the modification time of ' . $path . '.' );
	}

	$GLOBALS['failsafe_storage_test_files'][] = $path;

	clearstatcache( true, $path );

	return true;
}

/**
 * Creates both files of a backup set.
 *
 * @param string      $directory     Directory that holds the files.
 * @param string      $base          Backup base name.
 * @param int         $mtime         Modification time as a Unix timestamp.
 * @param string      $zip_contents  ZIP file contents.
 * @param string|null $sql_contents  SQL file contents, or null to leave the set incomplete.
 * @return true|WP_Error
 */
function failsafe_test_create_set( $directory, $base, $mtime, $zip_contents, $sql_contents ) {
	$created = failsafe_test_write_file( $directory . '/' . $base . '.zip', $zip_contents, $mtime );

	if ( is_wp_error( $created ) ) {
		return $created;
	}

	if ( null === $sql_contents ) {
		return true;
	}

	return failsafe_test_write_file( $directory . '/' . $base . '.sql', $sql_contents, $mtime );
}

/**
 * Creates an isolated temporary directory and tracks it for cleanup.
 *
 * @return string|WP_Error
 */
function failsafe_test_temp_dir() {
	$directory = rtrim( sys_get_temp_dir(), '/\\' ) . '/failsafe-storage-' . getmypid() . '-' . uniqid();

	if ( ! mkdir( $directory, 0700 ) ) {
		return new WP_Error( 'failsafe_test_mkdir_failed', 'Could not create ' . $directory . '.' );
	}

	$GLOBALS['failsafe_storage_test_dirs'][] = $directory;

	return $directory;
}

echo "failsafe_is_valid_backup_name()\n";

failsafe_assert( failsafe_is_valid_backup_name( 'failsafe-20261024-153000' ), 'accepts a plain base name' );
failsafe_assert( failsafe_is_valid_backup_name( 'failsafe-20261024-153000-2' ), 'accepts a duplicate suffix' );

$failsafe_invalid_names = array(
	'../x'                                             => 'path traversal',
	'failsafe-2026-153000'                             => 'a short date',
	''                                                 => 'an empty string',
	'/var/www/html/failsafe-20261024-153000'           => 'an absolute path',
	'failsafe-20261024-153000-' . str_repeat( '9', 20 ) => 'a name longer than 40 characters',
	'failsafe-20261024-153000.zip'                     => 'a file extension',
);

foreach ( $failsafe_invalid_names as $failsafe_name => $failsafe_reason ) {
	failsafe_assert( ! failsafe_is_valid_backup_name( $failsafe_name ), 'rejects ' . $failsafe_reason );
}

unset( $failsafe_invalid_names, $failsafe_name, $failsafe_reason );

echo "\nfailsafe_backup_path()\n";

$failsafe_base      = 'failsafe-20261024-153000';
$failsafe_directory = rtrim( failsafe_backup_dir(), '/\\' );

foreach ( array( 'zip', 'sql' ) as $failsafe_extension ) {
	$failsafe_path = failsafe_backup_path( $failsafe_base, $failsafe_extension );

	failsafe_assert( ! is_wp_error( $failsafe_path ), 'accepts the ' . $failsafe_extension . ' extension' );
	failsafe_assert(
		is_string( $failsafe_path ) && $failsafe_directory . '/' . $failsafe_base . '.' . $failsafe_extension === $failsafe_path,
		'builds the ' . $failsafe_extension . ' path inside the backup directory'
	);
}

$failsafe_invalid_path = failsafe_backup_path( '../x', 'zip' );

failsafe_assert( is_wp_error( $failsafe_invalid_path ), 'returns WP_Error for an invalid name' );
failsafe_assert(
	is_wp_error( $failsafe_invalid_path ) && 'failsafe_invalid_backup' === $failsafe_invalid_path->get_error_code(),
	'uses the invalid backup error code'
);

foreach ( array( 'php', 'txt', 'zip.php', '' ) as $failsafe_extension ) {
	$failsafe_path = failsafe_backup_path( $failsafe_base, $failsafe_extension );

	failsafe_assert( is_wp_error( $failsafe_path ), 'returns WP_Error for the "' . $failsafe_extension . '" extension' );
}

unset( $failsafe_base, $failsafe_directory, $failsafe_extension, $failsafe_path, $failsafe_invalid_path );

echo "\nfailsafe_get_backups()\n";

$failsafe_directory = rtrim( failsafe_backup_dir(), '/\\' );

failsafe_assert( is_dir( $failsafe_directory ), 'backup directory exists' );
failsafe_assert( false !== failsafe_fs(), 'initializes the WordPress filesystem' );

$failsafe_newest     = 'failsafe-19990101-000004';
$failsafe_middle     = 'failsafe-19990101-000003';
$failsafe_incomplete = 'failsafe-19990101-000002';
$failsafe_empty_zip  = 'failsafe-19990101-000001';
$failsafe_mtime      = 1000000000;

$failsafe_sets = array(
	$failsafe_newest     => array( str_repeat( 'z', 32 ), str_repeat( 's', 8 ), $failsafe_mtime + 400 ),
	$failsafe_middle     => array( str_repeat( 'z', 16 ), str_repeat( 's', 4 ), $failsafe_mtime + 300 ),
	$failsafe_incomplete => array( str_repeat( 'z', 12 ), null, $failsafe_mtime + 200 ),
	$failsafe_empty_zip  => array( '', str_repeat( 's', 3 ), $failsafe_mtime + 100 ),
);

foreach ( $failsafe_sets as $failsafe_base => $failsafe_set ) {
	list( $failsafe_zip_contents, $failsafe_sql_contents, $failsafe_set_mtime ) = $failsafe_set;

	$failsafe_created = failsafe_test_create_set(
		$failsafe_directory,
		$failsafe_base,
		$failsafe_set_mtime,
		$failsafe_zip_contents,
		$failsafe_sql_contents
	);

	failsafe_assert( ! is_wp_error( $failsafe_created ), 'creates the files for ' . $failsafe_base );
}

$failsafe_backups = failsafe_get_backups();

failsafe_assert( is_array( $failsafe_backups ), 'returns an array' );

foreach ( array_keys( $failsafe_sets ) as $failsafe_base ) {
	failsafe_assert( isset( $failsafe_backups[ $failsafe_base ] ), 'lists the set ' . $failsafe_base );
}

failsafe_assert(
	$failsafe_newest . '.zip' === $failsafe_backups[ $failsafe_newest ]['zip']['name'],
	'groups the zip file under its base name'
);
failsafe_assert(
	$failsafe_newest . '.sql' === $failsafe_backups[ $failsafe_newest ]['sql']['name'],
	'groups the sql file under its base name'
);
failsafe_assert( true === $failsafe_backups[ $failsafe_newest ]['complete'], 'marks a set with zip and sql as complete' );
failsafe_assert( true === $failsafe_backups[ $failsafe_middle ]['complete'], 'marks another complete set as complete' );
failsafe_assert( false === $failsafe_backups[ $failsafe_incomplete ]['complete'], 'marks a set without sql as incomplete' );
failsafe_assert( false === $failsafe_backups[ $failsafe_empty_zip ]['complete'], 'marks a set with an empty zip as incomplete' );

foreach ( $failsafe_sets as $failsafe_base => $failsafe_set ) {
	$failsafe_expected_size = strlen( $failsafe_set[0] );

	if ( null !== $failsafe_set[1] ) {
		$failsafe_expected_size += strlen( $failsafe_set[1] );
	}

	failsafe_assert(
		$failsafe_expected_size === $failsafe_backups[ $failsafe_base ]['size'],
		'sums the size of both files for ' . $failsafe_base
	);
}

$failsafe_expected_order = array_keys( $failsafe_sets );
$failsafe_actual_order   = array_values( array_intersect( array_keys( $failsafe_backups ), $failsafe_expected_order ) );

failsafe_assert( $failsafe_expected_order === $failsafe_actual_order, 'lists the sets newest first' );

$failsafe_previous   = null;
$failsafe_descending = true;

foreach ( $failsafe_backups as $failsafe_backup ) {
	if ( null !== $failsafe_previous && $failsafe_backup['time'] > $failsafe_previous ) {
		$failsafe_descending = false;
	}

	$failsafe_previous = (int) $failsafe_backup['time'];
}

failsafe_assert( $failsafe_descending, 'keeps every listed set in descending time order' );

failsafe_test_cleanup_files();

foreach ( array_keys( $failsafe_sets ) as $failsafe_base ) {
	failsafe_assert(
		! file_exists( $failsafe_directory . '/' . $failsafe_base . '.zip' )
		&& ! file_exists( $failsafe_directory . '/' . $failsafe_base . '.sql' ),
		'removes the temporary files of ' . $failsafe_base
	);
}

unset(
	$failsafe_directory,
	$failsafe_newest,
	$failsafe_middle,
	$failsafe_incomplete,
	$failsafe_empty_zip,
	$failsafe_mtime,
	$failsafe_sets,
	$failsafe_set,
	$failsafe_base,
	$failsafe_zip_contents,
	$failsafe_sql_contents,
	$failsafe_set_mtime,
	$failsafe_created,
	$failsafe_backups,
	$failsafe_expected_size,
	$failsafe_expected_order,
	$failsafe_actual_order,
	$failsafe_previous,
	$failsafe_descending,
	$failsafe_backup
);

echo "\nfailsafe_apply_retention()\n";

$failsafe_oldest     = 'failsafe-19990201-000001';
$failsafe_second     = 'failsafe-19990201-000002';
$failsafe_third      = 'failsafe-19990201-000003';
$failsafe_newest     = 'failsafe-19990201-000004';
$failsafe_mtime      = 1000001000;

$failsafe_retention_dir = failsafe_test_temp_dir();

failsafe_assert( ! is_wp_error( $failsafe_retention_dir ), 'creates a temporary backup directory' );

if ( ! is_wp_error( $failsafe_retention_dir ) ) {
	$failsafe_retention_filter = static function ( $directory ) use ( $failsafe_retention_dir ) {
		return $failsafe_retention_dir;
	};

	add_filter( 'failsafe_backup_dir', $failsafe_retention_filter );
	update_option( 'failsafe_retention_count', 2 );

	foreach ( array( $failsafe_oldest, $failsafe_second, $failsafe_third, $failsafe_newest ) as $failsafe_index => $failsafe_base ) {
		$failsafe_created = failsafe_test_create_set(
			$failsafe_retention_dir,
			$failsafe_base,
			$failsafe_mtime + ( 100 * $failsafe_index ),
			'zip:' . $failsafe_base,
			'sql:' . $failsafe_base
		);

		failsafe_assert( ! is_wp_error( $failsafe_created ), 'creates the set ' . $failsafe_base );
	}

	$failsafe_deleted = failsafe_apply_retention( $failsafe_newest );

	failsafe_assert( is_array( $failsafe_deleted ), 'returns the deleted base names' );

	$failsafe_expected_deleted = array( $failsafe_oldest, $failsafe_second );
	$failsafe_actual_deleted   = is_array( $failsafe_deleted ) ? $failsafe_deleted : array();

	sort( $failsafe_expected_deleted );
	sort( $failsafe_actual_deleted );

	failsafe_assert( $failsafe_expected_deleted === $failsafe_actual_deleted, 'deletes the two oldest sets' );
	failsafe_assert( file_exists( $failsafe_retention_dir . '/' . $failsafe_newest . '.zip' ), 'keeps the keep_base set' );
	failsafe_assert( ! file_exists( $failsafe_retention_dir . '/' . $failsafe_oldest . '.zip' ), 'removes the oldest zip file' );
	failsafe_assert( ! file_exists( $failsafe_retention_dir . '/' . $failsafe_second . '.sql' ), 'removes the second oldest sql file' );

	remove_filter( 'failsafe_backup_dir', $failsafe_retention_filter );
	failsafe_test_cleanup_files();
	failsafe_test_cleanup_dirs();
}

$failsafe_protected_dir = failsafe_test_temp_dir();

failsafe_assert( ! is_wp_error( $failsafe_protected_dir ), 'creates a second temporary backup directory' );

if ( ! is_wp_error( $failsafe_protected_dir ) ) {
	$failsafe_protected_filter = static function ( $directory ) use ( $failsafe_protected_dir ) {
		return $failsafe_protected_dir;
	};

	add_filter( 'failsafe_backup_dir', $failsafe_protected_filter );
	update_option( 'failsafe_retention_count', 1 );

	foreach ( array( $failsafe_oldest, $failsafe_second, $failsafe_third, $failsafe_newest ) as $failsafe_index => $failsafe_base ) {
		$failsafe_created = failsafe_test_create_set(
			$failsafe_protected_dir,
			$failsafe_base,
			$failsafe_mtime + ( 100 * $failsafe_index ),
			'zip:' . $failsafe_base,
			'sql:' . $failsafe_base
		);

		failsafe_assert( ! is_wp_error( $failsafe_created ), 'creates the protected set ' . $failsafe_base );
	}

	$failsafe_deleted = failsafe_apply_retention( $failsafe_oldest );

	$failsafe_expected_deleted = array( $failsafe_second, $failsafe_third );
	$failsafe_actual_deleted   = is_array( $failsafe_deleted ) ? $failsafe_deleted : array();

	sort( $failsafe_expected_deleted );
	sort( $failsafe_actual_deleted );

	failsafe_assert( $failsafe_expected_deleted === $failsafe_actual_deleted, 'deletes the sets beyond the limit' );
	failsafe_assert( file_exists( $failsafe_protected_dir . '/' . $failsafe_oldest . '.sql' ), 'never deletes the keep_base set' );

	remove_filter( 'failsafe_backup_dir', $failsafe_protected_filter );
	failsafe_test_cleanup_files();
	failsafe_test_cleanup_dirs();
}

failsafe_test_restore_retention();

$failsafe_restored = get_option( 'failsafe_retention_count', '__failsafe_missing__' );

failsafe_assert(
	$GLOBALS['failsafe_storage_test_retention']['value'] === $failsafe_restored,
	'restores the original retention option'
);

unset(
	$failsafe_oldest,
	$failsafe_second,
	$failsafe_third,
	$failsafe_newest,
	$failsafe_mtime,
	$failsafe_retention_dir,
	$failsafe_retention_filter,
	$failsafe_protected_dir,
	$failsafe_protected_filter,
	$failsafe_index,
	$failsafe_base,
	$failsafe_created,
	$failsafe_deleted,
	$failsafe_expected_deleted,
	$failsafe_actual_deleted,
	$failsafe_restored
);

echo "\nfailsafe_write_file_atomic()\n";

$failsafe_directory = rtrim( failsafe_backup_dir(), '/\\' );
$failsafe_path      = $failsafe_directory . '/failsafe-write-atomic-' . getmypid() . '.tmp';
$failsafe_contents  = "failsafe atomic write\n";
$failsafe_bytes     = failsafe_write_file_atomic( $failsafe_path, $failsafe_contents );

failsafe_assert( ! is_wp_error( $failsafe_bytes ), 'returns no WP_Error' );
failsafe_assert( is_int( $failsafe_bytes ) && strlen( $failsafe_contents ) === $failsafe_bytes, 'returns the number of bytes written' );
failsafe_assert( file_exists( $failsafe_path ), 'writes the destination file' );
failsafe_assert( $failsafe_contents === file_get_contents( $failsafe_path ), 'writes the exact contents' );
failsafe_assert( ! file_exists( $failsafe_path . '.part' ), 'leaves no .part file behind' );

$GLOBALS['failsafe_storage_test_files'][] = $failsafe_path;

failsafe_test_cleanup_files();

failsafe_assert( ! file_exists( $failsafe_path ), 'removes the temporary file' );

unset( $failsafe_directory, $failsafe_path, $failsafe_contents, $failsafe_bytes );

failsafe_report();
