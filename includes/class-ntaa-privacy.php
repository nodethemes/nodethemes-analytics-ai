<?php
/**
 * Visitor opt-out ([ntaa_optout] shortcode) and privacy-policy text.
 *
 * @package NodeThemes_Analytics_AI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Privacy.
 */
class NTAA_Privacy {

	/**
	 * Boot.
	 */
	public static function init() {
		add_shortcode( 'ntaa_optout', array( __CLASS__, 'optout' ) );
		add_action( 'admin_init', array( __CLASS__, 'policy_text' ) );
	}

	/**
	 * Opt-out checkbox for the privacy policy page.
	 *
	 * @param array $atts Attributes.
	 * @return string
	 */
	public static function optout( $atts ) {
		$a  = shortcode_atts(
			array(
				'tracked'     => __( 'Your visit is counted anonymously to improve this website. Untick the box to opt out.', 'nodethemes-analytics-ai' ),
				'not_tracked' => __( 'You have opted out: your visits are not counted. Tick the box to opt in again.', 'nodethemes-analytics-ai' ),
				'label'       => __( 'Count my visits anonymously', 'nodethemes-analytics-ai' ),
			),
			$atts,
			'ntaa_optout'
		);
		wp_enqueue_script( 'ntaa-optout', NTAA_URL . 'assets/js/optout.js', array(), NTAA_VERSION, true );
		ob_start();
		?>
		<div class="ntaa-optout" data-tracked="<?php echo esc_attr( $a['tracked'] ); ?>" data-not-tracked="<?php echo esc_attr( $a['not_tracked'] ); ?>" style="border:1px solid #ddd;border-radius:8px;padding:12px 14px;margin:1em 0">
			<p class="ntaa-optout-msg" style="margin:0 0 8px"></p>
			<label style="display:flex;gap:8px;align-items:center;cursor:pointer"><input type="checkbox"> <span><?php echo esc_html( $a['label'] ); ?></span></label>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Suggested privacy-policy text (Settings › Privacy › Policy guide).
	 */
	public static function policy_text() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		$cookieless = (int) NTAA_Settings::get( 'cookieless' );
		$text       = '<p>' . esc_html__( 'We use NodeThemes Analytics AI, a self-hosted analytics tool that runs on our own website, to count visits and understand how our pages are used. The data stays on our server and is not shared with third parties.', 'nodethemes-analytics-ai' ) . '</p>';
		$text      .= '<p>' . ( $cookieless
			? esc_html__( 'No cookies are used for analytics. Visitors are recognised for one day only with an anonymous code that cannot be turned back into an IP address. IP addresses are never stored.', 'nodethemes-analytics-ai' )
			: esc_html__( 'A random visitor ID is stored in your browser (localStorage and a first-party cookie named ntaa_vid) to recognise returning visits. IP addresses are never stored.', 'nodethemes-analytics-ai' ) ) . '</p>';
		$text      .= '<p>' . esc_html__( 'We record the pages you view, how you arrived (for example a search engine or a link), your device type, browser, approximate location (country, region and city derived from the IP address at the moment of the visit) and, when you shop, your orders. You can opt out at any time below.', 'nodethemes-analytics-ai' ) . '</p><p>[ntaa_optout]</p>';
		wp_add_privacy_policy_content( 'NodeThemes Analytics AI', wp_kses_post( $text ) );
	}
}
