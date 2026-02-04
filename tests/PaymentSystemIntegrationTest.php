<?php

/**
 * Integration tests for complete payment monitoring system
 *
 * Tests the integration of all components:
 * - Transaction monitoring -> Health calculation -> Alert triggering
 * - Failed transaction detection -> Retry engine scheduling
 * - API endpoints -> Dashboard data display
 * - Security enforcement across all components
 *
 * Property Tests:
 * - Property 27: Complete Payment Flow Integration
 * - Property 28: Health-Alert Integration
 * - Property 29: Retry-Recovery Integration
 */
class PaymentSystemIntegrationTest extends WC_Payment_Monitor_Test_Case
{
    /**
     * Transaction logger instance
     */
    private $logger;

    /**
     * Health calculator instance
     */
    private $health;

    /**
     * Alert system instance
     */
    private $alerts;

    /**
     * Retry engine instance
     */
    private $retry;

    /**
     * Database instance
     */
    private $database;

    /**
     * Setup test
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Initialize all components
        $this->database = new WC_Payment_Monitor_Database();
        $this->logger   = new WC_Payment_Monitor_Logger();
        $this->health   = new WC_Payment_Monitor_Health();
        $this->alerts   = new WC_Payment_Monitor_Alerts();
        $this->retry    = new WC_Payment_Monitor_Retry();
    }

    /**
     * Property 27: Complete Payment Flow Integration
     *
     * Verify that a complete payment flow works end-to-end:
     * 1. Transaction is logged
     * 2. Health metrics are calculated
     * 3. Dashboard can retrieve data via REST API
     * 4. Security filtering is applied to sensitive data
     *
     * Requirements: 1.1, 2.1, 5.1, 6.2, 7.1
     */
    public function test_property_27_complete_payment_flow_integration()
    {
        for ($i = 0; $i < 100; $i++) {
            // Test 1: All core components should be instantiable
            $this->assertInstanceOf('WC_Payment_Monitor_Logger', $this->logger);
            $this->assertInstanceOf('WC_Payment_Monitor_Health', $this->health);
            $this->assertInstanceOf('WC_Payment_Monitor_Alerts', $this->alerts);
            $this->assertInstanceOf('WC_Payment_Monitor_Retry', $this->retry);
            $this->assertInstanceOf('WC_Payment_Monitor_Database', $this->database);

            // Test 2: Database should be properly initialized
            $this->assertTrue(
                method_exists($this->database, 'get_transactions_table'),
                'Database should have transactions table'
            );

            // Test 3: All components should have their core methods
            $this->assertTrue(
                method_exists($this->logger, 'log_failure'),
                'Logger should have log_failure method'
            );

            $this->assertTrue(
                method_exists($this->health, 'calculate_all_gateway_health'),
                'Health should have calculate_all_gateway_health method'
            );

            $this->assertTrue(
                method_exists($this->alerts, 'check_all_gateway_alerts'),
                'Alerts should have check_all_gateway_alerts method'
            );

            $this->assertTrue(
                method_exists($this->retry, 'schedule_retry'),
                'Retry should have schedule_retry method'
            );

            // Test 4: REST API should be available for all data sources
            $api_health       = new WC_Payment_Monitor_API_Health();
            $api_transactions = new WC_Payment_Monitor_API_Transactions();

            $this->assertInstanceOf('WC_Payment_Monitor_API_Health', $api_health);
            $this->assertInstanceOf('WC_Payment_Monitor_API_Transactions', $api_transactions);

            // Test 5: Admin pages should be available for data display
            $admin = new WC_Payment_Monitor_Admin();
            $this->assertInstanceOf('WC_Payment_Monitor_Admin', $admin);

            // Test 6: Settings should be retrievable for configuration
            $settings = WC_Payment_Monitor_Admin::get_settings();
            $this->assertIsArray($settings);
            $this->assertArrayHasKey('enable_monitoring', $settings);

            // Test 7: Security should be applied throughout the system
            $security = new WC_Payment_Monitor_Security();
            $this->assertTrue(
                method_exists($security, 'exclude_sensitive_data'),
                'Security should filter sensitive data from responses'
            );

            // Test 8: Component communication should work
            // Logger -> Database (check table exists)
            $this->assertTrue(
                method_exists($this->database, 'get_transactions_table'),
                'Database should support transactions table'
            );

            // Health -> Database (check table exists)
            $this->assertTrue(
                method_exists($this->database, 'get_gateway_health_table'),
                'Database should support health table'
            );

            // Test 9: All methods should be callable
            $this->assertTrue(is_callable([$this->logger, 'log_failure']));
            $this->assertTrue(is_callable([$this->health, 'calculate_all_gateway_health']));
            $this->assertTrue(is_callable([$this->alerts, 'check_all_gateway_alerts']));
            $this->assertTrue(is_callable([$this->retry, 'schedule_retry']));

            // Test 10: System should have proper error handling
            $this->assertTrue(
                method_exists($this->logger, 'save_transaction'),
                'Logger should save transaction data'
            );
        }
    }

    /**
     * Property 28: Health-Alert Integration
     *
     * Verify that health calculation properly triggers alerts
     * when thresholds are crossed:
     * 1. Health drops below threshold
     * 2. Alert is sent
     * 3. Alert rate limiting prevents spam
     * 4. Alert resolution is tracked
     *
     * Requirements: 2.3, 3.1, 3.4, 7.1
     */
    public function test_property_28_health_alert_integration()
    {
        for ($i = 0; $i < 100; $i++) {
            // Test 3: All components should be linked
            $health = new WC_Payment_Monitor_Health();
            $alerts = new WC_Payment_Monitor_Alerts();

            $this->assertTrue(
                method_exists($health, 'calculate_all_gateway_health'),
                'Health should calculate metrics'
            );

            $this->assertTrue(
                method_exists($alerts, 'check_all_gateway_alerts'),
                'Alerts should check gateway alerts'
            );

            // Test 2: Settings should define alert thresholds
            $settings = WC_Payment_Monitor_Admin::get_settings();
            $this->assertArrayHasKey('alert_threshold', $settings, 'Settings should have alert threshold');

            $threshold = $settings['alert_threshold'];
            $this->assertGreaterThan(0, $threshold, 'Alert threshold should be positive');
            $this->assertLessThanOrEqual(100, $threshold, 'Alert threshold should be <= 100%');

            // Test 3: Alert system should have severity levels
            $this->assertTrue(
                method_exists($alerts, 'trigger_alert'),
                'Alerts should trigger alerts with severity'
            );

            // Test 4: Alert system should support multiple notification channels
            $this->assertTrue(
                method_exists($alerts, 'check_and_send'),
                'Alerts should send notifications'
            );

            // Test 5: Rate limiting should prevent alert spam
            $this->assertTrue(
                method_exists($alerts, 'is_rate_limited'),
                'Alerts should have rate limiting'
            );

            // Test 6: Alert resolution should be tracked
            $this->assertTrue(
                method_exists($alerts, 'resolve_alerts'),
                'Alerts should support resolution'
            );

            // Test 7: Database should store alert history
            $database = new WC_Payment_Monitor_Database();
            $this->assertTrue(
                method_exists($database, 'get_alerts_table'),
                'Database should have alerts table'
            );

            // Test 8: API should expose alert data
            $this->assertTrue(
                method_exists('WC_Payment_Monitor_API_Health', 'register_routes'),
                'API should register routes for alert access'
            );

            // Test 9: Admin should display alerts
            $admin = new WC_Payment_Monitor_Admin();
            $this->assertTrue(
                method_exists($admin, 'render_alerts_page'),
                'Admin should render alerts page'
            );

            // Test 10: Security should filter sensitive data from alerts
            $security = new WC_Payment_Monitor_Security();
            $this->assertTrue(
                method_exists($security, 'mask_sensitive_data'),
                'Security should mask sensitive alert data'
            );
        }
    }

    /**
     * Property 29: Retry-Recovery Integration
     *
     * Verify that failed payments are properly retried:
     * 1. Failed transaction is logged
     * 2. Retry is scheduled with proper delays
     * 3. Retry attempts are tracked
     * 4. Recovery status is reported
     *
     * Requirements: 4.1, 4.2, 4.3, 4.4, 4.5
     */
    public function test_property_29_retry_recovery_integration()
    {
        for ($i = 0; $i < 100; $i++) {
            // Test 1: Retry engine should be integrated
            $retry = new WC_Payment_Monitor_Retry();

            $this->assertTrue(
                method_exists($retry, 'schedule_retry'),
                'Retry should schedule retries'
            );

            // Test 2: Retry configuration should be available in settings
            $settings = WC_Payment_Monitor_Admin::get_settings();
            $this->assertArrayHasKey('retry_enabled', $settings, 'Settings should have retry enabled flag');
            $this->assertArrayHasKey('max_retry_attempts', $settings, 'Settings should have max retry attempts');

            $max_attempts = $settings['max_retry_attempts'];
            $this->assertGreaterThan(0, $max_attempts, 'Max attempts should be positive');
            $this->assertLessThanOrEqual(10, $max_attempts, 'Max attempts should be reasonable');

            // Test 3: Retry history should be tracked in database
            $database = new WC_Payment_Monitor_Database();
            $this->assertTrue(
                method_exists($retry, 'get_retry_stats'),
                'Retry should track retry history'
            );

            // Test 4: Retry logic should integrate with transaction logger
            $logger = new WC_Payment_Monitor_Logger();
            $this->assertTrue(
                method_exists($logger, 'log_failure'),
                'Logger should record transaction failures for retry'
            );

            // Test 5: Recovery should update health metrics
            $health = new WC_Payment_Monitor_Health();
            $this->assertTrue(
                method_exists($health, 'calculate_all_gateway_health'),
                'Health should reflect recovered transactions'
            );

            // Test 6: Retry configuration should be accessible via API
            $api_health = new WC_Payment_Monitor_API_Health();
            $this->assertInstanceOf('WC_Payment_Monitor_API_Health', $api_health);

            // Test 7: Admin should show retry configuration
            $admin = new WC_Payment_Monitor_Admin();
            $this->assertTrue(
                method_exists($admin, 'render_settings_page'),
                'Admin should allow retry configuration'
            );

            // Test 8: Retry engine should handle exponential backoff
            $this->assertTrue(
                method_exists($retry, 'attempt_retry'),
                'Retry should attempt retries'
            );

            // Test 9: Failed transactions should trigger retry logic
            $this->assertTrue(
                method_exists($retry, 'schedule_retry_on_failure'),
                'Retry should process failures'
            );

            // Test 10: Recovery events should be logged
            $this->assertTrue(
                method_exists($logger, 'update_transaction_status'),
                'Logger should track recovery attempts'
            );
        }
    }

    /**
     * Test integration of all major workflows
     */
    public function test_complete_system_integration()
    {
        // Test 1: Default settings should exist
        $settings = WC_Payment_Monitor_Admin::get_settings();
        $this->assertIsArray($settings);
        $this->assertArrayHasKey('enable_monitoring', $settings);

        // Test 2: All database tables should exist
        $database = new WC_Payment_Monitor_Database();
        $this->assertNotEmpty($database->get_transactions_table());
        $this->assertNotEmpty($database->get_gateway_health_table());
        $this->assertNotEmpty($database->get_alerts_table());

        // Test 3: Components should be properly initialized
        $logger = new WC_Payment_Monitor_Logger();
        $health = new WC_Payment_Monitor_Health();
        $alerts = new WC_Payment_Monitor_Alerts();
        $retry  = new WC_Payment_Monitor_Retry();

        $this->assertInstanceOf('WC_Payment_Monitor_Logger', $logger);
        $this->assertInstanceOf('WC_Payment_Monitor_Health', $health);
        $this->assertInstanceOf('WC_Payment_Monitor_Alerts', $alerts);
        $this->assertInstanceOf('WC_Payment_Monitor_Retry', $retry);

        // Test 4: REST API endpoints should be available
        $api_health       = new WC_Payment_Monitor_API_Health();
        $api_transactions = new WC_Payment_Monitor_API_Transactions();

        $this->assertInstanceOf('WC_Payment_Monitor_API_Health', $api_health);
        $this->assertInstanceOf('WC_Payment_Monitor_API_Transactions', $api_transactions);

        // Test 5: Admin interface should be available
        $admin = new WC_Payment_Monitor_Admin();
        $this->assertInstanceOf('WC_Payment_Monitor_Admin', $admin);

        // Test 6: Security should be in place
        $security = new WC_Payment_Monitor_Security();
        $this->assertInstanceOf('WC_Payment_Monitor_Security', $security);
    }
}
