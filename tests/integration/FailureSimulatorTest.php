<?php

/**
 * Integration tests for the Failure Simulator
 *
 * Tests the failure simulator functionality including:
 * - Creating simulated failures
 * - Clearing simulated failures (regression test)
 * - Getting simulation statistics
 * - Meta data is properly set on orders
 *
 * @package PaySentinel
 */
class FailureSimulatorTest extends WP_UnitTestCase {

	/**
	 * Failure simulator instance.
	 *
	 * @var PaySentinel_Failure_Simulator
	 */
	private $simulator;

	/**
	 * Database instance.
	 *
	 * @var PaySentinel_Database
	 */
	private $database;

	/**
	 * Logger instance.
	 *
	 * @var PaySentinel_Logger
	 */
	private $logger;

	/**
	 * Setup test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->database  = new PaySentinel_Database();
		$this->logger    = new PaySentinel_Logger();
		$this->simulator = new PaySentinel_Failure_Simulator();

		// Clean up any test orders
		$this->cleanup_test_orders();
	}

	/**
	 * Cleanup after each test.
	 */
	public function tearDown(): void {
		$this->cleanup_test_orders();
		parent::tearDown();
	}

	/**
	 * Helper: Clean up test orders created by simulator
	 */
	private function cleanup_test_orders() {
		global $wpdb;

		// Get all orders with simulated failure metadata
		$order_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT post_id FROM {$wpdb->postmeta}
				WHERE meta_key = %s",
				'_paysentinel_simulated_failure'
			)
		);

		foreach ( $order_ids as $order_id ) {
			$order = wc_get_order( $order_id );
			if ( $order ) {
				$order->delete( true );
			}
		}
	}

	/**
	 * Test that simulate_failure_for_order properly adds metadata
	 */
	public function test_simulate_failure_for_order_adds_metadata() {
		// Create a test order
		$order = wc_create_order();
		$order_id = $order->get_id();

		// Simulate a failure
		$result = $this->simulator->simulate_failure_for_order( $order_id, 'card_declined' );

		// Verify success
		$this->assertTrue( $result['success'] );
		$this->assertStringContainsString( 'Successfully simulated', $result['message'] );

		// Reload order to verify metadata
		$order = wc_get_order( $order_id );

		// Verify metadata exists
		$this->assertTrue( (bool) $order->get_meta( '_paysentinel_simulated_failure' ) );
		$this->assertEquals( 'card_declined', $order->get_meta( '_paysentinel_failure_scenario' ) );
		$this->assertNotEmpty( $order->get_meta( '_paysentinel_failure_message' ) );
		$this->assertNotEmpty( $order->get_meta( '_paysentinel_failure_code' ) );

		// Verify order status is failed
		$this->assertEquals( 'failed', $order->get_status() );
	}

	/**
	 * Test clear_simulated_failures removes orders with simulated metadata
	 *
	 * REGRESSION TEST: This test prevents the bug where clear_simulated_failures
	 * returned "Cleared 0 simulated orders" because get_posts() was used instead
	 * of wc_get_orders().
	 */
	public function test_clear_simulated_failures_removes_orders() {
		global $wpdb;

		// Create multiple test orders with simulated failures
		$order_ids = array();
		for ( $i = 0; $i < 3; $i++ ) {
			$order = wc_create_order();
			$order_id = $order->get_id();
			$order_ids[] = $order_id;

			// Simulate a failure
			$this->simulator->simulate_failure_for_order( $order_id, 'card_declined' );
		}

		// Verify orders exist
		$check_orders = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT post_id FROM {$wpdb->postmeta}
				WHERE meta_key = %s",
				'_paysentinel_simulated_failure'
			)
		);
		$this->assertCount( 3, $check_orders );

		// Clear simulated failures
		$result = $this->simulator->clear_simulated_failures();

		// Verify result
		$this->assertTrue( $result['success'] );
		$this->assertEquals( 3, $result['deleted_orders'] );
		$this->assertStringNotContainsString( 'Cleared 0', $result['message'] );

		// Verify orders are deleted
		foreach ( $order_ids as $order_id ) {
			$order = wc_get_order( $order_id );
			$this->assertFalse( $order );
		}

		// Verify no orders remain with the metadata
		$remaining_orders = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT post_id FROM {$wpdb->postmeta}
				WHERE meta_key = %s",
				'_paysentinel_simulated_failure'
			)
		);
		$this->assertCount( 0, $remaining_orders );
	}

	/**
	 * Test clear_simulated_failures returns 0 when no simulated orders exist
	 */
	public function test_clear_simulated_failures_with_no_orders() {
		// Clear any existing simulated orders
		$this->cleanup_test_orders();

		// Clear simulated failures
		$result = $this->simulator->clear_simulated_failures();

		// Verify result
		$this->assertTrue( $result['success'] );
		$this->assertEquals( 0, $result['deleted_orders'] );
		$this->assertEquals( 0, $result['deleted_transactions'] );
		$this->assertStringContainsString( 'Cleared 0', $result['message'] );
	}

	/**
	 * Test create_test_order_with_failure creates orders with proper setup
	 */
	public function test_create_test_order_with_failure() {
		$result = $this->simulator->create_test_order_with_failure( 'insufficient_funds', 'stripe' );

		// Verify success
		$this->assertTrue( $result['success'] );
		$this->assertArrayHasKey( 'order_id', $result );

		$order_id = $result['order_id'];
		$order = wc_get_order( $order_id );

		// Verify order exists
		$this->assertNotFalse( $order );

		// Verify metadata
		$this->assertTrue( (bool) $order->get_meta( '_paysentinel_simulated_failure' ) );
		$this->assertEquals( 'insufficient_funds', $order->get_meta( '_paysentinel_failure_scenario' ) );

		// Verify order status
		$this->assertEquals( 'failed', $order->get_status() );
	}

	/**
	 * Test get_simulation_stats returns correct counts
	 */
	public function test_get_simulation_stats() {
		global $wpdb;

		// Create multiple orders with different failure scenarios
		$scenarios = array( 'card_declined', 'expired_card', 'insufficient_funds' );

		foreach ( $scenarios as $scenario ) {
			for ( $i = 0; $i < 2; $i++ ) {
				$order = wc_create_order();
				$this->simulator->simulate_failure_for_order( $order->get_id(), $scenario );

				// Manually log the transaction since hooks may not fire in test environment
				$table_name = $this->database->get_transactions_table();
				$failure_code = strtoupper( str_replace( '_', '', $scenario ) );
				$wpdb->insert(
					$table_name,
					array(
						'order_id'       => $order->get_id(),
						'gateway_id'     => 'stripe',
						'status'         => 'failed',
						'failure_reason' => '[SIMULATED FAILURE] ' . $scenario,
						'failure_code'   => $failure_code,
						'amount'         => 100.00,
						'currency'       => 'USD',
						'created_at'     => current_time( 'mysql' ),
					)
				);
			}
		}

		// Get statistics
		$stats = $this->simulator->get_simulation_stats();

		// Verify total count
		$this->assertEquals( 6, $stats['total_simulated'] );

		// Verify scenario breakdown
		// Note: failure_code in database is uppercase with underscores removed
		$expected_codes = array(
			'CARDDECLINED',
			'EXPIREDCARD',
			'INSUFFICIENTFUNDS',
		);
		
		foreach ( $expected_codes as $code ) {
			$this->assertArrayHasKey( $code, $stats['by_scenario'] );
			$this->assertEquals( 2, $stats['by_scenario'][ $code ] );
		}
	}

	/**
	 * Test get_all_scenarios returns expected scenarios
	 */
	public function test_get_all_scenarios() {
		$scenarios = $this->simulator->get_all_scenarios();

		// Verify expected scenarios exist
		$expected_scenarios = array(
			'card_declined',
			'insufficient_funds',
			'expired_card',
			'incorrect_cvc',
			'processing_error',
			'gateway_timeout',
			'network_error',
			'rate_limit_exceeded',
			'fraud_detected',
			'invalid_account',
			'gateway_misconfigured',
			'currency_not_supported',
		);

		foreach ( $expected_scenarios as $scenario ) {
			$this->assertArrayHasKey( $scenario, $scenarios );
			$this->assertArrayHasKey( 'name', $scenarios[ $scenario ] );
			$this->assertArrayHasKey( 'code', $scenarios[ $scenario ] );
			$this->assertArrayHasKey( 'message', $scenarios[ $scenario ] );
		}
	}

	/**
	 * Test clearing orders with different statuses
	 *
	 * Ensures that clear_simulated_failures works regardless of order status
	 */
	public function test_clear_simulated_failures_with_various_statuses() {
		global $wpdb;

		// Create orders with simulated failures
		$order1 = wc_create_order();
		$this->simulator->simulate_failure_for_order( $order1->get_id(), 'card_declined' );

		$order2 = wc_create_order();
		$this->simulator->simulate_failure_for_order( $order2->get_id(), 'expired_card' );
		$order2->set_status( 'pending' );
		$order2->save();

		// Verify we have 2 orders
		$orders_before = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT post_id FROM {$wpdb->postmeta}
				WHERE meta_key = %s",
				'_paysentinel_simulated_failure'
			)
		);
		$this->assertCount( 2, $orders_before );

		// Clear all
		$result = $this->simulator->clear_simulated_failures();

		// Verify both were deleted
		$this->assertEquals( 2, $result['deleted_orders'] );

		// Verify no orders remain
		$orders_after = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT post_id FROM {$wpdb->postmeta}
				WHERE meta_key = %s",
				'_paysentinel_simulated_failure'
			)
		);
		$this->assertCount( 0, $orders_after );
	}

	/**
	 * Test simulate_failure_for_order with Order object parameter
	 */
	public function test_simulate_failure_for_order_with_object() {
		$order = wc_create_order();

		// Pass order object instead of ID
		$result = $this->simulator->simulate_failure_for_order( $order, 'network_error' );

		$this->assertTrue( $result['success'] );

		// Verify metadata
		$order = wc_get_order( $order->get_id() );
		$this->assertEquals( 'network_error', $order->get_meta( '_paysentinel_failure_scenario' ) );
	}
}
