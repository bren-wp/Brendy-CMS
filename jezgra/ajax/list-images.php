<?php defined('BRENDY') or die('Brendy CMS.');
requireAjaxMethod('POST');
requireAjaxRole(array('admin', 'editor', 'author'));

/*
| Returns a list of images from a particular page
|
| @_POST['pageNumber']	int	Page number for the paginator
| @_POST['path']	string	Pre-defined name for the directory to read, its pre-defined to avoid security issues
| @_POST['uuid']	string	Page UUID
|
| @return	array	Each file is an object with 'filename' (original) and
|			'thumbnail' (resolved preview filename — may differ from
|			the original for legacy pairs or fall back to it when no
|			thumbnail exists).
*/

// $_POST
// ----------------------------------------------------------------------------
// $_POST['pageNumber'] > 0
$pageNumber = brendyIntegerParameter($_POST, 'pageNumber', 1, 1, 100000);
if ($pageNumber === null) {
	http_response_code(400);
	ajaxResponse(1, $L->g('Invalid page number'));
}
$pageNumber--;

$path = isset($_POST['path']) && is_string($_POST['path']) && $_POST['path'] !== '' ? $_POST['path'] : false;
$uuid = isset($_POST['uuid']) && is_string($_POST['uuid']) && $_POST['uuid'] !== '' ? $_POST['uuid'] : false;
$query = isset($_POST['query']) && is_string($_POST['query']) ? trim($_POST['query']) : '';
if (mb_strlen($query, CHARSET) > 100 || strpos($query, "\0") !== false) ajaxResponse(1, $L->g('Search query is too long'));
// ----------------------------------------------------------------------------

// The only accepted value is kept for backward-compat with clients that
// preserve the old contract; the server now scans originals regardless.
if ($path !== 'thumbnails') {
	ajaxResponse(1, $L->g('Invalid path'));
}

// Resolve the originals and thumbnails directories
if ($uuid && IMAGE_RESTRICT) {
	if (!isValidPageUuid($uuid) || !canManagePageMedia($uuid)) {
		ajaxResponse(1, $L->g('Invalid UUID'));
	}
	$imagePath = PATH_UPLOADS_PAGES.$uuid.DS;
	$thumbnailPath = PATH_UPLOADS_PAGES.$uuid.DS.'thumbnails'.DS;
} else {
	// Authors using isolated page media must not enumerate the shared media
	// library simply by omitting the page UUID.
	if (IMAGE_RESTRICT && checkRole(array('author'), false)) {
		ajaxResponse(1, $L->g('Permission denied'));
	}
	$imagePath = PATH_UPLOADS;
	$thumbnailPath = PATH_UPLOADS_THUMBNAILS;
}

// Scan/sort originals once, but resolve thumbnail filesystem state only for
// the requested page instead of every media item in the library.
$pageData = mediaManagerImagePage($imagePath, $thumbnailPath, $pageNumber + 1, MEDIA_MANAGER_NUMBER_OF_FILES, $query);
ajaxResponse(0, $L->g('File list loaded'), $pageData);

?>
