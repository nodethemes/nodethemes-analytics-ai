<?php
/**
 * Free/Pro split. The Pro code lives in the separate "NodeThemes Analytics AI Pro" add-on.
 * The free plugin only knows the names of the Pro features so it can show them as locked.
 *
 * @package NodeThemes_Analytics_AI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Pro gate.
 */
final class NTAA_Pro {

	const UPGRADE_URL = 'https://nodethemes.com/product/nodethemes-analytics-ai-pro/';

	/**
	 * Is the Pro add-on installed and active? (it announces itself through this filter)
	 *
	 * @return bool
	 */
	public static function has_code() {
		return (bool) apply_filters( 'ntaa_pro_installed', false );
	}

	/**
	 * Installed AND licensed: the add-on answers this filter only while its license allows Pro features.
	 *
	 * @return bool
	 */
	public static function active() {
		return (bool) apply_filters( 'ntaa_is_pro', false );
	}

	/**
	 * License page of the add-on.
	 *
	 * @return string
	 */
	public static function license_url() {
		return admin_url( 'admin.php?page=ntaa-license' );
	}

	/**
	 * Where a locked button leads.
	 *
	 * @param string $feature Feature id for the UTM campaign.
	 * @return string
	 */
	public static function url( $feature = '' ) {
		if ( self::has_code() && ! self::active() ) {
			return self::license_url();
		}
		return add_query_arg(
			array(
				'utm_source'   => 'plugin',
				'utm_medium'   => 'lock',
				'utm_campaign' => $feature,
			),
			self::UPGRADE_URL
		);
	}

	/**
	 * Pro AI reports, shown locked in the free plugin (labels only – the prompts live in the add-on).
	 *
	 * @return array
	 */
	public static function locked_reports() {
		return array(
			'overview' => array( 'label' => __( 'Full performance report', 'nodethemes-analytics-ai' ), 'icon' => 'dashboard', 'desc' => __( 'Executive summary and a prioritized action plan', 'nodethemes-analytics-ai' ) ),
			'seo'      => array( 'label' => __( 'SEO & content strategy', 'nodethemes-analytics-ai' ), 'icon' => 'search', 'desc' => __( 'Content gaps, article ideas and internal links', 'nodethemes-analytics-ai' ) ),
			'traffic'  => array( 'label' => __( 'Traffic sources & campaigns', 'nodethemes-analytics-ai' ), 'icon' => 'share', 'desc' => __( 'Channel quality, referrers and UTM campaigns', 'nodethemes-analytics-ai' ) ),
			'ux'       => array( 'label' => __( 'UX & engagement', 'nodethemes-analytics-ai' ), 'icon' => 'admin-users', 'desc' => __( 'Friction points, bounce, speed and conversion', 'nodethemes-analytics-ai' ) ),
			'anomaly'  => array( 'label' => __( 'Anomaly detection', 'nodethemes-analytics-ai' ), 'icon' => 'warning', 'desc' => __( 'Spikes, drops, spam and unusual behaviour', 'nodethemes-analytics-ai' ) ),
			'growth'   => array( 'label' => __( 'Forecast & growth plan', 'nodethemes-analytics-ai' ), 'icon' => 'chart-line', 'desc' => __( 'Next-period forecast and a 30-day plan', 'nodethemes-analytics-ai' ) ),
		);
	}

	/**
	 * Feature list for upsell boxes.
	 *
	 * @return array
	 */
	public static function features() {
		return array(
			__( 'In-depth AI reports, chat with your data and AI-written email reports', 'nodethemes-analytics-ai' ),
			__( 'Funnels, including the WooCommerce checkout funnel', 'nodethemes-analytics-ai' ),
			__( 'Heatmaps and privacy-safe session recordings', 'nodethemes-analytics-ai' ),
			__( 'A/B tests with statistical significance', 'nodethemes-analytics-ai' ),
			__( 'Google Analytics 4 history import and Google Search Console keywords', 'nodethemes-analytics-ai' ),
			__( 'Form, media, JavaScript-error and content tracking', 'nodethemes-analytics-ai' ),
			__( 'Cohorts, users flow, multi-touch attribution and custom reports', 'nodethemes-analytics-ai' ),
			__( 'Page overlay, Bing keywords and an activity log', 'nodethemes-analytics-ai' ),
			__( 'Tag Manager, alerts, multiple websites with roll-ups, embeddable widgets and a reporting API (JSON, CSV, TSV, XML)', 'nodethemes-analytics-ai' ),
			__( 'Updates and priority support from NodeThemes', 'nodethemes-analytics-ai' ),
		);
	}

	/**
	 * PRO tag.
	 *
	 * @return string
	 */
	public static function badge() {
		return '<span class="ntaa-protag">PRO</span>';
	}

	/**
	 * Upsell box (settings page).
	 *
	 * @param string $title Title.
	 */
	public static function upsell_box( $title = '' ) {
		echo '<div class="ntaa-card ntaa-pro-box ntaa-pro-box--list"><div><h2>&#128274; ' . esc_html( $title ? $title : __( 'Pro feature', 'nodethemes-analytics-ai' ) ) . '</h2>';
		if ( self::has_code() ) {
			echo '<p>' . esc_html__( 'The Pro add-on is installed but its license is not active.', 'nodethemes-analytics-ai' ) . '</p>';
			echo '<p><a class="button button-primary" href="' . esc_url( self::license_url() ) . '">' . esc_html__( 'Activate license', 'nodethemes-analytics-ai' ) . '</a></p></div></div>';
			return;
		}
		echo '<p>' . esc_html__( 'Included in NodeThemes Analytics AI Pro:', 'nodethemes-analytics-ai' ) . '</p><ul class="ntaa-checks">';
		foreach ( self::features() as $f ) {
			echo '<li>' . esc_html( $f ) . '</li>';
		}
		echo '</ul><p><a class="button button-primary" target="_blank" rel="noopener" href="' . esc_url( self::url( 'settings' ) ) . '">' . esc_html__( 'Get Pro', 'nodethemes-analytics-ai' ) . '</a></p>';
		echo '<p class="description">' . esc_html__( 'Already bought Pro? Install the “NodeThemes Analytics AI Pro” add-on, then enter your license key under Analytics AI → License.', 'nodethemes-analytics-ai' ) . '</p></div></div>';
	}
}
