<?php
/**
 * Main menu flow (inline keyboard).
 *
 * @package WooTelegram_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Root menu + placeholder product actions.
 */
class WooTG_Flow_MainMenu extends WooTG_Flow_Base {

	/**
	 * Handle callbacks and plain text fallbacks.
	 */
	public function handle(): void {
		$data = $this->extract_callback_data();

		if ( is_string( $data ) && str_starts_with( $data, 'main_menu:' ) ) {
			$this->answer_callback();

			if ( 'main_menu:add_product' === $data ) {
				WooTG_Session::end( $this->chat_id );
				$this->send(
					WooTG_Telegram::escape_html(
						__( 'בקרוב: הוספת מוצר חדש', 'woo-telegram-manager' )
					)
				);
				return;
			}

			if ( 'main_menu:list_products' === $data ) {
				$this->send(
					WooTG_Telegram::escape_html(
						__( 'בקרוב: רשימת מוצרים', 'woo-telegram-manager' )
					)
				);
				return;
			}

			if ( 'main_menu:orders' === $data ) {
				$this->send(
					WooTG_Telegram::escape_html(
						__( 'בקרוב: הזמנות', 'woo-telegram-manager' )
					)
				);
				return;
			}

			if ( 'main_menu:settings' === $data ) {
				$this->send(
					WooTG_Telegram::escape_html(
						__( 'בקרוב: הגדרות', 'woo-telegram-manager' )
					)
				);
				return;
			}
		}

		self::show( $this->telegram, $this->chat_id );
	}

	/**
	 * Render the main inline menu.
	 */
	public static function show( WooTG_Telegram $telegram, int $chat_id ): void {
		$text = WooTG_Telegram::escape_html(
			__( "שלום! ברוך הבא לבוט ניהול החנות 🛒\nבחר פעולה:", 'woo-telegram-manager' )
		);

		$keyboard = array(
			'inline_keyboard' => array(
				array(
					array(
						'text'          => __( '➕ הוסף מוצר', 'woo-telegram-manager' ),
						'callback_data' => 'main_menu:add_product',
					),
				),
				array(
					array(
						'text'          => __( '📋 מוצרים', 'woo-telegram-manager' ),
						'callback_data' => 'main_menu:list_products',
					),
					array(
						'text'          => __( '🛒 הזמנות', 'woo-telegram-manager' ),
						'callback_data' => 'main_menu:orders',
					),
				),
				array(
					array(
						'text'          => __( '⚙️ הגדרות', 'woo-telegram-manager' ),
						'callback_data' => 'main_menu:settings',
					),
				),
			),
		);

		$telegram->send_message( $chat_id, $text, $keyboard, 'HTML' );
	}
}
