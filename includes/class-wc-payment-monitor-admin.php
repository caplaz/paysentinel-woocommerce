<?php
/**
 * Admin pages and menu registration
 *
 * Main admin class that coordinates the various admin handler components.
 * This class has been refactored to delegate responsibilities to specialized handlers.
 *
 * @package WC_Payment_Monitor
 * @since 1.0.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class WC_Payment_Monitor_Admin
{
    /**
     * Database instance
     *
     * @var WC_Payment_Monitor_Database
     */
    private $database;

    /**
     * Security instance
     *
     * @var WC_Payment_Monitor_Security
     */
    private $security;

    /**
     * License instance
     *
     * @var WC_Payment_Monitor_License
     */
    private $license;

    /**
     * Menu handler instance
     *
     * @var WC_Payment_Monitor_Admin_Menu_Handler
     */
    private $menu_handler;

    /**
     * Settings handler instance
     *
     * @var WC_Payment_Monitor_Admin_Settings_Handler
     */
    private $settings_handler;

    /**
     * Page renderer instance
     *
     * @var WC_Payment_Monitor_Admin_Page_Renderer
     */
    private $page_renderer;

    /**
     * AJAX handler instance
     *
     * @var WC_Payment_Monitor_Admin_Ajax_Handler
     */
    private $ajax_handler;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->database = new WC_Payment_Monitor_Database();
        $this->security = new WC_Payment_Monitor_Security();
        $this->license = new WC_Payment_Monitor_License();

        // Initialize handler instances
        $this->settings_handler = new WC_Payment_Monitor_Admin_Settings_Handler($this->security, $this->license);
        $this->page_renderer = new WC_Payment_Monitor_Admin_Page_Renderer($this->database, $this->license, $this->settings_handler);
        $this->menu_handler = new WC_Payment_Monitor_Admin_Menu_Handler($this->page_renderer);
        $this->ajax_handler = new WC_Payment_Monitor_Admin_Ajax_Handler($this->license);

        $this->init_hooks();
    }

    /**
     * Initialize WordPress hooks
     */
    private function init_hooks()
    {
        // Menu registration
        add_action('admin_menu', [$this->menu_handler, 'register_menu_pages']);

        // Settings registration
        add_action('admin_init', [$this->settings_handler, 'register_settings']);

        // Scripts and styles
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);

        // Slack OAuth callback
        add_action('admin_init', [$this->ajax_handler, 'handle_slack_callback']);

        // AJAX handlers
        add_action('wp_ajax_wc_payment_monitor_slack_test', [$this->ajax_handler, 'handle_slack_test']);
        add_action('wp_ajax_wc_payment_monitor_sync_integrations', [$this->ajax_handler, 'handle_sync_integrations']);
        add_action('wp_ajax_wc_payment_monitor_validate_license', [$this->ajax_handler, 'handle_validate_license_ajax']);

        // Admin POST actions
        add_action('admin_post_wc_payment_monitor_retry', [$this, 'handle_manual_retry']);
        add_action('admin_post_wc_payment_monitor_recovery', [$this, 'handle_recovery_email']);
        add_action('admin_post_wc_payment_monitor_deactivate_license', [$this, 'handle_deactivate_license']);
        add_action('admin_post_wc_payment_monitor_save_license', [$this->ajax_handler, 'handle_save_license']);
    }

    /**
     * Enqueue admin scripts and styles
     *
     * @param string $hook Current admin page hook.
     */
    public function enqueue_admin_scripts($hook)
    {
        // Only load on our plugin pages
        if (strpos($hook, 'wc-payment-monitor') === false) {
            return;
        }

        // Ensure constants are defined
        if (!defined('WC_PAYMENT_MONITOR_PLUGIN_URL') || !defined('WC_PAYMENT_MONITOR_VERSION')) {
            return;
        }

        // Enqueue WordPress REST API dependencies
        wp_enqueue_script('wp-api-fetch');
        wp_enqueue_script('wp-element');
        wp_enqueue_script('wp-components');
        wp_enqueue_script('wp-i18n');

        // Enqueue Chart.js 4.x from CDN for data visualization
        wp_register_script(
            'chartjs',
            'https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js',
            [],
            '4.4.1',
            true
        );

        // Enqueue our dashboard script
        $dashboard_js_path = WC_PAYMENT_MONITOR_PLUGIN_DIR . 'assets/js/dashboard/index.js';
        $dashboard_css_path = WC_PAYMENT_MONITOR_PLUGIN_DIR . 'assets/js/dashboard/index.css';
        $js_ver = file_exists($dashboard_js_path) ? filemtime($dashboard_js_path) : WC_PAYMENT_MONITOR_VERSION;
        $css_ver = file_exists($dashboard_css_path) ? filemtime($dashboard_css_path) : WC_PAYMENT_MONITOR_VERSION;

        wp_enqueue_script(
            'wc-payment-monitor-dashboard',
            WC_PAYMENT_MONITOR_PLUGIN_URL . 'assets/js/dashboard/index.js',
            ['wp-api-fetch', 'wp-element', 'chartjs'],
            $js_ver,
            true
        );

        wp_enqueue_style(
            'wc-payment-monitor-dashboard',
            WC_PAYMENT_MONITOR_PLUGIN_URL . 'assets/js/dashboard/index.css',
            [],
            $css_ver
        );

        // Localize script with admin data
        wp_localize_script('wc-payment-monitor-dashboard', 'wcPaymentMonitor', [
            'root' => esc_url_raw(rest_url()),
            'nonce' => wp_create_nonce('wp_rest'),
            'adminNonce' => wp_create_nonce('wc_payment_monitor_admin_nonce'),
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'tier' => $this->license->get_license_tier(),
            'isPremium' => $this->is_premium(),
        ]);
    }

    /**
     * Handle manual retry action
     */
    public function handle_manual_retry()
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You do not have permission to perform this action.', 'wc-payment-monitor'));
        }

        $order_id = isset($_GET['order_id']) ? intval($_GET['order_id']) : 0;
        check_admin_referer('wc_payment_monitor_retry_' . $order_id);

        if (!$order_id) {
            wp_redirect(admin_url('admin.php?page=wc-payment-monitor-transactions&message=' . urlencode(__('Invalid order ID.', 'wc-payment-monitor')) . '&type=error'));
            exit;
        }

        // Get retry instance
        if (!isset(WC_Payment_Monitor::get_instance()->retry)) {
            wp_redirect(admin_url('admin.php?page=wc-payment-monitor-transactions&message=' . urlencode(__('Retry component not available.', 'wc-payment-monitor')) . '&type=error'));
            exit;
        }

        $result = WC_Payment_Monitor::get_instance()->retry->manual_retry($order_id);
        $type = $result['success'] ? 'success' : 'error';

        wp_redirect(admin_url('admin.php?page=wc-payment-monitor-transactions&message=' . urlencode($result['message']) . '&type=' . $type));
        exit;
    }

    /**
     * Handle recovery email action
     */
    public function handle_recovery_email()
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You do not have permission to perform this action.', 'wc-payment-monitor'));
        }

        $order_id = isset($_GET['order_id']) ? intval($_GET['order_id']) : 0;
        check_admin_referer('wc_payment_monitor_recovery_' . $order_id);

        if (!$order_id) {
            wp_redirect(admin_url('admin.php?page=wc-payment-monitor-transactions&message=' . urlencode(__('Invalid order ID.', 'wc-payment-monitor')) . '&type=error'));
            exit;
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            wp_redirect(admin_url('admin.php?page=wc-payment-monitor-transactions&message=' . urlencode(__('Order not found.', 'wc-payment-monitor')) . '&type=error'));
            exit;
        }

        // Get retry instance
        if (!isset(WC_Payment_Monitor::get_instance()->retry)) {
            wp_redirect(admin_url('admin.php?page=wc-payment-monitor-transactions&message=' . urlencode(__('Retry component not available.', 'wc-payment-monitor')) . '&type=error'));
            exit;
        }

        WC_Payment_Monitor::get_instance()->retry->send_recovery_email($order);

        wp_redirect(admin_url('admin.php?page=wc-payment-monitor-transactions&message=' . urlencode(__('Recovery email sent successfully.', 'wc-payment-monitor')) . '&type=success'));
        exit;
    }

    /**
     * Handle license deactivation
     */
    public function handle_deactivate_license()
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You do not have permission to perform this action.', 'wc-payment-monitor'));
        }

        check_admin_referer('wc_payment_monitor_deactivate_license');

        // Deactivate license
        $this->license->deactivate_license();

        wp_redirect(admin_url('admin.php?page=wc-payment-monitor-settings&tab=license&message=' . urlencode(__('License deactivated successfully.', 'wc-payment-monitor')) . '&type=info'));
        exit;
    }

    /**
     * Get license tier
     *
     * @return string License tier (free, starter, pro, agency)
     */
    public function get_license_tier()
    {
        return $this->license->get_license_tier();
    }

    /**
     * Check if premium features are available
     *
     * @return bool True if premium tier (pro or agency)
     */
    public function is_premium()
    {
        $tier = $this->get_license_tier();
        return in_array($tier, ['pro', 'agency'], true);
    }
}
