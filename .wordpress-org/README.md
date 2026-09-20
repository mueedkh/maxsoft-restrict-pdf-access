# WordPress.org directory assets

These files are **not** part of the plugin ZIP. They live in the `assets/`
directory at the top level of the plugin's SVN repository, alongside `trunk/`
and `tags/` — not inside them. `.distignore` keeps this folder out of the
build.

## Present

| File | Size | Purpose |
| --- | --- | --- |
| `icon-128x128.png` | 128×128 | Search results and the plugin card |
| `icon-256x256.png` | 256×256 | High-DPI version of the above |
| `banner-772x250.png` | 772×250 | Header on the plugin page |
| `banner-1544x500.png` | 1544×500 | High-DPI version of the above |

Regenerate them with:

```bash
node .wordpress-org/build-assets.js .wordpress-org
```

## Still needed

Two screenshots, captured from a real install. The filenames are fixed and the
captions come from the `== Screenshots ==` list in `readme.txt`, in order:

| File | Caption it maps to |
| --- | --- |
| `screenshot-1.png` | The settings screen under Settings → Restrict PDF Access |
| `screenshot-2.png` | A logged-out visitor being sent to the login page |

Filenames must be lowercase. Keep each image under 10 MB.

## Publishing

Copy this folder's contents into `assets/` in SVN:

```bash
svn co https://plugins.svn.wordpress.org/maxsoft-restrict-pdf-access
cp .wordpress-org/* maxsoft-restrict-pdf-access/assets/
svn ci -m "Add directory assets"
```

Images are served through a CDN and cached, so a change can take a while to
appear.
