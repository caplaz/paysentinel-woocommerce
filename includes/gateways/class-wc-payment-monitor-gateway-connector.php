<?php

/**
 * Abstract Gateway Connector Base Class
 *
 * Defines the interface for payment gateway connectivity checks.
 * Subclasses implement specific gateway APIs (Stripe, PayPal, WooCommerce Payments).
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WC_Payment_Monitor_Gateway_Connector
 *
 * Abstract base class for gateway-specific connectivity checks.
 */
abstract class WC_Payment_Monitor_Gateway_Connector {

	/**
	 * Gateway ID (e.g., 'stripe', 'paypal', 'woocommerce_payments')
	 *
	 * @var string
	 */
	protected $gateway_id;

	/**
	 * Gateway title for display
	 *
	 * @var string
	 */
	protected $gateway_title;

	/**
	 * Constructor
	 *
	 * @param string $gateway_id The gateway identifier.
	 */
	public function __construct( $gateway_id ) {
		$this->gateway_id    = $gateway_id;
		$this->gateway_title = $this->get_gateway_title();
	}

	/**
	 * Get gateway ID
	 *
	 * @return string
	 */
	public function get_gateway_id() {
		return $this->gateway_id;
	}

	/**
	 * Get gateway title
	 *
	 * @return string
	 */
	abstract protected function get_gateway_title();

	/**
	 * Retrieve gateway credentials from WooCommerce settings
	 *
	 * @return array Associative array of credentials or empty array if not configured.
	 */
	abstract protected function get_credentials();

	/**
	 * Validate that required credentials are configured
	 *
	 * @return array { 'valid' => bool, 'error' => string|null }
	 */
	abstract public function validate_credentials();

	/**
	 * Test connection to gateway API
	 *
	 * @return array {
	 *               'success'         => bool,
	 *               'status'          => string ('online'|'offline'|'unconfigured'),
	 *               'message'         => string,
	 *               'last_checked_at' => string (MySQL datetime),
	 *               'http_code'       => int|null,
	 *               'response_time'   => float|null (milliseconds)
	 *               }
	 */
	abstract public function test_connection();

	/**
	 * Get current gateway status from API
	 *
	 * Wrapper around test_connection() for health checks
	 *
	 * @return array Gateway status response
	 */
	public function get_status() {
		return $this->test_connection();
	}

	/**
	 * Log connectivity check to database
	 *
	 * @param array $status Status result from test_connection().
	 *
	 * @return int|false Database insert ID or false on failure.
	 */
	public function log_connectivity_check( $status ) {
		global $wpdb;

		$table = $wpdb->prefix . 'payment_monitor_gateway_connectivity';

		return $wpdb->insert(
			$table,
			array(
				'gateway_id'       => $this->gateway_id,
				'status'           => $status['status'],
				'message'          => $status['message'],
				'http_code'        => $status['http_code'],
				'response_time_ms' => $status['response_time'],
				'checked_at'       => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%d', '%f', '%s' )
		);
	}

	/**
	 * Get last connectivity check for this gateway
	 *
	 * @return object|null Last check record or null if none exists.
	 */
	public function get_last_connectivity_check() {
		global $wpdb;

		$table = $wpdb->prefix . 'payment_monitor_gateway_connectivity';

		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM $table WHERE gateway_id = %s ORDER BY checked_at DESC LIMIT 1",
				$this->gateway_id
			)
		);
	}

	/**
	 * Get connectivity check history
	 *
	 * @param int $limit Number of recent checks to retrieve.
	 *
	 * @return array Array of connectivity check records.
	 */
	public function get_connectivity_history( $limit = 10 ) {
		global $wpdb;

		$table = $wpdb->prefix . 'payment_monitor_gateway_connectivity';

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM $table WHERE gateway_id = %s ORDER BY checked_at DESC LIMIT %d",
				$this->gateway_id,
				$limit
			)
		);
	}

	/**
	 * Make HTTP request with timeout handling
	 *
	 * @param string $url  Request URL.
	 * @param array  $args wp_remote_request arguments.
	 *
	 * @return array { 'success' => bool, 'response' => array, 'time_ms' => float }
	 */
	protected function make_http_request( $url, $args = array() ) {
		$start_time = microtime( true );

		$defaults = array(
			'timeout'    => 10,
			'sslverify'  => true,
			'user-agent' => 'WC-Payment-Monitor/1.0',
			'headers'    => array(),
		);

		$args = wp_parse_args( $args, $defaults );

		$response = wp_remote_request( $url, $args );

		$elapsed_ms = ( microtime( true ) - $start_time ) * 1000;

		if ( is_wp_error( $response ) ) {
			return array(
				'success'  => false,
				'response' => array(),
				'time_ms'  => $elapsed_ms,
				'error'    => $response->get_error_message(),
			);
		}

		return array(
			'success'  => true,
			'response' => $response,
			'time_ms'  => $elapsed_ms,
			'error'    => null,
		);
	}

	/**
	 * Helper: Build standard "unconfigured" response
	 *
	 * @return array Status response indicating gateway is not configured.
	 */
	protected function response_unconfigured() {
		return array(
			'success'         => false,
			'status'          => 'unconfigured',
			'message'         => sprintf(
				'%s credentials not configured in WooCommerce settings',
				$this->gateway_title
			),
			'last_checked_at' => current_time( 'mysql' ),
			'http_code'       => null,
			'response_time'   => null,
		);
	}

	/**
	 * Helper: Build standard "offline" response
	 *
	 * @param string $message       Error message.
	 * @param int    $http_code     Optional HTTP status code.
	 * @param float  $response_time Optional response time in milliseconds.
	 *
	 * @return array Status response indicating gateway is offline.
	 */
	protected function response_offline( $message, $http_code = null, $response_time = null ) {
		return array(
			'success'         => false,
			'status'          => 'offline',
			'message'         => $message,
			'last_checked_at' => current_time( 'mysql' ),
			'http_code'       => $http_code,
			'response_time'   => $response_time,
		);
	}

	/**
	 * Helper: Build standard "online" response
	 *
	 * @param string $message       Success message.
	 * @param int    $http_code     Optional HTTP status code.
	 * @param float  $response_time Optional response time in milliseconds.
	 *
	 * @return array Status response indicating gateway is online.
	 */
	protected function response_online( $message = 'Connected', $http_code = 200, $response_time = null ) {
		return array(
			'success'         => true,
			'status'          => 'online',
			'message'         => $message,
			'last_checked_at' => current_time( 'mysql' ),
			'http_code'       => $http_code,
			'response_time'   => $response_time,
		);
	}
}
