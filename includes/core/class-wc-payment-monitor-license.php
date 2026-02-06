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
	 * License API endpoints
	 */
	public const API_ENDPOINT_ACTIVATE  = 'https://paysentinel.caplaz.com/api/activate-license';
	public const API_ENDPOINT_VALIDATE  = 'https://paysentinel.caplaz.com/api/validate-license';
	public const API_ENDPOINT_SYNC      = 'https://paysentinel.caplaz.com/api/sync';
	public const API_ENDPOINT_ALERTS    = 'https://paysentinel.caplaz.com/api/alerts';

	/**
	 * Option names
	 */
	public const OPTION_LICENSE_KEY            = 'wc_payment_monitor_license_key';
	public const OPTION_LICENSE_STATUS         = 'wc_payment_monitor_license_status';
	public const OPTION_LICENSE_DATA           = 'wc_payment_monitor_license_data';
	public const OPTION_LAST_CHECK             = 'wc_payment_monitor_license_last_check';
	public const OPTION_SITE_REGISTERED        = 'wc_payment_monitor_site_registered';
	public const OPTION_SITE_REGISTRATION_DATA = 'wc_payment_monitor_site_registration_data';
	public const OPTION_SITE_SECRET            = 'wc_payment_monitor_site_secret';
	public const OPTION_SLACK_WORKSPACE        = 'wc_payment_monitor_slack_workspace';

	/**
	 * Gateway limits per tier
	 */
	public const GATEWAY_LIMITS = array(
		'free'    => 1,
		'starter' => 3,
		'pro'     => 999,
		'agency'  => 999,
	);

	/**
	 * Data retention limits (days) per tier
	 */
	public const RETENTION_LIMITS = array(
		'free'    => 7,
		'starter' => 30,
		'pro'     => 90,
		'agency'  => 90,
	);

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

		// Hourly license sync
		add_action( 'wc_payment_monitor_hourly_sync', array( $this, 'hourly_license_sync' ) );

		// Schedule daily check if not scheduled
		if ( ! wp_next_scheduled( 'wc_payment_monitor_daily_check' ) ) {
			wp_schedule_event( time(), 'daily', 'wc_payment_monitor_daily_check' );
		}

		// Schedule hourly sync if not scheduled
		if ( ! wp_next_scheduled( 'wc_payment_monitor_hourly_sync' ) ) {
			wp_schedule_event( time(), 'hourly', 'wc_payment_monitor_hourly_sync' );
		}
	}

	/**
	 * Activate license and register site (Step 1 - No HMAC required)
	 *
	 * @param string $license_key License key to activate
	 * @param string $site_url    Site URL (optional, uses current site if not provided)
	 *
	 * @return array Response with 'success', 'message', 'site_secret', and 'data' keys
	 */
	public function activate_license( $license_key, $site_url = '' ) {
		// Sanitize inputs
		$license_key = sanitize_text_field( $license_key );
		$site_url    = $site_url ? esc_url_raw( $site_url ) : get_site_url();

		if ( empty( $license_key ) ) {
			return array(
				'success' => false,
				'message' => __( 'License key is required', 'wc-payment-monitor' ),
				'data'    => null,
			);
		}

		// Use standard flags to prevent encoding issues
		$body = wp_json_encode(
			array(
				'license_key' => $license_key,
				'site_url'    => $site_url,
			),
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
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

		// Make API request to activation endpoint (no HMAC required)
		$response = wp_remote_post( self::API_ENDPOINT_ACTIVATE, $args );

		// Check for errors
		if ( is_wp_error( $response ) ) {
			return array(
				'success' => false,
				'message' => sprintf(
					/* translators: %s: error message */
					__( 'License activation failed: %s', 'wc-payment-monitor' ),
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
			$user_message = $this->get_user_friendly_error_message( $response_code, $data );
			return array(
				'success'    => false,
				'message'    => $user_message,
				'data'       => $data,
				'debug_info' => $debug_info,
			);
		}

		// Extract site_secret from response
		$site_secret = isset( $data['site_registration']['site_secret'] ) ? 
			sanitize_text_field( $data['site_registration']['site_secret'] ) : null;

		if ( empty( $site_secret ) ) {
			return array(
				'success'    => false,
				'message'    => __( 'Site secret not received from server', 'wc-payment-monitor' ),
				'data'       => $data,
				'debug_info' => $debug_info,
			);
		}

		// Check if site is registered
		$site_registered = isset( $data['site_registration']['registered'] ) ? 
			(bool) $data['site_registration']['registered'] : false;

		return array(
			'success'         => true,
			'site_registered' => $site_registered,
			'site_secret'     => $site_secret,
			'message'         => isset( $data['message'] ) ? $data['message'] : __( 'License activated successfully', 'wc-payment-monitor' ),
			'data'            => $data,
			'debug_info'      => $debug_info,
		);
	}

	/**
	 * Validate license key with remote API (Step 2 - HMAC required)
	 *
	 * @param string $license_key License key to validate
	 * @param string $site_url    Site URL (optional, uses current site if not provided)
	 * @param string $site_secret Optional site secret (if not provided, will retrieve from database)
	 *
	 * @return array Response with 'valid', 'message', and 'data' keys
	 */
	public function validate_license( $license_key, $site_url = '', $site_secret = null ) {
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

		// Get site secret for HMAC (use provided secret or retrieve from database)
		if ( null === $site_secret ) {
			$site_secret = $this->get_site_secret();
		}
		
		if ( empty( $site_secret ) ) {
			return array(
				'valid'   => false,
				'message' => __( 'Site not activated. Please activate your license first.', 'wc-payment-monitor' ),
				'data'    => null,
			);
		}

		// Prepare request body
		$body_array = array(
			'license_key' => $license_key,
			'site_url'    => $site_url,
		);

		// Make authenticated request with HMAC using the provided or retrieved secret
		$response = $this->make_authenticated_request_with_secret(
			self::API_ENDPOINT_VALIDATE,
			'POST',
			$body_array,
			$site_secret,
			$license_key,
			true // include site URL header
		);

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
			$user_message = $this->get_user_friendly_error_message( $response_code, $data );
			return array(
				'valid'      => false,
				'message'    => $user_message,
				'data'       => $data,
				'debug_info' => $debug_info,
			);
		}

		// Check if license is valid based on API response
		$is_valid = true; // 200 response means valid

		// Check if there's an explicit error indicator
		if ( isset( $data['error'] ) ) {
			$is_valid = false;
		}

		// Check expiration if license data is available
		if ( $is_valid && isset( $data['expiration_ts'] ) && ! empty( $data['expiration_ts'] ) ) {
			try {
				$expires_timestamp = strtotime( $data['expiration_ts'] );
				$current_time      = current_time( 'timestamp' );
				if ( $expires_timestamp <= $current_time ) {
					$is_valid = false; // License expired
				}
			} catch ( Exception $e ) {
				// If we can't parse the date, assume it's valid to avoid false negatives
				$is_valid = true;
			}
		}

		return array(
			'valid'      => $is_valid,
			'message'    => isset( $data['message'] ) ? $data['message'] : ( $is_valid ? __( 'License is valid', 'wc-payment-monitor' ) : __( 'License is invalid', 'wc-payment-monitor' ) ),
			'data'       => $data,
			'debug_info' => $debug_info,
		);
	}

	/**
	 * Register site with license on the server (deprecated - now handled by activate_license)
	 *
	 * @param string $license_key License key
	 *
	 * @return array Registration result with 'success' and 'reason' keys
	 */
	private function register_site_with_license( $license_key ) {
		// Site registration is now handled by activate_license endpoint
		// This method is kept for backward compatibility
		$result = $this->activate_license( $license_key );

		return array(
			'success' => $result['success'],
			'reason'  => isset( $result['message'] ) ? $result['message'] : __( 'Site registration completed', 'wc-payment-monitor' ),
		);
	}

	public function save_and_validate_license( $license_key ) {
		$site_url = get_site_url();

		// Step 1: Activate license and get site_secret (no HMAC required)
		$activation_result = $this->activate_license( $license_key, $site_url );

		if ( ! $activation_result['success'] ) {
			// Activation failed
			update_option( self::OPTION_LICENSE_KEY, $license_key );
			update_option( self::OPTION_LICENSE_STATUS, 'invalid' );
			update_option( self::OPTION_LICENSE_DATA, $activation_result['data'] );
			update_option( self::OPTION_LAST_CHECK, current_time( 'timestamp' ) );
			update_option( self::OPTION_SITE_REGISTERED, false );

			return array(
				'valid'   => false,
				'message' => $activation_result['message'],
				'data'    => $activation_result['data'],
			);
		}

		// Extract site_secret from activation
		$site_secret = isset( $activation_result['site_secret'] ) ? $activation_result['site_secret'] : null;
		
		if ( empty( $site_secret ) ) {
			return array(
				'valid'   => false,
				'message' => __( 'Site secret not received from activation. Please try again.', 'wc-payment-monitor' ),
				'data'    => $activation_result['data'],
			);
		}

		// Save site_secret from activation
		update_option( self::OPTION_SITE_SECRET, $site_secret );

		// Save site registration status
		$site_registered = isset( $activation_result['site_registered'] ) ? $activation_result['site_registered'] : false;
		update_option( self::OPTION_SITE_REGISTERED, $site_registered );

		// Step 2: Validate license with HMAC authentication (pass site_secret directly)
		// IMPORTANT: Use the same site_url that was used in activation
		$validation_result = $this->validate_license( $license_key, $site_url, $site_secret );

		// Merge activation and validation data
		$license_data = array_merge(
			isset( $activation_result['data']['license_info'] ) ? $activation_result['data']['license_info'] : array(),
			isset( $validation_result['data'] ) ? $validation_result['data'] : array()
		);

		// Save license key and status
		update_option( self::OPTION_LICENSE_KEY, $license_key );
		update_option( self::OPTION_LICENSE_STATUS, $validation_result['valid'] ? 'valid' : 'invalid' );
		update_option( self::OPTION_LICENSE_DATA, $license_data );
		update_option( self::OPTION_LAST_CHECK, current_time( 'timestamp' ) );

		// Update site registration data
		update_option(
			self::OPTION_SITE_REGISTRATION_DATA,
			array(
				'registered'    => $site_registered,
				'reason'        => $site_registered ? __( 'Site is registered', 'wc-payment-monitor' ) : __( 'Site registration pending', 'wc-payment-monitor' ),
				'registered_at' => $site_registered ? current_time( 'c' ) : null,
				'checked_at'    => current_time( 'timestamp' ),
			)
		);

		// Prepare final result message
		if ( $validation_result['valid'] && $site_registered ) {
			$message = __( 'License activated and validated successfully!', 'wc-payment-monitor' );
		} elseif ( $validation_result['valid'] && ! $site_registered ) {
			$message = __( 'License is valid, but site registration is pending.', 'wc-payment-monitor' );
		} else {
			$message = $validation_result['message'];
		}

		return array(
			'valid'           => $validation_result['valid'],
			'site_registered' => $site_registered,
			'message'         => $message,
			'data'            => $license_data,
		);
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
	 * Get the stored site secret for HMAC signing.
	 *
	 * @return string
	 */
	public function get_site_secret() {
		return get_option( self::OPTION_SITE_SECRET, '' );
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
	 *
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
	 * Hourly license sync via cron
	 */
	public function hourly_license_sync() {
		$license_key = $this->get_license_key();

		if ( ! empty( $license_key ) && $this->is_site_registered() ) {
			$this->sync_license();
		}
	}

	/**
	 * Sync license data with remote API using /sync endpoint
	 *
	 * @return array|WP_Error Sync result
	 */
	public function sync_license() {
		// Ensure site is registered before syncing
		if ( ! $this->is_site_registered() ) {
			return new WP_Error(
				'site_not_registered',
				__( 'Site must be registered before syncing license data.', 'wc-payment-monitor' )
			);
		}

		$response = $this->make_authenticated_request( self::API_ENDPOINT_SYNC, 'GET', null );

		if ( is_wp_error( $response ) ) {
			error_log( 'WC Payment Monitor: License sync failed - ' . $response->get_error_message() );
			return $response;
		}

		$response_code = wp_remote_retrieve_response_code( $response );
		$response_body = wp_remote_retrieve_body( $response );
		$data          = json_decode( $response_body, true );

		// Handle different response codes
		if ( 200 === $response_code ) {
			// Update license data with sync response
			$current_data = $this->get_license_data();

			// Merge sync data with existing license data
			if ( is_array( $current_data ) ) {
				$current_data['plan']       = isset( $data['plan'] ) ? $data['plan'] : ( isset( $current_data['plan'] ) ? $current_data['plan'] : 'free' );
				$current_data['features']   = isset( $data['features'] ) ? $data['features'] : ( isset( $current_data['features'] ) ? $current_data['features'] : array() );
				$current_data['quota']      = isset( $data['quota'] ) ? $data['quota'] : null;
				$current_data['expires_at'] = isset( $data['expires_at'] ) ? $data['expires_at'] : ( isset( $current_data['expiration_ts'] ) ? $current_data['expiration_ts'] : null );
				$current_data['valid']      = isset( $data['valid'] ) ? $data['valid'] : true;
				
				// Ensure integrations are cached in license data too
				if ( isset( $data['integrations'] ) ) {
					$current_data['integrations'] = $data['integrations'];
				}
			} else {
				$current_data = $data;
			}

			// Sync integrations (Standalone options)
			if ( isset( $data['integrations']['slack']['id'] ) ) {
				update_option( 'wc_payment_monitor_slack_workspace', $data['integrations']['slack']['id'] );
				
				// Also update main options for compatibility
				$options = get_option( 'wc_payment_monitor_options', array() );
				$options['alert_slack_workspace'] = $data['integrations']['slack']['id'];
				update_option( 'wc_payment_monitor_options', $options );
			}

			// Update license status
			if ( isset( $data['valid'] ) && $data['valid'] ) {
				update_option( self::OPTION_LICENSE_STATUS, 'valid' );
			}

			update_option( self::OPTION_LICENSE_DATA, $current_data );
			update_option( self::OPTION_LAST_CHECK, current_time( 'timestamp' ) );

			// Sync active integrations
			if ( isset( $data['integrations'] ) && is_array( $data['integrations'] ) ) {
				if ( isset( $data['integrations']['slack']['id'] ) ) {
					update_option( self::OPTION_SLACK_WORKSPACE, sanitize_text_field( $data['integrations']['slack']['id'] ) );
				} else {
					delete_option( self::OPTION_SLACK_WORKSPACE );
				}
			}

			// Update license status based on sync data
			if ( isset( $data['valid'] ) && ! $data['valid'] ) {
				update_option( self::OPTION_LICENSE_STATUS, 'invalid' );
			} else {
				update_option( self::OPTION_LICENSE_STATUS, 'valid' );
			}

			return array(
				'success' => true,
				'message' => __( 'License synced successfully', 'wc-payment-monitor' ),
				'data'    => $data,
			);
		} elseif ( 401 === $response_code ) {
			return new WP_Error(
				'unauthorized',
				__( 'Invalid HMAC signature. Please re-validate your license.', 'wc-payment-monitor' )
			);
		} elseif ( 403 === $response_code ) {
			update_option( self::OPTION_LICENSE_STATUS, 'invalid' );
			return new WP_Error(
				'forbidden',
				__( 'License is invalid or expired.', 'wc-payment-monitor' )
			);
		} else {
			return new WP_Error(
				'sync_failed',
				sprintf(
					/* translators: %d: HTTP status code */
					__( 'License sync failed with HTTP status %d', 'wc-payment-monitor' ),
					$response_code
				)
			);
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
			$debug_info   = '';

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
					<p
						style="margin-top: 10px; padding: 10px; background: #f1f1f1; border-left: 4px solid #dc3232; font-family: monospace; font-size: 12px;">
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
	 * Get user-friendly error message based on HTTP response code and API data
	 *
	 * @param int   $response_code HTTP response code
	 * @param array $data          Decoded JSON response data
	 *
	 * @return string User-friendly error message
	 */
	private function get_user_friendly_error_message( $response_code, $data ) {
		// Check for specific error messages from API
		if ( isset( $data['error'] ) ) {
			switch ( $data['error'] ) {
				case 'License not found':
					return __( 'Invalid license key. Please check your license key and try again.', 'wc-payment-monitor' );

				case 'License expired':
					return __( 'Your license has expired. Please renew your license to continue using premium features.', 'wc-payment-monitor' );

				case 'License suspended':
					return __( 'Your license has been suspended. Please contact support for assistance.', 'wc-payment-monitor' );

				case 'Too many activations':
					return __( 'This license key has reached its activation limit. Please contact support to increase your limit.', 'wc-payment-monitor' );

				case 'Invalid domain':
					return __( 'This license key is not valid for this domain. Please check your license restrictions.', 'wc-payment-monitor' );

				default:
					return sprintf(
						/* translators: %s: error message from API */
						__( 'License validation failed: %s', 'wc-payment-monitor' ),
						$data['error']
					);
			}
		}

		// Handle HTTP status codes
		switch ( $response_code ) {
			case 400:
				return __( 'Invalid license request. Please check your license key format.', 'wc-payment-monitor' );

			case 401:
				return __( 'License authentication failed. Please check your license key.', 'wc-payment-monitor' );

			case 403:
				return __( 'License access denied. Your license may be invalid or expired.', 'wc-payment-monitor' );

			case 404:
				return __( 'License server not found. Please try again later or contact support.', 'wc-payment-monitor' );

			case 429:
				return __( 'Too many license validation attempts. Please wait a few minutes and try again.', 'wc-payment-monitor' );

			case 500:
			case 502:
			case 503:
			case 504:
				return __( 'License server is temporarily unavailable. Please try again later.', 'wc-payment-monitor' );

			default:
				return __( 'Unable to validate license. Please check your internet connection and try again.', 'wc-payment-monitor' );
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
		delete_option( self::OPTION_SITE_SECRET );

		return array(
			'valid'   => true,
			'message' => __( 'License deactivated successfully', 'wc-payment-monitor' ),
		);
	}

	/**
	 * Make an authenticated API request to the PaySentinel SaaS
	 *
	 * @param string $endpoint         Full URL or path
	 * @param string $method           HTTP method
	 * @param array  $body             Request body (array)
	 * @param bool   $include_site_url Whether to include X-PaySentinel-Site-Url header
	 *
	 * @return array|WP_Error Response data or WP_Error
	 */
	public function make_authenticated_request( $endpoint, $method = 'POST', $body = array(), $include_site_url = true ) {
		// Ensure we have a license key and site secret
		$license_key = $this->get_license_key();
		$site_secret = $this->get_site_secret();

		if ( empty( $license_key ) || empty( $site_secret ) ) {
			return new WP_Error(
				'missing_authentication',
				__( 'Authentication details missing. Please re-validate your license.', 'wc-payment-monitor' )
			);
		}

		return $this->make_authenticated_request_with_secret( $endpoint, $method, $body, $site_secret, $license_key, $include_site_url );
	}

	/**
	 * Make an authenticated API request with explicit credentials
	 *
	 * @param string $endpoint         Full URL or path
	 * @param string $method           HTTP method
	 * @param array  $body             Request body (array)
	 * @param string $site_secret      Site secret for HMAC
	 * @param string $license_key      License key
	 * @param bool   $include_site_url Whether to include X-PaySentinel-Site-Url header
	 *
	 * @return array|WP_Error Response data or WP_Error
	 */
	private function make_authenticated_request_with_secret( $endpoint, $method, $body, $site_secret, $license_key, $include_site_url = true ) {
		$timestamp = time();
		
		// JSON encode the body with consistent flags to match signature generation
		$body_json = ! empty( $body ) ? wp_json_encode( $body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) : '';
		
		// Generate signature from the JSON string (not the array)
		$signature = WC_Payment_Monitor_Security::generate_hmac_signature( $body_json, $timestamp, $site_secret );

		$headers = array(
			'Content-Type'              => 'application/json',
			'X-PaySentinel-License-Key' => $license_key,
			'X-PaySentinel-Signature'   => $signature,
			'X-PaySentinel-Timestamp'   => $timestamp,
		);

		// Add site URL header if requested (recommended for most endpoints)
		if ( $include_site_url ) {
			$headers['X-PaySentinel-Site-Url'] = get_site_url();
		}

		$args = array(
			'method'      => $method,
			'headers'     => $headers,
			'timeout'     => 15,
			'httpversion' => '1.1',
			'sslverify'   => true,
		);

		if ( ! empty( $body_json ) ) {
			$args['body'] = $body_json;
		}

		return wp_remote_request( $endpoint, $args );
	}
}
