/* eslint-env jest */
/**
 * Which entry values are rendered as HTML, and which are escaped as text.
 *
 * This is a data-integrity test as much as a security one. The previous
 * discriminator content-sniffed the value with `/<[^>]+>/g`, so any plain-text
 * answer containing `<` followed by a name character and a later `>` was routed
 * into the HTML branch — where DOMPurify quite correctly removed it, silently
 * blanking real submitted data in the screen site owners use to read leads.
 */

import { isRichTextField } from '../sanitizeEntryValue';

const richText = ( value ) => ( { block_name: 'srfm-textarea', value } );

describe( 'isRichTextField', () => {
	it( 'selects the HTML branch only for the rich-text textarea block', () => {
		expect( isRichTextField( richText( '<p>hi</p>' ) ) ).toBe( true );
		expect(
			isRichTextField( {
				block_name: 'srfm-input',
				value: '<p>hi</p>',
			} )
		).toBe( false );
	} );

	it( 'keeps plain-text answers that merely look like markup on the text path', () => {
		// Each of these was destroyed by the old content-sniffing regex:
		// `<not a tag>` rendered as an empty cell, `if a<b then c>d` as `if a`+`d`.
		for ( const value of [
			'<not a tag>',
			'if a<b then c>d',
			'x<y>z',
			'5 < 10 and 3 > 1',
			'I paid <$50 for it>',
		] ) {
			expect(
				isRichTextField( { block_name: 'srfm-input', value } )
			).toBe( false );
		}
	} );

	it( 'does not treat a comparison typed into a textarea as markup', () => {
		// Even on the one block allowed to hold markup, a bare comparison must
		// not select the HTML branch.
		expect( isRichTextField( richText( '5 < 10 and 3 > 1' ) ) ).toBe(
			false
		);
		expect( isRichTextField( richText( 'plain answer' ) ) ).toBe( false );
	} );

	it( 'recognises the markup the rich-text editor actually emits', () => {
		for ( const value of [
			'<p>para</p>',
			'<strong>bold</strong>',
			'<ul><li>item</li></ul>',
			'<span style="color: rgb(230, 0, 0);">red</span>',
			'<br>',
			'</p>',
		] ) {
			expect( isRichTextField( richText( value ) ) ).toBe( true );
		}
	} );

	it( 'is safe for non-string and missing values', () => {
		expect( isRichTextField( richText( null ) ) ).toBe( false );
		expect( isRichTextField( richText( 42 ) ) ).toBe( false );
		expect( isRichTextField( richText( [ 'a' ] ) ) ).toBe( false );
		expect( isRichTextField( undefined ) ).toBe( false );
		expect( isRichTextField( {} ) ).toBe( false );
	} );

	it( 'requires block_name, which Pro must not drop', () => {
		// sureforms-pro's render-pro-fields formatter returns a bare
		// `{ label, value }`; formatField() grafts block_name back on. Without
		// that graft this returns false and Pro sites show raw tags.
		expect( isRichTextField( { value: '<p>hi</p>' } ) ).toBe( false );
	} );
} );
