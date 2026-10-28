<?php
/**
 * Segments: filter every report by visitor attributes, e.g. "mobile visitors from Germany who came from Google".
 *
 * A segment is a list of conditions combined with AND (each condition may hold several
 * values combined with OR). Conditions are turned into SQL only through a whitelist of
 * fields and operators, and every value is escaped with $wpdb->prepare().
 *
 * @package NodeThemes_Analytics_AI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Segments.
 */
class NTAA_Segments {

	const OPTION = 'ntaa_segments';

	/**
	 * Fields that can be used in a segment.
	 *
	 * @return array field => [label, sql (on visits alias v), kind: text|number|exists]
	 */
	public static function fields() {
		$f = array(
			'channel'        => array( __( 'Channel', 'nodethemes-analytics-ai' ), 'v.referrer_type', 'text' ),
			'referrer'       => array( __( 'Referrer', 'nodethemes-analytics-ai' ), 'v.referrer_host', 'text' ),
			'utm_source'     => array( __( 'Campaign source', 'nodethemes-analytics-ai' ), 'v.utm_source', 'text' ),
			'utm_medium'     => array( __( 'Campaign medium', 'nodethemes-analytics-ai' ), 'v.utm_medium', 'text' ),
			'utm_campaign'   => array( __( 'Campaign name', 'nodethemes-analytics-ai' ), 'v.utm_campaign', 'text' ),
			'device'         => array( __( 'Device type', 'nodethemes-analytics-ai' ), 'v.device', 'text' ),
			'browser'        => array( __( 'Browser', 'nodethemes-analytics-ai' ), 'v.browser', 'text' ),
			'os'             => array( __( 'Operating system', 'nodethemes-analytics-ai' ), 'v.os', 'text' ),
			'country'        => array( __( 'Country code', 'nodethemes-analytics-ai' ), 'v.country', 'text' ),
			'region'         => array( __( 'Region', 'nodethemes-analytics-ai' ), 'v.region', 'text' ),
			'city'           => array( __( 'City', 'nodethemes-analytics-ai' ), 'v.city', 'text' ),
			'language'       => array( __( 'Browser language', 'nodethemes-analytics-ai' ), 'v.language', 'text' ),
			'entry_page'     => array( __( 'Entry page', 'nodethemes-analytics-ai' ), 'v.entry_page', 'text' ),
			'exit_page'      => array( __( 'Exit page', 'nodethemes-analytics-ai' ), 'v.exit_page', 'text' ),
			'page_visited'   => array( __( 'Visited page', 'nodethemes-analytics-ai' ), 'page', 'exists_page' ),
			'event_category' => array( __( 'Event category', 'nodethemes-analytics-ai' ), 'category', 'exists_event' ),
			'event_action'   => array( __( 'Event action', 'nodethemes-analytics-ai' ), 'action', 'exists_event' ),
			'pageviews'      => array( __( 'Page views in the visit', 'nodethemes-analytics-ai' ), 'v.pageviews', 'number' ),
			'duration'       => array( __( 'Visit duration (seconds)', 'nodethemes-analytics-ai' ), 'v.duration', 'number' ),
			'visit_number'   => array( __( 'Visit number', 'nodethemes-analytics-ai' ), 'v.visit_number', 'number' ),
			'returning'      => array( __( 'Returning visitor (1 = yes)', 'nodethemes-analytics-ai' ), '(1 - v.is_new)', 'number' ),
			'goal'           => array( __( 'Converted goal (goal id)', 'nodethemes-analytics-ai' ), 'goal_id', 'exists_goal' ),
			'converted'      => array( __( 'Converted any goal (1 = yes)', 'nodethemes-analytics-ai' ), '(v.goal_conversions > 0)', 'number' ),
			'ordered'        => array( __( 'Placed an order (1 = yes)', 'nodethemes-analytics-ai' ), '(v.orders > 0)', 'number' ),
			'revenue'        => array( __( 'Revenue of the visit', 'nodethemes-analytics-ai' ), 'v.revenue', 'number' ),
			'hour'           => array( __( 'Hour of the visit (0–23)', 'nodethemes-analytics-ai' ), 'HOUR(v.started_at)', 'number' ),
		);
		$names = (array) NTAA_Settings::get( 'custom_dims', array() );
		for ( $i = 1; $i <= 5; $i++ ) {
			$n = isset( $names[ $i - 1 ] ) ? trim( (string) $names[ $i - 1 ] ) : '';
			if ( '' !== $n ) {
				$f[ 'dim' . $i ] = array( $n, 'v.dim' . $i, 'text' );
			}
		}
		return apply_filters( 'ntaa_segment_fields', $f );
	}

	/**
	 * Operators.
	 *
	 * @return array
	 */
	public static function operators() {
		return array(
			'eq'       => __( 'is', 'nodethemes-analytics-ai' ),
			'neq'      => __( 'is not', 'nodethemes-analytics-ai' ),
			'contains' => __( 'contains', 'nodethemes-analytics-ai' ),
			'ncontains' => __( 'does not contain', 'nodethemes-analytics-ai' ),
			'starts'   => __( 'starts with', 'nodethemes-analytics-ai' ),
			'gt'       => __( 'greater than', 'nodethemes-analytics-ai' ),
			'lt'       => __( 'less than', 'nodethemes-analytics-ai' ),
		);
	}

	/**
	 * Validate a segment definition.
	 *
	 * @param mixed $conds Conditions (array or JSON).
	 * @return array Clean list.
	 */
	public static function clean( $conds ) {
		if ( is_string( $conds ) ) {
			$conds = json_decode( wp_unslash( $conds ), true );
		}
		if ( ! is_array( $conds ) ) {
			return array();
		}
		$fields = self::fields();
		$ops    = self::operators();
		$out    = array();
		foreach ( array_slice( $conds, 0, 12 ) as $c ) {
			if ( ! is_array( $c ) || empty( $c['f'] ) || ! isset( $fields[ $c['f'] ] ) || empty( $c['o'] ) || ! isset( $ops[ $c['o'] ] ) ) {
				continue;
			}
			$vals = isset( $c['v'] ) ? ( is_array( $c['v'] ) ? $c['v'] : explode( '|', (string) $c['v'] ) ) : array();
			$vals = array_values(
				array_filter(
					array_map(
						function ( $x ) {
							return mb_substr( trim( sanitize_text_field( (string) $x ) ), 0, 191 );
						},
						array_slice( $vals, 0, 20 )
					),
					'strlen'
				)
			);
			if ( ! $vals ) {
				continue;
			}
			$out[] = array(
				'f' => $c['f'],
				'o' => $c['o'],
				'v' => $vals,
			);
		}
		return $out;
	}

	/**
	 * SQL WHERE fragment for the visits alias "v" (always starts with " AND " or is empty).
	 *
	 * @param array $conds Clean conditions.
	 * @return string
	 */
	public static function sql( $conds ) {
		global $wpdb;
		if ( ! $conds ) {
			return '';
		}
		$fields = self::fields();
		$parts  = array();
		foreach ( $conds as $c ) {
			list( , $col, $kind ) = $fields[ $c['f'] ];
			$ors                  = array();
			foreach ( $c['v'] as $val ) {
				$ors[] = self::expr( $col, $kind, $c['o'], $val );
			}
			$ors = array_filter( $ors );
			if ( ! $ors ) {
				continue;
			}
			$negative = in_array( $c['o'], array( 'neq', 'ncontains' ), true );
			$parts[]  = '(' . implode( $negative ? ' AND ' : ' OR ', $ors ) . ')';
		}
		return $parts ? ' AND ' . implode( ' AND ', $parts ) : '';
	}

	/**
	 * One comparison.
	 *
	 * @param string $col  Column / expression.
	 * @param string $kind Kind.
	 * @param string $op   Operator.
	 * @param string $val  Value.
	 * @return string
	 */
	private static function expr( $col, $kind, $op, $val ) {
		global $wpdb;
		if ( 0 === strpos( $kind, 'exists_' ) ) {
			$map   = array(
				'exists_page'  => array( NTAA_DB::t( 'pageviews' ), 'px' ),
				'exists_event' => array( NTAA_DB::t( 'events' ), 'ex' ),
				'exists_goal'  => array( NTAA_DB::t( 'conversions' ), 'cx' ),
			);
			list( $table, $a ) = $map[ $kind ];
			$neg   = in_array( $op, array( 'neq', 'ncontains' ), true );
			$inner = self::compare( $a . '.' . $col, $neg ? ( 'neq' === $op ? 'eq' : 'contains' ) : $op, $val, 'exists_goal' === $kind );
			return ( $neg ? 'NOT ' : '' ) . "EXISTS (SELECT 1 FROM {$table} {$a} WHERE {$a}.visit_id = v.id AND {$inner})";
		}
		return self::compare( $col, $op, $val, 'number' === $kind );
	}

	/**
	 * Comparison SQL.
	 *
	 * @param string $col     Column.
	 * @param string $op      Operator.
	 * @param string $val     Value.
	 * @param bool   $numeric Numeric column.
	 * @return string
	 */
	private static function compare( $col, $op, $val, $numeric ) {
		global $wpdb;
		if ( $numeric ) {
			$n = (float) str_replace( ',', '.', $val );
			switch ( $op ) {
				case 'gt':
					return $wpdb->prepare( "{$col} > %f", $n ); // phpcs:ignore WordPress.DB
				case 'lt':
					return $wpdb->prepare( "{$col} < %f", $n ); // phpcs:ignore WordPress.DB
				case 'neq':
					return $wpdb->prepare( "{$col} <> %f", $n ); // phpcs:ignore WordPress.DB
				default:
					return $wpdb->prepare( "{$col} = %f", $n ); // phpcs:ignore WordPress.DB
			}
		}
		switch ( $op ) {
			case 'neq':
				return $wpdb->prepare( "{$col} <> %s", $val ); // phpcs:ignore WordPress.DB
			case 'contains':
				return $wpdb->prepare( "{$col} LIKE %s", '%' . $wpdb->esc_like( $val ) . '%' ); // phpcs:ignore WordPress.DB
			case 'ncontains':
				return $wpdb->prepare( "{$col} NOT LIKE %s", '%' . $wpdb->esc_like( $val ) . '%' ); // phpcs:ignore WordPress.DB
			case 'starts':
				return $wpdb->prepare( "{$col} LIKE %s", $wpdb->esc_like( $val ) . '%' ); // phpcs:ignore WordPress.DB
			case 'gt':
				return $wpdb->prepare( "{$col} > %s", $val ); // phpcs:ignore WordPress.DB
			case 'lt':
				return $wpdb->prepare( "{$col} < %s", $val ); // phpcs:ignore WordPress.DB
			default:
				return $wpdb->prepare( "{$col} = %s", $val ); // phpcs:ignore WordPress.DB
		}
	}

	/* ---------- Saved segments ---------- */

	/**
	 * Saved segments visible to the current user.
	 *
	 * @return array
	 */
	public static function saved() {
		$all = get_option( self::OPTION, array() );
		$out = array();
		foreach ( is_array( $all ) ? $all : array() as $id => $s ) {
			if ( ! empty( $s['shared'] ) || (int) $s['user'] === get_current_user_id() ) {
				$out[] = array(
					'id'     => (int) $id,
					'name'   => (string) $s['name'],
					'conds'  => (array) $s['conds'],
					'shared' => (int) $s['shared'],
					'pre'    => empty( $s['pre'] ) ? 0 : 1,
					'mine'   => (int) $s['user'] === get_current_user_id(),
				);
			}
		}
		return $out;
	}

	/**
	 * Save a segment.
	 *
	 * @param array $in Input.
	 * @return array|WP_Error
	 */
	public static function save( $in ) {
		$conds = self::clean( isset( $in['conds'] ) ? $in['conds'] : array() );
		$name  = isset( $in['name'] ) ? mb_substr( sanitize_text_field( $in['name'] ), 0, 80 ) : '';
		if ( '' === $name || ! $conds ) {
			return new WP_Error( 'ntaa_segment', __( 'A segment needs a name and at least one condition.', 'nodethemes-analytics-ai' ), array( 'status' => 400 ) );
		}
		$all = get_option( self::OPTION, array() );
		$all = is_array( $all ) ? $all : array();
		$id  = isset( $in['id'] ) ? (int) $in['id'] : 0;
		if ( $id && isset( $all[ $id ] ) && (int) $all[ $id ]['user'] !== get_current_user_id() && ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'ntaa_segment_owner', __( 'You can only edit your own segments.', 'nodethemes-analytics-ai' ), array( 'status' => 403 ) );
		}
		if ( ! $id || ! isset( $all[ $id ] ) ) {
			$id = $all ? max( array_keys( $all ) ) + 1 : 1;
		}
		$old = isset( $all[ $id ] ) ? $all[ $id ] : null;
		$pre = current_user_can( 'manage_options' ) ? ( empty( $in['pre'] ) ? 0 : 1 ) : ( $old && ! empty( $old['pre'] ) ? 1 : 0 );
		$all[ $id ] = array(
			'name'   => $name,
			'conds'  => $conds,
			'shared' => empty( $in['shared'] ) ? 0 : 1,
			'pre'    => $pre,
			'user'   => isset( $all[ $id ]['user'] ) ? (int) $all[ $id ]['user'] : get_current_user_id(),
		);
		update_option( self::OPTION, $all, false );
		if ( $old && ( wp_json_encode( $old['conds'] ) !== wp_json_encode( $conds ) || ( ! empty( $old['pre'] ) && ! $pre ) ) ) {
			NTAA_Archive::drop_segment( $id );
		}
		if ( $pre ) {
			wp_schedule_single_event( time() + 5, 'ntaa_segment_backfill' );
		}
		return array( 'id' => $id ) + $all[ $id ];
	}

	/**
	 * Delete a segment.
	 *
	 * @param int $id Id.
	 * @return bool|WP_Error
	 */
	public static function delete( $id ) {
		$all = get_option( self::OPTION, array() );
		if ( ! isset( $all[ $id ] ) ) {
			return false;
		}
		if ( (int) $all[ $id ]['user'] !== get_current_user_id() && ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'ntaa_segment_owner', __( 'You can only delete your own segments.', 'nodethemes-analytics-ai' ), array( 'status' => 403 ) );
		}
		unset( $all[ $id ] );
		update_option( self::OPTION, $all, false );
		NTAA_Archive::drop_segment( $id );
		return true;
	}

	/**
	 * Conditions of a saved segment (any owner).
	 *
	 * @param int $id Id.
	 * @return array
	 */
	public static function conds_of( $id ) {
		$all = get_option( self::OPTION, array() );
		return isset( $all[ $id ]['conds'] ) ? self::clean( $all[ $id ]['conds'] ) : array();
	}

	/**
	 * Ids of segments that are pre-processed (archived every day for fast reports).
	 *
	 * @return array
	 */
	public static function preprocessed() {
		$out = array();
		foreach ( (array) get_option( self::OPTION, array() ) as $id => $s ) {
			if ( ! empty( $s['pre'] ) ) {
				$out[] = (int) $id;
			}
		}
		return $out;
	}

	/**
	 * Pre-processed segment with exactly these conditions (0 = none).
	 *
	 * @param array $conds Clean conditions.
	 * @return int
	 */
	public static function find_preprocessed( $conds ) {
		if ( ! $conds ) {
			return 0;
		}
		$key = wp_json_encode( $conds );
		foreach ( self::preprocessed() as $id ) {
			if ( wp_json_encode( self::conds_of( $id ) ) === $key ) {
				return $id;
			}
		}
		return 0;
	}
}
