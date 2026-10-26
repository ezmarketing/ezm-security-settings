<?php
/**
 * Track password change dates and remind admin-access users after 90 days.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EZM_Password_Age {

	const META_CHANGED  = 'ezm_password_changed_at';
	const META_REMINDED = 'ezm_password_reminded_at';
	const AGE_DAYS      = 90;
	const REMIND_DAYS   = 7;
	const SEEDED_OPTION = 'ezm_password_age_seeded';

	/**
	 * Roles we never track.
	 *
	 * @return string[]
	 */
	public static function excluded_roles() {
		return array( 'subscriber', 'customer' );
	}

	public static function init() {
		if ( ! EZM_Settings::get( 'module_password_age', 1 ) ) {
			return;
		}

		add_action( 'wp_set_password', array( __CLASS__, 'on_password_set' ), 10, 2 );
		add_action( 'after_password_reset', array( __CLASS__, 'on_password_reset' ), 10, 1 );
		add_action( 'profile_update', array( __CLASS__, 'on_profile_update' ), 10, 2 );
		add_action( 'user_register', array( __CLASS__, 'on_user_register' ), 30, 1 );
		add_action( 'admin_init', array( __CLASS__, 'maybe_seed' ), 5 );
	}

	/**
	 * Whether the module is enabled.
	 *
	 * @return bool
	 */
	public static function enabled() {
		return (bool) EZM_Settings::get( 'module_password_age', 1 );
	}

	/**
	 * Whether a user should be tracked (not subscriber/customer-only).
	 *
	 * @param int|WP_User $user User.
	 * @return bool
	 */
	public static function should_track_user( $user ) {
		$user = $user instanceof WP_User ? $user : get_userdata( (int) $user );
		if ( ! $user || ! $user->exists() ) {
			return false;
		}

		$roles = array_map( 'strtolower', (array) $user->roles );
		if ( empty( $roles ) ) {
			// No role yet — track if they have elevated caps.
			return user_can( $user, 'edit_posts' ) || user_can( $user, 'manage_options' );
		}

		$excluded = self::excluded_roles();
		foreach ( $roles as $role ) {
			if ( ! in_array( $role, $excluded, true ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Users with admin-level access (recipients of 90-day reminders).
	 *
	 * @param int|WP_User $user User.
	 * @return bool
	 */
	public static function is_admin_access_user( $user ) {
		$user = $user instanceof WP_User ? $user : get_userdata( (int) $user );
		if ( ! $user || ! $user->exists() ) {
			return false;
		}
		if ( is_multisite() && is_super_admin( $user->ID ) ) {
			return true;
		}
		return user_can( $user, 'manage_options' ) || in_array( 'administrator', (array) $user->roles, true );
	}

	/**
	 * @param int $user_id User ID.
	 * @return int Unix timestamp or 0.
	 */
	public static function get_changed_at( $user_id ) {
		return absint( get_user_meta( (int) $user_id, self::META_CHANGED, true ) );
	}

	/**
	 * @param int $user_id User ID.
	 * @param int $ts      Unix timestamp.
	 */
	public static function set_changed_at( $user_id, $ts = 0 ) {
		$user_id = absint( $user_id );
		if ( ! $user_id || ! self::should_track_user( $user_id ) ) {
			return;
		}
		$ts = $ts ? absint( $ts ) : time();
		update_user_meta( $user_id, self::META_CHANGED, $ts );
		delete_user_meta( $user_id, self::META_REMINDED );
	}

	/**
	 * Days since last recorded password change (null if unknown / not tracked).
	 *
	 * @param int $user_id User ID.
	 * @return int|null
	 */
	public static function days_since_change( $user_id ) {
		$ts = self::get_changed_at( $user_id );
		if ( ! $ts ) {
			return null;
		}
		return (int) floor( max( 0, time() - $ts ) / DAY_IN_SECONDS );
	}

	/**
	 * Seed "changed now" for all trackable users missing a timestamp.
	 * Call when the feature is first enabled.
	 *
	 * @return int Number of users seeded.
	 */
	public static function seed_missing_timestamps() {
		$users = get_users(
			array(
				'fields' => array( 'ID' ),
				'number' => -1,
			)
		);
		$count = 0;
		$now   = time();
		foreach ( $users as $u ) {
			$id = (int) $u->ID;
			if ( ! self::should_track_user( $id ) ) {
				continue;
			}
			if ( self::get_changed_at( $id ) ) {
				continue;
			}
			update_user_meta( $id, self::META_CHANGED, $now );
			$count++;
		}
		update_option( self::SEEDED_OPTION, 1, false );
		return $count;
	}

	/**
	 * Ensure seed has run once while module is on.
	 */
	public static function maybe_seed() {
		if ( ! self::enabled() ) {
			return;
		}
		if ( get_option( self::SEEDED_OPTION ) ) {
			return;
		}
		self::seed_missing_timestamps();
	}

	/**
	 * Called when module is turned on in settings.
	 *
	 * @param array $old Previous settings.
	 * @param array $new New settings.
	 */
	public static function on_settings_updated( $old, $new ) {
		$was = ! empty( $old['module_password_age'] );
		$now = ! empty( $new['module_password_age'] );
		if ( $now && ! $was ) {
			delete_option( self::SEEDED_OPTION );
			self::seed_missing_timestamps();
		}
	}

	public static function on_password_set( $password, $user_id ) {
		unset( $password );
		self::set_changed_at( (int) $user_id );
	}

	/**
	 * @param WP_User $user User.
	 */
	public static function on_password_reset( $user ) {
		if ( $user instanceof WP_User ) {
			self::set_changed_at( (int) $user->ID );
		}
	}

	/**
	 * Fallback if password updated without wp_set_password in older paths.
	 *
	 * @param int     $user_id       User ID.
	 * @param WP_User $old_user_data Old data.
	 */
	public static function on_profile_update( $user_id, $old_user_data ) {
		$user = get_userdata( $user_id );
		if ( ! $user || ! $old_user_data ) {
			return;
		}
		if ( ! empty( $user->user_pass ) && ! empty( $old_user_data->user_pass ) && $user->user_pass !== $old_user_data->user_pass ) {
			// Prefer not double-writing if wp_set_password already ran moments ago.
			$ts = self::get_changed_at( $user_id );
			if ( ! $ts || ( time() - $ts ) > 5 ) {
				self::set_changed_at( $user_id );
			}
		}
	}

	/**
	 * @param int $user_id New user.
	 */
	public static function on_user_register( $user_id ) {
		if ( self::should_track_user( $user_id ) ) {
			self::set_changed_at( $user_id );
		}
	}

	/**
	 * Overdue admin-access users (password age > 90 days).
	 *
	 * @return array[] {user_id, login, email, display, days, changed_at}
	 */
	public static function overdue_admin_users() {
		if ( ! self::enabled() ) {
			return array();
		}
		self::maybe_seed();

		$admin_roles = array( 'administrator' );
		if ( ! function_exists( 'wp_roles' ) ) {
			require_once ABSPATH . 'wp-includes/capabilities.php';
		}
		foreach ( wp_roles()->roles as $slug => $def ) {
			if ( ! empty( $def['capabilities']['manage_options'] ) ) {
				$admin_roles[] = $slug;
			}
		}
		$admin_roles = array_values( array_unique( $admin_roles ) );

		$users = get_users(
			array(
				'role__in' => $admin_roles,
				'fields'   => array( 'ID', 'user_login', 'user_email', 'display_name' ),
				'number'   => -1,
			)
		);

		// Multisite super admins may not all have manage_options on this site.
		if ( is_multisite() && function_exists( 'get_super_admins' ) ) {
			foreach ( get_super_admins() as $login ) {
				$u = get_user_by( 'login', $login );
				if ( $u ) {
					$users[] = $u;
				}
			}
		}

		$seen = array();
		$out  = array();
		foreach ( $users as $u ) {
			$id = (int) ( is_object( $u ) ? $u->ID : 0 );
			if ( ! $id || isset( $seen[ $id ] ) ) {
				continue;
			}
			$seen[ $id ] = true;

			$user = get_userdata( $id );
			if ( ! $user || ! self::is_admin_access_user( $user ) || ! self::should_track_user( $user ) ) {
				continue;
			}

			$changed = self::get_changed_at( $id );
			if ( ! $changed ) {
				// Should have been seeded; seed individually.
				self::set_changed_at( $id );
				continue;
			}

			$days = (int) floor( ( time() - $changed ) / DAY_IN_SECONDS );
			if ( $days < self::AGE_DAYS ) {
				continue;
			}

			$out[] = array(
				'user_id'    => $id,
				'login'      => $user->user_login,
				'email'      => $user->user_email,
				'display'    => $user->display_name ? $user->display_name : $user->user_login,
				'days'       => $days,
				'changed_at' => $changed,
			);
		}

		usort(
			$out,
			function ( $a, $b ) {
				return $b['days'] <=> $a['days'];
			}
		);

		return $out;
	}

	/**
	 * Daily: email overdue admins (max once every 7 days each).
	 *
	 * @return int Number of reminder emails sent.
	 */
	public static function run_daily_reminders() {
		if ( ! self::enabled() || ! EZM_Settings::get( 'alerts_enabled', 1 ) ) {
			return 0;
		}

		$sent = 0;
		foreach ( self::overdue_admin_users() as $row ) {
			$reminded = absint( get_user_meta( $row['user_id'], self::META_REMINDED, true ) );
			if ( $reminded && ( time() - $reminded ) < ( self::REMIND_DAYS * DAY_IN_SECONDS ) ) {
				continue;
			}
			if ( empty( $row['email'] ) || ! is_email( $row['email'] ) ) {
				continue;
			}
			if ( EZM_Mail::send_password_reminder( $row ) ) {
				update_user_meta( $row['user_id'], self::META_REMINDED, time() );
				$sent++;
			}
		}
		return $sent;
	}
}
