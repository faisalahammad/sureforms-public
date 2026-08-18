/**
 * Shared deep-link contract for the form editor's "Finish setting up" CTAs (#3030).
 *
 * The dashboard notice opens the editor with ?srfm_focus=… to auto-open a Form
 * Settings tab. Gutenberg strips unrecognised query args client-side before the
 * bundle can reliably read them, so PHP surfaces the value as
 * `window.srfmDeepLinkFocus` (printed before this bundle) — read that first,
 * falling back to the raw URL for safety.
 *
 * Kept in its own dependency-free module so Editor.js, GeneralSettings.js and
 * EmailNotification.js share one source: no import cycle (which is why
 * EmailNotification previously re-derived the value from `window`, diverging on
 * the URL-fallback path) and one place to reason about the contract.
 */
export const srfmDeepLinkFocus =
	( typeof window !== 'undefined' && window.srfmDeepLinkFocus ) ||
	new URLSearchParams( window.location.search ).get( 'srfm_focus' );

// Maps the ?srfm_focus value to a Form Settings tab id. Read through
// hasOwnProperty (never prototype members) so ?srfm_focus=constructor resolves to
// nothing rather than handing a function to setPopupTab.
export const SRFM_DEEP_LINK_TABS = {
	thankyou: 'form_confirmation',
	notifications: 'email_notification',
};
