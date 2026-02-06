# PRO Features Quick Reference

Quick reference guide for developers working with PRO features.

## License Tier Detection

```php
$license = new WC_Payment_Monitor_License();
$tier = $license->get_license_tier();
// Returns: 'free', 'starter', 'pro', or 'agency'
```

## License Tier Limits

### Gateway Limits
```php
WC_Payment_Monitor_License::GATEWAY_LIMITS['free']    // 1
WC_Payment_Monitor_License::GATEWAY_LIMITS['starter'] // 3
WC_Payment_Monitor_License::GATEWAY_LIMITS['pro']     // 999
WC_Payment_Monitor_License::GATEWAY_LIMITS['agency']  // 999
```

### Retention Limits (days)
```php
WC_Payment_Monitor_License::RETENTION_LIMITS['free']    // 7
WC_Payment_Monitor_License::RETENTION_LIMITS['starter'] // 30
WC_Payment_Monitor_License::RETENTION_LIMITS['pro']     // 90
WC_Payment_Monitor_License::RETENTION_LIMITS['agency']  // 90
```

## Analytics Periods

### Available to All Tiers
```php
'1hour'  => 3600    // 1 hour in seconds
'24hour' => 86400   // 24 hours in seconds
'7day'   => 604800  // 7 days in seconds
```

### Available to PRO/Agency Only
```php
'30day' => 2592000  // 30 days in seconds (PRO/Agency)
'90day' => 7776000  // 90 days in seconds (PRO/Agency)
```

## Checking Feature Access

### Check if Extended Period is Available
```php
$license = new WC_Payment_Monitor_License();
$tier = $license->get_license_tier();

if ( in_array( $tier, array( 'pro', 'agency' ), true ) ) {
    // User has access to 30-day and 90-day analytics
}
```

### Check Gateway Limit
```php
$license = new WC_Payment_Monitor_License();
$tier = $license->get_license_tier();
$limit = WC_Payment_Monitor_License::GATEWAY_LIMITS[ $tier ];

if ( count( $gateways ) > $limit ) {
    // User has exceeded their gateway limit
}
```

### Check Retention Period
```php
$license = new WC_Payment_Monitor_License();
$tier = $license->get_license_tier();
$retention = WC_Payment_Monitor_License::RETENTION_LIMITS[ $tier ];

// Use $retention in cleanup operations
$database->cleanup_old_transactions( $retention );
```

## Common Patterns

### Gating Extended Analytics in Health Calculation
```php
public function calculate_health( $gateway_id ) {
    $license = new WC_Payment_Monitor_License();
    $tier = $license->get_license_tier();
    
    foreach ( self::PERIODS as $period => $seconds ) {
        // Skip extended periods for non-PRO tiers
        if ( ( '30day' === $period || '90day' === $period ) 
             && ! in_array( $tier, array( 'pro', 'agency' ), true ) ) {
            continue;
        }
        
        // Calculate health for this period...
    }
}
```

### Gating Extended Analytics in API
```php
public function get_gateway_health( $request ) {
    $period = $request->get_param( 'period' ); // '30d' or '90d'
    
    $license = new WC_Payment_Monitor_License();
    $tier = $license->get_license_tier();
    
    // Check if user has access to this period
    if ( ( '30d' === $period || '90d' === $period ) 
         && ! in_array( $tier, array( 'pro', 'agency' ), true ) ) {
        return new WP_Error(
            'rest_forbidden_period',
            __( 'Extended analytics history is only available in PRO and Agency plans.', 'wc-payment-monitor' ),
            array( 'status' => 403 )
        );
    }
    
    // Return analytics...
}
```

### Limiting Gateways
```php
private function get_active_gateways() {
    $license = new WC_Payment_Monitor_License();
    $tier = $license->get_license_tier();
    $limit = WC_Payment_Monitor_License::GATEWAY_LIMITS[ $tier ];
    
    $enabled_gateways = get_option( 'wc_payment_monitor_settings' )['enabled_gateways'];
    
    // Apply tier-based limit
    return array_slice( $enabled_gateways, 0, $limit );
}
```

### Applying Retention in Cleanup
```php
public function run_daily_cleanup() {
    $license = new WC_Payment_Monitor_License();
    $tier = $license->get_license_tier();
    $retention_days = WC_Payment_Monitor_License::RETENTION_LIMITS[ $tier ];
    
    $database = new WC_Payment_Monitor_Database();
    $database->cleanup_old_transactions( $retention_days );
    $database->cleanup_old_alerts( 30 ); // Alerts kept for 30 days on all tiers
}
```

## API Response Examples

### Successful Response (PRO/Agency accessing 30-day analytics)
```json
{
  "success": true,
  "data": {
    "gateway_id": "stripe",
    "period": "30day",
    "success_rate": 98.5,
    "total_transactions": 1234,
    "successful_transactions": 1215,
    "failed_transactions": 19
  }
}
```

### Error Response (Free tier accessing 30-day analytics)
```json
{
  "code": "rest_forbidden_period",
  "message": "Extended analytics history is only available in PRO and Agency plans.",
  "data": {
    "status": 403
  }
}
```

### Gateway Locked Response
```json
{
  "gateway_id": "gateway_4",
  "is_locked": true,
  "health_percentage": 0,
  "connectivity_status": "locked",
  "connectivity_message": "Unlock PRO for more gateways"
}
```

## Database Schema

### Gateway Health Table
```sql
CREATE TABLE wp_payment_monitor_gateway_health (
    id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
    gateway_id VARCHAR(50) NOT NULL,
    period ENUM('1hour', '24hour', '7day', '30day', '90day') NOT NULL,
    total_transactions INT(11) UNSIGNED DEFAULT 0,
    successful_transactions INT(11) UNSIGNED DEFAULT 0,
    failed_transactions INT(11) UNSIGNED DEFAULT 0,
    success_rate DECIMAL(5,2) DEFAULT 0.00,
    calculated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY idx_gateway_period (gateway_id, period)
);
```

Note: The `30day` and `90day` periods are only populated for PRO and Agency tiers.

## Testing

### Test License Tier
```php
// Set Free tier
update_option( 'wc_payment_monitor_license_status', 'invalid' );

// Set PRO tier
update_option( 'wc_payment_monitor_license_status', 'valid' );
update_option( 'wc_payment_monitor_license_data', array( 'plan' => 'pro' ) );

// Set Agency tier
update_option( 'wc_payment_monitor_license_status', 'valid' );
update_option( 'wc_payment_monitor_license_data', array( 'plan' => 'agency' ) );
```

### Run PRO Features Tests
```bash
# Run all tests
composer test

# Run specific test suite
vendor/bin/phpunit tests/ProFeaturesIntegrationTest.php

# Run specific test
vendor/bin/phpunit --filter test_30day_analytics_requires_pro_tier
```

## Troubleshooting

### Extended Analytics Not Showing
1. Check license tier: `$license->get_license_tier()`
2. Verify license status: `$license->get_license_status()` should be 'valid'
3. Check license data: `$license->get_license_data()` should have 'plan' => 'pro' or 'agency'
4. Verify health calculation ran: Check `wp_payment_monitor_gateway_health` table for `30day` and `90day` rows

### Gateway Limit Not Working
1. Check license tier: Verify correct tier is detected
2. Check gateway count: Count enabled gateways in settings
3. Check limit constant: Verify `GATEWAY_LIMITS` array has correct values
4. Check array_slice: Verify `get_active_gateways()` is using correct limit

### Retention Not Applied
1. Check cron is running: `wp_next_scheduled( 'wc_payment_monitor_daily_cleanup' )`
2. Check license tier: Verify correct tier is detected in cleanup
3. Check cleanup method: Verify `cleanup_old_transactions()` is called with correct days
4. Check database: Query transactions table to see oldest records

## Documentation Links

- **User Guide**: `docs/PRO_FEATURES.md` - Complete user documentation
- **Test Guide**: `tests/README.md` - Test suite documentation
- **Implementation**: `docs/IMPLEMENTATION_SUMMARY.md` - Technical implementation details
- **API Spec**: `docs/API-SPECIFICATION.md` - REST API documentation

## Support

For questions about PRO features:
- Code implementation: See inline comments in source files
- Testing: See `tests/ProFeaturesIntegrationTest.php`
- User features: See `docs/PRO_FEATURES.md`
- Technical details: See `docs/IMPLEMENTATION_SUMMARY.md`
