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
	public function run_smart_scan( $target_url = null, $allow_no_cap = false, $demo_conflict = false, $auto_targets = true ) {
		if ( ! $allow_no_cap && ! current_user_can( 'manage_options' ) ) {
			return array(
				'status'  => 'error',
				'message' => __( 'Unauthorized.', 'active-plugin-conflict-detector' ),
			);
		}

		$active_plugins = (array) get_option( 'active_plugins', array() );
		$baseline       = $this->test_with_subset( $active_plugins, null, $demo_conflict );

		$result = array(
			'status'          => 'safe',
			'conflicting'     => null,
			'baseline'        => $baseline,
			'steps'           => array(),
			'targets_report'  => array(),
			'errors_from_log' => array(),
		);

		$targets = array();
		if ( is_string( $target_url ) && '' !== trim( $target_url ) ) {
			$targets[] = trim( $target_url );
		} elseif ( $auto_targets ) {
			$targets = $this->get_auto_targets();
		}

		$failing_target = null;
		foreach ( $targets as $t ) {
			$rep = $this->test_with_subset( $active_plugins, $t, $demo_conflict );
			$result['targets_report'][ $t ] = array(
				'code' => $rep['target_code'] ?? 0,
				'ok'   => ( 200 === (int) ( $rep['target_code'] ?? 0 ) ),
			);
			if ( 200 !== (int) ( $rep['target_code'] ?? 0 ) && ! $failing_target ) {
				$failing_target = $t;
			}
		}

		if ( ! $baseline['ok'] || $failing_target ) {
			$use_target = $failing_target ? $failing_target : ( $targets[0] ?? null );
			$conflict   = $this->binary_isolate( $active_plugins, $use_target, $demo_conflict );
			if ( $conflict ) {
				$confirmed = $this->confirm_conflict( $conflict, $use_target, $active_plugins, $demo_conflict );
				$result['status'] = 'conflict';
				if ( is_array( $confirmed ) ) {
					$result['conflicting_set'] = $confirmed;
				} else {
					$result['conflicting'] = $confirmed;
				}
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
	private function binary_isolate( array $plugins, $target_url = null, $demo_conflict = false ) {
		$plugins = array_values( array_unique( $plugins ) );
		$self    = defined( 'APCD_PLUGIN_BASENAME' ) ? APCD_PLUGIN_BASENAME : plugin_basename( APCD_PLUGIN_FILE );
		$plugins = array_values(
			array_filter(
				$plugins,
				static function ( $p ) use ( $self ) {
					return (string) $p !== (string) $self;
				}
			)
		);
		if ( count( $plugins ) <= 1 ) {
			return isset( $plugins[0] ) ? $plugins[0] : null;
		}
		$left  = array_slice( $plugins, 0, (int) floor( count( $plugins ) / 2 ) );
		$right = array_slice( $plugins, count( $left ) );

		$left_ok  = $this->test_with_subset( array_merge( $left, array( $self ) ), $target_url, $demo_conflict );
		$right_ok = $this->test_with_subset( array_merge( $right, array( $self ) ), $target_url, $demo_conflict );

		if ( ! $left_ok['ok'] && count( $left ) > 0 ) {
			return $this->binary_isolate( $left, $target_url, $demo_conflict );
		}
		if ( ! $right_ok['ok'] && count( $right ) > 0 ) {
			return $this->binary_isolate( $right, $target_url, $demo_conflict );
		}

		foreach ( $plugins as $p ) {
			$res = $this->test_with_subset( array( $p, $self ), $target_url, $demo_conflict );
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
	 * @return array{ok:bool, code:int, ajax_code:int, rest_code:int, target_code:int, errors:array}
	 */
	private function test_with_subset( array $subset, $target_url = null, $demo_conflict = false ) {
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
		$token        = wp_create_nonce( 'apcd_ping_token' );
		$args = array(
			'subset'     => $subset_param,
			'apcd_token' => $token,
		);
		if ( $demo_conflict ) {
			$args['apcd_demo_conflict'] = '1';
		}
		$ajax_ping_url = add_query_arg( $args, admin_url( 'admin-ajax.php?action=apcd_ping' ) );
		$rest_route    = add_query_arg( $args, rest_url( 'apcd/v1/ping' ) );

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
		$target_code = 0;
		if ( is_string( $target_url ) && '' !== trim( $target_url ) ) {
			$target        = trim( $target_url );
			$subset_has_wc = in_array( 'woocommerce/woocommerce.php', $subset, true );
			$wc_links      = array();
			if ( function_exists( 'wc_get_page_id' ) ) {
				foreach ( array( 'shop', 'cart', 'checkout', 'myaccount' ) as $slug ) {
					$id = (int) wc_get_page_id( $slug );
					if ( $id && $id > 0 ) {
						$link = get_permalink( $id );
						if ( is_string( $link ) && '' !== $link ) {
							$wc_links[] = untrailingslashit( $link );
						}
					}
				}
			} else {
				foreach ( array( 'woocommerce_shop_page_id', 'woocommerce_cart_page_id', 'woocommerce_checkout_page_id', 'woocommerce_myaccount_page_id' ) as $opt ) {
					$id = (int) get_option( $opt, 0 );
					if ( $id && $id > 0 ) {
						$link = get_permalink( $id );
						if ( is_string( $link ) && '' !== $link ) {
							$wc_links[] = untrailingslashit( $link );
						}
					}
				}
			}
			$norm_target = untrailingslashit( $target );
			$target_depends_wc = in_array( $norm_target, $wc_links, true );

			if ( $target_depends_wc && ! $subset_has_wc ) {
				$target_code = 200;
			} else {
				if ( 0 === strpos( $target, '/' ) ) {
					$target = home_url( $target );
				}
				$target = add_query_arg( $args, $target );
				$target_resp = wp_safe_remote_get(
					$target,
					array(
						'timeout' => 10,
						'headers' => array( 'Accept' => 'application/json' ),
					)
				);
				$target_code = is_wp_error( $target_resp ) ? 0 : (int) wp_remote_retrieve_response_code( $target_resp );
			}
		}

		restore_error_handler();
		remove_filter( 'option_active_plugins', $filter, 9999 );
		remove_filter( 'site_option_active_sitewide_plugins', $multisite_filter, 9999 );

		$ajax_code = is_wp_error( $ajax_resp ) ? 0 : (int) wp_remote_retrieve_response_code( $ajax_resp );
		$rest_code = is_wp_error( $rest_resp ) ? 0 : (int) wp_remote_retrieve_response_code( $rest_resp );
		$ok        = ( 200 === $ajax_code && 200 === $rest_code && ( is_string( $target_url ) && '' !== trim( $target_url ) ? ( 200 === $target_code ) : true ) );

		return array(
			'ok'        => $ok,
			'code'      => $ok ? 200 : 500,
			'ajax_code' => $ajax_code,
			'rest_code' => $rest_code,
			'target_code' => $target_code,
			'errors'    => $errors,
		);
	}

	public function run_smart_scan_cron() {
		return $this->run_smart_scan( null, true, false, true );
	}

	private function get_auto_targets() {
		$targets = array();
		$home    = home_url( '/' );
		if ( is_string( $home ) && '' !== $home ) {
			$targets[] = $home;
		}
		$front_id = (int) get_option( 'page_on_front', 0 );
		$posts_id = (int) get_option( 'page_for_posts', 0 );
		if ( $front_id ) {
			$targets[] = get_permalink( $front_id );
		}
		if ( $posts_id ) {
			$targets[] = get_permalink( $posts_id );
		}

		$wc_pages = array();
		if ( function_exists( 'wc_get_page_id' ) ) {
			foreach ( array( 'shop', 'cart', 'checkout', 'myaccount' ) as $slug ) {
				$id = (int) wc_get_page_id( $slug );
				if ( $id && $id > 0 ) {
					$wc_pages[] = $id;
				}
			}
		} else {
			foreach ( array( 'woocommerce_shop_page_id', 'woocommerce_cart_page_id', 'woocommerce_checkout_page_id', 'woocommerce_myaccount_page_id' ) as $opt ) {
				$id = (int) get_option( $opt, 0 );
				if ( $id && $id > 0 ) {
					$wc_pages[] = $id;
				}
			}
		}
		foreach ( array_unique( $wc_pages ) as $pid ) {
			$plink = get_permalink( $pid );
			if ( is_string( $plink ) && '' !== $plink ) {
				$targets[] = $plink;
			}
		}

		$targets = array_values( array_unique( array_filter( $targets ) ) );
		return $targets;
	}

	private function get_dependency_plugins_for_target( $target_url ) {
		$deps = array();
		if ( is_string( $target_url ) && '' !== $target_url ) {
			$t = strtolower( $target_url );
			if ( false !== strpos( $t, 'shop' ) || false !== strpos( $t, 'cart' ) || false !== strpos( $t, 'checkout' ) || false !== strpos( $t, 'my-account' ) || false !== strpos( $t, 'myaccount' ) ) {
				$deps[] = 'woocommerce/woocommerce.php';
			}
		}
		return array_values( array_unique( $deps ) );
	}

	private function confirm_conflict( $conflict, $target_url, array $active_plugins, $demo_conflict = false ) {
		$self     = defined( 'APCD_PLUGIN_BASENAME' ) ? APCD_PLUGIN_BASENAME : plugin_basename( APCD_PLUGIN_FILE );
		$deps     = $this->get_dependency_plugins_for_target( $target_url );
		$base_set = array_merge( array( $self ), $deps );
		$test1    = $this->test_with_subset( array_merge( array( $conflict ), $base_set ), $target_url, $demo_conflict );
		if ( ! empty( $test1['ok'] ) && true === $test1['ok'] ) {
			foreach ( $active_plugins as $p ) {
				if ( $p === $conflict || $p === $self ) {
					continue;
				}
				$pair = array_merge( array( $conflict, $p ), $base_set );
				$res  = $this->test_with_subset( $pair, $target_url, $demo_conflict );
				if ( empty( $res['ok'] ) || true !== $res['ok'] ) {
					return array( $conflict, $p );
				}
			}
			return $conflict;
		}
		return $conflict;
	}
}

