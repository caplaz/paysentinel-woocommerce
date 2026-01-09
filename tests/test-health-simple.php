<?php
echo "Starting simple health test...\n";

// Include required classes
require_once __DIR__ . '/../includes/class-wc-payment-monitor-database.php';
require_once __DIR__ . '/../includes/class-wc-payment-monitor-logger.php';
require_once __DIR__ . '/../includes/class-wc-payment-monitor-health.php';

echo "Classes loaded successfully\n";

// Mock basic WordPress functions
function get_option($option, $default = false) {
    return $default;
}

function current_time($type) {
    return date('Y-m-d H:i:s');
}

function add_action($hook, $callback, $priority = 10, $accepted_args = 1) {
    return true;
}

function add_filter($hook, $callback, $priority = 10, $accepted_args = 1) {
    return true;
}

function wp_next_scheduled($hook) {
    return false;
}

function wp_schedule_event($timestamp, $recurrence, $hook, $args = []) {
    return true;
}

function do_action($hook, ...$args) {
    return true;
}

function __($text, $domain = '') {
    return $text;
}

function dbDelta($sql) {
    return ['created' => 1];
}

// Mock wpdb
class MockWpdb {
    public $prefix = 'wp_';
    public $insert_id = 1;
    
    public function get_charset_collate() {
        return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
    }
    
    public function query($sql) {
        return true;
    }
    
    public function insert($table, $data, $format = null) {
        return 1;
    }
    
    public function update($table, $data, $where, $format = null, $where_format = null) {
        return 1;
    }
    
    public function get_row($query, $output = OBJECT) {
        return null;
    }
    
    public function get_results($query, $output = OBJECT) {
        return [];
    }
    
    public function get_var($sql) {
        return null;
    }
    
    public function prepare($query, ...$args) {
        return $query;
    }
}

$wpdb = new MockWpdb();

if (!defined('ABSPATH')) {
    define('ABSPATH', '/tmp/wordpress/');
}

echo "Setting up test environment...\n";

try {
    $database = new WC_Payment_Monitor_Database();
    echo "Database class created\n";
    
    $logger = new WC_Payment_Monitor_Logger();
    echo "Logger class created\n";
    
    $health = new WC_Payment_Monitor_Health();
    echo "Health class created\n";
    
    // Test basic functionality
    $periods = $health::PERIODS;
    echo "Health periods: " . implode(', ', array_keys($periods)) . "\n";
    
    echo "All tests completed successfully!\n";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}