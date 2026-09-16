import { __ } from '@wordpress/i18n';
import { createInterpolateElement } from '@wordpress/element';
import { Text } from '@bsf/force-ui';
import { useOnboardingNavigation } from '../hooks';
import { Divider, FeatureList, FeatureCarousel } from '../components';
import NavigationButtons from '../components/navigation-buttons';
import { getWelcomeSlides } from '../illustrations/welcome-slides';

const trustItems = [
	__( 'Gutenberg Native', 'sureforms' ),
	__( 'No Code Required', 'sureforms' ),
	__( 'Works With Any Theme', 'sureforms' ),
];

const Welcome = () => {
	const { navigateToNextRoute } = useOnboardingNavigation();

	return (
		<div className="space-y-6">
			<div className="space-y-2 text-center">
				<Text
					as="h2"
					size={ 30 }
					lineHeight={ 38 }
					weight={ 600 }
					color="primary"
				>
					{ createInterpolateElement(
						__(
							'Welcome to <brand>SureForms</brand> <wave>👋</wave>',
							'sureforms'
						),
						{
							brand: <span className="text-button-primary" />,
							wave: <span className="srfm-onboarding-wave" />,
						}
					) }
				</Text>
				<Text as="p" size={ 16 } color="secondary">
					{ __(
						'Build WordPress forms that actually convert.',
						'sureforms'
					) }
					<br />
					{ __(
						"Let's get you set up in under a minute.",
						'sureforms'
					) }
				</Text>
			</div>

			<FeatureCarousel slides={ getWelcomeSlides() } />

			<NavigationButtons
				containerProps={ { justify: 'center' } }
				continueProps={ {
					onClick: navigateToNextRoute,
					text: __( 'Set Up SureForms Now', 'sureforms' ),
				} }
			/>

			<Divider />

			<FeatureList inline size={ 12 } items={ trustItems } />
		</div>
	);
};

export default Welcome;
