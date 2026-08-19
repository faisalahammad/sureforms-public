import { __, _x } from '@wordpress/i18n';
import parse from 'html-react-parser';
import svgIcons from '@Svg/svgs.json';
import apiFetch from '@wordpress/api-fetch';
import { notify } from '@Utils/notify';

// Undo and redo functions for Custom Toolbar
function undoChange() {
	this.quill.history.undo();
}
function redoChange() {
	this.quill.history.redo();
}

// Custom image handler — uploads the file to the WordPress Media Library
// and inserts the returned URL. This avoids base64 data URLs which are
// stripped by wp_kses_post() on the PHP save path.
function imageHandler() {
	const quill = this.quill;
	const input = document.createElement( 'input' );
	input.setAttribute( 'type', 'file' );
	input.setAttribute( 'accept', 'image/*' );
	input.click();

	input.onchange = ( e ) => {
		const file = e.target.files?.[ 0 ];
		if ( ! file ) {
			return;
		}

		if ( ! /^image\//.test( file.type ) ) {
			notify.error( __( 'Please choose an image file.', 'sureforms' ) );
			return;
		}

		const formData = new window.FormData();
		formData.append( 'file', file );

		apiFetch( {
			path: '/wp/v2/media',
			method: 'POST',
			body: formData,
		} )
			.then( ( response ) => {
				const url =
					response?.source_url ||
					response?.media_details?.sizes?.full?.source_url;
				if ( ! url ) {
					notify.error(
						__(
							'The image uploaded but could not be inserted. Please try again.',
							'sureforms'
						)
					);
					return;
				}
				const range = quill.getSelection( true );
				quill.insertEmbed( range.index, 'image', {
					src: url,
					alt: response?.alt_text || '',
					'aria-hidden': 'true',
				} );
			} )
			.catch( ( err ) => {
				// Surface the failure — 413 (post_max_size), 403 (no upload_files)
				// and network errors all landed here silently, so picking an
				// oversized photo looked like nothing happened at all. WP sends a
				// human-readable `message` for REST-level errors; a request that
				// never reached the API (size cap, connection drop) has none, so
				// fall back to generic copy rather than printing an object.
				notify.error(
					err?.message ||
						__(
							'The image could not be uploaded. It may be too large, or you may not have permission to upload files.',
							'sureforms'
						)
				);
			} );
	};
}

// Custom matcher for the clipboard module for image tag.
function imageAttributeMatcher( node, delta ) {
	if ( node.tagName !== 'IMG' ) {
		return delta;
	}
	if ( delta.ops ) {
		// The delta object contains one image per delta object which is the first element of the ops array.
		const insert = delta.ops[ 0 ].insert;
		if ( typeof insert === 'object' && insert.image ) {
			insert.image = {
				src: node.getAttribute( 'src' ),
				alt: node.getAttribute( 'alt' ),
				'aria-hidden': node.getAttribute( 'aria-hidden' ),
			};
		}
	}
	return delta;
}

// Modules object for setting up the Quill editor
export const modules = {
	toolbar: {
		container: '#toolbar',
		handlers: {
			undo: undoChange,
			redo: redoChange,
			image: imageHandler,
		},
	},
	history: {
		delay: 500,
		maxStack: 100,
		userOnly: true,
	},
	clipboard: {
		matchVisual: false,
		matchers: [ [ 'img', imageAttributeMatcher ] ],
	},
};

// Formats objects for setting up the Quill editor
export const formats = [
	'header',
	'font',
	'size',
	'bold',
	'italic',
	'underline',
	'align',
	'strike',
	'script',
	'blockquote',
	'background',
	'list',
	'bullet',
	'indent',
	'link',
	'image',
	'color',
	'code-block',
];

// Quill Toolbar component
export const QuillToolbar = () => (
	<div
		id="toolbar"
		className="border-x border-t border-b-0 border-field-border border-solid rounded-t-lg"
	>
		<span className="ql-formats">
			<select className="ql-header" defaultValue="false">
				<option value="1">{ __( 'Heading 1', 'sureforms' ) }</option>
				<option value="2">{ __( 'Heading 2', 'sureforms' ) }</option>
				<option value="3">{ __( 'Heading 3', 'sureforms' ) }</option>
				<option value="4">{ __( 'Heading 4', 'sureforms' ) }</option>
				<option value="5">{ __( 'Heading 5', 'sureforms' ) }</option>
				<option value="6">{ __( 'Heading 6', 'sureforms' ) }</option>
				<option value="false">{ _x( 'Normal', 'Quill heading picker: default paragraph style', 'sureforms' ) }</option>
			</select>
			<button className="ql-bold" />
			<button className="ql-italic" />
			<button className="ql-underline" />
			<button className="ql-strike" />
			<button className="ql-list" value="ordered" />
			<button className="ql-list" value="bullet" />
			<button className="ql-blockquote" />
			<select className="ql-align" />
			<select className="ql-color" />
			<select className="ql-background" />
			<button className="ql-link" />
			<button className="ql-clean" />
			<button className="ql-image" />
			<button className="ql-undo">{ parse( svgIcons.undo ) }</button>
			<button className="ql-redo">{ parse( svgIcons.redo ) }</button>
		</span>
	</div>
);

export default QuillToolbar;
