<?php
/**
 * License validation and management
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WC_Payment_Monitor_License {

	/**
	 * License API endpoint
	 */
	const API_ENDPOINT = 'https://paysentinel.caplaz.com/api/validate-license';

	/**
	 * Option names
	 */
	const OPTION_LICENSE_KEY = 'wc_payment_monitor_license_key';
	const OPTION_LICENSE_STATUS = 'wc_payment_monitor_license_status';
	const OPTION_LICENSE_DATA = 'wc_payment_monitor_license_data';
	const OPTION_LAST_CHECK = 'wc_payment_monitor_license_last_check';

	/**
	 * Initialize hooks
	 */
	public function init_hooks() {
		// Check license on plugin activation
		add_action( 'admin_init', array( $this, 'check_license_on_admin' ) );
		
		// Add admin notices for license status
		add_action( 'admin_notices', array( $this, 'license_admin_notices' ) );
		
		// Daily license check
		add_action( 'wc_payment_monitor_daily_check', array( $this, 'daily_license_check' ) );
		
		// Schedule daily check if not scheduled
		if ( ! wp_next_scheduled( 'wc_payment_monitor_daily_check' ) ) {
			wp_schedule_event( time(), 'daily', 'wc_payment_monitor_daily_check' );
		}
	}

	/**
	 * Validate license key with remote API
	 *
	 * @param string $license_key License key to validate
	 * @param string $site_url Site URL (optional, uses current site if not provided)
	 * @return array Response with 'valid', 'message', and 'data' keys
	 */
	public function validate_license( $license_key, $site_url = '' ) {
		// Sanitize inputs
		$license_key = sanitize_text_field( $license_key );
		$site_url    = $site_url ? esc_url_raw( $site_url ) : get_site_url();

		if ( empty( $license_key ) ) {
			return array(
				'valid'   => false,
				'message' => __( 'License key is required', 'wc-payment-monitor' ),
				'data'    => null,
			);
		}

		// Prepare request
		$body = wp_json_encode(
			array(
				'license_key' => $license_key,
				'site_url'    => $site_url,
			)
		);

		$args = array(
			'body'        => $body,
			'headers'     => array(
				'Content-Type' => 'application/json',
			),
			'timeout'     => 15,
			'httpversion' => '1.1',
			'sslverify'   => true,
		);

		// Make API request
		$response = wp_remote_post( self::API_ENDPOINT, $args );

		// Check for errors
		if ( is_wp_error( $response ) ) {
			return array(
				'valid'   => false,
				'message' => sprintf(
					/* translators: %s: error message */
					__( 'License validation failed: %s', 'wc-payment-monitor' ),
					$response->get_error_message()
				),
				'data'    => null,
			);
		}

		// Parse response
		$response_code = wp_remote_retrieve_response_code( $response );
		$response_body = wp_remote_retrieve_body( $response );
		$data          = json_decode( $response_body, true );

		// Add debug info
		$debug_info = sprintf(
			'HTTP %d, Body: %s',
			$response_code,
			substr( $response_body, 0, 200 )
		);

		// Handle response
		if ( 200 !== $response_code ) {
			return array(
				'valid'      => false,
				'message'    => isset( $data['message'] ) ? $data['message'] : __( 'License validation failed', 'wc-payment-monitor' ),
				'data'       => $data,
				'debug_info' => $debug_info,
			);
		}

		// Check if license is valid based on API response
		// API returns expiration_ts if valid, or error message if invalid
		$is_valid = isset( $data['expiration_ts'] ) && ! empty( $data['expiration_ts'] );
		
		// If there's an error message in response, it's invalid
		if ( isset( $data['error'] ) || isset( $data['message'] ) ) {
			$is_valid = false;
		}

		return array(
			'valid'      => $is_valid,
			'message'    => isset( $data['message'] ) ? $data['message'] : ( $is_valid ? __( 'License is valid', 'wc-payment-monitor' ) : __( 'License is invalid', 'wc-payment-monitor' ) ),
			'data'       => $data,
			'debug_info' => $is_valid ? '' : $debug_info,
		);
	}

	/**
	 * Save license key and validate
	 *
	 * @param string $license_key License key to save and validate
	 * @return array Validation result
	 */
	public function save_and_validate_license( $license_key ) {
		// Validate license
		$result = $this->validate_license( $license_key );

		// Save license key and status
		update_option( self::OPTION_LICENSE_KEY, $license_key );
		update_option( self::OPTION_LICENSE_STATUS, $result['valid'] ? 'valid' : 'invalid' );
		update_option( self::OPTION_LICENSE_DATA, $result['data'] );
		update_option( self::OPTION_LAST_CHECK, current_time( 'timestamp' ) );

		return $result;
	}

	/**
	 * Get stored license key
	 *
	 * @return string License key
	 */
	public function get_license_key() {
		return get_option( self::OPTION_LICENSE_KEY, '' );
	}

	/**
	 * Get license status
	 *
	 * @return string Status: 'valid', 'invalid', or 'unknown'
	 */
	public function get_license_status() {
		return get_option( self::OPTION_LICENSE_STATUS, 'unknown' );
	}

	/**
	 * Get license data
	 *
	 * @return array|null License data from last validation
	 */
	public function get_license_data() {
		return get_option( self::OPTION_LICENSE_DATA, null );
	}

	/**
	 * Check if license is valid
	 *
	 * @return bool True if license is valid
	 */
	public function is_license_valid() {
		return 'valid' === $this->get_license_status();
	}

	/**
	 * Check license on admin pages (once per session)
	 */
	public function check_license_on_admin() {
		// Only check once per day
		$last_check = get_option( self::OPTION_LAST_CHECK, 0 );
		$now        = current_time( 'timestamp' );

		if ( ( $now - $last_check ) < DAY_IN_SECONDS ) {
			return;
		}

		// Get stored license key
		$license_key = $this->get_license_key();

		if ( empty( $license_key ) ) {
			update_option( self::OPTION_LICENSE_STATUS, 'unknown' );
			return;
		}

		// Validate license
		$this->save_and_validate_license( $license_key );
	}

	/**
	 * Daily license check via cron
	 */
	public function daily_license_check() {
		$license_key = $this->get_license_key();

		if ( ! empty( $license_key ) ) {
			$this->save_and_validate_license( $license_key );
		}
	}

	/**
	 * Display admin notices for license status
	 */
	public function license_admin_notices() {
		// Only show on plugin pages
		$screen = get_current_screen();
		if ( ! $screen || false === strpos( $screen->id, 'payment-monitor' ) ) {
			return;
		}

		$status = $this->get_license_status();

		if ( 'unknown' === $status ) {
			?>
			<div class="notice notice-warning is-dismissible">
				<p>
					<strong><?php esc_html_e( 'WooCommerce Payment Monitor:', 'wc-payment-monitor' ); ?></strong>
					<?php
					printf(
						/* translators: %s: settings page link */
						esc_html__( 'Please enter your license key to activate the plugin. %s', 'wc-payment-monitor' ),
						'<a href="' . esc_url( admin_url( 'admin.php?page=wc-payment-monitor-settings' ) ) . '">' . esc_html__( 'Go to Settings', 'wc-payment-monitor' ) . '</a>'
					);
					?>
				</p>
			</div>
			<?php
		} elseif ( 'invalid' === $status ) {
			?>
			<div class="notice notice-error is-dismissible">
				<p>
					<strong><?php esc_html_e( 'WooCommerce Payment Monitor:', 'wc-payment-monitor' ); ?></strong>
					<?php
					printf(
						/* translators: %s: settings page link */
						esc_html__( 'Your license key is invalid or expired. Please update your license key. %s', 'wc-payment-monitor' ),
						'<a href="' . esc_url( admin_url( 'admin.php?page=wc-payment-monitor-settings' ) ) . '">' . esc_html__( 'Go to Settings', 'wc-payment-monitor' ) . '</a>'
					);
					?>
				</p>
			</div>
			<?php
		}
	}

	/**
	 * Deactivate license (remove from options)
	 */
	public function deactivate_license() {
		delete_option( self::OPTION_LICENSE_KEY );
		delete_option( self::OPTION_LICENSE_STATUS );
		delete_option( self::OPTION_LICENSE_DATA );
		delete_option( self::OPTION_LAST_CHECK );

		return array(
			'valid'   => true,
			'message' => __( 'License deactivated successfully', 'wc-payment-monitor' ),
		);
	}
}
