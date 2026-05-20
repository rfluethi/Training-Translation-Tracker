/* global jQuery, tttAdmin */
/**
 * Admin-JS für die Settings-Seite.
 * Genau eine Funktion: den „Cache jetzt leeren"-Knopf per AJAX an admin-ajax.php.
 */
(function ($) {
	'use strict';

	$(function () {
		var $btn = $('#ttt-clear-cache');
		var $msg = $('#ttt-clear-cache-msg');

		if (!$btn.length) {
			return;
		}

		$btn.on('click', function () {
			$btn.prop('disabled', true);
			$msg.css('color', '#666').text(tttAdmin.clearing);

			$.post(tttAdmin.ajaxUrl, {
				action: 'ttt_clear_cache',
				nonce: tttAdmin.nonce
			})
				.done(function (res) {
					if (res && res.success && res.data && res.data.message) {
						$msg.css('color', '#46b450').text(res.data.message);
					} else {
						$msg.css('color', '#dc3232').text(tttAdmin.errorText);
					}
				})
				.fail(function () {
					$msg.css('color', '#dc3232').text(tttAdmin.errorText);
				})
				.always(function () {
					$btn.prop('disabled', false);
				});
		});
	});
})(jQuery);
