<?php
/**
 * Multilingual String Backfill.
 *
 * Registers String Packages for forms that already existed before the
 * multilingual integration became active. Without this, a form's strings only
 * reach the multilingual provider when the form is next re-saved, so existing
 * forms stay invisible to WPML until manually opened and saved again.
 *
 * @package sureforms.
 * @since 2.12.3
 */

namespace SRFM\Inc\Compatibility\Multilingual;

use SRFM\Inc\Helper;
use SRFM\Inc\Traits\Get_Instance;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * String_Backfill.
 *
 * One-time (per {@see self::SCHEMA_VERSION}) pass that runs
 * {@see String_Collector::collect()} for every existing form once a multilingual
 * provider is active, so their String Packages are registered without needing a
 * manual re-save.
 *
 * @since 2.12.3
 */
class String_Backfill {
	use Get_Instance;

	/**
	 * Option that records the schema version the backfill last completed for.
	 *
	 * @since 2.12.3
	 */
	public const DONE_OPTION = 'srfm_wpml_backfill_done';

	/**
	 * Backfill schema version. Bump this ONLY when the set of strings registered
	 * by String_Collector changes, to force a one-time re-backfill. Deliberately
	 * NOT tied to SRFM_VER, so ordinary plugin releases don't re-enqueue a job
	 * per form on every update.
	 *
	 * @since 2.12.3
	 */
	public const SCHEMA_VERSION = '1';

	/**
	 * Action Scheduler hook that backfills a single form.
	 *
	 * @since 2.12.3
	 */
	public const HOOK = 'srfm_wpml_backfill_form';

	/**
	 * Action Scheduler hook that backfills one page of forms and chains the next.
	 *
	 * @since 2.12.3
	 */
	public const HOOK_BATCH = 'srfm_wpml_backfill_batch';

	/**
	 * Per-form meta key recording the schema version a form was last backfilled for.
	 *
	 * Makes the pass idempotent per form, so a replayed or partially-failed batch
	 * never re-registers packages it already handled.
	 *
	 * @since 2.12.3
	 */
	public const FORM_MARKER = '_srfm_wpml_backfilled';

	/**
	 * Forms processed per batch action.
	 *
	 * @since 2.12.3
	 */
	private const BATCH_SIZE = 50;

	/**
	 * Constructor. Schedules the backfill on admin load and handles each queued form.
	 *
	 * @since 2.12.3
	 */
	public function __construct() {
		add_action( 'admin_init', [ $this, 'maybe_schedule' ] );
		add_action( self::HOOK_BATCH, [ $this, 'backfill_batch' ], 10, 1 );
		add_action( self::HOOK, [ $this, 'backfill_one' ], 10, 1 );
	}

	/**
	 * Queue a one-time backfill of all existing forms when a provider is active.
	 *
	 * Bails when the request is not a privileged admin page load, when no
	 * multilingual provider is active, when the backfill has already completed for
	 * the current schema version, or when Action Scheduler is unavailable.
	 *
	 * Enqueues ONE paginating batch action rather than one action per form. Action
	 * Scheduler's `$unique` flag matches on (status, hook, group_id) only — it does
	 * NOT consider `args` — so a per-form fan-out with `$unique = true` inserted the
	 * first form and then silently wrote 0 rows for every subsequent one, leaving
	 * exactly one form backfilled per site. A single self-chaining batch action never
	 * has more than one pending row for this hook, so uniqueness is irrelevant and
	 * the query stays bounded.
	 *
	 * @since 2.12.3
	 * @return void
	 */
	public function maybe_schedule(): void {
		// Only a genuine, privileged admin page load may start this. `admin_init`
		// also fires inside admin-ajax.php, which is reachable unauthenticated, and
		// this pass is state-changing and touches every form.
		if ( wp_doing_ajax() || wp_doing_cron() || ! Helper::current_user_can() ) {
			return;
		}

		if ( ! Multilingual_Manager::get_instance()->provider()->is_active() ) {
			return;
		}

		if ( self::SCHEMA_VERSION === get_option( self::DONE_OPTION ) ) {
			return;
		}

		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			return;
		}

		// Close the concurrency window BEFORE enqueuing. Writing the marker after the
		// enqueue let N concurrent admin requests each run the whole pass before any
		// of them recorded completion. autoload=false: this admin-only marker never
		// needs to load on the front end.
		update_option( self::DONE_OPTION, self::SCHEMA_VERSION, false );

		as_enqueue_async_action( self::HOOK_BATCH, [ 'paged' => 1 ], 'srfm' );
	}

	/**
	 * Backfill one page of forms, then chain the next page.
	 *
	 * Runs String_Collector directly per form (rather than enqueuing a child action
	 * each) and re-enqueues itself for the following page until a page comes back
	 * empty. Each form is marked with self::FORM_MARKER on success, so a batch that
	 * dies part-way can be replayed without re-registering packages it already did.
	 *
	 * @param mixed $paged 1-based page number.
	 * @since 2.12.3
	 * @return void
	 */
	public function backfill_batch( $paged = 1 ): void {
		// Args arrive from Action Scheduler, so the type is not guaranteed.
		$paged = is_numeric( $paged ) ? max( 1, (int) $paged ) : 1;

		$form_ids = get_posts(
			[
				'post_type'      => SRFM_FORMS_POST_TYPE,
				'post_status'    => [ 'publish', 'draft', 'pending', 'private', 'future' ],
				'fields'         => 'ids',
				'posts_per_page' => self::BATCH_SIZE, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- Bounded constant (50); batching is the point of this query.
				'paged'          => $paged,
				'orderby'        => 'ID',
				'order'          => 'ASC',
			]
		);

		if ( empty( $form_ids ) || ! is_array( $form_ids ) ) {
			return;
		}

		foreach ( $form_ids as $form_id ) {
			$this->backfill_one( (int) $form_id );
		}

		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			return;
		}

		// One pending action at a time, so $unique is unnecessary here.
		as_enqueue_async_action( self::HOOK_BATCH, [ 'paged' => $paged + 1 ], 'srfm' );
	}

	/**
	 * Backfill a single form's String Package.
	 *
	 * @param int $form_id The form post ID to backfill.
	 * @since 2.12.3
	 * @return void
	 */
	public function backfill_one( int $form_id ): void {
		if ( $form_id <= 0 ) {
			return;
		}

		// Idempotency guard: a replayed batch must not re-register packages for forms
		// it already processed under this schema version.
		if ( self::SCHEMA_VERSION === get_post_meta( $form_id, self::FORM_MARKER, true ) ) {
			return;
		}

		String_Collector::get_instance()->collect( $form_id );

		update_post_meta( $form_id, self::FORM_MARKER, self::SCHEMA_VERSION );
	}
}
