<?php

/**
 * Real Integration Tests for Logger Statistics
 */
class TransactionStatsTest extends WP_UnitTestCase {

	private $logger;

	public function setUp(): void {
		parent::setUp();

		if ( ! class_exists( 'WooCommerce' ) ) {
			if ( function_exists( 'WC' ) ) {
				WC();
			} else {
				$this->markTestSkipped( 'WooCommerce not active.' );
			}
		}

		$this->logger = new PaySentinel_Logger();

		// Clean up table for isolated tests
		global $wpdb;
		$table_name = ( new PaySentinel_Database() )->get_transactions_table();
		$wpdb->query( "TRUNCATE TABLE {$table_name}" );
	}

	public function tearDown(): void {
		parent::tearDown();
		// Clean up table after tests
		global $wpdb;
		$table_name = ( new PaySentinel_Database() )->get_transactions_table();
		$wpdb->query( "TRUNCATE TABLE {$table_name}" );
	}

	/**
	 * Test that transactions created long ago but updated recently are included in recent stats.
	 */
	public function test_get_transaction_stats_includes_recent_updates_with_old_creation() {
		global $wpdb;
		$table_name = ( new PaySentinel_Database() )->get_transactions_table();

		$gateway_id = 'test_gateway_stats';

		// Simulating an order created 3 days ago
		$three_days_ago = date_create( current_time( 'mysql' ) )->modify( '-3 days' )->format( 'Y-m-d H:i:s' );

		// 1. Transaction created old, but failed NOW (updated_at)
		$now = current_time( 'mysql' );
		$wpdb->insert(
			$table_name,
			array(
				'order_id'   => 9901,
				'gateway_id' => $gateway_id,
				'amount'     => 50.00,
				'currency'   => 'USD',
				'status'     => 'failed',
				'created_at' => $three_days_ago,
				'updated_at' => $now,
			)
		);

		// 2. Transaction created old, successfully updated old (should not be in recent stats)
		$wpdb->insert(
			$table_name,
			array(
				'order_id'   => 9902,
				'gateway_id' => $gateway_id,
				'amount'     => 20.00,
				'currency'   => 'USD',
				'status'     => 'success',
				'created_at' => $three_days_ago,
				'updated_at' => $three_days_ago,
			)
		);

		// Get stats for the last hour
		$stats = $this->logger->get_transaction_stats( $gateway_id, 3600 );

		// It should find exactly 1 transaction because the failed one was updated recently
		$this->assertEquals( 1, $stats['total_transactions'], 'Failed transaction with recent updated_at should be returned' );
		$this->assertEquals( 1, $stats['failed_transactions'] );
		$this->assertEquals( 0, $stats['successful_transactions'] );
	}
}
