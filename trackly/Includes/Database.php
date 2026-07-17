<?php
namespace Trackly\Includes;

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
	private static ?\Trackly\Includes\Repository\EventRepository $repository = null;

	/**
	 * Set a custom repository instance for mocking and dependency injection (Step 2: Testability / DI)
	 */
	public static function set_repository( \Trackly\Includes\Repository\EventRepository $repository ): void {
		self::$repository = $repository;
	}

	/**
	 * Get an instance of EventRepository.
	 */
	private static function get_repository(): \Trackly\Includes\Repository\EventRepository {
		if ( self::$repository === null ) {
			global $wpdb;
			$table_name = $wpdb->prefix . 'trackly_clicks';
			self::$repository = new \Trackly\Includes\Repository\EventRepository( $wpdb, $table_name );
		}
		return self::$repository;
	}

	/**
	 * Initialize hooks.
	 */
	public static function init(): void {
		add_action( 'trackly_daily_cleanup', array( __CLASS__, 'daily_cleanup' ) );
		add_action( 'trackly_weekly_ip_refresh', array( 'Trackly\Includes\ProxyRegistry', 'refresh_cf_ips' ) );
		add_filter( 'cron_schedules', array( 'Trackly\Includes\ProxyRegistry', 'add_cron_intervals' ) );
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
		$current_version = get_option( 'trackly_db_version', '0.0.0' );
		if ( version_compare( $current_version, TRACKLY_VERSION, '<' ) ) {
			$repo->upgrade( $current_version );
			update_option( 'trackly_db_version', TRACKLY_VERSION );
		}
	}

	/**
	 * Log Clicks with exception wrapper logs.
	 */
	public static function log_click( array $data ): bool {
		try {
			return self::get_repository()->log_click( $data );
		} catch ( \Exception $e ) {
			error_log( '[Trackly] Click logging failure: ' . $e->getMessage() );
			return false;
		}
	}

	/**
	 * Retrieve Clicks with exception wrapper logs.
	 */
	public static function get_clicks_for_page( string $page_url ): array {
		try {
			return self::get_repository()->get_clicks_for_page( $page_url );
		} catch ( \Exception $e ) {
			error_log( '[Trackly] Click retrieval failure: ' . $e->getMessage() );
			return array();
		}
	}

	/**
	 * Schedule the 30-day cleanup and weekly IP refresh cron jobs.
	 */
	public static function schedule_cleanup(): void {
		if ( ! wp_next_scheduled( 'trackly_daily_cleanup' ) ) {
			wp_schedule_event( time(), 'daily', 'trackly_daily_cleanup' );
		}
		if ( ! wp_next_scheduled( 'trackly_weekly_ip_refresh' ) ) {
			wp_schedule_event( time() + 60, 'weekly', 'trackly_weekly_ip_refresh' );
		}
	}

	/**
	 * Unschedule cleanup and IP refresh cron jobs securely (Step 3: Unschedule duplicates).
	 */
	public static function unschedule_cleanup(): void {
		wp_clear_scheduled_hook( 'trackly_daily_cleanup' );
		wp_clear_scheduled_hook( 'trackly_weekly_ip_refresh' );
	}

	/**
	 * Clean up click data older than 30 days.
	 */
	public static function daily_cleanup(): void {
		try {
			self::get_repository()->daily_cleanup();
		} catch ( \Exception $e ) {
			error_log( '[Trackly] Daily cleanup failure: ' . $e->getMessage() );
		}
	}
}
