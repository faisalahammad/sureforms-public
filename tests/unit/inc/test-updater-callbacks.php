<?php
/**
 * Class Test_Updater_Callbacks
 *
 * @package sureforms
 */

use Yoast\PHPUnitPolyfills\TestCases\TestCase;
use SRFM\Inc\Updater_Callbacks;

class Test_Updater_Callbacks extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		if ( ! class_exists( 'SRFM\Inc\Updater_Callbacks' ) ) {
			$this->markTestSkipped( 'Updater_Callbacks class not available.' );
		}
	}

	/**
	 * manage_empty_global_dynamic_options() must always leave a populated dynamic-block
	 * options array — seeding the defaults when the option is absent, and preserving
	 * existing values (only filling missing keys) when it already exists.
	 */
	public function test_manage_empty_global_dynamic_options() {
		// Absent option → defaults are seeded.
		delete_option( 'srfm_default_dynamic_block_option' );
		Updater_Callbacks::manage_empty_global_dynamic_options();
		$seeded = get_option( 'srfm_default_dynamic_block_option' );
		$this->assertIsArray( $seeded );
		$this->assertNotEmpty( $seeded, 'Defaults must be seeded when the option is absent.' );

		// Existing option → a custom value is preserved (missing keys only are filled).
		update_option( 'srfm_default_dynamic_block_option', [ 'srfm_custom_key' => 'keep-me' ] );
		Updater_Callbacks::manage_empty_global_dynamic_options();
		$merged = get_option( 'srfm_default_dynamic_block_option' );
		$this->assertIsArray( $merged );
		$this->assertSame( 'keep-me', $merged['srfm_custom_key'], 'Existing values must be preserved.' );

		delete_option( 'srfm_default_dynamic_block_option' );
	}

	/**
	 * When Elementor is not active the callback must be a safe no-op — no fatal from
	 * touching Elementor APIs, and it must not error when there is nothing to clear.
	 */
	public function test_clear_elementor_page_assets_cache_noops_without_elementor() {
		if ( class_exists( '\Elementor\Plugin' ) ) {
			$this->markTestSkipped( 'Elementor is active; no-op-without-Elementor path is not exercised.' );
		}

		// Should simply return without error.
		Updater_Callbacks::clear_elementor_page_assets_cache();
		$this->assertFalse( class_exists( '\Elementor\Plugin' ) );
	}

	/**
	 * When Elementor is active the callback must drop every `_elementor_page_assets`
	 * meta row so Elementor regenerates it (with the new stylesheet dependency) on the
	 * next render.
	 */
	public function test_clear_elementor_page_assets_cache_clears_meta() {
		if ( ! class_exists( '\Elementor\Plugin' ) ) {
			$this->markTestSkipped( 'Elementor is not active in this test environment.' );
		}

		$post_id = wp_insert_post(
			[
				'post_title'  => 'Elementor Assets Page',
				'post_status' => 'publish',
			]
		);
		update_post_meta( $post_id, '_elementor_page_assets', [ 'styles' => [ 'x' ] ] );
		$this->assertNotEmpty( get_post_meta( $post_id, '_elementor_page_assets', true ) );

		Updater_Callbacks::clear_elementor_page_assets_cache();

		$this->assertEmpty(
			get_post_meta( $post_id, '_elementor_page_assets', true ),
			'_elementor_page_assets must be cleared so Elementor regenerates it.'
		);

		wp_delete_post( $post_id, true );
	}
}
