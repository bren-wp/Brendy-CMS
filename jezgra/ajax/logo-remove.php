<?php defined('BRENDY') or die('Brendy CMS.');
requireAjaxMethod('POST');

/*
| Delete the site logo
| This script delete the file and set and empty string in the database
|
| @return	array
*/

requireAjaxRole(array('admin'));

$logoFilename = $site->logo(false);
$logoTargets = array();
if (is_string($logoFilename) && $logoFilename !== '' && basename($logoFilename) === $logoFilename &&
	Sanitize::pathFile(PATH_UPLOADS, $logoFilename) && is_file(PATH_UPLOADS . $logoFilename) &&
	!is_link(PATH_UPLOADS . $logoFilename)) {
	$logoTargets[] = PATH_UPLOADS . $logoFilename;
}
$stagedDeletion = stageFilesForDeletion($logoTargets, 'logo');
if ($stagedDeletion === false) ajaxResponse(1, $L->g('Unable to delete image'));

// Commit the setting only after the file is safely staged. If the JSON write
// fails, put the exact old logo back before returning an error.
if (!$site->set(array('logo' => ''))) {
	if (!rollbackStagedDeletion($stagedDeletion)) {
		Log::set(__FILE__ . LOG_SEP . 'Unable to roll back staged site logo.', LOG_TYPE_ERROR);
	}
	ajaxResponse(1, $L->g('The site logo setting could not be saved'));
}
if (!purgeStagedDeletion($stagedDeletion)) {
	Log::set(__FILE__ . LOG_SEP . 'Staged site logo could not be purged.', LOG_TYPE_ERROR);
}

ajaxResponse(0, $L->g('Logo removed'));

?>
