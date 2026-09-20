<?php
/**
 * Plugin Name:       MaXsoft Restrict PDF Access
 * Plugin URI:        https://github.com/mueedkh/maxsoft-restrict-pdf-access
 * Description:       Restrict media-library PDF files to logged-in users, and optionally the PDF.js Viewer full-screen URL. Blocked visitors are sent to a page you choose.
 * Version:           1.2.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            MaXsoft Technologies
 * Author URI:        https://maxsofttechnologies.com/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       maxsoft-restrict-pdf-access
 *
 * @package MaxsoftRestrictPdfAccess
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

define( 'MAXSOFT_RPDF_VERSION', '1.2.0' );
define( 'MAXSOFT_RPDF_FILE', __FILE__ );
define( 'MAXSOFT_RPDF_DIR', plugin_dir_path( __FILE__ ) );
define( 'MAXSOFT_RPDF_OPTION', 'maxsoft_rpdf_settings' );

/** Marker used for this plugin's block in .htaccess. */
define( 'MAXSOFT_RPDF_MARKER', 'MaXsoft Restrict PDF Access' );

/**
 * Folder slug of the "PDF.js Viewer" (PDFjs Viewer Shortcode) plugin.
 * Detection matches any active plugin whose path starts with this.
 */
define( 'MAXSOFT_RPDF_PDFJS_SLUG', 'pdfjs-viewer-shortcode' );

/* -------------------------------------------------------------------------
 * Settings
 * ---------------------------------------------------------------------- */

/**
 * Default settings.
 *
 * restrict_media:   1|0  protect direct PDF files in the media library.
 * restrict_viewer:  1|0  protect the PDF.js full-screen viewer URL
 *                        (only applied when the PDF.js Viewer plugin is active).
 * redirect_type:    'login' | 'page' | 'custom'
 * redirect_page_id: WP page ID (when type = page)
 * redirect_url:     absolute URL (when type = custom)
 *
 * @return array
 */
function maxsoft_rpdf_default_settings() {
	return array(
		'restrict_media'   => 1,
		'restrict_viewer'  => 1,
		'redirect_type'    => 'login',
		'redirect_page_id' => 0,
		'redirect_url'     => '',
	);
}

/**
 * Get merged settings.
 *
 * @return array
 */
function maxsoft_rpdf_get_settings() {
	$saved = get_option( MAXSOFT_RPDF_OPTION, array() );
	if ( ! is_array( $saved ) ) {
		$saved = array();
	}
	return wp_parse_args( $saved, maxsoft_rpdf_default_settings() );
}

/**
 * Resolve the URL a blocked visitor should be sent to.
 *
 * @param string $return_to Optional URL to return to after logging in.
 * @return string
 */
function maxsoft_rpdf_get_redirect_url( $return_to = '' ) {
	$settings = maxsoft_rpdf_get_settings();

	switch ( $settings['redirect_type'] ) {
		case 'page':
			if ( ! empty( $settings['redirect_page_id'] ) ) {
				$url = get_permalink( (int) $settings['redirect_page_id'] );
				if ( $url ) {
					return $url;
				}
			}
			break;

		case 'custom':
			if ( ! empty( $settings['redirect_url'] ) ) {
				return $settings['redirect_url'];
			}
			break;
	}

	// Default: WordPress login page (optionally returning to the request).
	return wp_login_url( $return_to );
}

/**
 * Is the "PDF.js Viewer" (PDFjs Viewer Shortcode) plugin active?
 *
 * Matches by folder slug so it works regardless of the plugin's main file
 * name, and also covers network-activated plugins on multisite.
 *
 * @return bool
 */
function maxsoft_rpdf_is_pdfjs_viewer_active() {
	$active  = false;
	$plugins = (array) get_option( 'active_plugins', array() );

	if ( is_multisite() ) {
		$plugins = array_merge( $plugins, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
	}

	foreach ( $plugins as $plugin ) {
		if ( 0 === strpos( $plugin, MAXSOFT_RPDF_PDFJS_SLUG . '/' ) ) {
			$active = true;
			break;
		}
	}

	/**
	 * Filter whether the PDF.js viewer is considered active.
	 *
	 * Allows protecting other PDF.js-based viewer plugins.
	 *
	 * @param bool $active Detected active state.
	 */
	return (bool) apply_filters( 'maxsoft_rpdf_pdfjs_viewer_active', $active );
}

/**
 * Is this request proxied through Cloudflare?
 *
 * Cloudflare adds the CF-Ray header to every request it proxies. Used only to
 * warn the admin that an edge/CDN cache can serve PDFs before WordPress runs.
 *
 * @return bool
 */
function maxsoft_rpdf_is_behind_cloudflare() {
	return ! empty( $_SERVER['HTTP_CF_RAY'] ) || ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] );
}

/* -------------------------------------------------------------------------
 * .htaccess management
 * ---------------------------------------------------------------------- */

/**
 * Build the rewrite rules for the enabled protections.
 *
 * Protected requests are routed to WordPress (index.php), where the
 * template_redirect handler does the real login check. Nothing is served by a
 * directly-bootstrapped PHP file, which keeps this WordPress.org compliant.
 *
 * Note on the viewer rule: mod_rewrite can only look at the raw cookie
 * header, so it cannot verify that a session cookie is genuine. The viewer
 * rule is therefore a convenience layer, not a security boundary -- the real
 * protection is the media rule, which routes every PDF through WordPress and
 * checks is_user_logged_in() in PHP. See the FAQ in readme.txt.
 *
 * @return array Lines for insert_with_markers() (empty = remove the block).
 */
function maxsoft_rpdf_htaccess_rules() {
	$settings = maxsoft_rpdf_get_settings();

	$do_media  = ! empty( $settings['restrict_media'] );
	$do_viewer = ! empty( $settings['restrict_viewer'] ) && maxsoft_rpdf_is_pdfjs_viewer_active();

	if ( ! $do_media && ! $do_viewer ) {
		return array();
	}

	$home_url      = home_url();
	$upload        = wp_upload_dir();
	$uploads_rel   = ltrim( str_replace( $home_url, '', $upload['baseurl'] ), '/' );
	$home_path_uri = rtrim( (string) wp_parse_url( $home_url, PHP_URL_PATH ), '/' );
	$index         = $home_path_uri . '/index.php';

	$rules   = array();
	$rules[] = '<IfModule mod_rewrite.c>';
	$rules[] = 'RewriteEngine On';

	if ( $do_viewer ) {
		$rules[] = '';
		$rules[] = '# Send logged-out visitors who open the PDF.js viewer to WordPress,';
		$rules[] = '# which redirects them to the configured page. The REDIRECT_maxsoft_rpdf';
		$rules[] = '# guard prevents an internal-rewrite loop. The cookie test only checks';
		$rules[] = '# for a well-formed session cookie name -- the PDF itself stays';
		$rules[] = '# protected by the media rule below, which validates the session in PHP.';
		$rules[] = 'RewriteCond %{ENV:REDIRECT_maxsoft_rpdf} !=1';
		$rules[] = 'RewriteCond %{HTTP_COOKIE} !wordpress_logged_in_[0-9a-f]{32}= [NC]';
		$rules[] = 'RewriteCond %{REQUEST_URI} /pdfjs/web/viewer\.(php|html)$ [NC]';
		$rules[] = 'RewriteRule .* ' . $index . '?maxsoft_rpdf_block=viewer [QSA,L,E=maxsoft_rpdf:1]';
	}

	if ( $do_media ) {
		$rules[] = '';
		$rules[] = '# Route uploaded PDFs through WordPress for a login check.';
		$rules[] = 'RewriteRule ^' . $uploads_rel . '/(.+\.pdf)$ ' . $index . '?maxsoft_rpdf_serve=$1 [QSA,L,NC]';
	}

	$rules[] = '</IfModule>';

	return $rules;
}

/**
 * Path to the site's root .htaccess.
 *
 * @return string
 */
function maxsoft_rpdf_htaccess_path() {
	if ( ! function_exists( 'get_home_path' ) ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
	}
	return get_home_path() . '.htaccess';
}

/**
 * Can we manage the site's .htaccess?
 *
 * @return bool
 */
function maxsoft_rpdf_htaccess_is_writable() {
	$htaccess = maxsoft_rpdf_htaccess_path();

	if ( file_exists( $htaccess ) ) {
		return is_writable( $htaccess );
	}

	return is_writable( dirname( $htaccess ) );
}

/**
 * Write (or refresh) the plugin's block in .htaccess.
 *
 * @return bool True on success.
 */
function maxsoft_rpdf_update_htaccess() {
	if ( ! function_exists( 'insert_with_markers' ) ) {
		require_once ABSPATH . 'wp-admin/includes/misc.php';
	}

	// Only manage .htaccess where it is writable.
	if ( ! maxsoft_rpdf_htaccess_is_writable() ) {
		return false;
	}

	return insert_with_markers( maxsoft_rpdf_htaccess_path(), MAXSOFT_RPDF_MARKER, maxsoft_rpdf_htaccess_rules() );
}

/**
 * Remove the plugin's block from .htaccess.
 *
 * @param string $marker Optional marker to remove. Defaults to the current one.
 */
function maxsoft_rpdf_remove_htaccess( $marker = MAXSOFT_RPDF_MARKER ) {
	if ( ! function_exists( 'insert_with_markers' ) ) {
		require_once ABSPATH . 'wp-admin/includes/misc.php';
	}

	$htaccess = maxsoft_rpdf_htaccess_path();

	if ( ! file_exists( $htaccess ) || ! is_writable( $htaccess ) ) {
		return;
	}

	// insert_with_markers() APPENDS an empty "# BEGIN / # END" pair when the
	// marker is not already in the file, so asking it to clear a block that
	// isn't there would create one. No content between the markers means
	// there is nothing to remove either way.
	if ( array() === extract_from_markers( $htaccess, $marker ) ) {
		return;
	}

	insert_with_markers( $htaccess, $marker, array() );
}

/* -------------------------------------------------------------------------
 * Activation / deactivation / upgrade
 * ---------------------------------------------------------------------- */

register_activation_hook( __FILE__, 'maxsoft_rpdf_activate' );
/**
 * On activation: migrate anything left by an older version, seed defaults and
 * write the rewrite rules.
 */
function maxsoft_rpdf_activate() {
	maxsoft_rpdf_migrate_legacy();

	if ( false === get_option( MAXSOFT_RPDF_OPTION ) ) {
		add_option( MAXSOFT_RPDF_OPTION, maxsoft_rpdf_default_settings() );
	}

	update_option( 'maxsoft_rpdf_version', MAXSOFT_RPDF_VERSION );
	maxsoft_rpdf_update_htaccess();
}

register_deactivation_hook( __FILE__, 'maxsoft_rpdf_deactivate' );
/**
 * On deactivation: remove the rules so PDFs are reachable again.
 */
function maxsoft_rpdf_deactivate() {
	maxsoft_rpdf_remove_htaccess();
}

/**
 * Carry settings and rewrite rules over from releases before 1.2.0, which used
 * the shorter "rpdf_" prefix and a different .htaccess marker.
 */
function maxsoft_rpdf_migrate_legacy() {
	$legacy = get_option( 'rpdf_settings' );

	if ( is_array( $legacy ) && false === get_option( MAXSOFT_RPDF_OPTION ) ) {
		add_option( MAXSOFT_RPDF_OPTION, wp_parse_args( $legacy, maxsoft_rpdf_default_settings() ) );
	}

	if ( false !== $legacy ) {
		delete_option( 'rpdf_settings' );
	}

	// The old block used a different marker, so it would otherwise be orphaned.
	maxsoft_rpdf_remove_htaccess( 'Restrict PDF' );
}

add_action( 'admin_init', 'maxsoft_rpdf_maybe_upgrade' );
/**
 * Run the migration and refresh the rules after a plugin update.
 */
function maxsoft_rpdf_maybe_upgrade() {
	if ( get_option( 'maxsoft_rpdf_version' ) === MAXSOFT_RPDF_VERSION ) {
		return;
	}

	maxsoft_rpdf_migrate_legacy();
	maxsoft_rpdf_update_htaccess();
	update_option( 'maxsoft_rpdf_version', MAXSOFT_RPDF_VERSION );
}

// Refresh rules when our settings change.
add_action( 'update_option_' . MAXSOFT_RPDF_OPTION, 'maxsoft_rpdf_update_htaccess' );
add_action( 'add_option_' . MAXSOFT_RPDF_OPTION, 'maxsoft_rpdf_update_htaccess' );

// Keep the viewer rule in sync when the PDF.js Viewer plugin is toggled.
add_action( 'activated_plugin', 'maxsoft_rpdf_on_plugin_state_change' );
add_action( 'deactivated_plugin', 'maxsoft_rpdf_on_plugin_state_change' );
/**
 * Refresh .htaccess when the PDF.js Viewer plugin is (de)activated.
 *
 * @param string $plugin Plugin path that changed state.
 */
function maxsoft_rpdf_on_plugin_state_change( $plugin ) {
	if ( 0 === strpos( (string) $plugin, MAXSOFT_RPDF_PDFJS_SLUG . '/' ) ) {
		maxsoft_rpdf_update_htaccess();
	}
}

/* -------------------------------------------------------------------------
 * Request handling (runs inside a normal WordPress front-end request)
 * ---------------------------------------------------------------------- */

add_action( 'template_redirect', 'maxsoft_rpdf_handle_request', 0 );
/**
 * Handle the requests our .htaccess routes to index.php.
 */
function maxsoft_rpdf_handle_request() {
	// PDF.js viewer block: .htaccess only routes logged-out visitors here.
	if ( isset( $_GET['maxsoft_rpdf_block'] ) && 'viewer' === sanitize_key( wp_unslash( $_GET['maxsoft_rpdf_block'] ) ) ) {
		if ( ! is_user_logged_in() ) {
			maxsoft_rpdf_redirect_visitor( maxsoft_rpdf_current_url() );
		}
		return; // Logged in (only via a hand-crafted URL): don't interfere.
	}

	// PDF file request.
	if ( ! isset( $_GET['maxsoft_rpdf_serve'] ) ) {
		return;
	}

	$requested = urldecode( wp_unslash( $_GET['maxsoft_rpdf_serve'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated against the uploads dir below.

	// Hard stop on bad input / traversal attempts.
	if ( '' === $requested || false !== strpos( $requested, '..' ) || false !== strpos( $requested, "\0" ) ) {
		status_header( 400 );
		exit;
	}

	// Real login check.
	if ( ! is_user_logged_in() ) {
		maxsoft_rpdf_redirect_visitor( maxsoft_rpdf_current_url() );
	}

	$upload  = wp_upload_dir();
	$basedir = wp_normalize_path( realpath( $upload['basedir'] ) );
	$path    = wp_normalize_path( realpath( $upload['basedir'] . '/' . ltrim( $requested, '/' ) ) );

	// Must exist, be a .pdf, and live inside the uploads directory.
	if (
		false === $basedir
		|| false === $path
		|| 0 !== strpos( $path, $basedir . '/' )
		|| 'pdf' !== strtolower( pathinfo( $path, PATHINFO_EXTENSION ) )
		|| ! is_file( $path )
	) {
		status_header( 404 );
		exit;
	}

	maxsoft_rpdf_stream_file( $path );
}

/**
 * Absolute URL of the current request (used as the after-login return URL).
 *
 * The scheme and host come from home_url() rather than the Host header, so a
 * spoofed header cannot steer the post-login redirect off-site. REQUEST_URI
 * keeps the original path even after an internal rewrite, so we don't prepend
 * home_url()'s path (that would double it on subdirectory installs).
 *
 * @return string
 */
function maxsoft_rpdf_current_url() {
	$home = wp_parse_url( home_url() );

	if ( empty( $home['host'] ) ) {
		return home_url( '/' );
	}

	$base = ( empty( $home['scheme'] ) ? 'http' : $home['scheme'] ) . '://' . $home['host'];

	if ( ! empty( $home['port'] ) ) {
		$base .= ':' . (int) $home['port'];
	}

	$uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- passed through esc_url_raw() below.

	return esc_url_raw( $base . '/' . ltrim( $uri, '/' ) );
}

/**
 * Redirect a blocked visitor to the configured target and stop.
 *
 * @param string $return_to Optional URL to return to after login.
 */
function maxsoft_rpdf_redirect_visitor( $return_to = '' ) {
	nocache_headers();
	// wp_safe_redirect is used; an admin-configured external custom URL is
	// permitted via the allowed_redirect_hosts filter below.
	wp_safe_redirect( maxsoft_rpdf_get_redirect_url( $return_to ), 302 );
	exit;
}

add_filter( 'allowed_redirect_hosts', 'maxsoft_rpdf_allowed_redirect_hosts' );
/**
 * Allow the admin-configured custom redirect host through wp_safe_redirect.
 *
 * @param string[] $hosts Allowed hosts.
 * @return string[]
 */
function maxsoft_rpdf_allowed_redirect_hosts( $hosts ) {
	$settings = maxsoft_rpdf_get_settings();

	if ( 'custom' === $settings['redirect_type'] && ! empty( $settings['redirect_url'] ) ) {
		$host = wp_parse_url( $settings['redirect_url'], PHP_URL_HOST );
		if ( $host ) {
			$hosts[] = $host;
		}
	}

	return $hosts;
}

/**
 * Stream a file to the browser with HTTP byte-range support.
 *
 * @param string $path Absolute, validated file path.
 */
function maxsoft_rpdf_stream_file( $path ) {
	// Drop any buffering so we can stream cleanly.
	while ( ob_get_level() > 0 ) {
		ob_end_clean();
	}

	// Don't let a large download hit the PHP time limit.
	if ( function_exists( 'set_time_limit' ) ) {
		@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	}

	$size = filesize( $path );

	if ( false === $size ) {
		status_header( 500 );
		exit;
	}

	$start = 0;
	$end   = $size - 1;

	header( 'Content-Type: application/pdf' );
	header( 'Accept-Ranges: bytes' );
	header( 'Content-Disposition: inline; filename="' . rawurlencode( basename( $path ) ) . '"' );
	header( 'X-Content-Type-Options: nosniff' );
	// Keep protected files out of search engines.
	header( 'X-Robots-Tag: noindex, nofollow', true );
	// Private cache only -- never let a shared/proxy cache hold protected files.
	header( 'Cache-Control: private, max-age=0, must-revalidate' );

	$range = isset( $_SERVER['HTTP_RANGE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_RANGE'] ) ) : '';

	// Honour a single "bytes=start-end" range. Multipart ranges (comma
	// separated) are not supported, so those fall back to the full file.
	if ( '' !== $range && false === strpos( $range, ',' ) && preg_match( '/bytes=\s*(\d*)-(\d*)\s*$/i', $range, $m ) ) {
		$r_start = ( '' === $m[1] ) ? null : (int) $m[1];
		$r_end   = ( '' === $m[2] ) ? null : (int) $m[2];

		if ( null === $r_start ) {
			// Suffix range: last N bytes.
			if ( null === $r_end || 0 === $r_end ) {
				header( 'Content-Range: bytes */' . $size );
				status_header( 416 );
				exit;
			}
			$start = max( 0, $size - $r_end );
		} else {
			$start = $r_start;
			$end   = ( null === $r_end ) ? $size - 1 : min( $r_end, $size - 1 );
		}

		if ( $start > $end || $start >= $size ) {
			header( 'Content-Range: bytes */' . $size );
			status_header( 416 );
			exit;
		}

		status_header( 206 );
		header( 'Content-Range: bytes ' . $start . '-' . $end . '/' . $size );
	}

	header( 'Content-Length: ' . ( $end - $start + 1 ) );

	// HEAD request: headers only.
	if ( isset( $_SERVER['REQUEST_METHOD'] ) && 'HEAD' === strtoupper( sanitize_key( $_SERVER['REQUEST_METHOD'] ) ) ) {
		exit;
	}

	// Direct file handles are required to stream large files in chunks;
	// WP_Filesystem reads whole files into memory and cannot stream.
	// phpcs:disable WordPress.WP.AlternativeFunctions
	$fp = fopen( $path, 'rb' );
	if ( false === $fp ) {
		status_header( 500 );
		exit;
	}

	if ( $start > 0 ) {
		fseek( $fp, $start );
	}

	$remaining = $end - $start + 1;
	$chunk     = 8192;

	while ( $remaining > 0 && ! feof( $fp ) ) {
		$read = ( $remaining > $chunk ) ? $chunk : $remaining;
		echo fread( $fp, $read ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- raw binary file stream.
		flush();
		$remaining -= $read;
	}

	fclose( $fp );
	// phpcs:enable WordPress.WP.AlternativeFunctions
	exit;
}

/* -------------------------------------------------------------------------
 * Admin: settings page
 * ---------------------------------------------------------------------- */

add_action( 'admin_menu', 'maxsoft_rpdf_admin_menu' );
/**
 * Register the settings page under Settings.
 */
function maxsoft_rpdf_admin_menu() {
	add_options_page(
		__( 'MaXsoft Restrict PDF Access', 'maxsoft-restrict-pdf-access' ),
		__( 'Restrict PDF Access', 'maxsoft-restrict-pdf-access' ),
		'manage_options',
		'maxsoft-restrict-pdf-access',
		'maxsoft_rpdf_settings_page'
	);
}

add_action( 'admin_init', 'maxsoft_rpdf_register_settings' );
/**
 * Register the settings group.
 */
function maxsoft_rpdf_register_settings() {
	register_setting(
		'maxsoft_rpdf_settings_group',
		MAXSOFT_RPDF_OPTION,
		array(
			'type'              => 'array',
			'sanitize_callback' => 'maxsoft_rpdf_sanitize_settings',
			'default'           => maxsoft_rpdf_default_settings(),
		)
	);
}

/**
 * Sanitize submitted settings.
 *
 * Do NOT save here: this runs on the sanitize_option_{$option} filter, so
 * calling update_option() would recurse. The .htaccess is refreshed by the
 * update_option/add_option hooks and by the settings page after a save.
 *
 * @param array $input Raw input.
 * @return array
 */
function maxsoft_rpdf_sanitize_settings( $input ) {
	$clean = maxsoft_rpdf_default_settings();

	if ( ! is_array( $input ) ) {
		return $clean;
	}

	$clean['restrict_media']  = empty( $input['restrict_media'] ) ? 0 : 1;
	$clean['restrict_viewer'] = empty( $input['restrict_viewer'] ) ? 0 : 1;

	$type = isset( $input['redirect_type'] ) ? sanitize_key( $input['redirect_type'] ) : 'login';
	if ( ! in_array( $type, array( 'login', 'page', 'custom' ), true ) ) {
		$type = 'login';
	}
	$clean['redirect_type']    = $type;
	$clean['redirect_page_id'] = isset( $input['redirect_page_id'] ) ? absint( $input['redirect_page_id'] ) : 0;
	$clean['redirect_url']     = isset( $input['redirect_url'] ) ? esc_url_raw( trim( $input['redirect_url'] ) ) : '';

	return $clean;
}

add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'maxsoft_rpdf_action_links' );
/**
 * Add a "Settings" link on the Plugins screen.
 *
 * @param array $links Existing action links.
 * @return array
 */
function maxsoft_rpdf_action_links( $links ) {
	$url  = admin_url( 'options-general.php?page=maxsoft-restrict-pdf-access' );
	$link = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'maxsoft-restrict-pdf-access' ) . '</a>';
	array_unshift( $links, $link );
	return $links;
}

/**
 * Render the settings page.
 */
function maxsoft_rpdf_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	// Re-write the rules on every save, even when the value was unchanged (the
	// update_option hook skips that case), so this page can "repair" them.
	if ( isset( $_GET['settings-updated'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only flag set by the Settings API redirect.
		maxsoft_rpdf_update_htaccess();
	}

	$settings    = maxsoft_rpdf_get_settings();
	$type        = $settings['redirect_type'];
	$viewer_on   = maxsoft_rpdf_is_pdfjs_viewer_active();
	$htaccess    = maxsoft_rpdf_htaccess_path();
	$htaccess_ok = maxsoft_rpdf_htaccess_is_writable();
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'MaXsoft Restrict PDF Access', 'maxsoft-restrict-pdf-access' ); ?></h1>
		<p><?php esc_html_e( 'Keep PDFs available to logged-in users only. Choose what to restrict and where to send blocked visitors.', 'maxsoft-restrict-pdf-access' ); ?></p>

		<?php if ( ! $htaccess_ok ) : ?>
			<div class="notice notice-warning">
				<p>
					<strong><?php esc_html_e( 'Heads up:', 'maxsoft-restrict-pdf-access' ); ?></strong>
					<?php
					printf(
						/* translators: %s: .htaccess file path. */
						esc_html__( 'The file %s is not writable, so protection rules could not be written automatically. On Apache, make it writable and re-save. On Nginx, copy the rules from readme.txt into your server config.', 'maxsoft-restrict-pdf-access' ),
						'<code>' . esc_html( $htaccess ) . '</code>'
					);
					?>
				</p>
			</div>
		<?php endif; ?>

		<?php if ( maxsoft_rpdf_is_behind_cloudflare() ) : ?>
			<div class="notice notice-warning">
				<p>
					<strong><?php esc_html_e( 'Cloudflare detected.', 'maxsoft-restrict-pdf-access' ); ?></strong>
					<?php esc_html_e( 'Cloudflare caches PDF files at its edge by default and can serve them before WordPress runs, which bypasses this protection for direct media-library links. In your Cloudflare dashboard add a Cache Rule that sets "Bypass cache" when the URI Path ends with .pdf (and contains /wp-content/uploads/), then purge the cache. The PDF.js viewer URL is not affected by this.', 'maxsoft-restrict-pdf-access' ); ?>
				</p>
			</div>
		<?php endif; ?>

		<form method="post" action="options.php">
			<?php settings_fields( 'maxsoft_rpdf_settings_group' ); ?>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'What to restrict', 'maxsoft-restrict-pdf-access' ); ?></th>
					<td>
						<fieldset>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( MAXSOFT_RPDF_OPTION ); ?>[restrict_media]" value="1" <?php checked( ! empty( $settings['restrict_media'] ) ); ?> />
								<?php esc_html_e( 'Restrict direct PDF files in the media library', 'maxsoft-restrict-pdf-access' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Blocks direct links such as /wp-content/uploads/2026/02/file.pdf. Works whether or not a PDF viewer plugin is installed.', 'maxsoft-restrict-pdf-access' ); ?></p>
							<br />

							<label>
								<input type="checkbox" name="<?php echo esc_attr( MAXSOFT_RPDF_OPTION ); ?>[restrict_viewer]" value="1" <?php checked( ! empty( $settings['restrict_viewer'] ) ); ?> />
								<?php esc_html_e( 'Restrict the PDF.js Viewer full-screen URL', 'maxsoft-restrict-pdf-access' ); ?>
							</label>
							<p class="description">
								<?php esc_html_e( 'Applies only while the "PDF.js Viewer" plugin is active.', 'maxsoft-restrict-pdf-access' ); ?>
								<?php if ( $viewer_on ) : ?>
									<strong style="color:#008a20;"><?php esc_html_e( 'Detected: active.', 'maxsoft-restrict-pdf-access' ); ?></strong>
								<?php else : ?>
									<strong style="color:#b32d2e;"><?php esc_html_e( 'Not detected, so this rule is skipped until the plugin is active.', 'maxsoft-restrict-pdf-access' ); ?></strong>
								<?php endif; ?>
							</p>
							<p class="description">
								<?php esc_html_e( 'Treat this as a convenience layer rather than a lock: the web server can only see that a login cookie is present, not that it is valid. Keep the media-library option above enabled, because that is what actually protects the PDF file itself.', 'maxsoft-restrict-pdf-access' ); ?>
							</p>
						</fieldset>
					</td>
				</tr>

				<tr>
					<th scope="row"><?php esc_html_e( 'Where to send blocked visitors', 'maxsoft-restrict-pdf-access' ); ?></th>
					<td>
						<fieldset>
							<label>
								<input type="radio" name="<?php echo esc_attr( MAXSOFT_RPDF_OPTION ); ?>[redirect_type]" value="login" <?php checked( $type, 'login' ); ?> />
								<?php esc_html_e( 'WordPress login page (default)', 'maxsoft-restrict-pdf-access' ); ?>
							</label><br />

							<label>
								<input type="radio" name="<?php echo esc_attr( MAXSOFT_RPDF_OPTION ); ?>[redirect_type]" value="page" <?php checked( $type, 'page' ); ?> />
								<?php esc_html_e( 'A specific page:', 'maxsoft-restrict-pdf-access' ); ?>
							</label>
							<?php
							wp_dropdown_pages(
								array(
									'name'              => MAXSOFT_RPDF_OPTION . '[redirect_page_id]',
									'selected'          => (int) $settings['redirect_page_id'],
									'show_option_none'  => esc_html__( 'Select a page', 'maxsoft-restrict-pdf-access' ),
									'option_none_value' => 0,
								)
							);
							?>
							<br />

							<label>
								<input type="radio" name="<?php echo esc_attr( MAXSOFT_RPDF_OPTION ); ?>[redirect_type]" value="custom" <?php checked( $type, 'custom' ); ?> />
								<?php esc_html_e( 'A custom URL:', 'maxsoft-restrict-pdf-access' ); ?>
							</label>
							<input type="url" class="regular-text" placeholder="https://example.com/members"
								name="<?php echo esc_attr( MAXSOFT_RPDF_OPTION ); ?>[redirect_url]"
								value="<?php echo esc_attr( $settings['redirect_url'] ); ?>" />
						</fieldset>
					</td>
				</tr>
			</table>

			<?php submit_button(); ?>
		</form>
	</div>
	<?php
}
