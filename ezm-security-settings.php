<?php
/**
 * Plugin Name: EZ Maintenance & Security Helper
 * Description: A tool to help identify disk space issues, hidden errors affecting your site, reporting features.
 * Version: 2.0.7
 * Author: EZMarketing
 * Text Domain: ezm-security-settings
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'EZM_SECURITY_VERSION', '2.0.7' );
define( 'EZM_SECURITY_FILE', __FILE__ );
define( 'EZM_SECURITY_PATH', plugin_dir_path( __FILE__ ) );
define( 'EZM_SECURITY_URL', plugin_dir_url( __FILE__ ) );
define( 'EZM_SECURITY_SLUG', 'ezm-security-settings' );

require_once EZM_SECURITY_PATH . 'plugin-update-checker/plugin-update-checker.php';

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

$ezm_security_update_checker = PucFactory::buildUpdateChecker(
	'https://github.com/ezmarketing/ezm-security-settings/',
	__FILE__,
	'ezm-security-settings'
);
$ezm_security_update_checker->getVcsApi()->enableReleaseAssets();
add_filter( 'puc_manual_check_link-ezm-security-settings', '__return_empty_string' );

require_once EZM_SECURITY_PATH . 'includes/class-ezm-db.php';
require_once EZM_SECURITY_PATH . 'includes/class-ezm-settings.php';
require_once EZM_SECURITY_PATH . 'includes/class-ezm-security.php';
require_once EZM_SECURITY_PATH . 'includes/class-ezm-disk.php';
require_once EZM_SECURITY_PATH . 'includes/class-ezm-logs.php';
require_once EZM_SECURITY_PATH . 'includes/class-ezm-media.php';
require_once EZM_SECURITY_PATH . 'includes/class-ezm-uploads-safety.php';
require_once EZM_SECURITY_PATH . 'includes/class-ezm-audit.php';
require_once EZM_SECURITY_PATH . 'includes/class-ezm-password-age.php';
require_once EZM_SECURITY_PATH . 'includes/class-ezm-mail.php';
require_once EZM_SECURITY_PATH . 'includes/class-ezm-admin.php';
require_once EZM_SECURITY_PATH . 'includes/class-ezm-plugin.php';

register_activation_hook( __FILE__, array( 'EZM_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'EZM_Plugin', 'deactivate' ) );

EZM_Plugin::instance();
