<?php
/**
 * Telegram Bot API wrapper (wp_remote_* only).
 *
 * @package WooTelegram_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Thin client for api.telegram.org.
 */
class WooTG_Telegram {

	/**
	 * Plain bot token.
	 */
	private string $token;

	/**
	 * Base URL including token prefix.
	 */
	private string $api_base;

	/**
	 * @param string|null $bot_token Plain token, or null to load from options (decrypted).
	 *
	 * @throws \RuntimeException When token is missing or invalid.
	 */
	public function __construct( ?string $bot_token = null ) {
		$plain = self::resolve_plain_token( $bot_token );
		if ( '' === $plain ) {
			throw new \RuntimeException( 'Missing or invalid Telegram bot token.' );
		}

		$this->token    = $plain;
		$this->api_base = 'https://api.telegram.org/bot' . $this->token . '/';
	}

	/**
	 * Send a text message.
	 */
	public function send_message( int $chat_id, string $text, ?array $reply_markup = null, string $parse_mode = 'HTML' ): ?array {
		$params = array(
			'chat_id'    => $chat_id,
			'text'       => $text,
			'parse_mode' => $parse_mode,
		);
		if ( null !== $reply_markup ) {
			$params['reply_markup'] = $reply_markup;
		}

		return $this->request( 'sendMessage', $params );
	}

	/**
	 * Edit an existing message.
	 */
	public function edit_message_text( int $chat_id, int $message_id, string $text, ?array $reply_markup = null ): ?array {
		$params = array(
			'chat_id'    => $chat_id,
			'message_id' => $message_id,
			'text'       => $text,
			'parse_mode' => 'HTML',
		);
		if ( null !== $reply_markup ) {
			$params['reply_markup'] = $reply_markup;
		}

		return $this->request( 'editMessageText', $params );
	}

	/**
	 * Answer a callback query.
	 */
	public function answer_callback_query( string $callback_query_id, ?string $text = null, bool $show_alert = false ): ?array {
		$params = array(
			'callback_query_id' => $callback_query_id,
			'show_alert'        => $show_alert,
		);
		if ( null !== $text ) {
			$params['text'] = $text;
		}

		return $this->request( 'answerCallbackQuery', $params );
	}

	/**
	 * Send chat action (typing, etc.).
	 */
	public function send_chat_action( int $chat_id, string $action = 'typing' ): ?array {
		return $this->request(
			'sendChatAction',
			array(
				'chat_id' => $chat_id,
				'action'  => $action,
			)
		);
	}

	/**
	 * Resolve file by file_id.
	 *
	 * @return array{file_path: string, file_url: string, file_size: int}|null
	 */
	public function get_file( string $file_id ): ?array {
		$res = $this->request( 'getFile', array( 'file_id' => $file_id ) );
		if ( null === $res || empty( $res['result'] ) || ! is_array( $res['result'] ) ) {
			return null;
		}

		$file = $res['result'];
		$path = isset( $file['file_path'] ) ? (string) $file['file_path'] : '';
		if ( '' === $path ) {
			$this->log_telegram_failure( 'getFile', 'Missing file_path in result', array( 'result' => $file ) );
			return null;
		}

		return array(
			'file_path' => $path,
			'file_url'  => 'https://api.telegram.org/file/bot' . $this->token . '/' . $path,
			'file_size' => isset( $file['file_size'] ) ? (int) $file['file_size'] : 0,
		);
	}

	/**
	 * Register webhook URL (optional secret_token for Telegram header validation).
	 */
	public function set_webhook( string $url, ?string $secret_token = null ): ?array {
		$params = array( 'url' => $url );
		if ( null !== $secret_token && '' !== $secret_token ) {
			$params['secret_token'] = $secret_token;
		}

		return $this->request( 'setWebhook', $params );
	}

	/**
	 * Remove webhook.
	 */
	public function delete_webhook(): ?array {
		return $this->request( 'deleteWebhook', array() );
	}

	/**
	 * Current webhook configuration.
	 */
	public function get_webhook_info(): ?array {
		return $this->request( 'getWebhookInfo', array(), 'GET' );
	}

	/**
	 * Bot identity (@username, etc.).
	 */
	public function get_me(): ?array {
		return $this->request( 'getMe', array(), 'GET' );
	}

	/**
	 * Escape text for parse_mode=HTML.
	 */
	public static function escape_html( string $text ): string {
		return str_replace(
			array( '&', '<', '>' ),
			array( '&amp;', '&lt;', '&gt;' ),
			$text
		);
	}

	/**
	 * Resolve plaintext token from argument or options.
	 */
	private static function resolve_plain_token( ?string $bot_token ): string {
		if ( null !== $bot_token ) {
			$t = trim( $bot_token );
			if ( '' !== $t ) {
				return $t;
			}
		}

		$settings = get_option( 'wootg_settings', array() );
		if ( ! is_array( $settings ) || ! isset( $settings['bot_token'] ) ) {
			return '';
		}

		$raw = trim( (string) $settings['bot_token'] );
		if ( '' === $raw ) {
			return '';
		}

		$decrypted = WooTG_Crypto::decrypt( $raw );
		if ( '' !== $decrypted ) {
			return $decrypted;
		}

		return self::is_plausible_bot_token( $raw ) ? $raw : '';
	}

	/**
	 * Legacy plaintext token heuristic.
	 */
	private static function is_plausible_bot_token( string $token ): bool {
		return 1 === preg_match( '/^[0-9]{6,}:[A-Za-z0-9_-]{30,}$/', $token );
	}

	/**
	 * Central Telegram API request.
	 *
	 * @param array<string, mixed> $params Request JSON body (POST) or query (GET).
	 */
	private function request( string $method, array $params = array(), string $http_method = 'POST' ): ?array {
		$url         = $this->api_base . $method;
		$http_method = strtoupper( $http_method );

		try {
			if ( 'GET' === $http_method ) {
				if ( ! empty( $params ) ) {
					$url = add_query_arg( $params, $url );
				}
				$response = wp_remote_get(
					$url,
					array(
						'timeout' => 15,
					)
				);
			} else {
				$response = wp_remote_post(
					$url,
					array(
						'timeout' => 15,
						'headers' => array(
							'Content-Type' => 'application/json',
						),
						'body'    => wp_json_encode( $params ),
					)
				);
			}
		} catch ( \Throwable $e ) {
			$this->log_telegram_failure(
				$method,
				$e->getMessage(),
				array(
					'exception' => get_class( $e ),
				)
			);
			return null;
		}

		if ( is_wp_error( $response ) ) {
			$this->log_telegram_failure( $method, $response->get_error_message(), array( 'params' => $params ) );
			return null;
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( ! is_array( $data ) ) {
			$this->log_telegram_failure(
				$method,
				'Invalid JSON response',
				array(
					'body' => $body,
				)
			);
			return null;
		}

		if ( empty( $data['ok'] ) ) {
			$desc = isset( $data['description'] ) ? (string) $data['description'] : 'Telegram API error';
			$this->log_telegram_failure(
				$method,
				$desc,
				array(
					'response' => $data,
				)
			);
			return null;
		}

		return $data;
	}

	/**
	 * Route failures to WooTG_Logger when available (Phase 2 §2 implements full logger).
	 *
	 * @param array<string, mixed> $details Context.
	 */
	private function log_telegram_failure( string $telegram_method, string $message, array $details = array() ): void {
		if ( class_exists( 'WooTG_Logger' ) ) {
			WooTG_Logger::log_error( 'telegram:' . $telegram_method, $message, $details );
			return;
		}

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log(
			'WooTG_Telegram::' . $telegram_method . ' ' . $message . ' ' . wp_json_encode( $details, JSON_UNESCAPED_UNICODE )
		);
	}
}
