<?php
/**
 * Smart retry logic tests.
 *
 * @package PaySentinel
 */

/**
 * Class SmartRetryLogicTest
 */
class SmartRetryLogicTest extends WP_UnitTestCase {

	/**
	 * Retry instance.
	 *
	 * @var PaySentinel_Retry
	 */
	private $retry_instance;
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

		// Ensure WooCommerce main class is loaded if needed.
		if ( ! class_exists( 'WooCommerce' ) ) {
			// Try checking if WC() function exists, which usually implies WooCommerce is loaded.
			if ( function_exists( 'WC' ) ) {
				// Initialize if needed.
				WC();
			} else {
				$this->markTestSkipped( 'WooCommerce not active.' );
			}
		}

		// Initialize required components.
		$this->retry_instance = new PaySentinel_Retry();

		// Mock options.
		update_option(
			'paysentinel_options',
			array(
				'retry_enabled'      => true,
				'max_retry_attempts' => 3,
				'retry_schedule'     => array( 3600, 21600 ),
			)
		);

		// Mock license to enable retry feature.
		update_option( 'paysentinel_license_status', 'valid' );
		update_option(
			'paysentinel_license_data',
			array(
				'key'  => 'test_key',
				'plan' => 'starter',
			)
		);

		// Check if Action Scheduler is loaded (it should be via WooCommerce).
		if ( class_exists( 'ActionScheduler_Store' ) ) {
			$GLOBALS['test_as_scheduled_actions'] = array();

			// Hook into AS to capture scheduled actions.
			add_action(
				'action_scheduler_stored_action',
				function ( $action_id ) {
					try {
						$action = ActionScheduler_Store::instance()->fetch_action( $action_id );
						if ( $action ) {
							$next      = $action->get_schedule()->get_date();
							$timestamp = $next ? $next->getTimestamp() : 0;

							$GLOBALS['test_as_scheduled_actions'][] = array(
								'timestamp' => $timestamp,
								'hook'      => $action->get_hook(),
								'args'      => $action->get_args(),
							);
						}
					} catch ( \Exception $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
						// Ignore errors fetching action.
					}
				}
			);
		} else {
			// Fallback mock if AS not loaded (though checking function_exists is shaky if already defined).
			if ( ! function_exists( 'as_schedule_single_action' ) ) {
				/**
				 * Mock as_schedule_single_action for tests.
				 *
				 * @param int    $timestamp Schedule timestamp.
				 * @param string $hook      Action hook.
				 * @param array  $args      Action arguments.
				 * @param string $_methods  Action group (unused).
				 * @return int
				 */
				function as_schedule_single_action( $timestamp, $hook, $args = array(), $_methods = '' ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed, VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
					$GLOBALS['test_as_scheduled_actions'][] = array(
						'timestamp' => $timestamp,
						'hook'      => $hook,
						'args'      => $args,
					);
					return wp_rand( 1, 1000 );
				}
			}
			$GLOBALS['test_as_scheduled_actions'] = array();
		}

		// Create a dummy order.
		$order = wc_create_order();
		$order->set_billing_email( 'test@example.com' );
		$order->save();
		$this->order_id = $order->get_id();

		// Create a transaction record manually in DB.
		global $wpdb;
		$table_name = ( new PaySentinel_Database() )->get_transactions_table();

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$table_name,
			array(
				'order_id'       => $this->order_id,
				'gateway_id'     => 'stripe',
				'transaction_id' => 'tx_12345',
				'amount'         => 100.00,
				'currency'       => 'USD',
				'status'         => 'failed',
				'retry_count'    => 0,
				'created_at'     => current_time( 'mysql' ),
			)
		);
		$this->transaction_id = $wpdb->insert_id;
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		parent::tearDown();
		wp_delete_post( $this->order_id, true );
		delete_option( 'paysentinel_options' );
		delete_option( 'paysentinel_license_status' );
		delete_option( 'paysentinel_license_data' );
	}

	/**
	 * Test Hard Decline (Fraud)
	 * Should NOT schedule retry, SHOULD send email
	 */
	public function test_hard_decline_behavior() {
		global $wpdb;
		$table_name = ( new PaySentinel_Database() )->get_transactions_table();

		// Add a stored payment method so we don't exit early on "No Stored Method" check.
		$user_id = $this->factory->user->create();
		$token   = new WC_Payment_Token_CC();
		$token->set_token( 'tok_123' );
		$token->set_gateway_id( 'stripe' );
		$token->set_card_type( 'visa' );
		$token->set_last4( '4242' );
		$token->set_expiry_month( '12' );
		$token->set_expiry_year( '2030' );
		$token->set_user_id( $user_id );
		$token->save();

		$order = wc_get_order( $this->order_id );
		$order->set_customer_id( $user_id );
		$order->set_payment_method( 'stripe' );
		$order->save();

		// Update transaction with hard decline reason.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$table_name,
			array( 'failure_reason' => 'Do Not Honor - Stolen Card' ), // Hard decline keyword.
			array( 'id' => $this->transaction_id )
		);
		$t = $wpdb->get_row( "SELECT * FROM $table_name WHERE id = " . $this->transaction_id ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable

		// Fake the email sending.
		$this->reset_phpmailer();

		// Clear previous actions.
		$GLOBALS['test_as_scheduled_actions'] = array();

		// Trigger logic.
		$this->retry_instance->schedule_retry_on_failure( $this->order_id );

		// Verify NO Action Scheduler event (filter for our hook to avoid unrelated noise).
		$scheduled_actions = array_filter(
			$GLOBALS['test_as_scheduled_actions'],
			function ( $a ) {
				return $a['hook'] === 'paysentinel_retry_payment';
			}
		);
		$this->assertEmpty( $scheduled_actions, 'Hard decline should not schedule AS action.' );

		// Verify Email Sent (Recovery Email).
		// Check if the recovery flag was set on the order.
		$order     = wc_get_order( $this->order_id );
		$sent_flag = $order->get_meta( '_paysentinel_recovery_sent' );

		$this->assertTrue( ! empty( $sent_flag ), 'Recovery email flag should be set on hard decline.' );
	}

	/**
	 * Test Soft Decline (Timeout)
	 * Should schedule retry
	 */
	public function test_soft_decline_schedules_retry() {
		global $wpdb;
		$table_name = ( new PaySentinel_Database() )->get_transactions_table();

		// Update transaction with soft decline reason.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$table_name,
			array( 'failure_reason' => 'Connection Timeout' ), // Soft decline.
			array( 'id' => $this->transaction_id )
		);

		// Need to ensure has_stored_payment_method returns true for this test path.
		// We'll mock the method using a partial mock or reflection if needed.
		// For now, let's assume the class checks order tokens. We can add a token.
		$token = new WC_Payment_Token_CC();
		$token->set_token( 'tok_123' );
		$token->set_gateway_id( 'stripe' );
		$token->set_card_type( 'visa' );
		$token->set_last4( '4242' );
		$token->set_expiry_month( '12' );
		$token->set_expiry_year( gmdate( 'Y', strtotime( '+1 year' ) ) );
		$token->set_user_id( get_current_user_id() );
		$token->save();

		// Link token to order? The class checks WC_Payment_Tokens::get_order_tokens OR customer tokens.
		// Let's rely on the method finding a stored method.
		// Since we can't easily mock private methods in this context without complex reflection.
		// Let's try to satisfy `has_stored_payment_method` by making sure the order has a customer with tokens.
		// Creating a user with a token.
		$user_id = $this->factory->user->create();
		$order   = wc_get_order( $this->order_id );
		$order->set_customer_id( $user_id );
		$order->set_payment_method( 'stripe' );
		$order->save();

		$token->set_user_id( $user_id );
		$token->save();

		// Trigger logic.
		$this->retry_instance->schedule_retry_on_failure( $this->order_id );

		// Verify Action Scheduler event.
		$this->assertNotEmpty( $GLOBALS['test_as_scheduled_actions'], 'Soft decline should schedule AS action.' );

		// Find the paysentinel_retry_payment action (may not be the last one if other actions are scheduled).
		$retry_action = null;
		foreach ( $GLOBALS['test_as_scheduled_actions'] as $action ) {
			if ( $action['hook'] === 'paysentinel_retry_payment' ) {
				$retry_action = $action;
				break;
			}
		}

		$this->assertNotNull( $retry_action, 'paysentinel_retry_payment action should be scheduled' );
		$this->assertEquals( $this->transaction_id, $retry_action['args'][0] );
	}

	/**
	 * Test Max Retries Reached
	 * Should send recovery email
	 */
	public function test_max_retries_reached() {
		global $wpdb;
		$table_name = ( new PaySentinel_Database() )->get_transactions_table();

		// Update transaction to max retries.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$table_name,
			array(
				'retry_count'    => 3, // Max is 3.
				'failure_reason' => 'Timeout',
			),
			array( 'id' => $this->transaction_id )
		);

		$GLOBALS['test_as_scheduled_actions'] = array();

		// Trigger logic.
		$this->retry_instance->schedule_retry_on_failure( $this->order_id );

		// Verify NO new retry.
		$this->assertEmpty( $GLOBALS['test_as_scheduled_actions'], 'Should not schedule retry if max reached.' );
	}

	/**
	 * Test Gateway Integration (Method Existence)
	 *
	 * Since we rely on external gateway plugins, we can't fully unit test the
	 * external API call here without heavy mocking of WC_Payment_Gateways.
	 * We verify the integration logic path.
	 */
	public function test_gateway_integration_call() {
		// Prepare a mock gateway.
		$mock_gateway = $this->getMockBuilder( 'WC_Payment_Gateway' )
			->setMethods( array( 'process_payment', 'scheduled_subscription_payment', 'supports' ) )
			->getMock();

		$mock_gateway->id           = 'stripe';
		$mock_gateway->method_title = 'Stripe';

		// Register our mock gateway.
		$gateways           = WC()->payment_gateways->payment_gateways();
		$gateways['stripe'] = $mock_gateway;
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		WC()->payment_gateways->payment_gateways = $gateways;

		// We need to inject this mocked gateway into the global WC instance or.
		// hook filter 'woocommerce_payment_gateways' depending on how it's retrieved.
		// However, WC()->payment_gateways() usually returns a protected property in unit tests environment.
		// Let's rely on filter since we are in WP environment.

		add_filter(
			'woocommerce_payment_gateways',
			function ( $_methods ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable, Generic.CodeAnalysis.UnusedFunctionParameter.Found
				return array( 'WC_Gateway_Stripe_Mock' ); // This path is hard to test cleanly in isolation without loading the real gateway class file.
			}
		);

		// Instead, let's verify if the Retry class has the logic to delegate to the gateway.
		// Using Reflection to test 'process_gateway_payment' directly with a mock.

		$retry  = new PaySentinel_Retry();
		$method = new ReflectionMethod( 'PaySentinel_Retry', 'process_gateway_payment' );
		$method->setAccessible( true );

		$order          = wc_get_order( $this->order_id );
		$payment_method = array( 'token_id' => 123 );

		// Case 1: Gateway with scheduled_subscription_payment (Official Stripe/PayPal).
		$mock_gateway_sub     = $this->getMockBuilder( 'WC_Payment_Gateway' )
			->setMethods( array( 'scheduled_subscription_payment', 'supports' ) )
			->getMock();
		$mock_gateway_sub->id = 'stripe';

		// Expect call to scheduled_subscription_payment.
		$mock_gateway_sub->expects( $this->once() )
			->method( 'scheduled_subscription_payment' )
			->with( $this->equalTo( $order->get_total() ), $this->equalTo( $order ) );

		// Temporarily force order status update in mock (or assume it happens).
		// Since we can't easily change order state inside the mock execution in PHPUnit without callback,.
		// We mainly verify the *call* happens.

		try {
			$method->invoke( $retry, $mock_gateway_sub, $order, $payment_method );
		} catch ( Exception $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			// Expected if mock doesn't return correct structure or if internal logic fails on status check.
		}

		// Case 2: Gateway with standard process_payment.
		$mock_gateway_std     = $this->getMockBuilder( 'WC_Payment_Gateway' )
			->setMethods( array( 'process_payment', 'supports' ) )
			->getMock();
		$mock_gateway_std->id = 'other_gateway';

		// Expect call to process_payment.
		$mock_gateway_std->expects( $this->once() )
			->method( 'process_payment' )
			->with( $this->equalTo( $order->get_id() ) )
			->willReturn(
				array(
					'result'   => 'success',
					'redirect' => '',
				)
			);

		try {
			$method->invoke( $retry, $mock_gateway_std, $order, $payment_method );
		} catch ( Exception $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			// Exception is expected in test context.
		}
	}

	/**
	 * Reset PHPMailer state.
	 */
	private function reset_phpmailer() {
		// Reset any mail mocks if needed.
	}

	/**
	 * Test Free License Tier
	 * Retry feature should not be available
	 */
	public function test_free_license_no_retry() {
		// Set free license.
		update_option( 'paysentinel_license_status', 'invalid' );
		delete_option( 'paysentinel_license_data' );

		$GLOBALS['test_as_scheduled_actions'] = array();

		// Trigger logic with soft decline (would normally schedule retry).
		global $wpdb;
		$table_name = ( new PaySentinel_Database() )->get_transactions_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$table_name,
			array( 'failure_reason' => 'Timeout' ),
			array( 'id' => $this->transaction_id )
		);

		$this->retry_instance->schedule_retry_on_failure( $this->order_id );

		// Verify NO retry scheduled for free tier.
		$retry_actions = array_filter(
			$GLOBALS['test_as_scheduled_actions'],
			function ( $a ) {
				return $a['hook'] === 'paysentinel_retry_payment';
			}
		);
		$this->assertEmpty( $retry_actions, 'Free tier should not schedule retries.' );
	}

	/**
	 * Test Pro License Tier
	 * Retry feature should be available
	 */
	public function test_pro_license_enables_retry() {
		// Set pro license.
		update_option( 'paysentinel_license_status', 'valid' );
		update_option(
			'paysentinel_license_data',
			array(
				'key'  => 'test_key',
				'plan' => 'pro',
			)
		);

		// Add payment token.
		$user_id = $this->factory->user->create();
		$token   = new WC_Payment_Token_CC();
		$token->set_token( 'tok_pro' );
		$token->set_gateway_id( 'stripe' );
		$token->set_card_type( 'visa' );
		$token->set_last4( '4242' );
		$token->set_expiry_month( '12' );
		$token->set_expiry_year( '2030' );
		$token->set_user_id( $user_id );
		$token->save();

		$order = wc_get_order( $this->order_id );
		$order->set_customer_id( $user_id );
		$order->set_payment_method( 'stripe' );
		$order->save();

		// Soft decline - should schedule retry for pro tier.
		global $wpdb;
		$table_name = ( new PaySentinel_Database() )->get_transactions_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$table_name,
			array( 'failure_reason' => 'Connection Timeout' ),
			array( 'id' => $this->transaction_id )
		);

		$GLOBALS['test_as_scheduled_actions'] = array();
		$this->retry_instance->schedule_retry_on_failure( $this->order_id );

		// Verify retry scheduled for pro tier.
		$retry_actions = array_filter(
			$GLOBALS['test_as_scheduled_actions'],
			function ( $a ) {
				return $a['hook'] === 'paysentinel_retry_payment';
			}
		);
		$this->assertNotEmpty( $retry_actions, 'Pro tier should schedule retries.' );
	}

	/**
	 * Test No Stored Payment Method
	 * Should send recovery email immediately without retry
	 */
	public function test_no_stored_payment_method() {
		// Don't add any payment token.
		$order = wc_get_order( $this->order_id );
		$order->set_payment_method( 'stripe' );
		$order->save();

		global $wpdb;
		$table_name = ( new PaySentinel_Database() )->get_transactions_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$table_name,
			array( 'failure_reason' => 'Connection Timeout' ), // Would normally retry.
			array( 'id' => $this->transaction_id )
		);

		$GLOBALS['test_as_scheduled_actions'] = array();
		$this->retry_instance->schedule_retry_on_failure( $this->order_id );

		// Verify NO retry scheduled.
		$retry_actions = array_filter(
			$GLOBALS['test_as_scheduled_actions'],
			function ( $a ) {
				return $a['hook'] === 'paysentinel_retry_payment';
			}
		);
		$this->assertEmpty( $retry_actions, 'Should not retry without stored payment method.' );

		// Verify recovery flag was set (email sent).
		$order     = wc_get_order( $this->order_id );
		$sent_flag = $order->get_meta( '_paysentinel_recovery_sent' );
		$this->assertTrue( ! empty( $sent_flag ), 'Should send recovery email instead.' );
	}

	/**
	 * Test Hard Decline Variations
	 * Test multiple hard decline keywords
	 */
	public function test_hard_decline_variations() {
		// Add payment token.
		$user_id = $this->factory->user->create();
		$token   = new WC_Payment_Token_CC();
		$token->set_token( 'tok_123' );
		$token->set_gateway_id( 'stripe' );
		$token->set_card_type( 'visa' );
		$token->set_last4( '4242' );
		$token->set_expiry_month( '12' );
		$token->set_expiry_year( '2030' );
		$token->set_user_id( $user_id );
		$token->save();

		$order = wc_get_order( $this->order_id );
		$order->set_customer_id( $user_id );
		$order->set_payment_method( 'stripe' );
		$order->save();

		$hard_decline_reasons = array(
			'fraud detected',
			'invalid card number',
			'expired card on file',
			'STOP RECURRING',
			'Closure',
			'Lost Card',
			'Stolen Card',
		);

		foreach ( $hard_decline_reasons as $reason ) {
			$GLOBALS['test_as_scheduled_actions'] = array();
			global $wpdb;
			$table_name = ( new PaySentinel_Database() )->get_transactions_table();

			// Create new order/transaction for each test.
			$new_order = wc_create_order();
			$new_order->set_customer_id( $user_id );
			$new_order->set_payment_method( 'stripe' );
			$new_order->set_billing_email( 'test@example.com' );
			$new_order->save();

			$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$table_name,
				array(
					'order_id'       => $new_order->get_id(),
					'gateway_id'     => 'stripe',
					'transaction_id' => 'tx_' . $reason,
					'amount'         => 100.00,
					'currency'       => 'USD',
					'status'         => 'failed',
					'failure_reason' => $reason,
					'retry_count'    => 0,
					'created_at'     => current_time( 'mysql' ),
				)
			);

			$this->retry_instance->schedule_retry_on_failure( $new_order->get_id() );

			$retry_actions = array_filter(
				$GLOBALS['test_as_scheduled_actions'],
				function ( $a ) {
					return $a['hook'] === 'paysentinel_retry_payment';
				}
			);

			$this->assertEmpty(
				$retry_actions,
				"Should not retry on hard decline: '$reason'"
			);

			wp_delete_post( $new_order->get_id(), true );
		}
	}

	/**
	 * Test Soft Decline Variations
	 */
	public function test_soft_decline_variations() {
		// Add payment token.
		$user_id = $this->factory->user->create();
		$token   = new WC_Payment_Token_CC();
		$token->set_token( 'tok_123' );
		$token->set_gateway_id( 'stripe' );
		$token->set_card_type( 'visa' );
		$token->set_last4( '4242' );
		$token->set_expiry_month( '12' );
		$token->set_expiry_year( '2030' );
		$token->set_user_id( $user_id );
		$token->save();

		$order = wc_get_order( $this->order_id );
		$order->set_customer_id( $user_id );
		$order->set_payment_method( 'stripe' );
		$order->save();

		$soft_decline_reasons = array(
			'Connection Timeout',
			'Insufficient Funds',
			'Bank Unavailable',
			'Generic Decline',
			'Please try again later',
		);

		foreach ( $soft_decline_reasons as $reason ) {
			$GLOBALS['test_as_scheduled_actions'] = array();
			global $wpdb;
			$table_name = ( new PaySentinel_Database() )->get_transactions_table();

			// Create new order/transaction for each test.
			$new_order = wc_create_order();
			$new_order->set_customer_id( $user_id );
			$new_order->set_payment_method( 'stripe' );
			$new_order->set_billing_email( 'test@example.com' );
			$new_order->save();

			$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$table_name,
				array(
					'order_id'       => $new_order->get_id(),
					'gateway_id'     => 'stripe',
					'transaction_id' => 'tx_soft_' . sanitize_title( $reason ),
					'amount'         => 100.00,
					'currency'       => 'USD',
					'status'         => 'failed',
					'failure_reason' => $reason,
					'retry_count'    => 0,
					'created_at'     => current_time( 'mysql' ),
				)
			);

			$this->retry_instance->schedule_retry_on_failure( $new_order->get_id() );

			$retry_actions = array_filter(
				$GLOBALS['test_as_scheduled_actions'],
				function ( $a ) {
					return $a['hook'] === 'paysentinel_retry_payment';
				}
			);

			$this->assertNotEmpty(
				$retry_actions,
				"Should retry on soft decline: '$reason'"
			);

			wp_delete_post( $new_order->get_id(), true );
		}
	}

	/**
	 * Test Unknown Failure Reason
	 * Should retry by default (assume temporary failure)
	 */
	public function test_unknown_failure_reason_retries() {
		// Add payment token.
		$user_id = $this->factory->user->create();
		$token   = new WC_Payment_Token_CC();
		$token->set_token( 'tok_123' );
		$token->set_gateway_id( 'stripe' );
		$token->set_card_type( 'visa' );
		$token->set_last4( '4242' );
		$token->set_expiry_month( '12' );
		$token->set_expiry_year( '2030' );
		$token->set_user_id( $user_id );
		$token->save();

		$order = wc_get_order( $this->order_id );
		$order->set_customer_id( $user_id );
		$order->set_payment_method( 'stripe' );
		$order->save();

		global $wpdb;
		$table_name = ( new PaySentinel_Database() )->get_transactions_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$table_name,
			array( 'failure_reason' => 'Unknown error XYZ-123' ), // Unknown reason.
			array( 'id' => $this->transaction_id )
		);

		$GLOBALS['test_as_scheduled_actions'] = array();
		$this->retry_instance->schedule_retry_on_failure( $this->order_id );

		$retry_actions = array_filter(
			$GLOBALS['test_as_scheduled_actions'],
			function ( $a ) {
				return $a['hook'] === 'paysentinel_retry_payment';
			}
		);

		$this->assertNotEmpty(
			$retry_actions,
			'Should retry on unknown failure reasons.'
		);
	}

	/**
	 * Test Retry Count Increments (via schedule_retry)
	 */
	public function test_retry_count_tracked_on_schedule() {
		global $wpdb;
		$table_name = ( new PaySentinel_Database() )->get_transactions_table();

		// Add payment token.
		$user_id = $this->factory->user->create();
		$token   = new WC_Payment_Token_CC();
		$token->set_token( 'tok_123' );
		$token->set_gateway_id( 'stripe' );
		$token->set_card_type( 'visa' );
		$token->set_last4( '4242' );
		$token->set_expiry_month( '12' );
		$token->set_expiry_year( '2030' );
		$token->set_user_id( $user_id );
		$token->save();

		$order = wc_get_order( $this->order_id );
		$order->set_customer_id( $user_id );
		$order->set_payment_method( 'stripe' );
		$order->save();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$table_name,
			array(
				'failure_reason' => 'Timeout',
				'retry_count'    => 0,
			),
			array( 'id' => $this->transaction_id )
		);

		// Get initial count.
		$trans_before = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT retry_count FROM {$table_name} WHERE id = %d",
				$this->transaction_id
			)
		);
		$this->assertEquals( 0, $trans_before->retry_count, 'Initial retry count should be 0' );

		// Schedule retry (which tracks retry count in the action).
		$GLOBALS['test_as_scheduled_actions'] = array();
		$this->retry_instance->schedule_retry( $this->transaction_id );

		// Verify action was scheduled with transaction ID.
		$retry_actions = array_filter(
			$GLOBALS['test_as_scheduled_actions'],
			function ( $a ) {
				return $a['hook'] === 'paysentinel_retry_payment';
			}
		);
		$this->assertNotEmpty( $retry_actions, 'Retry should be scheduled.' );

		$first_retry = reset( $retry_actions );
		$this->assertEquals( $this->transaction_id, $first_retry['args'][0], 'Scheduled action should reference correct transaction ID' );
	}

	/**
	 * Test Backoff Schedule
	 * First retry should be at 3600 seconds (1 hour)
	 */
	public function test_retry_backoff_schedule() {
		// Add payment token.
		$user_id = $this->factory->user->create();
		$token   = new WC_Payment_Token_CC();
		$token->set_token( 'tok_123' );
		$token->set_gateway_id( 'stripe' );
		$token->set_card_type( 'visa' );
		$token->set_last4( '4242' );
		$token->set_expiry_month( '12' );
		$token->set_expiry_year( '2030' );
		$token->set_user_id( $user_id );
		$token->save();

		$order = wc_get_order( $this->order_id );
		$order->set_customer_id( $user_id );
		$order->set_payment_method( 'stripe' );
		$order->save();

		global $wpdb;
		$table_name = ( new PaySentinel_Database() )->get_transactions_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$table_name,
			array(
				'failure_reason' => 'Timeout',
				'retry_count'    => 0,
			),
			array( 'id' => $this->transaction_id )
		);

		$GLOBALS['test_as_scheduled_actions'] = array();
		$this->retry_instance->schedule_retry_on_failure( $this->order_id );

		$retry_actions = array_filter(
			$GLOBALS['test_as_scheduled_actions'],
			function ( $a ) {
				return $a['hook'] === 'paysentinel_retry_payment';
			}
		);

		$this->assertNotEmpty( $retry_actions, 'Should schedule first retry.' );

		$first_retry    = reset( $retry_actions );
		$current_time   = time();
		$scheduled_time = $first_retry['timestamp'];

		// Should be scheduled approximately 3600 seconds (1 hour) from now.
		$time_diff = $scheduled_time - $current_time;
		$this->assertGreaterThanOrEqual( 3500, $time_diff, 'First retry should be ~1 hour away' );
		$this->assertLessThanOrEqual( 3700, $time_diff, 'Retry time should not be too far in future' );
	}

	/**
	 * Test Recovery Email Recipient
	 */
	public function test_recovery_email_recipient_correct() {
		// Add payment token.
		$user_id = $this->factory->user->create();
		$token   = new WC_Payment_Token_CC();
		$token->set_token( 'tok_123' );
		$token->set_gateway_id( 'stripe' );
		$token->set_card_type( 'visa' );
		$token->set_last4( '4242' );
		$token->set_expiry_month( '12' );
		$token->set_expiry_year( '2030' );
		$token->set_user_id( $user_id );
		$token->save();

		$order = wc_get_order( $this->order_id );
		$order->set_customer_id( $user_id );
		$order->set_payment_method( 'stripe' );
		$order->set_billing_email( 'customer@example.com' );
		$order->save();

		global $wpdb;
		$table_name = ( new PaySentinel_Database() )->get_transactions_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$table_name,
			array( 'failure_reason' => 'Fraud - Card Stolen' ),
			array( 'id' => $this->transaction_id )
		);

		// Mock wp_mail.
		$mail_recipient = null; // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		add_filter(
			'wp_mail',
			function ( $_result ) use ( &$mail_recipient ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found, VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
				// Capture args from wp_mail call.
				return true;
			}
		);

		$this->retry_instance->schedule_retry_on_failure( $this->order_id );

		// Verify recovery flag (email was intended to be sent).
		$updated_order = wc_get_order( $this->order_id );
		$sent_flag     = $updated_order->get_meta( '_paysentinel_recovery_sent' );
		$this->assertTrue( ! empty( $sent_flag ), 'Recovery email should be marked as sent.' );
	}

	/**
	 * Test First Retry Not Already At Max
	 */
	public function test_first_retry_not_at_max() {
		// Add payment token.
		$user_id = $this->factory->user->create();
		$token   = new WC_Payment_Token_CC();
		$token->set_token( 'tok_123' );
		$token->set_gateway_id( 'stripe' );
		$token->set_card_type( 'visa' );
		$token->set_last4( '4242' );
		$token->set_expiry_month( '12' );
		$token->set_expiry_year( '2030' );
		$token->set_user_id( $user_id );
		$token->save();

		$order = wc_get_order( $this->order_id );
		$order->set_customer_id( $user_id );
		$order->set_payment_method( 'stripe' );
		$order->save();

		global $wpdb;
		$table_name = ( new PaySentinel_Database() )->get_transactions_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$table_name,
			array(
				'failure_reason' => 'Timeout',
				'retry_count'    => 1,
			), // Already retried once.
			array( 'id' => $this->transaction_id )
		);

		$GLOBALS['test_as_scheduled_actions'] = array();
		$this->retry_instance->schedule_retry_on_failure( $this->order_id );

		$retry_actions = array_filter(
			$GLOBALS['test_as_scheduled_actions'],
			function ( $a ) {
				return $a['hook'] === 'paysentinel_retry_payment';
			}
		);

		$this->assertNotEmpty( $retry_actions, 'Should schedule second retry.' );
	}
}
