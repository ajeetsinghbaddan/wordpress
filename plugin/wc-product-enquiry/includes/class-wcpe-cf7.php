<?php
/**
 * Contact Form 7 integration.
 *
 * Responsibilities:
 *  1. Inject the product context (ID + signature) into our form as hidden inputs.
 *  2. Add a CSS-hidden honeypot field to catch dumb bots.
 *  3. Expose [_wcpe_product_name] etc. as CF7 "special mail tags".
 *  4. Rate-limit submissions per IP.
 *
 * Security note: the email is NEVER built from the product name posted by the
 * browser. Only the product ID travels over the wire; the name, SKU, price and
 * URL are looked up again from the database at send time. That makes it
 * impossible for someone to POST a fake product name into your inbox.
 *
 * @package WC_Product_Enquiry
 */

defined( 'ABSPATH' ) || exit;

class WCPE_CF7 {

	/** Product ID of the page currently being rendered. */
	private static $context_product_id = 0;

	/** True only while OUR modal form is being printed. */
	private static $rendering = false;

	/** Per-request memo of the resolved product, so we hit the DB once. */
	private static $resolved_product = null;

	public function __construct() {
		add_filter( 'wpcf7_form_hidden_fields', array( $this, 'add_hidden_fields' ) );
		add_filter( 'wpcf7_form_elements', array( $this, 'add_honeypot' ) );
		add_filter( 'wpcf7_special_mail_tags', array( $this, 'special_mail_tags' ), 10, 4 );
		add_filter( 'wpcf7_posted_data', array( $this, 'enrich_posted_data' ) );
		add_filter( 'wpcf7_spam', array( $this, 'spam_checks' ), 10, 2 );
	}

	/* ---------------------------------------------------------------------
	 * Render-time context
	 * ------------------------------------------------------------------ */

	/**
	 * Called by the front-end class right before/after printing the form.
	 *
	 * The flag matters: `wpcf7_form_hidden_fields` fires for EVERY CF7 form on
	 * the page. Without it we would bolt product fields onto an unrelated
	 * newsletter form sitting in the footer.
	 *
	 * @param int  $product_id Current product.
	 * @param bool $rendering  Whether our form is being printed right now.
	 */
	public static function set_context( $product_id, $rendering ) {
		self::$context_product_id = absint( $product_id );
		self::$rendering          = (bool) $rendering;
	}

	/**
	 * Signature that proves the product ID came from a page we rendered.
	 *
	 * wp_hash() is an HMAC keyed with the site's secret salts, so it cannot be
	 * forged by a visitor. It is NOT a nonce — nonces are user-session bound
	 * and would break for logged-out visitors on cached pages, which is exactly
	 * the audience for an enquiry form.
	 *
	 * @param int $product_id Product ID.
	 * @return string
	 */
	public static function signature( $product_id ) {
		return wp_hash( 'wcpe|product|' . absint( $product_id ) );
	}

	/**
	 * Add our hidden inputs to the CF7 form markup.
	 *
	 * @param array $fields name => value.
	 * @return array
	 */
	public function add_hidden_fields( $fields ) {
		if ( ! self::$rendering || ! self::$context_product_id ) {
			return $fields;
		}

		$fields['wcpe_product_id'] = self::$context_product_id;
		$fields['wcpe_sig']        = self::signature( self::$context_product_id );

		return $fields;
	}

	/**
	 * Append a honeypot input inside the <form>.
	 *
	 * Real people never see it (hidden with CSS, tabindex="-1", aria-hidden),
	 * but a naive spam bot fills every input it finds. If it comes back with a
	 * value, the submission is spam.
	 *
	 * @param string $elements Form inner HTML.
	 * @return string
	 */
	public function add_honeypot( $elements ) {
		if ( ! self::$rendering ) {
			return $elements;
		}

		$honeypot = '<div class="wcpe-hp" aria-hidden="true">'
			. '<label>' . esc_html__( 'Leave this field empty', 'wc-product-enquiry' ) . '</label>'
			. '<input type="text" name="wcpe_hp" value="" tabindex="-1" autocomplete="off">'
			. '</div>';

		return $elements . $honeypot;
	}

	/* ---------------------------------------------------------------------
	 * Submission time
	 * ------------------------------------------------------------------ */

	/**
	 * Resolve the submitted product ID back into a real WC_Product.
	 *
	 * Validation chain: signature matches → ID is a real product → product is
	 * published. Anything else returns null and the mail tags render empty.
	 *
	 * @return WC_Product|null
	 */
	private function get_submitted_product() {
		if ( null !== self::$resolved_product ) {
			return self::$resolved_product ?: null;
		}

		self::$resolved_product = false;

		// Read straight from $_POST rather than from CF7's posted data. The
		// `wpcf7_posted_data` filter runs *while* that array is still being
		// built, so asking CF7 for it there would come back empty.
		// The HMAC check below is what makes trusting $_POST here safe.
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$product_id = isset( $_POST['wcpe_product_id'] ) ? absint( wp_unslash( $_POST['wcpe_product_id'] ) ) : 0;
		$signature  = isset( $_POST['wcpe_sig'] ) ? sanitize_text_field( wp_unslash( $_POST['wcpe_sig'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( ! $product_id || ! $signature ) {
			return null;
		}

		// hash_equals() compares in constant time — the standard defence against
		// timing attacks when checking any kind of secret/HMAC.
		if ( ! hash_equals( self::signature( $product_id ), $signature ) ) {
			return null;
		}

		$product = wc_get_product( $product_id );

		if ( ! $product instanceof WC_Product || 'publish' !== get_post_status( $product_id ) ) {
			return null;
		}

		self::$resolved_product = $product;

		return $product;
	}

	/**
	 * Build the value for one of our mail tags.
	 *
	 * @param string $key Short key (name, url, sku, id, price, categories).
	 * @return string
	 */
	private function value_for( $key ) {
		$product = $this->get_submitted_product();

		if ( ! $product ) {
			return '';
		}

		switch ( $key ) {
			case 'name':
				return $product->get_name();

			case 'url':
				return (string) get_permalink( $product->get_id() );

			case 'sku':
				return $product->get_sku();

			case 'id':
				return (string) $product->get_id();

			case 'price':
				// wp_strip_all_tags: get_price_html() returns markup (<span>, &nbsp;
				// entities, sale <del>/<ins>) which we do not want in a plain-text email.
				return trim( wp_strip_all_tags( $product->get_price_html() ) );

			case 'categories':
				$terms = get_the_terms( $product->get_id(), 'product_cat' );
				return is_array( $terms ) ? implode( ', ', wp_list_pluck( $terms, 'name' ) ) : '';
		}

		return '';
	}

	/**
	 * Register [_wcpe_product_name] & friends with CF7.
	 *
	 * CF7 only routes tags that start with an underscore to this filter — that
	 * is how it distinguishes "special" tags (server-side data) from normal
	 * field tags the user placed in the form.
	 *
	 * @param string $output   Current output.
	 * @param string $name     Tag name.
	 * @param bool   $html     Whether the mail is HTML.
	 * @param mixed  $mail_tag Tag object (unused).
	 * @return string
	 */
	public function special_mail_tags( $output, $name, $html, $mail_tag = null ) {
		$map = array(
			'_wcpe_product_name'  => 'name',
			'_wcpe_product_url'   => 'url',
			'_wcpe_product_sku'   => 'sku',
			'_wcpe_product_id'    => 'id',
			'_wcpe_product_price' => 'price',
			'_wcpe_product_cats'  => 'categories',
		);

		if ( ! isset( $map[ $name ] ) ) {
			return $output;
		}

		$value = $this->value_for( $map[ $name ] );

		// Escaping is context-aware: HTML emails get esc_html, plain-text does not.
		return $html ? esc_html( $value ) : $value;
	}

	/**
	 * Put readable product info into the stored submission.
	 *
	 * Purely a convenience: if Flamingo (CF7's message-storage add-on) is
	 * installed, the record shows "Blue Cotton Shirt" instead of just "482".
	 * Values are derived server-side, so they stay trustworthy.
	 *
	 * @param array $data Posted data.
	 * @return array
	 */
	public function enrich_posted_data( $data ) {
		$product = $this->get_submitted_product();

		if ( $product ) {
			$data['wcpe_product_name'] = $product->get_name();
			$data['wcpe_product_url']  = get_permalink( $product->get_id() );
		}

		unset( $data['wcpe_hp'] ); // Keep the honeypot out of stored records.

		return $data;
	}

	/**
	 * Honeypot + rate limiting.
	 *
	 * Returning true marks the submission as spam: CF7 shows the visitor the
	 * normal "failed to send" message and does not email anything.
	 *
	 * @param bool  $spam       Current verdict.
	 * @param mixed $submission Submission object (CF7 5.7+).
	 * @return bool
	 */
	public function spam_checks( $spam, $submission = null ) {
		if ( $spam ) {
			return $spam; // Someone else already flagged it — leave it alone.
		}

		if ( ! $submission && class_exists( 'WPCF7_Submission' ) ) {
			$submission = WPCF7_Submission::get_instance();
		}

		if ( ! $submission ) {
			return $spam;
		}

		$contact_form = $submission->get_contact_form();

		// Only police OUR form. Other forms on the site keep their own rules.
		if ( ! $contact_form || absint( $contact_form->id() ) !== absint( WCPE_Settings::get( 'form_id' ) ) ) {
			return $spam;
		}

		// 1) Honeypot. Read from $_POST because CF7 strips unknown fields early.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- CF7 owns the request lifecycle; this is a read-only spam heuristic.
		if ( ! empty( $_POST['wcpe_hp'] ) ) {
			return true;
		}

		// 2) Rate limit per IP.
		$limit = absint( WCPE_Settings::get( 'rate_limit' ) );

		if ( $limit > 0 ) {
			$ip = $submission->get_meta( 'remote_ip' );

			if ( $ip ) {
				// The IP is hashed before it becomes a transient key: the key is
				// visible in the options table, and storing raw IPs there is
				// needless personal-data exposure (and GDPR risk).
				$key   = 'wcpe_rl_' . md5( $ip . wp_salt() );
				$count = (int) get_transient( $key );

				if ( $count >= $limit ) {
					return true;
				}

				// HOUR_IN_SECONDS window. Transients auto-expire, so nothing
				// accumulates in the DB long term.
				set_transient( $key, $count + 1, HOUR_IN_SECONDS );
			}
		}

		return $spam;
	}
}
