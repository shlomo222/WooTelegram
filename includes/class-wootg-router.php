<?php
/**
 * Routes Telegram updates to flows.
 *
 * @package WooTelegram_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Update router (commands → sessions → main menu).
 */
class WooTG_Router {

	/**
	 * @param array<string, mixed> $update Telegram update.
	 */
	public static function route( array $update ): void {
		try {
			$ctx = self::extract_context( $update );
			if ( null === $ctx ) {
				return;
			}

			$telegram = new WooTG_Telegram();
			$chat_id  = $ctx['chat_id'];
			$session  = WooTG_Session::get( $chat_id );

			if ( isset( $update['callback_query'] ) && is_array( $update['callback_query'] ) ) {
				$data = isset( $update['callback_query']['data'] ) && is_string( $update['callback_query']['data'] )
					? $update['callback_query']['data']
					: '';

				if ( str_starts_with( $data, 'main_menu:' ) ) {
					$flow = new WooTG_Flow_MainMenu( $update, $session );
					$flow->handle();
					return;
				}

				if ( null !== $session && ! empty( $session['current_flow'] ) ) {
					self::dispatch_flow( (string) $session['current_flow'], $update, $session );
					return;
				}

				WooTG_Flow_MainMenu::show( $telegram, $chat_id );
				return;
			}

			$cmd = self::parse_command_from_update( $update );
			if ( null !== $cmd ) {
				self::handle_command( $cmd, $telegram, $chat_id );
				return;
			}

			if ( null !== $session && ! empty( $session['current_flow'] ) ) {
				self::dispatch_flow( (string) $session['current_flow'], $update, $session );
				return;
			}

			WooTG_Flow_MainMenu::show( $telegram, $chat_id );
		} catch ( \Throwable $e ) {
			WooTG_Logger::log_error(
				'router_exception',
				$e->getMessage(),
				array(
					'trace' => $e->getTraceAsString(),
				),
				self::guess_chat_id( $update )
			);
		}
	}

	/**
	 * @param array<string, mixed>        $session Session snapshot.
	 * @param array<string, mixed>        $update  Raw update.
	 */
	public static function dispatch_flow( string $flow, array $update, array $session ): void {
		$flow = strtolower( trim( $flow ) );

		switch ( $flow ) {
			case 'main_menu':
				$handler = new WooTG_Flow_MainMenu( $update, $session );
				$handler->handle();
				return;
			default:
				WooTG_Logger::log(
					'router_unknown_flow',
					array(
						'flow' => $flow,
					),
					self::guess_chat_id( $update ),
					null,
					'warning'
				);

				$reset_chat = self::guess_chat_id( $update );
				if ( null === $reset_chat || $reset_chat <= 0 ) {
					return;
				}

				WooTG_Session::end( $reset_chat );

				try {
					$tg = new WooTG_Telegram();
					WooTG_Flow_MainMenu::show( $tg, $reset_chat );
				} catch ( \Throwable $e ) {
					WooTG_Logger::log_error(
						'router_reset_menu_failed',
						$e->getMessage(),
						array( 'flow' => $flow ),
						$reset_chat
					);
				}
		}
	}

	/**
	 * @param array<string, mixed> $update Update.
	 * @return array{chat_id: int, user_id: int}|null
	 */
	private static function extract_context( array $update ): ?array {
		$chat_id = 0;
		$user_id = 0;

		if ( isset( $update['message']['chat']['id'] ) ) {
			$chat_id = (int) $update['message']['chat']['id'];
			$user_id = isset( $update['message']['from']['id'] ) ? (int) $update['message']['from']['id'] : 0;
		} elseif ( isset( $update['edited_message']['chat']['id'] ) ) {
			$chat_id = (int) $update['edited_message']['chat']['id'];
			$user_id = isset( $update['edited_message']['from']['id'] ) ? (int) $update['edited_message']['from']['id'] : 0;
		} elseif ( isset( $update['callback_query'] ) && is_array( $update['callback_query'] ) ) {
			$cq = $update['callback_query'];
			if ( isset( $cq['message']['chat']['id'] ) ) {
				$chat_id = (int) $cq['message']['chat']['id'];
			} elseif ( isset( $cq['from']['id'] ) ) {
				$chat_id = (int) $cq['from']['id'];
			}
			$user_id = isset( $cq['from']['id'] ) ? (int) $cq['from']['id'] : 0;
		} elseif ( isset( $update['channel_post']['chat']['id'] ) ) {
			$chat_id = (int) $update['channel_post']['chat']['id'];
		}

		if ( $chat_id <= 0 ) {
			return null;
		}

		return array(
			'chat_id' => $chat_id,
			'user_id' => $user_id,
		);
	}

	/**
	 * @param array<string, mixed> $update Update.
	 */
	private static function guess_chat_id( array $update ): ?int {
		$ctx = self::extract_context( $update );

		return null !== $ctx ? $ctx['chat_id'] : null;
	}

	/**
	 * @param array<string, mixed> $update Update.
	 */
	private static function parse_command_from_update( array $update ): ?string {
		$text = null;

		if ( isset( $update['message']['text'] ) && is_string( $update['message']['text'] ) ) {
			$text = $update['message']['text'];
		}

		if ( null === $text || '' === trim( $text ) ) {
			return null;
		}

		return self::normalize_command( $text );
	}

	private static function normalize_command( string $text ): ?string {
		$text = trim( $text );
		if ( '' === $text || '/' !== $text[0] ) {
			return null;
		}

		$token = explode( ' ', $text, 2 )[0];
		$base  = explode( '@', $token, 2 )[0];

		return strtolower( $base );
	}

	/**
	 * @return void
	 */
	private static function handle_command( string $cmd, WooTG_Telegram $telegram, int $chat_id ): void {
		switch ( $cmd ) {
			case '/start':
				WooTG_Session::end( $chat_id );
				WooTG_Flow_MainMenu::show( $telegram, $chat_id );
				return;
			case '/menu':
				WooTG_Flow_MainMenu::show( $telegram, $chat_id );
				return;
			case '/cancel':
				WooTG_Session::end( $chat_id );
				$telegram->send_message(
					$chat_id,
					WooTG_Telegram::escape_html(
						__( 'בוטל', 'woo-telegram-manager' )
					),
					null,
					'HTML'
				);
				WooTG_Flow_MainMenu::show( $telegram, $chat_id );
				return;
			case '/help':
				$help = WooTG_Telegram::escape_html(
					__( "פקודות זמינות:\n/start — התחלה מהראש\n/menu — תפריט ראשי\n/cancel — ביטול פעולה\n/help — עזרה", 'woo-telegram-manager' )
				);
				$telegram->send_message( $chat_id, $help, null, 'HTML' );
				return;
			default:
				WooTG_Flow_MainMenu::show( $telegram, $chat_id );
				return;
		}
	}
}
