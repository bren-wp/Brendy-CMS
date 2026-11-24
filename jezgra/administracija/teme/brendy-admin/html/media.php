<?php defined('BRENDY') or die('Brendy CMS.');
// Preload the first chunk of files to avoid an AJAX round-trip the first time
// the Media Manager is opened. Scans originals and resolves each thumbnail,
// mirroring the shape returned by ajax/list-images.
if (IMAGE_RESTRICT) {
	$mediaImagesPath = PATH_UPLOADS_PAGES.PAGE_IMAGES_KEY.DS;
	$mediaThumbnailsPath = PATH_UPLOADS_PAGES.PAGE_IMAGES_KEY.DS.'thumbnails'.DS;
} else {
	$mediaImagesPath = PATH_UPLOADS;
	$mediaThumbnailsPath = PATH_UPLOADS_THUMBNAILS;
}
$preloadPage = mediaManagerImagePage($mediaImagesPath, $mediaThumbnailsPath, 1, MEDIA_MANAGER_NUMBER_OF_FILES);
$preLoadFiles = isset($preloadPage['files']) && is_array($preloadPage['files']) ? $preloadPage['files'] : array();

// Amount of pages for the paginator
$numberOfPages = isset($preloadPage['numberOfPages']) ? (int)$preloadPage['numberOfPages'] : 0;
$preLoadTotalFiles = isset($preloadPage['totalFiles']) ? (int)$preloadPage['totalFiles'] : 0;
?>

<div id="jsmediaManagerModal" class="modal" tabindex="-1" role="dialog">
<div class="modal-dialog modal-lg">
<div class="modal-content">
<div class="container-fluid">
<div class="row">
	<div class="col p-3">

	<!--
		UPLOAD INPUT
	-->
		<h3 class="mt-2 mb-3"><i class="bi bi-images" aria-hidden="true"></i> <?php $L->p('Images'); ?></h3>

		<div id="jsalertMedia" class="alert alert-warning d-none" role="alert"></div>

		<!-- Form and Input file -->
		<form name="brendyFormUpload" id="jsbrendyFormUpload" enctype="multipart/form-data">
			<div class="mb-3">
				<label class="form-label" for="jsimages"><?php $L->p('Choose images to upload'); ?></label>
				<input type="file" class="form-control" id="jsimages" name="images[]" accept=".jpg,.jpeg,.png,.gif,.webp,image/jpeg,image/png,image/gif,image/webp" multiple>
			</div>
		</form>

		<div id="jsbrendyMediaDropzone" class="brendy-media-dropzone mt-3" role="button" tabindex="0" aria-label="<?php echo Theme::escape($L->g('Drag images here or choose files above')); ?>">
			<i class="bi bi-cloud-arrow-up" aria-hidden="true"></i>
			<span><?php $L->p('Drag images here or choose files above'); ?></span>
		</div>

		<!-- Progress bar -->
		<div class="progress mt-3" aria-label="<?php echo Theme::escape($L->g('Upload progress')); ?>">
			<div id="jsbrendyProgressBar" class="progress-bar bg-primary" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"></div>
		</div>

		<div class="brendy-media-toolbar mt-3">
			<div class="brendy-filter-control"><i class="bi bi-search" aria-hidden="true"></i><input id="jsmediaSearch" type="search" class="form-control" placeholder="<?php echo Theme::escape($L->g('Search images')); ?>" aria-label="<?php echo Theme::escape($L->g('Search images')); ?>"></div>
			<small id="jsmediaCount" class="text-muted"></small>
		</div>

	<!--
		IMAGES LIST
	-->
		<!-- Table for list files -->
		<div class="table-responsive brendy-table-shell mt-3">
			<table id="jsbrendyMediaTable" class="table mb-0">
				<tr>
					<td><?php $L->p('There are no images'); ?></td>
				</tr>
			</table>
		</div>

		<!-- Paginator -->
		<nav id="jsbrendyMediaTablePagination"></nav>

	</div>
</div>
</div>
</div>
</div>
</div>

<script nonce="<?php echo htmlspecialchars(brendyCspNonce(), ENT_QUOTES | ENT_SUBSTITUTE, CHARSET); ?>">

<?php
$mediaJsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT |
	JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE;
echo 'const preLoadFiles = ' . json_encode($preLoadFiles, $mediaJsonFlags) . ';' . PHP_EOL;
echo 'const mediaImagesBase = ' . json_encode((string)PAGE_IMAGES_URL, $mediaJsonFlags) . ';' . PHP_EOL;
echo 'const mediaThumbnailsBase = ' . json_encode((string)PAGE_THUMBNAILS_URL, $mediaJsonFlags) . ';' . PHP_EOL;
echo 'const mediaPageKey = ' . json_encode((string)PAGE_IMAGES_KEY, $mediaJsonFlags) . ';' . PHP_EOL;
echo 'const preLoadTotalFiles = ' . (int)$preLoadTotalFiles . ';' . PHP_EOL;
echo 'const mediaLabels = ' . json_encode(array(
	'insert' => $L->g('Insert'),
	'insertThumbnail' => $L->g('Insert thumbnail'),
	'insertLinkedThumbnail' => $L->g('Insert linked thumbnail'),
	'setCover' => $L->g('Set as cover image'),
	'delete' => $L->g('Delete'),
	'empty' => IMAGE_RESTRICT ? $L->g('There are no images for the page') : $L->g('There are no images'),
	'unsupported' => $L->g('File type is not supported') . ' ' . implode(', ', $GLOBALS['ALLOWED_IMG_EXTENSION']),
	'tooLarge' => $L->g('Maximum load file size allowed:') . ' ' . Filesystem::bytesToHumanFileSize(min((int)Text::toBytes(ini_get('upload_max_filesize')), BRENDY_MAX_IMAGE_UPLOAD_BYTES)),
	'tooMany' => $L->g('A maximum of 20 images can be uploaded at once'),
	'requestFailed' => $L->g('Request failed'),
	'deleteConfirm' => $L->g('Delete this image permanently?'),
	'imageOne' => $L->g('Image'),
	'imageFew' => $L->g('Images few'),
	'imageMany' => $L->g('Images many')
), $mediaJsonFlags) . ';' . PHP_EOL;
?>

function openMediaManager() {
	bootstrap.Modal.getOrCreateInstance(document.getElementById('jsmediaManagerModal')).show();
}

function closeMediaManager() {
	bootstrap.Modal.getOrCreateInstance(document.getElementById('jsmediaManagerModal')).hide();
}

// Remove all files from the table
function cleanTable() {
	$('#jsbrendyMediaTable').empty();
}

function showMediaAlert(message) {
	$("#jsalertMedia").text(String(message)).removeClass('d-none');
}

function hideMediaAlert() {
	$("#jsalertMedia").addClass('d-none');
}

function createMediaAction(icon, label, classes, callback) {
	const link = $("<a>", {href: "#", "class": classes});
	$("<i>", {"class": "bi bi-" + icon, "aria-hidden": "true"}).appendTo(link);
	link.append(document.createTextNode(String(label)));
	link.on("click", function(event) {
		event.preventDefault();
		callback();
	});
	return link;
}

// Show the files in the table without interpreting filenames as HTML or code.
let mediaCurrentFiles = [];
let mediaCurrentPage = 1;
let mediaCurrentQuery = '';
let mediaSearchTimer = null;

function mediaImageWord(count) {
	const value = Math.abs(Number(count) || 0);
	const lastTwo = value % 100;
	const last = value % 10;
	if (last === 1 && lastTwo !== 11) return mediaLabels.imageOne;
	if (last >= 2 && last <= 4 && !(lastTwo >= 12 && lastTwo <= 14)) return mediaLabels.imageFew;
	return mediaLabels.imageMany;
}

function updateMediaCount(visibleCount, totalCount) {
	const visible = Math.max(0, Number(visibleCount) || 0);
	const total = Math.max(0, Number(totalCount) || 0);
	const value = visible === total ? String(total) : String(visible) + ' / ' + String(total);
	$('#jsmediaCount').text(value + ' ' + mediaImageWord(total));
}

function renderMediaRows(files) {
	cleanTable();
	const table = $('#jsbrendyMediaTable');
	if (!Array.isArray(files) || files.length === 0) {
		table.append($('<tr>').append($('<td>').text(mediaLabels.empty)));
		return;
	}

	$.each(files.slice(0, 100), function(key, item) {
		if (!item || typeof item.filename !== 'string' || item.filename.length > 255 ||
			/[\x00-\x1F\x7F\\/]/.test(item.filename)) return;
		const filename = item.filename;
		const image = mediaImagesBase + encodeURIComponent(filename);
		const thumbnailName = typeof item.thumbnail === 'string' && item.thumbnail.length <= 255 &&
			!/[\x00-\x1F\x7F\\/]/.test(item.thumbnail) ? item.thumbnail : '';
		const thumbnail = thumbnailName ? mediaThumbnailsBase + encodeURIComponent(thumbnailName) : image;
		const row = $('<tr>');
		const previewCell = $('<td>').css('width', '80px');
		$('<img>', {'class': 'img-thumbnail', alt: filename, src: thumbnail, loading: 'lazy', decoding: 'async'})
			.css({width: '50px', height: '50px', objectFit: 'cover'}).appendTo(previewCell);
		const information = $('<td>', {'class': 'information'});
		$('<div>', {'class': 'text-secondary pb-2'}).text(filename).appendTo(information);
		const actions = $('<div>');
		createMediaAction('plus-circle', mediaLabels.insert, 'me-3 text-primary', function() {
			if (typeof editorInsertMedia === 'function') editorInsertMedia(image);
			closeMediaManager();
		}).appendTo(actions);
		createMediaAction('image', mediaLabels.insertThumbnail, 'me-3 text-primary', function() {
			if (typeof editorInsertMedia === 'function') editorInsertMedia(thumbnail);
			closeMediaManager();
		}).appendTo(actions);
		createMediaAction('link-45deg', mediaLabels.insertLinkedThumbnail, 'me-3 text-primary', function() {
			if (typeof editorInsertLinkedMedia === 'function') editorInsertLinkedMedia(thumbnail, image);
			closeMediaManager();
		}).appendTo(actions);
		createMediaAction('display', mediaLabels.setCover, 'text-primary', function() {
			setCoverImage(filename);
			closeMediaManager();
		}).appendTo(actions);
		createMediaAction('trash', mediaLabels.delete, 'float-end text-danger', function() {
			if (window.confirm(mediaLabels.deleteConfirm)) deleteMedia(filename);
		}).appendTo(actions);
		actions.appendTo(information);
		row.append(previewCell, information).appendTo(table);
	});
}

function renderMediaPagination(numberOfPages, currentPage) {
	const pageCount = Number.isFinite(Number(numberOfPages)) ? Math.max(0, Math.min(1000, Math.floor(Number(numberOfPages)))) : 0;
	const activePage = Math.max(1, Math.min(pageCount || 1, Number(currentPage) || 1));
	const target = $('#jsbrendyMediaTablePagination').empty();
	if (pageCount <= 1) return;
	const pagination = $('<ul>', {'class': 'pagination justify-content-center flex-wrap'});
	const pages = [];
	for (let i = 1; i <= pageCount; i++) {
		if (i === 1 || i === pageCount || Math.abs(i - activePage) <= 2) pages.push(i);
	}
	let previous = 0;
	pages.forEach(function(i) {
		if (previous && i - previous > 1) {
			$('<li>', {'class': 'page-item disabled'}).append($('<span>', {'class': 'page-link'}).text('…')).appendTo(pagination);
		}
		const buttonAttrs = {type: 'button', 'class': 'btn btn-link page-link', 'aria-label': String(i)};
		if (i === activePage) buttonAttrs['aria-current'] = 'page';
		const button = $('<button>', buttonAttrs).text(i);
		button.on('click', function() { getFiles(i, mediaCurrentQuery); });
		$('<li>', {'class': 'page-item' + (i === activePage ? ' active' : '')}).append(button).appendTo(pagination);
		previous = i;
	});
	target.append(pagination);
}

function displayFiles(files, numberOfPages = <?= (int)$numberOfPages ?>, totalFiles = preLoadTotalFiles, currentPage = 1) {
	if (!Array.isArray(files)) return false;
	mediaCurrentFiles = files.slice(0, 100);
	mediaCurrentPage = Math.max(1, Number(currentPage) || 1);
	renderMediaRows(mediaCurrentFiles);
	renderMediaPagination(numberOfPages, mediaCurrentPage);
	updateMediaCount(mediaCurrentFiles.length, Math.max(mediaCurrentFiles.length, Number(totalFiles) || 0));
	return true;
}

// Search is performed server-side so it spans the complete media library,
// not only the currently visible pagination page.
function getFiles(pageNumber, query) {
	mediaCurrentQuery = String(query == null ? mediaCurrentQuery : query).trim().slice(0, 100);
	$.post(HTML_PATH_ADMIN_ROOT+"ajax/list-images",
		{ 	tokenCSRF: tokenCSRF,
			pageNumber: pageNumber,
			uuid: mediaPageKey,
			query: mediaCurrentQuery,
			path: "thumbnails"
		},
		function(data) {
			if (data.status === 0) {
				const pageCount = Math.max(0, Number(data.numberOfPages) || 0);
				if (Array.isArray(data.files) && data.files.length === 0 && pageCount > 0 && pageNumber > pageCount) {
					getFiles(pageCount, mediaCurrentQuery);
					return;
				}
				displayFiles(data.files, pageCount, data.totalFiles, pageNumber);
			} else {
				showMediaAlert(data.message || mediaLabels.requestFailed);
			}
		}
	).fail(function() { showMediaAlert(mediaLabels.requestFailed); });
}

// Delete the file and the thumbnail if exist
function deleteMedia(filename) {
	$.post(HTML_PATH_ADMIN_ROOT+"ajax/delete-image",
		{ 	tokenCSRF: tokenCSRF,
			filename: filename,
				uuid: mediaPageKey
		},
		function(data) { // success function
			if (data.status === 0) {
				if (String($("#jscoverImage").val() || '') === String(filename)) {
					$("#jscoverImage").val('');
					$("#jsexternalCoverImage").val('');
					if (typeof HTML_PATH_CORE_IMG === 'string') $("#jscoverImagePreview").attr('src', HTML_PATH_CORE_IMG + 'default.svg');
				}
				getFiles(mediaCurrentPage, mediaCurrentQuery);
			} else {
				showMediaAlert(data.message || mediaLabels.requestFailed);
			}
		}
	).fail(function() { showMediaAlert(mediaLabels.requestFailed); });
}

function setCoverImage(filename) {
	var image = mediaImagesBase + encodeURIComponent(String(filename));
	$("#jscoverImage").val(filename);
	$("#jsexternalCoverImage").val('');
	$("#jscoverImagePreview").attr("src", image);
}

function uploadImages() {
	// Remove current alerts
	hideMediaAlert();

	var images = $("#jsimages")[0].files;
	if (!images || images.length === 0) return false;
	if (images.length > 20) {
		showMediaAlert(mediaLabels.tooMany);
		return false;
	}
	for (var i=0; i < images.length; i++) {
		// Check file type/extension
		const validImageTypes = ['image/gif', 'image/jpeg', 'image/png', 'image/webp'];
		const validExtensions = ['gif', 'jpg', 'jpeg', 'png', 'webp'];
		const extension = String(images[i].name || '').split('.').pop().toLowerCase();
		if ((images[i].type && !validImageTypes.includes(images[i].type)) || !validExtensions.includes(extension)) {
			showMediaAlert(mediaLabels.unsupported);
			return false;
		}

		// Check file size and compare with PHP upload_max_filesize
		if (images[i].size > UPLOAD_MAX_FILESIZE) {
			showMediaAlert(mediaLabels.tooLarge);
			return false;
		}
	}

	// Clean progress bar
	$("#jsbrendyProgressBar").removeClass().addClass("progress-bar bg-primary");
	$("#jsbrendyProgressBar").width("0").attr("aria-valuenow", "0");

	// Data to send via AJAX
	var formData = new FormData($("#jsbrendyFormUpload")[0]);
	formData.append("uuid", mediaPageKey);
	formData.append("tokenCSRF", tokenCSRF);

	$.ajax({
		url: HTML_PATH_ADMIN_ROOT+"ajax/upload-images",
		type: "POST",
		data: formData,
		cache: false,
		contentType: false,
		processData: false,
		xhr: function() {
			var xhr = $.ajaxSettings.xhr();
			if (xhr.upload) {
				xhr.upload.addEventListener("progress", function(e) {
					if (e.lengthComputable) {
						var percentComplete = (e.loaded / e.total)*100;
						$("#jsbrendyProgressBar").width(percentComplete+"%").attr("aria-valuenow", Math.round(percentComplete));
					}
				}, false);
			}
			return xhr;
		}
	}).done(function(data) {
		if (data && data.status === 0) {
			$("#jsbrendyProgressBar").removeClass("bg-primary").addClass("bg-success");
			// Newly uploaded files sort first; reset search so they are immediately visible.
			$("#jsimages").val("");
			$("#jsmediaSearch").val("");
			getFiles(1, "");
		} else {
			$("#jsbrendyProgressBar").removeClass("bg-primary").addClass("bg-danger");
			showMediaAlert(data && data.message ? data.message : mediaLabels.requestFailed);
		}
	}).fail(function() {
		$("#jsbrendyProgressBar").removeClass("bg-primary").addClass("bg-danger");
		showMediaAlert(mediaLabels.requestFailed);
	});
}

$(function() {
	// Display the files preloaded for the first time
	displayFiles(preLoadFiles, <?= (int)$numberOfPages ?>, preLoadTotalFiles, 1);

	// Select image event
	$("#jsimages").on("change", function(e) {
		uploadImages();
	});

	// Debounced global search avoids one request for every keystroke.
	$("#jsmediaSearch").on("input", function() {
		window.clearTimeout(mediaSearchTimer);
		const query = String(this.value || '').trim();
		mediaSearchTimer = window.setTimeout(function() { getFiles(1, query); }, 250);
	});

	var dropzone = $("#jsbrendyMediaDropzone");
	dropzone.on("dragover dragenter", function(e) {
		e.preventDefault();
		e.stopPropagation();
		dropzone.addClass("is-dragging");
	});
	dropzone.on("dragleave", function() { dropzone.removeClass("is-dragging"); });
	dropzone.on("drop", function(e) {
		e.preventDefault();
		e.stopPropagation();
		dropzone.removeClass("is-dragging");
		const files = e.originalEvent && e.originalEvent.dataTransfer ? e.originalEvent.dataTransfer.files : null;
		if (files && files.length) {
			$("#jsimages").prop("files", files);
			uploadImages();
		}
	});
	dropzone.on("keydown", function(e) {
		if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); $("#jsimages").trigger("click"); }
	});
});

</script>
