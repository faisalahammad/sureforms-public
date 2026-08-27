<?php
/**
 * Client error logging.
 *
 * Owns the debug log file that the "Enable Logs" setting writes to: where it
 * lives, what may be written to it, and how large it is allowed to get. Nothing
 * else in the plugin should open that file.
 *
 * The log records form-submission failures reported by the visitor's browser —
 * the HTTP status and duration of the submit request, and the error behind a
 * failure — so a site owner can reproduce a bug and hand the file to support
 * instead of being talked through DevTools.
 *
 * @package SureForms
 * @since   x.x.x
 */

namespace SRFM\Inc;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Client_Logger
 *
 * Design notes that are load-bearing:
 *
 *  - Every method fails silently. The uploads directory is not writable on
 *    hardened or read-only-deploy hosts, and a logger that warns or fatals on a
 *    visitor-facing request is worse than one that records nothing.
 *  - The file name is unguessable and never disclosed. `.htaccess` does nothing
 *    on nginx, so the name is the real protection; the download handler derives
 *    the path itself and takes no filename parameter.
 *  - Over the size cap the log stops accepting writes rather than trimming or
 *    rotating. Someone reproducing a bug must not have the tail of their repro
 *    evicted by newer noise from an unrelated visitor.
 *
 * @since x.x.x
 */
class Client_Logger {
	/**
	 * Option holding the random component of the log file name.
	 *
	 * @since x.x.x
	 */
	public const FILENAME_OPTION = 'srfm_client_log_file';

	/**
	 * Option holding the Unix timestamp at which logging was switched on.
	 *
	 * Stored as a timestamp rather than a bool so logging can expire on its own.
	 * Support asks a site owner to turn logging on; nobody remembers to turn it
	 * off, and an indefinitely open write path is the long-term risk here.
	 *
	 * @since x.x.x
	 */
	public const ENABLED_AT_OPTION = 'srfm_client_log_enabled_at';

	/**
	 * How long logging stays on before it switches itself off, in seconds.
	 *
	 * @since x.x.x
	 */
	public const MAX_ENABLED_DURATION = 7 * DAY_IN_SECONDS;

	/**
	 * Maximum size of the log file in bytes.
	 *
	 * @since x.x.x
	 */
	public const MAX_FILE_SIZE = 1048576;

	/**
	 * Longest free-text value stored on a single entry, in characters.
	 *
	 * @since x.x.x
	 */
	public const MAX_TEXT_LENGTH = 500;

	/**
	 * Whether client error logging is currently switched on.
	 *
	 * This is the one authority. The frontend also carries a flag, but that flag
	 * is baked into cached HTML and can be up to a full cache TTL out of date, so
	 * it is only ever a hint — every write path re-checks here.
	 *
	 * @since x.x.x
	 * @return bool
	 */
	public static function is_enabled() {
		$general = get_option( 'srfm_general_settings_options', [] );

		if ( ! is_array( $general ) || empty( $general['srfm_enable_logs'] ) ) {
			return false;
		}

		$enabled_at = Helper::get_integer_value( get_option( self::ENABLED_AT_OPTION, 0 ) );

		// No recorded start means the setting was written by something that did not
		// stamp it. Treat it as on rather than silently ignoring the site owner.
		if ( ! $enabled_at ) {
			return true;
		}

		return time() - $enabled_at < self::MAX_ENABLED_DURATION;
	}

	/**
	 * Unix timestamp at which logging switches itself off, or 0 when not running.
	 *
	 * @since x.x.x
	 * @return int
	 */
	public static function get_expiry() {
		$enabled_at = Helper::get_integer_value( get_option( self::ENABLED_AT_OPTION, 0 ) );

		return $enabled_at ? $enabled_at + self::MAX_ENABLED_DURATION : 0;
	}

	/**
	 * Record that logging has just been switched on, or clear that record.
	 *
	 * @param bool $enabled Whether logging is being switched on.
	 * @since x.x.x
	 * @return void
	 */
	public static function set_enabled_at( $enabled ) {
		if ( ! $enabled ) {
			delete_option( self::ENABLED_AT_OPTION );
			return;
		}

		// Only stamp a fresh start. Re-saving the settings page with logging
		// already on must not extend the window indefinitely.
		if ( ! Helper::get_integer_value( get_option( self::ENABLED_AT_OPTION, 0 ) ) ) {
			update_option( self::ENABLED_AT_OPTION, time(), false );
		}
	}

	/**
	 * Switch logging off once it has been on longer than the maximum duration.
	 *
	 * Hooked - srfm_daily_scheduled_action.
	 *
	 * @since x.x.x
	 * @return void
	 */
	public static function maybe_expire() {
		$enabled_at = Helper::get_integer_value( get_option( self::ENABLED_AT_OPTION, 0 ) );

		if ( ! $enabled_at || time() - $enabled_at < self::MAX_ENABLED_DURATION ) {
			return;
		}

		$general = get_option( 'srfm_general_settings_options', [] );

		if ( is_array( $general ) ) {
			$general['srfm_enable_logs'] = false;
			update_option( 'srfm_general_settings_options', $general );
		}

		delete_option( self::ENABLED_AT_OPTION );
	}

	/**
	 * Absolute path to the log file, creating its directory if needed.
	 *
	 * @param bool $create Whether to create the directory when it is absent.
	 * @since x.x.x
	 * @return string Absolute path, or '' when the location is unusable.
	 */
	public static function get_log_path( $create = true ) {
		$uploads = wp_upload_dir();

		if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) ) {
			return '';
		}

		$dir = trailingslashit( $uploads['basedir'] ) . 'sureforms/logs/';

		if ( ! is_dir( $dir ) ) {
			if ( ! $create || ! wp_mkdir_p( $dir ) ) {
				return '';
			}

			self::protect_directory( $dir );
		}

		return $dir . 'srfm-debug-' . self::get_filename_hash() . '.log';
	}

	/**
	 * Append one validated entry to the log.
	 *
	 * @param array<string,mixed> $entry Entry as returned by sanitize_entry().
	 * @since x.x.x
	 * @return bool True when the line was written.
	 */
	public static function append( array $entry ) {
		// Checked here as well as at the route, so the guard sits on the function
		// that writes rather than only on today's single caller. Without it any
		// future caller writes to disk on a site that never switched logging on.
		if ( ! self::is_enabled() ) {
			return false;
		}

		if ( empty( $entry ) ) {
			return false;
		}

		$path = self::get_log_path();

		if ( '' === $path ) {
			return false;
		}

		// Cap and stop. Deliberately not a trim or a rotate: the person who
		// reproduced the bug is the one whose lines would be discarded.
		if ( self::is_full() ) {
			return false;
		}

		$entry['time'] = gmdate( 'Y-m-d H:i:s' );

		$line = wp_json_encode( $entry );

		if ( ! is_string( $line ) ) {
			return false;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents -- WP_Filesystem can prompt for credentials and is not initialised on a public REST request; this mirrors the raw-handle pattern already used in inc/entries.php. LOCK_EX is insurance for NFS/Windows -- appends of this size are already atomic on POSIX.
		return false !== file_put_contents( $path, $line . "\n", FILE_APPEND | LOCK_EX );
	}

	/**
	 * Whether the log has reached its size cap.
	 *
	 * @since x.x.x
	 * @return bool
	 */
	public static function is_full() {
		return self::get_file_size() >= self::MAX_FILE_SIZE;
	}

	/**
	 * Current size of the log file in bytes.
	 *
	 * @since x.x.x
	 * @return int
	 */
	public static function get_file_size() {
		$path = self::get_log_path( false );

		if ( '' === $path || ! file_exists( $path ) ) {
			return 0;
		}

		$size = filesize( $path );

		return is_int( $size ) ? $size : 0;
	}

	/**
	 * Delete the log file.
	 *
	 * @since x.x.x
	 * @return bool
	 */
	public static function clear() {
		$path = self::get_log_path( false );

		if ( '' === $path || ! file_exists( $path ) ) {
			return true;
		}

		return wp_delete_file_from_directory( $path, dirname( $path ) );
	}

	/**
	 * Reduce a caller-supplied payload to the fixed shape the log accepts.
	 *
	 * The endpoint never appends caller text directly. Redaction governs values;
	 * this governs shape. Without it, "we redact the field values" would still
	 * leave an anonymous caller writing arbitrary content into a file an
	 * administrator later opens.
	 *
	 * @param array<string,mixed> $raw Decoded request payload.
	 * @since x.x.x
	 * @return array<string,mixed> Empty when nothing usable survived.
	 */
	public static function sanitize_entry( array $raw ) {
		$allowed_types = [ 'network', 'response', 'error', 'message' ];
		$type          = isset( $raw['type'] ) ? sanitize_key( Helper::get_string_value( $raw['type'] ) ) : '';

		if ( ! in_array( $type, $allowed_types, true ) ) {
			return [];
		}

		$entry = [
			'type'    => $type,
			'form_id' => isset( $raw['form_id'] ) ? absint( Helper::get_integer_value( $raw['form_id'] ) ) : 0,
		];

		foreach ( [ 'message', 'source', 'body' ] as $key ) {
			if ( ! isset( $raw[ $key ] ) ) {
				continue;
			}

			$text = self::scrub_text( Helper::get_string_value( $raw[ $key ] ) );

			if ( '' !== $text ) {
				$entry[ $key ] = $text;
			}
		}

		foreach ( [ 'status', 'duration_ms', 'line' ] as $key ) {
			if ( isset( $raw[ $key ] ) ) {
				$entry[ $key ] = absint( Helper::get_integer_value( $raw[ $key ] ) );
			}
		}

		if ( isset( $raw['field_keys'] ) && is_array( $raw['field_keys'] ) ) {
			$keys = [];

			foreach ( array_slice( $raw['field_keys'], 0, 100 ) as $field_key ) {
				$keys[] = sanitize_text_field( Helper::get_string_value( $field_key ) );
			}

			$entry['field_keys'] = $keys;
		}

		// A type alone says nothing. Require at least one substantive value.
		$has_detail = isset( $entry['message'] ) || isset( $entry['body'] ) || isset( $entry['status'] );

		return $has_detail ? $entry : [];
	}

	/**
	 * Strip identifying detail out of free text and clamp its length.
	 *
	 * Redacting submitted field values is not sufficient on its own: error text
	 * interpolates user input constantly ("Invalid email: someone@example.com"),
	 * and a page URL routinely carries an address or a reset key in its query
	 * string. Whitespace is collapsed as a log-injection guard, matching
	 * inc/ai-form-builder/ai-helper.php.
	 *
	 * @param string $text Raw text.
	 * @since x.x.x
	 * @return string
	 */
	public static function scrub_text( $text ) {
		if ( '' === $text ) {
			return '';
		}

		// Drop query strings wholesale rather than allowlisting parameters.
		$text = (string) preg_replace( '#(https?://[^\s?]+)\?\S*#i', '$1', $text );

		// Email addresses.
		$text = (string) preg_replace( '/[\w.+-]+@[\w-]+\.[\w.-]+/', '[email]', $text );

		// Long digit runs: card numbers, phone numbers, ids.
		$text = (string) preg_replace( '/\d{7,}/', '[number]', $text );

		$text = (string) preg_replace( '/\s+/', ' ', $text );

		return mb_substr( trim( wp_strip_all_tags( $text ) ), 0, self::MAX_TEXT_LENGTH );
	}

	/**
	 * Random component of the log file name, generated once and reused.
	 *
	 * The blog id is part of the input because wp_salt() is network-wide: a
	 * salt-only hash would be identical on every site of a multisite network, and
	 * older subdirectory installs can share one uploads directory.
	 *
	 * @since x.x.x
	 * @return string
	 */
	private static function get_filename_hash() {
		$hash = get_option( self::FILENAME_OPTION, '' );

		if ( is_string( $hash ) && 32 === strlen( $hash ) && ctype_xdigit( $hash ) ) {
			return $hash;
		}

		$hash = hash_hmac( 'md5', 'srfm-client-log|' . get_current_blog_id(), wp_salt( 'auth' ) );

		update_option( self::FILENAME_OPTION, $hash, false );

		return $hash;
	}

	/**
	 * Write the directory guards, best effort.
	 *
	 * An index.html rather than index.php: the nginx failure mode is `autoindex on`
	 * producing a listing, and an index.html suppresses that. .htaccess covers
	 * Apache and is inert on nginx, which is why the unguessable file name — not
	 * these files — is what actually protects the log.
	 *
	 * @param string $dir Directory to guard.
	 * @since x.x.x
	 * @return void
	 */
	private static function protect_directory( $dir ) {
		$guards = [
			'.htaccess'  => "# Apache 2.4\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n# Apache 2.2\n<IfModule !mod_authz_core.c>\nOrder deny,allow\nDeny from all\n</IfModule>\n",
			'index.html' => '',
		];

		foreach ( $guards as $name => $contents ) {
			if ( file_exists( $dir . $name ) ) {
				continue;
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents -- Best-effort directory guard written at creation time; WP_Filesystem may prompt for credentials and is unavailable on the public request that first creates this directory.
			file_put_contents( $dir . $name, $contents );
		}
	}
}
