<?php
/**
 * Plugin Name:       DataMetric Analytics Dashboard and Heatmaps
 * Plugin URI:        https://www.ridvanbilgin.com/2026/07/datametric-wordpress-ga4-analytics-plugin.html
 * Description:       A modern Google Analytics 4 dashboard and page-level statistics client for WordPress with local click heatmaps and statistical anomaly insights.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      8.1
 * Author:            Rıdvan Bilgin
 * Author URI:        https://ridvanbilgin.com
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       datametric-analytics-heatmaps
 * Domain Path:       /languages
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

define( 'DATAMETRIC_VERSION', '1.0.0' );
define( 'DATAMETRIC_PATH', plugin_dir_path( __FILE__ ) );
define( 'DATAMETRIC_URL', plugin_dir_url( __FILE__ ) );

// 1. PSR-4 Class Loader (Supports Composer autoloader, defaults to fallback PSR-4 registered autoloader)
if ( file_exists( DATAMETRIC_PATH . 'vendor/autoload.php' ) ) {
	require_once DATAMETRIC_PATH . 'vendor/autoload.php';
} else {
	spl_autoload_register( function ( $class ) {
		$prefix = 'DataMetric\\';
		$base_dir = DATAMETRIC_PATH;

		$len = strlen( $prefix );
		if ( strncmp( $prefix, $class, $len ) !== 0 ) {
			return;
		}

		$relative_class = substr( $class, $len );
		$file = $base_dir . str_replace( '\\', '/', $relative_class ) . '.php';

		if ( file_exists( $file ) ) {
			require_once $file;
		}
	} );
}

function datametric_activate() {
	// Trigger DB table creation
	DataMetric\Includes\Database::create_tables();
	DataMetric\Includes\Database::schedule_cleanup();

	// Generate a unique dynamic fallback encryption key if not exists (Enterprise Security)
	if ( ! get_option( 'datametric_secure_salt' ) ) {
		$secure_key = wp_generate_password( 64, true, true );
		update_option( 'datametric_secure_salt', $secure_key, 'no' ); // Saved with autoload=no
	}

	// Grant custom capabilities for dashboards access control (Step 4: Capabilities control)
	$admin_role = get_role( 'administrator' );
	if ( $admin_role ) {
		$admin_role->add_cap( 'datametric_view_dashboard' );
	}
	$editor_role = get_role( 'editor' );
	if ( $editor_role ) {
		$editor_role->add_cap( 'datametric_view_dashboard' );
	}
}
register_activation_hook( __FILE__, 'datametric_activate' );

function datametric_deactivate() {
	DataMetric\Includes\Database::unschedule_cleanup();

	$admin_role = get_role( 'administrator' );
	if ( $admin_role ) {
		$admin_role->remove_cap( 'datametric_view_dashboard' );
	}
	$editor_role = get_role( 'editor' );
	if ( $editor_role ) {
		$editor_role->remove_cap( 'datametric_view_dashboard' );
	}
}
register_deactivation_hook( __FILE__, 'datametric_deactivate' );

// Run the plugin
function datametric_run() {
	// Translations for plugins hosted on WordPress.org are loaded automatically since WP 4.6;
	// no load_plugin_textdomain() call is required.

	// One-time rebrand migration (trackly_*/metricpulse_* -> datametric_*) for existing installs.
	DataMetric\Includes\Migration::maybe_migrate();

	$plugin = new DataMetric\Includes\Core();
	$plugin->run();
}
add_action( 'plugins_loaded', 'datametric_run' );

