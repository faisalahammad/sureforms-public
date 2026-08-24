<?php
/**
 * Class Test_Form_Views
 *
 * @package sureforms
 */

use Yoast\PHPUnitPolyfills\TestCases\TestCase;
use SRFM\Inc\Form_Views;
use SRFM\Inc\Submit_Token;

class Test_Form_Views extends TestCase {

	protected $views;

	protected function setUp(): void {
		parent::setUp();

		if ( ! class_exists( 'SRFM\Inc\Form_Views' ) ) {
			$this->markTestSkipped( 'Form_Views class not available.' );
		}

		$this->views            = Form_Views::get_instance();
		$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
		unset( $_GET['live_mode'] );
		wp_set_current_user( 0 );

		// Counting is opt-in and gated on this stamp, so an open window is the
		// precondition for every test about counting. The tests that assert the
		// never-enabled state delete it themselves first.
		update_option( Form_Views::TRACKING_STARTED_OPTION, time() - HOUR_IN_SECONDS );
	}

	protected function tearDown(): void {
		wp_set_current_user( 0 );
		delete_option( Form_Views::TRACKING_STARTED_OPTION );
		delete_option( 'srfm_general_settings_options' );
		parent::tearDown();
	}

	/**
	 * Helper to call private methods.
	 */
	private function call_private_method( $object, $method_name, $parameters = [] ) {
		$reflection = new \ReflectionClass( get_class( $object ) );
		$method     = $reflection->getMethod( $method_name );
		$method->setAccessible( true );
		return $method->invokeArgs( $object, $parameters );
	}

	private function make_form() {
		return wp_insert_post(
			[
				'post_type'   => SRFM_FORMS_POST_TYPE,
				'post_title'  => 'Views Test Form',
				'post_status' => 'publish',
			]
		);
	}

	// ──────────────────────────────────────────────
	// get_views / increment_views
	// ──────────────────────────────────────────────

	public function test_get_views_defaults_to_zero() {
		$form_id = $this->make_form();
		$this->assertSame( 0, $this->views->get_views( $form_id ) );
		wp_delete_post( $form_id, true );
	}

	public function test_increment_views_creates_then_increments() {
		$form_id = $this->make_form();

		$this->call_private_method( $this->views, 'increment_views', [ $form_id ] );
		$this->assertSame( 1, $this->views->get_views( $form_id ), 'First increment should create the meta at 1.' );

		$this->call_private_method( $this->views, 'increment_views', [ $form_id ] );
		$this->assertSame( 2, $this->views->get_views( $form_id ), 'Second increment should bump to 2.' );

		wp_delete_post( $form_id, true );
	}

	// ──────────────────────────────────────────────
	// should_track (exclusions)
	// ──────────────────────────────────────────────

	public function test_should_track_true_for_anonymous_visitor() {
		wp_set_current_user( 0 );
		$this->assertTrue( $this->call_private_method( $this->views, 'should_track' ) );
	}

	public function test_should_track_excludes_privileged_user() {
		$editor = wp_insert_user(
			[
				'user_login' => 'srfm_views_editor_' . wp_rand(),
				'user_pass'  => 'password',
				'user_email' => 'srfm_views_editor_' . wp_rand() . '@example.com',
				'role'       => 'editor',
			]
		);
		wp_set_current_user( $editor );

		$this->assertFalse( $this->call_private_method( $this->views, 'should_track' ) );

		wp_set_current_user( 0 );
		wp_delete_user( $editor );
	}

	// ──────────────────────────────────────────────
	// is_tracking_enabled (global General-settings toggle)
	// ──────────────────────────────────────────────

	public function test_is_tracking_enabled() {
		// Opt-in: absent option, absent key and a corrupted non-array value all deny.
		delete_option( 'srfm_general_settings_options' );
		$this->assertFalse( $this->views->is_tracking_enabled(), 'Absent option must default to disabled.' );

		update_option( 'srfm_general_settings_options', [ 'srfm_ip_log' => true ] );
		$this->assertFalse( $this->views->is_tracking_enabled(), 'Absent key must default to disabled.' );

		update_option( 'srfm_general_settings_options', 'not-an-array' );
		$this->assertFalse( $this->views->is_tracking_enabled(), 'A corrupted value must deny, not fatal.' );

		update_option( 'srfm_general_settings_options', [ 'srfm_form_views_tracking' => false ] );
		$this->assertFalse( $this->views->is_tracking_enabled(), 'Explicit false should hide the columns.' );

		update_option( 'srfm_general_settings_options', [ 'srfm_form_views_tracking' => true ] );
		$this->assertTrue( $this->views->is_tracking_enabled(), 'Explicit true should show the columns.' );

		delete_option( 'srfm_general_settings_options' );
	}

	/**
	 * Counting is gated on the tracking-started stamp, not on the display toggle.
	 *
	 * Before the feature has ever been switched on, nothing is counted — a hidden
	 * column that was quietly accumulating data was never really "off by default".
	 * Once the window is open, hiding the columns again only hides them, so
	 * switching back on reveals the period rather than a gap.
	 */
	public function test_should_track_follows_the_tracking_window() {
		wp_set_current_user( 0 );
		delete_option( 'srfm_general_settings_options' );

		// Never enabled → no stamp → nothing counted.
		delete_option( Form_Views::TRACKING_STARTED_OPTION );
		$this->assertFalse( $this->call_private_method( $this->views, 'should_track' ), 'No stamp means counting has not started.' );

		// Enabling writes the stamp, and counting begins.
		update_option( 'srfm_general_settings_options', [ 'srfm_form_views_tracking' => true ] );
		$this->assertGreaterThan( 0, $this->views->get_tracking_started_at(), 'Enabling should open the window.' );
		$this->assertTrue( $this->call_private_method( $this->views, 'should_track' ), 'Counting should run once the window is open.' );

		// Disabling hides the columns but must NOT stop counting.
		update_option( 'srfm_general_settings_options', [ 'srfm_form_views_tracking' => false ] );
		$this->assertFalse( $this->views->is_tracking_enabled(), 'The columns should be hidden.' );
		$this->assertTrue( $this->call_private_method( $this->views, 'should_track' ), 'Counting must continue while the columns are hidden.' );

		delete_option( 'srfm_general_settings_options' );
		delete_option( Form_Views::TRACKING_STARTED_OPTION );
	}

	// ──────────────────────────────────────────────
	// get_tracking_started_at (conversion-rate window)
	// ──────────────────────────────────────────────

	public function test_get_tracking_started_at() {
		delete_option( 'srfm_general_settings_options' );
		delete_option( Form_Views::TRACKING_STARTED_OPTION );

		// A pure read: asking must never open the window, or a site that never
		// enabled the feature would start a window just by rendering the list.
		$this->assertSame( 0, $this->views->get_tracking_started_at(), 'Never enabled must read as 0.' );
		$this->assertFalse( get_option( Form_Views::TRACKING_STARTED_OPTION ), 'Reading must not write the stamp.' );

		$before = time();
		update_option( 'srfm_general_settings_options', [ 'srfm_form_views_tracking' => true ] );
		$after   = time();
		$started = $this->views->get_tracking_started_at();

		$this->assertGreaterThanOrEqual( $before, $started, 'Enabling should stamp the current time.' );
		$this->assertLessThanOrEqual( $after, $started, 'Enabling should stamp the current time.' );

		// Write-once. An off/on cycle must not move the window, or the stored view
		// counts would be measured against an entry window shorter than they cover.
		//
		// Backdated deliberately: comparing against the stamp written a moment ago
		// would pass even with update_option(), because both writes land in the same
		// second. A known-old value is what actually distinguishes the two.
		update_option( Form_Views::TRACKING_STARTED_OPTION, 1000000000 );
		update_option( 'srfm_general_settings_options', [ 'srfm_form_views_tracking' => false ] );
		update_option( 'srfm_general_settings_options', [ 'srfm_form_views_tracking' => true ] );
		$this->assertSame( 1000000000, $this->views->get_tracking_started_at(), 'Re-enabling must not re-stamp an open window.' );

		// Not autoloaded — this is read on the Forms list screen and by the beacon.
		global $wpdb;
		$autoload = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Core table name from $wpdb.
				Form_Views::TRACKING_STARTED_OPTION
			)
		);
		// WP 6.6+ stores 'off' for an explicit false; older cores store 'no'.
		$this->assertContains( $autoload, [ 'no', 'off' ], 'Stamp must not be autoloaded on every request.' );

		// Saving with the toggle off must never open a window.
		delete_option( Form_Views::TRACKING_STARTED_OPTION );
		update_option( 'srfm_general_settings_options', [ 'srfm_form_views_tracking' => false ] );
		$this->assertSame( 0, $this->views->get_tracking_started_at(), 'Saving while off must not open the window.' );

		delete_option( 'srfm_general_settings_options' );
		delete_option( Form_Views::TRACKING_STARTED_OPTION );
	}

	// ──────────────────────────────────────────────
	// permissions_check (HMAC token)
	// ──────────────────────────────────────────────

	public function test_permissions_check_rejects_missing_token() {
		$form_id = $this->make_form();
		$request = new WP_REST_Request( 'POST', '/sureforms/v1/forms/track-view' );
		$request->set_param( 'form_id', $form_id );

		$result = $this->views->permissions_check( $request );
		$this->assertInstanceOf( 'WP_Error', $result );

		wp_delete_post( $form_id, true );
	}

	public function test_permissions_check_accepts_valid_token() {
		$form_id = $this->make_form();
		$token   = Submit_Token::generate( (int) $form_id );

		$request = new WP_REST_Request( 'POST', '/sureforms/v1/forms/track-view' );
		$request->set_param( 'form_id', $form_id );
		$request->set_header( 'X-WP-Submit-Token', $token );

		$this->assertTrue( $this->views->permissions_check( $request ) );

		wp_delete_post( $form_id, true );
	}

	// ──────────────────────────────────────────────
	// track_view (end to end)
	// ──────────────────────────────────────────────

	public function test_track_view_counts_for_anonymous_visitor() {
		$form_id = $this->make_form();
		wp_set_current_user( 0 );

		$request = new WP_REST_Request( 'POST', '/sureforms/v1/forms/track-view' );
		$request->set_param( 'form_id', $form_id );

		$response = $this->views->track_view( $request );
		$this->assertInstanceOf( 'WP_REST_Response', $response );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 1, $this->views->get_views( $form_id ) );

		wp_delete_post( $form_id, true );
	}

	public function test_track_view_does_not_count_privileged_user() {
		$form_id = $this->make_form();
		$admin   = wp_insert_user(
			[
				'user_login' => 'srfm_views_admin_' . wp_rand(),
				'user_pass'  => 'password',
				'user_email' => 'srfm_views_admin_' . wp_rand() . '@example.com',
				'role'       => 'administrator',
			]
		);
		wp_set_current_user( $admin );

		$request = new WP_REST_Request( 'POST', '/sureforms/v1/forms/track-view' );
		$request->set_param( 'form_id', $form_id );

		$response = $this->views->track_view( $request );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 0, $this->views->get_views( $form_id ), 'Privileged users must not be counted.' );

		wp_set_current_user( 0 );
		wp_delete_user( $admin );
		wp_delete_post( $form_id, true );
	}

	// ──────────────────────────────────────────────
	// register_route / localize_beacon
	// ──────────────────────────────────────────────

	/**
	 * register_route adds the public forms/track-view POST route to the endpoints array.
	 */
	public function test_register_route() {
		$endpoints = $this->views->register_route( [] );

		$this->assertIsArray( $endpoints );
		$this->assertArrayHasKey( 'forms/track-view', $endpoints );
		$this->assertSame( 'POST', $endpoints['forms/track-view']['methods'] );
		$this->assertArrayHasKey( 'callback', $endpoints['forms/track-view'] );
		$this->assertArrayHasKey( 'permission_callback', $endpoints['forms/track-view'] );
		$this->assertArrayHasKey( 'form_id', $endpoints['forms/track-view']['args'] );

		// Non-array input is returned unchanged (defensive guard).
		$this->assertSame( 'unchanged', $this->views->register_route( 'unchanged' ) );
	}

	/**
	 * localize_beacon is a safe no-op when the form-submit script is not enqueued,
	 * and localizes the beacon flag onto it when it is.
	 */
	public function test_localize_beacon() {
		// Not enqueued → early return, nothing localized, no error.
		wp_dequeue_script( 'srfm-form-submit' );
		$this->views->localize_beacon();
		$this->assertFalse( wp_script_is( 'srfm-form-submit', 'enqueued' ) );

		// Enqueued → beacon data is attached to the script.
		wp_register_script( 'srfm-form-submit', '', [], '1.0.0', true );
		wp_enqueue_script( 'srfm-form-submit' );
		$this->views->localize_beacon();

		$data = wp_scripts()->get_data( 'srfm-form-submit', 'data' );
		$this->assertIsString( $data );
		$this->assertStringContainsString( 'srfm_view_beacon', $data );

		wp_dequeue_script( 'srfm-form-submit' );
		wp_deregister_script( 'srfm-form-submit' );
	}
}
