<?php
/**
 * Turns whatever the user pasted into a validated set of IDs.
 *
 * @package YouTube_Embed_Pro
 */

defined( 'ABSPATH' ) || exit;

class YTEP_Parser {

	/**
	 * A YouTube video ID is always 11 characters from the URL-safe base64 alphabet.
	 * Anchoring with \A and \z (not ^ and $) prevents a newline from smuggling
	 * extra characters past the check.
	 */
	const VIDEO_ID_PATTERN = '/\A[A-Za-z0-9_-]{11}\z/';

	/**
	 * Playlist IDs vary in length, so we validate the alphabet and a sane range.
	 */
	const LIST_ID_PATTERN = '/\A[A-Za-z0-9_-]{2,64}\z/';

	/**
	 * Channel IDs are exactly "UC" + 22 URL-safe base64 characters.
	 */
	const CHANNEL_ID_PATTERN = '/\AUC[A-Za-z0-9_-]{22}\z/';

	/**
	 * Accept a channel ID or a channel URL and return the bare "UC…" ID.
	 *
	 * @param string $value Raw value.
	 * @return string '' when nothing valid was found.
	 */
	public static function extract_channel_id( $value ) {
		$value = trim( (string) $value );

		if ( preg_match( self::CHANNEL_ID_PATTERN, $value ) ) {
			return $value;
		}

		// e.g. https://www.youtube.com/channel/UCxxxxxxxxxxxxxxxxxxxxxx
		if ( preg_match( '#/channel/(UC[A-Za-z0-9_-]{22})#', $value, $m ) ) {
			return $m[1];
		}

		return '';
	}

	/**
	 * Hosts we are willing to build an embed from. Anything else is rejected,
	 * so a crafted URL cannot make us frame a third-party site.
	 *
	 * @return array
	 */
	public static function allowed_hosts() {
		return (array) apply_filters(
			'ytep_allowed_hosts',
			array(
				'youtube.com',
				'www.youtube.com',
				'm.youtube.com',
				'music.youtube.com',
				'youtu.be',
				'youtube-nocookie.com',
				'www.youtube-nocookie.com',
			)
		);
	}

	/**
	 * Parse a URL or bare ID.
	 *
	 * @param string $raw User supplied value.
	 * @return array|WP_Error {type, video_id, list_id, start}
	 */
	public static function parse( $raw ) {
		$raw = trim( wp_strip_all_tags( (string) $raw ) );

		if ( '' === $raw ) {
			return new WP_Error( 'ytep_empty', __( 'Add a YouTube URL or ID.', 'ytep' ) );
		}

		// Someone pasted just the video ID.
		if ( preg_match( self::VIDEO_ID_PATTERN, $raw ) ) {
			return self::result( 'video', $raw, '', 0 );
		}

		// Someone pasted just a playlist ID.
		if ( preg_match( '/\A(?:PL|UU|LL|OL|RD|FL|SP)[A-Za-z0-9_-]{10,}\z/', $raw ) ) {
			return self::result( 'playlist', '', $raw, 0 );
		}

		// esc_url_raw() drops javascript:, data: and other unsafe schemes.
		$url   = esc_url_raw( $raw, array( 'http', 'https' ) );
		$parts = $url ? wp_parse_url( $url ) : false;

		if ( empty( $parts['host'] ) ) {
			return new WP_Error( 'ytep_bad_url', __( 'That does not look like a valid URL.', 'ytep' ) );
		}

		$host  = strtolower( $parts['host'] );
		$hosts = array_map( 'strtolower', self::allowed_hosts() );

		if ( ! in_array( $host, $hosts, true ) ) {
			return new WP_Error( 'ytep_bad_host', __( 'Only youtube.com and youtu.be links can be embedded.', 'ytep' ) );
		}

		$query = array();
		if ( ! empty( $parts['query'] ) ) {
			wp_parse_str( $parts['query'], $query );
		}

		$path     = isset( $parts['path'] ) ? $parts['path'] : '';
		$segments = array_values( array_filter( explode( '/', $path ) ) );

		// Route on a lowercased copy, but keep the raw segments: video IDs are
		// case sensitive, so "dQw4..." must never become "dqw4...".
		$first_raw = isset( $segments[0] ) ? $segments[0] : '';
		$second    = isset( $segments[1] ) ? $segments[1] : '';
		$first     = strtolower( $first_raw );

		$video_id = '';
		$list_id  = self::clean_list_id( isset( $query['list'] ) ? $query['list'] : '' );
		$type     = '';

		if ( 'youtu.be' === $host ) {
			$video_id = self::clean_video_id( $first_raw );
			$type     = 'video';
		} elseif ( 'watch' === $first ) {
			$video_id = self::clean_video_id( isset( $query['v'] ) ? $query['v'] : '' );
			$type     = 'video';
		} elseif ( 'shorts' === $first ) {
			$video_id = self::clean_video_id( $second );
			$type     = 'short';
		} elseif ( 'live' === $first ) {
			$video_id = self::clean_video_id( $second );
			$type     = 'live';
		} elseif ( 'embed' === $first || 'v' === $first ) {
			if ( 'videoseries' === strtolower( $second ) ) {
				$type = 'playlist';
			} else {
				$video_id = self::clean_video_id( $second );
				$type     = 'video';
			}
		} elseif ( 'playlist' === $first ) {
			$type = 'playlist';
		}

		if ( '' === $video_id && '' === $list_id ) {
			return new WP_Error( 'ytep_no_id', __( 'No video or playlist ID found in that link.', 'ytep' ) );
		}

		if ( '' === $type ) {
			$type = $video_id ? 'video' : 'playlist';
		}

		if ( 'playlist' === $type && '' === $list_id ) {
			return new WP_Error( 'ytep_no_list', __( 'That playlist link is missing its list ID.', 'ytep' ) );
		}

		$start = 0;
		foreach ( array( 't', 'start' ) as $key ) {
			if ( isset( $query[ $key ] ) ) {
				$start = self::parse_time( $query[ $key ] );
				if ( $start ) {
					break;
				}
			}
		}

		return self::result( $type, $video_id, $list_id, $start );
	}

	/**
	 * Return the ID only if it matches the expected shape, otherwise an empty string.
	 *
	 * @param mixed $id Candidate ID.
	 * @return string
	 */
	public static function clean_video_id( $id ) {
		$id = is_string( $id ) ? trim( $id ) : '';
		return preg_match( self::VIDEO_ID_PATTERN, $id ) ? $id : '';
	}

	/**
	 * @param mixed $id Candidate playlist ID.
	 * @return string
	 */
	public static function clean_list_id( $id ) {
		$id = is_string( $id ) ? trim( $id ) : '';
		return preg_match( self::LIST_ID_PATTERN, $id ) ? $id : '';
	}

	/**
	 * Accept "90", "1h2m3s", "2m30s" and return whole seconds.
	 *
	 * @param mixed $value Raw time value.
	 * @return int
	 */
	public static function parse_time( $value ) {
		$value = is_scalar( $value ) ? trim( (string) $value ) : '';

		if ( ctype_digit( $value ) ) {
			return (int) $value;
		}

		if ( preg_match( '/\A(?:(\d+)h)?(?:(\d+)m)?(?:(\d+)s)?\z/i', $value, $m ) ) {
			$hours   = isset( $m[1] ) && '' !== $m[1] ? (int) $m[1] : 0;
			$minutes = isset( $m[2] ) && '' !== $m[2] ? (int) $m[2] : 0;
			$seconds = isset( $m[3] ) && '' !== $m[3] ? (int) $m[3] : 0;
			return ( $hours * 3600 ) + ( $minutes * 60 ) + $seconds;
		}

		return 0;
	}

	/**
	 * @param string $type     video|short|playlist|live.
	 * @param string $video_id Validated video ID.
	 * @param string $list_id  Validated playlist ID.
	 * @param int    $start    Start offset in seconds.
	 * @return array
	 */
	protected static function result( $type, $video_id, $list_id, $start ) {
		return array(
			'type'     => $type,
			'video_id' => $video_id,
			'list_id'  => $list_id,
			'start'    => absint( $start ),
		);
	}
}
