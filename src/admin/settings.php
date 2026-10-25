<?php
/**
 * Settings page: automatic backups, frequency and retention.
 *
 * @package Failsafe
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'FAILSAFE_SETTINGS_GROUP' ) ) {
	define( 'FAILSAFE_SETTINGS_GROUP', 'failsafe_settings' );
}

if ( ! defined( 'FAILSAFE_SETTINGS_PAGE' ) ) {
	define( 'FAILSAFE_SETTINGS_PAGE', 'failsafe-settings' );
}

if ( ! defined( 'FAILSAFE_RETENTION_MIN' ) ) {
	define( 'FAILSAFE_RETENTION_MIN', 0 );
}

if ( ! defined( 'FAILSAFE_RETENTION_MAX' ) ) {
	define( 'FAILSAFE_RETENTION_MAX', 50 );
}

add_action( 'admin_menu', 'failsafe_register_settings_page' );
add_action( 'admin_init', 'failsafe_register_settings' );

/**
 * Adds the page under Settings.
 *
 * @return void
 */
function failsafe_register_settings_page() {
	add_options_page(
		__( 'Failsafe Backup', 'failsafe-backup' ),
		__( 'Failsafe Backup', 'failsafe-backup' ),
		failsafe_backup_capability(),
		FAILSAFE_SETTINGS_PAGE,
		'failsafe_render_settings_page'
	);
}

/**
 * Registers the options, their sanitizers and the form sections.
 *
 * @return void
 */
function failsafe_register_settings() {
	register_setting(
		FAILSAFE_SETTINGS_GROUP,
		'failsafe_schedule_enabled',
		array(
			'type'              => 'boolean',
			'default'           => true,
			'sanitize_callback' => 'failsafe_sanitize_schedule_enabled',
		)
	);

	register_setting(
		FAILSAFE_SETTINGS_GROUP,
		'failsafe_schedule_frequency',
		array(
			'type'              => 'string',
			'default'           => FAILSAFE_CRON_FREQUENCY_DEFAULT,
			'sanitize_callback' => 'failsafe_sanitize_schedule_frequency',
		)
	);

	register_setting(
		FAILSAFE_SETTINGS_GROUP,
		'failsafe_retention_count',
		array(
			'type'              => 'integer',
			'default'           => FAILSAFE_RETENTION_DEFAULT,
			'sanitize_callback' => 'failsafe_sanitize_retention_count',
		)
	);

	add_settings_section(
		'failsafe_schedule',
		__( 'Automatic backups', 'failsafe-backup' ),
		'failsafe_render_schedule_section',
		FAILSAFE_SETTINGS_PAGE
	);

	add_settings_field(
		'failsafe_schedule_enabled',
		__( 'Schedule', 'failsafe-backup' ),
		'failsafe_render_schedule_enabled_field',
		FAILSAFE_SETTINGS_PAGE,
		'failsafe_schedule',
		array( 'label_for' => 'failsafe_schedule_enabled' )
	);

	add_settings_field(
		'failsafe_schedule_frequency',
		__( 'Frequency', 'failsafe-backup' ),
		'failsafe_render_frequency_field',
		FAILSAFE_SETTINGS_PAGE,
		'failsafe_schedule',
		array( 'label_for' => 'failsafe_schedule_frequency' )
	);

	add_settings_section(
		'failsafe_storage',
		__( 'Storage', 'failsafe-backup' ),
		'failsafe_render_storage_section',
		FAILSAFE_SETTINGS_PAGE
	);

	add_settings_field(
		'failsafe_retention_count',
		__( 'Backups to keep', 'failsafe-backup' ),
		'failsafe_render_retention_field',
		FAILSAFE_SETTINGS_PAGE,
		'failsafe_storage',
		array( 'label_for' => 'failsafe_retention_count' )
	);
}

/**
 * Sanitizes the "enable schedule" checkbox.
 *
 * @param mixed $value Raw submitted value.
 * @return bool
 */
function failsafe_sanitize_schedule_enabled( $value ) {
	$value = is_scalar( $value ) ? (string) $value : '';

	return in_array( $value, array( '1', 'true', 'on', 'yes' ), true );
}

/**
 * Sanitizes the frequency, falling back to the default on an unknown value.
 *
 * @param mixed $value Raw submitted value.
 * @return string
 */
function failsafe_sanitize_schedule_frequency( $value ) {
	$frequency = is_string( $value ) ? sanitize_key( $value ) : '';
	$known     = failsafe_cron_frequencies();

	if ( ! isset( $known[ $frequency ] ) ) {
		if ( function_exists( 'add_settings_error' ) ) {
			add_settings_error(
				'failsafe_schedule_frequency',
				'failsafe_invalid_frequency',
				__( 'Unknown backup frequency. The daily schedule was kept.', 'failsafe-backup' ),
				'error'
			);
		}

		return FAILSAFE_CRON_FREQUENCY_DEFAULT;
	}

	return $frequency;
}

/**
 * Sanitizes the retention count, clamped to 0–50.
 *
 * @param mixed $value Raw submitted value.
 * @return int
 */
function failsafe_sanitize_retention_count( $value ) {
	if ( ! is_numeric( $value ) ) {
		if ( function_exists( 'add_settings_error' ) ) {
			$message = __( 'The number of backups to keep must be a whole number. The previous value was kept.', 'failsafe-backup' );

			add_settings_error(
				'failsafe_retention_count',
				'failsafe_retention_invalid',
				$message,
				'error'
			);
		}

		return failsafe_get_retention_count();
	}

	$count = (int) $value;

	if ( $count < FAILSAFE_RETENTION_MIN || $count > FAILSAFE_RETENTION_MAX ) {
		$clamped = min( FAILSAFE_RETENTION_MAX, max( FAILSAFE_RETENTION_MIN, $count ) );

		if ( function_exists( 'add_settings_error' ) ) {
			add_settings_error(
				'failsafe_retention_count',
				'failsafe_retention_range',
				sprintf(
					/* translators: %d: number of backups that will be kept. */
					__( 'The number of backups to keep must be between 0 and 50. Using %d.', 'failsafe-backup' ),
					$clamped
				),
				'warning'
			);
		}

		return $clamped;
	}

	return $count;
}

/**
 * Renders the settings page.
 *
 * @return void
 */
function failsafe_render_settings_page() {
	if ( ! current_user_can( failsafe_backup_capability() ) ) {
		wp_die( esc_html__( 'You are not allowed to manage backup settings.', 'failsafe-backup' ) );
	}
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Failsafe Backup', 'failsafe-backup' ); ?></h1>
		<?php settings_errors(); ?>
		<?php failsafe_render_cron_status(); ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'options.php' ) ); ?>">
			<?php settings_fields( FAILSAFE_SETTINGS_GROUP ); ?>
			<?php do_settings_sections( FAILSAFE_SETTINGS_PAGE ); ?>
			<?php submit_button(); ?>
		</form>
	</div>
	<?php
}

/**
 * Explains how WP-Cron behaves.
 *
 * @return void
 */
function failsafe_render_schedule_section() {
	$cron_note = __(
		'Scheduled backups rely on WP-Cron, which only runs when the site receives a visit.',
		'failsafe-backup'
	);
	$late_note = __(
		'A quiet site can run a backup late; the status below shows when the next run is due.',
		'failsafe-backup'
	);

	printf( '<p>%s %s</p>', esc_html( $cron_note ), esc_html( $late_note ) );
}

/**
 * Renders the schedule checkbox.
 *
 * @return void
 */
function failsafe_render_schedule_enabled_field() {
	?>
	<input type="hidden" name="failsafe_schedule_enabled" value="0" />
	<input
		type="checkbox"
		id="failsafe_schedule_enabled"
		name="failsafe_schedule_enabled"
		value="1"
		<?php checked( failsafe_cron_enabled() ); ?>
	/>
	<p class="description">
		<?php esc_html_e( 'Run a backup automatically. Turning this off removes the scheduled event.', 'failsafe-backup' ); ?>
	</p>
	<?php
}

/**
 * Renders the frequency select.
 *
 * @return void
 */
function failsafe_render_frequency_field() {
	$current     = failsafe_cron_frequency();
	$frequencies = failsafe_cron_frequencies();
	$description = __(
		'How often automatic backups run. Changing it never adds a second event.',
		'failsafe-backup'
	);
	?>
	<select id="failsafe_schedule_frequency" name="failsafe_schedule_frequency">
		<?php foreach ( $frequencies as $slug => $label ) : ?>
			<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $current, $slug ); ?>>
				<?php echo esc_html( $label ); ?>
			</option>
		<?php endforeach; ?>
	</select>
	<p class="description"><?php echo esc_html( $description ); ?></p>
	<?php
}

/**
 * Describes what retention does.
 *
 * @return void
 */
function failsafe_render_storage_section() {
	$description = __(
		'Backups are stored in the site content directory and are not reachable from the web.',
		'failsafe-backup'
	);
	$retention   = __( 'The retention limit below is applied after every automatic backup.', 'failsafe-backup' );

	printf( '<p>%s %s</p>', esc_html( $description ), esc_html( $retention ) );
}

/**
 * Renders the retention input.
 *
 * @return void
 */
function failsafe_render_retention_field() {
	?>
	<input
		type="number"
		id="failsafe_retention_count"
		name="failsafe_retention_count"
		value="<?php echo esc_attr( (string) failsafe_get_retention_count() ); ?>"
		min="<?php echo esc_attr( (string) FAILSAFE_RETENTION_MIN ); ?>"
		max="<?php echo esc_attr( (string) FAILSAFE_RETENTION_MAX ); ?>"
		step="1"
		class="small-text"
	/>
	<p class="description">
		<?php esc_html_e( 'How many backup sets to keep. Use 0 to keep every backup.', 'failsafe-backup' ); ?>
	</p>
	<?php
}

/**
 * Shows the last run, the next run and any delay or misconfiguration.
 *
 * @return void
 */
function failsafe_render_cron_status() {
	$status = failsafe_cron_status();

	if ( $status['overdue'] ) {
		$message = sprintf(
			/* translators: %s: date and time the backup was due. */
			__( 'The scheduled backup is overdue. It was due on %s.', 'failsafe-backup' ),
			failsafe_format_timestamp( $status['next_run'] )
		);
		$message .= ' ' . __( 'WP-Cron only runs with traffic, so a quiet site can be late.', 'failsafe-backup' );

		printf( '<div class="notice notice-error inline"><p>%s</p></div>', esc_html( $message ) );
	} elseif ( $status['not_scheduled'] ) {
		$message  = __( 'Automatic backups are enabled, but no event is scheduled.', 'failsafe-backup' );
		$message .= ' ' . __( 'Save the settings to schedule it again.', 'failsafe-backup' );

		printf( '<div class="notice notice-warning inline"><p>%s</p></div>', esc_html( $message ) );
	}

	if ( $status['cron_disabled'] ) {
		$message  = __( 'DISABLE_WP_CRON is set, so WordPress does not start scheduled tasks.', 'failsafe-backup' );
		$message .= ' ' . __( 'Run wp-cron.php from a system cron job to keep backups on time.', 'failsafe-backup' );

		printf( '<div class="notice notice-warning inline"><p>%s</p></div>', esc_html( $message ) );
	}

	$next_run = __( 'Not scheduled', 'failsafe-backup' );

	if ( $status['next_run'] > 0 ) {
		$next_run = failsafe_describe_next_run( $status['next_run'] );
	}

	$rows = array(
		array(
			__( 'Automatic backups', 'failsafe-backup' ),
			$status['enabled'] ? __( 'Enabled', 'failsafe-backup' ) : __( 'Disabled', 'failsafe-backup' ),
		),
		array(
			__( 'Last run', 'failsafe-backup' ),
			failsafe_describe_last_run( $status['last_run'] ),
		),
		array(
			__( 'Last result', 'failsafe-backup' ),
			failsafe_describe_last_result( $status['last_result'] ),
		),
		array(
			__( 'Next run', 'failsafe-backup' ),
			$next_run,
		),
	);

	echo '<table class="widefat striped"><tbody>';

	foreach ( $rows as $row ) {
		printf(
			'<tr><th scope="row">%s</th><td>%s</td></tr>',
			esc_html( $row[0] ),
			esc_html( $row[1] )
		);
	}

	echo '</tbody></table>';
}

/**
 * Formats a timestamp with the site date and time formats.
 *
 * @param int $timestamp Unix timestamp.
 * @return string
 */
function failsafe_format_timestamp( $timestamp ) {
	$format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );

	return (string) wp_date( $format, (int) $timestamp );
}

/**
 * Describes the last run as a date plus a relative age.
 *
 * @param int $timestamp Unix timestamp, 0 when it never ran.
 * @return string
 */
function failsafe_describe_last_run( $timestamp ) {
	if ( $timestamp <= 0 ) {
		return __( 'Never', 'failsafe-backup' );
	}

	return sprintf(
		/* translators: 1: date and time, 2: human readable age such as "5 mins". */
		__( '%1$s (%2$s ago)', 'failsafe-backup' ),
		failsafe_format_timestamp( $timestamp ),
		human_time_diff( $timestamp )
	);
}

/**
 * Describes the next run as a date plus a relative wait.
 *
 * @param int $timestamp Unix timestamp.
 * @return string
 */
function failsafe_describe_next_run( $timestamp ) {
	if ( $timestamp < time() ) {
		return sprintf(
			/* translators: %s: date and time. */
			__( '%s (due now)', 'failsafe-backup' ),
			failsafe_format_timestamp( $timestamp )
		);
	}

	return sprintf(
		/* translators: 1: date and time, 2: human readable wait such as "5 mins". */
		__( '%1$s (in %2$s)', 'failsafe-backup' ),
		failsafe_format_timestamp( $timestamp ),
		human_time_diff( $timestamp )
	);
}

/**
 * Describes the stored outcome of the last scheduled run.
 *
 * @param array $result Normalised failsafe_cron_last_result() value.
 * @return string
 */
function failsafe_describe_last_result( $result ) {
	if ( 'ok' === $result['status'] ) {
		$text = __( 'Completed successfully.', 'failsafe-backup' );

		if ( $result['deleted'] > 0 ) {
			$text .= ' ' . sprintf(
				/* translators: %d: number of old backups removed by retention. */
				_n(
					'%d older backup was removed by retention.',
					'%d older backups were removed by retention.',
					$result['deleted'],
					'failsafe-backup'
				),
				$result['deleted']
			);
		}

		return $text;
	}

	if ( 'error' === $result['status'] ) {
		$text = __( 'Failed.', 'failsafe-backup' );

		if ( '' !== $result['code'] ) {
			$text .= ' ' . sprintf(
				/* translators: %s: error code. */
				__( 'Error code: %s.', 'failsafe-backup' ),
				$result['code']
			);
		}

		if ( '' !== $result['message'] ) {
			$text .= ' ' . $result['message'];
		}

		return $text;
	}

	return __( 'No automatic backup has run yet.', 'failsafe-backup' );
}
