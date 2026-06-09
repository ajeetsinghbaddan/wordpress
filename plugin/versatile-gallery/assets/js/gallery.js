/**
 * Versatile Gallery — frontend lightbox.
 *
 * Vanilla JS, no jQuery. It uses ONE click listener on the document (event
 * delegation) that handles every gallery on the page, including galleries
 * added dynamically. Image sources come from the anchor's href, which the
 * server already passed through esc_url(), so no untrusted markup is injected.
 */
( function () {
	'use strict';

	var lightbox    = null;
	var lightboxImg = null;
	var lastFocused = null;

	// Build the overlay once, the first time it is needed, and reuse it.
	function buildLightbox() {
		if ( lightbox ) {
			return;
		}

		lightbox = document.createElement( 'div' );
		lightbox.className = 'vgal-lightbox';
		lightbox.setAttribute( 'role', 'dialog' );
		lightbox.setAttribute( 'aria-modal', 'true' );

		var closeBtn = document.createElement( 'button' );
		closeBtn.className = 'vgal-lightbox-close';
		closeBtn.setAttribute( 'type', 'button' );
		closeBtn.setAttribute( 'aria-label', 'Close' );
		closeBtn.innerHTML = '&times;';

		lightboxImg = document.createElement( 'img' );
		lightboxImg.alt = '';

		lightbox.appendChild( closeBtn );
		lightbox.appendChild( lightboxImg );
		document.body.appendChild( lightbox );

		closeBtn.addEventListener( 'click', closeLightbox );
		// Clicking the dark backdrop (but not the image) closes the lightbox.
		lightbox.addEventListener( 'click', function ( e ) {
			if ( e.target === lightbox ) {
				closeLightbox();
			}
		} );
	}

	function openLightbox( url, alt ) {
		if ( ! url ) {
			return;
		}
		buildLightbox();
		lastFocused      = document.activeElement; // remember focus to restore later
		lightboxImg.src  = url;
		lightboxImg.alt  = alt || '';
		// requestAnimationFrame lets the element paint before we add the class,
		// so the CSS opacity transition actually animates.
		window.requestAnimationFrame( function () {
			lightbox.classList.add( 'is-open' );
		} );
		document.addEventListener( 'keydown', onKeydown );
	}

	function closeLightbox() {
		if ( ! lightbox ) {
			return;
		}
		lightbox.classList.remove( 'is-open' );
		document.removeEventListener( 'keydown', onKeydown );
		if ( lastFocused && typeof lastFocused.focus === 'function' ) {
			lastFocused.focus(); // return focus where it was (accessibility)
		}
	}

	function onKeydown( e ) {
		if ( 'Escape' === e.key ) {
			closeLightbox();
		}
	}

	document.addEventListener( 'click', function ( e ) {
		if ( ! e.target || ! e.target.closest ) {
			return;
		}
		// Only react to clicks inside a lightbox-enabled gallery item.
		var link = e.target.closest( '.vgal-gallery[data-vgal-lightbox="1"] .vgal-item' );
		if ( ! link || 'A' !== link.tagName ) {
			return;
		}
		e.preventDefault();
		var img = link.querySelector( 'img' );
		openLightbox( link.getAttribute( 'href' ), img ? img.getAttribute( 'alt' ) : '' );
	} );
} )();
