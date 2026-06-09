=== Versatile Gallery ===
Contributors: yourname
Tags: gallery, images, lightbox, gutenberg, elementor
Requires at least: 6.0
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A secure, lightweight image gallery available as a Gutenberg block, an Elementor widget, and a shortcode.

== Description ==

Versatile Gallery renders a responsive image grid with an optional lightbox.
The exact same output is produced from three entry points so it behaves
consistently no matter how you build pages:

* Gutenberg block ("Versatile Gallery", in the Media category)
* Elementor widget ("Versatile Gallery", in the General category)
* Shortcode (works in the Classic editor, widgets, and theme templates)

All input is sanitized and all output is escaped.

== Installation ==

1. Copy the `versatile-gallery` folder into `wp-content/plugins/`.
2. Activate "Versatile Gallery" from the Plugins screen.
3. Add the block, the Elementor widget, or the shortcode to any page.

== Usage ==

Shortcode example:

`[versatile_gallery ids="12,15,18" layout="masonry" columns="3" gap="12" size="medium" lightbox="true"]`

Attributes:

* `ids`      — comma-separated media library attachment IDs (required).
* `layout`   — grid, masonry, justified, mosaic, carousel, or captions (default grid).
* `columns`  — number of columns, 1–6 (default 3).
* `gap`      — gap between images in pixels, 0–80 (default 12).
* `size`     — registered image size: thumbnail, medium, large, full (default medium).
* `lightbox` — true/false (default true).

== Changelog ==

= 1.0.0 =
* Initial release.
