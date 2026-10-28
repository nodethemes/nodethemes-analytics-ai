<?php
/**
 * Report definitions and daily archiving.
 *
 * Every report is defined once and can be computed from the raw visit log for any period
 * (with or without a segment). Finished days are also stored in the archive table, so a
 * report over months or years only sums a few rows per day instead of scanning millions
 * of raw rows. Today is always read live from the raw log.
 *
 * Column meaning per report family (c1…c16, money in cents, CLS × 1000):
 * - summary: visits, visitors, pageviews, bounces, duration, conversions, goal value, orders, revenue,
 *            new visits, events, time on page sum, time n, scroll sum, scroll n, converted visits
 * - visit:   visits, pageviews, bounces, duration, conversions, goal value, orders, revenue, new visits,
 *            events, converted visits
 * - page:    views, unique views, time sum, time n, scroll sum, scroll n, load sum, load n, LCP sum, LCP n,
 *            CLS sum, vitals n, INP sum, INP n, LCP good
 * - perf:    load sum, load n, LCP sum, LCP n, CLS sum, vitals n, INP sum, INP n, TTFB sum, TTFB n, FCP sum,
 *            FCP n, LCP good, CLS good, INP good, TTFB good
 * - event:   total, unique visits, value sum
 * - search:  searches, searches without results, unique visits
 * - goal:    conversions, value, converted visits
 * - ecom:    orders, revenue, items, tax, shipping, discount, subtotal, new customers
 * - steps:   visits with product view, add to cart, checkout, order; abandoned carts, abandoned value
 * - product: views, adds to cart, quantity sold, revenue, orders, value added to carts
 * - category: quantity sold, revenue, orders
 *
 * @package NodeThemes_Analytics_AI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Archive.
 */
class NTAA_Archive {

	const SEP = "\x1f";

	/**
	 * Report registry.
	 *
	 * @return array
	 */
	public static function reports() {
		$sep  = 'CHAR(31)';
		$list = array(
			'summary'     => array( 'family' => 'summary' ),
			'perf'        => array( 'family' => 'perf' ),
			'channel'     => array( 'family' => 'visit', 'label' => 'v.referrer_type' ),
			'referrer'    => array( 'family' => 'visit', 'label' => "CONCAT(v.referrer_host, {$sep}, v.referrer_type)", 'where' => "v.referrer_host <> ''", 'top' => 1000 ),
			'ai'          => array( 'family' => 'visit', 'label' => 'v.referrer_host', 'where' => "v.referrer_type = 'ai'" ),
			'campaign'    => array( 'family' => 'visit', 'label' => "SUBSTRING(CONCAT(v.utm_campaign, {$sep}, v.utm_source, {$sep}, v.utm_medium), 1, 255)", 'where' => "(v.utm_campaign <> '' OR v.utm_source <> '')", 'top' => 1000 ),
			'campaign_term' => array( 'family' => 'visit', 'label' => "SUBSTRING(CONCAT(v.utm_term, {$sep}, v.utm_campaign), 1, 255)", 'where' => "v.utm_term <> ''", 'top' => 500 ),
			'campaign_content' => array( 'family' => 'visit', 'label' => "SUBSTRING(CONCAT(v.utm_content, {$sep}, v.utm_campaign), 1, 255)", 'where' => "v.utm_content <> ''", 'top' => 500 ),
			'device'      => array( 'family' => 'visit', 'label' => 'v.device' ),
			'browser'     => array( 'family' => 'visit', 'label' => 'v.browser' ),
			'os'          => array( 'family' => 'visit', 'label' => 'v.os' ),
			'country'     => array( 'family' => 'visit', 'label' => 'v.country' ),
			'region'      => array( 'family' => 'visit', 'label' => "SUBSTRING(CONCAT(v.country, {$sep}, v.region), 1, 255)", 'where' => "v.region <> ''", 'top' => 1000 ),
			'city'        => array( 'family' => 'visit', 'label' => "SUBSTRING(CONCAT(v.country, {$sep}, v.region, {$sep}, v.city), 1, 255)", 'where' => "v.city <> ''", 'top' => 1000 ),
			'language'    => array( 'family' => 'visit', 'label' => 'v.language' ),
			'screen'      => array( 'family' => 'visit', 'label' => 'v.screen', 'top' => 200 ),
			'entry'       => array( 'family' => 'visit', 'label' => 'v.entry_page', 'top' => 1000 ),
			'exit'        => array( 'family' => 'visit', 'label' => 'v.exit_page', 'top' => 1000 ),
			'hour'        => array( 'family' => 'visit', 'label' => 'HOUR(v.started_at)' ),
			'localhour'   => array( 'family' => 'visit', 'label' => 'v.local_hour', 'where' => 'v.local_hour < 24' ),
			'dowhour'     => array( 'family' => 'visit', 'label' => "CONCAT(DAYOFWEEK(v.started_at), '-', HOUR(v.started_at))" ),
			'returning'   => array( 'family' => 'visit', 'label' => "IF(v.is_new = 1, 'new', 'returning')" ),
			'duration_b'  => array( 'family' => 'visit', 'label' => "CASE WHEN v.duration < 10 THEN '0-10s' WHEN v.duration < 30 THEN '10-30s' WHEN v.duration < 60 THEN '30-60s' WHEN v.duration < 180 THEN '1-3m' WHEN v.duration < 600 THEN '3-10m' WHEN v.duration < 1800 THEN '10-30m' ELSE '30m+' END" ),
			'pages_b'     => array( 'family' => 'visit', 'label' => "CASE WHEN v.pageviews <= 1 THEN '1' WHEN v.pageviews = 2 THEN '2' WHEN v.pageviews = 3 THEN '3' WHEN v.pageviews = 4 THEN '4' WHEN v.pageviews = 5 THEN '5' WHEN v.pageviews <= 10 THEN '6-10' WHEN v.pageviews <= 20 THEN '11-20' ELSE '20+' END" ),
			'visitnum_b'  => array( 'family' => 'visit', 'label' => "CASE WHEN v.visit_number <= 5 THEN CAST(v.visit_number AS CHAR) WHEN v.visit_number <= 10 THEN '6-10' WHEN v.visit_number <= 25 THEN '11-25' ELSE '26+' END" ),
			'dayssince_b' => array( 'family' => 'visit', 'label' => "CASE WHEN v.days_since_last = 0 THEN '0' WHEN v.days_since_last = 1 THEN '1' WHEN v.days_since_last <= 7 THEN '2-7' WHEN v.days_since_last <= 14 THEN '8-14' WHEN v.days_since_last <= 30 THEN '15-30' WHEN v.days_since_last <= 60 THEN '31-60' WHEN v.days_since_last <= 120 THEN '61-120' ELSE '121+' END", 'where' => 'v.is_new = 0' ),
			'page'        => array( 'family' => 'page', 'top' => 2000 ),
			'ptitle'      => array( 'family' => 'page', 'label' => 'p.title', 'top' => 2000 ),
			'event'       => array( 'family' => 'event', 'top' => 1000 ),
			'evlabel'     => array( 'family' => 'evlabel', 'top' => 1000 ),
			'search'      => array( 'family' => 'search', 'top' => 1000 ),
			'goal'        => array( 'family' => 'goal' ),
			'goal_channel' => array( 'family' => 'goalby', 'dim' => 'v.referrer_type' ),
			'goal_device' => array( 'family' => 'goalby', 'dim' => 'v.device' ),
			'goal_country' => array( 'family' => 'goalby', 'dim' => 'v.country' ),
			'goal_campaign' => array( 'family' => 'goalby', 'dim' => "CONCAT(v.utm_campaign, {$sep}, v.utm_source)", 'where' => "(v.utm_campaign <> '' OR v.utm_source <> '')", 'top' => 500 ),
			'goal_entry'  => array( 'family' => 'goalby', 'dim' => 'v.entry_page', 'top' => 500 ),
			'ecom'        => array( 'family' => 'ecom' ),
			'steps'       => array( 'family' => 'steps' ),
			'product'     => array( 'family' => 'product', 'top' => 2000 ),
			'category'    => array( 'family' => 'category', 'top' => 500 ),
		);
		for ( $i = 1; $i <= 5; $i++ ) {
			$list[ 'dim' . $i ] = array( 'family' => 'visit', 'label' => 'v.dim' . $i, 'where' => "v.dim{$i} <> ''", 'top' => 1000 );
		}
		return apply_filters( 'ntaa_archive_reports', $list );
	}

	/* ---------------------------------------------------------------
	 * Raw computation
	 * ------------------------------------------------------------- */

	/**
	 * SQL condition "column within one of the time blocks".
	 *
	 * @param string $col    Column.
	 * @param array  $blocks List of [from datetime, to datetime].
	 * @return string
	 */
	public static function time_sql( $col, $blocks ) {
		global $wpdb;
		$or = array();
		foreach ( $blocks as $b ) {
			$or[] = $wpdb->prepare( "{$col} BETWEEN %s AND %s", $b[0], $b[1] ); // phpcs:ignore WordPress.DB
		}
		return $or ? '(' . implode( ' OR ', $or ) . ')' : '1=0';
	}

	/**
	 * Compute a report from the raw log.
	 *
	 * @param string $key     Report key.
	 * @param int    $site    Site id.
	 * @param array  $blocks  Time blocks.
	 * @param string $seg_sql Segment SQL for alias v ('' = none).
	 * @param string $only    Only this row label (null = all rows). Used by row evolution.
	 * @return array Rows: label, extra, c1…c16 (ints).
	 */
	public static function raw( $key, $site, $blocks, $seg_sql = '', $only = null ) {
		global $wpdb;
		$reports = self::reports();
		if ( ! isset( $reports[ $key ] ) || ! $blocks ) {
			return array();
		}
		$r    = $reports[ $key ];
		$tv   = NTAA_DB::t( 'visits' );
		$tp   = NTAA_DB::t( 'pageviews' );
		$te   = NTAA_DB::t( 'events' );
		$tc   = NTAA_DB::t( 'conversions' );
		$to   = NTAA_DB::t( 'orders' );
		$ti   = NTAA_DB::t( 'order_items' );
		$site = (int) $site;
		$sin  = self::site_in( $site );
		$w    = isset( $r['where'] ) ? ' AND ' . $r['where'] : '';
		$rows = array();
		$lw   = '';
		if ( null !== $only ) {
			$expr = self::label_expr( $r );
			if ( $expr ) {
				$lw = $wpdb->prepare( " AND {$expr} = %s", (string) $only ); // phpcs:ignore WordPress.DB
			}
		}

		switch ( $r['family'] ) {
			case 'summary':
				$vt   = self::time_sql( 'v.started_at', $blocks );
				$row  = $wpdb->get_row( "SELECT '' AS label, '' AS extra, COUNT(*) AS c1, 0 AS c2, SUM(v.pageviews) AS c3, SUM(v.pageviews <= 1) AS c4, SUM(v.duration) AS c5, SUM(v.goal_conversions) AS c6, SUM(ROUND(v.goal_value * 100)) AS c7, SUM(v.orders) AS c8, SUM(ROUND(v.revenue * 100)) AS c9, SUM(v.is_new) AS c10, SUM(v.events) AS c11, 0 AS c12, 0 AS c13, 0 AS c14, 0 AS c15, SUM(v.goal_conversions > 0) AS c16 FROM {$tv} v WHERE v.site_id {$sin} AND {$vt}{$seg_sql}", ARRAY_A ); // phpcs:ignore WordPress.DB
				$vis  = $wpdb->get_var( "SELECT SUM(n) FROM (SELECT DATE(v.started_at) AS d, COUNT(DISTINCT v.visitor_hash) AS n FROM {$tv} v WHERE v.site_id {$sin} AND {$vt}{$seg_sql} GROUP BY DATE(v.started_at)) x" ); // phpcs:ignore WordPress.DB
				$pt   = self::time_sql( 'p.created_at', $blocks );
				$join = $seg_sql ? " INNER JOIN {$tv} v ON v.id = p.visit_id" : '';
				$pv   = $wpdb->get_row( "SELECT SUM(p.time_on_page) AS c12, SUM(p.time_on_page > 0) AS c13, SUM(p.scroll_depth) AS c14, SUM(p.scroll_depth > 0) AS c15 FROM {$tp} p{$join} WHERE p.site_id {$sin} AND {$pt}{$seg_sql}", ARRAY_A ); // phpcs:ignore WordPress.DB
				if ( $row && (int) $row['c1'] > 0 ) {
					$row['c2'] = (int) $vis;
					$rows[]    = array_merge( $row, array_map( 'intval', (array) $pv ) );
				}
				break;

			case 'perf':
				$pt   = self::time_sql( 'p.created_at', $blocks );
				$join = $seg_sql ? " INNER JOIN {$tv} v ON v.id = p.visit_id" : '';
				$row  = $wpdb->get_row( "SELECT '' AS label, '' AS extra, SUM(p.load_time) AS c1, SUM(p.load_time > 0) AS c2, SUM(IF(p.vitals = 1, p.lcp, 0)) AS c3, SUM(p.vitals = 1 AND p.lcp > 0) AS c4, SUM(IF(p.vitals = 1, p.cls, 0)) AS c5, SUM(p.vitals = 1) AS c6, SUM(IF(p.vitals = 1, p.inp, 0)) AS c7, SUM(p.vitals = 1 AND p.inp > 0) AS c8, SUM(IF(p.vitals = 1, p.ttfb, 0)) AS c9, SUM(p.vitals = 1 AND p.ttfb > 0) AS c10, SUM(IF(p.vitals = 1, p.fcp, 0)) AS c11, SUM(p.vitals = 1 AND p.fcp > 0) AS c12, SUM(p.vitals = 1 AND p.lcp > 0 AND p.lcp <= 2500) AS c13, SUM(p.vitals = 1 AND p.cls <= 100) AS c14, SUM(p.vitals = 1 AND p.inp > 0 AND p.inp <= 200) AS c15, SUM(p.vitals = 1 AND p.ttfb > 0 AND p.ttfb <= 800) AS c16 FROM {$tp} p{$join} WHERE p.site_id {$sin} AND {$pt}{$seg_sql}", ARRAY_A ); // phpcs:ignore WordPress.DB
				if ( $row && ( (int) $row['c2'] > 0 || (int) $row['c6'] > 0 ) ) {
					$rows[] = $row;
				}
				break;

			case 'visit':
				$vt   = self::time_sql( 'v.started_at', $blocks );
				$rows = $wpdb->get_results( "SELECT {$r['label']} AS label, '' AS extra, COUNT(*) AS c1, SUM(v.pageviews) AS c2, SUM(v.pageviews <= 1) AS c3, SUM(v.duration) AS c4, SUM(v.goal_conversions) AS c5, SUM(ROUND(v.goal_value * 100)) AS c6, SUM(v.orders) AS c7, SUM(ROUND(v.revenue * 100)) AS c8, SUM(v.is_new) AS c9, SUM(v.events) AS c10, SUM(v.goal_conversions > 0) AS c11 FROM {$tv} v WHERE v.site_id {$sin} AND {$vt}{$w}{$lw}{$seg_sql} GROUP BY label", ARRAY_A ); // phpcs:ignore WordPress.DB
				break;

			case 'page':
				$pt   = self::time_sql( 'p.created_at', $blocks );
				$join = $seg_sql ? " INNER JOIN {$tv} v ON v.id = p.visit_id" : '';
				$plab = isset( $r['label'] ) ? $r['label'] : 'p.page';
				$pext = 'p.page' === $plab ? 'MAX(p.title)' : 'MAX(p.page)';
				$rows = $wpdb->get_results( "SELECT {$plab} AS label, {$pext} AS extra, COUNT(*) AS c1, COUNT(DISTINCT p.visit_id) AS c2, SUM(p.time_on_page) AS c3, SUM(p.time_on_page > 0) AS c4, SUM(p.scroll_depth) AS c5, SUM(p.scroll_depth > 0) AS c6, SUM(p.load_time) AS c7, SUM(p.load_time > 0) AS c8, SUM(IF(p.vitals = 1, p.lcp, 0)) AS c9, SUM(p.vitals = 1 AND p.lcp > 0) AS c10, SUM(IF(p.vitals = 1, p.cls, 0)) AS c11, SUM(p.vitals = 1) AS c12, SUM(IF(p.vitals = 1, p.inp, 0)) AS c13, SUM(p.vitals = 1 AND p.inp > 0) AS c14, SUM(p.vitals = 1 AND p.lcp > 0 AND p.lcp <= 2500) AS c15 FROM {$tp} p{$join} WHERE p.site_id {$sin} AND {$pt}{$lw}{$seg_sql} GROUP BY label", ARRAY_A ); // phpcs:ignore WordPress.DB
				break;

			case 'event':
			case 'evlabel':
				$et   = self::time_sql( 'e.created_at', $blocks );
				$join = $seg_sql ? " INNER JOIN {$tv} v ON v.id = e.visit_id" : '';
				$lab  = 'event' === $r['family'] ? "CONCAT(e.category, CHAR(31), e.action)" : "SUBSTRING(CONCAT(e.category, CHAR(31), e.action, CHAR(31), e.label), 1, 255)";
				$ew   = 'event' === $r['family'] ? '' : " AND e.label <> ''";
				$rows = $wpdb->get_results( "SELECT {$lab} AS label, '' AS extra, COUNT(*) AS c1, COUNT(DISTINCT e.visit_id) AS c2, SUM(ROUND(COALESCE(e.value, 0) * 100)) AS c3 FROM {$te} e{$join} WHERE e.site_id {$sin} AND {$et}{$ew}{$lw}{$seg_sql} GROUP BY label", ARRAY_A ); // phpcs:ignore WordPress.DB
				break;

			case 'search':
				$pt   = self::time_sql( 'p.created_at', $blocks );
				$join = $seg_sql ? " INNER JOIN {$tv} v ON v.id = p.visit_id" : '';
				$rows = $wpdb->get_results( "SELECT p.search_term AS label, MAX(p.search_cat) AS extra, COUNT(*) AS c1, SUM(p.search_count = 0) AS c2, COUNT(DISTINCT p.visit_id) AS c3 FROM {$tp} p{$join} WHERE p.site_id {$sin} AND {$pt} AND p.search_term <> ''{$lw}{$seg_sql} GROUP BY p.search_term", ARRAY_A ); // phpcs:ignore WordPress.DB
				break;

			case 'goal':
				$ct   = self::time_sql( 'c.created_at', $blocks );
				$join = $seg_sql ? " INNER JOIN {$tv} v ON v.id = c.visit_id" : '';
				$rows = $wpdb->get_results( "SELECT CAST(c.goal_id AS CHAR) AS label, '' AS extra, COUNT(*) AS c1, SUM(ROUND(c.value * 100)) AS c2, COUNT(DISTINCT c.visit_id) AS c3 FROM {$tc} c{$join} WHERE c.site_id {$sin} AND {$ct}{$seg_sql} GROUP BY c.goal_id", ARRAY_A ); // phpcs:ignore WordPress.DB
				break;

			case 'goalby':
				$ct   = self::time_sql( 'c.created_at', $blocks );
				$rows = $wpdb->get_results( "SELECT SUBSTRING(CONCAT(c.goal_id, CHAR(31), {$r['dim']}), 1, 255) AS label, '' AS extra, COUNT(*) AS c1, SUM(ROUND(c.value * 100)) AS c2, COUNT(DISTINCT c.visit_id) AS c3 FROM {$tc} c INNER JOIN {$tv} v ON v.id = c.visit_id WHERE c.site_id {$sin} AND {$ct}{$w}{$seg_sql} GROUP BY label", ARRAY_A ); // phpcs:ignore WordPress.DB
				break;

			case 'ecom':
				$ot   = self::time_sql( 'o.created_at', $blocks );
				$join = $seg_sql ? " INNER JOIN {$tv} v ON v.id = o.visit_id" : '';
				$row  = $wpdb->get_row( "SELECT '' AS label, '' AS extra, COUNT(*) AS c1, SUM(ROUND(o.revenue * 100)) AS c2, SUM(o.items) AS c3, SUM(ROUND(o.tax * 100)) AS c4, SUM(ROUND(o.shipping * 100)) AS c5, SUM(ROUND(o.discount * 100)) AS c6, SUM(ROUND(o.subtotal * 100)) AS c7, SUM(o.new_customer) AS c8 FROM {$to} o{$join} WHERE o.site_id {$sin} AND o.status = 'paid' AND {$ot}{$seg_sql}", ARRAY_A ); // phpcs:ignore WordPress.DB
				if ( $row && (int) $row['c1'] > 0 ) {
					$rows[] = $row;
				}
				break;

			case 'steps':
				$et   = self::time_sql( 'e.created_at', $blocks );
				$ot   = self::time_sql( 'o.created_at', $blocks );
				$joine = $seg_sql ? " INNER JOIN {$tv} v ON v.id = e.visit_id" : '';
				$joino = $seg_sql ? " INNER JOIN {$tv} v ON v.id = o.visit_id" : '';
				$a    = $wpdb->get_row( "SELECT COUNT(DISTINCT CASE WHEN e.action = 'product_view' THEN e.visit_id END) AS c1, COUNT(DISTINCT CASE WHEN e.action = 'add_to_cart' THEN e.visit_id END) AS c2, COUNT(DISTINCT CASE WHEN e.action IN ('checkout', 'checkout_view') THEN e.visit_id END) AS c3 FROM {$te} e{$joine} WHERE e.site_id {$sin} AND e.category = 'ecommerce' AND {$et}{$seg_sql}", ARRAY_A ); // phpcs:ignore WordPress.DB
				$b    = $wpdb->get_var( "SELECT COUNT(DISTINCT o.visit_id) FROM {$to} o{$joino} WHERE o.site_id {$sin} AND o.status = 'paid' AND o.visit_id > 0 AND {$ot}{$seg_sql}" ); // phpcs:ignore WordPress.DB
				$c    = $wpdb->get_row( "SELECT COUNT(*) AS c5, SUM(x.val) AS c6 FROM (SELECT e.visit_id, SUM(ROUND(COALESCE(e.value, 0) * 100)) AS val FROM {$te} e{$joine} WHERE e.site_id {$sin} AND e.category = 'ecommerce' AND e.action = 'add_to_cart' AND {$et}{$seg_sql} GROUP BY e.visit_id) x LEFT JOIN {$to} o2 ON o2.visit_id = x.visit_id AND o2.status = 'paid' WHERE o2.id IS NULL", ARRAY_A ); // phpcs:ignore WordPress.DB
				$row  = array(
					'label' => '',
					'extra' => '',
					'c1'    => (int) $a['c1'],
					'c2'    => (int) $a['c2'],
					'c3'    => (int) $a['c3'],
					'c4'    => (int) $b,
					'c5'    => (int) $c['c5'],
					'c6'    => (int) $c['c6'],
				);
				if ( $row['c1'] || $row['c2'] || $row['c3'] || $row['c4'] ) {
					$rows[] = $row;
				}
				break;

			case 'product':
				$et    = self::time_sql( 'e.created_at', $blocks );
				$it    = self::time_sql( 'i.created_at', $blocks );
				$joine = $seg_sql ? " INNER JOIN {$tv} v ON v.id = e.visit_id" : '';
				$joini = $seg_sql ? " INNER JOIN {$tv} v ON v.id = o.visit_id" : '';
				$ev    = $wpdb->get_results( "SELECT CAST(e.ref_id AS CHAR) AS label, MAX(e.label) AS extra, SUM(e.action = 'product_view') AS c1, SUM(e.action = 'add_to_cart') AS c2, SUM(IF(e.action = 'add_to_cart', ROUND(COALESCE(e.value, 0) * 100), 0)) AS c6 FROM {$te} e{$joine} WHERE e.site_id {$sin} AND e.category = 'ecommerce' AND e.ref_id > 0 AND e.action IN ('product_view', 'add_to_cart') AND {$et}{$seg_sql} GROUP BY e.ref_id", ARRAY_A ); // phpcs:ignore WordPress.DB
				$sold  = $wpdb->get_results( "SELECT CAST(i.product_id AS CHAR) AS label, MAX(i.name) AS extra, SUM(i.qty) AS c3, SUM(ROUND(i.price * i.qty * 100)) AS c4, COUNT(DISTINCT i.order_ref) AS c5 FROM {$ti} i INNER JOIN {$to} o ON o.id = i.order_ref AND o.status = 'paid'{$joini} WHERE i.site_id {$sin} AND {$it}{$seg_sql} GROUP BY i.product_id", ARRAY_A ); // phpcs:ignore WordPress.DB
				$merge = array();
				foreach ( array_merge( (array) $ev, (array) $sold ) as $x ) {
					$k = $x['label'];
					if ( ! isset( $merge[ $k ] ) ) {
						$merge[ $k ] = array( 'label' => $k, 'extra' => '' );
					}
					foreach ( $x as $col => $val ) {
						if ( 'extra' === $col && '' !== (string) $val ) {
							$merge[ $k ]['extra'] = $val;
						} elseif ( 'c' === $col[0] ) {
							$merge[ $k ][ $col ] = ( isset( $merge[ $k ][ $col ] ) ? $merge[ $k ][ $col ] : 0 ) + (int) $val;
						}
					}
				}
				$rows = array_values( $merge );
				break;

			case 'category':
				$it    = self::time_sql( 'i.created_at', $blocks );
				$joini = $seg_sql ? " INNER JOIN {$tv} v ON v.id = o.visit_id" : '';
				$rows  = $wpdb->get_results( "SELECT i.category AS label, '' AS extra, SUM(i.qty) AS c1, SUM(ROUND(i.price * i.qty * 100)) AS c2, COUNT(DISTINCT i.order_ref) AS c3 FROM {$ti} i INNER JOIN {$to} o ON o.id = i.order_ref AND o.status = 'paid'{$joini} WHERE i.site_id {$sin} AND i.category <> '' AND {$it}{$lw}{$seg_sql} GROUP BY i.category", ARRAY_A ); // phpcs:ignore WordPress.DB
				break;

			default:
				$rows = apply_filters( 'ntaa_raw_report', array(), $key, $r, $site, $blocks, $seg_sql );
		}
		$rows = self::normalize( (array) $rows );
		if ( null !== $only && '' === $lw ) {
			$rows = array_values(
				array_filter(
					$rows,
					function ( $x ) use ( $only ) {
						return $x['label'] === (string) $only;
					}
				)
			);
		}
		return $rows;
	}

	/**
	 * SQL expression of a report's row label (for filtering one row), or '' when unknown.
	 *
	 * @param array $r Report definition.
	 * @return string
	 */
	private static function label_expr( $r ) {
		switch ( $r['family'] ) {
			case 'visit':
				return $r['label'];
			case 'page':
				return isset( $r['label'] ) ? $r['label'] : 'p.page';
			case 'event':
				return 'CONCAT(e.category, CHAR(31), e.action)';
			case 'evlabel':
				return 'SUBSTRING(CONCAT(e.category, CHAR(31), e.action, CHAR(31), e.label), 1, 255)';
			case 'search':
				return 'p.search_term';
			case 'category':
				return 'i.category';
		}
		return '';
	}

	/**
	 * Daily values of one row of a report (row evolution).
	 *
	 * @param string $key     Report.
	 * @param string $label   Row label.
	 * @param int    $site    Site.
	 * @param string $from    Y-m-d.
	 * @param string $to      Y-m-d.
	 * @param int    $seg     Pre-processed segment id (0 = none).
	 * @param string $seg_sql Live segment SQL ('' = none).
	 * @param array  $raw_days Days that are read from the raw log.
	 * @return array day => row
	 */
	public static function evolution( $key, $label, $site, $from, $to, $seg, $seg_sql, $raw_days ) {
		global $wpdb;
		$out = array();
		$ta  = NTAA_DB::t( 'archive' );
		if ( ! $seg_sql || $seg ) {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$ta} WHERE site_id = %d AND report = %s AND lkey = %s AND day BETWEEN %s AND %s", $site, self::prefix( $seg ) . $key, md5( (string) $label ), $from, $to ), ARRAY_A ); // phpcs:ignore WordPress.DB
			foreach ( self::normalize( (array) $rows ) as $i => $r ) {
				$out[ $rows[ $i ]['day'] ] = $r;
			}
		}
		$start = microtime( true );
		foreach ( $raw_days as $d ) {
			if ( ( microtime( true ) - $start ) > 25 ) {
				break;
			}
			$rows = self::raw( $key, $site, array( array( $d . ' 00:00:00', $d . ' 23:59:59' ) ), $seg_sql, (string) $label );
			if ( $rows ) {
				$out[ $d ] = $rows[0];
			}
		}
		return $out;
	}

	/* ---------------------------------------------------------------
	 * New reports added by an update: fill them into days archived before
	 * ------------------------------------------------------------- */

	/**
	 * Queue report keys to be computed for every already archived day (runs in the background).
	 *
	 * @param array $keys Report keys.
	 */
	public static function queue_new_reports( $keys ) {
		$job         = get_option( 'ntaa_report_backfill', array() );
		$job         = is_array( $job ) ? $job : array();
		$job['keys'] = array_values( array_unique( array_merge( isset( $job['keys'] ) ? (array) $job['keys'] : array(), (array) $keys ) ) );
		$job['done'] = array();
		update_option( 'ntaa_report_backfill', $job, false );
		if ( ! wp_next_scheduled( 'ntaa_report_backfill' ) ) {
			wp_schedule_single_event( time() + 20, 'ntaa_report_backfill' );
		}
	}

	/**
	 * Process queued new reports for a chunk of archived days.
	 *
	 * @param int $max Days per run.
	 * @return bool True when everything is done.
	 */
	public static function run_new_reports( $max = 200 ) {
		global $wpdb;
		$job = get_option( 'ntaa_report_backfill', array() );
		if ( empty( $job['keys'] ) ) {
			return true;
		}
		$keys    = array_intersect( (array) $job['keys'], array_keys( self::reports() ) );
		$done    = isset( $job['done'] ) && is_array( $job['done'] ) ? $job['done'] : array();
		$ta      = NTAA_DB::t( 'archive' );
		$start   = microtime( true );
		$n       = 0;
		$pending = array();
		foreach ( (array) $wpdb->get_results( 'SELECT site_id, 0 AS seg, day FROM ' . NTAA_DB::t( 'archive_days' ) . " WHERE source = 'raw' UNION ALL SELECT site_id, seg_id AS seg, day FROM " . NTAA_DB::t( 'archive_seg' ) . ' ORDER BY day DESC', ARRAY_A ) as $row ) { // phpcs:ignore WordPress.DB
			$id = $row['site_id'] . '|' . $row['seg'] . '|' . $row['day'];
			if ( ! isset( $done[ $id ] ) ) {
				$pending[] = $row;
			}
		}
		foreach ( $pending as $row ) {
			if ( $n >= $max || ( microtime( true ) - $start ) > 40 ) {
				break;
			}
			$site    = (int) $row['site_id'];
			$seg     = (int) $row['seg'];
			$seg_sql = '';
			if ( $seg ) {
				$conds = NTAA_Segments::conds_of( $seg );
				$seg_sql = $conds ? NTAA_Segments::sql( $conds ) : '';
				if ( ! $seg_sql ) {
					$done[ $site . '|' . $seg . '|' . $row['day'] ] = 1;
					continue;
				}
			}
			$blocks = array( array( $row['day'] . ' 00:00:00', $row['day'] . ' 23:59:59' ) );
			$values = array();
			foreach ( $keys as $key ) {
				$reports = self::reports();
				$wpdb->query( $wpdb->prepare( "DELETE FROM {$ta} WHERE site_id = %d AND day = %s AND report = %s", $site, $row['day'], self::prefix( $seg ) . $key ) ); // phpcs:ignore WordPress.DB
				$rows = self::truncate( self::raw( $key, $site, $blocks, $seg_sql ), isset( $reports[ $key ]['top'] ) ? (int) $reports[ $key ]['top'] : 0 );
				foreach ( $rows as $r ) {
					$cols = array( $site, self::prefix( $seg ) . $key, $row['day'], md5( $r['label'] ), mb_substr( $r['label'], 0, 255 ), mb_substr( $r['extra'], 0, 255 ) );
					for ( $i = 1; $i <= 16; $i++ ) {
						$cols[] = (int) $r[ 'c' . $i ];
					}
					$values[] = $wpdb->prepare( '(%d,%s,%s,%s,%s,%s,%d,%d,%d,%d,%d,%d,%d,%d,%d,%d,%d,%d,%d,%d,%d,%d)', $cols ); // phpcs:ignore WordPress.DB
				}
			}
			foreach ( array_chunk( $values, 300 ) as $chunk ) {
				self::insert_values( $chunk );
			}
			$done[ $site . '|' . $seg . '|' . $row['day'] ] = 1;
			++$n;
		}
		if ( $n >= count( $pending ) ) {
			delete_option( 'ntaa_report_backfill' );
			return true;
		}
		$job['done'] = $done;
		update_option( 'ntaa_report_backfill', $job, false );
		return false;
	}

	/**
	 * Is a new report still being filled into older days?
	 *
	 * @return bool
	 */
	public static function new_reports_pending() {
		$job = get_option( 'ntaa_report_backfill', array() );
		return ! empty( $job['keys'] );
	}

	/**
	 * Cast rows to label/extra/c1…c16 integers.
	 *
	 * @param array $rows Rows.
	 * @return array
	 */
	public static function normalize( $rows ) {
		$out = array();
		foreach ( $rows as $r ) {
			$n = array(
				'label' => isset( $r['label'] ) ? (string) $r['label'] : '',
				'extra' => isset( $r['extra'] ) ? (string) $r['extra'] : '',
			);
			for ( $i = 1; $i <= 16; $i++ ) {
				$n[ 'c' . $i ] = isset( $r[ 'c' . $i ] ) ? (int) round( (float) $r[ 'c' . $i ] ) : 0;
			}
			$out[] = $n;
		}
		return $out;
	}

	/* ---------------------------------------------------------------
	 * Archiving
	 * ------------------------------------------------------------- */

	/**
	 * Site ids that collect data.
	 *
	 * @return array
	 */
	public static function site_ids() {
		$ids = apply_filters( 'ntaa_site_ids', array( 1 ) );
		return array_values( array_unique( array_map( 'intval', (array) $ids ) ) );
	}

	/**
	 * Sites whose raw data belongs to a site id (a roll-up of several websites returns its members).
	 *
	 * @param int $site Site.
	 * @return array
	 */
	public static function members( $site ) {
		$m = array_values( array_filter( array_map( 'intval', (array) apply_filters( 'ntaa_site_members', array( (int) $site ), (int) $site ) ) ) );
		return $m ? $m : array( (int) $site );
	}

	/**
	 * SQL "= 5" or "IN (2,3)" for the site_id column of a site (or roll-up).
	 *
	 * @param int $site Site.
	 * @return string
	 */
	public static function site_in( $site ) {
		$m = self::members( $site );
		return 1 === count( $m ) ? '= ' . (int) $m[0] : 'IN (' . implode( ',', $m ) . ')';
	}

	/**
	 * Today in the site's time zone.
	 *
	 * @return string
	 */
	public static function today() {
		return wp_date( 'Y-m-d' );
	}

	/**
	 * Compute and store all reports of one finished day.
	 *
	 * @param int    $site Site.
	 * @param string $day  Y-m-d.
	 * @return bool
	 */
	public static function archive_day( $site, $day, $seg = 0 ) {
		global $wpdb;
		if ( $day >= self::today() ) {
			return false;
		}
		$seg_sql = '';
		if ( $seg ) {
			$conds = NTAA_Segments::conds_of( $seg );
			if ( ! $conds ) {
				return false;
			}
			$seg_sql = NTAA_Segments::sql( $conds );
		}
		$prefix = self::prefix( $seg );
		if ( ! $seg && self::sum_members( $site, $day ) ) {
			return true;
		}
		$blocks = array( array( $day . ' 00:00:00', $day . ' 23:59:59' ) );
		$all    = array();
		foreach ( self::reports() as $key => $r ) {
			if ( ! empty( $r['raw_only'] ) ) {
				continue;
			}
			$rows = self::raw( $key, $site, $blocks, $seg_sql );
			if ( ! $rows ) {
				continue;
			}
			$all[ $key ] = self::truncate( $rows, isset( $r['top'] ) ? (int) $r['top'] : 0 );
		}
		$ta = NTAA_DB::t( 'archive' );
		if ( $seg ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$ta} WHERE site_id = %d AND day = %s AND report LIKE %s", $site, $day, $wpdb->esc_like( $prefix ) . '%' ) ); // phpcs:ignore WordPress.DB
		} else {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$ta} WHERE site_id = %d AND day = %s AND report NOT LIKE %s", $site, $day, '#%' ) ); // phpcs:ignore WordPress.DB
		}
		$values = array();
		foreach ( $all as $key => $rows ) {
			foreach ( $rows as $r ) {
				$cols = array( (int) $site, $prefix . $key, $day, md5( $r['label'] ), mb_substr( $r['label'], 0, 255 ), mb_substr( $r['extra'], 0, 255 ) );
				for ( $i = 1; $i <= 16; $i++ ) {
					$cols[] = (int) $r[ 'c' . $i ];
				}
				$values[] = $wpdb->prepare( '(%d,%s,%s,%s,%s,%s,%d,%d,%d,%d,%d,%d,%d,%d,%d,%d,%d,%d,%d,%d,%d,%d)', $cols ); // phpcs:ignore WordPress.DB
				if ( count( $values ) >= 300 ) {
					self::insert_values( $values );
					$values = array();
				}
			}
		}
		if ( $values ) {
			self::insert_values( $values );
		}
		if ( $seg ) {
			$wpdb->query( $wpdb->prepare( 'REPLACE INTO ' . NTAA_DB::t( 'archive_seg' ) . ' (site_id, seg_id, day, done_at) VALUES (%d, %d, %s, %s)', $site, $seg, $day, current_time( 'mysql' ) ) ); // phpcs:ignore WordPress.DB
		} else {
			$td = NTAA_DB::t( 'archive_days' );
			$wpdb->query( $wpdb->prepare( "REPLACE INTO {$td} (site_id, day, source, done_at) VALUES (%d, %s, 'raw', %s)", $site, $day, current_time( 'mysql' ) ) ); // phpcs:ignore WordPress.DB
		}
		return true;
	}

	/**
	 * Roll-up day: when every member website has the day archived, add up their archives
	 * (this also includes days imported from Google Analytics).
	 *
	 * @param int    $site Site.
	 * @param string $day  Y-m-d.
	 * @return bool True when done this way.
	 */
	private static function sum_members( $site, $day ) {
		global $wpdb;
		$members = self::members( $site );
		if ( count( $members ) < 2 || in_array( (int) $site, $members, true ) ) {
			return false;
		}
		$td  = NTAA_DB::t( 'archive_days' );
		$ta  = NTAA_DB::t( 'archive' );
		$in  = implode( ',', array_map( 'intval', $members ) );
		$got = $wpdb->get_results( $wpdb->prepare( "SELECT site_id, source FROM {$td} WHERE day = %s AND site_id IN ({$in})", $day ), OBJECT_K ); // phpcs:ignore WordPress.DB
		foreach ( $members as $m ) {
			if ( isset( $got[ $m ] ) ) {
				continue;
			}
			$first = self::first_raw_day( $m );
			if ( '' !== $first && $day >= $first ) {
				return false; // This website has raw data for the day that is not archived yet.
			}
		}
		$src = 'raw'; // Always rebuildable from its members.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$ta} WHERE site_id = %d AND day = %s AND report NOT LIKE %s", $site, $day, '#%' ) ); // phpcs:ignore WordPress.DB
		$sums = array();
		for ( $i = 1; $i <= 16; $i++ ) {
			$sums[] = "SUM(c{$i})";
		}
		$wpdb->query( $wpdb->prepare( "INSERT INTO {$ta} (site_id, report, day, lkey, label, extra, c1, c2, c3, c4, c5, c6, c7, c8, c9, c10, c11, c12, c13, c14, c15, c16) SELECT %d, report, day, lkey, MAX(label), MAX(extra), " . implode( ', ', $sums ) . " FROM {$ta} WHERE day = %s AND site_id IN ({$in}) AND report NOT LIKE %s GROUP BY report, day, lkey", $site, $day, '#%' ) ); // phpcs:ignore WordPress.DB
		$wpdb->query( $wpdb->prepare( "REPLACE INTO {$td} (site_id, day, source, done_at) VALUES (%d, %s, %s, %s)", $site, $day, $src, current_time( 'mysql' ) ) ); // phpcs:ignore WordPress.DB
		return true;
	}

	/**
	 * Report-key prefix of a pre-processed segment ('' = all visits).
	 *
	 * @param int $seg Segment id.
	 * @return string
	 */
	public static function prefix( $seg ) {
		return $seg ? '#' . (int) $seg . '.' : '';
	}

	/**
	 * Forget all archived data of a segment (edited or deleted).
	 *
	 * @param int $seg Segment id.
	 */
	public static function drop_segment( $seg ) {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . NTAA_DB::t( 'archive' ) . ' WHERE report LIKE %s', $wpdb->esc_like( self::prefix( $seg ) ) . '%' ) ); // phpcs:ignore WordPress.DB
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . NTAA_DB::t( 'archive_seg' ) . ' WHERE seg_id = %d', $seg ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Insert archive rows.
	 *
	 * @param array $values Prepared tuples.
	 */
	private static function insert_values( $values ) {
		global $wpdb;
		$ta = NTAA_DB::t( 'archive' );
		$wpdb->query( "INSERT INTO {$ta} (site_id, report, day, lkey, label, extra, c1, c2, c3, c4, c5, c6, c7, c8, c9, c10, c11, c12, c13, c14, c15, c16) VALUES " . implode( ',', $values ) . ' ON DUPLICATE KEY UPDATE c1 = c1 + VALUES(c1), c2 = c2 + VALUES(c2), c3 = c3 + VALUES(c3), c4 = c4 + VALUES(c4), c5 = c5 + VALUES(c5), c6 = c6 + VALUES(c6), c7 = c7 + VALUES(c7), c8 = c8 + VALUES(c8), c9 = c9 + VALUES(c9), c10 = c10 + VALUES(c10), c11 = c11 + VALUES(c11), c12 = c12 + VALUES(c12), c13 = c13 + VALUES(c13), c14 = c14 + VALUES(c14), c15 = c15 + VALUES(c15), c16 = c16 + VALUES(c16)' ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Keep the top N rows and sum the rest into "__other__".
	 *
	 * @param array $rows Rows.
	 * @param int   $top  N (0 = keep all).
	 * @return array
	 */
	public static function truncate( $rows, $top ) {
		if ( $top <= 0 || count( $rows ) <= $top ) {
			return $rows;
		}
		usort(
			$rows,
			function ( $a, $b ) {
				return $b['c1'] <=> $a['c1'];
			}
		);
		$keep  = array_slice( $rows, 0, $top );
		$other = array( 'label' => '__other__', 'extra' => '' );
		for ( $i = 1; $i <= 16; $i++ ) {
			$other[ 'c' . $i ] = 0;
		}
		foreach ( array_slice( $rows, $top ) as $r ) {
			for ( $i = 1; $i <= 16; $i++ ) {
				$other[ 'c' . $i ] += $r[ 'c' . $i ];
			}
		}
		$keep[] = $other;
		return $keep;
	}

	/**
	 * Mark a day as needing a new archive (late data, refunds, goal recalculation).
	 *
	 * @param int    $site Site (0 = all sites).
	 * @param string $day  Y-m-d.
	 */
	public static function invalidate( $site, $day ) {
		global $wpdb;
		$td = NTAA_DB::t( 'archive_days' );
		$ta = NTAA_DB::t( 'archive' );
		if ( $site ) {
			$src = $wpdb->get_var( $wpdb->prepare( "SELECT source FROM {$td} WHERE site_id = %d AND day = %s", $site, $day ) ); // phpcs:ignore WordPress.DB
			if ( null === $src || 'raw' !== $src ) {
				return; // Not archived yet, or imported from Google Analytics.
			}
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$td} WHERE site_id = %d AND day = %s", $site, $day ) ); // phpcs:ignore WordPress.DB
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$ta} WHERE site_id = %d AND day = %s", $site, $day ) ); // phpcs:ignore WordPress.DB
			$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . NTAA_DB::t( 'archive_seg' ) . ' WHERE site_id = %d AND day = %s', $site, $day ) ); // phpcs:ignore WordPress.DB
			foreach ( (array) apply_filters( 'ntaa_site_rollups', array(), (int) $site ) as $roll ) {
				if ( (int) $roll !== (int) $site ) {
					self::invalidate( (int) $roll, $day );
				}
			}
		} else {
			foreach ( self::site_ids() as $s ) {
				self::invalidate( $s, $day );
			}
		}
	}

	/**
	 * Invalidate a range of days.
	 *
	 * @param int    $site Site (0 = all).
	 * @param string $from Y-m-d.
	 * @param string $to   Y-m-d.
	 * @return int Days invalidated.
	 */
	public static function invalidate_range( $site, $from, $to ) {
		global $wpdb;
		$td    = NTAA_DB::t( 'archive_days' );
		$ta    = NTAA_DB::t( 'archive' );
		$sites = $site ? array( (int) $site ) : self::site_ids();
		$n     = 0;
		foreach ( $sites as $s ) {
			$n += (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$td} WHERE site_id = %d AND day BETWEEN %s AND %s AND source = 'raw'", $s, $from, $to ) ); // phpcs:ignore WordPress.DB
			$wpdb->query( $wpdb->prepare( "DELETE a FROM {$ta} a LEFT JOIN {$td} d ON d.site_id = a.site_id AND d.day = a.day WHERE a.site_id = %d AND a.day BETWEEN %s AND %s AND d.day IS NULL", $s, $from, $to ) ); // phpcs:ignore WordPress.DB
			// Pre-processed segments of these days are rebuilt too.
			$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . NTAA_DB::t( 'archive_seg' ) . ' WHERE site_id = %d AND day BETWEEN %s AND %s', $s, $from, $to ) ); // phpcs:ignore WordPress.DB
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$ta} WHERE site_id = %d AND day BETWEEN %s AND %s AND report LIKE %s", $s, $from, $to, '#%' ) ); // phpcs:ignore WordPress.DB
		}
		return $n;
	}

	/**
	 * First day with raw data for a site (cached).
	 *
	 * @param int $site Site.
	 * @return string Y-m-d or ''.
	 */
	public static function first_raw_day( $site ) {
		global $wpdb;
		$cache = get_option( 'ntaa_first_day', array() );
		$cache = is_array( $cache ) ? $cache : array();
		if ( isset( $cache[ $site ] ) && $cache[ $site ] ) {
			return $cache[ $site ];
		}
		$tv  = NTAA_DB::t( 'visits' );
		$min = $wpdb->get_var( "SELECT MIN(started_at) FROM {$tv} WHERE site_id " . self::site_in( $site ) ); // phpcs:ignore WordPress.DB
		$day = $min ? substr( $min, 0, 10 ) : '';
		$mem = self::members( $site );
		if ( count( $mem ) > 1 ) {
			// Roll-up: also cover days its websites imported from Google Analytics.
			$imp = $wpdb->get_var( 'SELECT MIN(day) FROM ' . NTAA_DB::t( 'archive_days' ) . ' WHERE site_id IN (' . implode( ',', array_map( 'intval', $mem ) ) . ')' ); // phpcs:ignore WordPress.DB
			if ( $imp && ( '' === $day || $imp < $day ) ) {
				$day = $imp;
			}
		}
		if ( $day ) {
			$cache[ $site ] = $day;
			update_option( 'ntaa_first_day', $cache, false );
		}
		return $day;
	}

	/**
	 * Days of a range that are archived (any source).
	 *
	 * @param int    $site Site.
	 * @param string $from Y-m-d.
	 * @param string $to   Y-m-d.
	 * @return array day => source
	 */
	public static function archived_days( $site, $from, $to, $seg = 0 ) {
		global $wpdb;
		if ( $seg ) {
			$out = array();
			foreach ( (array) $wpdb->get_col( $wpdb->prepare( 'SELECT day FROM ' . NTAA_DB::t( 'archive_seg' ) . ' WHERE site_id = %d AND seg_id = %d AND day BETWEEN %s AND %s', $site, $seg, $from, $to ) ) as $d ) { // phpcs:ignore WordPress.DB
				$out[ $d ] = 'raw';
			}
			return $out;
		}
		$td   = NTAA_DB::t( 'archive_days' );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT day, source FROM {$td} WHERE site_id = %d AND day BETWEEN %s AND %s", $site, $from, $to ) ); // phpcs:ignore WordPress.DB
		$out  = array();
		foreach ( $rows as $r ) {
			$out[ $r->day ] = $r->source;
		}
		return $out;
	}

	/**
	 * Make sure finished days of a range are archived (at most $max per call), and return
	 * the days that still have to be read from the raw log.
	 *
	 * @param int    $site Site.
	 * @param string $from Y-m-d.
	 * @param string $to   Y-m-d.
	 * @param int    $max  Days to archive now.
	 * @return array List of raw day strings.
	 */
	public static function prepare( $site, $from, $to, $max = 31, $seg = 0 ) {
		$archived = self::archived_days( $site, $from, $to, $seg );
		$today    = self::today();
		$first    = self::first_raw_day( $site );
		$raw      = array();
		$done     = 0;
		$start    = microtime( true );
		for ( $d = $from; $d <= $to; $d = gmdate( 'Y-m-d', strtotime( $d . ' +1 day' ) ) ) {
			if ( isset( $archived[ $d ] ) ) {
				continue;
			}
			if ( $d < $today && ( '' === $first || $d < $first ) ) {
				continue; // No raw data can exist for this day.
			}
			if ( $d < $today && $done < $max && ( microtime( true ) - $start ) < ( $seg ? 8 : 20 ) ) {
				self::archive_day( $site, $d, $seg );
				++$done;
				continue;
			}
			$raw[] = $d;
		}
		return $raw;
	}

	/**
	 * Turn a list of days into contiguous datetime blocks.
	 *
	 * @param array $days Y-m-d list (sorted).
	 * @return array
	 */
	public static function blocks( $days ) {
		$blocks = array();
		$start  = null;
		$prev   = null;
		foreach ( $days as $d ) {
			if ( null !== $prev && gmdate( 'Y-m-d', strtotime( $prev . ' +1 day' ) ) === $d ) {
				$prev = $d;
				continue;
			}
			if ( null !== $start ) {
				$blocks[] = array( $start . ' 00:00:00', $prev . ' 23:59:59' );
			}
			$start = $d;
			$prev  = $d;
		}
		if ( null !== $start ) {
			$blocks[] = array( $start . ' 00:00:00', $prev . ' 23:59:59' );
		}
		return $blocks;
	}

	/**
	 * Archive missing days in the background (cron).
	 *
	 * @param int $max Days per run.
	 * @return int Days archived.
	 */
	public static function backfill( $max = 60 ) {
		$done  = 0;
		$start = microtime( true );
		$yday  = gmdate( 'Y-m-d', strtotime( self::today() . ' -1 day' ) );
		$jobs  = array();
		foreach ( self::site_ids() as $site ) {
			$jobs[] = array( $site, 0 );
		}
		foreach ( NTAA_Segments::preprocessed() as $seg ) {
			foreach ( self::site_ids() as $site ) {
				$jobs[] = array( $site, $seg );
			}
		}
		foreach ( $jobs as $job ) {
			list( $site, $seg ) = $job;
			$first = self::first_raw_day( $site );
			if ( '' === $first || $first > $yday ) {
				continue;
			}
			$archived = self::archived_days( $site, $first, $yday, $seg );
			for ( $d = $yday; $d >= $first; $d = gmdate( 'Y-m-d', strtotime( $d . ' -1 day' ) ) ) {
				if ( isset( $archived[ $d ] ) ) {
					continue;
				}
				self::archive_day( $site, $d, $seg );
				++$done;
				if ( $done >= $max || ( microtime( true ) - $start ) > 40 ) {
					return $done;
				}
			}
		}
		return $done;
	}

	/**
	 * Read summed archive rows for a report.
	 *
	 * @param string $key  Report.
	 * @param int    $site Site.
	 * @param string $from Y-m-d.
	 * @param string $to   Y-m-d.
	 * @return array
	 */
	public static function read( $key, $site, $from, $to ) {
		global $wpdb;
		$ta   = NTAA_DB::t( 'archive' );
		$sums = array();
		for ( $i = 1; $i <= 16; $i++ ) {
			$sums[] = "SUM(c{$i}) AS c{$i}";
		}
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT MAX(label) AS label, MAX(extra) AS extra, ' . implode( ', ', $sums ) . " FROM {$ta} WHERE site_id = %d AND report = %s AND day BETWEEN %s AND %s GROUP BY lkey", $site, $key, $from, $to ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return self::normalize( (array) $rows );
	}

	/**
	 * Daily rows of a single-row report (summary, perf, ecom, steps).
	 *
	 * @param string $key  Report.
	 * @param int    $site Site.
	 * @param string $from Y-m-d.
	 * @param string $to   Y-m-d.
	 * @return array day => row
	 */
	public static function read_daily( $key, $site, $from, $to ) {
		global $wpdb;
		$ta   = NTAA_DB::t( 'archive' );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$ta} WHERE site_id = %d AND report = %s AND day BETWEEN %s AND %s", $site, $key, $from, $to ), ARRAY_A ); // phpcs:ignore WordPress.DB
		$out  = array();
		foreach ( self::normalize( (array) $rows ) as $i => $r ) {
			$out[ $rows[ $i ]['day'] ] = $r;
		}
		return $out;
	}
}
