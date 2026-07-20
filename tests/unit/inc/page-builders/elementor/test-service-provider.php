<?php
/**
 * Class Test_Elementor_Service_Provider
 *
 * @package sureforms
 */

use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Tests for the Elementor Service_Provider class.
 *
 * These tests require Elementor to be active. They are skipped
 * when the Elementor plugin is not loaded.
 */
class Test_Elementor_Service_Provider extends TestCase {

	/**
	 * Skip all tests if Elementor is not available.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		if ( ! class_exists( '\Elementor\Plugin' ) ) {
			$this->markTestSkipped( 'Elementor is not available.' );
		}
	}

	/**
	 * Test load_scripts enqueues editor assets.
	 */
	public function test_load_scripts() {
		$provider = new \SRFM\Inc\Page_Builders\Elementor\Service_Provider();
		$provider->load_scripts();
		$this->assertTrue( wp_script_is( 'sureforms-elementor-editor', 'enqueued' ) );
		wp_dequeue_script( 'sureforms-elementor-editor' );
	}

	/**
	 * Test widget() registers the SureForms widgets, including Payment History.
	 */
	public function test_widget() {
		$manager = new class() {
			/**
			 * Registered widget class names.
			 *
			 * @var array<string>
			 */
			public $registered = [];

			/**
			 * Mock Elementor widget registration.
			 *
			 * @param object $widget Widget instance.
			 * @return void
			 */
			public function register( $widget ) {
				$this->registered[] = get_class( $widget );
			}
		};

		( new \SRFM\Inc\Page_Builders\Elementor\Service_Provider() )->widget( $manager );

		$this->assertContains( \SRFM\Inc\Page_Builders\Elementor\Payment_History_Widget::class, $manager->registered );
	}
}
