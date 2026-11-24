(function () {
	'use strict';

	var root = document.documentElement;
	var body = document.body;
	var themeKey = 'brendy-admin-color-scheme';
	var themeButton = document.querySelector('[data-brendy-admin-theme-toggle]');
	var colorQuery = window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null;
	window.BrendyAdmin = window.BrendyAdmin || {};
	window.BrendyAdmin.setSwitchLabel = function (button, iconClass, label) {
		var element = button && button.jquery ? button[0] : button;
		if (!element) return;
		while (element.firstChild) element.removeChild(element.firstChild);
		var icon = document.createElement('i');
		icon.className = String(iconClass || '');
		element.appendChild(icon);
		element.appendChild(document.createTextNode(' ' + String(label || '')));
	};

	var savedTheme = null;
	try { savedTheme = window.localStorage.getItem(themeKey); } catch (error) { savedTheme = null; }
	if (savedTheme !== 'dark' && savedTheme !== 'light') savedTheme = 'auto';

	function resolvedTheme(theme) {
		return theme === 'auto' ? (colorQuery && colorQuery.matches ? 'dark' : 'light') : theme;
	}

	function applyTheme(theme) {
		var resolved = resolvedTheme(theme);
		body.setAttribute('data-brendy-admin-theme', theme);
		root.setAttribute('data-brendy-admin-theme', resolved);
		root.setAttribute('data-bs-theme', resolved);
		root.style.colorScheme = resolved;
		if (themeButton) {
			var icon = themeButton.querySelector('i');
			if (icon) icon.className = 'bi ' + (resolved === 'dark' ? 'bi-sun' : 'bi-moon-stars');
			themeButton.setAttribute('aria-pressed', resolved === 'dark' ? 'true' : 'false');
		}
	}
	applyTheme(savedTheme);
	if (themeButton) {
		themeButton.addEventListener('click', function () {
			var next = resolvedTheme(body.getAttribute('data-brendy-admin-theme')) === 'dark' ? 'light' : 'dark';
			applyTheme(next);
			try { window.localStorage.setItem(themeKey, next); } catch (error) { /* storage can be unavailable */ }
		});
	}
	if (colorQuery) {
		var syncSystemTheme = function () {
			if (body.getAttribute('data-brendy-admin-theme') === 'auto') applyTheme('auto');
		};
		if (colorQuery.addEventListener) colorQuery.addEventListener('change', syncSystemTheme);
		else if (colorQuery.addListener) colorQuery.addListener(syncSystemTheme);
	}

	var currentPath = window.location.pathname.replace(/\/+$/, '');
	document.querySelectorAll('.brendy-sidebar-nav .nav-link[href], .brendy-mobile-nav .nav-link[href]').forEach(function (link) {
		var linkPath;
		try { linkPath = new URL(link.href, window.location.href).pathname.replace(/\/+$/, ''); } catch (error) { return; }
		if (linkPath && (currentPath === linkPath || currentPath.indexOf(linkPath + '/') === 0)) {
			link.classList.add('active');
			link.setAttribute('aria-current', 'page');
		}
	});

	var palette = document.querySelector('[data-brendy-command-palette]');
	var paletteInput = palette ? palette.querySelector('input[type="search"]') : null;
	var paletteLinks = palette ? Array.prototype.slice.call(palette.querySelectorAll('.brendy-command-palette__list a')) : [];
	var paletteEmpty = palette ? palette.querySelector('.brendy-command-palette__empty') : null;
	var previousFocus = null;
	var activePaletteIndex = -1;

	function visiblePaletteLinks() {
		return paletteLinks.filter(function (link) { return !link.hidden; });
	}

	function setActivePaletteLink(index, focus) {
		var visible = visiblePaletteLinks();
		if (!visible.length) { activePaletteIndex = -1; return; }
		activePaletteIndex = (index + visible.length) % visible.length;
		paletteLinks.forEach(function (link) { link.classList.remove('is-selected'); link.removeAttribute('aria-current'); });
		visible[activePaletteIndex].classList.add('is-selected');
		visible[activePaletteIndex].setAttribute('aria-current', 'true');
		visible[activePaletteIndex].scrollIntoView({ block: 'nearest' });
		if (focus) visible[activePaletteIndex].focus();
	}

	function openPalette() {
		if (!palette || !palette.hidden) return;
		previousFocus = document.activeElement;
		palette.hidden = false;
		body.classList.add('brendy-command-open');
		if (paletteInput) {
			paletteInput.value = '';
			filterPalette('');
			window.setTimeout(function () { paletteInput.focus(); }, 20);
		}
	}

	function closePalette() {
		if (!palette || palette.hidden) return;
		palette.hidden = true;
		body.classList.remove('brendy-command-open');
		activePaletteIndex = -1;
		if (previousFocus && typeof previousFocus.focus === 'function') previousFocus.focus();
	}

	function filterPalette(query) {
		var normalized = String(query || '').trim().toLocaleLowerCase();
		var visible = 0;
		paletteLinks.forEach(function (link) {
			var matches = normalized === '' || link.textContent.toLocaleLowerCase().indexOf(normalized) !== -1;
			link.hidden = !matches;
			link.classList.remove('is-selected');
			if (matches) visible += 1;
		});
		activePaletteIndex = -1;
		if (paletteEmpty) paletteEmpty.hidden = visible !== 0;
	}

	document.querySelectorAll('[data-brendy-command]').forEach(function (button) { button.addEventListener('click', openPalette); });
	document.querySelectorAll('[data-brendy-command-close]').forEach(function (button) { button.addEventListener('click', closePalette); });
	if (paletteInput) {
		paletteInput.addEventListener('input', function () { filterPalette(paletteInput.value); });
		paletteInput.addEventListener('keydown', function (event) {
			if (event.key === 'ArrowDown') { event.preventDefault(); setActivePaletteLink(activePaletteIndex + 1, true); }
			else if (event.key === 'ArrowUp') { event.preventDefault(); setActivePaletteLink(activePaletteIndex < 0 ? visiblePaletteLinks().length - 1 : activePaletteIndex - 1, true); }
			else if (event.key === 'Enter' && activePaletteIndex >= 0) { event.preventDefault(); visiblePaletteLinks()[activePaletteIndex].click(); }
		});
	}
	if (palette) {
		palette.addEventListener('keydown', function (event) {
			if (event.key === 'ArrowDown' && event.target !== paletteInput) { event.preventDefault(); setActivePaletteLink(activePaletteIndex + 1, true); }
			if (event.key === 'ArrowUp' && event.target !== paletteInput) { event.preventDefault(); setActivePaletteLink(activePaletteIndex - 1, true); }
			if (event.key === 'Home' && event.target !== paletteInput) { event.preventDefault(); setActivePaletteLink(0, true); }
			if (event.key === 'End' && event.target !== paletteInput) { event.preventDefault(); setActivePaletteLink(visiblePaletteLinks().length - 1, true); }
			if (event.key === 'Tab') {
				var focusable = Array.prototype.slice.call(palette.querySelectorAll('input, button, a:not([hidden])')).filter(function (element) { return !element.hidden; });
				if (!focusable.length) return;
				var first = focusable[0];
				var last = focusable[focusable.length - 1];
				if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
				else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
			}
		});
	}
	document.addEventListener('keydown', function (event) {
		if ((event.ctrlKey || event.metaKey) && !event.shiftKey && !event.altKey && event.key.toLowerCase() === 'k') {
			event.preventDefault();
			openPalette();
		}
		if (event.key === 'Escape') closePalette();
	});

	document.querySelectorAll('[data-brendy-confirm]').forEach(function (element) {
		element.addEventListener('click', function (event) {
			if (!window.confirm(element.getAttribute('data-brendy-confirm'))) event.preventDefault();
		});
	});

	// Parent-page lookup is shared by both create and edit content screens.
	// Keeping it here removes two large inline copies and keeps CSP simpler.
	var parentSelect = document.getElementById('jsparent');
	if (parentSelect && window.jQuery && jQuery.fn && jQuery.fn.select2 && typeof HTML_PATH_ADMIN_ROOT === 'string') {
		jQuery(parentSelect).select2({
			placeholder: '',
			allowClear: true,
			theme: 'brendy5',
			minimumInputLength: 1,
			ajax: {
				url: HTML_PATH_ADMIN_ROOT + 'ajax/get-published',
				data: function (params) {
					var keyInput = document.querySelector('input[name="key"]');
					return { checkIsParent: true, query: params.term, currentKey: keyInput ? keyInput.value : '' };
				},
				processResults: function (data) { return data; }
			},
			templateResult: function (data) {
				if (data.loading) return data.text;
				var result = jQuery('<span>').text(String(data.text || ''));
				if (data.type === 'static') {
					result.append(' ').append(jQuery('<span>').addClass('badge rounded-pill text-bg-light').text(String(data.type)));
				}
				return result;
			}
		});
	}

	document.querySelectorAll('input[type="checkbox"][data-brendy-sync-hidden]').forEach(function (checkbox) {
		var sync = function () {
			var hidden = checkbox.previousElementSibling;
			if (hidden && hidden.type === 'hidden') hidden.value = checkbox.checked ? '1' : '0';
		};
		checkbox.addEventListener('change', sync);
		sync();
	});


	var filterQuery = document.querySelector('[data-brendy-filter-query]');
	var filterStatus = document.querySelector('[data-brendy-filter-status]');
	var filterCategory = document.querySelector('[data-brendy-filter-category]');
	var filterItems = Array.prototype.slice.call(document.querySelectorAll('[data-brendy-filter-item]'));
	var filterEmpty = document.querySelector('[data-brendy-filter-empty]');
	function runFilters() {
		var query = filterQuery ? filterQuery.value.trim().toLocaleLowerCase() : '';
		var status = filterStatus ? filterStatus.value : '';
		var category = filterCategory ? filterCategory.value : '';
		var visible = 0;
		filterItems.forEach(function (item) {
			var matchesQuery = query === '' || (item.getAttribute('data-search') || '').toLocaleLowerCase().indexOf(query) !== -1;
			var matchesStatus = status === '' || item.getAttribute('data-status') === status;
			var matchesCategory = category === '' || item.getAttribute('data-category') === category;
			item.hidden = !(matchesQuery && matchesStatus && matchesCategory);
			if (!item.hidden) visible += 1;
		});
		if (filterEmpty) filterEmpty.hidden = visible !== 0;
	}
	[filterQuery, filterStatus, filterCategory].forEach(function (control) {
		if (!control) return;
		control.addEventListener(control.tagName === 'INPUT' ? 'input' : 'change', runFilters);
	});
	runFilters();

	var appearancePreview = document.querySelector('[data-brendy-appearance-preview]');
	if (appearancePreview) {
		var accentPicker = document.getElementById('jsaccentColorPicker');
		var accentText = document.getElementById('jsaccentColor');
		var previewCard = appearancePreview.querySelector('.brendy-appearance-preview__card');
		var appearanceFields = {
			typography: document.querySelector('[name="typographyScale"]'),
			card: document.querySelector('[name="cardStyle"]'),
			rounded: document.querySelector('[name="roundedCards"]'),
			scheme: document.querySelector('[name="colorScheme"]')
		};
		function updateAppearancePreview() {
			if (!previewCard) return;
			var accent = accentText && /^#[0-9a-f]{6}$/i.test(accentText.value) ? accentText.value : '#2563eb';
			appearancePreview.style.setProperty('--preview-accent', accent);
			var typography = appearanceFields.typography ? appearanceFields.typography.value : 'comfortable';
			previewCard.style.fontSize = typography === 'large' ? '1.075rem' : (typography === 'compact' ? '.95rem' : '1rem');
			previewCard.style.borderRadius = appearanceFields.rounded && appearanceFields.rounded.value === 'false' ? '.25rem' : '1rem';
			var cardStyle = appearanceFields.card ? appearanceFields.card.value : 'elevated';
			previewCard.classList.toggle('is-outlined', cardStyle === 'outlined');
			previewCard.classList.toggle('is-flat', cardStyle === 'flat');
			var scheme = appearanceFields.scheme ? appearanceFields.scheme.value : 'auto';
			appearancePreview.classList.toggle('is-dark', scheme === 'dark');
		}
		if (accentPicker && accentText) {
			accentPicker.addEventListener('input', function () {
				accentText.value = accentPicker.value;
				accentText.dispatchEvent(new Event('input', { bubbles: true }));
			});
			accentText.addEventListener('input', function () {
				if (/^#[0-9a-f]{6}$/i.test(accentText.value)) accentPicker.value = accentText.value;
				updateAppearancePreview();
			});
		}
		Object.keys(appearanceFields).forEach(function (key) {
			if (appearanceFields[key]) appearanceFields[key].addEventListener('change', updateAppearancePreview);
		});
		updateAppearancePreview();
	}


	// Shared content-editor controls: options drawer, cover image, date picker and
	// new-page slug generation. Keeping these in one place avoids behavior drift
	// between New Content and Edit Content.
	var editorSidebar = document.getElementById('jseditorSidebar');
	var editorSidebarToggle = document.getElementById('jsoptionsSidebar');
	var editorSidebarClose = document.getElementById('jsoptionsSidebarClose');
	var editorShadow = document.getElementById('jsshadow');
	var editorSidebarPreviousFocus = null;

	function editorSidebarFocusable() {
		if (!editorSidebar) return [];
		return Array.prototype.slice.call(editorSidebar.querySelectorAll('a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])')).filter(function (element) {
			return !element.hidden && element.offsetParent !== null;
		});
	}

	function openEditorSidebar() {
		if (!editorSidebar || !editorSidebarToggle || !editorShadow || !editorSidebar.hidden) return;
		editorSidebarPreviousFocus = document.activeElement;
		editorSidebar.hidden = false;
		editorShadow.hidden = false;
		body.classList.add('brendy-editor-sidebar-open');
		editorSidebarToggle.setAttribute('aria-expanded', 'true');
		window.setTimeout(function () {
			var focusable = editorSidebarFocusable();
			(focusable[0] || editorSidebar).focus();
		}, 0);
	}

	function closeEditorSidebar() {
		if (!editorSidebar || editorSidebar.hidden) return;
		editorSidebar.hidden = true;
		if (editorShadow) editorShadow.hidden = true;
		body.classList.remove('brendy-editor-sidebar-open');
		if (editorSidebarToggle) editorSidebarToggle.setAttribute('aria-expanded', 'false');
		if (editorSidebarPreviousFocus && typeof editorSidebarPreviousFocus.focus === 'function') editorSidebarPreviousFocus.focus();
	}

	if (editorSidebarToggle && editorSidebar) editorSidebarToggle.addEventListener('click', openEditorSidebar);
	if (editorSidebarClose) editorSidebarClose.addEventListener('click', closeEditorSidebar);
	if (editorShadow) editorShadow.addEventListener('click', closeEditorSidebar);
	if (editorSidebar) {
		editorSidebar.addEventListener('keydown', function (event) {
			if (event.key === 'Escape') { event.preventDefault(); closeEditorSidebar(); return; }
			if (event.key !== 'Tab') return;
			var focusable = editorSidebarFocusable();
			if (!focusable.length) { event.preventDefault(); editorSidebar.focus(); return; }
			var first = focusable[0];
			var last = focusable[focusable.length - 1];
			if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
			else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
		});
	}

	var coverPreview = document.getElementById('jscoverImagePreview');
	var coverSelect = document.getElementById('jsbuttonSelectCoverImage');
	var coverRemove = document.getElementById('jsbuttonRemoveCoverImage');
	var coverValue = document.getElementById('jscoverImage');
	var externalCover = document.getElementById('jsexternalCoverImage');
	function openEditorMediaManager() {
		if (typeof window.openMediaManager === 'function') window.openMediaManager();
		else if (typeof openMediaManager === 'function') openMediaManager();
	}
	if (coverPreview) {
		coverPreview.setAttribute('tabindex', '0');
		coverPreview.setAttribute('role', 'button');
		coverPreview.addEventListener('click', openEditorMediaManager);
		coverPreview.addEventListener('keydown', function (event) {
			if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); openEditorMediaManager(); }
		});
	}
	if (coverSelect) coverSelect.addEventListener('click', openEditorMediaManager);
	if (coverRemove) coverRemove.addEventListener('click', function () {
		if (coverValue) coverValue.value = '';
		if (externalCover) externalCover.value = '';
		if (coverPreview && typeof HTML_PATH_CORE_IMG === 'string') coverPreview.setAttribute('src', HTML_PATH_CORE_IMG + 'default.svg');
	});
	if (externalCover && coverValue) externalCover.addEventListener('change', function () {
		var value = String(externalCover.value || '').trim();
		coverValue.value = value;
		if (!coverPreview) return;
		if (/^https?:\/\//i.test(value)) {
			coverPreview.setAttribute('src', value);
		} else if (value === '' && typeof HTML_PATH_CORE_IMG === 'string') {
			coverPreview.setAttribute('src', HTML_PATH_CORE_IMG + 'default.svg');
		}
	});


	var editorTitle = document.getElementById('jstitle');
	var editorParent = document.getElementById('jsparent');
	var editorSlug = document.getElementById('jsslug');
	var editorKey = document.querySelector('input[name="key"]');
	if (editorTitle && editorParent && editorSlug && (!editorKey || String(editorKey.value || '') === '') && typeof window.brendyAjax === 'function') {
		editorTitle.addEventListener('input', function () {
			var ajax = new window.brendyAjax();
			ajax.generateSlug(editorTitle.value, editorParent.value, '', window.jQuery ? jQuery(editorSlug) : editorSlug);
		});
	}

	// Shared publish/draft status control for both New Content and Edit Content.
	document.querySelectorAll('[data-brendy-content-status-switch]').forEach(function (button) {
		button.addEventListener('click', function () {
			var nextDraft = button.getAttribute('data-switch') === 'publish';
			var label = nextDraft ? button.getAttribute('data-draft-label') : button.getAttribute('data-publish-label');
			var icon = nextDraft ? 'bi bi-square-fill switch-icon-draft' : 'bi bi-square-fill switch-icon-publish';
			window.BrendyAdmin.setSwitchLabel(button, icon, label || '');
			button.setAttribute('data-switch', nextDraft ? 'draft' : 'publish');
			button.setAttribute('aria-pressed', nextDraft ? 'true' : 'false');
		});
	});

	// The edit-content delete modal uses the same form as Save. Keep the mutation
	// handler centralized so the delete path cannot drift from the editor form.
	var editorDeleteAccept = document.getElementById('jsbuttonDeleteAccept');
	if (editorDeleteAccept) {
		editorDeleteAccept.addEventListener('click', function () {
			var form = document.getElementById('jsform');
			var type = document.getElementById('jstype');
			var content = document.getElementById('jscontent');
			if (!form || !type || !content) return;
			type.value = 'delete';
			content.value = '';
			if (typeof form.requestSubmit === 'function') form.requestSubmit();
			else if (window.jQuery) jQuery(form).trigger('submit');
	});
	}

	var dirtyForms = Array.prototype.slice.call(document.querySelectorAll('form[method="post"], form[method="POST"]')).filter(function (form) {
		return form.action.indexOf('/logout') === -1 && !form.hasAttribute('data-brendy-no-dirty');
	});
	dirtyForms.forEach(function (form) {
		var markDirty = function () { if (!form.classList.contains('is-submitting')) form.classList.add('brendy-form-dirty'); };
		form.addEventListener('input', markDirty);
		form.addEventListener('change', markDirty);
		form.addEventListener('submit', function () { form.classList.add('is-submitting'); form.classList.remove('brendy-form-dirty'); });
	});
	window.addEventListener('beforeunload', function (event) {
		if (!document.querySelector('form.brendy-form-dirty')) return;
		event.preventDefault();
		event.returnValue = '';
	});
}());


// Shared select-all controller for plugin/admin tables.
(function () {
	'use strict';
	function initSelectAll() {
		document.querySelectorAll('[data-brendy-select-all-name]').forEach(function (master) {
			var name = master.getAttribute('data-brendy-select-all-name') || '';
			if (!name || name.length > 128) return;
			var form = master.closest('form') || document;
			var rows = Array.prototype.filter.call(form.querySelectorAll('input[type="checkbox"][name]'), function (input) {
				return input !== master && input.getAttribute('name') === name;
			});
			if (!rows.length) return;
			function sync() {
				var checked = rows.filter(function (row) { return row.checked; }).length;
				master.checked = checked === rows.length;
				master.indeterminate = checked > 0 && checked < rows.length;
			}
			master.addEventListener('change', function () {
				rows.forEach(function (row) { row.checked = master.checked; });
				master.indeterminate = false;
			});
			rows.forEach(function (row) { row.addEventListener('change', sync); });
			sync();
		});
	}
	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initSelectAll);
	else initSelectAll();
}());
