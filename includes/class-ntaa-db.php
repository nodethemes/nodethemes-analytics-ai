<?php
/**
 * Database tables, install / upgrade and data retention.
 *
 * Raw tables (visits, pageviews, events, conversions, orders, order_items) hold the visit log.
 * The archive table holds one pre-computed row per day, report and label, so reports for long
 * periods stay fast on busy sites and keep working after the raw log has been purged.
 *
 * @package NodeThemes_Analytics_AI
 */

defined( 'ABSPATH' ) || exit;

/**
 * DB.
 */
class NTAA_DB {

	/**
	 * Table names.
	 *
	 * @return array
	 */
	public static function tables() {
		global $wpdb;
		$p = $wpdb->prefix . 'ntaa_';
		return array(
			'visits'       => $p . 'visits',
			'pageviews'    => $p . 'pageviews',
			'events'       => $p . 'events',
			'conversions'  => $p . 'conversions',
			'orders'       => $p . 'orders',
			'order_items'  => $p . 'order_items',
			'archive'      => $p . 'archive',
			'archive_days' => $p . 'archive_days',
			'archive_seg'  => $p . 'archive_seg',
			'queue'        => $p . 'queue',
			'bots'         => $p . 'bots',
			'annotations'  => $p . 'annotations',
			'reports'      => $p . 'ai_reports',
		);
	}

	/**
	 * One table name.
	 *
	 * @param string $name Short name.
	 * @return string
	 */
	public static function t( $name ) {
		$t = self::tables();
		return $t[ $name ];
	}

	/**
	 * Activation.
	 */
	public static function activate() {
		self::install();
		NTAA_Settings::sync_caps();
		NTAA_Settings::write_runtime();
		NTAA_Cron::schedule();
	}

	/**
	 * Deactivation.
	 */
	public static function deactivate() {
		NTAA_Cron::unschedule();
	}

	/**
	 * Upgrade on version change.
	 */
	public static function maybe_upgrade() {
		$old = get_option( 'ntaa_db_version' );
		if ( $old !== NTAA_DB_VERSION ) {
			self::install();
			if ( $old && version_compare( $old, '2.2', '<' ) ) {
				NTAA_Archive::queue_new_reports( array( 'ptitle' ) ); // Page titles report (2.1.0).
			}
			NTAA_Settings::sync_caps();
			NTAA_Settings::write_runtime();
		}
	}

	/**
	 * Create / update all tables.
	 */
	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$c = $wpdb->get_charset_collate();
		$t = self::tables();

		$sql   = array();
		$sql[] = "CREATE TABLE {$t['visits']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  site_id smallint(5) unsigned NOT NULL DEFAULT 1,
  visitor_hash char(32) NOT NULL,
  visitor_id char(16) NOT NULL DEFAULT '',
  started_at datetime NOT NULL,
  last_activity datetime NOT NULL,
  entry_page varchar(255) NOT NULL DEFAULT '',
  exit_page varchar(255) NOT NULL DEFAULT '',
  referrer varchar(255) NOT NULL DEFAULT '',
  referrer_host varchar(191) NOT NULL DEFAULT '',
  referrer_type varchar(20) NOT NULL DEFAULT 'direct',
  utm_source varchar(100) NOT NULL DEFAULT '',
  utm_medium varchar(100) NOT NULL DEFAULT '',
  utm_campaign varchar(150) NOT NULL DEFAULT '',
  utm_term varchar(100) NOT NULL DEFAULT '',
  utm_content varchar(100) NOT NULL DEFAULT '',
  device varchar(20) NOT NULL DEFAULT '',
  browser varchar(40) NOT NULL DEFAULT '',
  os varchar(40) NOT NULL DEFAULT '',
  screen varchar(20) NOT NULL DEFAULT '',
  language varchar(10) NOT NULL DEFAULT '',
  country char(2) NOT NULL DEFAULT '',
  region varchar(80) NOT NULL DEFAULT '',
  city varchar(80) NOT NULL DEFAULT '',
  local_hour tinyint(3) unsigned NOT NULL DEFAULT 255,
  pageviews int(10) unsigned NOT NULL DEFAULT 0,
  events int(10) unsigned NOT NULL DEFAULT 0,
  duration int(10) unsigned NOT NULL DEFAULT 0,
  is_new tinyint(1) NOT NULL DEFAULT 1,
  visit_number int(10) unsigned NOT NULL DEFAULT 1,
  days_since_last int(10) unsigned NOT NULL DEFAULT 0,
  goal_conversions smallint(5) unsigned NOT NULL DEFAULT 0,
  goal_value decimal(14,2) NOT NULL DEFAULT 0,
  orders smallint(5) unsigned NOT NULL DEFAULT 0,
  revenue decimal(14,2) NOT NULL DEFAULT 0,
  dim1 varchar(191) NOT NULL DEFAULT '',
  dim2 varchar(191) NOT NULL DEFAULT '',
  dim3 varchar(191) NOT NULL DEFAULT '',
  dim4 varchar(191) NOT NULL DEFAULT '',
  dim5 varchar(191) NOT NULL DEFAULT '',
  PRIMARY KEY  (id),
  KEY started_at (started_at),
  KEY site_started (site_id,started_at),
  KEY last_activity (last_activity),
  KEY visitor_hash (visitor_hash,last_activity),
  KEY visitor_id (visitor_id,started_at)
) $c;";

		$sql[] = "CREATE TABLE {$t['pageviews']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  site_id smallint(5) unsigned NOT NULL DEFAULT 1,
  visit_id bigint(20) unsigned NOT NULL,
  pv_uid char(32) DEFAULT NULL,
  page varchar(255) NOT NULL DEFAULT '',
  title varchar(255) NOT NULL DEFAULT '',
  post_id bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  time_on_page int(10) unsigned NOT NULL DEFAULT 0,
  scroll_depth tinyint(3) unsigned NOT NULL DEFAULT 0,
  load_time int(10) unsigned NOT NULL DEFAULT 0,
  lcp int(10) unsigned NOT NULL DEFAULT 0,
  cls smallint(5) unsigned NOT NULL DEFAULT 0,
  inp int(10) unsigned NOT NULL DEFAULT 0,
  fcp int(10) unsigned NOT NULL DEFAULT 0,
  ttfb int(10) unsigned NOT NULL DEFAULT 0,
  vitals tinyint(1) NOT NULL DEFAULT 0,
  search_term varchar(191) NOT NULL DEFAULT '',
  search_count int(11) NOT NULL DEFAULT -1,
  search_cat varchar(40) NOT NULL DEFAULT '',
  PRIMARY KEY  (id),
  UNIQUE KEY pv_uid (pv_uid),
  KEY visit_id (visit_id),
  KEY created_at (created_at),
  KEY site_created (site_id,created_at),
  KEY page (page(191))
) $c;";

		$sql[] = "CREATE TABLE {$t['events']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  site_id smallint(5) unsigned NOT NULL DEFAULT 1,
  visit_id bigint(20) unsigned NOT NULL,
  category varchar(100) NOT NULL DEFAULT '',
  action varchar(150) NOT NULL DEFAULT '',
  label varchar(255) NOT NULL DEFAULT '',
  value double DEFAULT NULL,
  ref_id bigint(20) unsigned NOT NULL DEFAULT 0,
  page varchar(255) NOT NULL DEFAULT '',
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY visit_id (visit_id),
  KEY created_at (created_at),
  KEY category (category,action)
) $c;";

		$sql[] = "CREATE TABLE {$t['conversions']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  site_id smallint(5) unsigned NOT NULL DEFAULT 1,
  visit_id bigint(20) unsigned NOT NULL,
  goal_id int(10) unsigned NOT NULL,
  created_at datetime NOT NULL,
  value decimal(14,2) NOT NULL DEFAULT 0,
  page varchar(255) NOT NULL DEFAULT '',
  ref varchar(64) NOT NULL DEFAULT '',
  PRIMARY KEY  (id),
  KEY visit_goal (visit_id,goal_id),
  KEY goal_created (goal_id,created_at),
  KEY created_at (created_at)
) $c;";

		$sql[] = "CREATE TABLE {$t['orders']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  site_id smallint(5) unsigned NOT NULL DEFAULT 1,
  visit_id bigint(20) unsigned NOT NULL DEFAULT 0,
  source varchar(20) NOT NULL DEFAULT 'woocommerce',
  order_id varchar(64) NOT NULL,
  created_at datetime NOT NULL,
  status varchar(20) NOT NULL DEFAULT 'paid',
  revenue decimal(14,2) NOT NULL DEFAULT 0,
  subtotal decimal(14,2) NOT NULL DEFAULT 0,
  tax decimal(14,2) NOT NULL DEFAULT 0,
  shipping decimal(14,2) NOT NULL DEFAULT 0,
  discount decimal(14,2) NOT NULL DEFAULT 0,
  items int(10) unsigned NOT NULL DEFAULT 0,
  currency char(3) NOT NULL DEFAULT '',
  new_customer tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  UNIQUE KEY src_order (site_id,source,order_id),
  KEY created_at (created_at),
  KEY visit_id (visit_id)
) $c;";

		$sql[] = "CREATE TABLE {$t['order_items']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  site_id smallint(5) unsigned NOT NULL DEFAULT 1,
  order_ref bigint(20) unsigned NOT NULL,
  product_id bigint(20) unsigned NOT NULL DEFAULT 0,
  sku varchar(100) NOT NULL DEFAULT '',
  name varchar(255) NOT NULL DEFAULT '',
  category varchar(191) NOT NULL DEFAULT '',
  price decimal(14,2) NOT NULL DEFAULT 0,
  qty int(10) unsigned NOT NULL DEFAULT 1,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY order_ref (order_ref),
  KEY created_at (created_at),
  KEY product_id (product_id)
) $c;";

		$sql[] = "CREATE TABLE {$t['archive']} (
  site_id smallint(5) unsigned NOT NULL DEFAULT 1,
  report varchar(24) NOT NULL,
  day date NOT NULL,
  lkey char(32) NOT NULL,
  label varchar(255) NOT NULL DEFAULT '',
  extra varchar(255) NOT NULL DEFAULT '',
  c1 bigint(20) NOT NULL DEFAULT 0,
  c2 bigint(20) NOT NULL DEFAULT 0,
  c3 bigint(20) NOT NULL DEFAULT 0,
  c4 bigint(20) NOT NULL DEFAULT 0,
  c5 bigint(20) NOT NULL DEFAULT 0,
  c6 bigint(20) NOT NULL DEFAULT 0,
  c7 bigint(20) NOT NULL DEFAULT 0,
  c8 bigint(20) NOT NULL DEFAULT 0,
  c9 bigint(20) NOT NULL DEFAULT 0,
  c10 bigint(20) NOT NULL DEFAULT 0,
  c11 bigint(20) NOT NULL DEFAULT 0,
  c12 bigint(20) NOT NULL DEFAULT 0,
  c13 bigint(20) NOT NULL DEFAULT 0,
  c14 bigint(20) NOT NULL DEFAULT 0,
  c15 bigint(20) NOT NULL DEFAULT 0,
  c16 bigint(20) NOT NULL DEFAULT 0,
  PRIMARY KEY  (site_id,report,day,lkey),
  KEY day (day)
) $c;";

		$sql[] = "CREATE TABLE {$t['archive_days']} (
  site_id smallint(5) unsigned NOT NULL DEFAULT 1,
  day date NOT NULL,
  source varchar(10) NOT NULL DEFAULT 'raw',
  done_at datetime NOT NULL,
  PRIMARY KEY  (site_id,day)
) $c;";

		$sql[] = "CREATE TABLE {$t['archive_seg']} (
  site_id smallint(5) unsigned NOT NULL DEFAULT 1,
  seg_id int(10) unsigned NOT NULL,
  day date NOT NULL,
  done_at datetime NOT NULL,
  PRIMARY KEY  (site_id,seg_id,day)
) $c;";

		$sql[] = "CREATE TABLE {$t['queue']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  received_at datetime NOT NULL,
  payload mediumtext NOT NULL,
  PRIMARY KEY  (id)
) $c;";

		$sql[] = "CREATE TABLE {$t['bots']} (
  day date NOT NULL,
  bot varchar(60) NOT NULL,
  kind varchar(20) NOT NULL DEFAULT '',
  hits int(10) unsigned NOT NULL DEFAULT 0,
  last_url varchar(255) NOT NULL DEFAULT '',
  last_seen datetime NOT NULL,
  PRIMARY KEY  (day,bot)
) $c;";

		$sql[] = "CREATE TABLE {$t['annotations']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  site_id smallint(5) unsigned NOT NULL DEFAULT 1,
  day date NOT NULL,
  note varchar(255) NOT NULL DEFAULT '',
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY site_day (site_id,day)
) $c;";

		$sql[] = "CREATE TABLE {$t['reports']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  created_at datetime NOT NULL,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  provider varchar(30) NOT NULL DEFAULT '',
  model varchar(100) NOT NULL DEFAULT '',
  date_from date NOT NULL,
  date_to date NOT NULL,
  type varchar(30) NOT NULL DEFAULT '',
  prompt text NOT NULL,
  response longtext NOT NULL,
  PRIMARY KEY  (id),
  KEY created_at (created_at)
) $c;";

		foreach ( $sql as $q ) {
			dbDelta( $q );
		}
		do_action( 'ntaa_install_tables' );
		update_option( 'ntaa_db_version', NTAA_DB_VERSION );
	}

	/**
	 * Delete raw data older than the retention window (archives are kept).
	 */
	public static function purge_old() {
		global $wpdb;
		$days = (int) NTAA_Settings::get( 'retention_days', 395 );
		if ( $days <= 0 ) {
			return;
		}
		$limit = wp_date( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		// Make sure every day about to be deleted is archived first.
		NTAA_Archive::backfill( 400 );
		$t = self::tables();
		foreach ( array( 'pageviews', 'events', 'conversions', 'order_items' ) as $n ) {
			do {
				$n_del = $wpdb->query( $wpdb->prepare( "DELETE FROM {$t[ $n ]} WHERE created_at < %s LIMIT 5000", $limit ) ); // phpcs:ignore WordPress.DB
			} while ( $n_del >= 5000 );
		}
		do {
			$n_del = $wpdb->query( $wpdb->prepare( "DELETE FROM {$t['visits']} WHERE last_activity < %s LIMIT 5000", $limit ) ); // phpcs:ignore WordPress.DB
		} while ( $n_del >= 5000 );
		// Orders stay (they are small and needed for revenue reports).
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$t['bots']} WHERE day < %s", substr( $limit, 0, 10 ) ) ); // phpcs:ignore WordPress.DB
		do_action( 'ntaa_purge_old', $limit );
	}

	/**
	 * Delete every visit, pageview, event, conversion, order and archive.
	 */
	public static function truncate_all() {
		global $wpdb;
		foreach ( array( 'visits', 'pageviews', 'events', 'conversions', 'orders', 'order_items', 'archive', 'archive_days', 'archive_seg', 'queue', 'bots' ) as $n ) {
			$wpdb->query( 'TRUNCATE TABLE ' . self::t( $n ) ); // phpcs:ignore WordPress.DB
		}
		do_action( 'ntaa_truncate_all' );
	}
}
