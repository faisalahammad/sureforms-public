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

	/**
	 * Admin user id used to satisfy maybe_schedule()'s privilege gate.
	 *
	 * @var int
	 */
	private $admin_id = 0;

	protected function set_up(): void {
		parent::set_up();
		delete_option( String_Backfill::DONE_OPTION );
		$GLOBALS['srfm_test_as_calls'] = [];
		$this->reset_multilingual_manager_singleton();

		// maybe_schedule() now requires a privileged, non-ajax admin request.
		if ( function_exists( 'wp_set_current_user' ) && function_exists( 'wp_insert_user' ) ) {
			$this->admin_id = (int) wp_insert_user(
				[
					'user_login' => 'srfm_backfill_admin_' . wp_generate_password( 6, false ),
					'user_pass'  => wp_generate_password( 12, false ),
					'role'       => 'administrator',
				]
			);
			if ( $this->admin_id > 0 ) {
				wp_set_current_user( $this->admin_id );
			}
		}
	}

	protected function tear_down(): void {
		if ( null !== $this->filter_callback ) {
			remove_filter( 'srfm_multilingual_provider', $this->filter_callback, 10 );
			$this->filter_callback = null;
		}
		delete_option( String_Backfill::DONE_OPTION );
		delete_option( String_Backfill::LOCK_OPTION );
		$GLOBALS['srfm_test_as_calls'] = [];
		delete_option( String_Backfill::LOCK_OPTION );
		$this->reset_multilingual_manager_singleton();
		if ( $this->admin_id > 0 && function_exists( 'wp_delete_user' ) ) {
			wp_set_current_user( 0 );
			wp_delete_user( $this->admin_id );
			$this->admin_id = 0;
		}
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

	public function test_maybe_schedule_takes_the_lock_but_does_not_mark_done() {
		$this->install_active_provider();

		String_Backfill::get_instance()->maybe_schedule();

		// REGRESSION: DONE_OPTION used to be written here, before anything had run. A failed
		// enqueue or a worker that died before chaining then left the migration looking
		// complete forever, with no replay. Completion is now recorded only when the final
		// page comes back empty; scheduling only takes the run lock.
		$this->assertFalse(
			(bool) get_option( String_Backfill::DONE_OPTION ),
			'Scheduling must not record completion.'
		);

		$lock = get_option( String_Backfill::LOCK_OPTION );
		$this->assertIsArray( $lock, 'Scheduling must take the run lock.' );
		$this->assertSame( String_Backfill::SCHEMA_VERSION, $lock['schema'] ?? '' );
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

	public function test_maybe_schedule_enqueues_a_single_batch_action() {
		$this->install_active_provider();

		$GLOBALS['srfm_test_as_calls'] = [];

		String_Backfill::get_instance()->maybe_schedule();

		if ( ! defined( 'SRFM_TEST_AS_SHIM' ) ) {
			$this->markTestSkipped( 'Real Action Scheduler loaded; shim assertions not applicable.' );
		}

		// REGRESSION: this used to enqueue one action per form with $unique = true.
		// Action Scheduler's uniqueness check matches on (status, hook, group_id) only —
		// NOT on args — so the first form inserted and every later form silently wrote
		// 0 rows, leaving exactly one form backfilled per site. A single paginating batch
		// action removes the dependence on uniqueness entirely.
		$this->assertCount( 1, $GLOBALS['srfm_test_as_calls'], 'maybe_schedule() must enqueue exactly one batch action.' );

		$call = $GLOBALS['srfm_test_as_calls'][0];
		$this->assertSame( String_Backfill::HOOK_BATCH, $call['hook'] );
		$this->assertSame( 'srfm', $call['group'] );
		$this->assertSame( [ 'paged' => 1 ], $call['args'] );
	}

	public function test_backfill_batch_marks_done_only_on_the_final_empty_page() {
		$this->install_active_provider();

		// Pretend a run is in flight, then hand the worker a page far past the end.
		update_option(
			String_Backfill::LOCK_OPTION,
			[
				'schema'  => String_Backfill::SCHEMA_VERSION,
				'started' => time(),
			],
			false
		);

		String_Backfill::get_instance()->backfill_batch( 99999 );

		// An empty page is the only completion signal, and it releases the lock.
		$this->assertSame( String_Backfill::SCHEMA_VERSION, get_option( String_Backfill::DONE_OPTION ) );
		$this->assertFalse( (bool) get_option( String_Backfill::LOCK_OPTION ), 'Completion must release the lock.' );
	}

	public function test_backfill_batch_aborts_without_marking_done_when_provider_inactive() {
		// No provider installed → inactive at worker time, which is the case that used to
		// mark forms and the schema as done while collecting nothing.
		update_option(
			String_Backfill::LOCK_OPTION,
			[
				'schema'  => String_Backfill::SCHEMA_VERSION,
				'started' => time(),
			],
			false
		);

		String_Backfill::get_instance()->backfill_batch( 1 );

		$this->assertFalse(
			(bool) get_option( String_Backfill::DONE_OPTION ),
			'A run that aborts must not record completion.'
		);
		$this->assertFalse(
			(bool) get_option( String_Backfill::LOCK_OPTION ),
			'An aborted run must release the lock so a later admin load can retry.'
		);
	}

	public function test_maybe_schedule_does_not_start_a_second_run_while_one_is_in_flight() {
		$this->install_active_provider();

		update_option(
			String_Backfill::LOCK_OPTION,
			[
				'schema'  => String_Backfill::SCHEMA_VERSION,
				'started' => time(),
			],
			false
		);
		$GLOBALS['srfm_test_as_calls'] = [];

		String_Backfill::get_instance()->maybe_schedule();

		$this->assertSame( [], $GLOBALS['srfm_test_as_calls'], 'A run already in flight must not be duplicated.' );
	}

	public function test_maybe_schedule_reclaims_a_stale_lock() {
		$this->install_active_provider();

		// A worker that died without chaining leaves a lock behind. Past the TTL it must be
		// reclaimed, otherwise the migration stalls permanently.
		update_option(
			String_Backfill::LOCK_OPTION,
			[
				'schema'  => String_Backfill::SCHEMA_VERSION,
				'started' => time() - ( String_Backfill::LOCK_TTL + 60 ),
			],
			false
		);
		$GLOBALS['srfm_test_as_calls'] = [];

		String_Backfill::get_instance()->maybe_schedule();

		if ( ! defined( 'SRFM_TEST_AS_SHIM' ) ) {
			$this->markTestSkipped( 'Real Action Scheduler loaded; shim assertions not applicable.' );
		}

		$this->assertCount( 1, $GLOBALS['srfm_test_as_calls'], 'A stale lock must be reclaimed and the pass restarted.' );
	}

	public function test_maybe_schedule_skips_ajax_requests() {
		$this->install_active_provider();

		// admin_init also fires inside admin-ajax.php, which is reachable
		// unauthenticated — the pass is state-changing and touches every form.
		add_filter( 'wp_doing_ajax', '__return_true' );
		String_Backfill::get_instance()->maybe_schedule();
		remove_filter( 'wp_doing_ajax', '__return_true' );

		$this->assertFalse( (bool) get_option( String_Backfill::DONE_OPTION ), 'An ajax request must not start the backfill.' );
		$this->assertSame( [], $GLOBALS['srfm_test_as_calls'] );
	}

	public function test_maybe_schedule_skips_unprivileged_users() {
		$this->install_active_provider();

		wp_set_current_user( 0 );
		String_Backfill::get_instance()->maybe_schedule();

		$this->assertFalse( (bool) get_option( String_Backfill::DONE_OPTION ), 'A logged-out request must not start the backfill.' );
		$this->assertSame( [], $GLOBALS['srfm_test_as_calls'] );
	}

	public function test_backfill_batch_collects_every_form() {
		if ( ! function_exists( 'wp_insert_post' ) ) {
			$this->markTestSkipped( 'WordPress test bootstrap not available.' );
		}

		$stub = $this->install_active_provider();

		$form_ids = [];
		foreach ( [ 'One', 'Two', 'Three' ] as $suffix ) {
			$id = wp_insert_post(
				[
					'post_type'   => 'sureforms_form',
					'post_status' => 'publish',
					'post_title'  => 'Batch Form ' . $suffix,
				]
			);
			if ( is_wp_error( $id ) || 0 === (int) $id ) {
				$this->markTestSkipped( 'Could not create form posts.' );
			}
			$form_ids[] = (int) $id;
		}

		// Isolate from the save_post→collect() that wp_insert_post already fired.
		$stub->registered = [];

		String_Backfill::get_instance()->backfill_batch( 1 );

		// THE assertion adi3890 asked for: N forms must produce N backfills. This is
		// what the previous per-form-enqueue test could not catch, because the recording
		// shim ignores $unique and therefore recorded enqueues that real Action Scheduler
		// would have discarded.
		foreach ( $form_ids as $id ) {
			$this->assertContains(
				'form_' . $id . '_form_title',
				$stub->registered,
				"Form {$id} should have been collected by the batch."
			);
		}

		foreach ( $form_ids as $id ) {
			wp_delete_post( $id, true );
		}
	}

	public function test_backfill_one_is_idempotent_per_schema_version() {
		if ( ! function_exists( 'wp_insert_post' ) ) {
			$this->markTestSkipped( 'WordPress test bootstrap not available.' );
		}

		$stub = $this->install_active_provider();

		$form_id = (int) wp_insert_post(
			[
				'post_type'   => 'sureforms_form',
				'post_status' => 'publish',
				'post_title'  => 'Idempotent Form',
			]
		);
		if ( 0 === $form_id ) {
			$this->markTestSkipped( 'Could not create a form post.' );
		}

		String_Backfill::get_instance()->backfill_one( $form_id );
		$this->assertSame( String_Backfill::SCHEMA_VERSION, get_post_meta( $form_id, String_Backfill::FORM_MARKER, true ) );

		// A replayed batch must not re-register packages it already handled.
		$stub->registered = [];
		String_Backfill::get_instance()->backfill_one( $form_id );
		$this->assertSame( [], $stub->registered, 'A already-backfilled form must be skipped on replay.' );

		wp_delete_post( $form_id, true );
	}
}
