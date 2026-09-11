/* eslint-env jest */
/**
 * safeUrl() is the sink-side scheme guard for Form Checks item URLs.
 *
 * get_action_items() already runs every URL through esc_url_raw() with a scheme
 * allowlist -- but srfm_admin_filter runs afterwards, on the already-built
 * localisation payload, and can rewrite these values. React assigns href as a
 * property and react-dom 18 leaves a javascript: URL intact, with its warning
 * compiled out of production, so the server pass alone is not the last word
 * before the value reaches the DOM.
 *
 * Pinned because the two focus fixes alongside it were both code that read
 * correctly and never ran.
 */
import safeUrl from '../utils/safeUrl';

describe( 'safeUrl', () => {
	it.each( [
		[ 'https://sureforms.com/form/troubleshooting-form/' ],
		[ 'http://example.test/page' ],
		[ 'HTTPS://EXAMPLE.TEST/page' ],
		[ 'mailto:support@sureforms.com?subject=x' ],
		[ 'MAILTO:support@sureforms.com' ],
	] )( 'allows %s', ( url ) => {
		expect( safeUrl( url ) ).toBe( url );
	} );

	it.each( [
		[ 'relative/path' ],
		[ '/wp-admin/admin.php?page=sureforms_menu' ],
		[ '#anchor' ],
		[ '?query=only' ],
	] )( 'allows the schemeless %s', ( url ) => {
		expect( safeUrl( url ) ).toBe( url );
	} );

	it.each( [
		[ 'javascript:alert(1)' ],
		[ 'JavaScript:alert(1)' ],
		[ 'data:text/html;base64,PHNjcmlwdD4=' ],
		[ 'vbscript:msgbox(1)' ],
		[ 'file:///etc/passwd' ],
		[ 'ftp://example.test/x' ],
	] )( 'rejects %s', ( url ) => {
		expect( safeUrl( url ) ).toBeUndefined();
	} );

	it.each( [ [ '' ], [ null ], [ undefined ], [ 0 ], [ {} ], [ [] ] ] )(
		'rejects the non-string %p',
		( url ) => {
			expect( safeUrl( url ) ).toBeUndefined();
		}
	);

	// A browser trims C0 controls and spaces off both ends and strips tab, LF and
	// CR from anywhere in the string before it parses the scheme. A check anchored
	// on the raw string therefore reads a spelling the DOM never sees: every one
	// of these was returned untouched and ran on click.
	it.each( [
		[ 'leading space', ' javascript:alert(1)' ],
		[ 'leading newline', '\njavascript:alert(1)' ],
		[ 'leading NUL', '\u0000javascript:alert(1)' ],
		[ 'tab inside the scheme', 'java\tscript:alert(1)' ],
		[ 'newline inside the scheme', 'java\nscript:alert(1)' ],
		[ 'CR inside the scheme', 'java\rscript:alert(1)' ],
		[ 'both at once', ' java\tscript:alert(1)' ],
	] )( 'rejects javascript: hidden by a %s', ( _label, url ) => {
		expect( safeUrl( url ) ).toBeUndefined();
	} );

	// No scheme to check and a different origin on the other end, so the
	// schemeless-is-relative branch above must not claim these.
	it.each( [
		[ '//evil.test/x' ],
		[ '\\\\evil.test/x' ],
		[ ' //evil.test/x' ],
		[ '/\\evil.test/x' ],
	] )( 'rejects the protocol-relative %s', ( url ) => {
		expect( safeUrl( url ) ).toBeUndefined();
	} );

	it( 'hands back what it checked, not what it was given', () => {
		// Validating one spelling and returning another is how a scheme check gets
		// walked past: the caller would bind the untrimmed string.
		expect( safeUrl( '  https://example.test/x  ' ) ).toBe(
			'https://example.test/x'
		);
	} );

	it( 'still allows a single leading slash', () => {
		// One slash is a same-origin path. Only two leave the site.
		expect( safeUrl( '/wp-admin/admin.php?page=sureforms_menu' ) ).toBe(
			'/wp-admin/admin.php?page=sureforms_menu'
		);
	} );

	it( 'returns undefined rather than an empty string, so the caller drops the anchor', () => {
		// An anchor with href="" is not keyboard focusable and resolves to the
		// current document, so the guards have to see a falsy value they can act
		// on rather than a rendered dead control.
		expect( safeUrl( 'javascript:void 0' ) ).toBeUndefined();
	} );
} );
