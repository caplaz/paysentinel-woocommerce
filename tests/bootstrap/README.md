# Test Suite Documentation

## Overview

This directory contains comprehensive tests for the WooCommerce Payment Monitor plugin, including unit tests, integration tests, and property-based tests.

## Test Files

### Core Functionality Tests

- **DatabaseOperationsTest.php** - Tests database table creation, queries, and cleanup
- **TransactionLoggerTest.php** - Tests transaction logging functionality
- **TransactionLoggingPropertyTest.php** - Property-based tests for transaction logging
- **HealthCalculationTest.php** - Tests health metric calculations
- **HealthPropertyTest.php** - Property-based tests for health calculations
- **PaymentSystemIntegrationTest.php** - Integration tests for the complete payment flow

### Alert System Tests

- **AlertPropertyTest.php** - Property-based tests for alert generation
- **AlertSeverityLogicTest.php** - Tests for alert severity determination
- **PaymentAlertTest.php** - Tests for payment alert functionality

### Retry System Tests

- **RetryPropertyTest.php** - Property-based tests for retry logic
- **SmartRetryLogicTest.php** - Tests for smart retry functionality

### License & Premium Features Tests

- **LicenseGatingTest.php** - Tests license tier detection and feature gating
- **ProFeaturesIntegrationTest.php** - Integration tests for PRO plan features

### API Tests

- **APIPaginationPropertyTest.php** - Tests for API pagination
- **APIResponsePropertyTest.php** - Tests for API response formats

### Security & UI Tests

- **SecurityPropertyTest.php** - Tests for security features
- **AdminPagePropertyTest.php** - Tests for admin interface
- **DashboardAutoRefreshPropertyTest.php** - Tests for dashboard auto-refresh

### Diagnostic Tests

- **DiagnosticsTest.php** - Tests for diagnostic functionality
- **PluginStructureTest.php** - Tests for plugin structure and organization

## PRO Features Test Coverage

The `ProFeaturesIntegrationTest.php` suite specifically tests the three main PRO features:

### 1. Extended Analytics (30-day and 90-day periods)

Tests that extended analytics are:
- Available to PRO and Agency tiers
- Not available to Free and Starter tiers
- Return proper data structures
- Are properly stored in database

**Covered Test Cases:**
- `test_30day_analytics_requires_pro_tier()` - Verifies Free tier doesn't get 30-day analytics
- `test_90day_analytics_requires_pro_tier()` - Verifies PRO tier gets 90-day analytics
- `test_agency_tier_gets_extended_periods()` - Verifies Agency tier gets extended periods
- `test_starter_tier_no_extended_periods()` - Verifies Starter tier doesn't get extended periods
- `test_health_data_structure_for_extended_periods()` - Verifies data structure is correct
- `test_database_supports_extended_periods()` - Verifies database schema supports extended periods

### 2. Unlimited Gateways

Tests that gateway limits are:
- Enforced based on license tier
- Applied correctly (1 for Free, 3 for Starter, 999 for PRO/Agency)
- Respected in health calculations
- Properly displayed in UI (lock icons)

**Covered Test Cases:**
- `test_pro_tier_unlimited_gateways()` - Verifies PRO tier can monitor 10+ gateways
- `test_free_tier_one_gateway_limit()` - Verifies Free tier limited to 1 gateway
- `test_starter_tier_three_gateway_limit()` - Verifies Starter tier limited to 3 gateways

### 3. Extended Data Retention

Tests that data retention is:
- Tier-based (7/30/90 days)
- Applied in cleanup operations
- Configured correctly for each tier

**Covered Test Cases:**
- `test_data_retention_limits()` - Verifies retention constants are correct
- `test_license_tier_constants()` - Verifies all tier constants are correct

## Running Tests

### Prerequisites

Tests require WordPress test library to be installed:

```bash
# Using Docker (recommended)
make test

# Or locally
bash install-wp-tests.sh wordpress_test root '' localhost latest
phpunit
```

### Run All Tests

```bash
composer test
```

### Run Specific Test Suite

```bash
vendor/bin/phpunit tests/ProFeaturesIntegrationTest.php
vendor/bin/phpunit tests/LicenseGatingTest.php
vendor/bin/phpunit tests/HealthPropertyTest.php
```

### Run with Coverage

```bash
composer test-coverage
```

## Test Structure

### Base Test Case

All tests extend `WP_UnitTestCase` which provides:
- WordPress test environment
- Database transaction rollback
- Fixtures and factories
- WordPress core functions

### Property-Based Tests

Several tests use property-based testing to verify behavior across many inputs:
- **TransactionLoggingPropertyTest** - Tests logging with various transaction states
- **HealthPropertyTest** - Tests health calculations with various success rates
- **AlertPropertyTest** - Tests alert generation with various thresholds
- **RetryPropertyTest** - Tests retry logic with various failure patterns

### Integration Tests

Integration tests verify complete workflows:
- **PaymentSystemIntegrationTest** - Full payment processing flow
- **ProFeaturesIntegrationTest** - Complete PRO feature verification

## Adding New Tests

When adding tests for new features:

1. **Create test file** in `tests/` directory
2. **Extend WP_UnitTestCase** for WordPress integration
3. **Follow naming convention**: `{Feature}Test.php` or `{Feature}PropertyTest.php`
4. **Add docblocks** explaining what's being tested
5. **Group related tests** in the same test class
6. **Clean up** after tests (delete options, database records)

Example:

```php
<?php
/**
 * Tests for New Feature
 */
class NewFeatureTest extends WP_UnitTestCase {
	
	/**
	 * Test that feature works correctly
	 */
	public function test_feature_works() {
		// Arrange
		$input = 'test';
		
		// Act
		$result = do_something( $input );
		
		// Assert
		$this->assertEquals( 'expected', $result );
	}
	
	/**
	 * Clean up after tests
	 */
	public function tearDown(): void {
		parent::tearDown();
		// Clean up any test data
	}
}
```

## Continuous Integration

Tests run automatically on every push via GitHub Actions:
- Multiple PHP versions (7.4, 8.0, 8.1, 8.2)
- Multiple WordPress versions (latest, previous)
- Code coverage reporting
- Style checks (PHPCS)
- Static analysis (PHPStan)

## Test Coverage Goals

- **Unit Tests**: >80% code coverage
- **Integration Tests**: All critical user flows
- **Property Tests**: Edge cases and boundary conditions
- **Regression Tests**: All fixed bugs

## Troubleshooting Tests

### WordPress Test Library Not Found

```bash
bash install-wp-tests.sh wordpress_test root '' localhost latest
```

### Database Connection Issues

Check `phpunit.xml` for database credentials and ensure MySQL is running.

### Memory Limit Issues

Increase PHP memory limit:

```bash
php -d memory_limit=512M vendor/bin/phpunit
```

## Resources

- [WordPress Plugin Unit Tests](https://make.wordpress.org/cli/handbook/plugin-unit-tests/)
- [PHPUnit Documentation](https://phpunit.de/documentation.html)
- [WooCommerce Testing Guide](https://github.com/woocommerce/woocommerce/wiki/How-to-set-up-WooCommerce-development-environment)
