<?php
/**
 * Class Test_Bricks_Service_Provider
 *
 * @package sureforms
 */

use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Tests for the Bricks Service_Provider class.
 *
 * These tests require the Bricks builder to be active. They are skipped
 * when Bricks is not loaded.
 */
class Test_Bricks_Service_Provider extends TestCase {

	/**
	 * Skip all tests if Bricks is not available.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		if ( ! class_exists( '\Bricks\Elements' ) ) {
			$this->markTestSkipped( 'Bricks is not available.' );
		}
	}

	/**
	 * Test widget() registers the SureForms Bricks elements without error.
	 */
	public function test_widget() {
		$provider = new \SRFM\Inc\Page_Builders\Bricks\Service_Provider();
		$provider->widget();
		$this->assertInstanceOf( \SRFM\Inc\Page_Builders\Bricks\Service_Provider::class, $provider );
	}

	/**
	 * Test maybe_enqueue_payment_history_assets() is safe to call — it head-loads the
	 * Payment History stylesheet only when a Bricks Payment History element is detected
	 * in the page's template data, and no-ops otherwise. With no such element present it
	 * must not enqueue the handle.
	 */
	public function test_maybe_enqueue_payment_history_assets() {
		wp_dequeue_style( 'srfm-payment-history' );

		$provider = new \SRFM\Inc\Page_Builders\Bricks\Service_Provider();
		$provider->maybe_enqueue_payment_history_assets();

		$this->assertFalse(
			wp_style_is( 'srfm-payment-history', 'enqueued' ),
			'Stylesheet must not be enqueued when no Payment History element is present.'
		);
	}
}
