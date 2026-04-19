<?php
/**
 * Bootstrap (stubs until Phase 1 §5).
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

		if ( is_admin() ) {
			WooTG_Settings::init();
		}
	}
}
