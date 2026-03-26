<?php
/**
 * Integration tests for License Management.
 *
 * @package PaySentinel
 */

/**
 * Class LicenseManagementTest
 */
class LicenseManagementTest extends WP_UnitTestCase {



	/**
	 * License handler instance.
	 *
	 * @var PaySentinel_License
	 */
	private $license;

	/**
	 * Setup test environment.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->license = new PaySentinel_License();

		// Ensure options are clean.
		delete_option( PaySentinel_License::OPTION_LICENSE_KEY );
		delete_option( PaySentinel_License::OPTION_LICENSE_STATUS );
		delete_option( PaySentinel_License::OPTION_LICENSE_DATA );
		delete_option( PaySentinel_License::OPTION_SITE_SECRET );
		delete_option( PaySentinel_License::OPTION_SITE_REGISTERED );
	}

	/**
	 * Teardown test environment.
	 */
	public function tearDown(): void {
		parent::tearDown();
		remove_all_filters( 'pre_http_request' );
	}

	/**
	 * Mock a successful license activation response.
	 */
	private function mock_activation_success() {
		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) {
				if ( $url === PaySentinel_License::API_ENDPOINT_ACTIVATE ) {
					return array(
						'response' => array( 'code' => 200 ),
						'body'     => wp_json_encode(
							array(
								'success'           => true,
								'message'           => 'License activated successfully',
								'site_registration' => array(
									'registered'  => true,
									'site_secret' => 'test_secret_123',
								),
								'license_info'      => array(
									'plan' => 'pro',
								),
							)
						),
					);
				}
				return $pre;
			},
			10,
			3
		);
	}

	/**
	 * Mock a successful license validation response.
	 */
	private function mock_validation_success() {
		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) {
				if ( $url === PaySentinel_License::API_ENDPOINT_VALIDATE ) {
					return array(
						'response' => array( 'code' => 200 ),
						'body'     => wp_json_encode(
							array(
								'valid'         => true,
								'message'       => 'License is valid',
								'plan'          => 'pro',
								'expiration_ts' => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ),
								'features'      => array(

									'slack_alerts' => true,
								),
							)
						),
					);
				}
				return $pre;
			},
			10,
			3
		);
	}

	/**
	 * Test basic activation.
	 */
	public function test_activate_license_success() {
		$this->mock_activation_success();

		$result = $this->license->activate_license( 'test-key' );

		$this->assertTrue( $result['success'] );
		$this->assertEquals( 'test_secret_123', $result['site_secret'] );
		$this->assertTrue( $result['site_registered'] );
	}

	/**
	 * Test activation failure (e.g. invalid key).
	 */
	public function test_activate_license_failure() {
		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) {
				if ( $url === PaySentinel_License::API_ENDPOINT_ACTIVATE ) {
					return array(
						'response' => array( 'code' => 400 ),
						'body'     => wp_json_encode(
							array(
								'success' => false,
								'error'   => 'License not found',
							)
						),
					);
				}
				return $pre;
			},
			10,
			3
		);

		$result = $this->license->activate_license( 'invalid-key' );

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'Invalid license key', $result['message'] );
	}

	/**
	 * Test validation success.
	 */
	public function test_validate_license_success() {
		update_option( PaySentinel_License::OPTION_SITE_SECRET, 'test_secret_123' );
		$this->mock_validation_success();

		$result = $this->license->validate_license( 'test-key' );

		$this->assertTrue( $result['valid'] );
		$this->assertEquals( 'pro', $result['data']['plan'] );
	}

	/**
	 * Test combined save and validate workflow.
	 */
	public function test_save_and_validate_workflow() {
		$this->mock_activation_success();
		$this->mock_validation_success();

		$result = $this->license->save_and_validate_license( 'test-key' );

		$this->assertTrue( $result['valid'] );
		$this->assertTrue( $result['site_registered'] );

		// Assert options are saved.
		$this->assertEquals( 'test-key', get_option( PaySentinel_License::OPTION_LICENSE_KEY ) );
		$this->assertEquals( 'valid', get_option( PaySentinel_License::OPTION_LICENSE_STATUS ) );
		$this->assertEquals( 'test_secret_123', get_option( PaySentinel_License::OPTION_SITE_SECRET ) );
		$this->assertTrue( (bool) get_option( PaySentinel_License::OPTION_SITE_REGISTERED ) );

		$license_data = get_option( PaySentinel_License::OPTION_LICENSE_DATA );
		$this->assertEquals( 'pro', $license_data['plan'] );
	}

	/**
	 * Test tier detection.
	 */
	public function test_get_license_tier() {
		// Default to free.
		$this->assertEquals( 'free', $this->license->get_license_tier() );

		// Valid pro license.
		update_option( PaySentinel_License::OPTION_LICENSE_STATUS, 'valid' );
		update_option( PaySentinel_License::OPTION_LICENSE_DATA, array( 'plan' => 'pro' ) );
		$this->assertEquals( 'pro', $this->license->get_license_tier() );

		// Invalid license should return free.
		update_option( PaySentinel_License::OPTION_LICENSE_STATUS, 'invalid' );
		$this->assertEquals( 'free', $this->license->get_license_tier() );
	}

	/**
	 * Test feature availability.
	 */
	public function test_has_feature() {
		// No license.
		$this->assertFalse( $this->license->has_feature( 'slack_alerts' ) );

		// Valid license with features.
		update_option( PaySentinel_License::OPTION_LICENSE_STATUS, 'valid' );
		update_option(
			PaySentinel_License::OPTION_LICENSE_DATA,
			array(
				'features' => array(
					'slack_alerts' => true,
				),
			)
		);

		$this->assertTrue( $this->license->has_feature( 'slack_alerts' ) );
		$this->assertFalse( $this->license->has_feature( 'non_existent_feature' ) );
	}

	/**
	 * Test deactivation.
	 */
	public function test_deactivate_license() {
		update_option( PaySentinel_License::OPTION_LICENSE_KEY, 'test-key' );
		update_option( PaySentinel_License::OPTION_LICENSE_STATUS, 'valid' );

		$this->license->deactivate_license();

		$this->assertEmpty( get_option( PaySentinel_License::OPTION_LICENSE_KEY ) );
		$this->assertEmpty( get_option( PaySentinel_License::OPTION_LICENSE_STATUS ) );
		$this->assertEquals( 'unknown', $this->license->get_license_status() );
	}

	/**
	 * Test sync_license.
	 */
	public function test_sync_license_success() {
		update_option( PaySentinel_License::OPTION_LICENSE_KEY, 'test-key' );
		update_option( PaySentinel_License::OPTION_SITE_SECRET, 'test_secret_123' );
		update_option( PaySentinel_License::OPTION_SITE_REGISTERED, true );

		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) {
				if ( $url === PaySentinel_License::API_ENDPOINT_SYNC ) {
					return array(
						'response' => array( 'code' => 200 ),
						'body'     => wp_json_encode(
							array(
								'valid'    => true,
								'plan'     => 'agency',
								'features' => array( 'white_label' => true ),
								'quota'    => array(),
							)
						),
					);
				}
				return $pre;
			},
			10,
			3
		);

		$result = $this->license->sync_license();

		$this->assertTrue( $result['success'] );
		$license_data = get_option( PaySentinel_License::OPTION_LICENSE_DATA );
		$this->assertEquals( 'agency', $license_data['plan'] );
		$this->assertTrue( $license_data['features']['white_label'] );
	}

	/**
	 * Test sync_license when site not registered.
	 */
	public function test_sync_license_unregistered() {
		update_option( PaySentinel_License::OPTION_SITE_REGISTERED, false );

		$result = $this->license->sync_license();

		$this->assertWPError( $result );
		$this->assertEquals( 'site_not_registered', $result->get_error_code() );
	}

	/**
	 * Test user friendly error messages.
	 */
	public function test_get_user_friendly_error_messages() {
		$reflection = new ReflectionClass( $this->license );
		$method     = $reflection->getMethod( 'get_user_friendly_error_message' );
		$method->setAccessible( true );

		// API specific error: License not found.
		$msg = $method->invoke( $this->license, 400, array( 'error' => 'License not found' ) );
		$this->assertStringContainsString( 'Invalid license key', $msg );

		// API specific error: License expired.
		$msg = $method->invoke( $this->license, 400, array( 'error' => 'License expired' ) );
		$this->assertStringContainsString( 'license has expired', $msg );

		// Status code 403.
		$msg = $method->invoke( $this->license, 403, array() );
		$this->assertStringContainsString( 'License access denied', $msg );

		// Status code 429.
		$msg = $method->invoke( $this->license, 429, array() );
		$this->assertStringContainsString( 'Too many license validation attempts', $msg );

		// Status code 500.
		$msg = $method->invoke( $this->license, 500, array() );
		$this->assertStringContainsString( 'server is temporarily unavailable', $msg );
	}

	/**
	 * Test license admin notices.
	 */
	public function test_license_admin_notices() {
		// Mock screen to be on PaySentinel page.
		set_current_screen( 'toplevel_page_payment-monitor' );

		// 1. Unknown status notice.
		update_option( PaySentinel_License::OPTION_LICENSE_STATUS, 'unknown' );
		ob_start();
		$this->license->license_admin_notices();
		$output = ob_get_clean();
		$this->assertStringContainsString( 'Please enter your license key', $output );

		// 2. Invalid status notice.
		update_option( PaySentinel_License::OPTION_LICENSE_STATUS, 'invalid' );
		update_option( PaySentinel_License::OPTION_LICENSE_DATA, array( 'debug_info' => 'API Error 403' ) );
		ob_start();
		$this->license->license_admin_notices();
		$output = ob_get_clean();
		$this->assertStringContainsString( 'license key is invalid or expired', $output );
		$this->assertStringContainsString( 'API Error 403', $output );

		// 3. Valid but unregistered notice.
		update_option( PaySentinel_License::OPTION_LICENSE_STATUS, 'valid' );
		update_option( PaySentinel_License::OPTION_SITE_REGISTERED, false );
		ob_start();
		$this->license->license_admin_notices();
		$output = ob_get_clean();
		$this->assertStringContainsString( 'site is not registered', $output );

		// Reset screen.
		set_current_screen( 'dashboard' );
	}

	/**
	 * Test save_and_validate_license failure: activation failed.
	 */
	public function test_save_and_validate_license_activation_failed() {
		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) {
				if ( $url === PaySentinel_License::API_ENDPOINT_ACTIVATE ) {
					return array(
						'response' => array( 'code' => 403 ),
						'body'     => wp_json_encode( array( 'error' => 'Forbidden' ) ),
					);
				}
				return $pre;
			},
			10,
			3
		);

		$result = $this->license->save_and_validate_license( 'failed-key' );

		$this->assertFalse( $result['valid'] );
		$this->assertEquals( 'invalid', get_option( PaySentinel_License::OPTION_LICENSE_STATUS ) );
	}

	/**
	 * Test save_and_validate_license failure: no site secret.
	 */
	public function test_save_and_validate_license_no_secret() {
		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) {
				if ( $url === PaySentinel_License::API_ENDPOINT_ACTIVATE ) {
					return array(
						'response' => array( 'code' => 200 ),
						'body'     => wp_json_encode(
							array(
								'success'           => true,
								'site_registration' => array( 'registered' => true ), // No site_secret.
							)
						),
					);
				}
				return $pre;
			},
			10,
			3
		);

		$result = $this->license->save_and_validate_license( 'key-without-secret' );

		$this->assertFalse( $result['valid'] );
		$this->assertStringContainsString( 'Site secret not received', $result['message'] );
	}

	/**
	 * Test registration status retrieval.
	 */
	public function test_get_site_registration_status() {
		// No data.
		$status = $this->license->get_site_registration_status();
		$this->assertFalse( $status['registered'] );
		$this->assertEquals( 'not_checked', $status['reason'] );

		// With data.
		$data = array(
			'registered' => true,
			'reason'     => 'Site is registered',
			'checked_at' => time(),
		);
		update_option( PaySentinel_License::OPTION_SITE_REGISTRATION_DATA, $data );

		$status = $this->license->get_site_registration_status();
		$this->assertTrue( $status['registered'] );
		$this->assertEquals( 'Site is registered', $status['reason'] );
	}

	/**
	 * Test daily license check cron.
	 */
	public function test_daily_license_check() {
		update_option( PaySentinel_License::OPTION_LICENSE_KEY, 'test-key' );

		$this->mock_activation_success();
		$this->mock_validation_success();

		// Trigger daily check.
		$this->license->daily_license_check();

		$this->assertEquals( 'valid', get_option( PaySentinel_License::OPTION_LICENSE_STATUS ) );
	}

	/**
	 * Test hourly license sync cron.
	 */
	public function test_hourly_license_sync() {
		update_option( PaySentinel_License::OPTION_LICENSE_KEY, 'test-key' );
		update_option( PaySentinel_License::OPTION_SITE_SECRET, 'test_secret_123' );
		update_option( PaySentinel_License::OPTION_SITE_REGISTERED, true );

		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) {
				if ( $url === PaySentinel_License::API_ENDPOINT_SYNC ) {
					return array(
						'response' => array( 'code' => 200 ),
						'body'     => wp_json_encode(
							array(
								'valid' => true,
								'plan'  => 'pro',
							)
						),
					);
				}
				return $pre;
			},
			10,
			3
		);

		$this->license->hourly_license_sync();

		$data = get_option( PaySentinel_License::OPTION_LICENSE_DATA );
		$this->assertEquals( 'pro', $data['plan'] );
	}

	/**
	 * Test license status and data helpers.
	 */
	public function test_license_helpers() {
		// 1. is_site_registered.
		$this->assertFalse( $this->license->is_site_registered() );
		update_option( PaySentinel_License::OPTION_SITE_REGISTERED, true );
		$this->assertTrue( $this->license->is_site_registered() );

		// 2. get_license_data.
		$this->assertNull( $this->license->get_license_data() );
		$sample_data = array( 'plan' => 'starter' );
		update_option( PaySentinel_License::OPTION_LICENSE_DATA, $sample_data );
		$this->assertEquals( $sample_data, $this->license->get_license_data() );

		// 3. get_license_status.
		$this->assertEquals( 'unknown', $this->license->get_license_status() );
		update_option( PaySentinel_License::OPTION_LICENSE_STATUS, 'valid' );
		$this->assertEquals( 'valid', $this->license->get_license_status() );
	}

	/**
	 * Test sync_license with error responses.
	 */
	public function test_sync_license_errors() {
		update_option( PaySentinel_License::OPTION_LICENSE_KEY, 'test-key' );
		update_option( PaySentinel_License::OPTION_SITE_SECRET, 'test_secret_123' );
		update_option( PaySentinel_License::OPTION_SITE_REGISTERED, true );

		// 401 Unauthorized.
		add_filter(
			'pre_http_request',
			function () {
				return array(
					'response' => array( 'code' => 401 ),
					'body'     => '',
				);
			}
		);
		$result = $this->license->sync_license();
		$this->assertWPError( $result );
		$this->assertEquals( 'unauthorized', $result->get_error_code() );

		// 403 Forbidden.
		remove_all_filters( 'pre_http_request' );
		add_filter(
			'pre_http_request',
			function () {
				return array(
					'response' => array( 'code' => 403 ),
					'body'     => '',
				);
			}
		);
		$result = $this->license->sync_license();
		$this->assertWPError( $result );
		$this->assertEquals( 'forbidden', $result->get_error_code() );
		$this->assertEquals( 'invalid', get_option( PaySentinel_License::OPTION_LICENSE_STATUS ) );

		// 500 Server Error.
		remove_all_filters( 'pre_http_request' );
		add_filter(
			'pre_http_request',
			function () {
				return array(
					'response' => array( 'code' => 500 ),
					'body'     => '',
				);
			}
		);
		$result = $this->license->sync_license();
		$this->assertWPError( $result );
		$this->assertEquals( 'sync_failed', $result->get_error_code() );
	}

	/**
	 * Test sync_license data merging.
	 */
	public function test_sync_license_data_merging() {
		update_option( PaySentinel_License::OPTION_LICENSE_KEY, 'test-key' );
		update_option( PaySentinel_License::OPTION_SITE_SECRET, 'test_secret_123' );
		update_option( PaySentinel_License::OPTION_SITE_REGISTERED, true );
		update_option(
			PaySentinel_License::OPTION_LICENSE_DATA,
			array(
				'plan'         => 'starter',
				'old_artifact' => 'should_keep',
			)
		);

		add_filter(
			'pre_http_request',
			function () {
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode(
						array(
							'plan'         => 'pro',
							'integrations' => array( 'slack' => array( 'id' => 'T12345' ) ),
						)
					),
				);
			}
		);

		$this->license->sync_license();
		$data = get_option( PaySentinel_License::OPTION_LICENSE_DATA );

		$this->assertEquals( 'pro', $data['plan'] );
		$this->assertEquals( 'should_keep', $data['old_artifact'] );
	}

	/**
	 * Test validate_license expiration edge cases.
	 */
	public function test_validate_license_expiration() {
		update_option( PaySentinel_License::OPTION_SITE_SECRET, 'test_secret_123' );

		// 1. Expired license.
		add_filter(
			'pre_http_request',
			function () {
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode(
						array(
							'valid'         => true,
							'expiration_ts' => gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ),
						)
					),
				);
			}
		);

		$result = $this->license->validate_license( 'expired-key' );
		$this->assertFalse( $result['valid'] );

		// 2. Unparseable date (should fallback to valid to be safe).
		remove_all_filters( 'pre_http_request' );
		add_filter(
			'pre_http_request',
			function () {
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode(
						array(
							'valid'         => true,
							'expiration_ts' => 'invalid-date-format',
						)
					),
				);
			}
		);

		$result = $this->license->validate_license( 'weird-key' );
		$this->assertTrue( $result['valid'] );
	}

	/**
	 * Test make_authenticated_request failures.
	 */
	public function test_make_authenticated_request_failures() {
		// Missing details.
		delete_option( PaySentinel_License::OPTION_LICENSE_KEY );
		$result = $this->license->make_authenticated_request( 'https://example.com' );
		$this->assertWPError( $result );
		$this->assertEquals( 'missing_authentication', $result->get_error_code() );
	}

	/**
	 * Test init_hooks.
	 */
	public function test_init_hooks() {
		$this->license->init_hooks();

		$this->assertNotFalse( wp_next_scheduled( 'paysentinel_daily_check' ) );
		$this->assertNotFalse( wp_next_scheduled( 'paysentinel_hourly_sync' ) );
		$this->assertGreaterThan( 0, has_action( 'admin_init', array( $this->license, 'check_license_on_admin' ) ) );
	}

	/**
	 * Test license check on admin is triggered after 24 hours.
	 */
	public function test_check_license_on_admin() {
		update_option( PaySentinel_License::OPTION_LICENSE_KEY, 'test-key' );

		// Mock last check to be 25 hours ago.
		update_option( PaySentinel_License::OPTION_LAST_CHECK, time() - ( 25 * HOUR_IN_SECONDS ) );

		$this->mock_activation_success();
		$this->mock_validation_success();

		$this->license->check_license_on_admin();

		$this->assertEquals( 'valid', get_option( PaySentinel_License::OPTION_LICENSE_STATUS ) );

		// Assert last check was updated.
		$last_check = get_option( PaySentinel_License::OPTION_LAST_CHECK );
		$this->assertGreaterThan( time() - 10, $last_check );
	}

	/**
	 * Test sync_license with exhaustive fields.
	 */
	public function test_sync_license_exhaustive() {
		update_option( PaySentinel_License::OPTION_LICENSE_KEY, 'test-key' );
		update_option( PaySentinel_License::OPTION_SITE_SECRET, 'test_secret_123' );
		update_option( PaySentinel_License::OPTION_SITE_REGISTERED, true );

		$full_response = array(
			'valid'        => true,
			'plan'         => 'agency',
			'plan_color'   => '#ff0000',
			'features'     => array(
				'white_label' => true,
				'custom_logo' => true,
			),
			'quota'        => array(
				'remaining' => 450,
			),
			'expires_at'   => '2026-12-31 23:59:59',
			'integrations' => array(
				'slack' => array( 'id' => 'T99999' ),
			),
		);

		add_filter(
			'pre_http_request',
			function () use ( $full_response ) {
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode( $full_response ),
				);
			}
		);

		$this->license->sync_license();

		$data = get_option( PaySentinel_License::OPTION_LICENSE_DATA );
		$this->assertEquals( 'agency', $data['plan'] );
		$this->assertEquals( '#ff0000', $data['plan_color'] );
		$this->assertEquals( 450, $data['quota']['remaining'] );
	}

	/**
	 * Test authenticated requests (GET vs POST).
	 */
	public function test_authenticated_requests_signing() {
		update_option( PaySentinel_License::OPTION_LICENSE_KEY, 'test-key' );
		update_option( PaySentinel_License::OPTION_SITE_SECRET, 'test_secret_123' );

		$captured_args = null;
		add_filter(
			'pre_http_request',
			function ( $pre, $_args, $_url ) use ( &$captured_args ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable, Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
				$captured_args = $_args;
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => '{}',
				);
			},
			10,
			3
		);

		// 1. POST with body.
		$this->license->make_authenticated_request( 'https://api.test', 'POST', array( 'foo' => 'bar' ) );
		$this->assertEquals( 'POST', $captured_args['method'] );
		$this->assertStringContainsString( '"foo":"bar"', $captured_args['body'] );
		$this->assertNotEmpty( $captured_args['headers']['X-PaySentinel-Signature'] );

		// 2. GET with params (should move to URL).
		$this->license->make_authenticated_request( 'https://api.test', 'GET', array( 'param' => 'val' ) );
		$this->assertEquals( 'GET', $captured_args['method'] );
		$this->assertEmpty( $captured_args['body'] );
		// The URL modification is handled by make_authenticated_request_with_secret and passed to wp_remote_request.
		// Wait, I can't easily check the URL from $args in pre_http_request because it's passed as separate param.
	}
}
