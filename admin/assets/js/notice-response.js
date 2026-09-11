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
		// Guarded like the carousel's read of the same global: this file is
		// enqueued from several places and a missing localize object should not
		// throw out of a click handler.
		if ( typeof srfmNoticeResponse === 'undefined' ) {
			return;
		}

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

		// Positioning and spacing live in the stylesheet the renderer prints, so
		// an RTL sheet can override them and nothing here is a magic number.
		// Declared above adopt() because adopt() reads it and runs before the
		// controls are assembled.
		const nav = document.createElement( 'p' );
		nav.className = 'srfm-action-item-carousel-nav';

		/**
		 * Put the cards inside the wrapper, wherever they currently are.
		 *
		 * WordPress core relocates every `.notice` into `div.wrap` on jQuery ready,
		 * and that runs after this file's DOMContentLoaded handler -- so wrapping
		 * once at build time left the wrapper behind, empty and zero-height, with
		 * the controls positioned against it off the side of the screen and the
		 * reserved padding matching nothing.
		 *
		 * Driven by a MutationObserver rather than from render(). Called only from
		 * render() it could not fire for a relocation that happens after the first
		 * paint -- reaching it needed an arrow click, and a relocation is exactly
		 * what strands the arrows: the cards move away carrying their `hidden`
		 * attribute while nav stays behind, leaving one notice visible, the rest
		 * permanently hidden, and no control the user can reach to recover. On a
		 * surface whose only job is showing faults that is worse than not having a
		 * carousel. The every() check makes this idempotent, so the observer
		 * seeing its own writes is harmless.
		 */
		function adopt() {
			if (
				cards.every( function ( notice ) {
					return notice.parentNode === wrap;
				} )
			) {
				return;
			}

			// Anchor on the first card still outside the wrapper. cards[0] may
			// already be inside it, and wrap.insertBefore( wrap, … ) is a
			// HierarchyRequestError; a card that was removed rather than moved has
			// no parentNode at all, and dereferencing it would throw out of
			// render() before the visibility loop ran.
			const anchor = cards.filter( function ( notice ) {
				return notice.parentNode && notice.parentNode !== wrap;
			} )[ 0 ];

			if ( ! anchor ) {
				return;
			}

			// Moving the wrapper runs the DOM remove steps over its subtree, which
			// blurs whatever inside it had focus -- and nav is inside it, so the
			// arrow the user just pressed loses focus in exactly the case adopt()
			// exists for.
			const active = nav.ownerDocument.activeElement;
			const focused = nav.contains( active ) ? active : null;

			anchor.parentNode.insertBefore( wrap, anchor );
			cards.forEach( function ( notice ) {
				wrap.appendChild( notice );
			} );

			if ( focused ) {
				focused.focus();
			}
		}

		adopt();

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

		// And keep watching. A plugin acting on window.load, an admin-notice
		// manager or a screen-options re-layout can move the notices at any point,
		// and adopt() driven only from a click cannot recover from that -- the
		// click it needs is the thing the move breaks.
		if ( window.MutationObserver ) {
			new window.MutationObserver( function () {
				adopt();
				measure();
			} ).observe( document.body, { childList: true, subtree: true } );
		}
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
			// Both of these throw synchronously, not through the promise:
			// ClipboardItem's constructor on an unsupported MIME type, and
			// clipboard.write() on a bad argument in Chromium or a stale user
			// gesture in WebKit. Outside a try, the exception leaves copyDetails
			// and the click handler with neither callback run -- so the caller
			// never unlocks and the notice keeps no working action.
			try {
				const item = new window.ClipboardItem( {
					'text/plain': new Blob( [ text ], { type: 'text/plain' } ),
					'text/html': new Blob( [ detailsAsHtml( text ) ], {
						type: 'text/html',
					} ),
				} );

				navigator.clipboard.write( [ item ] ).then( done, nope );
			} catch ( e ) {
				nope();
			}

			return;
		}

		if ( navigator.clipboard && navigator.clipboard.writeText ) {
			try {
				navigator.clipboard.writeText( text ).then( done, nope );
			} catch ( e ) {
				nope();
			}

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
	 * The dialog for the classic wp-admin notices. The SureForms dashboard has its
	 * own, built on force-ui's Dialog, because there is no React on these screens
	 * and no force-ui bundle either -- so the two surfaces are separate
	 * implementations by necessity. They read their strings from one PHP array
	 * (Admin::get_details_dialog_labels(), reaching here as
	 * srfmNoticeResponse.details and the dashboard as srfm_admin.details_dialog),
	 * so the copy cannot drift even though the markup does.
	 *
	 * This one appends to document.body, so it is not subject to a containing
	 * block established by an ancestor transform or filter.
	 *
	 * The fallbacks below are English, because a plain admin script has no
	 * gettext runtime to fall back to; they are reached only if the localize data
	 * is missing entirely.
	 *
	 * Read with textContent and written with textContent, never innerHTML: the log
	 * contains whatever a server or a browser put in an error message, and that is
	 * not markup to be trusted.
	 *
	 * @param {Object}  config            Dialog contents.
	 * @param {string}  config.noticeId   Item id, used as the analytics key.
	 * @param {string}  config.text       Plain-text details to show and copy.
	 * @param {string}  config.supportUrl Where Contact Support goes. Empty means
	 *                                    there is nowhere to send them, so the
	 *                                    button is not reachable at all.
	 * @param {boolean} config.pending    Whether `text` is a placeholder that a
	 *                                    later setText() will replace.
	 * @return {Object|false} Handle with setText()/setSupportUrl(), or false if
	 *                        there was nothing to open.
	 */
	function showDetails( config ) {
		const noticeId = ( config && config.noticeId ) || '';
		const pending = !! ( config && config.pending );

		let text = ( config && config.text ) || '';
		let supportUrl = ( config && config.supportUrl ) || '';

		if ( ! text ) {
			return false;
		}

		const labels =
			( typeof srfmNoticeResponse !== 'undefined' &&
				srfmNoticeResponse.details ) ||
			{};

		const overlay = document.createElement( 'div' );
		overlay.className = 'srfm-details-overlay';

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
		panel.className = 'srfm-details-panel';

		const heading = document.createElement( 'h2' );
		heading.id = ids + '-title';
		heading.textContent = labels.title || 'Details';

		const description = document.createElement( 'p' );
		description.id = ids + '-desc';
		description.textContent = labels.description || '';
		description.className = 'srfm-details-description';

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

		if ( pending ) {
			// Announced as busy rather than silently showing a placeholder, so a
			// screen reader says the region is still filling instead of reading
			// "Loading" as if it were the report.
			pre.setAttribute( 'aria-busy', 'true' );
		}

		const actions = document.createElement( 'p' );
		actions.className = 'srfm-details-actions';

		// Visible, not a title attribute. pointer-events:none suppresses the native
		// tooltip, a title never fires on keyboard focus, and screen readers
		// commonly drop it on an unavailable control -- so the sentence explaining
		// why the button is inert could not be read by anyone.
		const hint = document.createElement( 'span' );
		hint.className = 'srfm-details-hint';
		hint.textContent = canCopy ? labels.copyFirst || '' : '';

		// Doubles as the live region for the unlock. Copying changes three things at
		// once -- the label, the icon and whether Contact Support works -- and none
		// of them was announced.
		hint.setAttribute( 'role', 'status' );

		const copy = document.createElement( 'button' );
		copy.type = 'button';
		copy.className = 'button srfm-details-copy';
		copy.textContent = labels.copy || 'Copy details';

		// Locked until the details are on the clipboard. The support form asks for
		// them, and arriving with nothing to paste means describing the failure
		// from memory.
		//
		// No href while it is locked, and a click guard behind that: `disabled` on
		// an <a> does nothing at all -- it still navigates -- so removing the
		// destination is what actually locks it.
		//
		// Hidden, not merely href-less, until there is a destination. An empty href
		// resolves to the current document, so the click used to open a duplicate
		// of the admin page and still acknowledge the failure -- standing the
		// notice down without anything having been reported. The destination now
		// arrives with the fetched payload, so the element is built up front and
		// revealed by setSupportUrl().
		const contact = document.createElement( 'a' );
		contact.className = 'button button-primary srfm-details-contact';
		contact.textContent = labels.contact || 'Contact Support';
		contact.hidden = ! supportUrl;

		function lockContact() {
			// The dimming and the pointer-events block hang off aria-disabled in the
			// stylesheet, so the state is declared once rather than in two places.
			contact.removeAttribute( 'href' );
			contact.setAttribute( 'aria-disabled', 'true' );
		}

		function unlockContact() {
			if ( ! supportUrl ) {
				return;
			}

			contact.href = supportUrl;
			contact.removeAttribute( 'aria-disabled' );
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

		// Nothing to copy yet. Left focusable-but-disabled rather than hidden, so
		// the control does not appear from nowhere once the payload lands.
		copy.disabled = pending;

		const close = document.createElement( 'button' );
		close.type = 'button';
		close.className = 'button-link srfm-details-close';
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
				return (
					el &&
					! el.hidden &&
					! el.disabled &&
					! el.hasAttribute( 'aria-disabled' )
				);
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

		contact.addEventListener( 'click', function () {
			sendResponse( noticeId, 'contact_support' );
			// The form opens in its own tab, so the dialog has nothing left to
			// show.
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

		actions.appendChild( hint );
		actions.appendChild( close );
		actions.appendChild( copy );

		actions.appendChild( contact );

		panel.appendChild( heading );
		panel.appendChild( description );
		panel.appendChild( pre );
		panel.appendChild( actions );
		overlay.appendChild( panel );
		document.body.appendChild( overlay );

		// The page behind must not scroll under the backdrop.
		document.body.style.overflow = 'hidden';

		// The diagnostics region while the payload is still coming: it carries the
		// placeholder, so focusing it says what the dialog is doing. Once there is
		// something to copy, the copy button is the first thing anyone wants.
		( copy.disabled ? pre : copy ).focus();

		return {
			/**
			 * Replace the placeholder once the payload arrives.
			 *
			 * @param {string}  next     The text to show and copy.
			 * @param {boolean} copyable Whether it is worth copying. False for an
			 *                           error message, which would otherwise unlock
			 *                           Contact Support on the strength of having
			 *                           copied the error.
			 * @return {void}
			 */
			setText( next, copyable ) {
				// Dropped once this dialog is gone. Open, close, open again inside
				// one round trip and the first response would otherwise write into
				// the second dialog -- or into a detached node, silently.
				if ( ! overlay.isConnected ) {
					return;
				}

				text = next || '';
				pre.textContent = text;
				pre.removeAttribute( 'aria-busy' );

				if ( false === copyable ) {
					// No report to paste, so the copy-first gate has nothing to gate
					// on. Contact Support is the only route that retires the notice,
					// and these items are not dismissible -- leaving it locked would
					// be an undismissable notice with no working action on it.
					copy.hidden = true;
					hint.textContent = '';
					unlockContact();
					return;
				}

				copy.disabled = ! text;
			},

			/**
			 * Point Contact Support somewhere, once the payload names it.
			 *
			 * @param {string} next Destination. Empty keeps the button out of the
			 *                      dialog entirely.
			 * @return {void}
			 */
			setSupportUrl( next ) {
				if ( ! overlay.isConnected ) {
					return;
				}

				supportUrl = next || '';
				contact.hidden = ! supportUrl;

				// A mailto: has no document to open, so _blank would leave a blank
				// tab behind. srfm_support_contact_url can return one, and the
				// notice's own links already match on this.
				if ( /^mailto:/i.test( supportUrl ) ) {
					contact.removeAttribute( 'target' );
					contact.removeAttribute( 'rel' );
				} else {
					contact.target = '_blank';
					contact.rel = 'noopener noreferrer';
				}

				if ( supportUrl && ! contact.hasAttribute( 'aria-disabled' ) ) {
					contact.href = supportUrl;
				}
			},

			dismiss,
		};
	}

	/**
	 * Open the dialog for one classic notice and fetch its payload.
	 *
	 * Fetched on open rather than printed beside every notice. The diagnostics are
	 * written by a public REST route, so shipping them with the page put
	 * attacker-authored text on every admin screen whether or not anyone opened
	 * the dialog.
	 *
	 * The dialog opens first, with a placeholder, and fills in when the response
	 * lands -- a surface whose whole job is reporting a failure must not be a
	 * button that does nothing until the network answers. A failed fetch says so
	 * in place and still offers Contact Support, which is the only action that
	 * retires the notice.
	 *
	 * @param {string} noticeId Item id, used as the analytics key.
	 * @param {string} category Failure category, sent to the handler.
	 * @return {boolean} Whether the payload was found and the dialog opened.
	 */
	function openDetails( noticeId, category ) {
		if ( typeof srfmNoticeResponse === 'undefined' || ! category ) {
			return false;
		}

		const labels = srfmNoticeResponse.details || {};

		const dialog = showDetails( {
			noticeId,
			text: labels.loading || 'Loading…',
			supportUrl: '',
			pending: true,
		} );

		if ( ! dialog ) {
			return false;
		}

		const body = new FormData();
		body.append( 'action', 'srfm_action_item_details' );
		body.append( 'nonce', srfmNoticeResponse.detailsNonce );
		body.append( 'category', category );

		fetch( srfmNoticeResponse.ajaxurl, { method: 'POST', body } )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( json ) {
				if ( ! json || ! json.success || ! json.data ) {
					throw new Error( 'unavailable' );
				}

				// Same fallback as the failure path below. A successful fetch must
				// not end up with less to act on than a failed one: these notices
				// are not dismissible and Contact Support is the only thing that
				// retires them, so an empty destination here is a dead end.
				dialog.setSupportUrl(
					json.data.support_url ||
						srfmNoticeResponse.supportUrl ||
						''
				);
				dialog.setText( json.data.details || '' );
			} )
			.catch( function () {
				// The report is built server-side, so there is nothing to show in
				// its place. Say so, and fall back to the untagged support form:
				// Contact Support is the only action that retires these notices, so
				// a failed fetch must not also take the way out with it.
				//
				// Destination set before the message, because the message releases
				// the copy-first lock and that release needs somewhere to point.
				dialog.setSupportUrl( srfmNoticeResponse.supportUrl || '' );
				dialog.setText( labels.unavailable || '', false );
			} );

		return true;
	}

	document.addEventListener( 'click', function ( e ) {
		// A click whose target is not an Element -- text nodes, some synthetic
		// events -- has no closest() and would throw out of the handler.
		if ( ! e.target || typeof e.target.closest !== 'function' ) {
			return;
		}

		const trigger = e.target.closest( '[data-srfm-details-for]' );

		if ( ! trigger ) {
			return;
		}

		// Only swallow the navigation if the dialog actually opened. If it cannot,
		// the href still goes to the dashboard, which is where the same details are
		// readable.
		if (
			openDetails(
				trigger.getAttribute( 'data-srfm-details-for' ),
				trigger.getAttribute( 'data-srfm-category' )
			)
		) {
			e.preventDefault();
		}
	} );
}() );
