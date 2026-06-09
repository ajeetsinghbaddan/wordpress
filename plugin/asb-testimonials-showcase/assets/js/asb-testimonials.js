/**
 * ASB Testimonials Showcase — front-end behaviour (jQuery).
 *
 * Why jQuery here? WordPress bundles jQuery, and using it (a) avoids any
 * `NodeList.forEach`/older-browser gaps, and (b) sidesteps the classic conflict
 * where `$` is undefined because WordPress runs jQuery in "noConflict" mode.
 * We therefore wrap everything in `( function ( $ ) { ... } )( jQuery );` so `$`
 * is safely scoped to jQuery inside this file, and we boot on `$(document).ready`.
 *
 * Robustness decision: rather than trust the stylesheet to win the layout, the
 * slider's flex layout is ALSO enforced here as INLINE styles set with
 * `setProperty(..., 'important')`. Inline important styles beat any theme
 * stylesheet rule, so the carousel becomes a horizontal row even if a theme is
 * fighting our CSS. This is the "something is clashing with WordPress" fix.
 */
( function ( $ ) {
	'use strict';

	// Honour reduced-motion users (skip auto-rotation / smooth scrolling).
	var REDUCE_MOTION = window.matchMedia &&
		window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;

	// Gap (px) between slides — mirrors the CSS --asb-ts-gap default.
	var GAP = 20;

	/**
	 * How many slides should be visible — based on the TRACK (container) width,
	 * not the viewport. This is the fix for cramped cards: a slider inside a
	 * narrow content column shows fewer, wider cards even on a large screen.
	 *
	 * @param {HTMLElement} track The scrolling track element.
	 * @return {number} 1, 2 or 3.
	 */
	function slidesPerView( track ) {
		var w = track.clientWidth || window.innerWidth || 0;
		if ( w >= 900 ) { return 3; }
		if ( w >= 580 ) { return 2; }
		return 1;
	}

	/**
	 * Force the track into a horizontal flex row and size each slide.
	 *
	 * Everything here is applied as inline `!important` so no theme stylesheet
	 * can override it. This is what guarantees the slider never collapses into a
	 * vertical stack regardless of the active theme.
	 *
	 * @param {jQuery} $root The slider wrapper.
	 */
	function enforceLayout( $root ) {
		var track = $root.find( '[data-asb-ts-track]' ).get( 0 );
		if ( ! track ) {
			return;
		}

		track.style.setProperty( 'display', 'flex', 'important' );
		track.style.setProperty( 'flex-direction', 'row', 'important' );
		track.style.setProperty( 'flex-wrap', 'nowrap', 'important' );
		track.style.setProperty( 'overflow-x', 'auto', 'important' );
		track.style.setProperty( 'gap', GAP + 'px', 'important' );

		var per = slidesPerView( track );

		$root.find( '.asb-ts-slide' ).each( function () {
			// Each slide takes an equal share of the row, minus its share of gaps.
			var basis = 'calc(' + ( 100 / per ) + '% - ' + ( GAP * ( per - 1 ) / per ) + 'px)';
			this.style.setProperty( 'flex', '0 0 ' + basis, 'important' );
			this.style.setProperty( 'max-width', basis, 'important' );
			this.style.setProperty( 'min-width', '0', 'important' );
		} );
	}

	/**
	 * Enhance one slider: enforce layout, then add buttons + keyboard scrolling.
	 *
	 * Scrolling uses jQuery's .animate() on scrollLeft, which is broadly
	 * compatible and avoids smooth-scroll inconsistencies between browsers.
	 */
	function initSlider() {
		var $root  = $( this );
		var $track = $root.find( '[data-asb-ts-track]' ).first();
		if ( ! $track.length ) {
			return;
		}

		enforceLayout( $root );

		// Distance to scroll for one "page": one slide width + gap.
		function stepPx() {
			var $slide = $track.find( '.asb-ts-slide' ).first();
			if ( ! $slide.length ) {
				return $track.width();
			}
			return $slide.outerWidth() + GAP;
		}

		function move( direction ) {
			var target = $track.scrollLeft() + ( direction * stepPx() );
			if ( REDUCE_MOTION ) {
				$track.scrollLeft( target );
			} else {
				$track.stop( true ).animate( { scrollLeft: target }, 350 );
			}
		}

		$root.find( '[data-asb-ts-next]' ).on( 'click', function () {
			move( 1 );
		} );
		$root.find( '[data-asb-ts-prev]' ).on( 'click', function () {
			move( -1 );
		} );

		// Keyboard: focus the track, use Left/Right arrows.
		$track.attr( 'tabindex', '0' ).on( 'keydown', function ( e ) {
			if ( 'ArrowRight' === e.key || 39 === e.which ) {
				e.preventDefault();
				move( 1 );
			} else if ( 'ArrowLeft' === e.key || 37 === e.which ) {
				e.preventDefault();
				move( -1 );
			}
		} );

		// Recompute slide widths when the viewport changes (debounced).
		var resizeTimer;
		$( window ).on( 'resize.asbts', function () {
			window.clearTimeout( resizeTimer );
			resizeTimer = window.setTimeout( function () {
				enforceLayout( $root );
			}, 150 );
		} );
	}

	/**
	 * Enhance one spotlight: rotate a single quote, with dot navigation and
	 * pause-on-interaction. Uses jQuery .prop('hidden', …) to toggle slides.
	 */
	function initSpotlight() {
		var $root  = $( this );
		var $items = $root.find( '[data-asb-ts-item]' );
		var $dots  = $root.find( '[data-asb-ts-dot]' );
		if ( $items.length < 2 ) {
			return;
		}

		var current = 0;
		var timer   = null;

		function show( index ) {
			$items.each( function ( i ) {
				$( this ).prop( 'hidden', i !== index );
			} );
			$dots.each( function ( i ) {
				$( this ).attr( 'aria-selected', i === index ? 'true' : 'false' );
			} );
			current = index;
		}

		$dots.each( function ( i ) {
			$( this ).on( 'click', function () {
				show( i );
				restart();
			} );
		} );

		function advance() {
			show( ( current + 1 ) % $items.length );
		}

		function start() {
			if ( ! REDUCE_MOTION && null === timer ) {
				timer = window.setInterval( advance, 6000 );
			}
		}

		function stop() {
			if ( timer ) {
				window.clearInterval( timer );
				timer = null;
			}
		}

		function restart() {
			stop();
			start();
		}

		// Pause while hovered or keyboard-focused.
		$root.on( 'mouseenter focusin', stop );
		$root.on( 'mouseleave focusout', start );

		start();
	}

	// Boot once the DOM is ready (script is enqueued in the footer with the
	// jQuery dependency, so both jQuery and the markup are guaranteed present).
	$( function () {
		$( '[data-asb-ts-slider]' ).each( initSlider );
		$( '[data-asb-ts-spotlight]' ).each( initSpotlight );
	} );
} )( jQuery );
