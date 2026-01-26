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
		'high'    => 70,
		'warning' => 85,
		'info'    => 95,
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

		// Hook into gateway connectivity checks
		add_action( 'wc_payment_monitor_gateway_checked', array( $this, 'check_gateway_connectivity_alert' ), 10, 2 );

		// Hook into individual payment failures for immediate critical alerts
		add_action( 'wc_payment_monitor_payment_failed', array( $this, 'check_immediate_transaction_alert' ), 10, 2 );
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
	 * Check gateway connectivity status and alert if down
	 *
	 * @param string $gateway_id Gateway ID
	 * @param array  $status Status array from connectivity check
	 */
	public function check_gateway_connectivity_alert( $gateway_id, $status ) {
		// Only alert if status is 'offline'
		if ( ! isset( $status['status'] ) || 'offline' !== $status['status'] ) {
			// If it's online, resolve any open 'gateway_down' alerts
			if ( isset( $status['status'] ) && 'online' === $status['status'] ) {
				$this->resolve_alerts( $gateway_id, 'gateway_down' );
			}
			return;
		}

		// Prepare alert data
		$alert_data = array(
			'alert_type' => 'gateway_down',
			'gateway_id' => $gateway_id,
			'severity'   => 'critical', // Gateway down is critical
			'message'    => sprintf( 
				__( 'Gateway %s is reported as offline. Error: %s', 'wc-payment-monitor' ), 
				ucfirst( $gateway_id ), 
				isset( $status['message'] ) ? $status['message'] : __( 'Unknown error', 'wc-payment-monitor' )
			),
			'metadata'   => json_encode( $status ),
		);
		
		// Check rate limiting
		if ( $this->is_rate_limited( $gateway_id, 'gateway_down' ) ) {
			return;
		}

		$this->trigger_alert( $alert_data );
	}

	/**
	 * Check for immediate alerts on transaction failure
	 * This implements the "Hybrid Approach" - catching critical errors immediately
	 * while leaving statistical analysis for the scheduled jobs.
	 * 
	 * @param int      $order_id Order ID
	 * @param WC_Order $order    Order object
	 */
	public function check_immediate_transaction_alert( $order_id, $order ) {
		// Get failure reason
		$logger = new WC_Payment_Monitor_Logger();
		$transaction = $logger->get_transaction_by_order_id( $order_id );
		
		if ( ! $transaction ) {
			return;
		}

		$reason = strtolower( $transaction->failure_reason );
		$gateway_id = $transaction->gateway_id;

		// Define critical error keywords that require immediate attention
		// These are "System Errors" vs "User Errors"
		$critical_errors = array(
			'authentication_required' => 'Gateway Misconfiguration',
			'connection refused'      => 'Connection Error',
			'timed out'               => 'Gateway Timeout',
			'api key'                 => 'Invalid API Key',
			'unauthorized'            => 'Unauthorized Access',
			'curl error'              => 'Network Error',
			'service unavailable'     => 'Service Unavailable',
		);

		foreach ( $critical_errors as $keyword => $label ) {
			if ( strpos( $reason, $keyword ) !== false ) {
				// Immediate Alert Found!
				$alert_data = array(
					'alert_type' => 'gateway_error',
					'gateway_id' => $gateway_id,
					'severity'   => 'critical',
					'message'    => sprintf( 
						__( 'Critical Gateway Error detected on Order #%s. Reason: %s', 'wc-payment-monitor' ), 
						$order_id,
						$transaction->failure_reason
					),
					'metadata'   => json_encode( array(
						'order_id'       => $order_id,
						'failure_reason' => $transaction->failure_reason,
						'error_type'     => $label
					) ),
				);

				$this->trigger_alert( $alert_data );
				return; // Only trigger one alert per transaction
			}
		}
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
		if ( $success_rate < self::SEVERITY_THRESHOLDS['high'] ) {
			return 'high';
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
		// Get alert threshold - check gateway-specific first (Pro+ feature)
		$settings  = get_option( 'wc_payment_monitor_settings', array() );
		$threshold = $this->get_gateway_alert_threshold( $gateway_id, $settings );

		// Only trigger if below threshold
		if ( $success_rate >= $threshold ) {
			return false;
		}

		// Check if alerts are enabled for this gateway (Pro+ feature)
		if ( ! $this->is_gateway_alerts_enabled( $gateway_id, $settings ) ) {
			return false;
		}

		// Check rate limiting
		if ( $this->is_rate_limited( $gateway_id, 'low_success_rate' ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Get alert threshold for a specific gateway
	 *
	 * @param string $gateway_id Gateway ID
	 * @param array  $settings Plugin settings
	 * @return float Alert threshold
	 */
	private function get_gateway_alert_threshold( $gateway_id, $settings ) {
		// Check if per-gateway configuration is available (Pro+ feature)
		if ( $this->has_feature( 'per_gateway_config' ) ) {
			$gateway_config = isset( $settings['gateway_alert_config'] ) ? $settings['gateway_alert_config'] : array();
			
			if ( isset( $gateway_config[ $gateway_id ] ) && isset( $gateway_config[ $gateway_id ]['threshold'] ) ) {
				return floatval( $gateway_config[ $gateway_id ]['threshold'] );
			}
		}

		// Fall back to global threshold
		return isset( $settings['alert_threshold'] ) ? floatval( $settings['alert_threshold'] ) : 85.0;
	}

	/**
	 * Check if alerts are enabled for a specific gateway
	 *
	 * @param string $gateway_id Gateway ID
	 * @param array  $settings Plugin settings
	 * @return bool Alerts enabled
	 */
	private function is_gateway_alerts_enabled( $gateway_id, $settings ) {
		// Check if per-gateway configuration is available (Pro+ feature)
		if ( $this->has_feature( 'per_gateway_config' ) ) {
			$gateway_config = isset( $settings['gateway_alert_config'] ) ? $settings['gateway_alert_config'] : array();
			
			if ( isset( $gateway_config[ $gateway_id ] ) && isset( $gateway_config[ $gateway_id ]['enabled'] ) ) {
				return (bool) $gateway_config[ $gateway_id ]['enabled'];
			}
		}

		// Default: enabled
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
		
		if ( isset( $alert_data['message'] ) && ! empty( $alert_data['message'] ) ) {
			return $alert_data['message'];
		}

		// Backward compatibility / default for low_success_rate
		$success_rate = isset( $alert_data['success_rate'] ) ? number_format( $alert_data['success_rate'], 2 ) : '0.00';
		$period       = isset( $alert_data['period'] ) ? $alert_data['period'] : 'custom';
		$failed_count = isset( $alert_data['failed_transactions'] ) ? $alert_data['failed_transactions'] : 0;
		$total_count  = isset( $alert_data['total_transactions'] ) ? $alert_data['total_transactions'] : 0;
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
		$tier               = $this->get_license_tier();
		$gateway_id         = isset( $alert_data['gateway_id'] ) ? $alert_data['gateway_id'] : '';

		// Determine which channels to use - check per-gateway config first (Pro+ feature)
		$channels = $this->get_alert_channels_for_gateway( $gateway_id, $settings, $tier );
		
		// Email - check if using local (free) or server-side delivery (starter+)
		if ( in_array( 'email', $channels, true ) && ! empty( $settings['alert_email'] ) ) {
			if ( 'free' === $tier ) {
				// Send email locally for free tier
				$email_sent         = $this->send_email_notification( $alert_data, $settings['alert_email'] );
				$notifications_sent = $notifications_sent || $email_sent;
				// Remove email from channels array since we handled it locally
				$channels = array_diff( $channels, array( 'email' ) );
			}
		}

		// Send through API if we have premium channels
		if ( ! empty( $channels ) ) {
			$api_sent           = $this->send_to_api( $alert_data, $channels, $settings );
			$notifications_sent = $notifications_sent || $api_sent;
		}

		return $notifications_sent;
	}

	/**
	 * Get alert channels for a specific gateway
	 *
	 * @param string $gateway_id Gateway ID
	 * @param array  $settings Plugin settings
	 * @param string $tier License tier
	 * @return array Alert channels
	 */
	private function get_alert_channels_for_gateway( $gateway_id, $settings, $tier ) {
		$channels = array();

		// Check per-gateway configuration first (Pro+ feature)
		if ( $this->has_feature( 'per_gateway_config' ) ) {
			$gateway_config = isset( $settings['gateway_alert_config'] ) ? $settings['gateway_alert_config'] : array();
			
			if ( isset( $gateway_config[ $gateway_id ] ) && isset( $gateway_config[ $gateway_id ]['channels'] ) ) {
				$configured_channels = $gateway_config[ $gateway_id ]['channels'];
				
				// Validate channels against tier permissions
				foreach ( $configured_channels as $channel ) {
					if ( $this->is_channel_available( $channel, $tier, $settings ) ) {
						$channels[] = $channel;
					}
				}
				
				return $channels;
			}
		}

		// Fall back to global channel configuration
		if ( ! empty( $settings['alert_email'] ) ) {
			$channels[] = 'email';
		}
		
		if ( ! empty( $settings['alert_phone_number'] ) && in_array( $tier, array( 'starter', 'pro', 'agency' ), true ) ) {
			$channels[] = 'sms';
		}
		
		if ( ! empty( $settings['alert_slack_workspace'] ) && in_array( $tier, array( 'pro', 'agency' ), true ) ) {
			$channels[] = 'slack';
		}

		return $channels;
	}

	/**
	 * Check if a specific alert channel is available
	 *
	 * @param string $channel Channel name
	 * @param string $tier License tier
	 * @param array  $settings Plugin settings
	 * @return bool Channel available
	 */
	private function is_channel_available( $channel, $tier, $settings ) {
		switch ( $channel ) {
			case 'email':
				return ! empty( $settings['alert_email'] );
			
			case 'sms':
				return ! empty( $settings['alert_phone_number'] ) && in_array( $tier, array( 'starter', 'pro', 'agency' ), true );
			
			case 'slack':
				return ! empty( $settings['alert_slack_workspace'] ) && in_array( $tier, array( 'pro', 'agency' ), true );
			
			default:
				return false;
		}
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
		// Use License class for validation
		$license = new WC_Payment_Monitor_License();
		$is_valid = $license->is_license_valid();

		// Allow filtering for testing or custom license validation
		$is_premium = apply_filters(
			'wc_payment_monitor_premium_available',
			$is_valid
		);

		return $is_premium;
	}

	/**
	 * Get license tier from license data
	 *
	 * @return string License tier: free, starter, pro, agency
	 */
	private function get_license_tier() {
		$license = new WC_Payment_Monitor_License();
		$license_data = $license->get_license_data();
		
		if ( ! $license_data || ! isset( $license_data['plan'] ) ) {
			return 'free';
		}
		
		return strtolower( $license_data['plan'] );
	}

	/**
	 * Check if a specific feature is available in current license tier
	 *
	 * @param string $feature_name Feature name
	 * @return bool Feature available
	 */
	private function has_feature( $feature_name ) {
		$license = new WC_Payment_Monitor_License();
		$license_data = $license->get_license_data();
		
		if ( ! $license_data || ! isset( $license_data['features'] ) ) {
			return false;
		}
		
		return isset( $license_data['features'][ $feature_name ] ) && $license_data['features'][ $feature_name ];
	}

	/**
	 * Send alert to centralized API for delivery
	 *
	 * @param array  $alert_data Alert data
	 * @param array  $channels Channels to send to (email, sms, slack)
	 * @param array  $settings Plugin settings
	 * @return bool Success
	 */
	private function send_to_api( $alert_data, $channels, $settings ) {
		$license = new WC_Payment_Monitor_License();
		$license_key = $license->get_license_key();
		
		if ( empty( $license_key ) ) {
			error_log( 'WC Payment Monitor: Cannot send alert to API - no license key' );
			return false;
		}

		// Prepare contact information
		$contact = array(
			'email' => isset( $settings['alert_email'] ) ? $settings['alert_email'] : '',
		);
		
		if ( ! empty( $settings['alert_phone_number'] ) ) {
			$contact['phone'] = $settings['alert_phone_number'];
		}
		
		if ( ! empty( $settings['alert_slack_workspace'] ) ) {
			$contact['slack_workspace'] = $settings['alert_slack_workspace'];
		}

		// Prepare alert payload
		$payload = array(
			'license_key' => $license_key,
			'site_url'    => get_site_url(),
			'contact'     => $contact,
			'channels'    => $channels,
			'alert'       => array(
				'type'          => $alert_data['alert_type'],
				'gateway'       => $alert_data['gateway_id'],
				'severity'      => $alert_data['severity'],
				'success_rate'  => isset( $alert_data['success_rate'] ) ? $alert_data['success_rate'] : null,
				'failed_count'  => isset( $alert_data['failed_transactions'] ) ? $alert_data['failed_transactions'] : 0,
				'total_count'   => isset( $alert_data['total_transactions'] ) ? $alert_data['total_transactions'] : 0,
				'timestamp'     => current_time( 'c' ),
				'message'       => $this->create_alert_message( $alert_data ),
			),
		);

		// Send to API
		$url = 'https://paysentinel.caplaz.com/api/alerts';
		
		$args = array(
			'method'  => 'POST',
			'headers' => array(
				'Content-Type' => 'application/json',
			),
			'body'    => wp_json_encode( $payload ),
			'timeout' => 10,
		);

		$response = wp_remote_post( $url, $args );

		if ( is_wp_error( $response ) ) {
			error_log( 'WC Payment Monitor API Error: ' . $response->get_error_message() );
			return false;
		}

		$response_code = wp_remote_retrieve_response_code( $response );
		$response_body = wp_remote_retrieve_body( $response );
		$response_data = json_decode( $response_body, true );

		// Handle different response codes
		if ( 200 === $response_code ) {
			// Success - log quota info if available
			if ( isset( $response_data['quota'] ) ) {
				update_option( 'wc_payment_monitor_sms_quota', $response_data['quota'] );
			}
			return true;
		} elseif ( 402 === $response_code ) {
			// Quota exceeded
			error_log( 'WC Payment Monitor: SMS quota exceeded' );
			update_option( 'wc_payment_monitor_quota_exceeded', true );
			return false;
		} elseif ( 401 === $response_code ) {
			// Invalid license
			error_log( 'WC Payment Monitor: Invalid license for API alerts' );
			return false;
		} else {
			// Other error
			error_log( 'WC Payment Monitor API Error: HTTP ' . $response_code . ' - ' . $response_body );
			return false;
		}
	}

	/**
	 * Send SMS notification (legacy method - now uses API)
	 * Kept for backward compatibility with test endpoints
	 *
	 * @param array  $alert_data Alert data
	 * @param string $phone_number Phone number
	 * @return bool Success
	 */
	private function send_sms_notification( $alert_data, $phone_number ) {
		$settings = get_option( 'wc_payment_monitor_settings', array() );
		$settings['alert_phone_number'] = $phone_number;
		return $this->send_to_api( $alert_data, array( 'sms' ), $settings );
	}

	/**
	 * Send Slack notification (legacy method - now uses API)
	 * Kept for backward compatibility with test endpoints
	 *
	 * @param array  $alert_data Alert data
	 * @param string $webhook_url Slack webhook URL (now slack workspace ID)
	 * @return bool Success
	 */
	private function send_slack_notification( $alert_data, $webhook_url ) {
		$settings = get_option( 'wc_payment_monitor_settings', array() );
		$settings['alert_slack_workspace'] = $webhook_url;
		return $this->send_to_api( $alert_data, array( 'slack' ), $settings );
	}

	/**
	 * Legacy Slack webhook method - redirects to API
	 *
	 * @param array  $alert_data Alert data
	 * @param string $webhook_url Slack webhook URL
	 * @return bool Success
	 */
	private function send_slack_notification_legacy( $alert_data, $webhook_url ) {
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