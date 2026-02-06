# WC_Payment_Monitor_Config Class Documentation

## Overview

The `WC_Payment_Monitor_Config` class provides centralized settings management for the WooCommerce Payment Monitor plugin. It uses a singleton pattern to ensure consistent settings access across the plugin and includes memory caching to minimize database queries.

## Features

- **Singleton Pattern**: Single instance shared across the plugin
- **Memory Caching**: Settings are cached to avoid repeated database calls
- **Validation**: Setter methods validate input before saving
- **Type Safety**: Proper type hints and return types
- **Clean API**: Convenience methods for commonly used settings

## Usage

### Getting the Instance

```php
$config = WC_Payment_Monitor_Config::instance();
```

### General Settings Methods

#### Get a Setting
```php
// Get with default fallback
$value = $config->get('alert_threshold', 85);
```

#### Set a Setting
```php
// Set a value
$config->set('alert_threshold', 90);
```

#### Get All Settings
```php
// Get all options
$all_settings = $config->get_all();
```

#### Update All Settings
```php
// Update all options at once
$config->update_all($settings_array);
```

### Convenience Methods

#### Alert Settings

```php
// Get alert threshold (default: 85)
$threshold = $config->get_alert_threshold();

// Set alert threshold (validates 0-100)
$config->set_alert_threshold(90);

// Get alert email
$email = $config->get_alert_email();

// Set alert email (validates email format)
$config->set_alert_email('admin@example.com');

// Get alert phone
$phone = $config->get_alert_phone();

// Set alert phone
$config->set_alert_phone('+1234567890');
```

#### Monitoring Settings

```php
// Check if monitoring is enabled
if ($config->is_monitoring_enabled()) {
    // Do monitoring
}

// Enable/disable monitoring
$config->set_monitoring_enabled(true);

// Get health check interval in minutes (default: 5)
$interval = $config->get_health_check_interval();

// Set health check interval (validates 1-1440)
$config->set_health_check_interval(10);
```

#### Retry Settings

```php
// Check if retry is enabled
if ($config->is_retry_enabled()) {
    // Schedule retry
}

// Enable/disable retry
$config->set_retry_enabled(true);

// Get max retry attempts (default: 3)
$max_attempts = $config->get_max_retry_attempts();

// Set max retry attempts (validates 1-10)
$config->set_max_retry_attempts(5);

// Get retry delay in minutes (default: 60)
$delay = $config->get_retry_delay();

// Set retry delay (validates 1-1440)
$config->set_retry_delay(120);

// Get retry statistics
$stats = $config->get_retry_stats();

// Set retry statistics
$config->set_retry_stats($stats_array);
```

#### Gateway Settings

```php
// Get enabled gateways
$gateways = $config->get_enabled_gateways();

// Set enabled gateways
$config->set_enabled_gateways(['stripe', 'paypal']);
```

#### Slack Integration

```php
// Get Slack workspace ID
$workspace = $config->get_slack_workspace();

// Set Slack workspace ID
$config->set_slack_workspace('workspace_123');

// Clear Slack workspace
$config->clear_slack_workspace();

// Check if Slack notifications are enabled
if ($config->is_slack_notifications_enabled()) {
    // Send Slack notification
}

// Enable/disable Slack notifications
$config->set_slack_notifications_enabled(true);
```

#### Email Notifications

```php
// Check if email notifications are enabled
if ($config->is_email_notifications_enabled()) {
    // Send email
}

// Enable/disable email notifications
$config->set_email_notifications_enabled(true);

// Get notification frequency
$frequency = $config->get_notification_frequency(); // 'immediate', 'hourly', 'daily'

// Set notification frequency
$config->set_notification_frequency('hourly');
```

#### Test Mode

```php
// Check if test mode is enabled
if ($config->is_test_mode_enabled()) {
    // Test mode logic
}

// Enable/disable test mode
$config->set_test_mode_enabled(true);
```

#### Debug Mode

```php
// Check if debug mode is enabled
if ($config->is_debug_mode_enabled()) {
    // Log debug info
}

// Enable/disable debug mode
$config->set_debug_mode_enabled(true);
```

#### Data Retention

```php
// Get data retention days (default: 30)
$days = $config->get_data_retention_days();

// Set data retention days (validates 1-365)
$config->set_data_retention_days(90);
```

#### Quota Management

```php
// Check if quota is exceeded
if ($config->is_quota_exceeded()) {
    // Handle quota exceeded
}

// Set quota exceeded status
$config->set_quota_exceeded(true);
```

### Cache Management

```php
// Clear the settings cache to reload from database
$config->clear_cache();
```

## Constants

### Option Keys

```php
WC_Payment_Monitor_Config::OPTION_MAIN_OPTIONS    // 'wc_payment_monitor_options'
WC_Payment_Monitor_Config::OPTION_SETTINGS        // 'wc_payment_monitor_settings'
WC_Payment_Monitor_Config::OPTION_SLACK_WORKSPACE // 'wc_payment_monitor_slack_workspace'
WC_Payment_Monitor_Config::OPTION_QUOTA_EXCEEDED  // 'wc_payment_monitor_quota_exceeded'
WC_Payment_Monitor_Config::OPTION_RETRY_STATS     // 'wc_payment_monitor_retry_stats'
```

### Default Values

```php
WC_Payment_Monitor_Config::DEFAULT_ALERT_THRESHOLD       // 85
WC_Payment_Monitor_Config::DEFAULT_HEALTH_CHECK_INTERVAL // 5
WC_Payment_Monitor_Config::DEFAULT_MAX_RETRY_ATTEMPTS    // 3
WC_Payment_Monitor_Config::DEFAULT_RETRY_DELAY           // 60
WC_Payment_Monitor_Config::DEFAULT_DATA_RETENTION_DAYS   // 30
```

## Validation

All setter methods include validation:

- **Alert Threshold**: 0-100 (float)
- **Health Check Interval**: 1-1440 minutes (int)
- **Max Retry Attempts**: 1-10 (int)
- **Retry Delay**: 1-1440 minutes (int)
- **Data Retention Days**: 1-365 days (int)
- **Email**: Valid email format
- **Notification Frequency**: 'immediate', 'hourly', or 'daily'

Invalid values will return `false` without updating the setting.

## Migration Guide

### Before (Direct get_option calls):

```php
$settings = get_option('wc_payment_monitor_options', array());
$threshold = isset($settings['alert_threshold']) ? $settings['alert_threshold'] : 85;

if ($threshold < 90) {
    // Do something
}
```

### After (Using Config class):

```php
$config = WC_Payment_Monitor_Config::instance();
$threshold = $config->get_alert_threshold();

if ($threshold < 90) {
    // Do something
}
```

### Before (Direct update_option calls):

```php
$settings = get_option('wc_payment_monitor_options', array());
$settings['alert_threshold'] = 90;
update_option('wc_payment_monitor_options', $settings);
```

### After (Using Config class):

```php
$config = WC_Payment_Monitor_Config::instance();
$config->set_alert_threshold(90);
```

## Best Practices

1. **Use the Instance**: Always get the instance rather than creating new objects
2. **Use Convenience Methods**: Prefer `get_alert_threshold()` over `get('alert_threshold')`
3. **Check Return Values**: Setter methods return `false` on validation failure
4. **Clear Cache When Needed**: If settings are updated outside the Config class, call `clear_cache()`

## Example: Updating Multiple Settings

```php
$config = WC_Payment_Monitor_Config::instance();

// Update individual settings
$config->set_alert_threshold(90);
$config->set_monitoring_enabled(true);
$config->set_retry_enabled(true);

// Or update all at once
$config->update_all([
    'alert_threshold' => 90,
    'enable_monitoring' => 1,
    'retry_enabled' => true,
    'max_retry_attempts' => 5,
]);
```

## Example: Checking Configuration

```php
$config = WC_Payment_Monitor_Config::instance();

if ($config->is_monitoring_enabled()) {
    $gateways = $config->get_enabled_gateways();
    $threshold = $config->get_alert_threshold();
    
    foreach ($gateways as $gateway) {
        // Monitor gateway with threshold
    }
}
```
