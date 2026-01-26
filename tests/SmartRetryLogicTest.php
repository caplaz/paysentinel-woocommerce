<?php
/**
 * Smart Retry Logic Tests
 */

class SmartRetryLogicTest extends WP_UnitTestCase {

	private $retry_instance;
	private $order_id;
	private $transaction_id;

	public function setUp(): void {
		parent::setUp();
		
		// Ensure WooCommerce main class is loaded if needed
		if ( ! class_exists( 'WooCommerce' ) ) {
			// Try checking if WC() function exists, which usually implies WooCommerce is loaded
			if ( function_exists( 'WC' ) ) {
				// Initialize if needed
				WC();
			} else {
				$this->markTestSkipped( 'WooCommerce not active.' );
			}
		}

		// Initialize required components
		$this->retry_instance = new WC_Payment_Monitor_Retry();
		
		// Mock options
		update_option( 'wc_payment_monitor_options', array(
			'retry_enabled'      => true,
			'max_retry_attempts' => 3,
			'retry_schedule'     => array( 3600, 21600 ),
		) );

		// Check if Action Scheduler is loaded (it should be via WooCommerce)
		if ( class_exists( 'ActionScheduler_Store' ) ) {
			$GLOBALS['test_as_scheduled_actions'] = array();
			
			// Hook into AS to capture scheduled actions
			add_action( 'action_scheduler_stored_action', function( $action_id ) {
				try {
					$action = ActionScheduler_Store::instance()->fetch_action( $action_id );
					if ( $action ) {
						$next = $action->get_schedule()->get_date();
						$timestamp = $next ? $next->getTimestamp() : 0;
						
						$GLOBALS['test_as_scheduled_actions'][] = array(
							'timestamp' => $timestamp,
							'hook'      => $action->get_hook(),
							'args'      => $action->get_args(),
						);
					}
				} catch ( \Exception $e ) {
					// Ignore errors fetching action
				}
			} );
		} else {
			// Fallback mock if AS not loaded (though checking function_exists is shaky if already defined)
			if ( ! function_exists( 'as_schedule_single_action' ) ) {
				function as_schedule_single_action( $timestamp, $hook, $args = array(), $group = '' ) {
					$GLOBALS['test_as_scheduled_actions'][] = array(
						'timestamp' => $timestamp,
						'hook'      => $hook,
						'args'      => $args,
					);
					return rand( 1, 1000 );
				}
			}
			$GLOBALS['test_as_scheduled_actions'] = array();
		}

		// Create a dummy order
		$order = wc_create_order();
		$order->set_billing_email( 'test@example.com' );
		$order->save();
		$this->order_id = $order->get_id();

		// Create a transaction record manually in DB
		global $wpdb;
		$table_name = ( new WC_Payment_Monitor_Database() )->get_transactions_table();
		
		$wpdb->insert(
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

	public function tearDown(): void {
		parent::tearDown();
		wp_delete_post( $this->order_id, true );
		delete_option( 'wc_payment_monitor_options' );
	}

	/**
	 * Test Hard Decline (Fraud)
	 * Should NOT schedule retry, SHOULD send email
	 */
	public function test_hard_decline_behavior() {
		global $wpdb;
		$table_name = ( new WC_Payment_Monitor_Database() )->get_transactions_table();

		// Add a stored payment method so we don't exit early on "No Stored Method" check
		$user_id = $this->factory->user->create();
		$token = new WC_Payment_Token_CC();
		$token->set_token('tok_123');
		$token->set_gateway_id('stripe');
		$token->set_card_type('visa');
		$token->set_last4('4242');
		$token->set_expiry_month( '12' );
		$token->set_expiry_year( '2030' );
		$token->set_user_id( $user_id );
		$token->save();

		$order = wc_get_order( $this->order_id );
		$order->set_customer_id( $user_id );
		$order->set_payment_method('stripe');
		$order->save();

		// Update transaction with hard decline reason
		$wpdb->update(
			$table_name,
			array( 'failure_reason' => 'Do Not Honor - Stolen Card' ), // Hard decline keyword
			array( 'id' => $this->transaction_id )
		);
		$t = $wpdb->get_row("SELECT * FROM $table_name WHERE id = " . $this->transaction_id);

		// Fake the email sending
		$this->reset_phpmailer();

		// Clear previous actions
		$GLOBALS['test_as_scheduled_actions'] = array();

		// Trigger logic
		$this->retry_instance->schedule_retry_on_failure( $this->order_id );

		// Verify NO Action Scheduler event (filter for our hook to avoid unrelated noise)
		$scheduled_actions = array_filter( $GLOBALS['test_as_scheduled_actions'], function($a) {
			return $a['hook'] === 'wc_payment_monitor_retry_payment';
		});
		$this->assertEmpty( $scheduled_actions, 'Hard decline should not schedule AS action.' );

		// Verify Email Sent (Recovery Email)
		// Note: WP_UnitTestCase usually mocks wp_mail, we can check basic assertions if available
		// Or we check if order note was added "Sent payment recovery email"
		$order = wc_get_order( $this->order_id );
		$notes = wc_get_order_notes( array( 'order_id' => $this->order_id ) );
		$found_email_note = false;
		foreach ( $notes as $note ) {
			if ( strpos( $note->content, 'Sent payment recovery email' ) !== false ) {
				$found_email_note = true;
				break;
			}
		}
		$this->assertTrue( $found_email_note, 'Recovery email note should be present on hard decline.' );
	}

	/**
	 * Test Soft Decline (Timeout)
	 * Should schedule retry
	 */
	public function test_soft_decline_schedules_retry() {
		global $wpdb;
		$table_name = ( new WC_Payment_Monitor_Database() )->get_transactions_table();

		// Update transaction with soft decline reason
		$wpdb->update(
			$table_name,
			array( 'failure_reason' => 'Connection Timeout' ), // Soft decline
			array( 'id' => $this->transaction_id )
		);

		// Need to ensure has_stored_payment_method returns true for this test path
		// We'll mock the method using a partial mock or reflection if needed.
		// For now, let's assume the class checks order tokens. We can add a token.
		$token = new WC_Payment_Token_CC();
		$token->set_token( 'tok_123' );
		$token->set_gateway_id( 'stripe' );
		$token->set_card_type( 'visa' );
		$token->set_last4( '4242' );
		$token->set_expiry_month( '12' );
		$token->set_expiry_year( date('Y', strtotime('+1 year')) );
		$token->set_user_id( get_current_user_id() );
		$token->save();
		
		// Link token to order? The class checks WC_Payment_Tokens::get_order_tokens OR customer tokens
		// Let's rely on the method finding a stored method.
		// Since we can't easily mock private methods in this context without complex reflection, 
		// let's try to satisfy `has_stored_payment_method` by making sure the order has a customer with tokens.
		// Creating a user with a token:
		$user_id = $this->factory->user->create();
		$order = wc_get_order( $this->order_id );
		$order->set_customer_id( $user_id );
		$order->set_payment_method('stripe');
		$order->save();
		
		$token->set_user_id( $user_id );
		$token->save();

		// Trigger logic
		$this->retry_instance->schedule_retry_on_failure( $this->order_id );

		// Verify Action Scheduler event
		$this->assertNotEmpty( $GLOBALS['test_as_scheduled_actions'], 'Soft decline should schedule AS action.' );
		$scheduled = end( $GLOBALS['test_as_scheduled_actions'] );
		$this->assertEquals( 'wc_payment_monitor_retry_payment', $scheduled['hook'] );
		$this->assertEquals( $this->transaction_id, $scheduled['args'][0] );
	}

	/**
	 * Test Max Retries Reached
	 * Should send recovery email
	 */
	public function test_max_retries_reached() {
		global $wpdb;
		$table_name = ( new WC_Payment_Monitor_Database() )->get_transactions_table();

		// Update transaction to max retries
		$wpdb->update(
			$table_name,
			array( 
				'retry_count' => 3, // Max is 3
				'failure_reason' => 'Timeout' 
			), 
			array( 'id' => $this->transaction_id )
		);
		
		$GLOBALS['test_as_scheduled_actions'] = array();

		// Trigger logic
		$this->retry_instance->schedule_retry_on_failure( $this->order_id );

		// Verify NO new retry
		$this->assertEmpty( $GLOBALS['test_as_scheduled_actions'], 'Should not schedule retry if max reached.' );
	}

	/**
	 * Test Analyze Failure Reason (Reflection)
	 */
	public function test_analyze_failure_reason_logic() {
		$method = new ReflectionMethod( 'WC_Payment_Monitor_Retry', 'analyze_failure_reason' );
		$method->setAccessible( true );

		$hard_codes = array( 'Fraud', 'Stolen', 'Pick up card', 'Hard Decline' );
		foreach ( $hard_codes as $code ) {
			$this->assertFalse( $method->invoke( $this->retry_instance, $code ), "Code '$code' should be False (Hard Decline)" );
		}

		$soft_codes = array( 'Timeout', 'Network Error', 'Insufficient Funds', 'Generic Error' );
		foreach ( $soft_codes as $code ) {
			$this->assertTrue( $method->invoke( $this->retry_instance, $code ), "Code '$code' should be True (Soft Decline)" );
		}
	}

	private function reset_phpmailer() {
		// Reset any mail mocks if needed
	}
}
