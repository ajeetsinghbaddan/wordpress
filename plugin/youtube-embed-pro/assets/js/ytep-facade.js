/**
 * Swaps the thumbnail button for a real iframe on click.
 * Nothing is loaded from YouTube until a visitor asks for it.
 */
( function () {
	'use strict';

	// Second line of defence: even though PHP built this URL, refuse to frame
	// anything that is not a YouTube embed path.
	var ALLOWED_SRC = /^https:\/\/(?:www\.youtube\.com|www\.youtube-nocookie\.com)\/embed\/[A-Za-z0-9_-]+(?:\?|$)/;

	function activate( poster ) {
		var src = poster.getAttribute( 'data-ytep-src' ) || '';

		if ( ! ALLOWED_SRC.test( src ) ) {
			return;
		}

		var iframe = document.createElement( 'iframe' );
		iframe.className = 'ytep__frame';
		// setAttribute keeps the value as data; it is never parsed as HTML.
		iframe.setAttribute( 'src', src );
		iframe.setAttribute( 'title', poster.getAttribute( 'data-ytep-title' ) || '' );
		iframe.setAttribute( 'referrerpolicy', 'strict-origin-when-cross-origin' );
		iframe.setAttribute( 'allow', 'accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share' );
		iframe.setAttribute( 'allowfullscreen', 'true' );

		poster.parentNode.replaceChild( iframe, poster );
		iframe.focus();
	}

	// Delegated listener, so embeds added later (AJAX, infinite scroll) work too.
	document.addEventListener( 'click', function ( event ) {
		var target = event.target;

		if ( ! target || ! target.closest ) {
			return;
		}

		var poster = target.closest( '.ytep__poster' );

		if ( poster ) {
			event.preventDefault();
			activate( poster );
		}
	} );
} )();
