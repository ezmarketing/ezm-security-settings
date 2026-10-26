<?php
/**
 * Digest emails and public unsubscribe.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EZM_Mail {

	public static function init() {
		add_action( 'init', array( __CLASS__, 'maybe_handle_unsubscribe' ) );
		add_filter( 'query_vars', array( __CLASS__, 'query_vars' ) );
		add_action( 'template_redirect', array( __CLASS__, 'render_unsubscribe_page' ) );
		// Gravity SMTP (SendGrid and others) warns when the From name key is missing.
		add_filter( 'wp_mail', array( __CLASS__, 'ensure_mail_from_name' ), 0 );
		add_filter( 'wp_mail_from_name', array( __CLASS__, 'fallback_from_name' ), 9999 );
	}

	/**
	 * Site title used when an email has no From name.
	 *
	 * @return string
	 */
	public static function fallback_from_name( $name = '' ) {
		$name = trim( wp_strip_all_tags( (string) $name ) );
		if ( '' !== $name ) {
			return $name;
		}
		$site = trim( wp_strip_all_tags( wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ) );
		return '' !== $site ? $site : 'WordPress';
	}

	/**
	 * Add a From name when headers omit it, so SMTP connectors don't read a missing name key.
	 *
	 * @param array $args wp_mail args.
	 * @return array
	 */
	public static function ensure_mail_from_name( $args ) {
		if ( ! is_array( $args ) ) {
			return $args;
		}
		$headers = isset( $args['headers'] ) ? $args['headers'] : array();
		$lines   = array();
		if ( is_string( $headers ) ) {
			$lines = preg_split( '/\r\n|\n|\r/', $headers );
		} elseif ( is_array( $headers ) ) {
			$lines = $headers;
		}
		if ( ! is_array( $lines ) ) {
			$lines = array();
		}

		$from_index = null;
		foreach ( $lines as $i => $line ) {
			if ( is_string( $line ) && preg_match( '/^from\s*:/i', $line ) ) {
				$from_index = $i;
				break;
			}
		}

		$name  = self::fallback_from_name();
		$email = sanitize_email( (string) get_option( 'admin_email' ) );
		if ( ! is_email( $email ) ) {
			return $args;
		}

		if ( null === $from_index ) {
			$lines[] = sprintf( 'From: %s <%s>', $name, $email );
		} else {
			$line = $lines[ $from_index ];
			// "From: user@example.com" has no display name.
			if ( ! preg_match( '/^from\s*:\s*.+<[^>]+>/i', $line ) ) {
				if ( preg_match( '/([A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,})/i', $line, $m ) ) {
					$email = $m[1];
				}
				$lines[ $from_index ] = sprintf( 'From: %s <%s>', $name, $email );
			}
		}

		$args['headers'] = is_string( $headers ) ? implode( "\r\n", $lines ) : array_values( $lines );
		return $args;
	}

	public static function query_vars( $vars ) {
		$vars[] = 'ezm_unsubscribe';
		$vars[] = 'ezm_email';
		$vars[] = 'ezm_token';
		return $vars;
	}

	/**
	 * Prefetch-safe: GET shows confirm; POST opts out.
	 */
	public static function maybe_handle_unsubscribe() {
		if ( empty( $_POST['ezm_unsubscribe_confirm'] ) ) {
			return;
		}
		$email = isset( $_POST['ezm_email'] ) ? sanitize_email( wp_unslash( $_POST['ezm_email'] ) ) : '';
		$token = isset( $_POST['ezm_token'] ) ? sanitize_text_field( wp_unslash( $_POST['ezm_token'] ) ) : '';
		if ( ! $email || ! is_email( $email ) || ! hash_equals( self::token_for( $email ), $token ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ?? '' ) ), 'ezm_unsub_' . $email ) ) {
			return;
		}

		$settings = EZM_Settings::all();
		$list     = isset( $settings['unsubscribed'] ) && is_array( $settings['unsubscribed'] ) ? $settings['unsubscribed'] : array();
		if ( ! in_array( $email, $list, true ) ) {
			$list[] = $email;
			EZM_Settings::update( array( 'unsubscribed' => $list ) );
		}
		wp_safe_redirect( add_query_arg( array( 'ezm_unsubscribe' => 'done', 'ezm_email' => rawurlencode( $email ) ), home_url( '/' ) ) );
		exit;
	}

	public static function render_unsubscribe_page() {
		if ( empty( $_GET['ezm_unsubscribe'] ) ) {
			return;
		}
		$status = sanitize_key( wp_unslash( $_GET['ezm_unsubscribe'] ) );
		$email  = isset( $_GET['ezm_email'] ) ? sanitize_email( wp_unslash( $_GET['ezm_email'] ) ) : '';
		$token  = isset( $_GET['ezm_token'] ) ? sanitize_text_field( wp_unslash( $_GET['ezm_token'] ) ) : '';

		status_header( 200 );
		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );

		echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>' . esc_html__( 'Unsubscribe', 'ezm-security-settings' ) . '</title>';
		echo '<style>body{font-family:system-ui,sans-serif;max-width:520px;margin:40px auto;padding:0 16px;color:#1d2327}button{padding:8px 14px}</style></head><body>';

		if ( 'done' === $status ) {
			echo '<h1>' . esc_html__( 'You are unsubscribed', 'ezm-security-settings' ) . '</h1>';
			echo '<p>' . esc_html( sprintf( __( '%s will no longer receive EZ Maintenance digests from this site.', 'ezm-security-settings' ), $email ) ) . '</p>';
		} else {
			if ( ! $email || ! hash_equals( self::token_for( $email ), $token ) ) {
				echo '<h1>' . esc_html__( 'Invalid link', 'ezm-security-settings' ) . '</h1>';
			} else {
				echo '<h1>' . esc_html__( 'Confirm unsubscribe', 'ezm-security-settings' ) . '</h1>';
				echo '<p>' . esc_html( sprintf( __( 'Stop digest emails for %s on %s?', 'ezm-security-settings' ), $email, get_bloginfo( 'name' ) ) ) . '</p>';
				echo '<form method="post">';
				wp_nonce_field( 'ezm_unsub_' . $email );
				echo '<input type="hidden" name="ezm_unsubscribe_confirm" value="1" />';
				echo '<input type="hidden" name="ezm_email" value="' . esc_attr( $email ) . '" />';
				echo '<input type="hidden" name="ezm_token" value="' . esc_attr( $token ) . '" />';
				echo '<button type="submit">' . esc_html__( 'Confirm unsubscribe', 'ezm-security-settings' ) . '</button>';
				echo '</form>';
			}
		}
		echo '</body></html>';
		exit;
	}

	/**
	 * @param string $email Email.
	 * @return string
	 */
	public static function token_for( $email ) {
		return hash_hmac( 'sha256', strtolower( $email ), wp_salt( 'auth' ) );
	}

	/**
	 * @param string $email Email.
	 * @return string
	 */
	public static function unsubscribe_url( $email ) {
		return add_query_arg(
			array(
				'ezm_unsubscribe' => 'confirm',
				'ezm_email'       => rawurlencode( $email ),
				'ezm_token'       => self::token_for( $email ),
			),
			home_url( '/' )
		);
	}

	public static function send_scheduled_digest() {
		self::send_digest( false );
		EZM_Plugin::reschedule_digest();
	}

	public static function send_test_digest() {
		self::send_digest( true );
	}

	/**
	 * Immediate high-priority security alert (not the weekly digest).
	 *
	 * @param string $title   Short title.
	 * @param string $message Body line.
	 * @param array  $meta    Optional details (actor, ip, etc).
	 */
	public static function send_urgent_alert( $title, $message, $meta = array() ) {
		if ( ! EZM_Settings::get( 'alerts_enabled', 1 ) ) {
			return;
		}
		if ( ! EZM_Settings::get( 'urgent_security_alerts', 1 ) ) {
			return;
		}

		$recipients = EZM_Settings::recipients();
		if ( empty( $recipients ) ) {
			$admin = get_option( 'admin_email' );
			if ( $admin ) {
				$recipients = array( $admin );
			}
		}
		if ( empty( $recipients ) ) {
			return;
		}

		// Light debounce: same title+message within 2 minutes.
		$debounce_key = 'ezm_urgent_' . md5( $title . '|' . $message );
		if ( get_transient( $debounce_key ) ) {
			return;
		}
		set_transient( $debounce_key, 1, 2 * MINUTE_IN_SECONDS );

		$site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$url  = home_url( '/' );
		$subject = sprintf( '[%s] URGENT: %s', $site, $title );

		$actor = isset( $meta['actor_display'] ) ? $meta['actor_display'] : '';
		$login = isset( $meta['actor_login'] ) ? $meta['actor_login'] : '';
		$email = isset( $meta['actor_email'] ) ? $meta['actor_email'] : '';
		$ip    = isset( $meta['ip'] ) ? $meta['ip'] : '';
		$when  = wp_date( 'Y-m-d H:i:s T' );

		ob_start();
		?>
		<div style="font-family:Segoe UI,Arial,sans-serif;color:#1d2327;max-width:640px;margin:0 auto;">
			<div style="background:#fcf0f1;border:1px solid #d63638;color:#b32d2e;padding:12px 14px;font-weight:700;margin:0 0 16px;">
				URGENT SECURITY ALERT
			</div>
			<h2 style="margin:0 0 4px;"><?php echo esc_html( $site ); ?></h2>
			<p style="margin:0 0 16px;color:#646970;"><?php echo esc_html( $url ); ?></p>
			<p style="font-size:15px;line-height:1.5;"><strong><?php echo esc_html( $title ); ?></strong></p>
			<p style="font-size:14px;line-height:1.5;"><?php echo esc_html( $message ); ?></p>
			<table cellpadding="6" cellspacing="0" border="1" style="border-collapse:collapse;width:100%;font-size:13px;margin:16px 0;">
				<tr style="background:#f0f0f1;"><th align="left">Field</th><th align="left">Value</th></tr>
				<tr><td>When</td><td><?php echo esc_html( $when ); ?></td></tr>
				<tr><td>Performed by</td><td><?php echo esc_html( $actor . ( $login ? " ({$login})" : '' ) ); ?></td></tr>
				<?php if ( $email ) : ?>
					<tr><td>Actor email</td><td><?php echo esc_html( $email ); ?></td></tr>
				<?php endif; ?>
				<tr><td>IP address</td><td><?php echo esc_html( $ip ? $ip : 'unknown' ); ?></td></tr>
				<?php if ( ! empty( $meta['actor_id'] ) ) : ?>
					<tr><td>Actor user ID</td><td><?php echo (int) $meta['actor_id']; ?></td></tr>
				<?php endif; ?>
			</table>
			<p style="color:#646970;font-size:12px;">If this was not expected, revoke the change and rotate credentials immediately.</p>
		</div>
		<?php
		$body = ob_get_clean();

		$headers = array(
			'Content-Type: text/html; charset=UTF-8',
			'X-Priority: 1',
			'Importance: high',
		);

		foreach ( $recipients as $to ) {
			wp_mail( $to, $subject, $body, $headers );
		}

		EZM_DB::log_event(
			'urgent_alert_sent',
			sprintf( 'Urgent alert emailed: %s', $title ),
			'critical',
			array(
				'title'      => $title,
				'recipients' => $recipients,
			)
		);
	}

	/**
	 * Personal 90-day password reminder to an admin-access user.
	 *
	 * @param array $row From EZM_Password_Age::overdue_admin_users().
	 * @return bool
	 */
	public static function send_password_reminder( $row ) {
		$email = isset( $row['email'] ) ? sanitize_email( $row['email'] ) : '';
		if ( ! $email || ! is_email( $email ) ) {
			return false;
		}

		// Respect digest unsubscribe list.
		$settings = EZM_Settings::all();
		$unsub    = isset( $settings['unsubscribed'] ) && is_array( $settings['unsubscribed'] ) ? $settings['unsubscribed'] : array();
		if ( in_array( strtolower( $email ), array_map( 'strtolower', $unsub ), true ) ) {
			return false;
		}

		$days  = isset( $row['days'] ) ? (int) $row['days'] : EZM_Password_Age::AGE_DAYS;
		$site  = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$login = wp_login_url();
		$subject = "It's time to update your WordPress Password";

		ob_start();
		?>
		<div style="font-family:Segoe UI,Arial,sans-serif;color:#1d2327;max-width:640px;margin:0 auto;line-height:1.55;font-size:15px;">
			<p style="margin:0 0 16px;">Hi<?php echo ! empty( $row['display'] ) ? ' ' . esc_html( $row['display'] ) : ''; ?>,</p>
			<p style="margin:0 0 16px;">
				We recommend changing your password every 90 days and it's been
				<strong><?php echo (int) $days; ?> days</strong> since your last password reset
				on <strong><?php echo esc_html( $site ); ?></strong>.
			</p>
			<p style="margin:0 0 16px;">
				We recommend <strong>NOT</strong> reusing passwords. A compromised admin login can lead to
				defaced pages, malware, stolen customer data, spam redirects, or a complete site lockout.
			</p>
			<h2 style="font-size:17px;margin:24px 0 10px;">How to update your password</h2>
			<ol style="margin:0 0 16px;padding-left:22px;">
				<li style="margin-bottom:8px;">Log in to your WordPress dashboard at <a href="<?php echo esc_url( $login ); ?>"><?php echo esc_html( $login ); ?></a>.</li>
				<li style="margin-bottom:8px;">In the top right corner, click <strong>Howdy</strong>.</li>
				<li style="margin-bottom:8px;">Scroll down to <strong>Account Management</strong>.</li>
				<li style="margin-bottom:8px;">If the Sessions <strong>Log Out Everywhere Else</strong> button is clickable, we recommend clicking it. Your account may be logged in on multiple devices.</li>
				<li style="margin-bottom:8px;">Click <strong>Set New Password</strong>, enter your new password, scroll to the bottom, and click <strong>Update Profile</strong>.</li>
			</ol>
			<p style="margin:0 0 16px;">
				That's it. We recommend doing this every 90 days or more often if your site contains/collects sensitive data.
			</p>
			<p style="color:#646970;font-size:12px;margin-top:24px;">
				<?php echo esc_html( $site ); ?> · EZ Maintenance security reminder
			</p>
		</div>
		<?php
		$body = ob_get_clean();

		$headers = array( 'Content-Type: text/html; charset=UTF-8' );
		$ok      = wp_mail( $email, $subject, $body, $headers );

		if ( $ok ) {
			EZM_DB::log_event(
				'password_reminder_sent',
				sprintf( 'Password age reminder sent to %s (%d days).', $email, $days ),
				'info',
				array(
					'user_id' => (int) ( $row['user_id'] ?? 0 ),
					'days'    => $days,
					'email'   => $email,
				)
			);
		}

		return (bool) $ok;
	}

	/**
	 * @param bool $force_full Force full sample content.
	 */
	public static function send_digest( $force_full = false ) {
		if ( ! EZM_Settings::get( 'alerts_enabled', 1 ) && ! $force_full ) {
			return;
		}

		$recipients = EZM_Settings::recipients();
		if ( empty( $recipients ) ) {
			$admin = get_option( 'admin_email' );
			if ( $admin ) {
				$recipients = array( $admin );
			}
		}
		if ( empty( $recipients ) ) {
			return;
		}

		$flags      = self::collect_health_flags();
		$high       = self::has_high_priority( $flags );
		$mode       = EZM_Settings::get( 'digest_mode', 'significant' );
		$significant = $high || ! empty( $flags['significant'] );

		if ( ! $force_full && 'significant' === $mode && ! $significant ) {
			// Monthly heartbeat: send thin mail once a month.
			$ym = current_time( 'Y-m' );
			$last = get_option( 'ezm_heartbeat_month', '' );
			if ( $last === $ym ) {
				return;
			}
			update_option( 'ezm_heartbeat_month', $ym, false );
			$flags['heartbeat'] = true;
		}

		$subject = sprintf(
			'[%s] EZ Maintenance digest%s',
			wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			$high ? ' — HIGH PRIORITY' : ''
		);

		$body = self::build_html( $flags, $high, $force_full || 'full' === $mode );

		$headers = array( 'Content-Type: text/html; charset=UTF-8' );
		if ( $high ) {
			$headers[] = 'X-Priority: 1';
			$headers[] = 'Importance: high';
		}

		foreach ( $recipients as $email ) {
			$html = str_replace( '{{UNSUB_URL}}', esc_url( self::unsubscribe_url( $email ) ), $body );
			wp_mail( $email, $subject, $html, $headers );
		}

		EZM_DB::log_event( 'digest_sent', 'Digest email sent.', $high ? 'warning' : 'info', array( 'recipients' => $recipients, 'high' => $high ) );
	}

	/**
	 * @return array
	 */
	public static function collect_health_flags() {
		$flags = array(
			'high'        => array(),
			'significant' => array(),
			'info'        => array(),
		);

		$critical_logs = EZM_Logs::critical_sized_files();
		foreach ( $critical_logs as $row ) {
			$flags['high'][] = sprintf(
				'Log file ≥ threshold: %s (%s)',
				$row['file_path'],
				size_format( (int) $row['file_size'] )
			);
		}

		$delta = EZM_Disk::significant_disk_delta();
		if ( $delta ) {
			$direction = ( $delta['pct'] >= 0 ) ? 'grew' : 'shrank';
			$flags['high'][] = sprintf(
				'Disk %s %s%% since %s (threshold %s%%): %s → %s',
				$direction,
				abs( $delta['pct'] ),
				$delta['old_date'],
				$delta['threshold'],
				size_format( $delta['old_bytes'] ),
				size_format( $delta['new_bytes'] )
			);
		}

		$count = EZM_Media::count_unreferenced();
		$bytes = EZM_Media::unreferenced_total_bytes();
		$c_thr = (int) EZM_Settings::get( 'unused_media_count', 50 );
		$m_thr = (int) EZM_Settings::get( 'unused_media_mb', 200 ) * 1024 * 1024;
		if ( $count >= $c_thr ) {
			$flags['high'][] = sprintf( 'Unused media count: %d (threshold %d)', $count, $c_thr );
		}
		if ( $bytes >= $m_thr ) {
			$flags['high'][] = sprintf( 'Unused media size: %s (threshold %d MB)', size_format( $bytes ), (int) EZM_Settings::get( 'unused_media_mb', 200 ) );
		}

		$unsafe = EZM_Uploads_Safety::unmarked_findings();
		if ( $unsafe ) {
			$sample = array_slice( wp_list_pluck( $unsafe, 'rel' ), 0, 8 );
			$flags['high'][] = sprintf(
				'Unsafe files in uploads (%d): %s',
				count( $unsafe ),
				implode( ', ', $sample )
			);
		}

		$oc = EZM_Audit::evaluate_object_cache();
		if ( ! empty( $oc['problem'] ) && empty( $oc['ignored'] ) ) {
			$flags['high'][] = $oc['message'] . ( $oc['reasons'] ? ' — ' . implode( ' ', $oc['reasons'] ) : '' );
		}

		if ( class_exists( 'EZM_Password_Age' ) && EZM_Password_Age::enabled() ) {
			$overdue = EZM_Password_Age::overdue_admin_users();
			if ( $overdue ) {
				$lines = array();
				foreach ( array_slice( $overdue, 0, 25 ) as $row ) {
					$lines[] = sprintf(
						'%s (%s) — %d days since last password change',
						$row['login'],
						$row['email'],
						(int) $row['days']
					);
				}
				$flags['high'][] = sprintf(
					'Admin passwords not changed in %d+ days (%d user%s): %s',
					EZM_Password_Age::AGE_DAYS,
					count( $overdue ),
					count( $overdue ) === 1 ? '' : 's',
					implode( '; ', $lines )
				);
			}
		}

		$events = EZM_Audit::recent_events( 7 );
		foreach ( $events as $ev ) {
			if ( in_array( $ev['severity'], array( 'critical', 'warning' ), true ) ) {
				$flags['significant'][] = $ev['message'];
			}
		}

		return $flags;
	}

	/**
	 * @param array $flags Flags.
	 * @return bool
	 */
	public static function has_high_priority( $flags ) {
		return ! empty( $flags['high'] );
	}

	/**
	 * @param array $flags Flags.
	 * @param bool  $high  High priority.
	 * @param bool  $full  Full digest.
	 * @return string
	 */
	public static function build_html( $flags, $high, $full ) {
		$site = get_bloginfo( 'name' );
		$url  = home_url( '/' );
		$period = sprintf( __( 'Last 7 days ending %s', 'ezm-security-settings' ), current_time( 'Y-m-d' ) );

		$samples = EZM_Disk::get_daily_samples();
		ksort( $samples );
		$samples = array_slice( $samples, -7, null, true );

		ob_start();
		?>
		<div style="font-family:Segoe UI,Arial,sans-serif;color:#1d2327;max-width:720px;margin:0 auto;">
			<h2 style="margin-bottom:4px;"><?php echo esc_html( $site ); ?></h2>
			<p style="margin-top:0;color:#646970;"><?php echo esc_html( $url ); ?> · <?php echo esc_html( $period ); ?></p>

			<?php if ( $high ) : ?>
				<div style="background:#fcf0f1;border:1px solid #d63638;color:#b32d2e;padding:12px 14px;font-weight:700;margin:16px 0;">
					HIGH PRIORITY — review needed
				</div>
			<?php endif; ?>

			<h3>7-day disk snapshot</h3>
			<table cellpadding="6" cellspacing="0" border="1" style="border-collapse:collapse;width:100%;font-size:13px;">
				<thead>
					<tr style="background:#f0f0f1;">
						<th align="left">Day</th>
						<th align="right">Total disk</th>
						<th align="right">Uploads</th>
						<th align="right">Logs</th>
						<th align="right">Files</th>
						<th align="right">Δ</th>
					</tr>
				</thead>
				<tbody>
				<?php
				$prev = null;
				foreach ( $samples as $row ) :
					$delta = ( null === $prev ) ? '—' : size_format( (int) $row['total_bytes'] - (int) $prev );
					$prev  = (int) $row['total_bytes'];
					?>
					<tr>
						<td><?php echo esc_html( $row['date'] ); ?></td>
						<td align="right"><?php echo esc_html( size_format( (int) $row['total_bytes'] ) ); ?></td>
						<td align="right"><?php echo esc_html( size_format( (int) $row['uploads'] ) ); ?></td>
						<td align="right"><?php echo esc_html( size_format( (int) $row['logs_tracked'] ) ); ?></td>
						<td align="right"><?php echo esc_html( (string) (int) $row['file_count'] ); ?></td>
						<td align="right"><?php echo esc_html( $delta ); ?></td>
					</tr>
				<?php endforeach; ?>
				<?php if ( empty( $samples ) ) : ?>
					<tr><td colspan="6"><?php esc_html_e( 'No samples yet — daily sampler will populate this.', 'ezm-security-settings' ); ?></td></tr>
				<?php endif; ?>
				</tbody>
			</table>

			<?php
			$agg = EZM_Logs::get_error_aggregation();
			$items = isset( $agg['items'] ) ? $agg['items'] : array();
			?>
			<h3>Top error-log issues</h3>
			<?php if ( empty( $items ) ) : ?>
				<p>None aggregated yet.</p>
			<?php else : ?>
				<ol>
				<?php foreach ( array_slice( $items, 0, 8 ) as $item ) : ?>
					<li><strong><?php echo (int) $item['count']; ?>×</strong> <?php echo esc_html( $item['message'] ); ?>
						<br><span style="color:#646970;font-size:12px;"><?php echo esc_html( $item['sample'] ); ?></span></li>
				<?php endforeach; ?>
				</ol>
			<?php endif; ?>

			<?php
			$bullets = array_merge( $flags['high'], $flags['significant'] );
			if ( $full || $bullets ) :
				?>
				<h3>Health flags</h3>
				<?php if ( empty( $bullets ) ) : ?>
					<p>No issues flagged.</p>
				<?php else : ?>
					<ul>
					<?php foreach ( array_slice( $bullets, 0, 15 ) as $b ) : ?>
						<li><?php echo esc_html( $b ); ?></li>
					<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			<?php endif; ?>

			<p style="margin:24px 0;">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=ezm-disk-space' ) ); ?>" style="background:#2271b1;color:#fff;padding:10px 16px;text-decoration:none;border-radius:3px;">View full disk report</a>
				&nbsp;
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=ezm-logs' ) ); ?>">Logs</a> ·
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=ezm-media-usage' ) ); ?>">Media Usage</a>
			</p>

			<p style="font-size:12px;color:#646970;">
				<a href="{{UNSUB_URL}}">Unsubscribe</a>
			</p>
		</div>
		<?php
		return ob_get_clean();
	}
}
