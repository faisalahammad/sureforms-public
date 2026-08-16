import SRFMAdvancedPanelBody from '@Components/advanced-panel-body';
import PageBreakSettings from '@Components/page-break-settings';
import { useDeviceType } from '@Controls/getPreviewType';
import { ToggleControl } from '@wordpress/components';
import { useDispatch, useSelect } from '@wordpress/data';
import { store as editorStore } from '@wordpress/editor';
import { useEffect, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { applyFilters } from '@wordpress/hooks';

// Force-UI
import Dialog from '../components/dialog/Dialog';
import { FormRestrictionProvider } from '../components/form-restrictions/context';
import { prepareBlockSlugs } from '@Utils/Helpers';

let prevMetaHash = '';

// Capture the dashboard deep-link target (?srfm_focus=…) the moment this
// bundle evaluates — the block editor strips unrecognised query args from the
// URL shortly after it boots, so reading the param later (inside a mount
// effect) finds it already gone. Snapshotting it here, before Gutenberg's URL
// cleanup runs, is what makes the deep-link reliable.
const srfmDeepLinkFocus = new URLSearchParams( window.location.search ).get(
	'srfm_focus'
);

function GeneralSettings( props ) {
	const { createNotice, removeNotice } = useDispatch( 'core/notices' );

	const { editPost } = useDispatch( editorStore );
	const { defaultKeys, isPageBreak } = props;
	let sureformsKeys = useSelect( ( select ) =>
		select( editorStore ).getEditedPostAttribute( 'meta' )
	);

	const deviceType = useDeviceType();
	const [ rootContainer, setRootContainer ] = useState(
		document.getElementById( 'srfm-form-container' )
	);
	const root = document.documentElement.querySelector( 'body' );
	const [ isOpen, setOpen ] = useState( false );
	const [ popupTab, setPopupTab ] = useState( false );
	// Mirrors `isOpen` for the deep-link poll below, which reads it from a
	// long-lived interval closure that would otherwise see a stale value.
	const isOpenRef = useRef( isOpen );
	isOpenRef.current = isOpen;
	const [ hasValidationErrors, setHasValidationErrors ] = useState( false );

	const closeModal = () => {
		if (
			hasValidationErrors &&
			! confirm(
				__(
					'Are you sure you want to close? Your unsaved changes will be lost as you have some validation errors.',
					'sureforms'
				)
			)
		) {
			return;
		}

		setOpen( false );

		if ( btoa( JSON.stringify( sureformsKeys ) ) !== prevMetaHash ) {
			createNotice(
				'warning',
				__(
					'There are few unsaved changes. Please save your changes to reflect the updates.',
					'sureforms'
				),
				{
					id: 'srfm-unsaved-changes-warning',
					isDismissible: true,
				}
			);
		}
	};

	useSelect( ( select ) => {
		if ( ! select( 'core/editor' ).isSavingPost() ) {
			return;
		}

		if (
			select( 'core/notices' )
				.getNotices()
				?.filter(
					( notice ) => 'srfm-unsaved-changes-warning' === notice?.id
				).length > 0
		) {
			// Remove SRFM unsaved changes notice if user is saving the current form.
			removeNotice( 'srfm-unsaved-changes-warning' );
		}
	}, [] );

	// if device type is desktop then
	useEffect( () => {
		setTimeout( () => {
			const tabletPreview =
				document.getElementsByClassName( 'is-tablet-preview' );
			const mobilePreview =
				document.getElementsByClassName( 'is-mobile-preview' );
			if ( tabletPreview.length !== 0 || mobilePreview.length !== 0 ) {
				const preview = tabletPreview[ 0 ] || mobilePreview[ 0 ];
				if ( preview ) {
					const iframe = preview.querySelector( 'iframe' );
					const iframeDocument =
						iframe?.contentWindow.document ||
						iframe?.contentDocument;
					const iframeBody = iframeDocument
						?.querySelector( 'html' )
						?.querySelector( 'body' );

					setRootContainer(
						iframeBody.querySelector( '.is-root-container' )
					);
				}
			} else {
				setRootContainer(
					document.getElementById( 'srfm-form-container' )
				);
			}
		}, 100 );
	}, [ deviceType, rootContainer ] );

	if ( sureformsKeys ) {
		// Font Size
		root.style.setProperty(
			'--srfm-font-size',
			sureformsKeys._srfm_fontsize
				? sureformsKeys._srfm_fontsize + 'px'
				: 'none'
		);
	} else {
		sureformsKeys = defaultKeys;
		editPost( {
			meta: sureformsKeys,
		} );
	}

	/*
	 * function to update post metas.
	 */
	function updateMeta( option, value ) {
		let value_id = 0;
		let key_id = '';

		// Form Container
		if ( option === '_srfm_bg_image' ) {
			if ( value ) {
				value_id = value.id;
				value = value.sizes.full.url;
			}
			key_id = option + '_id';
		}

		// Header Background Image
		if ( option === '_srfm_cover_image' ) {
			if ( value ) {
				value_id = value.id;
				value = value.sizes.full.url;
			}
			key_id = option + '_id';
		}

		const option_array = {};

		if ( key_id ) {
			option_array[ key_id ] = value_id;
		}
		option_array[ option ] = value;
		editPost( {
			meta: option_array,
		} );
	}

	const singleFormSettingsComponents = [
		{
			id: 'form_confirmation',
			title: __( 'Form Confirmation', 'sureforms' ),
		},
		{
			id: 'email_notification',
			title: __( 'Email Notification', 'sureforms' ),
		},
		{
			id: 'advanced-settings',
			title: __( 'Advanced Settings', 'sureforms' ),
		},
	];

	let singleSettings = applyFilters(
		'srfm.formSettings.singleSettings',
		singleFormSettingsComponents
	);

	// validate the singleSettings should only contain array of objects with id and title if it fails then reset to default singleSettings.
	if ( ! Array.isArray( singleSettings ) ) {
		singleSettings = singleFormSettingsComponents;
	}

	// Guarantee no empty slugs reach post_content on save.
	useEffect( () => {
		let wasSavingPost = false;

		const unsubscribePreSave = wp.data.subscribe( () => {
			const editorSelect = wp.data.select( 'core/editor' );
			const isSaving = editorSelect.isSavingPost?.();
			const isAutosave = editorSelect.isAutosavingPost?.();

			// Fire only on the leading edge of a manual (non-autosave) save.
			// Guard BEFORE dispatching — updateBlockAttributes is synchronous
			// and re-triggers subscribe listeners, causing infinite recursion
			// if wasSavingPost is still false.
			if ( isSaving && ! isAutosave && ! wasSavingPost ) {
				wasSavingPost = true;
				const { getBlocks } = wp.data.select( 'core/block-editor' );
				const { updateBlockAttributes } = wp.data.dispatch( 'core/block-editor' );
				prepareBlockSlugs( updateBlockAttributes, getBlocks() );
			} else {
				wasSavingPost = !! isSaving;
			}
		} );

		return unsubscribePreSave;
	}, [] );

	// Listen for form settings popup events to open the dialog
	useEffect( () => {
		const handleFormSettingsEvent = ( event ) => {
			const tabId = event.detail?.tabId;
			if ( tabId ) {
				setPopupTab( tabId );
				setOpen( true );
				prevMetaHash = btoa( JSON.stringify( sureformsKeys ) );
			}
		};

		// Add event listener
		window.addEventListener(
			'srfm-open-form-settings',
			handleFormSettingsEvent
		);

		// Cleanup event listener on unmount
		return () => {
			window.removeEventListener(
				'srfm-open-form-settings',
				handleFormSettingsEvent
			);
		};
	}, [ sureformsKeys ] );

	// Deep-link support: open a specific form-settings panel when the editor is
	// reached with ?srfm_focus=... — the dashboard "Finish setting up" card CTAs
	// use this so "Set where replies go" lands on the OttoKit (Automations)
	// screen, and "Edit the thank-you message" on Form Confirmation. The target
	// is read from `srfmDeepLinkFocus`, snapshotted at bundle-eval time because
	// the block editor strips the query arg from the URL before this effect runs.
	useEffect( () => {
		const tabByFocus = {
			ottokit: 'ottokit',
			thankyou: 'form_confirmation',
			notifications: 'email_notification',
		};
		const tabId = tabByFocus[ srfmDeepLinkFocus ];

		if ( ! tabId ) {
			return undefined;
		}

		// Open the form-settings dialog on the target tab via the same window
		// event the "Form Settings" popover uses. The editor mounts/remounts the
		// settings panels during initial load and the listener re-registers on
		// every meta change, so a single dispatch can miss it, and the Force UI
		// dialog's opacity fade-in can stall when toggled amid that churn. Poll
		// every 300ms: dispatch the open request until the dialog appears, then
		// stop dispatching and just hold the overlay visible.
		//
		// Once it has opened, halt the poll the moment `isOpen` goes back to false
		// — that is the user closing it (Esc / backdrop / ✕), which flips state on
		// this same live mount. A load-time remount instead tears this effect down
		// (clearing the interval) and re-runs it fresh on the new mount, so churn
		// still reopens while a deliberate close stays closed. Give up after the
		// load window if it never opened so the interval can't run indefinitely.
		let elapsed = 0;
		let opened = false;
		const ensureOpen = setInterval( () => {
			elapsed += 300;

			// Dialog is open on this mount: mark it opened and hold the overlay
			// painted (the fade-in can stall during load), then wait.
			if ( isOpenRef.current ) {
				opened = true;
				const panel = document.querySelector( '.srfm-dialog-panel' );
				const overlay = panel?.closest( '.fixed.inset-0' );
				if ( overlay ) {
					overlay.style.opacity = '1';
				}
				return;
			}

			// It was open and is now closed on this same live mount — the user
			// dismissed it (✕ / Esc / backdrop). Stop; do not reopen.
			if ( opened ) {
				clearInterval( ensureOpen );
				return;
			}

			// Not open yet — keep asking until the listener catches it.
			window.dispatchEvent(
				new CustomEvent( 'srfm-open-form-settings', {
					detail: { tabId },
				} )
			);

			if ( elapsed >= 12000 ) {
				clearInterval( ensureOpen );
			}
		}, 300 );

		return () => clearInterval( ensureOpen );
	}, [] );

	return (
		<>
			<SRFMAdvancedPanelBody
				title={ __( 'General', 'sureforms' ) }
				initialOpen={ true }
			>
				<ToggleControl
					label={ __( 'Use Labels as Placeholders', 'sureforms' ) }
					checked={ sureformsKeys._srfm_use_label_as_placeholder }
					onChange={ ( value ) => {
						updateMeta( '_srfm_use_label_as_placeholder', value );
					} }
				/>
				<p className="components-base-control__help">
					{ __(
						'Above setting will place the labels inside the fields as placeholders (where possible). This setting takes effect only on the live page, not in the editor preview.',
						'sureforms'
					) }
				</p>
			</SRFMAdvancedPanelBody>
			{ isPageBreak && (
				<SRFMAdvancedPanelBody
					title={ __( 'Page Break', 'sureforms' ) }
					initialOpen={ false }
				>
					<PageBreakSettings />
				</SRFMAdvancedPanelBody>
			) }

			<FormRestrictionProvider>
				<Dialog
					open={ isOpen }
					setOpen={ setOpen }
					close={ closeModal }
					sureformsKeys={ sureformsKeys }
					targetTab={ popupTab }
					setHasValidationErrors={ setHasValidationErrors }
				/>
			</FormRestrictionProvider>
		</>
	);
}

export default GeneralSettings;
