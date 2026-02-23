<?php
/**
 * Admin pages and menu registration
 *
 * Main admin class that coordinates the various admin handler components.
 * This class has been refactored to delegate responsibilities to specialized handlers.
 *
 * @package PaySentinel
 * @since 1.0.0
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PaySentinel_Admin {

	/**
	 * Database instance
	 *
	 * @var PaySentinel_Database
	 */
	private $database;

	/**
	 * Security instance
	 *
	 * @var PaySentinel_Security
	 */
	private $security;

	/**
	 * License instance
	 *
	 * @var PaySentinel_License
	 */
	private $license;

	/**
	 * Menu handler instance
	 *
	 * @var PaySentinel_Admin_Menu_Handler
	 */
	private $menu_handler;

	/**
	 * Settings handler instance
	 *
	 * @var PaySentinel_Admin_Settings_Handler
	 */
	private $settings_handler;

	/**
	 * Page renderer instance
	 *
	 * @var PaySentinel_Admin_Page_Renderer
	 */
	private $page_renderer;

	/**
	 * AJAX handler instance
	 *
	 * @var PaySentinel_Admin_Ajax_Handler
	 */
	private $ajax_handler;

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->database = new PaySentinel_Database();
		$this->security = new PaySentinel_Security();
		$this->license  = new PaySentinel_License();

		// Initialize handler instances
		$this->settings_handler = new PaySentinel_Admin_Settings_Handler( $this->security, $this->license );
		$this->page_renderer    = new PaySentinel_Admin_Page_Renderer( $this->database, $this->license, $this->settings_handler );
		$this->menu_handler     = new PaySentinel_Admin_Menu_Handler( $this->page_renderer );
		$this->ajax_handler     = new PaySentinel_Admin_Ajax_Handler( $this->license );

		$this->init_hooks();
	}

	/**
	 * Initialize WordPress hooks
	 */
	private function init_hooks() {
		// Menu registration
		add_action( 'admin_menu', array( $this->menu_handler, 'register_menu_pages' ) );

		// Settings registration
		add_action( 'admin_init', array( $this->settings_handler, 'register_settings' ) );

		// Scripts and styles
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ) );

		// Slack OAuth callback
		add_action( 'admin_init', array( $this->ajax_handler, 'handle_slack_callback' ) );

		// AJAX handlers
		add_action( 'wp_ajax_paysentinel_slack_test', array( $this->ajax_handler, 'handle_slack_test' ) );
		add_action( 'wp_ajax_paysentinel_sync_integrations', array( $this->ajax_handler, 'handle_sync_integrations' ) );
		add_action( 'wp_ajax_paysentinel_validate_license', array( $this->ajax_handler, 'handle_validate_license_ajax' ) );

		// Admin POST actions
		add_action( 'admin_post_paysentinel_retry', array( $this, 'handle_manual_retry' ) );
		add_action( 'admin_post_paysentinel_recovery', array( $this, 'handle_recovery_email' ) );
		add_action( 'admin_post_paysentinel_deactivate_license', array( $this, 'handle_deactivate_license' ) );
		add_action( 'admin_post_paysentinel_save_license', array( $this->ajax_handler, 'handle_save_license' ) );
	}

	/**
	 * Enqueue admin scripts and styles
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_admin_scripts( $hook ) {
		// Only load on our plugin pages
		if ( strpos( $hook, 'paysentinel' ) === false ) {
			return;
		}

		// Ensure constants are defined
		if ( ! defined( 'PAYSENTINEL_PLUGIN_URL' ) || ! defined( 'PAYSENTINEL_VERSION' ) ) {
			return;
		}

		// Enqueue WordPress REST API dependencies
		wp_enqueue_script( 'wp-api-fetch' );
		wp_enqueue_script( 'wp-element' );
		wp_enqueue_script( 'wp-components' );
		wp_enqueue_script( 'wp-i18n' );

		// Enqueue Chart.js 4.x from CDN for data visualization
		wp_register_script(
			'chartjs',
			'https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js',
			array(),
			'4.4.1',
			true
		);

		// Enqueue our dashboard script
		$dashboard_js_path  = PAYSENTINEL_PLUGIN_DIR . 'assets/js/dashboard/index.js';
		$dashboard_css_path = PAYSENTINEL_PLUGIN_DIR . 'assets/js/dashboard/index.css';
		$js_ver             = file_exists( $dashboard_js_path ) ? filemtime( $dashboard_js_path ) : PAYSENTINEL_VERSION;
		$css_ver            = file_exists( $dashboard_css_path ) ? filemtime( $dashboard_css_path ) : PAYSENTINEL_VERSION;

		wp_enqueue_script(
			'paysentinel-dashboard',
			PAYSENTINEL_PLUGIN_URL . 'assets/js/dashboard/index.js',
			array( 'wp-api-fetch', 'wp-element', 'chartjs' ),
			$js_ver,
			true
		);

		wp_enqueue_style(
			'paysentinel-dashboard',
			PAYSENTINEL_PLUGIN_URL . 'assets/js/dashboard/index.css',
			array(),
			$css_ver
		);

		// Prepare license tier data
		$tier        = $this->license->get_license_tier();
		$tier_labels = array(
			'free'    => __( 'Free', 'paysentinel' ),
			'starter' => __( 'Starter', 'paysentinel' ),
			'pro'     => __( 'Pro', 'paysentinel' ),
			'agency'  => __( 'Agency', 'paysentinel' ),
		);
		$tier_colors = array(
			'free'    => '#6c757d',
			'starter' => '#0073aa',
			'pro'     => '#46b450',
			'agency'  => '#9b51e0',
		);

		// Localize script with admin data
		wp_localize_script(
			'paysentinel-dashboard',
			'wcPaymentMonitor',
			array(
				'apiUrl'      => rest_url( 'paysentinel/v1' ),
				'root'        => esc_url_raw( rest_url() ),
				'nonce'       => wp_create_nonce( 'wp_rest' ),
				'restNonce'   => wp_create_nonce( 'wp_rest' ),
				'currentUser' => get_current_user_id(),
				'adminNonce'  => wp_create_nonce( 'paysentinel_admin_nonce' ),
				'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
				'tier'        => $tier,
				'isPremium'   => $this->is_premium(),
				'license'     => array(
					'tier'  => $tier,
					'label' => isset( $tier_labels[ $tier ] ) ? $tier_labels[ $tier ] : ucfirst( $tier ),
					'color' => isset( $tier_colors[ $tier ] ) ? $tier_colors[ $tier ] : '#0073aa',
				),
			)
		);
	}

	/**
	 * Handle manual retry action
	 */
	public function handle_manual_retry() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'paysentinel' ) );
		}

		$order_id = isset( $_GET['order_id'] ) ? intval( $_GET['order_id'] ) : 0;
		check_admin_referer( 'paysentinel_retry_' . $order_id );

		if ( ! $order_id ) {
			wp_redirect( admin_url( 'admin.php?page=paysentinel-transactions&message=' . urlencode( __( 'Invalid order ID.', 'paysentinel' ) ) . '&type=error' ) );
			exit;
		}

		// Get retry instance
		if ( ! isset( PaySentinel::get_instance()->retry ) ) {
			wp_redirect( admin_url( 'admin.php?page=paysentinel-transactions&message=' . urlencode( __( 'Retry component not available.', 'paysentinel' ) ) . '&type=error' ) );
			exit;
		}

		$result = PaySentinel::get_instance()->retry->manual_retry( $order_id );
		$type   = $result['success'] ? 'success' : 'error';

		wp_redirect( admin_url( 'admin.php?page=paysentinel-transactions&message=' . urlencode( $result['message'] ) . '&type=' . $type ) );
		exit;
	}

	/**
	 * Handle recovery email action
	 */
	public function handle_recovery_email() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'paysentinel' ) );
		}

		$order_id = isset( $_GET['order_id'] ) ? intval( $_GET['order_id'] ) : 0;
		check_admin_referer( 'paysentinel_recovery_' . $order_id );

		if ( ! $order_id ) {
			wp_redirect( admin_url( 'admin.php?page=paysentinel-transactions&message=' . urlencode( __( 'Invalid order ID.', 'paysentinel' ) ) . '&type=error' ) );
			exit;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			wp_redirect( admin_url( 'admin.php?page=paysentinel-transactions&message=' . urlencode( __( 'Order not found.', 'paysentinel' ) ) . '&type=error' ) );
			exit;
		}

		// Get retry instance
		if ( ! isset( PaySentinel::get_instance()->retry ) ) {
			wp_redirect( admin_url( 'admin.php?page=paysentinel-transactions&message=' . urlencode( __( 'Retry component not available.', 'paysentinel' ) ) . '&type=error' ) );
			exit;
		}

		PaySentinel::get_instance()->retry->send_recovery_email( $order );

		wp_redirect( admin_url( 'admin.php?page=paysentinel-transactions&message=' . urlencode( __( 'Recovery email sent successfully.', 'paysentinel' ) ) . '&type=success' ) );
		exit;
	}

	/**
	 * Handle license deactivation
	 */
	public function handle_deactivate_license() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'paysentinel' ) );
		}

		check_admin_referer( 'paysentinel_deactivate_license' );

		// Deactivate license
		$this->license->deactivate_license();

		wp_redirect( admin_url( 'admin.php?page=paysentinel-settings&tab=license&message=' . urlencode( __( 'License deactivated successfully.', 'paysentinel' ) ) . '&type=info' ) );
		exit;
	}

	/**
	 * Get license tier
	 *
	 * @return string License tier (free, starter, pro, agency)
	 */
	public function get_license_tier() {
		return $this->license->get_license_tier();
	}

	/**
	 * Render dashboard page
	 */
	public function render_dashboard_page() {
		$this->page_renderer->render_dashboard_page();
	}

	/**
	 * Render health page
	 */
	public function render_health_page() {
		$this->page_renderer->render_health_page();
	}

	/**
	 * Render transactions page
	 */
	public function render_transactions_page() {
		$this->page_renderer->render_transactions_page();
	}

	/**
	 * Render alerts page
	 */
	public function render_alerts_page() {
		$this->page_renderer->render_alerts_page();
	}

	/**
	 * Render settings page
	 */
	public function render_settings_page() {
		$this->page_renderer->render_settings_page();
	}

	/**
	 * Get plugin settings
	 *
	 * @return array Plugin settings with defaults
	 */
	public static function get_settings() {
		$defaults = array(
			'enable_monitoring'     => 1, // Changed to int for test compatibility
			'health_check_interval' => 300,
			'alert_threshold'       => 95,
			'retry_enabled'         => 1, // Changed to int for test compatibility
			'max_retry_attempts'    => 3,
			'license_key'           => '',
			'enable_test_mode'      => 0, // Changed to int for test compatibility
			'alert_email'           => '',
			'alert_phone_number'    => '',
			'alert_slack_workspace' => '',
			'gateway_alert_config'  => array(),
			'test_failure_rate'     => 0,
		);

		$settings = get_option( 'paysentinel_options', array() );
		return wp_parse_args( $settings, $defaults );
	}

	/**
	 * Get a specific setting value
	 *
	 * @param string $key     Setting key
	 * @param mixed  $default Default value if setting doesn't exist
	 * @return mixed Setting value
	 */
	public static function get_setting( $key, $default = null ) {
		$settings = self::get_settings();
		return isset( $settings[ $key ] ) ? $settings[ $key ] : $default;
	}

	/**
	 * Update plugin settings
	 *
	 * @param array $new_settings New settings to merge
	 * @return bool Success
	 */
	public static function update_settings( $new_settings ) {
		if ( ! is_array( $new_settings ) ) {
			return false;
		}

		$current_settings = get_option( 'paysentinel_options', array() );
		$updated_settings = array_merge( $current_settings, $new_settings );

		return update_option( 'paysentinel_options', $updated_settings );
	}

	/**
	 * Validate health check interval
	 *
	 * @param int $interval Interval in minutes
	 * @return array Validation result with 'valid', 'value', and optional 'message'
	 */
	public static function validate_health_check_interval( $interval ) {
		$interval = intval( $interval );

		if ( $interval < 1 ) {
			return array(
				'valid'   => false,
				'value'   => $interval,
				'message' => 'Health check interval must be at least 1 minute',
			);
		}

		if ( $interval > 1440 ) {
			return array(
				'valid'   => false,
				'value'   => $interval,
				'message' => 'Health check interval cannot exceed 1440 minutes (24 hours)',
			);
		}

		return array(
			'valid' => true,
			'value' => $interval,
		);
	}

	/**
	 * Validate retry configuration
	 *
	 * @param array $config Retry configuration
	 * @return array Validation result
	 */
	public static function validate_retry_configuration( $config ) {
		if ( ! is_array( $config ) ) {
			return array(
				'valid'   => false,
				'value'   => $config,
				'message' => 'Retry configuration must be an array',
				'errors'  => array( 'Retry configuration must be an array' ),
			);
		}

		if ( empty( $config ) ) {
			return array(
				'valid'   => false,
				'value'   => $config,
				'message' => 'Retry configuration cannot be empty',
				'errors'  => array( 'Retry configuration cannot be empty' ),
			);
		}

		$validated = array();
		$errors    = array();

		// Validate max_retry_attempts
		if ( isset( $config['max_retry_attempts'] ) ) {
			$attempts = intval( $config['max_retry_attempts'] );
			if ( $attempts < 1 || $attempts > 10 ) {
				$errors[] = 'Max retry attempts must be between 1 and 10';
			} else {
				$validated['max_retry_attempts'] = $attempts;
			}
		}

		// Validate retry_enabled
		if ( isset( $config['retry_enabled'] ) ) {
			$validated['retry_enabled'] = (bool) $config['retry_enabled'];
		}

		if ( ! empty( $errors ) ) {
			return array(
				'valid'   => false,
				'value'   => $config,
				'message' => implode( ', ', $errors ),
				'errors'  => $errors,
			);
		}

		return array(
			'valid' => true,
			'value' => $validated,
		);
	}

	/**
	 * Validate alert threshold
	 *
	 * @param float $threshold Alert threshold percentage
	 * @return array Validation result
	 */
	public static function validate_alert_threshold( $threshold ) {
		$threshold = floatval( $threshold );

		if ( $threshold < 0.1 || $threshold > 100 ) {
			return array(
				'valid'   => false,
				'value'   => $threshold,
				'message' => 'Alert threshold must be between 0.1 and 100',
				'errors'  => array( 'Alert threshold must be between 0.1 and 100' ),
			);
		}

		return array(
			'valid' => true,
			'value' => $threshold,
		);
	}

	/**
	 * Validate all admin settings
	 *
	 * @param array $settings Settings to validate
	 * @return array Validation result with 'valid', 'errors', and 'validated_settings'
	 */
	public static function validate_all_settings( $settings ) {
		if ( ! is_array( $settings ) ) {
			return array(
				'valid'              => false,
				'errors'             => array( 'Settings must be an array' ),
				'validated_settings' => array(),
			);
		}

		$errors             = array();
		$validated_settings = array();

		// Validate health_check_interval
		if ( isset( $settings['health_check_interval'] ) ) {
			$value = intval( $settings['health_check_interval'] );
			if ( $value < 1 || $value > 60 ) {
				$errors['health_check_interval'] = 'Health check interval must be between 1 and 60 minutes';
			} else {
				$validated_settings['health_check_interval'] = $value;
			}
		}

		// Validate alert_threshold
		if ( isset( $settings['alert_threshold'] ) ) {
			$value = floatval( $settings['alert_threshold'] );
			if ( $value < 0 || $value > 100 ) {
				$errors['alert_threshold'] = 'Alert threshold must be between 0 and 100';
			} else {
				$validated_settings['alert_threshold'] = $value;
			}
		}

		// Validate max_retry_attempts
		if ( isset( $settings['max_retry_attempts'] ) ) {
			$value = intval( $settings['max_retry_attempts'] );
			if ( $value < 0 || $value > 10 ) {
				$errors['max_retry_attempts'] = 'Max retry attempts must be between 0 and 10';
			} else {
				$validated_settings['max_retry_attempts'] = $value;
			}
		}

		// Validate other settings (basic validation)
		$valid_keys = array(
			'enable_monitoring',
			'retry_enabled',
			'enable_test_mode',
			'alert_threshold',
			'health_check_interval',
			'max_retry_attempts',
			'retry_delay',
			'alert_email',
			'slack_webhook_url',
			'sms_enabled',
			'twilio_sid',
			'twilio_token',
			'twilio_from_number',
			'alert_phone_numbers',
		);

		foreach ( $settings as $key => $value ) {
			if ( in_array( $key, $valid_keys, true ) ) {
				if ( is_numeric( $value ) ) {
					$validated_settings[ $key ] = is_float( $value ) ? floatval( $value ) : intval( $value );
				} elseif ( is_string( $value ) ) {
					$validated_settings[ $key ] = sanitize_text_field( $value );
				} elseif ( is_array( $value ) ) {
					$validated_settings[ $key ] = $value; // Assume arrays are already validated
				}
			}
		}

		return array(
			'valid'              => empty( $errors ),
			'errors'             => $errors,
			'validated_settings' => $validated_settings,
		);
	}

	/**
	 * Check if the current license is premium (not free)
	 *
	 * @return bool True if premium license, false if free
	 */
	public function is_premium() {
		$tier = $this->license->get_license_tier();
		return in_array( $tier, array( 'starter', 'pro', 'agency' ), true );
	}
}
