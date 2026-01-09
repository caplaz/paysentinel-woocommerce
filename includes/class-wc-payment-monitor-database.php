<?php
/**
 * Database management class
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class WC_Payment_Monitor_Database {
    
    /**
     * Database version
     */
    const DB_VERSION = '1.0.0';
    
    /**
     * Table names
     */
    private $transactions_table;
    private $gateway_health_table;
    private $alerts_table;
    
    /**
     * Constructor
     */
    public function __construct() {
        global $wpdb;
        
        $this->transactions_table = $wpdb->prefix . 'payment_monitor_transactions';
        $this->gateway_health_table = $wpdb->prefix . 'payment_monitor_gateway_health';
        $this->alerts_table = $wpdb->prefix . 'payment_monitor_alerts';
    }
    
    /**
     * Create all database tables
     */
    public function create_tables() {
        $this->create_transactions_table();
        $this->create_gateway_health_table();
        $this->create_alerts_table();
        
        // Update database version
        update_option('payment_monitor_db_version', self::DB_VERSION);
    }
    
    /**
     * Create transactions table
     */
    private function create_transactions_table() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE {$this->transactions_table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            order_id BIGINT(20) UNSIGNED NOT NULL,
            gateway_id VARCHAR(50) NOT NULL,
            transaction_id VARCHAR(100) DEFAULT NULL,
            amount DECIMAL(10,2) NOT NULL,
            currency VARCHAR(3) NOT NULL,
            status ENUM('success', 'failed', 'pending', 'retry') NOT NULL,
            failure_reason TEXT DEFAULT NULL,
            failure_code VARCHAR(50) DEFAULT NULL,
            retry_count TINYINT(3) UNSIGNED DEFAULT 0,
            customer_email VARCHAR(100) DEFAULT NULL,
            customer_ip VARCHAR(45) DEFAULT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME DEFAULT NULL,
            PRIMARY KEY (id),
            KEY idx_order_id (order_id),
            KEY idx_gateway_id (gateway_id),
            KEY idx_status (status),
            KEY idx_created_at (created_at),
            KEY idx_gateway_status_created (gateway_id, status, created_at)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
    
    /**
     * Create gateway health table
     */
    private function create_gateway_health_table() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE {$this->gateway_health_table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            gateway_id VARCHAR(50) NOT NULL,
            period ENUM('1hour', '24hour', '7day') NOT NULL,
            total_transactions INT(11) UNSIGNED DEFAULT 0,
            successful_transactions INT(11) UNSIGNED DEFAULT 0,
            failed_transactions INT(11) UNSIGNED DEFAULT 0,
            success_rate DECIMAL(5,2) DEFAULT 0.00,
            avg_response_time INT(11) UNSIGNED DEFAULT NULL,
            last_failure_at DATETIME DEFAULT NULL,
            calculated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY idx_gateway_period (gateway_id, period),
            KEY idx_gateway_id (gateway_id),
            KEY idx_calculated_at (calculated_at)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
    
    /**
     * Create alerts table
     */
    private function create_alerts_table() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE {$this->alerts_table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            alert_type ENUM('gateway_down', 'low_success_rate', 'high_failure_count', 'gateway_error') NOT NULL,
            gateway_id VARCHAR(50) NOT NULL,
            severity ENUM('info', 'warning', 'critical') NOT NULL,
            message TEXT NOT NULL,
            metadata TEXT DEFAULT NULL,
            is_resolved TINYINT(1) DEFAULT 0,
            resolved_at DATETIME DEFAULT NULL,
            notified_at DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY idx_gateway_id (gateway_id),
            KEY idx_alert_type (alert_type),
            KEY idx_severity (severity),
            KEY idx_is_resolved (is_resolved),
            KEY idx_created_at (created_at)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
    
    /**
     * Drop all database tables
     */
    public function drop_tables() {
        global $wpdb;
        
        $wpdb->query("DROP TABLE IF EXISTS {$this->transactions_table}");
        $wpdb->query("DROP TABLE IF EXISTS {$this->gateway_health_table}");
        $wpdb->query("DROP TABLE IF EXISTS {$this->alerts_table}");
        
        // Remove database version
        delete_option('payment_monitor_db_version');
    }
    
    /**
     * Get transactions table name
     */
    public function get_transactions_table() {
        return $this->transactions_table;
    }
    
    /**
     * Get gateway health table name
     */
    public function get_gateway_health_table() {
        return $this->gateway_health_table;
    }
    
    /**
     * Get alerts table name
     */
    public function get_alerts_table() {
        return $this->alerts_table;
    }
    
    /**
     * Check if tables exist
     */
    public function tables_exist() {
        global $wpdb;
        
        $tables = array(
            $this->transactions_table,
            $this->gateway_health_table,
            $this->alerts_table
        );
        
        foreach ($tables as $table) {
            $result = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
            if ($result !== $table) {
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * Get database version
     */
    public function get_db_version() {
        return get_option('payment_monitor_db_version', '0.0.0');
    }
    
    /**
     * Check if database needs update
     */
    public function needs_update() {
        return version_compare($this->get_db_version(), self::DB_VERSION, '<');
    }
}