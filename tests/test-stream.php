<?php
/**
 * Streaming checks for the SQL dump writer and the restore reader.
 *
 * Run inside the WordPress container:
 *   php /tmp/failsafe-tests/test-stream.php
 *
 * The script creates a single temporary table, <prefix>failsafe_stream_test,
 * and drops it from a shutdown handler so a failed assertion never leaves
 * data behind. No real table is ever dropped.
 *
 * @package Failsafe
 */

require '/var/www/html/wp-load.php';
require __DIR__ . '/assert.php';

global $wpdb;

$GLOBALS['failsafe_test_stream_table'] = $wpdb->prefix . 'failsafe_stream_test';
$GLOBALS['failsafe_test_stream_files'] = array();

/**
 * Removes every temporary file and table created by the test.
 *
 * Registered as a shutdown function as well, so the table is dropped even when
 * an assertion aborts the script.
 *
 * @return void
 */
function failsafe_test_stream_cleanup() {
	global $wpdb;

	$table = (string) $GLOBALS['failsafe_test_stream_table'];

	if ( '' !== $table ) {
		// The table name is built from $wpdb->prefix plus a fixed suffix.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- test cleanup for a temporary table.
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) );
	}

	foreach ( (array) $GLOBALS['failsafe_test_stream_files'] as $file ) {
		if ( is_file( $file ) ) {
			@unlink( $file );
		}
	}

	$GLOBALS['failsafe_test_stream_files'] = array();
}

register_shutdown_function( 'failsafe_test_stream_cleanup' );

/**
 * Creates an empty temporary file and remembers it for cleanup.
 *
 * @return string|WP_Error Absolute path on success.
 */
function failsafe_test_stream_temp_file() {
	$path = tempnam( get_temp_dir(), 'failsafe-stream-test-' );

	if ( false === $path ) {
		return new WP_Error( 'failsafe_test_temp_file', 'Could not create a temporary file.' );
	}

	$GLOBALS['failsafe_test_stream_files'][] = $path;

	return $path;
}

/**
 * Tells whether the temporary table exists.
 *
 * @param string $table Table name.
 * @return bool
 */
function failsafe_test_stream_table_exists( $table ) {
	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- test inspection of the temporary table.
	return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
}

/**
 * Drops the temporary table before a test that recreates it.
 *
 * @param string $table Table name.
 * @return void
 */
function failsafe_test_stream_drop_table( $table ) {
	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- the only table the test is allowed to drop.
	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) );
	$wpdb->last_error = '';
}

/**
 * Reads one note column of the temporary table.
 *
 * @param string $table Table name.
 * @param int    $id    Row id.
 * @return string|null
 */
function failsafe_test_stream_note( $table, $id ) {
	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- test inspection of the temporary table.
	return $wpdb->get_var( $wpdb->prepare( 'SELECT `note` FROM %i WHERE `id` = %d', $table, $id ) );
}

/**
 * Hides the timestamp of a dump so two runs can be compared.
 *
 * @param string $sql Dump contents.
 * @return string
 */
function failsafe_test_stream_normalize_date( $sql ) {
	return (string) preg_replace( '/^-- Date: .*$/m', '-- Date: <normalized>', $sql );
}

/**
 * Fills the temporary table with a payload big enough to produce a 5 MB dump.
 *
 * @param string $table   Table name.
 * @param int    $rows    Number of rows.
 * @param string $payload Value stored in every row.
 * @return true|WP_Error True on success.
 */
function failsafe_test_stream_fill_table( $table, $rows, $payload ) {
	global $wpdb;

	$table_sql  = $wpdb->prepare( '%i', $table );
	$batch_size = 100;

	for ( $start = 1; $start <= $rows; $start += $batch_size ) {
		$values = array();
		$end    = min( $rows, $start + $batch_size - 1 );

		for ( $id = $start; $id <= $end; $id++ ) {
			$values[] = $wpdb->prepare( '(%d, %s)', $id, $payload );
		}

		$wpdb->last_error = '';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- bulk fixture for the streaming test.
		$result = $wpdb->query( 'INSERT INTO ' . $table_sql . ' (`id`, `payload`) VALUES ' . implode( ', ', $values ) );

		if ( false === $result || '' !== $wpdb->last_error ) {
			return new WP_Error(
				'failsafe_test_fill_failed',
				'' !== $wpdb->last_error ? $wpdb->last_error : 'The fixture INSERT failed.'
			);
		}
	}

	return true;
}

$missing_functions = array();

foreach ( array( 'failsafe_write_sql_dump', 'failsafe_build_sql_dump', 'failsafe_restore_database_from_file' ) as $failsafe_function ) {
	if ( ! function_exists( $failsafe_function ) ) {
		$missing_functions[] = $failsafe_function;
	}
}

if ( $missing_functions ) {
	echo "Missing contract functions: " . implode( ', ', $missing_functions ) . "\n";
	failsafe_assert( false, 'the streaming contract functions are available' );
	failsafe_report();
}

$failsafe_table    = $GLOBALS['failsafe_test_stream_table'];
$failsafe_table_id = '`' . $failsafe_table . '`';

echo "failsafe_write_sql_dump()\n";

$failsafe_dump_path = failsafe_test_stream_temp_file();

failsafe_assert( ! is_wp_error( $failsafe_dump_path ), 'a temporary path is available' );

if ( is_wp_error( $failsafe_dump_path ) ) {
	failsafe_report();
}

$failsafe_written = failsafe_write_sql_dump( $failsafe_dump_path );

failsafe_assert( ! is_wp_error( $failsafe_written ), 'the dump is written without a WP_Error' );

if ( is_wp_error( $failsafe_written ) ) {
	printf( "  info %s: %s\n", $failsafe_written->get_error_code(), $failsafe_written->get_error_message() );
	failsafe_report();
}

$failsafe_size = (int) filesize( $failsafe_dump_path );

failsafe_assert( is_int( $failsafe_written ) && $failsafe_written > 0, 'returns a positive byte count' );
failsafe_assert( $failsafe_size > 0, 'the dump file is not empty' );
failsafe_assert( $failsafe_written === $failsafe_size, 'the returned byte count matches the file size' );

$failsafe_stream = (string) file_get_contents( $failsafe_dump_path );

failsafe_assert( str_starts_with( $failsafe_stream, '-- Failsafe Backup' ), 'the file starts with the dump header' );
failsafe_assert( str_contains( $failsafe_stream, "-- Site: " . home_url() . "\n" ), 'the header names the site' );
failsafe_assert( 1 === preg_match( '/^-- Date: \S+$/m', $failsafe_stream ), 'the header has a date line' );
failsafe_assert( str_contains( $failsafe_stream, "SET SESSION sql_mode = '';" ), 'the dump resets sql_mode' );
failsafe_assert( 1 === preg_match( '/^SET NAMES \S+/m', $failsafe_stream ), 'the dump declares the charset' );
failsafe_assert( str_contains( $failsafe_stream, 'SET FOREIGN_KEY_CHECKS = 0;' ), 'the dump disables foreign key checks' );
failsafe_assert( str_contains( $failsafe_stream, 'START TRANSACTION WITH CONSISTENT SNAPSHOT;' ), 'the dump opens a consistent snapshot' );
failsafe_assert( str_ends_with( rtrim( $failsafe_stream ), 'SET FOREIGN_KEY_CHECKS = 1;' ), 'the file ends by re-enabling foreign key checks' );
failsafe_assert( str_contains( $failsafe_stream, "\nCOMMIT;\n" ), 'the dump commits before finishing' );

$failsafe_tables = $wpdb->get_col( 'SHOW TABLES' );

failsafe_assert( ! $wpdb->last_error, 'the table list can be read' );
failsafe_assert(
	count( $failsafe_tables ) === substr_count( $failsafe_stream, 'CREATE TABLE ' ),
	'there is one CREATE TABLE per database table (' . count( $failsafe_tables ) . ')'
);

foreach ( $failsafe_tables as $failsafe_one_table ) {
	failsafe_assert(
		str_contains( $failsafe_stream, 'CREATE TABLE `' . $failsafe_one_table . '`' ),
		'CREATE TABLE present for ' . $failsafe_one_table
	);
	failsafe_assert(
		str_contains( $failsafe_stream, 'DROP TABLE IF EXISTS `' . $failsafe_one_table . '`' ),
		'DROP TABLE IF EXISTS present for ' . $failsafe_one_table
	);
}

echo "\nfailsafe_write_sql_dump() and failsafe_build_sql_dump() agree\n";

$failsafe_built = failsafe_build_sql_dump();

failsafe_assert( is_string( $failsafe_built ), 'the in-memory dump is a string' );

if ( is_string( $failsafe_built ) ) {
	failsafe_assert(
		failsafe_test_stream_normalize_date( $failsafe_stream ) === failsafe_test_stream_normalize_date( $failsafe_built ),
		'the file matches the in-memory dump except for the Date line'
	);
}

unset( $failsafe_stream, $failsafe_built );

echo "\nfailsafe_restore_database_from_file()\n";

failsafe_test_stream_drop_table( $failsafe_table );

failsafe_assert( ! failsafe_test_stream_table_exists( $failsafe_table ), 'the restore test starts without the temporary table' );

$failsafe_minimal = "-- Minimal dump for the streaming restore test\n"
	. "SET NAMES utf8mb4;\n"
	. "SET FOREIGN_KEY_CHECKS = 0;\n"
	. "START TRANSACTION WITH CONSISTENT SNAPSHOT;\n\n"
	. 'DROP TABLE IF EXISTS ' . $failsafe_table_id . ";\n"
	. 'CREATE TABLE ' . $failsafe_table_id . " (\n"
	. "  `id` bigint unsigned NOT NULL,\n"
	. "  `note` varchar(100) NOT NULL,\n"
	. "  PRIMARY KEY (`id`)\n"
	. ");\n\n"
	. 'INSERT INTO ' . $failsafe_table_id . " (`id`, `note`) VALUES (1, 'alpha'), (2, 'beta'), (3, 'gamma');\n\n"
	. "COMMIT;\n"
	. "SET FOREIGN_KEY_CHECKS = 1;\n";

$failsafe_minimal_path = failsafe_test_stream_temp_file();

if ( ! is_wp_error( $failsafe_minimal_path ) ) {
	failsafe_assert( false !== file_put_contents( $failsafe_minimal_path, $failsafe_minimal ), 'the minimal dump is written to /tmp' );
}

$failsafe_result = is_wp_error( $failsafe_minimal_path )
	? $failsafe_minimal_path
	: failsafe_restore_database_from_file( $failsafe_minimal_path );

failsafe_assert( true === $failsafe_result, 'the minimal dump is restored from a file' );

if ( is_wp_error( $failsafe_result ) ) {
	printf( "  info %s: %s\n", $failsafe_result->get_error_code(), $failsafe_result->get_error_message() );
}

failsafe_assert( failsafe_test_stream_table_exists( $failsafe_table ), 'the dump created the temporary table' );

$failsafe_rows = $wpdb->get_results( $wpdb->prepare( 'SELECT `id`, `note` FROM %i ORDER BY `id`', $failsafe_table ), ARRAY_A );

failsafe_assert( 3 === count( $failsafe_rows ), 'the dump inserted exactly three rows' );
failsafe_assert(
	array( 'alpha', 'beta', 'gamma' ) === array_column( (array) $failsafe_rows, 'note' ),
	'the three values were restored in order'
);

unset( $failsafe_rows );

echo "\nBlocked statement in a dump file\n";

$failsafe_note_before = failsafe_test_stream_note( $failsafe_table, 1 );
$failsafe_blocked     = "-- the only statement in this dump is forbidden\n"
	. 'UPDATE ' . $failsafe_table_id . " SET `note` = 'tampered' WHERE `id` = 1;\n";

$failsafe_blocked_path = failsafe_test_stream_temp_file();

if ( ! is_wp_error( $failsafe_blocked_path ) ) {
	failsafe_assert( false !== file_put_contents( $failsafe_blocked_path, $failsafe_blocked ), 'the blocked dump is written to /tmp' );
}

$failsafe_blocked_result = is_wp_error( $failsafe_blocked_path )
	? $failsafe_blocked_path
	: failsafe_restore_database_from_file( $failsafe_blocked_path );

failsafe_assert( is_wp_error( $failsafe_blocked_result ), 'a dump with UPDATE is rejected' );
failsafe_assert(
	is_wp_error( $failsafe_blocked_result ) && 'failsafe_restore_statement_blocked' === $failsafe_blocked_result->get_error_code(),
	'the rejection uses the failsafe_restore_statement_blocked code'
);
failsafe_assert(
	$failsafe_note_before === failsafe_test_stream_note( $failsafe_table, 1 ),
	'the blocked UPDATE did not change the row'
);
failsafe_assert(
	3 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $failsafe_table ) ),
	'the blocked dump did not run any other statement either'
);

unset( $failsafe_note_before, $failsafe_blocked_result );

echo "\n5 MB dump stays under 64 MB of memory\n";

failsafe_test_stream_drop_table( $failsafe_table );

$failsafe_create = $wpdb->query(
	$wpdb->prepare(
		'CREATE TABLE %i (`id` bigint unsigned NOT NULL, `payload` varchar(255) NOT NULL, PRIMARY KEY (`id`))',
		$failsafe_table
	)
);

failsafe_assert( false !== $failsafe_create, 'the bulk fixture table is created' );

$failsafe_row_count = 20000;
$failsafe_payload   = str_repeat( 'stream-payload-', 15 );
$failsafe_filled    = failsafe_test_stream_fill_table( $failsafe_table, $failsafe_row_count, $failsafe_payload );

failsafe_assert( true === $failsafe_filled, 'the bulk fixture rows are inserted' );

if ( is_wp_error( $failsafe_filled ) ) {
	printf( "  info %s: %s\n", $failsafe_filled->get_error_code(), $failsafe_filled->get_error_message() );
}

$failsafe_bulk_path = failsafe_test_stream_temp_file();

if ( function_exists( 'memory_reset_peak_usage' ) ) {
	memory_reset_peak_usage();
}

$failsafe_bulk_written = is_wp_error( $failsafe_bulk_path )
	? $failsafe_bulk_path
	: failsafe_write_sql_dump( $failsafe_bulk_path );
$failsafe_dump_peak    = memory_get_peak_usage( true );

failsafe_assert( ! is_wp_error( $failsafe_bulk_written ), 'the 5 MB dump is written' );

$failsafe_bulk_size = is_wp_error( $failsafe_bulk_path ) ? 0 : (int) filesize( $failsafe_bulk_path );

failsafe_assert(
	$failsafe_bulk_size >= 5 * 1024 * 1024,
	sprintf( 'the streamed dump is at least 5 MB (%s)', size_format( $failsafe_bulk_size, 2 ) )
);
failsafe_assert(
	$failsafe_dump_peak < 64 * 1024 * 1024,
	sprintf( 'writing the dump peaks below 64 MB (%.1f MB)', $failsafe_dump_peak / 1024 / 1024 )
);

if ( function_exists( 'memory_reset_peak_usage' ) ) {
	memory_reset_peak_usage();
}

$failsafe_bulk_result = is_wp_error( $failsafe_bulk_path )
	? $failsafe_bulk_path
	: failsafe_restore_database_from_file( $failsafe_bulk_path );
$failsafe_peak        = memory_get_peak_usage( true );

failsafe_assert( true === $failsafe_bulk_result, 'the 5 MB dump is restored from a file' );

if ( is_wp_error( $failsafe_bulk_result ) ) {
	printf( "  info %s: %s\n", $failsafe_bulk_result->get_error_code(), $failsafe_bulk_result->get_error_message() );
}

failsafe_assert(
	$failsafe_row_count === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $failsafe_table ) ),
	'all 20000 fixture rows are restored'
);
failsafe_assert(
	$failsafe_peak < 64 * 1024 * 1024,
	sprintf( 'restoring the dump peaks below 64 MB (%.1f MB)', $failsafe_peak / 1024 / 1024 )
);

failsafe_test_stream_cleanup();

failsafe_assert( ! failsafe_test_stream_table_exists( $failsafe_table ), 'the temporary table is removed' );

unset(
	$failsafe_table,
	$failsafe_table_id,
	$failsafe_dump_path,
	$failsafe_written,
	$failsafe_size,
	$failsafe_stream,
	$failsafe_tables,
	$failsafe_one_table,
	$failsafe_minimal,
	$failsafe_minimal_path,
	$failsafe_result,
	$failsafe_blocked,
	$failsafe_blocked_path,
	$failsafe_create,
	$failsafe_payload,
	$failsafe_filled,
	$failsafe_bulk_path,
	$failsafe_bulk_written,
	$failsafe_dump_peak,
	$failsafe_bulk_size,
	$failsafe_bulk_result,
	$failsafe_peak,
	$failsafe_function,
	$failsafe_missing_functions
);

failsafe_report();
