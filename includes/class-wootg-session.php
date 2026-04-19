<?php
/**
 * Per-chat Telegram flow state (DB-backed JSON).
 *
 * @package WooTelegram_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Session rows in wootg_sessions.
 */
class WooTG_Session {

	private const SITE_ID_MVP = 1;

	/**
	 * Current session snapshot or null.
	 *
	 * @return array{current_flow: string, current_step: string, session_data: array<string, mixed>}|null
	 */
	public static function get( int $chat_id ): ?array {
		$row = self::fetch_row( $chat_id );
		if ( null === $row ) {
			return null;
		}

		$data = self::decode_session_data( isset( $row['session_data'] ) ? (string) $row['session_data'] : '' );

		return array(
			'current_flow' => isset( $row['current_flow'] ) ? (string) $row['current_flow'] : '',
			'current_step' => isset( $row['current_step'] ) ? (string) $row['current_step'] : '',
			'session_data' => $data,
		);
	}

	/**
	 * Create or replace session state for a chat.
	 */
	public static function start( int $chat_id, int $user_id, string $flow, string $step = 'init', array $data = array() ): bool {
		global $wpdb;

		$table   = $wpdb->prefix . 'wootg_sessions';
		$payload = self::encode_session_data( $data );
		$now     = current_time( 'mysql' );

		$flow = self::truncate( sanitize_text_field( $flow ), 100 );
		$step = self::truncate( sanitize_text_field( $step ), 100 );

		$existing = self::fetch_row( $chat_id );

		if ( null !== $existing ) {
			$updated = $wpdb->update(
				$table,
				array(
					'telegram_user_id' => $user_id,
					'current_flow'       => $flow,
					'current_step'       => $step,
					'session_data'       => $payload,
					'updated_at'         => $now,
				),
				array(
					'site_id'            => self::SITE_ID_MVP,
					'telegram_chat_id' => $chat_id,
				),
				array( '%d', '%s', '%s', '%s', '%s' ),
				array( '%d', '%d' )
			);

			return false !== $updated;
		}

		$inserted = $wpdb->insert(
			$table,
			array(
				'site_id'            => self::SITE_ID_MVP,
				'telegram_chat_id'   => $chat_id,
				'telegram_user_id'   => $user_id,
				'current_flow'       => $flow,
				'current_step'       => $step,
				'session_data'       => $payload,
				'created_at'         => $now,
				'updated_at'         => $now,
			),
			array( '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s' )
		);

		return false !== $inserted;
	}

	/**
	 * Update step and merge into session_data (requires existing row).
	 */
	public static function update_step( int $chat_id, string $step, array $merge_data = array() ): bool {
		$session = self::get( $chat_id );
		if ( null === $session ) {
			return false;
		}

		$data = isset( $session['session_data'] ) && is_array( $session['session_data'] )
			? $session['session_data']
			: array();

		$data = array_merge( $data, $merge_data );

		global $wpdb;

		$table = $wpdb->prefix . 'wootg_sessions';

		$updated = $wpdb->update(
			$table,
			array(
				'current_step' => self::truncate( sanitize_text_field( $step ), 100 ),
				'session_data' => self::encode_session_data( $data ),
				'updated_at'   => current_time( 'mysql' ),
			),
			array(
				'site_id'            => self::SITE_ID_MVP,
				'telegram_chat_id' => $chat_id,
			),
			array( '%s', '%s', '%s' ),
			array( '%d', '%d' )
		);

		return false !== $updated;
	}

	/**
	 * Set a single key in session_data (requires existing row).
	 *
	 * @param mixed $value Arbitrary JSON-serializable value.
	 */
	public static function set_data( int $chat_id, string $key, $value ): bool {
		$key = sanitize_text_field( $key );
		if ( '' === $key ) {
			return false;
		}

		$session = self::get( $chat_id );
		if ( null === $session ) {
			return false;
		}

		$data = isset( $session['session_data'] ) && is_array( $session['session_data'] )
			? $session['session_data']
			: array();

		$data[ $key ] = $value;

		global $wpdb;

		$table = $wpdb->prefix . 'wootg_sessions';

		$updated = $wpdb->update(
			$table,
			array(
				'session_data' => self::encode_session_data( $data ),
				'updated_at'   => current_time( 'mysql' ),
			),
			array(
				'site_id'            => self::SITE_ID_MVP,
				'telegram_chat_id' => $chat_id,
			),
			array( '%s', '%s' ),
			array( '%d', '%d' )
		);

		return false !== $updated;
	}

	/**
	 * Read one key from session_data.
	 *
	 * @return mixed
	 */
	public static function get_data( int $chat_id, string $key, $default = null ) {
		$session = self::get( $chat_id );
		if ( null === $session || ! isset( $session['session_data'][ $key ] ) ) {
			return $default;
		}

		return $session['session_data'][ $key ];
	}

	/**
	 * Delete session row for a chat.
	 */
	public static function end( int $chat_id ): bool {
		global $wpdb;

		$table = $wpdb->prefix . 'wootg_sessions';

		$deleted = $wpdb->delete(
			$table,
			array(
				'site_id'            => self::SITE_ID_MVP,
				'telegram_chat_id' => $chat_id,
			),
			array( '%d', '%d' )
		);

		return false !== $deleted && $deleted > 0;
	}

	/**
	 * Remove stale sessions (by updated_at).
	 */
	public static function cleanup_stale( int $hours = 1 ): int {
		global $wpdb;

		$hours = max( 1, absint( $hours ) );

		$table = $wpdb->prefix . 'wootg_sessions';

		$sql = $wpdb->prepare(
			"DELETE FROM `{$table}` WHERE site_id = %d AND updated_at < ( UTC_TIMESTAMP() - INTERVAL %d HOUR )",
			self::SITE_ID_MVP,
			$hours
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql built with prepare above.
		$wpdb->query( $sql );

		return (int) $wpdb->rows_affected;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private static function fetch_row( int $chat_id ): ?array {
		global $wpdb;

		$table = $wpdb->prefix . 'wootg_sessions';

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM `{$table}` WHERE site_id = %d AND telegram_chat_id = %d LIMIT 1",
				self::SITE_ID_MVP,
				$chat_id
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function decode_session_data( string $json ): array {
		if ( '' === trim( $json ) ) {
			return array();
		}

		$data = json_decode( $json, true );

		return is_array( $data ) ? $data : array();
	}

	/**
	 * @param array<string, mixed> $data
	 */
	private static function encode_session_data( array $data ): string {
		$payload = wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE );

		return false === $payload ? '{}' : $payload;
	}

	private static function truncate( string $text, int $max ): string {
		if ( strlen( $text ) <= $max ) {
			return $text;
		}

		return substr( $text, 0, $max );
	}
}
