<?php
/**
 * Front-end tracker, collection endpoint, queue and hit processing.
 *
 * Two ways a hit is stored:
 * - Direct (default): the REST endpoint processes every hit immediately.
 * - High-traffic mode: hits are only appended to a queue table (one fast INSERT) and
 *   processed in batches every minute by cron.
 *
 * @package NodeThemes_Analytics_AI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Tracker.
 */
class NTAA_Tracker {

	const SESSION_TIMEOUT = 1800; // 30 minutes.

	/**
	 * Boot.
	 */
	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_action( 'ntaa_process_queue', array( __CLASS__, 'process_queue' ) );
		add_action( 'template_redirect', array( __CLASS__, 'log_crawler' ), 1 );
	}

	/* ---------------------------------------------------------------
	 * Front-end script
	 * ------------------------------------------------------------- */

	/**
	 * Should this page view be tracked?
	 *
	 * @return bool
	 */
	public static function should_track() {
		if ( ! NTAA_Settings::get( 'enabled' ) ) {
			return false;
		}
		if ( is_admin() || is_preview() || is_feed() || is_robots() || is_trackback() || is_customize_preview() ) {
			return false;
		}
		if ( is_user_logged_in() ) {
			$user     = wp_get_current_user();
			$excluded = (array) NTAA_Settings::get( 'exclude_roles', array() );
			if ( array_intersect( (array) $user->roles, $excluded ) ) {
				return false;
			}
		}
		return (bool) apply_filters( 'ntaa_should_track', true );
	}

	/**
	 * Endpoint URL the tracker sends to.
	 *
	 * @return string
	 */
	public static function endpoint() {
		return esc_url_raw( rest_url( 'ntaa/v1/collect' ) );
	}

	/**
	 * Tracker configuration for the current page.
	 *
	 * @return array
	 */
	public static function config() {
		$s      = NTAA_Settings::all();
		$config = array(
			'v'          => NTAA_VERSION,
			'endpoint'   => self::endpoint(),
			's'          => 1,
			'cookieless' => (int) $s['cookieless'],
			'consent'    => (string) $s['consent'],
			'dnt'        => (int) $s['respect_dnt'],
			'outbound'   => (int) $s['track_outbound'],
			'downloads'  => (int) $s['track_downloads'],
			'forms'      => (int) $s['track_forms'],
			'spa'        => (int) $s['track_spa'],
			'vitals'     => (int) $s['track_vitals'],
			'pid'        => is_singular() ? (int) get_queried_object_id() : 0,
		);
		if ( $s['track_search'] && is_search() ) {
			global $wp_query;
			$pt               = get_query_var( 'post_type' );
			$config['search'] = array(
				'k'   => mb_substr( (string) get_search_query( false ), 0, 190 ),
				'c'   => isset( $wp_query->found_posts ) ? (int) $wp_query->found_posts : -1,
				'cat' => is_string( $pt ) && $pt ? sanitize_key( $pt ) : '',
			);
		}
		$dims = apply_filters( 'ntaa_custom_dimensions', array() );
		if ( is_array( $dims ) && $dims ) {
			$config['cd'] = array();
			foreach ( $dims as $i => $val ) {
				$i = (int) $i;
				if ( $i >= 1 && $i <= 5 && '' !== (string) $val ) {
					$config['cd'][ $i ] = mb_substr( wp_strip_all_tags( (string) $val ), 0, 190 );
				}
			}
		}
		return apply_filters( 'ntaa_tracker_config', $config );
	}

	/**
	 * Enqueue the tracker.
	 */
	public static function enqueue() {
		if ( ! self::should_track() ) {
			return;
		}
		$args = array( 'in_footer' => true );
		if ( version_compare( get_bloginfo( 'version' ), '6.3', '>=' ) ) {
			$args['strategy'] = 'defer';
		}
		wp_enqueue_script( 'ntaa-tracker', NTAA_URL . 'assets/js/tracker.js', array(), NTAA_VERSION, $args );
		wp_add_inline_script( 'ntaa-tracker', 'window.NTAAConfig = ' . wp_json_encode( self::config() ) . ';', 'before' );
		do_action( 'ntaa_enqueue_tracker' );
	}

	/* ---------------------------------------------------------------
	 * Collection endpoint
	 * ------------------------------------------------------------- */

	/**
	 * Routes.
	 */
	public static function routes() {
		register_rest_route(
			'ntaa/v1',
			'/collect',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'collect' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Empty, uncached response.
	 *
	 * @return WP_REST_Response
	 */
	private static function respond() {
		$res = new WP_REST_Response( array( 'ok' => 1 ), 200 );
		$res->header( 'Cache-Control', 'no-store, no-cache, must-revalidate' );
		return $res;
	}

	/**
	 * POST /ntaa/v1/collect.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function collect( WP_REST_Request $request ) {
		$rt   = NTAA_Settings::runtime();
		$hits = NTAA_Hit::receive( $request->get_body(), $_SERVER, $rt, $_COOKIE ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( ! $hits ) {
			return self::respond();
		}
		if ( ! empty( $rt['queue'] ) ) {
			self::enqueue_hits( $hits );
			return self::respond();
		}
		foreach ( $hits as $hit ) {
			self::process( $hit );
		}
		return self::respond();
	}

	/**
	 * Append hits to the queue (one INSERT).
	 *
	 * @param array $hits Hits.
	 */
	public static function enqueue_hits( $hits ) {
		global $wpdb;
		$t      = NTAA_DB::t( 'queue' );
		$now    = current_time( 'mysql' );
		$values = array();
		foreach ( $hits as $hit ) {
			$values[] = $wpdb->prepare( '(%s,%s)', $now, wp_json_encode( $hit ) );
		}
		if ( $values ) {
			$wpdb->query( "INSERT INTO {$t} (received_at, payload) VALUES " . implode( ',', $values ) ); // phpcs:ignore WordPress.DB
		}
	}

	/**
	 * Process queued hits (cron, every minute). Runs for at most ~25 seconds.
	 *
	 * @param int $max Max hits.
	 * @return int Processed hits.
	 */
	public static function process_queue( $max = 20000 ) {
		global $wpdb;
		$t    = NTAA_DB::t( 'queue' );
		$lock = $wpdb->get_var( "SELECT GET_LOCK('ntaa_queue', 0)" ); // phpcs:ignore WordPress.DB
		if ( '0' === (string) $lock ) {
			return 0;
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 60 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		$start = microtime( true );
		$done  = 0;
		do {
			$rows = $wpdb->get_results( "SELECT id, payload FROM {$t} ORDER BY id ASC LIMIT 500" ); // phpcs:ignore WordPress.DB
			if ( ! $rows ) {
				break;
			}
			$ids = array();
			foreach ( $rows as $r ) {
				$hit = json_decode( $r->payload, true );
				if ( is_array( $hit ) ) {
					try {
						self::process( $hit );
					} catch ( Throwable $e ) { // phpcs:ignore PHPCompatibility.Classes.NewClasses.throwableFound
						// A broken hit must never block the queue.
						unset( $e );
					}
				}
				$ids[] = (int) $r->id;
				++$done;
			}
			$wpdb->query( "DELETE FROM {$t} WHERE id IN (" . implode( ',', $ids ) . ')' ); // phpcs:ignore WordPress.DB
		} while ( $done < $max && ( microtime( true ) - $start ) < 25 );

		$backlog = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t}" ); // phpcs:ignore WordPress.DB
		update_option( 'ntaa_queue_backlog', $backlog, false );
		$wpdb->query( "SELECT RELEASE_LOCK('ntaa_queue')" ); // phpcs:ignore WordPress.DB
		return $done;
	}

	/* ---------------------------------------------------------------
	 * Processing
	 * ------------------------------------------------------------- */

	/**
	 * Store one hit.
	 *
	 * @param array $hit Normalised hit (see NTAA_Hit::receive()).
	 */
	public static function process( $hit ) {
		if ( empty( $hit['t'] ) || empty( $hit['h'] ) ) {
			return;
		}
		$hit['ts'] = isset( $hit['ts'] ) ? (int) $hit['ts'] : time();
		$hit['s']  = isset( $hit['s'] ) ? max( 1, (int) $hit['s'] ) : 1;
		$d         = isset( $hit['d'] ) && is_array( $hit['d'] ) ? $hit['d'] : array();
		switch ( $hit['t'] ) {
			case 'pv':
				self::pageview( $hit, $d );
				break;
			case 'leave':
				self::leave( $hit, $d );
				break;
			case 'ev':
				self::event( $hit, $d );
				break;
			case 'goal':
				$visit = self::visit_for_hit( $hit, $d );
				if ( $visit ) {
					NTAA_Goals::manual( $visit, isset( $d['g'] ) ? (int) $d['g'] : 0, isset( $d['v'] ) && is_numeric( $d['v'] ) ? (float) $d['v'] : null, $hit['ts'] );
				}
				break;
			case 'order':
				$visit = self::visit_for_hit( $hit, $d );
				NTAA_Ecommerce::record_js_order( $visit, $hit['s'], $d, $hit['ts'] );
				break;
			default:
				$visit = self::visit_for_hit( $hit, $d );
				do_action( 'ntaa_process_' . $hit['t'], $hit, $visit );
		}
		self::maybe_invalidate( $hit['s'], $hit['ts'] );
	}

	/**
	 * A late hit for an already archived day re-opens that day's archive.
	 *
	 * @param int $site Site.
	 * @param int $ts   Time.
	 */
	private static function maybe_invalidate( $site, $ts ) {
		$day = wp_date( 'Y-m-d', $ts );
		if ( $day < wp_date( 'Y-m-d' ) ) {
			NTAA_Archive::invalidate( $site, $day );
		}
	}

	/**
	 * Visit a hit belongs to: through its page view id, or the visitor's current visit.
	 *
	 * @param array $hit Hit.
	 * @param array $d   Payload.
	 * @return object|null
	 */
	public static function visit_for_hit( $hit, $d ) {
		global $wpdb;
		$uid = isset( $d['pv'] ) ? self::uid( $d['pv'] ) : '';
		if ( $uid ) {
			$tp  = NTAA_DB::t( 'pageviews' );
			$tv  = NTAA_DB::t( 'visits' );
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT v.* FROM {$tp} p INNER JOIN {$tv} v ON v.id = p.visit_id WHERE p.pv_uid = %s", $uid ) ); // phpcs:ignore WordPress.DB
			if ( $row ) {
				return $row;
			}
		}
		return self::find_visit( $hit['s'], $hit['h'], $hit['ts'] );
	}

	/**
	 * Open visit of a visitor.
	 *
	 * @param int    $site Site.
	 * @param string $hash Visitor hash.
	 * @param int    $ts   Time.
	 * @param int    $window Seconds.
	 * @return object|null
	 */
	public static function find_visit( $site, $hash, $ts, $window = self::SESSION_TIMEOUT ) {
		global $wpdb;
		$t     = NTAA_DB::t( 'visits' );
		$since = wp_date( 'Y-m-d H:i:s', $ts - $window );
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE visitor_hash = %s AND site_id = %d AND last_activity >= %s ORDER BY id DESC LIMIT 1", $hash, $site, $since ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Valid page view uid or ''.
	 *
	 * @param mixed $v Value.
	 * @return string
	 */
	public static function uid( $v ) {
		$v = strtolower( preg_replace( '/[^a-fA-F0-9]/', '', (string) $v ) );
		return 32 === strlen( $v ) ? $v : '';
	}

	/**
	 * Clean text.
	 *
	 * @param mixed $v   Value.
	 * @param int   $len Max length.
	 * @return string
	 */
	public static function clean_text( $v, $len = 255 ) {
		return mb_substr( sanitize_text_field( is_scalar( $v ) ? (string) $v : '' ), 0, $len );
	}

	/**
	 * Path + meaningful query string (tracking parameters removed).
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public static function clean_path( $url ) {
		$parts = wp_parse_url( (string) $url );
		$path  = isset( $parts['path'] ) ? $parts['path'] : '/';
		$path  = '/' . ltrim( rawurldecode( $path ), '/' );
		if ( ! empty( $parts['query'] ) ) {
			parse_str( $parts['query'], $q );
			$drop = array( 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'utm_id', 'gclid', 'gbraid', 'wbraid', 'fbclid', 'msclkid', 'yclid', 'ttclid', 'twclid', 'li_fat_id', 'mc_cid', 'mc_eid', '_ga', '_gl', 'ref', 'mtm_source', 'mtm_campaign', 'mtm_medium', 'mtm_keyword', 'mtm_content', 'srsltid', 'ntaa_ab', 'ntaa_debug' );
			foreach ( $drop as $k ) {
				unset( $q[ $k ] );
			}
			if ( $q ) {
				$path .= '?' . urldecode( http_build_query( $q ) );
			}
		}
		$path = wp_check_invalid_utf8( $path, true );
		$path = (string) preg_replace( '/[\x00-\x1F\x7F<>"\\\\]/u', '', $path );
		return mb_substr( trim( $path ), 0, 255 );
	}

	/**
	 * Campaign parameters of a URL.
	 *
	 * @param string $url URL.
	 * @return array
	 */
	public static function utm( $url ) {
		$parts = wp_parse_url( (string) $url );
		$q     = array();
		if ( ! empty( $parts['query'] ) ) {
			parse_str( $parts['query'], $q );
		}
		$g   = function ( $a, $b, $len = 100 ) use ( $q ) {
			$v = isset( $q[ $a ] ) ? $q[ $a ] : ( isset( $q[ $b ] ) ? $q[ $b ] : '' );
			return is_string( $v ) ? mb_substr( sanitize_text_field( $v ), 0, $len ) : '';
		};
		$out = array(
			'utm_source'   => $g( 'utm_source', 'mtm_source' ),
			'utm_medium'   => $g( 'utm_medium', 'mtm_medium' ),
			'utm_campaign' => $g( 'utm_campaign', 'mtm_campaign', 150 ),
			'utm_term'     => $g( 'utm_term', 'mtm_keyword' ),
			'utm_content'  => $g( 'utm_content', 'mtm_content' ),
		);
		// Paid clicks without UTM tags.
		if ( '' === $out['utm_source'] ) {
			if ( ! empty( $q['gclid'] ) || ! empty( $q['gbraid'] ) || ! empty( $q['wbraid'] ) ) {
				$out['utm_source'] = 'google';
				$out['utm_medium'] = 'cpc';
			} elseif ( ! empty( $q['msclkid'] ) ) {
				$out['utm_source'] = 'bing';
				$out['utm_medium'] = 'cpc';
			} elseif ( ! empty( $q['ttclid'] ) ) {
				$out['utm_source'] = 'tiktok';
				$out['utm_medium'] = 'cpc';
			}
		}
		return $out;
	}

	/**
	 * Own hosts (never counted as referrers).
	 *
	 * @param int $site Site.
	 * @return array
	 */
	private static function own_hosts( $site ) {
		$rt = NTAA_Settings::runtime();
		if ( $site > 1 && isset( $rt['sites'][ $site ] ) ) {
			return (array) $rt['sites'][ $site ];
		}
		return array( (string) $rt['home_host'] );
	}

	/* ---------- page view ---------- */

	/**
	 * Store a page view (and open a visit when needed).
	 *
	 * @param array $hit Hit.
	 * @param array $d   Payload.
	 */
	private static function pageview( $hit, $d ) {
		global $wpdb;
		$tv   = NTAA_DB::t( 'visits' );
		$tp   = NTAA_DB::t( 'pageviews' );
		$ts   = $hit['ts'];
		$site = $hit['s'];
		$now  = wp_date( 'Y-m-d H:i:s', $ts );
		$uid  = isset( $d['pv'] ) ? self::uid( $d['pv'] ) : '';
		if ( '' === $uid ) {
			$uid = bin2hex( random_bytes( 16 ) );
		} elseif ( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$tp} WHERE pv_uid = %s", $uid ) ) ) { // phpcs:ignore WordPress.DB
			return; // Duplicate.
		}
		$url  = isset( $d['u'] ) ? (string) $d['u'] : '/';
		$page = self::clean_path( $url );
		$utm  = self::utm( $url );

		$visit = self::find_visit( $site, $hit['h'], $ts );
		if ( $visit && '' !== $utm['utm_campaign'] && $utm['utm_campaign'] !== $visit->utm_campaign ) {
			$visit = null; // A new campaign click starts a new visit.
		}

		$dims = array();
		if ( isset( $d['cd'] ) && is_array( $d['cd'] ) ) {
			foreach ( $d['cd'] as $i => $val ) {
				$i = (int) $i;
				if ( $i >= 1 && $i <= 5 && is_scalar( $val ) && '' !== (string) $val ) {
					$dims[ 'dim' . $i ] = self::clean_text( $val, 191 );
				}
			}
		}

		if ( ! $visit ) {
			$visit_id = self::create_visit( $hit, $d, $page, $utm, $dims, $now );
			if ( ! $visit_id ) {
				return;
			}
			$visit = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tv} WHERE id = %d", $visit_id ) ); // phpcs:ignore WordPress.DB
		} else {
			$visit_id = (int) $visit->id;
			$elapsed  = max( (int) $visit->duration, $ts - strtotime( $visit->started_at . ' ' . wp_timezone_string() ) );
			$set      = array(
				'last_activity' => max( $visit->last_activity, $now ),
				'exit_page'     => $page,
				'pageviews'     => (int) $visit->pageviews + 1,
				'duration'      => max( 0, $elapsed ),
			);
			$wpdb->update( $tv, array_merge( $set, $dims ), array( 'id' => $visit_id ) ); // phpcs:ignore WordPress.DB
			foreach ( array_merge( $set, $dims ) as $k => $v ) {
				$visit->$k = $v;
			}
		}

		$search_term  = '';
		$search_count = -1;
		$search_cat   = '';
		if ( isset( $d['sr'] ) && is_array( $d['sr'] ) && isset( $d['sr']['k'] ) ) {
			$search_term  = mb_strtolower( self::clean_text( $d['sr']['k'], 191 ) );
			$search_count = isset( $d['sr']['c'] ) && is_numeric( $d['sr']['c'] ) ? (int) $d['sr']['c'] : -1;
			$search_cat   = isset( $d['sr']['cat'] ) ? sanitize_key( (string) $d['sr']['cat'] ) : '';
		} else {
			$q = array();
			parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $q );
			if ( ! empty( $q['s'] ) && is_string( $q['s'] ) ) {
				$search_term = mb_strtolower( self::clean_text( $q['s'], 191 ) );
			}
		}

		$wpdb->insert( // phpcs:ignore WordPress.DB
			$tp,
			array(
				'site_id'      => $site,
				'visit_id'     => $visit_id,
				'pv_uid'       => $uid,
				'page'         => $page,
				'title'        => self::clean_text( isset( $d['ti'] ) ? $d['ti'] : '' ),
				'post_id'      => isset( $d['pid'] ) ? absint( $d['pid'] ) : 0,
				'created_at'   => $now,
				'search_term'  => $search_term,
				'search_count' => $search_count,
				'search_cat'   => mb_substr( $search_cat, 0, 40 ),
			)
		);

		NTAA_Goals::on_pageview( $visit, $page, $ts );
	}

	/**
	 * Create a visit row.
	 *
	 * @param array  $hit  Hit.
	 * @param array  $d    Payload.
	 * @param string $page Entry page.
	 * @param array  $utm  Campaign.
	 * @param array  $dims Custom dimensions.
	 * @param string $now  Local datetime.
	 * @return int
	 */
	private static function create_visit( $hit, $d, $page, $utm, $dims, $now ) {
		global $wpdb;
		$tv       = NTAA_DB::t( 'visits' );
		$site     = $hit['s'];
		$ref      = isset( $d['r'] ) ? esc_url_raw( (string) $d['r'] ) : '';
		$ref_host = $ref ? strtolower( (string) wp_parse_url( $ref, PHP_URL_HOST ) ) : '';
		$ref_host = preg_replace( '/^www\./', '', $ref_host );
		foreach ( self::own_hosts( $site ) as $own ) {
			if ( '' !== $ref_host && ( $ref_host === $own || substr( $ref_host, -strlen( '.' . $own ) ) === '.' . $own ) ) {
				$ref      = '';
				$ref_host = '';
				break;
			}
		}

		$ai_src = NTAA_UA::ai_referrer( $utm['utm_source'] );
		if ( '' !== $ai_src && '' === $utm['utm_campaign'] ) {
			// e.g. ChatGPT adds utm_source=chatgpt.com to its links.
			$ref_type = 'ai';
			if ( '' === $ref_host ) {
				$ref_host = strtolower( preg_replace( '/^www\./', '', $utm['utm_source'] ) );
			}
		} elseif ( '' !== $utm['utm_source'] || '' !== $utm['utm_campaign'] ) {
			$ref_type = 'campaign';
		} else {
			$ref_type = NTAA_UA::referrer_type( $ref_host );
		}

		$ua  = isset( $hit['ua'] ) && is_array( $hit['ua'] ) ? $hit['ua'] : array( 'browser' => '', 'os' => '', 'device' => '' );
		$geo = isset( $hit['geo'] ) && is_array( $hit['geo'] ) ? $hit['geo'] : array( 'country' => '', 'region' => '', 'city' => '' );

		$is_new       = 1;
		$visit_number = 1;
		$days_since   = 0;
		if ( '' !== $hit['vid'] ) {
			$prev = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) AS n, MAX(started_at) AS last FROM {$tv} WHERE visitor_id = %s", $hit['vid'] ) ); // phpcs:ignore WordPress.DB
			if ( $prev && (int) $prev->n > 0 ) {
				$is_new       = 0;
				$visit_number = (int) $prev->n + 1;
				$days_since   = max( 0, (int) floor( ( strtotime( $now ) - strtotime( (string) $prev->last ) ) / DAY_IN_SECONDS ) );
			}
		}
		$sw = isset( $d['sw'] ) ? absint( $d['sw'] ) : 0;
		$sh = isset( $d['sh'] ) ? absint( $d['sh'] ) : 0;
		$lh = isset( $d['lh'] ) && is_numeric( $d['lh'] ) ? (int) $d['lh'] : 255;

		$row = array_merge(
			array(
				'site_id'       => $site,
				'visitor_hash'  => $hit['h'],
				'visitor_id'    => (string) $hit['vid'],
				'started_at'    => $now,
				'last_activity' => $now,
				'entry_page'    => $page,
				'exit_page'     => $page,
				'referrer'      => mb_substr( $ref, 0, 255 ),
				'referrer_host' => mb_substr( $ref_host, 0, 191 ),
				'referrer_type' => $ref_type,
				'utm_source'    => $utm['utm_source'],
				'utm_medium'    => $utm['utm_medium'],
				'utm_campaign'  => $utm['utm_campaign'],
				'utm_term'      => $utm['utm_term'],
				'utm_content'   => $utm['utm_content'],
				'device'        => mb_substr( (string) $ua['device'], 0, 20 ),
				'browser'       => mb_substr( (string) $ua['browser'], 0, 40 ),
				'os'            => mb_substr( (string) $ua['os'], 0, 40 ),
				'screen'        => ( $sw && $sh && $sw < 20000 && $sh < 20000 ) ? $sw . 'x' . $sh : '',
				'language'      => isset( $d['l'] ) ? strtolower( substr( preg_replace( '/[^A-Za-z-]/', '', (string) $d['l'] ), 0, 10 ) ) : '',
				'country'       => (string) $geo['country'],
				'region'        => self::clean_text( $geo['region'], 80 ),
				'city'          => self::clean_text( $geo['city'], 80 ),
				'local_hour'    => ( $lh >= 0 && $lh <= 23 ) ? $lh : 255,
				'pageviews'     => 1,
				'events'        => 0,
				'duration'      => 0,
				'is_new'        => $is_new,
				'visit_number'  => $visit_number,
				'days_since_last' => $days_since,
			),
			$dims
		);
		$wpdb->insert( $tv, $row ); // phpcs:ignore WordPress.DB
		return (int) $wpdb->insert_id;
	}

	/* ---------- leave: engagement time, scroll, load time, Web Vitals ---------- */

	/**
	 * Update a page view when the visitor leaves it (or hides the tab).
	 *
	 * @param array $hit Hit.
	 * @param array $d   Payload.
	 */
	private static function leave( $hit, $d ) {
		global $wpdb;
		$uid = isset( $d['pv'] ) ? self::uid( $d['pv'] ) : '';
		if ( '' === $uid ) {
			return;
		}
		$tp  = NTAA_DB::t( 'pageviews' );
		$tv  = NTAA_DB::t( 'visits' );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT id, visit_id, time_on_page, scroll_depth, load_time FROM {$tp} WHERE pv_uid = %s", $uid ) ); // phpcs:ignore WordPress.DB
		if ( ! $row ) {
			return;
		}
		$time = min( 3600, isset( $d['tm'] ) ? absint( $d['tm'] ) : 0 );
		$sc   = min( 100, isset( $d['sc'] ) ? absint( $d['sc'] ) : 0 );
		$lt   = min( 120000, isset( $d['lt'] ) ? absint( $d['lt'] ) : 0 );
		$set  = array(
			'time_on_page' => max( (int) $row->time_on_page, $time ),
			'scroll_depth' => max( (int) $row->scroll_depth, $sc ),
			'load_time'    => (int) $row->load_time ? (int) $row->load_time : $lt,
		);
		if ( isset( $d['vt'] ) && is_array( $d['vt'] ) ) {
			$vt  = $d['vt'];
			$num = function ( $k, $max ) use ( $vt ) {
				return isset( $vt[ $k ] ) && is_numeric( $vt[ $k ] ) ? max( 0, min( $max, (int) round( (float) $vt[ $k ] ) ) ) : 0;
			};
			$set['lcp']    = $num( 'lcp', 120000 );
			$set['cls']    = isset( $vt['cls'] ) && is_numeric( $vt['cls'] ) ? max( 0, min( 65000, (int) round( (float) $vt['cls'] * 1000 ) ) ) : 0;
			$set['inp']    = $num( 'inp', 60000 );
			$set['fcp']    = $num( 'fcp', 120000 );
			$set['ttfb']   = $num( 'ttfb', 120000 );
			$set['vitals'] = ( $set['lcp'] || $set['fcp'] || $set['ttfb'] ) ? 1 : 0;
		}
		$wpdb->update( $tp, $set, array( 'id' => (int) $row->id ) ); // phpcs:ignore WordPress.DB

		$visit = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tv} WHERE id = %d", (int) $row->visit_id ) ); // phpcs:ignore WordPress.DB
		if ( $visit ) {
			$now  = wp_date( 'Y-m-d H:i:s', $hit['ts'] );
			$dur  = max( (int) $visit->duration, $hit['ts'] - strtotime( $visit->started_at . ' ' . wp_timezone_string() ) );
			$last = max( $visit->last_activity, $now );
			$wpdb->update( $tv, array( 'last_activity' => $last, 'duration' => max( 0, $dur ) ), array( 'id' => (int) $visit->id ) ); // phpcs:ignore WordPress.DB
			$visit->duration      = max( 0, $dur );
			$visit->last_activity = $last;
			NTAA_Goals::on_engagement( $visit, $hit['ts'] );
		}
	}

	/* ---------- events ---------- */

	/**
	 * Store an event.
	 *
	 * @param array $hit Hit.
	 * @param array $d   Payload.
	 */
	private static function event( $hit, $d ) {
		$visit = self::visit_for_hit( $hit, $d );
		if ( ! $visit ) {
			return;
		}
		self::store_event(
			$visit,
			isset( $d['c'] ) ? $d['c'] : '',
			isset( $d['a'] ) ? $d['a'] : '',
			isset( $d['n'] ) ? $d['n'] : '',
			isset( $d['v'] ) ? $d['v'] : null,
			isset( $d['rid'] ) ? absint( $d['rid'] ) : 0,
			isset( $d['u'] ) ? self::clean_path( $d['u'] ) : '',
			$hit['ts']
		);
	}

	/**
	 * Insert an event row and update its visit.
	 *
	 * @param object     $visit Visit.
	 * @param string     $cat   Category.
	 * @param string     $act   Action.
	 * @param string     $label Label.
	 * @param mixed      $value Value.
	 * @param int        $ref   Reference id (product id…).
	 * @param string     $page  Page.
	 * @param int        $ts    Time.
	 * @return bool
	 */
	public static function store_event( $visit, $cat, $act, $label, $value, $ref, $page, $ts ) {
		global $wpdb;
		$cat = self::clean_text( $cat, 100 );
		$act = self::clean_text( $act, 150 );
		if ( '' === $cat || '' === $act || ! $visit ) {
			return false;
		}
		$now = wp_date( 'Y-m-d H:i:s', $ts );
		$row = array(
			'site_id'    => (int) $visit->site_id,
			'visit_id'   => (int) $visit->id,
			'category'   => $cat,
			'action'     => $act,
			'label'      => self::clean_text( $label ),
			'ref_id'     => (int) $ref,
			'page'       => '' !== $page ? $page : (string) $visit->exit_page,
			'created_at' => $now,
		);
		if ( null !== $value && is_numeric( $value ) && is_finite( (float) $value ) ) {
			$row['value'] = (float) $value;
		}
		$wpdb->insert( NTAA_DB::t( 'events' ), $row ); // phpcs:ignore WordPress.DB
		$tv = NTAA_DB::t( 'visits' );
		$wpdb->query( $wpdb->prepare( "UPDATE {$tv} SET events = events + 1, last_activity = GREATEST(last_activity, %s) WHERE id = %d", $now, (int) $visit->id ) ); // phpcs:ignore WordPress.DB
		NTAA_Goals::on_event( $visit, $row, $ts );
		return true;
	}

	/* ---------------------------------------------------------------
	 * Server side: the visit of the current request (WooCommerce, PHP API)
	 * ------------------------------------------------------------- */

	/**
	 * Find the visit of the person making the current request.
	 *
	 * @param int $site   Site.
	 * @param int $window Look-back in seconds.
	 * @return object|null
	 */
	public static function current_visit( $site = 1, $window = self::SESSION_TIMEOUT ) {
		$rt = NTAA_Settings::runtime();
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( (string) wp_unslash( $_SERVER['HTTP_USER_AGENT'] ), 0, 500 ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( '' === $ua || NTAA_UA::is_bot( $ua ) ) {
			return null;
		}
		$hashes = array();
		if ( empty( $rt['cookieless'] ) && ! empty( $_COOKIE['ntaa_vid'] ) ) {
			$vid = strtolower( preg_replace( '/[^a-fA-F0-9]/', '', (string) wp_unslash( $_COOKIE['ntaa_vid'] ) ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			if ( 16 === strlen( $vid ) ) {
				$hashes[] = md5( $rt['secret'] . '|vid|' . $vid );
			}
		}
		$ip = NTAA_Hit::ip( $_SERVER, ! empty( $rt['trust_proxy'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( '' !== $ip ) {
			$hashes[] = NTAA_Hit::daily_hash( $ip, $ua, $site, $rt['secret'], $rt, 0 );
			$prev     = NTAA_Hit::daily_salt( $rt, -1 );
			if ( '' !== $prev ) {
				$hashes[] = NTAA_Hit::daily_hash( $ip, $ua, $site, $rt['secret'], $rt, -1 );
			}
		}
		foreach ( $hashes as $h ) {
			$v = self::find_visit( $site, $h, time(), $window );
			if ( $v ) {
				return $v;
			}
		}
		return null;
	}

	/**
	 * Record an event for the current visitor from PHP (e.g. "add to cart").
	 *
	 * @param string $cat   Category.
	 * @param string $act   Action.
	 * @param string $label Label.
	 * @param mixed  $value Value.
	 * @param int    $ref   Reference id.
	 * @return bool
	 */
	public static function server_event( $cat, $act, $label = '', $value = null, $ref = 0 ) {
		if ( ! NTAA_Settings::get( 'enabled' ) ) {
			return false;
		}
		$visit = self::current_visit();
		if ( ! $visit ) {
			return false;
		}
		return self::store_event( $visit, $cat, $act, $label, $value, $ref, '', time() );
	}

	/* ---------------------------------------------------------------
	 * Crawlers & AI agents
	 * ------------------------------------------------------------- */

	/**
	 * Count visits of known crawlers and AI agents (they never run JavaScript, so the tracker cannot see them).
	 */
	public static function log_crawler() {
		if ( ! NTAA_Settings::get( 'track_bots' ) || is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return;
		}
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$c  = NTAA_UA::crawler( $ua );
		if ( ! $c ) {
			return;
		}
		global $wpdb;
		$t   = NTAA_DB::t( 'bots' );
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? self::clean_path( (string) wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$now = current_time( 'mysql' );
		$wpdb->query( $wpdb->prepare( "INSERT INTO {$t} (day, bot, kind, hits, last_url, last_seen) VALUES (%s, %s, %s, 1, %s, %s) ON DUPLICATE KEY UPDATE hits = hits + 1, last_url = VALUES(last_url), last_seen = VALUES(last_seen)", substr( $now, 0, 10 ), $c[0], $c[1], $uri, $now ) ); // phpcs:ignore WordPress.DB
	}
}
