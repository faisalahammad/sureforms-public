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
}
