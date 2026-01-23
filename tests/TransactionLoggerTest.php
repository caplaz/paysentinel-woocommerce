<?php
/**
 * Real Integration Tests for Logger
 */
class TransactionLoggerTest extends WP_UnitTestCase {

	private $logger;
	private $order_id;

	public function setUp(): void {
		parent::setUp();
		
		if ( ! class_exists( 'WooCommerce' ) ) {
			$this->markTestSkipped( 'WooCommerce not active.' );
		}

		$this->logger = new WC_Payment_Monitor_Logger();
		
		// Create a dummy order
		$order = wc_create_order();
		$order->set_billing_email( 'logger_test@example.com' );
		$order->set_total( 50.00 );
		$order->save();
		$this->order_id = $order->get_id();
	}

	public function tearDown(): void {
		parent::tearDown();
		wp_delete_post( $this->order_id, true );
	}

	/**
	 * Test Log Failure fires Action
	 */
	public function test_log_failure_fires_action() {
		$fired = 0;
		add_action( 'wc_payment_monitor_payment_failed', function( $order_id ) use ( &$fired ) {
			$fired++;
		} );

		// Simulate failure
		$this->logger->log_failure( $this->order_id );

		$this->assertEquals( 1, $fired, 'The wc_payment_monitor_payment_failed action should fire exactly once.' );
	}

	/**
	 * Test Log Failure saves to Database
	 */
	public function test_log_failure_saves_db() {
		global $wpdb;
		$table_name = ( new WC_Payment_Monitor_Database() )->get_transactions_table();

		$this->logger->log_failure( $this->order_id );

		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table_name} WHERE order_id = %d", $this->order_id ) );

		$this->assertNotNull( $row, 'Transaction row should exist in DB.' );
		$this->assertEquals( 'failed', $row->status );
		$this->assertEquals( 50.00, $row->amount );
		$this->assertEquals( 'logger_test@example.com', $row->customer_email );
	}
}
