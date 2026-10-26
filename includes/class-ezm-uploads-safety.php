<?php
/**
 * Unsafe / unexpected files under uploads.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EZM_Uploads_Safety {

	const OPTION_SAFE  = 'ezm_uploads_marked_safe';
	const OPTION_FOUND = 'ezm_uploads_unsafe_found';

	/**
	 * Extensions treated as unsafe in uploads (unless marked safe).
	 *
	 * @return array
	 */
	public static function unsafe_extensions() {
		return array(
			'php', 'phtml', 'phar', 'php3', 'php4', 'php5', 'php7', 'php8',
			'cgi', 'pl', 'py', 'rb', 'sh', 'bash',
			'exe', 'dll', 'bat', 'cmd', 'com', 'scr', 'msi', 'vbs', 'ps1',
			'asp', 'aspx', 'jsp', 'jspx',
			'zip', 'rar', '7z', 'tar', 'gz', 'tgz', 'bz2',
			'js', // executable web payloads; mark safe if intentional
		);
	}

	public static function init() {
		add_action( 'wp_ajax_ezm_unsafe_scan', array( __CLASS__, 'ajax_scan' ) );
		add_action( 'wp_ajax_ezm_unsafe_mark_safe', array( __CLASS__, 'ajax_mark_safe' ) );
		add_action( 'wp_ajax_ezm_unsafe_unmark_safe', array( __CLASS__, 'ajax_unmark_safe' ) );
	}

	/**
	 * @return array Path-hash => true
	 */
	public static function marked_safe() {
		$list = get_option( self::OPTION_SAFE, array() );
		return is_array( $list ) ? $list : array();
	}

	/**
	 * @param string $path Absolute path.
	 */
	public static function mark_safe( $path ) {
		$path = wp_normalize_path( $path );
		$hash = md5( $path );
		$list = self::marked_safe();
		$list[ $hash ] = array(
			'path'      => $path,
			'marked_at' => current_time( 'mysql' ),
		);
		update_option( self::OPTION_SAFE, $list, false );
	}

	/**
	 * @param string $hash Path md5.
	 */
	public static function unmark_safe( $hash ) {
		$list = self::marked_safe();
		unset( $list[ $hash ] );
		update_option( self::OPTION_SAFE, $list, false );
	}

	/**
	 * Last scan snapshot (all unsafe found, including marked safe).
	 *
	 * @return array
	 */
	public static function last_findings() {
		$data = get_option( self::OPTION_FOUND, array() );
		return is_array( $data ) ? $data : array();
	}

	/**
	 * Unsafe files not marked safe.
	 *
	 * @return array
	 */
	public static function unmarked_findings() {
		$safe = self::marked_safe();
		$data = self::last_findings();
		$items = isset( $data['items'] ) && is_array( $data['items'] ) ? $data['items'] : array();
		$out   = array();
		foreach ( $items as $row ) {
			$hash = isset( $row['hash'] ) ? $row['hash'] : md5( $row['path'] );
			if ( isset( $safe[ $hash ] ) ) {
				continue;
			}
			$row['hash'] = $hash;
			$out[]       = $row;
		}
		return $out;
	}

	/**
	 * Scan uploads for unsafe extensions.
	 *
	 * @return array Findings.
	 */
	public static function scan() {
		$uploads = wp_upload_dir();
		$base    = wp_normalize_path( $uploads['basedir'] );
		$found   = array();
		if ( ! is_dir( $base ) ) {
			update_option( self::OPTION_FOUND, array(), false );
			return array();
		}

		$exts = self::unsafe_extensions();
		$skip_dirs = array( 'pending_deletion' );
		$start = microtime( true );

		try {
			$it = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $base, FilesystemIterator::SKIP_DOTS ),
				RecursiveIteratorIterator::LEAVES_ONLY
			);
		} catch ( Exception $e ) {
			return array();
		}

		foreach ( $it as $file ) {
			if ( ( microtime( true ) - $start ) > 15 ) {
				break;
			}
			/** @var SplFileInfo $file */
			if ( ! $file->isFile() ) {
				continue;
			}
			$path = wp_normalize_path( $file->getPathname() );
			$rel  = ltrim( substr( $path, strlen( trailingslashit( $base ) ) ), '/' );
			$parts = explode( '/', strtolower( $rel ) );
			if ( in_array( 'pending_deletion', $parts, true ) ) {
				continue;
			}

			$ext = strtolower( $file->getExtension() );
			if ( ! in_array( $ext, $exts, true ) ) {
				continue;
			}

			$found[] = array(
				'hash' => md5( $path ),
				'path' => $path,
				'rel'  => $rel,
				'ext'  => $ext,
				'size' => (int) $file->getSize(),
			);
			if ( count( $found ) >= 500 ) {
				break;
			}
		}

		update_option(
			self::OPTION_FOUND,
			array(
				'scanned_at' => current_time( 'mysql' ),
				'items'      => $found,
			),
			false
		);

		$unmarked = array();
		$safe     = self::marked_safe();
		foreach ( $found as $row ) {
			if ( ! isset( $safe[ $row['hash'] ] ) ) {
				$unmarked[] = $row;
			}
		}
		if ( $unmarked ) {
			EZM_DB::log_event(
				'unsafe_uploads',
				sprintf( 'Found %d unsafe file(s) in uploads (not marked safe).', count( $unmarked ) ),
				'critical',
				array(
					'count'  => count( $unmarked ),
					'sample' => array_slice( wp_list_pluck( $unmarked, 'rel' ), 0, 15 ),
				)
			);
		}

		return $found;
	}

	/**
	 * Items list for UI.
	 *
	 * @return array
	 */
	public static function list_for_ui() {
		$safe  = self::marked_safe();
		$data  = self::last_findings();
		$items = isset( $data['items'] ) && is_array( $data['items'] ) ? $data['items'] : array();
		$out   = array();
		foreach ( $items as $row ) {
			$hash = $row['hash'] ?? md5( $row['path'] );
			$row['hash']       = $hash;
			$row['marked_safe'] = isset( $safe[ $hash ] );
			$out[] = $row;
		}
		return $out;
	}

	public static function ajax_scan() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( null, 403 );
		}
		check_ajax_referer( 'ezm_admin', 'nonce' );
		$found = self::scan();
		wp_send_json_success(
			array(
				'count'    => count( $found ),
				'unmarked' => count( self::unmarked_findings() ),
			)
		);
	}

	public static function ajax_mark_safe() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( null, 403 );
		}
		check_ajax_referer( 'ezm_admin', 'nonce' );
		$path = isset( $_POST['path'] ) ? wp_normalize_path( sanitize_text_field( wp_unslash( $_POST['path'] ) ) ) : '';
		if ( ! $path ) {
			wp_send_json_error( array( 'message' => 'Missing path' ) );
		}
		self::mark_safe( $path );
		wp_send_json_success();
	}

	public static function ajax_unmark_safe() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( null, 403 );
		}
		check_ajax_referer( 'ezm_admin', 'nonce' );
		$hash = isset( $_POST['hash'] ) ? sanitize_text_field( wp_unslash( $_POST['hash'] ) ) : '';
		self::unmark_safe( $hash );
		wp_send_json_success();
	}
}
