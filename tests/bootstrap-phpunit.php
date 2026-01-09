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

// Define plugin constants
define('WC_PAYMENT_MONITOR_PLUGIN_FILE', dirname(__DIR__) . '/wc-payment-monitor.php');
define('WC_PAYMENT_MONITOR_PLUGIN_DIR', dirname(__DIR__) . '/');
define('WC_PAYMENT_MONITOR_PLUGIN_URL', 'http://example.org/wp-content/plugins/wc-payment-monitor/');
define('WC_PAYMENT_MONITOR_PLUGIN_BASENAME', 'wc-payment-monitor/wc-payment-monitor.php');
define('WC_PAYMENT_MONITOR_VERSION', '1.0.0');

// Mock WordPress functions if not available
if (!function_exists('__')) {
    function __($text, $domain = 'default') {
        return $text;
    }
}

if (!function_exists('esc_html__')) {
    function esc_html__($text, $domain = 'default') {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('esc_html')) {
    function esc_html($text) {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('esc_attr')) {
    function esc_attr($text) {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('checked')) {
    function checked($checked, $current = true, $echo = true) {
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
    function get_option($option, $default = false) {
        global $wp_options;
        if (!isset($wp_options)) {
            $wp_options = array();
        }
        return isset($wp_options[$option]) ? $wp_options[$option] : $default;
    }
}

if (!function_exists('update_option')) {
    function update_option($option, $value) {
        global $wp_options;
        if (!isset($wp_options)) {
            $wp_options = array();
        }
        $wp_options[$option] = $value;
        return true;
    }
}

if (!function_exists('delete_option')) {
    function delete_option($option) {
        global $wp_options;
        if (!isset($wp_options)) {
            $wp_options = array();
        }
        unset($wp_options[$option]);
        return true;
    }
}

if (!function_exists('current_user_can')) {
    function current_user_can($capability) {
        return true;
    }
}

if (!function_exists('wp_parse_args')) {
    function wp_parse_args($args, $defaults = array()) {
        if (is_object($args)) {
            $r = get_object_vars($args);
        } elseif (is_array($args)) {
            $r =& $args;
        } else {
            return $defaults;
        }
        
        if (is_array($defaults) && $defaults) {
            return array_merge($defaults, $r);
        }
        return $r;
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
require_once dirname(__DIR__) . '/includes/class-wc-payment-monitor-admin.php';
