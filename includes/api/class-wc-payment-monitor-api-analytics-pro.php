<?php

/**
 * PRO tier analytics REST API endpoints
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WC_Payment_Monitor_API_Analytics_Pro extends WC_Payment_Monitor_API_Base {

	/**
	 * Analytics instance
	 */
	private $analytics;

	/**
	 * Constructor
	 */
	public function __construct() {
		parent::__construct();
		$this->analytics = new WC_Payment_Monitor_Analytics_Pro();
	}

	/**
	 * Register REST routes for PRO analytics endpoints
	 */
	public function register_routes() {
		// Get comparative analytics for a gateway
		register_rest_route(
			$this->namespace,
			'/analytics/comparative/(?P<gateway_id>[^/]+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_comparative_analytics' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'gateway_id' => array(
						'type'        => 'string',
						'required'    => true,
						'description' => 'Payment gateway ID',
					),
				),
			)
		);

		// Get failure pattern analysis
		register_rest_route(
			$this->namespace,
			'/analytics/failure-patterns/(?P<gateway_id>[^/]+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_failure_patterns' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'gateway_id' => array(
						'type'        => 'string',
						'required'    => true,
						'description' => 'Payment gateway ID',
					),
					'days'       => array(
						'type'        => 'integer',
						'description' => 'Number of days to analyze',
						'default'     => 30,
						'minimum'     => 1,
						'maximum'     => 90,
					),
				),
			)
		);

		// Get advanced metrics summary
		register_rest_route(
			$this->namespace,
			'/analytics/metrics-summary',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_metrics_summary' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		// Get extended history
		register_rest_route(
			$this->namespace,
			'/analytics/extended-history/(?P<gateway_id>[^/]+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_extended_history' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'gateway_id' => array(
						'type'        => 'string',
						'required'    => true,
						'description' => 'Payment gateway ID',
					),
					'days'       => array(
						'type'        => 'integer',
						'description' => 'Number of days to retrieve',
						'default'     => 90,
						'minimum'     => 1,
						'maximum'     => 90,
					),
				),
			)
		);

		// Get gateway comparison
		register_rest_route(
			$this->namespace,
			'/analytics/gateway-comparison',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_gateway_comparison' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);
	}

	/**
	 * Get comparative analytics for a gateway
	 *
	 * @param WP_REST_Request $request Request object
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_comparative_analytics( $request ) {
		$gateway_id = $this->get_string_param( $request, 'gateway_id' );

		if ( empty( $gateway_id ) ) {
			return $this->get_error_response(
				'missing_gateway_id',
				__( 'Gateway ID is required', 'wc-payment-monitor' ),
				400
			);
		}

		$result = $this->analytics->get_comparative_analytics( $gateway_id );

		if ( isset( $result['error'] ) ) {
			return $this->get_error_response(
				$result['error'],
				$result['message'],
				403
			);
		}

		return new WP_REST_Response(
			array(
				'success' => true,
				'data'    => $result,
			),
			200
		);
	}

	/**
	 * Get failure pattern analysis
	 *
	 * @param WP_REST_Request $request Request object
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_failure_patterns( $request ) {
		$gateway_id = $this->get_string_param( $request, 'gateway_id' );
		$days       = $this->get_int_param( $request, 'days', 30 );

		if ( empty( $gateway_id ) ) {
			return $this->get_error_response(
				'missing_gateway_id',
				__( 'Gateway ID is required', 'wc-payment-monitor' ),
				400
			);
		}

		$result = $this->analytics->get_failure_pattern_analysis( $gateway_id, $days );

		if ( isset( $result['error'] ) ) {
			return $this->get_error_response(
				$result['error'],
				$result['message'],
				403
			);
		}

		return new WP_REST_Response(
			array(
				'success' => true,
				'data'    => $result,
			),
			200
		);
	}

	/**
	 * Get advanced metrics summary
	 *
	 * @param WP_REST_Request $request Request object
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_metrics_summary( $request ) {
		$result = $this->analytics->get_advanced_metrics_summary();

		if ( isset( $result['error'] ) ) {
			return $this->get_error_response(
				$result['error'],
				$result['message'],
				403
			);
		}

		return new WP_REST_Response(
			array(
				'success' => true,
				'data'    => $result,
			),
			200
		);
	}

	/**
	 * Get extended history
	 *
	 * @param WP_REST_Request $request Request object
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_extended_history( $request ) {
		$gateway_id = $this->get_string_param( $request, 'gateway_id' );
		$days       = $this->get_int_param( $request, 'days', 90 );

		if ( empty( $gateway_id ) ) {
			return $this->get_error_response(
				'missing_gateway_id',
				__( 'Gateway ID is required', 'wc-payment-monitor' ),
				400
			);
		}

		$result = $this->analytics->get_extended_history( $gateway_id, $days );

		if ( isset( $result['error'] ) ) {
			return $this->get_error_response(
				$result['error'],
				$result['message'],
				403
			);
		}

		return new WP_REST_Response(
			array(
				'success' => true,
				'data'    => $result,
			),
			200
		);
	}

	/**
	 * Get gateway comparison
	 *
	 * @param WP_REST_Request $request Request object
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_gateway_comparison( $request ) {
		$result = $this->analytics->get_gateway_comparison();

		if ( isset( $result['error'] ) ) {
			return $this->get_error_response(
				$result['error'],
				$result['message'],
				403
			);
		}

		return new WP_REST_Response(
			array(
				'success' => true,
				'data'    => $result,
			),
			200
		);
	}
}
