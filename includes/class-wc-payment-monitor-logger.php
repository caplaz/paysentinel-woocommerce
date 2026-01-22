<?php
/**
 * Transaction logging class
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WC_Payment_Monitor_Logger {

	/**
	 * Database instance
	 */
	private $database;

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->database = new WC_Payment_Monitor_Database();
		$this->init_hooks();
	}

	/**
	 * Initialize WooCommerce hooks
	 */
	private function init_hooks() {
		// Hook into WooCommerce payment events
		add_action( 'woocommerce_payment_complete', array( $this, 'log_success' ), 10, 1 );
		add_action( 'woocommerce_order_status_failed', array( $this, 'log_failure' ), 10, 2 );
		add_action( 'woocommerce_order_status_pending', array( $this, 'log_pending' ), 10, 2 );

		// Hook into payment gateway responses for more detailed logging
		add_action( 'woocommerce_payment_complete_order_status_completed', array( $this, 'log_payment_completion' ), 10, 2 );
		add_action( 'woocommerce_payment_complete_order_status_processing', array( $this, 'log_payment_completion' ), 10, 2 );
	}

	/**
	 * Log successful payment
	 *
	 * @param int $order_id Order ID
	 */
	public function log_success( $order_id ) {
		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			return;
		}

		$transaction_data = $this->extract_transaction_data( $order, 'success' );
		$this->save_transaction( $transaction_data );
	}

	/**
	 * Log failed payment
	 *
	 * @param int    $order_id Order ID
	 * @param string $old_status Previous order status
	 */
	public function log_failure( $order_id, $old_status = '' ) {
		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			return;
		}

		$transaction_data = $this->extract_transaction_data( $order, 'failed' );

		// Extract failure reason from order notes
		$failure_info                       = $this->extract_failure_info( $order );
		$transaction_data['failure_reason'] = $failure_info['reason'];
		$transaction_data['failure_code']   = $failure_info['code'];

		$this->save_transaction( $transaction_data );
	}

	/**
	 * Log pending payment
	 *
	 * @param int    $order_id Order ID
	 * @param string $old_status Previous order status
	 */
	public function log_pending( $order_id, $old_status = '' ) {
		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			return;
		}

		$transaction_data = $this->extract_transaction_data( $order, 'pending' );
		$this->save_transaction( $transaction_data );
	}

	/**
	 * Log payment completion with additional details
	 *
	 * @param int    $order_id Order ID
	 * @param object $order Order object
	 */
	public function log_payment_completion( $order_id, $order ) {
		if ( ! $order ) {
			return;
		}

		// Update existing transaction record if it exists
		$this->update_transaction_status( $order_id, 'success' );
	}

	/**
	 * Extract transaction data from WooCommerce order
	 *
	 * @param object $order Order object
	 * @param string $status Transaction status
	 * @return array Transaction data
	 */
	private function extract_transaction_data( $order, $status ) {
		$transaction_data = array(
			'order_id'       => $order->get_id(),
			'gateway_id'     => $order->get_payment_method(),
			'transaction_id' => $order->get_transaction_id(),
			'amount'         => floatval( $order->get_total() ),
			'currency'       => $order->get_currency(),
			'status'         => $status,
			'failure_reason' => null,
			'failure_code'   => null,
			'retry_count'    => 0,
			'customer_email' => $order->get_billing_email(),
			'customer_ip'    => $order->get_customer_ip_address(),
			'created_at'     => current_time( 'mysql' ),
			'updated_at'     => null,
		);

		return $transaction_data;
	}

	/**
	 * Extract failure information from order
	 *
	 * @param object $order Order object
	 * @return array Failure information
	 */
	private function extract_failure_info( $order ) {
		$failure_info = array(
			'reason' => null,
			'code'   => null,
		);

		// Get order notes to extract failure information
		$notes = get_comments(
			array(
				'post_id' => $order->get_id(),
				'number'  => 5,
				'orderby' => 'comment_date',
				'order'   => 'DESC',
				'approve' => 'approve',
				'type'    => 'order_note',
			)
		);

		foreach ( $notes as $note ) {
			$note_content = strtolower( $note->content );

			// Look for common failure patterns
			if ( strpos( $note_content, 'payment failed' ) !== false ||
				strpos( $note_content, 'transaction failed' ) !== false ||
				strpos( $note_content, 'declined' ) !== false ||
				strpos( $note_content, 'error' ) !== false ) {

				$failure_info['reason'] = $note->content;

				// Try to extract error codes
				if ( preg_match( '/code[:\s]+([a-zA-Z0-9_-]+)/i', $note->content, $matches ) ) {
					$failure_info['code'] = $matches[1];
				} elseif ( preg_match( '/error[:\s]+([a-zA-Z0-9_-]+)/i', $note->content, $matches ) ) {
					$failure_info['code'] = $matches[1];
				}

				break;
			}
		}

		// If no specific failure reason found, use generic message
		if ( empty( $failure_info['reason'] ) ) {
			$failure_info['reason'] = 'Payment failed - no specific reason provided';
		}

		return $failure_info;
	}

	/**
	 * Save transaction data to database
	 *
	 * @param array $transaction_data Transaction data
	 * @return int|false Transaction ID or false on failure
	 */
	public function save_transaction( $transaction_data ) {
		global $wpdb;

		$table_name = $this->database->get_transactions_table();

		// Check if transaction already exists for this order
		$existing_transaction = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id FROM {$table_name} WHERE order_id = %d",
				$transaction_data['order_id']
			)
		);

		if ( $existing_transaction ) {
			// Update existing transaction
			$transaction_data['updated_at'] = current_time( 'mysql' );
			unset( $transaction_data['created_at'] ); // Don't update created_at

			$result = $wpdb->update(
				$table_name,
				$transaction_data,
				array( 'order_id' => $transaction_data['order_id'] ),
				array(
					'%d', // order_id
					'%s', // gateway_id
					'%s', // transaction_id
					'%f', // amount
					'%s', // currency
					'%s', // status
					'%s', // failure_reason
					'%s', // failure_code
					'%d', // retry_count
					'%s', // customer_email
					'%s', // customer_ip
					'%s',  // updated_at
				),
				array( '%d' )
			);

			return $result !== false ? $existing_transaction->id : false;
		} else {
			// Insert new transaction
			$result = $wpdb->insert(
				$table_name,
				$transaction_data,
				array(
					'%d', // order_id
					'%s', // gateway_id
					'%s', // transaction_id
					'%f', // amount
					'%s', // currency
					'%s', // status
					'%s', // failure_reason
					'%s', // failure_code
					'%d', // retry_count
					'%s', // customer_email
					'%s', // customer_ip
					'%s', // created_at
					'%s',  // updated_at
				)
			);

			return $result !== false ? $wpdb->insert_id : false;
		}
	}

	/**
	 * Update transaction status
	 *
	 * @param int    $order_id Order ID
	 * @param string $status New status
	 * @return bool Success
	 */
	public function update_transaction_status( $order_id, $status ) {
		global $wpdb;

		$table_name = $this->database->get_transactions_table();

		$result = $wpdb->update(
			$table_name,
			array(
				'status'     => $status,
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'order_id' => $order_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		return $result !== false;
	}

	/**
	 * Get transaction by order ID
	 *
	 * @param int $order_id Order ID
	 * @return object|null Transaction data
	 */
	public function get_transaction_by_order_id( $order_id ) {
		global $wpdb;

		$table_name = $this->database->get_transactions_table();

		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table_name} WHERE order_id = %d",
				$order_id
			)
		);
	}

	/**
	 * Get transactions by gateway
	 *
	 * @param string $gateway_id Gateway ID
	 * @param int    $limit Limit results
	 * @param int    $offset Offset results
	 * @return array Transaction data
	 */
	public function get_transactions_by_gateway( $gateway_id, $limit = 100, $offset = 0 ) {
		global $wpdb;

		$table_name = $this->database->get_transactions_table();

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table_name} WHERE gateway_id = %s ORDER BY created_at DESC LIMIT %d OFFSET %d",
				$gateway_id,
				$limit,
				$offset
			)
		);
	}

	/**
	 * Get transactions by status
	 *
	 * @param string $status Transaction status
	 * @param int    $limit Limit results
	 * @param int    $offset Offset results
	 * @return array Transaction data
	 */
	public function get_transactions_by_status( $status, $limit = 100, $offset = 0 ) {
		global $wpdb;

		$table_name = $this->database->get_transactions_table();

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table_name} WHERE status = %s ORDER BY created_at DESC LIMIT %d OFFSET %d",
				$status,
				$limit,
				$offset
			)
		);
	}

	/**
	 * Get transactions within date range
	 *
	 * @param string $start_date Start date (Y-m-d H:i:s format)
	 * @param string $end_date End date (Y-m-d H:i:s format)
	 * @param string $gateway_id Optional gateway filter
	 * @return array Transaction data
	 */
	public function get_transactions_by_date_range( $start_date, $end_date, $gateway_id = null ) {
		global $wpdb;

		$table_name = $this->database->get_transactions_table();

		$sql    = "SELECT * FROM {$table_name} WHERE created_at BETWEEN %s AND %s";
		$params = array( $start_date, $end_date );

		if ( $gateway_id ) {
			$sql     .= ' AND gateway_id = %s';
			$params[] = $gateway_id;
		}

		$sql .= ' ORDER BY created_at DESC';

		return $wpdb->get_results( $wpdb->prepare( $sql, $params ) );
	}

	/**
	 * Get transaction statistics for a gateway and period
	 *
	 * @param string $gateway_id Gateway ID
	 * @param int    $period_seconds Period in seconds
	 * @return array Statistics
	 */
	public function get_transaction_stats( $gateway_id, $period_seconds ) {
		global $wpdb;

		$table_name = $this->database->get_transactions_table();
		$start_time = date( 'Y-m-d H:i:s', time() - $period_seconds );

		$stats = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT 
                COUNT(*) as total_transactions,
                SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) as successful_transactions,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed_transactions,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_transactions,
                SUM(CASE WHEN status = 'retry' THEN 1 ELSE 0 END) as retry_transactions,
                SUM(amount) as total_amount,
                AVG(amount) as avg_amount
            FROM {$table_name} 
            WHERE gateway_id = %s AND created_at >= %s AND status != 'pending'",
				$gateway_id,
				$start_time
			),
			ARRAY_A
		);

		// Calculate success rate (excluding pending transactions)
		if ( $stats['total_transactions'] > 0 ) {
			$stats['success_rate'] = round( ( $stats['successful_transactions'] / $stats['total_transactions'] ) * 100, 2 );
		} else {
			$stats['success_rate'] = 0.00;
		}

		return $stats;
	}
}
