<?php
/**
 * Property-based test for health calculation accuracy
 * 
 * **Feature: payment-monitor, Property 3: Health Calculation Accuracy**
 * **Validates: Requirements 2.1, 2.4**
 */

require_once __DIR__ . '/includes/class-wc-payment-monitor-test-case.php';
require_once __DIR__ . '/../includes/class-wc-payment-monitor-database.php';
require_once __DIR__ . '/../includes/class-wc-payment-monitor-logger.php';
require_once __DIR__ . '/../includes/class-wc-payment-monitor-health.php';

class Test_Health_Calculation_Accuracy_Property extends WC_Payment_Monitor_Test_Case {
    
    private $database;
    private $logger;
    private $health;
    
    public function set_up() {
        parent::set_up();
        
        // Initialize components
        $this->database = new WC_Payment_Monitor_Database();
        $this->database->create_tables();
        $this->logger = new WC_Payment_Monitor_Logger();
        $this->health = new WC_Payment_Monitor_Health();
        
        // Mock WordPress functions
        $this->mock_wordpress_time_functions();
        $this->mock_woocommerce_functions();
    }
    
    /**
     * Property Test: Health Calculation Accuracy
     * 
     * For any set of transaction data and time period (1hour, 24hour, 7day), 
     * the calculated success rate should equal (successful transactions / total transactions) × 100,
     * and historical health data should be stored for retrieval.
     * 
     * **Validates: Requirements 2.1, 2.4**
     */
    public function test_health_calculation_accuracy_property() {
        $iterations = 100; // Minimum 100 iterations for property-based testing
        $failures = [];
        
        for ($i = 0; $i < $iterations; $i++) {
            try {
                $this->run_single_health_calculation_test($i);
            } catch (Exception $e) {
                $failures[] = "Iteration $i: " . $e->getMessage();
            }
            
            // Clean up for next iteration
            $this->clean_up_database();
            $this->database->create_tables();
        }
        
        if (!empty($failures)) {
            $this->fail("Property test failed in " . count($failures) . " iterations:\n" . implode("\n", array_slice($failures, 0, 5)));
        }
        
        $this->assertTrue(true, "Health calculation accuracy property holds for all $iterations iterations");
    }
    
    /**
     * Run a single iteration of the health calculation accuracy test
     */
    private function run_single_health_calculation_test($iteration) {
        // Generate random test data
        $gateway_id = $this->generate_random_gateway_id();
        $transaction_sets = $this->generate_random_transaction_sets($gateway_id);
        
        // Insert transaction data into database
        $this->insert_transaction_data($transaction_sets);
        
        // Test each time period
        $periods = ['1hour' => 3600, '24hour' => 86400, '7day' => 604800];
        
        foreach ($periods as $period_name => $period_seconds) {
            $this->verify_health_calculation_for_period($gateway_id, $period_name, $period_seconds, $transaction_sets, $iteration);
        }
    }
    
    /**
     * Verify health calculation accuracy for a specific period
     */
    private function verify_health_calculation_for_period($gateway_id, $period_name, $period_seconds, $transaction_sets, $iteration) {
        // Calculate expected values based on our test data
        $expected_stats = $this->calculate_expected_stats($transaction_sets, $period_seconds);
        
        // Run the health calculation
        $health_data = $this->health->calculate_health($gateway_id);
        
        // Verify the calculation is correct
        $this->assertArrayHasKey($period_name, $health_data, "Missing health data for period $period_name in iteration $iteration");
        
        $period_health = $health_data[$period_name];
        
        // Verify basic structure
        $this->assertEquals($gateway_id, $period_health['gateway_id'], "Incorrect gateway_id in iteration $iteration");
        $this->assertEquals($period_name, $period_health['period'], "Incorrect period in iteration $iteration");
        
        // Verify transaction counts
        $this->assertEquals($expected_stats['total_transactions'], $period_health['total_transactions'], 
            "Total transactions mismatch for $period_name in iteration $iteration. Expected: {$expected_stats['total_transactions']}, Got: {$period_health['total_transactions']}");
        
        $this->assertEquals($expected_stats['successful_transactions'], $period_health['successful_transactions'], 
            "Successful transactions mismatch for $period_name in iteration $iteration. Expected: {$expected_stats['successful_transactions']}, Got: {$period_health['successful_transactions']}");
        
        $this->assertEquals($expected_stats['failed_transactions'], $period_health['failed_transactions'], 
            "Failed transactions mismatch for $period_name in iteration $iteration. Expected: {$expected_stats['failed_transactions']}, Got: {$period_health['failed_transactions']}");
        
        // Verify success rate calculation (core property)
        $expected_success_rate = $expected_stats['total_transactions'] > 0 
            ? round(($expected_stats['successful_transactions'] / $expected_stats['total_transactions']) * 100, 2)
            : 0.00;
        
        $this->assertEquals($expected_success_rate, $period_health['success_rate'], 
            "Success rate calculation incorrect for $period_name in iteration $iteration. Expected: $expected_success_rate%, Got: {$period_health['success_rate']}%");
        
        // Verify data storage (Requirements 2.4)
        $stored_health = $this->health->get_health_status($gateway_id, $period_name);
        $this->assertNotNull($stored_health, "Health data not stored for $period_name in iteration $iteration");
        $this->assertEquals($expected_success_rate, floatval($stored_health->success_rate), 
            "Stored success rate incorrect for $period_name in iteration $iteration");
    }
    
    /**
     * Generate random gateway ID for testing
     */
    private function generate_random_gateway_id() {
        $gateways = ['stripe', 'paypal', 'square', 'authorize_net', 'braintree'];
        return $gateways[array_rand($gateways)];
    }
    
    /**
     * Generate random transaction sets for different time periods
     */
    private function generate_random_transaction_sets($gateway_id) {
        $current_time = time();
        $transaction_sets = [];
        
        // Generate transactions for different time periods
        $periods = [
            '1hour' => ['start' => $current_time - 3600, 'end' => $current_time],
            '24hour' => ['start' => $current_time - 86400, 'end' => $current_time - 3600],
            '7day' => ['start' => $current_time - 604800, 'end' => $current_time - 86400],
            'older' => ['start' => $current_time - 1209600, 'end' => $current_time - 604800] // Older than 7 days
        ];
        
        foreach ($periods as $period => $time_range) {
            $transaction_count = rand(0, 50); // Random number of transactions
            $transactions = [];
            
            for ($i = 0; $i < $transaction_count; $i++) {
                $transactions[] = $this->generate_random_transaction($gateway_id, $time_range['start'], $time_range['end']);
            }
            
            $transaction_sets[$period] = $transactions;
        }
        
        return $transaction_sets;
    }
    
    /**
     * Generate a single random transaction
     */
    private function generate_random_transaction($gateway_id, $start_time, $end_time) {
        $statuses = ['success', 'failed', 'pending', 'retry'];
        $status = $statuses[array_rand($statuses)];
        
        // Weight success more heavily for realistic data
        if (rand(1, 100) <= 70) {
            $status = 'success';
        } elseif (rand(1, 100) <= 85) {
            $status = 'failed';
        }
        
        $created_at = rand($start_time, $end_time);
        
        return [
            'order_id' => rand(1000, 99999),
            'gateway_id' => $gateway_id,
            'transaction_id' => 'txn_' . rand(100000, 999999),
            'amount' => round(rand(1000, 50000) / 100, 2), // $10.00 to $500.00
            'currency' => 'USD',
            'status' => $status,
            'failure_reason' => $status === 'failed' ? 'Random test failure' : null,
            'failure_code' => $status === 'failed' ? 'test_error_' . rand(1, 10) : null,
            'retry_count' => $status === 'retry' ? rand(1, 3) : 0,
            'customer_email' => 'test' . rand(1, 1000) . '@example.com',
            'customer_ip' => rand(1, 255) . '.' . rand(1, 255) . '.' . rand(1, 255) . '.' . rand(1, 255),
            'created_at' => date('Y-m-d H:i:s', $created_at),
            'updated_at' => null
        ];
    }
    
    /**
     * Insert transaction data into database
     */
    private function insert_transaction_data($transaction_sets) {
        global $wpdb;
        $table_name = $this->database->get_transactions_table();
        
        foreach ($transaction_sets as $period => $transactions) {
            foreach ($transactions as $transaction) {
                $wpdb->insert($table_name, $transaction, [
                    '%d', '%s', '%s', '%f', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s'
                ]);
            }
        }
    }
    
    /**
     * Calculate expected statistics for verification
     */
    private function calculate_expected_stats($transaction_sets, $period_seconds) {
        $cutoff_time = time() - $period_seconds;
        $stats = [
            'total_transactions' => 0,
            'successful_transactions' => 0,
            'failed_transactions' => 0
        ];
        
        foreach ($transaction_sets as $period => $transactions) {
            foreach ($transactions as $transaction) {
                $transaction_time = strtotime($transaction['created_at']);
                
                // Only count transactions within the period
                if ($transaction_time >= $cutoff_time) {
                    $stats['total_transactions']++;
                    
                    if ($transaction['status'] === 'success') {
                        $stats['successful_transactions']++;
                    } elseif ($transaction['status'] === 'failed') {
                        $stats['failed_transactions']++;
                    }
                }
            }
        }
        
        return $stats;
    }
    
    /**
     * Mock WordPress time functions
     */
    private function mock_wordpress_time_functions() {
        if (!function_exists('current_time')) {
            function current_time($type) {
                return date('Y-m-d H:i:s');
            }
        }
    }
    
    /**
     * Mock WooCommerce functions
     */
    private function mock_woocommerce_functions() {
        if (!function_exists('wc_get_order')) {
            function wc_get_order($order_id) {
                return null; // Not needed for this test
            }
        }
        
        if (!function_exists('wc_get_order_notes')) {
            function wc_get_order_notes($args) {
                return []; // Not needed for this test
            }
        }
    }
    
    /**
     * Test edge case: Empty data handling
     * 
     * Verifies that health calculation returns zero values for periods with no transactions
     */
    public function test_empty_data_handling_property() {
        $iterations = 20; // Fewer iterations for edge case
        
        for ($i = 0; $i < $iterations; $i++) {
            $gateway_id = $this->generate_random_gateway_id();
            
            // Calculate health with no transaction data
            $health_data = $this->health->calculate_health($gateway_id);
            
            foreach ($health_data as $period => $data) {
                $this->assertEquals(0, $data['total_transactions'], "Empty data: total_transactions should be 0 for $period in iteration $i");
                $this->assertEquals(0, $data['successful_transactions'], "Empty data: successful_transactions should be 0 for $period in iteration $i");
                $this->assertEquals(0, $data['failed_transactions'], "Empty data: failed_transactions should be 0 for $period in iteration $i");
                $this->assertEquals(0.00, $data['success_rate'], "Empty data: success_rate should be 0.00 for $period in iteration $i");
            }
            
            // Clean up for next iteration
            $this->clean_up_database();
            $this->database->create_tables();
        }
        
        $this->assertTrue(true, "Empty data handling property holds for all $iterations iterations");
    }
    
    /**
     * Test edge case: Single transaction scenarios
     * 
     * Verifies calculation accuracy when there's only one transaction
     */
    public function test_single_transaction_scenarios_property() {
        $iterations = 50;
        
        for ($i = 0; $i < $iterations; $i++) {
            $gateway_id = $this->generate_random_gateway_id();
            $status = rand(0, 1) ? 'success' : 'failed';
            
            // Create single transaction
            $transaction = $this->generate_random_transaction($gateway_id, time() - 1800, time()); // Within last 30 minutes
            $transaction['status'] = $status;
            
            $this->insert_transaction_data(['recent' => [$transaction]]);
            
            // Calculate health
            $health_data = $this->health->calculate_health($gateway_id);
            
            // Verify 1-hour period (should include our transaction)
            $hour_data = $health_data['1hour'];
            $this->assertEquals(1, $hour_data['total_transactions'], "Single transaction: total should be 1 in iteration $i");
            
            if ($status === 'success') {
                $this->assertEquals(1, $hour_data['successful_transactions'], "Single success: successful should be 1 in iteration $i");
                $this->assertEquals(0, $hour_data['failed_transactions'], "Single success: failed should be 0 in iteration $i");
                $this->assertEquals(100.00, $hour_data['success_rate'], "Single success: rate should be 100% in iteration $i");
            } else {
                $this->assertEquals(0, $hour_data['successful_transactions'], "Single failure: successful should be 0 in iteration $i");
                $this->assertEquals(1, $hour_data['failed_transactions'], "Single failure: failed should be 1 in iteration $i");
                $this->assertEquals(0.00, $hour_data['success_rate'], "Single failure: rate should be 0% in iteration $i");
            }
            
            // Clean up for next iteration
            $this->clean_up_database();
            $this->database->create_tables();
        }
        
        $this->assertTrue(true, "Single transaction scenarios property holds for all $iterations iterations");
    }
}