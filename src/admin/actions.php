<?php
/**
 * Backup runner: builds the SQL dump and the site archive.
 *
 * @package Failsafe
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'admin_post_failsafe_run_backup', 'failsafe_handle_run_backup' );

/**
 * Capability required to run a backup.
 *
 * @return string
 */
function failsafe_backup_capability() {
	return (string) apply_filters( 'failsafe_backup_capability', 'manage_options' );
}

/**
 * Handles the "Backup now" form submission.
 *
 * @return void
 */
function failsafe_handle_run_backup() {
	if ( ! current_user_can( failsafe_backup_capability() ) ) {
		wp_die( esc_html__( 'You are not allowed to run backups.', 'failsafe' ) );
	}

	check_admin_referer( 'failsafe_run_backup', 'failsafe_backup_nonce' );

	$result = failsafe_create_backup();

	if ( is_wp_error( $result ) ) {
		failsafe_log( $result->get_error_code() . ': ' . $result->get_error_message() );

		wp_die(
			esc_html__( 'The backup could not be created. Check the site log for details.', 'failsafe' ),
			esc_html__( 'Backup failed', 'failsafe' ),
			array( 'response' => 500 )
		);
	}

	wp_safe_redirect(
		add_query_arg(
			array(
				'failsafe_backup'    => 'done',
				'failsafe_sql_bytes' => (int) $result['sql_bytes'],
				'failsafe_zip_bytes' => (int) $result['zip_bytes'],
			),
			admin_url()
		)
	);
	exit;
}

/**
 * Creates the SQL dump and the ZIP archive.
 *
 * @return array|WP_Error Paths and sizes on success, WP_Error on failure.
 */
function failsafe_create_backup() {
	$sql = failsafe_build_sql_dump();

	if ( is_wp_error( $sql ) ) {
		return $sql;
	}

	$sql_path = FAILSAFE_BACKUP_DIR . '/failsafe-backup.sql';
	$bytes    = file_put_contents( $sql_path, $sql );

	if ( false === $bytes ) {
		return new WP_Error(
			'failsafe_write_failed',
			sprintf(
				/* translators: %s: file path. */
				__( 'Could not write the dump to %s.', 'failsafe' ),
				$sql_path
			)
		);
	}

	$zip_path = FAILSAFE_BACKUP_DIR . '/failsafe-backup.zip';
	$zipped   = failsafe_create_zip( $sql_path, $zip_path );

	if ( is_wp_error( $zipped ) ) {
		return $zipped;
	}

	clearstatcache( true, $zip_path );

	return array(
		'sql_path'  => $sql_path,
		'zip_path'  => $zip_path,
		'sql_bytes' => $bytes,
		'zip_bytes' => (int) filesize( $zip_path ),
	);
}

/**
 * Builds a portable SQL dump of every table in the current database.
 *
 * @return string|WP_Error Dump contents on success.
 */
function failsafe_build_sql_dump() {
	global $wpdb;

	$tables = $wpdb->get_col( 'SHOW TABLES' );

	if ( $wpdb->last_error ) {
		return new WP_Error( 'failsafe_tables_failed', $wpdb->last_error );
	}

	if ( empty( $tables ) ) {
		return new WP_Error( 'failsafe_no_tables', __( 'No tables found in the database.', 'failsafe' ) );
	}

	$charset = preg_replace( '/[^A-Za-z0-9_]/', '', (string) $wpdb->charset );
	$collate = preg_replace( '/[^A-Za-z0-9_]/', '', (string) $wpdb->collate );

	$sql  = "-- Failsafe Backup SQL dump\n";
	$sql .= '-- Site: ' . home_url() . "\n";
	$sql .= '-- Date: ' . gmdate( 'c' ) . "\n\n";
	$sql .= "SET SESSION sql_mode = '';\n";
	$sql .= 'SET NAMES ' . ( $charset ? $charset : 'utf8mb4' );

	if ( $collate ) {
		$sql .= ' COLLATE ' . $collate;
	}

	$sql .= ";\nSET FOREIGN_KEY_CHECKS = 0;\n";
	$sql .= "START TRANSACTION WITH CONSISTENT SNAPSHOT;\n\n";

	foreach ( $tables as $table ) {
		$wpdb->last_error = '';

		$create = $wpdb->get_row( $wpdb->prepare( 'SHOW CREATE TABLE %i', $table ), ARRAY_A );

		if ( $wpdb->last_error ) {
			return new WP_Error( 'failsafe_create_table_failed', $table . ': ' . $wpdb->last_error );
		}

		if ( empty( $create ) || empty( $create['Create Table'] ) ) {
			// Views and other non-table objects are out of scope for this iteration.
			continue;
		}

		$sql .= $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) . ";\n";
		$sql .= $create['Create Table'] . ";\n\n";

		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i', $table ), ARRAY_A );

		if ( $wpdb->last_error ) {
			return new WP_Error( 'failsafe_select_failed', $table . ': ' . $wpdb->last_error );
		}

		if ( empty( $rows ) ) {
			continue;
		}

		$columns = array_keys( $rows[0] );
		$names   = array();

		foreach ( $columns as $column ) {
			$names[] = $wpdb->prepare( '%i', $column );
		}

		$sql_columns = implode( ', ', $names );
		$table_name  = $wpdb->prepare( '%i', $table );

		foreach ( $rows as $row ) {
			$values = array();

			foreach ( $columns as $column ) {
				$values[] = failsafe_sql_value( $row[ $column ] );
			}

			$sql .= 'INSERT INTO ' . $table_name . ' (' . $sql_columns . ') VALUES (' . implode( ', ', $values ) . ");\n";
		}

		$sql .= "\n";
	}

	$sql .= "COMMIT;\n";
	$sql .= "SET FOREIGN_KEY_CHECKS = 1;\n";

	return $sql;
}

/**
 * Converts a database value into a SQL literal.
 *
 * @param mixed $value Raw column value.
 * @return string
 */
function failsafe_sql_value( $value ) {
	if ( null === $value ) {
		return 'NULL';
	}

	if ( ! is_string( $value ) ) {
		return (string) $value;
	}

	// Binary or non-UTF-8 payloads cannot be quoted safely; emit a hex literal instead.
	if ( ! mb_check_encoding( $value, 'UTF-8' ) ) {
		return "X'" . bin2hex( $value ) . "'";
	}

	return "'" . esc_sql( $value ) . "'";
}

/**
 * Writes the SQL dump and every site file into a ZIP archive.
 *
 * @param string $sql_path Absolute path of the SQL dump.
 * @param string $zip_path Absolute path of the ZIP archive to create.
 * @return true|WP_Error True on success.
 */
function failsafe_create_zip( $sql_path, $zip_path ) {
	if ( ! class_exists( 'ZipArchive' ) ) {
		return new WP_Error( 'failsafe_no_ziparchive', __( 'The ZipArchive extension is not available.', 'failsafe' ) );
	}

	$zip    = new ZipArchive();
	$opened = $zip->open( $zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE );

	if ( true !== $opened ) {
		return new WP_Error(
			'failsafe_zip_open',
			sprintf(
				/* translators: %d: ZipArchive error code. */
				__( 'Could not open the archive (error %d).', 'failsafe' ),
				$opened
			)
		);
	}

	$add_errors = 0;

	if ( ! $zip->addFile( $sql_path, 'failsafe-backup.sql' ) ) {
		$add_errors++;
	}

	$root = rtrim( ABSPATH, '/\\' );
	$skip = array(
		FAILSAFE_BACKUP_DIR,
		$root . '/wp-content/cache',
		$root . '/wp-content/upgrade',
	);

	try {
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::LEAVES_ONLY
		);

		foreach ( $iterator as $file ) {
			$pathname = $file->getPathname();

			if ( failsafe_path_is_excluded( $pathname, $skip ) || ! is_readable( $pathname ) ) {
				continue;
			}

			if ( ! $zip->addFile( $pathname, substr( $pathname, strlen( $root ) + 1 ) ) ) {
				$add_errors++;
			}
		}
	} catch ( Exception $exception ) {
		$zip->close();

		return new WP_Error( 'failsafe_zip_iterate', $exception->getMessage() );
	}

	if ( ! $zip->close() ) {
		return new WP_Error( 'failsafe_zip_close', __( 'Could not finish the archive.', 'failsafe' ) );
	}

	if ( $add_errors > 0 ) {
		return new WP_Error(
			'failsafe_zip_partial',
			sprintf(
				/* translators: %d: number of files that could not be added. */
				__( 'The archive was written but %d files could not be added.', 'failsafe' ),
				$add_errors
			)
		);
	}

	return true;
}

/**
 * Tells whether a path is inside one of the excluded directories.
 *
 * @param string   $pathname Absolute path to test.
 * @param string[] $excluded Directories to skip.
 * @return bool
 */
function failsafe_path_is_excluded( $pathname, array $excluded ) {
	$real = realpath( $pathname );

	if ( false === $real ) {
		return false;
	}

	foreach ( $excluded as $directory ) {
		$real_dir = realpath( $directory );

		if ( false !== $real_dir && str_starts_with( $real, $real_dir . DIRECTORY_SEPARATOR ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Logs a message only when WordPress debugging is enabled.
 *
 * @param string $message Message to log.
 * @return void
 */
function failsafe_log( $message ) {
	if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
		error_log( 'failsafe: ' . $message );
	}
}
