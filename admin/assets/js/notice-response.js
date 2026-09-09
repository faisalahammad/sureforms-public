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
		cards[ 0 ].parentNode.insertBefore( wrap, cards[ 0 ] );
		cards.forEach( function ( notice ) {
			wrap.appendChild( notice );
		} );

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

		prev.addEventListener( 'click', step( -1 ) );
		next.addEventListener( 'click', step( 1 ) );

		nav.appendChild( prev );
		nav.appendChild( counter );
		nav.appendChild( next );
		wrap.appendChild( nav );

		render();

		// Reserve exactly the room the controls take, measured rather than assumed:
		// a translated counter is wider than "1 of 4" and a fixed padding lets a
		// long form title run underneath the buttons.
		wrap.style.setProperty(
			'--srfm-carousel-reserve',
			Math.ceil( nav.getBoundingClientRect().width ) + 24 + 'px'
		);
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
	 * @param {Function} fail Called when it does not, including where the API is
	 *                        absent entirely -- on a plain-HTTP admin
	 *                        navigator.clipboard does not exist, and a silent
	 *                        no-op there is what left the dialog with a button
	 *                        waiting on something that could never happen.
	 */
	function copyDetails( text, done, fail ) {
		const nope =
			typeof fail === 'function'
				? fail
				: function () {};

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

			navigator.clipboard.write( [ item ] ).then( done, nope );
			return;
		}

		if ( navigator.clipboard && navigator.clipboard.writeText ) {
			navigator.clipboard.writeText( text ).then( done, nope );
			return;
		}

		// No clipboard API at all. The text is on screen and selectable, so there
		// is nothing to recover -- but the caller has to be told, or it waits for a
		// callback that never arrives.
		nope();
	}

	/**
	 * Show what would be sent to support, before anything is sent.
	 *
	 * The one implementation of this dialog. It appends to document.body, so it is
	 * not subject to a containing block established by an ancestor transform or
	 * filter, and it is framework-free, so the React dashboard panel can call it
	 * through window.srfmOpenDetails rather than carrying a second copy. A second
	 * copy is how two surfaces end up with different keyboard behaviour, different
	 * contrast and two sets of the same strings.
	 *
	 * Every string comes from srfmNoticeResponse.details, so the labels are
	 * translated in PHP once and neither caller carries user-facing English.
	 *
	 * Read with textContent and written with textContent, never innerHTML: the log
	 * contains whatever a server or a browser put in an error message, and that is
	 * not markup to be trusted.
	 *
	 * @param {Object} config            Dialog contents.
	 * @param {string} config.noticeId   Item id, used as the analytics key.
	 * @param {string} config.text       Plain-text details to show and copy.
	 * @param {string} config.supportUrl Where Contact Support goes. Empty means
	 *                                   there is nowhere to send them, so the
	 *                                   button is not rendered at all.
	 * @return {boolean} Whether the dialog opened.
	 */
	function showDetails( config ) {
		const noticeId = ( config && config.noticeId ) || '';
		const text = ( config && config.text ) || '';
		const supportUrl = ( config && config.supportUrl ) || '';

		if ( ! text ) {
			return false;
		}

		const labels =
			( typeof srfmNoticeResponse !== 'undefined' &&
				srfmNoticeResponse.details ) ||
			{};

		const overlay = document.createElement( 'div' );
		overlay.style.cssText =
			'position:fixed;inset:0;z-index:999999;display:flex;align-items:center;' +
			'justify-content:center;background:rgba(0,0,0,.5);padding:16px;';

		// Restored on close. Without it the trigger is gone from the tab order and
		// a keyboard user starts again from the top of the page (WCAG 2.4.3).
		const opener = overlay.ownerDocument.activeElement;
		const previousOverflow = document.body.style.overflow;

		// Whether copying is even possible here. On a plain-HTTP admin
		// navigator.clipboard does not exist, so the copy step cannot be a
		// precondition for anything -- see the unlock reasoning below.
		const canCopy = !! (
			navigator.clipboard &&
			( navigator.clipboard.write || navigator.clipboard.writeText )
		);

		const ids = 'srfm-details-' + Math.random().toString( 36 ).slice( 2, 10 );

		const panel = document.createElement( 'div' );
		panel.setAttribute( 'role', 'dialog' );
		panel.setAttribute( 'aria-modal', 'true' );
		// Pointed at the real heading and description rather than repeating the
		// title in an aria-label, which announces it twice.
		panel.setAttribute( 'aria-labelledby', ids + '-title' );
		panel.setAttribute( 'aria-describedby', ids + '-desc' );
		panel.style.cssText =
			'background:#fff;border-radius:8px;padding:16px;width:100%;' +
			'max-width:720px;box-shadow:0 10px 30px rgba(0,0,0,.2);';

		const heading = document.createElement( 'h2' );
		heading.id = ids + '-title';
		heading.textContent = labels.title || 'Details';
		heading.style.cssText = 'margin:0 0 4px;font-size:14px;';

		const description = document.createElement( 'p' );
		description.id = ids + '-desc';
		description.textContent = labels.description || '';
		description.style.cssText = 'margin:0 0 12px;color:#50575e;';

		// Selectable and scrollable, because clipboard access can be refused and
		// then selecting by hand is the only way through. tabindex because Chromium
		// and WebKit do not make a scroll container focusable on their own, so
		// without it a keyboard user cannot reach the very thing the dialog exists
		// to show. Firefox does, which is why this looks fine there.
		const pre = document.createElement( 'pre' );
		pre.textContent = text;
		pre.tabIndex = 0;
		pre.setAttribute( 'role', 'region' );
		pre.setAttribute( 'aria-label', labels.logRegion || 'Diagnostics' );
		pre.style.cssText =
			'margin:0;max-height:320px;overflow:auto;white-space:pre-wrap;' +
			'word-break:break-word;background:#f6f7f7;padding:12px;' +
			'border-radius:6px;font-size:12px;';

		const actions = document.createElement( 'p' );
		actions.style.cssText =
			'display:flex;gap:8px;align-items:center;flex-wrap:wrap;' +
			'justify-content:flex-end;margin:12px 0 0;';

		// Visible, not a title attribute. pointer-events:none suppresses the native
		// tooltip, a title never fires on keyboard focus, and screen readers
		// commonly drop it on an unavailable control -- so the sentence explaining
		// why the button is inert could not be read by anyone.
		const hint = document.createElement( 'span' );
		hint.style.cssText =
			'margin-inline-end:auto;font-size:12px;color:#4b5563;';
		hint.textContent = canCopy ? labels.copyFirst || '' : '';

		// Doubles as the live region for the unlock. Copying changes three things at
		// once -- the label, the icon and whether Contact Support works -- and none
		// of them was announced.
		hint.setAttribute( 'role', 'status' );

		const copy = document.createElement( 'button' );
		copy.type = 'button';
		copy.className = 'button';
		copy.textContent = labels.copy || 'Copy details';

		// Locked until the details are on the clipboard. The support form asks for
		// them, and arriving with nothing to paste means describing the failure
		// from memory.
		//
		// No href while it is locked, and a click guard behind that: `disabled` on
		// an <a> does nothing at all -- it still navigates -- so removing the
		// destination is what actually locks it.
		//
		// Not rendered at all without a destination. An empty href resolves to the
		// current document, so the click used to open a duplicate of the admin page
		// and still acknowledge the failure -- standing the notice down without
		// anything having been reported. Reachable through srfm_action_items for an
		// item carrying details but no support_url.
		const contact = supportUrl ? document.createElement( 'a' ) : null;

		if ( contact ) {
			contact.className = 'button button-primary';
			contact.target = '_blank';
			contact.rel = 'noopener noreferrer';
			contact.textContent = labels.contact || 'Contact Support';
		}

		function lockContact() {
			if ( ! contact ) {
				return;
			}

			contact.removeAttribute( 'href' );
			contact.setAttribute( 'aria-disabled', 'true' );
			contact.style.opacity = '0.6';
			contact.style.pointerEvents = 'none';
		}

		function unlockContact() {
			if ( ! contact ) {
				return;
			}

			contact.href = supportUrl;
			contact.removeAttribute( 'aria-disabled' );
			contact.style.opacity = '';
			contact.style.pointerEvents = '';
		}

		// Locked only where copying can actually happen. Where it cannot, the copy
		// step is not a step the user can take, and Contact Support is the only
		// route that acknowledges the failure -- these items are not dismissible,
		// and only a submission failure ever clears itself. Keeping it locked
		// behind an unavailable clipboard would leave an undismissable notice with
		// no working action on it.
		if ( canCopy ) {
			lockContact();
		}

		const close = document.createElement( 'button' );
		close.type = 'button';
		close.className = 'button-link';
		close.textContent = labels.close || 'Close';

		let revert = 0;

		function dismiss() {
			document.removeEventListener( 'keydown', onKey );
			window.clearTimeout( revert );
			document.body.style.overflow = previousOverflow;
			overlay.remove();

			// Back to whatever opened it, on every close path.
			if ( opener && typeof opener.focus === 'function' ) {
				opener.focus();
			}
		}

		// aria-modal asserts that the background is unavailable; it does nothing to
		// the tab sequence in any browser. Without this, Tab from the last control
		// walks into the admin bar, the admin menu and the links behind the overlay.
		function focusables() {
			return [ pre, close, copy, contact ].filter( function ( el ) {
				return el && ! el.hasAttribute( 'aria-disabled' );
			} );
		}

		function onKey( e ) {
			if ( e.key === 'Escape' ) {
				dismiss();
				return;
			}

			if ( e.key !== 'Tab' ) {
				return;
			}

			const stops = focusables();

			if ( ! stops.length ) {
				return;
			}

			const at = stops.indexOf( overlay.ownerDocument.activeElement );
			const next = e.shiftKey ? at - 1 : at + 1;

			if ( at === -1 || next < 0 || next >= stops.length ) {
				e.preventDefault();
				stops[
					e.shiftKey ? stops.length - 1 : 0
				].focus();
			}
		}

		copy.addEventListener( 'click', function () {
			copyDetails(
				text,
				function () {
					copy.textContent = labels.copied || 'Copied';
					// Reverts on its own: a button stuck on "Copied" says nothing
					// about the next click. The unlock does not revert with it --
					// having the clipboard stays true after the label has gone back.
					window.clearTimeout( revert );
					revert = window.setTimeout( function () {
						copy.textContent = labels.copy || 'Copy details';
					}, 2000 );

					unlockContact();
					hint.textContent = labels.unlocked || '';

					// On success only, so the event means a copy happened rather
					// than a copy was attempted.
					sendResponse( noticeId, 'copy_details' );
				},
				function () {
					// Refused: an insecure origin, a permission policy, a user
					// decision. Do not claim success -- but release Contact Support
					// for the reason above, and say why the text was not copied.
					hint.textContent = labels.copyFailed || '';
					unlockContact();
				}
			);
		} );

		if ( contact ) {
			contact.addEventListener( 'click', function () {
				sendResponse( noticeId, 'contact_support' );
				// The form opens in its own tab, so the dialog has nothing left to
				// show.
				dismiss();
			} );
		}

		close.addEventListener( 'click', dismiss );
		overlay.addEventListener( 'click', function ( e ) {
			// Backdrop only: a click inside the panel must not close it while
			// someone is selecting the text.
			if ( e.target === overlay ) {
				dismiss();
			}
		} );
		document.addEventListener( 'keydown', onKey );

		actions.appendChild( hint );
		actions.appendChild( close );
		actions.appendChild( copy );

		if ( contact ) {
			actions.appendChild( contact );
		}

		panel.appendChild( heading );
		panel.appendChild( description );
		panel.appendChild( pre );
		panel.appendChild( actions );
		overlay.appendChild( panel );
		document.body.appendChild( overlay );

		// The page behind must not scroll under the backdrop.
		document.body.style.overflow = 'hidden';

		copy.focus();

		return true;
	}

	/**
	 * Open the dialog for one classic notice, from the payload beside it.
	 *
	 * The text is already in the page, hidden next to its notice, so opening this
	 * makes no request -- a modal that has to fetch can fail at the exact moment
	 * someone is trying to report a failure.
	 *
	 * @param {string} noticeId Item id, matched against the hidden payload's
	 *                          data-srfm-details-id.
	 * @return {boolean} Whether the payload was found and the dialog opened.
	 */
	function openDetails( noticeId ) {
		// Escaped: the id comes from the items array, which srfm_action_items can
		// contribute to, and an unescaped quote here throws a SyntaxError out of
		// the click handler.
		const selector =
			'.srfm-notice-details[data-srfm-details-id="' +
			( window.CSS && window.CSS.escape
				? window.CSS.escape( noticeId )
				: noticeId ) +
			'"]';

		let source = null;

		try {
			source = document.querySelector( selector );
		} catch ( e ) {
			source = null;
		}

		if ( ! source ) {
			return false;
		}

		return showDetails( {
			noticeId,
			text: source.textContent || '',
			supportUrl: source.getAttribute( 'data-srfm-support-url' ) || '',
		} );
	}

	// The dashboard panel is React and this file is not, so it calls in here rather
	// than carrying its own copy of the dialog. One implementation, one set of
	// strings, one keyboard behaviour.
	window.srfmOpenDetails = showDetails;

	document.addEventListener( 'click', function ( e ) {
		const trigger = e.target.closest( '[data-srfm-details-for]' );

		if ( ! trigger ) {
			return;
		}

		// Only swallow the navigation if the dialog actually opened. If the payload
		// is missing the href still goes to the dashboard, which is where the same
		// details are readable.
		if ( openDetails( trigger.getAttribute( 'data-srfm-details-for' ) ) ) {
			e.preventDefault();
		}
	} );
}() );
