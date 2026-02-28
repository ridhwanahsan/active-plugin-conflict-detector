<?php
/**
 * Exporter for creating a JSON debug snapshot.
 *
 * @package Active_Plugin_Conflict_Detector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class APCD_Exporter {
	/**
	 * Logger.
	 *
	 * @var APCD_Logger
	 */
	private $logger;

	/**
	 * Construct.
	 *
	 * @param APCD_Logger $logger Logger.
	 */
	public function __construct( APCD_Logger $logger ) {
		$this->logger = $logger;
	}

	/**
	 * Register action hook for export.
	 */
	public function hooks( APCD_Loader $loader ) {
		$loader->add_action( 'admin_post_apcd_export_snapshot', $this, 'handle_export' );
	}

	/**
	 * Handle export: download JSON file with site info, active plugins, last 100 log lines.
	 */
	public function handle_export() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'active-plugin-conflict-detector' ) );
		}
		check_admin_referer( 'apcd_export_snapshot' );

		$site = array(
			'wordpress'      => get_bloginfo( 'version' ),
			'php'            => PHP_VERSION,
			'theme'          => wp_get_theme() ? wp_get_theme()->get( 'Name' ) . ' ' . wp_get_theme()->get( 'Version' ) : '',
			'wp_debug'       => ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ? true : false,
			'server'         => isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['SERVER_SOFTWARE'] ) ) : '',
			'timestamp_utc'  => gmdate( 'c' ),
			'text_domain'    => 'active-plugin-conflict-detector',
			'plugin_version' => APCD_VERSION,
		);

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$all_plugins    = get_plugins();
		$active_plugins = (array) get_option( 'active_plugins', array() );
		$plugins_data   = array();
		foreach ( $active_plugins as $path ) {
			if ( isset( $all_plugins[ $path ] ) ) {
				$plugins_data[] = array(
					'path'    => $path,
					'name'    => $all_plugins[ $path ]['Name'] ?? '',
					'version' => $all_plugins[ $path ]['Version'] ?? '',
				);
			} else {
				$plugins_data[] = array(
					'path'    => $path,
					'name'    => '',
					'version' => '',
				);
			}
		}

		$log_lines = array();
		$log_path  = $this->logger->get_debug_log_path();
		if ( $log_path ) {
			$log_lines = $this->logger->tail( $log_path, 100 );
		}

		$payload = array(
			'site'           => $site,
			'active_plugins' => $plugins_data,
			'error_logs'     => $log_lines,
		);

		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=apcd-debug-snapshot-' . gmdate( 'Ymd-His' ) . '.json' );
		echo wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		exit;
	}
}

