<?php
namespace DataMetric\Includes;

/**
 * One-time migration from the legacy internal prefixes ("trackly_" and "metricpulse_")
 * to "datametric_". Renames options, the click table, cron events, and capabilities in
 * place so existing installs keep their data and settings after the rebrand.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Migration {

	const FLAG = 'datametric_migrated';
	const NEW  = 'datametric_';

	/**
	 * Legacy prefixes to migrate from, in order.
	 */
	const OLD_PREFIXES = array( 'trackly_', 'metricpulse_' );

	/**
	 * Run the migration once. Guarded by an option flag so it never repeats.
	 */
	public static function maybe_migrate(): void {
		if ( get_option( self::FLAG ) ) {
			return;
		}

		global $wpdb;

		foreach ( self::OLD_PREFIXES as $old_prefix ) {
			// 1. Rename real options (<old>_* -> datametric_*), skipping transients (they start with "_").
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$old_options = $wpdb->get_col(
				$wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( $old_prefix ) . '%' )
			);
			if ( is_array( $old_options ) ) {
				foreach ( $old_options as $old_name ) {
					$new_name = self::NEW . substr( $old_name, strlen( $old_prefix ) );
					if ( self::NEW . 'migrated' === $new_name ) {
						continue; // Never clobber the migration flag itself.
					}
					if ( false === get_option( $new_name, false ) ) {
						add_option( $new_name, get_option( $old_name ) );
					}
					delete_option( $old_name );
				}
			}

			// 2. Rename the click telemetry table if the legacy one exists and the new one does not.
			$old_table = $wpdb->prefix . $old_prefix . 'clicks';
			$new_table = $wpdb->prefix . 'datametric_clicks';
			if ( preg_match( '/^[a-zA-Z0-9_]+$/', $old_table ) && preg_match( '/^[a-zA-Z0-9_]+$/', $new_table ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$has_old = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $old_table ) );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$has_new = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $new_table ) );
				if ( $has_old && ! $has_new ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
					$wpdb->query( "RENAME TABLE `$old_table` TO `$new_table`" );
				}
			}

			// 3. Clear legacy cron hooks (the weekly IP refresh no longer exists).
			wp_clear_scheduled_hook( $old_prefix . 'daily_cleanup' );
			wp_clear_scheduled_hook( $old_prefix . 'weekly_ip_refresh' );

			// 4. Migrate the dashboard-view capability on the default roles.
			foreach ( array( 'administrator', 'editor' ) as $role_name ) {
				$role = get_role( $role_name );
				if ( $role ) {
					$role->add_cap( 'datametric_view_dashboard' );
					$role->remove_cap( $old_prefix . 'view_dashboard' );
				}
			}

			// 5. Drop stale legacy transients (caches regenerate on demand).
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( '_transient_' . $old_prefix ) . '%' ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( '_transient_timeout_' . $old_prefix ) . '%' ) );
		}

		// Ensure the current cron schedule exists after any legacy clears.
		Database::schedule_cleanup();

		update_option( self::FLAG, 1, 'no' );
	}
}
