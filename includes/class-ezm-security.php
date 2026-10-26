<?php
/**
 * Core security hardening (login enumeration + REST restrictions).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EZM_Security {

	public static function init() {
		add_filter( 'wp_login_errors', array( __CLASS__, 'obscure_login_errors' ) );
		add_filter( 'rest_authentication_errors', array( __CLASS__, 'restrict_rest' ) );
	}

	/**
	 * Obscure only credential errors that enable username enumeration.
	 *
	 * @param mixed $errors Login errors.
	 * @return mixed
	 */
	public static function obscure_login_errors( $errors ) {
		if ( ! is_wp_error( $errors ) ) {
			return $errors;
		}

		$enumeration_codes = array(
			'incorrect_password',
			'invalid_username',
			'invalid_email',
		);

		$matched = array_intersect( $enumeration_codes, $errors->get_error_codes() );
		if ( empty( $matched ) ) {
			return $errors;
		}

		foreach ( $matched as $code ) {
			$errors->remove( $code );
		}

		$errors->add(
			'invalid_login',
			__( 'Invalid login credentials.', 'ezm-security-settings' )
		);

		return $errors;
	}

	/**
	 * Restrict sensitive REST endpoints for non-administrators.
	 *
	 * @param mixed $result Existing auth result.
	 * @return mixed
	 */
	public static function restrict_rest( $result ) {
		if ( ! empty( $result ) ) {
			return $result;
		}

		$requested_route = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';

		$restricted_endpoints = array(
			'/wp/v2/users',
			'/wp/v2/settings',
		);

		foreach ( $restricted_endpoints as $endpoint ) {
			if ( false !== strpos( $requested_route, $endpoint ) ) {
				if ( ! is_user_logged_in() || ! current_user_can( 'administrator' ) ) {
					return new WP_Error(
						'rest_forbidden',
						__( 'Access to this endpoint is restricted.', 'ezm-security-settings' ),
						array( 'status' => 403 )
					);
				}
			}
		}

		return $result;
	}
}
