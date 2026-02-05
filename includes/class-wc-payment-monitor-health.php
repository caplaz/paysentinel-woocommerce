<?php

/**
 * Health calculation engine class
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WC_Payment_Monitor_Health {

	/**
	 * Database instance
	 */
	private $database;

	/**
	 * Logger instance
	 */
	private $logger;

	/**
	 * Health calculation periods in seconds
	 */
	public const PERIODS = array(
		'1hour'  => 3600,
		'24hour' => 86400,
		'7day'   => 604800,
	);

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->database = new WC_Payment_Monitor_Database();
		$this->logger   = new WC_Payment_Monitor_Logger();
		$this->init_hooks();
	}

	/**
	 * Initialize WordPress hooks
	 */
	private function init_hooks() {
		// Schedule health calculation cron job
		add_action( 'init', array( $this, 'schedule_health_calculation' ) );
		add_action( 'wc_payment_monitor_health_calculation', array( $this, 'calculate_all_gateway_health' ) );

		// Hook into plugin activation to schedule cron
		add_action( 'wc_payment_monitor_activated', array( $this, 'schedule_health_calculation' ) );
	}

	/**
	 * Schedule health calculation cron job
	 */
	public function schedule_health_calculation() {
		if ( ! wp_next_scheduled( 'wc_payment_monitor_health_calculation' ) ) {
			// Get monitoring interval from settings (default 5 minutes)
			$settings = get_option( 'wc_payment_monitor_settings', array() );
			$interval = isset( $settings['monitoring_interval'] ) ? $settings['monitoring_interval'] : 300;

			wp_schedule_event( time(), 'wc_payment_monitor_interval', 'wc_payment_monitor_health_calculation' );
		}

		// Add custom cron interval if not exists
		add_filter( 'cron_schedules', array( $this, 'add_cron_interval' ) );
	}

	/**
	 * Add custom cron interval
	 *
	 * @param array $schedules Existing schedules
	 *
	 * @return array Modified schedules
	 */
	public function add_cron_interval( $schedules ) {
		$settings = get_option( 'wc_payment_monitor_settings', array() );
		$interval = isset( $settings['monitoring_interval'] ) ? $settings['monitoring_interval'] : 300;

		$schedules['wc_payment_monitor_interval'] = array(
			'interval' => $interval,
			'display'  => sprintf( __( 'Every %d minutes', 'wc-payment-monitor' ), $interval / 60 ),
		);

		return $schedules;
	}

	/**
	 * Calculate health for all active gateways
	 */
	public function calculate_all_gateway_health() {
		$active_gateways = $this->get_active_gateways();

		foreach ( $active_gateways as $gateway_id ) {
			$this->calculate_health( $gateway_id );
		}
	}

	/**
	 * Calculate health metrics for a specific gateway
	 *
	 * @param string $gateway_id Gateway ID
	 *
	 * @return array Health data for all periods
	 */
	public function calculate_health( $gateway_id ) {
		$health_data = array();

		foreach ( self::PERIODS as $period => $seconds ) {
			$period_health          = $this->calculate_period_health( $gateway_id, $period, $seconds );
			$health_data[ $period ] = $period_health;

			// Store health data in database
			$this->store_health_data( $gateway_id, $period, $period_health );
		}

		// Trigger alert checking for this gateway
		do_action( 'wc_payment_monitor_gateway_health_calculated', $gateway_id, $health_data );

		return $health_data;
	}

	/**
	 * Calculate health metrics for a specific period
	 *
	 * @param string $gateway_id Gateway ID
	 * @param string $period     Period name (1hour, 24hour, 7day)
	 * @param int    $seconds    Period in seconds
	 *
	 * @return array Health metrics
	 */
	public function calculate_period_health( $gateway_id, $period, $seconds ) {
		// Get transaction statistics for the period
		$stats = $this->logger->get_transaction_stats( $gateway_id, $seconds );

		// Calculate additional metrics
		$health_data = array(
			'gateway_id'              => $gateway_id,
			'period'                  => $period,
			'total_transactions'      => intval( $stats['total_transactions'] ),
			'successful_transactions' => intval( $stats['successful_transactions'] ),
			'failed_transactions'     => intval( $stats['failed_transactions'] ),
			'success_rate'            => floatval( $stats['success_rate'] ),
			'avg_response_time'       => null, // Will be implemented in future versions
			'last_failure_at'         => $this->get_last_failure_time( $gateway_id, $seconds ),
			'calculated_at'           => current_time( 'mysql' ),
		);

		return $health_data;
	}

	/**
	 * Store health data in database
	 *
	 * @param string $gateway_id  Gateway ID
	 * @param string $period      Period name
	 * @param array  $health_data Health metrics
	 *
	 * @return bool Success
	 */
	private function store_health_data( $gateway_id, $period, $health_data ) {
		global $wpdb;

		$table_name = $this->database->get_gateway_health_table();

		// Check if record exists for this gateway and period
		$existing_record = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id FROM {$table_name} WHERE gateway_id = %s AND period = %s",
				$gateway_id,
				$period
			)
		);

		$data = array(
			'gateway_id'              => $health_data['gateway_id'],
			'period'                  => $health_data['period'],
			'total_transactions'      => $health_data['total_transactions'],
			'successful_transactions' => $health_data['successful_transactions'],
			'failed_transactions'     => $health_data['failed_transactions'],
			'success_rate'            => $health_data['success_rate'],
			'avg_response_time'       => $health_data['avg_response_time'],
			'last_failure_at'         => $health_data['last_failure_at'],
			'calculated_at'           => $health_data['calculated_at'],
		);

		$format = array(
			'%s', // gateway_id
			'%s', // period
			'%d', // total_transactions
			'%d', // successful_transactions
			'%d', // failed_transactions
			'%f', // success_rate
			'%d', // avg_response_time
			'%s', // last_failure_at
			'%s',  // calculated_at
		);

		if ( $existing_record ) {
			// Update existing record
			$result = $wpdb->update(
				$table_name,
				$data,
				array( 'id' => $existing_record->id ),
				$format,
				array( '%d' )
			);
		} else {
			// Insert new record
			$result = $wpdb->insert( $table_name, $data, $format );
		}

		return $result !== false;
	}

	/**
	 * Get health status for a specific gateway and period
	 *
	 * @param string $gateway_id Gateway ID
	 * @param string $period     Period name (1hour, 24hour, 7day)
	 *
	 * @return object|null Health data
	 */
	public function get_health_status( $gateway_id, $period ) {
		global $wpdb;

		$table_name = $this->database->get_gateway_health_table();

		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table_name} WHERE gateway_id = %s AND period = %s",
				$gateway_id,
				$period
			)
		);
	}

	/**
	 * Get health status for all periods of a gateway
	 *
	 * @param string $gateway_id Gateway ID
	 *
	 * @return array Health data for all periods
	 */
	public function get_gateway_health( $gateway_id ) {
		global $wpdb;

		$table_name = $this->database->get_gateway_health_table();

		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table_name} WHERE gateway_id = %s ORDER BY period",
				$gateway_id
			),
			ARRAY_A
		);

		// Organize by period
		$health_data = array();
		foreach ( $results as $row ) {
			$health_data[ $row['period'] ] = $row;
		}

		return $health_data;
	}

	/**
	 * Get health status for all gateways
	 *
	 * @param string $period Optional period filter
	 *
	 * @return array Health data
	 */
	public function get_all_gateway_health( $period = null ) {
		global $wpdb;

		$table_name = $this->database->get_gateway_health_table();

		$sql    = "SELECT * FROM {$table_name}";
		$params = array();

		if ( $period ) {
			$sql     .= ' WHERE period = %s';
			$params[] = $period;
		}

		$sql .= ' ORDER BY gateway_id, period';

		if ( ! empty( $params ) ) {
			return $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
		} else {
			return $wpdb->get_results( $sql, ARRAY_A );
		}
	}

	/**
	 * Check if gateway is degraded based on threshold
	 *
	 * @param string $gateway_id Gateway ID
	 * @param string $period     Period to check (default: 24hour)
	 *
	 * @return bool True if degraded
	 */
	public function is_gateway_degraded( $gateway_id, $period = '24hour' ) {
		$health_status = $this->get_health_status( $gateway_id, $period );

		if ( ! $health_status ) {
			return false;
		}

		$settings  = get_option( 'wc_payment_monitor_settings', array() );
		$threshold = isset( $settings['alert_threshold'] ) ? $settings['alert_threshold'] : 85;

		return $health_status->success_rate < $threshold;
	}

	/**
	 * Get gateway status (healthy, degraded, critical)
	 *
	 * @param string $gateway_id Gateway ID
	 * @param string $period     Period to check (default: 24hour)
	 *
	 * @return string Status
	 */
	public function get_gateway_status( $gateway_id, $period = '24hour' ) {
		$health_status = $this->get_health_status( $gateway_id, $period );

		if ( ! $health_status || $health_status->total_transactions == 0 ) {
			return 'unknown';
		}

		$success_rate = $health_status->success_rate;

		if ( $success_rate < 70 ) {
			return 'critical';
		} elseif ( $success_rate < 85 ) {
			return 'degraded';
		} else {
			return 'healthy';
		}
	}

	/**
	 * Get last failure time for a gateway within a period
	 *
	 * @param string $gateway_id     Gateway ID
	 * @param int    $period_seconds Period in seconds
	 *
	 * @return string|null Last failure timestamp
	 */
	private function get_last_failure_time( $gateway_id, $period_seconds ) {
		global $wpdb;

		$table_name = $this->database->get_transactions_table();
		$start_time = date( 'Y-m-d H:i:s', time() - $period_seconds );

		$last_failure = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT created_at FROM {$table_name} 
             WHERE gateway_id = %s AND status = 'failed' AND created_at >= %s 
             ORDER BY created_at DESC LIMIT 1",
				$gateway_id,
				$start_time
			)
		);

		return $last_failure;
	}

	/**
	 * Get active payment gateways
	 *
	 * @return array Gateway IDs
	 */
	private function get_active_gateways() {
		$gateways = array();

		// Get enabled gateways from settings
		$settings         = get_option( 'wc_payment_monitor_settings', array() );
		$enabled_gateways = isset( $settings['enabled_gateways'] ) ? $settings['enabled_gateways'] : array();

		if ( ! empty( $enabled_gateways ) ) {
			return $enabled_gateways;
		}

		// If no specific gateways configured, get all WooCommerce gateways
		if ( class_exists( 'WC_Payment_Gateways' ) ) {
			$wc_gateways        = WC_Payment_Gateways::instance();
			$available_gateways = $wc_gateways->get_available_payment_gateways();

			foreach ( $available_gateways as $gateway_id => $gateway ) {
				if ( $gateway->enabled === 'yes' ) {
					$gateways[] = $gateway_id;
				}
			}
		}

		return $gateways;
	}

	/**
	 * Get historical health data for trending
	 *
	 * @param string $gateway_id Gateway ID
	 * @param string $period     Period name
	 * @param int    $days       Number of days to retrieve (default: 30)
	 *
	 * @return array Historical health data
	 */
	public function get_health_history( $gateway_id, $period, $days = 30 ) {
		global $wpdb;

		$table_name = $this->database->get_gateway_health_table();
		$start_date = date( 'Y-m-d H:i:s', time() - ( $days * 86400 ) );

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table_name} 
             WHERE gateway_id = %s AND period = %s AND calculated_at >= %s 
             ORDER BY calculated_at ASC",
				$gateway_id,
				$period,
				$start_date
			),
			ARRAY_A
		);
	}

	/**
	 * Clear old health data
	 *
	 * @param int $days Keep data newer than this many days (default: 30)
	 *
	 * @return int Number of records deleted
	 */
	public function cleanup_old_health_data( $days = 30 ) {
		global $wpdb;

		$table_name  = $this->database->get_gateway_health_table();
		$cutoff_date = date( 'Y-m-d H:i:s', time() - ( $days * 86400 ) );

		return $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table_name} WHERE calculated_at < %s",
				$cutoff_date
			)
		);
	}

	/**
	 * Force health calculation for a specific gateway
	 *
	 * @param string $gateway_id Gateway ID
	 *
	 * @return array Health data
	 */
	public function force_calculate_health( $gateway_id ) {
		return $this->calculate_health( $gateway_id );
	}

	/**
	 * Get summary statistics across all gateways
	 *
	 * @param string $period Period to analyze (default: 24hour)
	 *
	 * @return array Summary statistics
	 */
	public function get_summary_stats( $period = '24hour' ) {
		global $wpdb;

		$table_name = $this->database->get_gateway_health_table();

		$stats = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT 
                COUNT(*) as total_gateways,
                AVG(success_rate) as avg_success_rate,
                MIN(success_rate) as min_success_rate,
                MAX(success_rate) as max_success_rate,
                SUM(total_transactions) as total_transactions,
                SUM(successful_transactions) as total_successful,
                SUM(failed_transactions) as total_failed
             FROM {$table_name} 
             WHERE period = %s",
				$period
			),
			ARRAY_A
		);

		// Calculate overall success rate
		if ( $stats['total_transactions'] > 0 ) {
			$stats['overall_success_rate'] = round( ( $stats['total_successful'] / $stats['total_transactions'] ) * 100, 2 );
		} else {
			$stats['overall_success_rate'] = 0.00;
		}

		return $stats;
	}
}
