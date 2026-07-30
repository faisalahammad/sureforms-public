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
// guards on this function, so provide a recording shim so both the "active" path
// and the per-form enqueue behaviour are observable. Calls land in
// $GLOBALS['srfm_test_as_calls']; SRFM_TEST_AS_SHIM marks the shim as ours (so a
// test can skip when the real Action Scheduler is loaded and this shim isn't).
if ( ! function_exists( 'as_enqueue_async_action' ) ) {
	define( 'SRFM_TEST_AS_SHIM', true );
	function as_enqueue_async_action( $hook, $args = [], $group = '', $unique = false, $priority = 10 ) {
		unset( $priority );
		if ( ! isset( $GLOBALS['srfm_test_as_calls'] ) || ! is_array( $GLOBALS['srfm_test_as_calls'] ) ) {
			$GLOBALS['srfm_test_as_calls'] = [];
		}
		$GLOBALS['srfm_test_as_calls'][] = [
			'hook'   => $hook,
			'args'   => $args,
			'group'  => $group,
			'unique' => $unique,
		];
		return count( $GLOBALS['srfm_test_as_calls'] );
	}
}

/**
 * Active-provider stub that records register_string() calls so the backfill's
 * routing into String_Collector::collect() can be asserted. supports_packages()
 * is false, so collect() takes the flat `form_{id}_{name}` fallback path.
 */
class Srfm_String_Backfill_Stub_Provider implements Provider {
	/**
	 * Recorded register_string() names.
	 *
	 * @var array<int, string>
	 */
	public $registered = [];

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
		$this->registered[] = $name;
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
		$GLOBALS['srfm_test_as_calls'] = [];
		$this->reset_multilingual_manager_singleton();
	}

	protected function tear_down(): void {
		if ( null !== $this->filter_callback ) {
			remove_filter( 'srfm_multilingual_provider', $this->filter_callback, 10 );
			$this->filter_callback = null;
		}
		delete_option( String_Backfill::DONE_OPTION );
		$GLOBALS['srfm_test_as_calls'] = [];
		$this->reset_multilingual_manager_singleton();
		parent::tear_down();
	}

	private function install_active_provider(): Srfm_String_Backfill_Stub_Provider {
		$stub                  = new Srfm_String_Backfill_Stub_Provider();
		$this->filter_callback = static function () use ( $stub ) {
			return $stub;
		};
		add_filter( 'srfm_multilingual_provider', $this->filter_callback, 10, 1 );
		$this->reset_multilingual_manager_singleton();

		return $stub;
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

	public function test_maybe_schedule_marks_done_with_schema_version() {
		$this->install_active_provider();

		String_Backfill::get_instance()->maybe_schedule();

		// Marker is the schema version, NOT SRFM_VER, so ordinary releases don't re-run.
		$this->assertSame( String_Backfill::SCHEMA_VERSION, get_option( String_Backfill::DONE_OPTION ) );
	}

	public function test_maybe_schedule_is_idempotent_for_current_schema_version() {
		$this->install_active_provider();
		update_option( String_Backfill::DONE_OPTION, String_Backfill::SCHEMA_VERSION );

		String_Backfill::get_instance()->maybe_schedule();

		// Already done for this schema version → no enqueues, marker untouched.
		$this->assertSame( String_Backfill::SCHEMA_VERSION, get_option( String_Backfill::DONE_OPTION ) );
		$this->assertSame( [], $GLOBALS['srfm_test_as_calls'], 'A completed schema version must not re-enqueue.' );
	}

	public function test_backfill_one_runs_collect_for_the_form() {
		$stub = $this->install_active_provider();

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

		// Isolate from the save_post→collect() that wp_insert_post already fired.
		$stub->registered = [];

		String_Backfill::get_instance()->backfill_one( (int) $form_id );

		// backfill_one() must route into String_Collector::collect(), which
		// registers the form's strings — the title always registers for a titled form.
		$this->assertContains( 'form_' . (int) $form_id . '_form_title', $stub->registered, 'backfill_one() should collect the form\'s strings.' );

		wp_delete_post( (int) $form_id, true );
	}

	public function test_maybe_schedule_enqueues_one_job_per_form() {
		if ( ! function_exists( 'wp_insert_post' ) ) {
			$this->markTestSkipped( 'WordPress test bootstrap not available.' );
		}

		$this->install_active_provider();

		$form_ids = [];
		foreach ( [ 'One', 'Two', 'Three' ] as $suffix ) {
			$id = wp_insert_post(
				[
					'post_type'   => 'sureforms_form',
					'post_status' => 'publish',
					'post_title'  => 'Enqueue Form ' . $suffix,
				]
			);
			if ( is_wp_error( $id ) || 0 === (int) $id ) {
				$this->markTestSkipped( 'Could not create form posts.' );
			}
			$form_ids[] = (int) $id;
		}

		// Reset so only the maybe_schedule() enqueues are considered.
		$GLOBALS['srfm_test_as_calls'] = [];

		String_Backfill::get_instance()->maybe_schedule();

		// Prefer Action Scheduler's own API (works in CI where real AS is loaded);
		// fall back to the recording shim when AS isn't present.
		if ( function_exists( 'as_has_scheduled_action' ) ) {
			foreach ( $form_ids as $id ) {
				$this->assertTrue(
					as_has_scheduled_action( String_Backfill::HOOK, [ 'form_id' => $id ], 'srfm' ),
					"Form {$id} should have a backfill action scheduled."
				);
			}
		} elseif ( defined( 'SRFM_TEST_AS_SHIM' ) ) {
			$enqueued_ids = [];
			foreach ( $GLOBALS['srfm_test_as_calls'] as $call ) {
				$this->assertSame( String_Backfill::HOOK, $call['hook'] );
				$this->assertSame( 'srfm', $call['group'] );
				$this->assertTrue( $call['unique'], 'Enqueue must pass the unique flag to dedupe re-queues.' );
				$this->assertArrayHasKey( 'form_id', $call['args'] );
				$this->assertIsInt( $call['args']['form_id'] );
				$enqueued_ids[] = $call['args']['form_id'];
			}
			foreach ( $form_ids as $id ) {
				$this->assertContains( $id, $enqueued_ids, "Form {$id} should be enqueued." );
				$this->assertSame( 1, count( array_keys( $enqueued_ids, $id, true ) ), "Form {$id} enqueued more than once." );
			}
		} else {
			$this->markTestSkipped( 'Action Scheduler not available and no recording shim installed.' );
		}

		foreach ( $form_ids as $id ) {
			wp_delete_post( $id, true );
		}
	}
}
