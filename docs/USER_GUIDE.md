# WC Payment Monitor - User Guide

## 1. Introduction

High-fidelity payment monitoring for WooCommerce. This plugin helps store owners track payment gateway performance, receive alerts for failure spikes, and automatically retry failed transactions.

## 2. Installation

1. Upload the `sentinel` folder to the `/wp-content/plugins/` directory.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. A new menu item "Payment Monitor" will appear in the main admin dashboard.

## 3. Configuration

Navigate to **Payment Monitor > Settings**.

### General Settings

- **Enable Monitoring**: Toggle the master switch for the logging system.
- **Health Check Interval**: Frequency of gateway connectivity checks (default: 5 minutes).

### Alert Settings

- **Alert Threshold**: The failure rate percentage that triggers an alert (e.g., 20%).
- **Alert Recipient**: Email address to receive notifications.

### Retry Settings

- **Enable Auto-Retry**: Allow the system to automatically retry failed payments.
- **Max Retry Attempts**: How many times to retry a failed transaction (default: 3).

## 4. Dashboard Overview

The dashboard provides a real-time view of your payment health.

- **Gateway Health**: Visual indicators of Stripe/PayPal status.
- **Recent Failures**: A list of the latest failed transactions.
- **Success Rate**: Historical charts of payment success vs. failure.

## 5. Transactions

View a detailed log of every payment attempt under **Payment Monitor > Transactions**.

- Status: Success, Failed, Pending, or Retry.
- Details: Click the "eye" icon to see failure reasons, error codes, and customer info.
- **Manual Actions**: You can manually trigger a retry or send a recovery email for failed orders.

## 6. Smart Retry System

The plugin intelligently handles failed payments:

- **Soft Declines** (e.g., Timeout, Network Error): Automatically retries up to 3 times on a schedule (1h, 6h, 24h).
- **Hard Declines** (e.g., Stolen Card, Fraud): Stops retries immediately and sends a recovery email to the customer with a direct payment link.

## 7. Troubleshooting

If you are not receiving alerts:

- Check your WordPress Cron is running.
- Verify your email settings in WordPress.
- Review the **Diagnostics** tab in the plugin to check system health.
