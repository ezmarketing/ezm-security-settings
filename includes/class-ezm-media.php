<?php
/**
 * Orphaned media, disk orphans, quarantine, 404 restore, cron purge.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EZM_Media {

	const SCAN_TRANSIENT = 'ezm_media_scan_job';

	public static function init() {
		add_action( 'wp_ajax_ezm_media_scan_start', array( __CLASS__, 'ajax_scan_start' ) );
		add_action( 'wp_ajax_ezm_media_scan_tick', array( __CLASS__, 'ajax_scan_tick' ) );
		add_action( 'wp_ajax_ezm_media_ignore', array( __CLASS__, 'ajax_ignore' ) );
		add_action( 'wp_ajax_ezm_media_quarantine', array( __CLASS__, 'ajax_quarantine' ) );
		add_action( 'wp_ajax_ezm_media_restore', array( __CLASS__, 'ajax_restore' ) );
		add_action( 'wp_ajax_ezm_media_purge_now', array( __CLASS__, 'ajax_purge_now' ) );
		add_action( 'wp_ajax_ezm_media_bulk', array( __CLASS__, 'ajax_bulk' ) );
		add_action( 'wp_ajax_ezm_media_delete_all', array( __CLASS__, 'ajax_delete_all' ) );
		add_action( 'wp_ajax_ezm_media_import', array( __CLASS__, 'ajax_import' ) );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_404_restore' ), 0 );
		add_action( 'ezm_media_purge_due', array( __CLASS__, 'purge_due_items' ) );

		if ( ! wp_next_scheduled( 'ezm_media_purge_due' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'ezm_media_purge_due' );
		}
	}

	/**
	 * Quarantine base directory under uploads.
	 *
	 * @return string
	 */
	public static function quarantine_base() {
		$uploads = wp_upload_dir();
		$base    = trailingslashit( wp_normalize_path( $uploads['basedir'] ) ) . 'pending_deletion';
		if ( ! is_dir( $base ) ) {
			wp_mkdir_p( $base );
		}
		return $base;
	}

	/**
	 * @param string $path Absolute path.
	 * @return string
	 */
	public static function path_hash( $path ) {
		return md5( wp_normalize_path( (string) $path ) );
	}

	/**
	 * WooCommerce Products → Placeholder image attachment ID.
	 *
	 * @return int
	 */
	public static function wc_placeholder_attachment_id() {
		$id = absint( get_option( 'woocommerce_placeholder_image', 0 ) );
		return $id;
	}

	/**
	 * Default WC placeholder basename whitelist.
	 *
	 * @param string $path File path or basename.
	 * @return bool
	 */
	public static function is_wc_placeholder_basename( $path ) {
		$base = strtolower( basename( (string) $path ) );
		return (bool) preg_match( '/^woocommerce-placeholder\.(webp|png|jpe?g|gif)$/', $base );
	}

	/**
	 * Whether an attachment is the configured WC placeholder (or default filename).
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $file          Absolute file path.
	 * @return bool
	 */
	public static function is_wc_placeholder( $attachment_id, $file = '' ) {
		$wc_id = self::wc_placeholder_attachment_id();
		if ( $wc_id && (int) $attachment_id === $wc_id ) {
			return true;
		}
		if ( $file && self::is_wc_placeholder_basename( $file ) ) {
			return true;
		}
		return false;
	}

	public static function ajax_scan_start() {
		self::verify();
		$ids = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'orderby'        => 'ID',
				'order'          => 'ASC',
			)
		);
		$job = array(
			'status'    => 'running',
			'phase'     => 'library',
			'queue'     => array_map( 'intval', $ids ),
			'processed' => 0,
			'total'     => count( $ids ),
			'known'     => array(),
		);
		set_transient( self::SCAN_TRANSIENT, $job, HOUR_IN_SECONDS );
		wp_send_json_success( array( 'job' => self::public_job( $job ) ) );
	}

	public static function ajax_scan_tick() {
		self::verify();
		$job = get_transient( self::SCAN_TRANSIENT );
		if ( ! is_array( $job ) ) {
			wp_send_json_error( array( 'message' => 'no job' ) );
		}
		$job = self::process_scan_chunk( $job );
		if ( 'done' === $job['status'] ) {
			delete_transient( self::SCAN_TRANSIENT );
		} else {
			set_transient( self::SCAN_TRANSIENT, $job, HOUR_IN_SECONDS );
		}
		wp_send_json_success( array( 'job' => self::public_job( $job ) ) );
	}

	/**
	 * Process a batch: library attachments, then disk orphans.
	 *
	 * @param array $job Job.
	 * @return array
	 */
	public static function process_scan_chunk( $job ) {
		if ( empty( $job['phase'] ) ) {
			$job['phase'] = 'library';
		}

		if ( 'library' === $job['phase'] ) {
			$budget = 15;
			$n      = 0;
			while ( $n < $budget && ! empty( $job['queue'] ) ) {
				$id = (int) array_shift( $job['queue'] );
				self::evaluate_attachment( $id );
				$job['processed']++;
				$n++;
			}
			if ( empty( $job['queue'] ) ) {
				$job = self::begin_disk_phase( $job );
			}
			return $job;
		}

		// Disk phase.
		$budget = 40;
		$n      = 0;
		while ( $n < $budget && ! empty( $job['queue'] ) ) {
			$path = (string) array_shift( $job['queue'] );
			self::evaluate_disk_file( $path );
			$job['processed']++;
			$n++;
		}
		if ( empty( $job['queue'] ) ) {
			$job['status'] = 'done';
		}
		return $job;
	}

	/**
	 * Transition job to disk orphan discovery.
	 *
	 * @param array $job Job.
	 * @return array
	 */
	private static function begin_disk_phase( $job ) {
		self::prune_stale_usage_rows();
		$known = self::build_known_attachment_paths();
		$files = self::collect_orphan_disk_paths( $known );

		$job['phase']     = 'disk';
		$job['known']     = array(); // drop large map from transient
		$job['queue']     = $files;
		$job['processed'] = 0;
		$job['total']     = count( $files );
		$job['status']    = empty( $files ) ? 'done' : 'running';
		return $job;
	}

	/**
	 * Absolute normalized paths known to the media library.
	 *
	 * @return array<string,true>
	 */
	public static function build_known_attachment_paths() {
		global $wpdb;
		$uploads = wp_upload_dir();
		$basedir = trailingslashit( wp_normalize_path( $uploads['basedir'] ) );
		$known   = array();

		$rows = $wpdb->get_results(
			"SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file'",
			ARRAY_A
		);
		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$rel = ltrim( str_replace( '\\', '/', (string) $row['meta_value'] ), '/' );
				if ( '' === $rel ) {
					continue;
				}
				$abs = wp_normalize_path( $basedir . $rel );
				$known[ $abs ] = true;
			}
		}

		$meta_rows = $wpdb->get_results(
			"SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attachment_metadata'",
			ARRAY_A
		);
		if ( is_array( $meta_rows ) ) {
			foreach ( $meta_rows as $row ) {
				$meta = maybe_unserialize( $row['meta_value'] );
				if ( ! is_array( $meta ) ) {
					continue;
				}
				$dir = '';
				if ( ! empty( $meta['file'] ) ) {
					$file_rel = ltrim( str_replace( '\\', '/', (string) $meta['file'] ), '/' );
					$abs      = wp_normalize_path( $basedir . $file_rel );
					$known[ $abs ] = true;
					$dir = trailingslashit( dirname( $abs ) );
				}
				if ( ! empty( $meta['original_image'] ) && $dir ) {
					$known[ wp_normalize_path( $dir . $meta['original_image'] ) ] = true;
				}
				if ( ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) && $dir ) {
					foreach ( $meta['sizes'] as $sz ) {
						if ( ! empty( $sz['file'] ) ) {
							$known[ wp_normalize_path( $dir . $sz['file'] ) ] = true;
						}
					}
				}
			}
		}

		return $known;
	}

	/**
	 * Collect upload files not tracked by the media library.
	 *
	 * @param array $known Known absolute paths.
	 * @return array Absolute paths.
	 */
	public static function collect_orphan_disk_paths( $known ) {
		$uploads = wp_upload_dir();
		$basedir = wp_normalize_path( $uploads['basedir'] );
		if ( ! is_dir( $basedir ) ) {
			return array();
		}

		$skip_basenames = array_merge(
			EZM_Settings::parse_path_list( EZM_Settings::get( 'disk_skip_dirs', '' ) ),
			array( 'pending_deletion', 'wc-logs', 'cache', 'wflogs', 'backup', 'backups', 'updraft', 'ai1wm-backups' )
		);
		$skip_basenames = array_map( 'strtolower', $skip_basenames );
		$skip_files     = array( '.htaccess', 'index.php', 'index.html', 'index.htm', 'web.config' );

		$found = array();
		$start = microtime( true );
		$max_s = 20.0;

		try {
			$it = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $basedir, FilesystemIterator::SKIP_DOTS ),
				RecursiveIteratorIterator::LEAVES_ONLY
			);
		} catch ( Exception $e ) {
			return array();
		}

		foreach ( $it as $fileinfo ) {
			if ( ( microtime( true ) - $start ) > $max_s ) {
				break;
			}
			/** @var SplFileInfo $fileinfo */
			if ( ! $fileinfo->isFile() ) {
				continue;
			}

			$name = $fileinfo->getFilename();
			if ( in_array( strtolower( $name ), $skip_files, true ) ) {
				continue;
			}

			// Skip dirs by path segments.
			$path = wp_normalize_path( $fileinfo->getPathname() );
			$rel  = ltrim( substr( $path, strlen( trailingslashit( $basedir ) ) ), '/' );
			$parts = explode( '/', strtolower( $rel ) );
			$skip  = false;
			foreach ( $parts as $part ) {
				if ( in_array( $part, $skip_basenames, true ) ) {
					$skip = true;
					break;
				}
				if ( 0 === strpos( $part, 'backup' ) ) {
					$skip = true;
					break;
				}
			}
			if ( $skip ) {
				continue;
			}

			// Only media-like extensions (plus common leftovers).
			$ext = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
			$ok  = array(
				'jpg', 'jpeg', 'jpe', 'png', 'gif', 'webp', 'avif', 'bmp', 'tiff', 'tif', 'ico', 'svg',
				'mp3', 'm4a', 'ogg', 'wav', 'flac',
				'mp4', 'm4v', 'mov', 'wmv', 'avi', 'webm',
				'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'zip', 'gz', 'csv', 'txt',
			);
			if ( ! in_array( $ext, $ok, true ) ) {
				continue;
			}

			if ( isset( $known[ $path ] ) ) {
				continue;
			}

			// Skip WC placeholder sitting on disk without library row? Still list as disk_orphan
			// unless basename whitelist — treat as system file, do not queue.
			if ( self::is_wc_placeholder_basename( $path ) ) {
				continue;
			}

			// Never index WP size/scaled sidecars as disk orphans — they ride with the original.
			if ( self::is_size_sidecar( $path ) ) {
				continue;
			}

			$found[] = $path;
			if ( count( $found ) >= 5000 ) {
				break;
			}
		}

		return $found;
	}

	/**
	 * Drop usage rows for files that no longer exist, and size-sidecar disk orphans.
	 *
	 * @return int Rows removed.
	 */
	public static function prune_stale_usage_rows() {
		global $wpdb;
		$table = $wpdb->prefix . 'ezm_media_usage';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows  = $wpdb->get_results(
			"SELECT id, file_path, status FROM {$table} WHERE status IN ('disk_orphan','unreferenced','pending_deletion') LIMIT 5000",
			ARRAY_A
		);
		if ( ! is_array( $rows ) ) {
			return 0;
		}

		$removed = 0;
		foreach ( $rows as $row ) {
			$path   = wp_normalize_path( (string) $row['file_path'] );
			$missing = ( '' === $path || ! is_file( $path ) );
			$sidecar = ( 'disk_orphan' === $row['status'] && self::is_size_sidecar( $path ) );
			if ( ! $missing && ! $sidecar ) {
				continue;
			}
			$wpdb->delete( $table, array( 'id' => (int) $row['id'] ), array( '%d' ) );
			$removed++;
		}
		return $removed;
	}

	/**
	 * Remove a stale usage row (and library attachment when appropriate).
	 *
	 * @param array  $row    Usage row.
	 * @param string $reason Reason code.
	 * @return array
	 */
	public static function clear_stale_usage_row( $row, $reason = 'missing_on_disk' ) {
		global $wpdb;
		$table = $wpdb->prefix . 'ezm_media_usage';
		$aid   = isset( $row['attachment_id'] ) ? (int) $row['attachment_id'] : 0;

		if ( ! empty( $row['id'] ) ) {
			$wpdb->delete( $table, array( 'id' => (int) $row['id'] ), array( '%d' ) );
		} elseif ( ! empty( $row['file_hash'] ) ) {
			$wpdb->delete( $table, array( 'file_hash' => $row['file_hash'] ), array( '%s' ) );
		}

		if ( $aid > 0 ) {
			$file = get_attached_file( $aid );
			if ( ! $file || ! is_file( $file ) ) {
				wp_delete_attachment( $aid, true );
			}
		}

		EZM_DB::log_event(
			'media_stale_cleared',
			sprintf( 'Cleared missing media usage row (%s).', $reason ),
			'info',
			array(
				'attachment_id' => $aid,
				'file_path'     => $row['file_path'] ?? '',
				'reason'        => $reason,
			)
		);

		return array(
			'cleared' => true,
			'reason'  => $reason,
		);
	}

	/**
	 * Upsert a disk-only orphan row.
	 *
	 * @param string $path Absolute path.
	 */
	public static function evaluate_disk_file( $path ) {
		global $wpdb;

		$path = wp_normalize_path( $path );
		if ( ! is_file( $path ) ) {
			return;
		}

		$hash = self::path_hash( $path );
		$ft   = wp_check_filetype( $path );
		$mime = ! empty( $ft['type'] ) ? $ft['type'] : 'application/octet-stream';
		$size = (int) filesize( $path );
		$table = $wpdb->prefix . 'ezm_media_usage';

		$existing = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE file_hash = %s", $hash ),
			ARRAY_A
		);

		$status = 'disk_orphan';
		if ( $existing && 'ignored' === $existing['status'] ) {
			$status = 'ignored';
		}
		if ( $existing && 'pending_deletion' === $existing['status'] ) {
			$status = 'pending_deletion';
		}

		$row = array(
			'attachment_id'   => 0,
			'file_hash'       => $hash,
			'status'          => $status,
			'mime_type'       => $mime,
			'file_path'       => $path,
			'file_size'       => $size,
			'used_on'         => wp_json_encode( array() ),
			'ignore_note'     => $existing['ignore_note'] ?? '',
			'last_checked_at' => current_time( 'mysql', true ),
			'updated_at'      => current_time( 'mysql', true ),
		);

		if ( $existing ) {
			$wpdb->update( $table, $row, array( 'id' => (int) $existing['id'] ) );
		} else {
			$wpdb->insert( $table, $row );
		}
	}

	/**
	 * Deep-ish usage check for one attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	public static function evaluate_attachment( $attachment_id ) {
		global $wpdb;

		$attachment_id = absint( $attachment_id );
		$post          = get_post( $attachment_id );
		if ( ! $post || 'attachment' !== $post->post_type ) {
			return;
		}

		$file = get_attached_file( $attachment_id );
		$file = $file ? wp_normalize_path( $file ) : '';
		$size = ( $file && is_file( $file ) ) ? (int) filesize( $file ) : 0;
		$mime = get_post_mime_type( $attachment_id );
		$hash = $file ? self::path_hash( $file ) : self::path_hash( 'attachment:' . $attachment_id );

		$used_on = array();
		$url     = wp_get_attachment_url( $attachment_id );
		$meta    = wp_get_attachment_metadata( $attachment_id );
		$search  = array();

		// WooCommerce placeholder (option ID and/or default filename).
		if ( self::is_wc_placeholder( $attachment_id, $file ) ) {
			$used_on[] = array(
				'id'  => 0,
				'how' => 'woocommerce_placeholder',
			);
		}

		if ( $url ) {
			$search[] = $url;
			$search[] = wp_make_link_relative( $url );
		}
		$search[] = (string) $attachment_id;
		if ( ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) && $file ) {
			foreach ( $meta['sizes'] as $sz ) {
				if ( ! empty( $sz['file'] ) ) {
					$search[] = $sz['file'];
				}
			}
		}

		// Featured image.
		$thumbs = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_thumbnail_id' AND meta_value = %s LIMIT 20",
				(string) $attachment_id
			)
		);
		foreach ( $thumbs as $pid ) {
			$used_on[] = array( 'id' => (int) $pid, 'how' => 'featured' );
		}

		// Content / meta text search (bounded) — skip if already referenced via WC placeholder.
		if ( empty( $used_on ) ) {
			foreach ( array_unique( array_filter( $search ) ) as $needle ) {
				if ( strlen( $needle ) < 3 ) {
					continue;
				}
				$like = '%' . $wpdb->esc_like( $needle ) . '%';
				$ids  = $wpdb->get_col(
					$wpdb->prepare(
						"SELECT ID FROM {$wpdb->posts} WHERE post_status IN ('publish','draft','private') AND post_type NOT IN ('attachment','revision') AND post_content LIKE %s LIMIT 10",
						$like
					)
				);
				foreach ( $ids as $pid ) {
					$used_on[] = array( 'id' => (int) $pid, 'how' => 'content' );
				}
				$meta_pids = $wpdb->get_col(
					$wpdb->prepare(
						"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_value LIKE %s LIMIT 15",
						$like
					)
				);
				foreach ( $meta_pids as $pid ) {
					if ( (int) $pid === $attachment_id ) {
						continue;
					}
					$used_on[] = array( 'id' => (int) $pid, 'how' => 'meta' );
				}
				if ( count( $used_on ) > 0 ) {
					break;
				}
			}

			// Also check options for this attachment ID (covers WC placeholder if get_option missed).
			$opt = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT option_name FROM {$wpdb->options} WHERE option_name = 'woocommerce_placeholder_image' AND option_value = %s LIMIT 1",
					(string) $attachment_id
				)
			);
			if ( $opt ) {
				$used_on[] = array( 'id' => 0, 'how' => 'woocommerce_placeholder' );
			}
		}

		$uniq = array();
		foreach ( $used_on as $u ) {
			$uniq[ $u['id'] . ':' . $u['how'] ] = $u;
		}
		$used_on = array_values( $uniq );

		$table = $wpdb->prefix . 'ezm_media_usage';
		$existing = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE attachment_id = %d OR file_hash = %s ORDER BY attachment_id DESC LIMIT 1",
				$attachment_id,
				$hash
			),
			ARRAY_A
		);

		$status = empty( $used_on ) ? 'unreferenced' : 'referenced';
		if ( $existing && 'ignored' === $existing['status'] ) {
			$status = 'ignored';
		}
		if ( $existing && 'pending_deletion' === $existing['status'] ) {
			$status = 'pending_deletion';
		}

		$row = array(
			'attachment_id'   => $attachment_id,
			'file_hash'       => $hash,
			'status'          => $status,
			'mime_type'       => (string) $mime,
			'file_path'       => $file,
			'file_size'       => $size,
			'used_on'         => wp_json_encode( $used_on ),
			'ignore_note'     => $existing['ignore_note'] ?? '',
			'last_checked_at' => current_time( 'mysql', true ),
			'updated_at'      => current_time( 'mysql', true ),
		);

		if ( $existing ) {
			$wpdb->update( $table, $row, array( 'id' => (int) $existing['id'] ) );
		} else {
			$wpdb->insert( $table, $row );
		}
	}

	/**
	 * Build WHERE clause + params for usage list/count.
	 *
	 * @param array $args status, mime, s.
	 * @return array{0:string,1:array}
	 */
	public static function usage_where( $args = array() ) {
		global $wpdb;
		$status = isset( $args['status'] ) ? sanitize_key( $args['status'] ) : 'unreferenced';
		$mime   = isset( $args['mime'] ) ? sanitize_text_field( $args['mime'] ) : '';
		$search = isset( $args['s'] ) ? sanitize_text_field( $args['s'] ) : '';

		$where  = '1=1';
		$params = array();

		if ( 'orphan_queue' === $status ) {
			$where .= " AND status IN ('unreferenced','disk_orphan')";
		} elseif ( 'all' !== $status ) {
			$where   .= ' AND status = %s';
			$params[] = $status;
		}
		if ( $mime ) {
			if ( 'image' === $mime ) {
				$where .= " AND mime_type LIKE 'image/%'";
			} elseif ( 'audio' === $mime ) {
				$where .= " AND mime_type LIKE 'audio/%'";
			} elseif ( 'video' === $mime ) {
				$where .= " AND mime_type LIKE 'video/%'";
			} elseif ( 'document' === $mime ) {
				$where .= " AND mime_type NOT LIKE 'image/%' AND mime_type NOT LIKE 'audio/%' AND mime_type NOT LIKE 'video/%'";
			}
		}
		if ( $search ) {
			$where   .= ' AND file_path LIKE %s';
			$params[] = '%' . $wpdb->esc_like( $search ) . '%';
		}

		return array( $where, $params );
	}

	/**
	 * List usage rows for UI.
	 *
	 * @param array $args Args.
	 * @return array
	 */
	public static function list_usage( $args = array() ) {
		global $wpdb;
		$table  = $wpdb->prefix . 'ezm_media_usage';
		$limit  = isset( $args['per_page'] ) ? max( 1, absint( $args['per_page'] ) ) : 30;
		$offset = isset( $args['offset'] ) ? absint( $args['offset'] ) : 0;

		list( $where, $params ) = self::usage_where( $args );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql      = "SELECT * FROM {$table} WHERE {$where} ORDER BY file_size DESC LIMIT %d OFFSET %d";
		$params[] = $limit;
		$params[] = $offset;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Count usage rows for a filter.
	 *
	 * @param array $args status, mime, s.
	 * @return int
	 */
	public static function count_usage( $args = array() ) {
		global $wpdb;
		$table = $wpdb->prefix . 'ezm_media_usage';
		list( $where, $params ) = self::usage_where( $args );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = "SELECT COUNT(*) FROM {$table} WHERE {$where}";
		if ( $params ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			return (int) $wpdb->get_var( $wpdb->prepare( $sql, $params ) );
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return (int) $wpdb->get_var( $sql );
	}

	public static function count_unreferenced() {
		global $wpdb;
		$table = $wpdb->prefix . 'ezm_media_usage';
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status IN ('unreferenced','disk_orphan')" );
	}

	public static function count_disk_orphans() {
		global $wpdb;
		$table = $wpdb->prefix . 'ezm_media_usage';
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'disk_orphan'" );
	}

	public static function unreferenced_total_bytes() {
		global $wpdb;
		$table = $wpdb->prefix . 'ezm_media_usage';
		return (int) $wpdb->get_var( "SELECT SUM(file_size) FROM {$table} WHERE status IN ('unreferenced','disk_orphan')" );
	}

	public static function ajax_ignore() {
		self::verify();
		global $wpdb;
		$id   = absint( $_POST['attachment_id'] ?? 0 );
		$hash = isset( $_POST['file_hash'] ) ? sanitize_text_field( wp_unslash( $_POST['file_hash'] ) ) : '';
		$note = sanitize_textarea_field( wp_unslash( $_POST['note'] ?? '' ) );
		$table = $wpdb->prefix . 'ezm_media_usage';

		$data = array(
			'status'      => 'ignored',
			'ignore_note' => $note,
			'updated_at'  => current_time( 'mysql', true ),
		);

		if ( $id > 0 ) {
			$wpdb->update( $table, $data, array( 'attachment_id' => $id ) );
		} elseif ( $hash ) {
			$wpdb->update( $table, $data, array( 'file_hash' => $hash ) );
		}
		wp_send_json_success();
	}

	public static function ajax_quarantine() {
		self::verify();
		$id   = absint( $_POST['attachment_id'] ?? 0 );
		$hash = isset( $_POST['file_hash'] ) ? sanitize_text_field( wp_unslash( $_POST['file_hash'] ) ) : '';

		if ( $id > 0 ) {
			$result = self::quarantine_attachment( $id );
		} elseif ( $hash ) {
			$result = self::quarantine_disk_file( $hash );
		} else {
			$result = new WP_Error( 'missing', __( 'Missing attachment or file hash.', 'ezm-security-settings' ) );
		}

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		wp_send_json_success( $result );
	}

	public static function ajax_restore() {
		self::verify();
		$qid = absint( $_POST['quarantine_id'] ?? 0 );
		$result = self::restore_quarantine( $qid, 'manual' );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		wp_send_json_success( $result );
	}

	public static function ajax_purge_now() {
		self::verify();
		$n = self::purge_due_items( true );
		wp_send_json_success( array( 'purged' => $n ) );
	}

	/**
	 * Discover sibling size/scaled files next to a primary file.
	 *
	 * @param string $file Absolute primary path.
	 * @return array
	 */
	public static function sibling_media_files( $file ) {
		$file = wp_normalize_path( $file );
		$files = array( $file );
		$dir   = trailingslashit( dirname( $file ) );
		$base  = pathinfo( $file, PATHINFO_FILENAME );
		$ext   = pathinfo( $file, PATHINFO_EXTENSION );

		// Strip -scaled / -NNNxNNN for sibling pattern.
		$stem = preg_replace( '/-(scaled|\d+x\d+)$/', '', $base );
		if ( ! $stem ) {
			$stem = $base;
		}

		$scaled = $dir . $stem . '-scaled.' . $ext;
		if ( is_file( $scaled ) ) {
			$files[] = wp_normalize_path( $scaled );
		}

		$matches = glob( $dir . $stem . '-*x*.' . $ext );
		if ( is_array( $matches ) ) {
			foreach ( $matches as $m ) {
				if ( is_file( $m ) && preg_match( '/-\d+x\d+\.' . preg_quote( $ext, '/' ) . '$/', $m ) ) {
					$files[] = wp_normalize_path( $m );
				}
			}
		}

		return array_values( array_unique( $files ) );
	}

	/**
	 * Move attachment files into pending_deletion preserving yyyy/mm.
	 *
	 * @param int $attachment_id ID.
	 * @return array|WP_Error
	 */
	public static function quarantine_attachment( $attachment_id ) {
		$file = get_attached_file( $attachment_id );
		if ( ! $file || ! is_file( $file ) ) {
			global $wpdb;
			$table = $wpdb->prefix . 'ezm_media_usage';
			$row   = $wpdb->get_row(
				$wpdb->prepare( "SELECT * FROM {$table} WHERE attachment_id = %d LIMIT 1", $attachment_id ),
				ARRAY_A
			);
			if ( ! $row ) {
				$row = array(
					'attachment_id' => $attachment_id,
					'file_path'     => $file ? $file : '',
					'file_hash'     => $file ? self::path_hash( $file ) : '',
				);
			}
			return self::clear_stale_usage_row( $row, 'attachment_missing_on_disk' );
		}

		$files_to_move = array( wp_normalize_path( $file ) );
		$meta          = wp_get_attachment_metadata( $attachment_id );
		if ( ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
			$dir = trailingslashit( dirname( $file ) );
			foreach ( $meta['sizes'] as $sz ) {
				if ( ! empty( $sz['file'] ) ) {
					$files_to_move[] = wp_normalize_path( $dir . $sz['file'] );
				}
			}
		}
		$scaled = preg_replace( '/(\.[^.]+)$/', '-scaled$1', wp_normalize_path( $file ) );
		if ( $scaled && is_file( $scaled ) ) {
			$files_to_move[] = $scaled;
		}

		return self::quarantine_paths( $files_to_move, $attachment_id, self::path_hash( $file ) );
	}

	/**
	 * Quarantine a disk orphan by file_hash.
	 *
	 * @param string $hash Path hash.
	 * @return array|WP_Error
	 */
	public static function quarantine_disk_file( $hash ) {
		global $wpdb;
		$table = $wpdb->prefix . 'ezm_media_usage';
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE file_hash = %s", $hash ), ARRAY_A );
		if ( ! $row || empty( $row['file_path'] ) ) {
			return new WP_Error( 'missing', __( 'Disk orphan record not found.', 'ezm-security-settings' ) );
		}
		$path = wp_normalize_path( $row['file_path'] );
		if ( ! is_file( $path ) ) {
			// Already gone (often a size sidecar moved with a sibling delete) — drop the stale row.
			return self::clear_stale_usage_row( $row, 'missing_on_disk' );
		}
		return self::quarantine_paths( self::sibling_media_files( $path ), 0, $hash );
	}

	/**
	 * Shared quarantine mover.
	 *
	 * @param array  $files_to_move Absolute paths.
	 * @param int    $attachment_id Attachment ID or 0.
	 * @param string $usage_hash    Usage row file_hash.
	 * @return array|WP_Error
	 */
	private static function quarantine_paths( $files_to_move, $attachment_id, $usage_hash ) {
		global $wpdb;

		$uploads = wp_upload_dir();
		$basedir = wp_normalize_path( trailingslashit( $uploads['basedir'] ) );
		$primary = null;

		foreach ( $files_to_move as $f ) {
			$f = wp_normalize_path( $f );
			if ( is_file( $f ) && 0 === strpos( $f, $basedir ) ) {
				$primary = $f;
				break;
			}
		}
		if ( ! $primary ) {
			return new WP_Error( 'outside', __( 'File is outside uploads or missing.', 'ezm-security-settings' ) );
		}

		$rel  = ltrim( substr( $primary, strlen( $basedir ) ), '/' );
		$dest = wp_normalize_path( trailingslashit( self::quarantine_base() ) . $rel );
		wp_mkdir_p( dirname( $dest ) );

		$moved = array();
		foreach ( array_unique( $files_to_move ) as $src ) {
			$src = wp_normalize_path( $src );
			if ( ! is_file( $src ) || 0 !== strpos( $src, $basedir ) ) {
				continue;
			}
			$src_rel  = ltrim( substr( $src, strlen( $basedir ) ), '/' );
			$src_dest = wp_normalize_path( trailingslashit( self::quarantine_base() ) . $src_rel );
			wp_mkdir_p( dirname( $src_dest ) );
			if ( ! @rename( $src, $src_dest ) ) {
				return new WP_Error( 'move_failed', sprintf( __( 'Could not move %s', 'ezm-security-settings' ), $src ) );
			}
			$moved[] = array( 'from' => $src, 'to' => $src_dest );
		}

		$now   = current_time( 'mysql', true );
		$purge = gmdate( 'Y-m-d H:i:s', time() + ( 90 * DAY_IN_SECONDS ) );
		$qtable = $wpdb->prefix . 'ezm_media_quarantine';
		$wpdb->insert(
			$qtable,
			array(
				'attachment_id'      => absint( $attachment_id ),
				'original_path'      => $primary,
				'quarantine_path'    => $dest,
				'relative_path'      => $rel,
				'file_size'          => is_file( $dest ) ? (int) filesize( $dest ) : 0,
				'quarantined_at'     => $now,
				'scheduled_purge_at' => $purge,
				'status'             => 'pending',
				'meta'               => wp_json_encode( array( 'moved' => $moved, 'usage_hash' => $usage_hash ) ),
			)
		);

		$usage = $wpdb->prefix . 'ezm_media_usage';
		$hashes = array();
		if ( $usage_hash ) {
			$hashes[] = $usage_hash;
		}
		foreach ( $moved as $pair ) {
			if ( ! empty( $pair['from'] ) ) {
				$hashes[] = self::path_hash( $pair['from'] );
			}
		}
		$hashes = array_unique( array_filter( $hashes ) );
		foreach ( $hashes as $h ) {
			$wpdb->update(
				$usage,
				array(
					'status'     => 'pending_deletion',
					'updated_at' => $now,
				),
				array( 'file_hash' => $h )
			);
		}
		if ( empty( $hashes ) && $attachment_id > 0 ) {
			$wpdb->update(
				$usage,
				array(
					'status'     => 'pending_deletion',
					'updated_at' => $now,
				),
				array( 'attachment_id' => $attachment_id )
			);
		}

		EZM_DB::log_event(
			'media_quarantine',
			$attachment_id
				? sprintf( 'Attachment %d quarantined for deletion.', $attachment_id )
				: sprintf( 'Disk file quarantined: %s', $rel ),
			'info',
			array( 'attachment_id' => $attachment_id, 'relative_path' => $rel )
		);

		return array(
			'quarantine_id' => (int) $wpdb->insert_id,
			'purge_at'      => $purge,
		);
	}

	/**
	 * Restore a quarantined item.
	 *
	 * @param int    $quarantine_id Row ID.
	 * @param string $reason        manual|404.
	 * @param array  $extra         Extra note data.
	 * @return array|WP_Error
	 */
	public static function restore_quarantine( $quarantine_id, $reason = 'manual', $extra = array() ) {
		global $wpdb;
		$table = $wpdb->prefix . 'ezm_media_quarantine';
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d AND status = 'pending'", $quarantine_id ), ARRAY_A );
		if ( ! $row ) {
			return new WP_Error( 'missing', __( 'Quarantine record not found.', 'ezm-security-settings' ) );
		}

		$meta  = json_decode( $row['meta'], true );
		$moved = ( is_array( $meta ) && ! empty( $meta['moved'] ) ) ? $meta['moved'] : array(
			array( 'from' => $row['original_path'], 'to' => $row['quarantine_path'] ),
		);

		foreach ( $moved as $pair ) {
			$from = wp_normalize_path( $pair['to'] );
			$to   = wp_normalize_path( $pair['from'] );
			if ( ! is_file( $from ) ) {
				continue;
			}
			wp_mkdir_p( dirname( $to ) );
			if ( ! @rename( $from, $to ) ) {
				return new WP_Error( 'restore_failed', __( 'Could not restore file.', 'ezm-security-settings' ) );
			}
		}

		$wpdb->update(
			$table,
			array(
				'status'      => 'restored',
				'restored_at' => current_time( 'mysql', true ),
			),
			array( 'id' => $quarantine_id )
		);

		$note_bits = array( 'Restored via ' . $reason );
		if ( ! empty( $extra['accessed'] ) ) {
			$note_bits[] = 'accessed ' . $extra['accessed'];
		}
		if ( ! empty( $extra['referrer'] ) ) {
			$note_bits[] = 'referrer ' . $extra['referrer'];
		} else {
			$note_bits[] = 'referrer empty (likely email/external)';
		}
		$note = implode( '; ', $note_bits );

		$usage = $wpdb->prefix . 'ezm_media_usage';
		$usage_hash = is_array( $meta ) && ! empty( $meta['usage_hash'] ) ? $meta['usage_hash'] : self::path_hash( $row['original_path'] );
		$aid        = (int) $row['attachment_id'];

		if ( $aid > 0 ) {
			$existing_note = $wpdb->get_var(
				$wpdb->prepare( "SELECT ignore_note FROM {$usage} WHERE attachment_id = %d", $aid )
			);
			$wpdb->update(
				$usage,
				array(
					'status'      => 'ignored',
					'ignore_note' => trim( (string) $existing_note . "\n" . $note ),
					'updated_at'  => current_time( 'mysql', true ),
				),
				array( 'attachment_id' => $aid )
			);
		} else {
			$existing_note = $wpdb->get_var(
				$wpdb->prepare( "SELECT ignore_note FROM {$usage} WHERE file_hash = %s", $usage_hash )
			);
			$wpdb->update(
				$usage,
				array(
					'status'      => 'ignored',
					'ignore_note' => trim( (string) $existing_note . "\n" . $note ),
					'updated_at'  => current_time( 'mysql', true ),
				),
				array( 'file_hash' => $usage_hash )
			);
		}

		EZM_DB::log_event(
			'media_restore',
			sprintf( 'Media restored (%s): %s', $reason, $row['relative_path'] ),
			'404' === $reason ? 'warning' : 'info',
			array_merge( array( 'attachment_id' => $aid, 'reason' => $reason ), $extra )
		);

		return array( 'attachment_id' => $aid, 'relative_path' => $row['relative_path'] );
	}

	/**
	 * Front-end 404 → restore from pending_deletion if path matches.
	 */
	public static function maybe_404_restore() {
		if ( ! is_404() ) {
			return;
		}
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		$path = wp_normalize_path( (string) wp_parse_url( $uri, PHP_URL_PATH ) );
		if ( ! $path ) {
			return;
		}

		$uploads = wp_upload_dir();
		$baseurl = wp_normalize_path( (string) wp_parse_url( $uploads['baseurl'], PHP_URL_PATH ) );
		if ( ! $baseurl || 0 !== strpos( $path, $baseurl ) ) {
			return;
		}

		$rel = ltrim( substr( $path, strlen( $baseurl ) ), '/' );
		if ( '' === $rel || 0 === strpos( $rel, 'pending_deletion/' ) ) {
			return;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'ezm_media_quarantine';
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE status = 'pending' AND relative_path = %s LIMIT 1",
				$rel
			),
			ARRAY_A
		);
		if ( ! $row ) {
			return;
		}

		$referrer = isset( $_SERVER['HTTP_REFERER'] ) ? esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : '';
		$result   = self::restore_quarantine(
			(int) $row['id'],
			'404',
			array(
				'accessed' => current_time( 'mysql' ),
				'referrer' => $referrer,
			)
		);

		if ( ! is_wp_error( $result ) ) {
			$new = trailingslashit( $uploads['baseurl'] ) . $rel;
			wp_safe_redirect( $new, 302 );
			exit;
		}
	}

	/**
	 * Permanently delete due quarantine items.
	 *
	 * @param bool $force_due_only Admin force.
	 * @return int
	 */
	public static function purge_due_items( $force_due_only = false ) {
		if ( ! EZM_Settings::get( 'auto_purge_after_90', 1 ) && ! $force_due_only ) {
			return 0;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'ezm_media_quarantine';
		$now   = current_time( 'mysql', true );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE status = 'pending' AND scheduled_purge_at <= %s LIMIT 50",
				$now
			),
			ARRAY_A
		);

		$count = 0;
		foreach ( $rows as $row ) {
			$meta  = json_decode( $row['meta'], true );
			$moved = ( is_array( $meta ) && ! empty( $meta['moved'] ) ) ? $meta['moved'] : array(
				array( 'to' => $row['quarantine_path'] ),
			);
			foreach ( $moved as $pair ) {
				$p = wp_normalize_path( $pair['to'] );
				if ( is_file( $p ) ) {
					@unlink( $p );
				}
			}
			if ( ! empty( $row['attachment_id'] ) ) {
				wp_delete_attachment( (int) $row['attachment_id'], true );
			}
			$wpdb->update( $table, array( 'status' => 'purged' ), array( 'id' => (int) $row['id'] ) );
			$count++;
		}
		return $count;
	}

	/**
	 * Count pending quarantine rows.
	 *
	 * @param array $args Optional search.
	 * @return int
	 */
	public static function count_quarantine( $args = array() ) {
		global $wpdb;
		$table  = $wpdb->prefix . 'ezm_media_quarantine';
		$search = isset( $args['s'] ) ? sanitize_text_field( $args['s'] ) : '';
		$where  = "status = 'pending'";
		$params = array();
		if ( $search ) {
			$where   .= ' AND relative_path LIKE %s';
			$params[] = '%' . $wpdb->esc_like( $search ) . '%';
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = "SELECT COUNT(*) FROM {$table} WHERE {$where}";
		if ( $params ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			return (int) $wpdb->get_var( $wpdb->prepare( $sql, $params ) );
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return (int) $wpdb->get_var( $sql );
	}

	/**
	 * List pending quarantine rows.
	 *
	 * @param array $args s, per_page, offset.
	 * @return array
	 */
	public static function list_quarantine( $args = array() ) {
		global $wpdb;
		$table  = $wpdb->prefix . 'ezm_media_quarantine';
		$search = isset( $args['s'] ) ? sanitize_text_field( $args['s'] ) : '';
		$limit  = isset( $args['per_page'] ) ? max( 1, absint( $args['per_page'] ) ) : 50;
		$offset = isset( $args['offset'] ) ? absint( $args['offset'] ) : 0;

		$where  = "status = 'pending'";
		$params = array();
		if ( $search ) {
			$where   .= ' AND relative_path LIKE %s';
			$params[] = '%' . $wpdb->esc_like( $search ) . '%';
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql      = "SELECT * FROM {$table} WHERE {$where} ORDER BY quarantined_at DESC LIMIT %d OFFSET %d";
		$params[] = $limit;
		$params[] = $offset;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Public URL for a file under uploads (library or disk orphan).
	 *
	 * @param string $path Absolute path.
	 * @return string
	 */
	public static function uploads_url_for_path( $path ) {
		$path    = wp_normalize_path( $path );
		$uploads = wp_upload_dir();
		$basedir = trailingslashit( wp_normalize_path( $uploads['basedir'] ) );
		if ( 0 !== strpos( $path, $basedir ) ) {
			return '';
		}
		$rel = ltrim( substr( $path, strlen( $basedir ) ), '/' );
		$parts = array_map( 'rawurlencode', explode( '/', $rel ) );
		return trailingslashit( $uploads['baseurl'] ) . implode( '/', $parts );
	}

	public static function maybe_run_monthly_pass() {
		$last = get_option( 'ezm_media_last_full_pass', '' );
		$ym   = current_time( 'Y-m' );
		if ( $last === $ym ) {
			return;
		}
		if ( ! get_transient( self::SCAN_TRANSIENT ) ) {
			$ids = get_posts(
				array(
					'post_type'      => 'attachment',
					'post_status'    => 'inherit',
					'posts_per_page' => 50,
					'fields'         => 'ids',
					'orderby'        => 'ID',
					'order'          => 'ASC',
				)
			);
			foreach ( $ids as $id ) {
				self::evaluate_attachment( (int) $id );
			}
			$known = self::build_known_attachment_paths();
			$files = array_slice( self::collect_orphan_disk_paths( $known ), 0, 100 );
			foreach ( $files as $path ) {
				self::evaluate_disk_file( $path );
			}
		}
		update_option( 'ezm_media_last_full_pass', $ym, false );
	}

	private static function verify() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( null, 403 );
		}
		check_ajax_referer( 'ezm_admin', 'nonce' );
	}

	private static function public_job( $job ) {
		return array(
			'status'    => $job['status'],
			'phase'     => isset( $job['phase'] ) ? $job['phase'] : 'library',
			'processed' => (int) $job['processed'],
			'total'     => (int) $job['total'],
			'queued'    => isset( $job['queue'] ) ? count( $job['queue'] ) : 0,
		);
	}

	/**
	 * Whether path looks like a WP image size sidecar (-150x150 / -scaled).
	 *
	 * @param string $path Path.
	 * @return bool
	 */
	public static function is_size_sidecar( $path ) {
		$base = pathinfo( basename( (string) $path ), PATHINFO_FILENAME );
		return (bool) preg_match( '/-(scaled|\d+x\d+)$/', $base );
	}

	/**
	 * Bulk delete (quarantine) or import selected rows.
	 */
	public static function ajax_bulk() {
		self::verify();
		$bulk   = isset( $_POST['bulk_action'] ) ? sanitize_key( wp_unslash( $_POST['bulk_action'] ) ) : '';
		$items  = isset( $_POST['items'] ) ? wp_unslash( $_POST['items'] ) : array();
		if ( ! is_array( $items ) ) {
			$items = array();
		}

		if ( ! in_array( $bulk, array( 'delete', 'import' ), true ) ) {
			wp_send_json_error( array( 'message' => 'Invalid bulk action.' ) );
		}

		$ok    = 0;
		$fail  = 0;
		$errors = array();
		// Cap per request for timeouts.
		$chunk = array_slice( $items, 0, 25 );

		foreach ( $chunk as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$aid  = absint( $item['attachment_id'] ?? 0 );
			$hash = sanitize_text_field( $item['file_hash'] ?? '' );

			if ( 'delete' === $bulk ) {
				if ( $aid > 0 ) {
					$r = self::quarantine_attachment( $aid );
				} elseif ( $hash ) {
					$r = self::quarantine_disk_file( $hash );
				} else {
					$r = new WP_Error( 'missing', 'Missing id/hash' );
				}
			} else {
				// Import only disk orphans.
				$path = '';
				if ( $hash ) {
					global $wpdb;
					$table = $wpdb->prefix . 'ezm_media_usage';
					$path  = (string) $wpdb->get_var( $wpdb->prepare( "SELECT file_path FROM {$table} WHERE file_hash = %s", $hash ) );
				}
				if ( ! $path ) {
					$r = new WP_Error( 'missing', 'File not found in usage table.' );
				} else {
					$r = self::import_to_media_library( $path );
				}
			}

			if ( is_wp_error( $r ) ) {
				$fail++;
				if ( count( $errors ) < 5 ) {
					$errors[] = $r->get_error_message();
				}
			} else {
				$ok++;
			}
		}

		wp_send_json_success(
			array(
				'ok'           => $ok,
				'fail'         => $fail,
				'errors'       => $errors,
				'processed'    => count( $chunk ),
				'remaining'    => max( 0, count( $items ) - count( $chunk ) ),
				'next_items'   => array_slice( $items, count( $chunk ) ),
			)
		);
	}

	/**
	 * Delete (quarantine) all rows matching the current orphan filter, in chunks.
	 */
	public static function ajax_delete_all() {
		self::verify();

		$status = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : 'orphan_queue';
		$mime   = isset( $_POST['mime'] ) ? sanitize_text_field( wp_unslash( $_POST['mime'] ) ) : '';
		$search = isset( $_POST['s'] ) ? sanitize_text_field( wp_unslash( $_POST['s'] ) ) : '';

		$allowed = array( 'orphan_queue', 'unreferenced', 'disk_orphan' );
		if ( ! in_array( $status, $allowed, true ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Delete all only works for Orphan queue, Library unreferenced, or On disk filters.', 'ezm-security-settings' ),
				)
			);
		}

		$filter = array(
			'status'   => $status,
			'mime'     => $mime,
			's'        => $search,
			'per_page' => 20,
			'offset'   => 0,
		);

		$rows      = self::list_usage( $filter );
		$remaining = self::count_usage( $filter );
		$ok        = 0;
		$fail      = 0;
		$errors    = array();

		foreach ( $rows as $row ) {
			$aid  = (int) ( $row['attachment_id'] ?? 0 );
			$hash = (string) ( $row['file_hash'] ?? '' );
			if ( $aid > 0 ) {
				$r = self::quarantine_attachment( $aid );
			} elseif ( $hash ) {
				$r = self::quarantine_disk_file( $hash );
			} else {
				$r = new WP_Error( 'missing', 'Missing id/hash' );
			}
			if ( is_wp_error( $r ) ) {
				$fail++;
				if ( count( $errors ) < 5 ) {
					$errors[] = $r->get_error_message();
				}
				// Drop hopeless rows so the queue can finish.
				if ( in_array( $r->get_error_code(), array( 'missing', 'outside' ), true ) ) {
					self::clear_stale_usage_row( $row, 'delete_all_' . $r->get_error_code() );
				}
			} else {
				$ok++;
			}
		}

		$left = self::count_usage( $filter );

		wp_send_json_success(
			array(
				'ok'        => $ok,
				'fail'      => $fail,
				'errors'    => $errors,
				'remaining' => $left,
				'done'      => ( 0 === $left ),
				'total_was' => $remaining,
			)
		);
	}

	public static function ajax_import() {
		self::verify();
		$hash = isset( $_POST['file_hash'] ) ? sanitize_text_field( wp_unslash( $_POST['file_hash'] ) ) : '';
		global $wpdb;
		$table = $wpdb->prefix . 'ezm_media_usage';
		$path  = (string) $wpdb->get_var( $wpdb->prepare( "SELECT file_path FROM {$table} WHERE file_hash = %s", $hash ) );
		$result = self::import_to_media_library( $path );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		wp_send_json_success( $result );
	}

	/**
	 * Register an on-disk uploads file as a Media Library attachment (Add from Server style).
	 *
	 * @param string $abs_path Absolute path under uploads.
	 * @return array|WP_Error
	 */
	public static function import_to_media_library( $abs_path ) {
		$abs_path = wp_normalize_path( (string) $abs_path );
		if ( ! $abs_path || ! is_file( $abs_path ) ) {
			return new WP_Error( 'missing', __( 'File not found.', 'ezm-security-settings' ) );
		}

		$uploads = wp_upload_dir();
		$basedir = trailingslashit( wp_normalize_path( $uploads['basedir'] ) );
		if ( 0 !== strpos( $abs_path, $basedir ) ) {
			return new WP_Error( 'outside', __( 'File is outside uploads.', 'ezm-security-settings' ) );
		}

		// Do not import size sidecars as top-level attachments.
		if ( self::is_size_sidecar( $abs_path ) ) {
			return new WP_Error( 'sidecar', __( 'Skipped image size variant — import the original instead.', 'ezm-security-settings' ) );
		}

		$rel = ltrim( substr( $abs_path, strlen( $basedir ) ), '/' );

		// Already in library?
		global $wpdb;
		$existing_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value = %s LIMIT 1",
				$rel
			)
		);
		if ( $existing_id ) {
			self::mark_usage_imported( $abs_path, (int) $existing_id );
			return array( 'attachment_id' => (int) $existing_id, 'existing' => true );
		}

		$filetype = wp_check_filetype( basename( $abs_path ), null );
		if ( empty( $filetype['type'] ) ) {
			return new WP_Error( 'mime', __( 'Unrecognized file type.', 'ezm-security-settings' ) );
		}

		$title = preg_replace( '/\.[^.]+$/', '', basename( $abs_path ) );
		$title = str_replace( array( '-', '_' ), ' ', $title );

		$attachment = array(
			'post_mime_type' => $filetype['type'],
			'post_title'     => sanitize_text_field( $title ),
			'post_content'   => '',
			'post_status'    => 'inherit',
		);

		// wp_insert_attachment with absolute path sets _wp_attached_file correctly without moving the file.
		$attach_id = wp_insert_attachment( $attachment, $abs_path );
		if ( is_wp_error( $attach_id ) || ! $attach_id ) {
			return is_wp_error( $attach_id ) ? $attach_id : new WP_Error( 'insert', __( 'Could not create attachment.', 'ezm-security-settings' ) );
		}

		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';

		$metadata = self::build_metadata_preferring_existing_sizes( $abs_path );
		if ( empty( $metadata ) || empty( $metadata['width'] ) ) {
			$metadata = wp_generate_attachment_metadata( $attach_id, $abs_path );
		}
		wp_update_attachment_metadata( $attach_id, $metadata );

		self::mark_usage_imported( $abs_path, (int) $attach_id );

		EZM_DB::log_event(
			'media_imported',
			sprintf( 'Imported disk file to media library: %s (ID %d)', $rel, $attach_id ),
			'info',
			array( 'attachment_id' => (int) $attach_id, 'path' => $rel )
		);

		return array( 'attachment_id' => (int) $attach_id, 'existing' => false );
	}

	/**
	 * Build attachment metadata using existing -WxH / -scaled siblings when present.
	 *
	 * @param string $abs_path Absolute original path.
	 * @return array
	 */
	public static function build_metadata_preferring_existing_sizes( $abs_path ) {
		$abs_path = wp_normalize_path( $abs_path );
		$size     = @getimagesize( $abs_path );
		if ( ! $size ) {
			return array();
		}

		$uploads = wp_upload_dir();
		$basedir = trailingslashit( wp_normalize_path( $uploads['basedir'] ) );
		$rel     = ltrim( substr( $abs_path, strlen( $basedir ) ), '/' );

		$meta = array(
			'width'  => (int) $size[0],
			'height' => (int) $size[1],
			'file'   => $rel,
			'sizes'  => array(),
		);

		$dir  = trailingslashit( dirname( $abs_path ) );
		$stem = pathinfo( $abs_path, PATHINFO_FILENAME );
		$ext  = pathinfo( $abs_path, PATHINFO_EXTENSION );

		$scaled = $dir . $stem . '-scaled.' . $ext;
		if ( is_file( $scaled ) ) {
			$ss = @getimagesize( $scaled );
			if ( $ss ) {
				$meta['sizes']['scaled'] = array(
					'file'      => basename( $scaled ),
					'width'     => (int) $ss[0],
					'height'    => (int) $ss[1],
					'mime-type' => $ss['mime'] ?? '',
				);
			}
		}

		$registered = wp_get_registered_image_subsizes();
		$globs      = glob( $dir . $stem . '-*x*.' . $ext );
		if ( ! is_array( $globs ) ) {
			$globs = array();
		}

		foreach ( $globs as $g ) {
			$g = wp_normalize_path( $g );
			if ( ! preg_match( '/-(\d+)x(\d+)\.' . preg_quote( $ext, '/' ) . '$/', $g, $m ) ) {
				continue;
			}
			$w = (int) $m[1];
			$h = (int) $m[2];
			$name = null;
			foreach ( $registered as $size_name => $def ) {
				if ( (int) $def['width'] === $w && ( (int) $def['height'] === $h || empty( $def['height'] ) || empty( $def['crop'] ) ) ) {
					$name = $size_name;
					break;
				}
			}
			if ( ! $name ) {
				$name = 'size-' . $w . 'x' . $h;
			}
			$gs = @getimagesize( $g );
			$meta['sizes'][ $name ] = array(
				'file'      => basename( $g ),
				'width'     => $gs ? (int) $gs[0] : $w,
				'height'    => $gs ? (int) $gs[1] : $h,
				'mime-type' => $gs['mime'] ?? '',
			);
		}

		return $meta;
	}

	/**
	 * After import, drop disk_orphan row / attach library id.
	 *
	 * @param string $abs_path Path.
	 * @param int    $attach_id Attachment ID.
	 */
	private static function mark_usage_imported( $abs_path, $attach_id ) {
		global $wpdb;
		$table = $wpdb->prefix . 'ezm_media_usage';
		$hash  = self::path_hash( $abs_path );

		// Remove sidecar disk_orphan rows for this stem.
		foreach ( self::sibling_media_files( $abs_path ) as $sib ) {
			$sh = self::path_hash( $sib );
			if ( $sh === $hash ) {
				continue;
			}
			$wpdb->delete( $table, array( 'file_hash' => $sh, 'attachment_id' => 0 ) );
		}

		$existing = $wpdb->get_row( $wpdb->prepare( "SELECT id FROM {$table} WHERE file_hash = %s", $hash ), ARRAY_A );
		$row      = array(
			'attachment_id'   => (int) $attach_id,
			'file_hash'       => $hash,
			'status'          => 'referenced',
			'mime_type'       => (string) get_post_mime_type( $attach_id ),
			'file_path'       => wp_normalize_path( $abs_path ),
			'file_size'       => is_file( $abs_path ) ? (int) filesize( $abs_path ) : 0,
			'used_on'         => wp_json_encode( array( array( 'id' => 0, 'how' => 'imported_to_library' ) ) ),
			'last_checked_at' => current_time( 'mysql', true ),
			'updated_at'      => current_time( 'mysql', true ),
		);
		if ( $existing ) {
			$wpdb->update( $table, $row, array( 'id' => (int) $existing['id'] ) );
		} else {
			$wpdb->insert( $table, $row );
		}
	}
}
