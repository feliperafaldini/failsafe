<?php
/**
 * Unit checks for failsafe_create_backup() and failsafe_create_zip().
 *
 * Run inside the WordPress container:
 *   php /var/www/html/wp-content/plugins/failsafe/tests/test-zip.php
 *
 * @package Failsafe
 */

require '/var/www/html/wp-load.php';
require __DIR__ . '/assert.php';

echo "failsafe_create_backup()\n";

$result = failsafe_create_backup();

failsafe_assert( false === is_wp_error( $result ), 'returns no WP_Error' );

if ( is_wp_error( $result ) ) {
	echo '  -> ' . $result->get_error_message() . "\n";
	failsafe_report();
}

failsafe_assert( is_array( $result ), 'returns the result array' );
failsafe_assert( file_exists( $result['sql_path'] ), 'SQL dump exists on disk' );
failsafe_assert( file_exists( $result['zip_path'] ), 'ZIP archive exists on disk' );
failsafe_assert( $result['sql_bytes'] > 1000, 'SQL dump is not empty (' . $result['sql_bytes'] . ' bytes)' );
failsafe_assert( $result['zip_bytes'] > 1000, 'ZIP archive is not empty (' . $result['zip_bytes'] . ' bytes)' );

echo "\nfailsafe_create_zip() contents\n";

$zip = new ZipArchive();

failsafe_assert( true === $zip->open( $result['zip_path'], ZipArchive::CHECKCONS ), 'archive opens with a consistency check' );
failsafe_assert( $zip->numFiles > 1000, 'archive holds the whole site (' . $zip->numFiles . ' entries)' );
$sql_name = basename( $result['sql_path'] );

failsafe_assert( false !== $zip->locateName( $sql_name ), 'archive contains the SQL dump (' . $sql_name . ')' );
failsafe_assert( false !== $zip->locateName( 'wp-config.php' ), 'archive contains wp-config.php' );
failsafe_assert( false !== $zip->locateName( 'wp-includes/version.php' ), 'archive contains core files' );
failsafe_assert( false !== $zip->locateName( 'wp-content/plugins/failsafe/failsafe.php' ), 'archive contains the plugin itself' );

$leaked = 0;

for ( $i = 0; $i < $zip->numFiles; $i++ ) {
	$name = $zip->getNameIndex( $i );

	if ( str_starts_with( $name, 'wp-content/failsafe-backups' ) ) {
		$leaked++;
	}
}

failsafe_assert( 0 === $leaked, 'no backup files inside the archive (found ' . $leaked . ')' );

$zip->close();

failsafe_report();
