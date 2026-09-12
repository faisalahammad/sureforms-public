/**
 * Shared Page Break Settings controls.
 *
 * Reads and writes `_srfm_page_break_settings` post meta via the
 * 'core/editor' store. Renders the same control set whether mounted in the
 * Form Options document panel (GeneralSettings) or the Page Break block's
 * own Inspector Controls.
 */
import { __ } from '@wordpress/i18n';
import { useDispatch, useSelect } from '@wordpress/data';
import { SelectControl, ToggleControl } from '@wordpress/components';
import SRFMTextControl from '@Components/text-control';

const PageBreakSettings = () => {
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
			<ToggleControl
				label={ __( 'Auto-Advance to Next Step', 'sureforms' ) }
				help={ __(
					'Move to the next step automatically when a single-choice answer is selected. The last step always needs the Submit button.',
					'sureforms'
				) }
				checked={ !! pageBreakSettings?.auto_advance }
				onChange={ ( value ) =>
					updatePageBreakSettings( 'auto_advance', value )
				}
			/>
			{ pageBreakSettings?.auto_advance && (
				<ToggleControl
					label={ __( 'Hide Next Button', 'sureforms' ) }
					help={ __(
						'Hides the Next button while auto-advance is on. It stays reachable by keyboard, so people who navigate with a keyboard are not stranded.',
						'sureforms'
					) }
					checked={ !! pageBreakSettings?.auto_advance_hide_next }
					onChange={ ( value ) =>
						updatePageBreakSettings(
							'auto_advance_hide_next',
							value
						)
					}
				/>
			) }
		</>
	);
};

export default PageBreakSettings;
