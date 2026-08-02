/**
 * Puzzle Gate — front-end
 *
 * Responsibilities, and just as importantly what it is NOT responsible for:
 *
 *   IT DOES : draw the puzzle, collect what the visitor did, ask the server.
 *   IT DOES NOT : decide whether the puzzle was solved, or hold the secret.
 *
 * Every meaningful decision happens on the server. This file could be rewritten
 * by an attacker in DevTools and it would gain them nothing, because the only
 * thing it can do is post an attempt to an endpoint that checks it properly.
 *
 * Written as an IIFE with no dependencies: no jQuery, no build step, ~7KB.
 */
( function () {
	'use strict';

	var CFG = window.PuzzleGateData || {};
	var T = CFG.i18n || {};

	/* =====================================================================
	 * Networking
	 * ===================================================================== */

	/**
	 * Thin wrapper around fetch().
	 *
	 * `credentials: 'same-origin'` is what makes the HttpOnly pass cookie travel
	 * with the request — without it the browser would omit cookies and the
	 * "remember I solved this" feature would silently never work.
	 *
	 * The X-WP-Nonce header identifies a logged-in WordPress session. It is not
	 * our security boundary (see the PHP comments) but sending it keeps requests
	 * attributed to the right user.
	 */
	function api( path, body ) {
		return fetch( CFG.root + path, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': CFG.nonce || ''
			},
			body: JSON.stringify( body )
		} ).then( function ( res ) {
			return res.json().then( function ( data ) {
				if ( ! res.ok ) {
					// WP_Error responses arrive as { code, message, data }.
					var err = new Error( ( data && data.message ) || T.error );
					err.code = data && data.code;
					throw err;
				}
				return data;
			} );
		} );
	}

	/* =====================================================================
	 * Small helpers
	 * ===================================================================== */

	function el( tag, className, text ) {
		var node = document.createElement( tag );
		if ( className ) { node.className = className; }
		if ( text !== undefined ) { node.textContent = text; }
		return node;
	}

	function say( gate, message, tone ) {
		var status = gate.querySelector( '[data-pgz-status]' );
		if ( ! status ) { return; }
		status.textContent = message || '';
		status.className = 'pgz__status' + ( tone ? ' pgz__status--' + tone : '' );
	}

	function hasCookie( name ) {
		return document.cookie.split( ';' ).some( function ( c ) {
			return c.trim().indexOf( name + '=' ) === 0;
		} );
	}

	function clock( seconds ) {
		var m = Math.floor( seconds / 60 );
		var s = seconds % 60;
		return m + ':' + ( s < 10 ? '0' : '' ) + s;
	}

	/* =====================================================================
	 * Reveal
	 * ===================================================================== */

	function reveal( gate, html ) {
		var plate = gate.querySelector( '[data-pgz-plate]' );

		gate.classList.add( 'is-open' );

		var box = el( 'div', 'pgz__revealed' );
		// The HTML came from our own REST endpoint, produced from post content
		// written by someone who can already publish on this site. That is the
		// same trust level as the rest of the page, so innerHTML is appropriate
		// here — it would NOT be if this string came from a visitor.
		box.innerHTML = html;

		plate.replaceChildren( box );

		// Move focus to the newly revealed region so keyboard and screen-reader
		// users land on the thing they just unlocked instead of being dumped at
		// the top of the document.
		box.setAttribute( 'tabindex', '-1' );
		box.focus( { preventScroll: true } );

		var live = el( 'p', 'screen-reader-text' );
		live.setAttribute( 'role', 'status' );
		live.textContent = T.announce || '';
		box.appendChild( live );

		if ( Number( CFG.confetti ) && ! matchMedia( '(prefers-reduced-motion: reduce)' ).matches ) {
			burst( gate );
		}
	}

	/* =====================================================================
	 * Puzzle renderers
	 * Each returns nothing; they wire their own submit path.
	 * ===================================================================== */

	var renderers = {};

	/* ---------- sliding tiles ---------- */

	renderers.slide = function ( gate, stage, data, submit ) {
		var size = data.puzzle.size;
		var board = data.puzzle.board.slice();
		var image = data.puzzle.image;
		var moves = [];
		var startedAt = Date.now();
		var timer;

		var readout = el( 'div', 'pgz__readout' );
		var moveOut = el( 'b', null, '0' );
		var timeOut = el( 'b', null, '0:00' );
		readout.append( wrap( T.moves, moveOut ), wrap( T.time, timeOut ) );

		var grid = el( 'div', 'pgz__board' );
		grid.style.gridTemplateColumns = 'repeat(' + size + ', 1fr)';
		grid.setAttribute( 'role', 'group' );
		grid.setAttribute( 'aria-label', 'Sliding puzzle' );

		stage.replaceChildren( readout, grid );
		draw();

		timer = setInterval( function () {
			timeOut.textContent = clock( Math.floor( ( Date.now() - startedAt ) / 1000 ) );
		}, 1000 );

		// Arrow keys slide the tile that sits in that direction from the blank.
		grid.addEventListener( 'keydown', function ( e ) {
			var blank = board.indexOf( 0 );
			var row = Math.floor( blank / size );
			var col = blank % size;
			var target = null;

			if ( e.key === 'ArrowUp' && row < size - 1 ) { target = blank + size; }
			if ( e.key === 'ArrowDown' && row > 0 ) { target = blank - size; }
			if ( e.key === 'ArrowLeft' && col < size - 1 ) { target = blank + 1; }
			if ( e.key === 'ArrowRight' && col > 0 ) { target = blank - 1; }

			if ( target !== null ) {
				e.preventDefault();
				move( target );
			}
		} );

		function wrap( label, valueNode ) {
			var span = el( 'span', null, label + ' ' );
			span.appendChild( valueNode );
			return span;
		}

		function draw() {
			grid.replaceChildren();

			board.forEach( function ( value, index ) {
				var tile = el( 'button', 'pgz__tile' );
				tile.type = 'button';

				if ( value === 0 ) {
					tile.classList.add( 'pgz__tile--blank' );
					tile.tabIndex = -1;
					tile.setAttribute( 'aria-hidden', 'true' );
				} else {
					tile.textContent = String( value );
					tile.setAttribute( 'aria-label', 'Tile ' + value );

					if ( image ) {
						// Slice one picture across the grid by shifting the
						// background position per tile — no image cropping,
						// no extra HTTP requests.
						var home = value - 1;
						tile.classList.add( 'pgz__tile--img' );
						tile.style.backgroundImage = 'url("' + image.replace( /"/g, '%22' ) + '")';
						tile.style.backgroundSize = ( size * 100 ) + '%';
						tile.style.backgroundPosition =
							( ( home % size ) / ( size - 1 ) * 100 ) + '% ' +
							( Math.floor( home / size ) / ( size - 1 ) * 100 ) + '%';
					}
				}

				tile.addEventListener( 'click', function () { move( index ); } );
				grid.appendChild( tile );
			} );
		}

		function move( index ) {
			var blank = board.indexOf( 0 );
			if ( ! adjacent( index, blank ) ) { return; }

			board[ blank ] = board[ index ];
			board[ index ] = 0;

			// The move log is the proof we send to the server. Order matters:
			// the server replays exactly this list from the scramble it issued.
			moves.push( index );
			moveOut.textContent = String( moves.length );

			draw();

			if ( solved() ) {
				clearInterval( timer );
				submit( { moves: moves } );
			}
		}

		function adjacent( a, b ) {
			var ra = Math.floor( a / size ), ca = a % size;
			var rb = Math.floor( b / size ), cb = b % size;
			return Math.abs( ra - rb ) + Math.abs( ca - cb ) === 1;
		}

		function solved() {
			for ( var i = 0; i < board.length - 1; i++ ) {
				if ( board[ i ] !== i + 1 ) { return false; }
			}
			return board[ board.length - 1 ] === 0;
		}
	};

	/* ---------- riddle ---------- */

	renderers.riddle = function ( gate, stage, data, submit ) {
		var question = el( 'p', 'pgz__question', data.puzzle.question );
		buildAnswerForm( stage, question, 'text', submit );
	};

	/* ---------- number sequence ---------- */

	renderers.sequence = function ( gate, stage, data, submit ) {
		var list = el( 'ul', 'pgz__sequence' );
		data.puzzle.sequence.forEach( function ( n ) {
			list.appendChild( el( 'li', null, String( n ) ) );
		} );
		list.appendChild( el( 'li', null, '?' ) );

		var question = el( 'p', 'pgz__question', 'What comes next?' );
		var holder = document.createDocumentFragment();
		holder.append( question, list );

		buildAnswerForm( stage, holder, 'number', submit );
	};

	/**
	 * Shared input + button for the answer-based puzzles.
	 * Deliberately not a <form>: a real form submit would reload the page.
	 */
	function buildAnswerForm( stage, prompt, inputType, submit ) {
		var row = el( 'div', 'pgz__form' );
		var input = el( 'input', 'pgz__input' );
		input.type = inputType;
		input.setAttribute( 'aria-label', 'Your answer' );
		input.placeholder = 'Your answer';
		input.autocomplete = 'off';

		var button = el( 'button', 'pgz__btn', T.check );
		button.type = 'button';

		function send() {
			if ( ! input.value.trim() ) { return; }
			submit( { answer: input.value } );
		}

		button.addEventListener( 'click', send );
		input.addEventListener( 'keydown', function ( e ) {
			if ( e.key === 'Enter' ) { e.preventDefault(); send(); }
		} );

		row.append( input, button );
		stage.replaceChildren( prompt, row );
		input.focus();
	}

	/* =====================================================================
	 * Gate lifecycle
	 * ===================================================================== */

	function start( gate ) {
		var stage = gate.querySelector( '[data-pgz-stage]' );
		var intro = gate.querySelector( '.pgz__intro' );
		var trigger = gate.querySelector( '[data-pgz-start]' );

		trigger.disabled = true;
		say( gate, T.loading );

		api( '/challenge', {
			post_id: Number( gate.dataset.pgzPost ),
			gate_id: gate.dataset.pgzGate
		} ).then( function ( data ) {
			var render = renderers[ data.type ];
			if ( ! render ) { throw new Error( T.error ); }

			intro.hidden = true;
			stage.hidden = false;
			say( gate, '' );

			render( gate, stage, data, function ( payload ) {
				attempt( gate, data.token, payload );
			} );
		} ).catch( function ( err ) {
			trigger.disabled = false;
			say( gate, err.message || T.error, 'bad' );
		} );
	}

	function attempt( gate, token, payload ) {
		say( gate, '…' );

		api( '/solve', { token: token, payload: payload } )
			.then( function ( data ) {
				if ( data.solved ) {
					reveal( gate, data.html );
					return;
				}

				var message = T.wrong;
				if ( data.hint ) { message += ' ' + T.hint + ': ' + data.hint; }
				if ( typeof data.attempts === 'number' ) {
					message += ' (' + data.attempts + ' left)';
				}
				say( gate, message, 'bad' );
			} )
			.catch( function ( err ) {
				var message = err.code === 'pgz_expired' ? T.expired
					: ( err.code === 'pgz_rate_limited' || err.code === 'pgz_burned' ) ? T.locked
					: ( err.message || T.error );
				say( gate, message, 'bad' );
				offerRestart( gate );
			} );
	}

	function offerRestart( gate ) {
		if ( gate.querySelector( '[data-pgz-restart]' ) ) { return; }

		var again = el( 'button', 'pgz__btn pgz__btn--ghost', T.restart );
		again.type = 'button';
		again.setAttribute( 'data-pgz-restart', '' );
		again.addEventListener( 'click', function () { window.location.reload(); } );

		gate.querySelector( '[data-pgz-status]' ).after( again );
	}

	/* =====================================================================
	 * Confetti — tiny, dependency-free, and skipped for reduced motion
	 * ===================================================================== */

	function burst( gate ) {
		var canvas = el( 'canvas', 'pgz__confetti' );
		var host = gate.querySelector( '.pgz__revealed' ) || gate;
		host.style.position = 'relative';
		host.appendChild( canvas );

		var w = canvas.width = host.offsetWidth;
		var h = canvas.height = Math.min( 320, host.offsetHeight || 320 );
		var ctx = canvas.getContext( '2d' );
		var accent = getComputedStyle( gate ).getPropertyValue( '--pgz-accent' ).trim() || '#c9a227';
		var bits = [];

		for ( var i = 0; i < 70; i++ ) {
			bits.push( {
				x: w / 2, y: h / 3,
				vx: ( Math.random() - 0.5 ) * 9,
				vy: Math.random() * -9 - 2,
				r: Math.random() * 4 + 2,
				a: 1,
				c: i % 3 === 0 ? accent : ( i % 3 === 1 ? '#e6edf3' : '#8ea0b2' )
			} );
		}

		( function frame() {
			ctx.clearRect( 0, 0, w, h );
			var alive = false;

			bits.forEach( function ( b ) {
				b.x += b.vx;
				b.y += b.vy;
				b.vy += 0.32;   // gravity
				b.a -= 0.012;   // fade
				if ( b.a <= 0 ) { return; }
				alive = true;
				ctx.globalAlpha = b.a;
				ctx.fillStyle = b.c;
				ctx.fillRect( b.x, b.y, b.r, b.r * 1.6 );
			} );

			if ( alive ) {
				requestAnimationFrame( frame );
			} else {
				canvas.remove(); // clean up: no orphaned canvas eating memory
			}
		} )();
	}

	/* =====================================================================
	 * Boot
	 * ===================================================================== */

	function init() {
		var gates = document.querySelectorAll( '.pgz[data-pgz-gate]' );
		if ( ! gates.length ) { return; }

		// The readable flag cookie tells us a pass *might* exist. If it is absent
		// we skip the /reveal round trip entirely, which is the common case.
		var maybeUnlocked = hasCookie( 'pgz_has_pass' );

		gates.forEach( function ( gate ) {
			var trigger = gate.querySelector( '[data-pgz-start]' );
			if ( trigger ) {
				trigger.addEventListener( 'click', function () { start( gate ); } );
			}

			if ( maybeUnlocked ) {
				api( '/reveal', {
					post_id: Number( gate.dataset.pgzPost ),
					gate_id: gate.dataset.pgzGate
				} ).then( function ( data ) {
					if ( data.solved ) { reveal( gate, data.html ); }
				} ).catch( function () {
					/* Still locked. Nothing to do — the lock is already drawn. */
				} );
			}
		} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
