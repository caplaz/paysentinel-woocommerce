<?php

/**
 * Unit tests for admin page header components.
 *
 * These tests run without a full WordPress environment (plain PHPUnit).
 * They cover:
 * - PaySentinel_Admin_Page_Renderer::HELP_URL constant
 * - render_page_header() output (h1 + help button)
 * - render_help_button() output (button only, no h1)
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

	/**
	 * render_page_header() must output the Help & Documentation button.
	 */
	public function test_render_page_header_outputs_help_button() {
		$output = $this->invoke_header( 'Any' );

		$this->assertStringContainsString( 'button button-secondary', $output );
		$this->assertStringContainsString( 'Help &amp; Documentation', $output );
		$this->assertStringContainsString( 'target="_blank"', $output );
	}

	/**
	 * render_page_header() must include HELP_URL in the button href.
	 */
	public function test_render_page_header_uses_help_url_constant_in_href() {
		$output = $this->invoke_header( 'Any' );

		$this->assertStringContainsString(
			PaySentinel_Admin_Page_Renderer::HELP_URL,
			$output,
			'render_page_header must embed HELP_URL in the href'
		);
	}

	/**
	 * render_page_header() must append #anchor when one is provided.
	 */
	public function test_render_page_header_appends_anchor_fragment() {
		$output = $this->invoke_header( 'Health', 'health' );

		$this->assertStringContainsString(
			PaySentinel_Admin_Page_Renderer::HELP_URL . '#health',
			$output
		);
	}

	/**
	 * render_page_header() must NOT add a trailing # when no anchor is given.
	 */
	public function test_render_page_header_no_fragment_without_anchor() {
		$output = $this->invoke_header( 'Dashboard' );

		// The base URL should appear, but not with a trailing hash
		$this->assertStringNotContainsString(
			PaySentinel_Admin_Page_Renderer::HELP_URL . '#',
			$output
		);
	}

	/**
	 * render_page_header() must strip a leading # from the anchor argument.
	 */
	public function test_render_page_header_strips_leading_hash_from_anchor() {
		$output_explicit = $this->invoke_header( 'Settings', '#settings' );
		$output_clean    = $this->invoke_header( 'Settings', 'settings' );

		// Both should produce the same href
		$this->assertStringContainsString( HELP_URL_BASE . '#settings', $output_explicit );
		$this->assertStringContainsString( HELP_URL_BASE . '#settings', $output_clean );
	}

	// -------------------------------------------------------------------------
	// render_help_button() — button only, no h1
	// -------------------------------------------------------------------------

	/**
	 * render_help_button() must be private.
	 */
	public function test_render_help_button_is_private_method() {
		$m = new ReflectionMethod( PaySentinel_Admin_Page_Renderer::class, 'render_help_button' );
		$this->assertTrue( $m->isPrivate(), 'render_help_button() must be private' );
	}

	/**
	 * render_help_button() must output the button but NOT an h1.
	 */
	public function test_render_help_button_outputs_button_without_h1() {
		$output = $this->invoke_help_button( 'transactions' );

		$this->assertStringContainsString( 'button button-secondary', $output );
		$this->assertStringContainsString( 'Help &amp; Documentation', $output );
		$this->assertStringNotContainsString( '<h1>', $output, 'render_help_button must not emit an h1' );
	}

	/**
	 * render_help_button() must include HELP_URL in its href.
	 */
	public function test_render_help_button_uses_help_url_constant() {
		$output = $this->invoke_help_button();

		$this->assertStringContainsString( PaySentinel_Admin_Page_Renderer::HELP_URL, $output );
	}

	/**
	 * render_help_button() must append the given anchor.
	 */
	public function test_render_help_button_appends_anchor() {
		$output = $this->invoke_help_button( 'settings' );

		$this->assertStringContainsString(
			PaySentinel_Admin_Page_Renderer::HELP_URL . '#settings',
			$output
		);
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

	private function invoke_help_button( string $anchor = '' ): string {
		$m = new ReflectionMethod( $this->renderer, 'render_help_button' );
		$m->setAccessible( true );
		ob_start();
		$m->invoke( $this->renderer, $anchor );
		return ob_get_clean();
	}
}

// File-scope constant to avoid repeating the long URL in assertions
if ( ! defined( 'HELP_URL_BASE' ) ) {
	define( 'HELP_URL_BASE', 'https://paysentinel.caplaz.com/docs/user-guide' );
}
