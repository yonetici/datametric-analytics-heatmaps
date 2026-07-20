<?php
namespace DataMetric\Admin;

use DataMetric\Includes\Api;
use DataMetric\Includes\Database;
use WP_REST_Response;

/**
 * Admin Panel controls and REST API handlers.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Admin {

	private $plugin_name;
	private $version;

	public function __construct( $plugin_name, $version ) {
		$this->plugin_name = $plugin_name;
		$this->version = $version;
	}

	public function init_hooks() {
		// Admin Menu
		add_action( 'admin_menu', array( $this, 'add_plugin_admin_menu' ) );
		// Register Admin Assets
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_styles_and_scripts' ) );
		// Register REST API Endpoints
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
		// Register Settings API
		add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	/**
	 * Register settings under WordPress Settings API.
	 */
	public function register_settings() {
		register_setting( 'datametric_settings_group', 'datametric_demo_mode', array(
			'type'              => 'string',
			'default'           => 'yes',
			'sanitize_callback' => 'sanitize_text_field',
		) );
		register_setting( 'datametric_settings_group', 'datametric_property_id', array(
			'type'              => 'string',
			'default'           => '',
			'sanitize_callback' => 'sanitize_text_field',
		) );
		register_setting( 'datametric_settings_group', 'datametric_credentials', array(
			'type'              => 'string',
			'default'           => '',
			'sanitize_callback' => array( $this, 'sanitize_and_encrypt_credentials' ),
		) );
		// Sampling rate option to prevent database table bloat
		register_setting( 'datametric_settings_group', 'datametric_sampling_rate', array(
			'type'              => 'string',
			'default'           => '100',
			'sanitize_callback' => 'sanitize_text_field',
		) );
		// Require consent option for GDPR compliance
		register_setting( 'datametric_settings_group', 'datametric_require_consent', array(
			'type'              => 'string',
			'default'           => 'yes',
			'sanitize_callback' => 'sanitize_text_field',
		) );
		// Whether to delete all stored data (table + options) when the plugin is uninstalled.
		register_setting( 'datametric_settings_group', 'datametric_delete_data', array(
			'type'              => 'string',
			'default'           => 'no',
			'sanitize_callback' => 'sanitize_text_field',
		) );
	}

	/**
	 * Settings callback to validate JSON and encrypt private key.
	 */
	public function sanitize_and_encrypt_credentials( $value ) {
		$value = wp_unslash( trim( $value ) );
		if ( empty( $value ) ) {
			return '';
		}

		$decoded = json_decode( $value, true );
		if ( json_last_error() !== JSON_ERROR_NONE ) {
			// If it's already encrypted, keep it
			$decrypted = Api::decrypt_data( $value );
			if ( ! empty( $decrypted ) && null !== json_decode( $decrypted ) ) {
				return $value;
			}
			add_settings_error( 'datametric_credentials', 'invalid_json', __( 'Invalid JSON format.', 'datametric-analytics-heatmaps' ) );
			return get_option( 'datametric_credentials', '' );
		}

		// Preserve actual key if user submitted placeholder masking sentinel pattern
		if ( isset( $decoded['private_key'] ) && $decoded['private_key'] === '___DATAMETRIC_MASKED_KEY___' ) {
			$existing_encrypted = get_option( 'datametric_credentials', '' );
			if ( ! empty( $existing_encrypted ) ) {
				$existing_raw = Api::decrypt_data( $existing_encrypted );
				$existing_decoded = json_decode( $existing_raw, true );
				if ( is_array( $existing_decoded ) && isset( $existing_decoded['private_key'] ) ) {
					$decoded['private_key'] = $existing_decoded['private_key'];
					$value = wp_json_encode( $decoded );
				}
			}
		}

		// Save encrypted JSON
		return Api::encrypt_data( $value );
	}

	/**
	 * Register Admin Menu Page.
	 */
	public function add_plugin_admin_menu() {
		add_menu_page(
			__( 'DataMetric', 'datametric-analytics-heatmaps' ),
			__( 'DataMetric', 'datametric-analytics-heatmaps' ),
			'manage_options',
			$this->plugin_name,
			array( $this, 'display_plugin_admin_page' ),
			'dashicons-chart-area',
			30
		);
	}

	/**
	 * Cache-busting asset version: the file's modification time, falling back to the plugin version.
	 */
	private function asset_version( string $relative ): string {
		$file = DATAMETRIC_PATH . $relative;
		$mtime = file_exists( $file ) ? filemtime( $file ) : false;
		return $mtime ? (string) $mtime : $this->version;
	}

	/**
	 * Enqueue Styles and Scripts for Admin page.
	 */
	public function enqueue_styles_and_scripts( $hook ) {
		if ( 'toplevel_page_' . $this->plugin_name !== $hook ) {
			return;
		}

		// Enqueue Localized ApexCharts (No longer loading from CDN)
		wp_enqueue_script( 'apexcharts', DATAMETRIC_URL . 'Admin/js/vendor/apexcharts.min.js', array(), '3.41.0', true );

		// Local Admin CSS & JS (Minified). Versioned by file mtime so browsers always pick up
		// updated assets instead of serving a stale copy cached under a fixed version string.
		wp_enqueue_style( $this->plugin_name . '-admin-css', DATAMETRIC_URL . 'Admin/css/trackly-admin.min.css', array(), $this->asset_version( 'Admin/css/trackly-admin.min.css' ) );
		wp_enqueue_script( $this->plugin_name . '-admin-js', DATAMETRIC_URL . 'Admin/js/trackly-admin.min.js', array( 'jquery', 'apexcharts' ), $this->asset_version( 'Admin/js/trackly-admin.min.js' ), true );

		// Localize Script for REST API URL, Nonce, connection state & translatable UI strings
		wp_localize_script( $this->plugin_name . '-admin-js', 'datametricData', array(
			'rest_url'    => esc_url_raw( rest_url( 'datametric/v1' ) ),
			'rest_nonce'  => wp_create_nonce( 'wp_rest' ),
			'debug'       => defined( 'WP_DEBUG' ) && WP_DEBUG,
			'state'       => Api::get_connection_state(),
			'locale'      => str_replace( '_', '-', get_locale() ),
			'i18n'        => array(
				'noData'            => __( 'No data found.', 'datametric-analytics-heatmaps' ),
				'loading'           => __( 'Loading...', 'datametric-analytics-heatmaps' ),
				'unavailable'       => __( 'N/A', 'datametric-analytics-heatmaps' ),
				'pageviews'         => __( 'Pageviews', 'datametric-analytics-heatmaps' ),
				'users'             => __( 'Users', 'datametric-analytics-heatmaps' ),
				'sessions'          => __( 'Sessions', 'datametric-analytics-heatmaps' ),
				'desktop'           => __( 'Desktop', 'datametric-analytics-heatmaps' ),
				'mobile'            => __( 'Mobile', 'datametric-analytics-heatmaps' ),
				'tablet'            => __( 'Tablet', 'datametric-analytics-heatmaps' ),
				'newVisitors'       => __( 'New', 'datametric-analytics-heatmaps' ),
				'returningVisitors' => __( 'Returning', 'datametric-analytics-heatmaps' ),
			),
		) );
	}

	/**
	 * Render the main Admin Dashboard page.
	 */
	public function display_plugin_admin_page() {
		// Clear GA transient cache if settings just updated & verify authority
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['settings-updated'] ) && current_user_can( 'manage_options' ) ) {
			Api::flush_cache();
		}

		$demo_mode = get_option( 'datametric_demo_mode', 'yes' );
		$property_id = get_option( 'datametric_property_id', '' );
		$credentials_encrypted = get_option( 'datametric_credentials', '' );
		$sampling_rate = get_option( 'datametric_sampling_rate', '100' );
		$require_consent = get_option( 'datametric_require_consent', 'yes' );
		
		// Decrypt credentials and mask the private key to prevent screen exposure
		$credentials_raw = Api::decrypt_data( $credentials_encrypted );
		$credentials = '';
		if ( ! empty( $credentials_raw ) ) {
			$creds_obj = json_decode( $credentials_raw, true );
			if ( is_array( $creds_obj ) ) {
				$creds_obj['private_key'] = '___DATAMETRIC_MASKED_KEY___';
				$credentials = wp_json_encode( $creds_obj, JSON_PRETTY_PRINT );
			}
		}
		?>
		<div class="trackly-admin-wrapper">
			<!-- Settings errors output added here (Fixes hidden validation errors QA bug) -->
			<?php settings_errors( 'datametric_credentials' ); ?>

			<!-- Header -->
			<header class="trackly-header">
				<div class="trackly-logo-area">
					<span class="dashicons dashicons-chart-area trackly-logo-icon"></span>
					<h1><?php esc_html_e( 'DataMetric', 'datametric-analytics-heatmaps' ); ?> <span class="trackly-badge">v<?php echo esc_html( $this->version ); ?></span></h1>
				</div>
				<div class="trackly-status-indicator">
					<?php $connection_state = Api::get_connection_state(); ?>
					<?php if ( 'demo' === $connection_state ) : ?>
						<span class="trackly-status demo"><span class="dot"></span> <?php esc_html_e( 'Demo Mode Active', 'datametric-analytics-heatmaps' ); ?></span>
					<?php elseif ( 'connected' === $connection_state ) : ?>
						<span class="trackly-status connected"><span class="dot"></span> <?php esc_html_e( 'GA4 Connected', 'datametric-analytics-heatmaps' ); ?></span>
					<?php else : ?>
						<span class="trackly-status disconnected"><span class="dot"></span> <?php esc_html_e( 'GA4 Not Configured', 'datametric-analytics-heatmaps' ); ?></span>
					<?php endif; ?>
				</div>
			</header>

			<?php if ( 'misconfigured' === $connection_state ) : ?>
				<div class="trackly-card trackly-latency-notice trackly-misconfigured-notice">
					<span class="dashicons dashicons-warning trackly-notice-icon"></span>
					<div class="trackly-notice-text">
						<strong><?php esc_html_e( 'Google Analytics is not connected', 'datametric-analytics-heatmaps' ); ?></strong>
						<p><?php esc_html_e( 'Demo Mode is off but no valid Property ID / Service Account JSON was found. Charts will show connection errors until you complete the settings or re-enable Demo Mode.', 'datametric-analytics-heatmaps' ); ?></p>
					</div>
				</div>
			<?php endif; ?>

			<!-- Tabs/Navigation -->
			<div class="trackly-tabs">
				<button class="trackly-tab-btn active" data-target="dashboard-tab">
					<span class="dashicons dashicons-dashboard"></span> <?php esc_html_e( 'Dashboard', 'datametric-analytics-heatmaps' ); ?>
				</button>
				<button class="trackly-tab-btn" data-target="settings-tab">
					<span class="dashicons dashicons-admin-generic"></span> <?php esc_html_e( 'Settings', 'datametric-analytics-heatmaps' ); ?>
				</button>
			</div>

			<!-- Dashboard Tab Content -->
			<div id="dashboard-tab" class="trackly-tab-content active">
				<!-- Real-time Banner -->
				<div class="trackly-card trackly-realtime-card">
					<div class="trackly-realtime-content">
						<h3><?php esc_html_e( 'Active Visitors', 'datametric-analytics-heatmaps' ); ?></h3>
						<div class="trackly-realtime-value">
							<span id="trackly-active-users">--</span>
							<span class="pulse-ring"></span>
						</div>
						<p><?php esc_html_e( 'in the last 30 minutes', 'datametric-analytics-heatmaps' ); ?></p>
					</div>
					<div class="trackly-realtime-spark" id="trackly-realtime-spark"></div>
				</div>

				<!-- Stats Grid -->
				<div class="trackly-grid trackly-kpi-grid">
					<div class="trackly-card stat-card">
						<div class="stat-info">
							<h4><?php esc_html_e( 'Pageviews', 'datametric-analytics-heatmaps' ); ?></h4>
							<h2 id="trackly-stat-views">--</h2>
						</div>
						<span class="dashicons dashicons-visibility stat-icon views"></span>
					</div>
					<div class="trackly-card stat-card">
						<div class="stat-info">
							<h4><?php esc_html_e( 'Unique Visitors', 'datametric-analytics-heatmaps' ); ?></h4>
							<h2 id="trackly-stat-users">--</h2>
						</div>
						<span class="dashicons dashicons-groups stat-icon users"></span>
					</div>
					<div class="trackly-card stat-card">
						<div class="stat-info">
							<h4><?php esc_html_e( 'Sessions', 'datametric-analytics-heatmaps' ); ?></h4>
							<h2 id="trackly-stat-sessions">--</h2>
						</div>
						<span class="dashicons dashicons-chart-bar stat-icon sessions"></span>
					</div>
					<div class="trackly-card stat-card">
						<div class="stat-info">
							<h4><?php esc_html_e( 'Engagement Rate', 'datametric-analytics-heatmaps' ); ?></h4>
							<h2 id="trackly-stat-engagement">--</h2>
						</div>
						<span class="dashicons dashicons-chart-line stat-icon engagement"></span>
					</div>
					<div class="trackly-card stat-card">
						<div class="stat-info">
							<h4><?php esc_html_e( 'Bounce Rate', 'datametric-analytics-heatmaps' ); ?></h4>
							<h2 id="trackly-stat-bounce">--</h2>
						</div>
						<span class="dashicons dashicons-exit stat-icon bounce"></span>
					</div>
					<div class="trackly-card stat-card">
						<div class="stat-info">
							<h4><?php esc_html_e( 'Avg. Session Duration', 'datametric-analytics-heatmaps' ); ?></h4>
							<h2 id="trackly-stat-duration">--</h2>
						</div>
						<span class="dashicons dashicons-clock stat-icon duration"></span>
					</div>
				</div>

				<!-- GA4 Processing Latency Alert Box -->
				<div class="trackly-card trackly-latency-notice">
					<span class="dashicons dashicons-info trackly-notice-icon"></span>
					<div class="trackly-notice-text">
						<strong><?php esc_html_e( 'GA4 Data Latency Notice', 'datametric-analytics-heatmaps' ); ?></strong>
						<p><?php esc_html_e( 'Google Analytics 4 (GA4) requires 24-48 hours to process data. Therefore, traffic metrics for yesterday and today may appear lower or incomplete. This latency is temporary.', 'datametric-analytics-heatmaps' ); ?></p>
					</div>
				</div>

				<!-- Main Graph -->
				<div class="trackly-card main-chart-card">
					<div class="chart-header">
						<h3><?php esc_html_e( 'Visitor Traffic Trend', 'datametric-analytics-heatmaps' ); ?></h3>
						<div class="chart-actions">
							<button class="trackly-chart-filter-btn active" data-days="7"><?php esc_html_e( 'Last 7 Days', 'datametric-analytics-heatmaps' ); ?></button>
							<button class="trackly-chart-filter-btn" data-days="30"><?php esc_html_e( 'Last 30 Days', 'datametric-analytics-heatmaps' ); ?></button>
						</div>
					</div>
					<div class="chart-container">
						<div id="trackly-main-chart"></div>
					</div>
				</div>

				<!-- Bottom Grid (Sources & Devices) -->
				<div class="trackly-grid double">
					<div class="trackly-card chart-half">
						<h3><?php esc_html_e( 'Traffic Sources', 'datametric-analytics-heatmaps' ); ?></h3>
						<div id="trackly-source-chart"></div>
					</div>
					<div class="trackly-card chart-half">
						<h3><?php esc_html_e( 'Device Distribution', 'datametric-analytics-heatmaps' ); ?></h3>
						<div id="trackly-device-chart"></div>
					</div>
				</div>

				<!-- Traffic Acquisition (Source / Medium — includes referrers) -->
				<div class="trackly-card table-card">
					<h3><?php esc_html_e( 'Traffic Acquisition (Source / Medium)', 'datametric-analytics-heatmaps' ); ?></h3>
					<div class="trackly-table-wrapper">
						<table class="trackly-table" id="trackly-acquisition-table">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Source / Medium', 'datametric-analytics-heatmaps' ); ?></th>
									<th><?php esc_html_e( 'Sessions', 'datametric-analytics-heatmaps' ); ?></th>
									<th><?php esc_html_e( 'Users', 'datametric-analytics-heatmaps' ); ?></th>
									<th><?php esc_html_e( 'Engagement', 'datametric-analytics-heatmaps' ); ?></th>
									<th><?php esc_html_e( 'Key Events', 'datametric-analytics-heatmaps' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<tr><td colspan="5" class="loading-td"><?php esc_html_e( 'Loading...', 'datametric-analytics-heatmaps' ); ?></td></tr>
							</tbody>
						</table>
					</div>
				</div>

				<!-- Top Pages Table -->
				<div class="trackly-card table-card">
					<h3><?php esc_html_e( 'Top Viewed Pages', 'datametric-analytics-heatmaps' ); ?></h3>
					<div class="trackly-table-wrapper">
						<table class="trackly-table" id="trackly-pages-table">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Page URL', 'datametric-analytics-heatmaps' ); ?></th>
									<th><?php esc_html_e( 'Pageviews', 'datametric-analytics-heatmaps' ); ?></th>
									<th><?php esc_html_e( 'Users', 'datametric-analytics-heatmaps' ); ?></th>
									<th><?php esc_html_e( 'Engagement', 'datametric-analytics-heatmaps' ); ?></th>
									<th><?php esc_html_e( 'Avg. Duration', 'datametric-analytics-heatmaps' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<tr>
									<td colspan="5" class="loading-td"><?php esc_html_e( 'Loading...', 'datametric-analytics-heatmaps' ); ?></td>
								</tr>
							</tbody>
						</table>
					</div>
				</div>

				<!-- Landing Pages Table -->
				<div class="trackly-card table-card">
					<h3><?php esc_html_e( 'Top Landing Pages', 'datametric-analytics-heatmaps' ); ?></h3>
					<div class="trackly-table-wrapper">
						<table class="trackly-table" id="trackly-landing-table">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Landing Page', 'datametric-analytics-heatmaps' ); ?></th>
									<th><?php esc_html_e( 'Sessions', 'datametric-analytics-heatmaps' ); ?></th>
									<th><?php esc_html_e( 'Engagement', 'datametric-analytics-heatmaps' ); ?></th>
									<th><?php esc_html_e( 'Pageviews', 'datametric-analytics-heatmaps' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<tr><td colspan="4" class="loading-td"><?php esc_html_e( 'Loading...', 'datametric-analytics-heatmaps' ); ?></td></tr>
							</tbody>
						</table>
					</div>
				</div>

				<!-- Bottom Grid (Geography & New vs Returning) -->
				<div class="trackly-grid double">
					<div class="trackly-card table-card">
						<h3><?php esc_html_e( 'Top Countries', 'datametric-analytics-heatmaps' ); ?></h3>
						<div id="trackly-geo-list" class="trackly-bar-list">
							<p class="loading-td"><?php esc_html_e( 'Loading...', 'datametric-analytics-heatmaps' ); ?></p>
						</div>
					</div>
					<div class="trackly-card chart-half">
						<h3><?php esc_html_e( 'New vs Returning', 'datametric-analytics-heatmaps' ); ?></h3>
						<div id="trackly-nvr-chart"></div>
					</div>
				</div>

				<!-- Top Events Table -->
				<div class="trackly-card table-card">
					<h3><?php esc_html_e( 'Top Events', 'datametric-analytics-heatmaps' ); ?></h3>
					<div class="trackly-table-wrapper">
						<table class="trackly-table" id="trackly-events-table">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Event Name', 'datametric-analytics-heatmaps' ); ?></th>
									<th><?php esc_html_e( 'Event Count', 'datametric-analytics-heatmaps' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<tr><td colspan="2" class="loading-td"><?php esc_html_e( 'Loading...', 'datametric-analytics-heatmaps' ); ?></td></tr>
							</tbody>
						</table>
					</div>
				</div>
			</div>

			<!-- Settings Tab Content (WordPress Settings API Integrated) -->
			<div id="settings-tab" class="trackly-tab-content">
				<div class="trackly-card settings-card">
					<h3><?php esc_html_e( 'Google Analytics Integration Settings', 'datametric-analytics-heatmaps' ); ?></h3>
					<form method="post" action="options.php">
						<?php
						settings_fields( 'datametric_settings_group' );
						do_settings_sections( 'datametric_settings_group' );
						?>

						<div class="trackly-form-group">
							<label class="trackly-switch-label">
								<input type="hidden" name="datametric_demo_mode" value="no">
								<input type="checkbox" name="datametric_demo_mode" value="yes" <?php checked( $demo_mode, 'yes' ); ?>>
								<span class="trackly-switch-slider"></span>
								<div class="label-text">
									<strong><?php esc_html_e( 'Enable Demo (Mock) Mode', 'datametric-analytics-heatmaps' ); ?></strong>
									<p class="description"><?php esc_html_e( 'Keep this enabled to test the plugin with mock data without connecting to Google Analytics.', 'datametric-analytics-heatmaps' ); ?></p>
								</div>
							</label>
						</div>

						<div class="trackly-form-group">
							<label class="trackly-switch-label">
								<input type="hidden" name="datametric_require_consent" value="no">
								<input type="checkbox" name="datametric_require_consent" value="yes" <?php checked( $require_consent, 'yes' ); ?>>
								<span class="trackly-switch-slider"></span>
								<div class="label-text">
									<strong><?php esc_html_e( 'Require Cookie Consent (Strict GDPR)', 'datametric-analytics-heatmaps' ); ?></strong>
									<p class="description"><?php esc_html_e( 'If enabled, the tracker will start in opt-in mode and will block click telemetry until consent is explicitly granted via Borlabs, Complianz, CLI, or Google Consent Mode v2.', 'datametric-analytics-heatmaps' ); ?></p>
								</div>
							</label>
						</div>

						<div class="trackly-form-group">
							<label for="datametric_property_id"><?php esc_html_e( 'GA4 Property ID', 'datametric-analytics-heatmaps' ); ?></label>
							<input type="text" id="datametric_property_id" name="datametric_property_id" value="<?php echo esc_attr( $property_id ); ?>" placeholder="e.g. 382901248" class="regular-text">
							<p class="description"><?php esc_html_e( 'The numeric Property ID of your Google Analytics property (Can be found in Admin > Property Settings).', 'datametric-analytics-heatmaps' ); ?></p>
						</div>

						<div class="trackly-form-group">
							<label for="datametric_credentials"><?php esc_html_e( 'Service Account JSON Key', 'datametric-analytics-heatmaps' ); ?></label>
							<textarea id="datametric_credentials" name="datametric_credentials" rows="8" class="large-text code" placeholder='{"type": "service_account", ...}'><?php echo esc_textarea( $credentials ); ?></textarea>
							<p class="description"><?php esc_html_e( 'Paste the contents of the Service Account JSON key file you created in the Google Cloud Console. Make sure to add this service account\'s email as a Viewer to your GA4 property.', 'datametric-analytics-heatmaps' ); ?></p>

							<details class="trackly-help">
								<summary><span class="dashicons dashicons-editor-help"></span> <?php esc_html_e( 'How do I get this JSON key? (step-by-step)', 'datametric-analytics-heatmaps' ); ?></summary>
								<div class="trackly-help-body">
									<ol>
										<li>
											<?php
											printf(
												/* translators: %s: Google Cloud Console link */
												esc_html__( 'Open the %s and create a new project (or select an existing one).', 'datametric-analytics-heatmaps' ),
												'<a href="https://console.cloud.google.com/" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Google Cloud Console', 'datametric-analytics-heatmaps' ) . '</a>'
											);
											?>
										</li>
										<li>
											<?php
											printf(
												/* translators: %s: API name */
												esc_html__( 'Go to %1$s and enable the %2$s.', 'datametric-analytics-heatmaps' ),
												'<strong>' . esc_html__( 'APIs &amp; Services → Library', 'datametric-analytics-heatmaps' ) . '</strong>',
												'<strong>' . esc_html__( 'Google Analytics Data API', 'datametric-analytics-heatmaps' ) . '</strong>'
											);
											?>
										</li>
										<li>
											<?php
											printf(
												/* translators: %s: menu path */
												esc_html__( 'Go to %s → Create Service Account. Give it a name and click Done (no roles are required at the project level).', 'datametric-analytics-heatmaps' ),
												'<strong>' . esc_html__( 'IAM &amp; Admin → Service Accounts', 'datametric-analytics-heatmaps' ) . '</strong>'
											);
											?>
										</li>
										<li>
											<?php
											printf(
												/* translators: 1: Keys tab, 2: menu path */
												esc_html__( 'Open the new service account, go to the %1$s tab, then %2$s. A .json file will download to your computer.', 'datametric-analytics-heatmaps' ),
												'<strong>' . esc_html__( 'Keys', 'datametric-analytics-heatmaps' ) . '</strong>',
												'<strong>' . esc_html__( 'Add Key → Create new key → JSON → Create', 'datametric-analytics-heatmaps' ) . '</strong>'
											);
											?>
										</li>
										<li><?php esc_html_e( 'Open that downloaded .json file in a text editor and paste its entire contents into the box above.', 'datametric-analytics-heatmaps' ); ?></li>
										<li>
											<?php
											printf(
												/* translators: 1: client_email field, 2: GA path, 3: Viewer role */
												esc_html__( 'Copy the service account email (the %1$s value, ending in .iam.gserviceaccount.com). In Google Analytics, go to %2$s and add that email with the %3$s role.', 'datametric-analytics-heatmaps' ),
												'<code>client_email</code>',
												'<strong>' . esc_html__( 'Admin → Property Access Management', 'datametric-analytics-heatmaps' ) . '</strong>',
												'<strong>' . esc_html__( 'Viewer', 'datametric-analytics-heatmaps' ) . '</strong>'
											);
											?>
										</li>
										<li><?php esc_html_e( 'Turn off Demo Mode above and click Save Settings. Your real data will appear within a few minutes.', 'datametric-analytics-heatmaps' ); ?></li>
									</ol>
									<p class="description"><?php esc_html_e( 'Note: GA4 processes data with a 24–48 hour delay, so today\'s and yesterday\'s numbers may look incomplete at first.', 'datametric-analytics-heatmaps' ); ?></p>
								</div>
							</details>
						</div>

						<!-- Sampling Rate Option Selector to prevent DB bloat -->
						<div class="trackly-form-group">
							<label for="datametric_sampling_rate"><?php esc_html_e( 'Click Sampling Rate', 'datametric-analytics-heatmaps' ); ?></label>
							<select id="datametric_sampling_rate" name="datametric_sampling_rate">
								<option value="100" <?php selected( $sampling_rate, '100' ); ?>><?php esc_html_e( '100% (Record all clicks)', 'datametric-analytics-heatmaps' ); ?></option>
								<option value="50" <?php selected( $sampling_rate, '50' ); ?>><?php esc_html_e( '50% (Record half of all clicks)', 'datametric-analytics-heatmaps' ); ?></option>
								<option value="25" <?php selected( $sampling_rate, '25' ); ?>><?php esc_html_e( '25% (Record a quarter of all clicks)', 'datametric-analytics-heatmaps' ); ?></option>
								<option value="10" <?php selected( $sampling_rate, '10' ); ?>><?php esc_html_e( '10% (Record only 10% of clicks - Recommended for high traffic sites)', 'datametric-analytics-heatmaps' ); ?></option>
							</select>
							<p class="description"><?php esc_html_e( 'For high traffic sites, you can reduce the click tracking frequency to prevent database bloat. Sampling runs on a per-session basis.', 'datametric-analytics-heatmaps' ); ?></p>
						</div>

						<div class="trackly-form-group">
							<label class="trackly-switch-label">
								<input type="hidden" name="datametric_delete_data" value="no">
								<input type="checkbox" name="datametric_delete_data" value="yes" <?php checked( get_option( 'datametric_delete_data', 'no' ), 'yes' ); ?>>
								<span class="trackly-switch-slider"></span>
								<div class="label-text">
									<strong><?php esc_html_e( 'Delete all data on uninstall', 'datametric-analytics-heatmaps' ); ?></strong>
									<p class="description"><?php esc_html_e( 'If enabled, deleting the plugin will permanently remove the click telemetry table and all settings. Leave off to preserve your data if you reinstall later.', 'datametric-analytics-heatmaps' ); ?></p>
								</div>
							</label>
						</div>

						<button type="submit" class="trackly-btn trackly-btn-primary"><?php esc_html_e( 'Save Settings', 'datametric-analytics-heatmaps' ); ?></button>
					</form>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Register WordPress REST API Routes.
	 */
	public function register_rest_routes() {
		register_rest_route( 'datametric/v1', '/stats', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_stats_callback' ),
			'permission_callback' => array( $this, 'check_admin_permissions' ),
		) );

		register_rest_route( 'datametric/v1', '/realtime', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_realtime_callback' ),
			'permission_callback' => array( $this, 'check_admin_permissions' ),
		) );

		register_rest_route( 'datametric/v1', '/page-stats', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_page_stats_callback' ),
			'permission_callback' => array( $this, 'check_admin_permissions' ),
		) );

		register_rest_route( 'datametric/v1', '/record-click', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'record_click_callback' ),
			// Intentionally public: anonymous visitors submit click telemetry, and a nonce cannot be
			// used here because it is baked into full-page caches and silently expires there. This is a
			// low-sensitivity, write-only endpoint. Abuse is mitigated inside the callback (bot filter,
			// same-origin heuristic, per-IP rate limiting) as best-effort hardening — not authentication.
			'permission_callback' => '__return_true',
		) );

		register_rest_route( 'datametric/v1', '/clicks', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_clicks_callback' ),
			'permission_callback' => array( $this, 'check_admin_permissions' ),
		) );

		register_rest_route( 'datametric/v1', '/save-event', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'save_event_callback' ),
			// Writes a site-wide option (custom GA4 event map), so require full admin capability.
			'permission_callback' => array( $this, 'check_manage_permissions' ),
		) );
	}

	/**
	 * Verify if current user has permission to VIEW reports (read-only endpoints).
	 */
	public function check_admin_permissions() {
		return current_user_can( 'datametric_view_dashboard' ) || current_user_can( 'manage_options' );
	}

	/**
	 * Verify if current user may CHANGE site-wide plugin settings (write endpoints).
	 */
	public function check_manage_permissions() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Best-effort abuse mitigation for the intentionally public /record-click endpoint.
	 *
	 * This is NOT authentication — the endpoint is public by design (anonymous visitors submit
	 * click telemetry, and a nonce cannot be used because it is baked into full-page caches and
	 * expires there). These heuristics (bot filter + same-origin) only reduce casual spam; the
	 * per-IP rate limiter in record_click_callback() provides the volume cap. Signals here are
	 * spoofable and are treated purely as hardening, never as a trust boundary.
	 *
	 * @return bool True if the request looks like a genuine same-origin visitor.
	 */
	private function is_probable_visitor_request() {
		// 1. Skip common web crawlers and scrapers via User-Agent to reduce database spam.
		$user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
		if ( '' === $user_agent || preg_match( '/bot|crawl|spider|slurp|Baiduspider|YandexBot|DuckDuckBot|facebookexternalhit|LinkedInBot|Lighthouse|HeadlessChrome|python-requests|curl|wget/i', $user_agent ) ) {
			return false;
		}

		// 2. Prefer a same-origin Origin or Referer header. Reject if BOTH are absent
		//    (header-less scripted requests) or if either is present and mismatched.
		$origin  = isset( $_SERVER['HTTP_ORIGIN'] ) ? esc_url_raw( wp_unslash( $_SERVER['HTTP_ORIGIN'] ) ) : '';
		$referer = isset( $_SERVER['HTTP_REFERER'] ) ? esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : '';
		$host    = wp_parse_url( home_url(), PHP_URL_HOST );

		if ( ! empty( $origin ) ) {
			return wp_parse_url( $origin, PHP_URL_HOST ) === $host;
		}
		if ( ! empty( $referer ) ) {
			return wp_parse_url( $referer, PHP_URL_HOST ) === $host;
		}

		return false;
	}

	/**
	 * Robust client IP retriever with Cloudflare and Nginx reverse proxy whitelisting (IPv4 & IPv6 support).
	 */
	private function get_ip_address() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '0.0.0.0';

		// Cloudflare's published edge ranges, bundled statically so the plugin never makes an
		// external request. Sites behind a different proxy can override these via the
		// 'datametric_trusted_proxies' filter below.
		$default_proxies = array(
			'173.245.48.0/20',
			'103.21.244.0/22',
			'103.22.200.0/22',
			'103.31.4.0/22',
			'141.101.64.0/18',
			'108.162.192.0/18',
			'190.93.240.0/20',
			'188.114.96.0/20',
			'197.234.240.0/22',
			'198.41.128.0/17',
			'162.158.0.0/15',
			'104.16.0.0/13',
			'104.24.0.0/14',
			'172.64.0.0/13',
			'131.0.72.0/22',
		);

		// Allow developers to override/append trusted proxy CIDRs
		$trusted_proxies = apply_filters( 'datametric_trusted_proxies', $default_proxies );

		if ( ! empty( $trusted_proxies ) ) {
			$is_trusted = false;
			foreach ( $trusted_proxies as $proxy ) {
				if ( $this->ip_in_range( $ip, $proxy ) ) {
					$is_trusted = true;
					break;
				}
			}

			if ( $is_trusted ) {
				foreach ( array( 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_CLIENT_IP' ) as $header ) {
					if ( ! empty( $_SERVER[ $header ] ) ) {
						// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
						$ips = explode( ',', wp_unslash( $_SERVER[ $header ] ) );
						$client_ip = trim( $ips[0] );
						if ( filter_var( $client_ip, FILTER_VALIDATE_IP ) !== false ) {
							return $client_ip;
						}
					}
				}
			}
		}

		return $ip;
	}

	/**
	 * Helper function to verify if an IP matches a CIDR block or absolute IP (IPv4 & IPv6 compliant).
	 */
	private function ip_in_range( $ip, $range ) {
		if ( strpos( $range, '/' ) === false ) {
			return $ip === $range;
		}

		list( $subnet, $bits ) = explode( '/', $range );
		$ip_bits = ( strpos( $ip, ':' ) !== false ) ? 128 : 32;
		$subnet_bits = ( strpos( $subnet, ':' ) !== false ) ? 128 : 32;

		if ( $ip_bits !== $subnet_bits ) {
			return false; // Mismatched IP families
		}

		$ip_bin = inet_pton( $ip );
		$subnet_bin = inet_pton( $subnet );
		if ( false === $ip_bin || false === $subnet_bin ) {
			return false;
		}

		// Create netmask
		$mask_bin = '';
		$full_bytes = floor( $bits / 8 );
		$rem_bits = $bits % 8;
		
		for ( $i = 0; $i < $full_bytes; $i++ ) {
			$mask_bin .= chr( 0xFF );
		}
		if ( $rem_bits > 0 ) {
			$mask_bin .= chr( 0xFF << ( 8 - $rem_bits ) );
		}
		$total_bytes = ( $ip_bits === 128 ) ? 16 : 4;
		$mask_bin = str_pad( $mask_bin, $total_bytes, chr( 0x00 ) );

		// Apply mask
		for ( $i = 0; $i < $total_bytes; $i++ ) {
			if ( ( $ip_bin[ $i ] & $mask_bin[ $i ] ) !== ( $subnet_bin[ $i ] & $mask_bin[ $i ] ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * REST Callback for overall site statistics.
	 */
	public function get_stats_callback( $request ) {
		$days = intval( $request->get_param( 'days' ) );
		if ( ! in_array( $days, array( 7, 30 ), true ) ) {
			$days = 7;
		}

		$start_date = $days === 30 ? '30daysAgo' : '7daysAgo';
		$range      = array( array( 'startDate' => $start_date, 'endDate' => 'yesterday' ) );

		$order_by = function ( $metric ) {
			return array( array( 'desc' => true, 'metric' => array( 'metricName' => $metric ) ) );
		};

		// GA4 batchRunReports allows at most 5 reports per call, so we use two batches.
		// Overview batch: only universally-stable metrics, so the core dashboard is bulletproof.
		$overview_reqs = array(
			// 0: Summary KPIs
			array( 'dateRanges' => $range, 'metrics' => $this->ga_fields( array( 'screenPageViews', 'activeUsers', 'sessions', 'engagementRate', 'bounceRate', 'averageSessionDuration' ) ) ),
			// 1: Daily trend
			array( 'dateRanges' => $range, 'dimensions' => $this->ga_fields( array( 'date' ) ), 'metrics' => $this->ga_fields( array( 'screenPageViews', 'activeUsers', 'sessions' ) ) ),
			// 2: Channel donut
			array( 'dateRanges' => $range, 'dimensions' => $this->ga_fields( array( 'sessionDefaultChannelGroup' ) ), 'metrics' => $this->ga_fields( array( 'sessions' ) ), 'orderBys' => $order_by( 'sessions' ), 'limit' => 8 ),
			// 3: Device donut
			array( 'dateRanges' => $range, 'dimensions' => $this->ga_fields( array( 'deviceCategory' ) ), 'metrics' => $this->ga_fields( array( 'activeUsers' ) ) ),
			// 4: Top pages
			array( 'dateRanges' => $range, 'dimensions' => $this->ga_fields( array( 'pagePath' ) ), 'metrics' => $this->ga_fields( array( 'screenPageViews', 'activeUsers', 'engagementRate', 'averageSessionDuration' ) ), 'orderBys' => $order_by( 'screenPageViews' ), 'limit' => 20 ),
		);

		$ov = Api::batch_run_reports( $overview_reqs );
		if ( is_wp_error( $ov ) ) {
			return new WP_REST_Response( array( 'success' => false, 'error' => $ov->get_error_message() ), 500 );
		}

		// Secondary batch: acquisition / audience widgets. Loaded best-effort — if it fails
		// (e.g. a property that rejects keyEvents), the core dashboard above still renders.
		$secondary_reqs = array(
			// 0: Traffic acquisition by source / medium (includes referrers)
			array( 'dateRanges' => $range, 'dimensions' => $this->ga_fields( array( 'sessionSourceMedium' ) ), 'metrics' => $this->ga_fields( array( 'sessions', 'activeUsers', 'engagementRate', 'keyEvents' ) ), 'orderBys' => $order_by( 'sessions' ), 'limit' => 10 ),
			// 1: Landing pages
			array( 'dateRanges' => $range, 'dimensions' => $this->ga_fields( array( 'landingPage' ) ), 'metrics' => $this->ga_fields( array( 'sessions', 'engagementRate', 'screenPageViews' ) ), 'orderBys' => $order_by( 'sessions' ), 'limit' => 10 ),
			// 2: Geography
			array( 'dateRanges' => $range, 'dimensions' => $this->ga_fields( array( 'country' ) ), 'metrics' => $this->ga_fields( array( 'activeUsers' ) ), 'orderBys' => $order_by( 'activeUsers' ), 'limit' => 10 ),
			// 3: Top events
			array( 'dateRanges' => $range, 'dimensions' => $this->ga_fields( array( 'eventName' ) ), 'metrics' => $this->ga_fields( array( 'eventCount' ) ), 'orderBys' => $order_by( 'eventCount' ), 'limit' => 10 ),
			// 4: New vs returning
			array( 'dateRanges' => $range, 'dimensions' => $this->ga_fields( array( 'newVsReturning' ) ), 'metrics' => $this->ga_fields( array( 'activeUsers' ) ) ),
		);
		$sec    = Api::batch_run_reports( $secondary_reqs );
		$sec_ok = ! is_wp_error( $sec );

		$realtime_users  = Api::get_realtime_users();
		$realtime_series = Api::get_realtime_series();

		return new WP_REST_Response( array(
			'success'          => true,
			'summary'          => isset( $ov[0] ) ? $ov[0] : array(),
			'chart'            => isset( $ov[1] ) ? $ov[1] : array(),
			'sources'          => isset( $ov[2] ) ? $ov[2] : array(),
			'devices'          => isset( $ov[3] ) ? $ov[3] : array(),
			'pages'            => isset( $ov[4] ) ? $ov[4] : array(),
			'acquisition'      => ( $sec_ok && isset( $sec[0] ) ) ? $sec[0] : array(),
			'landing_pages'    => ( $sec_ok && isset( $sec[1] ) ) ? $sec[1] : array(),
			'geography'        => ( $sec_ok && isset( $sec[2] ) ) ? $sec[2] : array(),
			'events'           => ( $sec_ok && isset( $sec[3] ) ) ? $sec[3] : array(),
			'new_vs_returning' => ( $sec_ok && isset( $sec[4] ) ) ? $sec[4] : array(),
			'realtime_users'   => $realtime_users,
			'realtime_series'  => $realtime_series,
		), 200 );
	}

	/**
	 * Helper: turn a list of GA4 field names into the API's [ [ 'name' => ... ], ... ] shape.
	 */
	private function ga_fields( array $names ): array {
		return array_map(
			function ( $name ) { return array( 'name' => $name ); },
			$names
		);
	}

	/**
	 * REST Callback for lightweight realtime polling.
	 */
	public function get_realtime_callback( $request ) {
		return new WP_REST_Response( array(
			'success'         => true,
			'realtime_users'  => Api::get_realtime_users(),
			'realtime_series' => Api::get_realtime_series(),
		), 200 );
	}

	/**
	 * REST Callback for single page stats.
	 */
	public function get_page_stats_callback( $request ) {
		$url = esc_url_raw( $request->get_param( 'url' ) );
		if ( empty( $url ) ) {
			return new WP_REST_Response( array( 'success' => false, 'error' => __( 'URL parameter required.', 'datametric-analytics-heatmaps' ) ), 400 );
		}

		$path = wp_parse_url( $url, PHP_URL_PATH );
		if ( empty( $path ) ) {
			$path = '/';
		}

		// GA4 stores pagePath exactly as requested; WordPress permalinks may or may not carry a
		// trailing slash. Match both variants so the panel is not empty due to a slash mismatch.
		$path_variants = array_values( array_unique( array(
			$path,
			rtrim( $path, '/' ) !== '' ? rtrim( $path, '/' ) : '/',
			rtrim( $path, '/' ) . '/',
		) ) );

		$page_path_filter = array(
			'filter' => array(
				'fieldName'    => 'pagePath',
				'inListFilter' => array(
					'values' => $path_variants,
				),
			),
		);

		$batch_requests = array(
			// Query 1: Page Totals over the last 7 days
			array(
				'dateRanges'      => array( array( 'startDate' => '7daysAgo', 'endDate' => 'yesterday' ) ),
				'dimensions'      => array( array( 'name' => 'pagePath' ) ),
				'metrics'         => array(
					array( 'name' => 'screenPageViews' ),
					array( 'name' => 'activeUsers' ),
					array( 'name' => 'bounceRate' ),
					array( 'name' => 'averageSessionDuration' ),
				),
				'dimensionFilter' => $page_path_filter,
			),
			// Query 2: Daily trend breakdown for statistical anomaly checks
			array(
				'dateRanges'      => array( array( 'startDate' => '7daysAgo', 'endDate' => 'yesterday' ) ),
				'dimensions'      => array( array( 'name' => 'date' ) ),
				'metrics'         => array(
					array( 'name' => 'screenPageViews' ),
				),
				'dimensionFilter' => $page_path_filter,
			),
		);

		$batch_reports = Api::batch_run_reports( $batch_requests );

		if ( is_wp_error( $batch_reports ) ) {
			return new WP_REST_Response( array( 'success' => false, 'error' => $batch_reports->get_error_message() ), 500 );
		}

		$report       = isset( $batch_reports[0] ) ? $batch_reports[0] : array();
		$daily_report = isset( $batch_reports[1] ) ? $batch_reports[1] : array();

		// Generate standard-deviation based insights
		$heatmap_service = new \DataMetric\Includes\Service\HeatmapService();
		$insights        = $heatmap_service->generate_statistical_insights( $daily_report, $report );

		return new WP_REST_Response( array(
			'success'  => true,
			'path'     => $path,
			'report'   => $report,
			'insights' => $insights,
		), 200 );
	}

	/**
	 * REST Callback to record a visitor click.
	 */
	public function record_click_callback( $request ) {
		// Best-effort abuse mitigation for this public, write-only telemetry endpoint (not auth).
		if ( ! $this->is_probable_visitor_request() ) {
			return new WP_REST_Response( array( 'success' => false, 'error' => __( 'Forbidden.', 'datametric-analytics-heatmaps' ) ), 403 );
		}

		// Fixed 60s window rate limit per IP, counting REQUESTS (each request may carry a batch of clicks).
		$ip = $this->get_ip_address();
		$transient_key = 'datametric_rate_' . md5( $ip );
		$requests = get_transient( $transient_key );

		if ( false === $requests ) {
			set_transient( $transient_key, 1, 60 );
		} elseif ( $requests >= 30 ) {
			return new WP_REST_Response( array( 'success' => false, 'error' => __( 'Rate limit exceeded.', 'datametric-analytics-heatmaps' ) ), 429 );
		} else {
			// Do not reset the TTL: keep the original 60s window so the limit cannot creep.
			set_transient( $transient_key, $requests + 1, 60 );
		}

		$params = $request->get_json_params();

		// Accept either a single click object or { clicks: [ ... ] } batch.
		$batch = ( isset( $params['clicks'] ) && is_array( $params['clicks'] ) ) ? $params['clicks'] : array( $params );

		// Cap the batch size defensively.
		$batch = array_slice( $batch, 0, 50 );

		$saved = 0;
		foreach ( $batch as $click ) {
			if ( empty( $click['page_url'] ) || ! isset( $click['click_x_pct'] ) || ! isset( $click['click_y_pct'] ) ) {
				continue;
			}
			$ok = Database::log_click( array(
				'page_url'         => $click['page_url'],
				'element_tag'      => isset( $click['element_tag'] ) ? sanitize_text_field( $click['element_tag'] ) : 'unknown',
				'element_selector' => isset( $click['element_selector'] ) ? sanitize_text_field( $click['element_selector'] ) : '',
				'click_x_pct'      => floatval( $click['click_x_pct'] ),
				'click_y_pct'      => floatval( $click['click_y_pct'] ),
			) );
			if ( $ok ) {
				$saved++;
			}
		}

		if ( 0 === $saved ) {
			return new WP_REST_Response( array( 'success' => false, 'error' => __( 'No valid click data.', 'datametric-analytics-heatmaps' ) ), 400 );
		}

		return new WP_REST_Response( array( 'success' => true, 'saved' => $saved ), 200 );
	}

	/**
	 * REST Callback to retrieve clicks.
	 */
	public function get_clicks_callback( $request ) {
		// In Demo Mode (the default until a GA4 property is configured) serve a representative
		// demo heatmap so the full experience is available without recording real clicks.
		if ( \DataMetric\Includes\Api::is_demo_mode() ) {
			return new WP_REST_Response( array(
				'success' => true,
				'clicks'  => $this->get_demo_clicks(),
				'demo'    => true,
			), 200 );
		}

		$url = esc_url_raw( $request->get_param( 'url' ) );
		if ( empty( $url ) ) {
			return new WP_REST_Response( array( 'success' => false, 'error' => __( 'URL parameter required.', 'datametric-analytics-heatmaps' ) ), 400 );
		}

		$clicks = Database::get_clicks_for_page( $url );

		return new WP_REST_Response( array(
			'success' => true,
			'clicks'  => $clicks,
		), 200 );
	}

	/**
	 * Build a deterministic set of demo click coordinates arranged in realistic hotspots
	 * (menu, hero CTA, content, sidebar, footer). Used to populate the heatmap in Demo Mode.
	 *
	 * @return array<int,array<string,mixed>> Click rows shaped like real records (percentage coordinates).
	 */
	private function get_demo_clicks() {
		// Cluster centers: [x%, y%, count]. Mimics where visitors typically click on a page.
		$centers = array(
			array( 50, 8, 16 ),   // top navigation / menu bar
			array( 50, 34, 15 ),  // hero call-to-action
			array( 28, 57, 12 ),  // primary content column
			array( 74, 52, 10 ),  // sidebar / widgets
			array( 50, 90, 11 ),  // footer links
		);

		// Deterministic PRNG (fixed seed) so the demo heatmap looks natural but stays stable.
		$seed = 1337;
		$rand = function ( $min, $max ) use ( &$seed ) {
			$seed = ( $seed * 1103515245 + 12345 ) & 0x7fffffff;
			return $min + ( $seed % 1000 ) / 1000 * ( $max - $min );
		};

		$clicks = array();
		foreach ( $centers as $c ) {
			list( $cx, $cy, $count ) = $c;
			for ( $i = 0; $i < $count; $i++ ) {
				$clicks[] = array(
					'click_x_pct'      => round( max( 2, min( 98, $cx + $rand( -9, 9 ) ) ), 2 ),
					'click_y_pct'      => round( max( 2, min( 98, $cy + $rand( -6, 6 ) ) ), 2 ),
					'element_tag'      => 'a',
					'element_selector' => 'demo',
				);
			}
		}
		return $clicks;
	}

	/**
	 * REST Callback to save GA4 event mapping.
	 * Relaxed regex to only block HTML tag enjections '<' and '>', resolving quotes regression.
	 */
	public function save_event_callback( $request ) {
		$params = $request->get_json_params();
		if ( empty( $params['selector'] ) || empty( $params['event_name'] ) ) {
			return new WP_REST_Response( array( 'success' => false, 'error' => __( 'Selector and Event Name required.', 'datametric-analytics-heatmaps' ) ), 400 );
		}

		$selector = sanitize_text_field( $params['selector'] );
		// Sanity check CSS selector syntax (blocks HTML tags, curly braces)
		if ( preg_match( '/[<>\{\}]/i', $selector ) ) {
			return new WP_REST_Response( array( 'success' => false, 'error' => __( 'Invalid CSS Selector payload.', 'datametric-analytics-heatmaps' ) ), 400 );
		}

		$saved_events = get_option( 'datametric_custom_events', array() );
		if ( ! is_array( $saved_events ) ) {
			$saved_events = array();
		}

		// Limit saved events to max 200 elements to prevent options database bloat
		if ( count( $saved_events ) >= 200 ) {
			return new WP_REST_Response( array( 'success' => false, 'error' => __( 'Maximum limit of 200 custom events reached.', 'datametric-analytics-heatmaps' ) ), 400 );
		}

		// Prevent duplicate selectors
		foreach ( $saved_events as $event ) {
			if ( $event['selector'] === $selector ) {
				return new WP_REST_Response( array( 'success' => false, 'error' => __( 'This CSS selector is already registered.', 'datametric-analytics-heatmaps' ) ), 400 );
			}
		}

		$saved_events[] = array(
			'selector'   => $selector,
			'event_name' => sanitize_key( $params['event_name'] ),
			'created_at' => current_time( 'mysql' ),
		);

		update_option( 'datametric_custom_events', $saved_events );

		return new WP_REST_Response( array( 'success' => true, 'events' => $saved_events ), 200 );
	}
}
