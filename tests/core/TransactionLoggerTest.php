<?php
/**
 * Real integration tests for logger.
 *
 * @package PaySentinel
 */

/**
 * Class TransactionLoggerTest
 */
class TransactionLoggerTest extends WP_UnitTestCase {

	/**
	 * Logger instance.
	 *
	 * @var PaySentinel_Logger
	 */
	private $logger;
	/**
	 * Order ID.
	 *
	 * @var int
	 */
	private $order_id;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		if ( ! class_exists( 'WooCommerce' ) ) {
			if ( function_exists( 'WC' ) ) {
				WC();
			} else {
				$this->markTestSkipped( 'WooCommerce not active.' );
			}
		}

		$this->logger = new PaySentinel_Logger();

		// Create a dummy order.
		$order = wc_create_order();
		$order->set_billing_email( 'logger_test@example.com' );
		$order->set_total( 50.00 );
		$order->save();
		$this->order_id = $order->get_id();
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		parent::tearDown();
		wp_delete_post( $this->order_id, true );
	}

	/**
	 * Test Log Failure fires Action.
	 */
	public function test_log_failure_fires_action() {
		$fired = 0;
		add_action(
			'paysentinel_payment_failed',
			function ( $_order_id ) use ( &$fired ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found, VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
				$fired++;
			}
		);

		// Simulate failure.
		$this->logger->log_failure( $this->order_id );

		$this->assertEquals( 1, $fired, 'The paysentinel_payment_failed action should fire exactly once.' );
	}

	/**
	 * Test Log Failure saves to Database.
	 */
	public function test_log_failure_saves_db() {
		global $wpdb;
		$table_name = ( new PaySentinel_Database() )->get_transactions_table();

		$this->logger->log_failure( $this->order_id );

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM {$table_name} WHERE order_id = %d",
				$this->order_id
			)
		);

		$this->assertNotNull( $row, 'Transaction row should exist in DB.' );
		$this->assertEquals( 'failed', $row->status );
		$this->assertEquals( 50.00, $row->amount );
		$this->assertEquals( 'logger_test@example.com', $row->customer_email );
	}
}
