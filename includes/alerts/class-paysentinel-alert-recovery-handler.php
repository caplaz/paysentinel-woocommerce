<?php
/**
 * Alert Recovery Handler Class
 *
 * Handles retry outcome alerts by listening to retry hooks and translating them
 * into alert records for the alert system.
 *
 * @package PaySentinel
 * @since 1.0.0
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * PaySentinel_Alert_Recovery_Handler class
 *
 * Listens to paysentinel_retry_successful and paysentinel_retry_failed hooks
 * and creates alerts through the alert checker system.
 */
class PaySentinel_Alert_Recovery_Handler {

	/**
	 * Alert checker instance
	 *
	 * @var PaySentinel_Alert_Checker
	 */
	private $checker;

	/**
	 * Logger instance
	 *
	 * @var PaySentinel_Logger
	 */
	private $logger;

	/**
	 * Database instance
	 *
	 * @var PaySentinel_Database
	 */
	private $database;

	/**
	 * Rate limiting window in seconds (5 minutes)
	 */
	private const RATE_LIMIT_WINDOW = 300;

	/**
	 * Constructor
	 *
	 * @param PaySentinel_Alert_Checker $checker   Initialized alert checker instance.
	 * @param PaySentinel_Logger        $logger    Initialized logger instance.
	 * @param PaySentinel_Database      $database  Initialized database instance.
	 */
	public function __construct( $checker, $logger = null, $database = null ) {
		$this->checker  = $checker;
		$this->logger   = $logger ?? new PaySentinel_Logger();
		$this->database = $database ?? new PaySentinel_Database();

		$this->register_hooks();
	}

	/**
	 * Register hooks for retry outcomes
	 */
	private function register_hooks() {
		add_action( 'paysentinel_retry_successful', array( $this, 'handle_recovery_success' ), 10, 3 );
		add_action( 'paysentinel_retry_failed', array( $this, 'handle_recovery_failure' ), 10, 4 );
	}

	/**
	 * Handle successful retry recovery
	 *
	 * @param WC_Order $order        Order object.
	 * @param object   $transaction  Transaction record.
	 * @param array    $retry_result Retry result array.
	 */
	public function handle_recovery_success( $order, $transaction, $retry_result ) {
		// Check rate limiting to prevent alert spam
		if ( $this->is_rate_limited( $transaction->gateway_id, 'retry_outcome' ) ) {
			return;
		}

		// Build alert data for successful recovery
		// retry_count is incremented by 1 because it's the successful attempt number
		$alert_data = $this->build_alert_data(
			'success',
			$order,
			$transaction,
			$retry_result,
			$transaction->retry_count + 1
		);

		// Trigger the alert through the checker
		$this->checker->trigger_alert( $alert_data );
	}

	/**
	 * Handle failed retry recovery
	 *
	 * @param WC_Order $order        Order object.
	 * @param object   $transaction  Transaction record.
	 * @param array    $retry_result Retry result array.
	 * @param int      $retry_count  Current retry count.
	 */
	public function handle_recovery_failure( $order, $transaction, $retry_result, $retry_count ) {
		// Check rate limiting to prevent alert spam
		if ( $this->is_rate_limited( $transaction->gateway_id, 'retry_outcome' ) ) {
			return;
		}

		// Build alert data for failed recovery
		$alert_data = $this->build_alert_data(
			'failed',
			$order,
			$transaction,
			$retry_result,
			$retry_count
		);

		// Trigger the alert through the checker
		$this->checker->trigger_alert( $alert_data );
	}

	/**
	 * Build alert data array from retry outcome information
	 *
	 * @param string   $status       Recovery status ('success' or 'failed').
	 * @param WC_Order $order        Order object.
	 * @param object   $transaction  Transaction record.
	 * @param array    $retry_result Retry result array.
	 * @param int      $retry_count  Retry attempt number.
	 *
	 * @return array Alert data array.
	 */
	private function build_alert_data( $status, $order, $transaction, $retry_result, $retry_count ) {
		$max_retries    = PaySentinel_Config::instance()->get_max_retry_attempts();
		$is_max_reached = $retry_count >= $max_retries;

		// Determine severity based on status
		if ( 'success' === $status ) {
			$severity = 'info';
			$message  = sprintf(
				__( 'Payment recovery successful on retry attempt %1$d for order #%2$d', 'paysentinel' ),
				$retry_count,
				$order->get_id()
			);
		} else {
			// Failed recovery
			$severity = $is_max_reached ? 'high' : 'warning';

			if ( $is_max_reached ) {
				$message = sprintf(
					__( 'Payment recovery exhausted all %1$d retry attempts for order #%2$d — manual intervention needed', 'paysentinel' ),
					$max_retries,
					$order->get_id()
				);
			} else {
				$message = sprintf(
					__( 'Payment recovery failed on attempt %1$d for order #%2$d. Retrying...', 'paysentinel' ),
					$retry_count,
					$order->get_id()
				);
			}
		}

		// Get original failure info
		$original_failure_reason = isset( $transaction->failure_reason ) ? $transaction->failure_reason : __( 'Unknown', 'paysentinel' );

		// Build metadata
		$metadata = array(
			'order_id'                => $order->get_id(),
			'customer_id'             => $order->get_customer_id(),
			'status'                  => $status,
			'recovery_time'           => current_time( 'mysql' ),
			'original_failure_reason' => $original_failure_reason,
			'retry_attempt'           => $retry_count,
			'total_retries'           => $max_retries,
			'gateway_id'              => $transaction->gateway_id,
			'transaction_id'          => isset( $retry_result['transaction_id'] ) ? $retry_result['transaction_id'] : null,
		);

		// Add failure-specific metadata
		if ( 'failed' === $status ) {
			$metadata['failure_message']     = isset( $retry_result['message'] ) ? $retry_result['message'] : __( 'Unknown error', 'paysentinel' );
			$metadata['max_retries_reached'] = $is_max_reached;
		}

		return array(
			'gateway_id' => $transaction->gateway_id,
			'alert_type' => 'retry_outcome',
			'severity'   => $severity,
			'message'    => $message,
			'metadata'   => $metadata,
		);
	}

	/**
	 * Check if alert is rate limited (prevent spam)
	 *
	 * @param string $gateway_id Gateway ID.
	 * @param string $alert_type Alert type.
	 *
	 * @return bool True if rate limited, false otherwise.
	 */
	private function is_rate_limited( $gateway_id, $alert_type ) {
		global $wpdb;

		$table_name       = $this->database->get_alerts_table();
		$time_limit_mysql = date_create( current_time( 'mysql' ) )->modify( '-' . self::RATE_LIMIT_WINDOW . ' seconds' )->format( 'Y-m-d H:i:s' );

		$recent_alert = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table_name} WHERE gateway_id = %s AND alert_type = %s AND created_at > %s LIMIT 1",
				$gateway_id,
				$alert_type,
				$time_limit_mysql
			)
		);

		return ! empty( $recent_alert );
	}
}
