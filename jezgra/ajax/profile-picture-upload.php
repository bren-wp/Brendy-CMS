<?php defined('BRENDY') or die('Brendy CMS.');
requireAjaxMethod('POST');
requireAjaxRole(array('admin', 'editor', 'author'));

// $_POST
// ----------------------------------------------------------------------------
// (string) $_POST['username']
$username = isset($_POST['username']) && is_string($_POST['username']) && $_POST['username'] !== '' ? $_POST['username'] : false;
// ----------------------------------------------------------------------------

if (!is_string($username) || $username === '' || !$users->exists($username)) {
	ajaxResponse(1, $L->g('Invalid username'));
}

if (($login->role() !== 'admin') && ($login->username() !== $username)) {
	ajaxResponse(1, $L->g('Invalid username'));
}

if (!isset($_FILES['profilePictureInputFile']) || !is_array($_FILES['profilePictureInputFile']) ||
	!isset($_FILES['profilePictureInputFile']['name'], $_FILES['profilePictureInputFile']['tmp_name'], $_FILES['profilePictureInputFile']['error']) ||
	$_FILES['profilePictureInputFile']['error'] !== UPLOAD_ERR_OK || !is_string($_FILES['profilePictureInputFile']['name'])) {
	ajaxResponse(1, $L->g('Profile picture upload failed'));
}

// Check path traversal
if (basename($username) !== $username || strpos($username, '/') !== false || strpos($username, '\\') !== false) {
	$message = $L->g('Invalid file path');
	Log::set($message, LOG_TYPE_ERROR);
	ajaxResponse(1, $message);
}

// Block dotfiles
if (strpos($_FILES['profilePictureInputFile']['name'], '.') === 0) {
	$message = $L->g('File type not allowed');
	Log::set($message, LOG_TYPE_ERROR);
	ajaxResponse(1, $message);
}

// Check file extension
$fileExtension = Filesystem::extension($_FILES['profilePictureInputFile']['name']);
$fileExtension = Text::lowercase($fileExtension);
if (!in_array($fileExtension, $GLOBALS['ALLOWED_IMG_EXTENSION'], true) ||
	!validateUploadedImage($_FILES['profilePictureInputFile']['tmp_name'], $_FILES['profilePictureInputFile']['name'])) {
	$message = $L->g('File type is not supported').' '.implode(', ',$GLOBALS['ALLOWED_IMG_EXTENSION']);
	Log::set($message, LOG_TYPE_ERROR);
	ajaxResponse(1, $message);
}

// Tmp filename
$tmpFilename = bin2hex(random_bytes(16)).'.'.$fileExtension;

// Final filename
$filename = $username.'.png';

// Ensure the Brendy temporary directory exists.
if (!Filesystem::directoryExists(PATH_TMP)) {
	if (!Filesystem::mkdir(PATH_TMP, true)) {
		$message = $L->g('Temporary directory is unavailable');
		Log::set($message, LOG_TYPE_ERROR);
		ajaxResponse(1, $message);
	}
}
$tmpFilename = Filesystem::nextFilename($tmpFilename, PATH_TMP);
if ($tmpFilename === false) {
	ajaxResponse(1, $L->g('Unable to allocate a safe temporary filename'));
}

// Move from temporary directory to uploads folder
$moved = moveUploadedFileSafely($_FILES['profilePictureInputFile']['tmp_name'], PATH_TMP.$tmpFilename);
if (!$moved) {
	$message = $L->g('Unable to store the uploaded file');
	Log::set($message, LOG_TYPE_ERROR);
	ajaxResponse(1, $message);
}

// Resize and convert to png
$profileDirectory = dirname(PATH_UPLOADS_PROFILES.$filename);
if (!Filesystem::directoryExists($profileDirectory) && !Filesystem::mkdir($profileDirectory, true)) {
	Filesystem::rmfile(PATH_TMP.$tmpFilename);
	ajaxResponse(1, $L->g('Profile picture directory is unavailable'));
}
if (!Filesystem::directoryExists($profileDirectory)) {
	Filesystem::rmfile(PATH_TMP.$tmpFilename);
	ajaxResponse(1, $L->g('Profile picture directory is unavailable'));
}
$image = new Image();
if ($image->setImage(PATH_TMP.$tmpFilename, PROFILE_IMG_WIDTH, PROFILE_IMG_HEIGHT, 'crop') === false) {
	Filesystem::rmfile(PATH_TMP.$tmpFilename);
	$message = $L->g('Profile picture processing failed');
	Log::set($message, LOG_TYPE_ERROR);
	ajaxResponse(1, $message);
}
if (!$image->saveImage(PATH_UPLOADS_PROFILES.$filename, PROFILE_IMG_QUALITY, false, true) ||
	!is_file(PATH_UPLOADS_PROFILES.$filename)) {
	Filesystem::rmfile(PATH_TMP.$tmpFilename);
	ajaxResponse(1, $L->g('Profile picture could not be saved'));
}

// Delete temporary file
Filesystem::rmfile(PATH_TMP.$tmpFilename);

// Permissions
@chmod(PATH_UPLOADS_PROFILES.$filename, 0644);

$profilePath = PATH_UPLOADS_PROFILES . $filename;
$profileVersion = Filesystem::fileVersionToken($profilePath, (string)BRENDY_BUILD);
ajaxResponse(0, $L->g('Image uploaded'), array(
	'filename'=>$filename,
	'absoluteURL'=>DOMAIN_UPLOADS_PROFILES.rawurlencode($username).'.png?version=' . rawurlencode($profileVersion)
));

?>
