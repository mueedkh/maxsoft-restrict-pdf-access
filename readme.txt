=== MaXsoft Restrict PDF Access ===
Contributors: maxsofttechnologies
Tags: pdf, restrict, login, members, protection
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Restrict media-library PDFs to logged-in users, and optionally the PDF.js Viewer URL. Send blocked visitors to the login page or any page.

== Description ==

MaXsoft Restrict PDF Access keeps your PDF files available to logged-in users
only. Visitors who are not logged in are redirected to a page you choose (the
WordPress login page by default).

Two independent protections, each with its own on/off switch:

1. **Media-library PDFs** - direct links such as
   `https://example.com/wp-content/uploads/2026/02/file.pdf` are blocked for
   logged-out visitors. This works on its own and does not require any PDF
   viewer plugin.

2. **PDF.js Viewer full-screen URL** - the full-screen viewer of the
   "PDF.js Viewer" (PDFjs Viewer Shortcode) plugin, e.g.
   `.../wp-content/plugins/pdfjs-viewer-shortcode/pdfjs/web/viewer.php?file=...`
   is sent away for logged-out visitors. This rule is applied **only when that
   plugin is active**, and is kept in sync automatically when you activate or
   deactivate it.

= How it works =

When a protected request comes in, it is routed through WordPress, which
verifies the visitor is logged in and then either serves the PDF (streamed
with HTTP range support, so large files load in the viewer) or redirects them
to your chosen page. The redirect destination is read live from your settings,
so changing it takes effect immediately.

= Which protection actually secures the file =

The media-library rule is the one that protects the PDF. Every request for an
uploaded PDF is routed through WordPress and checked with
`is_user_logged_in()`, so the file is never served unless the session is real.

The viewer rule is a convenience layer on top of that. Apache's rewrite engine
can only read the raw cookie header, so it can see that a login cookie is
present but cannot verify that it is genuine. Someone who sets a fake cookie
can reach the viewer page - but with the media rule enabled the PDF itself
still will not load. Keep the media-library option enabled.

= Redirect destination =

Under **Settings - Restrict PDF Access** you can send blocked visitors to:

* the WordPress login page (default),
* a specific page on your site, or
* a custom URL.

= Requirements =

* Apache with `mod_rewrite` and `AllowOverride` enabled, so the rules in
  `.htaccess` are honored, and a writable root `.htaccess`. If `.htaccess` is
  not writable the settings page tells you, and the rules are listed below to
  add manually.
* On Nginx (which ignores `.htaccess`) add the equivalent rules from the FAQ.

== Installation ==

1. Upload the `maxsoft-restrict-pdf-access` folder to `/wp-content/plugins/`,
   or install the ZIP via **Plugins - Add New - Upload Plugin**.
2. Activate the plugin through the **Plugins** screen.
3. Go to **Settings - Restrict PDF Access** to choose what to restrict and
   where to redirect blocked visitors. Sensible defaults are applied on
   activation.

== Frequently Asked Questions ==

= My PDFs are still downloadable without login. I use Cloudflare or a CDN. =

A CDN such as Cloudflare caches PDF files at its edge and serves them before
the request reaches WordPress, so the login check never runs. This is the most
common reason direct PDF protection appears not to work.

Fix it by telling the CDN not to cache PDFs, then purging the cache. On
Cloudflare: **Caching - Cache Rules - Create rule**, match *URI Path ends with*
`.pdf` (optionally also *contains* `/wp-content/uploads/`), set
**Bypass cache**, then purge the cache. The plugin shows a reminder on its
settings page when it detects that the site is behind Cloudflare.

= I don't use any PDF viewer plugin. Can I just protect the media-library PDFs? =

Yes. Leave "Restrict direct PDF files in the media library" enabled and leave
"Restrict the PDF.js Viewer full-screen URL" off (or simply don't install a
viewer plugin). The media-library protection works completely on its own.

= Does the viewer rule break if the PDF.js Viewer plugin is not installed? =

No. The viewer rule is written only while the "PDF.js Viewer" plugin is
active, and is removed automatically when you deactivate it.

= Are logged-in users affected? =

No. Logged-in users get PDFs served normally, including inside the PDF.js
viewer.

= It says my .htaccess is not writable. What do I add manually? =

Add this block to your site's root `.htaccess` (adjust paths if WordPress is
in a subdirectory):

`# BEGIN MaXsoft Restrict PDF Access`
`<IfModule mod_rewrite.c>`
`RewriteEngine On`
`# PDF.js viewer (only needed if you use that plugin):`
`RewriteCond %{ENV:REDIRECT_maxsoft_rpdf} !=1`
`RewriteCond %{HTTP_COOKIE} !wordpress_logged_in_[0-9a-f]{32}= [NC]`
`RewriteCond %{REQUEST_URI} /pdfjs/web/viewer\.(php|html)$ [NC]`
`RewriteRule .* /index.php?maxsoft_rpdf_block=viewer [QSA,L,E=maxsoft_rpdf:1]`
`# Uploaded PDFs:`
`RewriteRule ^wp-content/uploads/(.+\.pdf)$ /index.php?maxsoft_rpdf_serve=$1 [QSA,L,NC]`
`</IfModule>`
`# END MaXsoft Restrict PDF Access`

= How do I do this on Nginx? =

Nginx does not read `.htaccess`. Add the equivalent to your server block and
reload Nginx:

`location ~* /pdfjs/web/viewer\.(php|html)$ {`
`    if ($http_cookie !~* "wordpress_logged_in_[0-9a-f]{32}=") {`
`        rewrite ^ /index.php?maxsoft_rpdf_block=viewer last;`
`    }`
`    # ...your existing PHP handling for this location...`
`}`
`location ~* ^/wp-content/uploads/(?<pdfpath>.+\.pdf)$ {`
`    rewrite ^ /index.php?maxsoft_rpdf_serve=$pdfpath last;`
`}`

= Can I protect a different PDF.js-based viewer plugin? =

Use the `maxsoft_rpdf_pdfjs_viewer_active` filter to force the viewer rule on:
`add_filter( 'maxsoft_rpdf_pdfjs_viewer_active', '__return_true' );`

= Does uninstalling leave anything behind? =

No. Deactivating removes the `.htaccess` block so PDFs are reachable again,
and deleting the plugin also removes its saved settings.

== Screenshots ==

1. The settings screen under Settings - Restrict PDF Access, with the two
   protection switches and the redirect destination options.
2. A logged-out visitor opening a direct PDF link is sent to the login page.

== Changelog ==

= 1.2.0 =
* Renamed the plugin to MaXsoft Restrict PDF Access. Settings and `.htaccess`
  rules from 1.1.0 are migrated automatically on update.
* Change: all functions, constants, options and query arguments now use a
  longer, unique `maxsoft_rpdf_` prefix.
* Fix: the post-login return URL is now built from the site address instead of
  the `Host` request header.
* Fix: the `Range` request header is sanitized before parsing, and multipart
  range requests now fall back to serving the whole file.
* Fix: a `filesize()` failure returns 500 instead of sending a bad
  `Content-Length`.
* Change: the viewer rule's cookie test now requires a well-formed session
  cookie name, and its limits are documented on the settings screen and in the
  FAQ.
* New: served PDFs send `X-Robots-Tag: noindex, nofollow`.

= 1.1.0 =
* New: separate on/off switches for media-library PDFs and the PDF.js viewer.
* New: the PDF.js viewer rule is applied only when that plugin is active, and
  is kept in sync automatically on activation/deactivation.
* Change: protected requests now run through WordPress (index.php) instead of a
  directly bootstrapped file.
* New: settings-page warning when the site is behind Cloudflare, which can
  edge-cache PDFs and bypass the protection.
* Added the viewer-detection filter.

= 1.0.0 =
* Initial release: restrict media-library PDFs and the PDF.js viewer URL with a
  configurable redirect target.

== Upgrade Notice ==

= 1.2.0 =
The plugin has been renamed and re-prefixed. Your settings and rewrite rules
are migrated automatically. Re-save Settings - Restrict PDF Access afterwards
if your .htaccess is not writable.
