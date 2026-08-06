<?php
/**
 * Plugin Name:       Product Enquiry for WooCommerce (CF7)
 * Plugin URI:        https://github.com/ajeetsinghbaddan/wordpress/tree/main/plugin/wc-product-enquiry
 * Description:       Adds an "Enquire about this product" button to WooCommerce product pages. Opens a Contact Form 7 form in an accessible popup and attaches the product name, SKU, price and page URL to the enquiry email.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Ajeet Singh Baddan
 * License:           GPL-2.0-or-later
 * Text Domain:       wc-product-enquiry
 * Requires Plugins:  contact-form-7, woocommerce
 *
 * @package WC_Product_Enquiry
 */

defined( 'ABSPATH' ) || exit; // Block direct file access — the #1 baseline security rule for WP plugins.

define( 'WCPE_VERSION', '1.0.2' );
define( 'WCPE_FILE', __FILE__ );
define( 'WCPE_PATH', plugin_dir_path( __FILE__ ) );
define( 'WCPE_URL', plugin_dir_url( __FILE__ ) );

require_once WCPE_PATH . 'includes/class-wcpe-settings.php';
require_once WCPE_PATH . 'includes/class-wcpe-cf7.php';
require_once WCPE_PATH . 'includes/class-wcpe-frontend.php';
require_once WCPE_PATH . 'includes/class-wcpe-admin.php';

/**
 * Are both required plugins actually active?
 *
 * We check for classes/functions rather than file paths, because a plugin can be
 * active but broken, and because multisite network-activation makes path checks
 * unreliable.
 *
 * @return bool
 */
function wcpe_dependencies_met() {
	return class_exists( 'WooCommerce' ) && class_exists( 'WPCF7_ContactForm' );
}

/**
 * Boot the plugin.
 *
 * Hooked late on `plugins_loaded` (priority 20) so WooCommerce and CF7 have
 * already declared their classes by the time we look for them.
 */
function wcpe_bootstrap() {
	if ( ! wcpe_dependencies_met() ) {
		add_action( 'admin_notices', 'wcpe_dependency_notice' );
		return; // Fail quietly on the front end instead of throwing fatal errors.
	}

	new WCPE_CF7();
	new WCPE_Frontend();

	if ( is_admin() ) {
		new WCPE_Admin();
	}
}
add_action( 'plugins_loaded', 'wcpe_bootstrap', 20 );

/**
 * Tell the shop owner what is missing, in the admin only.
 */
function wcpe_dependency_notice() {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return; // Capability check: never leak setup info to subscribers.
	}

	$missing = array();

	if ( ! class_exists( 'WooCommerce' ) ) {
		$missing[] = 'WooCommerce';
	}
	if ( ! class_exists( 'WPCF7_ContactForm' ) ) {
		$missing[] = 'Contact Form 7';
	}

	printf(
		'<div class="notice notice-error"><p>%s</p></div>',
		esc_html(
			sprintf(
				/* translators: %s: comma separated plugin names. */
				__( 'Product Enquiry for WooCommerce needs these plugins active: %s', 'wc-product-enquiry' ),
				implode( ', ', $missing )
			)
		)
	);
}

/**
 * Declare compatibility with WooCommerce High-Performance Order Storage.
 *
 * Without this, WooCommerce shows an "incompatible plugin" warning on the
 * HPOS settings screen even though we never touch orders.
 */
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', WCPE_FILE, true );
		}
	}
);

/**
 * Load translations on `init` (required since WP 6.7 — calling it earlier
 * triggers a "doing it wrong" notice).
 */
add_action(
	'init',
	function () {
		load_plugin_textdomain( 'wc-product-enquiry', false, dirname( plugin_basename( WCPE_FILE ) ) . '/languages' );
	}
);
