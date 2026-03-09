<?php

/**
 * Unit Tests for Recovery Alerts (Retry Outcome Alerts)
 *
 * Tests the PaySentinel_Alert_Recovery_Handler class which listens to retry
 * hooks and generates alerts for successful and failed recovery attempts.
 */
class RetryRecoveryAlertsTest extends WP_UnitTestCase {

	private $recovery_handler;
	private $alert_checker;
	private $logger;
	private $database;

	public function setUp(): void {
		parent::setUp();

		$this->database = new PaySentinel_Database();
		$this->database->create_tables();

		// Clean up alerts table
		global $wpdb;
		$alerts_table = $this->database->get_alerts_table();
		$wpdb->query( "TRUNCATE TABLE $alerts_table" );

		// Enable retry functionality
		update_option(
			'paysentinel_options',
			array(
				'retry_enabled'      => true,
				'max_retry_attempts' => 3,
				'alerts_enabled'     => true,
				'alert_threshold'    => 95.0,
			)
		);

		// Reset config singleton for fresh settings
		$reflection = new ReflectionClass( 'PaySentinel_Config' );
		$property   = $reflection->getProperty( 'instance' );
		$property->setAccessible( true );
		$property->setValue( null );

		// Initialize components
		$this->logger        = new PaySentinel_Logger();
		$alerts_instance     = new PaySentinel_Alerts();
		$this->alert_checker = $alerts_instance->get_checker();

		// Create recovery handler (it will register its hooks)
		$this->recovery_handler = new PaySentinel_Alert_Recovery_Handler( $this->alert_checker, $this->logger );
	}

	/**
	 * Test that successful recovery triggers a retry_outcome alert with info severity
	 */
	public function test_recovery_success_triggers_info_alert() {
		$order_id = $this->create_test_order();
		$order    = wc_get_order( $order_id );

		// Create mock transaction
		$transaction = $this->create_mock_transaction( 'stripe', $order_id );

		// Create mock retry result
		$retry_result = array(
			'transaction_id' => 'txn_success_123',
			'message'        => 'Retry successful',
		);

		// Call the success handler directly
		$this->recovery_handler->handle_recovery_success( $order, $transaction, $retry_result );

		// Verify alert was created
		global $wpdb;
		$alerts_table = $this->database->get_alerts_table();
		$alert        = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$alerts_table} WHERE alert_type = %s", 'retry_outcome' )
		);

		$this->assertNotNull( $alert );
		$this->assertEquals( 'retry_outcome', $alert->alert_type );
		$this->assertEquals( 'info', $alert->severity );
		$this->assertStringContainsString( 'recovery successful', strtolower( $alert->message ) );
	}

	/**
	 * Test that failed recovery triggers a retry_outcome alert with warning severity
	 */
	public function test_recovery_failure_triggers_warning_alert() {
		$order_id = $this->create_test_order();
		$order    = wc_get_order( $order_id );

		// Create mock transaction with failure reason
		$transaction = $this->create_mock_transaction( 'stripe', $order_id, 'Card declined' );

		// Create mock retry result with failure
		$retry_result = array(
			'message' => 'Insufficient funds',
		);

		// Call failure handler with retry_count = 2 (not max)
		$this->recovery_handler->handle_recovery_failure( $order, $transaction, $retry_result, 2 );

		// Verify alert was created
		global $wpdb;
		$alerts_table = $this->database->get_alerts_table();
		$alert        = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$alerts_table} WHERE alert_type = %s", 'retry_outcome' )
		);

		$this->assertNotNull( $alert );
		$this->assertEquals( 'retry_outcome', $alert->alert_type );
		$this->assertEquals( 'warning', $alert->severity );
		$this->assertStringContainsString( 'recovery failed', strtolower( $alert->message ) );
	}

	/**
	 * Test that failed recovery with max retries reached triggers high severity alert
	 */
	public function test_recovery_max_retries_triggers_high_alert() {
		$order_id = $this->create_test_order();
		$order    = wc_get_order( $order_id );

		// Create mock transaction
		$transaction = $this->create_mock_transaction( 'stripe', $order_id, 'Card declined' );

		// Create mock retry result
		$retry_result = array(
			'message' => 'Max retries exceeded',
		);

		// Call failure handler with retry_count = 3 (max)
		$max_retries = PaySentinel_Config::instance()->get_max_retry_attempts();
		$this->recovery_handler->handle_recovery_failure( $order, $transaction, $retry_result, $max_retries );

		// Verify alert was created with high severity
		global $wpdb;
		$alerts_table = $this->database->get_alerts_table();
		$alert        = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$alerts_table} WHERE alert_type = %s", 'retry_outcome' )
		);

		$this->assertNotNull( $alert );
		$this->assertEquals( 'high', $alert->severity );
		$this->assertStringContainsString( 'exhausted', strtolower( $alert->message ) );
		$this->assertStringContainsString( 'manual intervention', strtolower( $alert->message ) );
	}

	/**
	 * Test that alert metadata includes all necessary information
	 */
	public function test_alert_metadata_completeness() {
		$order_id = $this->create_test_order();
		$order    = wc_get_order( $order_id );

		$transaction = $this->create_mock_transaction( 'stripe', $order_id, 'Card declined' );

		$retry_result = array(
			'transaction_id' => 'txn_success_456',
			'message'        => 'Success!',
		);

		$this->recovery_handler->handle_recovery_success( $order, $transaction, $retry_result );

		// Retrieve alert and decode metadata
		global $wpdb;
		$alerts_table = $this->database->get_alerts_table();
		$alert        = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$alerts_table} WHERE alert_type = %s", 'retry_outcome' )
		);

		$this->assertNotNull( $alert );
		$metadata = json_decode( $alert->metadata, true );

		// Verify all required metadata fields
		$this->assertEquals( $order_id, $metadata['order_id'] );
		$this->assertEquals( 'success', $metadata['status'] );
		$this->assertEquals( 'stripe', $metadata['gateway_id'] );
		$this->assertEquals( 'Card declined', $metadata['original_failure_reason'] );
		$this->assertArrayHasKey( 'retry_attempt', $metadata );
		$this->assertArrayHasKey( 'total_retries', $metadata );
		$this->assertArrayHasKey( 'transaction_id', $metadata );
		$this->assertArrayHasKey( 'recovery_time', $metadata );
	}

	/**
	 * Test that rate limiting prevents duplicate alerts within 5 minutes
	 */
	public function test_rate_limiting_prevents_duplicate_alerts() {
		$order_id = $this->create_test_order();
		$order    = wc_get_order( $order_id );

		$transaction = $this->create_mock_transaction( 'stripe', $order_id );

		$retry_result = array(
			'transaction_id' => 'txn_123',
			'message'        => 'Success',
		);

		// First call - should create alert
		$this->recovery_handler->handle_recovery_success( $order, $transaction, $retry_result );

		// Verify alert was created
		global $wpdb;
		$alerts_table = $this->database->get_alerts_table();
		$count1       = $wpdb->get_var( "SELECT COUNT(*) FROM {$alerts_table}" );
		$this->assertEquals( 1, $count1 );

		// Second call within rate limit window - should NOT create alert
		$this->recovery_handler->handle_recovery_success( $order, $transaction, $retry_result );

		// Verify no new alert was created
		$count2 = $wpdb->get_var( "SELECT COUNT(*) FROM {$alerts_table}" );
		$this->assertEquals( 1, $count2, 'Rate limiting should prevent duplicate alerts' );
	}

	/**
	 * Test that different gateways are rate-limited separately
	 */
	public function test_rate_limiting_per_gateway() {
		$order_id1 = $this->create_test_order();
		$order1    = wc_get_order( $order_id1 );

		$order_id2 = $this->create_test_order();
		$order2    = wc_get_order( $order_id2 );

		$transaction_stripe = $this->create_mock_transaction( 'stripe', $order_id1 );
		$transaction_square = $this->create_mock_transaction( 'square', $order_id2 );

		$retry_result = array(
			'transaction_id' => 'txn_123',
			'message'        => 'Success',
		);

		// Create alert for stripe gateway
		$this->recovery_handler->handle_recovery_success( $order1, $transaction_stripe, $retry_result );

		// Create alert for square gateway - should succeed (different gateway)
		$this->recovery_handler->handle_recovery_success( $order2, $transaction_square, $retry_result );

		// Verify two alerts were created (one per gateway)
		global $wpdb;
		$alerts_table = $this->database->get_alerts_table();
		$count        = $wpdb->get_var( "SELECT COUNT(*) FROM {$alerts_table}" );
		$this->assertEquals( 2, $count, 'Different gateways should have separate rate limits' );
	}

	/**
	 * Test that hooks are properly registered
	 */
	public function test_hooks_registered() {
		// Check if the actions are registered
		$this->assertTrue(
			has_action( 'paysentinel_retry_successful' ),
			'paysentinel_retry_successful hook should be registered'
		);

		$this->assertTrue(
			has_action( 'paysentinel_retry_failed' ),
			'paysentinel_retry_failed hook should be registered'
		);
	}

	// ===== Helper Methods =====

	/**
	 * Create a test WooCommerce order
	 *
	 * @return int Order ID
	 */
	private function create_test_order() {
		$order = wc_create_order();
		$order->set_total( 100 );
		$order->set_currency( 'USD' );
		$order->set_payment_method( 'stripe' );
		$order->save();

		return $order->get_id();
	}

	/**
	 * Create a mock transaction object
	 *
	 * @param string $gateway_id Gateway ID.
	 * @param int    $order_id   Order ID.
	 * @param string $failure_reason Optional failure reason.
	 *
	 * @return object Mock transaction object
	 */
	private function create_mock_transaction( $gateway_id, $order_id, $failure_reason = 'Card was declined' ) {
		$transaction                 = new stdClass();
		$transaction->id             = 1;
		$transaction->order_id       = $order_id;
		$transaction->gateway_id     = $gateway_id;
		$transaction->transaction_id = 'txn_test_' . rand( 1000, 9999 );
		$transaction->status         = 'failed';
		$transaction->failure_reason = $failure_reason;
		$transaction->failure_code   = 'card_declined';
		$transaction->retry_count    = 1;
		$transaction->amount         = 100.00;
		$transaction->currency       = 'USD';
		$transaction->created_at     = current_time( 'mysql' );

		return $transaction;
	}
}
