import { useState, useCallback, useEffect } from '@wordpress/element';
import { useSearchParams } from 'react-router-dom';

/**
 * Custom hook for managing entries sorting state with URL synchronization
 *
 * @param {string} initialSortBy - Initial sort column (default: '')
 * @param {string} initialOrder  - Initial sort order (default: '')
 * @return {Object} Sort state and handlers
 */
/**
 * Map frontend column keys to backend API field names.
 *
 * Module scope: this is static, and keeping it inside the hook re-created it every
 * render while being closed over by handleSort/getSortDirection without appearing in
 * their dependency arrays.
 */
const COLUMN_TO_API_FIELD = {
	id: 'id',
	status: 'status',
	dateTime: 'created_at',
};

const ALLOWED_SORT_BY = Object.keys( COLUMN_TO_API_FIELD );

export const useEntriesSort = ( initialSortBy = '', initialOrder = '' ) => {
	const [ searchParams, setSearchParams ] = useSearchParams();

	// Initialize state from URL params.
	// Allowlist the value: users who sorted by Language in 2.11-2.12.2 still have
	// ?sortBy=language in their history, and that column no longer exists in either
	// COLUMN_TO_API_FIELD or the REST orderby enum — so passing it through failed REST
	// validation and errored the whole list with no header left to click to recover.
	const [ sortBy, setSortBy ] = useState( () => {
		const fromUrl = searchParams.get( 'sortBy' ) || initialSortBy;
		return ALLOWED_SORT_BY.includes( fromUrl ) ? fromUrl : initialSortBy;
	} );
	const [ order, setOrder ] = useState(
		searchParams.get( 'order' ) || initialOrder
	);

	// Update URL params when sort changes
	useEffect( () => {
		const params = new URLSearchParams( searchParams );

		if ( sortBy ) {
			params.set( 'sortBy', sortBy );
		} else {
			params.delete( 'sortBy' );
		}

		if ( order ) {
			params.set( 'order', order );
		} else {
			params.delete( 'order' );
		}

		setSearchParams( params, { replace: true } );
	}, [ sortBy, order, searchParams, setSearchParams ] );

	/**
	 * Handle sort column change
	 * Toggle order if same column, otherwise set to DESC
	 *
	 * @param {string} columnKey - Column key to sort by
	 */
	const handleSort = useCallback(
		( columnKey ) => {
			const apiField = COLUMN_TO_API_FIELD[ columnKey ];

			if ( ! apiField ) {
				return;
			}

			// If clicking the same column, toggle the order
			if ( sortBy === apiField ) {
				setOrder( ( prevOrder ) =>
					prevOrder === 'DESC' ? 'ASC' : 'DESC'
				);
			} else {
				// New column, set it and default to DESC
				setSortBy( apiField );
				setOrder( 'DESC' );
			}
		},
		[ sortBy ]
	);

	/**
	 * Get the sort direction for a specific column
	 *
	 * @param {string} columnKey - Column key to check
	 * @return {string|null} 'asc', 'desc', or null if not sorted
	 */
	const getSortDirection = useCallback(
		( columnKey ) => {
			const apiField = COLUMN_TO_API_FIELD[ columnKey ];
			if ( sortBy === apiField ) {
				return order.toLowerCase();
			}
			return null;
		},
		[ sortBy, order ]
	);

	return {
		sortBy,
		order,
		handleSort,
		getSortDirection,
	};
};
