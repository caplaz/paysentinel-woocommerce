<?php
/**
 * PHPUnit bootstrap file for WC Payment Monitor tests
 */

// Composer autoloader
if (file_exists(dirname(__DIR__) . '/vendor/autoload.php')) {
	require_once dirname(__DIR__) . '/vendor/autoload.php';
}

// Define test environment constants
define('WC_PAYMENT_MONITOR_PLUGIN_FILE', dirname(__DIR__) . '/wc-payment-monitor.php');
define('WC_PAYMENT_MONITOR_PLUGIN_DIR', dirname(__DIR__) . '/');
define('WC_PAYMENT_MONITOR_PLUGIN_URL', 'http://example.org/wp-content/plugins/wc-payment-monitor/');
define('WC_PAYMENT_MONITOR_PLUGIN_BASENAME', 'wc-payment-monitor/wc-payment-monitor.php');
define('WC_PAYMENT_MONITOR_VERSION', '1.0.0');

// WordPress test environment setup
$_tests_dir = getenv('WP_TESTS_DIR');
if (!$_tests_dir) {
	$_tests_dir = rtrim(sys_get_temp_dir(), '/\\') . '/wordpress-tests-lib';
}

// Give access to tests_add_filter() function
require_once $_tests_dir . '/includes/functions.php';

/**
 * Register hook to load WooCommerce on muplugins_loaded.
 * This ensures WC loads at the earliest hook point in WordPress lifecycle.
 */
function _manually_load_plugin()
{
	if (!defined('WP_PLUGIN_DIR')) {
		define('WP_PLUGIN_DIR', '/tmp/wordpress/wp-content/plugins');
	}

	$wc_main = WP_PLUGIN_DIR . '/woocommerce/woocommerce.php';
	if (file_exists($wc_main)) {
		require_once $wc_main;
		// Ensure WooCommerce fully initializes if not already bootstrapped.
		if (function_exists('WC')) {
			WC();
		}
	}
}
tests_add_filter('muplugins_loaded', '_manually_load_plugin');

// Start up the WP testing environment
require $_tests_dir . '/includes/bootstrap.php';

/**
 * Post-bootstrap WooCommerce loading.
 * WooCommerce needs to load after WordPress is fully initialized.
 * We use wp_loaded hook which fires after all plugins are loaded and initialized.
 */
function _load_woocommerce_after_wp_loaded() {
	if (class_exists('WooCommerce')) {
		return; // Already loaded
	}

	if (!defined('WP_PLUGIN_DIR')) {
		define('WP_PLUGIN_DIR', '/tmp/wordpress/wp-content/plugins');
	}

	$wc_main = WP_PLUGIN_DIR . '/woocommerce/woocommerce.php';
	if (file_exists($wc_main)) {
		// Load WooCommerce plugin file which will define the WooCommerce class
		require_once $wc_main;
	}
}

// Fire on wp_loaded hook to ensure full WordPress initialization
add_action('wp_loaded', '_load_woocommerce_after_wp_loaded', 1);

// Also try to trigger it immediately after WordPress bootstrap in case hooks don't fire
_load_woocommerce_after_wp_loaded();

// Load test base classes
require_once __DIR__ . '/includes/class-wc-payment-monitor-test-case.php';

// Load plugin classes for testing
require_once dirname(__DIR__) . '/includes/class-wc-payment-monitor-database.php';
require_once dirname(__DIR__) . '/includes/class-wc-payment-monitor-logger.php';
require_once dirname(__DIR__) . '/includes/class-wc-payment-monitor-health.php';
require_once dirname(__DIR__) . '/includes/class-wc-payment-monitor-alerts.php';
require_once dirname(__DIR__) . '/includes/class-wc-payment-monitor-retry.php';
require_once dirname(__DIR__) . '/includes/class-wc-payment-monitor-security.php';
require_once dirname(__DIR__) . '/includes/class-wc-payment-monitor-license.php';
require_once dirname(__DIR__) . '/includes/class-wc-payment-monitor-api-base.php';
require_once dirname(__DIR__) . '/includes/class-wc-payment-monitor-api-health.php';
require_once dirname(__DIR__) . '/includes/class-wc-payment-monitor-api-transactions.php';
require_once dirname(__DIR__) . '/includes/class-wc-payment-monitor-admin.php';
