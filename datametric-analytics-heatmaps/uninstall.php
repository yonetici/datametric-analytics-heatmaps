<?php
/**
 * DataMetric Uninstall Handler.
 * Fired when the plugin is deleted from the WordPress admin.
 *
 * Data is only removed for sites that explicitly opted in via the
 * "Delete all data on uninstall" setting. Multisite installs are handled per-site.
 */

// If uninstall not called from WordPress, exit.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Remove all DataMetric data for the current site, but only if the site opted in.
 */
function datametric_uninstall_site() {
	global $wpdb;

	// Cron hooks are site-scoped; always clear them so no orphaned schedules remain.
	wp_clear_scheduled_hook( 'datametric_daily_cleanup' );

	// Respect the user's choice: preserve data unless deletion was explicitly enabled.
	if ( 'yes' !== get_option( 'datametric_delete_data', 'no' ) ) {
		return;
	}

	$datametric_options = array(
		'datametric_demo_mode',
		'datametric_property_id',
		'datametric_credentials',
		'datametric_sampling_rate',
		'datametric_secure_salt',
		'datametric_custom_events',
		'datametric_require_consent',
		'datametric_delete_data',
		'datametric_cleanup_lock',
		'datametric_db_version',
		'datametric_cache_ver',
	);
	foreach ( $datametric_options as $datametric_option ) {
		delete_option( $datametric_option );
	}

	// Catch any remaining prefixed options and transients. Patterns are built with esc_like()
	// and bound through prepare() (LIKE takes a bindable value, unlike a table identifier).
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'datametric_' ) . '%' ) );

	// Clear transients.
	delete_transient( 'datametric_access_token' );
	delete_transient( 'datametric_realtime_cache' );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( '_transient_datametric_' ) . '%' ) );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( '_transient_timeout_datametric_' ) . '%' ) );

	// Drop the custom table for this site (table name uses the site's prefix).
	$datametric_table_name = $wpdb->prefix . 'datametric_clicks';
	if ( preg_match( '/^[a-zA-Z0-9_]+$/', $datametric_table_name ) ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$wpdb->query( "DROP TABLE IF EXISTS $datametric_table_name" );
	}
}

if ( is_multisite() ) {
	// Iterate every site so no sub-site is left with orphaned tables/options.
	$datametric_site_ids = get_sites( array( 'fields' => 'ids', 'number' => 0 ) );
	foreach ( $datametric_site_ids as $datametric_site_id ) {
		switch_to_blog( $datametric_site_id );
		datametric_uninstall_site();
		restore_current_blog();
	}
} else {
	datametric_uninstall_site();
}
