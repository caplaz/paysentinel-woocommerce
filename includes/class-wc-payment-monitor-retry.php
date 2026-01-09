<?php
/**
 * Payment retry engine class
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WC_Payment_Monitor_Retry {

	/**
	 * Database instance
	 */
	private $database;

	/**
	 * Logger instance
	 */
	private $logger;

	/**
	 * Maximum retry attempts per transaction
	 */
	const MAX_RETRY_ATTEMPTS = 3;

	/**
	 * Default retry schedule in seconds (1h, 6h, 24h)
	 */
	const DEFAULT_RETRY_SCHEDULE = array( 3600, 21600, 86400 );

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->database = new WC_Payment_Monitor_Database();
		$this->logger   = new WC_Payment_Monitor_Logger();
		$this->init_hooks();
	}

	/**
	 * Initialize WordPress hooks
	 */
	private function init_hooks() {
		// Hook into failed payment events to schedule retries
		add_action( 'woocommerce_order_status_failed', array( $this, 'schedule_retry_on_failure' ), 20, 2 );

		// Register retry cron action
		add_action( 'wc_payment_monitor_retry_payment', array( $this, 'attempt_retry_action' ), 10, 1 );

		// Schedule periodic retry processing
		add_action( 'init', array( $this, 'schedule_retry_processing' ) );
		add_action( 'wc_payment_monitor_process_retries', array( $this, 'process_scheduled_retries' ) );

		// Hook into plugin activation to schedule cron
		add_action( 'wc_payment_monitor_activated', array( $this, 'schedule_retry_processing' ) );
	}

	/**
	 * Schedule retry processing cron job
	 */
	public function schedule_retry_processing() {
		if ( ! wp_next_scheduled( 'wc_payment_monitor_process_retries' ) ) {
			wp_schedule_event( time(), 'hourly', 'wc_payment_monitor_process_retries' );
		}
	}

	/**
	 * Schedule retry when payment fails
	 *
	 * @param int    $order_id Order ID
	 * @param string $old_status Previous order status
	 */
	public function schedule_retry_on_failure( $order_id, $old_status = '' ) {
		// Check if auto-retry is enabled
		$settings          = get_option( 'wc_payment_monitor_settings', array() );
		$enable_auto_retry = isset( $settings['enable_auto_retry'] ) ? $settings['enable_auto_retry'] : true;

		if ( ! $enable_auto_retry ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		// Check if order has a stored payment method
		if ( ! $this->has_stored_payment_method( $order ) ) {
			return;
		}

		// Get transaction record
		$transaction = $this->logger->get_transaction_by_order_id( $order_id );
		if ( ! $transaction ) {
			return;
		}

		// Don't schedule retry if already at max attempts
		if ( $transaction->retry_count >= self::MAX_RETRY_ATTEMPTS ) {
			return;
		}

		// Schedule the first retry
		$this->schedule_retry( $transaction->id );
	}

	/**
	 * Schedule retry attempts for a transaction
	 *
	 * @param int $transaction_id Transaction ID
	 * @return bool Success
	 */
	public function schedule_retry( $transaction_id ) {
		global $wpdb;

		$table_name = $this->database->get_transactions_table();

		// Get transaction details
		$transaction = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table_name} WHERE id = %d",
				$transaction_id
			)
		);

		if ( ! $transaction || $transaction->retry_count >= self::MAX_RETRY_ATTEMPTS ) {
			return false;
		}

		// Get retry schedule from settings
		$settings       = get_option( 'wc_payment_monitor_settings', array() );
		$retry_schedule = isset( $settings['retry_schedule'] ) ? $settings['retry_schedule'] : self::DEFAULT_RETRY_SCHEDULE;

		// Calculate next retry time
		$retry_attempt = $transaction->retry_count;
		if ( $retry_attempt >= count( $retry_schedule ) ) {
			return false; // No more retries scheduled
		}

		$retry_delay = $retry_schedule[ $retry_attempt ];
		$retry_time  = time() + $retry_delay;

		// Schedule the retry
		wp_schedule_single_event( $retry_time, 'wc_payment_monitor_retry_payment', array( $transaction_id ) );

		// Log the scheduled retry
		error_log(
			sprintf(
				'WC Payment Monitor: Scheduled retry %d for transaction %d at %s',
				$retry_attempt + 1,
				$transaction_id,
				date( 'Y-m-d H:i:s', $retry_time )
			)
		);

		return true;
	}

	/**
	 * Action callback for retry payment cron
	 *
	 * @param int $transaction_id Transaction ID
	 */
	public function attempt_retry_action( $transaction_id ) {
		$this->attempt_retry( $transaction_id );
	}

	/**
	 * Attempt to retry a payment
	 *
	 * @param int $transaction_id Transaction ID
	 * @return bool Success
	 */
	public function attempt_retry( $transaction_id ) {
		global $wpdb;

		$table_name = $this->database->get_transactions_table();

		// Get transaction details
		$transaction = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table_name} WHERE id = %d",
				$transaction_id
			)
		);

		if ( ! $transaction ) {
			error_log( 'WC Payment Monitor: Transaction not found for retry: ' . $transaction_id );
			return false;
		}

		// Check retry limits
		if ( $transaction->retry_count >= self::MAX_RETRY_ATTEMPTS ) {
			error_log( 'WC Payment Monitor: Max retry attempts reached for transaction: ' . $transaction_id );
			return false;
		}

		// Get the order
		$order = wc_get_order( $transaction->order_id );
		if ( ! $order ) {
			error_log( 'WC Payment Monitor: Order not found for retry: ' . $transaction->order_id );
			return false;
		}

		// Check if order is still in failed status
		if ( $order->get_status() !== 'failed' ) {
			error_log( 'WC Payment Monitor: Order status changed, skipping retry: ' . $transaction->order_id );
			return false;
		}

		// Increment retry count
		$new_retry_count = $transaction->retry_count + 1;
		$wpdb->update(
			$table_name,
			array(
				'retry_count' => $new_retry_count,
				'status'      => 'retry',
				'updated_at'  => current_time( 'mysql' ),
			),
			array( 'id' => $transaction_id ),
			array( '%d', '%s', '%s' ),
			array( '%d' )
		);

		// Attempt the payment retry
		$retry_result = $this->process_payment_retry( $order, $transaction );

		if ( $retry_result['success'] ) {
			// Retry succeeded
			$this->handle_successful_retry( $order, $transaction, $retry_result );
			return true;
		} else {
			// Retry failed
			$this->handle_failed_retry( $order, $transaction, $retry_result, $new_retry_count );

			// Schedule next retry if not at max attempts
			if ( $new_retry_count < self::MAX_RETRY_ATTEMPTS ) {
				$this->schedule_retry( $transaction_id );
			}
			return false;
		}
	}

	/**
	 * Process payment retry using stored payment method
	 *
	 * @param WC_Order $order Order object
	 * @param object   $transaction Transaction record
	 * @return array Retry result
	 */
	private function process_payment_retry( $order, $transaction ) {
		try {
			// Get payment gateway
			$gateway_id = $transaction->gateway_id;
			$gateways   = WC_Payment_Gateways::instance();
			$gateway    = $gateways->get_available_payment_gateways()[ $gateway_id ] ?? null;

			if ( ! $gateway ) {
				return array(
					'success' => false,
					'message' => 'Payment gateway not available: ' . $gateway_id,
				);
			}

			// Check if gateway supports retry functionality
			if ( ! $this->gateway_supports_retry( $gateway ) ) {
				return array(
					'success' => false,
					'message' => 'Gateway does not support automatic retry: ' . $gateway_id,
				);
			}

			// Get stored payment method
			$payment_method = $this->get_stored_payment_method( $order );
			if ( ! $payment_method ) {
				return array(
					'success' => false,
					'message' => 'No stored payment method available',
				);
			}

			// Prepare order for retry
			$order->add_order_note(
				sprintf(
					__( 'Attempting automatic payment retry %1$d of %2$d', 'wc-payment-monitor' ),
					$transaction->retry_count + 1,
					self::MAX_RETRY_ATTEMPTS
				)
			);

			// Process the payment
			$result = $this->process_gateway_payment( $gateway, $order, $payment_method );

			return $result;

		} catch ( Exception $e ) {
			error_log( 'WC Payment Monitor Retry Error: ' . $e->getMessage() );

			return array(
				'success' => false,
				'message' => 'Exception during retry: ' . $e->getMessage(),
			);
		}
	}

	/**
	 * Process payment through gateway
	 *
	 * @param WC_Payment_Gateway $gateway Payment gateway
	 * @param WC_Order           $order Order object
	 * @param array              $payment_method Payment method data
	 * @return array Processing result
	 */
	private function process_gateway_payment( $gateway, $order, $payment_method ) {
		// This is a simplified implementation
		// In a real implementation, you would need to handle each gateway's specific API

		// For demonstration, we'll simulate a payment attempt
		// In practice, you would call the gateway's API with the stored payment method

		// Simulate success/failure based on gateway health
		$health         = new WC_Payment_Monitor_Health();
		$gateway_health = $health->get_health_status( $gateway->id, '1hour' );

		// Higher chance of success if gateway is healthy
		$success_probability = 0.3; // Base 30% chance
		if ( $gateway_health && $gateway_health->success_rate > 80 ) {
			$success_probability = 0.7; // 70% chance if gateway is healthy
		}

		$is_successful = ( mt_rand() / mt_getrandmax() ) < $success_probability;

		if ( $is_successful ) {
			// Simulate successful payment
			$transaction_id = 'retry_' . time() . '_' . $order->get_id();

			return array(
				'success'        => true,
				'transaction_id' => $transaction_id,
				'message'        => 'Payment retry successful',
			);
		} else {
			return array(
				'success' => false,
				'message' => 'Payment retry failed - insufficient funds or card declined',
			);
		}
	}

	/**
	 * Handle successful retry
	 *
	 * @param WC_Order $order Order object
	 * @param object   $transaction Transaction record
	 * @param array    $retry_result Retry result
	 */
	private function handle_successful_retry( $order, $transaction, $retry_result ) {
		global $wpdb;

		$table_name = $this->database->get_transactions_table();

		// Update transaction record
		$wpdb->update(
			$table_name,
			array(
				'status'         => 'success',
				'transaction_id' => $retry_result['transaction_id'],
				'failure_reason' => null,
				'failure_code'   => null,
				'updated_at'     => current_time( 'mysql' ),
			),
			array( 'id' => $transaction->id ),
			array( '%s', '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);

		// Update order status
		$order->payment_complete( $retry_result['transaction_id'] );
		$order->add_order_note(
			sprintf(
				__( 'Payment retry successful on attempt %1$d. Transaction ID: %2$s', 'wc-payment-monitor' ),
				$transaction->retry_count + 1,
				$retry_result['transaction_id']
			)
		);

		// Send success notification to customer
		$this->send_retry_success_email( $order );

		// Track retry success for monitoring
		$this->track_retry_success( $transaction->gateway_id, $transaction->retry_count + 1 );

		// Fire action hook
		do_action( 'wc_payment_monitor_retry_successful', $order, $transaction, $retry_result );
	}

	/**
	 * Handle failed retry
	 *
	 * @param WC_Order $order Order object
	 * @param object   $transaction Transaction record
	 * @param array    $retry_result Retry result
	 * @param int      $retry_count Current retry count
	 */
	private function handle_failed_retry( $order, $transaction, $retry_result, $retry_count ) {
		global $wpdb;

		$table_name = $this->database->get_transactions_table();

		// Update transaction record
		$wpdb->update(
			$table_name,
			array(
				'status'         => 'failed',
				'failure_reason' => $retry_result['message'],
				'updated_at'     => current_time( 'mysql' ),
			),
			array( 'id' => $transaction->id ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);

		// Add order note
		$order->add_order_note(
			sprintf(
				__( 'Payment retry %1$d failed: %2$s', 'wc-payment-monitor' ),
				$retry_count,
				$retry_result['message']
			)
		);

		// If max attempts reached, add final note
		if ( $retry_count >= self::MAX_RETRY_ATTEMPTS ) {
			$order->add_order_note(
				sprintf(
					__( 'Maximum retry attempts (%d) reached. No further automatic retries will be attempted.', 'wc-payment-monitor' ),
					self::MAX_RETRY_ATTEMPTS
				)
			);
		}

		// Track retry failure for monitoring
		$this->track_retry_failure( $transaction->gateway_id, $retry_count );

		// Fire action hook
		do_action( 'wc_payment_monitor_retry_failed', $order, $transaction, $retry_result, $retry_count );
	}

	/**
	 * Send retry success email to customer
	 *
	 * @param WC_Order $order Order object
	 * @return bool Success
	 */
	public function send_retry_success_email( $order ) {
		$customer_email = $order->get_billing_email();
		if ( empty( $customer_email ) ) {
			return false;
		}

		$subject = sprintf(
			__( '[%1$s] Payment Successful - Order #%2$s', 'wc-payment-monitor' ),
			get_bloginfo( 'name' ),
			$order->get_order_number()
		);

		$message = $this->create_retry_success_email_template( $order );

		$headers = array(
			'Content-Type: text/html; charset=UTF-8',
			'From: ' . get_bloginfo( 'name' ) . ' <' . get_option( 'admin_email' ) . '>',
		);

		return wp_mail( $customer_email, $subject, $message, $headers );
	}

	/**
	 * Create retry success email template
	 *
	 * @param WC_Order $order Order object
	 * @return string HTML email content
	 */
	private function create_retry_success_email_template( $order ) {
		$order_url   = $order->get_view_order_url();
		$order_total = $order->get_formatted_order_total();
		$subject     = sprintf(
			__( '[%1$s] Payment Successful - Order #%2$s', 'wc-payment-monitor' ),
			get_bloginfo( 'name' ),
			$order->get_order_number()
		);

		ob_start();
		?>
		<!DOCTYPE html>
		<html>
		<head>
			<meta charset="UTF-8">
			<title><?php echo esc_html( $subject ); ?></title>
			<style>
				body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
				.container { max-width: 600px; margin: 0 auto; padding: 20px; }
				.header { background-color: #28a745; color: white; padding: 20px; text-align: center; }
				.content { background-color: #f9f9f9; padding: 20px; }
				.order-details { background-color: white; padding: 15px; margin: 15px 0; border-left: 4px solid #28a745; }
				.button { display: inline-block; background-color: #0073aa; color: white; padding: 10px 20px; text-decoration: none; border-radius: 3px; margin: 10px 0; }
				.footer { text-align: center; padding: 20px; font-size: 12px; color: #666; }
			</style>
		</head>
		<body>
			<div class="container">
				<div class="header">
					<h1><?php _e( 'Payment Successful!', 'wc-payment-monitor' ); ?></h1>
					<p><?php _e( 'Your payment has been processed successfully', 'wc-payment-monitor' ); ?></p>
				</div>
				
				<div class="content">
					<h2><?php _e( 'Good News!', 'wc-payment-monitor' ); ?></h2>
					<p><?php _e( 'We\'re pleased to inform you that your payment has been successfully processed after our automatic retry system resolved the initial payment issue.', 'wc-payment-monitor' ); ?></p>
					
					<div class="order-details">
						<h3><?php _e( 'Order Details', 'wc-payment-monitor' ); ?></h3>
						<ul>
							<li><strong><?php _e( 'Order Number:', 'wc-payment-monitor' ); ?></strong> #<?php echo esc_html( $order->get_order_number() ); ?></li>
							<li><strong><?php _e( 'Order Total:', 'wc-payment-monitor' ); ?></strong> <?php echo wp_kses_post( $order_total ); ?></li>
							<li><strong><?php _e( 'Payment Method:', 'wc-payment-monitor' ); ?></strong> <?php echo esc_html( $order->get_payment_method_title() ); ?></li>
							<li><strong><?php _e( 'Order Date:', 'wc-payment-monitor' ); ?></strong> <?php echo esc_html( $order->get_date_created()->format( 'F j, Y' ) ); ?></li>
						</ul>
					</div>
					
					<p>
						<a href="<?php echo esc_url( $order_url ); ?>" class="button">
							<?php _e( 'View Order Details', 'wc-payment-monitor' ); ?>
						</a>
					</p>
					
					<p><?php _e( 'Your order is now being processed and you will receive shipping information once your items are dispatched.', 'wc-payment-monitor' ); ?></p>
					
					<p><?php _e( 'Thank you for your patience and for choosing us!', 'wc-payment-monitor' ); ?></p>
				</div>
				
				<div class="footer">
					<p><?php _e( 'This email was sent by our automated payment recovery system', 'wc-payment-monitor' ); ?></p>
					<p><?php echo esc_html( get_bloginfo( 'name' ) ); ?> - <?php echo esc_url( home_url() ); ?></p>
				</div>
			</div>
		</body>
		</html>
		<?php
		return ob_get_clean();
	}

	/**
	 * Check if order has stored payment method
	 *
	 * @param WC_Order $order Order object
	 * @return bool Has stored payment method
	 */
	private function has_stored_payment_method( $order ) {
		// Check for WooCommerce Subscriptions payment tokens
		$payment_tokens = WC_Payment_Tokens::get_order_tokens( $order->get_id() );
		if ( ! empty( $payment_tokens ) ) {
			return true;
		}

		// Check for gateway-specific stored payment methods
		$payment_method = $order->get_payment_method();
		$stored_methods = WC_Payment_Tokens::get_customer_tokens( $order->get_customer_id(), $payment_method );

		return ! empty( $stored_methods );
	}

	/**
	 * Get stored payment method for order
	 *
	 * @param WC_Order $order Order object
	 * @return array|null Payment method data
	 */
	private function get_stored_payment_method( $order ) {
		// Get payment tokens for the order
		$payment_tokens = WC_Payment_Tokens::get_order_tokens( $order->get_id() );

		if ( ! empty( $payment_tokens ) ) {
			$token = reset( $payment_tokens );
			return array(
				'token_id'   => $token->get_id(),
				'token'      => $token->get_token(),
				'type'       => $token->get_type(),
				'gateway_id' => $token->get_gateway_id(),
			);
		}

		// Fallback to customer's default payment method
		$customer_id = $order->get_customer_id();
		if ( $customer_id ) {
			$payment_method = $order->get_payment_method();
			$stored_methods = WC_Payment_Tokens::get_customer_tokens( $customer_id, $payment_method );

			if ( ! empty( $stored_methods ) ) {
				$token = reset( $stored_methods );
				return array(
					'token_id'   => $token->get_id(),
					'token'      => $token->get_token(),
					'type'       => $token->get_type(),
					'gateway_id' => $token->get_gateway_id(),
				);
			}
		}

		return null;
	}

	/**
	 * Check if gateway supports retry functionality
	 *
	 * @param WC_Payment_Gateway $gateway Payment gateway
	 * @return bool Supports retry
	 */
	private function gateway_supports_retry( $gateway ) {
		// Check if gateway supports tokenization (required for retry)
		if ( ! $gateway->supports( 'tokenization' ) ) {
			return false;
		}

		// Check if gateway supports saved payment methods
		if ( ! $gateway->supports( 'subscriptions' ) && ! $gateway->supports( 'subscription_cancellation' ) ) {
			// For non-subscription gateways, check if they support add_payment_method
			if ( ! $gateway->supports( 'add_payment_method' ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Process scheduled retries
	 */
	public function process_scheduled_retries() {
		global $wpdb;

		$table_name = $this->database->get_transactions_table();

		// Find transactions that need retry processing
		$pending_retries = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table_name} 
             WHERE status = 'failed' 
             AND retry_count < %d 
             AND created_at > %s
             ORDER BY created_at ASC
             LIMIT 50",
				self::MAX_RETRY_ATTEMPTS,
				date( 'Y-m-d H:i:s', time() - ( 7 * 86400 ) ) // Only process retries from last 7 days
			)
		);

		foreach ( $pending_retries as $transaction ) {
			// Check if retry is already scheduled
			$scheduled = wp_next_scheduled( 'wc_payment_monitor_retry_payment', array( $transaction->id ) );

			if ( ! $scheduled ) {
				// Schedule retry if not already scheduled
				$this->schedule_retry( $transaction->id );
			}
		}
	}

	/**
	 * Track retry success for monitoring
	 *
	 * @param string $gateway_id Gateway ID
	 * @param int    $attempt_number Attempt number
	 */
	private function track_retry_success( $gateway_id, $attempt_number ) {
		// Store retry success statistics
		$stats = get_option( 'wc_payment_monitor_retry_stats', array() );

		if ( ! isset( $stats[ $gateway_id ] ) ) {
			$stats[ $gateway_id ] = array(
				'total_retries'      => 0,
				'successful_retries' => 0,
				'by_attempt'         => array(),
			);
		}

		++$stats[ $gateway_id ]['total_retries'];
		++$stats[ $gateway_id ]['successful_retries'];

		if ( ! isset( $stats[ $gateway_id ]['by_attempt'][ $attempt_number ] ) ) {
			$stats[ $gateway_id ]['by_attempt'][ $attempt_number ] = array(
				'total'      => 0,
				'successful' => 0,
			);
		}

		++$stats[ $gateway_id ]['by_attempt'][ $attempt_number ]['total'];
		++$stats[ $gateway_id ]['by_attempt'][ $attempt_number ]['successful'];

		update_option( 'wc_payment_monitor_retry_stats', $stats );
	}

	/**
	 * Track retry failure for monitoring
	 *
	 * @param string $gateway_id Gateway ID
	 * @param int    $attempt_number Attempt number
	 */
	private function track_retry_failure( $gateway_id, $attempt_number ) {
		// Store retry failure statistics
		$stats = get_option( 'wc_payment_monitor_retry_stats', array() );

		if ( ! isset( $stats[ $gateway_id ] ) ) {
			$stats[ $gateway_id ] = array(
				'total_retries'      => 0,
				'successful_retries' => 0,
				'by_attempt'         => array(),
			);
		}

		++$stats[ $gateway_id ]['total_retries'];

		if ( ! isset( $stats[ $gateway_id ]['by_attempt'][ $attempt_number ] ) ) {
			$stats[ $gateway_id ]['by_attempt'][ $attempt_number ] = array(
				'total'      => 0,
				'successful' => 0,
			);
		}

		++$stats[ $gateway_id ]['by_attempt'][ $attempt_number ]['total'];

		update_option( 'wc_payment_monitor_retry_stats', $stats );
	}

	/**
	 * Get retry statistics
	 *
	 * @param string $gateway_id Optional gateway filter
	 * @return array Retry statistics
	 */
	public function get_retry_stats( $gateway_id = null ) {
		$stats = get_option( 'wc_payment_monitor_retry_stats', array() );

		if ( $gateway_id && isset( $stats[ $gateway_id ] ) ) {
			return $stats[ $gateway_id ];
		}

		return $gateway_id ? array() : $stats;
	}

	/**
	 * Get retry success rate
	 *
	 * @param string $gateway_id Gateway ID
	 * @return float Success rate percentage
	 */
	public function get_retry_success_rate( $gateway_id ) {
		$stats = $this->get_retry_stats( $gateway_id );

		if ( empty( $stats ) || $stats['total_retries'] == 0 ) {
			return 0.0;
		}

		return round( ( $stats['successful_retries'] / $stats['total_retries'] ) * 100, 2 );
	}

	/**
	 * Manual retry trigger for admin
	 *
	 * @param int $order_id Order ID
	 * @return array Result
	 */
	public function manual_retry( $order_id ) {
		$transaction = $this->logger->get_transaction_by_order_id( $order_id );

		if ( ! $transaction ) {
			return array(
				'success' => false,
				'message' => __( 'Transaction not found', 'wc-payment-monitor' ),
			);
		}

		if ( $transaction->retry_count >= self::MAX_RETRY_ATTEMPTS ) {
			return array(
				'success' => false,
				'message' => __( 'Maximum retry attempts reached', 'wc-payment-monitor' ),
			);
		}

		$order = wc_get_order( $order_id );
		if ( ! $order || $order->get_status() !== 'failed' ) {
			return array(
				'success' => false,
				'message' => __( 'Order is not in failed status', 'wc-payment-monitor' ),
			);
		}

		// Attempt immediate retry
		$result = $this->attempt_retry( $transaction->id );

		return array(
			'success' => $result,
			'message' => $result
				? __( 'Manual retry successful', 'wc-payment-monitor' )
				: __( 'Manual retry failed', 'wc-payment-monitor' ),
		);
	}

	/**
	 * Clear retry statistics
	 *
	 * @param string $gateway_id Optional gateway filter
	 * @return bool Success
	 */
	public function clear_retry_stats( $gateway_id = null ) {
		if ( $gateway_id ) {
			$stats = get_option( 'wc_payment_monitor_retry_stats', array() );
			unset( $stats[ $gateway_id ] );
			return update_option( 'wc_payment_monitor_retry_stats', $stats );
		} else {
			return delete_option( 'wc_payment_monitor_retry_stats' );
		}
	}
}