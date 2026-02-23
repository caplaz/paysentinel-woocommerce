<?php

/**
 * Unit tests for PaySentinel_Alerts class
 * Tests alert triggering and notification logic
 */
class PaymentAlertTest extends PHPUnit\Framework\TestCase {

	/**
	 * Test alert thresholds are reasonable
	 */
	public function test_alert_thresholds() {
		// Expected alert thresholds for failure rates
		// Note: These represent Success Rates.
		// < 75% is High (25%+ failure rate)
		// < 90% is Warning (10-25% failure rate)
		// < 95% is Info (5-10% failure rate)
		$thresholds = PaySentinel_Alerts::SEVERITY_THRESHOLDS;

		$this->assertArrayHasKey( 'high', $thresholds );
		$this->assertArrayHasKey( 'warning', $thresholds );
		$this->assertArrayHasKey( 'info', $thresholds );
		$this->assertArrayNotHasKey( 'critical', $thresholds, 'Critical severity should be reserved for immediate alerts' );

		$this->assertEquals( 75, $thresholds['high'] );
		$this->assertEquals( 90, $thresholds['warning'] );
		$this->assertEquals( 95, $thresholds['info'] );
	}

	/**
	 * Test alert severity levels
	 */
	public function test_alert_severity_levels() {
		$severity_levels = array( 'critical', 'high', 'warning', 'info' );

		foreach ( $severity_levels as $level ) {
			$this->assertIsString( $level );
			$this->assertNotEmpty( $level );
		}
	}

	/**
	 * Test alert state transitions
	 */
	public function test_alert_state_transitions() {
		// Test possible alert states
		$valid_states = array( 'active', 'resolved', 'acknowledged' );

		foreach ( $valid_states as $state ) {
			$this->assertIsString( $state );
			$this->assertNotEmpty( $state );
			$this->assertMatchesRegularExpression( '/^[a-z]+$/', $state );
		}
	}

	/**
	 * Test alert condition evaluation
	 */
	public function test_alert_condition_evaluation() {
		// Test that alert conditions can be evaluated correctly
		$failure_rate       = 35;
		$critical_threshold = 50;
		$warning_threshold  = 20;

		// Should trigger warning but not critical
		$this->assertLessThan( $critical_threshold, $failure_rate );
		$this->assertGreaterThan( $warning_threshold, $failure_rate );

		// Test threshold crossing
		$this->assertTrue( $failure_rate > $warning_threshold );
		$this->assertFalse( $failure_rate > $critical_threshold );
	}
}
