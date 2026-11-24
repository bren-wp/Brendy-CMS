<?php defined('BRENDY') or die('Brendy CMS.');
requireAjaxMethod('POST');

/*
| Change a page's type. Allowed types: published, sticky, static, draft.
| Allowed transitions are restricted to the same set on both ends — anything
| else (autosave, scheduled) goes through the normal edit flow.
|
| @_POST['key']	string	Page key
| @_POST['type']	string	Target type
|
| @return	JSON { status, message, key, type }
*/

requireAjaxRole(array('admin', 'editor', 'author'));

$allowed = array('published', 'sticky', 'static', 'draft');

$key = isset($_POST['key']) && is_string($_POST['key']) ? trim($_POST['key']) : false;
$newType = isset($_POST['type']) && is_string($_POST['type']) ? trim($_POST['type']) : false;
if ($key === false || $newType === false || !isValidPageKey($key) || strlen($newType) > 20 ||
	preg_match('/[\x00-\x1F\x7F]/', $newType) || !in_array($newType, $allowed, true)) {
	http_response_code(400);
	ajaxResponse(1, $L->g('Invalid request.'));
}

// The helper reacquires the shared content lock, refreshes DB state after any
// wait, revalidates authorization/ownership, updates the complete subtree and
// refreshes Search/Sitemap/taxonomy derived state after the commit.
$affected = changePageType($key, $newType, $login->username());
if ($affected === false) {
	ajaxResponse(1, $L->g('Failed to update page type.'));
}
if ($affected === 0) {
	ajaxResponse(0, $L->g('Page type unchanged.'), array('key' => $key, 'type' => $newType, 'affected' => 0));
}

ajaxResponse(0, $L->g('Page type updated.'), array(
	'key' => $key,
	'type' => $newType,
	'affected' => (int)$affected
));
