<?php
/**
 * Alert system class (Refactored)
 *
 * Main orchestrator for the alert system. Delegates specific responsibilities
 * to specialized handler classes.
 *
 * @package WC_Payment_Monitor
 * @since 1.0.0
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WC_Payment_Monitor_Alerts class
 *
 * Orchestrates the alert system by coordinating between checker, notifier,
 * and template manager classes. Also provides database query methods and
 * license validation helpers.
 */
class WC_Payment_Monitor_Alerts {

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
	 * Alert checker instance
	 *
	 * @var WC_Payment_Monitor_Alert_Checker
	 */
	private $checker;

	/**
	 * Alert notifier instance
	 *
	 * @var WC_Payment_Monitor_Alert_Notifier
	 */
	private $notifier;

	/**
	 * Template manager instance
	 *
	 * @var WC_Payment_Monitor_Alert_Template_Manager
	 */
	private $template_manager;

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
	 */
	public function __construct() {
		$this->database        = new WC_Payment_Monitor_Database();
		$this->health          = new WC_Payment_Monitor_Health();
		$this->gateway_manager = new WC_Payment_Monitor_Gateway_Manager();

		// Initialize handler classes
		$this->template_manager = new WC_Payment_Monitor_Alert_Template_Manager( $this->gateway_manager );
		$this->notifier         = new WC_Payment_Monitor_Alert_Notifier( $this->template_manager, $this->database );
		$this->checker          = new WC_Payment_Monitor_Alert_Checker(
			$this->database,
			$this->health,
			$this->gateway_manager,
			$this->notifier
		);

		$this->init_hooks();
	}

	/**
	 * Initialize WordPress hooks
	 */
	private function init_hooks() {
		// Hook into health calculation to check for alerts
		add_action( 'wc_payment_monitor_health_calculation', array( $this->checker, 'check_all_gateway_alerts' ) );

		// Hook into individual health calculations
		add_action( 'wc_payment_monitor_gateway_health_calculated', array( $this->checker, 'check_gateway_alerts' ), 10, 2 );

		// Hook into gateway connectivity checks
		add_action( 'wc_payment_monitor_gateway_checked', array( $this->checker, 'check_gateway_connectivity_alert' ), 10, 2 );

		// Hook into individual payment failures for immediate critical alerts
		add_action( 'wc_payment_monitor_payment_failed', array( $this->checker, 'check_immediate_transaction_alert' ), 10, 2 );
	}

	/**
	 * Check alerts for all gateways
	 *
	 * Delegates to checker class.
	 */
	public function check_all_gateway_alerts() {
		return $this->checker->check_all_gateway_alerts();
	}

	/**
	 * Check alerts for a specific gateway
	 *
	 * Delegates to checker class.
	 *
	 * @param string $gateway_id  Gateway ID.
	 * @param array  $health_data Health data for all periods.
	 */
	public function check_gateway_alerts( $gateway_id, $health_data ) {
		return $this->checker->check_gateway_alerts( $gateway_id, $health_data );
	}

	/**
	 * Check gateway connectivity status and alert if down
	 *
	 * Delegates to checker class.
	 *
	 * @param string $gateway_id Gateway ID.
	 * @param array  $status     Status array from connectivity check.
	 */
	public function check_gateway_connectivity_alert( $gateway_id, $status ) {
		return $this->checker->check_gateway_connectivity_alert( $gateway_id, $status );
	}

	/**
	 * Check for immediate transaction alert on payment failure
	 *
	 * Delegates to checker class.
	 *
	 * @param int      $order_id Order ID.
	 * @param WC_Order $order    Order object.
	 */
	public function check_immediate_transaction_alert( $order_id, $order ) {
		return $this->checker->check_immediate_transaction_alert( $order_id, $order );
	}

	/**
	 * Check if alert is rate limited
	 *
	 * Delegates to checker class.
	 *
	 * @param string $gateway_id Gateway ID.
	 * @param string $alert_type Alert type.
	 * @return bool True if rate limited.
	 */
	public function is_rate_limited( $gateway_id, $alert_type ) {
		return $this->checker->is_rate_limited( $gateway_id, $alert_type );
	}

	/**
	 * Trigger an alert and send notifications
	 *
	 * Delegates to checker class.
	 *
	 * @param array $alert_data Alert data array.
	 * @return int|false Alert ID on success, false on failure.
	 */
	public function trigger_alert( $alert_data ) {
		return $this->checker->trigger_alert( $alert_data );
	}

	/**
	 * Resolve open alerts for a gateway
	 *
	 * Delegates to checker class.
	 *
	 * @param string $gateway_id Gateway ID.
	 * @param string $alert_type Alert type to resolve.
	 * @return int Number of alerts resolved.
	 */
	public function resolve_alerts( $gateway_id, $alert_type ) {
		return $this->checker->resolve_alerts( $gateway_id, $alert_type );
	}

	/**
	 * Get alerts by gateway
	 *
	 * @param string $gateway_id    Gateway ID.
	 * @param int    $limit         Limit results.
	 * @param bool   $resolved_only Show only resolved alerts.
	 * @return array Alert data.
	 */
	public function get_alerts_by_gateway( $gateway_id, $limit = 50, $resolved_only = false ) {
		global $wpdb;

		$table_name = $this->database->get_alerts_table();

		$sql    = "SELECT * FROM {$table_name} WHERE gateway_id = %s";
		$params = array( $gateway_id );

		if ( $resolved_only !== null ) {
			$sql     .= ' AND resolved = %d';
			$params[] = $resolved_only ? 1 : 0;
		}

		$sql     .= ' ORDER BY created_at DESC LIMIT %d';
		$params[] = $limit;

		return $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
	}

	/**
	 * Get recent alerts
	 *
	 * @param int    $limit    Limit results.
	 * @param string $severity Filter by severity.
	 * @return array Alert data.
	 */
	public function get_recent_alerts( $limit = 50, $severity = null ) {
		global $wpdb;

		$table_name = $this->database->get_alerts_table();

		$sql    = "SELECT * FROM {$table_name}";
		$params = array();

		if ( $severity ) {
			$sql     .= ' WHERE severity = %s';
			$params[] = $severity;
		}

		$sql     .= ' ORDER BY created_at DESC LIMIT %d';
		$params[] = $limit;

		if ( empty( $params ) ) {
			return $wpdb->get_results( "SELECT * FROM {$table_name} ORDER BY created_at DESC LIMIT " . intval( $limit ), ARRAY_A );
		}

		return $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
	}

	/**
	 * Get alert statistics
	 *
	 * @param int $days Number of days to analyze (default: 7).
	 * @return array Alert statistics.
	 */
	public function get_alert_stats( $days = 7 ) {
		global $wpdb;

		$table_name = $this->database->get_alerts_table();
		$start_date = gmdate( 'Y-m-d H:i:s', time() - ( $days * 86400 ) );

		$stats = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT 
                COUNT(*) as total_alerts,
                SUM(CASE WHEN severity = 'critical' THEN 1 ELSE 0 END) as critical_alerts,
                SUM(CASE WHEN severity = 'high' THEN 1 ELSE 0 END) as high_alerts,
                SUM(CASE WHEN severity = 'warning' THEN 1 ELSE 0 END) as warning_alerts,
                SUM(CASE WHEN severity = 'info' THEN 1 ELSE 0 END) as info_alerts,
                SUM(CASE WHEN resolved = 1 THEN 1 ELSE 0 END) as resolved_alerts,
                SUM(CASE WHEN resolved = 0 THEN 1 ELSE 0 END) as unresolved_alerts
             FROM {$table_name} 
             WHERE created_at >= %s",
				$start_date
			),
			ARRAY_A
		);

		return $stats;
	}

	/**
	 * Check if premium features are available
	 *
	 * @return bool Premium features available.
	 */
	private function is_premium_feature_available() {
		$license  = new WC_Payment_Monitor_License();
		$is_valid = 'valid' === $license->get_license_status();

		$is_premium = apply_filters(
			'wc_payment_monitor_premium_available',
			$is_valid
		);

		return $is_premium;
	}

	/**
	 * Get license tier from license data
	 *
	 * @return string License tier: free, starter, pro, agency.
	 */
	private function get_license_tier() {
		$license      = new WC_Payment_Monitor_License();
		$license_data = $license->get_license_data();

		if ( ! $license_data || ! isset( $license_data['plan'] ) ) {
			return 'free';
		}

		return strtolower( $license_data['plan'] );
	}

	/**
	 * Check if a specific feature is available in current license tier
	 *
	 * @param string $feature_name Feature name.
	 * @return bool Feature available.
	 */
	private function has_feature( $feature_name ) {
		$license      = new WC_Payment_Monitor_License();
		$license_data = $license->get_license_data();

		if ( ! $license_data || ! isset( $license_data['features'] ) ) {
			return false;
		}

		return isset( $license_data['features'][ $feature_name ] ) && $license_data['features'][ $feature_name ];
	}

	/**
	 * Validate license key
	 *
	 * @param string $license_key License key.
	 * @return array Validation result.
	 */
	public function validate_license( $license_key ) {
		if ( empty( $license_key ) ) {
			return array(
				'valid'   => false,
				'message' => __( 'License key is required', 'wc-payment-monitor' ),
			);
		}

		$license_obj = new WC_Payment_Monitor_License();
		$result      = $license_obj->validate_license( $license_key );

		if ( $result['valid'] ) {
			return array(
				'valid'   => true,
				'message' => __( 'License validated successfully', 'wc-payment-monitor' ),
				'expires' => isset( $result['data']['expiration_ts'] ) ? $result['data']['expiration_ts'] : null,
			);
		} else {
			return array(
				'valid'   => false,
				'message' => isset( $result['message'] ) ? $result['message'] : __( 'Invalid license key', 'wc-payment-monitor' ),
			);
		}
	}

	/**
	 * Check license status
	 *
	 * @return array License status information.
	 */
	public function get_license_status() {
		$license         = new WC_Payment_Monitor_License();
		$license_key     = $license->get_license_key();
		$license_status  = $license->get_license_status();
		$license_data    = $license->get_license_data();
		$license_expires = isset( $license_data['expiration'] ) ? $license_data['expiration'] : '';

		return array(
			'has_key'           => ! empty( $license_key ),
			'status'            => $license_status,
			'expires'           => $license_expires,
			'is_active'         => $license_status === 'valid',
			'premium_available' => $this->is_premium_feature_available(),
		);
	}

	/**
	 * Test SMS configuration
	 *
	 * Delegates to notifier class.
	 *
	 * @param string $phone_number Phone number to test.
	 * @return array Test result.
	 */
	public function test_sms_configuration( $phone_number ) {
		return $this->notifier->test_sms_configuration( $phone_number );
	}

	/**
	 * Test Slack configuration
	 *
	 * Delegates to notifier class.
	 *
	 * @param string $webhook_url Slack webhook URL.
	 * @return array Test result.
	 */
	public function test_slack_configuration( $webhook_url ) {
		return $this->notifier->test_slack_configuration( $webhook_url );
	}
}
