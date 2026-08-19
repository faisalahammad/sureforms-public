/**
 * Shared deep-link contract for the form editor's "Finish setting up" CTAs (#3030).
 *
 * The dashboard notice opens the editor with ?srfm_focus=… to auto-open a Form
 * Settings tab. Gutenberg strips unrecognised query args client-side before the
 * bundle can reliably read them, so PHP surfaces the value as
 * `window.srfmDeepLinkFocus` (printed before this bundle); read that first, URL
 * as fallback.
 *
 * Kept in its own dependency-free module so Editor.js, GeneralSettings.js and
 * EmailNotification.js share one source: no import cycle and one place to reason
 * about the contract.
 */

// Maps the ?srfm_focus value to a Form Settings tab id.
export const SRFM_DEEP_LINK_TABS = {
	thankyou: 'form_confirmation',
	notifications: 'email_notification',
};

// Raw target, guarded so importing this module in a non-browser context
// (jsdom / unit tests) can't ReferenceError on `window`.
const rawDeepLinkFocus =
	typeof window === 'undefined'
		? null
		: window.srfmDeepLinkFocus ||
		  new URLSearchParams( window.location.search ).get( 'srfm_focus' );

// Expose only a value the map recognises (resolved via hasOwnProperty so
// ?srfm_focus=constructor can't reach a prototype member); anything else is ''.
// This keeps the downstream guards belt-and-braces rather than the sole defence.
export const srfmDeepLinkFocus =
	rawDeepLinkFocus &&
	Object.prototype.hasOwnProperty.call( SRFM_DEEP_LINK_TABS, rawDeepLinkFocus )
		? rawDeepLinkFocus
		: '';

// Shared signal: GeneralSettings sets `consumed` true once it has mounted and
// dispatched the deep-link, so the editor root's opener loop can stop
// deterministically instead of inferring success from churning store selectors.
export const deepLinkState = { consumed: false };
