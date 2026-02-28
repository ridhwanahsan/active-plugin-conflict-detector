<?php
/**
 * Admin UI and AJAX handlers.
 *
 * @package Active_Plugin_Conflict_Detector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class APCD_Admin {
	/**
	 * Scanner.
	 *
	 * @var APCD_Scanner
	 */
	private $scanner;

	/**
	 * Exporter.
	 *
	 * @var APCD_Exporter
	 */
	private $exporter;

	/**
	 * Logger.
	 *
	 * @var APCD_Logger
	 */
	private $logger;

	/**
	 * Loader.
	 *
	 * @var APCD_Loader
	 */
	private $loader;

	/**
	 * Construct.
	 *
	 * @param APCD_Scanner $scanner Scanner.
	 * @param APCD_Exporter $exporter Exporter.
	 * @param APCD_Logger   $logger Logger.
	 * @param APCD_Loader   $loader Loader.
	 */
	public function __construct( APCD_Scanner $scanner, APCD_Exporter $exporter, APCD_Logger $logger, APCD_Loader $loader ) {
		$this->scanner  = $scanner;
		$this->exporter = $exporter;
		$this->logger   = $logger;
		$this->loader   = $loader;
		$this->register_hooks();
	}

	/**
	 * Register hooks.
	 */
	private function register_hooks() {
		$this->loader->add_action( 'admin_menu', $this, 'register_menu' );
		$this->loader->add_action( 'admin_enqueue_scripts', $this, 'enqueue_admin_assets' );
		$this->loader->add_action( 'wp_ajax_apcd_run_scan', $this, 'ajax_run_scan' );
		$this->loader->add_action( 'wp_ajax_apcd_get_analysis', $this, 'ajax_get_analysis' );

		// Pings for scanner testing.
		$this->loader->add_action( 'wp_ajax_nopriv_apcd_ping', $this, 'ajax_ping' );
		$this->loader->add_action( 'wp_ajax_apcd_ping', $this, 'ajax_ping' );
		$this->loader->add_action( 'rest_api_init', $this, 'register_rest_routes' );

		$this->exporter->hooks( $this->loader );
		$this->hooks_settings( $this->loader );
	}

	/**
	 * Register admin menu.
	 */
	public function register_menu() {
		add_menu_page(
			__( 'Active Plugin Conflict Detector', 'active-plugin-conflict-detector' ),
			__( 'APCD', 'active-plugin-conflict-detector' ),
			'manage_options',
			'apcd-dashboard',
			array( $this, 'render_dashboard' ),
			'dashicons-warning',
			58
		);
		add_submenu_page(
			'apcd-dashboard',
			__( 'APCD Analysis', 'active-plugin-conflict-detector' ),
			__( 'Analysis', 'active-plugin-conflict-detector' ),
			'manage_options',
			'apcd-analysis',
			array( $this, 'render_analysis' )
		);
		add_submenu_page(
			'apcd-dashboard',
			__( 'APCD Settings', 'active-plugin-conflict-detector' ),
			__( 'Settings', 'active-plugin-conflict-detector' ),
			'manage_options',
			'apcd-settings',
			array( $this, 'render_settings' )
		);
		add_submenu_page(
			'apcd-dashboard',
			__( 'APCD Logs', 'active-plugin-conflict-detector' ),
			__( 'Logs', 'active-plugin-conflict-detector' ),
			'manage_options',
			'apcd-logs',
			array( $this, 'render_logs' )
		);
	}

	/**
	 * Enqueue admin assets.
	 *
	 * @param string $hook Hook suffix.
	 */
	public function enqueue_admin_assets( $hook ) {
		if ( ! in_array( $hook, array( 'toplevel_page_apcd-dashboard', 'apcd_page_apcd-analysis', 'apcd_page_apcd-settings', 'apcd_page_apcd-logs' ), true ) ) {
			return;
		}
		wp_enqueue_style( 'apcd-admin', APCD_PLUGIN_URL . 'assets/css/admin.css', array(), APCD_VERSION );
		wp_enqueue_script( 'apcd-admin', APCD_PLUGIN_URL . 'assets/js/admin.js', array( 'jquery' ), APCD_VERSION, true );
		wp_localize_script(
			'apcd-admin',
			'apcdVars',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'apcd_run_scan' ),
				'strings' => array(
					'scanning' => __( 'Scanning…', 'active-plugin-conflict-detector' ),
					'done'     => __( 'Done', 'active-plugin-conflict-detector' ),
					'error'    => __( 'Error', 'active-plugin-conflict-detector' ),
					'conflictingPlugin' => __( 'Conflicting plugin', 'active-plugin-conflict-detector' ),
					'recentFatalErrors' => __( 'Recent fatal errors', 'active-plugin-conflict-detector' ),
					'targetsStatus' => __( 'Targets status', 'active-plugin-conflict-detector' ),
					'autoMitigateLabel' => __( 'Auto-mitigate (deactivate detected plugin)', 'active-plugin-conflict-detector' ),
				),
				'autoMitigateDefault' => (bool) get_option( 'apcd_auto_mitigate', false ),
			)
		);
	}

	/**
	 * Render dashboard page.
	 */
	public function render_dashboard() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$theme = wp_get_theme();
		?>
		<div class="wrap apcd-wrap">
			<h1><?php echo esc_html__( 'Active Plugin Conflict Detector', 'active-plugin-conflict-detector' ); ?></h1>

			<h2 class="title"><?php echo esc_html__( 'System Overview', 'active-plugin-conflict-detector' ); ?></h2>
			<table class="widefat striped">
				<tbody>
					<tr>
						<th><?php echo esc_html__( 'WordPress version', 'active-plugin-conflict-detector' ); ?></th>
						<td><?php echo esc_html( get_bloginfo( 'version' ) ); ?></td>
					</tr>
					<tr>
						<th><?php echo esc_html__( 'PHP version', 'active-plugin-conflict-detector' ); ?></th>
						<td><?php echo esc_html( PHP_VERSION ); ?></td>
					</tr>
					<tr>
						<th><?php echo esc_html__( 'Active theme', 'active-plugin-conflict-detector' ); ?></th>
						<td><?php echo esc_html( $theme ? $theme->get( 'Name' ) . ' ' . $theme->get( 'Version' ) : '' ); ?></td>
					</tr>
					<tr>
						<th><?php echo esc_html__( 'Total active plugins', 'active-plugin-conflict-detector' ); ?></th>
						<td><?php echo esc_html( count( (array) get_option( 'active_plugins', array() ) ) ); ?></td>
					</tr>
					<tr>
						<th><?php echo esc_html__( 'WP_DEBUG status', 'active-plugin-conflict-detector' ); ?></th>
						<td>
							<?php
							$debug_on = defined( 'WP_DEBUG' ) && WP_DEBUG;
							echo $debug_on ? '<span class="apcd-badge apcd-green">' . esc_html__( 'Enabled', 'active-plugin-conflict-detector' ) . '</span>' : '<span class="apcd-badge apcd-gray">' . esc_html__( 'Disabled', 'active-plugin-conflict-detector' ) . '</span>';
							?>
						</td>
					</tr>
					<tr>
						<th><?php echo esc_html__( 'Server software', 'active-plugin-conflict-detector' ); ?></th>
						<td><?php echo isset( $_SERVER['SERVER_SOFTWARE'] ) ? esc_html( (string) wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : ''; ?></td>
					</tr>
				</tbody>
			</table>

			<h2 class="title" style="margin-top:24px;"><?php echo esc_html__( 'Active Plugins', 'active-plugin-conflict-detector' ); ?></h2>
			<?php $this->render_active_plugins_table(); ?>

			<h2 class="title" style="margin-top:24px;"><?php echo esc_html__( 'Conflict Scan', 'active-plugin-conflict-detector' ); ?></h2>
			<div class="apcd-scan">
				<button id="apcd-run-scan" class="button button-primary">
					<?php echo esc_html__( 'Run Smart Scan', 'active-plugin-conflict-detector' ); ?>
				</button>
				<input type="text" id="apcd-target-url" class="regular-text" placeholder="<?php echo esc_attr__( 'Target URL (optional)', 'active-plugin-conflict-detector' ); ?>" style="margin-left:8px;max-width:320px;">
				<label style="margin-left:8px;">
					<input type="checkbox" id="apcd-auto-mitigate" <?php echo get_option( 'apcd_auto_mitigate', false ) ? 'checked' : ''; ?> />
					<?php echo esc_html__( 'Auto-mitigate (deactivate detected plugin)', 'active-plugin-conflict-detector' ); ?>
				</label>
				<div class="apcd-progress">
					<div class="apcd-progress-bar" id="apcd-progress-bar" style="width:0%"></div>
				</div>
				<div id="apcd-scan-result" class="apcd-scan-result" aria-live="polite"></div>
			</div>

			<h2 class="title" style="margin-top:24px;"><?php echo esc_html__( 'Export', 'active-plugin-conflict-detector' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'apcd_export_snapshot' ); ?>
				<input type="hidden" name="action" value="apcd_export_snapshot" />
				<button type="submit" class="button"><?php echo esc_html__( 'Download Debug Snapshot', 'active-plugin-conflict-detector' ); ?></button>
			</form>
		</div>
		<?php
	}

	/**
	 * Render active plugins table.
	 */
	private function render_active_plugins_table() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$active_plugin_paths = (array) get_option( 'active_plugins', array() );
		$all_plugins         = get_plugins();
		?>
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php echo esc_html__( 'Plugin', 'active-plugin-conflict-detector' ); ?></th>
					<th><?php echo esc_html__( 'Version', 'active-plugin-conflict-detector' ); ?></th>
					<th><?php echo esc_html__( 'Status', 'active-plugin-conflict-detector' ); ?></th>
					<th><?php echo esc_html__( 'Compat', 'active-plugin-conflict-detector' ); ?></th>
					<th><?php echo esc_html__( 'Recently Updated', 'active-plugin-conflict-detector' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $active_plugin_paths as $path ) : ?>
					<?php
					$data   = $all_plugins[ $path ] ?? array();
					$name   = $data['Name'] ?? $path;
					$ver    = $data['Version'] ?? '';
					$abs    = WP_PLUGIN_DIR . '/' . $path;
					$mtime  = file_exists( $abs ) ? filemtime( $abs ) : 0;
					$recent = ( $mtime > 0 && ( time() - $mtime ) <= 7 * DAY_IN_SECONDS );
					$rphp   = $data['RequiresPHP'] ?? ( $data['Requires PHP'] ?? '' );
					$rwp    = $data['RequiresWP'] ?? ( $data['Requires'] ?? ( $data['Requires at least'] ?? '' ) );
					$compat = 'ok';
					if ( is_string( $rphp ) && '' !== $rphp && version_compare( PHP_VERSION, $rphp, '<' ) ) {
						$compat = 'bad';
					}
					if ( is_string( $rwp ) && '' !== $rwp && version_compare( get_bloginfo( 'version' ), $rwp, '<' ) ) {
						$compat = 'bad';
					}
					?>
					<tr>
						<td><?php echo esc_html( $name ); ?></td>
						<td><?php echo esc_html( $ver ); ?></td>
						<td><span class="apcd-badge apcd-green"><?php echo esc_html__( 'Active', 'active-plugin-conflict-detector' ); ?></span></td>
						<td>
							<?php
							if ( 'ok' === $compat ) {
								echo '<span class="apcd-badge apcd-green">' . esc_html__( 'OK', 'active-plugin-conflict-detector' ) . '</span>';
							} else {
								echo '<span class="apcd-badge apcd-red">' . esc_html__( 'Potential Issue', 'active-plugin-conflict-detector' ) . '</span>';
							}
							?>
						</td>
						<td>
							<?php
							if ( $recent ) {
								echo '<span class="apcd-badge apcd-orange">' . esc_html__( 'Yes', 'active-plugin-conflict-detector' ) . '</span>';
							} else {
								echo '<span class="apcd-badge apcd-gray">' . esc_html__( 'No', 'active-plugin-conflict-detector' ) . '</span>';
							}
							?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * AJAX: ping endpoint for scanner (admin-ajax).
	 */
	public function ajax_ping() {
		$tok = isset( $_GET['apcd_token'] ) ? sanitize_text_field( (string) wp_unslash( $_GET['apcd_token'] ) ) : '';
		$this->maybe_apply_active_subset_from_request();
		wp_send_json_success(
			array( 'pong' => true ),
			200
		);
	}

	/**
	 * Register REST route for ping.
	 */
	public function register_rest_routes() {
		register_rest_route(
			'apcd/v1',
			'/ping',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'rest_ping' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * REST ping callback.
	 *
	 * @return WP_REST_Response
	 */
	public function rest_ping() {
		$tok = isset( $_GET['apcd_token'] ) ? sanitize_text_field( (string) wp_unslash( $_GET['apcd_token'] ) ) : '';
		$this->maybe_apply_active_subset_from_request();
		return new WP_REST_Response( array( 'pong' => true ), 200 );
	}

	/**
	 * AJAX run scan handler.
	 */
	public function ajax_run_scan() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'active-plugin-conflict-detector' ) ), 403 );
		}
		check_ajax_referer( 'apcd_run_scan', 'nonce' );

		$target_url     = isset( $_POST['target_url'] ) ? esc_url_raw( (string) wp_unslash( $_POST['target_url'] ) ) : '';
		$auto_mitigate  = isset( $_POST['auto_mitigate'] ) ? ( (string) wp_unslash( $_POST['auto_mitigate'] ) === '1' ) : (bool) get_option( 'apcd_auto_mitigate', false );
		$result = $this->scanner->run_smart_scan( $target_url, false );

		$status_label = __( 'Safe', 'active-plugin-conflict-detector' );
		$status_class = 'apcd-green';
		if ( 'warning' === $result['status'] ) {
			$status_label = __( 'Warning', 'active-plugin-conflict-detector' );
			$status_class = 'apcd-orange';
		} elseif ( 'conflict' === $result['status'] ) {
			$status_label = __( 'Conflict Detected', 'active-plugin-conflict-detector' );
			$status_class = 'apcd-red';
		} elseif ( 'error' === $result['status'] ) {
			$status_label = __( 'Error', 'active-plugin-conflict-detector' );
			$status_class = 'apcd-red';
		}

		$data = array(
			'status'          => $result['status'],
			'status_label'    => $status_label,
			'status_class'    => $status_class,
			'conflicting'     => $result['conflicting'] ?? null,
			'conflicting_set' => $result['conflicting_set'] ?? null,
			'baseline'        => $result['baseline'] ?? null,
			'errors_from_log' => $result['errors_from_log'] ?? array(),
		);

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$all_plugins = get_plugins();
		$get_name = static function ( $path ) use ( $all_plugins ) {
			if ( isset( $all_plugins[ $path ] ) && ! empty( $all_plugins[ $path ]['Name'] ) ) {
				return $all_plugins[ $path ]['Name'];
			}
			$base = basename( (string) $path );
			return $base ?: (string) $path;
		};
		if ( ! empty( $data['conflicting'] ) ) {
			$data['conflicting_name'] = $get_name( (string) $data['conflicting'] );
		}
		if ( ! empty( $data['conflicting_set'] ) && is_array( $data['conflicting_set'] ) ) {
			$names = array();
			foreach ( $data['conflicting_set'] as $p ) {
				$names[] = $get_name( (string) $p );
			}
			$data['conflicting_set_names'] = $names;
		}

		if ( $auto_mitigate && in_array( $result['status'], array( 'conflict' ), true ) ) {
			if ( ! function_exists( 'deactivate_plugins' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			$to_deactivate = array();
			if ( ! empty( $result['conflicting_set'] ) && is_array( $result['conflicting_set'] ) ) {
				$to_deactivate = $result['conflicting_set'];
			} elseif ( ! empty( $result['conflicting'] ) ) {
				$to_deactivate = array( $result['conflicting'] );
			}
			$done = array();
			foreach ( $to_deactivate as $pl ) {
				if ( is_string( $pl ) && '' !== $pl ) {
					deactivate_plugins( $pl, false );
					$done[] = $pl;
				}
			}
			if ( ! empty( $done ) ) {
				$data['mitigation'] = array(
					'deactivated' => $done,
				);
			}
		}

		$this->maybe_notify( $data );
		$this->record_scan_history( $data );

		wp_send_json_success( $data, 200 );
	}

	public function hooks_settings( APCD_Loader $loader ) {
		$loader->add_action( 'admin_post_apcd_save_settings', $this, 'handle_save_settings' );
	}

	public function handle_save_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'active-plugin-conflict-detector' ) );
		}
		check_admin_referer( 'apcd_save_settings' );
		$auto   = isset( $_POST['apcd_auto_mitigate'] ) ? true : false;
		$sched  = isset( $_POST['apcd_scan_schedule'] ) ? sanitize_text_field( (string) wp_unslash( $_POST['apcd_scan_schedule'] ) ) : 'daily';
		$wlist  = isset( $_POST['apcd_whitelist'] ) ? (string) wp_unslash( $_POST['apcd_whitelist'] ) : '';
		$blist  = isset( $_POST['apcd_blacklist'] ) ? (string) wp_unslash( $_POST['apcd_blacklist'] ) : '';
		$email  = isset( $_POST['apcd_notify_email'] ) ? sanitize_email( (string) wp_unslash( $_POST['apcd_notify_email'] ) ) : '';
		$hook   = isset( $_POST['apcd_notify_webhook'] ) ? esc_url_raw( (string) wp_unslash( $_POST['apcd_notify_webhook'] ) ) : '';

		update_option( 'apcd_auto_mitigate', $auto, false );
		update_option( 'apcd_scan_schedule', in_array( $sched, array( 'daily', 'weekly' ), true ) ? $sched : 'daily', false );
		update_option( 'apcd_whitelist', $wlist, false );
		update_option( 'apcd_blacklist', $blist, false );
		update_option( 'apcd_notify_email', $email, false );
		update_option( 'apcd_notify_webhook', $hook, false );

		wp_safe_redirect( admin_url( 'admin.php?page=apcd-settings&updated=1' ) );
		exit;
	}

	private function maybe_notify( array $data ) {
		if ( ( $data['status'] ?? '' ) !== 'conflict' ) {
			return;
		}
		$email   = (string) get_option( 'apcd_notify_email', '' );
		$webhook = (string) get_option( 'apcd_notify_webhook', '' );
		$subject = sprintf( '[APCD] Conflict detected: %s', $data['conflicting_name'] ?? ( $data['conflicting'] ?? 'set' ) );
		$body    = wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		if ( is_email( $email ) ) {
			wp_mail( $email, $subject, $body );
		}
		if ( '' !== $webhook ) {
			wp_safe_remote_post(
				$webhook,
				array(
					'headers' => array( 'Content-Type' => 'application/json' ),
					'body'    => $body,
					'timeout' => 10,
				)
			);
		}
	}

	private function record_scan_history( array $data ) {
		$history = get_option( 'apcd_scan_history', array() );
		if ( ! is_array( $history ) ) {
			$history = array();
		}
		$entry = array(
			'ts'         => time(),
			'status'     => $data['status'] ?? '',
			'conflicting'=> $data['conflicting'] ?? '',
			'baseline'   => $data['baseline'] ?? array(),
			'errors_cnt' => is_array( $data['errors_from_log'] ?? null ) ? count( $data['errors_from_log'] ) : 0,
		);
		$history[] = $entry;
		if ( count( $history ) > 50 ) {
			$history = array_slice( $history, -50 );
		}
		update_option( 'apcd_scan_history', $history, false );
	}

	private function get_scan_history() {
		$history = get_option( 'apcd_scan_history', array() );
		return is_array( $history ) ? $history : array();
	}

	public function ajax_get_analysis() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'active-plugin-conflict-detector' ) ), 403 );
		}
		$history = $this->get_scan_history();
		$statuses = array();
		$conflicts = array();
		$errors_over_time = array();
		foreach ( $history as $h ) {
			$s = $h['status'] ?? '';
			$statuses[ $s ] = isset( $statuses[ $s ] ) ? (int) $statuses[ $s ] + 1 : 1;
			$c = $h['conflicting'] ?? '';
			if ( '' !== $c ) {
				$conflicts[ $c ] = isset( $conflicts[ $c ] ) ? (int) $conflicts[ $c ] + 1 : 1;
			}
			$d = gmdate( 'Y-m-d', (int) ( $h['ts'] ?? time() ) );
			$errors_over_time[ $d ] = isset( $errors_over_time[ $d ] ) ? (int) $errors_over_time[ $d ] + (int) ( $h['errors_cnt'] ?? 0 ) : (int) ( $h['errors_cnt'] ?? 0 );
		}
		wp_send_json_success(
			array(
				'statuses' => $statuses,
				'conflicts' => $conflicts,
				'errors_over_time' => $errors_over_time,
			),
			200
		);
	}

	public function render_analysis() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap apcd-wrap" id="apcd-analysis">
			<h1><?php echo esc_html__( 'APCD Analysis', 'active-plugin-conflict-detector' ); ?></h1>
			<div style="display:flex;flex-wrap:wrap;gap:24px;">
				<canvas id="apcd-chart-status" width="420" height="240" style="background:#fff;border:1px solid #ddd;"></canvas>
				<canvas id="apcd-chart-conflicts" width="420" height="240" style="background:#fff;border:1px solid #ddd;"></canvas>
				<canvas id="apcd-chart-errors" width="420" height="240" style="background:#fff;border:1px solid #ddd;"></canvas>
			</div>
			<p><button class="button" id="apcd-refresh-analysis"><?php echo esc_html__( 'Refresh Analysis', 'active-plugin-conflict-detector' ); ?></button></p>
		</div>
		<?php
	}

	public function render_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$auto_mitigate = (bool) get_option( 'apcd_auto_mitigate', false );
		$schedule      = (string) get_option( 'apcd_scan_schedule', 'daily' );
		$whitelist     = (string) get_option( 'apcd_whitelist', '' );
		$blacklist     = (string) get_option( 'apcd_blacklist', '' );
		$email         = (string) get_option( 'apcd_notify_email', '' );
		$webhook       = (string) get_option( 'apcd_notify_webhook', '' );
		?>
		<div class="wrap apcd-wrap" id="apcd-settings">
			<h1><?php echo esc_html__( 'APCD Settings', 'active-plugin-conflict-detector' ); ?></h1>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'apcd_save_settings' ); ?>
				<input type="hidden" name="action" value="apcd_save_settings" />
				<table class="widefat striped">
					<tbody>
						<tr>
							<th><?php echo esc_html__( 'Auto-mitigate', 'active-plugin-conflict-detector' ); ?></th>
							<td><label><input type="checkbox" name="apcd_auto_mitigate" <?php echo $auto_mitigate ? 'checked' : ''; ?> /> <?php echo esc_html__( 'Deactivate detected conflicting plugin(s)', 'active-plugin-conflict-detector' ); ?></label></td>
						</tr>
						<tr>
							<th><?php echo esc_html__( 'Schedule', 'active-plugin-conflict-detector' ); ?></th>
							<td>
								<select name="apcd_scan_schedule">
									<option value="daily" <?php selected( $schedule, 'daily' ); ?>><?php echo esc_html__( 'Daily', 'active-plugin-conflict-detector' ); ?></option>
									<option value="weekly" <?php selected( $schedule, 'weekly' ); ?>><?php echo esc_html__( 'Weekly', 'active-plugin-conflict-detector' ); ?></option>
								</select>
							</td>
						</tr>
						<tr>
							<th><?php echo esc_html__( 'Whitelist (one per line)', 'active-plugin-conflict-detector' ); ?></th>
							<td><textarea name="apcd_whitelist" rows="5" class="large-text"><?php echo esc_textarea( $whitelist ); ?></textarea></td>
						</tr>
						<tr>
							<th><?php echo esc_html__( 'Blacklist (one per line)', 'active-plugin-conflict-detector' ); ?></th>
							<td><textarea name="apcd_blacklist" rows="5" class="large-text"><?php echo esc_textarea( $blacklist ); ?></textarea></td>
						</tr>
						<tr>
							<th><?php echo esc_html__( 'Notify email', 'active-plugin-conflict-detector' ); ?></th>
							<td><input type="email" name="apcd_notify_email" value="<?php echo esc_attr( $email ); ?>" class="regular-text" /></td>
						</tr>
						<tr>
							<th><?php echo esc_html__( 'Webhook URL', 'active-plugin-conflict-detector' ); ?></th>
							<td><input type="url" name="apcd_notify_webhook" value="<?php echo esc_attr( $webhook ); ?>" class="regular-text" /></td>
						</tr>
					</tbody>
				</table>
				<p><button type="submit" class="button button-primary"><?php echo esc_html__( 'Save Settings', 'active-plugin-conflict-detector' ); ?></button></p>
			</form>
		</div>
		<?php
	}

	public function render_logs() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$needle = isset( $_GET['plugin'] ) ? sanitize_text_field( (string) wp_unslash( $_GET['plugin'] ) ) : '';
		$lines  = array();
		$path   = $this->logger->get_debug_log_path();
		if ( $path ) {
			$lines = $this->logger->tail( $path, 200 );
			if ( '' !== $needle ) {
				$lines = array_values( array_filter( $lines, static function ( $l ) use ( $needle ) {
					return false !== stripos( $l, $needle );
				} ) );
			}
		}
		?>
		<div class="wrap apcd-wrap" id="apcd-logs">
			<h1><?php echo esc_html__( 'APCD Logs', 'active-plugin-conflict-detector' ); ?></h1>
			<form method="get">
				<input type="hidden" name="page" value="apcd-logs" />
				<label><?php echo esc_html__( 'Filter by plugin slug/path', 'active-plugin-conflict-detector' ); ?> <input type="text" name="plugin" value="<?php echo esc_attr( $needle ); ?>" class="regular-text" /></label>
				<button class="button"><?php echo esc_html__( 'Filter', 'active-plugin-conflict-detector' ); ?></button>
			</form>
			<pre style="background:#fff;border:1px solid #ddd;padding:12px;max-height:480px;overflow:auto;"><?php echo esc_html( implode( "\n", $lines ) ); ?></pre>
		</div>
		<?php
	}

	/**
	 * If a subset of active plugins is passed in request, apply it via filter for this request only.
	 * Accepts 'subset' param as base64-encoded JSON array of plugin paths or comma-separated string.
	 */
	private function maybe_apply_active_subset_from_request() {
		$subset_raw = isset( $_GET['subset'] ) ? (string) wp_unslash( $_GET['subset'] ) : '';
		if ( '' === $subset_raw ) {
			return;
		}
		$list = array();
		$decoded = base64_decode( $subset_raw, true );
		if ( is_string( $decoded ) && '' !== $decoded ) {
			$json = json_decode( $decoded, true );
			if ( is_array( $json ) ) {
				$list = array_map( 'sanitize_text_field', array_map( 'strval', $json ) );
			}
		}
		if ( empty( $list ) ) {
			$list = array_map( 'sanitize_text_field', array_map( 'trim', explode( ',', $subset_raw ) ) );
		}
		if ( empty( $list ) ) {
			return;
		}
		$list = array_values( array_filter( array_unique( $list ) ) );
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$all = get_plugins();
		$valid_paths = array_keys( $all );
		$list = array_values( array_intersect( $list, $valid_paths ) );
		if ( empty( $list ) ) {
			return;
		}
		add_filter(
			'option_active_plugins',
			static function () use ( $list ) {
				return $list;
			},
			9999
		);
		add_filter(
			'site_option_active_sitewide_plugins',
			static function () {
				return array();
			},
			9999
		);
	}
}
