<?php
/**
 * Base class for Telegram conversation flows.
 *
 * @package WooTelegram_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared helpers for flow handlers.
 */
abstract class WooTG_Flow_Base {

	protected WooTG_Telegram $telegram;

	protected int $chat_id;

	protected int $user_id;

	/**
	 * Session snapshot from WooTG_Session::get() or null.
	 *
	 * @var array<string, mixed>|null
	 */
	protected ?array $session;

	/**
	 * Raw Telegram update.
	 *
	 * @var array<string, mixed>
	 */
	protected array $update;

	/**
	 * @param array<string, mixed>      $update  Telegram update.
	 * @param array<string, mixed>|null $session Session row or null.
	 */
	public function __construct( array $update, ?array $session ) {
		$this->update  = $update;
		$this->session = $session;

		list( $chat, $user ) = self::extract_chat_and_user( $update );
		$this->chat_id = $chat > 0 ? $chat : 0;
		$this->user_id = $user > 0 ? $user : 0;

		$this->telegram = new WooTG_Telegram();
	}

	abstract public function handle(): void;

	protected function send( string $text, ?array $keyboard = null ): void {
		$this->telegram->send_message( $this->chat_id, $text, $keyboard, 'HTML' );
	}

	protected function answer_callback( string $text = '', bool $alert = false ): void {
		$id = $this->extract_callback_query_id();
		if ( null === $id ) {
			return;
		}

		$notify = '' !== $text ? $text : null;
		$this->telegram->answer_callback_query( $id, $notify, $alert );
	}

	protected function extract_text(): ?string {
		if ( isset( $this->update['message']['text'] ) && is_string( $this->update['message']['text'] ) ) {
			return $this->update['message']['text'];
		}

		if ( isset( $this->update['message']['caption'] ) && is_string( $this->update['message']['caption'] ) ) {
			return $this->update['message']['caption'];
		}

		return null;
	}

	protected function extract_callback_data(): ?string {
		if ( ! isset( $this->update['callback_query'] ) || ! is_array( $this->update['callback_query'] ) ) {
			return null;
		}

		$cq = $this->update['callback_query'];

		return isset( $cq['data'] ) && is_string( $cq['data'] ) ? $cq['data'] : null;
	}

	protected function extract_photo_file_id(): ?string {
		if ( ! isset( $this->update['message']['photo'] ) || ! is_array( $this->update['message']['photo'] ) ) {
			return null;
		}

		$photos = $this->update['message']['photo'];
		if ( array() === $photos ) {
			return null;
		}

		$last = end( $photos );
		if ( ! is_array( $last ) ) {
			return null;
		}

		return isset( $last['file_id'] ) && is_string( $last['file_id'] ) ? $last['file_id'] : null;
	}

	protected function extract_callback_query_id(): ?string {
		if ( ! isset( $this->update['callback_query'] ) || ! is_array( $this->update['callback_query'] ) ) {
			return null;
		}

		$cq = $this->update['callback_query'];

		return isset( $cq['id'] ) && is_string( $cq['id'] ) ? $cq['id'] : null;
	}

	/**
	 * @param array<string, mixed> $update Update.
	 * @return array{0: int, 1: int}
	 */
	protected static function extract_chat_and_user( array $update ): array {
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

		return array( $chat_id, $user_id );
	}
}
