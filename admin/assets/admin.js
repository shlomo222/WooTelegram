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

		var $ghToken = $('#wootg-github-token');
		$('.wootg-toggle-github-token').on('click', function () {
			var isPassword = $ghToken.attr('type') === 'password';
			$ghToken.attr('type', isPassword ? 'text' : 'password');
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

		var logsTimer = null;
		var $logsBody = $('#wootg-logs-body');

		function renderLogs(rows) {
			$logsBody.empty();
			if (!rows || !rows.length) {
				$('<tr/>')
					.append(
						$('<td/>', { colspan: 4, text: wootgAdmin.i18n.logsEmpty })
					)
					.appendTo($logsBody);
				return;
			}
			rows.forEach(function (row) {
				$('<tr/>')
					.append($('<td/>').text(row.created_at || ''))
					.append($('<td/>').text(row.action || ''))
					.append($('<td/>').text(row.status || ''))
					.append($('<td/>').text(row.error_message || ''))
					.appendTo($logsBody);
			});
		}

		function fetchLogs() {
			if (!$logsBody.length) {
				return;
			}
			$.post(wootgAdmin.ajaxUrl, {
				action: 'wootg_get_logs',
				nonce: wootgAdmin.nonce
			})
				.done(function (res) {
					if (res && res.success && res.data && res.data.rows) {
						renderLogs(res.data.rows);
					} else {
						renderLogs([]);
					}
				})
				.fail(function () {
					renderLogs([]);
				});
		}

		if ($logsBody.length) {
			fetchLogs();
			logsTimer = window.setInterval(fetchLogs, 10000);
			$(window).on('beforeunload', function () {
				if (logsTimer) {
					window.clearInterval(logsTimer);
				}
			});
		}

		$('#wootg-test-bot').on('click', function () {
			var $btn = $(this);
			var $out = $('#wootg-test-bot-result');
			$btn.prop('disabled', true);
			$out.removeClass('is-ok is-bad').text(wootgAdmin.i18n.loading);
			$.post(wootgAdmin.ajaxUrl, {
				action: 'wootg_test_bot',
				nonce: wootgAdmin.nonce
			})
				.always(function () {
					$btn.prop('disabled', false);
				})
				.done(function (res) {
					if (res && res.success && res.data) {
						var d = res.data;
						var uname = d.username ? '@' + d.username : '';
						var line =
							wootgAdmin.i18n.testBotOk +
							': ' +
							(d.first_name || '') +
							(uname ? ' ' + uname : '') +
							(d.bot_id ? ' (ID ' + d.bot_id + ')' : '');
						$out.addClass('is-ok').removeClass('is-bad').text(line);
					} else {
						var msg =
							res && res.data && res.data.message
								? res.data.message
								: wootgAdmin.i18n.testBotFail;
						$out.addClass('is-bad').removeClass('is-ok').text(msg);
					}
				})
				.fail(function () {
					$out.addClass('is-bad').removeClass('is-ok').text(wootgAdmin.i18n.errorGeneric);
				});
		});

		$('#wootg-test-github').on('click', function () {
			var $btn = $(this);
			var $out = $('#wootg-github-test-result');
			$btn.prop('disabled', true);
			$out.removeClass('is-ok is-bad').text(wootgAdmin.i18n.loading);
			$.post(wootgAdmin.ajaxUrl, {
				action: 'wootg_test_github',
				nonce: wootgAdmin.nonce
			})
				.always(function () {
					$btn.prop('disabled', false);
				})
				.done(function (res) {
					if (res && res.success && res.data && res.data.message) {
						$out.addClass('is-ok').removeClass('is-bad').text(res.data.message);
					} else {
						var msg =
							res && res.data && res.data.message
								? res.data.message
								: wootgAdmin.i18n.errorGeneric;
						$out.addClass('is-bad').removeClass('is-ok').text(msg);
					}
				})
				.fail(function () {
					$out.addClass('is-bad').removeClass('is-ok').text(wootgAdmin.i18n.errorGeneric);
				});
		});
	});
})(jQuery);
