<?php
/**
 * Admin Settings Handler
 *
 * Handles settings registration, validation, and field rendering for the Payment Monitor plugin.
 *
 * @package PaySentinel
 * @since 1.0.0
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PaySentinel_Admin_Settings_Handler
 *
 * Manages WordPress settings API integration and form field rendering.
 */
class PaySentinel_Admin_Settings_Handler {



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
	 * Constructor
	 *
	 * @param PaySentinel_Security $security Security instance.
	 * @param PaySentinel_License  $license License instance.
	 */
	public function __construct( $security, $license ) {
		$this->security = $security;
		$this->license  = $license;
	}

	/**
	 * Register plugin settings
	 *
	 * Registers WordPress settings API sections and fields for the Payment Monitor plugin.
	 * This includes general settings, notification settings, gateway settings, and advanced settings.
	 */
	public function register_settings() {
		// Register setting group
		register_setting(
			'paysentinel_settings',
			'paysentinel_options',
			array(
				'type'              => 'object',
				'sanitize_callback' => array( $this->security, 'validate_admin_settings' ),
				'show_in_rest'      => false,
			)
		);

		$this->register_settings_sections();
		$this->register_settings_fields();
	}

	/**
	 * Register settings sections
	 *
	 * Registers WordPress settings sections for organizing plugin settings.
	 *
	 * @return void
	 */
	private function register_settings_sections() {
		add_settings_section(
			'paysentinel_general',
			__( 'General Settings', 'paysentinel' ),
			array( $this, 'render_general_section' ),
			'paysentinel_settings'
		);

		add_settings_section(
			'paysentinel_gateways',
			__( 'Gateway Settings', 'paysentinel' ),
			array( $this, 'render_gateways_section' ),
			'paysentinel_settings'
		);

		add_settings_section(
			'paysentinel_advanced',
			__( 'Advanced Settings', 'paysentinel' ),
			array( $this, 'render_advanced_section' ),
			'paysentinel_settings'
		);
	}

	/**
	 * Register settings fields
	 *
	 * Registers all settings fields for the Payment Monitor plugin, including
	 * general, notification, gateway, and advanced settings fields.
	 *
	 * @return void
	 */
	private function register_settings_fields() {
		// General fields
		add_settings_field(
			'enable_monitoring',
			__( 'Enable Monitoring', 'paysentinel' ),
			array( $this, 'render_field_enable_monitoring' ),
			'paysentinel_settings',
			'paysentinel_general'
		);

		add_settings_field(
			'health_check_interval',
			__( 'Health Check Interval (minutes)', 'paysentinel' ),
			array( $this, 'render_field_health_check_interval' ),
			'paysentinel_settings',
			'paysentinel_general'
		);

		add_settings_field(
			'alert_threshold',
			__( 'Alert Threshold (%)', 'paysentinel' ),
			array( $this, 'render_field_alert_threshold' ),
			'paysentinel_settings',
			'paysentinel_general'
		);

		add_settings_field(
			'retry_enabled',
			__( 'Enable Payment Retry', 'paysentinel' ),
			array( $this, 'render_field_retry_enabled' ),
			'paysentinel_settings',
			'paysentinel_general'
		);

		add_settings_field(
			'max_retry_attempts',
			__( 'Max Retry Attempts', 'paysentinel' ),
			array( $this, 'render_field_max_retry_attempts' ),
			'paysentinel_settings',
			'paysentinel_general'
		);

		// Gateway configuration
		add_settings_field(
			'gateway_alert_config',
			__( 'Per-Gateway Alert Configuration', 'paysentinel' ),
			array( $this, 'render_field_gateway_alert_config' ),
			'paysentinel_settings',
			'paysentinel_gateways'
		);

		// Advanced settings
		add_settings_field(
			'enable_test_mode',
			__( 'Enable Test Mode', 'paysentinel' ),
			array( $this, 'render_field_enable_test_mode' ),
			'paysentinel_settings',
			'paysentinel_advanced'
		);

		add_settings_field(
			'test_failure_rate',
			__( 'Test Failure Rate (%)', 'paysentinel' ),
			array( $this, 'render_field_test_failure_rate' ),
			'paysentinel_settings',
			'paysentinel_advanced'
		);
	}

	/**
	 * Render general section
	 */
	public function render_general_section() {
		echo '<p>' . esc_html__( 'Core monitoring and protection settings.', 'paysentinel' ) . '</p>';
	}

	/**
	 * Render gateways section
	 */
	public function render_gateways_section() {
		echo '<p>' . esc_html__( 'Configure behavior for specific payment gateways.', 'paysentinel' ) . '</p>';
	}

	/**
	 * Render advanced section
	 */
	public function render_advanced_section() {
		echo '<p>' . esc_html__( 'Debugging and simulation tools.', 'paysentinel' ) . '</p>';
	}

	/**
	 * Render settings section description
	 */
	public function render_settings_section() {
		echo '<p>' . esc_html__( 'Configure PaySentinel settings below.', 'paysentinel' ) . '</p>';
	}

	/**
	 * Render enable monitoring field
	 */
	public function render_field_enable_monitoring() {
		$options = get_option( 'paysentinel_options', array() );
		$enabled = isset( $options['enable_monitoring'] ) ? intval( $options['enable_monitoring'] ) : 1;
		?>
		<input type="checkbox" name="paysentinel_options[enable_monitoring]" value="1" <?php checked( $enabled, 1 ); ?> />
		<label>
			<?php esc_html_e( 'Monitor payment gateway transactions', 'paysentinel' ); ?>
			<?php if ( function_exists( 'wc_help_tip' ) ) : ?>
				<?php echo wc_help_tip( __( 'When enabled, the plugin tracks all WooCommerce payment gateway transactions and logs them for health analysis.', 'paysentinel' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<?php endif; ?>
		</label>
		<?php
	}

	/**
	 * Render health check interval field
	 */
	public function render_field_health_check_interval() {
		$options  = get_option( 'paysentinel_options', array() );
		$interval = isset( $options['health_check_interval'] ) ? intval( $options['health_check_interval'] ) : 5;
		?>
		<input type="number" name="paysentinel_options[health_check_interval]" value="<?php echo esc_attr( $interval ); ?>"
			min="1" max="1440" />
		<p class="description">
			<?php esc_html_e( 'How often to recalculate gateway health (in minutes).', 'paysentinel' ); ?>
		</p>
		<?php
	}

	/**
	 * Render alert threshold field
	 */
	public function render_field_alert_threshold() {
		$options   = get_option( 'paysentinel_options', array() );
		$threshold = isset( $options['alert_threshold'] ) ? floatval( $options['alert_threshold'] ) : 85;
		?>
		<style>
			.paysentinel-threshold-ui {
				max-width: 450px;
				background: #fff;
				border: 1px solid #ccd0d4;
				padding: 20px;
				border-radius: 4px;
				box-shadow: 0 1px 1px rgba(0, 0, 0, .04);
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
				box-shadow: 0 1px 3px rgba(0, 0, 0, 0.3);
			}

			#alert-threshold-slider::-moz-range-thumb {
				border: 2px solid #fff;
				height: 18px;
				width: 18px;
				border-radius: 50%;
				background: #2271b1;
				cursor: pointer;
				box-shadow: 0 1px 3px rgba(0, 0, 0, 0.3);
			}
		</style>

		<div class="paysentinel-threshold-ui">
			<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
				<label for="alert-threshold-slider"
					style="margin: 0; font-weight: 600; font-size: 14px;"><?php esc_html_e( 'Alert Sensitivity', 'paysentinel' ); ?></label>
				<span
					style="background: #2271b1; color: #fff; padding: 2px 10px; border-radius: 12px; font-weight: 600; font-size: 14px; min-width: 40px; text-align: center;">
					<span id="threshold-val"><?php echo esc_attr( $threshold ); ?></span>%
				</span>
			</div>

			<div class="threshold-slider-container">
				<div class="threshold-bar-background"></div>
				<input type="range" name="paysentinel_options[alert_threshold]" id="alert-threshold-slider"
					value="<?php echo esc_attr( $threshold ); ?>" min="50" max="100" step="1" />
			</div>

			<div
				style="display: flex; justify-content: space-between; font-size: 11px; color: #8c8f94; margin-bottom: 25px; padding: 0 2px;">
				<span>50%</span>
				<span>75%</span>
				<span>90%</span>
				<span>95%</span>
				<span>100%</span>
			</div>

			<div
				style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; font-size: 12px; border-top: 1px solid #f0f0f1; padding-top: 15px;">
				<div style="display: flex; align-items: center;">
					<span
						style="width: 12px; height: 12px; background: #d63638; border-radius: 2px; margin-right: 8px; display: inline-block;"></span>
					<span><?php esc_html_e( 'High Severity (< 75%)', 'paysentinel' ); ?></span>
				</div>
				<div style="display: flex; align-items: center;">
					<span
						style="width: 12px; height: 12px; background: #dba617; border-radius: 2px; margin-right: 8px; display: inline-block;"></span>
					<span><?php esc_html_e( 'Warning (75-89%)', 'paysentinel' ); ?></span>
				</div>
				<div style="display: flex; align-items: center;">
					<span
						style="width: 12px; height: 12px; background: #ffba00; border-radius: 2px; margin-right: 8px; display: inline-block;"></span>
					<span><?php esc_html_e( 'Info (90-94%)', 'paysentinel' ); ?></span>
				</div>
				<div style="display: flex; align-items: center;">
					<span
						style="width: 12px; height: 12px; background: #46b450; border-radius: 2px; margin-right: 8px; display: inline-block;"></span>
					<span><?php esc_html_e( 'Healthy (≥ 95%)', 'paysentinel' ); ?></span>
				</div>
			</div>
		</div>

		<script>
			document.addEventListener('DOMContentLoaded', function () {
				const slider = document.getElementById('alert-threshold-slider');
				const display = document.getElementById('threshold-val');
				if (slider && display) {
					slider.addEventListener('input', function () {
						display.textContent = this.value;
					});
				}
			});
		</script>

		<p class="description">
			<?php esc_html_e( 'Adjust the slider to set your notification threshold. You will only receive alerts if the gateway success rate falls below this percentage.', 'paysentinel' ); ?>
		</p>
		<?php
	}

	/**
	 * Render retry enabled field
	 */
	public function render_field_retry_enabled() {
		$options = get_option( 'paysentinel_options', array() );
		$enabled = isset( $options['retry_enabled'] ) ? intval( $options['retry_enabled'] ) : 1;
		?>
		<input type="checkbox" name="paysentinel_options[retry_enabled]" value="1" <?php checked( $enabled, 1 ); ?> />
		<label>
			<?php esc_html_e( 'Automatically retry failed payments', 'paysentinel' ); ?>
			<?php if ( function_exists( 'wc_help_tip' ) ) : ?>
				<?php echo wc_help_tip( __( 'Attempt to automatically retry processing payments that failed due to temporary network or gateway issues.', 'paysentinel' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<?php endif; ?>
		</label>
		<p class="description">
			<?php esc_html_e( 'When enabled, failed payments are automatically retried using stored payment methods. Retries are scheduled at 1 hour, 6 hours, and 24 hours. Only retriable failures are attempted (excludes fraud, expired cards, etc.).', 'paysentinel' ); ?>
		</p>
		<?php
	}

	/**
	 * Render max retry attempts field
	 */
	public function render_field_max_retry_attempts() {
		$options  = get_option( 'paysentinel_options', array() );
		$attempts = isset( $options['max_retry_attempts'] ) ? intval( $options['max_retry_attempts'] ) : 3;
		?>
		<input type="number" name="paysentinel_options[max_retry_attempts]" value="<?php echo esc_attr( $attempts ); ?>" min="1"
			max="10" />
		<p class="description"><?php esc_html_e( 'Maximum number of retry attempts per transaction.', 'paysentinel' ); ?>
		</p>
		<?php
	}

	/**
	 * Render license key field
	 */
	public function render_field_license_key() {
		$options        = get_option( 'paysentinel_options', array() );
		$license_key    = $this->license->get_license_key();
		$license_status = $this->license->get_license_status();
		$license_data   = $this->license->get_license_data();
		$tier           = $this->license->get_license_tier();

		$tier_colors = array(
			'free'    => '#646970',
			'starter' => '#0073aa',
			'pro'     => '#d63638',
			'agency'  => '#9b51e0',
		);
		$badge_color = isset( $tier_colors[ $tier ] ) ? $tier_colors[ $tier ] : '#0073aa';
		?>
		<style>
			.license-field-wrapper {
				max-width: 500px;
				background: #fff;
				border: 1px solid #ccd0d4;
				border-radius: 4px;
				overflow: hidden;
				box-shadow: 0 1px 1px rgba(0, 0, 0, .04);
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
					<span class="dashicons dashicons-admin-network"
						style="color: <?php echo esc_attr( $badge_color ); ?>;"></span>
					<span
						style="font-weight: 600; color: #23282d;"><?php esc_html_e( 'Subscription Plan', 'paysentinel' ); ?></span>
				</div>
				<span class="license-status-badge" style="background: <?php echo esc_attr( $badge_color ); ?>;">
					<?php echo esc_html( ucwords( $tier ) ); ?>
				</span>
			</div>

			<div class="license-body">
				<div class="license-input-group">
					<input type="password" id="paysentinel_license_key" value="<?php echo esc_attr( $license_key ); ?>"
						style="flex-grow: 1; padding: 8px;"
						placeholder="<?php esc_html_e( 'PA-XXXX-XXXX-XXXX', 'paysentinel' ); ?>" />
					<button type="button" class="button"
						onclick="var field = document.getElementById('paysentinel_license_key'); var type = field.type === 'password' ? 'text' : 'password'; field.type = type; this.textContent = type === 'password' ? 'Show' : 'Hide';"
						style="min-width: 60px;">
						<?php esc_html_e( 'Show', 'paysentinel' ); ?>
					</button>
				</div>

				<?php if ( 'valid' === $license_status ) : ?>
					<div style="display: flex; justify-content: space-between; align-items: flex-end;">
						<div>
							<p style="margin: 0; color: #46b450; font-size: 13px; font-weight: 500;">
								<span class="dashicons dashicons-yes-alt"
									style="font-size: 16px; width: 16px; height: 16px; margin-right: 4px;"></span>
								<?php esc_html_e( 'Active protection enabled', 'paysentinel' ); ?>
							</p>
							<?php if ( isset( $license_data['expiration_ts'] ) ) : ?>
								<p style="margin: 5px 0 0; font-size: 12px; color: #646970;">
									<?php
									/* translators: %s: expiration date */
									echo esc_html( sprintf( __( 'Renews on: %s', 'paysentinel' ), date_i18n( get_option( 'date_format' ), strtotime( $license_data['expiration_ts'] ) ) ) );
									?>
								</p>
							<?php endif; ?>
							<p style="margin: 10px 0 0;">
								<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=paysentinel_deactivate_license' ), 'paysentinel_deactivate_license' ) ); ?>"
									class="submitdelete deletion"
									onclick="return confirm('<?php echo esc_js( __( 'Are you sure you want to deactivate your license? This will stop all PaySentinel monitoring.', 'paysentinel' ) ); ?>');"
									style="text-decoration: none; font-size: 12px;">
									<?php esc_html_e( 'Deactivate License', 'paysentinel' ); ?>
								</a>
								<span style="color: #ccd0d4; margin: 0 5px;">|</span>
								<a href="#"
									onclick="document.getElementById('paysentinel_license_key').value=''; document.getElementById('paysentinel_license_key').focus(); return false;"
									style="text-decoration: none; font-size: 12px; color: #2271b1;">
									<?php esc_html_e( 'Change Key', 'paysentinel' ); ?>
								</a>
							</p>
						</div>

						<?php if ( $tier !== 'agency' ) : ?>
							<?php
							$next_tier = ( $tier === 'free' ) ? 'Starter' : ( ( $tier === 'starter' ) ? 'Pro' : 'Agency' );
							?>
							<a href="<?php echo esc_url( PaySentinel_License::SAAS_URL . '/plans' ); ?>" target="_blank"
								class="upgrade-button">
								<span class="dashicons dashicons-star-filled"></span>
								<?php
								/* translators: %s: tier name */
								echo esc_html( sprintf( __( 'Upgrade to %s', 'paysentinel' ), $next_tier ) );
								?>
							</a>
						<?php endif; ?>
					</div>
				<?php else : ?>
					<p style="margin: 0; color: #d63638; font-size: 13px;">
						<span class="dashicons dashicons-warning"
							style="font-size: 16px; width: 16px; height: 16px; margin-right: 4px;"></span>
						<?php esc_html_e( 'Enter a valid license key to unlock real-time monitoring and Slack alerts.', 'paysentinel' ); ?>
					</p>
					<div style="margin-top: 15px;">
						<a href="<?php echo esc_url( PaySentinel_License::SAAS_URL . '/plans' ); ?>" target="_blank"
							class="button button-primary">
							<?php esc_html_e( 'Get a License Key', 'paysentinel' ); ?>
						</a>
					</div>
				<?php endif; ?>
			</div>

			<?php if ( 'valid' === $license_status && $this->license->is_site_registered() ) : ?>
				<div class="license-footer">
					<span style="color: #646970;">
						<span class="dashicons dashicons-admin-site"
							style="font-size: 14px; width: 14px; height: 14px; vertical-align: text-bottom;"></span>
						<?php echo esc_html( wp_parse_url( get_site_url(), PHP_URL_HOST ) ); ?>
					</span>
					<span style="color: #46b450; font-weight: 500;"><?php esc_html_e( 'Verified', 'paysentinel' ); ?></span>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render enable test mode field
	 */
	public function render_field_enable_test_mode() {
		$options = get_option( 'paysentinel_settings', array() );
		$enabled = isset( $options['enable_test_mode'] ) ? intval( $options['enable_test_mode'] ) : 0;
		?>
		<input type="checkbox" name="paysentinel_options[enable_test_mode]" value="1" <?php checked( $enabled, 1 ); ?> />
		<label>
			<?php esc_html_e( 'Enable payment failure simulation for testing', 'paysentinel' ); ?>
			<?php if ( function_exists( 'wc_help_tip' ) ) : ?>
				<?php echo wc_help_tip( __( 'Simulates payment failures directly in the checkout to test the alerting mechanism. Ensure this is only enabled on test sites.', 'paysentinel' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<?php endif; ?>
		</label>
		<p class="description" style="color: #d63638;">
			<strong><?php esc_html_e( 'Warning:', 'paysentinel' ); ?></strong>
			<?php esc_html_e( 'This will simulate random payment failures during checkout. Only enable on test/development sites!', 'paysentinel' ); ?>
		</p>
		<?php
	}

	/**
	 * Render per-gateway alert configuration field
	 */
	public function render_field_gateway_alert_config() {
		$settings       = get_option( 'paysentinel_settings', array() );
		$gateway_config = isset( $settings[ PaySentinel_Settings_Constants::GATEWAY_ALERT_CONFIG ] ) ? $settings[ PaySentinel_Settings_Constants::GATEWAY_ALERT_CONFIG ] : array();
		$tier           = $this->license->get_license_tier();
		$is_locked      = ! in_array( $tier, array( 'pro', 'agency' ), true );

		// Get active WooCommerce payment gateways
		$active_gateways = array();
		if ( class_exists( 'WC_Payment_Gateways' ) ) {
			$payment_gateways = WC_Payment_Gateways::instance();
			$gateways         = $payment_gateways->get_available_payment_gateways();
			foreach ( $gateways as $gateway_id => $gateway ) {
				$active_gateways[ $gateway_id ] = PaySentinel::get_friendly_gateway_name( $gateway_id );
			}
		}

		// Add common gateway IDs if not detected
		$default_gateways = array(
			'stripe'      => 'Stripe',
			'paypal'      => 'PayPal',
			'square'      => 'Square',
			'wc_payments' => 'WooCommerce Payments',
		);
		foreach ( $default_gateways as $id => $name ) {
			if ( ! isset( $active_gateways[ $id ] ) ) {
				$active_gateways[ $id ] = $name;
			}
		}
		?>
		<div style="position: relative;">
			<?php if ( $is_locked ) : ?>
				<div
					style="background: #fff3cd; border: 1px solid #ffc107; padding: 15px; border-radius: 4px; margin-bottom: 15px;">
					<span class="dashicons dashicons-lock"
						style="color: #856404; float: left; margin-right: 10px; font-size: 24px;"></span>
					<p style="margin: 0; color: #856404;">
						<strong><?php esc_html_e( 'Pro Feature', 'paysentinel' ); ?></strong><br>
						<?php
						/* translators: %s: upgrade URL */
						echo wp_kses(
							sprintf(
								/* translators: %s: placeholder */
								__( 'Per-gateway alert configuration requires Pro plan or higher. <a href="%s" class="button button-primary" style="margin-left: 10px;">Upgrade to Pro</a>', 'paysentinel' ),
								esc_url( admin_url( 'admin.php?page=paysentinel-settings&tab=license' ) )
							),
							array(
								'a' => array(
									'href'  => array(),
									'class' => array(),
									'style' => array(),
								),
							)
						);
						?>
					</p>
				</div>
			<?php else : ?>
				<p class="description" style="margin-bottom: 15px;">
					<?php esc_html_e( 'Configure custom alert thresholds and channels for each payment gateway. Leave empty to use global settings.', 'paysentinel' ); ?>
				</p>
			<?php endif; ?>

			<table class="widefat" style="<?php echo $is_locked ? 'opacity: 0.5; pointer-events: none;' : ''; ?>">
				<thead>
					<tr>
						<th style="width: 30%; padding-left: 10px;"><?php esc_html_e( 'Gateway', 'paysentinel' ); ?></th>
						<th style="width: 15%; padding-left: 10px;"><?php esc_html_e( 'Enabled', 'paysentinel' ); ?></th>
						<th style="width: 20%; padding-left: 10px;"><?php esc_html_e( 'Threshold (%)', 'paysentinel' ); ?></th>
						<th style="width: 35%; padding-left: 10px;"><?php esc_html_e( 'Alert Channels', 'paysentinel' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $active_gateways as $gateway_id => $gateway_name ) : ?>
						<?php
						$config    = isset( $gateway_config[ $gateway_id ] ) ? $gateway_config[ $gateway_id ] : array();
						$enabled   = isset( $config[ PaySentinel_Settings_Constants::GATEWAY_CONFIG_ENABLED ] ) ? (bool) $config[ PaySentinel_Settings_Constants::GATEWAY_CONFIG_ENABLED ] : true;
						$threshold = isset( $config[ PaySentinel_Settings_Constants::GATEWAY_CONFIG_THRESHOLD ] ) ? floatval( $config[ PaySentinel_Settings_Constants::GATEWAY_CONFIG_THRESHOLD ] ) : '';
						$channels  = isset( $config[ PaySentinel_Settings_Constants::GATEWAY_CONFIG_CHANNELS ] ) ? $config[ PaySentinel_Settings_Constants::GATEWAY_CONFIG_CHANNELS ] : array( 'email' );
						?>
						<tr>
							<td><strong><?php echo esc_html( $gateway_name ); ?></strong></td>
							<td>
								<input type="checkbox"
									name="paysentinel_options[gateway_alert_config][<?php echo esc_attr( $gateway_id ); ?>][enabled]"
									value="1" <?php checked( $enabled, true ); ?> 			<?php echo $is_locked ? 'disabled' : ''; ?> />
							</td>
							<td>
								<input type="number"
									name="paysentinel_options[gateway_alert_config][<?php echo esc_attr( $gateway_id ); ?>][threshold]"
									value="<?php echo esc_attr( $threshold ); ?>"
										placeholder="<?php echo esc_attr( $settings[ PaySentinel_Settings_Constants::ALERT_THRESHOLD ] ?? 85 ); ?>" min="1" max="100"
									step="0.1" style="width: 80px;" <?php echo $is_locked ? 'disabled' : ''; ?> />
							</td>
							<td>
								<label style="margin-right: 15px; display: inline-block;">
									<input type="checkbox"
										name="paysentinel_options[gateway_alert_config][<?php echo esc_attr( $gateway_id ); ?>][channels][]"
										value="email" <?php checked( in_array( 'email', $channels ), true ); ?> <?php echo $is_locked ? 'disabled' : ''; ?> />
									<?php esc_html_e( 'Email', 'paysentinel' ); ?>
								</label>
								<label style="margin-right: 15px; display: inline-block;">
									<input type="checkbox"
										name="paysentinel_options[gateway_alert_config][<?php echo esc_attr( $gateway_id ); ?>][channels][]"
										value="slack" <?php checked( in_array( 'slack', $channels ), true ); ?> <?php echo $is_locked ? 'disabled' : ''; ?> />
									<?php esc_html_e( 'Slack', 'paysentinel' ); ?>
								</label>
								<label style="margin-right: 15px; display: inline-block;">
									<input type="checkbox"
										name="paysentinel_options[gateway_alert_config][<?php echo esc_attr( $gateway_id ); ?>][channels][]"
										value="discord" <?php checked( in_array( 'discord', $channels ), true ); ?> <?php echo $is_locked ? 'disabled' : ''; ?> />
									<?php esc_html_e( 'Discord', 'paysentinel' ); ?>
								</label>
								<label style="display: inline-block;">
									<input type="checkbox"
										name="paysentinel_options[gateway_alert_config][<?php echo esc_attr( $gateway_id ); ?>][channels][]"
										value="teams" <?php checked( in_array( 'teams', $channels ), true ); ?> <?php echo $is_locked ? 'disabled' : ''; ?> />
									<?php esc_html_e( 'Teams', 'paysentinel' ); ?>
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
	public function render_field_test_failure_rate() {
		$options = get_option( 'paysentinel_settings', array() );
		$rate    = isset( $options['test_failure_rate'] ) ? intval( $options['test_failure_rate'] ) : 10;
		?>
		<input type="number" name="paysentinel_options[test_failure_rate]" value="<?php echo esc_attr( $rate ); ?>" min="0"
			max="100" />
		<p class="description">
			<?php esc_html_e( 'Percentage of payments to simulate as failures (only when test mode is enabled).', 'paysentinel' ); ?>
		</p>
		<?php
	}

	/**
	 * Render license section
	 *
	 * Renders the license management section on the settings page with license validation,
	 * activation status, and upgrade options.
	 */
	public function render_license_section() {
		$license_key    = $this->license->get_license_key();
		$license_status = $this->license->get_license_status();
		$license_data   = $this->license->get_license_data();
		$tier           = $this->license->get_license_tier();

		$tier_colors = array(
			'free'    => '#646970',
			'starter' => '#0073aa',
			'pro'     => '#d63638',
			'agency'  => '#9b51e0',
		);
		$badge_color = isset( $tier_colors[ $tier ] ) ? $tier_colors[ $tier ] : '#0073aa';
		?>
		<style>
			.license-section-wrapper {
				max-width: 800px;
				background: #fff;
				border: 1px solid #ccd0d4;
				border-radius: 4px;
				overflow: hidden;
				box-shadow: 0 1px 1px rgba(0, 0, 0, .04);
				margin-bottom: 30px;
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
				max-width: 500px;
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

			.license-loading {
				display: none;
				vertical-align: middle;
				margin-left: 10px;
			}
		</style>

		<div class="license-section-wrapper">
			<div class="license-header">
				<div style="display: flex; align-items: center; gap: 10px;">
					<span class="dashicons dashicons-admin-network"
						style="color: <?php echo esc_attr( $badge_color ); ?>;"></span>
					<span
						style="font-weight: 600; color: #23282d;"><?php esc_html_e( 'License Management', 'paysentinel' ); ?></span>
				</div>
				<div style="display: flex; align-items: center; gap: 10px;">
					<span style="font-size: 12px; color: #646970;"><?php esc_html_e( 'Plan:', 'paysentinel' ); ?></span>
					<span class="license-status-badge" style="background: <?php echo esc_attr( $badge_color ); ?>;">
						<?php echo esc_html( ucwords( $tier ) ); ?>
					</span>
				</div>
			</div>

			<div class="license-body">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="paysentinel-license-form">
					<input type="hidden" name="action" value="paysentinel_save_license">
					<?php wp_nonce_field( 'paysentinel_save_license' ); ?>

					<label for="paysentinel_license_key_input" style="display: block; margin-bottom: 8px; font-weight: 600;">
						<?php esc_html_e( 'License Key', 'paysentinel' ); ?>
					</label>

					<div class="license-input-group">
						<input type="password" id="paysentinel_license_key_input" name="license_key"
							value="<?php echo esc_attr( $license_key ); ?>" style="flex-grow: 1; padding: 8px;"
							placeholder="<?php esc_html_e( 'PA-XXXX-XXXX-XXXX', 'paysentinel' ); ?>" />

						<button type="button" class="button"
							onclick="var field = document.getElementById('paysentinel_license_key_input'); var type = field.type === 'password' ? 'text' : 'password'; field.type = type; this.textContent = type === 'password' ? 'Show' : 'Hide';"
							style="min-width: 60px;">
							<?php esc_html_e( 'Show', 'paysentinel' ); ?>
						</button>

						<button type="submit" class="button button-primary" id="validate-license-btn">
							<?php esc_html_e( 'Validate License', 'paysentinel' ); ?>
						</button>
						<span class="spinner license-loading"></span>
					</div>
				</form>

				<div id="license-message-area">
					<?php if ( 'valid' === $license_status ) : ?>
						<div style="display: flex; justify-content: space-between; align-items: flex-end;">
							<div>
								<p style="margin: 0; color: #46b450; font-size: 13px; font-weight: 500;">
									<span class="dashicons dashicons-yes-alt"
										style="font-size: 16px; width: 16px; height: 16px; margin-right: 4px;"></span>
									<?php esc_html_e( 'Active protection enabled', 'paysentinel' ); ?>
								</p>
								<?php if ( isset( $license_data['expiration_ts'] ) ) : ?>
									<p style="margin: 5px 0 0; font-size: 12px; color: #646970;">
										<?php
										/* translators: %s: expiration date */
										echo esc_html( sprintf( __( 'Renews on: %s', 'paysentinel' ), date_i18n( get_option( 'date_format' ), strtotime( $license_data['expiration_ts'] ) ) ) );
										?>
									</p>
								<?php endif; ?>
								<p style="margin: 10px 0 0;">
									<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=paysentinel_deactivate_license' ), 'paysentinel_deactivate_license' ) ); ?>"
										class="submitdelete deletion"
										onclick="return confirm('<?php echo esc_js( __( 'Are you sure you want to deactivate your license? This will stop all PaySentinel monitoring.', 'paysentinel' ) ); ?>');"
										style="text-decoration: none; font-size: 12px;">
										<?php esc_html_e( 'Deactivate License', 'paysentinel' ); ?>
									</a>
								</p>
							</div>

							<div style="display: flex; gap: 10px; align-items: flex-end;">
								<?php if ( $tier !== 'agency' ) : ?>
									<?php
									$next_tier = ( $tier === 'free' ) ? 'Starter' : ( ( $tier === 'starter' ) ? 'Pro' : 'Agency' );
									?>
									<a href="<?php echo esc_url( PaySentinel_License::SAAS_URL . '/plans' ); ?>" target="_blank"
										class="upgrade-button">
										<span class="dashicons dashicons-star-filled"></span>
										<?php
										/* translators: %s: tier name */
										echo esc_html( sprintf( __( 'Upgrade to %s', 'paysentinel' ), $next_tier ) );
										?>
									</a>
								<?php endif; ?>
							</div>
						</div>
					<?php else : ?>
						<p style="margin: 0; color: #d63638; font-size: 13px;">
							<span class="dashicons dashicons-warning"
								style="font-size: 16px; width: 16px; height: 16px; margin-right: 4px;"></span>
							<?php esc_html_e( 'Enter a valid license key to unlock real-time monitoring and Slack alerts.', 'paysentinel' ); ?>
						</p>
						<div style="margin-top: 15px;">
							<a href="<?php echo esc_url( PaySentinel_License::SAAS_URL . '/plans' ); ?>" target="_blank"
								class="button button-secondary">
								<?php esc_html_e( 'Get a License Key', 'paysentinel' ); ?>
							</a>
						</div>
					<?php endif; ?>
				</div>
			</div>

			<?php if ( 'valid' === $license_status && $this->license->is_site_registered() ) : ?>
				<div class="license-footer">
					<span style="color: #646970;">
						<span class="dashicons dashicons-admin-site"
							style="font-size: 14px; width: 14px; height: 14px; vertical-align: text-bottom;"></span>
						<?php echo esc_html( wp_parse_url( get_site_url(), PHP_URL_HOST ) ); ?>
					</span>
					<span
						style="color: #46b450; font-weight: 500;"><?php esc_html_e( 'Verified & Registered', 'paysentinel' ); ?></span>
				</div>
			<?php endif; ?>
		</div>

		<script>
			jQuery(document).ready(function ($) {
				const $btn = $('#validate-license-btn');
				const $spinner = $('.license-loading');
				const $input = $('#paysentinel_license_key_input');
				const $msgArea = $('#license-message-area');

				$btn.on('click', function (e) {
					e.preventDefault();

					const licenseKey = $input.val();
					if (!licenseKey) {
						alert('<?php echo esc_js( __( 'Please enter a license key.', 'paysentinel' ) ); ?>');
						return;
					}

					$btn.prop('disabled', true);
					$spinner.addClass('is-active').show();

					$.ajax({
						url: ajaxurl,
						type: 'POST',
						data: {
							action: 'paysentinel_validate_license',
							license_key: licenseKey,
							nonce: '<?php echo esc_js( wp_create_nonce( 'paysentinel_validate_license' ) ); ?>'
						},
						success: function (response) {
							if (response.success) {
								location.reload(); // Simplest way to update all UI parts
							} else {
								alert(response.data.message || '<?php echo esc_js( __( 'Validation failed.', 'paysentinel' ) ); ?>');
							}
						},
						error: function () {
							alert('<?php echo esc_js( __( 'An error occurred during validation.', 'paysentinel' ) ); ?>');
						},
						complete: function () {
							$btn.prop('disabled', false);
							$spinner.removeClass('is-active').hide();
						}
					});
				});
			});
		</script>
		<?php
	}
}
