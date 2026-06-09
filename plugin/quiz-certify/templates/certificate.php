<?php
/**
 * Standalone certificate page.
 *
 * Available variables (set by the certificate class):
 *   string $cert_name  Recipient name
 *   string $cert_quiz  Quiz title
 *   string $cert_sub   Optional subtitle
 *   float  $cert_pct   Score percentage
 *   string $cert_date  MySQL datetime of completion
 *   int    $cert_id    Result row ID (shown as a verification number)
 *   string $cert_tok   The certificate token
 *
 * This renders as a full HTML document on its own URL, deliberately outside the theme,
 * so it prints cleanly. Every dynamic value is escaped on output.
 *
 * @package QuizCertify
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Format the completion date using the site's configured date format and locale.
$date_display = date_i18n( get_option( 'date_format' ), strtotime( $cert_date ) );

// A short, human-readable verification code: zero-padded row ID + last 6 of the token.
$verify_code = sprintf( 'QC-%05d-%s', (int) $cert_id, strtoupper( substr( $cert_tok, -6 ) ) );
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php echo esc_attr( get_bloginfo( 'charset' ) ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="robots" content="noindex, nofollow">
	<title><?php echo esc_html__( 'Certificate', 'quiz-certify' ); ?> &middot; <?php echo esc_html( $cert_name ); ?></title>
	<style>
		/* ---- Design tokens ----
		   Palette: deep pine ink, warm paper, muted brass accent. Chosen to read as an
		   earned, archival document rather than a glossy gold-foil template. */
		:root {
			--qc-ink:    #163b41;
			--qc-paper:  #faf7f0;
			--qc-brass:  #b08a4a;
			--qc-text:   #2a2f30;
			--qc-faint:  #d8cfbb;
		}

		* { box-sizing: border-box; }

		html, body {
			margin: 0;
			padding: 0;
			background: #e9e4d8;
			color: var(--qc-text);
			font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
			-webkit-print-color-adjust: exact;
			print-color-adjust: exact;
		}

		.qc-toolbar {
			text-align: center;
			padding: 22px 16px;
		}
		.qc-print-btn,
		.qc-back-link {
			font: inherit;
			font-size: 15px;
			border: 1px solid var(--qc-ink);
			background: var(--qc-ink);
			color: #fff;
			padding: 11px 22px;
			border-radius: 4px;
			cursor: pointer;
			text-decoration: none;
			display: inline-block;
		}
		.qc-back-link {
			background: transparent;
			color: var(--qc-ink);
			margin-left: 8px;
		}
		.qc-print-btn:hover { background: #0f2a2f; }
		.qc-print-btn:focus-visible,
		.qc-back-link:focus-visible { outline: 3px solid var(--qc-brass); outline-offset: 2px; }

		/* ---- The certificate sheet ---- */
		.qc-cert {
			width: 1000px;
			max-width: 94vw;
			margin: 0 auto 48px;
			aspect-ratio: 1.414 / 1; /* landscape A4 proportions */
			background: var(--qc-paper);
			position: relative;
			padding: 58px;
			box-shadow: 0 14px 40px rgba(0,0,0,.18);
			/* Double frame: outer brass hairline, inner ink rule. */
			border: 2px solid var(--qc-brass);
			outline: 1px solid var(--qc-ink);
			outline-offset: -10px;
		}

		.qc-cert-inner {
			height: 100%;
			border: 1px solid var(--qc-faint);
			padding: 38px 54px;
			display: flex;
			flex-direction: column;
			text-align: center;
		}

		.qc-eyebrow {
			font-size: 13px;
			letter-spacing: .42em;
			text-transform: uppercase;
			color: var(--qc-brass);
			font-weight: 700;
			margin: 4px 0 0;
		}

		.qc-heading {
			font-family: Georgia, "Times New Roman", serif;
			font-size: 40px;
			font-weight: 400;
			letter-spacing: .02em;
			color: var(--qc-ink);
			margin: 8px 0 2px;
		}

		.qc-rule {
			width: 64px;
			height: 2px;
			background: var(--qc-brass);
			border: 0;
			margin: 18px auto 26px;
		}

		.qc-presented {
			font-size: 14px;
			letter-spacing: .14em;
			text-transform: uppercase;
			color: #6b7174;
			margin: 0;
		}

		.qc-name {
			font-family: Georgia, "Times New Roman", serif;
			font-size: 52px;
			font-style: italic;
			color: var(--qc-ink);
			margin: 14px 0 6px;
			line-height: 1.1;
			word-break: break-word;
		}
		.qc-name-underline {
			width: 320px;
			max-width: 70%;
			height: 1px;
			background: var(--qc-faint);
			margin: 0 auto 26px;
		}

		.qc-body-text {
			font-size: 17px;
			line-height: 1.6;
			color: var(--qc-text);
			max-width: 560px;
			margin: 0 auto;
		}
		.qc-quiz-name { font-weight: 700; color: var(--qc-ink); }

		.qc-spacer { flex: 1 1 auto; }

		/* Footer: date on the left, seal centered, verification on the right. */
		.qc-footer {
			display: flex;
			align-items: flex-end;
			justify-content: space-between;
			gap: 20px;
			margin-top: 24px;
		}
		.qc-footer-block { flex: 1; min-width: 0; }
		.qc-footer-label {
			font-size: 11px;
			letter-spacing: .12em;
			text-transform: uppercase;
			color: #8a8f90;
			margin: 0 0 4px;
		}
		.qc-footer-value {
			font-size: 15px;
			color: var(--qc-ink);
			border-top: 1px solid var(--qc-ink);
			padding-top: 6px;
			font-weight: 600;
			word-break: break-word;
		}

		/* ---- Signature element: the score seal ----
		   A brass ring around the actual percentage. The radial stripes give a subtle
		   guilloché feel without any image asset, and it ties the document to the real
		   achievement it certifies. */
		.qc-seal {
			flex: 0 0 auto;
			width: 124px;
			height: 124px;
			border-radius: 50%;
			position: relative;
			display: flex;
			align-items: center;
			justify-content: center;
			background:
				repeating-conic-gradient(var(--qc-brass) 0deg 6deg, transparent 6deg 12deg);
			padding: 8px;
		}
		.qc-seal::before {
			content: "";
			position: absolute;
			inset: 6px;
			border-radius: 50%;
			background: var(--qc-paper);
			border: 2px solid var(--qc-brass);
		}
		.qc-seal-core {
			position: relative;
			z-index: 1;
			text-align: center;
			color: var(--qc-ink);
		}
		.qc-seal-pct {
			font-family: Georgia, serif;
			font-size: 30px;
			line-height: 1;
			font-weight: 700;
		}
		.qc-seal-label {
			font-size: 9px;
			letter-spacing: .18em;
			text-transform: uppercase;
			color: var(--qc-brass);
			margin-top: 3px;
		}

		/* ---- Print rules: force the certificate onto exactly ONE landscape page ----
		   The two-page overflow on screen-derived sizing comes from aspect-ratio pushing
		   the printed height just past one page. In print we drop aspect-ratio and pin a
		   fixed height in millimetres that fits inside A4 landscape (210mm tall minus the
		   6mm page margins = ~198mm printable; 190mm leaves slack for printer rounding).
		   overflow:hidden and break-inside:avoid stop any stray pixel spilling to page 2. */
		@page { size: A4 landscape; margin: 6mm; }
		@media print {
			html, body { background: #fff; margin: 0; padding: 0; height: auto; }
			.qc-toolbar { display: none !important; }
			.qc-cert {
				box-shadow: none;
				margin: 0 auto;
				width: 100%;
				max-width: 100%;
				height: 190mm;
				aspect-ratio: auto;
				padding: 11mm;
				overflow: hidden;
				page-break-inside: avoid;
				break-inside: avoid;
				page-break-after: avoid;
			}
			.qc-cert-inner {
				height: 100%;
				padding: 7mm 14mm;
			}
		}

		@media (max-width: 720px) {
			.qc-cert { padding: 26px; aspect-ratio: auto; }
			.qc-cert-inner { padding: 22px; }
			.qc-heading { font-size: 28px; }
			.qc-name { font-size: 36px; }
			.qc-footer { flex-direction: column; align-items: center; text-align: center; }
			.qc-footer-value { border-top: none; }
		}
	</style>
</head>
<body>

	<div class="qc-toolbar">
		<button type="button" class="qc-print-btn" onclick="window.print();">
			<?php esc_html_e( 'Print / Save as PDF', 'quiz-certify' ); ?>
		</button>
		<a class="qc-back-link" href="<?php echo esc_url( home_url( '/' ) ); ?>">
			<?php esc_html_e( 'Back to site', 'quiz-certify' ); ?>
		</a>
	</div>

	<section class="qc-cert" role="img"
		aria-label="<?php echo esc_attr( sprintf( __( 'Certificate for %s', 'quiz-certify' ), $cert_name ) ); ?>">
		<div class="qc-cert-inner">
			<p class="qc-eyebrow"><?php esc_html_e( 'Certificate of Achievement', 'quiz-certify' ); ?></p>
			<h1 class="qc-heading"><?php echo esc_html( get_bloginfo( 'name' ) ); ?></h1>
			<hr class="qc-rule">

			<p class="qc-presented"><?php esc_html_e( 'This certifies that', 'quiz-certify' ); ?></p>
			<p class="qc-name"><?php echo esc_html( $cert_name ); ?></p>
			<div class="qc-name-underline"></div>

			<p class="qc-body-text">
				<?php
				if ( ! empty( $cert_sub ) ) {
					echo esc_html( $cert_sub );
				} else {
					printf(
						/* translators: %s: quiz title */
						esc_html__( 'has successfully completed %s', 'quiz-certify' ),
						'<span class="qc-quiz-name">' . esc_html( $cert_quiz ) . '</span>'
					);
				}
				?>
			</p>

			<div class="qc-spacer"></div>

			<div class="qc-footer">
				<div class="qc-footer-block">
					<p class="qc-footer-label"><?php esc_html_e( 'Date', 'quiz-certify' ); ?></p>
					<p class="qc-footer-value"><?php echo esc_html( $date_display ); ?></p>
				</div>

				<div class="qc-seal" aria-hidden="true">
					<div class="qc-seal-core">
						<div class="qc-seal-pct"><?php echo esc_html( (int) round( $cert_pct ) ); ?>%</div>
						<div class="qc-seal-label"><?php esc_html_e( 'Score', 'quiz-certify' ); ?></div>
					</div>
				</div>

				<div class="qc-footer-block">
					<p class="qc-footer-label"><?php esc_html_e( 'Verification', 'quiz-certify' ); ?></p>
					<p class="qc-footer-value"><?php echo esc_html( $verify_code ); ?></p>
				</div>
			</div>
		</div>
	</section>

</body>
</html>
