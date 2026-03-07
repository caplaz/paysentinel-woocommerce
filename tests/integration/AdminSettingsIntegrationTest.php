<?php
/**
 * Integration tests for Admin Settings.
 *
 * @package PaySentinel
 */

/**
 * Class AdminSettingsIntegrationTest
 */
class AdminSettingsIntegrationTest extends WP_UnitTestCase {


	/**
	 * Settings handler instance.
	 *
	 * @var PaySentinel_Admin_Settings_Handler
	 */
	private $handler;

	/**
	 * Setup test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		$security      = new PaySentinel_Security();
		$license       = new PaySentinel_License();
		$this->handler = new PaySentinel_Admin_Settings_Handler( $security, $license );

		// Clean up options before each test
		delete_option( 'paysentinel_options' );
	}

	/**
	 * Test settings registration.
	 */
	public function test_settings_registration() {
		global $wp_settings_sections, $wp_settings_fields;

		$this->handler->register_settings();

		// Check if the setting is registered
		$registered_settings = get_registered_settings();
		$this->assertArrayHasKey( 'paysentinel_options', $registered_settings );

		// Check sections
		$this->assertArrayHasKey( 'paysentinel_settings', $wp_settings_sections );
		$sections = $wp_settings_sections['paysentinel_settings'];
		$this->assertArrayHasKey( 'paysentinel_general', $sections );
		$this->assertArrayHasKey( 'paysentinel_gateways', $sections );
		$this->assertArrayHasKey( 'paysentinel_advanced', $sections );

		// Check some fields
		$this->assertArrayHasKey( 'paysentinel_settings', $wp_settings_fields );
		$fields = $wp_settings_fields['paysentinel_settings'];

		// General fields
		$this->assertArrayHasKey( 'paysentinel_general', $fields );
		$this->assertArrayHasKey( 'enable_monitoring', $fields['paysentinel_general'] );
		$this->assertArrayHasKey( 'health_check_interval', $fields['paysentinel_general'] );

		// Advanced fields
		$this->assertArrayHasKey( 'paysentinel_advanced', $fields );
		$this->assertArrayHasKey( 'enable_test_mode', $fields['paysentinel_advanced'] );
	}

	/**
	 * Test settings validation/sanitization in PaySentinel_Security.
	 */
	public function test_settings_validation_and_merging() {
		$old_settings = array(
			'enable_monitoring' => 1,
			'alert_threshold'   => 85,
		);
		update_option( 'paysentinel_options', $old_settings );

		$new_settings = array(
			'alert_threshold' => 90,
			'unknown_key'     => 'value',
		);

		$validated = PaySentinel_Security::validate_admin_settings( $new_settings );

		// alert_threshold should be updated and cast to int
		$this->assertEquals( 90, $validated['alert_threshold'] );

		// enable_monitoring should be preserved from old_settings
		$this->assertEquals( 1, $validated['enable_monitoring'] );

		// unknown_key should be present and sanitized
		$this->assertEquals( 'value', $validated['unknown_key'] );
	}

	/**
	 * Test checkbox handling for general tab.
	 */
	public function test_checkbox_handling_general_tab() {
		update_option(
			'paysentinel_options',
			array(
				'enable_monitoring' => 1,
				'retry_enabled'     => 1,
			)
		);

		// Simulate saving the general tab with checkboxes UNCHECKED (missing from POST)
		$input = array(
			'current_tab'     => 'general',
			'alert_threshold' => 85,
		);

		$validated = PaySentinel_Security::validate_admin_settings( $input );

		$this->assertEquals( 0, $validated['enable_monitoring'] );
		$this->assertEquals( 0, $validated['retry_enabled'] );
	}

	/**
	 * Test SQL injection rejection.
	 */
	public function test_settings_rejects_sql_keys() {
		$input = array(
			'SELECT * FROM wp_users' => 'malicious',
			'normal_key'             => 'good',
		);

		$validated = PaySentinel_Security::validate_admin_settings( $input );

		$this->assertArrayNotHasKey( 'SELECT * FROM wp_users', $validated );
		$this->assertArrayHasKey( 'normal_key', $validated );
	}

	/**
	 * Test recursive sanitization.
	 */
	public function test_recursive_sanitization() {
		$input = array(
			'gateway_alert_config' => array(
				'stripe' => array(
					'threshold' => '90',
					'channels'  => array( 'email', 'api <script>' ),
				),
			),
		);

		$validated = PaySentinel_Security::validate_admin_settings( $input );

		$this->assertIsArray( $validated['gateway_alert_config'] );
		$this->assertIsArray( $validated['gateway_alert_config']['stripe'] );
		$this->assertEquals( 'api', trim( $validated['gateway_alert_config']['stripe']['channels'][1] ) );
	}

	/**
	 * Test field rendering (partial test to ensure methods exist and output something).
	 */
	public function test_field_rendering_methods() {
		ob_start();
		$this->handler->render_field_enable_monitoring();
		$output = ob_get_clean();
		$this->assertStringContainsString( 'name="paysentinel_options[enable_monitoring]"', $output );

		ob_start();
		$this->handler->render_field_health_check_interval();
		$output = ob_get_clean();
		$this->assertStringContainsString( 'name="paysentinel_options[health_check_interval]"', $output );

		ob_start();
		$this->handler->render_field_alert_threshold();
		$output = ob_get_clean();
		$this->assertStringContainsString( 'id="alert-threshold-slider"', $output );

		// Sections
		ob_start();
		$this->handler->render_general_section();
		$this->handler->render_gateways_section();
		$this->handler->render_advanced_section();
		$output = ob_get_clean();
		$this->assertStringContainsString( 'Core monitoring', $output );

		// More fields
		ob_start();
		$this->handler->render_field_retry_enabled();
		$this->handler->render_field_max_retry_attempts();
		$this->handler->render_field_enable_test_mode();
		$output = ob_get_clean();
		$this->assertStringContainsString( 'retry_enabled', $output );
		$this->assertStringContainsString( 'max_retry_attempts', $output );

		// License & Plan fields
		ob_start();
		$this->handler->render_field_license_key();
		$this->handler->render_license_section();
		$output = ob_get_clean();
		$this->assertStringContainsString( 'Subscription Plan', $output );
		$this->assertStringContainsString( 'License Management', $output );

		// Config fields
		ob_start();
		$this->handler->render_field_gateway_alert_config();
		$this->handler->render_field_test_failure_rate();
		$output = ob_get_clean();
		$this->assertStringContainsString( 'gateway_alert_config', $output );

		// ensure the paper-plane icon, if present, uses vertical-align instead of
		// a margin hack. the icon only appears for unlocked tiers so we only
		// check the styles when it's actually in the output.
		if ( false !== strpos( $output, 'dashicons-paper-plane' ) ) {
			$this->assertStringContainsString( 'vertical-align: middle', $output );
			$this->assertStringNotContainsString( 'margin-top: 5px', $output );
		}
	}

	/**
	 * Test rendering with different license tiers.
	 */
	public function test_tiered_rendering() {
		// 1. Mock Pro Tier
		update_option( PaySentinel_License::OPTION_LICENSE_STATUS, 'valid' );
		update_option( PaySentinel_License::OPTION_LICENSE_DATA, array( 'plan' => 'pro' ) );

		ob_start();
		$this->handler->render_field_gateway_alert_config();
		$output = ob_get_clean();

		// Should NOT see lock icon or "Pro Feature" message in Pro tier (it's available)
		$this->assertStringNotContainsString( 'dashicons-lock', $output );
		$this->assertStringNotContainsString( 'Pro Feature', $output );

		// 2. Mock Free Tier
		update_option( PaySentinel_License::OPTION_LICENSE_STATUS, 'invalid' );

		ob_start();
		$this->handler->render_field_gateway_alert_config();
		$output = ob_get_clean();

		// Should see lock icon and Pro Feature message
		$this->assertStringContainsString( 'dashicons-lock', $output );
		$this->assertStringContainsString( 'Pro Feature', $output );
	}

	/**
	 * Test license section rendering with valid and registered site.
	 */
	public function test_license_section_registered() {
		update_option( PaySentinel_License::OPTION_LICENSE_STATUS, 'valid' );
		update_option(
			PaySentinel_License::OPTION_LICENSE_DATA,
			array(
				'plan'          => 'agency',
				'expiration_ts' => date( 'Y-m-d H:i:s', time() + YEAR_IN_SECONDS ),
			)
		);
		update_option( PaySentinel_License::OPTION_SITE_REGISTERED, true );

		ob_start();
		$this->handler->render_license_section();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Active protection enabled', $output );
		$this->assertStringContainsString( 'Verified &amp; Registered', $output );
		$this->assertStringContainsString( 'Renews on', $output );
	}

	/**
	 * Test fallback render_settings_section.
	 */
	public function test_render_settings_section_fallback() {
		ob_start();
		$this->handler->render_settings_section();
		$output = ob_get_clean();
		$this->assertStringContainsString( 'Configure PaySentinel settings', $output );
	}
}
