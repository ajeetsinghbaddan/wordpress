<?php
/**
 * Settings screen + stats table.
 *
 * @package PuzzleGate
 */

namespace PuzzleGate;

defined( 'ABSPATH' ) || exit;

class Admin {

	const PAGE = 'puzzle-gate';

	public function hooks(): void {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_init', array( $this, 'settings' ) );
		add_action( 'admin_post_pgz_reset_stats', array( $this, 'reset_stats' ) );
	}

	public function menu(): void {
		add_options_page(
			__( 'Puzzle Gate', 'puzzle-gate' ),
			__( 'Puzzle Gate', 'puzzle-gate' ),
			'manage_options', // capability, not a role — the WordPress way
			self::PAGE,
			array( $this, 'page' )
		);
	}

	/**
	 * The Settings API does three things for free: it renders nonce fields,
	 * checks the capability on save, and runs your sanitise callback. Rolling
	 * your own $_POST handler means re-implementing all three, usually badly.
	 */
	public function settings(): void {
		register_setting(
			'puzzle_gate_group',
			Plugin::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => Plugin::defaults(),
			)
		);
	}

	/**
	 * Never trust admin input either. A compromised admin session, or a plugin
	 * conflict writing junk, should not be able to poison the option.
	 */
	public function sanitize( $input ): array {
		$input = is_array( $input ) ? $input : array();
		$out   = Plugin::defaults();

		$out['accent']         = sanitize_hex_color( $input['accent'] ?? '' ) ?: $out['accent'];
		$out['default_type']   = Puzzle_Registry::get( sanitize_key( $input['default_type'] ?? '' ) )
			? sanitize_key( $input['default_type'] )
			: 'slide';
		$out['session_minutes'] = $this->clamp( $input['session_minutes'] ?? 30, 1, 240 );
		$out['max_attempts']    = $this->clamp( $input['max_attempts'] ?? 8, 1, 50 );
		$out['remember_hours']  = $this->clamp( $input['remember_hours'] ?? 24, 1, 8760 );
		$out['rate_limit']      = $this->clamp( $input['rate_limit'] ?? 20, 3, 300 );
		$out['confetti']        = empty( $input['confetti'] ) ? 0 : 1;
		$out['editor_preview']  = empty( $input['editor_preview'] ) ? 0 : 1;
		$out['collect_stats']   = empty( $input['collect_stats'] ) ? 0 : 1;

		return $out;
	}

	private function clamp( $value, int $min, int $max ): int {
		return max( $min, min( $max, (int) $value ) );
	}

	public function reset_stats(): void {
		// Two independent checks, both required:
		//   capability  -> "is this user allowed to do it?"
		//   nonce       -> "did this user actually intend it, on our page?"
		// A nonce alone does not authorise; a capability alone does not stop CSRF.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You cannot do that.', 'puzzle-gate' ), 403 );
		}
		check_admin_referer( 'pgz_reset_stats' );

		Stats::reset();

		wp_safe_redirect( add_query_arg( 'page', self::PAGE, admin_url( 'options-general.php' ) ) );
		exit;
	}

	public function page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Puzzle Gate', 'puzzle-gate' ); ?></h1>

			<h2 class="title"><?php echo esc_html__( 'How to use it', 'puzzle-gate' ); ?></h2>
			<p><?php echo esc_html__( 'Wrap anything you want hidden. The content between the tags is never sent to the browser until the server confirms a solve.', 'puzzle-gate' ); ?></p>
			<pre style="background:#fff;border:1px solid #dcdcde;padding:12px;overflow:auto">[puzzle_gate id="offer" type="slide" size="3" title="Members only" image="https://example.com/pic.jpg"]
Your hidden content, shortcodes and blocks all work here.
[/puzzle_gate]

[puzzle_gate id="quiz" type="riddle" question="What has keys but no locks?" answer="piano|a piano" hint="You play it."]
Well done. Here is the download link.
[/puzzle_gate]

[puzzle_gate id="quick" type="sequence" difficulty="hard"]
Unlocked.
[/puzzle_gate]</pre>
			<p><em><?php echo esc_html__( 'Always give each gate a unique id.', 'puzzle-gate' ); ?></em></p>

			<form method="post" action="options.php">
				<?php settings_fields( 'puzzle_gate_group' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="pgz-accent"><?php echo esc_html__( 'Accent colour', 'puzzle-gate' ); ?></label></th>
						<td><input type="color" id="pgz-accent" name="<?php echo esc_attr( Plugin::OPTION ); ?>[accent]" value="<?php echo esc_attr( (string) Plugin::option( 'accent' ) ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="pgz-type"><?php echo esc_html__( 'Default puzzle', 'puzzle-gate' ); ?></label></th>
						<td>
							<select id="pgz-type" name="<?php echo esc_attr( Plugin::OPTION ); ?>[default_type]">
								<?php foreach ( Puzzle_Registry::choices() as $slug => $label ) : ?>
									<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( Plugin::option( 'default_type' ), $slug ); ?>>
										<?php echo esc_html( $label ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<?php
					$numbers = array(
						'session_minutes' => __( 'Puzzle stays valid for (minutes)', 'puzzle-gate' ),
						'max_attempts'    => __( 'Wrong answers allowed per puzzle', 'puzzle-gate' ),
						'remember_hours'  => __( 'Keep a solved gate open for (hours)', 'puzzle-gate' ),
						'rate_limit'      => __( 'Requests allowed per visitor per minute', 'puzzle-gate' ),
					);
					foreach ( $numbers as $key => $label ) :
						?>
						<tr>
							<th scope="row"><label for="pgz-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label></th>
							<td><input type="number" min="1" id="pgz-<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( Plugin::OPTION ); ?>[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( (string) Plugin::option( $key ) ); ?>" class="small-text"></td>
						</tr>
					<?php endforeach; ?>
					<?php
					$flags = array(
						'confetti'       => __( 'Celebrate a solve with confetti', 'puzzle-gate' ),
						'editor_preview' => __( 'Show hidden content to users who can edit the post', 'puzzle-gate' ),
						'collect_stats'  => __( 'Collect solve statistics', 'puzzle-gate' ),
					);
					foreach ( $flags as $key => $label ) :
						?>
						<tr>
							<th scope="row"><?php echo esc_html( $label ); ?></th>
							<td><input type="checkbox" value="1" name="<?php echo esc_attr( Plugin::OPTION ); ?>[<?php echo esc_attr( $key ); ?>]" <?php checked( (int) Plugin::option( $key ), 1 ); ?>></td>
						</tr>
					<?php endforeach; ?>
				</table>
				<?php submit_button(); ?>
			</form>

			<h2><?php echo esc_html__( 'Results', 'puzzle-gate' ); ?></h2>
			<?php $rows = Stats::all(); ?>
			<?php if ( empty( $rows ) ) : ?>
				<p><?php echo esc_html__( 'Nothing yet. Numbers appear once visitors start opening gates.', 'puzzle-gate' ); ?></p>
			<?php else : ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php echo esc_html__( 'Gate', 'puzzle-gate' ); ?></th>
							<th><?php echo esc_html__( 'Started', 'puzzle-gate' ); ?></th>
							<th><?php echo esc_html__( 'Solved', 'puzzle-gate' ); ?></th>
							<th><?php echo esc_html__( 'Completion', 'puzzle-gate' ); ?></th>
							<th><?php echo esc_html__( 'Average time', 'puzzle-gate' ); ?></th>
							<th><?php echo esc_html__( 'Fastest', 'puzzle-gate' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $rows as $key => $row ) : ?>
						<?php
						$starts = max( 1, (int) $row['starts'] );
						$solves = (int) $row['solves'];
						$avg    = $solves > 0 ? round( (int) $row['seconds'] / $solves ) : 0;
						list( $pid ) = array_pad( explode( ':', (string) $key, 2 ), 2, '' );
						?>
						<tr>
							<td>
								<?php echo esc_html( get_the_title( (int) $pid ) ?: __( '(deleted post)', 'puzzle-gate' ) ); ?>
								<code><?php echo esc_html( (string) $key ); ?></code>
							</td>
							<td><?php echo esc_html( (string) $row['starts'] ); ?></td>
							<td><?php echo esc_html( (string) $solves ); ?></td>
							<td><?php echo esc_html( round( $solves / $starts * 100 ) . '%' ); ?></td>
							<td><?php echo esc_html( $avg ? $avg . 's' : '—' ); ?></td>
							<td><?php echo esc_html( $row['best'] ? (int) $row['best'] . 's' : '—' ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:12px">
					<input type="hidden" name="action" value="pgz_reset_stats">
					<?php wp_nonce_field( 'pgz_reset_stats' ); ?>
					<?php submit_button( __( 'Clear results', 'puzzle-gate' ), 'secondary', 'submit', false ); ?>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}
}
