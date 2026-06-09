<?php
/**
 * Turns a set of options into safe HTML for any of the six layouts.
 *
 * @package ASB_Testimonials_Showcase
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ASB_TS_Renderer
 *
 * This is the single source of truth for front-end HTML. The shortcode, the
 * Gutenberg block and the Elementor widget all collect options and then call
 * $renderer->render( $args ). Centralising output means:
 *   - All escaping lives in ONE place (easy to audit for security).
 *   - The three embed methods are guaranteed to look identical.
 *
 * Output-side security principle: escape EVERYTHING at the point of output.
 *   - esc_html()  for text shown to users.
 *   - esc_attr()  for HTML attribute values.
 *   - esc_url()   for URLs (image src, etc.).
 *   - wp_kses_post() for the testimonial body which may contain limited HTML.
 */
class ASB_TS_Renderer {

	/**
	 * The assets manager, injected so the renderer can enqueue CSS/JS on demand.
	 *
	 * @var ASB_TS_Assets
	 */
	private $assets;

	/**
	 * Counter to give every rendered group a unique DOM id (needed for ARIA
	 * relationships and for the JS to target a specific slider/spotlight).
	 *
	 * @var int
	 */
	private static $instance_count = 0;

	/**
	 * @param ASB_TS_Assets $assets The shared assets manager.
	 */
	public function __construct( $assets ) {
		$this->assets = $assets;
	}

	/**
	 * The whitelist of valid design keys mapped to human labels.
	 *
	 * Used both to render and to VALIDATE incoming design values everywhere
	 * (settings, shortcode, block, widget). If a value isn't a key here, it's
	 * rejected — a simple, robust allow-list.
	 *
	 * @return array
	 */
	public static function get_designs() {
		return array(
			'grid'      => __( 'Classic card grid', 'asb-testimonials-showcase' ),
			'slider'    => __( 'Horizontal slider / carousel', 'asb-testimonials-showcase' ),
			'spotlight' => __( 'Single-quote spotlight', 'asb-testimonials-showcase' ),
			'masonry'   => __( 'Masonry grid', 'asb-testimonials-showcase' ),
			'list'      => __( 'Minimal list with avatars', 'asb-testimonials-showcase' ),
			'bubble'    => __( 'Bubble / chat-style', 'asb-testimonials-showcase' ),
		);
	}

	/**
	 * Normalise raw input args into a clean, validated, typed set.
	 *
	 * This runs regardless of where the args came from (shortcode/block/widget),
	 * so even if one embed path forgot to validate, this catches it.
	 *
	 * @param array $args Raw args (design, category, count).
	 * @return array Clean args.
	 */
	private function normalise_args( $args ) {
		$settings = ASB_TS_Settings::get_settings();

		$defaults = array(
			'design'   => $settings['default_design'],
			'category' => $settings['default_category'], // term id or slug or ''.
			'count'    => $settings['default_count'],
			'orderby'  => 'date',
			'order'    => 'DESC',
		);

		$args = wp_parse_args( $args, $defaults );

		// Design: must be in the allow-list, otherwise fall back to grid.
		$designs = self::get_designs();
		if ( ! isset( $designs[ $args['design'] ] ) ) {
			$args['design'] = 'grid';
		}

		// Count: integer 1–50.
		$args['count'] = min( 50, max( 1, absint( $args['count'] ) ) );

		// Order: only allow ASC/DESC.
		$args['order'] = ( 'ASC' === strtoupper( (string) $args['order'] ) ) ? 'ASC' : 'DESC';

		// Orderby: small allow-list to avoid arbitrary query manipulation.
		$allowed_orderby = array( 'date', 'title', 'rand', 'menu_order' );
		if ( ! in_array( $args['orderby'], $allowed_orderby, true ) ) {
			$args['orderby'] = 'date';
		}

		return $args;
	}

	/**
	 * Build a safe WP_Query for testimonials.
	 *
	 * We use WP_Query (the high-level API) rather than raw SQL. WP_Query builds
	 * fully prepared, escaped queries internally, so we never concatenate user
	 * input into SQL ourselves. The category filter accepts either a numeric
	 * term ID or a slug and is expressed via the safe 'tax_query' structure.
	 *
	 * @param array $args Clean args from normalise_args().
	 * @return WP_Query
	 */
	private function build_query( $args ) {
		$query_args = array(
			'post_type'           => ASB_TS_CPT::POST_TYPE,
			'post_status'         => 'publish',
			'posts_per_page'      => $args['count'],
			'orderby'             => $args['orderby'],
			'order'               => $args['order'],
			'ignore_sticky_posts' => true,
			'no_found_rows'       => true, // We don't paginate, so skip the COUNT query.
		);

		// Category filter via the safe tax_query API (never raw SQL).
		if ( ! empty( $args['category'] ) ) {
			$category = $args['category'];
			// Decide whether we were given an ID or a slug.
			$field = is_numeric( $category ) ? 'term_id' : 'slug';
			$value = is_numeric( $category ) ? absint( $category ) : sanitize_title( $category );

			$query_args['tax_query'] = array(
				array(
					'taxonomy' => ASB_TS_CPT::TAXONOMY,
					'field'    => $field,
					'terms'    => $value,
				),
			);
		}

		return new WP_Query( $query_args );
	}

	/**
	 * Public entry point: render testimonials and return an HTML string.
	 *
	 * @param array $args Raw args from any embed method.
	 * @return string HTML (safe, fully escaped).
	 */
	public function render( $args ) {
		$args  = $this->normalise_args( $args );
		$query = $this->build_query( $args );

		// Enqueue CSS/JS only now that we know testimonials will be shown.
		$this->assets->enqueue_frontend();

		// No results: show a gentle, escaped message.
		if ( ! $query->have_posts() ) {
			return '<div class="asb-ts asb-ts--empty">'
				. esc_html__( 'No testimonials found.', 'asb-testimonials-showcase' )
				. '</div>';
		}

		// Collect each testimonial's data into a simple array of clean values.
		$items = array();
		while ( $query->have_posts() ) {
			$query->the_post();
			$items[] = $this->collect_item( get_the_ID() );
		}
		wp_reset_postdata(); // Always restore the main query after a custom loop.

		// A unique id per rendered group, used for ARIA + JS targeting.
		self::$instance_count++;
		$uid = 'asb-ts-' . self::$instance_count;

		// Dispatch to the chosen layout method. The method name is built from a
		// validated key, so it can only ever be one of our six known methods.
		$method = 'render_' . $args['design'];
		if ( ! method_exists( $this, $method ) ) {
			$method = 'render_grid';
		}

		$inner = $this->$method( $items, $uid, $args );

		/*
		 * Wrap every layout in .asb-ts-wrap. This wrapper is declared as a CSS
		 * "query container" (container-type: inline-size), which lets the layouts
		 * inside respond to the WIDTH OF THIS WRAPPER rather than the viewport.
		 * That's what stops 3 cramped columns from appearing inside a narrow
		 * content area on a wide screen.
		 */
		return '<div class="asb-ts-wrap">' . $inner . '</div>';
	}

	/**
	 * Gather one testimonial's fields into a clean associative array.
	 *
	 * Values are read here; escaping happens later at output time in each layout
	 * (so the same data array can feed any layout).
	 *
	 * @param int $post_id Testimonial ID.
	 * @return array
	 */
	private function collect_item( $post_id ) {
		$photo_id = absint( get_post_meta( $post_id, ASB_TS_Meta_Boxes::META_PHOTO, true ) );

		return array(
			'name'   => (string) get_post_meta( $post_id, ASB_TS_Meta_Boxes::META_NAME, true ),
			'role'   => (string) get_post_meta( $post_id, ASB_TS_Meta_Boxes::META_ROLE, true ),
			'rating' => (int) get_post_meta( $post_id, ASB_TS_Meta_Boxes::META_RATING, true ),
			'photo'  => $photo_id ? wp_get_attachment_image( $photo_id, 'thumbnail', false, array( 'loading' => 'lazy', 'alt' => '' ) ) : '',
			'text'   => (string) get_post_field( 'post_content', $post_id ),
			'title'  => (string) get_the_title( $post_id ),
		);
	}

	/* ===================================================================== *
	 * Small reusable partials (all escape their output).
	 * ===================================================================== */

	/**
	 * Render the star rating as accessible markup.
	 *
	 * We output filled/empty star glyphs plus a visually-hidden text label and
	 * an aria-label, so screen reader users hear "Rated 4 out of 5 stars".
	 *
	 * @param int $rating 0–5.
	 * @return string
	 */
	private function stars( $rating ) {
		$rating = min( 5, max( 0, (int) $rating ) );
		if ( 0 === $rating ) {
			return '';
		}

		$label = sprintf(
			/* translators: %d: star rating out of 5. */
			esc_attr__( 'Rated %d out of 5 stars', 'asb-testimonials-showcase' ),
			$rating
		);

		$stars = '';
		for ( $i = 1; $i <= 5; $i++ ) {
			$class  = $i <= $rating ? 'asb-ts-star asb-ts-star--on' : 'asb-ts-star';
			$stars .= '<span class="' . esc_attr( $class ) . '" aria-hidden="true">★</span>';
		}

		return '<div class="asb-ts-rating" role="img" aria-label="' . $label . '">' . $stars . '</div>';
	}

	/**
	 * Render the body of a single testimonial card (used by several layouts).
	 *
	 * @param array $item Clean item data.
	 * @return string
	 */
	private function card_inner( $item ) {
		$html  = '';
		$html .= $this->stars( $item['rating'] );

		// The testimonial text may contain limited, safe HTML (links, emphasis),
		// so we allow post-level HTML via wp_kses_post rather than stripping it.
		$html .= '<blockquote class="asb-ts-quote">' . wp_kses_post( $item['text'] ) . '</blockquote>';

		// Footer: photo + name + role. Name/role are plain text -> esc_html.
		$html .= '<footer class="asb-ts-meta">';
		if ( $item['photo'] ) {
			// wp_get_attachment_image() already returns safe, escaped markup.
			$html .= '<span class="asb-ts-avatar">' . $item['photo'] . '</span>';
		}
		$html .= '<span class="asb-ts-author">';
		if ( $item['name'] ) {
			$html .= '<span class="asb-ts-name">' . esc_html( $item['name'] ) . '</span>';
		}
		if ( $item['role'] ) {
			$html .= '<span class="asb-ts-role">' . esc_html( $item['role'] ) . '</span>';
		}
		$html .= '</span></footer>';

		return $html;
	}

	/* ===================================================================== *
	 * The six layouts. Each returns a complete, escaped HTML block.
	 * The outer wrapper carries a design-specific BEM-style class so the
	 * stylesheet can target it, plus the shared "asb-ts" hook class.
	 * ===================================================================== */

	/**
	 * 1) Classic card grid. A responsive CSS grid of equal cards.
	 */
	private function render_grid( $items, $uid, $args ) {
		$out = '<div class="asb-ts asb-ts--grid" id="' . esc_attr( $uid ) . '">';
		foreach ( $items as $item ) {
			$out .= '<article class="asb-ts-card">' . $this->card_inner( $item ) . '</article>';
		}
		$out .= '</div>';
		return $out;
	}

	/**
	 * 2) Horizontal slider / carousel.
	 *
	 * Accessibility: the wrapper is a labelled carousel region; each slide is a
	 * group with aria-roledescription="slide" and an "n of m" label; the
	 * prev/next buttons have aria-labels; the JS adds keyboard arrow support.
	 * Progressive enhancement: without JS the track is still a horizontal,
	 * scroll-snapping row.
	 *
	 * IMPORTANT: the track and slides are plain <div>s (not a <ul>/<li> list).
	 * Block themes inject layout/spacing rules onto lists inside post content,
	 * which were overriding our flex layout and making slides stack vertically.
	 * Using <div>s with explicit ARIA roles keeps the carousel semantics while
	 * sidestepping the theme's list styling entirely.
	 */
	private function render_slider( $items, $uid, $args ) {
		$total = count( $items );

		$out  = '<div class="asb-ts asb-ts--slider" id="' . esc_attr( $uid ) . '" data-asb-ts-slider'
			. ' role="region" aria-roledescription="' . esc_attr__( 'carousel', 'asb-testimonials-showcase' ) . '"'
			. ' aria-label="' . esc_attr__( 'Testimonials', 'asb-testimonials-showcase' ) . '">';

		$out .= '<button type="button" class="asb-ts-nav asb-ts-nav--prev" data-asb-ts-prev aria-label="'
			. esc_attr__( 'Previous testimonial', 'asb-testimonials-showcase' ) . '">&#8249;</button>';

		$out .= '<div class="asb-ts-track" data-asb-ts-track>';
		foreach ( $items as $index => $item ) {
			$slide_label = sprintf(
				/* translators: 1: current slide number, 2: total slides. */
				esc_attr__( '%1$d of %2$d', 'asb-testimonials-showcase' ),
				$index + 1,
				$total
			);
			$out .= '<div class="asb-ts-slide" role="group" aria-roledescription="'
				. esc_attr__( 'slide', 'asb-testimonials-showcase' ) . '" aria-label="' . $slide_label . '">'
				. '<article class="asb-ts-card">' . $this->card_inner( $item ) . '</article></div>';
		}
		$out .= '</div>';

		$out .= '<button type="button" class="asb-ts-nav asb-ts-nav--next" data-asb-ts-next aria-label="'
			. esc_attr__( 'Next testimonial', 'asb-testimonials-showcase' ) . '">&#8250;</button>';

		$out .= '</div>';
		return $out;
	}

	/**
	 * 3) Single-quote spotlight: one large testimonial that rotates.
	 *
	 * Uses aria-live="polite" so screen readers announce the new quote when it
	 * changes. Dots let users jump to a specific testimonial.
	 */
	private function render_spotlight( $items, $uid, $args ) {
		$out  = '<div class="asb-ts asb-ts--spotlight" id="' . esc_attr( $uid ) . '" data-asb-ts-spotlight'
			. ' role="region" aria-label="' . esc_attr__( 'Featured testimonial', 'asb-testimonials-showcase' ) . '">';

		$out .= '<div class="asb-ts-stage" aria-live="polite">';
		foreach ( $items as $index => $item ) {
			$hidden = $index === 0 ? '' : ' hidden';
			$out   .= '<article class="asb-ts-card asb-ts-spotlight-item" data-asb-ts-item="' . esc_attr( $index ) . '"' . $hidden . '>'
				. $this->card_inner( $item ) . '</article>';
		}
		$out .= '</div>';

		// Dot navigation.
		if ( count( $items ) > 1 ) {
			$out .= '<div class="asb-ts-dots" role="tablist" aria-label="'
				. esc_attr__( 'Choose testimonial', 'asb-testimonials-showcase' ) . '">';
			foreach ( $items as $index => $item ) {
				$current = 0 === $index ? ' aria-selected="true"' : ' aria-selected="false"';
				$out    .= '<button type="button" class="asb-ts-dot" role="tab"' . $current
					. ' data-asb-ts-dot="' . esc_attr( $index ) . '" aria-label="'
					. esc_attr( sprintf( /* translators: %d: slide number */ __( 'Show testimonial %d', 'asb-testimonials-showcase' ), $index + 1 ) )
					. '"></button>';
			}
			$out .= '</div>';
		}

		$out .= '</div>';
		return $out;
	}

	/**
	 * 4) Masonry / Pinterest-style grid using CSS columns (no JS layout needed).
	 */
	private function render_masonry( $items, $uid, $args ) {
		$out = '<div class="asb-ts asb-ts--masonry" id="' . esc_attr( $uid ) . '">';
		foreach ( $items as $item ) {
			$out .= '<article class="asb-ts-card asb-ts-masonry-item">' . $this->card_inner( $item ) . '</article>';
		}
		$out .= '</div>';
		return $out;
	}

	/**
	 * 5) Minimal list with avatars. A semantic <ul> of compact rows.
	 */
	private function render_list( $items, $uid, $args ) {
		$out = '<ul class="asb-ts asb-ts--list" id="' . esc_attr( $uid ) . '">';
		foreach ( $items as $item ) {
			$out .= '<li class="asb-ts-row"><article class="asb-ts-card">' . $this->card_inner( $item ) . '</article></li>';
		}
		$out .= '</ul>';
		return $out;
	}

	/**
	 * 6) Bubble / chat-style speech cards.
	 */
	private function render_bubble( $items, $uid, $args ) {
		$out = '<div class="asb-ts asb-ts--bubble" id="' . esc_attr( $uid ) . '">';
		foreach ( $items as $item ) {
			$out .= '<article class="asb-ts-card asb-ts-bubble-item">'
				. '<div class="asb-ts-bubble-body">' . $this->stars( $item['rating'] )
				. '<blockquote class="asb-ts-quote">' . wp_kses_post( $item['text'] ) . '</blockquote></div>'
				. '<footer class="asb-ts-meta">';
			if ( $item['photo'] ) {
				$out .= '<span class="asb-ts-avatar">' . $item['photo'] . '</span>';
			}
			$out .= '<span class="asb-ts-author">';
			if ( $item['name'] ) {
				$out .= '<span class="asb-ts-name">' . esc_html( $item['name'] ) . '</span>';
			}
			if ( $item['role'] ) {
				$out .= '<span class="asb-ts-role">' . esc_html( $item['role'] ) . '</span>';
			}
			$out .= '</span></footer></article>';
		}
		$out .= '</div>';
		return $out;
	}
}
