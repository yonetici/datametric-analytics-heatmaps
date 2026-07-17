<?php
namespace Trackly\Admin;

use Trackly\Includes\Api;
use Trackly\Includes\Database;
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
		register_setting( 'trackly_settings_group', 'trackly_demo_mode', array(
			'type'              => 'string',
			'default'           => 'yes',
			'sanitize_callback' => 'sanitize_text_field',
		) );
		register_setting( 'trackly_settings_group', 'trackly_property_id', array(
			'type'              => 'string',
			'default'           => '',
			'sanitize_callback' => 'sanitize_text_field',
		) );
		register_setting( 'trackly_settings_group', 'trackly_credentials', array(
			'type'              => 'string',
			'default'           => '',
			'sanitize_callback' => array( $this, 'sanitize_and_encrypt_credentials' ),
		) );
		// Sampling rate option to prevent database table bloat
		register_setting( 'trackly_settings_group', 'trackly_sampling_rate', array(
			'type'              => 'string',
			'default'           => '100',
			'sanitize_callback' => 'sanitize_text_field',
		) );
		// Require consent option for GDPR compliance
		register_setting( 'trackly_settings_group', 'trackly_require_consent', array(
			'type'              => 'string',
			'default'           => 'yes',
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
			add_settings_error( 'trackly_credentials', 'invalid_json', __( 'Invalid JSON format.', 'metricpulse' ) );
			return get_option( 'trackly_credentials', '' );
		}

		// Preserve actual key if user submitted placeholder masking sentinel pattern
		if ( isset( $decoded['private_key'] ) && $decoded['private_key'] === '___TRACKLY_MASKED_KEY___' ) {
			$existing_encrypted = get_option( 'trackly_credentials', '' );
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
			__( 'Trackly', 'metricpulse' ),
			__( 'Trackly', 'metricpulse' ),
			'manage_options',
			$this->plugin_name,
			array( $this, 'display_plugin_admin_page' ),
			'dashicons-chart-area',
			30
		);
	}

	/**
	 * Enqueue Styles and Scripts for Admin page.
	 */
	public function enqueue_styles_and_scripts( $hook ) {
		if ( 'toplevel_page_' . $this->plugin_name !== $hook ) {
			return;
		}

		// Enqueue Localized ApexCharts (No longer loading from CDN)
		wp_enqueue_script( 'apexcharts', TRACKLY_URL . 'Admin/js/vendor/apexcharts.min.js', array(), '3.41.0', true );

		// Local Admin CSS & JS (Minified)
		wp_enqueue_style( $this->plugin_name . '-admin-css', TRACKLY_URL . 'Admin/css/trackly-admin.min.css', array(), $this->version );
		wp_enqueue_script( $this->plugin_name . '-admin-js', TRACKLY_URL . 'Admin/js/trackly-admin.min.js', array( 'jquery', 'apexcharts' ), $this->version, true );

		// Localize Script for REST API URL & Nonce
		wp_localize_script( $this->plugin_name . '-admin-js', 'tracklyData', array(
			'rest_url'   => esc_url_raw( rest_url( 'trackly/v1' ) ),
			'rest_nonce' => wp_create_nonce( 'wp_rest' ),
			'debug'      => defined( 'WP_DEBUG' ) && WP_DEBUG,
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

		$demo_mode = get_option( 'trackly_demo_mode', 'yes' );
		$property_id = get_option( 'trackly_property_id', '' );
		$credentials_encrypted = get_option( 'trackly_credentials', '' );
		$sampling_rate = get_option( 'trackly_sampling_rate', '100' );
		$require_consent = get_option( 'trackly_require_consent', 'yes' );
		
		// Decrypt credentials and mask the private key to prevent screen exposure
		$credentials_raw = Api::decrypt_data( $credentials_encrypted );
		$credentials = '';
		if ( ! empty( $credentials_raw ) ) {
			$creds_obj = json_decode( $credentials_raw, true );
			if ( is_array( $creds_obj ) ) {
				$creds_obj['private_key'] = '___TRACKLY_MASKED_KEY___';
				$credentials = json_encode( $creds_obj, JSON_PRETTY_PRINT );
			}
		}
		$is_connected = ! empty( $property_id ) && ! empty( $credentials_encrypted );

		?>
		<div class="trackly-admin-wrapper">
			<!-- Settings errors output added here (Fixes hidden validation errors QA bug) -->
			<?php settings_errors( 'trackly_credentials' ); ?>

			<!-- Header -->
			<header class="trackly-header">
				<div class="trackly-logo-area">
					<span class="dashicons dashicons-chart-area trackly-logo-icon"></span>
					<h1><?php esc_html_e( 'Trackly', 'metricpulse' ); ?> <span class="trackly-badge">v<?php echo esc_html( $this->version ); ?></span></h1>
				</div>
				<div class="trackly-status-indicator">
					<?php if ( $demo_mode === 'yes' ) : ?>
						<span class="trackly-status demo"><span class="dot"></span> <?php esc_html_e( 'Demo Mode Active', 'metricpulse' ); ?></span>
					<?php elseif ( $is_connected ) : ?>
						<span class="trackly-status connected"><span class="dot"></span> <?php esc_html_e( 'GA4 Connected', 'metricpulse' ); ?></span>
					<?php else : ?>
						<span class="trackly-status disconnected"><span class="dot"></span> <?php esc_html_e( 'GA4 Disconnected', 'metricpulse' ); ?></span>
					<?php endif; ?>
				</div>
			</header>

			<!-- Tabs/Navigation -->
			<div class="trackly-tabs">
				<button class="trackly-tab-btn active" data-target="dashboard-tab">
					<span class="dashicons dashicons-dashboard"></span> <?php esc_html_e( 'Dashboard', 'metricpulse' ); ?>
				</button>
				<button class="trackly-tab-btn" data-target="settings-tab">
					<span class="dashicons dashicons-admin-generic"></span> <?php esc_html_e( 'Settings', 'metricpulse' ); ?>
				</button>
			</div>

			<!-- Dashboard Tab Content -->
			<div id="dashboard-tab" class="trackly-tab-content active">
				<!-- Real-time Banner -->
				<div class="trackly-card trackly-realtime-card">
					<div class="trackly-realtime-content">
						<h3><?php esc_html_e( 'Active Visitors', 'metricpulse' ); ?></h3>
						<div class="trackly-realtime-value">
							<span id="trackly-active-users">--</span>
							<span class="pulse-ring"></span>
						</div>
						<p><?php esc_html_e( 'Active Visitors', 'metricpulse' ); ?></p>
					</div>
					<div class="trackly-realtime-spark"></div>
				</div>

				<!-- Stats Grid -->
				<div class="trackly-grid">
					<div class="trackly-card stat-card">
						<div class="stat-info">
							<h4><?php esc_html_e( 'Pageviews', 'metricpulse' ); ?></h4>
							<h2 id="trackly-stat-views">--</h2>
						</div>
						<span class="dashicons dashicons-visibility stat-icon views"></span>
					</div>
					<div class="trackly-card stat-card">
						<div class="stat-info">
							<h4><?php esc_html_e( 'Unique Visitors', 'metricpulse' ); ?></h4>
							<h2 id="trackly-stat-users">--</h2>
						</div>
						<span class="dashicons dashicons-groups stat-icon users"></span>
					</div>
					<div class="trackly-card stat-card">
						<div class="stat-info">
							<h4><?php esc_html_e( 'Bounce Rate (Unengaged)', 'metricpulse' ); ?></h4>
							<h2 id="trackly-stat-bounce">--</h2>
						</div>
						<span class="dashicons dashicons-exit stat-icon bounce"></span>
					</div>
					<div class="trackly-card stat-card">
						<div class="stat-info">
							<h4><?php esc_html_e( 'Average Session Duration', 'metricpulse' ); ?></h4>
							<h2 id="trackly-stat-duration">--</h2>
						</div>
						<span class="dashicons dashicons-clock stat-icon duration"></span>
					</div>
				</div>

				<!-- GA4 Processing Latency Alert Box -->
				<div class="trackly-card trackly-latency-notice">
					<span class="dashicons dashicons-info trackly-notice-icon"></span>
					<div class="trackly-notice-text">
						<strong><?php esc_html_e( 'GA4 Data Latency Notice', 'metricpulse' ); ?></strong>
						<p><?php esc_html_e( 'Google Analytics 4 (GA4) requires 24-48 hours to process data. Therefore, traffic metrics for yesterday and today may appear lower or incomplete. This latency is temporary.', 'metricpulse' ); ?></p>
					</div>
				</div>

				<!-- Main Graph -->
				<div class="trackly-card main-chart-card">
					<div class="chart-header">
						<h3><?php esc_html_e( 'Visitor Traffic Trend', 'metricpulse' ); ?></h3>
						<div class="chart-actions">
							<button class="trackly-chart-filter-btn active" data-days="7"><?php esc_html_e( 'Last 7 Days', 'metricpulse' ); ?></button>
							<button class="trackly-chart-filter-btn" data-days="30"><?php esc_html_e( 'Last 30 Days', 'metricpulse' ); ?></button>
						</div>
					</div>
					<div class="chart-container">
						<div id="trackly-main-chart"></div>
					</div>
				</div>

				<!-- Bottom Grid (Sources & Devices) -->
				<div class="trackly-grid double">
					<div class="trackly-card chart-half">
						<h3><?php esc_html_e( 'Traffic Sources', 'metricpulse' ); ?></h3>
						<div id="trackly-source-chart"></div>
					</div>
					<div class="trackly-card chart-half">
						<h3><?php esc_html_e( 'Device Distribution', 'metricpulse' ); ?></h3>
						<div id="trackly-device-chart"></div>
					</div>
				</div>

				<!-- Top Pages Table -->
				<div class="trackly-card table-card">
					<h3><?php esc_html_e( 'Top Viewed Pages', 'metricpulse' ); ?></h3>
					<div class="trackly-table-wrapper">
						<table class="trackly-table" id="trackly-pages-table">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Page URL', 'metricpulse' ); ?></th>
									<th><?php esc_html_e( 'Pageviews', 'metricpulse' ); ?></th>
									<th><?php esc_html_e( 'Users', 'metricpulse' ); ?></th>
									<th><?php esc_html_e( 'Bounce Rate', 'metricpulse' ); ?></th>
									<th><?php esc_html_e( 'Avg. Duration', 'metricpulse' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<tr>
									<td colspan="5" class="loading-td"><?php esc_html_e( 'Loading...', 'metricpulse' ); ?></td>
								</tr>
							</tbody>
						</table>
					</div>
				</div>
			</div>

			<!-- Settings Tab Content (WordPress Settings API Integrated) -->
			<div id="settings-tab" class="trackly-tab-content">
				<div class="trackly-card settings-card">
					<h3><?php esc_html_e( 'Google Analytics Integration Settings', 'metricpulse' ); ?></h3>
					<form method="post" action="options.php">
						<?php
						settings_fields( 'trackly_settings_group' );
						do_settings_sections( 'trackly_settings_group' );
						?>

						<div class="trackly-form-group">
							<label class="trackly-switch-label">
								<input type="hidden" name="trackly_demo_mode" value="no">
								<input type="checkbox" name="trackly_demo_mode" value="yes" <?php checked( $demo_mode, 'yes' ); ?>>
								<span class="trackly-switch-slider"></span>
								<div class="label-text">
									<strong><?php esc_html_e( 'Enable Demo (Mock) Mode', 'metricpulse' ); ?></strong>
									<p class="description"><?php esc_html_e( 'Keep this enabled to test the plugin with mock data without connecting to Google Analytics.', 'metricpulse' ); ?></p>
								</div>
							</label>
						</div>

						<div class="trackly-form-group">
							<label class="trackly-switch-label">
								<input type="hidden" name="trackly_require_consent" value="no">
								<input type="checkbox" name="trackly_require_consent" value="yes" <?php checked( $require_consent, 'yes' ); ?>>
								<span class="trackly-switch-slider"></span>
								<div class="label-text">
									<strong><?php esc_html_e( 'Require Cookie Consent (Strict GDPR)', 'metricpulse' ); ?></strong>
									<p class="description"><?php esc_html_e( 'If enabled, the tracker will start in opt-in mode and will block click telemetry until consent is explicitly granted via Borlabs, Complianz, CLI, or Google Consent Mode v2.', 'metricpulse' ); ?></p>
								</div>
							</label>
						</div>

						<div class="trackly-form-group">
							<label for="trackly_property_id"><?php esc_html_e( 'GA4 Property ID', 'metricpulse' ); ?></label>
							<input type="text" id="trackly_property_id" name="trackly_property_id" value="<?php echo esc_attr( $property_id ); ?>" placeholder="e.g. 382901248" class="regular-text">
							<p class="description"><?php esc_html_e( 'The numeric Property ID of your Google Analytics property (Can be found in Admin > Property Settings).', 'metricpulse' ); ?></p>
						</div>

						<div class="trackly-form-group">
							<label for="trackly_credentials"><?php esc_html_e( 'Service Account JSON Key', 'metricpulse' ); ?></label>
							<textarea id="trackly_credentials" name="trackly_credentials" rows="8" class="large-text code" placeholder='{"type": "service_account", ...}'><?php echo esc_textarea( $credentials ); ?></textarea>
							<p class="description"><?php esc_html_e( 'Paste the contents of the Service Account JSON key file you created in the Google Cloud Console. Make sure to add this service account\'s email as a Viewer to your GA4 property.', 'metricpulse' ); ?></p>
						</div>

						<!-- Sampling Rate Option Selector to prevent DB bloat -->
						<div class="trackly-form-group">
							<label for="trackly_sampling_rate"><?php esc_html_e( 'Click Sampling Rate', 'metricpulse' ); ?></label>
							<select id="trackly_sampling_rate" name="trackly_sampling_rate">
								<option value="100" <?php selected( $sampling_rate, '100' ); ?>><?php esc_html_e( '100% (Record all clicks)', 'metricpulse' ); ?></option>
								<option value="50" <?php selected( $sampling_rate, '50' ); ?>><?php esc_html_e( '50% (Record half of all clicks)', 'metricpulse' ); ?></option>
								<option value="25" <?php selected( $sampling_rate, '25' ); ?>><?php esc_html_e( '25% (Record a quarter of all clicks)', 'metricpulse' ); ?></option>
								<option value="10" <?php selected( $sampling_rate, '10' ); ?>><?php esc_html_e( '10% (Record only 10% of clicks - Recommended for high traffic sites)', 'metricpulse' ); ?></option>
							</select>
							<p class="description"><?php esc_html_e( 'For high traffic sites, you can reduce the click tracking frequency to prevent database bloat. Sampling runs on a per-session basis.', 'metricpulse' ); ?></p>
						</div>

						<button type="submit" class="trackly-btn trackly-btn-primary"><?php esc_html_e( 'Save Settings', 'metricpulse' ); ?></button>
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
		register_rest_route( 'trackly/v1', '/stats', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_stats_callback' ),
			'permission_callback' => array( $this, 'check_admin_permissions' ),
		) );

		register_rest_route( 'trackly/v1', '/realtime', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_realtime_callback' ),
			'permission_callback' => array( $this, 'check_admin_permissions' ),
		) );

		register_rest_route( 'trackly/v1', '/page-stats', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_page_stats_callback' ),
			'permission_callback' => array( $this, 'check_admin_permissions' ),
		) );

		register_rest_route( 'trackly/v1', '/record-click', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'record_click_callback' ),
			'permission_callback' => array( $this, 'check_public_click_permissions' ),
		) );

		register_rest_route( 'trackly/v1', '/clicks', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_clicks_callback' ),
			'permission_callback' => array( $this, 'check_admin_permissions' ),
		) );

		register_rest_route( 'trackly/v1', '/save-event', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'save_event_callback' ),
			'permission_callback' => array( $this, 'check_admin_permissions' ),
		) );
	}

	/**
	 * Verify if current user has permission to view reports.
	 */
	public function check_admin_permissions() {
		return current_user_can( 'trackly_view_dashboard' ) || current_user_can( 'manage_options' );
	}

	/**
	 * Verify public click telemetry REST API submissions via public nonce.
	 */
	public function check_public_click_permissions( $request ) {
		// 1. Block common web crawlers and scrapers via User-Agent to prevent database spam
		$user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
		if ( preg_match( '/Googlebot|bingbot|Slurp|Baiduspider|YandexBot|DuckDuckBot|facebookexternalhit|LinkedInBot|Lighthouse|HeadlessChrome/i', $user_agent ) ) {
			return false;
		}

		// 2. Prevent Cross-Origin click logging by verifying that Origin or Referer header matches the host domain
		$origin = isset( $_SERVER['HTTP_ORIGIN'] ) ? esc_url_raw( wp_unslash( $_SERVER['HTTP_ORIGIN'] ) ) : '';
		$referer = isset( $_SERVER['HTTP_REFERER'] ) ? esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : '';
		$host = wp_parse_url( home_url(), PHP_URL_HOST );

		if ( ! empty( $origin ) ) {
			$origin_host = wp_parse_url( $origin, PHP_URL_HOST );
			if ( $host !== $origin_host ) {
				return false;
			}
		} elseif ( ! empty( $referer ) ) {
			$referer_host = wp_parse_url( $referer, PHP_URL_HOST );
			if ( $host !== $referer_host ) {
				return false;
			}
		}

		// 3. Verify public telemetry CSRF nonce token
		$nonce = $request->get_header( 'X-WP-Nonce' );
		return (bool) wp_verify_nonce( $nonce, 'trackly_public_clicks' );
	}

	/**
	 * Robust client IP retriever with Cloudflare and Nginx reverse proxy whitelisting (IPv4 & IPv6 support).
	 */
	private function get_ip_address() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '0.0.0.0';

		$cached_proxies = get_option( 'trackly_cf_proxies', array() );
		$default_proxies = ! empty( $cached_proxies ) ? $cached_proxies : array(
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
		$trusted_proxies = apply_filters( 'trackly_trusted_proxies', $default_proxies );

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
		if ( ! in_array( $days, array( 7, 30 ) ) ) {
			$days = 7;
		}

		$start_date = $days === 30 ? '30daysAgo' : '7daysAgo';

		$summary_req = array(
			'dateRanges' => array( array( 'startDate' => $start_date, 'endDate' => 'yesterday' ) ),
			'metrics'    => array(
				array( 'name' => 'screenPageViews' ),
				array( 'name' => 'activeUsers' ),
				array( 'name' => 'bounceRate' ),
				array( 'name' => 'averageSessionDuration' ),
			),
		);

		$chart_req = array(
			'dateRanges' => array( array( 'startDate' => $start_date, 'endDate' => 'yesterday' ) ),
			'dimensions' => array( array( 'name' => 'date' ) ),
			'metrics'    => array(
				array( 'name' => 'screenPageViews' ),
				array( 'name' => 'activeUsers' ),
				array( 'name' => 'bounceRate' ),
				array( 'name' => 'averageSessionDuration' ),
			),
		);

		$sources_req = array(
			'dateRanges' => array( array( 'startDate' => $start_date, 'endDate' => 'yesterday' ) ),
			'dimensions' => array( array( 'name' => 'sessionDefaultChannelGroup' ) ),
			'metrics'    => array( array( 'name' => 'activeUsers' ) ),
		);

		$devices_req = array(
			'dateRanges' => array( array( 'startDate' => $start_date, 'endDate' => 'yesterday' ) ),
			'dimensions' => array( array( 'name' => 'deviceCategory' ) ),
			'metrics'    => array( array( 'name' => 'activeUsers' ) ),
		);

		$pages_req = array(
			'dateRanges' => array( array( 'startDate' => $start_date, 'endDate' => 'yesterday' ) ),
			'dimensions' => array( array( 'name' => 'pagePath' ) ),
			'metrics'    => array(
				array( 'name' => 'screenPageViews' ),
				array( 'name' => 'activeUsers' ),
				array( 'name' => 'bounceRate' ),
				array( 'name' => 'averageSessionDuration' ),
			),
		);

		$batch_report = Api::batch_run_reports( array(
			$summary_req,
			$chart_req,
			$sources_req,
			$devices_req,
			$pages_req,
		) );

		if ( is_wp_error( $batch_report ) ) {
			return new WP_REST_Response( array( 'success' => false, 'error' => $batch_report->get_error_message() ), 500 );
		}

		$realtime_users = Api::get_realtime_users();

		return new WP_REST_Response( array(
			'success'        => true,
			'summary'        => isset( $batch_report['reports'][0] ) ? $batch_report['reports'][0] : array(),
			'chart'          => isset( $batch_report['reports'][1] ) ? $batch_report['reports'][1] : array(),
			'sources'        => isset( $batch_report['reports'][2] ) ? $batch_report['reports'][2] : array(),
			'devices'        => isset( $batch_report['reports'][3] ) ? $batch_report['reports'][3] : array(),
			'pages'          => isset( $batch_report['reports'][4] ) ? $batch_report['reports'][4] : array(),
			'realtime_users' => $realtime_users,
		), 200 );
	}

	/**
	 * REST Callback for lightweight realtime polling.
	 */
	public function get_realtime_callback( $request ) {
		$realtime_users = Api::get_realtime_users();
		return new WP_REST_Response( array(
			'success'        => true,
			'realtime_users' => $realtime_users,
		), 200 );
	}

	/**
	 * REST Callback for single page stats.
	 */
	public function get_page_stats_callback( $request ) {
		$url = esc_url_raw( $request->get_param( 'url' ) );
		if ( empty( $url ) ) {
			return new WP_REST_Response( array( 'success' => false, 'error' => __( 'URL parameter required.', 'metricpulse' ) ), 400 );
		}

		$path = wp_parse_url( $url, PHP_URL_PATH );
		if ( empty( $path ) ) {
			$path = '/';
		}

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
				'dimensionFilter' => array(
					'filter' => array(
						'fieldName' => 'pagePath',
						'stringFilter' => array(
							'matchType' => 'EXACT',
							'value'     => $path,
						),
					),
				),
			),
			// Query 2: Daily trend breakdown for statistical anomaly checks
			array(
				'dateRanges'      => array( array( 'startDate' => '7daysAgo', 'endDate' => 'yesterday' ) ),
				'dimensions'      => array( array( 'name' => 'date' ) ),
				'metrics'         => array(
					array( 'name' => 'screenPageViews' ),
				),
				'dimensionFilter' => array(
					'filter' => array(
						'fieldName' => 'pagePath',
						'stringFilter' => array(
							'matchType' => 'EXACT',
							'value'     => $path,
						),
					),
				),
			),
		);

		$batch_reports = Api::batch_run_reports( $batch_requests );

		if ( is_wp_error( $batch_reports ) ) {
			return new WP_REST_Response( array( 'success' => false, 'error' => $batch_reports->get_error_message() ), 500 );
		}

		$report       = isset( $batch_reports[0] ) ? $batch_reports[0] : array();
		$daily_report = isset( $batch_reports[1] ) ? $batch_reports[1] : array();

		// Generate standard-deviation based insights
		$heatmap_service = new \Trackly\Includes\Service\HeatmapService();
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
		$ip = $this->get_ip_address();
		$transient_key = 'trackly_rate_' . md5( $ip );
		$clicks = get_transient( $transient_key );

		if ( false === $clicks ) {
			set_transient( $transient_key, 1, 60 );
		} elseif ( $clicks >= 10 ) {
			return new WP_REST_Response( array( 'success' => false, 'error' => __( 'Rate limit exceeded.', 'metricpulse' ) ), 429 );
		} else {
			set_transient( $transient_key, $clicks + 1, 60 );
		}

		$params = $request->get_json_params();
		if ( empty( $params['page_url'] ) || ! isset( $params['click_x_pct'] ) || ! isset( $params['click_y_pct'] ) ) {
			return new WP_REST_Response( array( 'success' => false, 'error' => __( 'Invalid click data.', 'metricpulse' ) ), 400 );
		}

		$log_result = Database::log_click( array(
			'page_url'         => $params['page_url'],
			'element_tag'      => isset( $params['element_tag'] ) ? sanitize_text_field( $params['element_tag'] ) : 'unknown',
			'element_selector' => isset( $params['element_selector'] ) ? sanitize_text_field( $params['element_selector'] ) : '',
			'click_x_pct'      => floatval( $params['click_x_pct'] ),
			'click_y_pct'      => floatval( $params['click_y_pct'] ),
		) );

		return new WP_REST_Response( array( 'success' => (bool) $log_result ), 200 );
	}

	/**
	 * REST Callback to retrieve clicks.
	 */
	public function get_clicks_callback( $request ) {
		$url = esc_url_raw( $request->get_param( 'url' ) );
		if ( empty( $url ) ) {
			return new WP_REST_Response( array( 'success' => false, 'error' => __( 'URL parameter required.', 'metricpulse' ) ), 400 );
		}

		$clicks = Database::get_clicks_for_page( $url );

		return new WP_REST_Response( array(
			'success' => true,
			'clicks'  => $clicks,
		), 200 );
	}

	/**
	 * REST Callback to save GA4 event mapping.
	 * Relaxed regex to only block HTML tag enjections '<' and '>', resolving quotes regression.
	 */
	public function save_event_callback( $request ) {
		$params = $request->get_json_params();
		if ( empty( $params['selector'] ) || empty( $params['event_name'] ) ) {
			return new WP_REST_Response( array( 'success' => false, 'error' => __( 'Selector and Event Name required.', 'metricpulse' ) ), 400 );
		}

		$selector = sanitize_text_field( $params['selector'] );
		// Sanity check CSS selector syntax (blocks HTML tags, curly braces)
		if ( preg_match( '/[<>\{\}]/i', $selector ) ) {
			return new WP_REST_Response( array( 'success' => false, 'error' => __( 'Invalid CSS Selector payload.', 'metricpulse' ) ), 400 );
		}

		$saved_events = get_option( 'trackly_custom_events', array() );
		if ( ! is_array( $saved_events ) ) {
			$saved_events = array();
		}

		// Limit saved events to max 200 elements to prevent options database bloat
		if ( count( $saved_events ) >= 200 ) {
			return new WP_REST_Response( array( 'success' => false, 'error' => __( 'Maximum limit of 200 custom events reached.', 'metricpulse' ) ), 400 );
		}

		// Prevent duplicate selectors
		foreach ( $saved_events as $event ) {
			if ( $event['selector'] === $selector ) {
				return new WP_REST_Response( array( 'success' => false, 'error' => __( 'This CSS selector is already registered.', 'metricpulse' ) ), 400 );
			}
		}

		$saved_events[] = array(
			'selector'   => $selector,
			'event_name' => sanitize_key( $params['event_name'] ),
			'created_at' => current_time( 'mysql' ),
		);

		update_option( 'trackly_custom_events', $saved_events );

		return new WP_REST_Response( array( 'success' => true, 'events' => $saved_events ), 200 );
	}
}
