<?php
/**
 * Unit checks for scheduling: frequencies, intervals, the run lock and the
 * stored outcome of the last run.
 *
 * Run inside the WordPress container:
 *   php /tmp/failsafe-tests/test-cron.php
 *
 * The script snapshots every option it touches and restores it on shutdown, so
 * a failed assertion never leaves a lock behind or a changed result.
 *
 * @package Failsafe
 */

require '/var/www/html/wp-load.php';
require __DIR__ . '/assert.php';

$GLOBALS['failsafe_cron_test_state'] = array(
	'snapshotted' => false,
	'restored'    => false,
	'lock'        => array(
		'exists' => false,
		'value'  => '',
	),
	'transient'   => array(
		'exists'  => false,
		'value'   => '',
		'timeout' => false,
	),
	'last_run'    => array(
		'exists' => false,
		'value'  => '',
	),
	'last_result' => array(
		'exists' => false,
		'value'  => '',
	),
);

/**
 * Records the current value of every option the test changes.
 *
 * @return void
 */
function failsafe_test_cron_snapshot() {
	$state = $GLOBALS['failsafe_cron_test_state'];

	$lock_option = get_option( FAILSAFE_CRON_LOCK_OPTION, '__failsafe_missing__' );

	$state['lock'] = array(
		'exists' => '__failsafe_missing__' !== $lock_option,
		'value'  => $lock_option,
	);

	$transient = get_transient( FAILSAFE_CRON_LOCK_TRANSIENT );

	$state['transient'] = array(
		'exists'  => false !== $transient,
		'value'   => $transient,
		'timeout' => get_option( '_transient_timeout_' . FAILSAFE_CRON_LOCK_TRANSIENT, false ),
	);

	$last_run = get_option( 'failsafe_last_run', '__failsafe_missing__' );

	$state['last_run'] = array(
		'exists' => '__failsafe_missing__' !== $last_run,
		'value'  => $last_run,
	);

	$last_result = get_option( 'failsafe_last_result', '__failsafe_missing__' );

	$state['last_result'] = array(
		'exists' => '__failsafe_missing__' !== $last_result,
		'value'  => $last_result,
	);

	$state['snapshotted']            = true;
	$GLOBALS['failsafe_cron_test_state'] = $state;
}

/**
 * Puts every touched option back to the value it had before the test.
 *
 * Also runs from a shutdown handler, so a failed assertion still releases the
 * lock and restores the stored result.
 *
 * @return void
 */
function failsafe_test_cron_restore() {
	$state = $GLOBALS['failsafe_cron_test_state'];

	if ( empty( $state['snapshotted'] ) || ! empty( $state['restored'] ) ) {
		return;
	}

	// Drop whatever the test created before restoring the original state.
	delete_option( FAILSAFE_CRON_LOCK_OPTION );
	delete_transient( FAILSAFE_CRON_LOCK_TRANSIENT );

	if ( $state['lock']['exists'] ) {
		update_option( FAILSAFE_CRON_LOCK_OPTION, $state['lock']['value'], false );
	}

	if ( $state['transient']['exists'] ) {
		$ttl = 0;

		if ( false !== $state['transient']['timeout'] ) {
			$ttl = max( 1, (int) $state['transient']['timeout'] - time() );
		}

		set_transient( FAILSAFE_CRON_LOCK_TRANSIENT, $state['transient']['value'], $ttl );
	}

	foreach ( array( 'last_run', 'last_result' ) as $option ) {
		$name = 'failsafe_' . $option;

		if ( $state[ $option ]['exists'] ) {
			update_option( $name, $state[ $option ]['value'], false );
		} else {
			delete_option( $name );
		}
	}

	$GLOBALS['failsafe_cron_test_state']['restored'] = true;
}

register_shutdown_function( 'failsafe_test_cron_restore' );

failsafe_test_cron_snapshot();

// Start from a clean lock: a stale lock from an earlier manual run must not
// make the first assertion fail.
failsafe_cron_release_lock();

echo "failsafe_cron_frequencies()\n";

$failsafe_frequencies = failsafe_cron_frequencies();

failsafe_assert( is_array( $failsafe_frequencies ), 'returns an array' );
failsafe_assert( count( $failsafe_frequencies ) >= 4, 'offers at least four frequencies' );

foreach ( array( 'hourly', 'twicedaily', 'daily', 'failsafe_weekly' ) as $failsafe_slug ) {
	failsafe_assert( isset( $failsafe_frequencies[ $failsafe_slug ] ), 'contains the ' . $failsafe_slug . ' frequency' );
}

echo "\nfailsafe_cron_interval()\n";

foreach ( array_keys( $failsafe_frequencies ) as $failsafe_slug ) {
	$failsafe_interval = failsafe_cron_interval( $failsafe_slug );

	failsafe_assert(
		is_int( $failsafe_interval ) && $failsafe_interval > 0,
		'returns a positive interval for ' . $failsafe_slug
	);
}

failsafe_assert(
	failsafe_cron_interval( 'nonsense' ) === HOUR_IN_SECONDS,
	'an unknown frequency falls back to one hour'
);
failsafe_assert(
	failsafe_cron_interval( '' ) === HOUR_IN_SECONDS,
	'an empty frequency falls back to one hour'
);
failsafe_assert(
	failsafe_cron_interval( 'failsafe_weekly' ) === WEEK_IN_SECONDS,
	'the weekly frequency uses a week'
);

echo "\nfailsafe_cron_acquire_lock()\n";

$failsafe_first_lock = failsafe_cron_acquire_lock();

failsafe_assert( true === $failsafe_first_lock, 'the first call acquires the lock' );
failsafe_assert(
	false !== get_option( FAILSAFE_CRON_LOCK_OPTION, false ),
	'stores the lock as an option'
);
failsafe_assert(
	false !== get_transient( FAILSAFE_CRON_LOCK_TRANSIENT ),
	'stores the lock as a transient'
);

$failsafe_second_lock = failsafe_cron_acquire_lock();

failsafe_assert( is_wp_error( $failsafe_second_lock ), 'the second call is refused' );
failsafe_assert(
	is_wp_error( $failsafe_second_lock ) && 'failsafe_cron_locked' === $failsafe_second_lock->get_error_code(),
	'the refusal uses the failsafe_cron_locked code'
);

failsafe_cron_release_lock();

failsafe_assert(
	false === get_option( FAILSAFE_CRON_LOCK_OPTION, false ),
	'release removes the lock option'
);
failsafe_assert(
	false === get_transient( FAILSAFE_CRON_LOCK_TRANSIENT ),
	'release removes the lock transient'
);

$failsafe_third_lock = failsafe_cron_acquire_lock();

failsafe_assert( true === $failsafe_third_lock, 'the lock can be acquired again after release' );

failsafe_cron_release_lock();

echo "\nfailsafe_cron_take_over_stale_lock()\n";

$failsafe_stale = time() - failsafe_cron_lock_timeout() - 60;

update_option( FAILSAFE_CRON_LOCK_OPTION, $failsafe_stale, false );

failsafe_assert( false === get_transient( FAILSAFE_CRON_LOCK_TRANSIENT ), 'the stale setup has no transient' );
failsafe_assert( true === failsafe_cron_take_over_stale_lock(), 'takes over an abandoned option' );

$failsafe_refreshed = (int) get_option( FAILSAFE_CRON_LOCK_OPTION, 0 );

failsafe_assert( $failsafe_refreshed > $failsafe_stale, 'replaces the old timestamp with the current time' );
failsafe_assert( false === failsafe_cron_take_over_stale_lock(), 'does not take over a fresh lock' );

$failsafe_blocked = failsafe_cron_acquire_lock();

failsafe_assert( is_wp_error( $failsafe_blocked ), 'the refreshed lock still blocks a second run' );

failsafe_cron_release_lock();

// A stale option also gets taken over through the acquire path.
update_option( FAILSAFE_CRON_LOCK_OPTION, $failsafe_stale, false );

failsafe_assert( true === failsafe_cron_acquire_lock(), 'acquire takes over a stale lock' );
failsafe_assert(
	(int) get_option( FAILSAFE_CRON_LOCK_OPTION, 0 ) > $failsafe_stale,
	'acquire refreshes the stale timestamp'
);

failsafe_cron_release_lock();

echo "\nlock ownership\n";

failsafe_cron_release_lock( true );

failsafe_assert( true === failsafe_cron_acquire_lock(), 'the lock can be acquired for the ownership checks' );
failsafe_assert(
	1 === preg_match( '/^\d+:[0-9a-f-]{36}$/', (string) get_option( FAILSAFE_CRON_LOCK_OPTION, '' ) ),
	'the lock stores a timestamp and a token'
);

// Another run took the lock over while this one was stalled.
update_option( FAILSAFE_CRON_LOCK_OPTION, time() . ':foreign-token', false );

failsafe_cron_release_lock();

failsafe_assert(
	false !== get_option( FAILSAFE_CRON_LOCK_OPTION, false ),
	'release leaves a lock owned by another run in place'
);

failsafe_cron_release_lock( true );

failsafe_assert(
	false === get_option( FAILSAFE_CRON_LOCK_OPTION, false ),
	'force release removes the foreign lock'
);

echo "\nfailsafe_cron_store_result()\n";

failsafe_cron_store_result( 'ok', '', '', 2 );

$failsafe_last_run    = get_option( 'failsafe_last_run', false );
$failsafe_last_result = get_option( 'failsafe_last_result', array() );

failsafe_assert( is_numeric( $failsafe_last_run ), 'stores the run timestamp' );
failsafe_assert(
	is_numeric( $failsafe_last_run ) && abs( time() - (int) $failsafe_last_run ) <= 5,
	'the run timestamp is the current time'
);
failsafe_assert( is_array( $failsafe_last_result ), 'stores the result as an array' );
failsafe_assert(
	is_array( $failsafe_last_result ) && 'ok' === $failsafe_last_result['status'],
	'stores the ok status'
);
failsafe_assert(
	is_array( $failsafe_last_result ) && 2 === $failsafe_last_result['deleted'],
	'stores the number of deleted backups'
);
failsafe_assert(
	is_array( $failsafe_last_result ) && (int) $failsafe_last_result['time'] === (int) $failsafe_last_run,
	'uses the same timestamp for the run and the result'
);

failsafe_cron_store_result( 'error', 'failsafe_test_error', 'Something failed.', 0 );

$failsafe_last_result = get_option( 'failsafe_last_result', array() );

failsafe_assert(
	is_array( $failsafe_last_result ) && 'error' === $failsafe_last_result['status'],
	'stores the error status'
);
failsafe_assert(
	is_array( $failsafe_last_result ) && 'failsafe_test_error' === $failsafe_last_result['code'],
	'stores the error code'
);
failsafe_assert(
	is_array( $failsafe_last_result ) && 'Something failed.' === $failsafe_last_result['message'],
	'stores the error message'
);

$failsafe_normalised = failsafe_cron_last_result();

failsafe_assert( 'error' === $failsafe_normalised['status'], 'failsafe_cron_last_result reads the error status' );
failsafe_assert( 0 === $failsafe_normalised['deleted'], 'failsafe_cron_last_result reads the deleted count' );

failsafe_cron_store_result( 'unexpected', 'failsafe_test_error', 'Something failed.', 0 );

$failsafe_last_result = get_option( 'failsafe_last_result', array() );

failsafe_assert(
	is_array( $failsafe_last_result ) && 'error' === $failsafe_last_result['status'],
	'any status other than ok is stored as error'
);

failsafe_test_cron_restore();

failsafe_assert(
	$GLOBALS['failsafe_cron_test_state']['lock']['value'] === get_option(
		FAILSAFE_CRON_LOCK_OPTION,
		'__failsafe_missing__'
	),
	'restores the original lock option'
);
failsafe_assert(
	$GLOBALS['failsafe_cron_test_state']['transient']['value'] === get_transient( FAILSAFE_CRON_LOCK_TRANSIENT ),
	'restores the original lock transient'
);

unset(
	$failsafe_frequencies,
	$failsafe_slug,
	$failsafe_interval,
	$failsafe_first_lock,
	$failsafe_second_lock,
	$failsafe_third_lock,
	$failsafe_stale,
	$failsafe_refreshed,
	$failsafe_blocked,
	$failsafe_last_run,
	$failsafe_last_result,
	$failsafe_normalised
);

failsafe_report();
