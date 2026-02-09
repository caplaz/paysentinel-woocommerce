<?php

/**
 * Tests for PRO tier advanced analytics features
 */
class ProAnalyticsTest extends WP_UnitTestCase {

	private $analytics;
	private $license;
	private $database;

	/**
	 * Set up test environment
	 */
	public function setUp(): void {
		parent::setUp();
		$this->analytics = new WC_Payment_Monitor_Analytics_Pro();
		$this->license   = new WC_Payment_Monitor_License();
		$this->database  = new WC_Payment_Monitor_Database();
	}

	/**
	 * Test PRO analytics availability check
	 */
	public function test_pro_analytics_availability() {
		// Free tier - should NOT have access
		update_option( 'wc_payment_monitor_license_status', 'invalid' );
		$this->assertFalse( $this->analytics->is_pro_analytics_available() );

		// Starter tier - should NOT have access
		update_option( 'wc_payment_monitor_license_status', 'valid' );
		update_option( 'wc_payment_monitor_license_data', array( 'plan' => 'starter' ) );
		$this->assertFalse( $this->analytics->is_pro_analytics_available() );

		// Pro tier - should HAVE access
		update_option( 'wc_payment_monitor_license_data', array( 'plan' => 'pro' ) );
		$this->assertTrue( $this->analytics->is_pro_analytics_available() );

		// Agency tier - should HAVE access
		update_option( 'wc_payment_monitor_license_data', array( 'plan' => 'agency' ) );
		$this->assertTrue( $this->analytics->is_pro_analytics_available() );
	}

	/**
	 * Test comparative analytics for free tier (should fail)
	 */
	public function test_comparative_analytics_free_tier() {
		update_option( 'wc_payment_monitor_license_status', 'invalid' );
		$result = $this->analytics->get_comparative_analytics( 'stripe' );

		$this->assertArrayHasKey( 'error', $result );
		$this->assertEquals( 'pro_feature_required', $result['error'] );
	}

	/**
	 * Test comparative analytics for PRO tier (should succeed)
	 */
	public function test_comparative_analytics_pro_tier() {
		update_option( 'wc_payment_monitor_license_status', 'valid' );
		update_option( 'wc_payment_monitor_license_data', array( 'plan' => 'pro' ) );

		$gateway_id = 'stripe';
		$result     = $this->analytics->get_comparative_analytics( $gateway_id );

		$this->assertArrayNotHasKey( 'error', $result );
		$this->assertArrayHasKey( 'gateway_id', $result );
		$this->assertArrayHasKey( 'periods', $result );
		$this->assertArrayHasKey( 'trends', $result );
		$this->assertEquals( $gateway_id, $result['gateway_id'] );
	}

	/**
	 * Test failure pattern analysis gating
	 */
	public function test_failure_pattern_analysis_gating() {
		// Free tier - should fail
		update_option( 'wc_payment_monitor_license_status', 'invalid' );
		$result = $this->analytics->get_failure_pattern_analysis( 'stripe', 30 );
		$this->assertArrayHasKey( 'error', $result );
		$this->assertEquals( 'pro_feature_required', $result['error'] );

		// Pro tier - should succeed
		update_option( 'wc_payment_monitor_license_status', 'valid' );
		update_option( 'wc_payment_monitor_license_data', array( 'plan' => 'pro' ) );
		$result = $this->analytics->get_failure_pattern_analysis( 'stripe', 30 );
		$this->assertArrayNotHasKey( 'error', $result );
		$this->assertArrayHasKey( 'gateway_id', $result );
		$this->assertArrayHasKey( 'analysis_period', $result );
		$this->assertArrayHasKey( 'top_failure_reasons', $result );
	}

	/**
	 * Test extended history respects tier limits
	 */
	public function test_extended_history_respects_tier_limits() {
		// Free tier - should fail
		update_option( 'wc_payment_monitor_license_status', 'invalid' );
		$result = $this->analytics->get_extended_history( 'stripe', 90 );
		$this->assertArrayHasKey( 'error', $result );

		// Pro tier - should succeed with 90-day limit
		update_option( 'wc_payment_monitor_license_status', 'valid' );
		update_option( 'wc_payment_monitor_license_data', array( 'plan' => 'pro' ) );
		$result = $this->analytics->get_extended_history( 'stripe', 90 );
		$this->assertArrayNotHasKey( 'error', $result );
		$this->assertIsArray( $result );
	}

	/**
	 * Test gateway comparison feature
	 */
	public function test_gateway_comparison_pro_feature() {
		// Free tier - should fail
		update_option( 'wc_payment_monitor_license_status', 'invalid' );
		$result = $this->analytics->get_gateway_comparison();
		$this->assertArrayHasKey( 'error', $result );

		// Pro tier - should succeed
		update_option( 'wc_payment_monitor_license_status', 'valid' );
		update_option( 'wc_payment_monitor_license_data', array( 'plan' => 'pro' ) );
		$result = $this->analytics->get_gateway_comparison();
		$this->assertArrayNotHasKey( 'error', $result );
		$this->assertArrayHasKey( 'gateways', $result );
		$this->assertArrayHasKey( 'rankings', $result );
	}

	/**
	 * Test trend calculation logic
	 */
	public function test_trend_calculations() {
		update_option( 'wc_payment_monitor_license_status', 'valid' );
		update_option( 'wc_payment_monitor_license_data', array( 'plan' => 'pro' ) );

		// Use reflection to access private method
		$reflection = new ReflectionClass( $this->analytics );
		$method     = $reflection->getMethod( 'calculate_trends' );
		$method->setAccessible( true );

		$periods = array(
			'24hour' => array( 'success_rate' => 95.0 ),
			'7day'   => array( 'success_rate' => 90.0 ),
			'30day'  => array( 'success_rate' => 92.0 ),
			'90day'  => array( 'success_rate' => 93.0 ),
		);

		$trends = $method->invoke( $this->analytics, $periods );

		$this->assertArrayHasKey( '24h_vs_7d', $trends );
		$this->assertArrayHasKey( '7d_vs_30d', $trends );
		$this->assertArrayHasKey( '30d_vs_90d', $trends );

		// 24hour (95) vs 7day (90) = improving
		$this->assertEquals( 'improving', $trends['24h_vs_7d']['direction'] );
		$this->assertEquals( 5.0, $trends['24h_vs_7d']['success_rate_change'] );

		// 7day (90) vs 30day (92) = declining
		$this->assertEquals( 'declining', $trends['7d_vs_30d']['direction'] );
	}

	/**
	 * Test advanced metrics summary
	 */
	public function test_advanced_metrics_summary() {
		// Free tier - should fail
		update_option( 'wc_payment_monitor_license_status', 'invalid' );
		$result = $this->analytics->get_advanced_metrics_summary();
		$this->assertArrayHasKey( 'error', $result );

		// Pro tier - should succeed
		update_option( 'wc_payment_monitor_license_status', 'valid' );
		update_option( 'wc_payment_monitor_license_data', array( 'plan' => 'pro' ) );
		update_option( 'wc_payment_monitor_settings', array( 'enabled_gateways' => array( 'stripe', 'paypal' ) ) );

		$result = $this->analytics->get_advanced_metrics_summary();
		$this->assertArrayNotHasKey( 'error', $result );
		$this->assertArrayHasKey( 'total_gateways', $result );
		$this->assertArrayHasKey( 'gateway_metrics', $result );
	}

	/**
	 * Test that PRO users get all health periods
	 */
	public function test_pro_users_get_all_periods() {
		update_option( 'wc_payment_monitor_license_status', 'valid' );
		update_option( 'wc_payment_monitor_license_data', array( 'plan' => 'pro' ) );
		update_option( 'wc_payment_monitor_settings', array( 'enabled_gateways' => array( 'stripe' ) ) );

		$health      = new WC_Payment_Monitor_Health();
		$health_data = $health->calculate_health( 'stripe' );

		// PRO users should have access to all periods
		$this->assertArrayHasKey( '1hour', $health_data );
		$this->assertArrayHasKey( '24hour', $health_data );
		$this->assertArrayHasKey( '7day', $health_data );
		$this->assertArrayHasKey( '30day', $health_data );
		$this->assertArrayHasKey( '90day', $health_data );
	}

	/**
	 * Test data retention enforcement for PRO tier
	 */
	public function test_data_retention_enforcement() {
		// PRO tier should have 90-day retention
		update_option( 'wc_payment_monitor_license_status', 'valid' );
		update_option( 'wc_payment_monitor_license_data', array( 'plan' => 'pro' ) );

		$tier           = $this->license->get_license_tier();
		$retention_days = WC_Payment_Monitor_License::RETENTION_LIMITS[ $tier ];

		$this->assertEquals( 'pro', $tier );
		$this->assertEquals( 90, $retention_days );

		// Free tier should have 7-day retention
		update_option( 'wc_payment_monitor_license_status', 'invalid' );
		$tier_free           = $this->license->get_license_tier();
		$retention_days_free = WC_Payment_Monitor_License::RETENTION_LIMITS[ $tier_free ];

		$this->assertEquals( 'free', $tier_free );
		$this->assertEquals( 7, $retention_days_free );
	}

	/**
	 * Test unlimited gateway enforcement for PRO tier
	 */
	public function test_unlimited_gateways_for_pro() {
		// Set up 10 gateways
		$gateways = array( 'gw1', 'gw2', 'gw3', 'gw4', 'gw5', 'gw6', 'gw7', 'gw8', 'gw9', 'gw10' );
		update_option( 'wc_payment_monitor_settings', array( 'enabled_gateways' => $gateways ) );

		$health = new WC_Payment_Monitor_Health();

		// Use reflection to access private method
		$reflection = new ReflectionClass( $health );
		$method     = $reflection->getMethod( 'get_active_gateways' );
		$method->setAccessible( true );

		// Free tier - should limit to 1
		update_option( 'wc_payment_monitor_license_status', 'invalid' );
		$active_free = $method->invoke( $health );
		$this->assertCount( 1, $active_free );

		// PRO tier - should allow all (effectively unlimited)
		update_option( 'wc_payment_monitor_license_status', 'valid' );
		update_option( 'wc_payment_monitor_license_data', array( 'plan' => 'pro' ) );
		$active_pro = $method->invoke( $health );
		$this->assertCount( 10, $active_pro ); // All 10 gateways
	}
}
