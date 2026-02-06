<?php
/**
 * Admin Menu Handler
 *
 * Handles menu/submenu registration and routing for the Payment Monitor plugin.
 *
 * @package WC_Payment_Monitor
 * @since 1.0.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class WC_Payment_Monitor_Admin_Menu_Handler
 *
 * Manages WordPress admin menu registration and page routing.
 */
class WC_Payment_Monitor_Admin_Menu_Handler
{
    /**
     * Page renderer instance
     *
     * @var WC_Payment_Monitor_Admin_Page_Renderer
     */
    private $page_renderer;

    /**
     * Constructor
     *
     * @param WC_Payment_Monitor_Admin_Page_Renderer $page_renderer Page renderer instance.
     */
    public function __construct($page_renderer)
    {
        $this->page_renderer = $page_renderer;
    }

    /**
     * Register admin menu pages
     *
     * Adds the main menu page and all submenu pages for the Payment Monitor plugin.
     */
    public function register_menu_pages()
    {
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
            [$this->page_renderer, 'render_dashboard_page'],
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
            [$this->page_renderer, 'render_dashboard_page']
        );

        // Add gateway health submenu
        add_submenu_page(
            'wc-payment-monitor',
            __('Gateway Health', 'wc-payment-monitor'),
            __('Gateway Health', 'wc-payment-monitor'),
            'manage_woocommerce',
            'wc-payment-monitor-health',
            [$this->page_renderer, 'render_health_page']
        );

        // Add transaction logs submenu
        add_submenu_page(
            'wc-payment-monitor',
            __('Transactions', 'wc-payment-monitor'),
            __('Transactions', 'wc-payment-monitor'),
            'manage_woocommerce',
            'wc-payment-monitor-transactions',
            [$this->page_renderer, 'render_transactions_page']
        );

        // Add alerts submenu
        add_submenu_page(
            'wc-payment-monitor',
            __('Alerts', 'wc-payment-monitor'),
            __('Alerts', 'wc-payment-monitor'),
            'manage_woocommerce',
            'wc-payment-monitor-alerts',
            [$this->page_renderer, 'render_alerts_page']
        );

        // Add diagnostic tools submenu
        add_submenu_page(
            'wc-payment-monitor',
            __('Diagnostic Tools', 'wc-payment-monitor'),
            __('Diagnostic Tools', 'wc-payment-monitor'),
            'manage_woocommerce',
            'wc-payment-monitor-diagnostics',
            [$this->page_renderer, 'render_diagnostics_page']
        );

        // Add settings submenu
        add_submenu_page(
            'wc-payment-monitor',
            __('Settings', 'wc-payment-monitor'),
            __('Settings', 'wc-payment-monitor'),
            'manage_woocommerce',
            'wc-payment-monitor-settings',
            [$this->page_renderer, 'render_settings_page']
        );
    }
}
