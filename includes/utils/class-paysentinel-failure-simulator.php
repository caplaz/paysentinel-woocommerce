<?php
/**
 * Payment Failure Simulator
 *
 * Simulates various payment failure scenarios for testing and development
 *
 * @package PaySentinel
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * PaySentinel Failure Simulator class.
 *
 * Handles simulating payment failures for testing purposes.
 */
class PaySentinel_Failure_Simulator {

	/**
	 * Database instance.
	 *
	 * @var PaySentinel_Database $database Database instance.
	 */
	private $database;

	/**
	 * Logger instance.
	 *
	 * @var PaySentinel_Logger $logger Logger instance.
	 */
	private $logger;

	/**
	 * Available failure scenarios
	 */
	public const FAILURE_SCENARIOS = array(
		'card_declined'          => array(
			'name'    => 'Card Declined',
			'code'    => 'card_declined',
			'message' => 'Your card was declined.',
		),
		'insufficient_funds'     => array(
			'name'    => 'Insufficient Funds',
			'code'    => 'insufficient_funds',
			'message' => 'Your card has insufficient funds.',
		),
		'expired_card'           => array(
			'name'    => 'Expired Card',
			'code'    => 'expired_card',
			'message' => 'Your card has expired.',
		),
		'incorrect_cvc'          => array(
			'name'    => 'Incorrect CVC',
			'code'    => 'incorrect_cvc',
			'message' => 'Your card\'s security code is incorrect.',
		),
		'processing_error'       => array(
			'name'    => 'Processing Error',
			'code'    => 'processing_error',
			'message' => 'An error occurred while processing your card.',
		),
		'gateway_timeout'        => array(
			'name'    => 'Gateway Timeout',
			'code'    => 'gateway_timeout',
			'message' => 'The payment gateway timed out. Please try again.',
		),
		'network_error'          => array(
			'name'    => 'Network Error',
			'code'    => 'network_error',
			'message' => 'A network error occurred. Please check your connection.',
		),
		'rate_limit_exceeded'    => array(
			'name'    => 'Rate Limit Exceeded',
			'code'    => 'rate_limit_exceeded',
			'message' => 'Too many requests. Please try again later.',
		),
		'fraud_detected'         => array(
			'name'    => 'Fraud Detected',
			'code'    => 'fraud_detected',
			'message' => 'This transaction has been flagged as potentially fraudulent.',
		),
		'invalid_account'        => array(
			'name'    => 'Invalid Account',
			'code'    => 'invalid_account',
			'message' => 'The payment account is invalid or has been closed.',
		),
		'gateway_misconfigured'  => array(
			'name'    => 'Gateway Misconfigured',
			'code'    => 'authentication_required',
			'message' => 'Payment gateway authentication failed. Please check your API keys.',
		),
		'currency_not_supported' => array(
			'name'    => 'Currency Not Supported',
			'code'    => 'currency_not_supported',
			'message' => 'The selected currency is not supported by this gateway.',
		),
	);

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->database = new PaySentinel_Database();
		$this->logger   = new PaySentinel_Logger();
		$this->init_hooks();
	}

	/**
	 * Initialize hooks
	 */
	private function init_hooks() {
		// Only activate simulator if test mode is enabled.
		if ( $this->is_test_mode_enabled() ) {
			add_filter( 'woocommerce_payment_successful_result', array( $this, 'maybe_simulate_failure' ), 10, 2 );
			add_action( 'woocommerce_checkout_order_processed', array( $this, 'maybe_simulate_processing_failure' ), 10, 3 );
		}
	}

	/**
	 * Check if test mode is enabled.
	 *
	 * @return bool Test mode enabled status.
	 */
	public function is_test_mode_enabled() {
		$settings = get_option( 'paysentinel_settings', array() );
		return isset( $settings[ PaySentinel_Settings_Constants::ENABLE_TEST_MODE ] ) && $settings[ PaySentinel_Settings_Constants::ENABLE_TEST_MODE ];
	}

	/**
	 * Get failure probability setting.
	 *
	 * @return int Percentage (0-100).
	 */
	private function get_failure_probability() {
		$settings = get_option( 'paysentinel_settings', array() );
		return isset( $settings[ PaySentinel_Settings_Constants::TEST_FAILURE_RATE ] ) ? intval( $settings[ PaySentinel_Settings_Constants::TEST_FAILURE_RATE ] ) : 10;
	}

	/**
	 * Get configured failure scenarios.
	 *
	 * @return array Failure scenarios.
	 */
	private function get_enabled_scenarios() {
		$settings = get_option( 'paysentinel_settings', array() );
		$enabled  = isset( $settings[ PaySentinel_Settings_Constants::TEST_FAILURE_SCENARIOS ] ) ? $settings[ PaySentinel_Settings_Constants::TEST_FAILURE_SCENARIOS ] : array_keys( self::FAILURE_SCENARIOS );

		if ( empty( $enabled ) ) {
			$enabled = array_keys( self::FAILURE_SCENARIOS );
		}

		return $enabled;
	}

	/**
	 * Maybe simulate a payment failure.
	 *
	 * @param array $result   Payment result.
	 * @param int   $order_id Order ID.
	 *
	 * @return array Modified result.
	 */
	public function maybe_simulate_failure( $result, $order_id ) {
		// Don't interfere if payment already failed.
		if ( isset( $result['result'] ) && 'failure' === $result['result'] ) {
			return $result;
		}

		// Check if we should simulate a failure.
		$probability = $this->get_failure_probability();
		$should_fail = ( wp_rand( 1, 100 ) <= $probability );

		if ( ! $should_fail ) {
			return $result;
		}

		// Select a random failure scenario.
		$enabled_scenarios = $this->get_enabled_scenarios();
		$scenario_key      = $enabled_scenarios[ array_rand( $enabled_scenarios ) ];
		$scenario          = self::FAILURE_SCENARIOS[ $scenario_key ];

		// Log the simulated failure.
		$order = wc_get_order( $order_id );
		if ( $order ) {
			$failure_code = strtoupper( str_replace( '_', '', $scenario_key ) );
			$failure_note = sprintf(
				/* translators: %s is the failure scenario message. */
				__( '[SIMULATED FAILURE] %s', 'paysentinel' ),
				$scenario['message']
			);

			$order->update_status( 'failed', $failure_note );

			// Add metadata to identify this as simulated.
			$order->add_meta_data( '_paysentinel_simulated_failure', true, true );
			$order->add_meta_data( '_paysentinel_failure_scenario', $scenario_key, true );
			$order->save();

			// Explicitly write to the transactions table with the [SIMULATED FAILURE] marker.
			$this->upsert_simulated_failure_transaction( $order, $failure_note, $failure_code );
		}

		// Return failure result.
		return array(
			'result'   => 'failure',
			'messages' => sprintf(
				'<strong>%s:</strong> %s',
				__( 'Payment Failed (Test Mode)', 'paysentinel' ),
				$scenario['message']
			),
		);
	}

	/**
	 * Maybe simulate processing failure.
	 *
	 * @param int    $order_id    Order ID.
	 * @param array  $posted_data Posted data.
	 * @param object $order       Order object.
	 */
	public function maybe_simulate_processing_failure( $order_id, $posted_data, $order ) {
		// Check if already handled by maybe_simulate_failure.
		if ( $order->get_meta( '_paysentinel_simulated_failure' ) ) {
			return;
		}

		// Additional processing failures can be simulated here.
		// For example, simulate timeouts during order processing.
	}

	/**
	 * Manually simulate a failure for a specific order.
	 *
	 * @param int|WC_Order $order_id_or_object Order ID or Order object.
	 * @param string       $scenario_key       Scenario key.
	 *
	 * @return array Result.
	 */
	public function simulate_failure_for_order( $order_id_or_object, $scenario_key ) {
		// Accept either order ID or order object.
		if ( is_numeric( $order_id_or_object ) ) {
			$order = wc_get_order( $order_id_or_object );
		} else {
			$order = $order_id_or_object;
		}

		if ( ! $order || ! is_a( $order, 'WC_Order' ) ) {
			return array(
				'success' => false,
				'message' => __( 'Order not found.', 'paysentinel' ),
			);
		}

		if ( ! isset( self::FAILURE_SCENARIOS[ $scenario_key ] ) ) {
			return array(
				'success' => false,
				'message' => __( 'Invalid failure scenario.', 'paysentinel' ),
			);
		}

		$scenario     = self::FAILURE_SCENARIOS[ $scenario_key ];
		$failure_code = strtoupper( str_replace( '_', '', $scenario_key ) );
		$failure_note = sprintf(
			/* translators: %1$s is the failure scenario message, %2$s is the failure code. */
			__( '[SIMULATED FAILURE] %1$s (Error Code: %2$s)', 'paysentinel' ),
			$scenario['message'],
			$failure_code
		);

		// Update order status to failed.
		$order->update_status( 'failed', $failure_note );

		// Add metadata to identify this as simulated and save.
		$order->add_meta_data( '_paysentinel_simulated_failure', true, true );
		$order->add_meta_data( '_paysentinel_failure_scenario', $scenario_key, true );
		$order->add_meta_data( '_paysentinel_failure_message', $scenario['message'], true );
		$order->add_meta_data( '_paysentinel_failure_code', $failure_code, true );
		$order->save();

		// Explicitly write to the transactions table with the [SIMULATED FAILURE] marker.
		// This is the authoritative record used by get_simulated_failure_order_ids() and
		// clear_simulated_failures(). We cannot rely solely on the woocommerce_order_status_failed
		// hook because it may not fire correctly in all environments (e.g. HPOS with missing
		// meta table) or its failure_reason extraction may miss the note.
		$this->upsert_simulated_failure_transaction( $order, $failure_note, $failure_code );

		return array(
			'success' => true,
			'message' => sprintf(
				/* translators: %1$s is the failure scenario name, %2$d is the order ID. */
				__( 'Successfully simulated %1$s failure for order #%2$d.', 'paysentinel' ),
				$scenario['name'],
				$order->get_id()
			),
		);
	}

	/**
	 * Upsert a transaction record in the transactions table for a simulated failure.
	 *
	 * Ensures a [SIMULATED FAILURE] record exists regardless of whether the
	 * woocommerce_order_status_failed hook fires correctly (e.g. HPOS environments
	 * where the hook + logger chain may not populate the table).
	 *
	 * @param WC_Order $order        The order object.
	 * @param string   $failure_note The full failure note string (starts with '[SIMULATED FAILURE]').
	 * @param string   $failure_code The failure code string.
	 */
	private function upsert_simulated_failure_transaction( $order, $failure_note, $failure_code ) {
		global $wpdb;

		$table_name = $this->database->get_transactions_table();
		$order_id   = $order->get_id();

		// Ensure the table exists — guards against the activation hook not completing (e.g. prior
		// fatal error), which would leave the transactions table absent and all DB calls silently
		// returning false/empty. dbDelta is idempotent; running it here is safe.
		// IMPORTANT: needs_update() only checks the version option, which can be set from a prior
		// partial activation even when the tables were never actually created. tables_exist() does
		// the authoritative SHOW TABLES check.
		if ( $this->database->needs_update() || ! $this->database->tables_exist() ) {
			// error_log( '[PaySentinel] upsert_simulated_failure_transaction: tables missing or outdated — running create_tables().' );
			$this->database->create_tables();
		}

		// error_log( '[PaySentinel] upsert_simulated_failure_transaction: writing record for order #' . $order_id . ', table=' . $table_name );

		// Check if a record already exists for this order (the hook may have already logged it).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$existing_id = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM %i WHERE order_id = %d ORDER BY created_at DESC LIMIT 1',
				$table_name,
				$order_id
			)
		);

		if ( $existing_id ) {
			// Update the existing record to ensure the failure_reason has the [SIMULATED FAILURE] marker.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$result = $wpdb->update(
				$table_name,
				array(
					'status'         => 'failed',
					'failure_reason' => $failure_note,
					'failure_code'   => $failure_code,
					'updated_at'     => current_time( 'mysql' ),
				),
				array( 'id' => $existing_id ),
				array( '%s', '%s', '%s', '%s' ),
				array( '%d' )
			);

			if ( false === $result && $wpdb->last_error ) {
				// error_log( '[PaySentinel] upsert_simulated_failure_transaction UPDATE failed for order #' . $order_id . ': ' . $wpdb->last_error );
			}
		} else {
			// Insert a new record so get_simulated_failure_order_ids() can find this order.
			$gateway_id = $order->get_payment_method();
			$currency   = $order->get_currency();
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$result = $wpdb->insert(
				$table_name,
				array(
					'order_id'       => $order_id,
					'gateway_id'     => $gateway_id ? $gateway_id : 'simulated',
					'transaction_id' => null,
					'amount'         => floatval( $order->get_total() ),
					'currency'       => $currency ? $currency : 'USD',
					'status'         => 'failed',
					'failure_reason' => $failure_note,
					'failure_code'   => $failure_code,
					'retry_count'    => 0,
					'customer_email' => $order->get_billing_email(),
					'customer_ip'    => $order->get_customer_ip_address(),
					'created_at'     => current_time( 'mysql' ),
					'updated_at'     => null,
				),
				array( '%d', '%s', '%s', '%f', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s' )
			);

			if ( false === $result && $wpdb->last_error ) {
				// error_log( '[PaySentinel] upsert_simulated_failure_transaction INSERT failed for order #' . $order_id . ': ' . $wpdb->last_error );
			}
		}
	}

	/**
	 * Create a test order with simulated failure.
	 *
	 * @param string $scenario_key Scenario key.
	 * @param string $gateway_id   Gateway ID (optional, uses random enabled gateway if not specified).
	 *
	 * @return array Result with order_id.
	 */
	public function create_test_order_with_failure( $scenario_key, $gateway_id = null ) {
		if ( ! isset( self::FAILURE_SCENARIOS[ $scenario_key ] ) ) {
			return array(
				'success' => false,
				'message' => __( 'Invalid failure scenario.', 'paysentinel' ),
			);
		}

		// Use random enabled gateway if not specified.
		if ( empty( $gateway_id ) ) {
			$gateway_id = $this->get_random_enabled_gateway();
		}

		// Create a test order with realistic data.
		$order = wc_create_order();

		// Set basic order properties.
		$order->set_payment_method( $gateway_id );
		$order->set_currency( 'USD' );
		$order->set_billing_first_name( 'Test' );
		$order->set_billing_last_name( 'Customer' );
		$order->set_billing_address_1( '123 Test Street' );
		$order->set_billing_city( 'Test City' );
		$order->set_billing_state( 'CA' );
		$order->set_billing_postcode( '12345' );
		$order->set_billing_country( 'US' );

		// Add a test product.
		$product = wc_get_product( $this->get_or_create_test_product() );
		if ( $product ) {
			$order->add_product( $product, 1 );
		}

		// Calculate totals before setting transaction-specific data.
		$order->calculate_totals();

		// Now set transaction-specific data (after calculate_totals to ensure it's not cleared).
		$transaction_id = strtolower( $gateway_id ) . '_sim_' . uniqid() . '_' . time();
		$customer_email = 'test-failure-' . uniqid() . '@example.com';
		$customer_ip    = '192.168.1.' . wp_rand( 1, 254 );

		$order->set_transaction_id( $transaction_id );
		$order->set_billing_email( $customer_email );
		$order->set_customer_ip_address( $customer_ip );

		// Save order to persist all data before status change (like a real gateway would).
		$order->save();

		// Simulate the failure - pass the order object directly.
		$result = $this->simulate_failure_for_order( $order, $scenario_key );

		if ( $result['success'] ) {
			$result['order_id'] = $order->get_id();
		}

		return $result;
	}

	/**
	 * Get enabled payment gateways.
	 *
	 * @return array Array of gateway IDs.
	 */
	private function get_enabled_gateways() {
		$available_gateways = WC()->payment_gateways->get_available_payment_gateways();

		if ( empty( $available_gateways ) ) {
			// Fallback to common gateways if none are enabled.
			return array( 'stripe', 'paypal', 'bacs' );
		}

		return array_keys( $available_gateways );
	}

	/**
	 * Get a random enabled gateway.
	 *
	 * @return string Gateway ID.
	 */
	private function get_random_enabled_gateway() {
		$gateways = $this->get_enabled_gateways();
		return $gateways[ array_rand( $gateways ) ];
	}

	/**
	 * Get or create a test product.
	 *
	 * @return int Product ID.
	 */
	private function get_or_create_test_product() {
		// Check for existing test product.
		$existing = get_posts(
			array(
				'post_type'      => 'product',
				'posts_per_page' => 1,
				// phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_key'       => '_paysentinel_test_product',
				// phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'     => '1',
				'post_status'    => 'publish',
			)
		);

		if ( ! empty( $existing ) ) {
			return $existing[0]->ID;
		}

		// Create test product.
		$product = new WC_Product_Simple();
		$product->set_name( 'Payment Monitor Test Product' );
		$product->set_status( 'publish' );
		$product->set_catalog_visibility( 'hidden' );
		$product->set_price( 100.00 );
		$product->set_regular_price( 100.00 );
		$product_id = $product->save();

		update_post_meta( $product_id, '_paysentinel_test_product', '1' );

		return $product_id;
	}

	/**
	 * Generate bulk test failures.
	 *
	 * @param int    $count      Number of failures to generate.
	 * @param string $gateway_id Gateway ID (optional, uses random enabled gateways if not specified).
	 * @param array  $scenarios  Specific scenarios to use (empty for random).
	 *
	 * @return array Results.
	 */
	public function generate_bulk_failures( $count, $gateway_id = null, $scenarios = array() ) {
		$results = array(
			'success'   => 0,
			'failed'    => 0,
			'order_ids' => array(),
			'errors'    => array(),
			'gateways'  => array(),
		);

		$available_scenarios = empty( $scenarios ) ? array_keys( self::FAILURE_SCENARIOS ) : $scenarios;
		$enabled_gateways    = $this->get_enabled_gateways();

		for ( $i = 0; $i < $count; $i++ ) {
			$scenario_key = $available_scenarios[ array_rand( $available_scenarios ) ];

			// Use specified gateway or rotate through enabled gateways.
			$current_gateway = $gateway_id ? $gateway_id : $enabled_gateways[ $i % count( $enabled_gateways ) ];

			$result = $this->create_test_order_with_failure( $scenario_key, $current_gateway );

			if ( $result['success'] ) {
				++$results['success'];
				$results['order_ids'][] = $result['order_id'];

				// Track which gateways were used.
				if ( ! isset( $results['gateways'][ $current_gateway ] ) ) {
					$results['gateways'][ $current_gateway ] = 0;
				}
				++$results['gateways'][ $current_gateway ];
			} else {
				++$results['failed'];
				$results['errors'][] = $result['message'];
			}
		}

		return $results;
	}

	/**
	 * Get all failure scenarios.
	 *
	 * @return array Failure scenarios.
	 */
	public function get_all_scenarios() {
		return self::FAILURE_SCENARIOS;
	}

	/**
	 * Get available payment gateways.
	 *
	 * @return array Array of gateway data.
	 */
	public function get_available_gateways() {
		$available_gateways = WC()->payment_gateways->get_available_payment_gateways();
		$gateways_data      = array();

		foreach ( $available_gateways as $gateway_id => $gateway ) {
			$gateways_data[] = array(
				'id'      => $gateway_id,
				'title'   => $gateway->get_title(),
				'enabled' => $gateway->enabled === 'yes',
			);
		}

		return $gateways_data;
	}

	/**
	 * Get statistics on simulated failures.
	 *
	 * @return array Statistics.
	 */
	public function get_simulation_stats() {
		global $wpdb;

		$table_name = $this->database->get_transactions_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$results = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT failure_code, COUNT(*) as count
				FROM %i
				WHERE status = %s
				AND failure_reason LIKE %s
				GROUP BY failure_code',
				$table_name,
				'failed',
				'[SIMULATED FAILURE]%'
			)
		);

		$stats = array(
			'total_simulated' => 0,
			'by_scenario'     => array(),
		);

		foreach ( $results as $row ) {
			$stats['total_simulated']                  += $row->count;
			$stats['by_scenario'][ $row->failure_code ] = $row->count;
		}

		return $stats;
	}

	/**
	 * Get order IDs with simulated failures.
	 *
	 * Queries the transactions table using the [SIMULATED FAILURE] marker in
	 * failure_reason. This is storage-model agnostic and reliable regardless
	 * of whether HPOS or its meta table exists.
	 *
	 * @return array Order IDs.
	 */
	private function get_simulated_failure_order_ids() {
		global $wpdb;

		$table_name = $this->database->get_transactions_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$order_ids = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT DISTINCT order_id FROM %i WHERE failure_reason LIKE %s AND order_id > 0',
				$table_name,
				'[SIMULATED FAILURE]%'
			)
		);

		return array_map( 'absint', $order_ids );
	}

	/**
	 * Clear all simulated failures.
	 *
	 * @return array Result.
	 */
	public function clear_simulated_failures() {
		global $wpdb;

		// Get orders with simulated failures - works with both legacy and HPOS storage.
		$order_ids = $this->get_simulated_failure_order_ids();

		$deleted_orders = count( $order_ids );

		// Delete transaction records first (before deleting orders).
		$deleted_transactions = 0;
		$table_name           = $this->database->get_transactions_table();

		if ( ! empty( $order_ids ) ) {
			$order_ids_string = implode( ',', array_map( 'absint', $order_ids ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$deleted_transactions = $wpdb->query(
				$wpdb->prepare(
					"DELETE FROM %i WHERE order_id IN ({$order_ids_string})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$table_name
				)
			);
		}

		// Now delete the orders using WooCommerce's proper method.
		foreach ( $order_ids as $order_id ) {
			$order = wc_get_order( $order_id );
			if ( $order ) {
				$order->delete( true );
			}
		}

		return array(
			'success'              => true,
			'deleted_orders'       => $deleted_orders,
			'deleted_transactions' => $deleted_transactions,
			'message'              => sprintf(
				/* translators: %1$d is the number of deleted orders, %2$d is the number of deleted transactions. */
				__( 'Cleared %1$d simulated orders and %2$d transaction records.', 'paysentinel' ),
				$deleted_orders,
				$deleted_transactions
			),
		);
	}
}
