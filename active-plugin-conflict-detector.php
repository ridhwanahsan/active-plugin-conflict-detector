<?php
/**
 * Plugin Name:       Active Plugin Conflict Detector
 * Description:       Detects active plugin conflicts safely using a smart, non-intrusive binary scan. Includes system overview, active plugins table, and exportable debug snapshot.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            ridhwanahsann
 * Contributors:      ridhwanahsann
 * Text Domain:       active-plugin-conflict-detector
 * Domain Path:       /languages
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package Active_Plugin_Conflict_Detector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'APCD_VERSION' ) ) {
	define( 'APCD_VERSION', '1.0.0' );
}

if ( ! defined( 'APCD_PLUGIN_FILE' ) ) {
	define( 'APCD_PLUGIN_FILE', __FILE__ );
}

if ( ! defined( 'APCD_PLUGIN_BASENAME' ) ) {
	define( 'APCD_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
}

if ( ! defined( 'APCD_PLUGIN_DIR' ) ) {
	define( 'APCD_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
}

if ( ! defined( 'APCD_PLUGIN_URL' ) ) {
	define( 'APCD_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
}

// Autoload includes.
require_once APCD_PLUGIN_DIR . 'includes/class-apcd-loader.php';
require_once APCD_PLUGIN_DIR . 'includes/class-apcd-logger.php';
require_once APCD_PLUGIN_DIR . 'includes/class-apcd-scanner.php';
require_once APCD_PLUGIN_DIR . 'includes/class-apcd-exporter.php';
require_once APCD_PLUGIN_DIR . 'includes/class-apcd-admin.php';

/**
 * Bootstrap the plugin.
 */
function apcd_run() {
	$loader  = new APCD_Loader();
	$logger  = new APCD_Logger();
	$scanner = new APCD_Scanner( $logger );
	$export  = new APCD_Exporter( $logger );
	$admin   = new APCD_Admin( $scanner, $export, $logger, $loader );

	// Load translations.
	add_action(
		'plugins_loaded',
		static function () {
			load_plugin_textdomain( 'active-plugin-conflict-detector', false, dirname( APCD_PLUGIN_BASENAME ) . '/languages' );
		}
	);

	$loader->run();
}

apcd_run();

/**
 * Activation hook.
 */
function apcd_activate() {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}
}
register_activation_hook( __FILE__, 'apcd_activate' );

/**
 * Deactivation hook.
 */
function apcd_deactivate() {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}
}
register_deactivation_hook( __FILE__, 'apcd_deactivate' );

