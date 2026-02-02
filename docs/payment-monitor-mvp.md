# WooCommerce Payment Failure Monitor

## MVP Technical Specification v1.0

**Document Version:** 1.0  
**Last Updated:** January 2026  
**Target MVP Launch:** 12 weeks from start  
**Primary Author:** Technical Specification Team

---

## 1. EXECUTIVE SUMMARY

### 1.1 Product Vision

A WordPress plugin that monitors WooCommerce payment gateway health in real-time, alerts store owners when payments fail, and provides actionable diagnostics to recover lost revenue.

### 1.2 Core Value Proposition

"Stop losing sales to payment failures. Monitor gateway health, get instant alerts, and recover failed transactions automatically."

### 1.3 Target Users

- WooCommerce store owners (annual revenue: $50K - $2M)
- Store managers responsible for operations
- Technical administrators managing payment integrations

### 1.4 Success Metrics (MVP)

- 1,000 plugin installations in first 90 days
- 5% conversion to paid tier
- 80% user retention after 30 days
- Average of $500 revenue recovered per paying customer/month

---

## 2. TECHNICAL ARCHITECTURE

### 2.1 System Overview

```
┌─────────────────────────────────────────────────────────┐
│                    WordPress Site                        │
│  ┌───────────────────────────────────────────────────┐  │
│  │          WooCommerce Payment Monitor Plugin       │  │
│  │                                                   │  │
│  │  ┌──────────────┐  ┌──────────────┐            │  │
│  │  │   Monitor    │  │   Alert      │            │  │
│  │  │   Engine     │  │   System     │            │  │
│  │  └──────┬───────┘  └──────┬───────┘            │  │
│  │         │                  │                     │  │
│  │  ┌──────▼──────────────────▼───────┐            │  │
│  │  │      Data Layer / Storage       │            │  │
│  │  └──────┬──────────────────────────┘            │  │
│  │         │                                        │  │
│  │  ┌──────▼──────────────────────────┐            │  │
│  │  │     Admin Dashboard UI          │            │  │
│  │  └─────────────────────────────────┘            │  │
│  └───────────────────────────────────────────────────┘  │
│                                                          │
│  ┌───────────────────────────────────────────────────┐  │
│  │              WooCommerce Core                     │  │
│  └───────────┬───────────────────────────────────────┘  │
└──────────────┼──────────────────────────────────────────┘
               │
      ┌────────▼────────┐
      │  Payment Gateway │
      │   APIs (Stripe,  │
      │  PayPal, Square) │
      └──────────────────┘
```

### 2.2 Technology Stack

**Core Platform:**

- WordPress: 6.4+
- WooCommerce: 8.0+
- PHP: 7.4+ (8.0+ recommended)
- MySQL: 5.7+ / MariaDB: 10.3+

**Frontend:**

- React: 18.x (for admin dashboard)
- Chart.js: 4.x (for analytics visualization)
- Tailwind CSS: 3.x (via CDN, core utilities only)

**Backend:**

- WordPress REST API
- WooCommerce REST API
- WordPress Cron (for scheduled tasks)

**External Services:**

- Stripe API v2023-10-16
- PayPal REST API
- Square API v2023-10-18
- Twilio API (for SMS alerts - optional)

---

## 3. DATABASE SCHEMA

### 3.1 Custom Tables

#### Table: `wp_wc_payment_monitor_transactions`

Stores transaction monitoring data.

```sql
CREATE TABLE `wp_wc_payment_monitor_transactions` (
  `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id` BIGINT(20) UNSIGNED NOT NULL,
  `gateway_id` VARCHAR(50) NOT NULL,
  `transaction_id` VARCHAR(100) DEFAULT NULL,
  `amount` DECIMAL(10,2) NOT NULL,
  `currency` VARCHAR(3) NOT NULL,
  `status` ENUM('success', 'failed', 'pending', 'retry') NOT NULL,
  `failure_reason` TEXT DEFAULT NULL,
  `failure_code` VARCHAR(50) DEFAULT NULL,
  `retry_count` TINYINT(3) UNSIGNED DEFAULT 0,
  `customer_email` VARCHAR(100) DEFAULT NULL,
  `customer_ip` VARCHAR(45) DEFAULT NULL,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  INDEX `idx_order_id` (`order_id`),
  INDEX `idx_gateway_status` (`gateway_id`, `status`),
  INDEX `idx_created_at` (`created_at`),
  INDEX `idx_status_retry` (`status`, `retry_count`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

#### Table: `wp_wc_payment_monitor_gateway_health`

Stores gateway health metrics.

```sql
CREATE TABLE `wp_wc_payment_monitor_gateway_health` (
  `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `gateway_id` VARCHAR(50) NOT NULL,
  `period` ENUM('1hour', '24hour', '7day') NOT NULL,
  `total_transactions` INT(11) UNSIGNED NOT NULL DEFAULT 0,
  `successful_transactions` INT(11) UNSIGNED NOT NULL DEFAULT 0,
  `failed_transactions` INT(11) UNSIGNED NOT NULL DEFAULT 0,
  `success_rate` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  `avg_response_time` INT(11) UNSIGNED DEFAULT NULL COMMENT 'milliseconds',
  `last_failure_at` DATETIME DEFAULT NULL,
  `calculated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_gateway_period` (`gateway_id`, `period`, `calculated_at`),
  INDEX `idx_gateway_period` (`gateway_id`, `period`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

#### Table: `wp_wc_payment_monitor_alerts`

Stores alert history.

```sql
CREATE TABLE `wp_wc_payment_monitor_alerts` (
  `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `alert_type` ENUM('gateway_down', 'low_success_rate', 'high_failure_count', 'gateway_error') NOT NULL,
  `gateway_id` VARCHAR(50) NOT NULL,
  `severity` ENUM('info', 'warning', 'critical') NOT NULL,
  `message` TEXT NOT NULL,
  `metadata` TEXT DEFAULT NULL COMMENT 'JSON data',
  `is_resolved` TINYINT(1) DEFAULT 0,
  `resolved_at` DATETIME DEFAULT NULL,
  `notified_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  INDEX `idx_gateway_created` (`gateway_id`, `created_at`),
  INDEX `idx_resolved` (`is_resolved`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### 3.2 WordPress Options

Store plugin settings in `wp_options`:

```php
// Plugin settings
'wc_payment_monitor_settings' => [
    'enabled_gateways' => ['stripe', 'paypal', 'square'],
    'alert_email' => 'admin@store.com',
    'alert_threshold' => 85, // Alert when success rate drops below 85%
    'monitoring_interval' => 300, // seconds
    'enable_auto_retry' => true,
    'retry_schedule' => [3600, 21600, 86400], // 1hr, 6hr, 24hr
]

// License/activation
'wc_payment_monitor_license' => [
    'key' => 'xxx-xxx-xxx',
    'tier' => 'pro', // free, starter, pro, enterprise
    'expires' => '2026-12-31',
    'site_url' => 'https://example.com'
]

// Gateway credentials (encrypted)
'wc_payment_monitor_credentials' => [
    'stripe' => ['api_key' => 'encrypted_key'],
    'paypal' => ['client_id' => 'encrypted_id', 'secret' => 'encrypted_secret'],
]
```

---

## 4. CORE FEATURES & REQUIREMENTS

### 4.1 Feature: Real-Time Transaction Monitoring

#### Requirements

- **FR-001**: Plugin MUST hook into WooCommerce payment process
- **FR-002**: Plugin MUST log all payment attempts (success/failure)
- **FR-003**: Plugin MUST capture failure reason and error codes
- **FR-004**: Plugin MUST NOT interfere with normal checkout flow
- **FR-005**: Plugin MUST handle high transaction volumes (1000+/hour)

#### Implementation

```php
<?php
/**
 * Hook into WooCommerce payment events
 */
class WC_Payment_Monitor_Logger {

    public function __construct() {
        // Hook successful payments
        add_action('woocommerce_payment_complete', [$this, 'log_success'], 10, 1);

        // Hook failed payments
        add_action('woocommerce_order_status_failed', [$this, 'log_failure'], 10, 1);

        // Hook pending payments
        add_action('woocommerce_order_status_pending', [$this, 'log_pending'], 10, 1);
    }

    /**
     * Log successful payment
     */
    public function log_success($order_id) {
        $order = wc_get_order($order_id);

        $data = [
            'order_id' => $order_id,
            'gateway_id' => $order->get_payment_method(),
            'transaction_id' => $order->get_transaction_id(),
            'amount' => $order->get_total(),
            'currency' => $order->get_currency(),
            'status' => 'success',
            'customer_email' => $order->get_billing_email(),
            'customer_ip' => $order->get_customer_ip_address(),
            'created_at' => current_time('mysql'),
        ];

        $this->save_transaction($data);
        $this->update_gateway_health($data['gateway_id'], 'success');
    }

    /**
     * Log failed payment
     */
    public function log_failure($order_id) {
        $order = wc_get_order($order_id);

        // Extract failure reason from order notes
        $notes = wc_get_order_notes([
            'order_id' => $order_id,
            'limit' => 1,
            'orderby' => 'date_created_gmt',
            'order' => 'DESC',
        ]);

        $failure_reason = !empty($notes) ? $notes[0]->content : 'Unknown error';

        $data = [
            'order_id' => $order_id,
            'gateway_id' => $order->get_payment_method(),
            'amount' => $order->get_total(),
            'currency' => $order->get_currency(),
            'status' => 'failed',
            'failure_reason' => $failure_reason,
            'failure_code' => $this->extract_error_code($failure_reason),
            'customer_email' => $order->get_billing_email(),
            'customer_ip' => $order->get_customer_ip_address(),
            'created_at' => current_time('mysql'),
        ];

        $this->save_transaction($data);
        $this->update_gateway_health($data['gateway_id'], 'failed');

        // Trigger failure alert if threshold exceeded
        $this->check_alert_threshold($data['gateway_id']);
    }

    /**
     * Save transaction to database
     */
    private function save_transaction($data) {
        global $wpdb;
        $table = $wpdb->prefix . 'wc_payment_monitor_transactions';
        $wpdb->insert($table, $data);
    }

    /**
     * Update gateway health metrics
     */
    private function update_gateway_health($gateway_id, $result) {
        // Implementation in section 4.2
    }
}
```

### 4.2 Feature: Gateway Health Monitoring

#### Requirements

- **FR-010**: Plugin MUST calculate success rates for 1hr, 24hr, 7day periods
- **FR-011**: Plugin MUST detect when success rate drops below threshold
- **FR-012**: Plugin MUST identify gateway downtime
- **FR-013**: Plugin MUST store historical health data
- **FR-014**: Health calculations MUST run every 5 minutes

#### Implementation

```php
<?php
/**
 * Gateway health calculator
 */
class WC_Payment_Monitor_Health {

    /**
     * Calculate health metrics for all periods
     */
    public function calculate_health($gateway_id) {
        $periods = [
            '1hour' => 3600,
            '24hour' => 86400,
            '7day' => 604800,
        ];

        foreach ($periods as $period => $seconds) {
            $this->calculate_period_health($gateway_id, $period, $seconds);
        }
    }

    /**
     * Calculate health for specific period
     */
    private function calculate_period_health($gateway_id, $period, $seconds) {
        global $wpdb;
        $table = $wpdb->prefix . 'wc_payment_monitor_transactions';

        $cutoff = date('Y-m-d H:i:s', time() - $seconds);

        // Get transaction counts
        $stats = $wpdb->get_row($wpdb->prepare("
            SELECT
                COUNT(*) as total,
                SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) as successful,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed
            FROM {$table}
            WHERE gateway_id = %s
            AND created_at >= %s
        ", $gateway_id, $cutoff));

        $total = (int) $stats->total;
        $successful = (int) $stats->successful;
        $failed = (int) $stats->failed;

        $success_rate = $total > 0 ? ($successful / $total) * 100 : 0;

        // Save health data
        $health_table = $wpdb->prefix . 'wc_payment_monitor_gateway_health';

        $wpdb->insert($health_table, [
            'gateway_id' => $gateway_id,
            'period' => $period,
            'total_transactions' => $total,
            'successful_transactions' => $successful,
            'failed_transactions' => $failed,
            'success_rate' => round($success_rate, 2),
            'calculated_at' => current_time('mysql'),
        ]);

        return [
            'success_rate' => $success_rate,
            'total' => $total,
            'failed' => $failed,
        ];
    }

    /**
     * Get current health status
     */
    public function get_health_status($gateway_id, $period = '1hour') {
        global $wpdb;
        $table = $wpdb->prefix . 'wc_payment_monitor_gateway_health';

        return $wpdb->get_row($wpdb->prepare("
            SELECT *
            FROM {$table}
            WHERE gateway_id = %s AND period = %s
            ORDER BY calculated_at DESC
            LIMIT 1
        ", $gateway_id, $period));
    }
}
```

### 4.3 Feature: Alert System

#### Requirements

- **FR-020**: Plugin MUST send email alerts when success rate drops below threshold
- **FR-021**: Plugin MUST support multiple alert channels (email, SMS, Slack)
- **FR-022**: Plugin MUST prevent alert fatigue (rate limiting)
- **FR-023**: Plugin MUST show alerts in admin dashboard
- **FR-024**: Plugin MUST allow alert configuration per gateway

#### Implementation

```php
<?php
/**
 * Alert management system
 */
class WC_Payment_Monitor_Alerts {

    private $rate_limit = 3600; // Don't send same alert more than once per hour

    /**
     * Check if alert should be triggered
     */
    public function check_and_send($gateway_id, $health_data) {
        $settings = get_option('wc_payment_monitor_settings');
        $threshold = $settings['alert_threshold'] ?? 85;

        if ($health_data['success_rate'] < $threshold) {
            $this->trigger_alert([
                'type' => 'low_success_rate',
                'gateway' => $gateway_id,
                'severity' => $this->calculate_severity($health_data['success_rate']),
                'message' => sprintf(
                    '%s success rate dropped to %.2f%% (%d failed transactions)',
                    ucfirst($gateway_id),
                    $health_data['success_rate'],
                    $health_data['failed']
                ),
                'metadata' => json_encode($health_data),
            ]);
        }
    }

    /**
     * Trigger alert through configured channels
     */
    private function trigger_alert($alert_data) {
        // Check if we already sent this alert recently
        if ($this->is_rate_limited($alert_data)) {
            return;
        }

        // Save alert to database
        $alert_id = $this->save_alert($alert_data);

        // Send through configured channels
        $settings = get_option('wc_payment_monitor_settings');

        // Email
        if (!empty($settings['alert_email'])) {
            $this->send_email_alert($alert_data, $settings['alert_email']);
        }

        // SMS (Pro feature)
        if (!empty($settings['alert_phone']) && $this->is_pro_user()) {
            $this->send_sms_alert($alert_data, $settings['alert_phone']);
        }

        // Slack (Pro feature)
        if (!empty($settings['slack_webhook']) && $this->is_pro_user()) {
            $this->send_slack_alert($alert_data, $settings['slack_webhook']);
        }

        // Update alert as notified
        $this->mark_alert_notified($alert_id);
    }

    /**
     * Send email alert
     */
    private function send_email_alert($alert_data, $email) {
        $subject = sprintf('[Payment Alert] %s - %s',
            ucfirst($alert_data['severity']),
            $alert_data['gateway']
        );

        $message = $this->get_email_template($alert_data);

        $headers = ['Content-Type: text/html; charset=UTF-8'];

        wp_mail($email, $subject, $message, $headers);
    }

    /**
     * Email template
     */
    private function get_email_template($alert_data) {
        ob_start();
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; }
                .alert { padding: 20px; border-left: 4px solid #dc3545; background: #f8f9fa; }
                .critical { border-left-color: #dc3545; }
                .warning { border-left-color: #ffc107; }
                .info { border-left-color: #17a2b8; }
                .button {
                    display: inline-block;
                    padding: 10px 20px;
                    background: #007bff;
                    color: white;
                    text-decoration: none;
                    border-radius: 4px;
                }
            </style>
        </head>
        <body>
            <div class="alert <?php echo esc_attr($alert_data['severity']); ?>">
                <h2>⚠️ Payment Gateway Alert</h2>
                <p><strong>Gateway:</strong> <?php echo esc_html(ucfirst($alert_data['gateway'])); ?></p>
                <p><strong>Severity:</strong> <?php echo esc_html(ucfirst($alert_data['severity'])); ?></p>
                <p><strong>Message:</strong> <?php echo esc_html($alert_data['message']); ?></p>
                <p><strong>Time:</strong> <?php echo esc_html(current_time('Y-m-d H:i:s')); ?></p>

                <p style="margin-top: 20px;">
                    <a href="<?php echo admin_url('admin.php?page=wc-payment-monitor'); ?>" class="button">
                        View Dashboard
                    </a>
                </p>
            </div>
        </body>
        </html>
        <?php
        return ob_get_clean();
    }

    /**
     * Check rate limiting
     */
    private function is_rate_limited($alert_data) {
        global $wpdb;
        $table = $wpdb->prefix . 'wc_payment_monitor_alerts';

        $recent = $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(*)
            FROM {$table}
            WHERE alert_type = %s
            AND gateway_id = %s
            AND created_at >= %s
        ",
            $alert_data['type'],
            $alert_data['gateway'],
            date('Y-m-d H:i:s', time() - $this->rate_limit)
        ));

        return $recent > 0;
    }

    /**
     * Calculate alert severity
     */
    private function calculate_severity($success_rate) {
        if ($success_rate < 70) return 'critical';
        if ($success_rate < 85) return 'warning';
        return 'info';
    }
}
```

### 4.4 Feature: Admin Dashboard

#### Requirements

- **FR-030**: Plugin MUST provide visual dashboard in WordPress admin
- **FR-031**: Dashboard MUST show real-time gateway health status
- **FR-032**: Dashboard MUST display recent failed transactions
- **FR-033**: Dashboard MUST show revenue recovery opportunities
- **FR-034**: Dashboard MUST be mobile-responsive

#### Implementation Structure

```
admin/
├── dashboard.php           # Main dashboard page
├── settings.php           # Settings page
├── components/
│   ├── GatewayHealth.jsx  # Gateway status cards
│   ├── FailedTransactions.jsx
│   ├── RevenueMetrics.jsx
│   └── AlertHistory.jsx
├── api/
│   └── endpoints.php      # REST API endpoints
└── assets/
    ├── css/
    │   └── admin-styles.css
    └── js/
        └── dashboard-app.js
```

**React Dashboard Component (GatewayHealth.jsx):**

```javascript
import React, { useState, useEffect } from "react";

const GatewayHealth = () => {
  const [gateways, setGateways] = useState([]);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    fetchGatewayHealth();
    // Refresh every 30 seconds
    const interval = setInterval(fetchGatewayHealth, 30000);
    return () => clearInterval(interval);
  }, []);

  const fetchGatewayHealth = async () => {
    try {
      const response = await fetch(
        "/wp-json/wc-payment-monitor/v1/gateway-health",
      );
      const data = await response.json();
      setGateways(data);
      setLoading(false);
    } catch (error) {
      console.error("Failed to fetch gateway health:", error);
    }
  };

  const getStatusColor = (successRate) => {
    if (successRate >= 95) return "bg-green-500";
    if (successRate >= 85) return "bg-yellow-500";
    return "bg-red-500";
  };

  const getStatusText = (successRate) => {
    if (successRate >= 95) return "Healthy";
    if (successRate >= 85) return "Degraded";
    return "Critical";
  };

  if (loading) {
    return <div className='p-4'>Loading gateway health...</div>;
  }

  return (
    <div className='grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4'>
      {gateways.map((gateway) => (
        <div key={gateway.id} className='bg-white rounded-lg shadow p-6'>
          <div className='flex items-center justify-between mb-4'>
            <h3 className='text-lg font-semibold capitalize'>{gateway.name}</h3>
            <span
              className={`px-3 py-1 rounded-full text-white text-sm ${getStatusColor(gateway.success_rate)}`}>
              {getStatusText(gateway.success_rate)}
            </span>
          </div>

          <div className='space-y-2'>
            <div className='flex justify-between'>
              <span className='text-gray-600'>Success Rate (24h)</span>
              <span className='font-semibold'>{gateway.success_rate}%</span>
            </div>

            <div className='flex justify-between'>
              <span className='text-gray-600'>Total Transactions</span>
              <span className='font-semibold'>
                {gateway.total_transactions}
              </span>
            </div>

            <div className='flex justify-between'>
              <span className='text-gray-600'>Failed</span>
              <span className='font-semibold text-red-600'>
                {gateway.failed_transactions}
              </span>
            </div>

            {gateway.last_failure && (
              <div className='text-sm text-gray-500 mt-3'>
                Last failure: {new Date(gateway.last_failure).toLocaleString()}
              </div>
            )}
          </div>
        </div>
      ))}
    </div>
  );
};

export default GatewayHealth;
```

### 4.5 Feature: Payment Retry Logic

#### Requirements

- **FR-040**: Plugin MUST support configurable retry schedules
- **FR-041**: Plugin MUST use stored payment methods for retry (Leveraging official Gateway Extensions)
- **FR-042**: Plugin MUST track retry attempts and success rates
- **FR-043**: Plugin MUST notify customers of retry attempts
- **FR-044**: Plugin MUST respect maximum retry limits

#### Implementation

The retry engine acts as a coordinator, delegating the actual transaction processing to the installed and active WooCommerce Payment Gateway extensions (e.g., WooCommerce Stripe Gateway, WooCommerce PayPal Payments). This ensures:

1.  **PCI Compliance**: Tokens are handled securely by certified extensions.
2.  **Compatibility**: Retries utilize the exact same flow as Subscription Renewals (`scheduled_subscription_payment` method) or standard Checkout (`process_payment`).
3.  **Accuracy**: Success and Failure are determined by the gateway's real response, capturing detailed error codes (e.g., `card_declined`, `insufficient_funds`) directly from order metadata and logs.

```php
<?php
/**
 * Automatic payment retry system
 */
class WC_Payment_Monitor_Retry {

    /**
     * Schedule retry for failed payment
     */
    public function schedule_retry($transaction_id) {
        $settings = get_option('wc_payment_monitor_settings');

        if (!$settings['enable_auto_retry']) {
            return false;
        }

        $transaction = $this->get_transaction($transaction_id);

        // Don't retry if max attempts reached
        if ($transaction->retry_count >= 3) {
            return false;
        }

        // Get retry schedule (in seconds)
        $retry_schedule = $settings['retry_schedule'] ?? [3600, 21600, 86400];
        $next_retry = $retry_schedule[$transaction->retry_count];

        // Schedule WordPress cron event
        wp_schedule_single_event(
            time() + $next_retry,
            'wc_payment_monitor_retry',
            [$transaction_id]
        );

        return true;
    }

    /**
     * Attempt payment retry
     */
    public function attempt_retry($transaction_id) {
        $transaction = $this->get_transaction($transaction_id);
        $order = wc_get_order($transaction->order_id);

        if (!$order) {
            return false;
        }

        // Get payment gateway
        $gateway = WC()->payment_gateways->payment_gateways()[$transaction->gateway_id];

        if (!$gateway) {
            return false; // Gateway extension not active
        }

        // Increment retry count
        $this->increment_retry_count($transaction_id);

        // Attempt payment leveraged from official extension logic
        // 1. Try 'scheduled_subscription_payment' (Preferred for background/off-session)
        // 2. Fallback to 'process_payment' with stored token injection

        // Note: The below is a logical representation, actual method handles specific API nuances
        $result = $this->process_gateway_payment($gateway, $order);

        if ($result['success']) {
            // Update transaction status
            $this->update_transaction_status($transaction_id, 'success');

            // Send success notification
            $this->send_retry_success_email($order);

            return true;
        } else {
            // Update with new failure reason
            $this->update_transaction_status($transaction_id, 'failed');
            // Log specific gateway error codes captured from order meta/notes

            // Schedule next retry if within limit
            $this->schedule_retry($transaction_id);

            return false;
        }
    }

    /**
     * Send retry success notification
     */
    private function send_retry_success_email($order) {
        $mailer = WC()->mailer();

        $subject = sprintf('Payment Successful for Order #%s', $order->get_order_number());

        $message = sprintf(
            'Good news! Your payment for order #%s has been successfully processed after an initial failure. Your order is now being prepared for shipment.',
            $order->get_order_number()
        );

        $mailer->send(
            $order->get_billing_email(),
            $subject,
            $mailer->wrap_message($subject, $message)
        );
    }
}

// Register cron hook
add_action('wc_payment_monitor_retry', function($transaction_id) {
    $retry = new WC_Payment_Monitor_Retry();
    $retry->attempt_retry($transaction_id);
});
```

---

## 5. REST API ENDPOINTS

### 5.1 Gateway Health Endpoints

```php
/**
 * GET /wp-json/wc-payment-monitor/v1/gateway-health
 * Returns health status for all gateways
 */
register_rest_route('wc-payment-monitor/v1', '/gateway-health', [
    'methods' => 'GET',
    'callback' => 'wcpm_get_gateway_health',
    'permission_callback' => function() {
        return current_user_can('manage_woocommerce');
    }
]);

function wcpm_get_gateway_health() {
    global $wpdb;
    $table = $wpdb->prefix . 'wc_payment_monitor_gateway_health';

    // Get latest health for each gateway
    $gateways = $wpdb->get_results("
        SELECT
            gh.*,
            g.title as gateway_name
        FROM (
            SELECT gateway_id, MAX(calculated_at) as latest
            FROM {$table}
            WHERE period = '24hour'
            GROUP BY gateway_id
        ) latest
        JOIN {$table} gh ON gh.gateway_id = latest.gateway_id
            AND gh.calculated_at = latest.latest
        LEFT JOIN {$wpdb->prefix}woocommerce_payment_tokenmeta g
            ON g.meta_value = gh.gateway_id
    ");

    return rest_ensure_response($gateways);
}

/**
 * GET /wp-json/wc-payment-monitor/v1/gateway-health/{gateway_id}
 * Returns health history for specific gateway
 */
register_rest_route('wc-payment-monitor/v1', '/gateway-health/(?P<gateway_id>[a-zA-Z0-9_-]+)', [
    'methods' => 'GET',
    'callback' => 'wcpm_get_gateway_health_history',
    'permission_callback' => function() {
        return current_user_can('manage_woocommerce');
    }
]);
```

### 5.2 Transaction Endpoints

```php
/**
 * GET /wp-json/wc-payment-monitor/v1/transactions
 * Returns recent transactions with filters
 */
register_rest_route('wc-payment-monitor/v1', '/transactions', [
    'methods' => 'GET',
    'callback' => 'wcpm_get_transactions',
    'permission_callback' => function() {
        return current_user_can('manage_woocommerce');
    },
    'args' => [
        'status' => [
            'default' => 'all',
            'enum' => ['all', 'success', 'failed', 'pending', 'retry']
        ],
        'gateway' => [
            'default' => 'all'
        ],
        'limit' => [
            'default' => 50,
            'type' => 'integer',
            'maximum' => 200
        ],
        'offset' => [
            'default' => 0,
            'type' => 'integer'
        ]
    ]
]);

/**
 * POST /wp-json/wc-payment-monitor/v1/transactions/{id}/retry
 * Manually trigger retry for failed transaction
 */
register_rest_route('wc-payment-monitor/v1', '/transactions/(?P<id>\d+)/retry', [
    'methods' => 'POST',
    'callback' => 'wcpm_retry_transaction',
    'permission_callback' => function() {
        return current_user_can('manage_woocommerce');
    }
]);
```

### 5.3 Alert Endpoints

```php
/**
 * GET /wp-json/wc-payment-monitor/v1/alerts
 * Returns recent alerts
 */
register_rest_route('wc-payment-monitor/v1', '/alerts', [
    'methods' => 'GET',
    'callback' => 'wcpm_get_alerts',
    'permission_callback' => function() {
        return current_user_can('manage_woocommerce');
    }
]);

/**
 * POST /wp-json/wc-payment-monitor/v1/alerts/{id}/resolve
 * Mark alert as resolved
 */
register_rest_route('wc-payment-monitor/v1', '/alerts/(?P<id>\d+)/resolve', [
    'methods' => 'POST',
    'callback' => 'wcpm_resolve_alert',
    'permission_callback' => function() {
        return current_user_can('manage_woocommerce');
    }
]);
```

---

## 6. SECURITY CONSIDERATIONS

### 6.1 Data Protection

**Payment Credentials:**

- Store API keys encrypted using WordPress encryption functions
- Never log sensitive data (full card numbers, CVV)
- Use WooCommerce's existing payment token system

**Code Example:**

```php
function wcpm_encrypt_credential($value) {
    if (!function_exists('openssl_encrypt')) {
        return base64_encode($value); // Fallback
    }

    $encryption_key = wp_salt('auth');
    $iv = openssl_random_pseudo_bytes(16);

    $encrypted = openssl_encrypt(
        $value,
        'AES-256-CBC',
        hash('sha256', $encryption_key),
        0,
        $iv
    );

    return base64_encode($iv . $encrypted);
}

function wcpm_decrypt_credential($encrypted) {
    if (!function_exists('openssl_decrypt')) {
        return base64_decode($encrypted); // Fallback
    }

    $data = base64_decode($encrypted);
    $encryption_key = wp_salt('auth');
    $iv = substr($data, 0, 16);
    $encrypted_value = substr($data, 16);

    return openssl_decrypt(
        $encrypted_value,
        'AES-256-CBC',
        hash('sha256', $encryption_key),
        0,
        $iv
    );
}
```

### 6.2 Access Control

- Use WordPress capabilities (`manage_woocommerce`)
- Implement nonce verification for all forms
- Validate and sanitize all inputs
- Use prepared statements for database queries

### 6.3 PCI Compliance

- Do NOT store card numbers (use gateway tokens)
- Do NOT store CVV codes
- Use HTTPS for all API communications
- Follow payment gateway security guidelines

---

## 7. TESTING STRATEGY

### 7.1 Unit Tests

**Test Coverage Required:**

- Database operations (CRUD)
- Health calculations
- Alert triggering logic
- Retry scheduling

**Example Test:**

```php
class Test_Gateway_Health extends WP_UnitTestCase {

    public function test_success_rate_calculation() {
        // Create test transactions
        $this->create_test_transaction('stripe', 'success');
        $this->create_test_transaction('stripe', 'success');
        $this->create_test_transaction('stripe', 'failed');

        // Calculate health
        $health = new WC_Payment_Monitor_Health();
        $result = $health->calculate_health('stripe');

        // Assert 66.67% success rate
        $this->assertEquals(66.67, $result['success_rate']);
    }

    public function test_alert_rate_limiting() {
        $alert = new WC_Payment_Monitor_Alerts();

        // Send first alert
        $sent1 = $alert->trigger_alert([...]);
        $this->assertTrue($sent1);

        // Try to send duplicate immediately
        $sent2 = $alert->trigger_alert([...]);
        $this->assertFalse($sent2); // Should be rate limited
    }
}
```

### 7.2 Integration Tests

**Test Scenarios:**

1. Complete payment flow (success)
2. Failed payment logging
3. Health metric calculation after transactions
4. Alert triggering when threshold crossed
5. Retry scheduling and execution
6. Dashboard data rendering

### 7.3 Manual Testing Checklist

```
[ ] Install plugin on fresh WordPress + WooCommerce
[ ] Connect payment gateway (Stripe test mode)
[ ] Process successful test payment
[ ] Process failed test payment (use test card: 4000000000000002)
[ ] Verify transaction logged correctly
[ ] Verify health metrics updated
[ ] Trigger alert by forcing multiple failures
[ ] Verify email alert received
[ ] Test retry scheduling
[ ] Test dashboard loads correctly
[ ] Test with 1000+ transactions (performance)
[ ] Test plugin conflicts (top 20 WooCommerce plugins)
[ ] Test on different hosting environments
```

---

## 8. DEPLOYMENT PLAN

### 8.1 Development Phases

**Phase 1: Foundation (Weeks 1-4)**

- Database schema implementation
- Core hooks and logging
- Basic health calculation
- Unit tests

**Phase 2: Features (Weeks 5-8)**

- Alert system
- Retry logic
- REST API endpoints
- Dashboard UI

**Phase 3: Polish (Weeks 9-10)**

- UI/UX refinements
- Performance optimization
- Documentation
- Integration tests

**Phase 4: Beta (Weeks 11-12)**

- Beta testing with 20-50 stores
- Bug fixes
- Performance tuning
- Prepare for launch

### 8.2 Launch Checklist

**Pre-Launch:**

```
[ ] Code review completed
[ ] Security audit passed
[ ] All tests passing (unit + integration)
[ ] Performance benchmarks met (<100ms overhead)
[ ] Documentation complete (user + developer)
[ ] Support system ready (docs, FAQ, ticket system)
[ ] Payment gateway testing (Stripe, PayPal, Square)
[ ] WordPress.org submission prepared
[ ] Marketing materials ready (landing page, videos)
[ ] Analytics/tracking implemented
```

**WordPress.org Submission:**

```
[ ] Plugin name available
[ ] Meets WordPress coding standards
[ ] Passes Plugin Check plugin
[ ] readme.txt formatted correctly
[ ] Screenshots prepared (1280x720px)
[ ] Banner images (772x250px, 1544x500px)
[ ] Icon images (128x128px, 256x256px)
[ ] SVN repository setup
```

### 8.3 Rollout Strategy

**Week 1-2: Soft Launch**

- WordPress.org release (free tier only)
- Limited announcement (existing audience)
- Monitor for critical bugs
- Gather initial feedback

**Week 3-4: Public Launch**

- Premium tiers available
- Full marketing campaign
- Press releases
- Community engagement

**Week 5-8: Growth**

- Content marketing
- Paid advertising
- Partnership outreach
- Feature iterations based on feedback

---

## 9. MONITORING & METRICS

### 9.1 Application Metrics

**Track:**

- Plugin installations (total, active)
- Premium conversions (free → paid)
- Churn rate
- Average revenue per user (ARPU)
- Customer lifetime value (LTV)

**Implementation:**

```php
// Anonymous usage tracking (opt-in)
function wcpm_send_usage_stats() {
    if (!get_option('wcpm_allow_tracking')) {
        return;
    }

    $stats = [
        'plugin_version' => WCPM_VERSION,
        'wp_version' => get_bloginfo('version'),
        'wc_version' => WC()->version,
        'php_version' => PHP_VERSION,
        'active_gateways' => count(WC()->payment_gateways->get_available_payment_gateways()),
        'total_orders_last_30_days' => $this->get_order_count(30),
        'license_tier' => get_option('wc_payment_monitor_license')['tier'],
    ];

    wp_remote_post('https://api.yoursite.com/v1/stats', [
        'body' => json_encode($stats),
        'headers' => ['Content-Type' => 'application/json'],
    ]);
}

// Schedule weekly
if (!wp_next_scheduled('wcpm_send_usage_stats')) {
    wp_schedule_event(time(), 'weekly', 'wcpm_send_usage_stats');
}
```

### 9.2 Performance Metrics

**Monitor:**

- Database query time (<50ms per query)
- Page load impact (<100ms overhead)
- Memory usage (<5MB)
- API response times (<200ms)

**Error Tracking:**

- Integrate Sentry or similar for error monitoring
- Track PHP errors, warnings, notices
- Monitor failed API calls to payment gateways

---

## 10. MAINTENANCE & SUPPORT

### 10.1 Update Schedule

**Monthly:**

- Bug fixes
- Minor feature improvements
- Security patches

**Quarterly:**

- Major feature releases
- Performance optimizations
- Gateway additions

### 10.2 Support Channels

**Free Tier:**

- WordPress.org support forums
- Documentation/FAQ
- Email support (48-hour response)

**Premium Tiers:**

- Priority email support (24-hour response)
- Live chat (Pro/Enterprise)
- Phone support (Enterprise only)

### 10.3 Documentation Requirements

**User Documentation:**

- Getting started guide
- Gateway setup tutorials
- Dashboard walkthrough
- Troubleshooting guide
- FAQ (50+ questions)

**Developer Documentation:**

- API reference
- Hooks and filters
- Extension development guide
- Code examples

---

## 11. SUCCESS CRITERIA

### 11.1 MVP Success Metrics (First 90 Days)

**Adoption:**

- ✅ 1,000+ plugin installations
- ✅ 50+ active premium customers
- ✅ 80%+ user retention after 30 days

**Technical:**

- ✅ 4.5+ star average rating (WordPress.org)
- ✅ <5 critical bugs reported
- ✅ <100ms performance impact
- ✅ 99.9% uptime

**Business:**

- ✅ $5,000+ MRR
- ✅ 5% free-to-paid conversion
- ✅ Positive unit economics (LTV > CAC)

### 11.2 Key Performance Indicators (KPIs)

**Leading Indicators:**

- Daily active installations
- Dashboard engagement (daily active users)
- Alert open rates
- Support ticket volume
- Feature request frequency

**Lagging Indicators:**

- Monthly recurring revenue (MRR)
- Customer lifetime value (LTV)
- Customer acquisition cost (CAC)
- Net promoter score (NPS)
- Churn rate

---

## 12. RISKS & MITIGATION

### 12.1 Technical Risks

| Risk                   | Impact   | Probability | Mitigation                                        |
| ---------------------- | -------- | ----------- | ------------------------------------------------- |
| Gateway API changes    | High     | Medium      | Version locking, fallback handling, monitoring    |
| Performance issues     | High     | Low         | Load testing, query optimization, caching         |
| Security vulnerability | Critical | Low         | Security audit, regular updates, bug bounty       |
| Plugin conflicts       | Medium   | High        | Extensive compatibility testing, defensive coding |

### 12.2 Business Risks

| Risk                               | Impact | Probability | Mitigation                                   |
| ---------------------------------- | ------ | ----------- | -------------------------------------------- |
| Low adoption                       | High   | Medium      | Free tier for viral growth, strong marketing |
| Payment gateway partnership issues | Medium | Low         | Work within public APIs, clear positioning   |
| Competitor emerges                 | Medium | Medium      | First-mover advantage, continuous innovation |
| Support overwhelm                  | Medium | Medium      | Excellent docs, community forums, chatbot    |

---

## 13. APPENDICES

### 13.1 Third-Party Dependencies

**Required:**

- WordPress 6.4+
- WooCommerce 8.0+
- PHP 7.4+
- MySQL 5.7+

**Optional:**

- Twilio (SMS alerts)
- Slack (webhook alerts)

### 13.2 Gateway Support Matrix

| Gateway              | MVP Support | Auth Method | API Docs                               |
| -------------------- | ----------- | ----------- | -------------------------------------- |
| Stripe               | ✅ Yes      | API Key     | https://stripe.com/docs/api            |
| PayPal               | ✅ Yes      | OAuth 2.0   | https://developer.paypal.com           |
| Square               | ✅ Yes      | OAuth 2.0   | https://developer.squareup.com         |
| WooCommerce Payments | ✅ Yes      | API Key     | WC native                              |
| Authorize.net        | 📅 Phase 2  | API Key     | https://developer.authorize.net        |
| Braintree            | 📅 Phase 2  | API Key     | https://developer.paypal.com/braintree |

### 13.3 Browser/Device Support

**Admin Dashboard:**

- Chrome 90+
- Firefox 88+
- Safari 14+
- Edge 90+

**Mobile responsive:** Yes (tablet and desktop focus)

---

## 14. GLOSSARY

**Gateway Health:** The success rate and availability status of a payment gateway over a specified time period.

**Success Rate:** Percentage of successful transactions out of total transactions (successful / total × 100).

**Alert Threshold:** The success rate percentage below which an alert is triggered (default: 85%).

**Retry Logic:** Automated system that attempts to reprocess failed payments at scheduled intervals.

**Rate Limiting:** Mechanism to prevent alert fatigue by limiting frequency of similar alerts.

**Transaction Monitoring:** Real-time tracking of all payment attempts through WooCommerce.

---

## 15. REVISION HISTORY

| Version | Date       | Author         | Changes                   |
| ------- | ---------- | -------------- | ------------------------- |
| 1.0     | 2026-01-08 | Technical Team | Initial MVP specification |

---

## 16. SIGN-OFF

**Technical Lead:** **********\_********** Date: ****\_\_****

**Product Owner:** **********\_********** Date: ****\_\_****

**QA Lead:** **********\_********** Date: ****\_\_****

---

**END OF DOCUMENT**

_This technical specification is a living document and will be updated as requirements evolve during development._
