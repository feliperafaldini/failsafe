<?php
/**
 * Scheduling: the WP-Cron event, the run lock and the run bookkeeping.
 *
 * WP-Cron only fires when the site receives traffic. The status helpers in this
 * file expose "nothing scheduled" and "already overdue" so the settings page can
 * warn the site owner instead of failing silently.
 *
 * @package Failsafe
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'FAILSAFE_CRON_HOOK' ) ) {
	define( 'FAILSAFE_CRON_HOOK', 'failsafe_cron_backup' );
}

if ( ! defined( 'FAILSAFE_CRON_LOCK_OPTION' ) ) {
	define( 'FAILSAFE_CRON_LOCK_OPTION', 'failsafe_cron_lock' );
}

if ( ! defined( 'FAILSAFE_CRON_LOCK_TRANSIENT' ) ) {
	define( 'FAILSAFE_CRON_LOCK_TRANSIENT', 'failsafe_cron_lock' );
}

if ( ! defined( 'FAILSAFE_CRON_LOCK_TIMEOUT' ) ) {
	define( 'FAILSAFE_CRON_LOCK_TIMEOUT', 15 * MINUTE_IN_SECONDS );
}

if ( ! defined( 'FAILSAFE_CRON_FREQUENCY_DEFAULT' ) ) {
	define( 'FAILSAFE_CRON_FREQUENCY_DEFAULT', 'daily' );
}

register_activation_hook( dirname( __DIR__ ) . '/failsafe.php', 'failsafe_cron_activate' );
register_deactivation_hook( dirname( __DIR__ ) . '/failsafe.php', 'failsafe_cron_deactivate' );

add_action( 'cron_schedules', 'failsafe_cron_schedules' );
add_action( FAILSAFE_CRON_HOOK, 'failsafe_cron_run' );

// Saving the schedule options must immediately create, move or remove the event.
add_action( 'add_option_failsafe_schedule_enabled', 'failsafe_cron_sync_schedule' );
add_action( 'update_option_failsafe_schedule_enabled', 'failsafe_cron_sync_schedule' );
add_action( 'add_option_failsafe_schedule_frequency', 'failsafe_cron_sync_schedule' );
add_action( 'update_option_failsafe_schedule_frequency', 'failsafe_cron_sync_schedule' );

/**
 * Re-creates a missing event on every admin request.
 *
 * The install can lose its cron option (database restore, broken migration);
 * this keeps automatic backups alive without waiting for a manual save.
 *
 * @return void
 */
function failsafe_cron_maybe_schedule() {
	$result = failsafe_cron_schedule();

	if ( is_wp_error( $result ) ) {
		failsafe_log( $result->get_error_code() . ': ' . $result->get_error_message() );
	}
}

add_action( 'admin_init', 'failsafe_cron_maybe_schedule' );

/**
 * Registers the weekly recurrence used by the frequency setting.
 *
 * @param array<string,array> $schedules Schedules registered so far.
 * @return array<string,array>
 */
function failsafe_cron_schedules( $schedules ) {
	if ( ! isset( $schedules['failsafe_weekly'] ) ) {
		$schedules['failsafe_weekly'] = array(
			'interval' => WEEK_IN_SECONDS,
			'display'  => __( 'Once weekly', 'failsafe-backup' ),
		);
	}

	return $schedules;
}

/**
 * Frequencies offered to the site owner: slug => label.
 *
 * @return array<string,string>
 */
function failsafe_cron_frequencies() {
	$frequencies = array(
		'hourly'          => __( 'Hourly', 'failsafe-backup' ),
		'twicedaily'      => __( 'Twice daily', 'failsafe-backup' ),
		'daily'           => __( 'Daily', 'failsafe-backup' ),
		'failsafe_weekly' => __( 'Weekly', 'failsafe-backup' ),
	);

	return (array) apply_filters( 'failsafe_cron_frequencies', $frequencies );
}

/**
 * Current frequency, always one of failsafe_cron_frequencies().
 *
 * @return string
 */
function failsafe_cron_frequency() {
	$frequency = (string) get_option( 'failsafe_schedule_frequency', FAILSAFE_CRON_FREQUENCY_DEFAULT );
	$known     = failsafe_cron_frequencies();

	if ( ! isset( $known[ $frequency ] ) ) {
		return FAILSAFE_CRON_FREQUENCY_DEFAULT;
	}

	return $frequency;
}

/**
 * Tells whether automatic backups are enabled.
 *
 * @return bool
 */
function failsafe_cron_enabled() {
	return (bool) apply_filters( 'failsafe_cron_enabled', (bool) get_option( 'failsafe_schedule_enabled', true ) );
}

/**
 * Interval of a recurrence in seconds.
 *
 * @param string $frequency Recurrence slug.
 * @return int
 */
function failsafe_cron_interval( $frequency ) {
	$schedules = wp_get_schedules();

	if ( isset( $schedules[ $frequency ]['interval'] ) ) {
		return (int) $schedules[ $frequency ]['interval'];
	}

	return HOUR_IN_SECONDS;
}

/**
 * Makes sure exactly one event exists, or none when scheduling is disabled.
 *
 * Calling this twice never creates a second event: the existing event is kept
 * when it already uses the configured frequency, and replaced otherwise.
 *
 * @return true|WP_Error
 */
function failsafe_cron_schedule() {
	if ( ! failsafe_cron_enabled() ) {
		failsafe_cron_unschedule();

		return true;
	}

	$frequency = failsafe_cron_frequency();
	$next_run  = wp_next_scheduled( FAILSAFE_CRON_HOOK );

	if ( $next_run ) {
		if ( wp_get_schedule( FAILSAFE_CRON_HOOK ) === $frequency ) {
			return true;
		}

		// The frequency changed: drop the old event before creating the new one.
		failsafe_cron_unschedule();
	}

	$timestamp = time() + failsafe_cron_interval( $frequency );
	$scheduled = wp_schedule_event( $timestamp, $frequency, FAILSAFE_CRON_HOOK );

	if ( is_wp_error( $scheduled ) ) {
		return $scheduled;
	}

	if ( true !== $scheduled ) {
		return new WP_Error(
			'failsafe_cron_schedule_failed',
			__( 'Could not schedule the automatic backup.', 'failsafe-backup' )
		);
	}

	return true;
}

/**
 * Removes every event for the backup hook.
 *
 * @return void
 */
function failsafe_cron_unschedule() {
	wp_clear_scheduled_hook( FAILSAFE_CRON_HOOK );
}

/**
 * Applies the current settings to the cron event.
 *
 * Registered on the option hooks, so saving the settings page is enough.
 *
 * @return void
 */
function failsafe_cron_sync_schedule() {
	$result = failsafe_cron_schedule();

	if ( is_wp_error( $result ) ) {
		failsafe_log( $result->get_error_code() . ': ' . $result->get_error_message() );
	}
}

/**
 * Activation hook: stores the defaults and schedules the first event.
 *
 * @return void
 */
function failsafe_cron_activate() {
	add_option( 'failsafe_schedule_enabled', true );
	add_option( 'failsafe_schedule_frequency', FAILSAFE_CRON_FREQUENCY_DEFAULT );

	failsafe_cron_sync_schedule();
}

/**
 * Deactivation hook: removes the event and any leftover lock.
 *
 * @return void
 */
function failsafe_cron_deactivate() {
	failsafe_cron_unschedule();
	failsafe_cron_release_lock();
}

/**
 * Number of seconds after which a lock is considered abandoned.
 *
 * @return int
 */
function failsafe_cron_lock_timeout() {
	return max( 1, (int) apply_filters( 'failsafe_cron_lock_timeout', FAILSAFE_CRON_LOCK_TIMEOUT ) );
}

/**
 * Takes the run lock so two scheduled backups never run at the same time.
 *
 * The transient gives a cheap expiry; add_option() is the race guard because
 * the database unique key only lets one request create the row.
 *
 * @return true|WP_Error
 */
function failsafe_cron_acquire_lock() {
	if ( false !== get_transient( FAILSAFE_CRON_LOCK_TRANSIENT ) ) {
		return new WP_Error( 'failsafe_cron_locked', __( 'A scheduled backup is already running.', 'failsafe-backup' ) );
	}

	if ( ! add_option( FAILSAFE_CRON_LOCK_OPTION, time(), '', false ) && ! failsafe_cron_take_over_stale_lock() ) {
		return new WP_Error( 'failsafe_cron_locked', __( 'A scheduled backup is already running.', 'failsafe-backup' ) );
	}

	set_transient( FAILSAFE_CRON_LOCK_TRANSIENT, time(), failsafe_cron_lock_timeout() );

	return true;
}

/**
 * Replaces a lock left behind by a run that died without releasing it.
 *
 * The UPDATE only matches the exact stale value, so two requests cannot both
 * steal the same lock: the database picks a single winner.
 *
 * @return bool True when this request now owns the lock.
 */
function failsafe_cron_take_over_stale_lock() {
	global $wpdb;

	$started = (int) get_option( FAILSAFE_CRON_LOCK_OPTION, 0 );

	if ( $started <= 0 || ( time() - $started ) < failsafe_cron_lock_timeout() ) {
		return false;
	}

	$sql = 'UPDATE ' . $wpdb->prepare( '%i', $wpdb->options )
		. ' SET option_value = ' . (int) time()
		. " WHERE option_name = '" . esc_sql( FAILSAFE_CRON_LOCK_OPTION ) . "'"
		. " AND option_value = '" . esc_sql( (string) $started ) . "'";

	/*
	 * Compare-and-swap: the UPDATE only matches while the row still holds the
	 * stale timestamp, so two requests cannot both take over the same lock.
	 * The identifier goes through %i and the values through esc_sql(), per the
	 * project SQL conventions; the option cache is cleared right below.
	 */
	// phpcs:ignore -- The statement is assembled with %i and esc_sql(); see the note above.
	$updated = $wpdb->query( $sql );

	wp_cache_delete( FAILSAFE_CRON_LOCK_OPTION, 'options' );

	if ( 1 !== (int) $updated ) {
		return false;
	}

	failsafe_log( 'cron: took over a stale backup lock' );

	return true;
}

/**
 * Releases the run lock. Safe to call when no lock is held.
 *
 * @return void
 */
function failsafe_cron_release_lock() {
	delete_option( FAILSAFE_CRON_LOCK_OPTION );
	delete_transient( FAILSAFE_CRON_LOCK_TRANSIENT );
}

/**
 * Cron callback. Never lets an error escape into WP-Cron.
 *
 * @return void
 */
function failsafe_cron_run() {
	$result = failsafe_cron_execute();

	if ( is_wp_error( $result ) ) {
		failsafe_log( $result->get_error_code() . ': ' . $result->get_error_message() );
	}
}

/**
 * Runs one scheduled backup under the lock and records the outcome.
 *
 * @return true|WP_Error
 */
function failsafe_cron_execute() {
	$lock = failsafe_cron_acquire_lock();

	if ( is_wp_error( $lock ) ) {
		return $lock;
	}

	try {
		$backup = failsafe_create_backup();

		if ( is_wp_error( $backup ) ) {
			failsafe_cron_store_result( 'error', $backup->get_error_code(), $backup->get_error_message() );

			return $backup;
		}

		$deleted = failsafe_apply_retention();

		failsafe_cron_store_result( 'ok', '', '', count( $deleted ) );

		return true;
	} catch ( Throwable $exception ) {
		failsafe_cron_store_result( 'error', 'failsafe_cron_exception', $exception->getMessage() );

		return new WP_Error( 'failsafe_cron_exception', $exception->getMessage() );
	} finally {
		failsafe_cron_release_lock();
	}
}

/**
 * Stores the timestamp and the outcome of the last run.
 *
 * @param string $status  Either "ok" or "error".
 * @param string $code    Error code, empty on success.
 * @param string $message Error message, empty on success.
 * @param int    $deleted Number of old backups removed by retention.
 * @return void
 */
function failsafe_cron_store_result( $status, $code = '', $message = '', $deleted = 0 ) {
	$now = time();

	update_option( 'failsafe_last_run', $now, false );
	update_option(
		'failsafe_last_result',
		array(
			'status'  => ( 'ok' === $status ) ? 'ok' : 'error',
			'code'    => (string) $code,
			'message' => (string) $message,
			'time'    => $now,
			'deleted' => (int) $deleted,
		),
		false
	);
}

/**
 * Timestamp of the last scheduled run, 0 when it never ran.
 *
 * @return int
 */
function failsafe_cron_last_run() {
	return (int) get_option( 'failsafe_last_run', 0 );
}

/**
 * Outcome of the last scheduled run, normalised.
 *
 * @return array{status:string,code:string,message:string,time:int,deleted:int}
 */
function failsafe_cron_last_result() {
	$stored = get_option( 'failsafe_last_result', array() );

	$result = wp_parse_args(
		is_array( $stored ) ? $stored : array(),
		array(
			'status'  => '',
			'code'    => '',
			'message' => '',
			'time'    => 0,
			'deleted' => 0,
		)
	);

	$result['status']  = in_array( $result['status'], array( 'ok', 'error' ), true ) ? $result['status'] : '';
	$result['code']    = (string) $result['code'];
	$result['message'] = (string) $result['message'];
	$result['time']    = (int) $result['time'];
	$result['deleted'] = (int) $result['deleted'];

	return $result;
}

/**
 * Snapshot of the schedule used by the settings page.
 *
 * "Overdue" means the event was due in the past and WP-Cron has not run it yet,
 * which is the visible symptom of a site without traffic.
 *
 * @return array<string,mixed>
 */
function failsafe_cron_status() {
	$enabled  = failsafe_cron_enabled();
	$next_run = (int) wp_next_scheduled( FAILSAFE_CRON_HOOK );

	return array(
		'enabled'       => $enabled,
		'frequency'     => failsafe_cron_frequency(),
		'next_run'      => $next_run,
		'overdue'       => $next_run > 0 && $next_run < time(),
		'not_scheduled' => $enabled && 0 === $next_run,
		'last_run'      => failsafe_cron_last_run(),
		'last_result'   => failsafe_cron_last_result(),
		'cron_disabled' => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
	);
}
