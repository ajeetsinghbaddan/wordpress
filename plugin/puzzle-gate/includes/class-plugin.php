<?php
/**
 * Central wiring: settings defaults, asset registration, component boot.
 *
 * @package PuzzleGate
 */

namespace PuzzleGate;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	/** Single option row holding every setting (fewer DB rows than one option per field). */
	const OPTION = 'puzzle_gate_settings';

	/** @var Plugin|null */
	private static $instance = null;

	/**
	 * Singleton accessor.
	 *
	 * Not because singletons are elegant, but because WordPress plugins are
	 * loaded once per request and we must guarantee hooks are registered exactly
	 * once. Registering the same hook twice would render every gate twice.
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		Puzzle_Registry::init();

		( new Shortcode() )->hooks();
		( new Block() )->hooks();
		( new REST_Controller() )->hooks();

		if ( is_admin() ) {
			( new Admin() )->hooks();
		}

		/*
		 * Registered on `init` rather than `wp_enqueue_scripts`.
		 *
		 * `wp_enqueue_scripts` only fires on the front end, but the block needs
		 * these handles to exist in the editor too, and block.json is processed
		 * during `init`. Registering here covers every context; enqueueing still
		 * happens lazily, only where a gate is actually rendered.
		 */
		add_action( 'init', array( $this, 'register_assets' ) );
	}

	/**
	 * Default settings. Every read goes through option() so a missing key in an
	 * old saved option never produces an "undefined index" warning after upgrade.
	 */
	public static function defaults(): array {
		return array(
			'accent'            => '#c9a227', // brass
			'default_type'      => 'slide',
			'session_minutes'   => 30,   // how long a fetched challenge stays valid
			'max_attempts'      => 8,    // wrong answers allowed per challenge
			'remember_hours'    => 24,   // how long a solved gate stays unlocked
			'rate_limit'        => 20,   // requests per IP per minute per endpoint
			'confetti'          => 1,
			'editor_preview'    => 1,    // users who can edit the post see content unlocked
			'collect_stats'     => 1,
		);
	}

	/**
	 * Read one setting with a safe fallback.
	 *
	 * @param string $key Setting key.
	 * @return mixed
	 */
	public static function option( string $key ) {
		static $cache = null;
		if ( null === $cache ) {
			$saved = get_option( self::OPTION, array() );
			$cache = wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
		}
		return $cache[ $key ] ?? null;
	}

	/**
	 * Register (not enqueue) the front-end assets.
	 *
	 * PERFORMANCE: registering only tells WordPress the files exist. The actual
	 * <link>/<script> tags are added by Shortcode::render() and *only* on pages
	 * that really contain a gate. A plugin that enqueues unconditionally adds two
	 * HTTP requests to every page on the site — the single most common cause of
	 * "this plugin slowed down my WordPress".
	 */
	public function register_assets(): void {
		wp_register_style(
			'puzzle-gate',
			PUZZLE_GATE_URL . 'assets/css/puzzle-gate.css',
			array(),
			PUZZLE_GATE_VERSION
		);

		wp_register_script(
			'puzzle-gate',
			PUZZLE_GATE_URL . 'assets/js/puzzle-gate.js',
			array(),
			PUZZLE_GATE_VERSION,
			array(
				// `defer` lets the browser keep parsing HTML while the file
				// downloads, then runs it before DOMContentLoaded. Better than
				// in_footer alone for interactive widgets.
				'strategy'  => 'defer',
				'in_footer' => true,
			)
		);

		/*
		 * Pass server-side values to JS safely.
		 *
		 * wp_localize_script JSON-encodes and escapes the data, which is what
		 * makes it safe. Never build a <script> tag by string-concatenating PHP
		 * variables — that is how XSS gets shipped.
		 */
		wp_localize_script(
			'puzzle-gate',
			'PuzzleGateData',
			array(
				'root'     => esc_url_raw( rest_url( REST_Controller::NAMESPACE_ ) ),
				// A REST nonce proves the request came from this logged-in user's
				// session. It is *optional* here: it goes stale inside full-page
				// caches, so the real CSRF protection is the unguessable
				// server-issued challenge token. We send it when available purely
				// so logged-in requests are attributed to the right user.
				'nonce'    => wp_installing() ? '' : wp_create_nonce( 'wp_rest' ),
				'confetti' => (int) self::option( 'confetti' ),
				'i18n'     => array(
					'loading'   => __( 'Preparing the lock…', 'puzzle-gate' ),
					'error'     => __( 'That did not go through. Try again.', 'puzzle-gate' ),
					'expired'   => __( 'This puzzle timed out. Start a fresh one.', 'puzzle-gate' ),
					'wrong'     => __( 'Not it. Keep going.', 'puzzle-gate' ),
					'locked'    => __( 'Too many tries. Wait a minute and start again.', 'puzzle-gate' ),
					'solved'    => __( 'Unlocked', 'puzzle-gate' ),
					'moves'     => __( 'moves', 'puzzle-gate' ),
					'time'      => __( 'time', 'puzzle-gate' ),
					'check'     => __( 'Check answer', 'puzzle-gate' ),
					'restart'   => __( 'Shuffle again', 'puzzle-gate' ),
					'hint'      => __( 'Hint', 'puzzle-gate' ),
					'announce'  => __( 'Puzzle solved. The hidden content is now shown below.', 'puzzle-gate' ),
					'tilemoved' => __( 'Tile moved.', 'puzzle-gate' ),
				),
			)
		);

		// Accent colour is a setting, so it has to be injected at runtime.
		// esc_attr on a CSS value blocks `}` injection into the stylesheet.
		$accent = sanitize_hex_color( (string) self::option( 'accent' ) ) ?: '#c9a227';
		wp_add_inline_style( 'puzzle-gate', ':root{--pgz-accent:' . esc_attr( $accent ) . ';}' );
	}
}
