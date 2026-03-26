<?php
/**
 * PayPal Gateway Connector
 *
 * Handles connectivity checks with PayPal API
 *
 * @package PaySentinel
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PaySentinel_PayPal_Connector
 *
 * PayPal-specific connectivity checker
 */
class PaySentinel_PayPal_Connector extends PaySentinel_Gateway_Connector {

	public const PAYPAL_LIVE_API    = 'https://api.paypal.com';
	public const PAYPAL_SANDBOX_API = 'https://api.sandbox.paypal.com';

	/**
	 * Constructor
	 */
	public function __construct() {
		parent::__construct( 'paypal' );
	}

	/**
	 * Get gateway title
	 *
	 * @return string
	 */
	protected function get_gateway_title() {
		return 'PayPal';
	}

	/**
	 * Retrieve PayPal API credentials from WooCommerce settings
	 *
	 * @return array
	 */
	protected function get_credentials() {
		$paypal_settings = get_option( 'woocommerce_paypal_settings', array() );

		if ( ! is_array( $paypal_settings ) ) {
			return array();
		}

		// Determine if sandbox or live.
		$sandbox = isset( $paypal_settings['sandbox'] ) && 'yes' === $paypal_settings['sandbox'];

		// Get credentials based on mode.
		$client_id = $sandbox ? $paypal_settings['sandbox_client_id'] ?? '' : $paypal_settings['client_id'] ?? '';
		$secret    = $sandbox ? $paypal_settings['sandbox_secret'] ?? '' : $paypal_settings['secret'] ?? '';

		return array(
			'client_id' => $client_id,
			'secret'    => $secret,
			'sandbox'   => $sandbox,
		);
	}

	/**
	 * Validate PayPal credentials
	 *
	 * @return array
	 */
	public function validate_credentials() {
		$credentials = $this->get_credentials();

		if ( empty( $credentials['client_id'] ) || empty( $credentials['secret'] ) ) {
			return array(
				'valid' => false,
				'error' => 'PayPal Client ID or Secret not configured',
			);
		}

		return array(
			'valid' => true,
			'error' => null,
		);
	}

	/**
	 * Test connection to PayPal API via OAuth token endpoint
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
		$api_base    = $credentials['sandbox'] ? self::PAYPAL_SANDBOX_API : self::PAYPAL_LIVE_API;

		// Prepare Basic Auth header (RFC 7617 requires base64 encoding of credentials).
		$auth_header = base64_encode( $credentials['client_id'] . ':' . $credentials['secret'] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- required by PayPal OAuth Basic Auth spec.

		// Make request to PayPal OAuth token endpoint.
		$response = $this->make_http_request(
			$api_base . '/v1/oauth2/token',
			array(
				'method'  => 'POST',
				'headers' => array(
					'Authorization' => 'Basic ' . $auth_header,
					'Accept'        => 'application/json',
					'Content-Type'  => 'application/x-www-form-urlencoded',
				),
				'body'    => 'grant_type=client_credentials',
			)
		);

		if ( ! $response['success'] ) {
			return $this->response_offline(
				'Failed to connect to PayPal API: ' . $response['error'],
				null,
				$response['time_ms']
			);
		}

		$http_code = wp_remote_retrieve_response_code( $response['response'] );
		$body      = json_decode( wp_remote_retrieve_body( $response['response'] ), true );

		// PayPal returns 200 OK with access_token for successful authentication.
		if ( 200 === $http_code && isset( $body['access_token'] ) ) {
			return $this->response_online(
				'Successfully connected to PayPal',
				$http_code,
				$response['time_ms']
			);
		}

		// Check for error response.
		if ( isset( $body['error_description'] ) ) {
			return $this->response_offline(
				'PayPal API error: ' . $body['error_description'],
				$http_code,
				$response['time_ms']
			);
		}

		// Unexpected response format.
		return $this->response_offline(
			'Unexpected response from PayPal API (HTTP ' . $http_code . ')',
			$http_code,
			$response['time_ms']
		);
	}
}
