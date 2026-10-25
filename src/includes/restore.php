<?php
/**
 * Restore logic: SQL parsing, database execution and safe archive extraction.
 *
 * The restore is deliberately database-only. Site files are extracted to a
 * temporary directory outside the web root, validated, and discarded; the
 * database dump is the only payload executed. The backup used is never deleted.
 *
 * @package Failsafe
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'FAILSAFE_RESTORE_DUMP_NAME' ) ) {
	define( 'FAILSAFE_RESTORE_DUMP_NAME', 'failsafe-backup.sql' );
}

if ( ! defined( 'FAILSAFE_RESTORE_MAX_ENTRIES' ) ) {
	define( 'FAILSAFE_RESTORE_MAX_ENTRIES', 50000 );
}

/**
 * Splits a SQL dump into individual statements.
 *
 * Handles single quotes, double quotes, backticks, backslash escapes, doubled
 * quotes, line comments, block comments and DELIMITER directives. Returned
 * statements never include their trailing delimiter.
 *
 * @param string $sql Dump contents.
 * @return string[]
 */
function failsafe_split_sql_statements( string $sql ): array {
	// A UTF-8 byte order mark would otherwise be glued to the first keyword.
	if ( str_starts_with( $sql, "\xEF\xBB\xBF" ) ) {
		$sql = substr( $sql, 3 );
	}

	$statements  = array();
	$length      = strlen( $sql );
	$delimiter   = ';';
	$buffer      = '';
	$has_content = false;
	$state       = 'normal';
	$index       = 0;

	while ( $index < $length ) {
		if ( 'normal' !== $state ) {
			$char  = $sql[ $index ];
			$quote = 'single' === $state ? "'" : ( 'double' === $state ? '"' : '`' );

			// Backslash escapes the next character inside quoted strings, never inside identifiers.
			if ( '\\' === $char && 'backtick' !== $state && ( $index + 1 ) < $length ) {
				$buffer .= $char . $sql[ $index + 1 ];
				$index  += 2;
				continue;
			}

			if ( $quote === $char ) {
				// A doubled quote is an escaped quote and keeps the string open.
				if ( ( $index + 1 ) < $length && $quote === $sql[ $index + 1 ] ) {
					$buffer .= $char . $quote;
					$index  += 2;
					continue;
				}

				$state = 'normal';
			}

			$buffer .= $char;
			$index++;

			continue;
		}

		$char = $sql[ $index ];
		$next = $sql[ $index + 1 ] ?? '';

		// A DELIMITER directive is only meaningful at the start of a statement.
		if ( ! $has_content && preg_match( '/\GDELIMITER[ \t]+(\S+)[^\r\n]*(?:\r\n|\r|\n|$)/i', $sql, $matches, 0, $index ) ) {
			$delimiter = $matches[1];
			$buffer    = '';
			$index    += strlen( $matches[0] );
			continue;
		}

		// Delimiters may have more than one character after a DELIMITER directive.
		if ( 0 === substr_compare( $sql, $delimiter, $index, strlen( $delimiter ) ) ) {
			$statement = trim( $buffer );

			if ( $has_content && '' !== $statement ) {
				$statements[] = $statement;
			}

			$buffer      = '';
			$has_content = false;
			$index      += strlen( $delimiter );
			continue;
		}

		// Line comments. MySQL requires whitespace after "--", so "a--b" stays valid SQL.
		$after = $sql[ $index + 2 ] ?? '';

		if ( '-' === $char && '-' === $next && ( '' === $after || 1 === preg_match( '/\s/', $after ) ) ) {
			$line_end = strpos( $sql, "\n", $index );
			$index    = false === $line_end ? $length : $line_end + 1;
			$buffer  .= "\n";
			continue;
		}

		if ( '#' === $char ) {
			$line_end = strpos( $sql, "\n", $index );
			$index    = false === $line_end ? $length : $line_end + 1;
			$buffer  .= "\n";
			continue;
		}

		// Block comments. Version comments, marked with "!" after the opener, are executable SQL.
		if ( '/' === $char && '*' === $next ) {
			$close         = strpos( $sql, '*/', $index + 2 );
			$comment_end   = false === $close ? $length : $close;
			$next_index    = false === $close ? $length : $close + 2;
			$is_executable = ( $index + 2 ) < $comment_end && '!' === $sql[ $index + 2 ];

			if ( $is_executable ) {
				$inner = substr( $sql, $index + 3, $comment_end - $index - 3 );
				$inner = (string) preg_replace( '/^[0-9]{5,6}[ \t]*/', '', $inner );

				if ( '' !== trim( $inner ) ) {
					$buffer     .= $inner;
					$has_content = true;
				}
			} else {
				$buffer .= ' ';
			}

			$index = $next_index;
			continue;
		}

		if ( "'" === $char || '"' === $char || '`' === $char ) {
			$state       = "'" === $char ? 'single' : ( '"' === $char ? 'double' : 'backtick' );
			$buffer     .= $char;
			$has_content = true;
			$index++;
			continue;
		}

		$buffer .= $char;

		if ( ! $has_content && ! ctype_space( $char ) ) {
			$has_content = true;
		}

		$index++;
	}

	if ( $has_content ) {
		$statement = trim( $buffer );

		if ( '' !== $statement ) {
			$statements[] = $statement;
		}
	}

	return $statements;
}

/**
 * Executes every statement of a dump against the current database connection.
 *
 * Statements are run in order. A failing data statement aborts the restore with
 * a WP_Error quoting the statement; failing SET statements are only logged, as
 * restricted database users may not be allowed to change some session values.
 *
 * The statements are read from the SQL dump inside the validated backup
 * archive, not from user input. They are MySQL DDL and multi-row INSERT
 * commands, so they cannot be passed through $wpdb->prepare(): doing so would
 * escape the data and break CREATE TABLE and INSERT.
 *
 * @param string $sql Dump contents.
 * @return true|WP_Error True when every statement ran.
 */
function failsafe_restore_database( string $sql ) {
	global $wpdb;

	$statements = failsafe_split_sql_statements( $sql );

	if ( empty( $statements ) ) {
		return new WP_Error( 'failsafe_restore_empty', __( 'The dump does not contain any SQL statement.', 'failsafe' ) );
	}

	$total    = count( $statements );
	$previous = $wpdb->suppress_errors( true );

	try {
		foreach ( $statements as $position => $statement ) {
			$wpdb->last_error = '';

			if ( ! failsafe_restore_allowed_statement( $statement ) ) {
				return new WP_Error(
					'failsafe_restore_statement_blocked',
					sprintf(
						/* translators: 1: statement number, 2: total number of statements, 3: truncated SQL command. */
						__( 'Restore stopped at statement %1$d of %2$d: this statement type is not allowed. Command: %3$s', 'failsafe' ),
						(int) $position + 1,
						$total,
						failsafe_restore_statement_excerpt( $statement )
					)
				);
			}

			// Statements come from the backup archive, never from user input; preparing them would break DDL.
			// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- statements are read from the validated backup archive, not from user input, and cannot be prepared without breaking CREATE TABLE and INSERT.
			$result = $wpdb->query( $statement );

			if ( false !== $result && '' === $wpdb->last_error ) {
				continue;
			}

			$message = '' !== $wpdb->last_error ? $wpdb->last_error : __( 'unknown database error', 'failsafe' );

			if ( failsafe_restore_is_best_effort_statement( $statement ) ) {
				failsafe_log(
					sprintf(
						'restore: ignored error on statement %1$d of %2$d: %3$s',
						(int) $position + 1,
						$total,
						$message
					)
				);
				continue;
			}

			return new WP_Error(
				'failsafe_restore_query_failed',
				sprintf(
					/* translators: 1: statement number, 2: total number of statements, 3: database error message, 4: truncated SQL command. */
					__( 'Restore stopped at statement %1$d of %2$d: %3$s. Command: %4$s', 'failsafe' ),
					(int) $position + 1,
					$total,
					$message,
					failsafe_restore_statement_excerpt( $statement )
				)
			);
		}
	} finally {
		$wpdb->suppress_errors( $previous );
	}

	return true;
}

/**
 * Tells whether a failing statement may be skipped without corrupting data.
 *
 * @param string $statement SQL statement.
 * @return bool
 */
function failsafe_restore_is_best_effort_statement( string $statement ): bool {
	return 1 === preg_match( '/^\s*SET\b/i', $statement );
}

/**
 * Statement types the restore is allowed to execute.
 *
 * A backup archive is a file on disk: if it is tampered with, the restore must
 * not become a way to run arbitrary SQL against the site. Only the commands a
 * dump needs are accepted.
 *
 * @return string[] Upper-case statement prefixes.
 */
function failsafe_restore_allowed_statements(): array {
	return (array) apply_filters(
		'failsafe_restore_allowed_statements',
		array(
			'SET',
			'DROP TABLE',
			'DROP VIEW',
			'CREATE TABLE',
			'CREATE VIEW',
			'ALTER TABLE',
			'INSERT',
			'REPLACE',
			'LOCK TABLES',
			'UNLOCK TABLES',
			'START TRANSACTION',
			'COMMIT',
			'ROLLBACK',
		)
	);
}

/**
 * Tells whether a statement is safe to execute during a restore.
 *
 * @param string $statement SQL statement.
 * @return bool
 */
function failsafe_restore_allowed_statement( string $statement ): bool {
	$allowed = failsafe_restore_allowed_statements();

	if ( empty( $allowed ) ) {
		return false;
	}

	$pattern = '/^\s*(?:' . implode( '|', array_map( 'preg_quote', $allowed ) ) . ')\b/i';

	return 1 === preg_match( $pattern, $statement );
}

/**
 * Shortens a SQL statement for an error message.
 *
 * @param string $statement SQL statement.
 * @param int    $length    Maximum number of characters.
 * @return string
 */
function failsafe_restore_statement_excerpt( string $statement, int $length = 200 ): string {
	$excerpt = trim( (string) preg_replace( '/\s+/', ' ', $statement ) );

	if ( function_exists( 'mb_strlen' ) && function_exists( 'mb_substr' ) ) {
		if ( mb_strlen( $excerpt, 'UTF-8' ) <= $length ) {
			return $excerpt;
		}

		return mb_substr( $excerpt, 0, $length, 'UTF-8' ) . '...';
	}

	if ( strlen( $excerpt ) <= $length ) {
		return $excerpt;
	}

	return substr( $excerpt, 0, $length ) . '...';
}

/**
 * Creates the safety backup taken before a restore.
 *
 * @return true|WP_Error
 */
function failsafe_create_restore_safety_backup() {
	if ( ! function_exists( 'failsafe_create_backup' ) ) {
		return new WP_Error(
			'failsafe_restore_no_runner',
			__( 'The backup routine is not available, so the restore was aborted.', 'failsafe' )
		);
	}

	$result = failsafe_create_backup();

	if ( is_wp_error( $result ) ) {
		return new WP_Error(
			'failsafe_restore_safety_backup_failed',
			sprintf(
				/* translators: %s: database or filesystem error message. */
				__( 'The safety backup could not be created, so the restore was aborted: %s', 'failsafe' ),
				$result->get_error_message()
			)
		);
	}

	return true;
}

/**
 * Extracts a backup archive to a temporary directory after validating every entry.
 *
 * Entries using parent references, absolute paths, null bytes or symlinks are
 * rejected before anything is written. The temporary directory has to live
 * outside the web root.
 *
 * @param string $zip_path   Absolute path of the archive.
 * @param string $target_dir Optional directory to extract into.
 * @return string|WP_Error Absolute path of the extraction directory.
 */
function failsafe_extract_backup_archive( string $zip_path, string $target_dir = '' ) {
	if ( ! class_exists( 'ZipArchive' ) ) {
		return new WP_Error( 'failsafe_no_ziparchive', __( 'The ZipArchive extension is not available.', 'failsafe' ) );
	}

	if ( ! is_readable( $zip_path ) ) {
		return new WP_Error(
			'failsafe_restore_zip_unreadable',
			sprintf(
				/* translators: %s: archive path. */
				__( 'The backup archive %s cannot be read.', 'failsafe' ),
				$zip_path
			)
		);
	}

	if ( '' === $target_dir ) {
		$target_dir = trailingslashit( get_temp_dir() ) . 'failsafe-restore-' . wp_generate_password( 16, false, false );
	}

	if ( ! wp_mkdir_p( $target_dir ) && ! is_dir( $target_dir ) ) {
		return new WP_Error(
			'failsafe_restore_temp_failed',
			sprintf(
				/* translators: %s: directory path. */
				__( 'Could not create the temporary directory %s.', 'failsafe' ),
				$target_dir
			)
		);
	}

	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- 0700 protects the temporary directory that holds the extracted dump, and this runs before any WP_Filesystem transport is chosen.
	@chmod( $target_dir, 0700 );

	$real_target = realpath( $target_dir );
	$real_root   = realpath( ABSPATH );

	if ( false === $real_target || ( false !== $real_root && ( $real_target === $real_root || str_starts_with( $real_target . DIRECTORY_SEPARATOR, trailingslashit( $real_root ) ) ) ) ) {
		failsafe_remove_temp_directory( $target_dir );

		return new WP_Error(
			'failsafe_restore_temp_inside_root',
			__( 'The extraction directory must live outside the WordPress installation.', 'failsafe' )
		);
	}

	$zip    = new ZipArchive();
	$opened = $zip->open( $zip_path, ZipArchive::CHECKCONS );

	if ( true !== $opened ) {
		failsafe_remove_temp_directory( $target_dir );

		return new WP_Error(
			'failsafe_restore_zip_open',
			sprintf(
				/* translators: %d: ZipArchive error code. */
				__( 'Could not open the backup archive (error %d).', 'failsafe' ),
				$opened
			)
		);
	}

	if ( 0 === $zip->numFiles ) {
		return failsafe_restore_abort_extraction(
			$zip,
			$target_dir,
			new WP_Error( 'failsafe_restore_zip_empty', __( 'The backup archive is empty.', 'failsafe' ) )
		);
	}

	$max_entries = (int) apply_filters( 'failsafe_restore_max_entries', FAILSAFE_RESTORE_MAX_ENTRIES );

	if ( $max_entries > 0 && $zip->numFiles > $max_entries ) {
		return failsafe_restore_abort_extraction(
			$zip,
			$target_dir,
			new WP_Error(
				'failsafe_restore_zip_too_large',
				sprintf(
					/* translators: 1: number of entries, 2: maximum number of entries. */
					__( 'The archive has %1$d entries and exceeds the limit of %2$d.', 'failsafe' ),
					(int) $zip->numFiles,
					$max_entries
				)
			)
		);
	}

	for ( $index = 0; $index < $zip->numFiles; $index++ ) {
		$name = $zip->getNameIndex( $index );

		if ( false === $name ) {
			return failsafe_restore_abort_extraction(
				$zip,
				$target_dir,
				new WP_Error( 'failsafe_restore_entry_unreadable', __( 'An archive entry could not be read.', 'failsafe' ) )
			);
		}

		$valid = failsafe_restore_validate_entry_name( $name );

		if ( is_wp_error( $valid ) ) {
			return failsafe_restore_abort_extraction(
				$zip,
				$target_dir,
				new WP_Error(
					'failsafe_restore_entry_rejected',
					sprintf(
						/* translators: 1: archive entry name, 2: rejection reason. */
						__( 'The archive entry %1$s was rejected: %2$s', 'failsafe' ),
						$name,
						$valid->get_error_message()
					)
				)
			);
		}

		if ( failsafe_restore_entry_is_symlink( $zip, $index ) ) {
			return failsafe_restore_abort_extraction(
				$zip,
				$target_dir,
				new WP_Error(
					'failsafe_restore_entry_symlink',
					sprintf(
						/* translators: %s: archive entry name. */
						__( 'The archive entry %s is a symbolic link, which is not allowed.', 'failsafe' ),
						$name
					)
				)
			);
		}
	}

	for ( $index = 0; $index < $zip->numFiles; $index++ ) {
		$name = $zip->getNameIndex( $index );

		if ( false === $name || '' === $name ) {
			return failsafe_restore_abort_extraction(
				$zip,
				$target_dir,
				new WP_Error( 'failsafe_restore_entry_unreadable', __( 'An archive entry could not be read.', 'failsafe' ) )
			);
		}

		if ( str_ends_with( $name, '/' ) ) {
			$directory = $real_target . DIRECTORY_SEPARATOR . rtrim( str_replace( '\\', '/', $name ), '/' );

			if ( ! wp_mkdir_p( $directory ) && ! is_dir( $directory ) ) {
				return failsafe_restore_abort_extraction(
					$zip,
					$target_dir,
					new WP_Error(
						'failsafe_restore_extract_failed',
						sprintf(
							/* translators: %s: directory path. */
							__( 'Could not create the extracted directory %s.', 'failsafe' ),
							$directory
						)
					)
				);
			}

			continue;
		}

		$parent = dirname( str_replace( '\\', '/', $name ) );

		if ( '.' !== $parent && ! wp_mkdir_p( $real_target . DIRECTORY_SEPARATOR . $parent ) && ! is_dir( $real_target . DIRECTORY_SEPARATOR . $parent ) ) {
			return failsafe_restore_abort_extraction(
				$zip,
				$target_dir,
				new WP_Error(
					'failsafe_restore_extract_failed',
					sprintf(
						/* translators: %s: directory path. */
						__( 'Could not create the extracted directory %s.', 'failsafe' ),
						$real_target . DIRECTORY_SEPARATOR . $parent
					)
				)
			);
		}

		if ( ! $zip->extractTo( $real_target, $name ) ) {
			return failsafe_restore_abort_extraction(
				$zip,
				$target_dir,
				new WP_Error(
					'failsafe_restore_extract_failed',
					sprintf(
						/* translators: %s: archive entry name. */
						__( 'Could not extract the archive entry %s.', 'failsafe' ),
						$name
					)
				)
			);
		}
	}

	$zip->close();

	return $real_target;
}

/**
 * Builds a WP_Error after closing the archive and removing the partial extraction.
 *
 * @param ZipArchive $zip        Open archive.
 * @param string     $target_dir Extraction directory.
 * @param WP_Error   $error      Error to return.
 * @return WP_Error
 */
function failsafe_restore_abort_extraction( ZipArchive $zip, string $target_dir, WP_Error $error ) {
	$zip->close();
	failsafe_remove_temp_directory( $target_dir );

	return $error;
}

/**
 * Validates one archive entry name against path traversal and absolute paths.
 *
 * @param string $name Entry name as stored in the archive.
 * @return true|WP_Error
 */
function failsafe_restore_validate_entry_name( string $name ) {
	if ( '' === $name || str_contains( $name, "\0" ) ) {
		return new WP_Error(
			'failsafe_restore_entry_invalid',
			__( 'the entry name is empty or contains a null byte.', 'failsafe' )
		);
	}

	$normalized = str_replace( '\\', '/', $name );

	if ( str_starts_with( $normalized, '/' ) ) {
		return new WP_Error(
			'failsafe_restore_entry_absolute',
			__( 'absolute paths are not allowed.', 'failsafe' )
		);
	}

	if ( 1 === preg_match( '/^[A-Za-z]:/', $normalized ) ) {
		return new WP_Error(
			'failsafe_restore_entry_absolute',
			__( 'absolute Windows paths are not allowed.', 'failsafe' )
		);
	}

	foreach ( explode( '/', $normalized ) as $segment ) {
		if ( '..' === $segment ) {
			return new WP_Error(
				'failsafe_restore_entry_traversal',
				__( 'parent directory references are not allowed.', 'failsafe' )
			);
		}
	}

	return true;
}

/**
 * Tells whether an archive entry is a Unix symbolic link.
 *
 * @param ZipArchive $zip   Open archive.
 * @param int        $index Entry index.
 * @return bool
 */
function failsafe_restore_entry_is_symlink( ZipArchive $zip, int $index ): bool {
	$operating_system = 0;
	$attributes       = 0;

	if ( ! $zip->getExternalAttributesIndex( $index, $operating_system, $attributes ) ) {
		return false;
	}

	if ( ZipArchive::OPSYS_UNIX !== $operating_system ) {
		return false;
	}

	// The high 16 bits hold the Unix st_mode; 0120000 is S_IFLNK.
	$mode = ( $attributes >> 16 ) & 0170000;

	return 0120000 === $mode;
}

/**
 * Removes a temporary extraction directory.
 *
 * Refuses to touch anything outside the system temporary directory. Deletion
 * goes through WP_Filesystem; symbolic links are unlinked, never followed.
 *
 * @param string $directory Absolute path to remove.
 * @return bool
 */
function failsafe_remove_temp_directory( string $directory ): bool {
	$real_directory = realpath( $directory );
	$real_temp      = realpath( get_temp_dir() );

	if ( false === $real_directory || false === $real_temp || ! is_dir( $real_directory ) ) {
		return false;
	}

	if ( $real_directory === $real_temp || ! str_starts_with( $real_directory . DIRECTORY_SEPARATOR, trailingslashit( $real_temp ) ) ) {
		return false;
	}

	require_once ABSPATH . 'wp-admin/includes/file.php';

	$filesystem = WP_Filesystem( false, $real_directory, true ) ? $GLOBALS['wp_filesystem'] : false;

	if ( ! $filesystem ) {
		failsafe_log( 'restore cleanup: the filesystem API could not be initialised.' );

		return false;
	}

	$failed = false;

	try {
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $real_directory, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $iterator as $item ) {
			$pathname = $item->getPathname();

			if ( $item->isDir() && ! $item->isLink() ) {
				$failed = ! $filesystem->delete( $pathname ) || $failed;
				continue;
			}

			// Force the file branch so a symbolic link is unlinked instead of followed.
			$failed = ! $filesystem->delete( $pathname, false, 'f' ) || $failed;
		}
	} catch ( Exception $exception ) {
		failsafe_log( 'restore cleanup: ' . $exception->getMessage() );

		return false;
	}

	return $filesystem->delete( $real_directory ) && ! $failed;
}
