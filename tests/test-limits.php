<?php
/**
 * Unit checks for the calculation helpers in includes/limits.php.
 *
 * These assertions need no database and no WordPress bootstrap:
 *   php tests/test-limits.php
 *
 * Inside the container the file also works after wp-load, as the other test
 * scripts do.
 *
 * @package Failsafe
 */

$limits_file = dirname( __DIR__ ) . '/src/includes/limits.php';

if ( ! function_exists( 'failsafe_parse_size' ) && is_readable( $limits_file ) ) {
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', dirname( __DIR__ ) . '/' );
	}

	require $limits_file;
}

// Running from /tmp inside the container: the plugin is loaded by wp-load.php.
if ( ! function_exists( 'failsafe_parse_size' ) && is_readable( '/var/www/html/wp-load.php' ) ) {
	require '/var/www/html/wp-load.php';
}

// Last chance: the plugin is installed but was not loaded yet.
if ( ! function_exists( 'failsafe_parse_size' ) ) {
	$installed = '/var/www/html/wp-content/plugins/failsafe-backup/includes/limits.php';

	if ( is_readable( $installed ) ) {
		require $installed;
	}
}

if ( ! function_exists( 'failsafe_parse_size' ) ) {
	echo "includes/limits.php could not be loaded\n";
	exit( 1 );
}

require __DIR__ . '/assert.php';

$one_mb = 1024 * 1024;

echo "failsafe_parse_size()\n";

$sizes = array(
	'128M'      => 128 * $one_mb,
	'1G'        => 1024 * $one_mb,
	'512K'      => 512 * 1024,
	'2T'        => 2 * 1024 * 1024 * 1024 * 1024,
	'1.5G'      => (int) ( 1.5 * 1024 * $one_mb ),
	'268435456' => 268435456,
	'128m'      => 128 * $one_mb,
	' 64M '     => 64 * $one_mb,
	'-1'        => -1,
	'0'         => 0,
);

foreach ( $sizes as $raw => $expected ) {
	failsafe_assert(
		$expected === failsafe_parse_size( $raw ),
		sprintf( '"%s" parses to %d', $raw, $expected )
	);
}

failsafe_assert( 128 === failsafe_parse_size( 128 ), 'integer input is returned as is' );
failsafe_assert( -1 === failsafe_parse_size( -1 ), 'negative integer means no limit' );
failsafe_assert( 0 === failsafe_parse_size( '' ), 'empty value is treated as unknown' );
failsafe_assert( 0 === failsafe_parse_size( 'lots' ), 'unparsable value is treated as unknown' );

echo "\nfailsafe_required_memory_bytes()\n";

failsafe_assert( 64 * $one_mb === FAILSAFE_MIN_MEMORY_BYTES, 'the memory floor is 64 MB' );
failsafe_assert(
	64 * $one_mb === failsafe_required_memory_bytes( $one_mb ),
	'the requirement is the streaming floor, not a multiple of the database'
);
failsafe_assert(
	64 * $one_mb === failsafe_required_memory_bytes( 4096 * $one_mb ),
	'a 4 GB database still needs only the floor, because the dump is streamed'
);
failsafe_assert( 64 * $one_mb === failsafe_required_memory_bytes( 0 ), 'an empty database needs the same floor' );
failsafe_assert(
	64 * $one_mb === failsafe_required_memory_bytes( $one_mb, 10 ),
	'the old factor argument is ignored'
);
failsafe_assert(
	64 * $one_mb === failsafe_required_memory_bytes( -10 ),
	'a negative size is ignored'
);

echo "\nfailsafe_required_disk_bytes()\n";

failsafe_assert(
	FAILSAFE_DISK_MARGIN_BYTES === failsafe_required_disk_bytes( 0 ),
	'an empty database still needs the safety margin'
);
failsafe_assert(
	failsafe_required_disk_bytes( 10 * $one_mb ) > failsafe_required_disk_bytes( $one_mb ),
	'a bigger database needs more free space'
);
failsafe_assert(
	FAILSAFE_DISK_MARGIN_BYTES === failsafe_required_disk_bytes( -10 ),
	'a negative size is treated as zero'
);

echo "\nfailsafe_estimate_backup_seconds()\n";

failsafe_assert(
	FAILSAFE_BACKUP_BASE_SECONDS === failsafe_estimate_backup_seconds( 0 ),
	'an empty database still costs the fixed setup time'
);
failsafe_assert(
	failsafe_estimate_backup_seconds( 10 * $one_mb ) > failsafe_estimate_backup_seconds( 0 ),
	'a bigger database takes longer'
);
failsafe_assert(
	failsafe_estimate_backup_seconds( -10 ) === failsafe_estimate_backup_seconds( 0 ),
	'a negative size is treated as zero'
);

echo "\nfailsafe_available_disk_bytes()\n";

failsafe_assert(
	0 < failsafe_available_disk_bytes( __DIR__ ),
	'a real directory reports free space'
);
failsafe_assert(
	-1 === failsafe_available_disk_bytes( '/nonexistent-failsafe-path' ),
	'a missing directory reports unknown space'
);

failsafe_report();
