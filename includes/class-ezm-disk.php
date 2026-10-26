<?php
/**
 * Disk Space: chunked tree scan, history, daily sampler.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EZM_Disk {

	const TREE_OPTION     = 'ezm_disk_tree';
	const HISTORY_OPTION  = 'ezm_disk_history';
	const JOB_TRANSIENT   = 'ezm_disk_scan_job';
	const SAMPLE_OPTION   = 'ezm_disk_daily_samples';

	public static function init() {
		add_action( 'wp_ajax_ezm_disk_scan_start', array( __CLASS__, 'ajax_scan_start' ) );
		add_action( 'wp_ajax_ezm_disk_scan_tick', array( __CLASS__, 'ajax_scan_tick' ) );
		add_action( 'wp_ajax_ezm_disk_scan_status', array( __CLASS__, 'ajax_scan_status' ) );
		add_action( 'ezm_disk_scan_continue', array( __CLASS__, 'cron_continue' ) );
	}

	/**
	 * Human-readable size.
	 *
	 * @param int $bytes Bytes.
	 * @return string
	 */
	public static function format_bytes( $bytes ) {
		$bytes = max( 0, (float) $bytes );
		$units = array( 'B', 'KB', 'MB', 'GB', 'TB' );
		$i     = 0;
		while ( $bytes >= 1024 && $i < count( $units ) - 1 ) {
			$bytes /= 1024;
			$i++;
		}
		return round( $bytes, $i > 0 ? 2 : 0 ) . ' ' . $units[ $i ];
	}

	/**
	 * Paths to skip (basename match).
	 *
	 * @return array
	 */
	public static function skip_dirs() {
		return EZM_Settings::parse_path_list( EZM_Settings::get( 'disk_skip_dirs', '' ) );
	}

	/**
	 * Get finished tree payload.
	 *
	 * @return array|null
	 */
	public static function get_tree() {
		$data = get_option( self::TREE_OPTION, null );
		return is_array( $data ) ? $data : null;
	}

	/**
	 * History points for chart (last 90 days).
	 *
	 * @return array
	 */
	public static function get_history() {
		$h = get_option( self::HISTORY_OPTION, array() );
		return is_array( $h ) ? $h : array();
	}

	/**
	 * Daily samples for email (last 7+).
	 *
	 * @return array
	 */
	public static function get_daily_samples() {
		$s = get_option( self::SAMPLE_OPTION, array() );
		return is_array( $s ) ? $s : array();
	}

	/**
	 * Compare recent disk totals against the disk_delta_pct setting.
	 *
	 * Prefers ~7-day lookback from daily samples; falls back to 90-day history.
	 *
	 * @return array|null {pct, old_bytes, new_bytes, old_date, new_date, threshold} or null if not enough data / under threshold.
	 */
	public static function significant_disk_delta() {
		$threshold = max( 1, (int) EZM_Settings::get( 'disk_delta_pct', 10 ) );

		$points = array();
		foreach ( self::get_daily_samples() as $row ) {
			if ( empty( $row['date'] ) || ! isset( $row['total_bytes'] ) ) {
				continue;
			}
			$points[ $row['date'] ] = array(
				'date'        => $row['date'],
				'total_bytes' => (int) $row['total_bytes'],
			);
		}
		if ( count( $points ) < 2 ) {
			foreach ( self::get_history() as $row ) {
				if ( empty( $row['date'] ) || ! isset( $row['total_bytes'] ) ) {
					continue;
				}
				$points[ $row['date'] ] = array(
					'date'        => $row['date'],
					'total_bytes' => (int) $row['total_bytes'],
				);
			}
		}
		if ( count( $points ) < 2 ) {
			return null;
		}

		ksort( $points );
		$dates  = array_keys( $points );
		$latest = $points[ end( $dates ) ];

		$target = gmdate( 'Y-m-d', strtotime( $latest['date'] . ' -7 days' ) );
		$base   = null;
		foreach ( $points as $date => $row ) {
			if ( $date <= $target ) {
				$base = $row;
			}
		}
		if ( ! $base ) {
			// Not enough history for a week — compare to the previous sample.
			$prev_key = $dates[ count( $dates ) - 2 ];
			$base     = $points[ $prev_key ];
		}

		$old = (int) $base['total_bytes'];
		$new = (int) $latest['total_bytes'];
		if ( $old <= 0 ) {
			return null;
		}

		$pct = ( ( $new - $old ) / $old ) * 100;
		if ( abs( $pct ) < $threshold ) {
			return null;
		}

		return array(
			'pct'       => round( $pct, 1 ),
			'old_bytes' => $old,
			'new_bytes' => $new,
			'old_date'  => $base['date'],
			'new_date'  => $latest['date'],
			'threshold' => $threshold,
		);
	}

	/**
	 * Append total_bytes history point (cap ~90).
	 *
	 * @param int $total_bytes Total.
	 * @param int $file_count  Optional file count.
	 */
	public static function append_history( $total_bytes, $file_count = 0 ) {
		$history   = self::get_history();
		$date      = current_time( 'Y-m-d' );
		$history[] = array(
			'date'        => $date,
			'total_bytes' => (int) $total_bytes,
			'file_count'  => (int) $file_count,
		);
		// Keep last 90 unique-ish days (allow one per day overwrite).
		$by_date = array();
		foreach ( $history as $row ) {
			$by_date[ $row['date'] ] = $row;
		}
		ksort( $by_date );
		$by_date = array_slice( $by_date, -90, null, true );
		update_option( self::HISTORY_OPTION, array_values( $by_date ), false );
	}

	/**
	 * Lightweight daily sample without full tree.
	 */
	public static function daily_size_sample() {
		$root = wp_normalize_path( ABSPATH );
		$totals = self::quick_totals( $root );

		$samples = self::get_daily_samples();
		$date    = current_time( 'Y-m-d' );
		$uploads = wp_upload_dir();
		$upload_path = ! empty( $uploads['basedir'] ) ? wp_normalize_path( $uploads['basedir'] ) : '';
		$upload_tot  = $upload_path && is_dir( $upload_path ) ? self::quick_totals( $upload_path ) : array( 'bytes' => 0, 'files' => 0 );

		$samples[ $date ] = array(
			'date'         => $date,
			'total_bytes'  => $totals['bytes'],
			'uploads'      => $upload_tot['bytes'],
			'logs_tracked' => EZM_Logs::tracked_total_bytes(),
			'file_count'   => $totals['files'],
		);

		// Cap ~14 days for email window.
		ksort( $samples );
		$samples = array_slice( $samples, -14, null, true );
		update_option( self::SAMPLE_OPTION, $samples, false );

		self::append_history( $totals['bytes'], $totals['files'] );
	}

	/**
	 * Quick recursive size with time/depth budget (sampler).
	 *
	 * @param string $path Root path.
	 * @return array{bytes:int,files:int}
	 */
	public static function quick_totals( $path ) {
		$bytes = 0;
		$files = 0;
		$skip  = self::skip_dirs();
		$start = microtime( true );
		$max_s = 8.0;

		$iterator = null;
		try {
			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS ),
				RecursiveIteratorIterator::SELF_FIRST
			);
		} catch ( Exception $e ) {
			return array( 'bytes' => 0, 'files' => 0 );
		}

		foreach ( $iterator as $file ) {
			if ( ( microtime( true ) - $start ) > $max_s ) {
				break;
			}
			/** @var SplFileInfo $file */
			$name = $file->getFilename();
			if ( $file->isDir() && in_array( $name, $skip, true ) ) {
				$iterator->next();
				continue;
			}
			if ( $file->isFile() ) {
				$bytes += (int) $file->getSize();
				$files++;
			}
		}

		return array( 'bytes' => $bytes, 'files' => $files );
	}

	/**
	 * Start a new scan job.
	 */
	public static function ajax_scan_start() {
		self::verify_ajax();
		$job = self::create_job();
		set_transient( self::JOB_TRANSIENT, $job, HOUR_IN_SECONDS );
		if ( ! wp_next_scheduled( 'ezm_disk_scan_continue' ) ) {
			wp_schedule_single_event( time() + 30, 'ezm_disk_scan_continue' );
		}
		wp_send_json_success( array( 'job' => self::public_job( $job ) ) );
	}

	public static function ajax_scan_tick() {
		self::verify_ajax();
		$job = get_transient( self::JOB_TRANSIENT );
		if ( ! is_array( $job ) ) {
			wp_send_json_error( array( 'message' => __( 'No active scan.', 'ezm-security-settings' ) ) );
		}
		$job = self::process_chunk( $job );
		if ( 'done' === $job['status'] ) {
			delete_transient( self::JOB_TRANSIENT );
		} else {
			set_transient( self::JOB_TRANSIENT, $job, HOUR_IN_SECONDS );
		}
		wp_send_json_success(
			array(
				'job'  => self::public_job( $job ),
				'tree' => ( 'done' === $job['status'] ) ? self::get_tree() : null,
			)
		);
	}

	public static function ajax_scan_status() {
		self::verify_ajax();
		$job = get_transient( self::JOB_TRANSIENT );
		wp_send_json_success(
			array(
				'job'  => is_array( $job ) ? self::public_job( $job ) : null,
				'tree' => self::get_tree(),
			)
		);
	}

	public static function cron_continue() {
		$job = get_transient( self::JOB_TRANSIENT );
		if ( ! is_array( $job ) || 'done' === $job['status'] ) {
			return;
		}
		$job = self::process_chunk( $job );
		if ( 'done' === $job['status'] ) {
			delete_transient( self::JOB_TRANSIENT );
			return;
		}
		set_transient( self::JOB_TRANSIENT, $job, HOUR_IN_SECONDS );
		wp_schedule_single_event( time() + 15, 'ezm_disk_scan_continue' );
	}

	private static function verify_ajax() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'forbidden' ), 403 );
		}
		check_ajax_referer( 'ezm_admin', 'nonce' );
	}

	/**
	 * @return array
	 */
	private static function create_job() {
		$root = wp_normalize_path( untrailingslashit( ABSPATH ) );
		return array(
			'status'     => 'running',
			'root'       => $root,
			'queue'      => array( $root ),
			'nodes'      => array(),
			'processed'  => 0,
			'started_at' => time(),
			'updated_at' => time(),
		);
	}

	/**
	 * Process a chunk of directories from the queue.
	 *
	 * @param array $job Job state.
	 * @return array
	 */
	public static function process_chunk( $job ) {
		$skip      = self::skip_dirs();
		$budget    = 25; // dirs per tick
		$time_end  = microtime( true ) + 2.5;
		$processed = 0;

		while ( $processed < $budget && ! empty( $job['queue'] ) && microtime( true ) < $time_end ) {
			$dir = array_shift( $job['queue'] );
			$dir = wp_normalize_path( $dir );
			if ( ! is_dir( $dir ) ) {
				continue;
			}

			$base = basename( $dir );
			if ( in_array( $base, $skip, true ) && $dir !== $job['root'] ) {
				continue;
			}

			$file_bytes = 0;
			$file_count = 0;
			$children   = array();

			$entries = @scandir( $dir );
			if ( false === $entries ) {
				continue;
			}

			foreach ( $entries as $entry ) {
				if ( '.' === $entry || '..' === $entry ) {
					continue;
				}
				$path = wp_normalize_path( $dir . '/' . $entry );
				if ( is_link( $path ) ) {
					continue;
				}
				if ( is_dir( $path ) ) {
					if ( in_array( $entry, $skip, true ) ) {
						continue;
					}
					$children[]   = $path;
					$job['queue'][] = $path;
				} elseif ( is_file( $path ) ) {
					$file_bytes += (int) @filesize( $path );
					$file_count++;
				}
			}

			$rel = self::relative_path( $job['root'], $dir );
			$job['nodes'][ $rel ] = array(
				'path'       => $rel,
				'name'       => ( '' === $rel ? basename( $job['root'] ) : basename( $dir ) ),
				'file_bytes' => $file_bytes,
				'file_count' => $file_count,
				'children'   => array_map(
					function ( $c ) use ( $job ) {
						return self::relative_path( $job['root'], $c );
					},
					$children
				),
				'total'      => 0,
			);

			$processed++;
			$job['processed']++;
		}

		$job['updated_at'] = time();

		if ( empty( $job['queue'] ) ) {
			$job = self::finalize_job( $job );
		}

		return $job;
	}

	/**
	 * Roll up totals and persist tree.
	 *
	 * @param array $job Job.
	 * @return array
	 */
	private static function finalize_job( $job ) {
		$nodes = $job['nodes'];

		// Bottom-up total: iterate until stable or by depth.
		$changed = true;
		$guard   = 0;
		while ( $changed && $guard < 50 ) {
			$changed = false;
			$guard++;
			foreach ( $nodes as $rel => $node ) {
				$sum = (int) $node['file_bytes'];
				foreach ( $node['children'] as $child ) {
					if ( isset( $nodes[ $child ] ) ) {
						$sum += (int) $nodes[ $child ]['total'];
					}
				}
				if ( (int) $node['total'] !== $sum ) {
					$nodes[ $rel ]['total'] = $sum;
					$changed = true;
				}
			}
		}

		// Sort children largest-first.
		foreach ( $nodes as $rel => $node ) {
			usort(
				$nodes[ $rel ]['children'],
				function ( $a, $b ) use ( $nodes ) {
					$ta = isset( $nodes[ $a ] ) ? $nodes[ $a ]['total'] : 0;
					$tb = isset( $nodes[ $b ] ) ? $nodes[ $b ]['total'] : 0;
					return $tb <=> $ta;
				}
			);
		}

		$root_rel = '';
		$total    = isset( $nodes[ $root_rel ] ) ? (int) $nodes[ $root_rel ]['total'] : 0;
		$files    = 0;
		foreach ( $nodes as $n ) {
			$files += (int) $n['file_count'];
		}

		$tree = array(
			'scanned_at' => current_time( 'mysql' ),
			'root'       => $job['root'],
			'total'      => $total,
			'file_count' => $files,
			'nodes'      => $nodes,
		);

		update_option( self::TREE_OPTION, $tree, false );
		self::append_history( $total, $files );

		$job['status'] = 'done';
		$job['nodes']  = array();
		$job['total']  = $total;

		return $job;
	}

	/**
	 * @param string $root Root.
	 * @param string $path Absolute path.
	 * @return string
	 */
	private static function relative_path( $root, $path ) {
		$root = trailingslashit( wp_normalize_path( $root ) );
		$path = wp_normalize_path( $path );
		if ( 0 === strpos( $path, $root ) ) {
			return ltrim( substr( $path, strlen( $root ) ), '/' );
		}
		if ( $path === rtrim( $root, '/' ) ) {
			return '';
		}
		return ltrim( $path, '/' );
	}

	/**
	 * @param array $job Job.
	 * @return array
	 */
	private static function public_job( $job ) {
		return array(
			'status'    => $job['status'],
			'processed' => (int) $job['processed'],
			'queued'    => isset( $job['queue'] ) ? count( $job['queue'] ) : 0,
			'total'     => isset( $job['total'] ) ? (int) $job['total'] : null,
		);
	}

	/**
	 * Highlight known WP bloat paths.
	 *
	 * @param string $rel Relative path.
	 * @return bool
	 */
	public static function is_bloat_path( $rel ) {
		$rel = strtolower( $rel );
		$hints = array(
			'wp-content/uploads',
			'wp-content/cache',
			'wp-content/upgrade',
			'wp-content/backup',
			'wp-content/backups',
			'wp-content/ai1wm-backups',
			'wp-content/updraft',
			'wp-content/debug.log',
		);
		foreach ( $hints as $h ) {
			if ( 0 === strpos( $rel, $h ) || $rel === $h ) {
				return true;
			}
		}
		return false;
	}
}
