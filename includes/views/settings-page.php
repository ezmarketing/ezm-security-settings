<?php
/**
 * Settings admin view.
 *
 * @var array $s Settings.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

settings_errors( 'ezm_settings' );
?>
<div class="wrap ezm-wrap">
	<h1><?php esc_html_e( 'EZ Maintenance — Settings', 'ezm-security-settings' ); ?></h1>
	<p class="description"><?php esc_html_e( 'Alert schedule, thresholds, and module toggles.', 'ezm-security-settings' ); ?></p>

	<form method="post" action="">
		<?php wp_nonce_field( 'ezm_save_settings', 'ezm_settings_nonce' ); ?>

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Email alerts', 'ezm-security-settings' ); ?></th>
				<td>
					<label><input type="checkbox" name="ezm[alerts_enabled]" value="1" <?php checked( ! empty( $s['alerts_enabled'] ) ); ?> />
					<?php esc_html_e( 'Enable email alerts', 'ezm-security-settings' ); ?></label>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="ezm_recipients"><?php esc_html_e( 'Alert recipients', 'ezm-security-settings' ); ?></label></th>
				<td>
					<input type="text" class="large-text" id="ezm_recipients" name="ezm[alert_recipients]" value="<?php echo esc_attr( $s['alert_recipients'] ); ?>" />
					<p class="description"><?php esc_html_e( 'Comma-separated emails. Unsubscribed addresses are skipped.', 'ezm-security-settings' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Digest mode', 'ezm-security-settings' ); ?></th>
				<td>
					<select name="ezm[digest_mode]">
						<option value="significant" <?php selected( $s['digest_mode'], 'significant' ); ?>><?php esc_html_e( 'Significant changes only', 'ezm-security-settings' ); ?></option>
						<option value="full" <?php selected( $s['digest_mode'], 'full' ); ?>><?php esc_html_e( 'Full digest', 'ezm-security-settings' ); ?></option>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'How often', 'ezm-security-settings' ); ?></th>
				<td>
					<select name="ezm[schedule_freq]" id="ezm_schedule_freq">
						<option value="daily" <?php selected( $s['schedule_freq'], 'daily' ); ?>><?php esc_html_e( 'Daily', 'ezm-security-settings' ); ?></option>
						<option value="weekly" <?php selected( $s['schedule_freq'], 'weekly' ); ?>><?php esc_html_e( 'Weekly', 'ezm-security-settings' ); ?></option>
						<option value="monthly" <?php selected( $s['schedule_freq'], 'monthly' ); ?>><?php esc_html_e( 'Monthly', 'ezm-security-settings' ); ?></option>
					</select>
					<select name="ezm[schedule_dow]" class="ezm-weekly-only">
						<?php
						$days = array( __( 'Sunday' ), __( 'Monday' ), __( 'Tuesday' ), __( 'Wednesday' ), __( 'Thursday' ), __( 'Friday' ), __( 'Saturday' ) );
						foreach ( $days as $i => $label ) {
							printf( '<option value="%d" %s>%s</option>', $i, selected( (int) $s['schedule_dow'], $i, false ), esc_html( $label ) );
						}
						?>
					</select>
					<select name="ezm[schedule_dom]" class="ezm-monthly-only">
						<?php for ( $d = 1; $d <= 28; $d++ ) : ?>
							<option value="<?php echo (int) $d; ?>" <?php selected( (int) $s['schedule_dom'], $d ); ?>><?php echo (int) $d; ?></option>
						<?php endfor; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="ezm_time"><?php esc_html_e( 'Time (site timezone)', 'ezm-security-settings' ); ?></label></th>
				<td>
					<input type="time" step="900" id="ezm_time" name="ezm[schedule_time]" value="<?php echo esc_attr( $s['schedule_time'] ); ?>" />
					<p class="description"><?php esc_html_e( 'Snaps to nearest 15 minutes.', 'ezm-security-settings' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="ezm_log_excludes"><?php esc_html_e( 'Exclude folders (logs)', 'ezm-security-settings' ); ?></label></th>
				<td>
					<textarea class="large-text code" rows="4" id="ezm_log_excludes" name="ezm[log_exclude_folders]"><?php echo esc_textarea( $s['log_exclude_folders'] ); ?></textarea>
					<p class="description"><?php esc_html_e( 'One path per line.', 'ezm-security-settings' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="ezm_skip_dirs"><?php esc_html_e( 'Disk scan skip dirs', 'ezm-security-settings' ); ?></label></th>
				<td>
					<textarea class="large-text code" rows="3" id="ezm_skip_dirs" name="ezm[disk_skip_dirs]"><?php echo esc_textarea( $s['disk_skip_dirs'] ); ?></textarea>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="ezm_crit_log"><?php esc_html_e( 'Critical log size (MB)', 'ezm-security-settings' ); ?></label></th>
				<td><input type="number" min="1" id="ezm_crit_log" name="ezm[critical_log_mb]" value="<?php echo esc_attr( (string) $s['critical_log_mb'] ); ?>" /></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Unused media thresholds', 'ezm-security-settings' ); ?></th>
				<td>
					<label><?php esc_html_e( 'Count', 'ezm-security-settings' ); ?>
						<input type="number" min="1" name="ezm[unused_media_count]" value="<?php echo esc_attr( (string) $s['unused_media_count'] ); ?>" />
					</label>
					&nbsp;
					<label><?php esc_html_e( 'Total MB', 'ezm-security-settings' ); ?>
						<input type="number" min="1" name="ezm[unused_media_mb]" value="<?php echo esc_attr( (string) $s['unused_media_mb'] ); ?>" />
					</label>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="ezm_delta"><?php esc_html_e( 'Disk delta alert %', 'ezm-security-settings' ); ?></label></th>
				<td><input type="number" min="1" id="ezm_delta" name="ezm[disk_delta_pct]" value="<?php echo esc_attr( (string) $s['disk_delta_pct'] ); ?>" /></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Event retention', 'ezm-security-settings' ); ?></th>
				<td>
					<select name="ezm[event_retention_days]">
						<option value="90" <?php selected( (int) $s['event_retention_days'], 90 ); ?>><?php esc_html_e( '90 days', 'ezm-security-settings' ); ?></option>
						<option value="180" <?php selected( (int) $s['event_retention_days'], 180 ); ?>><?php esc_html_e( '6 months (default)', 'ezm-security-settings' ); ?></option>
						<option value="365" <?php selected( (int) $s['event_retention_days'], 365 ); ?>><?php esc_html_e( '1 year', 'ezm-security-settings' ); ?></option>
						<option value="730" <?php selected( (int) $s['event_retention_days'], 730 ); ?>><?php esc_html_e( '2 years', 'ezm-security-settings' ); ?></option>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Modules', 'ezm-security-settings' ); ?></th>
				<td>
					<label><input type="checkbox" name="ezm[module_disk]" value="1" <?php checked( ! empty( $s['module_disk'] ) ); ?> /> <?php esc_html_e( 'Disk Space', 'ezm-security-settings' ); ?></label><br />
					<label><input type="checkbox" name="ezm[module_logs]" value="1" <?php checked( ! empty( $s['module_logs'] ) ); ?> /> <?php esc_html_e( 'Logs', 'ezm-security-settings' ); ?></label><br />
					<label><input type="checkbox" name="ezm[module_media]" value="1" <?php checked( ! empty( $s['module_media'] ) ); ?> /> <?php esc_html_e( 'Media Usage', 'ezm-security-settings' ); ?></label><br />
					<label><input type="checkbox" name="ezm[module_audit]" value="1" <?php checked( ! empty( $s['module_audit'] ) ); ?> /> <?php esc_html_e( 'Audit / security events', 'ezm-security-settings' ); ?></label><br />
					<label><input type="checkbox" name="ezm[module_password_age]" value="1" <?php checked( ! empty( $s['module_password_age'] ) ); ?> /> <?php esc_html_e( 'Password age tracking & 90-day admin reminders', 'ezm-security-settings' ); ?></label><br />
					<label><input type="checkbox" name="ezm[expect_defender]" value="1" <?php checked( ! empty( $s['expect_defender'] ) ); ?> /> <?php esc_html_e( 'Expect Defender Pro watchdog', 'ezm-security-settings' ); ?></label><br />
					<label><input type="checkbox" name="ezm[plugin_event_alerts]" value="1" <?php checked( ! empty( $s['plugin_event_alerts'] ) ); ?> /> <?php esc_html_e( 'Plugin add/remove/disable alerts', 'ezm-security-settings' ); ?></label><br />
					<label><input type="checkbox" name="ezm[urgent_security_alerts]" value="1" <?php checked( ! empty( $s['urgent_security_alerts'] ) ); ?> /> <?php esc_html_e( 'Immediate email: new admin user or new plugin', 'ezm-security-settings' ); ?></label><br />
					<label><input type="checkbox" name="ezm[orphaned_media_enabled]" value="1" <?php checked( ! empty( $s['orphaned_media_enabled'] ) ); ?> /> <?php esc_html_e( 'Orphaned media scanning', 'ezm-security-settings' ); ?></label><br />
					<label><input type="checkbox" name="ezm[auto_purge_after_90]" value="1" <?php checked( ! empty( $s['auto_purge_after_90'] ) ); ?> /> <?php esc_html_e( 'Auto permanent-delete quarantined media after 90 days', 'ezm-security-settings' ); ?></label>
				</td>
			</tr>
			<?php if ( ! empty( $s['unsubscribed'] ) && is_array( $s['unsubscribed'] ) ) : ?>
			<tr>
				<th scope="row"><?php esc_html_e( 'Unsubscribed', 'ezm-security-settings' ); ?></th>
				<td>
					<?php foreach ( $s['unsubscribed'] as $email ) : ?>
						<label style="display:block;">
							<input type="checkbox" name="ezm[remove_unsub][]" value="<?php echo esc_attr( $email ); ?>" />
							<?php echo esc_html( $email ); ?> — <?php esc_html_e( 'remove to re-allow', 'ezm-security-settings' ); ?>
						</label>
					<?php endforeach; ?>
				</td>
			</tr>
			<?php endif; ?>
		</table>

		<p class="submit">
			<button type="submit" class="button button-primary"><?php esc_html_e( 'Save settings', 'ezm-security-settings' ); ?></button>
			<button type="submit" name="ezm_send_test_email" value="1" class="button"><?php esc_html_e( 'Send test email', 'ezm-security-settings' ); ?></button>
		</p>
	</form>
</div>
