# WooCommerce Payment Monitor

A WordPress plugin that monitors WooCommerce payment gateway health in real-time, alerts store owners when payments fail, and provides actionable diagnostics to recover lost revenue.

## Features

### Free Tier
- ✅ Monitor 1 payment gateway
- ✅ Basic analytics (1-hour, 24-hour, 7-day periods)
- ✅ 7-day data retention
- ✅ Email alerts
- ✅ Real-time health monitoring

### PRO Tier ($99/year)
- ✅ **Unlimited gateways** - Monitor all your payment methods
- ✅ **Extended analytics** - 30-day and 90-day success rate tracking
- ✅ **90-day data retention** - Keep historical data for 3 months
- ✅ SMS alerts (500/month)
- ✅ Slack integration
- ✅ Per-gateway configuration

See [PRO Features Documentation](docs/PRO_FEATURES.md) for complete details on all license tiers.

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

1. `wp_payment_monitor_transactions` - Stores all payment transaction data
2. `wp_payment_monitor_gateway_health` - Stores gateway performance metrics
3. `wp_payment_monitor_alerts` - Stores alert history and status

## Performance Monitoring & Alerts

The plugin monitors gateway health using a hybrid approach:

- **Immediate Alerts**: Critical system errors trigger alerts instantly.
- **Statistical Alerts**: Success rates are analyzed over 1h, 24h, and 7d periods.
- **Volume Awareness**: Severity is automatically adjusted for low-volume stores to reduce noise.

See [Alert Severity Logic](docs/ALERT_LOGIC.md) for more technical details.

## Pre-Launch Checklist

Before deploying to production, review the [Pre-Launch Testing Checklist](docs/PRE_LAUNCH_CHECKLIST.md) to ensure all features work correctly across different license tiers.

## Development

This plugin follows WordPress coding standards and uses an autoloader for class management.

### Documentation

- [PRO Features Guide](docs/PRO_FEATURES.md) - Details on PRO plan features and implementation
- [Alert Logic Documentation](docs/ALERT_LOGIC.md) - How alerts are triggered and managed
- [Pre-Launch Checklist](docs/PRE_LAUNCH_CHECKLIST.md) - Testing checklist for production deployment
- [Test Suite Documentation](tests/README.md) - Guide to running and writing tests

### Class Naming Convention

All classes follow the pattern: `WC_Payment_Monitor_{Component}`

### File Naming Convention

All class files follow the pattern: `class-wc-payment-monitor-{component}.php`

### Run Tests in Docker

This repo includes a minimal Docker setup to run the PHPUnit suite against a fresh WordPress test install.

Requirements:

- Docker Desktop (or compatible runtime)

Commands:

```bash
# Build test image (first time or after Dockerfile changes)
make build

# Run the test suite (downloads WP + test suite on first run)
make test

# Rebuild image and run tests
make test-rebuild

# Optionally, tear down the DB container if it was started
make down
```

Notes:

- The test runner installs the WordPress test suite into a temporary directory inside the container and uses the database provided by the `db` service.
- Database credentials are defined in `docker-compose.yml` and passed to `install-wp-tests.sh`. Database creation is handled by the MySQL container; the installer is invoked with `skip-database-creation=true`.
- To pin a specific WordPress version, set `WP_VERSION` in `docker-compose.yml` (e.g. `6.6`).
- **Note**: The WordPress test suite may have compatibility issues with certain versions. If tests fail with syntax errors, try a different `WP_VERSION` or consider running tests locally without Docker.
