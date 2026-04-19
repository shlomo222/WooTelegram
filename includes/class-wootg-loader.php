<?php
/**
 * Bootstrap: text domain, webhook, cron, admin UI.
 *
 * @package WooTelegram_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Main loader.
 */
class WooTG_Loader {

	/**
	 * Initialize plugin after translations load.
	 */
	public static function init(): void {
		load_plugin_textdomain(
			'woo-telegram-manager',
			false,
			dirname( WOOTG_BASENAME ) . '/languages'
		);

		WooTG_Webhook::init();

		if ( ! wp_next_scheduled( 'wootg_cleanup_sessions' ) ) {
			wp_schedule_event( time(), 'hourly', 'wootg_cleanup_sessions' );
		}

		add_action( 'wootg_cleanup_sessions', array( WooTG_Session::class, 'cleanup_stale' ) );

		if ( is_admin() ) {
			WooTG_Settings::init();
		}
	}
}
