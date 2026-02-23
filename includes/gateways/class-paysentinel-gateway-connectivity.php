<?php

/**
 * Gateway Connectivity Checker
 *
 * Runs connectivity checks on payment gateways and stores results
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PaySentinel_Gateway_Connectivity
 *
 * Manages connectivity checks for payment gateways
 */
class PaySentinel_Gateway_Connectivity {

	/**
	 * List of supported gateway connectors
	 *
	 * @var array
	 */
	private $connectors = array(
		'stripe'               => 'PaySentinel_Stripe_Connector',
		'paypal'               => 'PaySentinel_PayPal_Connector',
		'woocommerce_payments' => 'PaySentinel_WC_Payments_Connector',
		'square_credit_card'   => 'PaySentinel_Square_Connector',
	);

	/**
	 * Check connectivity for all supported gateways
	 *
	 * @return array {
	 *               'checked_gateways' => int,
	 *               'online_gateways'  => array,
	 *               'offline_gateways' => array,
	 *               'results'          => array of status results by gateway_id
	 *               }
	 */
	public function check_all_gateways() {
		$results = array(
			'checked_gateways'      => 0,
			'online_gateways'       => array(),
			'offline_gateways'      => array(),
			'unconfigured_gateways' => array(),
			'results'               => array(),
		);

		$license = new PaySentinel_License();
		$tier    = $license->get_license_tier();
		$limit   = isset( PaySentinel_License::GATEWAY_LIMITS[ $tier ] ) ? PaySentinel_License::GATEWAY_LIMITS[ $tier ] : 1;
		$count   = 0;

		foreach ( $this->connectors as $gateway_id => $connector_class ) {
			if ( $count >= $limit ) {
				break;
			}

			// Skip if connector class doesn't exist
			if ( ! class_exists( $connector_class ) ) {
				continue;
			}

			$status = $this->check_gateway( $gateway_id );

			if ( null !== $status ) {
				$results['results'][ $gateway_id ] = $status;
				++$results['checked_gateways'];
				++$count;

				if ( 'online' === $status['status'] ) {
					$results['online_gateways'][] = $gateway_id;
				} elseif ( 'offline' === $status['status'] ) {
					$results['offline_gateways'][] = $gateway_id;
				} else {
					$results['unconfigured_gateways'][] = $gateway_id;
				}
			}
		}

		return $results;
	}

	/**
	 * Check connectivity for a specific gateway
	 *
	 * @param string $gateway_id Gateway identifier.
	 *
	 * @return array|null Status result or null if gateway not found.
	 */
	public function check_gateway( $gateway_id ) {
		if ( ! isset( $this->connectors[ $gateway_id ] ) ) {
			return null;
		}

		$connector_class = $this->connectors[ $gateway_id ];

		// Skip if class doesn't exist
		if ( ! class_exists( $connector_class ) ) {
			return null;
		}

		try {
			// Instantiate the connector
			$connector = new $connector_class();

			// Run the test
			$status = $connector->test_connection();

			// Log the result to database
			$connector->log_connectivity_check( $status );

			// Trigger hook for other plugins to react
			do_action( 'paysentinel_gateway_checked', $gateway_id, $status );

			return $status;

		} catch ( Exception $e ) {
			// Log exception
			error_log(
				sprintf(
					'Payment Monitor: Exception while checking %s gateway: %s',
					$gateway_id,
					$e->getMessage()
				)
			);

			return null;
		}
	}

	/**
	 * Get last connectivity check for a gateway
	 *
	 * @param string $gateway_id Gateway identifier.
	 *
	 * @return object|null Last check record or null.
	 */
	public function get_last_check( $gateway_id ) {
		if ( ! isset( $this->connectors[ $gateway_id ] ) ) {
			return null;
		}

		$connector_class = $this->connectors[ $gateway_id ];

		if ( ! class_exists( $connector_class ) ) {
			return null;
		}

		$connector = new $connector_class();
		return $connector->get_last_connectivity_check();
	}

	/**
	 * Get connectivity history for a gateway
	 *
	 * @param string $gateway_id Gateway identifier.
	 * @param int    $limit      Number of records to retrieve.
	 *
	 * @return array Array of connectivity check records.
	 */
	public function get_history( $gateway_id, $limit = 10 ) {
		if ( ! isset( $this->connectors[ $gateway_id ] ) ) {
			return array();
		}

		$connector_class = $this->connectors[ $gateway_id ];

		if ( ! class_exists( $connector_class ) ) {
			return array();
		}

		$connector = new $connector_class();
		return $connector->get_connectivity_history( $limit );
	}

	/**
	 * Get all supported gateway IDs
	 *
	 * @return array Array of gateway IDs.
	 */
	public function get_supported_gateways() {
		return array_keys( $this->connectors );
	}

	/**
	 * Get supported gateways that are actually enabled in WooCommerce
	 *
	 * @return array Array of enabled gateway IDs.
	 */
	public function get_enabled_gateways() {
		$enabled = array();

		$wc_gateways = WC()->payment_gateways()->get_available_payment_gateways();

		foreach ( $this->connectors as $gateway_id => $connector_class ) {
			// Check if this gateway exists in WooCommerce and is enabled
			if ( isset( $wc_gateways[ $gateway_id ] ) && $wc_gateways[ $gateway_id ]->enabled ) {
				$enabled[] = $gateway_id;
			}
		}

		return $enabled;
	}

	/**
	 * Clean old connectivity check records (older than specified days)
	 *
	 * @param int $days Number of days to keep records for.
	 *
	 * @return int Number of records deleted.
	 */
	public function cleanup_old_checks( $days = 30 ) {
		global $wpdb;

		$table = $wpdb->prefix . 'payment_monitor_gateway_connectivity';

		// Calculate cutoff date
		$cutoff_date = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );

		return $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM $table WHERE checked_at < %s",
				$cutoff_date
			)
		);
	}
}
