<?php

/**
 * Tests that REST API routes are registered correctly.
 *
 * @package PaySentinel\Tests\API
 */
class RESTAPIRouteRegistrationTest extends PaySentinel_Test_Case {

	public function test_api_routes_are_registered() {
		// Ensure REST API is initialized.
		PaySentinel::get_instance()->init_api_endpoints();

		$server = rest_get_server();
		$routes = $server->get_routes();

		$this->assertArrayHasKey( '/paysentinel/v1/health/gateways', $routes, 'Health gateways route should be registered' );
		$this->assertArrayHasKey( '/paysentinel/v1/transactions', $routes, 'Transactions route should be registered' );
		$this->assertArrayHasKey( '/paysentinel/v1/alerts', $routes, 'Alerts route should be registered' );
	}

	public function test_api_namespace_is_correct() {
		$api = new class() extends PaySentinel_API_Base {
			public function register_routes() {}
		};

		$reflection = new ReflectionClass( $api );
		$property   = $reflection->getProperty( 'namespace' );
		$property->setAccessible( true );

		$this->assertEquals( 'paysentinel/v1', $property->getValue( $api ), 'API namespace should be paysentinel/v1' );
	}
}
