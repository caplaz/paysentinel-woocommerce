<?php

/**
 * Base REST API endpoint class
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

abstract class WC_Payment_Monitor_API_Base {

	/**
	 * REST namespace
	 */
	protected $namespace = 'wc-payment-monitor/v1';

	/**
	 * Database instance
	 */
	protected $database;

	/**
	 * Whether routes have been registered
	 *
	 * @var bool
	 */
	protected $routes_registered = false;

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->database = new WC_Payment_Monitor_Database();
		$this->init_hooks();
	}

	/**
	 * Initialize hooks
	 */
	protected function init_hooks() {
		if ( did_action( 'rest_api_init' ) || doing_action( 'rest_api_init' ) ) {
			$this->register_routes_once();
		} else {
			add_action( 'rest_api_init', array( $this, 'register_routes_once' ) );
		}
	}

	/**
	 * Wrapper to ensure routes are only registered once
	 */
	public function register_routes_once() {
		if ( $this->routes_registered ) {
			return;
		}
		$this->register_routes();
		$this->routes_registered = true;
	}

	/**
	 * Register API routes - must be implemented by subclasses
	 */
	abstract public function register_routes();

	/**
	 * Check if user has permission to access API
	 *
	 * @param WP_REST_Request $request Request object
	 *
	 * @return bool|WP_Error
	 */
	public function check_permission( $request ) {
		// Allow access if user is logged in with manage_woocommerce capability
		// REST API automatically validates the request and sets up user context
		return current_user_can( 'manage_woocommerce' );
	}

	/**
	 * Get error response with consistent format
	 *
	 * @param string $error_code Error code
	 * @param string $message    Error message
	 * @param int    $status     HTTP status code
	 *
	 * @return WP_REST_Response
	 */
	protected function get_error_response( $error_code, $message, $status = 400 ) {
		return new WP_REST_Response(
			array(
				'success' => false,
				'code'    => $error_code,
				'message' => $message,
			),
			$status
		);
	}

	/**
	 * Get success response with consistent format (direct/unwrapped)
	 *
	 * @param mixed $data   Response data
	 * @param int   $status HTTP status code
	 *
	 * @return WP_REST_Response
	 */
	protected function get_success_response( $data, $status = 200 ) {
		return new WP_REST_Response( $data, $status );
	}

	/**
	 * Get paginated response (direct/unwrapped format)
	 *
	 * @param array $items    Array of items
	 * @param int   $total    Total count
	 * @param int   $page     Current page
	 * @param int   $per_page Items per page
	 * @param int   $status   HTTP status code
	 *
	 * @return WP_REST_Response
	 */
	protected function get_paginated_response( $items, $total, $page, $per_page, $status = 200 ) {
		return new WP_REST_Response(
			array(
				'items'      => $items,
				'pagination' => array(
					'page'        => intval( $page ),
					'per_page'    => intval( $per_page ),
					'total'       => intval( $total ),
					'total_pages' => intval( ceil( $total / $per_page ) ),
				),
			),
			$status
		);
	}

	/**
	 * Validate pagination parameters
	 *
	 * @param WP_REST_Request $request Request object
	 *
	 * @return array Validated pagination parameters
	 */
	protected function validate_pagination( $request ) {
		$page     = $request->get_param( 'page' );
		$per_page = $request->get_param( 'per_page' );

		// Set defaults
		$page     = $page ? intval( $page ) : 1;
		$per_page = $per_page ? intval( $per_page ) : 20;

		// Validate ranges
		$page     = $page > 0 ? $page : 1;
		$per_page = ( $per_page > 0 && $per_page <= 100 ) ? $per_page : 20;

		return array(
			'page'     => $page,
			'per_page' => $per_page,
		);
	}

	/**
	 * Calculate offset for pagination
	 *
	 * @param int $page     Current page number
	 * @param int $per_page Items per page
	 *
	 * @return int Offset for LIMIT clause
	 */
	protected function calculate_offset( $page, $per_page ) {
		return ( $page - 1 ) * $per_page;
	}

	/**
	 * Sanitize and validate date parameters
	 *
	 * @param string $date_string Date string to validate (Y-m-d format)
	 *
	 * @return string|WP_REST_Response Validated date or error
	 */
	protected function validate_date( $date_string ) {
		if ( empty( $date_string ) ) {
			return '';
		}

		// Check format
		$date = DateTime::createFromFormat( 'Y-m-d', $date_string );
		if ( ! $date || $date->format( 'Y-m-d' ) !== $date_string ) {
			return $this->get_error_response(
				'invalid_date_format',
				__( 'Date must be in Y-m-d format', 'wc-payment-monitor' ),
				400
			);
		}

		return $date_string;
	}

	/**
	 * Get sanitized string parameter
	 *
	 * @param WP_REST_Request $request Request object
	 * @param string          $param   Parameter name
	 * @param string          $default Default value
	 *
	 * @return string
	 */
	protected function get_string_param( $request, $param, $default = '' ) {
		$value = $request->get_param( $param );
		return $value ? sanitize_text_field( $value ) : $default;
	}

	/**
	 * Get sanitized integer parameter
	 *
	 * @param WP_REST_Request $request Request object
	 * @param string          $param   Parameter name
	 * @param int             $default Default value
	 *
	 * @return int
	 */
	protected function get_int_param( $request, $param, $default = 0 ) {
		$value = $request->get_param( $param );
		return $value ? intval( $value ) : $default;
	}
}
