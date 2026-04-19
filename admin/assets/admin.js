/**
 * WooTelegram Manager — admin settings.
 */
(function ($) {
	'use strict';

	function setStatus($el, state, text) {
		var emoji = '🟡';
		if (state === 'ok') {
			emoji = '🟢';
		} else if (state === 'bad') {
			emoji = '🔴';
		} else if (state === 'warn') {
			emoji = '🟡';
		} else if (state === 'loading') {
			emoji = '🟡';
		}
		$el.attr('data-state', state);
		$el.find('.wootg-webhook-status__emoji').text(emoji);
		$el.find('.wootg-webhook-status__text').text(text);
	}

	function fetchWebhookStatus() {
		var $status = $('#wootg-webhook-status');
		setStatus($status, 'loading', wootgAdmin.i18n.loading);

		$.post(
			wootgAdmin.ajaxUrl,
			{
				action: 'wootg_webhook_status',
				nonce: wootgAdmin.nonce
			}
		)
			.done(function (res) {
				if (!res || !res.success || !res.data) {
					setStatus($status, 'bad', wootgAdmin.i18n.errorGeneric);
					return;
				}
				var registered = !!res.data.registered;
				var label = res.data.label || (registered ? wootgAdmin.i18n.registered : wootgAdmin.i18n.notRegistered);
				setStatus($status, registered ? 'ok' : 'bad', label);
			})
			.fail(function () {
				setStatus($status, 'bad', wootgAdmin.i18n.errorGeneric);
			});
	}

	$(function () {
		var $token = $('#wootg-bot-token');
		var $toggle = $('.wootg-toggle-token');

		$toggle.on('click', function () {
			var isPassword = $token.attr('type') === 'password';
			$token.attr('type', isPassword ? 'text' : 'password');
			$(this).attr('aria-pressed', isPassword ? 'true' : 'false');
		});

		$('#wootg-register-webhook').on('click', function () {
			var $btn = $(this);
			var $status = $('#wootg-webhook-status');
			$btn.prop('disabled', true);
			setStatus($status, 'loading', wootgAdmin.i18n.loading);
			$.post(
				wootgAdmin.ajaxUrl,
				{
					action: 'wootg_register_webhook',
					nonce: wootgAdmin.nonce
				}
			)
				.always(function () {
					$btn.prop('disabled', false);
				})
				.done(function (res) {
					if (res && res.success) {
						fetchWebhookStatus();
					} else {
						var msg =
							res && res.data && res.data.message
								? res.data.message
								: wootgAdmin.i18n.errorGeneric;
						setStatus($status, 'bad', msg);
					}
				})
				.fail(function () {
					setStatus($status, 'bad', wootgAdmin.i18n.errorGeneric);
				});
		});

		$('#wootg-delete-webhook').on('click', function () {
			if (!window.confirm(wootgAdmin.i18n.confirmDelete)) {
				return;
			}
			var $btn = $(this);
			var $status = $('#wootg-webhook-status');
			$btn.prop('disabled', true);
			setStatus($status, 'loading', wootgAdmin.i18n.loading);
			$.post(
				wootgAdmin.ajaxUrl,
				{
					action: 'wootg_delete_webhook',
					nonce: wootgAdmin.nonce
				}
			)
				.always(function () {
					$btn.prop('disabled', false);
				})
				.done(function (res) {
					if (res && res.success) {
						fetchWebhookStatus();
					} else {
						var msg =
							res && res.data && res.data.message
								? res.data.message
								: wootgAdmin.i18n.errorGeneric;
						setStatus($status, 'bad', msg);
					}
				})
				.fail(function () {
					setStatus($status, 'bad', wootgAdmin.i18n.errorGeneric);
				});
		});

		$('#wootg-check-webhook').on('click', function () {
			fetchWebhookStatus();
		});

		fetchWebhookStatus();
	});
})(jQuery);
