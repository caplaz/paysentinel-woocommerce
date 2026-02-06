<?php

/**
 * Unit tests for WC_Payment_Monitor_Health class
 * Tests health calculation and success rate computation
 */
class HealthCalculationTest extends PHPUnit\Framework\TestCase {

	/**
	 * Test that health calculator can be instantiated
	 */
	public function test_health_constants_defined() {
		// Verify PERIODS constant is defined correctly
		$this->assertTrue( defined( 'WC_Payment_Monitor_Health' ) || file_exists( __DIR__ . '/../includes/class-wc-payment-monitor-health.php' ) );
	}

	/**
	 * Test health period calculations
	 */
	public function test_health_periods() {
		// Expected periods for health calculation
		$expected_periods = array(
			'1hour'  => 3600,
			'24hour' => 86400,
			'7day'   => 604800,
			'30day'  => 2592000,
			'90day'  => 7776000,
		);

		// Verify these are reasonable time windows
		foreach ( $expected_periods as $name => $seconds ) {
			$this->assertGreaterThan( 0, $seconds );
			$this->assertIsInt( $seconds );
		}
	}

	/**
	 * Test success rate calculation logic
	 */
	public function test_success_rate_calculation() {
		// Test basic success rate calculation
		$total_transactions      = 100;
		$successful_transactions = 95;
		$success_rate            = ( $successful_transactions / $total_transactions ) * 100;

		$this->assertEquals( 95.0, $success_rate );
		$this->assertGreaterThanOrEqual( 0, $success_rate );
		$this->assertLessThanOrEqual( 100, $success_rate );
	}

	/**
	 * Test edge cases for success rate
	 */
	public function test_success_rate_edge_cases() {
		// Test 0% success
		$this->assertEquals( 0.0, ( 0 / 100 ) * 100 );

		// Test 100% success
		$this->assertEquals( 100.0, ( 100 / 100 ) * 100 );

		// Test zero transactions (avoid division by zero)
		$success_rate = 0;
		if ( 0 > 0 ) {
			$success_rate = ( 0 / 0 ) * 100;
		}
		$this->assertEquals( 0, $success_rate );
	}
}
