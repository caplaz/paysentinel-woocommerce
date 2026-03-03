<?php
/**
 * PaySentinel Settings Constants
 *
 * Centralized constants for all settings keys to prevent typos and ensure consistency.
 *
 * @package PaySentinel
 * @since 1.0.0
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * PaySentinel_Settings_Constants class
 *
 * Defines all settings keys as class constants for type safety and consistency.
 */
class PaySentinel_Settings_Constants {

	/**
	 * Alert Settings Keys
	 */
	public const ALERT_EMAIL                  = 'alert_email';
	public const ALERT_PHONE_NUMBER           = 'alert_phone_number';
	public const ALERT_SLACK_WORKSPACE        = 'alert_slack_workspace';
	public const ALERT_THRESHOLD              = 'alert_threshold';
	public const IMMEDIATE_TRANSACTION_ALERTS = 'immediate_transaction_alerts';

	/**
	 * Gateway Settings Keys
	 */
	public const GATEWAY_ALERT_CONFIG = 'gateway_alert_config';
	public const ENABLED_GATEWAYS     = 'enabled_gateways';

	/**
	 * Monitoring & Health Check Keys
	 */
	public const HEALTH_CHECK_INTERVAL = 'health_check_interval';
	public const ENABLE_MONITORING     = 'enable_monitoring';

	/**
	 * Retry Settings Keys
	 */
	public const RETRY_ENABLED      = 'retry_enabled';
	public const RETRY_SCHEDULE     = 'retry_schedule';
	public const MAX_RETRY_ATTEMPTS = 'max_retry_attempts';

	/**
	 * Per-Gateway Configuration Nested Keys
	 */
	public const GATEWAY_CONFIG_ENABLED   = 'enabled';
	public const GATEWAY_CONFIG_THRESHOLD = 'threshold';
	public const GATEWAY_CONFIG_CHANNELS  = 'channels';

	/**
	 * Alert Notification Channels
	 */
	public const CHANNEL_EMAIL = 'email';
	public const CHANNEL_SMS   = 'sms';
	public const CHANNEL_SLACK = 'slack';

	/**
	 * Metadata Keys
	 */
	public const GATEWAY_METRICS = 'gateway_metrics';

	/**
	 * Test/Debug Settings Keys
	 */
	public const ENABLE_TEST_MODE       = 'enable_test_mode';
	public const TEST_FAILURE_RATE      = 'test_failure_rate';
	public const TEST_FAILURE_SCENARIOS = 'test_failure_scenarios';

	/**
	 * UI State Keys (temporary, not persisted)
	 */
	public const CURRENT_TAB = 'current_tab';
}
