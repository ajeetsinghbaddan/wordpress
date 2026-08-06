/**
 * Product Enquiry — modal controller. v1.0.1
 *
 * Changes from 1.0.0, and why:
 *  - The modal is looked up lazily on each click instead of once at parse time,
 *    so the script still works if an optimiser reorders it or the theme injects
 *    the product summary after page load.
 *  - The <dialog> is moved to <body> before its first open. A native dialog
 *    positions itself against the nearest ancestor carrying a transform/filter/
 *    will-change, and page builders add those constantly — the dialog then opens
 *    off-screen, which looks exactly like "nothing happened".
 *  - The click listener runs in the capture phase, so a theme handler calling
 *    stopPropagation() on the Add to cart form cannot swallow it.
 *  - Failures log a console warning instead of failing silently.
 */
( function () {
	'use strict';

	var config = window.wcpeConfig || {};
	var lastFocused = null;
	var closeTimer = null;
	var relocated = false;
	var warned = false;

	var FOCUSABLE =
		'a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

	/**
	 * Find the dialog. Called at click time, not at load time.
	 */
	function getModal() {
		var modal = document.getElementById( 'wcpe-modal' );

		if ( ! modal ) {
			if ( ! warned ) {
				warned = true;
				console.warn(
					'[Product Enquiry] #wcpe-modal is not in the DOM. The popup markup prints on wp_footer — check that your theme calls wp_footer() in footer.php, and that no optimiser is stripping it.'
				);
			}
			return null;
		}

		if ( ! relocated ) {
			relocated = true;

			// Reparent to <body> so no ancestor transform can capture the
			// dialog's containing block.
			if ( modal.parentNode !== document.body ) {
				document.body.appendChild( modal );
			}

			if ( typeof modal.showModal !== 'function' ) {
				modal.classList.add( 'wcpe-modal--fallback' );
				modal.setAttribute( 'role', 'dialog' );
				modal.setAttribute( 'aria-modal', 'true' );
			}

			bindModalEvents( modal );
		}

		return modal;
	}

	function supportsDialog( modal ) {
		return typeof modal.showModal === 'function';
	}

	function getFocusable( modal ) {
		return Array.prototype.filter.call( modal.querySelectorAll( FOCUSABLE ), function ( el ) {
			return el.offsetParent !== null; // Skips the honeypot and hidden fields.
		} );
	}

	function focusFirstField( modal ) {
		var fields = modal.querySelectorAll( '.wcpe-modal__body ' + FOCUSABLE );

		if ( fields.length ) {
			fields[ 0 ].focus();
			return;
		}

		var closeBtn = modal.querySelector( '[data-wcpe-close]' );
		if ( closeBtn ) {
			closeBtn.focus();
		}
	}

	function openModal( trigger ) {
		var modal = getModal();

		if ( ! modal ) {
			return;
		}

		lastFocused = trigger || document.activeElement;

		if ( supportsDialog( modal ) ) {
			try {
				modal.showModal();
			} catch ( err ) {
				// InvalidStateError if the dialog was somehow already open.
				console.warn( '[Product Enquiry] showModal() failed, using fallback.', err );
				modal.classList.add( 'wcpe-modal--fallback', 'is-open' );
				modal.setAttribute( 'open', '' );
			}
		} else {
			modal.setAttribute( 'open', '' );
			modal.classList.add( 'is-open' );
			document.addEventListener( 'keydown', fallbackKeydown );
		}

		document.body.classList.add( 'wcpe-modal-open' );
		focusFirstField( modal );
	}

	function closeModal() {
		var modal = document.getElementById( 'wcpe-modal' );

		window.clearTimeout( closeTimer );

		if ( modal ) {
			if ( supportsDialog( modal ) && modal.open ) {
				modal.close();
			} else {
				modal.removeAttribute( 'open' );
				modal.classList.remove( 'is-open' );
				document.removeEventListener( 'keydown', fallbackKeydown );
			}
		}

		document.body.classList.remove( 'wcpe-modal-open' );

		if ( lastFocused && typeof lastFocused.focus === 'function' ) {
			lastFocused.focus();
		}
	}

	/**
	 * Escape + Tab trapping for browsers without native <dialog>.
	 */
	function fallbackKeydown( event ) {
		var modal = document.getElementById( 'wcpe-modal' );

		if ( ! modal ) {
			return;
		}

		if ( 'Escape' === event.key ) {
			event.preventDefault();
			closeModal();
			return;
		}

		if ( 'Tab' !== event.key ) {
			return;
		}

		var focusable = getFocusable( modal );

		if ( ! focusable.length ) {
			return;
		}

		var first = focusable[ 0 ];
		var last = focusable[ focusable.length - 1 ];

		if ( event.shiftKey && document.activeElement === first ) {
			event.preventDefault();
			last.focus();
		} else if ( ! event.shiftKey && document.activeElement === last ) {
			event.preventDefault();
			first.focus();
		}
	}

	/**
	 * Listeners that belong to the dialog itself, attached once.
	 */
	function bindModalEvents( modal ) {
		// Backdrop click. With a native dialog, backdrop clicks report the
		// dialog as the target, so compare the pointer against its box.
		modal.addEventListener( 'click', function ( event ) {
			if ( event.target !== modal ) {
				return;
			}

			var box = modal.getBoundingClientRect();
			var inside =
				event.clientX >= box.left &&
				event.clientX <= box.right &&
				event.clientY >= box.top &&
				event.clientY <= box.bottom;

			if ( ! inside ) {
				closeModal();
			}
		} );

		// Native Escape fires `close` rather than our handler — clean up here too.
		modal.addEventListener( 'close', function () {
			document.body.classList.remove( 'wcpe-modal-open' );

			if ( lastFocused && typeof lastFocused.focus === 'function' ) {
				lastFocused.focus();
			}
		} );
	}

	/*
	 * Capture phase (the `true` argument): this handler runs on the way DOWN the
	 * tree, before any theme handler on the button or the cart form gets a
	 * chance to call stopPropagation().
	 */
	document.addEventListener(
		'click',
		function ( event ) {
			var target = event.target;

			// SVG icons and text nodes do not always expose closest().
			if ( ! target || typeof target.closest !== 'function' ) {
				target = target && target.parentElement;
			}

			if ( ! target || typeof target.closest !== 'function' ) {
				return;
			}

			var opener = target.closest( '[data-wcpe-open]' );

			if ( opener ) {
				event.preventDefault();
				event.stopPropagation(); // Don't let the theme also react to this click.
				openModal( opener );
				return;
			}

			if ( target.closest( '[data-wcpe-close]' ) ) {
				event.preventDefault();
				closeModal();
			}
		},
		true
	);

	/*
	 * Contact Form 7 fires a DOM event per submission outcome. Only the success
	 * event closes the popup — failures stay open so validation messages are readable.
	 */
	document.addEventListener( 'wpcf7mailsent', function ( event ) {
		var modal = document.getElementById( 'wcpe-modal' );

		if ( ! config.autoClose || ! modal || ! modal.contains( event.target ) ) {
			return;
		}

		closeTimer = window.setTimeout( closeModal, config.closeDelay || 2500 );
	} );
} )();
