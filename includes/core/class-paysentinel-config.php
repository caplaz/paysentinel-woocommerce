<?php
/**
 * Configuration Management Class
 *
 * Centralizes settings management and provides a clean API for accessing configuration values.
 * Uses singleton pattern to ensure consistent settings access across the plugin.
 *
 * @package PaySentinel
 * @since 1.0.0
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * PaySentinel_Config class
 *
 * Manages plugin configuration with caching and validation.
 */
class PaySentinel_Config {

	/**
	 * Option key constants
	 */
	public const OPTION_MAIN_OPTIONS    = 'paysentinel_options';
	public const OPTION_SETTINGS        = 'paysentinel_settings';
	public const OPTION_SLACK_WORKSPACE = 'paysentinel_slack_workspace';
	public const OPTION_QUOTA_EXCEEDED  = 'paysentinel_quota_exceeded';
	public const OPTION_RETRY_STATS     = 'paysentinel_retry_stats';

	/**
	 * Default values
	 */
	public const DEFAULT_ALERT_THRESHOLD       = 85;
	public const DEFAULT_HEALTH_CHECK_INTERVAL = 5;
	public const DEFAULT_MAX_RETRY_ATTEMPTS    = 3;
	public const DEFAULT_RETRY_DELAY           = 60;
	public const DEFAULT_DATA_RETENTION_DAYS   = 30;

	/**
	 * Singleton instance
	 *
	 * @var PaySentinel_Config|null
	 */
	private static $instance = null;

	/**
	 * Cached settings
	 *
	 * @var array
	 */
	private $cache = array();

	/**
	 * Get singleton instance
	 *
	 * @return PaySentinel_Config
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor
	 */
	private function __construct() {
		$this->load_cache();
	}

	/**
	 * Load settings into cache
	 *
	 * @return void
	 */
	private function load_cache() {
		$this->cache = array(
			'options'         => get_option( self::OPTION_MAIN_OPTIONS, array() ),
			'settings'        => get_option( self::OPTION_SETTINGS, array() ),
			'slack_workspace' => get_option( self::OPTION_SLACK_WORKSPACE, '' ),
			'quota_exceeded'  => get_option( self::OPTION_QUOTA_EXCEEDED, false ),
			'retry_stats'     => get_option( self::OPTION_RETRY_STATS, array() ),
		);
	}

	/**
	 * Clear settings cache
	 *
	 * @return void
	 */
	public function clear_cache() {
		$this->load_cache();
	}

	/**
	 * Get a setting value from main options
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Default value if setting not found.
	 * @return mixed Setting value or default.
	 */
	public function get( $key, $default = null ) {
		if ( isset( $this->cache['options'][ $key ] ) ) {
			return $this->cache['options'][ $key ];
		}
		return $default;
	}

	/**
	 * Set a setting value in main options
	 *
	 * @param string $key   Setting key.
	 * @param mixed  $value Setting value.
	 * @return bool True on success, false on failure.
	 */
	public function set( $key, $value ) {
		$this->cache['options'][ $key ] = $value;
		$result                         = update_option( self::OPTION_MAIN_OPTIONS, $this->cache['options'] );
		return $result;
	}

	/**
	 * Get all settings from main options
	 *
	 * @return array All settings.
	 */
	public function get_all() {
		return $this->cache['options'];
	}

	/**
	 * Update all settings in main options
	 *
	 * @param array $settings Settings array.
	 * @return bool True on success, false on failure.
	 */
	public function update_all( $settings ) {
		if ( ! is_array( $settings ) ) {
			return false;
		}
		$this->cache['options'] = $settings;
		return update_option( self::OPTION_MAIN_OPTIONS, $settings );
	}

	/**
	 * Get a setting from the settings option
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Default value if setting not found.
	 * @return mixed Setting value or default.
	 */
	public function get_setting( $key, $default = null ) {
		if ( isset( $this->cache['settings'][ $key ] ) ) {
			return $this->cache['settings'][ $key ];
		}
		return $default;
	}

	/**
	 * Set a setting in the settings option
	 *
	 * @param string $key   Setting key.
	 * @param mixed  $value Setting value.
	 * @return bool True on success, false on failure.
	 */
	public function set_setting( $key, $value ) {
		$this->cache['settings'][ $key ] = $value;
		return update_option( self::OPTION_SETTINGS, $this->cache['settings'] );
	}

	/**
	 * Get all settings from settings option
	 *
	 * @return array All settings.
	 */
	public function get_all_settings() {
		return $this->cache['settings'];
	}

	/**
	 * Get alert threshold
	 *
	 * @return float Alert threshold percentage.
	 */
	public function get_alert_threshold() {
		$threshold = $this->get( 'alert_threshold', self::DEFAULT_ALERT_THRESHOLD );
		return floatval( $threshold );
	}

	/**
	 * Set alert threshold
	 *
	 * @param float $value Alert threshold percentage (0-100).
	 * @return bool True on success, false on failure.
	 */
	public function set_alert_threshold( $value ) {
		$value = floatval( $value );
		if ( $value < 0 || $value > 100 ) {
			return false;
		}
		return $this->set( 'alert_threshold', $value );
	}

	/**
	 * Get health check interval
	 *
	 * @return int Health check interval in minutes.
	 */
	public function get_health_check_interval() {
		$interval = $this->get( 'health_check_interval', self::DEFAULT_HEALTH_CHECK_INTERVAL );
		return intval( $interval );
	}

	/**
	 * Set health check interval
	 *
	 * @param int $value Interval in minutes (1-1440).
	 * @return bool True on success, false on failure.
	 */
	public function set_health_check_interval( $value ) {
		$value = intval( $value );
		if ( $value < 1 || $value > 1440 ) {
			return false;
		}
		return $this->set( 'health_check_interval', $value );
	}

	/**
	 * Check if monitoring is enabled
	 *
	 * @return bool True if monitoring is enabled.
	 */
	public function is_monitoring_enabled() {
		$enabled = $this->get( 'enable_monitoring', 1 );
		return (bool) $enabled;
	}

	/**
	 * Set monitoring enabled status
	 *
	 * @param bool $enabled Whether monitoring is enabled.
	 * @return bool True on success, false on failure.
	 */
	public function set_monitoring_enabled( $enabled ) {
		return $this->set( 'enable_monitoring', $enabled ? 1 : 0 );
	}

	/**
	 * Check if retry is enabled
	 *
	 * @return bool True if retry is enabled.
	 */
	public function is_retry_enabled() {
		$enabled = $this->get( 'retry_enabled', true );
		return (bool) $enabled;
	}

	/**
	 * Set retry enabled status
	 *
	 * @param bool $enabled Whether retry is enabled.
	 * @return bool True on success, false on failure.
	 */
	public function set_retry_enabled( $enabled ) {
		return $this->set( 'retry_enabled', (bool) $enabled );
	}

	/**
	 * Get maximum retry attempts
	 *
	 * @return int Maximum retry attempts.
	 */
	public function get_max_retry_attempts() {
		$max = $this->get( 'max_retry_attempts', self::DEFAULT_MAX_RETRY_ATTEMPTS );
		return intval( $max );
	}

	/**
	 * Set maximum retry attempts
	 *
	 * @param int $value Maximum retry attempts (1-10).
	 * @return bool True on success, false on failure.
	 */
	public function set_max_retry_attempts( $value ) {
		$value = intval( $value );
		if ( $value < 1 || $value > 10 ) {
			return false;
		}
		return $this->set( 'max_retry_attempts', $value );
	}

	/**
	 * Get alert email address
	 *
	 * @return string Alert email address.
	 */
	public function get_alert_email() {
		return $this->get( 'alert_email', get_option( 'admin_email' ) );
	}

	/**
	 * Set alert email address
	 *
	 * @param string $email Email address.
	 * @return bool True on success, false on failure.
	 */
	public function set_alert_email( $email ) {
		if ( ! is_email( $email ) ) {
			return false;
		}
		return $this->set( 'alert_email', sanitize_email( $email ) );
	}

	/**
	 * Get alert phone number
	 *
	 * @return string Alert phone number.
	 */
	public function get_alert_phone() {
		return $this->get( 'alert_phone', '' );
	}

	/**
	 * Set alert phone number
	 *
	 * @param string $phone Phone number.
	 * @return bool True on success, false on failure.
	 */
	public function set_alert_phone( $phone ) {
		return $this->set( 'alert_phone', sanitize_text_field( $phone ) );
	}

	/**
	 * Check if test mode is enabled
	 *
	 * @return bool True if test mode is enabled.
	 */
	public function is_test_mode_enabled() {
		$enabled = $this->get( 'test_mode', false );
		return (bool) $enabled;
	}

	/**
	 * Set test mode enabled status
	 *
	 * @param bool $enabled Whether test mode is enabled.
	 * @return bool True on success, false on failure.
	 */
	public function set_test_mode_enabled( $enabled ) {
		return $this->set( 'test_mode', (bool) $enabled );
	}

	/**
	 * Get enabled gateways
	 *
	 * @return array Array of enabled gateway IDs.
	 */
	public function get_enabled_gateways() {
		$gateways = $this->get_setting( 'enabled_gateways', array() );
		if ( ! is_array( $gateways ) ) {
			return array();
		}
		return $gateways;
	}

	/**
	 * Set enabled gateways
	 *
	 * @param array $gateways Array of gateway IDs.
	 * @return bool True on success, false on failure.
	 */
	public function set_enabled_gateways( $gateways ) {
		if ( ! is_array( $gateways ) ) {
			return false;
		}
		return $this->set_setting( 'enabled_gateways', $gateways );
	}

	/**
	 * Get Slack workspace ID
	 *
	 * @return string Slack workspace ID.
	 */
	public function get_slack_workspace() {
		return $this->cache['slack_workspace'];
	}

	/**
	 * Set Slack workspace ID
	 *
	 * @param string $workspace_id Slack workspace ID.
	 * @return bool True on success, false on failure.
	 */
	public function set_slack_workspace( $workspace_id ) {
		$workspace_id                   = sanitize_text_field( $workspace_id );
		$this->cache['slack_workspace'] = $workspace_id;
		return update_option( self::OPTION_SLACK_WORKSPACE, $workspace_id );
	}

	/**
	 * Clear Slack workspace ID
	 *
	 * @return bool True on success, false on failure.
	 */
	public function clear_slack_workspace() {
		$this->cache['slack_workspace'] = '';
		return delete_option( self::OPTION_SLACK_WORKSPACE );
	}

	/**
	 * Check if quota is exceeded
	 *
	 * @return bool True if quota is exceeded.
	 */
	public function is_quota_exceeded() {
		return (bool) $this->cache['quota_exceeded'];
	}

	/**
	 * Set quota exceeded status
	 *
	 * @param bool $exceeded Whether quota is exceeded.
	 * @return bool True on success, false on failure.
	 */
	public function set_quota_exceeded( $exceeded ) {
		$this->cache['quota_exceeded'] = (bool) $exceeded;
		return update_option( self::OPTION_QUOTA_EXCEEDED, (bool) $exceeded );
	}

	/**
	 * Get retry statistics
	 *
	 * @return array Retry statistics.
	 */
	public function get_retry_stats() {
		return $this->cache['retry_stats'];
	}

	/**
	 * Set retry statistics
	 *
	 * @param array $stats Retry statistics.
	 * @return bool True on success, false on failure.
	 */
	public function set_retry_stats( $stats ) {
		if ( ! is_array( $stats ) ) {
			return false;
		}
		$this->cache['retry_stats'] = $stats;
		return update_option( self::OPTION_RETRY_STATS, $stats );
	}

	/**
	 * Get notification frequency
	 *
	 * @return string Notification frequency ('immediate', 'daily', 'hourly').
	 */
	public function get_notification_frequency() {
		return $this->get( 'notification_frequency', 'immediate' );
	}

	/**
	 * Set notification frequency
	 *
	 * @param string $frequency Notification frequency.
	 * @return bool True on success, false on failure.
	 */
	public function set_notification_frequency( $frequency ) {
		$valid_frequencies = array( 'immediate', 'hourly', 'daily' );
		if ( ! in_array( $frequency, $valid_frequencies, true ) ) {
			return false;
		}
		return $this->set( 'notification_frequency', $frequency );
	}

	/**
	 * Check if email notifications are enabled
	 *
	 * @return bool True if email notifications are enabled.
	 */
	public function is_email_notifications_enabled() {
		$enabled = $this->get( 'enable_email_notifications', true );
		return (bool) $enabled;
	}

	/**
	 * Set email notifications enabled status
	 *
	 * @param bool $enabled Whether email notifications are enabled.
	 * @return bool True on success, false on failure.
	 */
	public function set_email_notifications_enabled( $enabled ) {
		return $this->set( 'enable_email_notifications', (bool) $enabled );
	}

	/**
	 * Check if Slack notifications are enabled
	 *
	 * @return bool True if Slack notifications are enabled.
	 */
	public function is_slack_notifications_enabled() {
		$enabled = $this->get( 'enable_slack_notifications', false );
		return (bool) $enabled;
	}

	/**
	 * Set Slack notifications enabled status
	 *
	 * @param bool $enabled Whether Slack notifications are enabled.
	 * @return bool True on success, false on failure.
	 */
	public function set_slack_notifications_enabled( $enabled ) {
		return $this->set( 'enable_slack_notifications', (bool) $enabled );
	}

	/**
	 * Get retry delay in minutes
	 *
	 * @return int Retry delay in minutes.
	 */
	public function get_retry_delay() {
		$delay = $this->get( 'retry_delay', self::DEFAULT_RETRY_DELAY );
		return intval( $delay );
	}

	/**
	 * Set retry delay
	 *
	 * @param int $minutes Delay in minutes (1-1440).
	 * @return bool True on success, false on failure.
	 */
	public function set_retry_delay( $minutes ) {
		$minutes = intval( $minutes );
		if ( $minutes < 1 || $minutes > 1440 ) {
			return false;
		}
		return $this->set( 'retry_delay', $minutes );
	}

	/**
	 * Get data retention days
	 *
	 * @return int Data retention days.
	 */
	public function get_data_retention_days() {
		$days = $this->get( 'data_retention_days', self::DEFAULT_DATA_RETENTION_DAYS );
		return intval( $days );
	}

	/**
	 * Set data retention days
	 *
	 * @param int $days Number of days (1-365).
	 * @return bool True on success, false on failure.
	 */
	public function set_data_retention_days( $days ) {
		$days = intval( $days );
		if ( $days < 1 || $days > 365 ) {
			return false;
		}
		return $this->set( 'data_retention_days', $days );
	}

	/**
	 * Get debug mode status
	 *
	 * @return bool True if debug mode is enabled.
	 */
	public function is_debug_mode_enabled() {
		$enabled = $this->get( 'debug_mode', false );
		return (bool) $enabled;
	}

	/**
	 * Set debug mode enabled status
	 *
	 * @param bool $enabled Whether debug mode is enabled.
	 * @return bool True on success, false on failure.
	 */
	public function set_debug_mode_enabled( $enabled ) {
		return $this->set( 'debug_mode', (bool) $enabled );
	}
}
