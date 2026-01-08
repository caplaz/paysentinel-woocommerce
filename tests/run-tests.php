<?php
/**
 * Simple test runner for database schema tests
 * This runs without requiring full WordPress environment
 */

// Include the database class
require_once __DIR__ . '/../includes/class-wc-payment-monitor-database.php';

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

// Mock wpdb
class MockWpdb {
    public $prefix = 'wp_';
    public $created_tables = [];
    public $table_structures = [];
    public $queries = [];
    
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
            }
        }
        
        return true;
    }
    
    public function get_var($sql) {
        if (preg_match('/SHOW TABLES LIKE\s+\'([^\']+)\'/', $sql, $matches)) {
            $table = $matches[1];
            return in_array($table, $this->created_tables) ? $table : null;
        }
        return null;
    }
    
    public function prepare($query, ...$args) {
        return vsprintf(str_replace('%s', "'%s'", $query), $args);
    }
}

// Set up global wpdb
$wpdb = new MockWpdb();

// Define constants
if (!defined('ABSPATH')) {
    define('ABSPATH', '/tmp/wordpress/');
}

/**
 * Test runner class
 */
class DatabaseSchemaTestRunner {
    
    private $tests_passed = 0;
    private $tests_failed = 0;
    private $database;
    
    public function __construct() {
        $this->database = new WC_Payment_Monitor_Database();
    }
    
    public function run_all_tests() {
        echo "Running Database Schema Tests...\n";
        echo "================================\n\n";
        
        $this->test_transactions_table_creation();
        $this->test_transactions_table_indexes();
        $this->test_gateway_health_table_creation();
        $this->test_gateway_health_table_indexes();
        $this->test_alerts_table_creation();
        $this->test_alerts_table_indexes();
        $this->test_database_version_set();
        $this->test_tables_exist_check();
        $this->test_drop_tables();
        $this->test_database_version_checking();
        
        $this->print_summary();
    }
    
    private function test_transactions_table_creation() {
        global $wpdb;
        
        echo "Testing transactions table creation... ";
        
        // Reset state
        $wpdb->created_tables = [];
        $wpdb->table_structures = [];
        
        // Create tables
        $this->database->create_tables();
        
        // Verify transactions table was created
        $expected_table = $wpdb->prefix . 'wc_payment_monitor_transactions';
        
        if (!in_array($expected_table, $wpdb->created_tables)) {
            $this->fail("Transactions table was not created");
            return;
        }
        
        // Verify table structure contains required columns
        $table_sql = $wpdb->table_structures[$expected_table];
        $required_columns = [
            'id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT',
            'order_id BIGINT(20) UNSIGNED NOT NULL',
            'gateway_id VARCHAR(50) NOT NULL',
            'transaction_id VARCHAR(100) DEFAULT NULL',
            'amount DECIMAL(10,2) NOT NULL',
            'currency VARCHAR(3) NOT NULL',
            "status ENUM('success', 'failed', 'pending', 'retry') NOT NULL",
            'failure_reason TEXT DEFAULT NULL',
            'failure_code VARCHAR(50) DEFAULT NULL',
            'retry_count TINYINT(3) UNSIGNED DEFAULT 0',
            'customer_email VARCHAR(100) DEFAULT NULL',
            'customer_ip VARCHAR(45) DEFAULT NULL',
            'created_at DATETIME NOT NULL',
            'updated_at DATETIME DEFAULT NULL'
        ];
        
        foreach ($required_columns as $column) {
            if (strpos($table_sql, $column) === false) {
                $this->fail("Missing column: $column");
                return;
            }
        }
        
        $this->pass();
    }
    
    private function test_transactions_table_indexes() {
        global $wpdb;
        
        echo "Testing transactions table indexes... ";
        
        $expected_table = $wpdb->prefix . 'wc_payment_monitor_transactions';
        $table_sql = $wpdb->table_structures[$expected_table];
        
        $required_indexes = [
            'PRIMARY KEY (id)',
            'KEY idx_order_id (order_id)',
            'KEY idx_gateway_id (gateway_id)',
            'KEY idx_status (status)',
            'KEY idx_created_at (created_at)',
            'KEY idx_gateway_status_created (gateway_id, status, created_at)'
        ];
        
        foreach ($required_indexes as $index) {
            if (strpos($table_sql, $index) === false) {
                $this->fail("Missing index: $index");
                return;
            }
        }
        
        $this->pass();
    }
    
    private function test_gateway_health_table_creation() {
        global $wpdb;
        
        echo "Testing gateway health table creation... ";
        
        $expected_table = $wpdb->prefix . 'wc_payment_monitor_gateway_health';
        
        if (!in_array($expected_table, $wpdb->created_tables)) {
            $this->fail("Gateway health table was not created");
            return;
        }
        
        $table_sql = $wpdb->table_structures[$expected_table];
        $required_columns = [
            'id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT',
            'gateway_id VARCHAR(50) NOT NULL',
            "period ENUM('1hour', '24hour', '7day') NOT NULL",
            'total_transactions INT(11) UNSIGNED DEFAULT 0',
            'successful_transactions INT(11) UNSIGNED DEFAULT 0',
            'failed_transactions INT(11) UNSIGNED DEFAULT 0',
            'success_rate DECIMAL(5,2) DEFAULT 0.00',
            'avg_response_time INT(11) UNSIGNED DEFAULT NULL',
            'last_failure_at DATETIME DEFAULT NULL',
            'calculated_at DATETIME NOT NULL'
        ];
        
        foreach ($required_columns as $column) {
            if (strpos($table_sql, $column) === false) {
                $this->fail("Missing column: $column");
                return;
            }
        }
        
        $this->pass();
    }
    
    private function test_gateway_health_table_indexes() {
        global $wpdb;
        
        echo "Testing gateway health table indexes... ";
        
        $expected_table = $wpdb->prefix . 'wc_payment_monitor_gateway_health';
        $table_sql = $wpdb->table_structures[$expected_table];
        
        $required_indexes = [
            'PRIMARY KEY (id)',
            'UNIQUE KEY idx_gateway_period (gateway_id, period)',
            'KEY idx_gateway_id (gateway_id)',
            'KEY idx_calculated_at (calculated_at)'
        ];
        
        foreach ($required_indexes as $index) {
            if (strpos($table_sql, $index) === false) {
                $this->fail("Missing index: $index");
                return;
            }
        }
        
        $this->pass();
    }
    
    private function test_alerts_table_creation() {
        global $wpdb;
        
        echo "Testing alerts table creation... ";
        
        $expected_table = $wpdb->prefix . 'wc_payment_monitor_alerts';
        
        if (!in_array($expected_table, $wpdb->created_tables)) {
            $this->fail("Alerts table was not created");
            return;
        }
        
        $table_sql = $wpdb->table_structures[$expected_table];
        $required_columns = [
            'id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT',
            "alert_type ENUM('gateway_down', 'low_success_rate', 'high_failure_count', 'gateway_error') NOT NULL",
            'gateway_id VARCHAR(50) NOT NULL',
            "severity ENUM('info', 'warning', 'critical') NOT NULL",
            'message TEXT NOT NULL',
            'metadata TEXT DEFAULT NULL',
            'is_resolved TINYINT(1) DEFAULT 0',
            'resolved_at DATETIME DEFAULT NULL',
            'notified_at DATETIME DEFAULT NULL',
            'created_at DATETIME NOT NULL'
        ];
        
        foreach ($required_columns as $column) {
            if (strpos($table_sql, $column) === false) {
                $this->fail("Missing column: $column");
                return;
            }
        }
        
        $this->pass();
    }
    
    private function test_alerts_table_indexes() {
        global $wpdb;
        
        echo "Testing alerts table indexes... ";
        
        $expected_table = $wpdb->prefix . 'wc_payment_monitor_alerts';
        $table_sql = $wpdb->table_structures[$expected_table];
        
        $required_indexes = [
            'PRIMARY KEY (id)',
            'KEY idx_gateway_id (gateway_id)',
            'KEY idx_alert_type (alert_type)',
            'KEY idx_severity (severity)',
            'KEY idx_is_resolved (is_resolved)',
            'KEY idx_created_at (created_at)'
        ];
        
        foreach ($required_indexes as $index) {
            if (strpos($table_sql, $index) === false) {
                $this->fail("Missing index: $index");
                return;
            }
        }
        
        $this->pass();
    }
    
    private function test_database_version_set() {
        echo "Testing database version is set... ";
        
        $version = get_option('wc_payment_monitor_db_version');
        if ($version !== '1.0.0') {
            $this->fail("Database version not set correctly. Expected: 1.0.0, Got: $version");
            return;
        }
        
        $this->pass();
    }
    
    private function test_tables_exist_check() {
        global $wpdb;
        
        echo "Testing tables exist check... ";
        
        // Tables should exist after creation
        if (!$this->database->tables_exist()) {
            $this->fail("tables_exist() returned false when tables should exist");
            return;
        }
        
        // Test with missing table
        $wpdb->created_tables = [];
        if ($this->database->tables_exist()) {
            $this->fail("tables_exist() returned true when tables should not exist");
            return;
        }
        
        $this->pass();
    }
    
    private function test_drop_tables() {
        global $wpdb;
        
        echo "Testing table dropping... ";
        
        // Create tables first
        $this->database->create_tables();
        
        if (!$this->database->tables_exist()) {
            $this->fail("Tables should exist before dropping");
            return;
        }
        
        // Drop tables
        $this->database->drop_tables();
        
        // Verify tables were dropped
        if ($this->database->tables_exist()) {
            $this->fail("Tables should not exist after dropping");
            return;
        }
        
        // Verify database version option was removed
        if ($this->database->get_db_version() !== '0.0.0') {
            $this->fail("Database version should be reset after dropping tables");
            return;
        }
        
        $this->pass();
    }
    
    private function test_database_version_checking() {
        echo "Testing database version checking... ";
        
        // Reset options
        delete_option('wc_payment_monitor_db_version');
        
        // Test initial state (no version set)
        if ($this->database->get_db_version() !== '0.0.0') {
            $this->fail("Initial database version should be 0.0.0");
            return;
        }
        
        if (!$this->database->needs_update()) {
            $this->fail("Database should need update when no version is set");
            return;
        }
        
        // Set current version
        update_option('wc_payment_monitor_db_version', '1.0.0');
        if ($this->database->get_db_version() !== '1.0.0') {
            $this->fail("Database version should be 1.0.0 after setting");
            return;
        }
        
        if ($this->database->needs_update()) {
            $this->fail("Database should not need update when version is current");
            return;
        }
        
        // Test older version
        update_option('wc_payment_monitor_db_version', '0.9.0');
        if (!$this->database->needs_update()) {
            $this->fail("Database should need update when version is older");
            return;
        }
        
        $this->pass();
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
        echo "\n================================\n";
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
$runner = new DatabaseSchemaTestRunner();
$runner->run_all_tests();