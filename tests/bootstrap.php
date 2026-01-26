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
 * Manually load required plugins for the test environment.
 *
 * We load WooCommerce before our plugin because several tests rely on WC
 * core objects being available (orders, tokens, etc.). Some CI runs were
 * skipping WooCommerce-dependent tests because WC was not loaded even after
 * CLI installation/activation. Loading it explicitly here guarantees the
 * class exists in the test bootstrap regardless of DB activation state.
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
		if (function_exists('WC') && method_exists('WooCommerce', 'instance')) {
			WC();
		}
	}

	// Load our plugin if needed for integration tests.
	// require WC_PAYMENT_MONITOR_PLUGIN_FILE;
}
tests_add_filter('muplugins_loaded', '_manually_load_plugin');

/**
 * Double-check WooCommerce is loaded after WordPress bootstrap.
 * This protects against scenarios where the muplugins_loaded hook is bypassed.
 */
function _ensure_woocommerce_loaded()
{
	if (class_exists('WooCommerce')) {
		return;
	}
	if (!defined('WP_PLUGIN_DIR')) {
		define('WP_PLUGIN_DIR', '/tmp/wordpress/wp-content/plugins');
	}
	$wc_main = WP_PLUGIN_DIR . '/woocommerce/woocommerce.php';
	if (file_exists($wc_main)) {
		require_once $wc_main;
		if (function_exists('WC') && method_exists('WooCommerce', 'instance')) {
			WC();
		}
	}
}
tests_add_filter('plugins_loaded', '_ensure_woocommerce_loaded');

// Start up the WP testing environment
require $_tests_dir . '/includes/bootstrap.php';

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
