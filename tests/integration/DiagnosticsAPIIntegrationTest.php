<?php

/**
 * Integration tests for Diagnostics API response format
 *
 * Ensures that diagnostics and maintenance endpoints return data in a structure
 * consistent with what the JavaScript frontend expects, preventing "undefined" errors.
 */
class DiagnosticsAPIIntegrationTest extends WP_UnitTestCase
{

    /**
     * Diagnostics API instance
     */
    private $api;

    /**
     * Admin user instance
     */
    private $admin_user;

    /**
     * Setup test
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->api = new PaySentinel_API_Diagnostics();

        // Create admin user for API access
        $this->admin_user = $this->factory->user->create(array('role' => 'administrator'));
        wp_set_current_user($this->admin_user);
    }

    /**
     * Test that Maintenance endpoints return a consistent structure with 'data.message'
     *
     * This protects against the regression where JS code expects response.message
     * but the API returns response.data.message.
     */
    public function test_maintenance_endpoints_return_consistent_message_structure()
    {
        $endpoints = array(
            '/paysentinel/v1/diagnostics/maintenance/orphaned' => 'clean_orphaned',
            '/paysentinel/v1/diagnostics/health/recalculate' => 'recalculate_health',
            '/paysentinel/v1/diagnostics/health/reset' => 'reset_health',
            '/paysentinel/v1/diagnostics/maintenance/archive' => 'archive_transactions',
            '/paysentinel/v1/simulator/clear' => 'clear_simulated_failures',
        );

        foreach ($endpoints as $route => $method) {
            $request = new WP_REST_Request('POST', $route);

            // Call the method directly
            $response = $this->api->$method($request);

            $this->assertInstanceOf('WP_REST_Response', $response, "Method $method for route $route did not return WP_REST_Response");

            $data = $response->get_data();

            // Universal Property: Success response must have 'success' and 'data' keys
            $this->assertIsArray($data, "Response from $route should be an array");
            $this->assertArrayHasKey('success', $data, "Response from $route missing 'success' key");
            $this->assertTrue($data['success'], "Response from $route should indicate success");
            $this->assertArrayHasKey('data', $data, "Response from $route missing 'data' key (JS expects data.message)");

            // Verified Payload: The message must be inside the 'data' object
            $this->assertIsArray($data['data'], "The 'data' property in $route response must be an array/object");
            $this->assertArrayHasKey('message', $data['data'], "The 'message' property in $route must be inside 'data' (JS expects response.data.message)");
            $this->assertIsString($data['data']['message']);
            $this->assertNotEmpty($data['data']['message']);
        }
    }

    /**
     * Test that Simulation Success returns the expected structure
     */
    public function test_simulation_success_returns_expected_structure()
    {
        $request = new WP_REST_Request('POST', '/paysentinel/v1/simulator/simulate');
        $request->set_param('scenario', 'card_declined');
        $request->set_param('count', 1);

        $response = $this->api->simulate_failure($request);
        $data = $response->get_data();

        $this->assertArrayHasKey('success', $data);
        $this->assertTrue($data['success']);
        $this->assertArrayHasKey('data', $data);
        $this->assertArrayHasKey('message', $data['data'], "JS expects response.data.message for single simulation");
    }

    /**
     * Test that Full Diagnostics returns the expected structure for table rendering
     */
    public function test_full_diagnostics_returns_expected_structure()
    {
        $request = new WP_REST_Request('GET', '/paysentinel/v1/diagnostics/full');

        $response = $this->api->get_full_diagnostics();
        $data = $response->get_data();

        $this->assertArrayHasKey('success', $data);
        $this->assertArrayHasKey('data', $data);

        $payload = $data['data'];
        $this->assertIsArray($payload);

        // Ensure required sections for JS rendering are present
        $this->assertArrayHasKey('timestamp', $payload);
        $this->assertArrayHasKey('system_info', $payload);
        $this->assertArrayHasKey('database', $payload);
        $this->assertArrayHasKey('gateways', $payload);
    }
}
