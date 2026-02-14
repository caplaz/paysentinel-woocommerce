<?php
/**
 * Alert Notifier Class
 *
 * Handles sending notifications through various channels (email, SMS, Slack).
 *
 * @package WC_Payment_Monitor
 * @since 1.0.0
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WC_Payment_Monitor_Alert_Notifier class
 *
 * Responsible for sending alert notifications through configured channels
 * and managing notification delivery.
 */
class WC_Payment_Monitor_Alert_Notifier {

	/**
	 * Template manager instance
	 *
	 * @var WC_Payment_Monitor_Alert_Template_Manager
	 */
	private $template_manager;

	/**
	 * Database instance
	 *
	 * @var WC_Payment_Monitor_Database
	 */
	private $database;

	/**
	 * Constructor
	 *
	 * @param WC_Payment_Monitor_Alert_Template_Manager $template_manager Template manager instance.
	 * @param WC_Payment_Monitor_Database               $database         Database instance.
	 */
	public function __construct( $template_manager, $database ) {
		$this->template_manager = $template_manager;
		$this->database         = $database;
	}

	/**
	 * Send notifications for an alert
	 *
	 * @param array $alert_data Alert data.
	 * @param int   $alert_id   Alert ID.
	 * @return bool Success.
	 */
	public function send_notifications( $alert_data, $alert_id ) {
		$settings           = get_option( 'wc_payment_monitor_settings', array() );
		$notifications_sent = false;
		$tier               = $this->get_license_tier();
		$gateway_id         = isset( $alert_data['gateway_id'] ) ? $alert_data['gateway_id'] : '';

		// Check if site is registered - alerts only work for registered sites
		$license = new WC_Payment_Monitor_License();
		if ( ! $license->is_site_registered() ) {
			error_log( 'WC Payment Monitor: Cannot send alert - site not registered with license' );
			return false;
		}

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
	 * @param string $gateway_id Gateway ID.
	 * @param array  $settings   Plugin settings.
	 * @param string $tier       License tier.
	 * @return array Alert channels.
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

		$slack_workspace = get_option( 'wc_payment_monitor_slack_workspace' );
		if ( ! empty( $slack_workspace ) && in_array( $tier, array( 'pro', 'agency' ), true ) ) {
			$channels[] = 'slack';
		}

		return $channels;
	}

	/**
	 * Check if a specific alert channel is available
	 *
	 * @param string $channel  Channel name.
	 * @param string $tier     License tier.
	 * @param array  $settings Plugin settings.
	 * @return bool Channel available.
	 */
	private function is_channel_available( $channel, $tier, $settings ) {
		switch ( $channel ) {
			case 'email':
				return ! empty( $settings['alert_email'] );

			case 'sms':
				return ! empty( $settings['alert_phone_number'] ) && in_array( $tier, array( 'starter', 'pro', 'agency' ), true );

			case 'slack':
				$slack_workspace = get_option( 'wc_payment_monitor_slack_workspace' );
				return ! empty( $slack_workspace ) && in_array( $tier, array( 'pro', 'agency' ), true );

			default:
				return false;
		}
	}

	/**
	 * Send email notification
	 *
	 * @param array  $alert_data    Alert data.
	 * @param string $email_address Email address.
	 * @return bool Success.
	 */
	private function send_email_notification( $alert_data, $email_address ) {
		$subject = sprintf(
			__( '[%1$s] Payment Gateway Alert - %2$s', 'wc-payment-monitor' ),
			get_bloginfo( 'name' ),
			ucfirst( $alert_data['severity'] )
		);

		$message = $this->template_manager->create_email_template( $alert_data );

		$headers = array(
			'Content-Type: text/html; charset=UTF-8',
			'From: ' . get_bloginfo( 'name' ) . ' <' . get_option( 'admin_email' ) . '>',
		);

		return wp_mail( $email_address, $subject, $message, $headers );
	}

	/**
	 * Send alert to API for premium channel delivery
	 *
	 * @param array $alert_data Alert data.
	 * @param array $channels   Channels to send to.
	 * @param array $settings   Plugin settings.
	 * @return bool Success.
	 */
	private function send_to_api( $alert_data, $channels, $settings ) {
		$license     = new WC_Payment_Monitor_License();
		$license_key = $license->get_license_key();

		if ( empty( $license_key ) ) {
			error_log( 'WC Payment Monitor: Cannot send alert to API - no license key' );
			return false;
		}

		// Check if site is registered - alerts only work for registered sites
		if ( ! $license->is_site_registered() ) {
			error_log( 'WC Payment Monitor: Cannot send alert to API - site not registered with license' );
			return false;
		}

		// Prepare contact information
		$contact = array(
			'email' => isset( $settings['alert_email'] ) ? $settings['alert_email'] : '',
		);

		if ( ! empty( $settings['alert_phone_number'] ) ) {
			$contact['phone'] = $settings['alert_phone_number'];
		}

		// Check for Slack integration
		$slack_workspace = get_option( 'wc_payment_monitor_slack_workspace', '' );
		if ( ! empty( $slack_workspace ) ) {
			$contact['slack_workspace'] = $slack_workspace;
		}

		// Prepare alert payload according to API spec
		// The API expects a simple structure with license_key, alert_type, recipient/integration_id, and message
		$message = $this->template_manager->create_alert_message( $alert_data );

		$payload = array(
			'license_key' => $license_key,
			'site_url'    => get_site_url(),
			'message'     => $message,
			'data'        => array(
				'gateway'      => $alert_data['gateway_id'],
				'severity'     => $alert_data['severity'],
				'success_rate' => isset( $alert_data['success_rate'] ) ? $alert_data['success_rate'] : null,
				'failed_count' => isset( $alert_data['failed_transactions'] ) ? $alert_data['failed_transactions'] : 0,
				'total_count'  => isset( $alert_data['total_transactions'] ) ? $alert_data['total_transactions'] : 0,
				'timestamp'    => current_time( 'c' ),
			),
		);

		// Add channel-specific fields
		if ( in_array( 'sms', $channels, true ) && ! empty( $contact['phone'] ) ) {
			$payload['alert_type'] = 'SMS';
			$payload['recipient']  = $contact['phone'];
		} elseif ( in_array( 'slack', $channels, true ) && ! empty( $contact['slack_workspace'] ) ) {
			$payload['alert_type']     = 'SLACK';
			$payload['integration_id'] = $contact['slack_workspace'];
		} else {
			// Default to email or first available channel
			$payload['alert_type'] = 'EMAIL';
			$payload['recipient']  = $contact['email'];
		}

		// Send to API
		$license  = new WC_Payment_Monitor_License();
		$response = $license->make_authenticated_request(
			WC_Payment_Monitor_License::API_ENDPOINT_ALERTS,
			'POST',
			$payload,
			true
		);

		if ( is_wp_error( $response ) ) {
			error_log( 'WC Payment Monitor API Error: ' . $response->get_error_message() );
			return false;
		}

		$response_code = wp_remote_retrieve_response_code( $response );
		$response_body = wp_remote_retrieve_body( $response );
		$response_data = json_decode( $response_body, true );

		// Handle different response codes per API spec
		if ( 200 === $response_code ) {
			// Success - alert delivered
			return true;
		} elseif ( 400 === $response_code ) {
			// Bad request - missing required fields
			$error_msg = isset( $response_data['error'] ) ? $response_data['error'] : 'Bad request - missing required fields';
			error_log( 'WC Payment Monitor: Alert API error (400) - ' . $error_msg );
			return false;
		} elseif ( 401 === $response_code ) {
			// Unauthorized - invalid HMAC signature
			error_log( 'WC Payment Monitor: Invalid HMAC signature for API alerts' );
			return false;
		} elseif ( 403 === $response_code ) {
			// Quota exceeded or invalid license
			$error_msg = isset( $response_data['error'] ) ? $response_data['error'] : 'Quota exceeded or invalid license';
			error_log( 'WC Payment Monitor: ' . $error_msg );
			update_option( 'wc_payment_monitor_quota_exceeded', true );
			return false;
		} elseif ( 404 === $response_code ) {
			// Integration not found
			error_log( 'WC Payment Monitor: Integration not found' );
			return false;
		} elseif ( 429 === $response_code ) {
			// Rate limit exceeded
			$retry_after = wp_remote_retrieve_header( $response, 'Retry-After' );
			error_log( 'WC Payment Monitor: Rate limit exceeded. Retry after: ' . ( $retry_after ? $retry_after . ' seconds' : 'unknown' ) );
			return false;
		} elseif ( 502 === $response_code ) {
			// Integration service error (Slack/SMS provider failure)
			error_log( 'WC Payment Monitor: Integration service error (Slack/SMS provider failure)' );
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
	 * @param array  $alert_data   Alert data.
	 * @param string $phone_number Phone number.
	 * @return bool Success.
	 */
	private function send_sms_notification( $alert_data, $phone_number ) {
		$settings                       = get_option( 'wc_payment_monitor_settings', array() );
		$settings['alert_phone_number'] = $phone_number;
		return $this->send_to_api( $alert_data, array( 'sms' ), $settings );
	}

	/**
	 * Send Slack notification (legacy method - now uses API)
	 * Kept for backward compatibility with test endpoints
	 *
	 * @param array  $alert_data  Alert data.
	 * @param string $webhook_url Slack webhook URL (now slack workspace ID).
	 * @return bool Success.
	 */
	private function send_slack_notification( $alert_data, $webhook_url ) {
		$settings                          = get_option( 'wc_payment_monitor_settings', array() );
		$settings['alert_slack_workspace'] = $webhook_url;
		return $this->send_to_api( $alert_data, array( 'slack' ), $settings );
	}

	/**
	 * Legacy Slack webhook method - redirects to API
	 *
	 * @param array  $alert_data  Alert data.
	 * @param string $webhook_url Slack webhook URL.
	 * @return bool Success.
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
		$payload = $this->template_manager->create_slack_payload( $alert_data );

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
	 * Test SMS configuration
	 *
	 * @param string $phone_number Phone number.
	 * @return array Test result.
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
	 * @param string $webhook_url Slack webhook URL.
	 * @return array Test result.
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

	/**
	 * Check if premium features are available
	 *
	 * @return bool Premium features available.
	 */
	private function is_premium_feature_available() {
		$license = new WC_Payment_Monitor_License();
		$tier    = $license->get_license_tier();
		return in_array( $tier, array( 'starter', 'pro', 'agency' ), true );
	}

	/**
	 * Get license tier
	 *
	 * @return string License tier.
	 */
	private function get_license_tier() {
		$license = new WC_Payment_Monitor_License();
		return $license->get_license_tier();
	}

	/**
	 * Check if a specific feature is available
	 *
	 * @param string $feature_name Feature name.
	 * @return bool Feature available.
	 */
	private function has_feature( $feature_name ) {
		$license = new WC_Payment_Monitor_License();
		return $license->has_feature( $feature_name );
	}
}
