<?php
/**
 * Alert Checker Class
 *
 * Handles health checking and alert triggering logic for payment gateways.
 *
 * @package WC_Payment_Monitor
 * @since 1.0.0
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WC_Payment_Monitor_Alert_Checker class
 *
 * Responsible for checking gateway health, determining when alerts should be triggered,
 * and managing alert resolution.
 */
class WC_Payment_Monitor_Alert_Checker {

	/**
	 * Database instance
	 *
	 * @var WC_Payment_Monitor_Database
	 */
	private $database;

	/**
	 * Health instance
	 *
	 * @var WC_Payment_Monitor_Health
	 */
	private $health;

	/**
	 * Gateway manager instance
	 *
	 * @var WC_Payment_Monitor_Gateway_Manager
	 */
	private $gateway_manager;

	/**
	 * Alert notifier instance
	 *
	 * @var WC_Payment_Monitor_Alert_Notifier
	 */
	private $notifier;

	/**
	 * Config instance
	 *
	 * @var WC_Payment_Monitor_Config
	 */
	private $config;

	/**
	 * Alert severity thresholds
	 */
	public const SEVERITY_THRESHOLDS = array(
		'high'    => 75,
		'warning' => 90,
		'info'    => 95,
	);

	/**
	 * Rate limiting window in seconds (1 hour)
	 */
	public const RATE_LIMIT_WINDOW = 3600;

	/**
	 * Constructor
	 *
	 * @param WC_Payment_Monitor_Database        $database        Database instance.
	 * @param WC_Payment_Monitor_Health          $health          Health instance.
	 * @param WC_Payment_Monitor_Gateway_Manager $gateway_manager Gateway manager instance.
	 * @param WC_Payment_Monitor_Alert_Notifier  $notifier        Alert notifier instance.
	 */
	public function __construct( $database, $health, $gateway_manager, $notifier ) {
		$this->database        = $database;
		$this->health          = $health;
		$this->gateway_manager = $gateway_manager;
		$this->notifier        = $notifier;
		$this->config          = WC_Payment_Monitor_Config::instance();
	}

	/**
	 * Check alerts for all gateways
	 */
	public function check_all_gateway_alerts() {
		$active_gateways = $this->gateway_manager->get_active_gateways();

		foreach ( $active_gateways as $gateway_id ) {
			$health_data = $this->health->get_gateway_health( $gateway_id );
			$this->check_and_send( $gateway_id, $health_data );
		}
	}

	/**
	 * Check alerts for a specific gateway
	 *
	 * @param string $gateway_id  Gateway ID.
	 * @param array  $health_data Health data for all periods.
	 */
	public function check_gateway_alerts( $gateway_id, $health_data ) {
		$this->check_and_send( $gateway_id, $health_data );
	}

	/**
	 * Check gateway connectivity status and alert if down
	 *
	 * @param string $gateway_id Gateway ID.
	 * @param array  $status     Status array from connectivity check.
	 */
	public function check_gateway_connectivity_alert( $gateway_id, $status ) {
		// Only alert if status is 'offline'
		if ( ! isset( $status['status'] ) || 'offline' !== $status['status'] ) {
			// If it's online, resolve any open 'gateway_down' alerts
			$this->resolve_alerts( $gateway_id, 'gateway_down' );
			return;
		}

		// Check if already rate limited
		if ( $this->is_rate_limited( $gateway_id, 'gateway_down' ) ) {
			return;
		}

		// Create connectivity alert
		$alert_data = array(
			'gateway_id'   => $gateway_id,
			'alert_type'   => 'gateway_down',
			'severity'     => 'critical',
			'success_rate' => 0,
			'period'       => 'current',
			'message'      => sprintf(
				__( 'Payment gateway %s is currently unavailable. Customers cannot complete payments.', 'wc-payment-monitor' ),
				$this->gateway_manager->get_gateway_display_name( $gateway_id )
			),
			'metadata'     => array(
				'error'      => isset( $status['error'] ) ? $status['error'] : '',
				'checked_at' => current_time( 'mysql' ),
			),
		);

		$this->trigger_alert( $alert_data );
	}

	/**
	 * Check for immediate transaction alert on payment failure
	 *
	 * @param int      $order_id Order ID.
	 * @param WC_Order $order    Order object.
	 */
	public function check_immediate_transaction_alert( $order_id, $order ) {
		$payment_method = $order->get_payment_method();

		if ( empty( $payment_method ) ) {
			return;
		}

		// Check if immediate alerts are enabled for this gateway
		$settings = $this->config->get_all_settings();
		if ( ! $this->is_gateway_alerts_enabled( $payment_method, $settings ) ) {
			return;
		}

		// Check if immediate transaction alerts are enabled
		$immediate_alerts_enabled = isset( $settings['immediate_transaction_alerts'] )
			? (bool) $settings['immediate_transaction_alerts']
			: false;

		if ( ! $immediate_alerts_enabled ) {
			return;
		}

		// Check rate limiting (use separate type to not interfere with health alerts)
		if ( $this->is_rate_limited( $payment_method, 'immediate_transaction' ) ) {
			return;
		}

		// Get order details for context
		$alert_data = array(
			'gateway_id'   => $payment_method,
			'alert_type'   => 'immediate_transaction',
			'severity'     => 'high',
			'success_rate' => null,
			'period'       => 'immediate',
			'message'      => sprintf(
				__( 'Payment failed for order #%d using %s gateway.', 'wc-payment-monitor' ),
				$order_id,
				$this->gateway_manager->get_gateway_display_name( $payment_method )
			),
			'metadata'     => array(
				'order_id'     => $order_id,
				'order_total'  => $order->get_total(),
				'customer'     => $order->get_billing_email(),
				'failure_time' => current_time( 'mysql' ),
			),
		);

		$this->trigger_alert( $alert_data );
	}

	/**
	 * Check health data and send alerts if needed
	 *
	 * @param string $gateway_id  Gateway ID.
	 * @param array  $health_data Health data for all periods.
	 */
	public function check_and_send( $gateway_id, $health_data ) {
		$settings = $this->config->get_all_settings();

		// Check if alerts are enabled for this gateway
		if ( ! $this->is_gateway_alerts_enabled( $gateway_id, $settings ) ) {
			return;
		}

		// Check each period that has health data
		foreach ( $health_data as $period => $data ) {
			if ( ! isset( $data['success_rate'] ) || ! isset( $data['total_transactions'] ) ) {
				continue;
			}

			$success_rate       = $data['success_rate'];
			$total_transactions = $data['total_transactions'];

			// Calculate severity
			$severity = $this->calculate_severity( $success_rate, $total_transactions );

			// Check if we should trigger an alert
			if ( $this->should_trigger_alert( $gateway_id, $success_rate, $severity ) ) {
				$alert_data = array(
					'gateway_id'        => $gateway_id,
					'alert_type'        => 'health',
					'severity'          => $severity,
					'success_rate'      => $success_rate,
					'period'            => $period,
					'total_transactions' => $total_transactions,
					'failed_transactions' => $data['failed_transactions'] ?? 0,
				);

				$this->trigger_alert( $alert_data );
			} else {
				// Check if we should resolve any existing alerts
				$this->check_alert_resolution( $gateway_id, $success_rate );
			}
		}
	}

	/**
	 * Calculate alert severity based on success rate and transaction volume
	 *
	 * @param float $success_rate       Success rate (0-100).
	 * @param int   $total_transactions Total number of transactions.
	 * @return string Severity level: 'critical', 'high', 'warning', or 'info'.
	 */
	private function calculate_severity( $success_rate, $total_transactions = 0 ) {
		// Critical: Very low success rate or complete failure with transactions
		if ( $success_rate < self::SEVERITY_THRESHOLDS['high'] ) {
			return 'critical';
		}

		// High: Success rate below 90%
		if ( $success_rate < self::SEVERITY_THRESHOLDS['warning'] ) {
			return 'high';
		}

		// Warning: Success rate below 95%
		if ( $success_rate < self::SEVERITY_THRESHOLDS['info'] ) {
			return 'warning';
		}

		// Info: Success rate below 100% but above 95%
		if ( $success_rate < 100 ) {
			return 'info';
		}

		return 'info';
	}

	/**
	 * Determine if an alert should be triggered
	 *
	 * @param string $gateway_id   Gateway ID.
	 * @param float  $success_rate Success rate.
	 * @param string $severity     Alert severity.
	 * @return bool True if alert should be triggered.
	 */
	private function should_trigger_alert( $gateway_id, $success_rate, $severity ) {
		$settings = $this->config->get_all_settings();

		// Get threshold for this gateway
		$threshold = $this->get_gateway_alert_threshold( $gateway_id, $settings );

		// Don't alert if success rate is above threshold
		if ( $success_rate >= $threshold ) {
			return false;
		}

		// Check rate limiting
		if ( $this->is_rate_limited( $gateway_id, 'health' ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Get alert threshold for a specific gateway
	 *
	 * @param string $gateway_id Gateway ID.
	 * @param array  $settings   Plugin settings.
	 * @return float Threshold percentage (0-100).
	 */
	private function get_gateway_alert_threshold( $gateway_id, $settings ) {
		// Check for gateway-specific threshold
		$gateway_thresholds = isset( $settings['gateway_thresholds'] ) ? $settings['gateway_thresholds'] : array();

		if ( isset( $gateway_thresholds[ $gateway_id ] ) ) {
			return (float) $gateway_thresholds[ $gateway_id ];
		}

		// Return default threshold
		return isset( $settings['alert_threshold'] ) ? (float) $settings['alert_threshold'] : 95.0;
	}

	/**
	 * Check if alerts are enabled for a specific gateway
	 *
	 * @param string $gateway_id Gateway ID.
	 * @param array  $settings   Plugin settings.
	 * @return bool True if alerts are enabled.
	 */
	private function is_gateway_alerts_enabled( $gateway_id, $settings ) {
		// Check global alerts enabled
		$alerts_enabled = isset( $settings['alerts_enabled'] ) ? (bool) $settings['alerts_enabled'] : false;

		if ( ! $alerts_enabled ) {
			return false;
		}

		// Check gateway-specific setting
		$gateway_alerts = isset( $settings['gateway_alerts'] ) ? $settings['gateway_alerts'] : array();

		// If gateway_alerts is not set or empty, assume all gateways are enabled
		if ( empty( $gateway_alerts ) ) {
			return true;
		}

		return isset( $gateway_alerts[ $gateway_id ] ) ? (bool) $gateway_alerts[ $gateway_id ] : false;
	}

	/**
	 * Check if alert is rate limited
	 *
	 * @param string $gateway_id Gateway ID.
	 * @param string $alert_type Alert type.
	 * @return bool True if rate limited.
	 */
	public function is_rate_limited( $gateway_id, $alert_type ) {
		global $wpdb;

		$table_name = $wpdb->prefix . 'wc_payment_monitor_alerts';
		$time_limit = date( 'Y-m-d H:i:s', time() - self::RATE_LIMIT_WINDOW );

		$recent_alert = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table_name} 
				WHERE gateway_id = %s 
				AND alert_type = %s 
				AND created_at > %s 
				ORDER BY created_at DESC 
				LIMIT 1",
				$gateway_id,
				$alert_type,
				$time_limit
			)
		);

		return ! empty( $recent_alert );
	}

	/**
	 * Trigger an alert and send notifications
	 *
	 * @param array $alert_data Alert data array.
	 * @return int|false Alert ID on success, false on failure.
	 */
	public function trigger_alert( $alert_data ) {
		// Save alert to database
		$alert_record = array(
			'gateway_id'   => $alert_data['gateway_id'],
			'alert_type'   => $alert_data['alert_type'],
			'severity'     => $alert_data['severity'],
			'success_rate' => $alert_data['success_rate'] ?? null,
			'period'       => $alert_data['period'] ?? 'unknown',
			'message'      => $alert_data['message'] ?? '',
			'metadata'     => isset( $alert_data['metadata'] ) ? wp_json_encode( $alert_data['metadata'] ) : null,
			'created_at'   => current_time( 'mysql' ),
			'is_resolved'  => 0,
		);

		$alert_id = $this->save_alert( $alert_record );

		if ( ! $alert_id ) {
			return false;
		}

		// Send notifications
		$this->notifier->send_notifications( $alert_data, $alert_id );

		// Fire action hook for extensibility
		do_action( 'wc_payment_monitor_alert_triggered', $alert_id, $alert_data );

		return $alert_id;
	}

	/**
	 * Check if alerts should be resolved based on current success rate
	 *
	 * @param string $gateway_id   Gateway ID.
	 * @param float  $success_rate Current success rate.
	 */
	private function check_alert_resolution( $gateway_id, $success_rate ) {
		$settings  = $this->config->get_all_settings();
		$threshold = $this->get_gateway_alert_threshold( $gateway_id, $settings );

		// If success rate is back above threshold, resolve alerts
		if ( $success_rate >= $threshold ) {
			$this->resolve_alerts( $gateway_id, 'health' );
		}
	}

	/**
	 * Resolve open alerts for a gateway
	 *
	 * @param string $gateway_id Gateway ID.
	 * @param string $alert_type Alert type to resolve.
	 * @return int Number of alerts resolved.
	 */
	public function resolve_alerts( $gateway_id, $alert_type ) {
		global $wpdb;

		$table_name = $wpdb->prefix . 'wc_payment_monitor_alerts';

		$updated = $wpdb->update(
			$table_name,
			array(
				'is_resolved' => 1,
				'resolved_at' => current_time( 'mysql' ),
			),
			array(
				'gateway_id'  => $gateway_id,
				'alert_type'  => $alert_type,
				'is_resolved' => 0,
			),
			array( '%d', '%s' ),
			array( '%s', '%s', '%d' )
		);

		if ( $updated ) {
			// Fire action hook for extensibility
			do_action( 'wc_payment_monitor_alerts_resolved', $gateway_id, $alert_type, $updated );
		}

		return $updated;
	}

	/**
	 * Save alert to database
	 *
	 * @param array $alert_record Alert record data.
	 * @return int|false Alert ID on success, false on failure.
	 */
	private function save_alert( $alert_record ) {
		global $wpdb;

		$table_name = $wpdb->prefix . 'wc_payment_monitor_alerts';

		$inserted = $wpdb->insert(
			$table_name,
			$alert_record,
			array(
				'%s', // gateway_id
				'%s', // alert_type
				'%s', // severity
				'%f', // success_rate
				'%s', // period
				'%s', // message
				'%s', // metadata
				'%s', // created_at
				'%d', // is_resolved
			)
		);

		if ( ! $inserted ) {
			return false;
		}

		return $wpdb->insert_id;
	}
}
