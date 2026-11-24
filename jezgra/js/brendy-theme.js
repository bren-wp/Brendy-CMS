(function () {
	'use strict';

	var configNode = document.getElementById('brendy-frontend-config');
	var config = {};
	try {
		config = configNode ? JSON.parse(configNode.textContent || '{}') : {};
	} catch (error) {
		config = {};
	}

	var root = document.documentElement;
	var body = document.body;
	var labels = config.labels || {};
	var preferenceKey = 'brendy-color-scheme';
	var colorQuery = window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null;
	var motionQuery = window.matchMedia ? window.matchMedia('(prefers-reduced-motion: reduce)') : null;
	var configuredScheme = ['light', 'dark', 'auto'].indexOf(config.colorScheme) !== -1 ? config.colorScheme : 'auto';
	var savedScheme = null;
	try { savedScheme = window.localStorage.getItem(preferenceKey); } catch (error) { savedScheme = null; }
	var activeScheme = savedScheme === 'light' || savedScheme === 'dark' ? savedScheme : configuredScheme;
	root.setAttribute('data-brendy-color-scheme', activeScheme);

	function prefersReducedMotion() {
		return config.animationLevel === 'off' || Boolean(motionQuery && motionQuery.matches);
	}

	function isDarkScheme() {
		var scheme = root.getAttribute('data-brendy-color-scheme');
		return scheme === 'dark' || (scheme === 'auto' && Boolean(colorQuery && colorQuery.matches));
	}

	function onAnimationFrame(callback) {
		var scheduled = false;
		var frame = window.requestAnimationFrame || function (handler) { return window.setTimeout(handler, 16); };
		return function () {
			if (scheduled) return;
			scheduled = true;
			frame(function () {
				scheduled = false;
				callback();
			});
		};
	}

	function icon(className) {
		var element = document.createElement('i');
		element.className = 'bi ' + className;
		element.setAttribute('aria-hidden', 'true');
		return element;
	}

	function floatingButton(className, label, iconName) {
		var button = document.createElement('button');
		button.type = 'button';
		button.className = 'brendy-floating-button ' + className;
		button.setAttribute('aria-label', label);
		button.title = label;
		button.appendChild(icon(iconName));
		return button;
	}

	var mainImage = document.querySelector('main img, article img, [role="main"] img');
	if (mainImage && !mainImage.hasAttribute('data-brendy-lazy') && !mainImage.hasAttribute('loading')) {
		mainImage.loading = 'eager';
		mainImage.fetchPriority = 'high';
		mainImage.decoding = 'async';
		mainImage.setAttribute('data-brendy-eager', '');
	}
	if (config.lazyLoadImages !== false) {
		document.querySelectorAll('img:not([loading]):not([data-brendy-eager])').forEach(function (image) {
			image.loading = 'lazy';
			image.decoding = 'async';
		});
	}

	var collapses = [];
	document.querySelectorAll('[data-brendy-collapse-target]').forEach(function (toggle) {
		var selector = toggle.getAttribute('data-brendy-collapse-target') || '';
		if (!/^#[A-Za-z][A-Za-z0-9_:-]*$/.test(selector)) return;
		var target = document.getElementById(selector.slice(1));
		if (!target) return;

		var controller = {
			toggle: toggle,
			target: target,
			setOpen: function (open, restoreFocus) {
				target.classList.toggle('show', open);
				toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
				if (!open && restoreFocus) toggle.focus();
			}
		};
		collapses.push(controller);

		toggle.addEventListener('click', function () {
			controller.setOpen(!target.classList.contains('show'), false);
		});
		target.addEventListener('click', function (event) {
			var clicked = event.target instanceof Element ? event.target : null;
			if (clicked && clicked.closest('a')) controller.setOpen(false, false);
		});
	});

	if (collapses.length) {
		document.addEventListener('keydown', function (event) {
			if (event.key !== 'Escape') return;
			collapses.forEach(function (controller) {
				if (controller.target.classList.contains('show')) controller.setOpen(false, true);
			});
		});
	}

	document.querySelectorAll('a[target="_blank"]').forEach(function (link) {
		var rel = (link.getAttribute('rel') || '').split(/\s+/).filter(Boolean);
		['noopener', 'noreferrer'].forEach(function (token) {
			if (rel.indexOf(token) === -1) rel.push(token);
		});
		link.setAttribute('rel', rel.join(' '));
	});

	var liveStatus = document.createElement('div');
	liveStatus.className = 'brendy-live-status';
	liveStatus.setAttribute('role', 'status');
	liveStatus.setAttribute('aria-live', 'polite');
	liveStatus.setAttribute('aria-atomic', 'true');
	body.appendChild(liveStatus);
	var statusTimeout;
	function announce(message) {
		window.clearTimeout(statusTimeout);
		liveStatus.textContent = message;
		liveStatus.classList.add('is-visible');
		statusTimeout = window.setTimeout(function () {
			liveStatus.classList.remove('is-visible');
		}, 2400);
	}

	var tools = document.createElement('div');
	tools.className = 'brendy-floating-tools';
	tools.setAttribute('aria-label', 'Brendy');

	var themeButton = floatingButton('brendy-theme-toggle', labels.useDarkTheme || 'Use dark theme', 'bi-moon-stars');
	function updateThemeButton() {
		var dark = isDarkScheme();
		while (themeButton.firstChild) themeButton.removeChild(themeButton.firstChild);
		themeButton.appendChild(icon(dark ? 'bi-sun' : 'bi-moon-stars'));
		var label = dark ? (labels.useLightTheme || 'Use light theme') : (labels.useDarkTheme || 'Use dark theme');
		themeButton.setAttribute('aria-label', label);
		themeButton.setAttribute('aria-pressed', dark ? 'true' : 'false');
		themeButton.title = label;
		root.style.colorScheme = dark ? 'dark' : 'light';
	}
	themeButton.addEventListener('click', function () {
		var next = isDarkScheme() ? 'light' : 'dark';
		root.setAttribute('data-brendy-color-scheme', next);
		try { window.localStorage.setItem(preferenceKey, next); } catch (error) { /* storage can be unavailable */ }
		updateThemeButton();
	});
	tools.appendChild(themeButton);
	updateThemeButton();
	if (colorQuery) {
		var syncSystemTheme = function () {
			if (root.getAttribute('data-brendy-color-scheme') === 'auto') updateThemeButton();
		};
		if (colorQuery.addEventListener) colorQuery.addEventListener('change', syncSystemTheme);
		else if (colorQuery.addListener) colorQuery.addListener(syncSystemTheme);
	}

	if (config.showBackToTop !== false) {
		var backToTop = floatingButton('brendy-back-to-top', labels.backToTop || 'Back to top', 'bi-arrow-up');
		backToTop.addEventListener('click', function () {
			window.scrollTo({ top: 0, behavior: prefersReducedMotion() ? 'auto' : 'smooth' });
		});
		tools.appendChild(backToTop);
		var updateBackToTop = function () { backToTop.classList.toggle('is-visible', window.scrollY > 480); };
		var scheduleBackToTop = onAnimationFrame(updateBackToTop);
		window.addEventListener('scroll', scheduleBackToTop, { passive: true });
		updateBackToTop();
	}

	body.appendChild(tools);

	// Product signature is part of the Brendy distribution and is intentionally
	// outside editable site content so theme switches cannot remove branding.
	if (!document.querySelector('[data-brendy-brand-signature], .brendy-brand-signature')) {
		var signature = document.createElement('div');
		signature.className = 'brendy-brand-signature';
		signature.setAttribute('data-brendy-brand-signature', '');
		signature.appendChild(document.createTextNode('Brendy CMS · '));
		var signatureLink = document.createElement('a');
		signatureLink.href = 'https://brendigo.com';
		signatureLink.target = '_blank';
		signatureLink.rel = 'noopener noreferrer';
		signatureLink.textContent = 'Brendigo';
		signature.appendChild(signatureLink);
		var footer = document.querySelector('footer');
		if (footer) footer.appendChild(signature); else body.appendChild(signature);
	}

	if (config.showReadingProgress !== false) {
		var progressOptions = config.readingProgress && typeof config.readingProgress === 'object' ? config.readingProgress : {};
		var progress = document.createElement('div');
		progress.className = 'brendy-reading-progress';
		progress.setAttribute('aria-hidden', 'true');
		progress.setAttribute('data-position', progressOptions.position === 'bottom' ? 'bottom' : 'top');
		var progressHeight = Number(progressOptions.height);
		if (Number.isFinite(progressHeight)) progress.style.height = Math.max(2, Math.min(10, progressHeight)) + 'px';
		if (typeof progressOptions.color === 'string' && /^#[0-9a-f]{6}$/i.test(progressOptions.color)) progress.style.backgroundColor = progressOptions.color;
		body.appendChild(progress);
		var readingScope = document.querySelector('[data-brendy-reading-scope], main article, article, main') || body;
		var updateProgress = function () {
			var bounds = readingScope.getBoundingClientRect();
			var start = window.scrollY + bounds.top;
			var distance = Math.max(1, readingScope.scrollHeight - window.innerHeight);
			var percent = Math.min(100, Math.max(0, (window.scrollY - start) / distance * 100));
			progress.style.width = percent + '%';
		};
		var scheduleProgress = onAnimationFrame(updateProgress);
		window.addEventListener('scroll', scheduleProgress, { passive: true });
		window.addEventListener('resize', scheduleProgress, { passive: true });
		updateProgress();
	}

	function copyToClipboard(value) {
		if (navigator.clipboard && window.isSecureContext) return navigator.clipboard.writeText(value);
		return new Promise(function (resolve, reject) {
			var field = document.createElement('textarea');
			field.value = value;
			field.setAttribute('readonly', '');
			field.style.position = 'fixed';
			field.style.opacity = '0';
			body.appendChild(field);
			field.select();
			try {
				document.execCommand('copy') ? resolve() : reject(new Error('Copy failed'));
			} catch (error) {
				reject(error);
			} finally {
				field.remove();
			}
		});
	}

	function offerManualCopy(url) {
		window.prompt(labels.copyLink || 'Copy link', url);
	}

	document.querySelectorAll('[data-brendy-share]').forEach(function (button) {
		button.addEventListener('click', function () {
			var title = button.getAttribute('data-share-title') || document.title;
			var url = button.getAttribute('data-share-url') || window.location.href;
			if (navigator.share) {
				navigator.share({ title: title, url: url }).catch(function (error) {
					if (error && error.name !== 'AbortError') copyToClipboard(url).then(function () {
						announce(labels.linkCopied || 'Link copied');
					}).catch(function () { offerManualCopy(url); });
				});
				return;
			}
			copyToClipboard(url).then(function () {
				announce(labels.linkCopied || 'Link copied');
			}).catch(function () { offerManualCopy(url); });
		});
	});

	document.querySelectorAll('[data-brendy-print]').forEach(function (button) {
		button.addEventListener('click', function () { window.print(); });
	});

	document.querySelectorAll('[data-brendy-search-form]').forEach(function (searchForm) {
		searchForm.addEventListener('submit', function (event) {
			var field = searchForm.querySelector('input[name="q"]');
			if (!field) return;
			var query = String(field.value || '').trim();
			var minimum = Math.max(1, Math.min(100, Number(searchForm.getAttribute('data-min-chars') || field.minLength || 1)));
			var queryLength = Array.from(query).length;
			if (queryLength < minimum) {
				event.preventDefault();
				var minimumMessage = String(labels.searchMinimum || 'Please enter at least %d characters to search').replace('%d', String(minimum));
				field.setCustomValidity(minimumMessage);
				if (typeof field.reportValidity === 'function') field.reportValidity();
				var clearValidity = function () { field.setCustomValidity(''); };
				field.addEventListener('input', clearValidity, { once: true });
				return;
			}
			field.setCustomValidity('');
			var base = searchForm.getAttribute('data-search-base');
			if (!base) return;
			try {
				var target = new URL(base, window.location.href);
				if (target.origin !== window.location.origin) return;
				event.preventDefault();
				window.location.assign(target.href.replace(/\/?$/, '/') + encodeURIComponent(query));
			} catch (error) {
				// Keep the native GET form fallback if URL parsing is unavailable.
			}
		});
	});

	if (!prefersReducedMotion() && 'IntersectionObserver' in window) {
		var revealObserver = new IntersectionObserver(function (entries, observer) {
			entries.forEach(function (entry) {
				if (!entry.isIntersecting) return;
				entry.target.classList.add('is-visible');
				observer.unobserve(entry.target);
			});
		}, { rootMargin: '0px 0px -7% 0px', threshold: 0.08 });
		document.querySelectorAll('.brendy-card, .brendy-related-card, main > article').forEach(function (element) {
			element.classList.add('brendy-reveal');
			revealObserver.observe(element);
		});
	}
}());
