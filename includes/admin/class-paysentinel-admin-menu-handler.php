<?php
/**
 * Admin Menu Handler
 *
 * Handles menu/submenu registration and routing for the Payment Monitor plugin.
 *
 * @package PaySentinel
 * @since 1.0.0
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PaySentinel_Admin_Menu_Handler
 *
 * Manages WordPress admin menu registration and page routing.
 */
class PaySentinel_Admin_Menu_Handler {




	/**
	 * Page renderer instance
	 *
	 * @var PaySentinel_Admin_Page_Renderer
	 */
	private $page_renderer;

	/**
	 * Constructor
	 *
	 * @param PaySentinel_Admin_Page_Renderer $page_renderer Page renderer instance.
	 */
	public function __construct( $page_renderer ) {
		$this->page_renderer = $page_renderer;
	}

	/**
	 * Register admin menu pages
	 *
	 * Adds the main menu page and all submenu pages for the Payment Monitor plugin.
	 */
	public function register_menu_pages() {
		// Check user capability
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		// Add main menu page
		add_menu_page(
			__( 'PaySentinel', 'paysentinel' ),
			__( 'PaySentinel', 'paysentinel' ),
			'manage_woocommerce',
			'paysentinel',
			array( $this->page_renderer, 'render_dashboard_page' ),
			'dashicons-chart-line',
			56
		);

		// Add dashboard submenu
		add_submenu_page(
			'paysentinel',
			__( 'Dashboard', 'paysentinel' ),
			__( 'Dashboard', 'paysentinel' ),
			'manage_woocommerce',
			'paysentinel',
			array( $this->page_renderer, 'render_dashboard_page' )
		);

		// Add gateway health submenu
		add_submenu_page(
			'paysentinel',
			__( 'Gateway Health', 'paysentinel' ),
			__( 'Gateway Health', 'paysentinel' ),
			'manage_woocommerce',
			'paysentinel-health',
			array( $this->page_renderer, 'render_health_page' )
		);

		// Add transaction logs submenu
		add_submenu_page(
			'paysentinel',
			__( 'Transactions', 'paysentinel' ),
			__( 'Transactions', 'paysentinel' ),
			'manage_woocommerce',
			'paysentinel-transactions',
			array( $this->page_renderer, 'render_transactions_page' )
		);

		// Add alerts submenu
		add_submenu_page(
			'paysentinel',
			__( 'Alerts', 'paysentinel' ),
			__( 'Alerts', 'paysentinel' ),
			'manage_woocommerce',
			'paysentinel-alerts',
			array( $this->page_renderer, 'render_alerts_page' )
		);

		// Add diagnostic tools submenu
		add_submenu_page(
			'paysentinel',
			__( 'Diagnostic Tools', 'paysentinel' ),
			__( 'Diagnostic Tools', 'paysentinel' ),
			'manage_woocommerce',
			'paysentinel-diagnostics',
			array( $this->page_renderer, 'render_diagnostics_page' )
		);

		// Add settings submenu
		add_submenu_page(
			'paysentinel',
			__( 'Settings', 'paysentinel' ),
			__( 'Settings', 'paysentinel' ),
			'manage_woocommerce',
			'paysentinel-settings',
			array( $this->page_renderer, 'render_settings_page' )
		);

		// Add help submenu (external link)
		add_submenu_page(
			'paysentinel',
			__( 'Help & Documentation', 'paysentinel' ),
			__( 'Help & Documentation', 'paysentinel' ),
			'manage_woocommerce',
			'paysentinel-help',
			array( $this, 'redirect_to_help' )
		);
	}

	/**
	 * Redirect to the external help site
	 */
	public function redirect_to_help() {
		$help_url = PaySentinel_Admin_Page_Renderer::HELP_URL;
		echo '<script type="text/javascript">window.open("' . esc_url( $help_url ) . '", "_blank"); history.back();</script>';
		echo '<p>' . sprintf(
			/* translators: %s: help URL */
			__( 'Redirecting to <a href="%s" target="_blank">Help & Documentation</a>...', 'paysentinel' ),
			esc_url( $help_url )
		) . '</p>';
	}
}
