# PaySentinel User Guide

Welcome to the **PaySentinel** user guide! This document explains the primary features of the plugin and how to configure them for your WooCommerce store.

## Table of Contents

1. [Real-Time Monitoring](#real-time-monitoring)
2. [Alert System](#alert-system)
3. [Auto-Retry Feature](#auto-retry-feature)
4. [Dashboard Overview](#dashboard-overview)
5. [Troubleshooting](#troubleshooting)

---

## Real-Time Monitoring

PaySentinel continuously monitors your payment gateways to ensure they are functioning correctly. It tracks transactions in real-time and calculates success rates for each enabled gateway.

- **Success Rate Calculation**: Calculated based on the number of successful vs. failed transactions over a configured time period.
- **Historical Data**: View 30-day history of gateway performance (Starter+ plans).

## Alert System

Get notified immediately when your payment gateways experience issues.

### Notification Channels
- **Email**: Receive alerts directly at your admin email.
- **Slack/Discord/Teams**: Integrate with your favorite communication tools.
- **SMS**: Get text alerts for critical failures (Starter+ plans).

### Alert Severities
- **Info**: Minor fluctuations or successful recoveries.
- **Warning**: Success rate dropping below threshold.
- **Critical**: Gateway is completely down (0% success rate).

## Auto-Retry Feature

*Available in Starter plans and above.*

The **Auto-Retry** feature intelligently recovers lost sales by automatically re-attempting failed transactions using stored payment tokens.

- **Exponential Backoff**: Retries are attempted at 1 hour, 6 hours, and 24 hours.
- **Smart Detection**: Only "soft" failures (like temporary gateway downtime) are retried. Hard declines (like "stolen card") are not retried.
- **Customer Notifications**: Customers receive branded emails when a recovery succeeds or if they need to update their payment method.

[Read the full Auto-Retry documentation](features/auto-retry.md)

## Dashboard Overview

The PaySentinel Dashboard (**WooCommerce > PaySentinel**) provides a high-level view of your store's payment health:

- **Gateway Status**: Green/Yellow/Red indicators for each gateway.
- **Performance Charts**: Visual representation of success rates over time.
- **Active Alerts**: List of current issues needing attention.
- **Recent Transactions**: searchable log of recent payment attempts and their outcomes.

## Troubleshooting

If you encounter issues with PaySentinel:

1. **Check the Logs**: Go to **WooCommerce > Status > Logs** and select the `paysentinel` log.
2. **Failure Simulator**: Use the [Failure Simulator](features/failure-simulator.md) to test your alert configurations in a sandbox environment.
3. **Common Issues**:
    - **No Alerts Sent**: Verify your notification settings and check if WP-Cron is running.
    - **High False Positives**: Adjust your "Health Check Interval" and "Alert Threshold" in settings.

---

For more technical details, see the [Developer Guide](DEVELOPER_GUIDE.md).
