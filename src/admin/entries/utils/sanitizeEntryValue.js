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
 *     wp-admin (UI redressing, defacement, click-jacked POSTs). Every
 *     server-side filter is bypassed on this path: values are stored
 *     entity-encoded, so PHP only ever sees inert text, and decodeHTMLEntities()
 *     turns them back into live markup before the sanitizer runs. DOMPurify is
 *     the ONLY guard standing at this sink.
 *  2. Dropping the SVG/MathML namespaces removes `<foreignObject>` — the exact
 *     namespace CVE-2026-18406 used — and with it the `<svg><style>` shape,
 *     which is the one construct able to slip past DOMPurify's own anti-mXSS
 *     guard (that guard only fires on elements with no element children).
 *
 * Nothing legitimate is lost: the rich-text editor emits plain HTML formatting
 * only (see the Quill `formats` list in blocks/textarea/components/utils.js),
 * and Helper::sanitize_textarea() already strips style attributes server-side.
 *
 * @since 2.12.3
 */
export const RICH_TEXT_SANITIZE_CONFIG = {
	USE_PROFILES: { html: true },
	FORBID_TAGS: [ 'style', 'form', 'input', 'button', 'select', 'textarea' ],
	FORBID_ATTR: [ 'style', 'action', 'formaction' ],
};

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
