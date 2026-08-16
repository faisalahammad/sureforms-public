/**
 * Dashboard "Finish setting up" card (#3030 / #3031).
 *
 * The server (`srfm_admin.thankyou_prompt_forms`) lists the latest form the
 * current user can edit that still has an unfinished setup step — no reply
 * destination, the default thank-you message, or not embedded on a page — along
 * with which steps remain. This renders the contextual nudge with a CTA per
 * incomplete step; dismissing persists per form so it does not return.
 *
 * @package
 */

import { useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { Button, Title } from '@bsf/force-ui';
import { X, FileText } from 'lucide-react';

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

const ThankYouPrompt = () => {
	const initial = Array.isArray( window.srfm_admin?.thankyou_prompt_forms )
		? window.srfm_admin.thankyou_prompt_forms
		: [];
	const [ forms, setForms ] = useState( initial );

	if ( ! forms.length ) {
		return null;
	}

	// Optimistically hide, then persist the dismissal. A failed request just
	// means the card may reappear next load — never a blocking error.
	const dismiss = ( formId ) => {
		setForms( ( prev ) => prev.filter( ( form ) => form.id !== formId ) );
		apiFetch( {
			path: 'sureforms/v1/dismiss-thankyou-prompt',
			method: 'POST',
			data: { form_id: formId },
			headers: { 'X-WP-Nonce': window.srfm_admin?.thankyou_prompt_nonce },
		} ).catch( () => {} );
	};

	return (
		<div className="pt-5 xl:pt-8">
			{ forms.map( ( form ) => {
				const steps = form.steps || {};
				const daysAgo = Number( form.days_ago ) || 0;

				const clauses = [];
				if ( steps.replies ) {
					clauses.push(
						__( 'replies have nowhere to go', 'sureforms' )
					);
				}
				if ( steps.page ) {
					clauses.push( __( "the form isn't on a page", 'sureforms' ) );
				}
				if ( steps.thankyou ) {
					clauses.push(
						__(
							'the thank-you message is still the default',
							'sureforms'
						)
					);
				}

				const timeText =
					daysAgo > 0
						? sprintf(
							/* translators: %d: number of days. */
							_n(
								'You started this form %d day ago',
								'You started this form %d days ago',
								daysAgo,
								'sureforms'
							),
							daysAgo
						  )
						: __( 'You started this form recently', 'sureforms' );

				return (
					<div
						key={ form.id }
						className="relative flex items-start gap-4 p-5 mb-4 rounded-xl bg-background-primary border-0.5 border-solid border-border-subtle shadow-sm-blur-1"
					>
						<Button
							variant="ghost"
							size="sm"
							icon={ <X className="size-4" /> }
							onClick={ () => dismiss( form.id ) }
							aria-label={ __( 'Dismiss', 'sureforms' ) }
							className="absolute top-3 right-3"
						/>

						<div
							className="flex items-center justify-center rounded-xl shrink-0"
							style={ {
								width: '2.75rem',
								height: '2.75rem',
								backgroundColor: '#FBEAE3',
							} }
						>
							<FileText
								className="size-6"
								style={ { color: '#C15A3B' } }
							/>
						</div>

						<div className="min-w-0 pr-6">
							<Title
								tag="h3"
								size="md"
								title={ sprintf(
									/* translators: %s: form name. */
									__(
										'Finish setting up “%s”',
										'sureforms'
									),
									form.title
								) }
							/>
							<p className="mt-1.5 mb-4 text-sm text-text-secondary max-w-2xl">
								{ sprintf(
									/* translators: 1: "You started this form N days ago", 2: what's left, e.g. "replies have nowhere to go, and the form isn't on a page". */
									__(
										"%1$s and haven't opened it since. It isn't collecting anything yet — %2$s.",
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
									onClick={ () =>
										window.location.assign( form.edit_url )
									}
								>
									{ __( 'Edit form', 'sureforms' ) }
								</Button>

								{ steps.replies && (
									<Button
										variant="link"
										size="sm"
										onClick={ () =>
											window.location.assign(
												form.replies_url
											)
										}
									>
										{ __(
											'Set where replies go',
											'sureforms'
										) }
									</Button>
								) }
								{ steps.thankyou && (
									<Button
										variant="link"
										size="sm"
										onClick={ () =>
											window.location.assign(
												form.thankyou_url
											)
										}
									>
										{ __(
											'Edit the thank-you message',
											'sureforms'
										) }
									</Button>
								) }
								{ steps.page && (
									<Button
										variant="link"
										size="sm"
										onClick={ () =>
											window.location.assign(
												form.page_url
											)
										}
									>
										{ __( 'Add to a page', 'sureforms' ) }
									</Button>
								) }
							</div>
						</div>
					</div>
				);
			} ) }
		</div>
	);
};

export default ThankYouPrompt;
