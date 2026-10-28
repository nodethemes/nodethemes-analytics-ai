<?php
/**
 * Receives tracking requests and turns them into clean, privacy-safe "hits".
 *
 * This class deliberately avoids WordPress functions that are not loaded in SHORTINIT mode,
 * so the same code runs in the normal REST endpoint and in the fast endpoint (track.php).
 * The IP address is used here only (exclusions, visitor hash, GeoIP) and is never stored.
 *
 * @package NodeThemes_Analytics_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Hit receiver.
 */
class NTAA_Hit {

	const MAX_BATCH = 25;

	/**
	 * Parse a request body into normalised hits.
	 *
	 * @param string $body   Raw body.
	 * @param array  $server $_SERVER.
	 * @param array  $rt     Runtime snapshot (NTAA_Settings::runtime()).
	 * @param array  $cookie $_COOKIE.
	 * @return array List of hits (empty when the request must be ignored).
	 */
	public static function receive( $body, $server, $rt, $cookie = array() ) {
		if ( empty( $rt['enabled'] ) || ! is_string( $body ) || '' === $body ) {
			return array();
		}
		if ( strlen( $body ) > 2097152 ) {
			return array();
		}
		if ( "\x1f\x8b" === substr( $body, 0, 2 ) && function_exists( 'gzdecode' ) ) {
			$body = @gzdecode( $body, 4194304 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			if ( false === $body ) {
				return array();
			}
		}
		$data = json_decode( $body, true );
		if ( ! is_array( $data ) ) {
			return array();
		}
		$list = isset( $data['b'] ) && is_array( $data['b'] ) ? array_slice( $data['b'], 0, self::MAX_BATCH ) : array( $data );

		$ua = isset( $server['HTTP_USER_AGENT'] ) ? substr( (string) $server['HTTP_USER_AGENT'], 0, 500 ) : '';
		if ( NTAA_UA::is_bot( $ua ) ) {
			return array();
		}
		if ( ! empty( $rt['dnt'] ) && ( ( isset( $server['HTTP_DNT'] ) && '1' === (string) $server['HTTP_DNT'] ) || ( isset( $server['HTTP_SEC_GPC'] ) && '1' === (string) $server['HTTP_SEC_GPC'] ) ) ) {
			return array();
		}
		if ( isset( $cookie['ntaa_optout'] ) && '1' === (string) $cookie['ntaa_optout'] ) {
			return array();
		}
		$ip = self::ip( $server, ! empty( $rt['trust_proxy'] ) );
		if ( self::ip_excluded( $ip, isset( $rt['exclude_ips'] ) ? (string) $rt['exclude_ips'] : '' ) ) {
			return array();
		}

		$site = isset( $list[0]['s'] ) ? (int) $list[0]['s'] : 1;
		if ( $site < 1 ) {
			$site = 1;
		}
		if ( $site > 1 ) {
			// Extra sites (Pro): the request must come from one of the site's own domains.
			$allowed = isset( $rt['sites'][ $site ] ) ? (array) $rt['sites'][ $site ] : array();
			$origin  = self::origin_host( $server );
			if ( ! $allowed || '' === $origin || ! self::host_allowed( $origin, $allowed ) ) {
				return array();
			}
		}

		$ts     = time();
		$types  = isset( $rt['types'] ) && is_array( $rt['types'] ) ? $rt['types'] : array();
		$vid    = isset( $list[0]['vid'] ) ? strtolower( preg_replace( '/[^a-fA-F0-9]/', '', (string) $list[0]['vid'] ) ) : '';
		$vid    = ( empty( $rt['cookieless'] ) && 16 === strlen( $vid ) ) ? $vid : '';
		$secret = isset( $rt['secret'] ) ? (string) $rt['secret'] : '';
		$hash   = '' !== $vid ? md5( $secret . '|vid|' . $vid ) : self::daily_hash( $ip, $ua, $site, $secret, $rt );
		$geo    = null;
		$parsed = null;
		$out    = array();

		foreach ( $list as $h ) {
			if ( ! is_array( $h ) || empty( $h['t'] ) || ! is_string( $h['t'] ) ) {
				continue;
			}
			$t = preg_replace( '/[^a-z0-9_]/', '', strtolower( $h['t'] ) );
			if ( ! isset( $types[ $t ] ) ) {
				continue;
			}
			$enc = json_encode( $h ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			if ( false === $enc || strlen( $enc ) > (int) $types[ $t ] ) {
				continue;
			}
			$hit = array(
				't'   => $t,
				's'   => $site,
				'h'   => $hash,
				'vid' => $vid,
				'ts'  => $ts,
				'd'   => self::clean_payload( $h ),
			);
			if ( 'pv' === $t ) {
				if ( null === $geo ) {
					$geo = self::geo( $ip, $server, $rt, isset( $h['tz'] ) ? (string) $h['tz'] : '' );
				}
				if ( null === $parsed ) {
					$parsed = NTAA_UA::parse( $ua );
				}
				$hit['geo'] = $geo;
				$hit['ua']  = $parsed;
			}
			$out[] = $hit;
		}
		return $out;
	}

	/**
	 * Keep only scalar values and nested arrays of limited depth/size.
	 *
	 * @param mixed $v     Value.
	 * @param int   $depth Depth.
	 * @return mixed
	 */
	private static function clean_payload( $v, $depth = 0 ) {
		if ( is_array( $v ) ) {
			if ( $depth > 4 ) {
				return array();
			}
			$o = array();
			$i = 0;
			foreach ( $v as $k => $x ) {
				if ( ++$i > 400 ) {
					break;
				}
				$o[ is_int( $k ) ? $k : substr( (string) $k, 0, 40 ) ] = self::clean_payload( $x, $depth + 1 );
			}
			return $o;
		}
		if ( is_string( $v ) ) {
			return substr( $v, 0, 2048 );
		}
		if ( is_bool( $v ) || is_int( $v ) || is_float( $v ) || null === $v ) {
			return $v;
		}
		return '';
	}

	/**
	 * Visitor IP (proxy headers only when trusted).
	 *
	 * @param array $server $_SERVER.
	 * @param bool  $trust  Trust proxy headers.
	 * @return string
	 */
	public static function ip( $server, $trust ) {
		$ip = isset( $server['REMOTE_ADDR'] ) ? trim( (string) $server['REMOTE_ADDR'] ) : '';
		if ( $trust ) {
			foreach ( array( 'HTTP_CF_CONNECTING_IP', 'HTTP_TRUE_CLIENT_IP', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR' ) as $k ) {
				if ( ! empty( $server[ $k ] ) ) {
					$parts = explode( ',', (string) $server[ $k ] );
					$cand  = trim( $parts[0] );
					if ( filter_var( $cand, FILTER_VALIDATE_IP ) ) {
						$ip = $cand;
						break;
					}
				}
			}
		}
		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
	}

	/**
	 * Is the IP excluded by the site owner's rules?
	 *
	 * @param string $ip   IP.
	 * @param string $list Rules (one per line; * wildcards and CIDR).
	 * @return bool
	 */
	public static function ip_excluded( $ip, $list ) {
		$list = trim( $list );
		if ( '' === $list || '' === $ip ) {
			return false;
		}
		foreach ( preg_split( '/[\s,]+/', $list ) as $rule ) {
			$rule = trim( $rule );
			if ( '' === $rule ) {
				continue;
			}
			if ( $rule === $ip ) {
				return true;
			}
			if ( false !== strpos( $rule, '*' ) ) {
				$re = '/^' . str_replace( '\*', '.*', preg_quote( $rule, '/' ) ) . '$/';
				if ( preg_match( $re, $ip ) ) {
					return true;
				}
			}
			if ( false !== strpos( $rule, '/' ) && self::cidr_match( $ip, $rule ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * CIDR match for IPv4 and IPv6.
	 *
	 * @param string $ip   IP.
	 * @param string $cidr Range.
	 * @return bool
	 */
	public static function cidr_match( $ip, $cidr ) {
		$parts   = explode( '/', $cidr, 2 );
		$ip_bin  = @inet_pton( $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		$sub_bin = @inet_pton( $parts[0] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( false === $ip_bin || false === $sub_bin || strlen( $ip_bin ) !== strlen( $sub_bin ) ) {
			return false;
		}
		$bits  = max( 0, min( strlen( $ip_bin ) * 8, (int) ( isset( $parts[1] ) ? $parts[1] : 0 ) ) );
		$bytes = intdiv( $bits, 8 );
		if ( substr( $ip_bin, 0, $bytes ) !== substr( $sub_bin, 0, $bytes ) ) {
			return false;
		}
		$rem = $bits % 8;
		if ( 0 === $rem ) {
			return true;
		}
		$mask = chr( ( 0xff << ( 8 - $rem ) ) & 0xff );
		return ( $ip_bin[ $bytes ] & $mask ) === ( $sub_bin[ $bytes ] & $mask );
	}

	/**
	 * Host of the page that sent the request.
	 *
	 * @param array $server $_SERVER.
	 * @return string
	 */
	public static function origin_host( $server ) {
		foreach ( array( 'HTTP_ORIGIN', 'HTTP_REFERER' ) as $k ) {
			if ( ! empty( $server[ $k ] ) ) {
				$h = parse_url( (string) $server[ $k ], PHP_URL_HOST ); // phpcs:ignore WordPress.WP.AlternativeFunctions
				if ( $h ) {
					return strtolower( preg_replace( '/^www\./i', '', $h ) );
				}
			}
		}
		return '';
	}

	/**
	 * Is the host one of the allowed domains (subdomains included)?
	 *
	 * @param string $host    Host.
	 * @param array  $allowed Domains.
	 * @return bool
	 */
	public static function host_allowed( $host, $allowed ) {
		foreach ( $allowed as $d ) {
			$d = strtolower( preg_replace( '/^www\./i', '', trim( (string) $d ) ) );
			if ( '' !== $d && ( $host === $d || substr( $host, -strlen( '.' . $d ) ) === '.' . $d ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Today's random salt (rotates daily; yesterday's is kept one day for order attribution).
	 *
	 * @param array $rt Runtime.
	 * @param int   $offset 0 = today, -1 = yesterday.
	 * @return string
	 */
	public static function daily_salt( $rt, $offset = 0 ) {
		$tz = null;
		try {
			$tz = new DateTimeZone( ! empty( $rt['tz'] ) ? $rt['tz'] : 'UTC' );
		} catch ( Exception $e ) {
			$tz = new DateTimeZone( 'UTC' );
		}
		$today = ( new DateTime( 'now', $tz ) )->format( 'Y-m-d' );
		$s     = get_option( 'ntaa_daily_salt' );
		if ( ! is_array( $s ) || ! isset( $s['date'] ) || $s['date'] !== $today ) {
			$prev = ( is_array( $s ) && isset( $s['date'], $s['salt'] ) ) ? $s : array( 'date' => '', 'salt' => '' );
			$s    = array(
				'date'      => $today,
				'salt'      => bin2hex( random_bytes( 32 ) ),
				'prev_date' => $prev['date'],
				'prev_salt' => $prev['salt'],
			);
			update_option( 'ntaa_daily_salt', $s, true );
		}
		if ( -1 === $offset ) {
			return isset( $s['prev_salt'] ) ? (string) $s['prev_salt'] : '';
		}
		return (string) $s['salt'];
	}

	/**
	 * Cookieless visitor hash (changes every day, cannot be turned back into the IP).
	 *
	 * @param string $ip     IP.
	 * @param string $ua     User agent.
	 * @param int    $site   Site id.
	 * @param string $secret Secret.
	 * @param array  $rt     Runtime.
	 * @param int    $offset Day offset.
	 * @return string
	 */
	public static function daily_hash( $ip, $ua, $site, $secret, $rt, $offset = 0 ) {
		return md5( self::daily_salt( $rt, $offset ) . '|' . $ip . '|' . $ua . '|' . $site . '|' . $secret );
	}

	/**
	 * Location of the visitor.
	 *
	 * @param string $ip     IP.
	 * @param array  $server $_SERVER.
	 * @param array  $rt     Runtime.
	 * @param string $tz     Visitor time zone.
	 * @return array country, region, city
	 */
	public static function geo( $ip, $server, $rt, $tz ) {
		$geo = array(
			'country' => '',
			'region'  => '',
			'city'    => '',
		);
		if ( ! empty( $rt['geo_files'] ) && class_exists( 'NTAA_GeoIP' ) ) {
			$geo = NTAA_GeoIP::lookup( $ip, $rt['geo_files'] );
		} elseif ( ! empty( $rt['geo_files'] ) ) {
			foreach ( (array) $rt['geo_files'] as $f ) {
				try {
					$r   = new NTAA_MMDB( $f );
					$rec = $r->get( $ip );
					$r->close();
					if ( $rec ) {
						$geo = NTAA_MMDB::location( $rec );
						break;
					}
				} catch ( Exception $e ) {
					continue;
				}
			}
		}
		if ( '' === $geo['country'] ) {
			$_server_backup = $_SERVER; // NTAA_UA::country reads CDN headers from $_SERVER.
			$_SERVER        = $server; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			$geo['country'] = NTAA_UA::country( preg_replace( '/[^A-Za-z_\/+-]/', '', $tz ) );
			$_SERVER        = $_server_backup; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			$geo['region']  = '';
			$geo['city']    = '';
		}
		$prec = isset( $rt['geo_prec'] ) ? $rt['geo_prec'] : 'city';
		if ( 'country' === $prec ) {
			$geo['region'] = '';
			$geo['city']   = '';
		} elseif ( 'region' === $prec ) {
			$geo['city'] = '';
		}
		return $geo;
	}
}
