=== Quiz Certify ===
Contributors: yourname
Tags: quiz, certificate, exam, test, assessment
Requires at least: 6.0
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 1.3.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Create multiple quizzes, store student records, and let users print a verifiable certificate when they pass.

== Description ==

Quiz Certify lets you build any number of quizzes, embed each one with a shortcode or a
Gutenberg block, grade submissions securely on the server, keep a record of everyone who
took a quiz, and award a printable certificate to anyone who reaches the passing score.

Features:

* Unlimited quizzes, each managed like a normal post.
* Single-answer and multiple-answer questions.
* Per-quiz passing score and optional student-email collection.
* Server-side grading — answers are never exposed to the browser.
* A tokenized, verifiable certificate that prints to a single page or saves as PDF.
* Student records admin page with quiz filter and CSV export.
* A listing page ([quiz_certify_list] or the "Quiz List" block) shows all quizzes; a
  visitor picks one and it loads in place without a full reload (shareable ?quiz=ID URL).
* Embed anywhere: native Gutenberg blocks, native Elementor widgets, or shortcodes
  ([quiz_certify id=".."] and [quiz_certify_list]). The front-end inherits the theme.
* Name and email are always collected and required, and validated before submitting.
* The Results admin page shows a View / Download certificate link for each pass.

== Compatibility ==

* Block editor: add the "Quiz Certify" block and pick a quiz.
* Elementor: use the native "Quiz" or "Quiz List" widget (search "quiz"), or a Shortcode widget.
* Page builders / classic editor: paste the shortcode, e.g. [quiz_certify id="12"].

== Installation ==

1. Upload the `quiz-certify` folder to `/wp-content/plugins/`, or install the .zip via
   Plugins > Add New > Upload Plugin.
2. Activate the plugin.
3. Go to "Quizzes" and create a quiz. Place a single quiz with the "Quiz Certify" block
   or [quiz_certify id="12"], or list them all with the "Quiz List" block or
   [quiz_certify_list].
4. View who took each quiz under Quizzes > Results, and export to CSV from there.

== Frequently Asked Questions ==

= Can the front-end design match my theme? =
Yes. The quiz inherits your theme's typography and colours, and buttons pick up your
theme's button styling. To supply 100% of the styling yourself, disable the plugin CSS:
`add_filter( 'quiz_certify_load_styles', '__return_false' );`

= Where are student records stored? =
In a dedicated table, `{prefix}_quiz_certify_results`, viewable under Quizzes > Results.

= Can a user fake a passing score? =
No. Grading happens entirely on the server, and certificates are served from a random
32-character token tied to a real passing record.

== Changelog ==

= 1.3.0 =
* Added native Elementor widgets ("Quiz" and "Quiz List") in addition to blocks and shortcodes.
* Email is now always collected and required on the front end; name and email are both
  validated (client and server) before a result is recorded.
* The Results admin page now has a Certificate column with a View / Download link for each
  passing record, and the CSV export includes the certificate URL.


= 1.2.0 =
* Added a quiz listing page: [quiz_certify_list] shortcode and a "Quiz List" block.
* Selecting a quiz loads it in place via AJAX (no reload) with a shareable URL and
  browser Back support; degrades to normal links without JavaScript.
* Quiz form now uses a delegated submit handler so dynamically loaded quizzes work.


= 1.1.0 =
* Added a Gutenberg block and confirmed Elementor/page-builder compatibility.
* Front-end quiz now inherits theme styles.
* Certificate now prints to a single page.
* Added student email collection and a Results admin page with CSV export.

= 1.0.0 =
* Initial release.
