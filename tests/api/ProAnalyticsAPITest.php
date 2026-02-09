<?php

/**
 * Tests for PRO tier analytics REST API endpoints
 */
class ProAnalyticsAPITest extends WP_UnitTestCase {

	private $api;
	private $license;

	/**
	 * Set up test environment
	 */
	public function setUp(): void {
		parent::setUp();
		$this->api     = new WC_Payment_Monitor_API_Analytics_Pro();
		$this->license = new WC_Payment_Monitor_License();

		// Create admin user for API access
		$this->admin_user = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_user );
	}

	/**
	 * Test comparative analytics endpoint with free tier
	 */
	public function test_comparative_analytics_endpoint_free_tier() {
		update_option( 'wc_payment_monitor_license_status', 'invalid' );

		$request  = new WP_REST_Request( 'GET', '/wc-payment-monitor/v1/analytics/comparative/stripe' );
		$response = $this->api->get_comparative_analytics( $request );

		$this->assertInstanceOf( 'WP_REST_Response', $response );
		$data = $response->get_data();

		$this->assertFalse( $data['success'] );
		$this->assertArrayHasKey( 'error', $data );
	}

	/**
	 * Test comparative analytics endpoint with PRO tier
	 */
	public function test_comparative_analytics_endpoint_pro_tier() {
		update_option( 'wc_payment_monitor_license_status', 'valid' );
		update_option( 'wc_payment_monitor_license_data', array( 'plan' => 'pro' ) );

		$request = new WP_REST_Request( 'GET', '/wc-payment-monitor/v1/analytics/comparative/stripe' );
		$request->set_param( 'gateway_id', 'stripe' );
		$response = $this->api->get_comparative_analytics( $request );

		$this->assertInstanceOf( 'WP_REST_Response', $response );
		$data = $response->get_data();

		$this->assertTrue( $data['success'] );
		$this->assertArrayHasKey( 'data', $data );
		$this->assertArrayHasKey( 'gateway_id', $data['data'] );
	}

	/**
	 * Test failure patterns endpoint gating
	 */
	public function test_failure_patterns_endpoint_gating() {
		// Free tier - should fail
		update_option( 'wc_payment_monitor_license_status', 'invalid' );

		$request = new WP_REST_Request( 'GET', '/wc-payment-monitor/v1/analytics/failure-patterns/stripe' );
		$request->set_param( 'gateway_id', 'stripe' );
		$request->set_param( 'days', 30 );
		$response = $this->api->get_failure_patterns( $request );

		$data = $response->get_data();
		$this->assertFalse( $data['success'] );

		// PRO tier - should succeed
		update_option( 'wc_payment_monitor_license_status', 'valid' );
		update_option( 'wc_payment_monitor_license_data', array( 'plan' => 'pro' ) );

		$response = $this->api->get_failure_patterns( $request );
		$data     = $response->get_data();
		$this->assertTrue( $data['success'] );
		$this->assertArrayHasKey( 'top_failure_reasons', $data['data'] );
	}

	/**
	 * Test metrics summary endpoint
	 */
	public function test_metrics_summary_endpoint() {
		// Free tier - should fail
		update_option( 'wc_payment_monitor_license_status', 'invalid' );

		$request  = new WP_REST_Request( 'GET', '/wc-payment-monitor/v1/analytics/metrics-summary' );
		$response = $this->api->get_metrics_summary( $request );

		$data = $response->get_data();
		$this->assertFalse( $data['success'] );

		// PRO tier - should succeed
		update_option( 'wc_payment_monitor_license_status', 'valid' );
		update_option( 'wc_payment_monitor_license_data', array( 'plan' => 'pro' ) );
		update_option( 'wc_payment_monitor_settings', array( 'enabled_gateways' => array( 'stripe' ) ) );

		$response = $this->api->get_metrics_summary( $request );
		$data     = $response->get_data();
		$this->assertTrue( $data['success'] );
		$this->assertArrayHasKey( 'total_gateways', $data['data'] );
	}

	/**
	 * Test extended history endpoint
	 */
	public function test_extended_history_endpoint() {
		// Free tier - should fail
		update_option( 'wc_payment_monitor_license_status', 'invalid' );

		$request = new WP_REST_Request( 'GET', '/wc-payment-monitor/v1/analytics/extended-history/stripe' );
		$request->set_param( 'gateway_id', 'stripe' );
		$request->set_param( 'days', 90 );
		$response = $this->api->get_extended_history( $request );

		$data = $response->get_data();
		$this->assertFalse( $data['success'] );

		// PRO tier - should succeed
		update_option( 'wc_payment_monitor_license_status', 'valid' );
		update_option( 'wc_payment_monitor_license_data', array( 'plan' => 'pro' ) );

		$response = $this->api->get_extended_history( $request );
		$data     = $response->get_data();
		$this->assertTrue( $data['success'] );
	}

	/**
	 * Test gateway comparison endpoint
	 */
	public function test_gateway_comparison_endpoint() {
		// Free tier - should fail
		update_option( 'wc_payment_monitor_license_status', 'invalid' );

		$request  = new WP_REST_Request( 'GET', '/wc-payment-monitor/v1/analytics/gateway-comparison' );
		$response = $this->api->get_gateway_comparison( $request );

		$data = $response->get_data();
		$this->assertFalse( $data['success'] );

		// PRO tier - should succeed
		update_option( 'wc_payment_monitor_license_status', 'valid' );
		update_option( 'wc_payment_monitor_license_data', array( 'plan' => 'pro' ) );

		$response = $this->api->get_gateway_comparison( $request );
		$data     = $response->get_data();
		$this->assertTrue( $data['success'] );
		$this->assertArrayHasKey( 'gateways', $data['data'] );
		$this->assertArrayHasKey( 'rankings', $data['data'] );
	}

	/**
	 * Test missing gateway_id parameter handling
	 */
	public function test_missing_gateway_id_parameter() {
		update_option( 'wc_payment_monitor_license_status', 'valid' );
		update_option( 'wc_payment_monitor_license_data', array( 'plan' => 'pro' ) );

		$request = new WP_REST_Request( 'GET', '/wc-payment-monitor/v1/analytics/comparative/stripe' );
		// Don't set gateway_id parameter
		$response = $this->api->get_comparative_analytics( $request );

		$this->assertInstanceOf( 'WP_REST_Response', $response );
		$data = $response->get_data();
		$this->assertFalse( $data['success'] );
	}
}
