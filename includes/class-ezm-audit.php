<?php
/**
 * Security audit hooks, Defender watchdog, posture checks.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EZM_Audit {

	const CONFIG_HASH_OPTION = 'ezm_wp_config_hash';
	const UPDATE_GRACE       = 'ezm_plugin_update_grace';

	public static function init() {
		add_action( 'wp_ajax_ezm_object_cache_ignore', array( __CLASS__, 'ajax_object_cache_ignore' ) );
		add_action( 'wp_ajax_ezm_object_cache_unignore', array( __CLASS__, 'ajax_object_cache_unignore' ) );
		add_action( 'wp_ajax_ezm_object_cache_delete', array( __CLASS__, 'ajax_object_cache_delete' ) );

		if ( ! EZM_Settings::get( 'module_audit', 1 ) ) {
			return;
		}

		add_action( 'activated_plugin', array( __CLASS__, 'on_plugin_activated' ), 10, 2 );
		add_action( 'deactivated_plugin', array( __CLASS__, 'on_plugin_deactivated' ), 10, 2 );
		add_action( 'deleted_plugin', array( __CLASS__, 'on_plugin_deleted' ), 10, 2 );
		add_filter( 'upgrader_pre_install', array( __CLASS__, 'snapshot_plugin_dirs' ), 10, 2 );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'on_upgrader_complete' ), 10, 2 );
		add_action( 'switch_theme', array( __CLASS__, 'on_switch_theme' ), 10, 3 );
		add_action( 'set_user_role', array( __CLASS__, 'on_set_user_role' ), 10, 3 );
		add_action( 'add_user_role', array( __CLASS__, 'on_add_user_role' ), 10, 2 );
		add_action( 'user_register', array( __CLASS__, 'on_user_register' ), 20, 1 );
		add_action( 'grant_super_admin', array( __CLASS__, 'on_grant_super_admin' ), 10, 1 );
		add_action( 'wp_login_failed', array( __CLASS__, 'on_login_failed' ) );
	}

	/**
	 * Context for who performed an action (actor + IP).
	 *
	 * @return array{actor_id:int,actor_login:string,actor_email:string,actor_display:string,ip:string}
	 */
	public static function actor_context() {
		$actor_id = get_current_user_id();
		$login    = '';
		$email    = '';
		$display  = '';
		if ( $actor_id ) {
			$user = get_userdata( $actor_id );
			if ( $user ) {
				$login   = $user->user_login;
				$email   = $user->user_email;
				$display = $user->display_name ? $user->display_name : $user->user_login;
			}
		}
		return array(
			'actor_id'      => (int) $actor_id,
			'actor_login'   => $login,
			'actor_email'   => $email,
			'actor_display' => $display ? $display : __( '(unknown / not logged in)', 'ezm-security-settings' ),
			'ip'            => self::request_ip(),
		);
	}

	/**
	 * Best-effort client IP (respects common proxy headers cautiously).
	 *
	 * @return string
	 */
	public static function request_ip() {
		$candidates = array();
		if ( ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
			$candidates[] = wp_unslash( $_SERVER['HTTP_CF_CONNECTING_IP'] );
		}
		if ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			$parts = explode( ',', wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) );
			if ( ! empty( $parts[0] ) ) {
				$candidates[] = trim( $parts[0] );
			}
		}
		if ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
			$candidates[] = wp_unslash( $_SERVER['REMOTE_ADDR'] );
		}
		foreach ( $candidates as $ip ) {
			$ip = sanitize_text_field( $ip );
			if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
				return $ip;
			}
		}
		return '';
	}

	/**
	 * Whether a user currently has administrator-level access.
	 *
	 * @param int $user_id User ID.
	 * @return bool
	 */
	public static function user_is_admin_level( $user_id ) {
		$user_id = absint( $user_id );
		if ( ! $user_id ) {
			return false;
		}
		if ( is_multisite() && is_super_admin( $user_id ) ) {
			return true;
		}
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return false;
		}
		if ( in_array( 'administrator', (array) $user->roles, true ) ) {
			return true;
		}
		return user_can( $user_id, 'manage_options' );
	}

	/**
	 * Whether a role slug is administrator-level.
	 *
	 * @param string $role Role slug.
	 * @return bool
	 */
	public static function role_is_admin_level( $role ) {
		$role = sanitize_key( $role );
		if ( 'administrator' === $role ) {
			return true;
		}
		if ( ! function_exists( 'get_role' ) ) {
			return false;
		}
		$obj = get_role( $role );
		return $obj && ! empty( $obj->capabilities['manage_options'] );
	}

	/**
	 * Normalize plugin file or folder to a stable slug for debounce keys.
	 * e.g. insert-headers-and-footers/ihaf.php → insert-headers-and-footers
	 *
	 * @param string $plugin Plugin basename or folder.
	 * @return string
	 */
	public static function plugin_slug( $plugin ) {
		$plugin = str_replace( '\\', '/', (string) $plugin );
		$plugin = trim( $plugin, '/' );
		if ( '' === $plugin ) {
			return '';
		}
		if ( false !== strpos( $plugin, '/' ) ) {
			return strtolower( dirname( $plugin ) );
		}
		// Bare folder or single-file plugin (hello.php → hello.php).
		return strtolower( preg_replace( '/\.php$/', '', $plugin ) );
	}

	/**
	 * Transient key marking a recent install/alert for a plugin slug.
	 *
	 * @param string $plugin Plugin file or folder.
	 * @return string
	 */
	public static function plugin_alert_transient_key( $plugin ) {
		$slug = self::plugin_slug( $plugin );
		return $slug ? 'ezm_plugin_alert_' . md5( $slug ) : '';
	}

	/**
	 * Remember we already emailed about this plugin (install or activate).
	 *
	 * @param string $plugin Plugin file or folder.
	 */
	public static function mark_plugin_alerted( $plugin ) {
		$key = self::plugin_alert_transient_key( $plugin );
		if ( $key ) {
			set_transient( $key, 1, 10 * MINUTE_IN_SECONDS );
		}
	}

	/**
	 * @param string $plugin Plugin file or folder.
	 * @return bool
	 */
	public static function was_plugin_recently_alerted( $plugin ) {
		$key = self::plugin_alert_transient_key( $plugin );
		return $key && get_transient( $key );
	}

	public static function on_plugin_activated( $plugin, $network_wide ) {
		if ( ! EZM_Settings::get( 'plugin_event_alerts', 1 ) && ! EZM_Settings::get( 'urgent_security_alerts', 1 ) ) {
			return;
		}
		// Suppress if within update grace window for this plugin.
		$grace = get_transient( self::UPDATE_GRACE );
		if ( is_array( $grace ) && ! empty( $grace[ $plugin ] ) && time() < (int) $grace[ $plugin ] ) {
			EZM_DB::log_event( 'plugin_reactivated', sprintf( 'Plugin reactivated after update: %s', $plugin ), 'info', array( 'plugin' => $plugin ) );
			return;
		}

		$ctx = self::actor_context();
		$meta = array_merge(
			array(
				'plugin'  => $plugin,
				'network' => (bool) $network_wide,
			),
			$ctx
		);
		$message = sprintf(
			'Plugin activated: %s (by %s from IP %s)',
			$plugin,
			$ctx['actor_display'],
			$ctx['ip'] ? $ctx['ip'] : 'unknown'
		);

		// Same plugin just installed (or already alerted) — log only, no second email.
		if ( self::was_plugin_recently_alerted( $plugin ) ) {
			EZM_DB::log_event( 'plugin_activated', $message . ' [email suppressed: already alerted]', 'info', $meta );
			return;
		}

		EZM_DB::log_event( 'plugin_activated', $message, 'critical', $meta );

		if ( EZM_Settings::get( 'urgent_security_alerts', 1 ) ) {
			self::mark_plugin_alerted( $plugin );
			EZM_Mail::send_urgent_alert(
				'Plugin activated',
				$message,
				$meta
			);
		}
	}

	public static function on_plugin_deactivated( $plugin, $network_wide ) {
		if ( ! EZM_Settings::get( 'plugin_event_alerts', 1 ) ) {
			return;
		}
		EZM_DB::log_event( 'plugin_deactivated', sprintf( 'Plugin deactivated: %s', $plugin ), 'warning', array( 'plugin' => $plugin ) );
	}

	public static function on_plugin_deleted( $plugin_file, $deleted ) {
		if ( ! EZM_Settings::get( 'plugin_event_alerts', 1 ) ) {
			return;
		}
		EZM_DB::log_event( 'plugin_deleted', sprintf( 'Plugin deleted: %s', $plugin_file ), 'warning', array( 'plugin' => $plugin_file, 'deleted' => (bool) $deleted ) );
	}

	/**
	 * Folder names currently in the plugins directory.
	 *
	 * @return string[]
	 */
	public static function plugin_dir_names() {
		$base  = trailingslashit( WP_PLUGIN_DIR );
		$names = array();
		if ( ! is_dir( $base ) ) {
			return $names;
		}
		$items = scandir( $base );
		if ( ! is_array( $items ) ) {
			return $names;
		}
		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}
			if ( is_dir( $base . $item ) || ( is_file( $base . $item ) && substr( $item, -4 ) === '.php' ) ) {
				$names[] = strtolower( $item );
			}
		}
		sort( $names );
		return $names;
	}

	/**
	 * Snapshot plugin folders before an install so a nameless hook can be matched later.
	 *
	 * @param bool|WP_Error $response   Install response.
	 * @param array         $hook_extra Hook extra.
	 * @return bool|WP_Error
	 */
	public static function snapshot_plugin_dirs( $response, $hook_extra ) {
		if ( is_array( $hook_extra ) && isset( $hook_extra['type'] ) && 'plugin' === $hook_extra['type'] ) {
			set_transient( 'ezm_plugin_dirs_before', self::plugin_dir_names(), 15 * MINUTE_IN_SECONDS );
		}
		return $response;
	}

	/**
	 * Plugin folders that appeared since the pre-install snapshot.
	 *
	 * @return string[]
	 */
	public static function new_plugin_dirs() {
		$before = get_transient( 'ezm_plugin_dirs_before' );
		if ( ! is_array( $before ) ) {
			return array();
		}
		return array_values( array_diff( self::plugin_dir_names(), $before ) );
	}

	/**
	 * Whether this upload is replacing a plugin that was already on disk.
	 *
	 * Zip uploads through wp-admin use action "install" even for updates.
	 *
	 * @param WP_Upgrader $upgrader      Upgrader.
	 * @param array       $options       Hook extra.
	 * @param string[]    $slug_sources  Plugin file or folder names.
	 * @return bool
	 */
	public static function is_plugin_replace( $upgrader, $options, $slug_sources ) {
		if ( ! empty( $options['overwrite_package'] ) || ! empty( $options['overwrite'] ) ) {
			return true;
		}
		if ( is_object( $upgrader ) && isset( $upgrader->skin->overwrite ) && $upgrader->skin->overwrite ) {
			return true;
		}

		$before = get_transient( 'ezm_plugin_dirs_before' );
		if ( ! is_array( $before ) || empty( $slug_sources ) ) {
			return false;
		}
		$before = array_map( 'strtolower', $before );
		foreach ( $slug_sources as $src ) {
			$src    = strtolower( (string) $src );
			$slug   = self::plugin_slug( $src );
			$folder = strtolower( basename( str_replace( '\\', '/', $src ) ) );
			if ( in_array( $src, $before, true ) || ( $slug && in_array( $slug, $before, true ) ) || in_array( $folder, $before, true ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Treat a replaced plugin as an update so the following activate hook stays quiet.
	 *
	 * @param string[] $slug_sources Plugin file or folder names.
	 */
	public static function mark_replaced_plugins( $slug_sources ) {
		$grace = get_transient( self::UPDATE_GRACE );
		if ( ! is_array( $grace ) ) {
			$grace = array();
		}
		$until = time() + 10 * MINUTE_IN_SECONDS;
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$installed = array_keys( get_plugins() );
		foreach ( $slug_sources as $src ) {
			$src  = (string) $src;
			$slug = self::plugin_slug( $src );
			$grace[ $src ] = $until;
			if ( $slug ) {
				$grace[ $slug ] = $until;
			}
			foreach ( $installed as $file ) {
				if ( $slug && self::plugin_slug( $file ) === $slug ) {
					$grace[ $file ] = $until;
				}
			}
		}
		set_transient( self::UPDATE_GRACE, $grace, 15 * MINUTE_IN_SECONDS );
	}

	/**
	 * Read destination_name only when the upgrader result is an array.
	 * A failed upload leaves a WP_Error here.
	 *
	 * @param mixed $result Upgrader result.
	 * @return string
	 */
	public static function upgrader_destination_name( $result ) {
		if ( ! is_array( $result ) || empty( $result['destination_name'] ) || ! is_scalar( $result['destination_name'] ) ) {
			return '';
		}
		return (string) $result['destination_name'];
	}

	/**
	 * @param WP_Upgrader $upgrader Upgrader.
	 * @param array       $options  Options.
	 */
	public static function on_upgrader_complete( $upgrader, $options ) {
		if ( empty( $options['type'] ) || 'plugin' !== $options['type'] ) {
			return;
		}

		$action  = isset( $options['action'] ) ? $options['action'] : '';
		$plugins = array();
		if ( ! empty( $options['plugins'] ) && is_array( $options['plugins'] ) ) {
			$plugins = $options['plugins'];
		} elseif ( ! empty( $options['plugin'] ) ) {
			$plugins = array( $options['plugin'] );
		}

		$dest = '';
		if ( is_object( $upgrader ) ) {
			$dest = self::upgrader_destination_name( $upgrader->result );
			if ( '' === $dest && isset( $upgrader->skin->result ) ) {
				$dest = self::upgrader_destination_name( $upgrader->skin->result );
			}
		}

		// Brand-new install (not an update) — urgent.
		if ( 'install' === $action && EZM_Settings::get( 'urgent_security_alerts', 1 ) ) {
			$slug_sources = $plugins;
			if ( $dest ) {
				$slug_sources[] = $dest;
			}
			// Some installers fire this hook before the package name exists.
			// Fill the name from folders that appeared during this install.
			if ( empty( $slug_sources ) ) {
				$slug_sources = self::new_plugin_dirs();
			}

			$label = ! empty( $slug_sources ) ? implode( ', ', $slug_sources ) : '';

			$ctx  = self::actor_context();
			$meta = array_merge(
				array(
					'plugins' => $plugins,
					'action'  => 'install',
					'dest'    => $dest,
				),
				$ctx
			);

			// Don't email "unknown". A later hook in the same install has the real name.
			if ( '' === $label ) {
				EZM_DB::log_event(
					'plugin_installed',
					'Plugin install hook fired without a plugin name; waiting for a named event.',
					'info',
					$meta
				);
			} elseif ( self::is_plugin_replace( $upgrader, $options, $slug_sources ) ) {
				// Uploading a zip of a plugin that is already installed.
				self::mark_replaced_plugins( $slug_sources );
				EZM_DB::log_event(
					'plugin_updated_upload',
					sprintf(
						'Existing plugin replaced via upload: %s (by %s from IP %s)',
						$label,
						$ctx['actor_display'],
						$ctx['ip'] ? $ctx['ip'] : 'unknown'
					),
					'info',
					$meta
				);
			} else {
				$already = false;
				foreach ( $slug_sources as $src ) {
					if ( self::was_plugin_recently_alerted( $src ) ) {
						$already = true;
						break;
					}
				}

				$message = sprintf(
					'Plugin installed: %s (by %s from IP %s)',
					$label,
					$ctx['actor_display'],
					$ctx['ip'] ? $ctx['ip'] : 'unknown'
				);

				if ( $already ) {
					EZM_DB::log_event( 'plugin_installed', $message . ' [email suppressed: already alerted]', 'info', $meta );
				} else {
					EZM_DB::log_event( 'plugin_installed', $message, 'critical', $meta );
					EZM_Mail::send_urgent_alert( 'Plugin installed', $message, $meta );
					foreach ( $slug_sources as $src ) {
						self::mark_plugin_alerted( $src );
					}
				}
			}
		}

		if ( empty( $plugins ) ) {
			return;
		}

		$grace = get_transient( self::UPDATE_GRACE );
		if ( ! is_array( $grace ) ) {
			$grace = array();
		}
		foreach ( $plugins as $plugin ) {
			$grace[ $plugin ] = time() + 10 * MINUTE_IN_SECONDS;
		}
		set_transient( self::UPDATE_GRACE, $grace, 15 * MINUTE_IN_SECONDS );

		// Schedule failed-reactivate check.
		wp_schedule_single_event( time() + 5 * MINUTE_IN_SECONDS, 'ezm_check_plugin_reactivate', array( $plugins ) );
		if ( ! has_action( 'ezm_check_plugin_reactivate', array( __CLASS__, 'check_failed_reactivate' ) ) ) {
			add_action( 'ezm_check_plugin_reactivate', array( __CLASS__, 'check_failed_reactivate' ), 10, 1 );
		}
	}

	/**
	 * @param array $plugins Plugin files.
	 */
	public static function check_failed_reactivate( $plugins ) {
		if ( ! is_array( $plugins ) ) {
			return;
		}
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		foreach ( $plugins as $plugin ) {
			if ( ! is_plugin_active( $plugin ) ) {
				EZM_DB::log_event(
					'plugin_failed_reactivate',
					sprintf( 'Plugin failed to reactivate after update: %s', $plugin ),
					'critical',
					array( 'plugin' => $plugin )
				);
			}
		}
	}

	public static function on_switch_theme( $new_name, $new_theme, $old_theme ) {
		EZM_DB::log_event(
			'theme_switched',
			sprintf( 'Theme switched to %s', $new_name ),
			'warning',
			array(
				'new' => $new_name,
				'old' => is_object( $old_theme ) ? $old_theme->get( 'Name' ) : '',
			)
		);
	}

	public static function on_set_user_role( $user_id, $role, $old_roles ) {
		$ctx  = self::actor_context();
		$meta = array_merge(
			array(
				'user_id'   => (int) $user_id,
				'role'      => $role,
				'old_roles' => $old_roles,
			),
			$ctx
		);
		EZM_DB::log_event(
			'user_role_change',
			sprintf(
				'User %d role changed to %s (by %s from IP %s)',
				$user_id,
				$role,
				$ctx['actor_display'],
				$ctx['ip'] ? $ctx['ip'] : 'unknown'
			),
			'warning',
			$meta
		);

		$was_admin = false;
		foreach ( (array) $old_roles as $old ) {
			if ( self::role_is_admin_level( $old ) ) {
				$was_admin = true;
				break;
			}
		}
		if ( ! $was_admin && self::role_is_admin_level( $role ) ) {
			self::alert_admin_user_change( $user_id, 'promoted', $meta );
		}
	}

	/**
	 * @param int    $user_id User ID.
	 * @param string $role    Role slug.
	 */
	public static function on_add_user_role( $user_id, $role ) {
		if ( ! self::role_is_admin_level( $role ) ) {
			return;
		}
		$ctx  = self::actor_context();
		$meta = array_merge(
			array(
				'user_id' => (int) $user_id,
				'role'    => $role,
			),
			$ctx
		);
		self::alert_admin_user_change( $user_id, 'role_added', $meta );
	}

	/**
	 * @param int $user_id New user ID.
	 */
	public static function on_user_register( $user_id ) {
		$user_id = absint( $user_id );
		if ( ! $user_id || ! self::user_is_admin_level( $user_id ) ) {
			return;
		}
		$ctx  = self::actor_context();
		$meta = array_merge(
			array(
				'user_id' => $user_id,
			),
			$ctx
		);
		self::alert_admin_user_change( $user_id, 'created', $meta );
	}

	/**
	 * @param int $user_id User ID.
	 */
	public static function on_grant_super_admin( $user_id ) {
		$ctx  = self::actor_context();
		$meta = array_merge(
			array(
				'user_id' => (int) $user_id,
			),
			$ctx
		);
		self::alert_admin_user_change( $user_id, 'super_admin', $meta );
	}

	/**
	 * Log + optional immediate email for admin-level user changes.
	 *
	 * @param int    $user_id Target user.
	 * @param string $kind    created|promoted|role_added|super_admin.
	 * @param array  $meta    Extra meta (should include actor/ip).
	 */
	public static function alert_admin_user_change( $user_id, $kind, $meta = array() ) {
		$user = get_userdata( $user_id );
		$who  = $user
			? sprintf( '%s <%s> (ID %d)', $user->user_login, $user->user_email, $user_id )
			: sprintf( 'ID %d', $user_id );

		$labels = array(
			'created'     => 'New administrator user created',
			'promoted'    => 'User promoted to administrator',
			'role_added'  => 'Administrator role added to user',
			'super_admin' => 'User granted super admin',
		);
		$title = isset( $labels[ $kind ] ) ? $labels[ $kind ] : 'Administrator access changed';

		$actor = isset( $meta['actor_display'] ) ? $meta['actor_display'] : __( '(unknown)', 'ezm-security-settings' );
		$ip    = ! empty( $meta['ip'] ) ? $meta['ip'] : 'unknown';
		$message = sprintf( '%s: %s (by %s from IP %s)', $title, $who, $actor, $ip );

		$meta['kind']         = $kind;
		$meta['target_login'] = $user ? $user->user_login : '';
		$meta['target_email'] = $user ? $user->user_email : '';

		EZM_DB::log_event( 'admin_user_change', $message, 'critical', $meta );

		$debounce = 'ezm_admin_user_' . (int) $user_id;
		if ( get_transient( $debounce ) ) {
			return;
		}
		set_transient( $debounce, 1, 2 * MINUTE_IN_SECONDS );

		if ( EZM_Settings::get( 'urgent_security_alerts', 1 ) ) {
			EZM_Mail::send_urgent_alert( $title, $message, $meta );
		}
	}

	public static function on_login_failed( $username ) {
		$key   = 'ezm_failed_logins_' . gmdate( 'YmdH' );
		$count = (int) get_transient( $key );
		$count++;
		set_transient( $key, $count, 2 * HOUR_IN_SECONDS );
		if ( $count === 20 || ( $count > 20 && 0 === $count % 50 ) ) {
			EZM_DB::log_event(
				'failed_login_spike',
				sprintf( 'Failed login spike: %d in this hour (last attempt user: %s)', $count, $username ),
				'warning',
				array( 'count' => $count )
			);
		}
	}

	/**
	 * Daily posture / config / Defender checks.
	 */
	public static function run_daily_checks() {
		self::check_wp_config_changes();
		EZM_Uploads_Safety::scan();
		self::check_posture();
		self::check_defender_watchdog();
		self::check_object_cache_dropin();
		self::check_failed_reactivate_pending();
	}

	private static function check_failed_reactivate_pending() {
		// Ensure hook is registered for scheduled checks.
		add_action( 'ezm_check_plugin_reactivate', array( __CLASS__, 'check_failed_reactivate' ), 10, 1 );
	}

	private static function check_wp_config_changes() {
		$path = ABSPATH . 'wp-config.php';
		if ( ! is_readable( $path ) ) {
			// Sometimes one level up.
			$path = dirname( ABSPATH ) . '/wp-config.php';
		}
		if ( ! is_readable( $path ) ) {
			return;
		}
		$raw  = file_get_contents( $path );
		$hash = hash( 'sha256', $raw );
		$prev = get_option( self::CONFIG_HASH_OPTION, '' );
		if ( $prev && $prev !== $hash ) {
			$redacted = self::redact_config( $raw );
			EZM_DB::log_event(
				'wp_config_changed',
				'wp-config.php content changed.',
				'critical',
				array( 'hash' => $hash, 'snippet' => substr( $redacted, 0, 500 ) )
			);
		}
		update_option( self::CONFIG_HASH_OPTION, $hash, false );

		// Drop-ins.
		$dropins = array( 'advanced-cache.php', 'object-cache.php', 'db.php', 'db-error.php', 'install.php', 'maintenance.php', 'php-error.php', 'fatal-error-handler.php' );
		$content = wp_normalize_path( WP_CONTENT_DIR );
		$state   = get_option( 'ezm_dropin_hashes', array() );
		if ( ! is_array( $state ) ) {
			$state = array();
		}
		foreach ( $dropins as $file ) {
			$p = $content . '/' . $file;
			if ( ! is_readable( $p ) ) {
				if ( ! empty( $state[ $file ] ) ) {
					EZM_DB::log_event( 'dropin_removed', sprintf( 'Drop-in removed: %s', $file ), 'warning', array( 'file' => $file ) );
					unset( $state[ $file ] );
				}
				continue;
			}
			$h = hash_file( 'sha256', $p );
			if ( ! empty( $state[ $file ] ) && $state[ $file ] !== $h ) {
				EZM_DB::log_event( 'dropin_changed', sprintf( 'Drop-in changed: %s', $file ), 'critical', array( 'file' => $file ) );
			}
			$state[ $file ] = $h;
		}
		update_option( 'ezm_dropin_hashes', $state, false );
	}

	/**
	 * @param string $raw Config contents.
	 * @return string
	 */
	private static function redact_config( $raw ) {
		$raw = preg_replace( "/(define\\s*\\(\\s*['\"]DB_PASSWORD['\"]\\s*,\\s*)(['\"]).+?\\2/i", '$1$2***$2', $raw );
		$raw = preg_replace( "/(define\\s*\\(\\s*['\"]DB_USER['\"]\\s*,\\s*)(['\"]).+?\\2/i", '$1$2***$2', $raw );
		$raw = preg_replace( "/(define\\s*\\(\\s*['\"]AUTH_KEY['\"]\\s*,\\s*)(['\"]).+?\\2/i", '$1$2***$2', $raw );
		$raw = preg_replace( "/(define\\s*\\(\\s*['\"]SECURE_AUTH_KEY['\"]\\s*,\\s*)(['\"]).+?\\2/i", '$1$2***$2', $raw );
		$raw = preg_replace( "/(define\\s*\\(\\s*['\"]LOGGED_IN_KEY['\"]\\s*,\\s*)(['\"]).+?\\2/i", '$1$2***$2', $raw );
		$raw = preg_replace( "/(define\\s*\\(\\s*['\"]NONCE_KEY['\"]\\s*,\\s*)(['\"]).+?\\2/i", '$1$2***$2', $raw );
		$raw = preg_replace( "/(define\\s*\\(\\s*['\"][^'\"]*SALT['\"]\\s*,\\s*)(['\"]).+?\\2/i", '$1$2***$2', $raw );
		return $raw;
	}

	private static function check_php_in_uploads() {
		$uploads = wp_upload_dir();
		$base    = wp_normalize_path( $uploads['basedir'] );
		if ( ! is_dir( $base ) ) {
			return;
		}
		$found = array();
		$start = microtime( true );
		try {
			$it = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $base, FilesystemIterator::SKIP_DOTS ),
				RecursiveIteratorIterator::LEAVES_ONLY
			);
			foreach ( $it as $file ) {
				if ( ( microtime( true ) - $start ) > 5 ) {
					break;
				}
				/** @var SplFileInfo $file */
				if ( ! $file->isFile() ) {
					continue;
				}
				$ext = strtolower( $file->getExtension() );
				if ( in_array( $ext, array( 'php', 'phtml', 'phar' ), true ) ) {
					$path = wp_normalize_path( $file->getPathname() );
					// Ignore our pending_deletion marker dirs' accidental PHP — still report.
					$found[] = $path;
					if ( count( $found ) >= 10 ) {
						break;
					}
				}
			}
		} catch ( Exception $e ) {
			return;
		}

		if ( $found ) {
			EZM_DB::log_event(
				'php_in_uploads',
				sprintf( 'Found %d PHP file(s) under uploads.', count( $found ) ),
				'critical',
				array( 'files' => $found )
			);
		}
	}

	private static function check_posture() {
		$flags = array();
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			$flags[] = 'WP_DEBUG on';
		}
		if ( defined( 'DISALLOW_FILE_EDIT' ) && ! DISALLOW_FILE_EDIT ) {
			$flags[] = 'file edit allowed';
		}
		if ( ! defined( 'DISALLOW_FILE_EDIT' ) ) {
			$flags[] = 'DISALLOW_FILE_EDIT unset';
		}
		// XML-RPC: file exists and not obviously disabled by constant.
		if ( ! defined( 'XMLRPC_REQUEST' ) && file_exists( ABSPATH . 'xmlrpc.php' ) ) {
			$flags[] = 'xmlrpc.php present';
		}
		if ( $flags ) {
			EZM_DB::log_event( 'posture_flags', 'Posture checklist items noted.', 'info', array( 'flags' => $flags ) );
		}
	}

	private static function check_defender_watchdog() {
		if ( ! EZM_Settings::get( 'expect_defender', 1 ) ) {
			return;
		}

		$active = false;
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$candidates = array(
			'defender-security/wp-defender.php',
			'wp-defender/wp-defender.php',
			'defender-pro/wp-defender.php',
		);
		foreach ( $candidates as $c ) {
			if ( is_plugin_active( $c ) ) {
				$active = true;
				break;
			}
		}
		// Also detect by class/function.
		if ( ! $active && ( class_exists( 'WP_Defender\\Model\\Scan' ) || function_exists( 'wd_di' ) ) ) {
			$active = true;
		}

		if ( ! $active ) {
			EZM_DB::log_event( 'defender_missing', 'Defender expected but not detected as active.', 'warning' );
			return;
		}

		// Look for recent finished scan option/transient patterns used by Defender 6.x.
		$finished = false;
		$scan     = get_option( 'defender_scan', null );
		if ( is_array( $scan ) && ! empty( $scan['status'] ) && 'finish' === $scan['status'] ) {
			$ts = ! empty( $scan['dateFinished'] ) ? strtotime( $scan['dateFinished'] ) : 0;
			if ( $ts && $ts > time() - 7 * DAY_IN_SECONDS ) {
				$finished = true;
			}
		}

		// Alternate storage.
		if ( ! $finished ) {
			global $wpdb;
			$row = $wpdb->get_row( "SELECT option_value FROM {$wpdb->options} WHERE option_name LIKE '%defender%scan%' ORDER BY option_id DESC LIMIT 1" );
			if ( $row && false !== stripos( $row->option_value, 'finish' ) ) {
				$finished = true;
			}
		}

		if ( ! $finished ) {
			EZM_DB::log_event(
				'defender_stale',
				'Defender scan has not finished within 7 days (or status unknown/idle).',
				'warning'
			);
		}
	}

	/**
	 * Absolute path to the object-cache drop-in.
	 *
	 * @return string
	 */
	public static function object_cache_path() {
		return wp_normalize_path( trailingslashit( WP_CONTENT_DIR ) . 'object-cache.php' );
	}

	/**
	 * Ignored object-cache finding (hash-bound so a replaced file re-alerts).
	 *
	 * @return array
	 */
	public static function object_cache_ignore() {
		$data = get_option( 'ezm_object_cache_ignore', array() );
		return is_array( $data ) ? $data : array();
	}

	/**
	 * Evaluate wp-content/object-cache.php vs Redis availability.
	 *
	 * @return array{
	 *   exists:bool,is_redis:bool,problem:bool,ignored:bool,healthy:bool,
	 *   reasons:string[],message:string,path:string,hash:string
	 * }
	 */
	public static function evaluate_object_cache() {
		$path = self::object_cache_path();
		$out  = array(
			'exists'   => false,
			'is_redis' => false,
			'problem'  => false,
			'ignored'  => false,
			'healthy'  => true,
			'reasons'  => array(),
			'message'  => '',
			'path'     => $path,
			'hash'     => '',
		);

		if ( ! is_file( $path ) ) {
			return $out;
		}

		$out['exists'] = true;
		$hash          = @hash_file( 'sha256', $path );
		$out['hash']   = $hash ? $hash : md5( $path );

		$ignore = self::object_cache_ignore();
		if ( ! empty( $ignore['hash'] ) && $ignore['hash'] === $out['hash'] ) {
			$out['ignored'] = true;
		}

		$snippet = '';
		$fh      = @fopen( $path, 'rb' );
		if ( $fh ) {
			$snippet = (string) fread( $fh, 12288 );
			fclose( $fh );
		}
		$low = strtolower( $snippet );
		$is_redis = ( false !== strpos( $low, 'redis' ) || false !== strpos( $low, 'predis' ) );
		$out['is_redis'] = $is_redis;

		if ( ! $is_redis ) {
			// Memcached / other drop-ins are outside this Redis-specific check.
			$out['message'] = __( 'object-cache.php is present (not a Redis drop-in).', 'ezm-security-settings' );
			return $out;
		}

		$reasons = array();

		$has_client = extension_loaded( 'redis' ) || class_exists( 'Redis', false ) || class_exists( 'Predis\\Client', false );
		if ( ! $has_client ) {
			$reasons[] = __( 'No Redis PHP extension or Predis client is available.', 'ezm-security-settings' );
		}

		if ( defined( 'WP_REDIS_DISABLED' ) && WP_REDIS_DISABLED ) {
			$reasons[] = __( 'WP_REDIS_DISABLED is enabled.', 'ezm-security-settings' );
		}

		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$redis_plugins = array(
			'redis-cache/redis-cache.php',
			'redis-object-cache/redis-object-cache.php',
			'wp-redis/wp-redis.php',
			'object-cache-pro/object-cache-pro.php',
		);
		$plugin_active = false;
		foreach ( $redis_plugins as $plugin ) {
			if ( is_plugin_active( $plugin ) ) {
				$plugin_active = true;
				break;
			}
		}

		$connected = null;
		global $wp_object_cache;
		if ( is_object( $wp_object_cache ) ) {
			if ( method_exists( $wp_object_cache, 'redis_status' ) ) {
				$connected = (bool) $wp_object_cache->redis_status();
			} elseif ( method_exists( $wp_object_cache, 'is_connected' ) ) {
				$connected = (bool) $wp_object_cache->is_connected();
			} else {
				foreach ( array( 'redis_connected', 'connected', 'is_connected' ) as $prop ) {
					if ( isset( $wp_object_cache->$prop ) ) {
						$connected = (bool) $wp_object_cache->$prop;
						break;
					}
				}
			}
		}
		if ( false === $connected ) {
			$reasons[] = __( 'Object cache reports Redis is not connected.', 'ezm-security-settings' );
		}

		$reachable = null;
		if ( null === $connected ) {
			$reachable = self::probe_redis_endpoint();
			if ( false === $reachable ) {
				$reasons[] = __( 'Redis endpoint does not appear reachable.', 'ezm-security-settings' );
			}
		}

		$using_ext = function_exists( 'wp_using_ext_object_cache' ) ? wp_using_ext_object_cache() : false;
		if ( ! $using_ext ) {
			$reasons[] = __( 'object-cache.php exists but WordPress is not using an external object cache.', 'ezm-security-settings' );
		}

		$looks_live = ( true === $connected ) || ( true === $reachable && $has_client && $using_ext );
		if ( ! $plugin_active && ! $looks_live ) {
			$reasons[] = __( 'No Redis object-cache plugin is active (drop-in may be leftover).', 'ezm-security-settings' );
		}

		$out['reasons'] = array_values( array_unique( $reasons ) );
		$out['problem'] = ! empty( $out['reasons'] );
		$out['healthy'] = ! $out['problem'];

		if ( $out['problem'] ) {
			$out['message'] = __( 'wp-content/object-cache.php looks like a Redis drop-in, but Redis does not appear to be in use.', 'ezm-security-settings' );
		} else {
			$out['message'] = __( 'Redis object-cache drop-in looks healthy.', 'ezm-security-settings' );
		}

		return $out;
	}

	/**
	 * Quick TCP/unix probe with a short timeout (avoids hanging the admin).
	 *
	 * @return bool|null true reachable, false not, null skipped/unknown.
	 */
	private static function probe_redis_endpoint() {
		$scheme = defined( 'WP_REDIS_SCHEME' ) ? (string) WP_REDIS_SCHEME : 'tcp';
		if ( 'unix' === $scheme || 'uds' === $scheme ) {
			$path = defined( 'WP_REDIS_PATH' ) ? (string) WP_REDIS_PATH : '';
			if ( '' === $path ) {
				return null;
			}
			return file_exists( $path );
		}

		$host = defined( 'WP_REDIS_HOST' ) ? (string) WP_REDIS_HOST : '127.0.0.1';
		$port = defined( 'WP_REDIS_PORT' ) ? (int) WP_REDIS_PORT : 6379;
		if ( '' === $host || $port < 1 ) {
			return null;
		}

		$errno  = 0;
		$errstr = '';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fsockopen -- intentional short probe.
		$fp = @stream_socket_client(
			sprintf( 'tcp://%s:%d', $host, $port ),
			$errno,
			$errstr,
			0.4
		);
		if ( $fp ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			fclose( $fp );
			return true;
		}
		return false;
	}

	/**
	 * Persist / clear an ignore for the current object-cache.php hash.
	 *
	 * @param bool $ignore Ignore or clear.
	 * @return array Current evaluation.
	 */
	public static function set_object_cache_ignored( $ignore ) {
		$eval = self::evaluate_object_cache();
		if ( $ignore ) {
			if ( empty( $eval['exists'] ) || empty( $eval['hash'] ) ) {
				return $eval;
			}
			update_option(
				'ezm_object_cache_ignore',
				array(
					'hash'      => $eval['hash'],
					'path'      => $eval['path'],
					'ignored_at'=> current_time( 'mysql' ),
				),
				false
			);
		} else {
			delete_option( 'ezm_object_cache_ignore' );
		}
		return self::evaluate_object_cache();
	}

	/**
	 * Daily: log a critical event when Redis drop-in looks orphaned (unless ignored).
	 */
	public static function check_object_cache_dropin() {
		$eval = self::evaluate_object_cache();
		if ( empty( $eval['problem'] ) || ! empty( $eval['ignored'] ) ) {
			return;
		}

		$stamp = get_option( 'ezm_object_cache_alerted', array() );
		if ( is_array( $stamp ) && ! empty( $stamp['hash'] ) && $stamp['hash'] === $eval['hash'] && ! empty( $stamp['at'] ) && ( time() - (int) $stamp['at'] ) < DAY_IN_SECONDS ) {
			return;
		}

		EZM_DB::log_event(
			'object_cache_redis_unused',
			$eval['message'] . ' ' . implode( ' ', $eval['reasons'] ),
			'critical',
			array(
				'path'    => $eval['path'],
				'reasons' => $eval['reasons'],
				'hash'    => $eval['hash'],
			)
		);
		update_option(
			'ezm_object_cache_alerted',
			array(
				'hash' => $eval['hash'],
				'at'   => time(),
			),
			false
		);
	}

	public static function ajax_object_cache_ignore() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( null, 403 );
		}
		check_ajax_referer( 'ezm_admin', 'nonce' );
		$eval = self::set_object_cache_ignored( true );
		wp_send_json_success( $eval );
	}

	public static function ajax_object_cache_unignore() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( null, 403 );
		}
		check_ajax_referer( 'ezm_admin', 'nonce' );
		$eval = self::set_object_cache_ignored( false );
		wp_send_json_success( $eval );
	}

	public static function ajax_object_cache_delete() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( null, 403 );
		}
		check_ajax_referer( 'ezm_admin', 'nonce' );

		$path = self::object_cache_path();
		if ( ! is_file( $path ) ) {
			wp_send_json_error( array( 'message' => __( 'object-cache.php is already gone.', 'ezm-security-settings' ) ) );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		$ok = @unlink( $path );
		if ( ! $ok ) {
			wp_send_json_error( array( 'message' => __( 'Could not delete object-cache.php (permissions?).', 'ezm-security-settings' ) ) );
		}

		delete_option( 'ezm_object_cache_ignore' );
		delete_option( 'ezm_object_cache_alerted' );
		EZM_DB::log_event(
			'object_cache_deleted',
			'Deleted wp-content/object-cache.php drop-in.',
			'warning',
			array( 'path' => $path )
		);

		wp_send_json_success( array( 'message' => __( 'Deleted object-cache.php.', 'ezm-security-settings' ) ) );
	}

	/**
	 * Recent significant events for digests.
	 *
	 * @param int $days Days.
	 * @return array
	 */
	public static function recent_events( $days = 7 ) {
		global $wpdb;
		$table = $wpdb->prefix . 'ezm_events';
		$since = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE created_at >= %s ORDER BY created_at DESC LIMIT 100",
				$since
			),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}
}

// Register delayed hook early.
add_action( 'ezm_check_plugin_reactivate', array( 'EZM_Audit', 'check_failed_reactivate' ), 10, 1 );
