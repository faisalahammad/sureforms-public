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
	 * Constructor. Schedules the backfill on admin load and handles each queued form.
	 *
	 * @since 2.12.3
	 */
	public function __construct() {
		add_action( 'admin_init', [ $this, 'maybe_schedule' ] );
		add_action( self::HOOK, [ $this, 'backfill_one' ], 10, 1 );
	}

	/**
	 * Queue a one-time backfill of all existing forms when a provider is active.
	 *
	 * Bails when no multilingual provider is active, when the backfill has
	 * already completed for the current schema version, or when Action Scheduler
	 * is unavailable. Each form is processed in its own async job so a large form
	 * count never blocks the request.
	 *
	 * @since 2.12.3
	 * @return void
	 */
	public function maybe_schedule(): void {
		if ( ! Multilingual_Manager::get_instance()->provider()->is_active() ) {
			return;
		}

		if ( self::SCHEMA_VERSION === get_option( self::DONE_OPTION ) ) {
			return;
		}

		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			return;
		}

		$form_ids = get_posts(
			[
				'post_type'   => SRFM_FORMS_POST_TYPE,
				'post_status' => [ 'publish', 'draft', 'pending', 'private', 'future' ],
				'fields'      => 'ids',
				'numberposts' => -1,
			]
		);

		foreach ( $form_ids as $form_id ) {
			$form_id = (int) $form_id;
			// $unique = true: dedupe by (hook, args, group) so concurrent admin
			// requests or an interrupted-then-retried pass never double-queue a form.
			as_enqueue_async_action( self::HOOK, [ 'form_id' => $form_id ], 'srfm', true );
		}

		// Record completion up front so the pass doesn't re-queue on the next admin
		// load. autoload=false: this admin-only marker never needs to load on the
		// front end.
		update_option( self::DONE_OPTION, self::SCHEMA_VERSION, false );
	}

	/**
	 * Backfill a single form's String Package.
	 *
	 * @param int $form_id The form post ID to backfill.
	 * @since 2.12.3
	 * @return void
	 */
	public function backfill_one( int $form_id ): void {
		String_Collector::get_instance()->collect( $form_id );
	}
}
