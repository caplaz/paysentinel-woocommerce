<?php

/**
 * Gateway Manager class
 *
 * Centralizes all gateway-related logic including:
 * - Getting active gateways with tier limits
 * - Managing gateway availability
 * - Gateway configuration helpers
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PaySentinel_Gateway_Manager {

	/**
	 * License instance
	 *
	 * @var PaySentinel_License
	 */
	private $license;

	/**
	 * Constructor
	 *
	 * @param PaySentinel_License $license License instance (optional)
	 */
	public function __construct( $license = null ) {
		$this->license = $license ?? new PaySentinel_License();
	}

	/**
	 * Get active payment gateways respecting license tier limits
	 *
	 * Returns gateways that are:
	 * 1. Configured in plugin settings OR
	 * 2. Enabled in WooCommerce (if no settings configured)
	 * 3. Limited by current license tier
	 *
	 * @return array Gateway IDs
	 */
	public function get_active_gateways() {
		$gateways = array();
		$limit    = $this->get_gateway_limit();

		// Get enabled gateways from settings
		$settings         = get_option( 'paysentinel_settings', array() );
		$enabled_gateways = isset( $settings[ PaySentinel_Settings_Constants::ENABLED_GATEWAYS ] ) ? $settings[ PaySentinel_Settings_Constants::ENABLED_GATEWAYS ] : array();

		if ( ! empty( $enabled_gateways ) ) {
			return array_slice( $enabled_gateways, 0, $limit );
		}

		// If no specific gateways configured, get all WooCommerce enabled gateways
		$gateways = $this->get_woocommerce_enabled_gateways();

		return array_slice( $gateways, 0, $limit );
	}

	/**
	 * Get all available WooCommerce gateways
	 *
	 * Returns all payment gateways available in WooCommerce,
	 * regardless of enabled status or license limits.
	 *
	 * @return array Array of gateway objects keyed by gateway ID
	 */
	public function get_available_gateways() {
		if ( ! class_exists( 'WC_Payment_Gateways' ) ) {
			return array();
		}

		$wc_gateways = WC_Payment_Gateways::instance();
		return $wc_gateways->get_available_payment_gateways();
	}

	/**
	 * Get gateway limit for current license tier
	 *
	 * @return int Gateway limit (default: 1)
	 */
	public function get_gateway_limit() {
		$tier = $this->license->get_license_tier();
		return isset( PaySentinel_License::GATEWAY_LIMITS[ $tier ] )
			? PaySentinel_License::GATEWAY_LIMITS[ $tier ]
			: 1;
	}

	/**
	 * Check if a specific gateway is enabled for monitoring
	 *
	 * Checks both plugin settings and WooCommerce status,
	 * and respects license tier limits.
	 *
	 * @param string $gateway_id Gateway ID to check
	 *
	 * @return bool True if gateway is enabled
	 */
	public function is_gateway_enabled( $gateway_id ) {
		$active_gateways = $this->get_active_gateways();
		return in_array( $gateway_id, $active_gateways, true );
	}

	/**
	 * Get gateway display name
	 *
	 * Returns the human-readable name for a gateway.
	 * Falls back to gateway ID if name not found.
	 *
	 * @param string $gateway_id Gateway ID
	 *
	 * @return string Gateway display name
	 */
	public function get_gateway_display_name( $gateway_id ) {
		$available_gateways = $this->get_available_gateways();

		if ( isset( $available_gateways[ $gateway_id ] ) ) {
			return $available_gateways[ $gateway_id ]->get_title();
		}

		// Fallback: format gateway ID as readable name
		return $this->format_gateway_id_as_name( $gateway_id );
	}

	/**
	 * Get WooCommerce enabled gateways
	 *
	 * Returns only gateways that are enabled in WooCommerce settings.
	 * Only includes actual payment processors, not manual payment methods.
	 *
	 * @return array Gateway IDs
	 */
	private function get_woocommerce_enabled_gateways() {
		$gateways = array();

		if ( ! class_exists( 'WC_Payment_Gateways' ) ) {
			return $gateways;
		}

		$wc_gateways        = WC_Payment_Gateways::instance();
		$available_gateways = $wc_gateways->get_available_payment_gateways();

		foreach ( $available_gateways as $gateway_id => $gateway ) {
			// Only monitor gateways that are enabled and are actual payment processors
			// (not offline payment methods like cheque, bacs, cod, or token storage like card)
			if ( $gateway->enabled === 'yes' && $this->is_payment_processor_gateway( $gateway ) ) {
				$gateways[] = $gateway_id;
			}
		}

		return $gateways;
	}

	/**
	 * Check if a gateway is an actual payment processor gateway
	 *
	 * Filters out offline payment methods and payment token storage.
	 * Real payment gateways have external payment processing capabilities.
	 *
	 * @param WC_Payment_Gateway $gateway Gateway object to check
	 * @return bool True if this is a real payment processor gateway
	 */
	private function is_payment_processor_gateway( $gateway ) {
		// Known non-processor payment methods to exclude
		$non_processor_ids = array( 'card', 'bacs', 'cheque', 'cod' );

		// Get gateway ID from gateway object
		if ( isset( $gateway->id ) && in_array( $gateway->id, $non_processor_ids, true ) ) {
			return false;
		}

		// Offline payment methods should not be monitored
		if ( isset( $gateway->type ) && 'offline' === $gateway->type ) {
			return false;
		}

		// If it has external payment processing capability, it's a real gateway
		// Real gateways either have API integration or handle external transactions
		return true;
	}

	/**
	 * Format gateway ID as readable name
	 *
	 * Converts gateway_id to "Gateway ID" format.
	 *
	 * @param string $gateway_id Gateway ID
	 *
	 * @return string Formatted name
	 */
	private function format_gateway_id_as_name( $gateway_id ) {
		// Remove common prefixes
		$name = str_replace( array( 'woocommerce_', 'wc_', 'wc-' ), '', $gateway_id );

		// Replace underscores and hyphens with spaces
		$name = str_replace( array( '_', '-' ), ' ', $name );

		// Capitalize words
		return ucwords( $name );
	}
}
