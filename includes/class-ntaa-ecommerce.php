<?php
/**
 * Ecommerce: WooCommerce integration (product views, add to cart, checkout, orders, refunds)
 * and a JavaScript / PHP API for other shops.
 *
 * Orders are attributed to the visit of the buyer at checkout time and recorded once they are paid,
 * so payment-gateway callbacks (which come from another server) are handled correctly.
 *
 * @package NodeThemes_Analytics_AI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Ecommerce.
 */
class NTAA_Ecommerce {

	/**
	 * Boot.
	 */
	public static function init() {
		if ( ! class_exists( 'WooCommerce' ) || ! NTAA_Settings::get( 'track_ecommerce' ) ) {
			return;
		}
		add_filter( 'ntaa_tracker_config', array( __CLASS__, 'tracker_config' ) );
		add_action( 'woocommerce_add_to_cart', array( __CLASS__, 'add_to_cart' ), 20, 6 );
		add_action( 'woocommerce_remove_cart_item', array( __CLASS__, 'remove_from_cart' ), 20, 2 );
		add_action( 'woocommerce_checkout_order_processed', array( __CLASS__, 'attach_visit' ), 20, 1 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'attach_visit' ), 20, 1 );
		add_action( 'woocommerce_new_order', array( __CLASS__, 'attach_visit' ), 20, 1 );
		add_action( 'woocommerce_payment_complete', array( __CLASS__, 'order_paid' ), 20, 1 );
		add_action( 'woocommerce_order_status_processing', array( __CLASS__, 'order_paid' ), 20, 1 );
		add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'order_paid' ), 20, 1 );
		add_action( 'woocommerce_order_status_refunded', array( __CLASS__, 'order_cancelled' ), 20, 1 );
		add_action( 'woocommerce_order_status_cancelled', array( __CLASS__, 'order_cancelled' ), 20, 1 );
		add_action( 'woocommerce_order_status_failed', array( __CLASS__, 'order_cancelled' ), 20, 1 );
	}

	/**
	 * Is WooCommerce tracking active?
	 *
	 * @return bool
	 */
	public static function active() {
		return class_exists( 'WooCommerce' ) && NTAA_Settings::get( 'track_ecommerce' );
	}

	/**
	 * First product category name.
	 *
	 * @param WC_Product $p Product.
	 * @return string
	 */
	private static function category( $p ) {
		$id    = $p->get_parent_id() ? $p->get_parent_id() : $p->get_id();
		$terms = get_the_terms( $id, 'product_cat' );
		return ( is_array( $terms ) && $terms ) ? $terms[0]->name : '';
	}

	/**
	 * Add shop context to the tracker on product, cart, checkout and thank-you pages.
	 *
	 * @param array $c Config.
	 * @return array
	 */
	public static function tracker_config( $c ) {
		if ( function_exists( 'is_product' ) && is_product() ) {
			$p = wc_get_product( get_queried_object_id() );
			if ( $p ) {
				$c['wc'] = array(
					'page' => 'product',
					'p'    => array(
						'id'  => $p->get_id(),
						'n'   => mb_substr( wp_strip_all_tags( $p->get_name() ), 0, 200 ),
						'sku' => (string) $p->get_sku(),
						'cat' => self::category( $p ),
						'pr'  => (float) wc_get_price_to_display( $p ),
					),
				);
			}
		} elseif ( function_exists( 'is_order_received_page' ) && is_order_received_page() ) {
			$c['wc'] = array( 'page' => 'thankyou' );
		} elseif ( function_exists( 'is_checkout' ) && is_checkout() ) {
			$c['wc'] = array( 'page' => 'checkout' );
		} elseif ( function_exists( 'is_cart' ) && is_cart() ) {
			$c['wc'] = array( 'page' => 'cart' );
		}
		return $c;
	}

	/**
	 * Add to cart (classic, AJAX and Store API all fire this action).
	 *
	 * @param string $key          Cart item key.
	 * @param int    $product_id   Product id.
	 * @param int    $quantity     Quantity.
	 * @param int    $variation_id Variation id.
	 */
	public static function add_to_cart( $key, $product_id, $quantity, $variation_id = 0 ) {
		$p = wc_get_product( $variation_id ? $variation_id : $product_id );
		if ( ! $p ) {
			return;
		}
		$qty = max( 1, (int) $quantity );
		NTAA_Tracker::server_event( 'ecommerce', 'add_to_cart', mb_substr( wp_strip_all_tags( $p->get_name() ), 0, 200 ), round( (float) wc_get_price_to_display( $p ) * $qty, 2 ), (int) $product_id );
	}

	/**
	 * Remove from cart.
	 *
	 * @param string  $key  Cart item key.
	 * @param WC_Cart $cart Cart.
	 */
	public static function remove_from_cart( $key, $cart ) {
		$item = isset( $cart->cart_contents[ $key ] ) ? $cart->cart_contents[ $key ] : null;
		if ( ! $item || empty( $item['product_id'] ) ) {
			return;
		}
		$p = wc_get_product( ! empty( $item['variation_id'] ) ? $item['variation_id'] : $item['product_id'] );
		NTAA_Tracker::server_event( 'ecommerce', 'remove_from_cart', $p ? mb_substr( wp_strip_all_tags( $p->get_name() ), 0, 200 ) : '', null, (int) $item['product_id'] );
	}

	/**
	 * Remember the buyer's visit on the order (runs in the buyer's own request).
	 *
	 * @param int|WC_Order $order_id Order.
	 */
	public static function attach_visit( $order_id ) {
		static $seen = array();
		$order = $order_id instanceof WC_Order ? $order_id : wc_get_order( $order_id );
		if ( ! $order || isset( $seen[ $order->get_id() ] ) || $order->get_meta( '_ntaa_visit_id' ) || ( is_admin() && ! wp_doing_ajax() ) ) {
			return;
		}
		$seen[ $order->get_id() ] = true;
		$visit = NTAA_Tracker::current_visit( 1, DAY_IN_SECONDS );
		if ( $visit ) {
			$order->update_meta_data( '_ntaa_visit_id', (int) $visit->id );
			$order->save_meta_data();
			NTAA_Tracker::store_event( $visit, 'ecommerce', 'checkout', (string) $order->get_order_number(), (float) $order->get_total(), 0, '', time() );
		}
	}

	/**
	 * Order paid: record it once.
	 *
	 * @param int $order_id Order id.
	 */
	public static function order_paid( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || $order->get_meta( '_ntaa_recorded' ) ) {
			return;
		}
		global $wpdb;
		$tv       = NTAA_DB::t( 'visits' );
		$visit_id = (int) $order->get_meta( '_ntaa_visit_id' );
		$visit    = $visit_id ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tv} WHERE id = %d", $visit_id ) ) : null; // phpcs:ignore WordPress.DB

		$items = array();
		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}
			$p       = $item->get_product();
			$qty     = max( 1, (int) $item->get_quantity() );
			$items[] = array(
				'id'  => (int) $item->get_product_id(),
				'sku' => $p ? (string) $p->get_sku() : '',
				'n'   => mb_substr( wp_strip_all_tags( $item->get_name() ), 0, 200 ),
				'cat' => $p ? self::category( $p ) : '',
				'p'   => round( (float) $order->get_item_total( $item, true, false ), 2 ),
				'q'   => $qty,
			);
		}
		$email     = (string) $order->get_billing_email();
		$new_buyer = 1;
		if ( '' !== $email && function_exists( 'wc_get_orders' ) ) {
			$prev      = wc_get_orders(
				array(
					'billing_email' => $email,
					'status'        => array( 'wc-processing', 'wc-completed' ),
					'exclude'       => array( $order->get_id() ),
					'limit'         => 1,
					'return'        => 'ids',
				)
			);
			$new_buyer = $prev ? 0 : 1;
		}
		$created = $order->get_date_created();
		$ts      = $created ? $created->getTimestamp() : time();
		$ok      = self::record_order(
			$visit,
			1,
			'woocommerce',
			array(
				'id'    => (string) $order->get_id(),
				'rev'   => (float) $order->get_total() - (float) $order->get_total_refunded(),
				'sub'   => (float) $order->get_subtotal(),
				'tax'   => (float) $order->get_total_tax(),
				'ship'  => (float) $order->get_shipping_total(),
				'disc'  => (float) $order->get_discount_total(),
				'cur'   => (string) $order->get_currency(),
				'new'   => $new_buyer,
				'items' => $items,
			),
			$ts
		);
		if ( $ok ) {
			$order->update_meta_data( '_ntaa_recorded', 1 );
			$order->save_meta_data();
		}
	}

	/**
	 * Refunded / cancelled / failed: take the order out of the revenue.
	 *
	 * @param int $order_id Order id.
	 */
	public static function order_cancelled( $order_id ) {
		global $wpdb;
		$to  = NTAA_DB::t( 'orders' );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$to} WHERE source = 'woocommerce' AND order_id = %s AND site_id = 1", (string) $order_id ) ); // phpcs:ignore WordPress.DB
		if ( ! $row || 'paid' !== $row->status ) {
			return;
		}
		$wpdb->update( $to, array( 'status' => 'refunded' ), array( 'id' => (int) $row->id ) ); // phpcs:ignore WordPress.DB
		if ( $row->visit_id ) {
			$tv = NTAA_DB::t( 'visits' );
			$wpdb->query( $wpdb->prepare( "UPDATE {$tv} SET orders = GREATEST(0, CAST(orders AS SIGNED) - 1), revenue = GREATEST(0, revenue - %f) WHERE id = %d", (float) $row->revenue, (int) $row->visit_id ) ); // phpcs:ignore WordPress.DB
		}
		$order = wc_get_order( $order_id );
		if ( $order ) {
			$order->delete_meta_data( '_ntaa_recorded' );
			$order->save_meta_data();
		}
		NTAA_Archive::invalidate( 1, substr( (string) $row->created_at, 0, 10 ) );
	}

	/**
	 * Order reported by JavaScript (ntaa.trackOrder) for shops that are not WooCommerce.
	 *
	 * @param object|null $visit Visit.
	 * @param int         $site  Site.
	 * @param array       $d     Payload.
	 * @param int         $ts    Time.
	 */
	public static function record_js_order( $visit, $site, $d, $ts ) {
		if ( ! $visit ) {
			return;
		}
		// When WooCommerce runs on this site its own (trusted) orders are used instead.
		if ( 1 === (int) $site && self::active() ) {
			return;
		}
		$items = array();
		if ( isset( $d['items'] ) && is_array( $d['items'] ) ) {
			foreach ( array_slice( $d['items'], 0, 100 ) as $it ) {
				if ( is_array( $it ) ) {
					$items[] = $it;
				}
			}
		}
		self::record_order(
			$visit,
			$site,
			'js',
			array(
				'id'    => isset( $d['id'] ) ? (string) $d['id'] : '',
				'rev'   => isset( $d['rev'] ) ? (float) $d['rev'] : 0,
				'sub'   => isset( $d['sub'] ) ? (float) $d['sub'] : 0,
				'tax'   => isset( $d['tax'] ) ? (float) $d['tax'] : 0,
				'ship'  => isset( $d['ship'] ) ? (float) $d['ship'] : 0,
				'disc'  => isset( $d['disc'] ) ? (float) $d['disc'] : 0,
				'cur'   => isset( $d['cur'] ) ? (string) $d['cur'] : '',
				'new'   => 0,
				'items' => $items,
			),
			$ts
		);
	}

	/**
	 * Store an order and its items (idempotent per order id).
	 *
	 * @param object|null $visit  Visit.
	 * @param int         $site   Site.
	 * @param string      $source woocommerce | js | api.
	 * @param array       $o      Order data.
	 * @param int         $ts     Time.
	 * @return bool
	 */
	public static function record_order( $visit, $site, $source, $o, $ts ) {
		global $wpdb;
		$to  = NTAA_DB::t( 'orders' );
		$ti  = NTAA_DB::t( 'order_items' );
		$oid = mb_substr( sanitize_text_field( (string) $o['id'] ), 0, 64 );
		if ( '' === $oid ) {
			return false;
		}
		$exists = $wpdb->get_row( $wpdb->prepare( "SELECT id, status FROM {$to} WHERE site_id = %d AND source = %s AND order_id = %s", $site, $source, $oid ) ); // phpcs:ignore WordPress.DB
		if ( $exists && 'paid' === $exists->status ) {
			return true;
		}
		$rev   = max( 0, round( (float) $o['rev'], 2 ) );
		$now   = wp_date( 'Y-m-d H:i:s', $ts );
		$count = 0;
		foreach ( (array) $o['items'] as $it ) {
			$count += max( 1, (int) ( isset( $it['q'] ) ? $it['q'] : 1 ) );
		}
		$row = array(
			'site_id'      => (int) $site,
			'visit_id'     => $visit ? (int) $visit->id : 0,
			'source'       => $source,
			'order_id'     => $oid,
			'created_at'   => $now,
			'status'       => 'paid',
			'revenue'      => $rev,
			'subtotal'     => round( (float) $o['sub'], 2 ),
			'tax'          => round( (float) $o['tax'], 2 ),
			'shipping'     => round( (float) $o['ship'], 2 ),
			'discount'     => round( (float) $o['disc'], 2 ),
			'items'        => $count,
			'currency'     => strtoupper( substr( preg_replace( '/[^A-Za-z]/', '', (string) $o['cur'] ), 0, 3 ) ),
			'new_customer' => empty( $o['new'] ) ? 0 : 1,
		);
		if ( $exists ) {
			$wpdb->update( $to, $row, array( 'id' => (int) $exists->id ) ); // phpcs:ignore WordPress.DB
			$ref = (int) $exists->id;
			$wpdb->delete( $ti, array( 'order_ref' => $ref ) ); // phpcs:ignore WordPress.DB
		} else {
			$wpdb->insert( $to, $row ); // phpcs:ignore WordPress.DB
			$ref = (int) $wpdb->insert_id;
		}
		if ( ! $ref ) {
			return false;
		}
		foreach ( (array) $o['items'] as $it ) {
			$wpdb->insert( // phpcs:ignore WordPress.DB
				$ti,
				array(
					'site_id'    => (int) $site,
					'order_ref'  => $ref,
					'product_id' => isset( $it['id'] ) ? absint( $it['id'] ) : 0,
					'sku'        => isset( $it['sku'] ) ? mb_substr( sanitize_text_field( (string) $it['sku'] ), 0, 100 ) : '',
					'name'       => isset( $it['n'] ) ? mb_substr( sanitize_text_field( (string) $it['n'] ), 0, 255 ) : '',
					'category'   => isset( $it['cat'] ) ? mb_substr( sanitize_text_field( (string) $it['cat'] ), 0, 191 ) : '',
					'price'      => isset( $it['p'] ) ? round( (float) $it['p'], 2 ) : 0,
					'qty'        => isset( $it['q'] ) ? max( 1, (int) $it['q'] ) : 1,
					'created_at' => $now,
				)
			);
		}
		if ( $visit ) {
			$tv = NTAA_DB::t( 'visits' );
			$wpdb->query( $wpdb->prepare( "UPDATE {$tv} SET orders = orders + 1, revenue = revenue + %f WHERE id = %d", $rev, (int) $visit->id ) ); // phpcs:ignore WordPress.DB
			NTAA_Goals::on_order( $visit, $rev, $oid, $ts );
		}
		if ( substr( $now, 0, 10 ) < wp_date( 'Y-m-d' ) ) {
			NTAA_Archive::invalidate( (int) $site, substr( $now, 0, 10 ) );
		}
		do_action( 'ntaa_order_recorded', $row, $visit );
		return true;
	}
}
