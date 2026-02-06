# PRO Plan Features

This document outlines the features available in different license tiers of WooCommerce Payment Monitor.

## License Tiers

### Free Tier
The free tier provides basic payment monitoring with the following features:
- ✅ **1 Gateway**: Monitor one payment gateway
- ✅ **Basic Analytics**: 1-hour, 24-hour, and 7-day success rate tracking
- ✅ **7-Day Data Retention**: Transaction and health data kept for 7 days
- ✅ **Email Alerts**: Local email notifications when issues are detected
- ✅ **Global Alert Thresholds**: Set one threshold for all gateways

### Starter Tier ($49/year)
Perfect for small stores with a few payment methods:
- ✅ **3 Gateways**: Monitor up to 3 payment gateways
- ✅ **Basic Analytics**: 1-hour, 24-hour, and 7-day success rate tracking
- ✅ **30-Day Data Retention**: Transaction and health data kept for 30 days
- ✅ **Server-Side Email Alerts**: More reliable delivery
- ✅ **SMS Alerts**: 100 SMS notifications per month
- ✅ **Priority Support**: Faster response times

### PRO Tier ($99/year)
Designed for growing businesses with multiple payment methods:
- ✅ **Unlimited Gateways**: Monitor all your payment gateways (up to 999)
- ✅ **Extended Analytics**: 30-day and 90-day success rate tracking in addition to standard periods
- ✅ **90-Day Data Retention**: Transaction and health data kept for 90 days
- ✅ **Server-Side Email Alerts**: More reliable delivery
- ✅ **SMS Alerts**: 500 SMS notifications per month
- ✅ **Slack Integration**: Send alerts to your team's Slack workspace
- ✅ **Per-Gateway Configuration**: Set different thresholds and alert channels for each gateway
- ✅ **Priority Support**: Faster response times

### Agency Tier ($249/year)
For agencies managing multiple client sites:
- ✅ **Unlimited Gateways**: Monitor all payment gateways across all sites
- ✅ **Extended Analytics**: 30-day and 90-day success rate tracking
- ✅ **90-Day Data Retention**: Transaction and health data kept for 90 days
- ✅ **Multi-Site Support**: Manage multiple WordPress installations
- ✅ **SMS Alerts**: 1000 SMS notifications per month (shared across sites)
- ✅ **Slack Integration**: Send alerts to your team's Slack workspace
- ✅ **Per-Gateway Configuration**: Set different thresholds and alert channels for each gateway
- ✅ **Priority Support**: Faster response times

## Feature Implementation Details

### Extended Analytics (PRO/Agency Only)

#### What It Does
The PRO and Agency tiers include 30-day and 90-day analytics periods, allowing you to:
- Track long-term payment gateway health trends
- Identify seasonal patterns in payment failures
- Make data-driven decisions about gateway performance
- Compare performance across longer time periods

#### How It Works
The system automatically calculates and stores health metrics for extended periods:
- **30-day analytics**: Success rates, transaction counts, and failure patterns over the last 30 days
- **90-day analytics**: Comprehensive quarterly view of gateway performance

#### API Access
Extended analytics are available via REST API:
```
GET /wp-json/wc-payment-monitor/v1/health/gateways?period=30d
GET /wp-json/wc-payment-monitor/v1/health/gateways?period=90d
GET /wp-json/wc-payment-monitor/v1/health/gateways/{gateway_id}?period=30d
GET /wp-json/wc-payment-monitor/v1/health/gateways/{gateway_id}?period=90d
```

For Free and Starter tiers, these endpoints return a 403 error with a message indicating PRO or Agency tier is required.

### Unlimited Gateways (PRO/Agency Only)

#### What It Does
Monitor all your payment gateways without restrictions:
- **Free tier**: Limited to 1 gateway
- **Starter tier**: Limited to 3 gateways
- **PRO/Agency tiers**: Monitor up to 999 gateways (effectively unlimited)

#### How It Works
The gateway limit is enforced at multiple levels:
1. **Health Calculation**: Only calculates health for gateways within your tier's limit
2. **Dashboard Display**: Shows lock icons for gateways beyond your limit
3. **API Responses**: Returns `is_locked: true` for gateways beyond your limit

#### Unlocking More Gateways
To monitor more gateways:
1. Upgrade to a higher tier through your account
2. The system automatically detects your new tier
3. All gateways become available for monitoring immediately

### Extended Data Retention (Tier-Based)

#### What It Does
Retain historical transaction and health data for longer periods based on your tier:
- **Free tier**: 7 days of data retention
- **Starter tier**: 30 days of data retention
- **PRO tier**: 90 days of data retention
- **Agency tier**: 90 days of data retention

#### How It Works
The system runs a daily cleanup cron job that:
1. Checks your current license tier
2. Determines the retention period for your tier
3. Deletes transaction and health data older than the retention period
4. Optimizes database tables for performance

#### Why It Matters
Longer data retention allows you to:
- Investigate issues that occurred weeks or months ago
- Track long-term performance trends
- Generate quarterly reports
- Maintain compliance with record-keeping requirements

## Technical Implementation

### License Gating

All PRO features are gated at the code level:

```php
// In class-wc-payment-monitor-health.php
public function calculate_health( $gateway_id ) {
    $license = new WC_Payment_Monitor_License();
    $tier = $license->get_license_tier();
    
    foreach ( self::PERIODS as $period => $seconds ) {
        // Gate extended periods behind PRO tier
        if ( ( '30day' === $period || '90day' === $period ) 
             && ! in_array( $tier, array( 'pro', 'agency' ), true ) ) {
            continue; // Skip 30/90 day for non-PRO tiers
        }
        
        // Calculate and store health...
    }
}
```

### Database Schema

The database schema supports all periods including extended periods:

```sql
CREATE TABLE wp_payment_monitor_gateway_health (
    ...
    period ENUM('1hour', '24hour', '7day', '30day', '90day') NOT NULL,
    ...
);
```

### API Gating

REST API endpoints check license tier before returning extended analytics:

```php
// In class-wc-payment-monitor-api-health.php
if ( ( '30day' === $backend_period || '90day' === $backend_period ) 
     && ! in_array( $tier, array( 'pro', 'agency' ), true ) ) {
    return $this->get_error_response(
        'rest_forbidden_period',
        __( 'Extended analytics history is only available in PRO and Agency plans.', 'wc-payment-monitor' ),
        403
    );
}
```

## Upgrading

### How to Upgrade

1. Visit [paysentinel.caplaz.com](https://paysentinel.caplaz.com) to upgrade your plan
2. Enter your new license key in Settings → License
3. The system automatically detects your new tier
4. All PRO features become available immediately

### After Upgrading

After upgrading to PRO or Agency:
- **Extended Analytics**: Calculated on next health calculation cycle (every 5 minutes)
- **Unlimited Gateways**: All configured gateways become available immediately
- **Extended Retention**: Applied on next daily cleanup (keeps data longer going forward)

Note: Historical data beyond your previous retention period cannot be recovered, but all future data will be retained according to your new tier.

## Support

For questions about PRO features or upgrading:
- Email: support@caplaz.com
- Documentation: [docs.paysentinel.caplaz.com](https://docs.paysentinel.caplaz.com)
- License Management: [paysentinel.caplaz.com](https://paysentinel.caplaz.com)
