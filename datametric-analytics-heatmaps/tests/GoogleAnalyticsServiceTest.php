<?php
use PHPUnit\Framework\TestCase;
use DataMetric\Includes\Service\GoogleAnalyticsService;

/**
 * GoogleAnalyticsServiceTest runs unit and integration checks for GA4 connection.
 */
class GoogleAnalyticsServiceTest extends TestCase {

	private $service;

	protected function setUp(): void {
		// Reset in-memory option/transient state so tests do not leak into each other.
		global $mock_options, $mock_transients;
		$mock_options = array();
		$mock_transients = array();

		// Mock encryption salt
		update_option( 'datametric_secure_salt', str_repeat( 's', 64 ) );
		$this->service = new GoogleAnalyticsService();
	}

	public function test_is_demo_mode_default() {
		update_option( 'datametric_demo_mode', 'yes' );
		$this->assertTrue( $this->service->is_demo_mode() );
	}

	public function test_is_demo_mode_false_when_configured() {
		update_option( 'datametric_demo_mode', 'no' );
		update_option( 'datametric_property_id', '123456789' );

		// Encrypt valid dummy JSON credentials so decryption passes
		$encrypted = $this->service->encrypt_data( '{"private_key": "mysecretkey", "client_email": "myemail"}' );
		update_option( 'datametric_credentials', $encrypted );

		$this->assertFalse( $this->service->is_demo_mode() );
	}

	public function test_connection_state_demo() {
		update_option( 'datametric_demo_mode', 'yes' );
		$this->assertSame( 'demo', $this->service->get_connection_state() );
	}

	public function test_connection_state_misconfigured_when_demo_off_and_no_credentials() {
		update_option( 'datametric_demo_mode', 'no' );
		delete_option( 'datametric_property_id' );
		delete_option( 'datametric_credentials' );
		// is_demo_mode must NOT silently flip to true — it must report the real (unconfigured) state.
		$this->assertFalse( $this->service->is_demo_mode() );
		$this->assertSame( 'misconfigured', $this->service->get_connection_state() );
	}

	public function test_connection_state_connected_when_credentials_present() {
		update_option( 'datametric_demo_mode', 'no' );
		update_option( 'datametric_property_id', '123456789' );
		$encrypted = $this->service->encrypt_data( '{"private_key": "k", "client_email": "e"}' );
		update_option( 'datametric_credentials', $encrypted );
		$this->assertSame( 'connected', $this->service->get_connection_state() );
	}

	public function test_misconfigured_batch_returns_error_not_mock() {
		update_option( 'datametric_demo_mode', 'no' );
		delete_option( 'datametric_property_id' );
		delete_option( 'datametric_credentials' );
		$result = $this->service->batch_run_reports( array( array( 'metrics' => array() ) ) );
		$this->assertInstanceOf( 'WP_Error', $result );
	}

	public function test_encryption_roundtrip_with_binary_heavy_payload() {
		// Guards against the old '::' delimiter bug: random binary ciphertext must still decrypt.
		for ( $i = 0; $i < 25; $i++ ) {
			$secret = base64_encode( random_bytes( 64 ) ) . ':: :: ::';
			$encrypted = $this->service->encrypt_data( $secret );
			$this->assertNotEmpty( $encrypted );
			$this->assertSame( $secret, $this->service->decrypt_data( $encrypted ) );
		}
	}

	public function test_demo_channel_and_device_rows_have_dimension_values() {
		update_option( 'datametric_demo_mode', 'yes' );
		$reports = $this->service->batch_run_reports( array(
			array( 'dateRanges' => array( array( 'startDate' => '7daysAgo', 'endDate' => 'yesterday' ) ), 'dimensions' => array( array( 'name' => 'sessionDefaultChannelGroup' ) ), 'metrics' => array( array( 'name' => 'activeUsers' ) ) ),
			array( 'dateRanges' => array( array( 'startDate' => '7daysAgo', 'endDate' => 'yesterday' ) ), 'dimensions' => array( array( 'name' => 'deviceCategory' ) ), 'metrics' => array( array( 'name' => 'activeUsers' ) ) ),
			array( 'dateRanges' => array( array( 'startDate' => '7daysAgo', 'endDate' => 'yesterday' ) ), 'dimensions' => array( array( 'name' => 'pagePath' ) ), 'metrics' => array( array( 'name' => 'screenPageViews' ) ) ),
		) );

		foreach ( $reports as $report ) {
			$this->assertNotEmpty( $report['rows'] );
			foreach ( $report['rows'] as $row ) {
				// Regression guard: every dimensioned demo row must carry a dimension value,
				// otherwise the dashboard JS throws on row.dimensionValues[0].value.
				$this->assertNotEmpty( $row['dimensionValues'], 'Dimensioned demo row is missing dimensionValues.' );
				$this->assertArrayHasKey( 'value', $row['dimensionValues'][0] );
			}
		}
	}

	public function test_demo_supports_acquisition_geo_events_dimensions() {
		update_option( 'datametric_demo_mode', 'yes' );
		$dims = array( 'sessionSourceMedium', 'landingPage', 'country', 'eventName', 'newVsReturning' );
		foreach ( $dims as $dim ) {
			$report = $this->service->get_report( array(
				'dateRanges' => array( array( 'startDate' => '7daysAgo', 'endDate' => 'yesterday' ) ),
				'dimensions' => array( array( 'name' => $dim ) ),
				'metrics'    => array( array( 'name' => 'sessions' ), array( 'name' => 'activeUsers' ), array( 'name' => 'engagementRate' ), array( 'name' => 'keyEvents' ) ),
			) );
			$this->assertNotEmpty( $report['rows'], "No demo rows for dimension {$dim}" );
			$row = $report['rows'][0];
			$this->assertNotEmpty( $row['dimensionValues'][0]['value'], "Empty dimension value for {$dim}" );
			// Every requested metric must be present and numeric-castable.
			$this->assertCount( 4, $row['metricValues'], "Metric count mismatch for {$dim}" );
			$this->assertTrue( is_numeric( $row['metricValues'][0]['value'] ) );
		}
	}

	public function test_demo_metrics_are_internally_consistent() {
		update_option( 'datametric_demo_mode', 'yes' );
		$report = $this->service->get_report( array(
			'dateRanges' => array( array( 'startDate' => '7daysAgo', 'endDate' => 'yesterday' ) ),
			'metrics'    => array( array( 'name' => 'activeUsers' ), array( 'name' => 'sessions' ), array( 'name' => 'screenPageViews' ), array( 'name' => 'engagementRate' ), array( 'name' => 'bounceRate' ) ),
		) );
		$mv = $report['rows'][0]['metricValues'];
		$users    = (float) $mv[0]['value'];
		$sessions = (float) $mv[1]['value'];
		$views    = (float) $mv[2]['value'];
		$engage   = (float) $mv[3]['value'];
		$bounce   = (float) $mv[4]['value'];
		$this->assertGreaterThanOrEqual( $users, $sessions, 'Sessions should be >= users' );
		$this->assertGreaterThanOrEqual( $sessions, $views, 'Pageviews should be >= sessions' );
		$this->assertEqualsWithDelta( 1.0, $engage + $bounce, 0.001, 'engagementRate + bounceRate should equal 1' );
	}

	public function test_demo_realtime_series_has_30_buckets() {
		update_option( 'datametric_demo_mode', 'yes' );
		$series = $this->service->get_realtime_series();
		$this->assertCount( 30, $series );
		$this->assertArrayHasKey( 'minute', $series[0] );
		$this->assertArrayHasKey( 'users', $series[0] );
	}

	public function test_demo_chart_scales_with_date_range() {
		update_option( 'datametric_demo_mode', 'yes' );
		$make = function ( $start ) {
			return array( 'dateRanges' => array( array( 'startDate' => $start, 'endDate' => 'yesterday' ) ), 'dimensions' => array( array( 'name' => 'date' ) ), 'metrics' => array( array( 'name' => 'screenPageViews' ), array( 'name' => 'activeUsers' ) ) );
		};
		$seven  = $this->service->get_report( $make( '7daysAgo' ) );
		$thirty = $this->service->get_report( $make( '30daysAgo' ) );
		$this->assertCount( 7, $seven['rows'] );
		$this->assertCount( 30, $thirty['rows'] );
	}

	public function test_encryption_key_is_256_bit() {
		$method = new ReflectionMethod( $this->service, 'get_encryption_key' );
		$method->setAccessible( true );
		$key = $method->invoke( $this->service );
		// Raw AES-256 key must be exactly 32 bytes.
		$this->assertSame( 32, strlen( $key ) );
	}

	public function test_remote_ga_api_integration() {
		// Skip unless secure JSON config environment variable is explicitly provided (Step 7: Integration testing)
		$json_config = getenv( 'DATAMETRIC_GA_JSON' );
		if ( ! $json_config && defined( 'DATAMETRIC_GA_JSON' ) ) {
			$json_config = DATAMETRIC_GA_JSON;
		}

		if ( ! $json_config ) {
			$this->markTestSkipped( 'DATAMETRIC_GA_JSON not defined. Skipping live Google Analytics API integration test.' );
		}

		// Configure live service credentials from constant/env
		if ( ! defined( 'DATAMETRIC_GA_JSON' ) ) {
			define( 'DATAMETRIC_GA_JSON', $json_config );
		}
		update_option( 'datametric_property_id', getenv( 'DATAMETRIC_GA_PROPERTY_ID' ) ?: '123456789' );

		$report = $this->service->get_report( array(
			'dateRanges' => array( array( 'startDate' => '7daysAgo', 'endDate' => 'yesterday' ) ),
			'metrics'    => array( array( 'name' => 'screenPageViews' ) ),
		) );

		$this->assertNotInstanceOf( 'WP_Error', $report );
		$this->assertArrayHasKey( 'rowCount', $report );
	}
}
