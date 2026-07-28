<?php
/**
 * Class Test_Generate_Form_Markup
 *
 * @package sureforms
 */

use Yoast\PHPUnitPolyfills\TestCases\TestCase;
use SRFM\Inc\Generate_Form_Markup;

class Test_Generate_Form_Markup extends TestCase {

	protected $generate_form_markup;

	protected function setUp(): void {
		$this->generate_form_markup = new Generate_Form_Markup();
	}

	public function test_get_form_markup_empty_id() {
		$result = Generate_Form_Markup::get_form_markup( 0 );
		$this->assertIsString( $result );
	}

	public function test_get_form_markup_invalid_id() {
		$result = Generate_Form_Markup::get_form_markup( 999999 );
		$this->assertIsString( $result );
	}

	public function test_get_form_markup_with_valid_form() {
		if ( ! defined( 'SRFM_FORMS_POST_TYPE' ) ) {
			$this->markTestSkipped( 'SRFM_FORMS_POST_TYPE not defined' );
		}

		remove_all_actions( 'wp_insert_post_data' );

		$form_id = wp_insert_post( [
			'post_title'   => 'Test Markup Form',
			'post_type'    => SRFM_FORMS_POST_TYPE,
			'post_status'  => 'publish',
			'post_content' => 'simple content',
		] );

		$result = Generate_Form_Markup::get_form_markup( $form_id );
		$this->assertIsString( $result );

		wp_delete_post( $form_id, true );
	}

	public function test_add_entries_admin_bar_node() {
		if ( ! defined( 'SRFM_FORMS_POST_TYPE' ) || ! defined( 'SRFM_ENTRIES' ) ) {
			$this->markTestSkipped( 'SureForms constants not defined' );
		}

		// Only users who can view entries (manage_options) get the node.
		$admin = wp_insert_user(
			[
				'user_login' => 'srfm_entries_admin_' . wp_rand(),
				'user_pass'  => 'password',
				'user_email' => 'srfm_entries_admin_' . wp_rand() . '@example.com',
				'role'       => 'administrator',
			]
		);
		wp_set_current_user( is_wp_error( $admin ) ? 0 : (int) $admin );
		add_filter( 'show_admin_bar', '__return_true' );

		remove_all_actions( 'wp_insert_post_data' );
		$form_id = wp_insert_post(
			[
				'post_title'  => 'Admin Bar Entries Form',
				'post_type'   => SRFM_FORMS_POST_TYPE,
				'post_status' => 'publish',
			]
		);

		// The node shows only on the form's own Instant Form page, so enable
		// Instant Form and simulate being on that singular form page.
		update_post_meta( $form_id, '_srfm_instant_form_settings', [ 'enable_instant_form' => true ] );

		global $wp_query, $post;
		$prev_is_singular    = isset( $wp_query->is_singular ) ? $wp_query->is_singular : false;
		$prev_queried_object = $wp_query->get_queried_object();
		$prev_queried_id     = get_queried_object_id();
		$prev_post           = $post;

		$post                        = get_post( $form_id );
		$wp_query->is_singular       = true;
		$wp_query->queried_object    = $post;
		$wp_query->queried_object_id = $form_id;

		require_once ABSPATH . 'wp-includes/class-wp-admin-bar.php';
		$bar = new WP_Admin_Bar();
		$this->generate_form_markup->add_entries_admin_bar_node( $bar );

		$node = $bar->get_node( 'srfm-entries' );
		$this->assertNotNull( $node, 'Entries node should be added on the Instant Form page.' );
		$this->assertStringContainsString( 'page=' . SRFM_ENTRIES, $node->href );
		$this->assertStringContainsString( 'form=' . $form_id, $node->href );

		// With Instant Form disabled, the same singular page shows no node.
		update_post_meta( $form_id, '_srfm_instant_form_settings', [ 'enable_instant_form' => false ] );
		$bar_disabled = new WP_Admin_Bar();
		$this->generate_form_markup->add_entries_admin_bar_node( $bar_disabled );
		$this->assertNull( $bar_disabled->get_node( 'srfm-entries' ), 'No node when Instant Form is disabled for the form.' );

		// Re-enable, then confirm a subscriber (no manage_options) gets no node.
		update_post_meta( $form_id, '_srfm_instant_form_settings', [ 'enable_instant_form' => true ] );
		$subscriber = wp_insert_user(
			[
				'user_login' => 'srfm_entries_sub_' . wp_rand(),
				'user_pass'  => 'password',
				'user_email' => 'srfm_entries_sub_' . wp_rand() . '@example.com',
				'role'       => 'subscriber',
			]
		);
		wp_set_current_user( is_wp_error( $subscriber ) ? 0 : (int) $subscriber );
		$bar_sub = new WP_Admin_Bar();
		$this->generate_form_markup->add_entries_admin_bar_node( $bar_sub );
		$this->assertNull( $bar_sub->get_node( 'srfm-entries' ), 'Users without the entries capability must not see the node.' );

		// Restore query/post globals and clean up.
		$wp_query->is_singular       = $prev_is_singular;
		$wp_query->queried_object    = $prev_queried_object;
		$wp_query->queried_object_id = $prev_queried_id;
		$post                        = $prev_post;

		remove_filter( 'show_admin_bar', '__return_true' );
		wp_delete_post( $form_id, true );
		wp_set_current_user( 0 );
		if ( ! is_wp_error( $admin ) ) {
			wp_delete_user( (int) $admin );
		}
		if ( ! is_wp_error( $subscriber ) ) {
			wp_delete_user( (int) $subscriber );
		}
	}

	public function test_register_custom_endpoint() {
		do_action( 'rest_api_init' );
		$routes = rest_get_server()->get_routes();
		$found = false;
		foreach ( array_keys( $routes ) as $route ) {
			if ( strpos( $route, 'sureforms/v1/generate-form-markup' ) !== false ) {
				$found = true;
				break;
			}
		}
		$this->assertTrue( $found, 'The generate-form-markup endpoint should be registered' );
	}

	/**
	 * Test get_confirmation_markup returns string.
	 */
	public function test_get_confirmation_markup() {
		$result = Generate_Form_Markup::get_confirmation_markup();
		$this->assertIsString( $result );
	}

	/**
	 * Test get_current_block_attrs returns an array.
	 */
	public function test_get_current_block_attrs_returns_array() {
		$result = Generate_Form_Markup::get_current_block_attrs();
		$this->assertIsArray( $result );
	}

	/**
	 * Test enqueue_preview_styling_script enqueues the script.
	 */
	public function test_enqueue_preview_styling_script_enqueues_script() {
		Generate_Form_Markup::enqueue_preview_styling_script( '#srfm-container-1' );
		$this->assertTrue( wp_script_is( 'srfm-preview-styling', 'enqueued' ) );
		wp_dequeue_script( 'srfm-preview-styling' );
	}

	/**
	 * Test common_error_message outputs error markup.
	 */
	public function test_common_error_message_outputs_markup() {
		ob_start();
		Generate_Form_Markup::common_error_message();
		$output = ob_get_clean();
		$this->assertStringContainsString( 'srfm-error-message', $output );
		$this->assertStringContainsString( 'srfm-footer-error', $output );
	}

	public function test_get_form_markup_returns_string() {
		remove_all_actions( 'wp_insert_post_data' );

		$form_id = wp_insert_post( [
			'post_title'   => 'String Test Form',
			'post_type'    => 'sureforms_form',
			'post_status'  => 'publish',
			'post_content' => '',
		] );

		$result = Generate_Form_Markup::get_form_markup( $form_id );
		$this->assertIsString( $result );

		wp_delete_post( $form_id, true );
	}

	/**
	 * Test form markup contains the HMAC submit token attribute.
	 */
	public function test_form_markup_contains_submit_token() {
		remove_all_actions( 'wp_insert_post_data' );

		$form_id = wp_insert_post( [
			'post_title'   => 'Token Markup Test',
			'post_type'    => 'sureforms_form',
			'post_status'  => 'publish',
			'post_content' => '',
		] );

		$markup = Generate_Form_Markup::get_form_markup( $form_id );
		$this->assertStringContainsString( 'data-submit-token=', $markup, 'Form markup should contain data-submit-token attribute.' );

		wp_delete_post( $form_id, true );
	}

	/**
	 * Test form markup does not contain old nonce attributes.
	 */
	public function test_form_markup_does_not_contain_old_nonce_attributes() {
		remove_all_actions( 'wp_insert_post_data' );

		$form_id = wp_insert_post( [
			'post_title'   => 'No Nonce Markup Test',
			'post_type'    => 'sureforms_form',
			'post_status'  => 'publish',
			'post_content' => '',
		] );

		$markup = Generate_Form_Markup::get_form_markup( $form_id );
		$this->assertStringNotContainsString( 'data-nonce=', $markup, 'Form markup should not contain old data-nonce attribute.' );
		$this->assertStringNotContainsString( 'data-update-nonce=', $markup, 'Form markup should not contain old data-update-nonce attribute.' );

		wp_delete_post( $form_id, true );
	}

	/**
	 * Test get_redirect_url returns empty string when form_data is empty.
	 */
	public function test_get_redirect_url_empty_form_data() {
		$result = Generate_Form_Markup::get_redirect_url();
		$this->assertSame( '', $result );
	}

	/**
	 * Test get_redirect_url returns empty string when no confirmation meta exists.
	 */
	public function test_get_redirect_url_no_confirmation_meta() {
		remove_all_actions( 'wp_insert_post_data' );

		$form_id = wp_insert_post( [
			'post_title'  => 'Redirect URL Test Form',
			'post_type'   => 'sureforms_form',
			'post_status' => 'publish',
		] );

		$result = Generate_Form_Markup::get_redirect_url( [ 'form-id' => $form_id ] );
		$this->assertSame( '', $result );

		wp_delete_post( $form_id, true );
	}

	/**
	 * Test get_redirect_url returns page URL for "different page" confirmation type.
	 */
	public function test_get_redirect_url_different_page() {
		remove_all_actions( 'wp_insert_post_data' );

		$form_id = wp_insert_post( [
			'post_title'  => 'Redirect Page Test',
			'post_type'   => 'sureforms_form',
			'post_status' => 'publish',
		] );

		$confirmation = [
			[
				'confirmation_type' => 'different page',
				'page_url'          => 'https://example.com/thank-you',
				'custom_url'        => '',
			],
		];
		update_post_meta( $form_id, '_srfm_form_confirmation', $confirmation );

		$result = Generate_Form_Markup::get_redirect_url( [ 'form-id' => $form_id ] );
		$this->assertSame( 'https://example.com/thank-you', $result );

		wp_delete_post( $form_id, true );
	}

	/**
	 * Test get_redirect_url returns custom URL for "custom url" confirmation type.
	 */
	public function test_get_redirect_url_custom_url() {
		remove_all_actions( 'wp_insert_post_data' );

		$form_id = wp_insert_post( [
			'post_title'  => 'Redirect Custom URL Test',
			'post_type'   => 'sureforms_form',
			'post_status' => 'publish',
		] );

		$confirmation = [
			[
				'confirmation_type' => 'custom url',
				'page_url'          => '',
				'custom_url'        => 'https://example.com/custom-redirect',
			],
		];
		update_post_meta( $form_id, '_srfm_form_confirmation', $confirmation );

		$result = Generate_Form_Markup::get_redirect_url( [ 'form-id' => $form_id ] );
		$this->assertSame( 'https://example.com/custom-redirect', $result );

		wp_delete_post( $form_id, true );
	}

	/**
	 * Test get_redirect_url appends query params when enabled.
	 */
	public function test_get_redirect_url_with_query_params() {
		remove_all_actions( 'wp_insert_post_data' );

		$form_id = wp_insert_post( [
			'post_title'  => 'Redirect Query Params Test',
			'post_type'   => 'sureforms_form',
			'post_status' => 'publish',
		] );

		$confirmation = [
			[
				'confirmation_type'    => 'custom url',
				'custom_url'           => 'https://example.com/result',
				'page_url'             => '',
				'enable_query_params'  => true,
				'query_params'         => [
					[ 'status' => 'submitted' ],
					[ 'ref' => 'form' ],
				],
			],
		];
		update_post_meta( $form_id, '_srfm_form_confirmation', $confirmation );

		$result = Generate_Form_Markup::get_redirect_url( [ 'form-id' => $form_id ] );
		$this->assertStringContainsString( 'status=submitted', $result );
		$this->assertStringContainsString( 'ref=form', $result );

		wp_delete_post( $form_id, true );
	}

	/**
	 * Test get_redirect_url returns URL without query params when disabled.
	 */
	public function test_get_redirect_url_query_params_disabled() {
		remove_all_actions( 'wp_insert_post_data' );

		$form_id = wp_insert_post( [
			'post_title'  => 'Redirect No Params Test',
			'post_type'   => 'sureforms_form',
			'post_status' => 'publish',
		] );

		$confirmation = [
			[
				'confirmation_type'    => 'custom url',
				'custom_url'           => 'https://example.com/result',
				'page_url'             => '',
				'enable_query_params'  => false,
				'query_params'         => [
					[ 'status' => 'submitted' ],
				],
			],
		];
		update_post_meta( $form_id, '_srfm_form_confirmation', $confirmation );

		$result = Generate_Form_Markup::get_redirect_url( [ 'form-id' => $form_id ] );
		$this->assertStringNotContainsString( 'status=submitted', $result );
		$this->assertSame( 'https://example.com/result', $result );

		wp_delete_post( $form_id, true );
	}

	/**
	 * Test get_google_captcha_script renders the widget + enqueues the right
	 * Google script per version, and falls back to the missing-sitekey error.
	 */
	public function test_get_google_captcha_script() {
		// Empty site key => missing-sitekey error, no widget rendered.
		ob_start();
		Generate_Form_Markup::get_google_captcha_script( 'v2-checkbox', '' );
		$empty_output = ob_get_clean();
		$this->assertStringContainsString( 'sitekey-error', $empty_output );
		$this->assertStringNotContainsString( 'g-recaptcha', $empty_output );

		// v2-checkbox => g-recaptcha widget carrying the site key, and the
		// google-recaptcha script enqueued.
		ob_start();
		Generate_Form_Markup::get_google_captcha_script( 'v2-checkbox', 'test-site-key-v2' );
		$v2_output = ob_get_clean();
		$this->assertStringContainsString( 'g-recaptcha', $v2_output );
		$this->assertStringContainsString( 'test-site-key-v2', $v2_output );
		$this->assertTrue( wp_script_is( 'google-recaptcha', 'enqueued' ) );
		wp_dequeue_script( 'google-recaptcha' );

		// v3 => the dedicated v3 handle is enqueued.
		ob_start();
		Generate_Form_Markup::get_google_captcha_script( 'v3-reCAPTCHA', 'test-site-key-v3' );
		ob_get_clean();
		$this->assertTrue( wp_script_is( 'srfm-google-recaptchaV3', 'enqueued' ) );
		wp_dequeue_script( 'srfm-google-recaptchaV3' );
	}

	/**
	 * Test get_cf_turnstile_script renders the Turnstile widget + enqueues the
	 * Cloudflare script, and falls back to the missing-sitekey error.
	 */
	public function test_get_cf_turnstile_script() {
		// The Turnstile enqueue passes a legacy $args shape that WordPress trunk
		// flags via _doing_it_wrong (a PHP notice that PHPUnit would convert to a
		// failure). Suppress just the triggered notice for the duration of this
		// test so the rendered markup can still be asserted.
		add_filter( 'doing_it_wrong_trigger_error', '__return_false' );

		// Empty site key => missing-sitekey error, no widget.
		ob_start();
		Generate_Form_Markup::get_cf_turnstile_script( 'light', '' );
		$empty_output = ob_get_clean();
		$this->assertStringContainsString( 'sitekey-error', $empty_output );
		$this->assertStringNotContainsString( 'cf-turnstile', $empty_output );

		// Valid site key => cf-turnstile widget with the key + appearance mode,
		// and the Cloudflare Turnstile script enqueued.
		ob_start();
		Generate_Form_Markup::get_cf_turnstile_script( 'dark', 'test-turnstile-key' );
		$output = ob_get_clean();
		$this->assertStringContainsString( 'cf-turnstile', $output );
		$this->assertStringContainsString( 'test-turnstile-key', $output );
		$this->assertStringContainsString( 'dark', $output );
		$this->assertTrue( wp_script_is( SRFM_SLUG . '-cf-turnstile', 'enqueued' ) );
		wp_dequeue_script( SRFM_SLUG . '-cf-turnstile' );

		remove_filter( 'doing_it_wrong_trigger_error', '__return_false' );
	}

	/**
	 * Create a published form with optional Custom CSS / disable-styling meta.
	 *
	 * @param string $custom_css Custom CSS meta value.
	 * @param bool   $disable    Whether default styling is disabled.
	 * @return int Form ID.
	 */
	private function make_form_with_styling( $custom_css = '', $disable = false ) {
		remove_all_actions( 'wp_insert_post_data' );

		$form_id = wp_insert_post(
			[
				'post_title'   => 'Styling test form',
				'post_type'    => SRFM_FORMS_POST_TYPE,
				'post_status'  => 'publish',
				'post_content' => 'simple content',
			]
		);

		if ( '' !== $custom_css ) {
			update_post_meta( $form_id, '_srfm_form_custom_css', $custom_css );
		}
		if ( $disable ) {
			update_post_meta( $form_id, '_srfm_forms_styling', [ 'disable_default_styles' => true ] );
		}

		return $form_id;
	}

	/**
	 * Custom CSS appears exactly once on embedded views and is NOT emitted by
	 * get_form_markup() on the form's own single/instant view — there
	 * templates/single-form.php owns the (head, unscoped) output, so a second
	 * copy here would duplicate it.
	 */
	public function test_custom_css_once_embedded_and_absent_on_single_view() {
		$css     = '.srfm-test-marker { color: red; }';
		$form_id = $this->make_form_with_styling( $css );

		// Embedded context: global post is not the form.
		$GLOBALS['post'] = null;
		$markup          = Generate_Form_Markup::get_form_markup( $form_id );
		$this->assertSame( 1, substr_count( $markup, '.srfm-test-marker' ), 'Embedded markup must contain the Custom CSS exactly once.' );

		// Single/instant view context: the form itself is the queried post.
		$GLOBALS['post'] = get_post( $form_id );
		$markup          = Generate_Form_Markup::get_form_markup( $form_id );
		$this->assertStringNotContainsString( '.srfm-test-marker', $markup, 'On the single view the template outputs the Custom CSS; the markup must not duplicate it.' );

		$GLOBALS['post'] = null;
		wp_delete_post( $form_id, true );
	}

	/**
	 * Custom CSS still applies when default styling is disabled, the marker
	 * class renders, and the inline --srfm-* variable block is skipped.
	 */
	public function test_custom_css_and_marker_class_when_styling_disabled() {
		$css     = '.srfm-test-marker { color: red; }';
		$form_id = $this->make_form_with_styling( $css, true );

		$GLOBALS['post'] = null;
		$markup          = Generate_Form_Markup::get_form_markup( $form_id );

		$this->assertStringContainsString( '.srfm-test-marker', $markup );
		$this->assertStringContainsString( 'srfm-styling-none', $markup );
		$this->assertStringNotContainsString( '--srfm-color-scheme-primary', $markup );

		wp_delete_post( $form_id, true );
	}

	/**
	 * With default styling enabled the inline variable block renders and the
	 * marker class is absent.
	 */
	public function test_inline_variables_present_when_styling_enabled() {
		$form_id = $this->make_form_with_styling();

		$GLOBALS['post'] = null;
		$markup          = Generate_Form_Markup::get_form_markup( $form_id );

		$this->assertStringContainsString( '--srfm-color-scheme-primary', $markup );
		$this->assertStringNotContainsString( 'srfm-styling-none', $markup );

		wp_delete_post( $form_id, true );
	}

	/**
	 * When styling is disabled and there is no Custom CSS, no style tag is
	 * emitted at all (no empty scoped ruleset).
	 */
	public function test_no_style_tag_when_disabled_without_custom_css() {
		$form_id = $this->make_form_with_styling( '', true );

		$GLOBALS['post'] = null;
		$markup          = Generate_Form_Markup::get_form_markup( $form_id );

		$this->assertStringNotContainsString( '<style', $markup );
		$this->assertStringContainsString( 'srfm-styling-none', $markup );

		wp_delete_post( $form_id, true );
	}
}
