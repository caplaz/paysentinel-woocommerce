<?php
/**
 * Integration tests for Gateway Connectors.
 *
 * @package PaySentinel
 */

/**
 * Class GatewayConnectorsTest
 */
class GatewayConnectorsTest extends WP_UnitTestCase {


	/**
	 * Setup test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		// Reset options
		delete_option( 'woocommerce_stripe_settings' );
		delete_option( 'woocommerce_paypal_settings' );
		delete_option( 'woocommerce_square_settings' );
		delete_option( 'woocommerce_woocommerce_payments_settings' );

		$database = new PaySentinel_Database();
		$database->create_tables();
		$wpdb = $GLOBALS['wpdb'];
		$wpdb->query( 'TRUNCATE TABLE ' . $database->get_gateway_connectivity_table() );
	}

	/**
	 * Test Base Gateway Connector logging.
	 */
	public function test_base_connector_logging() {
		$connector = new PaySentinel_Stripe_Connector();

		$status = array(
			'status'        => 'online',
			'message'       => 'Connected',
			'http_code'     => 200,
			'response_time' => 123.45,
		);

		$result = $connector->log_connectivity_check( $status );
		$this->assertNotFalse( $result );

		$last = $connector->get_last_connectivity_check();
		$this->assertEquals( 'stripe', $last->gateway_id );
		$this->assertEquals( 'online', $last->status );
		$this->assertEquals( 123.45, (float) $last->response_time_ms );

		$history = $connector->get_connectivity_history();
		$this->assertCount( 1, $history );
	}

	/**
	 * Test Stripe Connector - Unconfigured.
	 */
	public function test_stripe_connector_unconfigured() {
		$connector = new PaySentinel_Stripe_Connector();
		$result    = $connector->test_connection();

		$this->assertFalse( $result['success'] );
		$this->assertEquals( 'unconfigured', $result['status'] );
		$this->assertStringContainsString( 'not configured', $result['message'] );
	}

	/**
	 * Test Stripe Connector - Invalid Key format.
	 */
	public function test_stripe_connector_invalid_format() {
		update_option(
			'woocommerce_stripe_settings',
			array(
				'secret_key' => 'invalid_key_format',
			)
		);

		$connector = new PaySentinel_Stripe_Connector();
		$result    = $connector->test_connection();

		$this->assertFalse( $result['success'] );
		$this->assertEquals( 'unconfigured', $result['status'] );
	}

	/**
	 * Test Stripe Connector - Successful Connection.
	 */
	public function test_stripe_connector_success() {
		update_option(
			'woocommerce_stripe_settings',
			array(
				'secret_key' => 'sk_live_test_key',
			)
		);

		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) {
				if ( strpos( $url, 'api.stripe.com' ) !== false ) {
					return array(
						'response' => array( 'code' => 200 ),
						'body'     => wp_json_encode( array( 'object' => 'balance' ) ),
					);
				}
				return $pre;
			},
			10,
			3
		);

		$connector = new PaySentinel_Stripe_Connector();
		$result    = $connector->test_connection();

		$this->assertTrue( $result['success'] );
		$this->assertEquals( 'online', $result['status'] );
		$this->assertStringContainsString( 'Successfully connected', $result['message'] );

		remove_all_filters( 'pre_http_request' );
	}

	/**
	 * Test Stripe Connector - API Error.
	 */
	public function test_stripe_connector_api_error() {
		update_option(
			'woocommerce_stripe_settings',
			array(
				'secret_key' => 'sk_live_test_key',
			)
		);

		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) {
				if ( strpos( $url, 'api.stripe.com' ) !== false ) {
					return array(
						'response' => array( 'code' => 401 ),
						'body'     => wp_json_encode( array( 'error' => array( 'message' => 'Invalid API Key' ) ) ),
					);
				}
				return $pre;
			},
			10,
			3
		);

		$connector = new PaySentinel_Stripe_Connector();
		$result    = $connector->test_connection();

		$this->assertFalse( $result['success'] );
		$this->assertEquals( 'offline', $result['status'] );
		$this->assertStringContainsString( 'Invalid API Key', $result['message'] );

		remove_all_filters( 'pre_http_request' );
	}

	/**
	 * Test Stripe Connector - HTTP Timeout.
	 */
	public function test_stripe_connector_timeout() {
		update_option(
			'woocommerce_stripe_settings',
			array(
				'secret_key' => 'sk_live_test_key',
			)
		);

		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) {
				if ( strpos( $url, 'api.stripe.com' ) !== false ) {
					return new WP_Error( 'http_request_failed', 'CURL error 28: Connection timed out' );
				}
				return $pre;
			},
			10,
			3
		);

		$connector = new PaySentinel_Stripe_Connector();
		$result    = $connector->test_connection();

		$this->assertFalse( $result['success'] );
		$this->assertEquals( 'offline', $result['status'] );
		$this->assertStringContainsString( 'timed out', $result['message'] );

		remove_all_filters( 'pre_http_request' );
	}

	/**
	 * Test PayPal Connector.
	 */
	public function test_paypal_connector() {
		$connector = new PaySentinel_PayPal_Connector();

		// 1. Unconfigured
		$this->assertEquals( 'unconfigured', $connector->test_connection()['status'] );

		// 2. Success
		update_option(
			'woocommerce_paypal_settings',
			array(
				'client_id' => 'CLIENT_ID',
				'secret'    => 'SECRET',
				'sandbox'   => 'no',
			)
		);

		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) {
				if ( strpos( $url, 'paypal.com' ) !== false ) {
					return array(
						'response' => array( 'code' => 200 ),
						'body'     => wp_json_encode( array( 'access_token' => 'TEST_TOKEN' ) ),
					);
				}
				return $pre;
			},
			10,
			3
		);

		$result = $connector->test_connection();
		$this->assertTrue( $result['success'] );
		$this->assertEquals( 'online', $result['status'] );
		remove_all_filters( 'pre_http_request' );
	}

	/**
	 * Test PayPal Connector API Error.
	 */
	public function test_paypal_connector_api_error() {
		update_option(
			'woocommerce_paypal_settings',
			array(
				'client_id' => 'CLIENT_ID',
				'secret'    => 'SECRET',
			)
		);

		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) {
				if ( strpos( $url, 'paypal.com' ) !== false ) {
					return array(
						'response' => array( 'code' => 401 ),
						'body'     => wp_json_encode( array( 'error_description' => 'Invalid client credentials' ) ),
					);
				}
				return $pre;
			},
			10,
			3
		);

		$connector = new PaySentinel_PayPal_Connector();
		$result    = $connector->test_connection();
		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'Invalid client credentials', $result['message'] );
		remove_all_filters( 'pre_http_request' );
	}

	/**
	 * Test Square Connector.
	 */
	public function test_square_connector() {
		$connector = new PaySentinel_Square_Connector();

		// 1. Unconfigured
		$this->assertEquals( 'unconfigured', $connector->test_connection()['status'] );

		// 2. Success
		update_option(
			'woocommerce_square_credit_card_settings',
			array(
				'token'   => 'SQUARE_TOKEN',
				'sandbox' => 'no',
			)
		);

		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) {
				if ( strpos( $url, 'square' ) !== false ) {
					return array(
						'response' => array( 'code' => 200 ),
						'body'     => wp_json_encode( array( 'locations' => array( array( 'id' => 'LOC1' ) ) ) ),
					);
				}
				return $pre;
			},
			10,
			3
		);

		$result = $connector->test_connection();
		$this->assertTrue( $result['success'] );
		$this->assertEquals( 'online', $result['status'] );
		remove_all_filters( 'pre_http_request' );
	}

	/**
	 * Test Square Connector API Error.
	 */
	public function test_square_connector_api_error() {
		update_option(
			'woocommerce_square_credit_card_settings',
			array(
				'token' => 'SQUARE_TOKEN',
			)
		);

		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) {
				if ( strpos( $url, 'square' ) !== false ) {
					return array(
						'response' => array( 'code' => 401 ),
						'body'     => wp_json_encode( array( 'errors' => array( array( 'detail' => 'Authorization failed' ) ) ) ),
					);
				}
				return $pre;
			},
			10,
			3
		);

		$connector = new PaySentinel_Square_Connector();
		$result    = $connector->test_connection();
		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'Authorization failed', $result['message'] );
		remove_all_filters( 'pre_http_request' );
	}

	/**
	 * Test WC Payments Connector.
	 */
	public function test_wc_payments_connector() {
		$connector = new PaySentinel_WC_Payments_Connector();

		// 1. Unconfigured
		$this->assertEquals( 'unconfigured', $connector->test_connection()['status'] );

		// 2. Disabled
		update_option(
			'woocommerce_payments_settings',
			array(
				'account_id' => 'ACC123',
				'enabled'    => 'no',
			)
		);
		$this->assertEquals( 'unconfigured', $connector->test_connection()['status'] );

		// 3. Plugin missing (Offline)
		update_option(
			'woocommerce_payments_settings',
			array(
				'account_id' => 'ACC123',
				'enabled'    => 'yes',
			)
		);
		$result = $connector->test_connection();
		$this->assertEquals( 'offline', $result['status'] );
		$this->assertStringContainsString( 'plugin not found', $result['message'] );

		// 4. Success (mocking class)
		if ( ! class_exists( 'WC_Payments_API_Client' ) ) {
			eval( 'class WC_Payments_API_Client {}' );
		}

		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) {
				if ( strpos( $url, 'wcpay' ) !== false ) {
					return array(
						'response' => array( 'code' => 200 ),
						'body'     => '{}',
					);
				}
				return $pre;
			},
			10,
			3
		);

		$result = $connector->test_connection();
		$this->assertTrue( $result['success'] );
		$this->assertEquals( 'online', $result['status'] );
		remove_all_filters( 'pre_http_request' );
	}

	/**
	 * Test Gateway Connectivity coordinator.
	 */
	public function test_gateway_connectivity_coordinator() {
		$connectivity = new PaySentinel_Gateway_Connectivity();

		// Mock Stripe success
		update_option( 'woocommerce_stripe_settings', array( 'secret_key' => 'sk_live_test' ) );
		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) {
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode( array( 'object' => 'balance' ) ),
				);
			},
			10,
			3
		);

		$results = $connectivity->check_all_gateways();

		$this->assertGreaterThanOrEqual( 1, $results['checked_gateways'] );
		$this->assertTrue( in_array( 'stripe', $results['online_gateways'] ) );

		$last_check = $connectivity->get_last_check( 'stripe' );
		$this->assertEquals( 'online', $last_check->status );

		$history = $connectivity->get_history( 'stripe' );
		$this->assertNotEmpty( $history );

		remove_all_filters( 'pre_http_request' );
	}

	/**
	 * Test Connectivity Cleanup.
	 */
	public function test_gateway_connectivity_cleanup() {
		global $wpdb;
		$connectivity = new PaySentinel_Gateway_Connectivity();
		$table        = $wpdb->prefix . 'payment_monitor_gateway_connectivity';

		// Add old record
		$wpdb->insert(
			$table,
			array(
				'gateway_id' => 'stripe',
				'status'     => 'online',
				'checked_at' => date( 'Y-m-d H:i:s', strtotime( '-40 days' ) ),
			)
		);

		// Add recent record
		$wpdb->insert(
			$table,
			array(
				'gateway_id' => 'stripe',
				'status'     => 'online',
				'checked_at' => current_time( 'mysql' ),
			)
		);

		$deleted = $connectivity->cleanup_old_checks( 30 );
		$this->assertEquals( 1, $deleted );

		$remaining = $wpdb->get_var( "SELECT COUNT(*) FROM $table" );
		$this->assertEquals( 1, $remaining );
	}

	/**
	 * Test supported and enabled gateways.
	 */
	public function test_gateway_connectivity_supported_enabled() {
		$connectivity = new PaySentinel_Gateway_Connectivity();

		$supported = $connectivity->get_supported_gateways();
		$this->assertContains( 'stripe', $supported );
		$this->assertContains( 'paypal', $supported );

		// Mock WC not active or no gateways enabled
		// This might be tricky without full WC suite, but we can try to mock the global WC()
		if ( function_exists( 'WC' ) ) {
			$enabled = $connectivity->get_enabled_gateways();
			$this->assertIsArray( $enabled );
		}
	}
}
