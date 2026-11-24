<?php defined('BRENDY') or die('Brendy CMS.');
requireAjaxMethod('POST');
requireAjaxRole(array('admin', 'editor', 'author'));

/*
| Upload an image to a particular page
|
| @_POST['uuid']	string	Page uuid
|
| @return		array
*/

// $_POST
// ----------------------------------------------------------------------------
$uuid = isset($_POST['uuid']) && is_string($_POST['uuid']) && $_POST['uuid'] !== '' ? $_POST['uuid'] : false;
// ----------------------------------------------------------------------------

// Check path traversal on $uuid
if ($uuid) {
	if (!isValidPageUuid($uuid)) {
		$message = $L->g('Invalid file path');
		Log::set($message, LOG_TYPE_ERROR);
		ajaxResponse(1, $message);
	}
}

// Set upload directory. When per-page media isolation is enabled, authors may
// never fall back to the shared upload directory by omitting the UUID.
if (IMAGE_RESTRICT) {
	if ($uuid) {
		if (!canManagePageMedia($uuid)) {
			ajaxResponse(1, $L->g('Permission denied'));
		}
		$imageDirectory = PATH_UPLOADS_PAGES . $uuid . DS;
		$thumbnailDirectory = $imageDirectory . 'thumbnails' . DS;
		if (!Filesystem::directoryExists($thumbnailDirectory) &&
			!Filesystem::mkdir($thumbnailDirectory, true)) {
			ajaxResponse(1, $L->g('Unable to prepare the image directory'));
		}
	} else {
		if (checkRole(array('author'), false)) {
			ajaxResponse(1, $L->g('Permission denied'));
		}
		$imageDirectory = PATH_UPLOADS;
		$thumbnailDirectory = PATH_UPLOADS_THUMBNAILS;
	}
} else {
	$imageDirectory = PATH_UPLOADS;
	$thumbnailDirectory = PATH_UPLOADS_THUMBNAILS;
}

$images = array();
$createdMediaPaths = array();
$rollbackUploadedBatch = function () use (&$createdMediaPaths) {
	foreach (array_reverse(array_unique($createdMediaPaths)) as $path) {
		if (is_string($path) && (is_file($path) || is_link($path))) {
			Filesystem::rmfile($path);
		}
	}
	$createdMediaPaths = array();
};
$failUploadBatch = function ($message) use (&$rollbackUploadedBatch) {
	$rollbackUploadedBatch();
	ajaxResponse(1, is_scalar($message) ? (string)$message : 'Image upload failed');
};
if (!isset($_FILES['images']) || !is_array($_FILES['images']) ||
	!isset($_FILES['images']['name'], $_FILES['images']['tmp_name'], $_FILES['images']['error']) ||
	!is_array($_FILES['images']['name']) || count($_FILES['images']['name']) > 20) {
	ajaxResponse(1, $L->g('Invalid image upload'));
}
foreach ($_FILES['images']['name'] as $index => $filename) {
	// Check for errors
	if (!isset($_FILES['images']['error'][$index], $_FILES['images']['tmp_name'][$index]) ||
		$_FILES['images']['error'][$index] !== UPLOAD_ERR_OK || !is_string($filename)) {
		$message = $L->g('Maximum load file size allowed:') . ' ' . Filesystem::bytesToHumanFileSize(min((int)Text::toBytes(ini_get('upload_max_filesize')), BRENDY_MAX_IMAGE_UPLOAD_BYTES));
		Log::set($message, LOG_TYPE_ERROR);
		$failUploadBatch($message);
	}

	// Normalize every uploaded filename through the shared core policy.
	$filename = brendySanitizeUploadedFilename($filename, 'image');
	if ($filename === false) {
		$failUploadBatch($L->g('Invalid filename'));
	}

	// Check file extension
	$fileExtension = Filesystem::extension($filename);
	$fileExtension = Text::lowercase($fileExtension);
	if (!in_array($fileExtension, $GLOBALS['ALLOWED_IMG_EXTENSION'], true) ||
		!validateUploadedImage($_FILES['images']['tmp_name'][$index], $filename)) {
		$message = $L->g('File type is not supported') . ' ' . implode(', ', $GLOBALS['ALLOWED_IMG_EXTENSION']);
		Log::set($message, LOG_TYPE_ERROR);
		$failUploadBatch($message);
	}

	// Ensure the Brendy temporary directory exists.
	if (!Filesystem::directoryExists(PATH_TMP)) {
		if (!Filesystem::mkdir(PATH_TMP, true)) {
			$message = $L->g('Temporary directory is unavailable');
			Log::set($message, LOG_TYPE_ERROR);
			$failUploadBatch($message);
		}
	}

	// Move from the PHP temporary file to the Brendy temporary directory.
	$temporaryFilename = Filesystem::nextFilename($filename, PATH_TMP);
	if ($temporaryFilename === false) $failUploadBatch($L->g('Unable to allocate a safe temporary filename'));
	$moved = moveUploadedFileSafely($_FILES['images']['tmp_name'][$index], PATH_TMP . $temporaryFilename);
	if (!$moved) {
		$message = $L->g('Unable to store the uploaded file');
		Log::set($message, LOG_TYPE_ERROR);
		$failUploadBatch($message);
	}

	// Transform the image and generate the thumbnail
	$image = transformImage(PATH_TMP . $temporaryFilename, $imageDirectory, $thumbnailDirectory);

	if ($image) {
		@chmod($image, 0644);
		$filename = Filesystem::filename($image);
		$createdMediaPaths[] = $image;
		$thumbnailFile = $thumbnailDirectory . $filename;
		if (is_file($thumbnailFile) && !is_link($thumbnailFile)) $createdMediaPaths[] = $thumbnailFile;
		array_push($images, $filename);
	} else {
		$message = $L->g('Image processing failed');
		Log::set($message, LOG_TYPE_ERROR);
		$failUploadBatch($message);
	}
}

ajaxResponse(0, $L->g('Images uploaded'), array(
	'images' => $images
));
