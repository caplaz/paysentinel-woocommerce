<?php
/**
 * Alert system tests across license tiers.
 *
 * @package PaySentinel
 */

/**
 * Class AlertLicenseTierTest
 */
class AlertLicenseTierTest extends WP_UnitTestCase {


	/**
	 * Database helper instance.
	 *
	 * @var PaySentinel_Database|null
	 */
	private $database;

	/**
	 * Internal alert checker instance.
	 *
	 * @var object|null
	 */
	private $checker;

	/**
	 * Setup with given license tier and settings.
	 *
	 * @param string     $plan               License plan identifier.
	 * @param array|null $per_gateway_config Optional per-gateway config array.
	 */
	private function setup_with_license( $plan, $per_gateway_config = null ) {
		// Reset the static singleton to force reload from options.
		$reflection = new ReflectionClass( 'PaySentinel_Config' );
		$property   = $reflection->getProperty( 'instance' );
		$property->setAccessible( true );
		$property->setValue( null, null );

		// Clean up.
		global $wpdb;
		$this->database = new PaySentinel_Database();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( "DELETE FROM {$this->database->get_alerts_table()}" );

		delete_option( 'paysentinel_options' );
		delete_option( PaySentinel_License::OPTION_SITE_REGISTERED );
		delete_option( PaySentinel_License::OPTION_LICENSE_STATUS );
		delete_option( PaySentinel_License::OPTION_LICENSE_DATA );

		// Register site.
		update_option( PaySentinel_License::OPTION_SITE_REGISTERED, true );
		update_option( PaySentinel_License::OPTION_LICENSE_STATUS, 'valid' );

		$plan_data = array( 'plan' => $plan );
		if ( 'agency' === $plan ) {
			$plan_data['features'] = array( 'per_gateway_config' => true );
		}
		update_option( PaySentinel_License::OPTION_LICENSE_DATA, $plan_data );

		// Setup settings in paysentinel_options (this is what get_all() reads from).
		$settings = array(
			PaySentinel_Settings_Constants::ALERT_THRESHOLD => 85, // phpcs:ignore Squiz.PHP.CommentedOutCode.Found -- Global default.
		);

		if ( null !== $per_gateway_config ) {
			$settings[ PaySentinel_Settings_Constants::GATEWAY_ALERT_CONFIG ] = $per_gateway_config;
		}

		update_option( 'paysentinel_options', $settings );

		// Clear config cache (reinitialize from fresh options).
		PaySentinel_Config::instance()->clear_cache();

		// Reinitialize alert system.
		$alerts = new PaySentinel_Alerts();

		$reflection = new ReflectionClass( $alerts );
		$property   = $reflection->getProperty( 'checker' );
		$property->setAccessible( true );
		$this->checker = $property->getValue( $alerts );
	}

	/**
	 * Tear down test environment.
	 */
	public function tearDown(): void {
		parent::tearDown();
		global $wpdb;
		if ( $this->database ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query( "DELETE FROM {$this->database->get_alerts_table()}" );
		}
	}

	/**
	 * Test: Free tier with global default threshold
	 */
	public function test_free_tier_creates_alerts() {
		$this->setup_with_license( 'free', null );

		// 0% success rate triggers alert (below 85% global threshold).
		$health_data = array(
			'current' => array(
				'success_rate'        => 0.0,
				'total_transactions'  => 4,
				'failed_transactions' => 4,
			),
		);

		$this->checker->check_gateway_alerts( 'stripe', $health_data );

		global $wpdb;
		$table = $this->database->get_alerts_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$alert = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM {$table} WHERE gateway_id = %s AND alert_type = 'low_success_rate'",
				'stripe'
			)
		);

		$this->assertNotNull( $alert, 'Free tier should create alert for 0% success rate (below 85% default)' );
		$this->assertEquals( 'stripe', $alert->gateway_id );
	}

	/**
	 * Test: Starter tier with global threshold
	 */
	public function test_starter_tier_creates_alerts() {
		$this->setup_with_license( 'starter', null );

		// 80% success triggers alert (below 85% threshold).
		$health_data = array(
			'current' => array(
				'success_rate'        => 80.0,
				'total_transactions'  => 10,
				'failed_transactions' => 2,
			),
		);

		$this->checker->check_gateway_alerts( 'paypal', $health_data );

		global $wpdb;
		$table = $this->database->get_alerts_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$alert = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM {$table} WHERE gateway_id = %s AND alert_type = 'low_success_rate'",
				'paypal'
			)
		);

		$this->assertNotNull( $alert, 'Starter tier should create alert for 80% success rate (below 85% threshold)' );
	}

	/**
	 * Test: Agency tier with per-gateway config - enabled gateway
	 */
	public function test_agency_tier_per_gateway_enabled() {
		$per_gateway_config = array(
			'klarna' => array(
				PaySentinel_Settings_Constants::GATEWAY_CONFIG_ENABLED => true,
				PaySentinel_Settings_Constants::GATEWAY_CONFIG_THRESHOLD => 85,
				PaySentinel_Settings_Constants::GATEWAY_CONFIG_CHANNELS => array( 'email' ),
			),
		);

		$this->setup_with_license( 'agency', $per_gateway_config );

		// 0% success rate triggers alert.
		$health_data = array(
			'current' => array(
				'success_rate'        => 0.0,
				'total_transactions'  => 4,
				'failed_transactions' => 4,
			),
		);

		$this->checker->check_gateway_alerts( 'klarna', $health_data );

		global $wpdb;
		$table = $this->database->get_alerts_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$alert = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM {$table} 
				WHERE gateway_id = %s AND alert_type = 'low_success_rate'",
				'klarna'
			)
		);

		$this->assertNotNull( $alert, 'Agency tier should create alert for enabled Klarna gateway at 0%' );
		$this->assertEquals( 'klarna', $alert->gateway_id );
		$this->assertNotEmpty( $alert->message );
		$this->assertStringContainsString( '0.00%', $alert->message );
	}

	/**
	 * Test: Agency tier with per-gateway config - disabled gateway
	 */
	public function test_agency_tier_per_gateway_disabled() {
		$per_gateway_config = array(
			'paypal' => array(
				PaySentinel_Settings_Constants::GATEWAY_CONFIG_ENABLED => false, // Disabled.
				PaySentinel_Settings_Constants::GATEWAY_CONFIG_THRESHOLD => 85,
				PaySentinel_Settings_Constants::GATEWAY_CONFIG_CHANNELS => array( 'email' ),
			),
		);

		$this->setup_with_license( 'agency', $per_gateway_config );

		// Even with 0% success, should NOT alert (disabled).
		$health_data = array(
			'current' => array(
				'success_rate'        => 0.0,
				'total_transactions'  => 20,
				'failed_transactions' => 20,
			),
		);

		$this->checker->check_gateway_alerts( 'paypal', $health_data );

		global $wpdb;
		$table = $this->database->get_alerts_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$alert_count = $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT COUNT(*) FROM {$table} WHERE gateway_id = %s",
				'paypal'
			)
		);

		$this->assertEquals( 0, $alert_count, 'Disabled gateway should NOT create alerts even at 0%' );
	}

	/**
	 * Test: Agency tier fallback to global for unconfigured gateway
	 */
	public function test_agency_tier_fallback_to_global() {
		$per_gateway_config = array(
			'klarna' => array(
				PaySentinel_Settings_Constants::GATEWAY_CONFIG_ENABLED => true,
				PaySentinel_Settings_Constants::GATEWAY_CONFIG_THRESHOLD => 50, // Only config Klarna.
				PaySentinel_Settings_Constants::GATEWAY_CONFIG_CHANNELS => array( 'email' ),
			),
		);

		$this->setup_with_license( 'agency', $per_gateway_config );

		// Test unconfigured gateway stripe at 80% (should use global 85%).
		$health_data = array(
			'current' => array(
				'success_rate'        => 80.0,
				'total_transactions'  => 10,
				'failed_transactions' => 2,
			),
		);

		$this->checker->check_gateway_alerts( 'stripe', $health_data );

		global $wpdb;
		$table = $this->database->get_alerts_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$alert = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM {$table} WHERE gateway_id = %s AND alert_type = 'low_success_rate'",
				'stripe'
			)
		);

		$this->assertNotNull( $alert, 'Unconfigured gateway should fallback to global 85% threshold' );
		$this->assertEquals( 'stripe', $alert->gateway_id );
	}
}
