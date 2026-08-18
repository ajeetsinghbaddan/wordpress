# Guest Post Submissions

Visitors submit blog posts from a front-end form. Nothing goes live until a moderator publishes it.

## Install

1. Copy the `guest-post-submissions` folder into `wp-content/plugins/`.
2. Activate it in **Plugins**.
3. Go to **Posts → Guest Post Settings** and pick which categories guests may use, and which account owns published guest posts.
4. Put `[guest_post_form]` on any page.

Optional shortcode attributes:

```
[guest_post_form title="Write for us" intro="We publish two guest essays a month."]
```

## How the flow works

```
Visitor fills form
        │
        ▼
admin-post.php ──► GPS_Submission_Handler
        │           nonce → honeypot → time trap → rate limit → validate
        ▼
wp_insert_post( post_status: 'pending' )   ← hardcoded, never from the request
        │
        ▼
Posts → Guest Submissions   (moderator publishes, rejects, or edits first)
        │
        ├── Publish → post_status: 'publish'      → guest emailed
        └── Reject  → post_status: 'gps_rejected' → guest emailed with the note
```

Approved posts are **owned** by the account you configure but **display** the guest's name, so themes that call `get_the_author_meta()` keep working.

## Where each concern lives

| File | Responsibility |
|---|---|
| `guest-post-submissions.php` | Header, constants, autoloader, activation |
| `includes/class-gps-plugin.php` | Wiring, author-name display, activation logic |
| `includes/class-gps-form.php` | Shortcode, conditional assets, form state across redirects |
| `includes/class-gps-submission-handler.php` | **All input validation.** The security boundary |
| `includes/class-gps-media.php` | Upload validation (6 layers) |
| `includes/class-gps-rate-limiter.php` | Per-IP throttling on transients |
| `includes/class-gps-admin.php` | Menu, approve/reject, capability + nonce checks |
| `includes/class-gps-list-table.php` | The moderation table |
| `includes/class-gps-settings.php` | Settings API, option sanitizing |
| `includes/class-gps-notifications.php` | The three emails |

## Security model at a glance

| Threat | Defence |
|---|---|
| CSRF | `wp_nonce_field` on the form, `wp_verify_nonce` on submit, per-post nonces on every admin action |
| Stored XSS | `wp_kses` with a narrow allowlist on input, context-correct escaping on every output |
| Privilege escalation | `post_status` is hardcoded to `pending`; there is no request path to `publish` |
| Unauthorised moderation | Custom `gps_moderate_submissions` capability, checked on both the menu and the handler |
| Malicious file upload | Upload-error check, size cap, magic-byte/extension match, `getimagesize`, core MIME allowlist |
| Open redirect | `wp_validate_redirect` + `wp_safe_redirect` on the return URL |
| Spam | Honeypot, signed time trap, per-IP rate limit, plus a filter for Akismet/Turnstile |
| SQL injection | No raw SQL except one `$wpdb->prepare`'d cleanup query in `uninstall.php` |
| Header injection | Newlines stripped from mail subjects; plain-text bodies only |
| Duplicate submissions | Post/Redirect/Get, plus a client-side double-click guard |
| Data loss | Uninstall only deletes content if you explicitly opt in |

## Extending it

```php
// Reject spam using any service you like.
add_action( 'gps_validate_submission', function ( $errors, $data ) {
	if ( my_spam_service( $data['content'] ) ) {
		$errors->add( 'spam', 'This looks like spam.' );
	}
}, 10, 2 );

// Post to Slack when something arrives.
add_action( 'gps_submission_created', function ( $post_id, $data ) {
	my_slack_ping( $data['title'] );
}, 10, 2 );

// Allow tables in guest content.
add_filter( 'gps_allowed_html', function ( $tags ) {
	$tags['table'] = array();
	$tags['tr']    = array();
	$tags['td']    = array( 'colspan' => array() );
	return $tags;
} );

// Behind Cloudflare? Opt in to the real client IP.
add_filter( 'gps_visitor_ip', function ( $ip ) {
	return isset( $_SERVER['HTTP_CF_CONNECTING_IP'] )
		? $_SERVER['HTTP_CF_CONNECTING_IP']
		: $ip;
} );
```

Available hooks: `gps_submission_created`, `gps_submission_approved`, `gps_submission_rejected`, `gps_validate_submission`, `gps_allowed_html`, `gps_visitor_ip`.

## Theme override

Copy `templates/form.php` to `wp-content/themes/your-theme/guest-post-submissions/form.php`. The plugin picks the theme copy up automatically, so your markup survives plugin updates.

## Known limits

- Uses one `meta_key` join to find guest posts. Fine into six figures of posts; past that, a custom post type would be the better model.
- Rate limiting is a fixed window, so an attacker can burst 2× the limit across a window boundary. Adequate for this threat model.
- No Akismet integration out of the box — use `gps_validate_submission`.
