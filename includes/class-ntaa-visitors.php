<?php
/**
 * Visits log, visit details, visitor profiles, page transitions, crawlers report,
 * annotations and personal-data tools.
 *
 * @package NodeThemes_Analytics_AI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Visitors.
 */
class NTAA_Visitors {

	/**
	 * Boot.
	 */
	public static function init() {
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'register_eraser' ) );
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'register_exporter' ) );
	}

	/**
	 * Visits log (newest first).
	 *
	 * @param NTAA_Stats $q        Query (range, site, segment).
	 * @param int        $page     Page (1-based).
	 * @param int        $per_page Per page.
	 * @return array
	 */
	public static function log( $q, $page = 1, $per_page = 25 ) {
		global $wpdb;
		$tv     = NTAA_DB::t( 'visits' );
		$seg    = NTAA_Segments::sql( $q->segment );
		$where  = 'v.site_id ' . NTAA_Archive::site_in( $q->site ) . $wpdb->prepare( ' AND v.started_at BETWEEN %s AND %s', $q->from . ' 00:00:00', $q->to . ' 23:59:59' ) . $seg;
		$offset = max( 0, ( (int) $page - 1 ) * (int) $per_page );
		$rows   = $wpdb->get_results( "SELECT v.id, v.visitor_id, v.started_at, v.last_activity, v.entry_page, v.exit_page, v.referrer, v.referrer_host, v.referrer_type, v.utm_source, v.utm_medium, v.utm_campaign, v.device, v.browser, v.os, v.screen, v.language, v.country, v.region, v.city, v.pageviews, v.events, v.duration, v.is_new, v.visit_number, v.goal_conversions, v.goal_value, v.orders, v.revenue FROM {$tv} v WHERE {$where} ORDER BY v.started_at DESC, v.id DESC LIMIT " . (int) $per_page . ' OFFSET ' . (int) $offset, ARRAY_A ); // phpcs:ignore WordPress.DB
		$total  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tv} v WHERE {$where}" ); // phpcs:ignore WordPress.DB
		return array(
			'rows'  => array_map( array( 'NTAA_Stats', 'intify' ), (array) $rows ),
			'total' => $total,
			'page'  => (int) $page,
			'pages' => (int) ceil( $total / max( 1, $per_page ) ),
		);
	}

	/**
	 * Everything that happened in one visit, in order.
	 *
	 * @param int $id Visit id.
	 * @return array|null
	 */
	public static function visit( $id ) {
		global $wpdb;
		$tv = NTAA_DB::t( 'visits' );
		$v  = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tv} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		if ( ! $v ) {
			return null;
		}
		unset( $v['visitor_hash'] );
		$actions = array();
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT page, title, created_at, time_on_page, scroll_depth, load_time, search_term, search_count FROM ' . NTAA_DB::t( 'pageviews' ) . ' WHERE visit_id = %d ORDER BY id ASC', $id ), ARRAY_A ) as $p ) { // phpcs:ignore WordPress.DB
			$actions[] = array( 'type' => '' !== $p['search_term'] ? 'search' : 'page' ) + NTAA_Stats::intify( $p );
		}
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT category, action, label, value, page, created_at FROM ' . NTAA_DB::t( 'events' ) . ' WHERE visit_id = %d ORDER BY id ASC', $id ), ARRAY_A ) as $e ) { // phpcs:ignore WordPress.DB
			$actions[] = array( 'type' => 'event' ) + $e;
		}
		$goals = NTAA_Goals::all();
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT goal_id, value, page, created_at FROM ' . NTAA_DB::t( 'conversions' ) . ' WHERE visit_id = %d ORDER BY id ASC', $id ), ARRAY_A ) as $c ) { // phpcs:ignore WordPress.DB
			$actions[] = array(
				'type' => 'goal',
				'name' => isset( $goals[ $c['goal_id'] ] ) ? $goals[ $c['goal_id'] ]['name'] : '#' . $c['goal_id'],
			) + $c;
		}
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT order_id, revenue, items, status, created_at FROM ' . NTAA_DB::t( 'orders' ) . ' WHERE visit_id = %d ORDER BY id ASC', $id ), ARRAY_A ) as $o ) { // phpcs:ignore WordPress.DB
			$actions[] = array( 'type' => 'order' ) + $o;
		}
		$actions = apply_filters( 'ntaa_visit_actions', $actions, (int) $id );
		usort(
			$actions,
			function ( $a, $b ) {
				return strcmp( $a['created_at'], $b['created_at'] );
			}
		);
		$v['actions'] = $actions;
		return NTAA_Stats::intify( $v );
	}

	/**
	 * All visits of a returning visitor (cookie mode only).
	 *
	 * @param string $visitor_id Visitor id.
	 * @return array
	 */
	public static function profile( $visitor_id ) {
		global $wpdb;
		$vid = strtolower( preg_replace( '/[^a-fA-F0-9]/', '', (string) $visitor_id ) );
		if ( 16 !== strlen( $vid ) ) {
			return array();
		}
		$tv   = NTAA_DB::t( 'visits' );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, started_at, entry_page, referrer_type, referrer_host, device, country, city, pageviews, duration, goal_conversions, orders, revenue FROM {$tv} WHERE visitor_id = %s ORDER BY started_at DESC LIMIT 200", $vid ), ARRAY_A ); // phpcs:ignore WordPress.DB
		$sum  = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) AS visits, SUM(pageviews) AS pageviews, SUM(duration) AS duration, SUM(goal_conversions) AS conversions, SUM(orders) AS orders, SUM(revenue) AS revenue, MIN(started_at) AS first, MAX(started_at) AS last FROM {$tv} WHERE visitor_id = %s", $vid ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return array(
			'visitor_id' => $vid,
			'summary'    => NTAA_Stats::intify( (array) $sum ),
			'visits'     => array_map( array( 'NTAA_Stats', 'intify' ), (array) $rows ),
		);
	}

	/**
	 * Transitions of a page: where visitors came from and where they went next.
	 *
	 * @param NTAA_Stats $q    Query.
	 * @param string     $page Page path.
	 * @return array
	 */
	public static function transitions( $q, $page ) {
		global $wpdb;
		$tp    = NTAA_DB::t( 'pageviews' );
		$tv    = NTAA_DB::t( 'visits' );
		$seg   = NTAA_Segments::sql( $q->segment );
		$join  = $seg ? " INNER JOIN {$tv} v ON v.id = p.visit_id" : '';
		$range = 'p.site_id ' . NTAA_Archive::site_in( $q->site ) . $wpdb->prepare( ' AND p.created_at BETWEEN %s AND %s AND p.page = %s', $q->from . ' 00:00:00', $q->to . ' 23:59:59', $page );
		$views = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tp} p{$join} WHERE {$range}{$seg}" ); // phpcs:ignore WordPress.DB

		// Busy pages: analyse the most recent views only (a large sample is statistically the same and stays fast).
		$limit = (int) apply_filters( 'ntaa_transitions_sample', 20000 );
		$base  = $wpdb->get_results( "SELECT p.id, p.visit_id FROM {$tp} p{$join} WHERE {$range}{$seg} ORDER BY p.id DESC LIMIT " . max( 100, $limit ), ARRAY_N ); // phpcs:ignore WordPress.DB
		$prev  = array();
		$next  = array();
		$first = array();
		$exits = 0;
		$seq   = array();
		$vids  = array_values( array_unique( array_map( 'intval', wp_list_pluck( $base, 1 ) ) ) );
		foreach ( array_chunk( $vids, 2000 ) as $chunk ) {
			$rows = $wpdb->get_results( "SELECT id, visit_id, page FROM {$tp} WHERE visit_id IN (" . implode( ',', $chunk ) . ') ORDER BY visit_id, id', ARRAY_N ); // phpcs:ignore WordPress.DB
			foreach ( $rows as $r ) {
				$seq[ (int) $r[1] ][] = array( (int) $r[0], $r[2] );
			}
		}
		foreach ( $base as $b ) {
			$id   = (int) $b[0];
			$list = isset( $seq[ (int) $b[1] ] ) ? $seq[ (int) $b[1] ] : array();
			foreach ( $list as $i => $pv ) {
				if ( $pv[0] !== $id ) {
					continue;
				}
				if ( 0 === $i ) {
					$first[ (int) $b[1] ] = ( isset( $first[ (int) $b[1] ] ) ? $first[ (int) $b[1] ] : 0 ) + 1;
				} else {
					$k          = $list[ $i - 1 ][1];
					$prev[ $k ] = ( isset( $prev[ $k ] ) ? $prev[ $k ] : 0 ) + 1;
				}
				if ( isset( $list[ $i + 1 ] ) ) {
					$k          = $list[ $i + 1 ][1];
					$next[ $k ] = ( isset( $next[ $k ] ) ? $next[ $k ] : 0 ) + 1;
				} else {
					++$exits;
				}
				break;
			}
		}
		$entries = array();
		foreach ( array_chunk( array_keys( $first ), 2000 ) as $chunk ) {
			foreach ( (array) $wpdb->get_results( "SELECT id, referrer_type FROM {$tv} WHERE id IN (" . implode( ',', array_map( 'intval', $chunk ) ) . ')', ARRAY_N ) as $r ) { // phpcs:ignore WordPress.DB
				$entries[ $r[1] ] = ( isset( $entries[ $r[1] ] ) ? $entries[ $r[1] ] : 0 ) + $first[ (int) $r[0] ];
			}
		}
		$top = function ( $map, $key, $n = 15 ) {
			arsort( $map );
			$out = array();
			foreach ( array_slice( $map, 0, $n, true ) as $k => $c ) {
				$out[] = array( $key => (string) $k, 'n' => (int) $c );
			}
			return $out;
		};
		$sample = count( $base );
		$scale  = $sample && $views > $sample ? $views / $sample : 1;
		$up     = function ( $rows ) use ( $scale ) {
			foreach ( $rows as &$r ) {
				$r['n'] = (int) round( $r['n'] * $scale );
			}
			return $rows;
		};
		$events = $wpdb->get_results( $wpdb->prepare( 'SELECT e.category, e.action, COUNT(*) AS n FROM ' . NTAA_DB::t( 'events' ) . " e INNER JOIN {$tv} v ON v.id = e.visit_id WHERE e.site_id " . NTAA_Archive::site_in( $q->site ) . " AND e.created_at BETWEEN %s AND %s AND e.page = %s{$seg} GROUP BY e.category, e.action ORDER BY n DESC LIMIT 10", $q->from . ' 00:00:00', $q->to . ' 23:59:59', $page ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return array(
			'page'     => $page,
			'views'    => $views,
			'sampled'  => $scale > 1 ? $sample : 0,
			'previous' => $up( $top( $prev, 'page' ) ),
			'next'     => $up( $top( $next, 'page' ) ),
			'entries'  => $up( $top( $entries, 'channel', 20 ) ),
			'exits'    => (int) round( $exits * $scale ),
			'events'   => array_map( array( 'NTAA_Stats', 'intify' ), (array) $events ),
		);
	}

	/**
	 * Crawlers & AI agents.
	 *
	 * @param string $from Y-m-d.
	 * @param string $to   Y-m-d.
	 * @return array
	 */
	public static function crawlers( $from, $to ) {
		global $wpdb;
		$t    = NTAA_DB::t( 'bots' );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT bot, MAX(kind) AS kind, SUM(hits) AS hits, MAX(last_seen) AS last_seen, COUNT(*) AS days FROM {$t} WHERE day BETWEEN %s AND %s GROUP BY bot ORDER BY hits DESC LIMIT 100", $from, $to ), ARRAY_A ); // phpcs:ignore WordPress.DB
		$last = $wpdb->get_results( $wpdb->prepare( "SELECT bot, last_url FROM {$t} WHERE day BETWEEN %s AND %s ORDER BY last_seen DESC LIMIT 300", $from, $to ), ARRAY_A ); // phpcs:ignore WordPress.DB
		$urls = array();
		foreach ( (array) $last as $l ) {
			if ( ! isset( $urls[ $l['bot'] ] ) ) {
				$urls[ $l['bot'] ] = $l['last_url'];
			}
		}
		$out = array();
		foreach ( (array) $rows as $r ) {
			$r['last_url'] = isset( $urls[ $r['bot'] ] ) ? $urls[ $r['bot'] ] : '';
			$out[]         = NTAA_Stats::intify( $r );
		}
		$daily = $wpdb->get_results( $wpdb->prepare( "SELECT day, kind, SUM(hits) AS hits FROM {$t} WHERE day BETWEEN %s AND %s GROUP BY day, kind ORDER BY day", $from, $to ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return array(
			'bots'  => $out,
			'daily' => array_map( array( 'NTAA_Stats', 'intify' ), (array) $daily ),
		);
	}

	/* ---------------------------------------------------------------
	 * Annotations
	 * ------------------------------------------------------------- */

	/**
	 * Annotations of a range.
	 *
	 * @param int    $site Site.
	 * @param string $from Y-m-d.
	 * @param string $to   Y-m-d.
	 * @return array
	 */
	public static function annotations( $site, $from, $to ) {
		global $wpdb;
		$t    = NTAA_DB::t( 'annotations' );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, day, note, user_id FROM {$t} WHERE site_id = %d AND day BETWEEN %s AND %s ORDER BY day", $site, $from, $to ), ARRAY_A ); // phpcs:ignore WordPress.DB
		foreach ( $rows as &$r ) {
			$u          = get_userdata( (int) $r['user_id'] );
			$r['user']  = $u ? $u->display_name : '';
			$r['mine']  = (int) $r['user_id'] === get_current_user_id();
		}
		return $rows;
	}

	/**
	 * Add an annotation.
	 *
	 * @param int    $site Site.
	 * @param string $day  Y-m-d.
	 * @param string $note Note.
	 * @return int|WP_Error
	 */
	public static function add_annotation( $site, $day, $note ) {
		global $wpdb;
		$note = mb_substr( sanitize_text_field( $note ), 0, 255 );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $day ) || '' === $note ) {
			return new WP_Error( 'ntaa_note', __( 'Please enter a date and a note.', 'nodethemes-analytics-ai' ), array( 'status' => 400 ) );
		}
		$wpdb->insert( // phpcs:ignore WordPress.DB
			NTAA_DB::t( 'annotations' ),
			array(
				'site_id'    => max( 1, (int) $site ),
				'day'        => $day,
				'note'       => $note,
				'user_id'    => get_current_user_id(),
				'created_at' => current_time( 'mysql' ),
			)
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * Delete an annotation (own, or any for administrators).
	 *
	 * @param int $id Id.
	 * @return bool
	 */
	public static function delete_annotation( $id ) {
		global $wpdb;
		$t   = NTAA_DB::t( 'annotations' );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT user_id FROM {$t} WHERE id = %d", $id ) ); // phpcs:ignore WordPress.DB
		if ( ! $row || ( (int) $row->user_id !== get_current_user_id() && ! current_user_can( 'manage_options' ) ) ) {
			return false;
		}
		return (bool) $wpdb->delete( $t, array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB
	}

	/* ---------------------------------------------------------------
	 * Personal data (GDPR)
	 * ------------------------------------------------------------- */

	/**
	 * Delete everything stored for a set of visits.
	 *
	 * @param array $ids Visit ids.
	 * @return int Visits deleted.
	 */
	public static function delete_visits( $ids ) {
		global $wpdb;
		$ids = array_filter( array_map( 'intval', (array) $ids ) );
		if ( ! $ids ) {
			return 0;
		}
		$in   = implode( ',', $ids );
		$days = $wpdb->get_col( 'SELECT DISTINCT DATE(started_at) FROM ' . NTAA_DB::t( 'visits' ) . " WHERE id IN ({$in})" ); // phpcs:ignore WordPress.DB
		foreach ( array( 'pageviews', 'events', 'conversions' ) as $t ) {
			$wpdb->query( 'DELETE FROM ' . NTAA_DB::t( $t ) . " WHERE visit_id IN ({$in})" ); // phpcs:ignore WordPress.DB
		}
		$wpdb->query( 'UPDATE ' . NTAA_DB::t( 'orders' ) . " SET visit_id = 0 WHERE visit_id IN ({$in})" ); // phpcs:ignore WordPress.DB
		do_action( 'ntaa_delete_visits', $ids );
		$n = (int) $wpdb->query( 'DELETE FROM ' . NTAA_DB::t( 'visits' ) . " WHERE id IN ({$in})" ); // phpcs:ignore WordPress.DB
		foreach ( (array) $days as $d ) {
			NTAA_Archive::invalidate( 0, $d );
		}
		return $n;
	}

	/**
	 * Delete all data of a visitor id (cookie mode) — Settings › Privacy tool.
	 *
	 * @param string $visitor_id Visitor id.
	 * @return int
	 */
	public static function delete_visitor( $visitor_id ) {
		global $wpdb;
		$vid = strtolower( preg_replace( '/[^a-fA-F0-9]/', '', (string) $visitor_id ) );
		if ( 16 !== strlen( $vid ) ) {
			return 0;
		}
		$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . NTAA_DB::t( 'visits' ) . ' WHERE visitor_id = %s', $vid ) ); // phpcs:ignore WordPress.DB
		return self::delete_visits( $ids );
	}

	/**
	 * Visits linked to an email address through WooCommerce orders.
	 *
	 * @param string $email Email.
	 * @return array Visit ids.
	 */
	private static function visits_for_email( $email ) {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return array();
		}
		$ids    = array();
		$orders = wc_get_orders(
			array(
				'billing_email' => $email,
				'limit'         => 200,
			)
		);
		foreach ( $orders as $o ) {
			$v = (int) $o->get_meta( '_ntaa_visit_id' );
			if ( $v ) {
				$ids[] = $v;
			}
		}
		if ( ! $ids ) {
			return array();
		}
		// Include the other visits of the same (cookie-mode) visitor.
		global $wpdb;
		$tv   = NTAA_DB::t( 'visits' );
		$vids = $wpdb->get_col( "SELECT DISTINCT visitor_id FROM {$tv} WHERE id IN (" . implode( ',', array_map( 'intval', $ids ) ) . ") AND visitor_id <> ''" ); // phpcs:ignore WordPress.DB
		foreach ( $vids as $vid ) {
			$ids = array_merge( $ids, $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$tv} WHERE visitor_id = %s", $vid ) ) ); // phpcs:ignore WordPress.DB
		}
		return array_values( array_unique( array_map( 'intval', $ids ) ) );
	}

	/**
	 * Register the eraser (Tools › Erase Personal Data).
	 *
	 * @param array $erasers Erasers.
	 * @return array
	 */
	public static function register_eraser( $erasers ) {
		$erasers['nodethemes-analytics-ai'] = array(
			'eraser_friendly_name' => __( 'NodeThemes Analytics AI', 'nodethemes-analytics-ai' ),
			'callback'             => function ( $email ) {
				$n = self::delete_visits( self::visits_for_email( $email ) );
				return array(
					'items_removed'  => $n > 0,
					'items_retained' => false,
					'messages'       => array(),
					'done'           => true,
				);
			},
		);
		return $erasers;
	}

	/**
	 * Register the exporter (Tools › Export Personal Data).
	 *
	 * @param array $exporters Exporters.
	 * @return array
	 */
	public static function register_exporter( $exporters ) {
		$exporters['nodethemes-analytics-ai'] = array(
			'exporter_friendly_name' => __( 'NodeThemes Analytics AI', 'nodethemes-analytics-ai' ),
			'callback'               => function ( $email ) {
				global $wpdb;
				$items = array();
				$ids   = self::visits_for_email( $email );
				if ( $ids ) {
					$rows = $wpdb->get_results( 'SELECT id, started_at, entry_page, referrer_host, device, browser, os, country, city, pageviews, duration FROM ' . NTAA_DB::t( 'visits' ) . ' WHERE id IN (' . implode( ',', array_map( 'intval', $ids ) ) . ')', ARRAY_A ); // phpcs:ignore WordPress.DB
					foreach ( $rows as $r ) {
						$data = array();
						foreach ( $r as $k => $v ) {
							$data[] = array(
								'name'  => $k,
								'value' => (string) $v,
							);
						}
						$items[] = array(
							'group_id'    => 'ntaa-visits',
							'group_label' => __( 'Website visits (analytics)', 'nodethemes-analytics-ai' ),
							'item_id'     => 'ntaa-visit-' . $r['id'],
							'data'        => $data,
						);
					}
				}
				return array(
					'data' => $items,
					'done' => true,
				);
			},
		);
		return $exporters;
	}
}
