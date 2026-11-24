<?php defined('BRENDY') or die('Brendy CMS.');
requireAjaxMethod('POST');
requireAjaxRole(array('admin', 'editor', 'author'));

/*
| Delete an image from a particular page
|
| @_POST['filename']	string	Name of the file to delete
| @_POST['uuid']	string	Page UUID
|
| @return	array
*/

// $_POST
// ----------------------------------------------------------------------------
$filename = isset($_POST['filename']) && is_string($_POST['filename']) ? $_POST['filename'] : false;
$uuid = isset($_POST['uuid']) && is_string($_POST['uuid']) && $_POST['uuid'] !== '' ? $_POST['uuid'] : false;
// ----------------------------------------------------------------------------

if (!is_string($filename) || $filename === '' || strlen($filename) > 255 || basename($filename) !== $filename ||
	strpos($filename, "\0") !== false || strpos($filename, '/') !== false || strpos($filename, '\\') !== false) {
	ajaxResponse(1, $L->g('Invalid filename'));
}

$extension = Text::lowercase(Filesystem::extension($filename));
if (!in_array($extension, $GLOBALS['ALLOWED_IMG_EXTENSION'], true)) {
	ajaxResponse(1, $L->g('Invalid image filename'));
}

if ($uuid && IMAGE_RESTRICT) {
	if (!isValidPageUuid($uuid) || !canManagePageMedia($uuid)) {
		ajaxResponse(1, $L->g('Invalid UUID'));
	}
	$imagePath = PATH_UPLOADS_PAGES.$uuid.DS;
	$thumbnailPath = PATH_UPLOADS_PAGES.$uuid.DS.'thumbnails'.DS;
} else {
	if (checkRole(array('author'), false)) {
		ajaxResponse(1, $L->g('Permission denied'));
	}
	$imagePath = PATH_UPLOADS;
	$thumbnailPath = PATH_UPLOADS_THUMBNAILS;
}

// Resolve the exact set of files first. Database-independent media deletion
// uses the same reusable staging/rollback primitive as Gallery and logo flows.
$deleteTargets = array();
$original = $imagePath . $filename;
if (Sanitize::pathFile($imagePath, $filename) && is_file($original) && !is_link($original)) {
	$deleteTargets[] = $original;
}
$exactThumbnail = $thumbnailPath . $filename;
if (Sanitize::pathFile($thumbnailPath, $filename) && is_file($exactThumbnail) && !is_link($exactThumbnail)) {
	$deleteTargets[] = $exactThumbnail;
} else {
	$base = pathinfo($filename, PATHINFO_FILENAME);
	foreach ($GLOBALS['ALLOWED_IMG_EXTENSION'] as $ext) {
		$candidate = $base . '.' . $ext;
		if ($candidate === $filename || is_file($imagePath . $candidate)) continue;
		$candidatePath = $thumbnailPath . $candidate;
		if (Sanitize::pathFile($thumbnailPath, $candidate) && is_file($candidatePath) && !is_link($candidatePath)) {
			$deleteTargets[] = $candidatePath;
		}
	}
}
$stagedDeletion = stageFilesForDeletion($deleteTargets, 'media');
if ($stagedDeletion === false) {
	Log::set('ajax/delete-image' . LOG_SEP . 'Unable to stage media deletion.', LOG_TYPE_ERROR);
	ajaxResponse(1, $L->g('Unable to delete image'));
}
$coverUuid = ($uuid && IMAGE_RESTRICT) ? $uuid : null;
$coverCleanup = method_exists($pages, 'clearCoverImageReferences')
	? $pages->clearCoverImageReferences($filename, $coverUuid)
	: false;
if ($coverCleanup === false) {
	if (!rollbackStagedDeletion($stagedDeletion)) {
		Log::set('ajax/delete-image' . LOG_SEP . 'Unable to restore staged media after cover-reference failure.', LOG_TYPE_ERROR);
	}
	ajaxResponse(1, $L->g('Unable to delete image'));
}

if (!purgeStagedDeletion($stagedDeletion)) {
	Log::set('ajax/delete-image' . LOG_SEP . 'Staged deleted media could not be purged.', LOG_TYPE_ERROR);
}

ajaxResponse(0, $L->g('Image deleted'));

?>
