<?php

/**
 * Unit tests for admin page header components.
 *
 * These tests run without a full WordPress environment (plain PHPUnit).
 * They cover:
 * - PaySentinel_Admin_Page_Renderer::HELP_URL constant
 * - render_page_header() output (h1)
 */
class AdminPageHeaderTest extends PaySentinel_Test_Case {



	/**
	 * Renderer instance obtained via reflection.
	 *
	 * @var PaySentinel_Admin_Page_Renderer
	 */
	private $renderer;

	/**
	 * Setup: grab the renderer from a fresh admin instance.
	 */
	protected function setUp(): void {
		parent::setUp();

		// Stub WordPress menu-registration functions (not needed here, but
		// PaySentinel_Admin constructor may call them indirectly).
		foreach ( array( 'add_menu_page', 'add_submenu_page', 'add_action', 'add_filter' ) as $fn ) {
			if ( ! function_exists( $fn ) ) {
				// phpcs:disable
				eval ("function {$fn}() { return ''; }");
				// phpcs:enable
			}
		}

		$admin      = new PaySentinel_Admin();
		$reflection = new ReflectionClass( $admin );

		$prop = $reflection->getProperty( 'page_renderer' );
		$prop->setAccessible( true );
		$this->renderer = $prop->getValue( $admin );
	}

	// -------------------------------------------------------------------------
	// HELP_URL constant
	// -------------------------------------------------------------------------

	/**
	 * HELP_URL must be defined on the renderer class.
	 */
	public function test_help_url_constant_is_defined() {
		$this->assertTrue(
			defined( 'PaySentinel_Admin_Page_Renderer::HELP_URL' ),
			'PaySentinel_Admin_Page_Renderer::HELP_URL must be defined'
		);
	}

	/**
	 * HELP_URL must be a non-empty HTTPS URL.
	 */
	public function test_help_url_is_valid_https_url() {
		$url = PaySentinel_Admin_Page_Renderer::HELP_URL;

		$this->assertIsString( $url, 'HELP_URL must be a string' );
		$this->assertNotEmpty( $url, 'HELP_URL must not be empty' );
		$this->assertStringStartsWith( 'https://', $url, 'HELP_URL must use HTTPS' );
		$this->assertNotFalse(
			filter_var( $url, FILTER_VALIDATE_URL ),
			'HELP_URL must be a valid URL'
		);
		// should still use docs path here
		$this->assertStringContainsString(
			'/docs/user-guide',
			$url,
			'HELP_URL should point to the documentation subtree'
		);
	}

	/**
	 * HELP_URL must not contain the old placeholder domain.
	 */
	public function test_help_url_is_not_old_placeholder() {
		$this->assertStringNotContainsString(
			'paysentinel.io',
			PaySentinel_Admin_Page_Renderer::HELP_URL,
			'HELP_URL must not use the old placeholder domain'
		);
	}

	/**
	 * HELP_URL must equal the official published docs URL.
	 */
	public function test_help_url_equals_official_docs_url() {
		$this->assertSame(
			'https://paysentinel.caplaz.com/docs/user-guide',
			PaySentinel_Admin_Page_Renderer::HELP_URL
		);
	}

	/**
	 * SIDEBAR_HELP_URL must be defined and should point to the site root.
	 */
	public function test_sidebar_help_url_defined_and_site_root() {
		$this->assertTrue(
			defined( 'PaySentinel_Admin_Page_Renderer::SIDEBAR_HELP_URL' ),
			'SIDEBAR_HELP_URL must be defined'
		);
		$this->assertSame(
			'https://paysentinel.caplaz.com',
			PaySentinel_Admin_Page_Renderer::SIDEBAR_HELP_URL
		);
	}

	// -------------------------------------------------------------------------
	// render_page_header() — h1 + button in one shot
	// -------------------------------------------------------------------------

	/**
	 * render_page_header() must be private (internal API).
	 */
	public function test_render_page_header_is_private_method() {
		$m = new ReflectionMethod( PaySentinel_Admin_Page_Renderer::class, 'render_page_header' );
		$this->assertTrue( $m->isPrivate(), 'render_page_header() must be private' );
	}

	/**
	 * render_page_header() must output an h1 containing the supplied title.
	 */
	public function test_render_page_header_outputs_h1_with_title() {
		$output = $this->invoke_header( 'My Page' );

		$this->assertStringContainsString( '<h1>', $output );
		$this->assertStringContainsString( 'My Page', $output );
	}



	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	private function invoke_header( string $title, string $anchor = '' ): string {
		$m = new ReflectionMethod( $this->renderer, 'render_page_header' );
		$m->setAccessible( true );
		ob_start();
		$m->invoke( $this->renderer, $title, $anchor );
		return ob_get_clean();
	}
}

// File-scope constant to avoid repeating the long URL in assertions
if ( ! defined( 'HELP_URL_BASE' ) ) {
	define( 'HELP_URL_BASE', 'https://paysentinel.caplaz.com/docs/user-guide' );
}
