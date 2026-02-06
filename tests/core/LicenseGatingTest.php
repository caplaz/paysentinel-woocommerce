<?php

/**
 * Tests for license-based gating of local features
 */
class LicenseGatingTest extends WP_UnitTestCase {

	/**
	 * Test gateway limits based on license tier
	 */
	public function test_gateway_limits() {
		$license = new WC_Payment_Monitor_License();

		// Test Free tier (limit 1)
		update_option( 'wc_payment_monitor_license_status', 'invalid' );
		$this->assertEquals( 'free', $license->get_license_tier() );
		$this->assertEquals( 1, WC_Payment_Monitor_License::GATEWAY_LIMITS['free'] );

		// Test Starter tier (limit 3)
		update_option( 'wc_payment_monitor_license_status', 'valid' );
		update_option( 'wc_payment_monitor_license_data', array( 'plan' => 'starter' ) );
		$this->assertEquals( 'starter', $license->get_license_tier() );
		$this->assertEquals( 3, WC_Payment_Monitor_License::GATEWAY_LIMITS['starter'] );

		// Test Pro tier (unlimited)
		update_option( 'wc_payment_monitor_license_data', array( 'plan' => 'pro' ) );
		$this->assertEquals( 'pro', $license->get_license_tier() );
		$this->assertEquals( 999, WC_Payment_Monitor_License::GATEWAY_LIMITS['pro'] );
	}

	/**
	 * Test health period gating
	 */
	public function test_health_period_gating() {
		$health = new WC_Payment_Monitor_Health();
		$gateway_id = 'stripe';

		// Mock active gateways to return at least our test gateway
		update_option( 'wc_payment_monitor_settings', array( 'enabled_gateways' => array( $gateway_id ) ) );

		// Free tier - should NOT have 30day or 90day
		update_option( 'wc_payment_monitor_license_status', 'invalid' );
		$health_data_free = $health->calculate_health( $gateway_id );
		$this->assertArrayHasKey( '1hour', $health_data_free );
		$this->assertArrayHasKey( '7day', $health_data_free );
		$this->assertArrayNotHasKey( '30day', $health_data_free );
		$this->assertArrayNotHasKey( '90day', $health_data_free );

		// Pro tier - should HAVE 30day and 90day
		update_option( 'wc_payment_monitor_license_status', 'valid' );
		update_option( 'wc_payment_monitor_license_data', array( 'plan' => 'pro' ) );
		$health_data_pro = $health->calculate_health( $gateway_id );
		$this->assertArrayHasKey( '1hour', $health_data_pro );
		$this->assertArrayHasKey( '7day', $health_data_pro );
		$this->assertArrayHasKey( '30day', $health_data_pro );
		$this->assertArrayHasKey( '90day', $health_data_pro );
	}

	/**
	 * Test retention limits gating
	 */
	public function test_retention_limits_gating() {
		update_option( 'wc_payment_monitor_license_status', 'invalid' );
		$this->assertEquals( 7, WC_Payment_Monitor_License::RETENTION_LIMITS['free'] );

		update_option( 'wc_payment_monitor_license_status', 'valid' );
		update_option( 'wc_payment_monitor_license_data', array( 'plan' => 'starter' ) );
		$this->assertEquals( 30, WC_Payment_Monitor_License::RETENTION_LIMITS['starter'] );

		update_option( 'wc_payment_monitor_license_data', array( 'plan' => 'pro' ) );
		$this->assertEquals( 90, WC_Payment_Monitor_License::RETENTION_LIMITS['pro'] );
	}

	/**
	 * Test gateway count enforcement in Health engine
	 */
	public function test_gateway_count_enforcement() {
		// Mock 5 enabled gateways
		$gateways = array( 'gw1', 'gw2', 'gw3', 'gw4', 'gw5' );
		update_option( 'wc_payment_monitor_settings', array( 'enabled_gateways' => $gateways ) );

		$health = new WC_Payment_Monitor_Health();
		
		// Use Reflection to access private method get_active_gateways
		$reflection = new ReflectionClass( $health );
		$method = $reflection->getMethod( 'get_active_gateways' );
		$method->setAccessible( true );

		// Free tier - should limit to 1
		update_option( 'wc_payment_monitor_license_status', 'invalid' );
		$active_free = $method->invoke( $health );
		$this->assertCount( 1, $active_free );

		// Starter tier - should limit to 3
		update_option( 'wc_payment_monitor_license_status', 'valid' );
		update_option( 'wc_payment_monitor_license_data', array( 'plan' => 'starter' ) );
		$active_starter = $method->invoke( $health );
		$this->assertCount( 3, $active_starter );

		// Pro tier - should be unlimited (5)
		update_option( 'wc_payment_monitor_license_data', array( 'plan' => 'pro' ) );
		$active_pro = $method->invoke( $health );
		$this->assertCount( 5, $active_pro );
	}
}
