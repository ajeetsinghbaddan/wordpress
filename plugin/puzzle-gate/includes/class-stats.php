<?php
/**
 * Per-gate counters, so you can tell whether a puzzle is fun or just annoying.
 *
 * @package PuzzleGate
 */

namespace PuzzleGate;

defined( 'ABSPATH' ) || exit;

class Stats {

	const OPTION = 'puzzle_gate_stats';

	/**
	 * Increment one counter.
	 *
	 * Stored in a single non-autoloaded option. That is deliberate:
	 *  - a custom database table needs migrations, dbDelta and an uninstall path;
	 *  - post meta would scatter numbers across the postmeta table;
	 *  - an option is one row, read only when the admin screen asks for it.
	 *
	 * The trade-off is honest: two visitors solving at the exact same
	 * millisecond can lose one increment (read-modify-write race). For "how many
	 * people opened this box" that is fine. If you ever need audit-grade numbers,
	 * swap this class for a custom table with an INSERT per event — the rest of
	 * the plugin only calls Stats::record().
	 */
	public static function record( int $post_id, string $gate_id, string $metric, int $seconds = 0 ): void {
		if ( ! Plugin::option( 'collect_stats' ) ) {
			return;
		}

		$all = get_option( self::OPTION, array() );
		$all = is_array( $all ) ? $all : array();
		$key = Session::gate_key( $post_id, $gate_id );

		$row = $all[ $key ] ?? array(
			'starts'  => 0,
			'solves'  => 0,
			'fails'   => 0,
			'seconds' => 0,
			'best'    => 0,
		);

		$row[ $metric ] = (int) ( $row[ $metric ] ?? 0 ) + 1;

		if ( 'solves' === $metric && $seconds > 0 ) {
			$row['seconds'] += $seconds;
			$row['best']     = $row['best'] > 0 ? min( $row['best'], $seconds ) : $seconds;
		}

		$all[ $key ] = $row;

		// `false` = do not autoload; this option can grow and is only needed in wp-admin.
		update_option( self::OPTION, $all, false );
	}

	public static function all(): array {
		$all = get_option( self::OPTION, array() );
		return is_array( $all ) ? $all : array();
	}

	public static function reset(): void {
		delete_option( self::OPTION );
	}
}
