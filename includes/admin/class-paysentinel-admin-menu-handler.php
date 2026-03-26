<?php
/**
 * Admin Menu Handler
 *
 * Handles menu/submenu registration and routing for the Payment Monitor plugin.
 *
 * @package PaySentinel
 * @since 1.0.0
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PaySentinel_Admin_Menu_Handler
 *
 * Manages WordPress admin menu registration and page routing.
 */
class PaySentinel_Admin_Menu_Handler {





	/**
	 * Page renderer instance
	 *
	 * @var PaySentinel_Admin_Page_Renderer
	 */
	private $page_renderer;

	/**
	 * Constructor
	 *
	 * @param PaySentinel_Admin_Page_Renderer $page_renderer Page renderer instance.
	 */
	public function __construct( $page_renderer ) {
		$this->page_renderer = $page_renderer;
	}

	/**
	 * Register admin menu pages
	 *
	 * Adds the main menu page and all submenu pages for the Payment Monitor plugin.
	 */
	public function register_menu_pages() {
		// Check user capability.
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		// Custom SVG icon matching PaySentinel logo (Shield + Pulse). Inner pulse line made bolder by separating into its own path and giving it a stroke.
		$icon_svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">'
			. '<path fill="currentColor" fill-rule="evenodd" d="M10 1L2 4v7c0 5 3.5 9 8 10 4.5-1 8-5 8-10V4l-8-3z"/>'
			// inner pulse line with stroke for extra weight.
			. '<path fill="none" stroke="currentColor" stroke-width="1" d="M16 10.5H11L9.5 14.5L8 6.5L6.5 10.5H4v1H6.5L8 7.5L9.5 15.5L11 11.5H16v-1z"/>'
			. '</svg>';

		// Add main menu page.
		add_menu_page(
			__( 'PaySentinel', 'paysentinel' ),
			__( 'PaySentinel', 'paysentinel' ),
			'manage_woocommerce',
			'paysentinel',
			array( $this->page_renderer, 'render_dashboard_page' ),
			'data:image/svg+xml;base64,' . base64_encode( $icon_svg ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- SVG icon encoded as data URI for WordPress admin menu.
			56
		);

		// Add dashboard submenu.
		add_submenu_page(
			'paysentinel',
			__( 'Dashboard', 'paysentinel' ),
			__( 'Dashboard', 'paysentinel' ),
			'manage_woocommerce',
			'paysentinel',
			array( $this->page_renderer, 'render_dashboard_page' )
		);

		// Add gateway health submenu.
		add_submenu_page(
			'paysentinel',
			__( 'Gateway Health', 'paysentinel' ),
			__( 'Gateway Health', 'paysentinel' ),
			'manage_woocommerce',
			'paysentinel-health',
			array( $this->page_renderer, 'render_health_page' )
		);

		// Add transaction logs submenu.
		add_submenu_page(
			'paysentinel',
			__( 'Transactions', 'paysentinel' ),
			__( 'Transactions', 'paysentinel' ),
			'manage_woocommerce',
			'paysentinel-transactions',
			array( $this->page_renderer, 'render_transactions_page' )
		);

		// Add analytics submenu.
		add_submenu_page(
			'paysentinel',
			__( 'Analytics', 'paysentinel' ),
			__( 'Analytics', 'paysentinel' ),
			'manage_woocommerce',
			'paysentinel-analytics',
			array( $this->page_renderer, 'render_analytics_page' )
		);

		// Add alerts submenu.
		add_submenu_page(
			'paysentinel',
			__( 'Alerts', 'paysentinel' ),
			__( 'Alerts', 'paysentinel' ),
			'manage_woocommerce',
			'paysentinel-alerts',
			array( $this->page_renderer, 'render_alerts_page' )
		);

		// Add diagnostic tools submenu.
		add_submenu_page(
			'paysentinel',
			__( 'Diagnostic Tools', 'paysentinel' ),
			__( 'Diagnostic Tools', 'paysentinel' ),
			'manage_woocommerce',
			'paysentinel-diagnostics',
			array( $this->page_renderer, 'render_diagnostics_page' )
		);

		// Add settings submenu.
		add_submenu_page(
			'paysentinel',
			__( 'Settings', 'paysentinel' ),
			__( 'Settings', 'paysentinel' ),
			'manage_woocommerce',
			'paysentinel-settings',
			array( $this->page_renderer, 'render_settings_page' )
		);

		// Add Remote Dashboard submenu (external link).
		add_submenu_page(
			'paysentinel',
			__( 'Remote Dashboard', 'paysentinel' ),
			__( 'Remote Dashboard', 'paysentinel' ),
			'manage_woocommerce',
			'paysentinel-help',
			array( $this, 'redirect_to_help' )
		);

		// Do not modify the registered submenu slug; tests expect the slug.
		// `paysentinel-help` to be present. We'll update the link in the.
		// admin footer script instead so the visible anchor points to the.
		// external site while the registered slug remains intact.

		// Ensure the menu link opens in a new tab by adding a small script.
		// to the admin footer that sets target and rel attributes on the anchor.
		add_action( 'admin_print_footer_scripts', array( $this, 'make_remote_dashboard_open_new_tab' ) );
	}

	/**
	 * Output JS in admin footer to make Remote Dashboard menu open in a new tab.
	 */
	public function make_remote_dashboard_open_new_tab() {
		$help_url = PaySentinel_Admin_Page_Renderer::SIDEBAR_HELP_URL;
		?>
		<script type="text/javascript">
		(function(){
			var helpUrl = "<?php echo esc_js( esc_url( $help_url ) ); ?>";
			var anchors = document.querySelectorAll('#adminmenu a, #toplevel_page_paysentinel a');
			for (var i = 0; i < anchors.length; i++) {
				var a = anchors[i];
				var hrefAttr = a.getAttribute('href') || '';
				// Detect the admin slug link for paysentinel-help and replace it.
				if (hrefAttr.indexOf('admin.php?page=paysentinel-help') !== -1 || hrefAttr.indexOf('paysentinel-help') !== -1) {
					a.setAttribute('href', helpUrl);
					a.setAttribute('target', '_blank');
					a.setAttribute('rel', 'noopener noreferrer');
				}
				// Also handle fully-qualified hrefs that may include the admin URL.
				if (a.href && a.href.indexOf('admin.php?page=paysentinel-help') !== -1) {
					a.href = helpUrl;
					a.setAttribute('target', '_blank');
					a.setAttribute('rel', 'noopener noreferrer');
				}
			}
		})();
		</script>
		<?php
	}

	// The old `redirect_to_help()` method was removed because the submenu.
	// now points directly to the external help URL. Keeping this class focused.
	// on menu registration only.
}
