<?php
/**
 * Streams a backup file to the browser.
 *
 * Only the base name and the file type come from the request. The path is
 * always assembled here, after validation, and never read from user input.
 *
 * @package Failsafe
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'admin_post_failsafe_download_backup', 'failsafe_handle_download_backup' );

/**
 * Handles a backup download request.
 *
 * @return void
 */
function failsafe_handle_download_backup() {
	if ( ! current_user_can( failsafe_backup_capability() ) ) {
		wp_die( '', 403 );
	}

	check_admin_referer( 'failsafe_download_backup' );

	$base = isset( $_GET['backup'] ) ? sanitize_key( wp_unslash( $_GET['backup'] ) ) : '';
	$type = isset( $_GET['tipo'] ) ? sanitize_key( wp_unslash( $_GET['tipo'] ) ) : '';

	if ( '' === $base || '' === $type ) {
		wp_die( '', 400 );
	}

	$path = failsafe_backup_path( $base, $type );

	if ( is_wp_error( $path ) ) {
		wp_die( '', 400 );
	}

	if ( is_link( $path ) ) {
		wp_die( '', 403 );
	}

	if ( ! file_exists( $path ) ) {
		wp_die( '', 404 );
	}

	$real     = realpath( $path );
	$real_dir = realpath( failsafe_backup_dir() );

	if ( false === $real || false === $real_dir || ! str_starts_with( $real, $real_dir . DIRECTORY_SEPARATOR ) ) {
		wp_die( '', 403 );
	}

	if ( ! is_file( $real ) || ! is_readable( $real ) ) {
		wp_die( '', 404 );
	}

	$size = filesize( $real );

	if ( false === $size ) {
		wp_die( '', 404 );
	}

	// Keep the response out of every cache, including the browser's.
	nocache_headers();

	// A compressed response would send a Content-Length that does not match the file.
	ini_set( 'zlib.output_compression', 'Off' ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- Needed so Content-Length matches the streamed file; see DESIGN.md section 8.

	header( 'Content-Type: ' . ( 'zip' === $type ? 'application/zip' : 'application/octet-stream' ) );
	header( 'Content-Disposition: attachment; filename="' . basename( $real ) . '"' );
	header( 'Content-Length: ' . (int) $size );
	header( 'X-Content-Type-Options: nosniff' );
	header( 'Cache-Control: private, no-store, max-age=0' );
	header( 'Referrer-Policy: no-referrer' );

	while ( ob_get_level() > 0 ) {
		ob_end_clean();
	}

	readfile( $real ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- Streaming keeps memory flat on large archives; see DESIGN.md D4.

	exit;
}
