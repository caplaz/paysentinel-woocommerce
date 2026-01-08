# WooCommerce Payment Monitor

A WordPress plugin that monitors WooCommerce payment gateway health in real-time, alerts store owners when payments fail, and provides actionable diagnostics to recover lost revenue.

## Plugin Structure

```
wc-payment-monitor/
├── wc-payment-monitor.php          # Main plugin file
├── includes/                       # Core plugin classes
│   ├── class-wc-payment-monitor-database.php
│   ├── class-wc-payment-monitor-logger.php
│   ├── class-wc-payment-monitor-health.php
│   ├── class-wc-payment-monitor-alerts.php
│   └── class-wc-payment-monitor-retry.php
├── admin/                          # Admin interface (future)
├── assets/                         # CSS/JS assets (future)
├── languages/                      # Translation files (future)
└── tests/                          # Unit and property tests (future)
```

## Installation

1. Upload the plugin files to `/wp-content/plugins/wc-payment-monitor/`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Ensure WooCommerce is installed and active

## Requirements

- WordPress 5.0+
- WooCommerce 5.0+
- PHP 7.4+

## Database Tables

The plugin creates three custom database tables:

1. `wp_wc_payment_monitor_transactions` - Stores all payment transaction data
2. `wp_wc_payment_monitor_gateway_health` - Stores gateway performance metrics
3. `wp_wc_payment_monitor_alerts` - Stores alert history and status

## Development

This plugin follows WordPress coding standards and uses an autoloader for class management.

### Class Naming Convention

All classes follow the pattern: `WC_Payment_Monitor_{Component}`

### File Naming Convention

All class files follow the pattern: `class-wc-payment-monitor-{component}.php`
