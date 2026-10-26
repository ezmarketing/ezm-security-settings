<?php
/**
 * Admin menus and page routing.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EZM_Admin {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'admin_init', array( __CLASS__, 'handle_settings_save' ) );
	}

	public static function register_menu() {
		add_menu_page(
			__( 'EZ Maintenance', 'ezm-security-settings' ),
			__( 'EZ Maintenance', 'ezm-security-settings' ),
			'manage_options',
			'ezm-maintenance',
			array( __CLASS__, 'render_settings' ),
			'dashicons-hammer',
			81
		);

		add_submenu_page(
			'ezm-maintenance',
			__( 'Settings', 'ezm-security-settings' ),
			__( 'Settings', 'ezm-security-settings' ),
			'manage_options',
			'ezm-maintenance',
			array( __CLASS__, 'render_settings' )
		);

		add_submenu_page(
			'ezm-maintenance',
			__( 'Disk Space', 'ezm-security-settings' ),
			__( 'Disk Space', 'ezm-security-settings' ),
			'manage_options',
			'ezm-disk-space',
			array( __CLASS__, 'render_disk' )
		);

		add_submenu_page(
			'ezm-maintenance',
			__( 'Media Usage', 'ezm-security-settings' ),
			__( 'Media Usage', 'ezm-security-settings' ),
			'manage_options',
			'ezm-media-usage',
			array( __CLASS__, 'render_media' )
		);

		add_submenu_page(
			'ezm-maintenance',
			__( 'Logs', 'ezm-security-settings' ),
			__( 'Logs', 'ezm-security-settings' ),
			'manage_options',
			'ezm-logs',
			array( __CLASS__, 'render_logs' )
		);
	}

	/**
	 * @param string $hook Current admin page hook.
	 */
	public static function enqueue( $hook ) {
		if ( ! preg_match( '/ezm-(maintenance|disk-space|media-usage|logs)/', (string) $hook ) ) {
			return;
		}

		wp_enqueue_style(
			'ezm-admin',
			EZM_SECURITY_URL . 'assets/admin.css',
			array(),
			EZM_SECURITY_VERSION
		);

		wp_enqueue_script(
			'ezm-admin',
			EZM_SECURITY_URL . 'assets/admin.js',
			array( 'jquery' ),
			EZM_SECURITY_VERSION,
			true
		);

		wp_localize_script(
			'ezm-admin',
			'ezmAdmin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'ezm_admin' ),
				'i18n'    => array(
					'scanning' => __( 'Scanning…', 'ezm-security-settings' ),
					'done'     => __( 'Scan complete.', 'ezm-security-settings' ),
					'error'    => __( 'Something went wrong.', 'ezm-security-settings' ),
				),
			)
		);
	}

	public static function handle_settings_save() {
		if ( empty( $_POST['ezm_settings_nonce'] ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ezm_settings_nonce'] ) ), 'ezm_save_settings' ) ) {
			return;
		}

		$input = isset( $_POST['ezm'] ) && is_array( $_POST['ezm'] ) ? wp_unslash( $_POST['ezm'] ) : array();
		$old   = EZM_Settings::all();
		$clean = EZM_Settings::sanitize_from_post( $input );
		EZM_Settings::update( $clean );
		EZM_Password_Age::on_settings_updated( $old, $clean );
		EZM_Plugin::reschedule_digest();

		if ( ! empty( $_POST['ezm_send_test_email'] ) ) {
			EZM_Mail::send_test_digest();
			add_settings_error( 'ezm_settings', 'ezm_test_sent', __( 'Test digest email queued/sent.', 'ezm-security-settings' ), 'updated' );
		} else {
			add_settings_error( 'ezm_settings', 'ezm_saved', __( 'Settings saved.', 'ezm-security-settings' ), 'updated' );
		}

		set_transient( 'ezm_settings_errors', get_settings_errors( 'ezm_settings' ), 30 );
		wp_safe_redirect( admin_url( 'admin.php?page=ezm-maintenance&updated=1' ) );
		exit;
	}

	public static function render_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$errors = get_transient( 'ezm_settings_errors' );
		if ( $errors ) {
			foreach ( $errors as $error ) {
				add_settings_error( $error['setting'], $error['code'], $error['message'], $error['type'] );
			}
			delete_transient( 'ezm_settings_errors' );
		}

		$s = EZM_Settings::all();
		include EZM_SECURITY_PATH . 'includes/views/settings-page.php';
	}

	public static function render_disk() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		include EZM_SECURITY_PATH . 'includes/views/disk-page.php';
	}

	public static function render_media() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		include EZM_SECURITY_PATH . 'includes/views/media-page.php';
	}

	public static function render_logs() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		include EZM_SECURITY_PATH . 'includes/views/logs-page.php';
	}
}
