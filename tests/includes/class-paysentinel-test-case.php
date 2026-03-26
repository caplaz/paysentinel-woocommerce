<?php
/**
 * Base test case class for PaySentinel tests.
 *
 * @package PaySentinel\Tests\Includes
 */

use PHPUnit\Framework\TestCase;

/**
 * Class PaySentinel_Test_Case
 */
class PaySentinel_Test_Case extends TestCase {

	/**
	 * Set up test environment.
	 */
	public function set_up() {
		parent::set_up();

		// Reset database state.
		$this->clean_up_database();

		// Initialize WordPress environment.
		if ( ! defined( 'ABSPATH' ) ) {
			define( 'ABSPATH', '/tmp/wordpress/' );
		}

		// Mock WordPress functions if not available.
		$this->mock_wordpress_functions();
	}

	/**
	 * Clean up after tests.
	 */
	public function tear_down() {
		$this->clean_up_database();
		parent::tear_down();
	}

	/**
	 * Clean up database tables.
	 */
	protected function clean_up_database() {
		global $wpdb;

		if ( ! isset( $wpdb ) ) {
			return;
		}

		$tables = array(
			$wpdb->prefix . 'payment_monitor_transactions',
			$wpdb->prefix . 'payment_monitor_gateway_health',
			$wpdb->prefix . 'payment_monitor_alerts',
		);

		foreach ( $tables as $table ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
			$wpdb->query( 'DROP TABLE IF EXISTS ' . $table );
		}

		// Clean up options.
		delete_option( 'payment_monitor_db_version' );
		delete_option( 'paysentinel_version' );
		delete_option( 'paysentinel_settings' );
	}

	/**
	 * Mock WordPress functions for testing.
	 */
	protected function mock_wordpress_functions() {
		if ( ! function_exists( 'get_option' ) ) {
			/**
			 * Retrieve an option value from the mocked storage.
			 *
			 * @param string $option Option name.
			 * @param mixed  $default_value Default value to return when option not set.
			 * @return mixed
			 */
			function get_option( $option, $default_value = false ) {
				static $options = array();
				return isset( $options[ $option ] ) ? $options[ $option ] : $default_value;
			}
		}

		if ( ! function_exists( 'update_option' ) ) {
			/**
			 * Update an option in the mocked storage.
			 *
			 * @param string $option Option name.
			 * @param mixed  $value Value to set.
			 * @return bool
			 */
			function update_option( $option, $value ) {
				static $options     = array();
				$options[ $option ] = $value;
				return true;
			}
		}

		if ( ! function_exists( 'add_option' ) ) {
			/**
			 * Add an option to the mocked storage.
			 *
			 * @param string $option Option name.
			 * @param mixed  $value Value to set.
			 * @return bool
			 */
			function add_option( $option, $value ) {
				return update_option( $option, $value );
			}
		}

		if ( ! function_exists( 'delete_option' ) ) {
			/**
			 * Delete an option from the mocked storage.
			 *
			 * @param string $option Option name.
			 * @return bool
			 */
			function delete_option( $option ) {
				static $options = array();
				unset( $options[ $option ] );
				return true;
			}
		}

		if ( ! function_exists( 'plugin_basename' ) ) {
			/**
			 * Get plugin basename for a file path.
			 *
			 * @param string $file File path.
			 * @return string
			 */
			function plugin_basename( $file ) {
				return basename( dirname( $file ) ) . '/' . basename( $file );
			}
		}

		if ( ! function_exists( 'plugin_dir_path' ) ) {
			/**
			 * Get plugin directory path for a file.
			 *
			 * @param string $file File path.
			 * @return string
			 */
			function plugin_dir_path( $file ) {
				return dirname( $file ) . '/';
			}
		}

		if ( ! function_exists( 'plugin_dir_url' ) ) {
			/**
			 * Get plugin directory URL for a file.
			 *
			 * @param string $file File path.
			 * @return string
			 */
			function plugin_dir_url( $file ) {
				return 'http://example.org/wp-content/plugins/' . basename( dirname( $file ) ) . '/';
			}
		}
	}

	/**
	 * Create a mock wpdb object for testing.
	 */
	protected function create_mock_wpdb() {
		global $wpdb;

		if ( ! isset( $wpdb ) ) {
			$wpdb          = new stdClass();
			$wpdb->prefix  = 'wp_';
			$wpdb->queries = array();

			$wpdb->query = function ( $sql ) use ( $wpdb ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->queries[] = $sql;
				return true;
			};

			$wpdb->get_var = function ( $_sql ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable, Generic.CodeAnalysis.UnusedFunctionParameter.Found
				return null;
			};

			$wpdb->get_charset_collate = function () {
				return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
			};

			$wpdb->prepare = function ( $query, ...$args ) {
				return vsprintf( str_replace( '%s', "'%s'", $query ), $args );
			};
		}

		return $wpdb;
	}
}
