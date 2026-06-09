/**
 * Quiz Certify - listing page behaviour.
 *
 * Progressive enhancement over the server-rendered grid:
 *   - Clicking a quiz fetches just that quiz over AJAX and swaps it in place (no reload).
 *   - The URL updates to ?quiz=ID via pushState, so the view is shareable and the
 *     browser Back button returns to the grid.
 *   - Without JavaScript the same links are real ?quiz=ID URLs the server handles, so
 *     the feature degrades gracefully.
 *
 * The injected quiz works without re-init because the quiz form uses a delegated submit
 * handler (see quiz-frontend.js).
 */
( function () {
	'use strict';

	if ( typeof QuizCertify === 'undefined' ) {
		return;
	}

	var i18n = QuizCertify.i18n;
	var reduceMotion = window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;

	function currentQuizParam() {
		var v = new URLSearchParams( window.location.search ).get( 'quiz' );
		return v ? v.replace( /[^0-9]/g, '' ) : '';
	}
	function urlForQuiz( id ) {
		var p = new URLSearchParams( window.location.search );
		p.set( 'quiz', String( id ).replace( /[^0-9]/g, '' ) );
		return window.location.pathname + '?' + p.toString();
	}
	function urlForList() {
		var p = new URLSearchParams( window.location.search );
		p.delete( 'quiz' );
		var q = p.toString();
		return window.location.pathname + ( q ? '?' + q : '' );
	}
	function scrollTo( el ) {
		if ( el && el.scrollIntoView ) {
			el.scrollIntoView( { behavior: reduceMotion ? 'auto' : 'smooth', block: 'start' } );
		}
	}

	document.querySelectorAll( '.qc-quiz-list' ).forEach( function ( listEl ) {
		var grid  = listEl.querySelector( '.qc-list-grid' );
		var panel = listEl.querySelector( '.qc-list-quiz' );

		// Only the grid view is enhanced. The single-quiz server view has no grid and its
		// back link is a normal navigation.
		if ( ! grid || ! panel ) {
			return;
		}

		grid.addEventListener( 'click', function ( e ) {
			var link = e.target.closest( '.qc-list-start' );
			if ( ! link ) {
				return;
			}
			e.preventDefault();
			var id = link.getAttribute( 'data-quiz-id' );
			if ( ! id ) {
				return;
			}
			history.pushState( { qcQuiz: id }, '', urlForQuiz( id ) );
			loadQuiz( id );
		} );

		window.addEventListener( 'popstate', function () {
			var id = currentQuizParam();
			if ( id ) {
				loadQuiz( id );
			} else {
				showGrid();
			}
		} );

		// If the page was opened directly on ?quiz=ID but the grid still rendered (e.g.
		// an invalid id fell through), make sure we show the grid.
		function loadQuiz( id ) {
			showLoading();

			var data = new FormData();
			data.append( 'action', 'qc_load_quiz' );
			data.append( 'nonce', QuizCertify.listNonce );
			data.append( 'quiz_id', id );

			fetch( QuizCertify.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				body: data
			} )
				.then( function ( r ) { return r.json(); } )
				.then( function ( res ) {
					if ( ! res || ! res.success ) {
						showError();
						return;
					}
					showQuiz( res.data.html );
				} )
				.catch( showError );
		}

		function showLoading() {
			grid.hidden = true;
			panel.hidden = false;
			panel.innerHTML = '';
			var p = document.createElement( 'p' );
			p.className = 'qc-list-loading';
			p.textContent = i18n.loading;
			panel.appendChild( p );
			scrollTo( panel );
		}

		function showError() {
			panel.innerHTML = '';
			panel.appendChild( backButton() );
			var p = document.createElement( 'p' );
			p.className = 'qc-form-error';
			p.textContent = i18n.loadError;
			panel.appendChild( p );
		}

		function showQuiz( html ) {
			panel.innerHTML = '';
			panel.appendChild( backButton() );

			// Parse the returned markup and move its nodes into the panel.
			var holder = document.createElement( 'div' );
			holder.innerHTML = html;
			while ( holder.firstChild ) {
				panel.appendChild( holder.firstChild );
			}

			// Move focus into the quiz for keyboard/screen-reader users.
			var q = panel.querySelector( '.qc-quiz' );
			if ( q ) {
				q.setAttribute( 'tabindex', '-1' );
				q.focus( { preventScroll: true } );
			}
			scrollTo( panel );
		}

		function showGrid() {
			panel.hidden = true;
			panel.innerHTML = '';
			grid.hidden = false;
			scrollTo( listEl );
		}

		function backButton() {
			var btn = document.createElement( 'button' );
			btn.type = 'button';
			btn.className = 'qc-list-back';
			btn.textContent = i18n.backToList;
			btn.addEventListener( 'click', function () {
				history.pushState( {}, '', urlForList() );
				showGrid();
			} );
			return btn;
		}
	} );
} )();
