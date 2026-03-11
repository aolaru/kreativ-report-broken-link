<?php
/**
 * Uninstall: Kreativ Report Broken Link
 */
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'krbl_notify_email' );
delete_option( 'krbl_enabled_post_types' );
delete_option( 'krbl_retention_days' );
delete_option( 'krbl_anonymize_ip' );
delete_option( 'krbl_last_cleanup' );

// Keep reports table by default (safer). Uncomment to drop it.
// global $wpdb;
// $table = $wpdb->prefix . 'broken_link_reports';
// $wpdb->query( "DROP TABLE IF EXISTS {$table}" );
