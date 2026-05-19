<?php
/**
 * Uninstall handler for smoxy Proxy.
 *
 * Runs when the plugin is deleted from the Plugins screen. Removes options
 * and transients created by the plugin so no residue is left behind.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'smoxy_settings' );
delete_option( 'smoxy_connection_status' );

global $wpdb;

$smoxy_transient_like = $wpdb->esc_like( '_transient_smoxy_purge_notice_' ) . '%';
$smoxy_timeout_like   = $wpdb->esc_like( '_transient_timeout_smoxy_purge_notice_' ) . '%';

// Iterate transients first so persistent object caches get a chance to invalidate before the rows are removed.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall sweep; no cache layer to consult for option_name lookups.
$smoxy_rows = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $smoxy_transient_like ) );
foreach ( (array) $smoxy_rows as $smoxy_option_name ) {
	$smoxy_name = preg_replace( '/^_transient_/', '', $smoxy_option_name );
	delete_transient( $smoxy_name );
}

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Belt-and-braces sweep of any orphaned rows (e.g. timeouts) the loop above did not clear.
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", $smoxy_transient_like, $smoxy_timeout_like ) );
