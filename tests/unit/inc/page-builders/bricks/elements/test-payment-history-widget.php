<?php
/**
 * Tests for the Bricks Payment History element.
 *
 * @package sureforms
 */

// Stub \Bricks\Element so Payment_History_Widget can be loaded without Bricks Builder.
namespace Bricks {
	if ( ! class_exists( 'Bricks\Element' ) ) {
		class Element { // phpcs:ignore
			public $settings       = [];
			public $id             = null;
			protected $controls       = []; // phpcs:ignore
			protected $control_groups = []; // phpcs:ignore
			protected $scripts        = []; // phpcs:ignore
			public function __construct( $element = null ) {} // phpcs:ignore
		}
	}
}

namespace SRFM\Inc\Page_Builders\Bricks\Elements {

	use Yoast\PHPUnitPolyfills\TestCases\TestCase;

	require_once SRFM_DIR . 'inc/page-builders/bricks/elements/payment-history-widget.php';

	/**
	 * Tests for SRFM\Inc\Page_Builders\Bricks\Elements\Payment_History_Widget.
	 */
	class Test_Bricks_Payment_History_Widget extends TestCase {

		/**
		 * Build an element instance without invoking the constructor.
		 *
		 * @return Payment_History_Widget
		 */
		private function element() {
			return ( new \ReflectionClass( Payment_History_Widget::class ) )->newInstanceWithoutConstructor();
		}

		/**
		 * Element label.
		 */
		public function test_get_label() {
			$this->assertSame( 'Payment History', $this->element()->get_label() );
		}

		/**
		 * Keywords include payment-related terms.
		 */
		public function test_get_keywords() {
			$this->assertContains( 'payment', $this->element()->get_keywords() );
		}

		/**
		 * set_controls() is the public Bricks control hook.
		 */
		public function test_set_controls() {
			$method = new \ReflectionMethod( Payment_History_Widget::class, 'set_controls' );
			$this->assertTrue( $method->isPublic() );
		}

		/**
		 * render() is the public Bricks render hook.
		 */
		public function test_render() {
			$method = new \ReflectionMethod( Payment_History_Widget::class, 'render' );
			$this->assertTrue( $method->isPublic() );
		}
	}
}
