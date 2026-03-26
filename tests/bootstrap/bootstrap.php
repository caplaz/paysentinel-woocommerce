<?php
/**
 * PHPUnit bootstrap file for PaySentinel tests.
 *
 * @package PaySentinel
 */

// Composer autoloader.
if ( file_exists( dirname( __DIR__ ) . '/vendor/autoload.php' ) ) {
	require_once dirname( __DIR__ ) . '/vendor/autoload.php';
}

// Define test environment constants.
define( 'PAYSENTINEL_PLUGIN_FILE', dirname( dirname( __DIR__ ) ) . '/paysentinel.php' );
define( 'PAYSENTINEL_PLUGIN_DIR', dirname( dirname( __DIR__ ) ) . '/' );
define( 'PAYSENTINEL_PLUGIN_URL', 'http://example.org/wp-content/plugins/paysentinel/' );
define( 'PAYSENTINEL_PLUGIN_BASENAME', 'paysentinel/paysentinel.php' );
define( 'PAYSENTINEL_VERSION', '1.0.0' );

// WordPress test environment setup.
$_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $_tests_dir ) {
	$_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

// Give access to tests_add_filter() function.
require_once $_tests_dir . '/includes/functions.php';

/**
 * Load WooCommerce and the plugin being tested on muplugins_loaded hook.
 * This matches the pattern used by WooCommerce core and major extensions.
 * muplugins_loaded is the earliest hook where plugin loading is appropriate.
 */
function _manually_load_plugin() {
	// Define WP_PLUGIN_DIR if not already defined.
	if ( ! defined( 'WP_PLUGIN_DIR' ) ) {
		define( 'WP_PLUGIN_DIR', '/tmp/wordpress/wp-content/plugins' );
	}

	// Load WooCommerce FIRST.
	$wc_main = WP_PLUGIN_DIR . '/woocommerce/woocommerce.php';
	if ( file_exists( $wc_main ) ) {
		require_once $wc_main;
	}

	// Load the plugin being tested.
	require_once PAYSENTINEL_PLUGIN_FILE;
}

tests_add_filter( 'muplugins_loaded', '_manually_load_plugin' );

// Ensure WooCommerce is installed when tests run.
// This must happen after WooCommerce loads but during test setup.
/**
 * Install WooCommerce for tests.
 */
function _install_woocommerce_for_tests() {
	if ( class_exists( 'WC_Install' ) ) {
		WC_Install::install();
	}
}

// Run installation on setup_theme hook - this is called during test bootstrap.
// and ensures WooCommerce database tables and options are properly initialized.
tests_add_filter( 'setup_theme', '_install_woocommerce_for_tests' );

// Create plugin tables after classes are loaded.
/**
 * Create plugin database tables.
 */
function _create_plugin_tables() {
	if ( class_exists( 'PaySentinel_Database' ) ) {
		( new PaySentinel_Database() )->create_tables();
	}
}
tests_add_filter( 'init', '_create_plugin_tables' );

// Start up the WP testing environment.
// This will trigger the muplugins_loaded and setup_theme hooks we registered above.
require $_tests_dir . '/includes/bootstrap.php';

// Load test base classes.
require_once __DIR__ . '/../includes/class-paysentinel-test-case.php';

// Load plugin classes for testing.
require_once dirname( dirname( __DIR__ ) ) . '/includes/core/class-paysentinel-database.php';
require_once dirname( dirname( __DIR__ ) ) . '/includes/core/class-paysentinel-logger.php';
require_once dirname( dirname( __DIR__ ) ) . '/includes/core/class-paysentinel-health.php';
require_once dirname( dirname( __DIR__ ) ) . '/includes/alerts/class-paysentinel-alerts.php';
require_once dirname( dirname( __DIR__ ) ) . '/includes/core/class-paysentinel-retry.php';
require_once dirname( dirname( __DIR__ ) ) . '/includes/core/class-paysentinel-security.php';
require_once dirname( dirname( __DIR__ ) ) . '/includes/core/class-paysentinel-license.php';
require_once dirname( dirname( __DIR__ ) ) . '/includes/api/class-paysentinel-api-base.php';
require_once dirname( dirname( __DIR__ ) ) . '/includes/api/class-paysentinel-api-health.php';
require_once dirname( dirname( __DIR__ ) ) . '/includes/api/class-paysentinel-api-transactions.php';
require_once dirname( dirname( __DIR__ ) ) . '/includes/admin/class-paysentinel-admin.php';
