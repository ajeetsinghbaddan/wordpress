<?php
/**
 * Plugin Name:       Puzzle Gate
 * Plugin URI:        https://github.com/ajeetsinghbaddan/wordpress/tree/main/plugin/puzzle-gate
 * Description:       Hide any part of a page behind an interactive puzzle. The hidden content never touches the browser until the server confirms the puzzle was actually solved.
 * Version:           1.0.0
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            Ajeet Singh Baddan
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       puzzle-gate
 *
 * ---------------------------------------------------------------------------
 * WHY THIS FILE LOOKS LIKE THIS
 * ---------------------------------------------------------------------------
 * WordPress identifies a plugin by parsing the header comment above. Only the
 * *main* plugin file is scanned, and only the first 8KB of it, which is why the
 * header sits at the very top.
 *
 * The file itself does almost nothing: it defines constants, loads classes, and
 * hands control to Plugin::instance(). Keeping the entry file thin makes the
 * plugin easy to test and avoids the classic WordPress trap of a 3,000 line
 * functions-style file where nothing can be reused.
 */

// ---------------------------------------------------------------------------
// SECURITY: block direct file access.
// Every PHP file in a plugin is reachable over HTTP (wp-content is web-served).
// ABSPATH is only defined once WordPress has bootstrapped, so if it is missing
// somebody has requested this file directly and we exit immediately. This one
// line prevents a whole class of "PHP notice / path disclosure" bugs.
// ---------------------------------------------------------------------------
defined( 'ABSPATH' ) || exit;

define( 'PUZZLE_GATE_VERSION', '1.0.0' );
define( 'PUZZLE_GATE_FILE', __FILE__ );
define( 'PUZZLE_GATE_DIR', plugin_dir_path( __FILE__ ) );  // /full/server/path/
define( 'PUZZLE_GATE_URL', plugin_dir_url( __FILE__ ) );   // https://site/wp-content/...

/**
 * Simple manual "autoloader".
 *
 * A real Composer autoloader would be overkill for a dozen classes, and bundling
 * vendor/ into a plugin risks colliding with another plugin's copy of the same
 * library. Explicit requires are boring, predictable and fast (opcache keeps the
 * compiled bytecode in memory anyway).
 */
foreach (
	array(
		'includes/class-gate-locator.php',
		'includes/class-session.php',
		'includes/class-puzzles.php',
		'includes/class-stats.php',
		'includes/class-lock-view.php',
		'includes/class-shortcode.php',
		'includes/class-block.php',
		'includes/class-rest-controller.php',
		'includes/class-admin.php',
		'includes/class-plugin.php',
	) as $pgz_file
) {
	require_once PUZZLE_GATE_DIR . $pgz_file;
}

/**
 * Boot on `plugins_loaded`, not immediately.
 *
 * At the moment this file is included, most of WordPress (translations, the
 * current user, other plugins) does not exist yet. `plugins_loaded` is the
 * earliest hook where the full plugin environment is guaranteed to be ready.
 */
add_action(
	'plugins_loaded',
	static function () {
		\PuzzleGate\Plugin::instance();
	}
);

/**
 * Activation hook: store default settings once, so the rest of the code can
 * assume the option exists. `register_activation_hook` fires exactly once, when
 * the user clicks "Activate" — never on normal page loads.
 */
register_activation_hook(
	__FILE__,
	static function () {
		if ( false === get_option( \PuzzleGate\Plugin::OPTION ) ) {
			// Third argument `false` = do not autoload. Autoloaded options are
			// fetched on *every* request; settings we only need occasionally
			// should stay out of that query.
			add_option( \PuzzleGate\Plugin::OPTION, \PuzzleGate\Plugin::defaults(), '', false );
		}
	}
);
