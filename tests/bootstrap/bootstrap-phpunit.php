<?php
/**
 * PHPUnit Bootstrap - Define WordPress constants before tests run.
 *
 * Prevents syntax errors when PHPUnit analyzes plugin files.
 *
 * @package PaySentinel
 */

// Composer autoloader.
if ( file_exists( dirname( __DIR__ ) . '/vendor/autoload.php' ) ) {
	require_once dirname( __DIR__ ) . '/vendor/autoload.php';
}

// Define ABSPATH to prevent "exit" statements in plugin classes.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

// Prevent PHP notices when plugin files are analyzed.
// phpcs:ignore WordPress.PHP.DevelopmentFunctions.prevent_path_disclosure_error_reporting, WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_error_reporting
error_reporting( E_ALL & ~E_NOTICE & ~E_WARNING );

// Define WordPress constants.
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

// Define plugin constants.
define( 'PAYSENTINEL_PLUGIN_FILE', dirname( __DIR__ ) . '/paysentinel.php' );
define( 'PAYSENTINEL_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
define( 'PAYSENTINEL_PLUGIN_URL', 'http://example.org/wp-content/plugins/paysentinel/' );
define( 'PAYSENTINEL_PLUGIN_BASENAME', 'paysentinel/paysentinel.php' );
define( 'PAYSENTINEL_VERSION', '1.0.0' );

// Mock global $wpdb.
// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
global $wpdb;
// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
$wpdb = new class() {
	/**
	 * Mock get_var for tests.
	 *
	 * @param string $query SQL query.
	 * @return mixed
	 */
	public function get_var( $query ) {
		// Return a default value for database size queries.
		if ( strpos( $query, 'information_schema' ) !== false ) {
			return '1024.00';
		}
		return 1; // Default for table existence checks.
	}
	/**
	 * Mock prepare for tests.
	 *
	 * @param string $query SQL query.
	 * @param mixed  ...$args Arguments.
	 * @return string
	 */
	public function prepare( $query, ...$args ) {
		// Simple mock - replace %s with args.
		if ( count( $args ) > 0 ) {
			foreach ( $args as $arg ) {
				$query = preg_replace( '/%s/', $arg, $query, 1 );
			}
		}
		return $query;
	}
	/**
	 * Mock get_results for tests.
	 *
	 * @param string $_query SQL query (unused).
	 * @return array
	 */
	public function get_results( $_query ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter, VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		return array(); // Return empty array for queries.
	}
	/**
	 * Mock esc_like for tests.
	 *
	 * @param string $text Text to escape.
	 * @return string
	 */
	public function esc_like( $text ) {
		return $text; // Simple mock - just return the text.
	}
};
if ( ! function_exists( '__' ) ) {
	/**
	 * Mock translation function.
	 *
	 * @param string $text   Text to translate.
	 * @param string $_domain Text domain (unused).
	 * @return string
	 */
	function __( $text, $_domain = 'default' ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter, VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		return $text;
	}
}

if ( ! function_exists( 'esc_html__' ) ) {
	/**
	 * Mock esc_html__ for tests.
	 *
	 * @param string $text    Text to escape.
	 * @param string $_domain Text domain (unused).
	 * @return string
	 */
	function esc_html__( $text, $_domain = 'default' ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter, VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	/**
	 * Mock esc_html for tests.
	 *
	 * @param string $text Text to escape.
	 * @return string
	 */
	function esc_html( $text ) {
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_attr' ) ) {
	/**
	 * Mock esc_attr for tests.
	 *
	 * @param string $text Text to escape.
	 * @return string
	 */
	function esc_attr( $text ) {
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'checked' ) ) {
	/**
	 * Mock checked for tests.
	 *
	 * @param mixed $checked Checked value.
	 * @param mixed $current Current value.
	 * @param bool  $_echo   Whether to echo (unused).
	 * @return string
	 */
	function checked( $checked, $current = true, $_echo = true ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter, Universal.NamingConventions.NoReservedKeywordParameterNames.echoFound, VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		$result = '';
		if ( $checked === $current ) {
			$result = ' checked="checked"';
		}
		if ( $_echo ) {
			echo esc_attr( $result ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		return $result;
	}
}

if ( ! function_exists( 'get_option' ) ) {
	/**
	 * Mock get_option for tests.
	 *
	 * @param string $option  Option name.
	 * @param mixed  $default Default value.
	 * @return mixed
	 */
	function get_option( $option, $default = false ) { // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.defaultFound
		global $wp_options;
		if ( ! isset( $wp_options ) ) {
			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			$wp_options = array();
		}
		return isset( $wp_options[ $option ] ) ? $wp_options[ $option ] : $default;
	}
}

if ( ! function_exists( 'update_option' ) ) {
	/**
	 * Mock update_option for tests.
	 *
	 * @param string $option Option name.
	 * @param mixed  $value  Option value.
	 * @return true
	 */
	function update_option( $option, $value ) {
		global $wp_options;
		if ( ! isset( $wp_options ) ) {
			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			$wp_options = array();
		}
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$wp_options[ $option ] = $value;
		return true;
	}
}

if ( ! function_exists( 'delete_option' ) ) {
	/**
	 * Mock delete_option for tests.
	 *
	 * @param string $option Option name.
	 * @return true
	 */
	function delete_option( $option ) {
		global $wp_options;
		if ( ! isset( $wp_options ) ) {
			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			$wp_options = array();
		}
		unset( $wp_options[ $option ] );
		return true;
	}
}

if ( ! function_exists( 'current_user_can' ) ) {
	/**
	 * Mock current_user_can for tests.
	 *
	 * @param string $_capability Capability to check (unused).
	 * @return true
	 */
	function current_user_can( $_capability ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter, VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		return true;
	}
}

if ( ! function_exists( 'wp_parse_args' ) ) {
	/**
	 * Mock wp_parse_args for tests.
	 *
	 * @param mixed $args     Arguments.
	 * @param array $defaults Default values.
	 * @return array
	 */
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
	/**
	 * Mock add_action for tests.
	 *
	 * @param string   $tag             Action hook.
	 * @param callable $function_to_add Callback.
	 * @param int      $priority        Priority.
	 * @param int      $accepted_args   Accepted args.
	 * @return true
	 */
	function add_action( $tag, $function_to_add, $priority = 10, $accepted_args = 1 ) {
		global $wp_actions;
		if ( ! isset( $wp_actions ) ) {
			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			$wp_actions = array();
		}
		if ( ! isset( $wp_actions[ $tag ] ) ) {
			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			$wp_actions[ $tag ] = array();
		}
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$wp_actions[ $tag ][] = array(
			'function'      => $function_to_add,
			'priority'      => $priority,
			'accepted_args' => $accepted_args,
		);
		return true;
	}
}

if ( ! function_exists( 'register_rest_route' ) ) {
	/**
	 * Mock register_rest_route for tests.
	 *
	 * @param string $_namespace Route namespace (unused as reserved keyword).
	 * @param string $route      Route path.
	 * @param array  $args       Route arguments.
	 * @return true
	 */
	function register_rest_route( $_namespace, $route, $args = array() ) { // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.namespaceFound, Generic.CodeAnalysis.UnusedFunctionParameter, VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		global $wp_rest_routes;
		if ( ! isset( $wp_rest_routes ) ) {
			$wp_rest_routes = array();
		}
		$key                    = $_namespace . $route; // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		$wp_rest_routes[ $key ] = $args;
		return true;
	}
}

// Load test base class and all plugin classes.
require_once __DIR__ . '/includes/class-paysentinel-test-case.php';
require_once dirname( __DIR__ ) . '/includes/core/class-paysentinel-security.php';
require_once dirname( __DIR__ ) . '/includes/core/class-paysentinel-license.php';
require_once dirname( __DIR__ ) . '/includes/core/class-paysentinel-config.php';
require_once dirname( __DIR__ ) . '/includes/core/class-paysentinel-database.php';
require_once dirname( __DIR__ ) . '/includes/core/class-paysentinel-logger.php';
require_once dirname( __DIR__ ) . '/includes/core/class-paysentinel-health.php';
require_once dirname( __DIR__ ) . '/includes/alerts/class-paysentinel-alerts.php';
require_once dirname( __DIR__ ) . '/includes/core/class-paysentinel-retry.php';
require_once dirname( __DIR__ ) . '/includes/api/class-paysentinel-api-base.php';
require_once dirname( __DIR__ ) . '/includes/api/class-paysentinel-api-health.php';
require_once dirname( __DIR__ ) . '/includes/api/class-paysentinel-api-transactions.php';
require_once dirname( __DIR__ ) . '/includes/api/class-paysentinel-api-diagnostics.php';
require_once dirname( __DIR__ ) . '/includes/utils/class-paysentinel-gateway-connectivity.php';
require_once dirname( __DIR__ ) . '/includes/utils/class-paysentinel-failure-simulator.php';
require_once dirname( __DIR__ ) . '/includes/core/class-paysentinel-diagnostics.php';
require_once dirname( __DIR__ ) . '/includes/admin/class-paysentinel-admin.php';
require_once dirname( __DIR__ ) . '/includes/admin/class-paysentinel-admin-ajax-handler.php';
require_once dirname( __DIR__ ) . '/includes/admin/class-paysentinel-admin-settings-handler.php';

// phpcs:disable Universal.Files.SeparateFunctionsFromOO.Mixed, Generic.Files.OneObjectStructurePerFile.MultipleFound
// Minimal WooCommerce stubs for tests when WooCommerce isn't loaded.
if ( ! function_exists( 'WC' ) ) {
	/**
	 * Stub for WC_Payment_Gateways.
	 */
	class WC_Payment_Gateways_Stub {

		/**
		 * Mock get_available_payment_gateways.
		 *
		 * @return array
		 */
		public function get_available_payment_gateways() {
			// Return empty array to simulate no gateways when WooCommerce isn't available.
			return array();
		}
	}

	/**
	 * Stub for WC main class.
	 */
	class WC_Main_Stub {

		/**
		 * Mock payment_gateways.
		 *
		 * @return WC_Payment_Gateways_Stub
		 */
		public function payment_gateways() {
			return new WC_Payment_Gateways_Stub();
		}
	}

	/**
	 * Mock WC() function.
	 *
	 * @return WC_Main_Stub
	 */
	function WC() { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid
		return new WC_Main_Stub();
	}
}

if ( ! function_exists( 'wc_get_order' ) ) {
	/**
	 * Mock wc_get_order for tests.
	 *
	 * @param int $_order_id Order ID (unused).
	 * @return null
	 */
	function wc_get_order( $_order_id ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter, VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		// No WooCommerce in unit tests; return null to skip order augmentation.
		return null;
	}
}

if ( ! function_exists( 'wp_timezone_string' ) ) {
	/**
	 * Mock wp_timezone_string for tests.
	 *
	 * @return string
	 */
	function wp_timezone_string() {
		return 'UTC';
	}
}

if ( ! function_exists( 'current_time' ) ) {
	/**
	 * Mock current_time for tests.
	 *
	 * @param string $_type Time type (unused).
	 * @param int    $_gmt  GMT offset (unused).
	 * @return string
	 */
	function current_time( $_type, $_gmt = 0 ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter, VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		return gmdate( 'Y-m-d H:i:s' );
	}
}

if ( ! function_exists( 'get_bloginfo' ) ) {
	/**
	 * Mock get_bloginfo for tests.
	 *
	 * @param string $show    Info to retrieve.
	 * @param string $_filter Filter (unused).
	 * @return string
	 */
	function get_bloginfo( $show = '', $_filter = 'raw' ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter, VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		$info = array(
			'version' => '6.0.0',
			'charset' => 'UTF-8',
		);
		return isset( $info[ $show ] ) ? $info[ $show ] : '';
	}
}

if ( ! function_exists( 'get_locale' ) ) {
	/**
	 * Mock get_locale for tests.
	 *
	 * @return string
	 */
	function get_locale() {
		return 'en_US';
	}
}

if ( ! function_exists( 'wp_next_scheduled' ) ) {
	/**
	 * Mock wp_next_scheduled for tests.
	 *
	 * @param string $_hook Hook name (unused).
	 * @return false
	 */
	function wp_next_scheduled( $_hook ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter, VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		return false; // No scheduled events in tests.
	}
}

if ( ! class_exists( 'WP_REST_Request' ) ) {
	/**
	 * Stub WP_REST_Request for tests.
	 */
	class WP_REST_Request {

		/**
		 * Mock get_param.
		 *
		 * @param string $_key Parameter name (unused).
		 * @return null
		 */
		public function get_param( $_key ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter, VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
			return null;
		}
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	/**
	 * Mock wp_json_encode for tests.
	 *
	 * @param mixed $data    Data to encode.
	 * @param int   $options JSON options.
	 * @param int   $depth   Recursion depth.
	 * @return string
	 */
	function wp_json_encode( $data, $options = 0, $depth = 512 ) {
		return json_encode( $data, $options, $depth ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
	}
}

if ( ! function_exists( 'wp_send_json_success' ) ) {
	/**
	 * Mock wp_send_json_success for tests.
	 *
	 * @param mixed $_data        Data (unused directly, encoded).
	 * @param int   $_status_code HTTP status code (unused).
	 * @throws Exception Always throws to simulate die.
	 */
	function wp_send_json_success( $_data = null, $_status_code = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter, VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		echo wp_json_encode( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			array(
				'success' => true,
				'data'    => $_data,
			)
		);
		throw new Exception( 'wp_send_json_success' );
	}
}

if ( ! function_exists( 'wp_send_json_error' ) ) {
	/**
	 * Mock wp_send_json_error for tests.
	 *
	 * @param mixed $_data        Data (unused directly, encoded).
	 * @param int   $_status_code HTTP status code (unused).
	 * @throws Exception Always throws to simulate die.
	 */
	function wp_send_json_error( $_data = null, $_status_code = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter, VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		echo wp_json_encode( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			array(
				'success' => false,
				'data'    => $_data,
			)
		);
		throw new Exception( 'wp_send_json_error' );
	}
}

if ( ! function_exists( 'admin_url' ) ) {
	/**
	 * Mock admin_url for tests.
	 *
	 * @param string $path    URL path.
	 * @param string $_scheme URL scheme (unused).
	 * @return string
	 */
	function admin_url( $path = '', $_scheme = 'admin' ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter, VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		return 'http://example.org/wp-admin/' . ltrim( $path, '/' );
	}
}

if ( ! function_exists( 'wp_create_nonce' ) ) {
	/**
	 * Mock wp_create_nonce for tests.
	 *
	 * @param mixed $action Nonce action.
	 * @return string
	 */
	function wp_create_nonce( $action = -1 ) {
		return substr( md5( $action ), 0, 10 );
	}
}

if ( ! function_exists( 'check_ajax_referer' ) ) {
	/**
	 * Mock check_ajax_referer for tests.
	 *
	 * @param mixed  $_action    Nonce action (unused).
	 * @param string $_query_arg Query arg (unused).
	 * @param bool   $_die       Whether to die on failure (unused).
	 * @return true
	 */
	function check_ajax_referer( $_action = -1, $_query_arg = '_wpnonce', $_die = true ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter, Universal.NamingConventions.NoReservedKeywordParameterNames.dieFound, VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		// In tests, always pass nonce check.
		return true;
	}
}

if ( ! function_exists( 'wp_remote_post' ) ) {
	/**
	 * Mock wp_remote_post for tests.
	 *
	 * @param string $url  URL to request.
	 * @param array  $args Request arguments.
	 * @return array
	 */
	function wp_remote_post( $url, $args = array() ) {
		return wp_remote_request( $url, array_merge( $args, array( 'method' => 'POST' ) ) );
	}
}

if ( ! function_exists( 'wp_remote_request' ) ) {
	/**
	 * Mock wp_remote_request for tests.
	 *
	 * @param string $url  URL to request.
	 * @param array  $args Request arguments.
	 * @return array
	 */
	function wp_remote_request( $url, $args = array() ) {
		// Apply filters to allow test mocking.
		$pre = apply_filters( 'pre_http_request', false, $args, $url );
		if ( false !== $pre ) {
			return $pre;
		}
		// Default mock response.
		return array(
			'response' => array( 'code' => 200 ),
			'body'     => wp_json_encode( array( 'success' => true ) ),
		);
	}
}

if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
	/**
	 * Mock wp_remote_retrieve_response_code for tests.
	 *
	 * @param mixed $response HTTP response.
	 * @return int
	 */
	function wp_remote_retrieve_response_code( $response ) {
		if ( is_wp_error( $response ) ) {
			return 0;
		}
		return isset( $response['response']['code'] ) ? $response['response']['code'] : 0;
	}
}

if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
	/**
	 * Mock wp_remote_retrieve_body for tests.
	 *
	 * @param mixed $response HTTP response.
	 * @return string
	 */
	function wp_remote_retrieve_body( $response ) {
		if ( is_wp_error( $response ) ) {
			return '';
		}
		return isset( $response['body'] ) ? $response['body'] : '';
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	/**
	 * Mock is_wp_error for tests.
	 *
	 * @param mixed $thing Object to check.
	 * @return bool
	 */
	function is_wp_error( $thing ) {
		return ( $thing instanceof WP_Error );
	}
}

if ( ! function_exists( 'get_site_url' ) ) {
	/**
	 * Mock get_site_url for tests.
	 *
	 * @param int|null $_blog_id Blog ID (unused).
	 * @param string   $path     URL path.
	 * @param string   $_scheme  URL scheme (unused).
	 * @return string
	 */
	function get_site_url( $_blog_id = null, $path = '', $_scheme = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter, VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		return 'http://example.org' . $path;
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	/**
	 * Mock apply_filters for tests.
	 *
	 * @param string $tag   Filter tag.
	 * @param mixed  $value Value to filter.
	 * @param mixed  ...$args Additional arguments.
	 * @return mixed
	 */
	function apply_filters( $tag, $value, ...$args ) {
		global $wp_filter;
		if ( ! isset( $wp_filter ) ) {
			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			$wp_filter = array();
		}
		if ( isset( $wp_filter[ $tag ] ) ) {
			foreach ( $wp_filter[ $tag ] as $_priority => $callbacks ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
				foreach ( $callbacks as $callback ) {
					$value = call_user_func_array( $callback['function'], array_merge( array( $value ), $args ) );
				}
			}
		}
		return $value;
	}
}

if ( ! function_exists( 'add_filter' ) ) {
	/**
	 * Mock add_filter for tests.
	 *
	 * @param string   $tag             Filter tag.
	 * @param callable $function_to_add Callback.
	 * @param int      $priority        Priority.
	 * @param int      $accepted_args   Accepted args.
	 * @return true
	 */
	function add_filter( $tag, $function_to_add, $priority = 10, $accepted_args = 1 ) {
		global $wp_filter;
		if ( ! isset( $wp_filter ) ) {
			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			$wp_filter = array();
		}
		if ( ! isset( $wp_filter[ $tag ] ) ) {
			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			$wp_filter[ $tag ] = array();
		}
		if ( ! isset( $wp_filter[ $tag ][ $priority ] ) ) {
			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			$wp_filter[ $tag ][ $priority ] = array();
		}
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$wp_filter[ $tag ][ $priority ][] = array(
			'function'      => $function_to_add,
			'accepted_args' => $accepted_args,
		);
		return true;
	}
}

if ( ! class_exists( 'WP_Error' ) ) {
	// phpcs:disable Generic.Classes.DuplicateClassName.Found
	/**
	 * Stub WP_Error for tests.
	 */
	class WP_Error {
	// phpcs:enable Generic.Classes.DuplicateClassName.Found
		/**
		 * Error code.
		 *
		 * @var string
		 */
		private $code;
		/**
		 * Error message.
		 *
		 * @var string
		 */
		private $message;
		/**
		 * Error data.
		 *
		 * @var mixed
		 */
		private $data;

		/**
		 * Constructor.
		 *
		 * @param string $code    Error code.
		 * @param string $message Error message.
		 * @param mixed  $data    Error data.
		 */
		public function __construct( $code = '', $message = '', $data = '' ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = $data;
		}

		/**
		 * Get error code.
		 *
		 * @return string
		 */
		public function get_error_code() {
			return $this->code;
		}

		/**
		 * Get error message.
		 *
		 * @return string
		 */
		public function get_error_message() {
			return $this->message;
		}

		/**
		 * Get error data.
		 *
		 * @return mixed
		 */
		public function get_error_data() {
			return $this->data;
		}
	}
}
