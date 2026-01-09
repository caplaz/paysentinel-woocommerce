<?php
/**
 * Gateway health REST API endpoints
 */

// Prevent direct access
if (!defined('ABSPATH')) {
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
                'methods' => 'GET',
                'callback' => array($this, 'get_all_gateway_health'),
                'permission_callback' => array($this, 'check_permission'),
                'args' => array(
                    'period' => array(
                        'type' => 'string',
                        'description' => 'Health calculation period (1hour, 24hour, 7day)',
                        'enum' => array('1hour', '24hour', '7day'),
                        'default' => '24hour',
                    ),
                ),
            )
        );
        
        // Get specific gateway health data
        register_rest_route(
            $this->namespace,
            '/health/gateways/(?P<gateway_id>[^/]+)',
            array(
                'methods' => 'GET',
                'callback' => array($this, 'get_gateway_health'),
                'permission_callback' => array($this, 'check_permission'),
                'args' => array(
                    'period' => array(
                        'type' => 'string',
                        'description' => 'Health calculation period (1hour, 24hour, 7day)',
                        'enum' => array('1hour', '24hour', '7day'),
                        'default' => '24hour',
                    ),
                    'gateway_id' => array(
                        'type' => 'string',
                        'required' => true,
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
                'methods' => 'GET',
                'callback' => array($this, 'get_gateway_health_history'),
                'permission_callback' => array($this, 'check_permission'),
                'args' => array(
                    'gateway_id' => array(
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Payment gateway ID',
                    ),
                    'days' => array(
                        'type' => 'integer',
                        'description' => 'Number of days to retrieve history for',
                        'default' => 7,
                        'minimum' => 1,
                        'maximum' => 30,
                    ),
                    'page' => array(
                        'type' => 'integer',
                        'description' => 'Page number',
                        'default' => 1,
                        'minimum' => 1,
                    ),
                    'per_page' => array(
                        'type' => 'integer',
                        'description' => 'Items per page',
                        'default' => 20,
                        'minimum' => 1,
                        'maximum' => 100,
                    ),
                ),
            )
        );
    }
    
    /**
     * Get all gateway health data
     * 
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response
     */
    public function get_all_gateway_health($request) {
        $period = $this->get_string_param($request, 'period', '24hour');
        
        // Validate period
        $valid_periods = array('1hour', '24hour', '7day');
        if (!in_array($period, $valid_periods, true)) {
            return $this->get_error_response(
                'invalid_period',
                __('Invalid health period. Must be one of: 1hour, 24hour, 7day', 'wc-payment-monitor'),
                400
            );
        }
        
        try {
            // Get all WooCommerce payment gateways
            $gateways = WC()->payment_gateways()->get_available_payment_gateways();
            
            if (empty($gateways)) {
                return new WP_REST_Response(array());
            }
            
            $health_data = array();
            
            foreach ($gateways as $gateway) {
                $gateway_id = $gateway->id;
                
                // Get health metrics for this gateway
                $health = $this->get_gateway_health_data($gateway_id, $period);
                
                if ($health) {
                    $health_data[] = array(
                        'gateway_id' => $gateway_id,
                        'gateway_name' => $gateway->title,
                        'period' => $period,
                        'success_rate' => floatval($health->success_rate),
                        'total_transactions' => intval($health->total_transactions),
                        'successful_transactions' => intval($health->successful_transactions),
                        'failed_transactions' => intval($health->failed_transactions),
                        'avg_response_time' => intval($health->avg_response_time),
                        'last_updated' => $health->calculated_at,
                    );
                }
            }
            
            return new WP_REST_Response($health_data);
        } catch (Exception $e) {
            return $this->get_error_response(
                'health_retrieval_failed',
                __('Failed to retrieve gateway health data', 'wc-payment-monitor'),
                500
            );
        }
    }
    
    /**
     * Get specific gateway health data
     * 
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response
     */
    public function get_gateway_health($request) {
        $gateway_id = $request->get_param('gateway_id');
        $period = $this->get_string_param($request, 'period', '24hour');
        
        // Sanitize gateway ID
        $gateway_id = sanitize_text_field($gateway_id);
        
        // Validate period
        $valid_periods = array('1hour', '24hour', '7day');
        if (!in_array($period, $valid_periods, true)) {
            return $this->get_error_response(
                'invalid_period',
                __('Invalid health period. Must be one of: 1hour, 24hour, 7day', 'wc-payment-monitor'),
                400
            );
        }
        
        try {
            // Get gateway
            $gateways = WC()->payment_gateways()->get_available_payment_gateways();
            
            if (!isset($gateways[$gateway_id])) {
                return $this->get_error_response(
                    'gateway_not_found',
                    __('Payment gateway not found', 'wc-payment-monitor'),
                    404
                );
            }
            
            $gateway = $gateways[$gateway_id];
            
            // Get health metrics
            $health = $this->get_gateway_health_data($gateway_id, $period);
            
            if (!$health) {
                return $this->get_error_response(
                    'health_data_not_found',
                    __('No health data available for this gateway', 'wc-payment-monitor'),
                    404
                );
            }
            
            $response_data = array(
                'gateway_id' => $gateway_id,
                'gateway_name' => $gateway->title,
                'period' => $period,
                'success_rate' => floatval($health->success_rate),
                'total_transactions' => intval($health->total_transactions),
                'successful_transactions' => intval($health->successful_transactions),
                'failed_transactions' => intval($health->failed_transactions),
                'avg_response_time' => intval($health->avg_response_time),
                'last_updated' => $health->calculated_at,
            );
            
            return new WP_REST_Response($response_data);
        } catch (Exception $e) {
            return $this->get_error_response(
                'health_retrieval_failed',
                __('Failed to retrieve gateway health data', 'wc-payment-monitor'),
                500
            );
        }
    }
    
    /**
     * Get gateway health history
     * 
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response
     */
    public function get_gateway_health_history($request) {
        $gateway_id = $request->get_param('gateway_id');
        $days = $this->get_int_param($request, 'days', 7);
        
        // Sanitize gateway ID
        $gateway_id = sanitize_text_field($gateway_id);
        
        // Validate days
        $days = ($days > 0 && $days <= 30) ? $days : 7;
        
        // Get pagination params
        $pagination = $this->validate_pagination($request);
        
        try {
            // Get gateway
            $gateways = WC()->payment_gateways()->get_available_payment_gateways();
            
            if (!isset($gateways[$gateway_id])) {
                return $this->get_error_response(
                    'gateway_not_found',
                    __('Payment gateway not found', 'wc-payment-monitor'),
                    404
                );
            }
            
            global $wpdb;
            $table_name = $wpdb->prefix . 'payment_monitor_gateway_health';
            
            // Check if table exists
            if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table_name)) !== $table_name) {
                return $this->get_paginated_response(
                    array(),
                    0,
                    $pagination['page'],
                    $pagination['per_page']
                );
            }
            
            // Calculate date range
            $end_date = current_time('mysql');
            $start_date = date_create(current_time('mysql'))->modify("-{$days} days")->format('Y-m-d H:i:s');
            
            // Get total count
            $total_count = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $table_name WHERE gateway_id = %s AND timestamp >= %s AND timestamp <= %s",
                $gateway_id,
                $start_date,
                $end_date
            ));
            
            // Get paginated results
            $offset = $this->calculate_offset($pagination['page'], $pagination['per_page']);
            
            $results = $wpdb->get_results($wpdb->prepare(
                "SELECT id, gateway_id, success_rate, total_transactions, successful_transactions, 
                        failed_transactions, status, timestamp as last_updated
                 FROM $table_name 
                 WHERE gateway_id = %s AND timestamp >= %s AND timestamp <= %s
                 ORDER BY timestamp DESC
                 LIMIT %d OFFSET %d",
                $gateway_id,
                $start_date,
                $end_date,
                $pagination['per_page'],
                $offset
            ));
            
            if (!$results) {
                $results = array();
            }
            
            // Format results
            $history_data = array_map(function ($row) {
                return array(
                    'id' => intval($row->id),
                    'gateway_id' => $row->gateway_id,
                    'success_rate' => floatval($row->success_rate),
                    'total_transactions' => intval($row->total_transactions),
                    'successful_transactions' => intval($row->successful_transactions),
                    'failed_transactions' => intval($row->failed_transactions),
                    'status' => $row->status,
                    'timestamp' => $row->last_updated,
                );
            }, $results);
            
            return $this->get_paginated_response(
                $history_data,
                $total_count,
                $pagination['page'],
                $pagination['per_page']
            );
        } catch (Exception $e) {
            return $this->get_error_response(
                'history_retrieval_failed',
                __('Failed to retrieve gateway health history', 'wc-payment-monitor'),
                500
            );
        }
    }
    
    /**
     * Get gateway health data from database
     * 
     * @param string $gateway_id Payment gateway ID
     * @param string $period Health period (1hour, 24hour, 7day)
     * @return object|null Health data or null if not found
     */
    private function get_gateway_health_data($gateway_id, $period) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'payment_monitor_gateway_health';
        
        // Check if table exists
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table_name)) !== $table_name) {
            return null;
        }
        
        $result = $wpdb->get_row($wpdb->prepare(
            "SELECT id, gateway_id, success_rate, total_transactions, successful_transactions,
                    failed_transactions, avg_response_time, status, timestamp as last_updated
             FROM $table_name 
             WHERE gateway_id = %s AND period = %s
             ORDER BY timestamp DESC
             LIMIT 1",
            $gateway_id,
            $period
        ));
        
        return $result;
    }
}
