<?php
/**
 * Stripe Gateway Connector
 *
 * Handles connectivity checks with Stripe API
 *
 * @package PaySentinel
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PaySentinel_Stripe_Connector
 *
 * Stripe-specific connectivity checker
 */
class PaySentinel_Stripe_Connector extends PaySentinel_Gateway_Connector {

	public const STRIPE_API_URL = 'https://api.stripe.com/v1';

	/**
	 * Constructor
	 */
	public function __construct() {
		parent::__construct( 'stripe' );
	}

	/**
	 * Get gateway title
	 *
	 * @return string
	 */
	protected function get_gateway_title() {
		return 'Stripe';
	}

	/**
	 * Retrieve Stripe API credentials from WooCommerce settings
	 *
	 * @return array
	 */
	protected function get_credentials() {
		$stripe_settings = get_option( 'woocommerce_stripe_settings', array() );

		if ( ! is_array( $stripe_settings ) ) {
			return array();
		}

		// Determine if test mode or live mode.
		$testmode = isset( $stripe_settings['testmode'] ) && 'yes' === $stripe_settings['testmode'];

		// Get API key based on mode.
		$api_key = $testmode ? $stripe_settings['test_secret_key'] ?? '' : $stripe_settings['secret_key'] ?? '';

		return array(
			'api_key'  => $api_key,
			'testmode' => $testmode,
		);
	}

	/**
	 * Validate Stripe credentials
	 *
	 * @return array
	 */
	public function validate_credentials() {
		$credentials = $this->get_credentials();

		if ( empty( $credentials['api_key'] ) ) {
			return array(
				'valid' => false,
				'error' => 'Stripe API key not configured',
			);
		}

		if ( ! preg_match( '/^(sk_live_|sk_test_)/', $credentials['api_key'] ) ) {
			return array(
				'valid' => false,
				'error' => 'Invalid Stripe secret key format',
			);
		}

		return array(
			'valid' => true,
			'error' => null,
		);
	}

	/**
	 * Test connection to Stripe API via Balance endpoint
	 *
	 * @return array
	 */
	public function test_connection() {
		// Validate credentials first.
		$validation = $this->validate_credentials();
		if ( ! $validation['valid'] ) {
			return $this->response_unconfigured();
		}

		$credentials = $this->get_credentials();
		$api_key     = $credentials['api_key'];

		// Make request to Stripe Balance API.
		$response = $this->make_http_request(
			self::STRIPE_API_URL . '/balance',
			array(
				'method'  => 'GET',
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'application/x-www-form-urlencoded',
				),
			)
		);

		if ( ! $response['success'] ) {
			return $this->response_offline(
				'Failed to connect to Stripe API: ' . $response['error'],
				null,
				$response['time_ms']
			);
		}

		$http_code = wp_remote_retrieve_response_code( $response['response'] );
		$body      = json_decode( wp_remote_retrieve_body( $response['response'] ), true );

		// Stripe returns 200 OK for successful balance retrieve.
		if ( 200 === $http_code && isset( $body['object'] ) && 'balance' === $body['object'] ) {
			return $this->response_online(
				'Successfully connected to Stripe',
				$http_code,
				$response['time_ms']
			);
		}

		// Check for error response.
		if ( isset( $body['error'] ) ) {
			$error_message = $body['error']['message'] ?? 'Unknown Stripe API error';
			return $this->response_offline(
				'Stripe API error: ' . $error_message,
				$http_code,
				$response['time_ms']
			);
		}

		// Unexpected response format.
		return $this->response_offline(
			'Unexpected response from Stripe API (HTTP ' . $http_code . ')',
			$http_code,
			$response['time_ms']
		);
	}
}
