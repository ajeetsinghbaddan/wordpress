<?php
/**
 * The lock plate markup, shared by the shortcode and the block.
 *
 * Extracted into its own class so there is exactly ONE place that decides what
 * goes into the HTML. If the placeholder ever leaked something it shouldn't,
 * you would want to fix it once, not in two files that drifted apart.
 *
 * @package PuzzleGate
 */

namespace PuzzleGate;

defined( 'ABSPATH' ) || exit;

class Lock_View {

	/**
	 * @param int    $post_id Post the gate lives on.
	 * @param string $gate_id Gate identifier.
	 * @param string $type    Puzzle slug.
	 * @param array  $labels  title / teaser / button strings.
	 * @param string $wrapper Extra attributes for the outer div (block supports output).
	 */
	public static function render( int $post_id, string $gate_id, string $type, array $labels, string $wrapper = '' ): string {
		$labels = wp_parse_args(
			$labels,
			array(
				'title'  => __( 'Locked', 'puzzle-gate' ),
				'teaser' => __( 'Solve the puzzle to open this section.', 'puzzle-gate' ),
				'button' => __( 'Open the lock', 'puzzle-gate' ),
			)
		);

		ob_start();
		?>
		<div class="pgz"
			data-pgz-gate="<?php echo esc_attr( $gate_id ); ?>"
			data-pgz-post="<?php echo esc_attr( (string) $post_id ); ?>"
			data-pgz-type="<?php echo esc_attr( $type ); ?>"
			<?php echo $wrapper; // phpcs:ignore WordPress.Security.EscapeOutput -- built by get_block_wrapper_attributes(), already escaped. ?>>

			<div class="pgz__plate" data-pgz-plate>
				<div class="pgz__seam" aria-hidden="true"></div>

				<div class="pgz__intro">
					<p class="pgz__eyebrow"><?php echo esc_html__( 'Locked section', 'puzzle-gate' ); ?></p>
					<h3 class="pgz__title"><?php echo esc_html( $labels['title'] ); ?></h3>
					<p class="pgz__teaser"><?php echo esc_html( $labels['teaser'] ); ?></p>
					<button type="button" class="pgz__btn" data-pgz-start>
						<?php echo esc_html( $labels['button'] ); ?>
					</button>
				</div>

				<div class="pgz__stage" data-pgz-stage hidden></div>

				<!-- aria-live="polite" announces changes without interrupting. -->
				<p class="pgz__status" data-pgz-status role="status" aria-live="polite"></p>
			</div>

			<noscript>
				<p class="pgz__noscript">
					<?php echo esc_html__( 'This section is unlocked by solving a puzzle, which needs JavaScript enabled.', 'puzzle-gate' ); ?>
				</p>
			</noscript>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/** Wrapper shown to users who can edit the post. */
	public static function preview( string $html ): string {
		return '<div class="pgz-preview"><span class="pgz-preview__tag">'
			. esc_html__( 'Puzzle Gate — visible to you because you can edit this post', 'puzzle-gate' )
			. '</span>' . $html . '</div>';
	}

	/** Wrapper for content the visitor has already unlocked. */
	public static function revealed( string $html ): string {
		return '<div class="pgz-revealed">' . $html . '</div>';
	}
}
