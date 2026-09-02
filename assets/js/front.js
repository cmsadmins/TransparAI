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

	/* Optional (one setting): label media the server-side filters cannot see.
	   Covers two cases against the upload paths of labeled attachments:
	   1. Plain <img> tags without a wp-image-{ID} class, as printed by ACF
	      fields returning URL/array, sliders and page-builder templates.
	   2. CSS background images (hero sections, cover-style layouts). */
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
		/* Strip conversion suffix (.webp/.avif after the original extension),
		   size suffix (-300x200) and the -scaled marker of large originals. */
		url = url.replace(/(\.(?:jpe?g|png|gif))\.(?:webp|avif)$/i, '$1');
		url = url.replace(/-\d+x\d+(\.[a-z0-9]+)$/i, '$1');
		url = url.replace(/-scaled(\.[a-z0-9]+)$/i, '$1');
		return url;
	}

	var flagged = {};
	config.bgMap.forEach(function (path) {
		flagged[normalizePath(path)] = true;
	});

	function makeBadge() {
		var badge = document.createElement('span');
		badge.className = 'trai-badge';
		badge.setAttribute('role', 'note');
		badge.setAttribute('data-trai-short', config.short);
		badge.textContent = config.label;
		return badge;
	}

	/* Plain <img> tags without an attachment class: wrap them with the same
	   markup the server-side filters produce, so styling and the mini-badge
	   logic apply unchanged. */
	function labelImages() {
		Array.prototype.forEach.call(document.images, function (img) {
			if (img.getAttribute('data-trai-done')) {
				return;
			}
			if (/(?:^|\s)wp-image-\d+(?:\s|$)/.test(img.className)) {
				return; /* Handled server-side when labeled. */
			}
			if (img.closest('.trai-wrap, .trai-thumbwrap, .trai-avwrap, .trai-bg-host')) {
				return;
			}
			var src = img.currentSrc || img.src || img.getAttribute('data-src') || '';
			if (!src || !flagged[normalizePath(src)]) {
				return;
			}
			img.setAttribute('data-trai-done', '1');
			var wrap = document.createElement('span');
			wrap.className = config.classesImg;
			var parent = img.parentNode;
			if (!parent) {
				return;
			}
			parent.insertBefore(wrap, img);
			wrap.appendChild(img);
			wrap.appendChild(makeBadge());
		});
	}

	function extractUrl(styleValue) {
		var match = /url\(\s*(['"]?)([^)'"]+)\1\s*\)/i.exec(styleValue);
		return match ? match[2] : '';
	}

	function labelBackgrounds() {
		/* Inline styles, block covers, Elementor sections/containers (their
		   backgrounds live in compiled CSS, hence getComputedStyle) and
		   WPBakery rows/columns with design-options fills or parallax. */
		var candidates = document.querySelectorAll(
			'[style*="background-image"], .wp-block-cover, .elementor-section, .e-con, .elementor-widget-wrap, .elementor-column-wrap, .vc_row-has-fill, .vc_column-inner, [data-vc-parallax-image]'
		);
		candidates.forEach(function (element) {
			if (element.getAttribute('data-trai-bg')) {
				return;
			}
			var url = element.getAttribute('data-vc-parallax-image') || '';
			if (!url) {
				url = extractUrl(element.getAttribute('style') || '');
			}
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
			config.classesBg.split(' ').forEach(function (cls) {
				if (cls) {
					element.classList.add(cls);
				}
			});
			element.appendChild(makeBadge());
		});
	}

	function labelAll() {
		labelImages();
		labelBackgrounds();
		scaleBadges();
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', labelAll);
	} else {
		labelAll();
	}
	window.addEventListener('load', labelAll);
})();
