<?php
/**
 * Standalone test for WC_Payment_Monitor_Health class
 * This runs without requiring full WordPress environment
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

// Note: time() and date() are built-in PHP functions, no need to mock them

function __($text, $domain = '') {
    return $text;
}

// Note: sprintf() is a built-in PHP function, no need to mock it

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
        
        // Handle aggregate queries
        if (preg_match('/SELECT\s+(COUNT\(\*\)|AVG\(|SUM\(|MIN\(|MAX\()/', $sql)) {
            // Simple mock for aggregate functions
            return '5'; // Return a mock value
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

if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}

if (!defined('OBJECT')) {
    define('OBJECT', 'OBJECT');
}

/**
 * Test runner class for Health Calculator
 */
class HealthTestRunner {
    
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
        echo "Running Health Calculator Tests...\n";
        echo "==================================\n\n";
        
        $this->test_health_calculation_periods();
        $this->test_health_data_storage();
        $this->test_gateway_status_determination();
        $this->test_empty_data_handling();
        $this->test_health_history_retrieval();
        $this->test_summary_statistics();
        $this->test_active_gateways_detection();
        
        $this->print_summary();
    }
    
    private function test_health_calculation_periods() {
        echo "Testing health calculation for different periods... ";
        
        // Create sample transaction data
        $this->create_sample_transactions();
        
        // Calculate health for stripe gateway
        $health_data = $this->health->calculate_health('stripe');
        
        // Verify all periods are calculated
        $expected_periods = ['1hour', '24hour', '7day'];
        foreach ($expected_periods as $period) {
            if (!isset($health_data[$period])) {
                $this->fail("Missing health data for period: $period");
                return;
            }
            
            $period_data = $health_data[$period];
            if (!isset($period_data['gateway_id']) ||
                !isset($period_data['period']) ||
                !isset($period_data['total_transactions']) ||
                !isset($period_data['successful_transactions']) ||
                !isset($period_data['failed_transactions']) ||
                !isset($period_data['success_rate']) ||
                !isset($period_data['calculated_at'])) {
                
                $this->fail("Missing required fields in health data for period: $period");
                return;
            }
            
            if ($period_data['gateway_id'] !== 'stripe' ||
                $period_data['period'] !== $period) {
                
                $this->fail("Incorrect gateway_id or period in health data");
                return;
            }
        }
        
        $this->pass();
    }
    
    private function test_health_data_storage() {
        echo "Testing health data storage and retrieval... ";
        
        // Calculate health (which should store data)
        $this->health->calculate_health('stripe');
        
        // Retrieve stored health data
        $stored_health = $this->health->get_gateway_health('stripe');
        
        if (empty($stored_health)) {
            $this->fail("No health data was stored");
            return;
        }
        
        // Verify all periods are stored
        $expected_periods = ['1hour', '24hour', '7day'];
        foreach ($expected_periods as $period) {
            if (!isset($stored_health[$period])) {
                $this->fail("Missing stored health data for period: $period");
                return;
            }
        }
        
        // Test individual period retrieval
        $hour_health = $this->health->get_health_status('stripe', '1hour');
        if (!$hour_health || $hour_health->gateway_id !== 'stripe' || $hour_health->period !== '1hour') {
            $this->fail("Individual period retrieval failed");
            return;
        }
        
        $this->pass();
    }
    
    private function test_gateway_status_determination() {
        echo "Testing gateway status determination... ";
        
        // Create health data with different success rates
        $test_cases = [
            ['success_rate' => 95.0, 'expected_status' => 'healthy'],
            ['success_rate' => 80.0, 'expected_status' => 'degraded'],
            ['success_rate' => 60.0, 'expected_status' => 'critical']
        ];
        
        foreach ($test_cases as $index => $test_case) {
            $gateway_id = "test_gateway_$index";
            
            // Manually insert health data
            global $wpdb;
            $table_name = $this->database->get_gateway_health_table();
            
            $wpdb->insert($table_name, [
                'gateway_id' => $gateway_id,
                'period' => '24hour',
                'total_transactions' => 100,
                'successful_transactions' => intval($test_case['success_rate']),
                'failed_transactions' => 100 - intval($test_case['success_rate']),
                'success_rate' => $test_case['success_rate'],
                'calculated_at' => current_time('mysql')
            ]);
            
            // Test status determination
            $status = $this->health->get_gateway_status($gateway_id, '24hour');
            
            if ($status !== $test_case['expected_status']) {
                $this->fail("Expected status '{$test_case['expected_status']}' but got '$status' for success rate {$test_case['success_rate']}%");
                return;
            }
            
            // Test degraded check
            $is_degraded = $this->health->is_gateway_degraded($gateway_id, '24hour');
            $should_be_degraded = $test_case['success_rate'] < 85;
            
            if ($is_degraded !== $should_be_degraded) {
                $this->fail("Degraded check failed for success rate {$test_case['success_rate']}%");
                return;
            }
        }
        
        $this->pass();
    }
    
    private function test_empty_data_handling() {
        echo "Testing empty data handling... ";
        
        // Calculate health for a gateway with no transactions
        $health_data = $this->health->calculate_health('empty_gateway');
        
        foreach ($health_data as $period => $data) {
            if ($data['total_transactions'] !== 0 ||
                $data['successful_transactions'] !== 0 ||
                $data['failed_transactions'] !== 0 ||
                $data['success_rate'] !== 0.0) {
                
                $this->fail("Empty data not handled correctly for period: $period");
                return;
            }
        }
        
        // Test status for gateway with no data
        $status = $this->health->get_gateway_status('empty_gateway', '24hour');
        if ($status !== 'unknown') {
            $this->fail("Expected 'unknown' status for gateway with no data, got: $status");
            return;
        }
        
        $this->pass();
    }
    
    private function test_health_history_retrieval() {
        echo "Testing health history retrieval... ";
        
        // Create multiple health records for history
        global $wpdb;
        $table_name = $this->database->get_gateway_health_table();
        
        $history_data = [
            ['calculated_at' => date('Y-m-d H:i:s', time() - 86400), 'success_rate' => 90.0], // 1 day ago
            ['calculated_at' => date('Y-m-d H:i:s', time() - 43200), 'success_rate' => 85.0], // 12 hours ago
            ['calculated_at' => date('Y-m-d H:i:s', time() - 3600), 'success_rate' => 95.0],  // 1 hour ago
        ];
        
        foreach ($history_data as $data) {
            $wpdb->insert($table_name, [
                'gateway_id' => 'history_test',
                'period' => '24hour',
                'total_transactions' => 100,
                'successful_transactions' => intval($data['success_rate']),
                'failed_transactions' => 100 - intval($data['success_rate']),
                'success_rate' => $data['success_rate'],
                'calculated_at' => $data['calculated_at']
            ]);
        }
        
        // Retrieve history
        $history = $this->health->get_health_history('history_test', '24hour', 2);
        
        if (count($history) !== 3) {
            $this->fail("Expected 3 history records, got: " . count($history));
            return;
        }
        
        // Verify chronological order (oldest first)
        if ($history[0]['success_rate'] != 90.0 ||
            $history[1]['success_rate'] != 85.0 ||
            $history[2]['success_rate'] != 95.0) {
            
            $this->fail("History records not in correct chronological order");
            return;
        }
        
        $this->pass();
    }
    
    private function test_summary_statistics() {
        echo "Testing summary statistics calculation... ";
        
        // Create health data for multiple gateways
        global $wpdb;
        $table_name = $this->database->get_gateway_health_table();
        
        $gateways_data = [
            ['gateway_id' => 'summary_test_1', 'success_rate' => 95.0, 'total_transactions' => 100, 'successful_transactions' => 95],
            ['gateway_id' => 'summary_test_2', 'success_rate' => 85.0, 'total_transactions' => 200, 'successful_transactions' => 170],
            ['gateway_id' => 'summary_test_3', 'success_rate' => 75.0, 'total_transactions' => 150, 'successful_transactions' => 112]
        ];
        
        foreach ($gateways_data as $data) {
            $wpdb->insert($table_name, [
                'gateway_id' => $data['gateway_id'],
                'period' => '24hour',
                'total_transactions' => $data['total_transactions'],
                'successful_transactions' => $data['successful_transactions'],
                'failed_transactions' => $data['total_transactions'] - $data['successful_transactions'],
                'success_rate' => $data['success_rate'],
                'calculated_at' => current_time('mysql')
            ]);
        }
        
        // Get summary statistics
        $summary = $this->health->get_summary_stats('24hour');
        
        // Verify calculations
        $expected_total_gateways = 3;
        $expected_avg_success_rate = (95.0 + 85.0 + 75.0) / 3; // 85.0
        $expected_total_transactions = 100 + 200 + 150; // 450
        $expected_total_successful = 95 + 170 + 112; // 377
        $expected_overall_success_rate = round((377 / 450) * 100, 2); // 83.78
        
        if ($summary['total_gateways'] != $expected_total_gateways ||
            abs($summary['avg_success_rate'] - $expected_avg_success_rate) > 0.01 ||
            $summary['total_transactions'] != $expected_total_transactions ||
            $summary['total_successful'] != $expected_total_successful ||
            abs($summary['overall_success_rate'] - $expected_overall_success_rate) > 0.01) {
            
            $this->fail("Summary statistics calculation is incorrect");
            return;
        }
        
        $this->pass();
    }
    
    private function test_active_gateways_detection() {
        echo "Testing active gateways detection... ";
        
        // Test with WooCommerce gateways (mocked)
        $this->health->calculate_all_gateway_health();
        
        // Verify that health was calculated for configured gateways
        $stripe_health = $this->health->get_gateway_health('stripe');
        $paypal_health = $this->health->get_gateway_health('paypal');
        
        if (empty($stripe_health)) {
            $this->fail("Health not calculated for stripe gateway");
            return;
        }
        
        if (empty($paypal_health)) {
            $this->fail("Health not calculated for paypal gateway");
            return;
        }
        
        $this->pass();
    }
    
    /**
     * Create sample transaction data for testing
     */
    private function create_sample_transactions() {
        global $wpdb;
        $table_name = $this->database->get_transactions_table();
        
        $transactions = [
            // Recent transactions (within 1 hour)
            ['gateway_id' => 'stripe', 'status' => 'success', 'amount' => 100.00, 'created_at' => date('Y-m-d H:i:s', time() - 1800)], // 30 min ago
            ['gateway_id' => 'stripe', 'status' => 'success', 'amount' => 150.00, 'created_at' => date('Y-m-d H:i:s', time() - 900)],  // 15 min ago
            ['gateway_id' => 'stripe', 'status' => 'failed', 'amount' => 75.00, 'created_at' => date('Y-m-d H:i:s', time() - 600)],   // 10 min ago
            
            // Older transactions (within 24 hours)
            ['gateway_id' => 'stripe', 'status' => 'success', 'amount' => 200.00, 'created_at' => date('Y-m-d H:i:s', time() - 7200)], // 2 hours ago
            ['gateway_id' => 'stripe', 'status' => 'success', 'amount' => 300.00, 'created_at' => date('Y-m-d H:i:s', time() - 14400)], // 4 hours ago
            ['gateway_id' => 'stripe', 'status' => 'failed', 'amount' => 125.00, 'created_at' => date('Y-m-d H:i:s', time() - 21600)], // 6 hours ago
            
            // Very old transactions (within 7 days)
            ['gateway_id' => 'stripe', 'status' => 'success', 'amount' => 250.00, 'created_at' => date('Y-m-d H:i:s', time() - 172800)], // 2 days ago
            ['gateway_id' => 'stripe', 'status' => 'success', 'amount' => 175.00, 'created_at' => date('Y-m-d H:i:s', time() - 259200)], // 3 days ago
            
            // PayPal transactions
            ['gateway_id' => 'paypal', 'status' => 'success', 'amount' => 400.00, 'created_at' => date('Y-m-d H:i:s', time() - 1800)],
            ['gateway_id' => 'paypal', 'status' => 'success', 'amount' => 350.00, 'created_at' => date('Y-m-d H:i:s', time() - 7200)]
        ];
        
        foreach ($transactions as $transaction) {
            $wpdb->insert($table_name, [
                'order_id' => rand(1000, 9999),
                'gateway_id' => $transaction['gateway_id'],
                'transaction_id' => 'txn_' . rand(100000, 999999),
                'amount' => $transaction['amount'],
                'currency' => 'USD',
                'status' => $transaction['status'],
                'failure_reason' => $transaction['status'] === 'failed' ? 'Test failure' : null,
                'failure_code' => $transaction['status'] === 'failed' ? 'test_error' : null,
                'retry_count' => 0,
                'customer_email' => 'test@example.com',
                'customer_ip' => '192.168.1.1',
                'created_at' => $transaction['created_at'],
                'updated_at' => null
            ]);
        }
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
        echo "\n==================================\n";
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
echo "Starting Health Calculator Tests...\n";
$runner = new HealthTestRunner();
$runner->run_all_tests();