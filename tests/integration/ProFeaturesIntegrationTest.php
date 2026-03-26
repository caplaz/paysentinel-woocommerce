<?php
/**
 * Integration tests for PRO plan features.
 *
 * @package PaySentinel
 */

/**
 * Class ProFeaturesIntegrationTest
 */
class ProFeaturesIntegrationTest extends WP_UnitTestCase {

	/**
	 * Test 30-day analytics are gated to PRO tier
	 */
	public function test_30day_analytics_requires_pro_tier() {
		// Setup: Create a Free tier license.
		update_option( 'paysentinel_license_status', 'invalid' );
		delete_option( 'paysentinel_license_data' );

		$license = new PaySentinel_License();
		$this->assertEquals( 'free', $license->get_license_tier() );

		// Create health engine.
		$health = new PaySentinel_Health();

		// Verify that calculate_health skips 30day period for free tier.
		$gateway_id = 'test_gateway';
		update_option( 'paysentinel_settings', array( 'enabled_gateways' => array( $gateway_id ) ) );

		$health_data = $health->calculate_health( $gateway_id );

		// Free tier should NOT have 30day or 90day.
		$this->assertArrayNotHasKey( '30day', $health_data );
		$this->assertArrayNotHasKey( '90day', $health_data );

		// But should have the standard periods.
		$this->assertArrayHasKey( '1hour', $health_data );
		$this->assertArrayHasKey( '24hour', $health_data );
		$this->assertArrayHasKey( '7day', $health_data );
	}

	/**
	 * Test 90-day analytics are gated to PRO tier
	 */
	public function test_90day_analytics_requires_pro_tier() {
		// Setup: Create a PRO tier license.
		update_option( 'paysentinel_license_status', 'valid' );
		update_option( 'paysentinel_license_data', array( 'plan' => 'pro' ) );

		$license = new PaySentinel_License();
		$this->assertEquals( 'pro', $license->get_license_tier() );

		// Create health engine.
		$health = new PaySentinel_Health();

		// Verify that calculate_health includes 90day period for PRO tier.
		$gateway_id = 'test_gateway';
		update_option( 'paysentinel_settings', array( 'enabled_gateways' => array( $gateway_id ) ) );

		$health_data = $health->calculate_health( $gateway_id );

		// PRO tier SHOULD have 30day and 90day.
		$this->assertArrayHasKey( '30day', $health_data );
		$this->assertArrayHasKey( '90day', $health_data );

		// And still have the standard periods.
		$this->assertArrayHasKey( '1hour', $health_data );
		$this->assertArrayHasKey( '24hour', $health_data );
		$this->assertArrayHasKey( '7day', $health_data );
	}

	/**
	 * Test Agency tier also gets extended periods
	 */
	public function test_agency_tier_gets_extended_periods() {
		// Setup: Create an Agency tier license.
		update_option( 'paysentinel_license_status', 'valid' );
		update_option( 'paysentinel_license_data', array( 'plan' => 'agency' ) );

		$license = new PaySentinel_License();
		$this->assertEquals( 'agency', $license->get_license_tier() );

		// Create health engine.
		$health = new PaySentinel_Health();

		// Verify that calculate_health includes extended periods for Agency tier.
		$gateway_id = 'test_gateway';
		update_option( 'paysentinel_settings', array( 'enabled_gateways' => array( $gateway_id ) ) );

		$health_data = $health->calculate_health( $gateway_id );

		// Agency tier SHOULD have all periods.
		$this->assertArrayHasKey( '30day', $health_data );
		$this->assertArrayHasKey( '90day', $health_data );
	}

	/**
	 * Test Starter tier does NOT get extended periods
	 */
	public function test_starter_tier_no_extended_periods() {
		// Setup: Create a Starter tier license.
		update_option( 'paysentinel_license_status', 'valid' );
		update_option( 'paysentinel_license_data', array( 'plan' => 'starter' ) );

		$license = new PaySentinel_License();
		$this->assertEquals( 'starter', $license->get_license_tier() );

		// Create health engine.
		$health = new PaySentinel_Health();

		// Verify that calculate_health skips extended periods for Starter tier.
		$gateway_id = 'test_gateway';
		update_option( 'paysentinel_settings', array( 'enabled_gateways' => array( $gateway_id ) ) );

		$health_data = $health->calculate_health( $gateway_id );

		// Starter tier should NOT have 30day or 90day.
		$this->assertArrayNotHasKey( '30day', $health_data );
		$this->assertArrayNotHasKey( '90day', $health_data );
	}

	/**
	 * Test unlimited gateways for PRO tier
	 */
	public function test_pro_tier_unlimited_gateways() {
		// Setup: Create many gateways.
		$many_gateways = array();
		for ( $i = 1; $i <= 10; $i++ ) {
			$many_gateways[] = "gateway_$i";
		}

		update_option( 'paysentinel_settings', array( 'enabled_gateways' => $many_gateways ) );

		// Test PRO tier (999 gateway limit - effectively unlimited).
		update_option( 'paysentinel_license_status', 'valid' );
		update_option( 'paysentinel_license_data', array( 'plan' => 'pro' ) );

		$health = new PaySentinel_Health();

		// Use Reflection to access private method.
		$reflection = new ReflectionClass( $health );
		$method     = $reflection->getMethod( 'get_active_gateways' );
		$method->setAccessible( true );

		$active_gateways = $method->invoke( $health );

		// PRO tier should get all 10 gateways.
		$this->assertCount( 10, $active_gateways );
	}

	/**
	 * Test gateway limiting for Free tier
	 */
	public function test_free_tier_one_gateway_limit() {
		// Setup: Create many gateways.
		$many_gateways = array( 'gw1', 'gw2', 'gw3', 'gw4', 'gw5' );
		update_option( 'paysentinel_settings', array( 'enabled_gateways' => $many_gateways ) );

		// Test Free tier (1 gateway limit).
		update_option( 'paysentinel_license_status', 'invalid' );
		delete_option( 'paysentinel_license_data' );

		$health = new PaySentinel_Health();

		// Use Reflection to access private method.
		$reflection = new ReflectionClass( $health );
		$method     = $reflection->getMethod( 'get_active_gateways' );
		$method->setAccessible( true );

		$active_gateways = $method->invoke( $health );

		// Free tier should only get 1 gateway.
		$this->assertCount( 1, $active_gateways );
		$this->assertEquals( 'gw1', $active_gateways[0] );
	}

	/**
	 * Test gateway limiting for Starter tier
	 */
	public function test_starter_tier_three_gateway_limit() {
		// Setup: Create many gateways.
		$many_gateways = array( 'gw1', 'gw2', 'gw3', 'gw4', 'gw5' );
		update_option( 'paysentinel_settings', array( 'enabled_gateways' => $many_gateways ) );

		// Test Starter tier (3 gateway limit).
		update_option( 'paysentinel_license_status', 'valid' );
		update_option( 'paysentinel_license_data', array( 'plan' => 'starter' ) );

		$health = new PaySentinel_Health();

		// Use Reflection to access private method.
		$reflection = new ReflectionClass( $health );
		$method     = $reflection->getMethod( 'get_active_gateways' );
		$method->setAccessible( true );

		$active_gateways = $method->invoke( $health );

		// Starter tier should get 3 gateways.
		$this->assertCount( 3, $active_gateways );
		$this->assertEquals( array( 'gw1', 'gw2', 'gw3' ), $active_gateways );
	}

	/**
	 * Test data retention limits per tier
	 */
	public function test_data_retention_limits() {
		// Free tier - 7 days.
		$this->assertEquals( 7, PaySentinel_License::RETENTION_LIMITS['free'] );

		// Starter tier - 30 days.
		$this->assertEquals( 30, PaySentinel_License::RETENTION_LIMITS['starter'] );

		// PRO tier - 90 days.
		$this->assertEquals( 90, PaySentinel_License::RETENTION_LIMITS['pro'] );

		// Agency tier - 90 days.
		$this->assertEquals( 90, PaySentinel_License::RETENTION_LIMITS['agency'] );
	}

	/**
	 * Test that health data structure is correct for extended periods
	 */
	public function test_health_data_structure_for_extended_periods() {
		// Setup PRO tier.
		update_option( 'paysentinel_license_status', 'valid' );
		update_option( 'paysentinel_license_data', array( 'plan' => 'pro' ) );

		$health     = new PaySentinel_Health();
		$gateway_id = 'test_gateway';
		update_option( 'paysentinel_settings', array( 'enabled_gateways' => array( $gateway_id ) ) );

		$health_data = $health->calculate_health( $gateway_id );

		// Verify 30day data structure.
		if ( isset( $health_data['30day'] ) ) {
			$this->assertArrayHasKey( 'gateway_id', $health_data['30day'] );
			$this->assertArrayHasKey( 'period', $health_data['30day'] );
			$this->assertArrayHasKey( 'total_transactions', $health_data['30day'] );
			$this->assertArrayHasKey( 'successful_transactions', $health_data['30day'] );
			$this->assertArrayHasKey( 'failed_transactions', $health_data['30day'] );
			$this->assertArrayHasKey( 'success_rate', $health_data['30day'] );

			$this->assertEquals( '30day', $health_data['30day']['period'] );
		}

		// Verify 90day data structure.
		if ( isset( $health_data['90day'] ) ) {
			$this->assertArrayHasKey( 'gateway_id', $health_data['90day'] );
			$this->assertArrayHasKey( 'period', $health_data['90day'] );
			$this->assertEquals( '90day', $health_data['90day']['period'] );
		}
	}

	/**
	 * Test that database schema supports extended periods
	 */
	public function test_database_supports_extended_periods() {
		global $wpdb;

		$database = new PaySentinel_Database(); // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		$table    = $wpdb->prefix . 'payment_monitor_gateway_health';

		// Get the ENUM values for the period column.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$result = $wpdb->get_row( "SHOW COLUMNS FROM {$table} LIKE 'period'" );

		$this->assertNotNull( $result );
		$this->assertStringContainsString( '30day', $result->Type ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$this->assertStringContainsString( '90day', $result->Type ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	}

	/**
	 * Test license tier constants are correct
	 */
	public function test_license_tier_constants() {
		// Gateway limits.
		$this->assertEquals( 1, PaySentinel_License::GATEWAY_LIMITS['free'] );
		$this->assertEquals( 3, PaySentinel_License::GATEWAY_LIMITS['starter'] );
		$this->assertEquals( 999, PaySentinel_License::GATEWAY_LIMITS['pro'] );
		$this->assertEquals( 999, PaySentinel_License::GATEWAY_LIMITS['agency'] );

		// Retention limits.
		$this->assertEquals( 7, PaySentinel_License::RETENTION_LIMITS['free'] );
		$this->assertEquals( 30, PaySentinel_License::RETENTION_LIMITS['starter'] );
		$this->assertEquals( 90, PaySentinel_License::RETENTION_LIMITS['pro'] );
		$this->assertEquals( 90, PaySentinel_License::RETENTION_LIMITS['agency'] );
	}
}
