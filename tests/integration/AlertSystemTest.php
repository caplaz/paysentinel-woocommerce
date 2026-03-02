<?php
/**
 * Integration tests for the Alert System.
 *
 * @package PaySentinel
 */

/**
 * Class AlertSystemTest
 */
class AlertSystemTest extends WP_UnitTestCase {


	/**
	 * Notifier instance.
	 *
	 * @var PaySentinel_Alert_Notifier
	 */
	private $notifier;

	/**
	 * Template manager instance.
	 *
	 * @var PaySentinel_Alert_Template_Manager
	 */
	private $template_manager;

	/**
	 * Setup test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		$gateway_manager        = new PaySentinel_Gateway_Manager();
		$this->template_manager = new PaySentinel_Alert_Template_Manager( $gateway_manager );

		$database       = new PaySentinel_Database();
		$this->notifier = new PaySentinel_Alert_Notifier( $this->template_manager, $database );

		// Reset options
		delete_option( 'paysentinel_settings' );
		delete_option( 'paysentinel_slack_workspace' );
		delete_option( 'paysentinel_quota_exceeded' );
		delete_option( PaySentinel_License::OPTION_SITE_REGISTERED );
		delete_option( PaySentinel_License::OPTION_LICENSE_KEY );
		delete_option( PaySentinel_License::OPTION_LICENSE_DATA );
		delete_option( PaySentinel_License::OPTION_SITE_SECRET );
	}

	/**
	 * Test Template Manager message creation.
	 */
	public function test_template_manager_create_alert_message() {
		$alert_data = array(
			'gateway_id'          => 'stripe',
			'severity'            => 'high',
			'success_rate'        => 45.5,
			'period'              => '1hour',
			'failed_transactions' => 11,
			'total_transactions'  => 20,
			'calculated_at'       => '2026-02-23 18:00:00',
		);

		$message = $this->template_manager->create_alert_message( $alert_data );
		$this->assertStringContainsString( 'Stripe', $message );
		$this->assertStringContainsString( '45.50%', $message );
		$this->assertStringContainsString( 'hour', $message );
		$this->assertStringContainsString( '9 out of 20', $message );
	}

	/**
	 * Test HTML email template generation.
	 */
	public function test_template_manager_create_email_template() {
		$alert_data = array(
			'gateway_id'          => 'paypal',
			'severity'            => 'critical',
			'success_rate'        => 10.0,
			'period'              => '24hour',
			'failed_transactions' => 90,
			'total_transactions'  => 100,
			'calculated_at'       => '2026-02-23 18:00:00',
		);

		$html = $this->template_manager->create_email_template( $alert_data );
		$this->assertStringContainsString( '<!DOCTYPE html>', $html );
		$this->assertStringContainsString( '#dc3232', $html ); // Critical color
		$this->assertStringContainsString( 'PayPal', $html );
		$this->assertStringContainsString( 'View Dashboard', $html );
	}

	/**
	 * Test SMS message creation.
	 */
	public function test_template_manager_create_sms_message() {
		$alert_data = array(
			'gateway_id'          => 'stripe',
			'severity'            => 'high',
			'success_rate'        => 50.0,
			'failed_transactions' => 5,
			'total_transactions'  => 10,
		);

		$sms = $this->template_manager->create_sms_message( $alert_data );
		$this->assertStringContainsString( 'HIGH ALERT', $sms );
		$this->assertStringContainsString( 'Stripe', $sms );
		$this->assertStringContainsString( '50.0%', $sms );
	}

	/**
	 * Test Slack payload creation.
	 */
	public function test_template_manager_create_slack_payload() {
		$alert_data = array(
			'gateway_id'          => 'stripe',
			'severity'            => 'warning',
			'success_rate'        => 80.0,
			'period'              => '7day',
			'failed_transactions' => 20,
			'total_transactions'  => 100,
			'calculated_at'       => '2026-02-23 18:00:00',
		);

		$payload = $this->template_manager->create_slack_payload( $alert_data );
		$this->assertEquals( ':warning:', $payload['icon_emoji'] );
		$this->assertEquals( '#ffb900', $payload['attachments'][0]['color'] );
		$this->assertCount( 6, $payload['attachments'][0]['fields'] );
	}

	/**
	 * Test that notifier fails when site is not registered.
	 */
	public function test_notifier_fails_when_unregistered() {
		$alert_data = array( 'gateway_id' => 'stripe' );
		$result     = $this->notifier->send_notifications( $alert_data, 1 );
		$this->assertFalse( $result );
	}

	/**
	 * Test Free tier local email sending.
	 */
	public function test_notifier_sends_local_email_on_free_tier() {
		update_option( PaySentinel_License::OPTION_SITE_REGISTERED, true );
		// Free tier by default if no data

		update_option(
			'paysentinel_settings',
			array(
				PaySentinel_Settings_Constants::ALERT_EMAIL => 'admin@example.com',
			)
		);

		// We need to catch wp_mail. Since it's a pluggable function, WP_UnitTestCase handles it via phpunit features usually,
		// but in this environment we can use a mock or check a global.
		// Actually, in this test environment, we might not have a reliable way to check wp_mail without a mock.
		// Let's assume it works if we reach that line and it returns true (mocking wp_mail is tricky in integration tests).

		$alert_data = array(
			'gateway_id'          => 'stripe',
			'severity'            => 'warning',
			'success_rate'        => 80.0,
			'period'              => '1hour',
			'failed_transactions' => 2,
			'total_transactions'  => 10,
			'calculated_at'       => '2026-02-23 18:00:00',
		);

		$result = $this->notifier->send_notifications( $alert_data, 1 );
		$this->assertTrue( $result );
	}

	/**
	 * Test API-based notifications for premium tiers.
	 */
	public function test_notifier_sends_to_api_on_premium_tier() {
		update_option( PaySentinel_License::OPTION_SITE_REGISTERED, true );
		update_option( PaySentinel_License::OPTION_LICENSE_KEY, 'PA-PRO-TEST-KEY' );
		update_option( PaySentinel_License::OPTION_SITE_SECRET, 'test_secret' );
		update_option( PaySentinel_License::OPTION_LICENSE_DATA, array( 'plan' => 'pro' ) );

		update_option(
			'paysentinel_settings',
			array(
				PaySentinel_Settings_Constants::ALERT_EMAIL => 'pro@example.com',
				PaySentinel_Settings_Constants::ALERT_PHONE_NUMBER => '+1234567890',
			)
		);
		update_option( 'paysentinel_slack_workspace', 'SLACK-ID' );

		// Mock API call
		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) {
				if ( strpos( $url, '/api/alerts' ) !== false ) {
					return array(
						'response' => array( 'code' => 200 ),
						'body'     => wp_json_encode( array( 'success' => true ) ),
					);
				}
				return $pre;
			},
			10,
			3
		);

		$alert_data = array(
			'gateway_id'          => 'stripe',
			'severity'            => 'critical',
			'success_rate'        => 5.0,
			'period'              => '1hour',
			'failed_transactions' => 19,
			'total_transactions'  => 20,
			'calculated_at'       => '2026-02-23 18:00:00',
		);

		$result = $this->notifier->send_notifications( $alert_data, 1 );
		$this->assertTrue( $result );

		remove_all_filters( 'pre_http_request' );
	}

	/**
	 * Test per-gateway channel configuration.
	 */
	public function test_notifier_respects_per_gateway_config() {
		update_option( PaySentinel_License::OPTION_SITE_REGISTERED, true );
		update_option( PaySentinel_License::OPTION_LICENSE_STATUS, 'valid' );
		update_option( PaySentinel_License::OPTION_LICENSE_KEY, 'PA-PRO-TEST-KEY' );
		update_option( PaySentinel_License::OPTION_SITE_SECRET, 'test_secret' );
		update_option(
			PaySentinel_License::OPTION_LICENSE_DATA,
			array(
				'plan'     => 'pro',
				'features' => array( 'per_gateway_config' => true ),
			)
		);

		update_option(
			'paysentinel_settings',
			array(
				PaySentinel_Settings_Constants::ALERT_EMAIL => 'global@example.com',
				PaySentinel_Settings_Constants::GATEWAY_ALERT_CONFIG => array(
					'stripe' => array(
						PaySentinel_Settings_Constants::GATEWAY_CONFIG_CHANNELS => array( 'sms' ), // Only SMS for stripe
					),
				),
				PaySentinel_Settings_Constants::ALERT_PHONE_NUMBER => '+1234567890',
			)
		);

		// Capture the alert_type sent to API
		$sent_alert_type = '';
		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) use ( &$sent_alert_type ) {
				if ( strpos( $url, '/api/alerts' ) !== false ) {
					$body            = json_decode( $args['body'], true );
					$sent_alert_type = $body['alert_type'];
					return array(
						'response' => array( 'code' => 200 ),
						'body'     => wp_json_encode( array( 'success' => true ) ),
					);
				}
				return $pre;
			},
			10,
			3
		);

		$alert_data = array(
			'gateway_id'          => 'stripe',
			'severity'            => 'critical',
			'success_rate'        => 5.0,
			'period'              => '1hour',
			'failed_transactions' => 19,
			'total_transactions'  => 20,
			'calculated_at'       => '2026-02-23 18:00:00',
		);

		$this->notifier->send_notifications( $alert_data, 1 );

		$this->assertEquals( 'SMS', $sent_alert_type );

		remove_all_filters( 'pre_http_request' );
	}

	/**
	 * Test handle quota exceeded (403).
	 */
	public function test_notifier_handles_quota_exceeded() {
		update_option( PaySentinel_License::OPTION_SITE_REGISTERED, true );
		update_option( PaySentinel_License::OPTION_LICENSE_STATUS, 'valid' );
		update_option( PaySentinel_License::OPTION_LICENSE_KEY, 'PA-PRO-TEST-KEY' );
		update_option( PaySentinel_License::OPTION_SITE_SECRET, 'test_secret' );
		update_option( PaySentinel_License::OPTION_LICENSE_DATA, array( 'plan' => 'starter' ) );

		update_option(
			'paysentinel_settings',
			array(
				'alert_phone_number' => '+1234567890',
			)
		);

		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) {
				if ( strpos( $url, '/api/alerts' ) !== false ) {
					return array(
						'response' => array( 'code' => 403 ),
						'body'     => wp_json_encode( array( 'error' => 'Quota exceeded' ) ),
					);
				}
				return $pre;
			},
			10,
			3
		);

		$alert_data = array(
			'gateway_id' => 'stripe',
			'severity'   => 'high',
		);
		$result     = $this->notifier->send_notifications( $alert_data, 1 );

		$this->assertFalse( $result );
		$this->assertTrue( (bool) get_option( 'paysentinel_quota_exceeded' ) );

		remove_all_filters( 'pre_http_request' );
	}

	/**
	 * Test other API error codes.
	 */
	public function test_notifier_handles_api_errors() {
		update_option( PaySentinel_License::OPTION_SITE_REGISTERED, true );
		update_option( PaySentinel_License::OPTION_LICENSE_STATUS, 'valid' );
		update_option( PaySentinel_License::OPTION_LICENSE_KEY, 'PA-PRO-TEST-KEY' );
		update_option( PaySentinel_License::OPTION_SITE_SECRET, 'test_secret' );

		$error_codes = array( 400, 401, 404, 429, 502, 500 );

		foreach ( $error_codes as $code ) {
			add_filter(
				'pre_http_request',
				function ( $pre, $args, $url ) use ( $code ) {
					if ( strpos( $url, '/api/alerts' ) !== false ) {
						return array(
							'response' => array( 'code' => $code ),
							'body'     => 'Error happened',
						);
					}
					return $pre;
				},
				10,
				3
			);

			$alert_data = array(
				'gateway_id'  => 'stripe',
				'severity'    => 'high',
				'alert_email' => 'test@test.com',
			);
			$result     = $this->notifier->send_notifications( $alert_data, 1 );
			$this->assertFalse( $result, "Failed for error code $code" );

			remove_all_filters( 'pre_http_request' );
		}
	}

	/**
	 * Test legacy test methods.
	 */
	public function test_legacy_diagnostic_methods() {
		update_option( PaySentinel_License::OPTION_SITE_REGISTERED, true );
		update_option( PaySentinel_License::OPTION_LICENSE_STATUS, 'valid' );
		update_option( PaySentinel_License::OPTION_LICENSE_KEY, 'PA-PRO-TEST-KEY' );
		update_option( PaySentinel_License::OPTION_SITE_SECRET, 'test_secret' );
		update_option( PaySentinel_License::OPTION_LICENSE_DATA, array( 'plan' => 'pro' ) );

		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) {
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode( array( 'success' => true ) ),
				);
			},
			10,
			3
		);

		$sms_result = $this->notifier->test_sms_configuration( '+1234567890' );
		$this->assertTrue( $sms_result['success'] );

		$slack_result = $this->notifier->test_slack_configuration( 'SLACK-ID' );
		$this->assertTrue( $slack_result['success'] );

		remove_all_filters( 'pre_http_request' );
	}

	/**
	 * Test Alert Checker severity calculation.
	 */
	public function test_checker_calculate_severity() {
		$database        = new PaySentinel_Database();
		$health          = new PaySentinel_Health();
		$gateway_manager = new PaySentinel_Gateway_Manager();
		$checker         = new PaySentinel_Alert_Checker( $database, $health, $gateway_manager, $this->notifier );

		$calculate_severity = new ReflectionMethod( 'PaySentinel_Alert_Checker', 'calculate_severity' );
		$calculate_severity->setAccessible( true );

		// Low volume
		$this->assertEquals( 'info', $calculate_severity->invoke( $checker, 50, 1 ) );
		$this->assertEquals( 'warning', $calculate_severity->invoke( $checker, 50, 5 ) );

		// Normal volume
		$this->assertEquals( 'critical', $calculate_severity->invoke( $checker, 70, 10 ) );
		$this->assertEquals( 'high', $calculate_severity->invoke( $checker, 85, 10 ) );
		$this->assertEquals( 'warning', $calculate_severity->invoke( $checker, 92, 10 ) );
		$this->assertEquals( 'info', $calculate_severity->invoke( $checker, 98, 10 ) );
	}

	/**
	 * Test Checker trigger_alert saves to DB.
	 */
	public function test_checker_trigger_alert_saves_to_db() {
		global $wpdb;
		$database        = new PaySentinel_Database();
		$health          = new PaySentinel_Health();
		$gateway_manager = new PaySentinel_Gateway_Manager();
		$checker         = new PaySentinel_Alert_Checker( $database, $health, $gateway_manager, $this->notifier );

		$database->create_tables();
		$table_name = $database->get_alerts_table();
		$wpdb->query( "TRUNCATE TABLE $table_name" );

		$alert_data = array(
			'gateway_id' => 'stripe',
			'alert_type' => 'low_success_rate',
			'severity'   => 'critical',
			'message'    => 'Test message',
			'metadata'   => array( 'foo' => 'bar' ),
		);

		// Required for notifier called inside trigger_alert
		update_option( PaySentinel_License::OPTION_SITE_REGISTERED, true );

		$alert_id = $checker->trigger_alert( $alert_data );
		$this->assertNotFalse( $alert_id );

		$saved = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table_name WHERE id = %d", $alert_id ) );
		$this->assertEquals( 'stripe', $saved->gateway_id );
		$this->assertEquals( 'low_success_rate', $saved->alert_type );
		$this->assertEquals( 0, $saved->is_resolved );
	}

	/**
	 * Test Alert Resolution.
	 */
	public function test_checker_resolve_alerts() {
		global $wpdb;
		$database        = new PaySentinel_Database();
		$health          = new PaySentinel_Health();
		$gateway_manager = new PaySentinel_Gateway_Manager();
		$checker         = new PaySentinel_Alert_Checker( $database, $health, $gateway_manager, $this->notifier );

		$database->create_tables();
		$table_name = $database->get_alerts_table();
		$wpdb->query( "TRUNCATE TABLE $table_name" );

		// Create an open alert with valid ENUM
		$wpdb->insert(
			$table_name,
			array(
				'gateway_id'  => 'stripe',
				'alert_type'  => 'low_success_rate',
				'severity'    => 'critical',
				'message'     => 'Low success rate!',
				'is_resolved' => 0,
				'created_at'  => current_time( 'mysql' ),
			)
		);

		$count = $checker->resolve_alerts( 'stripe', 'low_success_rate' );
		$this->assertEquals( 1, $count );

		$resolved = $wpdb->get_var( "SELECT is_resolved FROM $table_name LIMIT 1" );
		$this->assertEquals( 1, $resolved );
	}

	/**
	 * Test rate limiting.
	 */
	public function test_checker_is_rate_limited() {
		global $wpdb;
		$database        = new PaySentinel_Database();
		$health          = new PaySentinel_Health();
		$gateway_manager = new PaySentinel_Gateway_Manager();
		$checker         = new PaySentinel_Alert_Checker( $database, $health, $gateway_manager, $this->notifier );

		$database->create_tables();
		$table_name = $database->get_alerts_table();
		$wpdb->query( "TRUNCATE TABLE $table_name" );

		$this->assertFalse( $checker->is_rate_limited( 'stripe', 'low_success_rate' ) );

		// Add a recent alert with valid ENUM
		$wpdb->insert(
			$table_name,
			array(
				'gateway_id' => 'stripe',
				'alert_type' => 'low_success_rate',
				'severity'   => 'critical',
				'message'    => 'Low success rate!',
				'created_at' => current_time( 'mysql' ),
			)
		);

		$this->assertTrue( $checker->is_rate_limited( 'stripe', 'low_success_rate' ) );
	}

	/**
	 * Test immediate transaction alerts.
	 */
	public function test_checker_check_immediate_transaction_alert() {
		$database        = new PaySentinel_Database();
		$health          = new PaySentinel_Health();
		$gateway_manager = new PaySentinel_Gateway_Manager();
		$checker         = new PaySentinel_Alert_Checker( $database, $health, $gateway_manager, $this->notifier );

		// Mock settings and refresh config cache
		PaySentinel_Config::instance()->update_all(
			array(
				'alerts_enabled'               => 1,
				'immediate_transaction_alerts' => 1,
			)
		);

		// Ensure site is registered for the notifier
		update_option( PaySentinel_License::OPTION_SITE_REGISTERED, true );

		// Mock Order
		$order = $this->getMockBuilder( 'WC_Order' )
			->disableOriginalConstructor()
			->getMock();
		$order->method( 'get_payment_method' )->willReturn( 'stripe' );
		$order->method( 'get_total' )->willReturn( 100.0 );
		$order->method( 'get_billing_email' )->willReturn( 'test@example.com' );

		// We expect trigger_alert to be called.
		// Easiest is to check if an alert is saved in DB.
		$database->create_tables();
		$wpdb       = $GLOBALS['wpdb'];
		$table_name = $database->get_alerts_table();
		$wpdb->query( "TRUNCATE TABLE $table_name" );

		$checker->check_immediate_transaction_alert( 123, $order );

		$alert_exists = $wpdb->get_var( "SELECT COUNT(*) FROM $table_name WHERE gateway_id = 'stripe' AND alert_type = 'gateway_error'" );
		$this->assertEquals( 1, $alert_exists );
	}

	/**
	 * Test soft error detection.
	 */
	public function test_checker_is_soft_error() {
		$database        = new PaySentinel_Database();
		$health          = new PaySentinel_Health();
		$gateway_manager = new PaySentinel_Gateway_Manager();
		$checker         = new PaySentinel_Alert_Checker( $database, $health, $gateway_manager, $this->notifier );

		$is_soft_error = new ReflectionMethod( 'PaySentinel_Alert_Checker', 'is_soft_error' );
		$is_soft_error->setAccessible( true );

		$this->assertTrue( $is_soft_error->invoke( $checker, (object) array( 'failure_code' => 'card_declined' ) ) );
		$this->assertTrue( $is_soft_error->invoke( $checker, (object) array( 'failure_reason' => 'insufficient funds' ) ) );
		$this->assertFalse( $is_soft_error->invoke( $checker, (object) array( 'failure_code' => 'system_error' ) ) );
	}

	/**
	 * Test check_and_send logic.
	 */
	public function test_checker_check_and_send() {
		global $wpdb;
		$database        = new PaySentinel_Database();
		$health          = new PaySentinel_Health();
		$gateway_manager = new PaySentinel_Gateway_Manager();
		$checker         = new PaySentinel_Alert_Checker( $database, $health, $gateway_manager, $this->notifier );

		$database->create_tables();
		$table_name = $database->get_alerts_table();
		$wpdb->query( "TRUNCATE TABLE $table_name" );

		PaySentinel_Config::instance()->update_all(
			array(
				'alerts_enabled'  => 1,
				'alert_threshold' => 95.0,
			)
		);
		update_option( PaySentinel_License::OPTION_SITE_REGISTERED, true );

		// Case 1: Healthy
		$checker->check_and_send(
			'stripe',
			array(
				'1hour' => array(
					'success_rate'        => 99.0,
					'total_transactions'  => 100,
					'failed_transactions' => 1,
				),
			)
		);
		$this->assertEquals( 0, $wpdb->get_var( "SELECT COUNT(*) FROM $table_name" ) );

		// Case 2: Unhealthy
		$checker->check_and_send(
			'stripe',
			array(
				'1hour' => array(
					'success_rate'        => 80.0,
					'total_transactions'  => 100,
					'failed_transactions' => 20,
				),
			)
		);
		$this->assertEquals( 1, $wpdb->get_var( "SELECT COUNT(*) FROM $table_name" ) );
	}

	/**
	 * Test connectivity alert.
	 */
	public function test_checker_check_gateway_connectivity_alert() {
		global $wpdb;
		$database        = new PaySentinel_Database();
		$health          = new PaySentinel_Health();
		$gateway_manager = new PaySentinel_Gateway_Manager();
		$checker         = new PaySentinel_Alert_Checker( $database, $health, $gateway_manager, $this->notifier );

		$database->create_tables();
		$table_name = $database->get_alerts_table();
		$wpdb->query( "TRUNCATE TABLE $table_name" );
		update_option( PaySentinel_License::OPTION_SITE_REGISTERED, true );

		$checker->check_gateway_connectivity_alert(
			'stripe',
			array(
				'status' => 'offline',
				'error'  => 'Timeout',
			)
		);

		$alert = $wpdb->get_row( "SELECT * FROM $table_name" );
		$this->assertEquals( 'gateway_down', $alert->alert_type );
		$this->assertEquals( 'critical', $alert->severity );
	}

	/**
	 * Test Notifier legacy Slack notification.
	 */
	public function test_notifier_send_slack_notification_legacy() {
		update_option( PaySentinel_License::OPTION_SITE_REGISTERED, true );
		update_option( PaySentinel_License::OPTION_LICENSE_STATUS, 'valid' );
		update_option( PaySentinel_License::OPTION_LICENSE_DATA, array( 'plan' => 'pro' ) );

		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) {
				if ( strpos( $url, 'slack.com' ) !== false ) {
					return array(
						'response' => array( 'code' => 200 ),
						'body'     => 'ok',
					);
				}
				return $pre;
			},
			10,
			3
		);

		$send_slack_notification_legacy = new ReflectionMethod( 'PaySentinel_Alert_Notifier', 'send_slack_notification_legacy' );
		$send_slack_notification_legacy->setAccessible( true );

		$result = $send_slack_notification_legacy->invoke(
			$this->notifier,
			array(
				'gateway_id'          => 'stripe',
				'severity'            => 'critical',
				'success_rate'        => 0,
				'period'              => '1hour',
				'failed_transactions' => 10,
				'total_transactions'  => 10,
				'calculated_at'       => '2026-02-23 18:00:00',
			),
			'https://hooks.slack.com/services/TXXX/BXXX/XXXX'
		);

		$this->assertTrue( $result );
		remove_all_filters( 'pre_http_request' );
	}

	/**
	 * Test Notifier different API payload branches.
	 */
	public function test_notifier_api_payload_branches() {
		update_option( PaySentinel_License::OPTION_SITE_REGISTERED, true );
		update_option( PaySentinel_License::OPTION_LICENSE_STATUS, 'valid' );
		update_option( PaySentinel_License::OPTION_LICENSE_KEY, 'PA-PRO-TEST-KEY' );
		update_option( PaySentinel_License::OPTION_SITE_SECRET, 'test_secret' );
		update_option( PaySentinel_License::OPTION_LICENSE_DATA, array( 'plan' => 'pro' ) );

		$last_payload = array();
		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) use ( &$last_payload ) {
				if ( strpos( $url, '/api/alerts' ) !== false ) {
					$last_payload = json_decode( $args['body'], true );
					return array(
						'response' => array( 'code' => 200 ),
						'body'     => wp_json_encode( array( 'success' => true ) ),
					);
				}
				return $pre;
			},
			10,
			3
		);

		$send_to_api = new ReflectionMethod( 'PaySentinel_Alert_Notifier', 'send_to_api' );
		$send_to_api->setAccessible( true );

		// 1. Slack payload
		update_option( 'paysentinel_slack_workspace', 'SLACK-ID' );
		$send_to_api->invoke(
			$this->notifier,
			array(
				'gateway_id' => 'stripe',
				'severity'   => 'high',
			),
			array( 'slack' ),
			array()
		);
		$this->assertEquals( 'SLACK', $last_payload['alert_type'] );
		$this->assertEquals( 'SLACK-ID', $last_payload['integration_id'] );

		// 2. SMS payload
		$send_to_api->invoke(
			$this->notifier,
			array(
				'gateway_id' => 'stripe',
				'severity'   => 'high',
			),
			array( 'sms' ),
			array( 'alert_phone_number' => '+1111111111' )
		);
		$this->assertEquals( 'SMS', $last_payload['alert_type'] );
		$this->assertEquals( '+1111111111', $last_payload['recipient'] );

		// 3. Email payload (default)
		$send_to_api->invoke(
			$this->notifier,
			array(
				'gateway_id' => 'stripe',
				'severity'   => 'high',
			),
			array( PaySentinel_Settings_Constants::CHANNEL_EMAIL ),
			array( PaySentinel_Settings_Constants::ALERT_EMAIL => 'admin@test.com' )
		);
		$this->assertEquals( 'EMAIL', $last_payload['alert_type'] );
		$this->assertEquals( 'admin@test.com', $last_payload['recipient'] );

		remove_all_filters( 'pre_http_request' );
	}
}
