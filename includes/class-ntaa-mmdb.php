<?php
/**
 * Minimal, dependency-free reader for MaxMind DB (.mmdb) files.
 *
 * Works with MaxMind GeoLite2 / GeoIP2, DB-IP Lite and ip-location-db databases.
 * Implements the MaxMind DB File Format Specification 2.0. It never loads the
 * whole file into memory: every lookup reads only the tree nodes and the record it needs.
 * No WordPress functions are used, so the fast tracking endpoint can use it too.
 *
 * @package NodeThemes_Analytics_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * MaxMind DB reader.
 */
class NTAA_MMDB {

	const MARKER = "\xAB\xCD\xEFMaxMind.com";

	/** @var resource */
	private $fh;
	/** @var int */
	private $size;
	/** @var array */
	public $metadata = array();
	/** @var int */
	private $node_count;
	/** @var int */
	private $record_size;
	/** @var int */
	private $node_bytes;
	/** @var int */
	private $tree_size;
	/** @var int */
	private $data_start;
	/** @var int|null */
	private $ipv4_start = null;
	/** @var array Small read cache for data records. */
	private $cache = array();

	/**
	 * Open a database.
	 *
	 * @param string $file Path.
	 * @throws Exception When the file is not a valid MaxMind DB.
	 */
	public function __construct( $file ) {
		if ( ! is_readable( $file ) ) {
			throw new Exception( 'GeoIP database is not readable.' );
		}
		$fh = fopen( $file, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( ! $fh ) {
			throw new Exception( 'GeoIP database could not be opened.' );
		}
		$this->fh   = $fh;
		$this->size = (int) filesize( $file );

		// Metadata is at the end of the file, after the last marker (searched in the last 128 KB).
		$tail_len = min( $this->size, 131072 );
		fseek( $this->fh, $this->size - $tail_len );
		$tail = (string) fread( $this->fh, $tail_len ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$pos  = strrpos( $tail, self::MARKER );
		if ( false === $pos ) {
			throw new Exception( 'Not a MaxMind DB file (metadata marker missing).' );
		}
		$meta_start = $this->size - $tail_len + $pos + strlen( self::MARKER );
		list( $meta ) = $this->decode( $meta_start, $meta_start );
		if ( ! is_array( $meta ) || empty( $meta['node_count'] ) || empty( $meta['record_size'] ) ) {
			throw new Exception( 'Invalid MaxMind DB metadata.' );
		}
		$this->metadata    = $meta;
		$this->node_count  = (int) $meta['node_count'];
		$this->record_size = (int) $meta['record_size'];
		if ( ! in_array( $this->record_size, array( 24, 28, 32 ), true ) ) {
			throw new Exception( 'Unsupported record size.' );
		}
		$this->node_bytes = (int) ( $this->record_size * 2 / 8 );
		$this->tree_size  = $this->node_bytes * $this->node_count;
		$this->data_start = $this->tree_size + 16;
		if ( $this->data_start > $this->size ) {
			throw new Exception( 'Corrupt MaxMind DB (tree larger than file).' );
		}
	}

	/**
	 * Close.
	 */
	public function close() {
		if ( is_resource( $this->fh ) ) {
			fclose( $this->fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
	}

	/**
	 * Destructor.
	 */
	public function __destruct() {
		$this->close();
	}

	/**
	 * Read bytes.
	 *
	 * @param int $offset Offset.
	 * @param int $len    Length.
	 * @return string
	 * @throws Exception On a short read.
	 */
	private function read( $offset, $len ) {
		if ( $len <= 0 ) {
			return '';
		}
		if ( $offset < 0 || $offset + $len > $this->size ) {
			throw new Exception( 'Read outside of the database file.' );
		}
		fseek( $this->fh, $offset );
		$b = fread( $this->fh, $len ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( false === $b || strlen( $b ) !== $len ) {
			throw new Exception( 'Short read from the database file.' );
		}
		return $b;
	}

	/**
	 * Record of a node (0 = left, 1 = right).
	 *
	 * @param int $node  Node number.
	 * @param int $index 0|1.
	 * @return int
	 */
	private function record( $node, $index ) {
		$b = $this->read( $node * $this->node_bytes, $this->node_bytes );
		switch ( $this->record_size ) {
			case 24:
				$s = 0 === $index ? substr( $b, 0, 3 ) : substr( $b, 3, 3 );
				return ( ord( $s[0] ) << 16 ) | ( ord( $s[1] ) << 8 ) | ord( $s[2] );
			case 28:
				$mid = ord( $b[3] );
				if ( 0 === $index ) {
					return ( ( $mid & 0xF0 ) << 20 ) | ( ord( $b[0] ) << 16 ) | ( ord( $b[1] ) << 8 ) | ord( $b[2] );
				}
				return ( ( $mid & 0x0F ) << 24 ) | ( ord( $b[4] ) << 16 ) | ( ord( $b[5] ) << 8 ) | ord( $b[6] );
			default:
				$u = unpack( 'N', 0 === $index ? substr( $b, 0, 4 ) : substr( $b, 4, 4 ) );
				return (int) $u[1];
		}
	}

	/**
	 * Node where IPv4 addresses start in an IPv6 tree (after 96 zero bits).
	 *
	 * @return int
	 */
	private function ipv4_start() {
		if ( null !== $this->ipv4_start ) {
			return $this->ipv4_start;
		}
		$node = 0;
		if ( 6 === (int) $this->metadata['ip_version'] ) {
			for ( $i = 0; $i < 96 && $node < $this->node_count; $i++ ) {
				$node = $this->record( $node, 0 );
			}
		}
		$this->ipv4_start = $node;
		return $node;
	}

	/**
	 * Look an address up.
	 *
	 * @param string $ip IPv4 or IPv6 address.
	 * @return array|null Record, or null when not found.
	 * @throws Exception On a corrupt database.
	 */
	public function get( $ip ) {
		$packed = @inet_pton( $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( false === $packed ) {
			return null;
		}
		$bits = strlen( $packed ) * 8;
		if ( 128 === $bits && 4 === (int) $this->metadata['ip_version'] ) {
			// IPv6 address in an IPv4-only database: only IPv4-mapped addresses can be answered.
			if ( 0 === strncmp( $packed, str_repeat( "\0", 10 ) . "\xff\xff", 12 ) ) {
				$packed = substr( $packed, 12 );
				$bits   = 32;
			} else {
				return null;
			}
		}
		$node = 32 === $bits ? $this->ipv4_start() : 0;
		for ( $i = 0; $i < $bits && $node < $this->node_count; $i++ ) {
			$byte = ord( $packed[ $i >> 3 ] );
			$bit  = 1 & ( $byte >> ( 7 - ( $i % 8 ) ) );
			$node = $this->record( $node, $bit );
		}
		if ( $node === $this->node_count ) {
			return null;
		}
		if ( $node < $this->node_count ) {
			throw new Exception( 'Invalid node in the search tree.' );
		}
		$offset = $node - $this->node_count - 16 + $this->data_start;
		if ( $offset >= $this->size ) {
			throw new Exception( 'Invalid data pointer in the search tree.' );
		}
		if ( isset( $this->cache[ $offset ] ) ) {
			return $this->cache[ $offset ];
		}
		list( $rec ) = $this->decode( $offset, $this->data_start );
		if ( count( $this->cache ) > 256 ) {
			$this->cache = array();
		}
		$this->cache[ $offset ] = is_array( $rec ) ? $rec : null;
		return $this->cache[ $offset ];
	}

	/**
	 * Decode one value.
	 *
	 * @param int $offset Absolute offset.
	 * @param int $base   Absolute offset of the section the pointers are relative to.
	 * @param int $depth  Recursion depth.
	 * @return array [value, next offset]
	 * @throws Exception On invalid data.
	 */
	private function decode( $offset, $base, $depth = 0 ) {
		if ( $depth > 64 ) {
			throw new Exception( 'MaxMind DB data is nested too deeply.' );
		}
		$ctrl = ord( $this->read( $offset, 1 ) );
		++$offset;
		$type = $ctrl >> 5;

		if ( 1 === $type ) { // Pointer.
			$ss  = ( $ctrl >> 3 ) & 0x3;
			$vvv = $ctrl & 0x7;
			$b   = $this->read( $offset, $ss + 1 );
			switch ( $ss ) {
				case 0:
					$p = ( $vvv << 8 ) | ord( $b[0] );
					break;
				case 1:
					$p = ( ( $vvv << 16 ) | ( ord( $b[0] ) << 8 ) | ord( $b[1] ) ) + 2048;
					break;
				case 2:
					$p = ( ( $vvv << 24 ) | ( ord( $b[0] ) << 16 ) | ( ord( $b[1] ) << 8 ) | ord( $b[2] ) ) + 526336;
					break;
				default:
					$u = unpack( 'N', $b );
					$p = (int) $u[1];
			}
			list( $value ) = $this->decode( $base + $p, $base, $depth + 1 );
			return array( $value, $offset + $ss + 1 );
		}

		if ( 0 === $type ) { // Extended type.
			$type = 7 + ord( $this->read( $offset, 1 ) );
			++$offset;
			if ( $type < 8 ) {
				throw new Exception( 'Invalid extended type in MaxMind DB.' );
			}
		}

		$size = $ctrl & 0x1f;
		if ( $size >= 29 ) {
			$n = $size - 28;
			$b = $this->read( $offset, $n );
			$offset += $n;
			if ( 29 === $size ) {
				$size = 29 + ord( $b[0] );
			} elseif ( 30 === $size ) {
				$size = 285 + ( ( ord( $b[0] ) << 8 ) | ord( $b[1] ) );
			} else {
				$size = 65821 + ( ( ord( $b[0] ) << 16 ) | ( ord( $b[1] ) << 8 ) | ord( $b[2] ) );
			}
		}

		switch ( $type ) {
			case 2: // UTF-8 string.
				return array( $this->read( $offset, $size ), $offset + $size );
			case 3: // Double.
				if ( 8 !== $size ) {
					throw new Exception( 'Invalid double size.' );
				}
				$u = unpack( 'E', $this->read( $offset, 8 ) );
				return array( (float) $u[1], $offset + 8 );
			case 4: // Bytes.
				return array( $this->read( $offset, $size ), $offset + $size );
			case 5: // uint16.
			case 6: // uint32.
			case 8: // int32.
			case 9: // uint64.
			case 10: // uint128.
				$max = array( 5 => 2, 6 => 4, 8 => 4, 9 => 8, 10 => 16 );
				if ( $size > $max[ $type ] ) {
					throw new Exception( 'Invalid integer size.' );
				}
				$b   = $this->read( $offset, $size );
				$val = 0;
				$big = false;
				for ( $i = 0; $i < $size; $i++ ) {
					if ( $val > ( PHP_INT_MAX >> 8 ) ) {
						$big = true;
						break;
					}
					$val = ( $val << 8 ) | ord( $b[ $i ] );
				}
				if ( $big ) {
					$val = '0x' . bin2hex( $b ); // Too large for an int: return as hex text.
				} elseif ( 8 === $type && 4 === $size && $val >= 0x80000000 ) {
					$val -= 0x100000000;
				}
				return array( $val, $offset + $size );
			case 7: // Map.
				$map = array();
				for ( $i = 0; $i < $size; $i++ ) {
					list( $k, $offset ) = $this->decode( $offset, $base, $depth + 1 );
					list( $v, $offset ) = $this->decode( $offset, $base, $depth + 1 );
					$map[ (string) $k ] = $v;
				}
				return array( $map, $offset );
			case 11: // Array.
				$arr = array();
				for ( $i = 0; $i < $size; $i++ ) {
					list( $v, $offset ) = $this->decode( $offset, $base, $depth + 1 );
					$arr[] = $v;
				}
				return array( $arr, $offset );
			case 14: // Boolean (value is the size).
				return array( 0 !== $size, $offset );
			case 15: // Float.
				if ( 4 !== $size ) {
					throw new Exception( 'Invalid float size.' );
				}
				$u = unpack( 'G', $this->read( $offset, 4 ) );
				return array( (float) $u[1], $offset + 4 );
			case 12: // Data cache container.
			case 13: // End marker.
				return array( null, $offset );
		}
		throw new Exception( 'Unknown data type ' . $type . ' in MaxMind DB.' );
	}

	/**
	 * Normalise a record from any supported database layout.
	 *
	 * @param array|null $r Record.
	 * @return array country (ISO-2), region, city.
	 */
	public static function location( $r ) {
		$out = array(
			'country' => '',
			'region'  => '',
			'city'    => '',
		);
		if ( ! is_array( $r ) ) {
			return $out;
		}
		$name = function ( $node ) {
			if ( ! is_array( $node ) ) {
				return is_string( $node ) ? $node : '';
			}
			if ( isset( $node['names']['en'] ) ) {
				return (string) $node['names']['en'];
			}
			if ( isset( $node['names'] ) && is_array( $node['names'] ) && $node['names'] ) {
				return (string) reset( $node['names'] );
			}
			return '';
		};
		// MaxMind / DB-IP official layout.
		if ( isset( $r['country']['iso_code'] ) ) {
			$out['country'] = (string) $r['country']['iso_code'];
		} elseif ( isset( $r['registered_country']['iso_code'] ) ) {
			$out['country'] = (string) $r['registered_country']['iso_code'];
		} elseif ( isset( $r['country_code'] ) ) { // ip-location-db layout.
			$out['country'] = (string) $r['country_code'];
		}
		if ( isset( $r['subdivisions'][0] ) ) {
			$out['region'] = $name( $r['subdivisions'][0] );
		} elseif ( isset( $r['state1'] ) ) {
			$out['region'] = (string) $r['state1'];
		}
		if ( isset( $r['city'] ) ) {
			$out['city'] = $name( $r['city'] );
		}
		$out['country'] = strtoupper( substr( preg_replace( '/[^A-Za-z]/', '', $out['country'] ), 0, 2 ) );
		$out['region']  = substr( trim( $out['region'] ), 0, 80 );
		$out['city']    = substr( trim( $out['city'] ), 0, 80 );
		return $out;
	}
}
