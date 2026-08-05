<?php
/**
 * Class Test_Payment_History_Shortcode
 *
 * @package sureforms
 */

use Yoast\PHPUnitPolyfills\TestCases\TestCase;
use SRFM\Inc\Payments\Payment_History_Shortcode;

class Test_Payment_History_Shortcode extends TestCase {

	protected $shortcode;

	protected function setUp(): void {
		parent::setUp();

		if ( ! class_exists( 'SRFM\Inc\Payments\Payment_History_Shortcode' ) ) {
			$this->markTestSkipped( 'Payment_History_Shortcode class not available.' );
		}

		$this->shortcode = Payment_History_Shortcode::get_instance();
	}

	protected function tearDown(): void {
		// These tests mutate shared global state (current user, $GLOBALS['post'], $_POST,
		// and the shared $wp_styles/$wp_scripts registries) and the base polyfill
		// TestCase restores none of it, so reset here to stop one test's leftovers from
		// cascading into the next.
		wp_set_current_user( 0 );
		$GLOBALS['post'] = null;
		$_POST           = [];
		wp_dequeue_style( 'srfm-payment-history' );
		wp_dequeue_script( 'srfm-payment-history' );
		parent::tearDown();
	}

	/**
	 * Helper method to call private methods for testing.
	 */
	private function call_private_method( $object, $method_name, $parameters = [] ) {
		$reflection = new \ReflectionClass( get_class( $object ) );
		$method     = $reflection->getMethod( $method_name );
		$method->setAccessible( true );
		return $method->invokeArgs( $object, $parameters );
	}

	// ──────────────────────────────────────────────
	// render - shortcode attributes
	// ──────────────────────────────────────────────

	public function test_render_returns_login_message_for_logged_out_user() {
		wp_set_current_user( 0 );
		$result = $this->shortcode->render( [] );
		$this->assertIsString( $result );
		$this->assertStringContainsString( 'srfm-pd-widget', $result );
		$this->assertStringContainsString( 'srfm-pd-message', $result );
	}

	public function test_render_accepts_string_atts() {
		wp_set_current_user( 0 );
		$result = $this->shortcode->render( '' );
		$this->assertIsString( $result );
	}

	public function test_render_accepts_array_atts() {
		wp_set_current_user( 0 );
		$result = $this->shortcode->render( [ 'per_page' => '5' ] );
		$this->assertIsString( $result );
	}

	// ──────────────────────────────────────────────
	// enqueue_assets
	// ──────────────────────────────────────────────

	/**
	 * Create an administrator and set it as the current user (JS is only enqueued for
	 * logged-in users). Returns the user ID for cleanup.
	 */
	private function login_as_admin() {
		$user_id = wp_insert_user(
			[
				'user_login' => 'ph_admin_' . wp_generate_password( 8, false ),
				'user_pass'  => wp_generate_password(),
				'user_email' => wp_generate_password( 8, false ) . '@example.com',
				'role'       => 'administrator',
			]
		);
		$user_id = is_wp_error( $user_id ) ? 0 : (int) $user_id;
		wp_set_current_user( $user_id );
		return $user_id;
	}

	private function delete_user_safely( $user_id ) {
		wp_set_current_user( 0 );
		if ( ! $user_id ) {
			return;
		}
		if ( ! function_exists( 'wp_delete_user' ) ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
		}
		wp_delete_user( $user_id );
	}

	public function test_enqueue_assets_render_fallback_enqueues_for_logged_in_user() {
		$GLOBALS['post'] = null;
		$user_id         = $this->login_as_admin();

		// enqueue_assets( true ) is the render()-time fallback path (page builder / FSE
		// compat) where the presence gate is skipped. JS loads because the user is logged in.
		$this->shortcode->enqueue_assets( true );
		$this->assertTrue( wp_style_is( 'srfm-payment-history', 'enqueued' ) );
		$this->assertTrue( wp_script_is( 'srfm-payment-history', 'enqueued' ) );

		wp_dequeue_style( 'srfm-payment-history' );
		wp_dequeue_script( 'srfm-payment-history' );
		$this->delete_user_safely( $user_id );
	}

	public function test_enqueue_assets_render_fallback_skips_js_for_logged_out_user() {
		wp_dequeue_style( 'srfm-payment-history' );
		wp_dequeue_script( 'srfm-payment-history' );
		$GLOBALS['post'] = null;
		wp_set_current_user( 0 );

		// Render-time fallback for a logged-out visitor: the CSS still loads (the login
		// message must be styled) but the JS + nonce are withheld.
		$this->shortcode->enqueue_assets( true );
		$this->assertTrue( wp_style_is( 'srfm-payment-history', 'enqueued' ), 'CSS must load so the login message is styled.' );
		$this->assertFalse( wp_script_is( 'srfm-payment-history', 'enqueued' ), 'JS + nonce must not load for logged-out visitors.' );

		wp_dequeue_style( 'srfm-payment-history' );
	}

	public function test_enqueue_assets_enqueues_when_shortcode_present() {
		$post_id         = wp_insert_post( [
			'post_title'   => 'Payment History Page',
			'post_content' => '[srfm_payment_history]',
			'post_status'  => 'publish',
		] );
		$GLOBALS['post'] = get_post( $post_id );
		$user_id         = $this->login_as_admin();

		// Direct call simulates the render()-time fallback (page builder path).
		$this->shortcode->enqueue_assets( true );
		$this->assertTrue( wp_script_is( 'srfm-payment-history', 'enqueued' ) );

		// Cleanup.
		wp_dequeue_style( 'srfm-payment-history' );
		wp_dequeue_script( 'srfm-payment-history' );
		wp_delete_post( $post_id, true );
		$this->delete_user_safely( $user_id );
	}

	public function test_enqueue_assets_does_not_double_enqueue() {
		$user_id = $this->login_as_admin();

		// First call enqueues.
		$this->shortcode->enqueue_assets( true );
		$this->assertTrue( wp_script_is( 'srfm-payment-history', 'enqueued' ) );

		// Second call is a no-op (guard check).
		$this->shortcode->enqueue_assets( true );
		$this->assertTrue( wp_script_is( 'srfm-payment-history', 'enqueued' ) );

		wp_dequeue_style( 'srfm-payment-history' );
		wp_dequeue_script( 'srfm-payment-history' );
		$this->delete_user_safely( $user_id );
	}

	/**
	 * Helper: invoke enqueue_assets() exactly as the wp_enqueue_scripts hook does — with
	 * no argument, so `$from_render` defaults to false and the presence gate
	 * (has_block/has_shortcode against the global $post) is exercised.
	 */
	private function enqueue_assets_on_hook() {
		$this->shortcode->enqueue_assets();
	}

	public function test_register_assets() {
		// register_assets() must register (not enqueue) both handles so page-builder
		// widgets can pull the stylesheet into the <head> by handle — the fix for the
		// Elementor/Bricks FOUC regression.
		wp_deregister_style( 'srfm-payment-history' );
		wp_deregister_script( 'srfm-payment-history' );

		$this->shortcode->register_assets();

		$this->assertTrue( wp_style_is( 'srfm-payment-history', 'registered' ), 'Stylesheet handle must be registered for builder head-enqueue.' );
		$this->assertTrue( wp_script_is( 'srfm-payment-history', 'registered' ), 'Script handle must be registered.' );
		// Registration alone must not enqueue anything.
		$this->assertFalse( wp_style_is( 'srfm-payment-history', 'enqueued' ), 'Registering must not enqueue on unrelated pages.' );

		// A builder enqueuing by handle (get_style_depends/enqueue_scripts) then loads it.
		wp_enqueue_style( 'srfm-payment-history' );
		$this->assertTrue( wp_style_is( 'srfm-payment-history', 'enqueued' ) );
		wp_dequeue_style( 'srfm-payment-history' );
	}

	public function test_register_assets_is_hooked_on_wp_enqueue_scripts_at_priority_one() {
		// Priority 1 is the whole mechanism: the Elementor/Bricks widgets enqueue the
		// handle by name during wp_enqueue_scripts, so it must already be registered. If
		// this silently became priority 10 both builders would enqueue an unregistered
		// handle and nothing would print — while every other test here stayed green.
		$this->assertSame(
			1,
			has_action( 'wp_enqueue_scripts', [ $this->shortcode, 'register_assets' ] ),
			'register_assets() must be hooked on wp_enqueue_scripts at priority 1.'
		);
	}

	public function test_enqueue_assets_attaches_nonce_data_for_logged_in_user() {
		$GLOBALS['post'] = null;
		$user_id         = $this->login_as_admin();

		$this->shortcode->enqueue_assets( true );

		// The nonce/ajax_url/i18n payload must actually attach to the handle — not merely
		// "script enqueued". wp_localize_script stores it as the handle's 'data'.
		$data = wp_scripts()->get_data( 'srfm-payment-history', 'data' );
		$this->assertNotEmpty( $data, 'Localized data (incl. nonce) must attach for logged-in users.' );
		$this->assertStringContainsString( 'srfm_payment_history', (string) $data );

		$this->delete_user_safely( $user_id );
	}

	public function test_enqueue_assets_withholds_nonce_data_for_logged_out_user() {
		// Now that the handle stays permanently registered, "not enqueued" and "no nonce"
		// are distinct — assert the nonce data itself never attaches for a logged-out visitor.
		wp_deregister_script( 'srfm-payment-history' );
		$GLOBALS['post'] = null;
		wp_set_current_user( 0 );

		$this->shortcode->enqueue_assets( true );

		$this->assertFalse(
			wp_scripts()->get_data( 'srfm-payment-history', 'data' ),
			'No localized nonce data may attach for logged-out visitors.'
		);
	}

	public function test_enqueue_assets_registers_script_even_when_only_style_registered() {
		// Regression guard for the asymmetric-guard bug: if the script handle is missing
		// (an asset-optimisation plugin deregistered it) while the style is still
		// registered, enqueue_assets() must still re-register the script so the localize
		// has a handle to attach the nonce to. Pre-fix this failed because the guard keyed
		// only on the *style* being registered.
		$this->shortcode->register_assets();
		wp_deregister_script( 'srfm-payment-history' );
		$this->assertTrue( wp_style_is( 'srfm-payment-history', 'registered' ), 'Precondition: style registered.' );
		$this->assertFalse( wp_script_is( 'srfm-payment-history', 'registered' ), 'Precondition: script deregistered.' );

		$GLOBALS['post'] = null;
		$user_id         = $this->login_as_admin();

		$this->shortcode->enqueue_assets( true );

		$this->assertTrue( wp_script_is( 'srfm-payment-history', 'registered' ), 'Script must be re-registered before enqueue.' );
		$this->assertNotEmpty( wp_scripts()->get_data( 'srfm-payment-history', 'data' ), 'Nonce data must attach even if the script handle was missing.' );

		$this->delete_user_safely( $user_id );
	}

	public function test_enqueue_assets_gate_skips_when_block_absent() {
		wp_dequeue_style( 'srfm-payment-history' );
		wp_dequeue_script( 'srfm-payment-history' );

		$post_id         = wp_insert_post( [
			'post_title'   => 'Plain Page',
			'post_content' => 'No payment history on this page.',
			'post_status'  => 'publish',
		] );
		$GLOBALS['post'] = get_post( $post_id );

		$this->enqueue_assets_on_hook();

		$this->assertFalse( wp_style_is( 'srfm-payment-history', 'enqueued' ), 'CSS must not load on pages without the block/shortcode.' );
		$this->assertFalse( wp_script_is( 'srfm-payment-history', 'enqueued' ), 'JS must not load on pages without the block/shortcode.' );

		$GLOBALS['post'] = null;
		wp_delete_post( $post_id, true );
	}

	public function test_enqueue_assets_gate_skips_when_no_wp_post() {
		wp_dequeue_style( 'srfm-payment-history' );
		wp_dequeue_script( 'srfm-payment-history' );

		// Archive / 404 / REST context: $GLOBALS['post'] is not a WP_Post, so the
		// hook-time gate must early-return and enqueue nothing.
		$GLOBALS['post'] = null;

		$this->enqueue_assets_on_hook();

		$this->assertFalse( wp_style_is( 'srfm-payment-history', 'enqueued' ), 'CSS must not load when there is no WP_Post.' );
		$this->assertFalse( wp_script_is( 'srfm-payment-history', 'enqueued' ), 'JS must not load when there is no WP_Post.' );
	}

	/**
	 * The real regression guard for this PR: with the block/shortcode present on the
	 * hook, the assets load — and, more importantly, `test_enqueue_assets_gate_skips_*`
	 * prove they do NOT load otherwise (the behavioral change vs. the old site-wide load).
	 */
	public function test_enqueue_assets_gate_enqueues_when_shortcode_present_on_hook() {
		wp_dequeue_style( 'srfm-payment-history' );
		wp_dequeue_script( 'srfm-payment-history' );

		$post_id         = wp_insert_post( [
			'post_title'   => 'Payment History Page',
			'post_content' => '[srfm_payment_history]',
			'post_status'  => 'publish',
		] );
		$GLOBALS['post'] = get_post( $post_id );
		$user_id         = $this->login_as_admin();

		$this->enqueue_assets_on_hook();

		$this->assertTrue( wp_style_is( 'srfm-payment-history', 'enqueued' ), 'CSS must load when the shortcode is present.' );
		$this->assertTrue( wp_script_is( 'srfm-payment-history', 'enqueued' ), 'JS must load when the shortcode is present.' );

		wp_dequeue_style( 'srfm-payment-history' );
		wp_dequeue_script( 'srfm-payment-history' );
		$GLOBALS['post'] = null;
		wp_delete_post( $post_id, true );
		$this->delete_user_safely( $user_id );
	}

	public function test_enqueue_assets_gate_enqueues_when_block_present_on_hook() {
		wp_dequeue_style( 'srfm-payment-history' );
		wp_dequeue_script( 'srfm-payment-history' );

		// The Gutenberg block is the primary insertion path — cover its has_block()
		// detection with real block markup so a block-name typo cannot silently stop
		// asset loading with the shortcode tests still green.
		$post_id         = wp_insert_post( [
			'post_title'   => 'Payment History Block Page',
			'post_content' => '<!-- wp:srfm/payment-history /-->',
			'post_status'  => 'publish',
		] );
		$GLOBALS['post'] = get_post( $post_id );
		$user_id         = $this->login_as_admin();

		$this->enqueue_assets_on_hook();

		$this->assertTrue( wp_style_is( 'srfm-payment-history', 'enqueued' ), 'CSS must load when the block is present.' );
		$this->assertTrue( wp_script_is( 'srfm-payment-history', 'enqueued' ), 'JS must load when the block is present.' );

		wp_dequeue_style( 'srfm-payment-history' );
		wp_dequeue_script( 'srfm-payment-history' );
		$GLOBALS['post'] = null;
		wp_delete_post( $post_id, true );
		$this->delete_user_safely( $user_id );
	}

	public function test_enqueue_assets_gate_loads_css_but_not_js_for_logged_out_on_hook() {
		wp_dequeue_style( 'srfm-payment-history' );
		wp_dequeue_script( 'srfm-payment-history' );

		$post_id         = wp_insert_post( [
			'post_title'   => 'Payment History Page',
			'post_content' => '[srfm_payment_history]',
			'post_status'  => 'publish',
		] );
		$GLOBALS['post'] = get_post( $post_id );
		wp_set_current_user( 0 );

		$this->enqueue_assets_on_hook();

		$this->assertTrue( wp_style_is( 'srfm-payment-history', 'enqueued' ), 'CSS must load so the login message is styled.' );
		$this->assertFalse( wp_script_is( 'srfm-payment-history', 'enqueued' ), 'JS + nonce must be withheld from logged-out visitors.' );

		wp_dequeue_style( 'srfm-payment-history' );
		$GLOBALS['post'] = null;
		wp_delete_post( $post_id, true );
	}

	public function test_render_enqueues_css_for_logged_out_user() {
		wp_dequeue_style( 'srfm-payment-history' );
		wp_dequeue_script( 'srfm-payment-history' );

		wp_set_current_user( 0 );
		$result = $this->shortcode->render( [] );

		// The login message is returned, but assets must be enqueued first so it is styled
		// even when the widget is placed where the wp_enqueue_scripts gate cannot detect it.
		$this->assertStringContainsString( 'srfm-pd-widget', $result );
		$this->assertTrue( wp_style_is( 'srfm-payment-history', 'enqueued' ), 'Login message must load the stylesheet for logged-out users.' );

		wp_dequeue_style( 'srfm-payment-history' );
		wp_dequeue_script( 'srfm-payment-history' );
	}

	// ──────────────────────────────────────────────
	// ajax_cancel_subscription
	// ──────────────────────────────────────────────

	public function test_ajax_cancel_subscription_fails_without_nonce() {
		// Simulate AJAX call without nonce — should trigger wp_send_json_error.
		$_POST = [];
		try {
			$this->shortcode->ajax_cancel_subscription();
		} catch ( \WPDieException $e ) {
			$this->assertStringContainsString( 'Security check failed', $e->getMessage() );
			return;
		}
		// If wp_send_json_error calls wp_die in test env, we get here.
		$this->assertTrue( true );
	}

	public function test_ajax_cancel_subscription_fails_when_logged_out() {
		wp_set_current_user( 0 );
		$_POST['nonce'] = wp_create_nonce( 'srfm_frontend_payment_nonce' );
		try {
			$this->shortcode->ajax_cancel_subscription();
		} catch ( \WPDieException $e ) {
			$this->assertStringContainsString( 'logged in', $e->getMessage() );
			return;
		}
		$this->assertTrue( true );
	}

	public function test_ajax_cancel_subscription_fails_with_empty_payment_id() {
		$user_id = wp_insert_user( [
			'user_login' => 'testuser_cancel_' . wp_rand(),
			'user_pass'  => 'password',
			'user_email' => 'cancel_test_' . wp_rand() . '@example.com',
		] );
		wp_set_current_user( $user_id );
		$_POST['nonce']      = wp_create_nonce( 'srfm_frontend_payment_nonce' );
		$_POST['payment_id'] = 0;
		try {
			$this->shortcode->ajax_cancel_subscription();
		} catch ( \WPDieException $e ) {
			$this->assertStringContainsString( 'Invalid payment data', $e->getMessage() );
			wp_delete_user( $user_id );
			return;
		}
		wp_delete_user( $user_id );
		$this->assertTrue( true );
	}

	// ──────────────────────────────────────────────
	// format_amount
	// ──────────────────────────────────────────────

	public function test_format_amount_left_position() {
		$result = $this->call_private_method( $this->shortcode, 'format_amount', [ 99.99, 'USD' ] );
		$this->assertIsString( $result );
		$this->assertStringContainsString( '99.99', $result );
	}

	public function test_format_amount_zero() {
		$result = $this->call_private_method( $this->shortcode, 'format_amount', [ 0.0, 'USD' ] );
		$this->assertIsString( $result );
		$this->assertStringContainsString( '0.00', $result );
	}

	public function test_format_amount_large_number() {
		$result = $this->call_private_method( $this->shortcode, 'format_amount', [ 1234567.89, 'USD' ] );
		$this->assertIsString( $result );
		$this->assertStringContainsString( '1,234,567.89', $result );
	}

	// ──────────────────────────────────────────────
	// format_interval
	// ──────────────────────────────────────────────

	public function test_format_interval_month() {
		$result = $this->call_private_method( $this->shortcode, 'format_interval', [ 'month', 1 ] );
		$this->assertIsString( $result );
		$this->assertNotEmpty( $result );
	}

	public function test_format_interval_year() {
		$result = $this->call_private_method( $this->shortcode, 'format_interval', [ 'year', 1 ] );
		$this->assertIsString( $result );
	}

	public function test_format_interval_with_count_greater_than_one() {
		$result = $this->call_private_method( $this->shortcode, 'format_interval', [ 'month', 3 ] );
		$this->assertStringContainsString( '3', $result );
	}

	public function test_format_interval_unknown_type() {
		$result = $this->call_private_method( $this->shortcode, 'format_interval', [ 'unknown_interval', 1 ] );
		$this->assertSame( 'unknown_interval', $result );
	}

	// ──────────────────────────────────────────────
	// get_subscription_status_label
	// ──────────────────────────────────────────────

	public function test_subscription_status_label_active() {
		$result = $this->call_private_method( $this->shortcode, 'get_subscription_status_label', [ 'active' ] );
		$this->assertSame( 'Active', $result );
	}

	public function test_subscription_status_label_canceled() {
		$result = $this->call_private_method( $this->shortcode, 'get_subscription_status_label', [ 'canceled' ] );
		$this->assertSame( 'Cancelled', $result );
	}

	public function test_subscription_status_label_trialing() {
		$result = $this->call_private_method( $this->shortcode, 'get_subscription_status_label', [ 'trialing' ] );
		$this->assertSame( 'Trialing', $result );
	}

	public function test_subscription_status_label_past_due() {
		$result = $this->call_private_method( $this->shortcode, 'get_subscription_status_label', [ 'past_due' ] );
		$this->assertSame( 'Past Due', $result );
	}

	public function test_subscription_status_label_paused() {
		$result = $this->call_private_method( $this->shortcode, 'get_subscription_status_label', [ 'paused' ] );
		$this->assertSame( 'Paused', $result );
	}

	public function test_subscription_status_label_unknown_returns_ucfirst() {
		$result = $this->call_private_method( $this->shortcode, 'get_subscription_status_label', [ 'some_status' ] );
		$this->assertSame( 'Some status', $result );
	}

	public function test_subscription_status_label_empty() {
		$result = $this->call_private_method( $this->shortcode, 'get_subscription_status_label', [ '' ] );
		$this->assertSame( '', $result );
	}

	// ──────────────────────────────────────────────
	// get_payment_status_label
	// ──────────────────────────────────────────────

	public function test_payment_status_label_succeeded() {
		$result = $this->call_private_method( $this->shortcode, 'get_payment_status_label', [ 'succeeded' ] );
		$this->assertSame( 'Paid', $result );
	}

	public function test_payment_status_label_pending() {
		$result = $this->call_private_method( $this->shortcode, 'get_payment_status_label', [ 'pending' ] );
		$this->assertSame( 'Pending', $result );
	}

	public function test_payment_status_label_failed() {
		$result = $this->call_private_method( $this->shortcode, 'get_payment_status_label', [ 'failed' ] );
		$this->assertSame( 'Failed', $result );
	}

	public function test_payment_status_label_refunded() {
		$result = $this->call_private_method( $this->shortcode, 'get_payment_status_label', [ 'refunded' ] );
		$this->assertSame( 'Refunded', $result );
	}

	public function test_payment_status_label_partially_refunded() {
		$result = $this->call_private_method( $this->shortcode, 'get_payment_status_label', [ 'partially_refunded' ] );
		$this->assertSame( 'Partially Refunded', $result );
	}

	public function test_payment_status_label_unknown_returns_ucfirst() {
		$result = $this->call_private_method( $this->shortcode, 'get_payment_status_label', [ 'custom_status' ] );
		$this->assertSame( 'Custom status', $result );
	}

	// ──────────────────────────────────────────────
	// get_payment_badge_class
	// ──────────────────────────────────────────────

	public function test_payment_badge_class_succeeded() {
		$result = $this->call_private_method( $this->shortcode, 'get_payment_badge_class', [ 'succeeded' ] );
		$this->assertSame( 'srfm-pd-badge--paid', $result );
	}

	public function test_payment_badge_class_active() {
		$result = $this->call_private_method( $this->shortcode, 'get_payment_badge_class', [ 'active' ] );
		$this->assertSame( 'srfm-pd-badge--paid', $result );
	}

	public function test_payment_badge_class_pending() {
		$result = $this->call_private_method( $this->shortcode, 'get_payment_badge_class', [ 'pending' ] );
		$this->assertSame( 'srfm-pd-badge--pending', $result );
	}

	public function test_payment_badge_class_failed() {
		$result = $this->call_private_method( $this->shortcode, 'get_payment_badge_class', [ 'failed' ] );
		$this->assertSame( 'srfm-pd-badge--cancelled', $result );
	}

	public function test_payment_badge_class_refunded() {
		$result = $this->call_private_method( $this->shortcode, 'get_payment_badge_class', [ 'refunded' ] );
		$this->assertSame( 'srfm-pd-badge--refunded', $result );
	}

	public function test_payment_badge_class_unknown_returns_pending() {
		$result = $this->call_private_method( $this->shortcode, 'get_payment_badge_class', [ 'unknown' ] );
		$this->assertSame( 'srfm-pd-badge--pending', $result );
	}

	// ──────────────────────────────────────────────
	// parse_json_field
	// ──────────────────────────────────────────────

	public function test_parse_json_field_with_valid_json_string() {
		$result = $this->call_private_method( $this->shortcode, 'parse_json_field', [ '{"key":"value"}' ] );
		$this->assertIsArray( $result );
		$this->assertSame( 'value', $result['key'] );
	}

	public function test_parse_json_field_with_array() {
		$input  = [ 'key' => 'value' ];
		$result = $this->call_private_method( $this->shortcode, 'parse_json_field', [ $input ] );
		$this->assertSame( $input, $result );
	}

	public function test_parse_json_field_with_empty_string() {
		$result = $this->call_private_method( $this->shortcode, 'parse_json_field', [ '' ] );
		$this->assertSame( [], $result );
	}

	public function test_parse_json_field_with_invalid_json() {
		$result = $this->call_private_method( $this->shortcode, 'parse_json_field', [ 'not json' ] );
		$this->assertSame( [], $result );
	}

	public function test_parse_json_field_with_null() {
		$result = $this->call_private_method( $this->shortcode, 'parse_json_field', [ null ] );
		$this->assertSame( [], $result );
	}

	public function test_parse_json_field_with_integer() {
		$result = $this->call_private_method( $this->shortcode, 'parse_json_field', [ 123 ] );
		$this->assertSame( [], $result );
	}

	// ──────────────────────────────────────────────
	// get_string_from_sources
	// ──────────────────────────────────────────────

	public function test_get_string_from_sources_returns_from_primary() {
		$result = $this->call_private_method(
			$this->shortcode,
			'get_string_from_sources',
			[ 'name', [ 'name' => 'Primary' ], [ 'name' => 'Fallback' ] ]
		);
		$this->assertSame( 'Primary', $result );
	}

	public function test_get_string_from_sources_falls_back_to_secondary() {
		$result = $this->call_private_method(
			$this->shortcode,
			'get_string_from_sources',
			[ 'name', [], [ 'name' => 'Fallback' ] ]
		);
		$this->assertSame( 'Fallback', $result );
	}

	public function test_get_string_from_sources_returns_empty_when_not_found() {
		$result = $this->call_private_method(
			$this->shortcode,
			'get_string_from_sources',
			[ 'missing', [ 'other' => 'val' ], [ 'other2' => 'val2' ] ]
		);
		$this->assertSame( '', $result );
	}

	public function test_get_string_from_sources_skips_empty_primary_value() {
		$result = $this->call_private_method(
			$this->shortcode,
			'get_string_from_sources',
			[ 'name', [ 'name' => '' ], [ 'name' => 'Fallback' ] ]
		);
		$this->assertSame( 'Fallback', $result );
	}

	public function test_get_string_from_sources_converts_numeric_to_string() {
		$result = $this->call_private_method(
			$this->shortcode,
			'get_string_from_sources',
			[ 'count', [ 'count' => 42 ], [] ]
		);
		$this->assertSame( '42', $result );
	}

	// ──────────────────────────────────────────────
	// format_timestamp
	// ──────────────────────────────────────────────

	public function test_format_timestamp_with_unix_timestamp() {
		$result = $this->call_private_method( $this->shortcode, 'format_timestamp', [ '1700000000' ] );
		$this->assertIsString( $result );
		$this->assertNotEmpty( $result );
	}

	public function test_format_timestamp_with_date_string() {
		$result = $this->call_private_method( $this->shortcode, 'format_timestamp', [ '2024-01-15' ] );
		$this->assertIsString( $result );
		$this->assertNotEmpty( $result );
	}

	// ──────────────────────────────────────────────
	// get_form_title
	// ──────────────────────────────────────────────

	public function test_get_form_title_returns_unknown_for_zero_id() {
		$result = $this->call_private_method( $this->shortcode, 'get_form_title', [ 0 ] );
		$this->assertSame( 'Unknown Form', $result );
	}

	public function test_get_form_title_returns_unknown_for_negative_id() {
		$result = $this->call_private_method( $this->shortcode, 'get_form_title', [ -1 ] );
		$this->assertSame( 'Unknown Form', $result );
	}

	public function test_get_form_title_returns_unknown_for_nonexistent_post() {
		$result = $this->call_private_method( $this->shortcode, 'get_form_title', [ 999999 ] );
		$this->assertSame( 'Unknown Form', $result );
	}

	// ──────────────────────────────────────────────
	// extract_subscription_data
	// ──────────────────────────────────────────────

	public function test_extract_subscription_data_returns_expected_keys() {
		$payment = [
			'payment_data' => '{}',
			'extra'        => '{}',
		];
		$result  = $this->call_private_method( $this->shortcode, 'extract_subscription_data', [ $payment ] );
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'plan_name', $result );
		$this->assertArrayHasKey( 'interval_label', $result );
		$this->assertArrayHasKey( 'next_payment', $result );
		$this->assertArrayHasKey( 'cancelled_on', $result );
		$this->assertArrayHasKey( 'access_until', $result );
	}

	public function test_extract_subscription_data_empty_payment() {
		$result = $this->call_private_method( $this->shortcode, 'extract_subscription_data', [ [] ] );
		$this->assertIsArray( $result );
		$this->assertSame( '', $result['plan_name'] );
		$this->assertSame( '', $result['interval_label'] );
		$this->assertSame( '', $result['next_payment'] );
		$this->assertSame( '', $result['cancelled_on'] );
		$this->assertSame( '', $result['access_until'] );
	}

	public function test_extract_subscription_data_with_plan_name_in_payment_data() {
		$payment = [
			'payment_data' => wp_json_encode( [ 'plan_name' => 'Premium Plan' ] ),
			'extra'        => '{}',
		];
		$result  = $this->call_private_method( $this->shortcode, 'extract_subscription_data', [ $payment ] );
		$this->assertSame( 'Premium Plan', $result['plan_name'] );
	}

	public function test_extract_subscription_data_with_interval() {
		$payment = [
			'payment_data' => wp_json_encode( [
				'interval'       => 'month',
				'interval_count' => '1',
			] ),
			'extra'         => '{}',
		];
		$result  = $this->call_private_method( $this->shortcode, 'extract_subscription_data', [ $payment ] );
		$this->assertNotEmpty( $result['interval_label'] );
	}

	public function test_extract_subscription_data_with_next_period_end() {
		$payment = [
			'payment_data' => wp_json_encode( [ 'current_period_end' => '1700000000' ] ),
			'extra'        => '{}',
		];
		$result  = $this->call_private_method( $this->shortcode, 'extract_subscription_data', [ $payment ] );
		$this->assertNotEmpty( $result['next_payment'] );
	}

	public function test_extract_subscription_data_with_canceled_status_and_next_date() {
		$payment = [
			'payment_data'        => wp_json_encode( [ 'current_period_end' => '1700000000' ] ),
			'extra'               => '{}',
			'subscription_status' => 'canceled',
		];
		$result  = $this->call_private_method( $this->shortcode, 'extract_subscription_data', [ $payment ] );
		$this->assertNotEmpty( $result['access_until'] );
	}

	// ──────────────────────────────────────────────
	// build_where_conditions
	// ──────────────────────────────────────────────

	public function test_build_where_conditions_returns_array() {
		$result = $this->call_private_method( $this->shortcode, 'build_where_conditions', [ 1, [ 'per_page' => '10' ] ] );
		$this->assertIsArray( $result );
	}

	public function test_build_where_conditions_includes_gateway_filter() {
		$result = $this->call_private_method( $this->shortcode, 'build_where_conditions', [ 1, [] ] );
		$this->assertIsArray( $result );
		// Should contain at least gateway condition.
		$found_gateway = false;
		foreach ( $result as $group ) {
			if ( is_array( $group ) ) {
				foreach ( $group as $condition ) {
					if ( is_array( $condition ) && isset( $condition['key'] ) && 'gateway' === $condition['key'] ) {
						$found_gateway = true;
						break 2;
					}
				}
			}
		}
		$this->assertTrue( $found_gateway, 'Gateway filter condition should be present.' );
	}

	// ──────────────────────────────────────────────
	// get_i18n_strings
	// ──────────────────────────────────────────────

	public function test_get_i18n_strings_returns_array_with_expected_keys() {
		$result = $this->call_private_method( $this->shortcode, 'get_i18n_strings', [] );
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'cancel_confirm_now', $result );
		$this->assertArrayHasKey( 'are_you_sure', $result );
		$this->assertArrayHasKey( 'keep_subscription', $result );
		$this->assertArrayHasKey( 'yes_cancel', $result );
		$this->assertArrayHasKey( 'done', $result );
		$this->assertArrayHasKey( 'subscription_cancelled', $result );
		$this->assertArrayHasKey( 'error', $result );
	}

	public function test_get_i18n_strings_values_are_all_strings() {
		$result = $this->call_private_method( $this->shortcode, 'get_i18n_strings', [] );
		foreach ( $result as $key => $value ) {
			$this->assertIsString( $value, "i18n key '{$key}' should be a string." );
		}
	}

	// ──────────────────────────────────────────────
	// get_login_message / get_empty_message
	// ──────────────────────────────────────────────

	public function test_get_login_message_contains_widget_class() {
		$result = $this->call_private_method( $this->shortcode, 'get_login_message', [] );
		$this->assertStringContainsString( 'srfm-pd-widget', $result );
		$this->assertStringContainsString( 'srfm-pd-message', $result );
	}

	public function test_get_empty_message_contains_widget_class() {
		$result = $this->call_private_method( $this->shortcode, 'get_empty_message', [] );
		$this->assertStringContainsString( 'srfm-pd-widget', $result );
		$this->assertStringContainsString( 'srfm-pd-message', $result );
	}

	// ──────────────────────────────────────────────
	// process_stripe_subscription_cancellation
	// ──────────────────────────────────────────────

	public function test_stripe_subscription_cancellation_skips_non_stripe() {
		if ( ! class_exists( 'SRFM\Inc\Payments\Stripe\Admin_Stripe_Handler' ) ) {
			$this->markTestSkipped( 'Admin_Stripe_Handler not available.' );
		}
		$handler = \SRFM\Inc\Payments\Stripe\Admin_Stripe_Handler::get_instance();
		$default = [ 'success' => false, 'message' => 'default' ];
		$payment = [ 'gateway' => 'paypal' ];
		$result  = $handler->process_stripe_subscription_cancellation( $default, $payment );
		$this->assertSame( $default, $result );
	}

	public function test_stripe_subscription_cancellation_skips_empty_gateway() {
		if ( ! class_exists( 'SRFM\Inc\Payments\Stripe\Admin_Stripe_Handler' ) ) {
			$this->markTestSkipped( 'Admin_Stripe_Handler not available.' );
		}
		$handler = \SRFM\Inc\Payments\Stripe\Admin_Stripe_Handler::get_instance();
		$default = [ 'success' => false, 'message' => 'default' ];
		$payment = [];
		$result  = $handler->process_stripe_subscription_cancellation( $default, $payment );
		$this->assertSame( $default, $result );
	}

	public function test_stripe_subscription_cancellation_missing_subscription_id() {
		if ( ! class_exists( 'SRFM\Inc\Payments\Stripe\Admin_Stripe_Handler' ) ) {
			$this->markTestSkipped( 'Admin_Stripe_Handler not available.' );
		}
		$handler = \SRFM\Inc\Payments\Stripe\Admin_Stripe_Handler::get_instance();
		$default = [ 'success' => false, 'message' => 'default' ];
		$payment = [ 'gateway' => 'stripe' ];
		$result  = $handler->process_stripe_subscription_cancellation( $default, $payment );
		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'Subscription ID not found', $result['message'] );
	}

	public function test_stripe_subscription_cancellation_non_string_subscription_id() {
		if ( ! class_exists( 'SRFM\Inc\Payments\Stripe\Admin_Stripe_Handler' ) ) {
			$this->markTestSkipped( 'Admin_Stripe_Handler not available.' );
		}
		$handler = \SRFM\Inc\Payments\Stripe\Admin_Stripe_Handler::get_instance();
		$default = [ 'success' => false, 'message' => 'default' ];
		$payment = [ 'gateway' => 'stripe', 'subscription_id' => 12345 ];
		$result  = $handler->process_stripe_subscription_cancellation( $default, $payment );
		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'Subscription ID not found', $result['message'] );
	}
}
