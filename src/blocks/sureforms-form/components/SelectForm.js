import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { useEffect, useRef, useState } from '@wordpress/element';

const SelectForm = ( {
	label,
	id,
	formId,
	selectedVal,
	handleChange,
	setFormId,
} ) => {
	const [ formsData, setFormsData ] = useState( [] );
	const [ query, setQuery ] = useState( '' );
	const [ isOpen, setIsOpen ] = useState( false );
	const inputRef = useRef( null );

	// Fetch forms data.
	const fetchForms = async () => {
		let response;
		try {
			// No explicit X-WP-Nonce: apiFetch's nonce middleware supplies the real
			// wp_rest nonce. srfm_block_data.forms_data_nonce was never localized, so
			// passing it sent the header as `undefined` and relied on the middleware
			// overwriting it.
			response = await apiFetch( {
				path: 'sureforms/v1/forms-data',
				headers: {
					'content-type': 'application/json',
				},
			} );
			// if in the response object title is '' then set title to '(no title)'
			response.forEach( ( form ) => {
				if ( form.title === '' ) {
					form.title = __( '(no title)', 'sureforms' );
				}
			} );
			setFormsData( response );
		} catch ( error ) {
			console.log( error );
		}
	};

	useEffect( () => {
		fetchForms();
		document.addEventListener( 'click', toggle );
		return () => document.removeEventListener( 'click', toggle );
	}, [] );

	const selectOption = ( option ) => {
		setQuery( () => '' );
		handleChange( option[ label ] );
		setIsOpen( () => ! isOpen );
	};

	function toggle( e ) {
		setIsOpen( e && e.target === inputRef.current );
	}

	const getDisplayValue = () => {
		if ( query ) {
			return query;
		}
		if ( selectedVal ) {
			return selectedVal;
		}
		return '';
	};

	const filter = () => {
		return formsData.filter(
			( option ) =>
				option[ label ].toLowerCase().indexOf( query.toLowerCase() ) >
				-1
		);
	};

	return (
		<div className="srfm-form-select-dropdown">
			<div>
				<div className="srfm-form-select-value">
					<input
						ref={ inputRef }
						type="text"
						value={ getDisplayValue() }
						name="searchTerm"
						onChange={ ( e ) => {
							setQuery( e.target.value );
							handleChange( null );
						} }
						onClick={ toggle }
						placeholder={ __( 'Select a Form', 'sureforms' ) }
						autoComplete="off"
					/>
				</div>
				<div
					className={ `srfm-form-select-arrow ${
						isOpen ? 'srfm-form-arrow-open' : ''
					}` }
				></div>
			</div>

			<div
				className={ `srfm-form-select-options ${
					isOpen ? 'srfm-form-arrow-open' : ''
				}` }
			>
				{ formsData.length === 0 || filter().length === 0 ? (
					<div className="srfm-form-single-option">
						{ __( 'No forms found…', 'sureforms' ) }
					</div>
				) : (
					filter( formsData ).map( ( option, index ) => (
						<div
							onClick={ () => {
								selectOption( option );
								setFormId( option.id );
							} }
							className={ `srfm-form-single-option ${
								option[ id ] === formId
									? 'srfm-form-selected'
									: ''
							}` }
							key={ `${ id }-${ index }` }
						>
							{ option[ label ] }
						</div>
					) )
				) }
			</div>
		</div>
	);
};

export default SelectForm;
