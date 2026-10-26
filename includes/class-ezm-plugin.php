<?php
/**
 * Plugin bootstrap and cron orchestration.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EZM_Plugin {

	/** @var self|null */
	private static $instance = null;

	/**
	 * Singleton.
	 *
	 * @return self
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		EZM_DB::maybe_upgrade();
		EZM_Settings::ensure_option();

		EZM_Security::init();
		EZM_Admin::init();
		EZM_Disk::init();
		EZM_Logs::init();
		EZM_Media::init();
		EZM_Uploads_Safety::init();
		EZM_Audit::init();
		EZM_Password_Age::init();
		EZM_Mail::init();

		add_action( 'ezm_daily_tasks', array( $this, 'run_daily_tasks' ) );
		add_action( 'ezm_digest_send', array( 'EZM_Mail', 'send_scheduled_digest' ) );
		add_action( 'init', array( $this, 'ensure_cron' ) );
	}

	public static function activate() {
		EZM_DB::install();
		update_option( 'ezm_db_version', EZM_DB::DB_VERSION, false );
		EZM_Settings::ensure_option();
		self::schedule_events();
		if ( EZM_Settings::get( 'module_password_age', 1 ) ) {
			EZM_Password_Age::seed_missing_timestamps();
		}
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( 'ezm_daily_tasks' );
		wp_clear_scheduled_hook( 'ezm_digest_send' );
		wp_clear_scheduled_hook( 'ezm_disk_scan_continue' );
		wp_clear_scheduled_hook( 'ezm_media_purge_due' );
	}

	public function ensure_cron() {
		if ( ! wp_next_scheduled( 'ezm_daily_tasks' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'ezm_daily_tasks' );
		}
		self::reschedule_digest();
	}

	public static function schedule_events() {
		if ( ! wp_next_scheduled( 'ezm_daily_tasks' ) ) {
			wp_schedule_event( time() + 60, 'daily', 'ezm_daily_tasks' );
		}
		self::reschedule_digest();
	}

	/**
	 * Reschedule digest based on settings.
	 */
	public static function reschedule_digest() {
		wp_clear_scheduled_hook( 'ezm_digest_send' );

		$settings = EZM_Settings::all();
		if ( empty( $settings['alerts_enabled'] ) ) {
			return;
		}

		$ts = self::next_digest_timestamp( $settings );
		if ( $ts ) {
			wp_schedule_single_event( $ts, 'ezm_digest_send' );
		}
	}

	/**
	 * Compute next digest run as UTC timestamp.
	 *
	 * @param array $settings Settings.
	 * @return int
	 */
	public static function next_digest_timestamp( $settings ) {
		$time = isset( $settings['schedule_time'] ) ? $settings['schedule_time'] : '09:00';
		if ( ! preg_match( '/^(\d{2}):(\d{2})$/', $time, $m ) ) {
			$time = '09:00';
			$m    = array( 1 => '09', 2 => '00' );
		}

		$tz = wp_timezone();
		$now = new DateTimeImmutable( 'now', $tz );
		$candidate = $now->setTime( (int) $m[1], (int) $m[2], 0 );

		$freq = $settings['schedule_freq'] ?? 'weekly';

		if ( 'daily' === $freq ) {
			if ( $candidate <= $now ) {
				$candidate = $candidate->modify( '+1 day' );
			}
		} elseif ( 'monthly' === $freq ) {
			$dom = min( 28, max( 1, absint( $settings['schedule_dom'] ?? 1 ) ) );
			$candidate = $now->setDate( (int) $now->format( 'Y' ), (int) $now->format( 'n' ), $dom )
				->setTime( (int) $m[1], (int) $m[2], 0 );
			if ( $candidate <= $now ) {
				$candidate = $candidate->modify( 'first day of next month' )->setDate(
					(int) $candidate->format( 'Y' ),
					(int) $candidate->format( 'n' ),
					$dom
				)->setTime( (int) $m[1], (int) $m[2], 0 );
			}
		} else {
			// Weekly.
			$dow_map = array( 0 => 'Sunday', 1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday' );
			$dow     = absint( $settings['schedule_dow'] ?? 1 );
			$day     = $dow_map[ $dow ] ?? 'Monday';
			$candidate = new DateTimeImmutable( 'next ' . $day, $tz );
			$candidate = $candidate->setTime( (int) $m[1], (int) $m[2], 0 );
			// If today is that day and time still ahead, use today.
			if ( (int) $now->format( 'w' ) === $dow ) {
				$today = $now->setTime( (int) $m[1], (int) $m[2], 0 );
				if ( $today > $now ) {
					$candidate = $today;
				}
			}
		}

		return $candidate->getTimestamp();
	}

	public function run_daily_tasks() {
		EZM_Disk::daily_size_sample();
		EZM_Logs::refresh_index();
		EZM_Logs::aggregate_errors_sample();
		EZM_Media::maybe_run_monthly_pass();
		EZM_Media::purge_due_items();
		EZM_Audit::run_daily_checks();
		EZM_Password_Age::run_daily_reminders();
		EZM_DB::purge_old_events();

		// Keep digest schedule healthy.
		if ( ! wp_next_scheduled( 'ezm_digest_send' ) ) {
			self::reschedule_digest();
		}
	}
}
