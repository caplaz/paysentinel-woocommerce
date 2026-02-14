<?php

/**
 * PHPUnit Bootstrap - Define WordPress constants before tests run
 * Prevents syntax errors when PHPUnit analyzes plugin files
 */

// Composer autoloader
if ( file_exists( dirname( __DIR__ ) . '/vendor/autoload.php' ) ) {
	require_once dirname( __DIR__ ) . '/vendor/autoload.php';
}

// Define ABSPATH to prevent "exit" statements in plugin classes
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

// Prevent PHP notices when plugin files are analyzed
error_reporting( E_ALL & ~E_NOTICE & ~E_WARNING );

// Define WordPress constants
if ( ! defined( 'WP_MEMORY_LIMIT' ) ) {
	define( 'WP_MEMORY_LIMIT', '256M' );
}

if ( ! defined( 'WP_MAX_MEMORY_LIMIT' ) ) {
	define( 'WP_MAX_MEMORY_LIMIT', '512M' );
}

if ( ! defined( 'WP_DEBUG' ) ) {
	define( 'WP_DEBUG', false );
}

if ( ! defined( 'DB_NAME' ) ) {
	define( 'DB_NAME', 'test_db' );
}

// Define plugin constants
define( 'WC_PAYMENT_MONITOR_PLUGIN_FILE', dirname( __DIR__ ) . '/wc-payment-monitor.php' );
define( 'WC_PAYMENT_MONITOR_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
define( 'WC_PAYMENT_MONITOR_PLUGIN_URL', 'http://example.org/wp-content/plugins/wc-payment-monitor/' );
define( 'WC_PAYMENT_MONITOR_PLUGIN_BASENAME', 'wc-payment-monitor/wc-payment-monitor.php' );
define( 'WC_PAYMENT_MONITOR_VERSION', '1.0.0' );

// Mock global $wpdb
global $wpdb;
$wpdb = new class() {
	public function get_var( $query ) {
		// Return a default value for database size queries
		if ( strpos( $query, 'information_schema' ) !== false ) {
			return '1024.00';
		}
		return 1; // Default for table existence checks
	}
	public function prepare( $query, ...$args ) {
		// Simple mock - replace %s with args
		if ( count( $args ) > 0 ) {
			foreach ( $args as $arg ) {
				$query = preg_replace( '/%s/', $arg, $query, 1 );
			}
		}
		return $query;
	}
	public function get_results( $query ) {
		return array(); // Return empty array for queries
	}
	public function esc_like( $text ) {
		return $text; // Simple mock - just return the text
	}
};
if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = 'default' ) {
		return $text;
	}
}

if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( $text, $domain = 'default' ) {
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $text ) {
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( $text ) {
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'checked' ) ) {
	function checked( $checked, $current = true, $echo = true ) {
		$result = '';
		if ( $checked === $current ) {
			$result = ' checked="checked"';
		}
		if ( $echo ) {
			echo $result;
		}
		return $result;
	}
}

if ( ! function_exists( 'get_option' ) ) {
	function get_option( $option, $default = false ) {
		global $wp_options;
		if ( ! isset( $wp_options ) ) {
			$wp_options = array();
		}
		return isset( $wp_options[ $option ] ) ? $wp_options[ $option ] : $default;
	}
}

if ( ! function_exists( 'update_option' ) ) {
	function update_option( $option, $value ) {
		global $wp_options;
		if ( ! isset( $wp_options ) ) {
			$wp_options = array();
		}
		$wp_options[ $option ] = $value;
		return true;
	}
}

if ( ! function_exists( 'delete_option' ) ) {
	function delete_option( $option ) {
		global $wp_options;
		if ( ! isset( $wp_options ) ) {
			$wp_options = array();
		}
		unset( $wp_options[ $option ] );
		return true;
	}
}

if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( $capability ) {
		return true;
	}
}

if ( ! function_exists( 'wp_parse_args' ) ) {
	function wp_parse_args( $args, $defaults = array() ) {
		if ( is_object( $args ) ) {
			$r = get_object_vars( $args );
		} elseif ( is_array( $args ) ) {
			$r = &$args;
		} else {
			return $defaults;
		}

		if ( is_array( $defaults ) && $defaults ) {
			return array_merge( $defaults, $r );
		}
		return $r;
	}
}

if ( ! function_exists( 'add_action' ) ) {
	function add_action( $tag, $function_to_add, $priority = 10, $accepted_args = 1 ) {
		global $wp_actions;
		if ( ! isset( $wp_actions ) ) {
			$wp_actions = array();
		}
		if ( ! isset( $wp_actions[ $tag ] ) ) {
			$wp_actions[ $tag ] = array();
		}
		$wp_actions[ $tag ][] = array(
			'function'      => $function_to_add,
			'priority'      => $priority,
			'accepted_args' => $accepted_args,
		);
		return true;
	}
}

if ( ! function_exists( 'register_rest_route' ) ) {
	function register_rest_route( $namespace, $route, $args = array() ) {
		global $wp_rest_routes;
		if ( ! isset( $wp_rest_routes ) ) {
			$wp_rest_routes = array();
		}
		$key                    = $namespace . $route;
		$wp_rest_routes[ $key ] = $args;
		return true;
	}
}

// Load test base class and all plugin classes
require_once __DIR__ . '/includes/class-wc-payment-monitor-test-case.php';
require_once dirname( __DIR__ ) . '/includes/core/class-wc-payment-monitor-security.php';
require_once dirname( __DIR__ ) . '/includes/core/class-wc-payment-monitor-license.php';
require_once dirname( __DIR__ ) . '/includes/core/class-wc-payment-monitor-config.php';
require_once dirname( __DIR__ ) . '/includes/core/class-wc-payment-monitor-database.php';
require_once dirname( __DIR__ ) . '/includes/core/class-wc-payment-monitor-logger.php';
require_once dirname( __DIR__ ) . '/includes/core/class-wc-payment-monitor-health.php';
require_once dirname( __DIR__ ) . '/includes/alerts/class-wc-payment-monitor-alerts.php';
require_once dirname( __DIR__ ) . '/includes/core/class-wc-payment-monitor-retry.php';
require_once dirname( __DIR__ ) . '/includes/api/class-wc-payment-monitor-api-base.php';
require_once dirname( __DIR__ ) . '/includes/api/class-wc-payment-monitor-api-health.php';
require_once dirname( __DIR__ ) . '/includes/api/class-wc-payment-monitor-api-transactions.php';
require_once dirname( __DIR__ ) . '/includes/api/class-wc-payment-monitor-api-diagnostics.php';
require_once dirname( __DIR__ ) . '/includes/utils/class-wc-payment-monitor-gateway-connectivity.php';
require_once dirname( __DIR__ ) . '/includes/utils/class-wc-payment-monitor-failure-simulator.php';
require_once dirname( __DIR__ ) . '/includes/core/class-wc-payment-monitor-diagnostics.php';
require_once dirname( __DIR__ ) . '/includes/admin/class-wc-payment-monitor-admin.php';
require_once dirname( __DIR__ ) . '/includes/admin/class-wc-payment-monitor-admin-ajax-handler.php';
require_once dirname( __DIR__ ) . '/includes/admin/class-wc-payment-monitor-admin-settings-handler.php';

// Minimal WooCommerce stubs for tests when WooCommerce isn't loaded
if ( ! function_exists( 'WC' ) ) {
	class WC_Payment_Gateways_Stub {

		public function get_available_payment_gateways() {
			// Return empty array to simulate no gateways when WooCommerce isn't available
			return array();
		}
	}

	class WC_Main_Stub {

		public function payment_gateways() {
			return new WC_Payment_Gateways_Stub();
		}
	}

	function WC() {
		return new WC_Main_Stub();
	}
}

if ( ! function_exists( 'wc_get_order' ) ) {
	function wc_get_order( $order_id ) {
		// No WooCommerce in unit tests; return null to skip order augmentation
		return null;
	}
}

if ( ! function_exists( 'wp_timezone_string' ) ) {
	function wp_timezone_string() {
		return 'UTC';
	}
}

if ( ! function_exists( 'current_time' ) ) {
	function current_time( $type, $gmt = 0 ) {
		return date( 'Y-m-d H:i:s' );
	}
}

if ( ! function_exists( 'get_bloginfo' ) ) {
	function get_bloginfo( $show = '', $filter = 'raw' ) {
		$info = array(
			'version' => '6.0.0',
			'charset' => 'UTF-8',
		);
		return isset( $info[ $show ] ) ? $info[ $show ] : '';
	}
}

if ( ! function_exists( 'get_locale' ) ) {
	function get_locale() {
		return 'en_US';
	}
}

if ( ! function_exists( 'wp_next_scheduled' ) ) {
	function wp_next_scheduled( $hook ) {
		return false; // No scheduled events in tests
	}
}

if ( ! class_exists( 'WP_REST_Request' ) ) {
	class WP_REST_Request {

		public function get_param( $key ) {
			return null;
		}
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data, $options = 0, $depth = 512 ) {
		return json_encode( $data, $options, $depth );
	}
}

if ( ! function_exists( 'wp_send_json_success' ) ) {
	function wp_send_json_success( $data = null, $status_code = null ) {
		echo json_encode(
			array(
				'success' => true,
				'data'    => $data,
			)
		);
		throw new Exception( 'wp_send_json_success' );
	}
}

if ( ! function_exists( 'wp_send_json_error' ) ) {
	function wp_send_json_error( $data = null, $status_code = null ) {
		echo json_encode(
			array(
				'success' => false,
				'data'    => $data,
			)
		);
		throw new Exception( 'wp_send_json_error' );
	}
}

if ( ! function_exists( 'admin_url' ) ) {
	function admin_url( $path = '', $scheme = 'admin' ) {
		return 'http://example.org/wp-admin/' . ltrim( $path, '/' );
	}
}

if ( ! function_exists( 'wp_create_nonce' ) ) {
	function wp_create_nonce( $action = -1 ) {
		return substr( md5( $action ), 0, 10 );
	}
}

if ( ! function_exists( 'check_ajax_referer' ) ) {
	function check_ajax_referer( $action = -1, $query_arg = '_wpnonce', $die = true ) {
		// In tests, always pass nonce check
		return true;
	}
}

if ( ! function_exists( 'wp_remote_post' ) ) {
	function wp_remote_post( $url, $args = array() ) {
		return wp_remote_request( $url, array_merge( $args, array( 'method' => 'POST' ) ) );
	}
}

if ( ! function_exists( 'wp_remote_request' ) ) {
	function wp_remote_request( $url, $args = array() ) {
		// Apply filters to allow test mocking
		$pre = apply_filters( 'pre_http_request', false, $args, $url );
		if ( false !== $pre ) {
			return $pre;
		}
		// Default mock response
		return array(
			'response' => array( 'code' => 200 ),
			'body'     => json_encode( array( 'success' => true ) ),
		);
	}
}

if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
	function wp_remote_retrieve_response_code( $response ) {
		if ( is_wp_error( $response ) ) {
			return 0;
		}
		return isset( $response['response']['code'] ) ? $response['response']['code'] : 0;
	}
}

if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
	function wp_remote_retrieve_body( $response ) {
		if ( is_wp_error( $response ) ) {
			return '';
		}
		return isset( $response['body'] ) ? $response['body'] : '';
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ) {
		return ( $thing instanceof WP_Error );
	}
}

if ( ! function_exists( 'get_site_url' ) ) {
	function get_site_url( $blog_id = null, $path = '', $scheme = null ) {
		return 'http://example.org' . $path;
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $tag, $value, ...$args ) {
		global $wp_filter;
		if ( ! isset( $wp_filter ) ) {
			$wp_filter = array();
		}
		if ( isset( $wp_filter[ $tag ] ) ) {
			foreach ( $wp_filter[ $tag ] as $priority => $callbacks ) {
				foreach ( $callbacks as $callback ) {
					$value = call_user_func_array( $callback['function'], array_merge( array( $value ), $args ) );
				}
			}
		}
		return $value;
	}
}

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( $tag, $function_to_add, $priority = 10, $accepted_args = 1 ) {
		global $wp_filter;
		if ( ! isset( $wp_filter ) ) {
			$wp_filter = array();
		}
		if ( ! isset( $wp_filter[ $tag ] ) ) {
			$wp_filter[ $tag ] = array();
		}
		if ( ! isset( $wp_filter[ $tag ][ $priority ] ) ) {
			$wp_filter[ $tag ][ $priority ] = array();
		}
		$wp_filter[ $tag ][ $priority ][] = array(
			'function'      => $function_to_add,
			'accepted_args' => $accepted_args,
		);
		return true;
	}
}

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private $code;
		private $message;
		private $data;

		public function __construct( $code = '', $message = '', $data = '' ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = $data;
		}

		public function get_error_code() {
			return $this->code;
		}

		public function get_error_message() {
			return $this->message;
		}

		public function get_error_data() {
			return $this->data;
		}
	}
}
