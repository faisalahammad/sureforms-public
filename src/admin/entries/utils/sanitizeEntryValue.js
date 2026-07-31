import domPurify from 'dompurify';

/**
 * Sanitizer policy for entry values that legitimately contain markup
 * (rich-text textarea content shown in the entries admin view).
 *
 * DOMPurify's DEFAULT allowlist is deliberately broad — verified against 3.3.3,
 * it permits `style`, `form`, `input`, `button` and the SVG/MathML namespaces.
 * That is wrong for this screen for two reasons:
 *
 *  1. None of that is script execution, but an unauthenticated form submitter
 *     could still land document-wide CSS or a cross-origin `<form action>` in
 *     wp-admin (UI redressing, defacement, click-jacked POSTs). An ATTACKER
 *     payload reaches this sink with no server-side filtering at all: submitted
 *     as entity-encoded text it contains no literal `<`, so PHP treats it as
 *     inert text and every kses pass leaves it alone — then
 *     decodeHTMLEntities() turns it back into live markup on the client, just
 *     before this call. DOMPurify is the ONLY guard standing here.
 *  2. Dropping the SVG/MathML namespaces removes `<foreignObject>` — the exact
 *     namespace CVE-2026-18406 used — and with it the `<svg><style>` shape,
 *     which is the one construct able to slip past DOMPurify's own anti-mXSS
 *     guard (that guard only fires on elements with no element children).
 *
 * Note the asymmetry with LEGITIMATE rich text, which travels a different route:
 * it arrives as real markup and is filtered by Helper::sanitize_textarea(). That
 * function does NOT strip style attributes — disable_style_attr_parsing() returns
 * an empty allowlist, and WordPress's safecss_filter_attr() early-returns the CSS
 * unchanged when the allowlist is empty (kses.php), a behaviour pinned by
 * tests/unit/inc/test-helper.php. Stored rich text therefore legitimately carries
 * `style="color:…"`, `text-align` and `direction`, so `style` must be filtered
 * per-property here rather than forbidden outright.
 *
 * @since 2.12.3
 */
export const RICH_TEXT_SANITIZE_CONFIG = {
	USE_PROFILES: { html: true },
	FORBID_TAGS: [ 'style', 'form', 'input', 'button', 'select', 'textarea' ],
	// `class` is forbidden for the same reason the CSS allowlist below exists:
	// this is a Tailwind admin app whose content glob covers all of src/, so every
	// utility used anywhere is compiled into this bundle. Without this, a stored
	// `class="fixed inset-0 z-[99999999]"` reproduces the exact full-viewport
	// overlay the style filtering is there to prevent. Rich text does not need it.
	FORBID_ATTR: [ 'action', 'formaction', 'class' ],
	ALLOW_DATA_ATTR: false,
};

/**
 * CSS properties the rich-text editor legitimately emits.
 *
 * `style` is deliberately NOT in FORBID_ATTR: the editor registers Quill's
 * *style* attributors for alignment and direction (see
 * assets/js/unminified/blocks/textarea.js) and its colour/highlight formats are
 * style-based too, and `Helper::sanitize_textarea()` preserves them —
 * `disable_style_attr_parsing()` returns an empty allowlist, which makes
 * WordPress's safecss_filter_attr() SKIP filtering rather than strip it (pinned
 * by tests/unit/inc/test-helper.php). Forbidding `style` outright would silently
 * de-colour and un-align every rich-text entry already in the database.
 *
 * So allow the attribute but keep only these declarations. That blocks the
 * properties an attacker actually needs — `position`/`inset`/`z-index`/`width`
 * for a full-viewport overlay, and anything able to fetch a URL — while leaving
 * legitimate formatting untouched.
 *
 * @since 2.12.3
 */
const ALLOWED_CSS_PROPERTIES = [
	'color',
	'background-color',
	'text-align',
	'direction',
];

/**
 * Strip every CSS declaration that is not on the allowlist, and any value that
 * can reach out to a URL or re-enter a script context.
 *
 * Runs as a DOMPurify hook so it operates on the live node during sanitization —
 * never by re-parsing the serialized output, which is the mistake that caused
 * CVE-2026-18406 in the first place.
 *
 * @param {Element} node Node being sanitized.
 * @return {void}
 */
const filterStyleAttribute = ( node ) => {
	if ( ! node?.hasAttribute?.( 'style' ) ) {
		return;
	}

	const safe = node
		.getAttribute( 'style' )
		.split( ';' )
		.map( ( declaration ) => declaration.trim() )
		.filter( Boolean )
		.filter( ( declaration ) => {
			const separator = declaration.indexOf( ':' );
			const property = declaration
				.slice( 0, separator )
				.trim()
				.toLowerCase();

			if ( ! ALLOWED_CSS_PROPERTIES.includes( property ) ) {
				return false;
			}

			// Even on an allowed property, refuse anything that can fetch a
			// resource or smuggle in another context.
			const value = declaration.slice( separator + 1 ).toLowerCase();

			return ! /url\(|expression\(|javascript:|@import|\\/i.test( value );
		} )
		.join( '; ' );

	if ( safe ) {
		node.setAttribute( 'style', safe );
	} else {
		node.removeAttribute( 'style' );
	}
};

domPurify.addHook( 'afterSanitizeAttributes', filterStyleAttribute );

/**
 * Sanitize an entry value that may legitimately contain rich-text markup.
 *
 * CVE-2026-18406: the result MUST be inserted directly into the DOM (i.e. via
 * `dangerouslySetInnerHTML`). Never pass it to a second HTML parser such as
 * `html-react-parser` — the re-parse decodes entities DOMPurify deliberately
 * left inert and resurrects the payload. Sanitizer-straight-to-DOM is
 * DOMPurify's documented, mXSS-safe contract.
 *
 * @param {string} value Raw (entity-decoded) entry value.
 * @return {string} Sanitized HTML, safe for direct DOM insertion only.
 */
export const sanitizeEntryValue = ( value ) =>
	domPurify.sanitize( value, RICH_TEXT_SANITIZE_CONFIG );
