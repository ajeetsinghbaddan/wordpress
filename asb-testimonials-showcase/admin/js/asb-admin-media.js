/**
 * Admin: wire the "Select photo" button to the WordPress Media Library.
 *
 * This uses wp.media (loaded via wp_enqueue_media() in PHP) to open the standard
 * media modal. When the user picks an image we store its attachment ID in the
 * hidden field (which our save routine reads and validates server-side) and show
 * a thumbnail preview. We never trust the client value alone — PHP re-checks
 * that the ID is a real image attachment before saving.
 */
( function ( $ ) {
	'use strict';

	$( function () {
		var frame; // Reuse a single media frame instance.

		var $wrap    = $( '.asb-ts-fields' );
		var $idField = $( '#asb_ts_photo_id' );
		var $preview = $( '.asb-ts-photo-preview' );

		// Open the media modal when "Select photo" is clicked.
		$wrap.on( 'click', '.asb-ts-photo-upload', function ( e ) {
			e.preventDefault();

			// If we already built the frame, just reopen it.
			if ( frame ) {
				frame.open();
				return;
			}

			// Build the media frame, restricting it to images only.
			frame = wp.media( {
				title: ( window.asbTsMedia && asbTsMedia.title ) || 'Select photo',
				button: { text: ( window.asbTsMedia && asbTsMedia.button ) || 'Use this photo' },
				library: { type: 'image' }, // Only show images in the picker.
				multiple: false
			} );

			// When an image is chosen, capture its ID + thumbnail URL.
			frame.on( 'select', function () {
				var attachment = frame.state().get( 'selection' ).first().toJSON();

				$idField.val( attachment.id );

				// Prefer the small "thumbnail" size for the preview if available.
				var url = attachment.url;
				if ( attachment.sizes && attachment.sizes.thumbnail ) {
					url = attachment.sizes.thumbnail.url;
				}

				$preview.html( $( '<img>', { src: url, alt: '' } ) );
				$( '.asb-ts-photo-remove' ).show();
			} );

			frame.open();
		} );

		// Clear the selection.
		$wrap.on( 'click', '.asb-ts-photo-remove', function ( e ) {
			e.preventDefault();
			$idField.val( '' );
			$preview.empty();
			$( this ).hide();
		} );
	} );
} )( jQuery );
