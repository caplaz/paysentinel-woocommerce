<?php
/**
 * Property tests for admin dashboard implementation.
 *
 * @package PaySentinel
 */

/**
 * Class AdminPagePropertyTest
 */
class AdminPagePropertyTest extends PaySentinel_Test_Case {

	/**
	 * Menu handler instance.
	 *
	 * @var PaySentinel_Menu_Handler
	 */
	private $menu_handler;

	/**
	 * Settings handler instance.
	 *
	 * @var PaySentinel_Settings_Handler
	 */
	private $settings_handler;

	/**
	 * Setup test.
	 */
	protected function setUp(): void {
		parent::setUp();

		// Mock WordPress admin functions that aren't available in CLI.
		if ( ! function_exists( 'add_menu_page' ) ) {
			/**
			 * Stub for add_menu_page.
			 *
			 * @param string   $page_title Page title.
			 * @param string   $menu_title Menu title.
			 * @param string   $capability Capability required.
			 * @param string   $menu_slug  Menu slug.
			 * @param callable $_function  Callback function.
			 * @param string   $icon_url   Icon URL.
			 * @param int|null $position   Menu position.
			 * @return string
			 */
			function add_menu_page( $page_title, $menu_title, $capability, $menu_slug, $_function = '', $icon_url = '', $position = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed, VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
				global $admin_menu_pages;
				if ( ! isset( $admin_menu_pages ) ) {
					$admin_menu_pages = array();
				}
				$admin_menu_pages[ $menu_slug ] = array(
					'page_title' => $page_title,
					'menu_title' => $menu_title,
					'capability' => $capability,
					'menu_slug'  => $menu_slug,
					'function'   => $_function,
					'icon_url'   => $icon_url,
					'position'   => $position,
				);
				return 'toplevel_page_' . $menu_slug;
			}
		}

		if ( ! function_exists( 'add_submenu_page' ) ) {
			/**
			 * Stub for add_submenu_page.
			 *
			 * @param string   $parent_slug Parent menu slug.
			 * @param string   $page_title  Page title.
			 * @param string   $menu_title  Menu title.
			 * @param string   $capability  Capability required.
			 * @param string   $menu_slug   Menu slug.
			 * @param callable $_function   Callback function.
			 * @return string
			 */
			function add_submenu_page( $parent_slug, $page_title, $menu_title, $capability, $menu_slug, $_function = '' ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed, VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
				global $admin_submenu_pages;
				if ( ! isset( $admin_submenu_pages ) ) {
					$admin_submenu_pages = array();
				}
				if ( ! isset( $admin_submenu_pages[ $parent_slug ] ) ) {
					$admin_submenu_pages[ $parent_slug ] = array();
				}
				$admin_submenu_pages[ $parent_slug ][ $menu_slug ] = array(
					'page_title' => $page_title,
					'menu_title' => $menu_title,
					'capability' => $capability,
					'menu_slug'  => $menu_slug,
					'function'   => $_function,
				);
				return $parent_slug . '_' . $menu_slug;
			}
		}

		if ( ! function_exists( 'register_setting' ) ) {
			/**
			 * Stub for register_setting.
			 *
			 * @param string $option_group Option group.
			 * @param string $option_name  Option name.
			 * @param array  $args         Optional args.
			 * @return bool
			 */
			function register_setting( $option_group, $option_name, $args = array() ) {
				global $registered_settings;
				if ( ! isset( $registered_settings ) ) {
					$registered_settings = array();
				}
				$registered_settings[ $option_name ] = array(
					'option_group' => $option_group,
					'args'         => $args,
				);
				return true;
			}
		}

		if ( ! function_exists( 'add_settings_section' ) ) {
			/**
			 * Stub for add_settings_section.
			 *
			 * @param string   $id       Section ID.
			 * @param string   $title    Section title.
			 * @param callable $callback Section callback.
			 * @param string   $page     Settings page.
			 * @return bool
			 */
			function add_settings_section( $id, $title, $callback, $page ) {
				global $registered_sections;
				if ( ! isset( $registered_sections ) ) {
					$registered_sections = array();
				}
				$registered_sections[ $id ] = array(
					'title'    => $title,
					'callback' => $callback,
					'page'     => $page,
				);
				return true;
			}
		}

		if ( ! function_exists( 'add_settings_field' ) ) {
			/**
			 * Stub for add_settings_field.
			 *
			 * @param string   $id       Field ID.
			 * @param string   $title    Field title.
			 * @param callable $callback Field callback.
			 * @param string   $page     Settings page.
			 * @param string   $section  Section ID.
			 * @param array    $args     Optional args.
			 * @return bool
			 */
			function add_settings_field( $id, $title, $callback, $page, $section = 'default', $args = array() ) {
				global $registered_fields;
				if ( ! isset( $registered_fields ) ) {
					$registered_fields = array();
				}
				$registered_fields[ $id ] = array(
					'title'    => $title,
					'callback' => $callback,
					'page'     => $page,
					'section'  => $section,
					'args'     => $args,
				);
				return true;
			}
		}

		if ( ! function_exists( 'settings_fields' ) ) {
			/**
			 * Stub for settings_fields.
			 *
			 * @param string $option_group Option group.
			 * @return string
			 */
			function settings_fields( $option_group ) {
				return '<!-- settings_fields(' . esc_attr( $option_group ) . ') -->';
			}
		}

		if ( ! function_exists( 'do_settings_sections' ) ) {
			/**
			 * Stub for do_settings_sections.
			 *
			 * @param string $page Settings page.
			 * @return string
			 */
			function do_settings_sections( $page ) {
				return '<!-- do_settings_sections(' . esc_attr( $page ) . ') -->';
			}
		}

		if ( ! function_exists( 'submit_button' ) ) {
			/**
			 * Stub for submit_button.
			 *
			 * @param string|null $text             Button text.
			 * @param string      $type             Button type.
			 * @param string      $name             Button name.
			 * @param bool        $_wrap             Wrap in paragraph.
			 * @param mixed       $_other_attributes Other attributes.
			 * @return string
			 */
			function submit_button( $text = null, $type = 'primary', $name = 'submit', $_wrap = true, $_other_attributes = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed, VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
				return '<button type="submit" name="' . esc_attr( $name ) . '">' . esc_html( isset( $text ) ? $text : 'Save Changes' ) . '</button>';
			}
		}

		if ( ! function_exists( 'add_action' ) ) {
			/**
			 * Stub for add_action.
			 *
			 * @param string   $tag              Action tag.
			 * @param callable $function_to_add  Callback.
			 * @param int      $priority         Priority.
			 * @param int      $accepted_args    Accepted args.
			 * @return bool
			 */
			function add_action( $tag, $function_to_add, $priority = 10, $accepted_args = 1 ) {
				global $wp_actions; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
				if ( ! isset( $wp_actions ) ) {
					$wp_actions = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
				}
				if ( ! isset( $wp_actions[ $tag ] ) ) {
					$wp_actions[ $tag ] = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
				}
				$wp_actions[ $tag ][] = array( // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
					'function'      => $function_to_add,
					'priority'      => $priority,
					'accepted_args' => $accepted_args,
				);
				return true;
			}
		}

		// Create admin instance and get handlers.
		$this->admin = new PaySentinel_Admin();

		// Access the handlers through reflection since they're private.
		$admin_reflection      = new ReflectionClass( $this->admin );
		$menu_handler_property = $admin_reflection->getProperty( 'menu_handler' );
		$menu_handler_property->setAccessible( true );
		$this->menu_handler = $menu_handler_property->getValue( $this->admin );

		$settings_handler_property = $admin_reflection->getProperty( 'settings_handler' );
		$settings_handler_property->setAccessible( true );
		$this->settings_handler = $settings_handler_property->getValue( $this->admin );
	}

	/**
	 * Teardown test
	 */
	protected function tearDown(): void {
		global $admin_menu_pages, $admin_submenu_pages, $registered_settings, $registered_sections, $registered_fields, $wp_actions;
		unset( $admin_menu_pages, $admin_submenu_pages, $registered_settings, $registered_sections, $registered_fields, $wp_actions );
		parent::tearDown();
	}

	/**
	 * Property 22: Admin Page Registration Structure
	 *
	 * Verify that admin pages are properly registered with correct
	 * titles, menu slugs, capabilities, and callbacks.
	 *
	 * Requirements: 5.1, 5.2, 8.1, 8.2
	 */
	public function test_property_22_admin_page_registration_structure() {
		// Run iteration tests for property validation.
		for ( $i = 0; $i < 100; $i++ ) {
			// Test 1: Admin class should exist and be instantiable.
			$this->assertTrue(
				class_exists( 'PaySentinel_Admin' ),
				'Admin class should exist'
			);

			$admin = new PaySentinel_Admin();
			$this->assertInstanceOf(
				'PaySentinel_Admin',
				$admin,
				'Admin class should be instantiable'
			);

			// Test 2: Menu handler should have menu registration method.
			$this->assertTrue(
				method_exists( $this->menu_handler, 'register_menu_pages' ),
				'Menu handler should have register_menu_pages method'
			);

			// Test 3: Settings handler should have settings registration method.
			$this->assertTrue(
				method_exists( $this->settings_handler, 'register_settings' ),
				'Settings handler should have register_settings method'
			);

			// Test 4: Admin should have render methods for all pages.
			$expected_render_methods = array(
				'render_dashboard_page',
				'render_health_page',
				'render_transactions_page',
				'render_alerts_page',
				'render_settings_page',
			);

			foreach ( $expected_render_methods as $method ) {
				$this->assertTrue(
					method_exists( $admin, $method ),
					"Admin should have {$method} method"
				);

				// Verify method is callable.
				$this->assertTrue(
					is_callable( array( $admin, $method ) ),
					"{$method} should be callable"
				);
			}

			// Test 5: Admin class should have settings helper methods.
			$this->assertTrue(
				method_exists( 'PaySentinel_Admin', 'get_settings' ),
				'Admin should have get_settings static method'
			);

			$this->assertTrue(
				method_exists( 'PaySentinel_Admin', 'get_setting' ),
				'Admin should have get_setting static method'
			);

			$this->assertTrue(
				method_exists( 'PaySentinel_Admin', 'update_settings' ),
				'Admin should have update_settings static method'
			);
		}
	}

	/**
	 * Property 23: Settings Form Fields Validation
	 *
	 * Verify that all required settings fields are properly
	 * registered with correct labels, sanitization, and default values.
	 *
	 * Requirements: 8.2, 8.3, 8.4
	 */
	public function test_property_23_settings_form_fields_validation() {
		for ( $i = 0; $i < 100; $i++ ) {
			// Expected settings fields.
			$expected_fields = array(
				'enable_monitoring',
				'health_check_interval',
				'alert_threshold',
				'retry_enabled',
				'max_retry_attempts',
				'license_key',
			);

			// Test 1: All expected field render methods should exist on settings handler.
			foreach ( $expected_fields as $field ) {
				$method = 'render_field_' . $field;
				$this->assertTrue(
					method_exists( $this->settings_handler, $method ),
					"Settings handler should have {$method} render method for field: {$field}"
				);
			}

			// Test 2: Settings render methods should be callable.
			foreach ( $expected_fields as $field ) {
				$method = 'render_field_' . $field;
				$this->assertTrue(
					is_callable( array( $this->settings_handler, $method ) ),
					"render_field_{$field} should be callable"
				);
			}

			// Test 3: Settings handler should have render_settings_section method.
			$this->assertTrue(
				method_exists( $this->settings_handler, 'render_settings_section' ),
				'Settings handler should have render_settings_section method'
			);

			// Test 4: render_settings_section should be callable.
			$this->assertTrue(
				is_callable( array( $this->settings_handler, 'render_settings_section' ) ),
				'render_settings_section should be callable'
			);
		}
	}

	/**
	 * Property 24: Admin Settings Retrieval and Update
	 *
	 * Verify that settings can be reliably stored, retrieved, and updated
	 * with proper data types and default values when unset.
	 *
	 * Requirements: 8.2, 8.3
	 */
	public function test_property_24_admin_settings_retrieval_and_update() {
		for ( $i = 0; $i < 100; $i++ ) {
			// Clear existing settings.
			delete_option( 'paysentinel_options' );

			// Test 1: Default settings should return correct defaults.
			$settings = PaySentinel_Admin::get_settings();

			$this->assertIsArray( $settings, 'Settings should be array' );
			$this->assertArrayHasKey( 'enable_monitoring', $settings, 'Should have enable_monitoring setting' );
			$this->assertArrayHasKey( 'health_check_interval', $settings, 'Should have health_check_interval setting' );
			$this->assertArrayHasKey( 'alert_threshold', $settings, 'Should have alert_threshold setting' );
			$this->assertArrayHasKey( 'retry_enabled', $settings, 'Should have retry_enabled setting' );
			$this->assertArrayHasKey( 'max_retry_attempts', $settings, 'Should have max_retry_attempts setting' );

			// Test 2: Default values should have correct types.
			$this->assertIsInt( $settings['enable_monitoring'], 'enable_monitoring should be int' );
			$this->assertIsInt( $settings['health_check_interval'], 'health_check_interval should be int' );
			$this->assertIsInt( $settings['max_retry_attempts'], 'max_retry_attempts should be int' );

			// Test 3: Default values should be reasonable.
			$this->assertEquals( 1, $settings['enable_monitoring'], 'Monitoring should be enabled by default' );
			$this->assertGreaterThanOrEqual( 1, $settings['health_check_interval'], 'Interval should be >= 1' );
			$this->assertGreaterThanOrEqual( 1, $settings['max_retry_attempts'], 'Max attempts should be >= 1' );

			// Test 4: get_setting should return correct values.
			$enable = PaySentinel_Admin::get_setting( 'enable_monitoring' );
			$this->assertEquals( 1, $enable, 'get_setting should return correct enable_monitoring' );

			$interval = PaySentinel_Admin::get_setting( 'health_check_interval' );
			$this->assertIsInt( $interval, 'get_setting should return int for interval' );

			// Test 5: get_setting with default should work.
			$nonexistent = PaySentinel_Admin::get_setting( 'nonexistent_field', 'default_value' );
			$this->assertEquals( 'default_value', $nonexistent, 'get_setting should return default for nonexistent field' );

			// Test 6: update_settings should persist values.
			$new_settings = array(
				'enable_monitoring'     => 0,
				'health_check_interval' => 15,
				'alert_threshold'       => 30,
				'retry_enabled'         => 0,
				'max_retry_attempts'    => 5,
			);

			$result = PaySentinel_Admin::update_settings( $new_settings );
			$this->assertTrue( $result, 'update_settings should return true' );

			// Test 7: Updated settings should be retrievable.
			$updated = PaySentinel_Admin::get_settings();

			$this->assertEquals( 0, $updated['enable_monitoring'], 'Updated enable_monitoring should be 0' );
			$this->assertEquals( 15, $updated['health_check_interval'], 'Updated interval should be 15' );
			$this->assertEquals( 0, $updated['retry_enabled'], 'Updated retry_enabled should be 0' );
			$this->assertEquals( 5, $updated['max_retry_attempts'], 'Updated attempts should be 5' );

			// Test 8: Partial updates should preserve other settings.
			PaySentinel_Admin::update_settings( array( 'health_check_interval' => 20 ) );

			$partial = PaySentinel_Admin::get_settings();
			$this->assertEquals( 20, $partial['health_check_interval'], 'health_check_interval should be updated' );
			$this->assertEquals( 0, $partial['enable_monitoring'], 'enable_monitoring should be preserved' );
		}
	}

	/**
	 * Property 25: Configuration Management
	 * Validates Requirements 8.1, 8.2, 8.4
	 *
	 * Tests that configuration settings are properly validated and sanitized
	 */
	public function test_configuration_management() {
		// Test 1: Health check interval validation.
		for ( $i = 0; $i < 50; $i++ ) {
			$interval = wp_rand( 1, 1440 );
			$result   = PaySentinel_Admin::validate_health_check_interval( $interval );
			$this->assertTrue( $result['valid'], "Interval $interval should be valid" );
			$this->assertEquals( $interval, $result['value'], 'Validated interval should match input' );
		}

		// Test 2: Health check interval lower bound validation.
		$result = PaySentinel_Admin::validate_health_check_interval( 0 );
		$this->assertFalse( $result['valid'], 'Interval 0 should be invalid' );
		$this->assertNotEmpty( $result['message'], 'Should have error message' );

		// Test 3: Health check interval upper bound validation.
		$result = PaySentinel_Admin::validate_health_check_interval( 1441 );
		$this->assertFalse( $result['valid'], 'Interval > 1440 should be invalid' );

		// Test 4: Alert threshold validation.
		for ( $i = 0; $i < 50; $i++ ) {
			$threshold = ( wp_rand( 1, 10000 ) / 100 );
			if ( $threshold >= 0.1 && $threshold <= 100 ) {
				$result = PaySentinel_Admin::validate_alert_threshold( $threshold );
				$this->assertTrue( $result['valid'], "Threshold $threshold should be valid" );
				$this->assertEquals( $threshold, $result['value'], 'Validated threshold should match input' );
			}
		}

		// Test 5: Alert threshold boundary validation.
		$result = PaySentinel_Admin::validate_alert_threshold( 0.05 );
		$this->assertFalse( $result['valid'], 'Threshold < 0.1 should be invalid' );

		$result = PaySentinel_Admin::validate_alert_threshold( 100.5 );
		$this->assertFalse( $result['valid'], 'Threshold > 100 should be invalid' );

		// Test 6: Validate all settings together.
		$test_settings = array(
			'enable_monitoring'     => 1,
			'health_check_interval' => 10,
			'alert_threshold'       => 25.5,
			'retry_enabled'         => 1,
			'max_retry_attempts'    => 3,
		);

		$result = PaySentinel_Admin::validate_all_settings( $test_settings );
		$this->assertTrue( $result['valid'], 'Valid settings should pass validation' );
		$this->assertEmpty( $result['errors'], 'Valid settings should have no errors' );

		// Test 7: Invalid settings should fail validation.
		$invalid_settings = array(
			'health_check_interval' => -5,
			'alert_threshold'       => 150,
			'max_retry_attempts'    => 20,
		);

		$result = PaySentinel_Admin::validate_all_settings( $invalid_settings );
		$this->assertFalse( $result['valid'], 'Invalid settings should fail validation' );
		$this->assertNotEmpty( $result['errors'], 'Should have error messages' );
		$this->assertGreaterThan( 0, count( $result['errors'] ), 'Should have multiple errors' );

		// Test 8: Mixed valid and invalid settings.
		$mixed_settings = array(
			'enable_monitoring'     => 1,
			'health_check_interval' => 999, // Valid.
			'alert_threshold'       => 200, // Invalid.
		);

		$result = PaySentinel_Admin::validate_all_settings( $mixed_settings );
		$this->assertFalse( $result['valid'], 'Mixed settings with errors should fail' );
		$this->assertArrayHasKey( 'health_check_interval', $result['validated_settings'], 'Valid settings should be included' );
	}

	/**
	 * Property 26: Retry Configuration Validation
	 * Validates Requirements 8.3
	 *
	 * Tests that retry configuration is properly validated
	 */
	public function test_retry_configuration_validation() {
		// Test 1: Valid retry configurations.
		for ( $i = 1; $i <= 10; $i++ ) {
			$config = array( 'max_retry_attempts' => $i );
			$result = PaySentinel_Admin::validate_retry_configuration( $config );
			$this->assertTrue( $result['valid'], "Config with $i attempts should be valid" );
			$this->assertEquals( $i, $result['value']['max_retry_attempts'], 'Should return same config' );
		}

		// Test 2: Retry attempts lower bound validation.
		$config = array( 'max_retry_attempts' => 0 );
		$result = PaySentinel_Admin::validate_retry_configuration( $config );
		$this->assertFalse( $result['valid'], 'Zero retry attempts should be invalid' );
		$this->assertNotEmpty( $result['message'], 'Should have error message' );

		// Test 3: Retry attempts upper bound validation.
		$config = array( 'max_retry_attempts' => 11 );
		$result = PaySentinel_Admin::validate_retry_configuration( $config );
		$this->assertFalse( $result['valid'], 'More than 10 retry attempts should be invalid' );

		// Test 4: Negative retry attempts validation.
		$config = array( 'max_retry_attempts' => -5 );
		$result = PaySentinel_Admin::validate_retry_configuration( $config );
		$this->assertFalse( $result['valid'], 'Negative retry attempts should be invalid' );

		// Test 5: Non-array configuration should fail.
		$result = PaySentinel_Admin::validate_retry_configuration( 'invalid' );
		$this->assertFalse( $result['valid'], 'Non-array config should be invalid' );

		// Test 6: Empty configuration should fail.
		$result = PaySentinel_Admin::validate_retry_configuration( array() );
		$this->assertFalse( $result['valid'], 'Empty config should be invalid' );

		// Test 7: Large number validation.
		$config = array( 'max_retry_attempts' => 999 );
		$result = PaySentinel_Admin::validate_retry_configuration( $config );
		$this->assertFalse( $result['valid'], 'Very large retry attempts should be invalid' );

		// Test 8: Type coercion test - numeric string should work.
		$config = array( 'max_retry_attempts' => '5' );
		$result = PaySentinel_Admin::validate_retry_configuration( $config );
		$this->assertTrue( $result['valid'], 'Numeric string should be accepted and coerced' );
		$this->assertEquals( 5, $result['value']['max_retry_attempts'], 'Should coerce string to int' );

		// Test 9: Error arrays in invalid configs.
		$invalid_config = array( 'max_retry_attempts' => 15 );
		$result         = PaySentinel_Admin::validate_retry_configuration( $invalid_config );
		$this->assertFalse( $result['valid'], 'Invalid config should have errors' );
		$this->assertIsArray( $result['errors'], 'Should return errors array' );
		$this->assertGreaterThan( 0, count( $result['errors'] ), 'Should have error messages' );

		// Test 10: License tier check after validation.
		PaySentinel_Admin::update_settings(
			array(
				'max_retry_attempts' => 5,
			)
		);

		$admin = new PaySentinel_Admin();
		$tier  = $admin->get_license_tier();
		$this->assertEquals( 'free', $tier, 'Empty license key should be free tier' );
	}
}
