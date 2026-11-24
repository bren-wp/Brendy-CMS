(function (window, document) {
	'use strict';

	var config = window.BrendyEditorConfig || {};
	var textareaId = 'jseditor';
	var storagePrefix = 'brendy-editor-draft:';
	var localSaveTimer = null;
	var dirty = false;
	var submitting = false;
	var restored = false;
	var initialSnapshot = '';
	// Never restore identity, CSRF, or optimistic concurrency controls from browser storage.
	var protectedDraftFields = ['tokenCSRF', 'content', 'revisionId', 'save', 'uuid', 'key', 'contentVersion'];

	function textarea() {
		return document.getElementById(textareaId);
	}

	function editorInstance() {
		if (!window.tinymce || typeof window.tinymce.get !== 'function') return null;
		return window.tinymce.get(textareaId) || null;
	}

	function activeEditor() {
		var editor = editorInstance();
		return editor && editor.initialized === true ? editor : null;
	}

	function titleField() {
		return document.getElementById('jstitle');
	}

	function uuidField() {
		return document.getElementById('jsuuid');
	}

	function form() {
		return document.getElementById('jsform');
	}

	function escapeAttribute(value) {
		return String(value == null ? '' : value).replace(/[&<>"']/g, function (character) {
			return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'}[character];
		});
	}

	function safeUrl(value, imageSource) {
		var url = String(value == null ? '' : value).trim();
		if (!url || url.length > 4096 || /[\u0000-\u0020\u007f\\]/.test(url) || url.indexOf('//') === 0) return '';
		if (!imageSource && url.charAt(0) === '#') return url;
		var scheme = url.match(/^([A-Za-z][A-Za-z0-9+.-]*):/);
		if (scheme) {
			var protocol = scheme[1].toLowerCase();
			var allowed = imageSource ? ['http', 'https'] : ['http', 'https', 'mailto', 'tel'];
			return allowed.indexOf(protocol) !== -1 ? url : '';
		}
		var colon = url.indexOf(':');
		var firstBoundary = url.search(/[\/?#]/);
		if (firstBoundary === -1) firstBoundary = url.length;
		if (colon !== -1 && colon < firstBoundary) return '';
		return url;
	}

	function imageAltTextFromUrl(value) {
		var source = String(value == null ? '' : value).split(/[?#]/, 1)[0];
		var filename = source.substring(source.lastIndexOf('/') + 1);
		try { filename = decodeURIComponent(filename); } catch (error) { /* keep encoded name */ }
		filename = filename.replace(/\.[A-Za-z0-9]{1,10}$/, '').replace(/[-_]+/g, ' ').replace(/\s+/g, ' ').trim();
		if (/^(?:img|image|dsc|photo|picture)[ -]?\d*$/i.test(filename)) return '';
		return filename.substring(0, 180);
	}

	function insertIntoTextarea(content) {
		var element = textarea();
		if (!element) return false;
		var value = String(content == null ? '' : content);
		var start = typeof element.selectionStart === 'number' ? element.selectionStart : element.value.length;
		var end = typeof element.selectionEnd === 'number' ? element.selectionEnd : start;
		element.value = element.value.slice(0, start) + value + element.value.slice(end);
		var next = start + value.length;
		if (typeof element.setSelectionRange === 'function') element.setSelectionRange(next, next);
		element.dispatchEvent(new Event('input', {bubbles: true}));
		element.focus();
		markDirty();
		return true;
	}

	function editorContent() {
		var editor = activeEditor();
		if (editor && typeof editor.getContent === 'function') return editor.getContent();
		var element = textarea();
		return element ? element.value : '';
	}

	function formState() {
		var editorForm = form();
		var state = Object.create(null);
		if (!editorForm || !editorForm.elements) return state;
		Array.prototype.forEach.call(editorForm.elements, function (field) {
			if (!field || !field.name || field.disabled) return;
			if (protectedDraftFields.indexOf(field.name) !== -1) return;
			var type = String(field.type || '').toLowerCase();
			if (['submit', 'button', 'reset', 'file'].indexOf(type) !== -1) return;
			if (type === 'checkbox' || type === 'radio') {
				if (!Array.isArray(state[field.name])) state[field.name] = [];
				if (field.checked) state[field.name].push(String(field.value));
				return;
			}
			if (field.multiple && field.options) {
				state[field.name] = Array.prototype.filter.call(field.options, function (option) { return option.selected; }).map(function (option) { return String(option.value); });
				return;
			}
			var value = String(field.value == null ? '' : field.value);
			if (Object.prototype.hasOwnProperty.call(state, field.name)) {
				if (!Array.isArray(state[field.name])) state[field.name] = [state[field.name]];
				state[field.name].push(value);
			} else {
				state[field.name] = value;
			}
		});
		var statusSwitch = document.querySelector('[data-brendy-content-status-switch]');
		if (statusSwitch && Object.prototype.hasOwnProperty.call(state, 'type')) {
			state.type = statusSwitch.getAttribute('data-switch') === 'draft' ? 'draft' : (state.typeSelector || 'published');
		}
		return state;
	}

	function snapshot() {
		return JSON.stringify({ fields: formState(), content: editorContent() });
	}

	function contentVersion() {
		var field = form() && form().elements.namedItem('contentVersion');
		return field ? String(field.value || '') : '';
	}

	function storageKey() {
		var uuid = uuidField();
		var id = uuid && /^[a-f0-9]{32}$/i.test(uuid.value) ? uuid.value.toLowerCase() : window.location.pathname;
		var scope = /^[a-f0-9]{8,64}$/i.test(String(config.draftScope || '')) ? String(config.draftScope).toLowerCase() : 'default';
		return storagePrefix + scope + ':' + id;
	}

	function statusNode() {
		var existing = document.getElementById('brendyEditorStatus');
		if (existing) return existing;
		var element = textarea();
		if (!element || !element.parentNode) return null;
		var status = document.createElement('div');
		status.id = 'brendyEditorStatus';
		status.className = 'brendy-editor-status text-muted';
		status.setAttribute('role', 'status');
		status.setAttribute('aria-live', 'polite');
		status.textContent = String((config.labels || {}).ready || 'Urednik je spreman');
		element.parentNode.insertBefore(status, element.nextSibling);
		return status;
	}

	function setStatus(message, state) {
		var node = statusNode();
		if (!node) return;
		node.textContent = String(message || '');
		node.setAttribute('data-state', state || 'idle');
	}

	function readLocalDraft() {
		try {
			var raw = window.localStorage.getItem(storageKey());
			if (!raw || raw.length > Number(config.maxDraftBytes || 4500000)) return null;
			var parsed = JSON.parse(raw);
			if (!parsed || typeof parsed !== 'object' || typeof parsed.content !== 'string') return null;
			if (!parsed.fields || typeof parsed.fields !== 'object' || Array.isArray(parsed.fields)) {
				// Backwards compatibility with Brendy Editor <= 1.4 local drafts.
				parsed.fields = {};
				if (typeof parsed.title === 'string') parsed.fields.title = parsed.title;
			}
			if (!Number.isFinite(Number(parsed.savedAt))) return null;
			if (Date.now() - Number(parsed.savedAt) > 7 * 24 * 60 * 60 * 1000) {
				window.localStorage.removeItem(storageKey());
				return null;
			}
			return parsed;
		} catch (error) {
			return null;
		}
	}

	function clearLocalDraft() {
		window.clearTimeout(localSaveTimer);
		try { window.localStorage.removeItem(storageKey()); } catch (error) { /* storage may be unavailable */ }
	}

	function saveLocalDraft() {
		window.clearTimeout(localSaveTimer);
		if (!dirty || submitting) return;
		var content = editorContent();
		var fields = formState();
		var draft = {
			fields: fields,
			contentVersion: contentVersion(),
			content: String(content || ''),
			savedAt: Date.now()
		};
		var hasFieldValue = Object.keys(fields).some(function (key) {
			var value = fields[key];
			return Array.isArray(value) ? value.some(Boolean) : String(value || '') !== '';
		});
		if (!hasFieldValue && draft.content.trim() === '') return;
		var encoded;
		try { encoded = JSON.stringify(draft); } catch (error) { return; }
		if (encoded.length > Number(config.maxDraftBytes || 4500000)) {
			setStatus(String((config.labels || {}).draftTooLarge || 'Lokalni nacrt je prevelik za spremanje'), 'warning');
			return;
		}
		try {
			window.localStorage.setItem(storageKey(), encoded);
			setStatus(String((config.labels || {}).localSaved || 'Lokalni nacrt spremljen'), 'saved');
		} catch (error) {
			setStatus(String((config.labels || {}).localSaveFailed || 'Lokalni nacrt nije moguće spremiti'), 'warning');
		}
	}

	function scheduleLocalSave() {
		window.clearTimeout(localSaveTimer);
		localSaveTimer = window.setTimeout(saveLocalDraft, 1200);
	}

	function markDirty() {
		if (submitting) return;
		dirty = snapshot() !== initialSnapshot;
		if (dirty) {
			setStatus(String((config.labels || {}).unsaved || 'Nespremljene promjene'), 'dirty');
			scheduleLocalSave();
		} else {
			window.clearTimeout(localSaveTimer);
			setStatus(String((config.labels || {}).ready || 'Urednik je spreman'), 'idle');
		}
	}



	function applyDraftFields(fields) {
		var editorForm = form();
		if (!editorForm || !fields || typeof fields !== 'object') return;
		Object.keys(fields).forEach(function (name) {
			if (protectedDraftFields.indexOf(name) !== -1) return;
			var controls = editorForm.elements.namedItem(name);
			if (!controls) return;
			var values = Array.isArray(fields[name]) ? fields[name].map(String) : [String(fields[name] == null ? '' : fields[name])];
			var list = (typeof controls.length === 'number' && !controls.tagName) ? Array.prototype.slice.call(controls) : [controls];
			list.forEach(function (control) {
				if (!control || control.disabled) return;
				var type = String(control.type || '').toLowerCase();
				if (type === 'checkbox' || type === 'radio') {
					control.checked = values.indexOf(String(control.value == null ? '' : control.value)) !== -1;
				} else if (type !== 'file' && type !== 'submit' && type !== 'button' && type !== 'reset') {
					if (control.multiple && control.options) {
						Array.prototype.forEach.call(control.options, function (option) {
							option.selected = values.indexOf(String(option.value)) !== -1;
						});
					} else {
						var restoredValue = values.length ? values[0] : '';
						if (type === 'datetime-local') restoredValue = restoredValue.replace(' ', 'T');
						control.value = restoredValue;
					}
				}
			});
		});
	}

	function restoreLocalDraft(editor) {
		if (restored) return;
		restored = true;
		var draft = readLocalDraft();
		if (!draft) return;
		var serverModifiedAt = Number(config.serverModifiedAt || 0) * 1000;
		if (serverModifiedAt > 0 && Number(draft.savedAt) <= serverModifiedAt) {
			clearLocalDraft();
			return;
		}
		var draftVersion = typeof draft.contentVersion === 'string' ? draft.contentVersion : draft.fields.contentVersion;
		if (typeof draftVersion === 'string' && draftVersion !== contentVersion()) {
			setStatus(String((config.labels || {}).draftConflict || 'Lokalni nacrt pripada starijoj verziji sadržaja i nije automatski vraćen.'), 'warning');
			return;
		}
		var currentFields = formState();
		var currentContent = editorContent();
		if (JSON.stringify(draft.fields) === JSON.stringify(currentFields) && draft.content === currentContent) {
			try { window.localStorage.removeItem(storageKey()); } catch (error) { /* ignore */ }
			return;
		}
		var message = String((config.labels || {}).restoreDraft || 'Pronađen je lokalni nacrt. Želite li ga vratiti?');
		if (!window.confirm(message)) return;
		applyDraftFields(draft.fields);
		var statusSwitch = document.querySelector('[data-brendy-content-status-switch]');
		if (statusSwitch && typeof draft.fields.type === 'string' && ['draft', 'published', 'sticky', 'static'].indexOf(draft.fields.type) !== -1) {
			var desired = draft.fields.type === 'draft' ? 'draft' : 'publish';
			if (statusSwitch.getAttribute('data-switch') !== desired) statusSwitch.click();
		}
		if (editor && editor.initialized && typeof editor.setContent === 'function') editor.setContent(draft.content);
		else if (textarea()) textarea().value = draft.content;
		markDirty();
		setStatus(String((config.labels || {}).draftRestored || 'Lokalni nacrt je vraćen'), 'warning');
	}

	window.editorGetContent = editorContent;

	window.editorInsertHtml = function (html) {
		var content = String(html == null ? '' : html);
		var editor = activeEditor();
		if (editor && typeof editor.insertContent === 'function') {
			editor.insertContent(content);
			markDirty();
			return true;
		}
		return insertIntoTextarea(content);
	};

	window.editorInsertMedia = function (filename) {
		var source = safeUrl(filename, true);
		if (!source) return false;
		var alt = imageAltTextFromUrl(source);
		return window.editorInsertHtml('<figure class="brendy-editor-image"><img src="' + escapeAttribute(source) + '" alt="' + escapeAttribute(alt) + '" loading="lazy" decoding="async"></figure>');
	};

	window.editorInsertLinkedMedia = function (filename, link) {
		var source = safeUrl(filename, true);
		var target = safeUrl(link, false);
		if (!source || !target) return false;
		var alt = imageAltTextFromUrl(source);
		return window.editorInsertHtml('<figure class="brendy-editor-image"><a href="' + escapeAttribute(target) + '"><img src="' + escapeAttribute(source) + '" alt="' + escapeAttribute(alt) + '" loading="lazy" decoding="async"></a></figure>');
	};

	function bindDocumentShortcuts() {
		document.addEventListener('keydown', function (event) {
			if (!(event.ctrlKey || event.metaKey)) return;
			if (event.key.toLowerCase() === 's') {
				event.preventDefault();
				var save = document.getElementById('jsbuttonSave');
				if (save && !save.disabled) save.click();
			} else if (event.shiftKey && event.key.toLowerCase() === 'p') {
				event.preventDefault();
				var preview = document.getElementById('jsbuttonPreview');
				if (preview && !preview.disabled) preview.click();
			}
		});
	}

	function bindFormGuard() {
		var editorForm = form();
		if (editorForm) {
			editorForm.addEventListener('submit', function () {
				submitting = true;
				var editor = activeEditor();
				if (editor && typeof editor.save === 'function') editor.save();
			});
			editorForm.addEventListener('input', markDirty);
			editorForm.addEventListener('change', markDirty);
			editorForm.addEventListener('click', function (event) {
				if (event.target.closest('[data-brendy-content-status-switch]')) markDirty();
			});
		}
		window.addEventListener('beforeunload', function (event) {
			if (!dirty || submitting) return;
			event.preventDefault();
			event.returnValue = '';
		});
	}

	function initializeFallback() {
		initialSnapshot = snapshot();
		restoreLocalDraft(null);
		setStatus(String((config.labels || {}).fallback || 'Vizualni urednik se nije mogao pokrenuti; koristi se tekstualni način.'), 'warning');
	}

	var loadingEngine = false;
	var cloudEngine = false;
	var initializationPending = false;
	var localRetried = false;
	function loadEditorScript(url, isCloud, done) {
		var script = document.createElement('script');
		script.src = url;
		if (isCloud) { script.referrerPolicy = 'origin'; script.crossOrigin = 'anonymous'; }
		script.onload = function () { done(!!window.tinymce && typeof window.tinymce.init === 'function'); };
		script.onerror = function () { script.remove(); done(false); };
		document.head.appendChild(script);
	}
	function startEditor() {
		if (config.cloudScript && textarea() && !document.getElementById('brendyLocalEditorLink')) {
			var link = document.createElement('a');
			link.id = 'brendyLocalEditorLink';
			link.className = 'small d-inline-block mb-2';
			link.textContent = 'Koristi lokalni urednik';
			try { var url = new URL(window.location.href); url.searchParams.set('brendy_editor', 'local'); link.href = url.href; } catch (error) { link.href = '?brendy_editor=local'; }
			textarea().parentNode.insertBefore(link, textarea());
		}
		if (!textarea() || loadingEngine || initializationPending) return;
		if (window.tinymce && typeof window.tinymce.init === 'function') { initialize(); return; }
		loadingEngine = true;
		var local = function () {
			cloudEngine = false;
			if (!config.localScript) { loadingEngine = false; initializeFallback(); return; }
			loadEditorScript(config.localScript, false, function (ok) { loadingEngine = false; if (ok) initialize(); else initializeFallback(); });
		};
		if (config.cloudScript) {
			loadEditorScript(config.cloudScript, true, function (ok) {
				if (!ok) { local(); return; }
				cloudEngine = true; loadingEngine = false; initialize();
			});
		} else { local(); }
	}

	function initialize() {
		if (!textarea()) return;
		if (!window.tinymce || typeof window.tinymce.init !== 'function') { initializeFallback(); return; }
		if (editorInstance() || initializationPending) return;
		initializationPending = true;

		var plugins = [
			'advlist', 'autolink', 'charmap', 'code', 'codesample',
			'fullscreen', 'image', 'link', 'lists', 'pagebreak', 'searchreplace',
			'table', 'wordcount'
		];

		var options = {
			selector: '#' + textareaId,
			base_url: String(config.baseUrl || ''),
			suffix: '.min',
			license_key: 'gpl',
			language: 'hr',
			skin: 'oxide',
			schema: 'html5',
			element_format: 'html',
			entity_encoding: 'raw',
			auto_focus: textareaId,
			height: 560,
			min_height: 380,
			resize: true,
			menubar: 'edit view insert format tools table help',
			statusbar: true,
			branding: false,
			promotion: false,
			browser_spellcheck: true,
			contextmenu: 'link image table',
			plugins: plugins,
			toolbar_mode: 'wrap',
			toolbar: 'undo redo | blocks | bold italic underline strikethrough | forecolor backcolor removeformat | alignleft aligncenter alignright alignjustify | bullist numlist outdent indent | blockquote table | link brendymedia image charmap | pagebreak codesample code | searchreplace fullscreen',
			pagebreak_separator: String(config.pageBreak || '<!-- pagebreak -->'),
			paste_as_text: true,
			paste_data_images: false,
			automatic_uploads: false,
			convert_urls: true,
			relative_urls: false,
			remove_script_host: false,
			sandbox_iframes: true,
			convert_unsafe_embeds: true,
			allow_html_in_comments: false,
			invalid_elements: 'script,iframe,object,embed,form,input,button,textarea,select,option,meta,link,base,style',
			content_css: String(config.contentCss || ''),
			cache_suffix: '?v=' + encodeURIComponent(String(config.build || '1')),
			image_description: true,
			image_caption: true,
			image_advtab: false,
			link_assume_external_targets: 'https',
			allow_unsafe_link_target: false,
			referrer_policy: 'no-referrer-when-downgrade',
			style_formats: [
				{ title: 'Istaknuti odlomak', block: 'p', classes: 'lead' },
				{ title: 'Informativni blok', block: 'aside', classes: 'brendy-content-callout', wrapper: true },
				{ title: 'Gumb/poveznica', selector: 'a', classes: 'brendy-content-button' }
			],
			setup: function (editor) {
				editor.ui.registry.addButton('brendymedia', {
					icon: 'gallery',
					tooltip: String((config.labels || {}).media || 'Mediji'),
					onAction: function () {
						if (typeof window.openMediaManager === 'function') window.openMediaManager();
					}
				});
				var syncTextarea = function () {
					if (editor.initialized === true && typeof editor.save === 'function') editor.save();
					markDirty();
				};
				editor.on('init', function () {
					if (typeof editor.save === 'function') editor.save();
					initialSnapshot = snapshot();
					setStatus(String((config.labels || {}).ready || 'Urednik je spreman'), 'idle');
					restoreLocalDraft(editor);
				});
				editor.on('change input Undo Redo', syncTextarea);
			}
		};

		if (cloudEngine) {
			delete options.license_key;
			delete options.base_url;
			delete options.cache_suffix;
			options.language_url = new URL(String(config.baseUrl || '') + '/langs/hr.js', window.location.href).href;
			options.referrer_policy = 'origin';
		}
		if (config.documentBaseUrl) options.document_base_url = String(config.documentBaseUrl);

		function failed() {
			initializationPending = false;
			var value = editorContent();
			try { var instance = editorInstance(); if (instance) instance.remove(); } catch (error) { /* preserve textarea below */ }
			var field = textarea();
			if (field) { field.value = value; field.style.display = ''; field.removeAttribute('aria-hidden'); }
			if (cloudEngine && !localRetried && config.localScript) {
				localRetried = true; cloudEngine = false; loadingEngine = true;
				window.tinymce = undefined; window.tinyMCE = undefined;
				loadEditorScript(config.localScript, false, function (ok) {
					loadingEngine = false;
					if (ok) initialize(); else initializeFallback();
				});
			} else { initializeFallback(); }
		}
		try {
			Promise.resolve(window.tinymce.init(options)).then(function (editors) {
				initializationPending = false;
				if (Array.isArray(editors) && editors.length === 0 && !activeEditor()) failed();
			}, failed);
		} catch (error) { failed(); }
	}

	window.BrendyEditor = {
		version: '2.0.2',
		initialize: startEditor,
		getContent: window.editorGetContent,
		insertHtml: window.editorInsertHtml,
		saveLocalDraft: saveLocalDraft,
		clearLocalDraft: clearLocalDraft,
		isDirty: function () { return dirty; }
	};

	bindDocumentShortcuts();
	bindFormGuard();
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', startEditor, {once: true});
	} else {
		startEditor();
	}
})(window, document);
