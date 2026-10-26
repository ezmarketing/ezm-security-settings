<?php
/**
 * Plugin settings (autoload false).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EZM_Settings {

	const OPTION_KEY = 'ezm_settings';

	/**
	 * Default settings.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'alerts_enabled'           => 1,
			'alert_recipients'         => '',
			'digest_mode'              => 'significant', // significant | full
			'schedule_freq'            => 'weekly', // daily | weekly | monthly
			'schedule_dow'             => 1, // 0=Sun .. 6=Sat
			'schedule_dom'             => 1, // 1-28
			'schedule_time'            => '',
			'log_exclude_folders'      => "uploads/wc-logs\nuploads/wc-logs/",
			'critical_log_mb'          => 20,
			'unused_media_count'       => 50,
			'unused_media_mb'          => 200,
			'disk_skip_dirs'           => ".git\nnode_modules\n.svn\n.hg",
			'disk_delta_pct'           => 10,
			'event_retention_days'     => 180, // 90 | 180 | 365 | 730
			'expect_defender'          => 1,
			'module_disk'              => 1,
			'module_logs'              => 1,
			'module_media'             => 1,
			'module_audit'             => 1,
			'orphaned_media_enabled'   => 1,
			'auto_purge_after_90'      => 1,
			'plugin_event_alerts'      => 1,
			'urgent_security_alerts'   => 1,
			'module_password_age'      => 1,
			'unsubscribed'             => array(),
		);
	}

	/**
	 * Get all settings merged with defaults.
	 *
	 * @return array
	 */
	public static function all() {
		$stored = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		return array_merge( self::defaults(), $stored );
	}

	/**
	 * Get one setting.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Fallback.
	 * @return mixed
	 */
	public static function get( $key, $default = null ) {
		$all = self::all();
		if ( array_key_exists( $key, $all ) ) {
			return $all[ $key ];
		}
		return $default;
	}

	/**
	 * Update settings (partial).
	 *
	 * @param array $partial Values to merge.
	 * @return array
	 */
	public static function update( $partial ) {
		$all = self::all();
		foreach ( $partial as $key => $value ) {
			$all[ $key ] = $value;
		}
		update_option( self::OPTION_KEY, $all, false );
		return $all;
	}

	/**
	 * Ensure option exists with defaults (autoload false).
	 */
	public static function ensure_option() {
		if ( false === get_option( self::OPTION_KEY, false ) ) {
			$defaults = self::defaults();
			if ( empty( $defaults['schedule_time'] ) ) {
				$defaults['schedule_time'] = self::nearest_15_minutes();
			}
			add_option( self::OPTION_KEY, $defaults, '', 'no' );
		}
	}

	/**
	 * Snap current local time to nearest 15 minutes (H:i).
	 *
	 * @return string
	 */
	public static function nearest_15_minutes() {
		$ts   = current_time( 'timestamp' );
		$mins = (int) gmdate( 'i', $ts );
		$snap = (int) ( round( $mins / 15 ) * 15 );
		if ( $snap >= 60 ) {
			$ts  += HOUR_IN_SECONDS;
			$snap = 0;
		}
		return gmdate( 'H', $ts ) . ':' . str_pad( (string) $snap, 2, '0', STR_PAD_LEFT );
	}

	/**
	 * Snap an H:i string to nearest 15 minutes.
	 *
	 * @param string $time Time string.
	 * @return string
	 */
	public static function snap_time( $time ) {
		if ( ! preg_match( '/^(\d{1,2}):(\d{2})$/', $time, $m ) ) {
			return self::nearest_15_minutes();
		}
		$h    = min( 23, absint( $m[1] ) );
		$mins = absint( $m[2] );
		$snap = (int) ( round( $mins / 15 ) * 15 );
		if ( $snap >= 60 ) {
			$h    = ( $h + 1 ) % 24;
			$snap = 0;
		}
		return sprintf( '%02d:%02d', $h, $snap );
	}

	/**
	 * Parse exclude/skip folder textarea into relative path list.
	 *
	 * @param string $raw Textarea value.
	 * @return array
	 */
	public static function parse_path_list( $raw ) {
		$lines = preg_split( '/\r\n|\r|\n/', (string) $raw );
		$out   = array();
		foreach ( $lines as $line ) {
			$line = trim( str_replace( '\\', '/', $line ) );
			$line = ltrim( $line, '/' );
			if ( '' !== $line ) {
				$out[] = $line;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Sanitize posted settings form.
	 *
	 * @param array $input Raw POST.
	 * @return array
	 */
	public static function sanitize_from_post( $input ) {
		$clean = array();

		$clean['alerts_enabled']         = empty( $input['alerts_enabled'] ) ? 0 : 1;
		$clean['alert_recipients']       = sanitize_text_field( isset( $input['alert_recipients'] ) ? $input['alert_recipients'] : '' );
		$clean['digest_mode']            = in_array( $input['digest_mode'] ?? '', array( 'significant', 'full' ), true ) ? $input['digest_mode'] : 'significant';
		$clean['schedule_freq']          = in_array( $input['schedule_freq'] ?? '', array( 'daily', 'weekly', 'monthly' ), true ) ? $input['schedule_freq'] : 'weekly';
		$clean['schedule_dow']           = min( 6, max( 0, absint( $input['schedule_dow'] ?? 1 ) ) );
		$clean['schedule_dom']           = min( 28, max( 1, absint( $input['schedule_dom'] ?? 1 ) ) );
		$clean['schedule_time']          = self::snap_time( sanitize_text_field( $input['schedule_time'] ?? '' ) );
		$clean['log_exclude_folders']    = sanitize_textarea_field( $input['log_exclude_folders'] ?? '' );
		$clean['critical_log_mb']        = max( 1, absint( $input['critical_log_mb'] ?? 20 ) );
		$clean['unused_media_count']     = max( 1, absint( $input['unused_media_count'] ?? 50 ) );
		$clean['unused_media_mb']        = max( 1, absint( $input['unused_media_mb'] ?? 200 ) );
		$clean['disk_skip_dirs']         = sanitize_textarea_field( $input['disk_skip_dirs'] ?? '' );
		$clean['disk_delta_pct']         = max( 1, absint( $input['disk_delta_pct'] ?? 10 ) );
		$allowed_retention               = array( 90, 180, 365, 730 );
		$ret                             = absint( $input['event_retention_days'] ?? 180 );
		$clean['event_retention_days']   = in_array( $ret, $allowed_retention, true ) ? $ret : 180;
		$clean['expect_defender']        = empty( $input['expect_defender'] ) ? 0 : 1;
		$clean['module_disk']            = empty( $input['module_disk'] ) ? 0 : 1;
		$clean['module_logs']            = empty( $input['module_logs'] ) ? 0 : 1;
		$clean['module_media']           = empty( $input['module_media'] ) ? 0 : 1;
		$clean['module_audit']           = empty( $input['module_audit'] ) ? 0 : 1;
		$clean['orphaned_media_enabled'] = empty( $input['orphaned_media_enabled'] ) ? 0 : 1;
		$clean['auto_purge_after_90']    = empty( $input['auto_purge_after_90'] ) ? 0 : 1;
		$clean['plugin_event_alerts']    = empty( $input['plugin_event_alerts'] ) ? 0 : 1;
		$clean['urgent_security_alerts'] = empty( $input['urgent_security_alerts'] ) ? 0 : 1;
		$clean['module_password_age']    = empty( $input['module_password_age'] ) ? 0 : 1;

		// Preserve unsubscribed list unless explicitly replaced.
		$existing = self::all();
		$clean['unsubscribed'] = isset( $existing['unsubscribed'] ) && is_array( $existing['unsubscribed'] )
			? $existing['unsubscribed']
			: array();

		if ( ! empty( $input['remove_unsub'] ) && is_array( $input['remove_unsub'] ) ) {
			$remove = array_map( 'sanitize_email', $input['remove_unsub'] );
			$clean['unsubscribed'] = array_values(
				array_filter(
					$clean['unsubscribed'],
					function ( $email ) use ( $remove ) {
						return ! in_array( $email, $remove, true );
					}
				)
			);
		}

		return $clean;
	}

	/**
	 * Active alert recipient emails (minus unsubscribed).
	 *
	 * @return array
	 */
	public static function recipients() {
		$raw   = self::get( 'alert_recipients', '' );
		$parts = preg_split( '/[\s,;]+/', (string) $raw );
		$unsub = self::get( 'unsubscribed', array() );
		if ( ! is_array( $unsub ) ) {
			$unsub = array();
		}
		$emails = array();
		foreach ( $parts as $p ) {
			$email = sanitize_email( $p );
			if ( $email && is_email( $email ) && ! in_array( $email, $unsub, true ) ) {
				$emails[] = $email;
			}
		}
		return array_values( array_unique( $emails ) );
	}
}
