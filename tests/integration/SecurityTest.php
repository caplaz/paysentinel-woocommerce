<?php
/**
 * Integration tests for PaySentinel_Security.
 *
 * @package PaySentinel
 */

/**
 * Class SecurityTest
 */
class SecurityTest extends WP_UnitTestCase {



	/**
	 * Test encryption and decryption.
	 */
	public function test_encryption_decryption() {
		$data = 'secret_password_123';

		// Ensure AUTH_KEY is defined for testing if it's not already.
		if ( ! defined( 'AUTH_KEY' ) ) {
			define( 'AUTH_KEY', 'test_auth_key_for_phpunit' );
		}

		$encrypted = PaySentinel_Security::encrypt_credential( $data );
		$this->assertNotFalse( $encrypted );
		$this->assertNotEquals( $data, $encrypted );

		$decrypted = PaySentinel_Security::decrypt_credential( $encrypted );
		$this->assertEquals( $data, $decrypted );

		// Test empty input.
		$this->assertFalse( PaySentinel_Security::encrypt_credential( '' ) );
		$this->assertFalse( PaySentinel_Security::decrypt_credential( '' ) );

		// Test validation method.
		$this->assertTrue( PaySentinel_Security::validate_encryption() );
	}

	/**
	 * Test HMAC signature generation.
	 */
	public function test_hmac_signature() {
		$payload   = array(
			'z' => 'last',
			'a' => 'first',
			'm' => array(
				'2' => 'inner_last',
				'1' => 'inner_first',
			),
		);
		$timestamp = 1234567890;
		$secret    = 'top_secret_site_key';

		$signature = PaySentinel_Security::generate_hmac_signature( $payload, $timestamp, $secret );
		$this->assertNotEmpty( $signature );

		// Generate again with same data, should be identical.
		$signature2 = PaySentinel_Security::generate_hmac_signature( $payload, $timestamp, $secret );
		$this->assertEquals( $signature, $signature2 );

		// Test with string payload.
		$string_payload = '{"a":"first","m":{"1":"inner_first","2":"inner_last"},"z":"last"}';
		$signature3     = PaySentinel_Security::generate_hmac_signature( $string_payload, $timestamp, $secret );
		$this->assertEquals( $signature, $signature3 );
	}

	/**
	 * Test recursive ksort.
	 */
	public function test_recursive_ksort() {
		$array = array(
			'c' => 3,
			'a' => 1,
			'b' => array(
				'y' => 2,
				'x' => 1,
			),
		);

		PaySentinel_Security::recursive_ksort( $array );

		$keys = array_keys( $array );
		$this->assertEquals( array( 'a', 'b', 'c' ), $keys );

		$inner_keys = array_keys( $array['b'] );
		$this->assertEquals( array( 'x', 'y' ), $inner_keys );
	}

	/**
	 * Test sensitive data management.
	 */
	public function test_sensitive_data_filtering() {
		$data = array(
			'user_id' => 123,
			'api_key' => 'sk_test_123',
			'nested'  => array(
				'token' => 'abc',
				'name'  => 'John',
			),
		);

		// 1. Exclusion.
		$excluded = PaySentinel_Security::exclude_sensitive_data( $data );
		$this->assertArrayHasKey( 'user_id', $excluded );
		$this->assertArrayNotHasKey( 'api_key', $excluded );
		$this->assertArrayNotHasKey( 'token', $excluded['nested'] );
		$this->assertArrayHasKey( 'name', $excluded['nested'] );

		// 2. Masking.
		$masked = PaySentinel_Security::mask_sensitive_data( $data );
		$this->assertEquals( '***REDACTED***', $masked['api_key'] );
		$this->assertEquals( '***REDACTED***', $masked['nested']['token'] );
		$this->assertEquals( 123, $masked['user_id'] );
	}

	/**
	 * Test SQL preparation and execution.
	 */
	public function test_sql_utilities() {
		global $wpdb;

		// Test preparation.
		$query    = "SELECT * FROM {$wpdb->prefix}users WHERE ID = %d";
		$prepared = PaySentinel_Security::prepare_sql_query( $query, array( 1 ) );
		$this->assertStringContainsString( 'WHERE ID = 1', $prepared );

		// Test execution (SELECT).
		$results = PaySentinel_Security::execute_query( "SELECT ID FROM {$wpdb->prefix}users WHERE ID = %d", array( 1 ) );
		$this->assertIsArray( $results );

		// Test execution (Other).
		$count = PaySentinel_Security::execute_query( "UPDATE {$wpdb->prefix}options SET option_value = %s WHERE option_name = %s", array( 'new_val', 'non_existent_key' ) );
		$this->assertEquals( 0, $count );
	}

	/**
	 * Test user capability.
	 */
	public function test_check_user_capability() {
		$user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$this->assertTrue( PaySentinel_Security::check_user_capability( 'manage_options' ) );

		$subscriber_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		$this->assertFalse( PaySentinel_Security::check_user_capability( 'manage_options', $subscriber_id ) );
	}

	/**
	 * Test API Authentication validation.
	 */
	public function test_validate_api_authentication() {
		// 1. Not logged in.
		wp_set_current_user( 0 );
		$result = PaySentinel_Security::validate_api_authentication();
		$this->assertWPError( $result );
		$this->assertEquals( 'not_authenticated', $result->get_error_code() );

		// 2. Logged in, no permissions.
		$user_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );
		$result = PaySentinel_Security::validate_api_authentication();
		$this->assertWPError( $result );
		$this->assertEquals( 'insufficient_permissions', $result->get_error_code() );

		// 3. Logged in with permissions.
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		$admin    = get_userdata( $admin_id );
		$admin->add_cap( 'manage_woocommerce' );
		wp_set_current_user( $admin_id );
		$result = PaySentinel_Security::validate_api_authentication();
		$this->assertTrue( $result );
	}

	/**
	 * Test security headers.
	 */
	public function test_add_security_headers() {
		if ( ! function_exists( 'xdebug_get_headers' ) ) {
			$this->markTestSkipped( 'Xdebug is required to test headers' );
		}

		PaySentinel_Security::add_security_headers();
		$headers = xdebug_get_headers();

		$found = 0;
		foreach ( $headers as $header ) {
			if ( strpos( $header, 'X-Frame-Options' ) !== false ) {
				++$found;
			}
			if ( strpos( $header, 'X-Content-Type-Options' ) !== false ) {
				++$found;
			}
			if ( strpos( $header, 'Content-Security-Policy' ) !== false ) {
				++$found;
			}
		}
		$this->assertGreaterThanOrEqual( 3, $found );
	}

	/**
	 * Test admin settings validation.
	 */
	public function test_validate_admin_settings() {
		$settings = array(
			'current_tab'       => 'general',
			'enable_monitoring' => '1',
			'retry_enabled'     => '1',
			'some_number'       => '42',
			'some_text'         => 'Hello World',
			'DROP TABLE users'  => 'illegal_key', // This key should be rejected.
			'nested'            => array(
				'key' => 'value',
			),
		);

		$validated = PaySentinel_Security::validate_admin_settings( $settings );

		$this->assertEquals( 1, $validated['enable_monitoring'] );
		$this->assertEquals( 42, $validated['some_number'] );
		$this->assertEquals( 'Hello World', $validated['some_text'] );
		$this->assertArrayNotHasKey( 'DROP TABLE users', $validated );
		$this->assertEquals( 'value', $validated['nested']['key'] );

		// Test unchecked checkboxes.
		$settings_unchecked  = array( 'current_tab' => 'general' );
		$validated_unchecked = PaySentinel_Security::validate_admin_settings( $settings_unchecked );
		$this->assertEquals( 0, $validated_unchecked['enable_monitoring'] );
		$this->assertEquals( 0, $validated_unchecked['retry_enabled'] );

		// Test advanced tab.
		$settings_advanced  = array( 'current_tab' => 'advanced' );
		$validated_advanced = PaySentinel_Security::validate_admin_settings( $settings_advanced );
		$this->assertEquals( 0, $validated_advanced['enable_test_mode'] );
	}

	/**
	 * Test SQL injection detection.
	 */
	public function test_contains_sql_injection() {
		$reflection = new ReflectionClass( 'PaySentinel_Security' );
		$method     = $reflection->getMethod( 'contains_sql_injection' );
		$method->setAccessible( true );

		$this->assertTrue( $method->invoke( null, 'SELECT * FROM wp_users' ) );
		$this->assertTrue( $method->invoke( null, '1 OR 1=1' ) );
		$this->assertTrue( $method->invoke( null, 'UNION SELECT' ) );
		$this->assertTrue( $method->invoke( null, "';" ) );
		$this->assertFalse( $method->invoke( null, 'Normal String' ) );
	}
}
