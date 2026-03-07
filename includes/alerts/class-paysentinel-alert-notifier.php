<?php
/**
 * Alert Notifier Class
 *
 * Handles sending notifications through various channels (email, Slack, etc.).
 *
 * @package PaySentinel
 * @since 1.0.0
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * PaySentinel_Alert_Notifier class
 *
 * Responsible for sending alert notifications through configured channels
 * and managing notification delivery.
 */
class PaySentinel_Alert_Notifier {




	/**
	 * Template manager instance
	 *
	 * @var PaySentinel_Alert_Template_Manager
	 */
	private $template_manager;

	/**
	 * Database instance
	 *
	 * @var PaySentinel_Database
	 */
	private $database;

	/**
	 * Constructor
	 *
	 * @param PaySentinel_Alert_Template_Manager $template_manager Template manager instance.
	 * @param PaySentinel_Database               $database         Database instance.
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
		$settings = get_option( 'paysentinel_settings', array() );

		// Check if site is registered - alerts only work for registered sites
		$license = new PaySentinel_License();
		if ( ! $license->is_site_registered() ) {
			error_log( 'PaySentinel: Cannot send alert - site not registered with license' );
			return false;
		}

		// Send through API so they appear in the SaaS dashboard - SaaS will handle all email/slack channel deliveries
		return $this->send_to_api( $alert_data, $settings );
	}

	/**
	 * Send alert to API for central logging and premium channel delivery
	 *
	 * @param array $alert_data Alert data.
	 * @param array $settings   Plugin settings.
	 * @return bool Success.
	 */
	private function send_to_api( $alert_data, $settings ) {
		$license     = new PaySentinel_License();
		$license_key = $license->get_license_key();

		if ( empty( $license_key ) ) {
			error_log( 'PaySentinel: Cannot send alert to API - no license key' );
			return false;
		}

		// Check if site is registered - alerts only work for registered sites
		if ( ! $license->is_site_registered() ) {
			error_log( 'PaySentinel: Cannot send alert to API - site not registered with license' );
			return false;
		}

		// Prepare alert payload according to API spec
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

		// If specific channels are requested, pass them. Valid values: SLACK, EMAIL, DISCORD, TEAMS.
		// Omitting channels causes the SaaS to deliver to all enabled channels for the account.
		if ( ! empty( $alert_data['channels'] ) && is_array( $alert_data['channels'] ) ) {
			$payload['channels'] = $alert_data['channels'];
		}

		// Send to API
		$license  = new PaySentinel_License();
		$response = $license->make_authenticated_request(
			PaySentinel_License::API_ENDPOINT_ALERTS,
			'POST',
			$payload,
			true
		);

		if ( is_wp_error( $response ) ) {
			error_log( 'PaySentinel API Error: ' . $response->get_error_message() );
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
			error_log( 'PaySentinel: Alert API error (400) - ' . $error_msg );
			return false;
		} elseif ( 401 === $response_code ) {
			// Unauthorized - invalid HMAC signature
			error_log( 'PaySentinel: Invalid HMAC signature for API alerts' );
			return false;
		} elseif ( 403 === $response_code ) {
			// Quota exceeded or invalid license
			$error_msg = isset( $response_data['error'] ) ? $response_data['error'] : 'Quota exceeded or invalid license';
			error_log( 'PaySentinel: ' . $error_msg );
			update_option( 'paysentinel_quota_exceeded', true );
			return false;
		} elseif ( 429 === $response_code ) {
			// Rate limit exceeded
			$retry_after = wp_remote_retrieve_header( $response, 'Retry-After' );
			error_log( 'PaySentinel: Rate limit exceeded. Retry after: ' . ( $retry_after ? $retry_after . ' seconds' : 'unknown' ) );
			return false;
		} elseif ( 502 === $response_code ) {
			// Integration service error (Slack/Email provider failure)
			error_log( 'PaySentinel: Integration service error (Slack/Email provider failure)' );
			return false;
		} else {
			// Other error
			error_log( 'PaySentinel API Error: HTTP ' . $response_code . ' - ' . $response_body );
			return false;
		}
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
		$settings = get_option( 'paysentinel_settings', array() );
		$settings[ PaySentinel_Settings_Constants::ALERT_SLACK_WORKSPACE ] = $webhook_url;
		$alert_data['channels'] = array( 'SLACK' );
		return $this->send_to_api( $alert_data, $settings );
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
			error_log( 'PaySentinel: Slack webhook URL not configured' );
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
			error_log( 'PaySentinel Slack Error: ' . $response->get_error_message() );
			return false;
		}

		$response_code = wp_remote_retrieve_response_code( $response );

		if ( $response_code >= 200 && $response_code < 300 ) {
			return true;
		} else {
			$response_body = wp_remote_retrieve_body( $response );
			error_log( 'PaySentinel Slack Error: HTTP ' . $response_code . ' - ' . $response_body );
			return false;
		}
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
				'message' => __( 'Premium license required for Slack notifications', 'paysentinel' ),
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
				? __( 'Test Slack message sent successfully', 'paysentinel' )
				: __( 'Failed to send test Slack message. Please check your webhook URL.', 'paysentinel' ),
		);
	}

	/**
	 * Check if premium features are available
	 *
	 * @return bool Premium features available.
	 */
	private function is_premium_feature_available() {
		$license = new PaySentinel_License();
		$tier    = $license->get_license_tier();
		return in_array( $tier, array( 'starter', 'pro', 'agency' ), true );
	}

	/**
	 * Get license tier
	 *
	 * @return string License tier.
	 */
	private function get_license_tier() {
		$license = new PaySentinel_License();
		return $license->get_license_tier();
	}

	/**
	 * Check if a specific feature is available
	 *
	 * @param string $feature_name Feature name.
	 * @return bool Feature available.
	 */
	private function has_feature( $feature_name ) {
		$license = new PaySentinel_License();
		return $license->has_feature( $feature_name );
	}
}
