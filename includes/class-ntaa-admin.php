<?php
/**
 * Admin menus, assets, settings page and dashboard widget.
 *
 * @package NodeThemes_Analytics_AI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Admin.
 */
class NTAA_Admin {

	/**
	 * Boot.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'wp_dashboard_setup', array( __CLASS__, 'dashboard_widget' ) );
		add_action( 'admin_post_ntaa_reset_data', array( __CLASS__, 'reset_data' ) );
		add_action( 'admin_post_ntaa_process_queue', array( __CLASS__, 'process_queue_now' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( NTAA_FILE ), array( __CLASS__, 'action_links' ) );
	}

	/**
	 * Menus.
	 */
	public static function menu() {
		$cap = current_user_can( 'manage_options' ) ? 'manage_options' : NTAA_CAP;
		add_menu_page( __( 'NodeThemes Analytics AI', 'nodethemes-analytics-ai' ), __( 'Analytics AI', 'nodethemes-analytics-ai' ), $cap, 'ntaa', array( __CLASS__, 'page_dashboard' ), 'dashicons-chart-area', 3 );
		add_submenu_page( 'ntaa', __( 'Analytics dashboard', 'nodethemes-analytics-ai' ), __( 'Dashboard', 'nodethemes-analytics-ai' ), $cap, 'ntaa', array( __CLASS__, 'page_dashboard' ) );
		if ( current_user_can( 'manage_options' ) || current_user_can( NTAA_CAP_AI ) ) {
			add_submenu_page( 'ntaa', __( 'AI Insights', 'nodethemes-analytics-ai' ), __( 'AI Insights', 'nodethemes-analytics-ai' ), current_user_can( 'manage_options' ) ? 'manage_options' : NTAA_CAP_AI, 'ntaa-ai', array( __CLASS__, 'page_ai' ) );
		}
		add_submenu_page( 'ntaa', __( 'Analytics AI settings', 'nodethemes-analytics-ai' ), __( 'Settings', 'nodethemes-analytics-ai' ), 'manage_options', 'ntaa-settings', array( __CLASS__, 'page_settings' ) );
	}

	/**
	 * Plugin list links.
	 *
	 * @param array $links Links.
	 * @return array
	 */
	public static function action_links( $links ) {
		array_unshift(
			$links,
			'<a href="' . esc_url( admin_url( 'admin.php?page=ntaa' ) ) . '">' . esc_html__( 'Dashboard', 'nodethemes-analytics-ai' ) . '</a>',
			'<a href="' . esc_url( admin_url( 'admin.php?page=ntaa-settings' ) ) . '">' . esc_html__( 'Settings', 'nodethemes-analytics-ai' ) . '</a>'
		);
		if ( ! NTAA_Pro::has_code() ) {
			$links[] = '<a href="' . esc_url( NTAA_Pro::url( 'plugins' ) ) . '" target="_blank" rel="noopener" style="font-weight:600">' . esc_html__( 'Get Pro', 'nodethemes-analytics-ai' ) . '</a>';
		}
		return $links;
	}

	/**
	 * Settings API registration.
	 */
	public static function register_settings() {
		register_setting(
			'ntaa_settings_group',
			NTAA_Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( 'NTAA_Settings', 'sanitize' ),
			)
		);
	}

	/**
	 * Websites shown in the site switcher (Pro adds more).
	 *
	 * @return array id => { name, url }
	 */
	public static function sites() {
		$sites = apply_filters(
			'ntaa_admin_sites',
			array(
				1 => array(
					'name' => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
					'url'  => home_url(),
				),
			)
		);
		$out = array();
		foreach ( (array) $sites as $id => $s ) {
			if ( ntaa_user_can_view_site( (int) $id ) ) {
				$out[ (int) $id ] = $s;
			}
		}
		return $out ? $out : array( 1 => array( 'name' => get_bloginfo( 'name' ), 'url' => home_url() ) );
	}

	/**
	 * Data-source attribution required by the GeoIP licence (DB-IP Lite is CC BY 4.0).
	 *
	 * @return string HTML.
	 */
	public static function geo_attribution() {
		$i = NTAA_GeoIP::info();
		if ( ! $i['active'] ) {
			return '';
		}
		if ( $i['dbip'] ) {
			return '<a href="https://db-ip.com" target="_blank" rel="noopener">IP Geolocation by DB-IP</a> (CC BY 4.0)';
		}
		if ( 'maxmind' === $i['source'] ) {
			return esc_html__( 'This product includes GeoLite2 data created by MaxMind, available from', 'nodethemes-analytics-ai' ) . ' <a href="https://www.maxmind.com" target="_blank" rel="noopener">maxmind.com</a>';
		}
		return '';
	}

	/**
	 * Admin notices shown on top of the dashboard.
	 *
	 * @return array HTML strings.
	 */
	private static function notices() {
		$n = array();
		if ( ! NTAA_Settings::get( 'enabled' ) ) {
			$n[] = esc_html__( 'Tracking is switched off, no new visits are recorded.', 'nodethemes-analytics-ai' ) . ( current_user_can( 'manage_options' ) ? ' <a href="' . esc_url( admin_url( 'admin.php?page=ntaa-settings#tracking' ) ) . '">' . esc_html__( 'Settings', 'nodethemes-analytics-ai' ) . '</a>' : '' );
		}
		$backlog = (int) get_option( 'ntaa_queue_backlog', 0 );
		if ( NTAA_Settings::get( 'queue' ) && $backlog > 20000 ) {
			/* translators: %s: number */
			$n[] = esc_html( sprintf( __( '%s hits are waiting in the processing queue. If this number keeps growing, set up a real server cron job (Settings › Performance).', 'nodethemes-analytics-ai' ), number_format_i18n( $backlog ) ) );
		}
		return apply_filters( 'ntaa_admin_notices', $n );
	}

	/**
	 * Assets on our pages.
	 *
	 * @param string $hook Hook suffix.
	 */
	public static function assets( $hook ) {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$map  = array(
			'ntaa'          => 'dashboard',
			'ntaa-ai'       => 'ai',
			'ntaa-settings' => 'settings',
		);
		$view = isset( $map[ $page ] ) ? $map[ $page ] : apply_filters( 'ntaa_admin_view', '', $page );
		if ( 'index.php' === $hook ) {
			wp_enqueue_style( 'ntaa-admin', NTAA_URL . 'assets/css/admin.css', array(), NTAA_VERSION );
			return;
		}
		if ( ! $view ) {
			return;
		}
		$js = NTAA_URL . 'assets/js/';
		wp_enqueue_style( 'ntaa-admin', NTAA_URL . 'assets/css/admin.css', array( 'dashicons' ), NTAA_VERSION );
		wp_register_script( 'ntaa-chart', $js . 'chart.umd.js', array(), '4.5.1', true );
		wp_register_script( 'ntaa-core', $js . 'admin/core.js', array( 'ntaa-chart', 'wp-i18n' ), NTAA_VERSION, true );
		wp_set_script_translations( 'ntaa-core', 'nodethemes-analytics-ai', NTAA_DIR . 'languages' );
		if ( 'dashboard' === $view ) {
			wp_enqueue_script( 'ntaa-world-map', $js . 'world-map.js', array(), NTAA_VERSION, true );
			wp_enqueue_script( 'ntaa-dashboard', $js . 'admin/dashboard.js', array( 'ntaa-core', 'ntaa-world-map' ), NTAA_VERSION, true );
			wp_enqueue_script( 'ntaa-widgets', $js . 'admin/widgets.js', array( 'ntaa-dashboard' ), NTAA_VERSION, true );
			wp_set_script_translations( 'ntaa-dashboard', 'nodethemes-analytics-ai', NTAA_DIR . 'languages' );
			wp_set_script_translations( 'ntaa-widgets', 'nodethemes-analytics-ai', NTAA_DIR . 'languages' );
		} elseif ( 'ai' === $view ) {
			wp_enqueue_script( 'ntaa-ai', $js . 'admin/ai.js', array( 'ntaa-core' ), NTAA_VERSION, true );
			wp_set_script_translations( 'ntaa-ai', 'nodethemes-analytics-ai', NTAA_DIR . 'languages' );
		} elseif ( 'settings' === $view ) {
			wp_enqueue_script( 'ntaa-settings', $js . 'admin/settings.js', array( 'ntaa-core' ), NTAA_VERSION, true );
			wp_set_script_translations( 'ntaa-settings', 'nodethemes-analytics-ai', NTAA_DIR . 'languages' );
		} else {
			wp_enqueue_script( 'ntaa-core' );
		}

		$providers = array();
		foreach ( NTAA_Settings::providers() as $id => $p ) {
			$providers[ $id ] = array(
				'label'      => $p['label'],
				'model'      => NTAA_Settings::get( $id . '_model', $p['model'] ),
				'configured' => '' !== NTAA_Settings::get_api_key( $id ) || '' !== (string) NTAA_Settings::get( $id . '_base' ),
			);
		}
		$geo    = NTAA_GeoIP::info();
		$config = array(
			'view'         => $view,
			'rest'         => esc_url_raw( rest_url( 'ntaa/v1/' ) ),
			'nonce'        => wp_create_nonce( 'wp_rest' ),
			'home'         => home_url(),
			'settingsUrl'  => admin_url( 'admin.php?page=ntaa-settings' ),
			'aiUrl'        => admin_url( 'admin.php?page=ntaa-ai' ),
			'dashboardUrl' => admin_url( 'admin.php?page=ntaa' ),
			'licenseUrl'   => NTAA_Pro::license_url(),
			'upgradeUrl'   => NTAA_Pro::url( 'dashboard' ),
			'pro'          => NTAA_Pro::active(),
			'proInstalled' => NTAA_Pro::has_code(),
			'chat'         => NTAA_AI::chat_enabled(),
			'providers'    => $providers,
			'provider'     => NTAA_Settings::get( 'ai_provider' ),
			'types'        => NTAA_AI::ui_types(),
			'canManage'    => current_user_can( 'manage_options' ),
			'canAI'        => current_user_can( 'manage_options' ) || current_user_can( NTAA_CAP_AI ),
			'userId'       => get_current_user_id(),
			'cookieless'   => (int) NTAA_Settings::get( 'cookieless' ),
			'trackBots'    => (int) NTAA_Settings::get( 'track_bots' ),
			'woo'          => NTAA_Ecommerce::active(),
			'currency'     => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : '',
			'sites'        => self::sites(),
			'geo'          => array(
				'active'      => $geo['active'],
				'city'        => $geo['active'] && false !== stripos( (string) $geo['type'], 'city' ),
				'attribution' => self::geo_attribution(),
			),
			'notices'      => 'dashboard' === $view ? self::notices() : array(),
			'today'        => wp_date( 'Y-m-d' ),
			'locale'       => str_replace( '_', '-', get_user_locale() ),
			'rtl'          => is_rtl(),
			'startOfWeek'  => (int) get_option( 'start_of_week', 1 ),
			'support'      => ntaa_support(),
			'version'      => NTAA_VERSION,
		);
		wp_add_inline_script( 'ntaa-core', 'window.NTAAAdmin = ' . wp_json_encode( apply_filters( 'ntaa_admin_config', $config, $view ) ) . ';', 'before' );
		do_action( 'ntaa_admin_enqueue', $view );
	}

	/* ---------------------------------------------------------------
	 * Pages
	 * ------------------------------------------------------------- */

	/**
	 * Dashboard page shell (the app is rendered by dashboard.js).
	 */
	public static function page_dashboard() {
		echo '<div class="wrap ntaa-wrap"><h1 class="screen-reader-text">' . esc_html__( 'NodeThemes Analytics AI', 'nodethemes-analytics-ai' ) . '</h1><div id="ntaa-app" data-view="dashboard"><div class="ntaa-loading">' . esc_html__( 'Loading…', 'nodethemes-analytics-ai' ) . '</div></div>';
		echo '<noscript><p>' . esc_html__( 'Please enable JavaScript to see the analytics dashboard.', 'nodethemes-analytics-ai' ) . '</p></noscript></div>';
	}

	/**
	 * AI page shell.
	 */
	public static function page_ai() {
		echo '<div class="wrap ntaa-wrap"><h1 class="screen-reader-text">' . esc_html__( 'AI Insights', 'nodethemes-analytics-ai' ) . '</h1><div id="ntaa-app" data-view="ai"><div class="ntaa-loading">' . esc_html__( 'Loading…', 'nodethemes-analytics-ai' ) . '</div></div></div>';
	}

	/**
	 * WP dashboard widget.
	 */
	public static function dashboard_widget() {
		if ( ! ntaa_user_can_view() ) {
			return;
		}
		wp_add_dashboard_widget( 'ntaa_widget', __( 'Analytics AI — last 7 days', 'nodethemes-analytics-ai' ), array( __CLASS__, 'render_widget' ) );
	}

	/**
	 * Widget body.
	 */
	public static function render_widget() {
		$s   = new NTAA_Stats( wp_date( 'Y-m-d', time() - 6 * DAY_IN_SECONDS ), wp_date( 'Y-m-d' ) );
		$sum = $s->summary();
		$rt  = NTAA_Stats::realtime();
		echo '<div class="ntaa-widget"><div class="ntaa-widget-grid">';
		printf( '<div><b>%s</b><span>%s</span></div>', esc_html( number_format_i18n( $sum['visitors'] ) ), esc_html__( 'Visitors', 'nodethemes-analytics-ai' ) );
		printf( '<div><b>%s</b><span>%s</span></div>', esc_html( number_format_i18n( $sum['pageviews'] ) ), esc_html__( 'Pageviews', 'nodethemes-analytics-ai' ) );
		if ( NTAA_Ecommerce::active() ) {
			printf( '<div><b>%s</b><span>%s</span></div>', wp_kses_post( function_exists( 'wc_price' ) ? wc_price( $sum['revenue'] ) : number_format_i18n( $sum['revenue'], 2 ) ), esc_html__( 'Revenue', 'nodethemes-analytics-ai' ) );
		} else {
			printf( '<div><b>%s%%</b><span>%s</span></div>', esc_html( number_format_i18n( $sum['bounce_rate'], 1 ) ), esc_html__( 'Bounce rate', 'nodethemes-analytics-ai' ) );
		}
		printf( '<div><b>%s</b><span>%s</span></div>', esc_html( number_format_i18n( $rt['active_30'] ) ), esc_html__( 'Online now', 'nodethemes-analytics-ai' ) );
		echo '</div>';
		$pages = $s->top_pages( 5 );
		if ( $pages ) {
			echo '<ol class="ntaa-widget-list">';
			foreach ( $pages as $p ) {
				if ( '__other__' === $p['page'] ) {
					continue;
				}
				printf( '<li><span>%s</span><b>%s</b></li>', esc_html( $p['title'] ? $p['title'] : $p['page'] ), esc_html( number_format_i18n( $p['views'] ) ) );
			}
			echo '</ol>';
		}
		printf(
			'<p><a class="button button-primary" href="%s">%s</a> <a class="button" href="%s">%s</a></p>',
			esc_url( admin_url( 'admin.php?page=ntaa' ) ),
			esc_html__( 'Full dashboard', 'nodethemes-analytics-ai' ),
			esc_url( admin_url( 'admin.php?page=ntaa-ai' ) ),
			esc_html__( 'AI Insights', 'nodethemes-analytics-ai' )
		);
		echo '</div>';
	}

	/**
	 * Delete all collected data.
	 */
	public static function reset_data() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'nodethemes-analytics-ai' ), 403 );
		}
		check_admin_referer( 'ntaa_reset_data' );
		NTAA_DB::truncate_all();
		wp_safe_redirect( admin_url( 'admin.php?page=ntaa-settings&ntaa_reset=1#privacy' ) );
		exit;
	}

	/**
	 * "Process queue now" button.
	 */
	public static function process_queue_now() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'nodethemes-analytics-ai' ), 403 );
		}
		check_admin_referer( 'ntaa_process_queue' );
		$n = NTAA_Tracker::process_queue( 20000 );
		wp_safe_redirect( admin_url( 'admin.php?page=ntaa-settings&ntaa_processed=' . (int) $n . '#performance' ) );
		exit;
	}

	/* ---------------------------------------------------------------
	 * Settings page
	 * ------------------------------------------------------------- */

	/**
	 * Option field name.
	 *
	 * @param string $k Key.
	 * @return string
	 */
	private static function field_name( $k ) {
		return NTAA_Settings::OPTION . '[' . $k . ']';
	}

	/**
	 * Checkbox row.
	 *
	 * @param string $k     Key.
	 * @param string $label Label.
	 * @param string $help  Help text.
	 */
	private static function checkbox( $k, $label, $help = '' ) {
		printf(
			'<label class="ntaa-check"><input type="checkbox" name="%s" value="1" %s> <span>%s</span></label>%s',
			esc_attr( self::field_name( $k ) ),
			checked( 1, (int) NTAA_Settings::get( $k ), false ),
			esc_html( $label ),
			$help ? '<p class="description ntaa-check-help">' . esc_html( $help ) . '</p>' : '' // phpcs:ignore WordPress.Security.EscapeOutput
		);
	}

	/**
	 * Role checkboxes.
	 *
	 * @param string $k        Key.
	 * @param array  $selected Selected roles.
	 * @param bool   $lock_admin Administrator always on.
	 */
	private static function roles( $k, $selected, $lock_admin = false ) {
		echo '<div class="ntaa-roles">';
		foreach ( wp_roles()->get_names() as $key => $name ) {
			$locked = $lock_admin && 'administrator' === $key;
			printf(
				'<label class="ntaa-check"><input type="checkbox" name="%s[]" value="%s" %s %s> <span>%s</span></label>',
				esc_attr( self::field_name( $k ) ),
				esc_attr( $key ),
				checked( $locked || in_array( $key, (array) $selected, true ), true, false ),
				$locked ? 'disabled' : '',
				esc_html( translate_user_role( $name ) )
			);
		}
		echo '</div>';
	}

	/**
	 * Support box.
	 */
	public static function support_box() {
		$s = ntaa_support();
		echo '<div class="ntaa-support"><strong>' . esc_html__( 'Need help?', 'nodethemes-analytics-ai' ) . '</strong> '
			. esc_html__( 'Email', 'nodethemes-analytics-ai' ) . ' <a href="mailto:' . esc_attr( $s['email'] ) . '">' . esc_html( $s['email'] ) . '</a>'
			. ' &middot; WhatsApp <a href="' . esc_url( $s['whatsapp_url'] ) . '" target="_blank" rel="noopener">' . esc_html( $s['whatsapp'] ) . '</a>'
			. ' &middot; Telegram <a href="' . esc_url( $s['telegram'] ) . '" target="_blank" rel="noopener">@nodethemes</a>'
			. ' &middot; <a href="' . esc_url( $s['site'] ) . '" target="_blank" rel="noopener">nodethemes.com</a></div>';
	}

	/**
	 * Human file size.
	 *
	 * @param int $b Bytes.
	 * @return string
	 */
	private static function size( $b ) {
		return size_format( (int) $b, 1 );
	}

	/**
	 * Render settings.
	 */
	public static function page_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s         = NTAA_Settings::all();
		$providers = NTAA_Settings::providers();
		$langs     = NTAA_Settings::languages();
		$geo       = NTAA_GeoIP::info();
		$geo_msg   = get_transient( 'ntaa_geo_msg_' . get_current_user_id() );
		if ( $geo_msg ) {
			delete_transient( 'ntaa_geo_msg_' . get_current_user_id() );
		}
		$backlog   = (int) get_option( 'ntaa_queue_backlog', 0 );
		$lang_lbl  = array(
			'site' => __( 'Same as the site language', 'nodethemes-analytics-ai' ),
			'en'   => 'English',
			'fa'   => 'فارسی',
			'ar'   => 'العربية',
			'tr'   => 'Türkçe',
			'de'   => 'Deutsch',
			'fr'   => 'Français',
			'es'   => 'Español',
			'it'   => 'Italiano',
			'pt'   => 'Português',
			'ru'   => 'Русский',
			'nl'   => 'Nederlands',
		);
		$tabs = array(
			'tracking'    => __( 'Tracking', 'nodethemes-analytics-ai' ),
			'privacy'     => __( 'Privacy & GDPR', 'nodethemes-analytics-ai' ),
			'geoip'       => __( 'Locations (GeoIP)', 'nodethemes-analytics-ai' ),
			'performance' => __( 'Performance', 'nodethemes-analytics-ai' ),
			'access'      => __( 'Access', 'nodethemes-analytics-ai' ),
			'ai'          => __( 'AI', 'nodethemes-analytics-ai' ),
			'report'      => __( 'Email reports', 'nodethemes-analytics-ai' ),
			'dev'         => __( 'Developers', 'nodethemes-analytics-ai' ),
		);
		$tabs = apply_filters( 'ntaa_settings_tabs', $tabs );
		?>
		<div class="wrap ntaa-wrap">
			<div id="ntaa-app" data-view="settings" class="ntaa-settings">
				<header class="ntaa-header">
					<div class="ntaa-brand"><span class="ntaa-logo"><span class="dashicons dashicons-chart-area" aria-hidden="true"></span></span><div><h1><?php esc_html_e( 'Analytics AI settings', 'nodethemes-analytics-ai' ); ?><?php echo NTAA_Pro::active() ? ' <span class="ntaa-protag on">PRO</span>' : ''; // phpcs:ignore WordPress.Security.EscapeOutput ?></h1><p><?php esc_html_e( 'Tracking, privacy, locations, performance and AI connection', 'nodethemes-analytics-ai' ); ?></p></div></div>
					<span class="ntaa-version">v<?php echo esc_html( NTAA_VERSION ); ?></span>
				</header>

				<?php if ( isset( $_GET['ntaa_reset'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification ?>
					<div class="notice notice-success"><p><?php esc_html_e( 'All analytics data has been deleted.', 'nodethemes-analytics-ai' ); ?></p></div>
				<?php endif; ?>
				<?php if ( isset( $_GET['ntaa_processed'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification ?>
					<div class="notice notice-success"><p>
						<?php
						/* translators: %s: number */
						echo esc_html( sprintf( __( '%s queued hits were processed.', 'nodethemes-analytics-ai' ), number_format_i18n( (int) $_GET['ntaa_processed'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification
						?>
					</p></div>
				<?php endif; ?>
				<?php if ( is_array( $geo_msg ) ) : ?>
					<div class="notice notice-<?php echo 'success' === $geo_msg[0] ? 'success' : 'error'; ?>"><p><?php echo esc_html( $geo_msg[1] ); ?></p></div>
				<?php endif; ?>
				<?php settings_errors(); ?>

				<nav class="ntaa-tabs" data-tabs role="tablist">
					<?php $first = true; ?>
					<?php foreach ( $tabs as $id => $label ) : ?>
						<button type="button" role="tab" class="<?php echo $first ? 'active' : ''; ?>" data-tab="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?><?php echo ( 'report' === $id && ! NTAA_Pro::active() ) ? ' ' . NTAA_Pro::badge() : ''; // phpcs:ignore WordPress.Security.EscapeOutput ?></button>
						<?php $first = false; ?>
					<?php endforeach; ?>
				</nav>

				<form method="post" action="options.php">
					<?php settings_fields( 'ntaa_settings_group' ); ?>

					<section class="ntaa-card ntaa-tabpane active" data-pane="tracking">
						<h2><?php esc_html_e( 'Tracking', 'nodethemes-analytics-ai' ); ?></h2>
						<?php self::checkbox( 'enabled', __( 'Enable tracking', 'nodethemes-analytics-ai' ) ); ?>
						<?php self::checkbox( 'track_outbound', __( 'Track clicks on outbound links', 'nodethemes-analytics-ai' ) ); ?>
						<?php self::checkbox( 'track_downloads', __( 'Track file downloads (PDF, ZIP, APK…)', 'nodethemes-analytics-ai' ) ); ?>
						<?php self::checkbox( 'track_forms', __( 'Track form submissions', 'nodethemes-analytics-ai' ) ); ?>
						<?php self::checkbox( 'track_search', __( 'Track site search (WordPress, WooCommerce and ?q= / ?s= searches)', 'nodethemes-analytics-ai' ) ); ?>
						<?php self::checkbox( 'track_vitals', __( 'Measure page speed (Core Web Vitals: LCP, INP, CLS, TTFB)', 'nodethemes-analytics-ai' ) ); ?>
						<?php self::checkbox( 'track_ecommerce', __( 'Track WooCommerce (product views, cart, checkout, orders and revenue)', 'nodethemes-analytics-ai' ), NTAA_Ecommerce::active() ? '' : __( 'WooCommerce is not active on this site.', 'nodethemes-analytics-ai' ) ); ?>
						<?php self::checkbox( 'track_bots', __( 'Log crawlers & AI agents (GPTBot, ClaudeBot, Googlebot…) in a separate report', 'nodethemes-analytics-ai' ), __( 'Bots are never counted as visitors. Logging is done on the server for normal page loads.', 'nodethemes-analytics-ai' ) ); ?>
						<?php self::checkbox( 'track_spa', __( 'Single-page app support (address changes without a page reload)', 'nodethemes-analytics-ai' ) ); ?>

						<h3><?php esc_html_e( 'Do not track these users', 'nodethemes-analytics-ai' ); ?></h3>
						<?php self::roles( 'exclude_roles', $s['exclude_roles'] ); ?>
						<h3><label for="ntaa-exclude-ips"><?php esc_html_e( 'Excluded IP addresses', 'nodethemes-analytics-ai' ); ?></label></h3>
						<textarea id="ntaa-exclude-ips" name="<?php echo esc_attr( self::field_name( 'exclude_ips' ) ); ?>" rows="3" class="large-text code" dir="ltr" placeholder="203.0.113.7&#10;10.0.0.*&#10;192.168.0.0/16"><?php echo esc_textarea( $s['exclude_ips'] ); ?></textarea>
						<p class="description"><?php esc_html_e( 'One per line. Wildcards (*) and CIDR ranges are supported.', 'nodethemes-analytics-ai' ); ?></p>
						<?php self::checkbox( 'trust_proxy', __( 'My site is behind a CDN / proxy (Cloudflare etc.) — read the real visitor IP from its header', 'nodethemes-analytics-ai' ), __( 'Turn this on only when a proxy is really in front of your site, otherwise visitors could fake their IP to avoid exclusions.', 'nodethemes-analytics-ai' ) ); ?>

						<h3><?php esc_html_e( 'Custom dimensions', 'nodethemes-analytics-ai' ); ?></h3>
						<p class="description"><?php esc_html_e( 'Name up to five of your own visit properties (e.g. Membership, Author, Language version). Set them on the page with ntaa.setCustomDimension(1, "gold").', 'nodethemes-analytics-ai' ); ?></p>
						<div class="ntaa-dims">
							<?php for ( $i = 0; $i < 5; $i++ ) : ?>
								<label><span><?php echo esc_html( sprintf( '#%d', $i + 1 ) ); ?></span><input type="text" maxlength="60" name="<?php echo esc_attr( self::field_name( 'custom_dims' ) ); ?>[<?php echo (int) $i; ?>]" value="<?php echo esc_attr( isset( $s['custom_dims'][ $i ] ) ? $s['custom_dims'][ $i ] : '' ); ?>" placeholder="<?php esc_attr_e( 'not used', 'nodethemes-analytics-ai' ); ?>"></label>
							<?php endfor; ?>
						</div>
					</section>

					<section class="ntaa-card ntaa-tabpane" data-pane="privacy">
						<h2><?php esc_html_e( 'Privacy', 'nodethemes-analytics-ai' ); ?></h2>
						<?php self::checkbox( 'cookieless', __( 'Cookieless mode (recommended, GDPR-friendly)', 'nodethemes-analytics-ai' ), __( 'Nothing is stored in the visitor’s browser; visitors are recognised with a hash that changes every day, so returning visitors, visitor profiles and cohorts are not available. When off, a random ID is kept in the browser (first-party cookie + localStorage).', 'nodethemes-analytics-ai' ) ); ?>
						<?php self::checkbox( 'respect_dnt', __( 'Respect “Do Not Track” and Global Privacy Control', 'nodethemes-analytics-ai' ) ); ?>
						<table class="form-table" role="presentation">
							<tr>
								<th scope="row"><label for="ntaa-consent"><?php esc_html_e( 'Consent', 'nodethemes-analytics-ai' ); ?></label></th>
								<td>
									<select id="ntaa-consent" name="<?php echo esc_attr( self::field_name( 'consent' ) ); ?>">
										<option value="off" <?php selected( $s['consent'], 'off' ); ?>><?php esc_html_e( 'Not needed — track without asking (cookieless mode)', 'nodethemes-analytics-ai' ); ?></option>
										<option value="cookie" <?php selected( $s['consent'], 'cookie' ); ?>><?php esc_html_e( 'Track cookieless until consent, then use the visitor ID', 'nodethemes-analytics-ai' ); ?></option>
										<option value="strict" <?php selected( $s['consent'], 'strict' ); ?>><?php esc_html_e( 'Do not track at all until consent is given', 'nodethemes-analytics-ai' ); ?></option>
									</select>
									<p class="description"><?php esc_html_e( 'Works with the WP Consent API (Complianz, CookieYes, Borlabs…). From your own banner call ntaa.setConsent(true) / ntaa.setConsent(false).', 'nodethemes-analytics-ai' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="ntaa-ret"><?php esc_html_e( 'Keep the raw visit log for', 'nodethemes-analytics-ai' ); ?></label></th>
								<td><input id="ntaa-ret" type="number" min="0" max="3650" name="<?php echo esc_attr( self::field_name( 'retention_days' ) ); ?>" value="<?php echo esc_attr( $s['retention_days'] ); ?>" class="small-text"> <?php esc_html_e( 'days', 'nodethemes-analytics-ai' ); ?> <span class="description"><?php esc_html_e( '(0 = forever)', 'nodethemes-analytics-ai' ); ?></span>
									<p class="description"><?php esc_html_e( 'Older visits are deleted from the visits log, but your reports keep their daily totals. Segments and “apply goal to past data” only work inside this period.', 'nodethemes-analytics-ai' ); ?></p></td>
							</tr>
						</table>
						<p class="description"><?php esc_html_e( 'IP addresses are never stored in the database. The location is looked up when the visit arrives and only the country/region/city is kept.', 'nodethemes-analytics-ai' ); ?></p>
						<h3><?php esc_html_e( 'Opt-out for visitors', 'nodethemes-analytics-ai' ); ?></h3>
						<p><?php esc_html_e( 'Put this shortcode on your privacy policy page so visitors can switch tracking off:', 'nodethemes-analytics-ai' ); ?> <code>[ntaa_optout]</code> <button type="button" class="button button-small" data-copy="[ntaa_optout]"><?php esc_html_e( 'Copy', 'nodethemes-analytics-ai' ); ?></button></p>
						<p class="description"><?php esc_html_e( 'A suggested text for your privacy policy is available under Settings › Privacy › Policy guide.', 'nodethemes-analytics-ai' ); ?></p>
					</section>

					<section class="ntaa-card ntaa-tabpane" data-pane="geoip">
						<h2><?php esc_html_e( 'Visitor locations (GeoIP)', 'nodethemes-analytics-ai' ); ?></h2>
						<p class="description"><?php esc_html_e( 'The country, region and city of each visit are looked up in a database file stored on your own server — the IP address is never sent anywhere.', 'nodethemes-analytics-ai' ); ?></p>
						<table class="form-table" role="presentation">
							<tr>
								<th scope="row"><label for="ntaa-geo-src"><?php esc_html_e( 'Database', 'nodethemes-analytics-ai' ); ?></label></th>
								<td>
									<select id="ntaa-geo-src" name="<?php echo esc_attr( self::field_name( 'geoip_source' ) ); ?>">
										<option value="dbip_country" <?php selected( $s['geoip_source'], 'dbip_country' ); ?>><?php esc_html_e( 'DB-IP Lite — countries (free, ~7 MB, updated monthly)', 'nodethemes-analytics-ai' ); ?></option>
										<option value="dbip_city" <?php selected( $s['geoip_source'], 'dbip_city' ); ?>><?php esc_html_e( 'DB-IP Lite — cities (free, ~130 MB, updated monthly)', 'nodethemes-analytics-ai' ); ?></option>
										<option value="maxmind" <?php selected( $s['geoip_source'], 'maxmind' ); ?>><?php esc_html_e( 'MaxMind GeoLite2 City (free account and licence key needed)', 'nodethemes-analytics-ai' ); ?></option>
										<option value="upload" <?php selected( $s['geoip_source'], 'upload' ); ?>><?php esc_html_e( 'My own .mmdb file (upload below)', 'nodethemes-analytics-ai' ); ?></option>
										<option value="off" <?php selected( $s['geoip_source'], 'off' ); ?>><?php esc_html_e( 'Off — estimate the country from the time zone', 'nodethemes-analytics-ai' ); ?></option>
									</select>
								</td>
							</tr>
							<tr data-depends="<?php echo esc_attr( self::field_name( 'geoip_source' ) ); ?>=maxmind">
								<th scope="row"><?php esc_html_e( 'MaxMind account', 'nodethemes-analytics-ai' ); ?></th>
								<td>
									<input type="text" class="regular-text code" dir="ltr" name="<?php echo esc_attr( self::field_name( 'maxmind_account' ) ); ?>" value="<?php echo esc_attr( $s['maxmind_account'] ); ?>" placeholder="<?php esc_attr_e( 'Account ID', 'nodethemes-analytics-ai' ); ?>"><br>
									<input type="password" autocomplete="new-password" class="regular-text code" dir="ltr" name="<?php echo esc_attr( self::field_name( 'maxmind_key' ) ); ?>" value="" placeholder="<?php echo $s['maxmind_key'] ? esc_attr__( '•••••••• saved — type a new key to replace it', 'nodethemes-analytics-ai' ) : esc_attr__( 'Licence key', 'nodethemes-analytics-ai' ); ?>">
									<p class="description"><a href="https://www.maxmind.com/en/geolite2/signup" target="_blank" rel="noopener"><?php esc_html_e( 'Create a free MaxMind account', 'nodethemes-analytics-ai' ); ?></a></p>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="ntaa-geo-prec"><?php esc_html_e( 'Store', 'nodethemes-analytics-ai' ); ?></label></th>
								<td>
									<select id="ntaa-geo-prec" name="<?php echo esc_attr( self::field_name( 'geo_precision' ) ); ?>">
										<option value="city" <?php selected( $s['geo_precision'], 'city' ); ?>><?php esc_html_e( 'Country, region and city', 'nodethemes-analytics-ai' ); ?></option>
										<option value="region" <?php selected( $s['geo_precision'], 'region' ); ?>><?php esc_html_e( 'Country and region', 'nodethemes-analytics-ai' ); ?></option>
										<option value="country" <?php selected( $s['geo_precision'], 'country' ); ?>><?php esc_html_e( 'Country only (most private)', 'nodethemes-analytics-ai' ); ?></option>
									</select>
								</td>
							</tr>
						</table>
						<div class="ntaa-geo-status">
							<?php if ( $geo['active'] ) : ?>
								<p><span class="ntaa-pill ok"><?php esc_html_e( 'Active', 'nodethemes-analytics-ai' ); ?></span> <code dir="ltr"><?php echo esc_html( $geo['type'] ? $geo['type'] : $geo['source'] ); ?></code>
								<?php
								/* translators: 1: build date 2: size */
								echo esc_html( sprintf( __( 'built %1$s · %2$s', 'nodethemes-analytics-ai' ), $geo['built'] ? $geo['built'] : '—', self::size( $geo['size'] ) ) );
								?>
								</p>
								<?php if ( self::geo_attribution() ) : ?>
									<p class="description"><?php echo wp_kses_post( self::geo_attribution() ); ?></p>
								<?php endif; ?>
							<?php else : ?>
								<p><span class="ntaa-pill"><?php esc_html_e( 'Not installed', 'nodethemes-analytics-ai' ); ?></span> <?php esc_html_e( 'Save your choice, then click “Download now”. Until then the country is estimated from the visitor’s time zone.', 'nodethemes-analytics-ai' ); ?></p>
							<?php endif; ?>
							<?php if ( $geo['error'] ) : ?>
								<p class="ntaa-bad"><?php echo esc_html( $geo['error'] ); ?></p>
							<?php endif; ?>
						</div>
					</section>

					<section class="ntaa-card ntaa-tabpane" data-pane="performance">
						<h2><?php esc_html_e( 'Performance & busy sites', 'nodethemes-analytics-ai' ); ?></h2>
						<p class="description"><?php esc_html_e( 'Reports are pre-calculated once per day (archiving), so the dashboard stays fast even with millions of visits. Only today is read from the raw log.', 'nodethemes-analytics-ai' ); ?></p>
						<?php self::checkbox( 'queue', __( 'High-traffic mode: store hits in a queue and process them in the background', 'nodethemes-analytics-ai' ), __( 'Each hit only needs one quick database insert; a background job (every minute) does the rest. Recommended from about 50,000 pageviews per day.', 'nodethemes-analytics-ai' ) ); ?>
						<div data-depends="<?php echo esc_attr( self::field_name( 'queue' ) ); ?>=1">
							<p>
								<?php
								/* translators: %s: number */
								echo esc_html( sprintf( __( 'Waiting in the queue: %s hits', 'nodethemes-analytics-ai' ), number_format_i18n( $backlog ) ) );
								?>
							</p>
							<p class="description"><?php esc_html_e( 'WordPress runs background jobs only when someone visits the site. For a busy site, add a real server cron job and put define( \'DISABLE_WP_CRON\', true ); in wp-config.php:', 'nodethemes-analytics-ai' ); ?></p>
							<pre class="ntaa-code" dir="ltr">* * * * * wget -q -O - <?php echo esc_html( site_url( 'wp-cron.php?doing_wp_cron' ) ); ?> &gt;/dev/null 2&gt;&amp;1
# <?php esc_html_e( 'or with WP-CLI:', 'nodethemes-analytics-ai' ); ?>

* * * * * cd <?php echo esc_html( untrailingslashit( ABSPATH ) ); ?> &amp;&amp; wp cron event run --due-now &gt;/dev/null 2&gt;&amp;1</pre>
						</div>
					</section>

					<section class="ntaa-card ntaa-tabpane" data-pane="access">
						<h2><?php esc_html_e( 'Who can see the analytics', 'nodethemes-analytics-ai' ); ?></h2>
						<?php self::roles( 'roles_view', $s['roles_view'], true ); ?>
						<h3><?php esc_html_e( 'Who can use the AI features (uses your API credit)', 'nodethemes-analytics-ai' ); ?></h3>
						<?php self::roles( 'roles_ai', $s['roles_ai'], true ); ?>
						<p class="description"><?php esc_html_e( 'Only administrators can change settings, goals and delete data.', 'nodethemes-analytics-ai' ); ?></p>
					</section>

					<section class="ntaa-card ntaa-tabpane" data-pane="ai">
						<h2><?php esc_html_e( 'AI connection', 'nodethemes-analytics-ai' ); ?></h2>
						<p class="description"><?php esc_html_e( 'You use your own AI account, so the AI company bills you directly for what you use. Only aggregated numbers (e.g. views per page) are sent — never visitor IP addresses or personal data. Keys are stored encrypted.', 'nodethemes-analytics-ai' ); ?></p>
						<table class="form-table" role="presentation">
							<tr>
								<th scope="row"><label for="ntaa-provider"><?php esc_html_e( 'Default provider', 'nodethemes-analytics-ai' ); ?></label></th>
								<td>
									<select id="ntaa-provider" name="<?php echo esc_attr( self::field_name( 'ai_provider' ) ); ?>">
										<?php foreach ( $providers as $id => $p ) : ?>
											<option value="<?php echo esc_attr( $id ); ?>" <?php selected( $s['ai_provider'], $id ); ?>><?php echo esc_html( $p['label'] ); ?></option>
										<?php endforeach; ?>
									</select>
									<p class="description"><?php esc_html_e( 'You can switch provider for each report on the AI Insights page.', 'nodethemes-analytics-ai' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="ntaa-lang"><?php esc_html_e( 'Report language', 'nodethemes-analytics-ai' ); ?></label></th>
								<td>
									<select id="ntaa-lang" name="<?php echo esc_attr( self::field_name( 'ai_language' ) ); ?>">
										<?php foreach ( array_keys( $langs ) as $code ) : ?>
											<option value="<?php echo esc_attr( $code ); ?>" <?php selected( $s['ai_language'], $code ); ?>><?php echo esc_html( isset( $lang_lbl[ $code ] ) ? $lang_lbl[ $code ] : $code ); ?></option>
										<?php endforeach; ?>
									</select>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="ntaa-max"><?php esc_html_e( 'Max output tokens', 'nodethemes-analytics-ai' ); ?></label></th>
								<td><input id="ntaa-max" type="number" min="500" max="32000" step="100" name="<?php echo esc_attr( self::field_name( 'ai_max_tokens' ) ); ?>" value="<?php echo esc_attr( $s['ai_max_tokens'] ); ?>" class="small-text"> <span class="description"><?php esc_html_e( 'Recommended: 6000 (reasoning models need more).', 'nodethemes-analytics-ai' ); ?></span></td>
							</tr>
							<tr>
								<th scope="row"><label for="ntaa-note"><?php esc_html_e( 'About your business', 'nodethemes-analytics-ai' ); ?></label></th>
								<td>
									<textarea id="ntaa-note" name="<?php echo esc_attr( self::field_name( 'ai_custom_note' ) ); ?>" rows="3" class="large-text" placeholder="<?php esc_attr_e( 'Example: We sell running shoes online. Our main goal is more sales of trail shoes; most customers are 20–35.', 'nodethemes-analytics-ai' ); ?>"><?php echo esc_textarea( $s['ai_custom_note'] ); ?></textarea>
									<p class="description"><?php esc_html_e( 'Helps the AI give advice that fits your business.', 'nodethemes-analytics-ai' ); ?></p>
								</td>
							</tr>
						</table>

						<div class="ntaa-providers">
							<?php foreach ( $providers as $id => $p ) : ?>
								<?php
								$has_key  = '' !== NTAA_Settings::get_api_key( $id );
								$from_cst = NTAA_Settings::key_from_constant( $id );
								?>
								<div class="ntaa-provider ntaa-provider-<?php echo esc_attr( $id ); ?>">
									<div class="ntaa-provider-head">
										<h3><?php echo esc_html( $p['label'] ); ?></h3>
										<span class="ntaa-pill <?php echo $has_key ? 'ok' : ''; ?>"><?php echo $has_key ? esc_html__( 'Key saved', 'nodethemes-analytics-ai' ) : esc_html__( 'No key', 'nodethemes-analytics-ai' ); ?></span>
									</div>
									<label for="ntaa-key-<?php echo esc_attr( $id ); ?>"><?php esc_html_e( 'API key', 'nodethemes-analytics-ai' ); ?></label>
									<?php if ( $from_cst ) : ?>
										<p class="description">
											<?php
											/* translators: %s: constant name */
											printf( esc_html__( 'The key is read from %s in wp-config.php.', 'nodethemes-analytics-ai' ), '<code>' . esc_html( $p['const'] ) . '</code>' );
											?>
										</p>
									<?php else : ?>
										<input id="ntaa-key-<?php echo esc_attr( $id ); ?>" type="password" autocomplete="new-password" name="<?php echo esc_attr( self::field_name( $id . '_key' ) ); ?>" value="" class="regular-text code" dir="ltr" placeholder="<?php echo $has_key ? esc_attr__( '•••••••• saved — type a new key to replace it', 'nodethemes-analytics-ai' ) : esc_attr__( 'Paste your key here', 'nodethemes-analytics-ai' ); ?>">
										<?php if ( $has_key ) : ?>
											<label class="ntaa-check small"><input type="checkbox" name="<?php echo esc_attr( self::field_name( $id . '_key_delete' ) ); ?>" value="1"> <span><?php esc_html_e( 'Remove the saved key', 'nodethemes-analytics-ai' ); ?></span></label>
										<?php endif; ?>
										<p class="description"><a href="<?php echo esc_url( $p['keyhelp'] ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Get a key', 'nodethemes-analytics-ai' ); ?></a></p>
									<?php endif; ?>

									<label for="ntaa-model-<?php echo esc_attr( $id ); ?>"><?php esc_html_e( 'Model', 'nodethemes-analytics-ai' ); ?></label>
									<input id="ntaa-model-<?php echo esc_attr( $id ); ?>" type="text" list="ntaa-models-<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( self::field_name( $id . '_model' ) ); ?>" value="<?php echo esc_attr( $s[ $id . '_model' ] ); ?>" class="regular-text code" dir="ltr">
									<datalist id="ntaa-models-<?php echo esc_attr( $id ); ?>">
										<?php foreach ( $p['models'] as $m ) : ?>
											<option value="<?php echo esc_attr( $m ); ?>"></option>
										<?php endforeach; ?>
									</datalist>

									<label for="ntaa-base-<?php echo esc_attr( $id ); ?>"><?php esc_html_e( 'Custom API URL (optional)', 'nodethemes-analytics-ai' ); ?></label>
									<input id="ntaa-base-<?php echo esc_attr( $id ); ?>" type="url" name="<?php echo esc_attr( self::field_name( $id . '_base' ) ); ?>" value="<?php echo esc_attr( $s[ $id . '_base' ] ); ?>" class="regular-text code" dir="ltr" placeholder="<?php echo esc_attr( $p['base'] ); ?>">
									<p class="description">
										<?php
										if ( 'openai' === $id ) {
											echo esc_html__( 'For OpenAI-compatible services (OpenRouter, Azure, or a local Ollama model:', 'nodethemes-analytics-ai' ) . ' <code dir="ltr">http://localhost:11434/v1</code>). ' . esc_html__( 'Empty = official API.', 'nodethemes-analytics-ai' );
										} else {
											esc_html_e( 'Leave empty to use the official API.', 'nodethemes-analytics-ai' );
										}
										?>
									</p>
									<button type="button" class="button ntaa-test" data-provider="<?php echo esc_attr( $id ); ?>"><?php esc_html_e( 'Test connection', 'nodethemes-analytics-ai' ); ?></button>
									<span class="ntaa-test-result" data-for="<?php echo esc_attr( $id ); ?>" aria-live="polite"></span>
								</div>
							<?php endforeach; ?>
						</div>
						<p class="description"><?php esc_html_e( 'Tip: after entering a key, click “Save settings”, then “Test connection”. You can keep keys out of the database with define( \'NTAA_OPENAI_KEY\', \'…\' ) (or NTAA_ANTHROPIC_KEY / NTAA_GEMINI_KEY) in wp-config.php.', 'nodethemes-analytics-ai' ); ?></p>
					</section>

					<section class="ntaa-card ntaa-tabpane" data-pane="report">
						<h2><?php esc_html_e( 'Scheduled reports by email', 'nodethemes-analytics-ai' ); ?></h2>
						<?php if ( NTAA_Pro::active() ) : ?>
							<?php self::checkbox( 'report_enabled', __( 'Email me a KPI report automatically', 'nodethemes-analytics-ai' ) ); ?>
							<?php self::checkbox( 'report_ai', __( 'Add an AI-written summary and recommendations (uses your AI provider)', 'nodethemes-analytics-ai' ) ); ?>
							<table class="form-table" role="presentation">
								<tr>
									<th scope="row"><label for="ntaa-freq"><?php esc_html_e( 'How often', 'nodethemes-analytics-ai' ); ?></label></th>
									<td>
										<select id="ntaa-freq" name="<?php echo esc_attr( self::field_name( 'report_freq' ) ); ?>">
											<option value="daily" <?php selected( $s['report_freq'], 'daily' ); ?>><?php esc_html_e( 'Daily', 'nodethemes-analytics-ai' ); ?></option>
											<option value="weekly" <?php selected( $s['report_freq'], 'weekly' ); ?>><?php esc_html_e( 'Weekly', 'nodethemes-analytics-ai' ); ?></option>
											<option value="monthly" <?php selected( $s['report_freq'], 'monthly' ); ?>><?php esc_html_e( 'Monthly', 'nodethemes-analytics-ai' ); ?></option>
										</select>
									</td>
								</tr>
								<tr>
									<th scope="row"><label for="ntaa-email"><?php esc_html_e( 'Send to', 'nodethemes-analytics-ai' ); ?></label></th>
									<td><input id="ntaa-email" type="text" name="<?php echo esc_attr( self::field_name( 'report_email' ) ); ?>" value="<?php echo esc_attr( $s['report_email'] ); ?>" class="regular-text" dir="ltr">
										<p class="description"><?php esc_html_e( 'Separate several addresses with commas.', 'nodethemes-analytics-ai' ); ?></p></td>
								</tr>
							</table>
							<?php
							$next = wp_next_scheduled( 'ntaap_ai_report' );
							if ( $next && ! empty( $s['report_enabled'] ) ) {
								/* translators: %s: date */
								echo '<p class="description">' . esc_html( sprintf( __( 'Next report: %s', 'nodethemes-analytics-ai' ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $next ) ) ) . '</p>';
							}
							do_action( 'ntaa_settings_report_after' );
							?>
						<?php else : ?>
							<?php // Keep the stored values when the form is saved without Pro. ?>
							<input type="hidden" name="<?php echo esc_attr( self::field_name( 'report_enabled' ) ); ?>" value="<?php echo esc_attr( (int) $s['report_enabled'] ); ?>">
							<input type="hidden" name="<?php echo esc_attr( self::field_name( 'report_ai' ) ); ?>" value="<?php echo esc_attr( (int) $s['report_ai'] ); ?>">
							<input type="hidden" name="<?php echo esc_attr( self::field_name( 'report_freq' ) ); ?>" value="<?php echo esc_attr( $s['report_freq'] ); ?>">
							<input type="hidden" name="<?php echo esc_attr( self::field_name( 'report_email' ) ); ?>" value="<?php echo esc_attr( $s['report_email'] ); ?>">
							<?php NTAA_Pro::upsell_box( __( 'Scheduled email reports are a Pro feature', 'nodethemes-analytics-ai' ) ); ?>
						<?php endif; ?>
					</section>

					<section class="ntaa-card ntaa-tabpane" data-pane="dev">
						<h2><?php esc_html_e( 'For developers', 'nodethemes-analytics-ai' ); ?></h2>
						<h3><?php esc_html_e( 'JavaScript', 'nodethemes-analytics-ai' ); ?></h3>
						<pre dir="ltr" class="ntaa-code">ntaa.track('Category', 'Action', 'Label', 49.99);   // event
ntaa.trackGoal(3, 25);                               // manual goal #3 worth 25
ntaa.trackOrder({ id: '1024', revenue: 125, items: [{ id: 'SKU1', name: 'Shoe', price: 125, qty: 1 }] }); // shops without WooCommerce
ntaa.setCustomDimension(1, 'gold-member');           // before or after the page view
ntaa.setConsent(true);                               // from your cookie banner
ntaa.optOut(); ntaa.optIn();</pre>
						<p><?php esc_html_e( 'Without code — add these attributes to any element:', 'nodethemes-analytics-ai' ); ?></p>
						<pre dir="ltr" class="ntaa-code">&lt;button data-ntaa-event="CTA|signup|header"&gt;Sign up&lt;/button&gt;
&lt;a href="/pricing" data-ntaa-goal="3"&gt;See prices&lt;/a&gt;</pre>
						<p><?php esc_html_e( 'Calls made before the script has loaded are queued:', 'nodethemes-analytics-ai' ); ?></p>
						<pre dir="ltr" class="ntaa-code">window.ntaa = window.ntaa || { q: [], track: function () { this.q.push(arguments); } };</pre>
						<h3>PHP</h3>
						<pre dir="ltr" class="ntaa-code">ntaa_track_event( 'Newsletter', 'signup', 'footer' );
ntaa_track_goal( 3, 25.00 );</pre>
						<p class="description"><?php esc_html_e( 'Filters such as ntaa_tracker_config, ntaa_report and ntaa_ai_context let you extend the plugin. See the user guide for the full list.', 'nodethemes-analytics-ai' ); ?></p>
					</section>

					<?php do_action( 'ntaa_settings_panes', $s ); ?>

					<?php submit_button( __( 'Save settings', 'nodethemes-analytics-ai' ) ); ?>
				</form>

				<section class="ntaa-card ntaa-geo-actions" data-pane-extra="geoip">
					<h2><?php esc_html_e( 'GeoIP database', 'nodethemes-analytics-ai' ); ?></h2>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ntaa-inline-form">
						<input type="hidden" name="action" value="ntaa_geoip_update">
						<?php wp_nonce_field( 'ntaa_geoip' ); ?>
						<button class="button button-primary" <?php disabled( in_array( $s['geoip_source'], array( 'upload', 'off' ), true ) ); ?>><?php esc_html_e( 'Download now', 'nodethemes-analytics-ai' ); ?></button>
						<span class="description"><?php esc_html_e( 'Downloads the database you saved above. It is also updated automatically every month.', 'nodethemes-analytics-ai' ); ?></span>
					</form>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data" class="ntaa-inline-form">
						<input type="hidden" name="action" value="ntaa_geoip_upload">
						<?php wp_nonce_field( 'ntaa_geoip' ); ?>
						<label><?php esc_html_e( 'Or upload a file', 'nodethemes-analytics-ai' ); ?> <input type="file" name="ntaa_mmdb" accept=".mmdb,.gz"></label>
						<button class="button"><?php esc_html_e( 'Upload', 'nodethemes-analytics-ai' ); ?></button>
						<span class="description"><?php esc_html_e( 'MaxMind / DB-IP / IPinfo .mmdb, .mmdb.gz or GeoLite2 .tar.gz', 'nodethemes-analytics-ai' ); ?></span>
					</form>
				</section>

				<section class="ntaa-card" data-pane-extra="performance">
					<h2><?php esc_html_e( 'Process the queue now', 'nodethemes-analytics-ai' ); ?></h2>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="ntaa_process_queue">
						<?php wp_nonce_field( 'ntaa_process_queue' ); ?>
						<button class="button"><?php esc_html_e( 'Process up to 20,000 hits now', 'nodethemes-analytics-ai' ); ?></button>
					</form>
				</section>

				<section class="ntaa-card" data-pane-extra="privacy">
					<h2><?php esc_html_e( 'Delete one visitor (GDPR request)', 'nodethemes-analytics-ai' ); ?></h2>
					<p class="description"><?php esc_html_e( 'WordPress’ own Tools › Erase Personal Data also removes the visits linked to a customer’s WooCommerce orders.', 'nodethemes-analytics-ai' ); ?></p>
					<p><input type="text" id="ntaa-gdpr-id" class="regular-text code" dir="ltr" maxlength="16" placeholder="<?php esc_attr_e( 'Visitor ID (16 characters)', 'nodethemes-analytics-ai' ); ?>"> <button type="button" class="button" id="ntaa-gdpr-go"><?php esc_html_e( 'Delete visitor', 'nodethemes-analytics-ai' ); ?></button> <span id="ntaa-gdpr-out" aria-live="polite"></span></p>
				</section>

				<section class="ntaa-card ntaa-danger" data-pane-extra="privacy">
					<h2><?php esc_html_e( 'Delete all data', 'nodethemes-analytics-ai' ); ?></h2>
					<p><?php esc_html_e( 'Deletes every recorded visit, pageview, event, conversion, order link and report archive. Settings, goals, segments and saved AI reports are kept. This cannot be undone.', 'nodethemes-analytics-ai' ); ?></p>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm(<?php echo esc_attr( wp_json_encode( __( 'Are you sure? All analytics data will be deleted.', 'nodethemes-analytics-ai' ) ) ); ?>);">
						<input type="hidden" name="action" value="ntaa_reset_data">
						<?php wp_nonce_field( 'ntaa_reset_data' ); ?>
						<button class="button button-link-delete"><?php esc_html_e( 'Delete all analytics data', 'nodethemes-analytics-ai' ); ?></button>
					</form>
				</section>

				<?php self::support_box(); ?>
			</div>
		</div>
		<?php
	}
}
