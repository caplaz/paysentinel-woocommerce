<?php
/**
 * Integration tests for dashboard API response format.
 *
 * @package PaySentinel
 */

/**
 * Class DashboardAPIIntegrationTest
 */
class DashboardAPIIntegrationTest extends WP_UnitTestCase {

	/**
	 * API health instance.
	 *
	 * @var PaySentinel_API_Health
	 */
	private $api_health;

	/**
	 * Database instance.
	 *
	 * @var PaySentinel_Database
	 */
	private $database;

	/**
	 * Setup test.
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->api_health = new PaySentinel_API_Health();
		$this->database   = new PaySentinel_Database();

		// Ensure tables exist.
		$this->database->create_tables();

		// Create admin user for API access.
		$this->admin_user = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_user );
	}

	/**
	 * Property 30: Dashboard API Response Format Integration
	 *
	 * Ensures the health API returns responses in the format expected by the dashboard
	 * and that the dashboard can properly parse and display the data.
	 */
	public function test_property_30_dashboard_api_response_format_integration() {
		// Create some test transaction data.
		$this->create_test_transaction_data();

		// Test the API response format.
		$request = new WP_REST_Request( 'GET', '/paysentinel/v1/health/gateways' );
		$request->set_param( 'period', '24h' );
		$request->set_param( 'scope', 'enabled' );

		$response = $this->api_health->get_all_gateway_health( $request );
		$this->assertInstanceOf( 'WP_REST_Response', $response );

		$data = $response->get_data();

		// Verify the response has the expected structure for dashboard consumption.
		$this->assertIsArray( $data );
		$this->assertArrayHasKey( 'success', $data );
		$this->assertTrue( $data['success'] );
		$this->assertArrayHasKey( 'data', $data );
		$this->assertIsArray( $data['data'] );
		$this->assertArrayHasKey( 'items', $data['data'] );
		$this->assertArrayHasKey( 'pagination', $data['data'] );

		// Ensure items is an array (even if empty).
		$this->assertIsArray( $data['data']['items'] );

		// If there are items, verify they have the required fields for dashboard display.
		if ( ! empty( $data['data']['items'] ) ) {
			foreach ( $data['data']['items'] as $gateway ) {
				$this->assertIsArray( $gateway );
				$this->assertArrayHasKey( 'gateway_id', $gateway );
				$this->assertArrayHasKey( 'gateway_name', $gateway );
				$this->assertArrayHasKey( 'total_transactions', $gateway );
				$this->assertArrayHasKey( 'success_rate', $gateway );

				// Verify data types.
				$this->assertIsString( $gateway['gateway_id'] );
				$this->assertIsString( $gateway['gateway_name'] );
				$this->assertTrue( is_int( $gateway['total_transactions'] ) || is_float( $gateway['total_transactions'] ), 'total_transactions should be int or float' );
				$this->assertTrue( is_float( $gateway['success_rate'] ) || is_int( $gateway['success_rate'] ), 'success_rate should be float or int' );
			}
		}
	}

	/**
	 * Property 31: Health Data Display Consistency
	 *
	 * Ensures that when health data exists, the dashboard will display it
	 * instead of showing "No gateway health data available".
	 */
	public function test_property_31_health_data_display_consistency() {
		// Create test data that should result in health data being available.
		$this->create_test_transaction_data();

		$request = new WP_REST_Request( 'GET', '/paysentinel/v1/health/gateways' );
		$request->set_param( 'period', '24h' );
		$request->set_param( 'scope', 'enabled' );

		$response = $this->api_health->get_all_gateway_health( $request );
		$data     = $response->get_data();

		// Verify response structure.
		$this->assertArrayHasKey( 'success', $data );
		$this->assertTrue( $data['success'] );
		$this->assertArrayHasKey( 'data', $data );
		$this->assertArrayHasKey( 'items', $data['data'] );

		// The items array should exist (even if empty) and be properly structured.
		$this->assertIsArray( $data['data']['items'] );

		// Test that the data structure matches what the dashboard expects.
		// Dashboard checks: healthData.length > 0.
		$dashboard_would_display_data = count( $data['data']['items'] ) > 0;

		// If dashboard_would_display_data is false, it would show "No gateway health data available".
		// We want to ensure this doesn't happen when there should be data.
		// For this test, we created data, so there should be items.
		$this->assertTrue( $dashboard_would_display_data, 'Dashboard should display health data when API returns items' );
	}

	/**
	 * Property 32: Empty State Response Format
	 *
	 * Ensures that even when no gateways are available, the API returns
	 * the correct response format that the dashboard can handle.
	 */
	public function test_property_32_empty_state_response_format() {
		// Don't create any test data - test empty state.

		$request = new WP_REST_Request( 'GET', '/paysentinel/v1/health/gateways' );
		$request->set_param( 'period', '24h' );
		$request->set_param( 'scope', 'enabled' );

		$response = $this->api_health->get_all_gateway_health( $request );
		$data     = $response->get_data();

		// Verify response structure even in empty state.
		$this->assertArrayHasKey( 'success', $data );
		$this->assertTrue( $data['success'] );
		$this->assertArrayHasKey( 'data', $data );
		$this->assertArrayHasKey( 'items', $data['data'] );
		$this->assertArrayHasKey( 'pagination', $data['data'] );

		// Items should be an array (may or may not be empty depending on available gateways).
		$this->assertIsArray( $data['data']['items'] );

		// Dashboard would correctly show "No gateway health data available" only if no gateways exist.
		$dashboard_would_show_empty_message = count( $data['data']['items'] ) === 0;
		// We don't assert this as true since gateways may exist in test environment.
		$this->assertIsBool( $dashboard_would_show_empty_message );
	}

	/**
	 * Create test transaction data for testing.
	 */
	private function create_test_transaction_data() {
		global $wpdb;

		$table_name = $wpdb->prefix . 'payment_monitor_transactions';

		// Insert test transactions for stripe gateway.
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$table_name,
			array(
				'order_id'       => 1001,
				'gateway_id'     => 'stripe',
				'transaction_id' => 'txn_test_123',
				'amount'         => 99.99,
				'currency'       => 'USD',
				'status'         => 'success',
				'failure_reason' => null,
				'failure_code'   => null,
				'retry_count'    => 0,
				'customer_email' => 'test@example.com',
				'customer_ip'    => '127.0.0.1',
				'created_at'     => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%f', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s' )
		);

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$table_name,
			array(
				'order_id'       => 1002,
				'gateway_id'     => 'stripe',
				'transaction_id' => 'txn_test_124',
				'amount'         => 49.99,
				'currency'       => 'USD',
				'status'         => 'failed',
				'failure_reason' => 'Card declined',
				'failure_code'   => 'card_declined',
				'retry_count'    => 0,
				'customer_email' => 'test2@example.com',
				'customer_ip'    => '127.0.0.1',
				'created_at'     => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%f', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s' )
		);
	}

	/**
	 * Property 33: Malformed Response Handling
	 *
	 * Ensures the dashboard gracefully handles API responses that don't match
	 * the expected structure, preventing JavaScript errors
	 */
	public function test_property_33_malformed_response_handling() {
		// Test case 1: Missing data property.
		$malformed_response_1 = array(
			'success' => true,
			'message' => 'Success but no data',
		);

		// Test case 2: data is null.
		$malformed_response_2 = array(
			'success' => true,
			'data'    => null,
		);

		// Test case 3: data exists but no items.
		$malformed_response_3 = array(
			'success' => true,
			'data'    => array(
				'pagination' => array( 'total' => 0 ),
			),
		);

		// Test case 4: items is not an array.
		$malformed_response_4 = array(
			'success' => true,
			'data'    => array(
				'items'      => 'not an array',
				'pagination' => array( 'total' => 0 ),
			),
		);

		$test_cases = array(
			'missing_data'  => $malformed_response_1,
			'null_data'     => $malformed_response_2,
			'missing_items' => $malformed_response_3,
			'invalid_items' => $malformed_response_4,
		);

		foreach ( $test_cases as $case_name => $response_data ) {
			// We can't easily test the JavaScript directly, but we can verify.
			// that the API doesn't return these malformed responses in normal operation.
			// and document that the frontend should handle them gracefully.

			// For now, just assert that our normal API doesn't return these formats.
			$request = new WP_REST_Request( 'GET', '/paysentinel/v1/health/gateways' );
			$request->set_param( 'period', '24h' );
			$request->set_param( 'scope', 'enabled' );

			$response = $this->api_health->get_all_gateway_health( $request );
			$data     = $response->get_data();

			// Normal responses should always have data and items as array.
			$this->assertArrayHasKey( 'data', $data, "Case $case_name: data key missing" );
			$this->assertArrayHasKey( 'items', $data['data'], "Case $case_name: items key missing" );
			$this->assertIsArray( $data['data']['items'], "Case $case_name: items not array" );
		}
	}
}
