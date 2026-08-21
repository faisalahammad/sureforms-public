<?php
/**
 * SureForms Form Views tracker.
 *
 * Cache-safe, per-page-load form view (impression) tracking used to power the
 * Views and Conversion Rate columns on the Forms listing table.
 *
 * Views are recorded by a client-side beacon (see assets/js/unminified/form-submit.js)
 * that fires when a form actually becomes visible, so counting works even on
 * fully page-cached pages and non-JS crawlers are excluded naturally. The beacon
 * posts to the public `sureforms/v1/forms/track-view` route, which is authenticated with
 * the same HMAC Submit_Token used by form submission (not a nonce, which would break
 * under full-page caching).
 *
 * @package sureforms
 * @since x.x.x
 */

namespace SRFM\Inc;

use SRFM\Inc\Traits\Get_Instance;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Form Views tracker.
 *
 * @since x.x.x
 */
class Form_Views {
	use Get_Instance;

	/**
	 * Post meta key that stores the accumulated view count for a form.
	 */
	public const META_KEY = '_srfm_form_views';

	/**
	 * General-settings key that toggles view + conversion-rate tracking. Lives in
	 * the `srfm_general_settings_options` option; defaults ON when the key is absent
	 * (installs that predate this setting).
	 */
	public const SETTING_KEY = 'srfm_form_views_tracking';

	/**
	 * Option holding the unix time from which view counting has been running.
	 *
	 * Conversion rate divides entries by views, and views only start accruing when
	 * tracking is switched on — so counting entries from the beginning of time would
	 * compare two different periods and report a wildly inflated rate on any form
	 * that existed beforehand. Entries are therefore counted from this moment too.
	 *
	 * Deliberately its own option rather than a key inside
	 * `srfm_general_settings_options`: that array is rebuilt from an allowlist when
	 * settings are saved, so an unrecognised key added to it would be silently
	 * dropped on the next save.
	 */
	public const TRACKING_STARTED_OPTION = 'srfm_form_views_tracking_started_at';

	/**
	 * Max beacon hits accepted per visitor IP + form within the rate-limit window.
	 */
	private const RATE_LIMIT_MAX = 20;

	/**
	 * Constructor.
	 *
	 * @since x.x.x
	 */
	public function __construct() {
		add_filter( 'srfm_rest_api_endpoints', [ $this, 'register_route' ] );
		// Localize on wp_footer (before wp_print_footer_scripts at priority 20) rather than
		// wp_enqueue_scripts: forms embedded via page builders, widget shortcodes, or a late
		// do_blocks() pass enqueue srfm-form-submit AFTER wp_enqueue_scripts has run, and
		// those would otherwise never receive the beacon flag.
		add_action( 'wp_footer', [ $this, 'localize_beacon' ], 5 );
	}

	/**
	 * Unix time from which views have been counted.
	 *
	 * Written once, on first use, and never rewritten. Tracking is on by default, so
	 * there is no toggle event to hang this on for the majority of installs — the
	 * window simply opens the first time anything asks for it, which on an upgrade is
	 * the moment the feature becomes live.
	 *
	 * Not re-stamped when tracking is switched off and on again: the stored view
	 * counts from before the gap are kept, so moving the start forward would measure
	 * those views against a shorter entry window. Entries that arrive while tracking
	 * is off do skew the rate slightly, and the entries-exceed-views guard in
	 * Forms_Data covers the case where that skew makes the number meaningless.
	 *
	 * add_option() rather than update_option() so a concurrent request cannot move a
	 * window that is already open.
	 *
	 * @since x.x.x
	 * @return int Unix timestamp.
	 */
	public function get_tracking_started_at() {
		$started = Helper::get_integer_value( get_option( self::TRACKING_STARTED_OPTION, 0 ) );

		if ( $started > 0 ) {
			return $started;
		}

		$started = time();

		// Only creates the option when it does not already exist, so the first writer
		// wins and later calls read that value back.
		if ( ! add_option( self::TRACKING_STARTED_OPTION, $started, '', false ) ) {
			$started = Helper::get_integer_value( get_option( self::TRACKING_STARTED_OPTION, $started ) );
		}

		return $started;
	}

	/**
	 * Register the public `forms/track-view` REST route on the SureForms endpoints array.
	 *
	 * @param array<string,mixed> $endpoints Existing endpoint definitions.
	 * @since x.x.x
	 * @return array<string,mixed> Endpoints with the track-view route added.
	 */
	public function register_route( $endpoints ) {
		if ( ! is_array( $endpoints ) ) {
			return $endpoints;
		}

		$endpoints['forms/track-view'] = [
			'methods'             => 'POST',
			'callback'            => [ $this, 'track_view' ],
			'permission_callback' => [ $this, 'permissions_check' ],
			'args'                => [
				'form_id' => [
					'type'     => 'integer',
					'required' => true,
					'minimum'  => 1,
				],
			],
		];

		return $endpoints;
	}

	/**
	 * Permission check: proof-of-origin via the HMAC Submit_Token header (cache-safe).
	 *
	 * @param WP_REST_Request<array<string,mixed>> $request REST request.
	 * @since x.x.x
	 * @return true|WP_Error
	 */
	public function permissions_check( $request ) {
		$token   = Helper::get_string_value( $request->get_header( 'X-WP-Submit-Token' ) );
		$form_id = absint( Helper::get_integer_value( $request->get_param( 'form_id' ) ) );

		if ( ! Submit_Token::verify( $token, $form_id ) ) {
			return new WP_Error(
				'srfm_view_token_invalid',
				__( 'Security verification failed.', 'sureforms' ),
				[ 'status' => 403 ]
			);
		}

		return true;
	}

	/**
	 * Record a view for the given form.
	 *
	 * Silently no-ops (200) for excluded contexts so the beacon never surfaces an
	 * error to visitors; returns 429 only when the IP + form rate limit is exceeded.
	 *
	 * @param WP_REST_Request<array<string,mixed>> $request REST request.
	 * @since x.x.x
	 * @return WP_REST_Response
	 */
	public function track_view( $request ) {
		$form_id = absint( Helper::get_integer_value( $request->get_param( 'form_id' ) ) );

		if ( ! $form_id || SRFM_FORMS_POST_TYPE !== get_post_type( $form_id ) ) {
			return new WP_REST_Response( [ 'counted' => false ], 200 );
		}

		// Exclude previews and logged-in privileged users (author/editor/admin).
		if ( ! $this->should_track() ) {
			return new WP_REST_Response( [ 'counted' => false ], 200 );
		}

		if ( $this->is_rate_limited( $form_id ) ) {
			return new WP_REST_Response( [ 'counted' => false ], 429 );
		}

		$this->increment_views( $form_id );

		return new WP_REST_Response( [ 'counted' => true ], 200 );
	}

	/**
	 * Localize the beacon enable flag onto the (already enqueued) form-submit script.
	 *
	 * @since x.x.x
	 * @return void
	 */
	public function localize_beacon() {
		if ( ! wp_script_is( 'srfm-form-submit', 'enqueued' ) ) {
			return;
		}

		wp_localize_script(
			'srfm-form-submit',
			'srfm_view_beacon',
			[ 'enabled' => $this->should_track() ]
		);
	}

	/**
	 * Read the current view count for a form.
	 *
	 * @param int $form_id Form post ID.
	 * @since x.x.x
	 * @return int
	 */
	public function get_views( $form_id ) {
		return max( 0, Helper::get_integer_value( get_post_meta( $form_id, self::META_KEY, true ) ) );
	}

	/**
	 * Whether the Views and Conversion Rate columns are shown on the Forms list.
	 *
	 * Despite the setting key's name this governs display only — counting runs
	 * regardless, so switching the columns back on reveals the period they were
	 * hidden for rather than a gap. See should_track().
	 *
	 * Defaults to true when the setting has never been saved, so existing installs
	 * get the columns without an explicit opt-in.
	 *
	 * @since x.x.x
	 * @return bool
	 */
	public function is_tracking_enabled() {
		$general = get_option( 'srfm_general_settings_options', [] );

		if ( ! is_array( $general ) || ! isset( $general[ self::SETTING_KEY ] ) ) {
			return true;
		}

		return (bool) $general[ self::SETTING_KEY ];
	}

	/**
	 * Whether the current request should be counted as a view.
	 *
	 * Excludes logged-in users who can edit content (author/editor/admin) and
	 * form-builder / Instant Form live previews.
	 *
	 * Deliberately does not consult the General-settings toggle: that setting governs
	 * whether the Views and Conversion Rate columns are shown, not whether counting
	 * happens. Counting continues in the background so switching the columns back on
	 * reveals the period they were hidden for, rather than a gap.
	 *
	 * @since x.x.x
	 * @return bool
	 */
	private function should_track() {
		if ( is_user_logged_in() && current_user_can( 'edit_posts' ) ) {
			return false;
		}

		if ( ! empty( Helper::get_instant_form_live_data() ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Atomically increment the stored view count for a form.
	 *
	 * @param int $form_id Form post ID.
	 * @since x.x.x
	 * @return void
	 */
	private function increment_views( $form_id ) {
		global $wpdb;

		// Atomic increment so concurrent beacons don't clobber each other.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->postmeta} SET meta_value = meta_value + 1 WHERE post_id = %d AND meta_key = %s",
				$form_id,
				self::META_KEY
			)
		);

		if ( empty( $updated ) ) {
			// Row didn't exist yet — create it (unique so a racing insert can't duplicate).
			add_post_meta( $form_id, self::META_KEY, 1, true );
		}

		// Keep the post-meta cache consistent after the direct write.
		wp_cache_delete( $form_id, 'post_meta' );
	}

	/**
	 * Per visitor-IP + form rate limit. Fails closed when the IP is undeterminable.
	 *
	 * @param int $form_id Form post ID.
	 * @since x.x.x
	 * @return bool True when the request should be blocked.
	 */
	private function is_rate_limited( $form_id ) {
		// Use the connection's REMOTE_ADDR, not Helper::get_visitor_ip() (which trusts
		// client-supplied X-Forwarded-For / Client-IP headers). Otherwise an attacker
		// could send a fresh spoofed forwarded IP per request, get a new rate-limit
		// bucket each time, and inflate the view counter without bound.
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

		if ( empty( $ip ) || ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return true; // Fail closed if IP cannot be determined.
		}

		$transient_key = 'srfm_view_' . md5( $ip . '_' . $form_id );
		$attempts      = get_transient( $transient_key );

		if ( false === $attempts ) {
			set_transient( $transient_key, 1, MINUTE_IN_SECONDS );
			return false;
		}

		$attempts_count = Helper::get_integer_value( $attempts );

		if ( $attempts_count >= self::RATE_LIMIT_MAX ) {
			return true;
		}

		set_transient( $transient_key, $attempts_count + 1, MINUTE_IN_SECONDS );
		return false;
	}
}
