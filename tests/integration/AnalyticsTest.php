<?php
/**
 * Integration tests for Analytics and Reporting.
 *
 * @package PaySentinel\Tests\Integration
 */
class AnalyticsTest extends WP_UnitTestCase {


	/**
	 * Analytics service under test.
	 *
	 * @var PaySentinel_Analytics_Pro
	 */
	private $analytics;

	/**
	 * Health service used by analytics.
	 *
	 * @var PaySentinel_Health
	 */
	private $health;

	/**
	 * Logger instance for tests.
	 *
	 * @var PaySentinel_Logger
	 */
	private $logger;

	/**
	 * Database helper used in tests.
	 *
	 * @var PaySentinel_Database
	 */
	private $database;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->analytics = new PaySentinel_Analytics_Pro();
		$this->health    = new PaySentinel_Health();
		$this->logger    = new PaySentinel_Logger();
		$this->database  = new PaySentinel_Database();

		// Ensure fresh schema (PaySentinel_Database::create_gateway_health_table now drops the legacy unique index).
		$this->database->create_tables();

		// Clean up tables before each test.
		global $wpdb;
		$wpdb->query( "TRUNCATE TABLE {$this->database->get_transactions_table()}" );
		$wpdb->query( "TRUNCATE TABLE {$this->database->get_gateway_health_table()}" );

		// Set PRO license to enable all features.
		update_option( 'paysentinel_license_status', 'valid' );
		update_option( 'paysentinel_license_data', array( 'plan' => 'pro' ) );
	}

	/**
	 * Helper to insert transactions with custom data.
	 *
	 * @param string      $gateway    Gateway identifier.
	 * @param string      $status     Transaction status.
	 * @param string      $created_at Creation timestamp in MySQL format.
	 * @param string|null $reason     Optional failure reason.
	 * @param string|null $code       Optional failure code.
	 *  @return void
	 */
	private function insert_transaction( $gateway, $status, $created_at, $reason = null, $code = null ) {
		global $wpdb;
		$wpdb->insert(
			$this->database->get_transactions_table(),
			array(
				'order_id'       => rand( 1000, 9999 ),
				'gateway_id'     => $gateway,
				'transaction_id' => 'abc_' . rand( 100, 999 ),
				'amount'         => 100.00,
				'currency'       => 'USD',
				'status'         => $status,
				'failure_reason' => $reason,
				'failure_code'   => $code,
				'created_at'     => $created_at,
			)
		);
	}

	/**
	 * Test health calculation from raw transaction data
	 */
	public function test_health_calculation_aggregation() {
		$gateway = 'stripe';
		$now     = current_time( 'mysql' );

		// 1. Insert 10 transactions: 8 success, 2 failed.
		for ( $i = 0; $i < 8; $i++ ) {
			$this->insert_transaction( $gateway, 'success', $now );
		}
		for ( $i = 0; $i < 2; $i++ ) {
			$this->insert_transaction( $gateway, 'failed', $now );
		}

		// 2. Calculate health.
		$this->health->calculate_health( $gateway );

		// 3. Verify health record in DB.
		global $wpdb;
		$row = $wpdb->get_row( "SELECT * FROM {$this->database->get_gateway_health_table()} WHERE gateway_id = '$gateway' AND period = '1hour'" );

		$this->assertNotNull( $row );
		$this->assertEquals( 10, $row->total_transactions );
		$this->assertEquals( 8, $row->successful_transactions );
		$this->assertEquals( 2, $row->failed_transactions );
		$this->assertEquals( 80.00, floatval( $row->success_rate ) );
	}

	/**
	 * Test failure pattern analysis
	 */
	public function test_failure_pattern_analysis() {
		$gateway = 'paypal';
		$now     = current_time( 'mysql' );

		// Insert failures with specific reasons.
		$this->insert_transaction( $gateway, 'failed', $now, 'Insufficient funds', '10001' );
		$this->insert_transaction( $gateway, 'failed', $now, 'Insufficient funds', '10001' );
		$this->insert_transaction( $gateway, 'failed', $now, 'Insufficient funds', '10001' );
		$this->insert_transaction( $gateway, 'failed', $now, 'Invalid card', '10002' );
		$this->insert_transaction( $gateway, 'failed', $now, 'Invalid card', '10002' );
		$this->insert_transaction( $gateway, 'failed', $now, 'Generic error', '500' );

		$analysis = $this->analytics->get_failure_pattern_analysis( $gateway, 1 );

		$this->assertEquals( $gateway, $analysis['gateway_id'] );
		$this->assertCount( 3, $analysis['top_failure_reasons'] );

		$top = $analysis['top_failure_reasons'][0];
		$this->assertEquals( 'Insufficient funds', $top['failure_reason'] );
		$this->assertEquals( 3, $top['count'] );
	}

	/**
	 * Test comparative analytics and trends
	 */
	public function test_comparative_analytics_and_trends() {
		$gateway = 'stripe';

		// Mock health data for different periods.
		global $wpdb;
		$table = $this->database->get_gateway_health_table();
		$now   = current_time( 'mysql' );

		$wpdb->insert(
			$table,
			array(
				'gateway_id'         => $gateway,
				'period'             => '24hour',
				'success_rate'       => 95.0,
				'total_transactions' => 100,
				'calculated_at'      => $now,
			)
		);
		$wpdb->insert(
			$table,
			array(
				'gateway_id'         => $gateway,
				'period'             => '7day',
				'success_rate'       => 90.0,
				'total_transactions' => 700,
				'calculated_at'      => $now,
			)
		);

		$analytics = $this->analytics->get_comparative_analytics( $gateway );

		$this->assertArrayHasKey( 'periods', $analytics );
		$this->assertEquals( 95.0, $analytics['periods']['24hour']['success_rate'] );
		$this->assertEquals( 90.0, $analytics['periods']['7day']['success_rate'] );

		$this->assertArrayHasKey( '24h_vs_7d', $analytics['trends'] );
		$this->assertEquals( 'improving', $analytics['trends']['24h_vs_7d']['direction'] );
		$this->assertEquals( 5.0, $analytics['trends']['24h_vs_7d']['success_rate_change'] );
	}

	/**
	 * Test data cleanup
	 */
	public function test_analytics_data_cleanup() {
		global $wpdb;
		$table = $this->database->get_transactions_table();

		// Insert old transaction (95 days ago).
		$old_date = gmdate( 'Y-m-d H:i:s', time() - ( 95 * DAY_IN_SECONDS ) );
		$wpdb->insert(
			$table,
			array(
				'order_id'   => 1,
				'gateway_id' => 'stripe',
				'status'     => 'success',
				'amount'     => 10.00,
				'currency'   => 'USD',
				'created_at' => $old_date,
			)
		);

		// Insert recent transaction.
		$wpdb->insert(
			$table,
			array(
				'order_id'   => 2,
				'gateway_id' => 'stripe',
				'status'     => 'success',
				'amount'     => 10.00,
				'currency'   => 'USD',
				'created_at' => current_time( 'mysql' ),
			)
		);

		$deleted = $this->database->cleanup_old_transactions( 90 );
		$this->assertEquals( 1, $deleted );
		$this->assertEquals( 1, $wpdb->get_var( "SELECT COUNT(*) FROM $table" ) );
	}

	/**
	 * Test advanced metrics summary (multi-gateway)
	 */
	public function test_advanced_metrics_summary() {
		update_option( 'paysentinel_settings', array( 'enabled_gateways' => array( 'stripe', 'paypal' ) ) );

		// Insert health for stripe.
		global $wpdb;
		$table = $this->database->get_gateway_health_table();
		$wpdb->insert(
			$table,
			array(
				'gateway_id'         => 'stripe',
				'period'             => '24hour',
				'success_rate'       => 95.0,
				'total_transactions' => 100,
				'calculated_at'      => current_time( 'mysql' ),
			)
		);

		$summary = $this->analytics->get_advanced_metrics_summary();

		$this->assertEquals( 2, $summary['total_gateways'] );
		$this->assertArrayHasKey( 'stripe', $summary['gateway_metrics'] );
		$this->assertEquals( 95.0, $summary['gateway_metrics']['stripe']['periods']['24hour']['success_rate'] );
	}

	/**
	 * Test extended history
	 */
	public function test_extended_history() {
		$gateway = 'stripe';
		global $wpdb;
		$table = $this->database->get_gateway_health_table();

		// Insert health for 3 different days.
		$wpdb->insert(
			$table,
			array(
				'gateway_id'    => $gateway,
				'period'        => '24hour',
				'success_rate'  => 90.0,
				'calculated_at' => gmdate( 'Y-m-d H:i:s', time() - ( 10 * DAY_IN_SECONDS ) ),
			)
		);
		$wpdb->insert(
			$table,
			array(
				'gateway_id'    => $gateway,
				'period'        => '24hour',
				'success_rate'  => 95.0,
				'calculated_at' => gmdate( 'Y-m-d H:i:s', time() - ( 5 * DAY_IN_SECONDS ) ),
			)
		);

		$history = $this->analytics->get_extended_history( $gateway, 30 );
		$this->assertCount( 2, $history );
		$this->assertEquals( 90.0, floatval( $history[0]['success_rate'] ) );
	}

	/**
	 * Test gateway comparison and ranking
	 */
	public function test_gateway_comparison_ranking() {
		update_option( 'paysentinel_settings', array( 'enabled_gateways' => array( 'stripe', 'paypal' ) ) );
		global $wpdb;
		$table = $this->database->get_gateway_health_table();
		$now   = current_time( 'mysql' );

		// Stripe 99%.
		$wpdb->insert(
			$table,
			array(
				'gateway_id'    => 'stripe',
				'period'        => '24hour',
				'success_rate'  => 99.0,
				'calculated_at' => $now,
			)
		);
		// PayPal 80%.
		$wpdb->insert(
			$table,
			array(
				'gateway_id'    => 'paypal',
				'period'        => '24hour',
				'success_rate'  => 80.0,
				'calculated_at' => $now,
			)
		);

		$comparison = $this->analytics->get_gateway_comparison();

		$this->assertEquals( array( 'stripe', 'paypal' ), $comparison['rankings']['by_success_rate'] );
		$this->assertEquals( 99.0, $comparison['gateways']['stripe']['24hour']['success_rate'] );
	}

	/**
	 * Test health summary statistics
	 */
	public function test_health_summary_stats() {
		global $wpdb;
		$table = $this->database->get_gateway_health_table();
		$now   = current_time( 'mysql' );

		$wpdb->insert(
			$table,
			array(
				'gateway_id'              => 'stripe',
				'period'                  => '24hour',
				'success_rate'            => 100.0,
				'total_transactions'      => 10,
				'successful_transactions' => 10,
				'calculated_at'           => $now,
			)
		);
		$wpdb->insert(
			$table,
			array(
				'gateway_id'              => 'paypal',
				'period'                  => '24hour',
				'success_rate'            => 50.0,
				'total_transactions'      => 10,
				'successful_transactions' => 5,
				'calculated_at'           => $now,
			)
		);

		$stats = $this->health->get_summary_stats( '24hour' );

		$this->assertEquals( 2, $stats['total_gateways'] );
		$this->assertEquals( 75.0, $stats['overall_success_rate'] );
		$this->assertEquals( 50.0, floatval( $stats['min_success_rate'] ) );
	}
	/**
	 * Test PRO analytics availability check
	 */
	public function test_pro_analytics_availability() {
		// Free tier - should NOT have access
		update_option( 'paysentinel_license_status', 'invalid' );
		$this->assertFalse( $this->analytics->is_pro_analytics_available() );

		// Starter tier - should NOT have access
		update_option( 'paysentinel_license_status', 'valid' );
		update_option( 'paysentinel_license_data', array( 'plan' => 'starter' ) );
		$this->assertFalse( $this->analytics->is_pro_analytics_available() );

		// Pro tier - should HAVE access
		update_option( 'paysentinel_license_data', array( 'plan' => 'pro' ) );
		$this->assertTrue( $this->analytics->is_pro_analytics_available() );

		// Agency tier - should HAVE access
		update_option( 'paysentinel_license_data', array( 'plan' => 'agency' ) );
		$this->assertTrue( $this->analytics->is_pro_analytics_available() );
	}

	/**
	 * Test trend calculations with partial data
	 */
	public function test_trend_calculations_partial_data() {
		// Only 7day data, no 24hour data
		$periods = array(
			'7day' => array( 'success_rate' => 90.0 ),
		);

		$reflection = new ReflectionClass( $this->analytics );
		$method     = $reflection->getMethod( 'calculate_trends' );
		$method->setAccessible( true );

		$trends = $method->invoke( $this->analytics, $periods );

		$this->assertEmpty( $trends );
	}
}
