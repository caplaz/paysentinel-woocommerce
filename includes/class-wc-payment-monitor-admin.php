<?php
/**
 * Admin pages and menu registration
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class WC_Payment_Monitor_Admin {
    
    /**
     * Database instance
     */
    private $database;
    
    /**
     * Security instance
     */
    private $security;
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->database = new WC_Payment_Monitor_Database();
        $this->security = new WC_Payment_Monitor_Security();
        $this->init_hooks();
    }
    
    /**
     * Initialize WordPress hooks
     */
    private function init_hooks() {
        add_action('admin_menu', array($this, 'register_menu_pages'));
        add_action('admin_init', array($this, 'register_settings'));
    }
    
    /**
     * Register admin menu and pages
     */
    public function register_menu_pages() {
        // Check user capability
        if (!current_user_can('manage_woocommerce')) {
            return;
        }
        
        // Add main menu page
        add_menu_page(
            __('Payment Monitor', 'wc-payment-monitor'),
            __('Payment Monitor', 'wc-payment-monitor'),
            'manage_woocommerce',
            'wc-payment-monitor',
            array($this, 'render_dashboard_page'),
            'dashicons-chart-line',
            56
        );
        
        // Add dashboard submenu
        add_submenu_page(
            'wc-payment-monitor',
            __('Dashboard', 'wc-payment-monitor'),
            __('Dashboard', 'wc-payment-monitor'),
            'manage_woocommerce',
            'wc-payment-monitor',
            array($this, 'render_dashboard_page')
        );
        
        // Add gateway health submenu
        add_submenu_page(
            'wc-payment-monitor',
            __('Gateway Health', 'wc-payment-monitor'),
            __('Gateway Health', 'wc-payment-monitor'),
            'manage_woocommerce',
            'wc-payment-monitor-health',
            array($this, 'render_health_page')
        );
        
        // Add transaction logs submenu
        add_submenu_page(
            'wc-payment-monitor',
            __('Transactions', 'wc-payment-monitor'),
            __('Transactions', 'wc-payment-monitor'),
            'manage_woocommerce',
            'wc-payment-monitor-transactions',
            array($this, 'render_transactions_page')
        );
        
        // Add alerts submenu
        add_submenu_page(
            'wc-payment-monitor',
            __('Alerts', 'wc-payment-monitor'),
            __('Alerts', 'wc-payment-monitor'),
            'manage_woocommerce',
            'wc-payment-monitor-alerts',
            array($this, 'render_alerts_page')
        );
        
        // Add settings submenu
        add_submenu_page(
            'wc-payment-monitor',
            __('Settings', 'wc-payment-monitor'),
            __('Settings', 'wc-payment-monitor'),
            'manage_woocommerce',
            'wc-payment-monitor-settings',
            array($this, 'render_settings_page')
        );
    }
    
    /**
     * Register plugin settings
     */
    public function register_settings() {
        // Register setting group
        register_setting(
            'wc_payment_monitor_settings',
            'wc_payment_monitor_options',
            array(
                'type' => 'object',
                'sanitize_callback' => array($this->security, 'validate_admin_settings'),
                'show_in_rest' => false,
            )
        );
        
        // Add settings section
        add_settings_section(
            'wc_payment_monitor_main',
            __('Payment Monitor Settings', 'wc-payment-monitor'),
            array($this, 'render_settings_section'),
            'wc_payment_monitor_settings'
        );
        
        // Add settings fields
        add_settings_field(
            'enable_monitoring',
            __('Enable Monitoring', 'wc-payment-monitor'),
            array($this, 'render_field_enable_monitoring'),
            'wc_payment_monitor_settings',
            'wc_payment_monitor_main'
        );
        
        add_settings_field(
            'health_check_interval',
            __('Health Check Interval (minutes)', 'wc-payment-monitor'),
            array($this, 'render_field_health_check_interval'),
            'wc_payment_monitor_settings',
            'wc_payment_monitor_main'
        );
        
        add_settings_field(
            'alert_threshold',
            __('Alert Threshold (%)', 'wc-payment-monitor'),
            array($this, 'render_field_alert_threshold'),
            'wc_payment_monitor_settings',
            'wc_payment_monitor_main'
        );
        
        add_settings_field(
            'retry_enabled',
            __('Enable Payment Retry', 'wc-payment-monitor'),
            array($this, 'render_field_retry_enabled'),
            'wc_payment_monitor_settings',
            'wc_payment_monitor_main'
        );
        
        add_settings_field(
            'max_retry_attempts',
            __('Max Retry Attempts', 'wc-payment-monitor'),
            array($this, 'render_field_max_retry_attempts'),
            'wc_payment_monitor_settings',
            'wc_payment_monitor_main'
        );
        
        add_settings_field(
            'license_key',
            __('License Key', 'wc-payment-monitor'),
            array($this, 'render_field_license_key'),
            'wc_payment_monitor_settings',
            'wc_payment_monitor_main'
        );
    }
    
    /**
     * Render settings section
     */
    public function render_settings_section() {
        echo '<p>' . esc_html__('Configure Payment Monitor settings below.', 'wc-payment-monitor') . '</p>';
    }
    
    /**
     * Render enable monitoring field
     */
    public function render_field_enable_monitoring() {
        $options = get_option('wc_payment_monitor_options', array());
        $enabled = isset($options['enable_monitoring']) ? intval($options['enable_monitoring']) : 1;
        ?>
        <input type="checkbox" name="wc_payment_monitor_options[enable_monitoring]" value="1" <?php checked($enabled, 1); ?> />
        <label><?php esc_html_e('Monitor payment gateway transactions', 'wc-payment-monitor'); ?></label>
        <?php
    }
    
    /**
     * Render health check interval field
     */
    public function render_field_health_check_interval() {
        $options = get_option('wc_payment_monitor_options', array());
        $interval = isset($options['health_check_interval']) ? intval($options['health_check_interval']) : 5;
        ?>
        <input type="number" name="wc_payment_monitor_options[health_check_interval]" value="<?php echo esc_attr($interval); ?>" min="1" max="1440" />
        <p class="description"><?php esc_html_e('How often to recalculate gateway health (in minutes).', 'wc-payment-monitor'); ?></p>
        <?php
    }
    
    /**
     * Render alert threshold field
     */
    public function render_field_alert_threshold() {
        $options = get_option('wc_payment_monitor_options', array());
        $threshold = isset($options['alert_threshold']) ? floatval($options['alert_threshold']) : 20;
        ?>
        <input type="number" name="wc_payment_monitor_options[alert_threshold]" value="<?php echo esc_attr($threshold); ?>" min="1" max="100" step="0.1" />
        <p class="description"><?php esc_html_e('Failure rate percentage to trigger alerts.', 'wc-payment-monitor'); ?></p>
        <?php
    }
    
    /**
     * Render retry enabled field
     */
    public function render_field_retry_enabled() {
        $options = get_option('wc_payment_monitor_options', array());
        $enabled = isset($options['retry_enabled']) ? intval($options['retry_enabled']) : 1;
        ?>
        <input type="checkbox" name="wc_payment_monitor_options[retry_enabled]" value="1" <?php checked($enabled, 1); ?> />
        <label><?php esc_html_e('Automatically retry failed payments', 'wc-payment-monitor'); ?></label>
        <?php
    }
    
    /**
     * Render max retry attempts field
     */
    public function render_field_max_retry_attempts() {
        $options = get_option('wc_payment_monitor_options', array());
        $attempts = isset($options['max_retry_attempts']) ? intval($options['max_retry_attempts']) : 3;
        ?>
        <input type="number" name="wc_payment_monitor_options[max_retry_attempts]" value="<?php echo esc_attr($attempts); ?>" min="1" max="10" />
        <p class="description"><?php esc_html_e('Maximum number of retry attempts per transaction.', 'wc-payment-monitor'); ?></p>
        <?php
    }
    
    /**
     * Render license key field
     */
    public function render_field_license_key() {
        $options = get_option('wc_payment_monitor_options', array());
        $license_key = isset($options['license_key']) ? sanitize_text_field($options['license_key']) : '';
        ?>
        <input type="password" name="wc_payment_monitor_options[license_key]" value="<?php echo esc_attr($license_key); ?>" class="regular-text" />
        <p class="description"><?php esc_html_e('Enter your license key to enable premium features.', 'wc-payment-monitor'); ?></p>
        <?php
    }
    
    /**
     * Render dashboard page
     */
    public function render_dashboard_page() {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You do not have permission to access this page.', 'wc-payment-monitor'));
        }
        
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Payment Monitor Dashboard', 'wc-payment-monitor'); ?></h1>
            <div id="wc-payment-monitor-root"></div>
        </div>
        <?php
    }
    
    /**
     * Render gateway health page
     */
    public function render_health_page() {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You do not have permission to access this page.', 'wc-payment-monitor'));
        }
        
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Gateway Health', 'wc-payment-monitor'); ?></h1>
            <p><?php esc_html_e('Real-time health metrics for all payment gateways.', 'wc-payment-monitor'); ?></p>
            <div id="wc-payment-monitor-health-container"></div>
        </div>
        <?php
    }
    
    /**
     * Render transactions page
     */
    public function render_transactions_page() {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You do not have permission to access this page.', 'wc-payment-monitor'));
        }
        
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Transaction Log', 'wc-payment-monitor'); ?></h1>
            <p><?php esc_html_e('View all monitored payment transactions.', 'wc-payment-monitor'); ?></p>
            <div id="wc-payment-monitor-transactions-container"></div>
        </div>
        <?php
    }
    
    /**
     * Render alerts page
     */
    public function render_alerts_page() {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You do not have permission to access this page.', 'wc-payment-monitor'));
        }
        
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Alerts', 'wc-payment-monitor'); ?></h1>
            <p><?php esc_html_e('View all payment monitoring alerts.', 'wc-payment-monitor'); ?></p>
            <div id="wc-payment-monitor-alerts-container"></div>
        </div>
        <?php
    }
    
    /**
     * Render settings page
     */
    public function render_settings_page() {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You do not have permission to access this page.', 'wc-payment-monitor'));
        }
        
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Payment Monitor Settings', 'wc-payment-monitor'); ?></h1>
            <form method="post" action="options.php">
                <?php
                settings_fields('wc_payment_monitor_settings');
                do_settings_sections('wc_payment_monitor_settings');
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }
    
    /**
     * Get current settings
     * 
     * @return array Current settings
     */
    public static function get_settings() {
        $defaults = array(
            'enable_monitoring' => 1,
            'health_check_interval' => 5,
            'alert_threshold' => 20,
            'retry_enabled' => 1,
            'max_retry_attempts' => 3,
            'license_key' => '',
        );
        
        $options = get_option('wc_payment_monitor_options', array());
        return wp_parse_args($options, $defaults);
    }
    
    /**
     * Get single setting
     * 
     * @param string $setting Setting name
     * @param mixed $default Default value
     * @return mixed Setting value
     */
    public static function get_setting($setting, $default = null) {
        $settings = self::get_settings();
        return isset($settings[$setting]) ? $settings[$setting] : $default;
    }
    
    /**
     * Update settings
     * 
     * @param array $settings Settings to update
     * @return bool True on success
     */
    public static function update_settings($settings) {
        $current = self::get_settings();
        $updated = wp_parse_args($settings, $current);
        return update_option('wc_payment_monitor_options', $updated);
    }
}
