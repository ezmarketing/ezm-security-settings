(function ($) {
	'use strict';

	function post(action, data) {
		data = data || {};
		data.action = action;
		data.nonce = ezmAdmin.nonce;
		return $.post(ezmAdmin.ajaxUrl, data);
	}

	function renderChart(el) {
		if (!el) return;
		var history = [];
		try {
			history = JSON.parse(el.getAttribute('data-history') || '[]') || [];
		} catch (e) {
			history = [];
		}
		if (!history.length) {
			el.innerHTML = '<p style="color:#646970;margin:0;">No history yet. Daily samples and full scans will populate this chart.</p>';
			return;
		}

		var w = Math.max(el.clientWidth || 640, 320);
		var h = 160;
		var pad = 28;
		var max = 1;
		history.forEach(function (p) {
			max = Math.max(max, parseInt(p.total_bytes, 10) || 0);
		});

		var pts = history.map(function (p, i) {
			var x = pad + (i / Math.max(history.length - 1, 1)) * (w - pad * 2);
			var y = h - pad - ((parseInt(p.total_bytes, 10) || 0) / max) * (h - pad * 2);
			return x.toFixed(1) + ',' + y.toFixed(1);
		});

		var area = pad + ',' + (h - pad) + ' ' + pts.join(' ') + ' ' + (pad + (w - pad * 2)) + ',' + (h - pad);
		var first = history[0].date || '';
		var last = history[history.length - 1].date || '';
		var maxLabel = (max / (1024 * 1024)).toFixed(1) + ' MB';

		el.innerHTML =
			'<svg viewBox="0 0 ' + w + ' ' + h + '" width="100%" height="' + h + '" role="img" aria-label="Disk growth chart">' +
			'<polyline fill="none" stroke="#2271b1" stroke-width="2" points="' + pts.join(' ') + '" />' +
			'<polygon fill="rgba(34,113,177,0.12)" points="' + area + '" />' +
			'<text x="' + pad + '" y="14" font-size="11" fill="#646970">' + maxLabel + '</text>' +
			'<text x="' + pad + '" y="' + (h - 6) + '" font-size="11" fill="#646970">' + first + '</text>' +
			'<text x="' + (w - pad) + '" y="' + (h - 6) + '" font-size="11" fill="#646970" text-anchor="end">' + last + '</text>' +
			'</svg>';
	}

	function tickDiskScan() {
		var $status = $('#ezm-disk-status');
		post('ezm_disk_scan_tick').done(function (resp) {
			if (!resp || !resp.success) {
				$status.text(ezmAdmin.i18n.error);
				return;
			}
			var job = resp.data.job;
			if (job.status === 'done') {
				$status.text(ezmAdmin.i18n.done);
				window.location.reload();
				return;
			}
			$status.text(ezmAdmin.i18n.scanning + ' ' + job.processed + ' dirs (queue ' + job.queued + ')');
			setTimeout(tickDiskScan, 400);
		}).fail(function () {
			$status.text(ezmAdmin.i18n.error);
		});
	}

	function tickMediaScan() {
		var $status = $('#ezm-media-status');
		post('ezm_media_scan_tick').done(function (resp) {
			if (!resp || !resp.success) {
				$status.text(ezmAdmin.i18n.error);
				return;
			}
			var job = resp.data.job;
			if (job.status === 'done') {
				$status.text(ezmAdmin.i18n.done);
				window.location.reload();
				return;
			}
			var phase = job.phase || 'library';
			$status.text(ezmAdmin.i18n.scanning + ' [' + phase + '] ' + job.processed + '/' + job.total + ' (queue ' + job.queued + ')');
			setTimeout(tickMediaScan, 350);
		}).fail(function () {
			$status.text(ezmAdmin.i18n.error);
		});
	}

	$(function () {
		renderChart(document.getElementById('ezm-disk-chart'));

		$('#ezm-disk-rescan').on('click', function () {
			var $status = $('#ezm-disk-status');
			$status.text(ezmAdmin.i18n.scanning);
			post('ezm_disk_scan_start').done(function (resp) {
				if (resp && resp.success) {
					tickDiskScan();
				} else {
					$status.text(ezmAdmin.i18n.error);
				}
			});
		});

		// Auto-continue if a scan was left running.
		if ($('#ezm-disk-page').length && $('#ezm-disk-status').text().indexOf('progress') !== -1) {
			tickDiskScan();
		}

		$('#ezm-disk-tree').on('click', '.ezm-tree-toggle, .ezm-tree-branch:not(.is-leaf) > .ezm-tree-row .ezm-tree-name', function (e) {
			e.preventDefault();
			var $branch = $(this).closest('.ezm-tree-branch');
			if ($branch.hasClass('is-leaf')) {
				return;
			}
			var open = $branch.hasClass('is-collapsed');
			$branch.toggleClass('is-collapsed', !open);
			$branch.children('.ezm-tree-children').prop('hidden', !open);
			$branch.children('.ezm-tree-row').find('.ezm-tree-toggle').attr('aria-expanded', open ? 'true' : 'false');
		});

		$('#ezm-logs-refresh').on('click', function () {
			var $s = $('#ezm-logs-refresh-status');
			$s.text('…');
			post('ezm_logs_refresh').done(function (resp) {
				if (resp && resp.success) {
					$s.text('Indexed ' + resp.data.count + ' files.');
					setTimeout(function () { window.location.reload(); }, 600);
				} else {
					$s.text(ezmAdmin.i18n.error);
				}
			});
		});

		$('#ezm-object-cache-ignore').on('click', function () {
			post('ezm_object_cache_ignore').done(function () {
				window.location.reload();
			});
		});
		$('#ezm-object-cache-unignore').on('click', function () {
			post('ezm_object_cache_unignore').done(function () {
				window.location.reload();
			});
		});
		$('#ezm-object-cache-delete').on('click', function () {
			if (!window.confirm('Delete wp-content/object-cache.php?\n\nThis disables the Redis drop-in. It will NOT regenerate automatically — only if a Redis plugin enables object cache again.')) {
				return;
			}
			post('ezm_object_cache_delete').done(function (resp) {
				if (resp && resp.success) {
					window.location.reload();
				} else {
					window.alert((resp && resp.data && resp.data.message) || ezmAdmin.i18n.error);
				}
			});
		});

		$('#ezm-media-scan').on('click', function () {
			$('#ezm-media-status').text(ezmAdmin.i18n.scanning);
			post('ezm_media_scan_start').done(function (resp) {
				if (resp && resp.success) tickMediaScan();
			});
		});

		$(document).on('click', '.ezm-media-ignore', function () {
			var id = $(this).data('id');
			var hash = $(this).data('hash') || '';
			var note = window.prompt('Optional ignore note:', '');
			if (note === null) return;
			post('ezm_media_ignore', { attachment_id: id, file_hash: hash, note: note }).done(function () {
				window.location.reload();
			});
		});

		$(document).on('click', '.ezm-media-quarantine', function () {
			if (!window.confirm('Delete this file? It can be restored for 90 days.')) return;
			var id = $(this).data('id');
			var hash = $(this).data('hash') || '';
			post('ezm_media_quarantine', { attachment_id: id, file_hash: hash }).done(function (resp) {
				if (resp && resp.success) window.location.reload();
				else window.alert((resp && resp.data && resp.data.message) || ezmAdmin.i18n.error);
			});
		});

		$(document).on('click', '.ezm-media-import', function () {
			var hash = $(this).data('hash') || '';
			var $btn = $(this).prop('disabled', true);
			post('ezm_media_import', { file_hash: hash }).done(function (resp) {
				if (resp && resp.success) window.location.reload();
				else {
					window.alert((resp && resp.data && resp.data.message) || ezmAdmin.i18n.error);
					$btn.prop('disabled', false);
				}
			}).fail(function () {
				$btn.prop('disabled', false);
			});
		});

		function selectedMediaItems() {
			var items = [];
			$('.ezm-row-check:checked').each(function () {
				items.push({
					attachment_id: $(this).data('id') || 0,
					file_hash: $(this).data('hash') || '',
					status: $(this).data('status') || ''
				});
			});
			return items;
		}

		function setSelectAll(on) {
			$('#ezm-select-all, #ezm-select-all-head').prop('checked', !!on);
			$('.ezm-row-check').prop('checked', !!on);
		}

		$('#ezm-select-all, #ezm-select-all-head').on('change', function () {
			setSelectAll($(this).is(':checked'));
		});

		function runBulk(action, items, totals) {
			totals = totals || { ok: 0, fail: 0 };
			var $status = $('#ezm-bulk-status');
			if (!items.length) {
				$status.text('Done. OK: ' + totals.ok + (totals.fail ? ', failed: ' + totals.fail : ''));
				setTimeout(function () { window.location.reload(); }, 700);
				return;
			}
			$status.text('Working… ' + totals.ok + ' done, ' + items.length + ' left');
			post('ezm_media_bulk', { bulk_action: action, items: items }).done(function (resp) {
				if (!resp || !resp.success) {
					$status.text(ezmAdmin.i18n.error);
					return;
				}
				totals.ok += resp.data.ok || 0;
				totals.fail += resp.data.fail || 0;
				runBulk(action, resp.data.next_items || [], totals);
			}).fail(function () {
				$status.text(ezmAdmin.i18n.error);
			});
		}

		$('#ezm-bulk-apply').on('click', function () {
			var action = $('#ezm-bulk-action').val();
			var items = selectedMediaItems();
			if (!action) {
				window.alert('Choose a bulk action.');
				return;
			}
			if (!items.length) {
				window.alert('Select at least one file.');
				return;
			}
			if (action === 'delete' && !window.confirm('Delete ' + items.length + ' file(s)? They can be restored for 90 days.')) {
				return;
			}
			if (action === 'import') {
				items = items.filter(function (it) { return it.status === 'disk_orphan' && it.file_hash; });
				if (!items.length) {
					window.alert('Add to media library only works for on-disk files (not already in the library).');
					return;
				}
			}
			runBulk(action, items);
		});

		function runDeleteAll(totals) {
			totals = totals || { ok: 0, fail: 0 };
			var $btn = $('#ezm-delete-all');
			var $status = $('#ezm-bulk-status');
			$status.text('Deleting all… ' + totals.ok + ' done' + (totals.fail ? ', ' + totals.fail + ' failed' : ''));
			$btn.prop('disabled', true);
			post('ezm_media_delete_all', {
				status: $btn.data('status') || 'orphan_queue',
				mime: $btn.data('mime') || '',
				s: $btn.data('s') || ''
			}).done(function (resp) {
				if (!resp || !resp.success) {
					$status.text((resp && resp.data && resp.data.message) || ezmAdmin.i18n.error);
					$btn.prop('disabled', false);
					return;
				}
				totals.ok += resp.data.ok || 0;
				totals.fail += resp.data.fail || 0;
				var left = resp.data.remaining || 0;
				if (resp.data.done || left <= 0) {
					$status.text('Done. Deleted ' + totals.ok + (totals.fail ? ', failed: ' + totals.fail : ''));
					setTimeout(function () { window.location.reload(); }, 800);
					return;
				}
				$status.text('Deleting all… ' + totals.ok + ' done, ~' + left + ' left');
				runDeleteAll(totals);
			}).fail(function () {
				$status.text(ezmAdmin.i18n.error);
				$btn.prop('disabled', false);
			});
		}

		$('#ezm-delete-all').on('click', function () {
			var count = parseInt($(this).data('count'), 10) || 0;
			if (!count) {
				return;
			}
			var msg = 'Delete ALL ' + count + ' file(s) matching this filter?\n\nThey can be restored for 90 days from Pending deletion.\nThis cannot be undone from this screen once purged.';
			if (!window.confirm(msg)) {
				return;
			}
			if (count >= 100 && !window.confirm('Really delete ' + count + ' files? Last chance.')) {
				return;
			}
			runDeleteAll();
		});

		$('#ezm-unsafe-scan').on('click', function () {
			var $s = $('#ezm-unsafe-status');
			$s.text(ezmAdmin.i18n.scanning);
			post('ezm_unsafe_scan').done(function (resp) {
				if (resp && resp.success) {
					$s.text('Found ' + resp.data.count + ' (' + resp.data.unmarked + ' need review)');
					setTimeout(function () { window.location.reload(); }, 500);
				} else {
					$s.text(ezmAdmin.i18n.error);
				}
			});
		});

		$(document).on('click', '.ezm-unsafe-mark', function () {
			var path = $(this).data('path');
			post('ezm_unsafe_mark_safe', { path: path }).done(function () {
				window.location.reload();
			});
		});

		$(document).on('click', '.ezm-unsafe-unmark', function () {
			var hash = $(this).data('hash');
			post('ezm_unsafe_unmark_safe', { hash: hash }).done(function () {
				window.location.reload();
			});
		});

		$(document).on('click', '.ezm-media-restore', function () {
			var id = $(this).data('id');
			post('ezm_media_restore', { quarantine_id: id }).done(function () {
				window.location.reload();
			});
		});

		$('#ezm-media-purge-now').on('click', function () {
			if (!window.confirm('Permanently delete items whose 90-day purge date has passed?')) return;
			post('ezm_media_purge_now').done(function (resp) {
				window.alert('Purged: ' + (resp.data && resp.data.purged));
				window.location.reload();
			});
		});

		function syncScheduleVisibility() {
			var freq = $('#ezm_schedule_freq').val();
			$('.ezm-weekly-only').toggle(freq === 'weekly');
			$('.ezm-monthly-only').toggle(freq === 'monthly');
		}
		$('#ezm_schedule_freq').on('change', syncScheduleVisibility);
		syncScheduleVisibility();
	});
})(jQuery);
