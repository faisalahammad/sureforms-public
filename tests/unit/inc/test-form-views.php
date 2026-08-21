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

		$this->views                = Form_Views::get_instance();
		$_SERVER['REMOTE_ADDR']     = '127.0.0.1';
		unset( $_GET['live_mode'] );
		wp_set_current_user( 0 );
	}

	protected function tearDown(): void {
		wp_set_current_user( 0 );
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
		// Default ON when the option/key has never been saved.
		delete_option( 'srfm_general_settings_options' );
		$this->assertTrue( $this->views->is_tracking_enabled(), 'Absent option should default to enabled.' );

		// Key present but false → disabled.
		update_option( 'srfm_general_settings_options', [ 'srfm_form_views_tracking' => false ] );
		$this->assertFalse( $this->views->is_tracking_enabled(), 'Explicit false should hide the columns.' );
		// The toggle governs display only — counting must continue while the columns are hidden,
		// so switching them back on reveals the period rather than a gap.
		wp_set_current_user( 0 );
		$this->assertTrue( $this->call_private_method( $this->views, 'should_track' ), 'should_track must ignore the display toggle and keep counting.' );

		// Key present and true → enabled.
		update_option( 'srfm_general_settings_options', [ 'srfm_form_views_tracking' => true ] );
		$this->assertTrue( $this->views->is_tracking_enabled(), 'Explicit true should enable tracking.' );

		delete_option( 'srfm_general_settings_options' );
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
