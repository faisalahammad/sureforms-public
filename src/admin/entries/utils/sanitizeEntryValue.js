import createDOMPurify from 'dompurify';

/**
 * Sanitizer policy for entry content that legitimately contains markup —
 * rich-text textarea values and Pro change-log messages shown in the entries
 * admin view.
 *
 * DOMPurify's DEFAULT allowlist is deliberately broad — verified against 3.3.3,
 * it permits `style`, `form`, `input`, `button`, the media elements and the
 * SVG/MathML namespaces. That is wrong for this screen for three reasons:
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
 *  3. The media elements are a zero-click outbound request from the reviewing
 *     admin's session — see the comment on FORBID_TAGS below.
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
 * @since x.x.x
 */
export const RICH_TEXT_SANITIZE_CONFIG = {
	USE_PROFILES: { html: true },
	FORBID_TAGS: [
		'style',
		'form',
		'input',
		'button',
		'select',
		'textarea',
		// No legitimate stored rich text contains media: the Quill toolbar
		// (assets/js/unminified/blocks/textarea.js) offers header, bold/italic/
		// underline/strike, lists, blockquote, align, colour, link and clean —
		// no image, video or audio button. Allowing them would let an
		// UNAUTHENTICATED submitter force the reviewing admin's browser into an
		// attacker-controlled GET on entry view: IP / UA / Accept-Language
		// fingerprint exfiltration, read-receipt confirmation, and same-origin
		// GET CSRF against any nonce-less admin.php?action= handler.
		'img',
		'picture',
		'source',
		'video',
		'audio',
		'track',
		'object',
		'embed',
	],
	// `class` is forbidden for the same reason the CSS allowlist below exists:
	// this is a Tailwind admin app whose content glob covers all of src/, so every
	// utility used anywhere is compiled into this bundle. Without this, a stored
	// `class="fixed inset-0 z-[99999999]"` reproduces the exact full-viewport
	// overlay the style filtering is there to prevent. Rich text does not need it.
	// `srcset`/`poster`/`background` are belt-and-braces alongside the media tags
	// above, so a future widening of FORBID_TAGS cannot silently reopen the fetch.
	FORBID_ATTR: [
		'action',
		'formaction',
		'class',
		'srcset',
		'poster',
		'background',
	],
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
 * INVARIANT — only add a property here whose value grammar cannot reference a
 * `<url>` or an `<image>`. These four accept only `<color>` values and keywords,
 * which is what makes the value blocklist unnecessary. Adding `background`,
 * `background-image`, `list-style`, `content`, `border-image`, `cursor`, `mask`
 * or `filter` would turn this into a live external-fetch vector.
 *
 * @since x.x.x
 */
const ALLOWED_CSS_PROPERTIES = [
	'color',
	'background-color',
	'text-align',
	'direction',
];

/**
 * Keep only allowlisted CSS declarations on a node's `style` attribute.
 *
 * The attribute is rebuilt from the parsed CSSOM rather than filtered as a
 * string, so the browser's own CSS parser does the parsing. That removes three
 * classes of bug a hand-rolled `split( ';' )` filter has: declarations with no
 * colon are dropped instead of retained, quoted values and comments containing
 * `;` cannot truncate or smuggle, and CSS escapes are already resolved. Because
 * the attribute is always rewritten from what the parser understood, anything it
 * did not understand disappears.
 *
 * Runs as a DOMPurify hook so it operates on the live node during sanitization —
 * never by re-parsing the serialized output, which is the mistake that caused
 * CVE-2026-18406 in the first place.
 *
 * @param {Element} node Node being sanitized.
 * @return {void}
 */
const filterStyleAttribute = ( node ) => {
	if ( ! node?.style || ! node.hasAttribute?.( 'style' ) ) {
		return;
	}

	const declarations = Array.from( node.style )
		.filter( ( property ) => ALLOWED_CSS_PROPERTIES.includes( property ) )
		.map(
			( property ) =>
				`${ property }: ${ node.style.getPropertyValue( property ) }`
		);

	if ( declarations.length ) {
		node.setAttribute( 'style', declarations.join( '; ' ) );
	} else {
		node.removeAttribute( 'style' );
	}
};

/**
 * Private DOMPurify instance.
 *
 * `import domPurify from 'dompurify'` yields a module-scoped singleton, so
 * calling addHook() on it would silently apply this entry-specific CSS policy to
 * every other dompurify consumer sharing the instance. Bracketing with
 * removeHook() is not an alternative: it pops the LAST registered hook, so it
 * would clobber a hook registered after ours.
 */
const purifier = createDOMPurify( window );

purifier.addHook( 'afterSanitizeAttributes', filterStyleAttribute );

/**
 * The only block whose stored value can legitimately contain markup: the
 * textarea field, when its Rich Text editor is enabled.
 */
const RICH_TEXT_BLOCK = 'srfm-textarea';

/**
 * Should this field's value be rendered as HTML rather than as escaped text?
 *
 * The discriminator is the SERVER's `block_name` (set in `inc/rest-api.php`
 * from `Helper::get_block_name_from_field()`), not the shape of the value.
 * Content-sniffing with a loose `/<[^>]+>/` test on an already entity-decoded
 * value silently destroys plain-text answers: `<not a tag>` renders as an empty
 * cell and `if a<b then c>d` renders as `if a` + `d`, because DOMPurify quite
 * correctly removes what looks like an unknown element. Every non-textarea
 * field therefore stays on the escaping text path.
 *
 * The tag test is kept as a second condition — a plain-text answer typed into a
 * rich-text textarea should not take the HTML path either — but tightened to
 * require a real tag name, so `5 < 10 and 3 > 1` can never select it.
 *
 * @since x.x.x
 *
 * @param {Object} field Field object with `block_name` and `value`.
 * @return {boolean} True when the value must be sanitized and inserted as HTML.
 */
export const isRichTextField = ( field ) =>
	field?.block_name === RICH_TEXT_BLOCK &&
	typeof field.value === 'string' &&
	/<[a-z!/][^<>]*>/i.test( field.value );

/**
 * Sanitize entry content that may legitimately contain markup.
 *
 * CVE-2026-18406: the result MUST be inserted directly into the DOM (i.e. via
 * `dangerouslySetInnerHTML`). Never pass it to a second HTML parser such as
 * `html-react-parser` — the re-parse decodes entities DOMPurify deliberately
 * left inert and resurrects the payload. Sanitizer-straight-to-DOM is
 * DOMPurify's documented, mXSS-safe contract.
 *
 * @param {string} value Raw (entity-decoded) value.
 * @return {string} Sanitized HTML, safe for direct DOM insertion only.
 */
export const sanitizeEntryValue = ( value ) =>
	purifier.sanitize( value, RICH_TEXT_SANITIZE_CONFIG );
