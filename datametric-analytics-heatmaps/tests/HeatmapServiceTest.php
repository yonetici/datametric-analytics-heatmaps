<?php
use PHPUnit\Framework\TestCase;
use DataMetric\Includes\Service\HeatmapService;

/**
 * Unit tests for the standard-deviation anomaly engine.
 */
class HeatmapServiceTest extends TestCase {

	private HeatmapService $service;

	protected function setUp(): void {
		$this->service = new HeatmapService();
	}

	public function test_empty_values_return_zeroed_result() {
		$result = $this->service->detect_anomalies( array() );
		$this->assertSame( 0.0, $result['mean'] );
		$this->assertSame( 0.0, $result['std_dev'] );
		$this->assertSame( array(), $result['anomalies'] );
	}

	public function test_mean_and_std_dev_are_correct() {
		$result = $this->service->detect_anomalies( array( 2, 4, 4, 4, 5, 5, 7, 9 ) );
		$this->assertEqualsWithDelta( 5.0, $result['mean'], 0.0001 );
		// Population standard deviation of the classic data set is 2.0.
		$this->assertEqualsWithDelta( 2.0, $result['std_dev'], 0.0001 );
	}

	public function test_detects_a_high_outlier() {
		// A single large spike among small stable values should be flagged.
		$values = array( 10, 10, 10, 10, 100 );
		$result = $this->service->detect_anomalies( $values, 1.5 );
		$this->assertArrayHasKey( 4, $result['anomalies'] );
		$this->assertSame( 'high', $result['anomalies'][4]['direction'] );
	}

	public function test_uniform_values_have_no_anomalies() {
		$result = $this->service->detect_anomalies( array( 5, 5, 5, 5 ), 1.0 );
		$this->assertSame( array(), $result['anomalies'] );
	}

	public function test_stable_insight_when_no_anomaly() {
		$chart = array( 'rows' => array(
			array( 'metricValues' => array( array( 'value' => '100' ) ) ),
			array( 'metricValues' => array( array( 'value' => '101' ) ) ),
			array( 'metricValues' => array( array( 'value' => '99' ) ) ),
		) );
		$insights = $this->service->generate_statistical_insights( $chart, array() );
		$this->assertNotEmpty( $insights );
		$this->assertSame( 'info', $insights[0]['type'] );
	}
}
