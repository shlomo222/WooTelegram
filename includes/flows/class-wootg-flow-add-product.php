<?php
/**
 * Add product flow (Phase 3).
 *
 * @package WooTelegram_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Product creation flow (text-only MVP).
 */
class WooTG_Flow_AddProduct extends WooTG_Flow_Base {

	public function handle(): void {
		if ( null === $this->session ) {
			WooTG_Flow_MainMenu::show( $this->telegram, $this->chat_id );
			return;
		}

		$step = isset( $this->session['current_step'] ) ? (string) $this->session['current_step'] : '';

		$data = $this->extract_callback_data();
		if ( is_string( $data ) ) {
			$this->answer_callback();

			if ( 'add_product:cancel' === $data ) {
				WooTG_Session::end( $this->chat_id );
				WooTG_Flow_MainMenu::show( $this->telegram, $this->chat_id );
				return;
			}
		}

		switch ( $step ) {
			case 'ask_name':
				$this->handle_ask_name();
				return;
			case 'ask_short_description':
				$this->handle_ask_short_description();
				return;
			case 'ask_description':
				$this->handle_ask_description();
				return;
			case 'ask_category':
				$this->handle_ask_category();
				return;
			case 'ask_regular_price':
				$this->handle_ask_regular_price();
				return;
			case 'ask_sale_price':
				$this->handle_ask_sale_price();
				return;
			case 'ask_manage_stock':
				$this->handle_ask_manage_stock();
				return;
			case 'ask_stock_quantity':
				$this->handle_ask_stock_quantity();
				return;
			case 'confirm':
				$this->handle_confirm();
				return;
			default:
				WooTG_Session::update_step( $this->chat_id, 'ask_name' );
				self::show_ask_name( $this->telegram, $this->chat_id );
				return;
		}
	}

	public static function show_ask_name( WooTG_Telegram $telegram, int $chat_id ): void {
		$text = WooTG_Telegram::escape_html(
			__( "מעולה! בוא ניצור מוצר חדש.\nמה שם המוצר?", 'woo-telegram-manager' )
		);

		$keyboard = array(
			'inline_keyboard' => array(
				array(
					array(
						'text'          => __( '❌ ביטול', 'woo-telegram-manager' ),
						'callback_data' => 'add_product:cancel',
					),
				),
			),
		);

		$telegram->send_message( $chat_id, $text, $keyboard, 'HTML' );
	}

	public static function show_ask_short_description( WooTG_Telegram $telegram, int $chat_id ): void {
		$text = WooTG_Telegram::escape_html(
			__( "תיאור קצר (אופציונלי).\nאפשר גם ללחוץ \"דלג\".", 'woo-telegram-manager' )
		);

		$keyboard = array(
			'keyboard' => array(
				array(
					array( 'text' => __( 'דלג', 'woo-telegram-manager' ) ),
				),
			),
			'resize_keyboard' => true,
			'one_time_keyboard' => false,
		);

		$telegram->send_message( $chat_id, $text, $keyboard, 'HTML' );
	}

	public static function show_ask_description( WooTG_Telegram $telegram, int $chat_id ): void {
		$text = WooTG_Telegram::escape_html(
			__( "תיאור מלא (אופציונלי).\nאפשר גם ללחוץ \"דלג\".", 'woo-telegram-manager' )
		);

		$keyboard = array(
			'keyboard' => array(
				array(
					array( 'text' => __( 'דלג', 'woo-telegram-manager' ) ),
				),
			),
			'resize_keyboard' => true,
			'one_time_keyboard' => false,
		);

		$telegram->send_message( $chat_id, $text, $keyboard, 'HTML' );
	}

	public static function show_ask_category( WooTG_Telegram $telegram, int $chat_id ): void {
		$cats = WooTG_WC_Helper::get_categories( 20 );

		$rows = array();

		if ( array() === $cats ) {
			$text = WooTG_Telegram::escape_html(
				__( "לא נמצאו קטגוריות מוצרים.\nצור לפחות קטגוריה אחת ב-WooCommerce ונסה שוב.", 'woo-telegram-manager' )
			);

			$rows[] = array(
				array(
					'text'          => __( '❌ ביטול', 'woo-telegram-manager' ),
					'callback_data' => 'add_product:cancel',
				),
			);

			$telegram->send_message( $chat_id, $text, array( 'inline_keyboard' => $rows ), 'HTML' );
			return;
		}

		$text = WooTG_Telegram::escape_html(
			__( 'בחר קטגוריה למוצר:', 'woo-telegram-manager' )
		);

		$pair = array();
		foreach ( $cats as $cat ) {
			$cat_id   = isset( $cat['id'] ) ? absint( $cat['id'] ) : 0;
			$cat_name = isset( $cat['name'] ) ? (string) $cat['name'] : '';
			if ( $cat_id <= 0 || '' === $cat_name ) {
				continue;
			}

			$pair[] = array(
				'text'          => $cat_name,
				'callback_data' => 'add_product:category:' . $cat_id,
			);

			if ( 2 === count( $pair ) ) {
				$rows[] = $pair;
				$pair   = array();
			}
		}

		if ( array() !== $pair ) {
			$rows[] = $pair;
		}

		$rows[] = array(
			array(
				'text'          => __( '❌ ביטול', 'woo-telegram-manager' ),
				'callback_data' => 'add_product:cancel',
			),
		);

		$telegram->send_message( $chat_id, $text, array( 'inline_keyboard' => $rows ), 'HTML' );
	}

	public static function show_ask_regular_price( WooTG_Telegram $telegram, int $chat_id ): void {
		$text = WooTG_Telegram::escape_html(
			__( 'מה המחיר הרגיל? (לדוגמה 99.90)', 'woo-telegram-manager' )
		);

		$keyboard = array(
			'inline_keyboard' => array(
				array(
					array(
						'text'          => __( '❌ ביטול', 'woo-telegram-manager' ),
						'callback_data' => 'add_product:cancel',
					),
				),
			),
		);

		$telegram->send_message( $chat_id, $text, $keyboard, 'HTML' );
	}

	public static function show_ask_sale_price( WooTG_Telegram $telegram, int $chat_id ): void {
		$text = WooTG_Telegram::escape_html(
			__( "מחיר מבצע (אופציונלי).\nאפשר גם ללחוץ \"דלג\".", 'woo-telegram-manager' )
		);

		$keyboard = array(
			'keyboard' => array(
				array(
					array( 'text' => __( 'דלג', 'woo-telegram-manager' ) ),
				),
			),
			'resize_keyboard' => true,
			'one_time_keyboard' => false,
		);

		$telegram->send_message( $chat_id, $text, $keyboard, 'HTML' );
	}

	public static function show_ask_manage_stock( WooTG_Telegram $telegram, int $chat_id ): void {
		$text = WooTG_Telegram::escape_html(
			__( 'לנהל מלאי למוצר הזה?', 'woo-telegram-manager' )
		);

		$keyboard = array(
			'inline_keyboard' => array(
				array(
					array(
						'text'          => __( 'כן', 'woo-telegram-manager' ),
						'callback_data' => 'add_product:stock_yes',
					),
					array(
						'text'          => __( 'לא', 'woo-telegram-manager' ),
						'callback_data' => 'add_product:stock_no',
					),
				),
				array(
					array(
						'text'          => __( '❌ ביטול', 'woo-telegram-manager' ),
						'callback_data' => 'add_product:cancel',
					),
				),
			),
		);

		$telegram->send_message( $chat_id, $text, $keyboard, 'HTML' );
	}

	public static function show_ask_stock_quantity( WooTG_Telegram $telegram, int $chat_id ): void {
		$text = WooTG_Telegram::escape_html(
			__( 'כמה יחידות במלאי? (מספר שלם 0 ומעלה)', 'woo-telegram-manager' )
		);

		$keyboard = array(
			'inline_keyboard' => array(
				array(
					array(
						'text'          => __( '❌ ביטול', 'woo-telegram-manager' ),
						'callback_data' => 'add_product:cancel',
					),
				),
			),
		);

		$telegram->send_message( $chat_id, $text, $keyboard, 'HTML' );
	}

	public static function show_confirm( WooTG_Telegram $telegram, int $chat_id, array $data ): void {
		$name  = isset( $data['name'] ) ? (string) $data['name'] : '';
		$short = isset( $data['short_description'] ) ? (string) $data['short_description'] : '';
		$desc  = isset( $data['description'] ) ? (string) $data['description'] : '';
		$cat   = isset( $data['category_id'] ) ? absint( $data['category_id'] ) : 0;
		$reg   = isset( $data['regular_price'] ) ? (float) $data['regular_price'] : 0.0;
		$sale  = isset( $data['sale_price'] ) && null !== $data['sale_price'] ? (float) $data['sale_price'] : null;
		$ms    = ! empty( $data['manage_stock'] );
		$qty   = isset( $data['stock_quantity'] ) ? (int) $data['stock_quantity'] : null;

		$lines = array();
		$lines[] = '🧾 ' . __( 'סיכום מוצר', 'woo-telegram-manager' );
		$lines[] = '';
		$lines[] = __( 'שם:', 'woo-telegram-manager' ) . ' ' . $name;
		$lines[] = __( 'תיאור קצר:', 'woo-telegram-manager' ) . ' ' . ( '' !== $short ? $short : __( '(ללא)', 'woo-telegram-manager' ) );
		$lines[] = __( 'תיאור מלא:', 'woo-telegram-manager' ) . ' ' . ( '' !== $desc ? $desc : __( '(ללא)', 'woo-telegram-manager' ) );
		$lines[] = __( 'קטגוריה ID:', 'woo-telegram-manager' ) . ' ' . ( $cat > 0 ? (string) $cat : __( '(לא נבחרה)', 'woo-telegram-manager' ) );
		$lines[] = __( 'מחיר רגיל:', 'woo-telegram-manager' ) . ' ' . self::format_price( $reg );
		$lines[] = __( 'מחיר מבצע:', 'woo-telegram-manager' ) . ' ' . ( null !== $sale ? self::format_price( $sale ) : __( '(ללא)', 'woo-telegram-manager' ) );
		$lines[] = __( 'ניהול מלאי:', 'woo-telegram-manager' ) . ' ' . ( $ms ? __( 'כן', 'woo-telegram-manager' ) : __( 'לא', 'woo-telegram-manager' ) );
		if ( $ms ) {
			$lines[] = __( 'כמות במלאי:', 'woo-telegram-manager' ) . ' ' . ( null !== $qty ? (string) $qty : '0' );
		}

		$text = WooTG_Telegram::escape_html( implode( "\n", $lines ) );

		$keyboard = array(
			'inline_keyboard' => array(
				array(
					array(
						'text'          => __( 'פרסם', 'woo-telegram-manager' ),
						'callback_data' => 'add_product:publish',
					),
					array(
						'text'          => __( 'שמור כטיוטה', 'woo-telegram-manager' ),
						'callback_data' => 'add_product:draft',
					),
				),
				array(
					array(
						'text'          => __( 'ערוך', 'woo-telegram-manager' ),
						'callback_data' => 'add_product:edit',
					),
					array(
						'text'          => __( 'בטל', 'woo-telegram-manager' ),
						'callback_data' => 'add_product:cancel',
					),
				),
			),
		);

		$telegram->send_message( $chat_id, $text, $keyboard, 'HTML' );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function get_data(): array {
		if ( null === $this->session || ! isset( $this->session['session_data'] ) || ! is_array( $this->session['session_data'] ) ) {
			return array();
		}

		return $this->session['session_data'];
	}

	private function handle_ask_name(): void {
		$text = $this->extract_text();
		if ( null === $text ) {
			self::show_ask_name( $this->telegram, $this->chat_id );
			return;
		}

		$name = sanitize_text_field( $text );
		$name = trim( $name );

		if ( '' === $name ) {
			$this->send( WooTG_Telegram::escape_html( __( '❗️אנא הזן שם מוצר.', 'woo-telegram-manager' ) ) );
			self::show_ask_name( $this->telegram, $this->chat_id );
			return;
		}

		if ( mb_strlen( $name ) > 200 ) {
			$this->send( WooTG_Telegram::escape_html( __( '❗️שם המוצר ארוך מדי (מקסימום 200 תווים).', 'woo-telegram-manager' ) ) );
			self::show_ask_name( $this->telegram, $this->chat_id );
			return;
		}

		WooTG_Session::update_step(
			$this->chat_id,
			'ask_short_description',
			array(
				'name' => $name,
			)
		);
		self::show_ask_short_description( $this->telegram, $this->chat_id );
	}

	private function handle_ask_short_description(): void {
		$text = $this->extract_text();
		if ( null === $text ) {
			self::show_ask_short_description( $this->telegram, $this->chat_id );
			return;
		}

		$raw = trim( (string) $text );
		if ( __( 'דלג', 'woo-telegram-manager' ) === $raw || 'דלג' === $raw ) {
			$value = '';
		} else {
			$value = sanitize_textarea_field( $raw );
		}

		WooTG_Session::update_step(
			$this->chat_id,
			'ask_description',
			array(
				'short_description' => $value,
			)
		);
		self::show_ask_description( $this->telegram, $this->chat_id );
	}

	private function handle_ask_description(): void {
		$text = $this->extract_text();
		if ( null === $text ) {
			self::show_ask_description( $this->telegram, $this->chat_id );
			return;
		}

		$raw = trim( (string) $text );
		if ( __( 'דלג', 'woo-telegram-manager' ) === $raw || 'דלג' === $raw ) {
			$value = '';
		} else {
			$value = sanitize_textarea_field( $raw );
		}

		WooTG_Session::update_step(
			$this->chat_id,
			'ask_category',
			array(
				'description' => $value,
			)
		);
		self::show_ask_category( $this->telegram, $this->chat_id );
	}

	private function handle_ask_category(): void {
		$text = $this->extract_text();
		if ( null !== $text ) {
			$this->send(
				WooTG_Telegram::escape_html(
					__( 'בחר כפתור מהכפתורים למטה.', 'woo-telegram-manager' )
				)
			);
			self::show_ask_category( $this->telegram, $this->chat_id );
			return;
		}

		$data = $this->extract_callback_data();
		if ( ! is_string( $data ) || ! str_starts_with( $data, 'add_product:category:' ) ) {
			self::show_ask_category( $this->telegram, $this->chat_id );
			return;
		}

		$cat_id = absint( substr( $data, strlen( 'add_product:category:' ) ) );
		if ( $cat_id <= 0 ) {
			$this->send( WooTG_Telegram::escape_html( __( '❗️קטגוריה לא תקינה. נסה שוב.', 'woo-telegram-manager' ) ) );
			self::show_ask_category( $this->telegram, $this->chat_id );
			return;
		}

		WooTG_Session::update_step(
			$this->chat_id,
			'ask_regular_price',
			array(
				'category_id' => $cat_id,
			)
		);
		self::show_ask_regular_price( $this->telegram, $this->chat_id );
	}

	private function handle_ask_regular_price(): void {
		$text = $this->extract_text();
		if ( null === $text ) {
			self::show_ask_regular_price( $this->telegram, $this->chat_id );
			return;
		}

		$price = self::parse_price( (string) $text );
		if ( null === $price || $price <= 0 ) {
			$this->send( WooTG_Telegram::escape_html( __( '❗️מחיר לא תקין. הזן מספר חיובי (למשל 99.90).', 'woo-telegram-manager' ) ) );
			self::show_ask_regular_price( $this->telegram, $this->chat_id );
			return;
		}

		WooTG_Session::update_step(
			$this->chat_id,
			'ask_sale_price',
			array(
				'regular_price' => $price,
			)
		);
		self::show_ask_sale_price( $this->telegram, $this->chat_id );
	}

	private function handle_ask_sale_price(): void {
		$text = $this->extract_text();
		if ( null === $text ) {
			self::show_ask_sale_price( $this->telegram, $this->chat_id );
			return;
		}

		$raw = trim( (string) $text );
		if ( __( 'דלג', 'woo-telegram-manager' ) === $raw || 'דלג' === $raw ) {
			$sale = null;
		} else {
			$sale = self::parse_price( $raw );
			if ( null === $sale || $sale <= 0 ) {
				$this->send( WooTG_Telegram::escape_html( __( '❗️מחיר מבצע לא תקין. הזן מספר חיובי או לחץ "דלג".', 'woo-telegram-manager' ) ) );
				self::show_ask_sale_price( $this->telegram, $this->chat_id );
				return;
			}
		}

		WooTG_Session::update_step(
			$this->chat_id,
			'ask_manage_stock',
			array(
				'sale_price' => $sale,
			)
		);
		self::show_ask_manage_stock( $this->telegram, $this->chat_id );
	}

	private function handle_ask_manage_stock(): void {
		$text = $this->extract_text();
		if ( null !== $text ) {
			$this->send(
				WooTG_Telegram::escape_html(
					__( 'בחר כפתור מהכפתורים למטה.', 'woo-telegram-manager' )
				)
			);
			self::show_ask_manage_stock( $this->telegram, $this->chat_id );
			return;
		}

		$data = $this->extract_callback_data();
		if ( ! is_string( $data ) ) {
			self::show_ask_manage_stock( $this->telegram, $this->chat_id );
			return;
		}

		if ( 'add_product:stock_yes' === $data ) {
			WooTG_Session::update_step(
				$this->chat_id,
				'ask_stock_quantity',
				array(
					'manage_stock' => true,
				)
			);
			self::show_ask_stock_quantity( $this->telegram, $this->chat_id );
			return;
		}

		if ( 'add_product:stock_no' === $data ) {
			WooTG_Session::update_step(
				$this->chat_id,
				'confirm',
				array(
					'manage_stock'   => false,
					'stock_quantity' => null,
				)
			);
			self::show_confirm( $this->telegram, $this->chat_id, $this->get_data() );
			return;
		}

		self::show_ask_manage_stock( $this->telegram, $this->chat_id );
	}

	private function handle_ask_stock_quantity(): void {
		$text = $this->extract_text();
		if ( null === $text ) {
			self::show_ask_stock_quantity( $this->telegram, $this->chat_id );
			return;
		}

		$raw = trim( (string) $text );
		if ( '' === $raw || ! ctype_digit( $raw ) ) {
			$this->send( WooTG_Telegram::escape_html( __( '❗️כמות לא תקינה. הזן מספר שלם 0 ומעלה.', 'woo-telegram-manager' ) ) );
			self::show_ask_stock_quantity( $this->telegram, $this->chat_id );
			return;
		}

		$qty = (int) $raw;
		if ( $qty < 0 ) {
			$this->send( WooTG_Telegram::escape_html( __( '❗️כמות לא תקינה. הזן מספר שלם 0 ומעלה.', 'woo-telegram-manager' ) ) );
			self::show_ask_stock_quantity( $this->telegram, $this->chat_id );
			return;
		}

		WooTG_Session::update_step(
			$this->chat_id,
			'confirm',
			array(
				'stock_quantity' => $qty,
			)
		);
		self::show_confirm( $this->telegram, $this->chat_id, $this->get_data() );
	}

	private function handle_confirm(): void {
		$text = $this->extract_text();
		if ( null !== $text ) {
			$this->send(
				WooTG_Telegram::escape_html(
					__( 'בחר כפתור מהכפתורים למטה.', 'woo-telegram-manager' )
				)
			);
			self::show_confirm( $this->telegram, $this->chat_id, $this->get_data() );
			return;
		}

		$data = $this->extract_callback_data();
		if ( ! is_string( $data ) ) {
			self::show_confirm( $this->telegram, $this->chat_id, $this->get_data() );
			return;
		}

		if ( 'add_product:edit' === $data ) {
			WooTG_Session::start( $this->chat_id, $this->user_id, 'add_product', 'ask_name', array() );
			self::show_ask_name( $this->telegram, $this->chat_id );
			return;
		}

		if ( 'add_product:publish' !== $data && 'add_product:draft' !== $data ) {
			self::show_confirm( $this->telegram, $this->chat_id, $this->get_data() );
			return;
		}

		if ( ! class_exists( 'WC_Product_Simple' ) ) {
			$this->send( WooTG_Telegram::escape_html( __( '❗️WooCommerce לא זמין כרגע. נסה שוב מאוחר יותר.', 'woo-telegram-manager' ) ) );
			self::show_confirm( $this->telegram, $this->chat_id, $this->get_data() );
			return;
		}

		$session_data = $this->get_data();
		$name         = isset( $session_data['name'] ) ? (string) $session_data['name'] : '';
		$short        = isset( $session_data['short_description'] ) ? (string) $session_data['short_description'] : '';
		$desc         = isset( $session_data['description'] ) ? (string) $session_data['description'] : '';
		$cat_id       = isset( $session_data['category_id'] ) ? absint( $session_data['category_id'] ) : 0;
		$reg          = isset( $session_data['regular_price'] ) ? (float) $session_data['regular_price'] : 0.0;
		$sale         = isset( $session_data['sale_price'] ) && null !== $session_data['sale_price'] ? (float) $session_data['sale_price'] : null;
		$manage_stock = ! empty( $session_data['manage_stock'] );
		$qty          = isset( $session_data['stock_quantity'] ) && null !== $session_data['stock_quantity'] ? (int) $session_data['stock_quantity'] : 0;

		$status = 'add_product:publish' === $data ? 'publish' : 'draft';

		if ( $cat_id <= 0 ) {
			$this->send( WooTG_Telegram::escape_html( __( '❗️חובה לבחור קטגוריה למוצר.', 'woo-telegram-manager' ) ) );
			WooTG_Session::update_step( $this->chat_id, 'ask_category' );
			self::show_ask_category( $this->telegram, $this->chat_id );
			return;
		}

		try {
			$product = new WC_Product_Simple();
			$product->set_name( $name );
			$product->set_short_description( $short );
			$product->set_description( $desc );
			if ( $cat_id > 0 ) {
				$product->set_category_ids( array( $cat_id ) );
			}
			$product->set_regular_price( (string) $reg );
			if ( null !== $sale ) {
				$product->set_sale_price( (string) $sale );
			}
			$product->set_manage_stock( $manage_stock );
			if ( $manage_stock ) {
				$product->set_stock_quantity( $qty );
				$product->set_stock_status( $qty > 0 ? 'instock' : 'outofstock' );
			}
			$product->set_status( $status );

			$product_id = $product->save();
		} catch ( \Throwable $e ) {
			WooTG_Logger::log_error(
				'product_create_failed',
				$e->getMessage(),
				array(
					'name'   => $name,
					'status' => $status,
				),
				$this->chat_id
			);

			$this->send( WooTG_Telegram::escape_html( __( '❗️שגיאה ביצירת המוצר. נסה שוב.', 'woo-telegram-manager' ) ) );
			self::show_confirm( $this->telegram, $this->chat_id, $this->get_data() );
			return;
		}

		$product_id = absint( $product_id );
		if ( $product_id <= 0 ) {
			$this->send( WooTG_Telegram::escape_html( __( '❗️שגיאה ביצירת המוצר. נסה שוב.', 'woo-telegram-manager' ) ) );
			self::show_confirm( $this->telegram, $this->chat_id, $this->get_data() );
			return;
		}

		WooTG_Logger::log(
			'product_created',
			array(
				'name'   => $name,
				'id'     => $product_id,
				'status' => $status,
			),
			$this->chat_id,
			$product_id
		);

		$link = admin_url( 'post.php?post=' . $product_id . '&action=edit' );
		$msg  = WooTG_Telegram::escape_html( __( '✅ המוצר נוצר בהצלחה!', 'woo-telegram-manager' ) );
		$msg .= "\n\n" . '<a href="' . esc_url( $link ) . '">' . WooTG_Telegram::escape_html( __( 'עריכת המוצר במערכת', 'woo-telegram-manager' ) ) . '</a>';

		$this->send( $msg );

		WooTG_Session::end( $this->chat_id );
		WooTG_Flow_MainMenu::show( $this->telegram, $this->chat_id );
	}

	private static function parse_price( string $raw ): ?float {
		$raw = trim( $raw );
		if ( '' === $raw ) {
			return null;
		}

		$raw = str_replace( array( '₪', ' ' ), '', $raw );
		$raw = str_replace( ',', '.', $raw );

		if ( ! preg_match( '/^[0-9]+(\.[0-9]{1,2})?$/', $raw ) ) {
			return null;
		}

		$val = (float) $raw;

		return $val > 0 ? $val : null;
	}

	private static function format_price( float $price ): string {
		return number_format( $price, 2 ) . ' ₪';
	}
}

