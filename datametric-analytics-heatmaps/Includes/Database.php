<?php
namespace DataMetric\Includes;

/**
 * Facade class for Database operations.
 * Delegates actual queries and table modifications to EventRepository.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Database {

	/**
	 * Cached instance of EventRepository (Step 4: Singleton Pattern)
	 */
	private static ?\DataMetric\Includes\Repository\EventRepository $repository = null;

	/**
	 * Set a custom repository instance for mocking and dependency injection (Step 2: Testability / DI)
	 */
	public static function set_repository( \DataMetric\Includes\Repository\EventRepository $repository ): void {
		self::$repository = $repository;
	}

	/**
	 * Get an instance of EventRepository.
	 */
	private static function get_repository(): \DataMetric\Includes\Repository\EventRepository {
		if ( self::$repository === null ) {
			global $wpdb;
			$table_name = $wpdb->prefix . 'datametric_clicks';
			self::$repository = new \DataMetric\Includes\Repository\EventRepository( $wpdb, $table_name );
		}
		return self::$repository;
	}

	/**
	 * Initialize hooks.
	 */
	public static function init(): void {
		add_action( 'datametric_daily_cleanup', array( __CLASS__, 'daily_cleanup' ) );
	}

	/**
	 * Get Custom Table Name.
	 */
	public static function get_table_name(): string {
		return self::get_repository()->get_table_name();
	}

	/**
	 * Create Custom Tables and execute version migration upgrades.
	 */
	public static function create_tables(): void {
		$repo = self::get_repository();
		$repo->create_tables();

		// Database upgrade manager (Step 5: DB Versioning)
		$current_version = get_option( 'datametric_db_version', '0.0.0' );
		if ( version_compare( $current_version, DATAMETRIC_VERSION, '<' ) ) {
			$repo->upgrade( $current_version );
			update_option( 'datametric_db_version', DATAMETRIC_VERSION );
		}
	}

	/**
	 * Log Clicks with exception wrapper logs.
	 */
	public static function log_click( array $data ): bool {
		try {
			return self::get_repository()->log_click( $data );
		} catch ( \Exception $e ) {
			self::log_error( 'Click logging failure', $e );
			return false;
		}
	}

	/**
	 * Log an internal error, but only when WP_DEBUG is enabled.
	 */
	private static function log_error( string $context, \Throwable $e ): void {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( '[DataMetric] ' . $context . ': ' . $e->getMessage() );
		}
	}

	/**
	 * Retrieve Clicks with exception wrapper logs.
	 */
	public static function get_clicks_for_page( string $page_url ): array {
		try {
			return self::get_repository()->get_clicks_for_page( $page_url );
		} catch ( \Exception $e ) {
			self::log_error( 'Click retrieval failure', $e );
			return array();
		}
	}

	/**
	 * Schedule the 30-day cleanup cron job.
	 */
	public static function schedule_cleanup(): void {
		if ( ! wp_next_scheduled( 'datametric_daily_cleanup' ) ) {
			wp_schedule_event( time(), 'daily', 'datametric_daily_cleanup' );
		}
	}

	/**
	 * Unschedule cleanup cron jobs securely (Step 3: Unschedule duplicates).
	 */
	public static function unschedule_cleanup(): void {
		wp_clear_scheduled_hook( 'datametric_daily_cleanup' );
	}

	/**
	 * Acquire a mutually-exclusive lock using an atomic add_option() INSERT.
	 *
	 * add_option() fails (returns false) when the option row already exists, so exactly one
	 * caller can create it — a real mutex, unlike a get_option()+update_option() pair. A stale
	 * lock older than $ttl seconds is reclaimed so a crashed worker can't wedge the lock forever.
	 *
	 * @return bool True if the lock was acquired by this caller.
	 */
	public static function acquire_lock( string $key, int $ttl = 600 ): bool {
		if ( add_option( $key, time(), '', 'no' ) ) {
			return true;
		}
		// Row exists: reclaim it only if it is stale.
		$held = (int) get_option( $key, 0 );
		if ( $held && ( time() - $held ) >= $ttl ) {
			update_option( $key, time(), 'no' );
			return true;
		}
		return false;
	}

	/**
	 * Release a lock acquired via acquire_lock().
	 */
	public static function release_lock( string $key ): void {
		delete_option( $key );
	}

	/**
	 * Clean up click data older than 30 days.
	 */
	public static function daily_cleanup(): void {
		try {
			self::get_repository()->daily_cleanup();
		} catch ( \Exception $e ) {
			self::log_error( 'Daily cleanup failure', $e );
		}
	}
}
