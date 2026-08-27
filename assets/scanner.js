jQuery(function ($) {
	'use strict';

	const $buttons = $('.oras-ai-scan-button');
	const $wrap = $('#oras-ai-scan-progress');
	const $bar = $('#oras-ai-progress-bar');
	const $text = $('#oras-ai-progress-text');
	const $log = $('#oras-ai-scan-log');

	function log(message) {
		$log.append($('<div>').text(message));
		$log.scrollTop($log[0].scrollHeight);
	}

	function setButtonsDisabled(disabled) {
		$buttons.prop('disabled', disabled);
	}

	function fail(message) {
		$text.text(ORAS_AI_SCAN.strings.failed);
		log(message || ORAS_AI_SCAN.strings.failed);
		setButtonsDisabled(false);
	}

	function processQueue(queue, index) {
		if (index >= queue.length) {
			$bar.css('width', '100%');
			$text.text(ORAS_AI_SCAN.strings.complete);
			window.setTimeout(function () {
				window.location.reload();
			}, 900);
			return;
		}

		const sourceId = queue[index];
		const percent = queue.length ? Math.round((index / queue.length) * 100) : 100;
		$bar.css('width', percent + '%');
		$text.text(ORAS_AI_SCAN.strings.processing + ' ' + (index + 1) + ' / ' + queue.length + '…');

		$.post(ORAS_AI_SCAN.ajaxUrl, {
			action: 'oras_ai_process_source',
			nonce: ORAS_AI_SCAN.nonce,
			source_id: sourceId
		}).done(function (response) {
			if (!response || !response.success) {
				const message = response && response.data && response.data.message
					? response.data.message
					: 'Unknown processing error.';
				fail(message);
				return;
			}

			const data = response.data || {};
			const method = data.classified_by === 'rule' ? 'WordPress rule' : 'AI';
			log((data.title || ('Source ' + sourceId)) + ': ' + (data.kind || 'processed') + ' via ' + method);
			processQueue(queue, index + 1);
		}).fail(function (xhr) {
			let message = 'HTTP error while analyzing source.';
			if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
				message = xhr.responseJSON.data.message;
			}
			fail(message);
		});
	}

	$buttons.on('click', function () {
		const scanMode = $(this).data('scan-mode') || 'changed';

		if (scanMode === 'rebuild') {
			const confirmed = window.confirm(
				'Rebuild will re-evaluate every discovered source. Scanner-managed knowledge that is now Live Data or Ignored will be retired automatically. Manual knowledge entries will not be changed. Continue?'
			);
			if (!confirmed) {
				return;
			}
		}

		setButtonsDisabled(true);
		$wrap.prop('hidden', false);
		$bar.css('width', '2%');
		$log.empty();
		$text.text(ORAS_AI_SCAN.strings.starting);

		$.post(ORAS_AI_SCAN.ajaxUrl, {
			action: 'oras_ai_discover_sources',
			nonce: ORAS_AI_SCAN.nonce,
			scan_mode: scanMode
		}).done(function (response) {
			if (!response || !response.success) {
				const message = response && response.data && response.data.message
					? response.data.message
					: 'Unable to discover WordPress content.';
				fail(message);
				return;
			}

			const data = response.data || {};
			const queue = Array.isArray(data.queue) ? data.queue : [];

			log('Discovered ' + (data.found || 0) + ' source(s).');
			log(queue.length + ' source(s) queued for classification.');
			if (scanMode === 'rebuild') {
				log('Rebuild mode: all discovered sources will be re-evaluated.');
			}

			if (!queue.length) {
				$bar.css('width', '100%');
				$text.text(ORAS_AI_SCAN.strings.complete);
				window.setTimeout(function () {
					window.location.reload();
				}, 700);
				return;
			}

			processQueue(queue, 0);
		}).fail(function (xhr) {
			let message = 'HTTP error while discovering sources.';
			if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
				message = xhr.responseJSON.data.message;
			}
			fail(message);
		});
	});
});
