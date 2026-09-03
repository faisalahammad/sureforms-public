<?php
/**
 * Breakdance Compatibility.
 *
 * @package sureforms
 * @since   x.x.x
 */

namespace SRFM\Inc\Compatibility\Page_Builders;

use SRFM\Inc\Traits\Get_Instance;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Breakdance compatibility for the Instant Form page.
 *
 * Breakdance renders the whole page through a `template_include` filter of its
 * own: it buffers the theme template, swaps its asset placeholders into the
 * result, and returns a relay template that echoes the finished HTML.
 *
 * SureForms filters `template_include` at PHP_INT_MAX, so on a single form it
 * replaces that relay with the Instant Form template. The buffered page is
 * discarded -- but the work that produced it is not. `wp_head()` already ran
 * once inside the buffer, so every stylesheet is recorded in
 * `WP_Styles::$done`, and when the Instant Form template calls `wp_head()` the
 * second time none of them print again. The form arrives with no CSS at all.
 *
 * Standing Breakdance's filter down on these pages costs nothing that works
 * today: its output was already being thrown away. It removes the wasted render,
 * leaves `wp_head()` firing once, and lets the styles print normally.
 *
 * @since x.x.x
 */
class Breakdance {
	use Get_Instance;

	/**
	 * Breakdance's own `template_include` callback, as it registers it.
	 *
	 * @since x.x.x
	 */
	private const CALLBACK = 'Breakdance\ActionsFilters\template_include';

	/**
	 * Priority Breakdance registers that callback at.
	 *
	 * Matched exactly because remove_filter() needs the priority to find the
	 * entry. If Breakdance ever moves it, this stops applying rather than
	 * removing something else by accident.
	 *
	 * @since x.x.x
	 */
	private const PRIORITY = 1000000;

	/**
	 * Constructor
	 *
	 * @since x.x.x
	 */
	public function __construct() {
		// `wp` is the last hook before template_include is applied, and the first
		// at which the queried object is known -- so is_singular() is answerable
		// and Breakdance's filter has not run yet.
		add_action( 'wp', [ $this, 'stand_down_on_instant_form' ] );
	}

	/**
	 * Take Breakdance out of the chain on a single form.
	 *
	 * @since x.x.x
	 * @return void
	 */
	public function stand_down_on_instant_form() {
		if ( ! is_singular( SRFM_FORMS_POST_TYPE ) ) {
			return;
		}

		// Guarded on the filter actually being registered rather than on a
		// Breakdance version constant: this is only correct while that exact
		// callback is in the chain, and that is the thing worth testing.
		if ( ! has_filter( 'template_include', self::CALLBACK ) ) {
			return;
		}

		remove_filter( 'template_include', self::CALLBACK, self::PRIORITY );
	}
}
