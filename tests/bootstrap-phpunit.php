<?php

/**
 * PHPUnit Bootstrap - Define WordPress constants before tests run
 * Prevents syntax errors when PHPUnit analyzes plugin files
 */

// Composer autoloader
if (file_exists(dirname(__DIR__) . '/vendor/autoload.php')) {
    require_once dirname(__DIR__) . '/vendor/autoload.php';
}

// Define ABSPATH to prevent "exit" statements in plugin classes
if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}

// Prevent PHP notices when plugin files are analyzed
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);

// Define WordPress constants
if (!defined('WP_MEMORY_LIMIT')) {
    define('WP_MEMORY_LIMIT', '256M');
}

if (!defined('WP_MAX_MEMORY_LIMIT')) {
    define('WP_MAX_MEMORY_LIMIT', '512M');
}

if (!defined('WP_DEBUG')) {
    define('WP_DEBUG', false);
}

if (!defined('DB_NAME')) {
    define('DB_NAME', 'test_db');
}

// Define plugin constants
define('WC_PAYMENT_MONITOR_PLUGIN_FILE', dirname(__DIR__) . '/wc-payment-monitor.php');
define('WC_PAYMENT_MONITOR_PLUGIN_DIR', dirname(__DIR__) . '/');
define('WC_PAYMENT_MONITOR_PLUGIN_URL', 'http://example.org/wp-content/plugins/wc-payment-monitor/');
define('WC_PAYMENT_MONITOR_PLUGIN_BASENAME', 'wc-payment-monitor/wc-payment-monitor.php');
define('WC_PAYMENT_MONITOR_VERSION', '1.0.0');

// Mock global $wpdb
global $wpdb;
$wpdb = new class () {
    public function get_var($query)
    {
        // Return a default value for database size queries
        if (strpos($query, 'information_schema') !== false) {
            return '1024.00';
        }
        return 1; // Default for table existence checks
    }
    public function prepare($query, ...$args)
    {
        // Simple mock - replace %s with args
        if (count($args) > 0) {
            foreach ($args as $arg) {
                $query = preg_replace('/%s/', $arg, $query, 1);
            }
        }
        return $query;
    }
    public function get_results($query)
    {
        return []; // Return empty array for queries
    }
    public function esc_like($text)
    {
        return $text; // Simple mock - just return the text
    }
};
if (!function_exists('__')) {
    function __($text, $domain = 'default')
    {
        return $text;
    }
}

if (!function_exists('esc_html__')) {
    function esc_html__($text, $domain = 'default')
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('esc_html')) {
    function esc_html($text)
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('esc_attr')) {
    function esc_attr($text)
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('checked')) {
    function checked($checked, $current = true, $echo = true)
    {
        $result = '';
        if ($checked === $current) {
            $result = ' checked="checked"';
        }
        if ($echo) {
            echo $result;
        }
        return $result;
    }
}

if (!function_exists('get_option')) {
    function get_option($option, $default = false)
    {
        global $wp_options;
        if (!isset($wp_options)) {
            $wp_options = [];
        }
        return isset($wp_options[$option]) ? $wp_options[$option] : $default;
    }
}

if (!function_exists('update_option')) {
    function update_option($option, $value)
    {
        global $wp_options;
        if (!isset($wp_options)) {
            $wp_options = [];
        }
        $wp_options[$option] = $value;
        return true;
    }
}

if (!function_exists('delete_option')) {
    function delete_option($option)
    {
        global $wp_options;
        if (!isset($wp_options)) {
            $wp_options = [];
        }
        unset($wp_options[$option]);
        return true;
    }
}

if (!function_exists('current_user_can')) {
    function current_user_can($capability)
    {
        return true;
    }
}

if (!function_exists('wp_parse_args')) {
    function wp_parse_args($args, $defaults = [])
    {
        if (is_object($args)) {
            $r = get_object_vars($args);
        } elseif (is_array($args)) {
            $r = &$args;
        } else {
            return $defaults;
        }

        if (is_array($defaults) && $defaults) {
            return array_merge($defaults, $r);
        }
        return $r;
    }
}

if (!function_exists('add_action')) {
    function add_action($tag, $function_to_add, $priority = 10, $accepted_args = 1)
    {
        global $wp_actions;
        if (!isset($wp_actions)) {
            $wp_actions = [];
        }
        if (!isset($wp_actions[$tag])) {
            $wp_actions[$tag] = [];
        }
        $wp_actions[$tag][] = [
            'function'      => $function_to_add,
            'priority'      => $priority,
            'accepted_args' => $accepted_args,
        ];
        return true;
    }
}

if (!function_exists('register_rest_route')) {
    function register_rest_route($namespace, $route, $args = [])
    {
        global $wp_rest_routes;
        if (!isset($wp_rest_routes)) {
            $wp_rest_routes = [];
        }
        $key                    = $namespace . $route;
        $wp_rest_routes[$key] = $args;
        return true;
    }
}

// Load test base class and all plugin classes
require_once __DIR__ . '/includes/class-wc-payment-monitor-test-case.php';
require_once dirname(__DIR__) . '/includes/class-wc-payment-monitor-database.php';
require_once dirname(__DIR__) . '/includes/class-wc-payment-monitor-logger.php';
require_once dirname(__DIR__) . '/includes/class-wc-payment-monitor-health.php';
require_once dirname(__DIR__) . '/includes/class-wc-payment-monitor-alerts.php';
require_once dirname(__DIR__) . '/includes/class-wc-payment-monitor-retry.php';
require_once dirname(__DIR__) . '/includes/class-wc-payment-monitor-security.php';
require_once dirname(__DIR__) . '/includes/class-wc-payment-monitor-api-base.php';
require_once dirname(__DIR__) . '/includes/class-wc-payment-monitor-api-health.php';
require_once dirname(__DIR__) . '/includes/class-wc-payment-monitor-api-transactions.php';
require_once dirname(__DIR__) . '/includes/class-wc-payment-monitor-api-diagnostics.php';
require_once dirname(__DIR__) . '/includes/class-wc-payment-monitor-gateway-connectivity.php';
require_once dirname(__DIR__) . '/includes/class-wc-payment-monitor-failure-simulator.php';
require_once dirname(__DIR__) . '/includes/class-wc-payment-monitor-diagnostics.php';
require_once dirname(__DIR__) . '/includes/class-wc-payment-monitor-admin.php';

// Minimal WooCommerce stubs for tests when WooCommerce isn't loaded
if (!function_exists('WC')) {
    class WC_Payment_Gateways_Stub
    {
        public function get_available_payment_gateways()
        {
            // Return empty array to simulate no gateways when WooCommerce isn't available
            return [];
        }
    }

    class WC_Main_Stub
    {
        public function payment_gateways()
        {
            return new WC_Payment_Gateways_Stub();
        }
    }

    function WC()
    {
        return new WC_Main_Stub();
    }
}

if (!function_exists('wc_get_order')) {
    function wc_get_order($order_id)
    {
        // No WooCommerce in unit tests; return null to skip order augmentation
        return null;
    }
}

if (!function_exists('wp_timezone_string')) {
    function wp_timezone_string()
    {
        return 'UTC';
    }
}

if (!function_exists('current_time')) {
    function current_time($type, $gmt = 0)
    {
        return date('Y-m-d H:i:s');
    }
}

if (!function_exists('get_bloginfo')) {
    function get_bloginfo($show = '', $filter = 'raw')
    {
        $info = [
            'version' => '6.0.0',
            'charset' => 'UTF-8',
        ];
        return isset($info[$show]) ? $info[$show] : '';
    }
}

if (!function_exists('get_locale')) {
    function get_locale()
    {
        return 'en_US';
    }
}

if (!function_exists('wp_next_scheduled')) {
    function wp_next_scheduled($hook)
    {
        return false; // No scheduled events in tests
    }
}

// Mock WordPress REST API classes for testing
if (!class_exists('WP_REST_Request')) {
    class WP_REST_Request
    {
        public function get_param($key)
        {
            return null;
        }
    }
}
