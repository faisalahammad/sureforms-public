<?php
/**
 * Class Test_Client_Logger
 *
 * @package sureforms
 */

use SRFM\Inc\Client_Logger;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Tests for the client debug log: the enable/expire gate, the payload schema,
 * PII scrubbing, and the size cap.
 *
 * These write real files under the uploads directory, so every test restores the
 * setting and deletes the log — this suite runs on a plain TestCase with no
 * transaction to roll back.
 */
class Test_Client_Logger extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		$this->set_logging( true );
		Client_Logger::clear();
		delete_option( Client_Logger::FAULT_STREAK_OPTION );
	}

	protected function tearDown(): void {
		Client_Logger::clear();
		delete_option( Client_Logger::FAULT_STREAK_OPTION );
		$this->set_logging( false );
		delete_option( Client_Logger::FILENAME_OPTION );

		parent::tearDown();
	}

	// ---------------------------------------------------------------
	// The gate
	// ---------------------------------------------------------------

	/**
	 * The setting is the authority. The frontend flag is baked into cached HTML
	 * and can be a whole cache TTL out of date, so a false here must stop writes
	 * no matter what the browser was told.
	 */
	public function test_is_enabled() {
		$this->assertTrue( Client_Logger::is_enabled() );

		$this->set_logging( false );

		$this->assertFalse( Client_Logger::is_enabled() );
	}

	/**
	 * Logging is on by default, including on an install whose stored settings
	 * predate the option — an absent key must read as on, not off, or every
	 * existing site silently gets nothing.
	 */
	public function test_is_enabled_defaults_to_on() {
		$general = (array) get_option( 'srfm_general_settings_options', [] );
		unset( $general['srfm_enable_logs'] );
		update_option( 'srfm_general_settings_options', $general );

		$this->assertTrue( Client_Logger::is_enabled() );
	}

	// ---------------------------------------------------------------
	// Writing
	// ---------------------------------------------------------------

	/**
	 * The guard sits on the function that writes, not only on today's single
	 * caller. Without it a future caller writes to disk on a site that never
	 * switched logging on — and creates the directory doing it.
	 */
	public function test_append() {
		$this->assertTrue( Client_Logger::append( [ 'type' => 'error', 'message' => 'kept' ] ) );

		$this->set_logging( false );

		$this->assertFalse( Client_Logger::append( [ 'type' => 'error', 'message' => 'dropped' ] ) );
	}

	/**
	 * Once full the log stops accepting lines rather than trimming or rotating.
	 * The person who reproduced the bug is the one whose lines would be discarded.
	 */
	public function test_is_full() {
		$this->assertFalse( Client_Logger::is_full() );

		$this->fill_log();

		$this->assertTrue( Client_Logger::is_full() );
		$this->assertFalse( Client_Logger::append( [ 'type' => 'error', 'message' => 'after cap' ] ) );
	}

	/**
	 * A capped file must still parse. A cap implemented by truncating bytes would
	 * leave a half-written line and make the download unreadable.
	 */
	public function test_get_file_size() {
		$this->assertSame( 0, Client_Logger::get_file_size() );

		$this->fill_log();

		$path  = Client_Logger::get_log_path( false );
		$lines = file( $path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );

		$this->assertNotEmpty( $lines );

		foreach ( $lines as $line ) {
			$this->assertNotNull( json_decode( $line, true ), 'Every line must be valid JSON.' );
		}
	}

	/**
	 * The log lives under uploads, in its own directory, behind guards.
	 */
	public function test_get_log_path() {
		// Remove the directory first: the guards are written at creation time, so a
		// leftover directory from an earlier test would make this pass without ever
		// exercising the code that writes them.
		$existing = Client_Logger::get_log_path( false );

		if ( '' !== $existing ) {
			$dir = dirname( $existing );

			Client_Logger::clear();

			// Remove everything, not just the files this class knows about: an
			// earlier test in another class may have left a log behind, and a
			// non-empty directory would make the rmdir fail.
			foreach ( (array) glob( $dir . '/{,.}*', GLOB_BRACE ) as $file ) {
				if ( is_string( $file ) && is_file( $file ) ) {
					wp_delete_file( $file );
				}
			}

			if ( is_dir( $dir ) ) {
				rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Test fixture teardown of a directory this suite created.
			}
		}

		$path = Client_Logger::get_log_path();

		$this->assertStringContainsString( '/sureforms/logs/', $path );
		$this->assertStringEndsWith( '.log', $path );

		$dir = dirname( $path );

		$this->assertFileExists( $dir . '/.htaccess' );
		// index.html, not index.php: an index.html is what suppresses an nginx
		// autoindex listing, which is the failure mode .htaccess cannot cover.
		$this->assertFileExists( $dir . '/index.html' );
	}

	/**
	 * Clearing must remove the file, not merely empty the reported size.
	 */
	public function test_clear() {
		Client_Logger::append( [ 'type' => 'error', 'message' => 'temporary' ] );
		$path = Client_Logger::get_log_path( false );

		$this->assertFileExists( $path );

		Client_Logger::clear();

		$this->assertFileDoesNotExist( $path );
	}

	// ---------------------------------------------------------------
	// Schema and scrubbing
	// ---------------------------------------------------------------

	/**
	 * The endpoint is anonymous, so it must never append caller-supplied text.
	 * Redaction governs values; the schema governs shape. Without this an attacker
	 * writes arbitrary content into a file an administrator later opens.
	 */
	public function test_sanitize_entry() {
		$entry = Client_Logger::sanitize_entry(
			[
				'type'        => 'network',
				'status'      => 500,
				'duration_ms' => 812,
				'message'     => 'Submission responded 500',
				'evil'        => '<script>alert(1)</script>',
				'body'        => 'Fatal error in plugin.php',
			]
		);

		$this->assertArrayNotHasKey( 'evil', $entry );
		$this->assertSame( 'network', $entry['type'] );
		$this->assertSame( 500, $entry['status'] );
	}

	/**
	 * An unrecognised type is the fallthrough case and must produce nothing, so a
	 * caller cannot invent a category.
	 */
	public function test_sanitize_entry_rejects_an_unknown_type() {
		$this->assertSame( [], Client_Logger::sanitize_entry( [ 'type' => 'anything', 'message' => 'x' ] ) );
		$this->assertSame( [], Client_Logger::sanitize_entry( [ 'message' => 'no type at all' ] ) );
	}

	/**
	 * A type on its own says nothing and would just pad the file toward its cap.
	 */
	public function test_sanitize_entry_requires_some_detail() {
		$this->assertSame( [], Client_Logger::sanitize_entry( [ 'type' => 'error' ] ) );
	}

	/**
	 * Field keys are logged, values never are. The download must not become a
	 * personal-data export.
	 */
	public function test_sanitize_entry_keeps_field_keys_only() {
		$entry = Client_Logger::sanitize_entry(
			[
				'type'       => 'network',
				'status'     => 400,
				'field_keys' => array_fill( 0, 150, 'srfm-input-lbl-abc' ),
			]
		);

		// Capped so one request cannot consume the file.
		$this->assertCount( 100, $entry['field_keys'] );
	}

	/**
	 * Capping the count but not each key's length let one request write ~1MB of
	 * field keys and fill the log in a single call. Because the log stops rather
	 * than evicting, that silently disabled the feature until an admin cleared it
	 * -- a no-auth denial of service against the plugin's own diagnostics.
	 *
	 * The previous test used short fixed strings and passed throughout.
	 */
	public function test_sanitize_entry_clamps_each_field_key_length() {
		$entry = Client_Logger::sanitize_entry(
			[
				'type'       => 'network',
				'status'     => 400,
				'field_keys' => array_fill( 0, 100, str_repeat( 'A', 10000 ) ),
			]
		);

		foreach ( $entry['field_keys'] as $key ) {
			$this->assertLessThanOrEqual( Client_Logger::MAX_KEY_LENGTH, mb_strlen( $key ) );
		}

		Client_Logger::append( $entry );

		// One request must not come close to the cap.
		$this->assertLessThan( Client_Logger::MAX_FILE_SIZE / 10, Client_Logger::get_file_size() );
		$this->assertFalse( Client_Logger::is_full() );
	}

	/**
	 * A phone number is written 555-123-4567, not 5551234567, so a
	 * contiguous-digits rule never sees a real one. The earlier test only used an
	 * unformatted run and passed regardless.
	 */
	public function test_scrub_text_redacts_formatted_numbers() {
		foreach ( [
			'Phone 555-123-4567 rejected',
			'Call (555) 123-4567 now',
			'Intl +44 7700 900123 failed',
			'Card 4111 1111 1111 1111 declined',
		] as $sample ) {
			$scrubbed = Client_Logger::scrub_text( $sample );

			$this->assertStringContainsString( '[number]', $scrubbed, $sample );
			$this->assertDoesNotMatchRegularExpression( '/\d{3}[\s.-]\d{3}/', $scrubbed, $sample );
		}
	}

	/**
	 * A token after # is as sensitive as one after ?, and the URL rule only
	 * stripped query strings.
	 */
	public function test_scrub_text_strips_url_fragments() {
		$this->assertStringNotContainsString(
			's3cr3t',
			Client_Logger::scrub_text( 'Failed at https://example.com/reset#token=s3cr3t' )
		);
	}

	/**
	 * Redacting submitted values is not enough on its own: error text interpolates
	 * user input constantly, and page URLs carry addresses and reset keys in their
	 * query strings.
	 */
	public function test_scrub_text() {
		$this->assertStringNotContainsString(
			'bob@example.com',
			Client_Logger::scrub_text( 'Invalid email: bob@example.com' )
		);
		$this->assertStringNotContainsString(
			'447700900123',
			Client_Logger::scrub_text( 'Phone 447700900123 rejected' )
		);
		$this->assertStringNotContainsString(
			'reset_key',
			Client_Logger::scrub_text( 'Failed at https://example.com/page?reset_key=secret' )
		);
	}

	/**
	 * The scrubber must not eat the diagnosis. Provider error codes, WordPress
	 * error slugs and bracketed code lists are the payload of a CAPTCHA or
	 * validation rejection -- a future tightening of the redaction rules that
	 * strips them leaves entries that say a submission failed and nothing more.
	 */
	public function test_scrub_text_preserves_error_codes() {
		$message = 'Submission responded 200 (application/json) (srfm_invalid_form_id): Google reCAPTCHA verification failed. [invalid-input-response, timeout-or-duplicate]';

		$scrubbed = Client_Logger::scrub_text( $message );

		$this->assertStringContainsString( 'invalid-input-response', $scrubbed );
		$this->assertStringContainsString( 'timeout-or-duplicate', $scrubbed );
		$this->assertStringContainsString( 'srfm_invalid_form_id', $scrubbed );
		$this->assertStringContainsString( '200', $scrubbed );
	}

	/**
	 * Long text must be clamped so one entry cannot fill the file, and markup must
	 * not survive into a document someone opens.
	 */
	public function test_scrub_text_clamps_and_strips() {
		$scrubbed = Client_Logger::scrub_text( str_repeat( 'a', 5000 ) );

		$this->assertSame( Client_Logger::MAX_TEXT_LENGTH, mb_strlen( $scrubbed ) );
		$this->assertStringNotContainsString( '<script>', Client_Logger::scrub_text( '<script>alert(1)</script> hi' ) );
	}

	/**
	 * Every failure category the form can produce must survive the schema, or the
	 * "logs all submission failures" claim quietly stops being true for one of them.
	 *
	 * The categories are: a failure the server rejected, a request that never
	 * reached PHP, a response that was not JSON, and a failure caught in the
	 * browser before the request went out (client-side validation, captcha,
	 * payment) or after it returned (the after-submission step, a notification
	 * email).
	 */
	public function test_sanitize_entry_accepts_every_failure_category() {
		$cases = [
			'server rejection'  => [ 'type' => 'network', 'status' => 200, 'field_keys' => [ 'srfm-email-lbl-x' ] ],
			'transport failure' => [ 'type' => 'error', 'message' => 'TypeError: Failed to fetch' ],
			'non-JSON response' => [ 'type' => 'response', 'status' => 500, 'body' => 'PHP Fatal error' ],
			'blocked pre-submit' => [ 'type' => 'message', 'message' => 'Blocked before submit: field validation failed.' ],
		];

		foreach ( $cases as $label => $raw ) {
			$this->assertNotSame( [], Client_Logger::sanitize_entry( $raw ), $label . ' must be loggable.' );
		}
	}

	// ---------------------------------------------------------------
	// Repeated-failure detection
	// ---------------------------------------------------------------

	/**
	 * The distinction the whole notice rests on. A mistyped email, an expired
	 * captcha, a declined card and a rejection naming the field to fix are the form
	 * working correctly. Counting them would tell healthy sites to contact support
	 * -- on every install, because logging is on by default.
	 */
	public function test_is_fault() {
		$visitor_correctable = [
			'field validation'   => [ 'type' => 'blocked', 'message' => 'field validation failed' ],
			'captcha'            => [ 'type' => 'blocked', 'message' => 'captcha validation failed' ],
			'declined card'      => [ 'type' => 'blocked', 'message' => 'payment. Card declined.' ],
			'rejected field'     => [ 'type' => 'blocked', 'status' => 200, 'field_keys' => [ 'srfm-email-lbl-x' ] ],
		];

		foreach ( $visitor_correctable as $label => $entry ) {
			$this->assertFalse( Client_Logger::is_fault( $entry ), $label . ' must not count as a fault.' );
		}

		$faults = [
			'transport failure' => [ 'type' => 'error', 'message' => 'TypeError: Failed to fetch' ],
			'non-JSON response' => [ 'type' => 'response', 'status' => 500, 'body' => 'PHP Fatal error' ],
			'server error'      => [ 'type' => 'network', 'status' => 502 ],
			'token refused'     => [ 'type' => 'network', 'status' => 403 ],
			'email failure'     => [ 'type' => 'message', 'message' => 'Email notification failed to send.' ],
		];

		foreach ( $faults as $label => $entry ) {
			$this->assertTrue( Client_Logger::is_fault( $entry ), $label . ' must count as a fault.' );
		}
	}

	/**
	 * A 200 without field errors is the server failing rather than refusing, but a
	 * 200 is also how every ordinary rejection is returned -- so it must not count
	 * on its own.
	 */
	public function test_is_fault_ignores_an_ordinary_rejection() {
		$this->assertFalse( Client_Logger::is_fault( [ 'type' => 'network', 'status' => 200 ] ) );
	}

	/**
	 * The notice appears only after a run of faults, so one bad request on an
	 * otherwise healthy site says nothing.
	 */
	public function test_has_persistent_failures() {
		$this->assertFalse( Client_Logger::has_persistent_failures() );

		for ( $i = 0; $i < Client_Logger::FAULT_THRESHOLD - 1; $i++ ) {
			Client_Logger::append( [ 'type' => 'error', 'message' => 'TypeError: Failed to fetch' ] );
		}

		$this->assertFalse( Client_Logger::has_persistent_failures(), 'One short of the threshold must stay quiet.' );

		Client_Logger::append( [ 'type' => 'error', 'message' => 'TypeError: Failed to fetch' ] );

		$this->assertTrue( Client_Logger::has_persistent_failures() );
	}

	/**
	 * Visitor-correctable failures must never accumulate toward the notice, however
	 * many of them there are. A busy form produces these constantly.
	 */
	public function test_get_fault_streak_ignores_blocked_entries() {
		for ( $i = 0; $i < 20; $i++ ) {
			Client_Logger::append( [ 'type' => 'blocked', 'message' => 'field validation failed' ] );
		}

		$this->assertSame( 0, Client_Logger::get_fault_streak() );
		$this->assertFalse( Client_Logger::has_persistent_failures() );
	}

	/**
	 * One submission getting through is the best evidence the form is not broken,
	 * and retires the notice without anyone dismissing it.
	 */
	public function test_reset_fault_streak() {
		for ( $i = 0; $i < Client_Logger::FAULT_THRESHOLD; $i++ ) {
			Client_Logger::append( [ 'type' => 'error', 'message' => 'TypeError: Failed to fetch' ] );
		}

		$this->assertTrue( Client_Logger::has_persistent_failures() );

		Client_Logger::reset_fault_streak();

		$this->assertSame( 0, Client_Logger::get_fault_streak() );
		$this->assertFalse( Client_Logger::has_persistent_failures() );
	}

	/**
	 * A full log or an unwritable uploads directory must not blind the notice --
	 * on a badly broken site those are exactly the conditions that occur.
	 */
	public function test_get_fault_streak_counts_even_when_the_log_is_full() {
		$this->fill_log();
		$this->assertTrue( Client_Logger::is_full() );

		$before = Client_Logger::get_fault_streak();
		Client_Logger::append( [ 'type' => 'error', 'message' => 'TypeError: Failed to fetch' ] );

		$this->assertSame( $before + 1, Client_Logger::get_fault_streak() );
	}

	// ---------------------------------------------------------------
	// Helpers
	// ---------------------------------------------------------------

	/**
	 * Switch the stored setting, stamping the start so the expiry logic behaves.
	 *
	 * @param bool $enabled Whether logging should be on.
	 * @return void
	 */
	private function set_logging( $enabled ) {
		$general                     = (array) get_option( 'srfm_general_settings_options', [] );
		$general['srfm_enable_logs'] = $enabled;
		update_option( 'srfm_general_settings_options', $general );

	}

	/**
	 * Append until the log refuses further writes.
	 *
	 * @return void
	 */
	private function fill_log() {
		$blob = str_repeat( 'a', Client_Logger::MAX_TEXT_LENGTH );

		for ( $i = 0; $i < 5000; $i++ ) {
			if ( ! Client_Logger::append( [ 'type' => 'error', 'message' => $blob ] ) ) {
				return;
			}
		}
	}
}
