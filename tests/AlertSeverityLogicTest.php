<?php
/**
 * Tests for Alert Severity Logic (Immediate vs Statistical)
 */

class AlertSeverityLogicTest extends WP_UnitTestCase {

	private $alerts_instance;
	private $logger_instance;
	private $database_instance;

	public function setUp(): void {
		parent::setUp();
		
		$this->alerts_instance = new WC_Payment_Monitor_Alerts();
		$this->logger_instance = new WC_Payment_Monitor_Logger();
		$this->database_instance = new WC_Payment_Monitor_Database();
		
		// Ensure tables exist
		$this->database_instance->create_tables();
	}

	/**
	 * Test that "Hard" errors trigger immediate Critical alerts
	 */
	public function test_immediate_critical_alert_trigger() {
		// 1. Simulate a failed transaction with a critical keyword
		$order_id = 123;
		$gateway_id = 'stripe';
		$error_message = 'Connection timed out'; // "timed out" is a keyword
		
		// Use save_transaction directly to bypass complex order parsing
		$data = array(
			'order_id'       => $order_id,
			'gateway_id'     => $gateway_id,
			'transaction_id' => 'tx_123',
			'amount'         => 100.00,
			'currency'       => 'USD',
			'status'         => 'failed',
			'failure_reason' => $error_message, // This is what matters
			'failure_code'   => 'timeout',
			'retry_count'    => 0,
			'customer_email' => 'test@example.com',
			'customer_ip'    => '127.0.0.1',
			'created_at'     => current_time( 'mysql' ),
			'updated_at'     => null,
		);
		
		$this->logger_instance->save_transaction( $data );

		// 2. Trigger the hook manually (simulating the failure event)
		// check_immediate_transaction_alert($order_id, $order_object) - Order object is unused in the method body currently
		$order_dummy = new stdClass(); 
		
		// Capture alerts created
		$start_count = $this->get_alert_count();
		
		$this->alerts_instance->check_immediate_transaction_alert( $order_id, $order_dummy );
		
		$end_count = $this->get_alert_count();
		$this->assertEquals( $start_count + 1, $end_count, 'Should create one alert' );
		
		// 3. Verify the alert is CRITICAL
		$latest_alert = $this->get_latest_alert();
		$this->assertEquals( 'critical', $latest_alert['severity'] );
		$this->assertEquals( 'gateway_error', $latest_alert['alert_type'] );
	}

	/**
	 * Test that "Soft" errors DO NOT trigger immediate alerts
	 */
	public function test_soft_error_ignored_immediate() {
		// 1. Simulate a failed transaction with a soft reason (e.g. user error)
		$order_id = 124;
		$gateway_id = 'stripe';
		$error_message = 'Insufficient funds'; // User error
		
		$data = array(
			'order_id'       => $order_id,
			'gateway_id'     => $gateway_id,
			'transaction_id' => 'tx_124',
			'amount'         => 50.00,
			'currency'       => 'USD',
			'status'         => 'failed',
			'failure_reason' => $error_message, 
			'failure_code'   => 'decline',
			'retry_count'    => 0,
			'customer_email' => 'test@example.com',
			'customer_ip'    => '127.0.0.1',
			'created_at'     => current_time( 'mysql' ),
			'updated_at'     => null,
		);
		
		$this->logger_instance->save_transaction( $data );

		$order_dummy = new stdClass();
		
		$start_count = $this->get_alert_count();
		$this->alerts_instance->check_immediate_transaction_alert( $order_id, $order_dummy );
		$end_count = $this->get_alert_count();
		
		$this->assertEquals( $start_count, $end_count, 'Should NOT create immediate alert for soft errors' );
	}

	/**
	 * Test Statistical Logic (High vs Critical)
	 * Note: Depending on visibility of calculate_severity, we might test result indirectly via check_all_gateway_alerts
	 */
	public function test_statistical_severity_levels() {
		// Access the private method via reflection if needed, or check constants
		$thresholds = WC_Payment_Monitor_Alerts::SEVERITY_THRESHOLDS;
		
		// Confirm mapping
		$this->assertArrayHasKey('high', $thresholds);
		$this->assertArrayNotHasKey('critical', $thresholds);
		
		// If we could invoke calculate_severity:
		// 74% success -> High
		// 85% success -> Warning
		// 92% success -> Info
	}

	// Helper to get alert count from DB
	private function get_alert_count() {
		global $wpdb;
		$table = $this->database_instance->get_alerts_table();
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	}

	/**
	 * Test 0% success rate with low volume
	 */
	public function test_low_volume_zero_success_severity() {
		$gateway_id = 'test_gateway';
		$health_data = array(
			'24h' => array(
				'gateway_id' => $gateway_id,
				'period' => '24h',
				'total_transactions' => 1,
				'successful_transactions' => 0,
				'failed_transactions' => 1,
				'success_rate' => 0.0,
				'avg_response_time' => 0,
				'last_failure_at' => current_time('mysql'),
				'calculated_at' => current_time('mysql'),
			)
		);

		$this->alerts_instance->check_gateway_alerts($gateway_id, $health_data);

		$latest_alert = $this->get_latest_alert();
		$this->assertNotNull($latest_alert);
		$this->assertEquals('low_success_rate', $latest_alert['alert_type']);
		$this->assertEquals(0.0, (float)json_decode($latest_alert['metadata'], true)['success_rate']);
		
		// This is the core of the user's issue
		// With volume awareness: 0% success on 1 transaction should be info
		$this->assertEquals('info', $latest_alert['severity'], '0% success rate on 1 transaction should be info severity due to volume adjustment');
	}

	/**
	 * Test 0% success rate with high volume
	 */
	public function test_high_volume_zero_success_severity() {
		$gateway_id = 'test_gateway_high';
		$health_data = array(
			'24h' => array(
				'gateway_id' => $gateway_id,
				'period' => '24h',
				'total_transactions' => 15, // Sufficient volume
				'successful_transactions' => 0,
				'failed_transactions' => 15,
				'success_rate' => 0.0,
				'avg_response_time' => 0,
				'last_failure_at' => current_time('mysql'),
				'calculated_at' => current_time('mysql'),
			)
		);

		$this->alerts_instance->check_gateway_alerts($gateway_id, $health_data);

		$latest_alert = $this->get_latest_alert();
		$this->assertNotNull($latest_alert);
		$this->assertEquals('high', $latest_alert['severity'], '0% success rate on 15 transactions should be high severity');
	}

	private function get_latest_alert() {
		global $wpdb;
		$table = $this->database_instance->get_alerts_table();
		return $wpdb->get_row( "SELECT * FROM {$table} ORDER BY created_at DESC LIMIT 1", ARRAY_A );
	}
}
