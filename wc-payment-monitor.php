<?php
/**
 * Plugin Name: WooCommerce Payment Monitor
 * Plugin URI: https://github.com/your-username/wc-payment-monitor
 * Description: Real-time monitoring, alerting, and recovery capabilities for WooCommerce payment gateway failures.
 * Version: 1.0.0
 * Author: Your Name
 * Author URI: https://yourwebsite.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wc-payment-monitor
 * Domain Path: /languages
 * Requires at least: 5.0
 * Tested up to: 6.4
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 * WC tested up to: 8.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('WC_PAYMENT_MONITOR_VERSION', '1.0.0');
define('WC_PAYMENT_MONITOR_PLUGIN_FILE', __FILE__);
define('WC_PAYMENT_MONITOR_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WC_PAYMENT_MONITOR_PLUGIN_URL', plugin_dir_url(__FILE__));
define('WC_PAYMENT_MONITOR_PLUGIN_BASENAME', plugin_basename(__FILE__));

/**
 * Main plugin class
 */
class WC_Payment_Monitor {
    
    /**
     * Plugin instance
     */
    private static $instance = null;
    
    /**
     * Get plugin instance
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Constructor
     */
    private function __construct() {
        $this->init_hooks();
        $this->load_dependencies();
    }
    
    /**
     * Initialize hooks
     */
    private function init_hooks() {
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
        register_uninstall_hook(__FILE__, array('WC_Payment_Monitor', 'uninstall'));
        
        add_action('plugins_loaded', array($this, 'init'));
    }
    
    /**
     * Load plugin dependencies
     */
    private function load_dependencies() {
        // Autoloader
        spl_autoload_register(array($this, 'autoload'));
        
        // Load core classes
        require_once WC_PAYMENT_MONITOR_PLUGIN_DIR . 'includes/class-wc-payment-monitor-database.php';
        require_once WC_PAYMENT_MONITOR_PLUGIN_DIR . 'includes/class-wc-payment-monitor-logger.php';
        require_once WC_PAYMENT_MONITOR_PLUGIN_DIR . 'includes/class-wc-payment-monitor-health.php';
        require_once WC_PAYMENT_MONITOR_PLUGIN_DIR . 'includes/class-wc-payment-monitor-alerts.php';
        require_once WC_PAYMENT_MONITOR_PLUGIN_DIR . 'includes/class-wc-payment-monitor-retry.php';
        require_once WC_PAYMENT_MONITOR_PLUGIN_DIR . 'includes/class-wc-payment-monitor-security.php';
        
        // Load API classes
        require_once WC_PAYMENT_MONITOR_PLUGIN_DIR . 'includes/class-wc-payment-monitor-api-base.php';
        require_once WC_PAYMENT_MONITOR_PLUGIN_DIR . 'includes/class-wc-payment-monitor-api-health.php';
        require_once WC_PAYMENT_MONITOR_PLUGIN_DIR . 'includes/class-wc-payment-monitor-api-transactions.php';
    }
    
    /**
     * Autoloader for plugin classes
     */
    public function autoload($class_name) {
        if (strpos($class_name, 'WC_Payment_Monitor_') !== 0) {
            return;
        }
        
        $class_file = strtolower(str_replace('_', '-', $class_name));
        $class_file = str_replace('wc-payment-monitor-', '', $class_file);
        $file_path = WC_PAYMENT_MONITOR_PLUGIN_DIR . 'includes/class-wc-payment-monitor-' . $class_file . '.php';
        
        if (file_exists($file_path)) {
            require_once $file_path;
        }
    }
    
    /**
     * Initialize plugin
     */
    public function init() {
        // Check if WooCommerce is active
        if (!class_exists('WooCommerce')) {
            add_action('admin_notices', array($this, 'woocommerce_missing_notice'));
            return;
        }
        
        // Load text domain
        load_plugin_textdomain('wc-payment-monitor', false, dirname(plugin_basename(__FILE__)) . '/languages');
        
        // Initialize components
        $this->init_components();
    }
    
    /**
     * Initialize plugin components
     */
    private function init_components() {
        // Initialize transaction logger
        new WC_Payment_Monitor_Logger();
        
        // Initialize health calculation engine
        new WC_Payment_Monitor_Health();
        
        // Initialize alert system
        new WC_Payment_Monitor_Alerts();
        
        // Initialize retry engine
        new WC_Payment_Monitor_Retry();
        
        // Initialize REST API endpoints
        new WC_Payment_Monitor_API_Health();
        new WC_Payment_Monitor_API_Transactions();
    }
    
    /**
     * Plugin activation
     */
    public function activate() {
        // Check WordPress and WooCommerce versions
        if (!$this->check_requirements()) {
            deactivate_plugins(plugin_basename(__FILE__));
            wp_die(__('WooCommerce Payment Monitor requires WordPress 5.0+ and WooCommerce 5.0+', 'wc-payment-monitor'));
        }
        
        // Create database tables
        $database = new WC_Payment_Monitor_Database();
        $database->create_tables();
        
        // Set plugin version
        update_option('wc_payment_monitor_version', WC_PAYMENT_MONITOR_VERSION);
        
        // Set default options
        $this->set_default_options();
        
        // Trigger health calculation scheduling
        do_action('wc_payment_monitor_activated');
    }
    
    /**
     * Plugin deactivation
     */
    public function deactivate() {
        // Clear scheduled events
        wp_clear_scheduled_hook('wc_payment_monitor_health_calculation');
        wp_clear_scheduled_hook('wc_payment_monitor_retry_payments');
        wp_clear_scheduled_hook('wc_payment_monitor_process_retries');
    }
    
    /**
     * Plugin uninstall
     */
    public static function uninstall() {
        // Remove database tables
        $database = new WC_Payment_Monitor_Database();
        $database->drop_tables();
        
        // Remove options
        delete_option('wc_payment_monitor_version');
        delete_option('wc_payment_monitor_settings');
        delete_option('wc_payment_monitor_retry_stats');
        
        // Clear scheduled events
        wp_clear_scheduled_hook('wc_payment_monitor_health_calculation');
        wp_clear_scheduled_hook('wc_payment_monitor_retry_payments');
        wp_clear_scheduled_hook('wc_payment_monitor_process_retries');
    }
    
    /**
     * Check plugin requirements
     */
    private function check_requirements() {
        global $wp_version;
        
        if (version_compare($wp_version, '5.0', '<')) {
            return false;
        }
        
        if (!class_exists('WooCommerce')) {
            return false;
        }
        
        if (defined('WC_VERSION') && version_compare(WC_VERSION, '5.0', '<')) {
            return false;
        }
        
        return true;
    }
    
    /**
     * Set default plugin options
     */
    private function set_default_options() {
        $default_settings = array(
            'enabled_gateways' => array(),
            'alert_email' => get_option('admin_email'),
            'alert_threshold' => 85,
            'monitoring_interval' => 300, // 5 minutes
            'enable_auto_retry' => true,
            'retry_schedule' => array(3600, 21600, 86400), // 1h, 6h, 24h
            'alert_phone' => '',
            'slack_webhook' => '',
            'license_key' => '',
            'twilio_sid' => '',
            'twilio_token' => '',
            'twilio_from' => ''
        );
        
        add_option('wc_payment_monitor_settings', $default_settings);
    }
    
    /**
     * WooCommerce missing notice
     */
    public function woocommerce_missing_notice() {
        echo '<div class="error"><p><strong>' . 
             __('WooCommerce Payment Monitor', 'wc-payment-monitor') . 
             '</strong> ' . 
             __('requires WooCommerce to be installed and active.', 'wc-payment-monitor') . 
             '</p></div>';
    }
}

// Initialize plugin
WC_Payment_Monitor::get_instance();