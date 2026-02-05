<?php

/**
 * Square Gateway Connector
 *
 * Handles connectivity checks with Square API
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WC_Payment_Monitor_Square_Connector
 *
 * Square-specific connectivity checker
 */
class WC_Payment_Monitor_Square_Connector extends WC_Payment_Monitor_Gateway_Connector {

	public const SQUARE_PRODUCTION_API = 'https://connect.squareup.com/v2';
	public const SQUARE_SANDBOX_API    = 'https://connect.squareupsandbox.com/v2';

	/**
	 * Constructor
	 */
	public function __construct() {
		parent::__construct( 'square_credit_card' );
	}

	/**
	 * Get gateway title
	 *
	 * @return string
	 */
	protected function get_gateway_title() {
		return 'Square';
	}

	/**
	 * Retrieve Square API credentials from WooCommerce settings
	 *
	 * @return array
	 */
	protected function get_credentials() {
		$square_settings = get_option( 'woocommerce_square_credit_card_settings', array() );

		if ( ! is_array( $square_settings ) ) {
			return array();
		}

		// Determine if sandbox or live. Square plugin often stores this in general settings or integration settings.
		// NOTE: The official plugin structure can vary. We'll check standard locations.
		// Often Square tokens are stored in 'woocommerce_square_credit_card_settings' or separate options.
		// For MVP, we'll try to find the access token in likely locations.

		$sandbox      = isset( $square_settings['sandbox'] ) && 'yes' === $square_settings['sandbox'];
		$access_token = $sandbox ? ( $square_settings['sandbox_token'] ?? '' ) : ( $square_settings['token'] ?? '' );

		return array(
			'access_token' => $access_token,
			'sandbox'      => $sandbox,
		);
	}

	/**
	 * Validate Square credentials
	 *
	 * @return array
	 */
	public function validate_credentials() {
		$credentials = $this->get_credentials();

		if ( empty( $credentials['access_token'] ) ) {
			return array(
				'valid' => false,
				'error' => 'Square Access Token not configured',
			);
		}

		return array(
			'valid' => true,
			'error' => null,
		);
	}

	/**
	 * Test connection to Square API via Locations endpoint
	 *
	 * @return array
	 */
	public function test_connection() {
		// Validate credentials first
		$validation = $this->validate_credentials();
		if ( ! $validation['valid'] ) {
			return $this->response_unconfigured();
		}

		$credentials = $this->get_credentials();
		$api_base    = $credentials['sandbox'] ? self::SQUARE_SANDBOX_API : self::SQUARE_PRODUCTION_API;

		// Make request to Square Locations API (simplest read-only endpoint)
		$response = $this->make_http_request(
			$api_base . '/locations',
			array(
				'method'  => 'GET',
				'headers' => array(
					'Authorization'  => 'Bearer ' . $credentials['access_token'],
					'Content-Type'   => 'application/json',
					'Square-Version' => '2023-10-20', // Use a recent API version
				),
			)
		);

		if ( ! $response['success'] ) {
			return $this->response_offline(
				'Failed to connect to Square API: ' . $response['error'],
				null,
				$response['time_ms']
			);
		}

		$http_code = wp_remote_retrieve_response_code( $response['response'] );
		$body      = json_decode( wp_remote_retrieve_body( $response['response'] ), true );

		// Square returns 200 OK for successful locations retrieval
		if ( 200 === $http_code && isset( $body['locations'] ) ) {
			return $this->response_online(
				'Successfully connected to Square',
				$http_code,
				$response['time_ms']
			);
		}

		// Check for error response
		if ( isset( $body['errors'] ) ) {
			$error_msg = $body['errors'][0]['detail'] ?? 'Unknown Square API error';
			return $this->response_offline(
				'Square API error: ' . $error_msg,
				$http_code,
				$response['time_ms']
			);
		}

		// Unexpected response format
		return $this->response_offline(
			'Unexpected response from Square API (HTTP ' . $http_code . ')',
			$http_code,
			$response['time_ms']
		);
	}
}
