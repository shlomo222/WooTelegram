<?php
/**
 * Telegram webhook REST endpoint.
 *
 * @package WooTelegram_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * POST /wp-json/wootg/v1/webhook/{secret}
 */
class WooTG_Webhook {

	/**
	 * Register REST route.
	 */
	public static function init(): void {
		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
	}

	/**
	 * @return void
	 */
	public static function register_routes(): void {
		register_rest_route(
			'wootg/v1',
			'/webhook/(?P<secret>[a-zA-Z0-9]+)',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'handle' ),
				'permission_callback' => array( self::class, 'verify_secret' ),
			)
		);
	}

	/**
	 * Validate URL secret against stored option (no checks inside handle).
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public static function verify_secret( $request ): bool {
		if ( ! $request instanceof \WP_REST_Request ) {
			return false;
		}

		$secret = $request->get_param( 'secret' );
		if ( ! is_string( $secret ) || '' === $secret ) {
			return false;
		}

		$stored = get_option( 'wootg_webhook_secret', '' );
		if ( ! is_string( $stored ) || '' === $stored ) {
			return false;
		}

		return hash_equals( $stored, $secret );
	}

	/**
	 * Handle Telegram update. Always 200 unless permission denied by REST.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function handle( $request ) {
		if ( ! $request instanceof \WP_REST_Request ) {
			return new \WP_REST_Response( array( 'ok' => true ), 200 );
		}

		$raw_ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';
		$ip     = filter_var( $raw_ip, FILTER_VALIDATE_IP ) !== false ? $raw_ip : '';

		if ( $ip !== '' && self::is_rate_limited( $ip ) ) {
			WooTG_Logger::log_error(
				'webhook_rate_limited',
				'ip blocked',
				array( 'ip' => $ip )
			);
			return new \WP_REST_Response( array( 'ok' => true ), 200 );
		}

		$chat_id = null;

		try {
			$update = $request->get_json_params();
			if ( ! is_array( $update ) ) {
				$update = array();
			}

			$extracted = self::extract_chat_and_user( $update );
			$chat_id   = $extracted[0];

			if ( self::should_log_full_update() ) {
				WooTG_Logger::log(
					'telegram_raw_update',
					array( 'update' => $update ),
					$chat_id,
					null,
					'success'
				);
			}

			if ( null === $chat_id || $chat_id <= 0 ) {
				return new \WP_REST_Response( array( 'ok' => true ), 200 );
			}

			if ( ! WooTG_Auth::is_authorized( $chat_id ) ) {
				self::send_unauthorized_notice( $chat_id );
				return new \WP_REST_Response( array( 'ok' => true ), 200 );
			}

			WooTG_Router::route( $update );
		} catch ( \Throwable $e ) {
			WooTG_Logger::log_error(
				'webhook_handle_exception',
				$e->getMessage(),
				array(
					'trace' => $e->getTraceAsString(),
				),
				is_int( $chat_id ) ? $chat_id : null
			);
		}

		return new \WP_REST_Response( array( 'ok' => true ), 200 );
	}

	/**
	 * @param array<string, mixed> $update Update payload.
	 * @return array{0: int|null, 1: int|null} chat_id, telegram user id.
	 */
	private static function extract_chat_and_user( array $update ): array {
		$chat_id = null;
		$user_id = null;

		if ( isset( $update['message']['chat']['id'] ) ) {
			$chat_id = (int) $update['message']['chat']['id'];
			$user_id = isset( $update['message']['from']['id'] ) ? (int) $update['message']['from']['id'] : null;
		} elseif ( isset( $update['edited_message']['chat']['id'] ) ) {
			$chat_id = (int) $update['edited_message']['chat']['id'];
			$user_id = isset( $update['edited_message']['from']['id'] ) ? (int) $update['edited_message']['from']['id'] : null;
		} elseif ( isset( $update['channel_post']['chat']['id'] ) ) {
			$chat_id = (int) $update['channel_post']['chat']['id'];
		} elseif ( isset( $update['callback_query'] ) && is_array( $update['callback_query'] ) ) {
			$cq = $update['callback_query'];
			if ( isset( $cq['message']['chat']['id'] ) ) {
				$chat_id = (int) $cq['message']['chat']['id'];
			} elseif ( isset( $cq['from']['id'] ) ) {
				$chat_id = (int) $cq['from']['id'];
			}
			$user_id = isset( $cq['from']['id'] ) ? (int) $cq['from']['id'] : null;
		} elseif ( isset( $update['my_chat_member']['chat']['id'] ) ) {
			$chat_id = (int) $update['my_chat_member']['chat']['id'];
		}

		if ( null !== $chat_id && $chat_id <= 0 ) {
			$chat_id = null;
		}

		return array( $chat_id, $user_id );
	}

	private static function is_rate_limited( string $ip ): bool {
		$key   = 'wootg_rl_' . md5( $ip );
		$count = (int) get_transient( $key );

		if ( $count === 0 ) {
			set_transient( $key, 1, 60 );
			return false;
		}

		set_transient( $key, $count + 1, 60 );

		return $count >= 60;
	}

	private static function should_log_full_update(): bool {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			return true;
		}

		$settings = get_option( 'wootg_settings', array() );

		return is_array( $settings ) && ! empty( $settings['log_telegram_updates'] );
	}

	private static function send_unauthorized_notice( int $chat_id ): void {
		$text = WooTG_Telegram::escape_html(
			__( 'אתה לא מורשה להשתמש בבוט הזה', 'woo-telegram-manager' )
		);

		try {
			$telegram = new WooTG_Telegram();
			$telegram->send_message( $chat_id, $text, null, 'HTML' );
		} catch ( \Throwable $e ) {
			WooTG_Logger::log_error(
				'webhook_unauthorized_send',
				$e->getMessage(),
				array(),
				$chat_id
			);
		}
	}
}
