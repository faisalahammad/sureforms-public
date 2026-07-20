<?php
/**
 * Class Test_Elementor_Payment_History_Widget
 *
 * Tests for the Elementor Payment History widget.
 *
 * @package sureforms
 */

// Load Elementor stubs so Payment_History_Widget can extend Widget_Base.
require_once SRFM_DIR . 'tests/php/stubs/srfm-elementor-stubs.php';
require_once SRFM_DIR . 'inc/page-builders/elementor/payment-history-widget.php';

use Yoast\PHPUnitPolyfills\TestCases\TestCase;
use SRFM\Inc\Page_Builders\Elementor\Payment_History_Widget;

/**
 * Tests for SRFM\Inc\Page_Builders\Elementor\Payment_History_Widget.
 */
class Test_Elementor_Payment_History_Widget extends TestCase {

	/**
	 * Build a widget instance without invoking the Elementor constructor.
	 *
	 * @return Payment_History_Widget
	 */
	private function widget() {
		return ( new \ReflectionClass( Payment_History_Widget::class ) )->newInstanceWithoutConstructor();
	}

	/**
	 * Widget name matches the block/widget identifier.
	 */
	public function test_get_name() {
		$this->assertSame( 'srfm-payment-history', $this->widget()->get_name() );
	}

	/**
	 * Widget title.
	 */
	public function test_get_title() {
		$this->assertSame( 'Payment History', $this->widget()->get_title() );
	}

	/**
	 * Widget icon is an Elementor icon.
	 */
	public function test_get_icon() {
		$this->assertStringContainsString( 'eicon', $this->widget()->get_icon() );
	}

	/**
	 * Widget lives in the SureForms Elementor category.
	 */
	public function test_get_categories() {
		$this->assertContains( 'sureforms-elementor', $this->widget()->get_categories() );
	}

	/**
	 * Keywords include payment-related terms.
	 */
	public function test_get_keywords() {
		$this->assertContains( 'payment', $this->widget()->get_keywords() );
	}

	/**
	 * register_controls() is the protected Elementor control hook.
	 */
	public function test_register_controls() {
		$method = new \ReflectionMethod( Payment_History_Widget::class, 'register_controls' );
		$this->assertTrue( $method->isProtected() );
	}

	/**
	 * render() is the protected Elementor render hook.
	 */
	public function test_render() {
		$method = new \ReflectionMethod( Payment_History_Widget::class, 'render' );
		$this->assertTrue( $method->isProtected() );
	}
}
