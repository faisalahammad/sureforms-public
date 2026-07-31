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
 * (verified against 3.3.3) still permit `<style>`, `<form action>`, `style=`,
 * the media elements and the SVG/MathML namespaces the exploit relied on.
 */

import domPurify from 'dompurify';
import {
	isRichTextField,
	sanitizeEntryValue,
	sanitizeFieldValue,
	sanitizeLogMessage,
} from '../sanitizeEntryValue';
import { decodeHTMLEntities } from '../entryHelpers';

const sanitize = sanitizeEntryValue;

// A pristine instance, so "this is what the DEFAULT config does" assertions are
// genuinely about defaults and cannot be perturbed by a hook. The default export
// doubles as the factory, so calling it yields a fresh instance.
const pristine = domPurify( window );

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

		expect( pristine.sanitize( payload ) ).toMatch( /<svg/i );
		expect( sanitize( payload ) ).toBe( '' );
	} );

	it( 'neutralizes the payload in its STORED double-encoded form', () => {
		// The real sink is the composition, not the sanitizer alone: values are
		// stored double-encoded (sanitize_text_field, then a second
		// htmlspecialchars at inc/form-submit.php), and the view decodes before
		// sanitizing. Feed the actual stored bytes through the actual pipeline.
		const stored =
			'&amp;lt;svg&amp;gt;&amp;lt;style&amp;gt;&amp;amp;lt;foreignObject&amp;amp;gt;' +
			'&amp;amp;lt;img src=x onerror=alert(1)&amp;amp;gt;';

		const out = sanitize( decodeHTMLEntities( stored ) );

		expect( out ).not.toMatch( /onerror/i );
		expect( out ).not.toMatch( /<img/i );
		expect( out ).not.toMatch( /<svg/i );
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

	it( 'strips media elements that would fire an unauthenticated outbound GET', () => {
		// The Quill toolbar has no image/video/audio button, so no legitimate
		// stored rich text contains these. Allowing them lets an unauthenticated
		// submitter make the reviewing admin's browser hit an attacker host on
		// entry view (IP/UA leak, read receipt, same-origin GET CSRF).
		for ( const payload of [
			'<img src="https://evil.example/x.png">',
			'<img src=x srcset="https://evil.example/y 1x">',
			'<picture><source srcset="https://evil.example/z"></picture>',
			'<video autoplay src="https://evil.example/v.mp4"></video>',
			'<audio autoplay src="https://evil.example/a.mp3"></audio>',
			'<object data="https://evil.example/o"></object>',
			'<embed src="https://evil.example/e">',
		] ) {
			expect( sanitize( payload ) ).not.toMatch(
				/evil\.example|<img|<video|<audio|<source|<object|<embed/i
			);
		}

		// Defaults do allow them — this is policy, not DOMPurify behaviour.
		expect(
			pristine.sanitize( '<img src="https://evil.example/x.png">' )
		).toMatch( /<img/i );
	} );

	it( 'strips overlay CSS but keeps the formatting the editor emits', () => {
		// `style` cannot simply be forbidden: the rich-text editor registers
		// Quill's STYLE attributors for alignment/direction and its colour formats
		// are style-based, and Helper::sanitize_textarea() preserves them —
		// disable_style_attr_parsing() returns an empty allowlist, which makes
		// WordPress's safecss_filter_attr() skip filtering (kses.php: `if ( empty(
		// $allowed_attr ) ) { return $css; }`). Forbidding it outright would
		// de-colour and un-align every rich-text entry already stored.
		expect(
			sanitize( '<span style="color: rgb(230, 0, 0);">r</span>' )
		).toMatch( /color:\s*rgb\(230, 0, 0\)/ );
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
		const mixed = sanitize(
			'<p style="color:red;position:fixed;top:0">x</p>'
		);
		expect( mixed ).toMatch( /color:\s*red/ );
		expect( mixed ).not.toMatch( /position/i );
	} );

	it( 'rebuilds the style attribute from the CSSOM rather than parsing it by hand', () => {
		// A string filter that splits on ';' and slices at ':' gets all three of
		// these wrong: it retains `colorX` (indexOf returns -1, so slice(0,-1)
		// yields the property `color`), it truncates at the ';' inside a quoted
		// value, and it has to blocklist every URL-bearing CSS function by hand.
		expect( sanitize( '<p style="colorX">x</p>' ) ).not.toMatch( /style=/i );
		expect(
			sanitize( '<p style="background-color: \'a;position:fixed\'">x</p>' )
		).not.toMatch( /position/i );
		expect(
			sanitize( '<p style="color:red/*;position:fixed*/">x</p>' )
		).not.toMatch( /position/i );

		// Anything able to fetch a resource is gone because the PROPERTY is not
		// allowlisted — no value blocklist required.
		for ( const payload of [
			'<p style="background-color:url(https://evil.example/)">x</p>',
			'<p style="background-image:image-set(url(https://evil.example/a))">x</p>',
			'<p style="background:-moz-image-set(url(https://evil.example/a))">x</p>',
			'<p style="background-image:cross-fade(url(https://evil.example/a),url(b))">x</p>',
			'<p style="background-image:element(#a)">x</p>',
			'<p style="content:attr(href)">x</p>',
		] ) {
			expect( sanitize( payload ) ).not.toMatch(
				/url\(|image-set|cross-fade|element\(|attr\(/i
			);
		}
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

	it( 'strips javascript: and data: URLs but keeps ordinary links', () => {
		expect( sanitize( '<a href="javascript:alert(1)">c</a>' ) ).not.toMatch(
			/javascript:/i
		);
		expect(
			sanitize( '<a href="data:text/html,<script>alert(1)</script>">c</a>' )
		).not.toMatch( /data:/i );

		// The toolbar has a link button, so real links must survive.
		expect( sanitize( '<a href="https://example.com">l</a>' ) ).toMatch(
			/href="https:\/\/example\.com"/
		);
	} );

	it( 'preserves legitimate rich-text formatting', () => {
		// The whole reason the fix is not "escape everything": rich-text textarea
		// fields legitimately store HTML and must still render formatted.
		// Asserted per-construct rather than with an exact-string compare, which
		// would break on DOMPurify serialization changes.
		const out = sanitize(
			'<p><strong>Bold</strong> <em>italic</em> <a href="https://example.com">link</a></p>' +
				'<ul><li>one</li></ul><h2>Heading</h2><blockquote>quote</blockquote>' +
				'<pre><code>code</code></pre>'
		);

		for ( const tag of [
			'strong',
			'em',
			'a',
			'ul',
			'li',
			'h2',
			'blockquote',
			'pre',
			'code',
		] ) {
			expect( out ).toMatch( new RegExp( `<${ tag }[\\s>]`, 'i' ) );
		}
		expect( out ).toMatch( /Bold/ );
		expect( out ).toMatch( /Heading/ );
	} );

	it( 'preserves the change-log markup the Pro entry editor writes', () => {
		// sureforms-pro inc/extensions/hooks.php emits <strong>/<del> change
		// markup into log messages; rendering those as text would show raw tags.
		const out = sanitize(
			'<strong>Email: </strong> <del>old@example.com</del> → new@example.com'
		);

		expect( out ).toMatch( /<strong>/i );
		expect( out ).toMatch( /<del>/i );
		expect( out ).toMatch( /new@example\.com/ );
	} );
} );

describe( 'sanitizer instance isolation', () => {
	it( 'does not install its CSS hook on the shared DOMPurify singleton', () => {
		// This MUST assert on the default export, not on another
		// createDOMPurify() instance: the factory returns a fresh object every
		// call, so a `pristine` instance is unaffected by addHook() no matter
		// where it was called, and the test could never fail. sureforms-pro's
		// EntryEditModal imports this same singleton to seed the Quill editor,
		// so a leaked hook would strip style attributes during editing.
		expect(
			domPurify.sanitize( '<p style="position:fixed;top:0">x</p>' )
		).toMatch( /position:\s*fixed/ );
	} );
} );

describe( 'sanitizeLogMessage', () => {
	// Renders the sanitized string the way the DOM will, so assertions are about
	// what the user actually sees rather than about entity spelling.
	const rendered = ( message ) => {
		const host = document.createElement( 'div' );
		host.innerHTML = sanitizeLogMessage( message );
		return host.textContent;
	};

	it( 'keeps the change-log markup Pro writes', () => {
		const out = sanitizeLogMessage(
			'<strong>Email: </strong> <del>old@a.test</del> &#8594; new@a.test'
		);

		expect( out ).toMatch( /<strong>/i );
		expect( out ).toMatch( /<del>/i );
	} );

	it( 'displays escaped entities correctly without pre-decoding', () => {
		// The old implementation ran decodeHTMLEntities() first to fix this. It is
		// unnecessary: innerHTML performs the decode on insertion.
		expect(
			rendered( '<strong>Name: </strong> <del>O&#039;Brien</del>' )
		).toContain( "O'Brien" );
		expect( rendered( 'a &#8594; b' ) ).toContain( '→' );
	} );

	it( 'does not blank an escaped old value in the audit trail', () => {
		// Regression guard: pre-decoding turned `&lt;img src=x&gt;` back into a
		// live <img>, which the sanitizer then removed, leaving `<del></del>` and
		// misreporting what changed.
		const out = sanitizeLogMessage(
			'<strong>Message: </strong> <del>&lt;img src=x&gt;</del> &#8594; hello'
		);

		expect( out ).toMatch( /&lt;img src=x&gt;/ );
		expect( rendered( out ) ).toContain( '<img src=x>' );
	} );

	it( 'still strips live markup in a log message', () => {
		expect(
			sanitizeLogMessage( '<img src=x onerror=alert(1)>' )
		).not.toMatch( /onerror|<img/i );
	} );

	it( 'renders nothing for non-string messages', () => {
		// Previously `null` rendered the literal word "null" and an object
		// rendered JSON whose `<` characters were parsed as markup.
		expect( sanitizeLogMessage( null ) ).toBe( '' );
		expect( sanitizeLogMessage( undefined ) ).toBe( '' );
		expect( sanitizeLogMessage( { a: '<b>' } ) ).toBe( '' );
		expect( sanitizeLogMessage( 42 ) ).toBe( '' );
	} );
} );

describe( 'server-rendered markup (srfm-payment)', () => {
	// inc/payments/stripe/payments-settings.php hooks `srfm_entry_value` and
	// replaces the stored numeric payment ID with this anchor. It is
	// plugin-authored, not submitter input, so it keeps `class` and `target`.
	const anchor =
		'<a type="button" href="http://example.test/wp-admin/admin.php?page=sureforms_payments#/payment/323"' +
		' class="text-link-primary no-underline hover:underline" target="_blank">View Payment</a>';

	it( 'renders the payment link as a working anchor', () => {
		const out = sanitizeFieldValue( {
			block_name: 'srfm-payment',
			value: anchor,
		} );

		expect( out ).toMatch( /<a\s/i );
		expect( out ).toMatch( /page=sureforms_payments/ );
		expect( out ).toMatch( /View Payment/ );
	} );

	it( 'applies the SAME strict policy to payment as to submitter content', () => {
		// `block_name` comes from the submitted POST key and is not a trust
		// boundary: process_form_fields() accepts any key containing `-lbl-`, and
		// the payment filter leaves non-numeric values alone, so a submitter can
		// get their own payload labelled `srfm-payment`. A relaxed policy for this
		// label would hand them `class` — the Tailwind overlay vector.
		const out = sanitizeFieldValue( {
			block_name: 'srfm-payment',
			value: '<div class="fixed inset-0 z-[99999999]">gotcha</div>',
		} );

		expect( out ).not.toMatch( /class=/i );
	} );

	it( 'takes the markup branch so it is never shown as literal tags', () => {
		expect(
			isRichTextField( { block_name: 'srfm-payment', value: anchor } )
		).toBe( true );
	} );

	it( 'still refuses class on submitter-authored rich text', () => {
		// The relaxed policy must not leak to the textarea path — `class` is the
		// Tailwind overlay vector.
		expect(
			sanitizeFieldValue( {
				block_name: 'srfm-textarea',
				value: '<div class="fixed inset-0 z-[99999999]">x</div>',
			} )
		).not.toMatch( /class=/i );
	} );

	it( 'still strips scripts and media from the trusted policy', () => {
		const out = sanitizeFieldValue( {
			block_name: 'srfm-payment',
			value: '<a href="javascript:alert(1)" onclick="alert(1)">x</a><img src=y>',
		} );

		expect( out ).not.toMatch( /javascript:|onclick|<img/i );
	} );
} );
