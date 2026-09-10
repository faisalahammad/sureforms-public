/**
 * Bind-safe URL, or undefined.
 *
 * get_action_items() runs every item URL through esc_url_raw() with a scheme
 * allowlist -- but srfm_admin_filter runs afterwards, on the already-built
 * localisation payload, and can rewrite these values. React assigns href as a
 * property, and react-dom 18 leaves a javascript: URL intact with its warning
 * compiled out of production, so the server pass alone is not the last word
 * before the value reaches the DOM.
 *
 * Returns undefined rather than '' so the caller's `&&` guards drop the anchor
 * entirely instead of rendering one that is not keyboard focusable.
 *
 * @param {string} url Candidate URL.
 * @return {string|undefined} The URL, or undefined when its scheme is not allowed.
 */
const safeUrl = ( url ) => {
	if ( typeof url !== 'string' || '' === url ) {
		return undefined;
	}

	// Relative and same-document URLs carry no scheme and are safe.
	if ( ! /^[a-z][a-z0-9+.-]*:/i.test( url ) ) {
		return url;
	}

	return /^(?:https?|mailto):/i.test( url ) ? url : undefined;
};

export default safeUrl;
