<?php
/**
 * Activity log (DB + optional error_log when debugging).
 *
 * @package WooTelegram_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Persists events to wootg_activity_log.
 */
class WooTG_Logger {

	private const SITE_ID_MVP = 1;

	/**
	 * Log a successful or informational event.
	 *
	 * @param array<string, mixed> $details Context payload (stored as JSON).
	 */
	public static function log(
		string $action,
		array $details = array(),
		?int $chat_id = null,
		?int $wc_object_id = null,
		string $status = 'success'
	): void {
		global $wpdb;

		$table = $wpdb->prefix . 'wootg_activity_log';

		$payload = wp_json_encode( $details, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE );
		if ( false === $payload ) {
			$payload = '{}';
		}

		$wpdb->insert(
			$table,
			array(
				'site_id'          => self::SITE_ID_MVP,
				'telegram_chat_id' => $chat_id,
				'action'           => self::truncate( $action, 100 ),
				'details'          => $payload,
				'wc_object_id'     => $wc_object_id,
				'status'           => self::truncate( $status, 20 ),
				'error_message'    => null,
			),
			array(
				'%d',
				'%d',
				'%s',
				'%s',
				'%d',
				'%s',
				'%s',
			)
		);
	}

	/**
	 * Log an error (status error + error_message column).
	 *
	 * @param array<string, mixed> $details Extra context (stored as JSON).
	 */
	public static function log_error(
		string $action,
		string $error_message,
		array $details = array(),
		?int $chat_id = null
	): void {
		global $wpdb;

		$table = $wpdb->prefix . 'wootg_activity_log';

		$payload = wp_json_encode( $details, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE );
		if ( false === $payload ) {
			$payload = '{}';
		}

		$wpdb->insert(
			$table,
			array(
				'site_id'          => self::SITE_ID_MVP,
				'telegram_chat_id' => $chat_id,
				'action'           => self::truncate( $action, 100 ),
				'details'          => $payload,
				'wc_object_id'     => null,
				'status'           => 'error',
				'error_message'    => $error_message,
			),
			array(
				'%d',
				'%d',
				'%s',
				'%s',
				'%d',
				'%s',
				'%s',
			)
		);

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log(
				'WooTG_Logger::log_error ' . self::truncate( $action, 100 ) . ' ' . $error_message . ' ' . $payload
			);
		}
	}

	/**
	 * Recent log rows (for admin / diagnostics).
	 *
	 * @return list<array<string, mixed>>
	 */
	public static function get_recent( int $limit = 50, ?int $chat_id = null ): array {
		global $wpdb;

		$table = $wpdb->prefix . 'wootg_activity_log';
		$limit = max( 1, min( 500, absint( $limit ) ) );

		if ( null === $chat_id ) {
			$sql = $wpdb->prepare(
				"SELECT * FROM `{$table}` WHERE site_id = %d ORDER BY created_at DESC LIMIT %d",
				self::SITE_ID_MVP,
				$limit
			);
		} else {
			$sql = $wpdb->prepare(
				"SELECT * FROM `{$table}` WHERE site_id = %d AND telegram_chat_id = %d ORDER BY created_at DESC LIMIT %d",
				self::SITE_ID_MVP,
				$chat_id,
				$limit
			);
		}

		$rows = $wpdb->get_results( $sql, ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * @param int $max Max byte length (VARCHAR limits).
	 */
	private static function truncate( string $text, int $max ): string {
		if ( strlen( $text ) <= $max ) {
			return $text;
		}

		return substr( $text, 0, $max );
	}
}
