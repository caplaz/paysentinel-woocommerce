<?php

/**
 * Transaction history REST API endpoints
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PaySentinel_API_Transactions extends PaySentinel_API_Base {

	/**
	 * Register REST routes for transaction endpoints
	 */
	public function register_routes() {
		// Get all transactions with filtering
		register_rest_route(
			$this->namespace,
			'/transactions',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_transactions' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'gateway_id' => array(
						'type'        => 'string',
						'description' => 'Filter by payment gateway ID',
						'required'    => false,
					),
					'status'     => array(
						'type'        => 'string',
						'description' => 'Filter by transaction status (success, failed, pending)',
						'enum'        => array( 'success', 'failed', 'pending' ),
						'required'    => false,
					),
					'start_date' => array(
						'type'        => 'string',
						'description' => 'Start date for filtering (Y-m-d format)',
						'required'    => false,
					),
					'end_date'   => array(
						'type'        => 'string',
						'description' => 'End date for filtering (Y-m-d format)',
						'required'    => false,
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

		// Get single transaction details
		register_rest_route(
			$this->namespace,
			'/transactions/(?P<transaction_id>[0-9]+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_transaction' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'transaction_id' => array(
						'type'        => 'integer',
						'required'    => true,
						'description' => 'Transaction ID',
					),
				),
			)
		);
	}

	/**
	 * Get transactions with filtering and pagination
	 *
	 * @param WP_REST_Request $request Request object
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_transactions( $request ) {
		try {
			global $wpdb;
			$table_name = $this->database->get_transactions_table();

			// Check if table exists
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) !== $table_name ) {
				return $this->get_paginated_response(
					array(),
					0,
					1,
					20
				);
			}

			// Get filter parameters
			$gateway_id = $this->get_string_param( $request, 'gateway_id' );
			$status     = $this->get_string_param( $request, 'status' );
			$start_date = $this->get_string_param( $request, 'start_date' );
			$end_date   = $this->get_string_param( $request, 'end_date' );

			// Validate dates if provided
			if ( $start_date ) {
				$start_date_result = $this->validate_date( $start_date );
				if ( is_wp_error( $start_date_result ) ) {
					return $start_date_result;
				}
				$start_date = $start_date_result . ' 00:00:00';
			}

			if ( $end_date ) {
				$end_date_result = $this->validate_date( $end_date );
				if ( is_wp_error( $end_date_result ) ) {
					return $end_date_result;
				}
				$end_date = $end_date_result . ' 23:59:59';
			}

			// Build WHERE clause
			$where_conditions = array( '1=1' );
			$where_params     = array();

			if ( $gateway_id ) {
				$where_conditions[] = 'gateway_id = %s';
				$where_params[]     = $gateway_id;
			}

			if ( $status ) {
				$where_conditions[] = 'status = %s';
				$where_params[]     = $status;
			}

			if ( $start_date ) {
				$where_conditions[] = 'created_at >= %s';
				$where_params[]     = $start_date;
			}

			if ( $end_date ) {
				$where_conditions[] = 'created_at <= %s';
				$where_params[]     = $end_date;
			}

			$where_clause = implode( ' AND ', $where_conditions );

			// Get pagination parameters
			$pagination = $this->validate_pagination( $request );

			// Get total count
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$total_count = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM %i WHERE {$where_clause}", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					array_merge( array( $table_name ), $where_params )
				)
			);

			// Get paginated results
			$offset = $this->calculate_offset( $pagination['page'], $pagination['per_page'] );

			$query = $wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT id, order_id, gateway_id, status, amount, currency, failure_reason, failure_code, 
                     transaction_id, customer_email, customer_ip, created_at as created_at
                      FROM %i 
                      WHERE {$where_clause}
                      ORDER BY created_at DESC
                      LIMIT %d OFFSET %d",
				...array_merge( array( $table_name ), $where_params, array( $pagination['per_page'], $offset ) )
			);

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$results = $wpdb->get_results( $query );

			if ( ! $results ) {
				$results = array();
			}

			// Format results
			$transactions = array_map(
				function ( $row ) {
					return array(
						'id'             => intval( $row->id ),
						'order_id'       => intval( $row->order_id ),
						'gateway_id'     => $row->gateway_id,
						'gateway_name'   => PaySentinel::get_friendly_gateway_name( $row->gateway_id ),
						'status'         => $row->status,
						'amount'         => floatval( $row->amount ),
						'currency'       => $row->currency,
						'failure_reason' => $row->failure_reason,
						'failure_code'   => $row->failure_code,
						'created_at'     => $row->created_at,
						'transaction_id' => $row->transaction_id,
						'customer_email' => $row->customer_email,
						'customer_ip'    => $row->customer_ip,
					);
				},
				$results
			);

			return $this->get_paginated_response(
				$transactions,
				$total_count,
				$pagination['page'],
				$pagination['per_page']
			);
		} catch ( Exception $e ) {
			return $this->get_error_response(
				'transaction_retrieval_failed',
				__( 'Failed to retrieve transactions', 'paysentinel' ),
				500
			);
		}
	}

	/**
	 * Get single transaction details
	 *
	 * @param WP_REST_Request $request Request object
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_transaction( $request ) {
		try {
			$transaction_id = intval( $request->get_param( 'transaction_id' ) );

			if ( $transaction_id <= 0 ) {
				return $this->get_error_response(
					'invalid_transaction_id',
					__( 'Invalid transaction ID', 'paysentinel' ),
					400
				);
			}

			global $wpdb;
			$table_name = $this->database->get_transactions_table();

			// Check if table exists
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) !== $table_name ) {
				return $this->get_error_response(
					'transaction_not_found',
					__( 'Transaction not found', 'paysentinel' ),
					404
				);
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$result = $wpdb->get_row(
				$wpdb->prepare(
					'SELECT id, order_id, gateway_id, status, amount, currency, failure_reason, failure_code,
                        created_at as created_at
                 FROM %i 
                 WHERE id = %d',
					$table_name,
					$transaction_id
				)
			);

			if ( ! $result ) {
				return $this->get_error_response(
					'transaction_not_found',
					__( 'Transaction not found', 'paysentinel' ),
					404
				);
			}

			// Get associated order for additional context
			$order = wc_get_order( $result->order_id );

			$transaction_data = array(
				'id'             => intval( $result->id ),
				'order_id'       => intval( $result->order_id ),
				'gateway_id'     => $result->gateway_id,
				'status'         => $result->status,
				'amount'         => floatval( $result->amount ),
				'currency'       => $result->currency,
				'failure_reason' => $result->failure_reason,
				'failure_code'   => $result->failure_code,
				'created_at'     => $result->created_at,
			);

			// Add order details if order exists
			if ( $order ) {
				$transaction_data['order'] = array(
					'id'             => $order->get_id(),
					'status'         => $order->get_status(),
					'customer_email' => $order->get_billing_email(),
					'total'          => floatval( $order->get_total() ),
					'created_date'   => $order->get_date_created() ? $order->get_date_created()->format( 'Y-m-d H:i:s' ) : null,
				);
			}

			return $this->get_success_response( $transaction_data );
		} catch ( Exception $e ) {
			return $this->get_error_response(
				'transaction_retrieval_failed',
				__( 'Failed to retrieve transaction details', 'paysentinel' ),
				500
			);
		}
	}
}
