<?php

/**
 * Gateway health REST API endpoints
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WC_Payment_Monitor_API_Health extends WC_Payment_Monitor_API_Base {

	/**
	 * Register REST routes for health endpoints
	 */
	public function register_routes() {
		// Get all gateway health data
		register_rest_route(
			$this->namespace,
			'/health/gateways',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_all_gateway_health' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'period' => array(
						'type'        => 'string',
						'description' => 'Health calculation period (24h, 7d, 30d)',
						'enum'        => array( '24h', '7d', '30d' ),
						'default'     => '24h',
					),
					'scope'  => array(
						'type'        => 'string',
						'description' => 'Which gateways to include (enabled or all)',
						'enum'        => array( 'enabled', 'all' ),
						'default'     => 'enabled',
					),
				),
			)
		);

		// Get specific gateway health data
		register_rest_route(
			$this->namespace,
			'/health/gateways/(?P<gateway_id>[^/]+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_gateway_health' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'period'     => array(
						'type'        => 'string',
						'description' => 'Health calculation period (1hour, 24hour, 7day)',
						'enum'        => array( '1hour', '24hour', '7day' ),
						'default'     => '24hour',
					),
					'gateway_id' => array(
						'type'        => 'string',
						'required'    => true,
						'description' => 'Payment gateway ID',
					),
				),
			)
		);

		// Get gateway health history
		register_rest_route(
			$this->namespace,
			'/health/gateways/(?P<gateway_id>[^/]+)/history',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_gateway_health_history' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'gateway_id' => array(
						'type'        => 'string',
						'required'    => true,
						'description' => 'Payment gateway ID',
					),
					'days'       => array(
						'type'        => 'integer',
						'description' => 'Number of days to retrieve history for',
						'default'     => 7,
						'minimum'     => 1,
						'maximum'     => 30,
					),
					'page'       => array(
						'type'        => 'integer',
						'description' => 'Page number',
						'default'     => 1,
						'minimum'     => 1,
					),
					'per_page'   => array(
						'type'        => 'integer',
						'description' => 'Items per page',
						'default'     => 20,
						'minimum'     => 1,
						'maximum'     => 100,
					),
				),
			)
		);

		// Trigger on-demand health recalculation for all gateways
		register_rest_route(
			$this->namespace,
			'/health/recalculate',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'recalculate_all_health' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);
	}

	/**
	 * Get WooCommerce gateways with compatibility for stubs/tests.
	 *
	 * Prefers all registered gateways when available; falls back to available (enabled) gateways.
	 *
	 * @return array<string,object> Map of gateway_id => gateway object
	 */
	private function get_wc_gateways_all() {
		if ( ! function_exists( 'WC' ) || ! WC() || ! method_exists( WC(), 'payment_gateways' ) ) {
			return array();
		}

		$manager = WC()->payment_gateways();
		if ( ! $manager ) {
			return array();
		}

		// WooCommerce core provides payment_gateways() in runtime; test stubs may not.
		if ( method_exists( $manager, 'payment_gateways' ) ) {
			$all = $manager->payment_gateways();
			if ( is_array( $all ) ) {
				return $all;
			}
		}

		// Fallback: enabled/available gateways only
		if ( method_exists( $manager, 'get_available_payment_gateways' ) ) {
			$available = $manager->get_available_payment_gateways();
			return is_array( $available ) ? $available : array();
		}

		return array();
	}

	/**
	 * Get enabled (available) WooCommerce gateways with stub-safe fallback.
	 *
	 * @return array<string,object>
	 */
	private function get_wc_gateways_enabled() {
		if ( ! function_exists( 'WC' ) || ! WC() || ! method_exists( WC(), 'payment_gateways' ) ) {
			return array();
		}

		$manager = WC()->payment_gateways();
		if ( ! $manager ) {
			return array();
		}

		if ( method_exists( $manager, 'get_available_payment_gateways' ) ) {
			$available = $manager->get_available_payment_gateways();
			return is_array( $available ) ? $available : array();
		}

		// Fallback: if only full list is available, return that
		if ( method_exists( $manager, 'payment_gateways' ) ) {
			$all = $manager->payment_gateways();
			return is_array( $all ) ? $all : array();
		}

		return array();
	}

	/**
	 * Get all gateway health data
	 *
	 * @param WP_REST_Request $request Request object
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_all_gateway_health( $request ) {
		$period = $this->get_string_param( $request, 'period', '24h' );

		// Map frontend period format (24h, 7d, 30d, 90d) to backend format (1hour, 24hour, 7day, 30day, 90day)
		$period_map = array(
			'24h' => '24hour',
			'7d'  => '7day',
			'30d' => '30day',
			'90d' => '90day',
		);
		$backend_period = isset( $period_map[ $period ] ) ? $period_map[ $period ] : '24hour';

		// Validate period and license
		$license = new WC_Payment_Monitor_License();
		$tier    = $license->get_license_tier();

		if ( ( '30day' === $backend_period || '90day' === $backend_period ) && ! in_array( $tier, array( 'pro', 'agency' ), true ) ) {
			return $this->get_error_response(
				'rest_forbidden_period',
				__( 'Extended analytics history is only available in PRO and Agency plans.', 'wc-payment-monitor' ),
				403
			);
		}

		$valid_periods = array( '1hour', '24hour', '7day', '30day', '90day' );
		if ( ! in_array( $backend_period, $valid_periods, true ) ) {
			return $this->get_error_response(
				'invalid_period',
				__( 'Invalid health period. Must be one of: 24h, 7d, 30d, 90d', 'wc-payment-monitor' ),
				400
			);
		}

		try {
			// Get WooCommerce gateways based on requested scope (default: enabled)
			$scope    = $this->get_string_param( $request, 'scope', 'enabled' );
			$gateways = ( 'all' === $scope ) ? $this->get_wc_gateways_all() : $this->get_wc_gateways_enabled();

			if ( empty( $gateways ) ) {
				return $this->get_paginated_response( array(), 0, 1, 1 );
			}

			// Initialize connectivity checker
			$connectivity = new WC_Payment_Monitor_Gateway_Connectivity();

			$health_data   = array();
			$gateway_limit = WC_Payment_Monitor_License::GATEWAY_LIMITS[ $tier ];
			$count         = 0;

			foreach ( $gateways as $gateway ) {
				$gateway_id = $gateway->id;
				$is_locked  = $count >= $gateway_limit;
				$count++;

				// Get health metrics for this gateway
				$health = $this->get_gateway_health_data( $gateway_id, $backend_period );
				if ( ! $health ) {
					$health = $this->ensure_health_row( $gateway_id, $backend_period );
				}

				// Get last connectivity check
				$last_check = $connectivity->get_last_check( $gateway_id );

				if ( $health ) {
					// Get historical trend data
					$trend_data = $is_locked ? array() : $this->get_gateway_trend_data( $gateway_id, $period );

					$item = array(
						'gateway_id'              => $gateway_id,
						'gateway_name'            => WC_Payment_Monitor::get_friendly_gateway_name( $gateway_id ),
						'health_percentage'       => $is_locked ? 0 : floatval( $health->success_rate ),
						'success_rate'            => $is_locked ? 0 : floatval( $health->success_rate ),
						'success_rate_24h'        => $is_locked ? 0 : floatval( $health->success_rate ),
						'transaction_count'       => $is_locked ? 0 : intval( $health->total_transactions ),
						'successful_transactions' => $is_locked ? 0 : intval( $health->successful_transactions ),
						'failed_transactions'     => $is_locked ? 0 : intval( $health->failed_transactions ),
						'failed_count_24h'        => $is_locked ? 0 : intval( $health->failed_transactions ),
						'avg_response_time'       => $is_locked ? null : ( isset( $health->avg_response_time ) ? intval( $health->avg_response_time ) : null ),
						'last_checked'            => $health->calculated_at,
						'last_failure'            => null,
						'trend_data'              => $trend_data,
						'is_locked'               => $is_locked,
					);

					// Add connectivity status if available
					if ( $last_check ) {
						$item['connectivity_status']           = $is_locked ? 'locked' : $last_check->status;
						$item['connectivity_message']          = $is_locked ? 'Unlock PRO for more gateways' : $last_check->message;
						$item['connectivity_checked_at']       = $last_check->checked_at;
						$item['connectivity_response_time_ms'] = $is_locked ? null : floatval( $last_check->response_time_ms );
					} else {
						$item['connectivity_status']           = $is_locked ? 'locked' : null;
						$item['connectivity_message']          = $is_locked ? 'Unlock PRO for more gateways' : 'No connectivity check performed yet';
						$item['connectivity_checked_at']       = null;
						$item['connectivity_response_time_ms'] = null;
					}

					$health_data[] = $item;
				}
			}

			return $this->get_paginated_response(
				$health_data,
				count( $health_data ),
				1,
				max( 1, count( $health_data ) )
			);
		} catch ( Exception $e ) {
			return $this->get_error_response(
				'health_retrieval_failed',
				__( 'Failed to retrieve gateway health data', 'wc-payment-monitor' ),
				500
			);
		}
	}

	/**
	 * Get specific gateway health data
	 *
	 * @param WP_REST_Request $request Request object
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_gateway_health( $request ) {
		$gateway_id = $request->get_param( 'gateway_id' );
		$period     = $this->get_string_param( $request, 'period', '24h' );

		// Sanitize gateway ID
		$gateway_id = sanitize_text_field( $gateway_id );

		// Map frontend period format (24h, 7d, 30d, 90d) to backend format (1hour, 24hour, 7day, 30day, 90day)
		$period_map = array(
			'24h' => '24hour',
			'7d'  => '7day',
			'30d' => '30day',
			'90d' => '90day',
		);
		$backend_period = isset( $period_map[ $period ] ) ? $period_map[ $period ] : '24hour';

		// Validate period and license
		$license = new WC_Payment_Monitor_License();
		$tier    = $license->get_license_tier();

		if ( ( '30day' === $backend_period || '90day' === $backend_period ) && ! in_array( $tier, array( 'pro', 'agency' ), true ) ) {
			return $this->get_error_response(
				'rest_forbidden_period',
				__( 'Extended analytics history is only available in PRO and Agency plans.', 'wc-payment-monitor' ),
				403
			);
		}

		$valid_periods = array( '1hour', '24hour', '7day', '30day', '90day' );
		if ( ! in_array( $backend_period, $valid_periods, true ) ) {
			return $this->get_error_response(
				'invalid_period',
				__( 'Invalid health period. Must be one of: 24h, 7d, 30d, 90d', 'wc-payment-monitor' ),
				400
			);
		}

		try {
			// Get gateway (enabled by default unless scope=all)
			$scope    = $this->get_string_param( $request, 'scope', 'enabled' );
			$gateways = ( 'all' === $scope ) ? $this->get_wc_gateways_all() : $this->get_wc_gateways_enabled();

			if ( ! isset( $gateways[ $gateway_id ] ) ) {
				return $this->get_error_response(
					'gateway_not_found',
					__( 'Payment gateway not found', 'wc-payment-monitor' ),
					404
				);
			}

			$gateway = $gateways[ $gateway_id ];

			// Check if gateway is within license limits
			$gateway_limit = WC_Payment_Monitor_License::GATEWAY_LIMITS[ $tier ];
			$gateway_ids   = array_keys( $gateways );
			$index         = array_search( $gateway_id, $gateway_ids, true );
			$is_locked     = ( $index !== false && $index >= $gateway_limit );

			// Get health metrics
			$health = $this->get_gateway_health_data( $gateway_id, $period );
			if ( ! $health ) {
				$health = $this->ensure_health_row( $gateway_id, $period );
			}

			if ( ! $health ) {
				return $this->get_error_response(
					'health_data_not_found',
					__( 'No health data available for this gateway', 'wc-payment-monitor' ),
					404
				);
			}

			// Initialize connectivity checker
			$connectivity = new WC_Payment_Monitor_Gateway_Connectivity();
			$last_check   = $connectivity->get_last_check( $gateway_id );

			$response_data = array(
				'gateway_id'              => $gateway_id,
				'gateway_name'            => WC_Payment_Monitor::get_friendly_gateway_name( $gateway_id ),
				'period'                  => $period,
				'health_percentage'       => floatval( $health->success_rate ),
				'success_rate'            => floatval( $health->success_rate ),
				'success_rate_24h'        => floatval( $health->success_rate ),
				'transaction_count'       => intval( $health->total_transactions ),
				'successful_transactions' => intval( $health->successful_transactions ),
				'failed_transactions'     => intval( $health->failed_transactions ),
				'failed_count_24h'        => intval( $health->failed_transactions ),
				'avg_response_time'       => intval( $health->avg_response_time ),
				'last_checked'            => $health->calculated_at,
				'last_updated'            => $health->calculated_at,
				'last_failure'            => null,
				'trend_data'              => $this->get_gateway_trend_data( $gateway_id, '24h' ),
			);

			// Add connectivity status
			if ( $last_check ) {
				$response_data['connectivity_status']           = $last_check->status;
				$response_data['connectivity_message']          = $last_check->message;
				$response_data['connectivity_checked_at']       = $last_check->checked_at;
				$response_data['connectivity_response_time_ms'] = floatval( $last_check->response_time_ms );
			} else {
				$response_data['connectivity_status']           = null;
				$response_data['connectivity_message']          = 'No connectivity check performed yet';
				$response_data['connectivity_checked_at']       = null;
				$response_data['connectivity_response_time_ms'] = null;
			}

			return $this->get_success_response( $response_data );
		} catch ( Exception $e ) {
			return $this->get_error_response(
				'health_retrieval_failed',
				__( 'Failed to retrieve gateway health data', 'wc-payment-monitor' ),
				500
			);
		}
	}

	/**
	 * Get gateway health history
	 *
	 * @param WP_REST_Request $request Request object
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_gateway_health_history( $request ) {
		$gateway_id = $request->get_param( 'gateway_id' );
		$days       = $this->get_int_param( $request, 'days', 7 );

		// Sanitize gateway ID
		$gateway_id = sanitize_text_field( $gateway_id );

		// Validate days
		$days = ( $days > 0 && $days <= 30 ) ? $days : 7;

		// Get pagination params
		$pagination = $this->validate_pagination( $request );

		try {
			// Get gateway
			$gateways = WC()->payment_gateways()->get_available_payment_gateways();

			if ( ! isset( $gateways[ $gateway_id ] ) ) {
				return $this->get_error_response(
					'gateway_not_found',
					__( 'Payment gateway not found', 'wc-payment-monitor' ),
					404
				);
			}

			global $wpdb;
			$table_name = $wpdb->prefix . 'payment_monitor_gateway_health';

			// Check if table exists
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) !== $table_name ) {
				return $this->get_paginated_response(
					array(),
					0,
					$pagination['page'],
					$pagination['per_page']
				);
			}

			// Calculate date range
			$end_date   = current_time( 'mysql' );
			$start_date = date_create( current_time( 'mysql' ) )->modify( "-{$days} days" )->format( 'Y-m-d H:i:s' );

			// Get total count
			$total_count = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM $table_name WHERE gateway_id = %s AND calculated_at >= %s AND calculated_at <= %s",
					$gateway_id,
					$start_date,
					$end_date
				)
			);

			// Get paginated results
			$offset = $this->calculate_offset( $pagination['page'], $pagination['per_page'] );

			$results = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, gateway_id, success_rate, total_transactions, successful_transactions, 
						failed_transactions, calculated_at
				 FROM $table_name 
				 WHERE gateway_id = %s AND calculated_at >= %s AND calculated_at <= %s
				 ORDER BY calculated_at DESC
				 LIMIT %d OFFSET %d",
					$gateway_id,
					$start_date,
					$end_date,
					$pagination['per_page'],
					$offset
				)
			);

			if ( ! $results ) {
				$results = array();
			}

			// Format results
			$history_data = array_map(
				function ( $row ) {
					// Derive a simple status from success_rate for history presentation
					$status = 'unknown';
					if ( isset( $row->success_rate ) ) {
						$rate = floatval( $row->success_rate );
						if ( $rate >= 95 ) {
							$status = 'healthy';
						} elseif ( $rate >= 75 ) {
							$status = 'degraded';
						} else {
							$status = 'critical';
						}
					}

					return array(
						'id'                      => intval( $row->id ),
						'gateway_id'              => $row->gateway_id,
						'success_rate'            => floatval( $row->success_rate ),
						'total_transactions'      => intval( $row->total_transactions ),
						'successful_transactions' => intval( $row->successful_transactions ),
						'failed_transactions'     => intval( $row->failed_transactions ),
						'status'                  => $status,
						'timestamp'               => $row->calculated_at,
					);
				},
				$results
			);

			return $this->get_paginated_response(
				$history_data,
				$total_count,
				$pagination['page'],
				$pagination['per_page']
			);
		} catch ( Exception $e ) {
			return $this->get_error_response(
				'history_retrieval_failed',
				__( 'Failed to retrieve gateway health history', 'wc-payment-monitor' ),
				500
			);
		}
	}

	/**
	 * Get gateway health data from database
	 *
	 * @param string $gateway_id Payment gateway ID
	 * @param string $period     Health period (1hour, 24hour, 7day)
	 *
	 * @return object|null Health data or null if not found
	 */
	private function get_gateway_health_data( $gateway_id, $period ) {
		global $wpdb;
		$table_name = $wpdb->prefix . 'payment_monitor_gateway_health';

		// Check if table exists
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) !== $table_name ) {
			return null;
		}

		$result = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, gateway_id, success_rate, total_transactions, successful_transactions,
					failed_transactions, avg_response_time, calculated_at as last_updated
             FROM $table_name 
             WHERE gateway_id = %s AND period = %s
			 ORDER BY calculated_at DESC
             LIMIT 1",
				$gateway_id,
				$period
			)
		);

		return $result;
	}

	/**
	 * Get gateway health trend data for historical visualization
	 *
	 * @param string $gateway_id Payment gateway ID
	 * @param string $period     Time period (24h, 7d, 30d)
	 *
	 * @return array Array of trend data points with timestamp and health score
	 */
	private function get_gateway_trend_data( $gateway_id, $period ) {
		global $wpdb;
		$table_name = $wpdb->prefix . 'payment_monitor_gateway_health';

		// Check if table exists
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) !== $table_name ) {
			return array();
		}

		// Determine date range and aggregation based on period
		$end_date    = current_time( 'mysql' );
		$data_limit  = 24; // Default to 24 points
		$date_format = '%Y-%m-%d %H:00:00'; // Hourly grouping

		switch ( $period ) {
			case '24h':
				$start_date  = date_create( current_time( 'mysql' ) )->modify( '-24 hours' )->format( 'Y-m-d H:i:s' );
				$data_limit  = 24;
				$date_format = '%Y-%m-%d %H:00:00';
				break;
			case '7d':
				$start_date  = date_create( current_time( 'mysql' ) )->modify( '-7 days' )->format( 'Y-m-d H:i:s' );
				$data_limit  = 7;
				$date_format = '%Y-%m-%d 00:00:00';
				break;
			case '30d':
				$start_date  = date_create( current_time( 'mysql' ) )->modify( '-30 days' )->format( 'Y-m-d H:i:s' );
				$data_limit  = 30;
				$date_format = '%Y-%m-%d 00:00:00';
				break;
			case '90d':
				$start_date  = date_create( current_time( 'mysql' ) )->modify( '-90 days' )->format( 'Y-m-d H:i:s' );
				$data_limit  = 90;
				$date_format = '%Y-%m-%d 00:00:00';
				break;
			default:
				$start_date = date_create( current_time( 'mysql' ) )->modify( '-24 hours' )->format( 'Y-m-d H:i:s' );
		}

		// Get aggregated health data for the period
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT 
                    DATE_FORMAT(calculated_at, %s) as timestamp,
                    AVG(success_rate) as avg_health_score
                 FROM $table_name 
                 WHERE gateway_id = %s 
                 AND calculated_at >= %s 
                 AND calculated_at <= %s
                 GROUP BY DATE_FORMAT(calculated_at, %s)
                 ORDER BY calculated_at ASC
                 LIMIT %d",
				$date_format,
				$gateway_id,
				$start_date,
				$end_date,
				$date_format,
				$data_limit
			)
		);

		if ( ! $results ) {
			return array();
		}

		// Format results for frontend
		return array_map(
			function ( $row ) {
				return array(
					'timestamp'    => $row->timestamp,
					'health_score' => floatval( $row->avg_health_score ),
				);
			},
			$results
		);
	}

	/**
	 * Attempt to calculate and persist health data on-demand when missing
	 *
	 * @param string $gateway_id Gateway ID
	 * @param string $period     Backend health period (1hour, 24hour, 7day)
	 *
	 * @return object|null Newly fetched health row or null
	 */
	private function ensure_health_row( $gateway_id, $period ) {
		try {
			$health_engine = new WC_Payment_Monitor_Health();
			$health_engine->calculate_health( $gateway_id );

			// Re-fetch the row after calculation
			return $this->get_gateway_health_data( $gateway_id, $period );
		} catch ( Exception $e ) {
			return null;
		}
	}

	/**
	 * Trigger on-demand health recalculation for all gateways
	 *
	 * @param WP_REST_Request $request Request object
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function recalculate_all_health( $request ) {
		try {
			$health_engine = new WC_Payment_Monitor_Health();
			$gateways      = $this->get_wc_gateways_enabled();

			$recalculated = 0;
			foreach ( $gateways as $gateway ) {
				try {
					$health_engine->calculate_health( $gateway->id );
					++$recalculated;
				} catch ( Exception $e ) {
					// Log but continue with other gateways
					error_log(
						'Payment Monitor: Error recalculating health for gateway ' . $gateway->id . ': ' . $e->getMessage()
					);
				}
			}

			// Also trigger connectivity checks for enabled gateways
			try {
				$connectivity = new WC_Payment_Monitor_Gateway_Connectivity();
				$connectivity->check_all_gateways();
			} catch ( Exception $e ) {
				error_log(
					'Payment Monitor: Error during connectivity checks: ' . $e->getMessage()
				);
			}

			return $this->get_success_response(
				array(
					'success'        => true,
					'message'        => sprintf(
						/* translators: %d: number of gateways */
						__( 'Recalculated health for %d gateway(s)', 'wc-payment-monitor' ),
						$recalculated
					),
					'gateways_count' => $recalculated,
				)
			);
		} catch ( Exception $e ) {
			return $this->get_error_response(
				'recalculation_failed',
				__( 'Failed to recalculate gateway health', 'wc-payment-monitor' ),
				500
			);
		}
	}
}
