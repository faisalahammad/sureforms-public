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

	it( 'returns undefined rather than an empty string, so the caller drops the anchor', () => {
		// An anchor with href="" is not keyboard focusable and resolves to the
		// current document, so the guards have to see a falsy value they can act
		// on rather than a rendered dead control.
		expect( safeUrl( 'javascript:void 0' ) ).toBeUndefined();
	} );
} );
