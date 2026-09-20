<?php
/**
 * Uninstall cleanup for MaXsoft Restrict PDF Access.
 *
 * @package MaxsoftRestrictPdfAccess
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Remove every option this plugin has ever stored, including the ones used by
 * releases before 1.2.0.
 */
function maxsoft_rpdf_uninstall_options() {
	delete_option( 'maxsoft_rpdf_settings' );
	delete_option( 'maxsoft_rpdf_version' );
	delete_option( 'rpdf_settings' );
}

// Remove the .htaccess block so PDFs are reachable again. Both the current
// marker and the one used before 1.2.0 are cleared.
if ( ! function_exists( 'get_home_path' ) ) {
	require_once ABSPATH . 'wp-admin/includes/file.php';
}
if ( ! function_exists( 'insert_with_markers' ) ) {
	require_once ABSPATH . 'wp-admin/includes/misc.php';
}

$maxsoft_rpdf_htaccess = get_home_path() . '.htaccess';

if ( file_exists( $maxsoft_rpdf_htaccess ) && is_writable( $maxsoft_rpdf_htaccess ) ) {
	insert_with_markers( $maxsoft_rpdf_htaccess, 'MaXsoft Restrict PDF Access', array() );
	insert_with_markers( $maxsoft_rpdf_htaccess, 'Restrict PDF', array() );
}

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $maxsoft_rpdf_site_id ) {
		switch_to_blog( (int) $maxsoft_rpdf_site_id );
		maxsoft_rpdf_uninstall_options();
		restore_current_blog();
	}
} else {
	maxsoft_rpdf_uninstall_options();
}
