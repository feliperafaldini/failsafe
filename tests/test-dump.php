<?php
/**
 * Unit checks for failsafe_build_sql_dump().
 *
 * Run inside the WordPress container:
 *   php /var/www/html/wp-content/plugins/failsafe/tests/test-dump.php
 *
 * @package Failsafe
 */

require '/var/www/html/wp-load.php';
require __DIR__ . '/assert.php';

global $wpdb;

echo "failsafe_build_sql_dump()\n";

$sql = failsafe_build_sql_dump();

failsafe_assert( is_string( $sql ), 'returns a string' );
failsafe_assert( false === is_wp_error( $sql ), 'returns no WP_Error' );

if ( ! is_string( $sql ) ) {
	failsafe_report();
}

$tables = $wpdb->get_col( 'SHOW TABLES' );

failsafe_assert( str_starts_with( $sql, '-- Failsafe Backup SQL dump' ), 'starts with the dump header' );
failsafe_assert( str_contains( $sql, 'SET NAMES utf8mb4' ), 'declares the utf8mb4 charset' );
failsafe_assert( str_ends_with( $sql, "SET FOREIGN_KEY_CHECKS = 1;\n" ), 're-enables foreign key checks at the end' );

failsafe_assert(
	count( $tables ) === substr_count( $sql, 'CREATE TABLE ' ),
	'has one CREATE TABLE per database table (' . count( $tables ) . ')'
);

failsafe_assert( substr_count( $sql, 'INSERT INTO ' ) > 0, 'contains INSERT statements' );

// Every statement must be terminated: the dump is one big text file.
$last_statement = substr( rtrim( $sql ), -1 );
failsafe_assert( ';' === $last_statement, 'last statement is terminated with a semicolon' );

// No table may be left without its CREATE TABLE.
foreach ( $tables as $table ) {
	failsafe_assert(
		str_contains( $sql, 'CREATE TABLE `' . $table . '`' ),
		'CREATE TABLE present for ' . $table
	);
}

// Restoring over an existing database must work: every table is dropped first.
foreach ( $tables as $table ) {
	failsafe_assert(
		str_contains( $sql, 'DROP TABLE IF EXISTS `' . $table . '`' ),
		'DROP TABLE IF EXISTS present for ' . $table
	);
}

failsafe_assert( str_contains( $sql, "SET SESSION sql_mode = ''" ), 'resets sql_mode for portability' );
failsafe_assert( str_contains( $sql, 'START TRANSACTION WITH CONSISTENT SNAPSHOT' ), 'opens a consistent snapshot' );
failsafe_assert( str_contains( $sql, 'COMMIT;' ), 'commits the snapshot before finishing' );

// Values must be quoted, never bare identifiers.
failsafe_assert( ! str_contains( $sql, 'VALUES (NULL)' ), 'rows are not collapsed into raw NULL' );

failsafe_report();
