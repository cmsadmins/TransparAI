/*!
 * TransparAI front-end script.
 * Copyright (c) 2026 Patrick Schlesinger. License: GPL-2.0-or-later.
 */
(function () {
	'use strict';

	/* Shrink badges on small images to the short label (tooltip keeps the text). */
	function scaleBadges() {
		document.querySelectorAll('.trai-mode-overlay .trai-badge').forEach(function (badge) {
			if (badge.closest('.trai-avwrap, .trai-badge-below, .trai-badge-hidden')) {
				return; /* Static caption lines and hidden badges are never minified. */
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

	var config = window.transparaiFront;

	/* Overlay guard (one setting, on by default): themes and gallery plugins
	   stack their own layers over images (hover scrims, zoom icons, slider
	   chrome), and a sibling with a higher z-index or later paint order can
	   cover the badge regardless of the badge's own z-index. Instead of
	   guessing stacking contexts, elementsFromPoint() reveals the real paint
	   order at the badge's center; only a badge that is actually covered is
	   escalated: raise it, try the other corners, and as the last resort turn
	   it into the static caption line below the image, which nothing stacked
	   on the image can reach. Manual placements (trai-badge-manual from the
	   per-attachment override, or a trai-badge-* utility class on a container)
	   are left alone. Each badge is first tested when it becomes visible;
	   re-tested on resize and when images finish loading late. */
	var guardEnabled = !!(config && config.guard === '1' && 'IntersectionObserver' in window && document.elementsFromPoint);
	var GUARD_CORNERS = ['top-left', 'top-right', 'bottom-left', 'bottom-right'];
	var guarded = [];
	var guardSeen = guardEnabled ? new WeakSet() : null;

	function guardHost(badge) {
		if (badge.closest('.trai-badge-manual, .trai-avwrap, .trai-badge-hidden, .trai-badge-top-left, .trai-badge-top-right, .trai-badge-bottom-left, .trai-badge-bottom-right')) {
			return null;
		}
		var host = badge.closest('.trai-wrap, .trai-thumbwrap, .trai-bg-host');
		if (!host || !host.classList.contains('trai-mode-overlay')) {
			return null;
		}
		var below = badge.closest('.trai-badge-below');
		if (below && below !== host) {
			return null; /* Set on an ancestor by the site owner, not by the guard. */
		}
		return host;
	}

	/* Viewport chrome (admin bar, sticky headers, cookie bars) drifts over
	   arbitrary content while scrolling. It covers a badge only for the
	   moment it passes by, so treating it as a coverer would relocate badges
	   permanently for a transient overlap. */
	function stickyLayer(element) {
		for (var node = element; node && node !== document.body; node = node.parentElement) {
			var position = getComputedStyle(node).position;
			if (position === 'fixed' || position === 'sticky') {
				return true;
			}
		}
		return false;
	}

	function opaqueCoverer(element, badge) {
		if (element === badge || element.contains(badge) || stickyLayer(element)) {
			return false; /* The badge itself, one of its ancestors, or page chrome. */
		}
		var style = getComputedStyle(element);
		if (style.visibility === 'hidden' || parseFloat(style.opacity) <= 0.05) {
			return false;
		}
		if (/^(img|svg|video|canvas|picture|iframe|embed|object)$/i.test(element.tagName) || element.ownerSVGElement) {
			return true;
		}
		var bg = style.backgroundColor;
		if (bg && bg !== 'transparent') {
			var parts = /^rgba?\(([^)]+)\)$/.exec(bg);
			var comps = parts ? parts[1].split(',') : null;
			if (!comps || comps.length < 4 || parseFloat(comps[3]) > 0.02) {
				return true;
			}
		}
		return !!(style.backgroundImage && style.backgroundImage !== 'none');
	}

	/* The badge's center in viewport coordinates, or null while the badge
	   cannot be judged at all: collapsed layout, hidden slide, or scrolled
	   out of the viewport, which is the only area hit testing can see.
	   Single source of truth for "is this badge measurable right now". */
	function badgePoint(badge) {
		var rect = badge.getBoundingClientRect();
		if (!rect.width || !rect.height) {
			return null;
		}
		var x = rect.left + rect.width / 2;
		var y = rect.top + rect.height / 2;
		if (x < 0 || y < 0 || x >= window.innerWidth || y >= window.innerHeight) {
			return null;
		}
		return { x: x, y: y };
	}

	function isCovered(badge, point) {
		/* The badge is pointer-events:none and thus invisible to hit testing;
		   lift that for one synchronous call and restore the prior inline
		   value (icon-only and mini set pointer-events through CSS). */
		var prior = badge.style.pointerEvents;
		badge.style.pointerEvents = 'auto';
		var stack = document.elementsFromPoint(point.x, point.y);
		badge.style.pointerEvents = prior;
		var index = stack.indexOf(badge);
		if (index <= 0) {
			return false; /* Topmost already, or not testable at this point. */
		}
		for (var i = 0; i < index; i++) {
			if (opaqueCoverer(stack[i], badge)) {
				return true;
			}
		}
		return false;
	}

	function guardBasePos(host) {
		if (!host.getAttribute('data-trai-base-pos')) {
			var match = /trai-pos-(top-left|top-right|bottom-left|bottom-right)/.exec(host.className);
			host.setAttribute('data-trai-base-pos', match ? match[1] : 'bottom-right');
		}
		return host.getAttribute('data-trai-base-pos');
	}

	/* A rung only counts as solved when the badge is both judgeable and
	   uncovered there. Treating an unjudgeable rung as free would end the
	   ladder on an unverified position, which is how a covered badge stays
	   covered. */
	function guardSolved(badge) {
		var point = badgePoint(badge);
		return !!point && !isCovered(badge, point);
	}

	function runGuard(badge, host) {
		if (!badgePoint(badge)) {
			return false; /* Not judgeable now: keep the current state, retry later. */
		}
		var base = guardBasePos(host);
		badge.classList.remove('trai-badge--raised');
		host.classList.remove('trai-badge-below');
		GUARD_CORNERS.forEach(function (corner) {
			host.classList.toggle('trai-pos-' + corner, corner === base);
		});
		if (guardSolved(badge)) {
			return true;
		}
		badge.classList.add('trai-badge--raised');
		if (guardSolved(badge)) {
			return true;
		}
		var current = base;
		for (var i = 0; i < GUARD_CORNERS.length; i++) {
			if (GUARD_CORNERS[i] === base) {
				continue;
			}
			host.classList.remove('trai-pos-' + current);
			host.classList.add('trai-pos-' + GUARD_CORNERS[i]);
			current = GUARD_CORNERS[i];
			if (guardSolved(badge)) {
				return true;
			}
		}
		/* Every corner is covered (full scrim, or a stacking context the badge
		   cannot leave): the static line below the media is out of reach of
		   anything stacked on the image. */
		host.classList.remove('trai-pos-' + current);
		host.classList.add('trai-pos-' + base);
		badge.classList.remove('trai-badge--raised', 'trai-badge--mini');
		host.classList.add('trai-badge-below');
		return true;
	}

	/* First test once a badge is really visible: at 60% the center point is
	   inside the viewport, where elementsFromPoint works. Badges that never
	   reach the threshold are still caught by the resize/load re-runs. */
	var guardObserver = guardEnabled ? new IntersectionObserver(function (entries) {
		entries.forEach(function (entry) {
			if (entry.intersectionRatio < 0.6) {
				return;
			}
			var host = guardHost(entry.target);
			if (!host) {
				guardObserver.unobserve(entry.target);
				return;
			}
			if (runGuard(entry.target, host)) {
				guardObserver.unobserve(entry.target); /* Judged once; re-runs ride on resize/load. */
			} else {
				reguardSoon(); /* Mid-scroll race: the rect moved after the entry was computed. */
			}
		});
	}, { threshold: [0.6] }) : null;

	function discoverGuard() {
		if (!guardEnabled) {
			return;
		}
		document.querySelectorAll('.trai-mode-overlay .trai-badge').forEach(function (badge) {
			if (guardSeen.has(badge) || !guardHost(badge)) {
				return;
			}
			guardSeen.add(badge);
			guarded.push(badge);
			guardObserver.observe(badge);
		});
	}

	function reguardAll() {
		guarded = guarded.filter(function (badge) {
			return badge.isConnected;
		});
		guarded.forEach(function (badge) {
			var host = guardHost(badge);
			if (host) {
				runGuard(badge, host);
			}
		});
	}

	if (guardEnabled) {
		var guardTimer;
		var reguardSoon = function () {
			clearTimeout(guardTimer);
			guardTimer = setTimeout(reguardAll, 200);
		};
		discoverGuard();
		/* Lightboxes and galleries add their trigger layers around the load
		   event; lazyloaded images settle even later, on their own load. */
		window.addEventListener('load', reguardSoon);
		window.addEventListener('resize', reguardSoon);
		document.addEventListener('load', function (event) {
			if (event.target && event.target.tagName === 'IMG' && event.target.closest('.trai-wrap, .trai-thumbwrap')) {
				reguardSoon();
			}
		}, true);
	}

	/* Optional (one setting): label media the server-side filters cannot see.
	   Covers two cases against the upload paths of labeled attachments:
	   1. Plain <img> tags without a wp-image-{ID} class, as printed by ACF
	      fields returning URL/array, sliders and page-builder templates.
	   2. CSS background images (hero sections, cover-style layouts). */
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
		discoverGuard();
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
