<?php

/**
 * Security utilities and validation class
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WC_Payment_Monitor_Security {

	/**
	 * Encryption algorithm
	 */
	public const ENCRYPTION_METHOD = 'AES-256-CBC';

	/**
	 * Sensitive data fields to exclude
	 */
	private static $sensitive_fields = array(
		'password',
		'api_key',
		'secret_key',
		'token',
		'access_token',
		'refresh_token',
		'credential',
		'payment_token',
		'card_number',
		'cvv',
		'exp_month',
		'exp_year',
		'pin',
		'ssn',
		'bank_account',
		'routing_number',
	);

	/**
	 * Encrypt sensitive data using WordPress native functions
	 *
	 * @param string $data Data to encrypt
	 *
	 * @return string|false Encrypted data or false on failure
	 */
	public static function encrypt_credential( $data ) {
		if ( empty( $data ) ) {
			return false;
		}

		// Get encryption key from WordPress
		$key = self::get_encryption_key();
		if ( ! $key ) {
			return false;
		}

		try {
			// Generate IV
			$iv = openssl_random_pseudo_bytes( openssl_cipher_iv_length( self::ENCRYPTION_METHOD ) );

			if ( $iv === false ) {
				return false;
			}

			// Encrypt data
			$encrypted = openssl_encrypt(
				$data,
				self::ENCRYPTION_METHOD,
				$key,
				OPENSSL_RAW_DATA,
				$iv
			);

			if ( $encrypted === false ) {
				return false;
			}

			// Combine IV and encrypted data, then base64 encode
			$combined = $iv . $encrypted;
			return base64_encode( $combined );
		} catch ( Exception $e ) {
			return false;
		}
	}

	/**
	 * Decrypt sensitive data
	 *
	 * @param string $encrypted_data Encrypted data to decrypt
	 *
	 * @return string|false Decrypted data or false on failure
	 */
	public static function decrypt_credential( $encrypted_data ) {
		if ( empty( $encrypted_data ) ) {
			return false;
		}

		// Get encryption key
		$key = self::get_encryption_key();
		if ( ! $key ) {
			return false;
		}

		try {
			// Base64 decode
			$combined = base64_decode( $encrypted_data, true );
			if ( $combined === false ) {
				return false;
			}

			// Extract IV
			$iv_length = openssl_cipher_iv_length( self::ENCRYPTION_METHOD );
			$iv        = substr( $combined, 0, $iv_length );
			$encrypted = substr( $combined, $iv_length );

			// Decrypt
			$decrypted = openssl_decrypt(
				$encrypted,
				self::ENCRYPTION_METHOD,
				$key,
				OPENSSL_RAW_DATA,
				$iv
			);

			if ( $decrypted === false ) {
				return false;
			}

			return $decrypted;
		} catch ( Exception $e ) {
			return false;
		}
	}

	/**
	 * Generate HMAC signature for SaaS API requests
	 *
	 * @param array|string $payload     Request body or empty string
	 * @param int          $timestamp   Current Unix timestamp
	 * @param string       $site_secret The site secret from license validation
	 *
	 * @return string HMAC-SHA256 signature
	 */
	public static function generate_hmac_signature( $payload, $timestamp, $site_secret ) {
		$payload_string = '';

		if ( ! empty( $payload ) ) {
			if ( is_array( $payload ) ) {
				// Sort keys to ensure consistent signatures
				self::recursive_ksort( $payload );
				$payload_string = json_encode( $payload );
			} else {
				$payload_string = (string) $payload;
			}
		}

		$data_to_sign = $timestamp . '.' . $payload_string;

		return hash_hmac( 'sha256', $data_to_sign, $site_secret );
	}

	/**
	 * Recursively sort array keys
	 *
	 * @param array $array Array to sort
	 */
	private static function recursive_ksort( &$array ) {
		ksort( $array );
		foreach ( $array as &$value ) {
			if ( is_array( $value ) ) {
				self::recursive_ksort( $value );
			}
		}
	}

	/**
	 * Get or generate encryption key
	 * Uses WordPress AUTH_KEY as basis, creates unique key per site
	 *
	 * @return string|false Encryption key or false on failure
	 */
	private static function get_encryption_key() {
		// Try to get from transient first (performance)
		$cached_key = get_transient( 'wc_payment_monitor_encryption_key' );
		if ( $cached_key !== false ) {
			return $cached_key;
		}

		// Generate key based on WordPress constants
		if ( ! defined( 'AUTH_KEY' ) || empty( AUTH_KEY ) ) {
			return false;
		}

		// Create a consistent key based on site URL and AUTH_KEY
		$key = hash( 'sha256', AUTH_KEY . get_site_url(), true );

		// Cache for performance (but not persistently)
		set_transient( 'wc_payment_monitor_encryption_key', $key, HOUR_IN_SECONDS );

		return $key;
	}

	/**
	 * Check if user has required WordPress capability
	 *
	 * @param string $capability WordPress capability to check
	 * @param int    $user_id    Optional user ID (defaults to current user)
	 *
	 * @return bool True if user has capability, false otherwise
	 */
	public static function check_user_capability( $capability, $user_id = 0 ) {
		if ( ! $user_id ) {
			$user_id = get_current_user_id();
		}

		if ( ! $user_id ) {
			return false;
		}

		// Use WordPress user_can function for proper capability checking
		return user_can( $user_id, $capability );
	}

	/**
	 * Validate and sanitize SQL query parameters
	 * Uses prepared statements for SQL injection prevention
	 *
	 * @param string $query  SQL query with placeholders
	 * @param array  $params Parameters to bind to query
	 *
	 * @return string|WP_Error Prepared query or error
	 */
	public static function prepare_sql_query( $query, $params = array() ) {
		global $wpdb;

		// Validate inputs
		if ( empty( $query ) ) {
			return new WP_Error(
				'empty_query',
				'SQL query cannot be empty'
			);
		}

		if ( ! is_array( $params ) ) {
			$params = array();
		}

		// Use WordPress $wpdb->prepare for safe SQL preparation
		try {
			if ( empty( $params ) ) {
				return $query;
			}

			// Prepare query with parameters
			$prepared = $wpdb->prepare( $query, ...$params );

			if ( $prepared === false ) {
				return new WP_Error(
					'query_preparation_failed',
					'Failed to prepare SQL query'
				);
			}

			return $prepared;
		} catch ( Exception $e ) {
			return new WP_Error(
				'query_preparation_error',
				'Error preparing SQL query: ' . $e->getMessage()
			);
		}
	}

	/**
	 * Execute prepared SQL query safely
	 *
	 * @param string $query  SQL query with placeholders
	 * @param array  $params Parameters to bind
	 * @param string $output Optional output type (OBJECT, ARRAY_A, ARRAY_N)
	 *
	 * @return array|object|null Query results or null on failure
	 */
	public static function execute_query( $query, $params = array(), $output = OBJECT ) {
		global $wpdb;

		try {
			$prepared = self::prepare_sql_query( $query, $params );

			if ( is_wp_error( $prepared ) ) {
				return null;
			}

			// Execute prepared query
			if ( strpos( strtoupper( trim( $query ) ), 'SELECT' ) === 0 ) {
				return $wpdb->get_results( $prepared, $output );
			} else {
				return $wpdb->query( $prepared );
			}
		} catch ( Exception $e ) {
			return null;
		}
	}

	/**
	 * Exclude sensitive fields from response data
	 * Removes sensitive information
	 *
	 * @param array $data Data to filter
	 *
	 * @return array Filtered data with sensitive fields removed
	 */
	public static function exclude_sensitive_data( $data ) {
		if ( ! is_array( $data ) ) {
			return $data;
		}

		$filtered = array();

		foreach ( $data as $key => $value ) {
			// Check if this is a sensitive field
			if ( self::is_sensitive_field( $key ) ) {
				// Skip sensitive fields
				continue;
			}

			// Recursively filter nested arrays
			if ( is_array( $value ) ) {
				$filtered[ $key ] = self::exclude_sensitive_data( $value );
			} else {
				$filtered[ $key ] = $value;
			}
		}

		return $filtered;
	}

	/**
	 * Mask sensitive fields instead of removing them
	 * Useful when field presence is important
	 *
	 * @param array $data Data to filter
	 *
	 * @return array Data with sensitive fields masked
	 */
	public static function mask_sensitive_data( $data ) {
		if ( ! is_array( $data ) ) {
			return $data;
		}

		$filtered = array();

		foreach ( $data as $key => $value ) {
			if ( self::is_sensitive_field( $key ) ) {
				// Mask with asterisks, preserving structure
				$filtered[ $key ] = '***REDACTED***';
			} elseif ( is_array( $value ) ) {
				// Recursively filter nested arrays
				$filtered[ $key ] = self::mask_sensitive_data( $value );
			} else {
				$filtered[ $key ] = $value;
			}
		}

		return $filtered;
	}

	/**
	 * Check if a field name is sensitive
	 *
	 * @param string $field_name Field name to check
	 *
	 * @return bool True if field is sensitive, false otherwise
	 */
	private static function is_sensitive_field( $field_name ) {
		$field_lower = strtolower( $field_name );

		// Check exact matches
		foreach ( self::$sensitive_fields as $sensitive ) {
			if ( strpos( $field_lower, $sensitive ) !== false ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Validate credential encryption is working
	 *
	 * @return bool True if encryption works, false otherwise
	 */
	public static function validate_encryption() {
		$test_data = 'test_credential_validation_' . time();

		try {
			// Try to encrypt
			$encrypted = self::encrypt_credential( $test_data );
			if ( $encrypted === false ) {
				return false;
			}

			// Try to decrypt
			$decrypted = self::decrypt_credential( $encrypted );
			if ( $decrypted === false ) {
				return false;
			}

			// Verify decrypted matches original
			return $decrypted === $test_data;
		} catch ( Exception $e ) {
			return false;
		}
	}

	/**
	 * Add security headers to API responses
	 *
	 * @return void
	 */
	public static function add_security_headers() {
		// Prevent clickjacking
		header( 'X-Frame-Options: DENY' );

		// Prevent MIME sniffing
		header( 'X-Content-Type-Options: nosniff' );

		// Enable XSS protection
		header( 'X-XSS-Protection: 1; mode=block' );

		// Prevent search indexing of sensitive endpoints
		header( 'X-Robots-Tag: noindex, nofollow' );

		// Content Security Policy
		header( 'Content-Security-Policy: default-src \'self\'' );
	}

	/**
	 * Validate API request authentication
	 *
	 * @return bool|WP_Error True if authenticated, error otherwise
	 */
	public static function validate_api_authentication() {
		// Check if user is logged in
		if ( ! is_user_logged_in() ) {
			return new WP_Error(
				'not_authenticated',
				'User must be authenticated',
				array( 'status' => 401 )
			);
		}

		// Check required capability
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return new WP_Error(
				'insufficient_permissions',
				'User does not have required permissions',
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Sanitize and validate admin settings
	 *
	 * @param array $settings Settings to validate
	 *
	 * @return array Validated settings
	 */
	public static function validate_admin_settings( $settings ) {
		if ( ! is_array( $settings ) ) {
			return array();
		}

		$validated = array();

		foreach ( $settings as $key => $value ) {
			// Reject if key contains SQL or suspicious patterns
			if ( self::contains_sql_injection( $key ) ) {
				continue;
			}

			// Sanitize key
			$clean_key = sanitize_key( $key );

			// Sanitize value based on type
			if ( is_array( $value ) ) {
				$validated[ $clean_key ] = self::validate_admin_settings( $value );
			} elseif ( is_numeric( $value ) ) {
				$validated[ $clean_key ] = intval( $value );
			} else {
				$validated[ $clean_key ] = sanitize_text_field( $value );
			}
		}

		return $validated;
	}

	/**
	 * Check if string contains SQL injection patterns
	 *
	 * @param string $string String to check
	 *
	 * @return bool True if suspicious patterns found, false otherwise
	 */
	private static function contains_sql_injection( $string ) {
		$dangerous_patterns = array(
			'/(\b(UNION|SELECT|INSERT|UPDATE|DELETE|DROP|CREATE|ALTER)\b)/i',
			'/(--|;|\'|"|\*|\/)/i',
			'/(\b(OR|AND)\b\s*\d+\s*=\s*\d+)/i',
		);

		foreach ( $dangerous_patterns as $pattern ) {
			if ( preg_match( $pattern, $string ) ) {
				return true;
			}
		}

		return false;
	}
}
