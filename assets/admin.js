(function ($) {
	'use strict';

	var settings = window.NovamiraMainWPAdmin || {};
	var maxWorkers = Math.max(1, Math.min(3, parseInt(settings.maxConcurrent, 10) || 2));

	function openModal(selector) {
		$(selector).addClass('is-open').attr('aria-hidden', 'false');
	}

	function closeModal(selector) {
		$(selector).removeClass('is-open').attr('aria-hidden', 'true');
	}

	function selectedSites(selector) {
		return $(selector + ':checked').map(function () {
			return {
				id: parseInt($(this).val(), 10),
				name: $(this).data('site-name') || $(this).attr('aria-label') || ('Site ' + $(this).val())
			};
		}).get().filter(function (site) {
			return site.id > 0;
		});
	}

	function errorMessage(xhr, status) {
		if (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
			return xhr.responseJSON.data.message;
		}
		if ('timeout' === status) {
			return settings.timeoutMessage || 'This site timed out. Other sites will continue.';
		}
		return settings.requestFailed || 'The site request failed. Other sites will continue.';
	}

	function startQueue(sites, operation) {
		var pending = sites.slice();
		var active = 0;
		var finished = 0;
		var succeeded = 0;
		var failed = 0;
		var $modal = $('#nmm-bulk-progress-modal');
		var $tbody = $modal.find('tbody').empty();
		var $progress = $modal.find('progress').attr('max', sites.length).val(0);
		var $summary = $modal.find('.nmm-progress-summary').text('0/' + sites.length + ' completed');

		$modal.find('.nmm-progress-operation').text($('#nmm-bulk-operation option[value="' + operation + '"]').text() || operation);
		$modal.find('.nmm-modal-close').prop('disabled', true);
		$modal.find('.nmm-reload-fleet').hide();
		sites.forEach(function (site) {
			$('<tr>')
				.attr('data-site-id', site.id)
				.append($('<td>').text(site.name))
				.append($('<td class="nmm-queue-state">').text('Queued'))
				.append($('<td class="nmm-queue-message">').text('Waiting'))
				.appendTo($tbody);
		});
		openModal('#nmm-bulk-progress-modal');

		function completeOne(site, ok, message) {
			var $row = $tbody.find('tr[data-site-id="' + site.id + '"]');
			$row.find('.nmm-queue-state').text(ok ? 'Success' : 'Failed').toggleClass('nmm-ok', ok).toggleClass('nmm-bad', !ok);
			$row.find('.nmm-queue-message').text(message);
			finished += 1;
			succeeded += ok ? 1 : 0;
			failed += ok ? 0 : 1;
			$progress.val(finished);
			$summary.text(finished + '/' + sites.length + ' completed · ' + succeeded + ' succeeded · ' + failed + ' failed');
		}

		function pump() {
			while (active < maxWorkers && pending.length) {
				(function (site) {
					var $row = $tbody.find('tr[data-site-id="' + site.id + '"]');
					active += 1;
					$row.find('.nmm-queue-state').text('Working');
					$row.find('.nmm-queue-message').text('Contacting child site…');
					$.ajax({
						url: settings.ajaxUrl || window.ajaxurl,
						method: 'POST',
						dataType: 'json',
						timeout: 120000,
						data: {
							action: 'novamira_mainwp_bulk_site',
							nonce: settings.bulkNonce,
							site_id: site.id,
							operation: operation
						}
					}).done(function (response) {
						var data = response && response.data ? response.data : {};
						completeOne(site, !!(response && response.success), data.message || (response && response.success ? 'Completed' : 'Failed'));
					}).fail(function (xhr, status) {
						completeOne(site, false, errorMessage(xhr, status));
					}).always(function () {
						active -= 1;
						if (finished === sites.length) {
							$modal.find('.nmm-modal-close').prop('disabled', false);
							$modal.find('.nmm-reload-fleet').show();
						} else {
							pump();
						}
					});
				}(pending.shift()));
			}
		}

		pump();
	}

	$(function () {
		var bulkIntent = null;
		var $bulkOperation = $('#nmm-bulk-operation');
		if (!$bulkOperation.find('option[value="activate-pro-license"]').length) {
			$bulkOperation.find('option[value="update-pro"]').before(
				$('<option>', {
					value: 'activate-pro-license',
					text: 'Activate stored Pro license only (plugin must be active)'
				})
			);
		}
		var $fleetSelectAll = $('#nmm-select-all');
		var $fleetCheckboxes = $('.nmm-site-checkbox');
		$fleetSelectAll.on('change', function () {
			$fleetCheckboxes.prop('checked', this.checked);
		});
		$fleetCheckboxes.on('change', function () {
			var checked = $fleetCheckboxes.filter(':checked').length;
			$fleetSelectAll.prop('checked', checked === $fleetCheckboxes.length).prop('indeterminate', checked > 0 && checked < $fleetCheckboxes.length);
		});

		$('[data-nmm-preview]').on('click', function () {
			bulkIntent = 'preview';
		});
		$('[data-nmm-run]').on('click', function () {
			bulkIntent = 'run';
		});

		$('#nmm-bulk-fleet').on('submit', function (event) {
			var submitter = event.originalEvent && event.originalEvent.submitter;
			var isPreview = submitter ? $(submitter).is('[data-nmm-preview]') : 'preview' === bulkIntent;
			var isRun = submitter ? $(submitter).is('[data-nmm-run]') : 'run' === bulkIntent;
			bulkIntent = null;
			if (isPreview || !isRun) {
				return;
			}
			event.preventDefault();
			var sites = selectedSites('.nmm-site-checkbox');
			if (!sites.length) {
				window.alert(settings.selectSites || 'Select at least one site.');
				return;
			}
			if (!window.confirm(settings.confirmBulk || 'Run this action on every selected site?')) {
				return;
			}
			startQueue(sites, $('#nmm-bulk-operation').val());
		});

		$('#nmm-open-install-check').on('click', function () {
			openModal('#nmm-install-check-modal');
		});
		$('#nmm-missing-select-all').on('change', function () {
			$('.nmm-missing-site').prop('checked', this.checked);
		});
		$('.nmm-missing-site').on('change', function () {
			var $items = $('.nmm-missing-site');
			var checked = $items.filter(':checked').length;
			$('#nmm-missing-select-all').prop('checked', checked === $items.length).prop('indeterminate', checked > 0 && checked < $items.length);
		});
		$('#nmm-install-missing').on('click', function () {
			var sites = selectedSites('.nmm-missing-site');
			if (!sites.length) {
				window.alert(settings.selectSites || 'Select at least one site.');
				return;
			}
			closeModal('#nmm-install-check-modal');
			startQueue(sites, 'install-free');
		});

		$('.nmm-modal-close').on('click', function () {
			if (!$(this).prop('disabled')) {
				closeModal('#' + $(this).closest('.nmm-modal').attr('id'));
			}
		});
		$('.nmm-reload-fleet').on('click', function () {
			window.location.reload();
		});

		var $installModal = $('#nmm-install-check-modal');
		if ($installModal.length) {
			var autoKey = 'novamira-mainwp-install-check-' + $installModal.data('site-key');
			try {
				if (!window.sessionStorage.getItem(autoKey)) {
					window.sessionStorage.setItem(autoKey, 'shown');
					openModal('#nmm-install-check-modal');
				}
			} catch (error) {
				openModal('#nmm-install-check-modal');
			}
		}
	});
}(jQuery));
