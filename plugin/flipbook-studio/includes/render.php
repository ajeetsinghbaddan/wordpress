<?php
/**
 * Front-end rendering: the shortcode, the reader markup and the access gates.
 *
 * @package FlipbookStudio
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers the scripts and styles without loading them.
 *
 * Registering early and enqueuing later means a page with no flipbook on it
 * never downloads 1.4 MB of PDF.js. The reader only pulls its weight where it
 * is actually used.
 */
function fbs_register_assets() {
	wp_register_style( 'fbs-pageflip', FBS_URL . 'assets/vendor/pageflip/stPageFlip.css', array(), '2.0.7' );
	wp_register_style( 'fbs-reader', FBS_URL . 'assets/css/flipbook.css', array( 'fbs-pageflip' ), FBS_VERSION );

	wp_register_script( 'fbs-pdfjs', FBS_URL . 'assets/vendor/pdfjs/pdf.min.js', array(), '3.11.174', true );
	wp_register_script( 'fbs-pageflip', FBS_URL . 'assets/vendor/pageflip/page-flip.browser.js', array(), '2.0.7', true );
	wp_register_script( 'fbs-reader', FBS_URL . 'assets/js/flipbook.js', array( 'fbs-pdfjs', 'fbs-pageflip' ), FBS_VERSION, true );
}
add_action( 'wp_enqueue_scripts', 'fbs_register_assets' );

/**
 * Turns registration into an actual load. Called from the shortcode.
 */
function fbs_enqueue_reader() {
	wp_enqueue_style( 'fbs-reader' );
	wp_enqueue_script( 'fbs-reader' );
}

/**
 * The [flipbook] shortcode.
 *
 * @param array $atts Shortcode attributes.
 * @return string
 */
function fbs_shortcode( $atts ) {
	$atts = shortcode_atts(
		array(
			'id'      => 0,
			'height'  => '',
			'theme'   => '',
			'page'    => '',
			'toolbar' => 'yes',
		),
		$atts,
		'flipbook'
	);

	return fbs_render_reader( (int) $atts['id'], $atts );
}
add_shortcode( 'flipbook', 'fbs_shortcode' );

/**
 * Appends the reader to a flipbook's own page.
 *
 * The static guard stops an infinite loop if somebody pastes the shortcode for
 * a flipbook into that same flipbook's description.
 *
 * @param string $content Post content.
 * @return string
 */
function fbs_append_to_single( $content ) {
	static $rendering = false;

	if ( $rendering || ! is_singular( FBS_POST_TYPE ) || ! in_the_loop() || ! is_main_query() ) {
		return $content;
	}

	$rendering = true;
	$reader    = fbs_render_reader( get_the_ID(), array() );
	$rendering = false;

	return $content . $reader;
}
add_filter( 'the_content', 'fbs_append_to_single' );

/**
 * Builds the reader, or the appropriate gate screen.
 *
 * @param int   $book_id Flipbook post ID.
 * @param array $atts    Shortcode overrides.
 * @return string
 */
function fbs_render_reader( $book_id, $atts = array() ) {
	$book = get_post( $book_id );

	if ( ! $book || FBS_POST_TYPE !== $book->post_type ) {
		return current_user_can( 'edit_posts' )
			? fbs_notice( __( 'Flipbook not found. Check the id in the shortcode.', 'flipbook-studio' ) )
			: '';
	}

	$status = fbs_access_status( $book_id );

	switch ( $status ) {
		case 'not_found':
			return current_user_can( 'edit_posts' ) ? fbs_notice( __( 'This flipbook is not published yet.', 'flipbook-studio' ) ) : '';

		case 'no_file':
			return current_user_can( 'edit_post', $book_id )
				? fbs_notice( __( 'No PDF has been uploaded to this flipbook yet.', 'flipbook-studio' ) )
				: '';

		case 'expired':
			return fbs_notice( __( 'This flipbook is no longer available.', 'flipbook-studio' ) );

		case 'blocked':
			return fbs_notice( __( 'This flipbook cannot be shown on this site.', 'flipbook-studio' ) );

		case 'login_required':
			return fbs_notice(
				sprintf(
					/* translators: %s: sign-in link. */
					__( 'Sign in to read this flipbook. %s', 'flipbook-studio' ),
					'<a href="' . esc_url( wp_login_url( get_permalink( $book_id ) ) ) . '">' . esc_html__( 'Sign in', 'flipbook-studio' ) . '</a>'
				)
			);
	}

	fbs_enqueue_reader();

	$locked = ( 'password_required' === $status );
	$theme  = ! empty( $atts['theme'] ) ? sanitize_key( $atts['theme'] ) : fbs_get_meta( $book_id, '_fbs_theme' );
	$theme  = in_array( $theme, array( 'ink', 'paper', 'slate' ), true ) ? $theme : 'ink';
	$height = ! empty( $atts['height'] ) ? (int) $atts['height'] : (int) fbs_get_meta( $book_id, '_fbs_height' );
	$height = max( 320, min( 1600, $height ) );
	$start  = ! empty( $atts['page'] ) ? (int) $atts['page'] : (int) fbs_get_meta( $book_id, '_fbs_start_page' );
	$bar    = ! isset( $atts['toolbar'] ) || 'no' !== strtolower( (string) $atts['toolbar'] );

	$uid = 'fbs-' . $book_id . '-' . wp_generate_password( 6, false, false );

	$config = array(
		'id'            => $book_id,
		'uid'           => $uid,
		'file'          => $locked ? '' : fbs_file_url( $book_id ),
		'worker'        => FBS_URL . 'assets/vendor/pdfjs/pdf.worker.min.js',
		'cmaps'         => FBS_URL . 'assets/vendor/pdfjs/cmaps/',
		'restToken'     => rest_url( 'flipbook/v1/token' ),
		'restUnlock'    => rest_url( 'flipbook/v1/unlock' ),
		'restView'      => rest_url( 'flipbook/v1/view' ),
		'nonce'         => wp_create_nonce( 'wp_rest' ),
		'locked'        => $locked,
		'startPage'     => max( 1, $start ),
		'tokenTtl'      => (int) fbs_setting( 'token_ttl', 900 ),
		'analytics'     => (bool) fbs_setting( 'analytics', 1 ),
		'allowDownload' => (bool) fbs_get_meta( $book_id, '_fbs_allow_download' ),
		'allowPrint'    => (bool) fbs_get_meta( $book_id, '_fbs_allow_print' ),
		'sound'         => (bool) fbs_get_meta( $book_id, '_fbs_sound' ),
		'singlePage'    => (bool) fbs_get_meta( $book_id, '_fbs_single_page' ),
		'previewPages'  => (int) fbs_get_meta( $book_id, '_fbs_preview_pages' ),
		'watermark'     => fbs_watermark_text( $book_id ),
		'title'         => get_the_title( $book_id ),
		'shareUrl'      => get_permalink( $book_id ),
		'i18n'          => fbs_reader_strings(),
	);

	ob_start();
	?>
	<div id="<?php echo esc_attr( $uid ); ?>"
		class="fbs-reader fbs-theme-<?php echo esc_attr( $theme ); ?><?php echo $bar ? '' : ' fbs-no-toolbar'; ?>"
		style="--fbs-height: <?php echo (int) $height; ?>px"
		data-fbs="<?php echo esc_attr( wp_json_encode( $config ) ); ?>">

		<div class="fbs-stage">
			<div class="fbs-status" data-fbs-status>
				<div class="fbs-progress"><span data-fbs-bar></span></div>
				<p data-fbs-status-text><?php esc_html_e( 'Opening the book', 'flipbook-studio' ); ?></p>
			</div>

			<div class="fbs-book" data-fbs-book aria-live="polite"></div>

			<?php if ( $config['watermark'] ) : ?>
				<div class="fbs-watermark" aria-hidden="true"><span><?php echo esc_html( $config['watermark'] ); ?></span></div>
			<?php endif; ?>

			<button class="fbs-edge fbs-edge-prev" data-fbs-prev type="button" aria-label="<?php esc_attr_e( 'Previous page', 'flipbook-studio' ); ?>"></button>
			<button class="fbs-edge fbs-edge-next" data-fbs-next type="button" aria-label="<?php esc_attr_e( 'Next page', 'flipbook-studio' ); ?>"></button>

			<?php if ( $locked ) : ?>
				<div class="fbs-gate" data-fbs-gate>
					<div class="fbs-gate-inner">
						<h3><?php esc_html_e( 'This flipbook is password protected', 'flipbook-studio' ); ?></h3>
						<p><?php esc_html_e( 'Enter the password you were given to start reading.', 'flipbook-studio' ); ?></p>
						<div class="fbs-gate-row">
							<label class="screen-reader-text" for="<?php echo esc_attr( $uid ); ?>-pw"><?php esc_html_e( 'Password', 'flipbook-studio' ); ?></label>
							<input type="password" id="<?php echo esc_attr( $uid ); ?>-pw" data-fbs-password autocomplete="off">
							<button type="button" class="fbs-btn fbs-btn-primary" data-fbs-unlock><?php esc_html_e( 'Open', 'flipbook-studio' ); ?></button>
						</div>
						<p class="fbs-gate-error" data-fbs-gate-error role="alert"></p>
					</div>
				</div>
			<?php endif; ?>

			<div class="fbs-gate fbs-gate-preview" data-fbs-preview-gate hidden>
				<div class="fbs-gate-inner">
					<h3><?php esc_html_e( 'That is the end of the preview', 'flipbook-studio' ); ?></h3>
					<p><?php echo wp_kses_post( apply_filters( 'fbs_preview_message', __( 'Get in touch to read the rest of this book.', 'flipbook-studio' ), $book_id ) ); ?></p>
					<button type="button" class="fbs-btn" data-fbs-preview-back><?php esc_html_e( 'Back to the preview', 'flipbook-studio' ); ?></button>
				</div>
			</div>

			<?php fbs_render_panels( $uid ); ?>
		</div>

		<?php if ( $bar ) : ?>
			<?php fbs_render_toolbar( $book_id, $config ); ?>
		<?php endif; ?>
	</div>
	<?php
	return ob_get_clean();
}

/**
 * The side panels: thumbnails, contents and search.
 *
 * @param string $uid Unique instance id.
 */
function fbs_render_panels( $uid ) {
	?>
	<aside class="fbs-panel" data-fbs-panel="thumbs" hidden>
		<header><h4><?php esc_html_e( 'Pages', 'flipbook-studio' ); ?></h4>
			<button type="button" class="fbs-panel-close" data-fbs-panel-close aria-label="<?php esc_attr_e( 'Close panel', 'flipbook-studio' ); ?>">&times;</button>
		</header>
		<div class="fbs-thumbs" data-fbs-thumbs></div>
	</aside>

	<aside class="fbs-panel" data-fbs-panel="outline" hidden>
		<header><h4><?php esc_html_e( 'Contents', 'flipbook-studio' ); ?></h4>
			<button type="button" class="fbs-panel-close" data-fbs-panel-close aria-label="<?php esc_attr_e( 'Close panel', 'flipbook-studio' ); ?>">&times;</button>
		</header>
		<div class="fbs-outline" data-fbs-outline></div>
	</aside>

	<aside class="fbs-panel" data-fbs-panel="search" hidden>
		<header><h4><?php esc_html_e( 'Search', 'flipbook-studio' ); ?></h4>
			<button type="button" class="fbs-panel-close" data-fbs-panel-close aria-label="<?php esc_attr_e( 'Close panel', 'flipbook-studio' ); ?>">&times;</button>
		</header>
		<div class="fbs-search">
			<label class="screen-reader-text" for="<?php echo esc_attr( $uid ); ?>-q"><?php esc_html_e( 'Search text', 'flipbook-studio' ); ?></label>
			<input type="search" id="<?php echo esc_attr( $uid ); ?>-q" data-fbs-query
				placeholder="<?php esc_attr_e( 'Find in this book', 'flipbook-studio' ); ?>">
			<div class="fbs-results" data-fbs-results></div>
		</div>
	</aside>
	<?php
}

/**
 * The toolbar.
 *
 * @param int   $book_id Flipbook post ID.
 * @param array $config  Reader config.
 */
function fbs_render_toolbar( $book_id, $config ) {
	$icons = fbs_icons();
	?>
	<div class="fbs-toolbar" role="toolbar" aria-label="<?php esc_attr_e( 'Reader controls', 'flipbook-studio' ); ?>">

		<div class="fbs-tools fbs-tools-left">
			<button type="button" class="fbs-tool" data-fbs-toggle="thumbs" aria-pressed="false"
				title="<?php esc_attr_e( 'Pages', 'flipbook-studio' ); ?>"><?php echo $icons['grid']; // phpcs:ignore ?></button>
			<button type="button" class="fbs-tool" data-fbs-toggle="outline" aria-pressed="false"
				title="<?php esc_attr_e( 'Contents', 'flipbook-studio' ); ?>"><?php echo $icons['list']; // phpcs:ignore ?></button>
			<button type="button" class="fbs-tool" data-fbs-toggle="search" aria-pressed="false"
				title="<?php esc_attr_e( 'Search', 'flipbook-studio' ); ?>"><?php echo $icons['search']; // phpcs:ignore ?></button>
		</div>

		<div class="fbs-spine">
			<button type="button" class="fbs-tool" data-fbs-prev title="<?php esc_attr_e( 'Previous page', 'flipbook-studio' ); ?>"><?php echo $icons['prev']; // phpcs:ignore ?></button>
			<label class="screen-reader-text" for="<?php echo esc_attr( $config['uid'] ); ?>-page"><?php esc_html_e( 'Page number', 'flipbook-studio' ); ?></label>
			<input type="text" inputmode="numeric" class="fbs-page-input" id="<?php echo esc_attr( $config['uid'] ); ?>-page" data-fbs-page value="1" size="3">
			<span class="fbs-rule" aria-hidden="true"></span>
			<span class="fbs-total" data-fbs-total>—</span>
			<button type="button" class="fbs-tool" data-fbs-next title="<?php esc_attr_e( 'Next page', 'flipbook-studio' ); ?>"><?php echo $icons['next']; // phpcs:ignore ?></button>
		</div>

		<div class="fbs-tools fbs-tools-right">
			<button type="button" class="fbs-tool" data-fbs-zoom="out" title="<?php esc_attr_e( 'Zoom out', 'flipbook-studio' ); ?>"><?php echo $icons['minus']; // phpcs:ignore ?></button>
			<button type="button" class="fbs-tool" data-fbs-zoom="in" title="<?php esc_attr_e( 'Zoom in', 'flipbook-studio' ); ?>"><?php echo $icons['plus']; // phpcs:ignore ?></button>

			<?php if ( $config['sound'] ) : ?>
				<button type="button" class="fbs-tool" data-fbs-sound aria-pressed="true" title="<?php esc_attr_e( 'Page sound', 'flipbook-studio' ); ?>"><?php echo $icons['sound']; // phpcs:ignore ?></button>
			<?php endif; ?>

			<?php if ( $config['allowPrint'] ) : ?>
				<button type="button" class="fbs-tool" data-fbs-print title="<?php esc_attr_e( 'Print', 'flipbook-studio' ); ?>"><?php echo $icons['print']; // phpcs:ignore ?></button>
			<?php endif; ?>

			<?php if ( $config['allowDownload'] ) : ?>
				<button type="button" class="fbs-tool" data-fbs-download title="<?php esc_attr_e( 'Download PDF', 'flipbook-studio' ); ?>"><?php echo $icons['download']; // phpcs:ignore ?></button>
			<?php endif; ?>

			<button type="button" class="fbs-tool" data-fbs-share title="<?php esc_attr_e( 'Copy link to this page', 'flipbook-studio' ); ?>"><?php echo $icons['link']; // phpcs:ignore ?></button>
			<button type="button" class="fbs-tool" data-fbs-fullscreen title="<?php esc_attr_e( 'Fullscreen', 'flipbook-studio' ); ?>"><?php echo $icons['expand']; // phpcs:ignore ?></button>
		</div>
	</div>
	<p class="fbs-hint" data-fbs-hint aria-live="polite"></p>
	<?php
}

/**
 * Inline SVG icons.
 *
 * Inline rather than an icon font or sprite file: one less request, they
 * inherit the current text colour for free, and no third-party font loads.
 *
 * @return array
 */
function fbs_icons() {
	$open  = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">';
	$close = '</svg>';

	return array(
		'grid'     => $open . '<rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/>' . $close,
		'list'     => $open . '<line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><circle cx="4" cy="6" r="1"/><circle cx="4" cy="12" r="1"/><circle cx="4" cy="18" r="1"/>' . $close,
		'search'   => $open . '<circle cx="11" cy="11" r="7"/><line x1="16.5" y1="16.5" x2="21" y2="21"/>' . $close,
		'prev'     => $open . '<polyline points="15 5 8 12 15 19"/>' . $close,
		'next'     => $open . '<polyline points="9 5 16 12 9 19"/>' . $close,
		'plus'     => $open . '<circle cx="11" cy="11" r="7"/><line x1="11" y1="8" x2="11" y2="14"/><line x1="8" y1="11" x2="14" y2="11"/><line x1="16.5" y1="16.5" x2="21" y2="21"/>' . $close,
		'minus'    => $open . '<circle cx="11" cy="11" r="7"/><line x1="8" y1="11" x2="14" y2="11"/><line x1="16.5" y1="16.5" x2="21" y2="21"/>' . $close,
		'sound'    => $open . '<polygon points="4 9 8 9 13 5 13 19 8 15 4 15"/><path d="M17 9a4 4 0 0 1 0 6"/>' . $close,
		'print'    => $open . '<polyline points="7 8 7 3 17 3 17 8"/><rect x="4" y="8" width="16" height="8" rx="1"/><rect x="7" y="16" width="10" height="5"/>' . $close,
		'download' => $open . '<line x1="12" y1="4" x2="12" y2="15"/><polyline points="8 11 12 15 16 11"/><path d="M4 17v2a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-2"/>' . $close,
		'link'     => $open . '<path d="M10 13a5 5 0 0 0 7 0l3-3a5 5 0 0 0-7-7l-2 2"/><path d="M14 11a5 5 0 0 0-7 0l-3 3a5 5 0 0 0 7 7l2-2"/>' . $close,
		'expand'   => $open . '<polyline points="4 9 4 4 9 4"/><polyline points="20 9 20 4 15 4"/><polyline points="4 15 4 20 9 20"/><polyline points="20 15 20 20 15 20"/>' . $close,
	);
}

/**
 * Builds the per-reader watermark string.
 *
 * Filling in the reader's own name and the date turns a decorative overlay into
 * a mild accountability nudge: a leaked screenshot carries its source.
 *
 * @param int $book_id Flipbook post ID.
 * @return string
 */
function fbs_watermark_text( $book_id ) {
	$template = (string) fbs_get_meta( $book_id, '_fbs_watermark' );

	if ( '' === $template ) {
		return '';
	}

	$user  = wp_get_current_user();
	$name  = $user && $user->ID ? $user->display_name : __( 'Guest', 'flipbook-studio' );
	$email = $user && $user->ID ? $user->user_email : '';

	return strtr(
		$template,
		array(
			'{user}'  => $name,
			'{email}' => $email,
			'{date}'  => date_i18n( get_option( 'date_format' ) ),
		)
	);
}

/**
 * Strings handed to the reader script.
 *
 * Passing them as data keeps every user-facing word inside WordPress's
 * translation system instead of hard-coded in JavaScript.
 *
 * @return array
 */
function fbs_reader_strings() {
	return array(
		'loading'    => __( 'Opening the book', 'flipbook-studio' ),
		'rendering'  => __( 'Preparing pages', 'flipbook-studio' ),
		'failed'     => __( 'This flipbook could not be opened. Reload the page to try again.', 'flipbook-studio' ),
		'expired'    => __( 'Your reading link expired. Reload the page.', 'flipbook-studio' ),
		'noResults'  => __( 'Nothing found.', 'flipbook-studio' ),
		'searching'  => __( 'Reading the text', 'flipbook-studio' ),
		'copied'     => __( 'Link copied', 'flipbook-studio' ),
		'page'       => __( 'Page', 'flipbook-studio' ),
		'ofPages'    => __( 'of', 'flipbook-studio' ),
		'wrongPass'  => __( 'That password is not right.', 'flipbook-studio' ),
		'shortcuts'  => __( 'Arrow keys turn pages. F for fullscreen, + and − to zoom.', 'flipbook-studio' ),
	);
}

/**
 * Small inline notice used for gates and editor-only messages.
 *
 * @param string $message Message HTML.
 * @return string
 */
function fbs_notice( $message ) {
	return '<div class="fbs-notice">' . wp_kses_post( $message ) . '</div>';
}
