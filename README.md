# WooCommerce Payment Monitor (PaySentinel)

![License](https://img.shields.io/badge/license-GPLv2%20or%20later-blue.svg)
![WordPress](https://img.shields.io/badge/WordPress-6.0+-blue.svg)
![WooCommerce](https://img.shields.io/badge/WooCommerce-7.0+-blue.svg)
![PHP](https://img.shields.io/badge/PHP-7.4+-blue.svg)

**PaySentinel** is an advanced WooCommerce plugin that provides real-time monitoring and alerting for your payment gateways. Never lose a sale due to an unnoticed payment gateway failure again.

## Features

- **Real-Time Monitoring:** Continuously tracks gateway health and success rates.
- **Instant Alerts:** Get notified via Email, SMS, or Slack when success rates drop below your threshold.
- **Smart Retries:** Automatically retries failed payments for recoverable errors (e.g., temporary gateway downtime).
- **Proactive Protection:** Analyzes recent transactions to provide real-time status indicators in your dashboard.
- **Developer Friendly:** Built with extensibility in mind, featuring clear APIs and hooks.

## Installation

### Prerequisites

- WordPress 6.0 or higher
- WooCommerce 7.0 or higher
- PHP 7.4 or higher

### Basic Setup

1. Download the latest release from the [Releases](https://github.com/your-username/wc-payment-monitor/releases) page.
2. Log in to your WordPress admin dashboard.
3. Navigate to **Plugins > Add New** and upload the downloaded ZIP file.
4. Click **Install Now** and then **Activate**.

### Development Setup (Composer)

If you are a developer looking to contribute or customize the plugin, you can install the dependencies via Composer:

```bash
git clone https://github.com/your-username/wc-payment-monitor.git wp-content/plugins/wc-payment-monitor
cd wp-content/plugins/wc-payment-monitor
composer install
```

## Quick Start

1. Go to **WooCommerce > PaySentinel** in your WordPress dashboard.
2. In the **General Settings**, configure your `Health Check Interval` and `Alert Threshold`.
3. In the **Notification Settings**, configure where you would like to receive alerts (Email, SMS, Slack).
4. Save your changes and you are good to go!

## Documentation

For technical details, APIs, and guidelines on how to extend the plugin, please check the [Developer Guide](docs/DEVELOPER_GUIDE.md) in the `docs` folder.

## License

This project is licensed under the GPL-2.0 or later License - see the [LICENSE](LICENSE) file for details.
