<?php
/**
 * Streaming SQL dump writer.
 *
 * failsafe_build_sql_dump() keeps the whole dump in a string, so the plugin
 * can only back up a database that fits twice in memory_limit. The writer
 * below produces the very same SQL text straight to disk: tables are walked
 * in chunks and only the current chunk plus one batch of INSERT statements is
 * held in memory, so memory use no longer grows with the table size.
 *
 * @package Failsafe
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'FAILSAFE_DUMP_CHUNK_SIZE' ) ) {
	// Rows read per query while walking a table.
	define( 'FAILSAFE_DUMP_CHUNK_SIZE', 500 );
}

if ( ! defined( 'FAILSAFE_DUMP_INSERT_BATCH' ) ) {
	// INSERT statements kept in memory before they are written to disk.
	define( 'FAILSAFE_DUMP_INSERT_BATCH', 100 );
}

/**
 * Rows read per query while the dump walks a table.
 *
 * @return int
 */
function failsafe_dump_chunk_size(): int {
	return max( 1, (int) apply_filters( 'failsafe_dump_chunk_size', FAILSAFE_DUMP_CHUNK_SIZE ) );
}

/**
 * INSERT statements written to disk in one go.
 *
 * @return int
 */
function failsafe_dump_insert_batch(): int {
	return max( 1, (int) apply_filters( 'failsafe_dump_insert_batch', FAILSAFE_DUMP_INSERT_BATCH ) );
}

/**
 * Writes a portable SQL dump of every table to a file.
 *
 * The output is byte for byte the text failsafe_build_sql_dump() returns,
 * except for the "-- Date:" line, which changes between runs.
 *
 * @param string $path Absolute path of the file to write.
 * @return int|WP_Error Bytes written on success, WP_Error on failure.
 */
function failsafe_write_sql_dump( string $path ): int|WP_Error {
	if ( '' === $path ) {
		return new WP_Error( 'failsafe_dump_path_empty', __( 'No file was given for the SQL dump.', 'failsafe' ) );
	}

	$tables = failsafe_dump_tables();

	if ( is_wp_error( $tables ) ) {
		return $tables;
	}

	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- the dump is written in chunks; buffering it in memory is exactly what this function replaces.
	$handle = fopen( $path, 'wb' );

	if ( false === $handle ) {
		return new WP_Error(
			'failsafe_dump_open_failed',
			sprintf(
				/* translators: %s: file path. */
				__( 'Could not open %s for writing.', 'failsafe' ),
				$path
			)
		);
	}

	$bytes = failsafe_dump_write_body( $handle, $tables, $path );
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- the handle was opened here for a streamed write; WP_Filesystem has no appendable handle.
	$saved = fclose( $handle );

	if ( is_wp_error( $bytes ) ) {
		// A truncated file must never look like a finished backup.
		wp_delete_file( $path );

		return $bytes;
	}

	if ( ! $saved ) {
		wp_delete_file( $path );

		return new WP_Error(
			'failsafe_dump_close_failed',
			sprintf(
				/* translators: %s: file path. */
				__( 'Could not finish writing %s.', 'failsafe' ),
				$path
			)
		);
	}

	return $bytes;
}

/**
 * Builds the dump header.
 *
 * @return string
 */
function failsafe_dump_header(): string {
	global $wpdb;

	$charset = preg_replace( '/[^A-Za-z0-9_]/', '', (string) $wpdb->charset );
	$collate = preg_replace( '/[^A-Za-z0-9_]/', '', (string) $wpdb->collate );

	$header  = "-- Failsafe Backup SQL dump\n";
	$header .= '-- Site: ' . home_url() . "\n";
	$header .= '-- Date: ' . gmdate( 'c' ) . "\n\n";
	$header .= "SET SESSION sql_mode = '';\n";
	$header .= 'SET NAMES ' . ( $charset ? $charset : 'utf8mb4' );

	if ( $collate ) {
		$header .= ' COLLATE ' . $collate;
	}

	$header .= ";\nSET FOREIGN_KEY_CHECKS = 0;\n";
	$header .= "START TRANSACTION WITH CONSISTENT SNAPSHOT;\n\n";

	return $header;
}

/**
 * Builds the dump footer.
 *
 * @return string
 */
function failsafe_dump_footer(): string {
	return "COMMIT;\nSET FOREIGN_KEY_CHECKS = 1;\n";
}

/**
 * Lists every table of the current database, views included.
 *
 * @return string[]|WP_Error
 */
function failsafe_dump_tables() {
	global $wpdb;

	$wpdb->last_error = '';

	// SHOW TABLES takes no placeholders and the list is specific to this request.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The table list feeds the dump; SHOW TABLES cannot be prepared and is not worth caching.
	$tables = $wpdb->get_col( 'SHOW TABLES' );

	if ( $wpdb->last_error ) {
		return new WP_Error( 'failsafe_tables_failed', $wpdb->last_error );
	}

	if ( empty( $tables ) ) {
		return new WP_Error( 'failsafe_no_tables', __( 'No tables found in the database.', 'failsafe' ) );
	}

	return $tables;
}

/**
 * Writes the header, every table and the footer to an open file.
 *
 * @param resource $handle Open file handle.
 * @param string[] $tables Table names, in dump order.
 * @param string   $path   Path used in error messages.
 * @return int|WP_Error Bytes written.
 */
function failsafe_dump_write_body( $handle, array $tables, string $path ) {
	global $wpdb;

	$total = failsafe_dump_write( $handle, failsafe_dump_header(), $path );

	if ( is_wp_error( $total ) ) {
		return $total;
	}

	$chunk_size = failsafe_dump_chunk_size();
	$batch_size = failsafe_dump_insert_batch();

	foreach ( $tables as $table ) {
		$wpdb->last_error = '';

		// Reading the DDL is required to rebuild the table; the identifier goes through %i.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- SHOW CREATE TABLE only reads the schema for the dump and cannot be prepared beyond the %i table name.
		$create = $wpdb->get_row( $wpdb->prepare( 'SHOW CREATE TABLE %i', $table ), ARRAY_A );

		if ( $wpdb->last_error ) {
			return new WP_Error( 'failsafe_create_table_failed', $table . ': ' . $wpdb->last_error );
		}

		if ( empty( $create ) || empty( $create['Create Table'] ) ) {
			// Views and other non-table objects are out of scope for this iteration.
			continue;
		}

		$written = failsafe_dump_write(
			$handle,
			$wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) . ";\n" . $create['Create Table'] . ";\n\n",
			$path
		);

		if ( is_wp_error( $written ) ) {
			return $written;
		}

		$total += $written;

		$primary_key = failsafe_dump_primary_key( $table );

		if ( is_wp_error( $primary_key ) ) {
			return $primary_key;
		}

		$written = failsafe_dump_rows( $handle, $table, $primary_key, $chunk_size, $batch_size, $path );

		if ( is_wp_error( $written ) ) {
			return $written;
		}

		$total += $written;
	}

	$written = failsafe_dump_write( $handle, failsafe_dump_footer(), $path );

	if ( is_wp_error( $written ) ) {
		return $written;
	}

	return $total + $written;
}

/**
 * Reads the primary key of a table.
 *
 * The key is only usable for paging when every part is a plain column: a
 * functional key or an index prefix cannot be compared with a row
 * constructor, so those tables fall back to LIMIT/OFFSET.
 *
 * @param string $table Table name.
 * @return string[]|WP_Error Column names in key order, empty when paging by offset.
 */
function failsafe_dump_primary_key( string $table ) {
	global $wpdb;

	$wpdb->last_error = '';

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- SHOW KEYS only reads metadata and cannot be prepared beyond the %i table name.
	$keys = $wpdb->get_results( $wpdb->prepare( 'SHOW KEYS FROM %i WHERE Key_name = %s', $table, 'PRIMARY' ), ARRAY_A );

	if ( $wpdb->last_error ) {
		return new WP_Error( 'failsafe_dump_keys_failed', $table . ': ' . $wpdb->last_error );
	}

	if ( empty( $keys ) ) {
		return array();
	}

	$ordered = array();

	foreach ( $keys as $key ) {
		if ( empty( $key['Column_name'] ) || ! empty( $key['Sub_part'] ) ) {
			return array();
		}

		$ordered[ (int) $key['Seq_in_index'] ] = (string) $key['Column_name'];
	}

	ksort( $ordered );

	return array_values( $ordered );
}

/**
 * Reads one chunk of rows from a table.
 *
 * Tables with a primary key are paged with a key range, which keeps every
 * query on the index. Tables without one fall back to LIMIT/OFFSET, which
 * makes the server scan the rows it skips and is therefore much slower on
 * large tables.
 *
 * @param string     $table  Table name.
 * @param string[]   $key    Primary key columns, in key order.
 * @param array|null $last   Key values of the last row already read, null on the first chunk.
 * @param int        $offset Rows to skip when there is no primary key.
 * @param int        $limit  Maximum number of rows to read.
 * @return array|WP_Error
 */
function failsafe_dump_chunk( string $table, array $key, $last, int $offset, int $limit ) {
	global $wpdb;

	$limit = max( 1, $limit );

	if ( ! empty( $key ) ) {
		$sql     = 'SELECT * FROM ' . $wpdb->prepare( '%i', $table );
		$columns = array();

		foreach ( $key as $column ) {
			$columns[] = $wpdb->prepare( '%i', $column );
		}

		if ( null !== $last ) {
			$right = array();

			foreach ( array_keys( $key ) as $position ) {
				// Values come from the table itself; failsafe_sql_value() quotes them the same way the dump does.
				$right[] = failsafe_sql_value( $last[ $position ] );
			}

			if ( 1 === count( $key ) ) {
				$sql .= ' WHERE ' . $columns[0] . ' > ' . $right[0];
			} else {
				$sql .= ' WHERE (' . implode( ', ', $columns ) . ') > (' . implode( ', ', $right ) . ')';
			}
		}

		$sql .= ' ORDER BY ' . implode( ' ASC, ', $columns ) . ' ASC LIMIT ' . $limit;
	} else {
		$sql = $wpdb->prepare( 'SELECT * FROM %i LIMIT %d OFFSET %d', $table, $limit, max( 0, $offset ) );
	}

	$wpdb->last_error = '';

	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- every identifier goes through %i and every paging value through failsafe_sql_value(); a key range cannot be written as one prepared template.
	$rows = $wpdb->get_results( $sql, ARRAY_A );

	if ( $wpdb->last_error ) {
		return new WP_Error( 'failsafe_dump_select_failed', $table . ': ' . $wpdb->last_error );
	}

	return is_array( $rows ) ? $rows : array();
}

/**
 * Writes every row of a table as INSERT statements, one chunk at a time.
 *
 * @param resource $handle     Open file handle.
 * @param string   $table      Table name.
 * @param string[] $key        Primary key columns, in key order.
 * @param int      $chunk_size Rows per query.
 * @param int      $batch_size INSERT statements per write.
 * @param string   $path       Path used in error messages.
 * @return int|WP_Error Bytes written for this table.
 */
function failsafe_dump_rows( $handle, string $table, array $key, int $chunk_size, int $batch_size, string $path ) {
	global $wpdb;

	$table_name  = $wpdb->prepare( '%i', $table );
	$columns     = null;
	$sql_columns = '';
	$batch       = array();
	$last        = null;
	$offset      = 0;
	$total       = 0;

	while ( true ) {
		$rows = failsafe_dump_chunk( $table, $key, $last, $offset, $chunk_size );

		if ( is_wp_error( $rows ) ) {
			return $rows;
		}

		if ( empty( $rows ) ) {
			break;
		}

		if ( null === $columns ) {
			$columns = array_keys( $rows[0] );
			$names   = array();

			foreach ( $columns as $column ) {
				$names[] = $wpdb->prepare( '%i', $column );
			}

			$sql_columns = implode( ', ', $names );
		}

		foreach ( $rows as $row ) {
			$values = array();

			foreach ( $columns as $column ) {
				$values[] = failsafe_sql_value( $row[ $column ] );
			}

			$batch[] = 'INSERT INTO ' . $table_name . ' (' . $sql_columns . ') VALUES (' . implode( ', ', $values ) . ");\n";

			if ( count( $batch ) < $batch_size ) {
				continue;
			}

			$written = failsafe_dump_write( $handle, implode( '', $batch ), $path );

			if ( is_wp_error( $written ) ) {
				return $written;
			}

			$total += $written;
			$batch  = array();
		}

		if ( count( $rows ) < $chunk_size ) {
			break;
		}

		if ( ! empty( $key ) ) {
			$last_row = $rows[ count( $rows ) - 1 ];
			$next     = array();

			foreach ( $key as $column ) {
				$next[] = $last_row[ $column ];
			}

			if ( $next === $last ) {
				// A key range that does not move would loop forever and fill the disk.
				return new WP_Error(
					'failsafe_dump_paging_stalled',
					sprintf(
						/* translators: %s: table name. */
						__( 'Could not page through %s: the primary key did not advance.', 'failsafe' ),
						$table
					)
				);
			}

			$last = $next;
		} else {
			$offset += count( $rows );
		}
	}

	if ( ! empty( $batch ) ) {
		$written = failsafe_dump_write( $handle, implode( '', $batch ), $path );

		if ( is_wp_error( $written ) ) {
			return $written;
		}

		$total += $written;
	}

	if ( 0 === $total ) {
		return 0;
	}

	// The blank line that separates two tables in the dump.
	$written = failsafe_dump_write( $handle, "\n", $path );

	if ( is_wp_error( $written ) ) {
		return $written;
	}

	return $total + $written;
}

/**
 * Writes a string to the dump, retrying short writes.
 *
 * @param resource $handle Open file handle.
 * @param string   $data   Text to append.
 * @param string   $path   Path used in error messages.
 * @return int|WP_Error Bytes written.
 */
function failsafe_dump_write( $handle, string $data, string $path ) {
	$length = strlen( $data );
	$offset = 0;

	while ( $offset < $length ) {
		$buffer = ( 0 === $offset ) ? $data : substr( $data, $offset );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- the dump is streamed to disk; buffers keep memory flat and let a short write be retried.
		$written = fwrite( $handle, $buffer );

		if ( false === $written || 0 === $written ) {
			return new WP_Error(
				'failsafe_dump_write_failed',
				sprintf(
					/* translators: %s: file path. */
					__( 'Could not write to %s.', 'failsafe' ),
					$path
				)
			);
		}

		$offset += $written;
	}

	return $length;
}
