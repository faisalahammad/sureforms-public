<?php
/**
 * Class Test_Form_Submit
 *
 * @package sureforms
 */

use Yoast\PHPUnitPolyfills\TestCases\TestCase;

use SRFM\Inc\Database\Tables\Entries as EntriesTable;
use SRFM\Inc\Form_Submit;
use SRFM\Inc\Helper;
use SRFM\Inc\Submit_Token;

/**
 * Tests Plugin Initialization.
 *
 */
class Test_Form_Submit extends TestCase {

	protected $form_submit;

	protected function setUp(): void {
		$this->form_submit = new Form_Submit();
	}

	/**
	 * Test recaptcha_error_message method.
	 * This function tests various scenarios for the recaptcha_error_message method.
	 * It includes cases where:
	 * 1. No error code provided.
	 * 2. Error code provided and captcha type is g-recaptcha.
	 * 3. Error code provided and captcha type is hcaptcha.
	 * 4. Error code provided and captcha type is cf-turnstile.
	 * 5. Error code provided and captcha type is unknown.
	 *
	 */
	public function test_recaptcha_error_message() {

		// Test case 1: No error code provided
		$api_response = [];
		$expected = [
			'detail_message' => 'Captcha validation failed. No error code provided.',
			'message'        => 'Captcha validation failed.',
		];
		$result = $this->form_submit->recaptcha_error_message('g-recaptcha', $api_response);
		$this->assertEquals($expected, $result, 'Failed asserting when no error code provided.');

		// Test case 2: g-recaptcha
		$api_response = [ 'error-codes' => [ 'missing-input-secret' ] ];
		$expected = [
			'log_message' => 'Google reCAPTCHA: The secret parameter is missing. <br> Error Code: missing-input-secret',
			'message'     => 'Google reCAPTCHA verification failed. Please contact your site administrator.',
		];
		$result = $this->form_submit->recaptcha_error_message('g-recaptcha', $api_response);
		$this->assertEquals($expected, $result, 'Failed asserting for g-recaptcha error.');

		// Test case 3: hcaptcha
		$api_response = [ 'error-codes' => [ 'invalid-input-secret' ] ];
		$expected = [
			'log_message' => 'hCaptcha: Your secret key is invalid or malformed. <br> Error Code: invalid-input-secret',
			'message'     => 'hCaptcha verification failed. Please contact your site administrator.',
		];
		$result = $this->form_submit->recaptcha_error_message('hcaptcha', $api_response);
		$this->assertEquals($expected, $result, 'Failed asserting for hcaptcha error.');

		// Test case 4: cf-turnstile
		$api_response = [ 'error-codes' => [ 'timeout-or-duplicate' ] ];
		$expected = [
			'log_message' => 'Cloudflare Turnstile: The response parameter (token) has already been validated before. This means that the token was issued five minutes ago and is no longer valid, or it was already redeemed. <br> Error Code: timeout-or-duplicate',
			'message'     => 'Cloudflare Turnstile verification failed. Please contact your site administrator.',
		];
		$result = $this->form_submit->recaptcha_error_message('cf-turnstile', $api_response);
		$this->assertEquals($expected, $result, 'Failed asserting for cf-turnstile error.');

		// Test case 5: Unknown captcha type
		$api_response = [ 'error-codes' => [ 'missing-input-response' ] ];
		$expected = [
			'log_message' => 'Unknown Captcha: Invalid captcha type. <br> Error Code: missing-input-response',
			'message'     => 'Unknown Captcha verification failed. Please contact your site administrator.',
		];
		$result = $this->form_submit->recaptcha_error_message('unknown-captcha', $api_response);
		$this->assertEquals($expected, $result, 'Failed asserting for unknown captcha type.');
	}

	/**
	 * Test process_form_fields method.
	 */
	public function test_process_form_fields() {

		// Test case 1: Valid sureforms fields with -lbl-
		$form_data = [
			'text-lbl-field-name' => 'John Doe',
			'email-lbl-field-email' => 'john@example.com'
		];
		$expected = [
			'text-lbl-field-name' => 'John Doe',
			'email-lbl-field-email' => 'john@example.com'
		];
		$result = $this->call_private_method($this->form_submit, 'process_form_fields', [$form_data]);
		$this->assertEquals($expected, $result);

		// Test case 2: Form data without -lbl- fields (should be filtered out)
		$form_data = [
			'form-id' => '123',
			'nonce' => 'test_nonce',
			'regular_field' => 'value'
		];
		$result = $this->call_private_method($this->form_submit, 'process_form_fields', [$form_data]);
		$this->assertEquals([], $result);

		// Test case 3: Array values (like file uploads)
		$form_data = [
			'upload-lbl-field-files' => ['file1.jpg', 'file2.pdf']
		];
		$expected = [
			'upload-lbl-field-files' => ['file1.jpg', 'file2.pdf']
		];
		$result = $this->call_private_method($this->form_submit, 'process_form_fields', [$form_data]);
		$this->assertEquals($expected, $result);

		// Test case 4: HTML special characters sanitization
		$form_data = [
			'text-lbl-field-content' => '<script>alert("xss")</script>'
		];
		$expected = [
			'text-lbl-field-content' => '&lt;script&gt;alert(&quot;xss&quot;)&lt;/script&gt;'
		];
		$result = $this->call_private_method($this->form_submit, 'process_form_fields', [$form_data]);
		$this->assertEquals($expected, $result);
	}

	/**
	 * Test validate_turnstile_token with empty secret key.
	 */
	public function test_validate_turnstile_token_empty_secret_key() {
		$result = Form_Submit::validate_turnstile_token( '', 'some-response', '127.0.0.1' );
		$this->assertIsArray( $result );
		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'secret key is invalid', $result['error'] );
	}

	/**
	 * Test validate_turnstile_token with non-string secret key.
	 */
	public function test_validate_turnstile_token_non_string_secret() {
		$result = Form_Submit::validate_turnstile_token( 123, 'some-response', '127.0.0.1' );
		$this->assertIsArray( $result );
		$this->assertFalse( $result['success'] );
	}

	/**
	 * Test validate_turnstile_token with empty response.
	 */
	public function test_validate_turnstile_token_empty_response() {
		$result = Form_Submit::validate_turnstile_token( 'valid-secret', '', '127.0.0.1' );
		$this->assertIsArray( $result );
		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'response is missing', $result['error'] );
	}

	/**
	 * Test validate_hcaptcha_token with empty secret key.
	 */
	public function test_validate_hcaptcha_token_empty_secret_key() {
		$result = Form_Submit::validate_hcaptcha_token( '', 'some-response', '127.0.0.1' );
		$this->assertIsArray( $result );
		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'secret key is invalid', $result['error'] );
	}

	/**
	 * Test validate_hcaptcha_token with non-string secret key.
	 */
	public function test_validate_hcaptcha_token_non_string_secret() {
		$result = Form_Submit::validate_hcaptcha_token( 456, 'some-response', '127.0.0.1' );
		$this->assertIsArray( $result );
		$this->assertFalse( $result['success'] );
	}

	/**
	 * Test validate_hcaptcha_token with empty response.
	 */
	public function test_validate_hcaptcha_token_empty_response() {
		$result = Form_Submit::validate_hcaptcha_token( 'valid-secret', '', '127.0.0.1' );
		$this->assertIsArray( $result );
		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'response is missing', $result['error'] );
	}

	/**
	 * Test validate_hcaptcha_token with false response.
	 */
	public function test_validate_hcaptcha_token_false_response() {
		$result = Form_Submit::validate_hcaptcha_token( 'valid-secret', false, '127.0.0.1' );
		$this->assertIsArray( $result );
		$this->assertFalse( $result['success'] );
	}

	/**
	 * Test validate_turnstile_token with false response.
	 */
	public function test_validate_turnstile_token_false_response() {
		$result = Form_Submit::validate_turnstile_token( 'valid-secret', false, '127.0.0.1' );
		$this->assertIsArray( $result );
		$this->assertFalse( $result['success'] );
	}

	/**
	 * Test recaptcha_error_message with multiple error codes returns first.
	 */
	public function test_recaptcha_error_message_multiple_error_codes() {
		$api_response = [ 'error-codes' => [ 'bad-request', 'timeout-or-duplicate' ] ];
		$result = $this->form_submit->recaptcha_error_message( 'g-recaptcha', $api_response );
		$this->assertArrayHasKey( 'log_message', $result );
		$this->assertStringContainsString( 'bad-request', $result['log_message'] );
	}

	/**
	 * Test recaptcha_error_message with empty error-codes array.
	 */
	public function test_recaptcha_error_message_empty_error_codes_array() {
		$api_response = [ 'error-codes' => [] ];
		$result = $this->form_submit->recaptcha_error_message( 'g-recaptcha', $api_response );
		$this->assertArrayHasKey( 'detail_message', $result );
		$this->assertStringContainsString( 'No error code provided', $result['detail_message'] );
	}

	/**
	 * Test recaptcha_error_message with non-array error-codes.
	 */
	public function test_recaptcha_error_message_non_array_error_codes() {
		$api_response = [ 'error-codes' => 'string-value' ];
		$result = $this->form_submit->recaptcha_error_message( 'g-recaptcha', $api_response );
		$this->assertArrayHasKey( 'detail_message', $result );
		$this->assertStringContainsString( 'No error code provided', $result['detail_message'] );
	}

	/**
	 * Test process_form_fields with empty array.
	 */
	public function test_process_form_fields_empty_array() {
		$result = $this->call_private_method( $this->form_submit, 'process_form_fields', [ [] ] );
		$this->assertIsArray( $result );
		$this->assertEmpty( $result );
	}

	/**
	 * Test process_form_fields with mixed valid and invalid keys.
	 */
	public function test_process_form_fields_mixed_keys() {
		$form_data = [
			'text-lbl-field-name' => 'John',
			'form-id'             => '123',
			'email-lbl-field-email' => 'test@test.com',
			'nonce'               => 'abc',
		];
		$result = $this->call_private_method( $this->form_submit, 'process_form_fields', [ $form_data ] );
		$this->assertCount( 2, $result );
	}

	/**
	 * Test prepare_submission_data with basic fields.
	 */
	public function test_prepare_submission_data_basic() {
		$submission_data = [
			'srfm-text-abc123-lbl-first-name' => 'John Doe',
		];
		$result = $this->form_submit->prepare_submission_data( $submission_data );
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'name', $result );
		$this->assertEquals( 'John Doe', $result['name'] );
	}

	/**
	 * Test prepare_submission_data with upload field arrays.
	 */
	public function test_prepare_submission_data_upload_field() {
		$submission_data = [
			'srfm-upload-abc123-lbl-upload-resume' => [ 'https://example.com/file1.pdf', 'https://example.com/file2.pdf' ],
		];
		$result = $this->form_submit->prepare_submission_data( $submission_data );
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'resume', $result );
		$this->assertStringContainsString( ',', $result['resume'] );
	}

	/**
	 * Test prepare_submission_data with empty submission data.
	 */
	public function test_prepare_submission_data_empty() {
		$result = $this->form_submit->prepare_submission_data( [] );
		$this->assertIsArray( $result );
		$this->assertEmpty( $result );
	}

	/**
	 * Test prepare_submission_data properly decodes rawurlencode'd upload URLs.
	 */
	public function test_prepare_submission_data_upload_field_decodes_encoded_urls() {
		$submission_data = [
			'srfm-upload-abc123-lbl-upload-resume' => [
				rawurlencode( 'https://example.com/uploads/my file.pdf' ),
				rawurlencode( 'https://example.com/uploads/doc (2).pdf' ),
			],
		];
		$result = $this->form_submit->prepare_submission_data( $submission_data );
		$this->assertArrayHasKey( 'resume', $result );
		$this->assertSame( 'https://example.com/uploads/my file.pdf, https://example.com/uploads/doc (2).pdf', $result['resume'] );
	}

	/**
	 * Test handle_form_submission rejects when honeypot is enabled but field is missing.
	 */
	public function test_handle_form_submission_rejects_missing_honeypot_when_enabled() {
		$form_id = wp_insert_post(
			[
				'post_type'   => 'sureforms_form',
				'post_status' => 'publish',
				'post_title'  => 'Honeypot Test Form',
			]
		);

		update_post_meta( $form_id, '_srfm_captcha_security_type', 'none' );
		update_option(
			'srfm_security_settings_options',
			[ 'srfm_honeypot' => true ]
		);

		$request = new \WP_REST_Request( 'POST', '/sureforms/v1/submit-form' );
		$request->set_body_params(
			[
				'form-id' => (string) $form_id,
				// Intentionally omitting srfm-honeypot-field to simulate bot stripping it.
			]
		);

		ob_start();
		try {
			$this->form_submit->handle_form_submission( $request );
		} catch ( \WPDieException $e ) {
			$output = ob_get_clean();
			$data   = json_decode( $output, true );
			$this->assertIsArray( $data );
			$this->assertFalse( $data['success'] );
			$this->assertStringContainsString( 'spam', $data['data']['message'] );

			// Cleanup.
			wp_delete_post( $form_id, true );
			delete_option( 'srfm_security_settings_options' );
			return;
		}
		ob_end_clean();

		// Cleanup.
		wp_delete_post( $form_id, true );
		delete_option( 'srfm_security_settings_options' );
		$this->fail( 'Expected WPDieException was not thrown for missing honeypot field.' );
	}

	/**
	 * Test handle_form_submission allows submission when honeypot is disabled and field is absent.
	 */
	public function test_handle_form_submission_allows_missing_honeypot_when_disabled() {
		$form_id = wp_insert_post(
			[
				'post_type'   => 'sureforms_form',
				'post_status' => 'publish',
				'post_title'  => 'Honeypot Disabled Test Form',
			]
		);

		update_post_meta( $form_id, '_srfm_captcha_security_type', 'none' );
		update_option(
			'srfm_security_settings_options',
			[ 'srfm_honeypot' => false ]
		);

		$request = new \WP_REST_Request( 'POST', '/sureforms/v1/submit-form' );
		$request->set_body_params(
			[
				'form-id' => (string) $form_id,
				// No honeypot field — should be allowed since honeypot is disabled.
			]
		);

		ob_start();
		try {
			$result = $this->form_submit->handle_form_submission( $request );
		} catch ( \WPDieException $e ) {
			$output = ob_get_clean();
			$data   = json_decode( $output, true );

			// If it dies, it should NOT be the spam message — it could be a captcha or entry processing error.
			if ( is_array( $data ) && isset( $data['data']['message'] ) ) {
				$this->assertStringNotContainsString( 'spam', $data['data']['message'], 'Should not reject as spam when honeypot is disabled.' );
			}

			// Cleanup.
			wp_delete_post( $form_id, true );
			delete_option( 'srfm_security_settings_options' );
			return;
		}
		ob_end_clean();

		// If we reach here, form processed successfully (no die) — that's the expected behavior.
		wp_delete_post( $form_id, true );
		delete_option( 'srfm_security_settings_options' );
	}

	/**
	 * Test parse_email_notification_template returns expected structure.
	 */
	public function test_parse_email_notification_template() {
		$submission_data = [
			'srfm-text-abc123-lbl-first-name' => 'John Doe',
		];
		$item = [
			'email_to'   => 'admin@example.com',
			'subject'    => 'New Submission',
			'email_body' => '<p>Hello World</p>',
		];

		$result = Form_Submit::parse_email_notification_template( $submission_data, $item );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'to', $result );
		$this->assertArrayHasKey( 'subject', $result );
		$this->assertArrayHasKey( 'message', $result );
		$this->assertArrayHasKey( 'headers', $result );
		$this->assertEquals( 'admin@example.com', $result['to'] );
		$this->assertEquals( 'New Submission', $result['subject'] );
		$this->assertStringContainsString( 'Hello World', $result['message'] );
		$this->assertStringContainsString( 'Content-Type: text/html', $result['headers'] );
	}

	/**
	 * Test submit_form_permissions_check rejects request with missing token.
	 */
	public function test_submit_form_permissions_check_rejects_missing_token() {
		$form_id = wp_insert_post(
			[
				'post_type'   => 'sureforms_form',
				'post_status' => 'publish',
				'post_title'  => 'Token Test Form',
			]
		);

		$request = new \WP_REST_Request( 'POST', '/sureforms/v1/submit-form' );
		$request->set_body_params( [ 'form-id' => (string) $form_id ] );

		$result = $this->form_submit->submit_form_permissions_check( $request );
		$this->assertInstanceOf( \WP_Error::class, $result, 'Missing token should return WP_Error.' );
		$this->assertSame( 'srfm_token_invalid', $result->get_error_code() );

		wp_delete_post( $form_id, true );
	}

	/**
	 * Test submit_form_permissions_check rejects request with invalid token.
	 */
	public function test_submit_form_permissions_check_rejects_invalid_token() {
		$form_id = wp_insert_post(
			[
				'post_type'   => 'sureforms_form',
				'post_status' => 'publish',
				'post_title'  => 'Token Test Form',
			]
		);

		$request = new \WP_REST_Request( 'POST', '/sureforms/v1/submit-form' );
		$request->set_body_params( [ 'form-id' => (string) $form_id ] );
		$request->set_header( 'X-WP-Submit-Token', 'invalid-token-value' );

		$result = $this->form_submit->submit_form_permissions_check( $request );
		$this->assertInstanceOf( \WP_Error::class, $result, 'Invalid token should return WP_Error.' );
		$this->assertSame( 'srfm_token_invalid', $result->get_error_code() );

		wp_delete_post( $form_id, true );
	}

	/**
	 * Test submit_form_permissions_check accepts a valid HMAC token.
	 */
	public function test_submit_form_permissions_check_accepts_valid_token() {
		$form_id = wp_insert_post(
			[
				'post_type'   => 'sureforms_form',
				'post_status' => 'publish',
				'post_title'  => 'Token Test Form',
			]
		);

		$token   = \SRFM\Inc\Submit_Token::generate( $form_id );
		$request = new \WP_REST_Request( 'POST', '/sureforms/v1/submit-form' );
		$request->set_body_params( [ 'form-id' => (string) $form_id ] );
		$request->set_header( 'X-WP-Submit-Token', $token );

		$result = $this->form_submit->submit_form_permissions_check( $request );
		$this->assertTrue( $result, 'Valid HMAC token should pass permissions check.' );

		wp_delete_post( $form_id, true );
	}

	/**
	 * Test submit_form_permissions_check rejects token for wrong form.
	 */
	public function test_submit_form_permissions_check_rejects_wrong_form_token() {
		$form_id_a = wp_insert_post(
			[
				'post_type'   => 'sureforms_form',
				'post_status' => 'publish',
				'post_title'  => 'Form A',
			]
		);
		$form_id_b = wp_insert_post(
			[
				'post_type'   => 'sureforms_form',
				'post_status' => 'publish',
				'post_title'  => 'Form B',
			]
		);

		// Generate token for form A but submit to form B.
		$token   = \SRFM\Inc\Submit_Token::generate( $form_id_a );
		$request = new \WP_REST_Request( 'POST', '/sureforms/v1/submit-form' );
		$request->set_body_params( [ 'form-id' => (string) $form_id_b ] );
		$request->set_header( 'X-WP-Submit-Token', $token );

		$result = $this->form_submit->submit_form_permissions_check( $request );
		$this->assertInstanceOf( \WP_Error::class, $result, 'Token for form A should not work for form B.' );

		wp_delete_post( $form_id_a, true );
		wp_delete_post( $form_id_b, true );
	}

	/**
	 * Test register_custom_endpoint registers the submit-form REST route.
	 */
	public function test_register_custom_endpoint() {
		do_action( 'rest_api_init' );
		$routes = rest_get_server()->get_routes();
		$found  = false;
		foreach ( array_keys( $routes ) as $route ) {
			if ( strpos( $route, 'sureforms/v1/submit-form' ) !== false ) {
				$found = true;
				break;
			}
		}
		$this->assertTrue( $found, 'The submit-form REST endpoint should be registered.' );
	}

	/**
	 * Test register_custom_endpoint no longer registers the refresh-nonces route.
	 */
	public function test_register_custom_endpoint_no_refresh_nonces() {
		do_action( 'rest_api_init' );
		$routes = rest_get_server()->get_routes();
		$found  = false;
		foreach ( array_keys( $routes ) as $route ) {
			if ( strpos( $route, 'sureforms/v1/refresh-nonces' ) !== false ) {
				$found = true;
				break;
			}
		}
		$this->assertFalse( $found, 'The refresh-nonces REST endpoint should no longer be registered.' );
	}

	/**
	 * Test permissions_check is callable.
	 */
	public function test_permissions_check() {
		$result = $this->form_submit->permissions_check();
		$this->assertIsBool( $result );
	}

	/**
	 * Test handle_form_entry is callable.
	 */
	public function test_handle_form_entry() {
		$this->assertTrue( method_exists( $this->form_submit, 'handle_form_entry' ) );
	}

	/**
	 * Test send_email is callable.
	 */
	public function test_send_email() {
		$this->assertTrue( method_exists( Form_Submit::class, 'send_email' ) );
	}

	/**
	 * Test field_unique_validation is callable.
	 */
	public function test_field_unique_validation() {
		$this->assertTrue( method_exists( $this->form_submit, 'field_unique_validation' ) );
	}

	/**
	 * Build a SureForms field key for a block.
	 *
	 * @param string $block_id Block ID.
	 * @param string $label    Field label.
	 * @param string $slug     Block slug suffix.
	 * @return string
	 */
	private function make_field_key( $block_id, $label, $slug ) {
		return 'srfm-input-' . $block_id . '-lbl-' . rtrim( base64_encode( $label ), '=' ) . '-' . $slug;
	}

	/**
	 * No-op wp_die handler so wp_send_json() returns instead of ending the process.
	 *
	 * @return callable
	 */
	public function get_noop_die_handler() {
		return static function () {};
	}

	/**
	 * Invoke the AJAX uniqueness check and return its decoded response.
	 *
	 * wp_send_json() calls die() outright unless the request is an AJAX one, so the
	 * call is framed as AJAX and wp_die() is neutralised for the duration.
	 *
	 * @param int                  $form_id Form ID.
	 * @param array<string,string> $fields  Field keys/values to probe.
	 * @return array<mixed>
	 */
	private function call_unique_validation( $form_id, $fields ) {
		$previous_post = $_POST;
		$_POST         = array_merge(
			[
				'action' => 'validation_ajax_action',
				'token'  => Submit_Token::generate( $form_id ),
				'id'     => $form_id,
			],
			$fields
		);

		add_filter( 'wp_doing_ajax', '__return_true' );
		add_filter( 'wp_die_ajax_handler', [ $this, 'get_noop_die_handler' ] );

		ob_start();
		$this->form_submit->field_unique_validation();
		$json = ob_get_clean();

		remove_filter( 'wp_die_ajax_handler', [ $this, 'get_noop_die_handler' ] );
		remove_filter( 'wp_doing_ajax', '__return_true' );

		$_POST = $previous_post;

		return json_decode( Helper::get_string_value( $json ), true );
	}

	/**
	 * The uniqueness check must only probe fields the form itself marks unique.
	 *
	 * Without that restriction the nopriv handler answers "does an entry exist whose
	 * field X equals Y?" for arbitrary X and Y — an existence oracle over every
	 * stored submission value. See #2997.
	 */
	public function test_field_unique_validation_only_probes_fields_marked_unique() {
		remove_all_actions( 'wp_insert_post_data' );

		$unique_key   = $this->make_field_key( 'uniq1234', 'Email', 'email' );
		$ordinary_key = $this->make_field_key( 'plain567', 'Phone', 'phone' );

		$form_id = wp_insert_post(
			[
				'post_title'   => 'Unique Field Form',
				'post_type'    => SRFM_FORMS_POST_TYPE,
				'post_status'  => 'publish',
				'post_content' => '<!-- wp:srfm/input {"block_id":"uniq1234","isUnique":true} /--><!-- wp:srfm/input {"block_id":"plain567"} /-->',
			]
		);

		EntriesTable::add(
			[
				'form_id'   => $form_id,
				'form_data' => [
					$unique_key   => 'taken@example.com',
					$ordinary_key => '5551234567',
				],
			]
		);

		// The feature still works for the field the form marks unique.
		$response = $this->call_unique_validation( $form_id, [ $unique_key => 'taken@example.com' ] );
		$this->assertSame( [ [ $unique_key => 'not unique' ] ], $response['data'] );

		// A field that exists on the form but is NOT marked unique leaks nothing,
		// even though the value demonstrably exists in an entry.
		$response = $this->call_unique_validation( $form_id, [ $ordinary_key => '5551234567' ] );
		$this->assertSame( [], $response['data'], 'Fields the form does not mark unique must not be probeable.' );

		// An invented key that never belonged to the form leaks nothing either.
		$invented = $this->make_field_key( 'ghost999', 'Anything', 'x' );
		$response = $this->call_unique_validation( $form_id, [ $invented => 'taken@example.com' ] );
		$this->assertSame( [], $response['data'] );

		wp_delete_post( $form_id, true );
	}

	/**
	 * A form with no unique fields answers with an empty result set for any probe.
	 */
	public function test_field_unique_validation_form_without_unique_fields() {
		remove_all_actions( 'wp_insert_post_data' );

		$field_key = $this->make_field_key( 'plain567', 'Phone', 'phone' );
		$form_id   = wp_insert_post(
			[
				'post_title'   => 'No Unique Fields Form',
				'post_type'    => SRFM_FORMS_POST_TYPE,
				'post_status'  => 'publish',
				'post_content' => '<!-- wp:srfm/input {"block_id":"plain567"} /-->',
			]
		);

		EntriesTable::add(
			[
				'form_id'   => $form_id,
				'form_data' => [ $field_key => '5551234567' ],
			]
		);

		$response = $this->call_unique_validation( $form_id, [ $field_key => '5551234567' ] );
		$this->assertSame( [], $response['data'] );

		wp_delete_post( $form_id, true );
	}

	/**
	 * A srfm_unique_field_block_ids callback returning a plain list must add to the set,
	 * not silently wipe it — the lookup is isset( $set[ $id ] ), so an un-normalised
	 * list would disable uniqueness for the whole form.
	 */
	public function test_get_unique_field_block_ids_normalizes_filter_shapes() {
		remove_all_actions( 'wp_insert_post_data' );

		$form_id = wp_insert_post(
			[
				'post_title'   => 'Filter Shape Form',
				'post_type'    => SRFM_FORMS_POST_TYPE,
				'post_status'  => 'publish',
				'post_content' => '<!-- wp:srfm/input {"block_id":"derived1","isUnique":true} /-->',
			]
		);

		// List shape from an add-on.
		$as_list = static function () {
			return [ 'fromlist' ];
		};
		add_filter( 'srfm_unique_field_block_ids', $as_list );
		$ids = $this->call_private_method( $this->form_submit, 'get_unique_field_block_ids', [ $form_id ] );
		remove_filter( 'srfm_unique_field_block_ids', $as_list );

		$this->assertArrayHasKey( 'fromlist', $ids, 'A list-shaped filter return must be normalised to a map.' );
		$this->assertTrue( $ids['fromlist'] );

		// A broken filter must not wipe the derived set.
		$as_garbage = static function () {
			return 'not-an-array';
		};
		add_filter( 'srfm_unique_field_block_ids', $as_garbage );
		$ids = $this->call_private_method( $this->form_submit, 'get_unique_field_block_ids', [ $form_id ] );
		remove_filter( 'srfm_unique_field_block_ids', $as_garbage );

		$this->assertArrayHasKey( 'derived1', $ids, 'A non-array filter return must fall back to the derived set.' );

		wp_delete_post( $form_id, true );
	}

	/**
	 * The unique-field set is derived from the stored form, including nested blocks
	 * and reusable patterns, and ignores non-unique fields.
	 */
	public function test_get_unique_field_block_ids_derives_set_from_form() {
		remove_all_actions( 'wp_insert_post_data' );

		$pattern_id = wp_insert_post(
			[
				'post_title'   => 'Reusable field',
				'post_type'    => 'wp_block',
				'post_status'  => 'publish',
				'post_content' => '<!-- wp:srfm/input {"block_id":"inpattern","isUnique":true} /-->',
			]
		);

		$form_id = wp_insert_post(
			[
				'post_title'   => 'Nested Unique Form',
				'post_type'    => SRFM_FORMS_POST_TYPE,
				'post_status'  => 'publish',
				'post_content' => '<!-- wp:srfm/input {"block_id":"toplevel","isUnique":true} /-->'
					. '<!-- wp:group --><!-- wp:srfm/input {"block_id":"nested01","isUnique":true} /--><!-- /wp:group -->'
					. '<!-- wp:srfm/input {"block_id":"notuniq1"} /-->'
					. '<!-- wp:block {"ref":' . $pattern_id . '} /-->',
			]
		);

		$block_ids = $this->call_private_method( $this->form_submit, 'get_unique_field_block_ids', [ $form_id ] );

		$this->assertArrayHasKey( 'toplevel', $block_ids );
		$this->assertArrayHasKey( 'nested01', $block_ids, 'Fields inside container blocks must be found.' );
		$this->assertArrayHasKey( 'inpattern', $block_ids, 'Fields inside reusable patterns must be found.' );
		$this->assertArrayNotHasKey( 'notuniq1', $block_ids, 'Fields without isUnique must not be included.' );

		// A form that does not exist yields an empty set.
		$this->assertSame( [], $this->call_private_method( $this->form_submit, 'get_unique_field_block_ids', [ 999999 ] ) );

		wp_delete_post( $form_id, true );
		wp_delete_post( $pattern_id, true );
	}

	/**
	 * Helper method to call private methods for testing.
	 */
	private function call_private_method($object, $method_name, $parameters = []) {
		$reflection = new \ReflectionClass(get_class($object));
		$method = $reflection->getMethod($method_name);
		$method->setAccessible(true);
		return $method->invokeArgs($object, $parameters);
	}

	/**
	* Test process_form_fields with form-id handling.
	* Tests the new logic for passing form-id through the filter.
	*/
	public function test_process_form_fields_with_form_id() {
		// Test case 1: Form data with form-id should pass it to filter and then remove it
		$form_data = [
			'form-id'             => '19',
			'text-lbl-field-name' => 'Test User',
		];

		// Mock the filter to verify form_id is passed via context array.
		$received_context = null;
		$callback         = function( $data, $context ) use ( &$received_context ) {
			$received_context = $context;
			return $data;
		};
		add_filter( 'srfm_before_prepare_submission_data', $callback, 10, 2 );

		$result = $this->call_private_method( $this->form_submit, 'process_form_fields', [ $form_data ] );

		// Verify form_id was passed via the context array.
		$this->assertIsArray( $received_context, 'Context array should be passed to the filter' );
		$this->assertArrayHasKey( 'form_id', $received_context, 'Context should contain form_id' );
		$this->assertEquals( 19, $received_context['form_id'] );

		// Verify form-id is not present in submission data (it should never be injected).
		$this->assertArrayNotHasKey( 'form-id', $result );

		// Verify other fields are present.
		$this->assertArrayHasKey( 'text-lbl-field-name', $result );
		$this->assertEquals( 'Test User', $result['text-lbl-field-name'] );

		// Remove only this specific callback, not all filters on the hook.
		remove_filter( 'srfm_before_prepare_submission_data', $callback, 10 );
	}

	/**
	 * Test that a filter callback can modify submission data without affecting the context array.
	 */
	public function test_process_form_fields_filter_modifies_submission_data() {
		$form_data = [
			'form-id'             => '19',
			'text-lbl-field-name' => 'Test User',
		];

		// Simulate a filter that adds a custom key to submission data.
		$callback = function( $data ) {
			$data['custom-key'] = 'custom-value';
			return $data;
		};
		add_filter( 'srfm_before_prepare_submission_data', $callback, 10, 1 );

		$result = $this->call_private_method( $this->form_submit, 'process_form_fields', [ $form_data ] );

		// Custom key added by the filter should be present.
		$this->assertArrayHasKey( 'custom-key', $result );
		$this->assertEquals( 'custom-value', $result['custom-key'] );

		// Other fields should be unaffected.
		$this->assertArrayHasKey( 'text-lbl-field-name', $result );
		$this->assertEquals( 'Test User', $result['text-lbl-field-name'] );

		remove_filter( 'srfm_before_prepare_submission_data', $callback, 10 );
	}

	/**
	 * Test process_form_fields with non-numeric form-id.
	 */
	public function test_process_form_fields_with_non_numeric_form_id() {
		$form_data = [
			'form-id'             => 'invalid-id',
			'text-lbl-field-name' => 'Test User',
		];

		$result = $this->call_private_method( $this->form_submit, 'process_form_fields', [ $form_data ] );

		// Should not include form-id in result
		$this->assertArrayNotHasKey( 'form-id', $result );
	}

	/**
	 * Test process_form_fields with missing form-id.
	 */
	public function test_process_form_fields_without_form_id() {
		$form_data = [
			'text-lbl-field-name' => 'Test User',
		];

		$result = $this->call_private_method( $this->form_submit, 'process_form_fields', [ $form_data ] );

		// Should process normally without errors
		$this->assertArrayHasKey( 'text-lbl-field-name', $result );
		$this->assertEquals( 'Test User', $result['text-lbl-field-name'] );
	}

	/**
	 * Test process_form_fields with zero form-id.
	 */
	public function test_process_form_fields_with_zero_form_id() {
		$form_data = [
			'form-id'             => '0',
			'text-lbl-field-name' => 'Test User',
		];

		$result = $this->call_private_method( $this->form_submit, 'process_form_fields', [ $form_data ] );

		// form-id = 0 is valid but should be removed from result
		$this->assertArrayNotHasKey( 'form-id', $result );
	}

	/**
	 * is_known_language() returns false for an empty string regardless of
	 * provider state — empty is never a valid stored value.
	 */
	public function test_is_known_language_rejects_empty_string() {
		$result = $this->call_private_method( $this->form_submit, 'is_known_language', [ '' ] );
		$this->assertFalse( $result );
	}

	/**
	 * On non-WPML / non-Polylang sites the multilingual manager resolves to
	 * Null_Provider. is_known_language() should then accept the shape-validated
	 * code so we don't drop legitimate submissions just because no multilingual
	 * plugin is active.
	 */
	public function test_is_known_language_accepts_when_provider_inactive() {
		$result = $this->call_private_method( $this->form_submit, 'is_known_language', [ 'hi' ] );
		$this->assertTrue( $result );
	}

	/**
	 * When the wpml_active_languages filter exposes a known set, the helper
	 * should still accept codes not in that set when the active provider
	 * reports is_active() === false (Null_Provider case in the test env). This
	 * test pins that fallback path so the filter alone doesn't cause valid
	 * submissions to be rejected on non-multilingual sites.
	 */
	public function test_is_known_language_does_not_reject_when_provider_inactive_even_with_filter() {
		$listener = static function () {
			return [ 'en' => [ 'language_code' => 'en' ] ];
		};
		add_filter( 'wpml_active_languages', $listener );

		// 'hi' is NOT in the filter list, but the provider is inactive — so
		// is_known_language() should still return true (defence-in-depth applies
		// to the active-provider path only).
		$result = $this->call_private_method( $this->form_submit, 'is_known_language', [ 'hi' ] );
		$this->assertTrue( $result );

		remove_filter( 'wpml_active_languages', $listener );
	}

	/**
	 * Regression for the WPML permalink bug: a same-origin referer with a
	 * percent-encoded (non-ASCII) slug must keep its octets, NOT be collapsed to
	 * hyphens the way sanitize_text_field() did.
	 */
	public function test_normalize_submission_url_preserves_multibyte_percent_encoding() {
		$home = home_url();
		$path = '/' . rawurlencode( 'परीक्षण' ) . '/';
		$url  = $home . $path;

		$result = $this->call_private_method( $this->form_submit, 'normalize_submission_url', [ $url ] );

		$this->assertStringContainsString( '%E0%A4', $result, 'Percent-encoded octets must be preserved.' );
		$this->assertStringNotContainsString( '--', $result, 'Slug must not collapse to bare hyphens.' );
	}

	/**
	 * normalize_submission_url() rejects cross-origin, non-http(s) and overlong
	 * referers, returning an empty string.
	 */
	public function test_normalize_submission_url_rejects_invalid_referers() {
		// Cross-origin.
		$this->assertSame(
			'',
			$this->call_private_method( $this->form_submit, 'normalize_submission_url', [ 'https://evil.example.com/x/' ] )
		);
		// Non-http scheme.
		$this->assertSame(
			'',
			$this->call_private_method( $this->form_submit, 'normalize_submission_url', [ 'javascript:alert(1)' ] )
		);
		// Empty.
		$this->assertSame(
			'',
			$this->call_private_method( $this->form_submit, 'normalize_submission_url', [ '' ] )
		);
		// Overlong (>2048).
		$this->assertSame(
			'',
			$this->call_private_method( $this->form_submit, 'normalize_submission_url', [ home_url( '/' . str_repeat( 'a', 2100 ) ) ] )
		);
	}

	/**
	 * normalize_submission_url() keeps a legitimate same-origin URL and drops the
	 * fragment/userinfo by rebuilding from parsed parts.
	 */
	public function test_normalize_submission_url_accepts_same_origin_and_strips_fragment() {
		$result = $this->call_private_method( $this->form_submit, 'normalize_submission_url', [ home_url( '/form/6/?lang=hi#frag' ) ] );

		$this->assertStringContainsString( '/form/6/', $result );
		$this->assertStringContainsString( 'lang=hi', $result );
		$this->assertStringNotContainsString( '#frag', $result, 'Fragment must be dropped.' );
	}
}
