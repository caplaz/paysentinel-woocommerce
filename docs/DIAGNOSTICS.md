# Payment Diagnostics & Testing Tools

Complete guide for diagnostic tools, failure simulation, and testing workflows.

## Overview

The WooCommerce Payment Monitor includes comprehensive diagnostic and testing tools:

- **Failure Simulator** - Create controlled payment failures for testing
- **System Diagnostics** - Check database, gateways, and system health
- **Recovery Tools** - Force retries, reset metrics, clean orphaned data
- **Testing Workflows** - End-to-end testing procedures

## Quick Start

### Access Diagnostic Tools

Navigate to: **Payment Monitor → Diagnostic Tools**

### Simulate a Payment Failure

1. Select failure scenario (e.g., "Card Declined")
2. Choose gateway (Stripe, PayPal, etc.)
3. Click "Simulate Payment Failure"
4. Clear test data when done

### Run System Diagnostics

1. Click "Run Full Diagnostics"
2. Review results for issues
3. Take recovery actions as needed

## Failure Scenarios

The simulator provides 12 realistic failure scenarios:

- **Card Declined** - Card was declined by issuer
- **Insufficient Funds** - Not enough balance
- **Expired Card** - Payment method expired
- **Incorrect CVC** - Wrong security code
- **Processing Error** - Generic processing failure
- **Gateway Timeout** - Connection timeout
- **Network Error** - Network connectivity issue
- **Rate Limit Exceeded** - Too many API requests
- **Fraud Detected** - Flagged as fraudulent
- **Invalid Account** - Account closed/invalid
- **Gateway Misconfigured** - Authentication failed
- **Currency Not Supported** - Unsupported currency

## Admin Interface

### System Diagnostics Section

**Run Full Diagnostics** - Complete system health check

- Database health and table status
- Gateway connectivity
- Recent failures
- Stuck orders
- Retry queue status

**Check Gateway Status** - Test gateway connectivity
**Recalculate Health Metrics** - Force metric recalculation

### Failure Simulator Section

**Simulate Payment Failure** - Create test failures

- Select scenario
- Choose gateway
- Set count (1-50)

**Clear All Simulated Failures** - Remove test data

- Deletes test orders
- Cleans transaction records

### Maintenance Tools Section

**Clean Orphaned Records** - Remove orphaned transactions
**Archive Old Transactions** - Archive 90+ day old records
**Reset All Health Metrics** - Clear and recalculate metrics

## REST API Endpoints

### Simulator

```
GET  /wp-json/wc-payment-monitor/v1/simulator/scenarios
POST /wp-json/wc-payment-monitor/v1/simulator/simulate
GET  /wp-json/wc-payment-monitor/v1/simulator/stats
POST /wp-json/wc-payment-monitor/v1/simulator/clear
```

### Diagnostics

```
GET  /wp-json/wc-payment-monitor/v1/diagnostics/full
GET  /wp-json/wc-payment-monitor/v1/diagnostics/database
GET  /wp-json/wc-payment-monitor/v1/diagnostics/gateways
GET  /wp-json/wc-payment-monitor/v1/diagnostics/failures/recent
GET  /wp-json/wc-payment-monitor/v1/diagnostics/failures/analyze
```

### Recovery

```
POST /wp-json/wc-payment-monitor/v1/diagnostics/retry/:order_id
POST /wp-json/wc-payment-monitor/v1/diagnostics/health/reset
POST /wp-json/wc-payment-monitor/v1/diagnostics/health/recalculate
POST /wp-json/wc-payment-monitor/v1/diagnostics/gateway/test/:gateway_id
POST /wp-json/wc-payment-monitor/v1/diagnostics/cleanup/orphaned
POST /wp-json/wc-payment-monitor/v1/diagnostics/cleanup/archive
```

## PHP API Usage

### Simulate Single Failure

```php
$simulator = new WC_Payment_Monitor_Failure_Simulator();
$result = $simulator->create_test_order_with_failure('card_declined', 'stripe');

if ($result['success']) {
    echo "Created order #{$result['order_id']}";
}
```

### Generate Bulk Failures

```php
$simulator = new WC_Payment_Monitor_Failure_Simulator();
$result = $simulator->generate_bulk_failures(10, 'stripe');

echo "Created {$result['success']} test orders";
```

### Run Full Diagnostics

```php
$diagnostics = new WC_Payment_Monitor_Diagnostics();
$results = $diagnostics->run_full_diagnostics();

echo "Database Status: {$results['database']['status']}";
echo "Gateway Status: {$results['gateways']['status']}";
```

### Analyze Failures

```php
$diagnostics = new WC_Payment_Monitor_Diagnostics();
$analysis = $diagnostics->analyze_payment_failures(7); // Last 7 days

echo "Total Failures: {$analysis['total_failures']}";
foreach ($analysis['by_gateway'] as $gateway) {
    echo "{$gateway->gateway_id}: {$gateway->count} failures";
}
```

### Force Retry

```php
$diagnostics = new WC_Payment_Monitor_Diagnostics();
$result = $diagnostics->force_retry_order(12345);

if ($result['success']) {
    echo "Retry successful!";
}
```

### Clean Orphaned Records

```php
$diagnostics = new WC_Payment_Monitor_Diagnostics();
$result = $diagnostics->clean_orphaned_records();

echo "Deleted {$result['deleted']} orphaned records";
```

## Testing Workflows

### Workflow 1: Test Alert System

1. Generate 10 card decline failures
2. Recalculate health metrics
3. Verify alerts were triggered
4. Check alert content
5. Clear test data

```php
$simulator = new WC_Payment_Monitor_Failure_Simulator();
$diagnostics = new WC_Payment_Monitor_Diagnostics();

// Generate failures
$simulator->generate_bulk_failures(10, 'stripe', ['card_declined']);

// Recalculate metrics
$diagnostics->recalculate_health_metrics();

// Check alerts (via alerts API)
// ...

// Clean up
$simulator->clear_simulated_failures();
```

### Workflow 2: Test Retry Mechanism

1. Create one timeout failure
2. Force manual retry
3. Verify retry was attempted
4. Check transaction history
5. Confirm order status

```php
$simulator = new WC_Payment_Monitor_Failure_Simulator();
$diagnostics = new WC_Payment_Monitor_Diagnostics();

// Create failure
$result = $simulator->create_test_order_with_failure('gateway_timeout', 'stripe');
$order_id = $result['order_id'];

// Force retry
$retry_result = $diagnostics->force_retry_order($order_id);

// Verify
echo $retry_result['success'] ? 'Retry succeeded' : 'Retry failed';
```

### Workflow 3: Performance Testing

1. Generate 100 test failures
2. Run full diagnostics
3. Analyze failure patterns
4. Check system performance
5. Archive/clean up data

```php
$simulator = new WC_Payment_Monitor_Failure_Simulator();
$diagnostics = new WC_Payment_Monitor_Diagnostics();

// Generate bulk data
$simulator->generate_bulk_failures(100, 'stripe');

// Analyze
$analysis = $diagnostics->analyze_payment_failures(1);
$system = $diagnostics->run_full_diagnostics();

// Clean up
$diagnostics->archive_old_transactions(0); // Archive immediately
$simulator->clear_simulated_failures();
```

## Security & Safety

- All endpoints require `manage_woocommerce` capability
- Simulated orders clearly marked with metadata
- Test mode shows warnings in UI
- Confirmation required for destructive actions
- Bulk operations limited to prevent abuse
- Separate from production data

## Best Practices

### For Development/Testing

✓ Always use Test Mode on development sites
✓ Set appropriate failure rates (10-20%)
✓ Clear simulated data regularly
✓ Test with different failure scenarios
✓ Monitor system performance during bulk operations

### For Production Use

✗ Never enable Test Mode on production
✓ Use diagnostic tools to investigate real issues
✓ Archive old data regularly
✓ Test gateway connectivity periodically
✓ Clean orphaned records as part of maintenance

### For Troubleshooting

1. Start with full diagnostics for overview
2. Check recent failures for patterns
3. Test gateway connectivity for connection issues
4. Use failure analysis to identify trends
5. Force manual retries for urgent recovery

## Performance Considerations

- **Simulation**: Creates actual WooCommerce orders (lightweight)
- **Diagnostics**: Read-only queries (minimal impact)
- **Bulk Operations**: Limited to 100 at a time
- **Archive Operations**: Can be resource-intensive on large datasets
- **Health Recalculation**: Processes full transaction history

## Examples

See `/examples/diagnostic-tools-examples.php` for complete code examples including:

- Single failure simulation
- Bulk failure generation
- Full diagnostics workflow
- Failure analysis
- Gateway testing
- Database maintenance
- Complete testing workflow

## Troubleshooting

### Issue: Simulator Not Working

Check if test mode is enabled:

```php
$simulator = new WC_Payment_Monitor_Failure_Simulator();
echo $simulator->is_test_mode_enabled() ? 'Enabled' : 'Disabled';
```

### Issue: No Recent Failures Shown

Increase the limit:

```php
$diagnostics = new WC_Payment_Monitor_Diagnostics();
$failures = $diagnostics->get_recent_failures(50);
```

### Issue: Health Metrics Not Updating

Force recalculation:

```php
$diagnostics = new WC_Payment_Monitor_Diagnostics();
$diagnostics->recalculate_health_metrics();
```

### Issue: Can't Access Diagnostic Tools Page

- Must have `manage_woocommerce` capability
- Verify user is admin
- Check plugin is activated

## Support

For issues or questions:

1. Check diagnostic output first
2. Review recent failures for error messages
3. Test gateway connectivity
4. Contact support with diagnostic results
