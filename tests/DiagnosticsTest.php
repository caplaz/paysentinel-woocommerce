<?php
/**
 * Unit tests for WC_Payment_Monitor_Diagnostics class
 * Tests diagnostic functionality and bug fixes
 */

class DiagnosticsTest extends PHPUnit\Framework\TestCase {

	/**
	 * Test that diagnostics class can be instantiated
	 */
	public function test_diagnostics_class_can_be_instantiated() {
		$diagnostics = new WC_Payment_Monitor_Diagnostics();
		$this->assertInstanceOf(WC_Payment_Monitor_Diagnostics::class, $diagnostics);
	}

	/**
	 * Test that check_database_health method exists and returns array
	 */
	public function test_check_database_health_method_exists() {
		$diagnostics = new WC_Payment_Monitor_Diagnostics();
		$result = $diagnostics->check_database_health();

		$this->assertIsArray($result);
		$this->assertArrayHasKey('status', $result);
		$this->assertArrayHasKey('tables', $result);
	}

	/**
	 * Test that check_all_gateways method exists and returns array
	 */
	public function test_check_all_gateways_method_exists() {
		$diagnostics = new WC_Payment_Monitor_Diagnostics();
		$result = $diagnostics->check_all_gateways();

		$this->assertIsArray($result);
		$this->assertArrayHasKey('gateways', $result);
		$this->assertArrayHasKey('issues', $result);
	}

	/**
	 * Test run_full_diagnostics returns expected structure
	 */
	public function test_run_full_diagnostics_structure() {
		$diagnostics = new WC_Payment_Monitor_Diagnostics();
		$result = $diagnostics->run_full_diagnostics();

		$this->assertIsArray($result);
		$this->assertArrayHasKey('timestamp', $result);
		$this->assertArrayHasKey('database', $result);
		$this->assertArrayHasKey('gateways', $result);
		$this->assertArrayHasKey('system_info', $result);
	}

	/**
	 * Test system info collection
	 */
	public function test_get_system_info() {
		$diagnostics = new WC_Payment_Monitor_Diagnostics();
		$result = $diagnostics->get_system_info();

		$this->assertIsArray($result);
		$this->assertArrayHasKey('php_version', $result);
		$this->assertArrayHasKey('wordpress_version', $result);
		$this->assertArrayHasKey('woocommerce_version', $result);
		$this->assertArrayHasKey('plugin_version', $result);
	}

	/**
	 * Test API diagnostics class can be instantiated
	 */
	public function test_api_diagnostics_class_can_be_instantiated() {
		$api = new WC_Payment_Monitor_API_Diagnostics();
		$this->assertInstanceOf(WC_Payment_Monitor_API_Diagnostics::class, $api);
	}

	/**
	 * Test that clean_orphaned method exists and can be called
	 */
	public function test_clean_orphaned_method_exists() {
		$api = new WC_Payment_Monitor_API_Diagnostics();
		$this->assertTrue(method_exists($api, 'clean_orphaned'));
	}

	/**
	 * Test that archive_transactions method exists and can be called
	 */
	public function test_archive_transactions_method_exists() {
		$api = new WC_Payment_Monitor_API_Diagnostics();
		$this->assertTrue(method_exists($api, 'archive_transactions'));
	}

	/**
	 * Test that maintenance endpoints are registered
	 */
	public function test_maintenance_endpoints_are_registered() {
		$api = new WC_Payment_Monitor_API_Diagnostics();
		$this->assertTrue(method_exists($api, 'register_routes'));
		
		// Test that the register_routes method can be called without errors
		$api->register_routes();
		$this->assertTrue(true); // If we get here, no fatal errors occurred
	}
}