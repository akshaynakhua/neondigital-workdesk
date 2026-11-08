<?php
/**
 * Web Push protocol: VAPID (RFC 8292, ES256) and message encryption (RFC 8291, aes128gcm).
 *
 * Uses only PHP's OpenSSL and hash functions, so the plugin needs no Composer packages and no paid
 * push service. Keys are P-256, passed as base64url of their raw bytes (the format every Web Push tool
 * prints). Nothing here stores or logs a key.
 */

defined( 'ABSPATH' ) || exit;

class NDWD_WebPush {

	/** DER prefix of a P-256 public key; the 65 raw bytes follow it. */
	const SPKI_P256 = '3059301306072a8648ce3d020106082a8648ce3d030107034200';
	const RECORD_SIZE = 4096;

	public static function available() {
		return extension_loaded( 'openssl' ) && function_exists( 'openssl_pkey_derive' ) && function_exists( 'hash_hkdf' ) && in_array( 'aes-128-gcm', array_map( 'strtolower', openssl_get_cipher_methods() ), true );
	}

	public static function b64u_encode( $raw ) {
		return rtrim( strtr( base64_encode( $raw ), '+/', '-_' ), '=' ); // phpcs:ignore
	}

	/** @return string|false Raw bytes, or false when the text is not base64url. */
	public static function b64u_decode( $text ) {
		if ( ! is_string( $text ) || ! preg_match( '/^[A-Za-z0-9_-]+={0,2}$/', $text ) ) {
			return false;
		}
		return base64_decode( strtr( rtrim( $text, '=' ), '-_', '+/' ), true ); // phpcs:ignore
	}

	private static function clear_errors() {
		while ( openssl_error_string() ) {
			continue;
		}
	}

	/** New P-256 key. Some PHP builds (Windows) have no default OpenSSL config and need a minimal one. */
	private static function new_key() {
		$opts = array( 'private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1', 'private_key_bits' => 2048 );
		$key  = @openssl_pkey_new( $opts ); // phpcs:ignore
		if ( ! $key ) {
			$cnf = tempnam( sys_get_temp_dir(), 'ndwd' );
			if ( $cnf ) {
				file_put_contents( $cnf, "[req]\ndistinguished_name=dn\n[dn]\n" ); // phpcs:ignore
				$key = @openssl_pkey_new( $opts + array( 'config' => $cnf ) ); // phpcs:ignore
				unlink( $cnf ); // phpcs:ignore
			}
		}
		self::clear_errors();
		if ( ! $key ) {
			throw new RuntimeException( 'OpenSSL could not create a P-256 key.' );
		}
		return $key;
	}

	/** @return array{0:string,1:string} Raw private scalar (32 bytes) and raw public point (65 bytes). */
	private static function raw_parts( $key ) {
		$d = openssl_pkey_get_details( $key );
		return array(
			str_pad( $d['ec']['d'], 32, "\0", STR_PAD_LEFT ),
			"\x04" . str_pad( $d['ec']['x'], 32, "\0", STR_PAD_LEFT ) . str_pad( $d['ec']['y'], 32, "\0", STR_PAD_LEFT ),
		);
	}

	/** @return array{public:string,private:string} A new VAPID key pair, base64url. */
	public static function generate_keys() {
		list( $d, $pub ) = self::raw_parts( self::new_key() );
		return array( 'public' => self::b64u_encode( $pub ), 'private' => self::b64u_encode( $d ) );
	}

	private static function public_pem( $raw ) {
		return "-----BEGIN PUBLIC KEY-----\n" . chunk_split( base64_encode( hex2bin( self::SPKI_P256 ) . $raw ), 64, "\n" ) . "-----END PUBLIC KEY-----\n"; // phpcs:ignore
	}

	private static function private_pem( $d, $pub ) {
		$der = hex2bin( '30770201010420' ) . $d . hex2bin( 'a00a06082a8648ce3d030107a144034200' ) . $pub;
		return "-----BEGIN EC PRIVATE KEY-----\n" . chunk_split( base64_encode( $der ), 64, "\n" ) . "-----END EC PRIVATE KEY-----\n"; // phpcs:ignore
	}

	/** True when $raw is an uncompressed point actually on P-256. */
	public static function valid_public( $raw ) {
		if ( ! is_string( $raw ) || 65 !== strlen( $raw ) || "\x04" !== $raw[0] ) {
			return false;
		}
		$ok = (bool) @openssl_pkey_get_public( self::public_pem( $raw ) ); // phpcs:ignore
		self::clear_errors();
		return $ok;
	}

	/** True when the base64url pair really is one P-256 key pair. */
	public static function valid_pair( $public, $private ) {
		$pub = self::b64u_decode( $public );
		$d   = self::b64u_decode( $private );
		if ( ! $pub || ! $d || 32 !== strlen( $d ) || ! self::valid_public( $pub ) ) {
			return false;
		}
		$key = @openssl_pkey_get_private( self::private_pem( $d, $pub ) ); // phpcs:ignore
		$ok  = $key && hash_equals( $pub, self::raw_parts( $key )[1] );
		self::clear_errors();
		return $ok;
	}

	/** One DER INTEGER of an ECDSA signature as exactly 32 bytes. */
	private static function der_int( $der, &$pos ) {
		$len  = ord( $der[ $pos + 1 ] );
		$int  = substr( $der, $pos + 2, $len );
		$pos += 2 + $len;
		return str_pad( ltrim( $int, "\0" ), 32, "\0", STR_PAD_LEFT );
	}

	/**
	 * The Authorization header value for one push service.
	 *
	 * @param string $endpoint The subscription URL; only its origin goes in the token.
	 * @param string $subject  mailto: or https: contact for the push service.
	 */
	public static function vapid_header( $endpoint, $public, $private, $subject, $now = null ) {
		$p      = wp_parse_url( $endpoint );
		$aud    = $p['scheme'] . '://' . $p['host'] . ( isset( $p['port'] ) ? ':' . $p['port'] : '' );
		$claims = array( 'aud' => $aud, 'exp' => ( null === $now ? time() : $now ) + 12 * HOUR_IN_SECONDS, 'sub' => $subject );
		$input  = self::b64u_encode( '{"typ":"JWT","alg":"ES256"}' ) . '.' . self::b64u_encode( wp_json_encode( $claims, JSON_UNESCAPED_SLASHES ) );
		$pem    = self::private_pem( self::b64u_decode( $private ), self::b64u_decode( $public ) );
		$der    = '';
		if ( ! openssl_sign( $input, $der, $pem, OPENSSL_ALGO_SHA256 ) ) {
			throw new RuntimeException( 'Could not sign the VAPID token.' );
		}
		$pos = 2; // Short-form SEQUENCE: an ES256 signature is at most 72 bytes.
		$r   = self::der_int( $der, $pos );
		$s   = self::der_int( $der, $pos );
		return 'vapid t=' . $input . '.' . self::b64u_encode( $r . $s ) . ', k=' . $public;
	}

	/**
	 * Encrypts one message for one subscription (RFC 8291). A fresh key and salt are used per message;
	 * the two optional arguments exist only for checking the RFC's published test vector.
	 *
	 * @param string     $plain    Message text.
	 * @param string     $ua_pub   Raw p256dh of the subscription.
	 * @param string     $auth     Raw auth secret of the subscription.
	 * @param array|null $eph      Fixed [raw private, raw public] application-server key.
	 * @param string     $salt     Fixed 16-byte salt.
	 * @return string The aes128gcm body.
	 */
	public static function encrypt( $plain, $ua_pub, $auth, $eph = null, $salt = null ) {
		if ( $eph ) {
			list( $d, $as_pub ) = $eph;
			$as_key             = openssl_pkey_get_private( self::private_pem( $d, $as_pub ) );
		} else {
			$as_key           = self::new_key();
			list( , $as_pub ) = self::raw_parts( $as_key );
		}
		$salt = $salt ? $salt : random_bytes( 16 );
		$ecdh = openssl_pkey_derive( openssl_pkey_get_public( self::public_pem( $ua_pub ) ), $as_key, 32 );
		if ( false === $ecdh ) {
			throw new RuntimeException( 'Could not derive the shared secret.' );
		}
		$ikm    = hash_hkdf( 'sha256', $ecdh, 32, "WebPush: info\0" . $ua_pub . $as_pub, $auth );
		$cek    = hash_hkdf( 'sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt );
		$nonce  = hash_hkdf( 'sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt );
		$tag    = '';
		$cipher = openssl_encrypt( $plain . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16 );
		if ( false === $cipher ) {
			throw new RuntimeException( 'Could not encrypt the message.' );
		}
		return $salt . pack( 'N', self::RECORD_SIZE ) . chr( strlen( $as_pub ) ) . $as_pub . $cipher . $tag;
	}
}
