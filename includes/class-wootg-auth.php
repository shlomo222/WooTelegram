<?php
/**
 * Authorized Telegram chat IDs (MVP: single site_id).
 *
 * @package WooTelegram_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * DB + object cache for authorized operators.
 */
class WooTG_Auth {

	private const SITE_ID_MVP = 1;

	private const CACHE_GROUP = 'wootg_auth';

	private const CACHE_KEY_ALL = 'all';

	private const VALID_ROLES = array( 'admin', 'editor', 'viewer' );

	/**
	 * Whether this chat is allowed to use the bot (active row).
	 */
	public static function is_authorized( int $chat_id ): bool {
		$cached = self::get_cached_profile( $chat_id );
		if ( is_array( $cached ) ) {
			return ! empty( $cached['authorized'] );
		}

		$row = self::fetch_row_by_chat( $chat_id );
		self::prime_chat_cache( $chat_id, $row );

		return null !== $row && (int) $row['is_active'] === 1;
	}

	/**
	 * Role for an active authorized user, or null.
	 */
	public static function get_role( int $chat_id ): ?string {
		$cached = self::get_cached_profile( $chat_id );
		if ( is_array( $cached ) ) {
			return $cached['authorized'] ? $cached['role'] : null;
		}

		$row = self::fetch_row_by_chat( $chat_id );
		self::prime_chat_cache( $chat_id, $row );

		if ( null === $row || (int) $row['is_active'] !== 1 ) {
			return null;
		}

		$role = isset( $row['role'] ) ? (string) $row['role'] : '';

		return '' !== $role ? $role : null;
	}

	/**
	 * Insert or update an authorized user (active).
	 */
	public static function add( int $chat_id, string $display_name = '', string $role = 'admin' ): bool {
		global $wpdb;

		$table = $wpdb->prefix . 'wootg_authorized_users';

		$role         = self::normalize_role( $role );
		$display_name = sanitize_text_field( $display_name );

		$existing = self::fetch_row_by_chat( $chat_id );

		if ( null !== $existing ) {
			$updated = $wpdb->update(
				$table,
				array(
					'display_name' => $display_name,
					'role'         => $role,
					'is_active'    => 1,
				),
				array(
					'site_id'            => self::SITE_ID_MVP,
					'telegram_chat_id' => $chat_id,
				),
				array( '%s', '%s', '%d' ),
				array( '%d', '%d' )
			);

			$ok = false !== $updated;
		} else {
			$inserted = $wpdb->insert(
				$table,
				array(
					'site_id'            => self::SITE_ID_MVP,
					'telegram_chat_id' => $chat_id,
					'display_name'     => $display_name,
					'role'             => $role,
					'is_active'        => 1,
				),
				array( '%d', '%d', '%s', '%s', '%d' )
			);

			$ok = false !== $inserted;
		}

		if ( $ok ) {
			self::invalidate_chat( $chat_id );
		}

		return $ok;
	}

	/**
	 * Remove authorization row completely.
	 */
	public static function remove( int $chat_id ): bool {
		global $wpdb;

		$table = $wpdb->prefix . 'wootg_authorized_users';

		$deleted = $wpdb->delete(
			$table,
			array(
				'site_id'            => self::SITE_ID_MVP,
				'telegram_chat_id' => $chat_id,
			),
			array( '%d', '%d' )
		);

		if ( false !== $deleted && $deleted > 0 ) {
			self::invalidate_chat( $chat_id );
			return true;
		}

		return false;
	}

	/**
	 * All active authorized users for this site (MVP).
	 *
	 * @return list<array<string, mixed>>
	 */
	public static function get_all(): array {
		$cached = wp_cache_get( self::CACHE_KEY_ALL, self::CACHE_GROUP );
		if ( false !== $cached && is_array( $cached ) ) {
			return $cached;
		}

		global $wpdb;

		$table = $wpdb->prefix . 'wootg_authorized_users';

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM `{$table}` WHERE site_id = %d AND is_active = 1 ORDER BY created_at ASC",
				self::SITE_ID_MVP
			),
			ARRAY_A
		);

		$list = is_array( $rows ) ? array_values( $rows ) : array();

		wp_cache_set( self::CACHE_KEY_ALL, $list, self::CACHE_GROUP );

		return $list;
	}

	/**
	 * @return array{authorized: bool, role: ?string}|false
	 */
	private static function get_cached_profile( int $chat_id ) {
		$key = self::cache_key_chat( $chat_id );
		$val = wp_cache_get( $key, self::CACHE_GROUP );

		return false !== $val && is_array( $val ) ? $val : false;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private static function fetch_row_by_chat( int $chat_id ): ?array {
		global $wpdb;

		$table = $wpdb->prefix . 'wootg_authorized_users';

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
	 * @param array<string, mixed>|null $row
	 */
	private static function prime_chat_cache( int $chat_id, ?array $row ): void {
		$key = self::cache_key_chat( $chat_id );

		if ( null === $row ) {
			wp_cache_set(
				$key,
				array(
					'authorized' => false,
					'role'       => null,
				),
				self::CACHE_GROUP
			);
			return;
		}

		$active = (int) $row['is_active'] === 1;
		$role   = ( $active && isset( $row['role'] ) ) ? (string) $row['role'] : null;

		wp_cache_set(
			$key,
			array(
				'authorized' => $active,
				'role'       => $role,
			),
			self::CACHE_GROUP
		);
	}

	private static function invalidate_chat( int $chat_id ): void {
		wp_cache_delete( self::cache_key_chat( $chat_id ), self::CACHE_GROUP );
		wp_cache_delete( self::CACHE_KEY_ALL, self::CACHE_GROUP );
	}

	private static function cache_key_chat( int $chat_id ): string {
		return 'chat_' . $chat_id;
	}

	private static function normalize_role( string $role ): string {
		$role = sanitize_text_field( $role );

		return in_array( $role, self::VALID_ROLES, true ) ? $role : 'admin';
	}
}
