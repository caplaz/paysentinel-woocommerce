<?php
/**
 * Base REST API endpoint class
 */

// Prevent direct access
if (!defined('ABSPATH')) {
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
        add_action('rest_api_init', array($this, 'register_routes'));
    }
    
    /**
     * Register API routes - must be implemented by subclasses
     */
    abstract public function register_routes();
    
    /**
     * Check if user has permission to access API
     * 
     * @param WP_REST_Request $request Request object
     * @return bool|WP_Error
     */
    public function check_permission($request) {
        // Check if user is logged in and has manage_woocommerce capability
        if (!is_user_logged_in()) {
            return new WP_Error(
                'rest_authentication_required',
                __('Authentication required', 'wc-payment-monitor'),
                array('status' => 401)
            );
        }
        
        if (!current_user_can('manage_woocommerce')) {
            return new WP_Error(
                'rest_forbidden',
                __('You do not have permission to access this resource', 'wc-payment-monitor'),
                array('status' => 403)
            );
        }
        
        return true;
    }
    
    /**
     * Get error response with consistent format
     * 
     * @param string $error_code Error code
     * @param string $message Error message
     * @param int $status HTTP status code
     * @return WP_Error
     */
    protected function get_error_response($error_code, $message, $status = 400) {
        return new WP_Error(
            $error_code,
            $message,
            array('status' => $status)
        );
    }
    
    /**
     * Get success response with consistent format
     * 
     * @param mixed $data Response data
     * @param int $status HTTP status code
     * @return WP_REST_Response
     */
    protected function get_success_response($data, $status = 200) {
        return new WP_REST_Response(
            array(
                'success' => true,
                'data' => $data,
            ),
            $status
        );
    }
    
    /**
     * Validate pagination parameters
     * 
     * @param WP_REST_Request $request Request object
     * @return array Validated pagination parameters
     */
    protected function validate_pagination($request) {
        $page = $request->get_param('page');
        $per_page = $request->get_param('per_page');
        
        // Set defaults
        $page = $page ? intval($page) : 1;
        $per_page = $per_page ? intval($per_page) : 20;
        
        // Validate ranges
        $page = $page > 0 ? $page : 1;
        $per_page = ($per_page > 0 && $per_page <= 100) ? $per_page : 20;
        
        return array(
            'page' => $page,
            'per_page' => $per_page,
        );
    }
    
    /**
     * Calculate offset for pagination
     * 
     * @param int $page Current page number
     * @param int $per_page Items per page
     * @return int Offset for LIMIT clause
     */
    protected function calculate_offset($page, $per_page) {
        return ($page - 1) * $per_page;
    }
    
    /**
     * Get paginated response with metadata
     * 
     * @param array $items Array of items
     * @param int $total_count Total number of items
     * @param int $page Current page
     * @param int $per_page Items per page
     * @param int $status HTTP status code
     * @return WP_REST_Response
     */
    protected function get_paginated_response($items, $total_count, $page, $per_page, $status = 200) {
        $total_pages = ceil($total_count / $per_page);
        
        return new WP_REST_Response(
            array(
                'success' => true,
                'data' => $items,
                'pagination' => array(
                    'page' => $page,
                    'per_page' => $per_page,
                    'total' => intval($total_count),
                    'total_pages' => intval($total_pages),
                ),
            ),
            $status
        );
    }
    
    /**
     * Sanitize and validate date parameters
     * 
     * @param string $date_string Date string to validate (Y-m-d format)
     * @return string|WP_Error Validated date or error
     */
    protected function validate_date($date_string) {
        if (empty($date_string)) {
            return '';
        }
        
        // Check format
        $date = DateTime::createFromFormat('Y-m-d', $date_string);
        if (!$date || $date->format('Y-m-d') !== $date_string) {
            return $this->get_error_response(
                'invalid_date_format',
                __('Date must be in Y-m-d format', 'wc-payment-monitor'),
                400
            );
        }
        
        return $date_string;
    }
    
    /**
     * Get sanitized string parameter
     * 
     * @param WP_REST_Request $request Request object
     * @param string $param Parameter name
     * @param string $default Default value
     * @return string
     */
    protected function get_string_param($request, $param, $default = '') {
        $value = $request->get_param($param);
        return $value ? sanitize_text_field($value) : $default;
    }
    
    /**
     * Get sanitized integer parameter
     * 
     * @param WP_REST_Request $request Request object
     * @param string $param Parameter name
     * @param int $default Default value
     * @return int
     */
    protected function get_int_param($request, $param, $default = 0) {
        $value = $request->get_param($param);
        return $value ? intval($value) : $default;
    }
}
