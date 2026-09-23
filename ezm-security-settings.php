<?php
/*
Plugin Name: EZM WP Security Features
Description: Additional security for WP users
Version: 1.1.1
Author: EZMarketing
*/

require_once __DIR__ . '/plugin-update-checker/plugin-update-checker.php';

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

$ezm_security_update_checker = PucFactory::buildUpdateChecker(
	'https://github.com/ezmarketing/ezm-security-settings/',
	__FILE__,
	'ezm-security-settings'
);

// Prefer the zip attached to the GitHub Release (browser-friendly publish path).
$ezm_security_update_checker->getVcsApi()->enableReleaseAssets();

// Hide the "Check for updates" plugin-row link (automatic checks still run).
add_filter( 'puc_manual_check_link-ezm-security-settings', '__return_empty_string' );

/**
 * Credential errors that reveal whether a username or email exists.
 *
 * @return string[]
 */
function ezm_security_enumeration_codes() {
	return array(
		'incorrect_password',
		'invalid_username',
		'invalid_email',
	);
}

/**
 * Generic login failure. Same text for a bad password and an unknown account.
 *
 * @return string
 */
function ezm_security_generic_login_message() {
	return '<strong>' . esc_html__( 'Error:', 'ez-theme-function' ) . '</strong> ' . esc_html__( 'Invalid login credentials.', 'ez-theme-function' );
}

/**
 * Replace enumeration errors on the WP_Error, and keep unrelated codes.
 *
 * @param WP_Error $errors Login errors.
 * @return WP_Error
 */
function ezm_security_obscure_login_error_codes( $errors ) {
	$matched = array_intersect( ezm_security_enumeration_codes(), $errors->get_error_codes() );
	if ( empty( $matched ) ) {
		return $errors;
	}

	$generic = new WP_Error( 'invalid_login', ezm_security_generic_login_message() );
	foreach ( $errors->get_error_codes() as $code ) {
		if ( in_array( $code, $matched, true ) ) {
			continue;
		}
		foreach ( $errors->get_error_messages( $code ) as $message ) {
			$generic->add( $code, $message );
		}
	}

	return $generic;
}

/**
 * WooCommerce My Account calls wp_signon() and prints get_error_message().
 * That path never applies wp_login_errors, so a wrong password still names the account.
 */
add_filter( 'authenticate', function( $user ) {
	if ( ! is_wp_error( $user ) ) {
		return $user;
	}

	return ezm_security_obscure_login_error_codes( $user );
}, 100 );

/**
 * wp-login.php applies this to the error object before render.
 */
add_filter( 'wp_login_errors', function( $errors ) {
	if ( ! is_wp_error( $errors ) ) {
		return $errors;
	}

	return ezm_security_obscure_login_error_codes( $errors );
} );

/**
 * WooCommerce also passes the message string through login_errors.
 * Recaptcha, empty fields, and cookie errors do not match these phrases.
 */
add_filter( 'login_errors', function( $error ) {
	if ( ! is_string( $error ) || '' === $error ) {
		return $error;
	}

	$leaks = array(
		'The password you entered for the',
		'is not registered on this site',
		'Unknown email address',
		'Unknown username',
	);

	foreach ( $leaks as $leak ) {
		if ( false !== strpos( $error, $leak ) ) {
			return ezm_security_generic_login_message();
		}
	}

	return $error;
} );

// Restrict API access
add_filter( 'rest_authentication_errors', function( $result ) {
	// Respect any existing authentication errors
	if ( ! empty( $result ) ) {
		return $result;
	}

	// Get the requested route
	$requested_route = $_SERVER['REQUEST_URI'] ?? '';

	// Define restricted endpoints
	$restricted_endpoints = array(
		'/wp/v2/users',
		'/wp/v2/settings',
	);

	foreach ( $restricted_endpoints as $endpoint ) {
		if ( strpos( $requested_route, $endpoint ) !== false ) {
			if ( ! is_user_logged_in() || ! current_user_can( 'administrator' ) ) {
				return new WP_Error(
					'rest_forbidden',
					__( 'Access to this endpoint is restricted.', 'ez-theme-function' ),
					array( 'status' => 403 )
				);
			}
		}
	}

	return $result;
} );
