<?php defined('BRENDY') or die('Brendy CMS.');
requireAjaxMethod('POST');

/*
| Upload site logo
| The final filename is the site's name and the extension is the same as the file uploaded
|
| @_FILES['inputFile']	multipart/form-data	File from form
|
| @return	array
*/

requireAjaxRole(array('admin'));

if (!isset($_FILES['inputFile']) || !is_array($_FILES['inputFile']) ||
	!isset($_FILES['inputFile']['name'], $_FILES['inputFile']['tmp_name'], $_FILES['inputFile']['error']) ||
	$_FILES['inputFile']['error'] !== UPLOAD_ERR_OK || !is_string($_FILES['inputFile']['name'])) {
	ajaxResponse(1, $L->g('Site logo upload failed'));
}

// Check path traversal on $filename
if (basename($_FILES['inputFile']['name']) !== $_FILES['inputFile']['name'] ||
	strpos($_FILES['inputFile']['name'], '/') !== false || strpos($_FILES['inputFile']['name'], '\\') !== false) {
	$message = $L->g('Invalid file path');
	Log::set($message, LOG_TYPE_ERROR);
	ajaxResponse(1, $message);
}

// Block dotfiles
if (strpos($_FILES['inputFile']['name'], '.') === 0) {
	$message = $L->g('File type not allowed');
	Log::set($message, LOG_TYPE_ERROR);
	ajaxResponse(1, $message);
}

// File extension
$fileExtension = Filesystem::extension($_FILES['inputFile']['name']);
$fileExtension = Text::lowercase($fileExtension);
if (!in_array($fileExtension, $GLOBALS['ALLOWED_IMG_EXTENSION'], true) ||
	!validateUploadedImage($_FILES['inputFile']['tmp_name'], $_FILES['inputFile']['name'])) {
	$message = $L->g('File type is not supported') . ' ' . implode(', ', $GLOBALS['ALLOWED_IMG_EXTENSION']);
	Log::set($message, LOG_TYPE_ERROR);
	ajaxResponse(1, $message);
}

// Use a unique filename so a failed database write never overwrites the active logo.
$filenameBase = 'logo';
if (Text::isNotEmpty($site->title())) {
	$sanitizedTitle = Text::removeSpecialCharacters(Sanitize::htmlDecode($site->title()), '-');
	$sanitizedTitle = Text::removeQuotes($sanitizedTitle);
	$sanitizedTitle = Text::removeSpaces($sanitizedTitle, '-');
	$sanitizedTitle = trim($sanitizedTitle, '-');
	if (Text::isNotEmpty($sanitizedTitle)) {
		$filenameBase = mb_substr($sanitizedTitle, 0, 100, CHARSET);
	}
}
$filename = $filenameBase . '-' . bin2hex(random_bytes(8)) . '.' . $fileExtension;

if (!Filesystem::directoryExists(PATH_UPLOADS) && !Filesystem::mkdir(PATH_UPLOADS, true)) {
	ajaxResponse(1, $L->g('Upload directory is unavailable'));
}

// Move from temporary directory to uploads
if (!moveUploadedFileSafely($_FILES['inputFile']['tmp_name'], PATH_UPLOADS . $filename)) {
	ajaxResponse(1, $L->g('Unable to store the uploaded file'));
}

$oldFilename = $site->logo(false);
// Permissions
@chmod(PATH_UPLOADS . $filename, 0644);

// Store the filename in the database
if (!$site->set(array('logo' => $filename))) {
	Filesystem::rmfile(PATH_UPLOADS . $filename);
	ajaxResponse(1, $L->g('The site logo setting could not be saved'));
}

// Delete the previous logo only after both the file and setting were stored safely.
if ($oldFilename && $oldFilename !== $filename && basename($oldFilename) === $oldFilename &&
	Sanitize::pathFile(PATH_UPLOADS, $oldFilename) && is_file(PATH_UPLOADS.$oldFilename) &&
	!Filesystem::rmfile(PATH_UPLOADS . $oldFilename)) {
	Log::set(__FILE__ . LOG_SEP . 'Unable to delete the previous site logo.', LOG_TYPE_ERROR);
}

ajaxResponse(0, $L->g('Image uploaded'), array(
	'filename' => $filename,
	'absoluteURL' => DOMAIN_UPLOADS . rawurlencode($filename) . '?version=' . rawurlencode(Filesystem::fileVersionToken(PATH_UPLOADS . $filename, (string)BRENDY_BUILD))
));
