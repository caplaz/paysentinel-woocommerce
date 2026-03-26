<?php
/**
 * Integration tests for the Failure Simulator.
 *
 * @package PaySentinel
 */

/**
 * Class FailureSimulatorTest
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

		// Clean up any test orders.
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
	 * Helper: Get orders with simulated failures.
	 * Queries the transactions table using the [SIMULATED FAILURE] marker,
	 * which is the authoritative source regardless of HPOS configuration.
	 */
	private function get_simulated_orders() {
		global $wpdb;

		$table_name = $this->database->get_transactions_table();
		$sql        = 'SELECT DISTINCT order_id FROM ' . $table_name . ' WHERE failure_reason LIKE %s AND order_id > 0'; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return $wpdb->get_col( $wpdb->prepare( $sql, '[SIMULATED FAILURE]%' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * Helper: Clean up test orders created by simulator.
	 * Queries the transactions table using the [SIMULATED FAILURE] marker,
	 * which is the authoritative source regardless of HPOS configuration.
	 */
	private function cleanup_test_orders() {
		global $wpdb;

		// Get all orders with simulated failures from the transactions table.
		$table_name = $this->database->get_transactions_table();
		$sql        = 'SELECT DISTINCT order_id FROM ' . $table_name . ' WHERE failure_reason LIKE %s AND order_id > 0'; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$order_ids  = $wpdb->get_col( $wpdb->prepare( $sql, '[SIMULATED FAILURE]%' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		foreach ( $order_ids as $order_id ) {
			$order = wc_get_order( $order_id );
			if ( $order ) {
				$order->delete( true );
			}
		}

		// Also remove transaction records so they don't bleed into subsequent tests.
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"DELETE FROM {$table_name} WHERE failure_reason LIKE %s",
				'[SIMULATED FAILURE]%'
			)
		);
	}

	/**
	 * Test that simulate_failure_for_order properly adds metadata
	 */
	public function test_simulate_failure_for_order_adds_metadata() {
		// Create a test order.
		$order    = wc_create_order();
		$order_id = $order->get_id();

		// Simulate a failure.
		$result = $this->simulator->simulate_failure_for_order( $order_id, 'card_declined' );

		// Verify success.
		$this->assertTrue( $result['success'] );
		$this->assertStringContainsString( 'Successfully simulated', $result['message'] );

		// Reload order to verify metadata.
		$order = wc_get_order( $order_id );

		// Verify metadata exists.
		$this->assertTrue( (bool) $order->get_meta( '_paysentinel_simulated_failure' ) );
		$this->assertEquals( 'card_declined', $order->get_meta( '_paysentinel_failure_scenario' ) );
		$this->assertNotEmpty( $order->get_meta( '_paysentinel_failure_message' ) );
		$this->assertNotEmpty( $order->get_meta( '_paysentinel_failure_code' ) );

		// Verify order status is failed.
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
		// Create multiple test orders with simulated failures.
		$order_ids = array();
		for ( $i = 0; $i < 3; $i++ ) {
			$order       = wc_create_order();
			$order_id    = $order->get_id();
			$order_ids[] = $order_id;

			// Simulate a failure.
			$this->simulator->simulate_failure_for_order( $order_id, 'card_declined' );
		}

		// Verify orders exist.
		$check_orders = $this->get_simulated_orders();
		$this->assertCount( 3, $check_orders );

		// Clear simulated failures.
		$result = $this->simulator->clear_simulated_failures();

		// Verify result.
		$this->assertTrue( $result['success'] );
		$this->assertEquals( 3, $result['deleted_orders'] );
		$this->assertStringNotContainsString( 'Cleared 0', $result['message'] );

		// Verify orders are deleted.
		foreach ( $order_ids as $order_id ) {
			$order = wc_get_order( $order_id );
			$this->assertFalse( $order );
		}

		// Verify no orders remain with the metadata.
		$remaining_orders = $this->get_simulated_orders();
		$this->assertCount( 0, $remaining_orders );
	}

	/**
	 * Test clear_simulated_failures returns 0 when no simulated orders exist
	 */
	public function test_clear_simulated_failures_with_no_orders() {
		// Clear any existing simulated orders.
		$this->cleanup_test_orders();

		// Clear simulated failures.
		$result = $this->simulator->clear_simulated_failures();

		// Verify result.
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

		// Verify success.
		$this->assertTrue( $result['success'] );
		$this->assertArrayHasKey( 'order_id', $result );

		$order_id = $result['order_id'];
		$order    = wc_get_order( $order_id );

		// Verify order exists.
		$this->assertNotFalse( $order );

		// Verify metadata.
		$this->assertTrue( (bool) $order->get_meta( '_paysentinel_simulated_failure' ) );
		$this->assertEquals( 'insufficient_funds', $order->get_meta( '_paysentinel_failure_scenario' ) );

		// Verify order status.
		$this->assertEquals( 'failed', $order->get_status() );
	}

	/**
	 * Test get_simulation_stats returns correct counts
	 */
	public function test_get_simulation_stats() {
		global $wpdb; // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable

		// Create multiple orders with different failure scenarios.
		$scenarios = array( 'card_declined', 'expired_card', 'insufficient_funds' );

		foreach ( $scenarios as $scenario ) {
			for ( $i = 0; $i < 2; $i++ ) {
				$order = wc_create_order();
				$this->simulator->simulate_failure_for_order( $order->get_id(), $scenario );
			}
		}

		// Get statistics.
		$stats = $this->simulator->get_simulation_stats();

		// Verify total count.
		$this->assertEquals( 6, $stats['total_simulated'] );

		// Verify scenario breakdown.
		// Note: failure_code in database is uppercase with underscores removed.
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

		// Verify expected scenarios exist.
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
		// Create orders with simulated failures.
		$order1 = wc_create_order();
		$this->simulator->simulate_failure_for_order( $order1->get_id(), 'card_declined' );

		$order2 = wc_create_order();
		$this->simulator->simulate_failure_for_order( $order2->get_id(), 'expired_card' );
		$order2->set_status( 'pending' );
		$order2->save();

		// Verify we have 2 orders.
		$orders_before = $this->get_simulated_orders();
		$this->assertCount( 2, $orders_before );

		// Clear all.
		$result = $this->simulator->clear_simulated_failures();

		// Verify both were deleted.
		$this->assertEquals( 2, $result['deleted_orders'] );

		// Verify no orders remain.
		$orders_after = $this->get_simulated_orders();
		$this->assertCount( 0, $orders_after );
	}

	/**
	 * Test simulate_failure_for_order with Order object parameter
	 */
	public function test_simulate_failure_for_order_with_object() {
		$order = wc_create_order();

		// Pass order object instead of ID.
		$result = $this->simulator->simulate_failure_for_order( $order, 'network_error' );

		$this->assertTrue( $result['success'] );

		// Verify metadata.
		$order = wc_get_order( $order->get_id() );
		$this->assertEquals( 'network_error', $order->get_meta( '_paysentinel_failure_scenario' ) );
	}

	// -------------------------------------------------------------------------
	// Transaction table record tests (core regression prevention).
	// -------------------------------------------------------------------------

	/**
	 * Test that simulate_failure_for_order writes a record to the transactions table.
	 *
	 * REGRESSION TEST: Previously simulated failures were only stored in order metadata.
	 * When HPOS is enabled but wp_woocommerce_orders_meta does not exist, metadata writes
	 * fail silently and no record was findable. This test ensures the transactions table
	 * is always populated regardless of the metadata storage outcome.
	 */
	public function test_simulate_failure_writes_transaction_record() {
		global $wpdb;

		$order    = wc_create_order();
		$order_id = $order->get_id();

		$this->simulator->simulate_failure_for_order( $order_id, 'card_declined' );

		$table_name = $this->database->get_transactions_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM {$table_name} WHERE order_id = %d",
				$order_id
			)
		);

		// A record must exist.
		$this->assertNotNull( $row, 'simulate_failure_for_order must write a transaction record.' );

		// The failure_reason must carry the [SIMULATED FAILURE] marker.
		$this->assertStringStartsWith( '[SIMULATED FAILURE]', $row->failure_reason );

		// Status must be failed.
		$this->assertEquals( 'failed', $row->status );

		// failure_code must be set and match the uppercased scenario key.
		$this->assertEquals( 'CARDDECLINED', $row->failure_code );
	}

	/**
	 * Test that calling simulate_failure_for_order twice on the same order does not
	 * create duplicate transaction records (upsert / idempotency behaviour).
	 */
	public function test_simulate_failure_twice_does_not_duplicate_transaction() {
		global $wpdb;

		$order    = wc_create_order();
		$order_id = $order->get_id();

		$this->simulator->simulate_failure_for_order( $order_id, 'card_declined' );
		$this->simulator->simulate_failure_for_order( $order_id, 'expired_card' );

		$table_name = $this->database->get_transactions_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT COUNT(*) FROM {$table_name} WHERE order_id = %d",
				$order_id
			)
		);

		// Must be exactly 1 row — the second call updates, not inserts.
		$this->assertEquals( 1, $count, 'Calling simulate twice must not create duplicate transaction rows.' );

		// The record must reflect the latest scenario.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$reason = $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT failure_reason FROM {$table_name} WHERE order_id = %d",
				$order_id
			)
		);
		$this->assertStringStartsWith( '[SIMULATED FAILURE]', $reason );
	}

	/**
	 * Test that regular (non-simulated) failed orders are NOT included in
	 * get_simulated_orders() and are NOT touched by clear_simulated_failures().
	 */
	public function test_non_simulated_failures_are_not_affected() {
		global $wpdb;

		// Create a real (non-simulated) failed transaction record.
		$real_order    = wc_create_order();
		$real_order_id = $real_order->get_id();
		$table_name    = $this->database->get_transactions_table();

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$table_name,
			array(
				'order_id'       => $real_order_id,
				'gateway_id'     => 'stripe',
				'transaction_id' => null,
				'amount'         => 50.00,
				'currency'       => 'USD',
				'status'         => 'failed',
				'failure_reason' => 'Your card was declined.',
				'failure_code'   => 'DECLINED',
				'retry_count'    => 0,
				'customer_email' => 'customer@example.com',
				'customer_ip'    => '1.2.3.4',
				'created_at'     => current_time( 'mysql' ),
				'updated_at'     => null,
			),
			array( '%d', '%s', '%s', '%f', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s' )
		);

		// Create one simulated failure.
		$sim_order = wc_create_order();
		$this->simulator->simulate_failure_for_order( $sim_order->get_id(), 'fraud_detected' );

		// The real order must not appear in the simulated list.
		$simulated_ids = $this->get_simulated_orders();
		$this->assertNotContains( (string) $real_order_id, $simulated_ids );
		$this->assertContains( (string) $sim_order->get_id(), $simulated_ids );

		// clear_simulated_failures must NOT delete the real transaction record.
		$this->simulator->clear_simulated_failures();

		$real_row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT id FROM {$table_name} WHERE order_id = %d",
				$real_order_id
			)
		);
		$this->assertNotNull( $real_row, 'clear_simulated_failures must not delete non-simulated transaction records.' );

		// Clean up the real order.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( $table_name, array( 'order_id' => $real_order_id ), array( '%d' ) );
		$real_order->delete( true );
	}

	/**
	 * Test that clear_simulated_failures also removes the matching transaction records.
	 *
	 * REGRESSION TEST: After clearing, no [SIMULATED FAILURE] rows must remain in
	 * the transactions table – otherwise get_simulated_failure_order_ids() would
	 * find ghost records on a subsequent call.
	 */
	public function test_clear_simulated_failures_removes_transaction_records() {
		global $wpdb;

		$order = wc_create_order();
		$this->simulator->simulate_failure_for_order( $order->get_id(), 'gateway_timeout' );

		$table_name = $this->database->get_transactions_table();

		// Confirm the record exists before clearing.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$before = (int) $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT COUNT(*) FROM {$table_name} WHERE failure_reason LIKE %s",
				'[SIMULATED FAILURE]%'
			)
		);
		$this->assertGreaterThan( 0, $before );

		$this->simulator->clear_simulated_failures();

		// Confirm the record is gone after clearing.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$after = (int) $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT COUNT(*) FROM {$table_name} WHERE failure_reason LIKE %s",
				'[SIMULATED FAILURE]%'
			)
		);
		$this->assertEquals( 0, $after, 'Transaction records must be deleted by clear_simulated_failures.' );

		// The deleted_transactions count in the result must match.
		$result = $this->simulator->clear_simulated_failures();
		$this->assertEquals( 0, $result['deleted_transactions'] );
	}

	/**
	 * Test simulate_failure_for_order returns failure for an invalid order ID.
	 */
	public function test_simulate_failure_with_invalid_order_id_returns_failure() {
		$result = $this->simulator->simulate_failure_for_order( 999999999, 'card_declined' );

		$this->assertFalse( $result['success'] );
		$this->assertNotEmpty( $result['message'] );
	}

	/**
	 * Test simulate_failure_for_order returns failure for an invalid scenario key.
	 */
	public function test_simulate_failure_with_invalid_scenario_returns_failure() {
		$order  = wc_create_order();
		$result = $this->simulator->simulate_failure_for_order( $order->get_id(), 'nonexistent_scenario_xyz' );

		$this->assertFalse( $result['success'] );
		$this->assertNotEmpty( $result['message'] );

		// No transaction record should have been written.
		global $wpdb;
		$table_name = $this->database->get_transactions_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT COUNT(*) FROM {$table_name} WHERE order_id = %d",
				$order->get_id()
			)
		);
		$this->assertEquals( 0, $count );

		$order->delete( true );
	}

	/**
	 * Test that every defined failure scenario can be simulated and produces a
	 * transaction record with the correct [SIMULATED FAILURE] marker.
	 */
	public function test_all_defined_scenarios_can_be_simulated() {
		global $wpdb;

		$table_name = $this->database->get_transactions_table();
		$scenarios  = array_keys( $this->simulator->get_all_scenarios() );

		foreach ( $scenarios as $scenario_key ) {
			$order    = wc_create_order();
			$order_id = $order->get_id();

			$result = $this->simulator->simulate_failure_for_order( $order_id, $scenario_key );

			$this->assertTrue(
				$result['success'],
				"simulate_failure_for_order must succeed for scenario '{$scenario_key}'."
			);

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$reason = $wpdb->get_var(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"SELECT failure_reason FROM {$table_name} WHERE order_id = %d",
					$order_id
				)
			);

			$this->assertStringStartsWith(
				'[SIMULATED FAILURE]',
				(string) $reason,
				"Transaction record must carry [SIMULATED FAILURE] marker for scenario '{$scenario_key}'."
			);

			// Clean up immediately so counter doesn't bleed.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->delete( $table_name, array( 'order_id' => $order_id ), array( '%d' ) );
			$order->delete( true );
		}
	}

	/**
	 * Test generate_bulk_failures creates the requested number of orders, each
	 * backed by a transaction record in the transactions table.
	 */
	public function test_generate_bulk_failures_creates_transaction_records() {
		global $wpdb;

		$count   = 3;
		$results = $this->simulator->generate_bulk_failures( $count, 'stripe', array( 'card_declined', 'expired_card' ) );

		$this->assertEquals( $count, $results['success'] );
		$this->assertCount( $count, $results['order_ids'] );

		$table_name = $this->database->get_transactions_table();

		foreach ( $results['order_ids'] as $order_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$reason = $wpdb->get_var(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"SELECT failure_reason FROM {$table_name} WHERE order_id = %d",
					$order_id
				)
			);

			$this->assertStringStartsWith(
				'[SIMULATED FAILURE]',
				(string) $reason,
				"Bulk-generated order #{$order_id} must have a [SIMULATED FAILURE] transaction record."
			);
		}
	}

	/**
	 * Test create_test_order_with_failure writes a transaction record in addition
	 * to setting order metadata.
	 */
	public function test_create_test_order_with_failure_writes_transaction_record() {
		global $wpdb;

		$result = $this->simulator->create_test_order_with_failure( 'processing_error', 'stripe' );

		$this->assertTrue( $result['success'] );

		$order_id   = $result['order_id'];
		$table_name = $this->database->get_transactions_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM {$table_name} WHERE order_id = %d",
				$order_id
			)
		);

		$this->assertNotNull( $row, 'create_test_order_with_failure must produce a transaction record.' );
		$this->assertStringStartsWith( '[SIMULATED FAILURE]', $row->failure_reason );
		$this->assertEquals( 'failed', $row->status );
		$this->assertNotEmpty( $row->gateway_id );
		$this->assertGreaterThan( 0, (float) $row->amount );
	}
}
