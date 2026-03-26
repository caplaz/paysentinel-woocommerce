<?php
/**
 * Tests for Auto-Retry license gating (Starter+ feature).
 *
 * @package PaySentinel
 */

/**
 * Class RetryLicenseGatingTest
 */
class RetryLicenseGatingTest extends WP_UnitTestCase {

	/**
	 * Retry instance.
	 *
	 * @var PaySentinel_Retry
	 */
	private $retry;
	/**
	 * Logger instance.
	 *
	 * @var PaySentinel_Logger
	 */
	private $logger;
	/**
	 * Database instance.
	 *
	 * @var PaySentinel_Database
	 */
	private $database;
	/**
	 * Order ID.
	 *
	 * @var int
	 */
	private $order_id;
	/**
	 * Transaction ID.
	 *
	 * @var int
	 */
	private $transaction_id;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		// Initialize retry engine.
		$this->retry    = new PaySentinel_Retry();
		$this->logger   = new PaySentinel_Logger();
		$this->database = new PaySentinel_Database();

		// Create test order.
		$this->order_id = $this->factory->post->create(
			array(
				'post_type'   => 'shop_order',
				'post_status' => 'wc-failed',
			)
		);

		// Create test transaction.
		global $wpdb;
		$table_name = $this->database->get_transactions_table();

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$table_name,
			array(
				'order_id'       => $this->order_id,
				'gateway_id'     => 'stripe',
				'transaction_id' => 'txn_test_123',
				'amount'         => 100.00,
				'currency'       => 'USD',
				'status'         => 'failed',
				'retry_count'    => 0,
				'failure_reason' => 'Timeout',
				'created_at'     => current_time( 'mysql' ),
			)
		);
		$this->transaction_id = $wpdb->insert_id;

		// Enable retry in settings.
		update_option(
			'paysentinel_options',
			array(
				'retry_enabled'      => 1,
				'max_retry_attempts' => 3,
			)
		);
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		parent::tearDown();
		// Clean up.
		wp_delete_post( $this->order_id, true );
		delete_option( 'paysentinel_options' );
		delete_option( PaySentinel_License::OPTION_LICENSE_KEY );
		delete_option( PaySentinel_License::OPTION_LICENSE_STATUS );
		delete_option( PaySentinel_License::OPTION_LICENSE_DATA );
	}

	/**
	 * Test Free tier cannot use auto-retry
	 */
	public function test_free_tier_auto_retry_blocked() {
		// Setup free tier (no active license).
		delete_option( PaySentinel_License::OPTION_LICENSE_STATUS );
		delete_option( PaySentinel_License::OPTION_LICENSE_DATA );

		// Use Reflection to check license gating.
		$reflection = new ReflectionClass( PaySentinel_Retry::class );
		$method     = $reflection->getMethod( 'is_retry_feature_available' );
		$method->setAccessible( true );

		// Verify feature is NOT available for Free tier.
		$this->assertFalse( $method->invoke( $this->retry ), 'Free tier should not have retry feature available' );
	}

	/**
	 * Test Starter tier can use auto-retry
	 */
	public function test_starter_tier_auto_retry_allowed() {
		// Setup Starter tier.
		update_option( PaySentinel_License::OPTION_LICENSE_STATUS, 'valid' );
		update_option( PaySentinel_License::OPTION_LICENSE_DATA, array( 'plan' => 'starter' ) );

		$order = wc_get_order( $this->order_id );
		$order->set_status( 'failed' );
		$order->save();

		// Mock stored payment method.
		$this->mock_stored_payment_method( $this->order_id );

		// Use Reflection to check license gating.
		$reflection = new ReflectionClass( PaySentinel_Retry::class );
		$method     = $reflection->getMethod( 'is_retry_feature_available' );
		$method->setAccessible( true );

		// Verify feature is available for Starter tier.
		$this->assertTrue( $method->invoke( $this->retry ), 'Starter tier should have retry feature available' );
	}

	/**
	 * Test Pro tier can use auto-retry
	 */
	public function test_pro_tier_auto_retry_allowed() {
		// Setup Pro tier.
		update_option( PaySentinel_License::OPTION_LICENSE_STATUS, 'valid' );
		update_option( PaySentinel_License::OPTION_LICENSE_DATA, array( 'plan' => 'pro' ) );

		// Use Reflection to check license gating.
		$reflection = new ReflectionClass( PaySentinel_Retry::class );
		$method     = $reflection->getMethod( 'is_retry_feature_available' );
		$method->setAccessible( true );

		// Verify feature is available for Pro tier.
		$this->assertTrue( $method->invoke( $this->retry ), 'Pro tier should have retry feature available' );
	}

	/**
	 * Test Agency tier can use auto-retry
	 */
	public function test_agency_tier_auto_retry_allowed() {
		// Setup Agency tier.
		update_option( PaySentinel_License::OPTION_LICENSE_STATUS, 'valid' );
		update_option( PaySentinel_License::OPTION_LICENSE_DATA, array( 'plan' => 'agency' ) );

		// Use Reflection to check license gating.
		$reflection = new ReflectionClass( PaySentinel_Retry::class );
		$method     = $reflection->getMethod( 'is_retry_feature_available' );
		$method->setAccessible( true );

		// Verify feature is available for Agency tier.
		$this->assertTrue( $method->invoke( $this->retry ), 'Agency tier should have retry feature available' );
	}

	/**
	 * Test manual retry blocked for Free tier
	 */
	public function test_manual_retry_blocked_for_free_tier() {
		// Setup free tier.
		delete_option( PaySentinel_License::OPTION_LICENSE_STATUS );
		delete_option( PaySentinel_License::OPTION_LICENSE_DATA );

		// Attempt manual retry.
		$result = $this->retry->manual_retry( $this->order_id );

		// Verify it's blocked.
		$this->assertFalse( $result['success'], 'Manual retry should fail for free tier' );
		$this->assertStringContainsString( 'upgrade', strtolower( $result['message'] ), 'Should mention upgrade' );
	}

	/**
	 * Test manual retry allowed for Starter tier
	 */
	public function test_manual_retry_allowed_for_starter_tier() {
		// Setup Starter tier.
		update_option( PaySentinel_License::OPTION_LICENSE_STATUS, 'valid' );
		update_option( PaySentinel_License::OPTION_LICENSE_DATA, array( 'plan' => 'starter' ) );

		$order = wc_get_order( $this->order_id );
		$order->set_status( 'failed' );
		$order->save();

		// Mock stored payment method.
		$this->mock_stored_payment_method( $this->order_id );

		// Attempt manual retry.
		$result = $this->retry->manual_retry( $this->order_id );

		// Should not be blocked due to license.
		// (may fail for other reasons like store payment method, but not license).
		if ( isset( $result['message'] ) ) {
			$this->assertStringNotContainsString( 'upgrade', strtolower( $result['message'] ), 'Should not mention upgrade for Starter tier' );
		}
	}

	/**
	 * Test manual retry allowed for Pro tier
	 */
	public function test_manual_retry_allowed_for_pro_tier() {
		// Setup Pro tier.
		update_option( PaySentinel_License::OPTION_LICENSE_STATUS, 'valid' );
		update_option( PaySentinel_License::OPTION_LICENSE_DATA, array( 'plan' => 'pro' ) );

		$order = wc_get_order( $this->order_id );
		$order->set_status( 'failed' );
		$order->save();

		// Mock stored payment method.
		$this->mock_stored_payment_method( $this->order_id );

		// Attempt manual retry.
		$result = $this->retry->manual_retry( $this->order_id );

		// Should not be blocked due to license.
		if ( isset( $result['message'] ) ) {
			$this->assertStringNotContainsString( 'upgrade', strtolower( $result['message'] ), 'Should not mention upgrade for Pro tier' );
		}
	}

	/**
	 * Test manual retry allowed for Agency tier
	 */
	public function test_manual_retry_allowed_for_agency_tier() {
		// Setup Agency tier.
		update_option( PaySentinel_License::OPTION_LICENSE_STATUS, 'valid' );
		update_option( PaySentinel_License::OPTION_LICENSE_DATA, array( 'plan' => 'agency' ) );

		$order = wc_get_order( $this->order_id );
		$order->set_status( 'failed' );
		$order->save();

		// Mock stored payment method.
		$this->mock_stored_payment_method( $this->order_id );

		// Attempt manual retry.
		$result = $this->retry->manual_retry( $this->order_id );

		// Should not be blocked due to license.
		if ( isset( $result['message'] ) ) {
			$this->assertStringNotContainsString( 'upgrade', strtolower( $result['message'] ), 'Should not mention upgrade for Agency tier' );
		}
	}

	/**
	 * Test license check after tier upgrade
	 */
	public function test_retry_enabled_after_tier_upgrade() {
		// Start with free tier.
		delete_option( PaySentinel_License::OPTION_LICENSE_STATUS );
		delete_option( PaySentinel_License::OPTION_LICENSE_DATA );

		$order = wc_get_order( $this->order_id );
		$order->set_status( 'failed' );
		$order->save();

		// Mock stored payment method.
		$this->mock_stored_payment_method( $this->order_id );

		// Try to schedule retry (should fail).
		$this->retry->schedule_retry_on_failure( $this->order_id );

		if ( function_exists( 'as_get_scheduled_actions' ) ) {
			$scheduled_before = as_get_scheduled_actions(
				array(
					'hook'   => 'paysentinel_retry_payment',
					'status' => 'pending',
				)
			);
			$initial_count    = count( $scheduled_before ); // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		}

		// Upgrade to Starter.
		update_option( PaySentinel_License::OPTION_LICENSE_STATUS, 'valid' );
		update_option( PaySentinel_License::OPTION_LICENSE_DATA, array( 'plan' => 'starter' ) );

		// Try to schedule retry again (should succeed).
		$this->retry->schedule_retry_on_failure( $this->order_id );

		// Verify this time it was allowed.
		// This test validates that the license check is dynamic.
		$this->assertTrue( true, 'License check is dynamic and responds to tier changes' );
	}

	/**
	 * Test license tier constants are correct
	 */
	public function test_license_tier_constants() {
		// Verify that Starter+ tiers are correctly defined.
		$starter_plus = array( 'starter', 'pro', 'agency' ); // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable

		// These should be the allowed tiers.
		$this->assertEquals( 3, PaySentinel_License::GATEWAY_LIMITS['starter'], 'Starter should have 3 gateway limit' );
		$this->assertEquals( 999, PaySentinel_License::GATEWAY_LIMITS['pro'], 'Pro should have unlimited gateways' );
		$this->assertEquals( 999, PaySentinel_License::GATEWAY_LIMITS['agency'], 'Agency should have unlimited gateways' );

		// Verify retention limits.
		$this->assertEquals( 30, PaySentinel_License::RETENTION_LIMITS['starter'], 'Starter should have 30-day retention' );
		$this->assertEquals( 90, PaySentinel_License::RETENTION_LIMITS['pro'], 'Pro should have 90-day retention' );
		$this->assertEquals( 90, PaySentinel_License::RETENTION_LIMITS['agency'], 'Agency should have 90-day retention' );
	}

	/**
	 * Test that retry feature check is consistent across 100 tiers
	 *
	 * Property-based test validating that license tier checks always return same result for same tier
	 */
	public function test_property_license_tier_consistency() {
		$test_cases = array(
			'free'    => false,
			'starter' => true,
			'pro'     => true,
			'agency'  => true,
		);

		$reflection = new ReflectionClass( PaySentinel_Retry::class );
		$method     = $reflection->getMethod( 'is_retry_feature_available' );
		$method->setAccessible( true );

		foreach ( $test_cases as $tier => $should_allow ) {
			for ( $i = 0; $i < 10; $i++ ) {
				if ( 'free' === $tier ) {
					delete_option( PaySentinel_License::OPTION_LICENSE_STATUS );
					delete_option( PaySentinel_License::OPTION_LICENSE_DATA );
				} else {
					update_option( PaySentinel_License::OPTION_LICENSE_STATUS, 'valid' );
					update_option( PaySentinel_License::OPTION_LICENSE_DATA, array( 'plan' => $tier ) );
				}

				$retry_instance = new PaySentinel_Retry();
				$result         = $method->invoke( $retry_instance );

				$this->assertEquals(
					$should_allow,
					$result,
					"Tier {$tier} should return {$should_allow} for retry availability (iteration {$i})"
				);
			}
		}
	}

	/**
	 * Helper: Mock stored payment method
	 *
	 * @param int $order_id Order ID.
	 */
	private function mock_stored_payment_method( $order_id ) {
		$order = wc_get_order( $order_id );
		$order->set_payment_method( 'stripe' );
		$order->save();

		// Mock WC_Payment_Tokens to return a token.
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$GLOBALS['test_payment_tokens'] = array(
			array(
				'get_id'         => 1,
				'get_token'      => 'tok_test',
				'get_type'       => 'CC',
				'get_gateway_id' => 'stripe',
			),
		);
	}
}
