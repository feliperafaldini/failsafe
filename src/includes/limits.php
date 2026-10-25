<?php
/**
 * Pre-flight checks: memory, disk space and maximum execution time.
 *
 * The dump is built in memory, so a database that fits on disk may still
 * exhaust the PHP memory limit or the request timeout. These helpers estimate
 * the cost before any file is written and fail with a message the site owner
 * can act on.
 *
 * @package Failsafe
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'FAILSAFE_LIMIT_SAFETY_FACTOR' ) ) {
	// Memory assumed per byte of database: the dump text plus the row copies.
	define( 'FAILSAFE_LIMIT_SAFETY_FACTOR', 3 );
}

if ( ! defined( 'FAILSAFE_DB_SIZE_CACHE_TTL' ) ) {
	// Seconds the table sizes are trusted before information_schema is queried again.
	define( 'FAILSAFE_DB_SIZE_CACHE_TTL', 600 );
}

if ( ! defined( 'FAILSAFE_DISK_SAFETY_FACTOR' ) ) {
	// Disk assumed per byte of database: the SQL dump and the ZIP archive.
	define( 'FAILSAFE_DISK_SAFETY_FACTOR', 2 );
}

if ( ! defined( 'FAILSAFE_DISK_MARGIN_BYTES' ) ) {
	// Extra room for the archive index and the temporary ".part" file.
	define( 'FAILSAFE_DISK_MARGIN_BYTES', 16 * 1024 * 1024 );
}

if ( ! defined( 'FAILSAFE_BACKUP_BASE_SECONDS' ) ) {
	// Fixed cost of scanning the site and opening the archive.
	define( 'FAILSAFE_BACKUP_BASE_SECONDS', 5 );
}

if ( ! defined( 'FAILSAFE_DB_BYTES_PER_SECOND' ) ) {
	// Assumed dump throughput, conservative for the local container.
	define( 'FAILSAFE_DB_BYTES_PER_SECOND', 2 * 1024 * 1024 );
}

/**
 * Converts a php.ini size expression into bytes.
 *
 * Accepts plain bytes, the K/M/G/T/P suffixes and "-1", which PHP uses to
 * mean "no limit". An empty or unparsable value returns 0 so the caller can
 * treat it as "unknown" instead of blocking the backup.
 *
 * @param string|int $value Value as returned by ini_get() or a literal size.
 * @return int Bytes, -1 for no limit, 0 for unknown.
 */
function failsafe_parse_size( $value ) {
	if ( is_int( $value ) ) {
		return ( 0 > $value ) ? -1 : $value;
	}

	$value = trim( (string) $value );

	if ( '' === $value ) {
		return 0;
	}

	if ( '-1' === $value ) {
		return -1;
	}

	if ( 1 !== preg_match( '/^([0-9]+(?:\.[0-9]+)?)\s*([KMGTP]?)B?$/i', $value, $matches ) ) {
		return 0;
	}

	$units = array(
		''  => 0,
		'K' => 1,
		'M' => 2,
		'G' => 3,
		'T' => 4,
		'P' => 5,
	);

	$unit  = strtoupper( $matches[2] );
	$power = isset( $units[ $unit ] ) ? $units[ $unit ] : 0;

	return (int) round( (float) $matches[1] * ( 1024 ** $power ) );
}

/**
 * Memory the dump needs for a database of the given size.
 *
 * @param int        $database_bytes Estimated database size in bytes.
 * @param float|null $factor         Multiplier; defaults to FAILSAFE_LIMIT_SAFETY_FACTOR.
 * @return int Bytes.
 */
function failsafe_required_memory_bytes( $database_bytes, $factor = null ) {
	if ( null === $factor ) {
		$factor = FAILSAFE_LIMIT_SAFETY_FACTOR;
	}

	$factor = max( 1, (float) $factor );

	return (int) ceil( max( 0, (int) $database_bytes ) * $factor );
}

/**
 * Free disk space required to write a database of the given size.
 *
 * The dump and the archive live side by side until retention removes the old
 * backup, hence the multiplier, plus a fixed margin for archive overhead.
 *
 * @param int $database_bytes Estimated database size in bytes.
 * @return int Bytes.
 */
function failsafe_required_disk_bytes( $database_bytes ) {
	$database_bytes = max( 0, (int) $database_bytes );

	return (int) ( $database_bytes * FAILSAFE_DISK_SAFETY_FACTOR ) + FAILSAFE_DISK_MARGIN_BYTES;
}

/**
 * Estimated duration of a backup run for a database of the given size.
 *
 * @param int $database_bytes Estimated database size in bytes.
 * @return int Seconds, never less than FAILSAFE_BACKUP_BASE_SECONDS.
 */
function failsafe_estimate_backup_seconds( $database_bytes ) {
	$database_bytes = max( 0, (int) $database_bytes );
	$throughput     = ( FAILSAFE_DB_BYTES_PER_SECOND > 0 ) ? FAILSAFE_DB_BYTES_PER_SECOND : 1;
	$seconds        = FAILSAFE_BACKUP_BASE_SECONDS + (int) ceil( $database_bytes / $throughput );

	return max( FAILSAFE_BACKUP_BASE_SECONDS, $seconds );
}

/**
 * Free space of the filesystem that holds a directory.
 *
 * @param string $directory Absolute path.
 * @return int Bytes, or -1 when the value cannot be read.
 */
function failsafe_available_disk_bytes( $directory ) {
	if ( ! is_string( $directory ) || '' === $directory || ! is_dir( $directory ) ) {
		return -1;
	}

	if ( ! function_exists( 'disk_free_space' ) ) {
		return -1;
	}

	// The call raises a warning on some virtual filesystems; -1 means "unknown".
	$free = @disk_free_space( $directory );

	if ( false === $free ) {
		return -1;
	}

	return max( 0, (int) $free );
}

/**
 * Estimated size of the current database.
 *
 * Sums DATA_LENGTH and INDEX_LENGTH of every table in the current schema and
 * caches the result in an option for FAILSAFE_DB_SIZE_CACHE_TTL seconds.
 *
 * @return int|WP_Error Bytes on success.
 */
function failsafe_estimate_database_size() {
	global $wpdb;

	$cached = get_option( 'failsafe_db_size_cache' );

	if ( is_array( $cached ) && isset( $cached['bytes'], $cached['time'] ) ) {
		$age = time() - (int) $cached['time'];

		if ( FAILSAFE_DB_SIZE_CACHE_TTL > $age ) {
			return max( 0, (int) $cached['bytes'] );
		}
	}

	if ( ! is_object( $wpdb ) ) {
		return new WP_Error(
			'failsafe_no_database',
			__( 'The database connection is not available.', 'failsafe' )
		);
	}

	$wpdb->last_error = '';

	$bytes = $wpdb->get_var(
		$wpdb->prepare(
			'SELECT COALESCE( SUM( DATA_LENGTH + INDEX_LENGTH ), 0 )
			FROM information_schema.TABLES
			WHERE TABLE_SCHEMA = %s',
			$wpdb->dbname
		)
	);

	if ( $wpdb->last_error ) {
		return new WP_Error( 'failsafe_db_size_query_failed', $wpdb->last_error );
	}

	$bytes = max( 0, (int) $bytes );

	update_option(
		'failsafe_db_size_cache',
		array(
			'bytes' => $bytes,
			'time'  => time(),
		),
		false
	);

	return $bytes;
}

/**
 * Drops the cached database size so the next run measures the tables again.
 *
 * @return void
 */
function failsafe_clear_database_size_cache() {
	delete_option( 'failsafe_db_size_cache' );
}

/**
 * Checks that the server can hold the dump before the backup starts.
 *
 * @return true|WP_Error True when every limit has room, WP_Error otherwise.
 */
function failsafe_check_limits() {
	$database_bytes = failsafe_estimate_database_size();

	if ( is_wp_error( $database_bytes ) ) {
		return $database_bytes;
	}

	$database_bytes = (int) $database_bytes;

	$memory_limit  = failsafe_parse_size( ini_get( 'memory_limit' ) );
	$memory_needed = failsafe_required_memory_bytes( $database_bytes );

	if ( 0 < $memory_limit && $memory_needed > $memory_limit ) {
		return new WP_Error(
			'failsafe_limit_memory',
			sprintf(
				/* translators: 1: estimated database size, 2: memory required, 3: safety factor, 4: PHP memory limit. */
				__( 'This site has about %1$s of database. Dumping it needs about %2$s of memory (%3$d times the database size), but PHP only allows %4$s. Raise the memory_limit setting or run the backup from the command line.', 'failsafe' ),
				size_format( $database_bytes ),
				size_format( $memory_needed ),
				(int) FAILSAFE_LIMIT_SAFETY_FACTOR,
				size_format( $memory_limit )
			)
		);
	}

	$directory   = failsafe_backup_dir();
	$disk_free   = failsafe_available_disk_bytes( $directory );
	$disk_needed = failsafe_required_disk_bytes( $database_bytes );

	if ( 0 <= $disk_free && $disk_needed > $disk_free ) {
		return new WP_Error(
			'failsafe_limit_disk',
			sprintf(
				/* translators: 1: backup directory, 2: free space required, 3: free space available. */
				__( 'The backup directory %1$s needs about %2$s of free space, but only %3$s is available. Free up disk space and try again.', 'failsafe' ),
				$directory,
				size_format( $disk_needed ),
				size_format( $disk_free )
			)
		);
	}

	$time_limit = failsafe_parse_size( ini_get( 'max_execution_time' ) );
	$estimated  = failsafe_estimate_backup_seconds( $database_bytes );

	if ( 0 < $time_limit && $estimated > $time_limit ) {
		return new WP_Error(
			'failsafe_limit_time',
			sprintf(
				/* translators: 1: estimated duration in seconds, 2: PHP time limit in seconds. */
				__( 'This backup is estimated to take about %1$d seconds, but PHP stops the request after %2$d seconds. Raise the max_execution_time setting or run the backup in the background.', 'failsafe' ),
				$estimated,
				$time_limit
			)
		);
	}

	return true;
}
