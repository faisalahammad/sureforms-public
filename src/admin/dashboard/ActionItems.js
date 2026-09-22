import { __ } from '@wordpress/i18n';
import {
	useState,
	useRef,
	useMemo,
	useEffect,
	useLayoutEffect,
} from '@wordpress/element';
import { Button, Dialog, Label } from '@bsf/force-ui';
import {
	CircleCheck,
	TriangleAlert,
	ChevronUp,
	ChevronDown,
	Check,
	Copy,
} from 'lucide-react';
import safeUrl from './utils/safeUrl';

/**
 * Dashboard panel listing what SureForms has checked on this site.
 *
 * Follows SureRank's Page Checks: one row per problem, with a fix link and, for
 * advisory rows, an Ignore control. Row markup mirrors SureRank's CheckCard so
 * the two plugins look like siblings. Passing checks are not listed -- a panel
 * confirming nothing is wrong is something people learn to skip.
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
	// Read here rather than at module scope. Optional chaining does not protect
	// against an undeclared identifier, so a bundle enqueued without its localize
	// data would throw at module eval and take the whole dashboard down instead of
	// just this panel.
	const dialogLabels = useMemo(
		() => ( typeof srfm_admin === 'undefined' ? {} : srfm_admin?.details_dialog || {} ),
		[]
	);

	// Whether copying is possible at all. On a plain-HTTP admin
	// navigator.clipboard does not exist, so the copy step cannot be a
	// precondition for anything -- see the unlock reasoning in handleCopy.
	const canCopy = useMemo(
		() =>
			!! (
				navigator.clipboard &&
				( navigator.clipboard.write || navigator.clipboard.writeText )
			),
		[]
	);

	const [ dismissed, setDismissed ] = useState( [] );
	const [ open, setOpen ] = useState( true );
	// The item whose details are being read, or null.
	const [ details, setDetails ] = useState( null );
	const [ copied, setCopied ] = useState( false );
	// What the hint line says. A live region for the copy result. It no longer
	// announces an unlock, because Contact Support is never locked: the mailto:
	// carries the report, so there is nothing to copy first.
	const [ hint, setHint ] = useState( '' );

	// force-ui's Dialog.Panel renders FloatingOverlay in place in the React tree
	// -- FloatingPortal is used only by Dialog.Portal, which Panel does not use.
	// Left there, the overlay is a descendant of the Form Checks card inside the
	// dashboard's Container nesting, and position:fixed resolves against the
	// nearest ancestor establishing a containing block, which is what kept an
	// overlay rendered in here from ever appearing. Portalling to
	// #srfm-dialog-root rather than document.body matters: tailwind.config.js
	// scopes every utility behind an :is() allowlist that includes that id, so
	// body would render the dialog with no styling at all.
	const [ dialogRoot, setDialogRoot ] = useState( null );

	useLayoutEffect( () => {
		let root = document.getElementById( 'srfm-dialog-root' );

		if ( ! root ) {
			root = document.createElement( 'div' );
			root.id = 'srfm-dialog-root';
			document.body.appendChild( root );
		}

		setDialogRoot( root );
	}, [] );

	// Dialog.Panel forwards only className, so aria-labelledby cannot be passed to
	// the node it puts role="dialog" on. Wired here instead, against the ids set
	// on Title and Description below -- without it the dialog has no accessible
	// name at all.
	const titleId = 'srfm-details-title';
	const descriptionId = 'srfm-details-description';

	useEffect( () => {
		if ( ! details || ! dialogRoot ) {
			return;
		}

		// Both of them. There are two role="dialog" nodes in the portal, not one:
		// force-ui's outer motion div hardcodes it, and floating-ui's useRole()
		// puts it on the inner floating element too. querySelector returns the
		// outer scroll wrapper, so naming only that left the panel focus is
		// actually trapped inside unnamed. Scoped to dialogRoot because that id is
		// shared with other dialogs in this codebase.
		dialogRoot
			.querySelectorAll( '[role="dialog"]' )
			.forEach( ( node ) => {
				node.setAttribute( 'aria-labelledby', titleId );
				node.setAttribute( 'aria-describedby', descriptionId );
			} );
	}, [ details, dialogRoot ] );

	// Initial focus on Copy details rather than whatever FloatingFocusManager
	// reaches first, which is the close cross. The ref has to be attached to the
	// Button as well as declared -- force-ui's Button is forwardRef and applies it
	// to the rendered tag, and without the attribute this was a permanent no-op
	// that the optional chaining hid.
	const copyRef = useRef( null );

	useEffect( () => {
		if ( ! details ) {
			return;
		}

		const timer = window.setTimeout( () => copyRef.current?.focus(), 50 );

		return () => window.clearTimeout( timer );
	}, [ details ] );

	// Finding 1: force-ui always passes returnFocus to FloatingFocusManager as
	// `refs.reference` -- a ref object that always exists, so its `c?.reference`
	// guard is always truthy -- whose `.current` is null because no trigger prop
	// is used. FloatingFocusManager's getReturnElement() only takes its correct
	// `getPreviouslyFocusedElement()` path when returnFocus is a boolean, so
	// passing the object defeats the library's own default and every close path
	// drops focus to <body>. Restored by hand instead.
	const openerRef = useRef( null );

	useEffect( () => {
		if ( details ) {
			return;
		}

		const opener = openerRef.current;

		if ( ! opener || typeof opener.focus !== 'function' ) {
			return;
		}

		openerRef.current = null;

		// After the library has finished its own restore attempt on unmount,
		// otherwise it lands on the detached fallback span afterwards.
		const timer = window.setTimeout( () => opener.focus(), 0 );

		return () => window.clearTimeout( timer );
	}, [ details ] );

	// Low: an uncleared label timer crosses itself on two copies inside 2s, and
	// leaves a setState pending on a possibly-unmounted tree when the dialog is
	// closed inside that window.
	const revertRef = useRef( 0 );

	// Bumped per open and per close; a response carrying a stale token is dropped.
	const requestRef = useRef( 0 );

	useEffect( () => () => window.clearTimeout( revertRef.current ), [] );

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
					url: safeUrl( item.guide_url ),
					external: true,
				},
			  ]
			: [] ),
		...( item.cta_label && item.cta_url
			? [
				{
					name: item.cta_action,
					label: item.cta_label,
					url: safeUrl( item.cta_url ),
					// An item carrying details opens them here rather than
					// navigating: the point is to read the diagnostics before
					// sending them anywhere. cta_url stays as the fallback for a
					// browser with no JavaScript, which cannot open a dialog.
					dialog: !! item.has_details,
					// A mailto must reach the mail client, not a browser tab.
					// srfm_action_items is public, so one can still arrive that way.
					// Matched case-insensitively: a MAILTO: from the filter would
					// otherwise get target="_blank" and open a blank tab.
					external: ! /^mailto:/i.test( item.cta_url ),
				},
			  ]
			: [] ),
	];

	const handleCopy = ( item ) => async () => {
		const text = item.details || '';

		try {
			// Both flavours. A rich-text composer drops the newlines out of plain
			// text, which is what turns the diagnostics into one paragraph; pasting
			// HTML keeps every break, and <pre> keeps the log's columns lined up. A
			// plain-text field still gets the text.
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
			} else if ( navigator.clipboard?.writeText ) {
				// No ClipboardItem: the formatting is lost, which still beats
				// copying nothing.
				await navigator.clipboard.writeText( text );
			} else {
				throw new Error( 'no clipboard' );
			}

			setCopied( true );
			// Reverts on its own: a button stuck on "Copied" says nothing about the
			// next click.
			window.clearTimeout( revertRef.current );
			revertRef.current = window.setTimeout( () => setCopied( false ), 2000 );

			// Reported inside the try, so the event means a copy happened rather
			// than a copy was attempted. The classic notice records it the same way.
			post( 'srfm_notice_response', srfm_admin?.notice_response_nonce, {
				notice_id: item.id,
				button: 'copy_details',
			} );
		} catch ( e ) {
			// Refused outright, or no clipboard API at all. Say so rather than
			// claiming it was copied. Contact Support is unaffected -- it carries
			// the report in the email body, not from the clipboard -- and the text
			// is on screen and selectable either way.
			setCopied( false );
			setHint( dialogLabels.copyFailed || '' );
		}
	};

	const closeDetails = () => {
		// Invalidates any fetch still in flight, so a slow response cannot refill a
		// dialog the user has already closed.
		requestRef.current += 1;
		setDetails( null );
	};

	// Fetched on open rather than localised with the page. The diagnostics are
	// written through a public REST route, so shipping them in srfm_admin put
	// attacker-authored text into every admin screen's HTML whether or not anyone
	// opened this dialog. See Admin::handle_action_item_details().
	//
	// The dialog opens immediately with a placeholder and fills in when the
	// response lands. A surface whose whole job is reporting a failure must not be
	// a button that does nothing until the network answers.
	const openDetails = ( item ) => {
		// Guards against interleaving: open A, close it, open B, and A's response
		// would otherwise land in B's dialog.
		const token = ++requestRef.current;

		setDetails( {
			...item,
			details: dialogLabels.loading || '',
			support_url: '',
			pending: true,
		} );

		const fail = () => {
			setHint( '' );

			setDetails( ( prev ) =>
				prev
					? {
						...prev,
						details: dialogLabels.unavailable || '',
						// Untagged fallback: Contact Support is the only action
						// that retires these notices, so a failed fetch must not
						// take the way out with it.
						support_url: srfm_admin?.support_url || '',
						pending: false,
						failed: true,
					  }
					: prev
			);
		};

		const ajaxUrl = srfm_admin?.ajax_url;
		const nonce = srfm_admin?.action_item_details_nonce;

		if ( ! ajaxUrl || ! nonce ) {
			fail();
			return;
		}

		const body = new FormData();
		body.append( 'action', 'srfm_action_item_details' );
		body.append( 'nonce', nonce );
		body.append( 'category', item.category || '' );

		fetch( ajaxUrl, { method: 'POST', credentials: 'same-origin', body } )
			.then( ( response ) => response.json() )
			.then( ( json ) => {
				if ( ! json?.success || ! json?.data ) {
					throw new Error( 'unavailable' );
				}

				if ( token !== requestRef.current ) {
					return;
				}

				setDetails( ( prev ) =>
					prev
						? {
							...prev,
							details: json.data.details || '',
							// Same fallback as the failure path. A successful
							// fetch must not end up with less to act on than a
							// failed one: these notices are not dismissible and
							// Contact Support is the only thing that retires
							// them, so an empty destination here is a dead end.
							support_url:
									json.data.support_url ||
									srfm_admin?.support_url ||
									'',
							pending: false,
						  }
						: prev
				);
			} )
			.catch( () => {
				if ( token !== requestRef.current ) {
					return;
				}

				fail();
			} );
	};

	// Opens the dialog rather than navigating: the details are read here before
	// anything is sent. Nothing to download, nothing to intercept.
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
										className="font-medium no-underline hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-1 focus:[box-shadow:none] [&>span]:px-0 text-text-secondary shrink-0"
									>
										{ __( 'Ignore', 'sureforms' ) }
									</Button>
								) }
							</div>
							{ !! actionsFor( item ).length && (
								<div className="pl-6 flex items-center gap-4">
									{ actionsFor( item ).map( ( action, index ) =>
										// A native button for the one that opens the
										// dialog. Routed through force-ui's Button it
										// followed the href instead -- the same page in
										// a new tab -- and a control that navigates is
										// the wrong element for something that opens a
										// panel in place.
										//
										// force-ui's Dialog rather than an overlay
										// rendered in place. An overlay here is a
										// descendant of the panel's Container nesting,
										// and position:fixed resolves against the
										// nearest ancestor establishing a containing
										// block -- which is why one rendered here never
										// appeared. Dialog portals out, and brings the
										// focus trap, scroll lock and return-focus with
										// it. The classic wp-admin notices keep their
										// own framework-free dialog, because there is
										// no React on those screens; both read their
										// strings from the same PHP array.
										action.dialog ? (
											<button
												key={
													action.name ||
													`${ item.id }-${ index }`
												}
												type="button"
												onClick={ ( event ) => {
													// Captured before the dialog
													// mounts: force-ui defeats
													// FloatingFocusManager's own
													// return-focus, so nothing
													// else remembers this.
													openerRef.current =
														event.currentTarget;

													// Only when the server named
													// one: an unnamed action posts
													// "undefined" and 400s.
													if ( action.name ) {
														handleFix(
															item,
															action.name
														)();
													}
													setCopied( false );
													// Each failure is its own
													// report, so the copy has to
													// be made again for this one.
													setHint(
														canCopy
															? ''
															: dialogLabels.copyFailed ||
																	''
													);
													openDetails( item );
												} }
												className={ `bg-transparent border-0 p-0 cursor-pointer text-xs font-medium no-underline hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-1${
													index > 0
														? ' text-text-secondary'
														: ' text-link-primary hover:text-link-primary-hover'
												}` }
											>
												{ action.label }
											</button>
										) : (
											<Button
												key={
													action.name ||
													`${ item.id }-${ index }`
												}
												variant="link"
												size="xs"
												tag="a"
												href={ action.url }
												{ ...( action.external && {
													target: '_blank',
													rel: 'noopener noreferrer',
												} ) }
												// Only when the server named one.
												// An unnamed action posts
												// "undefined" as the button and is
												// rejected by the allowlist anyway.
												{ ...( action.name && {
													onClick: handleFix(
														item,
														action.name
													),
												} ) }
												// Underlined on hover only, matching
												// Quick Access below it. Three
												// underlined links stacked in a narrow
												// column read as a block of noise
												// rather than as actions.
												className={ `font-medium no-underline hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-1 focus:[box-shadow:none] [&>span]:px-0${
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

			     exitOnClickOutside is passed explicitly -- force-ui defaults it to
			     false, so without it a backdrop click does nothing, where the
			     classic dialog closes. */ }
			<Dialog
				design="simple"
				exitOnEsc
				exitOnClickOutside
				scrollLock
				open={ !! details }
				setOpen={ ( next ) => {
					// The boolean matters: discarding it means a future trigger or
					// a library change calling setOpen(true) would close this.
					if ( ! next ) {
						closeDetails();
					}
				} }
			>
				{ !! dialogRoot && (
					<Dialog.Portal root={ dialogRoot }>
						<Dialog.Backdrop />
						{ /* Wider than force-ui's default w-120: this holds a
						     diagnostics block and a fenced log, and 480px wraps
						     almost every line of it. */ }
						<Dialog.Panel className="gap-0 w-[50rem] max-w-[calc(100vw-2rem)]">
							<Dialog.Header>
								<div className="flex items-center justify-between">
									<Dialog.Title id={ titleId }>
										{ dialogLabels.title ||
											__( 'Details', 'sureforms' ) }
									</Dialog.Title>
									{ /* force-ui hardcodes an untranslated
							     aria-label="Close dialog"; it sits before the prop
							     spread, so this overrides it. */ }
									<Dialog.CloseButton
										onClick={ closeDetails }
										aria-label={
											dialogLabels.close ||
											__( 'Close', 'sureforms' )
										}
									/>
								</div>
								<Dialog.Description id={ descriptionId }>
									{ dialogLabels.description || '' }
								</Dialog.Description>
							</Dialog.Header>
							<Dialog.Body className="mt-3">
								{ /* Selectable and scrollable: clipboard access can be
						     refused, and then selecting by hand is the only way
						     through. tabIndex because Chromium and WebKit do not
						     make a scroll container focusable on their own, so
						     without it a keyboard user cannot reach the very thing
						     the dialog exists to show. */ }
								<pre
									tabIndex={ 0 }
									// Announced as busy while the payload is in
									// flight, so a screen reader says the region
									// is still filling rather than reading the
									// placeholder as if it were the report.
									aria-busy={
										details?.pending ? 'true' : undefined
									}
									{ ...( dialogLabels.logRegion && {
										role: 'region',
										'aria-label': dialogLabels.logRegion,
									} ) }
									className="m-0 max-h-80 overflow-auto whitespace-pre-wrap break-words rounded-md bg-background-secondary p-3 text-xs text-text-secondary"
								>
									{ details?.details || '' }
								</pre>
							</Dialog.Body>
							<Dialog.Footer className="justify-between">
								{ /* Visible text, not a title attribute: a title never fires
						     on keyboard focus and is commonly dropped by screen
						     readers on an unavailable control, so the sentence
						     saying why the button is inert could not be read by
						     anyone. role="status" so the unlock is announced. */ }
								<Label
									size="xs"
									tag="p"
									variant="neutral"
									role="status"
									className="font-normal text-text-secondary m-0 min-h-5"
								>
									{ /* A space rather than '', and tag="p"
									     rather than the default label: force-ui's
									     Label returns null on falsy children, so
									     the region did not exist on open and was
									     inserted with its text already set, which
									     is generally not announced. */ }
									{ hint || ' ' }
								</Label>
								<div className="flex items-center gap-2">
									{ /* Nothing to copy before the payload lands, and
									     nothing worth copying if it never did -- an
									     error message on the clipboard is not a
									     report. */ }
									{ ! details?.failed && (
										<Button
											ref={ copyRef }
											variant="outline"
											size="sm"
											disabled={ !! details?.pending }
											icon={
												copied ? (
													<Check className="size-4" />
												) : (
													<Copy className="size-4" />
												)
											}
											onClick={
												details
													? handleCopy( details )
													: undefined
											}
										>
											{ copied
												? dialogLabels.copied ||
												  __( 'Copied', 'sureforms' )
												: dialogLabels.copy ||
												  __(
												  	'Copy details',
												  	'sureforms'
												  ) }
										</Button>
									) }
									{ /* Not rendered at all without a destination. An empty
							     href resolves to the current document, so the click
							     would open a duplicate of this page and still
							     acknowledge the failure -- standing the notice down
							     without anything having been reported. Reachable
							     through srfm_action_items for an item carrying
							     details but no support_url.

							     No longer gated on a copy. The mailto: carries the
							     diagnostics and the log in the body, so there is
							     nothing for the person to paste and nothing to wait
							     for -- the 2.12.6 behaviour, restored. */ }
									{ !! safeUrl( details?.support_url ) && (
										<Button
											variant="primary"
											size="sm"
											tag="a"
											href={ safeUrl(
												details.support_url
											) }
											// Not on a mailto: -- it has no
											// document to open, so _blank
											// leaves a blank tab behind. This
											// is now the usual case rather
											// than the exception, and
											// srfm_support_contact_url can
											// still return an http(s) page.
											{ ...( ! /^mailto:/i.test(
												details.support_url || ''
											) && {
												target: '_blank',
												rel: 'noopener noreferrer',
											} ) }
											onClick={ () => {
												// Records the click, which is also
												// what stands the notice down until
												// something new fails.
												handleFix(
													details,
													'contact_support'
												)();
												// The composer takes over from
												// here, so the dialog has nothing
												// left to show.
												closeDetails();
											} }
											className="no-underline hover:no-underline"
										>
											{ dialogLabels.contact ||
												__(
													'Contact Support',
													'sureforms'
												) }
										</Button>
									) }
								</div>
							</Dialog.Footer>
						</Dialog.Panel>
					</Dialog.Portal>
				) }
			</Dialog>
		</div>
	);
};
