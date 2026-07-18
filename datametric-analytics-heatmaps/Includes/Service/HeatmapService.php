<?php
declare(strict_types=1);

namespace DataMetric\Includes\Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * HeatmapService handles click telemetry aggregations and standard deviation anomaly detection.
 * Implemented using strict types and modern PHP constructor promotion.
 */
class HeatmapService {

	/**
	 * Constructor.
	 */
	public function __construct() {}

	/**
	 * Run statistical standard deviation anomaly detection on a set of numeric values.
	 * Flags values that deviate from the mean by more than a given standard deviation threshold.
	 */
	public function detect_anomalies( array $values, float $threshold = 2.0 ): array {
		$count = count( $values );
		if ( $count === 0 ) {
			return array(
				'mean'      => 0.0,
				'std_dev'   => 0.0,
				'anomalies' => array(),
			);
		}

		$mean = array_sum( $values ) / $count;

		$variance = 0.0;
		foreach ( $values as $val ) {
			$variance += pow( $val - $mean, 2 );
		}
		$variance = $variance / $count;
		$std_dev = sqrt( $variance );

		$anomalies = array();
		foreach ( $values as $index => $val ) {
			if ( $std_dev > 0.0 ) {
				$deviation = abs( $val - $mean ) / $std_dev;
				if ( $deviation >= $threshold ) {
					$anomalies[ $index ] = array(
						'value'     => $val,
						'deviation' => $deviation,
						'direction' => ( $val > $mean ) ? 'high' : 'low',
					);
				}
			}
		}

		return array(
			'mean'      => $mean,
			'std_dev'   => $std_dev,
			'anomalies' => $anomalies,
		);
	}

	/**
	 * Run anomaly checks on page statistics (views and bounce rates) and generate mathematical insights.
	 */
	public function generate_statistical_insights( array $chart_data, array $page_data ): array {
		$insights = array();

		// 1. Analyze views over past days for traffic spikes
		$daily_views = array();
		if ( isset( $chart_data['rows'] ) && is_array( $chart_data['rows'] ) ) {
			foreach ( $chart_data['rows'] as $row ) {
				if ( isset( $row['metricValues'][0]['value'] ) ) {
					$daily_views[] = intval( $row['metricValues'][0]['value'] );
				}
			}
		}

		if ( count( $daily_views ) >= 3 ) {
			$analysis = $this->detect_anomalies( $daily_views, 1.8 ); // 1.8 std dev threshold
			$today_val = end( $daily_views );
			$today_index = key( $daily_views );

			if ( isset( $analysis['anomalies'][ $today_index ] ) ) {
				$anomaly = $analysis['anomalies'][ $today_index ];
				$dev = round( $anomaly['deviation'], 1 );
				if ( $anomaly['direction'] === 'high' ) {
					$insights[] = array(
						'type'        => 'success',
						'title'       => __( 'Significant Traffic Spike Detected', 'datametric-analytics-heatmaps' ),
						'description' => sprintf(
							/* translators: %s: standard deviations value */
							__( 'Today\'s traffic is %s standard deviations above the 7-day average. Verify active promotion campaigns or organic search ranking spikes.', 'datametric-analytics-heatmaps' ),
							$dev
						),
					);
				} else {
					$insights[] = array(
						'type'        => 'warning',
						'title'       => __( 'Significant Traffic Drop Detected', 'datametric-analytics-heatmaps' ),
						'description' => sprintf(
							/* translators: %s: standard deviations value */
							__( 'Today\'s traffic is %s standard deviations below the 7-day average. Check for connection issues or broken landing pages.', 'datametric-analytics-heatmaps' ),
							$dev
						),
					);
				}
			}
		}

		// 2. Analyze page-level bounce rate outliers
		$bounce_rates = array();
		if ( isset( $page_data['rows'] ) && is_array( $page_data['rows'] ) ) {
			foreach ( $page_data['rows'] as $row ) {
				if ( isset( $row['metricValues'][2]['value'] ) ) {
					$bounce_rates[] = floatval( $row['metricValues'][2]['value'] ) * 100;
				}
			}
		}

		if ( count( $bounce_rates ) >= 3 ) {
			$analysis = $this->detect_anomalies( $bounce_rates, 1.5 ); // 1.5 std dev threshold for page-level anomalies
			foreach ( $analysis['anomalies'] as $idx => $anomaly ) {
				if ( isset( $page_data['rows'][ $idx ]['dimensionValues'][0]['value'] ) ) {
					$page_path = $page_data['rows'][ $idx ]['dimensionValues'][0]['value'];
					$dev = round( $anomaly['deviation'], 1 );
					if ( $anomaly['direction'] === 'high' && $anomaly['value'] > 65.0 ) {
						$insights[] = array(
							'type'        => 'danger',
							'title'       => __( 'High Bounce Rate Anomaly', 'datametric-analytics-heatmaps' ),
							'description' => sprintf(
								/* translators: 1: page path, 2: bounce rate percentage, 3: standard deviations value */
								__( 'Page "%1$s" has a bounce rate of %2$s%% (%3$s standard deviations above average). We suggest reviewing the page layout, load speed, or call-to-actions.', 'datametric-analytics-heatmaps' ),
								esc_html( $page_path ),
								round( $anomaly['value'], 1 ),
								$dev
							),
						);
					}
				}
			}
		}

		// Default positive insight if no anomalies detected
		if ( empty( $insights ) ) {
			$insights[] = array(
				'type'        => 'info',
				'title'       => __( 'Stable Page Performance', 'datametric-analytics-heatmaps' ),
				'description' => __( 'All visitor traffic volumes and bounce rate patterns match normal statistical distributions for the current period.', 'datametric-analytics-heatmaps' ),
			);
		}

		return $insights;
	}
}
