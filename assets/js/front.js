(function () {
	'use strict';

	/* Shrink badges on small images to the short label (tooltip keeps the text). */
	function scaleBadges() {
		document.querySelectorAll('.trai-mode-overlay .trai-badge').forEach(function (badge) {
			var img = badge.parentElement ? badge.parentElement.querySelector('img, video') : null;
			var width = img ? img.clientWidth : 0;
			var mini = width > 0 && width < 140;
			badge.classList.toggle('trai-badge--mini', mini);
			if (mini && !badge.title) {
				badge.title = badge.textContent;
			}
		});
	}

	scaleBadges();
	window.addEventListener('load', scaleBadges);

	var resizeTimer;
	window.addEventListener('resize', function () {
		clearTimeout(resizeTimer);
		resizeTimer = setTimeout(scaleBadges, 150);
	});

	/* Optional: label CSS background images (hero sections, cover-style
	   layouts). Matches inline background-image URLs and common builder
	   containers against the upload paths of labeled attachments. */
	var config = window.transparaiFront;
	if (!config || !config.bgMap || !config.bgMap.length) {
		return;
	}

	function normalizePath(url) {
		try {
			url = decodeURIComponent(url);
		} catch (e) { /* keep raw */ }
		url = url.split('?')[0].split('#')[0];
		var uploads = url.indexOf('/uploads/');
		if (uploads !== -1) {
			url = url.slice(uploads + '/uploads/'.length);
		}
		/* Strip size suffix (-300x200) and conversion suffix (.webp/.avif after original ext). */
		url = url.replace(/(\.(?:jpe?g|png|gif))\.(?:webp|avif)$/i, '$1');
		url = url.replace(/-\d+x\d+(\.[a-z0-9]+)$/i, '$1');
		return url;
	}

	var flagged = {};
	config.bgMap.forEach(function (path) {
		flagged[path] = true;
	});

	function extractUrl(styleValue) {
		var match = /url\(\s*(['"]?)([^)'"]+)\1\s*\)/i.exec(styleValue);
		return match ? match[2] : '';
	}

	function labelBackgrounds() {
		var candidates = document.querySelectorAll(
			'[style*="background-image"], .wp-block-cover, .elementor-section, .elementor-widget-wrap, .elementor-column-wrap'
		);
		candidates.forEach(function (element) {
			if (element.getAttribute('data-trai-bg')) {
				return;
			}
			var style = element.getAttribute('style') || '';
			var url = extractUrl(style);
			if (!url) {
				var computed = window.getComputedStyle(element).backgroundImage;
				if (computed && computed !== 'none') {
					url = extractUrl(computed);
				}
			}
			if (!url) {
				return;
			}
			if (!flagged[normalizePath(url)]) {
				return;
			}
			element.setAttribute('data-trai-bg', '1');
			element.classList.add('trai-bg-host');
			config.classes.split(' ').forEach(function (cls) {
				if (cls) {
					element.classList.add(cls);
				}
			});
			var badge = document.createElement('span');
			badge.className = 'trai-badge';
			badge.setAttribute('role', 'note');
			badge.setAttribute('data-trai-short', config.short);
			badge.textContent = config.label;
			element.appendChild(badge);
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', labelBackgrounds);
	} else {
		labelBackgrounds();
	}
	window.addEventListener('load', labelBackgrounds);
})();
