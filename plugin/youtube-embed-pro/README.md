# YouTube Embed Pro

Secure YouTube embeds for WordPress. Ships a `[yt_embed]` shortcode and a
**YouTube Embed Pro** block. No build step, no external libraries, no API key.

## Install

Upload `youtube-embed-pro.zip` via **Plugins → Add New → Upload Plugin**, or copy
the `youtube-embed-pro` folder into `wp-content/plugins/`, then activate.

## Block

Insert **YouTube Embed Pro** from the Embeds category. The block first asks
what you want to embed — Video, Short, Playlist, Live stream, Channel uploads,
or any link with auto-detect — then shows the matching input. Everything can be
changed later from the sidebar (Source, Layout, Playback and privacy panels).

## Channels (admin setting)

Under **Settings → YouTube Embed Pro**, save channels one per line as:

```
My Channel | UCxxxxxxxxxxxxxxxxxxxxxx
Second One | https://www.youtube.com/channel/UCyyyyyyyyyyyyyyyyyyyyyy
```

Saved channels appear in the block's "Channel uploads" choice and embed the
channel's latest videos as a playlist (via the channel's auto-maintained
`UU…` uploads playlist — no API key needed). Handles like `@name` are rejected
because they cannot be resolved without the YouTube Data API. Find the ID on a
channel page under "Share channel → Copy channel ID".

## Shortcode

```
[yt_embed url="https://www.youtube.com/watch?v=dQw4w9WgXcQ"]
[yt_embed url="https://www.youtube.com/shorts/abc12345678" type="short"]
[yt_embed url="https://www.youtube.com/playlist?list=PLxxxxxxxxxxxxxxxx" type="playlist"]
[yt_embed url="dQw4w9WgXcQ" start="1m30s" ratio="16:9" max_width="720"]
[yt_embed channel="UCxxxxxxxxxxxxxxxxxxxxxx"]
```

| Attribute   | Values                                          | Default | Notes |
|-------------|-------------------------------------------------|---------|-------|
| `url`       | Full YouTube URL, video ID or playlist ID       | —       | Required unless `channel` is set |
| `channel`   | Channel ID (`UC…`) or channel URL               | —       | Embeds the channel's uploads |
| `type`      | `auto`, `video`, `short`, `playlist`, `live`, `channel` | `auto` | Overrides detection |
| `title`     | Text                                            | generic | Used by screen readers |
| `ratio`     | `16:9`, `9:16`, `4:3`, `3:2`, `1:1`, `21:9`     | by type | Shorts default to 9:16 |
| `max_width` | Pixels                                          | `0`     | `0` fills the container; Shorts default to 420 |
| `start`     | Seconds or `1h2m3s`                             | from URL | |
| `privacy`   | `yes` / `no`                                    | `yes`   | Uses youtube-nocookie.com |
| `facade`    | `yes` / `no`                                    | `yes`   | Thumbnail first, player on click |
| `autoplay`  | `yes` / `no`                                    | `no`    | Forces mute; disables the facade |
| `loop`      | `yes` / `no`                                    | `no`    | |
| `controls`  | `yes` / `no`                                    | `yes`   | |
| `captions`  | `yes` / `no`                                    | `no`    | |
| `class`     | Space-separated class names                     | —       | Sanitised per token |

## Supported link shapes

`watch?v=`, `youtu.be/`, `/shorts/`, `/embed/`, `/v/`, `/live/`, `/playlist?list=`,
`/embed/videoseries?list=`, bare 11-character video IDs, bare playlist IDs, plus
`t=` / `start=` timestamps on any of them.

## Security notes

- Host allowlist — only YouTube domains can ever end up in an `iframe` `src`.
- Video IDs must match `[A-Za-z0-9_-]{11}`; playlist IDs are alphabet-checked.
- `esc_url_raw()` strips `javascript:` and `data:` schemes before parsing.
- Every attribute is cast, clamped or whitelisted in one place (`sanitize_args`).
- Aspect ratios come from a fixed map, so nothing user-supplied reaches the
  `style` attribute.
- Output uses `esc_url()`, `esc_attr()` and `esc_html()` at the point of print.
- The block is dynamic (`save: null`, `supports.html: false`), so no raw HTML is
  stored in post content.
- The front-end script rebuilds the iframe with `setAttribute` and re-validates
  the URL, so nothing is parsed as HTML.
- The settings page uses the Settings API (nonce + capability handled by
  WordPress), is limited to `manage_options`, and channel IDs must match
  `UC[A-Za-z0-9_-]{22}` — invalid lines are skipped with an admin notice.
- Channel data reaches the editor via `wp_localize_script`, which JSON-encodes
  it so nothing can escape into script context.
- `uninstall.php` deletes the option when the plugin is removed.

## Filters

```php
// Allow an extra host.
add_filter( 'ytep_allowed_hosts', function ( $hosts ) {
	$hosts[] = 'youtube.co.uk';
	return $hosts;
} );
```
