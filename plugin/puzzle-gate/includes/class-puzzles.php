<?php
/**
 * Puzzle types.
 *
 * Each puzzle is split in two halves:
 *   - `public`   : what the browser is allowed to see (the scramble, the question)
 *   - `solution` : what stays on the server (the winning state, the hashed answer)
 *
 * Keeping those separate in the type system makes it structurally hard to leak
 * the answer by accident — the REST layer only ever serialises the public half.
 *
 * @package PuzzleGate
 */

namespace PuzzleGate;

defined( 'ABSPATH' ) || exit;

/**
 * Base class every puzzle extends.
 *
 * `abstract` means it can never be instantiated on its own; it exists to define
 * the contract (generate + verify) that the REST controller relies on. This is
 * the Strategy pattern: the controller does not care which puzzle it is holding.
 */
abstract class Puzzle {

	abstract public function slug(): string;
	abstract public function label(): string;

	/**
	 * Build a fresh challenge.
	 *
	 * @param array $atts Shortcode attributes (difficulty, question, …).
	 * @return array{public: array, solution: array}
	 */
	abstract public function generate( array $atts ): array;

	/**
	 * Check a submitted attempt against the stored solution.
	 *
	 * @param array $solution Server-held solution half.
	 * @param mixed $payload  Raw, untrusted user input.
	 */
	abstract public function verify( array $solution, $payload ): bool;

	/**
	 * Shortest believable human solve time, in seconds.
	 *
	 * A submission that arrives faster than this almost certainly came from a
	 * script replaying a recorded payload, so we reject it. Cheap, effective
	 * bot friction — set to 0 for puzzles where a fast solve is plausible.
	 */
	public function min_seconds(): int {
		return 0;
	}

	/** Optional hint shown after repeated failures. */
	public function hint( array $solution ): string {
		return '';
	}
}

/**
 * Registry so third parties (and you) can add puzzle types without touching core.
 */
class Puzzle_Registry {

	/** @var array<string, Puzzle> */
	private static $types = array();

	public static function init(): void {
		self::register( new Slide_Puzzle() );
		self::register( new Riddle_Puzzle() );
		self::register( new Sequence_Puzzle() );

		/**
		 * Add your own puzzle: extend \PuzzleGate\Puzzle and register it here.
		 *
		 * add_action( 'puzzle_gate_register_puzzles', function () {
		 *     \PuzzleGate\Puzzle_Registry::register( new My_Puzzle() );
		 * } );
		 */
		do_action( 'puzzle_gate_register_puzzles' );
	}

	public static function register( Puzzle $puzzle ): void {
		self::$types[ $puzzle->slug() ] = $puzzle;
	}

	public static function get( string $slug ): ?Puzzle {
		return self::$types[ $slug ] ?? null;
	}

	/** @return array<string, string> slug => label, for the settings dropdown. */
	public static function choices(): array {
		$out = array();
		foreach ( self::$types as $slug => $puzzle ) {
			$out[ $slug ] = $puzzle->label();
		}
		return $out;
	}
}

/* =========================================================================
 * 1. Sliding tile puzzle
 * ========================================================================= */

/**
 * The interesting one, security-wise.
 *
 * A sliding puzzle has no secret answer — the goal state is obvious. So instead
 * of asking "is the board solved?" (which the client could simply assert), we
 * ask the client to send **the moves it made**, and we replay them on the server
 * from the scramble we generated. Every move must be legal, and the final board
 * must be solved. You cannot fake that without actually producing a valid
 * solution to the specific board we handed you.
 */
class Slide_Puzzle extends Puzzle {

	public function slug(): string {
		return 'slide';
	}

	public function label(): string {
		return __( 'Sliding tiles', 'puzzle-gate' );
	}

	public function min_seconds(): int {
		return 3;
	}

	public function generate( array $atts ): array {
		// Clamp user input. Never trust a shortcode attribute to be sane —
		// size="999" would generate a million tiles and exhaust memory.
		$size = isset( $atts['size'] ) ? (int) $atts['size'] : 3;
		$size = max( 3, min( 5, $size ) );

		$board = self::solved( $size );

		/*
		 * WHY WE SCRAMBLE BY WALKING BACKWARDS
		 *
		 * Exactly half of all random permutations of a 15-puzzle are impossible
		 * to solve (parity). Shuffling with shuffle() therefore hands 50% of
		 * visitors an unsolvable board. Applying random *legal* moves to a solved
		 * board guarantees a solvable position, because every move is reversible.
		 */
		$blank = ( $size * $size ) - 1;
		$last  = -1;
		$steps = $size * $size * 12;

		for ( $i = 0; $i < $steps; $i++ ) {
			$options = self::neighbours( $blank, $size );
			$options = array_values( array_diff( $options, array( $last ) ) ); // no immediate undo
			$pick    = $options[ random_int( 0, count( $options ) - 1 ) ];

			$board[ $blank ] = $board[ $pick ];
			$board[ $pick ]  = 0;
			$last            = $blank;
			$blank           = $pick;
		}

		// Astronomically unlikely, but a scramble that lands back on solved would
		// be an instant unlock, so re-roll.
		if ( $board === self::solved( $size ) ) {
			return $this->generate( $atts );
		}

		$image = isset( $atts['image'] ) ? esc_url_raw( $atts['image'] ) : '';

		return array(
			'public'   => array(
				'size'  => $size,
				'board' => $board,
				'image' => $image,
			),
			'solution' => array(
				'size'  => $size,
				'board' => $board,
			),
		);
	}

	public function verify( array $solution, $payload ): bool {
		if ( ! is_array( $payload ) || ! isset( $payload['moves'] ) || ! is_array( $payload['moves'] ) ) {
			return false;
		}

		$moves = $payload['moves'];

		// Bound the work an attacker can make us do. Without a cap, a payload of
		// a million moves becomes a cheap denial-of-service.
		if ( count( $moves ) > 4000 ) {
			return false;
		}

		$size  = (int) $solution['size'];
		$board = array_map( 'intval', (array) $solution['board'] );
		$cells = $size * $size;

		foreach ( $moves as $pos ) {
			if ( ! is_numeric( $pos ) ) {
				return false;
			}
			$pos = (int) $pos;
			if ( $pos < 0 || $pos >= $cells ) {
				return false;
			}

			$blank = array_search( 0, $board, true );
			if ( ! in_array( $pos, self::neighbours( (int) $blank, $size ), true ) ) {
				return false; // illegal move — reject the whole attempt
			}

			$board[ $blank ] = $board[ $pos ];
			$board[ $pos ]   = 0;
		}

		return $board === self::solved( $size );
	}

	/** Goal state: 1,2,3 … n²-1, blank last. */
	private static function solved( int $size ): array {
		$cells = $size * $size;
		$out   = range( 1, $cells - 1 );
		$out[] = 0;
		return $out;
	}

	/** Indices orthogonally adjacent to $index on an n×n grid. */
	private static function neighbours( int $index, int $size ): array {
		$row = intdiv( $index, $size );
		$col = $index % $size;
		$out = array();

		if ( $row > 0 ) {
			$out[] = $index - $size;
		}
		if ( $row < $size - 1 ) {
			$out[] = $index + $size;
		}
		if ( $col > 0 ) {
			$out[] = $index - 1;
		}
		if ( $col < $size - 1 ) {
			$out[] = $index + 1;
		}

		return $out;
	}
}

/* =========================================================================
 * 2. Riddle / question
 * ========================================================================= */

/**
 * Author writes a question and the accepted answers. The answers are hashed
 * before being stored in the challenge transient and are never sent to the
 * browser, so there is genuinely nothing to find in DevTools.
 */
class Riddle_Puzzle extends Puzzle {

	public function slug(): string {
		return 'riddle';
	}

	public function label(): string {
		return __( 'Riddle / question', 'puzzle-gate' );
	}

	public function generate( array $atts ): array {
		$question = isset( $atts['question'] ) ? sanitize_text_field( $atts['question'] ) : __( 'What is the password?', 'puzzle-gate' );
		$answers  = isset( $atts['answer'] ) ? (string) $atts['answer'] : '';
		$hint     = isset( $atts['hint'] ) ? sanitize_text_field( $atts['hint'] ) : '';

		$hashes = array();
		foreach ( explode( '|', $answers ) as $answer ) {
			$normal = self::normalise( $answer );
			if ( '' !== $normal ) {
				$hashes[] = hash( 'sha256', $normal );
			}
		}

		return array(
			'public'   => array(
				'question' => $question,
				'hasHint'  => '' !== $hint,
			),
			'solution' => array(
				'hashes' => $hashes,
				'hint'   => $hint,
			),
		);
	}

	public function verify( array $solution, $payload ): bool {
		$given = is_array( $payload ) && isset( $payload['answer'] ) ? (string) $payload['answer'] : '';
		$given = self::normalise( $given );

		if ( '' === $given || empty( $solution['hashes'] ) ) {
			return false;
		}

		$candidate = hash( 'sha256', $given );
		$ok        = false;

		foreach ( (array) $solution['hashes'] as $expected ) {
			// hash_equals compares in constant time. A plain === leaks, through
			// timing, how many leading characters matched — enough for a patient
			// attacker to reconstruct a secret one byte at a time.
			if ( hash_equals( (string) $expected, $candidate ) ) {
				$ok = true;
			}
		}

		return $ok;
	}

	public function hint( array $solution ): string {
		return (string) ( $solution['hint'] ?? '' );
	}

	/**
	 * Make matching forgiving without making it sloppy: case, accents, padding
	 * and punctuation are ignored; word order and spelling still matter.
	 */
	private static function normalise( string $value ): string {
		$value = remove_accents( wp_strip_all_tags( $value ) );
		$value = strtolower( trim( $value ) );
		$value = preg_replace( '/[^\p{L}\p{N}\s]/u', '', $value );
		$value = preg_replace( '/\s+/u', ' ', (string) $value );
		return trim( (string) $value );
	}
}

/* =========================================================================
 * 3. Number sequence
 * ========================================================================= */

/**
 * Zero-configuration puzzle: the server invents a sequence, the visitor supplies
 * the next term. Because it is generated per challenge, there is no fixed answer
 * to share around in a comments thread.
 */
class Sequence_Puzzle extends Puzzle {

	public function slug(): string {
		return 'sequence';
	}

	public function label(): string {
		return __( 'Number sequence', 'puzzle-gate' );
	}

	public function min_seconds(): int {
		return 2;
	}

	public function generate( array $atts ): array {
		$hard = isset( $atts['difficulty'] ) && 'hard' === $atts['difficulty'];
		$kind = $hard ? random_int( 1, 3 ) : random_int( 1, 2 );
		$seq  = array();

		switch ( $kind ) {
			case 1: // arithmetic
				$start = random_int( 2, 20 );
				$step  = random_int( 3, 12 );
				for ( $i = 0; $i < 5; $i++ ) {
					$seq[] = $start + ( $step * $i );
				}
				$next = $start + ( $step * 5 );
				break;

			case 2: // geometric
				$start = random_int( 2, 6 );
				$ratio = random_int( 2, 3 );
				$value = $start;
				for ( $i = 0; $i < 5; $i++ ) {
					$seq[] = $value;
					$value *= $ratio;
				}
				$next = $value;
				break;

			default: // fibonacci-style
				$a = random_int( 1, 6 );
				$b = random_int( 2, 9 );
				for ( $i = 0; $i < 6; $i++ ) {
					$seq[] = $a;
					list( $a, $b ) = array( $b, $a + $b );
				}
				$next = $a;
				break;
		}

		return array(
			'public'   => array(
				'sequence' => $seq,
			),
			'solution' => array(
				'next' => (int) $next,
			),
		);
	}

	public function verify( array $solution, $payload ): bool {
		if ( ! is_array( $payload ) || ! isset( $payload['answer'] ) ) {
			return false;
		}
		$given = trim( (string) $payload['answer'] );

		if ( ! preg_match( '/^-?\d{1,12}$/', $given ) ) {
			return false;
		}

		return (int) $given === (int) $solution['next'];
	}

	public function hint( array $solution ): string {
		return __( 'Look at the gap between each pair of numbers.', 'puzzle-gate' );
	}
}
