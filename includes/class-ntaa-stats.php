<?php
/**
 * Report queries for the dashboard, the AI and the API.
 *
 * Without a segment, finished days come from the daily archive and only the remaining days
 * (normally just today) are read from the raw log. With a segment everything is computed from
 * the raw log, so segmented reports cover the raw-data retention period.
 *
 * "Visitors" for a period is the sum of the daily unique visitors.
 *
 * @package NodeThemes_Analytics_AI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Stats.
 */
class NTAA_Stats {

	/** @var string */
	public $from;
	/** @var string */
	public $to;
	/** @var int */
	public $site;
	/** @var array */
	public $segment;
	/** @var string */
	private $seg_sql;
	/** @var array|null Raw days (null until prepared). */
	private $raw_days = null;
	/** @var array */
	private $memo = array();
	/** @var int Pre-processed segment id (0 = none). */
	public $seg_id = 0;

	/**
	 * Constructor.
	 *
	 * @param string $from    Y-m-d.
	 * @param string $to      Y-m-d.
	 * @param int    $site    Site id.
	 * @param mixed  $segment Segment conditions (array / JSON) or saved segment id.
	 */
	public function __construct( $from, $to, $site = 1, $segment = array() ) {
		list( $this->from, $this->to ) = self::normalize_range( $from, $to );
		$this->site                    = max( 1, (int) $site );
		if ( is_numeric( $segment ) && (int) $segment > 0 ) {
			foreach ( NTAA_Segments::saved() as $s ) {
				if ( (int) $s['id'] === (int) $segment ) {
					$segment = $s['conds'];
					break;
				}
			}
		}
		$this->segment = NTAA_Segments::clean( $segment );
		$this->seg_sql = NTAA_Segments::sql( $this->segment );
		$this->seg_id  = NTAA_Segments::find_preprocessed( $this->segment );
	}

	/**
	 * Can archived data be used (no segment, or a pre-processed segment)?
	 *
	 * @return bool
	 */
	public function archived() {
		return ! $this->segmented() || $this->seg_id > 0;
	}

	/**
	 * Archive report key for this query.
	 *
	 * @param string $key Report.
	 * @return string
	 */
	private function akey( $key ) {
		return NTAA_Archive::prefix( $this->seg_id ) . $key;
	}

	/**
	 * Days of the range not yet archived for a pre-processed segment (shown as "processing").
	 *
	 * @return int
	 */
	public function pending_days() {
		if ( ! $this->seg_id ) {
			return 0;
		}
		$n = 0;
		foreach ( $this->raw_days() as $d ) {
			if ( $d < NTAA_Archive::today() ) {
				++$n;
			}
		}
		return $n;
	}

	/**
	 * Validate a range (defaults to the last 30 days).
	 *
	 * @param mixed $from From.
	 * @param mixed $to   To.
	 * @return array
	 */
	public static function normalize_range( $from, $to ) {
		$re   = '/^\d{4}-\d{2}-\d{2}$/';
		$to   = ( is_string( $to ) && preg_match( $re, $to ) ) ? $to : wp_date( 'Y-m-d' );
		$from = ( is_string( $from ) && preg_match( $re, $from ) ) ? $from : wp_date( 'Y-m-d', strtotime( $to . ' -29 days' ) );
		if ( $from > $to ) {
			list( $from, $to ) = array( $to, $from );
		}
		if ( ( strtotime( $to ) - strtotime( $from ) ) > 3700 * DAY_IN_SECONDS ) {
			$from = gmdate( 'Y-m-d', strtotime( $to . ' -3650 days' ) );
		}
		return array( $from, $to );
	}

	/**
	 * Number of days.
	 *
	 * @return int
	 */
	public function days() {
		return (int) round( ( strtotime( $this->to ) - strtotime( $this->from ) ) / DAY_IN_SECONDS ) + 1;
	}

	/**
	 * Same-length period just before.
	 *
	 * @return NTAA_Stats
	 */
	public function previous() {
		$days = $this->days();
		$to   = gmdate( 'Y-m-d', strtotime( $this->from . ' -1 day' ) );
		$from = gmdate( 'Y-m-d', strtotime( $to . ' -' . ( $days - 1 ) . ' days' ) );
		return new self( $from, $to, $this->site, $this->segment );
	}

	/**
	 * Is a segment applied?
	 *
	 * @return bool
	 */
	public function segmented() {
		return '' !== $this->seg_sql;
	}

	/**
	 * Archive finished days and find what must be read raw.
	 *
	 * @return array Raw days.
	 */
	private function raw_days() {
		if ( null === $this->raw_days ) {
			if ( $this->seg_id ) {
				$this->raw_days = NTAA_Archive::prepare( $this->site, $this->from, $this->to, 3, $this->seg_id );
			} elseif ( $this->segmented() ) {
				$days = array();
				for ( $d = $this->from; $d <= $this->to; $d = gmdate( 'Y-m-d', strtotime( $d . ' +1 day' ) ) ) {
					$days[] = $d;
				}
				$this->raw_days = $days;
			} else {
				$this->raw_days = NTAA_Archive::prepare( $this->site, $this->from, $this->to );
			}
		}
		return $this->raw_days;
	}

	/**
	 * Merged rows of a report (archive + raw), keyed by label.
	 *
	 * @param string $key Report key.
	 * @return array
	 */
	public function rows( $key ) {
		if ( isset( $this->memo[ $key ] ) ) {
			return $this->memo[ $key ];
		}
		$raw    = NTAA_Archive::raw( $key, $this->site, NTAA_Archive::blocks( $this->raw_days() ), $this->seg_sql );
		$merged = array();
		$sources = array( $raw );
		if ( $this->archived() ) {
			$sources[] = NTAA_Archive::read( $this->akey( $key ), $this->site, $this->from, $this->to );
		}
		foreach ( $sources as $list ) {
			foreach ( $list as $r ) {
				$k = $r['label'];
				if ( ! isset( $merged[ $k ] ) ) {
					$merged[ $k ] = $r;
					continue;
				}
				if ( '' === $merged[ $k ]['extra'] ) {
					$merged[ $k ]['extra'] = $r['extra'];
				}
				for ( $i = 1; $i <= 16; $i++ ) {
					$merged[ $k ][ 'c' . $i ] += $r[ 'c' . $i ];
				}
			}
		}
		$this->memo[ $key ] = $merged;
		return $merged;
	}

	/**
	 * Single row report (summary, perf, ecom, steps) — zeroes when empty.
	 *
	 * @param string $key Report.
	 * @return array
	 */
	private function one( $key ) {
		$rows = $this->rows( $key );
		if ( isset( $rows[''] ) ) {
			return $rows[''];
		}
		$z = array( 'label' => '', 'extra' => '' );
		for ( $i = 1; $i <= 16; $i++ ) {
			$z[ 'c' . $i ] = 0;
		}
		return $z;
	}

	/**
	 * Sort rows by a column and keep the top N.
	 *
	 * @param array  $rows  Rows.
	 * @param string $col   Column.
	 * @param int    $limit Limit.
	 * @return array
	 */
	private static function top( $rows, $col = 'c1', $limit = 50 ) {
		$rows = array_values( $rows );
		usort(
			$rows,
			function ( $a, $b ) use ( $col ) {
				if ( '__other__' === $a['label'] ) {
					return 1;
				}
				if ( '__other__' === $b['label'] ) {
					return -1;
				}
				return $b[ $col ] <=> $a[ $col ];
			}
		);
		return $limit > 0 ? array_slice( $rows, 0, $limit ) : $rows;
	}

	/**
	 * Ratio helper.
	 *
	 * @param float $a   Numerator.
	 * @param float $b   Denominator.
	 * @param int   $dec Decimals.
	 * @return float
	 */
	private static function ratio( $a, $b, $dec = 1 ) {
		return $b ? round( $a / $b, $dec ) : 0;
	}

	/* ---------------------------------------------------------------
	 * Summary & time series
	 * ------------------------------------------------------------- */

	/**
	 * Map a summary row to named KPIs.
	 *
	 * @param array $r Row.
	 * @return array
	 */
	private static function summary_from( $r ) {
		$visits = (int) $r['c1'];
		return array(
			'visitors'         => (int) $r['c2'],
			'visits'           => $visits,
			'pageviews'        => (int) $r['c3'],
			'bounce_rate'      => self::ratio( $r['c4'] * 100, $visits ),
			'avg_duration'     => (int) round( self::ratio( $r['c5'], $visits, 2 ) ),
			'pages_per_visit'  => self::ratio( $r['c3'], $visits, 2 ),
			'conversions'      => (int) $r['c6'],
			'goal_value'       => round( $r['c7'] / 100, 2 ),
			'orders'           => (int) $r['c8'],
			'revenue'          => round( $r['c9'] / 100, 2 ),
			'new_visits'       => (int) $r['c10'],
			'returning_visits' => max( 0, $visits - (int) $r['c10'] ),
			'events'           => (int) $r['c11'],
			'avg_time_on_page' => (int) round( self::ratio( $r['c12'], $r['c13'], 2 ) ),
			'avg_scroll'       => (int) round( self::ratio( $r['c14'], $r['c15'], 2 ) ),
			'conversion_rate'  => self::ratio( $r['c16'] * 100, $visits, 2 ),
		);
	}

	/**
	 * KPIs.
	 *
	 * @return array
	 */
	public function summary() {
		$s    = self::summary_from( $this->one( 'summary' ) );
		$perf = $this->one( 'perf' );
		$s['avg_load_ms'] = (int) round( self::ratio( $perf['c1'], $perf['c2'], 2 ) );
		return $s;
	}

	/**
	 * Daily (or hourly for ≤ 2 days) series.
	 *
	 * @return array
	 */
	public function timeseries() {
		global $wpdb;
		$hourly = $this->days() <= 2;
		$tv     = NTAA_DB::t( 'visits' );
		$site   = (int) $this->site;
		$points = array();

		if ( $hourly ) {
			$vt   = NTAA_Archive::time_sql( 'v.started_at', array( array( $this->from . ' 00:00:00', $this->to . ' 23:59:59' ) ) );
			$rows = $wpdb->get_results( "SELECT DATE_FORMAT(v.started_at, '%Y-%m-%d %H:00') AS k, COUNT(*) AS visits, COUNT(DISTINCT v.visitor_hash) AS visitors, SUM(v.pageviews) AS pageviews, SUM(v.pageviews <= 1) AS bounces, SUM(v.duration) AS dur, SUM(v.goal_conversions) AS conv, SUM(v.revenue) AS rev, SUM(v.orders) AS ord FROM {$tv} v WHERE v.site_id " . NTAA_Archive::site_in( $site ) . " AND {$vt}{$this->seg_sql} GROUP BY k", OBJECT_K ); // phpcs:ignore WordPress.DB
			$end  = strtotime( $this->to . ' 23:00:00' );
			for ( $ts = strtotime( $this->from . ' 00:00:00' ); $ts <= $end; $ts += HOUR_IN_SECONDS ) {
				$k        = gmdate( 'Y-m-d H:00', $ts );
				$r        = isset( $rows[ $k ] ) ? $rows[ $k ] : null;
				$points[] = self::point( $k, $r ? (int) $r->visits : 0, $r ? (int) $r->visitors : 0, $r ? (int) $r->pageviews : 0, $r ? (int) $r->bounces : 0, $r ? (int) $r->dur : 0, $r ? (int) $r->conv : 0, $r ? (float) $r->rev : 0, $r ? (int) $r->ord : 0 );
			}
			return array(
				'granularity' => 'hour',
				'points'      => $points,
			);
		}

		$daily = array();
		if ( $this->archived() ) {
			foreach ( NTAA_Archive::read_daily( $this->akey( 'summary' ), $this->site, $this->from, $this->to ) as $day => $r ) {
				$daily[ $day ] = $r;
			}
		}
		$raw_days = $this->raw_days();
		if ( $raw_days ) {
			$vt   = NTAA_Archive::time_sql( 'v.started_at', NTAA_Archive::blocks( $raw_days ) );
			$rows = $wpdb->get_results( "SELECT DATE(v.started_at) AS k, COUNT(*) AS c1, COUNT(DISTINCT v.visitor_hash) AS c2, SUM(v.pageviews) AS c3, SUM(v.pageviews <= 1) AS c4, SUM(v.duration) AS c5, SUM(v.goal_conversions) AS c6, SUM(ROUND(v.revenue * 100)) AS c9, SUM(v.orders) AS c8 FROM {$tv} v WHERE v.site_id " . NTAA_Archive::site_in( $site ) . " AND {$vt}{$this->seg_sql} GROUP BY DATE(v.started_at)", ARRAY_A ); // phpcs:ignore WordPress.DB
			foreach ( $rows as $r ) {
				$daily[ $r['k'] ] = NTAA_Archive::normalize( array( $r ) )[0];
			}
		}
		for ( $d = $this->from; $d <= $this->to; $d = gmdate( 'Y-m-d', strtotime( $d . ' +1 day' ) ) ) {
			$r        = isset( $daily[ $d ] ) ? $daily[ $d ] : null;
			$points[] = self::point( $d, $r ? $r['c1'] : 0, $r ? $r['c2'] : 0, $r ? $r['c3'] : 0, $r ? $r['c4'] : 0, $r ? $r['c5'] : 0, $r ? $r['c6'] : 0, $r ? $r['c9'] / 100 : 0, $r ? $r['c8'] : 0 );
		}
		return array(
			'granularity' => 'day',
			'points'      => $points,
		);
	}

	/**
	 * One chart point.
	 */
	private static function point( $k, $visits, $visitors, $pv, $bounces, $dur, $conv, $rev, $orders ) {
		return array(
			'date'         => $k,
			'visitors'     => (int) $visitors,
			'visits'       => (int) $visits,
			'pageviews'    => (int) $pv,
			'bounce_rate'  => self::ratio( $bounces * 100, $visits ),
			'avg_duration' => (int) round( self::ratio( $dur, $visits, 2 ) ),
			'conversions'  => (int) $conv,
			'revenue'      => round( (float) $rev, 2 ),
			'orders'       => (int) $orders,
		);
	}

	/* ---------------------------------------------------------------
	 * Grouped reports
	 * ------------------------------------------------------------- */

	/**
	 * Visit-family row → named metrics.
	 *
	 * @param array $r Row.
	 * @return array
	 */
	private static function visit_metrics( $r ) {
		$v = (int) $r['c1'];
		return array(
			'visits'          => $v,
			'pageviews'       => (int) $r['c2'],
			'bounce_rate'     => self::ratio( $r['c3'] * 100, $v ),
			'avg_duration'    => (int) round( self::ratio( $r['c4'], $v, 2 ) ),
			'pages_per_visit' => self::ratio( $r['c2'], $v, 1 ),
			'conversions'     => (int) $r['c5'],
			'conversion_rate' => self::ratio( $r['c11'] * 100, $v, 2 ),
			'goal_value'      => round( $r['c6'] / 100, 2 ),
			'orders'          => (int) $r['c7'],
			'revenue'         => round( $r['c8'] / 100, 2 ),
			'new_visits'      => (int) $r['c9'],
		);
	}

	/**
	 * Generic visit-family report.
	 *
	 * @param string $key   Report key.
	 * @param int    $limit Limit.
	 * @param string $sort  Sort column (c1 visits, c8 revenue…).
	 * @return array
	 */
	public function visit_report( $key, $limit = 50, $sort = 'c1' ) {
		$out = array();
		foreach ( self::top( $this->rows( $key ), $sort, $limit ) as $r ) {
			$out[] = array( 'name' => $r['label'] ) + self::visit_metrics( $r );
		}
		return $out;
	}

	/**
	 * Country / device / browser… (kept for compatibility).
	 *
	 * @param string $col   Dimension.
	 * @param int    $limit Limit.
	 * @return array
	 */
	public function dimension( $col, $limit = 30 ) {
		$map = array(
			'device'   => 'device',
			'browser'  => 'browser',
			'os'       => 'os',
			'country'  => 'country',
			'language' => 'language',
			'screen'   => 'screen',
		);
		return isset( $map[ $col ] ) ? $this->visit_report( $map[ $col ], $limit ) : array();
	}

	/**
	 * Pages.
	 *
	 * @param int $limit Limit.
	 * @return array
	 */
	public function top_pages( $limit = 50 ) {
		$out = array();
		foreach ( self::top( $this->rows( 'page' ), 'c1', $limit ) as $r ) {
			$out[] = array(
				'page'         => $r['label'],
				'title'        => $r['extra'],
				'views'        => $r['c1'],
				'unique_views' => $r['c2'],
				'avg_time'     => (int) round( self::ratio( $r['c3'], $r['c4'], 2 ) ),
				'avg_scroll'   => (int) round( self::ratio( $r['c5'], $r['c6'], 2 ) ),
				'avg_load'     => (int) round( self::ratio( $r['c7'], $r['c8'], 2 ) ),
				'lcp'          => (int) round( self::ratio( $r['c9'], $r['c10'], 2 ) ),
				'cls'          => self::ratio( $r['c11'] / 1000, $r['c12'], 3 ),
				'inp'          => (int) round( self::ratio( $r['c13'], $r['c14'], 2 ) ),
				'lcp_good'     => self::ratio( $r['c15'] * 100, $r['c10'], 0 ),
				'vitals_n'     => $r['c12'],
			);
		}
		return $out;
	}

	/**
	 * Page titles.
	 *
	 * @param int $limit Limit.
	 * @return array
	 */
	public function page_titles( $limit = 100 ) {
		$out = array();
		foreach ( self::top( $this->rows( 'ptitle' ), 'c1', $limit ) as $r ) {
			$out[] = array(
				'title'        => $r['label'],
				'page'         => $r['extra'],
				'views'        => $r['c1'],
				'unique_views' => $r['c2'],
				'avg_time'     => (int) round( self::ratio( $r['c3'], $r['c4'], 2 ) ),
				'avg_scroll'   => (int) round( self::ratio( $r['c5'], $r['c6'], 2 ) ),
				'avg_load'     => (int) round( self::ratio( $r['c7'], $r['c8'], 2 ) ),
			);
		}
		return $out;
	}

	/**
	 * Row evolution: daily values of one row of a report.
	 *
	 * @param string $key   Report key.
	 * @param string $label Row label.
	 * @return array|WP_Error
	 */
	public function evolution( $key, $label ) {
		$reports = NTAA_Archive::reports();
		if ( ! isset( $reports[ $key ] ) || in_array( $reports[ $key ]['family'], array( 'summary', 'perf', 'ecom', 'steps', 'goalby' ), true ) ) {
			return new WP_Error( 'ntaa_report', __( 'This report has no row evolution.', 'nodethemes-analytics-ai' ), array( 'status' => 400 ) );
		}
		$raw = $this->raw_days();
		if ( ! $this->archived() && count( $raw ) > 120 ) {
			return new WP_Error( 'ntaa_range', __( 'Row evolution of a live segment is limited to 120 days. Choose a shorter period or save the segment with “Pre-process (fast)”.', 'nodethemes-analytics-ai' ), array( 'status' => 400 ) );
		}
		$days   = NTAA_Archive::evolution( $key, $label, $this->site, $this->from, $this->to, $this->seg_id, $this->seg_sql, $raw );
		$points = array();
		$zero   = array_fill_keys( array_map( function ( $i ) { return 'c' . $i; }, range( 1, 16 ) ), 0 );
		for ( $d = $this->from; $d <= $this->to; $d = gmdate( 'Y-m-d', strtotime( $d . ' +1 day' ) ) ) {
			$r        = isset( $days[ $d ] ) ? $days[ $d ] : null;
			$points[] = array( 'date' => $d ) + ( $r ? array_intersect_key( $r, $zero ) : $zero );
		}
		return array(
			'report' => $key,
			'family' => $reports[ $key ]['family'],
			'label'  => $label,
			'points' => $points,
		);
	}

	/**
	 * Entry pages.
	 *
	 * @param int $limit Limit.
	 * @return array
	 */
	public function entry_pages( $limit = 30 ) {
		$out = array();
		foreach ( $this->visit_report( 'entry', $limit ) as $r ) {
			$out[] = array(
				'page'         => $r['name'],
				'entrances'    => $r['visits'],
				'bounce_rate'  => $r['bounce_rate'],
				'avg_duration' => $r['avg_duration'],
				'conversion_rate' => $r['conversion_rate'],
				'revenue'      => $r['revenue'],
			);
		}
		return $out;
	}

	/**
	 * Exit pages.
	 *
	 * @param int $limit Limit.
	 * @return array
	 */
	public function exit_pages( $limit = 30 ) {
		$out = array();
		foreach ( $this->visit_report( 'exit', $limit ) as $r ) {
			$out[] = array(
				'page'  => $r['name'],
				'exits' => $r['visits'],
			);
		}
		return $out;
	}

	/**
	 * Site search.
	 *
	 * @param int $limit Limit.
	 * @return array
	 */
	public function site_search( $limit = 50 ) {
		$out = array();
		foreach ( self::top( $this->rows( 'search' ), 'c1', $limit ) as $r ) {
			$out[] = array(
				'term'       => $r['label'],
				'category'   => $r['extra'],
				'searches'   => $r['c1'],
				'no_results' => $r['c2'],
			);
		}
		return $out;
	}

	/**
	 * Searches that found nothing.
	 *
	 * @param int $limit Limit.
	 * @return array
	 */
	public function search_no_results( $limit = 30 ) {
		$rows = array_filter(
			$this->rows( 'search' ),
			function ( $r ) {
				return $r['c2'] > 0;
			}
		);
		$out  = array();
		foreach ( self::top( $rows, 'c2', $limit ) as $r ) {
			$out[] = array(
				'term'     => $r['label'],
				'searches' => $r['c2'],
			);
		}
		return $out;
	}

	/**
	 * Channels.
	 *
	 * @return array
	 */
	public function referrer_types() {
		$out = array();
		foreach ( $this->visit_report( 'channel', 20 ) as $r ) {
			$r['type'] = $r['name'];
			$out[]     = $r;
		}
		return $out;
	}

	/**
	 * Referrers.
	 *
	 * @param int $limit Limit.
	 * @return array
	 */
	public function referrers( $limit = 50 ) {
		$out = array();
		foreach ( $this->visit_report( 'referrer', $limit ) as $r ) {
			$p     = explode( NTAA_Archive::SEP, $r['name'] );
			$out[] = array(
				'host' => '__other__' === $r['name'] ? '__other__' : $p[0],
				'type' => isset( $p[1] ) ? $p[1] : '',
			) + $r;
		}
		return $out;
	}

	/**
	 * Campaigns.
	 *
	 * @param int $limit Limit.
	 * @return array
	 */
	public function campaigns( $limit = 50 ) {
		$out = array();
		foreach ( $this->visit_report( 'campaign', $limit ) as $r ) {
			$p     = explode( NTAA_Archive::SEP, $r['name'] );
			$out[] = array(
				'campaign' => '__other__' === $r['name'] ? '__other__' : $p[0],
				'source'   => isset( $p[1] ) ? $p[1] : '',
				'medium'   => isset( $p[2] ) ? $p[2] : '',
			) + $r;
		}
		return $out;
	}

	/**
	 * Two-part label report (term/content of campaigns, regions, cities).
	 *
	 * @param string $key   Report.
	 * @param array  $names Part names.
	 * @param int    $limit Limit.
	 * @return array
	 */
	public function split_report( $key, $names, $limit = 50 ) {
		$out = array();
		foreach ( $this->visit_report( $key, $limit ) as $r ) {
			$p   = explode( NTAA_Archive::SEP, $r['name'] );
			$row = array();
			foreach ( $names as $i => $n ) {
				$row[ $n ] = '__other__' === $r['name'] ? ( 0 === $i ? '__other__' : '' ) : ( isset( $p[ $i ] ) ? $p[ $i ] : '' );
			}
			$out[] = $row + $r;
		}
		return $out;
	}

	/**
	 * Visits by weekday (1 = Sunday … 7 = Saturday) × hour.
	 *
	 * @return array
	 */
	public function heatmap() {
		$grid = array_fill( 1, 7, array_fill( 0, 24, 0 ) );
		foreach ( $this->rows( 'dowhour' ) as $r ) {
			$p = explode( '-', $r['label'] );
			if ( 2 === count( $p ) && isset( $grid[ (int) $p[0] ] ) ) {
				$grid[ (int) $p[0] ][ (int) $p[1] ] += $r['c1'];
			}
		}
		return $grid;
	}

	/**
	 * Events.
	 *
	 * @param int $limit Limit.
	 * @return array
	 */
	public function events( $limit = 50 ) {
		$out = array();
		foreach ( self::top( $this->rows( 'event' ), 'c1', $limit ) as $r ) {
			$p     = explode( NTAA_Archive::SEP, $r['label'] );
			$out[] = array(
				'category'  => '__other__' === $r['label'] ? '__other__' : $p[0],
				'action'    => isset( $p[1] ) ? $p[1] : '',
				'total'     => $r['c1'],
				'visits'    => $r['c2'],
				'value_sum' => $r['c3'] ? round( $r['c3'] / 100, 2 ) : null,
			);
		}
		return $out;
	}

	/**
	 * Event labels.
	 *
	 * @param int $limit Limit.
	 * @return array
	 */
	public function event_labels( $limit = 50 ) {
		$out = array();
		foreach ( self::top( $this->rows( 'evlabel' ), 'c1', $limit ) as $r ) {
			$p     = explode( NTAA_Archive::SEP, $r['label'] );
			$out[] = array(
				'category' => '__other__' === $r['label'] ? '__other__' : $p[0],
				'action'   => isset( $p[1] ) ? $p[1] : '',
				'label'    => isset( $p[2] ) ? $p[2] : '',
				'total'    => $r['c1'],
			);
		}
		return $out;
	}

	/**
	 * Performance & Core Web Vitals.
	 *
	 * @return array
	 */
	public function performance() {
		$p = $this->one( 'perf' );
		return array(
			'load'      => (int) round( self::ratio( $p['c1'], $p['c2'], 2 ) ),
			'lcp'       => (int) round( self::ratio( $p['c3'], $p['c4'], 2 ) ),
			'cls'       => self::ratio( $p['c5'] / 1000, $p['c6'], 3 ),
			'inp'       => (int) round( self::ratio( $p['c7'], $p['c8'], 2 ) ),
			'ttfb'      => (int) round( self::ratio( $p['c9'], $p['c10'], 2 ) ),
			'fcp'       => (int) round( self::ratio( $p['c11'], $p['c12'], 2 ) ),
			'lcp_good'  => self::ratio( $p['c13'] * 100, $p['c4'], 0 ),
			'cls_good'  => self::ratio( $p['c14'] * 100, $p['c6'], 0 ),
			'inp_good'  => self::ratio( $p['c15'] * 100, $p['c8'], 0 ),
			'ttfb_good' => self::ratio( $p['c16'] * 100, $p['c10'], 0 ),
			'samples'   => (int) $p['c6'],
		);
	}

	/* ---------------------------------------------------------------
	 * Goals & ecommerce
	 * ------------------------------------------------------------- */

	/**
	 * Goals overview.
	 *
	 * @return array
	 */
	public function goals() {
		$visits = max( 1, (int) $this->one( 'summary' )['c1'] );
		$rows   = $this->rows( 'goal' );
		$out    = array();
		foreach ( NTAA_Goals::all() as $id => $g ) {
			$r     = isset( $rows[ (string) $id ] ) ? $rows[ (string) $id ] : array( 'c1' => 0, 'c2' => 0, 'c3' => 0 );
			$out[] = array(
				'id'              => (int) $id,
				'name'            => $g['name'],
				'type'            => $g['type'],
				'active'          => (int) $g['active'],
				'conversions'     => (int) $r['c1'],
				'value'           => round( $r['c2'] / 100, 2 ),
				'converted_visits' => (int) $r['c3'],
				'conversion_rate' => self::ratio( $r['c3'] * 100, $visits, 2 ),
			);
		}
		return $out;
	}

	/**
	 * One goal broken down by a dimension.
	 *
	 * @param int    $goal Goal id.
	 * @param string $by   channel | device | country | campaign | entry.
	 * @param int    $limit Limit.
	 * @return array
	 */
	public function goal_by( $goal, $by, $limit = 20 ) {
		$key = 'goal_' . $by;
		if ( ! isset( NTAA_Archive::reports()[ $key ] ) ) {
			return array();
		}
		$rows = array();
		foreach ( $this->rows( $key ) as $r ) {
			$p = explode( NTAA_Archive::SEP, $r['label'], 2 );
			if ( (int) $p[0] === (int) $goal || ( 0 === (int) $goal ) ) {
				$r['label'] = isset( $p[1] ) ? $p[1] : '';
				$k          = $r['label'];
				if ( isset( $rows[ $k ] ) ) {
					for ( $i = 1; $i <= 16; $i++ ) {
						$rows[ $k ][ 'c' . $i ] += $r[ 'c' . $i ];
					}
				} else {
					$rows[ $k ] = $r;
				}
			}
		}
		$out = array();
		foreach ( self::top( $rows, 'c1', $limit ) as $r ) {
			$out[] = array(
				'name'        => $r['label'],
				'conversions' => $r['c1'],
				'value'       => round( $r['c2'] / 100, 2 ),
			);
		}
		return $out;
	}

	/**
	 * Goal conversions per day.
	 *
	 * @param int $goal Goal id (0 = all).
	 * @return array
	 */
	public function goal_series( $goal = 0 ) {
		global $wpdb;
		$out  = array();
		$tc   = NTAA_DB::t( 'conversions' );
		$tv   = NTAA_DB::t( 'visits' );
		$daily = array();
		if ( $this->archived() ) {
			$ta   = NTAA_DB::t( 'archive' );
			$cond = $goal ? $wpdb->prepare( ' AND label = %s', (string) (int) $goal ) : '';
			foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT day, SUM(c1) AS n, SUM(c2) AS v FROM {$ta} WHERE site_id = %d AND report = %s AND day BETWEEN %s AND %s{$cond} GROUP BY day", $this->site, $this->akey( 'goal' ), $this->from, $this->to ) ) as $r ) { // phpcs:ignore WordPress.DB
				$daily[ $r->day ] = array( (int) $r->n, (int) $r->v );
			}
		}
		$raw = $this->raw_days();
		if ( $raw ) {
			$ct   = NTAA_Archive::time_sql( 'c.created_at', NTAA_Archive::blocks( $raw ) );
			$cond = $goal ? ' AND c.goal_id = ' . (int) $goal : '';
			$join = $this->segmented() ? " INNER JOIN {$tv} v ON v.id = c.visit_id" : '';
			foreach ( (array) $wpdb->get_results( "SELECT DATE(c.created_at) AS day, COUNT(*) AS n, SUM(ROUND(c.value * 100)) AS v FROM {$tc} c{$join} WHERE c.site_id " . NTAA_Archive::site_in( $this->site ) . " AND {$ct}{$cond}{$this->seg_sql} GROUP BY DATE(c.created_at)" ) as $r ) { // phpcs:ignore WordPress.DB
				$daily[ $r->day ] = array( (int) $r->n, (int) $r->v );
			}
		}
		for ( $d = $this->from; $d <= $this->to; $d = gmdate( 'Y-m-d', strtotime( $d . ' +1 day' ) ) ) {
			$out[] = array(
				'date'        => $d,
				'conversions' => isset( $daily[ $d ] ) ? $daily[ $d ][0] : 0,
				'value'       => isset( $daily[ $d ] ) ? round( $daily[ $d ][1] / 100, 2 ) : 0,
			);
		}
		return $out;
	}

	/**
	 * Ecommerce overview.
	 *
	 * @return array
	 */
	public function ecommerce() {
		$e      = $this->one( 'ecom' );
		$s      = $this->one( 'steps' );
		$visits = (int) $this->one( 'summary' )['c1'];
		$orders = (int) $e['c1'];
		return array(
			'orders'          => $orders,
			'revenue'         => round( $e['c2'] / 100, 2 ),
			'items'           => (int) $e['c3'],
			'tax'             => round( $e['c4'] / 100, 2 ),
			'shipping'        => round( $e['c5'] / 100, 2 ),
			'discount'        => round( $e['c6'] / 100, 2 ),
			'subtotal'        => round( $e['c7'] / 100, 2 ),
			'new_customers'   => (int) $e['c8'],
			'aov'             => $orders ? round( $e['c2'] / 100 / $orders, 2 ) : 0,
			'conversion_rate' => self::ratio( $s['c4'] * 100, $visits, 2 ),
			'steps'           => array(
				'visits'       => $visits,
				'product_view' => (int) $s['c1'],
				'add_to_cart'  => (int) $s['c2'],
				'checkout'     => (int) $s['c3'],
				'purchase'     => (int) $s['c4'],
			),
			'abandoned'       => (int) $s['c5'],
			'abandoned_value' => round( $s['c6'] / 100, 2 ),
			'currency'        => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : '',
		);
	}

	/**
	 * Products.
	 *
	 * @param int $limit Limit.
	 * @return array
	 */
	public function products( $limit = 50 ) {
		$out = array();
		foreach ( self::top( $this->rows( 'product' ), 'c4', $limit ) as $r ) {
			$out[] = array(
				'id'         => '__other__' === $r['label'] ? 0 : (int) $r['label'],
				'name'       => '__other__' === $r['label'] ? '__other__' : $r['extra'],
				'views'      => $r['c1'],
				'adds'       => $r['c2'],
				'sold'       => $r['c3'],
				'revenue'    => round( $r['c4'] / 100, 2 ),
				'orders'     => $r['c5'],
				'conversion' => self::ratio( $r['c5'] * 100, $r['c1'], 1 ),
			);
		}
		return $out;
	}

	/**
	 * Product categories.
	 *
	 * @param int $limit Limit.
	 * @return array
	 */
	public function categories( $limit = 30 ) {
		$out = array();
		foreach ( self::top( $this->rows( 'category' ), 'c2', $limit ) as $r ) {
			$out[] = array(
				'name'    => $r['label'],
				'sold'    => $r['c1'],
				'revenue' => round( $r['c2'] / 100, 2 ),
				'orders'  => $r['c3'],
			);
		}
		return $out;
	}

	/* ---------------------------------------------------------------
	 * Real-time & bundles
	 * ------------------------------------------------------------- */

	/**
	 * Real-time (last 30 minutes).
	 *
	 * @param int $site Site.
	 * @return array
	 */
	public static function realtime( $site = 1 ) {
		global $wpdb;
		$v     = NTAA_DB::t( 'visits' );
		$p     = NTAA_DB::t( 'pageviews' );
		$site  = max( 1, (int) $site );
		$sin   = NTAA_Archive::site_in( $site );
		$now   = time();
		$since = wp_date( 'Y-m-d H:i:s', $now - 30 * MINUTE_IN_SECONDS );
		$five  = wp_date( 'Y-m-d H:i:s', $now - 5 * MINUTE_IN_SECONDS );

		$active30 = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT visitor_hash) FROM {$v} WHERE site_id {$sin} AND last_activity >= %s", $since ) ); // phpcs:ignore WordPress.DB
		$active5  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT visitor_hash) FROM {$v} WHERE site_id {$sin} AND last_activity >= %s", $five ) ); // phpcs:ignore WordPress.DB
		$per_min  = $wpdb->get_results( $wpdb->prepare( "SELECT DATE_FORMAT(created_at, '%%Y-%%m-%%d %%H:%%i') AS m, COUNT(*) AS c FROM {$p} WHERE site_id {$sin} AND created_at >= %s GROUP BY m", $since ), OBJECT_K ); // phpcs:ignore WordPress.DB
		$minutes  = array();
		for ( $i = 29; $i >= 0; $i-- ) {
			$k         = wp_date( 'Y-m-d H:i', $now - $i * MINUTE_IN_SECONDS );
			$minutes[] = array(
				'minute' => $k,
				'views'  => isset( $per_min[ $k ] ) ? (int) $per_min[ $k ]->c : 0,
			);
		}
		$recent = $wpdb->get_results( $wpdb->prepare( "SELECT p.page, p.title, p.created_at, v.id AS visit_id, v.device, v.browser, v.os, v.country, v.region, v.city, v.referrer_type, v.referrer_host, v.pageviews AS visit_pages FROM {$p} p INNER JOIN {$v} v ON v.id = p.visit_id WHERE p.site_id {$sin} AND p.created_at >= %s ORDER BY p.id DESC LIMIT 40", $since ), ARRAY_A ); // phpcs:ignore WordPress.DB
		$pages  = $wpdb->get_results( $wpdb->prepare( "SELECT page, MAX(title) AS title, COUNT(*) AS views FROM {$p} WHERE site_id {$sin} AND created_at >= %s GROUP BY page ORDER BY views DESC LIMIT 10", $since ), ARRAY_A ); // phpcs:ignore WordPress.DB
		$geo    = $wpdb->get_results( $wpdb->prepare( "SELECT country, COUNT(DISTINCT visitor_hash) AS visitors FROM {$v} WHERE site_id {$sin} AND last_activity >= %s GROUP BY country", $since ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return array(
			'active_30' => $active30,
			'active_5'  => $active5,
			'minutes'   => $minutes,
			'recent'    => $recent,
			'pages'     => array_map( array( __CLASS__, 'intify' ), (array) $pages ),
			'countries' => array_map( array( __CLASS__, 'intify' ), (array) $geo ),
			'backlog'   => (int) get_option( 'ntaa_queue_backlog', 0 ),
			'now'       => wp_date( 'Y-m-d H:i:s' ),
		);
	}

	/**
	 * Everything the dashboard needs for one range.
	 *
	 * @param string $part all | overview | content | sources | audience | engagement | events | goals | ecommerce.
	 * @return array
	 */
	public function report( $part = 'all' ) {
		$all  = 'all' === $part;
		$prev = $this->previous();
		$r    = array(
			'range'          => array( 'from' => $this->from, 'to' => $this->to, 'days' => $this->days() ),
			'previous_range' => array( 'from' => $prev->from, 'to' => $prev->to ),
			'segmented'      => $this->segmented(),
			'seg_fast'       => $this->seg_id > 0,
			'seg_pending'    => $this->pending_days(),
			'site'           => $this->site,
			'cookieless'     => (int) NTAA_Settings::get( 'cookieless' ),
			'summary'        => $this->summary(),
			'previous'       => $prev->summary(),
			'timeseries'     => $this->timeseries(),
		);
		if ( $all || 'overview' === $part ) {
			$r['pages']          = $this->top_pages( 10 );
			$r['referrer_types'] = $this->referrer_types();
			$r['devices']        = $this->visit_report( 'device', 10 );
			$r['countries']      = $this->visit_report( 'country', 250 );
			$r['heatmap']        = $this->heatmap();
		}
		if ( $all || 'content' === $part ) {
			$r['pages']             = $this->top_pages( 100 );
			$r['page_titles']       = $this->page_titles( 100 );
			$r['titles_pending']    = NTAA_Archive::new_reports_pending();
			$r['entry_pages']       = $this->entry_pages( 50 );
			$r['exit_pages']        = $this->exit_pages( 50 );
			$r['site_search']       = $this->site_search( 50 );
			$r['search_no_results'] = $this->search_no_results( 30 );
			$r['performance']       = $this->performance();
		}
		if ( $all || 'sources' === $part ) {
			$r['referrer_types']   = $this->referrer_types();
			$r['referrers']        = $this->referrers( 100 );
			$r['ai_assistants']    = $this->visit_report( 'ai', 30 );
			$r['campaigns']        = $this->campaigns( 100 );
			$r['campaign_terms']   = $this->split_report( 'campaign_term', array( 'term', 'campaign' ), 50 );
			$r['campaign_content'] = $this->split_report( 'campaign_content', array( 'content', 'campaign' ), 50 );
		}
		if ( $all || 'audience' === $part ) {
			$r['devices']   = $this->visit_report( 'device', 10 );
			$r['browsers']  = $this->visit_report( 'browser', 20 );
			$r['os']        = $this->visit_report( 'os', 20 );
			$r['countries'] = $this->visit_report( 'country', 250 );
			$r['regions']   = $this->split_report( 'region', array( 'country', 'region' ), 100 );
			$r['cities']    = $this->split_report( 'city', array( 'country', 'region', 'city' ), 100 );
			$r['languages'] = $this->visit_report( 'language', 30 );
			$r['screens']   = $this->visit_report( 'screen', 20 );
			$r['dims']      = array();
			$names          = (array) NTAA_Settings::get( 'custom_dims', array() );
			foreach ( $names as $i => $n ) {
				if ( '' !== trim( (string) $n ) ) {
					$r['dims'][] = array(
						'index' => $i + 1,
						'name'  => $n,
						'rows'  => $this->visit_report( 'dim' . ( $i + 1 ), 30 ),
					);
				}
			}
		}
		if ( $all || 'engagement' === $part ) {
			$r['returning']   = $this->visit_report( 'returning', 5 );
			$r['duration_b']  = $this->visit_report( 'duration_b', 20 );
			$r['pages_b']     = $this->visit_report( 'pages_b', 20 );
			$r['visitnum_b']  = $this->visit_report( 'visitnum_b', 20 );
			$r['dayssince_b'] = $this->visit_report( 'dayssince_b', 20 );
			$r['hours']       = $this->visit_report( 'hour', 24 );
			$r['localhours']  = $this->visit_report( 'localhour', 24 );
			$r['heatmap']     = $this->heatmap();
		}
		if ( $all || 'events' === $part ) {
			$r['events']       = $this->events( 100 );
			$r['event_labels'] = $this->event_labels( 100 );
			$r['performance']  = $this->performance();
		}
		if ( $all || 'goals' === $part ) {
			$r['goals']        = $this->goals();
			$r['goal_series']  = $this->goal_series( 0 );
			$r['goal_channel'] = $this->goal_by( 0, 'channel' );
			$r['goal_device']  = $this->goal_by( 0, 'device' );
			$r['goal_country'] = $this->goal_by( 0, 'country' );
			$r['goal_campaign'] = $this->goal_by( 0, 'campaign' );
			$r['goal_entry']   = $this->goal_by( 0, 'entry' );
		}
		if ( ( $all || 'ecommerce' === $part ) && NTAA_Ecommerce::active() || 'ecommerce' === $part ) {
			$r['ecommerce']          = $this->ecommerce();
			$r['ecommerce_previous'] = $prev->ecommerce();
			$r['products']           = $this->products( 100 );
			$r['categories']         = $this->categories( 50 );
			$r['revenue_channel']    = $this->visit_report( 'channel', 20, 'c8' );
			$r['revenue_campaign']   = $this->campaigns( 30 );
			$r['revenue_country']    = $this->visit_report( 'country', 30, 'c8' );
			$r['revenue_device']     = $this->visit_report( 'device', 10, 'c8' );
		}
		return apply_filters( 'ntaa_report', $r, $this, $part );
	}

	/**
	 * Cast numeric strings.
	 *
	 * @param array $row Row.
	 * @return array
	 */
	public static function intify( $row ) {
		foreach ( $row as $k => $val ) {
			if ( is_string( $val ) && is_numeric( $val ) && ! in_array( $k, array( 'page', 'name', 'host', 'title', 'label', 'campaign', 'source', 'medium', 'term', 'category', 'action', 'country' ), true ) ) {
				$row[ $k ] = false !== strpos( $val, '.' ) ? (float) $val : (int) $val;
			}
		}
		return $row;
	}
}
