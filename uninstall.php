<?php
/**
 * Uninstall WooTelegram Manager.
 *
 * @package WooTelegram_Manager
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

$prefix = $wpdb->prefix;

// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names are fixed, prefix escaped.
$wpdb->query( "DROP TABLE IF EXISTS `{$prefix}wootg_sessions`" );
// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$wpdb->query( "DROP TABLE IF EXISTS `{$prefix}wootg_authorized_users`" );
// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$wpdb->query( "DROP TABLE IF EXISTS `{$prefix}wootg_activity_log`" );

delete_option( 'wootg_settings' );
delete_option( 'wootg_db_version' );
delete_option( 'wootg_webhook_secret' );
