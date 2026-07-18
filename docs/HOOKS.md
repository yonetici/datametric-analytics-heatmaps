# Developer Action & Filter Hooks

DataMetric provides action and filter hooks, allowing developers to extend, alter, or customize behavior across telemetry logging and client IP detection.

---

## 🛠️ Filter Hooks

### 1. `datametric_trusted_proxies`
Allows developers to override or append custom CIDR ranges to the list of trusted reverse proxy IP subnets. Useful for enterprise networks, Varnish setups, or custom load balancer environments. The default list is Cloudflare's published edge ranges, bundled statically with the plugin (no external request is made).

- **Parameters**: `array $trusted_proxies` (List of IP CIDR ranges).
- **Example Usage**:
```php
add_filter( 'datametric_trusted_proxies', function( $proxies ) {
    $proxies[] = '192.168.50.0/24'; // Trust custom corporate network
    return $proxies;
} );
```

### 2. `datametric_before_log_click`
Fires before a click record is written, allowing custom validation or enrichment of the click payload. Return the (possibly modified) data array.

- **Parameters**: `array $data` (The click record about to be inserted).

---

## ⚡ Action Hooks

### 1. `datametric_daily_cleanup`
Fires daily via WP-Cron to purge click records older than 30 days.

- **Trigger**: WP-Cron schedule worker.
- **Example hook extension**:
```php
add_action( 'datametric_daily_cleanup', function() {
    // Custom logging or cleanup tasks
    error_log( 'DataMetric daily telemetry cleanup executed successfully.' );
} );
```

### 2. `datametric_after_log_click`
Fires immediately after a click record is written, allowing custom side effects.

- **Parameters**: `int $insert_id`, `array $insert_data`.
- **Trigger**: Public click-logging endpoint.
