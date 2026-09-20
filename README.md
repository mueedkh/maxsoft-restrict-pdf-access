# MaXsoft Restrict PDF Access

A WordPress plugin that keeps PDF files available to logged-in users only.
Logged-out visitors are redirected to the WordPress login page, a page you
pick, or any URL you enter.

## What it protects

**Media-library PDFs.** Direct links such as
`https://example.com/wp-content/uploads/2026/02/file.pdf` are routed through
WordPress, checked with `is_user_logged_in()`, and only then streamed back.
This is the protection that actually secures the file, and it works with no
other plugin installed.

**The PDF.js Viewer full-screen URL.** When the
[PDF.js Viewer](https://wordpress.org/plugins/pdfjs-viewer-shortcode/) plugin
is active, logged-out visitors opening its full-screen viewer are sent away
instead. The rule is written only while that plugin is active and is removed
automatically when it is deactivated.

Treat the viewer rule as a convenience layer rather than a lock. Apache's
rewrite engine can only read the raw cookie header, so it can tell that a
login cookie is present but not that it is valid. With the media rule enabled
the PDF itself still will not load, which is what matters.

## How it works

Activation writes a marked block into the site's root `.htaccess` that rewrites
protected requests to `index.php`. The real check then happens inside a normal
WordPress front-end request on `template_redirect`. No file bootstraps
WordPress directly, which keeps the plugin within the WordPress.org guidelines.

Files are streamed in 8 KB chunks with `Range` support, so large PDFs load
progressively in a viewer instead of being read into memory.

## Requirements

- WordPress 6.0 or newer
- PHP 7.4 or newer
- Apache with `mod_rewrite` and `AllowOverride`, and a writable root
  `.htaccess`

On Nginx, add the equivalent rules to your server block — they are in the FAQ
section of [`readme.txt`](readme.txt). If `.htaccess` is not writable the
settings screen says so and the rules can be pasted in by hand.

## Installation

Copy this repository into `wp-content/plugins/maxsoft-restrict-pdf-access/`
and activate it, then open **Settings → Restrict PDF Access**. Defaults are
applied on activation.

## Behind a CDN

Cloudflare and similar CDNs cache PDFs at the edge and serve them before the
request ever reaches WordPress, which bypasses the check. Add a cache rule that
bypasses the cache for paths ending in `.pdf`, then purge. The settings screen
shows a reminder when it detects Cloudflare.

## Hooks

```php
// Apply the viewer rule to another PDF.js-based viewer plugin.
add_filter( 'maxsoft_rpdf_pdfjs_viewer_active', '__return_true' );
```

## Development

Coding standards are configured in `phpcs.xml.dist`:

```bash
composer require --dev wp-coding-standards/wpcs
vendor/bin/phpcs
```

Directory icons and banners are generated from
[`.wordpress-org/build-assets.js`](.wordpress-org/build-assets.js); see the
[README there](.wordpress-org/README.md) for the publishing steps.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
