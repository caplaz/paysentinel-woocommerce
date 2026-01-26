<?php
/**
 * Alerts REST API endpoints
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WC_Payment_Monitor_API_Alerts extends WC_Payment_Monitor_API_Base {

	/**
	 * Register REST routes for alerts endpoints
	 */
	public function register_routes() {
		// Get alerts
		register_rest_route(
			$this->namespace,
			'/alerts',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_alerts' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'page'     => array(
						'type'        => 'integer',
						'description' => 'Page number',
						'default'     => 1,
						'minimum'     => 1,
						'required'    => false,
					),
					'per_page' => array(
						'type'        => 'integer',
						'description' => 'Items per page',
						'default'     => 20,
						'minimum'     => 1,
						'maximum'     => 100,
						'required'    => false,
					),
					'status'   => array(
						'type'        => 'string',
						'description' => 'Filter by alert status',
						'enum'        => array( 'active', 'resolved', 'all' ),
						'default'     => 'all',
						'required'    => false,
					),
					'severity' => array(
						'type'        => 'string',
						'description' => 'Filter by alert severity',
						'enum'        => array( 'info', 'warning', 'high', 'critical' ),
						'required'    => false,
					),
				),
			)
		);

		// Get specific alert
		register_rest_route(
			$this->namespace,
			'/alerts/(?P<id>[0-9]+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_alert' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'id' => array(
						'type'        => 'integer',
						'description' => 'Alert ID',
						'required'    => true,
					),
				),
			)
		);

		// Resolve alert
		register_rest_route(
			$this->namespace,
			'/alerts/(?P<id>[0-9]+)/resolve',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'resolve_alert' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'id' => array(
						'type'        => 'integer',
						'description' => 'Alert ID',
						'required'    => true,
					),
				),
			)
		);
	}

	/**
	 * Get alerts
	 *
	 * @param WP_REST_Request $request Request object
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_alerts( $request ) {
		global $wpdb;

		$page     = $request->get_param( 'page' );
		$per_page = $request->get_param( 'per_page' );
		$status   = $request->get_param( 'status' );
		$severity = $request->get_param( 'severity' );

		$offset = ( $page - 1 ) * $per_page;

		$where_clauses = array();
		$where_values  = array();

		if ( $status && $status !== 'all' ) {
			if ( $status === 'resolved' ) {
				$where_clauses[] = 'is_resolved = 1';
			} elseif ( $status === 'active' ) {
				$where_clauses[] = 'is_resolved = 0';
			}
		}

		if ( $severity ) {
			$where_clauses[] = 'severity = %s';
			$where_values[]  = $severity;
		}

		$where_sql = '';
		if ( ! empty( $where_clauses ) ) {
			$where_sql = 'WHERE ' . implode( ' AND ', $where_clauses );
		}

		$prefix     = ( is_object( $wpdb ) && property_exists( $wpdb, 'prefix' ) ) ? $wpdb->prefix : 'wp_';
		$table_name = $prefix . 'payment_monitor_alerts';

		// Check if table exists
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) !== $table_name ) {
			return $this->get_paginated_response( array(), 0, intval( $page ), intval( $per_page ) );
		}

		// Get total count
		$total_query = $wpdb->prepare(
			"SELECT COUNT(*) FROM {$table_name} {$where_sql}",
			$where_values
		);
		$total       = $wpdb->get_var( $total_query );

		// Get alerts
		$query = $wpdb->prepare(
			"SELECT * FROM {$table_name} {$where_sql} ORDER BY created_at DESC LIMIT %d OFFSET %d",
			array_merge( $where_values, array( $per_page, $offset ) )
		);

		$alerts = $wpdb->get_results( $query, ARRAY_A );

		// Format alerts
		$formatted_alerts = array();
		foreach ( $alerts as $alert ) {
			$formatted_alerts[] = $this->format_alert( $alert );
		}

		return $this->get_paginated_response(
			$formatted_alerts,
			intval( $total ),
			intval( $page ),
			intval( $per_page )
		);
	}

	/**
	 * Get specific alert
	 *
	 * @param WP_REST_Request $request Request object
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_alert( $request ) {
		global $wpdb;

		$id = $request->get_param( 'id' );

		$prefix     = ( is_object( $wpdb ) && property_exists( $wpdb, 'prefix' ) ) ? $wpdb->prefix : 'wp_';
		$table_name = $prefix . 'payment_monitor_alerts';

		// Check if table exists
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) !== $table_name ) {
			return $this->get_error_response( 'alert_not_found', 'Alert not found', 404 );
		}

		$alert = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table_name} WHERE id = %d", $id ),
			ARRAY_A
		);

		if ( ! $alert ) {
			return $this->get_error_response( 'alert_not_found', 'Alert not found', 404 );
		}

		return $this->get_success_response( $this->format_alert( $alert ) );
	}

	/**
	 * Resolve alert
	 */
	public function resolve_alert( $request ) {
		global $wpdb;

		$id = $request->get_param( 'id' );

		$prefix     = ( is_object( $wpdb ) && property_exists( $wpdb, 'prefix' ) ) ? $wpdb->prefix : 'wp_';
		$table_name = $prefix . 'payment_monitor_alerts';

		// Check if table exists
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) !== $table_name ) {
			return $this->get_error_response( 'alert_not_found', 'Alert not found', 404 );
		}

		// Check if alert exists
		$alert = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table_name} WHERE id = %d", $id ),
			ARRAY_A
		);

		if ( ! $alert ) {
			return $this->get_error_response( 'alert_not_found', 'Alert not found', 404 );
		}

		// Update alert status
		$result = $wpdb->update(
			$table_name,
			array(
				'is_resolved' => 1,
				'resolved_at' => current_time( 'mysql' ),
			),
			array( 'id' => $id ),
			array( '%d', '%s' ),
			array( '%d' )
		);

		if ( $result === false ) {
			return $this->get_error_response( 'update_failed', 'Failed to resolve alert', 500 );
		}

		// Get updated alert
		$updated_alert = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table_name} WHERE id = %d", $id ),
			ARRAY_A
		);

		return $this->get_success_response( $this->format_alert( $updated_alert ) );
	}

	/**
	 * Format alert for API response
	 */
	private function format_alert( $alert ) {
		// Generate title from alert_type
		$alert_type_titles = array(
			'gateway_down'       => 'Gateway Down',
			'low_success_rate'   => 'Low Success Rate',
			'high_failure_count' => 'High Failure Count',
			'gateway_error'      => 'Gateway Error',
		);

		$title = isset( $alert_type_titles[ $alert['alert_type'] ] )
			? $alert_type_titles[ $alert['alert_type'] ]
			: ucwords( str_replace( '_', ' ', $alert['alert_type'] ) );

		return array(
			'id'          => intval( $alert['id'] ),
			'alert_type'  => $alert['alert_type'],
			'gateway_id'  => $alert['gateway_id'],
			'title'       => $title,
			'message'     => $alert['message'],
			'severity'    => $alert['severity'],
			'status'      => $alert['is_resolved'] ? 'resolved' : 'active',
			'metadata'    => ! empty( $alert['metadata'] ) ? json_decode( $alert['metadata'], true ) : null,
			'created_at'  => $alert['created_at'],
			'resolved_at' => $alert['resolved_at'],
		);
	}
}
