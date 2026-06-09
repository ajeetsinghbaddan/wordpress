<?php
/**
 * Plugin settings page (default design + default category) using the Settings API.
 *
 * @package ASB_Testimonials_Showcase
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ASB_TS_Settings
 *
 * Uses the WordPress Settings API (register_setting / add_settings_section /
 * add_settings_field). The big advantage of the Settings API is that WordPress
 * handles the form submission, nonce verification and saving for us via
 * options.php — we only have to register fields and a sanitisation callback.
 * That callback is the single trusted gateway for all settings input.
 */
class ASB_TS_Settings {

	/**
	 * The single option name in wp_options. We store ALL settings as one array
	 * under this key, which keeps the options table tidy and lets us sanitise
	 * everything in one callback.
	 */
	const OPTION_KEY = 'asb_ts_settings';

	/**
	 * Settings group name used by register_setting + settings_fields().
	 */
	const GROUP = 'asb_ts_settings_group';

	/**
	 * Hooks: add the admin submenu page and register the settings.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	/**
	 * Return the saved settings merged over the defaults, so missing keys always
	 * have a safe fallback value. Static so other components can read settings
	 * without instantiating this class.
	 *
	 * @return array
	 */
	public static function get_settings() {
		$defaults = self::get_defaults();
		$saved    = get_option( self::OPTION_KEY, array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), $defaults );
	}

	/**
	 * Default values for every setting.
	 *
	 * @return array
	 */
	public static function get_defaults() {
		return array(
			'default_design'   => 'grid',  // One of the 6 layout keys.
			'default_category' => '',       // Empty = all categories.
			'default_count'    => 6,
			'delete_data'      => 0,        // If 1, uninstall also removes testimonials.
		);
	}

	/**
	 * Seed defaults into the DB on activation if no settings exist yet.
	 */
	public static function maybe_set_default_options() {
		if ( false === get_option( self::OPTION_KEY, false ) ) {
			add_option( self::OPTION_KEY, self::get_defaults() );
		}
	}

	/**
	 * Add a submenu page under the Testimonials CPT menu.
	 *
	 * The 'manage_options' capability means only administrators can open and
	 * change plugin settings — a capability check enforced by WordPress itself
	 * before the page callback runs.
	 */
	public function add_settings_page() {
		add_submenu_page(
			'edit.php?post_type=' . ASB_TS_CPT::POST_TYPE, // Parent = Testimonials menu.
			__( 'Testimonials Settings', 'asb-testimonials-showcase' ),
			__( 'Settings', 'asb-testimonials-showcase' ),
			'manage_options', // Capability required to see/use this page.
			'asb-ts-settings',
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Register the setting, its sanitisation callback, the section and fields.
	 *
	 * register_setting() ties OPTION_KEY to our sanitise_settings() callback.
	 * That callback is automatically run on every save, so it is the ONLY place
	 * settings input is trusted/cleaned. We never read $_POST here ourselves.
	 */
	public function register_settings() {
		register_setting(
			self::GROUP,
			self::OPTION_KEY,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_settings' ),
				'default'           => self::get_defaults(),
			)
		);

		add_settings_section(
			'asb_ts_main_section',
			__( 'Default display options', 'asb-testimonials-showcase' ),
			function () {
				echo '<p>' . esc_html__( 'These defaults are used whenever a shortcode, block or widget does not specify its own values.', 'asb-testimonials-showcase' ) . '</p>';
			},
			'asb-ts-settings'
		);

		add_settings_field(
			'default_design',
			__( 'Default design', 'asb-testimonials-showcase' ),
			array( $this, 'field_default_design' ),
			'asb-ts-settings',
			'asb_ts_main_section'
		);

		add_settings_field(
			'default_category',
			__( 'Default category filter', 'asb-testimonials-showcase' ),
			array( $this, 'field_default_category' ),
			'asb-ts-settings',
			'asb_ts_main_section'
		);

		add_settings_field(
			'default_count',
			__( 'Default number to show', 'asb-testimonials-showcase' ),
			array( $this, 'field_default_count' ),
			'asb-ts-settings',
			'asb_ts_main_section'
		);

		add_settings_field(
			'delete_data',
			__( 'On uninstall', 'asb-testimonials-showcase' ),
			array( $this, 'field_delete_data' ),
			'asb-ts-settings',
			'asb_ts_main_section'
		);
	}

	/**
	 * Sanitise the entire settings array before it is written to the DB.
	 *
	 * Each key is validated against what we expect:
	 *   - default_design must be one of our known layout keys (whitelist).
	 *   - default_category must be a valid term ID (or 0 for "all").
	 *   - default_count is an integer clamped to a sane 1–50 range.
	 *   - delete_data is coerced to a strict 0/1 boolean-ish int.
	 *
	 * @param array $input Raw submitted settings.
	 * @return array Clean settings safe to store.
	 */
	public function sanitize_settings( $input ) {
		$clean    = array();
		$defaults = self::get_defaults();

		// Whitelist the design against the renderer's allowed layouts.
		$allowed_designs        = array_keys( ASB_TS_Renderer::get_designs() );
		$clean['default_design'] = ( isset( $input['default_design'] ) && in_array( $input['default_design'], $allowed_designs, true ) )
			? $input['default_design']
			: $defaults['default_design'];

		// Category: must be a real term ID in our taxonomy, else 0 (= all).
		$cat = isset( $input['default_category'] ) ? absint( $input['default_category'] ) : 0;
		if ( $cat > 0 && ! term_exists( $cat, ASB_TS_CPT::TAXONOMY ) ) {
			$cat = 0;
		}
		$clean['default_category'] = $cat;

		// Count: integer between 1 and 50.
		$count                  = isset( $input['default_count'] ) ? absint( $input['default_count'] ) : $defaults['default_count'];
		$clean['default_count'] = min( 50, max( 1, $count ) );

		// Delete-data toggle: strict 0 or 1.
		$clean['delete_data'] = ( ! empty( $input['delete_data'] ) ) ? 1 : 0;

		return $clean;
	}

	/* --------------------------------------------------------------------- *
	 * Field renderers. Each prints one form control and escapes its output.
	 * --------------------------------------------------------------------- */

	/**
	 * Dropdown of the six available designs.
	 */
	public function field_default_design() {
		$settings = self::get_settings();
		$designs  = ASB_TS_Renderer::get_designs();
		echo '<select name="' . esc_attr( self::OPTION_KEY ) . '[default_design]">';
		foreach ( $designs as $key => $label ) {
			printf(
				'<option value="%1$s" %2$s>%3$s</option>',
				esc_attr( $key ),
				selected( $settings['default_design'], $key, false ),
				esc_html( $label )
			);
		}
		echo '</select>';
	}

	/**
	 * Dropdown of testimonial categories. wp_dropdown_categories handles its own
	 * escaping of the options it generates.
	 */
	public function field_default_category() {
		$settings = self::get_settings();
		wp_dropdown_categories(
			array(
				'taxonomy'         => ASB_TS_CPT::TAXONOMY,
				'name'             => self::OPTION_KEY . '[default_category]',
				'selected'         => (int) $settings['default_category'],
				'show_option_all'  => __( 'All categories', 'asb-testimonials-showcase' ),
				'hide_empty'       => false,
				'hierarchical'     => true,
				'value_field'      => 'term_id',
			)
		);
	}

	/**
	 * Numeric input for the default count.
	 */
	public function field_default_count() {
		$settings = self::get_settings();
		printf(
			'<input type="number" min="1" max="50" name="%1$s[default_count]" value="%2$s" />',
			esc_attr( self::OPTION_KEY ),
			esc_attr( $settings['default_count'] )
		);
	}

	/**
	 * Checkbox controlling whether uninstall also deletes testimonial content.
	 */
	public function field_delete_data() {
		$settings = self::get_settings();
		printf(
			'<label><input type="checkbox" name="%1$s[delete_data]" value="1" %2$s /> %3$s</label>',
			esc_attr( self::OPTION_KEY ),
			checked( 1, (int) $settings['delete_data'], false ),
			esc_html__( 'Also permanently delete all testimonials and categories when the plugin is deleted.', 'asb-testimonials-showcase' )
		);
	}

	/**
	 * Render the settings page wrapper.
	 *
	 * settings_fields() outputs the hidden nonce + option group fields that
	 * options.php verifies on submit — that is where the nonce/capability checks
	 * for the settings form live (handled by core).
	 */
	public function render_settings_page() {
		// Extra defensive capability check even though the menu already requires it.
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			<form method="post" action="options.php">
				<?php
				settings_fields( self::GROUP );           // Nonce + option group.
				do_settings_sections( 'asb-ts-settings' ); // Print our section + fields.
				submit_button();                           // Standard "Save Changes" button.
				?>
			</form>

			<hr />
			<h2><?php esc_html_e( 'How to display testimonials', 'asb-testimonials-showcase' ); ?></h2>
			<p><?php esc_html_e( 'Use any of these three methods. All support design, category and count options.', 'asb-testimonials-showcase' ); ?></p>
			<p><code>[testimonials design="slider" category="clients" count="6"]</code></p>
			<p><?php esc_html_e( 'Or add the "Testimonials Showcase" block in the editor, or the "Testimonials Showcase" widget in Elementor.', 'asb-testimonials-showcase' ); ?></p>
		</div>
		<?php
	}
}
