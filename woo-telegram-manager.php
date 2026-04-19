<?php
/**
 * Plugin Name: WooTelegram Manager
 * Description: ניהול חנות WooCommerce דרך טלגרם
 * Version: 1.0.0
 * Author: Shlomi
 * Text Domain: woo-telegram-manager
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * WC requires at least: 8.0
 * WC tested up to: 9.0
 * GitHub Plugin URI: shlomo222/WooTelegram
 * Primary Branch: main
 *
 * @package WooTelegram_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WOOTG_VERSION', '1.0.0' );
define( 'WOOTG_FILE', __FILE__ );
define( 'WOOTG_PATH', plugin_dir_path( __FILE__ ) );
define( 'WOOTG_URL', plugin_dir_url( __FILE__ ) );
define( 'WOOTG_BASENAME', plugin_basename( __FILE__ ) );

if ( ! function_exists( 'is_plugin_active' ) ) {
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
}

$wootg_wc_active = is_plugin_active( 'woocommerce/woocommerce.php' );
if ( is_multisite() ) {
	$wootg_wc_active = $wootg_wc_active || is_plugin_active_for_network( 'woocommerce/woocommerce.php' );
}

if ( ! $wootg_wc_active ) {
	add_action(
		'admin_notices',
		static function (): void {
			echo '<div class="notice notice-error"><p>';
			echo esc_html__( 'WooTelegram Manager דורש את WooCommerce להיות מותקן ופעיל.', 'woo-telegram-manager' );
			echo '</p></div>';
		}
	);

	add_action(
		'admin_init',
		static function (): void {
			deactivate_plugins( plugin_basename( WOOTG_FILE ) );
		}
	);

	return;
}

/**
 * Autoload WooTG_* classes from includes/ and includes/flows/.
 *
 * @param class-string $class Class name.
 */
function wootg_autoload( string $class ): void {
	if ( strncmp( $class, 'WooTG_', 6 ) !== 0 ) {
		return;
	}

	$short = substr( $class, 6 );
	if ( $short === '' ) {
		return;
	}

	// WooTG_Flow_MainMenu → flow-main-menu (split _ then split CamelCase).
	$slug = strtolower( preg_replace( '/(?<=[a-z0-9])([A-Z])/', '-$1', str_replace( '_', '-', $short ) ) );
	$file = 'class-wootg-' . $slug . '.php';

	$candidates = array(
		WOOTG_PATH . 'includes/' . $file,
		WOOTG_PATH . 'includes/flows/' . $file,
	);

	foreach ( $candidates as $path ) {
		if ( is_readable( $path ) ) {
			require_once $path;
			return;
		}
	}
}

spl_autoload_register( 'wootg_autoload' );

register_activation_hook( __FILE__, array( 'WooTG_Installer', 'install' ) );
register_deactivation_hook( __FILE__, array( 'WooTG_Installer', 'deactivate' ) );
add_action( 'plugins_loaded', array( 'WooTG_Loader', 'init' ) );
