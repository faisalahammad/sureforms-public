/* eslint-env jest */
/**
 * Regression tests for CVE-2026-18406 (stored XSS in the entries admin view).
 *
 * The original bug was NOT a DOMPurify failure — it was feeding DOMPurify's
 * serialized output to a SECOND HTML parser (html-react-parser). The re-parse
 * decoded entities the sanitizer had deliberately left inert, resurrecting an
 * executable payload. The fix inserts sanitizer output straight into the DOM.
 *
 * These tests pin the part that is testable in isolation: the sanitizer policy.
 * They fail if someone widens the config back to DOMPurify's defaults, which
 * (verified against 3.3.3) still permit `<style>`, `<form action>`, `style=` and
 * the SVG/MathML namespaces the exploit relied on.
 */

import domPurify from 'dompurify';
import { sanitizeEntryValue } from '../sanitizeEntryValue';

const sanitize = sanitizeEntryValue;

describe( 'entry value sanitization (CVE-2026-18406)', () => {
	it( 'strips the foreignObject payload from the published exploit', () => {
		const out = sanitize(
			'<svg><foreignObject><img src=x onerror=alert(1)></foreignObject></svg>'
		);

		expect( out ).not.toMatch( /onerror/i );
		expect( out ).not.toMatch( /foreignobject/i );
		expect( out ).not.toMatch( /<svg/i );
	} );

	it( 'strips the <svg><style> shape that survives DOMPurify defaults', () => {
		// This one matters: with the DEFAULT config this input round-trips with a
		// literal `<img ... onerror>` still inside an SVG-namespaced <style>,
		// which is the precondition for a re-parse mXSS. The policy must remove
		// the namespace entirely so the question cannot arise.
		const payload =
			'<svg><style><a>x</a>&lt;img src=x onerror=alert(1)&gt;</style></svg>';

		expect( domPurify.sanitize( payload ) ).toMatch( /<svg/i );
		expect( sanitize( payload ) ).toBe( '' );
	} );

	it( 'strips document-wide CSS injection', () => {
		expect( sanitize( '<style>body{display:none}</style>' ) ).not.toMatch(
			/<style/i
		);
	} );

	it( 'strips cross-origin forms and their controls', () => {
		const out = sanitize(
			'<form action="https://evil.example/"><input name="a" value="b"><button>go</button></form>'
		);

		expect( out ).not.toMatch( /<form/i );
		expect( out ).not.toMatch( /action=/i );
		expect( out ).not.toMatch( /<input/i );
	} );

	it( 'strips overlay CSS but keeps the formatting the editor emits', () => {
		// `style` cannot simply be forbidden: the rich-text editor registers
		// Quill's STYLE attributors for alignment/direction and its colour formats
		// are style-based, and Helper::sanitize_textarea() preserves them —
		// disable_style_attr_parsing() returns an empty allowlist, which makes
		// WordPress's safecss_filter_attr() skip filtering (kses.php: `if ( empty(
		// $allowed_attr ) ) { return $css; }`). Forbidding it outright would
		// de-colour and un-align every rich-text entry already stored.
		expect( sanitize( '<span style="color: rgb(230, 0, 0);">r</span>' ) ).toMatch(
			/color:\s*rgb\(230, 0, 0\)/
		);
		expect( sanitize( '<p style="text-align: center;">m</p>' ) ).toMatch(
			/text-align:\s*center/
		);
		expect( sanitize( '<p style="direction: rtl;">r</p>' ) ).toMatch(
			/direction:\s*rtl/
		);

		// ...but the properties needed for a full-viewport overlay are dropped.
		expect(
			sanitize( '<p style="position:fixed;inset:0;z-index:9999">x</p>' )
		).not.toMatch( /style=/i );

		// ...including when mixed in with a legitimate declaration.
		const mixed = sanitize( '<p style="color:red;position:fixed;top:0">x</p>' );
		expect( mixed ).toMatch( /color:\s*red/ );
		expect( mixed ).not.toMatch( /position/i );

		// ...and a URL-fetching value on an allowed property is refused outright.
		expect(
			sanitize( '<p style="background-color:url(https://evil.example/)">x</p>' )
		).not.toMatch( /url\(/i );
	} );

	it( 'strips class and data attributes used to smuggle in bundled utilities', () => {
		// This is a Tailwind admin app whose content glob covers all of src/, so
		// every utility used anywhere is compiled into this bundle. Allowing
		// `class` would reproduce the exact overlay the CSS filtering prevents.
		expect(
			sanitize( '<div class="fixed inset-0 z-[99999999]">x</div>' )
		).not.toMatch( /class=/i );
		expect( sanitize( '<p data-x="y">x</p>' ) ).not.toMatch( /data-x/i );
	} );

	it( 'strips javascript: URLs', () => {
		expect( sanitize( '<a href="javascript:alert(1)">c</a>' ) ).not.toMatch(
			/javascript:/i
		);
	} );

	it( 'preserves legitimate rich-text formatting', () => {
		// The whole reason the fix is not "escape everything": rich-text textarea
		// fields legitimately store HTML and must still render formatted.
		const richText =
			'<p><strong>Bold</strong> <em>italic</em> <a href="https://example.com">link</a></p>' +
			'<ul><li>one</li></ul><h2>Heading</h2><blockquote>quote</blockquote>' +
			'<pre><code>code</code></pre>';

		expect( sanitize( richText ) ).toBe( richText );
	} );
} );
