=== Flipbook Studio ===
Contributors: crossatlanticsoftware
Tags: pdf, flipbook, viewer, catalogue, ebook
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Upload a PDF and publish it as a page-turning flipbook that is served privately through signed, expiring links.

== Description ==

Flipbook Studio adds a "Flipbooks" section to wp-admin. You upload a PDF, choose who can read it, and drop the shortcode wherever you want it.

The PDF never becomes a public file. It is stored outside the media library in a folder that refuses direct HTTP requests, and it only reaches a browser through a signed URL that expires and is tied to that visitor.

= Reading experience =

* A "Flipbook" block for the editor, plus the shortcode for anywhere else
* Real page-turn animation with drag, swipe and click
* Thumbnail panel, PDF bookmark contents, full-text search
* Zoom with drag-to-pan, fullscreen, keyboard shortcuts
* Synthesised page-turn sound with a mute toggle, no audio file to download
* Deep links to a specific page, and a copy-link button that produces one
* Three themes: Ink, Paper, Slate
* Renders the pages you are about to see, not all of them at once
* Loads nothing until the reader scrolls near it

= Access control =

* Per-flipbook password, stored hashed
* Sign-in requirement
* Expiry date and time
* Domain allow list for embeds
* Free-preview page limit with a gate at the end
* Download and print buttons off by default
* Per-reader watermark carrying the reader's name and the date

= Reading activity =

Page-level read counts and unique reader counts, stored with a random per-tab session id and a salted hash of the IP. No cookies, no raw addresses, and rows older than a year are trimmed automatically.

== Installation ==

1. Upload the `flipbook-studio` folder to `/wp-content/plugins/`, or install the ZIP from Plugins > Add New > Upload Plugin.
2. Activate it.
3. Go to Flipbooks > Settings and check the storage self-test at the top of the page.
4. Add a flipbook, upload a PDF, publish, and paste the shortcode into any page.

= Nginx =

Apache and IIS pick up the deny rules this plugin writes. Nginx does not read them, so add this to your server block and reload:

    location ~* /wp-content/uploads/flipbook-protected/ {
        deny all;
        return 403;
    }

The settings page performs a live request against the folder and tells you which situation you are in.

== Shortcode ==

    [flipbook id="12"]
    [flipbook id="12" height="800"]
    [flipbook id="12" theme="paper"]
    [flipbook id="12" page="4"]
    [flipbook id="12" toolbar="no"]

== What the security actually covers ==

Worth being straight about, because "secure PDF" is often oversold.

Enforced on the server, and therefore real:

* The PDF has no public URL. Guessing the path does not help, and the folder refuses direct requests.
* Every request for the file must carry a signed token that expires and is bound to the visitor's browser. A link copied out of the network tab stops working.
* Password, sign-in, expiry and domain rules are all checked server-side on every single byte range the reader requests, not once at page load.
* Uploads are checked four ways, including reading the file header, so a script renamed to .pdf never lands on disk.
* Password attempts and file requests are rate limited.
* The stored path is validated against the private folder, so a tampered database value cannot be used to read arbitrary files.

Deterrents, not guarantees:

* Hiding the download and print buttons, blocking right-click, and the watermark. Anyone who can see a page can photograph it. These raise the effort of a casual grab and make a leak traceable; they are not DRM, and no browser-based viewer can be.

== Third-party code ==

* PDF.js 3.11.174 by Mozilla, Apache License 2.0, in `assets/vendor/pdfjs/`
* StPageFlip 2.0.7 by Nodlik, MIT License, in `assets/vendor/pageflip/`

Both are bundled locally, including the PDF.js character maps needed for CJK documents. Nothing is fetched from a CDN and no request leaves your server.

== Changelog ==

= 1.1.0 =
* Flipbook block for the editor: pick a book, override height, theme, start page and toolbar from the sidebar, with wide and full alignment.
* Responsive height: on screens under 783px the reader caps itself to 72% of the visible viewport, so a tall desktop height never overflows a phone.
* New REST route flipbook/v1/list for the block picker, restricted to users who can edit posts.

= 1.0.0 =
* First release.
