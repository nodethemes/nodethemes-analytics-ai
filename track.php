<?php
/**
 * Fast tracking endpoint for busy sites (High-traffic mode).
 *
 * Loads only the WordPress core basics (SHORTINIT: database + options, no plugins or theme),
 * checks the request with the same rules as the normal endpoint and appends the hits to the
 * queue table. Cron stores them in batches. It is only used when the site owner switches on
 * "High-traffic mode" + "Fast endpoint" and the self-test succeeded.
 *
 * @package NodeThemes_Analytics_AI
 */

// phpcs:disable WordPress.Security, WordPress.WP.AlternativeFunctions, WordPress.DB

define( 'SHORTINIT', true );
define( 'DOING_NTAA_TRACK', true );

$ntaa_dir  = __DIR__;
$ntaa_load = '';
for ( $ntaa_i = 0; $ntaa_i < 6; $ntaa_i++ ) {
	$ntaa_dir = dirname( $ntaa_dir );
	if ( file_exists( $ntaa_dir . '/wp-load.php' ) ) {
		$ntaa_load = $ntaa_dir . '/wp-load.php';
		break;
	}
}
if ( '' === $ntaa_load && ! empty( $_SERVER['DOCUMENT_ROOT'] ) && file_exists( rtrim( $_SERVER['DOCUMENT_ROOT'], '/' ) . '/wp-load.php' ) ) {
	$ntaa_load = rtrim( $_SERVER['DOCUMENT_ROOT'], '/' ) . '/wp-load.php';
}
if ( '' === $ntaa_load ) {
	http_response_code( 404 );
	exit;
}
require $ntaa_load;

header( 'Cache-Control: no-store, no-cache, must-revalidate' );
header( 'X-Robots-Tag: noindex' );

if ( 'POST' !== ( isset( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : '' ) ) {
	http_response_code( 405 );
	exit;
}

$ntaa_rt = get_option( 'ntaa_runtime' );
if ( isset( $_GET['selftest'] ) ) {
	// Only proves that this file can start WordPress on this server (used before the option is switched on).
	echo is_array( $ntaa_rt ) ? 'NTAA-OK' : 'NTAA-NO-RUNTIME';
	exit;
}
if ( ! is_array( $ntaa_rt ) || empty( $ntaa_rt['queue'] ) || empty( $ntaa_rt['fast'] ) ) {
	http_response_code( 404 );
	exit;
}

// Protect the database from floods: stop accepting when the backlog is far too large.
if ( (int) get_option( 'ntaa_queue_backlog', 0 ) > (int) $ntaa_rt['max_queue'] ) {
	http_response_code( 503 );
	exit;
}

require __DIR__ . '/includes/class-ntaa-mmdb.php';
require __DIR__ . '/includes/class-ntaa-ua.php';
require __DIR__ . '/includes/class-ntaa-hit.php';

$ntaa_body = file_get_contents( 'php://input', false, null, 0, 2097153 );
$ntaa_hits = NTAA_Hit::receive( (string) $ntaa_body, $_SERVER, $ntaa_rt, $_COOKIE );

if ( $ntaa_hits ) {
	global $wpdb;
	$ntaa_now    = gmdate( 'Y-m-d H:i:s', time() + (int) round( ( new DateTime( 'now', new DateTimeZone( $ntaa_rt['tz'] ? $ntaa_rt['tz'] : 'UTC' ) ) )->getOffset() ) );
	$ntaa_values = array();
	foreach ( $ntaa_hits as $ntaa_hit ) {
		$ntaa_values[] = $wpdb->prepare( '(%s,%s)', $ntaa_now, json_encode( $ntaa_hit ) );
	}
	$wpdb->query( 'INSERT INTO `' . preg_replace( '/[^A-Za-z0-9_]/', '', $ntaa_rt['table'] ) . '` (received_at, payload) VALUES ' . implode( ',', $ntaa_values ) );
}

http_response_code( 204 );
exit;
