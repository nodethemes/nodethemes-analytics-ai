<?php
/**
 * GeoIP: download, update and look up country / region / city from a local MaxMind-format database.
 *
 * Sources:
 * - DB-IP Lite (free, CC BY 4.0, downloaded monthly, no account needed) — country or city.
 * - MaxMind GeoLite2 City (free account: account ID + license key).
 * - Any .mmdb file uploaded by the site owner.
 *
 * The visitor's IP address is only used in memory for the lookup and is never stored.
 *
 * @package NodeThemes_Analytics_AI
 */

defined( 'ABSPATH' ) || exit;

/**
 * GeoIP.
 */
class NTAA_GeoIP {

	const OPTION = 'ntaa_geoip';

	/** @var NTAA_MMDB[]|null */
	private static $readers = null;

	/**
	 * Boot.
	 */
	public static function init() {
		add_action( 'ntaa_geoip_update', array( __CLASS__, 'update' ) );
		add_action( 'admin_post_ntaa_geoip_update', array( __CLASS__, 'handle_update' ) );
		add_action( 'admin_post_ntaa_geoip_upload', array( __CLASS__, 'handle_upload' ) );
	}

	/**
	 * Storage directory.
	 *
	 * @return string
	 */
	public static function dir() {
		$u = wp_get_upload_dir();
		return trailingslashit( $u['basedir'] ) . 'ntaa-geoip';
	}

	/**
	 * Create the directory with deny rules.
	 */
	private static function ensure_dir() {
		$d = self::dir();
		wp_mkdir_p( $d );
		if ( ! file_exists( $d . '/.htaccess' ) ) {
			file_put_contents( $d . '/.htaccess', "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		if ( ! file_exists( $d . '/index.php' ) ) {
			file_put_contents( $d . '/index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
	}

	/**
	 * Stored state.
	 *
	 * @return array
	 */
	public static function state() {
		$s = get_option( self::OPTION, array() );
		return wp_parse_args(
			is_array( $s ) ? $s : array(),
			array(
				'files'    => array(),
				'source'   => '',
				'type'     => '',
				'built'    => '',
				'updated'  => 0,
				'error'    => '',
				'checked'  => 0,
			)
		);
	}

	/**
	 * Absolute paths of the active database files.
	 *
	 * @return array
	 */
	public static function files() {
		if ( 'off' === NTAA_Settings::get( 'geoip_source' ) ) {
			return array();
		}
		$out = array();
		foreach ( (array) self::state()['files'] as $f ) {
			$p = self::dir() . '/' . basename( $f );
			if ( is_readable( $p ) ) {
				$out[] = $p;
			}
		}
		return $out;
	}

	/**
	 * Look an IP address up (works without WordPress loaded when $files is passed).
	 *
	 * @param string     $ip    IP address.
	 * @param array|null $files Database files (defaults to the active ones).
	 * @return array country, region, city (empty strings when unknown).
	 */
	public static function lookup( $ip, $files = null ) {
		$empty = array(
			'country' => '',
			'region'  => '',
			'city'    => '',
		);
		if ( '' === (string) $ip ) {
			return $empty;
		}
		if ( null === self::$readers ) {
			self::$readers = array();
			foreach ( null === $files ? self::files() : (array) $files as $f ) {
				try {
					self::$readers[] = new NTAA_MMDB( $f );
				} catch ( Exception $e ) {
					continue;
				}
			}
		}
		foreach ( self::$readers as $r ) {
			try {
				$rec = $r->get( $ip );
			} catch ( Exception $e ) {
				continue;
			}
			if ( $rec ) {
				return NTAA_MMDB::location( $rec );
			}
		}
		return $empty;
	}

	/**
	 * Reset cached readers (after an update).
	 */
	public static function reset() {
		if ( self::$readers ) {
			foreach ( self::$readers as $r ) {
				$r->close();
			}
		}
		self::$readers = null;
	}

	/* ---------------------------------------------------------------
	 * Download / update
	 * ------------------------------------------------------------- */

	/**
	 * Candidate download URLs for the chosen source.
	 *
	 * @param string $source Source id.
	 * @return array List of [url, format, headers].
	 */
	private static function urls( $source ) {
		$months = array( gmdate( 'Y-m' ), gmdate( 'Y-m', strtotime( 'first day of last month' ) ) );
		$out    = array();
		if ( 'dbip_country' === $source || 'dbip_city' === $source ) {
			$kind = 'dbip_city' === $source ? 'city' : 'country';
			foreach ( $months as $m ) {
				$out[] = array( 'https://download.db-ip.com/free/dbip-' . $kind . '-lite-' . $m . '.mmdb.gz', 'gz', array() );
			}
		} elseif ( 'maxmind' === $source ) {
			$acc = (string) NTAA_Settings::get( 'maxmind_account', '' );
			$key = NTAA_Settings::decrypt( (string) NTAA_Settings::get( 'maxmind_key', '' ) );
			if ( $acc && $key ) {
				$out[] = array(
					'https://download.maxmind.com/geoip/databases/GeoLite2-City/download?suffix=tar.gz',
					'tgz',
					array( 'Authorization' => 'Basic ' . base64_encode( $acc . ':' . $key ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
				);
			}
		}
		return apply_filters( 'ntaa_geoip_urls', $out, $source );
	}

	/**
	 * Download (or refresh) the database for the chosen source.
	 *
	 * @return true|WP_Error
	 */
	public static function update() {
		$source = (string) NTAA_Settings::get( 'geoip_source', 'dbip_country' );
		if ( in_array( $source, array( 'off', 'upload' ), true ) ) {
			return new WP_Error( 'ntaa_geo_src', __( 'Automatic download is not used for this GeoIP source.', 'nodethemes-analytics-ai' ) );
		}
		$urls = self::urls( $source );
		if ( ! $urls ) {
			$err = __( 'Enter your MaxMind account ID and license key first.', 'nodethemes-analytics-ai' );
			self::save_state( array( 'error' => $err, 'checked' => time() ) );
			return new WP_Error( 'ntaa_geo_key', $err );
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 600 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		self::ensure_dir();
		$last = null;
		foreach ( $urls as $u ) {
			list( $url, $format, $headers ) = $u;
			$tmp = wp_tempnam( 'ntaa-geoip' );
			$res = wp_remote_get(
				$url,
				array(
					'timeout'  => 300,
					'stream'   => true,
					'filename' => $tmp,
					'headers'  => $headers,
				)
			);
			$code = is_wp_error( $res ) ? 0 : (int) wp_remote_retrieve_response_code( $res );
			if ( 200 !== $code ) {
				wp_delete_file( $tmp );
				$last = is_wp_error( $res ) ? $res->get_error_message() : 'HTTP ' . $code;
				continue;
			}
			$result = self::install_file( $tmp, $format, $source );
			wp_delete_file( $tmp );
			if ( true === $result ) {
				return true;
			}
			$last = $result->get_error_message();
		}
		/* translators: %s: error */
		$err = sprintf( __( 'The GeoIP database could not be downloaded: %s', 'nodethemes-analytics-ai' ), (string) $last );
		self::save_state( array( 'error' => $err, 'checked' => time() ) );
		return new WP_Error( 'ntaa_geo_dl', $err );
	}

	/**
	 * Unpack, validate and activate a downloaded or uploaded file.
	 *
	 * @param string $tmp    Temporary file.
	 * @param string $format mmdb | gz | tgz.
	 * @param string $source Source id.
	 * @return true|WP_Error
	 */
	public static function install_file( $tmp, $format, $source ) {
		self::ensure_dir();
		$target = self::dir() . '/' . sanitize_file_name( $source ) . '-' . wp_generate_password( 8, false, false ) . '.mmdb';
		if ( 'gz' === $format ) {
			$in  = gzopen( $tmp, 'rb' );
			$out = $in ? fopen( $target, 'wb' ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions
			if ( ! $in || ! $out ) {
				return new WP_Error( 'ntaa_geo_gz', __( 'The downloaded file could not be unpacked.', 'nodethemes-analytics-ai' ) );
			}
			while ( ! gzeof( $in ) ) {
				fwrite( $out, gzread( $in, 1048576 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			}
			gzclose( $in );
			fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		} elseif ( 'tgz' === $format ) {
			if ( ! class_exists( 'PharData' ) ) {
				return new WP_Error( 'ntaa_geo_phar', __( 'The PHP Phar extension is needed to unpack the MaxMind file.', 'nodethemes-analytics-ai' ) );
			}
			try {
				$work = self::dir() . '/tmp-' . wp_generate_password( 8, false, false );
				wp_mkdir_p( $work );
				$tgz = $work . '/db.tar.gz';
				copy( $tmp, $tgz );
				$p = new PharData( $tgz );
				$p->decompress();
				$tar = new PharData( $work . '/db.tar' );
				$tar->extractTo( $work, null, true );
				$found = '';
				$it    = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $work, FilesystemIterator::SKIP_DOTS ) );
				foreach ( $it as $f ) {
					if ( '.mmdb' === substr( $f->getFilename(), -5 ) ) {
						$found = $f->getPathname();
						break;
					}
				}
				if ( $found ) {
					rename( $found, $target ); // phpcs:ignore WordPress.WP.AlternativeFunctions
				}
				self::rmdir( $work );
				if ( ! $found ) {
					return new WP_Error( 'ntaa_geo_tgz', __( 'No .mmdb file was found in the download.', 'nodethemes-analytics-ai' ) );
				}
			} catch ( Exception $e ) {
				return new WP_Error( 'ntaa_geo_tgz', $e->getMessage() );
			}
		} else {
			if ( ! copy( $tmp, $target ) ) {
				return new WP_Error( 'ntaa_geo_copy', __( 'The file could not be stored. Check folder permissions.', 'nodethemes-analytics-ai' ) );
			}
		}

		// Validate before switching.
		try {
			$r    = new NTAA_MMDB( $target );
			$meta = $r->metadata;
			$test = NTAA_MMDB::location( $r->get( '8.8.8.8' ) );
			$r->close();
		} catch ( Exception $e ) {
			wp_delete_file( $target );
			/* translators: %s: error */
			return new WP_Error( 'ntaa_geo_bad', sprintf( __( 'This is not a valid GeoIP (.mmdb) database: %s', 'nodethemes-analytics-ai' ), $e->getMessage() ) );
		}
		if ( '' === $test['country'] ) {
			wp_delete_file( $target );
			return new WP_Error( 'ntaa_geo_nocountry', __( 'The database does not contain country information.', 'nodethemes-analytics-ai' ) );
		}

		$old = self::state();
		foreach ( (array) $old['files'] as $f ) {
			wp_delete_file( self::dir() . '/' . basename( $f ) );
		}
		self::save_state(
			array(
				'files'   => array( basename( $target ) ),
				'source'  => $source,
				'type'    => isset( $meta['database_type'] ) ? (string) $meta['database_type'] : '',
				'built'   => isset( $meta['build_epoch'] ) ? gmdate( 'Y-m-d', (int) $meta['build_epoch'] ) : '',
				'updated' => time(),
				'checked' => time(),
				'error'   => '',
			)
		);
		self::reset();
		NTAA_Settings::write_runtime();
		return true;
	}

	/**
	 * Merge and save state.
	 *
	 * @param array $data Data.
	 */
	private static function save_state( $data ) {
		update_option( self::OPTION, array_merge( self::state(), $data ), false );
	}

	/**
	 * Remove a directory tree.
	 *
	 * @param string $dir Dir.
	 */
	private static function rmdir( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
		foreach ( $it as $f ) {
			$f->isDir() ? rmdir( $f->getPathname() ) : wp_delete_file( $f->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}

	/**
	 * Monthly refresh (called by cron).
	 */
	public static function maybe_monthly_update() {
		$src = (string) NTAA_Settings::get( 'geoip_source' );
		if ( in_array( $src, array( 'off', 'upload' ), true ) ) {
			return;
		}
		$s = self::state();
		if ( ! self::files() || $s['source'] !== $src || ( time() - (int) $s['updated'] ) > 32 * DAY_IN_SECONDS ) {
			if ( ( time() - (int) $s['checked'] ) > 6 * HOUR_IN_SECONDS ) {
				self::update();
			}
		}
	}

	/* ---------------------------------------------------------------
	 * Admin handlers
	 * ------------------------------------------------------------- */

	/**
	 * "Download now" button.
	 */
	public static function handle_update() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'nodethemes-analytics-ai' ), 403 );
		}
		check_admin_referer( 'ntaa_geoip' );
		$r = self::update();
		set_transient( 'ntaa_geo_msg_' . get_current_user_id(), is_wp_error( $r ) ? array( 'error', $r->get_error_message() ) : array( 'success', __( 'GeoIP database downloaded and activated.', 'nodethemes-analytics-ai' ) ), 120 );
		wp_safe_redirect( admin_url( 'admin.php?page=ntaa-settings#geoip' ) );
		exit;
	}

	/**
	 * Upload a .mmdb / .mmdb.gz file.
	 */
	public static function handle_upload() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'nodethemes-analytics-ai' ), 403 );
		}
		check_admin_referer( 'ntaa_geoip' );
		$f   = isset( $_FILES['ntaa_mmdb'] ) ? $_FILES['ntaa_mmdb'] : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$msg = array( 'error', __( 'The file upload failed.', 'nodethemes-analytics-ai' ) );
		if ( ! empty( $f['tmp_name'] ) && UPLOAD_ERR_OK === (int) $f['error'] && is_uploaded_file( $f['tmp_name'] ) ) {
			$name   = strtolower( (string) $f['name'] );
			$format = substr( $name, -3 ) === '.gz' && substr( $name, -7 ) !== '.tar.gz' ? 'gz' : ( substr( $name, -7 ) === '.tar.gz' ? 'tgz' : 'mmdb' );
			$r      = self::install_file( $f['tmp_name'], $format, 'upload' );
			$msg    = is_wp_error( $r ) ? array( 'error', $r->get_error_message() ) : array( 'success', __( 'GeoIP database uploaded and activated.', 'nodethemes-analytics-ai' ) );
			if ( ! is_wp_error( $r ) ) {
				$s                 = NTAA_Settings::all();
				$s['geoip_source'] = 'upload';
				NTAA_Settings::save( $s );
				NTAA_Settings::write_runtime();
			}
		}
		set_transient( 'ntaa_geo_msg_' . get_current_user_id(), $msg, 120 );
		wp_safe_redirect( admin_url( 'admin.php?page=ntaa-settings#geoip' ) );
		exit;
	}

	/**
	 * Info for the settings page.
	 *
	 * @return array
	 */
	public static function info() {
		$s     = self::state();
		$files = self::files();
		$size  = 0;
		foreach ( $files as $f ) {
			$size += (int) filesize( $f );
		}
		return array(
			'active'  => (bool) $files,
			'source'  => $s['source'],
			'type'    => $s['type'],
			'built'   => $s['built'],
			'updated' => $s['updated'],
			'size'    => $size,
			'error'   => $s['error'],
			'dbip'    => 0 === strpos( (string) $s['source'], 'dbip' ),
		);
	}
}
