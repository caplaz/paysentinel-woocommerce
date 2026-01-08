<?php
/**
 * Unit tests for WC_Payment_Monitor_Logger class
 * 
 * Tests transaction logging functionality
 * Tests WooCommerce hook integration
 * Tests database insertion methods
 * Requirements: 1.1, 1.2, 1.3
 */

require_once __DIR__ . '/includes/class-wc-payment-monitor-test-case.php';

class Test_Logger extends WC_Payment_Monitor_Test_Case {
    
    /**
     * Logger instance
     */
    private $logger;
    
    /**
     * Database instance
     */
    private $database;
    
    /**
     * Set up test environment
     */
    public function set_up() {
        parent::set_up();
        
        // Mock WordPress and WooCommerce functions
        $this->mock_woocommerce_functions();
        $this->mock_wordpress_database_functions();
        
        // Create database instance and tables
        $this->database = new WC_Payment_Monitor_Database();
        $this->database->create_tables();
        
        // Create logger instance
        $this->logger = new WC_Payment_Monitor_Logger();
    }
    
    /**
     * Test successful payment logging
     */
    public function test_log_success() {
        global $wpdb;
        
        // Create mock order
        $order = $this->create_mock_order([
            'id' => 123,
            'payment_method' => 'stripe',
            'transaction_id' => 'txn_123456',
            'total' => 99.99,
            'currency' => 'USD',
            'billing_email' => 'test@example.com',
            'customer_ip' => '192.168.1.1'
        ]);
        
        // Mock wc_get_order function
        global $mock_orders;
        $mock_orders[123] = $order;
        
        // Log successful payment
        $this->logger->log_success(123);
        
        // Verify transaction was saved
        $transaction = $this->logger->get_transaction_by_order_id(123);
        $this->assertNotNull($transaction);
        $this->assertEquals(123, $transaction->order_id);
        $this->assertEquals('stripe', $transaction->gateway_id);
        $this->assertEquals('txn_123456', $transaction->transaction_id);
        $this->assertEquals(99.99, $transaction->amount);
        $this->assertEquals('USD', $transaction->currency);
        $this->assertEquals('success', $transaction->status);
        $this->assertEquals('test@example.com', $transaction->customer_email);
        $this->assertEquals('192.168.1.1', $transaction->customer_ip);
        $this->assertNull($transaction->failure_reason);
        $this->assertNull($transaction->failure_code);
        $this->assertEquals(0, $transaction->retry_count);
    }
    
    /**
     * Test failed payment logging
     */
    public function test_log_failure() {
        global $wpdb;
        
        // Create mock order with failure notes
        $order = $this->create_mock_order([
            'id' => 124,
            'payment_method' => 'paypal',
            'transaction_id' => null,
            'total' => 149.99,
            'currency' => 'EUR',
            'billing_email' => 'fail@example.com',
            'customer_ip' => '192.168.1.2'
        ]);
        
        // Mock order notes with failure information
        global $mock_order_notes;
        $mock_order_notes[124] = [
            (object) [
                'content' => 'Payment failed: Insufficient funds. Error code: insufficient_funds',
                'date_created' => '2023-01-01 12:00:00'
            ]
        ];
        
        // Mock wc_get_order function
        global $mock_orders;
        $mock_orders[124] = $order;
        
        // Log failed payment
        $this->logger->log_failure(124);
        
        // Verify transaction was saved with failure details
        $transaction = $this->logger->get_transaction_by_order_id(124);
        $this->assertNotNull($transaction);
        $this->assertEquals(124, $transaction->order_id);
        $this->assertEquals('paypal', $transaction->gateway_id);
        $this->assertNull($transaction->transaction_id);
        $this->assertEquals(149.99, $transaction->amount);
        $this->assertEquals('EUR', $transaction->currency);
        $this->assertEquals('failed', $transaction->status);
        $this->assertEquals('fail@example.com', $transaction->customer_email);
        $this->assertEquals('192.168.1.2', $transaction->customer_ip);
        $this->assertStringContainsString('Payment failed: Insufficient funds', $transaction->failure_reason);
        $this->assertEquals('insufficient_funds', $transaction->failure_code);
        $this->assertEquals(0, $transaction->retry_count);
    }
    
    /**
     * Test pending payment logging
     */
    public function test_log_pending() {
        global $wpdb;
        
        // Create mock order
        $order = $this->create_mock_order([
            'id' => 125,
            'payment_method' => 'bacs',
            'transaction_id' => 'pending_123',
            'total' => 75.50,
            'currency' => 'GBP',
            'billing_email' => 'pending@example.com',
            'customer_ip' => '192.168.1.3'
        ]);
        
        // Mock wc_get_order function
        global $mock_orders;
        $mock_orders[125] = $order;
        
        // Log pending payment
        $this->logger->log_pending(125);
        
        // Verify transaction was saved
        $transaction = $this->logger->get_transaction_by_order_id(125);
        $this->assertNotNull($transaction);
        $this->assertEquals(125, $transaction->order_id);
        $this->assertEquals('bacs', $transaction->gateway_id);
        $this->assertEquals('pending_123', $transaction->transaction_id);
        $this->assertEquals(75.50, $transaction->amount);
        $this->assertEquals('GBP', $transaction->currency);
        $this->assertEquals('pending', $transaction->status);
        $this->assertEquals('pending@example.com', $transaction->customer_email);
        $this->assertEquals('192.168.1.3', $transaction->customer_ip);
    }
    
    /**
     * Test transaction update when order already exists
     */
    public function test_transaction_update_existing_order() {
        global $wpdb;
        
        // Create mock order
        $order = $this->create_mock_order([
            'id' => 126,
            'payment_method' => 'stripe',
            'transaction_id' => 'txn_update',
            'total' => 200.00,
            'currency' => 'USD',
            'billing_email' => 'update@example.com',
            'customer_ip' => '192.168.1.4'
        ]);
        
        // Mock wc_get_order function
        global $mock_orders;
        $mock_orders[126] = $order;
        
        // First log as pending
        $this->logger->log_pending(126);
        $transaction = $this->logger->get_transaction_by_order_id(126);
        $this->assertEquals('pending', $transaction->status);
        $original_created_at = $transaction->created_at;
        
        // Then update to success
        $this->logger->log_success(126);
        $updated_transaction = $this->logger->get_transaction_by_order_id(126);
        
        // Verify transaction was updated, not duplicated
        $this->assertEquals('success', $updated_transaction->status);
        $this->assertEquals($original_created_at, $updated_transaction->created_at);
        $this->assertNotNull($updated_transaction->updated_at);
    }
    
    /**
     * Test failure reason extraction from order notes
     */
    public function test_failure_reason_extraction() {
        global $wpdb;
        
        // Test various failure patterns
        $test_cases = [
            [
                'note' => 'Payment failed: Card declined by issuer',
                'expected_reason' => 'Payment failed: Card declined by issuer',
                'expected_code' => null
            ],
            [
                'note' => 'Transaction failed with error code: card_declined',
                'expected_reason' => 'Transaction failed with error code: card_declined',
                'expected_code' => 'card_declined'
            ],
            [
                'note' => 'Payment declined. Error: insufficient_funds',
                'expected_reason' => 'Payment declined. Error: insufficient_funds',
                'expected_code' => 'insufficient_funds'
            ]
        ];
        
        foreach ($test_cases as $index => $test_case) {
            $order_id = 200 + $index;
            
            // Create mock order
            $order = $this->create_mock_order([
                'id' => $order_id,
                'payment_method' => 'test',
                'total' => 100.00,
                'currency' => 'USD'
            ]);
            
            // Mock order notes
            global $mock_order_notes;
            $mock_order_notes[$order_id] = [
                (object) [
                    'content' => $test_case['note'],
                    'date_created' => '2023-01-01 12:00:00'
                ]
            ];
            
            // Mock wc_get_order function
            global $mock_orders;
            $mock_orders[$order_id] = $order;
            
            // Log failed payment
            $this->logger->log_failure($order_id);
            
            // Verify failure information extraction
            $transaction = $this->logger->get_transaction_by_order_id($order_id);
            $this->assertEquals($test_case['expected_reason'], $transaction->failure_reason);
            $this->assertEquals($test_case['expected_code'], $transaction->failure_code);
        }
    }
    
    /**
     * Test transaction statistics calculation
     */
    public function test_transaction_stats() {
        global $wpdb;
        
        // Create multiple transactions for testing
        $transactions = [
            ['order_id' => 301, 'gateway_id' => 'stripe', 'status' => 'success', 'amount' => 100.00],
            ['order_id' => 302, 'gateway_id' => 'stripe', 'status' => 'success', 'amount' => 150.00],
            ['order_id' => 303, 'gateway_id' => 'stripe', 'status' => 'failed', 'amount' => 75.00],
            ['order_id' => 304, 'gateway_id' => 'stripe', 'status' => 'pending', 'amount' => 200.00],
            ['order_id' => 305, 'gateway_id' => 'paypal', 'status' => 'success', 'amount' => 300.00]
        ];
        
        foreach ($transactions as $transaction_data) {
            $order = $this->create_mock_order([
                'id' => $transaction_data['order_id'],
                'payment_method' => $transaction_data['gateway_id'],
                'total' => $transaction_data['amount'],
                'currency' => 'USD'
            ]);
            
            global $mock_orders;
            $mock_orders[$transaction_data['order_id']] = $order;
            
            // Log transaction based on status
            switch ($transaction_data['status']) {
                case 'success':
                    $this->logger->log_success($transaction_data['order_id']);
                    break;
                case 'failed':
                    $this->logger->log_failure($transaction_data['order_id']);
                    break;
                case 'pending':
                    $this->logger->log_pending($transaction_data['order_id']);
                    break;
            }
        }
        
        // Test Stripe gateway stats
        $stripe_stats = $this->logger->get_transaction_stats('stripe', 3600); // 1 hour
        $this->assertEquals(4, $stripe_stats['total_transactions']);
        $this->assertEquals(2, $stripe_stats['successful_transactions']);
        $this->assertEquals(1, $stripe_stats['failed_transactions']);
        $this->assertEquals(1, $stripe_stats['pending_transactions']);
        $this->assertEquals(50.00, $stripe_stats['success_rate']); // 2/4 * 100
        $this->assertEquals(525.00, $stripe_stats['total_amount']); // 100+150+75+200
        
        // Test PayPal gateway stats
        $paypal_stats = $this->logger->get_transaction_stats('paypal', 3600);
        $this->assertEquals(1, $paypal_stats['total_transactions']);
        $this->assertEquals(1, $paypal_stats['successful_transactions']);
        $this->assertEquals(0, $paypal_stats['failed_transactions']);
        $this->assertEquals(100.00, $paypal_stats['success_rate']); // 1/1 * 100
        $this->assertEquals(300.00, $paypal_stats['total_amount']);
    }
    
    /**
     * Test getting transactions by gateway
     */
    public function test_get_transactions_by_gateway() {
        // Create test transactions
        $this->create_test_transactions();
        
        // Get Stripe transactions
        $stripe_transactions = $this->logger->get_transactions_by_gateway('stripe', 10, 0);
        $this->assertCount(2, $stripe_transactions);
        
        foreach ($stripe_transactions as $transaction) {
            $this->assertEquals('stripe', $transaction->gateway_id);
        }
        
        // Get PayPal transactions
        $paypal_transactions = $this->logger->get_transactions_by_gateway('paypal', 10, 0);
        $this->assertCount(1, $paypal_transactions);
        $this->assertEquals('paypal', $paypal_transactions[0]->gateway_id);
    }
    
    /**
     * Test getting transactions by status
     */
    public function test_get_transactions_by_status() {
        // Create test transactions
        $this->create_test_transactions();
        
        // Get successful transactions
        $success_transactions = $this->logger->get_transactions_by_status('success', 10, 0);
        $this->assertCount(2, $success_transactions);
        
        foreach ($success_transactions as $transaction) {
            $this->assertEquals('success', $transaction->status);
        }
        
        // Get failed transactions
        $failed_transactions = $this->logger->get_transactions_by_status('failed', 10, 0);
        $this->assertCount(1, $failed_transactions);
        $this->assertEquals('failed', $failed_transactions[0]->status);
    }
    
    /**
     * Test invalid order handling
     */
    public function test_invalid_order_handling() {
        // Mock wc_get_order to return false for invalid order
        global $mock_orders;
        $mock_orders[999] = false;
        
        // Attempt to log invalid order - should not throw exception
        $this->logger->log_success(999);
        $this->logger->log_failure(999);
        $this->logger->log_pending(999);
        
        // Verify no transaction was created
        $transaction = $this->logger->get_transaction_by_order_id(999);
        $this->assertNull($transaction);
    }
    
    /**
     * Create test transactions for testing queries
     */
    private function create_test_transactions() {
        $transactions = [
            ['order_id' => 401, 'gateway_id' => 'stripe', 'status' => 'success'],
            ['order_id' => 402, 'gateway_id' => 'stripe', 'status' => 'failed'],
            ['order_id' => 403, 'gateway_id' => 'paypal', 'status' => 'success']
        ];
        
        foreach ($transactions as $transaction_data) {
            $order = $this->create_mock_order([
                'id' => $transaction_data['order_id'],
                'payment_method' => $transaction_data['gateway_id'],
                'total' => 100.00,
                'currency' => 'USD'
            ]);
            
            global $mock_orders;
            $mock_orders[$transaction_data['order_id']] = $order;
            
            switch ($transaction_data['status']) {
                case 'success':
                    $this->logger->log_success($transaction_data['order_id']);
                    break;
                case 'failed':
                    $this->logger->log_failure($transaction_data['order_id']);
                    break;
            }
        }
    }
    
    /**
     * Create a mock WooCommerce order
     */
    private function create_mock_order($data) {
        $order = new stdClass();
        
        $order->get_id = function() use ($data) {
            return $data['id'];
        };
        
        $order->get_payment_method = function() use ($data) {
            return $data['payment_method'] ?? '';
        };
        
        $order->get_transaction_id = function() use ($data) {
            return $data['transaction_id'] ?? null;
        };
        
        $order->get_total = function() use ($data) {
            return $data['total'] ?? 0.00;
        };
        
        $order->get_currency = function() use ($data) {
            return $data['currency'] ?? 'USD';
        };
        
        $order->get_billing_email = function() use ($data) {
            return $data['billing_email'] ?? '';
        };
        
        $order->get_customer_ip_address = function() use ($data) {
            return $data['customer_ip'] ?? '';
        };
        
        return $order;
    }
    
    /**
     * Mock WooCommerce functions
     */
    private function mock_woocommerce_functions() {
        if (!function_exists('wc_get_order')) {
            function wc_get_order($order_id) {
                global $mock_orders;
                return isset($mock_orders[$order_id]) ? $mock_orders[$order_id] : false;
            }
        }
        
        if (!function_exists('wc_get_order_notes')) {
            function wc_get_order_notes($args) {
                global $mock_order_notes;
                $order_id = $args['order_id'];
                return isset($mock_order_notes[$order_id]) ? $mock_order_notes[$order_id] : [];
            }
        }
        
        if (!function_exists('current_time')) {
            function current_time($type) {
                return date('Y-m-d H:i:s');
            }
        }
    }
    
    /**
     * Mock WordPress database functions
     */
    private function mock_wordpress_database_functions() {
        global $wpdb;
        
        if (!isset($wpdb)) {
            $wpdb = new stdClass();
            $wpdb->prefix = 'wp_';
            $wpdb->insert_id = 1;
            $wpdb->queries = [];
            $wpdb->data = [];
        }
        
        // Mock insert method
        $wpdb->insert = function($table, $data, $format = null) use ($wpdb) {
            $wpdb->data[$table][] = (object) $data;
            $wpdb->insert_id++;
            return 1;
        };
        
        // Mock update method
        $wpdb->update = function($table, $data, $where, $format = null, $where_format = null) use ($wpdb) {
            if (!isset($wpdb->data[$table])) {
                return false;
            }
            
            foreach ($wpdb->data[$table] as &$row) {
                $match = true;
                foreach ($where as $key => $value) {
                    if (!isset($row->$key) || $row->$key != $value) {
                        $match = false;
                        break;
                    }
                }
                
                if ($match) {
                    foreach ($data as $key => $value) {
                        $row->$key = $value;
                    }
                    return 1;
                }
            }
            
            return false;
        };
        
        // Mock get_row method
        $wpdb->get_row = function($query, $output = OBJECT) use ($wpdb) {
            // Simple mock - extract table and WHERE clause
            if (preg_match('/FROM\s+(\S+).*WHERE\s+(\w+)\s*=\s*(\d+)/', $query, $matches)) {
                $table = $matches[1];
                $column = $matches[2];
                $value = $matches[3];
                
                if (isset($wpdb->data[$table])) {
                    foreach ($wpdb->data[$table] as $row) {
                        if (isset($row->$column) && $row->$column == $value) {
                            return $row;
                        }
                    }
                }
            }
            
            return null;
        };
        
        // Mock get_results method
        $wpdb->get_results = function($query, $output = OBJECT) use ($wpdb) {
            // Simple mock - extract table and conditions
            if (preg_match('/FROM\s+(\S+)/', $query, $matches)) {
                $table = $matches[1];
                
                if (isset($wpdb->data[$table])) {
                    $results = $wpdb->data[$table];
                    
                    // Apply WHERE conditions if present
                    if (preg_match('/WHERE\s+(\w+)\s*=\s*[\'"]([^\'"]+)[\'"]/', $query, $where_matches)) {
                        $column = $where_matches[1];
                        $value = $where_matches[2];
                        
                        $results = array_filter($results, function($row) use ($column, $value) {
                            return isset($row->$column) && $row->$column == $value;
                        });
                    }
                    
                    return array_values($results);
                }
            }
            
            return [];
        };
        
        // Mock prepare method
        $wpdb->prepare = function($query, ...$args) {
            return vsprintf(str_replace(['%s', '%d', '%f'], ['\'%s\'', '%d', '%f'], $query), $args);
        };
        
        // Mock dbDelta function
        if (!function_exists('dbDelta')) {
            function dbDelta($sql) {
                return ['created' => 1];
            }
        }
    }
}