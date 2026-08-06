# Product Enquiry for WooCommerce (Contact Form 7)

Adds an **Enquire about this product** button to WooCommerce single product pages. Clicking it opens an accessible popup containing a Contact Form 7 form, and the enquiry email arrives with the product name, SKU, price, category, page URL and product ID already attached.

---

## Install

1. Copy the `wc-product-enquiry` folder into `wp-content/plugins/` (or upload the ZIP via **Plugins → Add New → Upload Plugin**).
2. Activate it. WooCommerce and Contact Form 7 must both be active.
3. Create or pick a CF7 form (**Contact → Contact Forms**).
4. Go to **WooCommerce → Product Enquiry**, choose that form, save.
5. Open the form's **Mail** tab and paste the tags shown on the settings page into the message body:

```
Product: [_wcpe_product_name]
SKU: [_wcpe_product_sku]
Price: [_wcpe_product_price]
Category: [_wcpe_product_cats]
Page: [_wcpe_product_url]
Product ID: [_wcpe_product_id]
```

Putting `[_wcpe_product_name]` in the **Subject** line too makes enquiries easy to scan in an inbox.

---

## File map

| File | Job |
|---|---|
| `wc-product-enquiry.php` | Constants, dependency check, boot |
| `includes/class-wcpe-settings.php` | Option schema, defaults, sanitisation, hook whitelist |
| `includes/class-wcpe-cf7.php` | Hidden fields, honeypot, mail tags, spam checks |
| `includes/class-wcpe-frontend.php` | Button, conditional asset loading, modal markup |
| `includes/class-wcpe-admin.php` | Settings screen, per-product override |
| `assets/css/wcpe-frontend.css` | Modal styling (CSS custom properties) |
| `assets/js/wcpe-frontend.js` | Open/close, focus management, CF7 events |
| `uninstall.php` | Deletes options and product meta on delete |

---

## How the product data reaches your inbox

```
Product page render
  └─ hidden inputs added to the form:  wcpe_product_id  +  wcpe_sig (HMAC)

Visitor submits
  └─ CF7 spam filters: honeypot, per-IP rate limit
  └─ signature verified with hash_equals()
  └─ product ID looked up again in the database
  └─ [_wcpe_product_name] etc. filled from the DB record, not from the POST
```

**The browser only ever sends an ID.** Every human-readable value in the email is re-read from the database at send time, so a crafted POST cannot inject a fake product name, a phishing URL or HTML into your mail.

---

## Security decisions, briefly

| Concern | What the plugin does |
|---|---|
| Direct file access | `defined( 'ABSPATH' ) || exit;` at the top of every PHP file |
| Untrusted input | Everything cast/filtered on the way in (`absint`, `sanitize_text_field`, `sanitize_key`), everything escaped on the way out (`esc_html`, `esc_attr`, `esc_url`, `wp_kses_post`) |
| Settings tampering | Settings API + `sanitize_callback`; the sanitiser only writes keys that exist in `defaults()` |
| Privilege escalation | `manage_woocommerce` on the settings screen; product meta saved through WooCommerce's own nonce-verified pipeline |
| Spoofed product IDs | `wp_hash()` HMAC signature checked with `hash_equals()` (constant-time), plus a published-product check |
| Spam | CSS-hidden honeypot + per-IP hourly rate limit via transients |
| Personal data | IPs are hashed before being used as transient keys; the honeypot value is stripped from stored submissions |
| Nonces | Deliberately not used on the public form: nonces are session-bound and break on cached pages for logged-out visitors. CF7 handles its own request lifecycle; the HMAC covers what we care about. |

---

## Performance decisions, briefly

- **One decision, made early.** `WCPE_Frontend::setup()` runs on `wp` and bails immediately unless this is a single product page with the feature enabled. Nothing is hooked, enqueued or rendered anywhere else on the site.
- **One option row.** All settings live in `wcpe_settings`, autoloaded with core options, then memoised in a static property — zero extra queries per request.
- **No jQuery.** The front-end script is dependency-free vanilla JS.
- **Modal in the footer, printed once.** Keeps it out of theme containers with `overflow: hidden` and avoids duplicate DOM IDs.
- **CF7 assets requested explicitly** via `wpcf7_enqueue_scripts()`, because CF7 5.8+ skips them when it does not detect a form early in the page.
- **Transients for rate limiting** — they self-expire, so nothing accumulates in `wp_options`.

---

## Accessibility

Built on the native `<dialog>` element, which provides focus trapping, Escape-to-close and background inertness. Browsers without `showModal()` get a JS fallback that reproduces the trap manually. The dialog is labelled by its heading, the close button has an `aria-label`, focus moves to the first field on open and returns to the trigger on close, and `prefers-reduced-motion` is respected.

---

## Theming

The stylesheet supplies **structure only** — layout, scrolling, focus behaviour. Colour, typography, border radius and button appearance are inherited from the active theme by three mechanisms:

- `currentColor` + `color-mix()` for hairlines, hover states and the product strip tint, so they sit correctly on any background without hard-coded values
- Block-theme custom properties (`--wp--preset--color--base` / `--contrast`) for the popup surface, falling back to the CSS system colours `Canvas` / `CanvasText`, which already follow the visitor's OS light/dark setting
- `em` units rather than `rem`, so sizes track the theme's own type scale

The trigger button carries the theme's `.button` class, so it is styled by WooCommerce/your theme, not by this plugin. The popup heading has no font rules at all — it inherits your theme's `h2`.

**If your theme's `h2` is too large inside a popup:**

```css
#wcpe-modal .wcpe-modal__title {
	font-size: 1.15em;
}
```

**To nudge the surface without touching the rest:**

```css
#wcpe-modal {
	--wcpe-surface: #fffdf7;
	--wcpe-ink: #1b1a17;
	--wcpe-radius: 12px;
	--wcpe-pad: 2em;
}
```

**To style the popup entirely from your theme**, drop the plugin CSS and keep the markup:

```php
add_filter( 'wcpe_load_styles', '__return_false' );
```

All selectors are single classes with no `!important`, so theme rules override them without a specificity fight.

## Customising behaviour

**Change when the button appears** — filter the visibility decision:

```php
// Only offer an enquiry when the product is out of stock.
add_filter( 'wcpe_is_enabled', function ( $enabled, $product ) {
	return $enabled && ! $product->is_in_stock();
}, 10, 2 );
```

**Per-product control** — **Product data → Advanced → Enquiry button** (always show / never show / use global).

---

## Optional extras worth adding later

- **Flamingo** (by the CF7 author) stores every enquiry in the database, so nothing is lost if email delivery fails. This plugin already writes a readable product name and URL into the stored record.
- **An SMTP plugin** (WP Mail SMTP, FluentSMTP). `wp_mail()` through your host's PHP mailer is the most common reason enquiry emails land in spam.
- **CF7's reCAPTCHA/Turnstile integration** if you get targeted spam that beats the honeypot.

---

## Testing checklist

- [ ] Button appears in the chosen position and does **not** add to cart when clicked
- [ ] Popup opens, traps Tab, closes on Escape, backdrop click and the × button
- [ ] Email arrives with the correct product name, SKU, price and URL
- [ ] Variable, grouped and out-of-stock products all behave
- [ ] Per-product "Never show" hides the button
- [ ] Submitting 6+ times in an hour (default limit 5) is rejected
- [ ] Page still renders correctly with a page cache enabled

---

Tested against WooCommerce 8.x–9.x, Contact Form 7 5.7+, WordPress 6.0+, PHP 7.4+.
