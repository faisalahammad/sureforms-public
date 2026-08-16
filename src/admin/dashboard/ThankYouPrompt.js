/**
 * Dashboard prompt nudging the admin to personalise a new form's Thank You
 * message (#3030).
 *
 * The server (`srfm_admin.thankyou_prompt_forms`) lists only the latest form
 * whose confirmation message is still the shipped default and which the current
 * user may edit and has not dismissed — so this component just renders what it
 * is given. The CTA deep-links into the editor with `srfm_focus=thankyou`;
 * dismissing persists per form so it does not return on the next load.
 *
 * @package
 */

import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { Button, Title } from '@bsf/force-ui';
import { X, MessageSquareText } from 'lucide-react';

const ThankYouPrompt = () => {
	const initial = Array.isArray( window.srfm_admin?.thankyou_prompt_forms )
		? window.srfm_admin.thankyou_prompt_forms
		: [];
	const [ forms, setForms ] = useState( initial );

	if ( ! forms.length ) {
		return null;
	}

	// Optimistically hide, then persist the dismissal. A failed request just
	// means the prompt may reappear next load — never a blocking error.
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
			{ forms.map( ( form ) => (
				<div
					key={ form.id }
					className="flex items-center justify-between gap-3 p-4 mb-4 rounded-lg shadow-sm bg-background-primary border border-solid border-border-subtle"
				>
					<div className="flex items-center gap-3 min-w-0">
						<div className="flex items-center justify-center p-2 rounded-md bg-background-secondary shrink-0">
							<MessageSquareText className="size-5 text-icon-primary" />
						</div>
						<div className="min-w-0">
							<Title
								tag="h3"
								size="xs"
								title={ __(
									"Don't forget to personalize your Thank You message!",
									'sureforms'
								) }
							/>
							<p className="m-0 text-sm text-text-secondary">
								{ sprintf(
									/* translators: %s: form name. */
									__(
										'Your form “%s” is using the default Thank You message. Personalize it to improve the post-submission experience.',
										'sureforms'
									),
									form.title
								) }
							</p>
						</div>
					</div>
					<div className="flex items-center gap-2 shrink-0">
						<Button
							variant="primary"
							size="sm"
							onClick={ () =>
								window.location.assign( form.edit_url )
							}
						>
							{ __( 'Edit Thank You Message', 'sureforms' ) }
						</Button>
						<Button
							variant="ghost"
							size="sm"
							icon={ <X className="size-4" /> }
							onClick={ () => dismiss( form.id ) }
							aria-label={ __( 'Dismiss', 'sureforms' ) }
						/>
					</div>
				</div>
			) ) }
		</div>
	);
};

export default ThankYouPrompt;
