/**
 * Guest Post Submissions - front-end enhancements.
 *
 * IMPORTANT PRINCIPLE: everything in this file is PROGRESSIVE ENHANCEMENT.
 *
 * If this script fails to load, is blocked, or throws, the form still works
 * end to end -- because every rule enforced here is also enforced on the
 * server. Client-side checks exist to give fast feedback, never to protect
 * anything. Anyone can open devtools and delete this file's effects; that is
 * exactly why the server never trusts them.
 *
 * Written as a plain IIFE with no dependencies. Loading jQuery for a word
 * counter would be an unnecessary 90 KB on the visitor's connection.
 */
( function () {
	'use strict';

	// wp_localize_script injects this. Guard anyway: if the handle failed to
	// load, we bail silently rather than throwing on every keystroke.
	var config = window.gpsFormConfig || {};
	var i18n = config.i18n || {};

	var form = document.querySelector( '.gps-form' );

	if ( ! form ) {
		return;
	}

	/* --------------------------------------------------------------------
	 * Live word counter
	 * ------------------------------------------------------------------ */

	var contentField = form.querySelector( '#gps_content' );
	var counter = form.querySelector( '[data-gps-counter]' );

	function countWords( text ) {
		var trimmed = text.trim();

		if ( ! trimmed ) {
			return 0;
		}

		// Same splitting rule as the PHP side, including the non-breaking
		// space, so the browser and the server never disagree on the count.
		return trimmed.split( /[\s\u00A0]+/ ).length;
	}

	function updateCounter() {
		if ( ! counter || ! contentField ) {
			return;
		}

		var words = countWords( contentField.value );
		var min = config.minWords || 0;
		var max = config.maxWords || Infinity;

		counter.textContent = ' — ' + words + ' ' + ( i18n.words || 'words' );

		// classList.toggle with a second argument adds or removes based on the
		// boolean, avoiding a branch.
		counter.classList.toggle( 'gps-counter--over', words > max || ( words > 0 && words < min ) );
	}

	if ( contentField ) {
		/*
		 * 'input' fires on typing, pasting, and undo. 'keyup' would miss
		 * right-click paste, which is exactly how people move a draft in from
		 * Google Docs.
		 */
		contentField.addEventListener( 'input', updateCounter );
		updateCounter();
	}

	/* --------------------------------------------------------------------
	 * Image size pre-check
	 * ------------------------------------------------------------------ */

	var imageField = form.querySelector( '#gps_image' );

	if ( imageField && config.maxImageKb ) {
		imageField.addEventListener( 'change', function () {
			var file = imageField.files && imageField.files[ 0 ];

			if ( ! file ) {
				return;
			}

			// Catching this here saves the visitor uploading 8 MB over mobile
			// data only to be told no. The server still checks.
			if ( file.size > config.maxImageKb * 1024 ) {
				window.alert( i18n.imageTooBig || 'That image is too large.' );
				imageField.value = '';
			}
		} );
	}

	/* --------------------------------------------------------------------
	 * Double-submit guard
	 * ------------------------------------------------------------------ */

	var submitButton = form.querySelector( '.gps-button' );
	var isSubmitting = false;

	form.addEventListener( 'submit', function ( event ) {
		if ( isSubmitting ) {
			// Impatient double-clicks are a real source of duplicate posts.
			event.preventDefault();
			return;
		}

		isSubmitting = true;

		if ( submitButton ) {
			/*
			 * NOTE: we set `disabled` AFTER submission has begun, and we never
			 * disable the button before the browser has serialised the form --
			 * a disabled control is excluded from the submitted data. Since
			 * this button carries no name/value, it is safe here, but the
			 * ordering habit matters.
			 */
			submitButton.setAttribute( 'aria-busy', 'true' );
			submitButton.disabled = true;
			submitButton.textContent = i18n.sending || 'Sending…';
		}
	} );

	/* --------------------------------------------------------------------
	 * Move focus to the error summary after a failed submission
	 * ------------------------------------------------------------------ */

	var errorBox = document.querySelector( '.gps-alert--error' );

	if ( errorBox ) {
		// Without this, a keyboard or screen-reader user lands back at the top
		// of the page with no indication that anything went wrong.
		errorBox.focus();
	}
}() );
