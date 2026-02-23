<?php
/**
 * Admin AJAX Handler
 *
 * Handles AJAX endpoints for the Payment Monitor plugin admin interface.
 *
 * @package PaySentinel
 * @since 1.0.0
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PaySentinel_Admin_Ajax_Handler
 *
 * Manages AJAX requests from the admin interface.
 */
class PaySentinel_Admin_Ajax_Handler {

	/**
	 * License instance
	 *
	 * @var PaySentinel_License
	 */
	private $license;

	/**
	 * Config instance
	 *
	 * @var PaySentinel_Config
	 */
	private $config;

	/**
	 * Constructor
	 *
	 * @param PaySentinel_License $license License instance.
	 */
	public function __construct( $license ) {
		$this->license = $license;
		$this->config  = PaySentinel_Config::instance();
	}

	/**
	 * Handle Slack test AJAX request
	 *
	 * Sends a test message to the configured Slack workspace to verify integration.
	 */
	public function handle_slack_test() {
		check_ajax_referer( 'paysentinel_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized', 'paysentinel' ) ) );
		}

		$integration_id = $this->config->get_slack_workspace();

		if ( empty( $integration_id ) ) {
			wp_send_json_error( array( 'message' => __( 'No Slack workspace connected', 'paysentinel' ) ) );
		}

		$endpoint = PaySentinel_License::SAAS_URL . '/api/integrations/slack/test';
		$response = $this->license->make_authenticated_request(
			$endpoint,
			'POST',
			array(
				'integration_id' => $integration_id,
				'message'        => __( 'Testing PaySentinel Slack Integration... connection verified!', 'paysentinel' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			wp_send_json_error( array( 'message' => $response->get_error_message() ) );
		}

		$status = wp_remote_retrieve_response_code( $response );
		if ( $status !== 200 ) {
			$body  = json_decode( wp_remote_retrieve_body( $response ), true );
			$error = isset( $body['error'] ) ? $body['error'] : __( 'SaaS error', 'paysentinel' );
			wp_send_json_error( array( 'message' => $error ) );
		}

		wp_send_json_success( array( 'message' => __( 'Test alert sent successfully!', 'paysentinel' ) ) );
	}

	/**
	 * Handle sync integrations AJAX request
	 *
	 * Synchronizes integration data from the PaySentinel SaaS platform.
	 */
	public function handle_sync_integrations() {
		check_ajax_referer( 'paysentinel_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized', 'paysentinel' ) ) );
		}

		$result = $this->license->sync_license();

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( array( 'message' => __( 'Integrations synced from PaySentinel successfully!', 'paysentinel' ) ) );
	}

	/**
	 * Handle validate license AJAX request
	 *
	 * Validates a license key via AJAX without page reload.
	 */
	public function handle_validate_license_ajax() {
		check_ajax_referer( 'paysentinel_validate_license', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized', 'paysentinel' ) ) );
		}

		$license_key = isset( $_POST['license_key'] ) ? sanitize_text_field( $_POST['license_key'] ) : '';

		if ( empty( $license_key ) ) {
			wp_send_json_error( array( 'message' => __( 'License key is required', 'paysentinel' ) ) );
		}

		$result = $this->license->save_and_validate_license( $license_key );

		if ( $result['valid'] ) {
			wp_send_json_success( array( 'message' => __( 'License validated successfully!', 'paysentinel' ) ) );
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
			wp_die( __( 'Unauthorized', 'paysentinel' ) );
		}

		check_admin_referer( 'paysentinel_save_license' );

		$license_key = isset( $_POST['license_key'] ) ? sanitize_text_field( $_POST['license_key'] ) : '';

		if ( ! empty( $license_key ) ) {
			$result  = $this->license->save_and_validate_license( $license_key );
			$type    = $result['valid'] ? 'success' : 'error';
			$message = $result['message'];
		} else {
			$this->license->deactivate_license();
			$type    = 'info';
			$message = __( 'License key removed.', 'paysentinel' );
		}

		wp_redirect( admin_url( 'admin.php?page=paysentinel-settings&tab=license&message=' . urlencode( $message ) . '&type=' . $type ) );
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
					'paysentinel_settings',
					'slack_disconnect_error',
					__( 'Invalid security nonce. Please try again.', 'paysentinel' ),
					'error'
				);
				return;
			}

			// Get current integration ID
			$integration_id = $this->config->get_slack_workspace();

			if ( ! empty( $integration_id ) ) {
				// Call SaaS to remove tokens
				$endpoint = PaySentinel_License::SAAS_URL . '/api/integrations/slack';
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
				'paysentinel_settings',
				'slack_disconnect_success',
				__( 'Slack workspace disconnected successfully.', 'paysentinel' ),
				'updated'
			);

			wp_redirect( admin_url( 'admin.php?page=paysentinel-settings&tab=notifications' ) );
			exit;
		}

		// Handle Auth Callback
		if ( ! isset( $_GET['integration_id'] ) || ( ! isset( $_GET['slack_auth'] ) && ! isset( $_GET['success'] ) ) ) {
			return;
		}

		if ( ! isset( $_GET['state'] ) || ! wp_verify_nonce( $_GET['state'], 'slack_auth_nonce' ) ) {
			$received_state = isset( $_GET['state'] ) ? sanitize_text_field( $_GET['state'] ) : 'none';
			add_settings_error(
				'paysentinel_options',
				'slack_auth_error',
				sprintf( __( 'Invalid Slack auth state (Received: %s). Please try again.', 'paysentinel' ), $received_state ),
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
			'paysentinel_options',
			'slack_auth_success',
			__( 'Slack workspace connected successfully!', 'paysentinel' ),
			'updated'
		);

		// Clean up the URL
		wp_redirect( admin_url( 'admin.php?page=paysentinel-settings&tab=notifications' ) );
		exit;
	}
}
