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
	 * Option holding the run of consecutive faults with no successful submission.
	 *
	 * @since x.x.x
	 */
	public const FAULT_STREAK_OPTION = 'srfm_client_log_fault_streak';

	/**
	 * Consecutive faults before the site owner is told something is wrong.
	 *
	 * @since x.x.x
	 */
	public const FAULT_THRESHOLD = 5;

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
	 * On by default, including on installs whose stored settings predate the
	 * option. The point of the log is that the evidence already exists when a
	 * support ticket arrives -- a default of off would mean asking the reporter to
	 * enable it and reproduce, which is the round trip this feature removes.
	 *
	 * Costs nothing on a healthy site: only failures are ever written, so a site
	 * whose forms work never creates the file at all.
	 *
	 * This is the one authority. The frontend also carries a flag, but that flag is
	 * baked into cached HTML and can be a full cache TTL out of date, so every
	 * write path re-checks here.
	 *
	 * @since x.x.x
	 * @return bool
	 */
	public static function is_enabled() {
		$general = get_option( 'srfm_general_settings_options', [] );

		if ( ! is_array( $general ) || ! isset( $general['srfm_enable_logs'] ) ) {
			return true;
		}

		return (bool) $general['srfm_enable_logs'];
	}

	/**
	 * Whether an entry means the site is broken, rather than the visitor.
	 *
	 * This distinction is the whole basis of the failure notice. Most of what the
	 * log records is routine: a mistyped email, an expired captcha, a declined
	 * card, a submission the server rejected by naming the field to fix. Those are
	 * the form working correctly, and counting them would tell healthy sites to
	 * contact support -- on every install, because logging is on by default.
	 *
	 * A fault is what a visitor cannot resolve by trying again correctly: the
	 * request never reached PHP, the response was not JSON, the server returned an
	 * error status, or a notification email could not be sent.
	 *
	 * @param array<string,mixed> $entry Entry as returned by sanitize_entry().
	 * @since x.x.x
	 * @return bool
	 */
	public static function is_fault( array $entry ) {
		$type = $entry['type'] ?? '';

		// Allowlist, so an unrecognised or new category is not a fault by default.
		// 'blocked' is deliberately absent: it is the label the browser puts on a
		// stop the visitor can clear themselves.
		if ( in_array( $type, [ 'error', 'response', 'message' ], true ) ) {
			return true;
		}

		if ( 'network' !== $type ) {
			return false;
		}

		$status = isset( $entry['status'] ) ? Helper::get_integer_value( $entry['status'] ) : 0;

		// 403 is the submit token being refused, which on a cached site means the
		// page is serving a token the server will not accept.
		return $status >= 500 || 403 === $status;
	}

	/**
	 * How many faults have happened with no successful submission in between.
	 *
	 * @since x.x.x
	 * @return int
	 */
	public static function get_fault_streak() {
		return Helper::get_integer_value( get_option( self::FAULT_STREAK_OPTION, 0 ) );
	}

	/**
	 * Whether the form has failed often enough, and recently enough, to say so.
	 *
	 * @since x.x.x
	 * @return bool
	 */
	public static function has_persistent_failures() {
		return self::get_fault_streak() >= self::FAULT_THRESHOLD;
	}

	/**
	 * Clear the run of faults.
	 *
	 * Hooked - srfm_form_submit, which fires only on the success path. One
	 * submission getting through is the most reliable evidence available that the
	 * form is not broken, so it retires the notice without anyone dismissing it.
	 *
	 * @since x.x.x
	 * @return void
	 */
	public static function reset_fault_streak() {
		if ( self::get_fault_streak() > 0 ) {
			update_option( self::FAULT_STREAK_OPTION, 0, false );
		}
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

		// Counted before the file is touched. A full log or an unwritable uploads
		// directory must not stop the site owner being told the form is failing --
		// on a badly broken site those are exactly the conditions that occur.
		if ( self::is_fault( $entry ) ) {
			update_option( self::FAULT_STREAK_OPTION, self::get_fault_streak() + 1, false );
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
	 * The most recent whole log lines, up to a character budget.
	 *
	 * For pasting into a support email, where the transport imposes the limit: a
	 * mailto URL has to survive percent-encoding and every mail client's own
	 * length cap, so only a tail fits. Newest entries are the ones that describe
	 * the failure being reported, so the tail is the useful end.
	 *
	 * Whole lines only -- half a JSON object helps nobody.
	 *
	 * @param int $max_chars Character budget for the returned text.
	 * @since x.x.x
	 * @return array{text:string,shown:int,total:int}
	 */
	public static function get_tail( $max_chars = 1200 ) {
		$empty = [
			'text'  => '',
			'shown' => 0,
			'total' => 0,
		];

		$path = self::get_log_path( false );

		if ( '' === $path || ! file_exists( $path ) ) {
			return $empty;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file, WordPress.WP.AlternativeFunctions.file_system_read_file -- Reading a file this class owns; WP_Filesystem would prompt for credentials and is unavailable here.
		$lines = file( $path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );

		if ( ! is_array( $lines ) || empty( $lines ) ) {
			return $empty;
		}

		$total = count( $lines );
		$kept  = [];
		$used  = 0;

		foreach ( array_reverse( $lines ) as $line ) {
			$length = strlen( $line ) + 1;

			// Always keep one line, even if it alone exceeds the budget: an empty
			// excerpt is worse than a long one.
			if ( $used + $length > $max_chars && ! empty( $kept ) ) {
				break;
			}

			array_unshift( $kept, $line );
			$used += $length;
		}

		return [
			'text'  => implode( "\n", $kept ),
			'shown' => count( $kept ),
			'total' => $total,
		];
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
		$allowed_types = [ 'network', 'response', 'error', 'message', 'blocked' ];
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
