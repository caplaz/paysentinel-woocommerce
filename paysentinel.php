<?php
/**
 * Plugin Name: PaySentinel - Payment Monitor for WooCommerce
 * Plugin URI: https://github.com/caplaz/paysentinel-woocommerce/
 * Description: Real-time monitoring, alerting, and recovery capabilities for WooCommerce payment gateway failures.
 * Version: 1.1.0
 * Author: Caplaz
 * Author URI: https://www.caplaz.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: paysentinel
 * Domain Path: /languages
 * Requires at least: 6.5
 * Tested up to: 6.8
 * Requires PHP: 7.4
 * WC requires at least: 8.5
 * WC tested up to: 9.5
 *
 * @package PaySentinel
 */

// Declare WooCommerce feature compatibility.
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'product_block_editor', __FILE__, true );
		}
	}
);

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Define plugin constants.
define( 'PAYSENTINEL_VERSION', '1.1.0' );
define( 'PAYSENTINEL_PLUGIN_FILE', __FILE__ );
define( 'PAYSENTINEL_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'PAYSENTINEL_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'PAYSENTINEL_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Main plugin class
 */
class PaySentinel {



	/**
	 * Plugin instance
	 *
	 * @var PaySentinel|null
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
		register_uninstall_hook( __FILE__, array( 'PaySentinel', 'uninstall' ) );

		add_action( 'init', array( $this, 'init' ) );

		// Add custom cron schedules.
		add_filter( 'cron_schedules', array( $this, 'add_custom_cron_schedules' ) );

		// Schedule daily cleanup if not scheduled.
		if ( ! wp_next_scheduled( 'paysentinel_daily_cleanup' ) ) {
			wp_schedule_event( time(), 'daily', 'paysentinel_daily_cleanup' );
		}
		add_action( 'paysentinel_daily_cleanup', array( $this, 'run_daily_cleanup' ) );
	}

	/**
	 * Add custom cron schedules
	 *
	 * @param array $schedules Existing cron schedules.
	 *
	 * @return array Updated schedules.
	 */
	public function add_custom_cron_schedules( $schedules ) {
		if ( ! isset( $schedules['wc_monitor_30min'] ) ) {
			$schedules['wc_monitor_30min'] = array(
				'interval' => 30 * 60, // 30 minutes in seconds
				'display'  => __( 'Every 30 Minutes', 'paysentinel' ),
			);
		}
		return $schedules;
	}

	/**
	 * Load plugin dependencies
	 */
	private function load_dependencies() {
		// Autoloader.
		spl_autoload_register( array( $this, 'autoload' ) );

		// Load core classes.
		require_once PAYSENTINEL_PLUGIN_DIR . 'includes/core/class-paysentinel-config.php';
		require_once PAYSENTINEL_PLUGIN_DIR . 'includes/core/class-paysentinel-settings-constants.php';
		require_once PAYSENTINEL_PLUGIN_DIR . 'includes/core/class-paysentinel-database.php';
		require_once PAYSENTINEL_PLUGIN_DIR . 'includes/core/class-paysentinel-license.php';
		require_once PAYSENTINEL_PLUGIN_DIR . 'includes/gateways/class-paysentinel-gateway-manager.php';
		require_once PAYSENTINEL_PLUGIN_DIR . 'includes/core/class-paysentinel-logger.php';
		require_once PAYSENTINEL_PLUGIN_DIR . 'includes/core/class-paysentinel-telemetry.php';
		require_once PAYSENTINEL_PLUGIN_DIR . 'includes/core/class-paysentinel-health.php';

		// Load alert system classes (order matters - dependencies first)
		require_once PAYSENTINEL_PLUGIN_DIR . 'includes/alerts/class-paysentinel-alert-template-manager.php';
		require_once PAYSENTINEL_PLUGIN_DIR . 'includes/alerts/class-paysentinel-alert-notifier.php';
		require_once PAYSENTINEL_PLUGIN_DIR . 'includes/alerts/class-paysentinel-alert-checker.php';
		require_once PAYSENTINEL_PLUGIN_DIR . 'includes/alerts/class-paysentinel-alerts.php';
		require_once PAYSENTINEL_PLUGIN_DIR . 'includes/alerts/class-paysentinel-alert-recovery-handler.php';

		require_once PAYSENTINEL_PLUGIN_DIR . 'includes/core/class-paysentinel-retry.php';
		require_once PAYSENTINEL_PLUGIN_DIR . 'includes/core/class-paysentinel-security.php';

		// Load gateway connector classes.
		require_once PAYSENTINEL_PLUGIN_DIR . 'includes/gateways/class-paysentinel-gateway-connector.php';
		require_once PAYSENTINEL_PLUGIN_DIR . 'includes/gateways/class-paysentinel-stripe-connector.php';
		require_once PAYSENTINEL_PLUGIN_DIR . 'includes/gateways/class-paysentinel-paypal-connector.php';
		require_once PAYSENTINEL_PLUGIN_DIR . 'includes/gateways/class-paysentinel-square-connector.php';
		require_once PAYSENTINEL_PLUGIN_DIR . 'includes/gateways/class-paysentinel-wc-payments-connector.php';
		require_once PAYSENTINEL_PLUGIN_DIR . 'includes/gateways/class-paysentinel-gateway-connectivity.php';

		// Load API classes.
		require_once PAYSENTINEL_PLUGIN_DIR . 'includes/api/class-paysentinel-api-base.php';
		require_once PAYSENTINEL_PLUGIN_DIR . 'includes/api/class-paysentinel-api-health.php';
		require_once PAYSENTINEL_PLUGIN_DIR . 'includes/api/class-paysentinel-api-transactions.php';
		require_once PAYSENTINEL_PLUGIN_DIR . 'includes/api/class-paysentinel-api-alerts.php';

		// Load PRO analytics classes.
		require_once PAYSENTINEL_PLUGIN_DIR . 'includes/class-paysentinel-analytics-pro.php';
		require_once PAYSENTINEL_PLUGIN_DIR . 'includes/api/class-paysentinel-api-analytics-pro.php';

		// Load diagnostic and testing tools.
		require_once PAYSENTINEL_PLUGIN_DIR . 'includes/core/class-paysentinel-diagnostics.php';
		require_once PAYSENTINEL_PLUGIN_DIR . 'includes/utils/class-paysentinel-failure-simulator.php';
		require_once PAYSENTINEL_PLUGIN_DIR . 'includes/api/class-paysentinel-api-diagnostics.php';

		// Load admin handler classes (order matters - dependencies first)
		require_once PAYSENTINEL_PLUGIN_DIR . 'includes/admin/class-paysentinel-admin-menu-handler.php';
		require_once PAYSENTINEL_PLUGIN_DIR . 'includes/admin/class-paysentinel-admin-settings-handler.php';
		require_once PAYSENTINEL_PLUGIN_DIR . 'includes/admin/class-paysentinel-admin-page-renderer.php';
		require_once PAYSENTINEL_PLUGIN_DIR . 'includes/admin/class-paysentinel-admin-ajax-handler.php';
		require_once PAYSENTINEL_PLUGIN_DIR . 'includes/admin/class-paysentinel-admin.php';
	}

	/**
	 * Autoloader for plugin classes
	 *
	 * @param string $class_name The class name to autoload.
	 */
	public function autoload( $class_name ) {
		if ( strpos( $class_name, 'PaySentinel_' ) !== 0 ) {
			return;
		}

		$class_file = strtolower( str_replace( '_', '-', $class_name ) );
		$class_file = str_replace( 'paysentinel-', '', $class_file );
		$file_path  = PAYSENTINEL_PLUGIN_DIR . 'includes/class-paysentinel-' . $class_file . '.php';

		if ( file_exists( $file_path ) ) {
			require_once $file_path;
		}
	}

	/**
	 * Initialize plugin
	 */
	public function init() {
		// Check WordPress version.
		global $wp_version;
		if ( version_compare( $wp_version, '5.0', '<' ) ) {
			add_action( 'admin_notices', array( $this, 'wordpress_version_notice' ) );
			deactivate_plugins( plugin_basename( __FILE__ ) );
			return;
		}

		// Check if WooCommerce is active.
		if ( ! $this->is_woocommerce_active() ) {
			add_action( 'admin_notices', array( $this, 'woocommerce_missing_notice' ) );
			deactivate_plugins( plugin_basename( __FILE__ ) );
			return;
		}

		// Check WooCommerce version.
		if ( defined( 'WC_VERSION' ) && version_compare( WC_VERSION, '5.0', '<' ) ) {
			add_action( 'admin_notices', array( $this, 'woocommerce_version_notice' ) );
			deactivate_plugins( plugin_basename( __FILE__ ) );
			return;
		}

		// Load text domain.
		load_plugin_textdomain( 'paysentinel', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

		// Initialize components.
		$this->init_components();
	}

	/**
	 * Component instances
	 *
	 * @var PaySentinel_Logger $logger Transaction logger instance.
	 */
	public $logger;

	/**
	 * @var PaySentinel_Health $health Health calculation engine instance.
	 */
	public $health;

	/**
	 * @var PaySentinel_Alerts $alerts Alert system instance.
	 */
	public $alerts;

	/**
	 * @var PaySentinel_Telemetry $telemetry Telemetry system instance.
	 */
	public $telemetry;

	/**
	 * @var PaySentinel_Retry $retry Retry engine instance.
	 */
	public $retry;

	/**
	 * Initialize plugin components
	 */
	private function init_components() {
		// Ensure database tables exist at runtime, not only on activation.
		// needs_update() only checks the DB version option, which can be set from
		// a prior partial activation even when the tables were never created.
		// tables_exist() does the authoritative SHOW TABLES check.
		$database = new PaySentinel_Database();
		if ( $database->needs_update() || ! $database->tables_exist() ) {
			$database->create_tables();
		}

		// Initialize transaction logger.
		$this->logger = new PaySentinel_Logger();

		// Initialize health calculation engine.
		$this->health = new PaySentinel_Health();

		// Initialize alert system.
		$this->alerts = new PaySentinel_Alerts();

		// Initialize retry engine.
		$this->retry = new PaySentinel_Retry();

		// Initialize telemetry engine.
		$this->telemetry = new PaySentinel_Telemetry();

		// Initialize license system and hooks.
		$license = new PaySentinel_License();
		$license->init_hooks();

		// Initialize gateway connectivity scheduler.
		$this->init_gateway_connectivity_scheduler();

		// Initialize REST API endpoints on rest_api_init.
		add_action( 'rest_api_init', array( $this, 'init_api_endpoints' ) );

		// Initialize admin pages.
		if ( is_admin() ) {
			new PaySentinel_Admin();
		}
	}

	/**
	 * Initialize REST API endpoints
	 */
	public function init_api_endpoints() {
		new PaySentinel_API_Health();
		new PaySentinel_API_Transactions();
		new PaySentinel_API_Alerts();
		new PaySentinel_API_Diagnostics();
		new PaySentinel_API_Analytics_Pro();
	}

	/**
	 * Get friendly gateway name for display
	 *
	 * @param string $gateway_id The gateway ID.
	 *
	 * @return string Friendly gateway name.
	 */
	public static function get_friendly_gateway_name( $gateway_id ) {
		// Friendly name mapping for common payment gateways
		$friendly_names = array(
			// WooCommerce Payments sub-gateways
			'woocommerce_payments_affirm'               => 'WC Payments - Affirm',
			'woocommerce_payments_klarna'               => 'WC Payments - Klarna',
			'woocommerce_payments_afterpay'             => 'WC Payments - Afterpay',
			'woocommerce_payments_clearpay'             => 'WC Payments - Clearpay',
			'woocommerce_payments_woocommerce_payments' => 'WC Payments - Card',
			'woocommerce_payments'                      => 'WC Payments',

			// Stripe sub-gateways
			'stripe'                                    => 'Stripe',
			'stripe_affirm'                             => 'Stripe - Affirm',
			'stripe_klarna'                             => 'Stripe - Klarna',
			'stripe_afterpay'                           => 'Stripe - Afterpay',
			'stripe_clearpay'                           => 'Stripe - Clearpay',
			'stripe_alipay'                             => 'Stripe - Alipay',
			'stripe_wechat_pay'                         => 'Stripe - WeChat Pay',
			'stripe_bancontact'                         => 'Stripe - Bancontact',
			'stripe_eps'                                => 'Stripe - EPS',
			'stripe_giropay'                            => 'Stripe - Giropay',
			'stripe_ideal'                              => 'Stripe - iDEAL',
			'stripe_p24'                                => 'Stripe - Przelewy24',
			'stripe_sepa'                               => 'Stripe - SEPA Direct Debit',
			'stripe_sofort'                             => 'Stripe - Sofort',

			// PayPal
			'paypal'                                    => 'PayPal',
			'ppec_paypal'                               => 'PayPal Express Checkout',
			'paypal_pro'                                => 'PayPal Pro',
			'paypal_pro_payflow'                        => 'PayPal Pro Payflow',

			// Square
			'square_credit_card'                        => 'Square - Credit Card',
			'square'                                    => 'Square',

			// Other common gateways
			'cod'                                       => 'Cash on Delivery',
			'cheque'                                    => 'Check Payment',
			'bacs'                                      => 'Direct Bank Transfer',
			'authorize_net'                             => 'Authorize.Net',
			'braintree'                                 => 'Braintree',
			'braintree_paypal'                          => 'Braintree - PayPal',
			'braintree_credit_card'                     => 'Braintree - Credit Card',
			'eway'                                      => 'eWAY',
			'mollie'                                    => 'Mollie',
			'mollie_ideal'                              => 'Mollie - iDEAL',
			'mollie_creditcard'                         => 'Mollie - Credit Card',
			'klarna_checkout'                           => 'Klarna Checkout',
			'klarna_payments'                           => 'Klarna Payments',
			'affirm'                                    => 'Affirm',
			'afterpay'                                  => 'Afterpay',
			'clearpay'                                  => 'Clearpay',
		);

		// Check if we have a friendly name mapping
		if ( isset( $friendly_names[ $gateway_id ] ) ) {
			return $friendly_names[ $gateway_id ];
		}

		// Try to get the name from WooCommerce payment gateways
		if ( class_exists( 'WC_Payment_Gateways' ) ) {
			$wc_gateways = WC_Payment_Gateways::instance();
			$gateways    = $wc_gateways->get_available_payment_gateways();

			if ( isset( $gateways[ $gateway_id ] ) ) {
				return $gateways[ $gateway_id ]->get_title();
			}
		}

		// Fallback to gateway ID if name not found
		return ucfirst( str_replace( '_', ' ', $gateway_id ) );
	}

	/**
	 * Initialize gateway connectivity checker scheduler
	 */
	private function init_gateway_connectivity_scheduler() {
		// Schedule the gateway connectivity check cron job
		if ( ! wp_next_scheduled( 'paysentinel_gateway_connectivity_check' ) ) {
			wp_schedule_event( time(), 'wc_monitor_30min', 'paysentinel_gateway_connectivity_check' );
		}

		// Hook to run the connectivity check
		add_action( 'paysentinel_gateway_connectivity_check', array( $this, 'run_gateway_connectivity_check' ) );
	}

	/**
	 * Run gateway connectivity checks
	 * This is called by WordPress cron every 30 minutes
	 */
	public function run_gateway_connectivity_check() {
		try {
			$connectivity = new PaySentinel_Gateway_Connectivity();
			$results      = $connectivity->check_all_gateways();

			// Log connectivity check results
			do_action( 'paysentinel_connectivity_check_complete', $results );

			// Clean up old connectivity records based on license tier
			$license        = new PaySentinel_License();
			$tier           = $license->get_license_tier();
			$retention_days = isset( PaySentinel_License::RETENTION_LIMITS[ $tier ] ) ? PaySentinel_License::RETENTION_LIMITS[ $tier ] : 7;

			$connectivity->cleanup_old_checks( $retention_days );
		} catch ( Exception $e ) {
			error_log(
				'Payment Monitor: Error during gateway connectivity check: ' . $e->getMessage()
			);
		}
	}

	/**
	 * Run daily cleanup tasks
	 */
	public function run_daily_cleanup() {
		try {
			$license        = new PaySentinel_License();
			$tier           = $license->get_license_tier();
			$retention_days = isset( PaySentinel_License::RETENTION_LIMITS[ $tier ] ) ? PaySentinel_License::RETENTION_LIMITS[ $tier ] : 7;

			$database = new PaySentinel_Database();
			$database->cleanup_old_transactions( $retention_days );
			$database->cleanup_old_alerts( 30 ); // Alerts kept for 30 days
			$database->optimize_tables();
		} catch ( Exception $e ) {
			error_log( 'Payment Monitor: Error during daily cleanup: ' . $e->getMessage() );
		}
	}

	/**
	 * Plugin activation
	 */
	public function activate() {
		// Note: Requirements check moved to init() method to ensure WooCommerce is loaded
		// This allows activation even if WooCommerce loads after this plugin
		try {
			// Create database tables.
			$database = new PaySentinel_Database();
			$database->create_tables();

			// Set plugin version.
			update_option( 'paysentinel_version', PAYSENTINEL_VERSION );

			// Set default options.
			$this->set_default_options();

			// Trigger health calculation scheduling.
			do_action( 'paysentinel_activated' );
		} catch ( \Throwable $e ) {
			error_log( 'PaySentinel activation error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine() );
			// Re-throw so WordPress shows the activation error notice instead of a blank screen.
			throw $e;
		}
	}

	/**
	 * Plugin deactivation
	 */
	public function deactivate() {
		// Clear scheduled events
		wp_clear_scheduled_hook( 'paysentinel_health_calculation' );
		wp_clear_scheduled_hook( 'paysentinel_process_retries' );
		wp_clear_scheduled_hook( 'paysentinel_gateway_connectivity_check' );

		// Clear Action Scheduler actions
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( 'paysentinel_retry_payment' );
		}
	}

	/**
	 * Plugin uninstall
	 */
	public static function uninstall() {
		// Remove database tables
		require_once plugin_dir_path( __FILE__ ) . 'includes/class-paysentinel-database.php';
		$database = new PaySentinel_Database();
		$database->drop_tables();

		// Remove options
		delete_option( 'paysentinel_version' );
		delete_option( 'paysentinel_settings' );
		delete_option( 'paysentinel_retry_stats' );

		// Clear scheduled events
		wp_clear_scheduled_hook( 'paysentinel_health_calculation' );
		wp_clear_scheduled_hook( 'paysentinel_process_retries' );

		// Clear Action Scheduler actions
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( 'paysentinel_retry_payment' );
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
		return in_array( 'woocommerce/woocommerce.php', $active_plugins, true );
	}

	/**
	 * Set default plugin options
	 */
	private function set_default_options() {
		$default_settings = array(
			PaySentinel_Settings_Constants::ENABLED_GATEWAYS => array(),

			PaySentinel_Settings_Constants::ALERT_THRESHOLD => 85,
			PaySentinel_Settings_Constants::GATEWAY_ALERT_CONFIG => array(), // Per-gateway configuration (Pro+ feature)
		);

		add_option( 'paysentinel_settings', $default_settings );

		// Also set defaults in main options (paysentinel_options)
		$default_options = array(
			PaySentinel_Settings_Constants::ENABLE_MONITORING => true,
			PaySentinel_Settings_Constants::HEALTH_CHECK_INTERVAL => 300, // 5 minutes
			PaySentinel_Settings_Constants::RETRY_ENABLED  => true,
			PaySentinel_Settings_Constants::MAX_RETRY_ATTEMPTS => 3,
			PaySentinel_Settings_Constants::RETRY_SCHEDULE => array( 3600, 21600, 86400 ), // 1h, 6h, 24h
		);

		add_option( 'paysentinel_options', $default_options );
	}

	/**
	 * WooCommerce missing notice
	 */
	public function woocommerce_missing_notice() {
		echo '<div class="error"><p><strong>' .
			__( 'PaySentinel - Payment Monitor for WooCommerce', 'paysentinel' ) .
			'</strong> ' .
			__( 'requires WooCommerce to be installed and active.', 'paysentinel' ) .
			'</p></div>';
	}

	/**
	 * Show WordPress version notice
	 */
	public function wordpress_version_notice() {
		echo '<div class="error"><p><strong>' .
			__( 'PaySentinel - Payment Monitor for WooCommerce', 'paysentinel' ) .
			'</strong> ' .
			__( 'requires WordPress 5.0 or higher.', 'paysentinel' ) .
			'</p></div>';
	}

	/**
	 * Show WooCommerce version notice
	 */
	public function woocommerce_version_notice() {
		echo '<div class="error"><p><strong>' .
			__( 'PaySentinel - Payment Monitor for WooCommerce', 'paysentinel' ) .
			'</strong> ' .
			__( 'requires WooCommerce 5.0 or higher.', 'paysentinel' ) .
			'</p></div>';
	}
}

// Initialize plugin
PaySentinel::get_instance();
