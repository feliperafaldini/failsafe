<?php
/**
 * Unit checks for the settings sanitizers in admin/settings.php.
 *
 * Run inside the WordPress container:
 *   php /tmp/failsafe-tests/test-settings.php
 *
 * The retention option is changed once to prove the fallback behaviour and is
 * restored on shutdown, even when an assertion fails.
 *
 * @package Failsafe
 */

require '/var/www/html/wp-load.php';
require __DIR__ . '/assert.php';

$GLOBALS['failsafe_settings_test_retention'] = array(
	'snapshotted' => false,
	'restored'    => false,
	'exists'      => false,
	'value'       => '',
);

/**
 * Records the current retention option.
 *
 * @return void
 */
function failsafe_test_settings_snapshot() {
	$original = get_option( 'failsafe_retention_count', '__failsafe_missing__' );

	$GLOBALS['failsafe_settings_test_retention'] = array(
		'snapshotted' => true,
		'restored'    => false,
		'exists'      => '__failsafe_missing__' !== $original,
		'value'       => $original,
	);
}

/**
 * Puts the retention option back to the value it had before the test.
 *
 * @return void
 */
function failsafe_test_settings_restore() {
	$state = $GLOBALS['failsafe_settings_test_retention'];

	if ( empty( $state['snapshotted'] ) || ! empty( $state['restored'] ) ) {
		return;
	}

	if ( $state['exists'] ) {
		update_option( 'failsafe_retention_count', $state['value'] );
	} else {
		delete_option( 'failsafe_retention_count' );
	}

	$GLOBALS['failsafe_settings_test_retention']['restored'] = true;
}

register_shutdown_function( 'failsafe_test_settings_restore' );

failsafe_test_settings_snapshot();

echo "failsafe_sanitize_retention_count()\n";

failsafe_assert( 50 === failsafe_sanitize_retention_count( 999 ), 'clamps 999 to the maximum of 50' );
failsafe_assert( 0 === failsafe_sanitize_retention_count( -3 ), 'clamps a negative count to 0' );
failsafe_assert( 50 === failsafe_sanitize_retention_count( 51 ), 'clamps 51 to the maximum of 50' );
failsafe_assert( 0 === failsafe_sanitize_retention_count( 0 ), 'accepts 0' );
failsafe_assert( 5 === failsafe_sanitize_retention_count( 5 ), 'accepts a value inside the range' );
failsafe_assert( 12 === failsafe_sanitize_retention_count( '12' ), 'accepts a numeric string' );
failsafe_assert( 3 === failsafe_sanitize_retention_count( 3.9 ), 'truncates a float' );
failsafe_assert( 50 === failsafe_sanitize_retention_count( '999' ), 'clamps a numeric string above the range' );

// With nothing stored, a non-numeric value falls back to the default.
delete_option( 'failsafe_retention_count' );

failsafe_assert(
	FAILSAFE_RETENTION_DEFAULT === failsafe_sanitize_retention_count( 'abc' ),
	'a non-numeric value falls back to the default when nothing is stored'
);
failsafe_assert(
	FAILSAFE_RETENTION_DEFAULT === failsafe_sanitize_retention_count( '' ),
	'an empty value falls back to the default when nothing is stored'
);

// With a stored value, the sanitizer keeps it instead of resetting to default.
update_option( 'failsafe_retention_count', 7 );

failsafe_assert( 7 === failsafe_sanitize_retention_count( 'abc' ), 'a non-numeric value keeps the stored value' );
failsafe_assert( 7 === failsafe_sanitize_retention_count( null ), 'null keeps the stored value' );
failsafe_assert( 7 === failsafe_sanitize_retention_count( array( 3 ) ), 'an array keeps the stored value' );

echo "\nfailsafe_sanitize_schedule_frequency()\n";

failsafe_assert(
	'failsafe_weekly' === failsafe_sanitize_schedule_frequency( 'failsafe_weekly' ),
	'accepts the weekly slug used by the plugin'
);
failsafe_assert( 'daily' === failsafe_sanitize_schedule_frequency( 'daily' ), 'accepts daily' );
failsafe_assert( 'hourly' === failsafe_sanitize_schedule_frequency( 'hourly' ), 'accepts hourly' );
failsafe_assert( 'twicedaily' === failsafe_sanitize_schedule_frequency( 'twicedaily' ), 'accepts twicedaily' );
failsafe_assert(
	'daily' === failsafe_sanitize_schedule_frequency( 'nonsense' ),
	'an unknown frequency falls back to daily'
);
failsafe_assert(
	'daily' === failsafe_sanitize_schedule_frequency( 'weekly' ),
	'the bare weekly slug is not a plugin frequency and falls back to daily'
);
failsafe_assert( 'daily' === failsafe_sanitize_schedule_frequency( '' ), 'an empty frequency falls back to daily' );
failsafe_assert( 'daily' === failsafe_sanitize_schedule_frequency( null ), 'null falls back to daily' );
failsafe_assert( 'daily' === failsafe_sanitize_schedule_frequency( array( 'daily' ) ), 'an array falls back to daily' );
failsafe_assert(
	'daily' === failsafe_sanitize_schedule_frequency( 'DAILY' ),
	'the value is sanitized into a key before the lookup'
);

foreach ( array( 'hourly', 'twicedaily', 'daily', 'failsafe_weekly' ) as $failsafe_slug ) {
	$failsafe_sanitized = failsafe_sanitize_schedule_frequency( $failsafe_slug );

	failsafe_assert(
		isset( failsafe_cron_frequencies()[ $failsafe_sanitized ] ),
		'the sanitized ' . $failsafe_slug . ' is a known frequency'
	);
}

echo "\nfailsafe_sanitize_schedule_enabled()\n";

failsafe_assert( true === failsafe_sanitize_schedule_enabled( 1 ), 'accepts integer 1' );
failsafe_assert( false === failsafe_sanitize_schedule_enabled( 0 ), 'accepts integer 0' );
failsafe_assert( true === failsafe_sanitize_schedule_enabled( '1' ), 'accepts the string "1"' );
failsafe_assert( false === failsafe_sanitize_schedule_enabled( '0' ), 'accepts the string "0"' );
failsafe_assert( true === failsafe_sanitize_schedule_enabled( 'on' ), 'accepts "on" from the checkbox' );
failsafe_assert( true === failsafe_sanitize_schedule_enabled( true ), 'accepts a boolean true' );
failsafe_assert( false === failsafe_sanitize_schedule_enabled( '' ), 'rejects an empty value' );
failsafe_assert( false === failsafe_sanitize_schedule_enabled( 'nonsense' ), 'rejects an unknown value' );
failsafe_assert( false === failsafe_sanitize_schedule_enabled( array( 1 ) ), 'rejects an array' );
failsafe_assert(
	is_bool( failsafe_sanitize_schedule_enabled( '1' ) ) && is_bool( failsafe_sanitize_schedule_enabled( '0' ) ),
	'returns a boolean, which WordPress stores as 1 or 0'
);

failsafe_test_settings_restore();

failsafe_assert(
	$GLOBALS['failsafe_settings_test_retention']['value'] === get_option(
		'failsafe_retention_count',
		'__failsafe_missing__'
	),
	'restores the original retention option'
);

unset( $failsafe_slug, $failsafe_sanitized );

failsafe_report();
