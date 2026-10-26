<?php
/**
 * Log discovery, browser, size tracking, error aggregation.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EZM_Logs {

	const AGG_OPTION = 'ezm_log_error_agg';

	public static function init() {
		add_action( 'wp_ajax_ezm_logs_refresh', array( __CLASS__, 'ajax_refresh' ) );
		add_action( 'admin_post_ezm_logs_download', array( __CLASS__, 'download_log' ) );
		add_action( 'admin_post_ezm_logs_delete', array( __CLASS__, 'delete_log' ) );
	}

	/**
	 * Total bytes of tracked log files.
	 *
	 * @return int
	 */
	public static function tracked_total_bytes() {
		global $wpdb;
		$table = $wpdb->prefix . 'ezm_log_files';
		$sum   = $wpdb->get_var( "SELECT SUM(file_size) FROM {$table}" );
		return (int) $sum;
	}

	/**
	 * Paths that should be excluded from discovery.
	 *
	 * @return array
	 */
	public static function exclude_folders() {
		$excludes = EZM_Settings::parse_path_list( EZM_Settings::get( 'log_exclude_folders', '' ) );
		// Always skip WooCommerce logs from our browser.
		$excludes[] = 'wp-content/uploads/wc-logs';
		$excludes[] = 'uploads/wc-logs';
		return array_unique( $excludes );
	}

	/**
	 * Whether absolute path is under an exclude folder.
	 *
	 * @param string $abs Absolute path.
	 * @return bool
	 */
	public static function is_excluded( $abs ) {
		$abs  = wp_normalize_path( $abs );
		$root = wp_normalize_path( ABSPATH );
		$rel  = ( 0 === strpos( $abs, $root ) ) ? ltrim( substr( $abs, strlen( $root ) ), '/' ) : $abs;
		$rel  = strtolower( $rel );

		foreach ( self::exclude_folders() as $ex ) {
			$ex = strtolower( ltrim( str_replace( '\\', '/', $ex ), '/' ) );
			if ( '' === $ex ) {
				continue;
			}
			if ( 0 === strpos( $rel, $ex ) || false !== strpos( $rel, '/' . $ex . '/' ) || false !== strpos( $rel, '/' . $ex ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Known upload/log folder aliases → candidate plugin directory names.
	 *
	 * @return array<string,string[]>
	 */
	public static function path_aliases() {
		return array(
			'wp-defender'       => array( 'defender-security', 'wp-defender', 'defender-pro' ),
			'wflogs'            => array( 'wordfence' ),
			'updraft'           => array( 'updraftplus' ),
			'ai1wm-backups'     => array( 'all-in-one-wp-migration' ),
			'backwpup'          => array( 'backwpup' ),
			'ithemes-security'  => array( 'better-wp-security', 'ithemes-security-pro' ),
			'gravity_forms'     => array( 'gravityforms' ),
			'gravityforms'      => array( 'gravityforms' ),
			'sucuri'            => array( 'sucuri-scanner' ),
			'ewww'              => array( 'ewww-image-optimizer' ),
			'smush-logs'        => array( 'wp-smushit', 'wp-smush-pro' ),
			'wc-logs'           => array( 'woocommerce' ),
			'wpforms'           => array( 'wpforms-lite', 'wpforms' ),
			'ninja-forms'       => array( 'ninja-forms' ),
		);
	}

	/**
	 * Installed plugins keyed by lowercase directory slug.
	 *
	 * @return array<string,array{slug:string,label:string}>
	 */
	public static function installed_plugin_map() {
		static $map = null;
		if ( null !== $map ) {
			return $map;
		}
		$map = array();
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		foreach ( get_plugins() as $file => $data ) {
			$slug = dirname( $file );
			if ( '.' === $slug || '\\' === $slug ) {
				$slug = basename( $file, '.php' );
			}
			$key = strtolower( $slug );
			$map[ $key ] = array(
				'slug'  => $slug,
				'label' => ! empty( $data['Name'] ) ? $data['Name'] : $slug,
			);
		}
		return $map;
	}

	/**
	 * Human labels for known log folder aliases when the plugin is not installed.
	 *
	 * @return array<string,string>
	 */
	public static function alias_labels() {
		return array(
			'wp-defender'      => 'WP Defender',
			'wflogs'           => 'Wordfence',
			'updraft'          => 'UpdraftPlus',
			'ai1wm-backups'    => 'All-in-One WP Migration',
			'backwpup'         => 'BackWPup',
			'ithemes-security' => 'iThemes Security',
			'gravity_forms'    => 'Gravity Forms',
			'gravityforms'     => 'Gravity Forms',
			'sucuri'           => 'Sucuri',
			'ewww'             => 'EWWW Image Optimizer',
			'smush-logs'       => 'Smush',
			'wc-logs'          => 'WooCommerce',
			'wpforms'          => 'WPForms',
			'ninja-forms'      => 'Ninja Forms',
		);
	}

	/**
	 * Resolve alias folder to plugin slug/label.
	 *
	 * @param string $folder Alias folder name (lowercase).
	 * @return array{slug:string,label:string}|null
	 */
	public static function resolve_alias( $folder ) {
		$folder  = strtolower( $folder );
		$aliases = self::path_aliases();
		if ( ! isset( $aliases[ $folder ] ) ) {
			return null;
		}
		$installed = self::installed_plugin_map();
		foreach ( $aliases[ $folder ] as $candidate ) {
			$ck = strtolower( $candidate );
			if ( isset( $installed[ $ck ] ) ) {
				return $installed[ $ck ];
			}
		}
		$labels = self::alias_labels();
		return array(
			'slug'  => $aliases[ $folder ][0],
			'label' => isset( $labels[ $folder ] ) ? $labels[ $folder ] : $folder,
		);
	}

	/**
	 * Map path to plugin / source attribution.
	 *
	 * @param string $abs Absolute path.
	 * @return array{slug:string,label:string}
	 */
	public static function source_from_path( $abs ) {
		$abs       = wp_normalize_path( (string) $abs );
		$abs_l     = strtolower( $abs );
		$plugins   = wp_normalize_path( WP_PLUGIN_DIR );
		$mu        = wp_normalize_path( WPMU_PLUGIN_DIR );
		$content   = wp_normalize_path( WP_CONTENT_DIR );
		$root      = untrailingslashit( wp_normalize_path( ABSPATH ) );
		$installed = self::installed_plugin_map();
		$aliases   = self::path_aliases();

		// Direct plugin directory.
		$plugins = untrailingslashit( $plugins );
		if ( $plugins && ( $abs === $plugins || 0 === strpos( $abs, $plugins . '/' ) ) ) {
			$rest = ltrim( substr( $abs, strlen( $plugins ) ), '/' );
			$slug = strtolower( strtok( $rest, '/' ) );
			if ( isset( $installed[ $slug ] ) ) {
				return $installed[ $slug ];
			}
			return array(
				'slug'  => $slug,
				'label' => $slug,
			);
		}

		$mu = untrailingslashit( $mu );
		if ( $mu && ( $abs === $mu || 0 === strpos( $abs, $mu . '/' ) ) ) {
			return array(
				'slug'  => 'mu-plugins',
				'label' => 'MU Plugins',
			);
		}

		// Alias folders anywhere in the path (e.g. .../uploads/wp-defender/file.log).
		// Check before the generic wp-content fallback.
		foreach ( array_keys( $aliases ) as $folder ) {
			$needle = '/' . $folder . '/';
			if ( false !== strpos( $abs_l, $needle ) || substr( $abs_l, - ( strlen( $folder ) + 1 ) ) === '/' . $folder ) {
				$resolved = self::resolve_alias( $folder );
				if ( $resolved ) {
					return $resolved;
				}
			}
		}

		$rel = ( 0 === strpos( $abs, $root . '/' ) || $abs === $root )
			? ltrim( substr( $abs, strlen( $root ) ), '/' )
			: $abs;
		$segments = preg_split( '#[/\\\\]+#', strtolower( $rel ), -1, PREG_SPLIT_NO_EMPTY );
		if ( ! is_array( $segments ) ) {
			$segments = array();
		}

		// Match any path segment to an installed plugin folder.
		foreach ( $segments as $seg ) {
			if ( isset( $installed[ $seg ] ) ) {
				return $installed[ $seg ];
			}
		}

		$base = strtolower( basename( $abs ) );
		if ( in_array( $base, array( 'debug.log', 'error_log' ), true ) || 0 === strpos( $base, 'php_errors' ) ) {
			return array(
				'slug'  => 'wordpress',
				'label' => 'WordPress',
			);
		}

		$content = untrailingslashit( $content );
		if ( $content && ( $abs === $content || 0 === strpos( $abs, $content . '/' ) ) ) {
			return array(
				'slug'  => 'wp-content',
				'label' => 'wp-content',
			);
		}

		return array(
			'slug'  => 'core',
			'label' => 'WordPress',
		);
	}

	/**
	 * Re-attribute a row when the stored label looks generic / stale.
	 *
	 * @param array $row DB row.
	 * @return array
	 */
	public static function heal_row_source( $row ) {
		if ( empty( $row['file_path'] ) ) {
			return $row;
		}

		$src     = self::source_from_path( $row['file_path'] );
		$old_slug  = isset( $row['plugin_slug'] ) ? (string) $row['plugin_slug'] : '';
		$old_label = isset( $row['source_label'] ) ? (string) $row['source_label'] : '';

		if ( $src['slug'] === $old_slug && $src['label'] === $old_label ) {
			return $row;
		}

		// Only overwrite when stored attribution is generic, or the new one is more specific.
		$generic = array( 'wp-content', 'core', 'wordpress', '' );
		if ( ! in_array( $old_slug, $generic, true ) && in_array( $src['slug'], $generic, true ) ) {
			return $row;
		}

		$row['plugin_slug']  = $src['slug'];
		$row['source_label'] = $src['label'];

		if ( ! empty( $row['id'] ) ) {
			global $wpdb;
			$table = $wpdb->prefix . 'ezm_log_files';
			$wpdb->update(
				$table,
				array(
					'plugin_slug'  => $src['slug'],
					'source_label' => $src['label'],
				),
				array( 'id' => (int) $row['id'] ),
				array( '%s', '%s' ),
				array( '%d' )
			);
		}

		return $row;
	}

	/**
	 * Discover log files and upsert into DB.
	 *
	 * @return int Number of log files found.
	 */
	public static function refresh_index() {
		global $wpdb;

		$table = $wpdb->prefix . 'ezm_log_files';
		$roots = array(
			wp_normalize_path( ABSPATH ),
			wp_normalize_path( WP_CONTENT_DIR ),
		);
		$found = array();
		$start = microtime( true );

		foreach ( array_unique( $roots ) as $root ) {
			if ( ! is_dir( $root ) ) {
				continue;
			}
			try {
				$it = new RecursiveIteratorIterator(
					new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ),
					RecursiveIteratorIterator::LEAVES_ONLY
				);
			} catch ( Exception $e ) {
				continue;
			}

			foreach ( $it as $file ) {
				if ( ( microtime( true ) - $start ) > 12 ) {
					break 2;
				}
				/** @var SplFileInfo $file */
				if ( ! $file->isFile() ) {
					continue;
				}
				$name  = $file->getFilename();
				$lower = strtolower( $name );
				$is_log = ( 'error_log' === $lower ) || ( substr( $lower, -4 ) === '.log' );
				if ( ! $is_log ) {
					continue;
				}
				$abs = wp_normalize_path( $file->getPathname() );
				if ( self::is_excluded( $abs ) ) {
					continue;
				}
				$found[ $abs ] = $file;
			}
		}

		$seen_hashes = array();
		foreach ( $found as $abs => $file ) {
			$hash     = md5( $abs );
			$src      = self::source_from_path( $abs );
			$size     = (int) $file->getSize();
			$mtime    = gmdate( 'Y-m-d H:i:s', $file->getMTime() );
			$ctime    = gmdate( 'Y-m-d H:i:s', $file->getCTime() );
			$existing = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE file_hash = %s", $hash ), ARRAY_A );

			$history = array();
			if ( $existing && ! empty( $existing['size_history'] ) ) {
				$history = json_decode( $existing['size_history'], true );
				if ( ! is_array( $history ) ) {
					$history = array();
				}
			}
			$today             = current_time( 'Y-m-d' );
			$history[ $today ] = $size;
			ksort( $history );
			$history = array_slice( $history, -30, null, true );

			$row = array(
				'file_path'    => $abs,
				'file_hash'    => $hash,
				'source_label' => $src['label'],
				'plugin_slug'  => $src['slug'],
				'file_size'    => $size,
				'mtime'        => $mtime,
				'ctime'        => $ctime,
				'size_history' => wp_json_encode( $history ),
				'last_seen_at' => current_time( 'mysql', true ),
			);

			if ( $existing ) {
				$wpdb->update( $table, $row, array( 'id' => (int) $existing['id'] ) );
			} else {
				$wpdb->insert( $table, $row );
			}
			$seen_hashes[] = $hash;
		}

		return count( $found );
	}

	/**
	 * List indexed logs for UI.
	 *
	 * @param array $args Query args.
	 * @return array
	 */
	public static function list_logs( $args = array() ) {
		global $wpdb;
		$table  = $wpdb->prefix . 'ezm_log_files';
		$search = isset( $args['s'] ) ? sanitize_text_field( $args['s'] ) : '';
		$source = isset( $args['source'] ) ? sanitize_text_field( $args['source'] ) : '';
		$limit  = isset( $args['per_page'] ) ? max( 1, absint( $args['per_page'] ) ) : 50;
		$offset = isset( $args['offset'] ) ? absint( $args['offset'] ) : 0;

		$allowed_orderby = array(
			'name'   => 'file_path',
			'plugin' => 'source_label',
			'ctime'  => 'ctime',
			'mtime'  => 'mtime',
			'size'   => 'file_size',
		);
		$orderby_key = isset( $args['orderby'] ) ? sanitize_key( $args['orderby'] ) : 'mtime';
		if ( ! isset( $allowed_orderby[ $orderby_key ] ) ) {
			$orderby_key = 'mtime';
		}
		$orderby = $allowed_orderby[ $orderby_key ];
		$order   = ( isset( $args['order'] ) && 'ASC' === strtoupper( $args['order'] ) ) ? 'ASC' : 'DESC';

		$where = '1=1';
		$params = array();
		if ( $search ) {
			$where   .= ' AND file_path LIKE %s';
			$params[] = '%' . $wpdb->esc_like( $search ) . '%';
		}
		if ( $source ) {
			$where   .= ' AND plugin_slug = %s';
			$params[] = $source;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = "SELECT * FROM {$table} WHERE {$where} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";
		$params[] = $limit;
		$params[] = $offset;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
		if ( ! is_array( $rows ) ) {
			return array();
		}

		foreach ( $rows as $i => $row ) {
			$rows[ $i ] = self::heal_row_source( $row );
		}

		return $rows;
	}

	/**
	 * Files exceeding critical size threshold.
	 *
	 * @return array
	 */
	public static function critical_sized_files() {
		global $wpdb;
		$mb    = max( 1, (int) EZM_Settings::get( 'critical_log_mb', 20 ) );
		$bytes = $mb * 1024 * 1024;
		$table = $wpdb->prefix . 'ezm_log_files';
		$rows  = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE file_size >= %d ORDER BY file_size DESC", $bytes ),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Sample/aggregate recent error-looking lines from large/recent logs.
	 */
	public static function aggregate_errors_sample() {
		$rows = self::list_logs( array( 'per_page' => 20 ) );
		$agg  = array();

		foreach ( $rows as $row ) {
			$path = $row['file_path'];
			if ( ! is_readable( $path ) ) {
				continue;
			}
			$lines = self::tail_file( $path, 200 );
			foreach ( $lines as $line ) {
				$line = trim( str_replace( array( "\r\n", "\r" ), "\n", $line ) );
				if ( '' === $line ) {
					continue;
				}
				if ( ! preg_match( '/error|warning|fatal|exception/i', $line ) ) {
					continue;
				}
				$key = self::normalize_error_message( $line );
				if ( ! isset( $agg[ $key ] ) ) {
					$agg[ $key ] = array(
						'message' => $key,
						'count'   => 0,
						'sample'  => $path,
					);
				}
				$agg[ $key ]['count']++;
			}
		}

		uasort(
			$agg,
			function ( $a, $b ) {
				return $b['count'] <=> $a['count'];
			}
		);
		$agg = array_slice( $agg, 0, 20 );

		update_option(
			self::AGG_OPTION,
			array(
				'updated_at' => current_time( 'mysql' ),
				'items'      => array_values( $agg ),
			),
			false
		);
	}

	/**
	 * @return array
	 */
	public static function get_error_aggregation() {
		$data = get_option( self::AGG_OPTION, array() );
		return is_array( $data ) ? $data : array();
	}

	/**
	 * @param string $line Raw line.
	 * @return string
	 */
	public static function normalize_error_message( $line ) {
		$line = preg_replace( '/\[[^\]]+\]/', '', $line );
		$line = preg_replace( '/\b(?:0x)?[0-9a-f]{8,}\b/i', '{id}', $line );
		$line = preg_replace( '/\b\d+\b/', '{n}', $line );
		$line = preg_replace( '/\s+/', ' ', $line );
		return trim( substr( $line, 0, 240 ) );
	}

	/**
	 * Tail last N lines of a file safely.
	 *
	 * @param string $path Path.
	 * @param int    $lines Lines.
	 * @return array
	 */
	public static function tail_file( $path, $lines = 200 ) {
		$path  = wp_normalize_path( $path );
		$lines = max( 1, min( 2000, (int) $lines ) );
		if ( ! is_readable( $path ) ) {
			return array();
		}

		$size = filesize( $path );
		if ( false === $size || 0 === $size ) {
			return array();
		}

		$fp = fopen( $path, 'rb' );
		if ( ! $fp ) {
			return array();
		}

		$buffer = '';
		$chunk  = 4096;
		$pos    = $size;
		$got    = 0;

		while ( $pos > 0 && $got <= $lines ) {
			$read = ( $pos >= $chunk ) ? $chunk : $pos;
			$pos -= $read;
			fseek( $fp, $pos );
			$buffer = fread( $fp, $read ) . $buffer;
			$got    = substr_count( $buffer, "\n" );
		}
		fclose( $fp );

		$buffer = str_replace( array( "\r\n", "\r" ), "\n", $buffer );
		$parts  = explode( "\n", $buffer );
		return array_slice( $parts, -$lines );
	}

	public static function ajax_refresh() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( null, 403 );
		}
		check_ajax_referer( 'ezm_admin', 'nonce' );
		$count = self::refresh_index();
		self::aggregate_errors_sample();
		wp_send_json_success( array( 'count' => $count ) );
	}

	/**
	 * Stream an indexed log file as a download.
	 */
	public static function download_log() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Forbidden.', 'ezm-security-settings' ), 403 );
		}

		$hash = isset( $_GET['hash'] ) ? sanitize_text_field( wp_unslash( $_GET['hash'] ) ) : '';
		if ( '' === $hash || ! preg_match( '/^[a-f0-9]{32}$/', $hash ) ) {
			wp_die( esc_html__( 'Invalid log.', 'ezm-security-settings' ), 400 );
		}
		check_admin_referer( 'ezm_logs_download_' . $hash );

		global $wpdb;
		$table = $wpdb->prefix . 'ezm_log_files';
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE file_hash = %s", $hash ), ARRAY_A );
		if ( ! $row || empty( $row['file_path'] ) ) {
			wp_die( esc_html__( 'Log not found.', 'ezm-security-settings' ), 404 );
		}

		$path = wp_normalize_path( $row['file_path'] );
		if ( ! is_readable( $path ) || ! is_file( $path ) ) {
			wp_die( esc_html__( 'Log file is not readable.', 'ezm-security-settings' ), 404 );
		}

		$filename = basename( $path );
		$size     = filesize( $path );

		nocache_headers();
		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $filename ) . '"' );
		if ( false !== $size ) {
			header( 'Content-Length: ' . (int) $size );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		readfile( $path );
		exit;
	}

	/**
	 * Delete an indexed log file from disk and remove its index row.
	 */
	public static function delete_log() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Forbidden.', 'ezm-security-settings' ), 403 );
		}

		$hash = isset( $_GET['hash'] ) ? sanitize_text_field( wp_unslash( $_GET['hash'] ) ) : '';
		if ( '' === $hash || ! preg_match( '/^[a-f0-9]{32}$/', $hash ) ) {
			wp_die( esc_html__( 'Invalid log.', 'ezm-security-settings' ), 400 );
		}
		check_admin_referer( 'ezm_logs_delete_' . $hash );

		$redirect = add_query_arg(
			array(
				'page' => 'ezm-logs',
				'tab'  => 'browse',
			),
			admin_url( 'admin.php' )
		);

		global $wpdb;
		$table = $wpdb->prefix . 'ezm_log_files';
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE file_hash = %s", $hash ), ARRAY_A );
		if ( ! $row || empty( $row['file_path'] ) ) {
			wp_safe_redirect( add_query_arg( 'ezm_log_msg', 'missing', $redirect ) );
			exit;
		}

		$path    = wp_normalize_path( $row['file_path'] );
		$deleted = true;
		if ( is_file( $path ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			$deleted = @unlink( $path );
		}

		if ( ! $deleted ) {
			wp_safe_redirect( add_query_arg( 'ezm_log_msg', 'unlink_failed', $redirect ) );
			exit;
		}

		$wpdb->delete( $table, array( 'file_hash' => $hash ), array( '%s' ) );
		wp_safe_redirect( add_query_arg( 'ezm_log_msg', 'deleted', $redirect ) );
		exit;
	}
}
