<?php
declare(strict_types=1);

namespace DataMetric\Includes\Repository;

use DataMetric\Includes\Exception\DataMetricException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * EventRepository handles custom database operations for click telemetry data.
 * Built with strict types and modern PHP constructor property promotion.
 */
class EventRepository {

	/**
	 * Constructor with property promotion.
	 */
	public function __construct(
		private readonly \wpdb $wpdb,
		private readonly string $table_name
	) {}

	/**
	 * Get the active database table name.
	 */
	public function get_table_name(): string {
		return $this->table_name;
	}

	/**
	 * Create custom database tables.
	 */
	public function create_tables(): void {
		// Strict table name regex checking to eliminate any SQL injection vector on identifier placeholders
		if ( ! preg_match( '/^[a-zA-Z0-9_]+$/', $this->table_name ) ) {
			throw new \DataMetric\Includes\Exception\DataMetricException( 'Invalid database table configuration.' );
		}

		$table_name = $this->table_name;
		$charset_collate = $this->wpdb->get_charset_collate();

		// Create the base table via dbDelta. Prefixed-length indexes such as page_url(191) are
		// intentionally NOT declared here: dbDelta cannot round-trip them and would re-issue an
		// ALTER on every load. They are added once, idempotently, in ensure_indexes().
		$sql = "CREATE TABLE $table_name (
			id bigint(20) NOT NULL AUTO_INCREMENT,
			page_url varchar(255) NOT NULL,
			element_tag varchar(50) NOT NULL,
			element_selector varchar(255) NOT NULL,
			click_x_pct float NOT NULL,
			click_y_pct float NOT NULL,
			created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
			PRIMARY KEY  (id)
		) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		$this->ensure_indexes();
	}

	/**
	 * Add the secondary indexes once, only if they are missing (avoids dbDelta's prefix-index churn).
	 */
	private function ensure_indexes(): void {
		if ( ! preg_match( '/^[a-zA-Z0-9_]+$/', $this->table_name ) ) {
			return;
		}
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$has_page_idx = $this->wpdb->get_results( "SHOW INDEX FROM {$this->table_name} WHERE Key_name = 'page_url_created'" );
		if ( empty( $has_page_idx ) ) {
			$this->wpdb->query( "ALTER TABLE {$this->table_name} ADD INDEX page_url_created (page_url(191), created_at)" );
		}
		$has_created_idx = $this->wpdb->get_results( "SHOW INDEX FROM {$this->table_name} WHERE Key_name = 'created_at'" );
		if ( empty( $has_created_idx ) ) {
			$this->wpdb->query( "ALTER TABLE {$this->table_name} ADD INDEX created_at (created_at)" );
		}
		// phpcs:enable
	}

	public function log_click( array $data ): bool {
		// Filter raw click data before saving (Step 2: Custom Extensibility Hooks)
		$data = apply_filters( 'datametric_before_log_click', $data );
		if ( empty( $data ) || ! is_array( $data ) ) {
			return false;
		}

		$insert_data = array(
			'page_url'         => esc_url_raw( $data['page_url'] ),
			'element_tag'      => sanitize_text_field( $data['element_tag'] ),
			'element_selector' => sanitize_text_field( $data['element_selector'] ),
			'click_x_pct'      => floatval( $data['click_x_pct'] ),
			'click_y_pct'      => floatval( $data['click_y_pct'] ),
		);

		$result = $this->wpdb->insert(
			$this->table_name,
			$insert_data,
			array( '%s', '%s', '%s', '%f', '%f' )
		);

		if ( $result ) {
			// Trigger action after telemetry insertion
			do_action( 'datametric_after_log_click', $this->wpdb->insert_id, $insert_data );
		}

		return (bool) $result;
	}

	/**
	 * Fetch coordinates history for a page.
	 */
	public function get_clicks_for_page( string $page_url ): array {
		$clean_url = esc_url_raw( $page_url );

		// phpcs:disable WordPress.DB.PreparedSQL
		$results = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT click_x_pct, click_y_pct, element_selector FROM {$this->table_name} WHERE page_url = %s ORDER BY created_at DESC LIMIT 1000",
				$clean_url
			),
			ARRAY_A
		);
		// phpcs:enable

		$results = is_array( $results ) ? $results : array();

		// Filter retrieved click data (Step 2: Custom Extensibility Hooks)
		return apply_filters( 'datametric_clicks_for_page', $results, $page_url );
	}

	/**
	 * Clean up old click logs in limited batches to prevent table-locking timeouts.
	 */
	public function daily_cleanup(): void {
		// Acquire a genuinely atomic lock: add_option performs an INSERT that fails if the row
		// already exists, so only one concurrent process can win. (get_option + update_option is
		// NOT atomic — two workers can both pass the check.)
		if ( ! \DataMetric\Includes\Database::acquire_lock( 'datametric_cleanup_lock', 600 ) ) {
			return; // Another cleanup is already running / ran recently.
		}

		try {
			$limit = 500;
			$max_iterations = 100; // Cap execution safety to avoid infinite loops

			for ( $i = 0; $i < $max_iterations; $i++ ) {
				// phpcs:disable WordPress.DB.PreparedSQL
				$deleted = $this->wpdb->query(
					$this->wpdb->prepare(
						"DELETE FROM {$this->table_name} WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY) LIMIT %d",
						$limit
					)
				);
				// phpcs:enable

				if ( false === $deleted || $deleted === 0 ) {
					break;
				}

				// Yield execution control back to PHP & MySQL server thread (50ms pause)
				usleep( 50000 );
			}
		} finally {
			\DataMetric\Includes\Database::release_lock( 'datametric_cleanup_lock' );
		}
	}

	/**
	 * Run upgrade database migrations on version changes.
	 * Index creation is idempotent and handled by ensure_indexes().
	 */
	public function upgrade( string $from_version ): void {
		if ( ! preg_match( '/^[a-zA-Z0-9_]+$/', $this->table_name ) ) {
			throw new \DataMetric\Includes\Exception\DataMetricException( 'Invalid database table configuration.' );
		}
		// Future version-specific migrations go here. Indexes are ensured on every create_tables().
		$this->ensure_indexes();
	}
}
