<?php
/**
 * Presentation helpers for the backups screen.
 *
 * @package Failsafe
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Formats a timestamp using the site date and time settings.
 *
 * @param int $timestamp Unix timestamp.
 * @return string
 */
function failsafe_format_time( $timestamp ) {
	$format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );

	return (string) wp_date( $format, (int) $timestamp );
}

/**
 * Formats a byte count for display.
 *
 * One decimal keeps the difference between 1 MB and 1.3 MB visible.
 *
 * @param int $bytes Number of bytes.
 * @return string
 */
function failsafe_format_size( $bytes ) {
	return (string) size_format( (int) $bytes, 1 );
}
