/**
 * Quiz Certify - front-end quiz behaviour.
 *
 * Uses ONE delegated submit listener on the document instead of binding each form at
 * load. That matters because the listing page injects a quiz's HTML after the page has
 * loaded; delegation means those injected quizzes work with no re-initialisation.
 *
 * Grading happens entirely on the server — this script never sees the answer key. All
 * user-facing strings come from QuizCertify.i18n so they stay translatable.
 */
( function () {
	'use strict';

	if ( typeof QuizCertify === 'undefined' ) {
		return;
	}

	var i18n = QuizCertify.i18n;

	// Substitutes %s / %d / %1$d-style tokens and unescapes %%.
	function fmt( str ) {
		var args = Array.prototype.slice.call( arguments, 1 );
		var auto = 0;
		return String( str )
			.replace( /%(\d+)\$[sd]/g, function ( m, n ) { return args[ n - 1 ]; } )
			.replace( /%[sd]/g, function () { return args[ auto++ ]; } )
			.replace( /%%/g, '%' );
	}

	function emailLooksValid( value ) {
		return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test( value );
	}

	// Delegated submit handler. Bound once; handles every quiz form, current or future.
	document.addEventListener( 'submit', function ( e ) {
		var form = e.target;
		if ( ! form || ! form.classList || ! form.classList.contains( 'qc-quiz-form' ) ) {
			return;
		}
		e.preventDefault();
		handleSubmit( form );
	} );

	function handleSubmit( form ) {
		var quizEl = form.closest( '.qc-quiz' );
		if ( ! quizEl ) {
			return;
		}

		var resultEl = quizEl.querySelector( '.qc-result' );
		var errorEl  = quizEl.querySelector( '.qc-form-error' );
		var quizId   = quizEl.getAttribute( 'data-quiz-id' );

		errorEl.textContent = '';

		var nameInput  = form.querySelector( '.qc-name-input' );
		var emailInput = form.querySelector( '.qc-email-input' );
		var name  = nameInput ? nameInput.value.trim() : '';
		var email = emailInput ? emailInput.value.trim() : '';

		if ( ! name ) {
			errorEl.textContent = i18n.nameReq;
			if ( nameInput ) { nameInput.focus(); }
			return;
		}

		if ( ! emailLooksValid( email ) ) {
			errorEl.textContent = i18n.emailReq;
			if ( emailInput ) { emailInput.focus(); }
			return;
		}

		var data = new FormData();
		data.append( 'action', 'qc_submit_quiz' );
		data.append( 'nonce', QuizCertify.nonce );
		data.append( 'quiz_id', quizId );
		data.append( 'user_name', name );
		data.append( 'user_email', email );

		form.querySelectorAll( 'input[type="radio"]:checked, input[type="checkbox"]:checked' ).forEach(
			function ( input ) {
				data.append( 'answers[' + input.getAttribute( 'data-question' ) + '][]', input.value );
			}
		);

		var submitBtn = form.querySelector( '.qc-submit-btn' );
		submitBtn.disabled = true;
		submitBtn.textContent = i18n.submitting;

		fetch( QuizCertify.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: data
		} )
			.then( function ( r ) { return r.json(); } )
			.then( function ( res ) {
				if ( ! res || ! res.success ) {
					errorEl.textContent = ( res && res.data && res.data.message ) ? res.data.message : i18n.error;
					resetButton( submitBtn );
					return;
				}
				renderResult( res.data, form, resultEl );
			} )
			.catch( function () {
				errorEl.textContent = i18n.error;
				resetButton( submitBtn );
			} );
	}

	function renderResult( d, form, resultEl ) {
		form.hidden = true;
		resultEl.innerHTML = '';
		resultEl.hidden = false;
		resultEl.classList.toggle( 'qc-pass', !! d.passed );
		resultEl.classList.toggle( 'qc-fail', ! d.passed );

		var badge = document.createElement( 'div' );
		badge.className = 'qc-result-badge';
		badge.textContent = d.passed ? '\u2713' : '\u2715';
		resultEl.appendChild( badge );

		var heading = document.createElement( 'h3' );
		heading.className = 'qc-result-heading';
		// d.name is placed via textContent, so it cannot inject markup.
		heading.textContent = d.passed ? fmt( i18n.congrats, d.name ) : i18n.notQuite;
		resultEl.appendChild( heading );

		var scoreLine = document.createElement( 'p' );
		scoreLine.className = 'qc-result-score';
		scoreLine.textContent = fmt( i18n.scored, d.score, d.total, d.percentage );
		resultEl.appendChild( scoreLine );

		var sub = document.createElement( 'p' );
		sub.className = 'qc-result-sub';
		sub.textContent = d.passed ? fmt( i18n.metPass, d.passMark ) : fmt( i18n.missedPass, d.passMark );
		resultEl.appendChild( sub );

		if ( d.passed && d.certificate ) {
			var certBtn = document.createElement( 'a' );
			certBtn.className = 'qc-cert-btn wp-element-button button';
			certBtn.href = d.certificate;
			certBtn.target = '_blank';
			certBtn.rel = 'noopener';
			certBtn.textContent = i18n.viewCert;
			resultEl.appendChild( certBtn );
		}

		resultEl.scrollIntoView( { behavior: 'smooth', block: 'center' } );
	}

	function resetButton( btn ) {
		btn.disabled = false;
		btn.textContent = i18n.submitLabel;
	}
} )();
