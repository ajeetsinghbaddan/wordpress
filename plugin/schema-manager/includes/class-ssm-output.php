<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SSM_Output {

	public function __construct() {
		add_action( 'wp_head', array( $this, 'print_schema' ), 5 );
	}

	public function print_schema() {
		$settings = ssm_get_settings();

		if ( is_front_page() || is_home() ) {
			$this->render_json_ld( $this->build_website_schema( $settings ) );
		}

		if ( ! is_singular( array( 'post', 'page' ) ) ) {
			return;
		}

		$post_id = get_queried_object_id();

		$custom_schema = get_post_meta( $post_id, SSM_Metabox::META_SCHEMA, true );

		if ( ! empty( $custom_schema ) ) {
			$decoded = json_decode( $custom_schema, true );
			if ( is_array( $decoded ) ) {
				$this->render_json_ld( $decoded );
			}
		}

		if ( 'automatic' !== $settings['mode'] ) {
			return;
		}

		if ( '1' === get_post_meta( $post_id, SSM_Metabox::META_DISABLE, true ) ) {
			return;
		}

		if ( is_singular( 'post' ) && empty( $settings['enable_posts'] ) ) {
			return;
		}

		if ( is_singular( 'page' ) && empty( $settings['enable_pages'] ) ) {
			return;
		}

		$this->render_json_ld( $this->build_content_schema( $post_id, $settings ) );
	}

	private function build_website_schema( $settings ) {
		$schema = array(
			'@context'    => 'https://schema.org',
			'@type'       => $settings['organization_type'],
			'name'        => $settings['organization_name'],
			'url'         => home_url( '/' ),
			'description' => $settings['site_description'],
		);

		if ( ! empty( $settings['logo_url'] ) ) {
			$schema['logo'] = $settings['logo_url'];
		}

		if ( ! empty( $settings['social_profiles'] ) ) {
			$schema['sameAs'] = array_values( array_filter( explode( "\n", $settings['social_profiles'] ) ) );
		}

		return $schema;
	}

	private function build_content_schema( $post_id, $settings ) {
		$post = get_post( $post_id );

		$type = ( 'post' === $post->post_type ) ? 'Article' : 'WebPage';

		$schema = array(
			'@context'      => 'https://schema.org',
			'@type'         => $type,
			'headline'      => get_the_title( $post_id ),
			'url'           => get_permalink( $post_id ),
			'datePublished' => get_the_date( 'c', $post_id ),
			'dateModified'  => get_the_modified_date( 'c', $post_id ),
			'description'   => $this->get_description( $post ),
			'author'        => array(
				'@type' => 'Person',
				'name'  => get_the_author_meta( 'display_name', $post->post_author ),
			),
			'publisher'     => array(
				'@type' => $settings['organization_type'],
				'name'  => $settings['organization_name'],
			),
		);

		if ( ! empty( $settings['logo_url'] ) && 'Organization' === $settings['organization_type'] ) {
			$schema['publisher']['logo'] = array(
				'@type' => 'ImageObject',
				'url'   => $settings['logo_url'],
			);
		}

		if ( has_post_thumbnail( $post_id ) ) {
			$image = wp_get_attachment_image_src( get_post_thumbnail_id( $post_id ), 'full' );
			if ( $image ) {
				$schema['image'] = array(
					'@type'  => 'ImageObject',
					'url'    => $image[0],
					'width'  => $image[1],
					'height' => $image[2],
				);
			}
		}

		return $schema;
	}

	private function get_description( $post ) {
		if ( ! empty( $post->post_excerpt ) ) {
			return wp_strip_all_tags( $post->post_excerpt );
		}

		return wp_trim_words( wp_strip_all_tags( strip_shortcodes( $post->post_content ) ), 30 );
	}

	private function render_json_ld( $schema ) {
		if ( empty( $schema ) || ! is_array( $schema ) ) {
			return;
		}

		$json = wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		if ( false === $json ) {
			return;
		}

		echo "\n" . '<script type="application/ld+json">' . $json . '</script>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}
