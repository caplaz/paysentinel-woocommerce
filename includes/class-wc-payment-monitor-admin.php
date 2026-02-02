<?php
/**
 * Admin pages and menu registration
 */

// Prevent direct access
if (!defined('ABSPATH')) {
	exit;
}

class WC_Payment_Monitor_Admin
{

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
	public function __construct()
	{
		$this->database = new WC_Payment_Monitor_Database();
		$this->security = new WC_Payment_Monitor_Security();
		$this->license = new WC_Payment_Monitor_License();
		$this->init_hooks();
	}

	/**
	 * Initialize WordPress hooks
	 */
	private function init_hooks()
	{
		add_action('admin_menu', array($this, 'register_menu_pages'));
		add_action('admin_init', array($this, 'register_settings'));
		add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
		add_action('update_option_wc_payment_monitor_options', array($this, 'validate_license_on_save'), 10, 2);

		// Admin actions
		add_action('admin_post_wc_payment_monitor_retry', array($this, 'handle_manual_retry'));
		add_action('admin_post_wc_payment_monitor_recovery', array($this, 'handle_recovery_email'));
	}

	/**
	 * Enqueue admin scripts and styles
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
			array(),
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
			array('wp-api-fetch', 'wp-element', 'chartjs'),
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
				'apiUrl' => rest_url('wc-payment-monitor/v1/'),
				'nonce' => wp_create_nonce('wp_rest'),
				'currentUser' => get_current_user_id(),
				'restNonce' => sanitize_text_field(wp_create_nonce('wp_rest')),
			)
		);
	}

	/**
	 * Register admin menu and pages
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

		// Add diagnostic tools submenu
		add_submenu_page(
			'wc-payment-monitor',
			__('Diagnostic Tools', 'wc-payment-monitor'),
			__('Diagnostic Tools', 'wc-payment-monitor'),
			'manage_woocommerce',
			'wc-payment-monitor-diagnostics',
			array($this, 'render_diagnostics_page')
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
	public function register_settings()
	{
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

		// Add settings fields - License Key first (most important)
		add_settings_field(
			'license_key',
			__('License Key', 'wc-payment-monitor'),
			array($this, 'render_field_license_key'),
			'wc_payment_monitor_settings',
			'wc_payment_monitor_main'
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

		// Alert notification settings
		add_settings_field(
			'alert_email',
			__('Alert Email Address', 'wc-payment-monitor'),
			array($this, 'render_field_alert_email'),
			'wc_payment_monitor_settings',
			'wc_payment_monitor_main'
		);

		add_settings_field(
			'alert_phone_number',
			__('Alert Phone Number (SMS)', 'wc-payment-monitor'),
			array($this, 'render_field_alert_phone_number'),
			'wc_payment_monitor_settings',
			'wc_payment_monitor_main'
		);

		add_settings_field(
			'alert_slack_workspace',
			__('Slack Workspace ID', 'wc-payment-monitor'),
			array($this, 'render_field_alert_slack_workspace'),
			'wc_payment_monitor_settings',
			'wc_payment_monitor_main'
		);

		// Per-gateway alert configuration (Pro+ feature)
		add_settings_field(
			'gateway_alert_config',
			__('Per-Gateway Alert Configuration', 'wc-payment-monitor'),
			array($this, 'render_field_gateway_alert_config'),
			'wc_payment_monitor_settings',
			'wc_payment_monitor_main'
		);

		// Test mode settings
		add_settings_field(
			'enable_test_mode',
			__('Enable Test Mode', 'wc-payment-monitor'),
			array($this, 'render_field_enable_test_mode'),
			'wc_payment_monitor_settings',
			'wc_payment_monitor_main'
		);

		add_settings_field(
			'test_failure_rate',
			__('Test Failure Rate (%)', 'wc-payment-monitor'),
			array($this, 'render_field_test_failure_rate'),
			'wc_payment_monitor_settings',
			'wc_payment_monitor_main'
		);
	}

	/**
	 * Render settings section
	 */
	public function render_settings_section()
	{
		echo '<p>' . esc_html__('Configure Payment Monitor settings below.', 'wc-payment-monitor') . '</p>';
	}

	/**
	 * Render enable monitoring field
	 */
	public function render_field_enable_monitoring()
	{
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
	public function render_field_health_check_interval()
	{
		$options = get_option('wc_payment_monitor_options', array());
		$interval = isset($options['health_check_interval']) ? intval($options['health_check_interval']) : 5;
		?>
		<input type="number" name="wc_payment_monitor_options[health_check_interval]"
			value="<?php echo esc_attr($interval); ?>" min="1" max="1440" />
		<p class="description">
			<?php esc_html_e('How often to recalculate gateway health (in minutes).', 'wc-payment-monitor'); ?></p>
		<?php
	}

	/**
	 * Render alert threshold field
	 */
	public function render_field_alert_threshold()
	{
		$options = get_option('wc_payment_monitor_options', array());
		$threshold = isset($options['alert_threshold']) ? floatval($options['alert_threshold']) : 85;
		?>
		<style>
			.wc-payment-monitor-threshold-ui {
				max-width: 450px;
				background: #fff;
				border: 1px solid #ccd0d4;
				padding: 20px;
				border-radius: 4px;
				box-shadow: 0 1px 1px rgba(0,0,0,.04);
				margin-bottom: 20px;
			}
			.threshold-slider-container {
				position: relative;
				padding: 20px 0 10px;
			}
			.threshold-bar-background {
				height: 12px;
				width: 100%;
				border-radius: 6px;
				background: linear-gradient(to right, 
					#d63638 0%, #d63638 50%, 
					#dba617 50%, #dba617 80%, 
					#ffba00 80%, #ffba00 90%, 
					#46b450 90%, #46b450 100%);
			}
			#alert-threshold-slider {
				-webkit-appearance: none;
				width: 100%;
				background: transparent;
				position: relative;
				margin-top: -16px;
				z-index: 2;
				cursor: pointer;
				display: block;
			}
			#alert-threshold-slider:focus {
				outline: none;
			}
			#alert-threshold-slider::-webkit-slider-thumb {
				-webkit-appearance: none;
				border: 2px solid #fff;
				height: 20px;
				width: 20px;
				border-radius: 50%;
				background: #2271b1;
				cursor: pointer;
				margin-top: -2px;
				box-shadow: 0 1px 3px rgba(0,0,0,0.3);
			}
			#alert-threshold-slider::-moz-range-thumb {
				border: 2px solid #fff;
				height: 18px;
				width: 18px;
				border-radius: 50%;
				background: #2271b1;
				cursor: pointer;
				box-shadow: 0 1px 3px rgba(0,0,0,0.3);
			}
		</style>

		<div class="wc-payment-monitor-threshold-ui">
			<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
				<label for="alert-threshold-slider" style="margin: 0; font-weight: 600; font-size: 14px;"><?php esc_html_e('Alert Sensitivity', 'wc-payment-monitor'); ?></label>
				<span style="background: #2271b1; color: #fff; padding: 2px 10px; border-radius: 12px; font-weight: 600; font-size: 14px; min-width: 40px; text-align: center;">
					<span id="threshold-val"><?php echo esc_attr($threshold); ?></span>%
				</span>
			</div>

			<div class="threshold-slider-container">
				<div class="threshold-bar-background"></div>
				<input type="range" 
					name="wc_payment_monitor_options[alert_threshold]" 
					id="alert-threshold-slider"
					value="<?php echo esc_attr($threshold); ?>" 
					min="50" max="100" step="1" />
			</div>

			<div style="display: flex; justify-content: space-between; font-size: 11px; color: #8c8f94; margin-bottom: 25px; padding: 0 2px;">
				<span>50%</span>
				<span>75%</span>
				<span>90%</span>
				<span>95%</span>
				<span>100%</span>
			</div>

			<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; font-size: 12px; border-top: 1px solid #f0f0f1; padding-top: 15px;">
				<div style="display: flex; align-items: center;">
					<span style="width: 12px; height: 12px; background: #d63638; border-radius: 2px; margin-right: 8px; display: inline-block;"></span>
					<span><?php esc_html_e('High Severity (< 75%)', 'wc-payment-monitor'); ?></span>
				</div>
				<div style="display: flex; align-items: center;">
					<span style="width: 12px; height: 12px; background: #dba617; border-radius: 2px; margin-right: 8px; display: inline-block;"></span>
					<span><?php esc_html_e('Warning (75-89%)', 'wc-payment-monitor'); ?></span>
				</div>
				<div style="display: flex; align-items: center;">
					<span style="width: 12px; height: 12px; background: #ffba00; border-radius: 2px; margin-right: 8px; display: inline-block;"></span>
					<span><?php esc_html_e('Info (90-94%)', 'wc-payment-monitor'); ?></span>
				</div>
				<div style="display: flex; align-items: center;">
					<span style="width: 12px; height: 12px; background: #46b450; border-radius: 2px; margin-right: 8px; display: inline-block;"></span>
					<span><?php esc_html_e('Healthy (≥ 95%)', 'wc-payment-monitor'); ?></span>
				</div>
			</div>
		</div>

		<script>
			document.addEventListener('DOMContentLoaded', function() {
				const slider = document.getElementById('alert-threshold-slider');
				const display = document.getElementById('threshold-val');
				if (slider && display) {
					slider.addEventListener('input', function() {
						display.textContent = this.value;
					});
				}
			});
		</script>

		<p class="description">
			<?php esc_html_e('Adjust the slider to set your notification threshold. You will only receive alerts if the gateway success rate falls below this percentage.', 'wc-payment-monitor'); ?>
		</p>
		<?php
	}

	/**
	 * Render retry enabled field
	 */
	public function render_field_retry_enabled()
	{
		$options = get_option('wc_payment_monitor_options', array());
		$enabled = isset($options['retry_enabled']) ? intval($options['retry_enabled']) : 1;
		?>
		<input type="checkbox" name="wc_payment_monitor_options[retry_enabled]" value="1" <?php checked($enabled, 1); ?> />
		<label><?php esc_html_e('Automatically retry failed payments', 'wc-payment-monitor'); ?></label>
		<p class="description">
			<?php esc_html_e('When enabled, failed payments are automatically retried using stored payment methods. Retries are scheduled at 1 hour, 6 hours, and 24 hours. Only retriable failures are attempted (excludes fraud, expired cards, etc.).', 'wc-payment-monitor'); ?>
		</p>
		<?php
	}

	/**
	 * Render max retry attempts field
	 */
	public function render_field_max_retry_attempts()
	{
		$options = get_option('wc_payment_monitor_options', array());
		$attempts = isset($options['max_retry_attempts']) ? intval($options['max_retry_attempts']) : 3;
		?>
		<input type="number" name="wc_payment_monitor_options[max_retry_attempts]" value="<?php echo esc_attr($attempts); ?>"
			min="1" max="10" />
		<p class="description"><?php esc_html_e('Maximum number of retry attempts per transaction.', 'wc-payment-monitor'); ?>
		</p>
		<?php
	}

	/**
	 * Render license key field
	 */
	public function render_field_license_key()
	{
		$options = get_option('wc_payment_monitor_options', array());
		$license_key = isset($options['license_key']) ? sanitize_text_field($options['license_key']) : '';
		$license_status = $this->license->get_license_status();
		$license_data = $this->license->get_license_data();
		$tier = $this->license->get_license_tier();
		
		$tier_colors = array(
			'free'    => '#646970',
			'starter' => '#0073aa',
			'pro'     => '#d63638',
			'agency'  => '#9b51e0',
		);
		$badge_color = isset($tier_colors[$tier]) ? $tier_colors[$tier] : '#0073aa';
		?>
		<style>
			.license-field-wrapper {
				max-width: 500px;
				background: #fff;
				border: 1px solid #ccd0d4;
				border-radius: 4px;
				overflow: hidden;
				box-shadow: 0 1px 1px rgba(0,0,0,.04);
			}
			.license-header {
				padding: 15px 20px;
				background: #f8f9fa;
				border-bottom: 1px solid #ccd0d4;
				display: flex;
				justify-content: space-between;
				align-items: center;
			}
			.license-status-badge {
				padding: 4px 12px;
				border-radius: 12px;
				font-size: 11px;
				font-weight: 700;
				text-transform: uppercase;
				letter-spacing: 0.5px;
				color: #fff;
			}
			.license-body {
				padding: 20px;
			}
			.license-input-group {
				display: flex;
				gap: 10px;
				margin-bottom: 15px;
			}
			.license-footer {
				padding: 12px 20px;
				background: #fdfdfd;
				border-top: 1px solid #f0f0f1;
				display: flex;
				justify-content: space-between;
				align-items: center;
				font-size: 12px;
			}
			.upgrade-button {
				background-color: #46b450;
				border-color: #46b450;
				color: white;
				text-decoration: none;
				padding: 6px 14px;
				border-radius: 4px;
				font-weight: 600;
				display: inline-flex;
				align-items: center;
				transition: all 0.2s;
			}
			.upgrade-button:hover {
				background-color: #389140;
				color: white;
			}
			.upgrade-button .dashicons {
				margin-right: 5px;
				font-size: 18px;
				width: 18px;
				height: 18px;
			}
		</style>

		<div class="license-field-wrapper">
			<div class="license-header">
				<div style="display: flex; align-items: center; gap: 10px;">
					<span class="dashicons dashicons-admin-network" style="color: <?php echo esc_attr($badge_color); ?>;"></span>
					<span style="font-weight: 600; color: #23282d;"><?php esc_html_e('Subscription Plan', 'wc-payment-monitor'); ?></span>
				</div>
				<span class="license-status-badge" style="background: <?php echo esc_attr($badge_color); ?>;">
					<?php echo esc_html(ucwords($tier)); ?>
				</span>
			</div>

			<div class="license-body">
				<div class="license-input-group">
					<input type="password" id="wc_payment_monitor_license_key" name="wc_payment_monitor_options[license_key]"
						value="<?php echo esc_attr($license_key); ?>" style="flex-grow: 1; padding: 8px;" placeholder="<?php esc_html_e('PA-XXXX-XXXX-XXXX', 'wc-payment-monitor'); ?>" />
					<button type="button" class="button"
						onclick="var field = document.getElementById('wc_payment_monitor_license_key'); var type = field.type === 'password' ? 'text' : 'password'; field.type = type; this.textContent = type === 'password' ? 'Show' : 'Hide';"
						style="min-width: 60px;">
						<?php esc_html_e('Show', 'wc-payment-monitor'); ?>
					</button>
				</div>

				<?php if ('valid' === $license_status): ?>
					<div style="display: flex; justify-content: space-between; align-items: flex-end;">
						<div>
							<p style="margin: 0; color: #46b450; font-size: 13px; font-weight: 500;">
								<span class="dashicons dashicons-yes-alt" style="font-size: 16px; width: 16px; height: 16px; margin-right: 4px;"></span>
								<?php esc_html_e('Active protection enabled', 'wc-payment-monitor'); ?>
							</p>
							<?php if (isset($license_data['expiration_ts'])): ?>
								<p style="margin: 5px 0 0; font-size: 12px; color: #646970;">
									<?php echo esc_html(sprintf(__('Renews on: %s', 'wc-payment-monitor'), date_i18n(get_option('date_format'), strtotime($license_data['expiration_ts'])))); ?>
								</p>
							<?php endif; ?>
						</div>

						<?php if ($tier !== 'agency'): ?>
							<?php 
								$next_tier = ($tier === 'free') ? 'Starter' : (($tier === 'starter') ? 'Pro' : 'Agency');
							?>
							<a href="https://paysentinel.caplaz.com/pricing" target="_blank" class="upgrade-button">
								<span class="dashicons dashicons-star-filled"></span>
								<?php echo esc_html(sprintf(__('Upgrade to %s', 'wc-payment-monitor'), $next_tier)); ?>
							</a>
						<?php endif; ?>
					</div>
				<?php else: ?>
					<p style="margin: 0; color: #d63638; font-size: 13px;">
						<span class="dashicons dashicons-warning" style="font-size: 16px; width: 16px; height: 16px; margin-right: 4px;"></span>
						<?php esc_html_e('Enter a valid license key to unlock real-time monitoring and SMS/Slack alerts.', 'wc-payment-monitor'); ?>
					</p>
					<div style="margin-top: 15px;">
						<a href="https://paysentinel.caplaz.com/pricing" target="_blank" class="button button-primary">
							<?php esc_html_e('Get a License Key', 'wc-payment-monitor'); ?>
						</a>
					</div>
				<?php endif; ?>
			</div>

			<?php if ('valid' === $license_status && $this->license->is_site_registered()): ?>
				<div class="license-footer">
					<span style="color: #646970;">
						<span class="dashicons dashicons-admin-site" style="font-size: 14px; width: 14px; height: 14px; vertical-align: text-bottom;"></span>
						<?php echo esc_html(parse_url(get_site_url(), PHP_URL_HOST)); ?>
					</span>
					<span style="color: #46b450; font-weight: 500;"><?php esc_html_e('Verified', 'wc-payment-monitor'); ?></span>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render enable test mode field
	 */
	public function render_field_enable_test_mode()
	{
		$options = get_option('wc_payment_monitor_settings', array());
		$enabled = isset($options['enable_test_mode']) ? intval($options['enable_test_mode']) : 0;
		?>
		<input type="checkbox" name="wc_payment_monitor_options[enable_test_mode]" value="1" <?php checked($enabled, 1); ?> />
		<label><?php esc_html_e('Enable payment failure simulation for testing', 'wc-payment-monitor'); ?></label>
		<p class="description" style="color: #d63638;">
			<strong><?php esc_html_e('Warning:', 'wc-payment-monitor'); ?></strong>
			<?php esc_html_e('This will simulate random payment failures during checkout. Only enable on test/development sites!', 'wc-payment-monitor'); ?>
		</p>
		<?php
	}

	/**
	 * Render alert email field
	 */
	public function render_field_alert_email()
	{
		$settings = get_option('wc_payment_monitor_settings', array());
		$email = isset($settings['alert_email']) ? sanitize_email($settings['alert_email']) : get_option('admin_email');
		?>
		<input type="email" name="wc_payment_monitor_options[alert_email]"
			value="<?php echo esc_attr($email); ?>" class="regular-text" />
		<p class="description">
			<?php esc_html_e('Email address to receive payment failure alerts (Free tier - local delivery).', 'wc-payment-monitor'); ?>
		</p>
		<?php
	}

	/**
	 * Render alert phone number field
	 */
	public function render_field_alert_phone_number()
	{
		$settings = get_option('wc_payment_monitor_settings', array());
		$phone = isset($settings['alert_phone_number']) ? sanitize_text_field($settings['alert_phone_number']) : '';
		$tier = $this->license->get_license_tier();
		$is_locked = ! in_array($tier, array('starter', 'pro', 'agency'), true);
		?>
		<div style="position: relative;">
			<input type="tel" name="wc_payment_monitor_options[alert_phone_number]"
				value="<?php echo esc_attr($phone); ?>" class="regular-text"
				placeholder="+1234567890"
				<?php echo $is_locked ? 'disabled' : ''; ?> />
			<?php if ($is_locked): ?>
				<span class="dashicons dashicons-lock" style="color: #d63638; position: absolute; right: -30px; top: 5px;" title="<?php esc_attr_e('Starter plan or higher required', 'wc-payment-monitor'); ?>"></span>
			<?php else: ?>
				<span class="dashicons dashicons-yes-alt" style="color: #46b450; position: absolute; right: -30px; top: 5px;" title="<?php esc_attr_e('Feature available in your plan', 'wc-payment-monitor'); ?>"></span>
			<?php endif; ?>
		</div>
		<p class="description">
			<?php
			if ($is_locked) {
				printf(
					__('SMS alerts delivered via PaySentinel servers. <strong>Requires Starter plan or higher.</strong> <a href="%s" target="_blank">Upgrade Now</a>', 'wc-payment-monitor'),
					'https://paysentinel.caplaz.com/plans'
				);
			} else {
				$quota = $this->license->get_sms_quota();
				if ($quota && isset($quota['sms_remaining'], $quota['sms_limit'])) {
					printf(
						__('SMS alerts delivered via PaySentinel servers. Quota: %d/%d remaining this month.', 'wc-payment-monitor'),
						$quota['sms_remaining'],
						$quota['sms_limit']
					);
				} else {
					esc_html_e('SMS alerts delivered via PaySentinel servers. Enter your phone number with country code (e.g., +1234567890).', 'wc-payment-monitor');
				}
			}
			?>
		</p>
		<?php
	}

	/**
	 * Render alert Slack workspace field
	 */
	public function render_field_alert_slack_workspace()
	{
		$settings = get_option('wc_payment_monitor_settings', array());
		$slack = isset($settings['alert_slack_workspace']) ? sanitize_text_field($settings['alert_slack_workspace']) : '';
		$tier = $this->license->get_license_tier();
		$is_locked = ! in_array($tier, array('pro', 'agency'), true);
		?>
		<div style="position: relative;">
			<input type="text" name="wc_payment_monitor_options[alert_slack_workspace]"
				value="<?php echo esc_attr($slack); ?>" class="regular-text"
				placeholder="T01234567"
				<?php echo $is_locked ? 'disabled' : ''; ?> />
			<?php if ($is_locked): ?>
				<span class="dashicons dashicons-lock" style="color: #d63638; position: absolute; right: -30px; top: 5px;" title="<?php esc_attr_e('Pro plan or higher required', 'wc-payment-monitor'); ?>"></span>
			<?php else: ?>
				<span class="dashicons dashicons-yes-alt" style="color: #46b450; position: absolute; right: -30px; top: 5px;" title="<?php esc_attr_e('Feature available in your plan', 'wc-payment-monitor'); ?>"></span>
			<?php endif; ?>
		</div>
		<p class="description">
			<?php
			if ($is_locked) {
				printf(
					__('Slack/Discord/Teams notifications delivered via PaySentinel. <strong>Requires Pro plan or higher.</strong> <a href="%s" target="_blank">Upgrade Now</a>', 'wc-payment-monitor'),
					'https://paysentinel.caplaz.com/plans'
				);
			} else {
				esc_html_e('Slack notifications delivered via PaySentinel servers. Click "Connect Slack" below to link your workspace.', 'wc-payment-monitor');
			}
			?>
		</p>
		<?php if (!$is_locked): ?>
			<button type="button" class="button button-secondary" style="margin-top: 10px;">
				<?php esc_html_e('Connect Slack Workspace', 'wc-payment-monitor'); ?>
			</button>
		<?php endif; ?>
		<?php
	}

	/**
	 * Render per-gateway alert configuration field
	 */
	public function render_field_gateway_alert_config()
	{
		$settings = get_option('wc_payment_monitor_settings', array());
		$gateway_config = isset($settings['gateway_alert_config']) ? $settings['gateway_alert_config'] : array();
		$tier = $this->license->get_license_tier();
		$is_locked = ! in_array($tier, array('pro', 'agency'), true);
		
		// Get active WooCommerce payment gateways
		$active_gateways = array();
		if (class_exists('WC_Payment_Gateways')) {
			$payment_gateways = WC_Payment_Gateways::instance();
			$gateways = $payment_gateways->get_available_payment_gateways();
			foreach ($gateways as $gateway_id => $gateway) {
				$active_gateways[$gateway_id] = WC_Payment_Monitor::get_friendly_gateway_name( $gateway_id );
			}
		}
		
		// Add common gateway IDs if not detected
		$default_gateways = array(
			'stripe' => 'Stripe',
			'paypal' => 'PayPal',
			'square' => 'Square',
			'wc_payments' => 'WooCommerce Payments'
		);
		foreach ($default_gateways as $id => $name) {
			if (!isset($active_gateways[$id])) {
				$active_gateways[$id] = $name;
			}
		}
		?>
		<div style="position: relative;">
			<?php if ($is_locked): ?>
				<div style="background: #fff3cd; border: 1px solid #ffc107; padding: 15px; border-radius: 4px; margin-bottom: 15px;">
					<span class="dashicons dashicons-lock" style="color: #856404; float: left; margin-right: 10px; font-size: 24px;"></span>
					<p style="margin: 0; color: #856404;">
						<strong><?php esc_html_e('Pro Feature', 'wc-payment-monitor'); ?></strong><br>
						<?php 
						printf(
							__('Per-gateway alert configuration requires Pro plan or higher. <a href="%s" target="_blank" class="button button-primary" style="margin-left: 10px;">Upgrade to Pro</a>', 'wc-payment-monitor'),
							'https://paysentinel.caplaz.com/plans'
						);
						?>
					</p>
				</div>
			<?php else: ?>
				<p class="description" style="margin-bottom: 15px;">
					<?php esc_html_e('Configure custom alert thresholds and channels for each payment gateway. Leave empty to use global settings.', 'wc-payment-monitor'); ?>
				</p>
			<?php endif; ?>
			
			<table class="widefat" style="<?php echo $is_locked ? 'opacity: 0.5; pointer-events: none;' : ''; ?>">
				<thead>
					<tr>
						<th style="width: 30%;"><?php esc_html_e('Gateway', 'wc-payment-monitor'); ?></th>
						<th style="width: 15%;"><?php esc_html_e('Enabled', 'wc-payment-monitor'); ?></th>
						<th style="width: 20%;"><?php esc_html_e('Threshold (%)', 'wc-payment-monitor'); ?></th>
						<th style="width: 35%;"><?php esc_html_e('Alert Channels', 'wc-payment-monitor'); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ($active_gateways as $gateway_id => $gateway_name): ?>
						<?php
						$config = isset($gateway_config[$gateway_id]) ? $gateway_config[$gateway_id] : array();
						$enabled = isset($config['enabled']) ? (bool) $config['enabled'] : true;
						$threshold = isset($config['threshold']) ? floatval($config['threshold']) : '';
						$channels = isset($config['channels']) ? $config['channels'] : array('email');
						?>
						<tr>
							<td><strong><?php echo esc_html($gateway_name); ?></strong></td>
							<td>
								<input type="checkbox" 
									name="wc_payment_monitor_options[gateway_alert_config][<?php echo esc_attr($gateway_id); ?>][enabled]" 
									value="1" 
									<?php checked($enabled, true); ?>
									<?php echo $is_locked ? 'disabled' : ''; ?> />
							</td>
							<td>
								<input type="number" 
									name="wc_payment_monitor_options[gateway_alert_config][<?php echo esc_attr($gateway_id); ?>][threshold]" 
									value="<?php echo esc_attr($threshold); ?>" 
									placeholder="<?php echo esc_attr($settings['alert_threshold'] ?? 85); ?>"
									min="1" 
									max="100" 
									step="0.1" 
									style="width: 80px;"
									<?php echo $is_locked ? 'disabled' : ''; ?> />
							</td>
							<td>
								<label style="margin-right: 10px;">
									<input type="checkbox" 
										name="wc_payment_monitor_options[gateway_alert_config][<?php echo esc_attr($gateway_id); ?>][channels][]" 
										value="email" 
										<?php checked(in_array('email', $channels), true); ?>
										<?php echo $is_locked ? 'disabled' : ''; ?> />
									<?php esc_html_e('Email', 'wc-payment-monitor'); ?>
								</label>
								<label style="margin-right: 10px;">
									<input type="checkbox" 
										name="wc_payment_monitor_options[gateway_alert_config][<?php echo esc_attr($gateway_id); ?>][channels][]" 
										value="sms" 
										<?php checked(in_array('sms', $channels), true); ?>
										<?php echo $is_locked ? 'disabled' : ''; ?> />
									<?php esc_html_e('SMS', 'wc-payment-monitor'); ?>
								</label>
								<label>
									<input type="checkbox" 
										name="wc_payment_monitor_options[gateway_alert_config][<?php echo esc_attr($gateway_id); ?>][channels][]" 
										value="slack" 
										<?php checked(in_array('slack', $channels), true); ?>
										<?php echo $is_locked ? 'disabled' : ''; ?> />
									<?php esc_html_e('Slack', 'wc-payment-monitor'); ?>
								</label>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Render test failure rate field
	 */
	public function render_field_test_failure_rate()
	{
		$options = get_option('wc_payment_monitor_settings', array());
		$rate = isset($options['test_failure_rate']) ? intval($options['test_failure_rate']) : 10;
		?>
		<input type="number" name="wc_payment_monitor_options[test_failure_rate]" value="<?php echo esc_attr($rate); ?>"
			min="0" max="100" />
		<p class="description">
			<?php esc_html_e('Percentage of payments to simulate as failures (only when test mode is enabled).', 'wc-payment-monitor'); ?>
		</p>
		<?php
	}

	/**
	 * Render dashboard page
	 */
	public function render_dashboard_page()
	{
		if (!current_user_can('manage_woocommerce')) {
			wp_die(esc_html__('You do not have permission to access this page.', 'wc-payment-monitor'));
		}

		// Get license info for header display
		$tier = $this->license->get_license_tier();
		$quota = $this->license->get_sms_quota();
		$tier_labels = array(
			'free' => __('Free', 'wc-payment-monitor'),
			'starter' => __('Starter', 'wc-payment-monitor'),
			'pro' => __('Pro', 'wc-payment-monitor'),
			'agency' => __('Agency', 'wc-payment-monitor')
		);
		$tier_colors = array(
			'free' => '#6c757d',
			'starter' => '#0073aa',
			'pro' => '#46b450',
			'agency' => '#9b51e0'
		);
		$tier_label = isset($tier_labels[$tier]) ? $tier_labels[$tier] : ucfirst($tier);
		$tier_color = isset($tier_colors[$tier]) ? $tier_colors[$tier] : '#0073aa';

		?>
		<div class="wrap">
			<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
				<h1 style="margin: 0;"><?php esc_html_e('Payment Monitor Dashboard', 'wc-payment-monitor'); ?></h1>
				<div style="display: flex; gap: 15px; align-items: center;">
					<!-- License Tier Badge -->
					<div style="background: <?php echo esc_attr($tier_color); ?>; color: white; padding: 8px 16px; border-radius: 4px; font-weight: bold; font-size: 14px;">
						<span class="dashicons dashicons-awards" style="font-size: 16px; width: 16px; height: 16px; vertical-align: middle; margin-right: 5px;"></span>
						<?php echo esc_html($tier_label); ?> <?php esc_html_e('Plan', 'wc-payment-monitor'); ?>
					</div>
					
					<!-- SMS Quota Display -->
					<?php if ($quota && isset($quota['sms_remaining'], $quota['sms_limit'])): ?>
						<?php 
						$usage_percent = ($quota['sms_limit'] > 0) ? (($quota['sms_limit'] - $quota['sms_remaining']) / $quota['sms_limit']) * 100 : 0;
						$quota_color = '#46b450'; // green
						if ($usage_percent >= 80) {
							$quota_color = '#dc3232'; // red
						} elseif ($usage_percent >= 60) {
							$quota_color = '#f0b849'; // yellow
						}
						?>
						<div style="background: white; border: 1px solid #ddd; padding: 8px 16px; border-radius: 4px;">
							<div style="display: flex; align-items: center; gap: 10px;">
								<span class="dashicons dashicons-email" style="color: <?php echo esc_attr($quota_color); ?>; font-size: 20px; width: 20px; height: 20px;"></span>
								<div>
									<div style="font-size: 11px; color: #666; line-height: 1.2;">
										<?php esc_html_e('SMS Quota', 'wc-payment-monitor'); ?>
									</div>
									<div style="font-weight: bold; font-size: 14px; color: <?php echo esc_attr($quota_color); ?>;">
										<?php echo esc_html($quota['sms_remaining'] . ' / ' . $quota['sms_limit']); ?>
									</div>
								</div>
							</div>
							<?php if (isset($quota['sms_reset_date'])): ?>
								<div style="font-size: 10px; color: #999; margin-top: 2px;">
									<?php 
									printf(
										esc_html__('Resets: %s', 'wc-payment-monitor'),
										esc_html(date_i18n(get_option('date_format'), strtotime($quota['sms_reset_date'])))
									);
									?>
								</div>
							<?php endif; ?>
						</div>
					<?php endif; ?>
					
					<!-- Quota Exceeded Warning -->
					<?php
					$quota_exceeded = get_option('wc_payment_monitor_quota_exceeded', false);
					if ($quota_exceeded):
					?>
						<div style="background: #dc3232; color: white; padding: 8px 16px; border-radius: 4px; font-size: 13px;">
							<span class="dashicons dashicons-warning" style="font-size: 16px; width: 16px; height: 16px; vertical-align: middle;"></span>
							<?php esc_html_e('SMS Quota Exceeded', 'wc-payment-monitor'); ?>
							<a href="https://paysentinel.caplaz.com/upgrade" target="_blank" style="color: white; text-decoration: underline; margin-left: 10px;">
								<?php esc_html_e('Upgrade', 'wc-payment-monitor'); ?>
							</a>
						</div>
					<?php endif; ?>
					
					<!-- Upgrade Button for Free Tier -->
					<?php if ($tier === 'free'): ?>
						<a href="https://paysentinel.caplaz.com/plans" target="_blank" class="button button-primary" style="height: auto;">
							<span class="dashicons dashicons-star-filled" style="font-size: 16px; width: 16px; height: 16px; vertical-align: middle;"></span>
							<?php esc_html_e('Upgrade to Pro', 'wc-payment-monitor'); ?>
						</a>
					<?php endif; ?>
				</div>
			</div>
			<div id="wc-payment-monitor-root"></div>
		</div>
		<?php
	}

	/**
	 * Render gateway health page
	 */
	public function render_health_page()
	{
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
	public function render_transactions_page()
	{
		if (!current_user_can('manage_woocommerce')) {
			wp_die(esc_html__('You do not have permission to access this page.', 'wc-payment-monitor'));
		}

		global $wpdb;
		$table_name = $this->database->get_transactions_table();
		$transactions = $wpdb->get_results("SELECT * FROM {$table_name} ORDER BY id DESC LIMIT 50");

		add_thickbox();
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e('Transaction Log', 'wc-payment-monitor'); ?></h1>

			<?php if (isset($_GET['message'])): ?>
				<div
					class="notice notice-<?php echo esc_attr(isset($_GET['type']) ? sanitize_text_field($_GET['type']) : 'info'); ?> is-dismissible">
					<p><?php echo esc_html(urldecode($_GET['message'])); ?></p>
				</div>
			<?php endif; ?>

			<p><?php esc_html_e('View all monitored payment transactions.', 'wc-payment-monitor'); ?></p>

			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th><?php esc_html_e('Date', 'wc-payment-monitor'); ?></th>
						<th><?php esc_html_e('Status', 'wc-payment-monitor'); ?></th>
						<th><?php esc_html_e('Order', 'wc-payment-monitor'); ?></th>
						<th><?php esc_html_e('Amount', 'wc-payment-monitor'); ?></th>
						<th><?php esc_html_e('Gateway', 'wc-payment-monitor'); ?></th>
						<th><?php esc_html_e('Reason', 'wc-payment-monitor'); ?></th>
						<th><?php esc_html_e('Actions', 'wc-payment-monitor'); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if (empty($transactions)): ?>
						<tr>
							<td colspan="7"><?php esc_html_e('No transactions found.', 'wc-payment-monitor'); ?></td>
						</tr>
					<?php else: ?>
						<?php foreach ($transactions as $t): ?>
							<tr>
								<td><?php echo esc_html($t->created_at); ?></td>
								<td>
									<span class="wc-monitor-status status-<?php echo esc_attr($t->status); ?>" style="
										padding: 3px 8px;
										border-radius: 4px;
										font-weight: 500;
										background: <?php echo $t->status === 'success' ? '#d4edda' : ($t->status === 'failed' ? '#f8d7da' : '#fff3cd'); ?>;
										color: <?php echo $t->status === 'success' ? '#155724' : ($t->status === 'failed' ? '#721c24' : '#856404'); ?>;
									">
										<?php echo esc_html(ucfirst($t->status)); ?>
									</span>
								</td>
								<td>
									<a href="<?php echo esc_url(admin_url('post.php?post=' . $t->order_id . '&action=edit')); ?>">
										#<?php echo esc_html($t->order_id); ?>
									</a>
								</td>
								<td><?php echo esc_html($t->amount . ' ' . $t->currency); ?></td>
								<td><?php echo esc_html($t->gateway_id); ?></td>
								<td><?php echo esc_html($t->failure_reason ? $t->failure_reason : '-'); ?></td>
								<td>
									<a href="#TB_inline?width=600&height=440&inlineId=transaction-details-<?php echo $t->id; ?>"
										class="thickbox button button-small tip"
										title="<?php echo esc_attr(sprintf(__('Transaction Details #%d', 'wc-payment-monitor'), $t->id)); ?>"
										style="display: inline-flex; align-items: center; justify-content: center; padding: 0 8px;">
										<span class="dashicons dashicons-visibility"
											style="font-size: 18px; width: 18px; height: 18px;"></span>
									</a>

									<div id="transaction-details-<?php echo $t->id; ?>" style="display:none;">
										<div class="wc-monitor-details-modal" style="padding: 10px 20px;">
											<style>
												.wc-monitor-details-modal .form-table th,
												.wc-monitor-details-modal .form-table td {
													padding: 10px 0 !important;
												}
											</style>
											<table class="form-table" style="margin-top: 0; margin-bottom: 0;">
												<tr>
													<th style="width: 130px; vertical-align: top; font-weight: 600;">
														<?php esc_html_e('Transaction ID', 'wc-payment-monitor'); ?></th>
													<td><?php echo esc_html($t->transaction_id ? $t->transaction_id : '-'); ?></td>
												</tr>
												<tr>
													<th style="width: 130px; vertical-align: top; font-weight: 600;">
														<?php esc_html_e('Order ID', 'wc-payment-monitor'); ?></th>
													<td>#<?php echo esc_html($t->order_id); ?></td>
												</tr>
												<tr>
													<th style="width: 130px; vertical-align: top; font-weight: 600;">
														<?php esc_html_e('Gateway', 'wc-payment-monitor'); ?></th>
													<td><?php echo esc_html($t->gateway_id); ?></td>
												</tr>
												<tr>
													<th style="width: 130px; vertical-align: top; font-weight: 600;">
														<?php esc_html_e('Status', 'wc-payment-monitor'); ?></th>
													<td><?php echo esc_html(ucfirst($t->status)); ?></td>
												</tr>
												<tr>
													<th style="width: 130px; vertical-align: top; font-weight: 600;">
														<?php esc_html_e('Failure Reason', 'wc-payment-monitor'); ?></th>
													<td><?php echo esc_html($t->failure_reason ? $t->failure_reason : '-'); ?></td>
												</tr>
												<tr>
													<th style="width: 130px; vertical-align: top; font-weight: 600;">
														<?php esc_html_e('Failure Code', 'wc-payment-monitor'); ?></th>
													<td><?php echo esc_html($t->failure_code ? $t->failure_code : '-'); ?></td>
												</tr>
												<tr>
													<th style="width: 130px; vertical-align: top; font-weight: 600;">
														<?php esc_html_e('Retry Count', 'wc-payment-monitor'); ?></th>
													<td><?php echo esc_html($t->retry_count); ?></td>
												</tr>
												<tr>
													<th style="width: 130px; vertical-align: top; font-weight: 600;">
														<?php esc_html_e('Customer Email', 'wc-payment-monitor'); ?></th>
													<td><?php echo esc_html($t->customer_email); ?></td>
												</tr>
												<tr>
													<th style="width: 130px; vertical-align: top; font-weight: 600;">
														<?php esc_html_e('Customer IP', 'wc-payment-monitor'); ?></th>
													<td><?php echo esc_html($t->customer_ip); ?></td>
												</tr>
												<tr>
													<th style="width: 130px; vertical-align: top; font-weight: 600;">
														<?php esc_html_e('Created At', 'wc-payment-monitor'); ?></th>
													<td><?php echo esc_html($t->created_at); ?></td>
												</tr>
											</table>
										</div>
									</div>

									<?php if (in_array($t->status, array('failed', 'retry'))): ?>
										<a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=wc_payment_monitor_retry&order_id=' . $t->order_id), 'wc_payment_monitor_retry_' . $t->order_id)); ?>"
											class="button button-small tip"
											title="<?php esc_attr_e('Retry Payment Now', 'wc-payment-monitor'); ?>"
											style="margin-left: 5px; display: inline-flex; align-items: center; justify-content: center; padding: 0 8px;">
											<span class="dashicons dashicons-update"
												style="font-size: 18px; width: 18px; height: 18px;"></span>
										</a>
										<a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=wc_payment_monitor_recovery&order_id=' . $t->order_id), 'wc_payment_monitor_recovery_' . $t->order_id)); ?>"
											class="button button-small tip"
											style="margin-left: 5px; display: inline-flex; align-items: center; justify-content: center; padding: 0 8px;"
											title="<?php esc_attr_e('Send Recovery Email', 'wc-payment-monitor'); ?>">
											<span class="dashicons dashicons-email-alt"
												style="font-size: 18px; width: 18px; height: 18px;"></span>
										</a>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Render alerts page
	 */
	public function render_alerts_page()
	{
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
	public function render_settings_page()
	{
		if (!current_user_can('manage_woocommerce')) {
			wp_die(esc_html__('You do not have permission to access this page.', 'wc-payment-monitor'));
		}

		?>
		<div class="wrap">
			<h1><?php esc_html_e('Payment Monitor Settings', 'wc-payment-monitor'); ?></h1>
			<?php settings_errors('wc_payment_monitor_options'); ?>
			<form method="post" action="options.php">
				<?php
				settings_fields('wc_payment_monitor_settings');
				do_settings_sections('wc_payment_monitor_settings');
				submit_button();
				?>
			</form>
			
			<div style="margin-top: 30px; padding: 20px; background: #f8f9fa; border: 1px solid #e1e1e1; border-radius: 4px;">
				<h3 style="margin-top: 0; color: #23282d;"><?php esc_html_e('How Payment Monitor Works', 'wc-payment-monitor'); ?></h3>
				<p style="margin-bottom: 10px;">
					<strong><?php esc_html_e('Monitoring:', 'wc-payment-monitor'); ?></strong> 
					<?php esc_html_e('Tracks payment gateway success rates and triggers alerts when performance drops below thresholds.', 'wc-payment-monitor'); ?>
				</p>
				<p style="margin-bottom: 10px;">
					<strong><?php esc_html_e('Alerts:', 'wc-payment-monitor'); ?></strong> 
					<?php esc_html_e('Sends notifications via email, SMS, or Slack when payment issues are detected.', 'wc-payment-monitor'); ?>
				</p>
				<p style="margin-bottom: 10px;">
					<strong><?php esc_html_e('Auto-Retry:', 'wc-payment-monitor'); ?></strong> 
					<?php esc_html_e('Automatically retries failed payments using stored payment methods (excludes fraud/expired cards).', 'wc-payment-monitor'); ?>
				</p>
				<p style="margin-bottom: 0;">
					<strong><?php esc_html_e('License:', 'wc-payment-monitor'); ?></strong> 
					<?php esc_html_e('Required for premium features like SMS alerts, Slack integration, and advanced analytics.', 'wc-payment-monitor'); ?>
				</p>
			</div>
		</div>
		<?php
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
	 * Get current settings
	 *
	 * @return array Current settings
	 */
	public static function get_settings()
	{
		$defaults = array(
			'enable_monitoring' => 1,
			'health_check_interval' => 5,
			'alert_threshold' => 85,
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
	 * @param mixed  $default Default value
	 * @return mixed Setting value
	 */
	public static function get_setting($setting, $default = null)
	{
		$settings = self::get_settings();
		return isset($settings[$setting]) ? $settings[$setting] : $default;
	}

	/**
	 * Update settings
	 *
	 * @param array $settings Settings to update
	 * @return bool True on success
	 */
	public static function update_settings($settings)
	{
		$current = self::get_settings();
		$updated = wp_parse_args($settings, $current);
		return update_option('wc_payment_monitor_options', $updated);
	}

	/**
	 * Validate health check interval setting
	 *
	 * @param int $interval Health check interval in minutes
	 * @return array Validation result with 'valid' bool and 'message' string
	 */
	public static function validate_health_check_interval($interval)
	{
		$interval = intval($interval);

		if ($interval < 1) {
			return array(
				'valid' => false,
				'message' => __('Health check interval must be at least 1 minute.', 'wc-payment-monitor'),
			);
		}

		if ($interval > 1440) {
			return array(
				'valid' => false,
				'message' => __('Health check interval cannot exceed 1440 minutes (24 hours).', 'wc-payment-monitor'),
			);
		}

		return array(
			'valid' => true,
			'message' => '',
			'value' => $interval,
		);
	}

	/**
	 * Validate alert threshold setting
	 *
	 * @param float $threshold Alert threshold percentage
	 * @return array Validation result with 'valid' bool and 'message' string
	 */
	public static function validate_alert_threshold($threshold)
	{
		$threshold = floatval($threshold);

		if ($threshold < 0.1) {
			return array(
				'valid' => false,
				'message' => __('Alert threshold must be at least 0.1%.', 'wc-payment-monitor'),
			);
		}

		if ($threshold > 100) {
			return array(
				'valid' => false,
				'message' => __('Alert threshold cannot exceed 100%.', 'wc-payment-monitor'),
			);
		}

		return array(
			'valid' => true,
			'message' => '',
			'value' => $threshold,
		);
	}

	/**
	 * Validate retry configuration
	 *
	 * @param array $retry_config Retry configuration array
	 * @return array Validation result with 'valid' bool and 'message' string
	 */
	public static function validate_retry_configuration($retry_config)
	{
		if (!is_array($retry_config)) {
			return array(
				'valid' => false,
				'message' => __('Retry configuration must be an array.', 'wc-payment-monitor'),
			);
		}

		// Check if array is empty or missing required key
		if (empty($retry_config) || !isset($retry_config['max_retry_attempts'])) {
			return array(
				'valid' => false,
				'message' => __('Retry configuration must contain max_retry_attempts.', 'wc-payment-monitor'),
				'errors' => array(__('Retry configuration must contain max_retry_attempts.', 'wc-payment-monitor')),
			);
		}

		$errors = array();
		$max_attempts = intval($retry_config['max_retry_attempts']);

		if ($max_attempts < 1) {
			$errors[] = __('Max retry attempts must be at least 1.', 'wc-payment-monitor');
		}

		if ($max_attempts > 10) {
			$errors[] = __('Max retry attempts cannot exceed 10.', 'wc-payment-monitor');
		}

		if (!empty($errors)) {
			return array(
				'valid' => false,
				'message' => implode(' ', $errors),
				'errors' => $errors,
			);
		}

		return array(
			'valid' => true,
			'message' => '',
			'value' => $retry_config,
		);
	}

	/**
	 * Validate license key
	 *
	 * @param string $license_key License key to validate
	 * @return array Validation result with 'valid' bool and 'message' string
	 */
	public static function validate_license_key($license_key)
	{
		$license_key = sanitize_text_field($license_key);

		// Empty license key is valid (may be free tier)
		if (empty($license_key)) {
			return array(
				'valid' => true,
				'message' => '',
				'tier' => 'free',
			);
		}

		// License key format validation (alphanumeric and hyphens, 20-50 chars)
		if (!preg_match('/^[A-Za-z0-9\-]{20,50}$/', $license_key)) {
			return array(
				'valid' => false,
				'message' => __('License key format is invalid. Should be 20-50 alphanumeric characters with optional hyphens.', 'wc-payment-monitor'),
			);
		}

		// Check if license is active (simulate license validation)
		// In production, this would call a remote license server
		$is_premium = apply_filters('wc_payment_monitor_validate_license', false, $license_key);

		if ($is_premium) {
			return array(
				'valid' => true,
				'message' => '',
				'tier' => 'premium',
				'value' => $license_key,
			);
		} else {
			return array(
				'valid' => false,
				'message' => __('License key is invalid or inactive. Please check and try again.', 'wc-payment-monitor'),
			);
		}
	}

	/**
	 * Get current license tier
	 *
	 * @return string License tier ('free' or 'premium')
	 */
	public static function get_license_tier()
	{
		$settings = self::get_settings();
		$license_key = isset($settings['license_key']) ? $settings['license_key'] : '';

		if (empty($license_key)) {
			return 'free';
		}

		// Check if license is premium
		$is_premium = apply_filters('wc_payment_monitor_validate_license', false, $license_key);
		return $is_premium ? 'premium' : 'free';
	}

	/**
	 * Check if premium features are available
	 *
	 * @return bool True if premium tier
	 */
	public static function is_premium()
	{
		return self::get_license_tier() === 'premium';
	}

	/**
	 * Validate all settings together
	 *
	 * @param array $settings Settings array to validate
	 * @return array Validation result with 'valid' bool, 'errors' array, and 'validated_settings'
	 */
	public static function validate_all_settings($settings)
	{
		$errors = array();
		$validated_settings = array();

		// Validate enable monitoring
		if (isset($settings['enable_monitoring'])) {
			$validated_settings['enable_monitoring'] = intval($settings['enable_monitoring']);
		}

		// Validate health check interval
		if (isset($settings['health_check_interval'])) {
			$interval_validation = self::validate_health_check_interval($settings['health_check_interval']);
			if ($interval_validation['valid']) {
				$validated_settings['health_check_interval'] = $interval_validation['value'];
			} else {
				$errors[] = 'health_check_interval: ' . $interval_validation['message'];
			}
		}

		// Validate alert threshold
		if (isset($settings['alert_threshold'])) {
			$threshold_validation = self::validate_alert_threshold($settings['alert_threshold']);
			if ($threshold_validation['valid']) {
				$validated_settings['alert_threshold'] = $threshold_validation['value'];
			} else {
				$errors[] = 'alert_threshold: ' . $threshold_validation['message'];
			}
		}

		// Validate retry enabled
		if (isset($settings['retry_enabled'])) {
			$validated_settings['retry_enabled'] = intval($settings['retry_enabled']);
		}

		// Validate max retry attempts
		if (isset($settings['max_retry_attempts'])) {
			$retry_config = array('max_retry_attempts' => $settings['max_retry_attempts']);
			$retry_validation = self::validate_retry_configuration($retry_config);
			if ($retry_validation['valid']) {
				$validated_settings['max_retry_attempts'] = $retry_config['max_retry_attempts'];
			} else {
				$errors[] = 'max_retry_attempts: ' . $retry_validation['message'];
			}
		}

		// License key - sanitize only, validation happens in validate_license_on_save
		if (isset($settings['license_key'])) {
			$validated_settings['license_key'] = sanitize_text_field($settings['license_key']);
		}

		return array(
			'valid' => empty($errors),
			'errors' => $errors,
			'validated_settings' => $validated_settings,
		);
	}

	/**
	 * Validate license when settings are saved
	 *
	 * @param array $old_value Old settings value
	 * @param array $new_value New settings value
	 */
	public function validate_license_on_save($old_value, $new_value)
	{
		// Check if license key has changed or if we need to validate existing key
		$old_key = isset($old_value['license_key']) ? $old_value['license_key'] : '';
		$new_key = isset($new_value['license_key']) ? $new_value['license_key'] : '';

		// Always validate if there's a license key (not just when it changes)
		if (!empty($new_key)) {
			// Validate new license key
			$result = $this->license->save_and_validate_license($new_key);

			if ($result['valid']) {
				add_settings_error(
					'wc_payment_monitor_options',
					'license_valid',
					__('License key validated successfully!', 'wc-payment-monitor'),
					'success'
				);

				// Show site registration status
				if ($result['site_registered']) {
					add_settings_error(
						'wc_payment_monitor_options',
						'site_registered',
						__('Site is registered with your license.', 'wc-payment-monitor'),
						'success'
					);
				} else {
					add_settings_error(
						'wc_payment_monitor_options',
						'site_not_registered',
						sprintf(
							__('Site registration failed: %s. Some features may not work properly.', 'wc-payment-monitor'),
							isset($result['site_registration_reason']) ? $result['site_registration_reason'] : 'Unknown reason'
						),
						'warning'
					);
				}
			} else {
				// Use the user-friendly error message directly
				$error_msg = $result['message'];

				// Add debug info only if WP_DEBUG is enabled and user is admin
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG && current_user_can( 'manage_options' ) && isset( $result['debug_info'] ) && ! empty( $result['debug_info'] ) ) {
					$error_msg .= sprintf(
						/* translators: %s: debug info */
						__( ' (Debug: %s)', 'wc-payment-monitor' ),
						$result['debug_info']
					);
				}

				add_settings_error(
					'wc_payment_monitor_options',
					'license_invalid',
					$error_msg,
					'error'
				);
			}
		} elseif (!empty($old_key)) {
			// License key was removed, deactivate
			$this->license->deactivate_license();
			add_settings_error(
				'wc_payment_monitor_options',
				'license_deactivated',
				__('License key removed. Plugin features have been deactivated.', 'wc-payment-monitor'),
				'info'
			);
		}
	}

	/**
	 * Render diagnostics page
	 */
	public function render_diagnostics_page()
	{
		if (!current_user_can('manage_woocommerce')) {
			wp_die(esc_html__('You do not have permission to access this page.', 'wc-payment-monitor'));
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e('Payment Monitor - Diagnostic Tools', 'wc-payment-monitor'); ?></h1>

			<div style="margin: 20px 0;">
				<p><?php esc_html_e('Use these tools to diagnose and resolve payment issues.', 'wc-payment-monitor'); ?></p>
			</div>

			<div class="wc-payment-monitor-diagnostics">
				<div class="diagnostic-section">
					<div class="section-header">
						<div>
							<h2><?php esc_html_e('System Diagnostics', 'wc-payment-monitor'); ?></h2>
							<p class="section-subtitle">
								<?php esc_html_e('Run comprehensive health checks and diagnostics', 'wc-payment-monitor'); ?>
							</p>
						</div>
					</div>
					<div class="section-content">
						<button class="button button-primary" id="run-full-diagnostics">
							<?php esc_html_e('Run Full Diagnostics', 'wc-payment-monitor'); ?>
						</button>
						<button class="button" id="recalculate-health">
							<?php esc_html_e('Recalculate Health Metrics', 'wc-payment-monitor'); ?>
						</button>
						<div id="diagnostics-results" style="margin-top: 20px;"></div>
					</div>
				</div>

				<div class="diagnostic-section">
					<div class="section-header">
						<div>
							<h2><?php esc_html_e('Failure Simulator (Test Mode)', 'wc-payment-monitor'); ?></h2>
							<p class="section-subtitle">
								<?php esc_html_e('Create test orders with simulated payment failures', 'wc-payment-monitor'); ?>
							</p>
						</div>
					</div>
					<div class="section-content">
						<p><strong><?php esc_html_e('Warning:', 'wc-payment-monitor'); ?></strong>
							<?php esc_html_e('These tools create test orders with simulated failures for testing purposes.', 'wc-payment-monitor'); ?>
						</p>

						<label for="failure-scenario"><?php esc_html_e('Failure Scenario:', 'wc-payment-monitor'); ?></label>
						<select id="failure-scenario">
							<option value="card_declined"><?php esc_html_e('Card Declined', 'wc-payment-monitor'); ?></option>
							<option value="insufficient_funds">
								<?php esc_html_e('Insufficient Funds', 'wc-payment-monitor'); ?></option>
							<option value="gateway_timeout"><?php esc_html_e('Gateway Timeout', 'wc-payment-monitor'); ?>
							</option>
							<option value="network_error"><?php esc_html_e('Network Error', 'wc-payment-monitor'); ?></option>
							<option value="fraud_detected"><?php esc_html_e('Fraud Detected', 'wc-payment-monitor'); ?>
							</option>
						</select>

						<label for="failure-gateway"><?php esc_html_e('Gateway:', 'wc-payment-monitor'); ?></label>
						<select id="failure-gateway">
							<option value=""><?php esc_html_e('Random Enabled Gateway', 'wc-payment-monitor'); ?></option>
							<?php
							$available_gateways = WC()->payment_gateways()->get_available_payment_gateways();
							foreach ($available_gateways as $gateway_id => $gateway) {
								printf(
									'<option value="%s">%s</option>',
									esc_attr($gateway_id),
									esc_html(WC_Payment_Monitor::get_friendly_gateway_name( $gateway_id ))
								);
							}
							?>
							<input type="number" id="failure-count" value="1" min="1" max="50" />

							<button class="button button-secondary" id="simulate-failure">
								<?php esc_html_e('Simulate Payment Failure', 'wc-payment-monitor'); ?>
							</button>
							<button class="button button-secondary" id="clear-simulated">
								<?php esc_html_e('Clear All Simulated Failures', 'wc-payment-monitor'); ?>
							</button>

							<div id="simulator-results" style="margin-top: 20px;"></div>
					</div>
				</div>

				<div class="diagnostic-section">
					<div class="section-header">
						<div>
							<h2><?php esc_html_e('Maintenance Tools', 'wc-payment-monitor'); ?></h2>
							<p class="section-subtitle">
								<?php esc_html_e('Clean up and maintain transaction data', 'wc-payment-monitor'); ?></p>
						</div>
					</div>
					<div class="section-content">
						<button class="button" id="clean-orphaned">
							<?php esc_html_e('Clean Orphaned Records', 'wc-payment-monitor'); ?>
						</button>
						<button class="button" id="archive-old">
							<?php esc_html_e('Archive Old Transactions (90+ days)', 'wc-payment-monitor'); ?>
						</button>
						<button class="button button-secondary" id="reset-health">
							<?php esc_html_e('Reset All Health Metrics', 'wc-payment-monitor'); ?>
						</button>
						<div id="maintenance-results" style="margin-top: 20px;"></div>
					</div>
				</div>
			</div>

			<script>
				jQuery(document).ready(function ($) {
					// Helper: Format Gateway Results
					function formatGatewayResults(data) {
						var html = '<div class="card" style="max-width: 100%; margin-top: 10px; padding: 0;">';
						html += '<h3 style="padding: 10px 15px; margin: 0; background: #f8f9fa; border-bottom: 1px solid #ddd;"><?php esc_html_e('Gateway Status Report', 'wc-payment-monitor'); ?></h3>';
						
						if (data.issues && data.issues.length > 0) {
							html += '<div class="notice notice-warning inline" style="margin: 10px 15px;"><p><strong><?php esc_html_e('Issues Found:', 'wc-payment-monitor'); ?></strong></p><ul style="list-style: disc; margin-left: 20px;">';
							data.issues.forEach(function(issue) {
								html += '<li>' + issue + '</li>';
							});
							html += '</ul></div>';
						}

						html += '<table class="widefat striped" style="border: none; box-shadow: none;">';
						html += '<thead><tr>';
						html += '<th><?php esc_html_e('Gateway', 'wc-payment-monitor'); ?></th>';
						html += '<th><?php esc_html_e('Status', 'wc-payment-monitor'); ?></th>';
						html += '<th><?php esc_html_e('Message', 'wc-payment-monitor'); ?></th>';
						html += '<th><?php esc_html_e('Last Checked', 'wc-payment-monitor'); ?></th>';
						html += '<th><?php esc_html_e('24h Success Rate', 'wc-payment-monitor'); ?></th>';
						html += '</tr></thead><tbody>';

						if (data.gateways) {
							Object.keys(data.gateways).forEach(function(key) {
								var gateway = data.gateways[key];
								var statusIcon = gateway.status === 'online' ? '✅' : (gateway.status === 'offline' ? '❌' : '⚠️');
								var statusColor = gateway.status === 'online' ? 'green' : (gateway.status === 'offline' ? 'red' : 'orange');
								
								var successRate = '-';
								if (gateway.health_24h && gateway.health_24h.success_rate !== null) {
									successRate = gateway.health_24h.success_rate + '%';
								}

								html += '<tr>';
								html += '<td><strong>' + (gateway.id) + '</strong></td>';
								html += '<td><span style="color:' + statusColor + ';">' + statusIcon + ' ' + gateway.status.toUpperCase() + '</span></td>';
								html += '<td>' + (gateway.message || '-') + '</td>';
								html += '<td>' + (gateway.last_checked || 'Never') + '</td>';
								html += '<td>' + successRate + '</td>';
								html += '</tr>';
							});
						} else {
							html += '<tr><td colspan="5"><?php esc_html_e('No gateway data available.', 'wc-payment-monitor'); ?></td></tr>';
						}

						html += '</tbody></table></div>';
						return html;
					}

					// Helper: Format Full Diagnostics
					function formatFullDiagnostics(data) {
						var html = '<div style="margin-top: 15px;">';
						html += '<h3><?php esc_html_e('System Health Report', 'wc-payment-monitor'); ?> <span style="font-weight: normal; font-size: 13px; color: #666;">(' + data.timestamp + ')</span></h3>';

						// System Info
						if (data.system_info) {
							html += '<div class="card" style="margin-bottom: 20px; padding: 15px;">';
							html += '<h4 style="margin-top: 0;"><?php esc_html_e('System Information', 'wc-payment-monitor'); ?></h4>';
							html += '<table class="widefat fixed striped" style="width: 100%; border: 1px solid #eee;"><tbody>';
							Object.entries(data.system_info).forEach(function([key, value]) {
								var label = key.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());
								html += '<tr><td style="width: 200px; font-weight: 600;">' + label + '</td><td>' + value + '</td></tr>';
							});
							html += '</tbody></table></div>';
						}

						// Database
						if (data.database) {
							var dbStatus = data.database.status === 'healthy' ? '✅ Healthy' : '⚠️ Issues Found';
							html += '<div class="card" style="margin-bottom: 20px; padding: 15px;">';
							html += '<h4 style="margin-top: 0;"><?php esc_html_e('Database Status', 'wc-payment-monitor'); ?>: ' + dbStatus + '</h4>';
							
							if (data.database.issues && data.database.issues.length > 0) {
								html += '<div class="notice notice-warning inline"><ul style="margin: 5px 0 5px 20px; list-style: disc;">';
								data.database.issues.forEach(function(issue) {
									html += '<li>' + issue + '</li>';
								});
								html += '</ul></div>';
							}
							
							if (data.database.tables) {
								html += '<p><strong>Table Status:</strong></p>';
								html += '<ul style="margin-left: 20px; list-style: circle;">';
								Object.entries(data.database.tables).forEach(function([table, info]) {
									html += '<li><strong>' + table + ':</strong> ' + (info.exists ? 'Exists' : 'Missing') + ' (' + info.count + ' records)</li>';
								});
								html += '</ul>';
							}
							html += '</div>';
						}

						// Gateways section
						if (data.gateways) {
							html += formatGatewayResults(data.gateways);
						}
						
						html += '</div>';
						return html;
					}

					// Load available payment gateways on page load
					$.ajax({
						url: wcPaymentMonitor.apiUrl + 'simulator/gateways',
						method: 'GET',
						headers: {
							'X-WP-Nonce': wcPaymentMonitor.restNonce
						},
						success: function (response) {
							if (response.data && response.data.length > 0) {
								response.data.forEach(function (gateway) {
									if (gateway.enabled) {
										$('#failure-gateway').append(
											$('<option></option>')
												.attr('value', gateway.id)
												.text(gateway.title)
										);
									}
								});
							}
						}
					});

					// Add basic JavaScript functionality
					$('#run-full-diagnostics').on('click', function () {
						var $btn = $(this);
						$btn.prop('disabled', true).text('<?php esc_attr_e('Running...', 'wc-payment-monitor'); ?>');

						$.ajax({
							url: wcPaymentMonitor.apiUrl + 'diagnostics/full',
							method: 'GET',
							headers: {
								'X-WP-Nonce': wcPaymentMonitor.restNonce
							},
							success: function (response) {
								$('#diagnostics-results').html(formatFullDiagnostics(response));
							},
							error: function (xhr) {
								$('#diagnostics-results').html('<div class="error"><p>' + (xhr.responseJSON ? xhr.responseJSON.message : 'Error occurred') + '</p></div>');
							},
							complete: function () {
								$btn.prop('disabled', false).text('<?php esc_attr_e('Run Full Diagnostics', 'wc-payment-monitor'); ?>');
							}
						});
					});

					$('#recalculate-health').on('click', function () {
						var $btn = $(this);
						$btn.prop('disabled', true);

						$.ajax({
							url: wcPaymentMonitor.apiUrl + 'diagnostics/health/recalculate',
							method: 'POST',
							headers: {
								'X-WP-Nonce': wcPaymentMonitor.restNonce
							},
							success: function (response) {
								$('#diagnostics-results').html('<div class="updated"><p>' + response.message + '</p></div>');
							},
							error: function (xhr) {
								$('#diagnostics-results').html('<div class="error"><p>' + (xhr.responseJSON ? xhr.responseJSON.message : 'Error occurred') + '</p></div>');
							},
							complete: function () {
								$btn.prop('disabled', false);
							}
						});
					});

					$('#simulate-failure').on('click', function () {
						var $btn = $(this);
						var scenario = $('#failure-scenario').val();
						var gateway = $('#failure-gateway').val();
						var count = parseInt($('#failure-count').val());

						$btn.prop('disabled', true).text('<?php esc_attr_e('Simulating...', 'wc-payment-monitor'); ?>');

						$.ajax({
							url: wcPaymentMonitor.apiUrl + 'simulator/simulate',
							method: 'POST',
							headers: {
								'X-WP-Nonce': wcPaymentMonitor.restNonce,
								'Content-Type': 'application/json'
							},
							data: JSON.stringify({
								scenario: scenario,
								gateway_id: gateway,
								count: count
							}),
							success: function (response) {
								var msg = count > 1
									? 'Created ' + response.success + ' test orders with simulated failures'
									: response.message;
								$('#simulator-results').html('<div class="updated"><p>' + msg + '</p></div>');
							},
							error: function (xhr) {
								$('#simulator-results').html('<div class="error"><p>' + (xhr.responseJSON ? xhr.responseJSON.message : 'Error occurred') + '</p></div>');
							},
							complete: function () {
								$btn.prop('disabled', false).text('<?php esc_attr_e('Simulate Payment Failure', 'wc-payment-monitor'); ?>');
							}
						});
					});

					$('#clear-simulated').on('click', function () {
						if (!confirm('<?php esc_attr_e('Are you sure you want to delete all simulated test orders?', 'wc-payment-monitor'); ?>')) {
							return;
						}

						var $btn = $(this);
						$btn.prop('disabled', true);

						$.ajax({
							url: wcPaymentMonitor.apiUrl + 'simulator/clear',
							method: 'POST',
							headers: {
								'X-WP-Nonce': wcPaymentMonitor.restNonce
							},
							success: function (response) {
								$('#simulator-results').html('<div class="updated"><p>' + response.message + '</p></div>');
							},
							error: function (xhr) {
								$('#simulator-results').html('<div class="error"><p>' + (xhr.responseJSON ? xhr.responseJSON.message : 'Error occurred') + '</p></div>');
							},
							complete: function () {
								$btn.prop('disabled', false);
							}
						});
					});

					$('#clean-orphaned').on('click', function () {
						if (!confirm('<?php esc_attr_e('Clean orphaned transaction records?', 'wc-payment-monitor'); ?>')) {
							return;
						}

						var $btn = $(this);
						$btn.prop('disabled', true);

						$.ajax({
							url: wcPaymentMonitor.apiUrl + 'diagnostics/maintenance/orphaned',
							method: 'POST',
							headers: {
								'X-WP-Nonce': wcPaymentMonitor.restNonce
							},
							success: function (response) {
								$('#maintenance-results').html('<div class="updated"><p>' + response.message + '</p></div>');
							},
							error: function (xhr) {
								$('#maintenance-results').html('<div class="error"><p>' + (xhr.responseJSON ? xhr.responseJSON.message : 'Error occurred') + '</p></div>');
							},
							complete: function () {
								$btn.prop('disabled', false);
							}
						});
					});

					$('#archive-old').on('click', function () {
						if (!confirm('<?php esc_attr_e('Archive transactions older than 90 days?', 'wc-payment-monitor'); ?>')) {
							return;
						}

						var $btn = $(this);
						$btn.prop('disabled', true);

						$.ajax({
							url: wcPaymentMonitor.apiUrl + 'diagnostics/maintenance/archive',
							method: 'POST',
							headers: {
								'X-WP-Nonce': wcPaymentMonitor.restNonce
							},
							success: function (response) {
								$('#maintenance-results').html('<div class="updated"><p>' + response.message + '</p></div>');
							},
							error: function (xhr) {
								$('#maintenance-results').html('<div class="error"><p>' + (xhr.responseJSON ? xhr.responseJSON.message : 'Error occurred') + '</p></div>');
							},
							complete: function () {
								$btn.prop('disabled', false);
							}
						});
					});

					$('#reset-health').on('click', function () {
						if (!confirm('<?php esc_attr_e('Reset all health metrics? This cannot be undone.', 'wc-payment-monitor'); ?>')) {
							return;
						}

						var $btn = $(this);
						$btn.prop('disabled', true);

						$.ajax({
							url: wcPaymentMonitor.apiUrl + 'diagnostics/health/reset',
							method: 'POST',
							headers: {
								'X-WP-Nonce': wcPaymentMonitor.restNonce
							},
							success: function (response) {
								$('#maintenance-results').html('<div class="updated"><p>' + response.message + '</p></div>');
							},
							error: function (xhr) {
								$('#maintenance-results').html('<div class="error"><p>' + (xhr.responseJSON ? xhr.responseJSON.message : 'Error occurred') + '</p></div>');
							},
							complete: function () {
								$btn.prop('disabled', false);
							}
						});
					});
				});
			</script>

			<style>
				.wc-payment-monitor-diagnostics .diagnostic-section {
					margin-bottom: 20px;
				}

				.wc-payment-monitor-diagnostics .section-header {
					display: flex;
					justify-content: space-between;
					align-items: flex-start;
					padding: 20px;
					background: #fff;
					border: 1px solid #ccd0d4;
					border-radius: 4px;
					margin-bottom: 0;
				}

				.wc-payment-monitor-diagnostics .section-header h2 {
					margin: 0 0 5px 0;
					color: #1d2327;
					font-size: 20px;
					font-weight: 600;
				}

				.wc-payment-monitor-diagnostics .section-subtitle {
					margin: 0;
					color: #646970;
					font-size: 14px;
				}

				.wc-payment-monitor-diagnostics .section-content {
					padding: 20px;
					background: #fff;
					border: 1px solid #ccd0d4;
					border-top: none;
					border-radius: 0 0 4px 4px;
				}

				.wc-payment-monitor-diagnostics button {
					margin-right: 10px;
					margin-bottom: 10px;
				}

				.wc-payment-monitor-diagnostics label {
					display: inline-block;
					margin: 10px 10px 10px 0;
					font-weight: bold;
				}

				.wc-payment-monitor-diagnostics select,
				.wc-payment-monitor-diagnostics input[type="number"] {
					margin-right: 15px;
				}

				.wc-payment-monitor-diagnostics pre {
					background: #f5f5f5;
					padding: 15px;
					border: 1px solid #ddd;
					border-radius: 3px;
					overflow-x: auto;
					max-height: 500px;
				}
			</style>
		</div>
		<?php
	}
}
