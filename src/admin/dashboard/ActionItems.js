import { __ } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import { Button, Label } from '@bsf/force-ui';
import {
	CircleCheck,
	TriangleAlert,
	ChevronUp,
	ChevronDown,
} from 'lucide-react';

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
	const [ dismissed, setDismissed ] = useState( [] );
	const [ open, setOpen ] = useState( true );

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
		...( item.cta_label && item.cta_url
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
										// The dialog itself lives in
										// admin/assets/js/notice-response.js and is
										// called through window.srfmOpenDetails. It is
										// framework-free, appends to document.body --
										// so it is not subject to a containing block
										// established by an ancestor transform, which
										// is what kept an overlay rendered in here
										// from ever appearing -- and it already has to
										// exist for the classic wp-admin notices. A
										// second copy in React is how two surfaces end
										// up with different keyboard behaviour,
										// different contrast and two sets of the same
										// strings.
										action.dialog ? (
											<button
												key={ action.name }
												type="button"
												onClick={ () => {
													handleFix(
														item,
														action.name
													)();
													window.srfmOpenDetails?.( {
														noticeId: item.id,
														text: item.details,
														supportUrl:
															item.support_url,
													} );
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
		</div>
	);
};
