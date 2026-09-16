import { __, sprintf } from '@wordpress/i18n';
import { useState, useEffect, useRef } from '@wordpress/element';
import { Text, Badge, Button, Tabs } from '@bsf/force-ui';
import { ExternalLink } from 'lucide-react';
import { useOnboardingNavigation } from '../hooks';
import { useOnboardingState } from '../onboarding-state';
import NavigationButtons from '../components/navigation-buttons';
import { Header, Divider, BeforeAfterSlider } from '../components';
import { addQueryParam } from '@Utils/Helpers';
import ADDON_COMPARISONS from '../illustrations/addon-comparisons';

const COUPON_CODE = 'ONB10';
const TAB_ROTATE_MS = 3000;

// One tab per add-on; the free/pro mock pair for each lives in
// ../illustrations/addon-comparisons.js keyed by slug.
const TABS = [
	{
		slug: 'multistep',
		tab: __( 'Multistep', 'sureforms' ),
		title: __( 'Multistep Forms', 'sureforms' ),
		description: __(
			'Break complex forms into simple steps, reducing overwhelm and boosting completion. Guide users smoothly through the process.',
			'sureforms'
		),
	},
	{
		slug: 'conditional',
		tab: __( 'Conditional', 'sureforms' ),
		title: __( 'Conditional Fields', 'sureforms' ),
		description: __(
			"Show or hide fields based on user answers. Ask the right questions and display only what's needed to keep forms clean and relevant.",
			'sureforms'
		),
	},
	{
		slug: 'calculation',
		tab: __( 'Calculation', 'sureforms' ),
		title: __( 'Calculation Forms', 'sureforms' ),
		description: __(
			'Add interactive calculators to your forms for instant estimates, quotes, and calculations for your users.',
			'sureforms'
		),
	},
	{
		slug: 'conversational',
		tab: __( 'Conversational', 'sureforms' ),
		title: __( 'Conversational Forms', 'sureforms' ),
		description: __(
			'Create forms that feel like a conversation. One question at a time keeps users engaged and makes form completion easy.',
			'sureforms'
		),
	},
];

const copyText = async ( text ) => {
	try {
		await navigator.clipboard.writeText( text );
		return true;
	} catch ( error ) {
		// Clipboard API unavailable (insecure context / permissions): fall
		// back to a hidden textarea + execCommand.
		try {
			const textArea = document.createElement( 'textarea' );
			textArea.value = text;
			textArea.style.position = 'fixed';
			textArea.style.opacity = '0';
			document.body.appendChild( textArea );
			textArea.focus();
			textArea.select();
			document.execCommand( 'copy' );
			document.body.removeChild( textArea );
			return true;
		} catch ( fallbackError ) {
			return false;
		}
	}
};

const PremiumFeatures = () => {
	const [ , actions ] = useOnboardingState();
	const { navigateToPreviousRoute, navigateToNextRoute } =
		useOnboardingNavigation();
	const [ activeSlug, setActiveSlug ] = useState( TABS[ 0 ].slug );
	const [ isCopied, setIsCopied ] = useState( false );
	const [ isPaused, setIsPaused ] = useState( false );

	// Rotate through the tabs while the pointer is outside the showcase.
	useEffect( () => {
		if ( isPaused ) {
			return;
		}
		const timer = setInterval( () => {
			setActiveSlug( ( current ) => {
				const index = TABS.findIndex(
					( item ) => item.slug === current
				);
				return TABS[ ( index + 1 ) % TABS.length ].slug;
			} );
		}, TAB_ROTATE_MS );
		return () => clearInterval( timer );
	}, [ isPaused ] );

	const activeTab =
		TABS.find( ( item ) => item.slug === activeSlug ) || TABS[ 0 ];

	// Only the first tab is "viewed" without a click; every later entry comes
	// from handleTabChange. Recording the rotation as well would turn
	// viewed_premium_tabs into "sat on the step for 12 seconds".
	useEffect( () => {
		actions.markPremiumTabViewed( TABS[ 0 ].slug );
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [] );

	const pricingUrl = addQueryParam(
		srfm_admin?.sureforms_pricing_page,
		'onboarding_premium_features'
	);

	const handleUpgrade = () => {
		actions.setPremiumUpgradeClicked( true );
		window.open( pricingUrl, '_blank', 'noopener' );
	};

	const handleUpgradeAndContinue = () => {
		handleUpgrade();
		navigateToNextRoute();
	};

	const handleSkip = () => {
		actions.markStepSkipped( 'premiumFeatures' );
		navigateToNextRoute();
	};

	// Held in a ref so unmounting mid-countdown (e.g. clicking Upgrade) clears it.
	const copyTimer = useRef( null );
	useEffect( () => () => clearTimeout( copyTimer.current ), [] );

	const handleCopy = async () => {
		if ( await copyText( COUPON_CODE ) ) {
			setIsCopied( true );
			clearTimeout( copyTimer.current );
			copyTimer.current = setTimeout( () => setIsCopied( false ), 1500 );
		}
	};

	// A deliberate choice should stick, so restart the rotation from it.
	const handleTabChange = ( slug ) => {
		setActiveSlug( slug );
		actions.markPremiumTabViewed( slug );
	};

	return (
		<div className="space-y-7">
			<Header
				tag="h2"
				align="center"
				title={ __( 'Add more to your forms', 'sureforms' ) }
				description={ __(
					'Small additions that make a big difference',
					'sureforms'
				) }
			/>

			<div
				className="flex flex-col items-center gap-8"
				onMouseEnter={ () => setIsPaused( true ) }
				onMouseLeave={ () => setIsPaused( false ) }
			>
				<Tabs.Group
					activeItem={ activeSlug }
					onChange={ ( { value } ) =>
						handleTabChange(
							typeof value === 'string' ? value : value?.slug
						)
					}
					size="sm"
					variant="rounded"
					width="auto"
					// force-ui marks the active tab with bg-background-primary;
					// recolour that to the brand fill from the group so the
					// highlight always follows the component's own active state.
					className="[&_.bg-background-primary]:bg-button-primary [&_.bg-background-primary]:text-text-on-color [&_.bg-background-primary:hover]:text-text-on-color"
				>
					{ TABS.map( ( item ) => (
						<Tabs.Tab
							key={ item.slug }
							slug={ item.slug }
							text={ item.tab }
						/>
					) ) }
				</Tabs.Group>

				<div className="flex items-center justify-center gap-7">
					{ /* Keyed by slug so the divider resets when the tab changes. */ }
					<BeforeAfterSlider
						key={ activeTab.slug }
						before={ ADDON_COMPARISONS[ activeTab.slug ].free }
						after={ ADDON_COMPARISONS[ activeTab.slug ].pro }
						initial={ 20 }
						label={ sprintf(
							/* translators: %s: add-on name, e.g. Multistep Forms. */
							__( 'Compare %s: free versus premium', 'sureforms' ),
							activeTab.title
						) }
					/>
					<div className="w-[304px] space-y-1.5 rounded-lg bg-background-primary p-2">
						<div className="flex items-center gap-2">
							<Text size={ 16 } weight={ 500 } color="primary">
								{ activeTab.title }
							</Text>
							<Badge
								label={ __( 'Premium', 'sureforms' ) }
								size="xs"
								variant="neutral"
							/>
						</div>
						<Text size={ 14 } weight={ 400 } color="tertiary">
							{ activeTab.description }
						</Text>
						<a
							href={ pricingUrl }
							target="_blank"
							rel="noopener noreferrer"
							onClick={ () =>
								actions.setPremiumUpgradeClicked( true )
							}
							className="inline-block text-xs font-semibold text-button-primary no-underline hover:text-button-primary-hover hover:underline"
						>
							{ __( 'Upgrade Now', 'sureforms' ) }
						</a>
					</div>
				</div>
			</div>

			<Divider />

			<div className="flex items-center justify-between gap-2 rounded-lg p-3 ring-1 ring-alert-border-neutral bg-[#F9FAFB]">
				<Text
					size={ 12 }
					weight={ 400 }
					color="primary"
					className="px-1"
				>
					{ __(
						'Selected features require SureForms Business - use code ONB10 to get 10% off on any plan.',
						'sureforms'
					) }
				</Text>
				<Button variant="link" size="xs" onClick={ handleCopy }>
					{ isCopied
						? __( 'Copied', 'sureforms' )
						: __( 'Copy', 'sureforms' ) }
				</Button>
			</div>

			<NavigationButtons
				backProps={ { onClick: navigateToPreviousRoute } }
				skipProps={ {
					onClick: handleSkip,
					text: __( 'Skip', 'sureforms' ),
				} }
				continueProps={ {
					onClick: handleUpgradeAndContinue,
					text: __( 'Upgrade', 'sureforms' ),
					icon: <ExternalLink />,
					className: '[&>svg]:size-4',
				} }
			/>
		</div>
	);
};

export default PremiumFeatures;
