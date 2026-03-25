<?php
/**
 * Alert Checker Class
 *
 * Handles health checking and alert triggering logic for payment gateways.
 *
 * @package PaySentinel
 * @since 1.0.0
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * PaySentinel_Alert_Checker class
 *
 * Responsible for checking gateway health, determining when alerts should be triggered,
 * and managing alert resolution.
 */
class PaySentinel_Alert_Checker {



	/**
	 * Database instance
	 *
	 * @var PaySentinel_Database
	 */
	private $database;

	/**
	 * Health instance
	 *
	 * @var PaySentinel_Health
	 */
	private $health;

	/**
	 * Gateway manager instance
	 *
	 * @var PaySentinel_Gateway_Manager
	 */
	private $gateway_manager;

	/**
	 * Alert notifier instance
	 *
	 * @var PaySentinel_Alert_Notifier
	 */
	private $notifier;

	/**
	 * Config instance
	 *
	 * @var PaySentinel_Config
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
	 * @param PaySentinel_Database        $database        Database instance.
	 * @param PaySentinel_Health          $health          Health instance.
	 * @param PaySentinel_Gateway_Manager $gateway_manager Gateway manager instance.
	 * @param PaySentinel_Alert_Notifier  $notifier        Alert notifier instance.
	 */
	public function __construct( $database, $health, $gateway_manager, $notifier ) {
		$this->database        = $database;
		$this->health          = $health;
		$this->gateway_manager = $gateway_manager;
		$this->notifier        = $notifier;
		$this->config          = PaySentinel_Config::instance();
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
			'gateway_id' => $gateway_id,
			'alert_type' => 'gateway_down',
			'severity'   => 'critical',
			'period'     => 'current',
			'message'    => sprintf(
				/* translators: %s: gateway display name */
				__( 'Payment gateway %s is currently unavailable. Customers cannot complete payments.', 'paysentinel' ),
				$this->gateway_manager->get_gateway_display_name( $gateway_id )
			),
			'metadata'   => array(
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
		$settings = $this->config->get_all();
		if ( ! $this->is_gateway_alerts_enabled( $payment_method, $settings ) ) {
			return;
		}

		// Check if immediate transaction alerts are enabled
		$immediate_alerts_enabled = isset( $settings[ PaySentinel_Settings_Constants::IMMEDIATE_TRANSACTION_ALERTS ] )
			? (bool) $settings[ PaySentinel_Settings_Constants::IMMEDIATE_TRANSACTION_ALERTS ]
			: false;

		if ( ! $immediate_alerts_enabled ) {
			return;
		}

		// Check rate limiting (use separate type to not interfere with health alerts)
		if ( $this->is_rate_limited( $payment_method, 'immediate_transaction' ) ) {
			return;
		}

		// Check if this is a soft error that shouldn't trigger alerts
		$latest_transaction = $this->database->get_latest_transaction_for_order( $order_id );
		if ( $latest_transaction && $this->is_soft_error( $latest_transaction ) ) {
			return;
		}

		// Get order details for context
		$alert_data = array(
			'gateway_id'   => $payment_method,
			'alert_type'   => 'gateway_error',
			'severity'     => 'critical',
			'success_rate' => null,
			'period'       => 'immediate',
			'message'      => sprintf(
				/* translators: 1: order ID, 2: gateway display name */
				__( 'Payment failed for order #%1$d using %2$s gateway.', 'paysentinel' ),
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
		$settings = $this->config->get_all();

		// Check if alerts are enabled for this gateway
		if ( ! $this->is_gateway_alerts_enabled( $gateway_id, $settings ) ) {
			return;
		}

		// Get the first transaction date for this gateway to prevent premature alerts
		// for large time windows when the gateway was just recently added.
		$first_tx_date       = $this->database->get_first_transaction_date_for_gateway( $gateway_id );
		$gateway_age_seconds = $first_tx_date ? ( current_time( 'timestamp' ) - strtotime( $first_tx_date ) ) : 0;
		$period_seconds_map  = PaySentinel_Health::PERIODS;

		// Check each period that has health data
		foreach ( $health_data as $period => $data ) {
			// Skip larger alert periods if the gateway hasn't been active for that duration yet.
			// Always allow the 1hour (shortest) period to ensure we can alert immediately on failure.
			if ( '1hour' !== $period && isset( $period_seconds_map[ $period ] ) && $gateway_age_seconds < $period_seconds_map[ $period ] ) {
				continue;
			}

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
					'gateway_id'          => $gateway_id,
					'alert_type'          => 'low_success_rate',
					'severity'            => $severity,
					'success_rate'        => $success_rate,
					'period'              => $period,
					'total_transactions'  => $total_transactions,
					'failed_transactions' => $data['failed_transactions'] ?? 0,
					'message'             => sprintf(
						/* translators: 1: gateway name, 2: success rate percentage, 3: time period, 4: successful count, 5: total count */
						__( 'Payment gateway "%1$s" success rate has dropped to %2$s%% in the last %3$s. Only %4$d out of %5$d transactions succeeded.', 'paysentinel' ),
						$this->gateway_manager->get_gateway_display_name( $gateway_id ),
						number_format( $success_rate, 2 ),
						$period,
						$total_transactions - ( $data['failed_transactions'] ?? 0 ),
						$total_transactions
					),
					'metadata'            => array(
						'success_rate'        => $success_rate,
						'total_transactions'  => $total_transactions,
						'failed_transactions' => $data['failed_transactions'] ?? 0,
						'period'              => $period,
					),
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
		// Volume awareness: For very low transaction counts, reduce severity
		// 1 transaction: even 0% success is just 'info' (not enough data)
		// 2-5 transactions: 0% success is 'warning'
		// 6+ transactions: use normal severity thresholds
		if ( $total_transactions <= 1 ) {
			return $success_rate < 100 ? 'info' : 'info';
		} elseif ( $total_transactions <= 5 ) {
			if ( $success_rate < self::SEVERITY_THRESHOLDS['high'] ) {
				return 'warning';
			}
			if ( $success_rate < self::SEVERITY_THRESHOLDS['warning'] ) {
				return 'warning';
			}
			if ( $success_rate < self::SEVERITY_THRESHOLDS['info'] ) {
				return 'info';
			}
			return 'info';
		}

		// Normal severity calculation for higher volumes
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
		$settings = $this->config->get_all();

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
		// Check for gateway-specific threshold in per-gateway config (Agency feature)
		if ( $this->get_license_tier() === 'agency' && $this->has_feature( 'per_gateway_config' ) ) {
			$gateway_config = isset( $settings[ PaySentinel_Settings_Constants::GATEWAY_ALERT_CONFIG ] ) ? $settings[ PaySentinel_Settings_Constants::GATEWAY_ALERT_CONFIG ] : array();

			if ( isset( $gateway_config[ $gateway_id ][ PaySentinel_Settings_Constants::GATEWAY_CONFIG_THRESHOLD ] ) && ! empty( $gateway_config[ $gateway_id ][ PaySentinel_Settings_Constants::GATEWAY_CONFIG_THRESHOLD ] ) ) {
				return (float) $gateway_config[ $gateway_id ][ PaySentinel_Settings_Constants::GATEWAY_CONFIG_THRESHOLD ];
			}
		}

		// Return default threshold
		return isset( $settings[ PaySentinel_Settings_Constants::ALERT_THRESHOLD ] ) ? (float) $settings[ PaySentinel_Settings_Constants::ALERT_THRESHOLD ] : 95.0;
	}

	/**
	 * Check if alerts are enabled for a specific gateway
	 *
	 * @param string $gateway_id Gateway ID.
	 * @param array  $settings   Plugin settings.
	 * @return bool True if alerts are enabled.
	 */
	private function is_gateway_alerts_enabled( $gateway_id, $settings ) {
		// Check per-gateway configuration first (Agency feature)
		if ( $this->get_license_tier() === 'agency' && $this->has_feature( 'per_gateway_config' ) ) {
			$gateway_config = isset( $settings[ PaySentinel_Settings_Constants::GATEWAY_ALERT_CONFIG ] ) ? $settings[ PaySentinel_Settings_Constants::GATEWAY_ALERT_CONFIG ] : array();

			// If per-gateway config exists for this gateway, check if it's enabled
			if ( isset( $gateway_config[ $gateway_id ] ) ) {
				return isset( $gateway_config[ $gateway_id ][ PaySentinel_Settings_Constants::GATEWAY_CONFIG_ENABLED ] ) ? (bool) $gateway_config[ $gateway_id ][ PaySentinel_Settings_Constants::GATEWAY_CONFIG_ENABLED ] : true;
			}
		}

		// If no per-gateway config, assume alerts are enabled by default
		return true;
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

		$table_name = $this->database->get_alerts_table();
		$time_limit = date_create( current_time( 'mysql' ) )->modify( '-' . self::RATE_LIMIT_WINDOW . ' seconds' )->format( 'Y-m-d H:i:s' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
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
			'gateway_id'  => $alert_data['gateway_id'],
			'alert_type'  => $alert_data['alert_type'],
			'severity'    => $alert_data['severity'],
			'message'     => $alert_data['message'] ?? '',
			'metadata'    => isset( $alert_data['metadata'] ) ? wp_json_encode( $alert_data['metadata'] ) : null,
			'created_at'  => current_time( 'mysql' ),
			'is_resolved' => 0,
		);

		$alert_id = $this->save_alert( $alert_record );

		if ( ! $alert_id ) {
			return false;
		}

		// Send notifications
		$this->notifier->send_notifications( $alert_data, $alert_id );

		// Fire action hook for extensibility
		do_action( 'paysentinel_alert_triggered', $alert_id, $alert_data );

		return $alert_id;
	}

	/**
	 * Check if alerts should be resolved based on current success rate
	 *
	 * @param string $gateway_id   Gateway ID.
	 * @param float  $success_rate Current success rate.
	 */
	private function check_alert_resolution( $gateway_id, $success_rate ) {
		$settings  = $this->config->get_all();
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

		$table_name = $this->database->get_alerts_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
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
			do_action( 'paysentinel_alerts_resolved', $gateway_id, $alert_type, $updated );
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

		$table_name = $this->database->get_alerts_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$inserted = $wpdb->insert(
			$table_name,
			$alert_record,
			array(
				'%s', // gateway_id
				'%s', // alert_type
				'%s', // severity
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

	/**
	 * Check if a transaction represents a soft error that shouldn't trigger alerts
	 *
	 * @param object $transaction Transaction data
	 * @return bool True if this is a soft error
	 */
	private function is_soft_error( $transaction ) {
		// Soft errors are declines that don't indicate gateway issues
		$soft_error_codes    = array( 'decline', 'insufficient_funds', 'card_declined' );
		$soft_error_messages = array( 'insufficient funds', 'soft decline', 'declined' );

		$failure_code   = strtolower( $transaction->failure_code ?? '' );
		$failure_reason = strtolower( $transaction->failure_reason ?? '' );

		// Check failure code
		if ( in_array( $failure_code, $soft_error_codes, true ) ) {
			return true;
		}

		// Check failure reason for soft indicators
		foreach ( $soft_error_messages as $soft_message ) {
			if ( strpos( $failure_reason, $soft_message ) !== false ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Get the current license tier
	 *
	 * @return string License tier
	 */
	private function get_license_tier() {
		$license = new PaySentinel_License();
		return $license->get_license_tier();
	}

	/**
	 * Check if the license has a specific feature
	 *
	 * @param string $feature_name Feature name
	 * @return bool Feature available
	 */
	private function has_feature( $feature_name ) {
		$license = new PaySentinel_License();
		return $license->has_feature( $feature_name );
	}
}
