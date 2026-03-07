<?php
/**
 * Alert Template Manager Class
 *
 * Handles message formatting for different notification channels.
 *
 * @package PaySentinel
 * @since 1.0.0
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * PaySentinel_Alert_Template_Manager class
 *
 * Responsible for creating formatted messages for various alert channels
 * including email and Slack notifications.
 */
class PaySentinel_Alert_Template_Manager {

	/**
	 * Gateway manager instance
	 *
	 * @var PaySentinel_Gateway_Manager
	 */
	private $gateway_manager;

	/**
	 * Constructor
	 *
	 * @param PaySentinel_Gateway_Manager $gateway_manager Gateway manager instance.
	 */
	public function __construct( $gateway_manager ) {
		$this->gateway_manager = $gateway_manager;
	}

	/**
	 * Create alert message
	 *
	 * @param array $alert_data Alert data.
	 * @return string Alert message.
	 */
	public function create_alert_message( $alert_data ) {
		$gateway_name = $this->get_gateway_name( $alert_data['gateway_id'] );

		if ( isset( $alert_data['message'] ) && ! empty( $alert_data['message'] ) ) {
			return $alert_data['message'];
		}

		// Backward compatibility / default for low_success_rate
		$success_rate  = isset( $alert_data['success_rate'] ) ? number_format( $alert_data['success_rate'], 2 ) : '0.00';
		$period        = isset( $alert_data['period'] ) ? $alert_data['period'] : 'custom';
		$failed_count  = isset( $alert_data['failed_transactions'] ) ? $alert_data['failed_transactions'] : 0;
		$total_count   = isset( $alert_data['total_transactions'] ) ? $alert_data['total_transactions'] : 0;
		$success_count = $total_count - $failed_count;

		$message = sprintf(
			__( 'Payment gateway "%1$s" success rate has dropped to %2$s%% in the last %3$s. Only %4$d out of %5$d transactions succeeded (%6$d failed).', 'paysentinel' ),
			$gateway_name,
			$success_rate,
			$this->format_period_name( $period ),
			$success_count,
			$total_count,
			$failed_count
		);

		return $message;
	}

	/**
	 * Get gateway name
	 *
	 * @param string $gateway_id Gateway ID.
	 * @return string Gateway name.
	 */
	public function get_gateway_name( $gateway_id ) {
		return PaySentinel::get_friendly_gateway_name( $gateway_id );
	}

	/**
	 * Format period name for display
	 *
	 * @param string $period Period key.
	 * @return string Formatted period name.
	 */
	public function format_period_name( $period ) {
		$periods = array(
			'1hour'  => __( 'hour', 'paysentinel' ),
			'24hour' => __( '24 hours', 'paysentinel' ),
			'7day'   => __( '7 days', 'paysentinel' ),
		);

		return isset( $periods[ $period ] ) ? $periods[ $period ] : $period;
	}

	/**
	 * Get severity color for styling
	 *
	 * @param string $severity Severity level.
	 * @return string Color code.
	 */
	public function get_severity_color( $severity ) {
		$colors = array(
			'critical' => '#dc3232',
			'high'     => '#dc3232',
			'warning'  => '#ffb900',
			'info'     => '#0073aa',
		);

		return isset( $colors[ $severity ] ) ? $colors[ $severity ] : $colors['info'];
	}
}
