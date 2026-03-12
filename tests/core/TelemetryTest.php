<?php
/**
 * Telemetry Tests
 *
 * @package PaySentinel\Tests
 */

/**
 * Class TelemetryTest
 */
class TelemetryTest extends WP_UnitTestCase {

	/**
	 * Test order ID.
	 *
	 * @var int
	 */
	private $order_id;

	/**
	 * Intercepted request arguments.
	 *
	 * @var array|null
	 */
	private $request_args;

	/**
	 * Intercepted request URL.
	 *
	 * @var string|null
	 */
	private $request_url;

	/**
	 * Setup environment before each test.
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

		// Create a dummy order.
		$order = wc_create_order();
		$order->set_total( 123.45 );
		$order->set_currency( 'USD' );
		$order->set_payment_method( 'stripe' );
		$order->set_transaction_id( 'txn_123456' );
		$order->save();
		$this->order_id = $order->get_id();

		// Set license as active.
		update_option( 'paysentinel_license_key', 'test_key' );
		update_option( 'paysentinel_site_secret', 'test_secret' );
		update_option( 'paysentinel_license_status', 'valid' );

		$this->request_args = null;
		$this->request_url  = null;

		add_filter( 'pre_http_request', array( $this, 'intercept_request' ), 10, 3 );
	}

	/**
	 * Tear down environment after each test.
	 */
	public function tearDown(): void {
		parent::tearDown();

		remove_filter( 'pre_http_request', array( $this, 'intercept_request' ), 10 );

		if ( $this->order_id ) {
			wp_delete_post( $this->order_id, true );
		}

		delete_option( 'paysentinel_license_key' );
		delete_option( 'paysentinel_site_secret' );
		delete_option( 'paysentinel_license_status' );
	}

	/**
	 * Intercept wp_remote_request to check telemetry data.
	 *
	 * @param false|array|WP_Error $preempt     Whether to preempt an HTTP request return value.
	 * @param array                $parsed_args HTTP request arguments.
	 * @param string               $url         The request URL.
	 * @return array
	 */
	public function intercept_request( $preempt, $parsed_args, $url ) {
		if ( strpos( $url, PaySentinel_License::API_ENDPOINT_TELEMETRY ) !== false ) {
			$this->request_args = $parsed_args;
			$this->request_url  = $url;
			return array(
				'headers'  => array(),
				'body'     => wp_json_encode( array( 'success' => true ) ),
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
			);
		}
		return $preempt;
	}

	/**
	 * Test that successful payments generate correctly shaped telemetry payload.
	 */
	public function test_success_telemetry_payload() {
		$telemetry = new PaySentinel_Telemetry();

		$telemetry->send_success_telemetry( $this->order_id );

		$this->assertNotNull( $this->request_args, 'Telemetry request should have been fired.' );

		// The non-blocking parameter translates to timeout=5 and blocking=false in the customized WP Http args.
		$this->assertFalse( $this->request_args['blocking'], 'Request should be non-blocking.' );

		$body = json_decode( $this->request_args['body'], true );
		$this->assertEquals( 'test_key', $body['license_key'] );
		$this->assertTrue( $body['success'] );
		$this->assertEquals( 123.45, $body['amount'] );
		$this->assertEquals( 'USD', $body['currency'] );
		$this->assertEquals( 'stripe', $body['gateway'] );
		$this->assertEquals( 'txn_123456', $body['transaction_id'] );
		$this->assertArrayNotHasKey( 'error_code', $body );
	}

	/**
	 * Test that failed payments generate correctly shaped telemetry payload.
	 */
	public function test_failure_telemetry_payload() {
		$telemetry = new PaySentinel_Telemetry();

		// Simulate failure with custom metadata so logger extracts error code.
		$order = wc_get_order( $this->order_id );
		$order->add_meta_data( '_paysentinel_simulated_failure', 'yes' );
		$order->add_meta_data( '_paysentinel_failure_code', 'card_declined' );
		$order->add_meta_data( '_paysentinel_failure_message', 'The card was declined.' );
		$order->save();

		$telemetry->send_failure_telemetry( $this->order_id );

		$this->assertNotNull( $this->request_args, 'Telemetry request should have been fired.' );

		$body = json_decode( $this->request_args['body'], true );
		$this->assertFalse( $body['success'] );
		$this->assertArrayHasKey( 'error_code', $body );
		$this->assertEquals( 'card_declined', $body['error_code'] );
	}

	/**
	 * Test that telemetry is not sent if license is inactive.
	 */
	public function test_no_telemetry_if_license_inactive() {
		update_option( 'paysentinel_license_status', 'invalid' );

		$telemetry = new PaySentinel_Telemetry();

		$telemetry->send_success_telemetry( $this->order_id );

		$this->assertNull( $this->request_args, 'Telemetry request should NOT be fired if license is inactive.' );
	}
}
