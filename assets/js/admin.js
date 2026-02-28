/* global jQuery, apcdVars */
(function($){
	'use strict';

	function renderResult(container, data) {
		var html = '';
		html += '<div class="apcd-status ' + String(data.status_class) + '">';
		html += '<strong>' + String(data.status_label) + '</strong>';
		html += '</div>';

		if (data.conflicting) {
			html += '<p><strong>' + String(apcdVars.strings.conflictingPlugin) + ':</strong> ' + $('<div>').text(String(data.conflicting)).html() + '</p>';
		}
		if (data.errors_from_log && data.errors_from_log.length) {
			html += '<div class="apcd-errors"><strong>' + String(apcdVars.strings.recentFatalErrors) + ':</strong><ul>';
			for (var i = 0; i < data.errors_from_log.length; i++) {
				html += '<li>' + $('<div>').text(data.errors_from_log[i].message).html() + '</li>';
			}
			html += '</ul></div>';
		}
		$(container).html(html);
	}

	$(document).on('click', '#apcd-run-scan', function(e){
		e.preventDefault();
		var $btn = $(this);
		var $bar = $('#apcd-progress-bar');
		var $result = $('#apcd-scan-result');

		$btn.prop('disabled', true);
		$result.empty();
		$bar.css('width', '5%');

		$.ajax({
			url: apcdVars.ajaxUrl,
			method: 'POST',
			dataType: 'json',
			data: {
				action: 'apcd_run_scan',
				nonce: apcdVars.nonce
			}
		}).done(function(resp){
			$bar.css('width', '100%');
			if (resp && resp.success) {
				renderResult($result, resp.data || {});
			} else {
				$result.text(apcdVars.strings.error);
			}
		}).fail(function(){
			$bar.css('width', '100%');
			$result.text(apcdVars.strings.error);
		}).always(function(){
			setTimeout(function(){
				$btn.prop('disabled', false);
			}, 500);
		});
	});
})(jQuery);
