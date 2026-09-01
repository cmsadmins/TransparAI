(function () {
	'use strict';

	if (!window.transparaiAdmin) {
		return;
	}

	var labels = transparaiAdmin.labels;

	/* Non-blocking toast. Native alert() freezes the tab (and automation),
	   so every feedback path goes through this instead. */
	function notify(message) {
		var toast = document.createElement('div');
		toast.className = 'trai-toast';
		toast.setAttribute('role', 'status');
		toast.textContent = message;
		document.body.appendChild(toast);
		window.setTimeout(function () {
			toast.classList.add('trai-toast--out');
			window.setTimeout(function () { toast.remove(); }, 400);
		}, 4000);
	}

	/* =====================================================================
	 * Settings page: batched library scan with progress
	 * =================================================================== */

	var scanStart = document.getElementById('trai-scan-start');
	if (scanStart) {
		var scanAll = document.getElementById('trai-scan-all');
		var scanStop = document.getElementById('trai-scan-stop');
		var progressWrap = document.getElementById('trai-scan-progress');
		var progressBar = progressWrap.querySelector('.trai-progress-bar');
		var progressText = progressWrap.querySelector('.trai-progress-text');
		var running = false;
		var totals = { processed: 0, flagged: 0, queued: 0 };

		var renderProgress = function (remaining) {
			var done = totals.processed;
			var total = done + remaining;
			var pct = total > 0 ? Math.round((done / total) * 100) : 100;
			progressBar.style.width = pct + '%';
			progressText.textContent = labels.scanProgress
				.replace('%1$d', String(done))
				.replace('%2$d', String(totals.flagged))
				.replace('%3$d', String(totals.queued));
		};

		var step = function (mode, offset) {
			if (!running) {
				return;
			}
			jQuery.post(ajaxurl, {
				action: 'transparai_scan_batch',
				_wpnonce: transparaiAdmin.scanNonce,
				mode: mode,
				offset: offset
			}, function (resp) {
				if (!resp || !resp.success) {
					progressText.textContent = labels.scanFailed;
					running = false;
					scanStop.hidden = true;
					return;
				}
				var data = resp.data;
				totals.processed += data.processed;
				totals.flagged += data.flagged;
				totals.queued += data.queued;
				renderProgress(data.remaining);
				if (data.remaining > 0 && data.processed > 0) {
					step(mode, data.offset);
				} else {
					progressBar.style.width = '100%';
					progressText.textContent = progressText.textContent + ' ' + labels.scanDone;
					running = false;
					scanStop.hidden = true;
				}
			}).fail(function () {
				progressText.textContent = labels.scanFailed;
				running = false;
				scanStop.hidden = true;
			});
		};

		var begin = function (mode) {
			if (running) {
				return;
			}
			running = true;
			totals = { processed: 0, flagged: 0, queued: 0 };
			progressWrap.hidden = false;
			scanStop.hidden = false;
			progressBar.style.width = '0';
			progressText.textContent = '…';
			step(mode, 0);
		};

		scanStart.addEventListener('click', function () { begin('missing'); });
		scanAll.addEventListener('click', function () { begin('all'); });
		scanStop.addEventListener('click', function () {
			running = false;
			scanStop.hidden = true;
		});
	}

	/* =====================================================================
	 * Attachment details: review buttons + re-check (list mode edit screen
	 * and media modal share these delegated handlers)
	 * =================================================================== */

	jQuery(document).on('click', '.trai-review .trai-confirm, .trai-review .trai-dismiss', function () {
		var wrap = jQuery(this).closest('.trai-review');
		var id = parseInt(wrap.data('id'), 10);
		var op = jQuery(this).hasClass('trai-confirm') ? 'confirm' : 'dismiss';
		jQuery.post(ajaxurl, {
			action: 'transparai_bulk',
			_wpnonce: transparaiAdmin.nonce,
			op: op,
			ids: [id]
		}, function (resp) {
			if (resp && resp.success) {
				wrap.remove();
				/* Sync the checkbox: a later save of any other modal field would
				   otherwise re-submit the stale unchecked state and unflag. */
				jQuery('input[name="attachments[' + id + '][transparai_ai]"]').prop('checked', op === 'confirm');
				if (window.wp && wp.media && wp.media.attachment(id)) {
					wp.media.attachment(id).set('traiDetected', false);
					if (op === 'confirm') {
						wp.media.attachment(id).set('traiFlag', true);
					}
					toggleTile(id);
				}
			} else {
				notify(labels.updateFailed);
			}
		}).fail(function () {
			notify(labels.updateFailed);
		});
	});

	jQuery(document).on('click', '.trai-recheck', function () {
		var button = jQuery(this);
		var id = parseInt(button.data('id'), 10);
		button.prop('disabled', true);
		jQuery.post(ajaxurl, {
			action: 'transparai_recheck',
			_wpnonce: transparaiAdmin.scanNonce,
			attachment: id
		}, function (resp) {
			button.prop('disabled', false);
			if (!resp || !resp.success) {
				notify(labels.updateFailed);
				return;
			}
			var data = resp.data;
			var summary = data.status === 'clean'
				? labels.recheckClean
				: labels.recheckDone + ': ' + (data.generator || data.source) + ' (' + data.confidence + ')';
			var out = button.closest('.trai-recheck-wrap').find('.trai-recheck-result');
			if (!out.length) {
				out = jQuery('<span class="trai-recheck-result"></span>');
				button.closest('.trai-recheck-wrap').append(out);
			}
			out.text(summary).attr('title', data.evidence || '');
			if (data.status === 'flagged') {
				jQuery('input[name="attachments[' + id + '][transparai_ai]"]').prop('checked', true);
			}
			if (window.wp && wp.media && wp.media.attachment(id)) {
				if (data.status === 'flagged' || data.status === 'queued') {
					wp.media.attachment(id).set('traiFlag', data.status === 'flagged');
					wp.media.attachment(id).set('traiDetected', data.status === 'queued');
					toggleTile(id);
				}
			}
		}).fail(function () {
			button.prop('disabled', false);
			notify(labels.updateFailed);
		});
	});

	/* =====================================================================
	 * Media grid (wp.media views)
	 * =================================================================== */

	function toggleTile(id) {
		var model = window.wp && wp.media ? wp.media.attachment(id) : null;
		var tile = jQuery('.attachment[data-id="' + id + '"]');
		if (!model || !tile.length) {
			return;
		}
		tile.toggleClass('trai-flag', !!model.get('traiFlag'));
		tile.toggleClass('trai-detected', !model.get('traiFlag') && !!model.get('traiDetected'));
	}

	if (!window.wp || !wp.media || !wp.media.view) {
		return;
	}

	/* Badge on the tile */
	var Attachment = wp.media.view.Attachment;
	var origRender = Attachment.prototype.render;
	Attachment.prototype.render = function () {
		var result = origRender.apply(this, arguments);
		this.$el.toggleClass('trai-flag', !!this.model.get('traiFlag'));
		this.$el.toggleClass('trai-detected', !this.model.get('traiFlag') && !!this.model.get('traiDetected'));
		return result;
	};

	/* Checkbox in the details -> update the tile immediately */
	jQuery(document).on('change', 'input[name$="[transparai_ai]"]', function () {
		var match = this.name.match(/\[(\d+)\]/);
		var on = jQuery(this).is(':checked');
		if (match && wp.media.attachment(match[1])) {
			wp.media.attachment(match[1]).set('traiFlag', on);
			if (on) {
				wp.media.attachment(match[1]).set('traiDetected', false);
			}
			toggleTile(parseInt(match[1], 10));
		}
	});

	/* Dropdown filter in the grid view */
	var AiFilter = wp.media.view.AttachmentFilters.extend({
		id: 'trai-filter',
		createFilters: function () {
			this.filters = {
				all: { text: labels.filterAll, props: { transparai_filter: null }, priority: 10 },
				only: { text: labels.filterOnly, props: { transparai_filter: '1' }, priority: 20 },
				review: { text: labels.filterDetected, props: { transparai_filter: 'detected' }, priority: 30 },
				none: { text: labels.filterNone, props: { transparai_filter: '0' }, priority: 40 }
			};
		}
	});

	var Browser = wp.media.view.AttachmentsBrowser;
	var origToolbar = Browser.prototype.createToolbar;
	Browser.prototype.createToolbar = function () {
		origToolbar.apply(this, arguments);
		if (this.options.filters) {
			this.toolbar.set('traiFilter', new AiFilter({
				controller: this.controller,
				model: this.collection.props,
				priority: -75
			}).render());
		}
		/* Bulk buttons only in the media library screen (upload.php), not in the insert modal */
		if (this.controller.isModeActive && this.controller.isModeActive('grid')) {
			var bulk = function (view, op) {
				var state = view.controller.state();
				var selection = state && state.get('selection');
				if (!selection || !selection.length) {
					notify(labels.selectFirst);
					return;
				}
				var ids = selection.map(function (model) { return model.id; });
				jQuery.post(ajaxurl, {
					action: 'transparai_bulk',
					_wpnonce: transparaiAdmin.nonce,
					op: op,
					ids: ids
				}, function (resp) {
					if (resp && resp.success) {
						selection.each(function (model) {
							model.set('traiFlag', op === 'flag');
							model.set('traiDetected', false);
							toggleTile(model.id);
						});
					} else {
						notify(labels.updateFailed);
					}
				}).fail(function () {
					notify(labels.updateFailed);
				});
			};
			this.toolbar.set('traiBulkOn', new wp.media.view.Button({
				text: labels.bulkOn,
				className: 'trai-bulk-button',
				controller: this.controller,
				priority: -55,
				click: function () { bulk(this, 'flag'); }
			}).render());
			this.toolbar.set('traiBulkOff', new wp.media.view.Button({
				text: labels.bulkOff,
				className: 'trai-bulk-button',
				controller: this.controller,
				priority: -54,
				click: function () { bulk(this, 'unflag'); }
			}).render());
		}
	};
})();
