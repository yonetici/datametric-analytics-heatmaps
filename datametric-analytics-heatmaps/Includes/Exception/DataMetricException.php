<?php
declare(strict_types=1);

namespace DataMetric\Includes\Exception;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Custom Exception class for DataMetric domain-specific exceptions.
 */
class DataMetricException extends \Exception {}
