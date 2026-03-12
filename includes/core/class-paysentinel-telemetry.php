<?php

/**
 * Telemetry handling class
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PaySentinel_Telemetry {

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->init_hooks();
	}

	/**
	 * Initialize hooks
	 */
	private function init_hooks() {
		add_action( 'paysentinel_payment_success', array( $this, 'send_success_telemetry' ), 10, 2 );
		add_action( 'paysentinel_payment_failed', array( $this, 'send_failure_telemetry' ), 10, 2 );
	}

	/**
	 * Send success telemetry
	 *
	 * @param int           $order_id Order ID
	 * @param WC_Order|null $order    Order object
	 */
	public function send_success_telemetry( $order_id, $order = null ) {
		$this->send_telemetry( $order_id, $order, true );
	}

	/**
	 * Send failure telemetry
	 *
	 * @param int           $order_id Order ID
	 * @param WC_Order|null $order    Order object
	 */
	public function send_failure_telemetry( $order_id, $order = null ) {
		$this->send_telemetry( $order_id, $order, false );
	}

	/**
	 * Send telemetry payload
	 *
	 * @param int           $order_id Order ID
	 * @param WC_Order|null $order    Order object
	 * @param bool          $success  Success status
	 */
	private function send_telemetry( $order_id, $order, $success ) {
		// If order object wasn't passed (legacy), fetch it
		if ( ! $order || ! is_a( $order, 'WC_Order' ) ) {
			$order = wc_get_order( $order_id );
		}

		if ( ! $order ) {
			return;
		}

		$license = new PaySentinel_License();
		if ( 'valid' !== $license->get_license_status() ) {
			return;
		}

		$logger = new PaySentinel_Logger();
		// Get gateway from order
		$gateway = $order->get_payment_method();

		$payload = array(
			'license_key'    => $license->get_license_key(),
			'success'        => $success,
			'amount'         => (float) $order->get_total(),
			'currency'       => $order->get_currency(),
			'gateway'        => $gateway,
			'transaction_id' => $order->get_transaction_id(),
		);

		if ( ! $success ) {
			// Extract error code using the logger method
			if ( is_callable( array( $logger, 'extract_failure_info' ) ) ) {
				$failure_info          = $logger->extract_failure_info( $order );
				$payload['error_code'] = ! empty( $failure_info['code'] ) ? $failure_info['code'] : 'failed';
			} else {
				$payload['error_code'] = 'failed';
			}
		}

		// Send non-blocking authenticated request
		$license->make_authenticated_request(
			PaySentinel_License::API_ENDPOINT_TELEMETRY,
			'POST',
			$payload,
			true,  // include site url
			false  // non-blocking
		);
	}
}
