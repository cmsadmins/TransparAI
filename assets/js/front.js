(function () {
	'use strict';

	/* Shrink badges on small images to the short label (tooltip keeps the text). */
	function scaleBadges() {
		document.querySelectorAll('.trai-mode-overlay .trai-badge').forEach(function (badge) {
			if (badge.closest('.trai-avwrap')) {
				return; /* AV badges are static caption lines, never minified. */
			}
			var img = badge.parentElement ? badge.parentElement.querySelector('img, video') : null;
			var width = img ? img.clientWidth : 0;
			if (!width) {
				return; /* Not rendered yet (lazyload); the load handler re-runs this. */
			}
			badge.classList.remove('trai-badge--mini');
			/* Mini when the image is tiny or the full label would outgrow it
			   (scrollWidth measures the unclipped text). */
			var mini = width < 140 || badge.scrollWidth + 16 > width;
			badge.classList.toggle('trai-badge--mini', mini);
			if (mini && !badge.title) {
				badge.title = badge.textContent;
			}
		});
	}

	scaleBadges();
	window.addEventListener('load', scaleBadges);

	var resizeTimer;
	function scaleBadgesSoon() {
		clearTimeout(resizeTimer);
		resizeTimer = setTimeout(scaleBadges, 150);
	}
	window.addEventListener('resize', scaleBadgesSoon);

	/* Lazyloaded images (slider data-src) get their size after the load event;
	   capture their load to rescale the badge that sits on them. */
	document.addEventListener('load', function (event) {
		if (event.target && event.target.tagName === 'IMG' && event.target.closest('.trai-wrap')) {
			scaleBadgesSoon();
		}
	}, true);

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
			'[style*="background-image"], .wp-block-cover, .elementor-section, .e-con, .elementor-widget-wrap, .elementor-column-wrap, .swiper-slide-bg, .vc_row-has-fill, .vc_column-inner, [data-vc-parallax-image], [data-thumbnail]'
		);
		candidates.forEach(function (element) {
			if (element.getAttribute('data-trai-bg')) {
				return;
			}
			/* Attribute-declared backgrounds render later (WPBakery parallax,
			   Elementor Pro lazy galleries); the attribute is the stable source. */
			var url = element.getAttribute('data-vc-parallax-image') || element.getAttribute('data-thumbnail') || '';
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
			var key = normalizePath(url);
			if (!flagged[key]) {
				return;
			}
			/* One badge per surface and file: parallax and slider scripts add
			   inner layers repeating the image of a labeled ancestor, and grid
			   items pair a background layer with an <img> of the same file.
			   Surfaces showing a different labeled file still get their own. */
			var marker = '[data-trai-bg="' + key.replace(/(["\\])/g, '\\$1') + '"]';
			var host = element.closest('[data-trai-bg]');
			if ((host && host.getAttribute('data-trai-bg') === key) || element.querySelector(marker)) {
				return;
			}
			var surface = element.getBoundingClientRect();
			var alreadyLabeled = false;
			element.querySelectorAll('.trai-wrap img').forEach(function (labeled) {
				var labeledSrc = labeled.currentSrc || labeled.src || labeled.getAttribute('data-src') || '';
				if (!labeledSrc || normalizePath(labeledSrc) !== key) {
					return;
				}
				/* Same file alone is not enough: a small content image inside a
				   large background section is a separate surface. Skip only
				   when the labeled image covers this surface. */
				var rect = labeled.getBoundingClientRect();
				var overlapX = Math.max(0, Math.min(surface.right, rect.right) - Math.max(surface.left, rect.left));
				var overlapY = Math.max(0, Math.min(surface.bottom, rect.bottom) - Math.max(surface.top, rect.top));
				if (surface.width > 0 && surface.height > 0 && (overlapX * overlapY) / (surface.width * surface.height) > 0.8) {
					alreadyLabeled = true;
				}
			});
			if (alreadyLabeled) {
				return;
			}
			element.setAttribute('data-trai-bg', key);
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

	/* Widgets that build their media late (lazy background galleries, AJAX
	   grids) appear after the load event. Re-run on DOM changes, debounced;
	   the done-guards make repeat runs cheap no-ops. */
	if ('MutationObserver' in window && document.body) {
		var labelTimer;
		var observer = new MutationObserver(function () {
			clearTimeout(labelTimer);
			labelTimer = setTimeout(labelAll, 250);
		});
		observer.observe(document.body, {
			childList: true,
			subtree: true,
			attributes: true,
			attributeFilter: ['style', 'src']
		});
	}
})();
