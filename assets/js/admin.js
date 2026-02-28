/* global jQuery, apcdVars */
(function($){
	'use strict';

	function renderResult(container, data) {
		var html = '';
		html += '<div class="apcd-status ' + String(data.status_class) + '">';
		html += '<strong>' + String(data.status_label) + '</strong>';
		html += '</div>';

		if (data.conflicting) {
			var name = data.conflicting_name ? String(data.conflicting_name) : String(data.conflicting);
			var path = String(data.conflicting);
			html += '<p><strong>' + String(apcdVars.strings.conflictingPlugin) + ':</strong> ' + $('<div>').text(name).html() + ' <small>(' + $('<div>').text(path).html() + ')</small></p>';
		}
		if (data.conflicting_set && data.conflicting_set.length) {
			var names = data.conflicting_set_names || [];
			html += '<div class="apcd-confset"><strong>' + String(apcdVars.strings.conflictingPlugin) + ' (set)' + ':</strong><ul>';
			for (var k = 0; k < data.conflicting_set.length; k++) {
				var nm = names[k] ? String(names[k]) : String(data.conflicting_set[k]);
				var ph = String(data.conflicting_set[k]);
				html += '<li>' + $('<div>').text(nm).html() + ' <small>(' + $('<div>').text(ph).html() + ')</small></li>';
			}
			html += '</ul></div>';
		}
		if (data.errors_from_log && data.errors_from_log.length) {
			html += '<div class="apcd-errors"><strong>' + String(apcdVars.strings.recentFatalErrors) + ':</strong><ul>';
			for (var i = 0; i < data.errors_from_log.length; i++) {
				html += '<li>' + $('<div>').text(data.errors_from_log[i].message).html() + '</li>';
			}
			html += '</ul></div>';
		}
		if (data.targets_report) {
			var tr = data.targets_report;
			var keys = Object.keys(tr);
			if (keys.length) {
				html += '<div class="apcd-targets"><strong>' + String(apcdVars.strings.targetsStatus) + ':</strong><ul>';
				for (var j = 0; j < keys.length; j++) {
					var k = keys[j];
					var code = tr[k] && tr[k].code != null ? tr[k].code : 0;
					var ok = tr[k] && tr[k].ok ? true : false;
					var badge = ok ? '<span class="apcd-badge apcd-green">200</span>' : '<span class="apcd-badge apcd-red">' + String(code) + '</span>';
					html += '<li>' + $('<div>').text(k).html() + ' ' + badge + '</li>';
				}
				html += '</ul></div>';
			}
		}
		if (data.mitigation && data.mitigation.deactivated && data.mitigation.deactivated.length) {
			html += '<div class="apcd-mitigation"><strong>Deactivated:</strong><ul>';
			for (var m = 0; m < data.mitigation.deactivated.length; m++) {
				html += '<li>' + $('<div>').text(String(data.mitigation.deactivated[m])).html() + '</li>';
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
		var targetUrl = String($('#apcd-target-url').val() || '').trim();
		var autoMitigate = $('#apcd-auto-mitigate').is(':checked') ? '1' : '';

		$btn.prop('disabled', true);
		$result.empty();
		$bar.css('width', '5%');

		$.ajax({
			url: apcdVars.ajaxUrl,
			method: 'POST',
			dataType: 'json',
			data: {
				action: 'apcd_run_scan',
				nonce: apcdVars.nonce,
				target_url: targetUrl,
				auto_mitigate: autoMitigate
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

	function drawBarChart(canvas, map) {
		var ctx = canvas.getContext('2d');
		ctx.clearRect(0, 0, canvas.width, canvas.height);
		var keys = Object.keys(map);
		var vals = keys.map(function(k){ return map[k]; });
		var max = Math.max.apply(null, vals.concat([1]));
		var w = canvas.width, h = canvas.height;
		var barW = Math.max(20, Math.floor((w - 40) / Math.max(1, keys.length)));
		var maxLabels = Math.max(1, Math.floor((w - 40) / 80));
		var labelEvery = Math.max(1, Math.ceil(keys.length / maxLabels));
		ctx.textAlign = 'center';
		ctx.textBaseline = 'alphabetic';
		keys.forEach(function(k, i){
			var v = map[k];
			var x = 20 + i * barW;
			var barH = Math.floor((h - 40) * (v / max));
			var y = h - 20 - barH;
			ctx.fillStyle = '#2271b1';
			ctx.fillRect(x, y, barW - 8, barH);
			ctx.fillStyle = '#333';
			ctx.font = '12px sans-serif';
			if (i % labelEvery === 0) {
				var label = String(k);
				if (label.length > 12) label = label.slice(0, 11) + '…';
				ctx.fillText(label, x + (barW - 8)/2, h - 6);
			}
			ctx.fillText(String(v), x + (barW - 8)/2, y - 4);
		});
	}

	function drawPieChart(canvas, map) {
		var ctx = canvas.getContext('2d');
		ctx.clearRect(0, 0, canvas.width, canvas.height);
		var keys = Object.keys(map);
		var total = keys.reduce(function(sum, k){ return sum + (map[k] || 0); }, 0) || 1;
		var cx = canvas.width / 2, cy = canvas.height / 2, r = Math.min(cx, cy) - 10;
		var start = 0;
		var colors = ['#46b450','#ffb900','#dc3232','#2271b1','#777','#8f6aff','#00a0d2'];
		keys.forEach(function(k, i){
			var v = map[k] || 0;
			var angle = (v / total) * Math.PI * 2;
			ctx.beginPath();
			ctx.moveTo(cx, cy);
			ctx.arc(cx, cy, r, start, start + angle, false);
			ctx.closePath();
			ctx.fillStyle = colors[i % colors.length];
			ctx.fill();
			start += angle;
		});
		ctx.fillStyle = '#333';
		ctx.font = '12px sans-serif';
		var y = 16;
		var legendKeys = keys.slice(0, 8);
		legendKeys.forEach(function(k, i){
			ctx.fillStyle = colors[i % colors.length];
			ctx.fillRect(8, y - 10, 10, 10);
			ctx.fillStyle = '#333';
			var label = String(k);
			if (label.length > 28) label = label.slice(0, 27) + '…';
			ctx.fillText(label + ' (' + String(map[k]) + ')', 24, y);
			y += 16;
		});
	}

	function drawLineChart(canvas, map) {
		var ctx = canvas.getContext('2d');
		ctx.clearRect(0, 0, canvas.width, canvas.height);
		var keys = Object.keys(map).sort();
		var vals = keys.map(function(k){ return map[k]; });
		var max = Math.max.apply(null, vals.concat([1]));
		var w = canvas.width, h = canvas.height;
		var stepX = Math.max(20, Math.floor((w - 40) / Math.max(1, keys.length)));
		ctx.strokeStyle = '#2271b1';
		ctx.lineWidth = 2;
		ctx.beginPath();
		keys.forEach(function(k, i){
			var v = map[k];
			var x = 20 + i * stepX;
			var y = h - 20 - Math.floor((h - 40) * (v / max));
			if (i === 0) ctx.moveTo(x, y); else ctx.lineTo(x, y);
		});
		ctx.stroke();
		ctx.fillStyle = '#333';
		ctx.font = '12px sans-serif';
		ctx.textAlign = 'center';
		ctx.textBaseline = 'alphabetic';
		var maxLabels = Math.max(1, Math.floor((w - 40) / 80));
		var labelEvery = Math.max(1, Math.ceil(keys.length / maxLabels));
		keys.forEach(function(k, i){
			var v = map[k];
			var x = 20 + i * stepX;
			var y = h - 20 - Math.floor((h - 40) * (v / max));
			ctx.beginPath();
			ctx.arc(x, y, 3, 0, Math.PI*2);
			ctx.fill();
			if (i % labelEvery === 0) {
				var label = String(k);
				if (label.length > 12) label = label.slice(0, 11) + '…';
				ctx.fillText(label, x, h - 6);
			}
		});
	}

	function fetchAndRenderAnalysis() {
		var $btn = $('#apcd-refresh-analysis');
		$btn.prop('disabled', true);
		$.ajax({
			url: apcdVars.ajaxUrl,
			method: 'POST',
			dataType: 'json',
			data: { action: 'apcd_get_analysis' }
		}).done(function(resp){
			if (resp && resp.success) {
				var data = resp.data || {};
				var c1 = document.getElementById('apcd-chart-status');
				var c2 = document.getElementById('apcd-chart-conflicts');
				var c3 = document.getElementById('apcd-chart-errors');
				if (c1) drawBarChart(c1, data.statuses || {});
				if (c2) drawPieChart(c2, data.conflicts || {});
				if (c3) drawLineChart(c3, data.errors_over_time || {});
			}
		}).always(function(){
			setTimeout(function(){ $btn.prop('disabled', false); }, 300);
		});
	}

	$(function(){
		if ($('#apcd-analysis').length) {
			fetchAndRenderAnalysis();
			$(document).on('click', '#apcd-refresh-analysis', function(e){
				e.preventDefault();
				fetchAndRenderAnalysis();
			});
		}
	});
})(jQuery);
