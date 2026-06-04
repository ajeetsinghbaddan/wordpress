# Prompt: Build a Secure WordPress Testimonials Plugin

Build a complete, production-ready WordPress plugin from scratch. Follow every requirement below and write thoroughly commented code (explain what each function does and why) so the result is maintainable.

## 1. Plugin Identity
- Name: "ASB Testimonials Showcase"
- Text domain: `asb-testimonials-showcase` (fully translatable / i18n-ready)
- License: GPLv2 or later
- Target: WordPress 6.0+, PHP 7.4+ and 8.x compatible
- Must follow the official WordPress Coding Standards (WPCS) and Plugin Handbook best practices.
- Distributable as a standard installable .zip — no external paid dependencies.

## 2. Data Model (use native WordPress APIs, not custom DB tables where avoidable)
- Register a Custom Post Type `testimonial` (not publicly queryable as a single page unless desired; mainly managed in admin).
- Register a Custom Taxonomy `testimonial_category` attached to that CPT so testimonials can be grouped by category.
- Each testimonial should store these fields via post meta:
  - Author/Client name (text)
  - Role / Company (text)
  - Testimonial text (rich text / textarea)
  - Star rating 1–5 (integer)
  - Client photo (WordPress Media Library attachment ID)
- If any custom database queries are needed, ALWAYS use `$wpdb->prepare()`.

## 3. Admin / Backend
- Add an admin menu where the user can create unlimited testimonials and assign them to one or more categories.
- Build custom meta boxes for the fields above using the Media Library uploader for the photo.
- Provide a settings page (default design variation, default category filter, etc.).
- Every meta box save MUST verify a nonce and check `current_user_can('edit_post', $post_id)` before saving.
- Sanitize all saved input: `sanitize_text_field()`, `wp_kses_post()` for rich text, `absint()` for numbers/ratings, `absint()`/validation for attachment IDs.

## 4. Frontend Output — 6 Design Variations
Provide SIX distinct, visually different layouts the user can choose from, for example:
1. Classic card grid
2. Horizontal slider/carousel
3. Single-quote spotlight (one large rotating testimonial)
4. Masonry / Pinterest-style grid
5. Minimal list with avatars
6. Bubble/chat-style speech cards

For each layout:
- Fully responsive (mobile-first).
- Accessible (semantic HTML, ARIA where needed, keyboard-navigable slider).
- Enqueue CSS/JS properly with `wp_enqueue_style`/`wp_enqueue_script`, versioned, loaded only when the testimonials block/shortcode/widget is present on the page (conditional/asset-on-demand loading).
- No inline `<style>`/`<script>` blobs; use enqueued assets.

## 5. Three Ways to Embed (all must support: choosing design variation, filtering by category, and setting count/limit)
- **Shortcode**, e.g. `[testimonials design="slider" category="clients" count="6"]`
- **Gutenberg block** (WordPress block editor) with `block.json`, server-side render via `register_block_type`, and editor controls (InspectorControls) for design, category, and count.
- **Elementor widget** registered through Elementor's widget API, with controls for the same options, that only loads when Elementor is active (`did_action('elementor/loaded')` check).

## 6. Security — make it airtight (this is critical)
- Start every PHP file with `if ( ! defined( 'ABSPATH' ) ) { exit; }` to block direct access.
- Escape ALL output at the point of output: `esc_html()`, `esc_attr()`, `esc_url()`, `wp_kses_post()`.
- Sanitize ALL input on the way in.
- Use nonces (`wp_create_nonce` / `wp_verify_nonce` / `check_admin_referer`) on every form submission and AJAX request.
- Capability checks (`current_user_can`) on every privileged action.
- If using AJAX, register via `wp_ajax_` / `wp_ajax_nopriv_` hooks with nonce + capability verification.
- Validate and restrict any file/image handling to allowed types via the Media Library API.
- No use of `eval`, no unsanitized `$_GET`/`$_POST`/`$_REQUEST`, no SQL string concatenation.
- Prefix all functions, classes, hooks, and option names to avoid collisions.

## 7. Lifecycle & Quality
- Use activation/deactivation hooks (flush rewrite rules on activation after CPT registration).
- Provide an `uninstall.php` that cleanly removes the plugin's options (and optionally testimonials, behind a setting) so nothing is left behind.
- Organize code into a clear file structure (e.g. `includes/`, `admin/`, `public/`, `blocks/`, `elementor/`, `assets/`).
- Object-oriented or well-namespaced procedural code, with a main bootstrap class.

## 8. Deliverable
Output the full plugin file by file, with the complete folder structure, and include inline comments explaining the purpose of each major function, hook, and security measure.