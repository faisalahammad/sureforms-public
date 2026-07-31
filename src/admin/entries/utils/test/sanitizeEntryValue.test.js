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

	it( 'strips style attributes used for overlay/UI-redress', () => {
		expect(
			sanitize( '<p style="position:fixed;inset:0;z-index:9999">x</p>' )
		).not.toMatch( /style=/i );
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
