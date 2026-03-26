<?php
/**
 * Tests file.
 *
 * @package PaySentinel
 */

/**
 * Class GatewayManagerTest
 */
class GatewayManagerTest extends WP_UnitTestCase {

	/**
	 * Gateway manager instance.
	 *
	 * @var PaySentinel_Gateway_Manager
	 */
	private $gateway_manager;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->gateway_manager = new PaySentinel_Gateway_Manager();
	}

	/**
	 * Test that gateway manager can be instantiated
	 */
	public function test_gateway_manager_instantiation() {
		$this->assertInstanceOf( PaySentinel_Gateway_Manager::class, $this->gateway_manager );
	}

	/**
	 * Test is_payment_processor_gateway filters out card gateway
	 */
	public function test_card_gateway_is_excluded() {
		$reflection = new ReflectionClass( 'PaySentinel_Gateway_Manager' );
		$method     = $reflection->getMethod( 'is_payment_processor_gateway' );
		$method->setAccessible( true );

		// Create a mock card gateway.
		$card_gateway     = $this->getMockBuilder( 'WC_Payment_Gateway' )
			->disableOriginalConstructor()
			->getMock();
		$card_gateway->id = 'card';

		// Card gateway should be excluded.
		$is_processor = $method->invoke( $this->gateway_manager, $card_gateway );
		$this->assertFalse( $is_processor, 'Card payment method should not be treated as a processor gateway' );
	}

	/**
	 * Test is_payment_processor_gateway filters out bacs gateway
	 */
	public function test_bacs_gateway_is_excluded() {
		$reflection = new ReflectionClass( 'PaySentinel_Gateway_Manager' );
		$method     = $reflection->getMethod( 'is_payment_processor_gateway' );
		$method->setAccessible( true );

		// Create a mock BACS gateway.
		$bacs_gateway     = $this->getMockBuilder( 'WC_Payment_Gateway' )
			->disableOriginalConstructor()
			->getMock();
		$bacs_gateway->id = 'bacs';

		// BACS gateway should be excluded.
		$is_processor = $method->invoke( $this->gateway_manager, $bacs_gateway );
		$this->assertFalse( $is_processor, 'BACS offline payment method should not be treated as a processor gateway' );
	}

	/**
	 * Test is_payment_processor_gateway filters out cheque gateway
	 */
	public function test_cheque_gateway_is_excluded() {
		$reflection = new ReflectionClass( 'PaySentinel_Gateway_Manager' );
		$method     = $reflection->getMethod( 'is_payment_processor_gateway' );
		$method->setAccessible( true );

		// Create a mock cheque gateway.
		$cheque_gateway     = $this->getMockBuilder( 'WC_Payment_Gateway' )
			->disableOriginalConstructor()
			->getMock();
		$cheque_gateway->id = 'cheque';

		// Cheque gateway should be excluded.
		$is_processor = $method->invoke( $this->gateway_manager, $cheque_gateway );
		$this->assertFalse( $is_processor, 'Cheque offline payment method should not be treated as a processor gateway' );
	}

	/**
	 * Test is_payment_processor_gateway filters out cod gateway
	 */
	public function test_cod_gateway_is_excluded() {
		$reflection = new ReflectionClass( 'PaySentinel_Gateway_Manager' );
		$method     = $reflection->getMethod( 'is_payment_processor_gateway' );
		$method->setAccessible( true );

		// Create a mock COD gateway.
		$cod_gateway     = $this->getMockBuilder( 'WC_Payment_Gateway' )
			->disableOriginalConstructor()
			->getMock();
		$cod_gateway->id = 'cod';

		// COD gateway should be excluded.
		$is_processor = $method->invoke( $this->gateway_manager, $cod_gateway );
		$this->assertFalse( $is_processor, 'Cash on Delivery offline payment method should not be treated as a processor gateway' );
	}

	/**
	 * Test is_payment_processor_gateway includes real payment gateways
	 */
	public function test_stripe_gateway_is_included() {
		$reflection = new ReflectionClass( 'PaySentinel_Gateway_Manager' );
		$method     = $reflection->getMethod( 'is_payment_processor_gateway' );
		$method->setAccessible( true );

		// Create a mock Stripe gateway.
		$stripe_gateway       = $this->getMockBuilder( 'WC_Payment_Gateway' )
			->disableOriginalConstructor()
			->getMock();
		$stripe_gateway->id   = 'stripe';
		$stripe_gateway->type = null; // Not offline.

		// Stripe should be included.
		$is_processor = $method->invoke( $this->gateway_manager, $stripe_gateway );
		$this->assertTrue( $is_processor, 'Real payment gateways like Stripe should be treated as processor gateways' );
	}

	/**
	 * Test is_payment_processor_gateway includes PayPal gateway
	 */
	public function test_paypal_gateway_is_included() {
		$reflection = new ReflectionClass( 'PaySentinel_Gateway_Manager' );
		$method     = $reflection->getMethod( 'is_payment_processor_gateway' );
		$method->setAccessible( true );

		// Create a mock PayPal gateway.
		$paypal_gateway       = $this->getMockBuilder( 'WC_Payment_Gateway' )
			->disableOriginalConstructor()
			->getMock();
		$paypal_gateway->id   = 'paypal';
		$paypal_gateway->type = null; // Not offline.

		// PayPal should be included.
		$is_processor = $method->invoke( $this->gateway_manager, $paypal_gateway );
		$this->assertTrue( $is_processor, 'Real payment gateways like PayPal should be treated as processor gateways' );
	}

	/**
	 * Test is_payment_processor_gateway filters out offline gateways
	 */
	public function test_offline_gateway_is_excluded() {
		$reflection = new ReflectionClass( 'PaySentinel_Gateway_Manager' );
		$method     = $reflection->getMethod( 'is_payment_processor_gateway' );
		$method->setAccessible( true );

		// Create a mock offline gateway.
		$offline_gateway       = $this->getMockBuilder( 'WC_Payment_Gateway' )
			->disableOriginalConstructor()
			->getMock();
		$offline_gateway->id   = 'custom_offline';
		$offline_gateway->type = 'offline';

		// Offline gateway should be excluded.
		$is_processor = $method->invoke( $this->gateway_manager, $offline_gateway );
		$this->assertFalse( $is_processor, 'Offline gateways should not be treated as processor gateways' );
	}

	/**
	 * Test get_active_gateways only returns payment processor gateways
	 */
	public function test_get_active_gateways_filters_non_processors() {
		// This test verifies that get_active_gateways integrates the filtering correctly.
		$active_gateways = $this->gateway_manager->get_active_gateways();

		// Should be an array.
		$this->assertIsArray( $active_gateways );

		// Should not include manual payment methods.
		$this->assertNotContains( 'card', $active_gateways );
		$this->assertNotContains( 'bacs', $active_gateways );
		$this->assertNotContains( 'cheque', $active_gateways );
		$this->assertNotContains( 'cod', $active_gateways );
	}

	/**
	 * Test get_gateway_display_name returns a string
	 */
	public function test_get_gateway_display_name_returns_string() {
		// Test with a common gateway ID - should return a string (even if fallback formatted).
		$name = $this->gateway_manager->get_gateway_display_name( 'test_gateway_id' );
		$this->assertIsString( $name );
	}

	/**
	 * Test get_gateway_display_name returns formatted name for unknown gateways
	 */
	public function test_get_gateway_display_name_formats_unknown_gateways() {
		// For an unknown gateway, it should return a formatted version of the ID.
		$name = $this->gateway_manager->get_gateway_display_name( 'custom_payment_gateway' );
		$this->assertIsString( $name );

		// The formatted name should contain recognizable parts of the original name.
		// (after removing prefixes and converting underscores/hyphens to spaces).
		$this->assertStringContainsString( 'custom', strtolower( $name ) );
	}
}
