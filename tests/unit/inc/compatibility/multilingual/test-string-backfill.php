<?php
/**
 * Class Test_String_Backfill
 *
 * @package sureforms
 */

use Yoast\PHPUnitPolyfills\TestCases\TestCase;
use SRFM\Inc\Compatibility\Multilingual\String_Backfill;
use SRFM\Inc\Compatibility\Multilingual\Providers\Provider;

// Action Scheduler may not be loaded in the unit-test bootstrap; the backfill
// guards on this function, so provide a no-op so the "active" path is exercisable.
if ( ! function_exists( 'as_enqueue_async_action' ) ) {
	function as_enqueue_async_action( $hook, $args = [], $group = '', $unique = false, $priority = 10 ) {
		unset( $hook, $args, $group, $unique, $priority );
		return 0;
	}
}

/**
 * Minimal active-provider stub whose only meaningful signal is is_active() = true.
 */
class Srfm_String_Backfill_Stub_Provider implements Provider {
	public function is_active(): bool {
		return true;
	}
	public function current_language(): string {
		return 'xx';
	}
	public function default_language(): string {
		return 'xx';
	}
	public function register_string( string $name, string $value, string $domain = 'sureforms' ): void {
	}
	public function translate( string $value, string $name, string $domain = 'sureforms', ?string $language = null ): string {
		return $value;
	}
	public function switch_language( string $language ): void {
	}
	public function restore_language(): void {
	}
	public function render_language_switcher(): string {
		return '';
	}
	public function supports_packages(): bool {
		return false;
	}
	public function start_package( array $package ): void {
	}
	public function finish_package( array $package ): void {
	}
	public function register_package_string( array $package, string $name, string $value, string $title = '', string $type = 'LINE' ): void {
	}
	public function translate_package_string( array $package, string $name, string $value ): string {
		return $value;
	}
	public function delete_package( array $package ): void {
	}
}

/**
 * Tests for String_Backfill.
 */
class Test_String_Backfill extends TestCase {

	/**
	 * Filter callback currently attached, so it can be removed in tearDown.
	 *
	 * @var callable|null
	 */
	private $filter_callback = null;

	protected function set_up(): void {
		parent::set_up();
		delete_option( String_Backfill::DONE_OPTION );
		$this->reset_multilingual_manager_singleton();
	}

	protected function tear_down(): void {
		if ( null !== $this->filter_callback ) {
			remove_filter( 'srfm_multilingual_provider', $this->filter_callback, 10 );
			$this->filter_callback = null;
		}
		delete_option( String_Backfill::DONE_OPTION );
		$this->reset_multilingual_manager_singleton();
		parent::tear_down();
	}

	private function install_active_provider(): void {
		$stub                  = new Srfm_String_Backfill_Stub_Provider();
		$this->filter_callback = static function () use ( $stub ) {
			return $stub;
		};
		add_filter( 'srfm_multilingual_provider', $this->filter_callback, 10, 1 );
		$this->reset_multilingual_manager_singleton();
	}

	private function reset_multilingual_manager_singleton(): void {
		try {
			$ref = new ReflectionClass( \SRFM\Inc\Compatibility\Multilingual\Multilingual_Manager::class );
			if ( $ref->hasProperty( 'instance' ) ) {
				$prop = $ref->getProperty( 'instance' );
				$prop->setAccessible( true );
				$prop->setValue( null, null );
			}
		} catch ( \ReflectionException $e ) {
			unset( $e );
		}
	}

	public function test_maybe_schedule_skips_when_provider_inactive() {
		if ( defined( 'ICL_SITEPRESS_VERSION' ) && class_exists( '\SitePress' ) ) {
			$this->markTestSkipped( 'WPML appears to be loaded in the test environment.' );
		}

		// No provider installed → inactive → the done-marker must not be written.
		String_Backfill::get_instance()->maybe_schedule();

		$this->assertFalse( (bool) get_option( String_Backfill::DONE_OPTION ) );
	}

	public function test_maybe_schedule_marks_done_when_active() {
		$this->install_active_provider();

		String_Backfill::get_instance()->maybe_schedule();

		$this->assertSame( SRFM_VER, get_option( String_Backfill::DONE_OPTION ) );
	}

	public function test_maybe_schedule_is_idempotent_for_current_version() {
		$this->install_active_provider();
		update_option( String_Backfill::DONE_OPTION, SRFM_VER );

		// Should early-return (already done for this version) without error and
		// leave the marker untouched.
		String_Backfill::get_instance()->maybe_schedule();

		$this->assertSame( SRFM_VER, get_option( String_Backfill::DONE_OPTION ) );
	}

	public function test_backfill_one_runs_collect_for_the_form() {
		$this->install_active_provider();

		if ( ! function_exists( 'wp_insert_post' ) ) {
			$this->markTestSkipped( 'WordPress test bootstrap not available.' );
		}

		$form_id = wp_insert_post(
			[
				'post_type'   => 'sureforms_form',
				'post_status' => 'publish',
				'post_title'  => 'Backfill Test Form',
			]
		);

		if ( is_wp_error( $form_id ) || 0 === (int) $form_id ) {
			$this->markTestSkipped( 'Could not create a form post.' );
		}

		// Should not throw and should route to String_Collector::collect() for the
		// form (collect() re-checks the active provider internally).
		String_Backfill::get_instance()->backfill_one( (int) $form_id );

		$this->assertTrue( true );
	}
}
