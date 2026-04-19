<?php
/**
 * Admin settings page markup.
 *
 * @package WooTelegram_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! current_user_can( 'manage_woocommerce' ) ) {
	return;
}

$settings       = get_option( 'wootg_settings', array() );
$webhook_secret = (string) get_option( 'wootg_webhook_secret', '' );
$webhook_url    = $webhook_secret !== ''
	? site_url( '/wp-json/wootg/v1/webhook/' . $webhook_secret )
	: '';

$chat_ids = isset( $settings['authorized_chat_ids'] ) && is_array( $settings['authorized_chat_ids'] )
	? $settings['authorized_chat_ids']
	: array();
$chat_lines = implode( "\n", array_map( 'strval', $chat_ids ) );

?>
<div class="wrap wootg-settings-wrap" dir="rtl">
	<h1 class="wootg-page-title">
		<span class="wootg-page-title__icon" aria-hidden="true">📱</span>
		<?php echo esc_html( get_admin_page_title() ); ?>
	</h1>

	<form action="options.php" method="post" class="wootg-settings-form">
		<?php settings_fields( 'wootg_settings_group' ); ?>

		<div class="wootg-card">
			<h2 class="wootg-card__title"><?php esc_html_e( 'הגדרות בוט', 'woo-telegram-manager' ); ?></h2>
			<p class="wootg-card__desc"><?php esc_html_e( 'הטוקן נשמר מוצפן במסד הנתונים.', 'woo-telegram-manager' ); ?></p>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">
						<label for="wootg-bot-token"><?php esc_html_e( 'Bot Token', 'woo-telegram-manager' ); ?></label>
					</th>
					<td>
						<input
							type="password"
							id="wootg-bot-token"
							name="wootg_settings[bot_token]"
							class="regular-text wootg-bot-token-field"
							value=""
							autocomplete="off"
							placeholder="<?php esc_attr_e( 'הזן טוקן חדש לשינוי (ריק = ללא שינוי)', 'woo-telegram-manager' ); ?>"
						/>
						<button type="button" class="button wootg-toggle-token" aria-pressed="false">
							<?php esc_html_e( 'הצג / הסתר', 'woo-telegram-manager' ); ?>
						</button>
					</td>
				</tr>
			</table>
		</div>

		<div class="wootg-card">
			<h2 class="wootg-card__title"><?php esc_html_e( 'משתמשים מורשים', 'woo-telegram-manager' ); ?></h2>
			<p class="wootg-card__desc">
				<?php esc_html_e( 'Chat ID אחד בכל שורה. ניתן לקבל Chat ID מבוט כמו', 'woo-telegram-manager' ); ?>
				<a href="https://t.me/userinfobot" target="_blank" rel="noopener noreferrer">@userinfobot</a>.
			</p>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">
						<label for="wootg-chat-ids"><?php esc_html_e( 'Chat IDs', 'woo-telegram-manager' ); ?></label>
					</th>
					<td>
						<textarea
							id="wootg-chat-ids"
							name="wootg_settings[authorized_chat_ids]"
							rows="6"
							class="large-text code"
						><?php echo esc_textarea( $chat_lines ); ?></textarea>
					</td>
				</tr>
			</table>
		</div>

		<div class="wootg-card">
			<h2 class="wootg-card__title"><?php esc_html_e( 'Webhook', 'woo-telegram-manager' ); ?></h2>
			<p class="wootg-card__desc"><?php esc_html_e( 'טלגרם דורש HTTPS לרישום Webhook.', 'woo-telegram-manager' ); ?></p>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'כתובת Webhook', 'woo-telegram-manager' ); ?></th>
					<td>
						<input
							type="text"
							readonly
							class="large-text code"
							value="<?php echo esc_attr( $webhook_url ); ?>"
						/>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'סטטוס', 'woo-telegram-manager' ); ?></th>
					<td>
						<p class="wootg-webhook-status" id="wootg-webhook-status" data-state="loading">
							<span class="wootg-webhook-status__emoji" aria-hidden="true">🟡</span>
							<span class="wootg-webhook-status__text"><?php esc_html_e( 'טוען…', 'woo-telegram-manager' ); ?></span>
						</p>
						<p class="submit wootg-webhook-actions">
							<button type="button" class="button button-primary" id="wootg-register-webhook">
								<?php esc_html_e( 'רשום Webhook', 'woo-telegram-manager' ); ?>
							</button>
							<button type="button" class="button" id="wootg-delete-webhook">
								<?php esc_html_e( 'נתק Webhook', 'woo-telegram-manager' ); ?>
							</button>
							<button type="button" class="button" id="wootg-check-webhook">
								<?php esc_html_e( 'בדוק סטטוס', 'woo-telegram-manager' ); ?>
							</button>
						</p>
					</td>
				</tr>
			</table>
		</div>

		<?php submit_button( __( 'שמור הגדרות', 'woo-telegram-manager' ) ); ?>
	</form>
</div>
