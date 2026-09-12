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
 * Returns the normalised string rather than the input. What is checked has to be
 * what is used: validating one spelling and handing the caller another is how a
 * scheme check gets walked past.
 *
 * Returns undefined rather than '' so the caller's `&&` guards drop the anchor
 * entirely instead of rendering one that is not keyboard focusable.
 *
 * @param {string} url Candidate URL.
 * @return {string|undefined} The normalised URL, or undefined when it is not
 *                            safe to bind.
 */
const safeUrl = ( url ) => {
	if ( typeof url !== 'string' || '' === url ) {
		return undefined;
	}

	// The same normalisation a browser applies before it parses the scheme: C0
	// controls and spaces are trimmed from both ends, and tab, LF and CR are
	// removed from anywhere in the string. Without it the scheme test reads a
	// spelling the DOM never sees -- a leading space, or a tab inside the word
	// "javascript", both fail to match `^[a-z]` and were returned untouched.
	const normalised = url
		// eslint-disable-next-line no-control-regex
		.replace( /^[\u0000-\u0020\u007f]+|[\u0000-\u0020\u007f]+$/g, '' )
		.replace( /[\t\n\r]/g, '' );

	if ( '' === normalised ) {
		return undefined;
	}

	// Protocol-relative, and the backslash spelling of it that the URL standard
	// folds to the same thing under an http(s) base. It carries no scheme to
	// check and resolves to a different origin, so "safe because it is relative"
	// is exactly wrong here.
	if ( /^[/\\]{2}/.test( normalised ) ) {
		return undefined;
	}

	// Genuinely relative: a path, a query or a fragment, all same-origin.
	if ( ! /^[a-z][a-z0-9+.-]*:/i.test( normalised ) ) {
		return normalised;
	}

	return /^(?:https?|mailto):/i.test( normalised ) ? normalised : undefined;
};

export default safeUrl;
