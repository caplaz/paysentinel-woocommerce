<?php
/**
 * Unit tests for database schema creation
 * 
 * Tests table creation and index setup
 * Tests plugin activation/deactivation
 * Requirements: 6.1
 */

require_once __DIR__ . '/includes/class-wc-payment-monitor-test-case.php';

class Test_Database_Schema extends WC_Payment_Monitor_Test_Case {
    
    /**
     * Database instance
     */
    private $database;
    
    /**
     * Set up test environment
     */
    public function set_up() {
        parent::set_up();
        
        // Mock WordPress database functions
        $this->mock_wordpress_database_functions();
        
        // Create database instance
        $this->database = new WC_Payment_Monitor_Database();
    }
    
    /**
     * Test transactions table creation
     */
    public function test_transactions_table_creation() {
        global $wpdb;
        
        // Mock wpdb for testing
        $wpdb = $this->create_test_wpdb();
        
        // Create tables
        $this->database->create_tables();
        
        // Verify transactions table was created with correct structure
        $expected_table = $wpdb->prefix . 'wc_payment_monitor_transactions';
        $this->assertContains($expected_table, $wpdb->created_tables);
        
        // Verify table structure contains required columns
        $table_sql = $wpdb->table_structures[$expected_table];
        $this->assertStringContainsString('id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT', $table_sql);
        $this->assertStringContainsString('order_id BIGINT(20) UNSIGNED NOT NULL', $table_sql);
        $this->assertStringContainsString('gateway_id VARCHAR(50) NOT NULL', $table_sql);
        $this->assertStringContainsString('transaction_id VARCHAR(100) DEFAULT NULL', $table_sql);
        $this->assertStringContainsString('amount DECIMAL(10,2) NOT NULL', $table_sql);
        $this->assertStringContainsString('currency VARCHAR(3) NOT NULL', $table_sql);
        $this->assertStringContainsString("status ENUM('success', 'failed', 'pending', 'retry') NOT NULL", $table_sql);
        $this->assertStringContainsString('failure_reason TEXT DEFAULT NULL', $table_sql);
        $this->assertStringContainsString('failure_code VARCHAR(50) DEFAULT NULL', $table_sql);
        $this->assertStringContainsString('retry_count TINYINT(3) UNSIGNED DEFAULT 0', $table_sql);
        $this->assertStringContainsString('customer_email VARCHAR(100) DEFAULT NULL', $table_sql);
        $this->assertStringContainsString('customer_ip VARCHAR(45) DEFAULT NULL', $table_sql);
        $this->assertStringContainsString('created_at DATETIME NOT NULL', $table_sql);
        $this->assertStringContainsString('updated_at DATETIME DEFAULT NULL', $table_sql);
    }
    
    /**
     * Test transactions table indexes
     */
    public function test_transactions_table_indexes() {
        global $wpdb;
        
        // Mock wpdb for testing
        $wpdb = $this->create_test_wpdb();
        
        // Create tables
        $this->database->create_tables();
        
        // Verify indexes are created
        $expected_table = $wpdb->prefix . 'wc_payment_monitor_transactions';
        $table_sql = $wpdb->table_structures[$expected_table];
        
        $this->assertStringContainsString('PRIMARY KEY (id)', $table_sql);
        $this->assertStringContainsString('KEY idx_order_id (order_id)', $table_sql);
        $this->assertStringContainsString('KEY idx_gateway_id (gateway_id)', $table_sql);
        $this->assertStringContainsString('KEY idx_status (status)', $table_sql);
        $this->assertStringContainsString('KEY idx_created_at (created_at)', $table_sql);
        $this->assertStringContainsString('KEY idx_gateway_status_created (gateway_id, status, created_at)', $table_sql);
    }
    
    /**
     * Test gateway health table creation
     */
    public function test_gateway_health_table_creation() {
        global $wpdb;
        
        // Mock wpdb for testing
        $wpdb = $this->create_test_wpdb();
        
        // Create tables
        $this->database->create_tables();
        
        // Verify gateway health table was created
        $expected_table = $wpdb->prefix . 'wc_payment_monitor_gateway_health';
        $this->assertContains($expected_table, $wpdb->created_tables);
        
        // Verify table structure
        $table_sql = $wpdb->table_structures[$expected_table];
        $this->assertStringContainsString('id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT', $table_sql);
        $this->assertStringContainsString('gateway_id VARCHAR(50) NOT NULL', $table_sql);
        $this->assertStringContainsString("period ENUM('1hour', '24hour', '7day') NOT NULL", $table_sql);
        $this->assertStringContainsString('total_transactions INT(11) UNSIGNED DEFAULT 0', $table_sql);
        $this->assertStringContainsString('successful_transactions INT(11) UNSIGNED DEFAULT 0', $table_sql);
        $this->assertStringContainsString('failed_transactions INT(11) UNSIGNED DEFAULT 0', $table_sql);
        $this->assertStringContainsString('success_rate DECIMAL(5,2) DEFAULT 0.00', $table_sql);
        $this->assertStringContainsString('avg_response_time INT(11) UNSIGNED DEFAULT NULL', $table_sql);
        $this->assertStringContainsString('last_failure_at DATETIME DEFAULT NULL', $table_sql);
        $this->assertStringContainsString('calculated_at DATETIME NOT NULL', $table_sql);
    }
    
    /**
     * Test gateway health table indexes
     */
    public function test_gateway_health_table_indexes() {
        global $wpdb;
        
        // Mock wpdb for testing
        $wpdb = $this->create_test_wpdb();
        
        // Create tables
        $this->database->create_tables();
        
        // Verify indexes
        $expected_table = $wpdb->prefix . 'wc_payment_monitor_gateway_health';
        $table_sql = $wpdb->table_structures[$expected_table];
        
        $this->assertStringContainsString('PRIMARY KEY (id)', $table_sql);
        $this->assertStringContainsString('UNIQUE KEY idx_gateway_period (gateway_id, period)', $table_sql);
        $this->assertStringContainsString('KEY idx_gateway_id (gateway_id)', $table_sql);
        $this->assertStringContainsString('KEY idx_calculated_at (calculated_at)', $table_sql);
    }
    
    /**
     * Test alerts table creation
     */
    public function test_alerts_table_creation() {
        global $wpdb;
        
        // Mock wpdb for testing
        $wpdb = $this->create_test_wpdb();
        
        // Create tables
        $this->database->create_tables();
        
        // Verify alerts table was created
        $expected_table = $wpdb->prefix . 'wc_payment_monitor_alerts';
        $this->assertContains($expected_table, $wpdb->created_tables);
        
        // Verify table structure
        $table_sql = $wpdb->table_structures[$expected_table];
        $this->assertStringContainsString('id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT', $table_sql);
        $this->assertStringContainsString("alert_type ENUM('gateway_down', 'low_success_rate', 'high_failure_count', 'gateway_error') NOT NULL", $table_sql);
        $this->assertStringContainsString('gateway_id VARCHAR(50) NOT NULL', $table_sql);
        $this->assertStringContainsString("severity ENUM('info', 'warning', 'critical') NOT NULL", $table_sql);
        $this->assertStringContainsString('message TEXT NOT NULL', $table_sql);
        $this->assertStringContainsString('metadata TEXT DEFAULT NULL', $table_sql);
        $this->assertStringContainsString('is_resolved TINYINT(1) DEFAULT 0', $table_sql);
        $this->assertStringContainsString('resolved_at DATETIME DEFAULT NULL', $table_sql);
        $this->assertStringContainsString('notified_at DATETIME DEFAULT NULL', $table_sql);
        $this->assertStringContainsString('created_at DATETIME NOT NULL', $table_sql);
    }
    
    /**
     * Test alerts table indexes
     */
    public function test_alerts_table_indexes() {
        global $wpdb;
        
        // Mock wpdb for testing
        $wpdb = $this->create_test_wpdb();
        
        // Create tables
        $this->database->create_tables();
        
        // Verify indexes
        $expected_table = $wpdb->prefix . 'wc_payment_monitor_alerts';
        $table_sql = $wpdb->table_structures[$expected_table];
        
        $this->assertStringContainsString('PRIMARY KEY (id)', $table_sql);
        $this->assertStringContainsString('KEY idx_gateway_id (gateway_id)', $table_sql);
        $this->assertStringContainsString('KEY idx_alert_type (alert_type)', $table_sql);
        $this->assertStringContainsString('KEY idx_severity (severity)', $table_sql);
        $this->assertStringContainsString('KEY idx_is_resolved (is_resolved)', $table_sql);
        $this->assertStringContainsString('KEY idx_created_at (created_at)', $table_sql);
    }
    
    /**
     * Test database version is set after table creation
     */
    public function test_database_version_set() {
        global $wpdb;
        
        // Mock wpdb for testing
        $wpdb = $this->create_test_wpdb();
        
        // Create tables
        $this->database->create_tables();
        
        // Verify database version was set
        $this->assertEquals('1.0.0', get_option('wc_payment_monitor_db_version'));
    }
    
    /**
     * Test table existence check
     */
    public function test_tables_exist_check() {
        global $wpdb;
        
        // Mock wpdb for testing
        $wpdb = $this->create_test_wpdb();
        
        // Initially tables should not exist
        $this->assertFalse($this->database->tables_exist());
        
        // Create tables
        $this->database->create_tables();
        
        // Now tables should exist
        $this->assertTrue($this->database->tables_exist());
    }
    
    /**
     * Test table dropping
     */
    public function test_drop_tables() {
        global $wpdb;
        
        // Mock wpdb for testing
        $wpdb = $this->create_test_wpdb();
        
        // Create tables first
        $this->database->create_tables();
        $this->assertTrue($this->database->tables_exist());
        
        // Drop tables
        $this->database->drop_tables();
        
        // Verify tables were dropped
        $this->assertFalse($this->database->tables_exist());
        
        // Verify database version option was removed
        $this->assertEquals('0.0.0', $this->database->get_db_version());
    }
    
    /**
     * Test plugin activation creates tables
     */
    public function test_plugin_activation_creates_tables() {
        global $wpdb;
        
        // Mock wpdb for testing
        $wpdb = $this->create_test_wpdb();
        
        // Mock WordPress functions for activation
        $this->mock_activation_functions();
        
        // Create plugin instance
        $plugin = WC_Payment_Monitor::get_instance();
        
        // Trigger activation
        $plugin->activate();
        
        // Verify tables were created
        $expected_tables = [
            $wpdb->prefix . 'wc_payment_monitor_transactions',
            $wpdb->prefix . 'wc_payment_monitor_gateway_health',
            $wpdb->prefix . 'wc_payment_monitor_alerts'
        ];
        
        foreach ($expected_tables as $table) {
            $this->assertContains($table, $wpdb->created_tables);
        }
        
        // Verify plugin version was set
        $this->assertEquals('1.0.0', get_option('wc_payment_monitor_version'));
        
        // Verify default settings were created
        $settings = get_option('wc_payment_monitor_settings');
        $this->assertIsArray($settings);
        $this->assertEquals(85, $settings['alert_threshold']);
        $this->assertEquals(300, $settings['monitoring_interval']);
        $this->assertTrue($settings['enable_auto_retry']);
    }
    
    /**
     * Test plugin deactivation clears scheduled events
     */
    public function test_plugin_deactivation() {
        // Mock WordPress cron functions
        $cleared_hooks = [];
        
        if (!function_exists('wp_clear_scheduled_hook')) {
            function wp_clear_scheduled_hook($hook) {
                global $cleared_hooks;
                $cleared_hooks[] = $hook;
            }
        }
        
        // Create plugin instance
        $plugin = WC_Payment_Monitor::get_instance();
        
        // Trigger deactivation
        $plugin->deactivate();
        
        // Verify scheduled hooks were cleared
        $this->assertContains('wc_payment_monitor_health_calculation', $cleared_hooks);
        $this->assertContains('wc_payment_monitor_retry_payments', $cleared_hooks);
    }
    
    /**
     * Test plugin uninstall removes everything
     */
    public function test_plugin_uninstall() {
        global $wpdb;
        
        // Mock wpdb for testing
        $wpdb = $this->create_test_wpdb();
        
        // Mock WordPress cron functions
        $cleared_hooks = [];
        
        if (!function_exists('wp_clear_scheduled_hook')) {
            function wp_clear_scheduled_hook($hook) {
                global $cleared_hooks;
                $cleared_hooks[] = $hook;
            }
        }
        
        // Create tables and options first
        $database = new WC_Payment_Monitor_Database();
        $database->create_tables();
        update_option('wc_payment_monitor_version', '1.0.0');
        update_option('wc_payment_monitor_settings', ['test' => 'value']);
        
        // Trigger uninstall
        WC_Payment_Monitor::uninstall();
        
        // Verify tables were dropped
        $this->assertFalse($database->tables_exist());
        
        // Verify options were removed
        $this->assertFalse(get_option('wc_payment_monitor_version'));
        $this->assertFalse(get_option('wc_payment_monitor_settings'));
        
        // Verify scheduled hooks were cleared
        $this->assertContains('wc_payment_monitor_health_calculation', $cleared_hooks);
        $this->assertContains('wc_payment_monitor_retry_payments', $cleared_hooks);
    }
    
    /**
     * Test database version checking
     */
    public function test_database_version_checking() {
        // Test initial state (no version set)
        $this->assertEquals('0.0.0', $this->database->get_db_version());
        $this->assertTrue($this->database->needs_update());
        
        // Set version
        update_option('wc_payment_monitor_db_version', '1.0.0');
        $this->assertEquals('1.0.0', $this->database->get_db_version());
        $this->assertFalse($this->database->needs_update());
        
        // Test older version
        update_option('wc_payment_monitor_db_version', '0.9.0');
        $this->assertTrue($this->database->needs_update());
    }
    
    /**
     * Create a test wpdb mock object
     */
    private function create_test_wpdb() {
        $wpdb = new stdClass();
        $wpdb->prefix = 'wp_';
        $wpdb->created_tables = [];
        $wpdb->table_structures = [];
        $wpdb->queries = [];
        
        // Mock get_charset_collate
        $wpdb->get_charset_collate = function() {
            return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
        };
        
        // Mock query method to track table creation
        $wpdb->query = function($sql) use ($wpdb) {
            $wpdb->queries[] = $sql;
            
            // Track DROP TABLE queries
            if (preg_match('/DROP TABLE IF EXISTS\s+(\S+)/', $sql, $matches)) {
                $table = $matches[1];
                $key = array_search($table, $wpdb->created_tables);
                if ($key !== false) {
                    unset($wpdb->created_tables[$key]);
                    unset($wpdb->table_structures[$table]);
                }
            }
            
            return true;
        };
        
        // Mock get_var for table existence checks
        $wpdb->get_var = function($sql) use ($wpdb) {
            if (preg_match('/SHOW TABLES LIKE\s+\'([^\']+)\'/', $sql, $matches)) {
                $table = $matches[1];
                return in_array($table, $wpdb->created_tables) ? $table : null;
            }
            return null;
        };
        
        // Mock prepare method
        $wpdb->prepare = function($query, ...$args) {
            return vsprintf(str_replace('%s', "'%s'", $query), $args);
        };
        
        return $wpdb;
    }
    
    /**
     * Mock WordPress database functions
     */
    private function mock_wordpress_database_functions() {
        // Mock dbDelta function
        if (!function_exists('dbDelta')) {
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
        }
        
        // Mock ABSPATH constant
        if (!defined('ABSPATH')) {
            define('ABSPATH', '/tmp/wordpress/');
        }
    }
    
    /**
     * Mock WordPress activation functions
     */
    private function mock_activation_functions() {
        if (!function_exists('deactivate_plugins')) {
            function deactivate_plugins($plugins) {
                // Mock function
            }
        }
        
        if (!function_exists('wp_die')) {
            function wp_die($message) {
                throw new Exception($message);
            }
        }
        
        if (!function_exists('wp_clear_scheduled_hook')) {
            function wp_clear_scheduled_hook($hook) {
                global $cleared_hooks;
                if (!isset($cleared_hooks)) {
                    $cleared_hooks = [];
                }
                $cleared_hooks[] = $hook;
            }
        }
        
        if (!class_exists('WooCommerce')) {
            // Mock WooCommerce class existence
            eval('class WooCommerce {}');
        }
        
        if (!defined('WC_VERSION')) {
            define('WC_VERSION', '5.0.0');
        }
    }
}