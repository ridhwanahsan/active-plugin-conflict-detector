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

		// Pings for scanner testing.
		$this->loader->add_action( 'wp_ajax_nopriv_apcd_ping', $this, 'ajax_ping' );
		$this->loader->add_action( 'wp_ajax_apcd_ping', $this, 'ajax_ping' );
		$this->loader->add_action( 'rest_api_init', $this, 'register_rest_routes' );

		$this->exporter->hooks( $this->loader );
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
	}

	/**
	 * Enqueue admin assets.
	 *
	 * @param string $hook Hook suffix.
	 */
	public function enqueue_admin_assets( $hook ) {
		if ( 'toplevel_page_apcd-dashboard' !== $hook ) {
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
				),
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
					?>
					<tr>
						<td><?php echo esc_html( $name ); ?></td>
						<td><?php echo esc_html( $ver ); ?></td>
						<td><span class="apcd-badge apcd-green"><?php echo esc_html__( 'Active', 'active-plugin-conflict-detector' ); ?></span></td>
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

		$result = $this->scanner->run_smart_scan();

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
			'baseline'        => $result['baseline'] ?? null,
			'errors_from_log' => $result['errors_from_log'] ?? array(),
		);

		wp_send_json_success( $data, 200 );
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
