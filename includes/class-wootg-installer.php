<?php
/**
 * Installation and deactivation.
 *
 * @package WooTelegram_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin installer.
 */
class WooTG_Installer {

	/**
	 * Run on plugin activation.
	 */
	public static function install(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$prefix          = $wpdb->prefix;

		$sql_sessions = "CREATE TABLE {$prefix}wootg_sessions (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			site_id bigint(20) unsigned NOT NULL DEFAULT 1,
			telegram_chat_id bigint(20) NOT NULL,
			telegram_user_id bigint(20) NOT NULL DEFAULT 0,
			current_flow varchar(100) NOT NULL DEFAULT '',
			current_step varchar(100) NOT NULL DEFAULT '',
			session_data longtext NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY unique_chat (site_id, telegram_chat_id)
		) ENGINE=InnoDB {$charset_collate};";

		$sql_users = "CREATE TABLE {$prefix}wootg_authorized_users (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			site_id bigint(20) unsigned NOT NULL DEFAULT 1,
			telegram_chat_id bigint(20) NOT NULL,
			display_name varchar(255) NOT NULL DEFAULT '',
			role varchar(20) NOT NULL DEFAULT 'admin',
			is_active tinyint(1) NOT NULL DEFAULT 1,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY unique_user (site_id, telegram_chat_id)
		) ENGINE=InnoDB {$charset_collate};";

		$sql_log = "CREATE TABLE {$prefix}wootg_activity_log (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			site_id bigint(20) unsigned NOT NULL DEFAULT 1,
			telegram_chat_id bigint(20) NULL,
			action varchar(100) NOT NULL,
			details longtext NULL,
			wc_object_id bigint(20) NULL,
			status varchar(20) NOT NULL DEFAULT 'success',
			error_message text NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY idx_chat (telegram_chat_id),
			KEY idx_action (action),
			KEY idx_created (created_at)
		) ENGINE=InnoDB {$charset_collate};";

		dbDelta( $sql_sessions );
		dbDelta( $sql_users );
		dbDelta( $sql_log );

		update_option( 'wootg_db_version', '1.0.0' );

		if ( false === get_option( 'wootg_webhook_secret', false ) ) {
			update_option( 'wootg_webhook_secret', wp_generate_password( 32, false, false ) );
		}

		$defaults = array(
			'bot_token'              => '',
			'authorized_chat_ids'    => array(),
			'default_product_status' => 'publish',
			'default_stock_status' => 'instock',
			'default_manage_stock'   => true,
			'ai_provider'            => null,
			'ai_api_key'             => null,
		);

		add_option( 'wootg_settings', $defaults, '', 'no' );
	}

	/**
	 * Run on plugin deactivation.
	 */
	public static function deactivate(): void {
		$token = self::get_plain_bot_token_for_api();
		if ( $token === '' ) {
			return;
		}

		$url = 'https://api.telegram.org/bot' . rawurlencode( $token ) . '/deleteWebhook';

		wp_remote_post(
			$url,
			array(
				'timeout' => 15,
				'headers' => array(
					'Content-Type' => 'application/json',
				),
				'body'    => wp_json_encode( array() ),
			)
		);
	}

	/**
	 * Resolve bot token for outbound Telegram calls (plain or decrypted).
	 */
	private static function get_plain_bot_token_for_api(): string {
		$settings = get_option( 'wootg_settings', array() );
		if ( ! is_array( $settings ) || ! isset( $settings['bot_token'] ) ) {
			return '';
		}

		$raw = trim( (string) $settings['bot_token'] );
		if ( $raw === '' ) {
			return '';
		}

		if ( class_exists( 'WooTG_Crypto' ) ) {
			$decrypted = WooTG_Crypto::decrypt( $raw );
			if ( $decrypted !== '' ) {
				return $decrypted;
			}
		}

		return self::is_plausible_telegram_bot_token( $raw ) ? $raw : '';
	}

	/**
	 * Loose format check for legacy plaintext tokens when decrypt is unavailable or fails.
	 */
	private static function is_plausible_telegram_bot_token( string $token ): bool {
		return 1 === preg_match( '/^[0-9]{6,}:[A-Za-z0-9_-]{30,}$/', $token );
	}
}
