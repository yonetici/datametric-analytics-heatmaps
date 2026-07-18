# Installation Guide

Welcome to the installation documentation for **DataMetric Analytics Dashboard and Heatmaps**. Follow these steps to set up your GA4 dashboard and click heatmap tracker client on your WordPress site.

## 📋 Prerequisites
1. A WordPress site running version **6.0 or higher**.
2. PHP version **8.0 or higher**.
3. A Google Cloud Console account with access to Google Analytics 4.

## 🚀 Step-by-Step Installation

### Step 1: Upload and Activate
1. Download the plugin folder and zip it.
2. Go to your WordPress Dashboard -> **Plugins** -> **Add New** -> **Upload Plugin**.
3. Select your zip file and click **Install Now**.
4. Once uploaded, click **Activate Plugin**.

### Step 2: Configure Google Cloud Service Account
To fetch reporting statistics securely, DataMetric Analytics Dashboard and Heatmaps utilizes a Google Cloud Service Account.

1. Go to the [Google Cloud Console](https://console.cloud.google.com/).
2. Create a new project or select an existing one.
3. Enable the **Google Analytics Data API** for your project.
4. Go to **IAM & Admin** -> **Service Accounts** -> **Create Service Account**.
5. Give the account a name (e.g., `datametric-analytics-reader`) and click **Create and Continue**.
6. Once created, click on the service account, go to the **Keys** tab, and click **Add Key** -> **Create New Key**.
7. Select **JSON** format, click **Create**, and save the file to your computer.

### Step 3: Grant Analytics Read Permissions
1. Open the downloaded JSON file and find the `"client_email"` field (looks like `service-account-name@project-id.iam.gserviceaccount.com`).
2. Log in to your [Google Analytics Admin Dashboard](https://analytics.google.com/).
3. Under Property settings, select **Property Access Management**.
4. Click the **+** button -> **Add users**.
5. Paste the email address of the service account and grant it **Viewer** permissions.

### Step 4: Configure Plugin Settings
1. In your WordPress Admin Dashboard, navigate to **Settings** -> **DataMetric Analytics Dashboard and Heatmaps**.
2. Retrieve your GA4 **Property ID** from Google Analytics Property Settings and paste it.
3. Choose one of the following methods to input your Service Account JSON:

#### Option A: Copy & Paste (Database Storage)
- Copy the entire content of the downloaded JSON key file.
- Paste it into the **Google Service Account JSON** text area in the settings page.
- Click **Save Changes**. (The private key is encrypted using AES-256-GCM prior to database storage).

#### Option B: wp-config.php (Enterprise Recommended)
- For maximum security and lock-down, paste the JSON content directly as a constant inside your `wp-config.php` file:
```php
define( 'METRICPULSE_GA_JSON', '{"type": "service_account", "project_id": ...}' );
```
- When this constant is active, DataMetric Analytics Dashboard and Heatmaps automatically bypasses database credential lookups.
