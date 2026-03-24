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

		$license_key = isset( $_POST['license_key'] ) ? sanitize_text_field( wp_unslash( $_POST['license_key'] ) ) : '';

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
			wp_die( esc_html__( 'Unauthorized', 'paysentinel' ) );
		}

		check_admin_referer( 'paysentinel_save_license' );

		$license_key = isset( $_POST['license_key'] ) ? sanitize_text_field( wp_unslash( $_POST['license_key'] ) ) : '';

		if ( ! empty( $license_key ) ) {
			$result  = $this->license->save_and_validate_license( $license_key );
			$type    = $result['valid'] ? 'success' : 'error';
			$message = $result['message'];
		} else {
			$this->license->deactivate_license();
			$type    = 'info';
			$message = __( 'License key removed.', 'paysentinel' );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=paysentinel-settings&tab=license&message=' . urlencode( $message ) . '&type=' . $type ) );
		exit;
	}
}
