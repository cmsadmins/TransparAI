/*!
 * TransparAI chatbot script.
 * Copyright (c) 2026 Patrick Schlesinger. License: GPL-2.0-or-later.
 *
 * Two jobs, both local: place the AI notice next to a chat widget when the
 * site operator switched it on, and (administrators only) report which
 * known widgets this browser saw, so the settings page can show a finding.
 * Widgets load late, so a MutationObserver watches for 30 seconds and stops.
 */
(function () {
	'use strict';

	var config = window.transparaiChatbot;
	if (!config || !config.vendors) {
		return;
	}

	function present(vendor) {
		var i;
		for (i = 0; i < vendor.globals.length; i++) {
			if (typeof window[vendor.globals[i]] !== 'undefined') {
				return true;
			}
		}
		for (i = 0; i < vendor.selectors.length; i++) {
			try {
				if (document.querySelector(vendor.selectors[i])) {
					return true;
				}
			} catch (e) { /* invalid selector from a filter: skip it */ }
		}
		return false;
	}

	function widgetElement() {
		var id, vendor, i, el;
		for (id in config.vendors) {
			vendor = config.vendors[id];
			for (i = 0; i < vendor.selectors.length; i++) {
				try {
					el = document.querySelector(vendor.selectors[i]);
				} catch (e) {
					el = null;
				}
				if (el) {
					return el;
				}
			}
		}
		return null;
	}

	var reported = {};
	var reportTimer;

	function report(ids) {
		var fresh = ids.filter(function (id) { return !reported[id]; });
		if (!fresh.length || !config.report || !window.fetch) {
			return;
		}
		fresh.forEach(function (id) { reported[id] = true; });
		var body = new FormData();
		body.append('action', 'transparai_chatbot_seen');
		body.append('_wpnonce', config.nonce);
		fresh.forEach(function (id) { body.append('ids[]', id); });
		window.fetch(config.ajaxurl, { method: 'POST', credentials: 'same-origin', body: body }).catch(function () { /* best effort */ });
	}

	var notice;

	function placeNotice() {
		if (!config.badge) {
			return;
		}
		var host = widgetElement();
		if (!host) {
			if (notice) {
				notice.hidden = true;
			}
			return;
		}
		if (!notice) {
			notice = document.createElement('div');
			notice.className = 'trai-chat-notice';
			notice.setAttribute('role', 'note');
			notice.textContent = config.text;
			document.body.appendChild(notice);
		}
		notice.hidden = false;
		var rect = host.getBoundingClientRect();
		if (!rect.width && !rect.height) {
			return; /* Hidden launcher: keep the default corner. */
		}
		notice.style.bottom = Math.max(8, Math.round(window.innerHeight - rect.top + 8)) + 'px';
		notice.style.right = Math.max(8, Math.round(window.innerWidth - rect.right)) + 'px';
	}

	function check() {
		var seen = [];
		var id;
		for (id in config.vendors) {
			if (present(config.vendors[id])) {
				seen.push(id);
			}
		}
		if (seen.length) {
			clearTimeout(reportTimer);
			reportTimer = setTimeout(function () { report(seen); }, 400);
		}
		placeNotice();
	}

	function start() {
		check();
		if (!('MutationObserver' in window)) {
			return;
		}
		var timer;
		var observer = new MutationObserver(function () {
			clearTimeout(timer);
			timer = setTimeout(check, 400);
		});
		observer.observe(document.documentElement, { childList: true, subtree: true });
		setTimeout(function () {
			observer.disconnect();
			check();
		}, 30000);
		window.addEventListener('resize', placeNotice);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', start);
	} else {
		start();
	}
})();
