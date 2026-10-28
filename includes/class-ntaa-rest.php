<?php
/**
 * Admin REST API used by the dashboard (all routes need a logged-in user with the right capability).
 *
 * @package NodeThemes_Analytics_AI
 */

defined( 'ABSPATH' ) || exit;

/**
 * REST.
 */
class NTAA_Rest {

	const NS = 'ntaa/v1';

	/**
	 * Boot.
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	/**
	 * Can view analytics.
	 *
	 * @return bool
	 */
	public static function can_view() {
		return ntaa_user_can_view();
	}

	/**
	 * Can use AI.
	 *
	 * @return bool
	 */
	public static function can_ai() {
		return ntaa_user_can_view() && ( current_user_can( NTAA_CAP_AI ) || current_user_can( 'manage_options' ) );
	}

	/**
	 * Administrator.
	 *
	 * @return bool
	 */
	public static function can_manage() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Register a route.
	 *
	 * @param string       $path    Path.
	 * @param string       $methods Methods.
	 * @param callable     $cb      Callback.
	 * @param string|array $perm    Permission method name.
	 */
	private static function route( $path, $methods, $cb, $perm = 'can_view' ) {
		register_rest_route(
			self::NS,
			$path,
			array(
				'methods'             => $methods,
				'callback'            => $cb,
				'permission_callback' => array( __CLASS__, $perm ),
			)
		);
	}

	/**
	 * Routes.
	 */
	public static function routes() {
		self::route( '/stats', 'GET', array( __CLASS__, 'stats' ) );
		self::route( '/realtime', 'GET', array( __CLASS__, 'realtime' ) );
		self::route( '/visits', 'GET', array( __CLASS__, 'visits' ) );
		self::route( '/visits/(?P<id>\d+)', 'GET', array( __CLASS__, 'visit' ) );
		self::route( '/visitor/(?P<vid>[a-f0-9]{16})', 'GET', array( __CLASS__, 'visitor' ) );
		self::route( '/transitions', 'GET', array( __CLASS__, 'transitions' ) );
		self::route( '/evolution', 'GET', array( __CLASS__, 'evolution' ) );
		self::route( '/dashboard-layout', 'GET', array( __CLASS__, 'get_layout' ) );
		self::route( '/dashboard-layout', 'POST', array( __CLASS__, 'save_layout' ) );
		self::route( '/crawlers', 'GET', array( __CLASS__, 'crawlers' ) );
		self::route( '/annotations', 'GET', array( __CLASS__, 'annotations' ) );
		self::route( '/annotations', 'POST', array( __CLASS__, 'add_annotation' ) );
		self::route( '/annotations/(?P<id>\d+)', 'DELETE', array( __CLASS__, 'delete_annotation' ) );
		self::route( '/goals', 'GET', array( __CLASS__, 'goals' ) );
		self::route( '/goals', 'POST', array( __CLASS__, 'save_goal' ), 'can_manage' );
		self::route( '/goals/(?P<id>\d+)', 'DELETE', array( __CLASS__, 'delete_goal' ), 'can_manage' );
		self::route( '/goals/(?P<id>\d+)/recalc', 'POST', array( __CLASS__, 'recalc_goal' ), 'can_manage' );
		self::route( '/goals/(?P<id>\d+)/report', 'GET', array( __CLASS__, 'goal_report' ) );
		self::route( '/segments', 'GET', array( __CLASS__, 'segments' ) );
		self::route( '/segments', 'POST', array( __CLASS__, 'save_segment' ) );
		self::route( '/segments/(?P<id>\d+)', 'DELETE', array( __CLASS__, 'delete_segment' ) );
		self::route( '/gdpr/delete-visitor', 'POST', array( __CLASS__, 'gdpr_delete' ), 'can_manage' );
		self::route( '/ai/analyze', 'POST', array( __CLASS__, 'analyze' ), 'can_ai' );
		self::route( '/ai/reports', 'GET', array( __CLASS__, 'reports' ), 'can_ai' );
		self::route( '/ai/reports/(?P<id>\d+)', 'GET', array( __CLASS__, 'report' ), 'can_ai' );
		self::route( '/ai/reports/(?P<id>\d+)', 'DELETE', array( __CLASS__, 'delete_report' ), 'can_manage' );
		self::route( '/ai/test', 'POST', array( __CLASS__, 'test' ), 'can_manage' );
		do_action( 'ntaa_rest_routes', self::NS );
	}

	/**
	 * Uncached response.
	 *
	 * @param mixed $data Data.
	 * @return WP_REST_Response
	 */
	public static function ok( $data ) {
		$r = rest_ensure_response( $data );
		$r->header( 'Cache-Control', 'no-store' );
		return $r;
	}

	/**
	 * WP_Error with an HTTP status.
	 *
	 * @param WP_Error $e Error.
	 * @return WP_Error
	 */
	public static function error( WP_Error $e ) {
		$data = $e->get_error_data();
		$code = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 400;
		return new WP_Error( $e->get_error_code(), $e->get_error_message(), array( 'status' => $code ) );
	}

	/**
	 * Site from the request (only known sites).
	 *
	 * @param WP_REST_Request $req Request.
	 * @return int
	 */
	public static function site( $req ) {
		$site = max( 1, (int) $req->get_param( 'site' ) );
		return in_array( $site, NTAA_Archive::site_ids(), true ) && ntaa_user_can_view_site( $site ) ? $site : 1;
	}

	/**
	 * Stats object for the request's range, site and segment.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return NTAA_Stats
	 */
	public static function q( $req ) {
		$seg = $req->get_param( 'segment' );
		return new NTAA_Stats( $req->get_param( 'from' ), $req->get_param( 'to' ), self::site( $req ), $seg ? $seg : array() );
	}

	/* ---------- Reports ---------- */

	/**
	 * GET /stats.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response
	 */
	public static function stats( WP_REST_Request $req ) {
		$part = sanitize_key( (string) $req->get_param( 'part' ) );
		$q    = self::q( $req );
		$r    = $q->report( $part ? $part : 'all' );
		$r['annotations'] = NTAA_Visitors::annotations( $q->site, $q->from, $q->to );
		return self::ok( $r );
	}

	/**
	 * GET /realtime.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response
	 */
	public static function realtime( WP_REST_Request $req ) {
		return self::ok( NTAA_Stats::realtime( self::site( $req ) ) );
	}

	/**
	 * GET /visits.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response
	 */
	public static function visits( WP_REST_Request $req ) {
		$per = max( 5, min( 100, (int) $req->get_param( 'per_page' ) ? (int) $req->get_param( 'per_page' ) : 25 ) );
		return self::ok( NTAA_Visitors::log( self::q( $req ), max( 1, (int) $req->get_param( 'page' ) ), $per ) );
	}

	/**
	 * GET /visits/{id}.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function visit( WP_REST_Request $req ) {
		$v = NTAA_Visitors::visit( (int) $req['id'] );
		if ( ! $v || ! ntaa_user_can_view_site( (int) $v['site_id'] ) ) {
			return new WP_Error( 'not_found', __( 'Visit not found.', 'nodethemes-analytics-ai' ), array( 'status' => 404 ) );
		}
		return self::ok( $v );
	}

	/**
	 * GET /visitor/{vid}.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response
	 */
	public static function visitor( WP_REST_Request $req ) {
		return self::ok( NTAA_Visitors::profile( (string) $req['vid'] ) );
	}

	/**
	 * GET /transitions.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response
	 */
	public static function transitions( WP_REST_Request $req ) {
		return self::ok( NTAA_Visitors::transitions( self::q( $req ), mb_substr( (string) $req->get_param( 'page' ), 0, 255 ) ) );
	}

	/**
	 * GET /evolution — daily values of one table row.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function evolution( WP_REST_Request $req ) {
		$r = self::q( $req )->evolution( sanitize_key( (string) $req->get_param( 'report' ) ), mb_substr( (string) $req->get_param( 'label' ), 0, 255 ) );
		return is_wp_error( $r ) ? $r : self::ok( $r );
	}

	/**
	 * GET /dashboard-layout — the user's own dashboard widgets.
	 *
	 * @return WP_REST_Response
	 */
	public static function get_layout() {
		$l = get_user_meta( get_current_user_id(), 'ntaa_dash_layout', true );
		return self::ok( array( 'widgets' => is_array( $l ) ? $l : null ) );
	}

	/**
	 * POST /dashboard-layout.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response
	 */
	public static function save_layout( WP_REST_Request $req ) {
		$in  = $req->get_param( 'widgets' );
		$out = array();
		if ( is_array( $in ) ) {
			foreach ( array_slice( $in, 0, 40 ) as $w ) {
				if ( is_array( $w ) && ! empty( $w['id'] ) ) {
					$out[] = array(
						'id'   => substr( sanitize_key( $w['id'] ), 0, 40 ),
						'size' => isset( $w['size'] ) && 'full' === $w['size'] ? 'full' : 'half',
					);
				}
			}
		}
		if ( null === $in || 'reset' === $req->get_param( 'mode' ) ) {
			delete_user_meta( get_current_user_id(), 'ntaa_dash_layout' );
			return self::ok( array( 'widgets' => null ) );
		}
		update_user_meta( get_current_user_id(), 'ntaa_dash_layout', $out );
		return self::ok( array( 'widgets' => $out ) );
	}

	/**
	 * GET /crawlers.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response
	 */
	public static function crawlers( WP_REST_Request $req ) {
		$q = self::q( $req );
		return self::ok( NTAA_Visitors::crawlers( $q->from, $q->to ) );
	}

	/* ---------- Annotations ---------- */

	/**
	 * GET /annotations.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response
	 */
	public static function annotations( WP_REST_Request $req ) {
		$q = self::q( $req );
		return self::ok( NTAA_Visitors::annotations( $q->site, $q->from, $q->to ) );
	}

	/**
	 * POST /annotations.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function add_annotation( WP_REST_Request $req ) {
		$p  = (array) $req->get_json_params();
		$id = NTAA_Visitors::add_annotation( self::site( $req ), isset( $p['day'] ) ? (string) $p['day'] : '', isset( $p['note'] ) ? (string) $p['note'] : '' );
		return is_wp_error( $id ) ? self::error( $id ) : self::ok( array( 'id' => $id ) );
	}

	/**
	 * DELETE /annotations/{id}.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response
	 */
	public static function delete_annotation( WP_REST_Request $req ) {
		return self::ok( array( 'deleted' => NTAA_Visitors::delete_annotation( (int) $req['id'] ) ) );
	}

	/* ---------- Goals ---------- */

	/**
	 * GET /goals.
	 *
	 * @return WP_REST_Response
	 */
	public static function goals() {
		return self::ok( array_values( NTAA_Goals::all() ) );
	}

	/**
	 * POST /goals.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function save_goal( WP_REST_Request $req ) {
		$g = NTAA_Goals::save( (array) $req->get_json_params() );
		return is_wp_error( $g ) ? self::error( $g ) : self::ok( $g );
	}

	/**
	 * DELETE /goals/{id}.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response
	 */
	public static function delete_goal( WP_REST_Request $req ) {
		NTAA_Goals::delete( (int) $req['id'] );
		return self::ok( array( 'deleted' => true ) );
	}

	/**
	 * POST /goals/{id}/recalc — one step; the UI calls it until done.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function recalc_goal( WP_REST_Request $req ) {
		$p = (array) $req->get_json_params();
		list( $from, $to ) = NTAA_Stats::normalize_range( isset( $p['from'] ) ? $p['from'] : '', isset( $p['to'] ) ? $p['to'] : '' );
		$r = NTAA_Goals::recalc_step( (int) $req['id'], $from, $to, isset( $p['after'] ) ? (int) $p['after'] : 0 );
		return is_wp_error( $r ) ? self::error( $r ) : self::ok( $r );
	}

	/**
	 * GET /goals/{id}/report.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response
	 */
	public static function goal_report( WP_REST_Request $req ) {
		$q  = self::q( $req );
		$id = (int) $req['id'];
		return self::ok(
			array(
				'goal'     => NTAA_Goals::get( $id ),
				'series'   => $q->goal_series( $id ),
				'channel'  => $q->goal_by( $id, 'channel' ),
				'device'   => $q->goal_by( $id, 'device' ),
				'country'  => $q->goal_by( $id, 'country' ),
				'campaign' => $q->goal_by( $id, 'campaign' ),
				'entry'    => $q->goal_by( $id, 'entry' ),
			)
		);
	}

	/* ---------- Segments ---------- */

	/**
	 * GET /segments.
	 *
	 * @return WP_REST_Response
	 */
	public static function segments() {
		$fields = array();
		foreach ( NTAA_Segments::fields() as $k => $f ) {
			$fields[] = array(
				'id'    => $k,
				'label' => $f[0],
				'kind'  => in_array( $f[2], array( 'number' ), true ) ? 'number' : 'text',
			);
		}
		return self::ok(
			array(
				'saved'     => NTAA_Segments::saved(),
				'fields'    => $fields,
				'operators' => NTAA_Segments::operators(),
			)
		);
	}

	/**
	 * POST /segments.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function save_segment( WP_REST_Request $req ) {
		$s = NTAA_Segments::save( (array) $req->get_json_params() );
		return is_wp_error( $s ) ? self::error( $s ) : self::ok( $s );
	}

	/**
	 * DELETE /segments/{id}.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function delete_segment( WP_REST_Request $req ) {
		$r = NTAA_Segments::delete( (int) $req['id'] );
		return is_wp_error( $r ) ? self::error( $r ) : self::ok( array( 'deleted' => (bool) $r ) );
	}

	/**
	 * POST /gdpr/delete-visitor.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response
	 */
	public static function gdpr_delete( WP_REST_Request $req ) {
		$p = (array) $req->get_json_params();
		$n = 0;
		if ( ! empty( $p['visitor_id'] ) ) {
			$n = NTAA_Visitors::delete_visitor( (string) $p['visitor_id'] );
		} elseif ( ! empty( $p['visit_id'] ) ) {
			$n = NTAA_Visitors::delete_visits( array( (int) $p['visit_id'] ) );
		}
		return self::ok( array( 'deleted' => $n ) );
	}

	/* ---------- AI ---------- */

	/**
	 * POST /ai/analyze.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function analyze( WP_REST_Request $req ) {
		$p   = (array) $req->get_json_params();
		$res = NTAA_AI::analyze(
			sanitize_key( isset( $p['type'] ) ? $p['type'] : 'quick' ),
			isset( $p['from'] ) ? $p['from'] : '',
			isset( $p['to'] ) ? $p['to'] : '',
			isset( $p['question'] ) ? sanitize_textarea_field( $p['question'] ) : '',
			isset( $p['history'] ) && is_array( $p['history'] ) ? $p['history'] : array(),
			sanitize_key( isset( $p['provider'] ) ? $p['provider'] : '' ),
			isset( $p['site'] ) && in_array( (int) $p['site'], NTAA_Archive::site_ids(), true ) ? (int) $p['site'] : 1,
			isset( $p['segment'] ) ? $p['segment'] : array()
		);
		return is_wp_error( $res ) ? self::error( $res ) : self::ok( $res );
	}

	/**
	 * GET /ai/reports.
	 *
	 * @return WP_REST_Response
	 */
	public static function reports() {
		global $wpdb;
		$t    = NTAA_DB::t( 'reports' );
		$rows = $wpdb->get_results( "SELECT id, created_at, provider, model, date_from, date_to, type, SUBSTR(prompt, 1, 160) AS prompt FROM {$t} ORDER BY id DESC LIMIT 50", ARRAY_A ); // phpcs:ignore WordPress.DB
		return self::ok( $rows );
	}

	/**
	 * GET /ai/reports/{id}.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function report( WP_REST_Request $req ) {
		global $wpdb;
		$t   = NTAA_DB::t( 'reports' );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", (int) $req['id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB
		if ( ! $row ) {
			return new WP_Error( 'not_found', __( 'Report not found.', 'nodethemes-analytics-ai' ), array( 'status' => 404 ) );
		}
		return self::ok( $row );
	}

	/**
	 * DELETE /ai/reports/{id}.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response
	 */
	public static function delete_report( WP_REST_Request $req ) {
		global $wpdb;
		$wpdb->delete( NTAA_DB::t( 'reports' ), array( 'id' => (int) $req['id'] ) ); // phpcs:ignore WordPress.DB
		return self::ok( array( 'deleted' => true ) );
	}

	/**
	 * POST /ai/test.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function test( WP_REST_Request $req ) {
		$p   = (array) $req->get_json_params();
		$res = NTAA_AI::test( sanitize_key( isset( $p['provider'] ) ? $p['provider'] : '' ) );
		if ( is_wp_error( $res ) ) {
			return self::error( $res );
		}
		return self::ok(
			array(
				'ok'    => true,
				'model' => $res['model'],
				'reply' => mb_substr( $res['text'], 0, 200 ),
			)
		);
	}
}
