<?php
/**
 * Admin AJAX Handler
 *
 * Handles AJAX endpoints for the Payment Monitor plugin admin interface.
 *
 * @package WC_Payment_Monitor
 * @since 1.0.0
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WC_Payment_Monitor_Admin_Ajax_Handler
 *
 * Manages AJAX requests from the admin interface.
 */
class WC_Payment_Monitor_Admin_Ajax_Handler {

	/**
	 * License instance
	 *
	 * @var WC_Payment_Monitor_License
	 */
	private $license;

	/**
	 * Config instance
	 *
	 * @var WC_Payment_Monitor_Config
	 */
	private $config;

	/**
	 * Constructor
	 *
	 * @param WC_Payment_Monitor_License $license License instance.
	 */
	public function __construct( $license ) {
		$this->license = $license;
		$this->config  = WC_Payment_Monitor_Config::instance();
	}

	/**
	 * Handle Slack test AJAX request
	 *
	 * Sends a test message to the configured Slack workspace to verify integration.
	 */
	public function handle_slack_test() {
		check_ajax_referer( 'wc_payment_monitor_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized', 'wc-payment-monitor' ) ) );
		}

		$integration_id = $this->config->get_slack_workspace();

		if ( empty( $integration_id ) ) {
			wp_send_json_error( array( 'message' => __( 'No Slack workspace connected', 'wc-payment-monitor' ) ) );
		}

		$endpoint = WC_Payment_Monitor_License::SAAS_URL . '/api/integrations/slack/test';
		$response = $this->license->make_authenticated_request(
			$endpoint,
			'POST',
			array(
				'integration_id' => $integration_id,
				'message'        => __( 'Testing PaySentinel Slack Integration... connection verified!', 'wc-payment-monitor' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			wp_send_json_error( array( 'message' => $response->get_error_message() ) );
		}

		$status = wp_remote_retrieve_response_code( $response );
		if ( $status !== 200 ) {
			$body  = json_decode( wp_remote_retrieve_body( $response ), true );
			$error = isset( $body['error'] ) ? $body['error'] : __( 'SaaS error', 'wc-payment-monitor' );
			wp_send_json_error( array( 'message' => $error ) );
		}

		wp_send_json_success( array( 'message' => __( 'Test alert sent successfully!', 'wc-payment-monitor' ) ) );
	}

	/**
	 * Handle sync integrations AJAX request
	 *
	 * Synchronizes integration data from the PaySentinel SaaS platform.
	 */
	public function handle_sync_integrations() {
		check_ajax_referer( 'wc_payment_monitor_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized', 'wc-payment-monitor' ) ) );
		}

		$result = $this->license->sync_license();

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( array( 'message' => __( 'Integrations synced from PaySentinel successfully!', 'wc-payment-monitor' ) ) );
	}

	/**
	 * Handle validate license AJAX request
	 *
	 * Validates a license key via AJAX without page reload.
	 */
	public function handle_validate_license_ajax() {
		check_ajax_referer( 'wc_payment_monitor_validate_license', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized', 'wc-payment-monitor' ) ) );
		}

		$license_key = isset( $_POST['license_key'] ) ? sanitize_text_field( $_POST['license_key'] ) : '';

		if ( empty( $license_key ) ) {
			wp_send_json_error( array( 'message' => __( 'License key is required', 'wc-payment-monitor' ) ) );
		}

		$result = $this->license->save_and_validate_license( $license_key );

		if ( $result['valid'] ) {
			wp_send_json_success( array( 'message' => __( 'License validated successfully!', 'wc-payment-monitor' ) ) );
		} else {
			wp_send_json_error( array( 'message' => $result['message'] ) );
		}
	}

	/**
	 * Handle save license POST request
	 *
	 * Saves and validates a license key from a form submission.
	 */
	public function handle_save_license() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( __( 'Unauthorized', 'wc-payment-monitor' ) );
		}

		check_admin_referer( 'wc_payment_monitor_save_license' );

		$license_key = isset( $_POST['license_key'] ) ? sanitize_text_field( $_POST['license_key'] ) : '';

		if ( ! empty( $license_key ) ) {
			$result  = $this->license->save_and_validate_license( $license_key );
			$type    = $result['valid'] ? 'success' : 'error';
			$message = $result['message'];
		} else {
			$this->license->deactivate_license();
			$type    = 'info';
			$message = __( 'License key removed.', 'wc-payment-monitor' );
		}

		wp_redirect( admin_url( 'admin.php?page=wc-payment-monitor-settings&tab=license&message=' . urlencode( $message ) . '&type=' . $type ) );
		exit;
	}

	/**
	 * Handle Slack OAuth callback and disconnection
	 *
	 * Processes Slack authentication callbacks and disconnection requests.
	 */
	public function handle_slack_callback() {
		// Handle Disconnection
		if ( isset( $_GET['slack_disconnect'] ) ) {
			if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( $_GET['_wpnonce'], 'slack_disconnect_nonce' ) ) {
				add_settings_error(
					'wc_payment_monitor_settings',
					'slack_disconnect_error',
					__( 'Invalid security nonce. Please try again.', 'wc-payment-monitor' ),
					'error'
				);
				return;
			}

			// Get current integration ID
			$integration_id = $this->config->get_slack_workspace();

			if ( ! empty( $integration_id ) ) {
				// Call SaaS to remove tokens
				$endpoint = WC_Payment_Monitor_License::SAAS_URL . '/api/integrations/slack';
				$this->license->make_authenticated_request(
					$endpoint,
					'DELETE',
					array(
						'integration_id' => $integration_id,
					),
					true
				);
			}

			$this->config->clear_slack_workspace();

			add_settings_error(
				'wc_payment_monitor_settings',
				'slack_disconnect_success',
				__( 'Slack workspace disconnected successfully.', 'wc-payment-monitor' ),
				'updated'
			);

			wp_redirect( admin_url( 'admin.php?page=wc-payment-monitor-settings&tab=notifications' ) );
			exit;
		}

		// Handle Auth Callback
		if ( ! isset( $_GET['integration_id'] ) || ( ! isset( $_GET['slack_auth'] ) && ! isset( $_GET['success'] ) ) ) {
			return;
		}

		if ( ! isset( $_GET['state'] ) || ! wp_verify_nonce( $_GET['state'], 'slack_auth_nonce' ) ) {
			$received_state = isset( $_GET['state'] ) ? sanitize_text_field( $_GET['state'] ) : 'none';
			add_settings_error(
				'wc_payment_monitor_options',
				'slack_auth_error',
				sprintf( __( 'Invalid Slack auth state (Received: %s). Please try again.', 'wc-payment-monitor' ), $received_state ),
				'error'
			);
			return;
		}

		$integration_id = sanitize_text_field( $_GET['integration_id'] );
		$this->config->set_slack_workspace( $integration_id );

		// Also sync into main options array for compatibility
		$options                          = $this->config->get_all();
		$options['alert_slack_workspace'] = $integration_id;
		$this->config->update_all( $options );

		add_settings_error(
			'wc_payment_monitor_options',
			'slack_auth_success',
			__( 'Slack workspace connected successfully!', 'wc-payment-monitor' ),
			'updated'
		);

		// Clean up the URL
		wp_redirect( admin_url( 'admin.php?page=wc-payment-monitor-settings&tab=notifications' ) );
		exit;
	}
}
