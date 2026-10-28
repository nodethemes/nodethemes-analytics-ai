<?php
/**
 * Plugin Name:       NodeThemes Analytics AI - Privacy-First Analytics with AI Insights
 * Plugin URI:        https://nodethemes.com/
 * Description:       Matomo-style website analytics inside WordPress: visitors, pages, sources, campaigns, AI-assistant referrals, devices, GeoIP locations, real-time, visits log, goals, WooCommerce revenue, segments, Web Vitals and more. Cookieless, GDPR-friendly and built for busy sites. Bring your own OpenAI / Claude / Gemini API key for AI summaries.
 * Version:           2.1.1
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            NodeThemes
 * Author URI:        https://nodethemes.com/
 * License:           GPL-2.0-or-later
 * Text Domain:       nodethemes-analytics-ai
 * Update URI:        https://nodethemes.com/nodethemes-analytics-ai
 *
 * @package NodeThemes_Analytics_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( defined( 'NTAA_VERSION' ) ) {
	return;
}

define( 'NTAA_VERSION', '2.1.1' );
define( 'NTAA_DB_VERSION', '2.2' );
define( 'NTAA_FILE', __FILE__ );
define( 'NTAA_DIR', plugin_dir_path( __FILE__ ) );
define( 'NTAA_URL', plugin_dir_url( __FILE__ ) );
define( 'NTAA_CAP', 'view_ntaa_analytics' );
define( 'NTAA_CAP_AI', 'use_ntaa_ai' );

foreach ( array( 'settings', 'pro-gate', 'mmdb', 'ua', 'hit', 'geoip', 'db', 'segments', 'goals', 'archive', 'stats', 'tracker', 'ecommerce', 'visitors', 'privacy', 'ai', 'rest', 'cron', 'admin' ) as $ntaa_f ) {
	require_once NTAA_DIR . 'includes/class-ntaa-' . $ntaa_f . '.php';
}
unset( $ntaa_f );

// Free updates from nodethemes.com.
require_once NTAA_DIR . 'includes/updater.php';
NTAA_Free_Updater::boot( NTAA_FILE, NTAA_VERSION, 'https://nodethemes.com/' );

register_activation_hook( __FILE__, array( 'NTAA_DB', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'NTAA_DB', 'deactivate' ) );

add_action(
	'plugins_loaded',
	function () {
		NTAA_DB::maybe_upgrade();
		NTAA_GeoIP::init();
		NTAA_Tracker::init();
		NTAA_Ecommerce::init();
		NTAA_Visitors::init();
		NTAA_Privacy::init();
		NTAA_Rest::init();
		NTAA_Cron::init();
		if ( is_admin() ) {
			NTAA_Admin::init();
		}
		do_action( 'ntaa_loaded' ); // The Pro add-on hooks in here.
	}
);

/**
 * Can the current user view analytics?
 *
 * @return bool
 */
function ntaa_user_can_view() {
	return current_user_can( NTAA_CAP ) || current_user_can( 'manage_options' );
}

/**
 * Can the current user view a given site (Pro: per-site access)?
 *
 * @param int $site Site id.
 * @return bool
 */
function ntaa_user_can_view_site( $site ) {
	return (bool) apply_filters( 'ntaa_user_can_view_site', ntaa_user_can_view(), (int) $site );
}

/**
 * Record an event for the current visitor from PHP, e.g. ntaa_track_event( 'Newsletter', 'signup', 'footer' ).
 *
 * @param string     $category Category.
 * @param string     $action   Action.
 * @param string     $label    Label.
 * @param float|null $value    Value.
 * @return bool
 */
function ntaa_track_event( $category, $action, $label = '', $value = null ) {
	return NTAA_Tracker::server_event( $category, $action, $label, $value );
}

/**
 * Trigger a manual goal for the current visitor from PHP.
 *
 * @param int        $goal_id Goal id.
 * @param float|null $value   Value.
 * @return bool
 */
function ntaa_track_goal( $goal_id, $value = null ) {
	$visit = NTAA_Tracker::current_visit();
	if ( ! $visit ) {
		return false;
	}
	NTAA_Goals::manual( $visit, (int) $goal_id, $value, time() );
	return true;
}

/**
 * NodeThemes support contacts.
 *
 * @return array
 */
function ntaa_support() {
	return array(
		'site'         => 'https://nodethemes.com',
		'email'        => 'nodethemes.com@gmail.com',
		'whatsapp'     => '+44 7441 429969',
		'whatsapp_url' => 'https://wa.me/447441429969',
		'telegram'     => 'https://t.me/nodethemes',
	);
}
