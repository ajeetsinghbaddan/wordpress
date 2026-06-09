=== ASB Testimonials Showcase ===
Contributors: asb
Tags: testimonials, reviews, slider, gutenberg, elementor
Requires at least: 6.0
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 1.0.6
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Create and display client testimonials in six responsive, accessible layouts via shortcode, Gutenberg block, or Elementor widget.

== Description ==

ASB Testimonials Showcase registers a "Testimonial" post type and a "Testimonial Category" taxonomy, lets you store a client name, role/company, star rating and photo for each one, and displays them in any of six layouts:

1. Classic card grid
2. Horizontal slider / carousel
3. Single-quote spotlight
4. Masonry grid
5. Minimal list with avatars
6. Bubble / chat-style cards

Display testimonials three ways, all supporting design, category and count options:

* Shortcode: `[testimonials design="slider" category="clients" count="6"]`
* Gutenberg block: "Testimonials Showcase"
* Elementor widget: "Testimonials Showcase" (only loads when Elementor is active)

== Installation ==

1. Upload the `asb-testimonials-showcase` folder to `/wp-content/plugins/`, or install the .zip via Plugins > Add New > Upload Plugin.
2. Activate the plugin through the Plugins screen.
3. Add testimonials under the new "Testimonials" menu.
4. Configure defaults under Testimonials > Settings.
5. Embed via shortcode, block or Elementor widget.

== Frequently Asked Questions ==

= Does it delete my data when I remove it? =

No. By default uninstalling only removes the plugin's settings. If you tick the
"Also delete all testimonials" option on the settings page, deleting the plugin
will also remove the testimonial content.

= Is the slider accessible? =

Yes. It is keyboard navigable, exposes ARIA roles/labels, and honours the
prefers-reduced-motion setting.

== Changelog ==

= 1.0.0 =
* Initial release.
