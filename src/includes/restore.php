<?php
/**
 * Restore layer: SQL parsing, database import and safe archive extraction.
 *
 * The restore runs in three independent steps so each one can be tested on
 * its own:
 *
 *   1. failsafe_split_sql_statements() turns a dump into single commands.
 *   2. failsafe_extract_backup_zip() validates and unpacks the archive into a
 *      temporary directory outside the webroot.
 *   3. failsafe_restore_database() executes the commands in order and stops at
 *      the first error.
 *
 * @package Failsafe
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'FAILSAFE_RESTORE_SQL_ENTRY' ) ) {
	define( 'FAILSAFE_RESTORE_SQL_ENTRY', 'failsafe-backup.sql' );
}

if ( ! defined( 'FAILSAFE_RESTORE_MAX_ENTRIES' ) ) {
	define( 'FAILSAFE_RESTORE_MAX_ENTRIES', 100000 );
}

if ( ! defined( 'FAILSAFE_RESTORE_MAX_BYTES' ) ) {
	define( 'FAILSAFE_RESTORE_MAX_BYTES', 2 * 1024 * 1024 * 1024 );
}

/**
 * Splits a SQL dump into individual statements.
 *
 * Semicolons inside single quotes, double quotes and backticks are kept as
 * data. Backslash escapes, doubled quotes, `--`, `#` and block comments are
 * understood, and the mysql client `DELIMITER` directive is honoured.
 *
 * @param string $sql Dump contents.
 * @return string[] Statements without their trailing delimiter.
 */
function failsafe_split_sql_statements( $sql ) {
	$statements = array();
	$length     = strlen( $sql );
	$delimiter  = ';';
	$buffer     = '';
	$index      = 0;

	while ( $index < $length ) {
		// The DELIMITER command is a client directive, never sent to the server.
		if ( '' === trim( $buffer ) ) {
			if ( preg_match( '/\GDELIMITER[ \t]+([^ \t\r\n]+)[ \t]*(?:\r?\n|$)/i', $sql, $matches, 0, $index ) ) {
				$delimiter = $matches[1];
				$index    += strlen( $matches[0] );
				$buffer    = '';
				continue;
			}
		}

		$character = $sql[ $index ];

		if ( "'" === $character || '"' === $character || '`' === $character ) {
			$quote   = $character;
			$buffer .= $character;
			$index++;

			while ( $index < $length ) {
				$inner = $sql[ $index ];

				// Backticks escape with a doubled backtick; strings also accept backslash.
				if ( '\\' === $inner && '`' !== $quote ) {
					$buffer .= $inner;
					$index++;

					if ( $index < $length ) {
						$buffer .= $sql[ $index ];
						$index++;
					}

					continue;
				}

				if ( $inner === $quote ) {
					if ( $index + 1 < $length && $sql[ $index + 1 ] === $quote ) {
						$buffer .= $quote . $quote;
						$index  += 2;
						continue;
					}

					$buffer .= $inner;
					$index++;
					break;
				}

				$buffer .= $inner;
				$index++;
			}

			continue;
		}

		if ( '/' === $character && $index + 1 < $length && '*' === $sql[ $index + 1 ] ) {
			$end = strpos( $sql, '*/', $index + 2 );

			$index = false === $end ? $length : $end + 2;

			// A comment separates tokens, so it becomes whitespace.
			$buffer .= ' ';
			continue;
		}

		$is_dash_comment = '-' === $character
			&& $index + 1 < $length
			&& '-' === $sql[ $index + 1 ]
			&& (
				0 === $index
				|| "\n" === $sql[ $index - 1 ]
				|| "\r" === $sql[ $index - 1 ]
				|| $index + 2 >= $length
				|| ctype_space( $sql[ $index + 2 ] )
			);

		if ( '#' === $character || $is_dash_comment ) {
			$end = strpos( $sql, "\n", $index );

			$index = false === $end ? $length : $end;
			$buffer .= ' ';
			continue;
		}

		$delimiter_length = strlen( $delimiter );

		if ( 0 < $delimiter_length && $index + $delimiter_length <= $length && substr( $sql, $index, $delimiter_length ) === $delimiter ) {
			$statement = trim( $buffer );

			if ( '' !== $statement ) {
				$statements[] = $statement;
			}

			$buffer = '';
			$index += $delimiter_length;
			continue;
		}

		$buffer .= $character;
		$index++;
	}

	// The mysql client also runs whatever is left at the end of the file.
	$statement = trim( $buffer );

	if ( '' !== $statement ) {
		$statements[] = $statement;
	}

	return $statements;
}

/**
 * Shortens a statement for use in an error message.
 *
 * @param string $statement SQL statement.
 * @return string Single line excerpt with at most 200 characters.
 */
function failsafe_sql_statement_excerpt( $statement ) {
	$excerpt = preg_replace( '/\s+/', ' ', trim( (string) $statement ) );

	if ( ! is_string( $excerpt ) ) {
		$excerpt = (string) $statement;
	}

	if ( function_exists( 'mb_substr' ) ) {
		$excerpt_length = mb_strlen( $excerpt );

		if ( is_int( $excerpt_length ) && $excerpt_length > 200 ) {
			return mb_substr( $excerpt, 0, 200 ) . '...';
		}

		if ( is_int( $excerpt_length ) ) {
			return $excerpt;
		}
	}

	if ( strlen( $excerpt ) > 200 ) {
		return substr( $excerpt, 0, 200 ) . '...';
	}

	return $excerpt;
}

/**
 * Executes every statement of a dump against the current database.
 *
 * Comments and the DELIMITER directive are already removed by the parser.
 * Execution stops at the first error so a broken dump cannot be reported as
 * a successful restore.
 *
 * @param string $sql Dump contents.
 * @return true|WP_Error True when every statement ran.
 */
function failsafe_restore_database( $sql ) {
	global $wpdb;

	if ( ! is_string( $sql ) || '' === trim( $sql ) ) {
		return new WP_Error( 'failsafe_restore_empty_sql', __( 'The database dump is empty.', 'failsafe' ) );
	}

	$statements = failsafe_split_sql_statements( $sql );

	if ( empty( $statements ) ) {
		return new WP_Error( 'failsafe_restore_no_statements', __( 'The database dump contains no SQL statements.', 'failsafe' ) );
	}

	$suppress_errors = $wpdb->suppress_errors( true );

	foreach ( $statements as $statement ) {
		// Reset the previous error so it cannot be attributed to this query.
		$wpdb->last_error = '';
		$result           = $wpdb->query( $statement );

		if ( false === $result || '' !== $wpdb->last_error ) {
			$error = '' !== $wpdb->last_error
				? $wpdb->last_error
				: __( 'The database rejected the statement.', 'failsafe' );

			// Release locks and undo any open transaction before reporting.
			$wpdb->query( 'ROLLBACK' );
			$wpdb->last_error = '';
			$wpdb->suppress_errors( $suppress_errors );

			return new WP_Error(
				'failsafe_restore_query_failed',
				sprintf(
					/* translators: 1: database error, 2: SQL statement excerpt. */
					__( 'The restore stopped because the database returned an error: %1$s. Statement: %2$s', 'failsafe' ),
					$error,
					failsafe_sql_statement_excerpt( $statement )
				)
			);
		}
	}

	// A dump may open a transaction without closing it; never leave it open.
	$wpdb->last_error = '';
	$committed        = $wpdb->query( 'COMMIT' );

	if ( false === $committed || '' !== $wpdb->last_error ) {
		$error = '' !== $wpdb->last_error
			? $wpdb->last_error
			: __( 'The database rejected the commit.', 'failsafe' );

		$wpdb->suppress_errors( $suppress_errors );

		return new WP_Error(
			'failsafe_restore_commit_failed',
			sprintf(
				/* translators: %s: database error. */
				__( 'The restore could not be committed: %s', 'failsafe' ),
				$error
			)
		);
	}

	$wpdb->suppress_errors( $suppress_errors );

	return true;
}

/**
 * Tells whether an archive entry name stays inside the extraction directory.
 *
 * Rejects absolute paths, Windows drive letters, backslashes, null bytes and
 * any ".." path segment.
 *
 * @param string $name Entry name as stored in the archive.
 * @return bool
 */
function failsafe_zip_entry_is_safe( $name ) {
	if ( ! is_string( $name ) || '' === $name ) {
		return false;
	}

	if ( str_contains( $name, "\0" ) || str_contains( $name, '\\' ) ) {
		return false;
	}

	if ( '/' === $name[0] || preg_match( '/^[A-Za-z]:/', $name ) ) {
		return false;
	}

	foreach ( explode( '/', $name ) as $segment ) {
		if ( '..' === $segment ) {
			return false;
		}
	}

	return true;
}

/**
 * Tells whether an archive entry is a symbolic link.
 *
 * @param ZipArchive $zip   Open archive.
 * @param int        $index Entry index.
 * @return bool
 */
function failsafe_zip_entry_is_symlink( ZipArchive $zip, $index ) {
	$operating_system = 0;
	$attributes       = 0;

	if ( ! $zip->getExternalAttributesIndex( $index, $operating_system, $attributes ) ) {
		return false;
	}

	if ( ! in_array( $operating_system, array( ZipArchive::OPSYS_UNIX, ZipArchive::OPSYS_OS_X ), true ) ) {
		return false;
	}

	$mode = ( $attributes >> 16 ) & 0xFFFF;

	return 0120000 === ( $mode & 0170000 );
}

/**
 * Validates every entry of an open archive before anything is written to disk.
 *
 * @param ZipArchive $zip Open archive.
 * @return true|WP_Error True when the archive is safe to extract.
 */
function failsafe_validate_backup_zip( ZipArchive $zip ) {
	$entries = $zip->numFiles;

	if ( 0 === $entries ) {
		return new WP_Error( 'failsafe_restore_zip_empty', __( 'The backup archive is empty.', 'failsafe' ) );
	}

	if ( $entries > FAILSAFE_RESTORE_MAX_ENTRIES ) {
		return new WP_Error(
			'failsafe_restore_zip_too_many_entries',
			sprintf(
				/* translators: 1: number of entries, 2: maximum allowed. */
				__( 'The backup archive has %1$d entries; the limit is %2$d.', 'failsafe' ),
				$entries,
				FAILSAFE_RESTORE_MAX_ENTRIES
			)
		);
	}

	$total_bytes = 0;

	for ( $index = 0; $index < $entries; $index++ ) {
		$name = $zip->getNameIndex( $index );

		if ( ! failsafe_zip_entry_is_safe( $name ) ) {
			return new WP_Error(
				'failsafe_restore_zip_unsafe_entry',
				sprintf(
					/* translators: %s: archive entry name. */
					__( 'The backup archive contains an unsafe entry: %s', 'failsafe' ),
					failsafe_sql_statement_excerpt( (string) $name )
				)
			);
		}

		if ( failsafe_zip_entry_is_symlink( $zip, $index ) ) {
			return new WP_Error(
				'failsafe_restore_zip_symlink',
				sprintf(
					/* translators: %s: archive entry name. */
					__( 'The backup archive contains a symbolic link: %s', 'failsafe' ),
					$name
				)
			);
		}

		$stat         = $zip->statIndex( $index );
		$total_bytes += isset( $stat['size'] ) ? (int) $stat['size'] : 0;

		if ( $total_bytes > FAILSAFE_RESTORE_MAX_BYTES ) {
			return new WP_Error(
				'failsafe_restore_zip_too_large',
				__( 'The backup archive expands beyond the allowed size.', 'failsafe' )
			);
		}
	}

	return true;
}

/**
 * Creates a fresh temporary directory outside the webroot.
 *
 * @return string|WP_Error Absolute path on success.
 */
function failsafe_create_restore_temp_dir() {
	$base = trailingslashit( get_temp_dir() );

	if ( ! is_dir( $base ) || ! is_writable( $base ) ) {
		return new WP_Error(
			'failsafe_restore_temp_unwritable',
			sprintf(
				/* translators: %s: temporary directory. */
				__( 'The temporary directory %s is not writable.', 'failsafe' ),
				$base
			)
		);
	}

	$real_base    = realpath( $base );
	$real_abspath = realpath( ABSPATH );

	if ( false === $real_base ) {
		return new WP_Error( 'failsafe_restore_temp_missing', __( 'The temporary directory does not exist.', 'failsafe' ) );
	}

	if ( false !== $real_abspath ) {
		$real_abspath = rtrim( $real_abspath, '/\\' ) . DIRECTORY_SEPARATOR;

		if ( str_starts_with( rtrim( $real_base, '/\\' ) . DIRECTORY_SEPARATOR, $real_abspath ) ) {
			return new WP_Error(
				'failsafe_restore_temp_inside_webroot',
				__( 'The temporary directory is inside the webroot. Restore aborted.', 'failsafe' )
			);
		}
	}

	try {
		$suffix = bin2hex( random_bytes( 8 ) );
	} catch ( Exception $exception ) {
		return new WP_Error( 'failsafe_restore_temp_random', __( 'Could not generate a temporary directory name.', 'failsafe' ) );
	}

	$directory = $base . 'failsafe-restore-' . $suffix;

	if ( ! wp_mkdir_p( $directory ) || ! is_writable( $directory ) ) {
		failsafe_remove_directory( $directory );

		return new WP_Error(
			'failsafe_restore_temp_create',
			sprintf(
				/* translators: %s: temporary directory. */
				__( 'Could not create the temporary directory %s.', 'failsafe' ),
				$directory
			)
		);
	}

	return $directory;
}

/**
 * Extracts a backup archive into a temporary directory outside the webroot.
 *
 * Every entry is validated first. When a list of entries is given only those
 * are extracted, which lets the restore read the SQL dump without unpacking
 * the entire site.
 *
 * @param string   $zip_path Archive to open.
 * @param string[] $entries  Optional entry names. Empty extracts everything.
 * @return string|WP_Error Temporary directory path on success.
 */
function failsafe_extract_backup_zip( $zip_path, array $entries = array() ) {
	if ( ! class_exists( 'ZipArchive' ) ) {
		return new WP_Error( 'failsafe_no_ziparchive', __( 'The ZipArchive extension is not available.', 'failsafe' ) );
	}

	if ( ! is_string( $zip_path ) || ! is_readable( $zip_path ) ) {
		return new WP_Error( 'failsafe_restore_zip_missing', __( 'The backup archive could not be read.', 'failsafe' ) );
	}

	$zip    = new ZipArchive();
	$opened = $zip->open( $zip_path, ZipArchive::CHECKCONS );

	if ( true !== $opened ) {
		return new WP_Error(
			'failsafe_restore_zip_open',
			sprintf(
				/* translators: %d: ZipArchive error code. */
				__( 'The backup archive could not be opened (error %d).', 'failsafe' ),
				$opened
			)
		);
	}

	$validated = failsafe_validate_backup_zip( $zip );

	if ( is_wp_error( $validated ) ) {
		$zip->close();

		return $validated;
	}

	$selected = array();

	foreach ( $entries as $entry ) {
		$entry = (string) $entry;

		if ( ! failsafe_zip_entry_is_safe( $entry ) ) {
			$zip->close();

			return new WP_Error( 'failsafe_restore_entry_invalid', __( 'An invalid archive entry was requested.', 'failsafe' ) );
		}

		if ( false === $zip->locateName( $entry ) ) {
			$zip->close();

			return new WP_Error(
				'failsafe_restore_entry_missing',
				sprintf(
					/* translators: %s: archive entry name. */
					__( 'The backup archive does not contain %s.', 'failsafe' ),
					$entry
				)
			);
		}

		$selected[] = $entry;
	}

	$directory = failsafe_create_restore_temp_dir();

	if ( is_wp_error( $directory ) ) {
		$zip->close();

		return $directory;
	}

	$extracted = true === $zip->extractTo( $directory, empty( $selected ) ? null : $selected );

	$zip->close();

	if ( false === $extracted ) {
		failsafe_remove_directory( $directory );

		return new WP_Error( 'failsafe_restore_extract_failed', __( 'The backup archive could not be extracted.', 'failsafe' ) );
	}

	$real_directory = realpath( $directory );

	if ( false === $real_directory ) {
		failsafe_remove_directory( $directory );

		return new WP_Error( 'failsafe_restore_extract_missing', __( 'The extraction directory disappeared.', 'failsafe' ) );
	}

	$real_prefix = rtrim( $real_directory, '/\\' ) . DIRECTORY_SEPARATOR;

	foreach ( $selected as $entry ) {
		$real_entry = realpath( $real_directory . DIRECTORY_SEPARATOR . $entry );

		if ( false === $real_entry || ! str_starts_with( $real_entry, $real_prefix ) ) {
			failsafe_remove_directory( $directory );

			return new WP_Error( 'failsafe_restore_extract_escaped', __( 'An archive entry was extracted outside the temporary directory.', 'failsafe' ) );
		}
	}

	return $directory;
}

/**
 * Removes a temporary restore directory and its contents.
 *
 * Refuses to touch anything that does not carry the restore prefix.
 *
 * @param string $directory Absolute path.
 * @return void
 */
function failsafe_remove_directory( $directory ) {
	$directory = rtrim( (string) $directory, '/\\' );

	if ( '' === $directory || ! is_dir( $directory ) ) {
		return;
	}

	if ( ! str_starts_with( basename( $directory ), 'failsafe-restore-' ) ) {
		return;
	}

	try {
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $directory, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $iterator as $item ) {
			$pathname = $item->getPathname();

			if ( $item->isLink() || $item->isFile() ) {
				@unlink( $pathname );
			} else {
				@rmdir( $pathname );
			}
		}
	} catch ( Exception $exception ) {
		failsafe_log( 'restore cleanup: ' . $exception->getMessage() );

		return;
	}

	@rmdir( $directory );
}

/**
 * Reads a dump file from disk.
 *
 * @param string $path Absolute path.
 * @return string|WP_Error Dump contents.
 */
function failsafe_read_backup_file( $path ) {
	if ( ! is_string( $path ) || ! is_readable( $path ) ) {
		return new WP_Error( 'failsafe_restore_dump_missing', __( 'The database dump could not be read.', 'failsafe' ) );
	}

	$sql = file_get_contents( $path );

	if ( false === $sql ) {
		return new WP_Error(
			'failsafe_restore_dump_read',
			sprintf(
				/* translators: %s: file path. */
				__( 'Could not read %s.', 'failsafe' ),
				$path
			)
		);
	}

	if ( '' === trim( $sql ) ) {
		return new WP_Error( 'failsafe_restore_dump_empty', __( 'The database dump is empty.', 'failsafe' ) );
	}

	return $sql;
}

/**
 * Extracts the SQL dump from a backup archive and reads it.
 *
 * @param string $zip_path Archive path.
 * @return string|WP_Error Dump contents.
 */
function failsafe_read_sql_from_zip( $zip_path ) {
	$directory = failsafe_extract_backup_zip( $zip_path, array( FAILSAFE_RESTORE_SQL_ENTRY ) );

	if ( is_wp_error( $directory ) ) {
		return $directory;
	}

	$path = $directory . DIRECTORY_SEPARATOR . FAILSAFE_RESTORE_SQL_ENTRY;
	$sql  = failsafe_read_backup_file( $path );

	failsafe_remove_directory( $directory );

	return $sql;
}

/**
 * Restores the database from an existing backup set.
 *
 * A safety backup is created before anything else. The backup being restored
 * is never deleted. The archive is preferred over the standalone .sql file
 * because it is the artifact the backup screen lists as complete.
 *
 * @param string $base Backup base name.
 * @return array|WP_Error Summary on success.
 */
function failsafe_restore_backup( $base ) {
	if ( ! failsafe_is_valid_backup_name( $base ) ) {
		return new WP_Error( 'failsafe_invalid_backup', __( 'Invalid backup name.', 'failsafe' ) );
	}

	$backup = failsafe_get_backup( $base );

	if ( is_wp_error( $backup ) ) {
		return $backup;
	}

	$zip_path = '';
	$sql_path = '';

	if ( ! empty( $backup['zip'] ) && (int) $backup['zip']['size'] > 0 ) {
		$zip_path = failsafe_backup_path( $base, 'zip' );

		if ( is_wp_error( $zip_path ) ) {
			return $zip_path;
		}
	}

	if ( ! empty( $backup['sql'] ) ) {
		$sql_path = failsafe_backup_path( $base, 'sql' );

		if ( is_wp_error( $sql_path ) ) {
			return $sql_path;
		}
	}

	if ( '' === $zip_path && '' === $sql_path ) {
		return new WP_Error( 'failsafe_restore_no_source', __( 'This backup has no archive or database dump to restore from.', 'failsafe' ) );
	}

	$safety_backup = false;

	if ( function_exists( 'failsafe_create_backup' ) ) {
		// The safety backup must never trigger retention on the backup being restored.
		$disable_retention = static function () {
			return 0;
		};

		add_filter( 'failsafe_retention_count', $disable_retention, PHP_INT_MAX );

		try {
			$created = failsafe_create_backup();
		} finally {
			remove_filter( 'failsafe_retention_count', $disable_retention, PHP_INT_MAX );
		}

		if ( is_wp_error( $created ) ) {
			return new WP_Error(
				'failsafe_restore_safety_failed',
				sprintf(
					/* translators: %s: error message from the backup run. */
					__( 'The restore was cancelled because the safety backup failed: %s', 'failsafe' ),
					$created->get_error_message()
				)
			);
		}

		$safety_backup = true;
	} else {
		failsafe_log( 'restore: failsafe_create_backup() is unavailable; restoring without a safety backup.' );
	}

	// Retention or an external process may have removed the selected backup.
	if ( '' !== $zip_path && ! is_readable( $zip_path ) ) {
		return new WP_Error( 'failsafe_restore_source_missing', __( 'The backup archive disappeared before the restore could start.', 'failsafe' ) );
	}

	if ( '' === $zip_path && ! is_readable( $sql_path ) ) {
		return new WP_Error( 'failsafe_restore_source_missing', __( 'The database dump disappeared before the restore could start.', 'failsafe' ) );
	}

	$sql = '' !== $zip_path ? failsafe_read_sql_from_zip( $zip_path ) : failsafe_read_backup_file( $sql_path );

	if ( is_wp_error( $sql ) ) {
		return $sql;
	}

	$result = failsafe_restore_database( $sql );

	if ( is_wp_error( $result ) ) {
		failsafe_log( 'restore ' . $base . ': ' . $result->get_error_code() . ': ' . $result->get_error_message() );

		return $result;
	}

	wp_cache_flush();

	failsafe_log( 'restore ' . $base . ': completed.' );

	return array(
		'base'          => $base,
		'safety_backup' => $safety_backup,
	);
}
