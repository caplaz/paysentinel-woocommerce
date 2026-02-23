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
 * including email, SMS, and Slack notifications.
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
	 * Create HTML email template
	 *
	 * @param array $alert_data Alert data.
	 * @return string HTML email content.
	 */
	public function create_email_template( $alert_data ) {
		$gateway_name   = $this->get_gateway_name( $alert_data['gateway_id'] );
		$success_rate   = number_format( $alert_data['success_rate'], 2 );
		$period         = $this->format_period_name( $alert_data['period'] );
		$failed_count   = $alert_data['failed_transactions'];
		$total_count    = $alert_data['total_transactions'];
		$severity_color = $this->get_severity_color( $alert_data['severity'] );
		$subject        = sprintf(
			__( '[%1$s] Payment Gateway Alert - %2$s', 'paysentinel' ),
			get_bloginfo( 'name' ),
			ucfirst( $alert_data['severity'] )
		);

		$admin_url = admin_url( 'admin.php?page=paysentinel' );

		ob_start();
		?>
		<!DOCTYPE html>
		<html>

		<head>
			<meta charset="UTF-8">
			<title><?php echo esc_html( $subject ); ?></title>
			<style>
				body {
					font-family: Arial, sans-serif;
					line-height: 1.6;
					color: #333;
				}

				.container {
					max-width: 600px;
					margin: 0 auto;
					padding: 20px;
				}

				.header {
					background-color:
						<?php echo esc_attr( $severity_color ); ?>
					;
					color: white;
					padding: 20px;
					text-align: center;
				}

				.content {
					background-color: #f9f9f9;
					padding: 20px;
				}

				.stats {
					background-color: white;
					padding: 15px;
					margin: 15px 0;
					border-left: 4px solid
						<?php echo esc_attr( $severity_color ); ?>
					;
				}

				.button {
					display: inline-block;
					background-color: #0073aa;
					color: white;
					padding: 10px 20px;
					text-decoration: none;
					border-radius: 3px;
					margin: 10px 0;
				}

				.footer {
					text-align: center;
					padding: 20px;
					font-size: 12px;
					color: #666;
				}
			</style>
		</head>

		<body>
			<div class="container">
				<div class="header">
					<h1><?php _e( 'Payment Gateway Alert', 'paysentinel' ); ?></h1>
					<p><?php echo esc_html( ucfirst( $alert_data['severity'] ) ); ?>
						<?php _e( 'Alert', 'paysentinel' ); ?></p>
				</div>

				<div class="content">
					<h2><?php _e( 'Alert Details', 'paysentinel' ); ?></h2>
					<p><?php echo esc_html( $this->create_alert_message( $alert_data ) ); ?></p>

					<div class="stats">
						<h3><?php _e( 'Gateway Statistics', 'paysentinel' ); ?></h3>
						<ul>
							<li><strong><?php _e( 'Gateway:', 'paysentinel' ); ?></strong>
								<?php echo esc_html( $gateway_name ); ?></li>
							<li><strong><?php _e( 'Success Rate:', 'paysentinel' ); ?></strong>
								<?php echo esc_html( $success_rate ); ?>%</li>
							<li><strong><?php _e( 'Period:', 'paysentinel' ); ?></strong>
								<?php echo esc_html( $period ); ?></li>
							<li><strong><?php _e( 'Failed Transactions:', 'paysentinel' ); ?></strong>
								<?php echo esc_html( $failed_count ); ?></li>
							<li><strong><?php _e( 'Total Transactions:', 'paysentinel' ); ?></strong>
								<?php echo esc_html( $total_count ); ?></li>
							<li><strong><?php _e( 'Time:', 'paysentinel' ); ?></strong>
								<?php echo esc_html( $alert_data['calculated_at'] ); ?></li>
						</ul>
					</div>

					<p>
						<a href="<?php echo esc_url( $admin_url ); ?>" class="button">
							<?php _e( 'View Dashboard', 'paysentinel' ); ?>
						</a>
					</p>

					<h3><?php _e( 'Recommended Actions', 'paysentinel' ); ?></h3>
					<ul>
						<li><?php _e( 'Check gateway configuration and credentials', 'paysentinel' ); ?></li>
						<li><?php _e( 'Review recent failed transactions for patterns', 'paysentinel' ); ?></li>
						<li><?php _e( 'Contact your payment processor if issues persist', 'paysentinel' ); ?></li>
						<li><?php _e( 'Consider enabling backup payment methods', 'paysentinel' ); ?></li>
					</ul>
				</div>

				<div class="footer">
					<p><?php _e( 'This alert was generated by PaySentinel - Payment Monitor for WooCommerce', 'paysentinel' ); ?></p>
					<p><?php echo esc_html( get_bloginfo( 'name' ) ); ?> - <?php echo esc_url( home_url() ); ?></p>
				</div>
			</div>
		</body>

		</html>
		<?php
		return ob_get_clean();
	}

	/**
	 * Create SMS message
	 *
	 * @param array $alert_data Alert data.
	 * @return string SMS message.
	 */
	public function create_sms_message( $alert_data ) {
		$gateway_name = $this->get_gateway_name( $alert_data['gateway_id'] );
		$success_rate = number_format( $alert_data['success_rate'], 1 );
		$severity     = strtoupper( $alert_data['severity'] );

		$message = sprintf(
			'%s ALERT: %s gateway success rate dropped to %s%% (%d/%d transactions failed). Check your dashboard immediately.',
			$severity,
			$gateway_name,
			$success_rate,
			$alert_data['failed_transactions'],
			$alert_data['total_transactions']
		);

		return $message;
	}

	/**
	 * Create Slack payload
	 *
	 * @param array $alert_data Alert data.
	 * @return array Slack payload.
	 */
	public function create_slack_payload( $alert_data ) {
		$gateway_name   = $this->get_gateway_name( $alert_data['gateway_id'] );
		$success_rate   = number_format( $alert_data['success_rate'], 2 );
		$severity_color = $this->get_severity_color( $alert_data['severity'] );
		$admin_url      = admin_url( 'admin.php?page=paysentinel' );

		// Create attachment with rich formatting
		$attachment = array(
			'color'      => $severity_color,
			'title'      => sprintf( __( 'Payment Gateway Alert - %s', 'paysentinel' ), ucfirst( $alert_data['severity'] ) ),
			'title_link' => $admin_url,
			'text'       => $this->create_alert_message( $alert_data ),
			'fields'     => array(
				array(
					'title' => __( 'Gateway', 'paysentinel' ),
					'value' => $gateway_name,
					'short' => true,
				),
				array(
					'title' => __( 'Success Rate', 'paysentinel' ),
					'value' => $success_rate . '%',
					'short' => true,
				),
				array(
					'title' => __( 'Failed Transactions', 'paysentinel' ),
					'value' => $alert_data['failed_transactions'],
					'short' => true,
				),
				array(
					'title' => __( 'Total Transactions', 'paysentinel' ),
					'value' => $alert_data['total_transactions'],
					'short' => true,
				),
				array(
					'title' => __( 'Period', 'paysentinel' ),
					'value' => $this->format_period_name( $alert_data['period'] ),
					'short' => true,
				),
				array(
					'title' => __( 'Time', 'paysentinel' ),
					'value' => $alert_data['calculated_at'],
					'short' => true,
				),
			),
			'footer'     => get_bloginfo( 'name' ) . ' - PaySentinel - Payment Monitor for WooCommerce',
			'ts'         => time(),
		);

		// Add action buttons
		$attachment['actions'] = array(
			array(
				'type'  => 'button',
				'text'  => __( 'View Dashboard', 'paysentinel' ),
				'url'   => $admin_url,
				'style' => 'primary',
			),
		);

		$payload = array(
			'username'    => __( 'Payment Monitor', 'paysentinel' ),
			'icon_emoji'  => ':warning:',
			'text'        => sprintf(
				__( ':rotating_light: *%s Payment Alert* :rotating_light:', 'paysentinel' ),
				ucfirst( $alert_data['severity'] )
			),
			'attachments' => array( $attachment ),
		);

		return $payload;
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
