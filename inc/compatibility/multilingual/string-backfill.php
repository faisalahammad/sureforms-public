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
 * @since x.x.x
 */

namespace SRFM\Inc\Compatibility\Multilingual;

use SRFM\Inc\Traits\Get_Instance;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * String_Backfill.
 *
 * One-time (per plugin version) pass that runs {@see String_Collector::collect()}
 * for every existing form once a multilingual provider is active, so their
 * String Packages are registered without needing a manual re-save.
 *
 * @since x.x.x
 */
class String_Backfill {
	use Get_Instance;

	/**
	 * Option that records the plugin version the backfill last completed for.
	 * Storing the version (not a bare flag) lets a future release re-run the
	 * backfill if the registered string set changes.
	 *
	 * @since x.x.x
	 */
	public const DONE_OPTION = 'srfm_wpml_backfill_done';

	/**
	 * Action Scheduler hook that backfills a single form.
	 *
	 * @since x.x.x
	 */
	public const HOOK = 'srfm_wpml_backfill_form';

	/**
	 * Constructor. Schedules the backfill on admin load and handles each queued form.
	 *
	 * @since x.x.x
	 */
	public function __construct() {
		add_action( 'admin_init', [ $this, 'maybe_schedule' ] );
		add_action( self::HOOK, [ $this, 'backfill_one' ], 10, 1 );
	}

	/**
	 * Queue a one-time backfill of all existing forms when a provider is active.
	 *
	 * Bails when no multilingual provider is active, when the backfill has
	 * already completed for the current plugin version, or when Action Scheduler
	 * is unavailable. Each form is processed in its own async job so a large form
	 * count never blocks the request.
	 *
	 * @since x.x.x
	 * @return void
	 */
	public function maybe_schedule(): void {
		if ( ! Multilingual_Manager::get_instance()->provider()->is_active() ) {
			return;
		}

		if ( SRFM_VER === get_option( self::DONE_OPTION ) ) {
			return;
		}

		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			return;
		}

		$form_ids = get_posts(
			[
				'post_type'   => SRFM_FORMS_POST_TYPE,
				'post_status' => [ 'publish', 'draft', 'pending', 'private' ],
				'fields'      => 'ids',
				'numberposts' => -1,
			]
		);

		foreach ( $form_ids as $form_id ) {
			as_enqueue_async_action( self::HOOK, [ 'form_id' => (int) $form_id ], 'srfm' );
		}

		// Record completion up front: the per-form jobs are queued, and this pass
		// must not re-queue them on the next admin load.
		update_option( self::DONE_OPTION, SRFM_VER );
	}

	/**
	 * Backfill a single form's String Package.
	 *
	 * @param int $form_id The form post ID to backfill.
	 * @since x.x.x
	 * @return void
	 */
	public function backfill_one( int $form_id ): void {
		String_Collector::get_instance()->collect( (int) $form_id );
	}
}
