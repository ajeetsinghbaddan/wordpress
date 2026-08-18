<?php
/**
 * Per-IP submission throttling.
 *
 * @package GuestPostSubmissions
 */

defined( 'ABSPATH' ) || exit;

/**
 * Simple sliding-window rate limiter built on transients.
 *
 * WHY TRANSIENTS AND NOT A CUSTOM TABLE?
 *
 * A transient is a key/value pair with a built-in expiry. WordPress stores it
 * in the object cache if one exists (Redis, Memcached) and falls back to the
 * options table otherwise. That gives us three things for free:
 *
 *   1. Automatic expiry -- no cron job to prune old rows.
 *   2. Zero schema, zero migrations, zero dbDelta.
 *   3. On sites with Redis it never touches MySQL at all.
 *
 * A custom table would be the right call only if we needed to query the
 * history (e.g. "show me all blocked IPs"). We don't.
 */
class GPS_Rate_Limiter {

	const PREFIX = 'gps_rl_';

	/**
	 * Check whether this visitor is over the limit.
	 *
	 * @return bool True when the request should be rejected.
	 */
	public static function is_limited() {
		$max = (int) GPS_Settings::get( 'throttle_max' );

		if ( $max < 1 ) {
			return false;
		}

		return self::current_count() >= $max;
	}

	/**
	 * Record one submission against this visitor.
	 */
	public static function record() {
		$key    = self::key();
		$window = (int) GPS_Settings::get( 'throttle_window' );
		$count  = self::current_count();

		/*
		 * Note this is a fixed window, not a true sliding window: the expiry is
		 * reset on the first hit only. That is intentional -- a true sliding
		 * window needs a stored list of timestamps, which is more read/write
		 * traffic than this problem deserves. Fixed windows can be gamed at the
		 * boundary (2x the limit across two adjacent windows), which for guest
		 * blog posts is a non-issue.
		 */
		set_transient( $key, $count + 1, $window );
	}

	/**
	 * How many submissions this visitor has made in the window.
	 *
	 * @return int
	 */
	private static function current_count() {
		$stored = get_transient( self::key() );

		return false === $stored ? 0 : (int) $stored;
	}

	/**
	 * Build the transient key.
	 *
	 * The IP is hashed, never stored raw. Two reasons:
	 *
	 *   1. PRIVACY. Under GDPR an IP address is personal data. A hash lets us
	 *      recognise a repeat visitor without retaining an identifier.
	 *   2. SAFETY. Transient keys are limited in length and end up in a DB
	 *      column; hashing produces a fixed-length, alphanumeric-safe string
	 *      with no chance of injection through a malformed header.
	 *
	 * wp_hash() salts with the site's own secret keys, so the hashes are not
	 * reversible via a rainbow table of the ~4 billion IPv4 addresses.
	 *
	 * @return string
	 */
	private static function key() {
		return self::PREFIX . wp_hash( self::get_ip() );
	}

	/**
	 * Get the visitor's IP address.
	 *
	 * SECURITY NOTE -- READ BEFORE CHANGING THIS.
	 *
	 * We use REMOTE_ADDR only. It is set by the web server from the actual TCP
	 * connection and cannot be forged by the client.
	 *
	 * Headers like X-Forwarded-For and CF-Connecting-IP CAN be forged: they are
	 * just request headers, so an attacker sends a different one on every
	 * request and defeats the rate limiter entirely. They are only trustworthy
	 * if your server sits behind a proxy that you control AND that overwrites
	 * them.
	 *
	 * If you are behind Cloudflare or a load balancer, add this to your theme
	 * or an mu-plugin -- consciously, not by default:
	 *
	 *     add_filter( 'gps_visitor_ip', function ( $ip ) {
	 *         if ( ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
	 *             return $_SERVER['HTTP_CF_CONNECTING_IP'];
	 *         }
	 *         return $ip;
	 *     } );
	 *
	 * @return string
	 */
	public static function get_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? wp_unslash( $_SERVER['REMOTE_ADDR'] ) : '';

		// filter_var with FILTER_VALIDATE_IP rejects anything that is not a
		// syntactically valid IPv4/IPv6 address.
		$ip = filter_var( $ip, FILTER_VALIDATE_IP );

		$ip = $ip ? $ip : '0.0.0.0';

		/**
		 * Filter the detected visitor IP.
		 *
		 * @param string $ip Validated IP address.
		 */
		$filtered = apply_filters( 'gps_visitor_ip', $ip );

		// Re-validate after the filter: never trust what a third party returns.
		$filtered = filter_var( $filtered, FILTER_VALIDATE_IP );

		return $filtered ? $filtered : $ip;
	}
}
