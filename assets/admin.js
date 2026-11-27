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


	function bindSettings() {
		var $root = $('#nmm-settings');
		if (!$root.length) {
			return;
		}
		var $status = $('#nmm-settings-status');
		var $results = $('#nmm-settings-results');
		var $body = $results.find('tbody');
		var $apply = $('#nmm-settings-apply');
		var $scope = $('#nmm-settings-scope');
		var $selectAll = $('#nmm-settings-all');
		var $selectedCount = $('#nmm-settings-selected-count');
		var batch = null;
		var busy = false;
		var knownFeatures = new Set();
		var knownAbilities = new Set();

		function invalidate() {
			if (batch) {
				$status.empty();
				$body.empty();
				$results.prop('hidden', true);
			}
			batch = null;
			$apply.prop('hidden', true);
		}

		function updateSelection() {
			var $sites = $root.find('.nmm-settings-site:enabled');
			var checked = $sites.filter(':checked').length;
			$selectAll.prop('checked', checked > 0 && checked === $sites.length)
				.prop('indeterminate', checked > 0 && checked < $sites.length);
			$selectedCount.text(checked);
		}

		function collectPatch() {
			var scope = $scope.val();
			var patch = {};
			if (scope === 'read_mode') {
				return { read_mode: $('#nmm-settings-read-mode').val() === 'on' };
			}
			if (scope === 'ghost_mode') {
				return { ghost_mode: $('#nmm-settings-ghost-mode').val() === 'on' };
			}
			if (scope === 'reset_destinations') {
				return { reset_destinations: true };
			}
			if (scope !== 'general') {
				var $fields = $root.find('fieldset[data-nmm-settings-scope="' + scope + '"]');
				var id = $fields.find('[data-nmm-target-id]').val().trim();
				var choice = $fields.find('[data-nmm-target-choice]').val();
				if (!id) {
					return {};
				}
				patch[scope] = scope === 'ability_approval' ? { id: id, choice: choice } : { id: id, enabled: choice === 'on' };
				return patch;
			}
			var level = $('#nmm-approval-level').val();
			var emails = $('#nmm-hold-emails').val();
			if (level) {
				patch.approval_level = level;
			}
			if (emails) {
				patch.hold_emails = emails === 'on';
			}
			if ($('#nmm-change-approval-instructions').prop('checked')) {
				patch.approval_instructions = $('#nmm-approval-instructions').val();
			}
			if ($('#nmm-change-user-context').prop('checked')) {
				patch.user_context = $('#nmm-user-context').val();
			}
			return patch;
		}

		function describe(value) {
			var levels = { most: 'Ask more', big: 'Balanced', almost_none: 'Ask less', skip: 'Skip approvals', yes: 'Always ask', no: 'Never ask', level: 'Follow approval level' };
			if (typeof value === 'boolean') {
				return value ? 'On' : 'Off';
			}
			return Object.prototype.hasOwnProperty.call(levels, value) ? levels[value] : (value === '' || value == null ? '(empty)' : String(value));
		}

		function renderDetails($cell, step, data) {
			$cell.empty();
			if (step === 'apply') {
				$cell.text(data.message || 'Saved.');
				return;
			}
			if (step === 'preview') {
				var $changes = $('<dl>').appendTo($cell);
				var labels = {
					approval_level: 'Approval level',
					hold_emails: 'Hold AI emails',
					approval_instructions: 'Approval instructions',
					user_context: 'User context',
					read_mode: 'Read Mode',
					ghost_mode: 'Ghost Mode',
					approved_destinations: 'Approved destinations'
				};
				Object.keys(data.after || {}).forEach(function (key) {
					$('<dt>').text(Object.prototype.hasOwnProperty.call(labels, key) ? labels[key] : key).appendTo($changes);
					$('<dd>').css('white-space', 'pre-wrap').text(describe(data.before[key]) + ' → ' + describe(data.after[key])).appendTo($changes);
				});
				(data.notes || []).forEach(function (note) {
					$('<p>').text(note).appendTo($cell);
				});
				return;
			}
			$('<p>').text('Novamira ' + data.version + ' · ' + describe(data.approval_level) + ' · Email holding ' + describe(data.hold_emails) + ' · Read Mode ' + describe(data.read_mode) + ' · Ghost Mode ' + describe(data.ghost_mode) + ' · Approved destinations ' + data.approved_destinations).appendTo($cell);
			var $features = $('<ul>').appendTo($cell);
			Object.keys(data.features || {}).forEach(function (id) {
				var feature = data.features[id];
				$('<li>').text(feature.label + ' (' + id + ') · ' + describe(feature.active)).appendTo($features);
				if (!knownFeatures.has(id)) {
					$('<option>').val(id).text(feature.label).appendTo('#nmm-feature-ids');
					knownFeatures.add(id);
				}
			});
			(data.abilities || []).forEach(function (id) {
				if (!knownAbilities.has(id)) {
					$('<option>').val(id).appendTo('#nmm-ability-ids');
					knownAbilities.add(id);
				}
			});
		}

		function run(step, sites, patch) {
			busy = true;
			var $controls = $root.find('input, button, select, textarea').filter(':enabled');
			$controls.prop('disabled', true);
			$results.prop('hidden', false);
			$body.empty();
			var pending = sites.map(function (site) {
				var $row = $('<tr>').append($('<th scope="row">').text(site.name));
				site.$cell = $('<td>').text('Waiting').appendTo($row);
				$body.append($row);
				return site;
			});
			var finished = 0;
			var ready = [];
			var active = 0;
			function pump() {
				while (active < maxWorkers && pending.length) {
					execute(pending.shift());
				}
			}
			function execute(site) {
				active++;
				site.$cell.text('Contacting child site…');
				var payload = { step: step, patch: patch };
				if (step === 'apply') {
					payload.revision = site.preview.revision;
					payload.confirmation_token = site.preview.confirmation_token;
				}
				$.ajax({
					url: settings.ajaxUrl || window.ajaxurl,
					method: 'POST',
					dataType: 'json',
					timeout: 120000,
					data: { action: 'novamira_mainwp_settings', nonce: settings.bulkNonce, site_id: site.id, settings: JSON.stringify(payload) }
				}).done(function (response) {
					if (!response || !response.success) {
						site.$cell.text((response && response.data && response.data.message) || 'Request failed.');
						return;
					}
					renderDetails(site.$cell, step, response.data);
					if (step === 'preview') {
						site.preview = response.data;
					}
					ready.push(site);
				}).fail(function (xhr, status) {
					site.$cell.text(errorMessage(xhr, status));
				}).always(function () {
					active--;
					finished++;
					$status.text(finished + ' / ' + sites.length + ' sites processed. ' + ready.length + ' succeeded.');
					if (finished === sites.length) {
						busy = false;
						$controls.prop('disabled', false);
						if (step === 'preview' && ready.length) {
							batch = { sites: ready, patch: patch };
							$apply.prop('hidden', false).text('Apply to ' + ready.length + (ready.length === 1 ? ' site' : ' sites'));
						}
					}
					pump();
				});
			}
			pump();
		}

		function selection() {
			var sites = selectedSites('.nmm-settings-site:enabled');
			if (!sites.length) {
				$status.text(settings.selectSites || 'Select at least one site.');
			}
			return sites;
		}
		$root.on('input change', 'input:not([type="search"]), select, textarea', invalidate);
		$scope.on('change', function () {
			$root.find('[data-nmm-settings-scope]').each(function () {
				this.hidden = this.dataset.nmmSettingsScope !== $scope.val();
			});
		});
		$selectAll.on('change', function () {
			$root.find('.nmm-settings-site:enabled').prop('checked', this.checked);
			updateSelection();
		});
		$root.on('change', '.nmm-settings-site', updateSelection);
		updateSelection();
		$('#nmm-settings-inspect').on('click', function () {
			var sites = selection();
			if (!busy && sites.length) {
				invalidate();
				run('inspect', sites, {});
			}
		});
		$('#nmm-settings-form').on('submit', function (event) {
			event.preventDefault();
			var sites = selection();
			var patch = collectPatch();
			if (!busy && sites.length && Object.keys(patch).length) {
				invalidate();
				run('preview', sites, patch);
			} else if (sites.length && !Object.keys(patch).length) {
				$status.text('Choose at least one setting to change.');
			}
		});
		$apply.on('click', function () {
			if (!busy && batch) {
				var approved = batch;
				invalidate();
				run('apply', approved.sites, approved.patch);
			}
		});
	}

	$(function () {
		bindSettings();
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
