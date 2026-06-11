<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SSM_Settings {

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menu_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	public function add_menu_page() {
		add_options_page(
			__( 'Schema Manager', 'simple-schema-manager' ),
			__( 'Schema Manager', 'simple-schema-manager' ),
			'manage_options',
			'ssm-settings',
			array( $this, 'render_page' )
		);
	}

	public function register_settings() {
		register_setting(
			'ssm_settings_group',
			SSM_OPTION_KEY,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
			)
		);

		add_settings_section(
			'ssm_general_section',
			__( 'General Details', 'simple-schema-manager' ),
			array( $this, 'render_general_intro' ),
			'ssm-settings'
		);

		add_settings_field( 'mode', __( 'Schema Mode', 'simple-schema-manager' ), array( $this, 'field_mode' ), 'ssm-settings', 'ssm_general_section' );
		add_settings_field( 'organization_name', __( 'Organization / Person Name', 'simple-schema-manager' ), array( $this, 'field_org_name' ), 'ssm-settings', 'ssm_general_section' );
		add_settings_field( 'organization_type', __( 'Entity Type', 'simple-schema-manager' ), array( $this, 'field_org_type' ), 'ssm-settings', 'ssm_general_section' );
		add_settings_field( 'logo_url', __( 'Logo URL', 'simple-schema-manager' ), array( $this, 'field_logo' ), 'ssm-settings', 'ssm_general_section' );
		add_settings_field( 'site_description', __( 'Site Description', 'simple-schema-manager' ), array( $this, 'field_description' ), 'ssm-settings', 'ssm_general_section' );
		add_settings_field( 'social_profiles', __( 'Social Profile URLs (one per line)', 'simple-schema-manager' ), array( $this, 'field_social' ), 'ssm-settings', 'ssm_general_section' );
		add_settings_field( 'post_types', __( 'Apply Automatic Schema To', 'simple-schema-manager' ), array( $this, 'field_post_types' ), 'ssm-settings', 'ssm_general_section' );
	}

	public function sanitize( $input ) {
		$clean = array();

		$clean['mode'] = ( isset( $input['mode'] ) && in_array( $input['mode'], array( 'automatic', 'manual' ), true ) )
			? $input['mode']
			: 'automatic';

		$clean['organization_name'] = isset( $input['organization_name'] )
			? sanitize_text_field( $input['organization_name'] )
			: '';

		$clean['organization_type'] = ( isset( $input['organization_type'] ) && in_array( $input['organization_type'], array( 'Organization', 'Person' ), true ) )
			? $input['organization_type']
			: 'Organization';

		$clean['logo_url'] = isset( $input['logo_url'] )
			? esc_url_raw( $input['logo_url'] )
			: '';

		$clean['site_description'] = isset( $input['site_description'] )
			? sanitize_textarea_field( $input['site_description'] )
			: '';

		$clean['social_profiles'] = '';
		if ( isset( $input['social_profiles'] ) ) {
			$lines = explode( "\n", $input['social_profiles'] );
			$urls  = array();
			foreach ( $lines as $line ) {
				$url = esc_url_raw( trim( $line ) );
				if ( ! empty( $url ) ) {
					$urls[] = $url;
				}
			}
			$clean['social_profiles'] = implode( "\n", $urls );
		}

		$clean['enable_posts'] = ! empty( $input['enable_posts'] ) ? 1 : 0;
		$clean['enable_pages'] = ! empty( $input['enable_pages'] ) ? 1 : 0;

		return $clean;
	}

	public function render_general_intro() {
		echo '<p>' . esc_html__( 'These details are used to build the schema markup added to your site.', 'simple-schema-manager' ) . '</p>';
	}

	public function field_mode() {
		$settings = ssm_get_settings();
		?>
		<label>
			<input type="radio" name="<?php echo esc_attr( SSM_OPTION_KEY ); ?>[mode]" value="automatic" <?php checked( $settings['mode'], 'automatic' ); ?>>
			<?php esc_html_e( 'Automatic — schema is generated for every post and page', 'simple-schema-manager' ); ?>
		</label><br>
		<label>
			<input type="radio" name="<?php echo esc_attr( SSM_OPTION_KEY ); ?>[mode]" value="manual" <?php checked( $settings['mode'], 'manual' ); ?>>
			<?php esc_html_e( 'Manual — schema is only printed where you add it via the post editor box', 'simple-schema-manager' ); ?>
		</label>
		<?php
	}

	public function field_org_name() {
		$settings = ssm_get_settings();
		printf(
			'<input type="text" class="regular-text" name="%s[organization_name]" value="%s">',
			esc_attr( SSM_OPTION_KEY ),
			esc_attr( $settings['organization_name'] )
		);
	}

	public function field_org_type() {
		$settings = ssm_get_settings();
		?>
		<select name="<?php echo esc_attr( SSM_OPTION_KEY ); ?>[organization_type]">
			<option value="Organization" <?php selected( $settings['organization_type'], 'Organization' ); ?>><?php esc_html_e( 'Organization', 'simple-schema-manager' ); ?></option>
			<option value="Person" <?php selected( $settings['organization_type'], 'Person' ); ?>><?php esc_html_e( 'Person', 'simple-schema-manager' ); ?></option>
		</select>
		<?php
	}

	public function field_logo() {
		$settings = ssm_get_settings();
		printf(
			'<input type="url" class="regular-text" name="%s[logo_url]" value="%s" placeholder="https://example.com/logo.png">',
			esc_attr( SSM_OPTION_KEY ),
			esc_attr( $settings['logo_url'] )
		);
	}

	public function field_description() {
		$settings = ssm_get_settings();
		printf(
			'<textarea class="large-text" rows="3" name="%s[site_description]">%s</textarea>',
			esc_attr( SSM_OPTION_KEY ),
			esc_textarea( $settings['site_description'] )
		);
	}

	public function field_social() {
		$settings = ssm_get_settings();
		printf(
			'<textarea class="large-text" rows="4" name="%s[social_profiles]" placeholder="https://twitter.com/yourhandle">%s</textarea>',
			esc_attr( SSM_OPTION_KEY ),
			esc_textarea( $settings['social_profiles'] )
		);
	}

	public function field_post_types() {
		$settings = ssm_get_settings();
		?>
		<label>
			<input type="checkbox" name="<?php echo esc_attr( SSM_OPTION_KEY ); ?>[enable_posts]" value="1" <?php checked( $settings['enable_posts'], 1 ); ?>>
			<?php esc_html_e( 'Posts', 'simple-schema-manager' ); ?>
		</label><br>
		<label>
			<input type="checkbox" name="<?php echo esc_attr( SSM_OPTION_KEY ); ?>[enable_pages]" value="1" <?php checked( $settings['enable_pages'], 1 ); ?>>
			<?php esc_html_e( 'Pages', 'simple-schema-manager' ); ?>
		</label>
		<?php
	}

	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			<form method="post" action="options.php">
				<?php
				settings_fields( 'ssm_settings_group' );
				do_settings_sections( 'ssm-settings' );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}
}
