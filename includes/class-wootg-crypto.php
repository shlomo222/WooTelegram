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
 * Fallback: if OpenSSL is unavailable, stores base64 with "plain:" prefix.
 */
class WooTG_Crypto {

	const PLAIN_PREFIX = 'plain:';

	/**
	 * Encrypt plaintext; returns base64( IV || ciphertext ).
	 * Falls back to prefixed base64 when OpenSSL is unavailable.
	 */
	public static function encrypt( string $plaintext ): string {
		if ( $plaintext === '' ) {
			return '';
		}

		if ( ! extension_loaded( 'openssl' ) ) {
			self::maybe_show_openssl_notice();
			return self::PLAIN_PREFIX . base64_encode( $plaintext );
		}

		$key = self::get_binary_key();
		$iv  = random_bytes( 16 );

		$ciphertext = openssl_encrypt( $plaintext, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );
		if ( false === $ciphertext ) {
			self::log_error( 'openssl_encrypt failed — storing as plain fallback' );
			self::maybe_show_openssl_notice();
			return self::PLAIN_PREFIX . base64_encode( $plaintext );
		}

		return base64_encode( $iv . $ciphertext );
	}

	/**
	 * Decrypt payload from encrypt(); understands "plain:" fallback prefix.
	 * Returns '' only on genuine failure.
	 */
	public static function decrypt( string $encoded ): string {
		if ( $encoded === '' ) {
			return '';
		}

		if ( str_starts_with( $encoded, self::PLAIN_PREFIX ) ) {
			$decoded = base64_decode( substr( $encoded, strlen( self::PLAIN_PREFIX ) ), true );
			return ( false !== $decoded ) ? $decoded : '';
		}

		$binary = base64_decode( $encoded, true );
		if ( false === $binary || strlen( $binary ) < 17 ) {
			self::log_error( 'invalid base64 or too short' );
			return '';
		}

		$iv         = substr( $binary, 0, 16 );
		$ciphertext = substr( $binary, 16 );
		$key        = self::get_binary_key();

		$plain = openssl_decrypt( $ciphertext, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );
		if ( false === $plain ) {
			self::log_error( 'openssl_decrypt failed' );
			return '';
		}

		return $plain;
	}

	/**
	 * 32-byte key for AES-256.
	 */
	private static function get_binary_key(): string {
		return hash( 'sha256', self::get_key_material_string(), true );
	}

	/**
	 * Prefer AUTH_KEY; fallback to wp_salt('auth') which always exists.
	 */
	private static function get_key_material_string(): string {
		if ( defined( 'AUTH_KEY' ) && is_string( AUTH_KEY ) && AUTH_KEY !== '' ) {
			return AUTH_KEY;
		}

		return wp_salt( 'auth' );
	}

	/**
	 * Show a one-time admin notice that OpenSSL is unavailable.
	 */
	private static function maybe_show_openssl_notice(): void {
		add_action(
			'admin_notices',
			static function () {
				echo '<div class="notice notice-warning"><p>' .
					esc_html__( 'WooTelegram Manager: תוסף OpenSSL לא זמין בשרת. הטוקן נשמר ללא הצפנה — מומלץ להפעיל OpenSSL.', 'woo-telegram-manager' ) .
					'</p></div>';
			}
		);
	}

	/**
	 * Log errors via WooTG_Logger when available, otherwise error_log.
	 */
	private static function log_error( string $reason ): void {
		if ( class_exists( 'WooTG_Logger' ) ) {
			WooTG_Logger::log( 'crypto', 'error', $reason );
		} else {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( 'WooTG_Crypto: ' . $reason );
		}
	}
}
