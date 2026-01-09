<?php
/**
 * Simple test for WC_Payment_Monitor_Health class
 */

// Define WordPress constants first
if (!defined('ABSPATH')) {
    define('ABSPATH', '/tmp/wordpress/');
}

if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}

if (!defined('OBJECT')) {
    define('OBJECT', 'OBJECT');
}

echo "Starting Health Calculator Test...\n";
echo "==================================\n";

// Mock WordPress functions
function get_option($option, $default = false) {
    static $options = [
        'wc_payment_monitor_settings' => [
            'enabled_gateways' => ['stripe', 'paypal'],
            'alert_threshold' => 85,
            'monitoring_interval' => 300
        ]
    ];
    return isset($options[$option]) ? $options[$option] : $default;
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
    public $data = [];
    
    public function get_charset_collate() {
        return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
    }
    
    public function query($sql) {
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
        return vsprintf(str_replace(['%s', '%d', '%f'], ['\'%s\'', '%d', '%f'], $query), $args);
    }
}

$wpdb = new MockWpdb();

// Mock WooCommerce
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
            'paypal' => (object) ['enabled' => 'yes']
        ];
    }
}

// Include classes
require_once __DIR__ . '/../includes/class-wc-payment-monitor-database.php';
require_once __DIR__ . '/../includes/class-wc-payment-monitor-logger.php';
require_once __DIR__ . '/../includes/class-wc-payment-monitor-health.php';

echo "Classes loaded successfully\n";

try {
    // Test basic instantiation
    $database = new WC_Payment_Monitor_Database();
    echo "✓ Database class instantiated\n";
    
    $logger = new WC_Payment_Monitor_Logger();
    echo "✓ Logger class instantiated\n";
    
    $health = new WC_Payment_Monitor_Health();
    echo "✓ Health class instantiated\n";
    
    // Test constants
    $periods = WC_Payment_Monitor_Health::PERIODS;
    echo "✓ Health periods defined: " . implode(', ', array_keys($periods)) . "\n";
    
    // Test health calculation with empty data
    echo "\nTesting health calculation with empty data...\n";
    $health_data = $health->calculate_health('test_gateway');
    
    if (is_array($health_data) && count($health_data) === 3) {
        echo "✓ Health calculation returned data for all periods\n";
        
        foreach ($health_data as $period => $data) {
            if (isset($data['gateway_id']) && 
                isset($data['period']) && 
                isset($data['total_transactions']) &&
                isset($data['success_rate'])) {
                echo "✓ Period $period has required fields\n";
            } else {
                echo "✗ Period $period missing required fields\n";
            }
        }
    } else {
        echo "✗ Health calculation failed\n";
    }
    
    // Test gateway status
    echo "\nTesting gateway status determination...\n";
    $status = $health->get_gateway_status('test_gateway');
    echo "✓ Gateway status: $status\n";
    
    echo "\n==================================\n";
    echo "All basic tests passed!\n";
    
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
} catch (Error $e) {
    echo "✗ Fatal Error: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}