// Brendy Gallery admin JavaScript — isolated configuration, no page globals.
(function ($) {
	'use strict';
	$(function () {
		var configNode = document.getElementById('brendy-gallery-admin-config');
		if (!configNode) return;
		var config = {};
		try { config = JSON.parse(configNode.textContent || '{}'); } catch (error) { return; }
		if (!config || typeof config.webhookUrl !== 'string') return;
		var labels = config.labels && typeof config.labels === 'object' ? config.labels : {};
		var webhookUrl = config.webhookUrl;
		var csrf = typeof config.csrf === 'string' ? config.csrf : '';
		var slug = typeof config.slug === 'string' && /^[a-z0-9-]{1,100}$/.test(config.slug) ? config.slug : '';
		var maxFiles = Math.max(1, Math.min(20, Number(config.maxFiles) || 20));

		var $dropzone = $('#jsGalleryDropzone');
		var $fileInput = $('#jsGalleryFileInput');
		var $progressBar = $('#jsGalleryProgressBar');
		var $uploadMsg = $('#jsGalleryUploadMsg');
		var $uploadButton = $('#jsGalleryUploadButton');

		function showUploadError(message) {
			$uploadMsg.attr('class', 'alert alert-danger text-center mt-2').text(String(message || labels.uploadFailed || 'Upload failed'));
		}
		function clearUploadError() { $uploadMsg.attr('class', 'mt-2 small').text(''); }
		function setProgress(percent, state) {
			var value = Math.max(0, Math.min(100, Number(percent) || 0));
			$progressBar.css('width', value + '%').attr('aria-valuenow', String(value))
				.removeClass('bg-success bg-danger bg-primary').addClass(state || 'bg-primary');
			$progressBar.parent().prop('hidden', false);
		}
		function isSupportedImage(file) {
			if (!file || typeof file.name !== 'string') return false;
			var types = ['image/gif', 'image/png', 'image/jpeg', 'image/webp'];
			if (file.type && types.indexOf(file.type) !== -1) return true;
			return !file.type && /\.(?:gif|png|jpe?g|webp)$/i.test(file.name);
		}
		function uploadFiles(fileList) {
			if (!slug || !csrf || !fileList) return;
			var files = Array.prototype.slice.call(fileList, 0, maxFiles).filter(isSupportedImage);
			if (!files.length) { showUploadError(labels.unsupportedType || 'Unsupported file type'); return; }
			var formData = new FormData();
			files.forEach(function (file) { formData.append('images[]', file, file.name); });
			formData.append('tokenCSRF', csrf);
			formData.append('gallery', slug);
			setProgress(0, 'bg-primary'); clearUploadError();
			$.ajax({
				url: webhookUrl + 'upload', type: 'POST', data: formData, contentType: false, processData: false, dataType: 'json',
				xhr: function () {
					var xhr = new XMLHttpRequest();
					xhr.upload.addEventListener('progress', function (event) { if (event.lengthComputable) setProgress(Math.round(event.loaded / event.total * 100), 'bg-primary'); });
					return xhr;
				},
				success: function (response) {
					if (response && response.status === 0) { setProgress(100, 'bg-success'); clearUploadError(); window.setTimeout(function () { window.location.reload(); }, 350); }
					else { setProgress(100, 'bg-danger'); showUploadError(response && response.message ? response.message : labels.uploadFailed); }
				},
				error: function () { setProgress(100, 'bg-danger'); showUploadError(labels.uploadFailed); }
			});
		}
		if ($uploadButton.length && $fileInput.length) $uploadButton.on('click', function () { $fileInput.trigger('click'); });
		if ($dropzone.length) {
			$fileInput.on('change', function () { if (this.files && this.files.length) uploadFiles(this.files); });
			$dropzone.on('dragover dragenter', function (event) { event.preventDefault(); event.stopPropagation(); $dropzone.find('.gallery-upload-inner').addClass('dragover'); });
			$dropzone.on('dragleave drop', function (event) { event.preventDefault(); event.stopPropagation(); $dropzone.find('.gallery-upload-inner').removeClass('dragover'); });
			$dropzone.on('drop', function (event) { var transfer = event.originalEvent && event.originalEvent.dataTransfer; if (transfer && transfer.files) uploadFiles(transfer.files); });
		}

		function safeMediaUrl(value) {
			if (typeof value !== 'string' || value.length > 4096) return null;
			try {
				var url = new URL(value, window.location.href);
				if (url.origin !== window.location.origin || (url.protocol !== 'https:' && url.protocol !== 'http:')) return null;
				return url.href;
			} catch (error) { return null; }
		}
		function insertGalleryTag(value) {
			if (typeof value !== 'string' || !/^[a-z0-9-]{1,100}$/.test(value)) return;
			var tag = '[gallery:' + value + ']';
			if (typeof window.editorInsertHtml === 'function') { window.editorInsertHtml('\n' + tag + '\n'); return; }
			var $editor = $('#jseditor'); if ($editor.length) $editor.val($editor.val() + '\n' + tag + '\n');
		}
		function loadGalleryList() {
			var $body = $('#jsGallerySelectBody');
			$body.empty().append($('<p>').addClass('text-muted').text(labels.loading || 'Loading…'));
			$.ajax({ url: webhookUrl + 'list', type: 'GET', dataType: 'json', success: function (response) {
				if (!response || response.status !== 0 || !Array.isArray(response.galleries) || !response.galleries.length) {
					$body.empty().append($('<p>').addClass('text-muted').text(labels.noGalleries || 'No galleries')); return;
				}
				$body.empty();
				response.galleries.slice(0, 1000).forEach(function (gallery) {
					if (!gallery || typeof gallery.slug !== 'string' || !/^[a-z0-9-]{1,100}$/.test(gallery.slug)) return;
					var $item = $('<div>').addClass('gallery-select-item').attr('data-slug', gallery.slug);
					var $header = $('<div>').addClass('d-flex justify-content-between align-items-center gallery-select-header');
					var $details = $('<div>').addClass('gallery-select-details');
					$details.append($('<strong>').text(String(gallery.name || '')));
					$details.append($('<span>').addClass('text-muted ms-2').text('(' + Math.max(0, Number(gallery.imageCount) || 0) + ' ' + String(labels.images || '') + ')'));
					$header.append($details).append($('<button>').attr({ type: 'button', 'data-slug': gallery.slug }).addClass('btn btn-sm btn-primary js-gallery-insert').text(labels.insert || 'Insert'));
					$item.append($header);
					if (Array.isArray(gallery.images) && gallery.images.length) {
						var $grid = $('<div>').addClass('gallery-thumb-grid');
						gallery.images.slice(0, 8).forEach(function (image) {
							var mediaUrl = image && safeMediaUrl(image.thumbnail);
							if (mediaUrl) $grid.append($('<img>').attr({ src: mediaUrl, alt: '', loading: 'lazy', decoding: 'async' }));
						});
						if (gallery.images.length > 8) $grid.append($('<span>').addClass('text-muted align-self-center ms-1').text('+' + (gallery.images.length - 8)));
						$item.append($grid);
					}
					$body.append($item);
				});
			}, error: function () { $body.empty().append($('<p>').addClass('text-danger').text(labels.loadFailed || 'Unable to load galleries')); } });
		}
		var $selectModal = $('#jsGallerySelectModal');
		if ($selectModal.length) $selectModal.on('show.bs.modal', loadGalleryList);
		$(document).on('click', '.js-gallery-insert', function () { var value = String($(this).attr('data-slug') || ''); insertGalleryTag(value); if ($selectModal.length && window.bootstrap) bootstrap.Modal.getOrCreateInstance($selectModal[0]).hide(); });
		$(document).on('click', '.js-gallery-delete-image', function () {
			var gallery = String($(this).attr('data-gallery') || ''); var filename = String($(this).attr('data-filename') || '');
			if (!csrf || !/^[a-z0-9-]{1,100}$/.test(gallery) || !filename || filename.length > 255) return;
			if (!window.confirm(String(labels.deleteConfirm || 'Delete image?'))) return;
			$.ajax({ url: webhookUrl + 'delete-image', type: 'POST', dataType: 'json', data: { tokenCSRF: csrf, gallery: gallery, filename: filename }, success: function (response) {
				if (response && response.status === 0) {
					var node = document.getElementById('jsGalleryImage-' + filename); if (node) $(node).fadeOut(250, function () { $(this).remove(); });
				}
			} });
		});
	});
}(jQuery));
