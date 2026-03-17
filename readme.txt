=== PaySentinel - Payment Monitor for WooCommerce ===
Stable tag: 1.1.0
Requires at least: 6.5
Tested up to: 6.8
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Contributors: caplaz
Donate link: https://github.com/caplaz/paysentinel-woocommerce

Monitor WooCommerce payment gateways in real-time, get instant alerts, and automatically retry failed payments.

== Description ==

**PaySentinel** is an advanced WooCommerce plugin that provides real-time monitoring and alerting for your payment gateways. Never lose a sale due to an unnoticed payment gateway failure again.

= Core Features =

* **Real-Time Gateway Monitoring** - Continuously tracks health and success rates of all payment gateways
* **Instant Alerts** - Get notified via Email, Slack, Discord, or Teams when success rates drop below thresholds
* **Smart Automatic Retries** - Automatically retries failed payments for recoverable errors (temporary gateway downtime, timeouts, etc.)
* **Volume-Aware Intelligence** - Alert severity considers transaction volume, not just success percentages
* **Smart Error Filtering** - Automatically excludes user errors (declined cards, insufficient funds) from alerts
* **Gateway-Specific Configuration** - Set custom success thresholds and alert destinations per payment gateway
* **Manual Retry System** - Force-retry failed transactions directly from the admin dashboard
* **Detailed Logging** - Track all transactions, retries, and alerts with complete audit trail
* **HPOS Compatible** - Full support for WooCommerce High-Performance Order Storage
* **Developer Friendly** - Built with extensibility via hooks, filters, and comprehensive REST API

= Supported Payment Gateways =

PaySentinel monitors all real payment processor gateways and works perfectly with:
- Stripe
- PayPal
- Square
- WooCommerce Payments (Stripe)
- And any custom API-based payment gateway

Offline payment methods (Cash on Delivery, Bank Transfer, Cheque) are automatically excluded from monitoring.

= Dashboard Features =

* Real-time health status indicators per gateway
* Current payment success percentages
* Active and recent alert notifications
* Summary of all monitored payment gateways
* Manual transaction retry controls
* Alert history and trends

= Alert Configuration =

* Custom success rate thresholds per gateway
* Multiple alert severity levels (Info, Warning, Critical)
* Choose notification channels: Email, Slack, Discord, Teams, or webhooks
* Alert cooldown periods to prevent notification fatigue
* Immediate escalation for critical failures

= Security & Performance =

* Background processing - never blocks your checkout
* Asynchronous health checks (default: 5-minute intervals, configurable)
* No impact on checkout performance or page load times
* Comprehensive security audit for input/output handling
* CSRF/nonce protection on all admin actions
* Proper data sanitization and escaping throughout

= Testing & Quality =

* 325 comprehensive unit tests covering core functionality
* PHPUnit test suite with WordPress integration
* Automated CI/CD pipeline via GitHub Actions
* PHPCS WordPress coding standards compliance
* PHPStan static analysis for code quality
* Extensive test coverage for alerts, retries, and gateway detection

= Frequently Asked Questions =

**Does PaySentinel impact checkout performance?**
No. All monitoring and alerting happens in the background via WordPress scheduled actions, completely asynchronous to the checkout process.

**Which payment gateways can PaySentinel monitor?**
All real API-based payment gateways including Stripe, PayPal, Square, and custom processors. Offline methods like Cash on Delivery are excluded as they don't require real-time monitoring.

**Can I set different alert thresholds for different gateways?**
Yes! Each gateway can have custom success rate thresholds, alert destinations, and notification preferences.

**Is PaySentinel HPOS compatible?**
Absolutely! PaySentinel is fully compatible with WooCommerce's High-Performance Order Storage (HPOS).

**How often does PaySentinel check gateway health?**
You can configure the health check interval (default: 5 minutes). All checks happen asynchronously in the background.

**Can I manually retry failed payments?**
Yes! The admin interface provides transaction retry controls for manual payment recovery.

**What happens if an alert triggers?**
PaySentinel sends notifications to configured channels (email, SMS, Slack, webhooks) based on your settings. You can then investigate and manually retry failed transactions if needed.

**Is there documentation for extending PaySentinel?**
Yes! Check the Developer Guide in the plugin package for APIs, hooks, filters, and extension patterns.

== Requirements ==

* WordPress 6.5 or higher
* WooCommerce 8.5 or higher (9.0+ recommended)
* PHP 7.4 or higher (8.0+ recommended)
* MySQL/MariaDB 5.7 or higher

== Installation ==

= From WordPress.org Plugin Directory =

1. Go to **Plugins > Add New** in your WordPress admin
2. Search for "PaySentinel"
3. Click **Install Now**
4. Click **Activate Plugin**
5. Go to **WooCommerce > PaySentinel** to configure

= Manual Installation =

1. Download the plugin ZIP from the releases page
2. Go to **Plugins > Add New > Upload Plugin**
3. Choose the downloaded ZIP file
4. Click **Install Now**
5. Click **Activate Plugin**
6. Go to **WooCommerce > PaySentinel** to configure

= Initial Setup =

1. Navigate to **WooCommerce > PaySentinel** in your dashboard
2. Configure **General Settings**: Set health check interval and alert thresholds
3. Configure **Notification Settings**: Choose preferred alert channels (Email, Slack, Discord, Teams)
4. Enable monitoring for your payment gateways
5. Save and you're monitoring!

== Configuration ==

= General Settings =
* Health Check Interval - How often PaySentinel checks gateway health (default: 5 minutes)
* Alert Threshold - Success rate percentage that triggers alerts (default: 95%)
* Retry Settings - Enable/disable automatic retry and configure retry behavior

= Notification Configuration =
* Email Alerts - Configure email addresses for notifications
* Slack/Discord/Teams - Connect your favorite communication tools
* Webhooks - Send alert data to custom endpoints

= Gateway Monitoring =
* Enable/disable monitoring per payment gateway
* Set gateway-specific success rate thresholds
* Configure alert destinations and severity levels
* Monitor retry and transaction statistics

== Troubleshooting ==

= Alerts not triggering =
* Verify notification channels are properly configured
* Check that payment gateways are enabled for monitoring
* Review alert threshold settings (may be too high)
* Check WordPress error logs for any issues

= Performance concerns =
* PaySentinel runs asynchronously and shouldn't impact performance
* If needed, increase the health check interval in settings
* Monitor background job processing in WordPress

= HPOS compatibility issues =
* Ensure WooCommerce 8.5+ is installed
* Enable HPOS in WooCommerce settings: Settings > Advanced > Orders
* PaySentinel automatically detects and uses HPOS when enabled

= Gateway not being monitored =
* Verify the gateway is a real payment processor (not offline method)
* Check gateway is enabled in WooCommerce settings
* PaySentinel excludes Cash on Delivery, Bank Transfer, Cheque automatically

== Changelog ==

= 1.1.0 - March 17, 2026 =
* Added comprehensive Auto-Retry Engine for soft declines (Starter+ feature)
* Added Smart Decline Detection: automatic hard vs soft decline classification
* Added Recovery Email notifications for hard declines when retry not possible
* Added PRO Analytics Dashboard with ROI tracking and recovery flow visualization
* Added recovery metrics: transaction counts, success rates per gateway, email tracking
* Added License tier validation: Auto-retry enforces Starter+ requirement
* Added 'retry_outcome' to database alert types for retry recovery tracking
* Added 10 new comprehensive tests for retry logic (325 total tests now)
* Fixed order note retrieval in unit tests using WordPress standard get_comments()
* Fixed license tier mocking in retry tests for proper test isolation
* Improved test isolation with proper license option cleanup

= 1.0.2 - March 3, 2026 =
* Added comprehensive gateway detection filtering
* Improved HPOS compatibility and testing
* Fixed test isolation issues in CI/CD
* Added 11 new gateway manager tests
* Enhanced alert system accuracy
* Updated documentation and developer guidelines

= 1.0.1 - February 2026 =
* Fixed critical alerts severity logic
* Added volume-aware alert severity calculation
* Improved soft error detection
* Enhanced retry mechanism
* Added comprehensive test coverage

= 1.0.0 - January 2026 =
* Initial public release
* Real-time payment gateway monitoring
* Instant alert notifications (Email, SMS, Slack)
* Automatic payment retry mechanism
* Detailed dashboard and reporting
* Full WordPress and WooCommerce compatibility

== Screenshots ==

1. **Dashboard Overview** - Real-time payment gateway health status and alerts
2. **Alert Management** - Configure alert thresholds, channels, and notification rules
3. **Transaction Monitoring** - View recent transactions and retry failed payments
4. **Settings Configuration** - Configure health checks, retries, and notifications

== Support ==

* **GitHub Repository**: https://github.com/caplaz/paysentinel-woocommerce
* **GitHub Issues**: Report bugs and request features
* **Documentation**: https://github.com/caplaz/paysentinel-woocommerce/tree/main/docs
* **Developer Guide**: Contributing guidelines and architecture documentation

== Development ==

PaySentinel is actively developed and maintained on GitHub. Visit the repository for:
* Source code and issue tracking
* Contributing guidelines
* Developer documentation
* Test coverage and CI/CD setup

== Credits ==

PaySentinel is developed and maintained with care for the WooCommerce community.

== License ==

This plugin is licensed under the GPL v2 or later.

    This program is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License, version 2, as
    published by the Free Software Foundation.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program; if not, write to the Free Software
    Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA

== Warranty Disclaimer ==

This plugin is provided "as is" without warranty of any kind, express or implied, including but not limited to the warranties of merchantability, fitness for a particular purpose and non-infringement. PaySentinel is free software, and you use it at your own risk. The authors and maintainers shall not be liable for any damages or losses resulting from the use of this plugin.

== Additional Notes ==

= A Note About Payment Monitoring =
PaySentinel helps you monitor payment gateway health and availability. It is not a replacement for proper payment failure handling in your checkout process. Always implement proper error handling, customer communication, and support procedures.

= HPOS Support =
PaySentinel fully supports WooCommerce High-Performance Order Storage (HPOS). Enable it in WooCommerce settings for optimized performance with large order volumes.

= Contribution =
We welcome contributions! Please see CONTRIBUTING.md in the plugin package for guidelines on setting up a development environment, code standards, testing requirements, and the pull request process.
