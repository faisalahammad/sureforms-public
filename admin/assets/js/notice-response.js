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
	 */
	function buildNoticeCarousel() {
		const cards = Array.prototype.slice.call(
			document.querySelectorAll( '.srfm-action-item-notice' )
		);

		// One notice needs no chrome, and none needs nothing.
		if ( cards.length < 2 ) {
			return;
		}

		const labels = ( srfmNoticeResponse && srfmNoticeResponse.carousel ) || {};
		let index = 0;

		// Wrap in place, so the cards keep the position WordPress gave them
		// rather than being moved to the end of the screen.
		const wrap = document.createElement( 'div' );
		wrap.className = 'srfm-action-item-carousel';
		cards[ 0 ].parentNode.insertBefore( wrap, cards[ 0 ] );
		cards.forEach( function ( notice ) {
			wrap.appendChild( notice );
		} );

		// Pinned to the top right of the notice being read. Absolute rather than a
		// float so it cannot reflow the message text, and the notice gets padding on
		// that side to reserve the space -- a long form title would otherwise run
		// underneath the controls.
		const nav = document.createElement( 'p' );
		nav.className = 'srfm-action-item-carousel-nav';
		nav.style.display = 'flex';
		nav.style.alignItems = 'center';
		nav.style.gap = '8px';
		nav.style.position = 'absolute';
		nav.style.top = '8px';
		nav.style.right = '12px';
		nav.style.margin = '0';

		cards.forEach( function ( notice ) {
			notice.style.position = 'relative';
			notice.style.paddingRight = '130px';
		} );

		const prev = document.createElement( 'button' );
		prev.type = 'button';
		prev.className = 'button button-small';
		prev.innerHTML = '&lsaquo;';
		prev.setAttribute( 'aria-label', labels.previous || 'Previous' );

		const next = document.createElement( 'button' );
		next.type = 'button';
		next.className = 'button button-small';
		next.innerHTML = '&rsaquo;';
		next.setAttribute( 'aria-label', labels.next || 'Next' );

		const counter = document.createElement( 'span' );
		// Announced, because stepping swaps the text above with no other signal.
		counter.setAttribute( 'aria-live', 'polite' );

		function render() {
			cards.forEach( function ( notice, i ) {
				notice.style.display = i === index ? '' : 'none';
			} );

			// Moved rather than duplicated: one set of controls, always inside the
			// notice being read, so the count sits with the message it counts.
			cards[ index ].appendChild( nav );

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

		prev.addEventListener( 'click', step( -1 ) );
		next.addEventListener( 'click', step( 1 ) );

		nav.appendChild( prev );
		nav.appendChild( counter );
		nav.appendChild( next );

		render();
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', buildNoticeCarousel );
	} else {
		buildNoticeCarousel();
	}

	// ------------------------------------------------------------------
	// Details modal
	// ------------------------------------------------------------------

	/**
	 * The details as HTML, for the clipboard's text/html flavour.
	 *
	 * Gmail's composer is a rich-text field: it drops the newlines out of plain
	 * text, which is what turned the diagnostics into one paragraph. Pasting HTML
	 * instead keeps every break, and <pre> keeps the log's columns lined up.
	 *
	 * Escaped here, not on the server, so the escaping happens once and in the same
	 * place the markup is built. The source is a log holding whatever a server put
	 * in an error message, so it is never trusted as markup.
	 *
	 * @param {string} text Plain-text details.
	 * @return {string} Escaped HTML.
	 */
	function detailsAsHtml( text ) {
		const escaped = text
			.replace( /&/g, '&amp;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' );

		return (
			'<pre style="font-family:monospace;white-space:pre-wrap;' +
			'word-break:break-word;margin:0">' +
			escaped +
			'</pre>'
		);
	}

	/**
	 * Put the details on the clipboard in both flavours.
	 *
	 * A rich composer takes the HTML and keeps the line breaks; a plain-text field
	 * takes the text. Falls back to writeText where ClipboardItem is unavailable --
	 * that loses the formatting, but losing it is better than copying nothing.
	 *
	 * @param {string}   text Plain-text details.
	 * @param {Function} done Called once the clipboard actually holds it.
	 */
	function copyDetails( text, done ) {
		const supportsRich =
			window.ClipboardItem &&
			navigator.clipboard &&
			navigator.clipboard.write;

		if ( supportsRich ) {
			const item = new window.ClipboardItem( {
				'text/plain': new Blob( [ text ], { type: 'text/plain' } ),
				'text/html': new Blob( [ detailsAsHtml( text ) ], {
					type: 'text/html',
				} ),
			} );

			navigator.clipboard.write( [ item ] ).then( done, function () {
				// Refused (insecure origin, permission policy). The text is on
				// screen and selectable, so say nothing rather than claim success.
			} );
			return;
		}

		if ( navigator.clipboard && navigator.clipboard.writeText ) {
			navigator.clipboard.writeText( text ).then( done, function () {} );
		}
	}

	/**
	 * Show what would be sent to support, before anything is sent.
	 *
	 * The text is already in the page, hidden next to its notice, so opening this
	 * makes no request -- a modal that has to fetch can fail at the exact moment
	 * someone is trying to report a failure.
	 *
	 * Read with textContent and written with textContent, never innerHTML: the log
	 * contains whatever a server or a browser put in an error message, and that is
	 * not markup to be trusted.
	 *
	 * @param {string} noticeId Item id, matched against the hidden payload's
	 *                          data-srfm-details-id.
	 * @return {boolean} Whether the payload was found and the modal opened.
	 */
	function openDetails( noticeId ) {
		const source = document.querySelector(
			'.srfm-notice-details[data-srfm-details-id="' + noticeId + '"]'
		);

		if ( ! source ) {
			return false;
		}

		const labels = ( srfmNoticeResponse && srfmNoticeResponse.details ) || {};
		const text = source.textContent || '';
		const supportUrl = source.getAttribute( 'data-srfm-support-url' ) || '';

		const overlay = document.createElement( 'div' );
		overlay.style.cssText =
			'position:fixed;inset:0;z-index:100000;display:flex;align-items:center;' +
			'justify-content:center;background:rgba(0,0,0,.5);padding:16px;';

		const panel = document.createElement( 'div' );
		panel.setAttribute( 'role', 'dialog' );
		panel.setAttribute( 'aria-modal', 'true' );
		panel.setAttribute( 'aria-label', labels.title || 'Details' );
		panel.style.cssText =
			'background:#fff;border-radius:8px;padding:16px;width:100%;' +
			'max-width:720px;box-shadow:0 10px 30px rgba(0,0,0,.2);';

		const heading = document.createElement( 'h2' );
		heading.textContent = labels.title || 'Details';
		heading.style.cssText = 'margin:0 0 4px;font-size:14px;';

		const description = document.createElement( 'p' );
		description.textContent = labels.description || '';
		description.style.cssText = 'margin:0 0 12px;color:#50575e;';

		// Selectable and scrollable, because clipboard access can be refused and
		// then selecting by hand is the only way through.
		const pre = document.createElement( 'pre' );
		pre.textContent = text;
		pre.style.cssText =
			'margin:0;max-height:320px;overflow:auto;white-space:pre-wrap;' +
			'word-break:break-word;background:#f6f7f7;padding:12px;' +
			'border-radius:6px;font-size:12px;';

		const actions = document.createElement( 'p' );
		actions.style.cssText =
			'display:flex;gap:8px;justify-content:flex-end;margin:12px 0 0;';

		const copy = document.createElement( 'button' );
		copy.type = 'button';
		copy.className = 'button';
		copy.textContent = labels.copy || 'Copy details';

		// Locked until the details are on the clipboard. The support form asks for
		// them, and arriving with nothing to paste means describing the failure from
		// memory.
		//
		// No href while it is locked, and a click guard behind that: `disabled` on
		// an <a> does nothing at all -- it still navigates -- so removing the
		// destination is what actually locks it.
		const contact = document.createElement( 'a' );
		contact.className = 'button button-primary';
		contact.target = '_blank';
		contact.rel = 'noopener noreferrer';
		contact.textContent = labels.contact || 'Contact Support';
		contact.setAttribute( 'aria-disabled', 'true' );
		contact.title = labels.copyFirst || '';
		contact.style.opacity = '0.6';
		contact.style.pointerEvents = 'none';

		function unlockContact() {
			contact.href = supportUrl;
			contact.removeAttribute( 'aria-disabled' );
			contact.removeAttribute( 'title' );
			contact.style.opacity = '';
			contact.style.pointerEvents = '';
		}

		const close = document.createElement( 'button' );
		close.type = 'button';
		close.className = 'button-link';
		close.textContent = labels.close || 'Close';
		close.setAttribute( 'aria-label', labels.close || 'Close' );

		function dismiss() {
			document.removeEventListener( 'keydown', onKey );
			overlay.remove();
		}

		function onKey( e ) {
			if ( e.key === 'Escape' ) {
				dismiss();
			}
		}

		copy.addEventListener( 'click', function () {
			copyDetails( text, function () {
				copy.textContent = labels.copied || 'Copied';
				// Reverts on its own: a button stuck on "Copied" says nothing about
				// the next click. The unlock does not revert with it -- having the
				// clipboard stays true after the label has gone back.
				window.setTimeout( function () {
					copy.textContent = labels.copy || 'Copy details';
				}, 2000 );
				// Only on success. A refused clipboard leaves the form locked rather
				// than sending someone to it with nothing to paste.
				unlockContact();
				sendResponse( noticeId, 'copy_details' );
			} );
		} );

		contact.addEventListener( 'click', function () {
			sendResponse( noticeId, 'contact_support' );
			// The form opens in its own tab, so the dialog has nothing left to show.
			dismiss();
		} );

		close.addEventListener( 'click', dismiss );
		overlay.addEventListener( 'click', function ( e ) {
			// Backdrop only: a click inside the panel must not close it while
			// someone is selecting the text.
			if ( e.target === overlay ) {
				dismiss();
			}
		} );
		document.addEventListener( 'keydown', onKey );

		actions.appendChild( close );
		actions.appendChild( copy );
		actions.appendChild( contact );
		panel.appendChild( heading );
		panel.appendChild( description );
		panel.appendChild( pre );
		panel.appendChild( actions );
		overlay.appendChild( panel );
		document.body.appendChild( overlay );

		copy.focus();

		return true;
	}

	document.addEventListener( 'click', function ( e ) {
		const trigger = e.target.closest( '[data-srfm-details-for]' );

		if ( ! trigger ) {
			return;
		}

		// Only swallow the navigation if the modal actually opened. If the payload
		// is missing the href still goes to the dashboard, which is where the same
		// details are readable.
		if ( openDetails( trigger.getAttribute( 'data-srfm-details-for' ) ) ) {
			e.preventDefault();
		}
	} );
}() );
