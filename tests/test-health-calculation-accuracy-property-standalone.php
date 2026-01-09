<?php
/**
 * Standalone Property-based test for health calculation accuracy
 * 
 * **Feature: payment-monitor, Property 3: Health Calculation Accuracy**
 * **Validates: Requirements 2.1, 2.4**
 */

// Include required classes
require_once __DIR__ . '/../includes/class-wc-payment-monitor-database.php';
require_once __DIR__ . '/../includes/class-wc-payment-monitor-logger.php';
require_once __DIR__ . '/../includes/class-wc-payment-monitor-health.php';

// Mock WordPress functions
function get_option($option, $default = false) {
    static $options = [
        'wc_payment_monitor_settings' => [
            'enabled_gateways' => ['stripe', 'paypal'],
            'alert_email' => 'admin@example.com',
            'alert_threshold' => 85,
            'monitoring_interval' => 300,
            'enable_auto_retry' => true,
            'retry_schedule' => [3600, 21600, 86400],
            'alert_phone' => '',
            'slack_webhook' => ''
        ]
    ];
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

// Mock WordPress action hooks
function add_action($hook, $callback, $priority = 10, $accepted_args = 1) {
    // Mock function - just store the hook registration
    global $mock_hooks;
    if (!isset($mock_hooks)) {
        $mock_hooks = [];
    }
    $mock_hooks[$hook][] = $callback;
}

function add_filter($hook, $callback, $priority = 10, $accepted_args = 1) {
    // Mock function - just store the filter registration
    global $mock_filters;
    if (!isset($mock_filters)) {
        $mock_filters = [];
    }
    $mock_filters[$hook][] = $callback;
}

function wp_next_scheduled($hook) {
    // Mock - return false to allow scheduling
    return false;
}

function wp_schedule_event($timestamp, $recurrence, $hook, $args = []) {
    // Mock - just return true
    return true;
}

function do_action($hook, ...$args) {
    // Mock - just return true
    return true;
}

function __($text, $domain = '') {
    return $text;
}

// Mock WooCommerce classes
class WC_Payment_Gateways {
    private static $instance = null;
    
    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function get_available_payment_gateways() {
        return [
            'stripe' => (object) ['enabled' => 'yes'],
            'paypal' => (object) ['enabled' => 'yes'],
            'bacs' => (object) ['enabled' => 'no']
        ];
    }
}

function wc_get_order($order_id) {
    return null; // Not needed for this test
}

function wc_get_order_notes($args) {
    return []; // Not needed for this test
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
        // Parse query to extract table and conditions
        if (preg_match('/FROM\s+(\S+)/', $query, $table_matches)) {
            $table = $table_matches[1];
            
            if (isset($this->data[$table])) {
                $results = $this->data[$table];
                
                // Apply WHERE conditions
                if (preg_match_all('/(\w+)\s*=\s*[\'"]([^\'"]+)[\'"]/', $query, $where_matches, PREG_SET_ORDER)) {
                    foreach ($where_matches as $where_match) {
                        $column = $where_match[1];
                        $value = $where_match[2];
                        
                        $results = array_filter($results, function($row) use ($column, $value) {
                            return isset($row->$column) && $row->$column == $value;
                        });
                    }
                }
                
                return !empty($results) ? array_values($results)[0] : null;
            }
        }
        
        // Handle aggregate queries for transaction stats
        if (preg_match('/COUNT\(\*\)\s+as\s+total_transactions/', $query)) {
            return $this->get_transaction_stats_mock($query);
        }
        
        return null;
    }
    
    public function get_results($query, $output = OBJECT) {
        // Parse query to extract table and conditions
        if (preg_match('/FROM\s+(\S+)/', $query, $table_matches)) {
            $table = $table_matches[1];
            
            if (isset($this->data[$table])) {
                $results = $this->data[$table];
                
                // Apply WHERE conditions
                if (preg_match_all('/(\w+)\s*=\s*[\'"]([^\'"]+)[\'"]/', $query, $where_matches, PREG_SET_ORDER)) {
                    foreach ($where_matches as $where_match) {
                        $column = $where_match[1];
                        $value = $where_match[2];
                        
                        $results = array_filter($results, function($row) use ($column, $value) {
                            return isset($row->$column) && $row->$column == $value;
                        });
                    }
                }
                
                // Convert to array if requested
                if ($output === ARRAY_A) {
                    $results = array_map(function($row) {
                        return (array) $row;
                    }, $results);
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
        
        // Handle last failure time query
        if (preg_match('/SELECT\s+created_at\s+FROM.*WHERE.*status\s*=\s*[\'"]failed[\'"]/', $sql)) {
            // Return a mock timestamp or null
            return date('Y-m-d H:i:s', time() - 3600); // 1 hour ago
        }
        
        return null;
    }
    
    public function prepare($query, ...$args) {
        return vsprintf(str_replace(['%s', '%d', '%f'], ['\'%s\'', '%d', '%f'], $query), $args);
    }
    
    /**
     * Mock transaction stats calculation
     */
    private function get_transaction_stats_mock($query) {
        // Extract gateway_id and time from query
        preg_match('/gateway_id\s*=\s*[\'"]([^\'"]+)[\'"]/', $query, $gateway_matches);
        preg_match('/created_at\s*>=\s*[\'"]([^\'"]+)[\'"]/', $query, $time_matches);
        
        if (!$gateway_matches || !$time_matches) {
            return (object) [
                'total_transactions' => 0,
                'successful_transactions' => 0,
                'failed_transactions' => 0,
                'pending_transactions' => 0,
                'retry_transactions' => 0,
                'total_amount' => 0,
                'avg_amount' => 0,
                'success_rate' => 0.00
            ];
        }
        
        $gateway_id = $gateway_matches[1];
        $start_time = $time_matches[1];
        
        // Get transactions table
        $table = $this->prefix . 'wc_payment_monitor_transactions';
        if (!isset($this->data[$table])) {
            return (object) [
                'total_transactions' => 0,
                'successful_transactions' => 0,
                'failed_transactions' => 0,
                'pending_transactions' => 0,
                'retry_transactions' => 0,
                'total_amount' => 0,
                'avg_amount' => 0,
                'success_rate' => 0.00
            ];
        }
        
        // Filter transactions by gateway and time
        $transactions = array_filter($this->data[$table], function($row) use ($gateway_id, $start_time) {
            return $row->gateway_id === $gateway_id && $row->created_at >= $start_time;
        });
        
        // Calculate stats
        $total = count($transactions);
        $successful = count(array_filter($transactions, function($row) { return $row->status === 'success'; }));
        $failed = count(array_filter($transactions, function($row) { return $row->status === 'failed'; }));
        $pending = count(array_filter($transactions, function($row) { return $row->status === 'pending'; }));
        $retry = count(array_filter($transactions, function($row) { return $row->status === 'retry'; }));
        
        $total_amount = array_sum(array_map(function($row) { return $row->amount; }, $transactions));
        $avg_amount = $total > 0 ? $total_amount / $total : 0;
        $success_rate = $total > 0 ? round(($successful / $total) * 100, 2) : 0.00;
        
        return (object) [
            'total_transactions' => $total,
            'successful_transactions' => $successful,
            'failed_transactions' => $failed,
            'pending_transactions' => $pending,
            'retry_transactions' => $retry,
            'total_amount' => $total_amount,
            'avg_amount' => $avg_amount,
            'success_rate' => $success_rate
        ];
    }
}

// Set up global wpdb
$wpdb = new MockWpdb();

// Define constants
if (!defined('ABSPATH')) {
    define('ABSPATH', '/tmp/wordpress/');
}

if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}

if (!defined('OBJECT')) {
    define('OBJECT', 'OBJECT');
}

/**
 * Property-based test runner for Health Calculation Accuracy
 */
class HealthCalculationAccuracyPropertyTest {
    
    private $tests_passed = 0;
    private $tests_failed = 0;
    private $health;
    private $database;
    private $logger;
    
    public function __construct() {
        $this->database = new WC_Payment_Monitor_Database();
        $this->database->create_tables();
        $this->logger = new WC_Payment_Monitor_Logger();
        $this->health = new WC_Payment_Monitor_Health();
    }
    
    public function run_all_tests() {
        echo "Running Health Calculation Accuracy Property Tests...\n";
        echo "====================================================\n\n";
        
        $this->test_health_calculation_accuracy_property();
        $this->test_empty_data_handling_property();
        $this->test_single_transaction_scenarios_property();
        $this->test_historical_data_storage_property();
        
        $this->print_summary();
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
        echo "Testing health calculation accuracy property... ";
        
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
            return;
        }
        
        $this->pass("Health calculation accuracy property holds for all $iterations iterations");
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
        if (!isset($health_data[$period_name])) {
            throw new Exception("Missing health data for period $period_name in iteration $iteration");
        }
        
        $period_health = $health_data[$period_name];
        
        // Verify basic structure
        if ($period_health['gateway_id'] !== $gateway_id) {
            throw new Exception("Incorrect gateway_id in iteration $iteration");
        }
        
        if ($period_health['period'] !== $period_name) {
            throw new Exception("Incorrect period in iteration $iteration");
        }
        
        // Verify transaction counts
        if ($expected_stats['total_transactions'] !== $period_health['total_transactions']) {
            throw new Exception("Total transactions mismatch for $period_name in iteration $iteration. Expected: {$expected_stats['total_transactions']}, Got: {$period_health['total_transactions']}");
        }
        
        if ($expected_stats['successful_transactions'] !== $period_health['successful_transactions']) {
            throw new Exception("Successful transactions mismatch for $period_name in iteration $iteration. Expected: {$expected_stats['successful_transactions']}, Got: {$period_health['successful_transactions']}");
        }
        
        if ($expected_stats['failed_transactions'] !== $period_health['failed_transactions']) {
            throw new Exception("Failed transactions mismatch for $period_name in iteration $iteration. Expected: {$expected_stats['failed_transactions']}, Got: {$period_health['failed_transactions']}");
        }
        
        // Verify success rate calculation (core property)
        $expected_success_rate = $expected_stats['total_transactions'] > 0 
            ? round(($expected_stats['successful_transactions'] / $expected_stats['total_transactions']) * 100, 2)
            : 0.00;
        
        if (abs($expected_success_rate - $period_health['success_rate']) > 0.01) {
            throw new Exception("Success rate calculation incorrect for $period_name in iteration $iteration. Expected: $expected_success_rate%, Got: {$period_health['success_rate']}%");
        }
        
        // Verify data storage (Requirements 2.4)
        $stored_health = $this->health->get_health_status($gateway_id, $period_name);
        if (!$stored_health) {
            throw new Exception("Health data not stored for $period_name in iteration $iteration");
        }
        
        if (abs($expected_success_rate - floatval($stored_health->success_rate)) > 0.01) {
            throw new Exception("Stored success rate incorrect for $period_name in iteration $iteration");
        }
    }
    
    /**
     * Test edge case: Empty data handling
     */
    public function test_empty_data_handling_property() {
        echo "Testing empty data handling property... ";
        
        $iterations = 20; // Fewer iterations for edge case
        $failures = [];
        
        for ($i = 0; $i < $iterations; $i++) {
            try {
                $gateway_id = $this->generate_random_gateway_id();
                
                // Calculate health with no transaction data
                $health_data = $this->health->calculate_health($gateway_id);
                
                foreach ($health_data as $period => $data) {
                    if ($data['total_transactions'] !== 0) {
                        throw new Exception("Empty data: total_transactions should be 0 for $period in iteration $i");
                    }
                    if ($data['successful_transactions'] !== 0) {
                        throw new Exception("Empty data: successful_transactions should be 0 for $period in iteration $i");
                    }
                    if ($data['failed_transactions'] !== 0) {
                        throw new Exception("Empty data: failed_transactions should be 0 for $period in iteration $i");
                    }
                    if ($data['success_rate'] !== 0.00) {
                        throw new Exception("Empty data: success_rate should be 0.00 for $period in iteration $i");
                    }
                }
                
                // Clean up for next iteration
                $this->clean_up_database();
                $this->database->create_tables();
                
            } catch (Exception $e) {
                $failures[] = "Iteration $i: " . $e->getMessage();
            }
        }
        
        if (!empty($failures)) {
            $this->fail("Empty data handling property failed in " . count($failures) . " iterations:\n" . implode("\n", array_slice($failures, 0, 3)));
            return;
        }
        
        $this->pass("Empty data handling property holds for all $iterations iterations");
    }
    
    /**
     * Test edge case: Single transaction scenarios
     */
    public function test_single_transaction_scenarios_property() {
        echo "Testing single transaction scenarios property... ";
        
        $iterations = 50;
        $failures = [];
        
        for ($i = 0; $i < $iterations; $i++) {
            try {
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
                if ($hour_data['total_transactions'] !== 1) {
                    throw new Exception("Single transaction: total should be 1 in iteration $i");
                }
                
                if ($status === 'success') {
                    if ($hour_data['successful_transactions'] !== 1) {
                        throw new Exception("Single success: successful should be 1 in iteration $i");
                    }
                    if ($hour_data['failed_transactions'] !== 0) {
                        throw new Exception("Single success: failed should be 0 in iteration $i");
                    }
                    if ($hour_data['success_rate'] !== 100.00) {
                        throw new Exception("Single success: rate should be 100% in iteration $i");
                    }
                } else {
                    if ($hour_data['successful_transactions'] !== 0) {
                        throw new Exception("Single failure: successful should be 0 in iteration $i");
                    }
                    if ($hour_data['failed_transactions'] !== 1) {
                        throw new Exception("Single failure: failed should be 1 in iteration $i");
                    }
                    if ($hour_data['success_rate'] !== 0.00) {
                        throw new Exception("Single failure: rate should be 0% in iteration $i");
                    }
                }
                
                // Clean up for next iteration
                $this->clean_up_database();
                $this->database->create_tables();
                
            } catch (Exception $e) {
                $failures[] = "Iteration $i: " . $e->getMessage();
            }
        }
        
        if (!empty($failures)) {
            $this->fail("Single transaction scenarios property failed in " . count($failures) . " iterations:\n" . implode("\n", array_slice($failures, 0, 3)));
            return;
        }
        
        $this->pass("Single transaction scenarios property holds for all $iterations iterations");
    }
    
    /**
     * Test historical data storage property
     */
    public function test_historical_data_storage_property() {
        echo "Testing historical data storage property... ";
        
        $iterations = 30;
        $failures = [];
        
        for ($i = 0; $i < $iterations; $i++) {
            try {
                $gateway_id = $this->generate_random_gateway_id();
                $transaction_sets = $this->generate_random_transaction_sets($gateway_id);
                
                $this->insert_transaction_data($transaction_sets);
                
                // Calculate health multiple times to create history
                $this->health->calculate_health($gateway_id);
                
                // Verify all periods are stored
                $periods = ['1hour', '24hour', '7day'];
                foreach ($periods as $period) {
                    $stored_health = $this->health->get_health_status($gateway_id, $period);
                    if (!$stored_health) {
                        throw new Exception("Historical data not stored for $period in iteration $i");
                    }
                    
                    if ($stored_health->gateway_id !== $gateway_id) {
                        throw new Exception("Stored gateway_id incorrect for $period in iteration $i");
                    }
                    
                    if ($stored_health->period !== $period) {
                        throw new Exception("Stored period incorrect for $period in iteration $i");
                    }
                }
                
                // Verify gateway health retrieval
                $gateway_health = $this->health->get_gateway_health($gateway_id);
                if (count($gateway_health) !== 3) {
                    throw new Exception("Gateway health should contain 3 periods in iteration $i");
                }
                
                // Clean up for next iteration
                $this->clean_up_database();
                $this->database->create_tables();
                
            } catch (Exception $e) {
                $failures[] = "Iteration $i: " . $e->getMessage();
            }
        }
        
        if (!empty($failures)) {
            $this->fail("Historical data storage property failed in " . count($failures) . " iterations:\n" . implode("\n", array_slice($failures, 0, 3)));
            return;
        }
        
        $this->pass("Historical data storage property holds for all $iterations iterations");
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
     * Clean up database tables
     */
    private function clean_up_database() {
        global $wpdb;
        
        $tables = [
            $wpdb->prefix . 'wc_payment_monitor_transactions',
            $wpdb->prefix . 'wc_payment_monitor_gateway_health',
            $wpdb->prefix . 'wc_payment_monitor_alerts'
        ];
        
        foreach ($tables as $table) {
            $wpdb->query("DROP TABLE IF EXISTS {$table}");
        }
        
        // Clean up options
        delete_option('wc_payment_monitor_db_version');
        delete_option('wc_payment_monitor_version');
        delete_option('wc_payment_monitor_settings');
        
        // Reset wpdb data
        $wpdb->data = [];
        $wpdb->created_tables = [];
        $wpdb->table_structures = [];
        $wpdb->insert_id = 1;
    }
    
    private function pass($message) {
        echo "PASS - $message\n";
        $this->tests_passed++;
    }
    
    private function fail($message) {
        echo "FAIL - $message\n";
        $this->tests_failed++;
    }
    
    private function print_summary() {
        echo "\n====================================================\n";
        echo "Property Test Summary:\n";
        echo "Passed: {$this->tests_passed}\n";
        echo "Failed: {$this->tests_failed}\n";
        echo "Total:  " . ($this->tests_passed + $this->tests_failed) . "\n";
        
        if ($this->tests_failed > 0) {
            echo "\nSome property tests failed!\n";
            exit(1);
        } else {
            echo "\nAll property tests passed!\n";
            echo "\n**Feature: payment-monitor, Property 3: Health Calculation Accuracy**\n";
            echo "**Validates: Requirements 2.1, 2.4**\n";
            echo "\nProperty verified: For any set of transaction data and time period,\n";
            echo "the calculated success rate equals (successful transactions / total transactions) × 100,\n";
            echo "and historical health data is stored for retrieval.\n";
            exit(0);
        }
    }
}

// Run the property-based tests
echo "Starting Health Calculation Accuracy Property Tests...\n";
try {
    $runner = new HealthCalculationAccuracyPropertyTest();
    echo "Runner created successfully\n";
    $runner->run_all_tests();
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo "Stack trace: " . $e->getTraceAsString() . "\n";
} catch (Error $e) {
    echo "Fatal Error: " . $e->getMessage() . "\n";
    echo "Stack trace: " . $e->getTraceAsString() . "\n";
}