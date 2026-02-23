<?php
/**
 * Payment retry engine class
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PaySentinel_Retry {

	/**
	 * Database instance
	 */
	private $database;

	/**
	 * Logger instance
	 */
	private $logger;

	/**
	 * Maximum retry attempts per transaction (fallback)
	 */
	public const MAX_RETRY_ATTEMPTS = 3;

	/**
	 * Default retry schedule in seconds (1h, 6h, 24h)
	 */
	public const DEFAULT_RETRY_SCHEDULE = array( 3600, 21600, 86400 );

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->database = new PaySentinel_Database();
		$this->logger   = new PaySentinel_Logger();
		$this->init_hooks();
	}

	/**
	 * Initialize WordPress hooks
	 */
	private function init_hooks() {
		// Hook into local failure event (fired by Logger) to schedule retries
		add_action( 'paysentinel_payment_failed', array( $this, 'schedule_retry_on_failure' ), 10, 2 );

		// Register retry action scheduler
		add_action( 'paysentinel_retry_payment', array( $this, 'attempt_retry_action' ), 10, 1 );
	}

	/**
	 * Schedule retry when payment fails
	 *
	 * @param int    $order_id   Order ID
	 * @param string $old_status Previous order status
	 */
	public function schedule_retry_on_failure( $order_id, $old_status = '' ) {
		// Check if auto-retry is enabled
		if ( ! PaySentinel_Config::instance()->is_retry_enabled() ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		// Check if order has a stored payment method
		if ( ! $this->has_stored_payment_method( $order ) ) {
			// No stored method, send recovery email immediately
			$this->send_recovery_email( $order );
			return;
		}

		// Get transaction record
		$transaction = $this->logger->get_transaction_by_order_id( $order_id );
		if ( ! $transaction ) {
			return;
		}

		// Don't schedule retry if already at max attempts
		$max_retries = PaySentinel_Config::instance()->get_max_retry_attempts();
		if ( $transaction->retry_count >= $max_retries ) {
			return;
		}

		// Analyze failure reason to decide if we should retry
		if ( ! $this->analyze_failure_reason( $transaction->failure_reason ) ) {
			// Hard decline: Send recovery email instead of retrying
			$this->send_recovery_email( $order );
			return;
		}

		// Schedule the first retry
		$this->schedule_retry( $transaction->id );
	}

	/**
	 * Analyze failure reason to determine if retry is worthwhile
	 *
	 * @param string $reason Failure reason/message
	 *
	 * @return bool True if retry should be attempted
	 */
	private function analyze_failure_reason( $reason ) {
		if ( empty( $reason ) ) {
			return true;
		}

		$reason = strtolower( $reason );

		// List of keywords that indicate permanent failures
		$permanent_failures = array(
			'fraud',
			'do not honor',
			'stolen',
			'lost card',
			'pick up card',
			'invalid card number',
			'invalid account',
			'expired card',
			'closure',
			'stop recurring',
			'hard decline',
		);

		foreach ( $permanent_failures as $keyword ) {
			if ( strpos( $reason, $keyword ) !== false ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Schedule retry attempts for a transaction
	 *
	 * @param int $transaction_id Transaction ID
	 *
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

		$config      = PaySentinel_Config::instance();
		$max_retries = $config->get_max_retry_attempts();

		if ( ! $transaction || $transaction->retry_count >= $max_retries ) {
			return false;
		}

		// Get retry schedule from settings
		$settings       = $config->get_all();
		$retry_schedule = isset( $settings['retry_schedule'] ) ? $settings['retry_schedule'] : self::DEFAULT_RETRY_SCHEDULE;

		// Calculate next retry time
		$retry_attempt = $transaction->retry_count;
		if ( $retry_attempt >= count( $retry_schedule ) ) {
			// Fallback if we run out of schedule slots but haven't hit max retries:
			// Just use the last schedule interval or a default
			$retry_delay = end( $retry_schedule );
		} else {
			$retry_delay = $retry_schedule[ $retry_attempt ];
		}

		$retry_time = time() + $retry_delay;

		// Schedule with Action Scheduler
		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( $retry_time, 'paysentinel_retry_payment', array( $transaction_id ) );

			// Log the scheduled retry
			error_log(
				sprintf(
					'PaySentinel: Scheduled retry %d for transaction %d at %s via Action Scheduler',
					$retry_attempt + 1,
					$transaction_id,
					date( 'Y-m-d H:i:s', $retry_time )
				)
			);
			return true;
		} else {
			error_log( 'PaySentinel: Action Scheduler not available for retry scheduling' );
			return false;
		}
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
	 *
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
			error_log( 'PaySentinel: Transaction not found for retry: ' . $transaction_id );
			return false;
		}

		$max_retries = PaySentinel_Config::instance()->get_max_retry_attempts();

		// Check retry limits
		if ( $transaction->retry_count >= $max_retries ) {
			error_log( 'PaySentinel: Max retry attempts reached for transaction: ' . $transaction_id );
			return false;
		}

		// Get the order
		$order = wc_get_order( $transaction->order_id );
		if ( ! $order ) {
			error_log( 'PaySentinel: Order not found for retry: ' . $transaction->order_id );
			return false;
		}

		// Check if order is still in failed status
		if ( $order->get_status() !== 'failed' ) {
			error_log( 'PaySentinel: Order status changed, skipping retry: ' . $transaction->order_id );
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

			// Check if we should schedule another
			if ( $new_retry_count < $max_retries ) {
				$this->schedule_retry( $transaction_id );
			} else {
				// Max retries reached, send recovery email
				$this->send_recovery_email( $order );
			}
			return false;
		}
	}

	/**
	 * Process payment retry using stored payment method
	 *
	 * @param WC_Order $order       Order object
	 * @param object   $transaction Transaction record
	 *
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

			$settings    = get_option( 'paysentinel_options', array() );
			$max_retries = isset( $settings['max_retry_attempts'] ) ? intval( $settings['max_retry_attempts'] ) : self::MAX_RETRY_ATTEMPTS;

			// Prepare order for retry
			$order->add_order_note(
				sprintf(
					__( 'Attempting automatic payment retry %1$d of %2$d', 'paysentinel' ),
					$transaction->retry_count + 1,
					$max_retries
				)
			);

			// Process the payment
			$result = $this->process_gateway_payment( $gateway, $order, $payment_method );

			return $result;

		} catch ( Exception $e ) {
			error_log( 'PaySentinel Retry Error: ' . $e->getMessage() );

			return array(
				'success' => false,
				'message' => 'Exception during retry: ' . $e->getMessage(),
			);
		}
	}

	/**
	 * Process payment through gateway
	 *
	 * @param WC_Payment_Gateway $gateway        Payment gateway
	 * @param WC_Order           $order          Order object
	 * @param array              $payment_method Payment method data
	 *
	 * @return array Processing result
	 */
	private function process_gateway_payment( $gateway, $order, $payment_method ) {
		// Attempt to use official extensions' off-session mechanisms first (e.g. Subscriptions support)
		if ( method_exists( $gateway, 'scheduled_subscription_payment' ) ) {
			try {
				// The scheduled_subscription_payment method typically returns void
				// and throws an exception on failure, or updates order status.
				// We need to check the order status after the call.

				// Ensure global WC object is set if extensions rely on it
				if ( ! isset( $GLOBALS['woocommerce'] ) && function_exists( 'WC' ) ) {
					$GLOBALS['woocommerce'] = WC();
				}

				// Trigger the payment
				$gateway->scheduled_subscription_payment( $order->get_total(), $order );

				// Refresh order to check status changes made by the gateway
				$order = wc_get_order( $order->get_id() ); // Force reload

				if ( $order->has_status( 'processing' ) || $order->has_status( 'completed' ) ) {
					return array(
						'success'        => true,
						'transaction_id' => $order->get_transaction_id(),
						'message'        => __( 'Payment retry successful', 'paysentinel' ),
					);
				} else {
					// Retrieve error from order notes or logic
					return $this->capture_gateway_error( $order );
				}
			} catch ( Exception $e ) {
				return array(
					'success' => false,
					'message' => $e->getMessage(),
				);
			}
		}

		// Fallback: Standard Process Payment with a Token (if supported)
		// This is riskier as process_payment often assumes a user session (nonces, redirects)
		// but many token-capable gateways handle it gracefully if token_id is present in $_POST or passed props.

		try {
			// Some gateways look for $_POST['payment_token']
			if ( ! empty( $payment_method['token_id'] ) ) {
				$_POST['payment_token'] = $payment_method['token_id'];
			}

			// Capture any potential output from the gateway to avoid header errors
			ob_start();
			$result = $gateway->process_payment( $order->get_id() );
			ob_end_clean();

			// Clean up
			unset( $_POST['payment_token'] );

			if ( isset( $result['result'] ) && 'success' === $result['result'] ) {
				// Success
				// Note: process_payment might require a redirect URL, but since we are
				// in background, we care if the payment itself succeeded.
				// We check if order status moved to processing/completed.

				$order = wc_get_order( $order->get_id() ); // Reload
				if ( $order->has_status( 'processing' ) || $order->has_status( 'completed' ) ) {
					return array(
						'success'        => true,
						'transaction_id' => $order->get_transaction_id(),
						'message'        => __( 'Payment retry successful', 'paysentinel' ),
					);
				}
			}

			// If we got here, it failed or is pending redirect (which counts as failed for background retry)
			return $this->capture_gateway_error( $order );

		} catch ( Exception $e ) {
			return array(
				'success' => false,
				'message' => $e->getMessage(),
			);
		}
	}

	/**
	 * Capture specific gateway error details from order
	 *
	 * @param WC_Order $order Order Object
	 *
	 * @return array Failure details
	 */
	private function capture_gateway_error( $order ) {
		// 1. Check Order Meta for known gateway error keys

		// Stripe specific error capture
		if ( $order->get_meta( '_stripe_error_message' ) ) {
			return array(
				'success' => false,
				'message' => $order->get_meta( '_stripe_error_message' ),
				'code'    => $order->get_meta( '_stripe_error_code' ),
			);
		}

		// Stripe Intent errors
		if ( $order->get_meta( '_stripe_intent_error_message' ) ) {
			return array(
				'success' => false,
				'message' => $order->get_meta( '_stripe_intent_error_message' ),
				'code'    => 'stripe_intent_error',
			);
		}

		// PayPal specific error capture (often stored as _paypal_status associated meta)
		// Assuming standard logs or meta if available.

		// 2. Fallback: Parse recent Order Notes
		// We look for notices added by gateways "Payment failed: [Reason]"
		$notes = wc_get_order_notes(
			array(
				'order_id' => $order->get_id(),
				'limit'    => 5,
				'orderby'  => 'date_created_gmt',
				'order'    => 'DESC',
			)
		);

		if ( ! empty( $notes ) ) {
			foreach ( $notes as $note ) {
				// Look for failure language
				if ( stripos( $note->content, 'failed' ) !== false || stripos( $note->content, 'declined' ) !== false ) {
					return array(
						'success' => false,
						'message' => strip_tags( $note->content ),
						'code'    => 'gateway_failure_note',
					);
				}
			}
		}

		// 3. Fallback generic message
		return array(
			'success' => false,
			'message' => __( 'Payment retry failed. Please check gateway logs for details.', 'paysentinel' ),
			'code'    => 'unknown_error',
		);
	}

	/**
	 * Handle successful retry
	 *
	 * @param WC_Order $order        Order object
	 * @param object   $transaction  Transaction record
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
				__( 'Payment retry successful on attempt %1$d. Transaction ID: %2$s', 'paysentinel' ),
				$transaction->retry_count + 1,
				$retry_result['transaction_id']
			)
		);

		// Send success notification to customer
		$this->send_retry_success_email( $order );

		// Track retry success for monitoring
		$this->track_retry_success( $transaction->gateway_id, $transaction->retry_count + 1 );

		// Fire action hook
		do_action( 'paysentinel_retry_successful', $order, $transaction, $retry_result );
	}

	/**
	 * Handle failed retry
	 *
	 * @param WC_Order $order        Order object
	 * @param object   $transaction  Transaction record
	 * @param array    $retry_result Retry result
	 * @param int      $retry_count  Current retry count
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
				__( 'Payment retry %1$d failed: %2$s', 'paysentinel' ),
				$retry_count,
				$retry_result['message']
			)
		);

		$settings    = get_option( 'paysentinel_options', array() );
		$max_retries = isset( $settings['max_retry_attempts'] ) ? intval( $settings['max_retry_attempts'] ) : self::MAX_RETRY_ATTEMPTS;

		// If max attempts reached, add final note
		if ( $retry_count >= $max_retries ) {
			$order->add_order_note(
				sprintf(
					__( 'Maximum retry attempts (%d) reached. No further automatic retries will be attempted.', 'paysentinel' ),
					$max_retries
				)
			);
		}

		// Track retry failure for monitoring
		$this->track_retry_failure( $transaction->gateway_id, $retry_count );

		// Fire action hook
		do_action( 'paysentinel_retry_failed', $order, $transaction, $retry_result, $retry_count );
	}

	/**
	 * Send retry success email to customer
	 *
	 * @param WC_Order $order Order object
	 *
	 * @return bool Success
	 */
	public function send_retry_success_email( $order ) {
		$customer_email = $order->get_billing_email();
		if ( empty( $customer_email ) ) {
			return false;
		}

		$subject = sprintf(
			__( '[%1$s] Payment Successful - Order #%2$s', 'paysentinel' ),
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
	 *
	 * @return string HTML email content
	 */
	private function create_retry_success_email_template( $order ) {
		$order_url   = $order->get_view_order_url();
		$order_total = $order->get_formatted_order_total();
		$subject     = sprintf(
			__( '[%1$s] Payment Successful - Order #%2$s', 'paysentinel' ),
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
					<h1><?php _e( 'Payment Successful!', 'paysentinel' ); ?></h1>
					<p><?php _e( 'Your payment has been processed successfully', 'paysentinel' ); ?></p>
				</div>
				
				<div class="content">
					<h2><?php _e( 'Good News!', 'paysentinel' ); ?></h2>
					<p><?php _e( 'We\'re pleased to inform you that your payment has been successfully processed after our automatic retry system resolved the initial payment issue.', 'paysentinel' ); ?></p>
					
					<div class="order-details">
						<h3><?php _e( 'Order Details', 'paysentinel' ); ?></h3>
						<ul>
							<li><strong><?php _e( 'Order Number:', 'paysentinel' ); ?></strong> #<?php echo esc_html( $order->get_order_number() ); ?></li>
							<li><strong><?php _e( 'Order Total:', 'paysentinel' ); ?></strong> <?php echo wp_kses_post( $order_total ); ?></li>
							<li><strong><?php _e( 'Payment Method:', 'paysentinel' ); ?></strong> <?php echo esc_html( $order->get_payment_method_title() ); ?></li>
							<li><strong><?php _e( 'Order Date:', 'paysentinel' ); ?></strong> <?php echo esc_html( $order->get_date_created()->format( 'F j, Y' ) ); ?></li>
						</ul>
					</div>
					
					<p>
						<a href="<?php echo esc_url( $order_url ); ?>" class="button">
							<?php _e( 'View Order Details', 'paysentinel' ); ?>
						</a>
					</p>
					
					<p><?php _e( 'Your order is now being processed and you will receive shipping information once your items are dispatched.', 'paysentinel' ); ?></p>
					
					<p><?php _e( 'Thank you for your patience and for choosing us!', 'paysentinel' ); ?></p>
				</div>
				
				<div class="footer">
					<p><?php _e( 'This email was sent by our automated payment recovery system', 'paysentinel' ); ?></p>
					<p><?php echo esc_html( get_bloginfo( 'name' ) ); ?> - <?php echo esc_url( home_url() ); ?></p>
				</div>
			</div>
		</body>
		</html>
		<?php
		return ob_get_clean();
	}

	/**
	 * Send recovery email to customer (Manual Payment Link)
	 *
	 * @param WC_Order $order Order object
	 *
	 * @return bool Success
	 */
	public function send_recovery_email( $order ) {
		// Prevent spamming recovery emails (debounce: 5 minutes)
		$last_sent = $order->get_meta( '_paysentinel_recovery_sent' );
		if ( $last_sent && ( time() - intval( $last_sent ) < 300 ) ) {
			return false;
		}

		$customer_email = $order->get_billing_email();
		if ( empty( $customer_email ) ) {
			return false;
		}

		$subject = sprintf(
			__( '[%1$s] Action Required: Payment Failed for Order #%2$s', 'paysentinel' ),
			get_bloginfo( 'name' ),
			$order->get_order_number()
		);

		$message = $this->create_recovery_email_template( $order );

		$headers = array(
			'Content-Type: text/html; charset=UTF-8',
			'From: ' . get_bloginfo( 'name' ) . ' <' . get_option( 'admin_email' ) . '>',
		);

		// Flag as sent before adding note to avoid potential race conditions or loops
		$order->update_meta_data( '_paysentinel_recovery_sent', time() );
		$order->save();

		$order->add_order_note( __( 'Sent payment recovery email to customer.', 'paysentinel' ) );

		return wp_mail( $customer_email, $subject, $message, $headers );
	}

	/**
	 * Create recovery email template
	 *
	 * @param WC_Order $order Order object
	 *
	 * @return string HTML email content
	 */
	private function create_recovery_email_template( $order ) {
		$pay_link    = $order->get_checkout_payment_url();
		$order_total = $order->get_formatted_order_total();
		$subject     = sprintf(
			__( '[%1$s] Action Required: Payment Failed for Order #%2$s', 'paysentinel' ),
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
				.header { background-color: #d9534f; color: white; padding: 20px; text-align: center; }
				.content { background-color: #f9f9f9; padding: 20px; }
				.order-details { background-color: white; padding: 15px; margin: 15px 0; border-left: 4px solid #d9534f; }
				.button { display: inline-block; background-color: #d9534f; color: white; padding: 10px 20px; text-decoration: none; border-radius: 3px; margin: 10px 0; }
				.footer { text-align: center; padding: 20px; font-size: 12px; color: #666; }
			</style>
		</head>
		<body>
			<div class="container">
				<div class="header">
					<h1><?php _e( 'Payment Action Required', 'paysentinel' ); ?></h1>
					<p><?php _e( 'We were unable to process your payment', 'paysentinel' ); ?></p>
				</div>
				
				<div class="content">
					<h2><?php _e( 'Please Update Your Payment', 'paysentinel' ); ?></h2>
					<p><?php _e( 'Unfortunately, the payment for your recent order was declined. To ensure your order is processed without delay, please use the link below to complete your payment.', 'paysentinel' ); ?></p>
					
					<div class="order-details">
						<h3><?php _e( 'Order Details', 'paysentinel' ); ?></h3>
						<ul>
							<li><strong><?php _e( 'Order Number:', 'paysentinel' ); ?></strong> #<?php echo esc_html( $order->get_order_number() ); ?></li>
							<li><strong><?php _e( 'Order Total:', 'paysentinel' ); ?></strong> <?php echo wp_kses_post( $order_total ); ?></li>
							<li><strong><?php _e( 'Order Date:', 'paysentinel' ); ?></strong> <?php echo esc_html( $order->get_date_created()->format( 'F j, Y' ) ); ?></li>
						</ul>
					</div>
					
					<p style="text-align: center;">
						<a href="<?php echo esc_url( $pay_link ); ?>" class="button">
							<?php _e( 'Pay Now', 'paysentinel' ); ?>
						</a>
					</p>
					
					<p><?php _e( 'If you believe this charge should have gone through, please contact your bank or try a different card.', 'paysentinel' ); ?></p>
				</div>
				
				<div class="footer">
					<p><?php _e( 'This email was sent by our payment monitoring system', 'paysentinel' ); ?></p>
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
	 *
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
	 *
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
	 *
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
	 * Track retry success for monitoring
	 *
	 * @param string $gateway_id     Gateway ID
	 * @param int    $attempt_number Attempt number
	 */
	private function track_retry_success( $gateway_id, $attempt_number ) {
		// Store retry success statistics
		$stats = get_option( 'paysentinel_retry_stats', array() );

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

		update_option( 'paysentinel_retry_stats', $stats );
	}

	/**
	 * Track retry failure for monitoring
	 *
	 * @param string $gateway_id     Gateway ID
	 * @param int    $attempt_number Attempt number
	 */
	private function track_retry_failure( $gateway_id, $attempt_number ) {
		// Store retry failure statistics
		$stats = get_option( 'paysentinel_retry_stats', array() );

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

		update_option( 'paysentinel_retry_stats', $stats );
	}

	/**
	 * Get retry statistics
	 *
	 * @param string $gateway_id Optional gateway filter
	 *
	 * @return array Retry statistics
	 */
	public function get_retry_stats( $gateway_id = null ) {
		$stats = get_option( 'paysentinel_retry_stats', array() );

		if ( $gateway_id && isset( $stats[ $gateway_id ] ) ) {
			return $stats[ $gateway_id ];
		}

		return $gateway_id ? array() : $stats;
	}

	/**
	 * Get retry success rate
	 *
	 * @param string $gateway_id Gateway ID
	 *
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
	 *
	 * @return array Result
	 */
	public function manual_retry( $order_id ) {
		$transaction = $this->logger->get_transaction_by_order_id( $order_id );

		if ( ! $transaction ) {
			return array(
				'success' => false,
				'message' => __( 'Transaction not found', 'paysentinel' ),
			);
		}

		$settings    = get_option( 'paysentinel_options', array() );
		$max_retries = isset( $settings['max_retry_attempts'] ) ? intval( $settings['max_retry_attempts'] ) : self::MAX_RETRY_ATTEMPTS;

		if ( $transaction->retry_count >= $max_retries ) {
			return array(
				'success' => false,
				'message' => __( 'Maximum retry attempts reached', 'paysentinel' ),
			);
		}

		$order = wc_get_order( $order_id );
		if ( ! $order || $order->get_status() !== 'failed' ) {
			return array(
				'success' => false,
				'message' => __( 'Order is not in failed status', 'paysentinel' ),
			);
		}

		// Attempt immediate retry
		$result = $this->attempt_retry( $transaction->id );

		return array(
			'success' => $result,
			'message' => $result
				? __( 'Manual retry successful', 'paysentinel' )
				: __( 'Manual retry failed', 'paysentinel' ),
		);
	}

	/**
	 * Clear retry statistics
	 *
	 * @param string $gateway_id Optional gateway filter
	 *
	 * @return bool Success
	 */
	public function clear_retry_stats( $gateway_id = null ) {
		if ( $gateway_id ) {
			$stats = get_option( 'paysentinel_retry_stats', array() );
			unset( $stats[ $gateway_id ] );
			return update_option( 'paysentinel_retry_stats', $stats );
		} else {
			return delete_option( 'paysentinel_retry_stats' );
		}
	}
}
