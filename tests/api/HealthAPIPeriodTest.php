<?php

class Testable_PaySentinel_API_Health extends PaySentinel_API_Health {
	protected function get_wc_gateways_all() {
		$gateway     = new \stdClass();
		$gateway->id = 'bacs';
		return array( 'bacs' => $gateway );
	}
	protected function get_wc_gateways_enabled() {
		$gateway     = new \stdClass();
		$gateway->id = 'bacs';
		return array( 'bacs' => $gateway );
	}
}

/**
 * Tests for API Health period mapping and logic
 */
class HealthAPIPeriodTest extends WP_UnitTestCase {

	private $api;

	public function setUp(): void {
		parent::setUp();

		$this->api = new Testable_PaySentinel_API_Health();

		// Ensure proper DB tables
		$database = new PaySentinel_Database();
		$database->create_tables();

		// Truncate relevant tables
		global $wpdb;
		$tables = array(
			$database->get_transactions_table(),
			$database->get_gateway_health_table(),
		);

		foreach ( $tables as $table ) {
			$wpdb->query( "TRUNCATE TABLE {$table}" );
		}
	}

	public function tearDown(): void {
		global $wpdb;
		$database = new PaySentinel_Database();
		$tables   = array(
			$database->get_transactions_table(),
			$database->get_gateway_health_table(),
		);

		foreach ( $tables as $table ) {
			$wpdb->query( "TRUNCATE TABLE {$table}" );
		}

		parent::tearDown();
	}

	/**
	 * Test that the '24h' UI parameter translates to querying '24hour' in DB.
	 */
	public function test_get_gateway_health_uses_correct_backend_period_mapping() {
		global $wpdb;

		$gateway_id     = 'bacs';
		$frontend_param = '24h';
		$backend_period = '24hour';

		// Seed a health status for '24hour'
		$database   = new PaySentinel_Database();
		$table_name = $database->get_gateway_health_table();
		$wpdb->insert(
			$table_name,
			array(
				'gateway_id'              => $gateway_id,
				'period'                  => $backend_period,
				'total_transactions'      => 500,
				'successful_transactions' => 450,
				'failed_transactions'     => 50,
				'success_rate'            => 90.0,
				'avg_response_time'       => 200,
				'calculated_at'           => current_time( 'mysql' ),
			)
		);

		// Make request
		$request = new \WP_REST_Request( 'GET', '/paysentinel/v1/health/gateways/' . $gateway_id );
		$request->set_param( 'gateway_id', $gateway_id );
		$request->set_param( 'period', $frontend_param );
		$request->set_param( 'scope', 'all' );

		$response = $this->api->get_gateway_health( $request );

		$this->assertNotWPError( $response, 'Response should not be a WP_Error' );

		$data = $response->get_data();

		// Ensure it fetched the 24hour data record we manually injected
		$this->assertEquals( $frontend_param, $data['data']['period'], 'Response should mirror requested period' );
		$this->assertEquals( 50, $data['data']['failed_transactions'], 'It should retrieve the correct failed transactions amount for the mapped period' );
		$this->assertEquals( 90.0, $data['data']['success_rate'], 'It should retrieve the 24hour success rate from the period map' );
	}
}
