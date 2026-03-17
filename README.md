# PaySentinel - Payment Monitor for WooCommerce

![License](https://img.shields.io/badge/license-GPLv2%20or%20later-blue.svg)
![WordPress](https://img.shields.io/badge/WordPress-6.8+-blue.svg)
![WooCommerce](https://img.shields.io/badge/WooCommerce-9.0+-blue.svg)
![PHP](https://img.shields.io/badge/PHP-7.4+-blue.svg)
![Tests](https://img.shields.io/badge/tests-325%20passing-brightgreen.svg)

**PaySentinel** is an advanced WooCommerce plugin that provides real-time monitoring and alerting for your payment gateways. Never lose a sale due to an unnoticed payment gateway failure again.

> Monitor payment success rates, get instant alerts, and intelligently retry failed transactions—all automatically.

## Features

- **Real-Time Monitoring:** Continuously tracks gateway health and success rates.
- **Instant Alerts:** Get notified via Email, Slack, Discord, or Teams when success rates drop below your threshold.
- **Smart Retries:** Automatically retries failed payments for recoverable errors (e.g., temporary gateway downtime).
- **Proactive Protection:** Analyzes recent transactions to provide real-time status indicators in your dashboard.
- **Developer Friendly:** Built with extensibility in mind, featuring clear APIs and hooks.

## Installation

### Requirements

- **WordPress:** 6.5 or higher
- **WooCommerce:** 8.5 or higher (9.0+ recommended)
- **PHP:** 7.4 or higher (8.0+ recommended)
- **MySQL/MariaDB:** 5.7 or higher

### Basic Setup

1. Download the latest release from the [Releases](https://github.com/caplaz/paysentinel-woocommerce/releases) page.
2. Log in to your WordPress admin dashboard.
3. Navigate to **Plugins > Add New** and upload the downloaded ZIP file.
4. Click **Install Now** and then **Activate**.

### Development Setup (Composer)

If you are a developer looking to contribute or customize the plugin, you can install the dependencies via Composer:

```bash
git clone https://github.com/caplaz/paysentinel-woocommerce.git wp-content/plugins/paysentinel-woocommerce
cd wp-content/plugins/paysentinel-woocommerce
composer install
```

## Quick Start

1. Go to **WooCommerce > PaySentinel** in your WordPress dashboard.
2. In the **General Settings**, configure your `Health Check Interval` and `Alert Threshold`.
3. In the **Notification Settings**, configure your preferred alert channels (Email, Slack, Discord, Teams).
4. Enable monitoring for your specific payment gateways.
5. Save your changes and you're good to go!

## Configuration

### Dashboard

The PaySentinel dashboard displays:

- **Health Status:** Real-time payment gateway health indicators
- **Success Rate:** Current payment success percentage for each gateway
- **Recent Alerts:** Active and recent alert notifications
- **Gateway Overview:** Summary of all monitored payment gateways

### Alert Settings

- Set custom success rate thresholds per gateway
- Choose alert severity levels: Info, Warning, Critical
- Configure notification channels (Email, Slack, Discord, Teams)
- Set alert cooldown periods to avoid spam

### Retry Configuration

- Enable/disable automatic payment retry
- Configure retry wait times between attempts
- Set maximum retry count
- Define recoverable error types

## Advanced Features

### Intelligent Alert System

- **Volume-Aware Severity:** Severity considers transaction volume (not just success rate)
- **Smart Filtering:** Automatically excludes user errors (declined cards, insufficient funds)
- **Gateway-Specific Rules:** Custom alert thresholds per payment gateway
- **Immediate Escalation:** Critical alerts trigger instantly for zero success rates

### Payment Retry Logic

- **Automatic Retries:** Recoverable errors (timeouts, temporary failures) auto-retry
- **Exponential Backoff:** Retry delays increase progressively
- **Manual Retry:** Force-retry failed transactions from admin panel
- **Detailed Logging:** Track all retry attempts and outcomes

### Compatibility

- **HPOS Ready:** Full support for WooCommerce High-Performance Order Storage
- **Payment Gateways:** Monitored support for Stripe, PayPal, Square, WC Payments, and custom gateways
- **Multi-Language:** Internationalization-ready (i18n)
- **REST API:** Full REST API for custom integrations

## Frequently Asked Questions

**Q: Which payment gateways does PaySentinel monitor?**  
A: PaySentinel monitors all real payment processor gateways including Stripe, PayPal, Square, WooCommerce Payments, and any custom API-based gateways. It excludes offline methods (Cash on Delivery, Bank Transfer, Cheque) and payment token storage.

**Q: How often does PaySentinel check gateway health?**  
A: You can configure the health check interval in settings (default: every 5 minutes). Checks happen asynchronously to avoid impacting store performance.

**Q: Can I configure different alert thresholds per gateway?**  
A: Yes! Each payment gateway can have custom success rate thresholds and alert destinations.

**Q: Does PaySentinel impact checkout performance?**  
A: No. All monitoring and alerting happens in the background via WordPress scheduled actions, never blocking checkout.

**Q: Is PaySentinel HPOS compatible?**  
A: Yes! PaySentinel is fully compatible with WooCommerce's High-Performance Order Storage.

**Q: Can I manually retry failed payments?**  
A: Yes! The admin interface allows you to manually retry individual failed transactions.

## Documentation

For comprehensive information about PaySentinel, please see our:

- **[Documentation Index](docs/INDEX.md)** - The central hub for all project documentation.
- **[User Guide](docs/USER_GUIDE.md)** - Detailed guide for store owners and administrators.
- **[Developer Guide](docs/DEVELOPER_GUIDE.md)** - Technical documentation for extending the plugin.
- **[Troubleshooting Guide](docs/features/failure-simulator.md)** - How to test and debug payment failures.

## Support

- **Community Support**: [GitHub Discussions](https://github.com/caplaz/paysentinel-woocommerce/discussions)
- **Bug Reports**: [GitHub Issues](https://github.com/caplaz/paysentinel-woocommerce/issues)
- **Contribution Guide**: See [CONTRIBUTING.md](CONTRIBUTING.md)

## Testing

PaySentinel includes comprehensive test coverage:

- **325 automated tests** covering core functionality, alerts, APIs, and gateways
- **PHPUnit** framework with WordPress test suite
- **Full HPOS Integration** testing with 100% pass rate
- **Automated CI/CD** via GitHub Actions
- **Code quality tools** including PHPCS, PHPStan, and PHPMD

Run tests locally:

```bash
make test
```

## Packaging & Releases

Use the Makefile to create a distributable ZIP for releases:

```bash
make package
# produces paysentinel.zip
```

This target excludes tests, vendor/dev files, and documentation to produce a compact plugin ZIP suitable for distribution.

## Contributing

Contributions are welcome! Please read [CONTRIBUTING.md](CONTRIBUTING.md) for guidelines on:

- Setting up your development environment
- Code standards and best practices
- Testing requirements
- Submitting pull requests

## License

This project is licensed under the **GPL-2.0 or later** License - see the [LICENSE](LICENSE) file for details.

## Credits

PaySentinel is developed and maintained with care for the WooCommerce community.

---

**Version:** 1.1.0  
**Last Updated:** March 9, 2026  
**Status:** Production Ready
