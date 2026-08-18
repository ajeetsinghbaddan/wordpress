<?php
/**
 * Settings screen and option access.
 *
 * @package GuestPostSubmissions
 */

defined( 'ABSPATH' ) || exit;

/**
 * Owns the plugin's configuration.
 *
 * DESIGN NOTE: everything is stored in ONE option (an array) rather than 15
 * separate options. WordPress autoloads options on every request, so 15 rows
 * means 15 entries in the autoload cache. One array = one row = one lookup,
 * and get_option() caches it for the whole request.
 */
class GPS_Settings {

	const OPTION_KEY = 'gps_settings';

	/**
	 * Cached copy for the current request.
	 *
	 * @var array|null
	 */
	private static $cache = null;

	/**
	 * Hook the settings screen.
	 */
	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
	}

	/**
	 * Default configuration.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'notify_email'        => get_option( 'admin_email' ),
			'notify_author'       => 1,     // Email the guest on approve/reject.
			'attribution_user'    => 0,     // WP user that owns published guest posts.
			'allowed_categories'  => array(),
			'default_category'    => (int) get_option( 'default_category' ),
			'allow_tags'          => 1,
			'max_tags'            => 5,
			'min_words'           => 150,
			'max_words'           => 3000,
			'allow_image'         => 1,
			'max_image_kb'        => 2048,  // 2 MB.
			'require_consent'     => 1,
			'consent_text'        => '',
			'throttle_max'        => 3,     // Submissions...
			'throttle_window'     => 3600,  // ...per this many seconds, per IP.
			'min_fill_seconds'    => 5,     // Bot time-trap threshold.
			'delete_data_on_uninstall' => 0,
		);
	}

	/**
	 * Write defaults on activation without clobbering existing values.
	 */
	public static function seed_defaults() {
		$existing = get_option( self::OPTION_KEY, array() );

		if ( ! is_array( $existing ) ) {
			$existing = array();
		}

		$defaults = self::defaults();

		// Attribute published posts to whoever activated the plugin, so there
		// is always a valid author account out of the box.
		if ( empty( $existing['attribution_user'] ) ) {
			$defaults['attribution_user'] = get_current_user_id();
		}

		update_option( self::OPTION_KEY, array_merge( $defaults, $existing ), false );
	}

	/**
	 * Read one setting.
	 *
	 * @param string $key     Setting name.
	 * @param mixed  $fallback Value if the key is missing.
	 * @return mixed
	 */
	public static function get( $key, $fallback = null ) {
		if ( null === self::$cache ) {
			$stored = get_option( self::OPTION_KEY, array() );
			self::$cache = array_merge( self::defaults(), is_array( $stored ) ? $stored : array() );
		}

		if ( array_key_exists( $key, self::$cache ) ) {
			return self::$cache[ $key ];
		}

		return $fallback;
	}

	/**
	 * Register the option, sections and fields with the Settings API.
	 *
	 * WHY THE SETTINGS API AND NOT A HAND-ROLLED FORM?
	 * register_setting() gives you, for free: nonce generation and
	 * verification, the capability check on options.php, the "Settings saved"
	 * notice, and a guaranteed sanitize callback that runs on EVERY write --
	 * including writes made by other code calling update_option(). Hand-rolled
	 * settings forms are one of the most common sources of plugin
	 * vulnerabilities precisely because people forget one of those steps.
	 */
	public static function register_settings() {
		register_setting(
			'gps_settings_group',
			self::OPTION_KEY,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => self::defaults(),
			)
		);

		add_settings_section(
			'gps_section_general',
			__( 'Submission rules', 'guest-post-submissions' ),
			function () {
				echo '<p>' . esc_html__( 'Control what visitors are allowed to submit.', 'guest-post-submissions' ) . '</p>';
			},
			'gps-settings'
		);

		add_settings_section(
			'gps_section_moderation',
			__( 'Moderation and notifications', 'guest-post-submissions' ),
			'__return_false',
			'gps-settings'
		);

		add_settings_section(
			'gps_section_security',
			__( 'Spam and abuse controls', 'guest-post-submissions' ),
			function () {
				echo '<p>' . esc_html__( 'These limits apply per visitor IP address and cannot be bypassed from the browser.', 'guest-post-submissions' ) . '</p>';
			},
			'gps-settings'
		);

		$fields = array(
			array( 'min_words', __( 'Minimum words', 'guest-post-submissions' ), 'number', 'gps_section_general' ),
			array( 'max_words', __( 'Maximum words', 'guest-post-submissions' ), 'number', 'gps_section_general' ),
			array( 'allowed_categories', __( 'Selectable categories', 'guest-post-submissions' ), 'categories', 'gps_section_general' ),
			array( 'allow_tags', __( 'Allow tags', 'guest-post-submissions' ), 'checkbox', 'gps_section_general' ),
			array( 'max_tags', __( 'Maximum tags', 'guest-post-submissions' ), 'number', 'gps_section_general' ),
			array( 'allow_image', __( 'Allow featured image', 'guest-post-submissions' ), 'checkbox', 'gps_section_general' ),
			array( 'max_image_kb', __( 'Maximum image size (KB)', 'guest-post-submissions' ), 'number', 'gps_section_general' ),
			array( 'require_consent', __( 'Require consent checkbox', 'guest-post-submissions' ), 'checkbox', 'gps_section_general' ),
			array( 'consent_text', __( 'Consent wording', 'guest-post-submissions' ), 'text', 'gps_section_general' ),

			array( 'notify_email', __( 'Notify this address', 'guest-post-submissions' ), 'email', 'gps_section_moderation' ),
			array( 'notify_author', __( 'Email the guest on a decision', 'guest-post-submissions' ), 'checkbox', 'gps_section_moderation' ),
			array( 'attribution_user', __( 'Publish guest posts as', 'guest-post-submissions' ), 'user', 'gps_section_moderation' ),

			array( 'throttle_max', __( 'Submissions allowed per IP', 'guest-post-submissions' ), 'number', 'gps_section_security' ),
			array( 'throttle_window', __( 'Throttle window (seconds)', 'guest-post-submissions' ), 'number', 'gps_section_security' ),
			array( 'min_fill_seconds', __( 'Minimum seconds to fill the form', 'guest-post-submissions' ), 'number', 'gps_section_security' ),
			array( 'delete_data_on_uninstall', __( 'Delete all data when the plugin is deleted', 'guest-post-submissions' ), 'checkbox', 'gps_section_security' ),
		);

		foreach ( $fields as $field ) {
			list( $key, $label, $type, $section ) = $field;

			add_settings_field(
				'gps_field_' . $key,
				$label,
				array( __CLASS__, 'render_field' ),
				'gps-settings',
				$section,
				array(
					'key'       => $key,
					'type'      => $type,
					'label_for' => 'gps_field_' . $key,
				)
			);
		}
	}

	/**
	 * Render one settings field.
	 *
	 * Note every single output is escaped. esc_attr() for attribute values,
	 * esc_html() for text nodes. Escaping happens at the point of output --
	 * never "in advance" when saving -- because the correct escaping function
	 * depends on the context you are printing into.
	 *
	 * @param array $args Field args.
	 */
	public static function render_field( $args ) {
		$key   = $args['key'];
		$type  = $args['type'];
		$value = self::get( $key );
		$name  = self::OPTION_KEY . '[' . $key . ']';
		$id    = 'gps_field_' . $key;

		switch ( $type ) {
			case 'checkbox':
				printf(
					'<input type="checkbox" id="%1$s" name="%2$s" value="1" %3$s />',
					esc_attr( $id ),
					esc_attr( $name ),
					checked( 1, (int) $value, false )
				);
				break;

			case 'number':
				printf(
					'<input type="number" min="0" step="1" class="small-text" id="%1$s" name="%2$s" value="%3$s" />',
					esc_attr( $id ),
					esc_attr( $name ),
					esc_attr( $value )
				);
				break;

			case 'email':
				printf(
					'<input type="email" class="regular-text" id="%1$s" name="%2$s" value="%3$s" />',
					esc_attr( $id ),
					esc_attr( $name ),
					esc_attr( $value )
				);
				break;

			case 'user':
				wp_dropdown_users(
					array(
						'name'             => $name,
						'id'               => $id,
						'selected'         => (int) $value,
						'show_option_none' => __( 'No attribution account', 'guest-post-submissions' ),
						'option_none_value' => 0,
						// Only offer accounts that can actually own a post.
						'capability'       => array( 'edit_posts' ),
					)
				);
				echo '<p class="description">' . esc_html__( 'Approved posts are owned by this account but display the guest author name.', 'guest-post-submissions' ) . '</p>';
				break;

			case 'categories':
				$selected   = (array) $value;
				$categories = get_categories( array( 'hide_empty' => false ) );

				echo '<fieldset>';
				foreach ( $categories as $category ) {
					printf(
						'<label style="display:block;margin-bottom:4px"><input type="checkbox" name="%1$s[]" value="%2$d" %3$s /> %4$s</label>',
						esc_attr( $name ),
						(int) $category->term_id,
						checked( true, in_array( (int) $category->term_id, array_map( 'intval', $selected ), true ), false ),
						esc_html( $category->name )
					);
				}
				echo '</fieldset>';
				echo '<p class="description">' . esc_html__( 'Leave all unchecked to offer every category.', 'guest-post-submissions' ) . '</p>';
				break;

			default:
				printf(
					'<input type="text" class="regular-text" id="%1$s" name="%2$s" value="%3$s" />',
					esc_attr( $id ),
					esc_attr( $name ),
					esc_attr( $value )
				);
		}
	}

	/**
	 * Sanitize the whole option array before it is written to the database.
	 *
	 * This is the security boundary for settings. Anything arriving in $input
	 * came from an HTTP request and is untrusted, even though only an admin can
	 * reach this screen -- an admin can still be the victim of a CSRF or XSS
	 * chain, and "trusted user" is not the same as "trusted input".
	 *
	 * Note the structure: we build a NEW array from known keys rather than
	 * filtering the submitted one. An allowlist cannot be bypassed by adding
	 * unexpected keys; a denylist can.
	 *
	 * @param mixed $input Raw submitted value.
	 * @return array
	 */
	public static function sanitize( $input ) {
		$defaults = self::defaults();
		$existing = get_option( self::OPTION_KEY, array() );
		$existing = is_array( $existing ) ? array_merge( $defaults, $existing ) : $defaults;

		if ( ! is_array( $input ) ) {
			return $existing;
		}

		$clean = array();

		$clean['notify_email'] = isset( $input['notify_email'] ) && is_email( $input['notify_email'] )
			? sanitize_email( $input['notify_email'] )
			: $defaults['notify_email'];

		$clean['consent_text'] = isset( $input['consent_text'] )
			? sanitize_text_field( $input['consent_text'] )
			: '';

		// Checkboxes: absent from $_POST when unticked, so isset() IS the value.
		foreach ( array( 'notify_author', 'allow_tags', 'allow_image', 'require_consent', 'delete_data_on_uninstall' ) as $flag ) {
			$clean[ $flag ] = ! empty( $input[ $flag ] ) ? 1 : 0;
		}

		// Integers, each clamped to a sane range so a typo cannot break the site.
		$clean['attribution_user'] = isset( $input['attribution_user'] ) ? absint( $input['attribution_user'] ) : 0;
		$clean['default_category'] = isset( $input['default_category'] ) ? absint( $input['default_category'] ) : $defaults['default_category'];
		$clean['min_words']        = self::clamp( $input, 'min_words', 0, 10000, $defaults['min_words'] );
		$clean['max_words']        = self::clamp( $input, 'max_words', 50, 50000, $defaults['max_words'] );
		$clean['max_tags']         = self::clamp( $input, 'max_tags', 0, 20, $defaults['max_tags'] );
		$clean['max_image_kb']     = self::clamp( $input, 'max_image_kb', 50, 10240, $defaults['max_image_kb'] );
		$clean['throttle_max']     = self::clamp( $input, 'throttle_max', 1, 100, $defaults['throttle_max'] );
		$clean['throttle_window']  = self::clamp( $input, 'throttle_window', 60, DAY_IN_SECONDS, $defaults['throttle_window'] );
		$clean['min_fill_seconds'] = self::clamp( $input, 'min_fill_seconds', 0, 120, $defaults['min_fill_seconds'] );

		// Guard against min > max, which would make every submission fail.
		if ( $clean['min_words'] >= $clean['max_words'] ) {
			$clean['min_words'] = max( 0, $clean['max_words'] - 50 );
		}

		/*
		 * Category IDs: cast to int AND verify the term actually exists.
		 * Casting alone would still let someone store the ID of a deleted or
		 * non-category term, which would then be offered in the form.
		 */
		$clean['allowed_categories'] = array();
		if ( ! empty( $input['allowed_categories'] ) && is_array( $input['allowed_categories'] ) ) {
			foreach ( $input['allowed_categories'] as $term_id ) {
				$term_id = absint( $term_id );
				if ( $term_id && term_exists( $term_id, 'category' ) ) {
					$clean['allowed_categories'][] = $term_id;
				}
			}
		}

		// Reset the request cache so the next get() sees the new values.
		self::$cache = null;

		return $clean;
	}

	/**
	 * Read an integer from input and constrain it to a range.
	 *
	 * @param array  $input   Raw input.
	 * @param string $key     Key to read.
	 * @param int    $min     Lower bound.
	 * @param int    $max     Upper bound.
	 * @param int    $default Value when absent.
	 * @return int
	 */
	private static function clamp( $input, $key, $min, $max, $default ) {
		if ( ! isset( $input[ $key ] ) || '' === $input[ $key ] ) {
			return (int) $default;
		}

		return (int) min( $max, max( $min, (int) $input[ $key ] ) );
	}

	/**
	 * Render the settings page.
	 */
	public static function render_page() {
		// Defence in depth: the menu is already capability-gated, but a direct
		// request to the page slug must be blocked too.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to change these settings.', 'guest-post-submissions' ) );
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Guest Post Submissions', 'guest-post-submissions' ); ?></h1>

			<div class="gps-shortcode-hint">
				<p>
					<?php esc_html_e( 'Add the submission form to any page or post with this shortcode:', 'guest-post-submissions' ); ?>
					<code>[guest_post_form]</code>
				</p>
			</div>

			<form action="options.php" method="post">
				<?php
				/*
				 * settings_fields() prints the nonce, the option group and the
				 * _wp_http_referer. Without it options.php rejects the request.
				 * This is the CSRF protection for the settings screen.
				 */
				settings_fields( 'gps_settings_group' );
				do_settings_sections( 'gps-settings' );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}
}
