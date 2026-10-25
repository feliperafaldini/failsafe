<?php
/**
 * Unit checks for the SQL parser and the archive entry validation used by restore.
 *
 * These tests never touch the database.
 *
 * Run inside the WordPress container:
 *   php /var/www/html/wp-content/plugins/failsafe-backup/tests/test-restore.php
 *
 * @package Failsafe
 */

require '/var/www/html/wp-load.php';
require_once '/var/www/html/wp-content/plugins/failsafe-backup/includes/restore.php';
require __DIR__ . '/assert.php';

echo "failsafe_split_sql_statements()\n";

failsafe_assert( array() === failsafe_split_sql_statements( '' ), 'an empty dump yields no statements' );
failsafe_assert( array() === failsafe_split_sql_statements( "  \n\t\r\n " ), 'a whitespace-only dump yields no statements' );
failsafe_assert(
	array() === failsafe_split_sql_statements( "-- a comment;\n# another comment;\n/* one more; */\n" ),
	'a comment-only dump yields no statements'
);

$statements = failsafe_split_sql_statements( "INSERT INTO `t` (`v`) VALUES ('a;b');" );
failsafe_assert( 1 === count( $statements ), 'a semicolon inside single quotes does not split the statement' );
failsafe_assert( "INSERT INTO `t` (`v`) VALUES ('a;b')" === $statements[0], 'the single-quoted value is kept verbatim' );

$statements = failsafe_split_sql_statements( 'INSERT INTO `t` (`v`) VALUES ("a;b");' );
failsafe_assert( 1 === count( $statements ), 'a semicolon inside double quotes does not split the statement' );

$statements = failsafe_split_sql_statements( 'CREATE TABLE `a;b` (`id` int);' );
failsafe_assert( 1 === count( $statements ), 'a semicolon inside a backtick identifier does not split the statement' );

$statements = failsafe_split_sql_statements( "SELECT 1; -- a comment; with a semicolon\nSELECT 2;" );
failsafe_assert( 2 === count( $statements ), 'a -- comment hides its semicolon' );
failsafe_assert( 'SELECT 2' === $statements[1], 'the statement after the -- comment is intact' );

$statements = failsafe_split_sql_statements( "SELECT 1; # a comment; with a semicolon\nSELECT 2;" );
failsafe_assert( 2 === count( $statements ), 'a # comment hides its semicolon' );

$statements = failsafe_split_sql_statements( 'SELECT 1; /* ; -- # */ SELECT 2;' );
failsafe_assert( 2 === count( $statements ), 'a block comment hides its semicolon' );
failsafe_assert( 'SELECT 2' === $statements[1], 'the statement after the block comment is intact' );

$statements = failsafe_split_sql_statements( 'SELECT 1; SELECT 2' );
failsafe_assert( 2 === count( $statements ), 'a final statement without a delimiter is still returned' );

$statements = failsafe_split_sql_statements( "/*!40101 SET NAMES utf8 */;\nSELECT 1;" );
failsafe_assert( 2 === count( $statements ), 'an executable version comment becomes a statement' );
failsafe_assert( 'SET NAMES utf8' === $statements[0], 'the version number is stripped from the statement' );

$statements = failsafe_split_sql_statements( "\xEF\xBB\xBFSET NAMES utf8mb4;" );
failsafe_assert( array( 'SET NAMES utf8mb4' ) === $statements, 'a UTF-8 byte order mark is ignored' );

// A backslash-escaped quote must not close the string.
$statements = failsafe_split_sql_statements( "INSERT INTO `t` (`v`) VALUES ('it\\'s; fine');" );
failsafe_assert( 1 === count( $statements ), 'a backslash-escaped quote does not close the string' );
failsafe_assert( str_contains( $statements[0], "it\\'s; fine" ), 'the escaped quote is kept verbatim' );

// A doubled quote is SQL's other way to escape a quote.
$statements = failsafe_split_sql_statements( "INSERT INTO `t` (`v`) VALUES ('it''s; fine');" );
failsafe_assert( 1 === count( $statements ), 'a doubled quote does not close the string' );

// MySQL only starts a "--" comment when whitespace follows the dashes.
$statements = failsafe_split_sql_statements( 'SELECT 5--3; SELECT 1;' );
failsafe_assert( 2 === count( $statements ), '"--" without trailing whitespace is not a comment' );
failsafe_assert( 'SELECT 5--3' === $statements[0], '"5--3" is preserved as SQL' );

echo "\nDump shape produced by failsafe_build_sql_dump()\n";

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
  `option_value` longtext NOT NULL,
  PRIMARY KEY (`option_id`)
);

INSERT INTO `wp_options` (`option_id`, `option_value`) VALUES (1, 'a;b');
INSERT INTO `wp_options` (`option_id`, `option_value`) VALUES (2, 'it\'s; fine');
INSERT INTO `wp_options` (`option_id`, `option_value`) VALUES (3, 'say "hi"; now');
INSERT INTO `wp_options` (`option_id`, `option_value`) VALUES (4, X'00ff');
COMMIT;
SET FOREIGN_KEY_CHECKS = 1;
SQL;

$statements = failsafe_split_sql_statements( $dump );

failsafe_assert( 12 === count( $statements ), 'the dump is split into the expected number of statements (' . count( $statements ) . ')' );
failsafe_assert( "SET SESSION sql_mode = ''" === $statements[0], 'the first statement is SET SESSION sql_mode' );
failsafe_assert( 'SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci' === $statements[1], 'the SET NAMES statement survives intact' );
failsafe_assert( 'START TRANSACTION WITH CONSISTENT SNAPSHOT' === $statements[3], 'the snapshot statement is preserved' );
failsafe_assert( 'DROP TABLE IF EXISTS `wp_options`' === $statements[4], 'the DROP TABLE statement is preserved' );
failsafe_assert( str_starts_with( $statements[5], "CREATE TABLE `wp_options` (\n" ), 'the CREATE TABLE keeps its layout' );
failsafe_assert( str_contains( $statements[5], "`option_value` longtext NOT NULL" ), 'the CREATE TABLE body is complete' );
failsafe_assert( str_ends_with( $statements[5], ')' ), 'the CREATE TABLE does not keep the delimiter' );
failsafe_assert( 4 === count( array_filter( $statements, static function ( $statement ) { return str_starts_with( $statement, 'INSERT INTO' ); } ) ), 'every INSERT is returned as its own statement' );
failsafe_assert( 'COMMIT' === $statements[10], 'COMMIT is the eleventh statement' );
failsafe_assert( 'SET FOREIGN_KEY_CHECKS = 1' === $statements[11], 'the last statement re-enables foreign key checks' );

foreach ( $statements as $statement ) {
	failsafe_assert( ! str_starts_with( $statement, '--' ), 'no comment leaks into a statement' );
}

echo "\nDELIMITER directives\n";

$sql = <<<'SQL'
DELIMITER //
CREATE PROCEDURE p()
BEGIN
	SELECT 1;
	SELECT ';';
END//
DELIMITER ;
SELECT 3;
SQL;

$statements = failsafe_split_sql_statements( $sql );

failsafe_assert( 2 === count( $statements ), 'DELIMITER // keeps the procedure body in one statement (' . count( $statements ) . ')' );
failsafe_assert( str_contains( $statements[0], "SELECT 1;\n\tSELECT ';';" ), 'semicolons inside the procedure body are kept' );
failsafe_assert( 'SELECT 3' === $statements[1], 'DELIMITER ; restores the default delimiter' );

echo "\nfailsafe_restore_validate_entry_name()\n";

failsafe_assert( ! is_wp_error( failsafe_restore_validate_entry_name( 'wp-content/plugins/failsafe-backup/failsafe.php' ) ), 'a normal relative path is accepted' );
failsafe_assert( ! is_wp_error( failsafe_restore_validate_entry_name( 'wp-content/./failsafe.php' ) ), 'a single dot segment is accepted' );
failsafe_assert( is_wp_error( failsafe_restore_validate_entry_name( '../evil.php' ) ), 'a leading ../ is rejected' );
failsafe_assert( is_wp_error( failsafe_restore_validate_entry_name( 'wp-content/../../evil.php' ) ), 'a nested ../ is rejected' );
failsafe_assert( is_wp_error( failsafe_restore_validate_entry_name( 'wp-content\\..\\evil.php' ) ), 'a Windows style ../ is rejected' );
failsafe_assert( is_wp_error( failsafe_restore_validate_entry_name( '/etc/passwd' ) ), 'an absolute path is rejected' );
failsafe_assert( is_wp_error( failsafe_restore_validate_entry_name( 'C:\\Windows\\evil.php' ) ), 'an absolute Windows path is rejected' );
failsafe_assert( is_wp_error( failsafe_restore_validate_entry_name( "a\0b" ) ), 'a null byte is rejected' );
failsafe_assert( is_wp_error( failsafe_restore_validate_entry_name( '' ) ), 'an empty entry name is rejected' );

echo "\nfailsafe_extract_backup_archive()\n";

$temp_root = trailingslashit( get_temp_dir() );

$safe_zip = $temp_root . 'failsafe-test-safe-' . wp_generate_password( 8, false, false ) . '.zip';
$zip      = new ZipArchive();

failsafe_assert( true === $zip->open( $safe_zip, ZipArchive::CREATE | ZipArchive::OVERWRITE ), 'a test archive can be created' );
$zip->addFromString( 'failsafe-backup.sql', "SELECT 1;\n" );
$zip->addFromString( 'wp-content/plugins/failsafe-backup/failsafe.php', "<?php\n" );
$zip->close();

$extracted = failsafe_extract_backup_archive( $safe_zip );

failsafe_assert( ! is_wp_error( $extracted ), 'a clean archive is extracted' );

if ( ! is_wp_error( $extracted ) ) {
	failsafe_assert( is_readable( trailingslashit( $extracted ) . 'failsafe-backup.sql' ), 'the SQL dump is extracted' );
	failsafe_assert( is_readable( trailingslashit( $extracted ) . 'wp-content/plugins/failsafe-backup/failsafe.php' ), 'nested site files are extracted' );
	failsafe_assert( failsafe_remove_temp_directory( $extracted ), 'the extraction directory is removed' );
	failsafe_assert( ! file_exists( $extracted ), 'the extraction directory is gone' );
}

unlink( $safe_zip );

$evil_zip = $temp_root . 'failsafe-test-evil-' . wp_generate_password( 8, false, false ) . '.zip';
$zip      = new ZipArchive();
$zip->open( $evil_zip, ZipArchive::CREATE | ZipArchive::OVERWRITE );
$zip->addFromString( '../evil.php', "<?php\n" );
$zip->close();

$target = $temp_root . 'failsafe-test-evil-target-' . wp_generate_password( 8, false, false );
$result = failsafe_extract_backup_archive( $evil_zip, $target );

failsafe_assert( is_wp_error( $result ), 'an archive with ../ is rejected' );
failsafe_assert( is_wp_error( $result ) && 'failsafe_restore_entry_rejected' === $result->get_error_code(), 'the rejection code names the entry check' );
failsafe_assert( ! file_exists( dirname( $target ) . '/evil.php' ), 'nothing was written outside the extraction directory' );
failsafe_assert( ! is_dir( $target ), 'the temporary directory of a rejected archive is removed' );

unlink( $evil_zip );

$link_zip = $temp_root . 'failsafe-test-link-' . wp_generate_password( 8, false, false ) . '.zip';
$zip      = new ZipArchive();
$zip->open( $link_zip, ZipArchive::CREATE | ZipArchive::OVERWRITE );
$zip->addFromString( 'link', '/etc/passwd' );
$zip->setExternalAttributesName( 'link', ZipArchive::OPSYS_UNIX, 0120777 << 16 );
$zip->close();

$result = failsafe_extract_backup_archive( $link_zip );

failsafe_assert( is_wp_error( $result ), 'an archive with a symlink is rejected' );
failsafe_assert( is_wp_error( $result ) && 'failsafe_restore_entry_symlink' === $result->get_error_code(), 'the rejection code names the symlink check' );

unlink( $link_zip );

echo "\nfailsafe_restore_allowed_statement()\n";

$allowed_statements = array(
	'CREATE TABLE `wp_test` ( `id` int )',
	'DROP TABLE IF EXISTS `wp_test`',
	'INSERT INTO `wp_test` VALUES ( 1 )',
	'SET NAMES utf8mb4',
	'SET SESSION sql_mode = \'\'',
	'ALTER TABLE `wp_test` DISABLE KEYS',
	'START TRANSACTION WITH CONSISTENT SNAPSHOT',
	'COMMIT',
	'LOCK TABLES `wp_test` WRITE',
);

foreach ( $allowed_statements as $statement ) {
	failsafe_assert( failsafe_restore_allowed_statement( $statement ), 'allowed: ' . $statement );
}

$blocked_statements = array(
	'DROP DATABASE wp_local',
	'UPDATE wp_users SET user_pass = \'x\'',
	'DELETE FROM wp_users',
	'TRUNCATE TABLE wp_users',
	'GRANT ALL ON *.* TO \'x\'@\'%\'',
	'CREATE USER \'x\'@\'%\' IDENTIFIED BY \'y\'',
	'LOAD DATA INFILE \'/etc/passwd\' INTO TABLE wp_users',
	'SELECT SLEEP( 10 )',
	'CALL some_procedure()',
);

foreach ( $blocked_statements as $statement ) {
	failsafe_assert( ! failsafe_restore_allowed_statement( $statement ), 'blocked: ' . $statement );
}

// End-to-end: a blocked command must stop the restore before touching the database.
// The UPDATE below matches no row, so it is harmless even if the guard ever regressed.
$blocked = failsafe_restore_database( 'UPDATE wp_options SET option_value = option_value WHERE option_name = \'__failsafe_missing__\';' );

failsafe_assert( is_wp_error( $blocked ), 'a dump with a blocked statement is rejected' );
failsafe_assert(
	is_wp_error( $blocked ) && 'failsafe_restore_statement_blocked' === $blocked->get_error_code(),
	'the rejection code names the statement guard'
);

failsafe_report();
