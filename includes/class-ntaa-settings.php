<?php
/**
 * Settings storage + encryption of API keys.
 *
 * @package NodeThemes_Analytics_AI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Settings.
 */
class NTAA_Settings {

	const OPTION = 'ntaa_settings';

	private static $cache = null;

	public static function providers() {
		return array(
			'openai'    => array(
				'label'   => 'OpenAI (ChatGPT)',
				'model'   => 'gpt-5.5',
				'models'  => array( 'gpt-5.5', 'gpt-5.4-mini', 'gpt-5.4-nano', 'chat-latest' ),
				'base'    => 'https://api.openai.com/v1',
				'const'   => 'NTAA_OPENAI_KEY',
				'keyhelp' => 'https://platform.openai.com/api-keys',
			),
			'anthropic' => array(
				'label'   => 'Anthropic (Claude)',
				'model'   => 'claude-sonnet-5-5',
				'models'  => array( 'claude-sonnet-5-5', 'claude-opus-5-5', 'claude-haiku-5-5' ),
				'base'    => 'https://api.anthropic.com',
				'const'   => 'NTAA_ANTHROPIC_KEY',
				'keyhelp' => 'https://console.anthropic.com/settings/keys',
			),
			'gemini'    => array(
				'label'   => 'Google (Gemini)',
				'model'   => 'gemini-flash-latest',
				'models'  => array( 'gemini-flash-latest', 'gemini-3.8-flash', 'gemini-3.5-flash-lite', 'gemini-3.1-pro-preview' ),
				'base'    => 'https://generativelanguage.googleapis.com',
				'const'   => 'NTAA_GEMINI_KEY',
				'keyhelp' => 'https://aistudio.google.com/apikey',
			),
		);
	}

	/**
	 * Report languages (value => English name used in the AI prompt).
	 *
	 * @return array
	 */
	public static function languages() {
		return array(
			'site' => '',
			'en'   => 'English',
			'fa'   => 'Persian (Farsi)',
			'ar'   => 'Arabic',
			'tr'   => 'Turkish',
			'de'   => 'German',
			'fr'   => 'French',
			'es'   => 'Spanish',
			'it'   => 'Italian',
			'pt'   => 'Portuguese',
			'ru'   => 'Russian',
			'nl'   => 'Dutch',
		);
	}

	public static function defaults() {
		$d = array(
			// Tracking.
			'enabled'          => 1,
			'cookieless'       => 1,
			'respect_dnt'      => 1,
			'trust_proxy'      => 0,
			'exclude_roles'    => array( 'administrator' ),
			'exclude_ips'      => '',
			'track_outbound'   => 1,
			'track_downloads'  => 1,
			'track_forms'      => 0,
			'track_spa'        => 0,
			'track_vitals'     => 1,
			'track_search'     => 1,
			'track_ecommerce'  => 1,
			'track_bots'       => 1,
			'custom_dims'      => array( '', '', '', '', '' ),
			'consent'          => 'off', // off | cookie | strict.
			// Data & high traffic.
			'retention_days'   => 395,  // Raw visit log.
			'queue'            => 0,
			'fast_endpoint'    => 0,
			// GeoIP.
			'geoip_source'     => 'dbip_country', // dbip_country | dbip_city | maxmind | upload | off.
			'geo_precision'    => 'city', // city | region | country.
			'maxmind_account'  => '',
			'maxmind_key'      => '',
			// Access.
			'roles_view'       => array( 'administrator' ),
			'roles_ai'         => array( 'administrator' ),
			// AI.
			'ai_provider'      => 'openai',
			'ai_language'      => 'site',
			'ai_max_tokens'    => 6000,
			'ai_custom_note'   => '',
			// Scheduled reports (Pro).
			'report_enabled'   => 0,
			'report_freq'      => 'weekly',
			'report_email'     => get_option( 'admin_email' ),
			'report_ai'        => 1,
		);
		foreach ( self::providers() as $id => $p ) {
			$d[ $id . '_key' ]   = '';
			$d[ $id . '_model' ] = $p['model'];
			$d[ $id . '_base' ]  = '';
		}
		return $d;
	}

	public static function all() {
		if ( null === self::$cache ) {
			$saved       = get_option( self::OPTION, array() );
			self::$cache = wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
		}
		return self::$cache;
	}

	public static function get( $key, $fallback = null ) {
		$all = self::all();
		return array_key_exists( $key, $all ) ? $all[ $key ] : $fallback;
	}

	public static function save( array $values ) {
		update_option( self::OPTION, $values, false );
		self::$cache = null;
	}

	public static function flush() {
		self::$cache = null;
	}

	/* ---------- API keys ---------- */

	public static function get_api_key( $provider ) {
		$providers = self::providers();
		if ( ! isset( $providers[ $provider ] ) ) {
			return '';
		}
		$const = $providers[ $provider ]['const'];
		if ( defined( $const ) && constant( $const ) ) {
			return (string) constant( $const );
		}
		return self::decrypt( (string) self::get( $provider . '_key', '' ) );
	}

	public static function key_from_constant( $provider ) {
		$providers = self::providers();
		return isset( $providers[ $provider ] ) && defined( $providers[ $provider ]['const'] ) && constant( $providers[ $provider ]['const'] );
	}

	public static function configured_providers() {
		$out = array();
		foreach ( self::providers() as $id => $p ) {
			if ( self::get_api_key( $id ) !== '' || self::get( $id . '_base' ) ) {
				$out[ $id ] = $p['label'];
			}
		}
		return $out;
	}

	private static function crypto_key() {
		return hash( 'sha256', wp_salt( 'auth' ) . 'ntaa-analytics', true );
	}

	public static function encrypt( $plain ) {
		if ( '' === $plain ) {
			return '';
		}
		if ( ! function_exists( 'openssl_encrypt' ) ) {
			return 'b64:' . base64_encode( $plain );
		}
		$iv  = random_bytes( 16 );
		$enc = openssl_encrypt( $plain, 'aes-256-cbc', self::crypto_key(), OPENSSL_RAW_DATA, $iv );
		$mac = hash_hmac( 'sha256', $iv . $enc, self::crypto_key(), true );
		return 'enc:' . base64_encode( $iv . $mac . $enc );
	}

	public static function decrypt( $stored ) {
		if ( '' === $stored ) {
			return '';
		}
		if ( 0 === strpos( $stored, 'b64:' ) ) {
			return (string) base64_decode( substr( $stored, 4 ) );
		}
		if ( 0 !== strpos( $stored, 'enc:' ) || ! function_exists( 'openssl_decrypt' ) ) {
			return $stored;
		}
		$raw = base64_decode( substr( $stored, 4 ) );
		if ( strlen( $raw ) < 49 ) {
			return '';
		}
		$iv  = substr( $raw, 0, 16 );
		$mac = substr( $raw, 16, 32 );
		$enc = substr( $raw, 48 );
		if ( ! hash_equals( hash_hmac( 'sha256', $iv . $enc, self::crypto_key(), true ), $mac ) ) {
			return '';
		}
		$plain = openssl_decrypt( $enc, 'aes-256-cbc', self::crypto_key(), OPENSSL_RAW_DATA, $iv );
		return false === $plain ? '' : $plain;
	}

	/* ---------- Sanitizing the settings form ---------- */

	public static function sanitize( $input ) {
		$old = self::all();
		$in  = is_array( $input ) ? $input : array();
		$out = self::defaults();

		foreach ( array( 'enabled', 'cookieless', 'respect_dnt', 'trust_proxy', 'track_outbound', 'track_downloads', 'track_forms', 'track_spa', 'track_vitals', 'track_search', 'track_ecommerce', 'track_bots', 'queue', 'report_enabled', 'report_ai' ) as $k ) {
			$out[ $k ] = empty( $in[ $k ] ) ? 0 : 1;
		}
		// The fast endpoint can only be switched on together with the queue, and only after a successful self-test.
		$out['fast_endpoint'] = ( ! empty( $in['fast_endpoint'] ) && $out['queue'] ) ? 1 : 0;
		if ( $out['fast_endpoint'] && ! NTAA_Tracker::fast_endpoint_works() ) {
			$out['fast_endpoint'] = 0;
			add_settings_error( self::OPTION, 'ntaa_fast', __( 'The fast tracking endpoint could not be reached on this server, so it stays off. The queue is still used.', 'nodethemes-analytics-ai' ), 'warning' );
		}

		$roles                 = isset( $in['exclude_roles'] ) && is_array( $in['exclude_roles'] ) ? array_map( 'sanitize_key', $in['exclude_roles'] ) : array();
		$out['exclude_roles']  = array_values( $roles );
		$out['exclude_ips']    = isset( $in['exclude_ips'] ) ? sanitize_textarea_field( $in['exclude_ips'] ) : '';
		$out['retention_days'] = isset( $in['retention_days'] ) ? max( 0, min( 3650, (int) $in['retention_days'] ) ) : 395;
		$out['consent']        = isset( $in['consent'] ) && in_array( $in['consent'], array( 'off', 'cookie', 'strict' ), true ) ? $in['consent'] : 'off';

		$dims = array();
		for ( $i = 0; $i < 5; $i++ ) {
			$dims[] = isset( $in['custom_dims'][ $i ] ) ? mb_substr( sanitize_text_field( $in['custom_dims'][ $i ] ), 0, 60 ) : '';
		}
		$out['custom_dims'] = $dims;

		$out['geoip_source']    = isset( $in['geoip_source'] ) && in_array( $in['geoip_source'], array( 'dbip_country', 'dbip_city', 'maxmind', 'upload', 'off' ), true ) ? $in['geoip_source'] : 'dbip_country';
		$out['geo_precision']   = isset( $in['geo_precision'] ) && in_array( $in['geo_precision'], array( 'city', 'region', 'country' ), true ) ? $in['geo_precision'] : 'city';
		$out['maxmind_account'] = isset( $in['maxmind_account'] ) ? preg_replace( '/[^0-9]/', '', (string) $in['maxmind_account'] ) : '';
		$mm                     = isset( $in['maxmind_key'] ) ? trim( (string) $in['maxmind_key'] ) : '';
		if ( 0 === strpos( $mm, 'enc:' ) || 0 === strpos( $mm, 'b64:' ) ) {
			$out['maxmind_key'] = $mm;
		} elseif ( '' !== $mm ) {
			$out['maxmind_key'] = self::encrypt( preg_replace( '/[^A-Za-z0-9_]/', '', $mm ) );
		} else {
			$out['maxmind_key'] = $old['maxmind_key'];
		}

		foreach ( array( 'roles_view', 'roles_ai' ) as $rk ) {
			$r          = isset( $in[ $rk ] ) && is_array( $in[ $rk ] ) ? array_values( array_map( 'sanitize_key', $in[ $rk ] ) ) : array();
			$out[ $rk ] = array_values( array_unique( array_merge( array( 'administrator' ), $r ) ) );
		}

		$providers          = self::providers();
		$out['ai_provider'] = isset( $in['ai_provider'], $providers[ $in['ai_provider'] ] ) ? $in['ai_provider'] : 'openai';
		$out['ai_language'] = isset( $in['ai_language'] ) && array_key_exists( $in['ai_language'], self::languages() ) ? $in['ai_language'] : 'site';
		$out['ai_max_tokens'] = isset( $in['ai_max_tokens'] ) ? max( 500, min( 32000, (int) $in['ai_max_tokens'] ) ) : 6000;
		$out['ai_custom_note'] = isset( $in['ai_custom_note'] ) ? sanitize_textarea_field( $in['ai_custom_note'] ) : '';

		foreach ( $providers as $id => $p ) {
			$new_key = isset( $in[ $id . '_key' ] ) ? trim( (string) $in[ $id . '_key' ] ) : '';
			if ( ! empty( $in[ $id . '_key_delete' ] ) ) {
				$out[ $id . '_key' ] = '';
			} elseif ( 0 === strpos( $new_key, 'enc:' ) || 0 === strpos( $new_key, 'b64:' ) ) {
				// Already encrypted (WordPress may sanitize twice on first save).
				$out[ $id . '_key' ] = $new_key;
			} elseif ( '' !== $new_key ) {
				$out[ $id . '_key' ] = self::encrypt( sanitize_text_field( $new_key ) );
			} else {
				$out[ $id . '_key' ] = $old[ $id . '_key' ];
			}
			$model                 = isset( $in[ $id . '_model' ] ) ? preg_replace( '/[^A-Za-z0-9._:\/-]/', '', $in[ $id . '_model' ] ) : '';
			$out[ $id . '_model' ] = $model ? $model : $p['model'];
			$out[ $id . '_base' ]  = isset( $in[ $id . '_base' ] ) ? esc_url_raw( trim( $in[ $id . '_base' ] ) ) : '';
		}

		$out['report_freq']  = isset( $in['report_freq'] ) && in_array( $in['report_freq'], array( 'daily', 'weekly', 'monthly' ), true ) ? $in['report_freq'] : 'weekly';
		$out['report_email'] = isset( $in['report_email'] ) ? implode( ',', array_filter( array_map( 'sanitize_email', explode( ',', $in['report_email'] ) ) ) ) : '';

		self::$cache = null;
		return $out;
	}

	/* ---------- Runtime snapshot (used by the fast endpoint, which runs without plugins) ---------- */

	/**
	 * Secret used for visitor hashing and signed values. Created once.
	 *
	 * @return string
	 */
	public static function secret() {
		$s = get_option( 'ntaa_secret' );
		if ( ! $s ) {
			$s = wp_generate_password( 64, true, true );
			add_option( 'ntaa_secret', $s, '', false );
		}
		return (string) $s;
	}

	/**
	 * Store the small set of values the tracking endpoints need, in one autoloaded option.
	 */
	public static function write_runtime() {
		self::$cache = null;
		$s     = self::all();
		$types = apply_filters(
			'ntaa_hit_types',
			array(
				'pv'    => 8192,
				'leave' => 4096,
				'ev'    => 4096,
				'goal'  => 2048,
				'order' => 16384,
			)
		);
		$data = array(
			'v'           => 2,
			'secret'      => self::secret(),
			'home_host'   => strtolower( preg_replace( '/^www\./i', '', (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) ),
			'enabled'     => (int) $s['enabled'],
			'cookieless'  => (int) $s['cookieless'],
			'dnt'         => (int) $s['respect_dnt'],
			'trust_proxy' => (int) $s['trust_proxy'],
			'exclude_ips' => (string) $s['exclude_ips'],
			'queue'       => (int) $s['queue'],
			'fast'        => (int) $s['fast_endpoint'],
			'types'       => is_array( $types ) ? array_map( 'intval', $types ) : array(),
			'sites'       => apply_filters( 'ntaa_site_domains_map', array() ),
			'geo_files'   => NTAA_GeoIP::files(),
			'geo_prec'    => (string) $s['geo_precision'],
			'tz'          => wp_timezone_string(),
			'table'       => $GLOBALS['wpdb']->prefix . 'ntaa_queue',
			'max_queue'   => 500000,
		);
		update_option( 'ntaa_runtime', $data, true );
		return $data;
	}

	/**
	 * Runtime snapshot (rebuilt when missing).
	 *
	 * @return array
	 */
	public static function runtime() {
		$r = get_option( 'ntaa_runtime' );
		if ( ! is_array( $r ) || empty( $r['v'] ) ) {
			$r = self::write_runtime();
		}
		return $r;
	}

	/**
	 * Copy the role choices to WordPress capabilities.
	 */
	public static function sync_caps() {
		$s = self::all();
		foreach ( wp_roles()->role_objects as $name => $role ) {
			$view = in_array( $name, (array) $s['roles_view'], true ) || 'administrator' === $name;
			$ai   = in_array( $name, (array) $s['roles_ai'], true ) || 'administrator' === $name;
			if ( $view ) {
				$role->add_cap( NTAA_CAP );
			} else {
				$role->remove_cap( NTAA_CAP );
			}
			if ( $ai && $view ) {
				$role->add_cap( NTAA_CAP_AI );
			} else {
				$role->remove_cap( NTAA_CAP_AI );
			}
		}
	}
}
