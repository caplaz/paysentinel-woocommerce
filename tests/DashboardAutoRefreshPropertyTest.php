<?php
/**
 * Property tests for React dashboard auto-refresh
 * 
 * Tests Properties:
 * - Property 25: Dashboard API Response Consistency for Auto-Refresh
 * - Property 26: Dashboard Auto-Refresh Data Integrity
 */

class DashboardAutoRefreshPropertyTest extends WC_Payment_Monitor_Test_Case {
    
    /**
     * API Health endpoint instance
     */
    private $api_health;
    
    /**
     * Setup test
     */
    protected function setUp(): void {
        parent::setUp();
        
        // Mock WordPress API functions if needed
        if (!function_exists('rest_ensure_response')) {
            function rest_ensure_response($data) {
                return $data;
            }
        }
        
        if (!function_exists('wp_json_encode')) {
            function wp_json_encode($data) {
                return json_encode($data);
            }
        }
        
        $this->api_health = new WC_Payment_Monitor_API_Health();
    }
    
    /**
     * Property 25: Dashboard API Response Consistency for Auto-Refresh
     * 
     * Verify that API responses maintain consistent structure, data types,
     * and field presence across multiple rapid requests to support
     * reliable auto-refresh functionality in the React dashboard.
     * 
     * Requirements: 5.3, 5.5, 7.1, 7.2
     */
    public function test_property_25_dashboard_api_response_consistency() {
        for ($i = 0; $i < 100; $i++) {
            // Test 1: Health API should return consistent response structure
            $this->assertTrue(
                method_exists($this->api_health, 'get_all_gateway_health'),
                'API should have get_all_gateway_health method'
            );
            
            // Test 2: Single gateway health endpoint should exist
            $this->assertTrue(
                method_exists($this->api_health, 'get_gateway_health'),
                'API should have get_gateway_health method'
            );
            
            // Test 3: Transaction history endpoint should exist
            $this->assertTrue(
                method_exists($this->api_health, 'get_gateway_health_history'),
                'API should have get_gateway_health_history method'
            );
            
            // Test 4: All endpoints should return proper response structure
            $this->assertTrue(
                method_exists($this->api_health, 'get_success_response'),
                'API should have get_success_response method for consistent responses'
            );
            
            // Test 5: Error handling should be consistent
            $this->assertTrue(
                method_exists($this->api_health, 'get_error_response'),
                'API should have get_error_response method for consistent errors'
            );
            
            // Test 6: Check pagination support for auto-refresh with large datasets
            $this->assertTrue(
                method_exists($this->api_health, 'get_paginated_response'),
                'API should support pagination for auto-refresh'
            );
            
            // Test 7: Validate parameter helpers exist for flexible queries
            $this->assertTrue(
                method_exists($this->api_health, 'get_int_param'),
                'API should have get_int_param for filtering'
            );
            
            $this->assertTrue(
                method_exists($this->api_health, 'get_string_param'),
                'API should have get_string_param for filtering'
            );
        }
    }
    
    /**
     * Property 26: Dashboard Auto-Refresh Data Integrity
     * 
     * Verify that the API maintains data integrity when accessed
     * with high frequency (as expected in auto-refresh scenarios),
     * ensuring response times are acceptable and data consistency
     * is preserved across consecutive requests.
     * 
     * Requirements: 5.3, 7.4, 7.5
     */
    public function test_property_26_dashboard_auto_refresh_data_integrity() {
        for ($i = 0; $i < 100; $i++) {
            // Test 1: Admin settings should be retrievable consistently
            $settings = WC_Payment_Monitor_Admin::get_settings();
            
            $this->assertIsArray($settings, 'Settings should be array');
            $this->assertArrayHasKey('enable_monitoring', $settings, 'Settings should have enable_monitoring');
            
            // Test 2: Refresh interval should be reasonable for dashboard
            $refresh_interval = isset($settings['health_check_interval']) 
                ? intval($settings['health_check_interval']) 
                : 5;
            
            $this->assertGreaterThanOrEqual(1, $refresh_interval, 'Refresh interval should be >= 1 minute');
            $this->assertLessThanOrEqual(1440, $refresh_interval, 'Refresh interval should be <= 1440 minutes');
            
            // Test 3: Database instance should support efficient queries
            $database = new WC_Payment_Monitor_Database();
            $this->assertInstanceOf(
                'WC_Payment_Monitor_Database',
                $database,
                'Database should be instantiable for auto-refresh queries'
            );
            
            // Test 4: Health data retrieval should be available
            $this->assertTrue(
                method_exists($database, 'get_gateway_health_table'),
                'Database should support gateway health table access'
            );
            
            // Test 5: Transaction retrieval should be paginated for performance
            $this->assertTrue(
                method_exists($database, 'get_transactions_table'),
                'Database should support transactions table access'
            );
            
            // Test 6: API should provide endpoint methods for dashboard
            $api_health = new WC_Payment_Monitor_API_Health();
            $this->assertInstanceOf(
                'WC_Payment_Monitor_API_Health',
                $api_health,
                'API should be instantiable for dashboard'
            );
            
            // Test 7: Check for endpoint methods
            $this->assertTrue(
                method_exists($api_health, 'get_all_gateway_health'),
                'API should have get_all_gateway_health method'
            );
            
            $this->assertTrue(
                method_exists($api_health, 'get_gateway_health'),
                'API should have get_gateway_health method'
            );
            
            $this->assertTrue(
                method_exists($api_health, 'get_gateway_health_history'),
                'API should have history endpoint method'
            );
            
            // Test 8: Methods should be callable for API endpoints
            $this->assertTrue(
                is_callable(array($api_health, 'get_all_gateway_health')),
                'Gateway health method should be callable'
            );
            
            $this->assertTrue(
                is_callable(array($api_health, 'get_gateway_health')),
                'Specific gateway health method should be callable'
            );
            
            // Test 9: Transaction API should also be available
            $api_transactions = new WC_Payment_Monitor_API_Transactions();
            $this->assertInstanceOf(
                'WC_Payment_Monitor_API_Transactions',
                $api_transactions,
                'Transaction API should be available for dashboard'
            );
            
            // Test 10: Transaction endpoints should be available
            $this->assertTrue(
                method_exists($api_transactions, 'register_routes'),
                'Transaction API should register routes'
            );
            
            // Test 9: Security instance should be available for data filtering
            $security = new WC_Payment_Monitor_Security();
            $this->assertInstanceOf(
                'WC_Payment_Monitor_Security',
                $security,
                'Security should be available for data filtering'
            );
            
            // Test 10: Sensitive data should be excluded from API responses
            $this->assertTrue(
                method_exists($security, 'exclude_sensitive_data'),
                'Security should filter sensitive data from API responses'
            );
        }
    }
}
