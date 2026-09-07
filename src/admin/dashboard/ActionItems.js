import { __ } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import { Button, Label } from '@bsf/force-ui';
import { CircleCheck, TriangleAlert, ChevronUp, ChevronDown } from 'lucide-react';

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
					// Contact Support is a Gmail compose URL, so it opens in a new
					// tab like any other link. The mailto check stays because
					// srfm_action_items is public and a third party can still
					// contribute one, which must go to the mail client instead.
					external: ! item.cta_url?.startsWith( 'mailto:' ),
				},
			  ]
			: [] ),
	];

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
										className="font-medium focus:outline-none focus:[box-shadow:none] [&>span]:px-0 text-text-secondary shrink-0"
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
												tag="a"
												href={ action.url }
												{ ...( action.external && {
													target: '_blank',
													rel: 'noopener noreferrer',
												} ) }
												onClick={ handleFix(
													item,
													action.name
												) }
												className={ `font-medium focus:outline-none focus:[box-shadow:none] [&>span]:px-0${
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
