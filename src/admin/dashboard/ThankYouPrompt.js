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
						className="flex items-center justify-center rounded-lg shrink-0"
						style={ {
							width: '2.5rem',
							height: '2.5rem',
							backgroundColor: '#FBEAE3',
						} }
					>
						<MessageSquareText
							className="size-5"
							style={ { color: '#C15A3B' } }
						/>
					</div>

					<div className="min-w-0 pr-6">
						<Title
							tag="h3"
							size="sm"
							title={ __(
								"Don't forget to personalize your Thank You message!",
								'sureforms'
							) }
						/>
						<p className="mt-1 mb-3 text-sm text-text-secondary">
							{ sprintf(
								/* translators: %s: form name. */
								__(
									'Your form “%s” is using the default Thank You message. Personalize it to improve the post-submission experience.',
									'sureforms'
								),
								form.title
							) }
						</p>
						<Button
							variant="primary"
							size="sm"
							onClick={ () =>
								window.location.assign( form.edit_url )
							}
						>
							{ __( 'Edit Thank You Message', 'sureforms' ) }
						</Button>
					</div>
				</div>
			) ) }
		</div>
	);
};

export default ThankYouPrompt;
