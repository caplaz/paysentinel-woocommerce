<?php
/**
 * Admin pages and menu registration
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
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
	 * License instance
	 */
	private $license;

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->database = new WC_Payment_Monitor_Database();
		$this->security = new WC_Payment_Monitor_Security();
		$this->license  = new WC_Payment_Monitor_License();
		$this->init_hooks();
	}

	/**
	 * Initialize WordPress hooks
	 */
	private function init_hooks() {
		add_action( 'admin_menu', array( $this, 'register_menu_pages' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ) );
		add_action( 'update_option_wc_payment_monitor_options', array( $this, 'validate_license_on_save' ), 10, 2 );
	}

	/**
	 * Enqueue admin scripts and styles
	 */
	public function enqueue_admin_scripts( $hook ) {
		// Only load on our plugin pages
		if ( strpos( $hook, 'wc-payment-monitor' ) === false ) {
			return;
		}

		// Ensure constants are defined
		if ( ! defined( 'WC_PAYMENT_MONITOR_PLUGIN_URL' ) || ! defined( 'WC_PAYMENT_MONITOR_VERSION' ) ) {
			return;
		}

		// Enqueue WordPress REST API dependencies
		wp_enqueue_script( 'wp-api-fetch' );
		wp_enqueue_script( 'wp-element' );
		wp_enqueue_script( 'wp-components' );
		wp_enqueue_script( 'wp-i18n' );

		// Enqueue our dashboard script
		$dashboard_js_path  = WC_PAYMENT_MONITOR_PLUGIN_DIR . 'assets/js/dashboard/index.js';
		$dashboard_css_path = WC_PAYMENT_MONITOR_PLUGIN_DIR . 'assets/js/dashboard/index.css';
		$js_ver  = file_exists( $dashboard_js_path ) ? filemtime( $dashboard_js_path ) : WC_PAYMENT_MONITOR_VERSION;
		$css_ver = file_exists( $dashboard_css_path ) ? filemtime( $dashboard_css_path ) : WC_PAYMENT_MONITOR_VERSION;

		wp_enqueue_script(
			'wc-payment-monitor-dashboard',
			WC_PAYMENT_MONITOR_PLUGIN_URL . 'assets/js/dashboard/index.js',
			array( 'wp-api-fetch', 'wp-element' ),
			$js_ver,
			true
		);

		// Enqueue our dashboard styles
		wp_enqueue_style(
			'wc-payment-monitor-dashboard',
			WC_PAYMENT_MONITOR_PLUGIN_URL . 'assets/js/dashboard/index.css',
			array(),
			$css_ver
		);

		// Use wp_set_script_translations for REST API nonce
		wp_set_script_translations(
			'wc-payment-monitor-dashboard',
			'wc-payment-monitor'
		);

		// Localize script with API data
		wp_localize_script(
			'wc-payment-monitor-dashboard',
			'wcPaymentMonitor',
			array(
				'apiUrl'      => rest_url( 'wc-payment-monitor/v1/' ),
				'nonce'       => wp_create_nonce( 'wp_rest' ),
				'currentUser' => get_current_user_id(),
				'restNonce'   => sanitize_text_field( wp_create_nonce( 'wp_rest' ) ),
			)
		);
	}

	/**
	 * Register admin menu and pages
	 */
	public function register_menu_pages() {
		// Check user capability
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		// Add main menu page
		add_menu_page(
			__( 'Payment Monitor', 'wc-payment-monitor' ),
			__( 'Payment Monitor', 'wc-payment-monitor' ),
			'manage_woocommerce',
			'wc-payment-monitor',
			array( $this, 'render_dashboard_page' ),
			'dashicons-chart-line',
			56
		);

		// Add dashboard submenu
		add_submenu_page(
			'wc-payment-monitor',
			__( 'Dashboard', 'wc-payment-monitor' ),
			__( 'Dashboard', 'wc-payment-monitor' ),
			'manage_woocommerce',
			'wc-payment-monitor',
			array( $this, 'render_dashboard_page' )
		);

		// Add gateway health submenu
		add_submenu_page(
			'wc-payment-monitor',
			__( 'Gateway Health', 'wc-payment-monitor' ),
			__( 'Gateway Health', 'wc-payment-monitor' ),
			'manage_woocommerce',
			'wc-payment-monitor-health',
			array( $this, 'render_health_page' )
		);

		// Add transaction logs submenu
		add_submenu_page(
			'wc-payment-monitor',
			__( 'Transactions', 'wc-payment-monitor' ),
			__( 'Transactions', 'wc-payment-monitor' ),
			'manage_woocommerce',
			'wc-payment-monitor-transactions',
			array( $this, 'render_transactions_page' )
		);

		// Add alerts submenu
		add_submenu_page(
			'wc-payment-monitor',
			__( 'Alerts', 'wc-payment-monitor' ),
			__( 'Alerts', 'wc-payment-monitor' ),
			'manage_woocommerce',
			'wc-payment-monitor-alerts',
			array( $this, 'render_alerts_page' )
		);

		// Add settings submenu
		add_submenu_page(
			'wc-payment-monitor',
			__( 'Settings', 'wc-payment-monitor' ),
			__( 'Settings', 'wc-payment-monitor' ),
			'manage_woocommerce',
			'wc-payment-monitor-settings',
			array( $this, 'render_settings_page' )
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
				'type'              => 'object',
				'sanitize_callback' => array( $this->security, 'validate_admin_settings' ),
				'show_in_rest'      => false,
			)
		);

		// Add settings section
		add_settings_section(
			'wc_payment_monitor_main',
			__( 'Payment Monitor Settings', 'wc-payment-monitor' ),
			array( $this, 'render_settings_section' ),
			'wc_payment_monitor_settings'
		);

		// Add settings fields
		add_settings_field(
			'enable_monitoring',
			__( 'Enable Monitoring', 'wc-payment-monitor' ),
			array( $this, 'render_field_enable_monitoring' ),
			'wc_payment_monitor_settings',
			'wc_payment_monitor_main'
		);

		add_settings_field(
			'health_check_interval',
			__( 'Health Check Interval (minutes)', 'wc-payment-monitor' ),
			array( $this, 'render_field_health_check_interval' ),
			'wc_payment_monitor_settings',
			'wc_payment_monitor_main'
		);

		add_settings_field(
			'alert_threshold',
			__( 'Alert Threshold (%)', 'wc-payment-monitor' ),
			array( $this, 'render_field_alert_threshold' ),
			'wc_payment_monitor_settings',
			'wc_payment_monitor_main'
		);

		add_settings_field(
			'retry_enabled',
			__( 'Enable Payment Retry', 'wc-payment-monitor' ),
			array( $this, 'render_field_retry_enabled' ),
			'wc_payment_monitor_settings',
			'wc_payment_monitor_main'
		);

		add_settings_field(
			'max_retry_attempts',
			__( 'Max Retry Attempts', 'wc-payment-monitor' ),
			array( $this, 'render_field_max_retry_attempts' ),
			'wc_payment_monitor_settings',
			'wc_payment_monitor_main'
		);

		add_settings_field(
			'license_key',
			__( 'License Key', 'wc-payment-monitor' ),
			array( $this, 'render_field_license_key' ),
			'wc_payment_monitor_settings',
			'wc_payment_monitor_main'
		);
	}

	/**
	 * Render settings section
	 */
	public function render_settings_section() {
		echo '<p>' . esc_html__( 'Configure Payment Monitor settings below.', 'wc-payment-monitor' ) . '</p>';
	}

	/**
	 * Render enable monitoring field
	 */
	public function render_field_enable_monitoring() {
		$options = get_option( 'wc_payment_monitor_options', array() );
		$enabled = isset( $options['enable_monitoring'] ) ? intval( $options['enable_monitoring'] ) : 1;
		?>
		<input type="checkbox" name="wc_payment_monitor_options[enable_monitoring]" value="1" <?php checked( $enabled, 1 ); ?> />
		<label><?php esc_html_e( 'Monitor payment gateway transactions', 'wc-payment-monitor' ); ?></label>
		<?php
	}

	/**
	 * Render health check interval field
	 */
	public function render_field_health_check_interval() {
		$options  = get_option( 'wc_payment_monitor_options', array() );
		$interval = isset( $options['health_check_interval'] ) ? intval( $options['health_check_interval'] ) : 5;
		?>
		<input type="number" name="wc_payment_monitor_options[health_check_interval]" value="<?php echo esc_attr( $interval ); ?>" min="1" max="1440" />
		<p class="description"><?php esc_html_e( 'How often to recalculate gateway health (in minutes).', 'wc-payment-monitor' ); ?></p>
		<?php
	}

	/**
	 * Render alert threshold field
	 */
	public function render_field_alert_threshold() {
		$options   = get_option( 'wc_payment_monitor_options', array() );
		$threshold = isset( $options['alert_threshold'] ) ? floatval( $options['alert_threshold'] ) : 20;
		?>
		<input type="number" name="wc_payment_monitor_options[alert_threshold]" value="<?php echo esc_attr( $threshold ); ?>" min="1" max="100" step="0.1" />
		<p class="description"><?php esc_html_e( 'Failure rate percentage to trigger alerts.', 'wc-payment-monitor' ); ?></p>
		<?php
	}

	/**
	 * Render retry enabled field
	 */
	public function render_field_retry_enabled() {
		$options = get_option( 'wc_payment_monitor_options', array() );
		$enabled = isset( $options['retry_enabled'] ) ? intval( $options['retry_enabled'] ) : 1;
		?>
		<input type="checkbox" name="wc_payment_monitor_options[retry_enabled]" value="1" <?php checked( $enabled, 1 ); ?> />
		<label><?php esc_html_e( 'Automatically retry failed payments', 'wc-payment-monitor' ); ?></label>
		<?php
	}

	/**
	 * Render max retry attempts field
	 */
	public function render_field_max_retry_attempts() {
		$options  = get_option( 'wc_payment_monitor_options', array() );
		$attempts = isset( $options['max_retry_attempts'] ) ? intval( $options['max_retry_attempts'] ) : 3;
		?>
		<input type="number" name="wc_payment_monitor_options[max_retry_attempts]" value="<?php echo esc_attr( $attempts ); ?>" min="1" max="10" />
		<p class="description"><?php esc_html_e( 'Maximum number of retry attempts per transaction.', 'wc-payment-monitor' ); ?></p>
		<?php
	}

	/**
	 * Render license key field
	 */
	public function render_field_license_key() {
		$options       = get_option( 'wc_payment_monitor_options', array() );
		$license_key   = isset( $options['license_key'] ) ? sanitize_text_field( $options['license_key'] ) : '';
		$license_status = $this->license->get_license_status();
		$license_data  = $this->license->get_license_data();
		?>
		<div style="display: flex; align-items: center; gap: 10px;">
			<input type="password" id="wc_payment_monitor_license_key" name="wc_payment_monitor_options[license_key]" value="<?php echo esc_attr( $license_key ); ?>" class="regular-text" />
			<button type="button" class="button" onclick="var field = document.getElementById('wc_payment_monitor_license_key'); var type = field.type === 'password' ? 'text' : 'password'; field.type = type; this.textContent = type === 'password' ? 'Show' : 'Hide';" style="min-width: 60px;">
				<?php esc_html_e( 'Show', 'wc-payment-monitor' ); ?>
			</button>
		</div>
		<p class="description"><?php esc_html_e( 'Enter your license key to enable premium features.', 'wc-payment-monitor' ); ?></p>
		<?php if ( 'valid' === $license_status ) : ?>
			<p style="color: #46b450;">
				<span class="dashicons dashicons-yes-alt" style="color: #46b450;"></span>
				<?php esc_html_e( 'License is active and valid', 'wc-payment-monitor' ); ?>
			</p>
			<?php if ( $license_data ) : ?>
				<?php if ( isset( $license_data['plan'] ) ) : ?>
					<p style="margin: 10px 0; font-size: 16px; font-weight: bold; color: #0073aa;">
					<?php echo esc_html( ucwords( $license_data['plan'] ) ); ?> Plan
					</p>
				<?php endif; ?>
				<?php if ( isset( $license_data['expiration_ts'] ) ) : ?>
					<p style="margin: 5px 0; font-size: 14px;">
						<?php echo esc_html( sprintf( __( 'Expires: %s', 'wc-payment-monitor' ), date_i18n( get_option( 'date_format' ), strtotime( $license_data['expiration_ts'] ) ) ) ); ?>
					</p>
				<?php endif; ?>
			<?php endif; ?>
		<?php elseif ( 'invalid' === $license_status ) : ?>
			<p style="color: #dc3232;">
				<span class="dashicons dashicons-warning" style="color: #dc3232;"></span>
				<?php esc_html_e( 'License is invalid or expired', 'wc-payment-monitor' ); ?>
			</p>
		<?php endif; ?>
		<?php
	}

	/**
	 * Render dashboard page
	 */
	public function render_dashboard_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'wc-payment-monitor' ) );
		}

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Payment Monitor Dashboard', 'wc-payment-monitor' ); ?></h1>
			<div id="wc-payment-monitor-root"></div>
		</div>
		<?php
	}

	/**
	 * Render gateway health page
	 */
	public function render_health_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'wc-payment-monitor' ) );
		}

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Gateway Health', 'wc-payment-monitor' ); ?></h1>
			<p><?php esc_html_e( 'Real-time health metrics for all payment gateways.', 'wc-payment-monitor' ); ?></p>
			<div id="wc-payment-monitor-health-container"></div>
		</div>
		<?php
	}

	/**
	 * Render transactions page
	 */
	public function render_transactions_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'wc-payment-monitor' ) );
		}

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Transaction Log', 'wc-payment-monitor' ); ?></h1>
			<p><?php esc_html_e( 'View all monitored payment transactions.', 'wc-payment-monitor' ); ?></p>
			<div id="wc-payment-monitor-transactions-container"></div>
		</div>
		<?php
	}

	/**
	 * Render alerts page
	 */
	public function render_alerts_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'wc-payment-monitor' ) );
		}

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Alerts', 'wc-payment-monitor' ); ?></h1>
			<p><?php esc_html_e( 'View all payment monitoring alerts.', 'wc-payment-monitor' ); ?></p>
			<div id="wc-payment-monitor-alerts-container"></div>
		</div>
		<?php
	}

	/**
	 * Render settings page
	 */
	public function render_settings_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'wc-payment-monitor' ) );
		}

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Payment Monitor Settings', 'wc-payment-monitor' ); ?></h1>
			<?php settings_errors( 'wc_payment_monitor_options' ); ?>
			<form method="post" action="options.php">
				<?php
				settings_fields( 'wc_payment_monitor_settings' );
				do_settings_sections( 'wc_payment_monitor_settings' );
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
			'enable_monitoring'     => 1,
			'health_check_interval' => 5,
			'alert_threshold'       => 20,
			'retry_enabled'         => 1,
			'max_retry_attempts'    => 3,
			'license_key'           => '',
		);

		$options = get_option( 'wc_payment_monitor_options', array() );
		return wp_parse_args( $options, $defaults );
	}

	/**
	 * Get single setting
	 *
	 * @param string $setting Setting name
	 * @param mixed  $default Default value
	 * @return mixed Setting value
	 */
	public static function get_setting( $setting, $default = null ) {
		$settings = self::get_settings();
		return isset( $settings[ $setting ] ) ? $settings[ $setting ] : $default;
	}

	/**
	 * Update settings
	 *
	 * @param array $settings Settings to update
	 * @return bool True on success
	 */
	public static function update_settings( $settings ) {
		$current = self::get_settings();
		$updated = wp_parse_args( $settings, $current );
		return update_option( 'wc_payment_monitor_options', $updated );
	}

	/**
	 * Validate health check interval setting
	 *
	 * @param int $interval Health check interval in minutes
	 * @return array Validation result with 'valid' bool and 'message' string
	 */
	public static function validate_health_check_interval( $interval ) {
		$interval = intval( $interval );

		if ( $interval < 1 ) {
			return array(
				'valid'   => false,
				'message' => __( 'Health check interval must be at least 1 minute.', 'wc-payment-monitor' ),
			);
		}

		if ( $interval > 1440 ) {
			return array(
				'valid'   => false,
				'message' => __( 'Health check interval cannot exceed 1440 minutes (24 hours).', 'wc-payment-monitor' ),
			);
		}

		return array(
			'valid'   => true,
			'message' => '',
			'value'   => $interval,
		);
	}

	/**
	 * Validate alert threshold setting
	 *
	 * @param float $threshold Alert threshold percentage
	 * @return array Validation result with 'valid' bool and 'message' string
	 */
	public static function validate_alert_threshold( $threshold ) {
		$threshold = floatval( $threshold );

		if ( $threshold < 0.1 ) {
			return array(
				'valid'   => false,
				'message' => __( 'Alert threshold must be at least 0.1%.', 'wc-payment-monitor' ),
			);
		}

		if ( $threshold > 100 ) {
			return array(
				'valid'   => false,
				'message' => __( 'Alert threshold cannot exceed 100%.', 'wc-payment-monitor' ),
			);
		}

		return array(
			'valid'   => true,
			'message' => '',
			'value'   => $threshold,
		);
	}

	/**
	 * Validate retry configuration
	 *
	 * @param array $retry_config Retry configuration array
	 * @return array Validation result with 'valid' bool and 'message' string
	 */
	public static function validate_retry_configuration( $retry_config ) {
		if ( ! is_array( $retry_config ) ) {
			return array(
				'valid'   => false,
				'message' => __( 'Retry configuration must be an array.', 'wc-payment-monitor' ),
			);
		}

		// Check if array is empty or missing required key
		if ( empty( $retry_config ) || ! isset( $retry_config['max_retry_attempts'] ) ) {
			return array(
				'valid'   => false,
				'message' => __( 'Retry configuration must contain max_retry_attempts.', 'wc-payment-monitor' ),
				'errors'  => array( __( 'Retry configuration must contain max_retry_attempts.', 'wc-payment-monitor' ) ),
			);
		}

		$errors       = array();
		$max_attempts = intval( $retry_config['max_retry_attempts'] );

		if ( $max_attempts < 1 ) {
			$errors[] = __( 'Max retry attempts must be at least 1.', 'wc-payment-monitor' );
		}

		if ( $max_attempts > 10 ) {
			$errors[] = __( 'Max retry attempts cannot exceed 10.', 'wc-payment-monitor' );
		}

		if ( ! empty( $errors ) ) {
			return array(
				'valid'   => false,
				'message' => implode( ' ', $errors ),
				'errors'  => $errors,
			);
		}

		return array(
			'valid'   => true,
			'message' => '',
			'value'   => $retry_config,
		);
	}

	/**
	 * Validate license key
	 *
	 * @param string $license_key License key to validate
	 * @return array Validation result with 'valid' bool and 'message' string
	 */
	public static function validate_license_key( $license_key ) {
		$license_key = sanitize_text_field( $license_key );

		// Empty license key is valid (may be free tier)
		if ( empty( $license_key ) ) {
			return array(
				'valid'   => true,
				'message' => '',
				'tier'    => 'free',
			);
		}

		// License key format validation (alphanumeric and hyphens, 20-50 chars)
		if ( ! preg_match( '/^[A-Za-z0-9\-]{20,50}$/', $license_key ) ) {
			return array(
				'valid'   => false,
				'message' => __( 'License key format is invalid. Should be 20-50 alphanumeric characters with optional hyphens.', 'wc-payment-monitor' ),
			);
		}

		// Check if license is active (simulate license validation)
		// In production, this would call a remote license server
		$is_premium = apply_filters( 'wc_payment_monitor_validate_license', false, $license_key );

		if ( $is_premium ) {
			return array(
				'valid'   => true,
				'message' => '',
				'tier'    => 'premium',
				'value'   => $license_key,
			);
		} else {
			return array(
				'valid'   => false,
				'message' => __( 'License key is invalid or inactive. Please check and try again.', 'wc-payment-monitor' ),
			);
		}
	}

	/**
	 * Get current license tier
	 *
	 * @return string License tier ('free' or 'premium')
	 */
	public static function get_license_tier() {
		$settings    = self::get_settings();
		$license_key = isset( $settings['license_key'] ) ? $settings['license_key'] : '';

		if ( empty( $license_key ) ) {
			return 'free';
		}

		// Check if license is premium
		$is_premium = apply_filters( 'wc_payment_monitor_validate_license', false, $license_key );
		return $is_premium ? 'premium' : 'free';
	}

	/**
	 * Check if premium features are available
	 *
	 * @return bool True if premium tier
	 */
	public static function is_premium() {
		return self::get_license_tier() === 'premium';
	}

	/**
	 * Validate all settings together
	 *
	 * @param array $settings Settings array to validate
	 * @return array Validation result with 'valid' bool, 'errors' array, and 'validated_settings'
	 */
	public static function validate_all_settings( $settings ) {
		$errors             = array();
		$validated_settings = array();

		// Validate enable monitoring
		if ( isset( $settings['enable_monitoring'] ) ) {
			$validated_settings['enable_monitoring'] = intval( $settings['enable_monitoring'] );
		}

		// Validate health check interval
		if ( isset( $settings['health_check_interval'] ) ) {
			$interval_validation = self::validate_health_check_interval( $settings['health_check_interval'] );
			if ( $interval_validation['valid'] ) {
				$validated_settings['health_check_interval'] = $interval_validation['value'];
			} else {
				$errors[] = 'health_check_interval: ' . $interval_validation['message'];
			}
		}

		// Validate alert threshold
		if ( isset( $settings['alert_threshold'] ) ) {
			$threshold_validation = self::validate_alert_threshold( $settings['alert_threshold'] );
			if ( $threshold_validation['valid'] ) {
				$validated_settings['alert_threshold'] = $threshold_validation['value'];
			} else {
				$errors[] = 'alert_threshold: ' . $threshold_validation['message'];
			}
		}

		// Validate retry enabled
		if ( isset( $settings['retry_enabled'] ) ) {
			$validated_settings['retry_enabled'] = intval( $settings['retry_enabled'] );
		}

		// Validate max retry attempts
		if ( isset( $settings['max_retry_attempts'] ) ) {
			$retry_config     = array( 'max_retry_attempts' => $settings['max_retry_attempts'] );
			$retry_validation = self::validate_retry_configuration( $retry_config );
			if ( $retry_validation['valid'] ) {
				$validated_settings['max_retry_attempts'] = $retry_config['max_retry_attempts'];
			} else {
				$errors[] = 'max_retry_attempts: ' . $retry_validation['message'];
			}
		}

		// License key - sanitize only, validation happens in validate_license_on_save
		if ( isset( $settings['license_key'] ) ) {
			$validated_settings['license_key'] = sanitize_text_field( $settings['license_key'] );
		}

		return array(
			'valid'              => empty( $errors ),
			'errors'             => $errors,
			'validated_settings' => $validated_settings,
		);
	}

	/**
	 * Validate license when settings are saved
	 *
	 * @param array $old_value Old settings value
	 * @param array $new_value New settings value
	 */
	public function validate_license_on_save( $old_value, $new_value ) {
		// Check if license key has changed
		$old_key = isset( $old_value['license_key'] ) ? $old_value['license_key'] : '';
		$new_key = isset( $new_value['license_key'] ) ? $new_value['license_key'] : '';

		if ( $old_key !== $new_key && ! empty( $new_key ) ) {
			// Validate new license key
			$result = $this->license->save_and_validate_license( $new_key );

			if ( $result['valid'] ) {
				add_settings_error(
					'wc_payment_monitor_options',
					'license_valid',
					__( 'License key validated successfully!', 'wc-payment-monitor' ),
					'success'
				);
			} else {
				// Build error message without debug info
				$error_msg = sprintf(
					/* translators: %s: error message */
					__( 'License validation failed: %s', 'wc-payment-monitor' ),
					$result['message']
				);
				
				add_settings_error(
					'wc_payment_monitor_options',
					'license_invalid',
					$error_msg,
					'error'
				);
			}
		}
	}
}
