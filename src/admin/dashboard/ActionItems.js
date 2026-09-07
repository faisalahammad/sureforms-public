import { __ } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import { Button, Label } from '@bsf/force-ui';
import {
	CircleCheck,
	TriangleAlert,
	ChevronUp,
	ChevronDown,
	Check,
	Copy,
	X,
} from 'lucide-react';

/**
 * Dashboard panel listing what SureForms has checked on this site.
 *
 * Follows SureRank's Page Checks: one row per check, warnings first with a fix
 * link and an Ignore control, passing checks below as a green tick. Row markup
 * mirrors SureRank's CheckCard so the two plugins look like siblings.
 *
 * Rows are supplied fully formed by the server (see Admin::get_action_items()),
 * so adding a check needs no change here.
 */
const ICONS = {
	// Red for a fault that is losing entries, amber for advice the site owner can
	// act on at their leisure. Both are triangles: the shape says "check", the
	// colour says how urgent.
	//
	// amber-500 rather than force-ui's badge-color-yellow (#A16207): at 16px, next
	// to the red, that token reads brown rather than as a warning. The row's text
	// carries the same meaning, so the icon is not the only thing conveying it.
	error: <TriangleAlert className="size-4 text-badge-color-red shrink-0" />,
	warning: <TriangleAlert className="size-4 text-amber-500 shrink-0" />,
	success: (
		<CircleCheck className="size-4 text-badge-color-green shrink-0" />
	),
};

export default () => {
	const [ dismissed, setDismissed ] = useState( [] );
	const [ open, setOpen ] = useState( true );
	// The item whose details are being read, or null. Holds the item rather than a
	// boolean so the dialog keeps rendering the right one while it closes.
	const [ details, setDetails ] = useState( null );
	const [ copied, setCopied ] = useState( false );

	const items = ( srfm_admin?.action_items || [] ).filter(
		( item ) => ! dismissed.includes( item.id )
	);

	if ( ! items.length ) {
		return null;
	}

	// Fire and forget: neither tracking nor dismissal may block the UI.
	const post = ( action, nonce, extra = {} ) => {
		const ajaxUrl = srfm_admin?.ajax_url;

		if ( ! ajaxUrl || ! nonce ) {
			return;
		}

		const body = new FormData();
		body.append( 'action', action );
		body.append( 'nonce', nonce );
		Object.entries( extra ).forEach( ( [ key, value ] ) =>
			body.append( key, value )
		);

		fetch( ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body,
		} ).catch( () => {} );
	};

	const handleIgnore = ( item ) => () => {
		post( 'srfm_dismiss_action_item', srfm_admin?.dismiss_action_item_nonce, {
			item_id: item.id,
		} );

		setDismissed( ( prev ) => [ ...prev, item.id ] );
	};

	// Self-serve first. Someone who can fix it themselves should see that before
	// they are pointed at a support queue, and the emphasis follows the order
	// rather than the identity -- whichever action leads reads as the primary one,
	// so an item with no guide still has Contact Support in front.
	const actionsFor = ( item ) => [
		...( item.guide_label && item.guide_url
			? [
				{
					name: item.guide_action,
					label: item.guide_label,
					url: item.guide_url,
					external: true,
				},
			  ]
			: [] ),
		...( item.cta_label
			? [
				{
					name: item.cta_action,
					label: item.cta_label,
					url: item.cta_url,
					// An item carrying details opens them here rather than
					// navigating: the point is to read the diagnostics before
					// sending them anywhere. cta_url stays as the fallback the
					// classic wp-admin notice uses, since it cannot open a dialog.
					dialog: !! item.details,
					// A mailto must reach the mail client, not a browser tab.
					// srfm_action_items is public, so one can still arrive that way.
					external: ! item.cta_url?.startsWith( 'mailto:' ),
				},
			  ]
			: [] ),
	];

	const handleCopy = ( item ) => async () => {
		const text = item.details || '';

		try {
			// Both flavours. A rich-text composer -- Gmail's, for one -- drops the
			// newlines out of plain text, which is what turned the diagnostics into
			// one paragraph; pasting HTML keeps every break, and <pre> keeps the
			// log's columns lined up. A plain-text field still gets the text.
			//
			// Escaped here because the source is a log holding whatever a server put
			// in an error message. It is never trusted as markup.
			const html = `<pre style="font-family:monospace;white-space:pre-wrap;word-break:break-word;margin:0">${ text
				.replace( /&/g, '&amp;' )
				.replace( /</g, '&lt;' )
				.replace( />/g, '&gt;' ) }</pre>`;

			if ( window.ClipboardItem && navigator.clipboard?.write ) {
				await navigator.clipboard.write( [
					new window.ClipboardItem( {
						'text/plain': new Blob( [ text ], {
							type: 'text/plain',
						} ),
						'text/html': new Blob( [ html ], {
							type: 'text/html',
						} ),
					} ),
				] );
			} else {
				// No ClipboardItem: the formatting is lost, which still beats
				// copying nothing.
				await navigator.clipboard.writeText( text );
			}

			setCopied( true );
			// Reverts on its own: a button stuck on "Copied" says nothing about the
			// next click.
			setTimeout( () => setCopied( false ), 2000 );
		} catch ( e ) {
			// Clipboard access can be refused outright (an insecure origin, a
			// permission policy). The text is on screen and selectable, so there is
			// nothing to recover -- just do not claim it was copied.
			setCopied( false );
		}

		post( 'srfm_notice_response', srfm_admin?.notice_response_nonce, {
			notice_id: item.id,
			button: 'copy_details',
		} );
	};

	// The support link carries the log in its body, so the click just opens a
	// composed message -- no download to trigger, nothing to intercept.
	const handleFix = ( item, action ) => () =>
		post( 'srfm_notice_response', srfm_admin?.notice_response_nonce, {
			notice_id: item.id,
			// Named by the server, so the button key is not duplicated here and in
			// the allowlist that has to accept it.
			button: action,
		} );

	return (
		<div className="w-full bg-background-primary border-0.5 border-solid rounded-xl border-border-subtle p-3 gap-2 shadow-sm-blur-1">
			<div className="flex items-center justify-between p-1">
				<Label size="sm" className="font-semibold">
					{ __( 'Form Checks', 'sureforms' ) }
				</Label>
				<button
					type="button"
					onClick={ () => setOpen( ( prev ) => ! prev ) }
					aria-expanded={ open }
					aria-label={
						open
							? __( 'Collapse form checks', 'sureforms' )
							: __( 'Expand form checks', 'sureforms' )
					}
					className="flex items-center bg-transparent border-0 p-0.5 cursor-pointer text-icon-secondary hover:text-icon-primary"
				>
					{ open ? (
						<ChevronUp className="size-4" />
					) : (
						<ChevronDown className="size-4" />
					) }
				</button>
			</div>
			{ /* Same nesting as Quick Access below it: a grey well inside the white
			     card, holding white rows. The two sit in one column and were
			     reading as different components. */ }
			{ open && (
				<div className="flex flex-col bg-background-secondary gap-1 p-1 rounded-lg">
					{ items.map( ( item ) => (
						<div
							key={ item.id }
							className="relative flex flex-col gap-1 p-3 rounded-md bg-background-primary shadow-sm-blur-1"
						>
							<div className="w-full flex items-start gap-2">
								{ ICONS[ item.status ] ?? ICONS.warning }
								<div className="flex-1">
									<Label
										size="sm"
										className="font-medium block"
									>
										{ item.title }
									</Label>
									{ !! item.message && (
										<Label
											size="xs"
											variant="help"
											className="font-normal block pt-0.5"
										>
											{ item.message }
										</Label>
									) }
								</div>
								{ !! item.dismissible && (
									<Button
										variant="link"
										size="xs"
										onClick={ handleIgnore( item ) }
										className="font-medium no-underline hover:underline focus:outline-none focus:[box-shadow:none] [&>span]:px-0 text-text-secondary shrink-0"
									>
										{ __( 'Ignore', 'sureforms' ) }
									</Button>
								) }
							</div>
							{ !! actionsFor( item ).length && (
								<div className="pl-6 flex items-center gap-4">
									{ actionsFor( item ).map(
										( action, index ) => (
											<Button
												key={ action.name }
												variant="link"
												size="xs"
												{ ...( action.dialog
													? {
														onClick: ( e ) => {
															e.preventDefault();
															setCopied(
																false
															);
															setDetails(
																item
															);
															handleFix(
																item,
																action.name
															)();
														},
													  }
													: {
														tag: 'a',
														href: action.url,
														onClick: handleFix(
															item,
															action.name
														),
														...( action.external && {
															target: '_blank',
															rel: 'noopener noreferrer',
														} ),
													  } ) }
												// Underlined on hover only, matching
												// Quick Access below it. Three
												// underlined links stacked in a narrow
												// column read as a block of noise
												// rather than as actions.
												className={ `font-medium no-underline hover:underline focus:outline-none focus:[box-shadow:none] [&>span]:px-0${
													index > 0
														? ' text-text-secondary'
														: ''
												}` }
											>
												{ action.label }
											</Button>
										)
									) }
								</div>
							) }
						</div>
					) ) }
				</div>
			) }
			{ /* Read before send: the same text a support request needs, so it can
			     be pasted rather than described.

			     A plain overlay rather than force-ui's Dialog because the identical
			     modal has to exist for the classic wp-admin notices, which have no
			     React. One implementation, one behaviour, nothing to keep in step. */ }
			{ !! details && (
				<div
					className="fixed inset-0 z-[100000] flex items-center justify-center bg-black/50 p-4"
					role="presentation"
					onClick={ ( e ) => {
						// Backdrop only. A click inside the panel must not close it
						// while someone is selecting the text.
						if ( e.target === e.currentTarget ) {
							setDetails( null );
						}
					} }
				>
					<div
						className="w-full max-w-2xl rounded-lg bg-background-primary p-4 shadow-lg"
						role="dialog"
						aria-modal="true"
						aria-label={ __( 'Details', 'sureforms' ) }
					>
						<div className="flex items-start justify-between gap-2">
							<Label size="sm" className="font-semibold">
								{ __( 'Details', 'sureforms' ) }
							</Label>
							<button
								type="button"
								onClick={ () => setDetails( null ) }
								aria-label={ __( 'Close', 'sureforms' ) }
								className="flex items-center bg-transparent border-0 p-0.5 cursor-pointer text-icon-secondary hover:text-icon-primary"
							>
								<X className="size-4" />
							</button>
						</div>
						<Label
							size="xs"
							variant="help"
							className="font-normal block pt-1"
						>
							{ __(
								'What we recorded about this problem. Copy it into your support request so we can start from the cause rather than a description of it.',
								'sureforms'
							) }
						</Label>
						{ /* Selectable and scrollable: clipboard access can be
						     refused, and then selecting by hand is the only way
						     through. */ }
						<pre className="mt-3 mb-0 max-h-80 overflow-auto whitespace-pre-wrap break-words rounded-md bg-background-secondary p-3 text-xs text-text-secondary">
							{ details.details || '' }
						</pre>
						<div className="flex items-center justify-end gap-2 pt-3">
							<Button
								variant="outline"
								size="sm"
								icon={
									copied ? (
										<Check className="size-4" />
									) : (
										<Copy className="size-4" />
									)
								}
								onClick={ handleCopy( details ) }
							>
								{ copied
									? __( 'Copied', 'sureforms' )
									: __( 'Copy details', 'sureforms' ) }
							</Button>
							<Button
								variant="primary"
								size="sm"
								tag="a"
								href={ details.support_url }
								target="_blank"
								rel="noopener noreferrer"
								onClick={ handleFix( details, 'contact_support' ) }
							>
								{ __( 'Contact Support', 'sureforms' ) }
							</Button>
						</div>
					</div>
				</div>
			) }
		</div>
	);
};
