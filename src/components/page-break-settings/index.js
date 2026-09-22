/**
 * Shared Page Break Settings controls.
 *
 * Reads and writes `_srfm_page_break_settings` post meta via the
 * 'core/editor' store. Mounted both in the Form Options document panel
 * (GeneralSettings) and in the Page Break block's own Inspector Controls.
 *
 * Every control here writes one shared, form-level meta value, so the block
 * copy is a convenience duplicate of the form panel. `showAutoAdvance` lets a
 * caller leave the auto-advance pair out of its copy: they describe how the
 * whole form behaves rather than anything about the page break in front of
 * you, and repeating them on every page break reads as a per-break setting
 * that does not exist. Defaults to true so the form panel needs no argument.
 */
import { __ } from '@wordpress/i18n';
import { useDispatch, useSelect } from '@wordpress/data';
import { SelectControl, ToggleControl } from '@wordpress/components';
import SRFMTextControl from '@Components/text-control';

const PageBreakSettings = ( { showAutoAdvance = true } ) => {
	const pageBreakSettings = useSelect( ( select ) => {
		const meta =
			select( 'core/editor' ).getEditedPostAttribute( 'meta' );
		return meta?._srfm_page_break_settings || {};
	} );

	const { editPost } = useDispatch( 'core/editor' );

	function updatePageBreakSettings( option, value ) {
		editPost( {
			meta: {
				_srfm_page_break_settings: {
					...pageBreakSettings,
					[ option ]: value,
				},
			},
		} );
	}

	return (
		<>
			{ pageBreakSettings?.progress_indicator_type !== 'none' && (
				<>
					<ToggleControl
						label={ __( 'Show Labels', 'sureforms' ) }
						checked={ pageBreakSettings?.toggle_label }
						onChange={ ( value ) =>
							updatePageBreakSettings( 'toggle_label', value )
						}
					/>
					{ pageBreakSettings?.toggle_label && (
						<SRFMTextControl
							label={ __(
								'First Page Label',
								'sureforms'
							) }
							value={ pageBreakSettings?.first_page_label }
							data={ {
								value: pageBreakSettings?.first_page_label,
								label: 'first_page_label',
							} }
							onChange={ ( value ) =>
								updatePageBreakSettings(
									'first_page_label',
									value
								)
							}
							isFormSpecific={ true }
						/>
					) }
				</>
			) }
			<SelectControl
				__next40pxDefaultSize
				label={ __( 'Progress Indicator', 'sureforms' ) }
				value={ pageBreakSettings?.progress_indicator_type }
				className="srfm-progress-control"
				options={ [
					{
						label: __( 'None', 'sureforms' ),
						value: 'none',
					},
					{
						label: __( 'Progress Bar', 'sureforms' ),
						value: 'progress-bar',
					},
					{
						label: __( 'Connector', 'sureforms' ),
						value: 'connector',
					},
					{
						label: __( 'Steps', 'sureforms' ),
						value: 'steps',
					},
				] }
				onChange={ ( value ) =>
					updatePageBreakSettings(
						'progress_indicator_type',
						value
					)
				}
				__nextHasNoMarginBottom
			/>
			<SRFMTextControl
				data={ {
					value: pageBreakSettings?.next_button_text,
					label: 'next_button_text',
				} }
				label={ __( 'Next Button Text', 'sureforms' ) }
				value={ pageBreakSettings?.next_button_text }
				onChange={ ( value ) =>
					updatePageBreakSettings( 'next_button_text', value )
				}
				isFormSpecific={ true }
			/>
			<SRFMTextControl
				data={ {
					value: pageBreakSettings?.back_button_text,
					label: 'back_button_text',
				} }
				label={ __( 'Back Button Text', 'sureforms' ) }
				value={ pageBreakSettings?.back_button_text }
				onChange={ ( value ) =>
					updatePageBreakSettings( 'back_button_text', value )
				}
				isFormSpecific={ true }
			/>
			{ showAutoAdvance && (
				<>
					<ToggleControl
						label={ __( 'Auto-Advance to Next Step', 'sureforms' ) }
						checked={ !! pageBreakSettings?.auto_advance }
						onChange={ ( value ) =>
							updatePageBreakSettings( 'auto_advance', value )
						}
					/>
					{ /* Sibling paragraph rather than ToggleControl's `help` prop:
					that renders indented under the label, while every other
					described toggle in Form Options sits flush left. Matches
					_srfm_use_label_as_placeholder in GeneralSettings.js. */ }
					<p className="components-base-control__help">
						{ __(
							'Move to the next step automatically when a single-choice answer is selected. The last step always needs the Submit button.',
							'sureforms'
						) }
					</p>
					{ pageBreakSettings?.auto_advance && (
						<>
							<ToggleControl
								label={ __( 'Hide Next Button', 'sureforms' ) }
								checked={
									!! pageBreakSettings?.auto_advance_hide_next
								}
								onChange={ ( value ) =>
									updatePageBreakSettings(
										'auto_advance_hide_next',
										value
									)
								}
							/>
							<p className="components-base-control__help">
								{ __(
									'Hides the Next button while auto-advance is on. It stays reachable by keyboard, so people who navigate with a keyboard are not stranded.',
									'sureforms'
								) }
							</p>
						</>
					) }
				</>
			) }
		</>
	);
};

export default PageBreakSettings;
