/* global srfmNoticeResponse */
( function () {
	const notices = {
		'srfm-getting-started-notice': {
			primary: 'go_to_dashboard',
			snooze: 'maybe_later',
			dismiss: 'dismissed',
		},
		'srfm-plugin-review-notice': {
			primary: 'rate_sureforms',
			snooze: 'maybe_later',
			dismiss: 'dismissed',
		},
	};

	function getAction( el, noticeId ) {
		const config = notices[ noticeId ];
		if ( ! config ) {
			return null;
		}

		if (
			el.classList.contains( 'button-primary' ) ||
			( el.classList.contains( 'astra-notice-close' ) &&
				el.getAttribute( 'target' ) === '_blank' )
		) {
			return config.primary;
		}
		if ( el.hasAttribute( 'data-repeat-notice-after' ) ) {
			return config.snooze;
		}
		if ( el.classList.contains( 'astra-notice-close' ) ) {
			return config.dismiss;
		}
		return null;
	}

	function sendResponse( noticeId, button ) {
		const body = new FormData();
		body.append( 'action', 'srfm_notice_response' );
		body.append( 'nonce', srfmNoticeResponse.nonce );
		body.append( 'notice_id', noticeId );
		body.append( 'button', button );

		fetch( srfmNoticeResponse.ajaxurl, { method: 'POST', body } ).catch(
			() => {}
		);
	}

	// Action-item notices declare what to record on the element itself, so a new
	// one needs no entry in the map above. Delegated from the document because
	// these render on every admin screen, not inside one known container.
	document.addEventListener( 'click', function ( e ) {
		const link = e.target.closest( '[data-srfm-notice-id][data-srfm-button]' );
		if ( ! link ) {
			return;
		}

		const noticeId = link.getAttribute( 'data-srfm-notice-id' );
		const button = link.getAttribute( 'data-srfm-button' );

		if ( noticeId && button ) {
			sendResponse( noticeId, button );
		}
	} );

	Object.keys( notices ).forEach( function ( noticeId ) {
		const container = document.getElementById( noticeId );
		if ( ! container ) {
			return;
		}

		container.addEventListener( 'click', function ( e ) {
			const link = e.target.closest( 'a' );
			if ( ! link ) {
				return;
			}

			const action = getAction( link, noticeId );
			if ( action ) {
				sendResponse( noticeId, action );
			}
		} );
	} );

	// ------------------------------------------------------------------
	// Carousel for the action-item notices
	// ------------------------------------------------------------------

	/**
	 * Show SureForms' stacked admin notices one at a time.
	 *
	 * Four faults at once pushed the actual page below the fold on every admin
	 * screen, so the notices became the page. One at a time with a count keeps the
	 * warning visible without taking over.
	 *
	 * Built here rather than printed by PHP so that with JavaScript off every
	 * notice simply stays visible, exactly as before. Controls that cannot work
	 * must not be the thing that hides a warning.
	 *
	 * The controls are created once, in the wrapper, and never moved. Moving them
	 * into the newly shown card ran the DOM remove steps first, which unfocuses
	 * whatever they contain -- so every activation dropped focus to <body> and a
	 * keyboard user had to Tab in from the top of the page again, once per notice.
	 * It also carried the aria-live counter out of and back into the document with
	 * its text already set, which is generally not announced. Only one card is ever
	 * visible, so the wrapper's box is the visible card's box and pinning the
	 * controls to the wrapper looks identical.
	 */
	function buildNoticeCarousel() {
		const cards = Array.prototype.slice.call(
			document.querySelectorAll( '.srfm-action-item-notice' )
		);

		// One notice needs no chrome, and none needs nothing.
		if ( cards.length < 2 ) {
			return;
		}

		const labels =
			( typeof srfmNoticeResponse !== 'undefined' &&
				srfmNoticeResponse.carousel ) ||
			{};
		let index = 0;

		// Wrap in place, so the cards keep the position WordPress gave them
		// rather than being moved to the end of the screen.
		const wrap = document.createElement( 'div' );
		wrap.className = 'srfm-action-item-carousel';

		/**
		 * Put the cards inside the wrapper, wherever they currently are.
		 *
		 * WordPress core relocates every `.notice` into `div.wrap` on jQuery ready,
		 * and that runs after this file's DOMContentLoaded handler -- so wrapping
		 * once at build time left the wrapper behind, empty and zero-height, with
		 * the controls positioned against it off the side of the screen and the
		 * reserved padding matching nothing. Re-checking is cheap, only moves
		 * anything when something else has moved it, and also survives a plugin
		 * that relocates notices later.
		 */
		function adopt() {
			if (
				cards.every( function ( notice ) {
					return notice.parentNode === wrap;
				} )
			) {
				return;
			}

			cards[ 0 ].parentNode.insertBefore( wrap, cards[ 0 ] );
			cards.forEach( function ( notice ) {
				wrap.appendChild( notice );
			} );
		}

		adopt();

		// Positioning and spacing live in the stylesheet the renderer prints, so
		// an RTL sheet can override them and nothing here is a magic number.
		const nav = document.createElement( 'p' );
		nav.className = 'srfm-action-item-carousel-nav';

		const isRtl =
			document.documentElement.getAttribute( 'dir' ) === 'rtl' ||
			document.body.classList.contains( 'rtl' );

		const prev = document.createElement( 'button' );
		prev.type = 'button';
		prev.className = 'button button-small';
		// Previous points at the start of the reading order, which is the right in
		// an RTL locale. Hardcoding one direction contradicts the aria-label.
		prev.textContent = isRtl ? '\u203A' : '\u2039';
		prev.setAttribute( 'aria-label', labels.previous || 'Previous' );

		const next = document.createElement( 'button' );
		next.type = 'button';
		next.className = 'button button-small';
		next.textContent = isRtl ? '\u2039' : '\u203A';
		next.setAttribute( 'aria-label', labels.next || 'Next' );

		const counter = document.createElement( 'span' );
		// Announced, because stepping swaps the text above with no other signal.
		// The node stays put, so the region is in the document before its text
		// changes -- which is what makes the change announce at all.
		counter.setAttribute( 'aria-live', 'polite' );

		function render() {
			adopt();

			cards.forEach( function ( notice, i ) {
				notice.hidden = i !== index;
			} );

			counter.textContent = ( labels.counter || '%1$d of %2$d' )
				.replace( '%1$d', index + 1 )
				.replace( '%2$d', cards.length );
		}

		function step( delta ) {
			return function () {
				// Wraps, so a run of cards can be read round without hunting for
				// the end.
				index = ( index + delta + cards.length ) % cards.length;
				render();
			};
		}

		/**
		 * Reserve exactly the room the controls take, measured rather than assumed.
		 *
		 * A translated counter is wider than "1 of 4", and a fixed padding lets a
		 * long form title run underneath the buttons.
		 */
		function measure() {
			const width = Math.ceil( nav.getBoundingClientRect().width );

			if ( width > 0 ) {
				wrap.style.setProperty(
					'--srfm-carousel-reserve',
					width + 24 + 'px'
				);
			}
		}

		prev.addEventListener( 'click', step( -1 ) );
		next.addEventListener( 'click', step( 1 ) );

		nav.appendChild( prev );
		nav.appendChild( counter );
		nav.appendChild( next );
		wrap.appendChild( nav );

		render();
		measure();

		// Core has not finished moving notices when DOMContentLoaded handlers run,
		// so re-run once the queue has drained. render() re-adopts; this only has
		// to re-measure, because the controls have a box again by then.
		window.setTimeout( function () {
			render();
			measure();
		}, 0 );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', buildNoticeCarousel );
	} else {
		buildNoticeCarousel();
	}
}() );
