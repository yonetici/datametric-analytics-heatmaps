=== DataMetric Analytics Dashboard and Heatmaps ===
Contributors: datametric
Tags: analytics, ga4, heatmaps, dashboard, visitor-tracking
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A modern Google Analytics 4 dashboard and local click heatmap tracker for WordPress with statistical anomaly insights.

== Description ==

DataMetric Analytics Dashboard and Heatmaps is a GDPR-conscious, high-performance plugin that brings Google Analytics 4 reports and local click heatmaps directly to your WordPress website. The admin dashboard covers the metrics that matter: users, sessions, pageviews, engagement rate and average session duration KPIs; a multi-series traffic trend (7 or 30 days); traffic acquisition by source/medium (including referrers) with engagement and key events; traffic-channel and device breakdowns; top pages and top landing pages; top countries; top events; a new-vs-returning split; and a live "last 30 minutes" active-visitor sparkline. It also includes a local click heatmap, standard-deviation based anomaly insights, and a visual GA4 event builder.

All reports are fetched with batched, cached GA4 Data API calls (respecting the API's 5-reports-per-batch limit) and high-cardinality reports are bounded with ordering and row limits to stay within quota.

Introduction and full feature overview: https://www.ridvanbilgin.com/2026/07/datametric-wordpress-ga4-analytics-plugin.html

This plugin is designed for strict performance and security. Standard visitors only download a lightweight, vanilla JavaScript tracker under 5KB (with zero jQuery or external dependencies), while the heavier stats overlay interface is loaded exclusively for logged-in administrators. The plugin bundles its own fonts and libraries and makes no third-party front-end requests.

= A note on "insights" =

The anomaly insights are produced by classic descriptive statistics (mean and standard deviation over the reporting window), not machine learning. They flag traffic and bounce-rate values that deviate significantly from the recent average.

= External Services =

This plugin connects to one external service, and only when you configure it:

1. **Google Analytics 4 (GA4) Data API** (https://analyticsdata.googleapis.com)
   - **What it is and why it is used**: Retrieves your own GA4 property's reporting statistics (views, users, bounce rate, sessions, traffic sources, and devices) to display them in interactive charts on the admin dashboard. Requests are made only from wp-admin, only after you enter your GA4 Property ID and Service Account credentials, and only with your own Google credentials.
   - **Data sent**: your GA4 Property ID and report queries, authenticated with the Service Account credentials you provide. No visitor data from your site is sent.
   - **Terms**: https://marketingplatform.google.com/about/analytics/terms/us/
   - **Privacy Policy**: https://policies.google.com/privacy

The local click heatmap and anomaly insights run entirely on your own server and database; no visitor tracking data ever leaves your site.

= Bundled Third-Party Libraries =

* **ApexCharts.js** (v4.7.0, MIT License) — used to render the admin dashboard charts. Source and documentation: https://github.com/apexcharts/apexcharts.js . The library is bundled locally (Admin/js/vendor/apexcharts.min.js); no chart data leaves your server through it.

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/datametric-analytics-heatmaps` directory, or install the plugin through the WordPress plugins screen directly.
2. Activate the plugin through the 'Plugins' screen in WordPress.
3. Configure your GA4 Property ID and Service Account JSON credentials under the Settings menu, or leave Demo Mode enabled to preview the interface with mock data.

== Frequently Asked Questions ==

= Does this plugin support IPv6? =
Yes! The Cloudflare and reverse proxy whitelists used for accurate client IP detection support both IPv4 and IPv6 subnet ranges natively. These ranges are bundled with the plugin; no external request is made to obtain them.

= Is the click tracking GDPR-compliant? =
The click telemetry contains no personal data (no IP address, no user identifiers) — only the page path, the clicked element, and normalized click coordinates. It is stored in your own local database and automatically deleted after 30 days. By default the tracker starts in strict opt-in mode and waits for consent from a supported consent plugin (Complianz, Borlabs, CookieLawInfo, or Google Consent Mode v2); you can relax this in Settings.

= What happens to my data when I uninstall? =
By default nothing is deleted, so you can safely reinstall. If you enable "Delete all data on uninstall" in Settings, deleting the plugin will permanently drop the click table and remove all options.

= Do I need Google Analytics installed for the Event Builder? =
Yes. The Event Builder maps clicks to GA4 events via gtag. Your site must already load the GA4 / gtag snippet (for example via Site Kit or a manual tag) for these events to be sent.

== Screenshots ==

1. The main administrative analytics dashboard displaying GA4 report statistics and interactive charts.
2. Glassmorphic front-end client overlay displaying page-level analytics and click heatmap overlays.

== Privacy Policy ==

DataMetric Analytics Dashboard and Heatmaps respects user privacy and complies with GDPR. Telemetry data is recorded locally on custom database tables, contains no personally identifiable information (PII), and is automatically purged after 30 days.

== Upgrade Notice ==

= 1.0.0 =
Initial Release.

== Changelog ==

= 1.0.0 =
* Initial Release.
