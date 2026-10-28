<?php
/**
 * Goals: definitions, live conversion detection and recalculation over past data.
 *
 * Goal types:
 * - url:      a page is viewed (contains / exact / starts with / regex).
 * - event:    an event matches category / action / label.
 * - duration: the visit lasts at least N seconds.
 * - pages:    the visit has at least N page views.
 * - purchase: an order is placed (optionally above a minimum revenue).
 * - manual:   triggered from JavaScript ntaa.trackGoal(id, value) or PHP ntaa_track_goal().
 *
 * @package NodeThemes_Analytics_AI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Goals.
 */
class NTAA_Goals {

	const OPTION = 'ntaa_goals';

	/** @var array|null */
	private static $cache = null;

	/**
	 * All goals keyed by id.
	 *
	 * @param bool $active_only Only active goals.
	 * @return array
	 */
	public static function all( $active_only = false ) {
		if ( null === self::$cache ) {
			$g           = get_option( self::OPTION, array() );
			self::$cache = is_array( $g ) && isset( $g['goals'] ) && is_array( $g['goals'] ) ? $g['goals'] : array();
		}
		if ( ! $active_only ) {
			return self::$cache;
		}
		return array_filter(
			self::$cache,
			function ( $g ) {
				return ! empty( $g['active'] );
			}
		);
	}

	/**
	 * One goal.
	 *
	 * @param int $id Id.
	 * @return array|null
	 */
	public static function get( $id ) {
		$all = self::all();
		return isset( $all[ (int) $id ] ) ? $all[ (int) $id ] : null;
	}

	/**
	 * Validate and save a goal.
	 *
	 * @param array $in Input.
	 * @return array|WP_Error Saved goal.
	 */
	public static function save( $in ) {
		$types = array( 'url', 'event', 'duration', 'pages', 'purchase', 'manual' );
		$g     = array(
			'id'       => isset( $in['id'] ) ? (int) $in['id'] : 0,
			'name'     => isset( $in['name'] ) ? mb_substr( sanitize_text_field( $in['name'] ), 0, 100 ) : '',
			'type'     => isset( $in['type'] ) && in_array( $in['type'], $types, true ) ? $in['type'] : 'url',
			'match'    => isset( $in['match'] ) && in_array( $in['match'], array( 'contains', 'exact', 'starts', 'regex' ), true ) ? $in['match'] : 'contains',
			'pattern'  => isset( $in['pattern'] ) ? mb_substr( trim( wp_unslash( (string) $in['pattern'] ) ), 0, 255 ) : '',
			'ev_cat'   => isset( $in['ev_cat'] ) ? mb_substr( sanitize_text_field( $in['ev_cat'] ), 0, 100 ) : '',
			'ev_act'   => isset( $in['ev_act'] ) ? mb_substr( sanitize_text_field( $in['ev_act'] ), 0, 150 ) : '',
			'ev_label' => isset( $in['ev_label'] ) ? mb_substr( sanitize_text_field( $in['ev_label'] ), 0, 255 ) : '',
			'min'      => isset( $in['min'] ) ? max( 0, (float) $in['min'] ) : 0,
			'value'    => isset( $in['value'] ) && '' !== $in['value'] ? round( (float) $in['value'], 2 ) : 0,
			'ev_value' => empty( $in['ev_value'] ) ? 0 : 1,
			'multiple' => empty( $in['multiple'] ) ? 0 : 1,
			'case'     => empty( $in['case'] ) ? 0 : 1,
			'active'   => isset( $in['active'] ) ? ( empty( $in['active'] ) ? 0 : 1 ) : 1,
		);
		if ( '' === $g['name'] ) {
			return new WP_Error( 'ntaa_goal_name', __( 'Please give the goal a name.', 'nodethemes-analytics-ai' ) );
		}
		if ( 'url' === $g['type'] && '' === $g['pattern'] ) {
			return new WP_Error( 'ntaa_goal_pattern', __( 'Please enter the page address (or part of it) that counts as a conversion.', 'nodethemes-analytics-ai' ) );
		}
		if ( 'url' === $g['type'] && 'regex' === $g['match'] && false === @preg_match( self::regex( $g['pattern'], $g['case'] ), '' ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return new WP_Error( 'ntaa_goal_regex', __( 'The regular expression is not valid.', 'nodethemes-analytics-ai' ) );
		}
		if ( 'event' === $g['type'] && '' === $g['ev_cat'] && '' === $g['ev_act'] && '' === $g['ev_label'] ) {
			return new WP_Error( 'ntaa_goal_event', __( 'Enter at least an event category, action or label.', 'nodethemes-analytics-ai' ) );
		}
		if ( in_array( $g['type'], array( 'duration', 'pages' ), true ) && $g['min'] <= 0 ) {
			return new WP_Error( 'ntaa_goal_min', __( 'Enter a number greater than zero.', 'nodethemes-analytics-ai' ) );
		}

		$opt = get_option( self::OPTION, array() );
		$opt = is_array( $opt ) ? $opt : array();
		if ( empty( $opt['goals'] ) || ! is_array( $opt['goals'] ) ) {
			$opt['goals'] = array();
		}
		if ( empty( $opt['next'] ) ) {
			$opt['next'] = 1;
		}
		if ( $g['id'] && isset( $opt['goals'][ $g['id'] ] ) ) {
			$g['created'] = isset( $opt['goals'][ $g['id'] ]['created'] ) ? $opt['goals'][ $g['id'] ]['created'] : current_time( 'mysql' );
		} else {
			$g['id']      = (int) $opt['next'];
			$opt['next']  = $g['id'] + 1;
			$g['created'] = current_time( 'mysql' );
		}
		$opt['goals'][ $g['id'] ] = $g;
		update_option( self::OPTION, $opt, true );
		self::$cache = null;
		return $g;
	}

	/**
	 * Delete a goal (its conversions are kept in old archives).
	 *
	 * @param int $id Id.
	 */
	public static function delete( $id ) {
		$opt = get_option( self::OPTION, array() );
		if ( isset( $opt['goals'][ (int) $id ] ) ) {
			unset( $opt['goals'][ (int) $id ] );
			update_option( self::OPTION, $opt, true );
		}
		self::$cache = null;
	}

	/**
	 * Regex for a user pattern.
	 *
	 * @param string $pattern Pattern.
	 * @param int    $case    Case sensitive.
	 * @return string
	 */
	private static function regex( $pattern, $case ) {
		return '~' . str_replace( '~', '\~', $pattern ) . '~u' . ( $case ? '' : 'i' );
	}

	/**
	 * Does a text match a goal pattern?
	 *
	 * @param string $text    Text.
	 * @param string $match   Match type.
	 * @param string $pattern Pattern.
	 * @param int    $case    Case sensitive.
	 * @return bool
	 */
	public static function matches( $text, $match, $pattern, $case = 0 ) {
		if ( '' === $pattern ) {
			return true;
		}
		$text = (string) $text;
		if ( 'regex' === $match ) {
			return (bool) @preg_match( self::regex( $pattern, $case ), $text ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		if ( ! $case ) {
			$text    = mb_strtolower( $text );
			$pattern = mb_strtolower( $pattern );
		}
		switch ( $match ) {
			case 'exact':
				return $text === $pattern || rtrim( $text, '/' ) === rtrim( $pattern, '/' );
			case 'starts':
				return 0 === strpos( $text, $pattern );
			default:
				return false !== strpos( $text, $pattern );
		}
	}

	/* ---------------------------------------------------------------
	 * Live detection
	 * ------------------------------------------------------------- */

	/**
	 * Page view.
	 *
	 * @param object $visit Visit.
	 * @param string $page  Page path.
	 * @param int    $ts    Time.
	 */
	public static function on_pageview( $visit, $page, $ts ) {
		foreach ( self::all( true ) as $g ) {
			if ( 'url' === $g['type'] && self::matches( $page, $g['match'], $g['pattern'], $g['case'] ) ) {
				self::convert( $visit, $g, $g['value'], $page, '', $ts );
			}
		}
		self::on_engagement( $visit, $ts );
	}

	/**
	 * Visit duration / page count thresholds.
	 *
	 * @param object $visit Visit.
	 * @param int    $ts    Time.
	 */
	public static function on_engagement( $visit, $ts ) {
		foreach ( self::all( true ) as $g ) {
			if ( ( 'duration' === $g['type'] && (int) $visit->duration >= $g['min'] ) || ( 'pages' === $g['type'] && (int) $visit->pageviews >= $g['min'] ) ) {
				$g['multiple'] = 0;
				self::convert( $visit, $g, $g['value'], (string) $visit->exit_page, '', $ts );
			}
		}
	}

	/**
	 * Event.
	 *
	 * @param object $visit Visit.
	 * @param array  $ev    Event row.
	 * @param int    $ts    Time.
	 */
	public static function on_event( $visit, $ev, $ts ) {
		foreach ( self::all( true ) as $g ) {
			if ( 'event' !== $g['type'] ) {
				continue;
			}
			if ( self::matches( $ev['category'], 'exact', $g['ev_cat'] ) && self::matches( $ev['action'], 'exact', $g['ev_act'] ) && self::matches( $ev['label'], 'contains', $g['ev_label'] ) ) {
				$val = ( $g['ev_value'] && isset( $ev['value'] ) ) ? (float) $ev['value'] : $g['value'];
				self::convert( $visit, $g, $val, $ev['page'], '', $ts );
			}
		}
	}

	/**
	 * Order placed.
	 *
	 * @param object $visit   Visit.
	 * @param float  $revenue Revenue.
	 * @param string $order   Order id.
	 * @param int    $ts      Time.
	 */
	public static function on_order( $visit, $revenue, $order, $ts ) {
		foreach ( self::all( true ) as $g ) {
			if ( 'purchase' === $g['type'] && $revenue >= (float) $g['min'] ) {
				$g['multiple'] = 1;
				self::convert( $visit, $g, $g['value'] > 0 ? $g['value'] : $revenue, (string) $visit->exit_page, (string) $order, $ts );
			}
		}
	}

	/**
	 * Manual goal from JavaScript / PHP.
	 *
	 * @param object     $visit Visit.
	 * @param int        $id    Goal id.
	 * @param float|null $value Value override.
	 * @param int        $ts    Time.
	 */
	public static function manual( $visit, $id, $value, $ts ) {
		$g = self::get( $id );
		if ( $g && ! empty( $g['active'] ) && 'manual' === $g['type'] ) {
			self::convert( $visit, $g, null === $value ? $g['value'] : $value, (string) $visit->exit_page, '', $ts );
		}
	}

	/**
	 * Record a conversion (once per visit unless the goal allows more).
	 *
	 * @param object $visit Visit.
	 * @param array  $g     Goal.
	 * @param float  $value Value.
	 * @param string $page  Page.
	 * @param string $ref   Reference (order id).
	 * @param int    $ts    Time.
	 * @return bool
	 */
	public static function convert( $visit, $g, $value, $page, $ref, $ts ) {
		global $wpdb;
		$tc = NTAA_DB::t( 'conversions' );
		if ( '' !== $ref ) {
			if ( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$tc} WHERE visit_id = %d AND goal_id = %d AND ref = %s", (int) $visit->id, (int) $g['id'], $ref ) ) ) { // phpcs:ignore WordPress.DB
				return false;
			}
		} elseif ( empty( $g['multiple'] ) && $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$tc} WHERE visit_id = %d AND goal_id = %d LIMIT 1", (int) $visit->id, (int) $g['id'] ) ) ) { // phpcs:ignore WordPress.DB
			return false;
		}
		$value = round( (float) $value, 2 );
		$wpdb->insert( // phpcs:ignore WordPress.DB
			$tc,
			array(
				'site_id'    => (int) $visit->site_id,
				'visit_id'   => (int) $visit->id,
				'goal_id'    => (int) $g['id'],
				'created_at' => wp_date( 'Y-m-d H:i:s', $ts ),
				'value'      => $value,
				'page'       => mb_substr( (string) $page, 0, 255 ),
				'ref'        => mb_substr( (string) $ref, 0, 64 ),
			)
		);
		$tv = NTAA_DB::t( 'visits' );
		$wpdb->query( $wpdb->prepare( "UPDATE {$tv} SET goal_conversions = goal_conversions + 1, goal_value = goal_value + %f WHERE id = %d", $value, (int) $visit->id ) ); // phpcs:ignore WordPress.DB
		do_action( 'ntaa_goal_converted', $g, $visit, $value );
		return true;
	}

	/* ---------------------------------------------------------------
	 * Recalculation over the raw visit log (in steps, so it never times out)
	 * ------------------------------------------------------------- */

	/**
	 * Recalculate one goal over a date range, one step at a time.
	 *
	 * @param int    $id   Goal id.
	 * @param string $from Y-m-d.
	 * @param string $to   Y-m-d.
	 * @param int    $after Visit id to continue after (0 = start).
	 * @return array|WP_Error { done: bool, after: int, processed: int, conversions: int, total: int }
	 */
	public static function recalc_step( $id, $from, $to, $after = 0 ) {
		global $wpdb;
		$g = self::get( $id );
		if ( ! $g ) {
			return new WP_Error( 'ntaa_goal', __( 'Goal not found.', 'nodethemes-analytics-ai' ) );
		}
		if ( 'manual' === $g['type'] ) {
			return new WP_Error( 'ntaa_goal_manual', __( 'Manual goals are triggered by your own code and cannot be recalculated.', 'nodethemes-analytics-ai' ) );
		}
		$tv = NTAA_DB::t( 'visits' );
		$tp = NTAA_DB::t( 'pageviews' );
		$te = NTAA_DB::t( 'events' );
		$tc = NTAA_DB::t( 'conversions' );
		$to2 = NTAA_DB::t( 'orders' );
		$f  = $from . ' 00:00:00';
		$t  = $to . ' 23:59:59';

		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$tv} WHERE started_at BETWEEN %s AND %s", $f, $t ) ); // phpcs:ignore WordPress.DB
		$ids   = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$tv} WHERE started_at BETWEEN %s AND %s AND id > %d ORDER BY id ASC LIMIT 2000", $f, $t, $after ) ); // phpcs:ignore WordPress.DB
		if ( ! $ids ) {
			NTAA_Archive::invalidate_range( 0, $from, $to );
			return array( 'done' => true, 'after' => $after, 'processed' => 0, 'conversions' => 0, 'total' => $total );
		}
		$in = implode( ',', array_map( 'intval', $ids ) );

		// Remove this goal's old conversions for these visits and take them off the visit totals.
		$old = $wpdb->get_results( "SELECT visit_id, COUNT(*) AS n, SUM(value) AS v FROM {$tc} WHERE goal_id = " . (int) $g['id'] . " AND visit_id IN ({$in}) GROUP BY visit_id" ); // phpcs:ignore WordPress.DB
		foreach ( $old as $o ) {
			$wpdb->query( $wpdb->prepare( "UPDATE {$tv} SET goal_conversions = GREATEST(0, CAST(goal_conversions AS SIGNED) - %d), goal_value = GREATEST(0, goal_value - %f) WHERE id = %d", (int) $o->n, (float) $o->v, (int) $o->visit_id ) ); // phpcs:ignore WordPress.DB
		}
		$wpdb->query( "DELETE FROM {$tc} WHERE goal_id = " . (int) $g['id'] . " AND visit_id IN ({$in})" ); // phpcs:ignore WordPress.DB

		$visits = $wpdb->get_results( "SELECT * FROM {$tv} WHERE id IN ({$in})", OBJECT_K ); // phpcs:ignore WordPress.DB
		$conv   = 0;
		switch ( $g['type'] ) {
			case 'url':
				$rows = $wpdb->get_results( "SELECT visit_id, page, created_at FROM {$tp} WHERE visit_id IN ({$in}) ORDER BY id ASC" ); // phpcs:ignore WordPress.DB
				foreach ( $rows as $r ) {
					if ( isset( $visits[ $r->visit_id ] ) && self::matches( $r->page, $g['match'], $g['pattern'], $g['case'] ) ) {
						$conv += (int) self::convert( $visits[ $r->visit_id ], $g, $g['value'], $r->page, '', strtotime( $r->created_at . ' ' . wp_timezone_string() ) );
					}
				}
				break;
			case 'event':
				$rows = $wpdb->get_results( "SELECT * FROM {$te} WHERE visit_id IN ({$in}) ORDER BY id ASC", ARRAY_A ); // phpcs:ignore WordPress.DB
				foreach ( $rows as $r ) {
					if ( isset( $visits[ $r['visit_id'] ] ) && self::matches( $r['category'], 'exact', $g['ev_cat'] ) && self::matches( $r['action'], 'exact', $g['ev_act'] ) && self::matches( $r['label'], 'contains', $g['ev_label'] ) ) {
						$val   = ( $g['ev_value'] && null !== $r['value'] ) ? (float) $r['value'] : $g['value'];
						$conv += (int) self::convert( $visits[ $r['visit_id'] ], $g, $val, $r['page'], '', strtotime( $r['created_at'] . ' ' . wp_timezone_string() ) );
					}
				}
				break;
			case 'duration':
			case 'pages':
				foreach ( $visits as $v ) {
					$ok = 'duration' === $g['type'] ? (int) $v->duration >= $g['min'] : (int) $v->pageviews >= $g['min'];
					if ( $ok ) {
						$g2             = $g;
						$g2['multiple'] = 0;
						$conv          += (int) self::convert( $v, $g2, $g['value'], $v->exit_page, '', strtotime( $v->last_activity . ' ' . wp_timezone_string() ) );
					}
				}
				break;
			case 'purchase':
				$rows = $wpdb->get_results( "SELECT visit_id, order_id, revenue, created_at FROM {$to2} WHERE status = 'paid' AND visit_id IN ({$in})" ); // phpcs:ignore WordPress.DB
				foreach ( $rows as $r ) {
					if ( isset( $visits[ $r->visit_id ] ) && (float) $r->revenue >= (float) $g['min'] ) {
						$g2             = $g;
						$g2['multiple'] = 1;
						$conv          += (int) self::convert( $visits[ $r->visit_id ], $g2, $g['value'] > 0 ? $g['value'] : (float) $r->revenue, $visits[ $r->visit_id ]->exit_page, (string) $r->order_id, strtotime( $r->created_at . ' ' . wp_timezone_string() ) );
					}
				}
				break;
		}
		$last = (int) end( $ids );
		return array(
			'done'        => count( $ids ) < 2000,
			'after'       => $last,
			'processed'   => count( $ids ),
			'conversions' => $conv,
			'total'       => $total,
		) + ( count( $ids ) < 2000 ? array( 'invalidated' => NTAA_Archive::invalidate_range( 0, $from, $to ) ) : array() );
	}
}
