<?php
/**
 * Admin settings page and Telegram webhook AJAX.
 *
 * @package WooTelegram_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings UI and REST-adjacent webhook registration helpers.
 */
class WooTG_Settings {

	/**
	 * Register hooks.
	 */
	public static function init(): void {
		add_action( 'admin_menu', array( self::class, 'register_menu' ) );
		add_action( 'admin_init', array( self::class, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue_assets' ) );

		add_action( 'wp_ajax_wootg_register_webhook', array( self::class, 'ajax_register_webhook' ) );
		add_action( 'wp_ajax_wootg_delete_webhook', array( self::class, 'ajax_delete_webhook' ) );
		add_action( 'wp_ajax_wootg_webhook_status', array( self::class, 'ajax_webhook_status' ) );
		add_action( 'wp_ajax_wootg_test_bot', array( self::class, 'ajax_test_bot' ) );
		add_action( 'wp_ajax_wootg_get_logs', array( self::class, 'ajax_get_logs' ) );
		add_action( 'wp_ajax_wootg_regenerate_webhook_secret', array( self::class, 'ajax_regenerate_webhook_secret' ) );
		add_action( 'admin_notices', array( self::class, 'show_invalid_token_notice' ) );
	}

	/**
	 * Submenu under WooCommerce.
	 */
	public static function register_menu(): void {
		add_submenu_page(
			'woocommerce',
			__( 'טלגרם מנג׳ר', 'woo-telegram-manager' ),
			__( 'טלגרם מנג׳ר', 'woo-telegram-manager' ),
			'manage_woocommerce',
			'wootg-settings',
			array( self::class, 'render_page' )
		);
	}

	/**
	 * Render settings template.
	 */
	public static function render_page(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$template = WOOTG_PATH . 'admin/settings-page.php';
		if ( is_readable( $template ) ) {
			require $template;
		}
	}

	/**
	 * Register option + sanitizer.
	 */
	public static function register_settings(): void {
		register_setting(
			'wootg_settings_group',
			'wootg_settings',
			array(
				'sanitize_callback' => array( self::class, 'sanitize_settings' ),
			)
		);
	}

	/**
	 * Sanitize stored settings; encrypt bot token when changed.
	 *
	 * @param mixed $input Raw input.
	 * @return array<string, mixed>
	 */
	public static function sanitize_settings( $input ): array {
		$existing = get_option( 'wootg_settings', array() );
		if ( ! is_array( $existing ) ) {
			$existing = array();
		}

		$out = $existing;

		if ( ! is_array( $input ) ) {
			return $out;
		}

		$new_token = isset( $input['bot_token'] ) ? trim( (string) $input['bot_token'] ) : '';

		if ( $new_token === '' ) {
			$out['bot_token'] = isset( $existing['bot_token'] ) ? (string) $existing['bot_token'] : '';
		} else {
			$old_plain = self::get_plain_bot_token_from_stored(
				isset( $existing['bot_token'] ) ? (string) $existing['bot_token'] : ''
			);

			if ( $old_plain !== $new_token ) {
				if ( ! self::is_plausible_telegram_bot_token( $new_token ) ) {
					set_transient( 'wootg_invalid_token_notice', 1, 30 );
					$out['bot_token'] = isset( $existing['bot_token'] ) ? (string) $existing['bot_token'] : '';
				} else {
					$out['bot_token'] = WooTG_Crypto::encrypt( $new_token );
				}
			} else {
				$out['bot_token'] = isset( $existing['bot_token'] ) ? (string) $existing['bot_token'] : '';
			}
		}

		if ( isset( $input['authorized_chat_ids'] ) && is_string( $input['authorized_chat_ids'] ) ) {
			$lines = preg_split( '/\R/', $input['authorized_chat_ids'] );
			$ids   = array();
			if ( is_array( $lines ) ) {
				foreach ( $lines as $line ) {
					$trimmed = trim( (string) $line );
					if ( $trimmed === '' || ! preg_match( '/^-?[0-9]+$/', $trimmed ) ) {
						continue;
					}
					$id = intval( $trimmed );
					if ( $id !== 0 ) {
						$ids[] = $id;
					}
				}
			}
			$out['authorized_chat_ids'] = array_values( array_unique( $ids ) );
		}

		if ( isset( $input['default_product_status'] ) ) {
			$out['default_product_status'] = sanitize_text_field( (string) $input['default_product_status'] );
		}

		if ( isset( $input['default_stock_status'] ) ) {
			$out['default_stock_status'] = sanitize_text_field( (string) $input['default_stock_status'] );
		}

		if ( isset( $input['default_manage_stock'] ) ) {
			$out['default_manage_stock'] = (bool) $input['default_manage_stock'];
		}

		if ( array_key_exists( 'ai_provider', $input ) ) {
			$out['ai_provider'] = null === $input['ai_provider'] || '' === $input['ai_provider']
				? null
				: sanitize_text_field( (string) $input['ai_provider'] );
		}

		if ( array_key_exists( 'ai_api_key', $input ) ) {
			$out['ai_api_key'] = null === $input['ai_api_key'] || '' === $input['ai_api_key']
				? null
				: sanitize_text_field( (string) $input['ai_api_key'] );
		}

		self::sync_authorized_users_table(
			isset( $out['authorized_chat_ids'] ) && is_array( $out['authorized_chat_ids'] )
				? $out['authorized_chat_ids']
				: array()
		);

		return $out;
	}

	/**
	 * Keep wp_wootg_authorized_users in sync with the settings option.
	 *
	 * @param list<int> $new_ids
	 */
	private static function sync_authorized_users_table( array $new_ids ): void {
		if ( ! class_exists( 'WooTG_Auth' ) ) {
			return;
		}

		$existing_rows = WooTG_Auth::get_all();
		$existing_ids  = array_map(
			'intval',
			array_column( $existing_rows, 'telegram_chat_id' )
		);

		foreach ( $existing_ids as $old_id ) {
			if ( ! in_array( $old_id, $new_ids, true ) ) {
				WooTG_Auth::remove( $old_id );
			}
		}

		foreach ( $new_ids as $new_id ) {
			if ( ! in_array( $new_id, $existing_ids, true ) ) {
				WooTG_Auth::add( $new_id, '', 'admin' );
			}
		}
	}

	/**
	 * Assets for our admin screen only.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 */
	public static function enqueue_assets( string $hook_suffix ): void {
		if ( 'woocommerce_page_wootg-settings' !== $hook_suffix ) {
			return;
		}

		wp_enqueue_style(
			'wootg-admin',
			WOOTG_URL . 'admin/assets/admin.css',
			array(),
			WOOTG_VERSION
		);

		wp_enqueue_script(
			'wootg-admin',
			WOOTG_URL . 'admin/assets/admin.js',
			array( 'jquery' ),
			WOOTG_VERSION,
			true
		);

		wp_localize_script(
			'wootg-admin',
			'wootgAdmin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'wootg_admin' ),
				'i18n'    => array(
					'loading'          => __( 'טוען…', 'woo-telegram-manager' ),
					'registered'     => __( 'Webhook רשום', 'woo-telegram-manager' ),
					'notRegistered'  => __( 'Webhook לא רשום', 'woo-telegram-manager' ),
					'errorGeneric'   => __( 'שגיאה, נסה שוב.', 'woo-telegram-manager' ),
					'confirmDelete'       => __( 'לנתק את ה-Webhook מטלגרם?', 'woo-telegram-manager' ),
					'confirmRotateSecret' => __( 'פעולה זו תבטל את ה-Webhook הנוכחי ותרשום חדש. להמשיך?', 'woo-telegram-manager' ),
					'testBotOk'      => __( 'חיבור תקין', 'woo-telegram-manager' ),
					'testBotFail'    => __( 'החיבור נכשל', 'woo-telegram-manager' ),
					'logsEmpty'      => __( 'אין רשומות', 'woo-telegram-manager' ),
				),
			)
		);
	}

	/**
	 * AJAX: register Telegram webhook.
	 */
	public static function ajax_register_webhook(): void {
		check_ajax_referer( 'wootg_admin', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'אין הרשאה.', 'woo-telegram-manager' ) ), 403 );
		}

		$token = self::get_decrypted_bot_token_for_requests();
		if ( $token === '' ) {
			wp_send_json_error( array( 'message' => __( 'יש להגדיר Bot Token תחילה.', 'woo-telegram-manager' ) ) );
		}

		$secret = (string) get_option( 'wootg_webhook_secret', '' );
		if ( $secret === '' ) {
			wp_send_json_error( array( 'message' => __( 'חסר מפתח Webhook. הפעל מחדש את התוסף.', 'woo-telegram-manager' ) ) );
		}

		$url  = site_url( '/wp-json/wootg/v1/webhook/' . $secret );
		$api  = 'https://api.telegram.org/bot' . rawurlencode( $token ) . '/setWebhook';
		$resp = wp_remote_post(
			$api,
			array(
				'timeout' => 15,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( array( 'url' => $url ) ),
			)
		);

		if ( is_wp_error( $resp ) ) {
			wp_send_json_error(
				array(
					'message' => $resp->get_error_message(),
				)
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $resp );
		$body = wp_remote_retrieve_body( $resp );
		$data = json_decode( $body, true );

		if ( $code >= 400 || ! is_array( $data ) || empty( $data['ok'] ) ) {
			$desc = is_array( $data ) && isset( $data['description'] ) ? (string) $data['description'] : $body;
			wp_send_json_error( array( 'message' => $desc ) );
		}

		wp_send_json_success(
			array(
				'message' => __( 'ה-Webhook נרשם בהצלחה.', 'woo-telegram-manager' ),
				'info'    => $data,
			)
		);
	}

	/**
	 * AJAX: delete Telegram webhook.
	 */
	public static function ajax_delete_webhook(): void {
		check_ajax_referer( 'wootg_admin', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'אין הרשאה.', 'woo-telegram-manager' ) ), 403 );
		}

		$token = self::get_decrypted_bot_token_for_requests();
		if ( $token === '' ) {
			wp_send_json_error( array( 'message' => __( 'יש להגדיר Bot Token תחילה.', 'woo-telegram-manager' ) ) );
		}

		$api  = 'https://api.telegram.org/bot' . rawurlencode( $token ) . '/deleteWebhook';
		$resp = wp_remote_post(
			$api,
			array(
				'timeout' => 15,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( array() ),
			)
		);

		if ( is_wp_error( $resp ) ) {
			wp_send_json_error( array( 'message' => $resp->get_error_message() ) );
		}

		$code = (int) wp_remote_retrieve_response_code( $resp );
		$body = wp_remote_retrieve_body( $resp );
		$data = json_decode( $body, true );

		if ( $code >= 400 || ! is_array( $data ) || empty( $data['ok'] ) ) {
			$desc = is_array( $data ) && isset( $data['description'] ) ? (string) $data['description'] : $body;
			wp_send_json_error( array( 'message' => $desc ) );
		}

		wp_send_json_success(
			array(
				'message' => __( 'ה-Webhook נותק.', 'woo-telegram-manager' ),
				'info'    => $data,
			)
		);
	}

	/**
	 * AJAX: getWebhookInfo.
	 */
	public static function ajax_webhook_status(): void {
		check_ajax_referer( 'wootg_admin', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'אין הרשאה.', 'woo-telegram-manager' ) ), 403 );
		}

		$token = self::get_decrypted_bot_token_for_requests();
		if ( $token === '' ) {
			wp_send_json_success(
				array(
					'registered' => false,
					'label'      => __( 'אין Bot Token', 'woo-telegram-manager' ),
					'raw'        => null,
				)
			);
		}

		$api  = 'https://api.telegram.org/bot' . rawurlencode( $token ) . '/getWebhookInfo';
		$resp = wp_remote_get(
			$api,
			array(
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $resp ) ) {
			wp_send_json_error( array( 'message' => $resp->get_error_message() ) );
		}

		$code = (int) wp_remote_retrieve_response_code( $resp );
		$body = wp_remote_retrieve_body( $resp );
		$data = json_decode( $body, true );

		if ( $code >= 400 || ! is_array( $data ) || empty( $data['ok'] ) ) {
			$desc = is_array( $data ) && isset( $data['description'] ) ? (string) $data['description'] : $body;
			wp_send_json_error( array( 'message' => $desc ) );
		}

		$result = isset( $data['result'] ) && is_array( $data['result'] ) ? $data['result'] : array();
		$url    = isset( $result['url'] ) ? (string) $result['url'] : '';
		$reg    = $url !== '';

		wp_send_json_success(
			array(
				'registered' => $reg,
				'label'      => $reg
					? __( 'Webhook רשום', 'woo-telegram-manager' )
					: __( 'Webhook לא רשום', 'woo-telegram-manager' ),
				'raw'        => $result,
			)
		);
	}

	/**
	 * AJAX: getMe — verify bot token.
	 */
	public static function ajax_test_bot(): void {
		check_ajax_referer( 'wootg_admin', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'אין הרשאה.', 'woo-telegram-manager' ) ), 403 );
		}

		$token = self::get_decrypted_bot_token_for_requests();
		if ( $token === '' ) {
			wp_send_json_error( array( 'message' => __( 'בוט טוקן לא הוגדר. יש להזין טוקן ולשמור הגדרות תחילה.', 'woo-telegram-manager' ) ) );
			return;
		}

		try {
			$telegram = new WooTG_Telegram();
			$res      = $telegram->get_me();
		} catch ( \Throwable $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
			return;
		}

		if ( null === $res || empty( $res['ok'] ) || empty( $res['result'] ) || ! is_array( $res['result'] ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'לא התקבלה תשובה תקינה מטלגרם.', 'woo-telegram-manager' ),
				)
			);
			return;
		}

		$bot = $res['result'];

		wp_send_json_success(
			array(
				'ok'         => true,
				'first_name' => isset( $bot['first_name'] ) ? (string) $bot['first_name'] : '',
				'username'   => isset( $bot['username'] ) ? (string) $bot['username'] : '',
				'is_bot'     => ! empty( $bot['is_bot'] ),
				'bot_id'     => isset( $bot['id'] ) ? (int) $bot['id'] : null,
			)
		);
	}

	/**
	 * AJAX: recent activity log rows.
	 */
	public static function ajax_get_logs(): void {
		check_ajax_referer( 'wootg_admin', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'אין הרשאה.', 'woo-telegram-manager' ) ), 403 );
		}

		$rows = WooTG_Logger::get_recent( 20 );
		$out  = array();

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$out[] = array(
				'id'            => isset( $row['id'] ) ? (int) $row['id'] : 0,
				'created_at'    => isset( $row['created_at'] ) ? (string) $row['created_at'] : '',
				'action'        => isset( $row['action'] ) ? (string) $row['action'] : '',
				'status'        => isset( $row['status'] ) ? (string) $row['status'] : '',
				'error_message' => isset( $row['error_message'] ) && is_string( $row['error_message'] ) ? $row['error_message'] : '',
			);
		}

		wp_send_json_success( array( 'rows' => $out ) );
	}

	/**
	 * AJAX: regenerate webhook secret and re-register with Telegram.
	 */
	public static function ajax_regenerate_webhook_secret(): void {
		check_ajax_referer( 'wootg_admin', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'אין הרשאה.', 'woo-telegram-manager' ) ), 403 );
		}

		$new_secret = wp_generate_password( 32, false, false );
		update_option( 'wootg_webhook_secret', $new_secret );

		$new_url = site_url( '/wp-json/wootg/v1/webhook/' . $new_secret );

		$token = self::get_decrypted_bot_token_for_requests();
		if ( $token === '' ) {
			wp_send_json_success(
				array(
					'message'     => __( 'Secret חודש. אין Bot Token — Webhook לא נרשם אוטומטית.', 'woo-telegram-manager' ),
					'webhook_url' => $new_url,
				)
			);
		}

		$api  = 'https://api.telegram.org/bot' . rawurlencode( $token ) . '/setWebhook';
		$resp = wp_remote_post(
			$api,
			array(
				'timeout' => 15,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( array( 'url' => $new_url ) ),
			)
		);

		if ( is_wp_error( $resp ) ) {
			wp_send_json_error(
				array(
					'message'     => $resp->get_error_message(),
					'webhook_url' => $new_url,
				)
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $resp );
		$body = wp_remote_retrieve_body( $resp );
		$data = json_decode( $body, true );

		if ( $code >= 400 || ! is_array( $data ) || empty( $data['ok'] ) ) {
			$desc = is_array( $data ) && isset( $data['description'] ) ? (string) $data['description'] : $body;
			wp_send_json_error(
				array(
					'message'     => $desc,
					'webhook_url' => $new_url,
				)
			);
		}

		wp_send_json_success(
			array(
				'message'     => __( 'Secret חודש ו-Webhook נרשם מחדש בהצלחה.', 'woo-telegram-manager' ),
				'webhook_url' => $new_url,
			)
		);
	}

	/**
	 * Decrypted bot token for Telegram API, or empty.
	 */
	private static function get_decrypted_bot_token_for_requests(): string {
		$settings = get_option( 'wootg_settings', array() );
		if ( ! is_array( $settings ) || empty( $settings['bot_token'] ) ) {
			return '';
		}

		return self::get_plain_bot_token_from_stored( (string) $settings['bot_token'] );
	}

	/**
	 * Turn stored value (encrypted or legacy plain) into plaintext token.
	 */
	private static function get_plain_bot_token_from_stored( string $stored ): string {
		$stored = trim( $stored );
		if ( $stored === '' ) {
			return '';
		}

		$decrypted = WooTG_Crypto::decrypt( $stored );
		if ( $decrypted !== '' ) {
			return $decrypted;
		}

		return self::is_plausible_telegram_bot_token( $stored ) ? $stored : '';
	}

	/**
	 * Loose format check for legacy plaintext tokens.
	 */
	private static function is_plausible_telegram_bot_token( string $token ): bool {
		return 1 === preg_match( '/^[0-9]{6,12}:[A-Za-z0-9_-]{30,50}$/', $token );
	}

	public static function show_invalid_token_notice(): void {
		if ( ! get_transient( 'wootg_invalid_token_notice' ) ) {
			return;
		}
		delete_transient( 'wootg_invalid_token_notice' );
		echo '<div class="notice notice-error is-dismissible"><p>' .
			esc_html__( 'טוקן בוט לא תקין — לא נשמר', 'woo-telegram-manager' ) .
			'</p></div>';
	}

}
