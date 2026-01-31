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
	const OPTION_SITE_REGISTERED = 'wc_payment_monitor_site_registered';
	const OPTION_SITE_REGISTRATION_DATA = 'wc_payment_monitor_site_registration_data';

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
	 * @param string $action Action to perform ('validate' or 'register_site')
	 * @return array Response with 'valid', 'message', and 'data' keys
	 */
	public function validate_license( $license_key, $site_url = '', $action = 'validate' ) {
		// Sanitize inputs
		$license_key = sanitize_text_field( $license_key );
		$site_url    = $site_url ? esc_url_raw( $site_url ) : get_site_url();

		// Ensure site_url is a valid HTTPS URL
		$site_url = $this->normalize_site_url( $site_url );

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
				'action'      => $action,
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
			substr( $response_body, 0, 500 )
		);

		// Handle response
		if ( 200 !== $response_code ) {
			return array(
				'valid'      => false,
				'message'    => isset( $data['message'] ) ? $data['message'] : sprintf( __( 'HTTP %d: License validation failed', 'wc-payment-monitor' ), $response_code ),
				'data'       => $data,
				'debug_info' => $debug_info,
			);
		}

		// Check if license is valid based on API response
		// API returns expiration_ts if valid, or error message if invalid
		$is_valid = isset( $data['expiration_ts'] ) && ! empty( $data['expiration_ts'] );

		// If there's an error message in response, it's invalid
		if ( isset( $data['error'] ) || ( isset( $data['message'] ) && ! $is_valid ) ) {
			$is_valid = false;
		}

		// Handle site registration response
		$site_registered = false;
		$site_registration_reason = '';

		if ( $is_valid && isset( $data['site_registered'] ) ) {
			$site_registered = (bool) $data['site_registered'];
			if ( isset( $data['reason'] ) ) {
				$site_registration_reason = $data['reason'];
			}
		}

		return array(
			'valid'                   => $is_valid,
			'site_registered'         => $site_registered,
			'site_registration_reason' => $site_registration_reason,
			'message'                 => isset( $data['message'] ) ? $data['message'] : ( $is_valid ? __( 'License is valid', 'wc-payment-monitor' ) : __( 'License is invalid', 'wc-payment-monitor' ) ),
			'data'                    => $data,
			'debug_info'              => $debug_info,
		);
	}

	/**
	 * Register site with license on the server
	 *
	 * @param string $license_key License key
	 * @return array Registration result with 'success' and 'reason' keys
	 */
	private function register_site_with_license( $license_key ) {
		$result = $this->validate_license( $license_key, '', 'register_site' );

		if ( $result['valid'] && isset( $result['site_registered'] ) && $result['site_registered'] ) {
			return array(
				'success' => true,
				'reason'  => isset( $result['message'] ) ? $result['message'] : 'Site registered successfully',
			);
		}

		return array(
			'success' => false,
			'reason'  => isset( $result['message'] ) ? $result['message'] : 'Site registration failed',
		);
	}

	/**
	 * Normalize site URL to ensure it's a valid HTTPS URL
	 *
	 * @param string $site_url The site URL to normalize
	 * @return string Normalized HTTPS URL
	 */
	private function normalize_site_url( $site_url ) {
		// Parse the URL
		$parsed = parse_url( $site_url );

		// If parsing failed, try to create a basic HTTPS URL
		if ( ! $parsed || ! isset( $parsed['host'] ) ) {
			// For localhost/development environments, use a placeholder
			if ( strpos( $site_url, 'localhost' ) !== false || strpos( $site_url, '127.0.0.1' ) !== false ) {
				return 'https://localhost';
			}
			// For other cases, try to extract domain-like string
			$host = preg_replace( '/^https?:\/\//', '', $site_url );
			$host = preg_replace( '/\/.*$/', '', $host );
			if ( ! empty( $host ) ) {
				return 'https://' . $host;
			}
			// Last resort fallback
			return 'https://example.com';
		}

		// Ensure HTTPS scheme
		$scheme = isset( $parsed['scheme'] ) ? $parsed['scheme'] : 'https';
		if ( $scheme === 'http' ) {
			$scheme = 'https';
		}

		// Rebuild the URL
		$normalized = $scheme . '://' . $parsed['host'];

		// Add port if specified and not default
		if ( isset( $parsed['port'] ) ) {
			if ( ( $scheme === 'https' && $parsed['port'] !== 443 ) || ( $scheme === 'http' && $parsed['port'] !== 80 ) ) {
				$normalized .= ':' . $parsed['port'];
			}
		}

		// Add path if specified
		if ( isset( $parsed['path'] ) ) {
			$normalized .= $parsed['path'];
		}

		// Add query if specified
		if ( isset( $parsed['query'] ) ) {
			$normalized .= '?' . $parsed['query'];
		}

		// Add fragment if specified
		if ( isset( $parsed['fragment'] ) ) {
			$normalized .= '#' . $parsed['fragment'];
		}

		// Final validation
		if ( filter_var( $normalized, FILTER_VALIDATE_URL ) ) {
			return $normalized;
		}

		// If still invalid, return a basic HTTPS URL
		return 'https://' . $parsed['host'];
	}

	public function save_and_validate_license( $license_key ) {
		// Validate license
		$result = $this->validate_license( $license_key );

		// For valid licenses, if site is not registered, try to register it
		if ( $result['valid'] && ! $result['site_registered'] ) {
			$registration_result = $this->register_site_with_license( $license_key );
			if ( $registration_result['success'] ) {
				$result['site_registered'] = true;
				$result['site_registration_reason'] = $registration_result['reason'];
				$result['message'] = __( 'License validated and site registered successfully!', 'wc-payment-monitor' );
			} else {
				// Site registration failed, but license is still valid
				$result['site_registered'] = false;
				$result['site_registration_reason'] = $registration_result['reason'];
				$result['message'] = __( 'License is valid, but site registration failed. Some features may not work properly.', 'wc-payment-monitor' );
			}
		} elseif ( $result['valid'] && $result['site_registered'] ) {
			$result['message'] = __( 'License validated and site is registered!', 'wc-payment-monitor' );
		}

		// Save license key and status
		update_option( self::OPTION_LICENSE_KEY, $license_key );
		update_option( self::OPTION_LICENSE_STATUS, $result['valid'] ? 'valid' : 'invalid' );
		update_option( self::OPTION_LICENSE_DATA, $result['data'] );
		update_option( self::OPTION_LAST_CHECK, current_time( 'timestamp' ) );

		// Save site registration status
		update_option( self::OPTION_SITE_REGISTERED, $result['site_registered'] );
		update_option( self::OPTION_SITE_REGISTRATION_DATA, array(
			'registered' => $result['site_registered'],
			'reason'     => isset( $result['site_registration_reason'] ) ? $result['site_registration_reason'] : '',
			'registered_at' => $result['site_registered'] ? current_time( 'c' ) : null,
			'checked_at' => current_time( 'timestamp' ),
		) );

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
	 * Check if site is registered with license
	 *
	 * @return bool True if site is registered
	 */
	public function is_site_registered() {
		return (bool) get_option( self::OPTION_SITE_REGISTERED, false );
	}

	/**
	 * Get site registration data
	 *
	 * @return array|null Site registration data
	 */
	public function get_site_registration_data() {
		return get_option( self::OPTION_SITE_REGISTRATION_DATA, null );
	}

	/**
	 * Get site registration status with details
	 *
	 * @return array Site registration status
	 */
	public function get_site_registration_status() {
		$registration_data = $this->get_site_registration_data();
		
		if ( ! $registration_data ) {
			return array(
				'registered' => false,
				'reason'     => 'not_checked',
				'checked_at' => null,
			);
		}

		return $registration_data;
	}

	/**
	 * Get license tier/plan
	 *
	 * @return string Tier: free, starter, pro, agency
	 */
	public function get_license_tier() {
		if ( 'valid' !== $this->get_license_status() ) {
			return 'free';
		}

		$license_data = $this->get_license_data();
		
		if ( ! $license_data || ! isset( $license_data['plan'] ) ) {
			return 'free';
		}
		
		return strtolower( $license_data['plan'] );
	}

	/**
	 * Check if a specific feature is available in current license tier
	 *
	 * @param string $feature_name Feature name (e.g., 'sms_alerts', 'slack_alerts')
	 * @return bool|int Feature available (true/false or numeric limit)
	 */
	public function has_feature( $feature_name ) {
		if ( 'valid' !== $this->get_license_status() ) {
			return false;
		}

		$license_data = $this->get_license_data();
		
		if ( ! $license_data || ! isset( $license_data['features'] ) ) {
			return false;
		}
		
		if ( ! isset( $license_data['features'][ $feature_name ] ) ) {
			return false;
		}

		return $license_data['features'][ $feature_name ];
	}

	/**
	 * Get SMS quota information
	 *
	 * @return array|null Quota info with 'limit', 'used', 'remaining', 'reset_date'
	 */
	public function get_sms_quota() {
		if ( 'valid' !== $this->get_license_status() ) {
			return null;
		}

		$license_data = $this->get_license_data();
		
		if ( ! $license_data || ! isset( $license_data['quota'] ) ) {
			return null;
		}
		
		return $license_data['quota'];
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
			$license_data = $this->get_license_data();
			$debug_info = '';
			
			if ( $license_data && isset( $license_data['debug_info'] ) ) {
				$debug_info = $license_data['debug_info'];
			}
			
			?>
			<div class="notice notice-error is-dismissible">
				<p>
					<strong><?php esc_html_e( 'WooCommerce Payment Monitor:', 'wc-payment-monitor' ); ?></strong>
					<?php
					printf(
						/* translators: %s: settings page link */
						esc_html__( 'Your license key is invalid or expired. Please check your license key and try again. %s', 'wc-payment-monitor' ),
						'<a href="' . esc_url( admin_url( 'admin.php?page=wc-payment-monitor-settings' ) ) . '">' . esc_html__( 'Go to Settings', 'wc-payment-monitor' ) . '</a>'
					);
					?>
				</p>
				<?php if ( ! empty( $debug_info ) ) : ?>
				<p style="margin-top: 10px; padding: 10px; background: #f1f1f1; border-left: 4px solid #dc3232; font-family: monospace; font-size: 12px;">
					<strong><?php esc_html_e( 'Debug Information:', 'wc-payment-monitor' ); ?></strong><br>
					<?php echo esc_html( $debug_info ); ?>
				</p>
				<?php endif; ?>
			</div>
			<?php
		} elseif ( 'valid' === $status && ! $this->is_site_registered() ) {
			?>
			<div class="notice notice-warning is-dismissible">
				<p>
					<strong><?php esc_html_e( 'WooCommerce Payment Monitor:', 'wc-payment-monitor' ); ?></strong>
					<?php esc_html_e( 'Your license is valid, but this site is not registered. Alerts will not be sent until the site is registered with your license.', 'wc-payment-monitor' ); ?>
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
		delete_option( self::OPTION_SITE_REGISTERED );
		delete_option( self::OPTION_SITE_REGISTRATION_DATA );

		return array(
			'valid'   => true,
			'message' => __( 'License deactivated successfully', 'wc-payment-monitor' ),
		);
	}
}
