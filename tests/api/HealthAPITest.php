<?php

/**
 * Unit tests for WC_Payment_Monitor_API_Health class
 * Tests the health API endpoints and real-time transaction statistics
 */
class HealthAPITest extends WP_UnitTestCase {

	private $api;
	private $database;
	private $logger;

	/**
	 * Set up test environment
	 */
	public function setUp(): void {
		parent::setUp();

		$this->api      = new WC_Payment_Monitor_API_Health();
		$this->database = new WC_Payment_Monitor_Database();
		$this->logger   = new WC_Payment_Monitor_Logger();

		// Create admin user for API access
		$this->admin_user = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_user );

		// Ensure tables exist
		$this->database->create_tables();
	}

	/**
	 * Test that the health API can be instantiated
	 */
	public function test_health_api_can_be_instantiated() {
		$this->assertInstanceOf( 'WC_Payment_Monitor_API_Health', $this->api );
		$this->assertTrue( method_exists( $this->api, 'register_routes' ) );
		$this->assertTrue( method_exists( $this->api, 'get_all_gateway_health' ) );
	}

	/**
	 * Test get_realtime_transaction_stats method with no transactions
	 */
	public function test_get_realtime_transaction_stats_no_transactions() {
		$gateway_id = 'test_gateway';

		// Use reflection to access private method
		$reflection = new ReflectionClass( $this->api );
		$method     = $reflection->getMethod( 'get_realtime_transaction_stats' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->api, $gateway_id );

		$this->assertIsArray( $result );
		$this->assertEquals( 0, $result['total'] );
		$this->assertEquals( 0, $result['successful'] );
		$this->assertEquals( 0, $result['failed'] );
		$this->assertEquals( 0, $result['pending'] );
	}

	/**
	 * Test get_realtime_transaction_stats method with transactions
	 */
	public function test_get_realtime_transaction_stats_with_transactions() {
		global $wpdb;

		$gateway_id = 'test_gateway';

		// Insert test transactions
		$transactions = array(
			array(
				'gateway_id'    => $gateway_id,
				'status'        => 'success',
				'amount'        => 100.00,
				'created_at'    => current_time( 'mysql' ),
			),
			array(
				'gateway_id'    => $gateway_id,
				'status'        => 'failed',
				'amount'        => 50.00,
				'created_at'    => current_time( 'mysql' ),
			),
			array(
				'gateway_id'    => $gateway_id,
				'status'        => 'pending',
				'amount'        => 75.00,
				'created_at'    => current_time( 'mysql' ),
			),
		);

		foreach ( $transactions as $transaction ) {
			$wpdb->insert(
				$wpdb->prefix . 'payment_monitor_transactions',
				$transaction,
				array( '%s', '%s', '%f', '%s' )
			);
		}

		// Use reflection to access private method
		$reflection = new ReflectionClass( $this->api );
		$method     = $reflection->getMethod( 'get_realtime_transaction_stats' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->api, $gateway_id );

		$this->assertIsArray( $result );
		$this->assertEquals( 3, $result['total'] );
		$this->assertEquals( 1, $result['successful'] );
		$this->assertEquals( 1, $result['failed'] );
		$this->assertEquals( 1, $result['pending'] );
	}

	/**
	 * Test get_realtime_transaction_stats method with different gateway
	 */
	public function test_get_realtime_transaction_stats_different_gateway() {
		global $wpdb;

		$gateway_id_1 = 'gateway_1';
		$gateway_id_2 = 'gateway_2';

		// Insert transactions for gateway_1
		$wpdb->insert(
			$wpdb->prefix . 'payment_monitor_transactions',
			array(
				'gateway_id' => $gateway_id_1,
				'status'     => 'success',
				'amount'     => 100.00,
				'created_at' => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%f', '%s' )
		);

		// Use reflection to access private method
		$reflection = new ReflectionClass( $this->api );
		$method     = $reflection->getMethod( 'get_realtime_transaction_stats' );
		$method->setAccessible( true );

		$result_1 = $method->invoke( $this->api, $gateway_id_1 );
		$result_2 = $method->invoke( $this->api, $gateway_id_2 );

		$this->assertEquals( 1, $result_1['total'] );
		$this->assertEquals( 0, $result_2['total'] );
	}

	/**
	 * Test get_all_gateway_health includes total_transactions field
	 */
	public function test_get_all_gateway_health_includes_total_transactions() {
		global $wpdb;

		$gateway_id = 'test_gateway';

		// Insert test transactions
		$wpdb->insert(
			$wpdb->prefix . 'payment_monitor_transactions',
			array(
				'gateway_id' => $gateway_id,
				'status'     => 'success',
				'amount'     => 100.00,
				'created_at' => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%f', '%s' )
		);

		// Create a request
		$request = new WP_REST_Request( 'GET', '/wc-payment-monitor/v1/health/gateways' );

		// Test that the method can be called without errors
		$response = $this->api->get_all_gateway_health( $request );
		$this->assertInstanceOf( 'WP_REST_Response', $response );

		$data = $response->get_data();

		// The response should be successful
		$this->assertTrue( isset( $data['success'] ) ? $data['success'] : false );

		// If there are items, they should have the total_transactions field
		if ( isset( $data['data']['items'] ) && is_array( $data['data']['items'] ) && ! empty( $data['data']['items'] ) ) {
			foreach ( $data['data']['items'] as $gateway_data ) {
				$this->assertArrayHasKey( 'total_transactions', $gateway_data );
				$this->assertIsInt( $gateway_data['total_transactions'] );
			}
		}
	}

	/**
	 * Test get_all_gateway_health handles missing health data gracefully
	 */
	public function test_get_all_gateway_health_handles_missing_health_data() {
		// Create a request
		$request = new WP_REST_Request( 'GET', '/wc-payment-monitor/v1/health/gateways' );

		$response = $this->api->get_all_gateway_health( $request );
		$this->assertInstanceOf( 'WP_REST_Response', $response );

		$data = $response->get_data();
		$this->assertTrue( $data['success'] );
		$this->assertArrayHasKey( 'data', $data );
	}

	/**
	 * Test get_realtime_transaction_stats handles missing table gracefully
	 */
	public function test_get_realtime_transaction_stats_handles_missing_table() {
		global $wpdb;

		$gateway_id = 'test_gateway';

		// Temporarily rename the table to simulate it not existing
		$table_name = $wpdb->prefix . 'payment_monitor_transactions';
		$temp_table = $table_name . '_temp';

		// Check if table exists and rename it
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) === $table_name ) {
			$wpdb->query( "RENAME TABLE {$table_name} TO {$temp_table}" );
		}

		try {
			// Use reflection to access private method
			$reflection = new ReflectionClass( $this->api );
			$method     = $reflection->getMethod( 'get_realtime_transaction_stats' );
			$method->setAccessible( true );

			$result = $method->invoke( $this->api, $gateway_id );

			$this->assertIsArray( $result );
			$this->assertEquals( 0, $result['total'] );
			$this->assertEquals( 0, $result['successful'] );
			$this->assertEquals( 0, $result['failed'] );
			$this->assertEquals( 0, $result['pending'] );
		} finally {
			// Restore the table
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $temp_table ) ) === $temp_table ) {
				$wpdb->query( "RENAME TABLE {$temp_table} TO {$table_name}" );
			}
		}
	}

	/**
	 * Test that total_transactions field is included in gateway health response
	 */
	public function test_total_transactions_field_in_response() {
		global $wpdb;

		$gateway_id = 'test_gateway';

		// Insert multiple transactions
		for ( $i = 0; $i < 5; $i++ ) {
			$wpdb->insert(
				$wpdb->prefix . 'payment_monitor_transactions',
				array(
					'gateway_id' => $gateway_id,
					'status'     => 'success',
					'amount'     => 100.00,
					'created_at' => current_time( 'mysql' ),
				),
				array( '%s', '%s', '%f', '%s' )
			);
		}

		// Use reflection to access private method
		$reflection = new ReflectionClass( $this->api );
		$method     = $reflection->getMethod( 'get_realtime_transaction_stats' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->api, $gateway_id );

		$this->assertEquals( 5, $result['total'] );
		$this->assertEquals( 5, $result['successful'] );
		$this->assertEquals( 0, $result['failed'] );
		$this->assertEquals( 0, $result['pending'] );
	}

	/**
	 * Clean up after tests
	 */
	public function tearDown(): void {
		global $wpdb;

		// Clean up test data
		$wpdb->query( "DELETE FROM {$wpdb->prefix}payment_monitor_transactions WHERE gateway_id LIKE 'test_%'" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}payment_monitor_gateway_health WHERE gateway_id LIKE 'test_%'" );

		parent::tearDown();
	}
}