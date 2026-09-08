<?php
/**
 * Class Test_Frontend_Assets
 *
 * @package sureforms
 */

use Yoast\PHPUnitPolyfills\TestCases\TestCase;
use SRFM\Inc\Frontend_Assets;

/**
 * Tests for Frontend_Assets.
 */
class Test_Frontend_Assets extends TestCase {

	/**
	 * Frontend_Assets instance.
	 *
	 * @var Frontend_Assets
	 */
	protected $frontend_assets;

	/**
	 * Posts to remove after each test.
	 *
	 * This suite uses Yoast's TestCase rather than WP_UnitTestCase, so there is
	 * no per-test rollback -- a failed assertion would otherwise leak a
	 * published post into the rest of the run.
	 *
	 * @var array<int>
	 */
	protected $created_posts = [];

	/**
	 * Query state to restore, so one test cannot leak a view into the next.
	 *
	 * @var array<string,mixed>
	 */
	protected $previous_query = [];

	/**
	 * Whether this test faked a wp_head() having already fired.
	 *
	 * @var bool
	 */
	protected $faked_wp_head = false;

	/**
	 * Set up test fixtures.
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->frontend_assets = Frontend_Assets::get_instance();
	}

	/**
	 * Test enqueue_srfm_script does not localize payment_nonce for Stripe.
	 *
	 * After the HMAC token migration, the srfm_ajax localized data should
	 * contain ajax_url but NOT payment_nonce.
	 */
	public function test_enqueue_srfm_script_no_payment_nonce_for_stripe() {
		// Enqueue the payment block scripts.
		$this->frontend_assets->enqueue_srfm_script( 'srfm/payment', [] );

		// Get the localized data for the Stripe payment script.
		$scripts        = wp_scripts();
		$stripe_handle  = 'srfm-stripe-payment';
		$localized_data = $scripts->get_data( $stripe_handle, 'data' );

		if ( $localized_data ) {
			$this->assertStringContainsString( 'ajax_url', $localized_data, 'srfm_ajax should contain ajax_url.' );
			$this->assertStringNotContainsString( 'payment_nonce', $localized_data, 'srfm_ajax should NOT contain payment_nonce after HMAC migration.' );
		} else {
			// Script may not have been registered if SRFM_URL/SRFM_VER aren't defined in test env.
			$this->markTestSkipped( 'Stripe payment script not registered in test environment.' );
		}
	}

	/**
	 * Test enqueue_srfm_script is a callable method.
	 */
	public function test_enqueue_srfm_script_is_callable() {
		$this->assertTrue(
			method_exists( $this->frontend_assets, 'enqueue_srfm_script' ),
			'enqueue_srfm_script method should exist on Frontend_Assets.'
		);
	}

	/**
	 * Test enqueue_srfm_script localizes Quill i18n strings for rich text textarea.
	 */
	public function test_enqueue_srfm_script_localizes_quill_i18n_for_richtext_textarea() {
		// Enqueue the textarea block with isRichText enabled.
		$this->frontend_assets->enqueue_srfm_script( 'srfm/textarea', [ 'isRichText' => true ] );

		// Retrieve the localized data for the textarea script.
		$localized_data = wp_scripts()->get_data( SRFM_SLUG . '-textarea', 'data' );

		$this->assertNotEmpty( $localized_data, 'Localized script data should not be empty for rich text textarea.' );
		$this->assertStringContainsString( 'srfm_quill_i18n', $localized_data, 'Localized data should contain srfm_quill_i18n object.' );

		// Verify all expected i18n keys are present in the localized data.
		$expected_keys = [
			'normal',
			'heading_1',
			'heading_2',
			'heading_3',
			'heading_4',
			'heading_5',
			'heading_6',
			'visit_url',
			'enter_link',
			'edit',
			'save',
			'remove',
		];

		foreach ( $expected_keys as $key ) {
			$this->assertStringContainsString( '"' . $key . '"', $localized_data, "Localized data should contain the '{$key}' key." );
		}
	}

	/**
	 * Test enqueue_srfm_script does not localize Quill i18n for non-richtext textarea.
	 */
	public function test_enqueue_srfm_script_does_not_localize_quill_i18n_for_plain_textarea() {
		// Reset scripts registry to start clean.
		wp_scripts()->registered = [];
		wp_scripts()->queue      = [];

		// Enqueue the textarea block without isRichText.
		$this->frontend_assets->enqueue_srfm_script( 'srfm/textarea', [] );

		// The textarea script should not be enqueued at all for non-richtext.
		$localized_data = wp_scripts()->get_data( SRFM_SLUG . '-textarea', 'data' );

		$this->assertFalse( $localized_data, 'Localized data should not exist for non-richtext textarea.' );
	}

	/**
	 * Smoke test for register_scripts(): the method should run without errors,
	 * and the `srfm-form-submit` script — through which the WPML pipeline
	 * localizes built-in validation messages to JS — should be registered.
	 */
	public function test_register_scripts() {
		// Start clean so we can inspect what register_scripts() produced.
		wp_scripts()->registered = [];
		wp_scripts()->queue      = [];

		$this->frontend_assets->register_scripts();

		// The form-submit script is the host for localized validation strings.
		$registered = wp_scripts()->query( SRFM_SLUG . '-form-submit', 'registered' );
		$this->assertNotFalse( $registered, 'srfm-form-submit script should be registered after register_scripts().' );
	}

	/**
	 * Test enqueue_scripts_and_styles() honors the $skip_form_styles flag.
	 *
	 * When a form has default styling disabled, the SureForms stylesheets must
	 * be skipped while external library styles (Tom Select, intl-tel-input)
	 * and all scripts keep loading.
	 */
	public function test_enqueue_scripts_and_styles() {
		// Register all handles first, then start from an empty queue.
		$this->frontend_assets->register_scripts();
		wp_styles()->queue  = [];
		wp_scripts()->queue = [];

		Frontend_Assets::enqueue_scripts_and_styles( true );

		$this->assertFalse( wp_style_is( SRFM_SLUG . '-common', 'enqueued' ), 'SureForms stylesheets should be skipped when default styling is disabled.' );
		$this->assertFalse( wp_style_is( SRFM_SLUG . '-frontend-default', 'enqueued' ), 'SureForms stylesheets should be skipped when default styling is disabled.' );
		$this->assertFalse( wp_style_is( SRFM_SLUG . '-form', 'enqueued' ), 'SureForms stylesheets should be skipped when default styling is disabled.' );
		$this->assertTrue( wp_style_is( SRFM_SLUG . '-tom-select', 'enqueued' ), 'External library styles should always be enqueued.' );
		$this->assertTrue( wp_style_is( SRFM_SLUG . '-intl-tel-input', 'enqueued' ), 'External library styles should always be enqueued.' );
		$this->assertTrue( wp_script_is( SRFM_SLUG . '-frontend', 'enqueued' ), 'Scripts should always be enqueued.' );
		$this->assertTrue( wp_script_is( SRFM_SLUG . '-form-submit', 'enqueued' ), 'Scripts should always be enqueued.' );

		// Reset the queues and enqueue with default styling enabled.
		wp_styles()->queue  = [];
		wp_scripts()->queue = [];

		Frontend_Assets::enqueue_scripts_and_styles();

		$this->assertTrue( wp_style_is( SRFM_SLUG . '-common', 'enqueued' ), 'SureForms stylesheets should be enqueued by default.' );
		$this->assertTrue( wp_style_is( SRFM_SLUG . '-frontend-default', 'enqueued' ), 'SureForms stylesheets should be enqueued by default.' );
		$this->assertTrue( wp_style_is( SRFM_SLUG . '-form', 'enqueued' ), 'SureForms stylesheets should be enqueued by default.' );
	}

	/**
	 * On a single form, assets a discarded render already claimed are forgotten.
	 *
	 * A page builder that renders the whole page through its own
	 * `template_include` filter runs `wp_head()`/`wp_footer()` inside a buffer
	 * that page_template() then discards, leaving every handle in
	 * `WP_Styles::$done` / `WP_Scripts::$done`. Without the reset the Instant
	 * Form template's own wp_head()/wp_footer() print nothing -- no stylesheets,
	 * and no footer-registered `srfm-form-submit`, so the form cannot submit.
	 *
	 * The style and script handles here are asserted separately: the styles are
	 * the reported symptom, the footer script is the one that stops the form
	 * working, and a reset that covered only one registry would leave the other
	 * broken.
	 */
	public function test_page_template_clears_assets_claimed_by_a_discarded_render() {
		$form_id = $this->make_form();
		$this->make_singular( $form_id );

		wp_register_style( 'srfm-test-style', 'https://example.org/s.css', [], '1' );
		wp_register_script( 'srfm-test-footer-script', 'https://example.org/s.js', [], '1', true );

		// The state a discarded builder render leaves behind.
		$this->mark_wp_head_as_fired();
		wp_styles()->done  = [ 'srfm-test-style' ];
		wp_scripts()->done = [ 'srfm-test-footer-script' ];

		$this->frontend_assets->page_template( 'irrelevant-builder-relay.php' );

		$this->assertFalse(
			wp_style_is( 'srfm-test-style', 'done' ),
			'A stylesheet claimed by the discarded render must be printable again.'
		);
		$this->assertFalse(
			wp_script_is( 'srfm-test-footer-script', 'done' ),
			'A footer script claimed by the discarded render must be printable again, or the form cannot submit.'
		);
	}

	/**
	 * With no earlier render, page_template() leaves the registries untouched.
	 *
	 * This is the ordinary path on the overwhelming majority of installs, which
	 * have no page builder taking over `template_include` at all. Nothing has
	 * been printed yet, so there is nothing to forget and the reset must be
	 * inert -- asserted on the specific handle rather than on the array being
	 * empty, since other tests in this suite leave handles behind.
	 */
	public function test_page_template_keeps_printed_assets_without_an_earlier_render() {
		$form_id = $this->make_form();
		$this->make_singular( $form_id );

		wp_register_style( 'srfm-test-style', 'https://example.org/s.css', [], '1' );
		wp_styles()->done = [ 'srfm-test-style' ];

		// Deliberately do NOT mark wp_head as fired.
		$this->frontend_assets->page_template( 'irrelevant.php' );

		$this->assertTrue(
			wp_style_is( 'srfm-test-style', 'done' ),
			'Without a discarded render there is nothing to reset, so done must be left alone.'
		);
	}

	/**
	 * On any other page, page_template() touches neither the template nor assets.
	 *
	 * A page built in the site's page builder must keep both its own template and
	 * its printed assets. Getting this wrong would strip the layout from every
	 * page on the site, which is far worse than the bug being fixed, so the
	 * negative is asserted as carefully as the positive.
	 */
	public function test_page_template_leaves_other_pages_alone() {
		$page_id = wp_insert_post(
			[
				'post_title'  => 'An Ordinary Page',
				'post_type'   => 'page',
				'post_status' => 'publish',
			]
		);
		$this->assertIsInt( $page_id, 'Test setup: the page fixture must insert.' );
		$this->created_posts[] = $page_id;

		$this->make_singular( $page_id );
		$this->assertFalse( is_singular( SRFM_FORMS_POST_TYPE ), 'Test setup: this must not be a form page.' );

		wp_register_style( 'srfm-test-style', 'https://example.org/s.css', [], '1' );
		$this->mark_wp_head_as_fired();
		wp_styles()->done = [ 'srfm-test-style' ];

		$builder_template = 'builder-relay.php';

		$this->assertSame(
			$builder_template,
			$this->frontend_assets->page_template( $builder_template ),
			'Another plugin\'s template must be returned untouched off a form page.'
		);
		$this->assertTrue(
			wp_style_is( 'srfm-test-style', 'done' ),
			'Assets already printed on a page SureForms does not own must stay printed.'
		);
	}

	/**
	 * On a single form, the Instant Form template replaces whatever was passed in.
	 *
	 * Pins the behaviour the reset depends on: the incoming template really is
	 * discarded, which is why the registry state it produced has to be discarded
	 * with it.
	 */
	public function test_page_template_returns_the_instant_form_template() {
		$form_id = $this->make_form();
		$this->make_singular( $form_id );

		$resolved = $this->frontend_assets->page_template( 'builder-relay.php' );

		$this->assertNotSame( 'builder-relay.php', $resolved, 'The incoming template must not survive on a form page.' );
		$this->assertStringEndsWith( 'single-form.php', $resolved, 'A single form must resolve to the Instant Form template.' );
	}

	/**
	 * Create a published form fixture.
	 *
	 * @return int
	 */
	protected function make_form() {
		$form_id = wp_insert_post(
			[
				'post_title'  => 'Instant Form Assets',
				'post_type'   => SRFM_FORMS_POST_TYPE,
				'post_status' => 'publish',
			]
		);

		$this->assertIsInt( $form_id, 'Test setup: the form fixture must insert.' );
		$this->created_posts[] = $form_id;

		return $form_id;
	}

	/**
	 * Put the main query on a singular view of the given post.
	 *
	 * go_to() belongs to WP_UnitTestCase and this suite uses Yoast's TestCase, so
	 * the query globals are driven directly -- the same approach as
	 * Test_Generate_Form_Markup.
	 *
	 * @param int $post_id Post to be the queried object.
	 * @return void
	 */
	protected function make_singular( $post_id ) {
		global $wp_query;

		$this->previous_query = [
			'is_singular'       => $wp_query->is_singular,
			'queried_object'    => $wp_query->get_queried_object(),
			'queried_object_id' => $wp_query->get_queried_object_id(),
		];

		$wp_query->is_singular       = true;
		$wp_query->queried_object    = get_post( $post_id );
		$wp_query->queried_object_id = $post_id;
	}

	/**
	 * Make did_action( 'wp_head' ) report a completed head pass.
	 *
	 * The action counter is driven directly rather than firing `wp_head` for
	 * real: doing that would run every registered head callback and echo a
	 * document head into the test output. This is the one piece of state
	 * `reset_printed_assets()` reads, so it is the only piece worth faking.
	 *
	 * @return void
	 */
	protected function mark_wp_head_as_fired() {
		if ( did_action( 'wp_head' ) ) {
			return;
		}

		$GLOBALS['wp_actions']['wp_head'] = 1;
		$this->faked_wp_head              = true;
	}

	/**
	 * Undo everything a test registered.
	 */
	protected function tearDown(): void {
		wp_styles()->done  = [];
		wp_scripts()->done = [];

		wp_deregister_style( 'srfm-test-style' );
		wp_deregister_script( 'srfm-test-footer-script' );

		if ( $this->faked_wp_head ) {
			unset( $GLOBALS['wp_actions']['wp_head'] );
			$this->faked_wp_head = false;
		}

		if ( ! empty( $this->previous_query ) ) {
			global $wp_query;
			$wp_query->is_singular       = $this->previous_query['is_singular'];
			$wp_query->queried_object    = $this->previous_query['queried_object'];
			$wp_query->queried_object_id = $this->previous_query['queried_object_id'];
			$this->previous_query        = [];
		}

		foreach ( $this->created_posts as $post_id ) {
			wp_delete_post( $post_id, true );
		}
		$this->created_posts = [];

		parent::tearDown();
	}
}
