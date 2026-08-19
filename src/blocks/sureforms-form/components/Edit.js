/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { useSelect } from '@wordpress/data';
import { useRef, useEffect, useState, useCallback } from '@wordpress/element';
import {
	Placeholder,
	TextControl,
	PanelBody,
	PanelRow,
	Spinner,
	Button,
	ToggleControl,
	SelectControl,
	ExternalLink,
} from '@wordpress/components';
import { useEntityProp, store as coreStore } from '@wordpress/core-data';
import {
	InspectorControls,
	useBlockProps,
	Warning,
} from '@wordpress/block-editor';
import { applyFilters } from '@wordpress/hooks';

/**
 * Internal dependencies
 */
import {
	getColorControls,
	getLayoutControls,
	getFieldControls,
	getButtonControls,
} from './panel-controls';
import UpgradeModal from '@Components/upgrade-modal';

export default ( { attributes, setAttributes, clientId } ) => {
	const { id, showTitle, formTheme, blockId } = attributes;

	const iframeRef = useRef( null );
	const iframeContainerRef = useRef( null );
	const [ loading, setLoading ] = useState( false );
	const [ formIframeHeight, setFormIframeHeight ] = useState( 0 );
	const [ showUpgradeModal, setShowUpgradeModal ] = useState( false );

	// Generate unique blockId if not set (first render).
	useEffect( () => {
		if ( ! blockId ) {
			setAttributes( { blockId: clientId.substring( 0, 8 ) } );
		}
	}, [ blockId, clientId, setAttributes ] );

	// Check if Pro is active by looking for Pro-specific localized data.
	const isProActive = Boolean( window.srfm_block_data?.is_pro_active );

	// eslint-disable-next-line no-unused-vars
	const [ formUrl, setFormUrl ] = useEntityProp(
		'postType',
		'sureforms_form',
		'link',
		id
	);

	const blockProps = useBlockProps();

	const [ title, setTitle ] = useEntityProp(
		'postType',
		'sureforms_form',
		'title',
		id
	);

	const status = useSelect(
		( select ) => {
			const record = select( coreStore ).getEntityRecord(
				'postType',
				'sureforms_form',
				id
			);
			return record ? record.status : undefined;
		},
		[ id ]
	);

	const { isMissing, hasResolved, isInlineButton } = useSelect(
		( select ) => {
			const hasResolvedValue = select( coreStore ).hasFinishedResolution(
				'getEntityRecord',
				[ 'postType', 'sureforms_form', id ]
			);
			const form = select( coreStore ).getEntityRecord(
				'postType',
				'sureforms_form',
				id
			);
			// canUserEditEntityRecord() is deprecated since WP 6.7 (it warns in the
			// console on 7.1) and took ( kind, name, recordId ) — the single
			// 'sureforms_form' argument was landing in `kind`.
			const canEdit = select( coreStore ).canUser( 'update', {
				kind: 'postType',
				name: 'sureforms_form',
				id,
			} );
			return {
				canEdit,
				isMissing: hasResolvedValue && ! form,
				hasResolved: hasResolvedValue,
				isInlineButton: Boolean( form?.meta?._srfm_is_inline_button ),
				form,
			};
		},
		[ id ]
	);

	/**
	 * Read the preview document, when the browser lets us.
	 *
	 * Returns null instead of throwing: from WP 7.1 the editor canvas is a
	 * cross-origin-isolated document, so this iframe is treated as cross-origin
	 * and both `contentDocument` and any property access on it are unavailable.
	 *
	 * @return {Document|null} The preview document, or null when unreachable.
	 */
	const getPreviewDocument = () => {
		try {
			return iframeRef.current?.contentDocument ?? null;
		} catch ( error ) {
			// Cross-origin (or isolated) preview — nothing readable here.
			return null;
		}
	};

	// Sync the iframe height from the preview document, where that is readable.
	const syncHeightFromDocument = () => {
		const iframeDocument = getPreviewDocument();

		if ( ! iframeDocument ) {
			return;
		}

		const formOuterContainerSelector = iframeDocument.querySelector(
			'.srfm-form-container'
		);

		if ( formOuterContainerSelector ) {
			const getHeight = formOuterContainerSelector.offsetHeight;

			if ( getHeight && 0 !== getHeight ) {
				// set height of iframe if form is not empty.
				setFormIframeHeight( getHeight );
				iframeRef.current.height = getHeight;
			}
		}
	};

	// Remove unwanted elements from the iframe and add styling for the form
	const modifyIframeContent = () => {
		// Clear the loader first, unconditionally. Height measurement below is
		// best-effort — when the preview document is unreadable (WP 7.1's isolated
		// canvas) the height instead arrives over the srfm-preview-height message.
		// Clearing last, behind that guard, is what left the spinner up forever.
		setLoading( false );

		syncHeightFromDocument();
	};

	useEffect( () => {
		// Ensure the iframe container reference is valid
		if ( ! iframeContainerRef?.current ) {
			return;
		}

		const formElement = iframeContainerRef?.current; // Reference to the element being observed

		const options = {
			root: null, // Observe within the viewport
			rootMargin: '0px', // No additional margins
			threshold: 0.1, // Trigger when 10% of the element is visible
		};

		const observerCallback = ( entries ) => {
			entries.forEach( ( entry ) => {
				// Check if form is visible and iframe height hasn't been set.
				// syncHeightFromDocument() no-ops when the preview document is
				// unreachable; reading `.contentDocument.querySelector()` directly
				// threw a TypeError there.
				if ( ! formIframeHeight && entry.isIntersecting ) {
					syncHeightFromDocument();
				}
			} );
		};

		// Initialize Intersection Observer
		const observer = new IntersectionObserver( observerCallback, options );

		// Start observing the form element
		observer.observe( formElement );

		// Clean up the observer when the component unmounts or dependencies change
		return () => observer.disconnect();
	}, [] );

	useEffect( () => {
		if ( ! iframeRef?.current ) {
			return;
		}

		setLoading( true );

		iframeRef.current.onload = () => {
			modifyIframeContent();
		};

		// The load event can be missed entirely: the iframe is `loading="eager"`
		// so it may already have loaded before this effect runs, and a preview
		// blocked by the embedder's isolation policy never fires load at all.
		// Without this the loader would stay up for the rest of the session.
		const loaderFallback = setTimeout( () => {
			setLoading( false );
			syncHeightFromDocument();
		}, 3000 );

		return () => clearTimeout( loaderFallback );
	}, [ id, iframeRef, hasResolved, attributes.formTheme ] );

	// Height over postMessage — the only channel that survives a cross-origin
	// preview (WP 7.1's isolated editor canvas), where the document cannot be
	// measured from here. The preview page posts its own height on load/resize.
	// Identity is checked by comparing the event source against this iframe's
	// contentWindow: a reference comparison needs no cross-origin access, and is
	// stricter than an origin check when the embedder origin is opaque.
	useEffect( () => {
		const onPreviewMessage = ( event ) => {
			if (
				'srfm-preview-height' !== event.data?.type ||
				event.source !== iframeRef.current?.contentWindow
			) {
				return;
			}

			const height = Number( event.data.height );

			if ( ! Number.isFinite( height ) || height <= 0 ) {
				return;
			}

			setLoading( false );
			setFormIframeHeight( height );

			if ( iframeRef.current ) {
				iframeRef.current.height = height;
			}
		};

		window.addEventListener( 'message', onPreviewMessage );
		return () => window.removeEventListener( 'message', onPreviewMessage );
	}, [] );

	// Send styling updates to iframe via PostMessage
	const sendStylingToIframe = useCallback( () => {
		if ( ! iframeRef.current?.contentWindow || ! formUrl ) {
			return;
		}

		const iframeOrigin = new URL( formUrl ).origin;

		// If inheriting styling, send reset message to reload iframe with original styles
		if ( 'inherit' === attributes.formTheme ) {
			iframeRef.current.contentWindow.postMessage(
				{
					type: 'srfm-reset-styling',
				},
				iframeOrigin
			);
			return;
		}

		// Base styling object for free version
		const baseStyling = {
			formTheme: attributes.formTheme,
			// Colors
			primaryColor: attributes.primaryColor,
			textColor: attributes.textColor,
			textOnPrimaryColor: attributes.textOnPrimaryColor,
			// Background
			bgType: attributes.bgType,
			bgColor: attributes.bgColor,
			bgGradient: attributes.bgGradient,
			bgImage: attributes.bgImage,
			bgImagePosition: attributes.bgImagePosition,
			bgImageSize: attributes.bgImageSize,
			bgImageRepeat: attributes.bgImageRepeat,
			bgImageAttachment: attributes.bgImageAttachment,
			// Layout
			formPaddingTop: attributes.formPaddingTop,
			formPaddingRight: attributes.formPaddingRight,
			formPaddingBottom: attributes.formPaddingBottom,
			formPaddingLeft: attributes.formPaddingLeft,
			formPaddingUnit: attributes.formPaddingUnit,
			formBorderRadiusTop: attributes.formBorderRadiusTop,
			formBorderRadiusRight: attributes.formBorderRadiusRight,
			formBorderRadiusBottom: attributes.formBorderRadiusBottom,
			formBorderRadiusLeft: attributes.formBorderRadiusLeft,
			formBorderRadiusUnit: attributes.formBorderRadiusUnit,
			// Fields
			fieldSpacing: attributes.fieldSpacing,
			// Button
			buttonAlignment: attributes.buttonAlignment,
		};

		// Allow Pro to extend the styling object
		const styling = applyFilters(
			'srfm.embed.previewStyling',
			baseStyling,
			attributes
		);

		iframeRef.current.contentWindow.postMessage(
			{
				type: 'srfm-update-styling',
				styling,
			},
			iframeOrigin
		);
	}, [ attributes, formUrl ] );

	// Trigger styling update when attributes change
	useEffect( () => {
		sendStylingToIframe();
	}, [ sendStylingToIframe ] );

	// Re-send styling after iframe loads
	useEffect( () => {
		if ( ! loading && iframeRef.current?.contentWindow ) {
			const timer = setTimeout( () => {
				sendStylingToIframe();
			}, 100 );
			return () => clearTimeout( timer );
		}
	}, [ loading, sendStylingToIframe ] );

	// Handle image selection for background
	const onSelectImage = ( label, media ) => {
		if ( ! media || ! media.url ) {
			setAttributes( { [ label ]: '' } );
			return;
		}
		setAttributes( {
			[ label ]: media.url,
			bgImageId: media.id,
		} );
	};

	// Get panel controls from separate file
	// Pro can filter these arrays to add, remove, or modify controls
	const colorControls = getColorControls( {
		attributes,
		setAttributes,
		onSelectImage,
	} );
	const layoutControls = getLayoutControls( { attributes, setAttributes } );
	const fieldControls = getFieldControls( { attributes, setAttributes } );
	const allButtonControls = getButtonControls( {
		attributes,
		setAttributes,
	} );

	// Hide button alignment when the form uses a custom (inline) button —
	// the inline button manages its own layout. Keep other button styling
	// controls (added by Pro) so they still apply in custom theme mode.
	const buttonControls = isInlineButton
		? allButtonControls.filter(
			( control ) => control.id !== 'buttonAlignment'
		  )
		: allButtonControls;

	// If the form is not published or is missing, show a warning and allow the user to change the form.
	if ( isMissing || ( status && 'publish' !== status ) ) {
		return (
			<>
				<InspectorControls>
					<PanelBody>
						<PanelRow>
							<Button
								variant="secondary"
								text={ __( 'Change Form', 'sureforms' ) }
								onClick={ () => {
									setAttributes( { id: undefined } );
								} }
								className="srfm-change-form-btn"
							/>
						</PanelRow>
					</PanelBody>
				</InspectorControls>
				<div { ...blockProps }>
					<Warning>
						{ __(
							'This form has been deleted or is unavailable.',
							'sureforms'
						) }
					</Warning>
				</div>
			</>
		);
	}

	return (
		<>
			<InspectorControls>
				{ /* 1. Form Settings (General) */ }
				<PanelBody title={ __( 'Form Settings', 'sureforms' ) }>
					<PanelRow>
						<ToggleControl
							label={ __(
								'Show Form Title on this Page',
								'sureforms'
							) }
							checked={ showTitle }
							onChange={ ( value ) => {
								setAttributes( { showTitle: value } );
							} }
							className="srfm-form-page-title-toggle"
						/>
					</PanelRow>
					{ showTitle && (
						<PanelRow>
							<TextControl
								__next40pxDefaultSize
								label={ __( 'Form Title', 'sureforms' ) }
								value={ title }
								onChange={ ( value ) => {
									setTitle( value );
								} }
								className="srfm-form-page-title-input-wrapper"
							/>
						</PanelRow>
					) }
					<SelectControl
						__next40pxDefaultSize
						label={ __( 'Form Theme', 'sureforms' ) }
						value={ formTheme }
						options={ applyFilters( 'srfm.embed.formThemeOptions', [
							{
								label: __(
									"Inherit Form's Original Style",
									'sureforms'
								),
								value: 'inherit',
							},
							{
								label: __( 'Default', 'sureforms' ),
								value: 'default',
							},
							{
								label: __( 'Custom (Premium)', 'sureforms' ),
								value: 'custom',
							},
						] ) }
						onChange={ ( value ) => {
							// If Custom is selected and Pro is not active, show upgrade modal.
							if ( 'custom' === value && ! isProActive ) {
								setShowUpgradeModal( true );
								return;
							}
							setAttributes( { formTheme: value } );
						} }
						help={ __(
							'Select a theme style for this form embed.',
							'sureforms'
						) }
					/>
					{ srfm_block_data.is_admin_user && (
						<PanelRow>
							<p className="srfm-form-notice">
								{ __(
									'Note: For editing SureForms, please refer to the SureForms Editor - ',
									'sureforms'
								) }
								<ExternalLink
									href={ `${ srfm_block_data.post_url }?post=${ id }&action=edit` }
								>
									{ __( 'Edit Form', 'sureforms' ) }
								</ExternalLink>
							</p>
						</PanelRow>
					) }
					<PanelRow>
						<Button
							variant="secondary"
							text={ __( 'Change Form', 'sureforms' ) }
							onClick={ () => {
								setAttributes( { id: undefined } );
							} }
							className="srfm-change-form-btn"
						/>
					</PanelRow>
				</PanelBody>

				{ /* Styling panels - only show when not inheriting */ }
				{ 'inherit' !== formTheme && (
					<>
						{ /* 2. Colors */ }
						<PanelBody
							title={ __( 'Colors', 'sureforms' ) }
							initialOpen={ false }
						>
							{ colorControls.map( ( control ) => (
								<div
									className="components-base-control"
									key={ control.id }
								>
									{ control.component }
								</div>
							) ) }
						</PanelBody>

						{ /* 3. Layout */ }
						<PanelBody
							title={ __( 'Layout', 'sureforms' ) }
							initialOpen={ false }
							className="srfm-layout-panel"
						>
							{ layoutControls.map( ( control ) => (
								<div
									className="components-base-control"
									key={ control.id }
								>
									{ control.component }
								</div>
							) ) }
						</PanelBody>

						{ /* 4. Fields */ }
						<PanelBody
							title={ __( 'Fields', 'sureforms' ) }
							initialOpen={ false }
						>
							{ fieldControls.map( ( control ) => (
								<div
									className="components-base-control"
									key={ control.id }
								>
									{ control.component }
								</div>
							) ) }
						</PanelBody>

						{ /* 5. Button — hidden when no controls remain (e.g. default theme + inline button) */ }
						{ buttonControls.length > 0 && (
							<PanelBody
								title={ __( 'Button', 'sureforms' ) }
								initialOpen={ false }
							>
								{ buttonControls.map( ( control ) => (
									<div
										className="components-base-control"
										key={ control.id }
									>
										{ control.component }
									</div>
								) ) }
							</PanelBody>
						) }

						{ /* Pro can add additional panels via filter */ }
						{ applyFilters( 'srfm.embed.additionalPanels', null, {
							attributes,
							setAttributes,
						} ) }
					</>
				) }
			</InspectorControls>
			{ hasResolved ? (
				<div { ...blockProps }>
					<div
						className="srfm-iframe-container"
						ref={ iframeContainerRef }
					>
						{ loading && (
							<div className="srfm-iframe-loader">
								<Spinner />
							</div>
						) }
						{ showTitle && title && (
							<h2 className="srfm-form-title">{ title }</h2>
						) }
						<iframe
							loading={ 'eager' }
							ref={ iframeRef }
							/*
							 * From WP 7.1 the editor is cross-origin isolated
							 * (COEP). A framed document that does not opt in is
							 * blocked outright, which is why the preview never
							 * loaded. `credentialless` lets an isolated document
							 * embed it; browsers without support ignore it. Safe
							 * here because this iframe only ever renders published
							 * forms (see the isMissing / status guard above), so
							 * the preview needs no cookies. Declared in JSX rather
							 * than via setAttribute because it only takes effect if
							 * present before the frame starts loading.
							 */
							// eslint-disable-next-line react/no-unknown-property -- Valid HTML attribute React has no entry for; required at element creation.
							credentialless="true"
							title="srfm-iframe"
							src={
								formUrl +
								`?preview_id=${ id }&preview=true&form_preview=true`
							}
							width={ '100%' }
						/>
					</div>
				</div>
			) : (
				<div { ...blockProps }>
					<Placeholder>
						<Spinner />
					</Placeholder>
				</div>
			) }
			<UpgradeModal
				isOpen={ showUpgradeModal }
				onClose={ () => setShowUpgradeModal( false ) }
				title={ __( 'Advanced Styling', 'sureforms' ) }
				heading={ __( 'Unlock Custom Styling', 'sureforms' ) }
				description={ __(
					"Switch to Custom Mode to take full control of your form's design and spacing.",
					'sureforms'
				) }
				features={ [
					__(
						'Full color control (buttons, fields, text)',
						'sureforms'
					),
					__( 'Row and column gap control', 'sureforms' ),
					__( 'Field spacing and layout precision', 'sureforms' ),
					__( 'Complete button styling', 'sureforms' ),
				] }
			/>
		</>
	);
};
