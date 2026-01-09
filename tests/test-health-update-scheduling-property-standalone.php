<?php
/**
 * Standalone Property-based test for health update scheduling
 * 
 * **Feature: payment-monitor, Property 4: Health Update Scheduling**
 * **Validates: Requirements 2.2**
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
            'monitoring_interval' => 300, // Default 5 minutes
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

// Global variables to track cron scheduling
$mock_scheduled_events = [];
$mock_cron_schedules = [];
$mock_hooks = [];
$mock_filters = [];

// Mock WordPress action hooks
function add_action($hook, $callback, $priority = 10, $accepted_args = 1) {
    global $mock_hooks;
    if (!isset($mock_hooks[$hook])) {
        $mock_hooks[$hook] = [];
    }
    $mock_hooks[$hook][] = [
        'callback' => $callback,
        'priority' => $priority,
        'accepted_args' => $accepted_args
    ];
}

function add_filter($hook, $callback, $priority = 10, $accepted_args = 1) {
    global $mock_filters;
    if (!isset($mock_filters[$hook])) {
        $mock_filters[$hook] = [];
    }
    $mock_filters[$hook][] = [
        'callback' => $callback,
        'priority' => $priority,
        'accepted_args' => $accepted_args
    ];
}

function wp_next_scheduled($hook) {
    global $mock_scheduled_events;
    return isset($mock_scheduled_events[$hook]) ? $mock_scheduled_events[$hook]['timestamp'] : false;
}

function wp_schedule_event($timestamp, $recurrence, $hook, $args = []) {
    global $mock_scheduled_events;
    $mock_scheduled_events[$hook] = [
        'timestamp' => $timestamp,
        'recurrence' => $recurrence,
        'args' => $args
    ];
    return true;
}

function wp_clear_scheduled_hook($hook) {
    global $mock_scheduled_events;
    unset($mock_scheduled_events[$hook]);
    return true;
}

function do_action($hook, ...$args) {
    global $mock_hooks;
    if (isset($mock_hooks[$hook])) {
        foreach ($mock_hooks[$hook] as $hook_data) {
            $callback = $hook_data['callback'];
            if (is_array($callback) && count($callback) === 2) {
                $object = $callback[0];
                $method = $callback[1];
                if (is_object($object) && method_exists($object, $method)) {
                    call_user_func_array([$object, $method], $args);
                }
            } elseif (is_callable($callback)) {
                call_user_func_array($callback, $args);
            }
        }
    }
    return true;
}

function apply_filters($hook, $value, ...$args) {
    global $mock_filters;
    if (isset($mock_filters[$hook])) {
        foreach ($mock_filters[$hook] as $filter_data) {
            $callback = $filter_data['callback'];
            if (is_array($callback) && count($callback) === 2) {
                $object = $callback[0];
                $method = $callback[1];
                if (is_object($object) && method_exists($object, $method)) {
                    $value = call_user_func_array([$object, $method], array_merge([$value], $args));
                }
            } elseif (is_callable($callback)) {
                $value = call_user_func_array($callback, array_merge([$value], $args));
            }
        }
    }
    return $value;
}

// Note: Using PHP's built-in time() function

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
        // Handle aggregate queries for transaction stats
        if (preg_match('/COUNT\(\*\)\s+as\s+total_transactions/', $query)) {
            return $this->get_transaction_stats_mock($query);
        }
        
        return null;
    }
    
    public function get_results($query, $output = OBJECT) {
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
    
    /**
     * Mock transaction stats calculation
     */
    private function get_transaction_stats_mock($query) {
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
 * Property-based test runner for Health Update Scheduling
 */
class HealthUpdateSchedulingPropertyTest {
    
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
        echo "Running Health Update Scheduling Property Tests...\n";
        echo "=================================================\n\n";
        
        $this->test_health_update_scheduling_property();
        $this->test_cron_interval_configuration_property();
        $this->test_hook_registration_property();
        $this->test_scheduling_persistence_property();
        
        $this->print_summary();
    }
    
    /**
     * Property Test: Health Update Scheduling
     * 
     * For any monitoring interval configuration, the health calculation function 
     * should be invoked at the specified intervals via WordPress cron.
     * 
     * **Validates: Requirements 2.2**
     */
    public function test_health_update_scheduling_property() {
        echo "Testing health update scheduling property... ";
        
        $iterations = 100; // Minimum 100 iterations for property-based testing
        $failures = [];
        
        for ($i = 0; $i < $iterations; $i++) {
            try {
                $this->run_single_scheduling_test($i);
            } catch (Exception $e) {
                $failures[] = "Iteration $i: " . $e->getMessage();
            }
            
            // Clean up for next iteration
            $this->clean_up_scheduling();
        }
        
        if (!empty($failures)) {
            $this->fail("Property test failed in " . count($failures) . " iterations:\n" . implode("\n", array_slice($failures, 0, 5)));
            return;
        }
        
        $this->pass("Health update scheduling property holds for all $iterations iterations");
    }
    
    /**
     * Run a single iteration of the scheduling test
     */
    private function run_single_scheduling_test($iteration) {
        global $mock_scheduled_events, $mock_cron_schedules, $mock_hooks, $mock_filters;
        
        // Generate random monitoring interval (between 60 seconds and 3600 seconds)
        $monitoring_interval = rand(60, 3600);
        
        // Update settings with random interval
        $settings = get_option('wc_payment_monitor_settings', []);
        $settings['monitoring_interval'] = $monitoring_interval;
        update_option('wc_payment_monitor_settings', $settings);
        
        // Clear previous scheduling state
        $mock_scheduled_events = [];
        $mock_cron_schedules = [];
        $mock_hooks = [];
        $mock_filters = [];
        
        // Create new health instance to trigger scheduling
        $health = new WC_Payment_Monitor_Health();
        
        // Verify that the health calculation hook was registered
        if (!isset($mock_hooks['init'])) {
            throw new Exception("Init hook not registered in iteration $iteration");
        }
        
        // Verify that the health calculation action was registered
        if (!isset($mock_hooks['wc_payment_monitor_health_calculation'])) {
            throw new Exception("Health calculation action hook not registered in iteration $iteration");
        }
        
        // Simulate WordPress init action to trigger scheduling
        do_action('init');
        
        // Verify that the event was scheduled
        if (!isset($mock_scheduled_events['wc_payment_monitor_health_calculation'])) {
            throw new Exception("Health calculation event not scheduled in iteration $iteration");
        }
        
        $scheduled_event = $mock_scheduled_events['wc_payment_monitor_health_calculation'];
        
        // Verify the recurrence matches our custom interval
        if ($scheduled_event['recurrence'] !== 'wc_payment_monitor_interval') {
            throw new Exception("Incorrect recurrence schedule in iteration $iteration. Expected: wc_payment_monitor_interval, Got: {$scheduled_event['recurrence']}");
        }
        
        // Verify that the cron schedules filter was registered
        if (!isset($mock_filters['cron_schedules'])) {
            throw new Exception("Cron schedules filter not registered in iteration $iteration");
        }
        
        // Test the cron schedules filter
        $schedules = apply_filters('cron_schedules', []);
        
        if (!isset($schedules['wc_payment_monitor_interval'])) {
            throw new Exception("Custom cron interval not added in iteration $iteration");
        }
        
        $custom_schedule = $schedules['wc_payment_monitor_interval'];
        
        // Verify the interval matches our configuration
        if ($custom_schedule['interval'] !== $monitoring_interval) {
            throw new Exception("Custom cron interval mismatch in iteration $iteration. Expected: $monitoring_interval, Got: {$custom_schedule['interval']}");
        }
        
        // Verify the display name is correct
        $expected_display = sprintf('Every %d minutes', $monitoring_interval / 60);
        if ($custom_schedule['display'] !== $expected_display) {
            throw new Exception("Custom cron display name incorrect in iteration $iteration. Expected: '$expected_display', Got: '{$custom_schedule['display']}'");
        }
    }
    
    /**
     * Test cron interval configuration property
     */
    public function test_cron_interval_configuration_property() {
        echo "Testing cron interval configuration property... ";
        
        $iterations = 50;
        $failures = [];
        
        for ($i = 0; $i < $iterations; $i++) {
            try {
                global $mock_scheduled_events, $mock_cron_schedules, $mock_hooks, $mock_filters;
                
                // Test different interval configurations
                $test_intervals = [60, 120, 300, 600, 900, 1800, 3600]; // 1min to 1hour
                $interval = $test_intervals[array_rand($test_intervals)];
                
                // Update settings
                $settings = get_option('wc_payment_monitor_settings', []);
                $settings['monitoring_interval'] = $interval;
                update_option('wc_payment_monitor_settings', $settings);
                
                // Clear state
                $mock_scheduled_events = [];
                $mock_cron_schedules = [];
                $mock_hooks = [];
                $mock_filters = [];
                
                // Create health instance and trigger scheduling
                $health = new WC_Payment_Monitor_Health();
                do_action('init');
                
                // Test the cron schedules filter
                $schedules = apply_filters('cron_schedules', []);
                
                if (!isset($schedules['wc_payment_monitor_interval'])) {
                    throw new Exception("Custom cron interval not configured in iteration $i");
                }
                
                if ($schedules['wc_payment_monitor_interval']['interval'] !== $interval) {
                    throw new Exception("Cron interval configuration mismatch in iteration $i");
                }
                
                // Clean up
                $this->clean_up_scheduling();
                
            } catch (Exception $e) {
                $failures[] = "Iteration $i: " . $e->getMessage();
            }
        }
        
        if (!empty($failures)) {
            $this->fail("Cron interval configuration property failed in " . count($failures) . " iterations:\n" . implode("\n", array_slice($failures, 0, 3)));
            return;
        }
        
        $this->pass("Cron interval configuration property holds for all $iterations iterations");
    }
    
    /**
     * Test hook registration property
     */
    public function test_hook_registration_property() {
        echo "Testing hook registration property... ";
        
        $iterations = 30;
        $failures = [];
        
        for ($i = 0; $i < $iterations; $i++) {
            try {
                global $mock_hooks, $mock_filters;
                
                // Clear state
                $mock_hooks = [];
                $mock_filters = [];
                
                // Create health instance
                $health = new WC_Payment_Monitor_Health();
                
                // Verify required hooks are registered
                $required_hooks = [
                    'init',
                    'wc_payment_monitor_health_calculation',
                    'wc_payment_monitor_activated'
                ];
                
                foreach ($required_hooks as $hook) {
                    if (!isset($mock_hooks[$hook])) {
                        throw new Exception("Required hook '$hook' not registered in iteration $i");
                    }
                }
                
                // Verify required filters are registered
                $required_filters = [
                    'cron_schedules'
                ];
                
                foreach ($required_filters as $filter) {
                    if (!isset($mock_filters[$filter])) {
                        throw new Exception("Required filter '$filter' not registered in iteration $i");
                    }
                }
                
                // Verify hook callbacks are callable
                foreach ($mock_hooks['init'] as $hook_data) {
                    $callback = $hook_data['callback'];
                    if (is_array($callback) && count($callback) === 2) {
                        $object = $callback[0];
                        $method = $callback[1];
                        if (!is_object($object) || !method_exists($object, $method)) {
                            throw new Exception("Hook callback not callable in iteration $i");
                        }
                    }
                }
                
            } catch (Exception $e) {
                $failures[] = "Iteration $i: " . $e->getMessage();
            }
        }
        
        if (!empty($failures)) {
            $this->fail("Hook registration property failed in " . count($failures) . " iterations:\n" . implode("\n", array_slice($failures, 0, 3)));
            return;
        }
        
        $this->pass("Hook registration property holds for all $iterations iterations");
    }
    
    /**
     * Test scheduling persistence property
     */
    public function test_scheduling_persistence_property() {
        echo "Testing scheduling persistence property... ";
        
        $iterations = 20;
        $failures = [];
        
        for ($i = 0; $i < $iterations; $i++) {
            try {
                global $mock_scheduled_events;
                
                // Clear state
                $mock_scheduled_events = [];
                
                // Create health instance and schedule
                $health = new WC_Payment_Monitor_Health();
                do_action('init');
                
                // Verify event is scheduled
                if (!isset($mock_scheduled_events['wc_payment_monitor_health_calculation'])) {
                    throw new Exception("Event not initially scheduled in iteration $i");
                }
                
                // Try to schedule again (should not duplicate)
                do_action('init');
                
                // Should still have only one scheduled event
                $event_count = count(array_filter($mock_scheduled_events, function($key) {
                    return $key === 'wc_payment_monitor_health_calculation';
                }, ARRAY_FILTER_USE_KEY));
                
                if ($event_count !== 1) {
                    throw new Exception("Event scheduling not persistent/unique in iteration $i");
                }
                
                // Test activation hook
                do_action('wc_payment_monitor_activated');
                
                // Should still have the event scheduled
                if (!isset($mock_scheduled_events['wc_payment_monitor_health_calculation'])) {
                    throw new Exception("Event not scheduled after activation in iteration $i");
                }
                
            } catch (Exception $e) {
                $failures[] = "Iteration $i: " . $e->getMessage();
            }
        }
        
        if (!empty($failures)) {
            $this->fail("Scheduling persistence property failed in " . count($failures) . " iterations:\n" . implode("\n", array_slice($failures, 0, 3)));
            return;
        }
        
        $this->pass("Scheduling persistence property holds for all $iterations iterations");
    }
    
    /**
     * Clean up scheduling state
     */
    private function clean_up_scheduling() {
        global $mock_scheduled_events, $mock_cron_schedules, $mock_hooks, $mock_filters;
        
        $mock_scheduled_events = [];
        $mock_cron_schedules = [];
        $mock_hooks = [];
        $mock_filters = [];
        
        // Reset settings to default
        $settings = [
            'enabled_gateways' => ['stripe', 'paypal'],
            'alert_email' => 'admin@example.com',
            'alert_threshold' => 85,
            'monitoring_interval' => 300, // Default 5 minutes
            'enable_auto_retry' => true,
            'retry_schedule' => [3600, 21600, 86400],
            'alert_phone' => '',
            'slack_webhook' => ''
        ];
        update_option('wc_payment_monitor_settings', $settings);
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
        echo "\n=================================================\n";
        echo "Property Test Summary:\n";
        echo "Passed: {$this->tests_passed}\n";
        echo "Failed: {$this->tests_failed}\n";
        echo "Total:  " . ($this->tests_passed + $this->tests_failed) . "\n";
        
        if ($this->tests_failed > 0) {
            echo "\nSome property tests failed!\n";
            exit(1);
        } else {
            echo "\nAll property tests passed!\n";
            echo "\n**Feature: payment-monitor, Property 4: Health Update Scheduling**\n";
            echo "**Validates: Requirements 2.2**\n";
            echo "\nProperty verified: For any monitoring interval configuration,\n";
            echo "the health calculation function is invoked at the specified intervals via WordPress cron.\n";
            exit(0);
        }
    }
}

// Run the property-based tests
echo "Starting Health Update Scheduling Property Tests...\n";
echo "PHP Version: " . PHP_VERSION . "\n";
echo "Current working directory: " . getcwd() . "\n";
echo "File exists check: " . (file_exists(__DIR__ . '/../includes/class-wc-payment-monitor-health.php') ? 'YES' : 'NO') . "\n";

try {
    $runner = new HealthUpdateSchedulingPropertyTest();
    echo "Runner created successfully\n";
    $runner->run_all_tests();
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo "Stack trace: " . $e->getTraceAsString() . "\n";
} catch (Error $e) {
    echo "Fatal Error: " . $e->getMessage() . "\n";
    echo "Stack trace: " . $e->getTraceAsString() . "\n";
}