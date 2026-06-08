<?php
/**
 * Shared gallery renderer + input sanitization.
 *
 * This single class is the ONE place that turns gallery settings into HTML.
 * The block, the shortcode, and the Elementor widget all call it, so the
 * markup, the security rules, and the layout logic live in exactly one spot
 * (the "Don't Repeat Yourself" principle).
 *
 * @package VersatileGallery
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class VGAL_Renderer {

	/**
	 * Whitelist of image sizes we accept.
	 *
	 * @return string[]
	 */
	private static function allowed_sizes() {
		$sizes   = get_intermediate_image_sizes();
		$sizes[] = 'full';
		return $sizes;
	}

	/**
	 * Whitelist of layouts. The user's choice MUST be one of these, otherwise
	 * we fall back to 'grid'. Because the value ends up inside a CSS class name
	 * (vgal-gallery--{layout}), whitelisting guarantees no attacker-controlled
	 * string can ever reach the markup.
	 *
	 * @return string[]
	 */
	private static function allowed_layouts() {
		return array( 'grid', 'masonry', 'justified', 'mosaic', 'carousel', 'captions' );
	}

	/**
	 * Coerce any "truthy" representation into a real boolean.
	 *
	 * @param mixed $value Raw value.
	 * @return bool
	 */
	private static function to_bool( $value ) {
		if ( is_bool( $value ) ) {
			return $value;
		}
		return in_array( strtolower( (string) $value ), array( '1', 'true', 'yes', 'on' ), true );
	}

	/**
	 * Sanitize raw attributes from ANY source into a trusted, typed array.
	 *
	 * This is the security gate. No matter who calls render() or what they
	 * pass, the data is forced into known types and safe ranges here.
	 *
	 * @param array $raw Raw input.
	 * @return array Sanitized attributes.
	 */
	public static function sanitize_atts( $raw ) {
		$raw = is_array( $raw ) ? $raw : array();

		// IDs: accept "1,2,3" or an array; force each to a positive integer.
		$ids = array();
		if ( isset( $raw['ids'] ) ) {
			$list = is_array( $raw['ids'] ) ? $raw['ids'] : explode( ',', (string) $raw['ids'] );
			foreach ( $list as $id ) {
				$id = absint( $id );
				if ( $id > 0 ) {
					$ids[] = $id;
				}
			}
		}
		$ids = array_values( array_unique( $ids ) );

		// Columns: integer, clamped to a sane range.
		$columns = isset( $raw['columns'] ) ? absint( $raw['columns'] ) : 3;
		$columns = max( 1, min( 6, $columns ) );

		// Gap in pixels: integer, clamped.
		$gap = isset( $raw['gap'] ) ? absint( $raw['gap'] ) : 12;
		$gap = max( 0, min( 80, $gap ) );

		// Size: must be a registered, whitelisted size.
		$size = isset( $raw['size'] ) ? sanitize_key( $raw['size'] ) : 'medium';
		if ( ! in_array( $size, self::allowed_sizes(), true ) ) {
			$size = 'medium';
		}

		// Layout: must be a whitelisted layout.
		$layout = isset( $raw['layout'] ) ? sanitize_key( $raw['layout'] ) : 'grid';
		if ( ! in_array( $layout, self::allowed_layouts(), true ) ) {
			$layout = 'grid';
		}

		// Lightbox: strict boolean.
		$lightbox = isset( $raw['lightbox'] ) ? self::to_bool( $raw['lightbox'] ) : true;

		return array(
			'ids'      => $ids,
			'columns'  => $columns,
			'gap'      => $gap,
			'size'     => $size,
			'layout'   => $layout,
			'lightbox' => $lightbox,
		);
	}

	/**
	 * Build the gallery HTML.
	 *
	 * Rule of thumb: sanitize on input (above), ESCAPE on output (below).
	 * The chosen layout only changes (a) the wrapper's CSS class and, for two
	 * layouts, a little per-item data. All the visual heavy-lifting is done by
	 * CSS in gallery.css, so PHP stays fast and simple.
	 *
	 * @param array $raw Raw attributes from a consumer.
	 * @return string HTML that is safe to echo.
	 */
	public static function render( $raw ) {
		$atts = self::sanitize_atts( $raw );

		if ( empty( $atts['ids'] ) ) {
			return '';
		}

		wp_enqueue_style( 'versatile-gallery' );
		if ( $atts['lightbox'] ) {
			wp_enqueue_script( 'versatile-gallery' );
		}

		$layout       = $atts['layout'];
		$is_justified = ( 'justified' === $layout );
		$is_captions  = ( 'captions' === $layout );

		// CSS custom properties drive the grid; the same stylesheet handles
		// every layout/column/gap combination.
		$style = sprintf( '--vgal-columns:%d;--vgal-gap:%dpx;', $atts['columns'], $atts['gap'] );

		$lightbox_attr = $atts['lightbox'] ? ' data-vgal-lightbox="1"' : '';

		$items = '';
		foreach ( $atts['ids'] as $id ) {
			$img = wp_get_attachment_image(
				$id,
				$atts['size'],
				false,
				array(
					'class'   => 'vgal-image',
					'loading' => 'lazy',
				)
			);

			if ( ! $img ) {
				continue; // attachment deleted — skip safely.
			}

			// Justified rows need each tile's aspect ratio so flexbox can size
			// the rows. We compute it ONCE here from the attachment metadata and
			// pass it to CSS via a custom property; no JavaScript, no on-load
			// measuring loop. CSS then does: flex-grow/flex-basis from --vgal-ratio.
			$item_style = '';
			if ( $is_justified ) {
				$meta = wp_get_attachment_image_src( $id, $atts['size'] );
				if ( $meta && ! empty( $meta[2] ) ) {
					$ratio      = $meta[1] / $meta[2]; // width / height
					$item_style = sprintf(
						' style="--vgal-ratio:%s"',
						esc_attr( number_format( $ratio, 3, '.', '' ) )
					);
				}
			}

			// The captions layout shows the image's caption (or title) on hover.
			$caption_html = '';
			if ( $is_captions ) {
				$caption = wp_get_attachment_caption( $id );
				if ( ! $caption ) {
					$caption = get_the_title( $id );
				}
				if ( $caption ) {
					$caption_html = sprintf( '<span class="vgal-caption">%s</span>', esc_html( $caption ) );
				}
			}

			if ( $atts['lightbox'] ) {
				$full   = wp_get_attachment_image_url( $id, 'full' );
				$items .= sprintf(
					'<a class="vgal-item" href="%s"%s>%s%s</a>',
					esc_url( $full ),
					$item_style,
					$img,
					$caption_html
				);
			} else {
				$items .= sprintf(
					'<figure class="vgal-item"%s>%s%s</figure>',
					$item_style,
					$img,
					$caption_html
				);
			}
		}

		if ( '' === $items ) {
			return '';
		}

		// $layout is whitelisted in sanitize_atts(); esc_attr() is belt-and-braces.
		$classes = sprintf( 'vgal-gallery vgal-gallery--%s', $layout );

		return sprintf(
			'<div class="%s" style="%s"%s>%s</div>',
			esc_attr( $classes ),
			esc_attr( $style ),
			$lightbox_attr,
			$items
		);
	}
}
