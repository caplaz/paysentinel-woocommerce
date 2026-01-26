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
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Define plugin constants
define( 'WC_PAYMENT_MONITOR_VERSION', '1.0.1' );
define( 'WC_PAYMENT_MONITOR_PLUGIN_FILE', __FILE__ );
define( 'WC_PAYMENT_MONITOR_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WC_PAYMENT_MONITOR_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'WC_PAYMENT_MONITOR_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

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
		if ( null === self::$instance ) {
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
		register_activation_hook( __FILE__, array( $this, 'activate' ) );
		register_deactivation_hook( __FILE__, array( $this, 'deactivate' ) );
		register_uninstall_hook( __FILE__, array( 'WC_Payment_Monitor', 'uninstall' ) );

		add_action( 'init', array( $this, 'init' ) );
		
		// Add custom cron schedules
		add_filter( 'cron_schedules', array( $this, 'add_custom_cron_schedules' ) );
	}

	/**
	 * Add custom cron schedules
	 *
	 * @param array $schedules Existing cron schedules.
	 * @return array Updated schedules.
	 */
	public function add_custom_cron_schedules( $schedules ) {
		if ( ! isset( $schedules['wc_monitor_30min'] ) ) {
			$schedules['wc_monitor_30min'] = array(
				'interval' => 30 * 60, // 30 minutes in seconds
				'display'  => __( 'Every 30 Minutes', 'wc-payment-monitor' ),
			);
		}
		return $schedules;
	}

	/**
	 * Load plugin dependencies
	 */
	private function load_dependencies() {
		// Autoloader
		spl_autoload_register( array( $this, 'autoload' ) );

		// Load core classes
		require_once WC_PAYMENT_MONITOR_PLUGIN_DIR . 'includes/class-wc-payment-monitor-database.php';
		require_once WC_PAYMENT_MONITOR_PLUGIN_DIR . 'includes/class-wc-payment-monitor-logger.php';
		require_once WC_PAYMENT_MONITOR_PLUGIN_DIR . 'includes/class-wc-payment-monitor-health.php';
		require_once WC_PAYMENT_MONITOR_PLUGIN_DIR . 'includes/class-wc-payment-monitor-alerts.php';
		require_once WC_PAYMENT_MONITOR_PLUGIN_DIR . 'includes/class-wc-payment-monitor-retry.php';
		require_once WC_PAYMENT_MONITOR_PLUGIN_DIR . 'includes/class-wc-payment-monitor-security.php';
		require_once WC_PAYMENT_MONITOR_PLUGIN_DIR . 'includes/class-wc-payment-monitor-license.php';

		// Load gateway connector classes
		require_once WC_PAYMENT_MONITOR_PLUGIN_DIR . 'includes/class-wc-payment-monitor-gateway-connector.php';
		require_once WC_PAYMENT_MONITOR_PLUGIN_DIR . 'includes/class-wc-payment-monitor-stripe-connector.php';
		require_once WC_PAYMENT_MONITOR_PLUGIN_DIR . 'includes/class-wc-payment-monitor-paypal-connector.php';
		require_once WC_PAYMENT_MONITOR_PLUGIN_DIR . 'includes/class-wc-payment-monitor-wc-payments-connector.php';
		require_once WC_PAYMENT_MONITOR_PLUGIN_DIR . 'includes/class-wc-payment-monitor-gateway-connectivity.php';

		// Load API classes
		require_once WC_PAYMENT_MONITOR_PLUGIN_DIR . 'includes/class-wc-payment-monitor-api-base.php';
		require_once WC_PAYMENT_MONITOR_PLUGIN_DIR . 'includes/class-wc-payment-monitor-api-health.php';
		require_once WC_PAYMENT_MONITOR_PLUGIN_DIR . 'includes/class-wc-payment-monitor-api-transactions.php';
		require_once WC_PAYMENT_MONITOR_PLUGIN_DIR . 'includes/class-wc-payment-monitor-api-alerts.php';

		// Load diagnostic and testing tools
		require_once WC_PAYMENT_MONITOR_PLUGIN_DIR . 'includes/class-wc-payment-monitor-diagnostics.php';
		require_once WC_PAYMENT_MONITOR_PLUGIN_DIR . 'includes/class-wc-payment-monitor-failure-simulator.php';
		require_once WC_PAYMENT_MONITOR_PLUGIN_DIR . 'includes/class-wc-payment-monitor-api-diagnostics.php';

		// Load admin classes
		require_once WC_PAYMENT_MONITOR_PLUGIN_DIR . 'includes/class-wc-payment-monitor-admin.php';
	}

	/**
	 * Autoloader for plugin classes
	 */
	public function autoload( $class_name ) {
		if ( strpos( $class_name, 'WC_Payment_Monitor_' ) !== 0 ) {
			return;
		}

		$class_file = strtolower( str_replace( '_', '-', $class_name ) );
		$class_file = str_replace( 'wc-payment-monitor-', '', $class_file );
		$file_path  = WC_PAYMENT_MONITOR_PLUGIN_DIR . 'includes/class-wc-payment-monitor-' . $class_file . '.php';

		if ( file_exists( $file_path ) ) {
			require_once $file_path;
		}
	}

	/**
	 * Initialize plugin
	 */
	public function init() {
		// Check WordPress version
		global $wp_version;
		if ( version_compare( $wp_version, '5.0', '<' ) ) {
			add_action( 'admin_notices', array( $this, 'wordpress_version_notice' ) );
			deactivate_plugins( plugin_basename( __FILE__ ) );
			return;
		}

		// Check if WooCommerce is active
		if ( ! $this->is_woocommerce_active() ) {
			add_action( 'admin_notices', array( $this, 'woocommerce_missing_notice' ) );
			deactivate_plugins( plugin_basename( __FILE__ ) );
			return;
		}

		// Check WooCommerce version
		if ( defined( 'WC_VERSION' ) && version_compare( WC_VERSION, '5.0', '<' ) ) {
			add_action( 'admin_notices', array( $this, 'woocommerce_version_notice' ) );
			deactivate_plugins( plugin_basename( __FILE__ ) );
			return;
		}

		// Load text domain
		load_plugin_textdomain( 'wc-payment-monitor', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

		// Initialize components
		$this->init_components();
	}

	/**
	 * Component instances
	 */
	public $logger;
	public $health;
	public $alerts;
	public $retry;

	/**
	 * Initialize plugin components
	 */
	private function init_components() {
		// Initialize transaction logger
		$this->logger = new WC_Payment_Monitor_Logger();

		// Initialize health calculation engine
		$this->health = new WC_Payment_Monitor_Health();

		// Initialize alert system
		$this->alerts = new WC_Payment_Monitor_Alerts();

		// Initialize retry engine
		$this->retry = new WC_Payment_Monitor_Retry();

		// Initialize gateway connectivity scheduler
		$this->init_gateway_connectivity_scheduler();

		// Initialize REST API endpoints
		new WC_Payment_Monitor_API_Health();
		new WC_Payment_Monitor_API_Transactions();
		new WC_Payment_Monitor_API_Alerts();
		new WC_Payment_Monitor_API_Diagnostics();

		// Initialize admin pages
		if ( is_admin() ) {
			new WC_Payment_Monitor_Admin();
		}
	}

	/**
	 * Initialize gateway connectivity checker scheduler
	 */
	private function init_gateway_connectivity_scheduler() {
		// Schedule the gateway connectivity check cron job
		if ( ! wp_next_scheduled( 'wc_payment_monitor_gateway_connectivity_check' ) ) {
			wp_schedule_event( time(), 'wc_monitor_30min', 'wc_payment_monitor_gateway_connectivity_check' );
		}

		// Hook to run the connectivity check
		add_action( 'wc_payment_monitor_gateway_connectivity_check', array( $this, 'run_gateway_connectivity_check' ) );
	}

	/**
	 * Run gateway connectivity checks
	 * This is called by WordPress cron every 30 minutes
	 */
	public function run_gateway_connectivity_check() {
		try {
			$connectivity = new WC_Payment_Monitor_Gateway_Connectivity();
			$results = $connectivity->check_all_gateways();

			// Log connectivity check results
			do_action( 'wc_payment_monitor_connectivity_check_complete', $results );

			// Clean up old connectivity records (older than 30 days)
			$connectivity->cleanup_old_checks( 30 );
		} catch ( Exception $e ) {
			error_log(
				'Payment Monitor: Error during gateway connectivity check: ' . $e->getMessage()
			);
		}
	}

	/**
	 * Plugin activation
	 */
	public function activate() {
		// Note: Requirements check moved to init() method to ensure WooCommerce is loaded
		// This allows activation even if WooCommerce loads after this plugin

		// Create database tables
		$database = new WC_Payment_Monitor_Database();
		$database->create_tables();

		// Set plugin version
		update_option( 'wc_payment_monitor_version', WC_PAYMENT_MONITOR_VERSION );

		// Set default options
		$this->set_default_options();

		// Trigger health calculation scheduling
		do_action( 'wc_payment_monitor_activated' );
	}

	/**
	 * Plugin deactivation
	 */
	public function deactivate() {
		// Clear scheduled events
		wp_clear_scheduled_hook( 'wc_payment_monitor_health_calculation' );
		wp_clear_scheduled_hook( 'wc_payment_monitor_process_retries' );
		wp_clear_scheduled_hook( 'wc_payment_monitor_gateway_connectivity_check' );
		
		// Clear Action Scheduler actions
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( 'wc_payment_monitor_retry_payment' );
		}
	}

	/**
	 * Plugin uninstall
	 */
	public static function uninstall() {
		// Remove database tables
		require_once plugin_dir_path( __FILE__ ) . 'includes/class-wc-payment-monitor-database.php';
		$database = new WC_Payment_Monitor_Database();
		$database->drop_tables();

		// Remove options
		delete_option( 'wc_payment_monitor_version' );
		delete_option( 'wc_payment_monitor_settings' );
		delete_option( 'wc_payment_monitor_retry_stats' );

		// Clear scheduled events
		wp_clear_scheduled_hook( 'wc_payment_monitor_health_calculation' );
		wp_clear_scheduled_hook( 'wc_payment_monitor_process_retries' );
		
		// Clear Action Scheduler actions
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( 'wc_payment_monitor_retry_payment' );
		}
	}

	/**
	 * Check plugin requirements
	 */
	private function check_requirements() {
		global $wp_version;

		if ( version_compare( $wp_version, '5.0', '<' ) ) {
			return false;
		}

		// Check if WooCommerce is installed and active
		if ( ! $this->is_woocommerce_active() ) {
			return false;
		}

		// Check WooCommerce version if available
		if ( defined( 'WC_VERSION' ) && version_compare( WC_VERSION, '5.0', '<' ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Check if WooCommerce is active
	 */
	private function is_woocommerce_active() {
		// First check if the class exists (WooCommerce is loaded)
		if ( class_exists( 'WooCommerce' ) ) {
			return true;
		}

		// If class doesn't exist, check if WooCommerce plugin is active
		if ( function_exists( 'is_plugin_active' ) ) {
			return is_plugin_active( 'woocommerce/woocommerce.php' );
		}

		// Fallback: check if the plugin file exists in active plugins
		$active_plugins = get_option( 'active_plugins', array() );
		return in_array( 'woocommerce/woocommerce.php', $active_plugins );
	}

	/**
	 * Set default plugin options
	 */
	private function set_default_options() {
		$default_settings = array(
			'enabled_gateways'       => array(),
			'alert_email'            => get_option( 'admin_email' ),
			'alert_phone_number'     => '',
			'alert_slack_workspace'  => '',
			'alert_threshold'        => 85,
			'gateway_alert_config'   => array(), // Per-gateway configuration (Pro+ feature)
			'monitoring_interval'    => 300, // 5 minutes
			'enable_auto_retry'      => true,
			'retry_schedule'         => array( 3600, 21600, 86400 ), // 1h, 6h, 24h
			'license_key'            => '',
		);

		add_option( 'wc_payment_monitor_settings', $default_settings );
	}

	/**
	 * WooCommerce missing notice
	 */
	public function woocommerce_missing_notice() {
		echo '<div class="error"><p><strong>' .
			__( 'WooCommerce Payment Monitor', 'wc-payment-monitor' ) .
			'</strong> ' .
			__( 'requires WooCommerce to be installed and active.', 'wc-payment-monitor' ) .
			'</p></div>';
	}

	/**
	 * Show WordPress version notice
	 */
	public function wordpress_version_notice() {
		echo '<div class="error"><p><strong>' .
			__( 'WooCommerce Payment Monitor', 'wc-payment-monitor' ) .
			'</strong> ' .
			__( 'requires WordPress 5.0 or higher.', 'wc-payment-monitor' ) .
			'</p></div>';
	}

	/**
	 * Show WooCommerce version notice
	 */
	public function woocommerce_version_notice() {
		echo '<div class="error"><p><strong>' .
			__( 'WooCommerce Payment Monitor', 'wc-payment-monitor' ) .
			'</strong> ' .
			__( 'requires WooCommerce 5.0 or higher.', 'wc-payment-monitor' ) .
			'</p></div>';
	}
}

// Initialize plugin
WC_Payment_Monitor::get_instance();
