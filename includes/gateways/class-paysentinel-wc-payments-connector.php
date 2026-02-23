<?php

/**
 * WooCommerce Payments Gateway Connector
 *
 * Handles connectivity checks with WooCommerce Payments API
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PaySentinel_WC_Payments_Connector
 *
 * WooCommerce Payments-specific connectivity checker
 */
class PaySentinel_WC_Payments_Connector extends PaySentinel_Gateway_Connector {

	public const WC_PAYMENTS_API = 'https://public-api.wordpress.com/wpcom/v2/sites';

	/**
	 * Constructor
	 */
	public function __construct() {
		parent::__construct( 'woocommerce_payments' );
	}

	/**
	 * Get gateway title
	 *
	 * @return string
	 */
	protected function get_gateway_title() {
		return 'WooCommerce Payments';
	}

	/**
	 * Retrieve WooCommerce Payments credentials/settings
	 *
	 * @return array
	 */
	protected function get_credentials() {
		$wc_payments_settings = get_option( 'woocommerce_payments_settings', array() );

		if ( ! is_array( $wc_payments_settings ) ) {
			return array();
		}

		// WooCommerce Payments uses account ID and server credentials
		$account_id = $wc_payments_settings['account_id'] ?? '';
		$enabled    = isset( $wc_payments_settings['enabled'] ) && 'yes' === $wc_payments_settings['enabled'];

		return array(
			'account_id' => $account_id,
			'enabled'    => $enabled,
		);
	}

	/**
	 * Validate WooCommerce Payments credentials
	 *
	 * @return array
	 */
	public function validate_credentials() {
		$credentials = $this->get_credentials();

		if ( empty( $credentials['account_id'] ) ) {
			return array(
				'valid' => false,
				'error' => 'WooCommerce Payments account ID not configured',
			);
		}

		if ( ! $credentials['enabled'] ) {
			return array(
				'valid' => false,
				'error' => 'WooCommerce Payments is not enabled',
			);
		}

		return array(
			'valid' => true,
			'error' => null,
		);
	}

	/**
	 * Test connection to WooCommerce Payments
	 *
	 * For WooCommerce Payments, we check account status via local settings
	 * and attempt a simple API call to verify connectivity.
	 *
	 * @return array
	 */
	public function test_connection() {
		// Validate credentials/settings first
		$validation = $this->validate_credentials();
		if ( ! $validation['valid'] ) {
			return $this->response_unconfigured();
		}

		$credentials = $this->get_credentials();
		$account_id  = $credentials['account_id'];

		// Check if WooCommerce Payments class is available
		if ( ! class_exists( 'WC_Payments_API_Client' ) ) {
			return $this->response_offline(
				'WooCommerce Payments plugin not found or inactive',
				null,
				null
			);
		}

		try {
			// Get the WC Payments API client
			$api_client = new WC_Payments_API_Client();

			// Make a simple request to check connectivity
			// Request the account details to verify API is working
			$response = $this->make_http_request(
				self::WC_PAYMENTS_API . '/' . get_current_blog_id() . '/wcpay/accounts/me',
				array(
					'method'  => 'GET',
					'headers' => array(
						'Content-Type' => 'application/json',
					),
				)
			);

			if ( ! $response['success'] ) {
				return $this->response_offline(
					'Failed to connect to WooCommerce Payments API: ' . $response['error'],
					null,
					$response['time_ms']
				);
			}

			$http_code = wp_remote_retrieve_response_code( $response['response'] );

			// WooCommerce Payments should return 200 OK for account info
			if ( 200 === $http_code ) {
				return $this->response_online(
					'Successfully connected to WooCommerce Payments',
					$http_code,
					$response['time_ms']
				);
			}

			// Check for auth/permission errors
			if ( in_array( $http_code, array( 401, 403 ), true ) ) {
				return $this->response_offline(
					'Authentication failed with WooCommerce Payments API',
					$http_code,
					$response['time_ms']
				);
			}

			// Unexpected response
			return $this->response_offline(
				'Unexpected response from WooCommerce Payments API (HTTP ' . $http_code . ')',
				$http_code,
				$response['time_ms']
			);

		} catch ( Exception $e ) {
			return $this->response_offline(
				'Exception while connecting to WooCommerce Payments: ' . $e->getMessage(),
				null,
				null
			);
		}
	}
}
