<?php
/**
 * Front-end output.
 *
 * Efficiency principle used throughout this file: decide ONCE, on the `wp`
 * hook, whether this request needs the enquiry feature at all. If it does not,
 * we register no hooks, enqueue no CSS/JS and render nothing. A category page,
 * the cart, a blog post — none of them pay any cost for this plugin.
 *
 * @package WC_Product_Enquiry
 */

defined( 'ABSPATH' ) || exit;

class WCPE_Frontend {

	/** @var WC_Product|null Product for the current request. */
	private $product = null;

	/** @var bool Whether the modal still needs printing in the footer. */
	private $modal_printed = false;

	public function __construct() {
		// `wp` runs after the main query is resolved, so conditional tags like
		// is_product() are safe here — and it is still before wp_enqueue_scripts.
		add_action( 'wp', array( $this, 'setup' ) );
	}

	/**
	 * Work out whether this request needs the enquiry UI, and wire up hooks.
	 */
	public function setup() {
		if ( is_admin() || ! function_exists( 'is_product' ) || ! is_product() ) {
			return;
		}

		if ( ! absint( WCPE_Settings::get( 'form_id' ) ) ) {
			return; // No form chosen yet — nothing to show.
		}

		$product = wc_get_product( get_queried_object_id() );

		if ( ! $product instanceof WC_Product || ! self::is_enabled_for( $product ) ) {
			return;
		}

		$this->product = $product;

		$positions = WCPE_Settings::positions();
		$hook      = WCPE_Settings::get( 'button_hook' );
		$hook      = isset( $positions[ $hook ] ) ? $hook : 'woocommerce_after_add_to_cart_form';
		$priority  = $positions[ $hook ][1];

		// Catalogue mode: strip price + Add to cart so the enquiry button is the
		// only call to action. Useful for made-to-order or "price on request" items.
		if ( WCPE_Settings::get( 'hide_price_cart' ) ) {
			remove_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_price', 10 );
			remove_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_add_to_cart', 30 );

			// Those two hooks live inside the cart form we just removed, so the
			// button would never render. Fall back to a hook that still fires.
			if ( in_array( $hook, array( 'woocommerce_after_add_to_cart_button', 'woocommerce_after_add_to_cart_form' ), true ) ) {
				$hook     = 'woocommerce_single_product_summary';
				$priority = 30;
			}
		}

		add_action( $hook, array( $this, 'render_button' ), $priority );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_footer', array( $this, 'render_modal' ), 20 );
	}

	/* ---------------------------------------------------------------------
	 * Visibility rules
	 * ------------------------------------------------------------------ */

	/**
	 * Should the enquiry button appear for this product?
	 *
	 * Order of precedence: per-product setting → category filter → global switch.
	 *
	 * @param WC_Product $product Product object.
	 * @return bool
	 */
	public static function is_enabled_for( $product ) {
		// get_meta() reads from WooCommerce's already-primed meta cache, so this
		// is not an extra query.
		$override = $product->get_meta( '_wcpe_enabled' );

		if ( 'yes' === $override ) {
			$enabled = true;
		} elseif ( 'no' === $override ) {
			$enabled = false;
		} else {
			$enabled = (bool) WCPE_Settings::get( 'enable_globally' );

			$categories = WCPE_Settings::get( 'categories' );

			if ( $enabled && ! empty( $categories ) ) {
				$enabled = has_term( $categories, 'product_cat', $product->get_id() );
			}
		}

		/**
		 * Filter the final decision.
		 *
		 * Lets a theme or snippet add rules we did not think of, e.g. only show
		 * the button when the product is out of stock:
		 *
		 *   add_filter( 'wcpe_is_enabled', fn( $on, $p ) => ! $p->is_in_stock(), 10, 2 );
		 *
		 * @param bool       $enabled Current decision.
		 * @param WC_Product $product Product object.
		 */
		return (bool) apply_filters( 'wcpe_is_enabled', $enabled, $product );
	}

	/* ---------------------------------------------------------------------
	 * Assets
	 * ------------------------------------------------------------------ */

	/**
	 * Enqueue our CSS/JS, plus CF7's own assets.
	 *
	 * CF7 5.8+ only loads its scripts when it detects a form early in the page.
	 * Ours renders in the footer, which is too late for that detection, so we
	 * ask for them explicitly here.
	 */
	public function enqueue_assets() {
		/**
		 * Filter whether the plugin's stylesheet loads at all.
		 *
		 * Return false to style the popup entirely from your theme. The markup
		 * keeps its `wcpe-` classes either way, so nothing else changes:
		 *
		 *   add_filter( 'wcpe_load_styles', '__return_false' );
		 *
		 * @param bool $load Whether to enqueue wcpe-frontend.css.
		 */
		if ( apply_filters( 'wcpe_load_styles', true ) ) {
			wp_enqueue_style( 'wcpe-frontend', WCPE_URL . 'assets/css/wcpe-frontend.css', array(), WCPE_VERSION );
		}

		wp_enqueue_script( 'wcpe-frontend', WCPE_URL . 'assets/js/wcpe-frontend.js', array(), WCPE_VERSION, true );

		// wp_localize_script is the safe bridge from PHP to JS: values are
		// JSON-encoded and escaped, so no string concatenation into inline JS.
		wp_localize_script(
			'wcpe-frontend',
			'wcpeConfig',
			array(
				'autoClose'  => (bool) WCPE_Settings::get( 'auto_close' ),
				'closeDelay' => 2500,
			)
		);

		if ( function_exists( 'wpcf7_enqueue_scripts' ) ) {
			wpcf7_enqueue_scripts();
		}
		if ( function_exists( 'wpcf7_enqueue_styles' ) ) {
			wpcf7_enqueue_styles();
		}
	}

	/* ---------------------------------------------------------------------
	 * Markup
	 * ------------------------------------------------------------------ */

	/**
	 * The button on the product page.
	 *
	 * `type="button"` matters: on the "next to Add to cart" position this sits
	 * inside WooCommerce's cart <form>, and a button without an explicit type
	 * defaults to submit — clicking it would add the item to the cart.
	 */
	public function render_button() {
		if ( ! $this->product ) {
			return;
		}

		printf(
			'<button type="button" class="button wcpe-open-button" data-wcpe-open aria-haspopup="dialog" aria-controls="wcpe-modal">%s</button>',
			esc_html( WCPE_Settings::get( 'button_label' ) )
		);
	}

	/**
	 * The popup itself, printed once in the footer.
	 *
	 * Footer placement keeps the dialog out of the theme's layout containers,
	 * which is what prevents the classic "modal trapped inside a parent with
	 * overflow:hidden" bug.
	 */
	public function render_modal() {
		if ( ! $this->product || $this->modal_printed ) {
			return;
		}

		$this->modal_printed = true;

		$form_id = absint( WCPE_Settings::get( 'form_id' ) );
		$product = $this->product;
		?>
		<dialog id="wcpe-modal" class="wcpe-modal" aria-labelledby="wcpe-modal-title">
			<div class="wcpe-modal__panel">

				<header class="wcpe-modal__header">
					<h2 id="wcpe-modal-title" class="wcpe-modal__title"><?php echo esc_html( WCPE_Settings::get( 'modal_title' ) ); ?></h2>
					<button type="button" class="wcpe-modal__close" data-wcpe-close aria-label="<?php esc_attr_e( 'Close enquiry form', 'wc-product-enquiry' ); ?>">
						<svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false"><path d="M6 6l12 12M18 6L6 18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
					</button>
				</header>

				<?php if ( WCPE_Settings::get( 'show_thumbnail' ) ) : ?>
					<div class="wcpe-product-strip">
						<?php
						// Confirms to the visitor which product they are asking about —
						// the same context we attach to the email.
						echo wp_kses_post( $product->get_image( 'woocommerce_gallery_thumbnail', array( 'class' => 'wcpe-product-strip__img' ) ) );
						?>
						<div class="wcpe-product-strip__meta">
							<span class="wcpe-product-strip__name"><?php echo esc_html( $product->get_name() ); ?></span>
							<?php if ( $product->get_sku() ) : ?>
								<span class="wcpe-product-strip__sku"><?php echo esc_html( sprintf( /* translators: %s: product SKU */ __( 'SKU: %s', 'wc-product-enquiry' ), $product->get_sku() ) ); ?></span>
							<?php endif; ?>
						</div>
					</div>
				<?php endif; ?>

				<div class="wcpe-modal__body">
					<?php if ( WCPE_Settings::get( 'modal_intro' ) ) : ?>
						<p class="wcpe-modal__intro"><?php echo esc_html( WCPE_Settings::get( 'modal_intro' ) ); ?></p>
					<?php endif; ?>

					<?php
					// Flag ON: from here until the flag goes off, the CF7 filters in
					// WCPE_CF7 know that the form being built is ours.
					WCPE_CF7::set_context( $product->get_id(), true );

					$printed = false;

					if ( function_exists( 'wpcf7_contact_form' ) ) {
						$cf7 = wpcf7_contact_form( $form_id );

						if ( $cf7 ) {
							// form_html() returns CF7's own trusted, already-escaped markup.
							// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
							echo $cf7->form_html( array( 'html_id' => 'wcpe-enquiry-form' ) );
							$printed = true;
						}
					}

					if ( ! $printed ) {
						// Fallback for older CF7 builds.
						// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						echo do_shortcode( sprintf( '[contact-form-7 id="%d"]', $form_id ) );
					}

					WCPE_CF7::set_context( 0, false ); // Flag OFF.
					?>
				</div>
			</div>
		</dialog>
		<?php
	}
}
