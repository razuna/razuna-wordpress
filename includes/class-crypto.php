<?php
/**
 * At-rest encryption for stored OAuth tokens.
 *
 * Tokens are sensitive: a Razuna access/refresh token grants API access to the
 * connected user's assets. We encrypt them in the options table with a key
 * derived from the site's WordPress salts so a bare DB dump does not leak them.
 *
 * @package Razuna
 */

namespace Razuna;

defined( 'ABSPATH' ) || exit;

final class Crypto {

	/**
	 * Encrypt a string. Returns a base64 payload prefixed with the cipher used,
	 * or the plaintext prefixed with "plain:" if no crypto backend is available
	 * (so the system degrades gracefully rather than fatally).
	 */
	public static function encrypt( string $plaintext ): string {
		$key = self::key();

		if ( function_exists( 'sodium_crypto_secretbox' ) ) {
			$nonce  = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$cipher = sodium_crypto_secretbox( $plaintext, $nonce, $key );
			return 'sodium:' . base64_encode( $nonce . $cipher );
		}

		if ( function_exists( 'openssl_encrypt' ) ) {
			$iv     = random_bytes( 16 );
			$cipher = openssl_encrypt( $plaintext, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );
			if ( false !== $cipher ) {
				return 'openssl:' . base64_encode( $iv . $cipher );
			}
		}

		return 'plain:' . base64_encode( $plaintext );
	}

	/**
	 * Decrypt a payload produced by encrypt(). Returns '' on failure.
	 */
	public static function decrypt( string $payload ): string {
		$pos = strpos( $payload, ':' );
		if ( false === $pos ) {
			return '';
		}
		$scheme = substr( $payload, 0, $pos );
		$raw    = base64_decode( substr( $payload, $pos + 1 ), true );
		if ( false === $raw ) {
			return '';
		}
		$key = self::key();

		if ( 'sodium' === $scheme && function_exists( 'sodium_crypto_secretbox_open' ) ) {
			$nonce  = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$cipher = substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$plain  = sodium_crypto_secretbox_open( $cipher, $nonce, $key );
			return false === $plain ? '' : $plain;
		}

		if ( 'openssl' === $scheme && function_exists( 'openssl_decrypt' ) ) {
			$iv     = substr( $raw, 0, 16 );
			$cipher = substr( $raw, 16 );
			$plain  = openssl_decrypt( $cipher, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );
			return false === $plain ? '' : $plain;
		}

		if ( 'plain' === $scheme ) {
			return $raw;
		}

		return '';
	}

	/**
	 * 32-byte key derived from WordPress auth salts.
	 */
	private static function key(): string {
		$material = ( defined( 'AUTH_KEY' ) ? AUTH_KEY : '' )
			. ( defined( 'SECURE_AUTH_KEY' ) ? SECURE_AUTH_KEY : '' )
			. ( defined( 'AUTH_SALT' ) ? AUTH_SALT : '' );
		if ( '' === $material ) {
			// Last-resort fallback; still site-specific.
			$material = wp_salt( 'auth' );
		}
		return hash( 'sha256', 'razuna|' . $material, true );
	}
}
