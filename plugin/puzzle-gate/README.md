# Puzzle Gate

Hide part of a page behind an interactive puzzle. The hidden content is never
sent to the browser until the server has confirmed the puzzle was actually
solved.

---

## Install

1. Zip the `puzzle-gate` folder (or upload it to `wp-content/plugins/`).
2. Plugins → Add New → Upload Plugin → Activate.
3. Settings → Puzzle Gate to configure.

## Use — block

In the editor, insert **Puzzle Gate** (Design category, or type `/puzzle`). Put
whatever you want hidden *inside* it — paragraphs, images, buttons, embeds,
other blocks, any nesting depth. Configure the puzzle in the sidebar.

The block assigns itself a unique id on insert, and re-rolls it if you duplicate
the block, so two gates can never collide.

## Use — shortcode

```
[puzzle_gate id="offer" type="slide" size="3" title="Members only" teaser="Slide the tiles into order."]
Anything here is hidden: text, images, shortcodes, blocks.
[/puzzle_gate]
```

Both syntaxes resolve to the same internal shape, so they behave identically and
can be mixed on the same site.

| Attribute | Applies to | Notes |
|---|---|---|
| `id` | all | **Always set it.** Unique per post. It is how the server finds the gate again. |
| `type` | all | `slide`, `riddle`, `sequence` |
| `title` / `teaser` / `button` | all | Copy on the lock plate |
| `size` | slide | 3–5. `3` = 8-puzzle, `4` = 15-puzzle |
| `image` | slide | Slice a picture across the tiles |
| `question` / `answer` / `hint` | riddle | `answer` accepts alternatives: `piano\|a piano` |
| `difficulty` | sequence | `normal` or `hard` |

---

## How it actually works

### The problem with every "content locker" you've seen

The usual approach prints the secret into the HTML and hides it with
`display:none` or removes it with JavaScript. Ctrl+U defeats it. So does
disabling JavaScript. It is theatre.

### What this does instead

The secret lives in exactly one place — the `post_content` column — and it stays
there. Here is the full lifecycle:

```
1. Page renders
   Shortcode outputs a lock plate. No secret, no answer, no scramble.

2. Visitor clicks "Open the lock"
   POST /wp-json/puzzle-gate/v1/challenge  { post_id, gate_id }
   Server generates the puzzle and splits it in two:
      public   → sent to the browser (the scramble, the question)
      solution → stored in a transient, keyed by a random 32-hex token
   Browser receives: { token, puzzle: <public half only> }

3. Visitor plays, then submits
   POST /solve  { token, payload }
   Server loads the solution from the transient and checks the payload.

4. Only on success
   Server re-reads the post, extracts the enclosed content, renders it,
   and returns it in the JSON response. The token is destroyed.
```

At no point before step 4 does the secret exist anywhere in the browser.

### The interesting part: verifying a sliding puzzle

A riddle is easy to verify securely — the answer is a hash on the server and the
client never sees it.

A sliding puzzle has no secret. Everyone knows the goal is `1 2 3 / 4 5 6 / 7 8 _`.
So asking the client "are you solved?" is worthless: it can just say yes.

The fix is to make the client prove it did the work:

- The server generates the scramble and remembers it.
- The client sends **the list of moves it made**, in order.
- The server replays those moves from its own copy of the scramble, rejecting
  any move that isn't legal, and checks the final board.

You cannot fake that without producing a real solution to the specific board you
were handed. See `Slide_Puzzle::verify()`.

Related detail in `generate()`: the scramble is produced by making random *legal*
moves from a solved board, never by `shuffle()`. Exactly half of all permutations
of a 15-puzzle are mathematically unsolvable, so shuffling would hand 50% of your
visitors an impossible board.

---

### How the block stays as secure as the shortcode

The block is **dynamic**: its `save()` writes the inner blocks into
`post_content`, but the front-end HTML is produced by PHP at request time via
`render_callback`. So the author's blocks are stored, and the server decides —
per request, per visitor — what actually gets sent.

One extra step matters. A `render_block_data` filter deletes the hidden subtree
from the render tree *before* WordPress renders it. Simply ignoring the rendered
output in the callback would already be safe in the sense that nothing prints,
but blocks have side effects: a gallery inside the gate would enqueue lightbox
scripts, an embed would hit an oEmbed endpoint, a form block would register
itself in the footer. Each of those tells an observer something about what is
behind the lock. Removing the subtree stops it at the source — and saves the
render work on every page view.

At reveal time, `Gate_Locator` walks `parse_blocks()` (which parses without
rendering, so no side effects) to find the gate, then renders its children with
`render_block()` one at a time. Deliberately *not* `(new WP_Block($node))->render()`,
which would call our own callback again and recurse back into the lock.

## Security decisions, and the reasoning

| Concern | What the plugin does | Why |
|---|---|---|
| Content leakage | Secret never enters the HTML | The only defence that survives View Source |
| Direct object reference | `Gate_Locator::is_viewable()` checks post status, capability, post password | Otherwise the REST endpoint becomes a reader for drafts and private posts |
| Replay | Token destroyed on success; one-time use | Stops one recorded payload unlocking forever |
| Brute force | Per-IP fixed-window throttle + per-challenge attempt cap | Makes guessing a riddle answer pointless |
| Timing attacks | `hash_equals()` for answer comparison | `===` leaks match length through response time |
| Bots | Minimum believable solve time per puzzle type | A replayed payload arrives implausibly fast |
| Cookie theft | Pass cookie is `HttpOnly`, `Secure`, `SameSite=Lax` | XSS can't read it; other sites can't use it |
| Cookie forgery | Cookie holds a random id; the unlock list lives server-side | A bearer token, not a client-side claim |
| Enumeration | Same generic 404 for missing/private/wrong-id | Distinct errors let people map your private posts |
| DoS via payload | Move lists capped at 4000; board size clamped to 5 | Bounds the work one request can cost you |
| Input | REST `sanitize_callback` on every argument, `absint`/`sanitize_key` | Validation happens before your handler runs |
| Output | `esc_attr()` / `esc_html()` at every echo | Escape at output, because safety is context-dependent |
| Privacy | IPs are HMAC-hashed before storage | Throttling works without keeping personal data |
| Admin actions | Capability check **and** nonce | Capability = "may they?", nonce = "did they mean to?" |
| SQL | No hand-written queries; uninstall uses `$wpdb->prepare()` + `esc_like()` | Bound parameters, never concatenation |

### The one honest limitation

For a *visual* puzzle, a determined developer can write a script that solves the
board and posts a valid move list. That is unavoidable — the puzzle is visible to
the player by definition. What is guaranteed is that they must produce a real
solution, and that no amount of poking at the page reveals the content without
one. If you need a hard wall, use `type="riddle"` (no derivable answer) or a real
login.

---

## Performance decisions

- **Assets load only on pages with a gate.** They're registered on
  `wp_enqueue_scripts` and enqueued inside the shortcode callback. Loading CSS/JS
  site-wide is the single most common reason a plugin "makes WordPress slow".
- **No challenge until intent.** The puzzle is generated when the visitor clicks,
  not on page load. Most visitors never click.
- **No duplicate storage.** The content isn't copied into a transient at render
  time — it's re-read from the post on demand. One `get_post()` (usually already
  in object cache), always fresh, zero writes on page views.
- **Page-cache compatible.** No nonce is required in the HTML, so a cached page
  works fine. Nonces expire; the server-issued token is the real CSRF defence.
- **Transients, not a custom table.** They use Redis/Memcached automatically when
  the site has a persistent object cache.
- **GPU-friendly animation.** Only `transform` and `opacity` are animated.
- **No jQuery, no build step.** ~7KB of vanilla JS.

---

## Extending it

Add your own puzzle type without touching plugin files:

```php
add_action( 'puzzle_gate_register_puzzles', function () {
	class My_Puzzle extends \PuzzleGate\Puzzle {
		public function slug(): string  { return 'colour'; }
		public function label(): string { return 'Colour match'; }

		public function generate( array $atts ): array {
			$target = sprintf( '#%06x', random_int( 0, 0xFFFFFF ) );
			return array(
				'public'   => array( 'swatch' => $target ),
				'solution' => array( 'hex' => $target ),
			);
		}

		public function verify( array $solution, $payload ): bool {
			return hash_equals( $solution['hex'], strtolower( (string) ( $payload['answer'] ?? '' ) ) );
		}
	}
	\PuzzleGate\Puzzle_Registry::register( new My_Puzzle() );
} );
```

You'd then add a matching renderer to `renderers.colour` in the JS.

Other hooks:

- `puzzle_gate_solved` — `do_action( $post_id, $gate_id, $seconds )`. Award points, fire an email, log to analytics.
- `puzzle_gate_secret_content` — filter the revealed HTML.
- `puzzle_gate_client_ip` — return `HTTP_CF_CONNECTING_IP` if you're behind Cloudflare.

---

## Things worth knowing

- **Search engines never see gated content.** Usually the point, occasionally a
  surprise. WordPress's own site search queries `post_content` directly, so a
  post can still *match* on hidden words even though the words aren't shown.
- **Excerpts are safe** — `strip_shortcodes()` removes an enclosing shortcode
  together with its content, and it only strips *registered* tags, which is why
  the shortcode is registered early.
- **Editors bypass the lock** by default so they aren't forced to solve their own
  puzzle on every preview. Turn it off in settings.
- **Rate limiting needs a persistent store.** On a host with a non-persistent
  object cache the throttle counters can evaporate. Transients in the database
  (the default) are fine.
