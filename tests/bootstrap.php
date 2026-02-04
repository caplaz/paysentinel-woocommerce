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
 * Load WooCommerce and the plugin being tested on muplugins_loaded hook.
 * This matches the pattern used by WooCommerce core and major extensions.
 * muplugins_loaded is the earliest hook where plugin loading is appropriate.
 */
function _manually_load_plugin()
{
    // Define WP_PLUGIN_DIR if not already defined
    if (!defined('WP_PLUGIN_DIR')) {
        define('WP_PLUGIN_DIR', '/tmp/wordpress/wp-content/plugins');
    }

    // Load WooCommerce FIRST
    $wc_main = WP_PLUGIN_DIR . '/woocommerce/woocommerce.php';
    if (file_exists($wc_main)) {
        require_once $wc_main;
    }

    // Load the plugin being tested
    require_once WC_PAYMENT_MONITOR_PLUGIN_FILE;
}

tests_add_filter('muplugins_loaded', '_manually_load_plugin');

// Ensure WooCommerce is installed when tests run.
// This must happen after WooCommerce loads but during test setup.
function _install_woocommerce_for_tests()
{
    if (class_exists('WC_Install')) {
        WC_Install::install();
    }

    // Create plugin tables
    if (class_exists('WC_Payment_Monitor_Database')) {
        (new WC_Payment_Monitor_Database())->create_tables();
    }
}

// Run installation on setup_theme hook - this is called during test bootstrap
// and ensures WooCommerce database tables and options are properly initialized
tests_add_filter('setup_theme', '_install_woocommerce_for_tests');

// Start up the WP testing environment
// This will trigger the muplugins_loaded and setup_theme hooks we registered above
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
