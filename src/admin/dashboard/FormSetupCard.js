/**
 * Dashboard "Finish setting up" card (#3031).
 *
 * The server (`srfm_admin.form_setup_card`) hands over the latest form the user
 * can edit that still has an unfinished setup step — no reply destination, the
 * default thank-you message, or not embedded on a page — along with which steps
 * remain. This renders the contextual nudge and a CTA per incomplete step, plus
 * dismiss (✕) and a 14-day snooze, both persisted per form.
 *
 * @package
 */

import { useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { Button, Title } from '@bsf/force-ui';
import { X } from 'lucide-react';

// Join clause fragments as "a", "a and b", "a, b, and c".
const joinClauses = ( clauses ) => {
	if ( clauses.length <= 1 ) {
		return clauses.join( '' );
	}
	if ( clauses.length === 2 ) {
		return clauses.join( __( ' and ', 'sureforms' ) );
	}
	return (
		clauses.slice( 0, -1 ).join( ', ' ) +
		__( ', and ', 'sureforms' ) +
		clauses[ clauses.length - 1 ]
	);
};

const FormSetupCard = () => {
	const card = window.srfm_admin?.form_setup_card || null;
	const [ visible, setVisible ] = useState( !! card );

	if ( ! card || ! visible ) {
		return null;
	}

	const {
		id,
		title,
		days_ago: daysAgo = 0,
		steps = {},
		edit_url: editUrl,
		replies_url: repliesUrl,
		thankyou_url: thankyouUrl,
		page_url: pageUrl,
	} = card;

	// Optimistically hide, then persist. A failed request just means the card may
	// reappear next load — never a blocking error.
	const update = ( action ) => {
		setVisible( false );
		apiFetch( {
			path: 'sureforms/v1/dismiss-form-setup-card',
			method: 'POST',
			data: { form_id: id, action },
			headers: { 'X-WP-Nonce': window.srfm_admin?.form_setup_card_nonce },
		} ).catch( () => {} );
	};

	const clauses = [];
	if ( steps.replies ) {
		clauses.push( __( 'replies have nowhere to go', 'sureforms' ) );
	}
	if ( steps.page ) {
		clauses.push( __( "the form isn't on a page", 'sureforms' ) );
	}
	if ( steps.thankyou ) {
		clauses.push(
			__( 'the thank-you message is still the default', 'sureforms' )
		);
	}

	const timeText =
		daysAgo > 0
			? sprintf(
				/* translators: %d: number of days. */
				_n(
					'You created this form %d day ago',
					'You created this form %d days ago',
					daysAgo,
					'sureforms'
				),
				daysAgo
			  )
			: __( 'You created this form recently', 'sureforms' );

	return (
		<div className="pt-5 xl:pt-8">
			<div className="relative p-5 rounded-lg shadow-sm bg-background-primary border border-solid border-border-subtle">
				<Button
					variant="ghost"
					size="sm"
					icon={ <X className="size-4" /> }
					onClick={ () => update( 'dismiss' ) }
					aria-label={ __( 'Dismiss', 'sureforms' ) }
					className="absolute top-3 right-3"
				/>

				<Title
					tag="h3"
					size="sm"
					title={ sprintf(
						/* translators: %s: form name. */
						__( 'Finish setting up “%s”', 'sureforms' ),
						title
					) }
				/>

				<p className="mt-1 mb-4 text-sm text-text-secondary max-w-2xl">
					{ sprintf(
						/* translators: 1: "You created this form N days ago", 2: what's left, e.g. "replies have nowhere to go, and the form isn't on a page". */
						__(
							"%1$s and it isn't fully set up yet — %2$s.",
							'sureforms'
						),
						timeText,
						joinClauses( clauses )
					) }
				</p>

				<div className="flex flex-wrap items-center gap-x-5 gap-y-2">
					<Button
						variant="primary"
						size="sm"
						onClick={ () => window.location.assign( editUrl ) }
					>
						{ __( 'Edit form', 'sureforms' ) }
					</Button>

					{ steps.replies && (
						<Button
							variant="link"
							size="sm"
							onClick={ () =>
								window.location.assign( repliesUrl )
							}
						>
							{ __( 'Set where replies go', 'sureforms' ) }
						</Button>
					) }
					{ steps.thankyou && (
						<Button
							variant="link"
							size="sm"
							onClick={ () =>
								window.location.assign( thankyouUrl )
							}
						>
							{ __( 'Edit the thank-you message', 'sureforms' ) }
						</Button>
					) }
					{ steps.page && (
						<Button
							variant="link"
							size="sm"
							onClick={ () => window.location.assign( pageUrl ) }
						>
							{ __( 'Add to a page', 'sureforms' ) }
						</Button>
					) }

					<button
						type="button"
						onClick={ () => update( 'snooze' ) }
						className="p-0 ml-auto text-sm bg-transparent border-0 cursor-pointer text-text-tertiary hover:text-text-secondary"
					>
						{ __( 'Remind me in two weeks', 'sureforms' ) }
					</button>
				</div>
			</div>
		</div>
	);
};

export default FormSetupCard;
