import { __, sprintf } from '@wordpress/i18n';
import { useEffect } from '@wordpress/element';
import { ExternalLink } from 'lucide-react';
import { useOnboardingNavigation } from '../hooks';
import { useOnboardingState } from '../onboarding-state';
import { Divider, Header, FeatureList, HERO_PANEL_CLASS } from '../components';
import NavigationButtons from '../components/navigation-buttons';
// Rendered via <object>, not <img> or an inlined component: the artwork
// animates with CSS keyframes, which svgr/svgo strips when inlining, and
// <object> gives the SVG its own document where they run untouched.
import cacheConflictIllustration from '@Image/onboarding/cache-conflict.svg';

const tips = [
	__( 'Exclude SureForms pages from the cache completely', 'sureforms' ),
	__(
		'Clear your browser cache after changing plugin settings',
		'sureforms'
	),
	__(
		'Prevent failed form submissions and unexpected caching errors',
		'sureforms'
	),
];

/**
 * Shown only when a recognised caching plugin is active (see
 * getVisibleRoutes in ../hooks.js). "View full guide" opens the plugin's
 * setup guide without leaving the wizard; "I've fixed this" records the
 * acknowledgement and moves on.
 */
const CacheConflict = () => {
	const { navigateToNextRoute, navigateToPreviousRoute } =
		useOnboardingNavigation();
	const [ , actions ] = useOnboardingState();

	// Flip null -> false on render so the analytics blob distinguishes
	// "never saw this step" from "saw it and did not acknowledge".
	useEffect( () => {
		actions.setCacheConflictAcknowledged( false );
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [] );

	const pluginName = srfm_admin?.caching_plugin || '';
	const guideUrl = srfm_admin?.caching_plugin_doc_url || '';

	const handleViewGuide = () => {
		if ( guideUrl ) {
			window.open( guideUrl, '_blank', 'noopener' );
		}
	};

	const handleFixed = () => {
		actions.setCacheConflictAcknowledged( true );
		navigateToNextRoute();
	};

	return (
		<div className="space-y-4">
			<div className={ HERO_PANEL_CLASS }>
				<object
					type="image/svg+xml"
					data={ cacheConflictIllustration }
					className="pointer-events-none block h-auto w-full"
					aria-label={ __(
						'Illustration comparing a form with and without a cache exclusion',
						'sureforms'
					) }
				/>
			</div>

			<Header
				title={ sprintf(
					/* translators: %s: caching plugin name, e.g. LiteSpeed Cache. */
					__( '%s might be blocking your forms', 'sureforms' ),
					pluginName
				) }
				description={ __(
					"Without an exclusion, visitors may see outdated pages or submissions that don't go through as expected, leading to failed form entries and frustrated users.",
					'sureforms'
				) }
			/>

			<FeatureList
				heading={ __( 'Keep Your Forms Working', 'sureforms' ) }
				items={ tips }
			/>

			<Divider />

			<NavigationButtons
				backProps={ { onClick: navigateToPreviousRoute } }
				skipProps={ {
					onClick: handleFixed,
					text: __( "I've fixed this, continue setup", 'sureforms' ),
				} }
				continueProps={ {
					onClick: handleViewGuide,
					text: __( 'View full guide', 'sureforms' ),
					icon: <ExternalLink />,
					className: '[&>svg]:size-4',
					disabled: ! guideUrl,
				} }
			/>
		</div>
	);
};

export default CacheConflict;
