<?php
/**
 * Settings storage + sanitisation.
 *
 * Everything lives in ONE option row (`wcpe_settings`) instead of a dozen
 * separate rows. One row = one DB read, and it is autoloaded by WordPress
 * with the rest of the core options, so reading settings on the front end
 * costs zero extra queries.
 *
 * @package WC_Product_Enquiry
 */

defined( 'ABSPATH' ) || exit;

class WCPE_Settings {

	/** Option name in wp_options. */
	const OPTION = 'wcpe_settings';

	/** Runtime cache so we never unserialise the option twice per request. */
	private static $cache = null;

	/**
	 * Default values. Also acts as the schema: any key not listed here is
	 * dropped during sanitisation, so junk can never be written to the DB.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'form_id'         => 0,      // CF7 form post ID.
			'enable_globally' => 1,      // Show on every product unless a product opts out.
			'categories'      => array(), // Empty = all categories.
			'button_label'    => __( 'Enquire about this product', 'wc-product-enquiry' ),
			'button_hook'     => 'woocommerce_after_add_to_cart_form',
			'modal_title'     => __( 'Product enquiry', 'wc-product-enquiry' ),
			'modal_intro'     => __( 'Send us your question and we will reply by email.', 'wc-product-enquiry' ),
			'show_thumbnail'  => 1,      // Show product image/name strip inside the popup.
			'hide_price_cart' => 0,      // "Catalogue mode": hide price + Add to cart on enquiry products.
			'auto_close'      => 1,      // Close popup a few seconds after a successful send.
			'rate_limit'      => 5,      // Max submissions per IP per hour (0 = off).
		);
	}

	/**
	 * The hooks a shop owner is allowed to pick for the button position.
	 *
	 * This is a whitelist, not free text. A user-supplied action name would let
	 * anyone with settings access fire our callback in an unexpected context;
	 * a fixed map removes that whole class of problem and doubles as the
	 * <select> source.
	 *
	 * @return array hook => array( label, priority )
	 */
	public static function positions() {
		return array(
			'woocommerce_after_add_to_cart_button' => array( __( 'Next to the Add to cart button', 'wc-product-enquiry' ), 20 ),
			'woocommerce_after_add_to_cart_form'   => array( __( 'Below the Add to cart form', 'wc-product-enquiry' ), 10 ),
			'woocommerce_single_product_summary'   => array( __( 'Below the short description', 'wc-product-enquiry' ), 25 ),
			'woocommerce_product_meta_end'         => array( __( 'Below the SKU / category meta', 'wc-product-enquiry' ), 10 ),
			'woocommerce_share'                    => array( __( 'At the very bottom of the summary', 'wc-product-enquiry' ), 10 ),
		);
	}

	/**
	 * Read a single setting, or the whole array.
	 *
	 * @param string|null $key Setting key, or null for everything.
	 * @return mixed
	 */
	public static function get( $key = null ) {
		if ( null === self::$cache ) {
			$stored = get_option( self::OPTION, array() );
			// wp_parse_args guarantees every key exists, so callers never need isset().
			self::$cache = wp_parse_args( is_array( $stored ) ? $stored : array(), self::defaults() );
		}

		if ( null === $key ) {
			return self::$cache;
		}

		return isset( self::$cache[ $key ] ) ? self::$cache[ $key ] : null;
	}

	/**
	 * Sanitise callback used by register_setting().
	 *
	 * Rule: never trust $_POST. Every value is cast or filtered to the exact
	 * type we expect before it touches the database.
	 *
	 * @param mixed $input Raw submitted settings.
	 * @return array
	 */
	public static function sanitize( $input ) {
		$input    = is_array( $input ) ? $input : array();
		$defaults = self::defaults();
		$out      = array();

		$out['form_id']         = isset( $input['form_id'] ) ? absint( $input['form_id'] ) : 0;
		$out['enable_globally'] = empty( $input['enable_globally'] ) ? 0 : 1;
		$out['show_thumbnail']  = empty( $input['show_thumbnail'] ) ? 0 : 1;
		$out['hide_price_cart'] = empty( $input['hide_price_cart'] ) ? 0 : 1;
		$out['auto_close']      = empty( $input['auto_close'] ) ? 0 : 1;

		// absint + a hard ceiling: stops a typo like 999999 creating useless work.
		$out['rate_limit'] = isset( $input['rate_limit'] ) ? min( 100, absint( $input['rate_limit'] ) ) : 5;

		// Category IDs must be integers, and must actually exist as product cats.
		$out['categories'] = array();
		if ( ! empty( $input['categories'] ) && is_array( $input['categories'] ) ) {
			foreach ( $input['categories'] as $term_id ) {
				$term_id = absint( $term_id );
				if ( $term_id && term_exists( $term_id, 'product_cat' ) ) {
					$out['categories'][] = $term_id;
				}
			}
		}

		// sanitize_text_field strips tags, nulls and stray line breaks.
		$out['button_label'] = isset( $input['button_label'] ) ? sanitize_text_field( $input['button_label'] ) : $defaults['button_label'];
		$out['modal_title']  = isset( $input['modal_title'] ) ? sanitize_text_field( $input['modal_title'] ) : $defaults['modal_title'];
		$out['modal_intro']  = isset( $input['modal_intro'] ) ? sanitize_text_field( $input['modal_intro'] ) : $defaults['modal_intro'];

		// Whitelist check — anything not in positions() falls back to the default.
		$hook               = isset( $input['button_hook'] ) ? sanitize_key( $input['button_hook'] ) : '';
		$out['button_hook'] = array_key_exists( $hook, self::positions() ) ? $hook : $defaults['button_hook'];

		self::$cache = null; // Bust the runtime cache so the redirect shows fresh values.

		return $out;
	}
}
