/**
 * Quiz Certify - admin question editor behaviour.
 *
 * Handles three interactions on the quiz edit screen:
 *   1. Add a new question by cloning a hidden template.
 *   2. Remove a question.
 *   3. Keep the "correct answer" checkboxes consistent with the chosen answer type
 *      (only one may be ticked when the question allows a single correct answer).
 */
( function () {
	'use strict';

	var wrap = document.getElementById( 'qc-questions-wrap' );
	var template = document.getElementById( 'qc-question-template' );
	var addBtn = document.getElementById( 'qc-add-question' );

	if ( ! wrap || ! template || ! addBtn ) {
		return;
	}

	// Each question's field names are keyed by an index. We start the counter past any
	// existing questions so new rows never collide with saved ones.
	var counter = wrap.querySelectorAll( '.qc-question' ).length;

	addBtn.addEventListener( 'click', function () {
		// The template stores its HTML as text with __INDEX__ placeholders. Swap them
		// for the next unique number, then insert the result.
		var html = template.innerHTML.replace( /__INDEX__/g, String( counter ) );
		var holder = document.createElement( 'div' );
		holder.innerHTML = html.trim();
		var node = holder.firstElementChild;
		wrap.appendChild( node );
		counter++;
		relabel();
	} );

	// Event delegation: one listener on the wrapper handles clicks on any current or
	// future remove button, since rows are added dynamically.
	wrap.addEventListener( 'click', function ( e ) {
		if ( e.target.classList.contains( 'qc-remove-question' ) ) {
			var q = e.target.closest( '.qc-question' );
			if ( q ) {
				q.remove();
				relabel();
			}
		}
	} );

	// When a question is single-answer, ticking one correct option clears the rest.
	wrap.addEventListener( 'change', function ( e ) {
		if ( e.target.classList.contains( 'qc-correct-toggle' ) ) {
			var question = e.target.closest( '.qc-question' );
			var typeSelect = question.querySelector( '.qc-answer-type' );
			if ( typeSelect && 'single' === typeSelect.value && e.target.checked ) {
				question.querySelectorAll( '.qc-correct-toggle' ).forEach( function ( cb ) {
					if ( cb !== e.target ) {
						cb.checked = false;
					}
				} );
			}
		}
	} );

	// Number the visible questions (1, 2, 3…) for readability. This is display only;
	// the saved field indexes are independent.
	function relabel() {
		wrap.querySelectorAll( '.qc-question' ).forEach( function ( q, i ) {
			var label = q.querySelector( '.qc-question-label' );
			if ( label ) {
				label.textContent = 'Question ' + ( i + 1 );
			}
		} );
	}

	relabel();
} )();
