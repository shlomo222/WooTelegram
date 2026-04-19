<?php
/**
 * Encrypt / decrypt sensitive option values (e.g. bot token).
 *
 * @package WooTelegram_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AES-256-CBC helpers using AUTH_KEY-derived material.
 */
class WooTG_Crypto {

	/**
	 * Encrypt plaintext; returns base64( IV || ciphertext ).
	 */
	public static function encrypt( string $plaintext ): string {
		if ( $plaintext === '' ) {
			return '';
		}

		$key = self::get_binary_key();
		$iv  = random_bytes( 16 );

		$ciphertext = openssl_encrypt( $plaintext, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );
		if ( false === $ciphertext ) {
			self::log_decrypt_failure( 'encrypt openssl_encrypt failed' );
			return '';
		}

		return base64_encode( $iv . $ciphertext );
	}

	/**
	 * Decrypt payload from encrypt(); on failure returns '' and logs.
	 */
	public static function decrypt( string $encoded ): string {
		if ( $encoded === '' ) {
			return '';
		}

		$binary = base64_decode( $encoded, true );
		if ( false === $binary || strlen( $binary ) < 17 ) {
			self::log_decrypt_failure( 'invalid base64 or too short' );
			return '';
		}

		$iv         = substr( $binary, 0, 16 );
		$ciphertext = substr( $binary, 16 );
		$key        = self::get_binary_key();

		$plain = openssl_decrypt( $ciphertext, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );
		if ( false === $plain ) {
			self::log_decrypt_failure( 'openssl_decrypt failed' );
			return '';
		}

		return $plain;
	}

	/**
	 * 32-byte key for AES-256.
	 */
	private static function get_binary_key(): string {
		$material = self::get_key_material_string();
		return hash( 'sha256', $material, true );
	}

	/**
	 * Prefer AUTH_KEY; fallback to wp_salt when AUTH_KEY is missing or empty.
	 */
	private static function get_key_material_string(): string {
		if ( defined( 'AUTH_KEY' ) && is_string( AUTH_KEY ) && AUTH_KEY !== '' ) {
			return AUTH_KEY;
		}

		return wp_salt( 'wootg_crypto' );
	}

	/**
	 * Log decrypt/encrypt issues without exposing secrets.
	 */
	private static function log_decrypt_failure( string $reason ): void {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( 'WooTG_Crypto: ' . $reason );
	}
}
