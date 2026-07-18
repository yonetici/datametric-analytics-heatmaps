<?php
declare(strict_types=1);

namespace DataMetric\Includes\Service;

use DataMetric\Includes\Exception\DataMetricException;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * GoogleAnalyticsService handles GA4 OAuth2 tokens, report executions, and credentials encryption.
 * Implemented using strict types and modern PHP constructor promotion.
 */
class GoogleAnalyticsService {

	const TOKEN_KEY = 'datametric_access_token';
	const BATCH_PREFIX = 'datametric_b_';
	const REALTIME_KEY = 'datametric_realtime_cache';
	const CACHE_VERSION_KEY = 'datametric_cache_ver';

	// Sentinel returned by get_realtime_users() when the value could not be retrieved (distinct from a genuine 0).
	const REALTIME_UNAVAILABLE = -1;

	/**
	 * Constructor.
	 */
	public function __construct() {}

	/**
	 * Current report-cache generation number. Bumping it (see flush_cache) invalidates every
	 * previously stored report key at once, which works even when an external object cache
	 * (Redis/Memcached) is active and transients never touch the options table.
	 */
	private function cache_version(): int {
		return (int) get_option( self::CACHE_VERSION_KEY, 1 );
	}

	/**
	 * Flush all cached GA4 API data by rotating the cache generation and clearing hot keys.
	 */
	public function flush_cache(): void {
		delete_transient( self::TOKEN_KEY );
		delete_transient( self::REALTIME_KEY );
		delete_transient( self::REALTIME_KEY . '_series' );

		// Rotate the generation so all versioned batch keys become unreachable regardless of backend.
		update_option( self::CACHE_VERSION_KEY, $this->cache_version() + 1 );

		// Best-effort cleanup of any DB-stored batch transients (no-op under external object cache).
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( '_transient_' . self::BATCH_PREFIX ) . '%' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( '_transient_timeout_' . self::BATCH_PREFIX ) . '%' ) );
	}

	// AES-256-GCM binary layout constants (lengths are fixed by the cipher).
	const GCM_IV_LEN = 12;
	const GCM_TAG_LEN = 16;

	/**
	 * Derive a 32-byte (256-bit) AES key from the site salts and a persisted per-site secret salt.
	 *
	 * Uses the raw binary sha256 digest (not the 64-char hex string) so the full 256 bits of
	 * entropy actually reach AES-256. The per-site salt is generated on demand if it is missing
	 * so there is never a shared, hard-coded fallback key across installs.
	 */
	private function get_encryption_key(): string {
		$key = '';
		if ( defined( 'SECURE_AUTH_KEY' ) ) {
			$key .= SECURE_AUTH_KEY;
		}
		if ( defined( 'NONCE_KEY' ) ) {
			$key .= NONCE_KEY;
		}

		$secure_salt = get_option( 'datametric_secure_salt' );
		if ( empty( $secure_salt ) ) {
			$secure_salt = wp_generate_password( 64, true, true );
			update_option( 'datametric_secure_salt', $secure_salt, 'no' );
		}
		$key .= $secure_salt;

		// Raw 32-byte digest => true AES-256 key length.
		return hash( 'sha256', $key, true );
	}

	/**
	 * Encrypt credentials before saving to DB using AES-256-GCM.
	 * Stored layout (base64): [12-byte IV][16-byte tag][ciphertext].
	 */
	public function encrypt_data( string $data ): string {
		if ( '' === $data ) {
			return '';
		}
		$key = $this->get_encryption_key();
		$iv = openssl_random_pseudo_bytes( self::GCM_IV_LEN );
		$tag = '';
		$encrypted = openssl_encrypt( $data, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag );
		if ( false === $encrypted ) {
			return '';
		}
		// Concatenate fixed-length fields; never split on a delimiter that could occur in binary output.
		return base64_encode( $iv . $tag . $encrypted );
	}

	/**
	 * Decrypt credentials from DB using AES-256-GCM.
	 */
	public function decrypt_data( string $data ): string {
		if ( '' === $data ) {
			return '';
		}
		$key = $this->get_encryption_key();
		$decoded = base64_decode( $data, true );
		if ( false === $decoded || strlen( $decoded ) <= ( self::GCM_IV_LEN + self::GCM_TAG_LEN ) ) {
			return '';
		}

		$iv = substr( $decoded, 0, self::GCM_IV_LEN );
		$tag = substr( $decoded, self::GCM_IV_LEN, self::GCM_TAG_LEN );
		$encrypted = substr( $decoded, self::GCM_IV_LEN + self::GCM_TAG_LEN );

		$decrypted = openssl_decrypt( $encrypted, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag );
		return ( false !== $decrypted ) ? $decrypted : '';
	}

	/**
	 * Get Google Service Account JSON from multiple secure configuration sources.
	 * Checks constant, environment variables, filesystem secrets, and option table.
	 */
	private function get_credentials_json(): string {
		if ( defined( 'DATAMETRIC_GA_JSON' ) && ! empty( DATAMETRIC_GA_JSON ) ) {
			return DATAMETRIC_GA_JSON;
		}

		$env_val = getenv( 'DATAMETRIC_GA_JSON' );
		if ( $env_val && ! empty( $env_val ) ) {
			return $env_val;
		}

		if ( file_exists( '/etc/secrets/datametric.json' ) ) {
			// Reading a local, server-operator-provided secrets file (not a remote URL); WP_Filesystem is unnecessary here.
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$file_val = file_get_contents( '/etc/secrets/datametric.json' );
			if ( $file_val ) {
				return $file_val;
			}
		}

		$credentials_encrypted = get_option( 'datametric_credentials' );
		if ( ! empty( $credentials_encrypted ) ) {
			return $this->decrypt_data( (string) $credentials_encrypted );
		}

		return '';
	}

	/**
	 * Whether Demo (mock) Mode is explicitly enabled.
	 *
	 * This intentionally reflects ONLY the user's setting. It must not silently flip to true
	 * when credentials are missing/broken — doing so would present random mock numbers as if
	 * they were real analytics. Callers use get_connection_state()/is_configured() to tell a
	 * misconfiguration apart from an intentional demo.
	 */
	public function is_demo_mode(): bool {
		return get_option( 'datametric_demo_mode', 'yes' ) === 'yes';
	}

	/**
	 * Whether real GA4 credentials are present and structurally usable.
	 */
	public function is_configured(): bool {
		$property_id = get_option( 'datametric_property_id' );
		if ( empty( $property_id ) ) {
			return false;
		}
		$credentials_json = $this->get_credentials_json();
		if ( empty( $credentials_json ) ) {
			return false;
		}
		$creds = json_decode( $credentials_json, true );
		return is_array( $creds ) && ! empty( $creds['private_key'] ) && ! empty( $creds['client_email'] );
	}

	/**
	 * Resolve the connection state for UI/badges: 'demo', 'connected', or 'misconfigured'.
	 */
	public function get_connection_state(): string {
		if ( $this->is_demo_mode() ) {
			return 'demo';
		}
		return $this->is_configured() ? 'connected' : 'misconfigured';
	}

	/**
	 * Get GA4 Access Token using Service Account JSON.
	 */
	private function get_access_token(): string|WP_Error {
		$token = get_transient( self::TOKEN_KEY );
		if ( $token ) {
			return $token;
		}

		$credentials_json = $this->get_credentials_json();
		if ( empty( $credentials_json ) ) {
			return new WP_Error( 'no_credentials', __( 'Google Service Account credentials missing.', 'datametric-analytics-heatmaps' ) );
		}

		$creds = json_decode( $credentials_json, true );
		if ( ! is_array( $creds ) || empty( $creds['private_key'] ) || empty( $creds['client_email'] ) ) {
			return new WP_Error( 'invalid_credentials', __( 'Invalid Google Service Account JSON structure.', 'datametric-analytics-heatmaps' ) );
		}

		// Generate JWT
		$header = $this->base64url_encode( (string) wp_json_encode( array(
			'alg' => 'RS256',
			'typ' => 'JWT',
		) ) );

		$now = time();
		$payload = $this->base64url_encode( (string) wp_json_encode( array(
			'iss'   => $creds['client_email'],
			'scope' => 'https://www.googleapis.com/auth/analytics.readonly',
			'aud'   => 'https://oauth2.googleapis.com/token',
			'exp'   => $now + 3600,
			// Backdate iat slightly to tolerate minor server clock skew against Google's servers.
			'iat'   => $now - 30,
		) ) );

		$header_payload = $header . '.' . $payload;

		$signature = '';
		$pkey = openssl_pkey_get_private( $creds['private_key'] );
		if ( ! $pkey ) {
			return new WP_Error( 'invalid_private_key', __( 'Could not parse private key.', 'datametric-analytics-heatmaps' ) );
		}

		if ( ! openssl_sign( $header_payload, $signature, $pkey, 'SHA256' ) ) {
			return new WP_Error( 'signature_failed', __( 'Failed to sign JWT.', 'datametric-analytics-heatmaps' ) );
		}

		$jwt = $header_payload . '.' . $this->base64url_encode( $signature );

		// Fetch access token with 15 second timeout limit
		$response = wp_remote_post( 'https://oauth2.googleapis.com/token', array(
			'timeout' => 15,
			'body'    => array(
				'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
				'assertion'  => $jwt,
			),
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $body['access_token'] ) ) {
			return new WP_Error( 'token_error', isset( $body['error_description'] ) ? $body['error_description'] : __( 'Unknown token error.', 'datametric-analytics-heatmaps' ) );
		}

		$access_token = $body['access_token'];
		set_transient( self::TOKEN_KEY, $access_token, 55 * MINUTE_IN_SECONDS );

		return $access_token;
	}

	/**
	 * Run a Batch of Reports to optimize quota usage and execution time.
	 */
	public function batch_run_reports( array $requests ): array|WP_Error {
		// Demo Mode: return deterministic mock data (explicit user choice).
		if ( $this->is_demo_mode() ) {
			$mock_reports = array();
			foreach ( $requests as $req ) {
				$mock_reports[] = $this->generate_mock_report( $req );
			}
			return $mock_reports;
		}

		// Not demo but not usable => surface an error instead of silently faking data.
		if ( ! $this->is_configured() ) {
			return new WP_Error( 'not_configured', __( 'Google Analytics is not connected. Add a valid Property ID and Service Account JSON, or enable Demo Mode.', 'datametric-analytics-heatmaps' ) );
		}

		// Order-independent cache key (sort recursively) tied to the current cache generation.
		$normalized = $this->normalize_for_cache_key( $requests );
		$cache_key = self::BATCH_PREFIX . $this->cache_version() . '_' . md5( (string) wp_json_encode( $normalized ) );
		$cached = get_transient( $cache_key );
		if ( false !== $cached ) {
			return is_array( $cached ) ? $cached : array();
		}

		$property_id = get_option( 'datametric_property_id', '' );
		$token = $this->get_access_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$url = "https://analyticsdata.googleapis.com/v1beta/properties/{$property_id}:batchRunReports";

		$response = $this->post_ga_request( $url, array( 'requests' => $requests ), (string) $token );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $status ) {
			return new WP_Error( 'ga_api_error', $this->format_api_error( $status, $body ) );
		}

		$reports = isset( $body['reports'] ) ? $body['reports'] : array();
		set_transient( $cache_key, $reports, 10 * MINUTE_IN_SECONDS ); // Cache reports for 10 mins

		return $reports;
	}

	/**
	 * POST a JSON payload to the GA4 Data API with a single retry on transient (429/503) errors.
	 */
	private function post_ga_request( string $url, array $payload, string $token ) {
		$args = array(
			'timeout' => 15,
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
				'Content-Type'  => 'application/json',
			),
			'body'    => wp_json_encode( $payload ),
		);

		$response = wp_remote_post( $url, $args );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status = wp_remote_retrieve_response_code( $response );
		if ( 429 === $status || 503 === $status ) {
			// Brief backoff, then one retry for rate-limit / temporary unavailability.
			sleep( 1 );
			$retry = wp_remote_post( $url, $args );
			if ( ! is_wp_error( $retry ) ) {
				return $retry;
			}
		}

		return $response;
	}

	/**
	 * Build a human-readable message that distinguishes quota, auth, and generic API failures.
	 */
	private function format_api_error( int $status, $body ): string {
		$detail = ( is_array( $body ) && isset( $body['error']['message'] ) ) ? $body['error']['message'] : '';

		if ( 429 === $status ) {
			return __( 'Google Analytics API quota exceeded. Please try again later.', 'datametric-analytics-heatmaps' ) . ( $detail ? ' (' . $detail . ')' : '' );
		}
		if ( 401 === $status || 403 === $status ) {
			return __( 'Access denied by Google Analytics. Confirm the service account is added as a Viewer on the property.', 'datametric-analytics-heatmaps' ) . ( $detail ? ' (' . $detail . ')' : '' );
		}
		if ( $detail ) {
			return $detail;
		}
		return __( 'Failed to query GA4 Data API.', 'datametric-analytics-heatmaps' );
	}

	/**
	 * Recursively sort array keys so logically identical requests map to the same cache key.
	 */
	private function normalize_for_cache_key( array $data ): array {
		ksort( $data );
		foreach ( $data as $key => $value ) {
			if ( is_array( $value ) ) {
				$data[ $key ] = $this->normalize_for_cache_key( $value );
			}
		}
		return $data;
	}

	/**
	 * Retrieve a single report query.
	 */
	public function get_report( array $request_payload ): array|WP_Error {
		$batch = $this->batch_run_reports( array( $request_payload ) );
		if ( is_wp_error( $batch ) ) {
			return $batch;
		}
		return isset( $batch[0] ) ? $batch[0] : array();
	}

	/**
	 * Retrieve current active visitor count (Realtime query).
	 */
	public function get_realtime_users(): int {
		$cached = get_transient( self::REALTIME_KEY );
		if ( false !== $cached ) {
			return intval( $cached );
		}

		if ( $this->is_demo_mode() ) {
			$mock_users = wp_rand( 12, 35 );
			set_transient( self::REALTIME_KEY, $mock_users, 20 ); // Realtime cache 20 seconds
			return $mock_users;
		}

		// Not connected or auth failed: report "unavailable" and DO NOT cache it as 0,
		// so a real zero is never confused with an outage and the next poll retries.
		if ( ! $this->is_configured() ) {
			return self::REALTIME_UNAVAILABLE;
		}

		$property_id = get_option( 'datametric_property_id', '' );
		$token = $this->get_access_token();
		if ( is_wp_error( $token ) ) {
			return self::REALTIME_UNAVAILABLE;
		}

		$url = "https://analyticsdata.googleapis.com/v1beta/properties/{$property_id}:runRealtimeReport";

		$response = wp_remote_post( $url, array(
			'timeout' => 10,
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
				'Content-Type'  => 'application/json',
			),
			'body'    => wp_json_encode( array( 'metrics' => array( array( 'name' => 'activeUsers' ) ) ) ),
		) );

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return self::REALTIME_UNAVAILABLE;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		$active_users = 0;
		if ( isset( $body['rows'][0]['metricValues'][0]['value'] ) ) {
			$active_users = intval( $body['rows'][0]['metricValues'][0]['value'] );
		}

		set_transient( self::REALTIME_KEY, $active_users, 20 );
		return $active_users;
	}

	/**
	 * Safe base64 URL encoder.
	 */
	private function base64url_encode( string $data ): string {
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
	}

	/**
	 * Rich, metric-aware mock GA4 report generator for Demo Mode / sandbox environments.
	 *
	 * For any requested dimension it emits realistic rows, and for EACH requested metric it
	 * derives a self-consistent value from the row's base weight/engagement — so adding new
	 * metrics or dimensions to the dashboard never produces empty or mismatched demo data.
	 */
	private function generate_mock_report( array $request ): array {
		$dimensions   = isset( $request['dimensions'] ) ? $request['dimensions'] : array();
		$metrics      = isset( $request['metrics'] ) ? $request['metrics'] : array();
		$metric_names = array_map( function ( $m ) { return isset( $m['name'] ) ? $m['name'] : ''; }, $metrics );
		$dim_names    = array_map( function ( $d ) { return isset( $d['name'] ) ? $d['name'] : ''; }, $dimensions );
		$primary      = isset( $dim_names[0] ) ? $dim_names[0] : '';

		// Derive the window length from a "NdaysAgo" start date so 7-day and 30-day views differ.
		$days = 7;
		if ( isset( $request['dateRanges'][0]['startDate'] )
			&& preg_match( '/(\d+)daysAgo/', (string) $request['dateRanges'][0]['startDate'], $m ) ) {
			$days = max( 1, (int) $m[1] );
		}

		$entries = $this->mock_dimension_entries( $primary, $days );

		$rows = array();
		foreach ( $entries as $entry ) {
			$rows[] = array(
				'dimensionValues' => array_map(
					function ( $v ) { return array( 'value' => (string) $v ); },
					$entry['dims']
				),
				'metricValues'    => array_map(
					function ( $name ) use ( $entry ) {
						return array( 'value' => (string) $this->mock_metric_value( $name, $entry ) );
					},
					$metric_names
				),
			);
		}

		return array(
			'dimensionHeaders' => array_map( function ( $d ) { return array( 'name' => $d['name'] ); }, $dimensions ),
			'metricHeaders'    => array_map( function ( $m ) { return array( 'name' => $m['name'] ); }, $metrics ),
			'rows'             => $rows,
			'rowCount'         => count( $rows ),
		);
	}

	/**
	 * Build the demo rows (dimension value(s) + a base weight/engagement seed) for a dimension.
	 * Each entry: array( 'dims' => array<string>, 'weight' => int users-base, 'er' => 0..1, 'dur' => seconds ).
	 */
	private function mock_dimension_entries( string $dim, int $days ): array {
		$mk = function ( $dims, $weight, $er = null, $dur = null ) {
			return array(
				'dims'   => (array) $dims,
				'weight' => max( 5, (int) $weight ),
				'er'     => ( null !== $er ) ? $er : wp_rand( 45, 78 ) / 100,
				'dur'    => ( null !== $dur ) ? $dur : wp_rand( 70, 245 ),
			);
		};

		$list = function ( array $map ) use ( $mk ) {
			$out = array();
			foreach ( $map as $label => $weight ) {
				$out[] = $mk( array( $label ), $weight + wp_rand( -80, 120 ) );
			}
			return $out;
		};

		switch ( $dim ) {
			case 'date':
				$out = array();
				for ( $i = $days; $i >= 1; $i-- ) {
					$ts   = strtotime( "-{$i} days" );
					$wknd = in_array( (int) gmdate( 'N', $ts ), array( 6, 7 ), true );
					$p    = ( $days - $i ) / max( 1, $days );
					$w    = (int) round( ( 1150 + $p * 450 ) * ( $wknd ? 0.66 : 1.0 ) ) + wp_rand( -80, 80 );
					$out[] = $mk( array( gmdate( 'Ymd', $ts ) ), $w );
				}
				return $out;

			case 'pagePath':
				return $list( array( '/' => 2100, '/blog' => 1300, '/products' => 1050, '/about' => 640, '/contact' => 470, '/pricing' => 420, '/blog/getting-started' => 320, '/services' => 270 ) );

			case 'landingPage':
				return $list( array( '/' => 1800, '/blog' => 1100, '/pricing' => 720, '/products' => 560, '/campaign/summer' => 430, '/about' => 300 ) );

			case 'sessionDefaultChannelGroup':
				return $list( array( 'Organic Search' => 3400, 'Direct' => 2200, 'Organic Social' => 1500, 'Referral' => 950, 'Paid Search' => 780, 'Email' => 640 ) );

			case 'sessionSourceMedium':
				return $list( array( 'google / organic' => 3100, '(direct) / (none)' => 2200, 'google / cpc' => 780, 'm.facebook.com / referral' => 760, 'newsletter / email' => 620, 'bing / organic' => 640, 't.co / referral' => 540, 'linkedin.com / referral' => 360 ) );

			case 'sessionSource':
				return $list( array( 'google' => 3400, '(direct)' => 2200, 'facebook' => 760, 'bing' => 640, 't.co' => 540, 'newsletter' => 620 ) );

			case 'deviceCategory':
				return $list( array( 'desktop' => 4200, 'mobile' => 3600, 'tablet' => 620 ) );

			case 'browser':
				return $list( array( 'Chrome' => 5200, 'Safari' => 2100, 'Edge' => 780, 'Firefox' => 520, 'Samsung Internet' => 300 ) );

			case 'operatingSystem':
				return $list( array( 'Windows' => 3800, 'iOS' => 2200, 'Android' => 2000, 'macOS' => 1200, 'Linux' => 280 ) );

			case 'country':
				return $list( array( 'United States' => 2600, 'Turkey' => 1500, 'United Kingdom' => 1200, 'India' => 1100, 'Germany' => 980, 'Canada' => 620, 'France' => 540, 'Netherlands' => 360 ) );

			case 'eventName':
				return $list( array( 'page_view' => 4200, 'session_start' => 3400, 'scroll' => 2600, 'user_engagement' => 2400, 'click' => 900, 'form_submit' => 240, 'file_download' => 160 ) );

			case 'newVsReturning':
				return array( $mk( array( 'new' ), 4200, wp_rand( 40, 55 ) / 100 ), $mk( array( 'returning' ), 2600, wp_rand( 62, 82 ) / 100 ) );

			case '':
				// Totals row, scaled to the reporting window length.
				return array( $mk( array(), (int) round( $days * 1150 ) ) );

			default:
				$out = array();
				for ( $k = 1; $k <= 5; $k++ ) {
					$out[] = $mk( array( $dim . '_' . $k ), wp_rand( 200, 2000 ) );
				}
				return $out;
		}
	}

	/**
	 * Derive a plausible value for a single GA4 metric from a row's base weight/engagement seed.
	 * Keeps ratios internally consistent (sessions > users, views > sessions, etc.).
	 */
	private function mock_metric_value( string $name, array $e ) {
		$w        = $e['weight'];
		$er       = $e['er'];
		$dur      = $e['dur'];
		$sessions = (int) round( $w * 1.22 );
		$views    = (int) round( $w * 2.4 );
		$key      = max( 0, (int) round( $sessions * 0.045 ) );

		switch ( $name ) {
			case 'activeUsers':
			case 'totalUsers':
				return ( 'totalUsers' === $name ) ? (int) round( $w * 1.06 ) : $w;
			case 'newUsers':
				return (int) round( $w * 0.42 );
			case 'sessions':
				return $sessions;
			case 'engagedSessions':
				return (int) round( $sessions * $er );
			case 'screenPageViews':
				return $views;
			case 'eventCount':
				return (int) round( $views * 3.6 );
			case 'eventCountPerUser':
				return round( ( $views * 3.6 ) / max( 1, $w ), 2 );
			case 'engagementRate':
				return round( $er, 4 );
			case 'bounceRate':
				return round( 1 - $er, 4 );
			case 'averageSessionDuration':
				return $dur;
			case 'userEngagementDuration':
				return (int) round( $dur * $sessions );
			case 'sessionsPerUser':
				return round( $sessions / max( 1, $w ), 2 );
			case 'screenPageViewsPerSession':
				return round( $views / max( 1, $sessions ), 2 );
			case 'keyEvents':
			case 'conversions':
				return $key;
			case 'totalRevenue':
			case 'purchaseRevenue':
			case 'grossPurchaseRevenue':
				return round( $key * wp_rand( 25, 75 ), 2 );
			default:
				return $w;
		}
	}

	/**
	 * Active users for the last 30 minutes, one bucket per minute (oldest first).
	 * Powers the realtime sparkline. Returns array of array( 'minute' => int, 'users' => int ).
	 */
	public function get_realtime_series(): array {
		$cached = get_transient( self::REALTIME_KEY . '_series' );
		if ( false !== $cached && is_array( $cached ) ) {
			return $cached;
		}

		$series = array();

		if ( $this->is_demo_mode() ) {
			$base = wp_rand( 6, 14 );
			for ( $i = 29; $i >= 0; $i-- ) {
				$series[] = array( 'minute' => $i, 'users' => max( 0, $base + wp_rand( -5, 6 ) ) );
			}
			set_transient( self::REALTIME_KEY . '_series', $series, 20 );
			return $series;
		}

		if ( ! $this->is_configured() ) {
			return array();
		}

		$property_id = get_option( 'datametric_property_id', '' );
		$token       = $this->get_access_token();
		if ( is_wp_error( $token ) ) {
			return array();
		}

		$url      = "https://analyticsdata.googleapis.com/v1beta/properties/{$property_id}:runRealtimeReport";
		$response = wp_remote_post( $url, array(
			'timeout' => 10,
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
				'Content-Type'  => 'application/json',
			),
			'body'    => wp_json_encode( array(
				'dimensions' => array( array( 'name' => 'minutesAgo' ) ),
				'metrics'    => array( array( 'name' => 'activeUsers' ) ),
			) ),
		) );

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return array();
		}

		$body    = json_decode( wp_remote_retrieve_body( $response ), true );
		$buckets = array_fill( 0, 30, 0 );
		if ( isset( $body['rows'] ) && is_array( $body['rows'] ) ) {
			foreach ( $body['rows'] as $row ) {
				$minute = isset( $row['dimensionValues'][0]['value'] ) ? (int) $row['dimensionValues'][0]['value'] : -1;
				$users  = isset( $row['metricValues'][0]['value'] ) ? (int) $row['metricValues'][0]['value'] : 0;
				if ( $minute >= 0 && $minute <= 29 ) {
					$buckets[ $minute ] = $users;
				}
			}
		}
		for ( $i = 29; $i >= 0; $i-- ) {
			$series[] = array( 'minute' => $i, 'users' => $buckets[ $i ] );
		}

		set_transient( self::REALTIME_KEY . '_series', $series, 20 );
		return $series;
	}
}
