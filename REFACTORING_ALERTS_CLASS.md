# Alert System Refactoring

## Overview
The `WC_Payment_Monitor_Alerts` class has been refactored from a monolithic 1485-line class into four focused, maintainable classes following the Single Responsibility Principle.

## New Class Structure

### 1. WC_Payment_Monitor_Alert_Checker
**Location:** `includes/class-wc-payment-monitor-alert-checker.php`
**Size:** ~450 lines
**Responsibility:** Health checking and alert triggering logic

**Key Methods:**
- `check_all_gateway_alerts()` - Check alerts for all active gateways
- `check_gateway_alerts()` - Check alerts for specific gateway
- `check_gateway_connectivity_alert()` - Monitor gateway connectivity
- `check_immediate_transaction_alert()` - Handle immediate payment failures
- `trigger_alert()` - Main alert orchestrator
- `is_rate_limited()` - Prevent alert spam
- `resolve_alerts()` - Mark alerts as resolved
- `calculate_severity()` - Determine alert severity
- `should_trigger_alert()` - Apply threshold logic

**Dependencies:**
- WC_Payment_Monitor_Database
- WC_Payment_Monitor_Health
- WC_Payment_Monitor_Gateway_Manager
- WC_Payment_Monitor_Alert_Notifier

### 2. WC_Payment_Monitor_Alert_Notifier
**Location:** `includes/class-wc-payment-monitor-alert-notifier.php`
**Size:** ~485 lines
**Responsibility:** Notification delivery through various channels

**Key Methods:**
- `send_notifications()` - Main notification dispatcher
- `send_email_notification()` - Local email delivery (free tier)
- `send_to_api()` - API-based delivery (premium tiers)
- `get_alert_channels_for_gateway()` - Determine notification channels
- `is_channel_available()` - Check channel availability
- `test_sms_configuration()` - Test SMS setup
- `test_slack_configuration()` - Test Slack integration

**Supported Channels:**
- Email (local for free, API for premium)
- SMS (premium - Starter+)
- Slack (premium - Pro+)

**Dependencies:**
- WC_Payment_Monitor_Alert_Template_Manager
- WC_Payment_Monitor_Database
- WC_Payment_Monitor_License

### 3. WC_Payment_Monitor_Alert_Template_Manager
**Location:** `includes/class-wc-payment-monitor-alert-template-manager.php`
**Size:** ~320 lines
**Responsibility:** Message formatting for different channels

**Key Methods:**
- `create_alert_message()` - Generate text message
- `create_email_template()` - Generate HTML email
- `create_sms_message()` - Format SMS content
- `create_slack_payload()` - Create Slack message payload
- `get_gateway_name()` - Get friendly gateway name
- `format_period_name()` - Format time period
- `get_severity_color()` - Get color code for severity

**Template Features:**
- HTML email with responsive design
- Severity-based color coding
- Recommended action items
- Links to dashboard
- Slack rich formatting with buttons

**Dependencies:**
- WC_Payment_Monitor_Gateway_Manager

### 4. WC_Payment_Monitor_Alerts (Main Class)
**Location:** `includes/class-wc-payment-monitor-alerts.php`
**Size:** ~340 lines (reduced from 1485)
**Responsibility:** Orchestration and coordination

**Key Methods:**
- Constructor - Initialize handler classes
- `init_hooks()` - Register WordPress hooks
- Database query methods:
  - `get_alerts_by_gateway()`
  - `get_recent_alerts()`
  - `get_alert_stats()`
- License helpers:
  - `is_premium_feature_available()`
  - `get_license_tier()`
  - `has_feature()`
  - `validate_license()`
  - `get_license_status()`
- Delegation methods (forward to handlers):
  - `check_all_gateway_alerts()` → Checker
  - `trigger_alert()` → Checker
  - `test_sms_configuration()` → Notifier
  - `test_slack_configuration()` → Notifier

## Backward Compatibility

All public methods from the original class are preserved, ensuring no breaking changes:
- All WordPress hooks work identically
- External code calling the Alerts class continues to function
- Method signatures unchanged
- Return values unchanged

## Data Flow

```
1. WordPress Hook Triggered
   ↓
2. Main Alerts Class receives hook
   ↓
3. Delegates to Alert_Checker
   ↓
4. Checker evaluates health data
   ↓
5. If threshold breached:
   a. Checker saves alert to database
   b. Checker calls Notifier
      ↓
   c. Notifier determines channels
   d. Notifier calls Template_Manager for formatting
   e. Notifier sends to channels (email/SMS/Slack)
      ↓
6. Action hooks fired for extensibility
```

## Benefits

### Maintainability
- **Single Responsibility:** Each class has one clear purpose
- **Smaller Files:** Easier to navigate and understand
- **Clear Dependencies:** Explicit constructor injection

### Testability
- **Mockable Dependencies:** Can test each class in isolation
- **Focused Tests:** Test specific functionality without side effects
- **Less Setup:** Smaller classes need less test boilerplate

### Extensibility
- **Easy to Add Channels:** New notification channels only need Notifier changes
- **Easy to Add Alert Types:** New alert logic only needs Checker changes
- **Easy to Add Formats:** New message formats only need Template_Manager changes

### Readability
- **Clear Separation:** Easy to find where specific logic lives
- **Less Cognitive Load:** Each file focuses on one concern
- **Better Documentation:** Focused PHPDoc for each class

## Class Loading Order

The classes must be loaded in the correct order due to dependencies:

```php
// In wc-payment-monitor.php load_dependencies():
require_once 'includes/class-wc-payment-monitor-alert-template-manager.php';  // No dependencies
require_once 'includes/class-wc-payment-monitor-alert-notifier.php';          // Depends on Template Manager
require_once 'includes/class-wc-payment-monitor-alert-checker.php';           // Depends on Notifier
require_once 'includes/class-wc-payment-monitor-alerts.php';                  // Depends on all handlers
```

## Migration Notes

### For Developers
No changes required! The refactored code maintains full backward compatibility.

### For Testing
Existing tests should continue to pass. New tests can now:
- Test Alert_Checker in isolation (mocking Notifier)
- Test Alert_Notifier in isolation (mocking Template_Manager)
- Test Template_Manager in isolation (no external dependencies)

### For Future Development
When adding new features:
- **New alert types:** Add logic to Alert_Checker
- **New notification channels:** Add logic to Alert_Notifier
- **New message formats:** Add logic to Alert_Template_Manager
- **New database queries:** Add to main Alerts class

## Code Quality Metrics

**Before Refactoring:**
- Single file: 1485 lines
- Multiple responsibilities: health checking, notifications, templates, database
- Hard to test: many private methods, tight coupling
- Difficult to extend: changes affect entire class

**After Refactoring:**
- Four focused files: 340 + 450 + 485 + 320 = 1595 lines total
- Single responsibility per class
- Easy to test: mockable dependencies
- Easy to extend: changes isolated to specific classes
- ~110 additional lines for better structure and documentation

## Testing Strategy

```php
// Example: Testing Alert_Checker in isolation
$mock_database = $this->createMock(WC_Payment_Monitor_Database::class);
$mock_health = $this->createMock(WC_Payment_Monitor_Health::class);
$mock_gateway_manager = $this->createMock(WC_Payment_Monitor_Gateway_Manager::class);
$mock_notifier = $this->createMock(WC_Payment_Monitor_Alert_Notifier::class);

$checker = new WC_Payment_Monitor_Alert_Checker(
    $mock_database,
    $mock_health,
    $mock_gateway_manager,
    $mock_notifier
);

// Test alert triggering logic without actually sending notifications
$mock_notifier->expects($this->once())
    ->method('send_notifications');

$checker->trigger_alert($test_alert_data);
```

## Future Enhancements

With this refactored structure, these enhancements become easier:

1. **Additional Notification Channels:**
   - Discord
   - Microsoft Teams
   - Webhooks
   - Push notifications

2. **Advanced Alert Types:**
   - Predictive alerts (ML-based)
   - Trend-based alerts
   - Custom threshold alerts per gateway

3. **Template Improvements:**
   - Customizable email templates
   - Localization support
   - Brand-specific styling

4. **Enhanced Testing:**
   - Unit tests for each class
   - Integration tests for the full flow
   - Mock API responses

## WordPress Coding Standards

All classes follow WordPress coding standards:
- ✅ PHPDoc comments for all methods
- ✅ Proper indentation (tabs)
- ✅ Naming conventions (snake_case for functions)
- ✅ Security: prepared SQL queries, escaping output
- ✅ Internationalization: `__()` for all strings
- ✅ WordPress APIs: `current_time()`, `wp_mail()`, `wpdb`

## Security Considerations

- ✅ All database queries use `wpdb->prepare()`
- ✅ Rate limiting prevents alert spam
- ✅ License validation before sending notifications
- ✅ Input sanitization in all public methods
- ✅ Output escaping in email templates
- ✅ HMAC authentication for API requests

## Performance Impact

**Negligible:**
- Constructor overhead: ~4 object instantiations (microseconds)
- Method delegation: ~1 additional function call per operation
- Memory: ~4 additional objects (minimal overhead)
- Database queries: unchanged
- API calls: unchanged

The benefits of maintainability far outweigh the minimal performance cost.
