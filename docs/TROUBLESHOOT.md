# Troubleshooting Guide

Find solutions to common Google Analytics API connection issues, authorization errors, and telemetry configuration problems.

---

## 🔴 GA4 Connection Status: "Not Connected"
If the connection indicator displays "Not Connected" even after saving settings:

1. **Verify Property ID**: Ensure you entered a numeric **Property ID** (typically 9 digits, e.g., `389102830`), not the Tracking ID (e.g., `G-XXXXXX` or `UA-XXXXXX`).
2. **Missing Private Key**: Verify that the Google Service Account JSON you pasted contains a valid `"private_key"` block.
3. **wp-config Constant Active**: If `defined( 'METRICPULSE_GA_JSON' )` is active, settings fields inside WordPress admin are ignored. Check your `wp-config.php` file structure.

---

## 🟡 Google API Common Error Codes

### 1. `invalid_grant` or `signature_failed`
- **Cause**: The server's clock is out of sync with NTP servers. Google's OAuth2 endpoints reject JWT assertions with expiration timestamps too far in the future or past.
- **Solution**: Coordinate your server's clock with an NTP service (e.g., run `ntpdate pool.ntp.org` or consult your hosting provider).

### 2. `permission_denied` / `viewer_permissions`
- **Cause**: The Service Account email was not added to your Google Analytics Property Access Management list, or it was added with insufficient permissions.
- **Solution**: Add the `"client_email"` address from your service account JSON file to Google Analytics under Admin -> Property Access Management as a **Viewer**.

### 3. `data_api_not_enabled`
- **Cause**: The Google Analytics Data API v1beta has not been enabled in your Google Cloud Console project.
- **Solution**: Access the [Google Analytics Data API Dashboard](https://console.cloud.google.com/apis/library/analyticsdata.googleapis.com) in your Cloud console and click **Enable**.

---

## 🟢 Empty Heatmap or Click Records Not Displaying

1. **Wait for Traffic**: Ensure visitors have clicked on the page. Inactive or draft pages have zero database click records.
2. **Sampling Rate**: If `datametric_sampling_rate` is set low (e.g., `10%` or `25%`), only a random fraction of visitor sessions record clicks. Raise it to `100%` during staging testing.
3. **CORS/Origin Blocks**: Check your browser console for failed REST API requests. If your server strips both `Origin` and `Referer` headers, or you serve cross-origin requests, check your security proxy filters.
