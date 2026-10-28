<?php
/**
 * Runs when the plugin is deleted from the Plugins screen: removes tables, options, files and capabilities.
 *
 * @package NodeThemes_Analytics_AI
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;
foreach ( array( 'visits', 'pageviews', 'events', 'conversions', 'orders', 'order_items', 'archive', 'archive_days', 'archive_seg', 'queue', 'bots', 'annotations', 'ai_reports' ) as $ntaa_t ) {
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}ntaa_{$ntaa_t}" ); // phpcs:ignore WordPress.DB
}
foreach ( array( 'ntaa_settings', 'ntaa_db_version', 'ntaa_daily_salt', 'ntaa_runtime', 'ntaa_secret', 'ntaa_goals', 'ntaa_segments', 'ntaa_geoip', 'ntaa_first_day', 'ntaa_queue_backlog', 'ntaa_report_backfill' ) as $ntaa_o ) {
	delete_option( $ntaa_o );
}
delete_site_transient( 'ntaa_free_update' );
foreach ( array( 'ntaa_daily_maintenance', 'ntaa_hourly', 'ntaa_minutely', 'ntaa_geoip_update', 'ntaa_process_queue', 'ntaa_segment_backfill', 'ntaa_report_backfill' ) as $ntaa_h ) {
	wp_clear_scheduled_hook( $ntaa_h );
}

// GeoIP database files.
$ntaa_up  = wp_upload_dir( null, false );
$ntaa_dir = trailingslashit( $ntaa_up['basedir'] ) . 'ntaa-geoip';
if ( is_dir( $ntaa_dir ) ) {
	foreach ( (array) glob( $ntaa_dir . '/{,.}*', GLOB_BRACE ) as $ntaa_f ) {
		if ( is_file( $ntaa_f ) ) {
			wp_delete_file( $ntaa_f );
		}
	}
	@rmdir( $ntaa_dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
}

// Order meta written by the WooCommerce integration (HPOS and classic tables).
$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key IN ('_ntaa_visit_id', '_ntaa_recorded')" ); // phpcs:ignore WordPress.DB
if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'wc_orders_meta' ) ) ) { // phpcs:ignore WordPress.DB
	$wpdb->query( "DELETE FROM {$wpdb->prefix}wc_orders_meta WHERE meta_key IN ('_ntaa_visit_id', '_ntaa_recorded')" ); // phpcs:ignore WordPress.DB
}

delete_metadata( 'user', 0, 'ntaa_dash_layout', '', true );

foreach ( wp_roles()->role_objects as $ntaa_role ) {
	$ntaa_role->remove_cap( 'view_ntaa_analytics' );
	$ntaa_role->remove_cap( 'use_ntaa_ai' );
}
