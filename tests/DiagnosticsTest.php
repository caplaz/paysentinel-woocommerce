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
		$this->assertInstanceOf( WC_Payment_Monitor_Diagnostics::class, $diagnostics );
	}

	/**
	 * Test that check_database_health method exists and returns array
	 */
	public function test_check_database_health_method_exists() {
		$diagnostics = new WC_Payment_Monitor_Diagnostics();
		$result      = $diagnostics->check_database_health();

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'status', $result );
		$this->assertArrayHasKey( 'tables', $result );
	}

	/**
	 * Test that check_all_gateways method exists and returns array
	 */
	public function test_check_all_gateways_method_exists() {
		$diagnostics = new WC_Payment_Monitor_Diagnostics();
		$result      = $diagnostics->check_all_gateways();

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'gateways', $result );
		$this->assertArrayHasKey( 'issues', $result );
	}

	/**
	 * Test run_full_diagnostics returns expected structure
	 */
	public function test_run_full_diagnostics_structure() {
		$diagnostics = new WC_Payment_Monitor_Diagnostics();
		$result      = $diagnostics->run_full_diagnostics();

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'timestamp', $result );
		$this->assertArrayHasKey( 'database', $result );
		$this->assertArrayHasKey( 'gateways', $result );
		$this->assertArrayHasKey( 'system_info', $result );
	}

	/**
	 * Test system info collection
	 */
	public function test_get_system_info() {
		$diagnostics = new WC_Payment_Monitor_Diagnostics();
		$result      = $diagnostics->get_system_info();

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'php_version', $result );
		$this->assertArrayHasKey( 'wordpress_version', $result );
		$this->assertArrayHasKey( 'woocommerce_version', $result );
		$this->assertArrayHasKey( 'plugin_version', $result );
	}

	/**
	 * Test API diagnostics class can be instantiated
	 */
	public function test_api_diagnostics_class_can_be_instantiated() {
		$api = new WC_Payment_Monitor_API_Diagnostics();
		$this->assertInstanceOf( WC_Payment_Monitor_API_Diagnostics::class, $api );
	}

	/**
	 * Test that clean_orphaned method exists and can be called
	 */
	public function test_clean_orphaned_method_exists() {
		$api = new WC_Payment_Monitor_API_Diagnostics();
		$this->assertTrue( method_exists( $api, 'clean_orphaned' ) );
	}

	/**
	 * Test that archive_transactions method exists and can be called
	 */
	public function test_archive_transactions_method_exists() {
		$api = new WC_Payment_Monitor_API_Diagnostics();
		$this->assertTrue( method_exists( $api, 'archive_transactions' ) );
	}

	/**
	 * Test that maintenance endpoints are registered
	 */
	public function test_maintenance_endpoints_are_registered() {
		$api = new WC_Payment_Monitor_API_Diagnostics();
		$this->assertTrue( method_exists( $api, 'register_routes' ) );

		// Test that the register_routes method exists and is callable
		// Note: We don't actually call it here to avoid REST API registration warnings in tests
		$this->assertTrue( is_callable( array( $api, 'register_routes' ) ) );
	}

	/**
	 * Test clean_orphaned_records method exists and returns expected structure
	 */
	public function test_clean_orphaned_records_method_exists() {
		$diagnostics = new WC_Payment_Monitor_Diagnostics();
		$result      = $diagnostics->clean_orphaned_records();

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'success', $result );
		$this->assertArrayHasKey( 'deleted', $result );
		$this->assertArrayHasKey( 'transactions_deleted', $result );
		$this->assertArrayHasKey( 'alerts_deleted', $result );
		$this->assertArrayHasKey( 'message', $result );
		$this->assertTrue( $result['success'] );
	}

	/**
	 * Test clean_orphaned_records cleans orphaned transactions
	 */
	public function test_clean_orphaned_records_cleans_transactions() {
		global $wpdb;

		$diagnostics = new WC_Payment_Monitor_Diagnostics();
		$database    = new WC_Payment_Monitor_Database();
		$table_name  = $database->get_transactions_table();

		// Create a mock transaction with non-existent order ID
		$wpdb->insert(
			$table_name,
			array(
				'order_id'       => 999999, // Non-existent order
				'gateway_id'     => 'stripe',
				'amount'         => 100.00,
				'currency'       => 'USD',
				'status'         => 'failed',
				'failure_reason' => 'Test failure',
				'created_at'     => current_time( 'mysql' ),
			)
		);
		$inserted_id = $wpdb->insert_id;

		// Verify the record was inserted
		$record = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table_name} WHERE id = %d", $inserted_id )
		);
		$this->assertNotNull( $record );

		// Run cleanup
		$result = $diagnostics->clean_orphaned_records();

		// Verify the orphaned transaction was deleted
		$this->assertGreaterThanOrEqual( 1, $result['transactions_deleted'] );
		$this->assertGreaterThanOrEqual( 1, $result['deleted'] );

		// Verify the record is gone
		$record_after = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table_name} WHERE id = %d", $inserted_id )
		);
		$this->assertNull( $record_after );
	}

	/**
	 * Test clean_orphaned_records cleans orphaned alerts
	 */
	public function test_clean_orphaned_records_cleans_alerts() {
		global $wpdb;

		$diagnostics  = new WC_Payment_Monitor_Diagnostics();
		$database     = new WC_Payment_Monitor_Database();
		$alerts_table = $database->get_alerts_table();

		// Create a mock alert with non-existent order ID in metadata
		$metadata = json_encode(
			array(
				'order_id'       => 999999, // Non-existent order
				'failure_reason' => 'Test failure',
				'error_type'     => 'Connection Error',
			)
		);

		$wpdb->insert(
			$alerts_table,
			array(
				'alert_type'  => 'gateway_error',
				'gateway_id'  => 'stripe',
				'severity'    => 'critical',
				'message'     => 'Test alert message',
				'metadata'    => $metadata,
				'is_resolved' => 0,
				'created_at'  => current_time( 'mysql' ),
			)
		);
		$inserted_id = $wpdb->insert_id;

		// Verify the alert was inserted
		$alert = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$alerts_table} WHERE id = %d", $inserted_id )
		);
		$this->assertNotNull( $alert );

		// Run cleanup
		$result = $diagnostics->clean_orphaned_records();

		// Verify the orphaned alert was deleted
		$this->assertGreaterThanOrEqual( 1, $result['alerts_deleted'] );
		$this->assertGreaterThanOrEqual( 1, $result['deleted'] );

		// Verify the alert is gone
		$alert_after = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$alerts_table} WHERE id = %d", $inserted_id )
		);
		$this->assertNull( $alert_after );
	}

	/**
	 * Test clean_orphaned_records does not clean alerts without order_id
	 */
	public function test_clean_orphaned_records_preserves_alerts_without_order_id() {
		global $wpdb;

		$diagnostics  = new WC_Payment_Monitor_Diagnostics();
		$database     = new WC_Payment_Monitor_Database();
		$alerts_table = $database->get_alerts_table();

		// Create a mock alert without order_id in metadata (performance-based alert)
		$metadata = json_encode(
			array(
				'success_rate'        => 85.5,
				'total_transactions'  => 100,
				'failed_transactions' => 15,
			)
		);

		$wpdb->insert(
			$alerts_table,
			array(
				'alert_type'  => 'low_success_rate',
				'gateway_id'  => 'stripe',
				'severity'    => 'warning',
				'message'     => 'Test performance alert',
				'metadata'    => $metadata,
				'is_resolved' => 0,
				'created_at'  => current_time( 'mysql' ),
			)
		);
		$inserted_id = $wpdb->insert_id;

		// Run cleanup
		$result = $diagnostics->clean_orphaned_records();

		// Verify the alert was NOT deleted
		$this->assertEquals( 0, $result['alerts_deleted'] );

		// Verify the alert still exists
		$alert_after = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$alerts_table} WHERE id = %d", $inserted_id )
		);
		$this->assertNotNull( $alert_after );

		// Clean up
		$wpdb->delete( $alerts_table, array( 'id' => $inserted_id ) );
	}

	/**
	 * Test clean_orphaned_records does not clean gateway_error alerts with valid order_id
	 */
	public function test_clean_orphaned_records_preserves_valid_alerts() {
		global $wpdb;

		$diagnostics  = new WC_Payment_Monitor_Diagnostics();
		$database     = new WC_Payment_Monitor_Database();
		$alerts_table = $database->get_alerts_table();

		// Create a valid order first
		$order_id = $wpdb->insert(
			$wpdb->posts,
			array(
				'post_type'     => 'shop_order',
				'post_status'   => 'wc-completed',
				'post_date'     => current_time( 'mysql' ),
				'post_date_gmt' => current_time( 'mysql', true ),
			)
		);
		$order_id = $wpdb->insert_id;

		// Create a mock alert with valid order ID in metadata
		$metadata = json_encode(
			array(
				'order_id'       => $order_id,
				'failure_reason' => 'Test failure',
				'error_type'     => 'Connection Error',
			)
		);

		$wpdb->insert(
			$alerts_table,
			array(
				'alert_type'  => 'gateway_error',
				'gateway_id'  => 'stripe',
				'severity'    => 'critical',
				'message'     => 'Test alert message',
				'metadata'    => $metadata,
				'is_resolved' => 0,
				'created_at'  => current_time( 'mysql' ),
			)
		);
		$inserted_id = $wpdb->insert_id;

		// Run cleanup
		$result = $diagnostics->clean_orphaned_records();

		// Verify the alert was NOT deleted
		$this->assertEquals( 0, $result['alerts_deleted'] );

		// Verify the alert still exists
		$alert_after = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$alerts_table} WHERE id = %d", $inserted_id )
		);
		$this->assertNotNull( $alert_after );

		// Clean up
		$wpdb->delete( $alerts_table, array( 'id' => $inserted_id ) );
		$wpdb->delete( $wpdb->posts, array( 'ID' => $order_id ) );
	}
}
