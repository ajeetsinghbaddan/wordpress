/**
 * Groq Site Chatbot — frontend.
 * Plain vanilla JS (no jQuery dependency). Everything the model returns is
 * rendered with textContent, never innerHTML, so a malicious/compromised
 * answer can never execute script in the visitor's browser (XSS-safe).
 */
(function () {
	'use strict';

	if ( typeof gscConfig === 'undefined' ) {
		return;
	}

	var toggle   = document.getElementById( 'gsc-toggle' );
	var panel    = document.getElementById( 'gsc-panel' );
	var closeBtn = document.getElementById( 'gsc-close' );
	var form     = document.getElementById( 'gsc-form' );
	var input    = document.getElementById( 'gsc-input' );
	var sendBtn  = document.getElementById( 'gsc-send' );
	var messages = document.getElementById( 'gsc-messages' );

	// Conversation memory lives only in this tab; the server re-validates it.
	var history = [];
	var busy    = false;

	function openPanel( open ) {
		panel.hidden = ! open;
		toggle.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
		if ( open ) {
			input.focus();
			if ( ! messages.childElementCount ) {
				addBubble( 'assistant', 'Hi! Ask me anything about this site.' );
			}
		}
	}

	toggle.addEventListener( 'click', function () { openPanel( panel.hidden ); } );
	closeBtn.addEventListener( 'click', function () { openPanel( false ); } );

	/**
	 * Build a message bubble. textContent (not innerHTML) is the key
	 * security detail: the string is treated as text, never parsed as HTML.
	 */
	function addBubble( role, text, meta ) {
		var wrap = document.createElement( 'div' );
		wrap.className = 'gsc-msg gsc-' + role;

		var bubble = document.createElement( 'div' );
		bubble.className = 'gsc-bubble';
		bubble.textContent = text;
		wrap.appendChild( bubble );

		// Optional source links (site pages the answer came from).
		if ( meta && meta.sources && meta.sources.length ) {
			var srcWrap = document.createElement( 'div' );
			srcWrap.className = 'gsc-sources';
			meta.sources.forEach( function ( s ) {
				// Only allow http(s) URLs — blocks javascript: URI injection.
				if ( ! /^https?:\/\//i.test( s.url ) ) {
					return;
				}
				var a = document.createElement( 'a' );
				a.href = s.url;
				a.textContent = s.title;
				a.target = '_blank';
				a.rel = 'noopener noreferrer';
				srcWrap.appendChild( a );
			} );
			if ( srcWrap.childElementCount ) {
				wrap.appendChild( srcWrap );
			}
		}

		if ( meta && meta.source === 'web' ) {
			var tag = document.createElement( 'div' );
			tag.className = 'gsc-tag';
			tag.textContent = 'Answered from the web';
			wrap.appendChild( tag );
		}

		messages.appendChild( wrap );
		messages.scrollTop = messages.scrollHeight;
		return wrap;
	}

	function addTyping() {
		var el = addBubble( 'assistant', '' );
		el.querySelector( '.gsc-bubble' ).innerHTML =
			'<span class="gsc-dot"></span><span class="gsc-dot"></span><span class="gsc-dot"></span>';
		el.classList.add( 'gsc-typing' );
		return el;
	}

	form.addEventListener( 'submit', function ( e ) {
		e.preventDefault();
		if ( busy ) {
			return;
		}
		var text = input.value.trim();
		if ( ! text ) {
			return;
		}

		addBubble( 'user', text );
		input.value = '';
		busy = true;
		sendBtn.disabled = true;
		var typing = addTyping();

		fetch( gscConfig.endpoint, {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': gscConfig.nonce // CSRF token, verified server-side
			},
			body: JSON.stringify( { message: text, history: history } )
		} )
			.then( function ( res ) {
				return res.json().then( function ( data ) {
					return { ok: res.ok, data: data };
				} );
			} )
			.then( function ( result ) {
				typing.remove();
				if ( ! result.ok ) {
					addBubble( 'assistant', result.data && result.data.message
						? result.data.message
						: 'Something went wrong. Please try again.' );
					return;
				}
				addBubble( 'assistant', result.data.answer, result.data );
				// Remember the exchange for follow-up questions.
				history.push( { role: 'user', content: text } );
				history.push( { role: 'assistant', content: result.data.answer } );
				if ( history.length > 6 ) {
					history = history.slice( -6 ); // keep last 3 exchanges
				}
			} )
			.catch( function () {
				typing.remove();
				addBubble( 'assistant', 'Network error. Please check your connection and try again.' );
			} )
			.finally( function () {
				busy = false;
				sendBtn.disabled = false;
				input.focus();
			} );
	} );
})();
