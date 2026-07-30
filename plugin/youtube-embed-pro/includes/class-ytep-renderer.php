<?php
/**
 * Single place where embed HTML is built. Both the shortcode and the block
 * go through here, so there is only one set of escaping rules to audit.
 *
 * @package YouTube_Embed_Pro
 */

defined( 'ABSPATH' ) || exit;

class YTEP_Renderer {

	const STYLE_HANDLE  = 'ytep-embed';
	const SCRIPT_HANDLE = 'ytep-facade';

	/**
	 * Aspect ratios we allow. Never interpolate raw user text into a style
	 * attribute; map it through a whitelist instead.
	 *
	 * @return array
	 */
	public static function ratios() {
		return array(
			'16:9' => '16 / 9',
			'9:16' => '9 / 16',
			'4:3'  => '4 / 3',
			'3:2'  => '3 / 2',
			'1:1'  => '1 / 1',
			'21:9' => '21 / 9',
		);
	}

	public static function types() {
		return array( 'auto', 'video', 'short', 'playlist', 'live', 'channel' );
	}

	public static function register_assets() {
		if ( ! wp_style_is( self::STYLE_HANDLE, 'registered' ) ) {
			wp_register_style( self::STYLE_HANDLE, YTEP_URL . 'assets/css/ytep.css', array(), YTEP_VERSION );
		}
		if ( ! wp_script_is( self::SCRIPT_HANDLE, 'registered' ) ) {
			wp_register_script( self::SCRIPT_HANDLE, YTEP_URL . 'assets/js/ytep-facade.js', array(), YTEP_VERSION, true );
		}
	}

	/**
	 * Load assets only on pages that actually contain an embed.
	 *
	 * @param bool $needs_script Whether the click-to-load script is required.
	 */
	protected static function enqueue_assets( $needs_script ) {
		self::register_assets();
		wp_enqueue_style( self::STYLE_HANDLE );
		if ( $needs_script ) {
			wp_enqueue_script( self::SCRIPT_HANDLE );
		}
	}

	/**
	 * Turn "yes"/"no"/"true"/"1"/"on" into a real boolean.
	 * wp_validate_boolean() treats the string "no" as true, so we do it ourselves.
	 *
	 * @param mixed $value Raw value.
	 * @param bool  $default Fallback when the value is unrecognised.
	 * @return bool
	 */
	public static function to_bool( $value, $default = false ) {
		if ( is_bool( $value ) ) {
			return $value;
		}
		if ( is_numeric( $value ) ) {
			return (bool) (int) $value;
		}
		$value = strtolower( trim( (string) $value ) );
		if ( in_array( $value, array( 'yes', 'true', 'on', '1' ), true ) ) {
			return true;
		}
		if ( in_array( $value, array( 'no', 'false', 'off', '0' ), true ) ) {
			return false;
		}
		return $default;
	}

	/**
	 * Normalise every input to a known type and range before it is used.
	 *
	 * @param array $args Raw arguments from a shortcode or block.
	 * @return array
	 */
	public static function sanitize_args( array $args ) {
		$defaults = array(
			'url'       => '',
			'channel'   => '',
			'kind'      => 'auto',
			'title'     => '',
			'ratio'     => '',
			'max_width' => 0,
			'start'     => 0,
			'privacy'   => true,
			'facade'    => true,
			'autoplay'  => false,
			'loop'      => false,
			'controls'  => true,
			'captions'  => false,
			'class'     => '',
		);

		$args = wp_parse_args( $args, $defaults );

		$clean              = array();
		$clean['url']       = sanitize_text_field( (string) $args['url'] );
		$clean['channel']   = YTEP_Parser::extract_channel_id( $args['channel'] );
		$clean['kind']      = in_array( $args['kind'], self::types(), true ) ? $args['kind'] : 'auto';
		$clean['title']     = sanitize_text_field( (string) $args['title'] );
		$clean['ratio']     = isset( self::ratios()[ $args['ratio'] ] ) ? $args['ratio'] : '';
		$clean['max_width'] = min( 4000, absint( $args['max_width'] ) );
		$clean['start']     = min( DAY_IN_SECONDS, absint( $args['start'] ) );
		$clean['privacy']   = self::to_bool( $args['privacy'], true );
		$clean['facade']    = self::to_bool( $args['facade'], true );
		$clean['autoplay']  = self::to_bool( $args['autoplay'], false );
		$clean['loop']      = self::to_bool( $args['loop'], false );
		$clean['controls']  = self::to_bool( $args['controls'], true );
		$clean['captions']  = self::to_bool( $args['captions'], false );

		// Only valid CSS class tokens survive.
		$classes = array_filter( array_map( 'sanitize_html_class', preg_split( '/\s+/', (string) $args['class'] ) ) );
		$clean['class'] = implode( ' ', $classes );

		return $clean;
	}

	/**
	 * Build the embed HTML.
	 *
	 * @param array $args Raw arguments.
	 * @return string Escaped HTML, or '' when the input is unusable.
	 */
	public static function render( array $args ) {
		$args = self::sanitize_args( $args );

		$wants_channel = ( 'channel' === $args['kind'] ) || ( '' !== $args['channel'] && '' === $args['url'] );

		if ( $wants_channel ) {
			if ( '' === $args['channel'] ) {
				return self::error_notice( new WP_Error( 'ytep_no_channel', __( 'Pick a channel, or save one under Settings → YouTube Embed Pro.', 'ytep' ) ) );
			}

			// Every channel UC<x> has an auto-maintained uploads playlist
			// UU<x> — same 22 characters, different prefix. Embedding it
			// shows the channel's latest videos with no API key needed.
			$parsed = array(
				'type'     => 'playlist',
				'video_id' => '',
				'list_id'  => 'UU' . substr( $args['channel'], 2 ),
				'start'    => 0,
			);
			$type   = 'playlist';
		} else {
			$parsed = YTEP_Parser::parse( $args['url'] );

			if ( is_wp_error( $parsed ) ) {
				return self::error_notice( $parsed );
			}

			// An explicit type from the editor wins over auto-detection.
			$type = ( 'auto' === $args['kind'] ) ? $parsed['type'] : $args['kind'];
		}

		if ( 'playlist' === $type && '' === $parsed['list_id'] ) {
			return self::error_notice( new WP_Error( 'ytep_no_list', __( 'That link has no playlist ID. Choose another embed type.', 'ytep' ) ) );
		}

		$video_id = $parsed['video_id'];
		$list_id  = $parsed['list_id'];
		$start    = $args['start'] ? $args['start'] : $parsed['start'];

		$origin = $args['privacy'] ? 'https://www.youtube-nocookie.com' : 'https://www.youtube.com';

		$params = array(
			'rel'            => 0,
			'playsinline'    => 1,
			'modestbranding' => 1,
		);

		if ( 'playlist' === $type && '' === $video_id ) {
			$path             = '/embed/videoseries';
			$params['list']   = $list_id;
		} else {
			$path = '/embed/' . $video_id;
			if ( $list_id ) {
				$params['list'] = $list_id;
			}
		}

		if ( $start ) {
			$params['start'] = $start;
		}
		if ( ! $args['controls'] ) {
			$params['controls'] = 0;
		}
		if ( $args['captions'] ) {
			$params['cc_load_policy'] = 1;
		}
		if ( $args['loop'] ) {
			$params['loop'] = 1;
			if ( $video_id && ! $list_id ) {
				// A single video only loops if it is also passed as a one-item playlist.
				$params['playlist'] = $video_id;
			}
		}
		if ( $args['autoplay'] ) {
			$params['autoplay'] = 1;
			$params['mute']     = 1; // Browsers block unmuted autoplay anyway.
		}

		$src = $origin . $path . '?' . http_build_query( $params, '', '&' );

		$ratio     = $args['ratio'] ? self::ratios()[ $args['ratio'] ] : ( 'short' === $type ? '9 / 16' : '16 / 9' );
		$max_width = $args['max_width'] ? $args['max_width'] : ( 'short' === $type ? 420 : 0 );

		$style = '--ytep-ratio:' . $ratio . ';';
		if ( $max_width ) {
			$style .= '--ytep-max-width:' . $max_width . 'px;';
		}

		$title = $args['title'] ? $args['title'] : __( 'YouTube video player', 'ytep' );

		// A facade needs a thumbnail, which only exists for a specific video.
		$use_facade = $args['facade'] && ! $args['autoplay'] && '' !== $video_id;

		$classes = array( 'ytep', 'ytep--' . $type );
		if ( $use_facade ) {
			$classes[] = 'ytep--facade';
		}
		if ( $args['class'] ) {
			$classes[] = $args['class'];
		}

		self::enqueue_assets( $use_facade );

		if ( $use_facade ) {
			$facade_src = $src . '&autoplay=1';
			$thumbnail  = 'https://i.ytimg.com/vi/' . $video_id . '/hqdefault.jpg';

			$inner = sprintf(
				'<button type="button" class="ytep__poster" data-ytep-src="%1$s" data-ytep-title="%2$s" aria-label="%3$s">
					<img src="%4$s" alt="" loading="lazy" decoding="async" width="480" height="360">
					<span class="ytep__play" aria-hidden="true"></span>
				</button>',
				esc_url( $facade_src ),
				esc_attr( $title ),
				/* translators: %s: video title. */
				esc_attr( sprintf( __( 'Play %s', 'ytep' ), $title ) ),
				esc_url( $thumbnail )
			);
		} else {
			$inner = sprintf(
				'<iframe class="ytep__frame" src="%1$s" title="%2$s" loading="lazy" referrerpolicy="strict-origin-when-cross-origin" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share" allowfullscreen></iframe>',
				esc_url( $src ),
				esc_attr( $title )
			);
		}

		return sprintf(
			'<div class="%1$s" style="%2$s">%3$s</div>',
			esc_attr( implode( ' ', $classes ) ),
			esc_attr( $style ),
			$inner
		);
	}

	/**
	 * Editors see what went wrong; visitors see nothing.
	 *
	 * @param WP_Error $error The failure.
	 * @return string
	 */
	protected static function error_notice( WP_Error $error ) {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return '';
		}
		return '<p class="ytep-error">' . esc_html( $error->get_error_message() ) . '</p>';
	}
}
