/* global jQuery, tttAdmin */
/**
 * Admin JS for the settings page.
 * Exactly one function: the "Clear cache now" button via AJAX to admin-ajax.php.
 */
(function ($) {
	'use strict';

	$(function () {
		var $btn = $('#ttt-clear-cache');
		var $msg = $('#ttt-clear-cache-msg');

		if (!$btn.length) {
			return;
		}

		function setState(state, text) {
			$msg
				.removeClass('ttt-clear-msg-pending ttt-clear-msg-success ttt-clear-msg-error')
				.addClass('ttt-clear-msg-' + state)
				.text(text);
		}

		$btn.on('click', function () {
			$btn.prop('disabled', true);
			setState('pending', tttAdmin.clearing);

			$.post(tttAdmin.ajaxUrl, {
				action: 'ttt_clear_cache',
				nonce: tttAdmin.nonce
			})
				.done(function (res) {
					if (res && res.success && res.data && res.data.message) {
						setState('success', res.data.message);
					} else {
						setState('error', tttAdmin.errorText);
					}
				})
				.fail(function () {
					setState('error', tttAdmin.errorText);
				})
				.always(function () {
					$btn.prop('disabled', false);
				});
		});
	});
})(jQuery);
