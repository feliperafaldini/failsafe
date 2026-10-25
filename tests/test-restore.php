<?php
/**
 * Unit checks for the restore layer. No database is touched.
 *
 * Runs standalone (php tests/test-restore.php) or inside the WordPress
 * container (php /tmp/failsafe-tests/test-restore.php).
 *
 * @package Failsafe
 */

$failsafe_wp_load = '/var/www/html/wp-load.php';

if ( is_readable( $failsafe_wp_load ) ) {
	require $failsafe_wp_load;
} elseif ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

require __DIR__ . '/assert.php';

if ( ! function_exists( 'failsafe_split_sql_statements' ) ) {
	$failsafe_candidates = array( dirname( __DIR__ ) . '/src/includes/restore.php' );

	if ( defined( 'FAILSAFE_DIR' ) ) {
		$failsafe_candidates[] = FAILSAFE_DIR . '/includes/restore.php';
	}

	$failsafe_restore_file = '';

	foreach ( $failsafe_candidates as $failsafe_candidate ) {
		if ( is_readable( $failsafe_candidate ) ) {
			$failsafe_restore_file = $failsafe_candidate;
			break;
		}
	}

	if ( '' === $failsafe_restore_file ) {
		fwrite( STDERR, "Cannot find restore.php in:\n" . implode( "\n", $failsafe_candidates ) . "\n" );
		exit( 1 );
	}

	require_once $failsafe_restore_file;
}

echo "failsafe_split_sql_statements()\n";

failsafe_assert( array() === failsafe_split_sql_statements( '' ), 'empty input yields no statements' );
failsafe_assert( array() === failsafe_split_sql_statements( " \n\t " ), 'whitespace-only input yields no statements' );
failsafe_assert( array() === failsafe_split_sql_statements( ';;;' ), 'empty statements are skipped' );

failsafe_assert(
	array( 'SELECT 1', 'SELECT 2' ) === failsafe_split_sql_statements( 'SELECT 1; SELECT 2;' ),
	'two statements are split on the semicolon'
);

failsafe_assert(
	array( 'SELECT 1' ) === failsafe_split_sql_statements( 'SELECT 1' ),
	'a trailing statement without a semicolon is kept'
);

$sql        = "INSERT INTO `wp_options` (`option_value`) VALUES ('a;b');";
$statements = failsafe_split_sql_statements( $sql );

failsafe_assert( 1 === count( $statements ), 'semicolon inside a single-quoted string is not a separator' );
failsafe_assert( rtrim( $sql, ';' ) === $statements[0], 'semicolon inside a single-quoted string is preserved' );

$sql        = "INSERT INTO `wp_options` (`option_value`) VALUES ('It\\'s; fine');";
$statements = failsafe_split_sql_statements( $sql );

failsafe_assert( 1 === count( $statements ), 'backslash-escaped quote does not end the string' );
failsafe_assert( str_contains( $statements[0], "It\\'s; fine" ), 'escaped quote and semicolon survive parsing' );

$sql        = "INSERT INTO `wp_options` (`option_value`) VALUES ('It''s; fine');";
$statements = failsafe_split_sql_statements( $sql );

failsafe_assert( 1 === count( $statements ), 'doubled quote does not end the string' );
failsafe_assert( str_contains( $statements[0], "It''s; fine" ), 'doubled quote and semicolon survive parsing' );

$statements = failsafe_split_sql_statements( 'SELECT "a;b"; SELECT 2;' );

failsafe_assert( 2 === count( $statements ), 'semicolon inside a double-quoted string is not a separator' );
failsafe_assert( 'SELECT "a;b"' === $statements[0], 'double-quoted value is preserved' );

$statements = failsafe_split_sql_statements( "-- header; with semicolon\nSELECT 1; -- trailing; comment\nSELECT 2;" );

failsafe_assert( 2 === count( $statements ), 'dash comments are ignored' );
failsafe_assert( array( 'SELECT 1', 'SELECT 2' ) === $statements, 'dash comments do not leak into statements' );

$statements = failsafe_split_sql_statements( "# comment; here\nSELECT 1;" );

failsafe_assert( array( 'SELECT 1' ) === $statements, 'hash comments are ignored' );

$statements = failsafe_split_sql_statements( '/* comment; here */ SELECT 1;' );

failsafe_assert( array( 'SELECT 1' ) === $statements, 'block comments are ignored' );
failsafe_assert(
	array( 'SELECT 1' ) === failsafe_split_sql_statements( 'SELECT/* comment */1;' ),
	'a block comment separates tokens with whitespace'
);

$statements = failsafe_split_sql_statements( "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;\nCREATE TABLE `t` (`id` int);" );

failsafe_assert( 2 === count( $statements ), 'a dump with SET NAMES keeps both statements' );
failsafe_assert( 'SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci' === $statements[0], 'SET NAMES is preserved' );
failsafe_assert( 'CREATE TABLE `t` (`id` int)' === $statements[1], 'CREATE TABLE is preserved' );

$dump = <<<'SQL'
-- Failsafe Backup SQL dump
-- Site: http://example.test
-- Date: 2026-10-24T00:00:00+00:00

SET SESSION sql_mode = '';
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET FOREIGN_KEY_CHECKS = 0;
START TRANSACTION WITH CONSISTENT SNAPSHOT;

DROP TABLE IF EXISTS `wp_options`;
CREATE TABLE `wp_options` (
  `option_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `option_name` varchar(191) NOT NULL DEFAULT '',
  `option_value` longtext NOT NULL,
  PRIMARY KEY (`option_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `wp_options` (`option_id`, `option_name`, `option_value`) VALUES (1, 'siteurl', 'http://example.test;with;semicolons');
INSERT INTO `wp_options` (`option_id`, `option_name`, `option_value`) VALUES (2, 'quote', 'It''s a test; ok');

COMMIT;
SET FOREIGN_KEY_CHECKS = 1;
SQL;

$statements = failsafe_split_sql_statements( $dump );

failsafe_assert( 10 === count( $statements ), 'the full dump is split into ten statements (' . count( $statements ) . ')' );
failsafe_assert( str_starts_with( $statements[0], "SET SESSION sql_mode = ''" ), 'the first SET is preserved' );
failsafe_assert( 'SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci' === $statements[1], 'SET NAMES survives the dump header' );
failsafe_assert( 'DROP TABLE IF EXISTS `wp_options`' === $statements[4], 'DROP TABLE is its own statement' );
failsafe_assert( str_starts_with( $statements[5], 'CREATE TABLE `wp_options`' ), 'CREATE TABLE is one statement' );
failsafe_assert( str_contains( $statements[5], 'PRIMARY KEY (`option_id`)' ), 'CREATE TABLE keeps its body' );
failsafe_assert( str_contains( $statements[6], 'http://example.test;with;semicolons' ), 'INSERT keeps the semicolon inside the value' );
failsafe_assert( str_contains( $statements[7], "It''s a test; ok" ), 'INSERT keeps the doubled quote and semicolon' );
failsafe_assert( 'COMMIT' === $statements[8], 'COMMIT is its own statement' );
failsafe_assert( 'SET FOREIGN_KEY_CHECKS = 1' === $statements[9], 'the dump ends with foreign keys re-enabled' );

$delimiter_dump = <<<'SQL'
DELIMITER $$
CREATE PROCEDURE `p`()
BEGIN
  SELECT 'inside;procedure';
END$$
  DELIMITER ;
INSERT INTO `t` VALUES (1);
SQL;

$statements = failsafe_split_sql_statements( $delimiter_dump );

failsafe_assert( 2 === count( $statements ), 'DELIMITER directives are not returned as statements (' . count( $statements ) . ')' );
failsafe_assert( str_starts_with( $statements[0], 'CREATE PROCEDURE `p`' ), 'the procedure is one statement' );
failsafe_assert( str_contains( $statements[0], "SELECT 'inside;procedure'" ), 'semicolons inside the procedure are preserved' );
failsafe_assert( str_ends_with( $statements[0], 'END' ), 'the custom delimiter is removed from the procedure' );
failsafe_assert( 'INSERT INTO `t` VALUES (1)' === $statements[1], 'the delimiter is reset to the semicolon' );

echo "\nfailsafe_sql_statement_excerpt()\n";

$excerpt = failsafe_sql_statement_excerpt( "SELECT 1\n\tFROM   `t`" );

failsafe_assert( 'SELECT 1 FROM `t`' === $excerpt, 'the excerpt is collapsed to one line' );
failsafe_assert( strlen( failsafe_sql_statement_excerpt( str_repeat( 'a', 500 ) ) ) <= 203, 'the excerpt is truncated' );

echo "\nfailsafe_zip_entry_is_safe()\n";

failsafe_assert( failsafe_zip_entry_is_safe( 'failsafe-backup.sql' ), 'the SQL dump entry is safe' );
failsafe_assert( failsafe_zip_entry_is_safe( 'wp-content/uploads/2026/image.jpg' ), 'a nested site file is safe' );
failsafe_assert( ! failsafe_zip_entry_is_safe( '../evil.php' ), 'parent traversal is rejected' );
failsafe_assert( ! failsafe_zip_entry_is_safe( 'wp-content/../../evil.php' ), 'traversal in the middle is rejected' );
failsafe_assert( ! failsafe_zip_entry_is_safe( '/etc/passwd' ), 'absolute paths are rejected' );
failsafe_assert( ! failsafe_zip_entry_is_safe( 'C:/Windows/evil.php' ), 'drive letters are rejected' );
failsafe_assert( ! failsafe_zip_entry_is_safe( "wp-content\\evil.php" ), 'backslashes are rejected' );
failsafe_assert( ! failsafe_zip_entry_is_safe( '' ), 'empty entry names are rejected' );
failsafe_assert( ! failsafe_zip_entry_is_safe( "wp-content/evil\0.php" ), 'null bytes are rejected' );

failsafe_report();
