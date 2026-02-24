<?php

/**
 * Integration tests for admin page header consistency.
 *
 * Requires a full WordPress + WooCommerce environment (runs via `make test`).
 * Covers:
 * - WordPress sidebar menu label ("PaySentinel", not "Payment Monitor")
 * - All expected submenu slugs are registered
 * - Submenu page_title and menu_title are consistent with each other
 * - Every render_*_page() method outputs an h1 matching its sidebar label
 * - Help & Documentation button has been removed from page headers
 */
class AdminPageHeaderIntegrationTest extends WP_UnitTestCase {


	/**
	 * @var PaySentinel_Admin_Page_Renderer
	 */
	private $renderer;

	/**
	 * @var PaySentinel_Admin_Menu_Handler
	 */
	private $menu_handler;

	/**
	 * Admin user with manage_woocommerce capability.
	 *
	 * @var int
	 */
	private $admin_user_id;

	/**
	 * Setup
	 */
	protected function setUp(): void {
		parent::setUp();

		$admin      = new PaySentinel_Admin();
		$reflection = new ReflectionClass( $admin );

		$rp = $reflection->getProperty( 'page_renderer' );
		$rp->setAccessible( true );
		$this->renderer = $rp->getValue( $admin );

		$mp = $reflection->getProperty( 'menu_handler' );
		$mp->setAccessible( true );
		$this->menu_handler = $mp->getValue( $admin );

		// Create an admin user and grant the WooCommerce capability used by the menu
		$this->admin_user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_user_id );
		wp_get_current_user()->add_cap( 'manage_woocommerce' );
	}

	// -------------------------------------------------------------------------
	// Sidebar menu label
	// -------------------------------------------------------------------------

	/**
	 * The WordPress admin sidebar must show "PaySentinel", not "Payment Monitor".
	 */
	public function test_sidebar_menu_label_is_paysentinel() {
		global $menu;

		$this->menu_handler->register_menu_pages();

		$found = false;
		foreach ( $menu as $item ) {
			if ( $item[2] === 'paysentinel' ) {
				$found = true;
				$this->assertSame( 'PaySentinel', $item[3], 'page_title must be "PaySentinel"' );
				$this->assertSame( 'PaySentinel', $item[0], 'menu_title must be "PaySentinel"' );
				break;
			}
		}

		$this->assertTrue( $found, 'Top-level menu page with slug "paysentinel" must be registered' );

		foreach ( $menu as $item ) {
			if ( $item[2] === 'paysentinel' ) {
				$this->assertStringNotContainsString(
					'Payment Monitor',
					$item[0],
					'Menu label must not still read "Payment Monitor"'
				);
			}
		}
	}

	// -------------------------------------------------------------------------
	// Submenu registration
	// -------------------------------------------------------------------------

	/**
	 * All expected submenu pages must be registered.
	 */
	public function test_all_expected_submenu_slugs_are_registered() {
		global $submenu;

		$this->menu_handler->register_menu_pages();

		$expected = array(
			'paysentinel',
			'paysentinel-health',
			'paysentinel-transactions',
			'paysentinel-alerts',
			'paysentinel-diagnostics',
			'paysentinel-settings',
			'paysentinel-help',
		);

		$this->assertArrayHasKey( 'paysentinel', $submenu, "Submenu key 'paysentinel' must exist in global \$submenu" );

		$registered_slugs = array_column( $submenu['paysentinel'], 2 );

		foreach ( $expected as $slug ) {
			$this->assertContains(
				$slug,
				$registered_slugs,
				"Submenu page '{$slug}' must be registered"
			);
		}
	}

	/**
	 * For each submenu, page_title must equal menu_title and must not use old prefix patterns.
	 */
	public function test_submenu_page_title_matches_menu_title() {
		global $submenu;

		$this->menu_handler->register_menu_pages();

		$this->assertArrayHasKey( 'paysentinel', $submenu );
		$this->assertNotEmpty( $submenu['paysentinel'] );

		foreach ( $submenu['paysentinel'] as $entry ) {
			// $entry format: [0] => menu_title, [1] => capability, [2] => menu_slug, [3] => page_title
			$this->assertSame(
				$entry[3],
				$entry[0],
				"Submenu '{$entry[2]}': page_title and menu_title must be identical"
			);
			$this->assertStringNotContainsString(
				'Payment Monitor -',
				$entry[3],
				"Submenu '{$entry[2]}' must not use the 'Payment Monitor -' prefix pattern"
			);
		}
	}

	// -------------------------------------------------------------------------
	// Page h1 titles
	// -------------------------------------------------------------------------

	/**
	 * Each render method must output an h1 matching the expected title.
	 *
	 * @dataProvider page_title_provider
	 */
	public function test_page_h1_title_matches_expected( string $method, string $expected_title ) {
		$output = $this->capture( $method );

		$this->assertStringContainsString( '<h1', $output, "{$method} must output an <h1> tag" );
		$this->assertStringContainsString( '</h1>', $output, "{$method} must output a closing </h1> tag" );
		$this->assertStringContainsString(
			$expected_title,
			$output,
			"{$method} h1 must contain '{$expected_title}'"
		);
		$this->assertStringNotContainsString(
			'Payment Monitor -',
			$output,
			"{$method} must not use the old 'Payment Monitor -' prefix"
		);
	}

	/**
	 * @return array<string, array{string, string}>
	 */
	public function page_title_provider(): array {
		return array(
			'dashboard'    => array( 'render_dashboard_page', 'Dashboard' ),
			'health'       => array( 'render_health_page', 'Gateway Health' ),
			'transactions' => array( 'render_transactions_page', 'Transactions' ),
			'alerts'       => array( 'render_alerts_page', 'Alerts' ),
			'settings'     => array( 'render_settings_page', 'Settings' ),
			'diagnostics'  => array( 'render_diagnostics_page', 'Diagnostic Tools' ),
		);
	}

	// -------------------------------------------------------------------------
	// Help button absence
	// -------------------------------------------------------------------------

	/**
	 * No render method should output a Help & Documentation button now that it is
	 * removed from the UI. We assert the absence of the button markup.
	 *
	 * @dataProvider all_pages_provider
	 */
	public function test_every_page_does_not_include_help_button( string $method ) {
		$output = $this->capture( $method );

		$this->assertStringNotContainsString(
			'Help &amp; Documentation',
			$output,
			"{$method} must not include the help button text"
		);
		$this->assertStringNotContainsString(
			'button button-secondary',
			$output,
			"{$method} must not output any secondary button for help"
		);
	}

	/**
	 * @return array<string, array{string}>
	 */
	public function all_pages_provider(): array {
		return array(
			'dashboard'    => array( 'render_dashboard_page' ),
			'health'       => array( 'render_health_page' ),
			'transactions' => array( 'render_transactions_page' ),
			'alerts'       => array( 'render_alerts_page' ),
			'settings'     => array( 'render_settings_page' ),
			'diagnostics'  => array( 'render_diagnostics_page' ),
		);
	}

	// -------------------------------------------------------------------------
	// Helper
	// -------------------------------------------------------------------------

	/**
	 * Capture buffered output from a renderer method.
	 */
	private function capture( string $method ): string {
		ob_start();
		try {
			$this->renderer->$method();
		} catch ( \Throwable $e ) {
			// Swallow — some methods may call wp_die() before finishing output.
		}
		return ob_get_clean();
	}
}
