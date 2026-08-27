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
	}

	protected function tearDown(): void {
		Client_Logger::clear();
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
	 * Logging must expire on its own. Support asks a site owner to switch it on
	 * and nobody remembers to switch it off, so an indefinitely open write path is
	 * the long-term risk this guards.
	 */
	public function test_is_enabled_expires_after_the_maximum_duration() {
		update_option( Client_Logger::ENABLED_AT_OPTION, time() - Client_Logger::MAX_ENABLED_DURATION - 1 );

		$this->assertFalse( Client_Logger::is_enabled() );
	}

	/**
	 * The daily job must clear the stored setting too, not just report expiry —
	 * otherwise the settings screen keeps showing the toggle on.
	 */
	public function test_maybe_expire() {
		update_option( Client_Logger::ENABLED_AT_OPTION, time() - Client_Logger::MAX_ENABLED_DURATION - 1 );

		Client_Logger::maybe_expire();

		$general = get_option( 'srfm_general_settings_options', [] );

		$this->assertFalse( (bool) $general['srfm_enable_logs'] );
		$this->assertSame( 0, Client_Logger::get_expiry() );
	}

	/**
	 * Re-saving the settings page while logging is already on must not push the
	 * expiry further out, or the seven-day limit never arrives.
	 */
	public function test_set_enabled_at_does_not_extend_a_running_window() {
		$started = time() - 1000;
		update_option( Client_Logger::ENABLED_AT_OPTION, $started );

		Client_Logger::set_enabled_at( true );

		$this->assertSame( $started + Client_Logger::MAX_ENABLED_DURATION, Client_Logger::get_expiry() );
	}

	/**
	 * Expiry is only meaningful while logging runs.
	 */
	public function test_get_expiry() {
		$this->assertGreaterThan( time(), Client_Logger::get_expiry() );

		Client_Logger::set_enabled_at( false );

		$this->assertSame( 0, Client_Logger::get_expiry() );
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
	 * Long text must be clamped so one entry cannot fill the file, and markup must
	 * not survive into a document someone opens.
	 */
	public function test_scrub_text_clamps_and_strips() {
		$scrubbed = Client_Logger::scrub_text( str_repeat( 'a', 5000 ) );

		$this->assertSame( Client_Logger::MAX_TEXT_LENGTH, mb_strlen( $scrubbed ) );
		$this->assertStringNotContainsString( '<script>', Client_Logger::scrub_text( '<script>alert(1)</script> hi' ) );
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

		if ( $enabled ) {
			update_option( Client_Logger::ENABLED_AT_OPTION, time() );
		} else {
			delete_option( Client_Logger::ENABLED_AT_OPTION );
		}
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
