<?php
/**
 * Alert system class
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WC_Payment_Monitor_Alerts {

	/**
	 * Database instance
	 */
	private $database;

	/**
	 * Health instance
	 */
	private $health;

	/**
	 * Alert severity thresholds
	 */
	const SEVERITY_THRESHOLDS = array(
		'critical' => 70,
		'warning'  => 85,
		'info'     => 95,
	);

	/**
	 * Rate limiting window in seconds (1 hour)
	 */
	const RATE_LIMIT_WINDOW = 3600;

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->database = new WC_Payment_Monitor_Database();
		$this->health   = new WC_Payment_Monitor_Health();
		$this->init_hooks();
	}

	/**
	 * Initialize WordPress hooks
	 */
	private function init_hooks() {
		// Hook into health calculation to check for alerts
		add_action( 'wc_payment_monitor_health_calculation', array( $this, 'check_all_gateway_alerts' ) );

		// Hook into individual health calculations
		add_action( 'wc_payment_monitor_gateway_health_calculated', array( $this, 'check_gateway_alerts' ), 10, 2 );
	}

	/**
	 * Check alerts for all gateways
	 */
	public function check_all_gateway_alerts() {
		$active_gateways = $this->get_active_gateways();

		foreach ( $active_gateways as $gateway_id ) {
			$health_data = $this->health->get_gateway_health( $gateway_id );
			$this->check_and_send( $gateway_id, $health_data );
		}
	}

	/**
	 * Check alerts for a specific gateway
	 *
	 * @param string $gateway_id Gateway ID
	 * @param array  $health_data Health data for all periods
	 */
	public function check_gateway_alerts( $gateway_id, $health_data ) {
		$this->check_and_send( $gateway_id, $health_data );
	}

	/**
	 * Check and send alerts for a gateway
	 *
	 * @param string $gateway_id Gateway ID
	 * @param array  $health_data Health data for all periods
	 */
	public function check_and_send( $gateway_id, $health_data ) {
		// Check each period for alert conditions
		foreach ( $health_data as $period => $data ) {
			if ( empty( $data ) || ! isset( $data['success_rate'] ) ) {
				continue;
			}

			$success_rate       = floatval( $data['success_rate'] );
			$total_transactions = intval( $data['total_transactions'] );

			// Skip if no transactions in this period
			if ( $total_transactions === 0 ) {
				continue;
			}

			// Determine alert severity based on success rate
			$severity = $this->calculate_severity( $success_rate );

			// Check if we need to send an alert
			if ( $this->should_trigger_alert( $gateway_id, $success_rate, $severity ) ) {
				$alert_data = array(
					'alert_type'          => 'low_success_rate',
					'gateway_id'          => $gateway_id,
					'severity'            => $severity,
					'success_rate'        => $success_rate,
					'period'              => $period,
					'total_transactions'  => $total_transactions,
					'failed_transactions' => intval( $data['failed_transactions'] ),
					'calculated_at'       => $data['calculated_at'],
				);

				$this->trigger_alert( $alert_data );
			}

			// Check for resolution of previous alerts
			$this->check_alert_resolution( $gateway_id, $success_rate );
		}
	}

	/**
	 * Calculate alert severity based on success rate
	 *
	 * @param float $success_rate Success rate percentage
	 * @return string Severity level
	 */
	private function calculate_severity( $success_rate ) {
		if ( $success_rate < self::SEVERITY_THRESHOLDS['critical'] ) {
			return 'critical';
		} elseif ( $success_rate < self::SEVERITY_THRESHOLDS['warning'] ) {
			return 'warning';
		} elseif ( $success_rate < self::SEVERITY_THRESHOLDS['info'] ) {
			return 'info';
		}

		return 'info'; // Default to info for edge cases
	}

	/**
	 * Check if alert should be triggered
	 *
	 * @param string $gateway_id Gateway ID
	 * @param float  $success_rate Success rate
	 * @param string $severity Alert severity
	 * @return bool Should trigger alert
	 */
	private function should_trigger_alert( $gateway_id, $success_rate, $severity ) {
		// Get alert threshold from settings
		$settings  = get_option( 'wc_payment_monitor_settings', array() );
		$threshold = isset( $settings['alert_threshold'] ) ? floatval( $settings['alert_threshold'] ) : 85.0;

		// Only trigger if below threshold
		if ( $success_rate >= $threshold ) {
			return false;
		}

		// Check rate limiting
		if ( $this->is_rate_limited( $gateway_id, 'low_success_rate' ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Check if alert is rate limited
	 *
	 * @param string $gateway_id Gateway ID
	 * @param string $alert_type Alert type
	 * @return bool Is rate limited
	 */
	public function is_rate_limited( $gateway_id, $alert_type ) {
		global $wpdb;

		$table_name  = $this->database->get_alerts_table();
		$cutoff_time = date( 'Y-m-d H:i:s', time() - self::RATE_LIMIT_WINDOW );

		$recent_alert = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table_name} 
             WHERE gateway_id = %s AND alert_type = %s AND created_at > %s 
             ORDER BY created_at DESC LIMIT 1",
				$gateway_id,
				$alert_type,
				$cutoff_time
			)
		);

		return ! empty( $recent_alert );
	}

	/**
	 * Trigger an alert
	 *
	 * @param array $alert_data Alert data
	 * @return int|false Alert ID or false on failure
	 */
	public function trigger_alert( $alert_data ) {
		// Create alert message
		$message = $this->create_alert_message( $alert_data );

		// Prepare alert record
		$alert_record = array(
			'alert_type'  => $alert_data['alert_type'],
			'gateway_id'  => $alert_data['gateway_id'],
			'severity'    => $alert_data['severity'],
			'message'     => $message,
			'metadata'    => json_encode( $alert_data ),
			'is_resolved' => 0,
			'resolved_at' => null,
			'notified_at' => null,
			'created_at'  => current_time( 'mysql' ),
		);

		// Save alert to database
		$alert_id = $this->save_alert( $alert_record );

		if ( $alert_id ) {
			// Send notifications
			$notification_sent = $this->send_notifications( $alert_data, $alert_id );

			if ( $notification_sent ) {
				// Update notified_at timestamp
				$this->update_alert_notification_time( $alert_id );
			}

			// Fire action hook for extensibility
			do_action( 'wc_payment_monitor_alert_triggered', $alert_data, $alert_id );
		}

		return $alert_id;
	}

	/**
	 * Create alert message
	 *
	 * @param array $alert_data Alert data
	 * @return string Alert message
	 */
	private function create_alert_message( $alert_data ) {
		$gateway_name = $this->get_gateway_name( $alert_data['gateway_id'] );
		$success_rate = number_format( $alert_data['success_rate'], 2 );
		$period       = $alert_data['period'];
		$failed_count = $alert_data['failed_transactions'];
		$total_count  = $alert_data['total_transactions'];
		$success_count = $total_count - $failed_count;

		$message = sprintf(
			__( 'Payment gateway "%1$s" success rate has dropped to %2$s%% in the last %3$s. Only %4$d out of %5$d transactions succeeded (%6$d failed).', 'wc-payment-monitor' ),
			$gateway_name,
			$success_rate,
			$this->format_period_name( $period ),
			$success_count,
			$total_count,
			$failed_count
		);

		return $message;
	}

	/**
	 * Send notifications for an alert
	 *
	 * @param array $alert_data Alert data
	 * @param int   $alert_id Alert ID
	 * @return bool Success
	 */
	private function send_notifications( $alert_data, $alert_id ) {
		$settings           = get_option( 'wc_payment_monitor_settings', array() );
		$notifications_sent = false;

		// Send email notification
		if ( ! empty( $settings['alert_email'] ) ) {
			$email_sent         = $this->send_email_notification( $alert_data, $settings['alert_email'] );
			$notifications_sent = $notifications_sent || $email_sent;
		}

		// Send premium notifications if available
		if ( $this->is_premium_feature_available() ) {
			// SMS notification
			if ( ! empty( $settings['alert_phone'] ) ) {
				$sms_sent           = $this->send_sms_notification( $alert_data, $settings['alert_phone'] );
				$notifications_sent = $notifications_sent || $sms_sent;
			}

			// Slack notification
			if ( ! empty( $settings['slack_webhook'] ) ) {
				$slack_sent         = $this->send_slack_notification( $alert_data, $settings['slack_webhook'] );
				$notifications_sent = $notifications_sent || $slack_sent;
			}
		}

		return $notifications_sent;
	}

	/**
	 * Send email notification
	 *
	 * @param array  $alert_data Alert data
	 * @param string $email_address Email address
	 * @return bool Success
	 */
	private function send_email_notification( $alert_data, $email_address ) {
		$subject = sprintf(
			__( '[%1$s] Payment Gateway Alert - %2$s', 'wc-payment-monitor' ),
			get_bloginfo( 'name' ),
			ucfirst( $alert_data['severity'] )
		);

		$message = $this->create_email_template( $alert_data );

		$headers = array(
			'Content-Type: text/html; charset=UTF-8',
			'From: ' . get_bloginfo( 'name' ) . ' <' . get_option( 'admin_email' ) . '>',
		);

		return wp_mail( $email_address, $subject, $message, $headers );
	}

	/**
	 * Create HTML email template
	 *
	 * @param array $alert_data Alert data
	 * @return string HTML email content
	 */
	private function create_email_template( $alert_data ) {
		$gateway_name   = $this->get_gateway_name( $alert_data['gateway_id'] );
		$success_rate   = number_format( $alert_data['success_rate'], 2 );
		$period         = $this->format_period_name( $alert_data['period'] );
		$failed_count   = $alert_data['failed_transactions'];
		$total_count    = $alert_data['total_transactions'];
		$severity_color = $this->get_severity_color( $alert_data['severity'] );
		$subject        = sprintf(
			__( '[%1$s] Payment Gateway Alert - %2$s', 'wc-payment-monitor' ),
			get_bloginfo( 'name' ),
			ucfirst( $alert_data['severity'] )
		);

		$admin_url = admin_url( 'admin.php?page=wc-payment-monitor' );

		ob_start();
		?>
		<!DOCTYPE html>
		<html>
		<head>
			<meta charset="UTF-8">
			<title><?php echo esc_html( $subject ); ?></title>
			<style>
				body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
				.container { max-width: 600px; margin: 0 auto; padding: 20px; }
				.header { background-color: <?php echo esc_attr( $severity_color ); ?>; color: white; padding: 20px; text-align: center; }
				.content { background-color: #f9f9f9; padding: 20px; }
				.stats { background-color: white; padding: 15px; margin: 15px 0; border-left: 4px solid <?php echo esc_attr( $severity_color ); ?>; }
				.button { display: inline-block; background-color: #0073aa; color: white; padding: 10px 20px; text-decoration: none; border-radius: 3px; margin: 10px 0; }
				.footer { text-align: center; padding: 20px; font-size: 12px; color: #666; }
			</style>
		</head>
		<body>
			<div class="container">
				<div class="header">
					<h1><?php _e( 'Payment Gateway Alert', 'wc-payment-monitor' ); ?></h1>
					<p><?php echo esc_html( ucfirst( $alert_data['severity'] ) ); ?> <?php _e( 'Alert', 'wc-payment-monitor' ); ?></p>
				</div>
				
				<div class="content">
					<h2><?php _e( 'Alert Details', 'wc-payment-monitor' ); ?></h2>
					<p><?php echo esc_html( $this->create_alert_message( $alert_data ) ); ?></p>
					
					<div class="stats">
						<h3><?php _e( 'Gateway Statistics', 'wc-payment-monitor' ); ?></h3>
						<ul>
							<li><strong><?php _e( 'Gateway:', 'wc-payment-monitor' ); ?></strong> <?php echo esc_html( $gateway_name ); ?></li>
							<li><strong><?php _e( 'Success Rate:', 'wc-payment-monitor' ); ?></strong> <?php echo esc_html( $success_rate ); ?>%</li>
							<li><strong><?php _e( 'Period:', 'wc-payment-monitor' ); ?></strong> <?php echo esc_html( $period ); ?></li>
							<li><strong><?php _e( 'Failed Transactions:', 'wc-payment-monitor' ); ?></strong> <?php echo esc_html( $failed_count ); ?></li>
							<li><strong><?php _e( 'Total Transactions:', 'wc-payment-monitor' ); ?></strong> <?php echo esc_html( $total_count ); ?></li>
							<li><strong><?php _e( 'Time:', 'wc-payment-monitor' ); ?></strong> <?php echo esc_html( $alert_data['calculated_at'] ); ?></li>
						</ul>
					</div>
					
					<p>
						<a href="<?php echo esc_url( $admin_url ); ?>" class="button">
							<?php _e( 'View Dashboard', 'wc-payment-monitor' ); ?>
						</a>
					</p>
					
					<h3><?php _e( 'Recommended Actions', 'wc-payment-monitor' ); ?></h3>
					<ul>
						<li><?php _e( 'Check gateway configuration and credentials', 'wc-payment-monitor' ); ?></li>
						<li><?php _e( 'Review recent failed transactions for patterns', 'wc-payment-monitor' ); ?></li>
						<li><?php _e( 'Contact your payment processor if issues persist', 'wc-payment-monitor' ); ?></li>
						<li><?php _e( 'Consider enabling backup payment methods', 'wc-payment-monitor' ); ?></li>
					</ul>
				</div>
				
				<div class="footer">
					<p><?php _e( 'This alert was generated by WooCommerce Payment Monitor', 'wc-payment-monitor' ); ?></p>
					<p><?php echo esc_html( get_bloginfo( 'name' ) ); ?> - <?php echo esc_url( home_url() ); ?></p>
				</div>
			</div>
		</body>
		</html>
		<?php
		return ob_get_clean();
	}

	/**
	 * Check for alert resolution
	 *
	 * @param string $gateway_id Gateway ID
	 * @param float  $success_rate Current success rate
	 */
	private function check_alert_resolution( $gateway_id, $success_rate ) {
		// Get alert threshold from settings
		$settings  = get_option( 'wc_payment_monitor_settings', array() );
		$threshold = isset( $settings['alert_threshold'] ) ? floatval( $settings['alert_threshold'] ) : 85.0;

		// If success rate is back above threshold, resolve alerts
		if ( $success_rate >= $threshold ) {
			$this->resolve_alerts( $gateway_id, 'low_success_rate' );
		}
	}

	/**
	 * Resolve alerts for a gateway
	 *
	 * @param string $gateway_id Gateway ID
	 * @param string $alert_type Alert type to resolve
	 * @return int Number of alerts resolved
	 */
	public function resolve_alerts( $gateway_id, $alert_type ) {
		global $wpdb;

		$table_name = $this->database->get_alerts_table();

		$result = $wpdb->update(
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

		// Fire action hook for resolved alerts
		if ( $result > 0 ) {
			do_action( 'wc_payment_monitor_alerts_resolved', $gateway_id, $alert_type, $result );
		}

		return $result;
	}

	/**
	 * Save alert to database
	 *
	 * @param array $alert_record Alert data
	 * @return int|false Alert ID or false on failure
	 */
	private function save_alert( $alert_record ) {
		global $wpdb;

		$table_name = $this->database->get_alerts_table();

		$result = $wpdb->insert(
			$table_name,
			$alert_record,
			array(
				'%s', // alert_type
				'%s', // gateway_id
				'%s', // severity
				'%s', // message
				'%s', // metadata
				'%d', // is_resolved
				'%s', // resolved_at
				'%s', // notified_at
				'%s',  // created_at
			)
		);

		return $result !== false ? $wpdb->insert_id : false;
	}

	/**
	 * Update alert notification time
	 *
	 * @param int $alert_id Alert ID
	 * @return bool Success
	 */
	private function update_alert_notification_time( $alert_id ) {
		global $wpdb;

		$table_name = $this->database->get_alerts_table();

		$result = $wpdb->update(
			$table_name,
			array( 'notified_at' => current_time( 'mysql' ) ),
			array( 'id' => $alert_id ),
			array( '%s' ),
			array( '%d' )
		);

		return $result !== false;
	}

	/**
	 * Get gateway name for display
	 *
	 * @param string $gateway_id Gateway ID
	 * @return string Gateway name
	 */
	private function get_gateway_name( $gateway_id ) {
		if ( class_exists( 'WC_Payment_Gateways' ) ) {
			$wc_gateways = WC_Payment_Gateways::instance();
			$gateways    = $wc_gateways->get_available_payment_gateways();

			if ( isset( $gateways[ $gateway_id ] ) ) {
				return $gateways[ $gateway_id ]->get_title();
			}
		}

		// Fallback to gateway ID if name not found
		return ucfirst( str_replace( '_', ' ', $gateway_id ) );
	}

	/**
	 * Format period name for display
	 *
	 * @param string $period Period key
	 * @return string Formatted period name
	 */
	private function format_period_name( $period ) {
		$periods = array(
			'1hour'  => __( 'hour', 'wc-payment-monitor' ),
			'24hour' => __( '24 hours', 'wc-payment-monitor' ),
			'7day'   => __( '7 days', 'wc-payment-monitor' ),
		);

		return isset( $periods[ $period ] ) ? $periods[ $period ] : $period;
	}

	/**
	 * Get severity color for styling
	 *
	 * @param string $severity Severity level
	 * @return string Color code
	 */
	private function get_severity_color( $severity ) {
		$colors = array(
			'critical' => '#dc3232',
			'warning'  => '#ffb900',
			'info'     => '#0073aa',
		);

		return isset( $colors[ $severity ] ) ? $colors[ $severity ] : $colors['info'];
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
	 * Get alerts by gateway
	 *
	 * @param string $gateway_id Gateway ID
	 * @param int    $limit Limit results
	 * @param bool   $resolved_only Show only resolved alerts
	 * @return array Alert data
	 */
	public function get_alerts_by_gateway( $gateway_id, $limit = 50, $resolved_only = false ) {
		global $wpdb;

		$table_name = $this->database->get_alerts_table();

		$sql    = "SELECT * FROM {$table_name} WHERE gateway_id = %s";
		$params = array( $gateway_id );

		if ( $resolved_only !== null ) {
			$sql     .= ' AND is_resolved = %d';
			$params[] = $resolved_only ? 1 : 0;
		}

		$sql     .= ' ORDER BY created_at DESC LIMIT %d';
		$params[] = $limit;

		return $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
	}

	/**
	 * Get recent alerts
	 *
	 * @param int    $limit Limit results
	 * @param string $severity Filter by severity
	 * @return array Alert data
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

		return $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
	}

	/**
	 * Get alert statistics
	 *
	 * @param int $days Number of days to analyze (default: 7)
	 * @return array Alert statistics
	 */
	public function get_alert_stats( $days = 7 ) {
		global $wpdb;

		$table_name = $this->database->get_alerts_table();
		$start_date = date( 'Y-m-d H:i:s', time() - ( $days * 86400 ) );

		$stats = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT 
                COUNT(*) as total_alerts,
                SUM(CASE WHEN severity = 'critical' THEN 1 ELSE 0 END) as critical_alerts,
                SUM(CASE WHEN severity = 'warning' THEN 1 ELSE 0 END) as warning_alerts,
                SUM(CASE WHEN severity = 'info' THEN 1 ELSE 0 END) as info_alerts,
                SUM(CASE WHEN is_resolved = 1 THEN 1 ELSE 0 END) as resolved_alerts,
                SUM(CASE WHEN is_resolved = 0 THEN 1 ELSE 0 END) as unresolved_alerts
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
	 * @return bool Premium features available
	 */
	private function is_premium_feature_available() {
		// Check if premium license is active
		$settings       = get_option( 'wc_payment_monitor_settings', array() );
		$license_key    = isset( $settings['license_key'] ) ? $settings['license_key'] : '';
		$license_status = get_option( 'wc_payment_monitor_license_status', 'inactive' );

		// Allow filtering for testing or custom license validation
		$is_premium = apply_filters(
			'wc_payment_monitor_premium_available',
			! empty( $license_key ) && $license_status === 'active'
		);

		return $is_premium;
	}

	/**
	 * Send SMS notification (premium feature)
	 *
	 * @param array  $alert_data Alert data
	 * @param string $phone_number Phone number
	 * @return bool Success
	 */
	private function send_sms_notification( $alert_data, $phone_number ) {
		if ( ! $this->is_premium_feature_available() ) {
			return false;
		}

		$settings     = get_option( 'wc_payment_monitor_settings', array() );
		$twilio_sid   = isset( $settings['twilio_sid'] ) ? $settings['twilio_sid'] : '';
		$twilio_token = isset( $settings['twilio_token'] ) ? $settings['twilio_token'] : '';
		$twilio_from  = isset( $settings['twilio_from'] ) ? $settings['twilio_from'] : '';

		if ( empty( $twilio_sid ) || empty( $twilio_token ) || empty( $twilio_from ) ) {
			error_log( 'WC Payment Monitor: Twilio credentials not configured' );
			return false;
		}

		// Create SMS message
		$message = $this->create_sms_message( $alert_data );

		// Prepare Twilio API request
		$url = "https://api.twilio.com/2010-04-01/Accounts/{$twilio_sid}/Messages.json";

		$data = array(
			'From' => $twilio_from,
			'To'   => $phone_number,
			'Body' => $message,
		);

		$args = array(
			'method'  => 'POST',
			'headers' => array(
				'Authorization' => 'Basic ' . base64_encode( $twilio_sid . ':' . $twilio_token ),
				'Content-Type'  => 'application/x-www-form-urlencoded',
			),
			'body'    => http_build_query( $data ),
			'timeout' => 30,
		);

		$response = wp_remote_post( $url, $args );

		if ( is_wp_error( $response ) ) {
			error_log( 'WC Payment Monitor SMS Error: ' . $response->get_error_message() );
			return false;
		}

		$response_code = wp_remote_retrieve_response_code( $response );
		$response_body = wp_remote_retrieve_body( $response );

		if ( $response_code >= 200 && $response_code < 300 ) {
			return true;
		} else {
			error_log( 'WC Payment Monitor SMS Error: HTTP ' . $response_code . ' - ' . $response_body );
			return false;
		}
	}

	/**
	 * Send Slack notification (premium feature)
	 *
	 * @param array  $alert_data Alert data
	 * @param string $webhook_url Slack webhook URL
	 * @return bool Success
	 */
	private function send_slack_notification( $alert_data, $webhook_url ) {
		if ( ! $this->is_premium_feature_available() ) {
			return false;
		}

		if ( empty( $webhook_url ) ) {
			error_log( 'WC Payment Monitor: Slack webhook URL not configured' );
			return false;
		}

		// Create Slack message payload
		$payload = $this->create_slack_payload( $alert_data );

		$args = array(
			'method'  => 'POST',
			'headers' => array(
				'Content-Type' => 'application/json',
			),
			'body'    => json_encode( $payload ),
			'timeout' => 30,
		);

		$response = wp_remote_post( $webhook_url, $args );

		if ( is_wp_error( $response ) ) {
			error_log( 'WC Payment Monitor Slack Error: ' . $response->get_error_message() );
			return false;
		}

		$response_code = wp_remote_retrieve_response_code( $response );

		if ( $response_code >= 200 && $response_code < 300 ) {
			return true;
		} else {
			$response_body = wp_remote_retrieve_body( $response );
			error_log( 'WC Payment Monitor Slack Error: HTTP ' . $response_code . ' - ' . $response_body );
			return false;
		}
	}

	/**
	 * Create SMS message
	 *
	 * @param array $alert_data Alert data
	 * @return string SMS message
	 */
	private function create_sms_message( $alert_data ) {
		$gateway_name = $this->get_gateway_name( $alert_data['gateway_id'] );
		$success_rate = number_format( $alert_data['success_rate'], 1 );
		$severity     = strtoupper( $alert_data['severity'] );

		$message = sprintf(
			'%s ALERT: %s gateway success rate dropped to %s%% (%d/%d transactions failed). Check your dashboard immediately.',
			$severity,
			$gateway_name,
			$success_rate,
			$alert_data['failed_transactions'],
			$alert_data['total_transactions']
		);

		return $message;
	}

	/**
	 * Create Slack message payload
	 *
	 * @param array $alert_data Alert data
	 * @return array Slack payload
	 */
	private function create_slack_payload( $alert_data ) {
		$gateway_name   = $this->get_gateway_name( $alert_data['gateway_id'] );
		$success_rate   = number_format( $alert_data['success_rate'], 2 );
		$severity_color = $this->get_severity_color( $alert_data['severity'] );
		$admin_url      = admin_url( 'admin.php?page=wc-payment-monitor' );

		// Create attachment with rich formatting
		$attachment = array(
			'color'      => $severity_color,
			'title'      => sprintf( __( 'Payment Gateway Alert - %s', 'wc-payment-monitor' ), ucfirst( $alert_data['severity'] ) ),
			'title_link' => $admin_url,
			'text'       => $this->create_alert_message( $alert_data ),
			'fields'     => array(
				array(
					'title' => __( 'Gateway', 'wc-payment-monitor' ),
					'value' => $gateway_name,
					'short' => true,
				),
				array(
					'title' => __( 'Success Rate', 'wc-payment-monitor' ),
					'value' => $success_rate . '%',
					'short' => true,
				),
				array(
					'title' => __( 'Failed Transactions', 'wc-payment-monitor' ),
					'value' => $alert_data['failed_transactions'],
					'short' => true,
				),
				array(
					'title' => __( 'Total Transactions', 'wc-payment-monitor' ),
					'value' => $alert_data['total_transactions'],
					'short' => true,
				),
				array(
					'title' => __( 'Period', 'wc-payment-monitor' ),
					'value' => $this->format_period_name( $alert_data['period'] ),
					'short' => true,
				),
				array(
					'title' => __( 'Time', 'wc-payment-monitor' ),
					'value' => $alert_data['calculated_at'],
					'short' => true,
				),
			),
			'footer'     => get_bloginfo( 'name' ) . ' - WooCommerce Payment Monitor',
			'ts'         => time(),
		);

		// Add action buttons
		$attachment['actions'] = array(
			array(
				'type'  => 'button',
				'text'  => __( 'View Dashboard', 'wc-payment-monitor' ),
				'url'   => $admin_url,
				'style' => 'primary',
			),
		);

		$payload = array(
			'username'    => __( 'Payment Monitor', 'wc-payment-monitor' ),
			'icon_emoji'  => ':warning:',
			'text'        => sprintf(
				__( ':rotating_light: *%s Payment Alert* :rotating_light:', 'wc-payment-monitor' ),
				ucfirst( $alert_data['severity'] )
			),
			'attachments' => array( $attachment ),
		);

		return $payload;
	}

	/**
	 * Validate license key
	 *
	 * @param string $license_key License key
	 * @return array Validation result
	 */
	public function validate_license( $license_key ) {
		if ( empty( $license_key ) ) {
			return array(
				'valid'   => false,
				'message' => __( 'License key is required', 'wc-payment-monitor' ),
			);
		}

		// This would typically make an API call to your license server
		// For now, we'll implement a simple validation
		$license_server_url = 'https://your-license-server.com/api/validate';

		$args = array(
			'method'  => 'POST',
			'headers' => array(
				'Content-Type' => 'application/json',
			),
			'body'    => json_encode(
				array(
					'license_key' => $license_key,
					'domain'      => home_url(),
					'product'     => 'wc-payment-monitor',
				)
			),
			'timeout' => 30,
		);

		$response = wp_remote_post( $license_server_url, $args );

		if ( is_wp_error( $response ) ) {
			return array(
				'valid'   => false,
				'message' => __( 'Unable to validate license. Please try again later.', 'wc-payment-monitor' ),
			);
		}

		$response_code = wp_remote_retrieve_response_code( $response );
		$response_body = wp_remote_retrieve_body( $response );

		if ( $response_code === 200 ) {
			$data = json_decode( $response_body, true );

			if ( $data && isset( $data['valid'] ) && $data['valid'] ) {
				// Update license status
				update_option( 'wc_payment_monitor_license_status', 'active' );
				update_option( 'wc_payment_monitor_license_expires', $data['expires'] ?? '' );

				return array(
					'valid'   => true,
					'message' => __( 'License validated successfully', 'wc-payment-monitor' ),
					'expires' => $data['expires'] ?? null,
				);
			} else {
				update_option( 'wc_payment_monitor_license_status', 'invalid' );

				return array(
					'valid'   => false,
					'message' => $data['message'] ?? __( 'Invalid license key', 'wc-payment-monitor' ),
				);
			}
		} else {
			return array(
				'valid'   => false,
				'message' => __( 'License validation failed. Please contact support.', 'wc-payment-monitor' ),
			);
		}
	}

	/**
	 * Check license status
	 *
	 * @return array License status information
	 */
	public function get_license_status() {
		$settings        = get_option( 'wc_payment_monitor_settings', array() );
		$license_key     = isset( $settings['license_key'] ) ? $settings['license_key'] : '';
		$license_status  = get_option( 'wc_payment_monitor_license_status', 'inactive' );
		$license_expires = get_option( 'wc_payment_monitor_license_expires', '' );

		return array(
			'has_key'           => ! empty( $license_key ),
			'status'            => $license_status,
			'expires'           => $license_expires,
			'is_active'         => $license_status === 'active',
			'premium_available' => $this->is_premium_feature_available(),
		);
	}

	/**
	 * Test SMS configuration
	 *
	 * @param string $phone_number Phone number to test
	 * @return array Test result
	 */
	public function test_sms_configuration( $phone_number ) {
		if ( ! $this->is_premium_feature_available() ) {
			return array(
				'success' => false,
				'message' => __( 'Premium license required for SMS notifications', 'wc-payment-monitor' ),
			);
		}

		// Create test alert data
		$test_alert_data = array(
			'alert_type'          => 'test',
			'gateway_id'          => 'test_gateway',
			'severity'            => 'info',
			'success_rate'        => 75.5,
			'period'              => '24hour',
			'total_transactions'  => 100,
			'failed_transactions' => 25,
			'calculated_at'       => current_time( 'mysql' ),
		);

		$result = $this->send_sms_notification( $test_alert_data, $phone_number );

		return array(
			'success' => $result,
			'message' => $result
				? __( 'Test SMS sent successfully', 'wc-payment-monitor' )
				: __( 'Failed to send test SMS. Please check your configuration.', 'wc-payment-monitor' ),
		);
	}

	/**
	 * Test Slack configuration
	 *
	 * @param string $webhook_url Slack webhook URL
	 * @return array Test result
	 */
	public function test_slack_configuration( $webhook_url ) {
		if ( ! $this->is_premium_feature_available() ) {
			return array(
				'success' => false,
				'message' => __( 'Premium license required for Slack notifications', 'wc-payment-monitor' ),
			);
		}

		// Create test alert data
		$test_alert_data = array(
			'alert_type'          => 'test',
			'gateway_id'          => 'test_gateway',
			'severity'            => 'info',
			'success_rate'        => 75.5,
			'period'              => '24hour',
			'total_transactions'  => 100,
			'failed_transactions' => 25,
			'calculated_at'       => current_time( 'mysql' ),
		);

		$result = $this->send_slack_notification( $test_alert_data, $webhook_url );

		return array(
			'success' => $result,
			'message' => $result
				? __( 'Test Slack message sent successfully', 'wc-payment-monitor' )
				: __( 'Failed to send test Slack message. Please check your webhook URL.', 'wc-payment-monitor' ),
		);
	}
}