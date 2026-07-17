<?php
declare(strict_types=1);

namespace Trackly\Includes\Repository;

use Trackly\Includes\Exception\TracklyException;

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
		$table_name = $this->table_name;

		// v1.1 to v1.2 migration column cleanup protection
		if ( $this->wpdb->get_var( $this->wpdb->prepare( "SHOW TABLES LIKE %s", $table_name ) ) ) {
			$column_check_width = $this->wpdb->get_results( "SHOW COLUMNS FROM $table_name LIKE 'screen_width'" );
			if ( ! empty( $column_check_width ) ) {
				$this->wpdb->query( "ALTER TABLE $table_name DROP COLUMN screen_width" );
			}
			$column_check_height = $this->wpdb->get_results( "SHOW COLUMNS FROM $table_name LIKE 'screen_height'" );
			if ( ! empty( $column_check_height ) ) {
				$this->wpdb->query( "ALTER TABLE $table_name DROP COLUMN screen_height" );
			}
		}

		$charset_collate = $this->wpdb->get_charset_collate();

		$sql = "CREATE TABLE $table_name (
			id bigint(20) NOT NULL AUTO_INCREMENT,
			page_url varchar(255) NOT NULL,
			element_tag varchar(50) NOT NULL,
			element_selector varchar(255) NOT NULL,
			click_x_pct float NOT NULL,
			click_y_pct float NOT NULL,
			created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
			PRIMARY KEY  (id),
			KEY page_url_created (page_url(191), created_at),
			KEY created_at (created_at)
		) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	public function log_click( array $data ): bool {
		// Filter raw click data before saving (Step 2: Custom Extensibility Hooks)
		$data = apply_filters( 'trackly_before_log_click', $data );
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
			do_action( 'trackly_after_log_click', $this->wpdb->insert_id, $insert_data );
		}

		return (bool) $result;
	}

	/**
	 * Fetch coordinates history for a page.
	 */
	public function get_clicks_for_page( string $page_url ): array {
		$clean_url = esc_url_raw( $page_url );

		$results = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT click_x_pct, click_y_pct, element_selector FROM {$this->table_name} WHERE page_url = %s ORDER BY created_at DESC LIMIT 1000",
				$clean_url
			),
			ARRAY_A
		);

		$results = is_array( $results ) ? $results : array();

		// Filter retrieved click data (Step 2: Custom Extensibility Hooks)
		return apply_filters( 'trackly_clicks_for_page', $results, $page_url );
	}

	/**
	 * Clean up old click logs in limited batches to prevent table-locking timeouts.
	 */
	public function daily_cleanup(): void {
		// Atomic Lock (MySQL options based to prevent concurrency issues under Memcached/Redis)
		$lock = get_option( 'trackly_cleanup_lock' );
		if ( $lock && time() - intval( $lock ) < 600 ) {
			return; // Locked in past 10 minutes
		}
		update_option( 'trackly_cleanup_lock', time() );

		$limit = 500;
		$max_iterations = 100; // Cap execution safety to avoid infinite loops

		for ( $i = 0; $i < $max_iterations; $i++ ) {
			$deleted = $this->wpdb->query(
				$this->wpdb->prepare(
					"DELETE FROM {$this->table_name} WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY) LIMIT %d",
					$limit
				)
			);

			if ( false === $deleted || $deleted === 0 ) {
				break;
			}

			// Yield execution control back to PHP & MySQL server thread (50ms pause)
			usleep( 50000 );
		}

		delete_option( 'trackly_cleanup_lock' );
	}

	/**
	 * Run upgrade database migrations on version changes.
	 */
	public function upgrade( string $from_version ): void {
		// Execute schema upgrades if the user was running an older version
		if ( version_compare( $from_version, '1.0.0', '<' ) ) {
			$index_check = $this->wpdb->get_results( "SHOW INDEX FROM {$this->table_name} WHERE Key_name = 'page_url_created'" );
			if ( empty( $index_check ) ) {
				$this->wpdb->query( "ALTER TABLE {$this->table_name} ADD INDEX page_url_created (page_url(191), created_at)" );
			}
			$created_at_check = $this->wpdb->get_results( "SHOW INDEX FROM {$this->table_name} WHERE Key_name = 'created_at'" );
			if ( empty( $created_at_check ) ) {
				$this->wpdb->query( "ALTER TABLE {$this->table_name} ADD INDEX created_at (created_at)" );
			}
		}
	}
}
