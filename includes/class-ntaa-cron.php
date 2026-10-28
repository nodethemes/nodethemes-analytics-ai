<?php
/**
 * Scheduled jobs: queue processing (every minute), archiving (hourly), GeoIP update and data retention (daily).
 *
 * @package NodeThemes_Analytics_AI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Cron.
 */
class NTAA_Cron {

	/**
	 * Boot.
	 */
	public static function init() {
		add_filter( 'cron_schedules', array( __CLASS__, 'schedules' ) );
		add_action( 'ntaa_daily_maintenance', array( __CLASS__, 'daily' ) );
		add_action( 'ntaa_hourly', array( __CLASS__, 'hourly' ) );
		add_action( 'ntaa_minutely', array( __CLASS__, 'minutely' ) );
		add_action( 'ntaa_segment_backfill', array( __CLASS__, 'segment_backfill' ) );
		add_action( 'ntaa_report_backfill', array( __CLASS__, 'report_backfill' ) );
		add_action( 'init', array( __CLASS__, 'schedule' ) );
		add_action( 'update_option_' . NTAA_Settings::OPTION, array( __CLASS__, 'on_settings_change' ) );
		add_action( 'add_option_' . NTAA_Settings::OPTION, array( __CLASS__, 'on_settings_change' ) );
	}

	/**
	 * Custom intervals.
	 *
	 * @param array $s Schedules.
	 * @return array
	 */
	public static function schedules( $s ) {
		$s['ntaa_minute'] = array(
			'interval' => 60,
			'display'  => 'Every minute (Analytics AI)',
		);
		return $s;
	}

	/**
	 * Settings saved.
	 */
	public static function on_settings_change() {
		NTAA_Settings::flush();
		NTAA_Settings::sync_caps();
		NTAA_Settings::write_runtime();
		self::schedule();
		do_action( 'ntaa_settings_saved' );
	}

	/**
	 * Make sure the jobs exist.
	 */
	public static function schedule() {
		if ( ! wp_next_scheduled( 'ntaa_daily_maintenance' ) ) {
			$first = strtotime( wp_date( 'Y-m-d', time() + DAY_IN_SECONDS ) . ' 03:15:00 ' . wp_timezone_string() );
			wp_schedule_event( $first ? $first : time() + HOUR_IN_SECONDS, 'daily', 'ntaa_daily_maintenance' );
		}
		if ( ! wp_next_scheduled( 'ntaa_hourly' ) ) {
			wp_schedule_event( time() + 300, 'hourly', 'ntaa_hourly' );
		}
		$queue = (int) NTAA_Settings::get( 'queue' );
		$next  = wp_next_scheduled( 'ntaa_minutely' );
		if ( $queue && ! $next ) {
			wp_schedule_event( time() + 60, 'ntaa_minute', 'ntaa_minutely' );
		} elseif ( ! $queue && $next ) {
			wp_clear_scheduled_hook( 'ntaa_minutely' );
			// Store whatever is still waiting.
			wp_schedule_single_event( time() + 5, 'ntaa_process_queue' );
		}
	}

	/**
	 * Remove jobs.
	 */
	public static function unschedule() {
		foreach ( array( 'ntaa_daily_maintenance', 'ntaa_hourly', 'ntaa_minutely', 'ntaa_geoip_update', 'ntaa_process_queue', 'ntaa_segment_backfill', 'ntaa_report_backfill' ) as $h ) {
			wp_clear_scheduled_hook( $h );
		}
	}

	/**
	 * Every minute (High-traffic mode).
	 */
	public static function minutely() {
		NTAA_Tracker::process_queue();
	}

	/**
	 * Right after a segment is set to "pre-process": archive its past days in the background, in chunks.
	 */
	public static function segment_backfill() {
		$done = NTAA_Archive::backfill( 120 );
		if ( $done >= 120 && ! wp_next_scheduled( 'ntaa_segment_backfill' ) ) {
			wp_schedule_single_event( time() + 30, 'ntaa_segment_backfill' );
		}
	}

	/**
	 * After an update added a report: compute it for days archived before, in chunks.
	 */
	public static function report_backfill() {
		if ( ! NTAA_Archive::run_new_reports( 200 ) && ! wp_next_scheduled( 'ntaa_report_backfill' ) ) {
			wp_schedule_single_event( time() + 30, 'ntaa_report_backfill' );
		}
	}

	/**
	 * Every hour: archive finished days that are still missing.
	 */
	public static function hourly() {
		NTAA_Tracker::process_queue( 5000 );
		NTAA_Archive::backfill( 60 );
		if ( NTAA_Archive::new_reports_pending() ) {
			self::report_backfill();
		}
		NTAA_GeoIP::maybe_monthly_update();
	}

	/**
	 * Every day: retention, salt clean-up, runtime refresh.
	 */
	public static function daily() {
		NTAA_Archive::backfill( 400 );
		NTAA_DB::purge_old();
		NTAA_Settings::write_runtime();
		do_action( 'ntaa_daily' );
	}
}
