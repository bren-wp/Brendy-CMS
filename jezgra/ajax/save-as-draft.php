<?php defined('BRENDY') or die('Brendy CMS.');
requireAjaxMethod('POST');
requireAjaxRole(array('admin', 'editor', 'author'));

/*
| Create/edit a page and save as draft
| If the UUID already exists the page is updated
|
| @_POST['title']	string	Page title
| @_POST['content']	string	Page content
| @_POST['uuid']	string	Page uuid
| @_POST['uuid']	string	Page type, by default is draft
|
| @return	array
*/

// $_POST
// ----------------------------------------------------------------------------
$title = isset($_POST['title']) && is_string($_POST['title']) ? $_POST['title'] : false;
$content = isset($_POST['content']) && is_string($_POST['content']) ? $_POST['content'] : false;
$uuid = isset($_POST['uuid']) && is_string($_POST['uuid']) ? $_POST['uuid'] : false;
$contentVersion = isset($_POST['contentVersion']) && is_string($_POST['contentVersion']) ? trim($_POST['contentVersion']) : '';
$type = 'autosave';
// ----------------------------------------------------------------------------

if (!is_string($title) || !is_string($content) || mb_strlen($title, CHARSET) > 500 || strlen($content) > BRENDY_MAX_CONTENT_BYTES) {
	ajaxResponse(1, $L->g('Draft could not be saved because the content is invalid'));
}

// Check UUID
if (!is_string($uuid) || preg_match('/^autosave-[a-f0-9]{32}$/i', $uuid) !== 1) {
	ajaxResponse(1, $L->g('Draft could not be saved because the UUID is invalid'));
}

$originalUuid = substr($uuid, strlen('autosave-'));
$page = array(
	'uuid'=>$uuid,
	'key'=>$uuid,
	'slug'=>$uuid,
	'title'=>$title,
	'content'=>$content,
	'type'=>$type
);

// Autosave participates in the same content transaction as a manual save. A
// request can be issued before Save, wait for the mutation lock, and otherwise
// recreate an older autosave after the canonical page has already committed.
// Bind the autosave to the canonical page fingerprint while holding the lock.
$result = false;
$errorMessage = '';
if (!acquireContentMutationLock()) {
	ajaxResponse(1, $L->g('Draft could not be saved'));
}
try {
	refreshContentMutationState();

	if (!canManagePageMedia($originalUuid)) {
		$errorMessage = $L->g('Permission denied');
	} else {
		$canonicalKey = $pages->getByUUID($originalUuid);
		if ($canonicalKey !== false) {
			$canonicalRow = $pages->getPageDB($canonicalKey);
			if (!is_array($canonicalRow) || preg_match('/^[a-f0-9]{64}$/iD', $contentVersion) !== 1 ||
				!hash_equals(pageVersionFingerprint($canonicalKey, $canonicalRow), strtolower($contentVersion))) {
				$errorMessage = $L->g('This content was changed in another session. Reload the page before saving again.');
			}
		} elseif ($contentVersion !== '') {
			// The edit form referred to a canonical page which no longer exists.
			$errorMessage = $L->g('This content was changed in another session. Reload the page before saving again.');
		}

		if ($errorMessage === '') {
			$pageKey = $pages->getByUUID($uuid);
			if ($pageKey === false) {
				$result = createPageUnlocked($page);
			} else {
				$page['key'] = $pageKey;
				$result = editPageUnlocked($page);
			}
		}
	}
} finally {
	releaseContentMutationLock();
}

if ($errorMessage !== '') {
	ajaxResponse(1, $errorMessage);
}
if ($result === false) {
	ajaxResponse(1, $L->g('Draft could not be saved'));
}

ajaxResponse(0, $L->g('Draft saved'), array(
	'uuid'=>$uuid,
	'previewUrl'=>DOMAIN_PAGES . rawurlencode($uuid) . '?preview=' . rawurlencode($security->getPreviewToken($uuid))
));

?>
