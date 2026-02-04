<?php

/**
 * REST API endpoints for diagnostics and recovery tools
 */
if (!defined('ABSPATH')) {
    exit;
}

class WC_Payment_Monitor_API_Diagnostics extends WC_Payment_Monitor_API_Base
{
    /**
     * Diagnostics instance
     */
    private $diagnostics;

    /**
     * Failure simulator instance
     */
    private $simulator;

    /**
     * Constructor
     */
    public function __construct()
    {
        parent::__construct();
        $this->diagnostics = new WC_Payment_Monitor_Diagnostics();
        $this->simulator   = new WC_Payment_Monitor_Failure_Simulator();
    }

    /**
     * Register routes
     */
    public function register_routes()
    {
        // Full diagnostics
        register_rest_route(
            $this->namespace,
            '/diagnostics/full',
            [
                'methods'             => 'GET',
                'callback'            => [$this, 'get_full_diagnostics'],
                'permission_callback' => [$this, 'check_permission'],
            ]
        );

        // Database diagnostics
        register_rest_route(
            $this->namespace,
            '/diagnostics/database',
            [
                'methods'             => 'GET',
                'callback'            => [$this, 'get_database_diagnostics'],
                'permission_callback' => [$this, 'check_permission'],
            ]
        );

        // Gateway diagnostics
        register_rest_route(
            $this->namespace,
            '/diagnostics/gateways',
            [
                'methods'             => 'GET',
                'callback'            => [$this, 'get_gateway_diagnostics'],
                'permission_callback' => [$this, 'check_permission'],
            ]
        );

        // Recent failures
        register_rest_route(
            $this->namespace,
            '/diagnostics/failures/recent',
            [
                'methods'             => 'GET',
                'callback'            => [$this, 'get_recent_failures'],
                'permission_callback' => [$this, 'check_permission'],
                'args'                => [
                    'limit' => [
                        'default'           => 20,
                        'sanitize_callback' => 'absint',
                    ],
                ],
            ]
        );

        // Failure analysis
        register_rest_route(
            $this->namespace,
            '/diagnostics/failures/analyze',
            [
                'methods'             => 'GET',
                'callback'            => [$this, 'analyze_failures'],
                'permission_callback' => [$this, 'check_permission'],
                'args'                => [
                    'days' => [
                        'default'           => 7,
                        'sanitize_callback' => 'absint',
                    ],
                ],
            ]
        );

        // Force retry
        register_rest_route(
            $this->namespace,
            '/diagnostics/retry/(?P<order_id>\d+)',
            [
                'methods'             => 'POST',
                'callback'            => [$this, 'force_retry'],
                'permission_callback' => [$this, 'check_permission'],
            ]
        );

        // Reset gateway health
        register_rest_route(
            $this->namespace,
            '/diagnostics/health/reset',
            [
                'methods'             => 'POST',
                'callback'            => [$this, 'reset_health'],
                'permission_callback' => [$this, 'check_permission'],
                'args'                => [
                    'gateway_id' => [
                        'default'           => '',
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                ],
            ]
        );

        // Recalculate health
        register_rest_route(
            $this->namespace,
            '/diagnostics/health/recalculate',
            [
                'methods'             => 'POST',
                'callback'            => [$this, 'recalculate_health'],
                'permission_callback' => [$this, 'check_permission'],
            ]
        );

        // Test gateway connectivity
        register_rest_route(
            $this->namespace,
            '/diagnostics/gateway/test/(?P<gateway_id>[a-zA-Z0-9_-]+)',
            [
                'methods'             => 'POST',
                'callback'            => [$this, 'test_gateway'],
                'permission_callback' => [$this, 'check_permission'],
            ]
        );

        // Clean orphaned records
        register_rest_route(
            $this->namespace,
            '/diagnostics/cleanup/orphaned',
            [
                'methods'             => 'POST',
                'callback'            => [$this, 'clean_orphaned'],
                'permission_callback' => [$this, 'check_permission'],
            ]
        );

        // Archive old transactions
        register_rest_route(
            $this->namespace,
            '/diagnostics/cleanup/archive',
            [
                'methods'             => 'POST',
                'callback'            => [$this, 'archive_transactions'],
                'permission_callback' => [$this, 'check_permission'],
                'args'                => [
                    'days' => [
                        'default'           => 90,
                        'sanitize_callback' => 'absint',
                    ],
                ],
            ]
        );

        // Maintenance endpoints (aliases for cleanup)
        register_rest_route(
            $this->namespace,
            '/diagnostics/maintenance/orphaned',
            [
                'methods'             => 'POST',
                'callback'            => [$this, 'clean_orphaned'],
                'permission_callback' => [$this, 'check_permission'],
            ]
        );

        register_rest_route(
            $this->namespace,
            '/diagnostics/maintenance/archive',
            [
                'methods'             => 'POST',
                'callback'            => [$this, 'archive_transactions'],
                'permission_callback' => [$this, 'check_permission'],
                'args'                => [
                    'days' => [
                        'default'           => 90,
                        'sanitize_callback' => 'absint',
                    ],
                ],
            ]
        );

        // Failure simulator routes
        register_rest_route(
            $this->namespace,
            '/simulator/scenarios',
            [
                'methods'             => 'GET',
                'callback'            => [$this, 'get_failure_scenarios'],
                'permission_callback' => [$this, 'check_permission'],
            ]
        );

        register_rest_route(
            $this->namespace,
            '/simulator/gateways',
            [
                'methods'             => 'GET',
                'callback'            => [$this, 'get_available_gateways'],
                'permission_callback' => [$this, 'check_permission'],
            ]
        );

        register_rest_route(
            $this->namespace,
            '/simulator/simulate',
            [
                'methods'             => 'POST',
                'callback'            => [$this, 'simulate_failure'],
                'permission_callback' => [$this, 'check_permission'],
                'args'                => [
                    'scenario'   => [
                        'required'          => true,
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                    'gateway_id' => [
                        'default'           => null,
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                    'count'      => [
                        'default'           => 1,
                        'sanitize_callback' => 'absint',
                    ],
                ],
            ]
        );

        register_rest_route(
            $this->namespace,
            '/simulator/stats',
            [
                'methods'             => 'GET',
                'callback'            => [$this, 'get_simulation_stats'],
                'permission_callback' => [$this, 'check_permission'],
            ]
        );

        register_rest_route(
            $this->namespace,
            '/simulator/clear',
            [
                'methods'             => 'POST',
                'callback'            => [$this, 'clear_simulated_failures'],
                'permission_callback' => [$this, 'check_permission'],
            ]
        );
    }

    /**
     * Get full diagnostics
     *
     * @return WP_REST_Response
     */
    public function get_full_diagnostics()
    {
        $diagnostics = $this->diagnostics->run_full_diagnostics();
        return $this->get_success_response($diagnostics);
    }

    /**
     * Get database diagnostics
     *
     * @return WP_REST_Response
     */
    public function get_database_diagnostics()
    {
        $diagnostics = $this->diagnostics->check_database_health();
        return $this->get_success_response($diagnostics);
    }

    /**
     * Get gateway diagnostics
     *
     * @return WP_REST_Response
     */
    public function get_gateway_diagnostics()
    {
        $diagnostics = $this->diagnostics->check_all_gateways();
        return $this->get_success_response($diagnostics);
    }

    /**
     * Get recent failures
     *
     * @param WP_REST_Request $request Request object.
     *
     * @return WP_REST_Response
     */
    public function get_recent_failures($request)
    {
        $limit    = $request->get_param('limit');
        $failures = $this->diagnostics->get_recent_failures($limit);
        return $this->get_success_response($failures);
    }

    /**
     * Analyze payment failures
     *
     * @param WP_REST_Request $request Request object.
     *
     * @return WP_REST_Response
     */
    public function analyze_failures($request)
    {
        $days     = $request->get_param('days');
        $analysis = $this->diagnostics->analyze_payment_failures($days);
        return $this->get_success_response($analysis);
    }

    /**
     * Force retry for an order
     *
     * @param WP_REST_Request $request Request object.
     *
     * @return WP_REST_Response
     */
    public function force_retry($request)
    {
        $order_id = $request->get_param('order_id');
        $result   = $this->diagnostics->force_retry_order($order_id);

        if ($result['success']) {
            return $this->get_success_response($result);
        } else {
            return $this->get_error_response($result['message'], 400);
        }
    }

    /**
     * Reset gateway health metrics
     *
     * @param WP_REST_Request $request Request object.
     *
     * @return WP_REST_Response
     */
    public function reset_health($request)
    {
        $gateway_id = $request->get_param('gateway_id');
        $result     = $this->diagnostics->reset_gateway_health($gateway_id);
        return $this->get_success_response($result);
    }

    /**
     * Recalculate health metrics
     *
     * @return WP_REST_Response
     */
    public function recalculate_health()
    {
        $result = $this->diagnostics->recalculate_health_metrics();
        return $this->get_success_response($result);
    }

    /**
     * Test gateway connectivity
     *
     * @param WP_REST_Request $request Request object.
     *
     * @return WP_REST_Response
     */
    public function test_gateway($request)
    {
        $gateway_id = $request->get_param('gateway_id');
        $result     = $this->diagnostics->test_gateway_connectivity($gateway_id);

        if ($result['success']) {
            return $this->get_success_response($result);
        } else {
            return $this->get_error_response($result['message'], 400);
        }
    }

    /**
     * Clean orphaned records
     *
     * @return WP_REST_Response
     */
    public function clean_orphaned()
    {
        $result = $this->diagnostics->clean_orphaned_records();
        return $this->get_success_response($result);
    }

    /**
     * Archive old transactions
     *
     * @param WP_REST_Request $request Request object.
     *
     * @return WP_REST_Response
     */
    public function archive_transactions($request)
    {
        $days   = $request->get_param('days');
        $result = $this->diagnostics->archive_old_transactions($days);
        return $this->get_success_response($result);
    }

    /**
     * Get failure scenarios
     *
     * @return WP_REST_Response
     */
    public function get_failure_scenarios()
    {
        $scenarios = $this->simulator->get_all_scenarios();
        return $this->get_success_response($scenarios);
    }

    /**
     * Get available payment gateways
     *
     * @return WP_REST_Response
     */
    public function get_available_gateways()
    {
        $gateways = $this->simulator->get_available_gateways();
        return $this->get_success_response($gateways);
    }

    /**
     * Simulate a payment failure
     *
     * @param WP_REST_Request $request Request object.
     *
     * @return WP_REST_Response
     */
    public function simulate_failure($request)
    {
        $scenario   = $request->get_param('scenario');
        $gateway_id = $request->get_param('gateway_id');
        $count      = $request->get_param('count');

        if ($count > 1) {
            // Bulk simulation
            $result = $this->simulator->generate_bulk_failures($count, $gateway_id, [$scenario]);
        } else {
            // Single simulation
            $result = $this->simulator->create_test_order_with_failure($scenario, $gateway_id);
        }

        if (isset($result['success']) && $result['success']) {
            return $this->get_success_response($result);
        } elseif (isset($result['success']) && !$result['success']) {
            return $this->get_error_response($result['message'], 400);
        } else {
            // Bulk result
            return $this->get_success_response($result);
        }
    }

    /**
     * Get simulation statistics
     *
     * @return WP_REST_Response
     */
    public function get_simulation_stats()
    {
        $stats = $this->simulator->get_simulation_stats();
        return $this->get_success_response($stats);
    }

    /**
     * Clear simulated failures
     *
     * @return WP_REST_Response
     */
    public function clear_simulated_failures()
    {
        $result = $this->simulator->clear_simulated_failures();
        return $this->get_success_response($result);
    }
}
