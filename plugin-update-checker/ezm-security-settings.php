<?php
/*
Plugin Name: EZM WP Security Features
Description: Additional security for WP users
Version: 1.1
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
 * Obscure only credential errors that enable username enumeration.
 * Keeps other login errors (reCAPTCHA, empty fields, etc.) intact.
 */
add_filter( 'wp_login_errors', function( $errors ) {
	if ( ! is_wp_error( $errors ) ) {
		return $errors;
	}

	// These codes reveal whether a username/email exists.
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
		__( 'Invalid login credentials.', 'ez-theme-function' )
	);

	return $errors;
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
