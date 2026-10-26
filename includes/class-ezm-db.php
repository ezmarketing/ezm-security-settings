<?php
/**
 * Database schema and migrations.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EZM_DB {

	const DB_VERSION = '2.0.2';

	public static function maybe_upgrade() {
		$installed = get_option( 'ezm_db_version', '' );
		if ( $installed === self::DB_VERSION ) {
			return;
		}
		// Convert UNIQUE attachment_id → KEY before dbDelta, or it tries ADD KEY
		// and MySQL errors with "Duplicate key name 'attachment_id'".
		self::migrate_media_usage_indexes( $installed );
		self::install();
		update_option( 'ezm_db_version', self::DB_VERSION, false );
	}

	/**
	 * Drop UNIQUE on attachment_id if present so disk orphans can share attachment_id=0.
	 * Ensure non-unique attachment_id key and unique file_hash exist.
	 *
	 * @param string $from Prior DB version (unused; kept for call-site clarity).
	 */
	private static function migrate_media_usage_indexes( $from ) {
		global $wpdb;
		$table = $wpdb->prefix . 'ezm_media_usage';
		unset( $from );

		// Table may not exist yet on fresh install.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( $exists !== $table ) {
			return;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$indexes = $wpdb->get_results( "SHOW INDEX FROM {$table}", ARRAY_A );
		if ( ! is_array( $indexes ) ) {
			return;
		}

		$has_unique_attachment = false;
		$has_attachment_key    = false;
		$has_file_hash_index   = false;
		foreach ( $indexes as $idx ) {
			if ( empty( $idx['Key_name'] ) ) {
				continue;
			}
			if ( 'attachment_id' === $idx['Key_name'] ) {
				$has_attachment_key = true;
				if ( '0' === (string) $idx['Non_unique'] ) {
					$has_unique_attachment = true;
				}
			}
			if ( 'file_hash' === $idx['Key_name'] ) {
				$has_file_hash_index = true;
			}
		}

		if ( $has_unique_attachment ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "ALTER TABLE {$table} DROP INDEX attachment_id" );
			$has_attachment_key = false;
		}
		if ( ! $has_attachment_key ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "ALTER TABLE {$table} ADD KEY attachment_id (attachment_id)" );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$col = $wpdb->get_results( "SHOW COLUMNS FROM {$table} LIKE 'file_hash'" );
		if ( empty( $col ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "ALTER TABLE {$table} ADD COLUMN file_hash varchar(64) NOT NULL DEFAULT '' AFTER attachment_id" );
		}

		// Backfill hashes for existing rows.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SELECT id, file_path FROM {$table} WHERE file_hash = '' OR file_hash IS NULL", ARRAY_A );
		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$hash = md5( wp_normalize_path( (string) $row['file_path'] ) );
				$wpdb->update( $table, array( 'file_hash' => $hash ), array( 'id' => (int) $row['id'] ) );
			}
		}

		if ( ! $has_file_hash_index ) {
			// Avoid duplicate-key errors if a non-unique file_hash index already exists under another name.
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "ALTER TABLE {$table} ADD UNIQUE KEY file_hash (file_hash)" );
		}
	}

	public static function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$events  = $wpdb->prefix . 'ezm_events';
		$usage   = $wpdb->prefix . 'ezm_media_usage';
		$quar    = $wpdb->prefix . 'ezm_media_quarantine';
		$logs    = $wpdb->prefix . 'ezm_log_files';

		dbDelta(
			"CREATE TABLE {$events} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			event_type varchar(64) NOT NULL DEFAULT '',
			severity varchar(32) NOT NULL DEFAULT 'info',
			message text NOT NULL,
			meta longtext NULL,
			actor_id bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY event_type (event_type),
			KEY created_at (created_at),
			KEY severity (severity)
		) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$usage} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			attachment_id bigint(20) unsigned NOT NULL DEFAULT 0,
			file_hash varchar(64) NOT NULL DEFAULT '',
			status varchar(32) NOT NULL DEFAULT 'unreferenced',
			mime_type varchar(100) NOT NULL DEFAULT '',
			file_path text NOT NULL,
			file_size bigint(20) unsigned NOT NULL DEFAULT 0,
			used_on longtext NULL,
			ignore_note text NULL,
			last_checked_at datetime NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY file_hash (file_hash),
			KEY attachment_id (attachment_id),
			KEY status (status),
			KEY mime_type (mime_type)
		) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$quar} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			attachment_id bigint(20) unsigned NOT NULL DEFAULT 0,
			original_path text NOT NULL,
			quarantine_path text NOT NULL,
			relative_path text NOT NULL,
			file_size bigint(20) unsigned NOT NULL DEFAULT 0,
			quarantined_at datetime NOT NULL,
			scheduled_purge_at datetime NOT NULL,
			restored_at datetime NULL,
			status varchar(32) NOT NULL DEFAULT 'pending',
			meta longtext NULL,
			PRIMARY KEY  (id),
			KEY status (status),
			KEY scheduled_purge_at (scheduled_purge_at),
			KEY attachment_id (attachment_id)
		) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$logs} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			file_path text NOT NULL,
			file_hash varchar(64) NOT NULL DEFAULT '',
			source_label varchar(191) NOT NULL DEFAULT '',
			plugin_slug varchar(191) NOT NULL DEFAULT '',
			file_size bigint(20) unsigned NOT NULL DEFAULT 0,
			mtime datetime NULL,
			ctime datetime NULL,
			size_history longtext NULL,
			last_seen_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY file_hash (file_hash),
			KEY plugin_slug (plugin_slug)
		) {$charset};"
		);
	}

	/**
	 * Insert an audit/digest event.
	 *
	 * @param string $type     Event type slug.
	 * @param string $message  Human message.
	 * @param string $severity info|warning|critical.
	 * @param array  $meta     Extra data.
	 * @param int    $actor_id User ID.
	 * @return int|false
	 */
	public static function log_event( $type, $message, $severity = 'info', $meta = array(), $actor_id = 0 ) {
		global $wpdb;

		if ( ! $actor_id && is_user_logged_in() ) {
			$actor_id = get_current_user_id();
		}

		$table = $wpdb->prefix . 'ezm_events';
		$ok    = $wpdb->insert(
			$table,
			array(
				'event_type' => sanitize_key( $type ),
				'severity'   => sanitize_key( $severity ),
				'message'    => wp_strip_all_tags( $message ),
				'meta'       => wp_json_encode( $meta ),
				'actor_id'   => absint( $actor_id ),
				'created_at' => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%s', '%s', '%d', '%s' )
		);

		return $ok ? (int) $wpdb->insert_id : false;
	}

	/**
	 * Purge events older than retention setting.
	 */
	public static function purge_old_events() {
		global $wpdb;

		$days = EZM_Settings::get( 'event_retention_days', 180 );
		$days = max( 90, absint( $days ) );

		$table = $wpdb->prefix . 'ezm_events';
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE created_at < %s",
				gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) )
			)
		);
	}
}
