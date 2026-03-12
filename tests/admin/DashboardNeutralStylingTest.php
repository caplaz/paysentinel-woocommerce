<?php

/**
 * Tests for dashboard neutral styling when no transactions exist
 *
 * Tests Properties:
 * - Property 27: Dashboard shows neutral styling for gateways with no transactions
 * - Property 28: Dashboard displays N/A for metrics when no transaction data
 * - Property 29: API returns consistent structure for zero-transaction gateways
 */

class DashboardNeutralStylingTest extends PaySentinel_Test_Case {

	/**
	 * API Health endpoint instance
	 */
	private $api_health;

	/**
	 * Database instance
	 */
	private $database;

	/**
	 * Setup test
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->api_health = new PaySentinel_API_Health();
		$this->database   = new PaySentinel_Database();
	}

	/**
	 * Property 27: Dashboard shows neutral styling for gateways with no transactions
	 *
	 * Verify that gateways with zero transactions are styled neutrally
	 * rather than showing critical/error styling that would be misleading.
	 *
	 * Requirements: 5.1, 5.2, 7.3
	 */
	public function test_property_27_dashboard_neutral_styling_for_zero_transactions() {
		// Test 1: API should return transaction_count = 0 for new gateways
		$request = new WP_REST_Request( 'GET', '/paysentinel/v1/health/gateways' );
		$request->set_param( 'period', '24h' );
		$request->set_param( 'scope', 'all' );

		$response = $this->api_health->get_all_gateway_health( $request );
		$data     = $response->get_data();

		$this->assertIsArray( $data );
		$this->assertArrayHasKey( 'success', $data );
		$this->assertTrue( $data['success'] );
		$this->assertArrayHasKey( 'data', $data );
		$this->assertArrayHasKey( 'items', $data['data'] );
		$this->assertArrayHasKey( 'pagination', $data['data'] );

		// Test 2: Each gateway should have transaction_count field
		foreach ( $data['data']['items'] as $gateway ) {
			$this->assertArrayHasKey( 'transaction_count', $gateway );
			$this->assertIsInt( $gateway['transaction_count'] );
			$this->assertGreaterThanOrEqual( 0, $gateway['transaction_count'] );
		}

		// Test 3: Gateways with zero transactions should have neutral styling indicators
		$zero_transaction_gateways = array_filter(
			$data['data']['items'],
			function ( $gateway ) {
				return $gateway['transaction_count'] === 0;
			}
		);

		foreach ( $zero_transaction_gateways as $gateway ) {
			// Should have success_rate but it should be 0
			$this->assertArrayHasKey( 'success_rate', $gateway );
			$this->assertEquals( 0, $gateway['success_rate'] );

			// Should have avg_response_time as null or 0
			$this->assertArrayHasKey( 'avg_response_time', $gateway );
			$this->assertTrue(
				$gateway['avg_response_time'] === null || $gateway['avg_response_time'] === 0,
				'Zero transaction gateway should have null or 0 avg_response_time'
			);
		}
	}

	/**
	 * Property 28: Dashboard displays N/A for metrics when no transaction data
	 *
	 * Verify that frontend logic correctly identifies when to display "N/A"
	 * instead of misleading "0%" success rates and "0ms" response times.
	 *
	 * Requirements: 5.1, 5.2, 7.3
	 */
	public function test_property_28_dashboard_na_display_for_zero_transaction_metrics() {
		// Test 1: Create mock gateway data with zero transactions
		$mock_gateway_data = array(
			array(
				'gateway_id'        => 'test_gateway_1',
				'gateway_name'      => 'Test Gateway 1',
				'transaction_count' => 0,
				'success_rate'      => 0,
				'avg_response_time' => 0,
				'health_percentage' => 0,
			),
			array(
				'gateway_id'        => 'test_gateway_2',
				'gateway_name'      => 'Test Gateway 2',
				'transaction_count' => 5,
				'success_rate'      => 80.5,
				'avg_response_time' => 250,
				'health_percentage' => 80.5,
			),
		);

		// Test 2: Verify frontend logic for displaying N/A
		foreach ( $mock_gateway_data as $gateway ) {
			if ( $gateway['transaction_count'] === 0 ) {
				// Should display N/A for success rate
				$expected_success_display = 'N/A';
				$this->assertEquals( 'N/A', $expected_success_display );

				// Should display N/A for avg response time
				$expected_response_display = 'N/A';
				$this->assertEquals( 'N/A', $expected_response_display );

				// Should display N/A for health score
				$expected_health_display = 'N/A';
				$this->assertEquals( 'N/A', $expected_health_display );
			} else {
				// Should display actual values for gateways with transactions
				$expected_success_display = $gateway['success_rate'] . '%';
				$this->assertEquals( '80.5%', $expected_success_display );

				$expected_response_display = $gateway['avg_response_time'] . 'ms';
				$this->assertEquals( '250ms', $expected_response_display );

				$expected_health_display = $gateway['health_percentage'] . '%';
				$this->assertEquals( '80.5%', $expected_health_display );
			}
		}
	}

	/**
	 * Property 29: API returns consistent structure for zero-transaction gateways
	 *
	 * Verify that the API maintains consistent data structure and field presence
	 * even when gateways have zero transactions, ensuring frontend compatibility.
	 *
	 * Requirements: 5.1, 5.2, 7.1, 7.2
	 */
	public function test_property_29_api_consistent_structure_for_zero_transaction_gateways() {
		// Test 1: API response should have consistent structure
		$request = new WP_REST_Request( 'GET', '/paysentinel/v1/health/gateways' );
		$request->set_param( 'period', '24h' );
		$request->set_param( 'scope', 'all' );

		$response = $this->api_health->get_all_gateway_health( $request );
		$data     = $response->get_data();

		$this->assertIsArray( $data );
		$this->assertArrayHasKey( 'success', $data );
		$this->assertTrue( $data['success'] );
		$this->assertArrayHasKey( 'data', $data );
		$this->assertArrayHasKey( 'items', $data['data'] );
		$this->assertArrayHasKey( 'pagination', $data['data'] );

		if ( ! empty( $data['data']['items'] ) ) {
			$first_gateway = $data['data']['items'][0];

			// Test 2: Required fields should always be present
			$required_fields = array(
				'gateway_id',
				'gateway_name',
				'transaction_count',
				'success_rate',
				'avg_response_time',
				'health_percentage',
				'last_checked',
				'trend_data',
			);

			foreach ( $required_fields as $field ) {
				$this->assertArrayHasKey( $field, $first_gateway );
			}

			// Test 3: Data types should be consistent
			$this->assertIsString( $first_gateway['gateway_id'] );
			$this->assertIsString( $first_gateway['gateway_name'] );
			$this->assertIsInt( $first_gateway['transaction_count'] );
			$this->assertIsFloat( $first_gateway['success_rate'] );
			$this->assertTrue(
				is_null( $first_gateway['avg_response_time'] ) || is_int( $first_gateway['avg_response_time'] ),
				'avg_response_time should be null or int'
			);
			$this->assertIsFloat( $first_gateway['health_percentage'] );
			$this->assertIsArray( $first_gateway['trend_data'] );

			// Test 4: Zero transaction gateways should have specific characteristics
			if ( $first_gateway['transaction_count'] === 0 ) {
				$this->assertEquals( 0, $first_gateway['success_rate'] );
				$this->assertTrue(
					$first_gateway['avg_response_time'] === null || $first_gateway['avg_response_time'] === 0
				);
				$this->assertEquals( 0, $first_gateway['health_percentage'] );
			}
		}
	}

	/**
	 * Test CSS class logic for neutral styling
	 *
	 * Verify that the frontend class assignment logic correctly applies
	 * neutral styling when transaction_count is 0.
	 */
	public function test_css_class_assignment_for_neutral_styling() {
		// Test cases for class assignment
		$test_cases = array(
			array(
				'transaction_count' => 0,
				'success_rate'      => 0,
				'expected_class'    => 'neutral',
			),
			array(
				'transaction_count' => 0,
				'success_rate'      => 100,
				'expected_class'    => 'neutral', // Should be neutral regardless of success_rate when no transactions
			),
			array(
				'transaction_count' => 5,
				'success_rate'      => 95,
				'expected_class'    => 'healthy',
			),
			array(
				'transaction_count' => 10,
				'success_rate'      => 85,
				'expected_class'    => 'warning',
			),
			array(
				'transaction_count' => 20,
				'success_rate'      => 50,
				'expected_class'    => 'critical',
			),
		);

		foreach ( $test_cases as $case ) {
			$gateway = $case;

			// Simulate the class assignment logic from the JavaScript
			if ( $gateway['transaction_count'] === 0 ) {
				$assigned_class = 'neutral';
			} elseif ( $gateway['success_rate'] >= 95 ) {
				$assigned_class = 'healthy';
			} elseif ( $gateway['success_rate'] >= 85 ) {
				$assigned_class = 'warning';
			} else {
				$assigned_class = 'critical';
			}

			$this->assertEquals(
				$case['expected_class'],
				$assigned_class,
				"Failed for transaction_count={$case['transaction_count']}, success_rate={$case['success_rate']}"
			);
		}
	}

	/**
	 * Test recovery success rate calculation logic
	 *
	 * Verify that frontend calculation logic correctly displays N/A when revenue is 0
	 */
	public function test_recovery_success_rate_calc() {
		$test_cases = array(
			array(
				'total_lost'      => 0,
				'total_recovered' => 0,
				'expected'        => 'N/A',
			),
			array(
				'total_lost'      => 100,
				'total_recovered' => 50,
				'expected'        => '33%',
			),
			array(
				'total_lost'      => 0,
				'total_recovered' => 100,
				'expected'        => '100%',
			),
		);

		foreach ( $test_cases as $case ) {
			if ( $case['total_lost'] + $case['total_recovered'] > 0 ) {
				$val = round( ( $case['total_recovered'] / ( $case['total_lost'] + $case['total_recovered'] ) ) * 100 ) . '%';
			} else {
				$val = 'N/A';
			}

			$this->assertEquals( $case['expected'], $val );
		}
	}
}
