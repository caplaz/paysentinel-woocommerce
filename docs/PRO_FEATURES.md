# PRO Plan Features Documentation

This document describes the PRO tier features implemented for the WooCommerce Payment Monitor plugin.

## Overview

The PRO plan unlocks advanced analytics, unlimited gateway monitoring, and extended data retention for power users who need deep insights into their payment processing health.

## Feature 1: Advanced Analytics

### Description
PRO users get access to comprehensive analytics capabilities that go beyond basic health monitoring.

### Key Features
- **Comparative Analytics**: View health metrics across multiple time periods (1h, 24h, 7d, 30d, 90d)
- **Trend Analysis**: Automatic trend detection comparing different time periods
- **Failure Pattern Analysis**: Deep dive into failure reasons, hourly distribution, and daily trends
- **Gateway Comparison**: Side-by-side comparison of all monitored gateways
- **Extended Metrics**: Historical data visualization and advanced reporting

### API Endpoints

#### Get Comparative Analytics
```
GET /wp-json/wc-payment-monitor/v1/analytics/comparative/{gateway_id}
```

Returns period-by-period health metrics with trend analysis.

**Response:**
```json
{
  "success": true,
  "data": {
    "gateway_id": "stripe",
    "periods": {
      "1hour": { "success_rate": 98.5, ... },
      "24hour": { "success_rate": 97.2, ... },
      "7day": { "success_rate": 96.8, ... },
      "30day": { "success_rate": 95.1, ... },
      "90day": { "success_rate": 94.7, ... }
    },
    "trends": {
      "24h_vs_7d": {
        "success_rate_change": 0.4,
        "direction": "improving"
      }
    }
  }
}
```

#### Get Failure Pattern Analysis
```
GET /wp-json/wc-payment-monitor/v1/analytics/failure-patterns/{gateway_id}?days=30
```

Returns detailed failure analysis including top reasons, hourly distribution, and daily trends.

#### Get Advanced Metrics Summary
```
GET /wp-json/wc-payment-monitor/v1/analytics/metrics-summary
```

Returns comprehensive metrics for all monitored gateways.

#### Get Extended History
```
GET /wp-json/wc-payment-monitor/v1/analytics/extended-history/{gateway_id}?days=90
```

Returns up to 90 days of historical data (PRO tier only).

#### Get Gateway Comparison
```
GET /wp-json/wc-payment-monitor/v1/analytics/gateway-comparison
```

Returns comparative analysis across all gateways with rankings.

### Implementation Details

**Classes:**
- `WC_Payment_Monitor_Analytics_Pro` - Core analytics logic
- `WC_Payment_Monitor_API_Analytics_Pro` - REST API endpoints

**License Check:**
All PRO analytics methods check `is_pro_analytics_available()` which returns true only for 'pro' and 'agency' tiers.

## Feature 2: Unlimited Gateways

### Description
PRO users can monitor unlimited payment gateways (up to 999) compared to 1 gateway for free users.

### Implementation

**Gateway Limits** (defined in `WC_Payment_Monitor_License`):
```php
public const GATEWAY_LIMITS = array(
    'free'    => 1,
    'starter' => 3,
    'pro'     => 999,  // Effectively unlimited
    'agency'  => 999,
);
```

**Enforcement Points:**
1. `WC_Payment_Monitor_Health::get_active_gateways()` - Limits monitored gateways
2. `WC_Payment_Monitor_API_Health::get_all_gateway_health()` - API responses include `is_locked` flag
3. Gateway health calculations only run for non-locked gateways

### UI Behavior
Gateways beyond the tier limit show:
- `is_locked: true`
- Zero metrics (success_rate: 0, transaction_count: 0)
- Connectivity message: "Unlock PRO for more gateways"

## Feature 3: 90-Day History

### Description
PRO users retain transaction and health data for 90 days compared to 7 days for free users.

### Implementation

**Retention Limits** (defined in `WC_Payment_Monitor_License`):
```php
public const RETENTION_LIMITS = array(
    'free'    => 7,
    'starter' => 30,
    'pro'     => 90,   // 90 days
    'agency'  => 90,
);
```

**Cleanup Schedule:**
Daily cleanup task runs via WordPress cron (`wc_payment_monitor_daily_cleanup`) and:
1. Gets current license tier
2. Retrieves retention limit for that tier
3. Deletes transactions older than retention limit
4. Cleans up old alerts, health records, and connectivity checks

**Methods:**
- `WC_Payment_Monitor_Database::cleanup_old_transactions($days)`
- `WC_Payment_Monitor_Database::cleanup_old_alerts($days)`
- `WC_Payment_Monitor_Health::cleanup_old_health_data($days)`
- `WC_Payment_Monitor_Gateway_Connectivity::cleanup_old_checks($days)`

### Health Period Availability

PRO users also get access to 30-day and 90-day health calculation periods:

```php
// In WC_Payment_Monitor_Health::calculate_health()
if ( ( '30day' === $period || '90day' === $period ) && 
     ! in_array( $tier, array( 'pro', 'agency' ), true ) ) {
    continue; // Skip for non-PRO users
}
```

**API Gating:**
```php
// In WC_Payment_Monitor_API_Health::get_all_gateway_health()
if ( ( '30day' === $backend_period || '90day' === $backend_period ) && 
     ! in_array( $tier, array( 'pro', 'agency' ), true ) ) {
    return error_response(
        'rest_forbidden_period',
        'Extended analytics history is only available in PRO and Agency plans.',
        403
    );
}
```

## Testing

### Test Files
- `tests/ProAnalyticsTest.php` - Tests for PRO analytics features
- `tests/ProAnalyticsAPITest.php` - Tests for PRO API endpoints
- `tests/LicenseGatingTest.php` - Existing tests for tier-based gating

### Running Tests
```bash
# Run all PRO feature tests
phpunit tests/ProAnalyticsTest.php
phpunit tests/ProAnalyticsAPITest.php

# Run all license gating tests
phpunit tests/LicenseGatingTest.php
```

## Upgrade Path

### Free to PRO Upgrade Flow
1. User purchases PRO license key
2. User enters license key in Settings → License
3. License validation occurs
4. `get_license_tier()` returns 'pro'
5. PRO features immediately unlock:
   - Advanced analytics endpoints become accessible
   - Additional gateways can be monitored
   - 30-day and 90-day health periods appear
   - Extended history data becomes available

### No Data Migration Needed
- Existing health data remains intact
- New periods (30d, 90d) start calculating immediately
- Historical data accumulates up to 90-day retention limit

## Code Quality

All new code follows:
- WordPress coding standards
- PHPDoc documentation
- Proper license tier checking
- Error handling with meaningful messages
- Backward compatibility with existing features

## Files Modified/Created

### New Files
- `includes/class-wc-payment-monitor-analytics-pro.php`
- `includes/class-wc-payment-monitor-api-analytics-pro.php`
- `tests/ProAnalyticsTest.php`
- `tests/ProAnalyticsAPITest.php`
- `docs/PRO_FEATURES.md` (this file)

### Modified Files
- `wc-payment-monitor.php` - Added PRO class loading and API initialization

### Existing Files (Already Implemented)
- `includes/class-wc-payment-monitor-license.php` - Already has tier constants
- `includes/class-wc-payment-monitor-health.php` - Already gates extended periods
- `includes/class-wc-payment-monitor-api-health.php` - Already gates API access
- `includes/class-wc-payment-monitor-database.php` - Already has cleanup methods
- `wc-payment-monitor.php` - Already runs daily cleanup with tier-based retention

## Summary

The PRO plan implementation is minimal and surgical:
- Extended existing architecture rather than rebuilding
- Added new analytics classes for PRO-specific features
- Leveraged existing license tier checks and constants
- Maintained backward compatibility with free tier
- Comprehensive test coverage for all PRO features
