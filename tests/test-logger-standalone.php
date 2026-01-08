<?php
/**
 * Standalone test for WC_Payment_Monitor_Logger class
 * This runs without requiring full WordPress environment
 */

// Include required classes
require_once __DIR__ . '/../includes/class-wc-payment-monitor-database.php';
require_once __DIR__ . '/../includes/class-wc-payment-monitor-logger.php';

// Mock WordPress functions
function get_option($option, $default = false) {
    static $options = [];
    return isset($options[$option]) ? $options[$option] : $default;
}

function update_option($option, $value) {
    static $options = [];
    $options[$option] = $value;
    return true;
}

function add_option($option, $value) {
    return update_option($option, $value);
}

function delete_option($option) {
    static $options = [];
    unset($options[$option]);
    return true;
}

function current_time($type) {
    return date('Y-m-d H:i:s');
}

function dbDelta($sql) {
    global $wpdb;
    
    // Extract table name from CREATE TABLE statement
    if (preg_match('/CREATE TABLE\s+(\S+)\s+\(/', $sql, $matches)) {
        $table = $matches[1];
        $wpdb->created_tables[] = $table;
        $wpdb->table_structures[$table] = $sql;
    }
    
    return ['created' => 1];
}

// Mock WooCommerce functions
function wc_get_order($order_id) {
    global $mock_orders;
    return isset($mock_orders[$order_id]) ? $mock_orders[$order_id] : false;
}

function wc_get_order_notes($args) {
    global $mock_order_notes;
    $order_id = $args['order_id'];
    return isset($mock_order_notes[$order_id]) ? $mock_order_notes[$order_id] : [];
}

// Mock WordPress action hooks
function add_action($hook, $callback, $priority = 10, $accepted_args = 1) {
    // Mock function - just store the hook registration
    global $mock_hooks;
    if (!isset($mock_hooks)) {
        $mock_hooks = [];
    }
    $mock_hooks[$hook][] = $callback;
}

// Mock wpdb
class MockWpdb {
    public $prefix = 'wp_';
    public $insert_id = 1;
    public $created_tables = [];
    public $table_structures = [];
    public $queries = [];
    public $data = [];
    
    public function get_charset_collate() {
        return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
    }
    
    public function query($sql) {
        $this->queries[] = $sql;
        
        // Track DROP TABLE queries
        if (preg_match('/DROP TABLE IF EXISTS\s+(\S+)/', $sql, $matches)) {
            $table = $matches[1];
            $key = array_search($table, $this->created_tables);
            if ($key !== false) {
                unset($this->created_tables[$key]);
                unset($this->table_structures[$table]);
                unset($this->data[$table]);
            }
        }
        
        return true;
    }
    
    public function insert($table, $data, $format = null) {
        if (!isset($this->data[$table])) {
            $this->data[$table] = [];
        }
        
        $data['id'] = $this->insert_id++;
        $this->data[$table][] = (object) $data;
        return 1;
    }
    
    public function update($table, $data, $where, $format = null, $where_format = null) {
        if (!isset($this->data[$table])) {
            return false;
        }
        
        foreach ($this->data[$table] as &$row) {
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
    }
    
    public function get_row($query, $output = OBJECT) {
        // Simple mock - extract table and WHERE clause
        if (preg_match('/FROM\s+(\S+).*WHERE\s+(\w+)\s*=\s*(\d+)/', $query, $matches)) {
            $table = $matches[1];
            $column = $matches[2];
            $value = $matches[3];
            
            if (isset($this->data[$table])) {
                foreach ($this->data[$table] as $row) {
                    if (isset($row->$column) && $row->$column == $value) {
                        return $row;
                    }
                }
            }
        }
        
        return null;
    }
    
    public function get_results($query, $output = OBJECT) {
        // Simple mock - extract table and conditions
        if (preg_match('/FROM\s+(\S+)/', $query, $matches)) {
            $table = $matches[1];
            
            if (isset($this->data[$table])) {
                $results = $this->data[$table];
                
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
    }
    
    public function get_var($sql) {
        if (preg_match('/SHOW TABLES LIKE\s+\'([^\']+)\'/', $sql, $matches)) {
            $table = $matches[1];
            return in_array($table, $this->created_tables) ? $table : null;
        }
        return null;
    }
    
    public function prepare($query, ...$args) {
        return vsprintf(str_replace(['%s', '%d', '%f'], ['\'%s\'', '%d', '%f'], $query), $args);
    }
}

// Set up global wpdb
$wpdb = new MockWpdb();

// Define constants
if (!defined('ABSPATH')) {
    define('ABSPATH', '/tmp/wordpress/');
}

/**
 * Test runner class for Logger
 */
class LoggerTestRunner {
    
    private $tests_passed = 0;
    private $tests_failed = 0;
    private $logger;
    private $database;
    
    public function __construct() {
        $this->database = new WC_Payment_Monitor_Database();
        $this->database->create_tables();
        $this->logger = new WC_Payment_Monitor_Logger();
    }
    
    public function run_all_tests() {
        echo "Running Logger Tests...\n";
        echo "======================\n\n";
        
        $this->test_log_success();
        $this->test_log_failure();
        $this->test_log_pending();
        $this->test_transaction_update_existing_order();
        $this->test_failure_reason_extraction();
        $this->test_transaction_stats();
        $this->test_invalid_order_handling();
        
        $this->print_summary();
    }
    
    private function test_log_success() {
        echo "Testing successful payment logging... ";
        
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
        
        if (!$transaction) {
            $this->fail("Transaction was not saved");
            return;
        }
        
        if ($transaction->order_id != 123 ||
            $transaction->gateway_id != 'stripe' ||
            $transaction->transaction_id != 'txn_123456' ||
            $transaction->amount != 99.99 ||
            $transaction->currency != 'USD' ||
            $transaction->status != 'success' ||
            $transaction->customer_email != 'test@example.com' ||
            $transaction->customer_ip != '192.168.1.1' ||
            $transaction->failure_reason !== null ||
            $transaction->failure_code !== null ||
            $transaction->retry_count != 0) {
            
            $this->fail("Transaction data is incorrect");
            return;
        }
        
        $this->pass();
    }
    
    private function test_log_failure() {
        echo "Testing failed payment logging... ";
        
        // Create mock order
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
        
        if (!$transaction) {
            $this->fail("Transaction was not saved");
            return;
        }
        
        if ($transaction->order_id != 124 ||
            $transaction->gateway_id != 'paypal' ||
            $transaction->transaction_id !== null ||
            $transaction->amount != 149.99 ||
            $transaction->currency != 'EUR' ||
            $transaction->status != 'failed' ||
            $transaction->customer_email != 'fail@example.com' ||
            $transaction->customer_ip != '192.168.1.2' ||
            strpos($transaction->failure_reason, 'Payment failed: Insufficient funds') === false ||
            $transaction->failure_code != 'insufficient_funds' ||
            $transaction->retry_count != 0) {
            
            $this->fail("Transaction data is incorrect");
            return;
        }
        
        $this->pass();
    }
    
    private function test_log_pending() {
        echo "Testing pending payment logging... ";
        
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
        
        if (!$transaction) {
            $this->fail("Transaction was not saved");
            return;
        }
        
        if ($transaction->order_id != 125 ||
            $transaction->gateway_id != 'bacs' ||
            $transaction->transaction_id != 'pending_123' ||
            $transaction->amount != 75.50 ||
            $transaction->currency != 'GBP' ||
            $transaction->status != 'pending' ||
            $transaction->customer_email != 'pending@example.com' ||
            $transaction->customer_ip != '192.168.1.3') {
            
            $this->fail("Transaction data is incorrect");
            return;
        }
        
        $this->pass();
    }
    
    private function test_transaction_update_existing_order() {
        echo "Testing transaction update for existing order... ";
        
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
        
        if ($transaction->status != 'pending') {
            $this->fail("Initial status should be pending");
            return;
        }
        
        $original_created_at = $transaction->created_at;
        
        // Then update to success
        $this->logger->log_success(126);
        $updated_transaction = $this->logger->get_transaction_by_order_id(126);
        
        // Verify transaction was updated, not duplicated
        if ($updated_transaction->status != 'success') {
            $this->fail("Status should be updated to success");
            return;
        }
        
        if ($updated_transaction->created_at != $original_created_at) {
            $this->fail("Created timestamp should not change on update");
            return;
        }
        
        if ($updated_transaction->updated_at === null) {
            $this->fail("Updated timestamp should be set");
            return;
        }
        
        $this->pass();
    }
    
    private function test_failure_reason_extraction() {
        echo "Testing failure reason extraction... ";
        
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
            
            if ($transaction->failure_reason != $test_case['expected_reason']) {
                $this->fail("Failure reason extraction failed for case $index");
                return;
            }
            
            if ($transaction->failure_code != $test_case['expected_code']) {
                $this->fail("Failure code extraction failed for case $index");
                return;
            }
        }
        
        $this->pass();
    }
    
    private function test_transaction_stats() {
        echo "Testing transaction statistics calculation... ";
        
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
        
        if ($stripe_stats['total_transactions'] != 4 ||
            $stripe_stats['successful_transactions'] != 2 ||
            $stripe_stats['failed_transactions'] != 1 ||
            $stripe_stats['pending_transactions'] != 1 ||
            $stripe_stats['success_rate'] != 50.00 ||
            $stripe_stats['total_amount'] != 525.00) {
            
            $this->fail("Stripe gateway statistics are incorrect");
            return;
        }
        
        // Test PayPal gateway stats
        $paypal_stats = $this->logger->get_transaction_stats('paypal', 3600);
        
        if ($paypal_stats['total_transactions'] != 1 ||
            $paypal_stats['successful_transactions'] != 1 ||
            $paypal_stats['failed_transactions'] != 0 ||
            $paypal_stats['success_rate'] != 100.00 ||
            $paypal_stats['total_amount'] != 300.00) {
            
            $this->fail("PayPal gateway statistics are incorrect");
            return;
        }
        
        $this->pass();
    }
    
    private function test_invalid_order_handling() {
        echo "Testing invalid order handling... ";
        
        // Mock wc_get_order to return false for invalid order
        global $mock_orders;
        $mock_orders[999] = false;
        
        // Attempt to log invalid order - should not throw exception
        try {
            $this->logger->log_success(999);
            $this->logger->log_failure(999);
            $this->logger->log_pending(999);
        } catch (Exception $e) {
            $this->fail("Exception thrown for invalid order: " . $e->getMessage());
            return;
        }
        
        // Verify no transaction was created
        $transaction = $this->logger->get_transaction_by_order_id(999);
        if ($transaction !== null) {
            $this->fail("Transaction should not be created for invalid order");
            return;
        }
        
        $this->pass();
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
    
    private function pass() {
        echo "PASS\n";
        $this->tests_passed++;
    }
    
    private function fail($message) {
        echo "FAIL - $message\n";
        $this->tests_failed++;
    }
    
    private function print_summary() {
        echo "\n======================\n";
        echo "Test Summary:\n";
        echo "Passed: {$this->tests_passed}\n";
        echo "Failed: {$this->tests_failed}\n";
        echo "Total:  " . ($this->tests_passed + $this->tests_failed) . "\n";
        
        if ($this->tests_failed > 0) {
            echo "\nSome tests failed!\n";
            exit(1);
        } else {
            echo "\nAll tests passed!\n";
            exit(0);
        }
    }
}

// Run the tests
$runner = new LoggerTestRunner();
$runner->run_all_tests();