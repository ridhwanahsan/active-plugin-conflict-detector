<?php
/**
 * Smart scanner using divide-and-conquer to identify conflicting plugins.
 *
 * @package Active_Plugin_Conflict_Detector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class APCD_Scanner {
	/**
	 * Logger.
	 *
	 * @var APCD_Logger
	 */
	private $logger;

	/**
	 * Construct.
	 *
	 * @param APCD_Logger $logger Logger instance.
	 */
	public function __construct( APCD_Logger $logger ) {
		$this->logger = $logger;
	}

	/**
	 * Run the smart scan.
	 *
	 * @return array<string,mixed>
	 */
	public function run_smart_scan() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return array(
				'status'  => 'error',
				'message' => __( 'Unauthorized.', 'active-plugin-conflict-detector' ),
			);
		}

		$active_plugins = (array) get_option( 'active_plugins', array() );
		$baseline       = $this->test_with_subset( $active_plugins );

		$result = array(
			'status'          => 'safe',
			'conflicting'     => null,
			'baseline'        => $baseline,
			'steps'           => array(),
			'errors_from_log' => array(),
		);

		if ( ! $baseline['ok'] ) {
			$conflict = $this->binary_isolate( $active_plugins );
			if ( $conflict ) {
				$result['status']      = 'conflict';
				$result['conflicting'] = $conflict;
			} else {
				$result['status'] = 'warning';
			}
		} else {
			$log_path = $this->logger->get_debug_log_path();
			if ( $log_path ) {
				$lines = $this->logger->tail( $log_path, 100 );
				$errs  = $this->logger->extract_fatal_errors( $lines );
				if ( ! empty( $errs ) ) {
					$result['status']          = 'warning';
					$result['errors_from_log'] = $errs;
				}
			}
		}

		return $result;
	}

	/**
	 * Binary isolate a conflicting plugin by testing subsets.
	 *
	 * @param string[] $plugins Active plugin list (paths).
	 * @return string|null Conflicting plugin file path relative to plugins dir.
	 */
	private function binary_isolate( array $plugins ) {
		$plugins = array_values( array_unique( $plugins ) );
		if ( count( $plugins ) <= 1 ) {
			return isset( $plugins[0] ) ? $plugins[0] : null;
		}
		$left  = array_slice( $plugins, 0, (int) floor( count( $plugins ) / 2 ) );
		$right = array_slice( $plugins, count( $left ) );

		$left_ok  = $this->test_with_subset( $left );
		$right_ok = $this->test_with_subset( $right );

		if ( ! $left_ok['ok'] && count( $left ) > 0 ) {
			return $this->binary_isolate( $left );
		}
		if ( ! $right_ok['ok'] && count( $right ) > 0 ) {
			return $this->binary_isolate( $right );
		}

		foreach ( $plugins as $p ) {
			$res = $this->test_with_subset( array( $p ) );
			if ( ! $res['ok'] ) {
				return $p;
			}
		}
		return null;
	}

	/**
	 * Test a WordPress health subset by simulating only a given set of active plugins for the current request.
	 * Visitors are unaffected because this uses a runtime filter.
	 *
	 * @param string[] $subset Plugin paths relative to plugins dir.
	 * @return array{ok:bool, code:int, ajax_code:int, rest_code:int, errors:array}
	 */
	private function test_with_subset( array $subset ) {
		$subset = array_values( array_unique( $subset ) );
		/**
		 * Filter to override the active_plugins option for the duration of this test request only.
		 */
		$filter = static function ( $value ) use ( $subset ) {
			return $subset;
		};
		add_filter( 'option_active_plugins', $filter, 9999 );
		$multisite_filter = static function () {
			return array();
		};
		add_filter( 'site_option_active_sitewide_plugins', $multisite_filter, 9999 );

		$errors = array();
		set_error_handler(
			static function ( $severity, $message, $file, $line ) use ( &$errors ) {
				$errors[] = compact( 'severity', 'message', 'file', 'line' );
			}
		);
		register_shutdown_function(
			static function () use ( &$errors ) {
				$e = error_get_last();
				if ( $e && in_array( $e['type'], array( E_ERROR, E_PARSE, E_USER_ERROR, E_COMPILE_ERROR ), true ) ) {
					$errors[] = $e;
				}
			}
		);

		$subset_param = rawurlencode( base64_encode( wp_json_encode( $subset ) ) );
		$ajax_ping_url = add_query_arg( 'subset', $subset_param, admin_url( 'admin-ajax.php?action=apcd_ping' ) );
		$rest_route    = add_query_arg( 'subset', $subset_param, rest_url( 'apcd/v1/ping' ) );

		$ajax_resp = wp_safe_remote_get(
			$ajax_ping_url,
			array(
				'timeout' => 10,
				'headers' => array( 'Accept' => 'application/json' ),
			)
		);
		$rest_resp = wp_safe_remote_get(
			$rest_route,
			array(
				'timeout' => 10,
				'headers' => array( 'Accept' => 'application/json' ),
			)
		);

		restore_error_handler();
		remove_filter( 'option_active_plugins', $filter, 9999 );
		remove_filter( 'site_option_active_sitewide_plugins', $multisite_filter, 9999 );

		$ajax_code = is_wp_error( $ajax_resp ) ? 0 : (int) wp_remote_retrieve_response_code( $ajax_resp );
		$rest_code = is_wp_error( $rest_resp ) ? 0 : (int) wp_remote_retrieve_response_code( $rest_resp );
		$ok        = ( 200 === $ajax_code && 200 === $rest_code );

		return array(
			'ok'        => $ok,
			'code'      => $ok ? 200 : 500,
			'ajax_code' => $ajax_code,
			'rest_code' => $rest_code,
			'errors'    => $errors,
		);
	}
}

