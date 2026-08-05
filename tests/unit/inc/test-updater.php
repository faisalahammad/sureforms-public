<?php
/**
 * Class Test_Updater
 *
 * @package sureforms
 */

use Yoast\PHPUnitPolyfills\TestCases\TestCase;
use SRFM\Inc\Updater;

class Test_Updater extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		if ( ! class_exists( 'SRFM\Inc\Updater' ) ) {
			$this->markTestSkipped( 'Updater class not available.' );
		}
	}

	/**
	 * The updater-callback map must be version-keyed arrays of callables, and must
	 * include the 2.12.3 entry that clears Elementor's cached page assets so pre-existing
	 * pages pick up the Payment History stylesheet dependency after upgrade.
	 */
	public function test_get_updater_callbacks() {
		$callbacks = Updater::get_instance()->get_updater_callbacks();

		$this->assertIsArray( $callbacks );
		$this->assertArrayHasKey( '2.12.3', $callbacks );
		$this->assertContains(
			'SRFM\Inc\Updater_Callbacks::clear_elementor_page_assets_cache',
			$callbacks['2.12.3'],
			'The 2.12.3 upgrade must clear Elementor page-assets cache.'
		);

		// Every entry is a version key mapping to an array of callables.
		foreach ( $callbacks as $version => $fns ) {
			$this->assertIsString( $version );
			$this->assertIsArray( $fns );
			foreach ( $fns as $fn ) {
				$this->assertIsCallable( $fn, "Updater callback for {$version} must be callable." );
			}
		}
	}
}
