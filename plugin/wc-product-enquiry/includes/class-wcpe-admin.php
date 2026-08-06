<?php
/**
 * Admin screens.
 *
 * @package WC_Product_Enquiry
 */

defined( 'ABSPATH' ) || exit;

class WCPE_Admin {

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );

		// Per-product override, inside the WooCommerce "Product data" box.
		add_action( 'woocommerce_product_options_advanced', array( $this, 'product_field' ) );
		add_action( 'woocommerce_admin_process_product_object', array( $this, 'save_product_field' ) );

		add_filter( 'plugin_action_links_' . plugin_basename( WCPE_FILE ), array( $this, 'action_links' ) );
	}

	/**
	 * "Settings" link on the Plugins screen — small thing, saves a lot of hunting.
	 *
	 * @param array $links Existing links.
	 * @return array
	 */
	public function action_links( $links ) {
		$url = admin_url( 'admin.php?page=wcpe-settings' );
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'wc-product-enquiry' ) . '</a>' );
		return $links;
	}

	public function add_menu() {
		add_submenu_page(
			'woocommerce',
			__( 'Product Enquiry', 'wc-product-enquiry' ),
			__( 'Product Enquiry', 'wc-product-enquiry' ),
			'manage_woocommerce', // Capability, not a role: shop managers get in, subscribers never do.
			'wcpe-settings',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Register the option with the Settings API.
	 *
	 * The API gives us nonce generation, capability checks, the "Settings saved"
	 * notice and the sanitise callback for free — all things that are easy to
	 * get wrong when hand-rolling an options form.
	 */
	public function register_settings() {
		register_setting(
			'wcpe_settings_group',
			WCPE_Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( 'WCPE_Settings', 'sanitize' ),
				'default'           => WCPE_Settings::defaults(),
			)
		);
	}

	/**
	 * List available CF7 forms for the dropdown.
	 *
	 * @return array id => title
	 */
	private function get_cf7_forms() {
		$forms = array();

		if ( ! class_exists( 'WPCF7_ContactForm' ) ) {
			return $forms;
		}

		foreach ( WPCF7_ContactForm::find( array( 'posts_per_page' => 100 ) ) as $form ) {
			$forms[ $form->id() ] = $form->title();
		}

		return $forms;
	}

	public function render_page() {
		// Belt and braces: WordPress already gates the page by capability, but
		// an explicit check protects against direct calls to the callback.
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'wc-product-enquiry' ) );
		}

		$settings = WCPE_Settings::get();
		$forms    = $this->get_cf7_forms();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Product Enquiry', 'wc-product-enquiry' ); ?></h1>

			<form method="post" action="options.php">
				<?php settings_fields( 'wcpe_settings_group' ); // Prints nonce + option group hidden fields. ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="wcpe_form_id"><?php esc_html_e( 'Contact form', 'wc-product-enquiry' ); ?></label></th>
						<td>
							<select id="wcpe_form_id" name="<?php echo esc_attr( WCPE_Settings::OPTION ); ?>[form_id]">
								<option value="0"><?php esc_html_e( '— Select a form —', 'wc-product-enquiry' ); ?></option>
								<?php foreach ( $forms as $id => $title ) : ?>
									<option value="<?php echo esc_attr( $id ); ?>" <?php selected( $settings['form_id'], $id ); ?>>
										<?php echo esc_html( $title ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'The Contact Form 7 form shown inside the popup.', 'wc-product-enquiry' ); ?></p>
						</td>
					</tr>

					<tr>
						<th scope="row"><?php esc_html_e( 'Where to show it', 'wc-product-enquiry' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( WCPE_Settings::OPTION ); ?>[enable_globally]" value="1" <?php checked( $settings['enable_globally'], 1 ); ?>>
								<?php esc_html_e( 'Show on all products', 'wc-product-enquiry' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Individual products can still opt in or out under Product data → Advanced.', 'wc-product-enquiry' ); ?></p>
						</td>
					</tr>

					<tr>
						<th scope="row"><label for="wcpe_categories"><?php esc_html_e( 'Limit to categories', 'wc-product-enquiry' ); ?></label></th>
						<td>
							<?php
							$terms = get_terms(
								array(
									'taxonomy'   => 'product_cat',
									'hide_empty' => false,
									'number'     => 300,
								)
							);
							?>
							<select id="wcpe_categories" name="<?php echo esc_attr( WCPE_Settings::OPTION ); ?>[categories][]" multiple size="6" style="min-width:280px">
								<?php if ( ! is_wp_error( $terms ) ) : ?>
									<?php foreach ( $terms as $term ) : ?>
										<option value="<?php echo esc_attr( $term->term_id ); ?>" <?php selected( in_array( $term->term_id, (array) $settings['categories'], true ) ); ?>>
											<?php echo esc_html( $term->name ); ?>
										</option>
									<?php endforeach; ?>
								<?php endif; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Leave nothing selected to allow every category.', 'wc-product-enquiry' ); ?></p>
						</td>
					</tr>

					<tr>
						<th scope="row"><label for="wcpe_button_hook"><?php esc_html_e( 'Button position', 'wc-product-enquiry' ); ?></label></th>
						<td>
							<select id="wcpe_button_hook" name="<?php echo esc_attr( WCPE_Settings::OPTION ); ?>[button_hook]">
								<?php foreach ( WCPE_Settings::positions() as $hook => $meta ) : ?>
									<option value="<?php echo esc_attr( $hook ); ?>" <?php selected( $settings['button_hook'], $hook ); ?>>
										<?php echo esc_html( $meta[0] ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>

					<tr>
						<th scope="row"><label for="wcpe_button_label"><?php esc_html_e( 'Button label', 'wc-product-enquiry' ); ?></label></th>
						<td><input type="text" class="regular-text" id="wcpe_button_label" name="<?php echo esc_attr( WCPE_Settings::OPTION ); ?>[button_label]" value="<?php echo esc_attr( $settings['button_label'] ); ?>"></td>
					</tr>

					<tr>
						<th scope="row"><label for="wcpe_modal_title"><?php esc_html_e( 'Popup heading', 'wc-product-enquiry' ); ?></label></th>
						<td><input type="text" class="regular-text" id="wcpe_modal_title" name="<?php echo esc_attr( WCPE_Settings::OPTION ); ?>[modal_title]" value="<?php echo esc_attr( $settings['modal_title'] ); ?>"></td>
					</tr>

					<tr>
						<th scope="row"><label for="wcpe_modal_intro"><?php esc_html_e( 'Popup intro text', 'wc-product-enquiry' ); ?></label></th>
						<td><input type="text" class="large-text" id="wcpe_modal_intro" name="<?php echo esc_attr( WCPE_Settings::OPTION ); ?>[modal_intro]" value="<?php echo esc_attr( $settings['modal_intro'] ); ?>"></td>
					</tr>

					<tr>
						<th scope="row"><?php esc_html_e( 'Popup options', 'wc-product-enquiry' ); ?></th>
						<td>
							<label style="display:block;margin-bottom:6px">
								<input type="checkbox" name="<?php echo esc_attr( WCPE_Settings::OPTION ); ?>[show_thumbnail]" value="1" <?php checked( $settings['show_thumbnail'], 1 ); ?>>
								<?php esc_html_e( 'Show the product image and name inside the popup', 'wc-product-enquiry' ); ?>
							</label>
							<label style="display:block;margin-bottom:6px">
								<input type="checkbox" name="<?php echo esc_attr( WCPE_Settings::OPTION ); ?>[auto_close]" value="1" <?php checked( $settings['auto_close'], 1 ); ?>>
								<?php esc_html_e( 'Close the popup a moment after the message sends', 'wc-product-enquiry' ); ?>
							</label>
							<label style="display:block">
								<input type="checkbox" name="<?php echo esc_attr( WCPE_Settings::OPTION ); ?>[hide_price_cart]" value="1" <?php checked( $settings['hide_price_cart'], 1 ); ?>>
								<?php esc_html_e( 'Catalogue mode: hide the price and Add to cart button on enquiry products', 'wc-product-enquiry' ); ?>
							</label>
						</td>
					</tr>

					<tr>
						<th scope="row"><label for="wcpe_rate_limit"><?php esc_html_e( 'Submissions per hour', 'wc-product-enquiry' ); ?></label></th>
						<td>
							<input type="number" min="0" max="100" step="1" id="wcpe_rate_limit" name="<?php echo esc_attr( WCPE_Settings::OPTION ); ?>[rate_limit]" value="<?php echo esc_attr( $settings['rate_limit'] ); ?>">
							<p class="description"><?php esc_html_e( 'Maximum enquiries accepted from one IP address per hour. Set to 0 to turn the limit off.', 'wc-product-enquiry' ); ?></p>
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Save settings', 'wc-product-enquiry' ) ); ?>
			</form>

			<hr>

			<h2><?php esc_html_e( 'Add product details to your emails', 'wc-product-enquiry' ); ?></h2>
			<p><?php esc_html_e( 'Open your form in Contact → Contact Forms, switch to the Mail tab, and paste these tags into the message body. They are filled in from the database when the enquiry is sent.', 'wc-product-enquiry' ); ?></p>
			<textarea readonly rows="8" class="large-text code" onclick="this.select()">Product: [_wcpe_product_name]
SKU: [_wcpe_product_sku]
Price: [_wcpe_product_price]
Category: [_wcpe_product_cats]
Page: [_wcpe_product_url]
Product ID: [_wcpe_product_id]</textarea>
			<p class="description"><?php esc_html_e( 'Tip: put [_wcpe_product_name] in the Subject line too, so enquiries are easy to scan in your inbox.', 'wc-product-enquiry' ); ?></p>
		</div>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Per-product override
	 * ------------------------------------------------------------------ */

	/**
	 * Add a select to Product data → Advanced.
	 */
	public function product_field() {
		woocommerce_wp_select(
			array(
				'id'          => '_wcpe_enabled',
				'label'       => __( 'Enquiry button', 'wc-product-enquiry' ),
				'description' => __( 'Override the global setting for this product only.', 'wc-product-enquiry' ),
				'desc_tip'    => true,
				'options'     => array(
					''    => __( 'Use the global setting', 'wc-product-enquiry' ),
					'yes' => __( 'Always show', 'wc-product-enquiry' ),
					'no'  => __( 'Never show', 'wc-product-enquiry' ),
				),
			)
		);
	}

	/**
	 * Save it.
	 *
	 * WooCommerce has already verified its own nonce and the user's capability
	 * before this hook fires, so we only need to validate the VALUE. The
	 * whitelist below means the meta field can only ever hold '', 'yes' or 'no'.
	 *
	 * @param WC_Product $product Product being saved.
	 */
	public function save_product_field( $product ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified upstream by WooCommerce.
		$value = isset( $_POST['_wcpe_enabled'] ) ? sanitize_key( wp_unslash( $_POST['_wcpe_enabled'] ) ) : '';

		if ( ! in_array( $value, array( '', 'yes', 'no' ), true ) ) {
			$value = '';
		}

		$product->update_meta_data( '_wcpe_enabled', $value );
	}
}
